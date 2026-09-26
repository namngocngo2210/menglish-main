<?php

/**
 * Vai trò mặc định của hệ thống MENGLISH Admin và tập quyền mặc định của từng vai trò.
 *
 * - Danh sách permission, nhãn, mô tả, phạm vi dữ liệu: config/permission_catalog.php (nguồn duy nhất).
 * - File này CHỈ dùng khi CÀI MỚI (RoleSeeder chỉ gán quyền cho vai trò vừa được tạo — không bao giờ ghi đè cấu hình
 *   Admin đã chỉnh trên màn Vai trò). Quyền mới thêm cho hệ thống đang chạy → viết migration cấp mặc định.
 * - Lúc chạy, quyền luôn đọc từ database (roles / permissions / model_has_* / user_permission_overrides) qua Gate.
 *
 * Cú pháp:
 *  - "module.action"          quyền cụ thể (action / phạm vi "module.scope_<level>" / đối tượng).
 *  - "module.*"               mọi quyền THAO TÁC của module (không gồm quyền đối tượng và phạm vi dữ liệu).
 *  - "user.assign_role.*"     được gán mọi vai trò (trừ Super Admin).
 *  - "*"                      (chỉ vai trò admin — Super Admin) mọi quyền thao tác + phạm vi; quyền đối tượng liệt kê riêng.
 */
