<?php

namespace Tests\Feature;

use App\Models\BigTest;
use App\Models\ClassModel;
use App\Models\CrmCustomer;
use App\Models\PayrollPeriod;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\TuitionReceipt;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Nghiệm thu Phase 4 — "Toàn bộ luồng BPMN 1–22 chạy được": một lượt rút gọn trên dữ liệu demo (DatabaseSeeder, Phase 1–4),
 * mỗi bước chạm route chính bằng đúng vai trò, chỉ kiểm tra trang mở được / thao tác không lỗi. Chi tiết nghiệp vụ từng
 * phase nằm ở Phase1..4AcceptanceTest.
 */
class FullBpmnSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_condensed_walk_through_bpmn_steps_1_to_22_as_the_right_roles(): void
    {
        $this->seed(DatabaseSeeder::class);
        $u = fn (string $email) => User::where('email', $email)->firstOrFail();
        $admin = $u('admin@menglish.edu.vn');
        $manager = $u('manager@menglish.edu.vn');
        $academic = $u('nva@menglish.edu.vn');
        $lead = $u('academiclead@menglish.edu.vn');
        $sale = $u('tranmaia@menglish.edu.vn');
        $accountant = $u('ketoan2@menglish.edu.vn');
        $teacher = $u('nguyenvanan@menglish.edu.vn');
        $assistant = $u('ta.tuan@menglish.edu.vn');
        $studentUser = $u('hocvien1@menglish.edu.vn');
        $class = ClassModel::where('code', 'DEMO-CG-FAM1')->firstOrFail();
        $branchId = $class->branch_id;

        // BPMN 1 — Sale nhập khách, pipeline.
        $this->actingAs($sale)->post(route('crm.customers.store'), [
            'name' => '# Khách Smoke BPMN', 'phone' => '0356000001', 'parent_name' => 'PH Smoke', 'parent_phone' => '0356000002',
            'source' => 'Facebook Ads', 'branch_id' => $branchId, 'deal_value' => 9500000,
        ])->assertSessionHasNoErrors()->assertRedirect();
        $customer = CrmCustomer::where('phone_normalized', '0356000001')->firstOrFail();
        $this->actingAs($manager)->get(route('crm.pipeline'))->assertOk();
        $this->actingAs($sale)->get(route('crm.customers.show', $customer->id))->assertOk();

        // BPMN 2–3 — Tư vấn (CM chuyển bước), test đầu vào, học thử.
        $this->actingAs($academic)->post(route('crm.customers.next-stage', $customer->id))->assertSessionHasNoErrors();
        $this->assertSame('consulting', $customer->fresh()->stage);
        $this->actingAs($academic)->get(route('placement-tests.index'))->assertOk();
        $this->actingAs($teacher)->get(route('teacher.trial-guests', ['scope' => 'past']))->assertOk();

        // BPMN 4 — Chốt & xếp lớp sau → học viên + học phí; Học vụ xem Chờ xếp lớp / Xác nhận chính thức.
        $this->actingAs($manager)->get(route('crm.closing-wizard', ['customer_id' => $customer->id]))->assertOk();
        $this->actingAs($manager)->post(route('crm.closing-wizard.store'), [
            'customer_id' => $customer->id, 'course_id' => ClassModel::where('code', 'DEMO-CG-FAM2')->value('course_id'), 'fee_paid_at_closing' => 0,
        ])->assertSessionHasNoErrors()->assertRedirect();
        $student = Student::findOrFail($customer->fresh()->converted_student_id);
        $tuition = StudentTuition::where('student_id', $student->id)->firstOrFail();
        $this->actingAs($academic)->get(route('crm.waiting-list'))->assertOk();
        $this->actingAs($academic)->get(route('crm.confirmations'))->assertOk();

        // BPMN 5–6 — Lớp, TKB, dashboard lớp theo ngày / tuần.
        $this->actingAs($manager)->get(route('classes.index'))->assertOk();
        $this->actingAs($academic)->get(route('tasks.classes-dashboard', ['tab' => 'week']))->assertOk();
        $this->actingAs($academic)->get(route('students.show', $student->id))->assertOk();

        // BPMN 7–8 — Giáo trình theo chặng, giáo viên dạy + điểm danh buổi.
        $this->actingAs($lead)->get(route('syllabus.index'))->assertOk();
        $this->actingAs($lead)->get(route('syllabus.assignments'))->assertOk();
        $this->actingAs($teacher)->get(route('syllabus.teacher-view'))->assertOk();
        $this->actingAs($teacher)->get(route('teacher.attendance', $class->id))->assertOk();

        // BPMN 9 / 9b — Chấm công, kỷ luật.
        $this->actingAs($academic)->get(route('payroll.timesheets.teachers'))->assertOk();
        $this->actingAs($academic)->get(route('penalties.index'))->assertOk();

        // BPMN 10–13 — Bổ trợ, Big Test (kết quả, gửi PH).
        $this->actingAs($academic)->get(route('tasks.support-sessions'))->assertOk();
        $bigTest = BigTest::query()->latest('id')->firstOrFail();
        $this->actingAs($lead)->get(route('syllabus.big-tests.results', $bigTest->id))->assertOk();
        $this->actingAs($teacher)->get(route('teacher.big-test-report'))->assertOk();

        // BPMN 14 — Cổng học viên.
        $this->actingAs($studentUser)->get(route('portal.student.home2'))->assertOk();

        // BPMN 15 — Phiếu thu: CM lập, Kế toán duyệt → HĐ dải chi nhánh, công nợ giảm.
        $this->actingAs($academic)->post(route('tuition.receipts.store'), [
            'student_tuition_id' => $tuition->id, 'amount' => 2000000, 'tuition_amount' => 2000000, 'payment_method' => 'cash', 'submit_action' => 'submit',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $receipt = TuitionReceipt::where('student_tuition_id', $tuition->id)->firstOrFail();
        $this->actingAs($accountant)->get(route('tuition.receipts.approve', ['selected_id' => $receipt->id]))->assertOk();
        $this->actingAs($accountant)->post(route('tuition.receipts.approve.action', $receipt->id))->assertSessionHasNoErrors();
        $this->assertStringStartsWith('C26MCG-', (string) $receipt->fresh()->invoice_number);
        $this->assertEquals((float) $tuition->final_amount - 2000000, (float) $tuition->fresh()->debt_amount);

        // BPMN 15b — Hoàn phí / chuyển nhượng / bảo lưu / khất nợ, quá hạn.
        $this->actingAs($accountant)->get(route('tuition.refunds'))->assertOk();
        $this->actingAs($accountant)->get(route('tuition.overdue'))->assertOk();
        $this->actingAs($accountant)->get(route('tuition.invoices.cancellations', ['status' => 'all']))->assertOk();

        // BPMN 16–17 — Bảng lương, hoa hồng, "Lương của tôi".
        $this->actingAs($accountant)->get(route('payroll.periods.index'))->assertOk();
        $period = PayrollPeriod::where('status', 'approved')->firstOrFail();
        $this->actingAs($admin)->get(route('payroll.periods.show', $period->id))->assertOk();
        $this->actingAs($teacher)->get(route('portal.my-salary'))->assertOk();

        // BPMN 18–20 — Giao việc, trợ giảng, báo cáo trực lớp, ticket, thu chi.
        $this->actingAs($manager)->get(route('tasks.index'))->assertOk();
        $this->actingAs($assistant)->get(route('portal.ta-tasks'))->assertOk();
        $this->actingAs($teacher)->get(route('tasks.manual-approvals'))->assertOk();
        $this->actingAs($manager)->get(route('tickets.index'))->assertOk();
        $this->actingAs($accountant)->get(route('finance.reports.revenue'))->assertOk();
        $this->actingAs($accountant)->get(route('finance.expenses.index'))->assertOk();

        // BPMN 21 — Chăm sóc học viên (KPI tự động, danh sách học viên).
        $this->actingAs($academic)->get(route('students.index'))->assertOk();
        $this->actingAs($manager)->get(route('tasks.kpi-dashboard'))->assertOk();

        // BPMN 22 — Dashboard theo vai trò, nhật ký.
        foreach ([$admin, $manager, $lead, $academic, $accountant, $sale, $teacher] as $user) {
            $this->actingAs($user)->get(route('dashboard'))->assertOk();
        }
        $this->actingAs($admin)->get(route('activity-logs.index'))->assertOk();
    }
}
