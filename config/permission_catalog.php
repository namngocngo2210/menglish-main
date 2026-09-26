<?php

/**
 * Danh mục quyền (RBAC) — NGUỒN DUY NHẤT cho: danh sách permission, nhãn + mô tả tiếng Việt,
 * nhóm hiển thị trên màn Vai trò / Phân quyền cá nhân và quy tắc phạm vi dữ liệu theo module.
 * Xem docs/rbac.md.
 *
 * Mỗi module khai báo:
 *  - label / group / icon: hiển thị trên ma trận quyền.
 *  - actions: quyền thao tác "module.action" => [nhãn, mô tả ngắn]. Cột chuẩn của ma trận: view / create / update /
 *    delete / approve; action khác hiện ở "Thao tác khác".
 *  - audience (tuỳ chọn): quyền "đối tượng" — mô tả người dùng LÀ AI (cổng học viên / giáo viên / trợ giảng, người
 *    được giao phụ trách khách, người được xếp dạy lớp…) chứ không phải năng lực thao tác. Super Admin KHÔNG tự có
 *    các quyền này (Gate::before bỏ qua) — nếu không Admin sẽ hiện trong mọi danh sách giáo viên / cổng học viên.
 *  - scope (tuỳ chọn): phạm vi dữ liệu. Permission "module.scope_<level>"; mức cao nhất được cấp thắng; không được
 *    cấp mức nào thì dùng mức thấp nhất của module (levels[0]). Mô tả từng mức áp dụng thế nào cho module.
 *
 * Thêm quyền mới: thêm vào đây + (nếu cần cấp mặc định cho hệ thống đang chạy) viết migration cấp cho vai trò.
 */
