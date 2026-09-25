<?php

namespace App\Http\Controllers;

use App\Models\AcademicRecord;
use App\Models\ClassModel;
use Illuminate\Http\Request;

class AcademicSystemController extends Controller
{
    /**
     * Danh sách 58 màn hình hệ thống MENGLISH (Round Cuối)
     */
    public static function getScreens(): array
    {
        return [
            // Category 01: Web Admin (14 screens)
            [
                'category_id' => '01_Web_Admin',
                'category_name' => '01. Web Admin (Quản trị hệ thống)',
                'folder_name' => '01_quan_ly_tai_lieu_giao_trinh',
                'title_vn' => 'Quản lý tài liệu giáo trình',
                'desc' => 'Quản lý, upload và phân loại các tài liệu giáo trình học tập',
                'has_png' => true,
                'icon' => 'folder_shared',
                'num' => '01',
            ],
            [
                'category_id' => '01_Web_Admin',
                'category_name' => '01. Web Admin (Quản trị hệ thống)',
                'folder_name' => '02_soan_syllabus_theo_chang',
                'title_vn' => 'Soạn syllabus theo chặng',
                'desc' => 'Biên soạn khung chương trình và syllabus chi tiết theo từng chặng học',
                'has_png' => true,
                'icon' => 'menu_book',
                'num' => '02',
            ],
            [
                'category_id' => '01_Web_Admin',
                'category_name' => '01. Web Admin (Quản trị hệ thống)',
                'folder_name' => '03_giao_chang_cho_giao_vien',
                'title_vn' => 'Giao chặng học cho giáo viên',
                'desc' => 'Phân quyền và bàn giao chặng học cụ thể cho từng giáo viên phụ trách',
                'has_png' => true,
                'icon' => 'assignment_ind',
                'num' => '03',
            ],
            [
                'category_id' => '01_Web_Admin',
                'category_name' => '01. Web Admin (Quản trị hệ thống)',
                'folder_name' => '04_duyet_yeu_cau_dieu_chinh_tien_do',
                'title_vn' => 'Duyệt yêu cầu xin điều chỉnh tiến độ',
                'desc' => 'Admin phê duyệt/từ chối yêu cầu thay đổi tiến độ giảng dạy từ giáo viên',
                'has_png' => true,
                'icon' => 'rule',
                'num' => '04',
            ],
            [
                'category_id' => '01_Web_Admin',
                'category_name' => '01. Web Admin (Quản trị hệ thống)',
                'folder_name' => '05_chi_tiet_de_xuat_sua_giao_trinh',
                'title_vn' => 'Chi tiết đề xuất sửa giáo trình',
                'desc' => 'Admin xem chi tiết và xử lý đề xuất chỉnh sửa giáo trình từ giáo viên',
                'has_png' => true,
                'icon' => 'edit_note',
                'num' => '05',
            ],
            [
                'category_id' => '01_Web_Admin',
                'category_name' => '01. Web Admin (Quản trị hệ thống)',
                'folder_name' => '06_duyet_phan_phoi_de_big_test',
                'title_vn' => 'Duyệt & phân phối đề Big Test',
                'desc' => 'Kiểm duyệt đề thi Big Test và phân phối đề thi tới các lớp theo SLA',
                'has_png' => true,
                'icon' => 'fact_check',
                'num' => '06',
            ],
            [
                'category_id' => '01_Web_Admin',
                'category_name' => '01. Web Admin (Quản trị hệ thống)',
                'folder_name' => '07_duyet_ket_qua_big_test_gui_phu_huynh',
                'title_vn' => 'Duyệt kết quả Big Test & gửi phụ huynh',
                'desc' => 'Admin xem xét điểm số Big Test, nhận xét và phê duyệt gửi báo cáo về cho phụ huynh',
                'has_png' => true,
                'icon' => 'send_to_mobile',
                'num' => '07',
            ],
            [
                'category_id' => '01_Web_Admin',
                'category_name' => '01. Web Admin (Quản trị hệ thống)',
                'folder_name' => '08_nhac_lich_big_test',
                'title_vn' => 'Nhắc lịch Big Test',
                'desc' => 'Theo dõi và gửi thông báo nhắc nhở lịch thi Big Test sắp tới',
                'has_png' => true,
                'icon' => 'alarm',
                'num' => '08',
            ],
            [
                'category_id' => '01_Web_Admin',
                'category_name' => '01. Web Admin (Quản trị hệ thống)',
                'folder_name' => '09_cham_cong_theo_lich',
                'title_vn' => 'Chấm công theo lịch',
                'desc' => 'Quản lý và theo dõi dữ liệu chấm công giáo viên theo lịch giảng dạy',
                'has_png' => true,
                'icon' => 'calendar_month',
                'num' => '09',
            ],
            [
                'category_id' => '01_Web_Admin',
                'category_name' => '01. Web Admin (Quản trị hệ thống)',
                'folder_name' => '10_doi_soat_chot_bang_cong',
                'title_vn' => 'Đối soát — chốt bảng công',
                'desc' => 'Đối soát số liệu chấm công, duyệt và chốt bảng công hàng tháng',
                'has_png' => true,
                'icon' => 'verified',
                'num' => '10',
            ],
            [
                'category_id' => '01_Web_Admin',
                'category_name' => '01. Web Admin (Quản trị hệ thống)',
                'folder_name' => '11_danh_sach_buoi_day_thay_cho_xac_nhan',
                'title_vn' => 'Danh sách buổi dạy thay chờ xác nhận',
                'desc' => 'Quản lý và phê duyệt các yêu cầu dạy thay giữa các giáo viên',
                'has_png' => true,
                'icon' => 'published_with_changes',
                'num' => '11',
            ],
            [
                'category_id' => '01_Web_Admin',
                'category_name' => '01. Web Admin (Quản trị hệ thống)',
                'folder_name' => '12_dat_lich_hoc_thu_popup',
                'title_vn' => 'Đặt lịch khách học thử vào buổi popup',
                'desc' => 'Popup tiếp nhận và sắp xếp lịch học thử cho học viên mới vào lớp',
                'has_png' => true,
                'icon' => 'event_available',
                'num' => '12',
            ],
            [
                'category_id' => '01_Web_Admin',
                'category_name' => '01. Web Admin (Quản trị hệ thống)',
                'folder_name' => '13_tao_lop_moi',
                'title_vn' => 'Tạo lớp mới - Web Admin',
                'desc' => 'Biểu mẫu thiết lập lớp học mới: thông tin, giáo viên, phòng học, học phí',
                'has_png' => true,
                'icon' => 'group_add',
                'num' => '13',
            ],
            [
                'category_id' => '01_Web_Admin',
                'category_name' => '01. Web Admin (Quản trị hệ thống)',
                'folder_name' => '14_ho_so_lop_hoc',
                'title_vn' => 'Hồ sơ lớp học - Web Admin',
                'desc' => 'Thông tin chi tiết hồ sơ lớp học và danh sách học sinh',
                'has_png' => false,
                'icon' => 'school',
                'num' => '14',
            ],

            // Category 02: Quản Lý Học Thuật Và Học Vụ (20 screens)
            [
                'category_id' => '02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu',
                'category_name' => '02. Quản lý Học thuật & Học vụ & KPI',
                'folder_name' => '01_cau_hinh_kpi_hoc_vu_1',
                'title_vn' => 'Cấu hình KPI Học vụ (Mẫu 1)',
                'desc' => 'Thiết lập các chỉ số KPI trọng số dành cho nhân sự học vụ',
                'has_png' => true,
                'icon' => 'tune',
                'num' => '15',
            ],
            [
                'category_id' => '02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu',
                'category_name' => '02. Quản lý Học thuật & Học vụ & KPI',
                'folder_name' => '02_cau_hinh_kpi_hoc_vu_2',
                'title_vn' => 'Cấu hình KPI Học vụ (Mẫu 2)',
                'desc' => 'Giao diện cấu hình KPI nâng cao cho học vụ quản lý lớp',
                'has_png' => true,
                'icon' => 'display_settings',
                'num' => '16',
            ],
            [
                'category_id' => '02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu',
                'category_name' => '02. Quản lý Học thuật & Học vụ & KPI',
                'folder_name' => '03_tong_hop_kpi_danh_gia_thang',
                'title_vn' => 'Tổng hợp KPI & Đánh giá tháng',
                'desc' => 'Bảng tổng hợp điểm KPI, đánh giá hiệu suất nhân sự hàng tháng',
                'has_png' => true,
                'icon' => 'leaderboard',
                'num' => '17',
            ],
            [
                'category_id' => '02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu',
                'category_name' => '02. Quản lý Học thuật & Học vụ & KPI',
                'folder_name' => '04_kpi_thang',
                'title_vn' => 'KPI tháng Học vụ',
                'desc' => 'Báo cáo chi tiết KPI tháng theo từng tiêu chí học vụ',
                'has_png' => true,
                'icon' => 'monitoring',
                'num' => '18',
            ],
            [
                'category_id' => '02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu',
                'category_name' => '02. Quản lý Học thuật & Học vụ & KPI',
                'folder_name' => '05_nhat_ky_hoc_vu',
                'title_vn' => 'Nhật ký Học vụ',
                'desc' => 'Nhật ký theo dõi các công việc và sự vụ phát sinh hàng ngày của học vụ',
                'has_png' => true,
                'icon' => 'auto_stories',
                'num' => '19',
            ],
            [
                'category_id' => '02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu',
                'category_name' => '02. Quản lý Học thuật & Học vụ & KPI',
                'folder_name' => '06_bao_cao_ngay_hoc_vu',
                'title_vn' => 'Báo cáo ngày Học vụ',
                'desc' => 'Báo cáo tổng kết công việc, tỷ lệ chuyên cần và sĩ số trong ngày',
                'has_png' => true,
                'icon' => 'today',
                'num' => '20',
            ],
            [
                'category_id' => '02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu',
                'category_name' => '02. Quản lý Học thuật & Học vụ & KPI',
                'folder_name' => '07_nhap_bao_cao_tuan_hoc_vu',
                'title_vn' => 'Nhập Báo cáo tuần Học vụ',
                'desc' => 'Form nhập số liệu và nhận xét báo cáo tuần dành cho học vụ',
                'has_png' => true,
                'icon' => 'post_add',
                'num' => '21',
            ],
            [
                'category_id' => '02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu',
                'category_name' => '02. Quản lý Học thuật & Học vụ & KPI',
                'folder_name' => '08_qa_observation_du_gio_van_hanh',
                'title_vn' => 'QA Observation — Dự giờ đội vận hành',
                'desc' => 'Bảng tiêu chí đánh giá QA dự giờ và quan sát vận hành lớp học',
                'has_png' => true,
                'icon' => 'visibility',
                'num' => '22',
            ],
            [
                'category_id' => '02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu',
                'category_name' => '02. Quản lý Học thuật & Học vụ & KPI',
                'folder_name' => '09_checklist_hoc_phi_feedback_theo_lop',
                'title_vn' => 'Checklist Học phí & Feedback theo lớp',
                'desc' => 'Checklist kiểm tra tình trạng thu học phí và thu thập feedback phụ huynh',
                'has_png' => true,
                'icon' => 'checklist',
                'num' => '23',
            ],
            [
                'category_id' => '02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu',
                'category_name' => '02. Quản lý Học thuật & Học vụ & KPI',
                'folder_name' => '10_ra_soat_diem_danh_hoc_vu_admin',
                'title_vn' => 'Rà soát điểm danh - Học vụ / Admin',
                'desc' => 'Rà soát dữ liệu điểm danh, xử lý các trường hợp vắng học và can thiệp điểm danh',
                'has_png' => true,
                'icon' => 'how_to_reg',
                'num' => '24',
            ],
            [
                'category_id' => '02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu',
                'category_name' => '02. Quản lý Học thuật & Học vụ & KPI',
                'folder_name' => '11_chi_tiet_bang_luong_hoc_vu',
                'title_vn' => 'Chi tiết bảng lương Học vụ',
                'desc' => 'Bảng tính chi tiết lương cố định, KPI, thưởng và các khoản giảm trừ',
                'has_png' => true,
                'icon' => 'payments',
                'num' => '25',
            ],
            [
                'category_id' => '02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu',
                'category_name' => '02. Quản lý Học thuật & Học vụ & KPI',
                'folder_name' => '12_rollup_thu_hoc_phi_theo_lop',
                'title_vn' => 'Rollup thu học phí theo lớp',
                'desc' => 'Báo cáo tổng hợp tiến độ và số tiền thu học phí theo từng lớp',
                'has_png' => true,
                'icon' => 'account_balance_wallet',
                'num' => '26',
            ],
            [
                'category_id' => '02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu',
                'category_name' => '02. Quản lý Học thuật & Học vụ & KPI',
                'folder_name' => '13_tong_quan_danh_sach_lop_hoc_thuat',
                'title_vn' => 'Tổng quan Danh sách lớp Học thuật',
                'desc' => 'Dashboard tổng quan số lượng lớp đang chạy, theo khối và trình độ',
                'has_png' => true,
                'icon' => 'dashboard',
                'num' => '27',
            ],
            [
                'category_id' => '02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu',
                'category_name' => '02. Quản lý Học thuật & Học vụ & KPI',
                'folder_name' => '14_danh_sach_lop_chi_tiet_hoc_thuat',
                'title_vn' => 'Danh sách lớp chi tiết Học thuật',
                'desc' => 'Danh sách đầy đủ tất cả lớp học, trạng thái, giáo viên và sĩ số',
                'has_png' => true,
                'icon' => 'table_view',
                'num' => '28',
            ],
            [
                'category_id' => '02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu',
                'category_name' => '02. Quản lý Học thuật & Học vụ & KPI',
                'folder_name' => '15_chi_tiet_lop_hoc_hoc_thuat',
                'title_vn' => 'Chi tiết lớp học Học thuật',
                'desc' => 'Thông tin toàn diện của lớp: tiến độ syllabus, lịch Big Test, lịch dự giờ',
                'has_png' => true,
                'icon' => 'class',
                'num' => '29',
            ],
            [
                'category_id' => '02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu',
                'category_name' => '02. Quản lý Học thuật & Học vụ & KPI',
                'folder_name' => '16_danh_gia_du_gio_hoc_thuat',
                'title_vn' => 'Đánh giá dự giờ Học thuật',
                'desc' => 'Phiếu đánh giá dự giờ giáo viên: phương pháp giảng dạy, tương tác, chuyên môn',
                'has_png' => true,
                'icon' => 'rate_review',
                'num' => '30',
            ],
            [
                'category_id' => '02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu',
                'category_name' => '02. Quản lý Học thuật & Học vụ & KPI',
                'folder_name' => '17_bao_cao_hop_giao_vien_theo_tuan',
                'title_vn' => 'Báo cáo họp giáo viên theo tuần',
                'desc' => 'Biên bản và báo cáo nội dung cuộc họp chuyên môn giáo viên định kỳ hàng tuần',
                'has_png' => true,
                'icon' => 'groups',
                'num' => '31',
            ],
            [
                'category_id' => '02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu',
                'category_name' => '02. Quản lý Học thuật & Học vụ & KPI',
                'folder_name' => '18_bao_cao_tuan_hoc_thuat',
                'title_vn' => 'Báo cáo tuần — Học thuật',
                'desc' => 'Báo cáo công việc học thuật tuần: kết quả thực hiện, tồn đọng và kế hoạch',
                'has_png' => true,
                'icon' => 'view_week',
                'num' => '32',
            ],
            [
                'category_id' => '02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu',
                'category_name' => '02. Quản lý Học thuật & Học vụ & KPI',
                'folder_name' => '19_bao_cao_thang_hoc_thuat',
                'title_vn' => 'Báo cáo tháng — Học thuật',
                'desc' => 'Tổng hợp báo cáo tháng khối học thuật: chất lượng đào tạo, chỉ số vận hành',
                'has_png' => true,
                'icon' => 'calendar_view_month',
                'num' => '33',
            ],
            [
                'category_id' => '02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu',
                'category_name' => '02. Quản lý Học thuật & Học vụ & KPI',
                'folder_name' => '20_bao_cao_quy_hoc_thuat',
                'title_vn' => 'Báo cáo quý — Học thuật',
                'desc' => 'Báo cáo tổng kết quý của phòng học thuật',
                'has_png' => true,
                'icon' => 'assessment',
                'num' => '34',
            ],

            // Category 03: Cổng Giáo Viên (17 screens)
            [
                'category_id' => '03_Cong_Giao_Vien',
                'category_name' => '03. Cổng Giáo viên (Teacher Portal)',
                'folder_name' => '01_app_shell_cong_giao_vien',
                'title_vn' => 'App Shell Cổng Giáo viên (Tổng quan)',
                'desc' => 'Màn hình dashboard trang chủ giáo viên: lịch dạy hôm nay, thông báo, lớp phụ trách',
                'has_png' => true,
                'icon' => 'home',
                'num' => '35',
            ],
            [
                'category_id' => '03_Cong_Giao_Vien',
                'category_name' => '03. Cổng Giáo viên (Teacher Portal)',
                'folder_name' => '02_diem_danh_lop_giao_vien',
                'title_vn' => 'Điểm danh lớp - Cổng Giáo viên',
                'desc' => 'Màn hình giáo viên thực hiện điểm danh học sinh từng buổi học',
                'has_png' => true,
                'icon' => 'checklist_rtl',
                'num' => '36',
            ],
            [
                'category_id' => '03_Cong_Giao_Vien',
                'category_name' => '03. Cổng Giáo viên (Teacher Portal)',
                'folder_name' => '03_giao_bai_tap_ve_nha',
                'title_vn' => 'Giao bài tập về nhà - Cổng Giáo viên',
                'desc' => 'Giáo viên thiết lập bài tập về nhà: yêu cầu video, bài viết, workbook, tài liệu đính kèm',
                'has_png' => true,
                'icon' => 'assignment',
                'num' => '37',
            ],
            [
                'category_id' => '03_Cong_Giao_Vien',
                'category_name' => '03. Cổng Giáo viên (Teacher Portal)',
                'folder_name' => '04_bai_nop_cua_lop',
                'title_vn' => 'Bài nộp của lớp (Học sinh nộp video & bài viết)',
                'desc' => 'Giáo viên theo dõi danh sách học viên nộp bài tập, xem thumbnail video & hình ảnh bài làm',
                'has_png' => true,
                'icon' => 'video_library',
                'num' => '38',
            ],
            [
                'category_id' => '03_Cong_Giao_Vien',
                'category_name' => '03. Cổng Giáo viên (Teacher Portal)',
                'folder_name' => '05_nhan_xet_buoi_hoc_cho_tung_hoc_sinh',
                'title_vn' => 'Nhận xét buổi học cho từng học sinh',
                'desc' => 'Giáo viên nhập nhận xét, thái độ học tập và mức độ tiếp thu của từng em sau buổi học',
                'has_png' => true,
                'icon' => 'comment',
                'num' => '39',
            ],
            [
                'category_id' => '03_Cong_Giao_Vien',
                'category_name' => '03. Cổng Giáo viên (Teacher Portal)',
                'folder_name' => '06_nhap_diem_mini_test',
                'title_vn' => 'Nhập điểm mini test',
                'desc' => 'Giáo viên nhập điểm kiểm tra định kỳ nhỏ (mini test) cho từng học sinh',
                'has_png' => true,
                'icon' => 'grade',
                'num' => '40',
            ],
            [
                'category_id' => '03_Cong_Giao_Vien',
                'category_name' => '03. Cổng Giáo viên (Teacher Portal)',
                'folder_name' => '07_chang_dang_day_va_order_test',
                'title_vn' => 'Chặng đang dạy & Order Test',
                'desc' => 'Theo dõi chặng học hiện tại và yêu cầu đặt đề kiểm tra (Order Test) cho lớp',
                'has_png' => true,
                'icon' => 'quiz',
                'num' => '41',
            ],
            [
                'category_id' => '03_Cong_Giao_Vien',
                'category_name' => '03. Cổng Giáo viên (Teacher Portal)',
                'folder_name' => '08_xem_tai_lieu_giao_trinh',
                'title_vn' => 'Xem tài liệu giáo trình & nội dung buổi',
                'desc' => 'Giáo viên tra cứu tài liệu bài giảng, giáo án và slide theo từng buổi học',
                'has_png' => true,
                'icon' => 'menu_book',
                'num' => '42',
            ],
            [
                'category_id' => '03_Cong_Giao_Vien',
                'category_name' => '03. Cổng Giáo viên (Teacher Portal)',
                'folder_name' => '09_de_xuat_sua_giao_trinh',
                'title_vn' => 'Đề xuất sửa giáo trình',
                'desc' => 'Giáo viên gửi đề xuất sửa đổi lỗi hoặc nội dung trong giáo trình lên ban học thuật',
                'has_png' => true,
                'icon' => 'edit_document',
                'num' => '43',
            ],
            [
                'category_id' => '03_Cong_Giao_Vien',
                'category_name' => '03. Cổng Giáo viên (Teacher Portal)',
                'folder_name' => '10_lich_du_kien_big_test',
                'title_vn' => 'Lịch dự kiến Big Test',
                'desc' => 'Giáo viên theo dõi lịch thi Big Test dự kiến của các lớp',
                'has_png' => true,
                'icon' => 'event',
                'num' => '44',
            ],
            [
                'category_id' => '03_Cong_Giao_Vien',
                'category_name' => '03. Cổng Giáo viên (Teacher Portal)',
                'folder_name' => '11_report_thang_lich_big_test',
                'title_vn' => 'Report tháng — Lịch Big Test',
                'desc' => 'Báo cáo hàng tháng về tình hình tổ chức thi Big Test của các lớp',
                'has_png' => true,
                'icon' => 'summarize',
                'num' => '45',
            ],
            [
                'category_id' => '03_Cong_Giao_Vien',
                'category_name' => '03. Cổng Giáo viên (Teacher Portal)',
                'folder_name' => '12_xac_nhan_quiz_desktop',
                'title_vn' => 'Xác nhận Quiz (Desktop)',
                'desc' => 'Màn hình desktop giáo viên kiểm tra và xác nhận kết quả làm Quiz của học viên',
                'has_png' => true,
                'icon' => 'desktop_windows',
                'num' => '46',
            ],
            [
                'category_id' => '03_Cong_Giao_Vien',
                'category_name' => '03. Cổng Giáo viên (Teacher Portal)',
                'folder_name' => '13_xac_nhan_quiz_mobile',
                'title_vn' => 'Xác nhận Quiz (Mobile)',
                'desc' => 'Màn hình mobile giáo viên kiểm tra và duyệt kết quả làm Quiz của học sinh',
                'has_png' => true,
                'icon' => 'smartphone',
                'num' => '47',
            ],
            [
                'category_id' => '03_Cong_Giao_Vien',
                'category_name' => '03. Cổng Giáo viên (Teacher Portal)',
                'folder_name' => '14_xin_dieu_chinh_tien_do',
                'title_vn' => 'Gửi yêu cầu xin điều chỉnh tiến độ',
                'desc' => 'Giáo viên gửi đề xuất điều chỉnh nhanh/chậm tiến độ học so với syllabus',
                'has_png' => true,
                'icon' => 'update',
                'num' => '48',
            ],
            [
                'category_id' => '03_Cong_Giao_Vien',
                'category_name' => '03. Cổng Giáo viên (Teacher Portal)',
                'folder_name' => '15_check_in_cua_toi',
                'title_vn' => 'Check-in của tôi',
                'desc' => 'Màn hình check-in / check-out khi giáo viên đến trung tâm dạy học',
                'has_png' => true,
                'icon' => 'pin_drop',
                'num' => '49',
            ],
            [
                'category_id' => '03_Cong_Giao_Vien',
                'category_name' => '03. Cổng Giáo viên (Teacher Portal)',
                'folder_name' => '16_gui_bao_cao_cham_cong',
                'title_vn' => 'Gửi báo cáo chấm công',
                'desc' => 'Màn hình gửi số giờ dạy, ca dạy để xác nhận công cuối kỳ',
                'has_png' => true,
                'icon' => 'send',
                'num' => '50',
            ],
            [
                'category_id' => '03_Cong_Giao_Vien',
                'category_name' => '03. Cổng Giáo viên (Teacher Portal)',
                'folder_name' => '17_bao_cao_chung_cua_giao_vien',
                'title_vn' => 'Báo cáo chung của giáo viên',
                'desc' => 'Báo cáo tổng kết toàn diện của giáo viên về học thuật và vận hành lớp',
                'has_png' => true,
                'icon' => 'analytics',
                'num' => '51',
            ],

            // Category 04: Cổng Phụ Huynh / Học Sinh (7 screens)
            [
                'category_id' => '04_Cong_Phu_Huynh_Hoc_Sinh',
                'category_name' => '04. Cổng Phụ huynh / Học sinh',
                'folder_name' => '01_app_shell_phu_huynh_hoc_sinh',
                'title_vn' => 'MENGLISH — Cổng Phụ huynh / Học sinh (App Shell)',
                'desc' => 'Giao diện khung điều hướng và header của Cổng Phụ huynh / Học sinh',
                'has_png' => true,
                'icon' => 'grid_view',
                'num' => '52',
            ],
            [
                'category_id' => '04_Cong_Phu_Huynh_Hoc_Sinh',
                'category_name' => '04. Cổng Phụ huynh / Học sinh',
                'folder_name' => '02_trang_chu_phu_huynh_hoc_sinh',
                'title_vn' => 'Trang chủ Cổng Phụ huynh / Học sinh',
                'desc' => 'Màn hình trang chủ: thông tin học sinh, trạng thái học phí, tin tức',
                'has_png' => true,
                'icon' => 'family_restroom',
                'num' => '53',
            ],
            [
                'category_id' => '04_Cong_Phu_Huynh_Hoc_Sinh',
                'category_name' => '04. Cổng Phụ huynh / Học sinh',
                'folder_name' => '03_hoc_tap_cua_toi_nop_bai_tap',
                'title_vn' => 'Học tập của tôi (Report điểm, Nộp bài video & bài tập)',
                'desc' => 'Học sinh theo dõi điểm số, lộ trình, nộp bài tập quay video, viết từ vựng, workbook, quiz',
                'has_png' => true,
                'icon' => 'upload_file',
                'num' => '54',
            ],
            [
                'category_id' => '04_Cong_Phu_Huynh_Hoc_Sinh',
                'category_name' => '04. Cổng Phụ huynh / Học sinh',
                'folder_name' => '04_luyen_phat_am',
                'title_vn' => 'Luyện phát âm (Ghi âm thu giọng nói)',
                'desc' => 'Học sinh nghe file audio mẫu từ giáo trình và thu âm ghi âm trực tiếp để nộp bài',
                'has_png' => true,
                'icon' => 'mic',
                'num' => '55',
            ],
            [
                'category_id' => '04_Cong_Phu_Huynh_Hoc_Sinh',
                'category_name' => '04. Cổng Phụ huynh / Học sinh',
                'folder_name' => '05_danh_sach_thong_bao',
                'title_vn' => 'Danh sách thông báo',
                'desc' => 'Xem danh sách thông báo từ trung tâm: học phí, nghỉ lễ, sự kiện, sinh nhật',
                'has_png' => true,
                'icon' => 'notifications',
                'num' => '56',
            ],
            [
                'category_id' => '04_Cong_Phu_Huynh_Hoc_Sinh',
                'category_name' => '04. Cổng Phụ huynh / Học sinh',
                'folder_name' => '06_khao_sat',
                'title_vn' => 'Khảo sát & Đánh giá',
                'desc' => 'Khảo sát mức độ hài lòng về chất lượng giảng dạy, giáo trình, cơ sở vật chất',
                'has_png' => true,
                'icon' => 'contact_support',
                'num' => '57',
            ],
            [
                'category_id' => '04_Cong_Phu_Huynh_Hoc_Sinh',
                'category_name' => '04. Cổng Phụ huynh / Học sinh',
                'folder_name' => '07_phu_huynh_gui_feedback',
                'title_vn' => 'Phụ huynh gửi Feedback chặng học',
                'desc' => 'Màn hình đánh giá chặng học và gửi feedback đóng góp ý kiến từ phụ huynh',
                'has_png' => true,
                'icon' => 'feedback',
                'num' => '58',
            ],
        ];
    }

