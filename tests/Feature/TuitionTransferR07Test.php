<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\Course;
use App\Models\InvoiceConfiguration;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Models\StudentTuition;
use App\Models\TuitionReceipt;
use App\Models\TuitionRefundRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * TK-2026-0095/0096/0097 — chuyển nhượng học phí theo R-07 (HP-HT-04):
 * số tiền do server tính, người nhận phải còn nợ đủ, duyệt KHÔNG lập phiếu thu / không cấp số hóa đơn.
 */
class TuitionTransferR07Test extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $admin;

    private User $accountant;

    private Student $source;

    private StudentTuition $sourceTuition;

    private Student $target;

    private StudentTuition $targetTuition;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PermissionSeeder::class);
        $this->seed(\Database\Seeders\RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'CN R07', 'code' => 'R07', 'is_active' => true]);
        $this->admin = $this->user('admin');
        $this->accountant = $this->user('accountant');
        InvoiceConfiguration::create(['branch_id' => null, 'template_code' => '1/001', 'series_code' => 'C26MBD', 'start_number' => 1, 'current_number' => 500, 'is_active' => true]);

        $course = Course::create(['code' => 'R07-C', 'name' => 'R07', 'tuition_fee' => 6000000, 'total_lessons' => 10, 'is_active' => true]);
        $class = ClassModel::create(['code' => 'R07-A', 'name' => 'Lớp R07', 'course_id' => $course->id, 'branch_id' => $this->branch->id, 'max_capacity' => 10, 'tuition_fee' => 6000000, 'status' => 'active']);

        // Nguồn: hợp đồng 10 buổi 6.000.000, đã thu 5.000.000, đã học 4 buổi → chuyển 6 buổi = 5.000.000 / 10 × 6 = 3.000.000.
        $this->source = $this->student('HV-R07-1', $class);
        $this->sourceTuition = $this->tuition($this->source, 6000000, $class, 5000000);
        for ($i = 1; $i <= 4; $i++) {
            StudentAttendance::create(['class_id' => $class->id, 'student_id' => $this->source->id, 'session_date' => '2026-09-0'.$i, 'status' => 'present']);
        }
        $this->target = $this->student('HV-R07-2', $class);
        $this->targetTuition = $this->tuition($this->target, 6000000, $class, 1000000); // còn nợ 5.000.000
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function student(string $code, ClassModel $class): Student
    {
        return Student::create(['code' => $code, 'name' => 'Học viên '.$code, 'phone' => '09'.random_int(10000000, 99999999),
            'branch_id' => $this->branch->id, 'status' => 'studying', 'current_class_id' => $class->id]);
    }

    private function tuition(Student $student, float $final, ClassModel $class, float $paid): StudentTuition
    {
        $tuition = StudentTuition::create(['student_id' => $student->id, 'branch_id' => $this->branch->id, 'class_id' => $class->id, 'session_count' => 10,
            'total_amount' => $final, 'final_amount' => $final, 'paid_amount' => 0, 'debt_amount' => $final, 'due_date' => now()->addDays(10), 'status' => 'unpaid']);
        TuitionReceipt::create(['receipt_number' => TuitionReceipt::generateReceiptNumber(), 'student_tuition_id' => $tuition->id, 'student_id' => $student->id,
            'amount' => $paid, 'tuition_amount' => $paid, 'payment_method' => 'cash', 'payment_date' => now(), 'creator_id' => $this->accountant->id,
            'approver_id' => $this->admin->id, 'approved_at' => now(), 'status' => 'approved', 'invoice_number' => 'OLD-'.$student->id]);
        $tuition->recalculateDebt();

        return $tuition->fresh();
    }

    private function createTransfer(array $extra = [], ?Student $target = null)
    {
        return $this->actingAs($this->accountant)->post(route('tuition.refunds.store'), $extra + [
            'student_id' => $this->source->id, 'type' => 'transfer', 'target_student_id' => ($target ?? $this->target)->id, 'reason' => 'Chuyển cho em',
        ]);
    }

    public function test_create_ignores_client_amount_and_recomputes_by_formula(): void
    {
        $this->createTransfer(['refund_amount' => 99999999])->assertSessionHasNoErrors();

        $request = TuitionRefundRequest::where('type', 'transfer')->sole();
        $this->assertEquals(3000000, (float) $request->refund_amount);
    }

    public function test_create_works_without_any_client_amount(): void
    {
        $this->createTransfer()->assertSessionHasNoErrors();
        $this->assertEquals(3000000, (float) TuitionRefundRequest::where('type', 'transfer')->sole()->refund_amount);
    }

    public function test_create_is_rejected_when_receiver_debt_is_below_amount(): void
    {
        $poor = $this->student('HV-R07-3', ClassModel::first());
        $this->tuition($poor, 6000000, ClassModel::first(), 4000000); // còn nợ 2.000.000 < 3.000.000

        $message = 'Số tiền chuyển nhượng vượt quá công nợ còn lại của học viên nhận (2.000.000 đ). Vui lòng chọn học viên nhận khác.';
        $this->createTransfer([], $poor)->assertSessionHasErrors(['target_student_id' => $message]);
        $this->assertSame(0, TuitionRefundRequest::count());

        // Cùng thông báo qua API JSON: 422.
        $this->actingAs($this->accountant)->postJson(route('tuition.refunds.store'), [
            'student_id' => $this->source->id, 'type' => 'transfer', 'target_student_id' => $poor->id, 'reason' => 'x',
        ])->assertStatus(422)->assertJsonValidationErrors(['target_student_id' => $message]);
    }

    public function test_approve_creates_no_receipt_no_invoice_number_and_keeps_totals_consistent(): void
    {
        $this->createTransfer()->assertSessionHasNoErrors();
        $request = TuitionRefundRequest::where('type', 'transfer')->sole();
        $receiptsBefore = TuitionReceipt::count();
        $invoiceBefore = InvoiceConfiguration::whereNull('branch_id')->value('current_number');
        $paidSumBefore = (float) $this->sourceTuition->paid_amount + (float) $this->targetTuition->paid_amount;

        $this->actingAs($this->admin)->post(route('tuition.refunds.approve', $request->id))->assertSessionHasNoErrors();

        $this->assertSame('approved', $request->fresh()->status);
        // Không phiếu thu mới, không số hóa đơn bị tiêu thụ, không phiếu XFER-*.
        $this->assertSame($receiptsBefore, TuitionReceipt::count());
        $this->assertSame(0, TuitionReceipt::where('transaction_code', 'like', 'XFER-%')->count());
        $this->assertSame($invoiceBefore, InvoiceConfiguration::whereNull('branch_id')->value('current_number'));

        $source = $this->sourceTuition->fresh();
        $target = $this->targetTuition->fresh();
        // Nguồn: -6 buổi, -3.000.000 hợp đồng; đã thu 5M → 2M, công nợ không đổi.
        $this->assertSame(4, (int) $source->session_count);
        $this->assertSame(16, (int) $target->session_count, 'Bên nhận được cộng đúng 6 buổi (TK-2026-0100).');
        $this->assertEquals(3000000, (float) $source->final_amount);
        $this->assertEquals(2000000, (float) $source->paid_amount);
        $this->assertEquals(1000000, (float) $source->debt_amount);
        // Nhận: được ghi có 3.000.000 → công nợ 5M → 2M.
        $this->assertEquals(4000000, (float) $target->paid_amount);
        $this->assertEquals(2000000, (float) $target->debt_amount);
        $this->assertEquals($paidSumBefore, (float) $source->paid_amount + (float) $target->paid_amount, 'Tổng đã thu của hai bên không đổi.');

        // Tính lại công nợ (vd khi duyệt phiếu thu khác) không làm mất điều chỉnh.
        $source->recalculateDebt();
        $target->recalculateDebt();
        $this->assertEquals(2000000, (float) $source->paid_amount);
        $this->assertEquals(4000000, (float) $target->paid_amount);

        $log = Activity::where('log_name', 'tuition_transfer')->sole();
        $this->assertEquals(3000000, $log->properties['amount']);
        $this->assertSame(6, $log->properties['sessions']);
    }

    public function test_approval_logs_overdue_transfer(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-15 12:00:00'));
        $this->createTransfer()->assertSessionHasNoErrors();
        $request = TuitionRefundRequest::where('type', 'transfer')->sole();
        TuitionRefundRequest::whereKey($request->id)->update(['created_at' => now()->subDays(10)]);

        $this->actingAs($this->admin)->post(route('tuition.refunds.approve', $request->id))->assertSessionHasNoErrors();

        $this->assertSame('approved', $request->fresh()->status);
        $log = Activity::where('log_name', 'tuition_transfer')->sole();
        $this->assertSame(true, $log->properties['duyet_tre_han']);
        $this->assertEquals(3000000, $log->properties['amount']);
    }

    public function test_approve_recomputes_amount_and_blocks_when_receiver_debt_shrank_after_creation(): void
    {
        $this->createTransfer()->assertSessionHasNoErrors();
        $request = TuitionRefundRequest::where('type', 'transfer')->sole();
        // Người nhận đóng thêm 3.000.000 → còn nợ 2.000.000 < 3.000.000; hồ sơ bị sửa số tiền cũng không được tin.
        TuitionReceipt::create(['receipt_number' => TuitionReceipt::generateReceiptNumber(), 'student_tuition_id' => $this->targetTuition->id, 'student_id' => $this->target->id,
            'amount' => 3000000, 'tuition_amount' => 3000000, 'payment_method' => 'cash', 'payment_date' => now(), 'creator_id' => $this->accountant->id,
            'approver_id' => $this->admin->id, 'approved_at' => now(), 'status' => 'approved']);
        $request->update(['refund_amount' => 1]);

        $receiptsBefore = TuitionReceipt::count();
        $this->actingAs($this->admin)->post(route('tuition.refunds.approve', $request->id))->assertSessionHasErrors('target_student_id');

        $this->assertSame('pending', $request->fresh()->status);
        $this->assertSame($receiptsBefore, TuitionReceipt::count());
        $this->assertSame(10, (int) $this->sourceTuition->fresh()->session_count);
        $this->assertEquals(5000000, (float) $this->sourceTuition->fresh()->paid_amount);
    }

    public function test_transfer_of_36_session_contract_moves_33_sessions_to_receiver(): void
    {
        // TK-2026-0100: HV 41 (36 buổi, đã học 3) → HV 39: nguồn 36 → 3, nhận 36 → 69.
        Course::query()->update(['total_lessons' => 36]);
        $this->sourceTuition->update(['session_count' => 36]);
        $this->targetTuition->update(['session_count' => 36]);
        StudentAttendance::where('student_id', $this->source->id)->whereDate('session_date', '2026-09-04')->delete();

        $this->createTransfer()->assertSessionHasNoErrors();
        $request = TuitionRefundRequest::where('type', 'transfer')->sole();
        $this->actingAs($this->admin)->post(route('tuition.refunds.approve', $request->id))->assertSessionHasNoErrors();

        $this->assertSame(33, (int) $request->fresh()->transferred_sessions);
        $this->assertSame(3, (int) $this->sourceTuition->fresh()->session_count);
        $this->assertSame(69, (int) $this->targetTuition->fresh()->session_count);
        $this->assertEquals(round(5000000 / 36 * 33, 2), (float) $request->fresh()->refund_amount);
        // Màn hoàn phí của nguồn (cùng nguồn dữ liệu): tổng 3, đã học 3, không còn buổi dư để chuyển tiếp.
        $source = $this->sourceTuition->fresh();
        $this->assertSame(3, $source->sessionStats()['total']);
        $this->assertSame(3, $source->sessionStats()['attended']);
        $this->assertSame(0, $source->sessionStats()['remaining']);
        $this->assertSame(0, $source->transferValue()['sessions']);
    }

    public function test_double_approve_does_not_move_sessions_twice(): void
    {
        $this->createTransfer()->assertSessionHasNoErrors();
        $request = TuitionRefundRequest::where('type', 'transfer')->sole();

        $this->actingAs($this->admin)->post(route('tuition.refunds.approve', $request->id))->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('tuition.refunds.approve', $request->id));

        $this->assertSame(4, (int) $this->sourceTuition->fresh()->session_count);
        $this->assertSame(16, (int) $this->targetTuition->fresh()->session_count);
        $this->assertEquals(2000000, (float) $this->sourceTuition->fresh()->paid_amount);
        $this->assertEquals(4000000, (float) $this->targetTuition->fresh()->paid_amount);
    }

    public function test_failure_while_saving_receiver_rolls_back_whole_transfer(): void
    {
        $this->createTransfer()->assertSessionHasNoErrors();
        $request = TuitionRefundRequest::where('type', 'transfer')->sole();
        $targetId = $this->targetTuition->id;
        StudentTuition::saving(function (StudentTuition $t) use ($targetId) {
            if ($t->id === $targetId && $t->isDirty('session_count')) {
                throw new \RuntimeException('boom');
            }
        });

        try {
            $this->actingAs($this->admin)->withoutExceptionHandling()->post(route('tuition.refunds.approve', $request->id));
            $this->fail('Expected failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame('pending', $request->fresh()->status);
        $this->assertSame(10, (int) $this->sourceTuition->fresh()->session_count);
        $this->assertSame(10, (int) $this->targetTuition->fresh()->session_count);
        $this->assertEquals(5000000, (float) $this->sourceTuition->fresh()->paid_amount);
    }

    public function test_approve_uses_formula_not_stored_amount(): void
    {
        $this->createTransfer()->assertSessionHasNoErrors();
        $request = TuitionRefundRequest::where('type', 'transfer')->sole();
        $request->update(['refund_amount' => 100000]);

        $this->actingAs($this->admin)->post(route('tuition.refunds.approve', $request->id))->assertSessionHasNoErrors();

        $this->assertEquals(3000000, (float) $request->fresh()->refund_amount);
        $this->assertEquals(2000000, (float) $this->sourceTuition->fresh()->paid_amount);
    }

    public function test_legacy_approved_transfer_with_receipts_is_not_double_counted(): void
    {
        // Hồ sơ cũ (balance_adjusted = false) đã có phiếu XFER-*: recalculateDebt chỉ tính phiếu, không cộng thêm.
        TuitionRefundRequest::create(['student_id' => $this->source->id, 'target_student_id' => $this->target->id, 'type' => 'transfer', 'refund_amount' => 1000000,
            'reason' => 'cũ', 'requester_id' => $this->accountant->id, 'status' => 'approved']);

        $this->sourceTuition->recalculateDebt();
        $this->assertEquals(5000000, (float) $this->sourceTuition->fresh()->paid_amount);
    }

    public function test_refund_screen_exposes_server_computed_transfer_amount(): void
    {
        $this->actingAs($this->accountant)->get(route('tuition.refunds'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('studentFinance.'.$this->source->id.'.transfer_amount', 3000000)
                ->where('studentFinance.'.$this->source->id.'.transfer_sessions', 6)
                ->where('studentFinance.'.$this->target->id.'.debt', 5000000));
    }
}