return [

    /** Nhóm module trên ma trận quyền (theo thứ tự hiển thị). */
    'groups' => [
        'crm' => 'CRM & Tuyển sinh',
        'academic' => 'Học vụ & Đào tạo',
        'finance' => 'Kế toán / Học phí',
        'hr' => 'Nhân sự, Chấm công & Lương',
        'operations' => 'Vận hành & Hỗ trợ',
        'system' => 'Hệ thống & Phân quyền',
        'portal' => 'Cổng & Đối tượng người dùng',
    ],

    /** Các mức phạm vi dữ liệu (thấp → cao). */
    'scope_levels' => [
        'own' => 'Của tôi',
        'branch' => 'Chi nhánh',
        'all' => 'Toàn hệ thống',
    ],

    /** Cột chuẩn của ma trận (các action khác vào "Thao tác khác"). */
    'matrix_columns' => [
        'view' => 'Xem',
        'create' => 'Thêm',
        'update' => 'Sửa',
        'delete' => 'Xóa',
        'approve' => 'Duyệt',
    ],

    'modules' => [

        // ───────────────────────── CRM & Tuyển sinh ─────────────────────────
        'lead' => [
            'label' => 'Khách hàng tiềm năng (CRM)',
            'group' => 'crm',
            'icon' => 'leaderboard',
            'actions' => [
                'view' => ['Xem khách', 'Xem Kanban, danh sách, hồ sơ khách, khách chốt / thất bại, báo cáo CRM (trong phạm vi dữ liệu).'],
                'create' => ['Thêm khách', 'Thêm khách mới, nhập khách từ Excel.'],
                'update' => ['Sửa khách', 'Sửa thông tin khách, ghi nhật ký chăm sóc, tick mốc chăm sóc tháng đầu.'],
                'delete' => ['Xóa khách', 'Xóa khách (xóa mềm), xem danh sách khách đã xóa và khôi phục.'],
                'assign' => ['Phân công / phân công lại', 'Chọn hoặc đổi người phụ trách khách (bắt buộc lý do khi đổi).'],
                'convert' => ['Chốt khách & xếp lớp', 'Chốt khách: tạo hồ sơ học viên, tài khoản, học phí, xếp lớp / đưa vào Chờ xếp lớp.'],
                'mark_lost' => ['Đánh dấu thất bại', 'Chuyển khách chưa chốt sang Thất bại (có lý do).'],
                'stage_forward' => ['Chuyển bước pipeline (tiến)', 'Chuyển khách tiến từng bước một trên pipeline, đặt lịch học thử (vai trò CM — A6 Q1).'],
                'stage_back' => ['Lùi bước pipeline', 'Lùi giai đoạn của khách chưa chốt, bắt buộc lý do (A6: chỉ Admin).'],
                'trial_feedback' => ['Nhận xét học thử mọi buổi', 'Ghi nhận xét học thử cho khách ở buổi mình không dạy.'],
            ],
            'audience' => [
                'be_assigned' => ['Được nhận phụ trách khách', 'Có tên trong danh sách người phụ trách khi phân công khách (Sale / quản lý).'],
            ],
            'scope' => [
                'levels' => ['own', 'branch', 'all'],
                'own' => 'Khách được giao cho tôi phụ trách',
                'branch' => 'Khách thuộc chi nhánh của tôi (chi nhánh chính + chi nhánh được cấp thêm)',
                'all' => 'Mọi khách, mọi chi nhánh',
            ],
        ],
        'promotion' => [
            'label' => 'Ưu đãi tuyển sinh',
            'group' => 'crm',
            'icon' => 'redeem',
            'actions' => [
                'manage' => ['Quản lý ưu đãi', 'Tạo ưu đãi, áp dụng ưu đãi khi chốt khách.'],
            ],
        ],
        'entrance_test' => [
            'label' => 'Lịch & chấm test đầu vào',
            'group' => 'crm',
            'icon' => 'quiz',
            'actions' => [
                'view' => ['Xem lịch / kết quả test', 'Xem lịch hẹn test và kết quả test đầu vào của khách.'],
                'send' => ['Hẹn test / gửi link test', 'Hẹn lịch test, gửi link bài test online cho khách.'],
                'grade' => ['Nhập điểm test', 'Nhập / chấm điểm test đầu vào, gồm phần Viết / Nói (A6 Q7).'],
            ],
            'audience' => [
                'examine' => ['Được chọn làm người chấm test', 'Có tên trong danh sách người chấm khi hẹn test cho khách.'],
            ],
        ],
        'placement_test' => [
            'label' => 'Đề test đầu vào (AI)',
            'group' => 'crm',
            'icon' => 'quiz',
            'actions' => [
                'view' => ['Xem đề', 'Xem danh sách đề, thang điểm & hướng dẫn chấm.'],
                'create' => ['Tạo đề', 'Tạo đề mới, nhân bản đề.'],
                'update' => ['Sửa đề', 'Sửa đề, bật / tắt đề.'],
                'delete' => ['Xóa đề', 'Xóa đề test.'],
                'send' => ['Gửi đề', 'Gửi đề cho thí sinh.'],
                'grade' => ['Chấm bài làm', 'Xem và chấm bài làm của thí sinh.'],
                'distribute' => ['Phân phối đề', 'Phân phối đề cho chi nhánh / người dùng.'],
            ],
        ],

        // ───────────────────────── Học vụ & Đào tạo ─────────────────────────
        'student' => [
            'label' => 'Hồ sơ học viên',
            'group' => 'academic',
            'icon' => 'school',
            'actions' => [
                'view' => ['Xem học viên', 'Xem danh sách, hồ sơ học viên (trong phạm vi dữ liệu).'],
                'create' => ['Thêm học viên', 'Tạo hồ sơ học viên không qua CRM.'],
                'update' => ['Sửa học viên', 'Sửa thông tin hồ sơ học viên.'],
                'delete' => ['Xóa học viên', 'Xóa hồ sơ học viên.'],
                'change_status' => ['Đổi trạng thái học tập', 'Đổi trạng thái (bảo lưu, nghỉ hè, thôi học…), kết thúc bảo lưu.'],
                'assign_class' => ['Xếp lớp / xác nhận nhập học', 'Xếp lớp, liên kết lớp, xác nhận chính thức, bàn giao nhập học.'],
            ],
            'scope' => [
                'levels' => ['own', 'branch', 'all'],
                'own' => 'Học viên thuộc lớp tôi phụ trách / dạy',
                'branch' => 'Học viên thuộc chi nhánh của tôi (chưa gán chi nhánh thì theo lớp đang học)',
                'all' => 'Mọi học viên',
            ],
        ],
        'class' => [
            'label' => 'Lớp học & lịch học',
            'group' => 'academic',
            'icon' => 'groups',
            'actions' => [
                'view' => ['Xem lớp', 'Xem danh sách, hồ sơ lớp, lịch học (trong phạm vi dữ liệu).'],
                'create' => ['Tạo lớp', 'Mở lớp mới, sinh lịch học.'],
                'update' => ['Sửa lớp', 'Sửa lớp, lịch, đặt lịch học thử, dashboard đào tạo.'],
                'delete' => ['Xóa lớp', 'Xóa / hủy lớp.'],
            ],
            'audience' => [
                'teach' => ['Được xếp dạy lớp (GV chính / GVNN)', 'Có tên trong danh sách giáo viên chính / GVNN khi tạo, sửa lớp.'],
                'assist' => ['Được xếp làm trợ giảng lớp', 'Có tên trong danh sách trợ giảng khi tạo, sửa lớp.'],
            ],
            'scope' => [
                'levels' => ['own', 'branch', 'all'],
                'own' => 'Lớp tôi dạy / trợ giảng / GVNN (kể cả dạy thay theo buổi)',
                'branch' => 'Lớp của tôi + mọi lớp thuộc chi nhánh của tôi',
                'all' => 'Mọi lớp',
            ],
        ],
        'attendance_student' => [
            'label' => 'Điểm danh học viên',
            'group' => 'academic',
            'icon' => 'how_to_reg',
            'actions' => [
                'view' => ['Xem điểm danh', 'Xem điểm danh, chuyên cần của học viên.'],
                'record' => ['Điểm danh lớp mình', 'Dùng cổng giáo viên: điểm danh, giao bài, nhận xét, nhập điểm cho lớp mình được phân công.'],
                'record_any' => ['Điểm danh / nhập liệu thay GV', 'Thao tác trên cổng giáo viên thay giáo viên cho mọi lớp trong phạm vi dữ liệu Lớp học.'],
            ],
        ],
        'homework' => [
            'label' => 'Bài tập học viên nộp',
            'group' => 'academic',
            'icon' => 'assignment',
            'actions' => [
                'grade' => ['Chấm bài nộp', 'Xem và chấm bài tập học viên nộp qua cổng (lớp mình dạy; phạm vi Lớp học "Toàn hệ thống" thì mọi lớp).'],
            ],
        ],
        'level' => [
            'label' => 'Khung trình độ',
            'group' => 'academic',
            'icon' => 'stairs',
            'actions' => [
                'view' => ['Xem trình độ', 'Xem khung trình độ (CEFR).'],
                'create' => ['Thêm trình độ', 'Thêm trình độ.'],
                'update' => ['Sửa trình độ', 'Sửa, sắp xếp trình độ.'],
                'delete' => ['Xóa trình độ', 'Xóa trình độ.'],
            ],
        ],
        'syllabus' => [
            'label' => 'Giáo trình & chặng',
            'group' => 'academic',
            'icon' => 'menu_book',
            'actions' => [
                'view' => ['Xem giáo trình', 'Xem tài liệu, giáo trình, chặng, bài giảng.'],
                'update' => ['Nhập kết quả / cập nhật', 'Nhập kết quả Big Test, cập nhật nội dung buổi học.'],
                'manage' => ['Quản lý giáo trình', 'Soạn giáo trình / chặng / unit / buổi, giao chặng, tạo đợt Big Test, nhắc lịch.'],
                'upload' => ['Tải tài liệu', 'Tải lên / xóa tài liệu giáo trình.'],
                'propose_adjustment' => ['Đề xuất sửa / giãn tiến độ', 'Gửi đề xuất sửa giáo trình, xin điều chỉnh tiến độ.'],
                'approve_adjustment' => ['Duyệt đề xuất & tiến độ', 'Duyệt / từ chối đề xuất sửa giáo trình, điều chỉnh tiến độ; đóng chặng.'],
            ],
        ],
        'big_test' => [
            'label' => 'Big Test (Học thuật)',
            'group' => 'academic',
            'icon' => 'fact_check',
            'actions' => [
                'approve' => ['Duyệt Big Test', 'Duyệt order đề, phân phối đề, duyệt kết quả và gửi phụ huynh.'],
            ],
            'scope' => [
                'levels' => ['own', 'branch', 'all'],
                'own' => 'Đợt thi / order đề của lớp tôi phụ trách (hoặc do tôi gửi)',
                'branch' => 'Đợt thi / order đề của lớp tôi phụ trách + lớp thuộc chi nhánh của tôi',
                'all' => 'Mọi đợt thi / order đề',
            ],
        ],

        // ───────────────────────── Kế toán / Học phí ─────────────────────────
        'tuition' => [
            'label' => 'Học phí & phiếu thu',
            'group' => 'finance',
            'icon' => 'payments',
            'actions' => [
                'view' => ['Xem học phí', 'Xem học viên & công nợ, lịch sử thu, hoàn phí, quá hạn, cấu hình dải số.'],
                'create' => ['Lập phiếu thu', 'Lập / sửa phiếu thu của mình, nhập học phí từ Excel, ghi khoản chi.'],
                'approve' => ['Duyệt phiếu thu', 'Duyệt phiếu thu (không tự duyệt phiếu mình lập), xác nhận khoản thu trước khi chốt.'],
                'reject' => ['Trả về phiếu thu', 'Trả về phiếu thu kèm lý do.'],
                'mark_contacted' => ['Nhắc phí / đã liên hệ', 'Gửi nhắc phí, đánh dấu đã liên hệ phụ huynh.'],
                'report_overdue' => ['Báo cáo nợ quá hạn', 'Báo cáo khoản quá hạn cho Admin.'],
            ],
            'scope' => [
                'levels' => ['branch', 'all'],
                'branch' => 'Học phí của học viên thuộc chi nhánh của tôi',
                'all' => 'Học phí mọi chi nhánh (kế toán tổng)',
            ],
        ],
        'invoice' => [
            'label' => 'Hóa đơn & hủy hóa đơn',
            'group' => 'finance',
            'icon' => 'receipt_long',
            'actions' => [
                'request_cancel' => ['Yêu cầu hủy hóa đơn', 'Lập yêu cầu hủy hóa đơn.'],
                'approve_cancel' => ['Duyệt / từ chối hủy hóa đơn', 'Duyệt hoặc từ chối yêu cầu hủy hóa đơn.'],
            ],
        ],
        'refund_transfer' => [
            'label' => 'Hoàn phí, chuyển phí, khất nợ, bảo lưu',
            'group' => 'finance',
            'icon' => 'currency_exchange',
            'actions' => [
                'request' => ['Lập yêu cầu', 'Lập yêu cầu hoàn / chuyển / khất nợ / bảo lưu.'],
                'approve' => ['Duyệt khất nợ / bảo lưu', 'Duyệt yêu cầu khất nợ, bảo lưu.'],
                'approve_transfer' => ['Duyệt chuyển nhượng phí', 'Duyệt chuyển nhượng buổi dư sang học viên khác.'],
                'approve_refund' => ['Duyệt hoàn tiền (chi tiền)', 'Duyệt hoàn tiền cho phụ huynh (bắt buộc ảnh bằng chứng).'],
                'reject' => ['Từ chối yêu cầu', 'Từ chối yêu cầu hoàn / chuyển / khất nợ / bảo lưu.'],
            ],
        ],
        'bank_account' => [
            'label' => 'Tài khoản ngân hàng & SePay',
            'group' => 'finance',
            'icon' => 'account_balance',
            'actions' => [
                'manage' => ['Quản lý tài khoản & SePay', 'Thêm / sửa tài khoản nhận tiền, cấu hình SePay, xem thông số máy chủ.'],
            ],
        ],
        'invoice_range' => [
            'label' => 'Dải số hóa đơn',
            'group' => 'finance',
            'icon' => 'pin',
            'actions' => [
                'manage' => ['Cấu hình dải số chi nhánh', 'Thêm / bật / tắt dải số hóa đơn của chi nhánh trong phạm vi Học phí.'],
                'manage_default' => ['Cấu hình dải số mặc định', 'Cấu hình dải số dùng chung (không gắn chi nhánh).'],
            ],
        ],
        'fee_reminder_config' => [
            'label' => 'Cấu hình nhắc nợ',
            'group' => 'finance',
            'icon' => 'notifications_active',
            'actions' => [
                'manage' => ['Cấu hình nhắc nợ', 'Cấu hình mốc và mẫu nhắc nợ tự động.'],
            ],
        ],
        'finance' => [
            'label' => 'Báo cáo thu chi & sổ khoản chi',
            'group' => 'finance',
            'icon' => 'query_stats',
            'actions' => [
                'view' => ['Xem báo cáo thu chi', 'Xem doanh thu tạm tính, sổ khoản chi vận hành, xuất Excel.'],
            ],
            'scope' => [
                'levels' => ['branch', 'all'],
                'branch' => 'Số liệu chi nhánh của tôi',
                'all' => 'Số liệu mọi chi nhánh',
            ],
        ],

        // ───────────────────────── Nhân sự, Chấm công & Lương ─────────────────────────
        'user' => [
            'label' => 'Nhân sự & tài khoản',
            'group' => 'hr',
            'icon' => 'badge',
            'actions' => [
                'view' => ['Xem nhân sự', 'Xem danh sách, hồ sơ nhân sự (trong phạm vi dữ liệu).'],
                'create' => ['Tạo tài khoản', 'Tạo tài khoản nhân sự / học viên (chỉ vai trò được phép gán).'],
                'update' => ['Sửa tài khoản', 'Sửa hồ sơ, hợp đồng, vai trò chính.'],
                'delete' => ['Xóa tài khoản', 'Xóa (mềm) tài khoản.'],
                'lock' => ['Khóa / mở khóa', 'Khóa hoặc kích hoạt lại tài khoản.'],
                'reset_password' => ['Đặt lại mật khẩu', 'Đặt mật khẩu tạm, bắt buộc đổi ở lần đăng nhập sau.'],
                'assign_role' => ['Mở màn gán vai trò', 'Gán vai trò chính / kiêm nhiệm (chỉ các vai trò được phép gán bên dưới).'],
            ],
            // Quyền động "user.assign_role.<vai trò>": tạo / đổi tên / xóa cùng vai trò (xem App\Support\Rbac).
            'dynamic' => [
                'assign_role.' => ['Gán vai trò: :role', 'Được tạo tài khoản / gán vai trò ":role" cho người khác.'],
            ],
            'scope' => [
                'levels' => ['own', 'branch', 'all'],
                'own' => 'Tài khoản do tôi tạo',
                'branch' => 'Nhân sự thuộc chi nhánh của tôi',
                'all' => 'Mọi nhân sự',
            ],
        ],
        'attendance_staff' => [
            'label' => 'Chấm công nhân sự',
            'group' => 'hr',
            'icon' => 'schedule',
            'actions' => [
                'view' => ['Xem & duyệt chấm công', 'Xem giờ dạy, đối soát, xác nhận ca theo lịch, duyệt ca.'],
                'manual_record' => ['Chấm công thủ công', 'Chấm công tay / chỉnh ca (bắt buộc lý do).'],
                'sync' => ['Đồng bộ máy chấm công', 'Xem lịch sử đồng bộ, tải file lỗi.'],
            ],
            'scope' => [
                'levels' => ['own', 'branch', 'all'],
                'own' => 'Ca của lớp trong phạm vi Lớp học của tôi',
                'branch' => 'Ca của lớp thuộc chi nhánh của tôi + lớp của tôi',
                'all' => 'Mọi ca, mọi lớp',
            ],
        ],
        'payroll' => [
            'label' => 'Bảng lương',
            'group' => 'hr',
            'icon' => 'request_quote',
            'actions' => [
                'view' => ['Xem bảng lương', 'Xem kỳ lương, phiếu lương, lương cơ bản / CCCD của nhân sự.'],
                'create' => ['Tạo kỳ lương', 'Tạo kỳ lương mới.'],
                'edit' => ['Nhập khoản tay', 'Nhập khoản tay trên phiếu lương.'],
                'calculate' => ['Tính lương', 'Tính / tính lại bảng lương.'],
                'approve' => ['Duyệt bảng lương', 'Duyệt / chốt bảng lương (chặn khi còn người chưa chốt KPI).'],
                'mark_paid' => ['Đánh dấu đã chi trả', 'Đánh dấu kỳ lương đã chi trả.'],
                'view_own' => ['Xem lương của tôi', 'Xem phiếu lương, giờ dạy của chính mình.'],
            ],
            'scope' => [
                'levels' => ['own', 'branch', 'all'],
                'own' => 'Chỉ phiếu lương của tôi',
                'branch' => 'Phiếu lương nhân sự thuộc chi nhánh của tôi',
                'all' => 'Mọi phiếu lương',
            ],
        ],
        'kpi' => [
            'label' => 'KPI',
            'group' => 'hr',
            'icon' => 'insights',
            'actions' => [
                'view' => ['Xem KPI', 'Xem cấu hình KPI, tổng hợp KPI tháng, bảng xếp hạng, rà soát điểm danh.'],
                'confirm' => ['Chấm / chốt KPI', 'Chấm và chốt KPI tháng (không tự chấm cho mình), rà soát điểm danh.'],
                'manage' => ['Cấu hình tiêu chí KPI', 'Thêm / sửa / xóa tiêu chí KPI.'],
            ],
            'scope' => [
                'levels' => ['own', 'branch', 'all'],
                'own' => 'Chỉ KPI của tôi',
                'branch' => 'KPI nhân sự thuộc chi nhánh của tôi',
                'all' => 'KPI mọi nhân sự',
            ],
        ],
        'teacher_rate' => [
            'label' => 'Đơn giá & tham số lương',
            'group' => 'hr',
            'icon' => 'price_change',
            'actions' => [
                'manage' => ['Cấu hình đơn giá & tham số', 'Cấu hình tham số lương, đơn giá giáo viên (chung / cá nhân).'],
            ],
        ],
        'commission_config' => [
            'label' => 'Hoa hồng & thưởng tái tục',
            'group' => 'hr',
            'icon' => 'workspace_premium',
            'actions' => [
                'manage' => ['Cấu hình hoa hồng', 'Cấu hình bậc hoa hồng tuyển sinh, bảng thưởng tái tục.'],
            ],
        ],
        'violation' => [
            'label' => 'Vi phạm & kỷ luật',
            'group' => 'hr',
            'icon' => 'gavel',
            'actions' => [
                'view' => ['Xem mọi biên bản', 'Xem biên bản của mọi nhân sự (người khác chỉ xem biên bản của mình).'],
                'create' => ['Lập biên bản', 'Lập biên bản vi phạm.'],
                'confirm_error' => ['Xác nhận lỗi', 'Xác nhận lỗi vi phạm.'],
                'confirm_fine' => ['Quyết định mức phạt', 'Chốt biên bản và mức phạt (theo loại lỗi được phép chốt).'],
                'decide_academic' => ['Chốt lỗi chuyên môn', 'Được chốt biên bản lỗi chuyên môn / giảng dạy (HT).'],
                'decide_operations' => ['Chốt lỗi vận hành', 'Được chốt biên bản lỗi vận hành / nội quy (CM).'],
                'cancel' => ['Hủy biên bản', 'Hủy biên bản.'],
                'mark_paid' => ['Ghi nhận đã nộp phạt', 'Đánh dấu đã nộp phạt.'],
                'mark_resolved' => ['Ghi nhận khắc phục', 'Đánh dấu đã khắc phục / giải quyết.'],
            ],
        ],
        'staff_report' => [
            'label' => 'Nhật ký & báo cáo định kỳ',
            'group' => 'hr',
            'icon' => 'edit_note',
            'actions' => [
                'submit' => ['Viết nhật ký & báo cáo', 'Ghi nhật ký sự vụ, nộp báo cáo định kỳ của mình.'],
                'view_all' => ['Xem báo cáo của mọi người', 'Xem tổng hợp báo cáo & nhật ký của mọi nhân sự.'],
            ],
        ],

        // ───────────────────────── Vận hành & Hỗ trợ ─────────────────────────
        'work_task' => [
            'label' => 'Giao việc & trợ giảng',
            'group' => 'operations',
            'icon' => 'task',
            'actions' => [
                'view' => ['Xem công việc', 'Xem việc mình được giao / mình giao (và việc trong phạm vi dữ liệu).'],
                'create' => ['Giao việc', 'Giao việc cho nhân sự trong phạm vi.'],
                'update' => ['Sửa công việc', 'Sửa công việc.'],
                'approve' => ['Duyệt hoàn thành', 'Xác nhận / trả lại việc, báo cáo trực lớp; xem tab "Tất cả".'],
                'assign' => ['Giao việc trợ giảng', 'Giao nhiệm vụ ca cho trợ giảng, cấu hình lịch, buổi bổ trợ.'],
                'request' => ['Đề xuất việc ngược', 'Giáo viên / trợ giảng giao ngược việc cho người duyệt.'],
            ],
            'scope' => [
                'levels' => ['own', 'branch', 'all'],
                'own' => 'Việc tôi giao / được giao; KPI của tôi',
                'branch' => 'Việc thuộc chi nhánh của tôi (+ việc của tôi); KPI nhân sự chi nhánh',
                'all' => 'Mọi công việc; KPI mọi nhân sự',
            ],
        ],
        'support_ticket' => [
            'label' => 'Ticket hỗ trợ',
            'group' => 'operations',
            'icon' => 'support_agent',
            'actions' => [
                'view' => ['Xem ticket', 'Xem ticket trong phạm vi dữ liệu, trao đổi trong ticket.'],
                'create' => ['Tạo ticket', 'Tạo ticket hỗ trợ.'],
                'update' => ['Xử lý ticket', 'Được nhận xử lý ticket, cấu hình email nhận ticket.'],
                'assign' => ['Phân công ticket', 'Phân công người xử lý ticket.'],
                'close' => ['Đổi trạng thái / đóng', 'Đổi trạng thái, đóng ticket.'],
            ],
            'scope' => [
                'levels' => ['own', 'branch', 'all'],
                'own' => 'Ticket tôi tạo hoặc được giao xử lý',
                'branch' => 'Ticket của tôi + ticket do nhân sự chi nhánh tôi tạo',
                'all' => 'Mọi ticket',
            ],
        ],
        'notification' => [
            'label' => 'Thông báo',
            'group' => 'operations',
            'icon' => 'notifications',
            'actions' => [
                'view' => ['Xem thông báo của tôi', 'Xem hộp thông báo cá nhân.'],
                'view_system' => ['Xem thông báo chung', 'Xem cả thông báo chung của hệ thống (lead sót SLA 24h…).'],
                'manage' => ['Quét cảnh báo', 'Chạy quét lead chưa liên hệ, quản lý thông báo.'],
            ],
        ],
        'media' => [
            'label' => 'Media & tệp tin',
            'group' => 'operations',
            'icon' => 'perm_media',
            'actions' => [
                'view' => ['Xem media', 'Xem, tải tệp.'],
                'upload' => ['Tải lên', 'Tải tệp lên.'],
                'delete' => ['Xóa tệp', 'Xóa tệp, thư mục.'],
                'manage' => ['Quản lý thư mục', 'Tạo thư mục, di chuyển tệp.'],
            ],
        ],
        'survey' => [
            'label' => 'Khảo sát',
            'group' => 'operations',
            'icon' => 'ballot',
            'actions' => [
                'manage' => ['Quản lý đợt khảo sát', 'Tạo / sửa / xóa đợt khảo sát.'],
            ],
        ],
        'course' => [
            'label' => 'Khóa học & bảng giá',
            'group' => 'operations',
            'icon' => 'sell',
            'actions' => [
                'view' => ['Xem khóa học', 'Xem khóa học, bảng giá.'],
                'create' => ['Thêm khóa học', 'Thêm khóa học.'],
                'update' => ['Sửa khóa học', 'Sửa, bật / tắt khóa học.'],
                'delete' => ['Xóa khóa học', 'Xóa khóa học.'],
            ],
        ],
        'recruitment' => [
            'label' => 'Tuyển dụng',
            'group' => 'operations',
            'icon' => 'work',
            'actions' => [
                'view' => ['Xem tuyển dụng', 'Xem tin tuyển dụng, hồ sơ ứng viên.'],
                'manage' => ['Quản lý tuyển dụng', 'Đăng tin, đổi trạng thái hồ sơ.'],
            ],
        ],
        'report' => [
            'label' => 'Báo cáo tổng hợp',
            'group' => 'operations',
            'icon' => 'bar_chart',
            'actions' => [
                'view' => ['Xem báo cáo', 'Xem báo cáo thống kê (CRM, doanh số) trong phạm vi dữ liệu của module.'],
            ],
        ],
        'dashboard' => [
            'label' => 'Bảng điều khiển (Tổng quan)',
            'group' => 'operations',
            'icon' => 'dashboard',
            'actions' => [
                'operations' => ['Bảng điều hành', 'Số liệu vận hành: doanh thu tháng, học viên, lead, lớp, việc quá hạn, hàng chờ duyệt.'],
                'academic' => ['Bảng học thuật', 'Lớp đang chạy, đề xuất giáo trình / giãn tiến độ chờ duyệt, Big Test sắp tới.'],
            ],
            'scope' => [
                'levels' => ['branch', 'all'],
                'branch' => 'Số liệu chi nhánh của tôi',
                'all' => 'Số liệu toàn hệ thống',
            ],
        ],

        // ───────────────────────── Hệ thống & Phân quyền ─────────────────────────
        'role' => [
            'label' => 'Vai trò',
            'group' => 'system',
            'icon' => 'admin_panel_settings',
            'actions' => [
                'view' => ['Xem vai trò', 'Xem danh sách vai trò và ma trận quyền.'],
                'create' => ['Tạo / nhân bản vai trò', 'Tạo vai trò mới, nhân bản vai trò có sẵn.'],
                'update' => ['Sửa / đổi tên vai trò', 'Đổi tên hiển thị, mô tả vai trò.'],
                'delete' => ['Xóa vai trò', 'Xóa vai trò chưa gán cho ai (không xóa được Super Admin).'],
                'assign_permission' => ['Phân quyền cho vai trò', 'Bật / tắt quyền và phạm vi dữ liệu của vai trò.'],
            ],
        ],
        'permission' => [
            'label' => 'Danh mục quyền & phân quyền cá nhân',
            'group' => 'system',
            'icon' => 'security',
            'actions' => [
                'view' => ['Xem danh mục quyền', 'Xem danh mục quyền của hệ thống.'],
                'create' => ['Thêm quyền', 'Thêm quyền thủ công (quyền mới nên khai báo trong danh mục).'],
                'update' => ['Sửa quyền', 'Sửa tên quyền.'],
                'delete' => ['Xóa quyền', 'Xóa quyền chưa gán cho vai trò nào.'],
                'override' => ['Phân quyền cá nhân', 'Cho phép / chặn từng quyền và phạm vi dữ liệu cho từng người.'],
            ],
        ],
        'branch' => [
            'label' => 'Cơ sở & chi nhánh',
            'group' => 'system',
            'icon' => 'domain',
            'actions' => [
                'view' => ['Xem chi nhánh', 'Xem danh sách chi nhánh.'],
                'create' => ['Thêm chi nhánh', 'Thêm chi nhánh.'],
                'update' => ['Sửa chi nhánh', 'Sửa chi nhánh.'],
                'delete' => ['Xóa chi nhánh', 'Xóa chi nhánh.'],
                'manage' => ['Bật / tắt chi nhánh', 'Kích hoạt / ngừng chi nhánh.'],
            ],
        ],
        'system_category' => [
            'label' => 'Danh mục hệ thống',
            'group' => 'system',
            'icon' => 'settings',
            'actions' => [
                'manage' => ['Quản lý danh mục', 'Danh mục hệ thống, hàng hóa & vật phẩm, bộ màn mockup.'],
            ],
        ],
        'holiday' => [
            'label' => 'Ngày nghỉ lễ',
            'group' => 'system',
            'icon' => 'beach_access',
            'actions' => [
                'manage' => ['Quản lý ngày nghỉ', 'Thêm / sửa / xóa ngày nghỉ lễ, xếp bù.'],
            ],
        ],
        'activity_log' => [
            'label' => 'Nhật ký vận hành',
            'group' => 'system',
            'icon' => 'history',
            'actions' => [
                'view' => ['Xem nhật ký', 'Xem, lọc, xuất nhật ký thao tác (trước / sau).'],
                'undo' => ['Hoàn tác thao tác', 'Khôi phục giá trị cũ của một thao tác cập nhật.'],
            ],
            'scope' => [
                'levels' => ['own', 'branch', 'all'],
                'own' => 'Thao tác do tôi thực hiện',
                'branch' => 'Thao tác của nhân sự thuộc chi nhánh của tôi',
                'all' => 'Mọi thao tác',
            ],
        ],

        // ───────────────────────── Cổng & Đối tượng ─────────────────────────
        'portal' => [
            'label' => 'Cổng người dùng',
            'group' => 'portal',
            'icon' => 'door_open',
            'actions' => [],
            'audience' => [
                'staff' => ['Khu làm việc nhân sự', 'Tài khoản nhân sự: thấy menu ticket, vi phạm của tôi… (học viên thì không).'],
                'student' => ['Cổng học viên / phụ huynh', 'Tài khoản học viên: dùng cổng học viên (học tập, nộp bài, thông báo…).'],
                'teacher' => ['Cổng giáo viên', 'Là giáo viên: menu Cổng giáo viên, tài liệu dành cho giáo viên, giờ dạy của tôi.'],
                'assistant' => ['Cổng trợ giảng', 'Là trợ giảng: menu Cổng giáo viên, tài liệu dành cho trợ giảng, nhiệm vụ ca TA.'],
            ],
        ],
    ],
];