    /**
     * Dashboard tổng hợp 58 màn hình (Gallery, Search, Filter)
     */
    public function index(Request $request)
    {
        $screens = self::getScreens();
        $selectedCat = $request->query('cat', 'all');

        $categories = [
            'all' => [
                'id' => 'all',
                'name' => 'Tất cả màn hình',
                'count' => count($screens),
                'icon' => 'dashboard_customize',
            ],
            '01_Web_Admin' => [
                'id' => '01_Web_Admin',
                'name' => 'Web Admin (Quản trị hệ thống)',
                'count' => count(array_filter($screens, fn ($s) => $s['category_id'] === '01_Web_Admin')),
                'icon' => 'admin_panel_settings',
            ],
            '02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu' => [
                'id' => '02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu',
                'name' => 'Học thuật & Học vụ & KPI',
                'count' => count(array_filter($screens, fn ($s) => $s['category_id'] === '02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu')),
                'icon' => 'monitoring',
            ],
            '03_Cong_Giao_Vien' => [
                'id' => '03_Cong_Giao_Vien',
                'name' => 'Cổng Giáo Viên (Teacher Portal)',
                'count' => count(array_filter($screens, fn ($s) => $s['category_id'] === '03_Cong_Giao_Vien')),
                'icon' => 'co_present',
            ],
            '04_Cong_Phu_Huynh_Hoc_Sinh' => [
                'id' => '04_Cong_Phu_Huynh_Hoc_Sinh',
                'name' => 'Cổng Phụ Huynh & Học Sinh',
                'count' => count(array_filter($screens, fn ($s) => $s['category_id'] === '04_Cong_Phu_Huynh_Hoc_Sinh')),
                'icon' => 'family_restroom',
            ],
        ];

        return view('academic-system.index', compact('screens', 'categories', 'selectedCat'));
    }

