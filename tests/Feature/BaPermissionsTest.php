<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\Course;
use App\Models\CrmCustomer;
use App\Models\InvoiceCancellation;
use App\Models\PayrollPeriod;
use App\Models\PlacementTest;
use App\Models\PlacementTestSubmission;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\TeacherTimesheet;
use App\Models\TuitionReceipt;
use App\Models\TuitionRefundRequest;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Support\Navigation\SidebarMenu;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\GrantsPersonalPermissions;
use Tests\TestCase;

/**
 * Hai quyết định BA 26/09/2026:
 * 1. "Phần kế toán cho Admin phân quyền linh hoạt" — mọi thao tác kế toán theo permission (Admin cấp / thu hồi theo vai trò
 *    ở màn Vai trò hoặc theo người ở Phân quyền cá nhân); mặc định giữ đúng hành vi cũ.
 * 2. "Học vụ là actor chính làm việc bên CRM nên full quyền, trừ delete" — vẫn giới hạn chi nhánh, lùi bước vẫn chỉ Admin.
 */
class BaPermissionsTest extends TestCase
{
    use GrantsPersonalPermissions;
    use RefreshDatabase;

    private Branch $branch;

    private Branch $otherBranch;

    private User $admin;

    private User $manager;

    private User $accountant;

    private User $academic;

    private User $sales;

    private User $teacher;

    private ClassModel $class;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        Storage::fake(TuitionRefundRequest::PROOF_DISK);

        $this->branch = Branch::create(['name' => 'CN Phân Quyền', 'code' => 'PQ', 'is_active' => true]);
        $this->otherBranch = Branch::create(['name' => 'CN Khác', 'code' => 'PK', 'is_active' => true]);
        $this->admin = $this->makeUser('admin');
        $this->manager = $this->makeUser('manager');
        $this->accountant = $this->makeUser('accountant', name: 'Kế toán Chi nhánh');
        $this->academic = $this->makeUser('academic_staff', name: 'Học vụ PQ');
        $this->sales = $this->makeUser('sales_consultant', name: 'Sale Một');
        $this->teacher = $this->makeUser('teacher', name: 'GV Chấm Công');

