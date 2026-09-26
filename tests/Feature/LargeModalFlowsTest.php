<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\ClassReport;
use App\Models\Course;
use App\Models\CrmCustomer;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\SupportTicket;
use App\Models\TicketMessage;
use App\Models\TuitionReceipt;
use App\Models\User;
use App\Models\WorkTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Sprint IX-3 — "Modal lớn" (docs/frontend-interaction-redesign.md §3): 11 form + 3 modal xem nhanh.
 * Cùng route: request thường → trang đầy đủ như cũ; HX-Request → fragment modal; lỗi validate / nghiệp vụ → 422 trong modal;
 * lưu xong → 204 + HX-Trigger (close-modal, toast, sự kiện làm mới). Phân quyền / phạm vi dữ liệu giữ nguyên.
 */
class LargeModalFlowsTest extends TestCase
{
    use RefreshDatabase;

    private const HX = ['HX-Request' => 'true'];

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

    // ── GET: trang đầy đủ ↔ fragment ─────────────────────────────────────────────────────────

    /** @return array<string, array{0: callable(self): string, 1: string, 2: string}> [url, id form trong modal, chữ có trong modal] */
    public static function formPages(): array
    {
        return [
            'khách – thêm' => [fn (self $t) => route('crm.customers.create'), 'modal-customer-form', 'Thêm khách mới'],
            'khách – sửa' => [fn (self $t) => route('crm.customers.edit', $t->lead()->id), 'modal-customer-form', 'Sửa thông tin khách'],
            'giao việc' => [fn (self $t) => route('tasks.create'), 'modal-task-form', 'Giao việc mới'],
            'giao việc trợ giảng' => [fn (self $t) => route('tasks.ta-assign'), 'modal-ta-assign-form', 'Tạo lượt giao việc cho Trợ giảng'],
            'báo cáo trực lớp' => [fn (self $t) => route('tasks.class-reports.create', ['class_id' => $t->classModel()->id]), 'modal-class-report-form', 'Nộp báo cáo trực lớp'],
            'ticket – tạo' => [fn (self $t) => route('tickets.create'), 'modal-ticket-form', 'Tạo yêu cầu hỗ trợ (Ticket)'],
            'nhân sự – thêm' => [fn (self $t) => route('users.create'), 'modal-user-form', 'Thêm nhân viên mới'],
            'nhân sự – sửa' => [fn (self $t) => route('users.edit', $t->staff()), 'modal-user-form', 'Sửa thông tin nhân sự'],
            'phân quyền cá nhân' => [fn (self $t) => route('users.permissions.edit', $t->staff()), 'modal-permission-override-form', 'Phân quyền chi tiết — Giáo viên Modal'],
            'phiếu thu từ dòng học viên' => [fn (self $t) => route('tuition.receipts.create', ['tuition_id' => $t->tuition()->id]), 'modal-receipt-form', 'Lập phiếu thu học phí'],
        ];
    }

    #[DataProvider('formPages')]
    public function test_form_route_returns_full_page_normally_and_fragment_for_htmx(callable $url, string $formId, string $title): void
    {
        $url = $url($this);

        $this->actingAs($this->admin)->get($url)->assertOk()
            ->assertSee('data-sidebar', false)
            ->assertDontSee('id="'.$formId.'"', false);

        $this->actingAs($this->admin)->get($url, self::HX)->assertOk()
            ->assertHeader('Vary', 'HX-Request')
            ->assertDontSee('data-sidebar', false)
            ->assertDontSee('<html', false)
            ->assertDontSee('<script', false)
            ->assertSee($title)
            ->assertSee('id="'.$formId.'"', false)
            ->assertSee('form="'.$formId.'"', false);
    }