    /**
     * Mở trực tiếp màn hình với 100% UI nguyên bản
     */
    public function show(Request $request, string $category, string $screen)
    {
        $safeCategory = basename($category);
        $safeScreen = basename($screen);
        $screenKey = "{$safeCategory}/{$safeScreen}";

        $firstClassId = ClassModel::first()->id ?? 1;

        $nativeRouteMap = [
            '01_Web_Admin/01_quan_ly_tai_lieu_giao_trinh' => route('syllabus.documents'),
            '01_Web_Admin/02_soan_syllabus_theo_chang' => route('syllabus.builder'),
            '01_Web_Admin/03_giao_chang_cho_giao_vien' => route('syllabus.assignments'),
            '03_Cong_Giao_Vien/08_xem_tai_lieu_giao_trinh' => route('syllabus.teacher-view'),
            '03_Cong_Giao_Vien/09_de_xuat_sua_giao_trinh' => route('syllabus.teacher-propose'),
            '01_Web_Admin/05_chi_tiet_de_xuat_sua_giao_trinh' => route('syllabus.versions'),
            '03_Cong_Giao_Vien/14_xin_dieu_chinh_tien_do' => route('syllabus.teacher-adjust'),
            '01_Web_Admin/04_duyet_yeu_cau_dieu_chinh_tien_do' => route('syllabus.adjustment-requests'),
            // Flow 1: Tuyển sinh, Khai giảng & Quản lý Lớp học (6 bước chuẩn BA)
            '01_Web_Admin/12_dat_lich_hoc_thu_popup' => route('classes.trial-booking'),
            '01_Web_Admin/13_tao_lop_moi' => route('classes.create'),
            '01_Web_Admin/14_ho_so_lop_hoc' => route('classes.profile'),
            '02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu/13_tong_quan_danh_sach_lop_hoc_thuat' => route('classes.academic-overview'),
            '02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu/14_danh_sach_lop_chi_tiet_hoc_thuat' => route('classes.academic-list'),
            '02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu/15_chi_tiet_lop_hoc_hoc_thuat' => route('classes.academic-detail'),
            // Flow 4: Trải nghiệm Học sinh & Phụ huynh (7 bước chuẩn BA)
            '04_Cong_Phu_Huynh_Hoc_Sinh/01_app_shell_phu_huynh_hoc_sinh' => route('portal.app-shell'),
            '04_Cong_Phu_Huynh_Hoc_Sinh/02_trang_chu_phu_huynh_hoc_sinh' => route('portal.student.home'),
            '04_Cong_Phu_Huynh_Hoc_Sinh/03_hoc_tap_cua_toi_nop_bai_tap' => route('portal.student.homework'),
            '04_Cong_Phu_Huynh_Hoc_Sinh/04_luyen_phat_am' => route('portal.student.pronunciation'),
            '04_Cong_Phu_Huynh_Hoc_Sinh/05_danh_sach_thong_bao' => route('portal.student.notifications'),
            '04_Cong_Phu_Huynh_Hoc_Sinh/06_khao_sat' => route('portal.student.survey'),
            '04_Cong_Phu_Huynh_Hoc_Sinh/07_phu_huynh_gui_feedback' => route('portal.student.feedback'),
            // Cổng Giáo viên
            '03_Cong_Giao_Vien/04_bai_nop_cua_lop' => route('portal.teacher.submissions'),
            '03_Cong_Giao_Vien/01_app_shell_cong_giao_vien' => route('teacher.home'),
            '03_Cong_Giao_Vien/15_check_in_cua_toi' => route('teacher.home'),
            '03_Cong_Giao_Vien/02_diem_danh_lop_giao_vien' => route('teacher.attendance', ['classId' => $firstClassId]),
            '03_Cong_Giao_Vien/03_giao_bai_tap_ve_nha' => route('teacher.homework', ['classId' => $firstClassId]),
            '03_Cong_Giao_Vien/06_nhap_diem_mini_test' => route('teacher.scores', ['classId' => $firstClassId]),
            '03_Cong_Giao_Vien/05_nhan_xet_buoi_hoc_cho_tung_hoc_sinh' => route('teacher.remarks', ['classId' => $firstClassId]),
            '03_Cong_Giao_Vien/07_chang_dang_day_va_order_test' => route('teacher.order-test', ['classId' => $firstClassId]),
            '03_Cong_Giao_Vien/10_lich_du_kien_big_test' => route('syllabus.big-tests.schedules'),
            '03_Cong_Giao_Vien/11_report_thang_lich_big_test' => route('teacher.big-test-report'),
            '03_Cong_Giao_Vien/12_xac_nhan_quiz_desktop' => route('teacher.home'),
            '03_Cong_Giao_Vien/13_xac_nhan_quiz_mobile' => route('teacher.home'),
            '03_Cong_Giao_Vien/16_gui_bao_cao_cham_cong' => route('payroll.timesheets.manual'),
            '03_Cong_Giao_Vien/17_bao_cao_chung_cua_giao_vien' => route('teacher.general-report'),

            // Web Admin (Phase 2)
            '01_Web_Admin/06_duyet_phan_phoi_de_big_test' => route('syllabus.big-tests.distribution'),
            '01_Web_Admin/07_duyet_ket_qua_big_test_gui_phu_huynh' => route('syllabus.big-tests.results'),
            '01_Web_Admin/08_nhac_lich_big_test' => route('syllabus.big-tests.schedules'),
            '01_Web_Admin/09_cham_cong_theo_lich' => route('payroll.timesheets.teachers'),
            '01_Web_Admin/10_doi_soat_chot_bang_cong' => route('payroll.periods.index'),
            '01_Web_Admin/11_danh_sach_buoi_day_thay_cho_xac_nhan' => route('tasks.manual-approvals'),

            // Học Vụ & KPI (Phase 3)
            '02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu/01_cau_hinh_kpi_hoc_vu_1' => route('kpi.criteria'),
            '02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu/02_cau_hinh_kpi_hoc_vu_2' => route('kpi.criteria'),
            '02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu/03_tong_hop_kpi_danh_gia_thang' => route('kpi.monthly'),
            '02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu/04_kpi_thang' => route('kpi.monthly'),
            '02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu/05_nhat_ky_hoc_vu' => route('reports.journal'),
            '02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu/06_bao_cao_ngay_hoc_vu' => route('reports.my'),
            '02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu/07_nhap_bao_cao_tuan_hoc_vu' => route('reports.my'),
            '02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu/08_qa_observation_du_gio_van_hanh' => route('classes.qa-observation'),
            '02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu/09_checklist_hoc_phi_feedback_theo_lop' => route('classes.checklist'),
            '02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu/10_ra_soat_diem_danh_hoc_vu_admin' => route('kpi.attendance-review'),
            '02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu/11_chi_tiet_bang_luong_hoc_vu' => route('payroll.periods.index'),
            '02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu/12_rollup_thu_hoc_phi_theo_lop' => route('tuition.students'),
            '02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu/16_danh_gia_du_gio_hoc_thuat' => route('classes.evaluate-observation'),
            '02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu/17_bao_cao_hop_giao_vien_theo_tuan' => route('reports.all'),
            '02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu/18_bao_cao_tuan_hoc_thuat' => route('reports.all'),
            '02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu/19_bao_cao_thang_hoc_thuat' => route('reports.all'),
            '02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu/20_bao_cao_quy_hoc_thuat' => route('reports.all'),
        ];

        if ($request->has('native')) {
            if (isset($nativeRouteMap[$screenKey])) {
                return redirect()->to($nativeRouteMap[$screenKey]);
            }
        }

        if ($request->has('embed')) {
            $rawUrl = $request->fullUrlWithQuery(['embed' => null]);

            return view('academic-system.screen-viewer', compact('safeCategory', 'safeScreen', 'screenKey', 'rawUrl'));
        }

        $path = public_path("roundcuoi-kieulien/{$safeCategory}/{$safeScreen}/code.html");

        if (! file_exists($path)) {
            abort(404, "Không tìm thấy màn hình giao diện: {$safeCategory}/{$safeScreen}");
        }

        $html = file_get_contents($path);

        // Fetch real database records from MySQL for this screen
        $records = AcademicRecord::where('screen_key', $screenKey)
            ->orWhere('screen_key', $safeScreen)
            ->orWhere('screen_key', 'LIKE', "%/{$safeScreen}")
            ->latest()
            ->get();

        $seedCount = $records->where('is_seed', true)->count();
        $realUserCount = $records->where('is_seed', false)->count();

        $seedBadgeHtml = $seedCount > 0
            ? '<span style="background: rgba(245, 158, 11, 0.2); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.4); font-size: 10px; font-weight: 700; padding: 1px 7px; border-radius: 9999px;" title="Dữ liệu mẫu khởi tạo (Seed Data)">'.$seedCount.' SEED</span>'
            : '';

        $realBadgeHtml = $realUserCount > 0
            ? '<span style="background: rgba(16, 185, 129, 0.2); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.4); font-size: 10px; font-weight: 700; padding: 1px 7px; border-radius: 9999px;" title="Dữ liệu thực tế người dùng">'.$realUserCount.' THỰC TẾ</span>'
            : '';

        $nativeButtonHtml = isset($nativeRouteMap[$screenKey])
            ? '<span style="opacity: 0.3;">|</span>
    <a href="'.$nativeRouteMap[$screenKey].'" style="background: linear-gradient(135deg, #10b981, #059669); color: #ffffff; text-decoration: none; font-weight: 700; display: inline-flex; align-items: center; gap: 4px; padding: 3px 11px; border-radius: 9999px; box-shadow: 0 2px 8px rgba(16, 185, 129, 0.4); border: 1px solid rgba(255,255,255,0.25);">
        <span style="font-size: 13px;">🚀</span> Mở Laravel Native
    </a>'
            : '';

        $csrfToken = csrf_token();
        $recordsJson = json_encode($records, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);

        // Injected engine scripts & non-intrusive floating bar
        $injectedContent = '
<!-- MEnglish Real Data Engine & Quick Bar -->
<script>
    window.__MENGLISH_SCREEN_KEY__ = '.json_encode($screenKey).';
    window.__MENGLISH_REAL_DATA__ = '.$recordsJson.';
    window.__MENGLISH_CSRF_TOKEN__ = '.json_encode($csrfToken).';
</script>
<script src="/roundcuoi-kieulien/menglish-real-data-engine.js"></script>
<div id="menglish-admin-quick-bar" style="position: fixed; bottom: 18px; right: 18px; z-index: 999999; display: flex; align-items: center; gap: 8px; background: rgba(15, 23, 42, 0.94); color: #f8fafc; padding: 7px 16px; border-radius: 9999px; box-shadow: 0 10px 30px rgba(0,0,0,0.35); font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, sans-serif; font-size: 12px; backdrop-filter: blur(10px); border: 1px solid rgba(255,255,255,0.18);">
    <span style="display: inline-flex; align-items: center; gap: 4px; color: #10b981; font-weight: 700; font-size: 11px;">
        <span style="display: inline-block; width: 7px; height: 7px; border-radius: 9999px; background: #10b981;"></span> LIVE ('.$records->count().')
    </span>
    '.$seedBadgeHtml.'
    '.$realBadgeHtml.'
    '.$nativeButtonHtml.'
    <span style="opacity: 0.3;">|</span>
    <a href="'.route('academic-system.index').'" style="color: #fb923c; text-decoration: none; font-weight: 600; display: inline-flex; align-items: center; gap: 4px;">
        <span style="font-size: 15px;">⊞</span> 58 Màn hình
    </a>
    <span style="opacity: 0.3;">|</span>
    <a href="'.route('dashboard').'" style="color: #ffffff; text-decoration: none; font-weight: 500;">Admin Hub</a>
    <button onclick="document.getElementById(\'menglish-admin-quick-bar\').remove()" title="Đóng thanh điều hướng nhanh" style="background: none; border: none; color: #94a3b8; cursor: pointer; padding: 0 0 0 6px; font-size: 15px; line-height: 1;">×</button>
</div>
';

        if (str_contains($html, '</body>')) {
            $html = str_replace('</body>', $injectedContent.'</body>', $html);
        } else {
            $html .= $injectedContent;
        }

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }

