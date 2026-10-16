<?php

namespace Tests\Feature;

use App\Models\AdminNotification;
use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\Holiday;
use App\Models\StaffAttendanceRequest;
use App\Models\StaffReport;
use App\Models\User;
use App\Services\Kpi\KpiAutoCounter;
use App\Support\DailyReportDue;
use App\Support\ReportPeriod;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Rà soát luồng báo cáo ngày / tuần của nhân viên (16/10/2026): số liệu theo chi nhánh người viết, ngày không nộp tính trễ,
 * nhắc nộp + tổng hợp sáng cho Admin, mở sẵn hôm qua, Admin yêu cầu bổ sung / kiểm tra hàng loạt / kế tiếp, Quản lý chỉ thấy
 * chi nhánh mình, Admin ghi nhật ký cho Học vụ, nhắc báo cáo tháng người kiêm giáo viên, chặn trùng báo cáo cùng kỳ.
 */
class StaffReportReviewFixesTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branchA;

    private Branch $branchB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->branchA = Branch::create(['name' => 'Cầu Giấy', 'code' => 'CG', 'is_active' => true]);
        $this->branchB = Branch::create(['name' => 'Ba Đình', 'code' => 'BD', 'is_active' => true]);
        // Thứ Năm 15/10/2026, 14:00.
        $this->travelTo(Carbon::parse('2026-10-15 14:00'));
    }

    private function makeUser(string|array $roles, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge(['branch_id' => $this->branchA->id, 'is_active' => true], $attributes));
        $user->assignRole($roles);

        return $user;
    }

    private function dailyReport(User $user, string $date, ?string $createdAt = null, array $data = []): StaffReport
    {
        $report = StaffReport::create(['user_id' => $user->id, 'type' => 'daily', 'kind' => 'daily_ops', 'period_key' => $date, 'report_date' => $date,
            'title' => 'Báo cáo ngày '.Carbon::parse($date)->format('d/m/Y'), 'content' => 'Vận hành lớp học: - Số lớp kế hoạch: 2', 'status' => 'submitted', 'data' => $data]);
        if ($createdAt) {
            $report->created_at = Carbon::parse($createdAt);
            $report->save();
        }

        return $report;
    }

    private function classWithSessionToday(Branch $branch, string $code): ClassModel
    {
        $teacher = $this->makeUser('teacher_fulltime', ['branch_id' => $branch->id]);
        $course = Course::firstOrCreate(['code' => 'ST'], ['name' => 'Starters', 'total_lessons' => 24, 'tuition_fee' => 3000000, 'is_active' => true]);
        $class = ClassModel::create(['name' => "Lớp {$code}", 'code' => $code, 'course_id' => $course->id, 'branch_id' => $branch->id, 'teacher_id' => $teacher->id,
            'start_date' => now()->subMonth(), 'end_date' => now()->addMonths(2), 'max_capacity' => 12, 'status' => 'active']);
        ClassSession::create(['class_id' => $class->id, 'branch_id' => $branch->id, 'date' => today()->toDateString(), 'start_time' => '08:00', 'end_time' => '09:30',
            'room' => '302', 'type' => ClassSession::TYPE_REGULAR, 'status' => 'scheduled', 'teacher_id' => $teacher->id]);

        return $class;
    }

    public function test_daily_metrics_count_only_the_writer_branches_and_split_per_branch(): void
    {
        $this->classWithSessionToday($this->branchA, 'CG1');
        $this->classWithSessionToday($this->branchB, 'BD1');
        $cg = $this->makeUser('academic_staff');
        $both = $this->makeUser('academic_staff');
        $both->branches()->attach($this->branchB->id);
        $planned = fn (AssertableInertia $page) => collect($page->toArray()['props']['sections']['operations']['items'])->firstWhere('key', 'classes_planned');

        $this->actingAs($cg)->get(route('reports.periodic.daily', ['date' => today()->toDateString()]))
            ->assertInertia(function (AssertableInertia $page) use ($planned) {
                $this->assertSame('1', $planned($page)['value']);
                $this->assertNotContains('Chi nhánh', collect($planned($page)['details'])->pluck('tag')->all());
            });
        $this->actingAs($both)->get(route('reports.periodic.daily', ['date' => today()->toDateString()]))
            ->assertInertia(function (AssertableInertia $page) use ($planned) {
                $this->assertSame('2', $planned($page)['value']);
                $split = collect($planned($page)['details'])->where('tag', 'Chi nhánh')->pluck('text')->all();
                $this->assertEqualsCanonicalizing(['Cầu Giấy: 1 lớp', 'Ba Đình: 1 lớp'], $split);
            });
    }

    public function test_missing_days_count_as_late_except_sundays_holidays_and_approved_leave(): void
    {
        $staff = $this->makeUser('academic_staff');
        // T2 12/10 nộp đúng hạn, T3 13/10 không nộp, T4 14/10 nghỉ lễ; Chủ nhật 11/10 không phải nộp; thứ Bảy 10/10 nộp trễ.
        $this->dailyReport($staff, '2026-10-12', '2026-10-13 08:30');
        $this->dailyReport($staff, '2026-10-10', '2026-10-12 11:00');
        Holiday::create(['code' => 'L1', 'name' => 'Nghỉ lễ', 'start_date' => '2026-10-14', 'end_date' => '2026-10-14', 'is_system_wide' => true]);

        $from = Carbon::parse('2026-10-10');
        $to = Carbon::parse('2026-10-18');
        $this->assertSame(['2026-10-13'], DailyReportDue::missingDays($staff, $from, $to)->map->toDateString()->all());
        $evidence = app(KpiAutoCounter::class)->evidence('daily_report_late', $staff, $from, $to);
        $this->assertSame(2, KpiAutoCounter::countOf($evidence));
        $this->assertSame(['Báo cáo ngày 10/10 nộp lúc 11:00 12/10', 'Không nộp báo cáo ngày 13/10'], array_column($evidence, 'text'));

        // Nghỉ phép đã duyệt ngày 13/10 → không phải nộp.
        StaffAttendanceRequest::create(['user_id' => $staff->id, 'branch_id' => $this->branchA->id, 'type' => StaffAttendanceRequest::TYPE_LEAVE,
            'date_from' => '2026-10-13', 'date_to' => '2026-10-13', 'reason' => 'Ốm', 'status' => StaffAttendanceRequest::STATUS_APPROVED]);
        $this->assertSame([], DailyReportDue::missingDays($staff, $from, $to)->all());
    }

    public function test_daily_page_opens_yesterday_before_ten_and_lists_missing_days(): void
    {
        $staff = $this->makeUser('academic_staff');
        $this->dailyReport($staff, '2026-10-13');

        $this->travelTo(Carbon::parse('2026-10-15 08:30'));
        $this->actingAs($staff)->get(route('reports.periodic.daily'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('date', '2026-10-14')->where('deadline', '09:00 15/10')
                ->where('missingDays', fn ($days) => in_array('2026-10-14', collect($days)->pluck('date')->all(), true)
                    && ! in_array('2026-10-13', collect($days)->pluck('date')->all(), true))
                ->where('history', fn ($history) => collect($history)->firstWhere('period', '2026-10-14')['missing'] === true));

        $this->travelTo(Carbon::parse('2026-10-15 10:30'));
        $this->actingAs($staff)->get(route('reports.periodic.daily'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('date', '2026-10-15'));

        // Thẻ Tổng quan: ngày còn thiếu + link mở thẳng ngày đó.
        $this->actingAs($staff)->get(route('dashboard'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('dailyReport.missing.0.label', '14/10')
                ->where('dailyReport.missing.0.href', route('reports.periodic.daily', ['date' => '2026-10-14']))
                ->where('dailyReport.todaySubmitted', false));
    }

    public function test_admin_requests_changes_writer_resubmits_and_admin_reviews_in_bulk(): void
    {
        $staff = $this->makeUser('academic_staff', ['name' => 'Học vụ A']);
        $manager = $this->makeUser('manager');
        $admin = $this->makeUser('admin', ['name' => 'Admin Liên']);
        $today = today()->toDateString();
        $this->actingAs($staff)->post(route('reports.periodic.daily.store'), ['date' => $today, 'notes' => ['operations' => 'Lớp ST1 thiếu bàn']]);
        $report = StaffReport::where('kind', 'daily_ops')->sole();

        $this->actingAs($manager)->post(route('reports.request-changes', $report->id), ['note' => 'x'])->assertForbidden();
        $this->actingAs($admin)->post(route('reports.request-changes', $report->id), ['note' => ''])->assertSessionHasErrors('note');
        $this->actingAs($admin)->post(route('reports.request-changes', $report->id), ['note' => 'Bổ sung lý do lớp K26 nghỉ'])->assertRedirect();
        $this->assertSame(['returned', 'Bổ sung lý do lớp K26 nghỉ'], [$report->fresh()->status, $report->fresh()->review_note]);
        $notice = AdminNotification::where('user_id', $staff->id)->where('type', 'staff_report_returned')->sole();
        $this->assertSame(route('reports.periodic.daily', ['date' => $today]), $notice->data['link']);

        $this->actingAs($staff)->get(route('reports.periodic.daily', ['date' => $today]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('review.status', 'returned')->where('review.note', 'Bổ sung lý do lớp K26 nghỉ')->where('review.by', 'Admin Liên'));
        $this->actingAs($admin)->get(route('reports.all', ['review' => 'returned']))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('stats.returned', 1)->where('reports.data.0.id', $report->id));

        // Nộp lại → về chờ kiểm tra, xoá nhận xét, báo đúng Admin đã trả về.
        $this->actingAs($staff)->post(route('reports.periodic.daily.store'), ['date' => $today, 'notes' => ['operations' => 'Lớp K26 nghỉ do mất điện']]);
        $this->assertSame(['submitted', null], [$report->fresh()->status, $report->fresh()->review_note]);
        $this->assertSame(1, AdminNotification::where('user_id', $admin->id)->where('type', 'staff_report_resubmitted')->count());

        // Kiểm tra hàng loạt + nút "Kế tiếp" trong chi tiết.
        $other = $this->dailyReport($this->makeUser('academic_staff'), '2026-10-14');
        $this->actingAs($admin)->get(route('reports.detail', $report->id))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('nextId', $other->id)->where('canReview', true)->where('report.status', 'submitted'));
        $this->actingAs($admin)->post(route('reports.review-bulk'), ['ids' => [$report->id, $other->id]])->assertRedirect();
        $this->assertNotNull($report->fresh()->reviewed_at);
        $this->assertNotNull($other->fresh()->reviewed_at);
        $this->assertSame(1, AdminNotification::where('user_id', $staff->id)->where('type', 'staff_report_reviewed')->count());
        $this->actingAs($admin)->get(route('reports.detail', $report->id))->assertInertia(fn (AssertableInertia $page) => $page->where('nextId', null));
    }

    public function test_manager_sees_only_reports_and_journal_of_own_branch(): void
    {
        $cg = $this->makeUser('academic_staff', ['name' => 'Học vụ CG']);
        $bd = $this->makeUser('academic_staff', ['name' => 'Học vụ BD', 'branch_id' => $this->branchB->id]);
        $manager = $this->makeUser('manager');
        $own = $this->dailyReport($cg, '2026-10-14');
        $foreign = $this->dailyReport($bd, '2026-10-14');
        StaffReport::create(['user_id' => $bd->id, 'type' => 'journal', 'title' => 'Sự vụ BD', 'content' => 'Sự vụ BD', 'report_date' => today(), 'status' => 'open']);

        $this->actingAs($manager)->get(route('reports.all'))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('reports.data', 1)->where('reports.data.0.id', $own->id)
                ->where('stats.daily', 1)->where('stats.journal', 0)->where('canReview', false));
        $this->actingAs($manager)->get(route('reports.detail', $foreign->id))->assertNotFound();
        $this->actingAs($manager)->get(route('reports.journal'))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('journals.data', 0));

        $this->actingAs($this->makeUser('admin'))->get(route('reports.all', ['branch_id' => $this->branchB->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('reports.data', 2)->where('reports.data.0.user', 'Học vụ BD'));
    }

    public function test_admin_list_shows_writer_notes_late_flag_filters_and_missing_tab(): void
    {
        $late = $this->makeUser('academic_staff', ['name' => 'Học vụ Trễ']);
        $absent = $this->makeUser('academic_staff', ['name' => 'Học vụ Vắng']);
        $admin = $this->makeUser('admin');
        $report = $this->dailyReport($late, '2026-10-14', '2026-10-15 09:40', ['auto' => ['sections' => []], 'notes' => ['operations' => 'Phòng 302 hỏng điều hòa']]);
        $this->dailyReport($absent, '2026-10-12');

        $this->actingAs($admin)->get(route('reports.all', ['user_id' => $late->id, 'from' => '2026-10-14', 'to' => '2026-10-14', 'open' => $report->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('reports.data', 1)
                ->where('reports.data.0.late', true)->where('reports.data.0.preview', 'Vận hành lớp học: Phòng 302 hỏng điều hòa')
                ->where('reports.data.0.submitted_at', Carbon::parse('2026-10-15 09:40')->toIso8601String())
                ->where('openId', $report->id)->where('stats.missing_yesterday', 1));

        $this->actingAs($admin)->get(route('reports.all', ['view' => 'missing', 'from' => '2026-10-12', 'to' => '2026-10-14']))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('view', 'missing')->has('missing.rows', 2)
                ->where('missing.rows.0.user', 'Học vụ Trễ')->has('missing.rows.0.days', 2)
                ->where('missing.rows.1.user', 'Học vụ Vắng')->has('missing.rows.1.days', 2));
    }

    public function test_daily_reminder_and_admin_digest_are_sent_once(): void
    {
        $done = $this->makeUser('academic_staff', ['name' => 'Học vụ Đủ']);
        $missing = $this->makeUser('academic_staff', ['name' => 'Học vụ Thiếu']);
        $admin = $this->makeUser('admin');
        $this->dailyReport($done, '2026-10-14');

        $this->travelTo(Carbon::parse('2026-10-15 08:00'));
        $this->artisan('reports:remind-daily')->assertSuccessful();
        $this->artisan('reports:remind-daily')->assertSuccessful();
        $reminder = AdminNotification::where('type', 'daily_report_due')->sole();
        $this->assertSame([$missing->id, route('reports.periodic.daily', ['date' => '2026-10-14'])], [(int) $reminder->user_id, $reminder->data['link']]);

        $this->travelTo(Carbon::parse('2026-10-15 09:05'));
        $this->artisan('reports:daily-digest')->assertSuccessful();
        $this->artisan('reports:daily-digest')->assertSuccessful();
        $digest = AdminNotification::where('user_id', $admin->id)->where('type', 'staff_report_digest')->sole();
        $this->assertSame('Báo cáo ngày 14/10: đã nộp 1/2', $digest->title);
        $this->assertStringContainsString('Học vụ Thiếu', $digest->message);

        // Báo cáo ngày không báo Admin từng bản; báo cáo tuần vẫn báo, link mở thẳng báo cáo.
        $this->assertSame(0, AdminNotification::where('type', 'staff_report_submitted')->count());
        $this->actingAs($done)->post(route('reports.periodic.weekly-kpi.store'), ['week' => ReportPeriod::currentWeek()])->assertSessionHasNoErrors();
        $weekly = StaffReport::where('kind', 'weekly_kpi')->sole();
        $this->assertSame(route('reports.all', ['open' => $weekly->id]), AdminNotification::where('type', 'staff_report_submitted')->sole()->data['link']);
    }

    public function test_admin_logs_journal_for_academic_staff_and_urgent_entry_notifies_managers(): void
    {
        $staff = $this->makeUser('academic_staff', ['name' => 'Học vụ A']);
        $other = $this->makeUser('academic_staff');
        $admin = $this->makeUser('admin', ['name' => 'Founder']);
        $manager = $this->makeUser('manager');

        $this->actingAs($other)->post(route('reports.journal.store'), ['content' => 'x', 'subject_user_id' => $staff->id])->assertForbidden();
        $this->actingAs($admin)->post(route('reports.journal.store'), [
            'content' => 'Founder phải xử lý phụ huynh phàn nàn', 'subject_user_id' => $staff->id, 'severity' => 'urgent',
        ])->assertSessionHasNoErrors();

        $entry = StaffReport::where('type', 'journal')->sole();
        $this->assertSame([$staff->id, 'Founder'], [(int) $entry->user_id, $entry->data['logged_by']]);
        $this->assertSame(1, AdminNotification::where('user_id', $manager->id)->where('type', 'staff_journal_urgent')->count());
        $this->actingAs($staff)->get(route('reports.journal'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('journals.data.0.logged_by', 'Founder'));
        $this->actingAs($admin)->get(route('reports.journal'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('subjects', fn ($subjects) => collect($subjects)->pluck('value')->contains($staff->id)));
    }

    public function test_monthly_reminder_covers_staff_who_also_teach(): void
    {
        $dual = $this->makeUser(['academic_staff', 'teacher_fulltime']);
        // Hạn tháng 10/2026 là Chủ nhật 25/10 → 22/10 nhắc "còn 3 ngày".
        $this->travelTo(Carbon::parse('2026-10-22 08:00'));
        $this->artisan('reports:remind-monthly')->assertSuccessful();
        $notice = AdminNotification::where('user_id', $dual->id)->where('type', 'monthly_report_due')->sole();
        $this->assertSame(route('reports.periodic.teacher-monthly', ['month' => '2026-10']), $notice->data['link']);
    }

    public function test_one_report_per_period_and_no_future_weeks(): void
    {
        $staff = $this->makeUser('academic_staff');
        $this->dailyReport($staff, '2026-10-14');
        try {
            $this->dailyReport($staff, '2026-10-14');
            $this->fail('Bản trùng kỳ phải bị chặn.');
        } catch (UniqueConstraintViolationException) {
            $this->assertSame(1, StaffReport::count());
        }

        $next = ReportPeriod::weekKey(now()->addWeek());
        $this->actingAs($staff)->post(route('reports.periodic.weekly-kpi.store'), ['week' => $next])->assertSessionHasErrors('week');
        $this->actingAs($staff)->get(route('reports.periodic.weekly-kpi', ['week' => $next]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('week', ReportPeriod::currentWeek())
                ->where('weeks.0.label', 'Tuần này · Tuần 42 (12/10 – 18/10/2026)'));
    }
}