return [

    /**
     * Mật khẩu mặc định dùng cho các tài khoản seed (local/testing).
     * KHÔNG sử dụng giá trị này cho production; production phải đặt
     * SEED_DEFAULT_PASSWORD riêng hoặc yêu cầu đổi mật khẩu ngay sau khi tạo.
     */
    'seed_password' => env('SEED_DEFAULT_PASSWORD', 'Password123!'),

    /** Vai trò → quyền mặc định (chỉ áp dụng khi vai trò được tạo lần đầu). */
    'roles' => [
        // Super Admin: bất biến (không sửa / xóa được trên màn Vai trò), luôn toàn quyền thao tác qua Gate::before.
        'admin' => [
            '*',
            // Quyền đối tượng: Admin có tên trong danh sách người phụ trách khách / người chấm test như trước.
            'lead.be_assigned', 'entrance_test.examine', 'portal.staff',
        ],

        'manager' => [
            'user.view', 'user.lock', 'user.reset_password', 'user.assign_role', 'user.assign_role.*',
            'role.view',
            // CRM: mọi thao tác trừ lùi bước pipeline (A6 Q1: chỉ Admin).
            'lead.view', 'lead.create', 'lead.update', 'lead.delete', 'lead.assign', 'lead.convert', 'lead.mark_lost',
            'lead.stage_forward', 'lead.trial_feedback', 'lead.be_assigned',
            'entrance_test.*', 'entrance_test.examine', 'student.*', 'class.*', 'class.teach', 'attendance_student.*',
            'placement_test.view', 'placement_test.grade',
            // Duyệt đề xuất sửa giáo trình / giãn tiến độ / Big Test là việc của Học thuật (academic_lead) — BPMN.
            'level.*', 'syllabus.view', 'syllabus.update', 'syllabus.manage', 'syllabus.upload', 'syllabus.propose_adjustment',
            // Kế toán / Học phí: liệt kê từng quyền để các quyền chỉ Admin mặc định (duyệt hoàn tiền, duyệt hủy HĐ,
            // dải số mặc định, phạm vi mọi chi nhánh) không tự lan sang vai trò này.
            'tuition.view', 'tuition.create', 'tuition.approve', 'tuition.reject', 'tuition.mark_contacted', 'tuition.report_overdue',
            'invoice.request_cancel',
            'refund_transfer.request', 'refund_transfer.approve', 'refund_transfer.approve_transfer', 'refund_transfer.reject',
            // Flow §15: chỉ Admin duyệt/chi trả lương; Kế toán tính & soát; Manager chỉ xem.
            'payroll.view', 'payroll.view_own', 'kpi.*', 'teacher_rate.manage', 'commission_config.manage',
            'attendance_staff.view', 'attendance_staff.manual_record',
            // Chốt biên bản lỗi vận hành (CM); lỗi chuyên môn do Học thuật chốt.
            'violation.view', 'violation.create', 'violation.confirm_error', 'violation.confirm_fine', 'violation.cancel',
            'violation.mark_paid', 'violation.mark_resolved', 'violation.decide_operations',
            'work_task.*', 'support_ticket.*', 'notification.*', 'survey.manage',
            'course.view', 'recruitment.view', 'recruitment.manage',
            'media.*', 'activity_log.view', 'report.view', 'finance.view',
            'promotion.manage',
            'staff_report.submit', 'staff_report.view_all', 'dashboard.operations', 'portal.staff',
            // Phạm vi dữ liệu (A6 Q7: Quản lý cơ sở chỉ thấy chi nhánh mình).
            'lead.scope_branch', 'student.scope_branch', 'class.scope_branch', 'big_test.scope_all', 'tuition.scope_branch',
            'finance.scope_branch', 'attendance_staff.scope_branch', 'payroll.scope_all', 'kpi.scope_all', 'work_task.scope_branch',
            'support_ticket.scope_all', 'user.scope_branch', 'activity_log.scope_all', 'dashboard.scope_branch',
        ],

        'accountant' => [
            'tuition.view', 'tuition.create', 'tuition.approve', 'tuition.reject', 'tuition.mark_contacted', 'tuition.report_overdue',
            'invoice.request_cancel',
            'refund_transfer.request', 'refund_transfer.approve', 'refund_transfer.approve_transfer', 'refund_transfer.reject',
            'bank_account.manage', 'invoice_range.manage', 'fee_reminder_config.manage',
            'payroll.view', 'payroll.create', 'payroll.edit', 'payroll.calculate', 'payroll.view_own', 'report.view', 'finance.view',
            'work_task.view', 'support_ticket.create', 'support_ticket.view',
            'portal.staff',
            // Kế toán tổng (mọi chi nhánh) do Admin cấp "Phạm vi: Toàn hệ thống" theo người (BA 26/09/2026).
            'student.scope_branch', 'tuition.scope_branch', 'finance.scope_branch', 'attendance_staff.scope_branch',
            'payroll.scope_all', 'work_task.scope_own', 'support_ticket.scope_own',
        ],

        'academic_staff' => [
            'user.view', 'user.create', 'user.update', 'user.assign_role',
            'user.assign_role.assistant', 'user.assign_role.teacher', 'user.assign_role.teacher_fulltime',
            'user.assign_role.teacher_parttime', 'user.assign_role.student',
            // BA 26/09/2026: Học vụ là actor chính bên CRM → toàn quyền CRM / test đầu vào TRỪ xóa (lead.delete,
            // placement_test.delete). Vẫn giới hạn chi nhánh mình; lùi giai đoạn vẫn chỉ Admin (A6 Q1).
            'lead.view', 'lead.create', 'lead.update', 'lead.assign', 'lead.convert', 'lead.mark_lost',
            'lead.stage_forward', 'lead.trial_feedback',
            'promotion.manage',
            'student.*', 'class.*', 'class.assist', 'attendance_student.*',
            'attendance_staff.view', 'attendance_staff.manual_record',
            // Học vụ không duyệt (syllabus.approve_adjustment, big_test.approve chỉ dành cho Học thuật + Admin).
            'level.*', 'syllabus.view', 'syllabus.update', 'syllabus.manage', 'syllabus.upload', 'syllabus.propose_adjustment',
            'entrance_test.*', 'entrance_test.examine',
            'placement_test.view', 'placement_test.create', 'placement_test.update', 'placement_test.send', 'placement_test.grade', 'placement_test.distribute',
            'kpi.view', 'kpi.confirm',
            // CM chốt biên bản lỗi vận hành (Phase 3)
            'violation.view', 'violation.create', 'violation.confirm_error', 'violation.confirm_fine', 'violation.decide_operations',
            'tuition.view', 'tuition.create', 'tuition.mark_contacted', 'tuition.report_overdue',
            'work_task.*', 'support_ticket.create', 'support_ticket.view', 'notification.view', 'survey.manage', 'course.view',
            'staff_report.submit', 'portal.staff',
            'lead.scope_branch', 'student.scope_branch', 'class.scope_all', 'big_test.scope_all', 'tuition.scope_branch',
            'attendance_staff.scope_all', 'kpi.scope_all', 'work_task.scope_all', 'user.scope_own', 'support_ticket.scope_own',
        ],

        'academic_lead' => [
            'user.view', 'user.create', 'user.update', 'user.assign_role',
            'user.assign_role.teacher', 'user.assign_role.teacher_fulltime', 'user.assign_role.teacher_parttime',
            'lead.view',
            'student.view', 'class.*', 'class.teach', 'attendance_student.*',
            'level.*', 'syllabus.*', 'big_test.*',
            'entrance_test.*', 'entrance_test.examine', 'placement_test.*',
            'kpi.view', 'kpi.confirm',
            // HT chốt biên bản lỗi chuyên môn / giảng dạy (Phase 3)
            'violation.view', 'violation.create', 'violation.confirm_error', 'violation.confirm_fine', 'violation.decide_academic',
            'work_task.*', 'support_ticket.create', 'support_ticket.view', 'notification.view', 'survey.manage', 'course.view',
            'staff_report.submit', 'dashboard.academic', 'portal.staff',
            'lead.scope_branch', 'student.scope_branch', 'class.scope_all', 'big_test.scope_all', 'kpi.scope_all',
            'work_task.scope_all', 'user.scope_own', 'support_ticket.scope_own',
        ],

        'sales_consultant' => [
            'lead.view', 'lead.create', 'lead.update', 'lead.convert', 'lead.mark_lost', 'lead.be_assigned',
            'entrance_test.send',
            'report.view',
            'work_task.view', 'support_ticket.create', 'support_ticket.view', 'notification.view',
            'portal.staff',
            'lead.scope_own', 'work_task.scope_own', 'support_ticket.scope_own',
        ],

        'teacher' => [
            'payroll.view_own',
            'class.view', 'class.teach',
            'attendance_student.view', 'attendance_student.record', 'homework.grade', 'entrance_test.examine',
            'work_task.view', 'work_task.request', 'support_ticket.create', 'support_ticket.view',
            'syllabus.view', 'syllabus.update', 'syllabus.propose_adjustment',
            'staff_report.submit', 'portal.teacher', 'portal.staff',
            'class.scope_own', 'student.scope_own', 'big_test.scope_own', 'payroll.scope_own', 'work_task.scope_own', 'support_ticket.scope_own',
        ],

        'teacher_fulltime' => [
            'payroll.view_own',
            'class.view', 'class.teach',
            'attendance_student.view', 'attendance_student.record', 'homework.grade', 'entrance_test.examine',
            'work_task.view', 'work_task.request', 'support_ticket.create', 'support_ticket.view',
            'syllabus.view', 'syllabus.update', 'syllabus.propose_adjustment',
            'staff_report.submit', 'portal.teacher', 'portal.staff',
            'class.scope_own', 'student.scope_own', 'big_test.scope_own', 'payroll.scope_own', 'work_task.scope_own', 'support_ticket.scope_own',
        ],

        'teacher_parttime' => [
            'payroll.view_own',
            'class.view', 'class.teach',
            'attendance_student.view', 'attendance_student.record', 'homework.grade', 'entrance_test.examine',
            'work_task.view', 'work_task.request', 'support_ticket.create', 'support_ticket.view',
            'syllabus.view', 'syllabus.update', 'syllabus.propose_adjustment',
            'staff_report.submit', 'portal.teacher', 'portal.staff',
            'class.scope_own', 'student.scope_own', 'big_test.scope_own', 'payroll.scope_own', 'work_task.scope_own', 'support_ticket.scope_own',
        ],

        'assistant' => [
            'payroll.view_own',
            'class.view', 'class.assist',
            'attendance_student.view', 'attendance_student.record', 'homework.grade',
            'work_task.view', 'work_task.request', 'support_ticket.create', 'support_ticket.view',
            'syllabus.view', 'syllabus.propose_adjustment',
            'staff_report.submit', 'portal.assistant', 'portal.staff',
            'class.scope_own', 'student.scope_own', 'big_test.scope_own', 'payroll.scope_own', 'work_task.scope_own', 'support_ticket.scope_own',
        ],

        'student' => [
            'class.view',
            'attendance_student.view',
            'support_ticket.create', 'support_ticket.view',
            'notification.view',
            'portal.student',
            'class.scope_own', 'support_ticket.scope_own',
        ],
    ],

];
