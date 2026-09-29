<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CrmBranchTransfer;
use App\Models\CrmCustomer;
use App\Models\Student;
use App\Models\User;
use App\Support\Approvals\ApprovalInboxService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Chủ dự án 29/09/2026: người phụ trách khách = Học vụ (cùng Admin). Đổi người phụ trách sang Học vụ cơ sở khác
 * cần Admin duyệt chuyển cơ sở; duyệt xong khách và học viên chuyển sang cơ sở mới.
 */
class CrmLeadOwnerBranchTransferTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branchA;

    private Branch $branchB;

    private User $admin;

    private User $hocVuA;

    private User $hocVuB;

    private User $sales;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branchA = Branch::create(['name' => 'Cơ sở A', 'code' => 'CSA', 'is_active' => true]);
        $this->branchB = Branch::create(['name' => 'Cơ sở B', 'code' => 'CSB', 'is_active' => true]);
        $this->admin = $this->userWithRole('admin', null, 'Quản trị');
        $this->hocVuA = $this->userWithRole('academic_staff', $this->branchA, 'Học Vụ A');
        $this->hocVuB = $this->userWithRole('academic_staff', $this->branchB, 'Học Vụ B');
        $this->sales = $this->userWithRole('sales_consultant', $this->branchA, 'Sale Một');
    }

    public function test_owner_filter_and_create_form_list_admin_and_academic_staff_not_sales(): void
    {
        $this->lead(['assigned_user_id' => $this->hocVuA->id]);

        $this->actingAs($this->admin)->get(route('crm.pipeline'))->assertOk()
            ->assertSee('Quản trị — Admin')
            ->assertSee('Học Vụ A — Cơ sở A')
            ->assertSee('Học Vụ B — Cơ sở B')
            ->assertDontSee('Sale Một —');

        // Học vụ (phạm vi chi nhánh) tạo khách: chỉ thấy Admin + Học vụ cơ sở mình.
        $this->actingAs($this->hocVuA)->get(route('crm.customers.create'))->assertOk()
            ->assertSee('Quản trị — Admin')
            ->assertSee('Học Vụ A — Cơ sở A')
            ->assertDontSee('Học Vụ B');
    }

    public function test_create_rejects_sales_and_owner_of_another_branch(): void
    {
        $payload = ['name' => 'Khách mới', 'phone' => '0912 555 111', 'source' => 'Facebook Ads', 'branch_id' => $this->branchA->id];

        $this->actingAs($this->admin)->post(route('crm.customers.store'), $payload + ['assigned_user_id' => $this->sales->id])
            ->assertSessionHasErrors('assigned_user_id');
        $this->actingAs($this->admin)->post(route('crm.customers.store'), $payload + ['assigned_user_id' => $this->hocVuB->id])
            ->assertSessionHasErrors('assigned_user_id');
        $this->actingAs($this->admin)->post(route('crm.customers.store'), $payload + ['assigned_user_id' => $this->hocVuA->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($this->hocVuA->id, CrmCustomer::where('name', 'Khách mới')->value('assigned_user_id'));
    }

    public function test_academic_staff_reassign_to_other_branch_waits_for_admin_then_moves_customer_and_student(): void
    {
        $student = Student::create(['code' => 'HV-1', 'name' => 'Bé An', 'phone' => '0912000999', 'branch_id' => $this->branchA->id, 'status' => 'studying']);
        $lead = $this->lead([
            'assigned_user_id' => $this->hocVuA->id, 'stage' => 'waiting_class',
            'converted_student_id' => $student->id, 'waiting_branch_id' => $this->branchA->id,
        ]);

        $this->actingAs($this->hocVuA)->post(route('crm.customers.reassign', $lead->id), [
            'assigned_user_id' => $this->hocVuB->id, 'reason' => 'Gia đình chuyển nhà sang gần cơ sở B',
        ])->assertSessionHasNoErrors();

        $lead->refresh();
        $this->assertSame($this->hocVuA->id, $lead->assigned_user_id, 'Chưa duyệt thì chưa đổi');
        $this->assertSame($this->branchA->id, $lead->branch_id);
        $transfer = CrmBranchTransfer::sole();
        $this->assertSame(CrmBranchTransfer::STATUS_PENDING, $transfer->status);
        $this->assertSame($this->branchB->id, $transfer->to_branch_id);

        // Khách chờ duyệt: không gửi thêm yêu cầu khác.
        $this->actingAs($this->hocVuA)->post(route('crm.customers.reassign', $lead->id), [
            'assigned_user_id' => $this->hocVuB->id, 'reason' => 'Gửi lại',
        ])->assertSessionHasErrors('assigned_user_id');

        // Học vụ không duyệt được; Admin thấy trong Việc cần duyệt.
        $this->assertFalse($this->hocVuA->can('lead.approve_transfer'));
        $this->actingAs($this->admin)->get(route('approvals.index'))->assertOk()->assertSee('Chuyển cơ sở (CRM)')->assertSee('Cơ sở A → Cơ sở B');

        $this->actingAs($this->admin)->post(route('approvals.bulk'), [
            'action' => 'approve', 'items' => ['crm_branch_transfer:'.$transfer->id],
        ])->assertSessionHasNoErrors();

        $lead->refresh();
        $this->assertSame($this->hocVuB->id, $lead->assigned_user_id);
        $this->assertSame($this->branchB->id, $lead->branch_id);
        $this->assertSame($this->branchB->id, $lead->waiting_branch_id);
        $this->assertSame($this->branchB->id, $student->refresh()->branch_id);
        $this->assertSame(CrmBranchTransfer::STATUS_APPROVED, $transfer->refresh()->status);
        $this->assertSame($this->admin->id, $transfer->decided_by);
        // Học vụ cơ sở B thấy khách, Học vụ cơ sở A không còn thấy.
        $this->actingAs($this->hocVuB)->get(route('crm.customers.show', $lead->id))->assertOk();
        $this->actingAs($this->hocVuA)->get(route('crm.customers.show', $lead->id))->assertNotFound();
    }

    public function test_rejected_transfer_keeps_owner_and_branch(): void
    {
        $lead = $this->lead(['assigned_user_id' => $this->hocVuA->id]);
        $this->actingAs($this->hocVuA)->put(route('crm.customers.update', $lead->id), [
            'name' => $lead->name, 'phone' => $lead->phone, 'source' => 'Facebook Ads', 'branch_id' => $this->branchA->id,
            'assigned_user_id' => $this->hocVuB->id, 'notes' => 'Ghi chú mới',
        ])->assertSessionHasNoErrors();

        $lead->refresh();
        $this->assertSame('Ghi chú mới', $lead->notes, 'Các trường khác vẫn lưu');
        $this->assertSame($this->hocVuA->id, $lead->assigned_user_id);
        $transfer = CrmBranchTransfer::sole();

        $this->actingAs($this->admin)->post(route('approvals.bulk'), [
            'action' => 'reject', 'items' => ['crm_branch_transfer:'.$transfer->id], 'reason' => 'Cơ sở B đã kín lớp',
        ])->assertSessionHasNoErrors();

        $lead->refresh();
        $this->assertSame($this->hocVuA->id, $lead->assigned_user_id);
        $this->assertSame($this->branchA->id, $lead->branch_id);
        $this->assertSame(CrmBranchTransfer::STATUS_REJECTED, $transfer->refresh()->status);
        $this->assertSame(0, app(ApprovalInboxService::class)->badge($this->admin->fresh()));
    }

    public function test_admin_reassign_to_other_branch_applies_immediately(): void
    {
        $lead = $this->lead(['assigned_user_id' => $this->hocVuA->id]);

        $this->actingAs($this->admin)->post(route('crm.customers.reassign', $lead->id), [
            'assigned_user_id' => $this->hocVuB->id, 'reason' => 'Chuyển cơ sở',
        ])->assertSessionHasNoErrors();

        $lead->refresh();
        $this->assertSame($this->hocVuB->id, $lead->assigned_user_id);
        $this->assertSame($this->branchB->id, $lead->branch_id);
        $this->assertSame(CrmBranchTransfer::STATUS_APPROVED, CrmBranchTransfer::sole()->status);
    }

    public function test_same_branch_reassign_and_admin_owner_need_no_approval(): void
    {
        $hocVuA2 = $this->userWithRole('academic_staff', $this->branchA, 'Học Vụ A2');
        $lead = $this->lead(['assigned_user_id' => $this->hocVuA->id]);

        $this->actingAs($this->hocVuA)->post(route('crm.customers.reassign', $lead->id), ['assigned_user_id' => $hocVuA2->id, 'reason' => 'Nghỉ phép'])
            ->assertSessionHasNoErrors();
        $this->assertSame($hocVuA2->id, $lead->refresh()->assigned_user_id);

        $this->actingAs($this->hocVuA)->post(route('crm.customers.reassign', $lead->id), ['assigned_user_id' => $this->admin->id, 'reason' => 'Admin tự chăm'])
            ->assertSessionHasNoErrors();
        $this->assertSame($this->admin->id, $lead->refresh()->assigned_user_id);
        $this->assertSame($this->branchA->id, $lead->branch_id);
        $this->assertSame(0, CrmBranchTransfer::count());

        $this->actingAs($this->hocVuA)->post(route('crm.customers.reassign', $lead->id), ['assigned_user_id' => $this->sales->id, 'reason' => 'x'])
            ->assertSessionHasErrors('assigned_user_id');
    }

    public function test_import_uses_owner_column_by_name_or_email(): void
    {
        $csv = implode("\n", [
            'Họ tên,Số điện thoại,Người phụ trách',
            'Khách Một,0912000101,Học Vụ A',
            'Khách Hai,0912000102,'.$this->admin->email,
            'Khách Ba,0912000103,',
            'Khách Bốn,0912000104,Học Vụ B',
            'Khách Năm,0912000105,Sale Một',
        ]);

        $this->actingAs($this->admin)->post(route('crm.import.preview'), [
            'file' => UploadedFile::fake()->createWithContent('khach.csv', $csv), 'branch_id' => $this->branchA->id,
            'assigned_user_id' => $this->hocVuA->id,
        ])->assertSessionHasNoErrors();

        $rows = collect(session('crm_customer_import')['rows'])->keyBy('line');
        $this->assertSame([], $rows[2]['errors']);
        $this->assertSame([], $rows[3]['errors']);
        $this->assertSame([], $rows[4]['errors']);
        $this->assertStringContainsString('không phải Học vụ của chi nhánh nhận khách', implode(' ', $rows[5]['errors']));
        $this->assertStringContainsString('không phải Học vụ của chi nhánh nhận khách', implode(' ', $rows[6]['errors']));

        $this->actingAs($this->admin)->post(route('crm.import.store'))->assertRedirect(route('crm.customers.index'));
        $this->assertSame($this->hocVuA->id, CrmCustomer::where('name', 'Khách Một')->value('assigned_user_id'));
        $this->assertSame($this->admin->id, CrmCustomer::where('name', 'Khách Hai')->value('assigned_user_id'));
        $this->assertSame($this->hocVuA->id, CrmCustomer::where('name', 'Khách Ba')->value('assigned_user_id'));
        $this->assertSame(3, CrmCustomer::count());
    }

    public function test_import_default_owner_must_belong_to_the_import_branch(): void
    {
        $this->actingAs($this->admin)->post(route('crm.import.preview'), [
            'file' => UploadedFile::fake()->createWithContent('khach.csv', "Họ tên,Số điện thoại\nKhách,0912000201"),
            'branch_id' => $this->branchA->id, 'assigned_user_id' => $this->hocVuB->id,
        ])->assertSessionHasErrors('assigned_user_id');
    }

    private function lead(array $attributes = []): CrmCustomer
    {
        static $n = 0;
        $n++;

        return CrmCustomer::create($attributes + [
            'code' => 'KH-T'.$n, 'name' => 'Khách '.$n, 'phone' => '09120009'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'branch_id' => $this->branchA->id, 'source' => 'Facebook Ads', 'stage' => 'new',
        ]);
    }

    private function userWithRole(string $role, ?Branch $branch, string $name): User
    {
        $user = User::factory()->create(['branch_id' => $branch?->id, 'is_active' => true, 'name' => $name]);
        $user->assignRole($role);

        return $user;
    }
}
