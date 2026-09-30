<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Holiday;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\InteractsWithInertia;
use Tests\TestCase;

/**
 * Hạ tầng modal (Inertia) làm mẫu với Ngày nghỉ: cùng route trả trang đầy đủ (mở thẳng URL) hoặc nội dung modal
 * (header X-Remote-Modal → prop asModal); lưu từ modal → quay lại trang đang mở kèm thông báo; lỗi validate → về lại kèm lỗi.
 */
class HolidayModalTest extends TestCase
{
    use InteractsWithInertia;
    use RefreshDatabase;

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

    public function test_index_renders_list_with_modal_triggers(): void
    {
        $holiday = $this->holiday();

        $this->actingAs($this->admin)->get(route('holidays.index', ['search' => 'Tết']))->assertOk()
            ->assertSee('Danh sách ngày nghỉ')
            ->assertSee('Tết Nguyên Đán 2027')
            ->assertSee('05/02/2027')
            ->assertSee('Toàn hệ thống')
            ->assertSee('href="'.route('holidays.create', absolute: false).'"', false)
            ->assertSee('href="'.route('holidays.edit', $holiday, absolute: false).'"', false)
            ->assertSee('Thêm ngày nghỉ')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Holidays/Index')
                ->where('canManage', true)
                ->where('holidays.total', 1)
                ->where('holidays.data.0.name', 'Tết Nguyên Đán 2027')
                ->where('holidays.data.0.start_date', '2027-02-05'));

        $this->actingAs($this->admin)->get(route('holidays.index', ['search' => 'không có']))->assertOk()
            ->assertSee('Không tìm thấy ngày nghỉ')
            ->assertDontSee('Tết Nguyên Đán 2027');
    }

    public function test_create_returns_full_page_normally_and_modal_content_from_modal(): void
    {
        $this->actingAs($this->admin)->get(route('holidays.create'))->assertOk()
            ->assertSee('data-sidebar', false)
            ->assertSee('Thông tin ngày nghỉ')
            ->assertSee('Danh sách ngày nghỉ')
            ->assertSee('Cầu Giấy')
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Holidays/Form')->where('asModal', false)->has('holidays.data'));

        $this->actingAs($this->admin)->get(route('holidays.create'), self::MODAL)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Holidays/Form')
                ->where('asModal', true)
                ->where('holiday', null)
                ->where('branches.0.label', 'Cầu Giấy')
                ->missing('holidays'));
    }

    public function test_edit_is_prefilled(): void
    {
        $holiday = $this->holiday();

        $this->actingAs($this->admin)->get(route('holidays.edit', $holiday))->assertOk()
            ->assertSee('Sửa ngày nghỉ')
            ->assertSee('value="Tết Nguyên Đán 2027"', false)
            ->assertSee('value="2027-02-05"', false);

        $this->actingAs($this->admin)->get(route('holidays.edit', $holiday), self::MODAL)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Holidays/Form')
                ->where('asModal', true)
                ->where('holiday.id', $holiday->id)
                ->where('holiday.name', 'Tết Nguyên Đán 2027')
                ->where('holiday.start_date', '2027-02-05'));
    }

    public function test_invalid_store_from_modal_returns_to_current_page_with_errors(): void
    {
        $data = ['name' => 'Nghỉ thiếu ngày', 'start_date' => '2026-12-10', 'end_date' => '2026-12-01'];

        $this->actingAs($this->admin)->from(route('holidays.index'))
            ->post(route('holidays.store'), $data, self::MODAL)
            ->assertRedirect(route('holidays.index'))
            ->assertSessionHasErrors('end_date');
        $this->assertSame(0, Holiday::count());
    }

    public function test_valid_store_from_modal_returns_to_current_page_with_message(): void
    {
        $this->actingAs($this->admin)->from(route('holidays.index', ['page' => 2]))
            ->post(route('holidays.store'), $this->payload(), self::MODAL)
            ->assertRedirect(route('holidays.index', ['page' => 2]))
            ->assertSessionHas('status', fn ($msg) => str_starts_with($msg, 'Đã thêm ngày nghỉ.'));
        $this->assertSame(1, Holiday::where('name', 'Nghỉ Giáng sinh')->count());

        // Thông báo hiện thành toast trên trang vừa quay lại.
        $this->actingAs($this->admin)->get(route('holidays.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('flash.0.type', 'success'));
    }

    public function test_store_with_branches_limits_scope(): void
    {
        $this->actingAs($this->admin)->from(route('holidays.index'))
            ->post(route('holidays.store'), $this->payload(['branch_ids' => [$this->branch->id]]), self::MODAL)
            ->assertRedirect(route('holidays.index'));

        $holiday = Holiday::where('name', 'Nghỉ Giáng sinh')->firstOrFail();
        $this->assertFalse((bool) $holiday->is_system_wide);
        $this->assertSame([$this->branch->id], $holiday->branches->pluck('id')->all());
    }

    public function test_update_from_modal_invalid_then_valid(): void
    {
        $holiday = $this->holiday();

        $this->actingAs($this->admin)->from(route('holidays.index'))
            ->put(route('holidays.update', $holiday), $this->payload(['name' => '']), self::MODAL)
            ->assertRedirect(route('holidays.index'))
            ->assertSessionHasErrors('name');
        $this->assertSame('Tết Nguyên Đán 2027', $holiday->fresh()->name);

        $this->actingAs($this->admin)->from(route('holidays.index'))
            ->put(route('holidays.update', $holiday), $this->payload(), self::MODAL)
            ->assertRedirect(route('holidays.index'))
            ->assertSessionHas('status', fn ($msg) => str_starts_with($msg, 'Đã cập nhật ngày nghỉ.'));
        $this->assertSame('Nghỉ Giáng sinh', $holiday->fresh()->name);
    }

    public function test_destroy_returns_with_message(): void
    {
        $holiday = $this->holiday();

        $this->actingAs($this->admin)->from(route('holidays.index', ['search' => 'Tết']))
            ->delete(route('holidays.destroy', $holiday), [], self::MODAL)
            ->assertRedirect(route('holidays.index', ['search' => 'Tết']))
            ->assertSessionHas('status', fn ($msg) => str_starts_with($msg, 'Đã xóa ngày nghỉ.'));
        $this->assertSoftDeleted($holiday);
    }

    public function test_full_page_form_redirects_to_list(): void
    {
        $this->actingAs($this->admin)->post(route('holidays.store'), $this->payload())
            ->assertRedirect(route('holidays.index'))
            ->assertSessionHas('status', fn ($msg) => str_starts_with($msg, 'Đã thêm ngày nghỉ.'));

        $this->actingAs($this->admin)->from(route('holidays.create'))
            ->post(route('holidays.store'), ['name' => ''])
            ->assertRedirect(route('holidays.create'))
            ->assertSessionHasErrors(['name', 'start_date', 'end_date']);
    }

    public function test_modal_requests_still_respect_permissions(): void
    {
        $staff = User::factory()->create(['is_active' => true, 'branch_id' => $this->branch->id]);
        $staff->assignRole('teacher');

        $this->actingAs($staff)->get(route('holidays.create'), self::MODAL)->assertForbidden();
        $this->actingAs($staff)->post(route('holidays.store'), ['name' => ''], self::MODAL)->assertForbidden();
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
}