    /** @return array<string, array{0: callable(self): string, 1: string, 2: array<int, callable(self): string>}> [trang danh sách, sự kiện làm mới, nút mở modal (hx-get)] */
    public static function listPages(): array
    {
        return [
            'khách – danh sách' => [fn (self $t) => route('crm.customers.index'), 'crm-customers-changed', [
                fn (self $t) => route('crm.customers.create'), fn (self $t) => route('crm.customers.edit', $t->lead()->id), fn (self $t) => route('crm.customers.show', $t->lead()->id),
            ]],
            'khách – Kanban' => [fn (self $t) => route('crm.pipeline'), 'crm-customers-changed', [
                fn (self $t) => route('crm.customers.create'), fn (self $t) => route('crm.customers.show', $t->lead()->id),
            ]],
            'công việc' => [fn (self $t) => route('tasks.index', ['tab' => 'assigned']), 'tasks-changed', [
                fn (self $t) => route('tasks.create'), fn (self $t) => route('tasks.show', $t->task()->id),
            ]],
            'ticket' => [fn (self $t) => route('tickets.index'), 'tickets-changed', [
                fn (self $t) => route('tickets.create'), fn (self $t) => route('tickets.show', $t->ticket()->id),
            ]],
            'nhân sự' => [fn (self $t) => route('users.index'), 'users-changed', [
                fn (self $t) => route('users.create'), fn (self $t) => route('users.edit', $t->staff()), fn (self $t) => route('users.permissions.edit', $t->staff()),
            ]],
            'thu phí' => [fn (self $t) => route('tuition.students'), 'tuition-receipts-changed', [
                fn (self $t) => route('tuition.receipts.create', ['tuition_id' => $t->tuition()->id]),
            ]],
        ];
    }

    #[DataProvider('listPages')]
    public function test_list_page_has_refresh_region_and_modal_openers(callable $page, string $event, array $openers): void
    {
        $urls = array_map(fn (callable $opener) => $opener($this), $openers); // tạo dữ liệu trước khi mở trang

        $response = $this->actingAs($this->admin)->get($page($this))->assertOk()
            ->assertSee('hx-trigger="'.$event.' from:body"', false)
            ->assertSee('hx-disinherit="*"', false)
            ->assertSee('hx-target="#remote-modal-body"', false);
        foreach ($urls as $url) {
            $response->assertSee('hx-get="'.$url.'"', false);
        }
    }

    // ── Khách hàng (CRM) ─────────────────────────────────────────────────────────────────────

    public function test_customer_create_and_edit_modal_flow(): void
    {
        $payload = ['name' => 'Nguyễn Minh An', 'phone' => '0912345670', 'source' => 'Facebook', 'branch_id' => $this->branch->id];

        // SĐT sai → 422 trong modal, giữ dữ liệu đã nhập.
        $this->actingAs($this->admin)->post(route('crm.customers.store'), [...$payload, 'phone' => '12345'], self::HX)
            ->assertStatus(422)->assertDontSee('data-sidebar', false)
            ->assertSee('id="modal-customer-form"', false)->assertSee('value="Nguyễn Minh An"', false)
            ->assertSee('Số điện thoại không hợp lệ');
        $this->assertSame(0, CrmCustomer::count());

        $response = $this->actingAs($this->admin)->post(route('crm.customers.store'), $payload, self::HX);
        $customer = CrmCustomer::firstOrFail();
        $this->assertSaved($response, 'crm-customers-changed', "Đã thêm khách hàng Nguyễn Minh An ({$customer->code}) thành công vào Cơ sở dữ liệu!");

        $response = $this->actingAs($this->admin)->put(route('crm.customers.update', $customer->id), [...$payload, 'name' => 'Nguyễn Minh Anh'], self::HX);
        $this->assertSaved($response, 'crm-customers-changed', 'Cập nhật thông tin khách hàng thành công!');
        $this->assertSame('Nguyễn Minh Anh', $customer->fresh()->name);

        // Request thường giữ nguyên redirect về hồ sơ khách.
        $this->actingAs($this->admin)->post(route('crm.customers.store'), [...$payload, 'phone' => '0912345671', 'name' => 'Trần Bình'])
            ->assertRedirect(route('crm.customers.show', CrmCustomer::where('name', 'Trần Bình')->value('id')))->assertSessionHas('status');
    }

