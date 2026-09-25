<?php

namespace Tests\Feature;

use App\Models\AdminNotification;
use App\Models\Branch;
use App\Models\CrmCustomer;
use App\Models\CrmCustomerHistory;
use App\Models\PlacementTest;
use App\Models\PlacementTestSubmission;
use App\Models\Student;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\NotificationService;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 1 CRM (audit A3 / B4): SĐT, khách đã xóa, khóa hợp đồng, lịch sử sửa, cảnh báo bỏ quên,
 * pipeline (lọc, hạn liên hệ, sửa giai đoạn), chi tiết khách, danh sách chốt / không chốt, báo cáo.
 */
class Phase1CrmTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Branch $otherBranch;

    private User $admin;

    private User $manager;

    private User $academic;

    private User $sales;

    private User $otherSales;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'Cơ sở A', 'code' => 'CSA', 'is_active' => true]);
        $this->otherBranch = Branch::create(['name' => 'Cơ sở B', 'code' => 'CSB', 'is_active' => true]);
        $this->admin = $this->userWithRole('admin', $this->branch);
        $this->manager = $this->userWithRole('manager', $this->branch);
        $this->academic = $this->userWithRole('academic_staff', $this->branch);
        $this->sales = $this->userWithRole('sales_consultant', $this->branch, 'Sale Một');
        $this->otherSales = $this->userWithRole('sales_consultant', $this->otherBranch, 'Sale Hai');
    }

    // ── 1. SĐT ────────────────────────────────────────────────────────────

    public function test_customer_phone_must_be_vietnamese_format_on_create_and_update(): void
    {
        foreach (['12345', '0123 456', '09123456789', 'abc0912345678', '0112345678'] as $bad) {
            $this->actingAs($this->sales)->post(route('crm.customers.store'), $this->customerPayload(['phone' => $bad]))
                ->assertSessionHasErrors('phone');
        }
        $this->assertSame(0, CrmCustomer::count());

        $this->actingAs($this->sales)->post(route('crm.customers.store'), $this->customerPayload(['phone' => '+84 912 345 678', 'parent_phone' => '0987.654.321']))
            ->assertSessionHasNoErrors();
        $customer = CrmCustomer::firstOrFail();
        $this->assertSame('0912345678', $customer->phone_normalized);
        $this->assertSame('0987.654.321', $customer->parent_phone);

        // +84 và 0 là cùng một số → trùng.
        $this->actingAs($this->sales)->post(route('crm.customers.store'), $this->customerPayload(['phone' => '0912 345 678', 'name' => 'Trùng']))
            ->assertSessionHasErrors('phone');

        $this->actingAs($this->sales)->put(route('crm.customers.update', $customer), $this->customerPayload(['phone' => '0912']))
            ->assertSessionHasErrors('phone');
        $this->actingAs($this->sales)->put(route('crm.customers.update', $customer), $this->customerPayload(['parent_phone' => '999']))
            ->assertSessionHasErrors('parent_phone');
        $this->assertSame('0912345678', $customer->fresh()->phone_normalized);
    }

    // ── 2. Khách đã xóa ───────────────────────────────────────────────────

    public function test_deleted_customer_frees_phone_and_can_be_restored_by_manager(): void
    {
        $this->actingAs($this->manager)->post(route('crm.customers.store'), $this->customerPayload(['phone' => '0911 222 333', 'email' => 'a@example.com']))
            ->assertSessionHasNoErrors();
        $original = CrmCustomer::firstOrFail();

        $this->actingAs($this->manager)->delete(route('crm.customers.destroy', $original))->assertRedirect();
        $this->assertSoftDeleted('crm_customers', ['id' => $original->id]);
        $trashed = CrmCustomer::withTrashed()->find($original->id);
        $this->assertNull($trashed->phone_normalized);
        $this->assertNull($trashed->email);
        $this->assertSame('a@example.com', $trashed->deleted_email);

        // Danh sách khách đã xóa: Admin / Quản lý thấy, Sales không vào được.
        $this->actingAs($this->manager)->get(route('crm.customers.deleted'))->assertOk()->assertSee($original->name)->assertSee('Khôi phục');
        $this->actingAs($this->sales)->get(route('crm.customers.deleted'))->assertForbidden();
        $this->actingAs($this->sales)->post(route('crm.customers.restore', $original->id))->assertForbidden();

        // Khôi phục khi SĐT còn trống.
        $this->actingAs($this->manager)->post(route('crm.customers.restore', $original->id))->assertRedirect(route('crm.customers.show', $original->id));
        $restored = $original->fresh();
        $this->assertNull($restored->deleted_at);
        $this->assertSame('0911222333', $restored->phone_normalized);
        $this->assertSame('a@example.com', $restored->email);
        $this->assertDatabaseHas('crm_customer_histories', ['customer_id' => $original->id, 'type' => 'system', 'content' => 'Khôi phục khách hàng đã xóa.']);
    }

    public function test_recreating_deleted_customer_works_and_restore_is_blocked_on_phone_conflict(): void
    {
        $this->actingAs($this->manager)->post(route('crm.customers.store'), $this->customerPayload(['phone' => '0911 222 333']));
        $original = CrmCustomer::firstOrFail();
        $this->actingAs($this->manager)->delete(route('crm.customers.destroy', $original));

        // Trước đây lỗi "SĐT đã tồn tại" dù khách đã xóa.
        $this->actingAs($this->manager)->post(route('crm.customers.store'), $this->customerPayload(['phone' => '0911222333', 'name' => 'Khách tạo lại']))
            ->assertSessionHasNoErrors();
        $this->assertSame(1, CrmCustomer::count());

        $this->actingAs($this->manager)->post(route('crm.customers.restore', $original->id))->assertSessionHasErrors('restore');
        $this->assertSoftDeleted('crm_customers', ['id' => $original->id]);
    }

    public function test_manager_only_sees_deleted_customers_of_own_branch(): void
    {
        $own = $this->lead('consulting');
        $foreign = $this->lead('consulting', $this->otherBranch, $this->otherSales);
        $own->delete();
        $foreign->delete();

        $this->actingAs($this->manager)->get(route('crm.customers.deleted'))
            ->assertOk()->assertSee($own->code)->assertDontSee($foreign->code);
        $this->actingAs($this->manager)->post(route('crm.customers.restore', $foreign->id))->assertNotFound();
    }

    // ── 3–4. Khóa hợp đồng + lịch sử sửa ───────────────────────────────────

    public function test_contract_fields_are_locked_once_customer_is_closed(): void
    {
        foreach (['won', 'waiting_class'] as $stage) {
            $lead = $this->lead($stage, attributes: ['deal_value' => 12000000, 'course_interest' => 'Starters', 'converted_student_id' => $this->student()->id]);

            $this->actingAs($this->admin)->put(route('crm.customers.update', $lead), $this->customerPayload([
                'phone' => $lead->phone, 'deal_value' => 5000000, 'course_interest' => 'Starters',
            ]))->assertSessionHasErrors('deal_value');
            $this->assertEquals(12000000, (float) $lead->fresh()->deal_value);

            $this->actingAs($this->admin)->put(route('crm.customers.update', $lead), $this->customerPayload([
                'phone' => $lead->phone, 'branch_id' => $this->otherBranch->id, 'course_interest' => 'Starters', 'deal_value' => 12000000,
            ]))->assertSessionHasErrors('branch_id');

            // Form gửi thiếu trường bị khóa (readonly/disabled) → giữ giá trị cũ, vẫn sửa được thông tin liên hệ.
            $payload = $this->customerPayload(['phone' => $lead->phone, 'name' => 'Tên mới '.$stage]);
            unset($payload['deal_value'], $payload['branch_id'], $payload['course_interest']);
            $this->actingAs($this->admin)->put(route('crm.customers.update', $lead), $payload)->assertSessionHasNoErrors();
            $this->assertSame('Tên mới '.$stage, $lead->fresh()->name);
            $this->assertEquals(12000000, (float) $lead->fresh()->deal_value);
        }

        $open = $this->lead('consulting');
        $this->actingAs($this->admin)->get(route('crm.customers.edit', $open))->assertOk()->assertDontSee('đã khóa, không sửa được');
        $won = CrmCustomer::where('stage', 'won')->first();
        $this->actingAs($this->admin)->get(route('crm.customers.edit', $won))->assertOk()->assertSee('đã khóa, không sửa được');
    }

    public function test_updating_customer_writes_before_and_after_history(): void
    {
        $lead = $this->lead('consulting', attributes: ['name' => 'Tên cũ', 'deal_value' => 1000000, 'source' => 'Facebook Ads']);

        $this->actingAs($this->manager)->put(route('crm.customers.update', $lead), $this->customerPayload([
            'phone' => $lead->phone, 'name' => 'Tên mới', 'deal_value' => 2000000, 'source' => 'Facebook Ads',
            'assigned_user_id' => $this->sales->id, 'next_follow_up_at' => '2026-10-02 09:30',
        ]))->assertSessionHasNoErrors();

        $history = CrmCustomerHistory::where('customer_id', $lead->id)->where('type', 'update')->firstOrFail();
        $this->assertSame(['old' => 'Tên cũ', 'new' => 'Tên mới'], array_intersect_key($history->changes['name'], ['old' => 1, 'new' => 1]));
        $this->assertSame('1.000.000đ', $history->changes['deal_value']['old']);
        $this->assertSame('2.000.000đ', $history->changes['deal_value']['new']);
        $this->assertArrayHasKey('next_follow_up_at', $history->changes);
        $this->assertArrayNotHasKey('source', $history->changes);
        $this->assertStringContainsString('Họ tên: Tên cũ → Tên mới', $history->content);
        $this->assertSame($this->manager->id, $history->user_id);

        // Không đổi gì → không ghi lịch sử thừa.
        $this->actingAs($this->manager)->put(route('crm.customers.update', $lead), $this->customerPayload([
            'phone' => $lead->phone, 'name' => 'Tên mới', 'deal_value' => 2000000, 'source' => 'Facebook Ads',
            'assigned_user_id' => $this->sales->id, 'next_follow_up_at' => '2026-10-02 09:30',
        ]))->assertSessionHasNoErrors();
        $this->assertSame(1, CrmCustomerHistory::where('customer_id', $lead->id)->where('type', 'update')->count());
    }

    // ── 5. Cảnh báo khách bị bỏ quên ──────────────────────────────────────

    public function test_neglected_active_customers_notify_admin_and_assigned_sale(): void
    {
        $neglected = $this->lead('tested');
        $neglected->forceFill(['created_at' => now()->subDays(10)])->saveQuietly();
        CrmCustomerHistory::create(['customer_id' => $neglected->id, 'type' => 'call', 'content' => 'Gọi lần đầu'])
            ->forceFill(['created_at' => now()->subDays(5)])->saveQuietly();

        $recent = $this->lead('consulting');
        $recent->forceFill(['created_at' => now()->subDays(10)])->saveQuietly();
        CrmCustomerHistory::create(['customer_id' => $recent->id, 'type' => 'call', 'content' => 'Vừa gọi']);

        foreach (['waiting_class', 'won', 'lost'] as $closedStage) {
            $this->lead($closedStage)->forceFill(['created_at' => now()->subDays(30)])->saveQuietly();
        }

        $service = app(NotificationService::class);
        $this->assertSame(1, $service->scanAndSyncStaleLeads());

        $global = AdminNotification::where('type', 'stale_lead_care')->whereNull('user_id')->firstOrFail();
        $this->assertSame($neglected->id, $global->data['customer_id']);
        $personal = AdminNotification::where('type', 'stale_lead_care')->where('user_id', $this->sales->id)->firstOrFail();
        $this->assertSame($neglected->id, $personal->data['customer_id']);

        // Sale thấy thông báo cá nhân của mình; trung tâm thông báo hiển thị được loại cảnh báo mới.
        $this->assertSame(1, $service->getUnreadCount($this->sales));
        $this->actingAs($this->admin)->get(route('notifications.index'))->assertOk()->assertSee($neglected->name);
        $this->actingAs($this->sales)->get(route('notifications.index'))->assertOk()->assertSee($neglected->name);

        // Quét lại không cảnh báo trùng; có hoạt động mới rồi lại bị bỏ quên → cảnh báo lại.
        $this->assertSame(0, $service->scanAndSyncStaleLeads());
        CrmCustomerHistory::create(['customer_id' => $neglected->id, 'type' => 'message', 'content' => 'Nhắn Zalo'])
            ->forceFill(['created_at' => now()->subDays(4)])->saveQuietly();
        $this->assertSame(1, $service->scanAndSyncStaleLeads());
    }

    public function test_neglect_threshold_is_configurable_and_new_leads_notify_sale(): void
    {
        SystemSetting::set(NotificationService::NEGLECT_SETTING_KEY, 7);
        $lead = $this->lead('consulting');
        $lead->forceFill(['created_at' => now()->subDays(5)])->saveQuietly();
        $this->assertSame(0, app(NotificationService::class)->scanAndSyncStaleLeads());

        SystemSetting::set(NotificationService::NEGLECT_SETTING_KEY, 4);
        $this->assertSame(1, app(NotificationService::class)->scanAndSyncStaleLeads());

        $new = $this->lead('new');
        $new->forceFill(['created_at' => now()->subHours(30)])->saveQuietly();
        $this->assertSame(1, app(NotificationService::class)->scanAndSyncStaleLeads());
        $this->assertDatabaseHas('admin_notifications', ['type' => 'stale_lead_24h', 'user_id' => $this->sales->id]);
    }

    // ── 11. Pipeline ──────────────────────────────────────────────────────

    public function test_pipeline_filters_and_contact_deadline_badges(): void
    {
        $overdue = $this->lead('consulting', attributes: ['name' => 'Khách Quá Hạn', 'source' => 'Facebook Ads', 'next_follow_up_at' => now()->subHour()]);
        $soon = $this->lead('consulting', attributes: ['name' => 'Khách Sắp Hạn', 'source' => 'Google Ads', 'next_follow_up_at' => now()->addHours(5)]);
        $later = $this->lead('new', attributes: ['name' => 'Khách Còn Lâu', 'source' => 'Google Ads', 'next_follow_up_at' => now()->addDays(5)]);
        $foreign = $this->lead('new', $this->otherBranch, $this->otherSales, ['name' => 'Khách Cơ Sở B']);
        $foreign->forceFill(['created_at' => now()->subDays(20)])->saveQuietly();

        $response = $this->actingAs($this->admin)->get(route('crm.pipeline'))->assertOk()
            ->assertSee('Quá hạn')->assertSee('Sắp hết hạn')->assertSee('Sửa giai đoạn', false);
        $leads = collect($response->viewData('stages'))->flatMap(fn ($stage) => $stage['leads'])->keyBy('name');
        $this->assertSame('overdue', $leads['Khách Quá Hạn']['follow_up_status']);
        $this->assertSame('due_soon', $leads['Khách Sắp Hạn']['follow_up_status']);
        $this->assertNull($leads['Khách Còn Lâu']['follow_up_status']);

        $names = fn ($query) => collect($this->actingAs($this->admin)->get(route('crm.pipeline', $query))->viewData('stages'))
            ->flatMap(fn ($stage) => $stage['leads'])->pluck('name')->sort()->values()->all();

        $this->assertSame(['Khách Sắp Hạn'], $names(['search' => 'Sắp']));
        $this->assertSame(['Khách Còn Lâu', 'Khách Sắp Hạn'], $names(['source' => 'Google Ads']));
        $this->assertSame(['Khách Cơ Sở B'], $names(['branch_id' => $this->otherBranch->id]));
        $this->assertSame(['Khách Cơ Sở B'], $names(['assigned_user_id' => $this->otherSales->id]));
        $this->assertSame(['Khách Cơ Sở B'], $names(['to' => now()->subDays(10)->toDateString()]));
        $this->assertNotContains('Khách Cơ Sở B', $names(['from' => now()->subDay()->toDateString()]));

        // Manager: lọc chi nhánh bị bỏ qua (chỉ Admin), vẫn giới hạn chi nhánh mình.
        $managerNames = collect($this->actingAs($this->manager)->get(route('crm.pipeline', ['branch_id' => $this->otherBranch->id]))->viewData('stages'))
            ->flatMap(fn ($stage) => $stage['leads'])->pluck('name');
        $this->assertNotContains('Khách Cơ Sở B', $managerNames);
        $this->assertContains('Khách Quá Hạn', $managerNames);
    }

    public function test_pipeline_stage_edit_follows_a6_rules(): void
    {
        $lead = $this->lead('tested');

        // CM chỉ tiến 1 bước; không lùi.
        $this->actingAs($this->manager)->postJson(route('crm.customers.stage', $lead), ['stage' => 'consulting', 'reason' => 'x'])->assertForbidden();
        $this->actingAs($this->manager)->postJson(route('crm.customers.stage', $lead), ['stage' => 'result_sent'])->assertOk();
        // Admin lùi phải có lý do.
        $this->actingAs($this->admin)->postJson(route('crm.customers.stage', $lead), ['stage' => 'consulting'])->assertUnprocessable();
        $this->actingAs($this->admin)->postJson(route('crm.customers.stage', $lead), ['stage' => 'consulting', 'reason' => 'Nhập nhầm'])->assertOk();
        $this->assertSame('consulting', $lead->fresh()->stage);
    }

    // ── 12. Chi tiết khách ────────────────────────────────────────────────

    public function test_customer_detail_shows_parent_phone_status_card_and_filters_log(): void
    {
        $lead = $this->lead('consulting', attributes: ['parent_name' => 'Mẹ An', 'parent_phone' => '0987 111 222', 'next_follow_up_at' => now()->subDay()]);
        CrmCustomerHistory::create(['customer_id' => $lead->id, 'type' => 'call', 'content' => 'Nội dung cuộc gọi XYZ']);
        CrmCustomerHistory::create(['customer_id' => $lead->id, 'type' => 'note', 'content' => 'Ghi chú riêng ABC']);

        $this->actingAs($this->sales)->get(route('crm.customers.show', $lead))->assertOk()
            ->assertSee('0987 111 222')
            ->assertSee('Trạng thái &amp; Hạn xử lý', false)
            ->assertSee('Quá hạn')
            ->assertSee('In hồ sơ')
            ->assertDontSee('Phân công lại')
            ->assertSee('Nội dung cuộc gọi XYZ')->assertSee('Ghi chú riêng ABC');

        $this->actingAs($this->sales)->get(route('crm.customers.show', ['id' => $lead->id, 'log_type' => 'call']))->assertOk()
            ->assertSee('Nội dung cuộc gọi XYZ')->assertDontSee('Ghi chú riêng ABC');
    }

    public function test_reassign_is_manager_only_requires_reason_and_is_logged(): void
    {
        $lead = $this->lead('consulting');
        $newSales = $this->userWithRole('sales_consultant', $this->branch, 'Sale Mới');

        $this->actingAs($this->manager)->get(route('crm.customers.show', $lead))->assertOk()->assertSee('Phân công lại')->assertSee('Sale Mới');
        $this->actingAs($this->sales)->post(route('crm.customers.reassign', $lead), ['assigned_user_id' => $newSales->id, 'reason' => 'x'])->assertForbidden();
        $this->actingAs($this->academic)->post(route('crm.customers.reassign', $lead), ['assigned_user_id' => $newSales->id, 'reason' => 'x'])->assertForbidden();
        $this->actingAs($this->manager)->post(route('crm.customers.reassign', $lead), ['assigned_user_id' => $newSales->id])->assertSessionHasErrors('reason');

        $this->actingAs($this->manager)->post(route('crm.customers.reassign', $lead), ['assigned_user_id' => $newSales->id, 'reason' => 'Sale cũ nghỉ phép'])
            ->assertRedirect(route('crm.customers.show', $lead));
        $this->assertSame($newSales->id, $lead->fresh()->assigned_user_id);
        $history = CrmCustomerHistory::where('customer_id', $lead->id)->where('type', 'assign')->firstOrFail();
        $this->assertSame('Sale cũ nghỉ phép', $history->reason);
        $this->assertSame('Sale Một', $history->changes['assigned_user_id']['old']);
        $this->assertSame('Sale Mới', $history->changes['assigned_user_id']['new']);

        // Manager chi nhánh khác không thấy khách.
        $foreignManager = $this->userWithRole('manager', $this->otherBranch);
        $this->actingAs($foreignManager)->post(route('crm.customers.reassign', $lead), ['assigned_user_id' => $newSales->id, 'reason' => 'x'])->assertNotFound();
    }

    public function test_print_view_is_scoped_and_printable(): void
    {
        $lead = $this->lead('consulting', attributes: ['parent_phone' => '0987 000 111']);
        CrmCustomerHistory::create(['customer_id' => $lead->id, 'type' => 'call', 'content' => 'Lịch sử in']);

        $this->actingAs($this->sales)->get(route('crm.customers.print', $lead))->assertOk()
            ->assertSee('HỒ SƠ KHÁCH HÀNG TUYỂN SINH')->assertSee($lead->code)->assertSee('0987 000 111')->assertSee('Lịch sử in')->assertSee('window.print()', false);
        $this->actingAs($this->otherSales)->get(route('crm.customers.print', $lead))->assertNotFound();
    }

    public function test_first_month_care_checklist_for_won_customers(): void
    {
        $open = $this->lead('consulting');
        $this->actingAs($this->sales)->get(route('crm.customers.show', $open))->assertDontSee('Chăm sóc tháng đầu');
        $this->actingAs($this->sales)->post(route('crm.customers.care-checklist', $open), ['items' => ['session_1']])->assertSessionHasErrors('care');

        $won = $this->lead('won', attributes: ['converted_student_id' => $this->student()->id]);
        $this->actingAs($this->sales)->get(route('crm.customers.show', $won))->assertOk()->assertSee('Chăm sóc tháng đầu')->assertSee(CrmCustomer::CARE_CHECKLIST_ITEMS['session_1']);

        $this->actingAs($this->sales)->post(route('crm.customers.care-checklist', $won), ['items' => ['session_1', 'session_4_5'], 'note' => 'PH hài lòng'])
            ->assertRedirect(route('crm.customers.show', $won));
        $state = $won->fresh()->care_checklist;
        $this->assertNotNull($state['session_1']['done_at']);
        $this->assertNull($state['day_30']);
        $this->assertDatabaseHas('crm_customer_histories', ['customer_id' => $won->id, 'type' => 'care']);

        $this->actingAs($this->sales)->post(route('crm.customers.care-checklist', $won), ['items' => ['bogus']])->assertSessionHasErrors('items.0');
    }

    public function test_detail_shows_rubric_result_block_with_grade_group_scale(): void
    {
        $lead = $this->lead('tested');
        $test = PlacementTest::create(['code' => 'TEST-G3-G4', 'title' => 'Đề khối 3-4', 'is_active' => true]);
        $submission = new PlacementTestSubmission([
            'placement_test_id' => $test->id, 'customer_id' => $lead->id, 'candidate_name' => $lead->name, 'candidate_phone' => $lead->phone, 'status' => 'graded',
        ]);
        $submission->applyRubricGrade(['grade_group' => 'khoi_3_4', 'listening_score' => 13, 'reading_writing_score' => 17, 'speaking_score' => 8, 'chosen_class' => 'FAM 2 (NỬA SAU)']);
        $submission->save();

        $this->actingAs($this->academic)->get(route('crm.customers.show', $lead))->assertOk()
            ->assertSee('Khối 3 lên 4')->assertSee('38')->assertSee('/ 45')
            ->assertSee('FAM 2 (NỬA SAU)')
            ->assertSee('Lớp đề xuất theo thang điểm: <strong>Luyện MOVERS</strong>', false)
            ->assertSee('level Movers');
        $this->actingAs($this->academic)->get(route('placement-tests.results.show', $submission->id))->assertOk()
            ->assertSee('Chấm điểm theo thang điểm khối lớp')->assertDontSee('name="cefr_level"', false);
    }

    // ── 13. Khách chốt / không chốt ───────────────────────────────────────

    public function test_won_list_is_paginated_filtered_shows_class_and_exports(): void
    {
        for ($i = 1; $i <= 22; $i++) {
            $this->lead('won', attributes: ['name' => sprintf('Học viên %02d', $i), 'deal_value' => 1000000, 'converted_at' => now()->subDays($i), 'source' => $i % 2 ? 'Facebook Ads' : 'Google Ads']);
        }
        $this->lead('won', $this->otherBranch, $this->otherSales, ['name' => 'Học viên Cơ sở B', 'converted_at' => now()]);

        $response = $this->actingAs($this->manager)->get(route('crm.customers.won'))->assertOk()->assertSee('Lớp học')->assertSee('Chưa xếp lớp');
        $this->assertSame(22, $response->viewData('wonCustomers')->total());
        $this->assertCount(20, $response->viewData('wonCustomers')->items());
        $this->assertSame(22, $response->viewData('totalCount'));
        $this->assertEquals(22000000, $response->viewData('totalContractAmount'));

        $filtered = $this->actingAs($this->manager)->get(route('crm.customers.won', ['search' => 'Học viên 01']))->viewData('wonCustomers');
        $this->assertSame(['Học viên 01'], collect($filtered->items())->pluck('name')->all());
        $this->assertSame(11, $this->actingAs($this->manager)->get(route('crm.customers.won', ['source' => 'Google Ads']))->viewData('wonCustomers')->total());
        $this->assertSame(5, $this->actingAs($this->manager)->get(route('crm.customers.won', ['from' => now()->subDays(5)->toDateString()]))->viewData('wonCustomers')->total());

        $csv = $this->actingAs($this->manager)->get(route('crm.customers.won', ['export' => 'csv', 'source' => 'Google Ads']));
        $csv->assertOk();
        $content = file_get_contents($csv->baseResponse->getFile()->getPathname());
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $this->assertStringContainsString('Học viên 02', $content);
        $this->assertStringNotContainsString('Học viên 01', $content);
        $this->assertStringNotContainsString('Cơ sở B', $content);

        $xlsx = $this->actingAs($this->manager)->get(route('crm.customers.won', ['export' => 'xlsx']));
        $xlsx->assertOk();
        $this->assertStringContainsString('.xlsx', $xlsx->headers->get('content-disposition'));
    }

    public function test_lost_list_has_search_date_filter_pagination_and_export(): void
    {
        $this->lead('lost', attributes: ['name' => 'Mất Khách Một', 'lost_reason' => 'Học phí cao', 'lost_at' => now()->subDays(2)]);
        $this->lead('lost', attributes: ['name' => 'Mất Khách Hai', 'lost_reason' => 'Xa nhà', 'lost_at' => now()->subDays(40)]);

        $response = $this->actingAs($this->manager)->get(route('crm.lost-deals'))->assertOk();
        $this->assertSame(2, $response->viewData('lostCustomers')->total());
        $this->assertSame(['Mất Khách Một'], collect($this->actingAs($this->manager)->get(route('crm.lost-deals', ['search' => 'Một']))->viewData('lostCustomers')->items())->pluck('name')->all());
        $this->assertSame(['Mất Khách Hai'], collect($this->actingAs($this->manager)->get(route('crm.lost-deals', ['to' => now()->subDays(30)->toDateString()]))->viewData('lostCustomers')->items())->pluck('name')->all());

        $csv = $this->actingAs($this->manager)->get(route('crm.lost-deals', ['export' => 'csv']));
        $csv->assertOk();
        $content = file_get_contents($csv->baseResponse->getFile()->getPathname());
        $this->assertStringContainsString('Học phí cao', $content);
        $this->assertStringContainsString('Lý do thất bại', $content);
    }

    // ── 14. Báo cáo doanh số ──────────────────────────────────────────────

    public function test_sales_report_rep_table_is_scoped_and_exportable(): void
    {
        $foreignSalesSameRoleBranchA = $this->userWithRole('sales_consultant', $this->branch, 'Sale Ba');

        $salesRows = collect($this->actingAs($this->sales)->get(route('crm.reports'))->assertOk()->viewData('repsData'))->pluck('name')->all();
        $this->assertSame(['Sale Một'], $salesRows);

        $managerRows = collect($this->actingAs($this->manager)->get(route('crm.reports'))->viewData('repsData'))->pluck('name')->sort()->values()->all();
        $this->assertSame(['Sale Ba', 'Sale Một'], $managerRows);

        $adminRows = collect($this->actingAs($this->admin)->get(route('crm.reports'))->viewData('repsData'))->pluck('name');
        $this->assertContains('Sale Hai', $adminRows);

        $csv = $this->actingAs($this->sales)->get(route('crm.reports', ['export' => 'csv']));
        $csv->assertOk();
        $content = file_get_contents($csv->baseResponse->getFile()->getPathname());
        $this->assertStringContainsString('Sale Một', $content);
        $this->assertStringNotContainsString('Sale Hai', $content);
        $this->assertStringNotContainsString($foreignSalesSameRoleBranchA->name, $content);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function customerPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Nguyễn Văn An',
            'phone' => '0912 345 678',
            'source' => 'Facebook Ads',
            'branch_id' => $this->branch->id,
        ], $overrides);
    }

    private function lead(string $stage, ?Branch $branch = null, ?User $sales = null, array $attributes = []): CrmCustomer
    {
        $phone = '09'.random_int(10000000, 99999999);

        return CrmCustomer::create(array_merge([
            'code' => CrmCustomer::generateCode(),
            'name' => 'Lead '.$stage.' '.random_int(100, 999),
            'phone' => $phone,
            'phone_normalized' => $phone,
            'branch_id' => ($branch ?? $this->branch)->id,
            'assigned_user_id' => ($sales ?? $this->sales)->id,
            'source' => 'Facebook Ads',
            'stage' => $stage,
        ], $attributes));
    }

    private function student(): Student
    {
        return Student::create([
            'code' => 'HV-'.random_int(10000, 99999),
            'name' => 'Học viên test',
            'phone' => '0900000000',
            'branch_id' => $this->branch->id,
            'status' => Student::INITIAL_STATUS,
        ]);
    }

    private function userWithRole(string $role, Branch $branch, ?string $name = null): User
    {
        $user = User::factory()->create(array_filter(['branch_id' => $branch->id, 'is_active' => true, 'name' => $name]));
        $user->assignRole($role);

        return $user;
    }
}
