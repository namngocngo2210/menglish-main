<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\Course;
use App\Models\CrmCustomer;
use App\Models\PayrollPeriod;
use App\Models\Penalty;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\TuitionReceipt;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * TK-2026-0154: widget nghiệp vụ trên Tổng quan — đúng nguồn, đúng chi nhánh, luôn hiện kể cả 0, chỉ đọc.
 * Thứ Tư 18/11/2026 → tuần sinh nhật 16/11–22/11.
 */
class DashboardBusinessWidgetsTest extends TestCase
{
    use RefreshDatabase;

    private Branch $a;

    private Branch $b;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-18 09:00:00');
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->a = Branch::create(['name' => 'Cơ sở A', 'code' => 'CSA', 'is_active' => true]);
        $this->b = Branch::create(['name' => 'Cơ sở B', 'code' => 'CSB', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function seedData(): void
    {
        $staffA = $this->user('teacher_fulltime', $this->a);
        $staffB = $this->user('teacher_fulltime', $this->b);
        $penalty = fn (User $u, string $status) => Penalty::create([
            'code' => Penalty::generateCode(), 'user_id' => $u->id, 'violation_type' => 'X', 'error_category' => 'operations',
            'violation_date' => today(), 'status' => $status, 'amount' => 0,
        ]);
        $penalty($staffA, 'pending');
        $penalty($staffA, 'confirmed');
        $penalty($staffA, 'fined');
        $penalty($staffB, 'pending');

        $period = fn (string $code, int $month, string $start, string $end, string $status) => PayrollPeriod::create([
            'code' => $code, 'title' => $code, 'month' => $month, 'year' => 2026, 'start_date' => $start, 'end_date' => $end, 'status' => $status,
        ]);
        $period('PR-11', 11, '2026-11-01', '2026-11-30', 'draft');
        $period('PR-10', 10, '2026-10-01', '2026-10-31', 'draft');

        foreach ([[$this->a, 'new'], [$this->a, 'consulting'], [$this->a, 'testing'], [$this->a, 'tested'], [$this->b, 'new']] as $i => [$branch, $stage]) {
            $phone = '0911'.str_pad((string) $i, 6, '0', STR_PAD_LEFT);
            CrmCustomer::create(['code' => CrmCustomer::generateCode(), 'phone' => $phone, 'phone_normalized' => $phone, 'branch_id' => $branch->id,
                'source' => 'Facebook Ads', 'appointment_type' => 'offline', 'stage' => $stage, 'name' => "Lead {$i}"]);
        }

        $course = Course::create(['code' => 'STA', 'name' => 'Starters', 'tuition_fee' => 1, 'is_active' => true]);
        $classOf = fn (Branch $br) => ClassModel::create(['code' => 'C'.$br->code, 'name' => 'Lớp '.$br->code, 'course_id' => $course->id, 'branch_id' => $br->id,
            'max_capacity' => 10, 'status' => 'active']);
        $classA = $classOf($this->a);
        $classB = $classOf($this->b);

        $student = fn (Branch $br, ClassModel $c, string $dob, string $status = 'studying') => Student::create([
            'code' => 'HV'.random_int(1000, 9999).$br->id, 'name' => 'HS '.$dob, 'phone' => '09'.random_int(10000000, 99999999),
            'branch_id' => $br->id, 'current_class_id' => $c->id, 'status' => $status, 'dob' => $dob,
        ]);
        $sA = $student($this->a, $classA, '2010-11-20');
        $student($this->a, $classA, '2010-11-17');
        $student($this->a, $classA, '2010-11-25');
        $student($this->a, $classA, '2010-11-21', 'dropped');
        $sB = $student($this->b, $classB, '2010-11-19');

        $tuition = fn (Student $s, Branch $br, ClassModel $c) => StudentTuition::create([
            'student_id' => $s->id, 'branch_id' => $br->id, 'class_id' => $c->id, 'session_count' => 10, 'total_amount' => 1000000,
            'final_amount' => 1000000, 'paid_amount' => 0, 'debt_amount' => 1000000, 'due_date' => '2026-11-01', 'status' => 'unpaid',
        ]);
        $tA = $tuition($sA, $this->a, $classA);
        $tB = $tuition($sB, $this->b, $classB);

        $receipt = fn (StudentTuition $t, string $method, ?User $depositedBy = null) => TuitionReceipt::create([
            'receipt_number' => TuitionReceipt::generateReceiptNumber(), 'student_tuition_id' => $t->id, 'student_id' => $t->student_id,
            'amount' => 500000, 'payment_method' => $method, 'payment_date' => today(), 'status' => TuitionReceipt::STATUS_APPROVED,
            'deposited_by' => $depositedBy?->id, 'deposited_at' => $depositedBy ? now() : null,
        ]);
        $receipt($tA, 'cash');
        $receipt($tA, 'cash', $staffA);
        $receipt($tA, 'transfer');
        $receipt($tB, 'cash');
    }

    private function widgets(User $user): array
    {
        $overview = $this->actingAs($user)->get(route('dashboard'))->assertOk()->inertiaProps('overview');

        return ['widgets' => collect($overview['widgets'])->pluck('value', 'key')->all(), 'aggregate' => $overview['aggregate']];
    }

    public function test_admin_sees_all_widgets_system_wide_with_aggregate_block(): void
    {
        $this->seedData();
        $result = $this->widgets($this->user('admin', $this->a));

        $this->assertSame(
            ['violations' => 3, 'payroll' => 1, 'leads' => 4, 'birthdays' => 3, 'receipts_reconcile' => 2],
            $result['widgets'],
        );
        $this->assertSame([4, 4, 2, 2], array_column($result['aggregate'], 'value'));
    }

    public function test_admin_widgets_show_zero_not_hidden_when_no_data(): void
    {
        $result = $this->widgets($this->user('admin', $this->a));

        $this->assertSame(
            ['violations' => 0, 'payroll' => 0, 'leads' => 0, 'birthdays' => 0, 'receipts_reconcile' => 0],
            $result['widgets'],
        );
        $this->assertSame([0, 0, 0, 0], array_column($result['aggregate'], 'value'));
    }

    public function test_manager_is_scoped_to_own_branch_and_has_no_aggregate(): void
    {
        $this->seedData();
        $result = $this->widgets($this->user('manager', $this->a));

        $this->assertSame(2, $result['widgets']['violations']);
        $this->assertSame(3, $result['widgets']['leads']);
        $this->assertSame(2, $result['widgets']['birthdays']);
        $this->assertSame(1, $result['widgets']['receipts_reconcile']);
        $this->assertNull($result['aggregate']);
    }

    public function test_academic_staff_sees_leads_birthdays_overdue_tuition_and_receipts_in_own_branch(): void
    {
        $this->seedData();
        $result = $this->widgets($this->user('academic_staff', $this->a));

        $this->assertSame(['leads' => 3, 'birthdays' => 2, 'overdue_tuition' => 1, 'receipts_reconcile' => 1], $result['widgets']);
    }

    public function test_sales_consultant_only_sees_birthdays_and_teacher_sees_none(): void
    {
        $this->seedData();

        $this->assertSame(['birthdays' => 2], $this->widgets($this->user('sales_consultant', $this->a))['widgets']);
        $this->assertSame([], $this->widgets($this->user('teacher_fulltime', $this->a))['widgets']);
    }

    public function test_dashboard_widgets_are_read_only(): void
    {
        $this->seedData();
        $before = [Penalty::count(), PayrollPeriod::count(), TuitionReceipt::count(), CrmCustomer::count()];
        $this->widgets($this->user('admin', $this->a));

        $this->assertSame($before, [Penalty::count(), PayrollPeriod::count(), TuitionReceipt::count(), CrmCustomer::count()]);
    }

    private function user(string $role, Branch $branch): User
    {
        $user = User::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }
}
