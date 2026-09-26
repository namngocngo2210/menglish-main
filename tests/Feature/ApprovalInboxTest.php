<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\SyllabusChangeProposal;
use App\Models\SyllabusCurriculum;
use App\Models\TuitionReceipt;
use App\Models\TuitionRefundRequest;
use App\Models\User;
use App\Models\WorkTask;
use App\Support\Approvals\ApprovalInboxService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * IX-5 "Việc cần duyệt": phân quyền theo nguồn, phạm vi chi nhánh, badge + cache, duyệt / từ chối hàng loạt
 * đi đúng đường nghiệp vụ của màn gốc.
 */
class ApprovalInboxTest extends TestCase
{
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
            'admin' => ['admin', ['receipt', 'invoice_cancellation', 'refund', 'enrollment', 'crm_confirmation', 'syllabus_proposal', 'syllabus_adjustment', 'big_test_order', 'work_task', 'class_report']],
            'kế toán' => ['accountant', ['receipt', 'refund']],
            'quản lý cơ sở' => ['manager', ['receipt', 'refund', 'enrollment', 'crm_confirmation', 'work_task', 'class_report']],
            'trưởng học thuật' => ['academic_lead', ['syllabus_proposal', 'syllabus_adjustment', 'big_test_order', 'work_task', 'class_report']],
            'học vụ' => ['academic_staff', ['enrollment', 'crm_confirmation', 'work_task', 'class_report']],
            'giáo viên' => ['teacher', ['work_task', 'class_report']],
        ];
    }

    /** @param  list<string>  $expected */
    #[DataProvider('roleSources')]
    public function test_each_role_sees_only_sources_it_may_approve(string $role, array $expected): void
    {
        $user = $this->makeUser($role);

        $this->assertEqualsCanonicalizing($expected, array_keys(app(ApprovalInboxService::class)->visibleSources($user)));

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
        $this->assertSame(['receipt' => 2, 'refund' => 1], $inbox->counts($accountant));
        $this->assertSame(3, $inbox->badge($accountant));

        $response = $this->actingAs($accountant)->get(route('dashboard'))->assertOk();
        $this->assertMatchesRegularExpression('/data-approval-badge>3</', $response->getContent());

        $admin = $this->makeUser('admin');
        $this->assertSame(5, $inbox->badge($admin)); // 2 phiếu + 2 hồ sơ + 1 đề xuất
        $this->actingAs($accountant)->get(route('approvals.index'))
            ->assertSee('data-approval-group="hoc-phi"', false)
            ->assertSeeInOrder(['Tất cả', '3', 'Học phí', '3']);
    }

    public function test_counts_are_cached_per_user_and_invalidated_after_an_approval(): void
    {
        $accountant = $this->makeUser('accountant');
        $tuition = $this->makeTuition($this->makeStudent($this->branchA));
        $receipt = $this->pendingReceipt($tuition);
        $inbox = app(ApprovalInboxService::class);

        $this->assertSame(1, $inbox->badge($accountant));

        // Request sau: chỉ 1 lần đọc cache, không truy vấn bảng nghiệp vụ.
        $this->app->instance('request', Request::create('/'));
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->assertSame(1, $inbox->badge($accountant));
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
        $this->assertSame(0, $inbox->badge($accountant));
    }

    // ── Duyệt / từ chối hàng loạt ───────────────────────────────────────

    public function test_bulk_approve_uses_module_rules_and_failing_item_does_not_roll_back_others(): void
    {
        $accountant = $this->makeUser('accountant');
        $tuition = $this->makeTuition($this->makeStudent($this->branchA), 5000000);
        $first = $this->pendingReceipt($tuition, 1000000);
        $own = $this->pendingReceipt($tuition, 500000, $accountant); // người lập không được tự duyệt
        $second = $this->pendingReceipt($tuition, 2000000);

        $this->assertSame(3, app(ApprovalInboxService::class)->badge($accountant));

        $response = $this->htmx($accountant)->post(route('approvals.bulk'), [
            'action' => 'approve',
            'items' => ["receipt:{$first->id}", "receipt:{$own->id}", "receipt:{$second->id}"],
        ])->assertOk();

        $trigger = json_decode((string) $response->headers->get('HX-Trigger'), true);
        $this->assertTrue($trigger['approvals-changed']);
        $this->assertSame('warning', $trigger['toast']['type']);
        $this->assertStringContainsString('2/3', $trigger['toast']['message']);
        $response->assertSee('data-approval-failures', false)->assertSee('không được tự duyệt');

        // Cùng hiệu ứng như duyệt đơn lẻ ở màn gốc: phát hành số HĐ, người duyệt, trừ công nợ.
        foreach ([$first, $second] as $receipt) {
            $receipt->refresh();
            $this->assertSame(TuitionReceipt::STATUS_APPROVED, $receipt->status);
            $this->assertNotNull($receipt->invoice_number);
            $this->assertSame($accountant->id, (int) $receipt->approver_id);
        }
        $this->assertNotSame($first->invoice_number, $second->invoice_number);
        $this->assertSame(TuitionReceipt::STATUS_PENDING, $own->fresh()->status);
        $this->assertNull($own->fresh()->invoice_number);
        $this->assertEquals(3000000, (float) $tuition->fresh()->paid_amount);

        // Cache số đếm đã xoá sau khi duyệt qua inbox.
        $this->app->instance('request', Request::create('/'));
        $this->assertSame(1, app(ApprovalInboxService::class)->badge($accountant));
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

        $this->htmx($lead)->post(route('approvals.bulk'), ['action' => 'approve', 'items' => ["syllabus_proposal:{$proposal->id}"]])->assertOk();
        $proposal->refresh();
        $this->assertSame('approved', $proposal->status);
        $this->assertSame($lead->id, (int) $proposal->reviewer_id);

        // Người giao việc xác nhận hoàn thành từ inbox (luật "không tự duyệt" vẫn của module).
        $this->assertArrayHasKey('work_task', app(ApprovalInboxService::class)->counts($creator));
        $this->htmx($creator)->post(route('approvals.bulk'), ['action' => 'approve', 'items' => ["work_task:{$task->id}"]])->assertOk();
        $task->refresh();
        $this->assertSame('completed', $task->status);
        $this->assertSame($creator->id, (int) $task->confirmed_by);

        // Người làm không thấy / không duyệt được việc của mình.
        $this->app->instance('request', Request::create('/'));
        $this->assertSame(0, app(ApprovalInboxService::class)->counts($assignee)['work_task']);
    }

    public function test_reject_requires_reason(): void
    {
        $accountant = $this->makeUser('accountant');
        $receipt = $this->pendingReceipt($this->makeTuition($this->makeStudent($this->branchA)));

        $this->htmx($accountant)->post(route('approvals.bulk'), ['action' => 'reject', 'items' => ["receipt:{$receipt->id}"]])
            ->assertStatus(422)
            ->assertSee('Vui lòng nhập lý do từ chối.');
        $this->flushHeaders()->actingAs($accountant)->from(route('approvals.index'))
            ->post(route('approvals.bulk'), ['action' => 'reject', 'items' => ["receipt:{$receipt->id}"], 'reason' => ''])
            ->assertSessionHasErrors('reason');
        $this->assertSame(TuitionReceipt::STATUS_PENDING, $receipt->fresh()->status);

        $this->htmx($accountant)->post(route('approvals.bulk'), [
            'action' => 'reject', 'items' => ["receipt:{$receipt->id}"], 'reason' => 'Sai số tiền', 'single' => '1',
        ])->assertNoContent();
        $receipt->refresh();
        $this->assertSame(TuitionReceipt::STATUS_REJECTED, $receipt->status);
        $this->assertSame('Sai số tiền', $receipt->rejection_reason);
    }

    public function test_source_without_bulk_support_is_not_processed_in_inbox(): void
    {
        $accountant = $this->makeUser('accountant');
        $refund = TuitionRefundRequest::create(['student_id' => $this->makeStudent($this->branchA)->id, 'type' => 'extension', 'reason' => 'Khất nợ', 'requester_id' => $this->staff->id, 'status' => 'pending']);

        // Duyệt hoàn tiền / khất nợ cần thông tin ở màn gốc → chỉ từ chối được trong inbox.
        $this->htmx($accountant)->post(route('approvals.bulk'), ['action' => 'approve', 'items' => ["refund:{$refund->id}"]])
            ->assertOk()->assertSee('màn gốc');
        $this->assertSame('pending', $refund->fresh()->status);

        $this->flushHeaders()->actingAs($accountant)->get(route('approvals.index'))->assertOk()
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

        $this->assertSame(1, app(ApprovalInboxService::class)->counts($accountant)['receipt']);
        $this->actingAs($accountant)->get(route('approvals.index'))->assertOk()
            ->assertSee('data-approval-item="receipt:'.$inScope->id.'"', false)
            ->assertDontSee('data-approval-item="receipt:'.$outScope->id.'"', false);

        $this->htmx($accountant)->get(route('approvals.show', ['receipt', $outScope->id]))->assertNotFound();
        $this->htmx($accountant)->post(route('approvals.bulk'), ['action' => 'approve', 'items' => ["receipt:{$outScope->id}"]])
            ->assertOk()->assertSee('ngoài phạm vi');
        $this->assertSame(TuitionReceipt::STATUS_PENDING, $outScope->fresh()->status);
    }

    // ── Modal chi tiết ──────────────────────────────────────────────────

    public function test_detail_opens_as_modal_fragment_and_full_page(): void
    {
        $accountant = $this->makeUser('accountant');
        $receipt = $this->pendingReceipt($this->makeTuition($this->makeStudent($this->branchA)));

        $this->htmx($accountant)->get(route('approvals.show', ['receipt', $receipt->id]))->assertOk()
            ->assertDontSee('data-sidebar', false)
            ->assertSee('Phiếu thu '.$receipt->receipt_number)
            ->assertSee('id="approval-approve-form"', false)
            ->assertSee('id="approval-reject-form"', false);

        $this->flushHeaders()->actingAs($accountant)->get(route('approvals.show', ['receipt', $receipt->id]))->assertOk()
            ->assertSee('data-sidebar', false);

        // Nguồn không có quyền / không tồn tại → 404.
        $this->htmx($accountant)->get(route('approvals.show', ['syllabus_proposal', $this->pendingProposal()->id]))->assertNotFound();
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function htmx(User $user): static
    {
        return $this->actingAs($user)->withHeaders(['HX-Request' => 'true']);
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