    public function test_customer_quick_view_is_a_fragment_and_keeps_data_scope(): void
    {
        $lead = $this->lead();
        $lead->histories()->create(['user_id' => $this->admin->id, 'type' => 'call', 'content' => 'Gọi tư vấn lần 1']);

        $this->actingAs($this->admin)->get(route('crm.customers.show', $lead->id))->assertOk()
            ->assertSee('data-sidebar', false)->assertDontSee('data-testid="customer-quick-view"', false);

        $this->actingAs($this->admin)->get(route('crm.customers.show', $lead->id), self::HX)->assertOk()
            ->assertHeader('Vary', 'HX-Request')
            ->assertDontSee('data-sidebar', false)
            ->assertSee('data-testid="customer-quick-view"', false)
            ->assertSee($lead->name)->assertSee('Gọi tư vấn lần 1')
            ->assertSee('Mở trang đầy đủ')
            ->assertSee('href="'.route('crm.customers.edit', $lead->id).'"', false);

        // Sales khác không thấy lead của người khác (404 như trang đầy đủ).
        $otherSale = User::factory()->create(['is_active' => true, 'branch_id' => $this->branch->id]);
        $otherSale->syncRoles(['sales_consultant']);
        $this->actingAs($otherSale)->get(route('crm.customers.show', $lead->id), self::HX)->assertNotFound();
        $this->actingAs($otherSale)->get(route('crm.customers.edit', $lead->id), self::HX)->assertNotFound();
    }

    // ── Công việc ─────────────────────────────────────────────────────────────────────────────

    public function test_task_create_modal_flow_and_ta_assign_switch(): void
    {
        $ta = $this->ta();

        // Có lựa chọn "Giao cho: Trợ giảng" → chuyển sang form giao việc theo ca (luật riêng, route riêng).
        $this->actingAs($this->admin)->get(route('tasks.create'), self::HX)->assertOk()
            ->assertSee('data-assign-mode="assistant"', false)->assertSee('href="'.route('tasks.ta-assign').'"', false);

        $this->actingAs($this->admin)->post(route('tasks.store'), ['taskTitle' => '', 'assignee' => $ta->id, 'taskType' => 'one-time'], self::HX)
            ->assertStatus(422)->assertSee('id="modal-task-form"', false)->assertSee('role="alert"', false);

        $payload = ['taskTitle' => 'Chuẩn bị phòng 101', 'assignee' => $ta->id, 'dueDate' => '2026-10-09', 'taskType' => 'one-time'];
        $this->assertSaved($this->actingAs($this->admin)->post(route('tasks.store'), $payload, self::HX),
            'tasks-changed', "Đã giao việc 'Chuẩn bị phòng 101' thành công cho nhân sự!");

        $this->actingAs($this->admin)->post(route('tasks.store'), [...$payload, 'taskTitle' => 'Việc 2'])
            ->assertRedirect(route('tasks.index'))->assertSessionHas('success');

        // Giao việc trợ giảng trong modal (4xl): lỗi → 422 (màn cha tasks.ta-assign), đúng → 204.
        $this->actingAs($this->admin)->get(route('tasks.ta-assign'), self::HX)->assertOk()->assertSee("size = '4xl'", false);
        $this->actingAs($this->admin)->post(route('tasks.ta-assign.store'), ['assign_date' => '2026-10-08', 'tasks' => [['category' => 'before', 'content' => 'Photo đề']]], self::HX)
            ->assertStatus(422)->assertSee('id="modal-ta-assign-form"', false)->assertSee('Vui lòng chọn trợ giảng.');
        $response = $this->actingAs($this->admin)->post(route('tasks.ta-assign.store'), [
            'assistant_id' => $ta->id, 'assign_date' => '2026-10-08', 'tasks' => [['category' => 'before', 'content' => 'Photo đề']],
        ], self::HX);
        $this->assertSaved($response, 'tasks-changed', 'Đã tạo thành công 1 nhiệm vụ cho Trợ giảng!');
    }