    /**
     * API: Lấy danh sách bản ghi thực tế từ CSDL
     */
    public function apiGetRecords(Request $request)
    {
        $query = AcademicRecord::query();

        if ($request->has('screen_key')) {
            $key = $request->screen_key;
            $query->where(function ($q) use ($key) {
                $q->where('screen_key', $key)
                    ->orWhere('screen_key', 'LIKE', "%/{$key}")
                    ->orWhere('screen_key', 'LIKE', "{$key}/%");
            });
        }

        if ($request->has('module')) {
            $query->where('module', $request->module);
        }

        if ($request->has('is_seed')) {
            $query->where('is_seed', filter_var($request->is_seed, FILTER_VALIDATE_BOOLEAN));
        }

        return response()->json([
            'success' => true,
            'count' => $query->count(),
            'data' => $query->latest()->get(),
        ]);
    }

    /**
     * API: Lưu bản ghi mới vào CSDL thực tế
     */
    public function apiStoreRecord(Request $request)
    {
        $validated = $request->validate([
            'screen_key' => 'required|string',
            'title' => 'required|string',
            'data' => 'nullable|array',
            'status' => 'nullable|string',
            'is_seed' => 'nullable|boolean',
        ]);

        $screenKey = $validated['screen_key'];
        $module = 'academic';
        if (str_starts_with($screenKey, '01_')) {
            $module = 'web_admin';
        } elseif (str_starts_with($screenKey, '02_')) {
            $module = 'operations';
        } elseif (str_starts_with($screenKey, '03_')) {
            $module = 'teacher_portal';
        } elseif (str_starts_with($screenKey, '04_')) {
            $module = 'parent_portal';
        }

        $code = 'REC-'.strtoupper(substr(uniqid(), -6));
        if (isset($validated['data']['code'])) {
            $code = $validated['data']['code'];
        }

        $record = AcademicRecord::create([
            'screen_key' => $screenKey,
            'module' => $module,
            'record_code' => $code,
            'title' => $validated['title'],
            'status' => $validated['status'] ?? 'active',
            'is_seed' => $validated['is_seed'] ?? false,
            'data' => $validated['data'] ?? [],
            'user_id' => auth()->id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Đã lưu bản ghi vào CSDL thành công!',
            'data' => $record,
        ], 201);
    }

