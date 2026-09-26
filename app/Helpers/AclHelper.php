<?php

namespace App\Helpers;

class AclHelper
{
    public static function moduleLabel(string $module): string
    {
        return match ($module) {
            'user' => '👤 Quản Lý Người Dùng & Nhân Sự',
            'role' => '🛡️ Quản Lý Vai Trò & Phân Quyền',
            'permission' => '🔑 Danh Mục Quyền Hệ Thống',
            'branch' => '🏢 Quản Lý Cơ Sở & Chi Nhánh',
            'lead' => '🎯 CRM & Khách Hàng Tiềm Năng',
            'entrance_test' => '📝 Đề Thi & Chấm Test Đầu Vào',
            'promotion' => '🎁 Ưu Đãi Tuyển Sinh (Chốt khách)',
            'placement_test' => '📝 Khảo Thí & Placement Test Đầu Vào',
            'student' => '🎓 Quản Lý Học Viên & Hồ Sơ',
            'class' => '🏫 Quản Lý Lớp Học & Xếp Lớp',
            'attendance_student' => '📅 Điểm Danh & Chuyên Cần Học Viên',
            'level' => '🏆 Khung Trình Độ & Cấp Độ Đào Tạo',
            'syllabus' => '📚 Giáo Trình, Unit & Đợt Thi Big Test',
            'big_test' => '🧪 Duyệt Đề & Kết Quả Big Test (Học thuật)',
            'tuition' => '💰 Quản Lý Học Phí & Phiếu Thu',
            'invoice' => '🧾 Hóa Đơn Điện Tử & Hủy Hóa Đơn',
            'refund_transfer' => '🔄 Hoàn Phí, Khất Nợ & Chuyển Phí',
            'bank_account' => '🏦 Tài Khoản Ngân Hàng VietQR',
            'invoice_range' => '⚙️ Cấu Hình Dải Số Hóa Đơn',
            'fee_reminder_config' => '🔔 Cấu Hình Mẫu Nhắc Nợ Tự Động',
            'payroll' => '💵 Quản Lý Bảng Lương & Chi Trả',
            'kpi' => '📈 Bảng Vàng Doanh Số & KPI Leaderboard',
            'attendance_staff' => '⏱️ Chấm Công & Đồng Bộ Máy Chấm Công',
            'teacher_rate' => '📊 Đơn Giá Giờ Dạy Theo Cấp Bậc',
            'commission_config' => '💎 Bậc Hoa Hồng Tuyển Sinh',
            'violation' => '⚠️ Xử Lý Vi Phạm & Kỷ Luật Nhân Sự',
            'work_task' => '📋 Phân Công Công Việc & Trợ Giảng',
            'support_ticket', 'ticket' => '🎫 Quản Lý Ticket & Hỗ Trợ',
            'media' => '📁 Quản Lý Media & Tệp Tin Lưu Trữ',
            'notification' => '🔔 Thông Báo Hệ Thống',
            'system_category' => '📂 Danh Mục Danh Pháp Hệ Thống',
            'holiday' => '🏖️ Lịch Nghỉ Lễ & Ngày Nghỉ',
            'activity_log' => '📜 Nhật Ký Hoạt Động & Audit Log',
            'report' => '📊 Báo Cáo Thống Kê Tổng Hợp',
            'finance' => '💹 Báo Cáo Thu Chi & Sổ Khoản Chi',
            default => strtoupper($module),
        };
    }

    /**
     * Nhóm hiển thị của module trên màn Vai trò và ma trận Phân quyền cá nhân. Các module kế toán gom về một nhóm
     * "Kế toán / Học phí" (BA 26/09/2026: Admin phân quyền kế toán linh hoạt theo vai trò hoặc theo người).
     */
    public const MODULE_GROUPS = [
        'Kế toán / Học phí' => ['tuition', 'invoice', 'refund_transfer', 'invoice_range', 'bank_account', 'fee_reminder_config', 'finance', 'payroll'],
        'CRM & Tuyển sinh' => ['lead', 'promotion', 'entrance_test', 'placement_test'],
    ];

    public const OTHER_GROUP = 'Học vụ, nhân sự & hệ thống';

    public static function moduleGroup(string $module): string
    {
        foreach (self::MODULE_GROUPS as $group => $modules) {
            if (in_array($module, $modules, true)) {
                return $group;
            }
        }

        return self::OTHER_GROUP;
    }

    /**
     * Gom permission (đã nhóm theo module) theo nhóm hiển thị, giữ thứ tự: Kế toán / Học phí, CRM & Tuyển sinh, còn lại.
     *
     * @param  iterable<string, mixed>  $permissionsByModule
     * @return array<string, array<string, mixed>>
     */
    public static function groupModules(iterable $permissionsByModule): array
    {
        $groups = array_fill_keys([...array_keys(self::MODULE_GROUPS), self::OTHER_GROUP], []);
        foreach ($permissionsByModule as $module => $permissions) {
            $groups[self::moduleGroup((string) $module)][$module] = $permissions;
        }

        return array_filter($groups);
    }

