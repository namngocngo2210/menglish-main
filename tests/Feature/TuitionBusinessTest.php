<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\Course;
use App\Models\CrmCustomer;
use App\Models\InvoiceCancellation;
use App\Models\MerchandiseItem;
use App\Models\Promotion;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\TuitionReceipt;
use App\Models\TuitionRefundRequest;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TuitionBusinessTest extends TestCase
{
    use RefreshDatabase;

    private User $accountantUser;

    private User $approverUser;

    private Branch $branch;

    private Student $student;

    private ClassModel $classModel;

    private StudentTuition $tuition;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create([
            'name' => 'Chi nhánh Đống Đa',
            'code' => 'DD',
            'address' => 'Số 50 Chùa Bộc, Đống Đa, Hà Nội',
            'phone' => '02433332222',
            'is_active' => true,
        ]);

        $this->accountantUser = User::factory()->create([
            'branch_id' => $this->branch->id,
            'name' => 'Kế toán trưởng MEnglish',
            'is_active' => true,
        ]);
        $this->accountantUser->assignRole('accountant');

        // Người duyệt phải khác người lập phiếu (trừ admin)
        $this->approverUser = User::factory()->create([
            'branch_id' => $this->branch->id,
            'name' => 'Kế toán duyệt',
            'is_active' => true,
        ]);
        $this->approverUser->assignRole('accountant');

        $course = Course::create([
            'code' => 'IELTS-BASIC',
            'name' => 'IELTS Foundation 4.5 - 5.5',
            'tuition_fee' => 10000000,
            'duration_months' => 3,
            'is_active' => true,
        ]);

        $this->classModel = ClassModel::create([
            'code' => 'IE-DD-01',
            'name' => 'Lớp IELTS Foundation DD01',
            'course_id' => $course->id,
            'branch_id' => $this->branch->id,
            'status' => 'active',
        ]);

        $this->student = Student::create([
            'code' => 'HV-00010',
            'name' => 'Trần Khánh Linh',
            'phone' => '0912999888',
            'email' => 'khanhlinh.tran@gmail.com',
            'branch_id' => $this->branch->id,
            'current_class_id' => $this->classModel->id,
            'status' => 'studying',
        ]);

        $this->tuition = StudentTuition::create([
            'student_id' => $this->student->id,
            'class_id' => $this->classModel->id,
            'branch_id' => $this->branch->id,
            'total_amount' => 10000000,
            'discount_amount' => 1000000,
            'final_amount' => 9000000,
            'paid_amount' => 0,
            'debt_amount' => 9000000,
            'due_date' => now()->addDays(7),
            'status' => 'unpaid',
        ]);
    }

    // =========================================================================
    // a. Receipt creation with amount validation and payment method
    // =========================================================================

    public function test_can_create_receipt_with_valid_amount_and_method(): void
    {
        $payload = [
            'student_tuition_id' => $this->tuition->id,
            'amount' => 4500000,
            'payment_method' => 'vietqr',
            'transaction_code' => 'VQR987654321',
            'notes' => 'Thu tiền học phí đợt 1 qua VietQR Techcombank',
        ];

        $response = $this->actingAs($this->accountantUser)->post(route('tuition.receipts.store'), $payload);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        // Lập phiếu không bao giờ tự duyệt: chờ Kế toán/Admin duyệt, chưa cấp số hóa đơn
        $this->assertDatabaseHas('tuition_receipts', [
            'student_tuition_id' => $this->tuition->id,
            'amount' => 4500000,
            'payment_method' => 'vietqr',
            'transaction_code' => 'VQR987654321',
            'creator_id' => $this->accountantUser->id,
            'approver_id' => null,
            'invoice_number' => null,
            'status' => 'pending',
        ]);

        $receipt = TuitionReceipt::where('transaction_code', 'VQR987654321')->first();
        $this->assertNotNull($receipt);
        $this->assertStringStartsWith('PT-', $receipt->receipt_number);
    }

    public function test_receipt_creation_validation_fails_on_small_amount_and_missing_tuition(): void
    {
        $response = $this->actingAs($this->accountantUser)->post(route('tuition.receipts.store'), [
            'student_tuition_id' => 999999, // non existent
            'amount' => 500, // < 1000 min
            'payment_method' => '',
        ]);

        $response->assertSessionHasErrors(['student_tuition_id', 'amount', 'payment_method']);
    }

    // =========================================================================
    // b. Auto debt recalculation on tuition balance & receipt approve/reject
    // =========================================================================

    public function test_auto_debt_recalculation_transitions_status_from_unpaid_to_partial_to_paid(): void
    {
        $this->assertEquals('unpaid', $this->tuition->status);
        $this->assertEquals(9000000, $this->tuition->debt_amount);

        // Step 1: First payment of 4,000,000 -> status becomes 'partial', debt = 5,000,000
        $this->actingAs($this->accountantUser)->post(route('tuition.receipts.store'), [
            'student_tuition_id' => $this->tuition->id,
            'amount' => 4000000,
            'payment_method' => 'transfer',
            'transaction_code' => 'PAY-01',
        ]);
        $this->approveByCode('PAY-01');

        $this->tuition->refresh();
        $this->assertEquals(4000000, $this->tuition->paid_amount);
        $this->assertEquals(5000000, $this->tuition->debt_amount);
        $this->assertEquals('partial', $this->tuition->status);

        // Step 2: Second payment of remaining 5,000,000 -> status becomes 'paid', debt = 0
        $this->actingAs($this->accountantUser)->post(route('tuition.receipts.store'), [
            'student_tuition_id' => $this->tuition->id,
            'amount' => 5000000,
            'payment_method' => 'cash',
            'transaction_code' => 'PAY-02',
        ]);
        $this->approveByCode('PAY-02');

        $this->tuition->refresh();
        $this->assertEquals(9000000, $this->tuition->paid_amount);
        $this->assertEquals(0, $this->tuition->debt_amount);
        $this->assertEquals('paid', $this->tuition->status);
    }

    private function approveByCode(string $transactionCode): void
    {
        $receipt = TuitionReceipt::where('transaction_code', $transactionCode)->firstOrFail();
        $this->assertEquals('pending', $receipt->status);

        $this->actingAs($this->approverUser)
            ->post(route('tuition.receipts.approve.action', $receipt->id))
            ->assertSessionHasNoErrors();
    }

    public function test_can_approve_and_reject_pending_receipts(): void
    {
        // Create a pending receipt manually
        $pendingReceipt = TuitionReceipt::create([
            'receipt_number' => 'PT-PENDING-001',
            'student_tuition_id' => $this->tuition->id,
            'amount' => 3000000,
            'payment_method' => 'transfer',
            'transaction_code' => 'TR-PENDING',
            'payment_date' => now(),
            'creator_id' => $this->approverUser->id,
            'status' => 'pending',
        ]);

        // Pending receipt is not calculated in debt yet
        $this->tuition->recalculateDebt();
        $this->assertEquals(0, $this->tuition->paid_amount);

        // 1. Approve receipt
        $responseApprove = $this->actingAs($this->accountantUser)
            ->post(route('tuition.receipts.approve.action', $pendingReceipt->id));
        $responseApprove->assertRedirect();

        $pendingReceipt->refresh();
        $this->assertEquals('approved', $pendingReceipt->status);
        $this->assertEquals($this->accountantUser->id, $pendingReceipt->approver_id);

        $this->tuition->refresh();
        $this->assertEquals(3000000, $this->tuition->paid_amount);
        $this->assertEquals('partial', $this->tuition->status);

        // 2. Test reject another pending receipt
        $anotherPendingReceipt = TuitionReceipt::create([
            'receipt_number' => 'PT-PENDING-002',
            'student_tuition_id' => $this->tuition->id,
            'amount' => 2000000,
            'payment_method' => 'cash',
            'transaction_code' => 'TR-REJECT',
            'payment_date' => now(),
            'creator_id' => $this->accountantUser->id,
            'status' => 'pending',
        ]);

        $responseReject = $this->actingAs($this->accountantUser)
            ->post(route('tuition.receipts.reject.action', $anotherPendingReceipt->id));
        $responseReject->assertRedirect();

        $anotherPendingReceipt->refresh();
        $this->assertEquals('rejected', $anotherPendingReceipt->status);
    }

    // =========================================================================
    // c. Invoice cancellation request, approval, and rejection
    // =========================================================================

    public function test_invoice_cancellation_workflow(): void
    {
        $receipt = TuitionReceipt::create([
            'receipt_number' => 'PT-HDGTGT-001234',
            'invoice_number' => 'HDGTGT-001234',
            'student_tuition_id' => $this->tuition->id,
            'student_id' => $this->student->id,
            'amount' => 9000000,
            'payment_method' => 'transfer',
            'payment_date' => now(),
            'status' => 'approved',
        ]);
        $this->tuition->recalculateDebt();
        $this->assertEquals(0, (float) $this->tuition->debt_amount);

        // 1. Store cancellation request
        $responseStore = $this->actingAs($this->accountantUser)
            ->post(route('tuition.invoices.cancellations.store'), [
                'invoice_number' => 'HDGTGT-001234',
                'amount' => 9000000,
                'reason' => 'Xuất sai mã số thuế phụ huynh học viên',
            ]);

        $responseStore->assertRedirect();

        $this->assertDatabaseHas('invoice_cancellations', [
            'invoice_number' => 'HDGTGT-001234',
            'amount' => 9000000,
            'reason' => 'Xuất sai mã số thuế phụ huynh học viên',
            'requester_id' => $this->accountantUser->id,
            'status' => 'pending',
        ]);

        $cancellation = InvoiceCancellation::where('invoice_number', 'HDGTGT-001234')->first();
        $this->assertNotNull($cancellation);

        // 2. Approve cancellation
        $responseApprove = $this->actingAs($this->accountantUser)
            ->post(route('tuition.invoices.cancellations.approve', $cancellation->id));
        $responseApprove->assertRedirect();

        $cancellation->refresh();
        $this->assertEquals('approved', $cancellation->status);
        $this->assertEquals($this->accountantUser->id, $cancellation->approver_id);
        $this->assertEquals($receipt->id, $cancellation->tuition_receipt_id);
        $this->assertEquals('cancelled', $receipt->fresh()->status);
        $this->assertEquals(9000000, (float) $this->tuition->fresh()->debt_amount);

        // 3. Reject another cancellation
        $cancel2 = InvoiceCancellation::create([
            'invoice_number' => 'HDGTGT-005678',
            'amount' => 5000000,
            'reason' => 'Yêu cầu hủy không hợp lệ',
            'requester_id' => $this->accountantUser->id,
            'status' => 'pending',
        ]);

        $responseReject = $this->actingAs($this->accountantUser)
            ->post(route('tuition.invoices.cancellations.reject', $cancel2->id));
        $responseReject->assertRedirect();

        $cancel2->refresh();
        $this->assertEquals('rejected', $cancel2->status);
    }

    // =========================================================================
    // d. Refund / extension request, approval, and rejection
    // =========================================================================

    public function test_tuition_refund_and_extension_workflow(): void
    {
        TuitionReceipt::create([
            'receipt_number' => 'PT-REFUND-SEED',
            'student_tuition_id' => $this->tuition->id,
            'student_id' => $this->student->id,
            'amount' => 9000000,
            'payment_method' => 'transfer',
            'payment_date' => now(),
            'status' => 'approved',
        ]);
        $this->tuition->recalculateDebt();

        // 1. Create refund request
        $responseStore = $this->actingAs($this->accountantUser)
            ->post(route('tuition.refunds.store'), [
                'student_id' => $this->student->id,
                'type' => 'refund',
                'refund_amount' => 4500000,
                'reason' => 'Học viên đi du học sớm, xin hoàn 50% học phí',
            ]);

        $responseStore->assertRedirect();

        $this->assertDatabaseHas('tuition_refund_requests', [
            'student_id' => $this->student->id,
            'type' => 'refund',
            'refund_amount' => 4500000,
            'requester_id' => $this->accountantUser->id,
            'status' => 'pending',
        ]);

        $refund = TuitionRefundRequest::where('student_id', $this->student->id)->first();
        $this->assertNotNull($refund);

        // 2. Approve refund request
        $responseApprove = $this->actingAs($this->accountantUser)
            ->post(route('tuition.refunds.approve', $refund->id));
        $responseApprove->assertRedirect();

        $refund->refresh();
        $this->assertEquals('approved', $refund->status);
        $this->assertEquals($this->accountantUser->id, $refund->approver_id);

        // 3. Reject an extension request
        $extension = TuitionRefundRequest::create([
            'student_id' => $this->student->id,
            'type' => 'extension',
            'refund_amount' => 0,
            'reason' => 'Xin gia hạn nợ 30 ngày',
            'requester_id' => $this->accountantUser->id,
            'status' => 'pending',
        ]);

        $responseReject = $this->actingAs($this->accountantUser)
            ->post(route('tuition.refunds.reject', $extension->id));
        $responseReject->assertRedirect();

        $extension->refresh();
        $this->assertEquals('rejected', $extension->status);
    }

    public function test_refund_creation_fails_on_invalid_type(): void
    {
        $response = $this->actingAs($this->accountantUser)->post(route('tuition.refunds.store'), [
            'student_id' => $this->student->id,
            'type' => 'invalid_type',
            'refund_amount' => -100,
            'reason' => 'Lý do test',
        ]);

        $response->assertSessionHasErrors(['type', 'refund_amount']);
    }

    // =========================================================================
    // e. Overdue reminder trigger & Electronic Invoice Config
    // =========================================================================

    public function test_can_view_overdue_list_and_send_reminder(): void
    {
        // Set tuition to overdue
        $this->tuition->update([
            'status' => 'overdue',
            'due_date' => now()->subDays(5),
            'debt_amount' => 9000000,
        ]);

        $responseList = $this->actingAs($this->accountantUser)->get(route('tuition.overdue'));
        $responseList->assertOk();
        $responseList->assertSee('Trần Khánh Linh');

        $responseRemind = $this->actingAs($this->accountantUser)
            ->post(route('tuition.overdue.remind', $this->tuition->id));
        $responseRemind->assertRedirect();
        $responseRemind->assertSessionHas('status');
    }

    public function test_can_update_electronic_invoice_configuration(): void
    {
        $response = $this->actingAs($this->accountantUser)->post(route('tuition.config.update'), [
            'template_code' => '1/001',
            'series_code' => 'C26MEN',
            'current_number' => 1500,
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('invoice_configurations', [
            'template_code' => '1/001',
            'series_code' => 'C26MEN',
            'current_number' => 1500,
        ]);
    }

    public function test_tuition_split_and_transfer_workflow(): void
    {
        // Source student with initial paid tuition receipt
        TuitionReceipt::create([
            'receipt_number' => 'PT-TEST-001',
            'student_tuition_id' => $this->tuition->id,
            'amount' => 9000000,
            'payment_method' => 'transfer',
            'status' => 'approved',
        ]);
        $this->tuition->recalculateDebt();

        // Target student with debt
        $targetStudent = Student::create([
            'code' => 'HV-09999',
            'name' => 'Lê Hải Đăng',
            'phone' => '0988777666',
            'email' => 'haidang.le@gmail.com',
            'branch_id' => $this->branch->id,
            'current_class_id' => $this->classModel->id,
            'status' => 'studying',
        ]);

        $targetTuition = StudentTuition::create([
            'student_id' => $targetStudent->id,
            'class_id' => $this->classModel->id,
            'branch_id' => $this->branch->id,
            'total_amount' => 10000000,
            'discount_amount' => 0,
            'final_amount' => 10000000,
            'paid_amount' => 0,
            'debt_amount' => 10000000,
            'due_date' => now()->addDays(7),
            'status' => 'unpaid',
        ]);

        // 1. Submit transfer request (Xé lẻ 4.500.000đ từ học viên nguồn sang học viên đích)
        $responseTransfer = $this->actingAs($this->accountantUser)
            ->post(route('tuition.refunds.store'), [
                'student_id' => $this->student->id,
                'target_student_id' => $targetStudent->id,
                'type' => 'transfer',
                'total_paid' => 9000000,
                'attended_lessons' => 12,
                'refund_amount' => 4500000,
                'reason' => 'Xé lẻ 12 buổi học còn lại chuyển nhượng cho Lê Hải Đăng',
            ]);

        $responseTransfer->assertRedirect();
        $this->assertDatabaseHas('tuition_refund_requests', [
            'student_id' => $this->student->id,
            'target_student_id' => $targetStudent->id,
            'type' => 'transfer',
            'refund_amount' => 4500000,
            'status' => 'pending',
        ]);

        $transferReq = TuitionRefundRequest::where('target_student_id', $targetStudent->id)->first();
        $this->assertNotNull($transferReq);

        // 2. Approve transfer
        $responseApprove = $this->actingAs($this->accountantUser)
            ->post(route('tuition.refunds.approve', $transferReq->id));

        $responseApprove->assertRedirect();
        $transferReq->refresh();
        $this->assertEquals('approved', $transferReq->status);

        // 3. Verify debts were adjusted
        $this->tuition->refresh();
        $this->assertEquals(4500000, (float) $this->tuition->paid_amount);

        $targetTuition->refresh();
        $this->assertEquals(4500000, (float) $targetTuition->paid_amount);
        $this->assertEquals(5500000, (float) $targetTuition->debt_amount);
        $this->assertEquals('partial', $targetTuition->status);

        // 4. Verify transfer receipt was generated for target student
        $this->assertDatabaseHas('tuition_receipts', [
            'student_tuition_id' => $targetTuition->id,
            'amount' => 4500000,
            'payment_method' => 'transfer',
        ]);
    }

    public function test_fee_items_breakdown_and_multi_installment_workflow(): void
    {
        $crmUser = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $crmUser->assignRole('admin');
        $bank = BankAccount::create([
            'bank_code' => 'VCB', 'bank_name' => 'Vietcombank', 'account_number' => '123456789',
            'account_holder' => 'MENGLISH', 'branch_id' => $this->branch->id,
            'is_default_vietqr' => true, 'is_active' => true,
        ]);
        $promotion = Promotion::create([
            'code' => 'TUITION1M', 'name' => 'Giảm 1 triệu', 'type' => 'fixed', 'value' => 1000000, 'is_active' => true,
        ]);

        $customer = CrmCustomer::create([
            'code' => 'KH-MULTI01',
            'name' => 'Nguyễn Thị Bích Phương',
            'phone' => '0988 777 666',
            'branch_id' => $this->branch->id,
            'stage' => 'closing',
        ]);

        $feeItems = collect([
            ['code' => 'BOOK-CAM', 'name' => 'Bộ Giáo trình Cambridge Stage 3', 'amount' => 350000],
            ['code' => 'WORKBOOK', 'name' => 'Sách bài tập Workbook', 'amount' => 150000],
            ['code' => 'BALO', 'name' => 'Balo & Đồng phục MEnglish', 'amount' => 250000],
        ])->map(function (array $item): array {
            $merchandise = MerchandiseItem::create([
                'code' => $item['code'], 'name' => $item['name'], 'category' => 'other',
                'unit' => 'bộ', 'price' => $item['amount'], 'stock_quantity' => 10, 'is_active' => true,
            ]);

            return ['id' => $merchandise->id, 'name' => 'giá giả mạo', 'amount' => 1];
        })->all();

        // 1. Chốt deal qua closing wizard với 3 món sách vở (tổng 750k), thu đợt 1 là 5.000.000đ
        $responseWizard = $this->actingAs($crmUser)->post('/crm/closing-wizard', [
            'customer_id' => $customer->id,
            'class_id' => $this->classModel->id,
            'course_name' => 'IELTS Foundation',
            'base_tuition' => 10000000,
            'discount' => 1000000,
            'promotion_id' => $promotion->id,
            'fee_items' => json_encode($feeItems),
            'other_fees' => 750000,
            'prepaid_amount' => 0,
            'paid_amount' => 5000000, // Đợt 1
            'payment_method' => 'transfer',
            'bank_account_id' => $bank->id,
        ]);

        $responseWizard->assertRedirect(route('crm.customers.won'));

        // Kiểm tra hợp đồng học phí đã lưu đúng fee_items và other_fees
        $newStudent = Student::where('phone', '0988 777 666')->first();
        $this->assertNotNull($newStudent);
        $newTuition = StudentTuition::where('student_id', $newStudent->id)->first();
        $this->assertNotNull($newTuition);
        $this->assertEquals(750000, (float) $newTuition->other_fees);
        $this->assertCount(3, $newTuition->fee_items);
        $this->assertEquals('Bộ Giáo trình Cambridge Stage 3', $newTuition->fee_items[0]['name']);
        $this->assertEquals(9750000, (float) $newTuition->final_amount); // 10tr - 1tr + 750k
        $firstReceipt = TuitionReceipt::where('student_tuition_id', $newTuition->id)->firstOrFail();
        $this->assertEquals('pending', $firstReceipt->status);
        $this->actingAs($this->accountantUser)
            ->post(route('tuition.receipts.approve.action', $firstReceipt->id))
            ->assertRedirect();
        $newTuition->refresh();
        $this->assertEquals(5000000, (float) $newTuition->paid_amount);
        $this->assertEquals(4750000, (float) $newTuition->debt_amount);
        $this->assertEquals('partial', $newTuition->status);

        // Đợt 1: Phiếu thu đã tạo
        $this->assertDatabaseHas('tuition_receipts', [
            'student_tuition_id' => $newTuition->id,
            'amount' => 5000000,
        ]);

        // 2. Thu đợt 2: Phụ huynh nộp tiếp 4.750.000đ (hết nợ)
        $responseRound2 = $this->actingAs($this->accountantUser)->post(route('tuition.receipts.store'), [
            'student_tuition_id' => $newTuition->id,
            'amount' => 4750000,
            'payment_method' => 'cash',
            'notes' => 'Thu học phí đợt 2 hoàn tất (Bao gồm tiền sách vở)',
        ]);

        $responseRound2->assertRedirect();
        $responseRound2->assertSessionHasNoErrors();
        $secondReceipt = TuitionReceipt::where('student_tuition_id', $newTuition->id)
            ->where('id', '!=', $firstReceipt->id)->latest()->firstOrFail();
        $this->assertEquals('pending', $secondReceipt->status);
        $this->actingAs($this->approverUser)
            ->post(route('tuition.receipts.approve.action', $secondReceipt->id))
            ->assertRedirect();
        $newTuition->refresh();
        $this->assertEquals(9750000, (float) $newTuition->paid_amount);
        $this->assertEquals(0, (float) $newTuition->debt_amount);
        $this->assertEquals('paid', $newTuition->status);

        // 3. Xem trang Thông báo nộp học phí / Hóa đơn (/crm/tuition-bill/{id})
        $responseBill = $this->actingAs($this->accountantUser)->get(route('crm.tuition-bill', $newTuition->id));
        $responseBill->assertStatus(200);
        $responseBill->assertSee('Bộ Giáo trình Cambridge Stage 3');
        $responseBill->assertSee('Sách bài tập Workbook');
        $responseBill->assertSee('Balo &amp; Đồng phục MEnglish', false);
        $responseBill->assertSee('LỊCH SỬ THANH TOÁN CÁC ĐỢT');
        $responseBill->assertSee('Đợt 1');
        $responseBill->assertSee('Đợt 2');
        $responseBill->assertSee('ĐÃ HOÀN TẤT HỌC PHÍ');
    }
}
