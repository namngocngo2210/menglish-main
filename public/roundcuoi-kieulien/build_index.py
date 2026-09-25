import json

with open('flows_data.json', 'r', encoding='utf-8') as f:
    data = json.load(f)

flows = data['flows']
screens = data['screens']

# Archetype dictionary for all 58 screens
# Format: (type, label, icon, badge_css, short_action)
ARCHETYPES = {
    # 01_Web_Admin
    '01_quan_ly_tai_lieu_giao_trinh': ('table', 'Kho tài liệu', 'folder_open', 'bg-blue-50 text-blue-700 border-blue-200', 'Upload & phân quyền tài liệu'),
    '02_soan_syllabus_theo_chang': ('form', 'Soạn Syllabus', 'edit_document', 'bg-amber-50 text-amber-700 border-amber-200', 'Thiết lập khung chặng & bài học'),
    '03_giao_chang_cho_giao_vien': ('table', 'Phân bổ GV', 'assignment_ind', 'bg-indigo-50 text-indigo-700 border-indigo-200', 'Gán chặng học cho giáo viên'),
    '04_duyet_yeu_cau_dieu_chinh_tien_do': ('approval', 'Duyệt Tiến độ', 'rule', 'bg-emerald-50 text-emerald-700 border-emerald-200', 'Phê duyệt thay đổi giáo án'),
    '05_chi_tiet_de_xuat_sua_giao_trinh': ('approval', 'Duyệt Sửa bài', 'checklist_rtl', 'bg-orange-50 text-orange-700 border-orange-200', 'Xử lý phản ánh giáo trình'),
    '06_duyet_phan_phoi_de_big_test': ('approval', 'Phân phối Đề', 'quiz', 'bg-purple-50 text-purple-700 border-purple-200', 'Duyệt ma trận & gửi đề thi'),
    '07_duyet_ket_qua_big_test_gui_phu_huynh': ('approval', 'Duyệt Điểm PH', 'mark_email_read', 'bg-cyan-50 text-cyan-700 border-cyan-200', 'Khóa điểm & gửi báo cáo PH'),
    '08_nhac_lich_big_test': ('calendar', 'Nhắc Lịch thi', 'notifications_active', 'bg-rose-50 text-rose-700 border-rose-200', 'Theo dõi & bắn nhắc hẹn thi'),
    '09_cham_cong_theo_lich': ('table', 'Bảng công GV', 'calendar_today', 'bg-blue-50 text-blue-700 border-blue-200', 'Quản lý giờ dạy thực tế'),
    '10_doi_soat_chot_bang_cong': ('kpi', 'Khóa Bảng công', 'lock_clock', 'bg-purple-50 text-purple-700 border-purple-200', 'Đối soát & chốt lương tháng'),
    '11_danh_sach_buoi_day_thay_cho_xac_nhan': ('table', 'Duyệt Dạy thay', 'swap_horiz', 'bg-amber-50 text-amber-700 border-amber-200', 'Xác nhận ca dạy thay giữa GV'),
    '12_dat_lich_hoc_thu_popup': ('modal', 'Popup Học thử', 'event_available', 'bg-pink-50 text-pink-700 border-pink-200', 'Xếp lịch test đầu vào'),
    '13_tao_lop_moi': ('form', 'Biểu mẫu Mở lớp', 'add_circle', 'bg-emerald-50 text-emerald-700 border-emerald-200', 'Cài đặt lớp học & học phí'),
    '14_ho_so_lop_hoc': ('dashboard', 'Hồ sơ Lớp học', 'badge', 'bg-indigo-50 text-indigo-700 border-indigo-200', 'Chi tiết sĩ số & học viên'),

    # 02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu
    '01_cau_hinh_kpi_hoc_vu_1': ('kpi', 'Cấu hình KPI #1', 'tune', 'bg-purple-50 text-purple-700 border-purple-200', 'Trọng số tiêu chí đánh giá'),
    '02_cau_hinh_kpi_hoc_vu_2': ('kpi', 'Cấu hình KPI #2', 'tune', 'bg-purple-50 text-purple-700 border-purple-200', 'Cài đặt KPI nâng cao'),
    '03_tong_hop_kpi_danh_gia_thang': ('kpi', 'Xếp hạng KPI', 'military_tech', 'bg-amber-50 text-amber-700 border-amber-200', 'Tổng hợp điểm số nhân sự'),
    '04_kpi_thang': ('kpi', 'KPI Cá nhân', 'analytics', 'bg-purple-50 text-purple-700 border-purple-200', 'Bảng điểm hiệu suất chi tiết'),
    '05_nhat_ky_hoc_vu': ('table', 'Nhật ký Sự vụ', 'auto_stories', 'bg-blue-50 text-blue-700 border-blue-200', 'Ghi nhận ticket phát sinh'),
    '06_bao_cao_ngay_hoc_vu': ('dashboard', 'Báo cáo Ngày', 'today', 'bg-cyan-50 text-cyan-700 border-cyan-200', 'Sĩ số & chuyên cần ngày'),
    '07_nhap_bao_cao_tuan_hoc_vu': ('form', 'Form Báo cáo Tuần', 'post_add', 'bg-emerald-50 text-emerald-700 border-emerald-200', 'Cập nhật tiến độ & tồn đọng'),
    '08_qa_observation_du_gio_van_hanh': ('evaluation', 'Quan sát Vận hành', 'visibility', 'bg-teal-50 text-teal-700 border-teal-200', 'Tiêu chí dự giờ của QA'),
    '09_checklist_hoc_phi_feedback_theo_lop': ('table', 'Checklist Học phí', 'checklist', 'bg-blue-50 text-blue-700 border-blue-200', 'Rà soát thu tiền & feedback'),
    '10_ra_soat_diem_danh_hoc_vu_admin': ('table', 'Rà soát Điểm danh', 'how_to_reg', 'bg-indigo-50 text-indigo-700 border-indigo-200', 'Xử lý học sinh vắng học'),
    '11_chi_tiet_bang_luong_hoc_vu': ('kpi', 'Chi tiết Bảng lương', 'payments', 'bg-purple-50 text-purple-700 border-purple-200', 'Lương cứng, KPI & giảm trừ'),
    '12_rollup_thu_hoc_phi_theo_lop': ('table', 'Tổng hợp Công nợ', 'pie_chart', 'bg-emerald-50 text-emerald-700 border-emerald-200', 'Báo cáo tiến độ thu tiền'),
    '13_tong_quan_danh_sach_lop_hoc_thuat': ('dashboard', 'Sơ đồ Khối lớp', 'grid_view', 'bg-indigo-50 text-indigo-700 border-indigo-200', 'Sơ đồ & số lượng lớp chạy'),
    '14_danh_sach_lop_chi_tiet_hoc_thuat': ('table', 'Danh sách Lớp', 'table_chart', 'bg-blue-50 text-blue-700 border-blue-200', 'Tra cứu lớp, GV & học vụ'),
    '15_chi_tiet_lop_hoc_hoc_thuat': ('dashboard', 'Chi tiết Lớp học', 'library_books', 'bg-cyan-50 text-cyan-700 border-cyan-200', 'Tiến độ syllabus & bài thi'),
    '16_danh_gia_du_gio_hoc_thuat': ('evaluation', 'Chấm điểm Dự giờ', 'grading', 'bg-teal-50 text-teal-700 border-teal-200', 'Thẩm định chất lượng GV'),
    '17_bao_cao_hop_giao_vien_theo_tuan': ('evaluation', 'Biên bản Họp GV', 'groups', 'bg-indigo-50 text-indigo-700 border-indigo-200', 'Biên bản chuyên môn tuần'),
    '18_bao_cao_tuan_hoc_thuat': ('dashboard', 'Báo cáo Tuần HT', 'query_stats', 'bg-emerald-50 text-emerald-700 border-emerald-200', 'Kết quả đào tạo trong tuần'),
    '19_bao_cao_thang_hoc_thuat': ('dashboard', 'Báo cáo Tháng HT', 'insights', 'bg-purple-50 text-purple-700 border-purple-200', 'Chất lượng đào tạo tháng'),
    '20_bao_cao_quy_hoc_thuat': ('dashboard', 'Báo cáo Quý HT', 'leaderboard', 'bg-amber-50 text-amber-700 border-amber-200', 'Báo cáo chiến lược đào tạo'),

    # 03_Cong_Giao_Vien
    '01_app_shell_cong_giao_vien': ('dashboard', 'Portal Giáo viên', 'home', 'bg-emerald-50 text-emerald-700 border-emerald-200', 'Lịch dạy hôm nay & lớp phụ trách'),
    '02_diem_danh_lop_giao_vien': ('table', 'Điểm danh Mỗi buổi', 'fact_check', 'bg-emerald-50 text-emerald-700 border-emerald-200', 'Chấm chuyên cần có mặt / vắng'),
    '03_giao_bai_tap_ve_nha': ('form', 'Giao Bài tập', 'assignment', 'bg-indigo-50 text-indigo-700 border-indigo-200', 'Đính kèm video & bài tập tuần'),
    '04_bai_nop_cua_lop': ('dashboard', 'Chấm Bài nộp', 'rate_review', 'bg-blue-50 text-blue-700 border-blue-200', 'Xem video nộp & chấm bài viết'),
    '05_nhan_xet_buoi_hoc_cho_tung_hoc_sinh': ('form', 'Nhận xét Buổi học', 'rate_review', 'bg-cyan-50 text-cyan-700 border-cyan-200', 'Đánh giá thái độ & tiếp thu'),
    '06_nhap_diem_mini_test': ('table', 'Nhập điểm Mini Test', 'grade', 'bg-amber-50 text-amber-700 border-amber-200', 'Bảng nhập điểm kiểm tra định kỳ'),
    '07_chang_dang_day_va_order_test': ('form', 'Order Test chặng', 'shopping_cart_checkout', 'bg-purple-50 text-purple-700 border-purple-200', 'Yêu cầu đề thi kiểm tra chặng'),
    '08_xem_tai_lieu_giao_trinh': ('dashboard', 'Tài liệu Giáo án', 'menu_book', 'bg-indigo-50 text-indigo-700 border-indigo-200', 'Xem slide, giáo án & audio'),
    '09_de_xuat_sua_giao_trinh': ('form', 'Gửi Đề xuất Sửa', 'edit_attributes', 'bg-orange-50 text-orange-700 border-orange-200', 'Báo lỗi tài liệu lên học thuật'),
    '10_lich_du_kien_big_test': ('calendar', 'Lịch thi Big Test', 'event', 'bg-rose-50 text-rose-700 border-rose-200', 'Lịch thi dự kiến các lớp'),
    '11_report_thang_lich_big_test': ('table', 'Báo cáo Khảo thí', 'assessment', 'bg-purple-50 text-purple-700 border-purple-200', 'Tổng hợp tình hình thi cử'),
    '12_xac_nhan_quiz_desktop': ('dashboard', 'Duyệt Quiz Desktop', 'task_alt', 'bg-teal-50 text-teal-700 border-teal-200', 'Kiểm tra bài Quiz trên máy tính'),
    '13_xac_nhan_quiz_mobile': ('mobile', 'Duyệt Quiz Mobile', 'phone_android', 'bg-cyan-50 text-cyan-700 border-cyan-200', 'Chấm nhanh Quiz trên điện thoại'),
    '14_xin_dieu_chinh_tien_do': ('form', 'Xin Chỉnh tiến độ', 'speed', 'bg-amber-50 text-amber-700 border-amber-200', 'Đơn xin dạy nhanh/chậm syllabus'),
    '15_check_in_cua_toi': ('mobile', 'Check-in Ca dạy', 'location_on', 'bg-emerald-50 text-emerald-700 border-emerald-200', 'Xác nhận vào ca tại trung tâm'),
    '16_gui_bao_cao_cham_cong': ('form', 'Báo cáo Giờ dạy', 'send_and_archive', 'bg-purple-50 text-purple-700 border-purple-200', 'Chốt số giờ dạy tháng gửi Admin'),
    '17_bao_cao_chung_cua_giao_vien': ('form', 'Tổng kết Giáo viên', 'summarize', 'bg-indigo-50 text-indigo-700 border-indigo-200', 'Đánh giá học viên & lớp cuối kỳ'),

    # 04_Cong_Phu_Huynh_Hoc_Sinh
    '01_app_shell_phu_huynh_hoc_sinh': ('mobile', 'App Mobile Shell', 'smartphone', 'bg-cyan-50 text-cyan-700 border-cyan-200', 'Khung ứng dụng phụ huynh'),
    '02_trang_chu_phu_huynh_hoc_sinh': ('mobile', 'Home Phụ huynh/HS', 'cottage', 'bg-cyan-50 text-cyan-700 border-cyan-200', 'Tiến độ học & ca học tới'),
    '03_hoc_tap_cua_toi_nop_bai_tap': ('mobile', 'Nộp Bài tập Video', 'upload_file', 'bg-blue-50 text-blue-700 border-blue-200', 'Quay video & nộp bài tập'),
    '04_luyen_phat_am': ('audio', 'Luyện Thu âm AI', 'mic', 'bg-rose-50 text-rose-700 border-rose-200', 'Ghi âm & phân tích phát âm'),
    '05_danh_sach_thong_bao': ('mobile', 'Hộp thư Thông báo', 'notifications', 'bg-amber-50 text-amber-700 border-amber-200', 'Lịch nghỉ, học phí & tin tức'),
    '06_khao_sat': ('mobile', 'Khảo sát 5 Sao', 'star', 'bg-yellow-50 text-yellow-700 border-yellow-200', 'Đánh giá chất lượng đào tạo'),
    '07_phu_huynh_gui_feedback': ('mobile', 'Gửi Feedback Góp ý', 'rate_review', 'bg-purple-50 text-purple-700 border-purple-200', 'Phản ánh trực tiếp lên trung tâm')
}

