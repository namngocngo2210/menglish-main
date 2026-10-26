<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\TuitionReceipt;
use App\Models\TuitionRefundRequest;
use App\Models\User;
use App\Services\Tuition\SessionLedger;
use App\Support\Roles;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Sổ buổi học viên & lập phiếu thu theo buổi (logic tính học phí mới, 04/10/2026):
 * + buổi đã đóng khi phiếu được duyệt, − 1 mỗi buổi lớp diễn ra (vắng vẫn trừ), buổi hủy / bảo lưu không trừ,
 * chuyển lớp mang nguyên buổi tồn; Buổi cần thu = MAX(24 − buổi lớp đã diễn ra − tồn, 0).
 */
class SessionLedgerTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $academic;

    private User $accountant;

    /** Người duyệt phiếu thu: chỉ Admin (06/10/2026). */
    private User $admin;

    private Course $course;

    private ClassModel $classA;

    private int $codeSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-04 10:00:00');
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'Cơ sở sổ buổi', 'code' => 'SB', 'is_active' => true]);
        $this->academic = $this->makeUser('academic_staff');
        $this->accountant = $this->makeUser('accountant');
        $this->admin = $this->makeUser(Roles::ADMIN);
        // 24 buổi, 4.800.000 đ → 200.000 đ / buổi.
        $this->course = Course::create(['code' => 'SB24', 'name' => 'Starters', 'tuition_fee' => 4800000, 'total_lessons' => 24, 'is_active' => true]);
        $this->classA = $this->makeClass('SB-A');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_ledger_adds_paid_sessions_and_deducts_every_held_session(): void
    {
        $student = $this->makeStudent($this->classA, '2026-09-01');
        // 5 buổi đã qua (1 buổi nghỉ lễ đã hủy), 1 buổi hôm nay chưa tới giờ, 2 buổi tương lai.
        $this->sessions($this->classA, ['2026-09-07', '2026-09-14', '2026-09-21', '2026-09-28', '2026-10-01']);
        $this->sessions($this->classA, ['2026-09-02'], 'cancelled');
        $this->sessions($this->classA, ['2026-10-04'], 'scheduled', '18:00');
        $this->sessions($this->classA, ['2026-10-08', '2026-10-11']);
        $this->paidTuition($student, 2000000);

        $ledger = app(SessionLedger::class)->forStudent($student);

        // 2.000.000 / 200.000 = 10 buổi đã đóng; 5 buổi đã diễn ra (vắng hay có mặt đều trừ).
        $this->assertSame(['paid' => 10, 'used' => 5, 'balance' => 5], $ledger);

        Carbon::setTestNow('2026-10-04 18:30:00');
        $this->assertSame(6, app(SessionLedger::class)->forStudent($student)['used']);
    }

    public function test_transfer_keeps_balance_and_stops_deducting_old_class(): void
    {
        $student = $this->makeStudent($this->classA, '2026-09-01');
        $classB = $this->makeClass('SB-B');
        $this->sessions($this->classA, ['2026-09-07', '2026-09-14', '2026-10-05', '2026-10-06']);
        $this->sessions($classB, ['2026-09-10', '2026-10-05']);
        $this->paidTuition($student, 4800000);

        $this->actingAs($this->academic)->post(route('students.enrollments.store'), [
            'student_id' => $student->id,
            'class_id' => $classB->id,
        ])->assertSessionHasNoErrors();

        $old = ClassEnrollment::where('class_id', $this->classA->id)->first();
        $this->assertSame([Student::ENROLLMENT_TRANSFERRED, today()->toDateString()], [$old->status, $old->left_at->toDateString()]);
        // Ra khỏi danh sách + sĩ số lớp cũ, vào danh sách lớp mới.
        $this->assertFalse($this->classA->hasOnRoster($student->id));
        $this->assertTrue($classB->hasOnRoster($student->id));

        Carbon::setTestNow('2026-10-07 10:00:00');
        $ledger = app(SessionLedger::class)->forStudent($student->fresh());

        // Lớp A: 2 buổi trước khi chuyển; lớp B: chỉ buổi 05/10 (buổi 10/09 trước ngày vào lớp). Tồn 24 − 3 mang sang.
        $this->assertSame(['paid' => 24, 'used' => 3, 'balance' => 21], $ledger);
    }

    public function test_transfer_class_from_profile_with_backdated_date(): void
    {
        // Chuyển lớp từ thứ 7 (03/10) nhưng Học vụ cập nhật ngày 04/10: chọn ngày chuyển 03/10.
        $student = $this->makeStudent($this->classA, '2026-09-01');
        $classB = $this->makeClass('SB-B');
        $this->sessions($this->classA, ['2026-09-07', '2026-09-14', '2026-10-03']);
        $this->sessions($classB, ['2026-09-26', '2026-10-03']);
        $this->paidTuition($student, 2800000); // 14 buổi

        $this->actingAs($this->academic)->get(route('students.show', $student->id))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('mainClass', ['name' => 'Lớp SB-A', 'joined_on' => '2026-09-01', 'today' => '2026-10-04'])
                ->where('linkableClasses.0.value', $classB->id));

        $this->actingAs($this->academic)->post(route('students.transfer-class', $student->id), [
            'class_id' => $classB->id,
            'transferred_on' => '2026-10-03',
        ])->assertRedirect(route('students.show', $student->id))->assertSessionHasNoErrors();

        $student->refresh();
        $this->assertSame($classB->id, $student->current_class_id);
        $old = ClassEnrollment::where('class_id', $this->classA->id)->first();
        $this->assertSame([Student::ENROLLMENT_TRANSFERRED, '2026-10-03'], [$old->status, $old->left_at->toDateString()]);
        $this->assertSame('2026-10-03', ClassEnrollment::where('class_id', $classB->id)->first()->enrolled_at->toDateString());
        $this->assertFalse($this->classA->hasOnRoster($student->id));
        $this->assertTrue($classB->hasOnRoster($student->id));
        $this->assertSame([$classB->id], $student->activeClassIds());

        // Lớp A: 07/09, 14/09 (buổi 03/10 đã chuyển); lớp B: 03/10 (buổi 26/09 trước khi vào lớp). Tồn 14 − 3.
        $this->assertSame(['paid' => 14, 'used' => 3, 'balance' => 11], app(SessionLedger::class)->forStudent($student));
    }

    public function test_transfer_class_rejects_future_date_and_date_before_joining_old_class(): void
    {
        $student = $this->makeStudent($this->classA, '2026-09-01');
        $classB = $this->makeClass('SB-B');

        $this->actingAs($this->academic)->post(route('students.transfer-class', $student->id), [
            'class_id' => $classB->id, 'transferred_on' => '2026-10-05',
        ])->assertSessionHasErrors('transferred_on');
        $this->actingAs($this->academic)->post(route('students.transfer-class', $student->id), [
            'class_id' => $classB->id, 'transferred_on' => '2026-08-31',
        ])->assertSessionHasErrors('transferred_on');
        $this->actingAs($this->academic)->post(route('students.transfer-class', $student->id), [
            'class_id' => $this->classA->id, 'transferred_on' => '2026-10-04',
        ])->assertSessionHasErrors('class_id');

        $this->assertSame($this->classA->id, $student->fresh()->current_class_id);
        $this->assertSame(1, ClassEnrollment::count());
    }

    public function test_transfer_keeps_legacy_main_class_sessions_without_enrollment_row(): void
    {
        // Học viên cũ chưa có lượt xếp lớp chính (sổ buổi tính từ ngày tạo hồ sơ): chuyển lớp vẫn trừ buổi đã học ở lớp cũ.
        Carbon::setTestNow('2026-09-01 08:00:00');
        $student = Student::create(['code' => 'HV-SB-LEGACY', 'name' => 'Học viên cũ', 'phone' => '0900000999',
            'branch_id' => $this->branch->id, 'current_class_id' => $this->classA->id, 'status' => 'studying']);
        Carbon::setTestNow('2026-10-04 10:00:00');
        $classB = $this->makeClass('SB-B');
        $this->sessions($this->classA, ['2026-09-07', '2026-09-14']);

        $this->actingAs($this->academic)->post(route('students.transfer-class', $student->id), [
            'class_id' => $classB->id, 'transferred_on' => '2026-10-01',
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, app(SessionLedger::class)->forStudent($student->fresh())['used']);
        $this->assertFalse($this->classA->hasOnRoster($student->id));
    }

    public function test_migration_marks_earlier_transfers_so_student_leaves_old_roster(): void
    {
        // Chuyển lớp kiểu cũ: lớp chính cũ chỉ có ngày rời lớp, trạng thái vẫn "completed".
        $student = $this->makeStudent($this->classA, '2026-09-01');
        $classB = $this->makeClass('SB-B');
        ClassEnrollment::where('student_id', $student->id)->update(['left_at' => '2026-10-01']);
        ClassEnrollment::create(['student_id' => $student->id, 'class_id' => $classB->id, 'enrolled_at' => '2026-10-01', 'status' => 'completed']);
        $student->update(['current_class_id' => $classB->id]);
        $this->assertTrue($this->classA->hasOnRoster($student->id));

        (require database_path('migrations/2026_11_16_100000_mark_transferred_class_enrollments.php'))->up();

        $this->assertSame(Student::ENROLLMENT_TRANSFERRED, ClassEnrollment::where('class_id', $this->classA->id)->value('status'));
        $this->assertSame('completed', ClassEnrollment::where('class_id', $classB->id)->value('status'));
        $this->assertFalse($this->classA->hasOnRoster($student->id));
    }

    public function test_approved_deferral_period_is_not_deducted(): void
    {
        $student = $this->makeStudent($this->classA, '2026-09-01');
        $this->sessions($this->classA, ['2026-09-07', '2026-09-14', '2026-09-21', '2026-09-28']);
        TuitionRefundRequest::create([
            'student_id' => $student->id,
            'type' => TuitionRefundRequest::TYPE_DEFERRAL,
            'defer_from' => '2026-09-10',
            'defer_to' => '2026-09-25',
            'reason' => 'Đi du lịch',
            'requester_id' => $this->academic->id,
            'status' => 'approved',
        ]);

        $this->assertSame(2, app(SessionLedger::class)->forStudent($student)['used']);
    }

    public function test_receipt_collects_needed_sessions_with_fees_discount_and_surcharge(): void
    {
        $student = $this->makeStudent($this->classA, '2026-09-01');
        $this->sessions($this->classA, array_map(fn ($d) => "2026-09-{$d}", ['03', '05', '10', '12', '17', '19', '24', '26']));
        $tuition = $this->paidTuition($student, 2000000);

        $quote = app(SessionLedger::class)->quote($student->fresh(), $tuition->fresh());
        // Tồn = 10 − 8 = 2; còn lại của khóa = 24 − 8 = 16; cần thu = 16 − 2 = 14.
        $this->assertSame(2, $quote['balance']);
        $this->assertSame(16, $quote['remaining']);
        $this->assertSame(14, $quote['needed']);
        $this->assertSame('contract', $quote['mode']);
        $this->assertSame(14, $quote['max_sessions']);

        $payload = [
            'student_id' => $student->id,
            'student_tuition_id' => $tuition->id,
            'session_count' => 14,
            'exam_fee' => 100000,
            'other_fee' => 50000,
            'other_fee_reason' => 'Thẻ học viên',
            'discount_amount' => 100000,
            'discount_reason' => 'Quản lý duyệt',
            'surcharge_amount' => 20000,
            'surcharge_reason' => 'Phí in tài liệu',
            // 14 × 200.000 + 100.000 + 50.000 − 100.000 + 20.000
            'amount' => 2870000,
            'payment_method' => 'cash',
            'paper_invoice_number' => 'HDG-SB-1',
            'submit_action' => 'submit',
        ];
        $this->actingAs($this->academic)->post(route('tuition.receipts.store'), [...$payload, 'session_count' => 15])
            ->assertSessionHasErrors('session_count');
        $this->actingAs($this->academic)->post(route('tuition.receipts.store'), [...$payload, 'amount' => 2800000])
            ->assertSessionHasErrors('amount');
        $this->actingAs($this->academic)->post(route('tuition.receipts.store'), $payload)->assertSessionHasNoErrors();

        $receipt = TuitionReceipt::where('session_count', 14)->firstOrFail();
        $this->assertSame($tuition->id, $receipt->student_tuition_id);
        $this->assertEquals(2870000, (float) $receipt->amount);
        $this->assertEquals(2700000, $receipt->tuitionPortion());
        $this->assertEquals(170000, (float) $receipt->surcharge_amount);
        $this->assertEquals(200000, (float) $receipt->session_unit_price);
        $breakdown = $receipt->sessionBreakdown();
        $this->assertEquals(2800000, $breakdown['session_value']);
        $this->assertEquals(2950000, $breakdown['subtotal']);
        $this->assertEquals(2850000, $breakdown['total_due']);
        $this->assertEquals(20000, $breakdown['extra']);

        $this->actingAs($this->admin)->post(route('tuition.receipts.approve.action', $receipt->id))->assertSessionHasNoErrors();

        $this->assertEquals(0, (float) $tuition->fresh()->debt_amount);
        $this->assertSame(['paid' => 24, 'used' => 8, 'balance' => 16], app(SessionLedger::class)->forStudent($student));
    }

    public function test_balance_above_remaining_collects_zero_and_carries_over(): void
    {
        $classB = $this->makeClass('SB-B');
        $this->sessions($classB, array_map(fn ($d) => sprintf('2026-08-%02d', $d), range(1, 20)));
        $student = $this->makeStudent($classB, '2026-10-01');
        $this->paidTuition($student, 4800000);

        $quote = app(SessionLedger::class)->quote($student->fresh(), SessionLedger::openTuitionFor($student));

        $this->assertSame(['balance' => 24, 'remaining' => 4, 'needed' => 0, 'carry_over' => 20, 'mode' => 'new', 'max_sessions' => 0],
            array_intersect_key($quote, array_flip(['balance', 'remaining', 'needed', 'carry_over', 'mode', 'max_sessions'])));
    }

    public function test_next_course_receipt_creates_tuition_when_approved(): void
    {
        $student = $this->makeStudent($this->classA, '2026-10-01');

        $this->actingAs($this->academic)->get(route('tuition.receipts.create', ['student_id' => $student->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Tuition/ReceiptForm')
                ->where('students.0.quote.mode', 'new')
                ->where('students.0.quote.needed', 24)
                ->where('students.0.quote.unit_price', fn ($price) => (float) $price === 200000.0));

        $this->actingAs($this->academic)->post(route('tuition.receipts.store'), [
            'student_id' => $student->id,
            'session_count' => 12,
            'amount' => 2400000,
            'payment_method' => 'cash',
            'paper_invoice_number' => 'HDG-SB-2',
            'submit_action' => 'submit',
        ])->assertSessionHasNoErrors();

        $receipt = TuitionReceipt::firstOrFail();
        $this->assertNull($receipt->student_tuition_id);
        $this->assertSame(0, StudentTuition::count());

        $this->actingAs($this->admin)->post(route('tuition.receipts.approve.action', $receipt->id))->assertSessionHasNoErrors();

        $tuition = StudentTuition::firstOrFail();
        $this->assertSame($tuition->id, $receipt->fresh()->student_tuition_id);
        $this->assertSame(12, $tuition->session_count);
        $this->assertEquals(2400000, (float) $tuition->final_amount);
        $this->assertEquals(0, (float) $tuition->debt_amount);
        $this->assertSame(12, app(SessionLedger::class)->forStudent($student)['paid']);
    }

    public function test_joining_mid_course_charges_only_remaining_sessions(): void
    {
        $join = SessionLedger::joinTuition($this->classA, 8);

        $this->assertSame(['sessions' => 16, 'course_sessions' => 24, 'fee' => 3200000.0], $join);
        $this->assertSame(24, SessionLedger::courseSessions(null, new Course(['total_lessons' => 0])));
    }

    private function makeUser(string $role): User
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function makeClass(string $code): ClassModel
    {
        return ClassModel::create([
            'code' => $code, 'name' => 'Lớp '.$code, 'course_id' => $this->course->id,
            'branch_id' => $this->branch->id, 'max_capacity' => 20, 'status' => 'active', 'start_date' => '2026-08-01',
        ]);
    }

    public function test_tuition_session_stats_follow_ledger_absences_still_count(): void
    {
        // Hoàn phí / bảo lưu / công nợ dùng sessionStats(): khớp sổ buổi — vắng vẫn trừ, buổi trước khi vào lớp không trừ.
        $student = $this->makeStudent($this->classA, '2026-09-10');
        $this->sessions($this->classA, ['2026-09-07', '2026-09-14', '2026-09-21', '2026-09-28']);
        $tuition = $this->paidTuition($student, 4800000);
        // Chỉ điểm danh có mặt 1 buổi, 2 buổi vắng, 1 buổi GV quên điểm danh.
        $sessions = ClassSession::where('class_id', $this->classA->id)->orderBy('date')->get();
        foreach ([[1, 'present'], [2, 'absent']] as [$i, $status]) {
            \App\Models\StudentAttendance::create([
                'class_id' => $this->classA->id, 'class_session_id' => $sessions[$i]->id, 'student_id' => $student->id,
                'session_date' => $sessions[$i]->date, 'status' => $status,
            ]);
        }

        $this->assertSame(['total' => 24, 'attended' => 3, 'remaining' => 21, 'source' => 'course'], $tuition->fresh()->sessionStats());

        StudentTuition::preloadSessionStats([$fresh = $tuition->fresh()]);
        $this->assertSame(3, $fresh->sessionStats()['attended']);
    }

    private function makeStudent(ClassModel $class, string $enrolledAt): Student
    {
        $this->codeSeq++;
        $student = Student::create([
            'code' => 'HV-SB-'.$this->codeSeq,
            'name' => 'Học viên '.$this->codeSeq,
            'phone' => '09000000'.str_pad((string) $this->codeSeq, 2, '0', STR_PAD_LEFT),
            'branch_id' => $this->branch->id,
            'current_class_id' => $class->id,
            'status' => 'studying',
        ]);
        ClassEnrollment::create(['student_id' => $student->id, 'class_id' => $class->id, 'enrolled_at' => $enrolledAt, 'status' => 'completed']);

        return $student;
    }

    /** @param  list<string>  $dates */
    private function sessions(ClassModel $class, array $dates, string $status = 'scheduled', string $start = '08:00'): void
    {
        foreach ($dates as $date) {
            ClassSession::create([
                'class_id' => $class->id, 'branch_id' => $class->branch_id, 'date' => $date,
                'start_time' => $start, 'end_time' => '09:30', 'status' => $status,
            ]);
        }
    }

    /** Khoản học phí 24 buổi (4.800.000 đ) kèm phiếu đã duyệt $paid. */
    private function paidTuition(Student $student, float $paid): StudentTuition
    {
        $tuition = StudentTuition::create([
            'student_id' => $student->id, 'class_id' => $student->current_class_id, 'branch_id' => $this->branch->id,
            'total_amount' => 4800000, 'session_count' => 24, 'discount_amount' => 0, 'other_fees' => 0,
            'final_amount' => 4800000, 'paid_amount' => 0, 'debt_amount' => 4800000, 'status' => 'unpaid',
        ]);
        TuitionReceipt::create([
            'receipt_number' => TuitionReceipt::generateReceiptNumber(),
            'student_tuition_id' => $tuition->id, 'student_id' => $student->id,
            'amount' => $paid, 'tuition_amount' => $paid, 'payment_method' => 'transfer',
            'payment_date' => now(), 'status' => TuitionReceipt::STATUS_APPROVED, 'creator_id' => $this->accountant->id,
        ]);
        $tuition->recalculateDebt();

        return $tuition;
    }
}