    public function test_task_quick_view_and_status_change_in_modal(): void
    {
        $task = $this->task();

        $this->actingAs($this->admin)->get(route('tasks.show', $task->id))->assertOk()
            ->assertSee('data-sidebar', false)->assertSee('Kiểm kê kho')->assertSee('data-testid="task-detail"', false);
        $this->actingAs($this->admin)->get(route('tasks.show', $task->id), self::HX)->assertOk()
            ->assertDontSee('data-sidebar', false)->assertSee('data-testid="task-detail"', false)
            ->assertSee('form="modal-task-status-form"', false);

        // Người không liên quan, phạm vi "Của tôi" → 404.
        $outsider = User::factory()->create(['is_active' => true, 'branch_id' => $this->branch->id]);
        $outsider->syncRoles(['assistant']);
        $this->actingAs($outsider)->get(route('tasks.show', $task->id), self::HX)->assertNotFound();

        // Người thực hiện chuyển "Bị chặn" thiếu lý do → 422 ngay trong modal; có lý do → 204 + làm mới danh sách.
        $assignee = $task->assignee;
        $this->actingAs($assignee)->post(route('tasks.status.update', $task->id), ['status' => 'blocked'], self::HX)
            ->assertStatus(422)->assertSee('Vui lòng nhập lý do khiến công việc bị chặn.')->assertSee('data-testid="task-detail"', false);
        $this->assertSame('new', $task->fresh()->status);
        $this->assertSaved($this->actingAs($assignee)->post(route('tasks.status.update', $task->id), ['status' => 'blocked', 'reason' => 'Thiếu chìa khóa kho'], self::HX),
            'tasks-changed', 'Đã cập nhật trạng thái công việc thành công!');
        $this->assertSame('blocked', $task->fresh()->status);

        // Request thường giữ redirect back + lỗi session.
        $this->actingAs($assignee)->from(route('tasks.index'))->post(route('tasks.status.update', $task->id), ['status' => 'canceled'])
            ->assertRedirect(route('tasks.index'))->assertSessionHasErrors('status');
    }

    public function test_class_report_modal_flow(): void
    {
        $ta = $this->ta();
        $class = $this->classModel();

        $this->actingAs($ta)->get(route('tasks.class-reports.create', ['class_id' => $class->id]), self::HX)->assertOk()
            ->assertSee('enctype="multipart/form-data"', false)
            ->assertSee('this.form.requestSubmit()', false);

        $this->actingAs($ta)->post(route('tasks.class-reports.store'), ['class_id' => $class->id, 'session_name' => 'Buổi 5'], self::HX)
            ->assertStatus(422)->assertSee('id="modal-class-report-form"', false)->assertSee('Vui lòng nhập &quot;Hôm nay học gì&quot;.', false);

        $response = $this->actingAs($ta)->post(route('tasks.class-reports.store'), [
            'class_id' => $class->id, 'session_name' => 'Buổi 5', 'hom_nay_hoc_gi' => 'Listening part 2',
        ], self::HX);
        $response->assertNoContent();
        $triggers = $this->triggers($response);
        $this->assertTrue($triggers['tasks-changed']);
        $this->assertStringStartsWith('Đã nộp báo cáo trực lớp (không có ảnh)', $triggers['toast']['message']);
        $this->assertSame(1, ClassReport::count());

        $this->actingAs($ta)->post(route('tasks.class-reports.store'), ['class_id' => $class->id, 'session_name' => 'Buổi 6', 'hom_nay_hoc_gi' => 'Reading'])
            ->assertRedirect(route('portal.ta-tasks'))->assertSessionHas('success');

        // Cổng TA: khối nhiệm vụ tự làm mới, nút "Báo cáo" mở modal.
        $this->actingAs($ta)->get(route('portal.ta-tasks'))->assertOk()
            ->assertSee('hx-trigger="tasks-changed from:body"', false)
            ->assertSee('hx-get="'.route('tasks.class-reports.create').'"', false);
    }

    // ── Ticket hỗ trợ ────────────────────────────────────────────────────────────────────────

