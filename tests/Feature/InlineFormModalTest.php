<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\StaffReport;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Màn danh sách không còn form tạo nằm cạnh: form ở modal mở bằng nút trên đầu trang.
 * Lỗi validate → trang tải lại mở sẵn đúng modal (trường ẩn _modal) kèm dữ liệu đã nhập.
 */
class InlineFormModalTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $branch = Branch::create(['name' => 'Cầu Giấy', 'code' => 'CG-IM', 'is_active' => true]);
        $this->admin = User::factory()->create(['is_active' => true, 'branch_id' => $branch->id]);
        $this->admin->assignRole('admin');
    }

    /** @return array<string, array{string, string}> */
    public static function pages(): array
    {
        return [
            'nhắc nợ' => ['system-config.debt-reminders', 'new-reminder'],
        ];
    }

    #[DataProvider('pages')]
    public function test_create_form_lives_in_a_closed_modal_opened_by_a_button(string $route, string $modal): void
    {
        $this->actingAs($this->admin)->get(route($route))->assertOk()
            ->assertSee("\$dispatch('open-modal', '{$modal}')", false)
            ->assertSee('data-modal="'.$modal.'"', false)
            ->assertSee('show: false', false);
    }

    /** Phụ đạo (trang Vue): form nằm trong modal đóng sẵn, mở bằng nút trên đầu trang; lỗi validate trả về trang đang mở. */
    public function test_support_session_form_lives_in_a_closed_modal(): void
    {
        $this->actingAs($this->admin)->get(route('tasks.support-sessions'))->assertOk()
            ->assertSee('Xếp buổi phụ đạo')
            ->assertSee('id="new-support-session-form"', false)
            ->assertInertia(fn ($page) => $page->component('Tasks/SupportSessions')->where('selectedSupport', null));

        $this->actingAs($this->admin)->from(route('tasks.support-sessions'))
            ->post(route('tasks.support-sessions.store'), [])
            ->assertRedirect(route('tasks.support-sessions'))
            ->assertSessionHasErrors('session_date');
    }

    /** Trang Vue (Đợt khảo sát): form tạo nằm trong hộp thoại đóng sẵn (UiModal, v-show), mở bằng nút "Tạo đợt khảo sát". */
    public function test_survey_create_form_lives_in_a_closed_vue_modal(): void
    {
        $html = $this->actingAs($this->admin)->get(route('surveys.index'))->assertOk()
            ->assertSee('Tạo đợt khảo sát')
            ->assertSee('Tạo đợt khảo sát mới')
            ->assertSee('id="new-survey-form"', false)
            ->getContent();

        // Khung hộp thoại chứa form đang ẩn (display: none) khi mới mở trang.
        $form = strpos($html, 'id="new-survey-form"');
        $dialog = strrpos(substr($html, 0, $form), 'data-modal');
        $this->assertNotFalse($dialog);
        $this->assertMatchesRegularExpression('/<div[^>]*style="display:\s*none;?"[^>]*data-modal|<div[^>]*data-modal[^>]*style="display:\s*none;?"/', substr($html, strrpos(substr($html, 0, $dialog), '<div'), $form));
    }

    /** Nhật ký sự vụ đã sang Vue: form tạo nằm trong UiModal đóng sẵn; lỗi validate trả về kèm lỗi, modal (giữ state) vẫn mở. */
    public function test_journal_create_form_lives_in_a_closed_vue_modal(): void
    {
        $this->actingAs($this->admin)->get(route('reports.journal'))->assertOk()
            ->assertSee('Ghi nhận sự vụ mới')
            ->assertSee('id="new-journal-form"', false)
            ->assertSee('form="new-journal-form"', false)
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Reports/Journal'));
    }

    public function test_validation_error_returns_with_errors_and_entered_values(): void
    {
        $this->actingAs($this->admin)->from(route('reports.journal'))
            ->post(route('reports.journal.store'), ['title' => '', 'severity' => 'normal', 'content' => 'Mô tả giữ lại'])
            ->assertRedirect(route('reports.journal'))
            ->assertSessionHasErrors('title')
            ->assertSessionHasInput('content', 'Mô tả giữ lại');
        $this->assertSame(0, StaffReport::count());

        $this->actingAs($this->admin)->get(route('reports.journal'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Reports/Journal')->has('errors.title'));
    }
}