    /**
     * API: Cập nhật bản ghi trong CSDL thực tế
     */
    public function apiUpdateRecord(Request $request, $id)
    {
        $record = AcademicRecord::findOrFail($id);
        $record->update($request->only(['title', 'status', 'data']));

        return response()->json([
            'success' => true,
            'message' => 'Đã cập nhật bản ghi thành công!',
            'data' => $record,
        ]);
    }

    /**
     * API: Xóa bản ghi khỏi CSDL thực tế
     */
    public function apiDeleteRecord(Request $request, $id)
    {
        $record = AcademicRecord::findOrFail($id);
        $record->delete();

        return response()->json([
            'success' => true,
            'message' => 'Đã xóa bản ghi thành công!',
        ]);
    }

    /**
     * API: Xử lý các thao tác hành động (Duyệt, từ chối, check-in, điểm danh)
     */
    public function apiActionRecord(Request $request, $id)
    {
        $record = AcademicRecord::findOrFail($id);
        $action = $request->input('action', 'approve');

        if ($action === 'approve') {
            $record->status = 'approved';
        } elseif ($action === 'reject') {
            $record->status = 'rejected';
        }

        $data = $record->data ?: [];
        $data['last_action'] = $action;
        $data['action_by'] = auth()->user()?->name ?? 'Admin';
        $data['action_at'] = now()->toDateTimeString();
        $record->data = $data;
        $record->save();

        return response()->json([
            'success' => true,
            'message' => "Đã thực hiện thao tác {$action} thành công!",
            'data' => $record,
        ]);
    }

