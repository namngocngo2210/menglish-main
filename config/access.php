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
            'lead.stage_forward', 'lead.trial_feedback',
            'entrance_test.*', 'entrance_test.examine', 'student.*', 'class.*', 'class.teach', 'attendance_student.*',
            'placement_test.view', 'placement_test.grade',
            // Duyệt đề xuất sửa giáo trình / giãn tiến độ / Big Test là việc của Học thuật (academic_lead) — BPMN.
            'level.*', 'syllabus.view', 'syllabus.update', 'syllabus.manage', 'syllabus.upload', 'syllabus.propose_adjustment',
            // Kế toán / Học phí: liệt kê từng quyền để các quyền chỉ Admin mặc định (duyệt hoàn tiền, duyệt hủy HĐ,
            // dải số mặc định, phạm vi mọi chi nhánh) không tự lan sang vai trò này.
            'tuition.view', 'tuition.create', 'tuition.approve', 'tuition.reject', 'tuition.mark_contacted', 'tuition.report_overdue',
            'invoice.request_cancel',
            'refund_transfer.request', 'refund_transfer.approve', 'refund_transfer.approve_transfer', 'refund_transfer.reject',
            // Bảng lương (06/10/2026): Quản lý cơ sở chỉ xem phiếu lương của chính mình ("Lương của tôi"); Admin xem mọi phiếu.
            'payroll.view_own', 'kpi.*', 'teacher_rate.manage', 'commission_config.manage',
            'attendance_staff.view', 'attendance_staff.manual_record',
            // Chấm công hằng ngày (điện thoại): xem + duyệt đơn của nhân sự chi nhánh mình.
            'staff_checkin.view', 'staff_checkin.approve', 'staff_checkin.scope_branch',
            // Chốt biên bản lỗi vận hành (CM); lỗi chuyên môn do Học thuật chốt.
            'violation.view', 'violation.create', 'violation.confirm_error', 'violation.confirm_fine', 'violation.cancel',
            'violation.mark_paid', 'violation.mark_resolved', 'violation.decide_operations',
            'work_task.*', 'support_ticket.*', 'notification.*', 'survey.manage',
            'course.view', 'recruitment.view', 'recruitment.manage',
            'media.*', 'activity_log.view', 'finance.view',
            'promotion.manage',
            'staff_report.submit', 'staff_report.view_all', 'dashboard.operations', 'portal.staff',
            'room.view', 'room.manage',
            'class_quality.*',
            'academic_project.view', 'academic_project.view_all',
            'merchandise_stock.view', 'merchandise_stock.manage',
            // Phạm vi dữ liệu (A6 Q7: Quản lý cơ sở chỉ thấy chi nhánh mình).
            'lead.scope_branch', 'student.scope_branch', 'class.scope_branch', 'room.scope_branch', 'big_test.scope_all', 'tuition.scope_branch',
            'finance.scope_branch', 'attendance_staff.scope_branch', 'payroll.scope_own', 'kpi.scope_all', 'work_task.scope_branch',
            'merchandise_stock.scope_branch',
            'support_ticket.scope_all', 'user.scope_branch', 'activity_log.scope_all', 'dashboard.scope_branch',
            // Order học liệu: xử lý đạo cụ / in ấn / GVNN theo chi nhánh.
            'material_order.view_all', 'material_order.process_ops', 'material_order.scope_branch',
        ],

        'academic_staff' => [
            'user.view', 'user.create', 'user.update', 'user.assign_role',
            'user.assign_role.assistant', 'user.assign_role.teacher_fulltime',
            'user.assign_role.teacher_parttime', 'user.assign_role.student',
            // BA 26/09/2026: Học vụ là actor chính bên CRM → toàn quyền CRM / test đầu vào TRỪ xóa (lead.delete,
            // placement_test.delete). Vẫn giới hạn chi nhánh mình; lùi giai đoạn vẫn chỉ Admin (A6 Q1).
            'lead.view', 'lead.create', 'lead.update', 'lead.assign', 'lead.convert', 'lead.mark_lost',
            'lead.stage_forward', 'lead.trial_feedback',
            // Chủ dự án 29/09/2026: người phụ trách khách = Học vụ (cùng Admin).
            'lead.be_assigned',
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
            // Nhân sự full-time có phiếu lương: xem "Lương của tôi".
            'payroll.view_own', 'payroll.scope_own',
            // Phòng học: thêm / sửa phòng chi nhánh mình; xóa phòng và danh mục loại phòng chỉ Admin.
            'room.view', 'room.manage', 'room.scope_branch',
            // Dự giờ vận hành (QA) + checklist học phí & feedback theo lớp; đánh giá dự giờ học thuật là việc của Học thuật.
            'class_quality.view', 'class_quality.observe_operations', 'class_quality.checklist',
            'academic_project.view',
            // Tồn kho sách chi nhánh mình; ghi sai số hóa đơn giấy tiền mặt thì lập yêu cầu hủy hóa đơn.
            'merchandise_stock.view', 'merchandise_stock.manage', 'merchandise_stock.scope_branch', 'invoice.request_cancel',
            'lead.scope_branch', 'student.scope_branch', 'class.scope_all', 'big_test.scope_all', 'tuition.scope_branch',
            'attendance_staff.scope_all', 'kpi.scope_all', 'work_task.scope_all', 'user.scope_own', 'support_ticket.scope_own',
            // Order học liệu: CM xử lý đạo cụ / in ấn / GVNN của chi nhánh mình.
            'material_order.view_all', 'material_order.process_ops', 'material_order.scope_branch',
        ],

        'academic_lead' => [
            'user.view', 'user.create', 'user.update', 'user.assign_role',
            'user.assign_role.teacher_fulltime', 'user.assign_role.teacher_parttime',
            'lead.view',
            'student.view', 'class.*', 'class.teach', 'attendance_student.*',
            'level.*', 'syllabus.*', 'big_test.*',
            'entrance_test.*', 'entrance_test.examine', 'placement_test.*',
            'kpi.view', 'kpi.confirm',
            // HT chốt biên bản lỗi chuyên môn / giảng dạy (Phase 3). Lập biên bản chỉ CM (Học vụ) / Admin — chủ dự án chốt.
            'violation.view', 'violation.confirm_error', 'violation.confirm_fine', 'violation.decide_academic',
            'work_task.*', 'support_ticket.create', 'support_ticket.view', 'notification.view', 'survey.manage', 'course.view',
            'staff_report.submit', 'dashboard.academic', 'portal.staff',
            'payroll.view_own', 'payroll.scope_own',
            'room.view', 'room.scope_all',
            'class_quality.view', 'class_quality.observe_academic', 'class_quality.teacher_meeting',
            'academic_project.*',
            'lead.scope_branch', 'student.scope_branch', 'class.scope_all', 'big_test.scope_all', 'kpi.scope_all',
            'work_task.scope_all', 'user.scope_own', 'support_ticket.scope_own',
            // Order học liệu học thuật: Trưởng Học thuật xử lý, thấy mọi chi nhánh.
            'material_order.view_all', 'material_order.process_academic', 'material_order.scope_all',
        ],

        'sales_consultant' => [
            'lead.view', 'lead.create', 'lead.update', 'lead.convert', 'lead.mark_lost',
            'entrance_test.send',
            'work_task.view', 'support_ticket.create', 'support_ticket.view', 'notification.view',
            'portal.staff',
            'lead.scope_own', 'work_task.scope_own', 'support_ticket.scope_own',
        ],

        'teacher_fulltime' => [
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

        'teacher_parttime' => [
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

        'assistant' => [
            'payroll.view_own',
            'class.view', 'class.assist',
            'attendance_student.view', 'attendance_student.record', 'homework.grade',
            'work_task.view', 'work_task.request', 'support_ticket.create', 'support_ticket.view', 'notification.view',
            'syllabus.view', 'syllabus.propose_adjustment',
            'staff_report.submit', 'portal.assistant', 'portal.staff', 'academic_project.view',
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
