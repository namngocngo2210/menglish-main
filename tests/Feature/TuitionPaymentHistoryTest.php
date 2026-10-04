<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\MerchandiseItem;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\TuitionReceipt;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\GrantsPersonalPermissions;
use Tests\TestCase;

/** Lịch sử thu học phí đầy đủ: modal Học vụ (bấm dòng công nợ / thẻ học phí) và cổng học sinh (bấm thẻ học phí). */
class TuitionPaymentHistoryTest extends TestCase
{
    use GrantsPersonalPermissions;
    use RefreshDatabase;

    private Branch $branch;

    private Student $student;

    private StudentTuition $tuition;

    private User $studentUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'Chi nhánh Cầu Giấy', 'code' => 'CG']);
        $class = ClassModel::create(['name' => 'Starters A1', 'code' => 'ST-A1', 'max_capacity' => 16, 'status' => 'active', 'branch_id' => $this->branch->id]);
        $this->studentUser = User::factory()->create();
        $this->student = Student::create([
            'user_id' => $this->studentUser->id,
            'code' => 'HV-70001',
            'name' => 'Trần Minh Khang',
            'phone' => '0911 222 333',
            'status' => 'active',
            'branch_id' => $this->branch->id,
            'current_class_id' => $class->id,
        ]);
        $this->tuition = StudentTuition::create([
            'student_id' => $this->student->id,
            'class_id' => $class->id,
            'branch_id' => $this->branch->id,
            'total_amount' => 9000000,
            'other_fees' => 1000000,
            'final_amount' => 10000000,
            'paid_amount' => 0,
            'debt_amount' => 10000000,
            'due_date' => now()->addMonths(2),
            'status' => 'unpaid',
        ]);

        $book = MerchandiseItem::create(['code' => 'BOOK-SP', 'name' => 'Sách Starters Plus', 'category' => 'book', 'unit' => 'Cuốn', 'price' => 500000, 'stock_quantity' => 10, 'is_active' => true]);

        // 3 đợt đã duyệt (đợt 2 có thu khác 500k gồm sách), 1 phiếu chờ duyệt.
        $this->receipt('PT-DOT-1', 3000000, '2026-08-01');
        $this->receipt('PT-DOT-2', 4500000, '2026-09-01', [
            'surcharge_amount' => 500000,
            'surcharge_reason' => 'Mua thêm sách',
            'collected_items' => [['id' => $book->id, 'name' => 'Sách Starters Plus', 'quantity' => 1, 'source' => 'surcharge']],
        ]);
        $this->receipt('PT-DOT-3', 2000000, '2026-09-20', ['discount_amount' => 500000]);
        $this->receipt('PT-CHO-DUYET', 1000000, '2026-10-01', ['status' => TuitionReceipt::STATUS_PENDING]);
        $this->tuition->recalculateDebt();
    }

    private function receipt(string $number, int $amount, string $date, array $extra = []): TuitionReceipt
    {
        return TuitionReceipt::create([
            'receipt_number' => $number,
            'student_tuition_id' => $this->tuition->id,
            'student_id' => $this->student->id,
            'amount' => $amount,
            'payment_method' => 'transfer',
            'payment_date' => $date,
            'status' => TuitionReceipt::STATUS_APPROVED,
            ...$extra,
        ]);
    }

    private function accountant(?int $branchId = null): User
    {
        $user = User::factory()->create(['branch_id' => $branchId]);
        $user->assignRole('accountant');

        return $branchId ? $user : $this->grantHeadOffice($user);
    }

    public function test_staff_modal_lists_every_installment_with_running_debt(): void
    {
        $this->assertSame(500000.0, (float) $this->tuition->fresh()->debt_amount);

        $this->actingAs($this->accountant())
            ->get(route('tuition.students.payments', $this->student->id))
            ->assertOk()
            ->assertSee('Lịch sử thu học phí')
            ->assertSee('Sách Starters Plus')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Tuition/StudentPayments')
                ->where('summary.final_amount', 10000000)
                ->where('summary.paid_amount', 9000000)
                ->where('summary.debt_amount', 500000)
                ->where('summary.installments', 3)
                // Mới nhất trên cùng; còn nợ sau từng đợt = 10tr − Σ(tiền cấn học phí + ưu đãi).
                ->where('payments.0.number', 'PT-DOT-3')
                ->where('payments.0.title', 'Đợt 3')
                ->where('payments.0.debt_after', 500000)
                ->where('payments.1.number', 'PT-DOT-2')
                ->where('payments.1.debt_after', 3000000)
                ->where('payments.1.surcharge', 500000)
                ->where('payments.1.surcharge_detail', 'Mua thêm sách · Sách Starters Plus')
                ->where('payments.2.title', 'Đợt 1')
                ->where('payments.2.debt_after', 7000000)
                ->has('payments', 3)
                ->has('others', 1)
                ->where('others.0.number', 'PT-CHO-DUYET'));
    }

    public function test_staff_outside_branch_scope_cannot_open_history(): void
    {
        $other = Branch::create(['name' => 'Chi nhánh Hà Đông', 'code' => 'HD']);

        $this->actingAs($this->accountant($other->id))
            ->get(route('tuition.students.payments', $this->student->id))
            ->assertNotFound();
    }

    public function test_debt_list_rows_and_profile_card_open_the_history_modal(): void
    {
        $path = '/tuition/students/'.$this->student->id.'/payments';
        $user = $this->accountant();

        $this->actingAs($user)->get(route('tuition.students'))
            ->assertOk()
            ->assertSee($path.'" data-modal="2xl"', false);

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin)->get(route('students.show', $this->student->id))
            ->assertOk()
            ->assertSee('Xem toàn bộ lịch sử')
            ->assertSee($path, false);
    }

    public function test_student_portal_shows_full_history_without_other_fee_items(): void
    {
        $this->actingAs($this->studentUser)
            ->get(route('portal.student.home', ['studentId' => $this->student->id]))
            ->assertOk()
            ->assertSee('Lịch sử thu học phí')
            ->assertSee('PT-DOT-1')
            ->assertSee('Còn nợ sau lần này')
            ->assertDontSee('Sách Starters Plus')
            ->assertDontSee('Mua thêm sách')
            ->assertDontSee('PT-CHO-DUYET')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('totalPaid', 9000000)
                ->where('debtAmount', 500000)
                ->has('paymentHistory.payments', 3)
                ->where('paymentHistory.payments.1.surcharge', 500000)
                ->missing('paymentHistory.payments.1.surcharge_detail')
                ->has('paymentHistory.others', 0));
    }

    public function test_history_covers_every_course_of_the_student(): void
    {
        $nextClass = ClassModel::create(['name' => 'Movers B1', 'code' => 'MV-B1', 'max_capacity' => 16, 'status' => 'active', 'branch_id' => $this->branch->id]);
        $second = StudentTuition::create([
            'student_id' => $this->student->id,
            'class_id' => $nextClass->id,
            'branch_id' => $this->branch->id,
            'total_amount' => 6000000,
            'final_amount' => 6000000,
            'paid_amount' => 0,
            'debt_amount' => 6000000,
            'status' => 'unpaid',
        ]);
        TuitionReceipt::create([
            'receipt_number' => 'PT-KHOA-2',
            'student_tuition_id' => $second->id,
            'student_id' => $this->student->id,
            'amount' => 2000000,
            'payment_method' => 'cash',
            'payment_date' => '2026-10-02',
            'status' => TuitionReceipt::STATUS_APPROVED,
        ]);
        $second->recalculateDebt();

        $this->actingAs($this->accountant())
            ->get(route('tuition.students.payments', $this->student->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('tuitions', 2)
                ->has('payments', 4)
                ->where('summary.paid_amount', 11000000)
                ->where('summary.debt_amount', 4500000)
                ->where('payments.0.number', 'PT-KHOA-2')
                ->where('payments.0.tuition_label', 'Movers B1')
                ->where('payments.0.title', 'Đợt 1')
                ->where('summary.installments', 4)
                ->where('payments.0.debt_after', 4000000)
                ->where('payments.1.tuition_label', 'Starters A1'));

        // Thẻ "Thông tin học phí" trong hồ sơ cũng cộng mọi khóa và liệt kê phiếu của tất cả các khóa.
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin)
            ->get(route('students.show', $this->student->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('tuition.final_amount', 16000000)
                ->where('tuition.debt_amount', 4500000)
                ->has('tuition.receipts', 5)
                ->where('tuition.receipts.0.number', 'PT-KHOA-2'));
    }
}
