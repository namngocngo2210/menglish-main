<?php

namespace Tests\Feature;

use App\Models\InvoiceCancellation;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\TuitionReceipt;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GrantsPersonalPermissions;
use Tests\TestCase;

class TuitionTest extends TestCase
{
    use RefreshDatabase;
    use GrantsPersonalPermissions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
    }

    /** Kế toán tổng (không gán chi nhánh): Admin cấp quyền mọi chi nhánh qua Phân quyền cá nhân (BA 26/09/2026). */
    private function accountant(): User
    {
        $user = User::factory()->create();
        $user->assignRole('accountant');

        return $this->grantHeadOffice($user);
    }

    public function test_can_view_tuition_students_list(): void
    {
        $user = $this->accountant();
        $student = Student::create([
            'code' => 'HV-90001',
            'name' => 'Nguyễn Thuỳ Trang',
            'phone' => '0988 123 789',
        ]);
        StudentTuition::create([
            'student_id' => $student->id,
            'total_amount' => 12500000,
            'final_amount' => 12500000,
            'paid_amount' => 5000000,
            'debt_amount' => 7500000,
            'status' => 'partial',
        ]);

        $response = $this->actingAs($user)->get('/tuition/students');
        $response->assertStatus(200);
        $response->assertSee('Nguyễn Thuỳ Trang');
        // Phase 4 (mockup): số tiền định dạng Việt Nam (dấu chấm).
        $response->assertSee('7.500.000đ');
    }

    public function test_can_create_receipt_and_recalculate_debt(): void
    {
        $user = $this->accountant();
        $student = Student::create([
            'code' => 'HV-90002',
            'name' => 'Lê Quốc Bảo',
            'phone' => '0912 888 777',
        ]);
        $tuition = StudentTuition::create([
            'student_id' => $student->id,
            'total_amount' => 10000000,
            'final_amount' => 10000000,
            'paid_amount' => 0,
            'debt_amount' => 10000000,
            'status' => 'unpaid',
        ]);

        $response = $this->actingAs($user)->post('/tuition/receipts', [
            'student_tuition_id' => $tuition->id,
            'amount' => 10000000,
            'payment_method' => 'transfer',
            'transaction_code' => 'FT26089999',
            // Phase 4: chuyển khoản / VietQR bắt buộc minh chứng khi gửi duyệt.
            'proof_image_preview' => '/uploads/tuition/receipts/test-proof.png',
            'notes' => 'Thanh toán 100% học phí',
        ]);

        $response->assertRedirect();
        $receipt = TuitionReceipt::where('student_tuition_id', $tuition->id)->firstOrFail();
        $this->assertEquals('pending', $receipt->status);
        $this->assertEquals(0, (float) $tuition->fresh()->paid_amount);

        // Kế toán khác duyệt -> mới ghi nhận công nợ
        $this->actingAs($this->accountant())
            ->post(route('tuition.receipts.approve.action', $receipt->id))
            ->assertSessionHasNoErrors();
        $this->assertEquals('approved', $receipt->fresh()->status);

        $tuition->refresh();
        $this->assertEquals(10000000, $tuition->paid_amount);
        $this->assertEquals(0, $tuition->debt_amount);
        $this->assertEquals('paid', $tuition->status);
    }

    public function test_can_create_receipt_with_only_surcharge_and_no_tuition(): void
    {
        $user = $this->accountant();
        $student = Student::create([
            'code' => 'HV-SURCHARGE',
            'name' => 'Nguyễn Phụ Thu',
            'phone' => '0987654321',
        ]);

        $response = $this->actingAs($user)->post('/tuition/receipts', [
            'student_id' => $student->id,
            'surcharge_amount' => 150000,
            'surcharge_reason' => 'Phụ thu giáo trình in ấn bổ sung & thẻ học viên',
            'amount' => 150000,
            'payment_method' => 'transfer',
            'submit_action' => 'submit',
            // Phase 4: chuyển khoản / VietQR bắt buộc minh chứng khi gửi duyệt.
            'proof_image_preview' => '/uploads/tuition/receipts/test-proof.png',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('tuition_receipts', [
            'student_id' => $student->id,
            'student_tuition_id' => null,
            'surcharge_amount' => 150000,
            'amount' => 150000,
            'status' => 'pending',
        ]);
    }

    public function test_can_save_draft_receipt(): void
    {
        $user = $this->accountant();
        $student = Student::create([
            'code' => 'HV-DRAFT',
            'name' => 'Lưu Nháp',
            'phone' => '0987111222',
        ]);

        $response = $this->actingAs($user)->post('/tuition/receipts', [
            'student_id' => $student->id,
            'surcharge_amount' => 50000,
            'surcharge_reason' => 'Lệ phí thẻ',
            'amount' => 50000,
            'payment_method' => 'cash',
            'submit_action' => 'draft',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('tuition_receipts', [
            'student_id' => $student->id,
            'amount' => 50000,
            'status' => 'draft',
        ]);
    }

    public function test_invoice_cancellation_reverses_student_debt(): void
    {
        $user = $this->accountant();
        $student = Student::create([
            'code' => 'HV-REVERT',
            'name' => 'Đỗ Hoàn Tác',
            'phone' => '0988777666',
        ]);
        $tuition = StudentTuition::create([
            'student_id' => $student->id,
            'total_amount' => 10000000,
            'final_amount' => 10000000,
            'paid_amount' => 10000000,
            'debt_amount' => 0,
            'status' => 'paid',
        ]);

        $receipt = TuitionReceipt::create([
            'receipt_number' => 'PT-REVERT-01',
            'invoice_number' => 'HD-REVERT-01',
            'student_tuition_id' => $tuition->id,
            'student_id' => $student->id,
            'amount' => 10000000,
            'payment_method' => 'cash',
            'status' => 'approved',
            'payment_date' => now(),
        ]);

        $cancellation = InvoiceCancellation::create([
            'invoice_number' => 'HD-REVERT-01',
            'tuition_receipt_id' => $receipt->id,
            'student_id' => $student->id,
            'amount' => 10000000,
            'reason' => 'Viết sai thông tin hóa đơn cần hoàn tác công nợ',
            'status' => 'pending',
            'requester_id' => $user->id,
        ]);

        // Phase 4 (mockup duyet-huy-hoa-don): chỉ Admin phê duyệt hủy hóa đơn.
        $this->actingAs($user)->post(route('tuition.invoices.cancellations.approve', $cancellation->id))->assertForbidden();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $responseApprove = $this->actingAs($admin)->post(route('tuition.invoices.cancellations.approve', $cancellation->id));
        $responseApprove->assertRedirect();

        $cancellation->refresh();
        $this->assertEquals('approved', $cancellation->status);
        $this->assertEquals('cancelled', $receipt->fresh()->status);
        $this->assertEquals('HD-REVERT-01', $receipt->fresh()->invoice_number);

        $tuition->refresh();
        $this->assertEquals(0, (float) $tuition->paid_amount);
        $this->assertEquals(10000000, (float) $tuition->debt_amount);
        $this->assertEquals('unpaid', $tuition->status);
    }

    public function test_can_view_receipts_approve_page_with_pending_list(): void
    {
        $user = $this->accountant();
        $student = Student::create([
            'code' => 'HV-TEST-APP',
            'name' => 'Nguyễn Văn Duyệt',
            'phone' => '0988222111',
        ]);
        $tuition = StudentTuition::create([
            'student_id' => $student->id,
            'total_amount' => 15000000,
            'final_amount' => 15000000,
            'paid_amount' => 0,
            'debt_amount' => 15000000,
            'status' => 'unpaid',
        ]);
        $receipt = TuitionReceipt::create([
            'receipt_number' => 'PT-TEST-001',
            'student_tuition_id' => $tuition->id,
            'student_id' => $student->id,
            'amount' => 5000000,
            'tuition_amount' => 5000000,
            'payment_method' => 'transfer',
            'transaction_code' => 'FT999888777',
            'payer_name' => 'Nguyễn Văn Duyệt',
            'status' => 'pending',
            'payment_date' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('tuition.receipts.approve'));
        $response->assertStatus(200);
        $response->assertSee('PT-TEST-001');
        $response->assertSee('Nguyễn Văn Duyệt');
        $response->assertSee('Duyệt phiếu thu học phí');
    }

    public function test_can_approve_receipt_action_and_recalculate_debt(): void
    {
        $user = $this->accountant();
        $student = Student::create([
            'code' => 'HV-TEST-APP2',
            'name' => 'Trần Thị Duyệt',
            'phone' => '0988333444',
        ]);
        $tuition = StudentTuition::create([
            'student_id' => $student->id,
            'total_amount' => 10000000,
            'final_amount' => 10000000,
            'paid_amount' => 0,
            'debt_amount' => 10000000,
            'status' => 'unpaid',
        ]);
        $receipt = TuitionReceipt::create([
            'receipt_number' => 'PT-TEST-002',
            'student_tuition_id' => $tuition->id,
            'student_id' => $student->id,
            'amount' => 4000000,
            'tuition_amount' => 4000000,
            'payment_method' => 'cash',
            'status' => 'pending',
            'payment_date' => now(),
        ]);

        $response = $this->actingAs($user)->post(route('tuition.receipts.approve.action', $receipt->id), [
            'note' => 'Duyệt thành công giao dịch đóng cọc',
        ]);

        $response->assertRedirect();
        $receipt->refresh();
        $this->assertEquals('approved', $receipt->status);
        $this->assertEquals($user->id, $receipt->approver_id);

        $tuition->refresh();
        $this->assertEquals(4000000, (float) $tuition->paid_amount);
        $this->assertEquals(6000000, (float) $tuition->debt_amount);
        $this->assertEquals('partial', $tuition->status);
    }

    public function test_can_reject_receipt_action(): void
    {
        $user = $this->accountant();
        $student = Student::create([
            'code' => 'HV-TEST-REJ',
            'name' => 'Lê Văn Từ Chối',
            'phone' => '0988555666',
        ]);
        $tuition = StudentTuition::create([
            'student_id' => $student->id,
            'total_amount' => 8000000,
            'final_amount' => 8000000,
            'paid_amount' => 0,
            'debt_amount' => 8000000,
            'status' => 'unpaid',
        ]);
        $receipt = TuitionReceipt::create([
            'receipt_number' => 'PT-TEST-003',
            'student_tuition_id' => $tuition->id,
            'student_id' => $student->id,
            'amount' => 2000000,
            'tuition_amount' => 2000000,
            'payment_method' => 'transfer',
            'status' => 'pending',
            'payment_date' => now(),
        ]);

        $response = $this->actingAs($user)->post(route('tuition.receipts.reject.action', $receipt->id), [
            'rejection_reason' => 'Chứng từ chuyển khoản mờ, sai số tài khoản thụ hưởng',
        ]);

        $response->assertRedirect();
        $receipt->refresh();
        $this->assertEquals('rejected', $receipt->status);
        $this->assertStringContainsString('sai số tài khoản thụ hưởng', $receipt->rejection_reason);

        $tuition->refresh();
        $this->assertEquals(0, (float) $tuition->paid_amount);
        $this->assertEquals(8000000, (float) $tuition->debt_amount);
    }
}