# Attach archetype data to screens and flows
for s in screens:
    fn = s['folder_name']
    arch = ARCHETYPES.get(fn, ('table', 'Giao diện', 'web', 'bg-slate-50 text-slate-700 border-slate-200', 'Xem chi tiết giao diện'))
    s['arch_type'] = arch[0]
    s['arch_label'] = arch[1]
    s['arch_icon'] = arch[2]
    s['arch_badge'] = arch[3]
    s['short_action'] = arch[4]

for flow in flows:
    for st in flow['steps']:
        fn = st['folder_name']
        arch = ARCHETYPES.get(fn, ('table', 'Giao diện', 'web', 'bg-slate-50 text-slate-700 border-slate-200', 'Xem chi tiết giao diện'))
        st['arch_type'] = arch[0]
        st['arch_label'] = arch[1]
        st['arch_icon'] = arch[2]
        st['arch_badge'] = arch[3]
        st['short_action'] = arch[4]

html_template = f'''<!DOCTYPE html>
<html lang="vi" class="h-full bg-slate-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MENGLISH - Sơ đồ Luồng Vận Hành & Trình Duyệt Giao Diện (58 Màn Hình)</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet" />
    <style>
        body {{ font-family: 'Plus Jakarta Sans', sans-serif; }}
        .material-symbols-outlined {{ font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24; }}
        ::-webkit-scrollbar {{ width: 6px; height: 6px; }}
        ::-webkit-scrollbar-track {{ background: transparent; }}
        ::-webkit-scrollbar-thumb {{ background: #cbd5e1; border-radius: 9999px; }}
        ::-webkit-scrollbar-thumb:hover {{ background: #94a3b8; }}
        .no-scrollbar::-webkit-scrollbar {{ display: none; }}
        .no-scrollbar {{ -ms-overflow-style: none; scrollbar-width: none; }}
        
        /* Blueprint wireframe patterns */
        .pattern-grid {{
            background-image: radial-gradient(rgba(148, 163, 184, 0.25) 1px, transparent 1px);
            background-size: 12px 12px;
        }}
    </style>
</head>
<body class="min-h-full flex flex-col bg-slate-100 text-slate-800 antialiased selection:bg-orange-500 selection:text-white">

    <!-- Top Navigation Header -->
    <header class="bg-white border-b border-slate-200 sticky top-0 z-40 shadow-xs">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16 gap-3">
                
                <!-- Logo & Brand -->
                <div class="flex items-center gap-3 shrink-0 cursor-pointer" onclick="switchView('flow')">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-orange-500 to-amber-600 flex items-center justify-center text-white font-extrabold text-xl shadow-md shadow-orange-500/20">
                        M
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="text-base font-bold text-slate-900 tracking-tight">MENGLISH</span>
                            <span class="bg-orange-500 text-white text-[10px] font-bold px-2 py-0.5 rounded-full uppercase tracking-wider">Flow UI</span>
                        </div>
                        <p class="text-[11px] text-slate-500 hidden sm:block">8 Luồng Vận Hành Thực Chiến &bull; 58 Màn Hình Chuẩn Hóa</p>
                    </div>
                </div>

                <!-- Navigation Tabs -->
                <div class="flex items-center bg-slate-100 p-1 rounded-xl border border-slate-200 text-xs font-semibold">
                    <button id="navBtn-flow" onclick="switchView('flow')" 
                        class="view-nav-btn flex items-center gap-1.5 px-3 py-1.5 rounded-lg transition-all bg-white text-orange-600 shadow-xs font-bold">
                        <span class="material-symbols-outlined text-sm">account_tree</span>
                        <span>8 Luồng Tác Nghiệp</span>
                    </button>
                    <button id="navBtn-viewer" onclick="openViewer()" 
                        class="view-nav-btn flex items-center gap-1.5 px-3 py-1.5 rounded-lg transition-all text-slate-600 hover:text-slate-900">
                        <span class="material-symbols-outlined text-sm">devices</span>
                        <span>Trình Xem Live</span>
                    </button>
                    <button id="navBtn-grid" onclick="switchView('grid')" 
                        class="view-nav-btn flex items-center gap-1.5 px-3 py-1.5 rounded-lg transition-all text-slate-600 hover:text-slate-900">
                        <span class="material-symbols-outlined text-sm">grid_view</span>
                        <span>Tất Cả (58)</span>
                    </button>
                </div>

                <!-- Right Controls: Display Mode & Search -->
                <div class="flex items-center gap-2">
                    
                    <!-- Visual Mode Switcher (Wireframe vs Mockup) -->
                    <div class="hidden lg:flex items-center bg-slate-100 p-0.5 rounded-lg border border-slate-200 text-[11px] font-medium" title="Chuyển chế độ hiển thị thẻ màn hình">
                        <button id="cardModeBtn-wireframe" onclick="setCardVisualMode('wireframe')" 
                            class="px-2.5 py-1 rounded-md transition-all bg-white text-orange-700 font-bold shadow-xs flex items-center gap-1">
                            <span class="material-symbols-outlined text-xs text-orange-600">design_services</span>
                            <span>Trực quan</span>
                        </button>
                        <button id="cardModeBtn-mockup" onclick="setCardVisualMode('mockup')" 
                            class="px-2.5 py-1 rounded-md transition-all text-slate-500 hover:text-slate-900 flex items-center gap-1">
                            <span class="material-symbols-outlined text-xs">image</span>
                            <span>Ảnh Chụp</span>
                        </button>
                    </div>

                    <!-- Search Input -->
                    <div class="relative w-36 sm:w-56">
                        <span class="material-symbols-outlined absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400 text-sm pointer-events-none">search</span>
                        <input type="text" id="globalSearch" placeholder="Tìm nhanh... (/)" 
                            class="w-full pl-8 pr-8 py-1.5 bg-slate-100 focus:bg-white border border-slate-200 rounded-lg text-xs text-slate-800 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-orange-500 transition-all">
                        <button id="clearSearchBtn" onclick="clearSearch()" class="hidden absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600">
                            <span class="material-symbols-outlined text-xs">close</span>
                        </button>
                    </div>

                    <!-- Drawer Toggle Button -->
                    <button onclick="toggleFlowDrawer()" 
                        class="flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-orange-50 hover:bg-orange-100 text-orange-700 font-semibold text-xs border border-orange-200 transition-colors">
                        <span class="material-symbols-outlined text-base">menu_open</span>
                        <span class="hidden md:inline">Menu Luồng</span>
                    </button>
                </div>

            </div>
        </div>
    </header>

    <!-- Search Results Dropdown -->
    <div id="searchResultsContainer" class="hidden fixed top-16 left-0 right-0 z-30 bg-white/95 backdrop-blur-md border-b border-slate-200 shadow-xl max-h-[75vh] overflow-y-auto">
        <div class="max-w-5xl mx-auto p-4">
            <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-3">
                <div class="text-xs font-bold text-slate-500 uppercase tracking-wider flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-sm text-orange-600">manage_search</span>
                    Kết quả tìm kiếm (<span id="searchCount">0</span>)
                </div>
                <button onclick="clearSearch()" class="text-xs text-slate-400 hover:text-slate-700 font-medium">Đóng [Esc]</button>
            </div>
            <div id="searchResultsList" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3"></div>
        </div>
    </div>

    <!-- MAIN VIEW 1: FLOW MAP (SƠ ĐỒ 8 LUỒNG) -->
    <div id="view-flow" class="flex-1 w-full max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-5">
        
        <!-- Streamlined Flow Command Bar -->
        <div class="bg-white rounded-2xl border border-slate-200/90 p-4 sm:p-5 shadow-xs mb-6">
            <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                
                <!-- Left: Quick Title & Stats -->
                <div>
                    <div class="flex items-center gap-2 mb-1">
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-orange-100 text-orange-800 text-[11px] font-bold">
                            <span class="w-1.5 h-1.5 rounded-full bg-orange-600"></span> 8 Luồng Nghiệp Vụ
                        </span>
                        <span class="text-xs text-slate-400">&bull;</span>
                        <span class="text-xs font-semibold text-slate-600">58 Màn hình tuần tự</span>
                        <span class="text-xs text-slate-400">&bull;</span>
                        <span class="text-xs font-semibold text-emerald-600 flex items-center gap-1">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> 100% Live Prototype
                        </span>
                    </div>
                    <h1 class="text-lg sm:text-xl font-bold text-slate-900 tracking-tight">
                        Sơ Đồ Điều Hướng Quy Trình Vận Hành MENGLISH
                    </h1>
                </div>

                <!-- Right: Visual Mode Switcher (Mobile/Tablet visible) -->
                <div class="flex items-center gap-2 self-start lg:self-auto">
                    <span class="text-xs text-slate-500 font-medium hidden sm:inline">Chế độ xem thẻ:</span>
                    <div class="flex items-center bg-slate-100 p-0.5 rounded-lg border border-slate-200 text-xs font-semibold">
                        <button id="subCardModeBtn-wireframe" onclick="setCardVisualMode('wireframe')" 
                            class="px-3 py-1 rounded-md transition-all bg-white text-orange-700 font-bold shadow-xs flex items-center gap-1">
                            <span class="material-symbols-outlined text-sm text-orange-600">design_services</span>
                            <span>Trực Quan</span>
                        </button>
                        <button id="subCardModeBtn-mockup" onclick="setCardVisualMode('mockup')" 
                            class="px-3 py-1 rounded-md transition-all text-slate-500 hover:text-slate-900 flex items-center gap-1">
                            <span class="material-symbols-outlined text-sm">image</span>
                            <span>Ảnh Mockup</span>
                        </button>
                    </div>
                </div>

            </div>

            <!-- Quick Flow Jump Chips -->
            <div class="flex items-center gap-2 mt-4 pt-3 border-t border-slate-100 overflow-x-auto pb-1 no-scrollbar text-xs font-semibold">
                <span class="text-slate-400 text-[11px] uppercase tracking-wider shrink-0 mr-1">Nhảy tới luồng:</span>
                <a href="#flow-1" class="px-2.5 py-1 rounded-lg bg-slate-100 hover:bg-orange-50 text-slate-700 hover:text-orange-700 transition whitespace-nowrap">1. Tuyển sinh & Lớp</a>
                <a href="#flow-2" class="px-2.5 py-1 rounded-lg bg-slate-100 hover:bg-orange-50 text-slate-700 hover:text-orange-700 transition whitespace-nowrap">2. Giáo trình & Syllabus</a>
                <a href="#flow-3" class="px-2.5 py-1 rounded-lg bg-slate-100 hover:bg-orange-50 text-slate-700 hover:text-orange-700 transition whitespace-nowrap">3. Giáo viên lên lớp</a>
                <a href="#flow-4" class="px-2.5 py-1 rounded-lg bg-slate-100 hover:bg-orange-50 text-slate-700 hover:text-orange-700 transition whitespace-nowrap">4. Cổng Phụ huynh/HS</a>
                <a href="#flow-5" class="px-2.5 py-1 rounded-lg bg-slate-100 hover:bg-orange-50 text-slate-700 hover:text-orange-700 transition whitespace-nowrap">5. Khảo thí & Big Test</a>
                <a href="#flow-6" class="px-2.5 py-1 rounded-lg bg-slate-100 hover:bg-orange-50 text-slate-700 hover:text-orange-700 transition whitespace-nowrap">6. Học vụ & Học phí</a>
                <a href="#flow-7" class="px-2.5 py-1 rounded-lg bg-slate-100 hover:bg-orange-50 text-slate-700 hover:text-orange-700 transition whitespace-nowrap">7. Chấm công & Lương</a>
                <a href="#flow-8" class="px-2.5 py-1 rounded-lg bg-slate-100 hover:bg-orange-50 text-slate-700 hover:text-orange-700 transition whitespace-nowrap">8. Kiểm định QA</a>
            </div>
        </div>

        <!-- 8 Flows Container -->
        <div id="flowListContainer" class="space-y-8">
            <!-- Injected via JS -->
        </div>

    </div>

    <!-- MAIN VIEW 2: LIVE VIEWER (TRÌNH DUYỆT TƯƠNG TÁC) -->
    <div id="view-viewer" class="hidden flex-1 w-full flex flex-col bg-slate-900" style="min-height: calc(100vh - 64px);">
        
        <!-- Viewer Top Bar -->
        <div class="bg-slate-900 border-b border-slate-800 text-white px-4 py-2.5 flex flex-wrap items-center justify-between gap-3 shrink-0">
            
            <!-- Left Info -->
            <div class="flex items-center gap-3 min-w-0">
                <button onclick="switchView('flow')" title="Quay về Sơ đồ Luồng" class="p-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 hover:text-white transition-colors shrink-0">
                    <span class="material-symbols-outlined text-base">arrow_back</span>
                </button>
                <div class="min-w-0">
                    <div class="flex items-center gap-2">
                        <span id="viewerFlowTag" class="px-2 py-0.5 rounded text-[10px] font-bold bg-orange-500/20 text-orange-400 border border-orange-500/30 shrink-0">
                            Luồng 1
                        </span>
                        <h2 id="viewerScreenTitle" class="text-sm font-bold text-white truncate">Tên màn hình</h2>
                        <span id="viewerArchBadge" class="hidden sm:inline-flex text-[10px] font-semibold px-2 py-0.5 rounded bg-slate-800 text-slate-300 border border-slate-700">Loại</span>
                        <span id="viewerNativeStatusBadge" class="hidden text-[10px] font-bold px-2 py-0.5 rounded bg-emerald-500/20 text-emerald-300 border border-emerald-500/40">✓ Đã ghép vào Laravel</span>
                    </div>
                    <div class="flex items-center gap-2 text-xs text-slate-400 mt-0.5 truncate">
                        <span id="viewerRoleBadge" class="text-[11px] text-amber-300 font-medium">Vai trò</span>
                        <span>&bull;</span>
                        <span id="viewerStepIndicator" class="text-slate-400">Bước 1/6</span>
                        <span class="hidden sm:inline">&bull;</span>
                        <span id="viewerActionTag" class="text-slate-400 text-[11px] hidden sm:inline truncate">Tác vụ chính</span>
                    </div>
                </div>
            </div>

            <!-- Center Stepper Controls (Keyboard: Left / Right) -->
            <div class="flex items-center gap-2 shrink-0">
                <button id="prevStepBtn" onclick="navigateStep(-1)" title="Phím tắt: Mũi tên trái (←)"
                    class="flex items-center gap-1 px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-semibold transition disabled:opacity-40 disabled:cursor-not-allowed">
                    <span class="material-symbols-outlined text-sm">arrow_left</span>
                    <span class="hidden sm:inline">Trước</span>
                </button>
                <div class="px-2.5 py-1 rounded-lg bg-slate-950 text-xs font-mono font-bold text-orange-400 border border-slate-800">
                    <span id="currentStepIndexBadge">1</span> / <span id="totalStepIndexBadge">6</span>
                </div>
                <button id="nextStepBtn" onclick="navigateStep(1)" title="Phím tắt: Mũi tên phải (→)"
                    class="flex items-center gap-1 px-3 py-1.5 rounded-lg bg-orange-600 hover:bg-orange-500 text-white text-xs font-semibold shadow-xs transition disabled:opacity-40 disabled:cursor-not-allowed">
                    <span class="hidden sm:inline">Sau</span>
                    <span class="material-symbols-outlined text-sm">arrow_right</span>
                </button>
            </div>

            <!-- Right Controls: View mode & Responsive -->
            <div class="flex items-center gap-2 shrink-0">
                
                <!-- View Mode: Interactive HTML vs Raw Mockup -->
                <div class="flex items-center bg-slate-800 p-0.5 rounded-lg border border-slate-700 text-xs">
                    <button id="modeBtn-code" onclick="setViewerMode('code')" 
                        class="px-2.5 py-1 rounded text-xs font-semibold bg-orange-600 text-white transition">
                        Live HTML
                    </button>
                    <button id="modeBtn-design" onclick="setViewerMode('design')" 
                        class="px-2.5 py-1 rounded text-xs font-semibold text-slate-400 hover:text-white transition">
                        Ảnh Mockup
                    </button>
                </div>

                <!-- Device Width Viewport -->
                <div class="hidden md:flex items-center bg-slate-800 p-0.5 rounded-lg border border-slate-700 text-xs">
                    <button id="deviceBtn-desktop" onclick="setDeviceWidth('100%')" title="Desktop (100%)" 
                        class="p-1.5 rounded text-white bg-slate-700 transition">
                        <span class="material-symbols-outlined text-sm">desktop_windows</span>
                    </button>
                    <button id="deviceBtn-tablet" onclick="setDeviceWidth('768px')" title="Tablet (768px)" 
                        class="p-1.5 rounded text-slate-400 hover:text-white transition">
                        <span class="material-symbols-outlined text-sm">tablet_mac</span>
                    </button>
                    <button id="deviceBtn-mobile" onclick="setDeviceWidth('390px')" title="Mobile (390px)" 
                        class="p-1.5 rounded text-slate-400 hover:text-white transition">
                        <span class="material-symbols-outlined text-sm">smartphone</span>
                    </button>
                </div>

                <!-- Laravel Native Button -->
                <a id="viewerLaravelNativeLink" href="#" target="_blank" title="Mở trực tiếp màn hình thực tế trong hệ thống Laravel (localhost:8000)" 
                    class="hidden items-center gap-1.5 px-3 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold shadow transition border border-emerald-400/50 animate-pulse">
                    <span class="material-symbols-outlined text-sm">rocket_launch</span>
                    <span class="hidden sm:inline">Mở trên Laravel Native</span>
                </a>

                <!-- Open in new tab & Sidebar Toggle -->
                <a id="viewerExternalLink" href="#" target="_blank" title="Mở trong tab mới độc lập" 
                    class="p-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 hover:text-white transition">
                    <span class="material-symbols-outlined text-base">open_in_new</span>
                </a>
                <button onclick="toggleViewerSidebar()" title="Bật/Tắt Menu Sidebar" 
                    class="p-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 hover:text-white transition">
                    <span class="material-symbols-outlined text-base">view_sidebar</span>
                </button>
            </div>

        </div>

        <!-- Viewer Viewport & Sidebar -->
        <div class="flex-1 flex overflow-hidden relative" style="height: calc(100vh - 120px);">
            
            <!-- Left Sidebar -->
            <aside id="viewerSidebar" class="w-80 bg-slate-900 border-r border-slate-800 flex flex-col shrink-0 transition-all duration-200 z-20">
                <div class="p-3 border-b border-slate-800 flex items-center justify-between gap-2">
                    <span class="text-xs font-bold uppercase tracking-wider text-slate-400 flex items-center gap-1.5 shrink-0">
                        <span class="material-symbols-outlined text-sm text-orange-500">alt_route</span>
                        Điều hướng 8 Luồng
                    </span>
                    <div class="flex items-center gap-1.5">
                        <button onclick="toggleExpandAllFlows()" 
                            class="text-[11px] font-semibold text-orange-400 hover:text-orange-300 flex items-center gap-1 px-2 py-0.5 rounded bg-slate-800 transition">
                            <span class="material-symbols-outlined text-xs" id="expandAllIcon">unfold_less</span>
                            <span id="expandAllText">Thu gọn</span>
                        </button>
                        <button onclick="toggleViewerSidebar()" class="text-slate-500 hover:text-slate-300 p-1">
                            <span class="material-symbols-outlined text-sm">close</span>
                        </button>
                    </div>
                </div>
                <div id="viewerSidebarContent" class="flex-1 overflow-y-auto p-2 space-y-2"></div>
            </aside>

            <!-- Main Frame -->
            <div class="flex-1 bg-slate-950 flex flex-col items-center justify-start overflow-hidden relative p-0 sm:p-2">
                
                <!-- Live HTML Iframe -->
                <div id="viewerIframeWrapper" class="w-full h-full flex flex-col items-center justify-center transition-all duration-300">
                    <iframe id="screenIframe" src="about:blank" class="w-full h-full border-0 bg-white rounded-none sm:rounded-lg shadow-2xl transition-all"></iframe>
                </div>

                <!-- Raw Mockup Image (with Lightbox Zoom) -->
                <div id="viewerImageWrapper" class="hidden w-full h-full overflow-auto p-6 flex flex-col items-center justify-start">
                    <div class="mb-3 flex items-center gap-2">
                        <button onclick="openImageLightbox(document.getElementById('screenImageMockup').src)" 
                            class="px-3 py-1.5 rounded-lg bg-orange-600 hover:bg-orange-500 text-white text-xs font-bold shadow-md flex items-center gap-1 transition">
                            <span class="material-symbols-outlined text-sm">zoom_in</span>
                            <span>Phóng to toàn màn hình (Xem rõ chữ)</span>
                        </button>
                    </div>
                    <img id="screenImageMockup" src="" alt="Mockup thiết kế" 
                        onclick="openImageLightbox(this.src)"
                        class="max-w-4xl w-full rounded-lg shadow-2xl border border-slate-800 object-contain cursor-zoom-in hover:opacity-95 transition">
                </div>

                <!-- Bottom Floating Steps Strip -->
                <div id="viewerBottomStepper" class="absolute bottom-3 left-1/2 -translate-x-1/2 bg-slate-900/90 backdrop-blur-md border border-slate-700/80 rounded-full px-4 py-1.5 flex items-center gap-2 shadow-xl z-10 max-w-[90vw] overflow-x-auto no-scrollbar"></div>

            </div>

        </div>

    </div>

    <!-- MAIN VIEW 3: GRID VIEW (58 MÀN HÌNH) -->
    <div id="view-grid" class="hidden flex-1 w-full max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
            <div>
                <h2 class="text-xl font-bold text-slate-900">Thư Viện 58 Màn Hình</h2>
                <p class="text-xs text-slate-500">Xem theo danh mục phân hệ hoặc chuyển đổi chế độ Trực quan / Ảnh Mockup</p>
            </div>
            <!-- Category filter pills -->
            <div class="flex items-center gap-1.5 overflow-x-auto pb-1 no-scrollbar text-xs font-semibold" id="gridCatFilter">
                <button onclick="filterGridCategory('all')" class="grid-tab-btn active px-3 py-1.5 rounded-lg bg-orange-600 text-white shadow-xs" data-cat="all">
                    Tất cả (58)
                </button>
                <button onclick="filterGridCategory('01_Web_Admin')" class="grid-tab-btn px-3 py-1.5 rounded-lg bg-slate-200 text-slate-700 hover:bg-slate-300" data-cat="01_Web_Admin">
                    Web Admin (14)
                </button>
                <button onclick="filterGridCategory('02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu')" class="grid-tab-btn px-3 py-1.5 rounded-lg bg-slate-200 text-slate-700 hover:bg-slate-300" data-cat="02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu">
                    Học thuật & Học vụ (20)
                </button>
                <button onclick="filterGridCategory('03_Cong_Giao_Vien')" class="grid-tab-btn px-3 py-1.5 rounded-lg bg-slate-200 text-slate-700 hover:bg-slate-300" data-cat="03_Cong_Giao_Vien">
                    Cổng Giáo viên (17)
                </button>
                <button onclick="filterGridCategory('04_Cong_Phu_Huynh_Hoc_Sinh')" class="grid-tab-btn px-3 py-1.5 rounded-lg bg-slate-200 text-slate-700 hover:bg-slate-300" data-cat="04_Cong_Phu_Huynh_Hoc_Sinh">
                    Phụ huynh / HS (7)
                </button>
            </div>
        </div>

        <!-- Grid container -->
        <div id="allScreensGrid" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-5"></div>
    </div>

    <!-- Offcanvas Flow Menu Drawer -->
    <div id="flowDrawerOverlay" onclick="toggleFlowDrawer()" class="hidden fixed inset-0 bg-slate-900/60 backdrop-blur-xs z-50 transition-opacity duration-200"></div>
    <div id="flowDrawerPanel" class="fixed inset-y-0 right-0 w-full sm:w-96 bg-white shadow-2xl z-50 flex flex-col transform translate-x-full transition-transform duration-300 ease-in-out border-l border-slate-200">
        <div class="p-4 border-b border-slate-200 flex items-center justify-between bg-slate-50">
            <div class="flex items-center gap-2">
                <div class="w-8 h-8 rounded-lg bg-orange-600 text-white flex items-center justify-center font-bold text-sm">M</div>
                <div>
                    <h3 class="text-sm font-bold text-slate-900">8 Luồng Vận Hành</h3>
                    <p class="text-[11px] text-slate-500">Chọn luồng để bắt đầu</p>
                </div>
            </div>
            <button onclick="toggleFlowDrawer()" class="p-1.5 rounded-lg hover:bg-slate-200 text-slate-400 hover:text-slate-700">
                <span class="material-symbols-outlined text-base">close</span>
            </button>
        </div>
        <div id="flowDrawerList" class="flex-1 overflow-y-auto p-4 space-y-2.5"></div>
        <div class="p-3 border-t border-slate-200 bg-slate-50 text-center text-xs text-slate-500">
            Nhấn <kbd class="px-1.5 py-0.5 rounded bg-white border border-slate-300 font-mono text-[10px]">Esc</kbd> để đóng
        </div>
    </div>

    <!-- Fullscreen Lightbox Modal for High-Res Screenshot Zoom -->
    <div id="imageLightboxModal" class="hidden fixed inset-0 bg-black/90 backdrop-blur-md z-50 flex flex-col items-center justify-center p-4">
        <div class="w-full max-w-6xl flex items-center justify-between text-white pb-3">
            <span class="text-sm font-bold text-slate-200 flex items-center gap-1.5">
                <span class="material-symbols-outlined text-orange-400">zoom_in</span>
                <span>Xem Chi Tiết Mockup Độ Phân Giải Cao (100% Gốc)</span>
            </span>
            <button onclick="closeImageLightbox()" class="px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-white text-xs font-bold transition flex items-center gap-1">
                <span class="material-symbols-outlined text-sm">close</span>
                <span>Đóng [Esc]</span>
            </button>
        </div>
        <div class="flex-1 w-full max-w-6xl overflow-auto flex items-center justify-center border border-slate-800 rounded-xl bg-slate-950 p-2">
            <img id="lightboxImg" src="" alt="Mockup Zoom" class="max-w-full max-h-[85vh] object-contain rounded">
        </div>
    </div>

    <!-- Footer -->
    <footer id="pageFooter" class="bg-white border-t border-slate-200 py-4 text-center text-xs text-slate-500 mt-auto">
        <div class="max-w-7xl mx-auto px-4 flex flex-col sm:flex-row items-center justify-between gap-2">
            <p>MENGLISH UI Design System &bull; 8 Luồng Nghiệp Vụ &bull; 58 Màn Hình Thực Chiến</p>
            <div class="flex items-center gap-4 text-xs font-semibold text-slate-600">
                <button onclick="switchView('flow')" class="hover:text-orange-600">8 Luồng</button>
                <button onclick="openViewer()" class="hover:text-orange-600">Trình xem Live</button>
                <button onclick="switchView('grid')" class="hover:text-orange-600">Thư viện Tất cả</button>
            </div>
        </div>
    </footer>

    <!-- App Logic Script -->
    <script>
        const FLOW_DATA = {json.dumps(flows, ensure_ascii=False)};
        const ALL_SCREENS = {json.dumps(screens, ensure_ascii=False)};

        // Bản đồ liên kết trực tiếp tới các trang Laravel Native đã ghép hoàn chỉnh
        const LARAVEL_NATIVE_MAP = {{
            // Flow 1: Tuyển sinh, Khai giảng & Quản lý Lớp học (6 bước chuẩn BA)
            '01_Web_Admin/12_dat_lich_hoc_thu_popup': 'http://127.0.0.1:8000/classes/trial-booking',
            '01_Web_Admin/13_tao_lop_moi': 'http://127.0.0.1:8000/classes/create',
            '01_Web_Admin/14_ho_so_lop_hoc': 'http://127.0.0.1:8000/classes/profile',
            '02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu/13_tong_quan_danh_sach_lop_hoc_thuat': 'http://127.0.0.1:8000/classes/academic-overview',
            '02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu/14_danh_sach_lop_chi_tiet_hoc_thuat': 'http://127.0.0.1:8000/classes/academic-list',
            '02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu/15_chi_tiet_lop_hoc_hoc_thuat': 'http://127.0.0.1:8000/classes/academic-detail',
            // Flow 2: Quản lý Giáo trình & Phân bổ Syllabus (8 bước)
            '01_Web_Admin/01_quan_ly_tai_lieu_giao_trinh': 'http://127.0.0.1:8000/syllabus/documents',
            '01_Web_Admin/02_soan_syllabus_theo_chang': 'http://127.0.0.1:8000/syllabus/builder',
            '01_Web_Admin/03_giao_chang_cho_giao_vien': 'http://127.0.0.1:8000/syllabus/assignments',
            '03_Cong_Giao_Vien/08_xem_tai_lieu_giao_trinh': 'http://127.0.0.1:8000/syllabus/teacher-view',
            '03_Cong_Giao_Vien/09_de_xuat_sua_giao_trinh': 'http://127.0.0.1:8000/syllabus/teacher-propose',
            '01_Web_Admin/05_chi_tiet_de_xuat_sua_giao_trinh': 'http://127.0.0.1:8000/syllabus/versions',
            '03_Cong_Giao_Vien/14_xin_dieu_chinh_tien_do': 'http://127.0.0.1:8000/syllabus/teacher-adjust',
            '01_Web_Admin/04_duyet_yeu_cau_dieu_chinh_tien_do': 'http://127.0.0.1:8000/syllabus/adjustment-requests',
            // Flow 3: Giáo viên chấm bài
            '03_Cong_Giao_Vien/04_bai_nop_cua_lop': 'http://127.0.0.1:8000/portal/teacher-submissions',
            // Flow 4: Trải nghiệm Học sinh & Phụ huynh (7 bước chuẩn BA)
            '04_Cong_Phu_Huynh_Hoc_Sinh/01_app_shell_phu_huynh_hoc_sinh': 'http://127.0.0.1:8000/portal/app-shell',
            '04_Cong_Phu_Huynh_Hoc_Sinh/02_trang_chu_phu_huynh_hoc_sinh': 'http://127.0.0.1:8000/portal/home',
            '04_Cong_Phu_Huynh_Hoc_Sinh/03_hoc_tap_cua_toi_nop_bai_tap': 'http://127.0.0.1:8000/portal/student-homework',
            '04_Cong_Phu_Huynh_Hoc_Sinh/04_luyen_phat_am': 'http://127.0.0.1:8000/portal/pronunciation',
            '04_Cong_Phu_Huynh_Hoc_Sinh/05_danh_sach_thong_bao': 'http://127.0.0.1:8000/portal/notifications',
            '04_Cong_Phu_Huynh_Hoc_Sinh/06_khao_sat': 'http://127.0.0.1:8000/portal/survey',
            '04_Cong_Phu_Huynh_Hoc_Sinh/07_phu_huynh_gui_feedback': 'http://127.0.0.1:8000/portal/feedback',
        }};

        // App State
        let currentView = 'flow'; // 'flow' | 'viewer' | 'grid'
        let currentFlowId = 'flow-1';
        let currentStepIndex = 0;
        let viewerMode = 'code'; // 'code' | 'design'
        let cardVisualMode = 'wireframe'; // 'wireframe' (clean blueprint) | 'mockup' (raw png)
        let gridCategory = 'all';
        let expandedFlows = new Set(FLOW_DATA.map(f => f.id));

        // Toggle Card Visual Mode (Wireframe vs Mockup)
        function setCardVisualMode(mode) {{
            cardVisualMode = mode;
            ['wireframe', 'mockup'].forEach(m => {{
                const b1 = document.getElementById(`cardModeBtn-${{m}}`);
                const b2 = document.getElementById(`subCardModeBtn-${{m}}`);
                if (m === mode) {{
                    if (b1) b1.className = 'px-2.5 py-1 rounded-md transition-all bg-white text-orange-700 font-bold shadow-xs flex items-center gap-1';
                    if (b2) b2.className = 'px-3 py-1 rounded-md transition-all bg-white text-orange-700 font-bold shadow-xs flex items-center gap-1';
                }} else {{
                    if (b1) b1.className = 'px-2.5 py-1 rounded-md transition-all text-slate-500 hover:text-slate-900 flex items-center gap-1';
                    if (b2) b2.className = 'px-3 py-1 rounded-md transition-all text-slate-500 hover:text-slate-900 flex items-center gap-1';
                }}
            }});

            renderFlows();
            if (currentView === 'grid') renderAllScreensGrid(ALL_SCREENS);
        }}

        // Helper: Generate Clean Archetype Wireframe Canvas (Pure CSS/HTML, No Tiny Text!)
        function generateWireframePreview(st) {{
            const type = st.arch_type || 'table';
            const icon = st.arch_icon || 'web';
            const label = st.arch_label || 'Giao diện';
            const badge = st.arch_badge || 'bg-slate-50 text-slate-700 border-slate-200';

            // Archetype stylized vector wireframes
            if (type === 'table') {{
                return `
                    <div class="w-full h-full bg-slate-900 pattern-grid flex flex-col justify-between p-3 relative select-none">
                        <!-- Mini Window Bar -->
                        <div class="flex items-center justify-between border-b border-slate-800/80 pb-2">
                            <div class="flex items-center gap-1.5">
                                <span class="w-2 h-2 rounded-full bg-rose-500/80"></span>
                                <span class="w-2 h-2 rounded-full bg-amber-500/80"></span>
                                <span class="w-2 h-2 rounded-full bg-emerald-500/80"></span>
                            </div>
                            <span class="text-[10px] font-mono text-blue-400 font-bold flex items-center gap-1">
                                <span class="material-symbols-outlined text-[13px]">table_chart</span> BẢNG DỮ LIỆU
                            </span>
                        </div>
                        <!-- Mini Table Rows -->
                        <div class="space-y-1.5 my-auto">
                            <div class="h-2 bg-slate-800 rounded w-full flex items-center justify-between px-1">
                                <div class="w-12 h-1 bg-slate-700 rounded"></div>
                                <div class="w-6 h-1 bg-blue-500/60 rounded"></div>
                            </div>
                            <div class="h-2 bg-slate-800/60 rounded w-full flex items-center justify-between px-1">
                                <div class="w-16 h-1 bg-slate-700 rounded"></div>
                                <div class="w-8 h-1 bg-emerald-500/60 rounded"></div>
                            </div>
                            <div class="h-2 bg-slate-800/40 rounded w-full flex items-center justify-between px-1">
                                <div class="w-10 h-1 bg-slate-700 rounded"></div>
                                <div class="w-5 h-1 bg-amber-500/60 rounded"></div>
                            </div>
                        </div>
                        <!-- Center Focus Badge -->
                        <div class="absolute inset-0 flex items-center justify-center pointer-events-none">
                            <div class="w-11 h-11 rounded-xl bg-blue-500/10 border border-blue-500/30 flex items-center justify-center text-blue-400 shadow-inner">
                                <span class="material-symbols-outlined text-2xl">${{icon}}</span>
                            </div>
                        </div>
                    </div>
                `;
            }} else if (type === 'form') {{
                return `
                    <div class="w-full h-full bg-slate-900 pattern-grid flex flex-col justify-between p-3 relative select-none">
                        <div class="flex items-center justify-between border-b border-slate-800/80 pb-2">
                            <div class="flex items-center gap-1.5">
                                <span class="w-2 h-2 rounded-full bg-rose-500/80"></span>
                                <span class="w-2 h-2 rounded-full bg-amber-500/80"></span>
                                <span class="w-2 h-2 rounded-full bg-emerald-500/80"></span>
                            </div>
                            <span class="text-[10px] font-mono text-amber-400 font-bold flex items-center gap-1">
                                <span class="material-symbols-outlined text-[13px]">edit_note</span> BIỂU MẪU NHẬP
                            </span>
                        </div>
                        <!-- Form skeleton inputs -->
                        <div class="space-y-1.5 my-auto max-w-[160px]">
                            <div class="h-2 bg-slate-800 rounded w-16"></div>
                            <div class="h-3.5 bg-slate-800 border border-slate-700 rounded w-full"></div>
                            <div class="h-2 bg-slate-800 rounded w-12"></div>
                            <div class="h-3.5 bg-slate-800 border border-slate-700 rounded w-full"></div>
                        </div>
                        <!-- Center Focus Badge -->
                        <div class="absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none">
                            <div class="w-11 h-11 rounded-xl bg-amber-500/10 border border-amber-500/30 flex items-center justify-center text-amber-400 shadow-inner">
                                <span class="material-symbols-outlined text-2xl">${{icon}}</span>
                            </div>
                        </div>
                    </div>
                `;
            }} else if (type === 'mobile' || type === 'audio') {{
                return `
                    <div class="w-full h-full bg-slate-900 pattern-grid flex items-center justify-center p-2 relative select-none">
                        <!-- Phone Silhouette -->
                        <div class="w-28 h-28 rounded-2xl bg-slate-950 border border-cyan-500/40 shadow-lg flex flex-col p-1.5 relative overflow-hidden">
                            <div class="w-6 h-1 bg-slate-800 rounded-full mx-auto mb-1"></div>
                            <div class="flex-1 rounded-lg bg-slate-900 flex flex-col items-center justify-center p-1">
                                <span class="material-symbols-outlined text-xl text-cyan-400 mb-0.5">${{icon}}</span>
                                <span class="text-[9px] font-bold text-cyan-300 font-mono">${{type === 'audio' ? 'THU ÂM AI' : 'MOBILE APP'}}</span>
                                ${{type === 'audio' ? `
                                    <div class="flex items-center gap-0.5 mt-1">
                                        <span class="w-1 h-2 bg-rose-400 rounded-full"></span>
                                        <span class="w-1 h-3.5 bg-rose-500 rounded-full animate-pulse"></span>
                                        <span class="w-1 h-2 bg-rose-400 rounded-full"></span>
                                    </div>
                                ` : ''}}
                            </div>
                        </div>
                        <div class="absolute top-2 right-2">
                            <span class="text-[9px] font-mono text-cyan-400 font-bold px-1.5 py-0.5 rounded bg-cyan-950/80 border border-cyan-800">
                                📱 Phone
                            </span>
                        </div>
                    </div>
                `;
            }} else if (type === 'approval') {{
                return `
                    <div class="w-full h-full bg-slate-900 pattern-grid flex flex-col justify-between p-3 relative select-none">
                        <div class="flex items-center justify-between border-b border-slate-800/80 pb-2">
                            <span class="text-[10px] font-mono text-emerald-400 font-bold flex items-center gap-1">
                                <span class="material-symbols-outlined text-[13px]">verified</span> QUY TRÌNH PHÊ DUYỆT
                            </span>
                            <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                        </div>
                        <div class="flex items-center justify-center gap-3 my-auto">
                            <div class="px-2.5 py-1.5 rounded-lg bg-emerald-950/80 border border-emerald-500/50 text-emerald-300 text-[10px] font-bold flex items-center gap-1">
                                <span class="material-symbols-outlined text-xs">check_circle</span> Duyệt
                            </div>
                            <div class="text-slate-600">&harr;</div>
                            <div class="px-2.5 py-1.5 rounded-lg bg-rose-950/80 border border-rose-500/50 text-rose-300 text-[10px] font-bold flex items-center gap-1">
                                <span class="material-symbols-outlined text-xs">cancel</span> Từ chối
                            </div>
                        </div>
                        <div class="text-[10px] text-center text-slate-500 font-mono">SLA: 24 Giờ</div>
                    </div>
                `;
            }} else if (type === 'kpi') {{
                return `
                    <div class="w-full h-full bg-slate-900 pattern-grid flex flex-col justify-between p-3 relative select-none">
                        <div class="flex items-center justify-between border-b border-slate-800/80 pb-2">
                            <span class="text-[10px] font-mono text-purple-400 font-bold flex items-center gap-1">
                                <span class="material-symbols-outlined text-[13px]">payments</span> LƯƠNG & KPI
                            </span>
                            <span class="text-[9px] font-bold text-purple-300 px-1.5 py-0.2 rounded bg-purple-900/60 border border-purple-700">Tháng</span>
                        </div>
                        <div class="flex items-center justify-around my-auto">
                            <div class="text-center">
                                <div class="text-sm font-extrabold text-white font-mono">100%</div>
                                <div class="text-[9px] text-purple-300">Hoàn thành</div>
                            </div>
                            <div class="w-9 h-9 rounded-full border-2 border-purple-500/60 border-t-purple-400 flex items-center justify-center text-purple-300">
                                <span class="material-symbols-outlined text-sm">trending_up</span>
                            </div>
                        </div>
                    </div>
                `;
            }} else {{
                // Default dashboard / general
                return `
                    <div class="w-full h-full bg-slate-900 pattern-grid flex flex-col justify-between p-3 relative select-none">
                        <div class="flex items-center justify-between border-b border-slate-800/80 pb-2">
                            <div class="flex items-center gap-1.5">
                                <span class="w-2 h-2 rounded-full bg-orange-500"></span>
                                <span class="text-[10px] font-mono text-orange-400 font-bold uppercase">${{label}}</span>
                            </div>
                        </div>
                        <div class="flex items-center justify-center my-auto">
                            <div class="w-12 h-12 rounded-xl bg-orange-500/10 border border-orange-500/30 flex items-center justify-center text-orange-400">
                                <span class="material-symbols-outlined text-2xl">${{icon}}</span>
                            </div>
                        </div>
                        <div class="h-1.5 bg-slate-800 rounded w-3/4 mx-auto"></div>
                    </div>
                `;
            }}
        }}

        // Role Color Badge
        function getRoleBadge(role) {{
            let color = 'bg-slate-100 text-slate-700 border-slate-200';
            if (role.includes('Admin')) color = 'bg-orange-50 text-orange-700 border-orange-200';
            else if (role.includes('Học vụ')) color = 'bg-blue-50 text-blue-700 border-blue-200';
            else if (role.includes('Học thuật') || role.includes('QA') || role.includes('bộ môn')) color = 'bg-indigo-50 text-indigo-700 border-indigo-200';
            else if (role.includes('Giáo viên')) color = 'bg-emerald-50 text-emerald-700 border-emerald-200';
            else if (role.includes('Phụ huynh') || role.includes('Học sinh')) color = 'bg-cyan-50 text-cyan-700 border-cyan-200';
            else if (role.includes('Kế toán') || role.includes('Nhân sự') || role.includes('Quản trị')) color = 'bg-purple-50 text-purple-700 border-purple-200';

            return `<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-bold border ${{color}} shrink-0">
                ${{role}}
            </span>`;
        }}

        // Render Sơ đồ 8 Luồng (FLOW VIEW)
        function renderFlows() {{
            const container = document.getElementById('flowListContainer');
            container.innerHTML = '';

            FLOW_DATA.forEach((flow, fIndex) => {{
                const flowCard = document.createElement('div');
                flowCard.id = flow.id;
                flowCard.className = 'bg-white rounded-2xl border border-slate-200/90 shadow-xs overflow-hidden scroll-mt-20';

                // Pipeline steps HTML
                const stepsHtml = flow.steps.map((st, sIndex) => {{
                    const thumbUrl = st.has_png ? `${{st.category_id}}/${{st.folder_name}}/screen.png` : '';
                    const codeUrl = `${{st.category_id}}/${{st.folder_name}}/code.html`;
                    
                    return `
                        <div class="flex items-center shrink-0">
                            
                            <!-- Sleek Step Card -->
                            <div class="w-64 bg-white rounded-xl border border-slate-200 hover:border-orange-500 shadow-xs hover:shadow-md transition-all flex flex-col overflow-hidden group/step cursor-pointer"
                                 onclick="startFlowAtStep('${{flow.id}}', ${{sIndex}})">
                                
                                <!-- Step Card Header: #Step & Role -->
                                <div class="px-3 py-2 bg-slate-50 border-b border-slate-100 flex items-center justify-between gap-1.5">
                                    <span class="inline-flex items-center gap-1 font-bold text-xs text-slate-800">
                                        <span class="w-5 h-5 rounded-full bg-slate-800 text-white text-[10px] flex items-center justify-center font-mono font-bold">
                                            ${{sIndex + 1}}
                                        </span>
                                        <span>Bước ${{sIndex + 1}}</span>
                                    </span>
                                    ${{getRoleBadge(st.role)}}
                                </div>

                                <!-- Card Visual: Clean Wireframe vs Raw Screenshot -->
                                <div class="h-32 relative overflow-hidden flex items-center justify-center border-b border-slate-100 group/canvas">
                                    ${{cardVisualMode === 'wireframe' 
                                        ? generateWireframePreview(st)
                                        : (st.has_png 
                                            ? `<img src="${{thumbUrl}}" alt="${{st.title_vn}}" class="w-full h-full object-cover object-top group-hover/step:scale-105 transition-transform duration-300">`
                                            : `<div class="w-full h-full bg-slate-900 text-slate-400 flex items-center justify-center text-xs font-mono">HTML Live Prototype</div>`
                                          )
                                    }}
                                    
                                    <!-- Hover Live Action Overlay -->
                                    <div class="absolute inset-0 bg-slate-900/60 opacity-0 group-hover/step:opacity-100 transition-opacity flex items-center justify-center gap-2">
                                        <span class="px-3 py-1 rounded-lg bg-orange-600 text-white text-xs font-bold shadow flex items-center gap-1">
                                            <span class="material-symbols-outlined text-sm">play_arrow</span> Xem Live
                                        </span>
                                        ${{st.has_png ? `
                                            <button onclick="event.stopPropagation(); openImageLightbox('${{thumbUrl}}')" title="Xem ảnh to" class="p-1 rounded-lg bg-slate-800 hover:bg-slate-700 text-white">
                                                <span class="material-symbols-outlined text-sm">zoom_in</span>
                                            </button>
                                        ` : ''}}
                                    </div>
                                </div>

                                <!-- Card Body: Concise, Zero Fluff -->
                                <div class="p-3 flex-1 flex flex-col justify-between">
                                    <div>
                                        <div class="flex items-center gap-1.5 mb-1 flex-wrap">
                                            <span class="text-[9px] font-bold px-1.5 py-0.2 rounded border ${{st.arch_badge}} uppercase">
                                                ${{st.arch_label}}
                                            </span>
                                            ${{LARAVEL_NATIVE_MAP[st.category_id + '/' + st.folder_name] ? `
                                                <span class="text-[9px] font-extrabold px-1.5 py-0.2 rounded bg-emerald-100 text-emerald-800 border border-emerald-300 uppercase tracking-tight flex items-center gap-1" title="Đã được ghép hoàn thiện vào Laravel">
                                                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-600"></span>Laravel Native
                                                </span>
                                            ` : ''}}
                                        </div>
                                        <h4 class="text-xs font-bold text-slate-900 group-hover/step:text-orange-600 transition-colors line-clamp-1 mb-1" title="${{st.stepName || st.title_vn}}">
                                            ${{st.stepName || st.title_vn}}
                                        </h4>
                                        <p class="text-[11px] text-slate-500 line-clamp-1 font-medium">
                                            ${{st.short_action}}
                                        </p>
                                    </div>

                                    <!-- Bottom Action Links -->
                                    <div class="pt-2 mt-2 border-t border-slate-100 flex items-center justify-between gap-1 text-[11px]">
                                        <span class="text-orange-600 font-bold group-hover/step:underline flex items-center gap-0.5">
                                            Mở tương tác &rarr;
                                        </span>
                                        <div class="flex items-center gap-1">
                                            ${{LARAVEL_NATIVE_MAP[st.category_id + '/' + st.folder_name] ? `
                                                <a href="${{LARAVEL_NATIVE_MAP[st.category_id + '/' + st.folder_name]}}" target="_blank" onclick="event.stopPropagation()" title="Mở trực tiếp trên Laravel Native" class="px-1.5 py-0.5 text-[10px] font-bold text-emerald-700 bg-emerald-50 hover:bg-emerald-600 hover:text-white rounded border border-emerald-300 transition">
                                                    Laravel
                                                </a>
                                            ` : ''}}
                                            <a href="${{codeUrl}}" target="_blank" onclick="event.stopPropagation()" title="Mở tab riêng" class="p-1 text-slate-400 hover:text-slate-700 hover:bg-slate-100 rounded transition">
                                                <span class="material-symbols-outlined text-sm">open_in_new</span>
                                            </a>
                                        </div>
                                    </div>
                                </div>

                            </div>

                            <!-- Connector Arrow between steps -->
                            ${{sIndex < flow.steps.length - 1 ? `
                                <div class="flex flex-col items-center justify-center px-1.5 text-slate-300">
                                    <span class="material-symbols-outlined text-lg text-orange-400">arrow_forward</span>
                                </div>
                            ` : ''}}
                        </div>
                    `;
                }}).join('');

                flowCard.innerHTML = `
                    <!-- Flow Header: Clean & Compact -->
                    <div class="p-4 sm:p-5 bg-gradient-to-r from-slate-900 to-slate-800 text-white flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-gradient-to-br ${{flow.badge_color}} flex items-center justify-center text-white shrink-0 shadow-md">
                                <span class="material-symbols-outlined text-xl">${{flow.icon}}</span>
                            </div>
                            <div>
                                <div class="flex items-center gap-2">
                                    <span class="px-2 py-0.2 rounded text-[10px] font-extrabold uppercase tracking-wider bg-white/20 text-orange-300 font-mono">
                                        Luồng ${{fIndex + 1}}
                                    </span>
                                    <h3 class="text-base sm:text-lg font-bold tracking-tight text-white">
                                        ${{flow.title}}
                                    </h3>
                                    <span class="text-xs text-slate-400 font-medium">(${{flow.steps.length}} bước)</span>
                                </div>
                                <p class="text-xs text-slate-300 mt-0.5 max-w-2xl line-clamp-1">
                                    ${{flow.desc}}
                                </p>
                            </div>
                        </div>

                        <!-- Run Entire Flow Button -->
                        <div class="shrink-0">
                            <button onclick="startFlowAtStep('${{flow.id}}', 0)" 
                                class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-xl bg-orange-600 hover:bg-orange-500 text-white font-bold text-xs shadow transition hover:scale-105 active:scale-95">
                                <span class="material-symbols-outlined text-base">play_arrow</span>
                                <span>Chạy toàn bộ luồng này &rarr;</span>
                            </button>
                        </div>
                    </div>

                    <!-- Flow Pipeline Horizontal Track -->
                    <div class="p-4 bg-slate-50/70 overflow-x-auto border-t border-slate-200/60 flex items-stretch gap-1">
                        ${{stepsHtml}}
                    </div>
                `;

                container.appendChild(flowCard);
            }});
        }}

        // Start Flow in Viewer
        function startFlowAtStep(flowId, stepIdx) {{
            currentFlowId = flowId;
            currentStepIndex = stepIdx;
            switchView('viewer');
            loadViewerStep();
        }}

        // Load step details into Live Viewer
        function loadViewerStep() {{
            const flow = FLOW_DATA.find(f => f.id === currentFlowId) || FLOW_DATA[0];
            if (currentStepIndex < 0) currentStepIndex = 0;
            if (currentStepIndex >= flow.steps.length) currentStepIndex = flow.steps.length - 1;

            const step = flow.steps[currentStepIndex];
            const flowIdx = FLOW_DATA.indexOf(flow) + 1;

            // Top Bar Meta
            document.getElementById('viewerFlowTag').innerText = `Luồng ${{flowIdx}}: ${{flow.short_title}}`;
            document.getElementById('viewerScreenTitle').innerText = `${{step.stepName || step.title_vn}}`;
            document.getElementById('viewerRoleBadge').innerText = `Tác nhân: ${{step.role}}`;
            document.getElementById('viewerStepIndicator').innerText = `Bước ${{currentStepIndex + 1}}/${{flow.steps.length}}`;
            document.getElementById('viewerActionTag').innerText = step.short_action || '';
            
            const archBadge = document.getElementById('viewerArchBadge');
            if (archBadge) archBadge.innerText = step.arch_label || 'Giao diện';

            document.getElementById('currentStepIndexBadge').innerText = currentStepIndex + 1;
            document.getElementById('totalStepIndexBadge').innerText = flow.steps.length;

            document.getElementById('prevStepBtn').disabled = (currentStepIndex === 0);
            document.getElementById('nextStepBtn').disabled = (currentStepIndex === flow.steps.length - 1);

            const codeUrl = `${{step.category_id}}/${{step.folder_name}}/code.html`;
            const thumbUrl = step.has_png ? `${{step.category_id}}/${{step.folder_name}}/screen.png` : '';
            document.getElementById('viewerExternalLink').href = codeUrl;

            // Update Laravel Native link & badge
            const screenKey = `${{step.category_id}}/${{step.folder_name}}`;
            const nativeUrl = LARAVEL_NATIVE_MAP[screenKey];
            const nativeBtn = document.getElementById('viewerLaravelNativeLink');
            const nativeBadge = document.getElementById('viewerNativeStatusBadge');

            if (nativeUrl) {{
                if (nativeBtn) {{
                    nativeBtn.href = nativeUrl;
                    nativeBtn.classList.remove('hidden');
                    nativeBtn.classList.add('inline-flex');
                }}
                if (nativeBadge) {{
                    nativeBadge.classList.remove('hidden');
                    nativeBadge.classList.add('inline-flex');
                }}
            }} else {{
                if (nativeBtn) {{
                    nativeBtn.classList.add('hidden');
                    nativeBtn.classList.remove('inline-flex');
                }}
                if (nativeBadge) {{
                    nativeBadge.classList.add('hidden');
                    nativeBadge.classList.remove('inline-flex');
                }}
            }}

            // Update Iframe & Image Mockup
            const iframe = document.getElementById('screenIframe');
            if (iframe.src !== codeUrl && !iframe.src.endsWith(codeUrl)) {{
                iframe.src = codeUrl;
            }}

            const mockupImg = document.getElementById('screenImageMockup');
            if (step.has_png) {{
                mockupImg.src = thumbUrl;
                mockupImg.classList.remove('hidden');
            }} else {{
                mockupImg.src = '';
                mockupImg.classList.add('hidden');
            }}

            // Render bottom step dots
            renderBottomStepper(flow);
            renderViewerSidebar();

            window.location.hash = `view=viewer&flow=${{currentFlowId}}&step=${{currentStepIndex + 1}}`;
        }}

        function navigateStep(delta) {{
            const flow = FLOW_DATA.find(f => f.id === currentFlowId);
            if (!flow) return;
            const target = currentStepIndex + delta;
            if (target >= 0 && target < flow.steps.length) {{
                currentStepIndex = target;
                loadViewerStep();
            }}
        }}

        function renderBottomStepper(flow) {{
            const container = document.getElementById('viewerBottomStepper');
            container.innerHTML = flow.steps.map((st, idx) => {{
                const isCur = (idx === currentStepIndex);
                return `
                    <button onclick="jumpToStepInCurrentFlow(${{idx}})" title="${{st.stepName || st.title_vn}}"
                        class="flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold transition-all ${{
                            isCur 
                            ? 'bg-orange-600 text-white shadow-md' 
                            : 'text-slate-400 hover:text-white hover:bg-slate-800'
                        }}">
                        <span class="font-mono text-[10px]">${{idx + 1}}</span>
                        <span class="hidden md:inline max-w-[100px] truncate text-[11px]">${{st.stepName || st.title_vn}}</span>
                    </button>
                `;
            }}).join('');
        }}

        function jumpToStepInCurrentFlow(idx) {{
            currentStepIndex = idx;
            loadViewerStep();
        }}

        // Render Sidebar inside Live Viewer
        function renderViewerSidebar() {{
            const container = document.getElementById('viewerSidebarContent');
            container.innerHTML = FLOW_DATA.map((flow, fIdx) => {{
                const isExpanded = expandedFlows.has(flow.id);
                const isCurrentFlow = (flow.id === currentFlowId);
                return `
                    <div class="rounded-xl border ${{isCurrentFlow ? 'border-orange-500/60 bg-slate-800/60' : 'border-slate-800/80 bg-slate-900/90'}} overflow-hidden transition-all shadow-xs">
                        <button onclick="toggleFlowAccordion('${{flow.id}}')" 
                            class="w-full text-left p-2.5 flex items-center justify-between gap-2 hover:bg-slate-800/80 transition-colors group cursor-pointer">
                            <div class="flex items-center gap-2 min-w-0">
                                <div class="w-6 h-6 rounded-lg bg-gradient-to-br ${{flow.badge_color}} text-white flex items-center justify-center shrink-0 shadow-xs">
                                    <span class="material-symbols-outlined text-xs">${{flow.icon}}</span>
                                </div>
                                <div class="min-w-0">
                                    <div class="text-xs font-bold text-white group-hover:text-orange-300 transition-colors truncate">${{fIdx + 1}}. ${{flow.short_title}}</div>
                                    <div class="text-[10px] text-slate-400">${{flow.steps.length}} bước</div>
                                </div>
                            </div>
                            <span class="material-symbols-outlined text-sm text-slate-400 group-hover:text-white transition-transform duration-200 ${{isExpanded ? 'rotate-180' : ''}}">
                                expand_more
                            </span>
                        </button>
                        
                        ${{isExpanded ? `
                            <div class="p-1.5 space-y-1 bg-slate-950/60 border-t border-slate-800/60">
                                ${{flow.steps.map((st, sIdx) => {{
                                    const isCurrentStep = (isCurrentFlow && sIdx === currentStepIndex);
                                    return `
                                        <button onclick="jumpToStep('${{flow.id}}', ${{sIdx}})" 
                                            class="w-full text-left px-2 py-1.5 rounded-lg flex items-center justify-between text-xs transition-all ${{
                                                isCurrentStep 
                                                ? 'bg-orange-600 text-white font-bold shadow-md' 
                                                : 'text-slate-300 hover:bg-slate-800/80 hover:text-white'
                                            }}">
                                            <div class="flex items-center gap-2 min-w-0">
                                                <span class="text-[10px] font-mono opacity-70 shrink-0">#${{sIdx + 1}}</span>
                                                <span class="truncate text-[11px]">${{st.stepName || st.title_vn}}</span>
                                            </div>
                                            <span class="text-[9px] px-1.5 py-0.2 rounded shrink-0 ${{isCurrentStep ? 'bg-orange-700 text-white' : 'bg-slate-800 text-slate-400'}}">
                                                ${{st.role.split('/')[0]}}
                                            </span>
                                        </button>
                                    `;
                                }}).join('')}}
                            </div>
                        ` : ''}}
                    </div>
                `;
            }}).join('');
            updateExpandAllButton();
        }}

        function toggleFlowAccordion(flowId) {{
            if (expandedFlows.has(flowId)) {{
                expandedFlows.delete(flowId);
            }} else {{
                expandedFlows.add(flowId);
            }}
            renderViewerSidebar();
        }}

        function toggleExpandAllFlows() {{
            if (expandedFlows.size > 0) {{
                expandedFlows.clear();
            }} else {{
                expandedFlows = new Set(FLOW_DATA.map(f => f.id));
            }}
            renderViewerSidebar();
        }}

        function updateExpandAllButton() {{
            const textEl = document.getElementById('expandAllText');
            const iconEl = document.getElementById('expandAllIcon');
            if (!textEl || !iconEl) return;
            if (expandedFlows.size === 0) {{
                textEl.innerText = 'Mở tất cả';
                iconEl.innerText = 'unfold_more';
            }} else {{
                textEl.innerText = 'Thu gọn';
                iconEl.innerText = 'unfold_less';
            }}
        }}

        function jumpToStep(flowId, stepIdx) {{
            currentFlowId = flowId;
            currentStepIndex = stepIdx;
            expandedFlows.add(flowId);
            loadViewerStep();
        }}

        // Switch Device Width Viewport
        function setDeviceWidth(width) {{
            const wrapper = document.getElementById('viewerIframeWrapper');
            const iframe = document.getElementById('screenIframe');
            
            ['desktop', 'tablet', 'mobile'].forEach(d => {{
                document.getElementById(`deviceBtn-${{d}}`).className = 'p-1.5 rounded text-slate-400 hover:text-white transition-all';
            }});

            if (width === '100%') {{
                document.getElementById('deviceBtn-desktop').className = 'p-1.5 rounded text-white bg-slate-700';
                wrapper.style.maxWidth = '100%';
                iframe.style.height = '100%';
            }} else if (width === '768px') {{
                document.getElementById('deviceBtn-tablet').className = 'p-1.5 rounded text-white bg-slate-700';
                wrapper.style.maxWidth = '768px';
                iframe.style.height = '1024px';
            }} else if (width === '390px') {{
                document.getElementById('deviceBtn-mobile').className = 'p-1.5 rounded text-white bg-slate-700';
                wrapper.style.maxWidth = '390px';
                iframe.style.height = '844px';
            }}
        }}

        // Toggle Viewer Mode (HTML Code vs Mockup Image)
        function setViewerMode(mode) {{
            viewerMode = mode;
            const codeWrapper = document.getElementById('viewerIframeWrapper');
            const imgWrapper = document.getElementById('viewerImageWrapper');
            const btnCode = document.getElementById('modeBtn-code');
            const btnDesign = document.getElementById('modeBtn-design');

            if (mode === 'code') {{
                codeWrapper.classList.remove('hidden');
                imgWrapper.classList.add('hidden');
                btnCode.className = 'px-2.5 py-1 rounded text-xs font-semibold bg-orange-600 text-white transition';
                btnDesign.className = 'px-2.5 py-1 rounded text-xs font-semibold text-slate-400 hover:text-white transition';
            }} else {{
                codeWrapper.classList.add('hidden');
                imgWrapper.classList.remove('hidden');
                btnDesign.className = 'px-2.5 py-1 rounded text-xs font-semibold bg-orange-600 text-white transition';
                btnCode.className = 'px-2.5 py-1 rounded text-xs font-semibold text-slate-400 hover:text-white transition';
            }}
        }}

        // Toggle Viewer Sidebar
        function toggleViewerSidebar() {{
            const sb = document.getElementById('viewerSidebar');
            if (sb.classList.contains('hidden')) {{
                sb.classList.remove('hidden');
            }} else {{
                sb.classList.add('hidden');
            }}
        }}

        // Offcanvas Flow Drawer
        function toggleFlowDrawer() {{
            const overlay = document.getElementById('flowDrawerOverlay');
            const panel = document.getElementById('flowDrawerPanel');

            if (panel.classList.contains('translate-x-full')) {{
                overlay.classList.remove('hidden');
                panel.classList.remove('translate-x-full');
                renderFlowDrawerList();
            }} else {{
                panel.classList.add('translate-x-full');
                setTimeout(() => overlay.classList.add('hidden'), 250);
            }}
        }}

        function renderFlowDrawerList() {{
            const list = document.getElementById('flowDrawerList');
            list.innerHTML = FLOW_DATA.map((flow, idx) => `
                <div class="p-3 rounded-xl border border-slate-200 hover:border-orange-300 bg-white hover:bg-orange-50/40 transition-all cursor-pointer group"
                     onclick="toggleFlowDrawer(); startFlowAtStep('${{flow.id}}', 0);">
                    <div class="flex items-center justify-between gap-2 mb-1">
                        <div class="flex items-center gap-2">
                            <span class="w-6 h-6 rounded-md bg-gradient-to-br ${{flow.badge_color}} text-white flex items-center justify-center text-xs font-bold">
                                ${{idx + 1}}
                            </span>
                            <h4 class="text-xs font-bold text-slate-900 group-hover:text-orange-600 transition-colors">${{flow.title}}</h4>
                        </div>
                        <span class="text-[10px] font-semibold bg-slate-100 text-slate-600 px-2 py-0.5 rounded-full">${{flow.steps.length}} bước</span>
                    </div>
                    <p class="text-[11px] text-slate-500 line-clamp-1 mt-0.5">${{flow.desc}}</p>
                </div>
            `).join('');
        }}

        // Lightbox Modal functions
        function openImageLightbox(src) {{
            if (!src) return;
            const modal = document.getElementById('imageLightboxModal');
            const img = document.getElementById('lightboxImg');
            img.src = src;
            modal.classList.remove('hidden');
        }}

        function closeImageLightbox() {{
            document.getElementById('imageLightboxModal').classList.add('hidden');
        }}

        // GRID VIEW - Render all 58 screens
        function renderAllScreensGrid(items) {{
            const grid = document.getElementById('allScreensGrid');
            grid.innerHTML = '';

            items.forEach((s, idx) => {{
                const card = document.createElement('div');
                card.className = 'bg-white rounded-xl border border-slate-200 hover:border-orange-500 shadow-xs hover:shadow-md transition-all flex flex-col overflow-hidden group cursor-pointer';
                card.onclick = () => openViewerWithScreen(`${{s.category_id}}/${{s.folder_name}}`);

                const thumbUrl = s.has_png ? `${{s.category_id}}/${{s.folder_name}}/screen.png` : '';
                const codeUrl = `${{s.category_id}}/${{s.folder_name}}/code.html`;
                
                card.innerHTML = `
                    <div class="h-32 bg-slate-100 relative overflow-hidden border-b border-slate-100 flex items-center justify-center">
                        ${{cardVisualMode === 'wireframe'
                            ? generateWireframePreview(s)
                            : (s.has_png 
                                ? `<img src="${{thumbUrl}}" alt="${{s.title_vn}}" class="w-full h-full object-cover object-top group-hover:scale-105 transition duration-300">`
                                : `<div class="w-full h-full bg-slate-900 text-slate-400 flex items-center justify-center text-xs font-mono">HTML Live</div>`
                              )
                        }}
                        <div class="absolute top-2 left-2 bg-slate-900/80 backdrop-blur-xs text-white text-[10px] font-mono font-semibold px-2 py-0.5 rounded-full">
                            #${{idx + 1}}
                        </div>
                    </div>
                    <div class="p-3.5 flex-1 flex flex-col justify-between">
                        <div>
                            <div class="flex items-center gap-1.5 mb-1">
                                <span class="text-[9px] font-bold px-1.5 py-0.2 rounded border ${{s.arch_badge}} uppercase">
                                    ${{s.arch_label}}
                                </span>
                                <span class="text-[10px] text-slate-400 truncate">${{s.category_name.split('(')[0]}}</span>
                            </div>
                            <h3 class="text-xs font-bold text-slate-800 line-clamp-1 mb-1 group-hover:text-orange-600 transition">
                                ${{s.title_vn}}
                            </h3>
                            <p class="text-[11px] text-slate-500 line-clamp-1 font-medium">
                                ${{s.short_action}}
                            </p>
                        </div>
                        <div class="pt-2 mt-2 border-t border-slate-100 flex items-center justify-between gap-1 text-[11px]">
                            <span class="text-orange-600 font-bold group-hover:underline">Xem Live &rarr;</span>
                            <a href="${{codeUrl}}" target="_blank" onclick="event.stopPropagation()" title="Mở tab mới" class="p-1 text-slate-400 hover:text-slate-700 hover:bg-slate-100 rounded transition">
                                <span class="material-symbols-outlined text-sm">open_in_new</span>
                            </a>
                        </div>
                    </div>
                `;
                grid.appendChild(card);
            }});
        }}

        function filterGridCategory(cat) {{
            gridCategory = cat;
            document.querySelectorAll('.grid-tab-btn').forEach(btn => {{
                if (btn.dataset.cat === cat) {{
                    btn.className = 'grid-tab-btn active px-3 py-1.5 rounded-lg bg-orange-600 text-white shadow-xs';
                }} else {{
                    btn.className = 'grid-tab-btn px-3 py-1.5 rounded-lg bg-slate-200 text-slate-700 hover:bg-slate-300';
                }}
            }});
            const filtered = (cat === 'all') ? ALL_SCREENS : ALL_SCREENS.filter(s => s.category_id === cat);
            renderAllScreensGrid(filtered);
        }}

        function openViewerWithScreen(screenPath) {{
            let foundFlowId = 'flow-1';
            let foundStepIdx = 0;

            for (const f of FLOW_DATA) {{
                const idx = f.steps.findIndex(st => st.screen === screenPath || `${{st.category_id}}/${{st.folder_name}}` === screenPath);
                if (idx !== -1) {{
                    foundFlowId = f.id;
                    foundStepIdx = idx;
                    break;
                }}
            }}

            startFlowAtStep(foundFlowId, foundStepIdx);
        }}

        // Switch View Modes
        function switchView(viewName) {{
            currentView = viewName;
            const views = ['flow', 'viewer', 'grid'];
            views.forEach(v => {{
                const el = document.getElementById(`view-${{v}}`);
                const btn = document.getElementById(`navBtn-${{v}}`);
                if (v === viewName) {{
                    el.classList.remove('hidden');
                    if (btn) btn.className = 'view-nav-btn flex items-center gap-1.5 px-3 py-1.5 rounded-lg transition-all bg-white text-orange-600 shadow-xs font-bold';
                }} else {{
                    el.classList.add('hidden');
                    if (btn) btn.className = 'view-nav-btn flex items-center gap-1.5 px-3 py-1.5 rounded-lg transition-all text-slate-600 hover:text-slate-900';
                }}
            }});

            const footer = document.getElementById('pageFooter');
            if (viewName === 'viewer') {{
                footer.classList.add('hidden');
            }} else {{
                footer.classList.remove('hidden');
            }}

            if (viewName === 'grid') {{
                renderAllScreensGrid(ALL_SCREENS);
            }}

            window.location.hash = `view=${{viewName}}`;
        }}

        function openViewer() {{
            switchView('viewer');
            loadViewerStep();
        }}

        // Global Search
        const searchInput = document.getElementById('globalSearch');
        const searchResultsContainer = document.getElementById('searchResultsContainer');
        const searchResultsList = document.getElementById('searchResultsList');
        const searchCount = document.getElementById('searchCount');
        const clearBtn = document.getElementById('clearSearchBtn');

        searchInput.addEventListener('input', (e) => {{
            const query = e.target.value.trim().toLowerCase();
            if (!query) {{
                searchResultsContainer.classList.add('hidden');
                clearBtn.classList.add('hidden');
                return;
            }}

            clearBtn.classList.remove('hidden');
            const matches = [];

            FLOW_DATA.forEach(flow => {{
                flow.steps.forEach((st, idx) => {{
                    const text = `${{flow.title}} ${{st.stepName}} ${{st.title_vn}} ${{st.desc}} ${{st.role}} ${{st.category_name}} ${{st.short_action}}`.toLowerCase();
                    if (text.includes(query)) {{
                        matches.push({{ flow, step: st, stepIndex: idx }});
                    }}
                }});
            }});

            searchCount.innerText = matches.length;
            searchResultsList.innerHTML = '';

            if (matches.length === 0) {{
                searchResultsList.innerHTML = `<div class="col-span-full py-8 text-center text-slate-400 text-xs">Không tìm thấy màn hình nào phù hợp với "${{query}}"</div>`;
            }} else {{
                matches.slice(0, 12).forEach(m => {{
                    const item = document.createElement('div');
                    item.className = 'p-3 bg-slate-50 hover:bg-orange-50 border border-slate-200 hover:border-orange-300 rounded-xl cursor-pointer transition-all flex items-center justify-between gap-3 group';
                    item.onclick = () => {{
                        clearSearch();
                        startFlowAtStep(m.flow.id, m.stepIndex);
                    }};
                    item.innerHTML = `
                        <div class="min-w-0">
                            <div class="flex items-center gap-1.5 mb-1">
                                <span class="text-[10px] font-bold text-orange-600 uppercase tracking-wider">${{m.flow.short_title}}</span>
                                <span class="text-slate-300">&bull;</span>
                                <span class="text-[10px] text-slate-500 font-mono">Bước ${{m.stepIndex + 1}}</span>
                            </div>
                            <h5 class="text-xs font-bold text-slate-900 group-hover:text-orange-600 truncate">${{m.step.stepName || m.step.title_vn}}</h5>
                            <p class="text-[11px] text-slate-500 truncate mt-0.5">${{m.step.short_action}}</p>
                        </div>
                        ${{getRoleBadge(m.step.role)}}
                    `;
                    searchResultsList.appendChild(item);
                }});
            }}

            searchResultsContainer.classList.remove('hidden');
        }});

        function clearSearch() {{
            searchInput.value = '';
            searchResultsContainer.classList.add('hidden');
            clearBtn.classList.add('hidden');
        }}

        // Keyboard Shortcuts
        document.addEventListener('keydown', (e) => {{
            if (e.key === '/' && document.activeElement !== searchInput) {{
                e.preventDefault();
                searchInput.focus();
            }} else if (e.key === 'Escape') {{
                clearSearch();
                closeImageLightbox();
                const drawer = document.getElementById('flowDrawerPanel');
                if (!drawer.classList.contains('translate-x-full')) toggleFlowDrawer();
            }} else if (currentView === 'viewer') {{
                if (e.key === 'ArrowLeft') {{
                    navigateStep(-1);
                }} else if (e.key === 'ArrowRight') {{
                    navigateStep(1);
                }}
            }}
        }});

        // Initialize App
        function initApp() {{
            renderFlows();
            renderAllScreensGrid(ALL_SCREENS);

            const hash = window.location.hash;
            if (hash) {{
                const params = new URLSearchParams(hash.replace('#', ''));
                const view = params.get('view') || 'flow';
                const flow = params.get('flow');
                const step = parseInt(params.get('step') || '1', 10) - 1;

                if (view === 'viewer' && flow) {{
                    currentFlowId = flow;
                    currentStepIndex = step >= 0 ? step : 0;
                    switchView('viewer');
                    loadViewerStep();
                    return;
                }} else if (view === 'grid') {{
                    switchView('grid');
                    return;
                }}
            }}

            switchView('flow');
        }}

        initApp();
    </script>
</body>
</html>
'''

with open('index.html', 'w', encoding='utf-8') as f_out:
    f_out.write(html_template)

print('Successfully generated clean index.html with Visual Wireframe Blueprints!')