    /**
     * Shortcut cho Web Admin
     */
    public function webAdmin(Request $request, ?string $screen = null)
    {
        if ($screen) {
            return $this->show($request, '01_Web_Admin', $screen);
        }

        return redirect()->route('academic-system.index', ['cat' => '01_Web_Admin']);
    }

    /**
     * Shortcut cho Học thuật & Học vụ & KPI
     */
    public function operations(Request $request, ?string $screen = null)
    {
        if ($screen) {
            return $this->show($request, '02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu', $screen);
        }

        return redirect()->route('academic-system.index', ['cat' => '02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu']);
    }

    /**
     * Shortcut cho Cổng Giáo Viên
     */
    public function teacherPortal(Request $request, ?string $screen = null)
    {
        if ($screen) {
            return $this->show($request, '03_Cong_Giao_Vien', $screen);
        }

        return redirect()->route('academic-system.index', ['cat' => '03_Cong_Giao_Vien']);
    }

    /**
     * Shortcut cho Cổng Phụ Huynh / Học Sinh
     */
    public function parentPortal(Request $request, ?string $screen = null)
    {
        if ($screen) {
            return $this->show($request, '04_Cong_Phu_Huynh_Hoc_Sinh', $screen);
        }

        return redirect()->route('academic-system.index', ['cat' => '04_Cong_Phu_Huynh_Hoc_Sinh']);
    }
}