    public function test_ticket_create_modal_flow(): void
    {
        $this->actingAs($this->admin)->post(route('tickets.store'), ['title' => 'Lỗi in hóa đơn', 'category' => 'tuition', 'priority' => 'high'], self::HX)
            ->assertStatus(422)->assertSee('id="modal-ticket-form"', false)->assertSee('value="Lỗi in hóa đơn"', false)
            ->assertSee('<option value="tuition" selected', false);

        $response = $this->actingAs($this->admin)->post(route('tickets.store'), [
            'title' => 'Lỗi in hóa đơn', 'category' => 'tuition', 'priority' => 'high', 'description' => 'Bấm in bị trắng trang.',
        ], self::HX);
        $ticket = SupportTicket::firstOrFail();
        $this->assertSaved($response, 'tickets-changed', "Đã tạo phiếu yêu cầu hỗ trợ / báo lỗi {$ticket->code} thành công!");
    }

    public function test_ticket_show_modal_with_reply_refreshing_conversation(): void
    {
        $ticket = $this->ticket();

        $this->actingAs($this->admin)->get(route('tickets.show', $ticket->id), self::HX)->assertOk()
            ->assertDontSee('data-sidebar', false)
            ->assertSee('data-testid="ticket-conversation"', false)
            ->assertSee('id="modal-ticket-reply-form"', false)
            ->assertSee('x-data="attachmentUploader"', false)
            ->assertSee('Máy chiếu phòng 2 không lên hình');

        // Gửi phản hồi trống → 422, hội thoại hiện lại kèm lỗi (tickets.messages.store → màn chi tiết tickets.show).
        $this->actingAs($this->admin)->post(route('tickets.messages.store', $ticket->id), ['message' => ''], self::HX)
            ->assertStatus(422)->assertSee('data-testid="ticket-conversation"', false)
            ->assertSee('Vui lòng nhập Nội dung phản hồi.');

        // Gửi phản hồi → 200, nội dung modal là hội thoại mới + toast + làm mới danh sách.
        $response = $this->actingAs($this->admin)->post(route('tickets.messages.store', $ticket->id), ['message' => 'Đã thay dây HDMI.'], self::HX);
        $response->assertOk()->assertSee('data-testid="ticket-conversation"', false)->assertSee('Đã thay dây HDMI.');
        $triggers = $this->triggers($response);
        $this->assertSame(['message' => 'Đã gửi phản hồi thành công!', 'type' => 'success'], $triggers['toast']);
        $this->assertTrue($triggers['tickets-changed']);
        $this->assertArrayNotHasKey('close-modal', $triggers);
        $this->assertSame(2, TicketMessage::count());
        $this->assertSame('in_progress', $ticket->fresh()->status);

        // Request thường giữ redirect back.
        $this->actingAs($this->admin)->from(route('tickets.show', $ticket->id))
            ->post(route('tickets.messages.store', $ticket->id), ['message' => 'OK'])
            ->assertRedirect(route('tickets.show', $ticket->id))->assertSessionHas('status', 'Đã gửi phản hồi thành công!');

        // Người ngoài luồng ticket vẫn bị chặn.
        $outsider = User::factory()->create(['is_active' => true, 'branch_id' => $this->branch->id]);
        $outsider->syncRoles(['teacher']);
        $this->actingAs($outsider)->get(route('tickets.show', $ticket->id), self::HX)->assertForbidden();
        $this->actingAs($outsider)->post(route('tickets.messages.store', $ticket->id), ['message' => 'x'], self::HX)->assertForbidden();
    }

    // ── Nhân sự ──────────────────────────────────────────────────────────────────────────────

