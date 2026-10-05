<?php

namespace Tests\Support;

use Database\Seeders\RoleSeeder;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Vai trò TÙY CHỈNH chỉ dùng trong test. Hệ thống chỉ có 9 vai trò cố định (App\Support\Roles); "Kế toán & Thu ngân" và
 * "Giáo viên giảng dạy" đã bỏ khỏi cấu hình mặc định. Hai vai trò này giữ lại ở đây với đúng tập quyền cũ để các test kiểm tra
 * phân quyền linh hoạt (Admin tạo vai trò riêng ở màn Vai trò, phạm vi dữ liệu theo người…) vẫn có một "vai trò tài chính" /
 * "vai trò giáo viên chung" làm mẫu — logic nghiệp vụ không phụ thuộc tên vai trò.
 */
final class TestOnlyRoles
{
    public const ACCOUNTANT = 'accountant';

    public const TEACHER = 'teacher';

    /** @return array<string, list<string>> */
    public static function definitions(): array
    {
        return [
            'accountant' => [
                'tuition.view', 'tuition.create', 'tuition.approve', 'tuition.reject', 'tuition.mark_contacted', 'tuition.report_overdue',
                // Xác nhận tiền mặt thu trong ngày đã nộp về TK công ty trước 19:00 (Admin qua Gate::before).
                'tuition.confirm_deposit',
                'invoice.request_cancel',
                'refund_transfer.request', 'refund_transfer.approve', 'refund_transfer.approve_transfer', 'refund_transfer.reject',
                'bank_account.manage', 'invoice_range.manage', 'fee_reminder_config.manage',
                'merchandise_stock.view', 'merchandise_stock.manage', 'merchandise_stock.scope_branch',
                'staff_checkin.view', 'staff_checkin.scope_branch',
                'payroll.view', 'payroll.create', 'payroll.edit', 'payroll.calculate', 'payroll.view_own', 'finance.view',
                'work_task.view', 'support_ticket.create', 'support_ticket.view', 'notification.view',
                'portal.staff',
                // Kế toán tổng (mọi chi nhánh) do Admin cấp "Phạm vi: Toàn hệ thống" theo người (BA 26/09/2026).
                'student.scope_branch', 'tuition.scope_branch', 'finance.scope_branch', 'attendance_staff.scope_branch',
                'payroll.scope_all', 'work_task.scope_own', 'support_ticket.scope_own',
            ],
            'teacher' => [
                'payroll.view_own',
                'class.view', 'class.teach',
                'attendance_student.view', 'attendance_student.record', 'homework.grade', 'entrance_test.examine',
                'work_task.view', 'work_task.request', 'support_ticket.create', 'support_ticket.view', 'notification.view',
                'syllabus.view', 'syllabus.update', 'syllabus.propose_adjustment',
                'staff_report.submit', 'portal.teacher', 'portal.staff', 'academic_project.view',
                'class.scope_own', 'student.scope_own', 'big_test.scope_own', 'payroll.scope_own', 'work_task.scope_own', 'support_ticket.scope_own',
                // Order học liệu: giáo viên tạo order, chỉ thấy order của mình.
                'material_order.create', 'material_order.scope_own',
            ],
        ];
    }

    /** Tạo trước (rỗng quyền) để PermissionSeeder sinh quyền "user.assign_role.<vai trò>" cho cả hai vai trò này. */
    public static function createRoles(): void
    {
        foreach (array_keys(self::definitions()) as $name) {
            Role::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
    }

    /** Gán tập quyền cho hai vai trò (chạy sau PermissionSeeder + RoleSeeder). */
    public static function seed(): void
    {
        $all = Permission::query()->where('guard_name', 'web')->pluck('name');
        foreach (self::definitions() as $name => $patterns) {
            $role = Role::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            /** @var Collection<int, string> $resolved */
            $resolved = RoleSeeder::resolvePatterns($patterns, $all);
            $role->syncPermissions($resolved->all());
        }
    }
}
