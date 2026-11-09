<?php

namespace Tests\Feature;

use App\Http\Controllers\StaffReportController;
use App\Models\Branch;
use App\Models\StaffReport;
use App\Models\User;
use App\Support\StaffType;
use App\Support\TimesheetReminder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class TimesheetMonthlyReportTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-30 10:00:00'));
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->branch = Branch::create(['name' => 'Cơ sở A', 'code' => 'CA', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeUser(string|array $roles, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge([
            'branch_id' => $this->branch->id, 'is_active' => true, 'created_at' => '2026-10-01',
        ], $attributes));
        $user->assignRole($roles);

        return $user;
    }

    public function test_academic_roles_have_a_separate_timesheet_tab_and_keep_their_primary_cadence(): void
    {
        foreach (['academic_staff' => 'daily_ops', 'academic_lead' => 'academic_weekly'] as $role => $primary) {
            $user = $this->makeUser($role);
            $this->assertSame($primary, StaffReportController::primaryStructuredKind($user));
            $this->actingAs($user)->get(route('reports.periodic.timesheet-monthly'))->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page->component('Reports/TimesheetMonthly')
                    ->where('month', '2026-11')->where('submitted_at', null)
                    ->where('pending.0.month', '2026-11')
                    ->where('tabs', fn ($tabs) => collect($tabs)->contains(fn ($tab) => $tab['label'] === 'Báo cáo chấm công tháng' && $tab['active'])));
            $this->get(route('reports.periodic.timesheet-monthly', ['month' => '2026-12']))->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page->where('month', '2026-11'));
        }
        $this->get(route('reports.periodic.academic-monthly'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Reports/AcademicMonthly'));
    }

    public function test_resubmission_updates_only_the_authenticated_users_month_and_submission_date(): void
    {
        $other = $this->makeUser('academic_staff');
        foreach (['teacher_fulltime', 'academic_staff', 'academic_lead'] as $role) {
            $user = $this->makeUser($role);
            $this->actingAs($user)->post(route('reports.periodic.teacher-attendance.store'), [
                'month' => '2026-11', 'note' => 'Lần đầu', 'user_id' => $other->id,
            ])->assertSessionHasNoErrors();
            Carbon::setTestNow(Carbon::parse('2026-12-02 10:00:00'));
            $this->post(route('reports.periodic.teacher-attendance.store'), [
                'month' => '2026-11', 'note' => 'Cập nhật', 'user_id' => $other->id,
            ])->assertSessionHasNoErrors();
            $report = StaffReport::where('user_id', $user->id)->sole();
            $this->assertSame(['teacher_attendance', '2026-11', 'submitted', 'Cập nhật'], [$report->kind, $report->period_key, $report->status, $report->data['note']]);
            $route = $role === 'teacher_fulltime' ? 'reports.periodic.teacher-monthly' : 'reports.periodic.timesheet-monthly';
            $this->get(route($route, ['month' => '2026-11']))->assertOk()->assertSee('Đã nộp - 02/12/2026')
                ->assertInertia(fn (AssertableInertia $page) => $page->where(
                    $role === 'teacher_fulltime' ? 'attendance.submitted_at' : 'submitted_at', $report->updated_at->toIso8601String()));
        }
        $this->assertSame(3, StaffReport::count());
        $this->assertSame(0, StaffReport::where('user_id', $other->id)->count());
    }

    public function test_future_month_is_rejected_and_empty_note_is_allowed(): void
    {
        $user = $this->makeUser('academic_staff');
        $this->actingAs($user)->post(route('reports.periodic.teacher-attendance.store'), ['month' => '2026-12'])
            ->assertSessionHasErrors('month');
        $this->assertSame(0, StaffReport::count());
        $this->post(route('reports.periodic.teacher-attendance.store'), ['month' => '2026-10'])->assertSessionHasNoErrors();
        $this->assertNull(StaffReport::sole()->data['note']);
        Carbon::setTestNow(Carbon::parse('2026-12-02 10:00:00'));
        $this->post(route('reports.periodic.teacher-attendance.store'), ['month' => '2026-10'])->assertSessionHasNoErrors();
        $this->assertSame('2026-12-02', StaffReport::sole()->updated_at->toDateString());
    }

    public function test_assistant_only_cannot_submit_or_see_the_tab_even_with_an_academic_role(): void
    {
        foreach (['assistant', ['assistant', 'academic_staff']] as $roles) {
            $user = $this->makeUser($roles);
            $this->assertNotContains('teacher_attendance', StaffType::structuredReports($user));
            $this->actingAs($user)->get(route('reports.periodic.timesheet-monthly'))->assertRedirect();
            $this->post(route('reports.periodic.teacher-attendance.store'), ['month' => '2026-11'])->assertForbidden();
            $this->get(route(is_array($roles) ? 'reports.periodic.daily' : 'reports.my'))->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page->where('timesheetReminder', null)
                    ->where('tabs', fn ($tabs) => ! collect($tabs)->contains(fn ($tab) => $tab['label'] === 'Báo cáo chấm công tháng')));
        }
        $teacher = $this->makeUser('teacher_fulltime');
        $this->assertSame(['teacher_monthly'], StaffType::structuredReports($teacher));
        $this->actingAs($teacher)->get(route('reports.periodic.timesheet-monthly'))->assertRedirect(route('reports.periodic.teacher-monthly'));
    }

    public function test_reminder_starts_on_monday_of_the_week_containing_month_end(): void
    {
        foreach (['2026-11' => '2026-11-30', '2026-10' => '2026-10-26', '2027-01' => '2027-01-25', '2026-12' => '2026-12-28'] as $month => $start) {
            $this->assertSame($start, TimesheetReminder::startsOn($month)->toDateString());
        }
        $user = $this->makeUser('teacher_fulltime');
        Carbon::setTestNow(Carbon::parse('2026-11-29'));
        $this->assertSame([], TimesheetReminder::pendingMonths($user, today()));
        Carbon::setTestNow(Carbon::parse('2026-11-30'));
        $this->assertSame(['2026-11'], TimesheetReminder::pendingMonths($user, today()));
        Carbon::setTestNow(Carbon::parse('2026-12-01'));
        $this->assertSame(['2026-11'], TimesheetReminder::pendingMonths($user, today()));
        Carbon::setTestNow(Carbon::parse('2026-12-28'));
        $this->assertSame(['2026-11', '2026-12'], TimesheetReminder::pendingMonths($user, today()));
    }

    public function test_only_submitted_timesheets_for_this_user_remove_reminders(): void
    {
        $user = $this->makeUser('academic_staff');
        $other = $this->makeUser('teacher_fulltime');
        $this->actingAs($other)->post(route('reports.periodic.teacher-attendance.store'), ['month' => '2026-11'])->assertSessionHasNoErrors();
        $this->actingAs($user)->post(route('reports.periodic.teacher-attendance.store'), ['month' => '2026-11'])->assertSessionHasNoErrors();
        $report = StaffReport::where('user_id', $user->id)->sole();
        $this->assertSame([], TimesheetReminder::pendingMonths($user, today()));
        $this->get(route('dashboard'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('timesheetReminder', null));
        $report->update(['status' => 'draft']);
        $this->assertSame(['2026-11'], TimesheetReminder::pendingMonths($user, today()));
        $report->update(['status' => 'submitted', 'kind' => 'academic_monthly']);
        $this->assertSame(['2026-11'], TimesheetReminder::pendingMonths($user, today()));
    }

    public function test_floor_creation_month_and_ineligible_users_are_respected(): void
    {
        $user = $this->makeUser('academic_lead');
        Carbon::setTestNow(Carbon::parse('2026-11-01'));
        // Tháng 10 trước mốc triển khai nên không nhắc bù.
        $this->assertSame([], TimesheetReminder::pendingMonths($user, today()));
        Carbon::setTestNow(Carbon::parse('2026-12-28'));
        $newUser = $this->makeUser('academic_staff', ['created_at' => '2026-12-15']);
        $this->assertSame(['2026-12'], TimesheetReminder::pendingMonths($newUser, today()));
        foreach (['assistant', 'admin', 'sales_consultant'] as $role) {
            $this->assertSame([], TimesheetReminder::pendingMonths($this->makeUser($role), today()));
        }
    }

    public function test_shared_reminder_on_dashboard_and_report_pages_uses_the_correct_destination(): void
    {
        foreach (['academic_staff', 'academic_lead', 'teacher_fulltime', 'assistant'] as $role) {
            $user = $this->makeUser($role);
            $destination = $role === 'teacher_fulltime' ? 'reports.periodic.teacher-monthly' : 'reports.periodic.timesheet-monthly';
            $pages = ['dashboard', in_array($role, ['academic_staff', 'academic_lead'], true) ? 'reports.periodic.daily' : ($role === 'assistant' ? 'reports.my' : 'reports.periodic.teacher-monthly')];
            foreach ($pages as $route) {
                $this->actingAs($user)->get(route($route))->assertOk()->assertInertia(fn (AssertableInertia $page) => $role === 'assistant'
                    ? $page->where('timesheetReminder', null)
                    : $page->where('timesheetReminder.0.month', '2026-11')
                        ->where('timesheetReminder.0.href', route($destination, ['month' => '2026-11'])));
            }
            $this->get(route('reports.journal'))->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page->where('timesheetReminder', null));
        }
    }
}