    public function test_user_form_has_three_tabs_and_error_tab_is_selected(): void
    {
        $this->actingAs($this->admin)->get(route('users.create'), self::HX)->assertOk()
            ->assertSee('data-tab="account" aria-selected="true"', false)
            ->assertSee('data-tab="profile" aria-selected="false"', false)
            ->assertSee('data-tab="salary" aria-selected="false"', false)
            ->assertSee('enctype="multipart/form-data"', false);

        $valid = ['name' => 'Lê Thu Hà', 'email' => 'ha.le@menglish.test', 'branch_id' => $this->branch->id, 'role' => 'teacher', 'password' => 'Secret@123'];

        // Lỗi ở tab Tài khoản.
        $this->actingAs($this->admin)->post(route('users.store'), [...$valid, 'email' => 'khong-hop-le'], self::HX)
            ->assertStatus(422)->assertSee('id="modal-user-form"', false)
            ->assertSee('data-tab="account" aria-selected="true"', false);

        // Chỉ lỗi ở tab Lương → tab Lương được chọn sẵn.
        $this->actingAs($this->admin)->post(route('users.store'), [...$valid, 'base_salary' => -5], self::HX)
            ->assertStatus(422)
            ->assertSee('data-tab="salary" aria-selected="true"', false)
            ->assertSee('data-tab="account" aria-selected="false"', false)
            ->assertSee('value="Lê Thu Hà"', false);
        $this->assertFalse(User::where('email', 'ha.le@menglish.test')->exists());

        $this->assertSaved($this->actingAs($this->admin)->post(route('users.store'), $valid, self::HX), 'users-changed', 'Đã tạo tài khoản thành công.');
        $created = User::where('email', 'ha.le@menglish.test')->firstOrFail();

        $response = $this->actingAs($this->admin)->put(route('users.update', $created), [...$valid, 'password' => '', 'hometown' => 'Nam Định'], self::HX);
        $this->assertSaved($response, 'users-changed', 'Đã cập nhật tài khoản thành công.');
        $this->assertSame('Nam Định', $created->fresh()->hometown);

        $this->actingAs($this->admin)->put(route('users.update', $created), [...$valid, 'password' => ''])
            ->assertRedirect(route('users.index'))->assertSessionHas('status', 'Đã cập nhật tài khoản thành công.');
    }

    public function test_user_permissions_modal_flow_and_role_links(): void
    {
        $staff = $this->staff();

        $response = $this->actingAs($this->admin)->put(route('users.permissions.update', $staff), ['overrides' => ['lead' => ['view' => 'allow']]], self::HX);
        $this->assertSaved($response, 'users-changed', 'Đã cập nhật phân quyền chi tiết của Giáo viên Modal.');
        $this->assertTrue($staff->fresh()->can('lead.view'));

        $this->actingAs($this->admin)->put(route('users.permissions.update', $staff), ['overrides' => []])
            ->assertRedirect(route('users.index'))->assertSessionHas('status');

        // Không tự phân quyền cho chính mình (403 cả khi gọi từ modal).
        $manager = User::factory()->create(['is_active' => true, 'branch_id' => $this->branch->id]);
        $manager->givePermissionTo('permission.override');
        $this->actingAs($manager)->get(route('users.permissions.edit', $manager), self::HX)->assertForbidden();

        // IX-2 còn lại: link gán vai trò ở users.show / trang phân quyền mở modal.
        $this->actingAs($this->admin)->get(route('users.show', $staff))->assertOk()
            ->assertSee('hx-get="'.route('users.roles.edit', $staff).'"', false)
            ->assertSee('hx-trigger="users-changed from:body"', false);
        $this->actingAs($this->admin)->get(route('users.permissions.edit', $staff))->assertOk()
            ->assertSee('hx-get="'.route('users.roles.edit', $staff).'"', false)
            ->assertSee('x-on:users-changed.window', false);
    }

    // ── Phiếu thu học phí ────────────────────────────────────────────────────────────────────

