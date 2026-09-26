<?php

namespace Tests\Feature;

use App\Models\AcademicRecord;
use App\Models\AdminNotification;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\ClassReport;
use App\Models\Course;
use App\Models\CrmCustomer;
use App\Models\InvoiceCancellation;
use App\Models\SepayTransaction;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\SupportTicket;
use App\Models\TuitionContactLog;
use App\Models\TuitionReceipt;
use App\Models\TuitionRefundRequest;
use App\Models\User;
use App\Models\WorkTask;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Nghiệm thu Phase 4 (docs/audit-report-and-roadmap.md — Phần C Phase 4, "Kết quả đạt được"):
 * lập phiếu thu → duyệt → xuất hóa đơn → công nợ về 0, qua HTTP bằng đúng vai trò; SePay tự đối soát không ghi nhận 2 lần;
 * hủy hóa đơn hoàn công nợ; chuyển nhượng / hoàn phí / khất nợ; danh sách quá hạn + nhắc nợ; phạm vi chi nhánh; nhật ký
 * trước / sau; ticket ghi chú nội bộ; bắt đổi mật khẩu lần đầu; báo cáo trực lớp.
 *
 * Luật hoàn phí mới (1 tuần / cùng tháng, chỉ Admin duyệt, bắt buộc ảnh bằng chứng, cờ "Quá hạn xử lý") và luật xác nhận
 * báo cáo trực lớp của người giao việc khi lớp chưa có GV chính đang được làm ở nhánh khác — chưa kiểm ở đây.
 */
