<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\ClassReport;
use App\Models\Course;
use App\Models\CrmCustomer;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\TuitionReceipt;
use App\Models\User;
use App\Models\WorkTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\InteractsWithInertia;
use Tests\TestCase;

/**
 * Sprint IX-3 — "Modal lớn" (docs/frontend-interaction-redesign.md §3): 11 form + 3 modal xem nhanh.
 * (Ticket hỗ trợ đã chuyển sang Vue/Inertia — xem SupportTicketModalTest.)
 * Cùng route: request thường → trang đầy đủ như cũ; X-Remote-Modal → trang trong modal chung (prop asModal);
 * lỗi validate / nghiệp vụ hiện trong modal; lưu xong quay lại trang đang mở kèm thông báo. Phân quyền / phạm vi dữ liệu giữ nguyên.
 */
class LargeModalFlowsTest extends TestCase
{
    use InteractsWithInertia;
    use RefreshDatabase;

    private User $admin;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-07 09:00:00'));

        $this->branch = Branch::create(['name' => 'Cầu Giấy', 'code' => 'CG-LM', 'is_active' => true]);
        $this->admin = User::factory()->create(['name' => 'Quản trị viên', 'is_active' => true, 'branch_id' => $this->branch->id]);
        $this->admin->assignRole('admin');
    }

    // ── Khách hàng (CRM) ─────────────────────────────────────────────────────────────────────

    public function test_customer_create_opens_as_page_or_modal_and_lists_link_to_it(): void
    {
        // Danh sách / Kanban (trang Vue) có nút mở form "Thêm khách mới" (modal Inertia, cùng URL với trang đầy đủ).
        $create = route('crm.customers.create', absolute: false).'"';
        $this->actingAs($this->admin)->get(route('crm.customers.index'))->assertOk()->assertSee($create, false);
        $this->actingAs($this->admin)->get(route('crm.pipeline'))->assertOk()->assertSee($create, false);

        $this->actingAs($this->admin)->get(route('crm.customers.create'))->assertOk()
            ->assertSee('data-sidebar', false)->assertSee('Thêm khách mới')->assertSee('id="add-lead-form"', false);
        $this->actingAs($this->admin)->get(route('crm.customers.create'), self::MODAL)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Crm/Customers/Create')->where('asModal', true));
    }

    public function test_customer_create_and_edit_modal_flow(): void
    {
        $payload = ['name' => 'Nguyễn Minh An', 'phone' => '0912345670', 'source' => 'Facebook', 'branch_id' => $this->branch->id];

        // SĐT sai → quay lại trang đang mở kèm lỗi (hiện ngay trong modal), không tạo khách.
        $this->actingAs($this->admin)->from(route('crm.customers.index'))
            ->post(route('crm.customers.store'), [...$payload, 'phone' => '12345'], self::MODAL)
            ->assertRedirect(route('crm.customers.index'))
            ->assertSessionHasErrors('phone');
        $this->assertStringContainsString('Số điện thoại không hợp lệ', session('errors')->first('phone'));
        $this->assertSame(0, CrmCustomer::count());

        // Lưu từ modal → về lại trang đang mở kèm thông báo (modal đóng, danh sách có dữ liệu mới).
        $this->actingAs($this->admin)->from(route('crm.pipeline'))
            ->post(route('crm.customers.store'), $payload, self::MODAL)
            ->assertRedirect(route('crm.pipeline'));
        $customer = CrmCustomer::firstOrFail();
        $this->assertSame("Đã thêm khách hàng Nguyễn Minh An ({$customer->short_code}) thành công vào Cơ sở dữ liệu!", session('status'));

        // Sửa trong tab "Thông tin khách hàng" (form trên trang hồ sơ).
        $this->actingAs($this->admin)->put(route('crm.customers.update', $customer->id), [...$payload, 'name' => 'Nguyễn Minh Anh'])
            ->assertRedirect(route('crm.customers.show', ['id' => $customer->id, 'tab' => 'info']))
            ->assertSessionHas('status', 'Cập nhật thông tin khách hàng thành công!');
        $this->assertSame('Nguyễn Minh Anh', $customer->fresh()->name);

        // Request thường giữ nguyên redirect về hồ sơ khách.
        $this->actingAs($this->admin)->post(route('crm.customers.store'), [...$payload, 'phone' => '0912345671', 'name' => 'Trần Bình'])
            ->assertRedirect(route('crm.customers.show', CrmCustomer::where('name', 'Trần Bình')->value('id')))->assertSessionHas('status');
    }

    public function test_customer_profile_is_the_single_full_page_and_keeps_data_scope(): void
    {
        $lead = $this->lead();

        // Hồ sơ đầy đủ có form sửa trực tiếp (tab "Thông tin khách hàng"); không còn modal xem nhanh.
        $this->actingAs($this->admin)->get(route('crm.customers.show', ['id' => $lead->id, 'tab' => 'info']))->assertOk()
            ->assertSee('data-sidebar', false)->assertSee('Thông tin khách hàng')
            ->assertSee('id="edit-lead-form"', false)
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Crm/Customers/Show')->where('tab', 'info'));

        // URL sửa cũ → chuyển sang trang đầy đủ.
        $this->actingAs($this->admin)->get(route('crm.customers.edit', $lead->id))
            ->assertRedirect(route('crm.customers.show', ['id' => $lead->id, 'tab' => 'info']));

        // Sales khác không thấy lead của người khác.
        $otherSale = User::factory()->create(['is_active' => true, 'branch_id' => $this->branch->id]);
        $otherSale->syncRoles(['sales_consultant']);
        $this->actingAs($otherSale)->get(route('crm.customers.show', $lead->id))->assertNotFound();
        $this->actingAs($otherSale)->get(route('crm.customers.edit', $lead->id))->assertNotFound();
    }

    // ── Công việc ─────────────────────────────────────────────────────────────────────────────

    /** Công việc đã chuyển sang Vue (Inertia): modal = header X-Remote-Modal; lưu xong quay lại trang đang mở kèm thông báo. */
    public function test_task_create_modal_flow_and_ta_assign_switch(): void
    {
        $ta = $this->ta();

        // Có lựa chọn "Giao cho: Trợ giảng" → chuyển sang form giao việc theo ca (luật riêng, route riêng).
        $this->actingAs($this->admin)->get(route('tasks.create'))->assertOk()
            ->assertSee('data-sidebar', false)
            ->assertSee('data-assign-mode="assistant"', false)->assertSee('href="'.route('tasks.ta-assign', absolute: false).'"', false);
        $this->actingAs($this->admin)->get(route('tasks.create'), self::MODAL)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Tasks/Create')->where('asModal', true)->where('canTaAssign', true)->where('title', 'Giao việc mới'));

        $this->actingAs($this->admin)->from(route('tasks.index'))->post(route('tasks.store'), ['taskTitle' => '', 'assignee' => $ta->id, 'taskType' => 'one-time'], self::MODAL)
            ->assertRedirect(route('tasks.index'))->assertSessionHasErrors(['taskTitle', 'dueDate']);

        $payload = ['taskTitle' => 'Chuẩn bị phòng 101', 'assignee' => $ta->id, 'dueDate' => '2026-10-09', 'taskType' => 'one-time'];
        $this->actingAs($this->admin)->from(route('tasks.index', ['tab' => 'assigned']))->post(route('tasks.store'), $payload, self::MODAL)
            ->assertRedirect(route('tasks.index', ['tab' => 'assigned']))
            ->assertSessionHas('success', "Đã giao việc 'Chuẩn bị phòng 101' thành công cho nhân sự!");

        $this->actingAs($this->admin)->post(route('tasks.store'), [...$payload, 'taskTitle' => 'Việc 2'])
            ->assertRedirect(route('tasks.index'))->assertSessionHas('success');

        // Giao việc trợ giảng trong modal (4xl): lỗi → về lại kèm lỗi, đúng → quay lại trang đang mở.
        $this->actingAs($this->admin)->get(route('tasks.ta-assign'), self::MODAL)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Tasks/TaAssign')->where('asModal', true)->has('assistants', 1));
        $this->actingAs($this->admin)->get(route('tasks.ta-assign'))->assertOk()
            ->assertSee('Tạo lượt giao việc cho Trợ giảng')->assertSee('id="ta-assign-form"', false);
        $this->actingAs($this->admin)->from(route('tasks.index'))->post(route('tasks.ta-assign.store'), ['assign_date' => '2026-10-08', 'tasks' => [['category' => 'before', 'content' => 'Photo đề']]], self::MODAL)
            ->assertRedirect(route('tasks.index'))->assertSessionHasErrors(['assistant_id' => 'Vui lòng chọn trợ giảng.']);
        $this->actingAs($this->admin)->from(route('tasks.index'))->post(route('tasks.ta-assign.store'), [
            'assistant_id' => $ta->id, 'assign_date' => '2026-10-08', 'tasks' => [['category' => 'before', 'content' => 'Photo đề']],
        ], self::MODAL)->assertRedirect(route('tasks.index'))->assertSessionHas('success', 'Đã tạo thành công 1 nhiệm vụ cho Trợ giảng!');
    }

    public function test_task_quick_view_and_status_change_in_modal(): void
    {
        $task = $this->task();

        $this->actingAs($this->admin)->get(route('tasks.show', $task->id))->assertOk()
            ->assertSee('data-sidebar', false)->assertSee('Kiểm kê kho')->assertSee('data-testid="task-detail"', false)
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Tasks/Show')->where('asModal', false)->where('task.title', 'Kiểm kê kho'));
        $this->actingAs($this->admin)->get(route('tasks.show', $task->id), self::MODAL)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Tasks/Show')->where('asModal', true)->where('task.id', $task->id));

        // Người không liên quan, phạm vi "Của tôi" → 404.
        $outsider = User::factory()->create(['is_active' => true, 'branch_id' => $this->branch->id]);
        $outsider->syncRoles(['assistant']);
        $this->actingAs($outsider)->get(route('tasks.show', $task->id), self::MODAL)->assertNotFound();

        // Người thực hiện chuyển "Bị chặn" thiếu lý do → lỗi hiện lại trong modal; có lý do → lưu, quay lại danh sách.
        $assignee = $task->assignee;
        $this->actingAs($assignee)->get(route('tasks.show', $task->id), self::MODAL)
            ->assertInertia(fn (AssertableInertia $page) => $page->where('allowed.0.value', 'in_progress'));
        $this->actingAs($assignee)->from(route('tasks.index'))->post(route('tasks.status.update', $task->id), ['status' => 'blocked'], self::MODAL)
            ->assertRedirect(route('tasks.index'))->assertSessionHasErrors(['reason' => 'Vui lòng nhập lý do khiến công việc bị chặn.']);
        $this->assertSame('new', $task->fresh()->status);
        $this->actingAs($assignee)->from(route('tasks.index'))->post(route('tasks.status.update', $task->id), ['status' => 'blocked', 'reason' => 'Thiếu chìa khóa kho'], self::MODAL)
            ->assertRedirect(route('tasks.index'))->assertSessionHas('success', 'Đã cập nhật trạng thái công việc thành công!');
        $this->assertSame('blocked', $task->fresh()->status);

        // Request thường giữ redirect back + lỗi session.
        $this->actingAs($assignee)->from(route('tasks.index'))->post(route('tasks.status.update', $task->id), ['status' => 'canceled'])
            ->assertRedirect(route('tasks.index'))->assertSessionHasErrors('status');
    }

    public function test_class_report_modal_flow(): void
    {
        $ta = $this->ta();
        $class = $this->classModel();

        $this->actingAs($ta)->get(route('tasks.class-reports.create', ['class_id' => $class->id]), self::MODAL)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Tasks/ClassReportCreate')->where('asModal', true)->where('selectedClassId', $class->id));
        $this->actingAs($ta)->get(route('tasks.class-reports.create', ['class_id' => $class->id]))->assertOk()
            ->assertSee('id="class-report-form"', false)->assertSee('name="board_images[]"', false);

        $this->actingAs($ta)->from(route('portal.ta-tasks'))->post(route('tasks.class-reports.store'), ['class_id' => $class->id, 'session_name' => 'Buổi 5'], self::MODAL)
            ->assertRedirect(route('portal.ta-tasks'))->assertSessionHasErrors(['hom_nay_hoc_gi' => 'Vui lòng nhập "Hôm nay học gì".']);

        $response = $this->actingAs($ta)->from(route('portal.ta-tasks'))->post(route('tasks.class-reports.store'), [
            'class_id' => $class->id, 'session_name' => 'Buổi 5', 'hom_nay_hoc_gi' => 'Listening part 2',
        ], self::MODAL);
        $response->assertRedirect(route('portal.ta-tasks'));
        $this->assertStringStartsWith('Đã nộp báo cáo trực lớp (không có ảnh)', session('success'));
        $this->assertSame(1, ClassReport::count());

        $this->actingAs($ta)->post(route('tasks.class-reports.store'), ['class_id' => $class->id, 'session_name' => 'Buổi 6', 'hom_nay_hoc_gi' => 'Reading'])
            ->assertRedirect(route('portal.ta-tasks'))->assertSessionHas('success');

        // Cổng TA: nút "Báo cáo" mở form nộp báo cáo trong modal.
        $this->actingAs($ta)->get(route('portal.ta-tasks'))->assertOk()
            ->assertSee('href="'.route('tasks.class-reports.create', absolute: false).'" data-modal-size="2xl"', false);
    }

    // ── Phiếu thu học phí ────────────────────────────────────────────────────────────────────

    public function test_receipt_modal_from_student_row(): void
    {
        $tuition = $this->tuition();

        // "Lập phiếu thu mới" (lập tự do) vẫn là trang riêng; dòng học viên mở modal 4xl (nút <UiButton modal="4xl">).
        $this->actingAs($this->admin)->get(route('tuition.students'))->assertOk()
            ->assertSee('href="'.e(route('tuition.receipts.create', ['tuition_id' => $tuition->id], false)).'"', false)
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Tuition/Students'));

        // Mở thẳng URL → trang đầy đủ; từ dòng học viên (X-Remote-Modal) → nội dung modal, khoản học phí + học viên chọn sẵn.
        $this->actingAs($this->admin)->get(route('tuition.receipts.create', ['tuition_id' => $tuition->id]))->assertOk()
            ->assertSee('data-sidebar', false)
            ->assertSee('Lập phiếu thu học phí')
            ->assertSee('name="submit_action"', false)
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Tuition/ReceiptForm')->where('asModal', false));
        $this->actingAs($this->admin)->get(route('tuition.receipts.create', ['tuition_id' => $tuition->id]), self::MODAL)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Tuition/ReceiptForm')
                ->where('asModal', true)
                ->where('initialTuitionId', (string) $tuition->id)
                ->where('initialStudentId', (string) $tuition->student_id));

        $payload = ['student_tuition_id' => $tuition->id, 'student_id' => $tuition->student_id, 'amount' => 4500000, 'tuition_amount' => 4500000,
            'payment_method' => 'cash', 'paper_invoice_number' => 'HDG-0001', 'submit_action' => 'submit'];

        // Lỗi validate và lỗi nghiệp vụ từ modal → quay lại trang đang mở kèm lỗi + dữ liệu đã nhập (modal giữ nguyên, khoản đã chọn).
        $this->actingAs($this->admin)->from(route('tuition.students'))
            ->post(route('tuition.receipts.store'), [...$payload, 'amount' => 500], self::MODAL)
            ->assertRedirect(route('tuition.students'))->assertSessionHasErrors();
        $this->actingAs($this->admin)->from(route('tuition.students'))
            ->post(route('tuition.receipts.store'), [...$payload, 'amount' => 4550000, 'surcharge_amount' => 50000], self::MODAL)
            ->assertRedirect(route('tuition.students'))
            ->assertSessionHasErrors(['surcharge_reason' => 'Bắt buộc nhập lý do khi có số tiền phụ thu.'])
            ->assertSessionHasInput('student_tuition_id');
        $this->assertSame(0, TuitionReceipt::count());

        // Lưu xong từ modal → về lại trang đang mở kèm thông báo.
        $response = $this->actingAs($this->admin)->from(route('tuition.students'))->post(route('tuition.receipts.store'), $payload, self::MODAL);
        $receipt = TuitionReceipt::firstOrFail();
        $response->assertRedirect(route('tuition.students'))
            ->assertSessionHas('status', "Đã gửi duyệt phiếu thu {$receipt->receipt_number} (Số tiền: 4.500.000 đ) lên cấp Quản lý / Kế toán!");
        $this->assertSame(TuitionReceipt::STATUS_PENDING, $receipt->status);

        // Request thường: lỗi nghiệp vụ vẫn redirect back kèm lỗi + dữ liệu cũ.
        $this->actingAs($this->admin)->from(route('tuition.students'))
            ->post(route('tuition.receipts.store'), [...$payload, 'amount' => 4550000, 'surcharge_amount' => 50000])
            ->assertRedirect(route('tuition.students'))->assertSessionHasErrors('surcharge_reason')->assertSessionHasInput('amount');
    }

    // ── Hạ tầng: modal xem nhanh — nút Back đóng modal ──────────────────────────────────────

    public function test_quick_view_modals_close_on_browser_back(): void
    {
        $js = file_get_contents(resource_path('js/lib/remoteModal.js'));

        $this->assertStringContainsString("window.addEventListener('popstate'", $js);
        $this->assertStringContainsString('event.stopImmediatePropagation()', $js);
        $this->assertStringContainsString('window.history.pushState({ ...window.history.state, remoteModal: historyEntry.id }', $js);
        $this->assertStringContainsString('window.history.back()', $js);

        $this->assertStringContainsString('modal-history>Trao đổi', file_get_contents(resource_path('js/Pages/SupportTickets/Index.vue')));
        $this->assertStringContainsString("{ size: '2xl', history: true }", file_get_contents(resource_path('js/Pages/Tasks/Index.vue')));
    }

    // ── Helpers ─────────────────────────────────────────────────────────────────────────────

    public function staff(): User
    {
        $user = User::query()->where('email', 'gv.lm@example.com')->first()
            ?? User::factory()->create(['name' => 'Giáo viên Modal', 'email' => 'gv.lm@example.com', 'is_active' => true, 'branch_id' => $this->branch->id]);
        $user->syncRoles(['teacher']);

        return $user;
    }

    public function ta(): User
    {
        $user = User::query()->where('email', 'ta.lm@example.com')->first()
            ?? User::factory()->create(['name' => 'Trợ giảng Modal', 'email' => 'ta.lm@example.com', 'is_active' => true, 'branch_id' => $this->branch->id]);
        $user->syncRoles(['assistant']);

        return $user;
    }

    public function lead(): CrmCustomer
    {
        $sale = User::query()->where('email', 'sale.lm@example.com')->first()
            ?? tap(User::factory()->create(['name' => 'Sale Modal', 'email' => 'sale.lm@example.com', 'is_active' => true, 'branch_id' => $this->branch->id]))->syncRoles(['sales_consultant']);

        return CrmCustomer::query()->firstOrCreate(['code' => 'KH-LM-1'], [
            'name' => 'Phạm Gia Bảo', 'phone' => '0987000111', 'phone_normalized' => '0987000111', 'source' => 'Facebook',
            'stage' => 'consulting', 'branch_id' => $this->branch->id, 'assigned_user_id' => $sale->id,
        ]);
    }

    public function task(): WorkTask
    {
        return WorkTask::query()->firstOrCreate(['title' => 'Kiểm kê kho'], [
            'creator_id' => $this->admin->id, 'assignee_id' => $this->staff()->id, 'branch_id' => $this->branch->id,
            'due_date' => '2026-10-09', 'task_type' => 'one_time', 'status' => 'new',
        ]);
    }

    public function classModel(): ClassModel
    {
        return ClassModel::query()->where('code', 'LM-01')->first() ?? ClassModel::create([
            'name' => 'Starters LM', 'code' => 'LM-01', 'course_id' => $this->course()->id, 'branch_id' => $this->branch->id,
            'teacher_id' => $this->staff()->id, 'assistant_id' => $this->ta()->id, 'status' => 'active',
            'start_date' => '2026-09-01', 'end_date' => '2026-12-31',
        ]);
    }

    public function tuition(): StudentTuition
    {
        $student = Student::query()->firstOrCreate(['code' => 'HV-LM-1'], [
            'name' => 'Đỗ Minh Khang', 'phone' => '0912000222', 'branch_id' => $this->branch->id,
            'current_class_id' => $this->classModel()->id, 'status' => 'studying',
        ]);

        return StudentTuition::query()->firstOrCreate(['student_id' => $student->id], [
            'class_id' => $this->classModel()->id, 'branch_id' => $this->branch->id, 'total_amount' => 9000000, 'discount_amount' => 0,
            'final_amount' => 9000000, 'paid_amount' => 0, 'debt_amount' => 9000000, 'due_date' => '2026-10-15', 'status' => 'unpaid',
        ]);
    }

    private function course(): Course
    {
        return Course::query()->firstOrCreate(['code' => 'STA-LM'], ['name' => 'Starters', 'tuition_fee' => 9000000, 'is_active' => true]);
    }
}