    public function test_receipt_modal_from_student_row(): void
    {
        $tuition = $this->tuition();

        // "Lập phiếu thu mới" (lập tự do) vẫn là trang riêng; dòng học viên mở modal 4xl.
        $this->actingAs($this->admin)->get(route('tuition.students'))->assertOk()
            ->assertDontSee('hx-get="'.route('tuition.receipts.create').'"', false)
            ->assertSee('data-modal-size="4xl"', false);

        // JS form nằm trong module (Alpine.data), không còn <script> inline.
        $this->actingAs($this->admin)->get(route('tuition.receipts.create', ['tuition_id' => $tuition->id]))->assertOk()
            ->assertSee('x-data="createReceiptManager(', false)
            ->assertDontSee('function createReceiptManager', false);
        $this->actingAs($this->admin)->get(route('tuition.receipts.create', ['tuition_id' => $tuition->id]), self::HX)->assertOk()
            ->assertSee('x-data="createReceiptManager(', false)
            ->assertSee('name="submit_action" value="draft"', false)
            ->assertSee('enctype="multipart/form-data"', false);

        $payload = ['student_tuition_id' => $tuition->id, 'student_id' => $tuition->student_id, 'amount' => 4500000, 'tuition_amount' => 4500000,
            'payment_method' => 'cash', 'submit_action' => 'submit'];

        // Lỗi validate và lỗi nghiệp vụ (trước đây back()->withErrors) → 422 ngay trong modal, giữ khoản học phí đã chọn.
        $this->actingAs($this->admin)->post(route('tuition.receipts.store'), [...$payload, 'amount' => 500], self::HX)
            ->assertStatus(422)->assertSee('id="modal-receipt-form"', false)->assertDontSee('data-sidebar', false);
        $this->actingAs($this->admin)->post(route('tuition.receipts.store'), [...$payload, 'amount' => 4550000, 'surcharge_amount' => 50000], self::HX)
            ->assertStatus(422)->assertSee('Bắt buộc nhập lý do khi có số tiền phụ thu.')
            ->assertSee('&#039;'.$tuition->id.'&#039;, &#039;'.$tuition->student_id.'&#039;', false); // khoản học phí + học viên vẫn chọn sẵn
        $this->assertSame(0, TuitionReceipt::count());

        $response = $this->actingAs($this->admin)->post(route('tuition.receipts.store'), $payload, self::HX);
        $receipt = TuitionReceipt::firstOrFail();
        $this->assertSaved($response, 'tuition-receipts-changed', "Đã gửi duyệt phiếu thu {$receipt->receipt_number} (Số tiền: 4.500.000 VNĐ) lên cấp Quản lý / Kế toán!");
        $this->assertSame(TuitionReceipt::STATUS_PENDING, $receipt->status);

        // Request thường: lỗi nghiệp vụ vẫn redirect back kèm lỗi + dữ liệu cũ.
        $this->actingAs($this->admin)->from(route('tuition.students'))
            ->post(route('tuition.receipts.store'), [...$payload, 'amount' => 4550000, 'surcharge_amount' => 50000])
            ->assertRedirect(route('tuition.students'))->assertSessionHasErrors('surcharge_reason')->assertSessionHasInput('amount');
    }

    // ── Hạ tầng: modal xem nhanh đẩy URL, Back đóng modal ───────────────────────────────────

    public function test_remote_modal_script_handles_push_url_and_back_button(): void
    {
        $js = file_get_contents(resource_path('js/components/remote-modal.js'));

        $this->assertStringContainsString("getAttribute?.('hx-push-url') === 'true'", $js);
        $this->assertStringContainsString('history.pushState({ remoteModal:', $js);
        $this->assertStringContainsString("window.addEventListener('popstate'", $js);
        $this->assertStringContainsString('history.back()', $js);
        $this->assertStringContainsString('historyCacheSize = 0', $js);
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

    public function ticket(): SupportTicket
    {
        return SupportTicket::query()->where('title', 'Máy chiếu hỏng')->first() ?? tap(SupportTicket::create([
            'code' => SupportTicket::generateCode(), 'title' => 'Máy chiếu hỏng', 'category' => 'technical_issue', 'priority' => 'medium',
            'description' => 'Máy chiếu phòng 2 không lên hình', 'creator_id' => $this->staff()->id, 'status' => 'open',
        ]), fn (SupportTicket $t) => TicketMessage::create([
            'support_ticket_id' => $t->id, 'user_id' => $t->creator_id, 'message' => $t->description, 'is_internal_note' => false,
        ]));
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

    private function assertSaved(TestResponse $response, string $event, string $message): void
    {
        $response->assertNoContent();
        $triggers = $this->triggers($response);
        $this->assertTrue($triggers['close-modal']);
        $this->assertTrue($triggers[$event]);
        $this->assertSame(['message' => $message, 'type' => 'success'], $triggers['toast']);
    }

    private function triggers(TestResponse $response): array
    {
        return json_decode($response->headers->get('HX-Trigger'), true, flags: JSON_THROW_ON_ERROR);
    }
}
