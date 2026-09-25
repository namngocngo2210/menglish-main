<?php

$screensJson = file_get_contents(__DIR__ . '/screens_meta.json');
$screens = json_decode($screensJson, true);

$allSeeds = [];

function makeSeed($screenKey, $module, $index, $codeSuffix, $title, $status, $extraData = []) {
    $code = "SEED-" . strtoupper($codeSuffix) . "-" . sprintf('%02d', $index);
    $fullTitle = "[SEED] " . $title;
    
    $baseData = [
        'id' => $code,
        'code' => $code,
        'is_seed' => true,
        'seed_tag' => '[DỮ LIỆU MẪU - SEED]',
        'seed_badge' => 'DỮ LIỆU SEED',
        'loai_du_lieu' => 'Dữ liệu mẫu khởi tạo',
        'title' => $fullTitle,
        'status' => $status,
        'created_at' => date('Y-m-d H:i:s', strtotime('-' . (60 - $index * 5) . ' days')),
    ];

    return [
        'screen_key' => $screenKey,
        'module' => $module,
        'record_code' => $code,
        'title' => $fullTitle,
        'status' => $status,
        'is_seed' => true,
        'data' => array_merge($baseData, $extraData),
    ];
}

foreach ($screens as $s) {
    $cat = $s['category_id'];
    $folder = $s['folder_name'];
    $screenKey = "{$cat}/{$folder}";
    $titleVn = $s['title_vn'];
    
    $module = 'academic';
    if ($cat === '01_Web_Admin') $module = 'web_admin';
    elseif ($cat === '02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu') $module = 'operations';
    elseif ($cat === '03_Cong_Giao_Vien') $module = 'teacher_portal';
    elseif ($cat === '04_Cong_Phu_Huynh_Hoc_Sinh') $module = 'parent_portal';

    $records = [];
    $shortCode = substr(preg_replace('/[^a-zA-Z0-9]/', '', $folder), 0, 5);

    switch ($folder) {
        // === 01_Web_Admin ===
        case '01_quan_ly_tai_lieu_giao_trinh':
            $items = [
                ['Giáo trình IELTS Writing Task 2 Masterclass 2026', 'active', ['loai' => 'PDF', 'so_trang' => 120, 'cap_do' => 'IELTS 6.5 - 8.0', 'tac_gia' => 'Học thuật MEnglish']],
                ['Bộ đề Speaking Forecast Quý 3/2026 kèm Audio chuẩn UK', 'active', ['loai' => 'PDF + MP3', 'so_trang' => 64, 'cap_do' => 'Mọi cấp độ', 'tac_gia' => 'Ban Chuyên môn']],
                ['Flashcard 3000 Từ vựng Oxford Smart Vocab', 'active', ['loai' => 'Quizlet/Anki', 'so_trang' => 3000, 'cap_do' => 'B1 - B2', 'tac_gia' => 'Học vụ']],
                ['Sổ tay Ngữ pháp Ứng dụng & Cấu trúc Viết học thuật', 'active', ['loai' => 'DOCX', 'so_trang' => 95, 'cap_do' => 'Căn bản - Trung cấp', 'tac_gia' => 'Thầy Hoàng Nam']],
                ['Cambridge IELTS 19 Academic - Đề và Giải thích chi tiết', 'active', ['loai' => 'PDF', 'so_trang' => 160, 'cap_do' => 'Luyện đề thực chiến', 'tac_gia' => 'Academic Team']],
            ];
            break;

        case '02_soan_syllabus_theo_chang':
            $items = [
                ['Chặng 1: Xây dựng Nền tảng Phát âm IPA & Phản xạ từ vựng', 'active', ['so_buoi' => 12, 'thoi_luong' => '6 tuần', 'dau_ra' => 'IPA Chuẩn 44 âm']],
                ['Chặng 2: Giao tiếp Chủ đề Đời sống & Đàm thoại hàng ngày', 'active', ['so_buoi' => 12, 'thoi_luong' => '6 tuần', 'dau_ra' => 'Tự tin giao tiếp A2+']],
                ['Chặng 3: Chiến thuật Đọc hiểu Skimming/Scanning & Viết đoạn văn', 'active', ['so_buoi' => 12, 'thoi_luong' => '6 tuần', 'dau_ra' => 'B1 Preliminary']],
                ['Chặng 4: Luyện đề IELTS 4 Kỹ năng & Thuyết trình chuyên sâu', 'active', ['so_buoi' => 12, 'thoi_luong' => '6 tuần', 'dau_ra' => 'IELTS 5.5 - 6.5']],
                ['Chặng 5: Luyện đề Thực chiến, Mock Test & Tối ưu Band điểm', 'active', ['so_buoi' => 12, 'thoi_luong' => '6 tuần', 'dau_ra' => 'IELTS 7.0+']],
            ];
            break;

        case '03_giao_chang_cho_giao_vien':
            $items = [
                ['Giao Chặng Foundation A1 cho GV Nguyễn Hoàng Nam (Lớp ME-F01)', 'approved', ['giao_vien' => 'Nguyễn Hoàng Nam', 'lop' => 'ME-F01', 'so_hoc_vien' => 16]],
                ['Giao Chặng Communication B1 cho GV Trần Thị Mai Anh (Lớp ME-C02)', 'approved', ['giao_vien' => 'Trần Thị Mai Anh', 'lop' => 'ME-C02', 'so_hoc_vien' => 14]],
                ['Giao Chặng IELTS Target 6.5 cho GV Lê Đình Huy (Lớp ME-I03)', 'approved', ['giao_vien' => 'Lê Đình Huy', 'lop' => 'ME-I03', 'so_hoc_vien' => 18]],
                ['Giao Chặng Business English cho GV Phạm Thu Hà (Lớp ME-B04)', 'approved', ['giao_vien' => 'Phạm Thu Hà', 'lop' => 'ME-B04', 'so_hoc_vien' => 12]],
                ['Giao Chặng Pronunciation Pro cho GV Vũ Đức Anh (Lớp ME-P05)', 'approved', ['giao_vien' => 'Vũ Đức Anh', 'lop' => 'ME-P05', 'so_hoc_vien' => 15]],
            ];
            break;

        case '04_duyet_yeu_cau_dieu_chinh_tien_do':
            $items = [
                ['Lớp ME-K102: Xin hoãn 1 buổi để bổ trợ ngữ pháp Thì Hoàn thành', 'pending', ['lop' => 'ME-K102', 'giao_vien' => 'Nguyễn Hoàng Nam', 'ly_do' => 'Học viên cần củng cố thì hiện tại hoàn thành']],
                ['Lớp ME-IELTS08: Xin đẩy nhanh tiến độ Unit 4 sớm 2 ngày', 'approved', ['lop' => 'ME-IELTS08', 'giao_vien' => 'Lê Đình Huy', 'ly_do' => 'Lớp tiếp thu nhanh, hoàn thành sớm']],
                ['Lớp ME-TEEN05: Đề xuất chuyển lịch ôn tập sang thứ Bảy 19:00', 'approved', ['lop' => 'ME-TEEN05', 'giao_vien' => 'Phạm Thu Hà', 'ly_do' => 'Trùng lịch thi học kỳ ở trường cấp 3']],
                ['Lớp ME-COMM02: Bổ sung 1 buổi workshop phản xạ Speaking 1-1', 'pending', ['lop' => 'ME-COMM02', 'giao_vien' => 'Trần Thị Mai Anh', 'ly_do' => 'Tăng tương tác phát âm trước chặng 2']],
                ['Lớp ME-K105: Gộp 2 bài review ngữ pháp vào 1 buổi thực hành nhóm', 'rejected', ['lop' => 'ME-K105', 'giao_vien' => 'Vũ Đức Anh', 'ly_do' => 'Không đảm bảo thời lượng thực hành chuẩn']],
            ];
            break;

        case '05_chi_tiet_de_xuat_sua_giao_trinh':
            $items = [
                ['Cập nhật số liệu biểu đồ Reading Passage 2 năm 2026 - Unit 4', 'approved', ['mon' => 'Reading', 'unit' => 'Unit 4', 'nguoi_de_xuat' => 'Lê Đình Huy']],
                ['Thay thế đoạn Audio Track 14 bằng giọng chuẩn Anh - Mỹ mới', 'approved', ['mon' => 'Listening', 'unit' => 'Unit 6', 'nguoi_de_xuat' => 'Trần Thị Mai Anh']],
                ['Thêm 15 câu trắc nghiệm Phrasal Verbs vào phần Quiz cuối bài', 'pending', ['mon' => 'Vocabulary', 'unit' => 'Unit 2', 'nguoi_de_xuat' => 'Nguyễn Hoàng Nam']],
                ['Đính kèm bảng Mindmap tóm tắt ngữ pháp Thì quá khứ tiếp diễn', 'approved', ['mon' => 'Grammar', 'unit' => 'Unit 8', 'nguoi_de_xuat' => 'Phạm Thu Hà']],
                ['Sửa lỗi chính tả bài tập điền từ trang 45 sách Bài tập', 'in_review', ['mon' => 'Workbook', 'unit' => 'Unit 5', 'nguoi_de_xuat' => 'Vũ Đức Anh']],
            ];
            break;

        case '06_duyet_phan_phoi_de_big_test':
            $items = [
                ['Phân phối đề Big Test 1 - Khối Pre-IELTS (45 học viên)', 'approved', ['khoi' => 'Pre-IELTS', 'so_luong' => 45, 'thoi_gian_lam_bai' => '90 phút', 'hinh_thuc' => 'Online + Offline']],
                ['Phân phối đề Big Test Giữa kỳ - Khối Giao tiếp B1 (32 học viên)', 'approved', ['khoi' => 'Communication B1', 'so_luong' => 32, 'thoi_gian_lam_bai' => '60 phút', 'hinh_thuc' => 'Vấn đáp 1-1']],
                ['Phân phối đề Big Test Cuối chặng 3 - Khối Target 6.5 (28 học viên)', 'approved', ['khoi' => 'IELTS 6.5+', 'so_luong' => 28, 'thoi_gian_lam_bai' => '120 phút', 'hinh_thuc' => 'Full Mock']],
                ['Phân phối đề Đánh giá năng lực Kids Starters (18 học viên)', 'approved', ['khoi' => 'Kids Starters', 'so_luong' => 18, 'thoi_gian_lam_bai' => '45 phút', 'hinh_thuc' => 'Trắc nghiệm tương tác']],
                ['Phân phối đề Mock Test Tháng 9 Chuẩn Cambridge (50 học viên)', 'approved', ['khoi' => 'Toàn trung tâm', 'so_luong' => 50, 'thoi_gian_lam_bai' => '150 phút', 'hinh_thuc' => 'Thi phòng máy Lab']],
            ];
            break;

        case '07_duyet_ket_qua_big_test_gui_phu_huynh':
            $items = [
                ['Duyệt gửi kết quả Big Test học sinh Nguyễn Gia Huy (Điểm: 8.5/10 - Giỏi)', 'approved', ['hoc_sinh' => 'Nguyễn Gia Huy', 'lop' => 'ME-I01', 'diem' => '8.5/10', 'xep_loai' => 'Giỏi']],
                ['Duyệt gửi kết quả Big Test học sinh Trần Bảo Ngọc (Điểm: 9.2/10 - Xuất sắc)', 'approved', ['hoc_sinh' => 'Trần Bảo Ngọc', 'lop' => 'ME-I02', 'diem' => '9.2/10', 'xep_loai' => 'Xuất sắc']],
                ['Duyệt gửi kết quả Big Test học sinh Lê Tuấn Kiệt (Điểm: 6.8/10 - Đạt)', 'approved', ['hoc_sinh' => 'Lê Tuấn Kiệt', 'lop' => 'ME-K08', 'diem' => '6.8/10', 'xep_loai' => 'Đạt']],
                ['Duyệt gửi kết quả Big Test học sinh Phạm Minh Anh (Điểm: 8.0/10 - Giỏi)', 'approved', ['hoc_sinh' => 'Phạm Minh Anh', 'lop' => 'ME-C04', 'diem' => '8.0/10', 'xep_loai' => 'Giỏi']],
                ['Duyệt gửi kết quả Big Test học sinh Hoàng Đăng Khoa (Điểm: 7.5/10 - Khá)', 'approved', ['hoc_sinh' => 'Hoàng Đăng Khoa', 'lop' => 'ME-T03', 'diem' => '7.5/10', 'xep_loai' => 'Khá']],
            ];
            break;

        case '08_nhac_lich_big_test':
            $items = [
                ['Nhắc lịch thi Big Test Lớp ME-IELTS-K20 (16/09/2026 - 18:30)', 'active', ['lop' => 'ME-IELTS-K20', 'ngay_thi' => '2026-09-16 18:30', 'phong' => 'Lab 1', 'giao_thi' => 'Nguyễn Hoàng Nam']],
                ['Nhắc lịch thi Giữa kỳ Lớp ME-COMM-K12 (18/09/2026 - 19:45)', 'active', ['lop' => 'ME-COMM-K12', 'ngay_thi' => '2026-09-18 19:45', 'phong' => 'Room 202', 'giao_thi' => 'Trần Thị Mai Anh']],
                ['Nhắc thi bù Speaking cho 3 học sinh vắng (20/09/2026 - 17:00)', 'active', ['lop' => 'ME-ALL', 'ngay_thi' => '2026-09-20 17:00', 'phong' => 'Room 101', 'giao_thi' => 'Lê Đình Huy']],
                ['Nhắc gửi thông báo qua Zalo/SMS tới phụ huynh Lớp ME-KIDS-K05', 'active', ['lop' => 'ME-KIDS-K05', 'ngay_thi' => '2026-09-22 09:00', 'phong' => 'Room 104', 'giao_thi' => 'Phạm Thu Hà']],
                ['Nhắc in đề thi và kiểm tra tai nghe phòng Lab 2 (25/09/2026)', 'active', ['lop' => 'ME-I04', 'ngay_thi' => '2026-09-25 18:00', 'phong' => 'Lab 2', 'giao_thi' => 'Vũ Đức Anh']],
            ];
            break;

        case '09_cham_cong_theo_lich':
            $items = [
                ['Chấm công GV Nguyễn Hoàng Nam - Buổi 14 ME-K102 (Đúng giờ)', 'approved', ['giao_vien' => 'Nguyễn Hoàng Nam', 'lop' => 'ME-K102', 'thoi_gian' => '18:00 - 20:00', 'tinh_trang' => 'Đúng giờ']],
                ['Chấm công GV Trần Thị Mai Anh - Buổi 10 ME-COMM01 (Đúng giờ)', 'approved', ['giao_vien' => 'Trần Thị Mai Anh', 'lop' => 'ME-COMM01', 'thoi_gian' => '19:30 - 21:30', 'tinh_trang' => 'Đúng giờ']],
                ['Chấm công GV Lê Đình Huy - Buổi 22 ME-IELTS03 (Đúng giờ)', 'approved', ['giao_vien' => 'Lê Đình Huy', 'lop' => 'ME-IELTS03', 'thoi_gian' => '17:45 - 19:45', 'tinh_trang' => 'Đúng giờ']],
                ['Chấm công GV Phạm Thu Hà - Buổi 6 ME-TEEN02 (Đúng giờ)', 'approved', ['giao_vien' => 'Phạm Thu Hà', 'lop' => 'ME-TEEN02', 'thoi_gian' => '08:30 - 10:30', 'tinh_trang' => 'Đúng giờ']],
                ['Chấm công GV Vũ Đức Anh - Buổi 18 ME-K104 (Vào trễ 5 phút)', 'approved', ['giao_vien' => 'Vũ Đức Anh', 'lop' => 'ME-K104', 'thoi_gian' => '18:05 - 20:00', 'tinh_trang' => 'Trễ 5p']],
            ];
            break;

        case '10_doi_soat_chot_bang_cong':
            $items = [
                ['Bảng công Tháng 08/2026 - GV Nguyễn Hoàng Nam (48 giờ dạy)', 'approved', ['giao_vien' => 'Nguyễn Hoàng Nam', 'so_gio' => 48, 'he_so' => 1.2, 'thanh_tien' => '14,400,000 VNĐ']],
                ['Bảng công Tháng 08/2026 - GV Trần Thị Mai Anh (54 giờ dạy)', 'approved', ['giao_vien' => 'Trần Thị Mai Anh', 'so_gio' => 54, 'he_so' => 1.2, 'thanh_tien' => '16,200,000 VNĐ']],
                ['Bảng công Tháng 08/2026 - GV Lê Đình Huy (42 giờ dạy)', 'approved', ['giao_vien' => 'Lê Đình Huy', 'so_gio' => 42, 'he_so' => 1.3, 'thanh_tien' => '16,380,000 VNĐ']],
                ['Bảng công Tháng 08/2026 - GV Phạm Thu Hà (40 giờ dạy)', 'approved', ['giao_vien' => 'Phạm Thu Hà', 'so_gio' => 40, 'he_so' => 1.1, 'thanh_tien' => '11,000,000 VNĐ']],
                ['Bảng công Tháng 08/2026 - Trợ giảng Đỗ Quỳnh Nga (60 giờ)', 'approved', ['giao_vien' => 'Đỗ Quỳnh Nga (TA)', 'so_gio' => 60, 'he_so' => 1.0, 'thanh_tien' => '6,000,000 VNĐ']],
            ];
            break;

        case '11_danh_sach_buoi_day_thay_cho_xac_nhan':
            $items = [
                ['GV Mai Anh dạy thay GV Hoàng Nam (Lớp ME-K102, Buổi 15 - 18/09)', 'approved', ['gv_nho' => 'Nguyễn Hoàng Nam', 'gv_day' => 'Trần Thị Mai Anh', 'lop' => 'ME-K102', 'ly_do' => 'Ốm sốt']],
                ['GV Đình Huy dạy thay GV Thu Hà (Lớp ME-TEEN01, Buổi 8 - 20/09)', 'approved', ['gv_nho' => 'Phạm Thu Hà', 'gv_day' => 'Lê Đình Huy', 'lop' => 'ME-TEEN01', 'ly_do' => 'Công tác']],
                ['GV Đức Anh dạy thay GV Đình Huy (Lớp ME-IELTS02, Buổi 11 - 22/09)', 'pending', ['gv_nho' => 'Lê Đình Huy', 'gv_day' => 'Vũ Đức Anh', 'lop' => 'ME-IELTS02', 'ly_do' => 'Trùng lịch']],
                ['GV Hoàng Nam dạy thay GV Mai Anh (Lớp ME-COMM03, Buổi 13 - 24/09)', 'approved', ['gv_nho' => 'Trần Thị Mai Anh', 'gv_day' => 'Nguyễn Hoàng Nam', 'lop' => 'ME-COMM03', 'ly_do' => 'Gia đình']],
                ['GV Thu Hà dạy thay GV Đức Anh (Lớp ME-K104, Buổi 19 - 26/09)', 'pending', ['gv_nho' => 'Vũ Đức Anh', 'gv_day' => 'Phạm Thu Hà', 'lop' => 'ME-K104', 'ly_do' => 'Học cao học']],
            ];
            break;

        case '12_dat_lich_hoc_thu_popup':
            $items = [
                ['Khách học thử: Trần Văn Bình (SĐT: 0912345678 - Trình độ Mất gốc)', 'approved', ['ten_khach' => 'Trần Văn Bình', 'sdt' => '0912345678', 'muc_tieu' => 'Mất gốc lấy lại căn bản', 'ca' => 'Tối T2 18:30']],
                ['Khách học thử: Lê Phương Linh (SĐT: 0987654321 - Giao tiếp B1)', 'approved', ['ten_khach' => 'Lê Phương Linh', 'sdt' => '0987654321', 'muc_tieu' => 'Giao tiếp công sở', 'ca' => 'Tối T4 19:45']],
                ['Khách học thử: Đặng Hữu Quân (SĐT: 0933112233 - IELTS Target 6.5)', 'approved', ['ten_khach' => 'Đặng Hữu Quân', 'sdt' => '0933112233', 'muc_tieu' => 'IELTS du học', 'ca' => 'Tối T6 18:00']],
                ['Khách học thử: Nguyễn Thùy Dương (SĐT: 0944556677 - Lớp Tiếng Anh Teen)', 'approved', ['ten_khach' => 'Nguyễn Thùy Dương', 'sdt' => '0944556677', 'muc_tieu' => 'Bổ trợ điểm thi lớp 10', 'ca' => 'Sáng CN 09:00']],
                ['Khách học thử: Vũ Quang Minh (SĐT: 0966778899 - Luyện phát âm IPA)', 'pending', ['ten_khach' => 'Vũ Quang Minh', 'sdt' => '0966778899', 'muc_tieu' => 'Sửa ngọng L/N và phát âm', 'ca' => 'Chiều T7 15:00']],
            ];
            break;

        case '13_tao_lop_moi':
            $items = [
                ['Tạo lớp: ME-IELTS-K26 (Phòng 301, Khai giảng 25/09/2026)', 'active', ['ten_lop' => 'ME-IELTS-K26', 'phong' => 'P.301', 'gv_chinh' => 'Lê Đình Huy', 'si_so' => 16, 'hoc_phi' => '6,800,000 VNĐ']],
                ['Tạo lớp: ME-COMM-K15 (Phòng 202, Khai giảng 28/09/2026)', 'active', ['ten_lop' => 'ME-COMM-K15', 'phong' => 'P.202', 'gv_chinh' => 'Trần Thị Mai Anh', 'si_so' => 14, 'hoc_phi' => '5,200,000 VNĐ']],
                ['Tạo lớp: ME-KIDS-K08 (Phòng 104, Khai giảng 30/09/2026)', 'active', ['ten_lop' => 'ME-KIDS-K08', 'phong' => 'P.104', 'gv_chinh' => 'Phạm Thu Hà', 'si_so' => 12, 'hoc_phi' => '4,500,000 VNĐ']],
                ['Tạo lớp: ME-FOUND-K40 (Phòng 205, Khai giảng 02/10/2026)', 'active', ['ten_lop' => 'ME-FOUND-K40', 'phong' => 'P.205', 'gv_chinh' => 'Nguyễn Hoàng Nam', 'si_so' => 18, 'hoc_phi' => '4,800,000 VNĐ']],
                ['Tạo lớp: ME-ADV-K12 (Phòng Online Zoom, Khai giảng 05/10/2026)', 'active', ['ten_lop' => 'ME-ADV-K12', 'phong' => 'Zoom 01', 'gv_chinh' => 'Vũ Đức Anh', 'si_so' => 15, 'hoc_phi' => '5,900,000 VNĐ']],
            ];
            break;

        case '14_ho_so_lop_hoc':
            $items = [
                ['Hồ sơ lớp ME-IELTS-K20: Sĩ số 16, Chuyên cần 96%, Tiến độ 15/30 buổi', 'active', ['lop' => 'ME-IELTS-K20', 'si_so' => 16, 'chuyen_can' => '96%', 'tien_do' => '15/30 buổi', 'gv' => 'Lê Đình Huy']],
                ['Hồ sơ lớp ME-COMM-K12: Sĩ số 14, Chuyên cần 92%, Tiến độ 18/24 buổi', 'active', ['lop' => 'ME-COMM-K12', 'si_so' => 14, 'chuyen_can' => '92%', 'tien_do' => '18/24 buổi', 'gv' => 'Trần Thị Mai Anh']],
                ['Hồ sơ lớp ME-KIDS-K05: Sĩ số 12, Chuyên cần 98%, Tiến độ 09/16 buổi', 'active', ['lop' => 'ME-KIDS-K05', 'si_so' => 12, 'chuyen_can' => '98%', 'tien_do' => '09/16 buổi', 'gv' => 'Phạm Thu Hà']],
                ['Hồ sơ lớp ME-FOUND-K32: Sĩ số 18, Chuyên cần 94%, Tiến độ 22/36 buổi', 'active', ['lop' => 'ME-FOUND-K32', 'si_so' => 18, 'chuyen_can' => '94%', 'tien_do' => '22/36 buổi', 'gv' => 'Nguyễn Hoàng Nam']],
                ['Hồ sơ lớp ME-TEEN-K09: Sĩ số 15, Chuyên cần 89%, Tiến độ 12/20 buổi', 'active', ['lop' => 'ME-TEEN-K09', 'si_so' => 15, 'chuyen_can' => '89%', 'tien_do' => '12/20 buổi', 'gv' => 'Vũ Đức Anh']],
            ];
            break;

        // === 02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu ===
        case '01_cau_hinh_kpi_hoc_vu_1':
            $items = [
                ['Tỷ lệ chuyên cần học viên lớp phụ trách (Mục tiêu: >= 92%)', 'active', ['trong_so' => '25%', 'chi_tieu' => '92%', 'don_vi' => '%', 'loai' => 'Chuyên cần']],
                ['Tỷ lệ chăm sóc và liên hệ phụ huynh hàng tháng (Mục tiêu: 100%)', 'active', ['trong_so' => '20%', 'chi_tieu' => '100%', 'don_vi' => '%', 'loai' => 'Chăm sóc']],
                ['Tỷ lệ thu học phí đúng hạn theo SLA (Mục tiêu: >= 95%)', 'active', ['trong_so' => '20%', 'chi_tieu' => '95%', 'don_vi' => '%', 'loai' => 'Tài chính']],
                ['Điểm hài lòng Feedback phụ huynh & học viên (Mục tiêu: >= 4.5/5)', 'active', ['trong_so' => '20%', 'chi_tieu' => '4.5/5', 'don_vi' => 'Điểm', 'loai' => 'Đánh giá']],
                ['Nộp báo cáo tuần và nhật ký học vụ đúng hạn (Mục tiêu: 100%)', 'active', ['trong_so' => '15%', 'chi_tieu' => '100%', 'don_vi' => '%', 'loai' => 'Vận hành']],
            ];
            break;

        case '02_cau_hinh_kpi_hoc_vu_2':
            $items = [
                ['Tỷ lệ học sinh tái tục khóa học tiếp theo (Mục tiêu: >= 80%)', 'active', ['trong_so' => '30%', 'chi_tieu' => '80%', 'don_vi' => '%', 'nhom' => 'Tái tục']],
                ['Tỷ lệ học sinh đạt điểm chuẩn Big Test (Mục tiêu: >= 85%)', 'active', ['trong_so' => '25%', 'chi_tieu' => '85%', 'don_vi' => '%', 'nhom' => 'Chất lượng']],
                ['Thời gian xử lý khiếu nại phụ huynh trong 24 giờ (Mục tiêu: 100%)', 'active', ['trong_so' => '15%', 'chi_tieu' => '24h', 'don_vi' => 'Giờ', 'nhom' => 'SLA']],
                ['Tỷ lệ học sinh hoàn thành bài tập về nhà (Mục tiêu: >= 90%)', 'active', ['trong_so' => '15%', 'chi_tieu' => '90%', 'don_vi' => '%', 'nhom' => 'Học tập']],
                ['Tham gia đầy đủ các buổi đào tạo nội bộ học thuật (Mục tiêu: 100%)', 'active', ['trong_so' => '15%', 'chi_tieu' => '100%', 'don_vi' => '%', 'nhom' => 'Phát triển']],
            ];
            break;

        case '03_tong_hop_kpi_danh_gia_thang':
            $items = [
                ['Tổng hợp KPI Tháng 08/2026 - CV Nguyễn Thị Mai (Điểm: 96.5 - Xuất sắc)', 'approved', ['nhan_su' => 'Nguyễn Thị Mai', 'vi_tri' => 'Học vụ', 'diem' => 96.5, 'xep_loai' => 'Xuất sắc', 'thuong' => '1,500,000 VNĐ']],
                ['Tổng hợp KPI Tháng 08/2026 - CV Lê Thảo Ly (Điểm: 94.0 - Giỏi)', 'approved', ['nhan_su' => 'Lê Thảo Ly', 'vi_tri' => 'Học vụ', 'diem' => 94.0, 'xep_loai' => 'Giỏi', 'thuong' => '1,000,000 VNĐ']],
                ['Tổng hợp KPI Tháng 08/2026 - CV Trần Anh Tuấn (Điểm: 89.5 - Khá)', 'approved', ['nhan_su' => 'Trần Anh Tuấn', 'vi_tri' => 'Học vụ', 'diem' => 89.5, 'xep_loai' => 'Khá', 'thuong' => '500,000 VNĐ']],
                ['Tổng hợp KPI Tháng 08/2026 - CV Phạm Ngọc Hoa (Điểm: 95.0 - Giỏi)', 'approved', ['nhan_su' => 'Phạm Ngọc Hoa', 'vi_tri' => 'Học vụ', 'diem' => 95.0, 'xep_loai' => 'Giỏi', 'thuong' => '1,000,000 VNĐ']],
                ['Tổng hợp KPI Tháng 08/2026 - CV Đỗ Minh Trí (Điểm: 88.0 - Khá)', 'approved', ['nhan_su' => 'Đỗ Minh Trí', 'vi_tri' => 'Học vụ', 'diem' => 88.0, 'xep_loai' => 'Khá', 'thuong' => '500,000 VNĐ']],
            ];
            break;

        case '04_kpi_thang':
            $items = [
                ['Báo cáo KPI Tháng: Tỷ lệ chuyên cần trung bình toàn chi nhánh 95.4%', 'active', ['chi_so' => 'Chuyên cần', 'thuc_dat' => '95.4%', 'muc_tieu' => '92%', 'danh_gia' => 'Vượt chỉ tiêu']],
                ['Báo cáo KPI Tháng: Tỷ lệ chăm sóc phụ huynh định kỳ 99.2%', 'active', ['chi_so' => 'Chăm sóc', 'thuc_dat' => '99.2%', 'muc_tieu' => '100%', 'danh_gia' => 'Đạt chuẩn']],
                ['Báo cáo KPI Tháng: Tỷ lệ thu hồi học phí đúng hạn 97.1%', 'active', ['chi_so' => 'Thu học phí', 'thuc_dat' => '97.1%', 'muc_tieu' => '95%', 'danh_gia' => 'Vượt chỉ tiêu']],
                ['Báo cáo KPI Tháng: Điểm số hài lòng học viên đạt 4.82/5', 'active', ['chi_so' => 'CSAT', 'thuc_dat' => '4.82/5', 'muc_tieu' => '4.5/5', 'danh_gia' => 'Xuất sắc']],
                ['Báo cáo KPI Tháng: Tỷ lệ tái tục khóa mới đạt 82.5%', 'active', ['chi_so' => 'Tái tục', 'thuc_dat' => '82.5%', 'muc_tieu' => '80%', 'danh_gia' => 'Đạt chuẩn']],
            ];
            break;

        case '05_nhat_ky_hoc_vu':
            $items = [
                ['Hỗ trợ bù bài học cho HS Nguyễn Gia Huy do nghỉ ốm buổi 14', 'active', ['hoc_sinh' => 'Nguyễn Gia Huy', 'lop' => 'ME-K102', 'noi_dung' => 'Gửi slide bài giảng và hẹn GV phụ đạo 30p']],
                ['Xử lý phản ánh phụ huynh về điều hòa phòng 202 quá lạnh', 'active', ['phong' => 'Room 202', 'lop' => 'ME-COMM01', 'noi_dung' => 'Đã điều chỉnh nhiệt độ 26 độ C và dặn TA lưu ý']],
                ['Cấp phát bổ sung giáo trình IELTS Speaking cho 2 bạn nhập học muộn', 'active', ['lop' => 'ME-IELTS03', 'so_luong' => 2, 'noi_dung' => 'Đã bàn giao sách và hướng dẫn vào group Zalo']],
                ['Hỗ trợ chuyển lớp từ ca T2-T4 sang ca T3-T5 cho HS Lê Tuấn Kiệt', 'active', ['hoc_sinh' => 'Lê Tuấn Kiệt', 'lop_cu' => 'ME-K102', 'lop_moi' => 'ME-K105', 'noi_dung' => 'Đã hoàn tất thủ tục']],
                ['Gửi thông báo lịch nghỉ lễ Quốc khánh tới 120 phụ huynh các lớp', 'active', ['nhom' => 'Toàn chi nhánh', 'so_luong' => 120, 'noi_dung' => 'Gửi SMS Brandname và post lên App']],
            ];
            break;

        case '06_bao_cao_ngay_hoc_vu':
            $items = [
                ['Báo cáo ngày 08/09: 8 ca học diễn ra thuận lợi, sĩ số 108/112 (96.4%)', 'active', ['ngay' => '2026-09-08', 'tong_ca' => 8, 'si_so' => '108/112', 'ti_le' => '96.4%', 'su_vu' => 0]],
                ['Báo cáo ngày 07/09: 6 ca học diễn ra, 1 sự vụ điều hòa đã xử lý', 'active', ['ngay' => '2026-09-07', 'tong_ca' => 6, 'si_so' => '82/86', 'ti_le' => '95.3%', 'su_vu' => 1]],
                ['Báo cáo ngày 06/09: 10 ca học cuối tuần, khai giảng 1 lớp mới ME-KIDS', 'active', ['ngay' => '2026-09-06', 'tong_ca' => 10, 'si_so' => '135/140', 'ti_le' => '96.4%', 'su_vu' => 0]],
                ['Báo cáo ngày 05/09: 8 ca học, tổ chức thi Big Test cho 2 lớp IELTS', 'active', ['ngay' => '2026-09-05', 'tong_ca' => 8, 'si_so' => '110/115', 'ti_le' => '95.6%', 'su_vu' => 0]],
                ['Báo cáo ngày 04/09: 7 ca học diễn ra đúng tiến độ, điểm danh 100%', 'active', ['ngay' => '2026-09-04', 'tong_ca' => 7, 'si_so' => '98/102', 'ti_le' => '96.0%', 'su_vu' => 0]],
            ];
            break;

        case '07_nhap_bao_cao_tuan_hoc_vu':
            $items = [
                ['Báo cáo tuần 36/2026: Đánh giá chất lượng vận hành và tỷ lệ chuyên cần', 'approved', ['tuan' => 'Tuần 36', 'thoi_gian' => '01/09 - 07/09', 'nguoi_lap' => 'Nguyễn Thị Mai', 'ti_le_chuyen_can' => '96.2%']],
                ['Báo cáo tuần 35/2026: Hoàn tất thu học phí đợt cuối tháng 8', 'approved', ['tuan' => 'Tuần 35', 'thoi_gian' => '25/08 - 31/08', 'nguoi_lap' => 'Lê Thảo Ly', 'ti_le_chuyen_can' => '95.8%']],
                ['Báo cáo tuần 34/2026: Tổ chức thành công kỳ thi Mock Test Cambridge', 'approved', ['tuan' => 'Tuần 34', 'thoi_gian' => '18/08 - 24/08', 'nguoi_lap' => 'Trần Anh Tuấn', 'ti_le_chuyen_can' => '97.0%']],
                ['Báo cáo tuần 33/2026: Đổi lịch học và giải quyết nghỉ phép giáo viên', 'approved', ['tuan' => 'Tuần 33', 'thoi_gian' => '11/08 - 17/08', 'nguoi_lap' => 'Phạm Ngọc Hoa', 'ti_le_chuyen_can' => '94.5%']],
                ['Báo cáo tuần 32/2026: Tổng kết khai giảng 3 lớp học hè chất lượng cao', 'approved', ['tuan' => 'Tuần 32', 'thoi_gian' => '04/08 - 10/08', 'nguoi_lap' => 'Đỗ Minh Trí', 'ti_le_chuyen_can' => '98.1%']],
            ];
            break;

        case '08_qa_observation_du_gio_van_hanh':
            $items = [
                ['QA Dự giờ: Lớp ME-IELTS02 - GV Hoàng Nam (Điểm: 94/100 - Rất tốt)', 'approved', ['lop' => 'ME-IELTS02', 'giao_vien' => 'Nguyễn Hoàng Nam', 'nguoi_du_gio' => 'QA Lead', 'diem' => '94/100', 'nhan_xet' => 'Tương tác tốt, quản lý lớp chặt chẽ']],
                ['QA Dự giờ: Lớp ME-COMM01 - GV Mai Anh (Điểm: 96/100 - Xuất sắc)', 'approved', ['lop' => 'ME-COMM01', 'giao_vien' => 'Trần Thị Mai Anh', 'nguoi_du_gio' => 'QA Lead', 'diem' => '96/100', 'nhan_xet' => 'Năng lượng cao, sửa phát âm tỉ mỉ']],
                ['QA Dự giờ: Lớp ME-KIDS03 - GV Thu Hà (Điểm: 90/100 - Tốt)', 'approved', ['lop' => 'ME-KIDS03', 'giao_vien' => 'Phạm Thu Hà', 'nguoi_du_gio' => 'QA Specialist', 'diem' => '90/100', 'nhan_xet' => 'Trò chơi hấp dẫn, các bé tập trung']],
                ['QA Dự giờ: Lớp ME-TEEN02 - GV Đình Huy (Điểm: 92/100 - Rất tốt)', 'approved', ['lop' => 'ME-TEEN02', 'giao_vien' => 'Lê Đình Huy', 'nguoi_du_gio' => 'QA Specialist', 'diem' => '92/100', 'nhan_xet' => 'Giải thích ngữ pháp mạch lạc, dễ nhớ']],
                ['QA Dự giờ: Lớp ME-FOUND05 - GV Đức Anh (Điểm: 88/100 - Khá)', 'approved', ['lop' => 'ME-FOUND05', 'giao_vien' => 'Vũ Đức Anh', 'nguoi_du_gio' => 'QA Lead', 'diem' => '88/100', 'nhan_xet' => 'Cần phân bổ thời gian thực hành nhiều hơn']],
            ];
            break;

        case '09_checklist_hoc_phi_feedback_theo_lop':
            $items = [
                ['Checklist ME-IELTS-K20: Đã thu 15/16 học viên (93.7%), Feedback 4.9/5', 'active', ['lop' => 'ME-IELTS-K20', 'da_thu' => '15/16', 'ti_le' => '93.7%', 'feedback' => '4.9/5', 'tinh_trang' => 'Tốt']],
                ['Checklist ME-COMM-K12: Đã thu 13/13 học viên (100%), Feedback 4.8/5', 'active', ['lop' => 'ME-COMM-K12', 'da_thu' => '13/13', 'ti_le' => '100%', 'feedback' => '4.8/5', 'tinh_trang' => 'Hoàn thành']],
                ['Checklist ME-KIDS-K05: Đã thu 11/12 học viên (91.6%), Feedback 4.7/5', 'active', ['lop' => 'ME-KIDS-K05', 'da_thu' => '11/12', 'ti_le' => '91.6%', 'feedback' => '4.7/5', 'tinh_trang' => 'Còn 1 nhắc hẹn']],
                ['Checklist ME-FOUND-K32: Đã thu 17/18 học viên (94.4%), Feedback 4.85/5', 'active', ['lop' => 'ME-FOUND-K32', 'da_thu' => '17/18', 'ti_le' => '94.4%', 'feedback' => '4.85/5', 'tinh_trang' => 'Tốt']],
                ['Checklist ME-TEEN-K09: Đã thu 14/14 học viên (100%), Feedback 4.9/5', 'active', ['lop' => 'ME-TEEN-K09', 'da_thu' => '14/14', 'ti_le' => '100%', 'feedback' => '4.9/5', 'tinh_trang' => 'Hoàn thành']],
            ];
            break;

        case '10_ra_soat_diem_danh_hoc_vu_admin':
            $items = [
                ['Rà soát ca 18:00 Thứ Hai: 4 lớp hoạt động, đủ 4 GV và 4 TA check-in', 'approved', ['ca' => 'Tối T2 18:00', 'so_lop' => 4, 'giao_vien' => '4/4', 'tro_giang' => '4/4', 'tinh_trang' => 'Chuẩn chỉ']],
                ['Rà soát ca 19:45 Thứ Ba: 3 lớp hoạt động, 1 học sinh nghỉ có phép', 'approved', ['ca' => 'Tối T3 19:45', 'so_lop' => 3, 'giao_vien' => '3/3', 'tro_giang' => '3/3', 'tinh_trang' => 'Đã cập nhật']],
                ['Rà soát ca 18:00 Thứ Tư: 5 lớp hoạt động, phát hiện 1 trường hợp vào trễ 5p', 'approved', ['ca' => 'Tối T4 18:00', 'so_lop' => 5, 'giao_vien' => '5/5', 'tro_giang' => '5/5', 'tinh_trang' => 'Đã nhắc nhở']],
                ['Rà soát ca 19:45 Thứ Năm: 4 lớp hoạt động, 100% chuyên cần học viên', 'approved', ['ca' => 'Tối T5 19:45', 'so_lop' => 4, 'giao_vien' => '4/4', 'tro_giang' => '4/4', 'tinh_trang' => 'Xuất sắc']],
                ['Rà soát ca 08:30 Thứ Bảy: 6 lớp thiếu nhi, phụ huynh check-in mã QR đầy đủ', 'approved', ['ca' => 'Sáng T7 08:30', 'so_lop' => 6, 'giao_vien' => '6/6', 'tro_giang' => '6/6', 'tinh_trang' => 'An toàn']],
            ];
            break;

        case '11_chi_tiet_bang_luong_hoc_vu':
            $items = [
                ['Bảng lương Tháng 08/2026 - CV Nguyễn Thị Mai: 14,500,000 VNĐ (KPI 96.5%)', 'approved', ['nhan_su' => 'Nguyễn Thị Mai', 'luong_cung' => '10,000,000', 'thuong_kpi' => '4,500,000', 'tong_nhan' => '14,500,000 VNĐ']],
                ['Bảng lương Tháng 08/2026 - CV Lê Thảo Ly: 15,200,000 VNĐ (KPI 94.0%)', 'approved', ['nhan_su' => 'Lê Thảo Ly', 'luong_cung' => '11,000,000', 'thuong_kpi' => '4,200,000', 'tong_nhan' => '15,200,000 VNĐ']],
                ['Bảng lương Tháng 08/2026 - CV Trần Anh Tuấn: 13,800,000 VNĐ (KPI 89.5%)', 'approved', ['nhan_su' => 'Trần Anh Tuấn', 'luong_cung' => '10,000,000', 'thuong_kpi' => '3,800,000', 'tong_nhan' => '13,800,000 VNĐ']],
                ['Bảng lương Tháng 08/2026 - CV Phạm Ngọc Hoa: 14,900,000 VNĐ (KPI 95.0%)', 'approved', ['nhan_su' => 'Phạm Ngọc Hoa', 'luong_cung' => '10,500,000', 'thuong_kpi' => '4,400,000', 'tong_nhan' => '14,900,000 VNĐ']],
                ['Bảng lương Tháng 08/2026 - CV Đỗ Minh Trí: 13,500,000 VNĐ (KPI 88.0%)', 'approved', ['nhan_su' => 'Đỗ Minh Trí', 'luong_cung' => '10,000,000', 'thuong_kpi' => '3,500,000', 'tong_nhan' => '13,500,000 VNĐ']],
            ];
            break;

        case '12_rollup_thu_hoc_phi_theo_lop':
            $items = [
                ['Rollup ME-IELTS01: Đã thu 128,000,000 / 135,000,000 VNĐ (94.8%)', 'active', ['lop' => 'ME-IELTS01', 'da_thu' => '128,000,000', 'tong' => '135,000,000', 'ti_le' => '94.8%', 'con_lai' => '7,000,000']],
                ['Rollup ME-COMM02: Đã thu 95,000,000 / 95,000,000 VNĐ (100%)', 'active', ['lop' => 'ME-COMM02', 'da_thu' => '95,000,000', 'tong' => '95,000,000', 'ti_le' => '100%', 'con_lai' => '0']],
                ['Rollup ME-KIDS04: Đã thu 82,000,000 / 88,000,000 VNĐ (93.1%)', 'active', ['lop' => 'ME-KIDS04', 'da_thu' => '82,000,000', 'tong' => '88,000,000', 'ti_le' => '93.1%', 'con_lai' => '6,000,000']],
                ['Rollup ME-FOUND06: Đã thu 110,000,000 / 115,000,000 VNĐ (95.6%)', 'active', ['lop' => 'ME-FOUND06', 'da_thu' => '110,000,000', 'tong' => '115,000,000', 'ti_le' => '95.6%', 'con_lai' => '5,000,000']],
                ['Rollup ME-TEEN01: Đã thu 90,000,000 / 90,000,000 VNĐ (100%)', 'active', ['lop' => 'ME-TEEN01', 'da_thu' => '90,000,000', 'tong' => '90,000,000', 'ti_le' => '100%', 'con_lai' => '0']],
            ];
            break;

        case '13_tong_quan_danh_sach_lop_hoc_thuat':
            $items = [
                ['Khối IELTS Foundation: 8 lớp đang chạy, 126 học viên, chuyên cần 96.1%', 'active', ['khoi' => 'IELTS Foundation', 'so_lop' => 8, 'hoc_vien' => 126, 'chuyen_can' => '96.1%']],
                ['Khối Tiếng Anh Giao Tiếp: 6 lớp đang chạy, 88 học viên, chuyên cần 94.3%', 'active', ['khoi' => 'Giao Tiếp Pro', 'so_lop' => 6, 'hoc_vien' => 88, 'chuyen_can' => '94.3%']],
                ['Khối Kids & Junior: 5 lớp đang chạy, 62 học viên, chuyên cần 98.0%', 'active', ['khoi' => 'Kids & Junior', 'so_lop' => 5, 'hoc_vien' => 62, 'chuyen_can' => '98.0%']],
                ['Khối IELTS Intensive 6.5+: 4 lớp đang chạy, 58 học viên, chuyên cần 95.5%', 'active', ['khoi' => 'IELTS 6.5+', 'so_lop' => 4, 'hoc_vien' => 58, 'chuyen_can' => '95.5%']],
                ['Khối Doanh nghiệp (Corporate): 2 lớp đang chạy, 28 học viên, chuyên cần 92.0%', 'active', ['khoi' => 'Corporate B2B', 'so_lop' => 2, 'hoc_vien' => 28, 'chuyen_can' => '92.0%']],
            ];
            break;

        case '14_danh_sach_lop_chi_tiet_hoc_thuat':
            $items = [
                ['Lớp ME-IELTS-A01: GV Lê Đình Huy - Sĩ số 16/16 - Tiến độ Buổi 24/30', 'active', ['ten_lop' => 'ME-IELTS-A01', 'giao_vien' => 'Lê Đình Huy', 'si_so' => '16/16', 'tien_do' => '24/30']],
                ['Lớp ME-COMM-B02: GV Trần Thị Mai Anh - Sĩ số 14/14 - Tiến độ Buổi 18/24', 'active', ['ten_lop' => 'ME-COMM-B02', 'giao_vien' => 'Trần Thị Mai Anh', 'si_so' => '14/14', 'tien_do' => '18/24']],
                ['Lớp ME-KIDS-K01: GV Phạm Thu Hà - Sĩ số 12/12 - Tiến độ Buổi 10/16', 'active', ['ten_lop' => 'ME-KIDS-K01', 'giao_vien' => 'Phạm Thu Hà', 'si_so' => '12/12', 'tien_do' => '10/16']],
                ['Lớp ME-FOUND-F03: GV Nguyễn Hoàng Nam - Sĩ số 18/18 - Tiến độ Buổi 28/36', 'active', ['ten_lop' => 'ME-FOUND-F03', 'giao_vien' => 'Nguyễn Hoàng Nam', 'si_so' => '18/18', 'tien_do' => '28/36']],
                ['Lớp ME-ADV-G04: GV Vũ Đức Anh - Sĩ số 15/15 - Tiến độ Buổi 14/20', 'active', ['ten_lop' => 'ME-ADV-G04', 'giao_vien' => 'Vũ Đức Anh', 'si_so' => '15/15', 'tien_do' => '14/20']],
            ];
            break;

        case '15_chi_tiet_lop_hoc_hoc_thuat':
            $items = [
                ['Kế hoạch giảng dạy chi tiết 30 buổi - Lớp ME-IELTS-A01', 'active', ['muc' => 'Giáo án', 'so_buoi' => 30, 'noi_dung' => 'Full 4 kỹ năng theo chuẩn Cambridge']],
                ['Danh sách 16 học viên và thông tin liên hệ phụ huynh', 'active', ['muc' => 'Học viên', 'so_luong' => 16, 'noi_dung' => 'Đầy đủ hồ sơ đầu vào']],
                ['Bảng điểm danh và theo dõi chuyên cần 24 buổi đã học', 'active', ['muc' => 'Chuyên cần', 'ti_le' => '96.5%', 'noi_dung' => 'Chỉ có 4 lượt vắng có phép']],
                ['Bảng tổng hợp điểm số Mini Test và Big Test giữa kỳ', 'active', ['muc' => 'Bảng điểm', 'diem_tb' => '7.8/10', 'noi_dung' => '100% đạt chuẩn đầu ra']],
                ['Đánh giá phản hồi giữa kỳ từ học viên về giáo viên & trợ giảng', 'active', ['muc' => 'Feedback', 'diem_csat' => '4.9/5', 'noi_dung' => 'Khen ngợi phương pháp giảng dạy']],
            ];
            break;

        case '16_danh_gia_du_gio_hoc_thuat':
            $items = [
                ['Đánh giá chuyên môn GV Nguyễn Hoàng Nam: 94/100 (Kỹ năng sư phạm xuất sắc)', 'approved', ['giao_vien' => 'Nguyễn Hoàng Nam', 'diem' => '94/100', 'xep_loai' => 'Xuất sắc', 'chuyen_mon' => 'IELTS Speaking']],
                ['Đánh giá chuyên môn GV Trần Thị Mai Anh: 96/100 (Tương tác và năng lượng lớp vượt trội)', 'approved', ['giao_vien' => 'Trần Thị Mai Anh', 'diem' => '96/100', 'xep_loai' => 'Xuất sắc', 'chuyen_mon' => 'Giao tiếp']],
                ['Đánh giá chuyên môn GV Lê Đình Huy: 92/100 (Kiến thức học thuật chuẩn xác)', 'approved', ['giao_vien' => 'Lê Đình Huy', 'diem' => '92/100', 'xep_loai' => 'Giỏi', 'chuyen_mon' => 'IELTS Writing']],
                ['Đánh giá chuyên môn GV Phạm Thu Hà: 90/100 (Quản lý học sinh nhỏ tuổi kiên nhẫn)', 'approved', ['giao_vien' => 'Phạm Thu Hà', 'diem' => '90/100', 'xep_loai' => 'Giỏi', 'chuyen_mon' => 'Kids & Teens']],
                ['Đánh giá chuyên môn GV Vũ Đức Anh: 89/100 (Phương pháp trực quan, cần tăng tốc độ nói)', 'approved', ['giao_vien' => 'Vũ Đức Anh', 'diem' => '89/100', 'xep_loai' => 'Khá', 'chuyen_mon' => 'Phát âm IPA']],
            ];
            break;

        case '17_bao_cao_hop_giao_vien_theo_tuan':
            $items = [
                ['Biên bản họp chuyên môn Tuần 1 Tháng 9: Thống nhất format đề Big Test mới', 'approved', ['tuan' => 'Tuần 1 T9', 'chu_tri' => 'Academic Director', 'tham_gia' => '12 Giáo viên', 'ket_luan' => 'Áp dụng đề mới từ 15/09']],
                ['Biên bản họp chuyên môn Tuần 2 Tháng 9: Đào tạo phương pháp chấm Speaking 2026', 'approved', ['tuan' => 'Tuần 2 T9', 'chu_tri' => 'Senior Trainer', 'tham_gia' => '14 Giáo viên', 'ket_luan' => 'Thống nhất rubric 4 tiêu chí']],
                ['Biên bản họp chuyên môn Tuần 3 Tháng 8: Rà soát học sinh có nguy cơ rớt chuẩn', 'approved', ['tuan' => 'Tuần 3 T8', 'chu_tri' => 'Head of Academic', 'tham_gia' => '10 Giáo viên', 'ket_luan' => 'Kích hoạt gia sư phụ đạo 1-1']],
                ['Biên bản họp chuyên môn Tuần 4 Tháng 8: Chuẩn bị khai giảng chuỗi lớp thu đông', 'approved', ['tuan' => 'Tuần 4 T8', 'chu_tri' => 'Center Manager', 'tham_gia' => '15 Giáo viên', 'ket_luan' => 'Phân công lịch giảng dạy']],
                ['Biên bản họp định kỳ: Triển khai tính năng nộp bài video trên ứng dụng MEnglish', 'approved', ['tuan' => 'Họp Định Kỳ', 'chu_tri' => 'Product Lead', 'tham_gia' => 'Toàn bộ GV', 'ket_luan' => 'GV chấm bài trong vòng 48h']],
            ];
            break;

        case '18_bao_cao_tuan_hoc_thuat':
            $items = [
                ['Báo cáo học thuật Tuần 36: Tỷ lệ hoàn thành giáo trình 98.5%, 0 ca chậm tiến độ', 'approved', ['tuan' => 'Tuần 36', 'hoan_thanh' => '98.5%', 'cham_tien_do' => 0, 'chat_luong' => 'Rất tốt']],
                ['Báo cáo học thuật Tuần 35: 96 bài tập video nộp đúng hạn, điểm TB 8.2', 'approved', ['tuan' => 'Tuần 35', 'hoan_thanh' => '97.0%', 'nop_bai' => 96, 'chat_luong' => 'Tốt']],
                ['Báo cáo học thuật Tuần 34: 4 lớp hoàn thành thi Big Test với tỷ lệ đỗ 100%', 'approved', ['tuan' => 'Tuần 34', 'hoan_thanh' => '99.0%', 'ti_le_do' => '100%', 'chat_luong' => 'Xuất sắc']],
                ['Báo cáo học thuật Tuần 33: Phê duyệt 2 yêu cầu điều chỉnh tiến độ hợp lý', 'approved', ['tuan' => 'Tuần 33', 'hoan_thanh' => '96.2%', 'dieu_chinh' => 2, 'chat_luong' => 'Đạt chuẩn']],
                ['Báo cáo học thuật Tuần 32: Đón nhận 3 giáo viên mới hoàn thành khóa huấn luyện', 'approved', ['tuan' => 'Tuần 32', 'hoan_thanh' => '98.0%', 'gv_moi' => 3, 'chat_luong' => 'Tốt']],
            ];
            break;

        case '19_bao_cao_thang_hoc_thuat':
            $items = [
                ['Báo cáo tổng kết Học thuật Tháng 08/2026: Đạt 105% chỉ tiêu chất lượng đào tạo', 'approved', ['thang' => 'Tháng 08/2026', 'chi_tieu' => '105%', 'so_hoc_vien' => 340, 'diem_csat' => '4.85/5']],
                ['Báo cáo tổng kết Học thuật Tháng 07/2026: Tổ chức 12 workshop Speaking miễn phí', 'approved', ['thang' => 'Tháng 07/2026', 'chi_tieu' => '102%', 'so_hoc_vien' => 310, 'diem_csat' => '4.80/5']],
                ['Báo cáo tổng kết Học thuật Tháng 06/2026: Khai mạc khóa hè Summer Camp 150 học viên', 'approved', ['thang' => 'Tháng 06/2026', 'chi_tieu' => '108%', 'so_hoc_vien' => 360, 'diem_csat' => '4.90/5']],
                ['Báo cáo tổng kết Học thuật Tháng 05/2026: Hoàn thiện ngân hàng 500 đề thi trắc nghiệm', 'approved', ['thang' => 'Tháng 05/2026', 'chi_tieu' => '100%', 'so_hoc_vien' => 290, 'diem_csat' => '4.75/5']],
                ['Báo cáo tổng kết Học thuật Tháng 04/2026: Nâng cấp tài liệu giảng dạy chuẩn Oxford 2026', 'approved', ['thang' => 'Tháng 04/2026', 'chi_tieu' => '101%', 'so_hoc_vien' => 280, 'diem_csat' => '4.78/5']],
            ];
            break;

        case '20_bao_cao_quy_hoc_thuat':
            $items = [
                ['Báo cáo Chiến lược Học thuật Quý 2/2026: Tăng trưởng 22% số học sinh đạt chuẩn IELTS', 'approved', ['quy' => 'Quý 2/2026', 'tang_truong' => '+22%', 'doanh_so_hoc_thuat' => 'Vượt kế hoạch', 'ket_luan' => 'Tiếp tục mở rộng khối Teens']],
                ['Báo cáo Chiến lược Học thuật Quý 1/2026: Ra mắt lộ trình đào tạo Blended Learning', 'approved', ['quy' => 'Quý 1/2026', 'tang_truong' => '+18%', 'hieu_qua' => 'Tăng 30% giờ tự học', 'ket_luan' => 'Thành công vượt bậc']],
                ['Báo cáo Chiến lược Học thuật Quý 4/2025: Hoàn tất kiểm định chất lượng đào tạo ISO', 'approved', ['quy' => 'Quý 4/2025', 'tang_truong' => '+15%', 'kiem_dinh' => 'Đạt chuẩn', 'ket_luan' => 'Chuẩn hóa quy trình']],
                ['Báo cáo Chiến lược Học thuật Quý 3/2025: Tối ưu hóa đội ngũ 25 giáo viên cơ hữu', 'approved', ['quy' => 'Quý 3/2025', 'tang_truong' => '+20%', 'doi_ngu' => '25 GV cơ hữu', 'ket_luan' => 'Ổn định chất lượng']],
                ['Báo cáo Tổng kết Năm học 2025-2026: 98.6% học viên đạt hoặc vượt cam kết ban đầu', 'approved', ['quy' => 'Năm 2025-2026', 'cam_ket' => '98.6%', 'tong_hoc_vien' => 1250, 'ket_luan' => 'Thành tựu xuất sắc']],
            ];
            break;

        // === 03_Cong_Giao_Vien ===
        case '01_app_shell_cong_giao_vien':
            $items = [
                ['Lịch dạy hôm nay: Ca 1 (18:00 - 19:30) Lớp ME-IELTS-K20 tại Phòng 301', 'active', ['ca' => 'Ca 1', 'thoi_gian' => '18:00 - 19:30', 'lop' => 'ME-IELTS-K20', 'phong' => 'P.301']],
                ['Lịch dạy hôm nay: Ca 2 (19:45 - 21:15) Lớp ME-COMM-K12 tại Phòng 202', 'active', ['ca' => 'Ca 2', 'thoi_gian' => '19:45 - 21:15', 'lop' => 'ME-COMM-K12', 'phong' => 'P.202']],
                ['Nhắc việc: Còn 3 bài nộp video Speaking của lớp ME-I01 cần chấm điểm', 'pending', ['loai' => 'Chấm bài', 'so_luong' => 3, 'han_chot' => 'Hôm nay 23:59']],
                ['Thông báo: Kỳ thi Big Test đợt 2 diễn ra vào Thứ Bảy tuần này', 'active', ['loai' => 'Thông báo', 'ngay' => 'Thứ 7 tuần này', 'noi_dung' => 'GV chuẩn bị phòng lab']],
                ['Bảng lương tạm tính tháng hiện tại: 42 giờ dạy đã xác nhận', 'active', ['loai' => 'Lương', 'so_gio' => 42, 'trang_thai' => 'Đã chốt công']],
            ];
            break;

        case '02_diem_danh_lop_giao_vien':
            $items = [
                ['Điểm danh: Nguyễn Gia Huy (Có mặt - Đúng giờ - Làm bài tập đầy đủ)', 'approved', ['hoc_sinh' => 'Nguyễn Gia Huy', 'tinh_trang' => 'Có mặt', 'dung_gio' => true, 'bai_tap' => 'Hoàn thành']],
                ['Điểm danh: Trần Bảo Ngọc (Có mặt - Đúng giờ - Tương tác xuất sắc)', 'approved', ['hoc_sinh' => 'Trần Bảo Ngọc', 'tinh_trang' => 'Có mặt', 'dung_gio' => true, 'bai_tap' => 'Hoàn thành']],
                ['Điểm danh: Lê Tuấn Kiệt (Có mặt - Trễ 10 phút do kẹt xe)', 'approved', ['hoc_sinh' => 'Lê Tuấn Kiệt', 'tinh_trang' => 'Đi muộn 10p', 'dung_gio' => false, 'bai_tap' => 'Hoàn thành']],
                ['Điểm danh: Phạm Minh Anh (Có mặt - Đúng giờ - Phát biểu hăng hái)', 'approved', ['hoc_sinh' => 'Phạm Minh Anh', 'tinh_trang' => 'Có mặt', 'dung_gio' => true, 'bai_tap' => 'Hoàn thành']],
                ['Điểm danh: Hoàng Đăng Khoa (Nghỉ học có phép - Đã báo phụ huynh)', 'pending', ['hoc_sinh' => 'Hoàng Đăng Khoa', 'tinh_trang' => 'Nghỉ có phép', 'dung_gio' => false, 'bai_tap' => 'Chưa nộp']],
            ];
            break;

        case '03_giao_bai_tap_ve_nha':
            $items = [
                ['Bài tập Buổi 12: Quay video Speaking 2 phút miêu tả thành phố em yêu thích', 'active', ['lop' => 'ME-COMM-K12', 'hinh_thuc' => 'Video/Audio', 'deadline' => 'Trước buổi 13', 'muc_tieu' => 'Luyện phát âm & ngữ điệu']],
                ['Bài tập Buổi 15: Viết đoạn văn Task 2 (150 từ) chủ đề Môi trường', 'active', ['lop' => 'ME-IELTS-K20', 'hinh_thuc' => 'Văn bản', 'deadline' => 'Trước 20:00 ngày mai', 'muc_tieu' => 'Phát triển luận điểm']],
                ['Bài tập Buổi 8: Hoàn thành 20 câu trắc nghiệm Ngữ pháp Unit 4 trên App', 'active', ['lop' => 'ME-FOUND-K32', 'hinh_thuc' => 'Quiz trắc nghiệm', 'deadline' => 'Trong 48 giờ', 'muc_tieu' => 'Củng cố Thì Hiện Tại']],
                ['Bài tập Buổi 6: Thu âm đọc 10 từ vựng chứa âm /θ/ và /ð/ chuẩn IPA', 'active', ['lop' => 'ME-KIDS-K05', 'hinh_thuc' => 'Ghi âm', 'deadline' => 'Trước chủ nhật', 'muc_tieu' => 'Sửa âm gió']],
                ['Bài tập Buổi 10: Nghe Audio Cam 18 Test 2 Section 1 và điền từ khóa', 'active', ['lop' => 'ME-TEEN-K09', 'hinh_thuc' => 'Điền từ', 'deadline' => 'Trước buổi 11', 'muc_tieu' => 'Bắt từ khóa ngày tháng']],
            ];
            break;

        case '04_bai_nop_cua_lop':
            $items = [
                ['Bài nộp HS Nguyễn Gia Huy: Video Speaking Part 2 (Thời lượng 2p15s) - Chờ chấm', 'pending', ['hoc_sinh' => 'Nguyễn Gia Huy', 'lop' => 'ME-COMM-K12', 'loai' => 'Video MP4', 'nop_luc' => 'Hôm qua 21:30']],
                ['Bài nộp HS Trần Bảo Ngọc: Video Speaking Task 1 (Điểm: 9.0 - Nhận xét chi tiết)', 'approved', ['hoc_sinh' => 'Trần Bảo Ngọc', 'lop' => 'ME-COMM-K12', 'loai' => 'Video MP4', 'diem' => '9.0/10']],
                ['Bài nộp HS Lê Tuấn Kiệt: Bài luận IELTS Task 2 File PDF (Chờ phản hồi)', 'pending', ['hoc_sinh' => 'Lê Tuấn Kiệt', 'lop' => 'ME-IELTS-K20', 'loai' => 'File PDF', 'nop_luc' => 'Hôm nay 08:15']],
                ['Bài nộp HS Phạm Minh Anh: Audio thu âm phát âm IPA (Điểm: 8.5 - Tốt)', 'approved', ['hoc_sinh' => 'Phạm Minh Anh', 'lop' => 'ME-FOUND-K32', 'loai' => 'Audio MP3', 'diem' => '8.5/10']],
                ['Bài nộp HS Hoàng Đăng Khoa: Video thuyết trình nhóm (Điểm: 8.0 - Khá)', 'approved', ['hoc_sinh' => 'Hoàng Đăng Khoa', 'lop' => 'ME-TEEN-K09', 'loai' => 'Video MP4', 'diem' => '8.0/10']],
            ];
            break;

        case '05_nhan_xet_buoi_hoc_cho_tung_hoc_sinh':
            $items = [
                ['Nhận xét HS Nguyễn Gia Huy: Tiếp thu nhanh, phản xạ nhanh, cần chú ý âm đuôi /s/', 'active', ['hoc_sinh' => 'Nguyễn Gia Huy', 'thai_do' => 'Tích cực', 'diem_manh' => 'Từ vựng đa dạng', 'can_cai_thien' => 'Ending sounds']],
                ['Nhận xét HS Trần Bảo Ngọc: Phát âm chuẩn tự nhiên, tự tin trả lời câu hỏi khó', 'active', ['hoc_sinh' => 'Trần Bảo Ngọc', 'thai_do' => 'Rất tích cực', 'diem_manh' => 'Ngữ điệu tự nhiên', 'can_cai_thien' => 'Duy trì phong độ']],
                ['Nhận xét HS Lê Tuấn Kiệt: Có tiến bộ so với buổi trước, cần tập trung hơn phần nghe', 'active', ['hoc_sinh' => 'Lê Tuấn Kiệt', 'thai_do' => 'Khá', 'diem_manh' => 'Chăm chỉ ghi chép', 'can_cai_thien' => 'Tốc độ nghe']],
                ['Nhận xét HS Phạm Minh Anh: Tích cực phát biểu, giọng đọc to rõ ràng, cần ôn từ vựng Unit 3', 'active', ['hoc_sinh' => 'Phạm Minh Anh', 'thai_do' => 'Nhiệt tình', 'diem_manh' => 'Tự tin trước lớp', 'can_cai_thien' => 'Ôn từ vựng']],
                ['Nhận xét HS Hoàng Đăng Khoa: Hoàn thành bài tập nhóm tốt, cần hạn chế nói chuyện riêng', 'active', ['hoc_sinh' => 'Hoàng Đăng Khoa', 'thai_do' => 'Trung bình', 'diem_manh' => 'Hợp tác tốt', 'can_cai_thien' => 'Kỷ luật lớp']],
            ];
            break;

        case '06_nhap_diem_mini_test':
            $items = [
                ['Mini Test Buổi 10 - Lớp ME-IELTS-K20: Điểm TB 8.1/10 (15/16 học viên tham gia)', 'approved', ['lop' => 'ME-IELTS-K20', 'bai_test' => 'Mini Test 10', 'diem_tb' => '8.1', 'tham_gia' => '15/16']],
                ['Mini Test Buổi 8 - Lớp ME-COMM-K12: Điểm TB 8.5/10 (14/14 học viên tham gia)', 'approved', ['lop' => 'ME-COMM-K12', 'bai_test' => 'Mini Test 08', 'diem_tb' => '8.5', 'tham_gia' => '14/14']],
                ['Mini Test Buổi 6 - Lớp ME-KIDS-K05: Điểm TB 9.0/10 (12/12 học viên tham gia)', 'approved', ['lop' => 'ME-KIDS-K05', 'bai_test' => 'Mini Test 06', 'diem_tb' => '9.0', 'tham_gia' => '12/12']],
                ['Mini Test Buổi 12 - Lớp ME-FOUND-K32: Điểm TB 7.8/10 (18/18 học viên tham gia)', 'approved', ['lop' => 'ME-FOUND-K32', 'bai_test' => 'Mini Test 12', 'diem_tb' => '7.8', 'tham_gia' => '18/18']],
                ['Mini Test Buổi 4 - Lớp ME-TEEN-K09: Điểm TB 8.0/10 (15/15 học viên tham gia)', 'approved', ['lop' => 'ME-TEEN-K09', 'bai_test' => 'Mini Test 04', 'diem_tb' => '8.0', 'tham_gia' => '15/15']],
            ];
            break;

        case '07_chang_dang_day_va_order_test':
            $items = [
                ['Order đề Big Test 1 cho Lớp ME-IELTS-K20 (Dự kiến thi ngày 18/09 - 16 học viên)', 'approved', ['lop' => 'ME-IELTS-K20', 'chang' => 'Chặng 1', 'ngay_thi' => '2026-09-18', 'so_luong' => 16]],
                ['Order đề Big Test Giữa kỳ cho Lớp ME-COMM-K12 (Dự kiến thi ngày 20/09 - 14 học viên)', 'approved', ['lop' => 'ME-COMM-K12', 'chang' => 'Chặng 2', 'ngay_thi' => '2026-09-20', 'so_luong' => 14]],
                ['Order đề Test Đánh giá năng lực Kids Starters Lớp ME-KIDS-K05 (12 học viên)', 'approved', ['lop' => 'ME-KIDS-K05', 'chang' => 'Chặng 1', 'ngay_thi' => '2026-09-22', 'so_luong' => 12]],
                ['Order đề Mini Test Chặng 3 Lớp ME-FOUND-K32 (Dự kiến thi ngày 25/09 - 18 học viên)', 'pending', ['lop' => 'ME-FOUND-K32', 'chang' => 'Chặng 3', 'ngay_thi' => '2026-09-25', 'so_luong' => 18]],
                ['Order đề Thi thử Mock Test Lớp ME-TEEN-K09 (Dự kiến thi ngày 28/09 - 15 học viên)', 'pending', ['lop' => 'ME-TEEN-K09', 'chang' => 'Chặng 2', 'ngay_thi' => '2026-09-28', 'so_luong' => 15]],
            ];
            break;

        case '08_xem_tai_lieu_giao_trinh':
            $items = [
                ['Slide Giảng dạy Buổi 15: Kỹ năng Viết bài luận IELTS Task 2 (Problem & Solution)', 'active', ['buoi' => 'Buổi 15', 'mon' => 'Writing', 'dinh_dang' => 'PPTX', 'so_slide' => 35]],
                ['File Audio Nghe Unit 6: Hội thoại Đặt phòng Khách sạn & Hỏi đường (Accents)', 'active', ['buoi' => 'Buổi 12', 'mon' => 'Listening', 'dinh_dang' => 'MP3', 'thoi_luong' => '14 phút']],
                ['Flashcard Trực quan Từ vựng Chủ đề Công nghệ & AI (Bản cập nhật 2026)', 'active', ['buoi' => 'Buổi 10', 'mon' => 'Vocabulary', 'dinh_dang' => 'PDF', 'so_tu' => 50]],
                ['Sổ tay Hướng dẫn Giảng dạy Teacher Guide Chặng 2 (Phương pháp TBL)', 'active', ['buoi' => 'Toàn chặng', 'mon' => 'Pedagogy', 'dinh_dang' => 'PDF', 'so_trang' => 48]],
                ['Phiếu Bài tập In ấn Handout Buổi 16: Thực hành Speaking Part 3 theo cặp', 'active', ['buoi' => 'Buổi 16', 'mon' => 'Speaking', 'dinh_dang' => 'DOCX', 'so_ban' => 20]],
            ];
            break;

        case '09_de_xuat_sua_giao_trinh':
            $items = [
                ['Đề xuất bổ sung video ngắn minh họa khẩu hình miệng cho âm /θ/ và /s/', 'approved', ['giao_vien' => 'Trần Thị Mai Anh', 'bai' => 'Unit 2 IPA', 'trang_thai' => 'Đã tiếp nhận']],
                ['Đề xuất cập nhật dữ liệu biểu đồ GDP bài tập Writing Task 1 sang năm 2025-2026', 'approved', ['giao_vien' => 'Lê Đình Huy', 'bai' => 'Unit 5 Writing', 'trang_thai' => 'Đã sửa']],
                ['Đề xuất thêm gợi ý từ nối linking words vào phần mẫu Speaking Part 2', 'pending', ['giao_vien' => 'Nguyễn Hoàng Nam', 'bai' => 'Unit 7 Speaking', 'trang_thai' => 'Chờ duyệt']],
                ['Đề xuất rút ngắn bài đọc Passage 1 vì từ vựng quá học thuật so với cấp độ B1', 'pending', ['giao_vien' => 'Phạm Thu Hà', 'bai' => 'Unit 9 Reading', 'trang_thai' => 'Đang xem xét']],
                ['Đề xuất thêm bài tập điền từ trực tiếp trên Quizizz cho lớp thiếu nhi', 'approved', ['giao_vien' => 'Vũ Đức Anh', 'bai' => 'Unit 4 Kids', 'trang_thai' => 'Đã áp dụng']],
            ];
            break;

        case '10_lich_du_kien_big_test':
            $items = [
                ['Lịch thi Big Test Lớp ME-IELTS-K20: Thứ Tư 18/09/2026 lúc 18:30 (Phòng Lab 1)', 'active', ['lop' => 'ME-IELTS-K20', 'ngay' => '2026-09-18 18:30', 'phong' => 'Lab 1', 'hinh_thuc' => 'Full Test']],
                ['Lịch thi Big Test Lớp ME-COMM-K12: Thứ Sáu 20/09/2026 lúc 19:45 (Phòng 202)', 'active', ['lop' => 'ME-COMM-K12', 'ngay' => '2026-09-20 19:45', 'phong' => 'Room 202', 'hinh_thuc' => 'Speaking 1-1']],
                ['Lịch thi Big Test Lớp ME-KIDS-K05: Thứ Bảy 22/09/2026 lúc 09:00 (Phòng 104)', 'active', ['lop' => 'ME-KIDS-K05', 'ngay' => '2026-09-22 09:00', 'phong' => 'Room 104', 'hinh_thuc' => 'Interactive Quiz']],
                ['Lịch thi Big Test Lớp ME-FOUND-K32: Thứ Ba 25/09/2026 lúc 18:00 (Phòng Lab 2)', 'active', ['lop' => 'ME-FOUND-K32', 'ngay' => '2026-09-25 18:00', 'phong' => 'Lab 2', 'hinh_thuc' => 'Paper Test']],
                ['Lịch thi Big Test Lớp ME-TEEN-K09: Thứ Năm 27/09/2026 lúc 19:30 (Phòng 205)', 'active', ['lop' => 'ME-TEEN-K09', 'ngay' => '2026-09-27 19:30', 'phong' => 'Room 205', 'hinh_thuc' => 'Hybrid Test']],
            ];
            break;

        case '11_report_thang_lich_big_test':
            $items = [
                ['Tháng 08/2026: Đã tổ chức 14 buổi thi Big Test, 186 thí sinh, tỷ lệ qua 97.8%', 'approved', ['thang' => '08/2026', 'tong_buoi' => 14, 'thi_sinh' => 186, 'ti_le_qua' => '97.8%']],
                ['Tháng 07/2026: Đã tổ chức 12 buổi thi Big Test, 160 thí sinh, tỷ lệ qua 96.5%', 'approved', ['thang' => '07/2026', 'tong_buoi' => 12, 'thi_sinh' => 160, 'ti_le_qua' => '96.5%']],
                ['Tháng 06/2026: Đã tổ chức 16 buổi thi Big Test, 210 thí sinh, tỷ lệ qua 98.2%', 'approved', ['thang' => '06/2026', 'tong_buoi' => 16, 'thi_sinh' => 210, 'ti_le_qua' => '98.2%']],
                ['Tháng 05/2026: Đã tổ chức 10 buổi thi Big Test, 135 thí sinh, tỷ lệ qua 95.0%', 'approved', ['thang' => '05/2026', 'tong_buoi' => 10, 'thi_sinh' => 135, 'ti_le_qua' => '95.0%']],
                ['Tháng 04/2026: Đã tổ chức 11 buổi thi Big Test, 142 thí sinh, tỷ lệ qua 96.0%', 'approved', ['thang' => '04/2026', 'tong_buoi' => 11, 'thi_sinh' => 142, 'ti_le_qua' => '96.0%']],
            ];
            break;

        case '12_xac_nhan_quiz_desktop':
            $items = [
                ['Bộ Quiz 1: 20 câu Trắc nghiệm Ngữ pháp Thì Hiện tại hoàn thành (Đã duyệt)', 'approved', ['bo_quiz' => 'Quiz 01', 'so_cau' => 20, 'chu_de' => 'Present Perfect', 'thoi_gian' => '15 phút']],
                ['Bộ Quiz 2: 15 câu Điền từ Từ vựng Chủ đề Travel & Tourism (Đã duyệt)', 'approved', ['bo_quiz' => 'Quiz 02', 'so_cau' => 15, 'chu_de' => 'Travel Vocab', 'thoi_gian' => '12 phút']],
                ['Bộ Quiz 3: 10 câu Phân biệt Cặp âm dễ nhầm lẫn /s/ và /ʃ/ (Đã duyệt)', 'approved', ['bo_quiz' => 'Quiz 03', 'so_cau' => 10, 'chu_de' => 'Phonetics', 'thoi_gian' => '10 phút']],
                ['Bộ Quiz 4: 25 câu Chiến thuật Đọc hiểu Đoạn văn Ngắn Skimming (Chờ duyệt)', 'pending', ['bo_quiz' => 'Quiz 04', 'so_cau' => 25, 'chu_de' => 'Reading Strategy', 'thoi_gian' => '20 phút']],
                ['Bộ Quiz 5: 15 câu Sửa lỗi sai Tìm lỗi ngữ pháp câu phức (Đã duyệt)', 'approved', ['bo_quiz' => 'Quiz 05', 'so_cau' => 15, 'chu_de' => 'Error Identification', 'thoi_gian' => '15 phút']],
            ];
            break;

        case '13_xac_nhan_quiz_mobile':
            $items = [
                ['Quiz App Mobile: Gamification Từ vựng Daily Life Unit 3 (10 câu hỏi hình ảnh)', 'approved', ['app' => 'Mobile iOS/Android', 'so_cau' => 10, 'loai' => 'Hình ảnh trực quan', 'diem' => 100]],
                ['Quiz App Mobile: Nghe Audio ngắn và chọn đáp án đúng Unit 5 (12 câu)', 'approved', ['app' => 'Mobile iOS/Android', 'so_cau' => 12, 'loai' => 'Audio tương tác', 'diem' => 120]],
                ['Quiz App Mobile: Ghép cặp Từ đồng nghĩa Synonyms Flash Card (15 cặp từ)', 'approved', ['app' => 'Mobile iOS/Android', 'so_cau' => 15, 'loai' => 'Ghép thẻ bài', 'diem' => 150]],
                ['Quiz App Mobile: Thử thách Nói nhanh Phát âm chuẩn cùng AI (8 câu đọc mẫu)', 'approved', ['app' => 'Mobile iOS/Android', 'so_cau' => 8, 'loai' => 'Voice AI Recognition', 'diem' => 80]],
                ['Quiz App Mobile: Sắp xếp các từ thành câu hoàn chỉnh Unit 7 (10 câu)', 'approved', ['app' => 'Mobile iOS/Android', 'so_cau' => 10, 'loai' => 'Kéo thả chữ', 'diem' => 100]],
            ];
            break;

        case '14_xin_dieu_chinh_tien_do':
            $items = [
                ['Đơn xin đổi lịch buổi 16 lớp ME-IELTS-K20 sang Chủ nhật 15:00 (Lý do: GV bận việc)', 'approved', ['giao_vien' => 'Lê Đình Huy', 'lop' => 'ME-IELTS-K20', 'buoi' => 16, 'lich_moi' => 'CN 15:00']],
                ['Đơn xin bù bài 1 buổi phụ đạo Speaking cá nhân cho Lớp ME-COMM-K12', 'approved', ['giao_vien' => 'Trần Thị Mai Anh', 'lop' => 'ME-COMM-K12', 'buoi' => 'Phụ đạo', 'lich_moi' => 'T7 14:00']],
                ['Đơn xin chia nhỏ bài giảng Unit 8 thành 2 buổi để học viên luyện viết kỹ hơn', 'pending', ['giao_vien' => 'Nguyễn Hoàng Nam', 'lop' => 'ME-FOUND-K32', 'buoi' => 18, 'lich_moi' => 'Thêm 1 buổi']],
                ['Đơn xin dời buổi thi Big Test lùi lại 3 ngày để học viên kịp ôn tập', 'approved', ['giao_vien' => 'Phạm Thu Hà', 'lop' => 'ME-KIDS-K05', 'buoi' => 'Big Test', 'lich_moi' => '25/09']],
                ['Đơn xin đổi phòng học từ 202 sang phòng Lab đa phương tiện buổi 14', 'approved', ['giao_vien' => 'Vũ Đức Anh', 'lop' => 'ME-TEEN-K09', 'buoi' => 14, 'lich_moi' => 'Lab 1']],
            ];
            break;

        case '15_check_in_cua_toi':
            $items = [
                ['Check-in: Buổi 14 Lớp ME-IELTS-K20 lúc 17:55 (Đúng giờ - Định vị GPS Trung tâm)', 'approved', ['lop' => 'ME-IELTS-K20', 'gio_checkin' => '17:55', 'gio_hoc' => '18:00', 'vi_tri' => 'Phòng 301']],
                ['Check-in: Buổi 18 Lớp ME-COMM-K12 lúc 19:40 (Đúng giờ - Định vị GPS Trung tâm)', 'approved', ['lop' => 'ME-COMM-K12', 'gio_checkin' => '19:40', 'gio_hoc' => '19:45', 'vi_tri' => 'Phòng 202']],
                ['Check-in: Buổi 09 Lớp ME-KIDS-K05 lúc 08:45 (Đúng giờ - Quét mã QR Lớp học)', 'approved', ['lop' => 'ME-KIDS-K05', 'gio_checkin' => '08:45', 'gio_hoc' => '09:00', 'vi_tri' => 'Phòng 104']],
                ['Check-in: Buổi 22 Lớp ME-FOUND-K32 lúc 17:58 (Đúng giờ - Định vị GPS Trung tâm)', 'approved', ['lop' => 'ME-FOUND-K32', 'gio_checkin' => '17:58', 'gio_hoc' => '18:00', 'vi_tri' => 'Phòng 205']],
                ['Check-in: Buổi 12 Lớp ME-TEEN-K09 lúc 19:33 (Đúng giờ - Quét mã QR Lớp học)', 'approved', ['lop' => 'ME-TEEN-K09', 'gio_checkin' => '19:33', 'gio_hoc' => '19:30', 'vi_tri' => 'Phòng 205']],
            ];
            break;

        case '16_gui_bao_cao_cham_cong':
            $items = [
                ['Báo cáo chấm công Tuần 1 Tháng 9 - GV Nguyễn Hoàng Nam (Tổng: 14 giờ dạy thực tế)', 'approved', ['giao_vien' => 'Nguyễn Hoàng Nam', 'so_gio' => 14, 'so_buoi' => 7, 'trang_thai' => 'Đã duyệt']],
                ['Báo cáo chấm công Tuần 1 Tháng 9 - GV Trần Thị Mai Anh (Tổng: 16 giờ dạy thực tế)', 'approved', ['giao_vien' => 'Trần Thị Mai Anh', 'so_gio' => 16, 'so_buoi' => 8, 'trang_thai' => 'Đã duyệt']],
                ['Báo cáo chấm công Tuần 1 Tháng 9 - GV Lê Đình Huy (Tổng: 12 giờ dạy thực tế)', 'approved', ['giao_vien' => 'Lê Đình Huy', 'so_gio' => 12, 'so_buoi' => 6, 'trang_thai' => 'Đã duyệt']],
                ['Báo cáo chấm công Tuần 1 Tháng 9 - GV Phạm Thu Hà (Tổng: 10 giờ dạy thực tế)', 'approved', ['giao_vien' => 'Phạm Thu Hà', 'so_gio' => 10, 'so_buoi' => 5, 'trang_thai' => 'Đã duyệt']],
                ['Báo cáo chấm công Tuần 1 Tháng 9 - GV Vũ Đức Anh (Tổng: 14 giờ dạy thực tế)', 'approved', ['giao_vien' => 'Vũ Đức Anh', 'so_gio' => 14, 'so_buoi' => 7, 'trang_thai' => 'Đã duyệt']],
            ];
            break;

        case '17_bao_cao_chung_cua_giao_vien':
            $items = [
                ['Tổng kết kết quả lớp ME-IELTS-K20: 100% học sinh đạt chỉ tiêu Band điểm chặng 1', 'approved', ['lop' => 'ME-IELTS-K20', 'ti_le_dat' => '100%', 'diem_tb' => '6.8', 'nhan_xet_chung' => 'Lớp có động lực cao']],
                ['Tổng kết kết quả lớp ME-COMM-K12: Học sinh phản xạ tốt, tự tin giao tiếp chủ đề đời sống', 'approved', ['lop' => 'ME-COMM-K12', 'ti_le_dat' => '95%', 'diem_tb' => '8.4', 'nhan_xet_chung' => 'Năng động, phát âm tiến bộ']],
                ['Tổng kết kết quả lớp ME-KIDS-K05: Các bé thuộc 80 từ vựng và bảng chữ cái tiếng Anh', 'approved', ['lop' => 'ME-KIDS-K05', 'ti_le_dat' => '100%', 'diem_tb' => '9.2', 'nhan_xet_chung' => 'Hào hứng tham gia hoạt động']],
                ['Tổng kết kết quả lớp ME-FOUND-K32: Học sinh nắm vững 6 thì cơ bản và viết câu chuẩn', 'approved', ['lop' => 'ME-FOUND-K32', 'ti_le_dat' => '94%', 'diem_tb' => '7.9', 'nhan_xet_chung' => 'Cần ôn thêm phần Nghe']],
                ['Tổng kết kết quả lớp ME-TEEN-K09: Điểm thi giữa kỳ ở trường phổ thông tăng bình quân 1.5 điểm', 'approved', ['lop' => 'ME-TEEN-K09', 'ti_le_dat' => '98%', 'diem_tb' => '8.2', 'nhan_xet_chung' => 'Hiệu quả rõ rệt']],
            ];
            break;

        // === 04_Cong_Phu_Huynh_Hoc_Sinh ===
        case '01_app_shell_phu_huynh_hoc_sinh':
            $items = [
                ['Thông tin khóa học: Khóa IELTS Master Band 6.5+ (Lớp ME-IELTS-K20)', 'active', ['khoa_hoc' => 'IELTS Master 6.5+', 'lop' => 'ME-IELTS-K20', 'gv' => 'Thầy Lê Đình Huy', 'phong' => 'P.301']],
                ['Lịch học tuần này: Tối Thứ 2 & Thứ 4 (18:00 - 19:30) tại Cơ sở 1', 'active', ['lich' => 'T2 - T4 (18:00 - 19:30)', 'dia_diem' => 'Cơ sở 1', 'xe_dua_don' => 'Không']],
                ['Chuyên cần của học viên: Đã tham gia 15/15 buổi học (Tỷ lệ 100%)', 'active', ['chuyen_can' => '100%', 'so_buoi' => '15/15', 'vang_hoc' => 0, 'dung_gio' => '100%']],
                ['Bài tập về nhà mới: Video Speaking 2 phút miêu tả hoạt động yêu thích', 'pending', ['bai_tap' => 'Speaking Video', 'deadline' => '20:00 Ngày mai', 'tinh_trang' => 'Chưa nộp']],
                ['Thông báo mới từ trung tâm: Lịch thi Big Test định kỳ ngày 18/09/2026', 'active', ['thong_bao' => 'Lịch thi Big Test', 'ngay' => '18/09/2026', 'ghi_chu' => 'Phụ huynh nhắc con ôn tập']],
            ];
            break;

        case '02_trang_chu_phu_huynh_hoc_sinh':
            $items = [
                ['Học phí Đợt 2: Đã thanh toán thành công 6,800,000 VNĐ (Mã HĐ: HD-8842)', 'approved', ['dot' => 'Đợt 2', 'so_tien' => '6,800,000 VNĐ', 'ngay_dong' => '2026-08-25', 'trang_thai' => 'Đã thanh toán']],
                ['Kết quả buổi học gần nhất: Buổi 15 (Điểm phát âm: 9/10 - Nhận xét tốt)', 'active', ['buoi' => 'Buổi 15', 'diem' => '9.0/10', 'nhan_xet' => 'Phát âm tự tin, ngữ điệu tự nhiên']],
                ['Huy hiệu đạt được: Ngôi sao chuyên cần Tháng 8 (Đi học 100% đúng giờ)', 'active', ['danh_hieu' => 'Học viên Chuyên Cần', 'thang' => '08/2026', 'phan_thuong' => '50 Điểm Star']],
                ['Bài tập về nhà cần hoàn thành: 1 bài trắc nghiệm + 1 video ngắn', 'pending', ['so_bai' => 2, 'han_chot' => 'Trong 24h tới', 'trang_thai' => 'Đang làm']],
                ['Khảo sát chất lượng dịch vụ giáo dục định kỳ: Đang mở (Thời gian: 3 phút)', 'active', ['khao_sat' => 'Đánh giá Tháng 8', 'thoi_luong' => '3 phút', 'trang_thai' => 'Chưa gửi']],
            ];
            break;

        case '03_hoc_tap_cua_toi_nop_bai_tap':
            $items = [
                ['Bài nộp: Video Speaking Part 2 - Chủ đề "My Favorite Book" (Đã nộp lúc 19:30)', 'approved', ['tieu_de' => 'My Favorite Book', 'dinh_dang' => 'Video MP4', 'dung_luong' => '45 MB', 'diem' => '8.5/10']],
                ['Bài nộp: Bài viết luận Task 1 - Biểu đồ dân số đô thị (Đã chấm: 7.5/10)', 'approved', ['tieu_de' => 'IELTS Writing Task 1', 'dinh_dang' => 'PDF', 'dung_luong' => '1.2 MB', 'diem' => '7.5/10']],
                ['Bài nộp: Ghi âm 10 câu giao tiếp thực tế nhà hàng & khách sạn (Đang chờ chấm)', 'pending', ['tieu_de' => 'Restaurant & Hotel Dialogue', 'dinh_dang' => 'Audio MP3', 'dung_luong' => '4.8 MB', 'diem' => 'Chờ chấm']],
                ['Bài nộp: 20 câu trắc nghiệm Ngữ pháp Thì Quá khứ đơn (Điểm: 19/20 - Xuất sắc)', 'approved', ['tieu_de' => 'Past Simple Tense Quiz', 'dinh_dang' => 'Quiz online', 'dung_luong' => 'N/A', 'diem' => '19/20']],
                ['Bài nộp: Dự án nhóm Video Thuyết trình Bảo vệ Môi trường (Điểm: 9.0/10)', 'approved', ['tieu_de' => 'Environmental Protection Project', 'dinh_dang' => 'Video MP4', 'dung_luong' => '95 MB', 'diem' => '9.0/10']],
            ];
            break;

        case '04_luyen_phat_am':
            $items = [
                ['Bài luyện âm IPA /θ/ (think, thank, thirty): AI chấm điểm 92/100 (Xuất sắc)', 'approved', ['am_luyen' => '/θ/', 'tu_mau' => 'think, thank, thirty, tooth', 'diem_ai' => '92/100', 'nhan_xet' => 'Khẩu hình chuẩn']],
                ['Bài luyện âm IPA /ð/ (this, that, there, mother): AI chấm điểm 88/100 (Rất tốt)', 'approved', ['am_luyen' => '/ð/', 'tu_mau' => 'this, that, brother, weather', 'diem_ai' => '88/100', 'nhan_xet' => 'Rung dây thanh tốt']],
                ['Bài luyện âm đuôi Ending Sounds /s/ & /z/ (cats, dogs, boxes): AI chấm điểm 90/100', 'approved', ['am_luyen' => 'Ending /s/ & /z/', 'tu_mau' => 'cats, dogs, teaches', 'diem_ai' => '90/100', 'nhan_xet' => 'Không bị nuốt âm đuôi']],
                ['Bài luyện Trọng âm từ 3 âm tiết (computer, beautiful, understand): AI chấm 85/100', 'approved', ['am_luyen' => 'Word Stress', 'tu_mau' => 'computer, expensive, important', 'diem_ai' => '85/100', 'nhan_xet' => 'Nhấn đúng trọng âm chính']],
                ['Bài luyện Ngữ điệu câu hỏi Intonation (Rising & Falling): AI chấm điểm 94/100', 'approved', ['am_luyen' => 'Sentence Intonation', 'tu_mau' => 'Do you like coffee? / Where are you?', 'diem_ai' => '94/100', 'nhan_xet' => 'Ngữ điệu tự nhiên']],
            ];
            break;

        case '05_danh_sach_thong_bao':
            $items = [
                ['Thông báo Lịch thi Big Test Giữa kỳ đợt Tháng 9/2026', 'active', ['ngay_gui' => '2026-09-08', 'nguoi_gui' => 'Ban Giám đốc Đào tạo', 'muc_do' => 'Quan trọng', 'da_doc' => true]],
                ['Thông báo Lịch nghỉ Lễ Quốc khánh 2/9 và lịch học bù', 'active', ['ngay_gui' => '2026-08-28', 'nguoi_gui' => 'Phòng Học vụ', 'muc_do' => 'Toàn trung tâm', 'da_doc' => true]],
                ['Thư khen ngợi Học viên Xuất sắc Tháng 8 đạt điểm cao nhất lớp', 'active', ['ngay_gui' => '2026-08-31', 'nguoi_gui' => 'Giáo viên Chủ nhiệm', 'muc_do' => 'Khen thưởng', 'da_doc' => true]],
                ['Nhắc lịch đóng học phí chặng tiếp theo với ưu đãi 10% tri ân', 'active', ['ngay_gui' => '2026-09-02', 'nguoi_gui' => 'Phòng Tài chính', 'muc_do' => 'Tài chính', 'da_doc' => false]],
                ['Thư mời tham gia Workshop Kỹ năng Săn học bổng Du học Quốc tế', 'active', ['ngay_gui' => '2026-09-05', 'nguoi_gui' => 'Bộ phận Du học', 'muc_do' => 'Sự kiện miễn phí', 'da_doc' => true]],
            ];
            break;

        case '06_khao_sat':
            $items = [
                ['Khảo sát mức độ hài lòng về chất lượng giảng dạy của Giáo viên (Tháng 8/2026)', 'approved', ['doi_tuong' => 'Giáo viên', 'diem_danh_gia' => '5/5 sao', 'dong_gop' => 'Giáo viên dạy rất nhiệt tình, con rất thích học']],
                ['Khảo sát cơ sở vật chất phòng học, máy chiếu và điều hòa tại Trung tâm', 'approved', ['doi_tuong' => 'Cơ sở vật chất', 'diem_danh_gia' => '4.8/5 sao', 'dong_gop' => 'Phòng sạch sẽ, mạng wifi ổn định']],
                ['Khảo sát độ tương tác và hỗ trợ của Trợ giảng ngoài giờ học', 'approved', ['doi_tuong' => 'Trợ giảng', 'diem_danh_gia' => '5/5 sao', 'dong_gop' => 'Trợ giảng nhắc nhở bài tập rất chu đáo']],
                ['Khảo sát trải nghiệm ứng dụng di động MEnglish và chức năng nộp bài tập', 'approved', ['doi_tuong' => 'Ứng dụng App', 'diem_danh_gia' => '4.7/5 sao', 'dong_gop' => 'App tiện lợi, con tự quay video nộp dễ dàng']],
                ['Khảo sát kỳ vọng đầu ra và mong muốn của phụ huynh cho chặng học tiếp theo', 'approved', ['doi_tuong' => 'Lộ trình học', 'diem_danh_gia' => '5/5 sao', 'dong_gop' => 'Mong trung tâm mở thêm lớp giao tiếp cuối tuần']],
            ];
            break;

        case '07_phu_huynh_gui_feedback':
            $items = [
                ['Feedback phụ huynh HS Gia Huy: Con tự tin nói tiếng Anh nhiều hơn khi về nhà', 'approved', ['phu_huynh' => 'Chị Nguyễn Thu Thủy', 'hoc_sinh' => 'Nguyễn Gia Huy', 'diem' => '5/5 sao', 'loi_nhan' => 'Cảm ơn thầy Nam và trung tâm đã tận tâm chỉ dạy']],
                ['Feedback phụ huynh HS Bảo Ngọc: Phương pháp dạy thực tế, con hào hứng mỗi buổi đi học', 'approved', ['phu_huynh' => 'Anh Trần Minh Đức', 'hoc_sinh' => 'Trần Bảo Ngọc', 'diem' => '5/5 sao', 'loi_nhan' => 'Gia đình rất hài lòng với chất lượng đào tạo']],
                ['Feedback phụ huynh HS Tuấn Kiệt: Đề xuất có thêm các buổi câu lạc bộ nói chuyện với người bản xứ', 'approved', ['phu_huynh' => 'Chị Lê Thị Thanh', 'hoc_sinh' => 'Lê Tuấn Kiệt', 'diem' => '4.5/5 sao', 'loi_nhan' => 'Muốn con có thêm môi trường cọ xát thực tế']],
                ['Feedback phụ huynh HS Minh Anh: Giáo trình in đẹp, hình ảnh sinh động, bé rất thích làm bài', 'approved', ['phu_huynh' => 'Chị Phạm Quỳnh Mai', 'hoc_sinh' => 'Phạm Minh Anh', 'diem' => '5/5 sao', 'loi_nhan' => 'Bé tự giác làm bài không cần mẹ nhắc']],
                ['Feedback phụ huynh HS Đăng Khoa: Trung tâm thông báo tình hình học tập rất kịp thời và chi tiết', 'approved', ['phu_huynh' => 'Anh Hoàng Văn Tuấn', 'hoc_sinh' => 'Hoàng Đăng Khoa', 'diem' => '4.8/5 sao', 'loi_nhan' => 'Nhận xét của thầy rất đúng với ưu khuyết điểm của con']],
            ];
            break;

        default:
            $items = [
                ["Dữ liệu mẫu tính năng 01: {$titleVn}", 'active', ['tinh_nang' => $titleVn, 'chi_tiet' => 'Mục mẫu 1']],
                ["Dữ liệu mẫu tính năng 02: {$titleVn}", 'active', ['tinh_nang' => $titleVn, 'chi_tiet' => 'Mục mẫu 2']],
                ["Dữ liệu mẫu tính năng 03: {$titleVn}", 'active', ['tinh_nang' => $titleVn, 'chi_tiet' => 'Mục mẫu 3']],
                ["Dữ liệu mẫu tính năng 04: {$titleVn}", 'active', ['tinh_nang' => $titleVn, 'chi_tiet' => 'Mục mẫu 4']],
                ["Dữ liệu mẫu tính năng 05: {$titleVn}", 'active', ['tinh_nang' => $titleVn, 'chi_tiet' => 'Mục mẫu 5']],
            ];
            break;
    }

    foreach ($items as $idx => $item) {
        $records[] = makeSeed(
            $screenKey,
            $module,
            $idx + 1,
            $shortCode,
            $item[0],
            $item[1],
            $item[2]
        );
    }

    $allSeeds[$screenKey] = $records;
}

file_put_contents(__DIR__ . '/academic_seeds_58.json', json_encode($allSeeds, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "Successfully generated 5 seed records for each of the " . count($allSeeds) . " screens! Total records: " . (count($allSeeds) * 5) . PHP_EOL;
