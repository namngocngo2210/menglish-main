<?php

namespace Tests\Feature;

use App\Models\AcademicProject;
use App\Models\AcademicProjectMilestone;
use App\Models\AdminNotification;
use App\Models\BigTest;
use App\Models\Branch;
use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\CrmCustomer;
use App\Models\CrmCustomerHistory;
use App\Models\KpiCriterion;
use App\Models\MaterialOrder;
use App\Models\StaffReport;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Models\User;
use App\Models\WorkTask;
use App\Support\ReportPeriod;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Báo cáo tuần lấy số liệu từ hệ thống (chủ dự án 15/10/2026): báo cáo tuần KPI Học vụ tự tính 9 chỉ số quy mô và số lần
 * phát sinh từng mục KPI (nguồn tự động / Nhật ký Học vụ), kèm ghi chú các báo cáo ngày; báo cáo tuần Học thuật điền sẵn dòng.
 */
class WeeklyReportMetricsTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private ClassModel $class;

    private ClassModel $other;

    private User $teacher;

    private string $week;

    private Carbon $monday;

    protected function setUp(): void
    {
        parent::setUp();

        // Thứ 4 trong tuần: thứ 2, thứ 3 đã qua (phải nộp báo cáo ngày).
        Carbon::setTestNow(Carbon::parse('2026-10-14 10:00'));
        $this->week = ReportPeriod::currentWeek();
        $this->monday = Carbon::parse('2026-10-12');

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->branch = Branch::create(['name' => 'Cơ sở A', 'code' => 'CA', 'is_active' => true]);
        $this->teacher = $this->makeUser('teacher_fulltime', ['name' => 'Cô Lan']);
        $course = Course::create(['name' => 'Starters', 'code' => 'ST', 'total_lessons' => 24, 'tuition_fee' => 3000000, 'is_active' => true]);
        $this->class = ClassModel::create([
            'name' => 'Lớp ST1', 'code' => 'ST1', 'course_id' => $course->id, 'branch_id' => $this->branch->id, 'teacher_id' => $this->teacher->id,
            'start_date' => now()->subMonth(), 'end_date' => now()->addMonths(2), 'max_capacity' => 12, 'status' => 'active',
        ]);
        $this->other = $this->class->replicate()->fill(['name' => 'Lớp ST2', 'code' => 'ST2']);
        $this->other->save();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeUser(string $role, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge(['branch_id' => $this->branch->id, 'is_active' => true], $attributes));
        $user->assignRole($role);

        return $user;
    }

    private function student(string $name): Student
    {
        return Student::create(['code' => 'HV-'.mb_substr($name, -3), 'name' => $name, 'phone' => '09'.random_int(10000000, 99999999), 'branch_id' => $this->branch->id, 'status' => 'studying']);
    }

    private function makeSession(ClassModel $class, Carbon $date, array $attributes = []): ClassSession
    {
        return ClassSession::create(array_merge([
            'class_id' => $class->id, 'branch_id' => $this->branch->id, 'date' => $date->toDateString(), 'start_time' => '08:00', 'end_time' => '09:30',
            'type' => ClassSession::TYPE_REGULAR, 'status' => 'completed', 'teacher_id' => $this->teacher->id,
        ], $attributes));
    }

    private static function metric(array $metrics, string $key): array
    {
        return collect($metrics['items'])->firstWhere('key', $key);
    }

    private static function kpi(array $groups, string $code): array
    {
        return collect($groups)->flatMap(fn ($g) => $g['items'])->firstWhere('code', $code);
    }

    public function test_weekly_kpi_report_is_computed_from_system_data(): void
    {
        $staff = $this->makeUser('academic_staff', ['name' => 'Giáo vụ A']);
        KpiCriterion::create(['role' => 'academic_staff', 'group_name' => 'Học phí & dữ liệu', 'code' => '1.2', 'name' => 'Thu đúng học phí', 'weight' => 10,
            'unit' => 'lan', 'auto_source' => 'receipt_rejected', 'is_active' => true]);
        KpiCriterion::create(['role' => 'academic_staff', 'group_name' => 'Học viên & phụ huynh', 'code' => '2.3', 'name' => 'Founder phải can thiệp trực tiếp',
            'weight' => 5, 'unit' => 'lan', 'is_active' => true]);
        KpiCriterion::create(['role' => 'academic_staff', 'group_name' => 'Báo cáo', 'code' => '4.1', 'name' => 'Nộp báo cáo đúng hạn', 'weight' => 5,
            'unit' => 'lan', 'auto_source' => 'daily_report_late', 'is_active' => true]);

        // Lịch học: ST1 thứ 2 + thứ 3, ST2 học bù thứ 3, buổi hủy không tính.
        $this->makeSession($this->class, $this->monday);
        $this->makeSession($this->class, $this->monday->copy()->addDay());
        $makeup = $this->makeSession($this->other, $this->monday->copy()->addDay(), ['type' => ClassSession::TYPE_MAKEUP]);
        $this->makeSession($this->other, $this->monday->copy()->addDays(2), ['status' => 'cancelled']);

        $an = $this->student('Nguyễn An');
        $binh = $this->student('Trần Bình');
        ClassEnrollment::create(['class_id' => $this->class->id, 'student_id' => $an->id, 'status' => 'pending', 'enrolled_at' => today()->subMonth(), 'left_at' => $this->monday->copy()->addDay()]);
        ClassEnrollment::create(['class_id' => $this->other->id, 'student_id' => $an->id, 'status' => 'pending', 'enrolled_at' => $this->monday->copy()->addDay()]);
        ClassEnrollment::create(['class_id' => $this->class->id, 'student_id' => $binh->id, 'status' => 'pending', 'enrolled_at' => today()->subMonth()]);
        StudentAttendance::create(['class_id' => $this->other->id, 'class_session_id' => $makeup->id, 'student_id' => $binh->id,
            'user_id' => $this->teacher->id, 'session_date' => $makeup->date, 'status' => 'present']);

        $lead = CrmCustomer::create(['code' => 'KH-1', 'name' => 'Lê Hằng', 'phone' => '0933111222', 'stage' => 'won', 'source' => 'Facebook',
            'branch_id' => $this->branch->id, 'assigned_user_id' => $staff->id]);
        CrmCustomer::create(['code' => 'KH-2', 'name' => 'Phạm Vy', 'phone' => '0933111333', 'stage' => 'new', 'source' => 'Tiktok',
            'branch_id' => $this->branch->id, 'assigned_user_id' => $staff->id]);
        CrmCustomerHistory::create(['customer_id' => $lead->id, 'user_id' => $staff->id, 'type' => 'stage_change', 'from_stage' => 'tested', 'to_stage' => 'won', 'content' => 'Chốt']);
        BigTest::create(['code' => 'BT-1', 'title' => 'Big Test 1', 'class_id' => $this->class->id, 'test_type' => 'midterm', 'scheduled_at' => $this->monday->copy()->addDays(4)->setTime(9, 0), 'status' => 'draft']);

        AdminNotification::create(['user_id' => $staff->id, 'type' => 'receipt_rejected', 'title' => 'Phiếu thu bị trả về', 'message' => 'PT-1 bị trả về', 'is_read' => false, 'data' => ['receipt_id' => 1]]);
        foreach (['Founder gọi phụ huynh bé An', 'Founder xử lý học phí'] as $content) {
            StaffReport::create(['user_id' => $staff->id, 'type' => 'journal', 'title' => 'Sự vụ', 'content' => $content, 'kpi_code' => '2.3', 'report_date' => $this->monday]);
        }
        StaffReport::create(['user_id' => $staff->id, 'type' => 'journal', 'title' => 'Sự vụ', 'content' => 'Tuần trước', 'kpi_code' => '2.3', 'report_date' => $this->monday->copy()->subDays(3)]);
        // Báo cáo ngày thứ 2 nộp trễ (sau 9h sáng thứ 3), có ghi chú vận hành; thứ 3 chưa nộp.
        $daily = StaffReport::create(['user_id' => $staff->id, 'type' => 'daily', 'kind' => 'daily_ops', 'period_key' => $this->monday->toDateString(),
            'title' => 'Báo cáo ngày', 'content' => 'x', 'report_date' => $this->monday, 'data' => ['notes' => ['operations' => 'Phòng 302 hỏng loa']]]);
        $daily->forceFill(['created_at' => $this->monday->copy()->addDay()->setTime(11, 0)])->saveQuietly();

        $this->actingAs($staff)->get(route('reports.periodic.weekly-kpi'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Reports/WeeklyKpi')->where('live', true)
                ->where('metrics', function ($metrics) {
                    $metrics = collect($metrics)->toArray();
                    $values = collect(['classes_running', 'students', 'teachers', 'new_leads', 'new_closed', 'transfers', 'makeups', 'upcoming_test_classes'])
                        ->mapWithKeys(fn ($k) => [$k => self::metric($metrics, $k)['value']])->all();
                    $this->assertSame(['classes_running' => '2', 'students' => '2', 'teachers' => '1', 'new_leads' => '2', 'new_closed' => '1',
                        'transfers' => '1', 'makeups' => '1', 'upcoming_test_classes' => '1'], $values);
                    $this->assertSame('Nguyễn An: Lớp ST1 → Lớp ST2', self::metric($metrics, 'transfers')['details'][0]['text']);

                    return true;
                })
                ->where('groups', function ($groups) {
                    $groups = collect($groups)->toArray();
                    $this->assertSame(['auto', 1], [self::kpi($groups, '1.2')['source'], self::kpi($groups, '1.2')['count']]);
                    $this->assertSame(['journal', 2], [self::kpi($groups, '2.3')['source'], self::kpi($groups, '2.3')['count']]);
                    $late = self::kpi($groups, '4.1');
                    $this->assertSame(2, $late['count']); // 1 bản nộp trễ + 1 ngày không nộp (13/10) tính là trễ.
                    $this->assertSame('Báo cáo ngày đã nộp 1/2 ngày · còn thiếu 13/10', collect($late['details'])->firstWhere('tag', 'Tham khảo')['text']);

                    return true;
                })
                ->where('daily.notes.0.lines.0.text', '12/10: Phòng 302 hỏng loa'));

        $this->actingAs($staff)->post(route('reports.periodic.weekly-kpi.store'), ['week' => $this->week, 'note' => 'Tuần ổn'])->assertSessionHasNoErrors();
        $report = StaffReport::where('kind', 'weekly_kpi')->sole();
        $this->assertSame([$this->week, 'weekly'], [$report->period_key, $report->type]);
        $this->assertSame(2, collect($report->data['counts'])->firstWhere('code', '2.3')['count']);
        $this->assertSame(2, $report->data['metrics']['classes_running']);
        $this->assertStringContainsString('Học viên & phụ huynh — Founder phải can thiệp trực tiếp: 2 lần', $report->content);
        $this->assertStringContainsString('HV chuyển lớp: 1', $report->content);
        $this->assertStringContainsString('Ghi chú tuần: Tuần ổn', $report->content);

        // Đã nộp: hiện số lúc nộp; "Cập nhật số liệu" tính lại.
        StaffReport::create(['user_id' => $staff->id, 'type' => 'journal', 'title' => 'Sự vụ', 'content' => 'Thêm', 'kpi_code' => '2.3', 'report_date' => today()]);
        $this->actingAs($staff)->get(route('reports.periodic.weekly-kpi', ['week' => $this->week]))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('live', false)->where('note', 'Tuần ổn')
                ->where('groups', fn ($groups) => self::kpi(collect($groups)->toArray(), '2.3')['count'] === 2));
        $this->actingAs($staff)->get(route('reports.periodic.weekly-kpi', ['week' => $this->week, 'refresh' => 1]))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('live', true)
                ->where('groups', fn ($groups) => self::kpi(collect($groups)->toArray(), '2.3')['count'] === 3));
    }

    public function test_weekly_kpi_report_submitted_by_hand_stays_readable(): void
    {
        $staff = $this->makeUser('academic_staff');
        StaffReport::create(['user_id' => $staff->id, 'type' => 'weekly', 'kind' => 'weekly_kpi', 'period_key' => $this->week, 'title' => 'Báo cáo tuần',
            'content' => 'x', 'report_date' => $this->monday,
            'data' => ['counts' => [['id' => 1, 'code' => 'CS1', 'name' => 'Gọi điện phụ huynh', 'group' => 'Chăm sóc', 'count' => 4]], 'metrics' => ['classes_running' => 12, 'students' => null]]]);

        $this->actingAs($staff)->get(route('reports.periodic.weekly-kpi', ['week' => $this->week]))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('legacy', [
                ['label' => 'Gọi điện phụ huynh', 'value' => '4 lần'],
                ['label' => 'Số lớp đang chạy', 'value' => '12'],
            ]));
    }

    public function test_academic_weekly_report_is_prefilled_from_system(): void
    {
        $lead = $this->makeUser('academic_lead', ['name' => 'Học thuật B']);
        $project = AcademicProject::create(['code' => 'DA-1', 'name' => 'Giáo trình Movers', 'owner_id' => $lead->id, 'status' => 'in_progress',
            'start_date' => today()->subWeek(), 'deadline' => today()->addMonth(), 'created_by' => $lead->id]);
        $done = AcademicProjectMilestone::create(['academic_project_id' => $project->id, 'title' => 'Unit 1-3', 'assignee_id' => $lead->id,
            'due_date' => $this->monday, 'status' => 'done', 'completed_at' => $this->monday->copy()->setTime(15, 0)]);
        $next = AcademicProjectMilestone::create(['academic_project_id' => $project->id, 'title' => 'Unit 4-6', 'assignee_id' => $lead->id,
            'due_date' => $this->monday->copy()->addDays(9), 'status' => 'in_progress', 'target_quantity' => 4, 'done_quantity' => 2, 'unit' => 'unit']);
        $task = WorkTask::create(['title' => 'Duyệt đề Big Test ST1', 'creator_id' => $lead->id, 'assignee_id' => $lead->id, 'branch_id' => $this->branch->id,
            'status' => 'completed', 'completed_at' => $this->monday->copy()->addDay()->setTime(9, 0), 'due_date' => $this->monday->copy()->addDay()]);
        $order = MaterialOrder::create(['code' => 'OD-1', 'branch_id' => $this->branch->id, 'class_id' => $this->class->id, 'requester_id' => $this->teacher->id,
            'category' => MaterialOrder::CATEGORY_ACADEMIC, 'title' => 'Flashcard Unit 3', 'quantity' => 1, 'use_date' => today()->addDays(2), 'due_at' => now()->addDay(), 'status' => 'pending']);

        $this->actingAs($lead)->get(route('reports.periodic.academic-weekly'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Reports/AcademicWeekly')
                ->where('suggestions', function ($suggestions) use ($done, $next, $task, $order) {
                    $suggestions = collect($suggestions)->toArray();
                    $this->assertSame(["milestone:{$done->id}", "task:{$task->id}"], array_column($suggestions['done'], 'source'));
                    $this->assertSame(["milestone:{$next->id}"], array_column($suggestions['planned'], 'source'));
                    $this->assertSame(50, $suggestions['planned'][0]['progress']);
                    $this->assertStringContainsString('Đã làm 2/4 unit', $suggestions['planned'][0]['detail']);
                    $this->assertContains("order:{$order->id}", array_column($suggestions['requests'], 'source'));

                    return true;
                })
                ->where('summary', function ($summary) {
                    $items = collect(collect($summary)->toArray()['items'])->keyBy('key');
                    $this->assertSame(['1', '1', '0'], [$items['milestones_due']['value'], $items['milestones_done']['value'], $items['milestones_overdue']['value']]);

                    return true;
                }));

        $this->actingAs($lead)->post(route('reports.periodic.academic-weekly.store'), ['week' => $this->week, 'rows' => [
            'done' => [['content' => 'Giáo trình Movers: Unit 1-3', 'progress' => '100', 'source' => "milestone:{$done->id}", 'report' => 'Đã gửi GV']],
        ]])->assertSessionHasNoErrors();
        $row = StaffReport::where('kind', 'academic_weekly')->sole()->data['rows']['done'][0];
        $this->assertSame(["milestone:{$done->id}", 100, 'Đã gửi GV'], [$row['source'], $row['progress'], $row['report']]);
    }

    public function test_admin_opens_report_detail_like_the_entry_screen(): void
    {
        $staff = $this->makeUser('academic_staff', ['name' => 'Giáo vụ A']);
        $lead = $this->makeUser('academic_lead', ['name' => 'Học thuật B']);
        $this->makeSession($this->class, today());
        $this->actingAs($staff)->post(route('reports.periodic.daily.store'), ['date' => today()->toDateString(), 'notes' => ['operations' => 'Phòng 302 hỏng loa']])
            ->assertSessionHasNoErrors();
        $this->actingAs($staff)->post(route('reports.periodic.weekly-kpi.store'), ['week' => $this->week, 'note' => 'Tuần ổn'])->assertSessionHasNoErrors();
        $this->actingAs($lead)->post(route('reports.periodic.academic-weekly.store'), ['week' => $this->week, 'rows' => ['done' => [['content' => 'Dự giờ ST1', 'progress' => '100']]]])
            ->assertSessionHasNoErrors();
        $legacy = StaffReport::create(['user_id' => $staff->id, 'type' => 'daily', 'kind' => 'daily_ops', 'period_key' => '2026-10-01', 'title' => 'Báo cáo ngày 01/10',
            'content' => 'x', 'report_date' => '2026-10-01', 'data' => ['fields' => ['classes_planned' => 3, 'classes_note' => 'test']]]);
        $journal = StaffReport::create(['user_id' => $staff->id, 'class_id' => $this->class->id, 'type' => 'journal', 'title' => 'Sự vụ', 'content' => 'Phụ huynh phản ánh', 'kpi_code' => '2.3', 'report_date' => today()]);

        $admin = $this->makeUser('admin');
        $this->actingAs($admin)->get(route('reports.all'))->assertOk()->assertSee('Chi tiết')->assertDontSee('Xem đầy đủ');

        $id = fn (string $kind) => StaffReport::where('kind', $kind)->where('period_key', '!=', '2026-10-01')->value('id');
        $this->actingAs($admin)->get(route('reports.detail', $id('daily_ops')))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Reports/Detail')->where('view', 'daily')
                ->where('auto.sections.operations.items.0.value', '1')
                ->where('notes.operations', 'Phòng 302 hỏng loa')
                ->where('report.for', 'Học vụ'));
        $this->actingAs($admin)->get(route('reports.detail', $id('weekly_kpi')))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('view', 'weekly_kpi')->where('note', 'Tuần ổn')->has('auto.metrics.items', 9));
        $this->actingAs($admin)->get(route('reports.detail', $id('academic_weekly')))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('view', 'academic_weekly')->where('rows.done.0.content', 'Dự giờ ST1'));
        $this->actingAs($admin)->get(route('reports.detail', $legacy->id))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('view', 'daily_legacy')
                ->has('legacy.sections', 3)->has('legacy.fields.media_posts')
                ->where('legacy.values.classes_planned', 3)->where('legacy.values.classes_note', 'test')->missing('legacy.values.classes_actual')
                // Số liệu + chi tiết hệ thống tính lại cho ngày đó, như màn Học vụ đang thấy.
                ->has('auto.sections.operations.items')->has('auto.computed_at'));

        // Báo cáo tuần nhập tay bản cũ: mọi mục KPI (kể cả 0) theo nhóm và đủ 9 chỉ số (ô trống = null).
        $legacyWeek = StaffReport::create(['user_id' => $staff->id, 'type' => 'weekly', 'kind' => 'weekly_kpi', 'period_key' => '2026-W38', 'title' => 'Báo cáo tuần cũ',
            'content' => 'x', 'report_date' => '2026-09-14', 'data' => [
                'counts' => [['id' => 1, 'code' => '1.1', 'name' => 'Mục A', 'group' => 'Vận hành', 'count' => 2], ['id' => 2, 'code' => '1.2', 'name' => 'Mục B', 'group' => 'Vận hành', 'count' => 0]],
                'metrics' => ['students' => 40, 'teachers' => null],
            ]]);
        $this->actingAs($admin)->get(route('reports.detail', $legacyWeek->id))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('view', 'weekly_legacy')
                ->where('legacy.groups.0.name', 'Vận hành')->has('legacy.groups.0.items', 2)->where('legacy.groups.0.items.1.count', 0)
                ->has('legacy.metrics', 9)->where('legacy.metrics.1', ['key' => 'students', 'label' => 'Số học sinh', 'value' => 40])
                ->where('legacy.metrics.2.value', null));
        $this->actingAs($admin)->get(route('reports.detail', $journal->id))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('view', 'journal')->where('report.class', 'Lớp ST1')->where('report.kpi_code', '2.3'));

        // Chỉ người xem tổng hợp mới mở được.
        $this->actingAs($staff)->get(route('reports.detail', $journal->id))->assertForbidden();
    }
}
