<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\Course;
use App\Models\StaffReport;
use App\Models\User;
use App\Models\WorkTask;
use App\Support\ReportPeriod;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Báo cáo có cấu trúc theo mockup: báo cáo ngày Học vụ (02/06), báo cáo tuần Học thuật (02/18), báo cáo tháng GV (03/17, nháp / nộp),
 * phân loại theo `kind` (1 tài khoản vừa Học thuật vừa GV không bị ghi đè) và màn Admin xem tổng.
 */
class StructuredStaffReportsTest extends TestCase
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

    private function makeUser(string|array $roles, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge(['branch_id' => $this->branch->id, 'is_active' => true], $attributes));
        $user->assignRole($roles);

        return $user;
    }

    public function test_daily_report_saves_notes_and_creates_follow_up_tasks(): void
    {
        $staff = $this->makeUser('academic_staff', ['name' => 'Học vụ A']);
        $today = now()->toDateString();

        // Số liệu lấy từ hệ thống (DailyReportMetricsTest); người viết chỉ nhập ghi chú từng khối.
        $this->actingAs($staff)->get(route('reports.periodic.daily', ['date' => $today]))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Reports/DailyOps')->where('date', $today)
                ->has('sections', 3)->where('live', true)->where('assignees.0.value', $staff->id)->where('tasks', []));

        // Task thiếu tiêu đề / hạn đã qua → báo lỗi; ngày chưa tới không nộp được.
        $this->actingAs($staff)->post(route('reports.periodic.daily.store'), ['date' => $today, 'tasks' => [['title' => '', 'due_date' => now()->subDay()->toDateString()]]])
            ->assertSessionHasErrors(['tasks.0.title', 'tasks.0.due_date']);
        $this->actingAs($staff)->post(route('reports.periodic.daily.store'), ['date' => now()->addDay()->toDateString()])
            ->assertSessionHasErrors('date');

        // Rà soát 16/10/2026: mỗi báo cáo nhiều task, mỗi task có tiêu đề, nội dung, hạn (từ hôm nay) và người phụ trách (để trống là mình).
        $this->actingAs($staff)->post(route('reports.periodic.daily.store'), [
            'date' => $today, 'notes' => ['operations' => 'Phòng 302 hỏng điều hòa'],
            'tasks' => [
                ['title' => 'Gọi thợ sửa điều hòa 302', 'content' => 'Hỏng từ chiều', 'due_date' => $today, 'assignee_id' => ''],
                ['title' => 'Báo phụ huynh lớp ST1 đổi phòng', 'due_date' => now()->addDay()->toDateString()],
            ],
        ])->assertSessionHasNoErrors()->assertRedirect(route('reports.periodic.daily', ['date' => $today]));
        // Lưu lại không gửi task mới → giữ task cũ, không tạo thêm; gửi thêm task → cộng vào.
        $this->actingAs($staff)->post(route('reports.periodic.daily.store'), [
            'date' => $today, 'notes' => ['operations' => 'Đã báo sửa điều hòa'],
        ])->assertSessionHasNoErrors();
        $this->actingAs($staff)->post(route('reports.periodic.daily.store'), [
            'date' => $today, 'notes' => ['operations' => 'Đã báo sửa điều hòa'], 'tasks' => [['title' => 'Kiểm tra lại điều hòa', 'due_date' => $today]],
        ])->assertSessionHasNoErrors();

        $report = StaffReport::sole();
        $this->assertSame(['daily_ops', 'daily', $today], [$report->kind, $report->type, $report->period_key]);
        $this->assertSame(0, $report->data['fields']['classes_actual']);
        $this->assertSame(['operations' => 'Đã báo sửa điều hòa'], $report->data['notes']);
        $this->assertStringContainsString('Số lớp thực tế: 0', $report->content);

        $tasks = WorkTask::orderBy('id')->get();
        $this->assertSame($tasks->pluck('id')->all(), $report->data['task_ids']);
        $this->assertSame(['Gọi thợ sửa điều hòa 302', 'Báo phụ huynh lớp ST1 đổi phòng', 'Kiểm tra lại điều hòa'], $tasks->pluck('title')->all());
        $this->assertSame([$staff->id, $staff->id], [(int) $tasks[0]->creator_id, (int) $tasks[0]->assignee_id]);
        $this->assertStringContainsString('Hỏng từ chiều', $tasks[0]->description);

        $this->actingAs($staff)->get(route('reports.periodic.daily', ['date' => $today]))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('live', false)->where('notes.operations', 'Đã báo sửa điều hòa')
                ->has('tasks', 3)->where('tasks.0.title', 'Gọi thợ sửa điều hòa 302')->where('history.0.period', $today)->where('history.0.missing', false));

        // Việc follow-up chỉ giao cho người mình được giao việc (tài khoản đã khóa thì không).
        $stranger = $this->makeUser('teacher_fulltime', ['is_active' => false]);
        $yesterday = now()->subDay()->toDateString();
        $this->actingAs($staff)->post(route('reports.periodic.daily.store'), [
            'date' => $yesterday, 'tasks' => [['title' => 'Việc', 'due_date' => $today, 'assignee_id' => $stranger->id]],
        ])->assertSessionHasErrors('tasks.0.assignee_id');
        $this->assertSame(3, WorkTask::count());

        // Vai trò khác không vào được báo cáo ngày Học vụ.
        $this->actingAs($this->makeUser('teacher_fulltime'))->post(route('reports.periodic.daily.store'), ['date' => $today])
            ->assertForbidden();
    }

    public function test_academic_weekly_report_saves_rows_per_section(): void
    {
        $lead = $this->makeUser('academic_lead');
        $week = ReportPeriod::currentWeek();

        $this->actingAs($lead)->get(route('reports.periodic.academic-weekly'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Reports/AcademicWeekly')->where('week', $week)->has('sections', 3));

        $this->actingAs($lead)->post(route('reports.periodic.academic-weekly.store'), ['week' => $week, 'rows' => ['done' => [['content' => '']]]])
            ->assertSessionHasErrors('rows');
        $this->actingAs($lead)->post(route('reports.periodic.academic-weekly.store'), [
            'week' => $week, 'rows' => ['done' => [['content' => 'Rà soát đề'], ['detail' => 'Thiếu nội dung']]],
        ])->assertSessionHasErrors('rows.done.1.content');
        $this->actingAs($lead)->post(route('reports.periodic.academic-weekly.store'), [
            'week' => $week, 'rows' => ['done' => [['content' => 'Rà soát đề', 'link' => 'không phải link']]],
        ])->assertSessionHasErrors('rows.done.0.link');

        $this->actingAs($lead)->post(route('reports.periodic.academic-weekly.store'), [
            'week' => $week,
            'rows' => [
                'done' => [['content' => 'Rà soát đề Big Test', 'deadline' => '2026-10-16', 'progress' => '100'], ['content' => '']],
                'planned' => [['content' => 'Chuẩn bị giáo trình Movers']],
                'requests' => [['content' => 'Mua loa phòng 302', 'support' => 'Cần Học vụ duyệt chi']],
            ],
        ])->assertSessionHasNoErrors();

        $report = StaffReport::sole();
        $this->assertSame(['academic_weekly', 'weekly', $week], [$report->kind, $report->type, $report->period_key]);
        $this->assertCount(1, $report->data['rows']['done']);
        $this->assertSame(100, $report->data['rows']['done'][0]['progress']);
        $this->assertStringContainsString('Rà soát đề Big Test (deadline 16/10/2026, tiến độ 100%)', $report->content);
        $this->assertStringContainsString('Đề xuất HT: Cần Học vụ duyệt chi', $report->content);

        // Báo cáo tháng Học thuật thấy tuần này đã nộp, kèm người nộp.
        $this->actingAs($lead)->get(route('reports.periodic.academic-monthly'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('weeklyReports', fn ($weeks) => collect($weeks)->contains(fn ($w) => $w['week'] === $week && $w['pic'] === $lead->name)));
    }

    public function test_dual_role_account_keeps_academic_and_teacher_monthly_reports_apart(): void
    {
        $user = $this->makeUser(['academic_lead', 'teacher_fulltime'], ['name' => 'Cô Hoa']);
        $month = ReportPeriod::currentMonth();

        $this->actingAs($user)->post(route('reports.periodic.academic-monthly.store'), ['month' => $month, 'narrative' => ['overall' => 'Tháng ổn định']])
            ->assertSessionHasNoErrors();
        $this->actingAs($user)->post(route('reports.periodic.teacher-monthly.store'), ['month' => $month, 'general' => ['progress' => 'Xong Unit 3']])
            ->assertSessionHasNoErrors();
        $this->actingAs($user)->post(route('reports.periodic.teacher-attendance.store'), ['month' => $month, 'note' => 'Dạy bù 1 buổi'])
            ->assertSessionHasNoErrors();

        $this->assertSame(3, StaffReport::count());
        $this->assertSame('Tháng ổn định', StaffReport::where('kind', 'academic_monthly')->sole()->data['narrative']['overall']);
        $this->assertSame('Xong Unit 3', StaffReport::where('kind', 'teacher_monthly')->sole()->data['general']['progress']);
        $this->assertSame('Dạy bù 1 buổi', StaffReport::where('kind', 'teacher_attendance')->sole()->data['note']);
    }

    public function test_teacher_monthly_draft_stays_private_until_submitted(): void
    {
        $teacher = $this->makeUser('teacher_fulltime', ['name' => 'Cô Lan']);
        $course = Course::create(['name' => 'Starters', 'code' => 'ST', 'total_lessons' => 24, 'tuition_fee' => 3000000, 'is_active' => true]);
        $class = ClassModel::create([
            'name' => 'Lớp ST1', 'code' => 'ST1', 'course_id' => $course->id, 'branch_id' => $this->branch->id, 'teacher_id' => $teacher->id,
            'start_date' => now()->subMonth(), 'end_date' => now()->addMonths(2), 'max_capacity' => 12, 'status' => 'active',
        ]);
        $month = ReportPeriod::currentMonth();
        $payload = ['month' => $month, 'classes' => [$class->id => [
            'sessions' => 8, 'program_progress' => 'on_track', 'checks' => ['syllabus_provided' => '1'], 'situation' => '2 bạn hay đi muộn', 'support_note' => 'Cần TA kèm',
        ]]];

        $this->actingAs($teacher)->post(route('reports.periodic.teacher-monthly.store'), [...$payload, 'intent' => 'draft'])->assertSessionHasNoErrors();
        $report = StaffReport::sole();
        $this->assertSame(['teacher_monthly', 'draft'], [$report->kind, $report->status]);
        $row = $report->data['classes'][$class->id];
        $this->assertSame([8, 'on_track', '2 bạn hay đi muộn', true], [$row['sessions'], $row['program_progress'], $row['situation'], $row['need_support']]);
        $this->assertTrue($row['checks']['syllabus_provided']);

        $admin = $this->makeUser('admin');
        $this->actingAs($admin)->get(route('academic.reports', ['tab' => 'monthly']))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('monthlyReports', 0));

        $this->actingAs($teacher)->post(route('reports.periodic.teacher-monthly.store'), [...$payload, 'intent' => 'submit'])->assertSessionHasNoErrors();
        // Đã nộp rồi thì lưu nháp lại không kéo về nháp.
        $this->actingAs($teacher)->post(route('reports.periodic.teacher-monthly.store'), [...$payload, 'intent' => 'draft'])->assertSessionHasNoErrors();
        $this->assertSame('submitted', StaffReport::sole()->status);

        $this->actingAs($admin)->get(route('academic.reports', ['tab' => 'monthly']))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('monthlyReports', 1)->where('monthlyReports.0.user', 'Cô Lan')
                ->where('monthlyReports.0.content', fn ($content) => str_contains($content, '2 bạn hay đi muộn')));
    }

    public function test_admin_daily_tab_lists_operations_daily_reports(): void
    {
        $staff = $this->makeUser('academic_staff', ['name' => 'Học vụ A']);
        $today = now()->toDateString();
        $this->actingAs($staff)->post(route('reports.periodic.daily.store'), ['date' => $today, 'notes' => ['operations' => 'Ổn định']])
            ->assertSessionHasNoErrors();
        $lead = $this->makeUser('academic_lead', ['name' => 'Học thuật B']);
        $this->actingAs($lead)->post(route('reports.periodic.academic-weekly.store'), ['week' => ReportPeriod::currentWeek(), 'rows' => ['done' => [['content' => 'Dự giờ']]]])
            ->assertSessionHasNoErrors();

        $admin = $this->makeUser('admin');
        $this->actingAs($admin)->get(route('academic.reports', ['tab' => 'daily']))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Academic/Reports')
                ->has('staffDailyReports', 1)
                ->where('staffDailyReports.0.user', 'Học vụ A')
                ->where('staffDailyReports.0.classes', '0 / 0')
                ->where('staffDailyReports.0.staff', '0 GV · 0 TG')
                ->where('staffDailyReports.0.enrollment', 'Data 0 · Test 0 · Thu phí 0')
                ->where('totalDailyReportsToday', 1));

        $this->actingAs($admin)->get(route('academic.reports', ['tab' => 'weekly']))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('weeklyReports', 1)->where('weeklyReports.0.user', 'Học thuật B')
                ->where('weeklyReports.0.content', fn ($content) => str_contains($content, 'Dự giờ'))->has('weeklyKpiReports', 0));
    }
}
