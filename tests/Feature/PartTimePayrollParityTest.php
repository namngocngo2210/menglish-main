<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\TeacherHourlyRate;
use App\Models\TeacherTimesheet;
use App\Models\User;
use App\Support\Money;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** TK-2026-0118: GV part-time — bảng lương, chi tiết phiếu và widget "Lương tạm tính" dùng chung một đường tính. */
class PartTimePayrollParityTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $admin;

    private User $vn;

    private User $foreign;

    private ClassModel $class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 14)->setTime(10, 0));
        $this->branch = Branch::create(['name' => 'Cơ sở A', 'code' => 'CSA', 'is_active' => true]);
        $this->admin = $this->staff(Roles::ADMIN);
        $this->vn = $this->staff(Roles::TEACHER_PARTTIME);
        $this->foreign = $this->staff(Roles::FOREIGN_TEACHER);
        $course = Course::create(['code' => 'FAM', 'name' => 'Family', 'tuition_fee' => 1, 'is_active' => true]);
        $this->class = ClassModel::create(['code' => 'DEMO-CG-FAM1', 'name' => 'Family 1', 'course_id' => $course->id,
            'branch_id' => $this->branch->id, 'teacher_id' => $this->vn->id, 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true, 'base_salary' => 0]);
        $user->assignRole($role);

        return $user;
    }

    private function rate(float $amount = 250000, string $from = '2026-10-01'): void
    {
        TeacherHourlyRate::create(['user_id' => $this->vn->id, 'hourly_rate' => $amount, 'rate_unit' => TeacherHourlyRate::UNIT_SESSION,
            'teacher_type' => 'parttime', 'effective_from' => $from]);
    }

    private function teach(string $date, bool $withForeign, string $status = 'valid'): TeacherTimesheet
    {
        $session = ClassSession::create(['class_id' => $this->class->id, 'branch_id' => $this->branch->id, 'date' => $date,
            'start_time' => '17:30', 'end_time' => '19:00', 'teacher_id' => $this->vn->id,
            'foreign_teacher_id' => $withForeign ? $this->foreign->id : null, 'status' => 'scheduled']);

        return TeacherTimesheet::create(['user_id' => $this->vn->id, 'class_id' => $this->class->id, 'class_session_id' => $session->id,
            'teaching_date' => $date, 'scheduled_time' => '17:30-19:00', 'hours' => 1.5, 'type' => 'regular', 'source' => 'manual', 'status' => $status]);
    }

    private function runPayroll(): PayrollRecord
    {
        $period = PayrollPeriod::firstOrCreate(['code' => 'PR-2026-11'], ['title' => 'Bảng lương Tháng 11/2026', 'month' => 11, 'year' => 2026,
            'start_date' => '2026-11-01', 'end_date' => '2026-11-30', 'status' => PayrollPeriod::STATUS_DRAFT]);
        $period->calculatePayrollForPeriod();

        return $period->records()->where('user_id', $this->vn->id)->firstOrFail();
    }

    private function widget(): array
    {
        return $this->actingAs($this->vn)->get(route('dashboard'))->assertOk()->inertiaProps('overview')['shifts']['salary'];
    }

    public function test_regular_manual_sessions_are_paid_and_widget_matches_record_net(): void
    {
        $this->rate();
        foreach (['2026-11-02', '2026-11-09', '2026-11-11'] as $date) {
            $this->teach($date, false);
        }

        $record = $this->runPayroll();
        $this->assertEquals(750000, $record->teaching_salary);
        $this->assertSame(3, (int) $record->teaching_sessions);
        $this->assertEquals(750000, $record->net_salary);

        // Widget = net của phiếu kỳ hiện tại; chi tiết phiếu cộng ra đúng tổng.
        $this->assertSame(Money::format($record->net_salary), $this->widget()['amount']);
        $detail = $this->actingAs($this->admin)->get(route('payroll.records.show', $record->id))->assertOk()->inertiaProps('timesheets');
        $this->assertEquals(750000, collect($detail)->sum('amount'));
        $this->assertSame([false, false, false], collect($detail)->pluck('co_taught')->all());
    }

    public function test_recalculation_is_idempotent(): void
    {
        $this->rate();
        $this->teach('2026-11-02', false);
        $first = $this->runPayroll();
        $second = $this->runPayroll();

        $this->assertSame($first->id, $second->id);
        $this->assertEquals(250000, $second->teaching_salary);
        $this->assertSame(1, (int) $second->teaching_sessions);
        $this->assertSame(1, PayrollRecord::where('user_id', $this->vn->id)->count());
    }

    /** BR-03 giữ nguyên: buổi có GVNN không vào lương tự tính, nhưng phiếu / chi tiết / widget phải cùng một con số. */
    public function test_co_taught_sessions_stay_manual_and_detail_widget_agree_with_record(): void
    {
        $this->rate();
        foreach (['2026-11-02', '2026-11-09', '2026-11-11'] as $date) {
            $this->teach($date, true);
        }

        $record = $this->runPayroll();
        $this->assertEquals(0, $record->teaching_salary);
        $this->assertSame(0, (int) $record->teaching_sessions);
        $this->assertSame(3, $record->foreign_teacher_sessions_count);

        $detail = collect($this->actingAs($this->admin)->get(route('payroll.records.show', $record->id))->assertOk()->inertiaProps('timesheets'));
        $this->assertEquals(0, $detail->sum('amount'));   // không còn lệch 750.000 so với phiếu
        $this->assertSame([true, true, true], $detail->pluck('co_taught')->all());

        $widget = $this->widget();
        $this->assertSame(Money::format($record->net_salary), $widget['amount']);
        $this->assertSame(0.0, (float) $widget['hours']);
    }

    public function test_widget_estimate_before_payroll_uses_session_rate_and_ignores_unapproved_shifts(): void
    {
        $this->rate();
        $this->teach('2026-11-02', false);
        $this->teach('2026-11-09', false, 'pending_review');
        $this->teach('2026-11-11', false, 'invalid');

        $widget = $this->widget();
        $this->assertSame(Money::format(250000), $widget['amount']);
        $this->assertEquals(1.5, $widget['hours']);
        $this->assertFalse($widget['missingRate']);
    }

    public function test_widget_rate_effective_date_is_respected(): void
    {
        $this->rate(200000, '2026-01-01');
        $this->rate(300000, '2026-11-10');
        $this->teach('2026-11-09', false);
        $this->teach('2026-11-11', false);

        $this->assertSame(Money::format(500000), $this->widget()['amount']);
    }

    public function test_assistant_without_hour_rate_still_shows_missing_rate(): void
    {
        $ta = $this->staff(Roles::ASSISTANT);
        TeacherTimesheet::create(['user_id' => $ta->id, 'class_id' => $this->class->id, 'teaching_date' => '2026-11-02',
            'scheduled_time' => '17:30-19:00', 'hours' => 1.5, 'type' => 'regular', 'status' => 'valid']);

        $widget = $this->actingAs($ta)->get(route('dashboard'))->assertOk()->inertiaProps('overview')['shifts']['salary'];
        $this->assertNull($widget['amount']);
        $this->assertTrue($widget['missingRate']);
    }
}
