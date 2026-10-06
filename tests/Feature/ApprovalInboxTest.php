<?php

namespace Tests\Feature;

use App\Models\AcademicRecord;
use App\Models\Branch;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\SyllabusChangeProposal;
use App\Models\SyllabusCurriculum;
use App\Models\TuitionReceipt;
use App\Models\TuitionRefundRequest;
use App\Models\User;
use App\Models\WorkTask;
use App\Providers\ApprovalServiceProvider;
use App\Services\Tuition\PaymentReportService;
use App\Support\Approvals\ApprovalInboxService;
use App\Support\Navigation\SidebarMenu;
use App\Support\Roles;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithInertia;
use Tests\TestCase;

/**
 * IX-5 "Việc cần duyệt": phân quyền theo nguồn, phạm vi chi nhánh, badge + cache, duyệt / từ chối hàng loạt
 * đi đúng đường nghiệp vụ của màn gốc. Module chỉ Admin mở được (yêu cầu 06/10/2026): vai trò khác vẫn có nguồn
 * theo quyền ở tầng service (màn nghiệp vụ gốc dùng), nhưng không có menu / badge và bị 403 ở hộp duyệt.
 */
class ApprovalInboxTest extends TestCase
{
    use InteractsWithInertia;
    use RefreshDatabase;

    private Branch $branchA;