        $this->course = Course::create(['code' => 'PQ-FAM1', 'name' => 'Starters FAM 1 PQ', 'tuition_fee' => 9000000, 'total_lessons' => 24, 'is_active' => true]);
        $this->class = ClassModel::create([
            'code' => 'PQ-FAM1-A', 'name' => 'Lớp PQ FAM 1', 'course_id' => $this->course->id, 'branch_id' => $this->branch->id,
            'teacher_id' => $this->teacher->id, 'max_capacity' => 12, 'tuition_fee' => 9000000, 'status' => 'active',
        ]);
        BankAccount::create([
            'bank_code' => 'VCB', 'bank_name' => 'Vietcombank', 'account_number' => '0011223344', 'account_holder' => 'MENGLISH',
            'branch_id' => $this->branch->id, 'is_default_vietqr' => true, 'is_active' => true,
        ]);
    }

    // ── Quyết định 1: Kế toán phân quyền linh hoạt ────────────────────────────────────────────

    public function test_finance_defaults_preserve_current_behaviour(): void
    {
        $role = fn (string $name) => Role::findByName($name, 'web');
        foreach (['tuition.scope_all', 'finance.scope_all', 'invoice_range.manage_default', 'refund_transfer.approve_refund', 'invoice.approve_cancel', 'payroll.approve'] as $adminOnly) {
            $this->assertTrue($role('admin')->hasPermissionTo($adminOnly), $adminOnly);
            foreach (['manager', 'accountant', 'academic_staff'] as $other) {
                $this->assertFalse($role($other)->hasPermissionTo($adminOnly), "{$other} không có {$adminOnly} theo mặc định");
            }
        }
        foreach (['refund_transfer.approve', 'refund_transfer.approve_transfer', 'refund_transfer.reject', 'tuition.approve', 'invoice.request_cancel'] as $shared) {
            $this->assertTrue($role('manager')->hasPermissionTo($shared), "manager {$shared}");
            $this->assertTrue($role('accountant')->hasPermissionTo($shared), "accountant {$shared}");
        }
        $this->assertTrue($role('accountant')->hasPermissionTo('invoice_range.manage'));
        $this->assertFalse($role('accountant')->hasPermissionTo('attendance_staff.manual_record'));

        // Hiệu lực: Kế toán / Quản lý không duyệt hoàn tiền, không duyệt hủy HĐ, không sửa dải mặc định; chỉ thấy chi nhánh mình.
        $refund = $this->refundRequest('refund');
        foreach ([$this->accountant, $this->manager] as $user) {
            $this->actingAs($user)->post(route('tuition.refunds.approve', $refund->id))->assertForbidden();
        }
        $cancellation = $this->cancellation();
        $this->actingAs($this->accountant)->post(route('tuition.invoices.cancellations.reject', $cancellation->id))->assertForbidden();
        $this->actingAs($this->accountant)->post(route('tuition.config.update'), ['template_code' => '1/001', 'series_code' => 'C26PQ', 'current_number' => 5])
            ->assertForbidden();
        $foreign = $this->student('HV-PQ-KHAC', $this->otherBranch, withTuition: true);
        $this->actingAs($this->accountant)->get(route('tuition.students'))->assertOk()->assertDontSee('HV-PQ-KHAC');
        $this->actingAs($this->admin)->get(route('tuition.students'))->assertOk()->assertSee('HV-PQ-KHAC');
        $this->assertNotNull($foreign);
    }

    public function test_admin_grants_refund_approval_to_accountant_role_on_roles_screen(): void
    {
        $refund = $this->refundRequest('refund');
        $transfer = $this->refundRequest('transfer');

        $this->actingAs($this->admin)->get(route('roles.edit', Role::findByName('accountant', 'web')))->assertOk()
            ->assertSee('Kế toán / Học phí')->assertSee('Duyệt hoàn tiền (chi tiền)')->assertSee('refund_transfer.approve_refund')
            ->assertSee('Phạm vi dữ liệu')->assertSee('Học phí mọi chi nhánh (kế toán tổng)')->assertSee('Duyệt / từ chối hủy hóa đơn');

        $this->actingAs($this->accountant)->get(route('tuition.refunds'))->assertOk()
            ->assertDontSee(route('tuition.refunds.approve', $refund->id), false)->assertSee(route('tuition.refunds.approve', $transfer->id), false);
        $this->accountant = $this->accountant->fresh();

        $accountantRole = Role::findByName('accountant', 'web');
        $permissions = $accountantRole->permissions->pluck('name')->push('refund_transfer.approve_refund')->all();
        $this->actingAs($this->admin)->put(route('roles.update', $accountantRole), ['name' => 'accountant', 'permissions' => $permissions])
            ->assertSessionHasNoErrors();
        $this->accountant = $this->accountant->fresh();

        $this->actingAs($this->accountant)->get(route('tuition.refunds'))->assertOk()->assertSee(route('tuition.refunds.approve', $refund->id), false);
        // Qua cổng quyền → gặp luật nghiệp vụ tiếp theo (bắt buộc ảnh bằng chứng), rồi duyệt được.
        $this->actingAs($this->accountant)->post(route('tuition.refunds.approve', $refund->id), ['clawback_commission' => 0])->assertSessionHasErrors('proof_image');
        $this->actingAs($this->accountant)->post(route('tuition.refunds.approve', $refund->id), [
            'clawback_commission' => 0, 'proof_image' => UploadedFile::fake()->image('unc.png', 20, 20),
        ])->assertSessionHasNoErrors();
        $this->assertSame(['approved', $this->accountant->id], [$refund->fresh()->status, $refund->fresh()->approver_id]);

        // Thu hồi quyền duyệt chuyển nhượng của riêng Quản lý cơ sở (Phân quyền cá nhân) → 403; Kế toán vẫn duyệt được.
        $this->actingAs($this->admin)->put(route('users.permissions.update', $this->manager), [
            'overrides' => ['refund_transfer' => ['approve_transfer' => 'deny']],
        ])->assertSessionHasNoErrors();
        $this->manager = $this->manager->fresh();
        $this->actingAs($this->manager)->post(route('tuition.refunds.approve', $transfer->id))->assertForbidden();
        $this->actingAs($this->accountant)->post(route('tuition.refunds.approve', $transfer->id))->assertSessionHasNoErrors();
        $this->assertSame('approved', $transfer->fresh()->status);

        // Từ chối theo quyền riêng refund_transfer.reject.
        $deferral = $this->refundRequest('extension');
        $this->grantPersonal($this->sales, 'tuition.view');
        $this->actingAs($this->sales)->post(route('tuition.refunds.reject', $deferral->id))->assertForbidden();
        $this->actingAs($this->manager)->post(route('tuition.refunds.reject', $deferral->id), ['rejection_reason' => 'Không đủ căn cứ'])->assertSessionHasNoErrors();
        $this->assertSame('rejected', $deferral->fresh()->status);
    }

    public function test_admin_grants_invoice_cancellation_approval_to_one_accountant_personally(): void
    {
        $cancellation = $this->cancellation();
        $otherAccountant = $this->makeUser('accountant', name: 'Kế toán Khác');

        $this->actingAs($this->admin)->get(route('users.permissions.edit', $this->accountant))->assertOk()
            ->assertSee('Kế toán / Học phí')->assertSee('Duyệt / từ chối hủy hóa đơn')->assertSee('Duyệt hoàn tiền (chi tiền)');
        $this->actingAs($this->admin)->put(route('users.permissions.update', $this->accountant), [
            'overrides' => ['invoice' => ['approve_cancel' => 'allow']],
        ])->assertSessionHasNoErrors();

        $this->actingAs($otherAccountant)->post(route('tuition.invoices.cancellations.reject', $cancellation->id))->assertForbidden();
        $this->actingAs($this->accountant)->get(route('tuition.invoices.cancellations', ['selected_id' => $cancellation->id]))->assertOk()
            ->assertDontSee('Yêu cầu đang chờ Admin (hoặc người được cấp quyền duyệt hủy hóa đơn) phê duyệt.');
        $this->actingAs($this->accountant)->post(route('tuition.invoices.cancellations.reject', $cancellation->id), ['rejection_reason' => 'Hóa đơn đúng'])
            ->assertSessionHasNoErrors();
        $this->assertSame(['rejected', $this->accountant->id], [$cancellation->fresh()->status, $cancellation->fresh()->approver_id]);
    }

    public function test_head_office_scope_and_default_invoice_range_are_permissions_not_missing_branch(): void
    {
        $this->student('HV-PQ-KHAC', $this->otherBranch, withTuition: true);
        $noBranch = User::factory()->create(['branch_id' => null, 'is_active' => true]);
        $noBranch->assignRole('accountant');

        // Không gán chi nhánh KHÔNG còn nghĩa là kế toán tổng.
        $this->actingAs($noBranch)->get(route('tuition.students'))->assertOk()->assertDontSee('HV-PQ-KHAC');
        $this->actingAs($noBranch)->get(route('finance.reports.revenue'))->assertForbidden();
        $this->actingAs($noBranch)->post(route('tuition.config.ranges.store'), [
            'template_code' => '1/001', 'series_code' => 'C26DEF', 'start_number' => 1, 'end_number' => 100,
        ])->assertForbidden();

        // Admin cấp theo người: phạm vi dữ liệu Học phí / Thu chi "Toàn hệ thống" + invoice_range.manage_default.
        $this->actingAs($this->admin)->put(route('users.permissions.update', $noBranch), [
            'overrides' => ['invoice_range' => ['manage_default' => 'allow']],
            'data_scope' => ['tuition' => 'all', 'finance' => 'all'],
        ])->assertSessionHasNoErrors();
        $noBranch = $noBranch->fresh(); // request mới nạp lại quyền (test dùng chung đối tượng user giữa các request)
        $this->actingAs($noBranch)->get(route('tuition.students'))->assertOk()->assertSee('HV-PQ-KHAC');
        $this->actingAs($noBranch)->get(route('finance.reports.revenue'))->assertOk();
        $this->actingAs($noBranch)->post(route('tuition.config.ranges.store'), [
            'template_code' => '1/001', 'series_code' => 'C26DEF', 'start_number' => 1, 'end_number' => 100,
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('invoice_configurations', ['series_code' => 'C26DEF', 'branch_id' => null]);

        // Kế toán có chi nhánh được cấp phạm vi "Toàn hệ thống" cũng thấy mọi chi nhánh (phạm vi theo quyền, không theo gán chi nhánh).
        $this->grantPersonal($this->accountant, 'tuition.scope_all');
        $this->actingAs($this->accountant)->get(route('tuition.students'))->assertOk()->assertSee('HV-PQ-KHAC');
    }

    public function test_accountant_manual_timesheet_follows_permission_only(): void
    {
        $foreignClass = ClassModel::create([
            'code' => 'PK-FAM1', 'name' => 'Lớp Khác', 'course_id' => $this->course->id, 'branch_id' => $this->otherBranch->id,
            'teacher_id' => $this->teacher->id, 'max_capacity' => 12, 'status' => 'active',
        ]);
        $payload = fn (ClassModel $class) => [
            'user_id' => $this->teacher->id, 'class_id' => $class->id, 'teaching_date' => now()->subDay()->toDateString(),
            'time_in' => '18:00', 'time_out' => '19:30', 'type' => 'regular', 'notes' => 'GV quên check-in cổng giáo viên',
        ];

        $this->actingAs($this->accountant)->get(route('payroll.timesheets.manual'))->assertForbidden();
        $this->actingAs($this->accountant)->post(route('payroll.timesheets.manual.store'), $payload($this->class))->assertForbidden();
        $this->assertNotContains('payroll.timesheets.manual', $this->menuRoutes($this->accountant));

        $this->grantPersonal($this->accountant, 'attendance_staff.manual_record');
        $this->actingAs($this->accountant)->get(route('payroll.timesheets.manual'))->assertOk()->assertSee('Lớp PQ FAM 1')->assertDontSee('Lớp Khác');
        $this->assertContains('payroll.timesheets.manual', $this->menuRoutes($this->accountant));
        $this->actingAs($this->accountant)->post(route('payroll.timesheets.manual.store'), $payload($this->class))->assertSessionHasNoErrors();
        $this->assertSame(['manual', 1.5], [TeacherTimesheet::sole()->source, (float) TeacherTimesheet::sole()->hours]);
        // Vẫn giới hạn chi nhánh của mình.
        $this->actingAs($this->accountant)->post(route('payroll.timesheets.manual.store'), $payload($foreignClass))->assertForbidden();
    }

    public function test_payroll_approval_is_permission_based(): void
    {
        $period = PayrollPeriod::create([
            'code' => 'PR-PQ', 'title' => 'Bảng lương PQ', 'month' => now()->month, 'year' => now()->year,
            'start_date' => now()->copy()->startOfMonth(), 'end_date' => now()->copy()->endOfMonth(), 'status' => 'draft',
        ]);
        $this->actingAs($this->accountant)->post(route('payroll.periods.approve', $period->id))->assertForbidden();
        $this->actingAs($this->manager)->post(route('payroll.periods.approve', $period->id))->assertForbidden();

        // Người được cấp payroll.view (kể cả vai trò trước đây bị chặn cứng như Học thuật) xem được danh sách kỳ lương.
        $lead = $this->makeUser('academic_lead');
        $this->actingAs($lead)->get(route('payroll.periods.index'))->assertForbidden();
        $this->grantPersonal($lead, 'payroll.view');
        $this->actingAs($lead)->get(route('payroll.periods.index'))->assertOk();

        $period->calculatePayrollForPeriod();
        $this->grantPersonal($this->accountant, 'payroll.approve');
        $this->actingAs($this->accountant)->post(route('payroll.periods.approve', $period->id))->assertSessionHasNoErrors();
        $this->assertSame('approved', $period->fresh()->status);
    }

    public function test_migration_moves_existing_roles_and_head_accountants_to_permissions(): void
    {
        // Mô phỏng dữ liệu trước migration: chưa có quyền mới, Quản lý / Kế toán còn "invoice.approve_cancel" treo, Học vụ chỉ xem CRM.
        $new = ['tuition.all_branches', 'finance.all_branches', 'invoice_range.manage_default', 'refund_transfer.approve_refund', 'refund_transfer.approve_transfer', 'refund_transfer.reject'];
        Permission::whereIn('name', $new)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['manager', 'accountant'] as $name) {
            Role::findByName($name, 'web')->givePermissionTo('invoice.approve_cancel');
        }
        $academicRole = Role::findByName('academic_staff', 'web');
        $academicRole->syncPermissions($academicRole->permissions->pluck('name')->reject(fn ($p) => str_starts_with($p, 'lead.') && $p !== 'lead.view')
            ->reject(fn ($p) => in_array($p, ['promotion.manage', 'placement_test.create', 'placement_test.update', 'placement_test.send', 'placement_test.distribute'], true))->all());
        $headAccountant = User::factory()->create(['branch_id' => null, 'is_active' => true]);
        $headAccountant->assignRole('accountant');

        $migration = require database_path('migrations/2026_10_06_100000_add_flexible_finance_and_crm_permissions.php');
        $migration->up();
        $migration->up(); // chạy lại không tạo trùng

        $role = fn (string $name) => Role::findByName($name, 'web')->fresh();
        $this->assertTrue($role('admin')->hasPermissionTo('refund_transfer.approve_refund'));
        $this->assertTrue($role('accountant')->hasPermissionTo('refund_transfer.approve_transfer'));
        $this->assertTrue($role('manager')->hasPermissionTo('refund_transfer.reject'));
        $this->assertFalse($role('accountant')->hasPermissionTo('refund_transfer.approve_refund'));
        $this->assertFalse($role('manager')->hasPermissionTo('invoice.approve_cancel'));
        $this->assertFalse($role('accountant')->hasPermissionTo('invoice.approve_cancel'));
        $this->assertTrue($role('admin')->hasPermissionTo('invoice.approve_cancel'));
        foreach (['lead.create', 'lead.update', 'lead.assign', 'lead.convert', 'lead.mark_lost', 'promotion.manage', 'placement_test.create', 'placement_test.grade'] as $crm) {
            $this->assertTrue($role('academic_staff')->hasPermissionTo($crm), $crm);
        }
        $this->assertFalse($role('academic_staff')->hasPermissionTo('lead.delete'));
        $this->assertFalse($role('academic_staff')->hasPermissionTo('placement_test.delete'));

        $this->assertSame(3, UserPermissionOverride::where('user_id', $headAccountant->id)->where('allow', true)->count());
        $this->assertSame(0, UserPermissionOverride::where('user_id', $this->accountant->id)->count(), 'Kế toán chi nhánh không được cấp.');
        $this->assertTrue($headAccountant->fresh()->can('tuition.all_branches'));
        $this->assertFalse($this->accountant->fresh()->can('tuition.all_branches'));

        // RBAC (2026_10_07_100100): *.all_branches → phạm vi dữ liệu "Toàn hệ thống" (vai trò + cá nhân), quyền cũ bị xóa.
        $rbac = require database_path('migrations/2026_10_07_100100_introduce_flexible_rbac_permissions.php');
        $rbac->up();
        $rbac->up(); // chạy lại không tạo trùng
        $this->assertFalse(Permission::where('name', 'tuition.all_branches')->exists());
        $this->assertFalse(Permission::where('name', 'finance.all_branches')->exists());
        $head = $headAccountant->fresh();
        foreach (['tuition', 'finance', 'attendance_staff'] as $module) {
            $this->assertSame('all', \App\Support\DataScope::level($head, $module), $module);
            $this->assertSame('branch', \App\Support\DataScope::level($this->accountant->fresh(), $module), $module);
        }
        $this->assertTrue($head->can('invoice_range.manage_default'));
        $this->assertTrue($role('admin')->hasPermissionTo('tuition.scope_all'));
        $this->assertSame(0, UserPermissionOverride::where('user_id', $this->accountant->id)->count());
    }

    // ── Quyết định 2: Học vụ toàn quyền CRM trừ xóa ────────────────────────────────────────────

    public function test_academic_staff_closes_deal_assigns_class_and_reassigns_sale(): void
    {
        $lead = $this->lead('consulting');
        $this->assertContains('crm.pipeline', $this->menuRoutes($this->academic));

        $this->actingAs($this->academic)->get(route('crm.closing-wizard', ['customer_id' => $lead->id]))->assertOk();
        $this->actingAs($this->academic)->post(route('crm.closing-wizard.store'), [
            'customer_id' => $lead->id, 'class_id' => null, 'course_id' => $this->course->id, 'fee_paid_at_closing' => 0,
        ])->assertSessionHasNoErrors();
        $lead->refresh();
        $this->assertSame('waiting_class', $lead->stage);
        $this->assertNotNull($lead->converted_student_id);

        $this->actingAs($this->academic)->post(route('crm.customers.assign-class', $lead), ['class_id' => $this->class->id])->assertSessionHasNoErrors();
        $this->assertSame('won', $lead->fresh()->stage);
        $this->assertSame($this->class->id, Student::find($lead->converted_student_id)->current_class_id);

        // Chốt thẳng vào lớp.
        $direct = $this->lead('tested');
        $this->actingAs($this->academic)->post(route('crm.closing-wizard.store'), [
            'customer_id' => $direct->id, 'class_id' => $this->class->id, 'fee_paid_at_closing' => 0,
        ])->assertSessionHasNoErrors();
        $this->assertSame('won', $direct->fresh()->stage);

        // Phân công lại Sale (bắt buộc lý do, ghi lịch sử).
        $other = $this->lead('consulting');
        $newSales = $this->makeUser('sales_consultant', name: 'Sale Hai');
        $this->actingAs($this->academic)->get(route('crm.customers.show', $other))->assertOk()->assertSee('Phân công lại');
        $this->actingAs($this->academic)->post(route('crm.customers.reassign', $other), ['assigned_user_id' => $newSales->id])->assertSessionHasErrors('reason');
        $this->actingAs($this->academic)->post(route('crm.customers.reassign', $other), ['assigned_user_id' => $newSales->id, 'reason' => 'Sale Một nghỉ phép'])
            ->assertSessionHasNoErrors();
        $this->assertSame($newSales->id, $other->fresh()->assigned_user_id);

        // Tạo khách và giao Sale ngay khi nhập.
        $this->actingAs($this->academic)->post(route('crm.customers.store'), [
            'name' => 'Khách Học Vụ Nhập', 'phone' => '0912000777', 'branch_id' => $this->branch->id, 'source' => 'Walk-in', 'assigned_user_id' => $this->sales->id,
        ])->assertSessionHasNoErrors();
        $this->assertSame($this->sales->id, CrmCustomer::where('phone_normalized', '0912000777')->value('assigned_user_id'));
    }

    public function test_academic_staff_ticks_care_checklist_imports_customers_and_grades_tests(): void
    {
        $won = $this->lead('won', ['converted_student_id' => $this->student('HV-PQ-WON', $this->branch)->id]);
        $this->actingAs($this->academic)->post(route('crm.customers.care-checklist', $won), ['items' => ['session_1']])->assertSessionHasNoErrors();
        $this->assertNotNull($won->fresh()->care_checklist['session_1']['done_at']);

        $csv = "Họ tên,Số điện thoại,Tên phụ huynh,SĐT phụ huynh,Email,Nguồn,Khóa học quan tâm\nNguyễn Nhập,0912 345 111,Mẹ Nhập,0987654111,,Hội thảo,Starters";
        $this->actingAs($this->academic)->get(route('crm.import'))->assertOk();
        $this->actingAs($this->academic)->post(route('crm.import.preview'), [
            'file' => UploadedFile::fake()->createWithContent('khach.csv', "\xEF\xBB\xBF".$csv), 'branch_id' => $this->branch->id, 'assigned_user_id' => $this->sales->id,
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->academic)->post(route('crm.import.store'))->assertRedirect(route('crm.customers.index'));
        $this->assertSame($this->branch->id, CrmCustomer::where('phone_normalized', '0912345111')->value('branch_id'));
        // Không nhập được vào chi nhánh khác.
        $this->actingAs($this->academic)->post(route('crm.import.preview'), [
            'file' => UploadedFile::fake()->createWithContent('khach2.csv', "\xEF\xBB\xBF".$csv), 'branch_id' => $this->otherBranch->id,
        ])->assertSessionHasErrors('branch_id');

        $test = PlacementTest::create(['code' => 'PQ-TEST', 'title' => 'Đề PQ', 'is_active' => true]);
        $lead = $this->lead('consulting');
        $this->actingAs($this->academic)->post(route('crm.customers.save-test-score', $lead->id), [
            'placement_test_id' => $test->id, 'grade_group' => 'khoi_2_3', 'listening_score' => 6, 'reading_writing_score' => 7, 'speaking_score' => 6,
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, PlacementTestSubmission::where('customer_id', $lead->id)->count());

        // Soạn / sửa đề test đầu vào; báo cáo CRM.
        $this->actingAs($this->academic)->get(route('placement-tests.create'))->assertOk();
        $this->actingAs($this->academic)->get(route('crm.reports'))->assertOk();
    }

    public function test_academic_staff_cannot_delete_or_move_backward_and_stays_in_branch(): void
    {
        $lead = $this->lead('tested');
        $this->actingAs($this->academic)->delete(route('crm.customers.destroy', $lead))->assertForbidden();
        $this->actingAs($this->academic)->get(route('crm.customers.deleted'))->assertForbidden();
        $this->assertNotSoftDeleted($lead);
        $test = PlacementTest::create(['code' => 'PQ-DEL', 'title' => 'Đề không xóa', 'is_active' => true]);
        $this->actingAs($this->academic)->delete(route('placement-tests.destroy', $test->id))->assertForbidden();
        $this->assertDatabaseHas('placement_tests', ['id' => $test->id]);

        // Lùi giai đoạn vẫn chỉ Admin (A6 Q1).
        $this->actingAs($this->academic)->json('POST', route('crm.customers.stage', $lead->id), ['stage' => 'consulting', 'reason' => 'Nhập nhầm'])->assertForbidden();
        $this->assertSame('tested', $lead->fresh()->stage);

        // Chi nhánh khác: không thấy, không chốt.
        $foreign = $this->lead('consulting', ['branch_id' => $this->otherBranch->id]);
        $this->actingAs($this->academic)->get(route('crm.customers.show', $foreign))->assertNotFound();
        $this->actingAs($this->academic)->post(route('crm.closing-wizard.store'), [
            'customer_id' => $foreign->id, 'class_id' => $this->class->id, 'fee_paid_at_closing' => 0,
        ])->assertNotFound();
        $this->assertNull($foreign->fresh()->converted_student_id);
    }

    public function test_manager_and_sales_crm_rights_unchanged(): void
    {
        $lead = $this->lead('consulting');
        $newSales = $this->makeUser('sales_consultant', name: 'Sale Ba');
        $this->actingAs($this->sales)->post(route('crm.customers.reassign', $lead), ['assigned_user_id' => $newSales->id, 'reason' => 'x'])->assertForbidden();
        $this->actingAs($this->sales)->json('POST', route('crm.customers.stage', $lead->id), ['stage' => 'test_scheduled'])->assertForbidden();
        $this->actingAs($this->sales)->delete(route('crm.customers.destroy', $lead))->assertForbidden();
        $this->actingAs($this->manager)->post(route('crm.customers.reassign', $lead), ['assigned_user_id' => $newSales->id, 'reason' => 'Chia lại khách'])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->delete(route('crm.customers.destroy', $lead))->assertRedirect();
        $this->assertSoftDeleted($lead);
        $this->actingAs($this->manager)->get(route('crm.customers.deleted'))->assertOk();
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function makeUser(string $role, ?Branch $branch = null, ?string $name = null): User
    {
        $user = User::factory()->create(array_filter(['branch_id' => ($branch ?? $this->branch)->id, 'is_active' => true, 'name' => $name]));
        $user->assignRole($role);

        return $user;
    }

    /** @return list<string> */
    private function menuRoutes(User $user): array
    {
        return collect(app(SidebarMenu::class)->groupsFor($user->fresh()))->flatMap(fn (array $g) => collect($g['items'])->pluck('route'))->all();
    }

    private function lead(string $stage, array $attributes = []): CrmCustomer
    {
        return CrmCustomer::create($attributes + [
            'code' => CrmCustomer::generateCode(), 'name' => 'Khách PQ '.$stage.' '.random_int(100, 999), 'phone' => '09'.random_int(10000000, 99999999),
            'branch_id' => $this->branch->id, 'assigned_user_id' => $this->sales->id, 'stage' => $stage,
        ]);
    }

    private function student(string $code, Branch $branch, bool $withTuition = false): Student
    {
        $student = Student::create(['code' => $code, 'name' => 'Học viên '.$code, 'phone' => '09'.random_int(10000000, 99999999), 'branch_id' => $branch->id, 'status' => 'studying']);
        if ($withTuition) {
            StudentTuition::create(['student_id' => $student->id, 'branch_id' => $branch->id, 'total_amount' => 3000000, 'final_amount' => 3000000,
                'paid_amount' => 0, 'debt_amount' => 3000000, 'due_date' => now()->addDays(5), 'status' => 'unpaid']);
        }

        return $student;
    }

    /** Học viên đã đóng 6.000.000đ (phiếu đã duyệt, có số HĐ) để lập hồ sơ hoàn / chuyển / khất nợ. */
    private function paidStudent(string $code): Student
    {
        $student = $this->student($code, $this->branch);
        $tuition = StudentTuition::create(['student_id' => $student->id, 'branch_id' => $this->branch->id, 'total_amount' => 9000000, 'final_amount' => 9000000,
            'paid_amount' => 0, 'debt_amount' => 9000000, 'due_date' => now()->addDays(10), 'status' => 'unpaid']);
        TuitionReceipt::create([
            'receipt_number' => TuitionReceipt::generateReceiptNumber(), 'student_tuition_id' => $tuition->id, 'student_id' => $student->id,
            'amount' => 6000000, 'tuition_amount' => 6000000, 'payment_method' => 'cash', 'payment_date' => now(), 'invoice_number' => 'C26PQ-'.random_int(1000, 9999),
            'creator_id' => $this->admin->id, 'approver_id' => $this->admin->id, 'approved_at' => now(), 'status' => 'approved',
        ]);
        $tuition->recalculateDebt();

        return $student;
    }

    private function refundRequest(string $type): TuitionRefundRequest
    {
        $student = $this->paidStudent('HV-PQ-'.strtoupper($type).'-'.random_int(100, 999));
        $attributes = ['student_id' => $student->id, 'type' => $type, 'reason' => 'Hồ sơ '.$type, 'requester_id' => $this->accountant->id, 'status' => 'pending'];

        return TuitionRefundRequest::create($attributes + match ($type) {
            'refund' => ['refund_amount' => 1000000, 'no_transfer_reason' => 'Không có người nhận chuyển nhượng'],
            'transfer' => ['refund_amount' => 1000000, 'target_student_id' => $this->student('HV-PQ-NHAN-'.random_int(100, 999), $this->branch, withTuition: true)->id],
            default => ['extended_due_date' => now()->addDays(20)->toDateString()],
        });
    }

    private function cancellation(): InvoiceCancellation
    {
        $student = $this->paidStudent('HV-PQ-HUY');
        $receipt = TuitionReceipt::where('student_id', $student->id)->sole();

        return InvoiceCancellation::create([
            'invoice_number' => $receipt->invoice_number, 'tuition_receipt_id' => $receipt->id, 'student_id' => $student->id,
            'amount' => 6000000, 'reason' => 'Sai tên người nộp', 'requester_id' => $this->manager->id, 'status' => 'pending',
        ]);
    }
}
