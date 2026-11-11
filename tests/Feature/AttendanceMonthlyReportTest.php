<?php

namespace Tests\Feature;

use App\Models\AttendanceMonthlyReport;
use App\Models\Branch;
use App\Models\StaffAttendance;
use App\Models\User;
use App\Support\MonthlyReportDue;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AttendanceMonthlyReportTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-10-15 10:00:00'));
        $branch = Branch::create(['name' => 'Attendance reports', 'code' => 'ATT-RPT', 'is_active' => true]);
        $this->teacher = User::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);
        $this->teacher->assignRole('teacher_fulltime');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_teaching_staff_can_view_their_monthly_summary(): void
    {
        StaffAttendance::create([
            'user_id' => $this->teacher->id, 'branch_id' => $this->teacher->branch_id,
            'work_date' => '2026-09-15', 'check_in_at' => '2026-09-15 08:00:00',
            'check_out_at' => '2026-09-15 17:00:00',
        ]);

        StaffAttendance::create([
            'user_id' => User::factory()->create()->id, 'branch_id' => $this->teacher->branch_id,
            'work_date' => '2026-09-15', 'check_in_at' => '2026-09-15 08:00:00',
        ]);

        foreach (['teacher_fulltime', 'teacher_parttime', 'assistant', 'foreign_teacher'] as $role) {
            $this->teacher->syncRoles([$role]);
            $this->actingAs($this->teacher)->get(route('my-attendance.monthly-report'))
                ->assertOk()->assertInertia(fn (Assert $page) => $page->component('MyAttendance/MonthlyReport')
                    ->where('month', '2026-09')->where('summary.days', 1)->where('report', null));
        }
    }

    public function test_store_creates_own_report_and_redirects_to_read_only_page(): void
    {
        $this->actingAs($this->teacher)->post(route('my-attendance.monthly-report.store'), [
            'month' => '2026-09', 'note' => 'Đã kiểm tra công.',
        ])->assertRedirect(route('my-attendance.monthly-report', ['month' => '2026-09']));

        $this->assertDatabaseHas('attendance_monthly_reports', [
            'user_id' => $this->teacher->id, 'month' => '2026-09',
            'submitted_at' => '2026-10-15 10:00:00', 'note' => 'Đã kiểm tra công.',
        ]);
        $this->get(route('my-attendance.monthly-report', ['month' => '2026-09']))
            ->assertOk()->assertSee('Đã nộp - 15/10/2026')
            ->assertDontSee('name="note"', false)
            ->assertInertia(fn (Assert $page) => $page->where('report.note', 'Đã kiểm tra công.'));
        $this->get(route('my-attendance.monthly-report'))->assertInertia(fn (Assert $page) => $page->where('month', '2026-10'));
    }

    public function test_duplicate_submission_keeps_first_timestamp_and_note(): void
    {
        $this->actingAs($this->teacher)->post(route('my-attendance.monthly-report.store'), ['month' => '2026-10', 'note' => 'First'])->assertSessionHasNoErrors();
        $first = AttendanceMonthlyReport::sole();
        Carbon::setTestNow(now()->addHour());
        $this->post(route('my-attendance.monthly-report.store'), ['month' => '2026-10', 'note' => 'Second'])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('attendance_monthly_reports', 1);
        $this->assertTrue($first->submitted_at->equalTo($first->fresh()->submitted_at));
        $this->assertSame('First', $first->fresh()->note);
        $this->get(route('my-attendance.monthly-report'))->assertInertia(fn (Assert $page) => $page->where('month', '2026-09'));
    }

    public function test_store_rejects_old_future_and_malformed_months_and_long_notes(): void
    {
        foreach (['2026-08', '2026-11', '2026-13', 'invalid'] as $month) {
            $this->actingAs($this->teacher)->post(route('my-attendance.monthly-report.store'), ['month' => $month])->assertSessionHasErrors('month');
        }
        $this->post(route('my-attendance.monthly-report.store'), ['month' => '2026-10', 'note' => str_repeat('a', 1001)])->assertSessionHasErrors('note');
        $this->assertDatabaseCount('attendance_monthly_reports', 0);
    }

    public function test_another_users_report_is_not_read_or_changed(): void
    {
        $other = User::factory()->create();
        $report = AttendanceMonthlyReport::create(['user_id' => $other->id, 'month' => '2026-09', 'submitted_at' => now()->subDay(), 'note' => 'Private']);
        $this->actingAs($this->teacher)->get(route('my-attendance.monthly-report', ['month' => '2026-09', 'user_id' => $other->id]))
            ->assertInertia(fn (Assert $page) => $page->where('report', null));
        $this->get(route('teacher.home'))->assertInertia(fn (Assert $page) => $page->where('todo.attendance_report_pending', '2026-09'));
        $this->post(route('my-attendance.monthly-report.store'), ['month' => '2026-09', 'user_id' => $other->id, 'note' => 'Mine'])->assertSessionHasNoErrors();

        $this->assertSame('Private', $report->fresh()->note);
        $this->assertTrue($report->submitted_at->equalTo($report->fresh()->submitted_at));
        $this->assertDatabaseHas('attendance_monthly_reports', ['user_id' => $this->teacher->id, 'month' => '2026-09', 'note' => 'Mine']);
    }

    public function test_home_reminder_links_to_previous_month_and_disappears_after_submission(): void
    {
        $this->actingAs($this->teacher)->get(route('teacher.home'))->assertOk()
            ->assertSee('Báo cáo chấm công chưa nộp')
            ->assertInertia(fn (Assert $page) => $page->where('todo.attendance_report_pending', '2026-09')
                ->where('todo.attendance_report_url', route('my-attendance.monthly-report', ['month' => '2026-09'])));
        $this->post(route('my-attendance.monthly-report.store'), ['month' => '2026-09'])->assertSessionHasNoErrors();
        $this->get(route('teacher.home'))->assertDontSee('Báo cáo chấm công chưa nộp')
            ->assertInertia(fn (Assert $page) => $page->where('todo.attendance_report_pending', null));
    }

    public function test_home_chip_is_marked_late_only_for_a_previous_month(): void
    {
        $this->actingAs($this->teacher)->get(route('teacher.home'))->assertSee('đã trễ')
            ->assertInertia(fn (Assert $page) => $page->where('todo.attendance_report_late', true));
        AttendanceMonthlyReport::create(['user_id' => $this->teacher->id, 'month' => '2026-09', 'submitted_at' => now()]);
        Carbon::setTestNow(Carbon::parse('2026-10-30 10:00:00'));
        $this->get(route('teacher.home'))->assertInertia(fn (Assert $page) => $page->where('todo.attendance_report_pending', '2026-10')
            ->where('todo.attendance_report_late', false));
    }

    public function test_submission_is_late_only_after_the_last_day_of_its_month(): void
    {
        foreach ([['2026-09-30 23:59:59', false], ['2026-10-01 00:00:00', true]] as [$at, $late]) {
            AttendanceMonthlyReport::query()->delete();
            AttendanceMonthlyReport::create(['user_id' => $this->teacher->id, 'month' => '2026-09', 'submitted_at' => $at]);
            $this->actingAs($this->teacher)->get(route('my-attendance.monthly-report', ['month' => '2026-09']))
                ->assertInertia(fn (Assert $page) => $page->where('report.late', $late));
        }
    }

    public function test_current_month_is_pending_from_three_days_before_last_sunday_onwards(): void
    {
        AttendanceMonthlyReport::create(['user_id' => $this->teacher->id, 'month' => '2026-09', 'submitted_at' => now()]);
        $threshold = MonthlyReportDue::dueDate(now())->subDays(3);
        foreach ([$threshold->copy()->subSecond(), $threshold, $threshold->copy()->addDays(3), now()->endOfMonth()] as $date) {
            Carbon::setTestNow($date);
            $this->actingAs($this->teacher)->get(route('teacher.home'))->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('todo.attendance_report_pending', $date->lt($threshold) ? null : '2026-10'));
        }
        $this->post(route('my-attendance.monthly-report.store'), ['month' => '2026-10'])->assertSessionHasNoErrors();
        $this->get(route('teacher.home'))->assertInertia(fn (Assert $page) => $page->where('todo.attendance_report_pending', null));
        $this->get(route('my-attendance.monthly-report'))->assertInertia(fn (Assert $page) => $page->where('month', '2026-10'));
    }

    public function test_previous_month_takes_priority_when_both_months_are_due(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-31 10:00:00'));
        $this->actingAs($this->teacher)->get(route('teacher.home'))
            ->assertInertia(fn (Assert $page) => $page->where('todo.attendance_report_pending', '2026-09'));
        $this->post(route('my-attendance.monthly-report.store'), ['month' => '2026-09'])->assertSessionHasNoErrors();
        $this->get(route('teacher.home'))
            ->assertInertia(fn (Assert $page) => $page->where('todo.attendance_report_pending', '2026-10'));
    }

    public function test_default_month_handles_year_rollover_without_month_overflow(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-01-31 10:00:00'));
        $this->actingAs($this->teacher)->get(route('my-attendance.monthly-report'))
            ->assertInertia(fn (Assert $page) => $page->where('month', '2026-12'));
        $this->post(route('my-attendance.monthly-report.store'), ['month' => '2026-12'])->assertSessionHasNoErrors();
    }

    public function test_non_staff_cannot_view_or_submit_reports(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->actingAs($user)->get(route('my-attendance.monthly-report'))->assertForbidden();
        $this->post(route('my-attendance.monthly-report.store'), ['month' => '2026-09'])->assertForbidden();
    }
}
