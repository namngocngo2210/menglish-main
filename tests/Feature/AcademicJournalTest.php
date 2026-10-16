<?php

namespace Tests\Feature;

use App\Models\AdminNotification;
use App\Models\Branch;
use App\Models\StaffReport;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Nhật ký Học vụ (mockup 02/05): ghi sự việc bằng mã KPI Học vụ + nội dung; chỉ Admin xác nhận "Lỗi nghiêm trọng".
 * Báo cáo định kỳ nộp xong được đẩy về Admin kiểm tra ở "Tổng hợp báo cáo & nhật ký" (14/10/2026).
 */
class AcademicJournalTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->branch = Branch::create(['name' => 'Cơ sở A', 'code' => 'CA', 'is_active' => true]);
    }

    private function makeUser(string $role, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge(['branch_id' => $this->branch->id, 'is_active' => true], $attributes));
        $user->assignRole($role);

        return $user;
    }

    public function test_staff_logs_entry_with_kpi_code_and_content_only(): void
    {
        $staff = $this->makeUser('academic_staff', ['name' => 'Nguyễn Thị A']);

        $this->actingAs($staff)->get(route('reports.journal'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Reports/Journal')
                ->where('canConfirmSerious', false)
                ->where('kpiOptions.0.value', '1.1'));

        $this->actingAs($staff)->post(route('reports.journal.store'), ['kpi_code' => '', 'content' => ''])
            ->assertSessionHasErrors('content');
        $this->actingAs($staff)->post(route('reports.journal.store'), [
            'kpi_code' => '1.2', 'content' => "Học viên B thắc mắc điểm danh ngày 08/10.\nĐã cập nhật lại hệ thống.",
        ])->assertSessionHasNoErrors();

        $entry = StaffReport::where('type', 'journal')->sole();
        $this->assertSame('1.2', $entry->kpi_code);
        $this->assertSame('Học viên B thắc mắc điểm danh ngày 08/10.', $entry->title);
        $this->assertSame('normal', $entry->severity);
        $this->assertNull($entry->serious_confirmed_at);

        $this->actingAs($staff)->get(route('reports.journal'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('journals.data.0.kpi_code', '1.2')
                ->where('journals.data.0.serious', false)
                ->where('journals.data.0.user', 'Nguyễn Thị A')
                ->where('journals.data.0.user_role', 'Học vụ'));
    }

    public function test_only_admin_confirms_serious_error(): void
    {
        $staff = $this->makeUser('academic_staff');
        $manager = $this->makeUser('manager');
        $admin = $this->makeUser('admin', ['name' => 'Admin Liên']);
        $entry = StaffReport::create(['user_id' => $staff->id, 'type' => 'journal', 'title' => 'GV nghỉ gấp', 'content' => 'GV nghỉ gấp', 'severity' => 'normal', 'report_date' => today(), 'status' => 'open']);

        $this->actingAs($staff)->post(route('reports.journal.serious', $entry->id), ['serious' => 1])->assertForbidden();
        $this->actingAs($manager)->post(route('reports.journal.serious', $entry->id), ['serious' => 1])->assertForbidden();
        $this->assertNull($entry->fresh()->serious_confirmed_at);

        $this->actingAs($admin)->get(route('reports.journal'))->assertInertia(fn (AssertableInertia $page) => $page->where('canConfirmSerious', true));
        $this->actingAs($admin)->post(route('reports.journal.serious', $entry->id), ['serious' => 1])->assertRedirect();
        $this->assertSame($admin->id, $entry->fresh()->serious_confirmed_by);

        $this->actingAs($staff)->get(route('reports.journal'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('journals.data.0.serious', true)
                ->where('journals.data.0.serious_by', 'Admin Liên'));

        $this->actingAs($admin)->post(route('reports.journal.serious', $entry->id), ['serious' => 0]);
        $this->assertNull($entry->fresh()->serious_confirmed_by);
        $this->assertNull($entry->fresh()->serious_confirmed_at);
    }

    public function test_submitted_report_is_pushed_to_admin_for_review(): void
    {
        $staff = $this->makeUser('academic_staff', ['name' => 'Học vụ A']);
        $manager = $this->makeUser('manager');
        $admin = $this->makeUser('admin');
        $today = now()->toDateString();

        $this->actingAs($staff)->post(route('reports.periodic.daily.store'), ['date' => $today, 'notes' => ['operations' => 'Ghi chú 5']])
            ->assertSessionHasNoErrors();
        $report = StaffReport::where('kind', 'daily_ops')->sole();
        $this->assertNull($report->reviewed_at);
        // Báo cáo ngày không báo Admin từng bản (rà soát 16/10/2026): Admin nhận bản tổng hợp mỗi sáng (reports:daily-digest).
        $this->assertSame(0, AdminNotification::where('user_id', $admin->id)->where('type', 'staff_report_submitted')->count());

        $this->actingAs($staff)->post(route('reports.periodic.daily.store'), ['date' => $today, 'notes' => ['operations' => 'Ghi chú 6']]);

        $this->actingAs($admin)->get(route('reports.all', ['review' => 'pending']))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Reports/All')
                ->where('canReview', true)->where('stats.pending_review', 1)
                ->where('reports.data.0.id', $report->id)->where('reports.data.0.reviewed_at', null));

        $this->actingAs($manager)->post(route('reports.review', $report->id))->assertForbidden();
        $this->actingAs($admin)->post(route('reports.review', $report->id), ['reviewed' => 1])->assertRedirect();
        $this->assertSame($admin->id, $report->fresh()->reviewed_by);
        // Người viết được báo báo cáo đã được kiểm tra.
        $this->assertSame(1, AdminNotification::where('user_id', $staff->id)->where('type', 'staff_report_reviewed')->count());
        $this->actingAs($admin)->get(route('reports.all', ['review' => 'pending']))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('stats.pending_review', 0)->has('reports.data', 0));

        // Sửa sau khi Admin đã kiểm tra → về lại chờ kiểm tra.
        $this->actingAs($staff)->post(route('reports.periodic.daily.store'), ['date' => $today, 'notes' => ['operations' => 'Ghi chú 7']]);
        $this->assertNull($report->fresh()->reviewed_at);
    }

    public function test_academic_lead_creates_daily_report_for_admin_review(): void
    {
        $lead = $this->makeUser('academic_lead', ['name' => 'Học thuật B']);
        $admin = $this->makeUser('admin');
        $today = now()->toDateString();

        // Cùng biểu mẫu báo cáo ngày Học vụ; tab Ngày đứng trước Tuần / Tháng / Quý, báo cáo chính vẫn là tuần.
        $this->actingAs($lead)->get(route('reports.periodic.daily'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Reports/DailyOps')->where('audience', 'Học thuật')
                ->has('sections', 4)->has('tabs', 4)->where('tabs.0.active', true));
        $this->actingAs($lead)->get(route('reports.my'))->assertRedirect(route('reports.periodic.academic-weekly'));

        $this->actingAs($lead)->post(route('reports.periodic.daily.store'), ['date' => $today, 'notes' => ['projects' => 'Ghi chú 4']])
            ->assertSessionHasNoErrors();
        $report = StaffReport::where('kind', 'daily_ops')->sole();
        $this->assertSame($lead->id, (int) $report->user_id);

        $this->actingAs($admin)->get(route('reports.all', ['review' => 'pending']))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('reports.data.0.id', $report->id)->where('reports.data.0.for', 'Học thuật'));
    }
}
