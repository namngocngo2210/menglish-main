<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\TuitionReceipt;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** TK-2026-0104: cổng học viên xem chi tiết phiếu thu đã duyệt + bill (HP-LS-08, HP-LS-09). */
class PortalReceiptDetailTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $other;

    private Student $student;

    private StudentTuition $tuition;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $branch = Branch::create(['name' => 'Chi nhánh Cầu Giấy', 'code' => 'CG']);
        $class = ClassModel::create(['name' => 'Starters A1', 'code' => 'ST-A1', 'max_capacity' => 16, 'status' => 'active', 'branch_id' => $branch->id]);
        $this->owner = User::factory()->create();
        $this->other = User::factory()->create();
        $this->student = $this->makeStudent($this->owner, 'HV-81001', $class, $branch);
        $this->makeStudent($this->other, 'HV-81002', $class, $branch);
        $this->tuition = StudentTuition::create([
            'student_id' => $this->student->id, 'class_id' => $class->id, 'branch_id' => $branch->id,
            'total_amount' => 5000000, 'final_amount' => 5000000, 'paid_amount' => 0, 'debt_amount' => 5000000,
            'due_date' => now()->addMonth(), 'status' => 'unpaid',
        ]);
    }

    private function makeStudent(User $user, string $code, ClassModel $class, Branch $branch): Student
    {
        $user->assignRole('student');

        return Student::create(['user_id' => $user->id, 'code' => $code, 'name' => 'HS '.$code, 'phone' => '0911000'.substr($code, -3),
            'status' => 'active', 'branch_id' => $branch->id, 'current_class_id' => $class->id]);
    }

    private function receipt(string $status = TuitionReceipt::STATUS_APPROVED, array $extra = []): TuitionReceipt
    {
        return TuitionReceipt::create([
            'receipt_number' => 'PT-DETAIL-'.TuitionReceipt::count(),
            'student_tuition_id' => $this->tuition->id,
            'student_id' => $this->student->id,
            'amount' => 2000000,
            'payment_method' => 'transfer',
            'payment_date' => '2026-09-01',
            'status' => $status,
            'notes' => 'GHI-CHU-NOI-BO',
            'proof_image' => '/uploads/tuition/receipts/bill.jpg',
            ...$extra,
        ]);
    }

    public function test_owner_sees_approved_receipt_with_bill_and_no_internal_fields(): void
    {
        $rc = $this->receipt();

        $this->actingAs($this->owner)->get(route('portal.student.receipt', $rc->id))
            ->assertOk()
            ->assertDontSee('GHI-CHU-NOI-BO')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Portal/ReceiptDetail')
                ->where('receipt.number', $rc->receipt_number)
                ->where('receipt.amount', 2000000)
                ->where('receipt.method', 'Chuyển khoản')
                ->where('receipt.date', '2026-09-01')
                ->where('receipt.bill', '/uploads/tuition/receipts/bill.jpg')
                ->where('receipt.bill_is_pdf', false)
                ->has('receipt.fee_item')
                ->missing('receipt.notes')
                ->missing('receipt.creator_name')
                ->missing('receipt.approver_name')
                ->missing('receipt.reconciliation')
                ->missing('receipt.transaction_code'));
    }

    public function test_other_students_receipt_is_forbidden(): void
    {
        $rc = $this->receipt();

        $this->actingAs($this->other)->get(route('portal.student.receipt', $rc->id))->assertForbidden();
    }

    public function test_other_students_unapproved_receipt_is_forbidden_not_leaked(): void
    {
        $rc = $this->receipt(TuitionReceipt::STATUS_PENDING);

        $this->actingAs($this->other)->get(route('portal.student.receipt', $rc->id))->assertForbidden();
    }

    public function test_own_unapproved_receipt_is_not_found(): void
    {
        foreach ([TuitionReceipt::STATUS_PENDING, TuitionReceipt::STATUS_DRAFT, TuitionReceipt::STATUS_REJECTED] as $status) {
            $rc = $this->receipt($status);
            $this->actingAs($this->owner)->get(route('portal.student.receipt', $rc->id))->assertNotFound();
        }
    }

    /** TK-2026-0107: phiếu đã hủy hóa đơn xem được (kèm cờ cancelled); phiếu đã duyệt không bật cờ. */
    public function test_cancelled_receipt_is_viewable_and_flagged(): void
    {
        $cancelled = $this->receipt(TuitionReceipt::STATUS_CANCELLED);
        $approved = $this->receipt();

        $this->actingAs($this->owner)->get(route('portal.student.receipt', $cancelled->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('receipt.cancelled', true)->where('receipt.amount', 2000000));
        $this->actingAs($this->owner)->get(route('portal.student.receipt', $approved->id))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('receipt.cancelled', false));
    }

    public function test_missing_receipt_is_not_found_and_guest_is_redirected(): void
    {
        $rc = $this->receipt();

        $this->actingAs($this->owner)->get(route('portal.student.receipt', 999999))->assertNotFound();
        auth()->logout();
        $this->get(route('portal.student.receipt', $rc->id))->assertRedirect();
    }

    public function test_pdf_bill_flagged_and_missing_bill_is_null(): void
    {
        $pdf = $this->receipt(extra: ['proof_image' => '/uploads/tuition/receipts/bill.PDF']);
        $none = $this->receipt(extra: ['proof_image' => null]);

        $this->actingAs($this->owner)->get(route('portal.student.receipt', $pdf->id))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('receipt.bill_is_pdf', true));
        $this->actingAs($this->owner)->get(route('portal.student.receipt', $none->id))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('receipt.bill', null));
    }

    public function test_history_dialog_links_each_payment_to_detail(): void
    {
        $rc = $this->receipt();

        $this->actingAs($this->owner)->get(route('portal.student.home', ['studentId' => $this->student->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('paymentHistory.payments.0.detail_url', route('portal.student.receipt', $rc->id)));
    }
}
