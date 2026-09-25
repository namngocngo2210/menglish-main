<?php

/**
 * Danh sách permission và role mặc định của hệ thống MENGLISH Admin.
 *
 * File này CHỈ được dùng làm nguồn dữ liệu cho seeder (PermissionSeeder,
 * RoleSeeder). Việc kiểm tra quyền lúc runtime luôn đọc từ database
 * (bảng roles/permissions/model_has_roles/model_has_permissions và
 * user_permission_overrides) thông qua Gate/Policy — không đọc trực tiếp
 * từ file config này trong Controller/Service.
 */
return [

    /**
     * Mật khẩu mặc định dùng cho các tài khoản seed (local/testing).
     * KHÔNG sử dụng giá trị này cho production; production phải đặt
     * SEED_DEFAULT_PASSWORD riêng hoặc yêu cầu đổi mật khẩu ngay sau khi tạo.
     */
    'seed_password' => env('SEED_DEFAULT_PASSWORD', 'Password123!'),

    'permissions' => [
        'user' => ['view', 'create', 'update', 'delete', 'lock', 'reset_password', 'assign_role'],
        'role' => ['view', 'create', 'update', 'delete', 'assign_permission'],
        'permission' => ['view', 'create', 'update', 'delete', 'override'],
        'branch' => ['view', 'create', 'update', 'delete', 'manage'],
        'lead' => ['view', 'create', 'update', 'delete', 'assign', 'convert', 'mark_lost'],
        'promotion' => ['manage'],
        'entrance_test' => ['view', 'send', 'grade'],
        'placement_test' => ['view', 'create', 'update', 'delete', 'send', 'grade', 'distribute'],
        'student' => ['view', 'create', 'update', 'delete', 'change_status', 'assign_class'],
        'class' => ['view', 'create', 'update', 'delete'],
        'attendance_student' => ['view', 'record'],
        'level' => ['view', 'create', 'update', 'delete'],
        'syllabus' => ['view', 'update', 'manage', 'upload', 'propose_adjustment', 'approve_adjustment'],
        'tuition' => ['view', 'create', 'approve', 'reject', 'mark_contacted', 'report_overdue'],
        'invoice' => ['request_cancel', 'approve_cancel'],
        'refund_transfer' => ['request', 'approve'],
        'bank_account' => ['manage'],
        'invoice_range' => ['manage'],
        'fee_reminder_config' => ['manage'],
        'payroll' => ['view', 'create', 'edit', 'calculate', 'approve', 'mark_paid', 'view_own'],
        'kpi' => ['view', 'confirm', 'manage'],
        'attendance_staff' => ['view', 'manual_record', 'sync'],
        'teacher_rate' => ['manage'],
        'commission_config' => ['manage'],
        'violation' => ['view', 'create', 'confirm_error', 'confirm_fine', 'cancel', 'mark_paid', 'mark_resolved'],
        'work_task' => ['view', 'create', 'update', 'assign', 'approve', 'request'],
        'support_ticket' => ['view', 'create', 'update', 'assign', 'close'],
        'media' => ['view', 'upload', 'delete', 'manage'],
        'notification' => ['view', 'manage'],
        'system_category' => ['manage'],
        'survey' => ['manage'],
        'course' => ['view', 'create', 'update', 'delete'],
        'recruitment' => ['view', 'manage'],
        'holiday' => ['manage'],
        'activity_log' => ['view'],
        'report' => ['view'],
        // Báo cáo thu chi / sổ khoản chi (finance.*). Tách khỏi report.view để Sale chỉ xem báo cáo CRM.
        'finance' => ['view'],
    ],

    /**
     * Role mặc định và tập permission tương ứng (theo "module.action").
     * "*" nghĩa là toàn bộ action của module đó.
     */
    'roles' => [
        'admin' => ['*'],

        'manager' => [
            'user.view', 'user.lock', 'user.reset_password', 'user.assign_role',
            'role.view',
            'lead.*', 'entrance_test.*', 'student.*', 'class.*', 'attendance_student.*',
            'placement_test.view', 'placement_test.grade',
            'level.*', 'syllabus.*',
            'tuition.*', 'invoice.*', 'refund_transfer.*',
            // Flow §15: chỉ Admin duyệt/chi trả lương; Kế toán tính & soát; Manager chỉ xem.
            'payroll.view', 'payroll.view_own', 'kpi.*', 'teacher_rate.manage', 'commission_config.manage',
            'attendance_staff.view', 'attendance_staff.manual_record',
            'violation.*',
            'work_task.*', 'support_ticket.*', 'notification.*', 'survey.manage',
            'course.view', 'recruitment.view', 'recruitment.manage',
            'media.*', 'activity_log.view', 'report.view', 'finance.view',
            'promotion.manage',
        ],

        'accountant' => [
            'tuition.*', 'invoice.*', 'refund_transfer.*',
            'bank_account.manage', 'invoice_range.manage', 'fee_reminder_config.manage',
            'payroll.view', 'payroll.create', 'payroll.edit', 'payroll.calculate', 'payroll.view_own', 'report.view', 'finance.view',
            'work_task.view', 'support_ticket.create', 'support_ticket.view',
        ],

        'academic_staff' => [
            'user.view', 'user.create', 'user.update', 'user.assign_role',
            'lead.view',
            'student.*', 'class.*', 'attendance_student.*',
            'attendance_staff.view', 'attendance_staff.manual_record',
            'level.*', 'syllabus.*',
            'entrance_test.*',
            'placement_test.view', 'placement_test.grade',
            'kpi.view', 'kpi.confirm',
            // CM chốt biên bản lỗi vận hành (Phase 3)
            'violation.view', 'violation.create', 'violation.confirm_error', 'violation.confirm_fine',
            'tuition.view', 'tuition.create', 'tuition.mark_contacted', 'tuition.report_overdue',
            'work_task.*', 'support_ticket.create', 'support_ticket.view', 'notification.view', 'survey.manage', 'course.view',
        ],

        'academic_lead' => [
            'user.view', 'user.create', 'user.update', 'user.assign_role',
            'lead.view',
            'student.view', 'class.*', 'attendance_student.*',
            'level.*', 'syllabus.*',
            'entrance_test.*', 'placement_test.*',
            'kpi.view', 'kpi.confirm',
            // HT chốt biên bản lỗi chuyên môn / giảng dạy (Phase 3)
            'violation.view', 'violation.create', 'violation.confirm_error', 'violation.confirm_fine',
            'work_task.*', 'support_ticket.create', 'support_ticket.view', 'notification.view', 'survey.manage', 'course.view',
        ],

        'sales_consultant' => [
            'lead.view', 'lead.create', 'lead.update', 'lead.convert', 'lead.mark_lost',
            'entrance_test.send',
            'report.view',
            'work_task.view', 'support_ticket.create', 'support_ticket.view', 'notification.view',
        ],

        'teacher' => [
            'payroll.view_own',
            'class.view',
            'attendance_student.view', 'attendance_student.record',
            'work_task.view', 'work_task.request', 'support_ticket.create', 'support_ticket.view',
            'syllabus.view', 'syllabus.update', 'syllabus.propose_adjustment',
        ],

        'teacher_fulltime' => [
            'payroll.view_own',
            'class.view',
            'attendance_student.view', 'attendance_student.record',
            'work_task.view', 'work_task.request', 'support_ticket.create', 'support_ticket.view',
            'syllabus.view', 'syllabus.update', 'syllabus.propose_adjustment',
        ],

        'teacher_parttime' => [
            'payroll.view_own',
            'class.view',
            'attendance_student.view', 'attendance_student.record',
            'work_task.view', 'work_task.request', 'support_ticket.create', 'support_ticket.view',
            'syllabus.view', 'syllabus.update', 'syllabus.propose_adjustment',
        ],

        'assistant' => [
            'payroll.view_own',
            'class.view',
            'attendance_student.view', 'attendance_student.record',
            'work_task.view', 'work_task.request', 'support_ticket.create', 'support_ticket.view',
            'syllabus.view', 'syllabus.propose_adjustment',
        ],

        'student' => [
            'class.view',
            'attendance_student.view',
            'support_ticket.create', 'support_ticket.view',
            'notification.view',
        ],
    ],

];
