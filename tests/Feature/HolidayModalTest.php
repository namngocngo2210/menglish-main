<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Holiday;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Sprint IX-1 — hạ tầng modal (htmx) làm mẫu với Ngày nghỉ:
 * cùng route trả trang đầy đủ (request thường) hoặc fragment modal (HX-Request), lỗi validate 422, lưu xong 204 + HX-Trigger.
 */
class HolidayModalTest extends TestCase
{
    use RefreshDatabase;

    private const HX = ['HX-Request' => 'true'];

    private User $admin;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-07 09:00:00'));

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'Cầu Giấy', 'code' => 'CG-HX', 'is_active' => true]);
        $this->admin = User::factory()->create(['name' => 'Quản trị viên', 'is_active' => true, 'branch_id' => $this->branch->id]);
        $this->admin->assignRole('admin');
    }

    public function test_index_renders_list_with_modal_triggers_and_refresh_region(): void
    {
        $this->holiday();

        $this->actingAs($this->admin)->get(route('holidays.index', ['search' => 'Tết']))->assertOk()
            ->assertSee('id="holiday-list"', false)
            ->assertSee('hx-trigger="holidays-changed from:body"', false)
            ->assertSee('search=T', false) // vùng làm mới giữ bộ lọc hiện tại
            ->assertSee('hx-get="'.route('holidays.create').'"', false)
            ->assertSee('hx-target="#remote-modal-body"', false)
            ->assertSee('data-modal-size="md"', false)
            ->assertSee('id="remote-modal-body"', false)
            ->assertDontSee('onsubmit="return confirm', false);
    }

    public function test_create_returns_full_page_normally_and_fragment_for_htmx(): void
    {
        $this->actingAs($this->admin)->get(route('holidays.create'))->assertOk()
            ->assertSee('data-sidebar', false)
            ->assertSee('Thông tin ngày nghỉ')
            ->assertSee('Danh sách ngày nghỉ');

        $this->actingAs($this->admin)->get(route('holidays.create'), self::HX)->assertOk()
            ->assertHeader('Vary', 'HX-Request')
            ->assertDontSee('data-sidebar', false)
            ->assertDontSee('<html', false)
            ->assertDontSee('Danh sách ngày nghỉ')
            ->assertSee('Thêm ngày nghỉ')
            ->assertSee('id="modal-holiday-form"', false)
            ->assertSee('action="'.route('holidays.store').'"', false)
            ->assertSee('form="modal-holiday-form"', false);
    }

    public function test_edit_fragment_is_prefilled(): void
    {
        $holiday = $this->holiday();

        $this->actingAs($this->admin)->get(route('holidays.edit', $holiday), self::HX)->assertOk()
            ->assertDontSee('data-sidebar', false)
            ->assertSee('Sửa ngày nghỉ')
            ->assertSee('value="Tết Nguyên Đán 2027"', false)
            ->assertSee('action="'.route('holidays.update', $holiday).'"', false);
    }

    public function test_invalid_store_via_htmx_rerenders_form_with_errors_422(): void
    {
        $data = ['name' => 'Nghỉ thiếu ngày', 'start_date' => '2026-12-10', 'end_date' => '2026-12-01'];
        $message = $this->errorMessage(route('holidays.store'), 'post', $data, 'end_date');

        $response = $this->actingAs($this->admin)->post(route('holidays.store'), $data, self::HX);

        $response->assertStatus(422)
            ->assertDontSee('data-sidebar', false)
            ->assertSee('id="modal-holiday-form"', false)
            ->assertSee('value="Nghỉ thiếu ngày"', false) // giữ dữ liệu đã nhập
            ->assertSee('role="alert"', false)
            ->assertSee($message);
        $this->assertSame(0, Holiday::count());

        // Lỗi + old input chỉ sống trong request đó.
        $this->actingAs($this->admin)->get(route('holidays.create'), self::HX)->assertOk()
            ->assertDontSee('Nghỉ thiếu ngày')->assertDontSee('role="alert"', false);
    }

    public function test_valid_store_via_htmx_returns_204_with_triggers(): void
    {
        $response = $this->actingAs($this->admin)->post(route('holidays.store'), $this->payload(), self::HX);

        $response->assertNoContent();
        $triggers = $this->triggers($response);
        $this->assertTrue($triggers['close-modal']);
        $this->assertTrue($triggers['holidays-changed']);
        $this->assertSame('success', $triggers['toast']['type']);
        $this->assertStringStartsWith('Đã thêm ngày nghỉ.', $triggers['toast']['message']);
        $this->assertSame(1, Holiday::where('name', 'Nghỉ Giáng sinh')->count());
    }

    public function test_update_via_htmx_invalid_then_valid(): void
    {
        $holiday = $this->holiday();
        $message = $this->errorMessage(route('holidays.update', $holiday), 'put', $this->payload(['name' => '']), 'name');

        $this->actingAs($this->admin)->put(route('holidays.update', $holiday), $this->payload(['name' => '']), self::HX)
            ->assertStatus(422)
            ->assertSee('Sửa ngày nghỉ')
            ->assertSee('action="'.route('holidays.update', $holiday).'"', false)
            ->assertSee($message);
        $this->assertSame('Tết Nguyên Đán 2027', $holiday->fresh()->name);

        $response = $this->actingAs($this->admin)->put(route('holidays.update', $holiday), $this->payload(), self::HX);
        $response->assertNoContent();
        $triggers = $this->triggers($response);
        $this->assertTrue($triggers['close-modal']);
        $this->assertStringStartsWith('Đã cập nhật ngày nghỉ.', $triggers['toast']['message']);
        $this->assertSame('Nghỉ Giáng sinh', $holiday->fresh()->name);
    }

    public function test_destroy_via_htmx_returns_204_with_triggers(): void
    {
        $holiday = $this->holiday();

        $response = $this->actingAs($this->admin)->delete(route('holidays.destroy', $holiday), [], self::HX);

        $response->assertNoContent();
        $this->assertStringStartsWith('Đã xóa ngày nghỉ.', $this->triggers($response)['toast']['message']);
        $this->assertSoftDeleted($holiday);
    }

    public function test_non_htmx_requests_keep_redirect_behaviour(): void
    {
        $this->actingAs($this->admin)->post(route('holidays.store'), $this->payload())
            ->assertRedirect(route('holidays.index'))
            ->assertSessionHas('status', fn ($msg) => str_starts_with($msg, 'Đã thêm ngày nghỉ.'));

        $this->actingAs($this->admin)->from(route('holidays.create'))
            ->post(route('holidays.store'), ['name' => ''])
            ->assertRedirect(route('holidays.create'))
            ->assertSessionHasErrors(['name', 'start_date', 'end_date']);
    }

    public function test_htmx_requests_still_respect_permissions(): void
    {
        $staff = User::factory()->create(['is_active' => true, 'branch_id' => $this->branch->id]);
        $staff->assignRole('teacher');

        $this->actingAs($staff)->get(route('holidays.create'), self::HX)->assertForbidden();
        $this->actingAs($staff)->post(route('holidays.store'), ['name' => ''], self::HX)->assertForbidden();
    }

    private function holiday(): Holiday
    {
        return Holiday::create([
            'code' => 'HOL-2027-001', 'name' => 'Tết Nguyên Đán 2027',
            'start_date' => '2027-02-05', 'end_date' => '2027-02-12', 'is_system_wide' => true,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return [...['name' => 'Nghỉ Giáng sinh', 'start_date' => '2026-12-24', 'end_date' => '2026-12-25'], ...$overrides];
    }

    /** Thông báo lỗi mà request thường (redirect back) nhận được — để so với bản htmx. */
    private function errorMessage(string $url, string $method, array $data, string $field): string
    {
        $this->actingAs($this->admin)->{$method}($url, $data)->assertSessionHasErrors($field);

        return session('errors')->first($field);
    }

    private function triggers(TestResponse $response): array
    {
        return json_decode($response->headers->get('HX-Trigger'), true, flags: JSON_THROW_ON_ERROR);
    }
}
