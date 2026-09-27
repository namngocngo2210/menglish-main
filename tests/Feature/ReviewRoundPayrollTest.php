<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\TeacherHourlyRate;
use App\Models\TeacherTimesheet;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rà soát đợt 5-6 (Lương): tính lại không làm mất lương GV đã nghỉ, đối soát ca đúng phạm vi chi nhánh,
 * xóa được ghi chú GVNN, không thêm đơn giá hiệu lực trong kỳ đã khóa, tổng kỳ theo phạm vi người xem.
 */
class ReviewRoundPayrollTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Branch $otherBranch;

    private User $admin;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'Cơ sở L', 'code' => 'CSL', 'is_active' => true]);
        $this->otherBranch = Branch::create(['name' => 'Cơ sở M', 'code' => 'CSM', 'is_active' => true]);
        $this->admin = $this->userWithRole('admin');
        $this->teacher = $this->userWithRole('teacher');
    }

    public function test_recalculation_keeps_pay_of_teacher_who_left(): void
    {
        $class = $this->makeClass($this->branch);
        TeacherTimesheet::create([
            'user_id' => $this->teacher->id, 'class_id' => $class->id, 'teaching_date' => '2026-08-10',
            'hours' => 2, 'hourly_rate' => 200000, 'type' => 'regular', 'status' => 'valid',
        ]);
        $this->teacher->update(['is_active' => false]);
        $this->teacher->delete();

        $period = $this->period(8);
        $period->calculatePayrollForPeriod();

        $this->assertTrue($period->records()->where('user_id', $this->teacher->id)->exists());
    }

    public function test_branch_manager_cannot_review_timesheet_of_other_branch(): void
    {
        $manager = $this->userWithRole('manager');
        $foreign = TeacherTimesheet::create([
            'user_id' => $this->teacher->id, 'class_id' => $this->makeClass($this->otherBranch)->id, 'teaching_date' => now()->toDateString(),
            'hours' => 2, 'type' => 'regular', 'status' => 'pending_review',
        ]);

        $this->actingAs($manager)->post(route('payroll.timesheets.review', $foreign->id), ['decision' => 'valid'])->assertForbidden();
        $this->actingAs($manager)->post(route('payroll.timesheets.bulk-review'), ['ids' => [$foreign->id]]);
        $this->assertSame('pending_review', $foreign->fresh()->status);
    }

    public function test_foreign_session_note_can_be_cleared(): void
    {
        $period = $this->period(8);
        $record = PayrollRecord::create([
            'payroll_period_id' => $period->id, 'user_id' => $this->teacher->id, 'employee_type' => 'parttime',
            'adjustment_notes' => 'Ghi chú cũ',
        ]);

        $this->actingAs($this->admin)->post(route('payroll.records.update', $record->id), ['foreign_session_pay' => 0, 'notes' => ''])
            ->assertSessionHasNoErrors();

        $this->assertNull($record->fresh()->adjustment_notes);
    }

    public function test_teacher_rate_cannot_start_inside_locked_period(): void
    {
        $this->period(8, 'approved');

        $this->actingAs($this->admin)->post(route('payroll.config.teacher-rates.personal.store'), [
            'user_id' => $this->teacher->id, 'hourly_rate' => 250000, 'rate_unit' => 'session', 'effective_from' => '2026-08-15',
        ])->assertSessionHasErrors('effective_from');
        $this->assertSame(0, TeacherHourlyRate::where('user_id', $this->teacher->id)->count());
    }

    private function makeClass(Branch $branch): ClassModel
    {
        static $seq = 0;
        $seq++;

        return ClassModel::create(['code' => 'L-'.$seq, 'name' => 'Lớp L'.$seq, 'branch_id' => $branch->id, 'teacher_id' => $this->teacher->id, 'status' => 'active']);
    }

    private function period(int $month, string $status = 'draft'): PayrollPeriod
    {
        return PayrollPeriod::create([
            'code' => sprintf('PR-2026-%02d', $month), 'title' => "Bảng lương Tháng {$month}/2026", 'month' => $month, 'year' => 2026,
            'start_date' => sprintf('2026-%02d-01', $month), 'end_date' => now()->setDate(2026, $month, 1)->endOfMonth()->toDateString(),
            'status' => $status,
        ]);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }
}
