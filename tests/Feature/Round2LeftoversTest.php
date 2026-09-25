<?php

namespace Tests\Feature;

use App\Models\AdminNotification;
use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\CommissionAdjustment;
use App\Models\CommissionTier;
use App\Models\Course;
use App\Models\CrmCustomer;
use App\Models\InvoiceCancellation;
use App\Models\InvoiceConfiguration;
use App\Models\PayrollPeriod;
use App\Models\Penalty;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Models\StudentTuition;
use App\Models\TuitionReceipt;
use App\Models\TuitionRefundRequest;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Vòng 2 — các việc còn tồn của vòng 1 (docs/audit-report-and-roadmap.md, Phần D):
 * mã biên bản, số HĐ phiếu hoàn/chuyển nhượng, kết thúc bảo lưu, đồng bộ buổi học bù,
 * ngưỡng khai giảng, thu hồi hoa hồng khi hủy HĐ sau kỳ lương đã duyệt, phạm vi chi nhánh màn Học phí.
 */
class Round2LeftoversTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Branch $branch2;

    private User $admin;

    private User $accountant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-05 08:00:00'));
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'CN Cầu Giấy R2', 'code' => 'CG-R2', 'is_active' => true]);
        $this->branch2 = Branch::create(['name' => 'CN Đống Đa R2', 'code' => 'DD-R2', 'is_active' => true]);
        $this->admin = $this->makeUser('admin');
        $this->accountant = $this->makeUser('accountant');
    }

    private function makeUser(string $role, ?Branch $branch = null, bool $withBranch = true): User
    {
        $user = User::factory()->create([
            'branch_id' => $withBranch ? ($branch ?? $this->branch)->id : null,
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function makeStudent(string $code, ?Branch $branch = null, array $extra = []): Student
    {
        return Student::create($extra + [
            'code' => $code, 'name' => "Học viên {$code}", 'phone' => '09'.random_int(10000000, 99999999),
            'branch_id' => ($branch ?? $this->branch)->id, 'status' => 'studying',
        ]);
    }

    private function makeTuition(Student $student, float $final = 6000000, array $extra = []): StudentTuition
    {
        return StudentTuition::create($extra + [
            'student_id' => $student->id, 'branch_id' => $student->branch_id,
            'total_amount' => $final, 'final_amount' => $final, 'paid_amount' => 0, 'debt_amount' => $final,
            'due_date' => now()->subDays(3)->toDateString(), 'status' => 'unpaid',
        ]);
    }

    private function approvedReceipt(StudentTuition $tuition, float $amount, array $extra = []): TuitionReceipt
    {
        $receipt = TuitionReceipt::create($extra + [
            'receipt_number' => TuitionReceipt::generateReceiptNumber(),
            'student_tuition_id' => $tuition->id, 'student_id' => $tuition->student_id,
            'amount' => $amount, 'tuition_amount' => $amount, 'payment_method' => 'cash',
            'payment_date' => now(), 'creator_id' => $this->accountant->id, 'status' => 'pending',
        ]);
        $receipt->update(['status' => 'approved', 'approver_id' => $this->admin->id]);
        $tuition->recalculateDebt();

        return $receipt;
    }

    private function makeClass(string $code, array $overrides = []): ClassModel
    {
        return ClassModel::create($overrides + [
            'code' => $code, 'name' => "Lớp {$code}", 'program' => 'IELTS', 'level' => 'B1',
            'branch_id' => $this->branch->id, 'room' => 'P101', 'status' => 'active', 'max_capacity' => 10,
            'start_date' => '2026-09-01', 'end_date' => '2026-12-31',
        ]);
    }

    // ── 1. Mã biên bản dùng bộ sinh mã chung ────────────────────────────

    public function test_penalty_code_continues_from_existing_max_through_shared_generator(): void
    {
        $user = $this->makeUser('teacher');
        $base = ['user_id' => $user->id, 'violation_type' => 'Đi muộn', 'error_category' => 'operations', 'violation_date' => '2026-10-01', 'status' => 'pending'];
        Penalty::create($base + ['code' => 'BB-2026-007']);
        Penalty::create($base + ['code' => 'BB-2025-099']);

        $first = Penalty::generateCode();
        $this->assertSame('BB-2026-008', $first);
        Penalty::create($base + ['code' => $first]);
        $this->assertSame('BB-2026-009', Penalty::generateCode());
        $this->assertDatabaseHas('document_sequences', ['key' => 'penalty', 'period' => '2026', 'last_value' => 9]);

        $this->travelTo(Carbon::parse('2027-01-02 08:00:00'));
        $this->assertSame('BB-2027-001', Penalty::generateCode());
    }

    // ── 2. Số HĐ phiếu hoàn / chuyển nhượng theo dải chi nhánh ──────────

    public function test_refund_and_transfer_receipts_use_branch_invoice_range_with_default_fallback(): void
    {
        InvoiceConfiguration::create(['branch_id' => null, 'template_code' => '1/001', 'series_code' => 'C26MEN', 'start_number' => 1, 'current_number' => 1001, 'is_active' => true]);
        InvoiceConfiguration::create(['branch_id' => $this->branch->id, 'template_code' => '1/001', 'series_code' => 'C26CG', 'start_number' => 1, 'end_number' => 500, 'current_number' => 1, 'is_active' => true]);

        $source = $this->makeStudent('HV-R2-S1');
        $sourceTuition = $this->makeTuition($source);
        $this->approvedReceipt($sourceTuition, 3000000, ['invoice_number' => 'C26CG-0000999']);

        TuitionRefundRequest::create([
            'student_id' => $source->id, 'type' => 'refund', 'total_paid' => 3000000, 'refund_amount' => 1000000,
            'reason' => 'Chuyển nhà', 'requester_id' => $this->accountant->id, 'status' => 'pending',
        ]);
        $refund = TuitionRefundRequest::latest('id')->first();
        $this->actingAs($this->admin)->post(route('tuition.refunds.approve', $refund->id), ['clawback_commission' => 0])
            ->assertSessionHasNoErrors();

        $refundReceipt = TuitionReceipt::where('transaction_code', 'REFUND-'.$refund->id)->firstOrFail();
        $this->assertSame('C26CG-0000001', $refundReceipt->invoice_number);

        // Học viên nhận ở chi nhánh chưa có dải riêng → dải mặc định.
        $target = $this->makeStudent('HV-R2-T1', $this->branch2);
        $this->makeTuition($target, 5000000);
        $transfer = TuitionRefundRequest::create([
            'student_id' => $source->id, 'target_student_id' => $target->id, 'type' => 'transfer', 'total_paid' => 2000000,
            'refund_amount' => 500000, 'reason' => 'Chuyển cho em', 'requester_id' => $this->accountant->id, 'status' => 'pending',
        ]);
        $this->actingAs($this->admin)->post(route('tuition.refunds.approve', $transfer->id))->assertSessionHasNoErrors();

        $this->assertSame('C26CG-0000002', TuitionReceipt::where('transaction_code', 'XFER-OUT-'.$transfer->id)->value('invoice_number'));
        $this->assertSame('C26MEN-0001001', TuitionReceipt::where('transaction_code', 'XFER-IN-'.$transfer->id)->value('invoice_number'));
    }

    // ── 3. Kết thúc bảo lưu ─────────────────────────────────────────────

    public function test_daily_command_ends_expired_deferrals_idempotently_and_notifies_academic_staff(): void
    {
        $academic = $this->makeUser('academic_staff');
        $otherBranchAcademic = $this->makeUser('academic_staff', $this->branch2);
        $started = $this->makeClass('R2-STARTED');
        $upcoming = $this->makeClass('R2-UPCOMING', ['start_date' => '2026-11-01', 'status' => 'pending_schedule']);

        $a = $this->makeStudent('HV-R2-D1', null, ['status' => 'deferred', 'current_class_id' => $started->id]);
        $b = $this->makeStudent('HV-R2-D2', null, ['status' => 'deferred', 'current_class_id' => $upcoming->id]);
        $c = $this->makeStudent('HV-R2-D3', null, ['status' => 'deferred', 'current_class_id' => $started->id]);
        $deferred = fn (string $until) => [
            'deferred_from' => '2026-09-01', 'deferred_until' => $until, 'frozen_remaining_sessions' => 12,
            'frozen_debt_amount' => 2000000, 'reminder_paused_until' => Carbon::parse($until)->addDay()->toDateString(),
        ];
        $tuitionA = $this->makeTuition($a, 6000000, $deferred('2026-10-04'));
        $this->makeTuition($b, 6000000, $deferred('2026-10-01'));
        $this->makeTuition($c, 6000000, $deferred('2026-10-05')); // hôm nay vẫn còn bảo lưu

        $this->artisan('students:end-deferrals')->assertSuccessful();

        $this->assertSame('studying', $a->fresh()->status);
        $this->assertSame('waiting_start', $b->fresh()->status);
        $this->assertSame('deferred', $c->fresh()->status);

        $tuitionA->refresh();
        $this->assertNull($tuitionA->deferred_until);
        $this->assertNull($tuitionA->frozen_remaining_sessions);
        $this->assertNull($tuitionA->frozen_debt_amount);
        $this->assertFalse($tuitionA->remindersPausedOn());
        $this->assertStringContainsString('Kết thúc bảo lưu', (string) $tuitionA->notes);

        $this->assertSame(2, AdminNotification::where('type', 'deferral_ended')->where('user_id', $academic->id)->count());
        $this->assertSame(0, AdminNotification::where('type', 'deferral_ended')->where('user_id', $otherBranchAcademic->id)->count());

        // Chạy lại: không đổi gì, không thông báo trùng.
        $this->artisan('students:end-deferrals')->assertSuccessful();
        $this->assertSame(2, AdminNotification::where('type', 'deferral_ended')->count());
    }

    public function test_manual_end_deferral_action(): void
    {
        $academic = $this->makeUser('academic_staff');
        $class = $this->makeClass('R2-MANUAL');
        $student = $this->makeStudent('HV-R2-M1', null, ['status' => 'deferred', 'current_class_id' => $class->id]);
        $tuition = $this->makeTuition($student, 6000000, [
            'deferred_from' => '2026-10-01', 'deferred_until' => '2026-11-30', 'frozen_debt_amount' => 1000000,
            'reminder_paused_until' => '2026-12-01',
        ]);

        $this->actingAs($this->admin)->get(route('students.show', $student->id))
            ->assertOk()->assertSee('Kết thúc bảo lưu');

        $this->actingAs($this->admin)->post(route('students.end-deferral', $student->id))
            ->assertRedirect(route('students.show', $student->id))
            ->assertSessionHas('status', fn ($msg) => str_contains($msg, 'Đang học'));

        $this->assertSame('studying', $student->fresh()->status);
        $this->assertNull($tuition->fresh()->deferred_until);
        $this->assertFalse($tuition->fresh()->remindersPausedOn());
        $this->assertSame(1, AdminNotification::where('type', 'deferral_ended')->where('user_id', $academic->id)->count());

        // Không còn bảo lưu → báo lỗi, không đổi trạng thái.
        $this->actingAs($this->admin)->post(route('students.end-deferral', $student->id))
            ->assertSessionHasErrors('status');
        $this->assertSame('studying', $student->fresh()->status);
    }

    // ── 4. Buổi học bù đồng bộ nhân sự / phòng khi sửa lớp ──────────────

    public function test_class_update_syncs_future_makeup_sessions_but_keeps_protected_ones(): void
    {
        $teacher = $this->makeUser('teacher');
        $newTeacher = $this->makeUser('teacher');
        $assistant = $this->makeUser('assistant');
        $class = $this->makeClass('R2-MK', ['teacher_id' => $teacher->id, 'assistant_id' => $assistant->id]);
        $session = fn (string $date, string $type, array $extra = []) => ClassSession::create($extra + [
            'class_id' => $class->id, 'branch_id' => $class->branch_id, 'date' => $date, 'shift_name' => 'Slot 1',
            'type' => $type, 'start_time' => '18:00', 'end_time' => '19:30', 'room' => 'P101',
            'teacher_id' => $teacher->id, 'assistant_id' => $assistant->id, 'status' => 'scheduled',
        ]);
        $regular = $session('2026-10-12', ClassSession::TYPE_REGULAR);
        $makeup = $session('2026-10-19', ClassSession::TYPE_MAKEUP);
        $pastMakeup = $session('2026-10-01', ClassSession::TYPE_MAKEUP);
        $makeupWithAttendance = $session('2026-10-26', ClassSession::TYPE_MAKEUP);
        $student = $this->makeStudent('HV-R2-MK');
        StudentAttendance::create([
            'class_id' => $class->id, 'class_session_id' => $makeupWithAttendance->id, 'student_id' => $student->id,
            'session_date' => '2026-10-26', 'status' => 'present',
        ]);

        $this->actingAs($this->admin)->put(route('classes.update', $class->id), [
            'ten_lop' => $class->name, 'ma_lop' => $class->code, 'chi_nhanh' => $this->branch->id,
            'chuong_trinh' => 'IELTS', 'cap_do' => 'B1', 'si_so_toi_da' => 10,
            'giao_vien_chinh' => $newTeacher->id, 'tro_giang' => $assistant->id, 'phong_hoc' => 'P202',
        ])->assertSessionHasNoErrors();

        $this->assertSame($newTeacher->id, $regular->fresh()->teacher_id);
        $this->assertSame($newTeacher->id, $makeup->fresh()->teacher_id);
        $this->assertSame('P202', $makeup->fresh()->room);
        $this->assertSame($teacher->id, $pastMakeup->fresh()->teacher_id);
        $this->assertSame($teacher->id, $makeupWithAttendance->fresh()->teacher_id);
        $this->assertSame('P101', $makeupWithAttendance->fresh()->room);
    }

    // ── 5. Ngưỡng khai giảng + chỗ trống ────────────────────────────────

    public function test_class_forms_store_min_students_not_above_capacity_and_views_show_seats(): void
    {
        $payload = [
            'ten_lop' => 'Lớp ngưỡng', 'ma_lop' => 'R2-MIN', 'chi_nhanh' => $this->branch->id,
            'chuong_trinh' => 'IELTS', 'cap_do' => 'B1', 'si_so_toi_da' => 8,
        ];
        $this->actingAs($this->admin)->post(route('classes.store'), $payload + ['min_students' => 9])
            ->assertSessionHasErrors(['min_students' => 'Ngưỡng khai giảng không được lớn hơn sĩ số tối đa.']);
        $this->assertDatabaseMissing('classes', ['code' => 'R2-MIN']);

        $this->actingAs($this->admin)->post(route('classes.store'), $payload + ['min_students' => 5])->assertSessionHasNoErrors();
        $class = ClassModel::where('code', 'R2-MIN')->firstOrFail();
        $this->assertSame(5, $class->min_students);

        // Không nhập ngưỡng + sĩ số nhỏ hơn 6 → ngưỡng không vượt sĩ số.
        $this->actingAs($this->admin)->post(route('classes.store'), ['ma_lop' => 'R2-MIN2', 'si_so_toi_da' => 4] + $payload)->assertSessionHasNoErrors();
        $this->assertSame(4, ClassModel::where('code', 'R2-MIN2')->value('min_students'));

        $this->actingAs($this->admin)->put(route('classes.update', $class->id), $payload + ['min_students' => 3])->assertSessionHasNoErrors();
        $this->assertSame(3, $class->fresh()->min_students);
        $this->actingAs($this->admin)->put(route('classes.update', $class->id), $payload + ['min_students' => 20])->assertSessionHasErrors('min_students');

        $this->makeStudent('HV-R2-SEAT', null, ['current_class_id' => $class->id, 'status' => 'waiting_start']);
        $this->assertSame(7, $class->fresh()->seatsLeft());

        $this->actingAs($this->admin)->get(route('classes.edit', $class->id))->assertOk()->assertSee('Ngưỡng khai giảng')->assertSee('name="min_students"', false);
        $this->actingAs($this->admin)->get(route('classes.index'))->assertOk()->assertSee('Còn 7 chỗ')->assertSee('Thiếu 2/3 để KG');
        $this->actingAs($this->admin)->get(route('classes.profile', $class->id))->assertOk()
            ->assertSee('Còn 7 chỗ')->assertSee('Ngưỡng khai giảng 3')->assertSee('Cần thêm 2 học viên để khai giảng');
    }

    // ── 6. Hủy HĐ sau khi kỳ lương đã duyệt → thu hồi hoa hồng ──────────

    public function test_invoice_cancellation_after_approved_payroll_claws_back_commission(): void
    {
        // Q3: bậc theo số HS chốt — thay 3 bậc mặc định bằng một bậc 5%
        CommissionTier::query()->delete();
        CommissionTier::create(['tier_name' => 'Mức 5%', 'min_revenue' => 0, 'min_students' => 0, 'new_sale_percent' => 5, 'renew_percent' => 10, 'bonus_amount' => 0]);
        $sales = $this->makeUser('sales_consultant');
        $student = $this->makeStudent('HV-R2-CB');
        CrmCustomer::create([
            'code' => 'KH-R2-CB', 'name' => 'Khách CB', 'phone' => $student->phone, 'stage' => 'won', 'deal_value' => 10000000,
            'branch_id' => $this->branch->id, 'assigned_user_id' => $sales->id, 'commission_user_id' => $sales->id,
            'converted_student_id' => $student->id, 'converted_at' => '2026-08-20 09:00:00',
            // Gate kép (Q3): đủ 30 ngày từ ngày chốt trước 30/09 + đủ 3/3 mốc chăm sóc → hoa hồng trả trong kỳ tháng 9
            'care_checklist' => ['session_1' => ['done_at' => '2026-08-22'], 'session_4_5' => ['done_at' => '2026-09-01'], 'day_30' => ['done_at' => '2026-09-19']],
        ]);
        $tuition = $this->makeTuition($student, 10000000);

        $this->travelTo(Carbon::parse('2026-09-10 10:00:00'));
        $septReceipt = $this->approvedReceipt($tuition, 4000000, ['invoice_number' => 'C26MEN-0000501']);
        $this->travelTo(Carbon::parse('2026-10-03 10:00:00'));
        $octReceipt = $this->approvedReceipt($tuition, 2000000, ['invoice_number' => 'C26MEN-0000502']);
        $this->travelTo(Carbon::parse('2026-10-05 08:00:00'));

        $september = PayrollPeriod::create([
            'code' => 'PR-2026-09', 'title' => 'Bảng lương Tháng 9/2026', 'month' => 9, 'year' => 2026, 'status' => 'draft',
            'start_date' => '2026-09-01', 'end_date' => '2026-09-30',
        ]);
        $september->calculatePayrollForPeriod();
        $september->update(['status' => 'approved']);

        $cancel = fn (TuitionReceipt $receipt) => InvoiceCancellation::create([
            'invoice_number' => $receipt->invoice_number, 'tuition_receipt_id' => $receipt->id, 'student_id' => $student->id,
            'amount' => $receipt->amount, 'reason' => 'Xuất sai thông tin', 'requester_id' => $this->accountant->id, 'status' => 'pending',
        ]);

        // Phiếu tháng 9 (kỳ đã duyệt, đã chi 5%) → thu hồi 200.000đ ở kỳ kế tiếp.
        $this->actingAs($this->admin)->post(route('tuition.invoices.cancellations.approve', $cancel($septReceipt)->id))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', fn ($msg) => str_contains($msg, 'thu hồi 200.000 VNĐ'));
        $adjustment = CommissionAdjustment::where('user_id', $sales->id)->sole();
        $this->assertEquals(-200000, (float) $adjustment->amount);
        $this->assertNull($adjustment->settled_at);
        $this->assertSame('cancelled', $septReceipt->fresh()->status);

        // Phiếu tháng 10 (kỳ chưa duyệt) → không tạo thu hồi, lần tính lương tự loại phiếu.
        $this->actingAs($this->admin)->post(route('tuition.invoices.cancellations.approve', $cancel($octReceipt)->id))
            ->assertSessionHasNoErrors();
        $this->assertSame(1, CommissionAdjustment::count());

        $october = PayrollPeriod::create([
            'code' => 'PR-2026-10', 'title' => 'Bảng lương Tháng 10/2026', 'month' => 10, 'year' => 2026, 'status' => 'draft',
            'start_date' => '2026-10-01', 'end_date' => '2026-10-31',
        ]);
        $this->travelTo(Carbon::parse('2026-11-01 08:00:00'));
        $october->calculatePayrollForPeriod();
        $record = $october->records()->where('user_id', $sales->id)->firstOrFail();
        $this->assertEquals(0, (float) $record->commission_base);
        $this->assertEquals(200000, (float) $record->commission_clawback);
    }

    // ── 7. Phạm vi chi nhánh các màn Học phí ────────────────────────────

    public function test_tuition_screens_are_scoped_to_manager_branch(): void
    {
        $manager = $this->makeUser('manager');
        $own = $this->makeStudent('HV-R2-OWN');
        $other = $this->makeStudent('HV-R2-OTH', $this->branch2);
        $ownTuition = $this->makeTuition($own);
        $otherTuition = $this->makeTuition($other);
        $ownReceipt = TuitionReceipt::create([
            'receipt_number' => 'PT-R2-OWN', 'student_tuition_id' => $ownTuition->id, 'student_id' => $own->id, 'amount' => 100000,
            'tuition_amount' => 100000, 'payment_method' => 'cash', 'payment_date' => now(), 'creator_id' => $this->accountant->id, 'status' => 'pending',
        ]);
        $otherReceipt = TuitionReceipt::create([
            'receipt_number' => 'PT-R2-OTH', 'student_tuition_id' => $otherTuition->id, 'student_id' => $other->id, 'amount' => 100000,
            'tuition_amount' => 100000, 'payment_method' => 'cash', 'payment_date' => now(), 'creator_id' => $this->accountant->id, 'status' => 'pending',
        ]);

        foreach (['tuition.students', 'tuition.overdue', 'tuition.refunds'] as $route) {
            $this->actingAs($manager)->get(route($route))->assertOk()
                ->assertSee('HV-R2-OWN')->assertDontSee('HV-R2-OTH');
        }
        foreach (['tuition.history', 'tuition.receipts.approve'] as $route) {
            $this->actingAs($manager)->get(route($route, ['status' => 'all']))->assertOk()
                ->assertSee('PT-R2-OWN')->assertDontSee('PT-R2-OTH');
        }
        $this->actingAs($manager)->get(route('tuition.receipts.approve', ['selected_id' => $otherReceipt->id]))
            ->assertOk()->assertDontSee('PT-R2-OTH');

        $this->actingAs($manager)->post(route('tuition.receipts.approve.action', $otherReceipt->id))->assertForbidden();
        $this->assertSame('pending', $otherReceipt->fresh()->status);
        $this->actingAs($manager)->post(route('tuition.overdue.contacted', $otherTuition->id))->assertForbidden();
        $this->actingAs($manager)->post(route('tuition.receipts.approve.action', $ownReceipt->id))->assertSessionHasNoErrors();
        $this->assertSame('approved', $ownReceipt->fresh()->status);

        // Kế toán tổng (không gán chi nhánh) thấy tất cả; kế toán chi nhánh chỉ chi nhánh mình.
        $headAccountant = $this->makeUser('accountant', null, false);
        $this->actingAs($headAccountant)->get(route('tuition.students'))->assertOk()->assertSee('HV-R2-OWN')->assertSee('HV-R2-OTH');
        $this->actingAs($this->accountant)->get(route('tuition.students'))->assertOk()->assertSee('HV-R2-OWN')->assertDontSee('HV-R2-OTH');
        $this->actingAs($this->admin)->get(route('tuition.students'))->assertOk()->assertSee('HV-R2-OWN')->assertSee('HV-R2-OTH');
    }
}
