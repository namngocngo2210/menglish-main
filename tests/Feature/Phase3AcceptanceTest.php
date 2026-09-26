<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\CommissionItem;
use App\Models\Course;
use App\Models\CrmCustomer;
use App\Models\KpiCriterion;
use App\Models\KpiEvaluation;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\Penalty;
use App\Models\Student;
use App\Models\TeacherTimesheet;
use App\Models\TuitionReceipt;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Nghiệm thu Phase 3 (docs/audit-report-and-roadmap.md — Phần C, "Kết quả đạt được"):
 * buổi dạy → chấm công → phạt / hoa hồng / KPI → bảng lương → duyệt → nhân viên xem lương, qua HTTP bằng đúng vai trò,
 * theo A6 (Q3 công thức lương Part-time / Full-time, hoa hồng theo bậc + gate kép, KPI Học vụ 15 mục).
 *
 * Mốc thời gian: kỳ tháng 8/2026 (tính 01/09, duyệt 02/09), kỳ tháng 9 (tính + duyệt 01/10), kỳ tháng 10 (tính 01/11).
 */
class Phase3AcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $admin;

    private User $manager;

    private User $academic;

    private User $lead;

    private User $accountant;

    private User $sale;

    private User $partTime;

    private User $fullTime;

    private User $otherTeacher;

    private Course $course;

    private ClassModel $classPt;

    private ClassModel $classFt;

    private ClassModel $otherClass;

    /** @var array<string, ClassSession> */
    private array $sessions = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'Cơ sở Nghiệm thu 3', 'code' => 'N3', 'is_active' => true]);
        $this->admin = $this->userWithRole('admin');
        $this->manager = $this->userWithRole('manager');
        $this->academic = $this->userWithRole('academic_staff', ['name' => 'Học vụ Phượng', 'base_salary' => 8000000]);
        $this->lead = $this->userWithRole('academic_lead');
        $this->accountant = $this->userWithRole('accountant');
        $this->sale = $this->userWithRole('sales_consultant', ['name' => 'Sale Hà', 'base_salary' => 7000000]);
        $this->partTime = $this->userWithRole('teacher_parttime', ['name' => 'GV Part-time Lan']);
        $this->fullTime = $this->userWithRole('teacher_fulltime', ['name' => 'GV Full-time Minh', 'base_salary' => 10000000]);
        $this->otherTeacher = $this->userWithRole('teacher_parttime');

        $this->course = Course::create(['code' => 'N3-FAM1', 'name' => 'Starters FAM 1 (N3)', 'tuition_fee' => 9000000, 'total_lessons' => 24, 'is_active' => true]);
        $this->classPt = $this->makeClass('N3-PT', $this->partTime);
        $this->classFt = $this->makeClass('N3-FT', $this->fullTime);
        $this->otherClass = $this->makeClass('N3-OTHER', $this->otherTeacher);

        // Buổi học thật (lịch Phase 2): lớp GV part-time T2/T4/T6, lớp GV full-time T3/T5.
        foreach (['2026-08-03', '2026-08-05', '2026-08-07', '2026-09-07'] as $date) {
            $this->sessions['pt-'.$date] = $this->makeSession($this->classPt, $date, $this->partTime);
        }
        foreach (['2026-08-04', '2026-08-06'] as $date) {
            $this->sessions['ft-'.$date] = $this->makeSession($this->classFt, $date, $this->fullTime);
        }
        $this->sessions['other-2026-08-03'] = $this->makeSession($this->otherClass, '2026-08-03', $this->otherTeacher);

        // 5 học viên đã học lớp GV part-time từ tháng 7 (mẫu số KPI giữ HS tháng 8).
        foreach (range(1, 5) as $i) {
            $student = Student::create([
                'code' => 'HV-N3-'.$i, 'name' => 'Học viên N3 '.$i, 'phone' => '09110000'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'branch_id' => $this->branch->id, 'status' => 'studying', 'current_class_id' => $this->classPt->id,
            ]);
            ClassEnrollment::create(['student_id' => $student->id, 'class_id' => $this->classPt->id, 'enrolled_at' => '2026-07-15', 'status' => 'completed']);
            $this->students[] = $student;
        }
    }

    /** @var list<Student> */
    private array $students = [];

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_session_to_timesheet_to_penalty_commission_kpi_to_payroll_approval_and_my_salary(): void
    {
        // ── 0. Cấu hình: đơn giá buổi riêng có phiên bản (Quản lý cơ sở); Kế toán không có quyền ─────────
        $this->at('2026-07-01 09:00');
        $this->actingAs($this->accountant)->post(route('payroll.config.teacher-rates.personal.store'), $this->rate(200000, '2026-07-01'))->assertForbidden();
        $this->actingAs($this->manager)->post(route('payroll.config.teacher-rates.personal.store'), $this->rate(200000, '2026-07-01'))->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post(route('payroll.config.teacher-rates.personal.store'), $this->rate(250000, '2026-09-01'))->assertSessionHasNoErrors();

        // ── 1. Sale chốt 2 khách mới (đã đóng học phí) ngày 03/08; Kế toán duyệt phiếu thu ───────────────
        $this->at('2026-08-03 10:00');
        $k1 = $this->closeCustomer('0977300001', 'Khách Một');
        $k2 = $this->closeCustomer('0977300002', 'Khách Hai');
        $this->at('2026-08-03 15:00');
        foreach ([$k1, $k2] as $customer) {
            $receipt = TuitionReceipt::where('student_id', $customer->converted_student_id)->where('status', 'pending')->firstOrFail();
            $this->actingAs($this->sale)->post(route('tuition.receipts.approve.action', $receipt->id))->assertForbidden();
            $this->actingAs($this->accountant)->post(route('tuition.receipts.approve.action', $receipt->id))->assertSessionHasNoErrors();
            $this->assertSame('approved', $receipt->fresh()->status);
        }

        // ── 2. GV check-in: chỉ buổi thật hôm nay được phân công ─────────────────────────────────────
        $this->at('2026-08-03 17:20');
        $s1 = $this->sessions['pt-2026-08-03'];
        $this->actingAs($this->partTime)->post(route('teacher.checkin'), ['session_ids' => [$this->sessions['pt-2026-08-05']->id]])
            ->assertSessionHasErrors('class_ids');                                  // buổi chưa tới
        $this->actingAs($this->partTime)->post(route('teacher.checkin'), ['session_ids' => [$this->sessions['other-2026-08-03']->id]])
            ->assertSessionHasErrors('class_ids');                                  // buổi của GV khác
        $this->actingAs($this->partTime)->post(route('teacher.checkin'), ['class_ids' => [$this->classFt->id]])->assertForbidden(); // lớp không dạy
        $this->assertSame(0, TeacherTimesheet::count());
        $this->actingAs($this->partTime)->post(route('teacher.checkin'), ['session_ids' => [$s1->id]])->assertSessionHasNoErrors();
        $first = TeacherTimesheet::where('class_session_id', $s1->id)->firstOrFail();
        $this->assertSame(['checkin', 'pending_review', '17:20'], [$first->source, $first->status, $first->checkin_time]);
        $this->assertEquals(1.5, $first->hours);
        // Bấm lại: không tạo thêm, không ghi đè giờ check-in
        $this->at('2026-08-03 17:45');
        $this->actingAs($this->partTime)->post(route('teacher.checkin'), ['session_ids' => [$s1->id]])->assertSessionHasErrors('class_ids');
        $this->assertSame(1, TeacherTimesheet::count());
        $this->assertSame('17:20', $first->fresh()->checkin_time);

        $this->at('2026-08-04 17:25');
        $this->actingAs($this->fullTime)->post(route('teacher.checkin'), ['session_ids' => [$this->sessions['ft-2026-08-04']->id]])->assertSessionHasNoErrors();

        // ── 3. GV quên check-in 05/08 → Học vụ chấm công tay (bắt buộc lý do, giờ vào/ra), không trùng ─
        $this->at('2026-08-05 21:00');
        $manual = [
            'user_id' => $this->partTime->id, 'class_id' => $this->classPt->id, 'teaching_date' => '2026-08-05',
            'time_in' => '17:30', 'time_out' => '19:00', 'type' => 'regular', 'notes' => 'GV quên check-in, đối chiếu sổ điểm danh.',
        ];
        $this->actingAs($this->partTime)->post(route('payroll.timesheets.manual.store'), $manual)->assertForbidden();
        $this->actingAs($this->academic)->post(route('payroll.timesheets.manual.store'), ['notes' => ''] + $manual)->assertSessionHasErrors('notes');
        $this->actingAs($this->academic)->post(route('payroll.timesheets.manual.store'), ['time_out' => '17:00'] + $manual)->assertSessionHasErrors('time_out');
        $this->actingAs($this->academic)->post(route('payroll.timesheets.manual.store'), ['teaching_date' => '2026-08-07'] + $manual)->assertSessionHasErrors('teaching_date');
        $this->assertSame(2, TeacherTimesheet::count());
        $this->actingAs($this->academic)->post(route('payroll.timesheets.manual.store'), $manual)->assertSessionHasNoErrors();
        $manualRow = TeacherTimesheet::where('source', 'manual')->firstOrFail();
        $this->assertSame($this->sessions['pt-2026-08-05']->id, $manualRow->class_session_id);
        $this->assertSame(['17:30', '19:00', 'pending_review'], [$manualRow->checkin_time, $manualRow->checkout_time, $manualRow->status]);
        $this->actingAs($this->academic)->post(route('payroll.timesheets.manual.store'), ['notes' => 'Nhập lại lần hai.'] + $manual)
            ->assertSessionHasErrors('teaching_date');                               // trùng buổi → bị chặn
        $this->actingAs($this->partTime)->post(route('teacher.checkin'), ['session_ids' => [$this->sessions['pt-2026-08-05']->id]])
            ->assertSessionHasErrors('class_ids');                                  // đã chấm tay → không check-in chồng
        $this->assertSame(3, TeacherTimesheet::count());

        $this->at('2026-08-06 17:25');
        $this->actingAs($this->fullTime)->post(route('teacher.checkin'), ['session_ids' => [$this->sessions['ft-2026-08-06']->id]])->assertSessionHasNoErrors();
        $this->at('2026-08-07 17:22');
        $this->actingAs($this->partTime)->post(route('teacher.checkin'), ['session_ids' => [$this->sessions['pt-2026-08-07']->id]])->assertSessionHasNoErrors();
        // Ca chấm tay ngày không có buổi học (workshop) → không gắn buổi, sẽ bị từ chối
        $this->at('2026-08-08 18:00');
        $this->actingAs($this->academic)->post(route('payroll.timesheets.manual.store'), [
            'teaching_date' => '2026-08-08', 'time_in' => '14:00', 'time_out' => '16:00', 'type' => 'workshop', 'notes' => 'Workshop phụ huynh ngoài lịch.',
        ] + $manual)->assertSessionHasNoErrors();
        $workshop = TeacherTimesheet::whereNull('class_session_id')->firstOrFail();

        // Học vụ duyệt: ca buổi thật hợp lệ; ca không có buổi bị từ chối (bắt buộc lý do)
        $this->at('2026-08-09 08:00');
        $this->actingAs($this->partTime)->post(route('payroll.timesheets.review', $first->id), ['decision' => 'valid'])->assertForbidden();
        foreach (TeacherTimesheet::whereNotNull('class_session_id')->get() as $timesheet) {
            $this->actingAs($this->academic)->post(route('payroll.timesheets.review', $timesheet->id), ['decision' => 'valid'])->assertSessionHasNoErrors();
        }
        $this->actingAs($this->academic)->post(route('payroll.timesheets.review', $workshop->id), ['decision' => 'invalid'])->assertSessionHasErrors('rejection_reason');
        $this->actingAs($this->academic)->post(route('payroll.timesheets.review', $workshop->id), ['decision' => 'invalid', 'rejection_reason' => 'Không có buổi học trên lịch.'])
            ->assertSessionHasNoErrors();
        $this->assertSame(5, TeacherTimesheet::where('status', 'valid')->count());

        // ── 4. Kỷ luật: ghi nhận → giải trình → HT/CM chốt theo loại lỗi kèm số tiền → quá hạn / nộp ──────
        $this->at('2026-08-10 09:00');
        $this->actingAs($this->partTime)->post(route('penalties.store'), $this->violation($this->otherTeacher, 'operations'))->assertForbidden();
        $this->actingAs($this->academic)->post(route('penalties.store'), $this->violation($this->partTime, 'operations'))->assertSessionHasNoErrors();
        $late = Penalty::where('user_id', $this->partTime->id)->firstOrFail();
        $this->assertSame('pending', $late->status);
        $this->assertEquals(0, $late->amount);                                        // chưa cần số tiền khi ghi nhận
        $this->actingAs($this->partTime)->get(route('penalties.index'))->assertOk()->assertSee($late->code);
        $this->actingAs($this->otherTeacher)->get(route('penalties.index'))->assertOk()->assertDontSee($late->code);
        $this->actingAs($this->otherTeacher)->post(route('penalties.explain', $late->id), ['explanation' => 'Tôi giải trình thay người khác.'])->assertForbidden();
        $this->actingAs($this->partTime)->post(route('penalties.explain', $late->id), ['explanation' => 'Ngắn'])->assertSessionHasErrors('explanation');
        $this->actingAs($this->partTime)->post(route('penalties.explain', $late->id), ['explanation' => 'Em bị hỏng xe trên đường, đã báo TA.'])->assertSessionHasNoErrors();
        $this->assertSame('explained', $late->fresh()->status);

        $this->at('2026-08-10 15:00');
        $this->actingAs($this->lead)->post(route('penalties.confirm', $late->id), ['decision' => 'fine', 'amount' => 100000])->assertForbidden(); // lỗi vận hành do CM chốt
        $this->actingAs($this->manager)->post(route('penalties.confirm', $late->id), ['decision' => 'fine'])->assertSessionHasErrors('amount');
        $this->actingAs($this->manager)->post(route('penalties.confirm', $late->id), ['decision' => 'fine', 'amount' => 100000, 'decision_note' => 'Lần đầu'])
            ->assertSessionHasNoErrors();
        $late->refresh();
        $this->assertSame(['fined', '2026-08-12'], [$late->status, $late->due_date->toDateString()]);   // nộp trong 2 ngày

        $this->at('2026-08-11 09:00');
        $this->actingAs($this->academic)->post(route('penalties.store'), $this->violation($this->fullTime, 'academic', 'Chậm nộp nhận xét buổi học (> 24h)'))->assertSessionHasNoErrors();
        $paidFine = Penalty::where('user_id', $this->fullTime->id)->firstOrFail();
        $this->actingAs($this->fullTime)->post(route('penalties.explain', $paidFine->id), ['explanation' => 'Tuần chấm Big Test nên nộp muộn.'])->assertSessionHasNoErrors();
        $this->actingAs($this->academic)->post(route('penalties.confirm', $paidFine->id), ['decision' => 'fine', 'amount' => 200000])->assertForbidden(); // lỗi chuyên môn do HT chốt
        $this->actingAs($this->lead)->post(route('penalties.confirm', $paidFine->id), ['decision' => 'fine', 'amount' => 200000])->assertSessionHasNoErrors();
        $this->at('2026-08-12 10:00');
        $this->actingAs($this->manager)->post(route('penalties.mark-paid', $paidFine->id))->assertSessionHasNoErrors();
        $this->assertSame('paid', $paidFine->fresh()->status);

        // ── 5. HS thôi học giữa kỳ (KPI giữ HS), KPI Học vụ tháng 8 (không tự chấm) ──────────────────
        $this->at('2026-08-20 10:00');
        $this->students[0]->update(['status' => Student::STATUS_DROPPED]);
        $this->at('2026-08-28 09:00');
        $this->actingAs($this->academic)->post(route('penalties.store'), $this->violation($this->partTime, 'academic', 'Không nộp giáo án / bài tập đúng hạn', '2026-08-28'))
            ->assertSessionHasNoErrors();
        $lateAug = Penalty::where('user_id', $this->partTime->id)->where('violation_date', '>=', '2026-08-28')->firstOrFail();
        $this->at('2026-08-31 10:00');
        $this->actingAs($this->lead)->post(route('penalties.confirm', $lateAug->id), ['decision' => 'fine', 'amount' => 150000])->assertSessionHasNoErrors();
        $this->assertSame('2026-09-02', $lateAug->fresh()->due_date->toDateString());

        $scores = KpiCriterion::active()->ordered()->get()->mapWithKeys(fn (KpiCriterion $c) => [$c->id => $c->code === '1.2' ? 50 : 100])->all();
        $this->assertCount(15, $scores);
        $this->actingAs($this->academic)->post(route('kpi.evaluate.store', $this->academic->id), ['month' => 8, 'year' => 2026, 'score' => $scores])->assertForbidden();
        $this->actingAs($this->manager)->post(route('kpi.evaluate.store', $this->academic->id), ['month' => 8, 'year' => 2026, 'score' => $scores])->assertSessionHasNoErrors();
        $this->assertEquals(92.5, (float) KpiEvaluation::where('user_id', $this->academic->id)->value('total_score'));

        // ── 6. Kỳ tháng 8: Kế toán tính, nhập tay, tính lại ─────────────────────────────────────────
        $this->at('2026-09-01 08:00');
        $this->actingAs($this->manager)->post(route('payroll.periods.store'), ['month' => 8, 'year' => 2026])->assertForbidden();
        $this->actingAs($this->partTime)->get(route('payroll.periods.index'))->assertForbidden();
        $this->actingAs($this->accountant)->post(route('payroll.periods.store'), ['month' => 8, 'year' => 2026])->assertSessionHasNoErrors();
        $august = PayrollPeriod::where('code', 'PR-2026-08')->firstOrFail();
        $this->actingAs($this->accountant)->post(route('payroll.periods.store'), ['month' => 8, 'year' => 2026])->assertSessionHasErrors('month');
        $this->assertEqualsCanonicalizing(
            [$this->academic->id, $this->sale->id, $this->partTime->id, $this->fullTime->id],
            $august->records()->pluck('user_id')->all()
        );

        $pt = $this->record($august, $this->partTime);
        $this->assertSame(['parttime', 3], [$pt->employee_type, $pt->teaching_sessions]);   // 2 check-in + 1 chấm tay; workshop bị từ chối
        $this->assertEquals(600000, $pt->teaching_salary);                                    // 3 buổi × 200.000đ (phiên bản tháng 7)
        $this->assertSame([5, 4], [$pt->retention_base_students, $pt->retention_students]);   // 1 HS thôi học
        $this->assertEquals(100000, $pt->penalty_deduction);                                  // quá hạn 12/08
        $this->assertEquals(0, $pt->insurance_deduction);
        $this->actingAs($this->partTime)->get(route('portal.my-salary'))->assertOk()->assertViewHas('record', null); // chưa duyệt → chưa thấy
        $this->actingAs($this->partTime)->get(route('payroll.records.show', $pt->id))->assertForbidden();

        $this->at('2026-09-01 08:30');
        $this->actingAs($this->manager)->post(route('payroll.records.adjust', $pt->id), ['retention_tier' => 20000])->assertForbidden();
        $this->actingAs($this->accountant)->post(route('payroll.records.adjust', $pt->id), ['retention_tier' => 30000])->assertSessionHasErrors('retention_tier');
        $this->actingAs($this->accountant)->post(route('payroll.records.adjust', $pt->id), [
            'retention_tier' => 20000, 'tax_deduction' => 999999, 'lines' => [['kind' => 'earning', 'label' => 'Gửi xe', 'amount' => 100000]],
        ])->assertSessionHasNoErrors();
        $ft = $this->record($august, $this->fullTime);
        $this->actingAs($this->accountant)->post(route('payroll.records.adjust', $ft->id), [
            'kpi_manual_amount' => 1000000, 'tax_deduction' => 250000, 'lines' => [['kind' => 'earning', 'label' => 'Phụ cấp trách nhiệm', 'amount' => 500000]],
        ])->assertSessionHasNoErrors();
        $this->at('2026-09-01 08:45');
        $this->actingAs($this->manager)->post(route('payroll.periods.calculate', $august->id))->assertForbidden();
        $this->actingAs($this->accountant)->post(route('payroll.periods.calculate', $august->id))->assertSessionHasNoErrors();

        // Part-time: 600.000 + KPI 4 HS × 20.000 + Gửi xe 100.000 − phạt 100.000; không BHXH / CĐ / TNCN
        $pt = $this->record($august, $this->partTime);
        $this->assertEquals(80000, $pt->kpi_bonus);
        $this->assertEquals(0, $pt->tax_deduction);
        $this->assertEquals(680000, $pt->net_salary);
        // Full-time: 10.000.000 + KPI 1.000.000 + phụ cấp 500.000 − BHXH 10,5% − CĐ 0,5% − TNCN 250.000 (phạt đã nộp → không trừ)
        $ft = $this->record($august, $this->fullTime);
        $this->assertSame([2, 0.0], [$ft->teaching_sessions, (float) $ft->teaching_salary]);
        $this->assertEquals(1050000, $ft->insurance_deduction);
        $this->assertEquals(50000, $ft->union_deduction);
        $this->assertEquals(250000, $ft->tax_deduction);
        $this->assertEquals(0, $ft->penalty_deduction);
        $this->assertEquals(10150000, $ft->net_salary);
        // Học vụ: 8.000.000 + quỹ 2.000.000 × 92,5% − 840.000 − 40.000
        $hv = $this->record($august, $this->academic);
        $this->assertSame('academic_kpi', $hv->kpi_source);
        $this->assertEquals(1850000, $hv->kpi_bonus);
        $this->assertEquals(8970000, $hv->net_salary);
        // Sale: 2 khách chốt 03/08 → bậc 3%; chưa đủ 30 ngày → hoãn 2 × 270.000đ
        $sale = $this->record($august, $this->sale);
        $this->assertSame(2, $sale->commission_closed_count);
        $this->assertEquals(3, $sale->commission_percent);
        $this->assertEquals(0, $sale->commission_bonus);
        $this->assertEquals(540000, $sale->commission_deferred);
        $this->assertEquals(6230000, $sale->net_salary);
        $this->assertEquals(680000 + 10150000 + 8970000 + 6230000, (float) $august->fresh()->total_amount);

        $this->actingAs($this->accountant)->get(route('payroll.records.show', $pt->id))->assertOk()
            ->assertSee('Lương buổi dạy (3 buổi)')->assertSee('4/5 HS giữ được')->assertSee('Gửi xe')->assertSee('680.000');
        $this->actingAs($this->accountant)->get(route('payroll.records.show', $ft->id))->assertOk()
            ->assertSee('BHXH (10,5% lương cơ bản)')->assertSee('Công đoàn (0,5% lương cơ bản)')->assertSee('Thuế TNCN')->assertSee('10.150.000');

        // ── 7. Chỉ Admin duyệt; kỳ đã duyệt khóa toàn bộ dữ liệu ─────────────────────────────────────
        $this->at('2026-09-02 09:00');
        $this->actingAs($this->accountant)->post(route('payroll.periods.approve', $august->id))->assertForbidden();
        $this->actingAs($this->manager)->post(route('payroll.periods.approve', $august->id))->assertForbidden();
        $this->finalizeKpi($august);
        $this->actingAs($this->admin)->post(route('payroll.periods.approve', $august->id))->assertSessionHasNoErrors();
        $this->assertSame('approved', $august->fresh()->status);
        $this->assertSame('deducted', $late->fresh()->status);
        $this->assertSame('fined', $lateAug->fresh()->status);        // hạn nộp 02/09 → chưa trừ kỳ 8

        $this->actingAs($this->academic)->post(route('payroll.timesheets.manual.store'), ['teaching_date' => '2026-08-07', 'notes' => 'Bổ sung sau khi duyệt.'] + $manual)
            ->assertSessionHasErrors('teaching_date');
        $this->actingAs($this->academic)->post(route('payroll.timesheets.review', $workshop->id), ['decision' => 'valid'])->assertSessionHasErrors('teaching_date');
        $this->actingAs($this->academic)->post(route('penalties.store'), $this->violation($this->partTime, 'operations', 'Vi phạm nội quy trung tâm', '2026-08-20'))
            ->assertSessionHasErrors('violation_date');
        $this->actingAs($this->manager)->post(route('kpi.evaluate.store', $this->academic->id), ['month' => 8, 'year' => 2026, 'score' => array_map(fn () => 100, $scores)])
            ->assertSessionHasErrors('month');
        $this->assertEquals(92.5, (float) KpiEvaluation::where('user_id', $this->academic->id)->value('total_score'));
        $this->actingAs($this->accountant)->post(route('payroll.records.adjust', $pt->id), ['retention_tier' => 25000])->assertStatus(422);
        $this->actingAs($this->accountant)->post(route('payroll.periods.calculate', $august->id))->assertStatus(422);
        $this->actingAs($this->manager)->post(route('penalties.mark-paid', $late->id))->assertStatus(422);
        $this->assertSame(5, TeacherTimesheet::where('teaching_date', '<', '2026-09-01')->where('status', 'valid')->count());

        // Nhân viên xem "Lương của tôi" sau khi duyệt, cùng các dòng phiếu lương
        $this->actingAs($this->partTime)->get(route('portal.my-salary'))->assertOk()
            ->assertViewHas('record', fn (?PayrollRecord $r) => $r?->id === $pt->id && (float) $r->net_salary === 680000.0)->assertSee('KPI giữ học sinh');
        $this->actingAs($this->otherTeacher)->get(route('portal.my-salary'))->assertOk()->assertViewHas('record', null);

        // ── 8. Tháng 9: đơn giá mới, mốc chăm sóc, kỳ tháng 9 ────────────────────────────────────────
        $this->at('2026-09-07 17:25');
        $this->actingAs($this->partTime)->post(route('teacher.checkin'), ['session_ids' => [$this->sessions['pt-2026-09-07']->id]])->assertSessionHasNoErrors();
        $this->at('2026-09-08 08:00');
        $this->actingAs($this->academic)->post(route('payroll.timesheets.review', TeacherTimesheet::latest('id')->value('id')), ['decision' => 'valid'])->assertSessionHasNoErrors();
        $this->at('2026-09-10 10:00');
        $this->actingAs($this->sale)->post(route('crm.customers.care-checklist', $k1->id), ['items' => ['session_1', 'session_4_5', 'day_30']])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post(route('crm.customers.care-checklist', $k2->id), ['items' => ['session_1', 'session_4_5']])->assertSessionHasNoErrors();

        $this->at('2026-10-01 08:00');
        $this->actingAs($this->accountant)->post(route('payroll.periods.store'), ['month' => 9, 'year' => 2026])->assertSessionHasNoErrors();
        $september = PayrollPeriod::where('code', 'PR-2026-09')->firstOrFail();
        $pt9 = $this->record($september, $this->partTime);
        $this->assertEquals(250000, $pt9->teaching_salary);                   // phiên bản đơn giá từ 01/09
        $this->assertEquals(150000, $pt9->penalty_deduction);                 // biên bản 28/08 quá hạn 02/09 → trừ kỳ 9
        $this->assertEquals(100000, $pt9->net_salary);
        $sale9 = $this->record($september, $this->sale);
        $this->assertEquals(270000, $sale9->commission_bonus);                // khách 1 đủ 30 ngày + 3/3 mốc
        $this->assertEquals(270000, $sale9->commission_deferred);             // khách 2 mới 2/3 mốc
        $this->assertEquals(6500000, $sale9->net_salary);
        $this->assertStringContainsString('2/3 mốc', CommissionItem::where('crm_customer_id', $k2->id)->value('deferred_reason'));

        // GV nộp phạt trực tiếp sau lần tính → phải tính lại trước khi duyệt (không trừ 2 lần)
        $this->at('2026-10-01 10:00');
        $this->actingAs($this->manager)->post(route('penalties.mark-paid', $lateAug->id))->assertSessionHasNoErrors();
        $this->at('2026-10-01 11:00');
        $this->actingAs($this->admin)->post(route('payroll.periods.approve', $september->id))->assertSessionHasErrors('period');
        $this->assertSame('reviewing', $september->fresh()->status);
        $this->actingAs($this->accountant)->post(route('payroll.periods.calculate', $september->id))->assertSessionHasNoErrors();
        $this->assertEquals(250000, $this->record($september, $this->partTime)->net_salary);
        $this->at('2026-10-01 11:30');
        $this->finalizeKpi($september);
        $this->actingAs($this->admin)->post(route('payroll.periods.approve', $september->id))->assertSessionHasNoErrors();
        $this->assertSame('paid', $lateAug->fresh()->status);
        $this->assertSame(1, CommissionItem::where('status', CommissionItem::STATUS_PAID)->whereNotNull('settled_at')->count());

        // ── 9. Tháng 10: khách 2 đủ mốc → hoa hồng hoãn được trả ở kỳ sau, khoản đã trả không trả lại ──
        $this->at('2026-10-05 10:00');
        $this->actingAs($this->manager)->post(route('crm.customers.care-checklist', $k2->id), ['items' => ['session_1', 'session_4_5', 'day_30']])->assertSessionHasNoErrors();
        $this->at('2026-11-01 08:00');
        $this->actingAs($this->accountant)->post(route('payroll.periods.store'), ['month' => 10, 'year' => 2026])->assertSessionHasNoErrors();
        $october = PayrollPeriod::where('code', 'PR-2026-10')->firstOrFail();
        $sale10 = $this->record($october, $this->sale);
        $this->assertEquals(270000, $sale10->commission_bonus);
        $this->assertEquals(0, $sale10->commission_deferred);
        $this->assertEquals(6500000, $sale10->net_salary);
        $this->assertSame(2, CommissionItem::count());

        // Sale xem lương tháng 9 (đã duyệt), tháng 10 (chưa duyệt) thì không
        $this->actingAs($this->sale)->get(route('portal.my-salary', ['period_id' => $september->id]))->assertOk()->assertSee('Hoa hồng tuyển sinh');
        $this->actingAs($this->sale)->get(route('portal.my-salary', ['period_id' => $october->id]))->assertNotFound();
    }

    // ───────────── helpers ─────────────

    private function userWithRole(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes + ['branch_id' => $this->branch->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function makeClass(string $code, User $teacher): ClassModel
    {
        return ClassModel::create([
            'code' => $code, 'name' => "Lớp {$code}", 'course_id' => $this->course->id, 'branch_id' => $this->branch->id,
            'teacher_id' => $teacher->id, 'room' => 'P'.$code, 'max_capacity' => 12, 'tuition_fee' => 9000000,
            'status' => 'active', 'start_date' => '2026-07-01', 'end_date' => '2026-12-31',
        ]);
    }

    private function makeSession(ClassModel $class, string $date, User $teacher): ClassSession
    {
        return ClassSession::create([
            'class_id' => $class->id, 'branch_id' => $this->branch->id, 'date' => $date, 'shift_name' => 'Ca chiều',
            'start_time' => '17:30', 'end_time' => '19:00', 'room' => $class->room, 'teacher_id' => $teacher->id, 'status' => 'scheduled',
        ]);
    }

    private function at(string $moment): void
    {
        $this->travelTo(Carbon::parse($moment));
    }

    private function rate(int $amount, string $from): array
    {
        return ['user_id' => $this->partTime->id, 'hourly_rate' => $amount, 'rate_unit' => 'session', 'effective_from' => $from];
    }

    private function violation(User $user, string $category, string $type = 'Đến muộn > 15 phút không báo trước', ?string $date = null): array
    {
        return [
            'user_id' => $user->id, 'error_category' => $category, 'violation_type' => $type,
            'violation_date' => $date ?? now()->toDateString(), 'class_id' => $this->classPt->id,
        ];
    }

    /** Sale nhập khách → Học vụ chuyển "Đang tư vấn" → Sale Chốt & Xếp lớp (đã đóng học phí, tiền mặt). */
    private function closeCustomer(string $phone, string $name): CrmCustomer
    {
        $this->actingAs($this->sale)->post(route('crm.customers.store'), [
            'name' => $name, 'phone' => $phone, 'parent_name' => 'PH '.$name, 'parent_phone' => '09883000'.substr($phone, -2),
            'source' => 'Bạn bè giới thiệu', 'branch_id' => $this->branch->id, 'deal_value' => 9000000,
        ])->assertSessionHasNoErrors();
        $customer = CrmCustomer::where('phone_normalized', $phone)->firstOrFail();
        $this->actingAs($this->sale)->post(route('crm.customers.next-stage', $customer->id))->assertForbidden();
        $this->actingAs($this->academic)->post(route('crm.customers.next-stage', $customer->id))->assertSessionHasNoErrors();
        $this->actingAs($this->sale)->post(route('crm.closing-wizard.store'), [
            'customer_id' => $customer->id, 'class_id' => $this->classPt->id, 'fee_paid_at_closing' => 1,
            'paid_amount' => 9000000, 'payment_method' => 'cash',
        ])->assertSessionHasNoErrors();

        return tap($customer->refresh(), fn (CrmCustomer $c) => $this->assertSame('won', $c->stage));
    }

    private function record(PayrollPeriod $period, User $user): PayrollRecord
    {
        return PayrollRecord::where('payroll_period_id', $period->id)->where('user_id', $user->id)->firstOrFail();
    }

    /** BA chốt: phải chốt KPI mọi nhân sự trước khi chốt bảng lương — đánh dấu KPI đã chốt (không đổi số tiền đã tính). */
    private function finalizeKpi(\App\Models\PayrollPeriod $period): void
    {
        foreach ($period->records()->get() as $record) {
            if ($record->kpi_state[0] !== 'pending') {
                continue;
            }
            match ($record->kpi_source) {
                \App\Models\PayrollRecord::KPI_RETENTION => $record->forceFill(['retention_tier' => 0])->saveQuietly(),
                \App\Models\PayrollRecord::KPI_ACADEMIC => $record->forceFill(['kpi_score' => 0])->saveQuietly(),
                default => $record->forceFill(['kpi_manual_amount' => 0])->saveQuietly(),
            };
        }
    }
}
