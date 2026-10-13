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

    public function test_daily_report_saves_fields_and_creates_follow_up_task_once(): void
    {
        $staff = $this->makeUser('academic_staff', ['name' => 'Học vụ A']);
        $today = now()->toDateString();

        $this->actingAs($staff)->get(route('reports.periodic.daily'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Reports/DailyOps')->where('date', $today)
                ->has('sections', 3)->where('assignees.0.value', $staff->id)->where('task', null));

        // Không nhập gì → báo lỗi; bật Tạo Task mà thiếu tiêu đề → báo lỗi.
        $this->actingAs($staff)->post(route('reports.periodic.daily.store'), ['date' => $today, 'fields' => []])->assertSessionHasErrors('fields');
        $this->actingAs($staff)->post(route('reports.periodic.daily.store'), [
            'date' => $today, 'fields' => ['classes_planned' => 5], 'task' => ['enabled' => 1],
        ])->assertSessionHasErrors(['task.title', 'task.description', 'task.due_date', 'task.assignee_id']);
        // Ngày chưa tới không nộp được.
        $this->actingAs($staff)->post(route('reports.periodic.daily.store'), ['date' => now()->addDay()->toDateString(), 'fields' => ['classes_planned' => 5]])
            ->assertSessionHasErrors('date');

        $task = ['enabled' => 1, 'title' => 'Sửa điều hòa phòng 302', 'description' => 'Gọi bảo trì', 'due_date' => $today, 'assignee_id' => $staff->id];
        $this->actingAs($staff)->post(route('reports.periodic.daily.store'), [
            'date' => $today, 'fields' => ['classes_planned' => 12, 'classes_actual' => 11, 'facilities' => 'Phòng 302 hỏng điều hòa'], 'task' => $task,
        ])->assertSessionHasNoErrors()->assertRedirect(route('reports.periodic.daily', ['date' => $today]));
        // Lưu lại trong ngày là cập nhật, không tạo thêm việc.
        $this->actingAs($staff)->post(route('reports.periodic.daily.store'), [
            'date' => $today, 'fields' => ['classes_planned' => 12, 'classes_actual' => 12], 'task' => $task,
        ])->assertSessionHasNoErrors();

        $report = StaffReport::sole();
        $this->assertSame(['daily_ops', 'daily', $today], [$report->kind, $report->type, $report->period_key]);
        $this->assertSame(12, $report->data['fields']['classes_actual']);
        $this->assertNull($report->data['fields']['facilities']);
        $this->assertStringContainsString('Số lớp thực tế: 12', $report->content);

        $created = WorkTask::sole();
        $this->assertSame($report->data['task_id'], $created->id);
        $this->assertSame([$staff->id, $staff->id, 'Sửa điều hòa phòng 302'], [(int) $created->creator_id, (int) $created->assignee_id, $created->title]);

        $this->actingAs($staff)->get(route('reports.periodic.daily', ['date' => $today]))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('values.classes_actual', 12)->where('task.title', 'Sửa điều hòa phòng 302')->has('history', 1));

        // Việc follow-up chỉ giao cho người mình được giao việc (tài khoản đã khóa thì không).
        $stranger = $this->makeUser('teacher_fulltime', ['is_active' => false]);
        $yesterday = now()->subDay()->toDateString();
        $this->actingAs($staff)->post(route('reports.periodic.daily.store'), [
            'date' => $yesterday, 'fields' => ['classes_planned' => 3], 'task' => [...$task, 'assignee_id' => $stranger->id],
        ])->assertSessionHasErrors('task.assignee_id');
        $this->assertSame(1, WorkTask::count());

        // Vai trò khác không vào được báo cáo ngày Học vụ.
        $this->actingAs($this->makeUser('teacher_fulltime'))->post(route('reports.periodic.daily.store'), ['date' => $today, 'fields' => ['classes_planned' => 1]])
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
        $this->actingAs($staff)->post(route('reports.periodic.daily.store'), [
            'date' => $today, 'fields' => ['classes_planned' => 12, 'classes_actual' => 11, 'teachers' => 9, 'assistants' => 6, 'new_data' => 8],
        ])->assertSessionHasNoErrors();
        $lead = $this->makeUser('academic_lead', ['name' => 'Học thuật B']);
        $this->actingAs($lead)->post(route('reports.periodic.academic-weekly.store'), ['week' => ReportPeriod::currentWeek(), 'rows' => ['done' => [['content' => 'Dự giờ']]]])
            ->assertSessionHasNoErrors();

        $admin = $this->makeUser('admin');
        $this->actingAs($admin)->get(route('academic.reports', ['tab' => 'daily']))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Academic/Reports')
                ->has('staffDailyReports', 1)
                ->where('staffDailyReports.0.user', 'Học vụ A')
                ->where('staffDailyReports.0.classes', '11 / 12')
                ->where('staffDailyReports.0.staff', '9 GV · 6 TG')
                ->where('staffDailyReports.0.enrollment', 'Data 8')
                ->where('totalDailyReportsToday', 1));

        $this->actingAs($admin)->get(route('academic.reports', ['tab' => 'weekly']))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('weeklyReports', 1)->where('weeklyReports.0.user', 'Học thuật B')
                ->where('weeklyReports.0.content', fn ($content) => str_contains($content, 'Dự giờ'))->has('weeklyKpiReports', 0));
    }
}