class Phase4AcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private const SEPAY_SECRET = 'n4-sepay-test-secret';

    private Branch $branchA;

    private Branch $branchB;

    private User $admin;

    private User $managerA;

    private User $managerB;

    private User $academic;

    private User $accountant;

    private User $accountantB;

    private User $sale;

    private User $teacher;

    private User $assistant;

    private ClassModel $class;

    /** @var list<string> */
    private array $uploadedProofs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        config(['services.sepay.webhook_enabled' => true]);

        $this->branchA = Branch::create(['name' => 'Cơ sở Nghiệm thu A', 'code' => 'N4A', 'is_active' => true]);
        $this->branchB = Branch::create(['name' => 'Cơ sở Nghiệm thu B', 'code' => 'N4B', 'is_active' => true]);
        $this->admin = $this->userWithRole('admin');
        $this->managerA = $this->userWithRole('manager');
        $this->managerB = $this->userWithRole('manager', $this->branchB);
        $this->academic = $this->userWithRole('academic_staff', null, ['name' => 'CM Học vụ A']);
        $this->accountant = $this->userWithRole('accountant', null, ['name' => 'Kế toán A']);
        $this->accountantB = $this->userWithRole('accountant', $this->branchB);
        $this->sale = $this->userWithRole('sales_consultant');
        $this->teacher = $this->userWithRole('teacher', null, ['name' => 'GV Chính A']);
        $this->assistant = $this->userWithRole('assistant', null, ['name' => 'TA Trực A']);

        $course = Course::create(['code' => 'N4-FAM1', 'name' => 'Starters FAM 1 (N4)', 'tuition_fee' => 9000000, 'total_lessons' => 24, 'is_active' => true]);
        $this->class = ClassModel::create([
            'code' => 'N4A-FAM1', 'name' => 'Lớp N4A FAM 1', 'course_id' => $course->id, 'branch_id' => $this->branchA->id,
            'teacher_id' => $this->teacher->id, 'assistant_id' => $this->assistant->id, 'max_capacity' => 12, 'tuition_fee' => 9000000,
            'status' => 'active', 'start_date' => '2026-08-15', 'end_date' => '2026-12-31',
        ]);
    }

    protected function tearDown(): void
    {
        foreach (TuitionReceipt::whereNotNull('proof_image')->where('proof_image', 'like', '/uploads/%')->pluck('proof_image') as $path) {
            @unlink(public_path(ltrim($path, '/')));
        }
        $this->travelBack();
        parent::tearDown();
    }

    public function test_receipt_to_invoice_to_zero_debt_with_sepay_cancellation_refund_and_overdue(): void
    {
        // ── 0. Cấu hình (Kế toán / Admin): TK ngân hàng + dải số HĐ theo chi nhánh, SePay HMAC ──────────────
        $this->at('2026-09-01 08:00');
        $this->actingAs($this->sale)->post(route('tuition.config.ranges.store'), $this->range($this->branchA, 'N4A'))->assertForbidden();
        foreach ([[$this->branchA, 'N4A', '0901 000 111'], [$this->branchB, 'N4B', '1903 000 222']] as [$branch, $series, $number]) {
            $this->actingAs($this->accountant)->post(route('tuition.config.ranges.store'), $this->range($branch, $series))->assertSessionHasNoErrors();
            $this->actingAs($this->accountant)->post(route('system-config.bank-accounts.store'), [
                'bank_code' => 'MB', 'bank_name' => 'MB Bank', 'account_number' => $number, 'account_holder' => 'Menglish', 'branch_id' => $branch->id,
            ])->assertSessionHasNoErrors();
        }
        $this->actingAs($this->sale)->post(route('system-config.sepay.update'), $this->sepayConfig())->assertForbidden();
        $this->actingAs($this->admin)->post(route('system-config.sepay.update'), $this->sepayConfig())->assertSessionHasNoErrors();

        // ── 1. Sale chốt khách (chưa đóng phí) → hồ sơ học viên + học phí 9.000.000, hạn đóng +7 ngày, QR TK chi nhánh ──
        $s1 = $this->closeCustomer('0977400001', 'Khách Nghiệm Thu Một');
        $s2 = $this->closeCustomer('0977400002', 'Khách Nghiệm Thu Hai');
        $t1 = $this->tuition($s1);
        $this->assertSame([9000000.0, 9000000.0, '2026-09-08'], [(float) $t1->final_amount, (float) $t1->debt_amount, $t1->due_date->toDateString()]);
        $this->assertSame('0901 000 111', $t1->resolveBankAccount()?->account_number, 'QR theo tài khoản của chi nhánh.');
        $this->assertNotEmpty($t1->transfer_memo);

        // ── 2. Đợt 1: CM lập nháp → gửi duyệt phải có minh chứng → Kế toán trả về → CM sửa, gửi lại → Kế toán duyệt ──
        $this->at('2026-09-02 10:00');
        $this->actingAs($this->sale)->post(route('tuition.receipts.store'), $this->receiptInput($t1, 4000000, 'transfer', 'FT26N40001'))->assertForbidden();
        $this->actingAs($this->academic)->post(route('tuition.receipts.store'), $this->receiptInput($t1, 4000000, 'transfer', 'FT26N40001', 'draft'))
            ->assertSessionHasNoErrors();
        $r1 = TuitionReceipt::where('student_tuition_id', $t1->id)->sole();
        $this->assertSame([TuitionReceipt::STATUS_DRAFT, null], [$r1->status, $r1->invoice_number]);
        $this->actingAs($this->academic)->put(route('tuition.receipts.update', $r1->id), $this->receiptInput($t1, 4000000, 'transfer', 'FT26N40001'))
            ->assertSessionHasErrors('proof_image');
        $this->actingAs($this->academic)->put(route('tuition.receipts.update', $r1->id), $this->receiptInput($t1, 4000000, 'transfer', 'FT26N40001') + [
            'proof_image' => UploadedFile::fake()->image('uy-nhiem-chi.png', 40, 40),
        ])->assertSessionHasNoErrors();
        $this->assertSame(TuitionReceipt::STATUS_PENDING, $r1->fresh()->status);
        $this->assertNotNull($r1->fresh()->proof_image);
        $this->actingAs($this->accountant)->post(route('tuition.receipts.reject.action', $r1->id), ['rejection_reason' => 'Mã GD không khớp sao kê.'])
            ->assertSessionHasNoErrors();
        $this->assertSame(TuitionReceipt::STATUS_REJECTED, $r1->fresh()->status);
        $this->assertDatabaseHas('admin_notifications', ['user_id' => $this->academic->id, 'type' => 'receipt_rejected']);
        $this->actingAs($this->academic)->put(route('tuition.receipts.update', $r1->id), $this->receiptInput($t1, 4000000, 'transfer', 'FT26N40001B'))
            ->assertSessionHasNoErrors();
        $this->assertSame(TuitionReceipt::STATUS_PENDING, $r1->fresh()->status);
        $this->assertEquals(9000000, (float) $t1->fresh()->debt_amount, 'Phiếu chờ duyệt chưa trừ công nợ.');

        $this->actingAs($this->sale)->post(route('tuition.receipts.approve.action', $r1->id))->assertForbidden();
        $this->actingAs($this->academic)->post(route('tuition.receipts.approve.action', $r1->id))->assertForbidden();
        $this->actingAs($this->managerB)->post(route('tuition.receipts.approve.action', $r1->id))->assertForbidden(); // ngoài chi nhánh
        $this->at('2026-09-02 15:00');
        $this->actingAs($this->accountant)->post(route('tuition.receipts.approve.action', $r1->id))->assertSessionHasNoErrors();
        $r1->refresh();
        $this->assertSame([TuitionReceipt::STATUS_APPROVED, 'N4A-0000001', $this->accountant->id], [$r1->status, $r1->invoice_number, $r1->approver_id]);
        $t1->refresh();
        $this->assertSame([5000000.0, 4000000.0, 'partial'], [(float) $t1->debt_amount, (float) $t1->paid_amount, $t1->status]);

        // Nhật ký: duyệt phiếu ghi trước / sau.
        $log = Activity::where('subject_type', TuitionReceipt::class)->where('subject_id', $r1->id)->where('event', 'updated')->latest('id')->firstOrFail();
        $this->assertSame('pending', $log->properties['old']['status']);
        $this->assertSame('approved', $log->properties['attributes']['status']);
        $this->assertSame($this->accountant->id, $log->causer_id);
        $this->actingAs($this->admin)->get(route('activity-logs.index'))->assertOk()->assertSee('So sánh trước / sau');

        // ── 3. Đợt 2: Kế toán lập phiếu tiền mặt, không tự duyệt; Quản lý duyệt → HĐ kế tiếp của dải, công nợ về 0 ──
        $this->at('2026-09-05 09:00');
        $this->actingAs($this->accountant)->post(route('tuition.receipts.store'), $this->receiptInput($t1, 6000000, 'cash'))->assertSessionHasErrors('amount'); // vượt nợ
        $this->actingAs($this->accountant)->post(route('tuition.receipts.store'), $this->receiptInput($t1, 5000000, 'cash'))->assertSessionHasNoErrors();
        $r2 = TuitionReceipt::where('student_tuition_id', $t1->id)->where('status', 'pending')->sole();
        $this->actingAs($this->accountant)->post(route('tuition.receipts.approve.action', $r2->id))->assertSessionHasErrors('receipt');
        $this->actingAs($this->managerA)->post(route('tuition.receipts.approve.action', $r2->id))->assertSessionHasNoErrors();
        $this->assertSame('N4A-0000002', $r2->fresh()->invoice_number);
        $t1->refresh();
        $this->assertSame([0.0, 9000000.0, 'paid'], [(float) $t1->debt_amount, (float) $t1->paid_amount, $t1->status]);
        $this->actingAs($this->accountant)->get(route('tuition.history'))->assertOk()->assertSee('N4A-0000002');

        // ── 4. Hủy hóa đơn đợt 2 → công nợ khôi phục, số HĐ giữ nguyên (không cấp lại) ─────────────────────────
        $this->at('2026-09-06 09:00');
        $this->actingAs($this->accountant)->post(route('tuition.invoices.cancellations.store'), ['invoice_number' => 'N4A-0000002', 'amount' => 1000, 'reason' => 'Sai'])
            ->assertSessionHasErrors('amount');
        $this->actingAs($this->accountant)->post(route('tuition.invoices.cancellations.store'), [
            'invoice_number' => 'N4A-0000002', 'amount' => 5000000, 'reason' => 'PH đổi hình thức thanh toán sang chuyển khoản.',
        ])->assertSessionHasNoErrors();
        $cancellation = InvoiceCancellation::sole();
        $this->actingAs($this->managerB)->post(route('tuition.invoices.cancellations.approve', $cancellation->id))->assertForbidden();
        $this->actingAs($this->managerA)->post(route('tuition.invoices.cancellations.approve', $cancellation->id))->assertSessionHasNoErrors();
        $this->assertSame([TuitionReceipt::STATUS_CANCELLED, 'N4A-0000002'], [$r2->fresh()->status, $r2->fresh()->invoice_number]);
        $this->assertEquals(5000000, (float) $t1->fresh()->debt_amount);

        // ── 5. SePay: tài khoản lạ bị từ chối, sai chữ ký 401, đúng nội dung QR → gạch nợ 1 lần, gửi lại bị bỏ qua ──────
        $this->at('2026-09-06 20:00');
        $this->webhook(['id' => 'SP-N4-0009', 'accountNumber' => '0000999888', 'transferAmount' => 5000000, 'content' => $t1->transfer_memo])
            ->assertOk()->assertJson(['success' => false]);
        $this->assertSame('rejected_account', SepayTransaction::where('sepay_id', 'SP-N4-0009')->value('status'));
        $this->postJson(route('sepay.webhook.api'), ['id' => 'SP-N4-0001'], ['X-SePay-Signature' => 'sai'])->assertStatus(401);
        $this->assertEquals(5000000, (float) $t1->fresh()->debt_amount);

        $payload = ['id' => 'SP-N4-0001', 'accountNumber' => '0901000111', 'transferAmount' => 5000000, 'content' => $t1->transfer_memo.' CK dot 2'];
        $this->webhook($payload)->assertOk()->assertJson(['success' => true, 'invoice_number' => 'N4A-0000003']);
        $this->webhook($payload)->assertOk()->assertJsonFragment(['success' => true]);
        $this->assertSame(1, SepayTransaction::where('sepay_id', 'SP-N4-0001')->count());
        $this->assertSame(1, TuitionReceipt::where('transaction_code', 'SP-N4-0001')->count(), 'Webhook gửi lại không tạo phiếu thứ 2.');
        $t1->refresh();
        $this->assertSame([0.0, 'paid'], [(float) $t1->debt_amount, $t1->status]);
        // Phiếu tay cho đúng giao dịch SePay đã gạch nợ bị chặn.
        $this->actingAs($this->academic)->post(route('tuition.receipts.store'), $this->receiptInput($this->tuition($s2), 5000000, 'transfer', 'SP-N4-0001', 'submit', true))
            ->assertSessionHasErrors('transaction_code');

        // ── 6. Phiếu tay CK chờ duyệt, sau đó SePay cùng mã → không ghi 2 lần, Kế toán vẫn duyệt được phiếu tay ──────
        $t2 = $this->tuition($s2);
        $this->at('2026-09-07 10:00');
        $this->actingAs($this->academic)->post(route('tuition.receipts.store'), $this->receiptInput($t2, 3000000, 'transfer', 'FT26N40009', 'submit', true))
            ->assertSessionHasNoErrors();
        $manual = TuitionReceipt::where('student_tuition_id', $t2->id)->where('status', 'pending')->sole();
        $this->webhook(['id' => 'FT26N40009', 'accountNumber' => '0901000111', 'transferAmount' => 3000000, 'content' => $t2->transfer_memo])->assertOk();
        $tx = SepayTransaction::where('sepay_id', 'FT26N40009')->sole();
        $this->assertSame(['duplicate_manual', $manual->id], [$tx->status, $tx->matched_receipt_id]);
        $this->assertSame(1, TuitionReceipt::where('student_tuition_id', $t2->id)->count());
        $this->actingAs($this->accountant)->post(route('tuition.receipts.approve.action', $manual->id))->assertSessionHasNoErrors();
        $this->assertSame('approved', $manual->fresh()->status);
        $this->assertEquals(6000000, (float) $t2->fresh()->debt_amount);

        // Mã học viên dạng HV-00001 (bộ sinh mã dùng chung) trong nội dung CK được nhận ra.
        $this->actingAs($this->academic)->post(route('students.store'), ['name' => 'Học viên Mã Số', 'phone' => '0977400099', 'branch_id' => $this->branchA->id])
            ->assertSessionHasNoErrors();
        $numbered = Student::where('phone', '0977400099')->sole();
        $this->assertMatchesRegularExpression('/^HV-\d{5}$/', $numbered->code);
        $t3 = StudentTuition::create(['student_id' => $numbered->id, 'branch_id' => $this->branchA->id, 'total_amount' => 2000000, 'final_amount' => 2000000,
            'paid_amount' => 0, 'debt_amount' => 2000000, 'due_date' => '2026-09-30', 'status' => 'unpaid']);
        $this->webhook(['id' => 'SP-N4-0002', 'accountNumber' => '0901000111', 'transferAmount' => 2000000,
            'content' => str_replace('-', '', $numbered->code).' NOP HOC PHI'])->assertOk()->assertJson(['success' => true]);
        $this->assertEquals(0, (float) $t3->fresh()->debt_amount);

        // ── 7. Chuyển nhượng (S1 → S2) và hoàn phí (S1): phiếu âm / dương có số HĐ theo dải chi nhánh ──────────────
        $this->at('2026-09-08 09:00');
        $this->actingAs($this->sale)->post(route('tuition.refunds.store'), ['student_id' => $s1->id, 'type' => 'refund', 'refund_amount' => 1, 'reason' => 'x'])->assertForbidden();
        $this->actingAs($this->accountant)->post(route('tuition.refunds.store'), [
            'student_id' => $s1->id, 'type' => 'transfer', 'refund_amount' => 2000000, 'target_student_id' => $s2->id, 'reason' => 'Chuyển buổi dư sang em.',
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->accountant)->post(route('tuition.refunds.store'), [
            'student_id' => $s1->id, 'type' => 'refund', 'refund_amount' => 1000000, 'reason' => 'Chuyển nơi ở, hoàn phần chưa học.',
        ])->assertSessionHasNoErrors();
        foreach (TuitionRefundRequest::where('student_id', $s1->id)->orderBy('id')->get() as $request) {
            $this->actingAs($this->admin)->post(route('tuition.refunds.approve', $request->id), ['clawback_commission' => 0])->assertSessionHasNoErrors();
        }
        $t1->refresh();
        $this->assertSame([6000000.0, 6000000.0, 0.0], [(float) $t1->final_amount, (float) $t1->paid_amount, (float) $t1->debt_amount]);
        $this->assertEquals(4000000, (float) $t2->fresh()->debt_amount);
        $this->assertSame(0, TuitionReceipt::where('status', 'approved')->whereNull('invoice_number')->count());
        $this->assertSame(0, TuitionReceipt::where('status', 'approved')->where('invoice_number', 'not like', 'N4A-%')->count(), 'Mọi HĐ lấy từ dải chi nhánh A.');
        $this->assertSame(TuitionReceipt::where('invoice_number', 'like', 'N4A-%')->count(), TuitionReceipt::distinct()->count('invoice_number'));

        // ── 8. Quá hạn: S2 quá 12 ngày (≥ 7), S4 quá 3 ngày (1–6); liên hệ, báo Admin, nhắc nợ; khất nợ tạm dừng ─────
        $this->at('2026-09-10 10:00');
        $s4 = $this->closeCustomer('0977400004', 'Khách Nghiệm Thu Bốn');
        $t4 = $this->tuition($s4);
        $otherStudent = Student::create(['code' => 'HV-N4B-01', 'name' => 'Học viên Chi nhánh B', 'phone' => '0977400050', 'branch_id' => $this->branchB->id, 'status' => 'studying']);
        $tB = StudentTuition::create(['student_id' => $otherStudent->id, 'branch_id' => $this->branchB->id, 'total_amount' => 3000000, 'final_amount' => 3000000,
            'paid_amount' => 0, 'debt_amount' => 3000000, 'due_date' => '2026-09-01', 'status' => 'overdue']);

        $this->at('2026-09-20 08:30');
        $this->artisan('tuition:send-debt-reminders')->assertExitCode(0);
        $this->assertTrue(AcademicRecord::where('record_code', 'DEBTREMIND-T+3-'.$t4->id.'-2026-09-20')->exists(), 'Nhắc nợ tự động mốc T+3.');
        $this->at('2026-09-20 10:00');
        $this->actingAs($this->academic)->get(route('tuition.overdue'))->assertOk()
            ->assertViewHas('seriousOverdue', fn ($rows) => $rows->pluck('id')->all() === [$t2->id] && $rows->first()->days_overdue === 12)
            ->assertViewHas('newOverdue', fn ($rows) => $rows->pluck('id')->all() === [$t4->id] && $rows->first()->days_overdue === 3);
        $this->actingAs($this->academic)->post(route('tuition.overdue.contacted', $t2->id), ['note' => 'Gọi PH, hẹn cuối tuần.'])->assertSessionHasNoErrors();
        $this->actingAs($this->academic)->post(route('tuition.overdue.report-admin', $t2->id), ['note' => 'Quá hạn gần 2 tuần.'])->assertSessionHasNoErrors();
        $this->actingAs($this->academic)->post(route('tuition.overdue.remind', $t2->id))->assertSessionHasNoErrors();
        $this->assertSame(2, TuitionContactLog::where('student_tuition_id', $t2->id)->count());
        $this->assertDatabaseHas('admin_notifications', ['user_id' => $this->admin->id, 'type' => 'overdue_report']);
        $this->assertTrue(AcademicRecord::where('record_code', 'like', 'DEBTREMIND-%-'.$t2->id.'-2026-09-20')->exists());
        $this->actingAs($this->managerB)->post(route('tuition.overdue.contacted', $t2->id))->assertForbidden();

        $this->actingAs($this->accountant)->post(route('tuition.refunds.store'), [
            'student_id' => $s4->id, 'type' => 'extension', 'extended_due_date' => '2026-10-05', 'reason' => 'Khất đến kỳ lương.',
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->managerA)->post(route('tuition.refunds.approve', TuitionRefundRequest::where('student_id', $s4->id)->sole()->id))->assertSessionHasNoErrors();
        $this->actingAs($this->academic)->get(route('tuition.overdue'))->assertOk()
            ->assertViewHas('newOverdue', fn ($rows) => $rows->isEmpty())
            ->assertViewHas('paused', fn ($rows) => $rows->pluck('id')->contains($t4->id));

        // ── 9. Phạm vi: Quản lý / Kế toán chi nhánh B chỉ thấy chi nhánh mình; Sale không vào báo cáo tài chính ─────
        $this->actingAs($this->managerB)->get(route('tuition.overdue'))->assertOk()
            ->assertViewHas('overdueTuitions', fn ($rows) => $rows->pluck('id')->all() === [$tB->id]);
        $this->actingAs($this->managerB)->get(route('tuition.students'))->assertOk()
            ->assertViewHas('tuitions', fn ($page) => collect($page->items())->pluck('branch_id')->unique()->all() === [$this->branchB->id]);
        $this->actingAs($this->managerB)->get(route('finance.reports.revenue', ['branch_id' => $this->branchA->id]))->assertOk()
            ->assertViewHas('branchId', $this->branchB->id);
        $this->actingAs($this->accountantB)->get(route('finance.reports.revenue', ['branch_id' => 'all']))->assertOk()
            ->assertViewHas('branches', fn ($branches) => $branches->pluck('id')->all() === [$this->branchB->id]);
        $this->actingAs($this->admin)->get(route('finance.reports.revenue', ['month' => '2026-09']))->assertOk()
            ->assertViewHas('totalRevenue', fn ($total) => (float) $total > 0);
        foreach (['finance.reports.revenue', 'finance.expenses.index', 'tuition.students', 'tuition.overdue'] as $route) {
            $this->actingAs($this->sale)->get(route($route))->assertForbidden();
        }
    }

    public function test_ticket_internal_note_is_hidden_from_student_and_first_login_forces_password_change(): void
    {
        $studentUser = $this->userWithRole('student', null, ['name' => 'Học viên Hỏi Đáp']);
        $this->actingAs($studentUser)->post(route('tickets.store'), [
            'title' => 'Xin hóa đơn học phí', 'category' => 'tuition', 'priority' => 'medium', 'description' => 'PH cần HĐ điện tử.',
        ])->assertRedirect();
        $ticket = SupportTicket::sole();
        $this->assertMatchesRegularExpression('/^TK-\d{4}-\d{4}$/', $ticket->code);
        $this->assertDatabaseHas('admin_notifications', ['user_id' => $this->managerA->id, 'type' => 'ticket_new']);
        $this->assertDatabaseMissing('admin_notifications', ['user_id' => $this->managerB->id, 'type' => 'ticket_new']);

        $this->actingAs($studentUser)->post(route('tickets.messages.store', $ticket->id), ['message' => 'x', 'is_internal_note' => 1])->assertForbidden();
        $this->actingAs($this->managerA)->post(route('tickets.messages.store', $ticket->id), ['message' => 'NỘI BỘ: kiểm tra phiếu trước khi gửi.', 'is_internal_note' => 1])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->managerA)->post(route('tickets.messages.store', $ticket->id), ['message' => 'Đã gửi hóa đơn vào email phụ huynh.'])
            ->assertSessionHasNoErrors();
        $this->actingAs($studentUser)->get(route('tickets.show', $ticket->id))->assertOk()
            ->assertSee('Đã gửi hóa đơn vào email phụ huynh.')->assertDontSee('NỘI BỘ: kiểm tra phiếu');
        $this->actingAs($this->managerA)->get(route('tickets.show', $ticket->id))->assertOk()->assertSee('NỘI BỘ: kiểm tra phiếu');
        $this->actingAs($this->teacher)->get(route('tickets.show', $ticket->id))->assertForbidden();

        // Tài khoản mới do Admin tạo: lần đầu đăng nhập bị chuyển sang đổi mật khẩu cho tới khi đổi xong.
        $this->actingAs($this->admin)->post(route('users.store'), [
            'name' => 'Kế toán Mới', 'email' => 'ketoan.n4@menglish.edu.vn', 'branch_id' => $this->branchA->id, 'role' => 'accountant', 'password' => 'MatKhauTam123',
        ])->assertSessionHasNoErrors();
        $this->app['auth']->forgetGuards();
        $this->post(route('login'), ['email' => 'ketoan.n4@menglish.edu.vn', 'password' => 'MatKhauTam123'])->assertRedirect();
        $this->assertAuthenticated();
        $this->get(route('tuition.students'))->assertRedirect(route('profile.edit', ['force_password' => 1]));
        $this->get(route('dashboard'))->assertRedirect(route('profile.edit', ['force_password' => 1]));
        $this->put(route('password.update'), [
            'current_password' => 'MatKhauTam123', 'password' => 'MatKhauMoi@2026', 'password_confirmation' => 'MatKhauMoi@2026',
        ])->assertSessionHasNoErrors();
        $this->assertFalse(User::where('email', 'ketoan.n4@menglish.edu.vn')->value('must_change_password'));
        $this->get(route('tuition.students'))->assertOk();
    }

    public function test_class_duty_report_photo_completes_task_otherwise_main_teacher_confirms(): void
    {
        $this->actingAs($this->academic)->post(route('tasks.ta-assign.store'), [
            'assistant_id' => $this->assistant->id, 'assign_date' => now()->toDateString(), 'branch_id' => $this->branchA->id,
            'tasks' => [
                ['category' => 'before', 'content' => 'Mở phòng', 'attach_class' => '1', 'class_id' => $this->class->id],
                ['category' => 'during', 'content' => 'Hỗ trợ điểm danh', 'attach_class' => '1', 'class_id' => $this->class->id],
                ['category' => 'after', 'content' => 'Báo cáo trực lớp', 'attach_class' => '1', 'class_id' => $this->class->id],
            ],
        ])->assertSessionHasNoErrors();
        $shifts = WorkTask::where('assignee_id', $this->assistant->id)->get()->keyBy('time_slot_category');
        $this->assertSame(['after', 'before', 'during'], $shifts->keys()->sort()->values()->all());
        $this->assertDatabaseHas('admin_notifications', ['user_id' => $this->assistant->id, 'type' => 'task_assigned']);

        // Có ảnh bảng → báo cáo duyệt ngay, việc trực lớp hoàn thành.
        $this->actingAs($this->assistant)->post(route('tasks.class-reports.store'), [
            'class_id' => $this->class->id, 'session_name' => 'Buổi 6', 'hom_nay_hoc_gi' => 'Unit 3', 'task_id' => $shifts['after']->id,
            'board_image_url' => 'https://menglish.edu.vn/anh-bang.jpg',
        ])->assertSessionHasNoErrors();
        $withPhoto = ClassReport::where('session_name', 'Buổi 6')->sole();
        $this->assertSame('approved', $withPhoto->status);
        $this->assertSame('completed', $shifts['after']->fresh()->status);

        // Không ảnh → chờ GV chính của lớp xác nhận; TA không tự duyệt.
        $this->actingAs($this->assistant)->post(route('tasks.class-reports.store'), [
            'class_id' => $this->class->id, 'session_name' => 'Buổi 7', 'hom_nay_hoc_gi' => 'Unit 4', 'task_id' => $shifts['during']->id,
        ])->assertSessionHasNoErrors();
        $noPhoto = ClassReport::where('session_name', 'Buổi 7')->sole();
        $this->assertSame('pending_approval', $noPhoto->status);
        $this->assertSame('pending_confirmation', $shifts['during']->fresh()->status);
        $this->assertDatabaseHas('admin_notifications', ['user_id' => $this->teacher->id, 'type' => 'class_report_pending']);
        $this->actingAs($this->assistant)->post(route('tasks.class-reports.approve', $noPhoto->id))->assertForbidden();
        $this->actingAs($this->teacher)->post(route('tasks.class-reports.approve', $noPhoto->id))->assertSessionHasNoErrors();
        $this->assertSame(['approved', $this->teacher->id], [$noPhoto->fresh()->status, $noPhoto->fresh()->approved_by]);
        $this->assertSame('completed', $shifts['during']->fresh()->status);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function userWithRole(string $role, ?Branch $branch = null, array $attributes = []): User
    {
        $user = User::factory()->create($attributes + ['branch_id' => ($branch ?? $this->branchA)->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function at(string $moment): void
    {
        $this->travelTo(Carbon::parse($moment));
    }

    private function range(Branch $branch, string $series): array
    {
        return ['branch_id' => $branch->id, 'template_code' => '1/001', 'series_code' => $series, 'start_number' => 1, 'end_number' => 100];
    }

    private function sepayConfig(): array
    {
        return [
            'webhook_name' => 'SePay N4', 'webhook_url' => route('sepay.webhook.api'), 'transaction_type' => 'in', 'data_format' => 'json',
            'auth_method' => 'hmac_sha256', 'secret_key' => self::SEPAY_SECRET, 'is_active' => 1,
        ];
    }

    private function webhook(array $payload): TestResponse
    {
        $json = json_encode($payload + ['transferType' => 'in', 'gateway' => 'MB', 'transactionDate' => now()->format('Y-m-d H:i:s')]);

        return $this->call('POST', route('sepay.webhook.api'), [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SEPAY_SIGNATURE' => hash_hmac('sha256', $json, self::SEPAY_SECRET),
        ], $json);
    }

    /** Sale nhập khách → CM (Học vụ) chuyển "Đang tư vấn" → Sale Chốt & Xếp lớp, chưa đóng học phí. */
    private function closeCustomer(string $phone, string $name): Student
    {
        $this->actingAs($this->sale)->post(route('crm.customers.store'), [
            'name' => $name, 'phone' => $phone, 'parent_name' => 'PH '.$name, 'parent_phone' => '09884000'.substr($phone, -2),
            'source' => 'Bạn bè giới thiệu', 'branch_id' => $this->branchA->id, 'deal_value' => 9000000,
        ])->assertSessionHasNoErrors();
        $customer = CrmCustomer::where('phone_normalized', $phone)->firstOrFail();
        $this->actingAs($this->academic)->post(route('crm.customers.next-stage', $customer->id))->assertSessionHasNoErrors();
        $this->actingAs($this->sale)->post(route('crm.closing-wizard.store'), [
            'customer_id' => $customer->id, 'class_id' => $this->class->id, 'fee_paid_at_closing' => 0,
        ])->assertSessionHasNoErrors();

        return Student::findOrFail($customer->refresh()->converted_student_id);
    }

    private function tuition(Student $student): StudentTuition
    {
        return StudentTuition::where('student_id', $student->id)->sole();
    }

    private function receiptInput(StudentTuition $tuition, float $amount, string $method, ?string $code = null, string $action = 'submit', bool $withProof = false): array
    {
        return array_filter([
            'student_tuition_id' => $tuition->id, 'amount' => $amount, 'tuition_amount' => $amount, 'payment_method' => $method,
            'transaction_code' => $code, 'payer_name' => 'Phụ huynh', 'submit_action' => $action,
            'proof_image' => $withProof ? UploadedFile::fake()->image('ck.png', 40, 40) : null,
        ], fn ($v) => $v !== null);
    }
}