    /** Nhãn riêng theo đúng tên permission (ưu tiên hơn nhãn chung theo action). */
    private const PERMISSION_LABELS = [
        'tuition.all_branches' => 'Xem & xử lý học phí mọi chi nhánh (kế toán tổng)',
        'finance.all_branches' => 'Xem báo cáo thu chi mọi chi nhánh',
        'refund_transfer.approve' => 'Duyệt khất nợ / bảo lưu',
        'refund_transfer.approve_transfer' => 'Duyệt chuyển nhượng phí',
        'refund_transfer.approve_refund' => 'Duyệt hoàn tiền (chi tiền)',
        'refund_transfer.reject' => 'Từ chối yêu cầu hoàn / chuyển / khất nợ / bảo lưu',
        'refund_transfer.request' => 'Lập yêu cầu hoàn / chuyển / khất nợ / bảo lưu',
        'invoice.approve_cancel' => 'Duyệt / từ chối hủy hóa đơn',
        'invoice_range.manage' => 'Cấu hình dải số hóa đơn chi nhánh',
        'invoice_range.manage_default' => 'Cấu hình dải số hóa đơn mặc định (dùng chung)',
        'tuition.approve' => 'Duyệt phiếu thu (không tự duyệt phiếu mình lập)',
        'tuition.reject' => 'Trả về phiếu thu',
        'bank_account.manage' => 'Quản lý tài khoản ngân hàng & SePay',
        'fee_reminder_config.manage' => 'Cấu hình nhắc nợ',
        'payroll.approve' => 'Duyệt / chốt bảng lương',
        'payroll.edit' => 'Nhập khoản tay trên phiếu lương',
        'payroll.mark_paid' => 'Đánh dấu đã chi trả lương',
        'finance.view' => 'Xem báo cáo thu chi & sổ khoản chi',
    ];

    public static function actionLabel(string $permissionName): string
    {
        if (isset(self::PERMISSION_LABELS[$permissionName])) {
            return self::PERMISSION_LABELS[$permissionName];
        }

        $parts = explode('.', $permissionName);
        $action = $parts[1] ?? $permissionName;

        return match ($action) {
            'view' => 'Xem danh sách',
            'create' => 'Thêm mới',
            'update' => 'Chỉnh sửa',
            'delete' => 'Xóa',
            'lock' => 'Khóa tài khoản',
            'reset_password' => 'Đặt lại mật khẩu',
            'assign_role' => 'Gán vai trò',
            'assign_permission' => 'Phân quyền vai trò',
            'override' => 'Phân quyền cá nhân',
            'assign' => 'Phân công phụ trách',
            'convert' => 'Chốt deal & Xếp lớp',
            'mark_lost' => 'Đánh dấu thất bại',
            'send' => 'Gửi đề thi cho học viên',
            'grade' => 'Chấm điểm bài thi',
            'distribute' => 'Phân phối đề thi',
            'change_status' => 'Đổi trạng thái học tập',
            'assign_class' => 'Bàn giao vào lớp học',
            'record' => 'Ghi nhận điểm danh',
            'manual_record' => 'Chấm công thủ công',
            'sync' => 'Đồng bộ dữ liệu máy chấm công',
            'manage' => 'Quản lý toàn quyền',
            'upload' => 'Tải lên tài liệu / tệp tin',
            'calculate' => 'Tính toán tự động',
            'approve' => 'Phê duyệt',
            'reject' => 'Từ chối / Bác bỏ',
            'mark_contacted' => 'Đã liên hệ',
            'report_overdue' => 'Báo cáo nợ quá hạn',
            'request_cancel' => 'Yêu cầu hủy hóa đơn',
            'approve_cancel' => 'Duyệt hủy hóa đơn',
            'request' => 'Gửi yêu cầu',
            'edit' => 'Điều chỉnh',
            'view_own' => 'Xem bảng lương cá nhân',
            'confirm' => 'Xác nhận mốc KPI',
            'confirm_error' => 'Xác nhận lỗi vi phạm',
            'confirm_fine' => 'Xác nhận phạt tiền',
            'cancel' => 'Hủy biên bản',
            'close' => 'Đóng ticket hỗ trợ',
            'mark_paid' => 'Đã nộp phạt',
            'mark_resolved' => 'Đã giải quyết sự cố',
            'propose_adjustment' => 'Đề xuất chỉnh sửa giáo trình / syllabus',
            'approve_adjustment' => 'Phê duyệt đề xuất chỉnh sửa giáo trình',
            default => $action,
        };
    }

    public static function roleLabel(string $roleName): string
    {
        return match ($roleName) {
            'admin' => 'Quản trị viên Cấp cao (Admin)',
            'manager' => 'Giám đốc / Quản lý Cơ sở (Manager)',
            'accountant' => 'Kế toán Trưởng & Thu ngân (Accountant)',
            'academic_lead', 'academic' => 'Học thuật độc lập (Academic Lead)',
            'academic_staff' => 'Học vụ (Academic Staff)',
            'sales_consultant' => 'Chuyên viên Tư vấn Tuyển sinh (Sales)',
            'teacher' => 'Giáo viên Giảng dạy (Teacher)',
            'teacher_fulltime' => 'Giáo viên Fulltime',
            'teacher_parttime' => 'Giáo viên Parttime',
            'assistant' => 'Trợ giảng (Teaching Assistant)',
            'student' => 'Học viên (Student)',
            default => $roleName,
        };
    }

    /** Nhãn vai trò ngắn (bảng, ô chọn người nhận) theo mockup: "Admin", "Học thuật", "Kế toán"… */
    public static function shortRoleLabel(string $roleName): string
    {
        return match ($roleName) {
            'admin' => 'Admin',
            'manager' => 'Quản lý cơ sở',
            'accountant' => 'Kế toán',
            'academic_lead', 'academic' => 'Học thuật',
            'academic_staff' => 'Học vụ',
            'sales_consultant' => 'Tư vấn viên',
            'teacher' => 'Giáo viên',
            'teacher_fulltime' => 'Giáo viên Full-time',
            'teacher_parttime' => 'Giáo viên Part-time',
            'assistant' => 'Trợ giảng',
            'student' => 'Học viên',
            default => $roleName,
        };
    }
}