    private Branch $branchB;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branchA = Branch::create(['name' => 'CN A', 'code' => 'CNA', 'is_active' => true]);
        $this->branchB = Branch::create(['name' => 'CN B', 'code' => 'CNB', 'is_active' => true]);
        $this->staff = $this->makeUser('academic_staff');
    }

    // ── Phân quyền ───────────────────────────────────────────────────────

    /** @return array<string, array{0: string, 1: list<string>}> */
    public static function roleSources(): array
    {
        return [
            'admin' => ['admin', ['receipt', 'invoice_cancellation', 'refund', 'payment_report', 'enrollment', 'crm_confirmation', 'crm_branch_transfer', 'syllabus_proposal', 'syllabus_adjustment', 'big_test_order', 'work_task', 'class_report', 'staff_attendance_request']],
            'kế toán' => ['accountant', ['receipt', 'refund', 'payment_report']],
            'quản lý cơ sở' => ['manager', ['receipt', 'refund', 'payment_report', 'enrollment', 'crm_confirmation', 'work_task', 'class_report', 'staff_attendance_request']],
            'trưởng học thuật' => ['academic_lead', ['syllabus_proposal', 'syllabus_adjustment', 'big_test_order', 'work_task', 'class_report']],
            'học vụ' => ['academic_staff', ['enrollment', 'crm_confirmation', 'work_task', 'class_report']],
            'giáo viên' => ['teacher', ['work_task', 'class_report']],
        ];
    }

    /** @param  list<string>  $expected */
    #[DataProvider('roleSources')]
    public function test_each_role_sees_only_sources_it_may_approve_and_only_admin_opens_the_inbox(string $role, array $expected): void
    {
        $user = $this->makeUser($role);

        $this->assertEqualsCanonicalizing($expected, array_keys(app(ApprovalInboxService::class)->visibleSources($user)));

        if ($role !== Roles::ADMIN) {
            // Module "Cần duyệt" chỉ dành cho Admin: không menu, không badge, 403 cả bản máy tính lẫn điện thoại.
            $this->assertFalse(app(ApprovalInboxService::class)->canView($user));
            $this->assertNull(app(ApprovalInboxService::class)->badge($user));
            $this->actingAs($user)->get(route('approvals.index'))->assertForbidden();
            $this->actingAs($user)->post(route('approvals.bulk'), ['action' => 'approve', 'items' => ['receipt:1']])->assertForbidden();
            $this->actingAs($user)->get(route('approvals.show', ['receipt', 1]))->assertForbidden();
            $this->actingAs($user)->get(route('dashboard'))->assertDontSee('data-menu-item="approvals"', false);

            return;
        }

        $page = $this->actingAs($user)->get(route('approvals.index'))->assertOk();
        // Chưa có mục chờ nên chưa có section → kiểm tra qua chip nhóm: chỉ nhóm của nguồn được duyệt.
        $groups = collect(app(ApprovalInboxService::class)->visibleSources($user))->map->group()->unique()->values();
        foreach (['hoc-phi' => 'Học phí', 'dao-tao' => 'Đào tạo', 'cong-viec' => 'Công việc'] as $slug => $label) {
            $groups->contains($label)
                ? $page->assertSee('data-approval-group="'.$slug.'"', false)
                : $page->assertDontSee('data-approval-group="'.$slug.'"', false);
        }

        // Sidebar: "Việc cần duyệt" là mục cấp 1 thứ 2 (sau Tổng quan).
        preg_match_all('/data-menu-item="([^"]+)"/', $page->getContent(), $m);
        $this->assertSame(['dashboard', 'approvals'], array_slice($m[1], 0, 2));
    }

    /** @return array<string, array{0: string}> */
    public static function rolesWithoutSources(): array
    {
        return ['tư vấn' => ['sales_consultant'], 'học viên' => ['student']];
    }

    #[DataProvider('rolesWithoutSources')]
    public function test_role_without_any_source_gets_403_and_no_sidebar_item(string $role): void
    {
        $user = $this->makeUser($role);

        $this->assertSame([], app(ApprovalInboxService::class)->visibleSources($user));
        $this->assertNull(app(ApprovalInboxService::class)->badge($user));
        $this->actingAs($user)->get(route('approvals.index'))->assertForbidden();
        $this->actingAs($user)->post(route('approvals.bulk'), ['action' => 'approve', 'items' => ['receipt:1']])->assertForbidden();
        $this->actingAs($user)->get(route('dashboard'))->assertDontSee('data-menu-item="approvals"', false);
    }

    public function test_module_is_admin_only_but_original_screens_still_open_by_permission(): void
    {
        $menu = app(SidebarMenu::class);
        $admin = $this->makeUser(Roles::ADMIN);
        $this->assertTrue($admin->can(ApprovalServiceProvider::MODULE_ABILITY));
        $this->assertContains('approvals', collect($menu->groupsFor($admin))->pluck('id')->all());
        $this->actingAs($admin)->get(route('approvals.index'))->assertOk();

        // QLCS (ảnh người dùng gửi), Học thuật, Học vụ: có nguồn duyệt theo quyền nhưng không vào được module.
        foreach ([Roles::MANAGER, Roles::ACADEMIC_LEAD, Roles::ACADEMIC_STAFF] as $role) {
            $user = $this->makeUser($role);
            $this->assertNotSame([], app(ApprovalInboxService::class)->visibleSources($user), $role);
            $this->assertFalse($user->can(ApprovalServiceProvider::MODULE_ABILITY), $role);
            $this->assertFalse($user->can(ApprovalServiceProvider::INBOX_ABILITY), $role);
            $this->assertNotContains('approvals', collect($menu->groupsFor($user))->pluck('id')->all(), $role);
            $this->actingAs($user)->get(route('dashboard'))->assertOk()
                ->assertDontSee('data-menu-item="approvals"', false)
                ->assertDontSee('Chờ bạn duyệt');
            $this->actingAs($user)->get(route('approvals.index'))->assertForbidden();
        }

        // Màn gốc vẫn mở theo quyền như trước (mở từ màn nghiệp vụ / thông báo), chỉ không còn trong menu Cần duyệt.
        $this->actingAs($this->makeUser(Roles::MANAGER))->get(route('tuition.receipts.approve'))->assertOk()
            ->assertDontSee('data-workspace-tabs="approvals"', false);
        $this->actingAs($this->makeUser(Roles::ACADEMIC_STAFF))->get(route('students.enrollments'))->assertOk();
        $this->actingAs($this->makeUser(Roles::ACADEMIC_LEAD))->get(route('syllabus.adjustment-requests'))->assertOk();
    }

    public function test_source_follows_permission_not_role_name(): void
    {
        $accountant = $this->makeUser('accountant');
        $this->assertArrayNotHasKey('invoice_cancellation', app(ApprovalInboxService::class)->visibleSources($accountant));

        // Admin cấp thêm quyền duyệt hủy HĐ cho người → nguồn xuất hiện ngay (không đọc tên vai trò).
        $accountant->givePermissionTo('invoice.approve_cancel');
        $this->app->instance('request', Request::create('/'));
        $this->assertArrayHasKey('invoice_cancellation', app(ApprovalInboxService::class)->visibleSources($accountant->fresh()));
    }

    // ── Badge + cache ───────────────────────────────────────────────────

    public function test_badge_equals_sum_of_visible_sources(): void
    {
        $accountant = $this->makeUser('accountant');
        $student = $this->makeStudent($this->branchA);
        $tuition = $this->makeTuition($student);
        $this->pendingReceipt($tuition);
        $this->pendingReceipt($tuition);
        TuitionRefundRequest::create(['student_id' => $student->id, 'type' => 'extension', 'reason' => 'Khất nợ', 'requester_id' => $this->staff->id, 'status' => 'pending']);
        // Hoàn phí: mặc định chỉ Admin duyệt → không tính cho kế toán.
        TuitionRefundRequest::create(['student_id' => $student->id, 'type' => 'refund', 'refund_amount' => 100000, 'reason' => 'Du học', 'requester_id' => $this->staff->id, 'status' => 'pending']);
        $this->pendingProposal(); // nguồn Đào tạo, kế toán không thấy

        $inbox = app(ApprovalInboxService::class);
        // Số đếm theo phạm vi vẫn tính cho kế toán, nhưng không phải Admin nên không có badge.
        $this->assertSame(['receipt' => 2, 'refund' => 1, 'payment_report' => 0], $inbox->counts($accountant));
        $this->assertNull($inbox->badge($accountant));
        $this->assertDoesNotMatchRegularExpression('/data-approval-badge>/', $this->actingAs($accountant)->get(route('dashboard'))->assertOk()->getContent());

        $admin = $this->makeUser(Roles::ADMIN);
        $this->assertSame(5, $inbox->badge($admin)); // 2 phiếu + 2 hồ sơ + 1 đề xuất
        $response = $this->actingAs($admin)->get(route('dashboard'))->assertOk();
        $this->assertMatchesRegularExpression('/data-approval-badge>5</', $response->getContent());
        $this->actingAs($admin)->get(route('approvals.index'))
            ->assertSee('data-approval-group="hoc-phi"', false)
            ->assertSeeInOrder(['Tất cả', '5', 'Học phí', '4']);
    }

    public function test_counts_are_cached_per_user_and_invalidated_after_an_approval(): void
    {
        $accountant = $this->makeUser('accountant');
        $tuition = $this->makeTuition($this->makeStudent($this->branchA));
        $receipt = $this->pendingReceipt($tuition);
        $inbox = app(ApprovalInboxService::class);

        $this->assertSame(1, array_sum($inbox->counts($accountant)));

        // Request sau: chỉ 1 lần đọc cache, không truy vấn bảng nghiệp vụ.
        $this->app->instance('request', Request::create('/'));
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->assertSame(1, array_sum($inbox->counts($accountant)));
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();
        $this->assertCount(1, $queries, $queries->implode("\n"));
        $this->assertStringContainsString('cache', $queries->first());

        // Duyệt ở màn cũ (không qua inbox) → observer đổi phiên bản cache → số đếm tính lại.
        $this->actingAs($this->makeUser('accountant'))
            ->post(route('tuition.receipts.approve.action', $receipt->id))
            ->assertSessionHasNoErrors();
        $this->assertSame(TuitionReceipt::STATUS_APPROVED, $receipt->fresh()->status);

        $this->app->instance('request', Request::create('/'));
        $this->assertSame(0, array_sum($inbox->counts($accountant)));
    }

    // ── Duyệt / từ chối hàng loạt ───────────────────────────────────────

    public function test_bulk_approve_uses_module_rules_and_failing_item_does_not_roll_back_others(): void
    {
        $admin = $this->makeUser(Roles::ADMIN);
        $tuition = $this->makeTuition($this->makeStudent($this->branchA), 5000000);
        $first = $this->pendingReceipt($tuition, 1000000);
        $gone = $this->pendingReceipt($tuition, 500000);
        $second = $this->pendingReceipt($tuition, 2000000);

        $this->assertSame(3, app(ApprovalInboxService::class)->badge($admin));
        // Phiếu đã bị xử lý ở nơi khác trước khi bấm duyệt hàng loạt → mục đó lỗi, các mục khác vẫn duyệt.
        $gone->update(['status' => TuitionReceipt::STATUS_REJECTED]);

        $this->modal($admin)->post(route('approvals.bulk'), [
            'action' => 'approve',
            'items' => ["receipt:{$first->id}", "receipt:{$gone->id}", "receipt:{$second->id}"],
        ])->assertRedirect(route('approvals.index'))
            ->assertSessionHas('warning', fn (string $message) => str_contains($message, '2/3'))
            ->assertSessionHas('approval_results');

        // Trang danh sách tải lại: vùng kết quả liệt kê mục lỗi.
        $this->flushHeaders()->actingAs($admin)->get(route('approvals.index'))->assertOk()
            ->assertSee('data-approval-failures', false)->assertSee('không còn chờ duyệt');

        // Cùng hiệu ứng như duyệt đơn lẻ ở màn gốc: phát hành số HĐ, người duyệt, trừ công nợ.
        foreach ([$first, $second] as $receipt) {
            $receipt->refresh();
            $this->assertSame(TuitionReceipt::STATUS_APPROVED, $receipt->status);
            $this->assertNotNull($receipt->invoice_number);
            $this->assertSame($admin->id, (int) $receipt->approver_id);
        }
        $this->assertNotSame($first->invoice_number, $second->invoice_number);
        $this->assertSame(TuitionReceipt::STATUS_REJECTED, $gone->fresh()->status);
        $this->assertNull($gone->fresh()->invoice_number);
        $this->assertEquals(3000000, (float) $tuition->fresh()->paid_amount);

        // Cache số đếm đã xoá sau khi duyệt qua inbox.
        $this->app->instance('request', Request::create('/'));
        $this->assertSame(0, app(ApprovalInboxService::class)->badge($admin));
    }

    public function test_bulk_approve_other_sources_goes_through_their_controllers(): void
    {
        $lead = $this->makeUser('academic_lead');
        $proposal = $this->pendingProposal();

        $creator = $this->makeUser('academic_staff');
        $assignee = $this->makeUser('teacher');
        $task = WorkTask::create([
            'title' => 'Chuẩn bị tài liệu', 'creator_id' => $creator->id, 'assignee_id' => $assignee->id,
            'branch_id' => $this->branchA->id, 'due_date' => today(), 'task_type' => 'one_time', 'status' => 'pending_confirmation',
        ]);

        // Không phải Admin → không vào hộp duyệt; đường duyệt của nguồn (dùng chung với màn gốc) vẫn chạy ở tầng service.
        $this->modal($lead)->post(route('approvals.bulk'), ['action' => 'approve', 'items' => ["syllabus_proposal:{$proposal->id}"]])
            ->assertForbidden();
        $this->assertSame('pending', $proposal->fresh()->status);
        $this->actingAs($lead);
        $this->assertTrue(app(ApprovalInboxService::class)->process($lead, 'approve', ["syllabus_proposal:{$proposal->id}"])[0]['ok']);
        $proposal->refresh();
        $this->assertSame('approved', $proposal->status);
        $this->assertSame($lead->id, (int) $proposal->reviewer_id);

        // Người giao việc xác nhận hoàn thành từ inbox (luật "không tự duyệt" vẫn của module).
        $this->assertArrayHasKey('work_task', app(ApprovalInboxService::class)->counts($creator));
        $this->actingAs($creator);
        $this->assertTrue(app(ApprovalInboxService::class)->process($creator, 'approve', ["work_task:{$task->id}"])[0]['ok']);
        $task->refresh();
        $this->assertSame('completed', $task->status);
        $this->assertSame($creator->id, (int) $task->confirmed_by);

        // Người làm không thấy / không duyệt được việc của mình.
        $this->app->instance('request', Request::create('/'));
        $this->assertSame(0, app(ApprovalInboxService::class)->counts($assignee)['work_task']);
    }

    public function test_reject_requires_reason(): void
    {
        $admin = $this->makeUser(Roles::ADMIN);
        $receipt = $this->pendingReceipt($this->makeTuition($this->makeStudent($this->branchA)));

        $this->modal($admin)->post(route('approvals.bulk'), ['action' => 'reject', 'items' => ["receipt:{$receipt->id}"]])
            ->assertRedirect(route('approvals.index'))
            ->assertSessionHasErrors(['reason' => 'Vui lòng nhập lý do từ chối.']);
        $this->flushHeaders()->actingAs($admin)->from(route('approvals.index'))
            ->post(route('approvals.bulk'), ['action' => 'reject', 'items' => ["receipt:{$receipt->id}"], 'reason' => ''])
            ->assertSessionHasErrors('reason');
        $this->assertSame(TuitionReceipt::STATUS_PENDING, $receipt->fresh()->status);

        // Từ modal chi tiết (single): chỉ thông báo, không có vùng kết quả.
        $this->modal($admin)->post(route('approvals.bulk'), [
            'action' => 'reject', 'items' => ["receipt:{$receipt->id}"], 'reason' => 'Sai số tiền', 'single' => '1',
        ])->assertRedirect(route('approvals.index'))->assertSessionHas('status')->assertSessionMissing('approval_results');
        $receipt->refresh();
        $this->assertSame(TuitionReceipt::STATUS_REJECTED, $receipt->status);
        $this->assertSame('Sai số tiền', $receipt->rejection_reason);
    }

    public function test_source_without_bulk_support_is_not_processed_in_inbox(): void
    {
        $admin = $this->makeUser(Roles::ADMIN);
        $refund = TuitionRefundRequest::create(['student_id' => $this->makeStudent($this->branchA)->id, 'type' => 'extension', 'reason' => 'Khất nợ', 'requester_id' => $this->staff->id, 'status' => 'pending']);

        // Duyệt hoàn tiền / khất nợ cần thông tin ở màn gốc → chỉ từ chối được trong inbox.
        $this->modal($admin)->post(route('approvals.bulk'), ['action' => 'approve', 'items' => ["refund:{$refund->id}"]])
            ->assertRedirect(route('approvals.index'))
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'màn gốc'));
        $this->assertSame('pending', $refund->fresh()->status);

        $this->flushHeaders()->actingAs($admin)->get(route('approvals.index'))->assertOk()
            ->assertSee('data-approval-item="refund:'.$refund->id.'"', false)
            ->assertSee('data-approve="0"', false)
            ->assertSee(route('tuition.refunds', ['status' => 'pending']), false);
    }

    // ── Phạm vi chi nhánh ───────────────────────────────────────────────

    public function test_items_outside_branch_scope_are_not_listed_counted_or_processed(): void
    {
        $accountant = $this->makeUser('accountant'); // phạm vi Học phí: chi nhánh A
        $inScope = $this->pendingReceipt($this->makeTuition($this->makeStudent($this->branchA)));
        $outScope = $this->pendingReceipt($this->makeTuition($this->makeStudent($this->branchB), 1000000, $this->branchB));

        // Hộp duyệt chỉ Admin mở (Admin không giới hạn chi nhánh) → phạm vi kiểm tra ở tầng service mà màn gốc dùng chung.
        $inbox = app(ApprovalInboxService::class);
        $this->assertSame(1, $inbox->counts($accountant)['receipt']);
        $receipts = collect($inbox->sections($accountant, null, 15))->firstWhere('source', $inbox->sources()['receipt'])['items'];
        $this->assertSame(['receipt:'.$inScope->id], $receipts->map->ref()->values()->all());

        $this->assertNull($inbox->find($accountant, 'receipt', $outScope->id));
        $result = $inbox->process($accountant, 'approve', ["receipt:{$outScope->id}"])[0];
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('ngoài phạm vi', $result['message']);
        $this->assertSame(TuitionReceipt::STATUS_PENDING, $outScope->fresh()->status);
    }

    // ── Modal chi tiết ──────────────────────────────────────────────────

    public function test_detail_opens_as_modal_fragment_and_full_page(): void
    {
        $admin = $this->makeUser(Roles::ADMIN);
        $receipt = $this->pendingReceipt($this->makeTuition($this->makeStudent($this->branchA)));

        $this->modal($admin)->get(route('approvals.show', ['receipt', $receipt->id]))->assertOk()
            ->assertSee('Phiếu thu '.\App\Support\DisplayCode::short($receipt->receipt_number))
            ->assertSee('id="approval-approve-form"', false)
            ->assertSee('id="approval-reject-form"', false)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Approvals/Show')
                ->where('asModal', true)
                ->where('item.ref', 'receipt:'.$receipt->id)
                ->where('canApprove', true)
                ->where('canReject', true));

        $this->flushHeaders()->actingAs($admin)->get(route('approvals.show', ['receipt', $receipt->id]))->assertOk()
            ->assertSee('data-sidebar', false);

        // Nguồn không tồn tại / mục không còn chờ duyệt → 404.
        $this->modal($admin)->get(route('approvals.show', ['khong_co', $receipt->id]))->assertNotFound();
        $this->modal($admin)->get(route('approvals.show', ['receipt', $receipt->id + 1000]))->assertNotFound();
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    // ── Học viên báo đã đóng học phí (cổng học viên) ──────────────────

    public function test_student_payment_report_reaches_accountant_and_student_gets_the_result(): void
    {
        $accountant = $this->makeUser('accountant');
        $student = $this->makeStudent($this->branchA);
        $studentUser = $this->makeUser('student');
        $student->update(['user_id' => $studentUser->id]);
        $this->makeTuition($student);
        $outScope = $this->makeStudent($this->branchB);

        $this->actingAs($studentUser)->post(route('portal.student.tuition.request'), [
            'student_id' => $student->id, 'amount' => 2500000, 'content' => 'CK Vietcombank 25/09',
        ])->assertSessionHas('success');
        AcademicRecord::create([
            'screen_key' => PaymentReportService::SCREEN_KEY, 'module' => 'student_portal', 'record_code' => 'YCHOCPHI-OUT',
            'title' => 'Báo đóng', 'status' => 'pending', 'data' => ['student_id' => (string) $outScope->id, 'amount' => 1000000],
        ]);
        $report = AcademicRecord::where('screen_key', PaymentReportService::SCREEN_KEY)->where('data->student_id', (string) $student->id)->firstOrFail();

        // Kế toán chi nhánh A: đúng 1 mục trong phạm vi (mục của chi nhánh B ngoài phạm vi); Admin duyệt ở hộp Cần duyệt.
        $this->assertSame(1, app(ApprovalInboxService::class)->counts($accountant)['payment_report']);
        $admin = $this->makeUser(Roles::ADMIN);
        $this->actingAs($admin)->get(route('approvals.index'))->assertOk()
            ->assertSee('data-approval-item="payment_report:'.$report->id.'"', false)
            ->assertSee('CK Vietcombank 25/09');

        $this->modal($admin)->post(route('approvals.bulk'), ['action' => 'approve', 'items' => ["payment_report:{$report->id}"]])
            ->assertRedirect(route('approvals.index'));
        $this->assertSame(PaymentReportService::STATUS_CONFIRMED, $report->fresh()->status);

        // Học viên nhận thông báo kết quả trong hộp thư cổng học viên.
        $this->flushHeaders()->actingAs($studentUser)->get(route('portal.student.notifications'))->assertOk()
            ->assertSee('Kế toán đã xác nhận khoản đóng 2.500.000 đ');
    }

    public function test_rejecting_a_payment_report_tells_the_student_why(): void
    {
        $admin = $this->makeUser(Roles::ADMIN);
        $student = $this->makeStudent($this->branchA);
        $report = AcademicRecord::create([
            'screen_key' => PaymentReportService::SCREEN_KEY, 'module' => 'student_portal', 'record_code' => 'YCHOCPHI-R',
            'title' => 'Báo đóng', 'status' => 'pending', 'data' => ['student_id' => (string) $student->id, 'amount' => 500000],
        ]);

        $this->modal($admin)->post(route('approvals.bulk'), [
            'action' => 'reject', 'items' => ["payment_report:{$report->id}"], 'reason' => 'Chưa thấy tiền về',
        ])->assertRedirect(route('approvals.index'));

        $this->assertSame(PaymentReportService::STATUS_REJECTED, $report->fresh()->status);
        $notification = AcademicRecord::where('record_code', 'YCHOCPHI-KQ-'.$report->id)->firstOrFail();
        $this->assertStringContainsString('Chưa thấy tiền về', $notification->data['content']);
        $this->assertSame((string) $student->id, (string) $notification->data['student_id']);
    }

    /** Request từ modal / form "Duyệt đã chọn" của trang danh sách (UiForm gửi X-Remote-Modal) → quay lại trang đang mở. */
    private function modal(User $user): static
    {
        return $this->actingAs($user)->withHeaders(self::MODAL)->from(route('approvals.index'));
    }

    private function makeUser(string $role): User
    {
        $user = User::factory()->create(['branch_id' => $this->branchA->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function makeStudent(Branch $branch): Student
    {
        static $n = 0;
        $n++;

        return Student::create(['code' => 'HV-AI-'.$n.'-'.$branch->id, 'name' => 'Học viên '.$n, 'phone' => '09000000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT), 'branch_id' => $branch->id, 'status' => 'studying']);
    }

    private function makeTuition(Student $student, float $final = 5000000, ?Branch $branch = null): StudentTuition
    {
        return StudentTuition::create([
            'student_id' => $student->id, 'branch_id' => ($branch ?? $this->branchA)->id,
            'total_amount' => $final, 'final_amount' => $final, 'paid_amount' => 0, 'debt_amount' => $final,
            'due_date' => now()->addDays(10), 'status' => 'unpaid',
        ]);
    }

    private function pendingReceipt(StudentTuition $tuition, float $amount = 1000000, ?User $creator = null): TuitionReceipt
    {
        return TuitionReceipt::create([
            'receipt_number' => TuitionReceipt::generateReceiptNumber(),
            'student_tuition_id' => $tuition->id, 'student_id' => $tuition->student_id,
            'amount' => $amount, 'payment_method' => 'cash', 'payment_date' => now(),
            'creator_id' => ($creator ?? $this->staff)->id, 'status' => TuitionReceipt::STATUS_PENDING,
        ]);
    }

    private function pendingProposal(): SyllabusChangeProposal
    {
        $curriculum = SyllabusCurriculum::firstOrCreate(['code' => 'CUR-AI'], ['title' => 'Giáo trình Inbox', 'version' => 'v1']);

        return SyllabusChangeProposal::create([
            'curriculum_id' => $curriculum->id, 'user_id' => $this->makeUser('teacher')->id,
            'new_content' => 'Thêm bài luyện nghe', 'reason' => 'Học viên yếu nghe', 'status' => 'pending',
        ]);
    }
}
