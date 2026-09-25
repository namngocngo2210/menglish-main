<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class MockupHubController extends Controller
{
    public function index()
    {
        $academicScreens = AcademicSystemController::getScreens();

        $webAdminScreens = array_values(array_filter($academicScreens, fn($s) => $s['category_id'] === '01_Web_Admin'));
        $opsScreens = array_values(array_filter($academicScreens, fn($s) => $s['category_id'] === '02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu'));
        $teacherScreens = array_values(array_filter($academicScreens, fn($s) => $s['category_id'] === '03_Cong_Giao_Vien'));
        $parentScreens = array_values(array_filter($academicScreens, fn($s) => $s['category_id'] === '04_Cong_Phu_Huynh_Hoc_Sinh'));

        $mapScreen = function($s) {
            return [
                'num' => $s['num'],
                'name' => $s['title_vn'],
                'type' => 'academic',
                'icon' => $s['icon'],
                'route' => 'academic-system.show',
                'params' => ['category' => $s['category_id'], 'screen' => $s['folder_name']],
                'src' => 'roundcuoi-kieulien/' . $s['category_id'] . '/' . $s['folder_name'] . '/code.html',
                'desc' => $s['desc'],
            ];
        };

        $newModules = [
            [
                'id' => '01_Web_Admin',
                'name' => '1. Quản trị Học thuật (Web Admin) — 58 Màn',
                'icon' => 'admin_panel_settings',
                'badge' => count($webAdminScreens) . ' màn',
                'screens' => array_map($mapScreen, $webAdminScreens),
            ],
            [
                'id' => '02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu',
                'name' => '2. Học thuật, Vận hành lớp & KPI',
                'icon' => 'monitoring',
                'badge' => count($opsScreens) . ' màn',
                'screens' => array_map($mapScreen, $opsScreens),
            ],
            [
                'id' => '03_Cong_Giao_Vien',
                'name' => '3. Cổng Giáo Viên (Teacher Portal)',
                'icon' => 'co_present',
                'badge' => count($teacherScreens) . ' màn',
                'screens' => array_map($mapScreen, $teacherScreens),
            ],
            [
                'id' => '04_Cong_Phu_Huynh_Hoc_Sinh',
                'name' => '4. Cổng Phụ Huynh & Học Sinh',
                'icon' => 'family_restroom',
                'badge' => count($parentScreens) . ' màn',
                'screens' => array_map($mapScreen, $parentScreens),
            ],
        ];

        $oldModules = [
            [
                'id' => 'classes',
                'name' => '1. Flow 1: Tuyển sinh, Khai giảng & Quản lý Lớp học',
                'icon' => 'meeting_room',
                'badge' => '6 màn (100% Native)',
                'screens' => [
                    ['num' => 'F1-01', 'name' => 'Đặt lịch khách học thử (Popup #1)', 'type' => 'modal', 'icon' => 'event_available', 'route' => 'classes.trial-booking', 'src' => 'roundcuoi-kieulien/01_Web_Admin/12_dat_lich_hoc_thu_popup/code.html', 'desc' => 'Popup tiếp nhận và sắp xếp lịch học thử cho học viên mới vào lớp'],
                    ['num' => 'F1-02', 'name' => 'Tạo lớp mới (Form #2)', 'type' => 'form', 'icon' => 'group_add', 'route' => 'classes.create', 'src' => 'roundcuoi-kieulien/01_Web_Admin/13_tao_lop_moi/code.html', 'desc' => 'Biểu mẫu thiết lập lớp học mới: thông tin, giáo viên, phòng học, học phí'],
                    ['num' => 'F1-03', 'name' => 'Hồ sơ lớp học (Admin / Học vụ #3)', 'type' => 'dashboard', 'icon' => 'school', 'route' => 'classes.profile', 'src' => 'roundcuoi-kieulien/01_Web_Admin/14_ho_so_lop_hoc/code.html', 'desc' => 'Thông tin chi tiết hồ sơ lớp học và danh sách học sinh'],
                    ['num' => 'F1-04', 'name' => 'Sơ đồ khối lớp học thuật (#4)', 'type' => 'dashboard', 'icon' => 'grid_view', 'route' => 'classes.academic-overview', 'src' => 'roundcuoi-kieulien/02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu/13_tong_quan_danh_sach_lop_hoc_thuat/code.html', 'desc' => 'Dashboard tổng quan số lượng lớp đang chạy, theo khối và trình độ'],
                    ['num' => 'F1-05', 'name' => 'Danh sách lớp chi tiết (#5)', 'type' => 'table', 'icon' => 'table_chart', 'route' => 'classes.academic-list', 'src' => 'roundcuoi-kieulien/02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu/14_danh_sach_lop_chi_tiet_hoc_thuat/code.html', 'desc' => 'Danh sách đầy đủ tất cả lớp học, trạng thái, giáo viên và sĩ số'],
                    ['num' => 'F1-06', 'name' => 'Chi tiết lớp học học thuật (#6)', 'type' => 'dashboard', 'icon' => 'library_books', 'route' => 'classes.academic-detail', 'src' => 'roundcuoi-kieulien/02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu/15_chi_tiet_lop_hoc_hoc_thuat/code.html', 'desc' => 'Thông tin toàn diện của lớp: tiến độ syllabus, lịch Big Test, lịch dự giờ'],
                ]
            ],
            [
                'id' => 'crm',
                'name' => '2. CRM & Tuyển sinh',
                'icon' => 'pie_chart',
                'badge' => '10 màn',
                'screens' => [
                    ['num' => '01', 'name' => 'App Shell / Layout', 'type' => 'layout', 'icon' => 'dashboard', 'route' => 'crm.pipeline', 'src' => 'crm-ui-mockup/app-shell-layout/code.html'],
                    ['num' => '02', 'name' => 'Pipeline (Kanban)', 'type' => 'kanban', 'icon' => 'view_kanban', 'route' => 'crm.pipeline', 'src' => 'crm-ui-mockup/pipeline-tong-quan-giai-doan/code.html'],
                    ['num' => '03', 'name' => 'Danh sách Khách hàng', 'type' => 'table', 'icon' => 'list_alt', 'route' => 'crm.customers.index', 'src' => 'crm-ui-mockup/danh-sach-khach/code.html'],
                    ['num' => '04', 'name' => 'Thêm khách mới', 'type' => 'modal', 'icon' => 'person_add', 'route' => 'crm.customers.create', 'src' => 'crm-ui-mockup/them-khach-moi/code.html'],
                    ['num' => '05', 'name' => 'Chi tiết Khách hàng', 'type' => 'detail', 'icon' => 'person', 'route' => 'crm.customers.show', 'params' => ['id' => 101], 'src' => 'crm-ui-mockup/chi-tiet-khach-hang/code.html'],
                    ['num' => '06', 'name' => 'Sửa thông tin Khách', 'type' => 'modal', 'icon' => 'edit', 'route' => 'crm.customers.edit', 'params' => ['id' => 101], 'src' => 'crm-ui-mockup/sua-thong-tin-khach/code.html'],
                    ['num' => '07', 'name' => 'Khách chốt thành công', 'type' => 'table', 'icon' => 'how_to_reg', 'route' => 'crm.customers.won', 'src' => 'crm-ui-mockup/khach-hang-chot-thanh-cong/code.html'],
                    ['num' => '08', 'name' => 'Quy trình Chốt & Xếp lớp', 'type' => 'wizard', 'icon' => 'route', 'route' => 'crm.closing-wizard', 'src' => 'crm-ui-mockup/quy-trinh-chot-xep-lop/code.html'],
                    ['num' => '09', 'name' => 'Khách không chốt (Lost Deals)', 'type' => 'report', 'icon' => 'person_off', 'route' => 'crm.lost-deals', 'src' => 'crm-ui-mockup/khach-khong-chot-lost-deals/code.html'],
                    ['num' => '10', 'name' => 'Báo cáo Doanh số', 'type' => 'report', 'icon' => 'bar_chart', 'route' => 'crm.reports', 'src' => 'crm-ui-mockup/bao-cao-doanh-so/code.html'],
                ]
            ],
            [
                'id' => 'tuition',
                'name' => '2. Học phí & Hóa đơn',
                'icon' => 'receipt_long',
                'badge' => '7 màn',
                'screens' => [
                    ['num' => '11', 'name' => 'DS Học viên & Thu phí', 'type' => 'table', 'icon' => 'group', 'route' => 'tuition.students', 'src' => 'hoc-phi-va-hoa-don-ui-mockup/danh-sach-hoc-vien-thu-phi/code.html'],
                    ['num' => '12', 'name' => 'Nhập hàng loạt (Excel)', 'type' => 'wizard', 'icon' => 'upload_file', 'route' => 'tuition.import', 'src' => 'hoc-phi-va-hoa-don-ui-mockup/nhap-danh-sach-hang-loat/code.html'],
                    ['num' => '13', 'name' => 'Lập Phiếu thu Học phí', 'type' => 'form', 'icon' => 'receipt_long', 'route' => 'tuition.receipts.create', 'src' => 'hoc-phi-va-hoa-don-ui-mockup/lap-phieu-thu-hoc-phi/code.html'],
                    ['num' => '14', 'name' => 'Duyệt Phiếu thu', 'type' => 'detail', 'icon' => 'fact_check', 'route' => 'tuition.receipts.approve', 'src' => 'hoc-phi-va-hoa-don-ui-mockup/duyet-phieu-thu-hoc-phi/code.html'],
                    ['num' => '15', 'name' => 'Lịch sử Thu học phí', 'type' => 'detail', 'icon' => 'history', 'route' => 'tuition.history', 'src' => 'hoc-phi-va-hoa-don-ui-mockup/lich-su-thu-hoc-phi/code.html'],
                    ['num' => '16', 'name' => 'Duyệt / Hủy Hóa đơn', 'type' => 'table', 'icon' => 'cancel_presentation', 'route' => 'tuition.invoices.cancellations', 'src' => 'hoc-phi-va-hoa-don-ui-mockup/duyet-huy-hoa-don/code.html'],
                    ['num' => '17', 'name' => 'Hoàn tiền & Khất nợ', 'type' => 'form', 'icon' => 'currency_exchange', 'route' => 'tuition.refunds', 'src' => 'hoc-phi-va-hoa-don-ui-mockup/hoan-tien-va-khat-no/code.html'],
                ]
            ],
            [
                'id' => 'system',
                'name' => '3. Quản trị hệ thống (Epic 5)',
                'icon' => 'settings',
                'badge' => '7 màn',
                'screens' => [
                    ['num' => '18', 'name' => 'Quản lý Tài khoản & Vai trò', 'type' => 'table', 'icon' => 'manage_accounts', 'route' => 'users.index', 'src' => 'epic-5/quan-ly-tai-khoan-vai-tro/code.html'],
                    ['num' => '19', 'name' => 'Phân quyền chi tiết cá nhân', 'type' => 'form', 'icon' => 'admin_panel_settings', 'route' => 'roles.index', 'src' => 'epic-5/phan-quyen-chi-tiet-ca-nhan/code.html'],
                    ['num' => '20', 'name' => 'Quản lý Danh mục hệ thống', 'type' => 'table', 'icon' => 'category', 'route' => 'system-categories.index', 'src' => 'epic-5/quan-ly-danh-muc-he-thong/code.html'],
                    ['num' => '21', 'name' => 'Cấu hình Ngày nghỉ', 'type' => 'form', 'icon' => 'event_busy', 'route' => 'holidays.index', 'src' => 'epic-5/cau-hinh-ngay-nghi/code.html'],
                    ['num' => '22', 'name' => 'Cấu hình Nhắc nợ', 'type' => 'form', 'icon' => 'notifications_active', 'route' => 'system-config.debt-reminders', 'src' => 'epic-5/cau-hinh-nhac-no/code.html'],
                    ['num' => '23', 'name' => 'Tài khoản Ngân hàng', 'type' => 'form', 'icon' => 'account_balance', 'route' => 'system-config.bank-accounts', 'src' => 'epic-5/cau-hinh-tai-khoan-ngan-hang/code.html'],
                    ['num' => '24', 'name' => 'Nhật ký vận hành', 'type' => 'report', 'icon' => 'view_list', 'route' => 'activity-logs.index', 'src' => 'epic-5/nhat-ky-van-hanh/code.html'],
                ]
            ],
            [
                'id' => 'students',
                'name' => '4. Hồ sơ Học sinh (Epic 6)',
                'icon' => 'school',
                'badge' => '4 màn',
                'screens' => [
                    ['num' => '25', 'name' => 'DS & Liên kết lớp học sinh', 'type' => 'table', 'icon' => 'school', 'route' => 'students.index', 'src' => 'epic-6/ho-so-hoc-sinh-danh-sach-lien-ket-lop/code.html'],
                    ['num' => '26', 'name' => 'Chi tiết HS (Phân quyền)', 'type' => 'detail', 'icon' => 'badge', 'route' => 'students.scoped', 'params' => ['id' => 'HV-01'], 'src' => 'epic-6/chi-tiet-ho-so-hoc-sinh-phan-quyen/code.html'],
                    ['num' => '27', 'name' => 'Chi tiết HS (Desktop)', 'type' => 'detail', 'icon' => 'account_box', 'route' => 'students.show', 'params' => ['id' => 'HV-01'], 'src' => 'epic-6/chi-tiet-ho-so-hoc-sinh-desktop/code.html'],
                    ['num' => '28', 'name' => 'Khách chốt (Xác nhận nhập học)', 'type' => 'table', 'icon' => 'verified_user', 'route' => 'students.enrollments', 'src' => 'epic-6/khach-hang-chot-thanh-cong-xac-nhan/code.html'],
                ]
            ],
            [
                'id' => 'payroll',
                'name' => '5. Lương, Chấm công & KPI (Epic 7)',
                'icon' => 'payments',
                'badge' => '12 màn',
                'screens' => [
                    ['num' => '29', 'name' => 'DS Bảng lương theo kỳ', 'type' => 'table', 'icon' => 'payments', 'route' => 'payroll.periods.index', 'src' => 'epic-7/danh-sach-bang-luong-theo-ky/code.html'],
                    ['num' => '30', 'name' => 'Chi tiết Bảng lương', 'type' => 'detail', 'icon' => 'request_quote', 'route' => 'payroll.periods.show', 'params' => ['id' => 'BL-2026-08'], 'src' => 'epic-7/chi-tiet-bang-luong/code.html'],
                    ['num' => '31', 'name' => 'Bảng lương GV Full-time', 'type' => 'detail', 'icon' => 'account_balance_wallet', 'route' => 'payroll.periods.fulltime', 'params' => ['id' => 'BL-2026-08'], 'src' => 'epic-7/chi-tiet-bang-luong-gv-fulltime/code.html'],
                    ['num' => '32', 'name' => 'Bảng lương Học thuật', 'type' => 'detail', 'icon' => 'auto_stories', 'route' => 'payroll.periods.academic', 'params' => ['id' => 'BL-2026-08'], 'src' => 'epic-7/chi-tiet-bang-luong-hoc-thuat/code.html'],
                    ['num' => '33', 'name' => 'Bảng lương Học vụ', 'type' => 'detail', 'icon' => 'menu_book', 'route' => 'payroll.periods.operations', 'params' => ['id' => 'BL-2026-08'], 'src' => 'epic-7/chi-tiet-bang-luong-hoc-vu/code.html'],
                    ['num' => '34', 'name' => 'Chấm công thủ công', 'type' => 'form', 'icon' => 'edit_calendar', 'route' => 'payroll.timesheets.manual', 'src' => 'epic-7/cham-cong-thu-cong/code.html'],
                    ['num' => '35', 'name' => 'Chi tiết Chấm công GV', 'type' => 'detail', 'icon' => 'event_available', 'route' => 'payroll.timesheets.teachers', 'src' => 'epic-7/chi-tiet-cham-cong-theo-gv/code.html'],
                    ['num' => '36', 'name' => 'Lịch sử Đồng bộ Chấm công', 'type' => 'table', 'icon' => 'sync', 'route' => 'payroll.timesheets.sync-history', 'src' => 'epic-7/lich-su-dong-bo-cham-cong/code.html'],
                    ['num' => '37', 'name' => 'BXH KPI & Hoa hồng', 'type' => 'report', 'icon' => 'leaderboard', 'route' => 'payroll.kpi-leaderboard', 'src' => 'epic-7/bang-kpi-cong-khai/code.html'],
                    ['num' => '38', 'name' => 'Cấu hình Đơn giá GV', 'type' => 'form', 'icon' => 'price_change', 'route' => 'payroll.config.teacher-rates', 'src' => 'epic-7/cau-hinh-don-gia-giao-vien/code.html'],
                    ['num' => '39', 'name' => 'Cấu hình Mốc Hoa hồng', 'type' => 'form', 'icon' => 'military_tech', 'route' => 'payroll.config.commission-tiers', 'src' => 'epic-7/cau-hinh-moc-hoa-hong-thuong-tai-tuc/code.html'],
                    ['num' => '40', 'name' => 'Lương của tôi (Portal)', 'type' => 'detail', 'icon' => 'wallet', 'route' => 'portal.my-salary', 'src' => 'epic-7/luong-cua-toi-teacher-portal/code.html'],
                ]
            ],
            [
                'id' => 'overdue',
                'name' => '6. Thu phí quá hạn (Epic 8)',
                'icon' => 'alarm_on',
                'badge' => '1 màn',
                'screens' => [
                    ['num' => '41', 'name' => 'DS Thu phí quá hạn', 'type' => 'table', 'icon' => 'alarm_on', 'route' => 'tuition.overdue', 'src' => 'epic-8-thu-phi-qua-han/code.html'],
                ]
            ],
            [
                'id' => 'penalties',
                'name' => '7. Kỷ luật & Xử phạt (Epic 8)',
                'icon' => 'warning',
                'badge' => '1 màn',
                'screens' => [
                    ['num' => '42', 'name' => 'Danh sách Vi phạm & Xử phạt', 'type' => 'table', 'icon' => 'warning', 'route' => 'penalties.index', 'src' => 'epic-8-danh-sach-phat/code.html'],
                ]
            ],
            [
                'id' => 'levels',
                'name' => '8. Cấu hình Trình độ',
                'icon' => 'workspace_premium',
                'badge' => '1 màn',
                'screens' => [
                    ['num' => '43', 'name' => 'Cấu hình Trình độ & Syllabus', 'type' => 'form', 'icon' => 'workspace_premium', 'route' => 'course-levels.index', 'src' => 'cau-hinh-trinh-do/code.html'],
                ]
            ],
            [
                'id' => 'invoice-config',
                'name' => '9. Cấu hình Hóa đơn',
                'icon' => 'tune',
                'badge' => '1 màn',
                'screens' => [
                    ['num' => '44', 'name' => 'Dải số Hóa đơn', 'type' => 'form', 'icon' => 'tune', 'route' => 'tuition.config', 'src' => 'cauhinhhoadon-ui-mockup/code.html'],
                ]
            ],
            [
                'id' => 'placement-tests',
                'name' => '10. Quản lý Đề Test Đầu Vào',
                'icon' => 'quiz',
                'badge' => '4 màn',
                'screens' => [
                    ['num' => '45', 'name' => 'Danh sách Đề test đầu vào', 'type' => 'table', 'icon' => 'quiz', 'route' => 'placement-tests.index', 'src' => 'quan-ly-de-dau-vao-crm/qu_n_l_test_u_v_o_danh_s_ch_menglish_admin/code.html'],
                    ['num' => '46', 'name' => 'Tạo mới Đề test đầu vào', 'type' => 'form', 'icon' => 'post_add', 'route' => 'placement-tests.create', 'src' => 'quan-ly-de-dau-vao-crm/t_o_m_i_qu_n_l_test_menglish_admin/code.html'],
                    ['num' => '47', 'name' => 'Chi tiết & Nhận xét kết quả', 'type' => 'detail', 'icon' => 'assignment_turned_in', 'route' => 'placement-tests.results.show', 'params' => ['id' => 'TEST-01'], 'src' => 'quan-ly-de-dau-vao-crm/kh_i_test_online_chi_ti_t_kh_ch_h_ng_menglish_admin/code.html'],
                    ['num' => '48', 'name' => 'Thang điểm & Nhận xét', 'type' => 'report', 'icon' => 'auto_awesome', 'route' => 'placement-tests.rubric-guide', 'src' => 'Thang điểm + hướng dẫn nhận xét.html'],
                ]
            ],
            [
                'id' => 'syllabus',
                'name' => '11. Quản lý Syllabus & Big Test',
                'icon' => 'menu_book',
                'badge' => '8 màn',
                'screens' => [
                    ['num' => '49', 'name' => 'Quản lý Tài liệu & Giáo trình', 'type' => 'table', 'icon' => 'folder_shared', 'route' => 'syllabus.documents', 'src' => 'sylabuss/qu_n_l_t_i_li_u_gi_o_tr_nh_menglish_admin/code.html'],
                    ['num' => '50', 'name' => 'Soạn Syllabus theo chương', 'type' => 'form', 'icon' => 'menu_book', 'route' => 'syllabus.builder', 'src' => 'sylabuss/so_n_syllabus_theo_ch_ng_c_p_nh_t_to_n_di_n_menglish_admin/code.html'],
                    ['num' => '51', 'name' => 'Giao chương cho GV', 'type' => 'form', 'icon' => 'assignment_ind', 'route' => 'syllabus.assignments', 'src' => 'sylabuss/giao_ch_ng_cho_gv_c_p_nh_t_c_ch_ng_t_ng_menglish_admin/code.html'],
                    ['num' => '52', 'name' => 'Xuất & Sửa giáo trình', 'type' => 'form', 'icon' => 'edit_document', 'route' => 'syllabus.versions', 'src' => 'sylabuss/xu_t_s_a_gi_o_tr_nh_c_p_nh_t_x_l_menglish_admin/code.html'],
                    ['num' => '53', 'name' => 'Duyệt yêu cầu điều chỉnh tiến độ', 'type' => 'detail', 'icon' => 'rule', 'route' => 'syllabus.adjustment-requests', 'src' => 'sylabuss/duy_t_y_u_c_u_xin_i_u_ch_nh_ti_n_menglish_admin/code.html'],
                    ['num' => '54', 'name' => 'Duyệt phân phối Big Test', 'type' => 'detail', 'icon' => 'fact_check', 'route' => 'syllabus.big-tests.distribution', 'src' => 'sylabuss/duy_t_ph_n_ph_i_big_test_c_p_nh_t_sla_menglish_admin/code.html'],
                    ['num' => '55', 'name' => 'Nhắc lịch Big Test', 'type' => 'table', 'icon' => 'alarm', 'route' => 'syllabus.big-tests.schedules', 'src' => 'sylabuss/nh_c_l_ch_big_test_menglish_admin/code.html'],
                    ['num' => '56', 'name' => 'Duyệt KQ Big Test & Gửi Phụ huynh', 'type' => 'report', 'icon' => 'send_to_mobile', 'route' => 'syllabus.big-tests.results', 'src' => 'sylabuss/duy_t_k_t_qu_big_test_g_i_ph_huynh_menglish_admin/code.html'],
                ]
            ],
            [
                'id' => 'tasks',
                'name' => '12. Phân công công việc & Trợ giảng',
                'icon' => 'assignment_turned_in',
                'badge' => '9 màn',
                'screens' => [
                    ['num' => '57', 'name' => 'Danh sách công việc', 'type' => 'table', 'icon' => 'task_alt', 'route' => 'tasks.index', 'src' => 'phan-cong-cong-viec/danh_s_ch_c_ng_vi_c_menglish_admin/code.html'],
                    ['num' => '58', 'name' => 'Form Giao việc mới', 'type' => 'form', 'icon' => 'add_task', 'route' => 'tasks.create', 'src' => 'phan-cong-cong-viec/form_giao_vi_c_menglish_admin/code.html'],
                    ['num' => '59', 'name' => 'Dashboard Lớp học (Ngày / Tuần)', 'type' => 'dashboard', 'icon' => 'dashboard', 'route' => 'tasks.classes-dashboard', 'src' => 'phan-cong-cong-viec/dashboard_l_p_h_c_theo_ng_y_ma_tr_n_khung_gi_tu_n_menglish_admin/code.html'],
                    ['num' => '60', 'name' => 'Tạo lượt giao việc cho Trợ giảng', 'type' => 'form', 'icon' => 'assignment_ind', 'route' => 'tasks.ta-assign', 'src' => 'phan-cong-cong-viec/t_o_l_t_giao_vi_c_cho_tr_gi_ng_menglish_admin/code.html'],
                    ['num' => '61', 'name' => 'Nhiệm vụ hôm nay (TA Portal)', 'type' => 'portal', 'icon' => 'checklist', 'route' => 'portal.ta-tasks', 'src' => 'phan-cong-cong-viec/nhi_m_v_h_m_nay_ta_menglish_admin/code.html'],
                    ['num' => '62', 'name' => 'Nộp báo cáo trực lớp TA', 'type' => 'form', 'icon' => 'assignment', 'route' => 'tasks.class-reports.create', 'src' => 'phan-cong-cong-viec/n_p_b_o_c_o_tr_c_l_p_ta_menglish_admin/code.html'],
                    ['num' => '63', 'name' => 'Xác nhận hoàn thành thủ công', 'type' => 'detail', 'icon' => 'fact_check', 'route' => 'tasks.manual-approvals', 'src' => 'phan-cong-cong-viec/x_c_nh_n_ho_n_th_nh_th_c_ng_menglish_admin/code.html'],
                    ['num' => '64', 'name' => 'TKB & Báo cáo Nhân sự', 'type' => 'form', 'icon' => 'calendar_month', 'route' => 'tasks.schedule-config', 'src' => 'phan-cong-cong-viec/tkb_c_u_h_nh_l_ch_l_p_b_o_c_o_ph_ng_nh_n_s_menglish_admin/code.html'],
                    ['num' => '65', 'name' => 'Bảng KPI tự động', 'type' => 'report', 'icon' => 'monitoring', 'route' => 'tasks.kpi-dashboard', 'src' => 'phan-cong-cong-viec/b_ng_kpi_t_ng_menglish_admin/code.html'],
                ]
            ],
            [
                'id' => 'finance-epic13',
                'name' => '13. Báo cáo Thu Chi & Doanh thu (Epic 13)',
                'icon' => 'query_stats',
                'badge' => '2 màn',
                'screens' => [
                    ['num' => '66', 'name' => 'Sổ khoản chi vận hành', 'type' => 'table', 'icon' => 'payments', 'route' => 'finance.expenses.index', 'src' => 'epic-13-bao-cao-thu-chi/kho_n_chi_v_n_h_nh_menglish_admin/code.html'],
                    ['num' => '67', 'name' => 'Báo cáo Doanh thu tạm tính', 'type' => 'dashboard', 'icon' => 'query_stats', 'route' => 'finance.reports.revenue', 'src' => 'epic-13-bao-cao-thu-chi/b_o_c_o_doanh_thu_t_m_t_nh_menglish_admin/code.html'],
                ]
            ],
        ];

        $modules = array_merge($newModules, $oldModules);
        return view('mockups.hub', compact('modules'));
    }
}
