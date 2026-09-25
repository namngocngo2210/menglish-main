<?php

namespace Tests\Feature;

use App\Models\AdminNotification;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\Course;
use App\Models\CrmCustomer;
use App\Models\SepayConfiguration;
use App\Models\SepayTransaction;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\TuitionReceipt;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SepayWebhookTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Student $student;

    private StudentTuition $tuition;

    protected function setUp(): void
    {
        parent::setUp();

        // Mặc định .env tắt webhook (SEPAY_WEBHOOK_ENABLED=false) nên test phải bật thủ công
        config(['services.sepay.webhook_enabled' => true]);

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        SepayConfiguration::create([
            'webhook_name' => 'Webhook test',
            'auth_method' => 'none',
            'is_active' => true,
        ]);

        $this->branch = Branch::create(['name' => 'CN SePay', 'code' => 'SP', 'is_active' => true]);

        // Phase 4: webhook chỉ gạch nợ khi tiền vào đúng tài khoản đã cấu hình.
        BankAccount::create([
            'bank_code' => 'MBB', 'bank_name' => 'MB Bank', 'account_number' => '0123 456 789',
            'account_holder' => 'TRUNG TAM MENGLISH', 'branch_id' => $this->branch->id, 'is_active' => true,
        ]);
        $course = Course::create(['code' => 'SP-COURSE', 'name' => 'Khóa SePay', 'is_active' => true]);
        ClassModel::create([
            'code' => 'SP-CLASS', 'name' => 'Lớp SePay', 'course_id' => $course->id,
            'branch_id' => $this->branch->id, 'status' => 'active',
        ]);

        $this->student = Student::create([
            'name' => 'Nguyễn Văn A',
            'code' => 'HV-SEPAY01',
            'phone' => '0912345678',
            'branch_id' => $this->branch->id,
            'status' => 'studying',
        ]);

        $this->tuition = StudentTuition::create([
            'student_id' => $this->student->id,
            'branch_id' => $this->branch->id,
            'total_amount' => 5000000,
            'final_amount' => 5000000,
            'paid_amount' => 0,
            'debt_amount' => 5000000,
            'status' => 'unpaid',
        ]);
    }

    private function postWebhook(array $overrides = [])
    {
        return $this->postJson(route('sepay.webhook.api'), array_merge([
            'id' => 'SP-'.random_int(1000, 999999),
            'accountNumber' => '0123456789',
            'transferType' => 'in',
            'transferAmount' => 3000000,
            'content' => 'HV-SEPAY01 NGUYEN VAN A thanh toan hoc phi',
            'transactionDate' => now()->toIso8601String(),
        ], $overrides));
    }

    public function test_caps_receipt_at_remaining_debt_and_flags_overpayment(): void
    {
        $response = $this->postWebhook(['transferAmount' => 6000000]);

        $response->assertOk()->assertJson(['success' => true]);

        $receipt = TuitionReceipt::where('student_tuition_id', $this->tuition->id)->firstOrFail();
        $this->assertSame(5000000.0, (float) $receipt->amount, 'Phiếu thu phải được kẹp bằng đúng số nợ còn lại');

        $this->tuition->refresh();
        $this->assertSame(5000000.0, (float) $this->tuition->paid_amount);
        $this->assertSame(0.0, (float) $this->tuition->debt_amount);

        $tx = SepayTransaction::where('status', 'matched')->firstOrFail();
        $this->assertStringContainsString('thừa', $tx->response_message);

        $this->assertTrue(
            AdminNotification::where('type', 'warning')->where('title', 'like', '%chuyển thừa%')->exists(),
            'Phải có cảnh báo cho phần tiền khách chuyển thừa'
        );
    }

    public function test_ignores_transfer_when_contract_already_paid(): void
    {
        $this->tuition->update(['paid_amount' => 5000000, 'debt_amount' => 0, 'status' => 'paid']);

        $response = $this->postWebhook(['transferAmount' => 2000000]);

        $response->assertOk();
        $this->assertSame(0, TuitionReceipt::count(), 'Không được lập phiếu thu khi hợp đồng đã hết nợ');

        $tx = SepayTransaction::where('status', 'overpaid')->firstOrFail();
        $this->assertStringContainsString('đã thanh toán đủ', $tx->response_message);
        $this->assertTrue(AdminNotification::where('type', 'warning')->exists());
    }

    public function test_duplicate_sepay_id_is_processed_only_once(): void
    {
        $payload = ['id' => 'SP-DUP-001', 'transferAmount' => 5000000];

        $this->postWebhook($payload)->assertOk();
        $this->postWebhook($payload)->assertOk();

        $this->assertSame(1, SepayTransaction::where('sepay_id', 'SP-DUP-001')->count());
        $this->assertSame(1, TuitionReceipt::count(), 'Giao dịch trùng không được tạo thêm phiếu thu');
        $this->assertSame(5000000.0, (float) $this->tuition->fresh()->paid_amount);
    }

    public function test_matches_crm_lead_code_with_ulid_and_normalized_phone(): void
    {
        $lead = CrmCustomer::create([
            'code' => 'KH-01J8Z9TESTLEAD',
            'name' => 'Lead SePay',
            'phone' => '0912 345 678',
            'phone_normalized' => '0912345678',
            'source' => 'Facebook Ads',
            'branch_id' => $this->branch->id,
            'stage' => 'waiting_class',
        ]);

        // Học viên lưu SĐT dạng +84 — hệ thống phải vẫn khớp với lead 0-prefixed
        $this->student->update(['phone' => '+84 912 345 678']);

        $response = $this->postWebhook([
            'transferAmount' => 3000000,
            'content' => 'KH-01J8Z9TESTLEAD chuyen tien hoc phi',
        ]);

        $response->assertOk();
        $this->assertSame(1, TuitionReceipt::count());
        $this->assertSame('waiting_class', $lead->fresh()->stage, 'Thanh toán không được tự chuyển giai đoạn Lead (Đã chốt chỉ qua gán lớp)');
    }

    public function test_transfer_to_unconfigured_account_is_not_reconciled(): void
    {
        $response = $this->postWebhook(['accountNumber' => '9999999999', 'transferAmount' => 3000000]);

        $response->assertOk()->assertJson(['success' => false]);
        $this->assertSame(0, TuitionReceipt::count(), 'Tiền vào tài khoản lạ không được gạch nợ');
        $this->assertSame('rejected_account', SepayTransaction::firstOrFail()->status);
        $this->assertSame(5000000.0, (float) $this->tuition->fresh()->debt_amount);

        // Thiếu số tài khoản cũng không được gạch nợ.
        $this->postWebhook(['accountNumber' => null, 'transferAmount' => 3000000])->assertJson(['success' => false]);
        $this->assertSame(0, TuitionReceipt::count());
    }

    public function test_sepay_does_not_double_count_manual_receipt_with_same_reference(): void
    {
        TuitionReceipt::create([
            'receipt_number' => 'PT-MANUAL-1', 'student_tuition_id' => $this->tuition->id, 'student_id' => $this->student->id,
            'amount' => 3000000, 'tuition_amount' => 3000000, 'payment_method' => 'transfer', 'transaction_code' => 'FT-SAME-01',
            'payment_date' => now(), 'status' => 'approved', 'invoice_number' => 'C26MEN-0000001',
        ]);
        $this->tuition->recalculateDebt();

        $this->postWebhook(['id' => 'SP-X1', 'referenceCode' => 'ft-same-01', 'transferAmount' => 3000000])->assertOk();

        $this->assertSame(1, TuitionReceipt::count());
        $this->assertSame('duplicate_manual', SepayTransaction::where('sepay_id', 'SP-X1')->value('status'));
        $this->assertSame(2000000.0, (float) $this->tuition->fresh()->debt_amount);
    }

    public function test_sepay_holds_transfer_matching_recent_manual_receipt_for_review(): void
    {
        TuitionReceipt::create([
            'receipt_number' => 'PT-MANUAL-2', 'student_tuition_id' => $this->tuition->id, 'student_id' => $this->student->id,
            'amount' => 3000000, 'tuition_amount' => 3000000, 'payment_method' => 'transfer',
            'payment_date' => now()->subDay(), 'status' => 'approved', 'invoice_number' => 'C26MEN-0000002',
        ]);
        $this->tuition->recalculateDebt();

        $this->postWebhook(['id' => 'SP-X2', 'transferAmount' => 3000000])->assertOk();

        $this->assertSame(1, TuitionReceipt::count(), 'Không tự tạo phiếu thứ 2 khi đã có phiếu tay cùng tiền, cùng ngày');
        $this->assertSame('needs_review', SepayTransaction::where('sepay_id', 'SP-X2')->value('status'));
    }

    public function test_webhook_returns_503_when_disabled_by_flag(): void
    {
        config(['services.sepay.webhook_enabled' => false]);

        $this->postWebhook(['transferAmount' => 1000000])
            ->assertStatus(503)
            ->assertJson(['success' => false]);

        // Không ghi nhận giao dịch hay phiếu thu nào khi đang tắt
        $this->assertSame(0, SepayTransaction::count());
        $this->assertSame(0, TuitionReceipt::count());

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin)->get(route('sepay.transactions.recent'))
            ->assertStatus(503);
    }
}
