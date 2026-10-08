<?php

namespace Database\Seeders;

use App\Http\Controllers\MerchandiseItemController;
use App\Http\Controllers\MerchandiseStockController;
use App\Http\Controllers\RecruitmentController;
use App\Http\Controllers\TuitionController;
use App\Http\Controllers\UserController;
use App\Models\AdminNotification;
use App\Models\Branch;
use App\Models\CandidateCv;
use App\Models\ClassModel;
use App\Models\InvoiceCancellation;
use App\Models\JobPosting;
use App\Models\MaterialOrder;
use App\Models\MerchandiseItem;
use App\Models\MerchandiseStockMovement;
use App\Models\Student;
use App\Models\TimesheetSyncLog;
use App\Models\TuitionReceipt;
use App\Models\User;
use App\Services\MaterialOrderService;
use App\Support\Roles;
use Database\Seeders\Concerns\InvokesControllersAsUser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Dữ liệu demo phủ đủ case khối Vận hành & nhân sự (tốt lẫn xấu), mốc thời gian tương đối với hôm nay (D-n = n ngày trước):
 * - Tuyển dụng: 5 tin (đang mở, mở mà đã quá hạn nộp, đã đóng sau khi tuyển đủ, thực tập mới đăng) — hệ thống không có
 *   trạng thái nháp; 11 CV nộp qua cổng tuyển dụng, đi qua đủ bước Chờ xử lý → Đang đánh giá → Đã phỏng vấn → Đã tuyển /
 *   Từ chối (ghi chú từng bước), 1 hồ sơ ứng tuyển tự do không gắn tin.
 * - Tài khoản: 1 Sales bị khóa (vô hiệu hóa), 1 GV part-time đã nghỉ việc (ngừng hoạt động, HĐ hết hạn), 1 GV part-time
 *   dạy 3 cơ sở (chi nhánh chính Cầu Giấy + user_branches Ba Đình, Đống Đa).
 * - Order học liệu (MaterialOrderService) loại Đạo cụ / In ấn / Order GVNN: hoàn thành đúng hạn, bị từ chối, quá hạn chưa
 *   xử lý (job "Quá hạn"), quá hạn rồi xử lý trễ, tạo trễ, đang xử lý, chờ xử lý.
 * - Lịch sử đồng bộ chấm công (AppSheet / máy FaceID): thành công, lỗi một phần (dòng lỗi + bỏ qua do kỳ lương đã chốt),
 *   lỗi toàn bộ (mã lỗi hệ thống), 1 bản ghi kiểu cũ status "error".
 * - Kho hàng hóa: danh mục sách / sách bài tập / đồng phục / balo / quà tặng (theo MerchandiseItemSeeder, tạo qua form thật),
 *   1 mặt hàng ngừng bán còn tồn, 1 mặt hàng có tồn cũ chưa phân chi nhánh; nhập kho 3 chi nhánh, xuất theo phiếu thu phụ thu
 *   đã duyệt, hoàn kho khi hủy hóa đơn, kiểm kê thừa / thiếu; tồn Sắp hết, Hết (0), Âm → việc "Nhập bù sách" tự giao Admin
 *   (1 việc tự hoàn thành khi nhập bù, 2 việc còn mở); 1 phiếu thu chờ duyệt sẽ làm kho âm thêm (cảnh báo ở màn duyệt).
 *
 * Gọi từ DemoCoverageSeeder (php artisan demo:luong); chạy được trên dữ liệu db:seed. Idempotent: đã có tin tuyển dụng
 * MARKER thì bỏ qua. Chỉ thêm dữ liệu (tài khoản khóa / nghỉ việc là tài khoản mới của seeder).
 */
class DemoCoverageOpsSeeder extends Seeder
{
    use InvokesControllersAsUser;

    /** Tin tuyển dụng đầu tiên: có rồi thì seeder đã chạy. */
    public const MARKER = '# Giáo viên tiếng Anh Fulltime (Young Learners)';

    /** Gắn vào ghi chú phiếu thu bán hàng của seeder. */
    public const TAG = '[demo-ops]';

    /** Tài khoản mẫu cần có: khóa => email. */
    private const STAFF = [
        'admin' => 'admin@menglish.edu.vn',
        'manager_cg' => 'manager@menglish.edu.vn',
        'manager_bd' => 'manager.bd@menglish.edu.vn',
        'cm_cg' => 'nva@menglish.edu.vn',
        'cm_bd' => 'giaovu2@menglish.edu.vn',
        'teacher_cg' => 'nguyenvanan@menglish.edu.vn',
        'teacher_bd' => 'gv.cohuu2@menglish.edu.vn',
        'native' => 'gv.native1@menglish.edu.vn',
    ];

    private const CLASSES = ['DEMO-CG-FAM1', 'DEMO-CG-FAM0', 'DEMO-BD-FAM1', 'DEMO-BD-FAM0'];

    /**
     * Tin tuyển dụng: khóa => [tiêu đề, bộ phận, chi nhánh (null = toàn hệ thống), loại, lương, mô tả, yêu cầu, quyền lợi,
     * đăng D-n, hạn nộp (số ngày tới, âm = đã qua), đóng tin D-n (null = còn mở)].
     */
    private const JOBS = [
        'teacher' => [self::MARKER, 'Đào tạo', 'CG', 'Full-time', '15 – 22 triệu',
            'Giảng dạy lớp Starters / Movers / Flyers theo giáo trình Cambridge, soạn giáo án, chấm bài và phản hồi phụ huynh hằng tháng.',
            'IELTS 7.5+ hoặc tương đương; có TESOL/CELTA; tối thiểu 1 năm dạy trẻ 6–12 tuổi.',
            'Lương tháng 13, BHXH đầy đủ, đào tạo nội bộ hằng quý, thưởng KPI giữ học sinh.', 28, 17, null],
        'assistant' => ['# Trợ giảng tiếng Anh Part-time ca tối', 'Đào tạo', 'BD', 'Part-time', '60.000 – 80.000đ/giờ',
            'Hỗ trợ GV trên lớp, điểm danh, chấm bài tập về nhà, kèm học viên yếu sau giờ học.',
            'Sinh viên năm 3 trở lên ngành Ngôn ngữ Anh / Sư phạm Anh; nhận ca 18:00–21:00 ít nhất 3 buổi/tuần.',
            'Được đào tạo lên GV part-time sau 6 tháng.', 16, 12, null],
        'sales' => ['# Chuyên viên Tư vấn Tuyển sinh', 'Tuyển sinh', 'DD', 'Full-time', '9 triệu + hoa hồng',
            'Tư vấn khóa học cho phụ huynh / học viên, chăm sóc lead từ Facebook / Zalo, đặt lịch test đầu vào và chốt đăng ký.',
            'Kinh nghiệm sales giáo dục là lợi thế; giao tiếp tốt; làm ca xoay cuối tuần.',
            'Hoa hồng theo bậc, thưởng nóng theo tháng.', 45, -4, null],
        'native' => ['# Giáo viên bản ngữ IELTS (Native Speaker)', 'Đào tạo', null, 'Part-time', 'Thỏa thuận (theo giờ)',
            'Teach IELTS Speaking & Writing for Foundation / Intensive classes across M English branches.',
            'Native speaker, bachelor degree, TEFL/CELTA, 2+ years IELTS teaching.',
            'Work permit support, flexible schedule.', 70, -25, 30],
        'intern' => ['# Thực tập sinh Marketing nội dung', 'Marketing', 'CG', 'Thực tập', 'Hỗ trợ 3 triệu/tháng',
            'Viết bài fanpage, quay dựng video ngắn hoạt động lớp học, hỗ trợ sự kiện tuyển sinh.',
            'Sinh viên năm cuối ngành Marketing / Truyền thông; biết Canva, CapCut.',
            'Có xác nhận thực tập, cơ hội lên nhân viên chính thức.', 5, 25, null],
    ];

    /**
     * CV nộp qua cổng tuyển dụng: [tin (null = ứng tuyển tự do), họ tên, email, SĐT, chi nhánh mong muốn, nộp D-n,
     * các bước [trạng thái, D-n, ghi chú]].
     */
    private const CANDIDATES = [
        ['teacher', 'Nguyễn Thu Trang', 'thutrang.nguyen@example.com', '0912345601', 'CG', 1, []],
        ['teacher', 'Phạm Quang Huy', 'quanghuy.pham@example.com', '0912345602', 'CG', 6, [
            ['reviewing', 5, 'IELTS 7.5, 1 năm dạy trung tâm. Hẹn demo lesson tuần sau.'],
        ]],
        ['teacher', 'Đỗ Thị Hồng Nhung', 'hongnhung.do@example.com', '0912345603', 'CG', 12, [
            ['reviewing', 11, 'Hồ sơ tốt, CELTA 2024.'],
            ['interviewed', 8, 'Demo lesson Starters Unit 3: quản lý lớp tốt, phát âm chuẩn. Chờ chốt lương.'],
        ]],
        ['teacher', 'Lê Đức Anh', 'ducanh.le@example.com', '0912345604', 'CG', 20, [
            ['reviewing', 19, 'IELTS 8.0, 3 năm dạy YLE.'],
            ['interviewed', 16, 'Phỏng vấn + demo đạt, Trưởng Học thuật đồng ý.'],
            ['accepted', 12, 'Đã gửi offer 18 triệu, nhận việc đầu tháng sau tại Cầu Giấy.'],
        ]],
        ['teacher', 'Trần Minh Châu', 'minhchau.tran@example.com', '0912345605', 'CG', 18, [
            ['reviewing', 17, 'IELTS 6.5, chưa có TESOL.'],
            ['rejected', 15, 'Chưa đạt yêu cầu IELTS 7.5 và chứng chỉ giảng dạy. Đã gửi email cảm ơn.'],
        ]],
        ['assistant', 'Vũ Ngọc Ánh', 'ngocanh.vu@example.com', '0912345606', 'BD', 2, []],
        ['assistant', 'Hoàng Gia Linh', 'gialinh.hoang@example.com', '0912345607', 'BD', 10, [
            ['reviewing', 9, 'SV năm 3 ĐH Hà Nội, rảnh 4 tối/tuần.'],
            ['interviewed', 7, 'Phỏng vấn vòng 1 ổn, hẹn thử việc 1 buổi.'],
            ['rejected', 5, 'Không đến buổi thử việc, không liên lạc được.'],
        ]],
        ['assistant', 'Bùi Thanh Tâm', 'thanhtam.bui@example.com', '0912345608', 'BD', 14, [
            ['reviewing', 13, 'SV Sư phạm Anh năm 4.'],
            ['interviewed', 11, 'Thử việc lớp Starters BD tốt.'],
            ['accepted', 9, 'Nhận ca tối T2–T4–T6 tại Ba Đình.'],
        ]],
        ['sales', 'Ngô Phương Thảo', 'phuongthao.ngo@example.com', '0912345609', 'DD', 9, [
            ['reviewing', 3, 'Có 2 năm sales khóa học online, hẹn phỏng vấn.'],
        ]],
        ['sales', 'Đặng Văn Long', 'vanlong.dang@example.com', '0912345610', 'DD', 30, [
            ['rejected', 28, 'Không phù hợp ca xoay cuối tuần.'],
        ]],
        ['native', 'James Carter', 'james.carter@example.com', '0912345611', null, 55, [
            ['reviewing', 52, 'CELTA, 4 years IELTS in Vietnam.'],
            ['interviewed', 45, 'Trial class IELTS Intensive: very good feedback.'],
            ['accepted', 32, 'Signed part-time contract, 12 hours/week.'],
        ]],
        [null, 'Lý Hải Yến', 'haiyen.ly@example.com', '0912345612', 'DD', 3, []],
    ];

    /**
     * Tài khoản mới: khóa => [mã NV, tên, email, SĐT, chi nhánh chính, vai trò, tạo D-n, loại HĐ, HĐ hết hạn (số ngày tới,
     * âm = đã hết), chi nhánh dạy thêm].
     */
    private const USERS = [
        'locked' => ['ME-0381', 'Đỗ Minh Hiếu', 'sale.minhhieu@menglish.edu.vn', '0900000381', 'BD', Roles::SALES_CONSULTANT, 150, 'Hợp đồng lao động 1 năm', 215, []],
        'resigned' => ['ME-0382', 'Phan Thị Lan Anh', 'gv.lananh@menglish.edu.vn', '0900000382', 'CG', Roles::TEACHER_PARTTIME, 200, 'Hợp đồng cộng tác', -20, []],
        'multi' => ['ME-0383', 'Trịnh Quốc Bảo', 'gv.quocbao@menglish.edu.vn', '0900000383', 'CG', Roles::TEACHER_PARTTIME, 90, 'Hợp đồng cộng tác', 275, ['BD', 'DD']],
    ];

    /**
     * Order học liệu: [loại, lớp, người đặt (khóa STAFF), tiêu đề, số lượng, ngày dùng (số ngày tới, âm = đã qua), tạo, nhận,
     * xong (giờ so với hạn xử lý; chuỗi 'Nh' = N giờ trước bây giờ; null = không), kết quả, ghi chú / lý do, job quá hạn chạy
     * (giờ so với hạn; null = không)].
     */
    private const ORDERS = [
        [MaterialOrder::CATEGORY_PROPS, 'DEMO-CG-FAM1', 'teacher_cg', 'Bộ thẻ hình con vật + bóng nhựa cho trò chơi Unit 2', 1, -12, -50, -46, -26,
            MaterialOrder::STATUS_DONE, 'Đã chuẩn bị đủ, để ở tủ đạo cụ phòng 201.', null],
        [MaterialOrder::CATEGORY_PRINTING, 'DEMO-BD-FAM1', 'teacher_bd', 'In 15 phiếu bài tập Unit 4 (A4, 2 mặt)', 15, -9, -30, -28, -22,
            MaterialOrder::STATUS_DONE, 'Đã in, để ở ngăn lớp tại quầy Học vụ.', null],
        [MaterialOrder::CATEGORY_PRINTING, 'DEMO-CG-FAM0', 'teacher_cg', 'In 20 bộ đề kiểm tra giữa khóa', 20, -5, -48, null, -45,
            MaterialOrder::STATUS_REJECTED, 'Đề giữa khóa Học thuật đã in sẵn theo order học liệu học thuật — nhận tại phòng Học vụ, không in lại.', null],
        [MaterialOrder::CATEGORY_FOREIGN_TEACHER, 'DEMO-CG-FAM1', 'native', 'Flashcards + realia for Unit 5 (Food) — native lesson', null, -25, -80, -54, -30,
            MaterialOrder::STATUS_DONE, 'Đã mua hoa quả nhựa, in flashcards màu khổ A5.', null],
        [MaterialOrder::CATEGORY_PROPS, 'DEMO-BD-FAM0', 'teacher_bd', 'Mượn loa bluetooth + micro cho buổi Show & Tell', 1, -2, -50, null, null,
            null, null, 1],
        [MaterialOrder::CATEGORY_PRINTING, 'DEMO-CG-FAM1', 'teacher_cg', 'Photo 12 bộ worksheet Phonics', 12, -4, -24, 2, 18,
            MaterialOrder::STATUS_DONE, 'Xử lý trễ do máy photo hỏng chiều hôm trước.', 1],
        [MaterialOrder::CATEGORY_PRINTING, 'DEMO-BD-FAM1', 'teacher_bd', 'In bổ sung 3 bộ đề cho học viên mới vào lớp', 3, -1, 3, 4, 5,
            MaterialOrder::STATUS_DONE, 'GV gửi sau giờ chốt, Học vụ vẫn in kịp tối hôm trước.', null],
        [MaterialOrder::CATEGORY_PROPS, 'DEMO-CG-FAM0', 'teacher_cg', 'Bộ đồ hóa trang Halloween (10 bộ)', 10, 6, '26h', '2h', null,
            null, 'Đã liên hệ shop, chờ giao hàng.', null],
        [MaterialOrder::CATEGORY_PRINTING, 'DEMO-BD-FAM1', 'teacher_bd', 'In 18 giấy khen cuối khóa', 18, 10, '4h', null, null,
            null, null, null],
        [MaterialOrder::CATEGORY_FOREIGN_TEACHER, 'DEMO-CG-FAM1', 'native', 'Board game "Guess Who" + sticker rewards for next month', null, 35, '3h', null, null,
            null, null, null],
    ];

    /** Đợt đồng bộ chấm công: [D-n, giờ, thiết bị, nguồn, chi nhánh, tổng dòng, thành công, lỗi, bỏ qua, trạng thái, mã lỗi, nội dung lỗi, dòng lỗi]. */
    private const SYNC_LOGS = [
        [21, '23:30', 'Máy chấm công vân tay (cũ) – Ba Đình', 'device', 'BD', 0, 0, 0, 0, 'error', null, 'Mất kết nối thiết bị (bản ghi trước khi nâng cấp).', null],
        [9, '23:30', 'AppSheet – Chấm công giáo viên', 'appsheet', 'CG', 46, 46, 0, 0, 'success', null, null, null],
        [8, '23:30', 'AppSheet – Chấm công giáo viên', 'appsheet', 'CG', 52, 49, 2, 1, 'partial', null, null, [
            ['employee_code' => 'GV-0492', 'employee_name' => 'Nguyễn Văn An', 'code' => 'SESSION_MISMATCH', 'message' => 'Ca 18:00 ngày chấm không khớp lịch lớp nào của GV.'],
            ['employee_code' => 'GV-0999', 'employee_name' => null, 'code' => 'EMPLOYEE_NOT_FOUND', 'message' => 'Không tìm thấy mã nhân viên GV-0999 trong hệ thống.'],
        ]],
        [8, '23:35', 'Máy chấm công FaceID – Ba Đình', 'device', 'BD', 38, 38, 0, 0, 'success', null, null, null],
        [7, '23:30', 'AppSheet – Chấm công giáo viên', 'appsheet', null, 0, 0, 0, 0, 'failed', 'APPSHEET_AUTH_401',
            'AppSheet API trả về 401 Unauthorized: Application Access Key đã hết hạn. Cấp lại key trong AppSheet > Manage > Integrations.', null],
        [6, '08:10', 'AppSheet – Chấm công giáo viên (chạy lại)', 'appsheet', null, 98, 98, 0, 0, 'success', null, null, null],
        [3, '23:30', 'Máy chấm công FaceID – Đống Đa', 'device', 'DD', 0, 0, 0, 0, 'failed', 'DEVICE_TIMEOUT',
            'Không kết nối được thiết bị 192.168.3.20:4370 sau 3 lần thử (timeout 30s).', null],
        [2, '23:30', 'AppSheet – Chấm công giáo viên', 'appsheet', 'CG', 40, 31, 1, 8, 'partial', null, null, [
            ['employee_code' => 'TA-0002', 'employee_name' => 'Mai Ngọc Trâm', 'code' => 'CHECKOUT_BEFORE_CHECKIN', 'message' => 'Giờ ra (17:05) sớm hơn giờ vào (17:45).'],
        ]],
        [1, '23:30', 'AppSheet – Chấm công giáo viên', 'appsheet', 'CG', 44, 44, 0, 0, 'success', null, null, null],
    ];

    /**
     * Danh mục hàng hóa (theo MerchandiseItemSeeder): mã => [tên, nhóm, ĐVT, giá bán, giá vốn, mô tả, tồn đầu Cầu Giấy,
     * tồn cũ chưa phân chi nhánh, đang bán].
     */
    private const ITEMS = [
        'BOOK-CAM-S1' => ['Bộ Giáo trình Cambridge Primary Stage 1', MerchandiseItem::CATEGORY_BOOK, 'Bộ', 300000, 210000, 'Bộ giáo trình chuẩn Cambridge dành cho học viên cấp độ 1 kèm audio.', 40, 0, true],
        'BOOK-CAM-S2' => ['Bộ Giáo trình Cambridge Primary Stage 2', MerchandiseItem::CATEGORY_BOOK, 'Bộ', 320000, 220000, 'Bộ sách chính khóa Cambridge cấp độ 2 phát triển 4 kỹ năng.', 30, 0, true],
        'WB-CAM-S3' => ['Sách bài tập Workbook Cambridge Stage 3', MerchandiseItem::CATEGORY_WORKBOOK, 'Cuốn', 150000, 90000, 'Sách bài tập thực hành bổ trợ ngữ pháp và từ vựng theo buổi học.', 50, 0, true],
        'BOOK-IELTS-FD' => ['Bộ Giáo trình IELTS Foundation Master', MerchandiseItem::CATEGORY_BOOK, 'Bộ', 450000, 300000, 'Giáo trình luyện thi IELTS Foundation bản quyền MEnglish biên soạn.', 20, 0, true],
        'UNI-POLO-S' => ['Áo Polo Đồng phục MEnglish (Size S)', MerchandiseItem::CATEGORY_UNIFORM, 'Chiếc', 200000, 130000, 'Thun cá sấu cotton 4 chiều, màu cam nhận diện thương hiệu.', 2, 0, true],
        'UNI-POLO-M' => ['Áo Polo Đồng phục MEnglish (Size M)', MerchandiseItem::CATEGORY_UNIFORM, 'Chiếc', 200000, 130000, 'Thun cá sấu cotton 4 chiều, màu cam nhận diện thương hiệu.', 20, 0, true],
        'UNI-POLO-XS25' => ['Áo Polo Đồng phục mẫu 2025 (Size XS)', MerchandiseItem::CATEGORY_UNIFORM, 'Chiếc', 180000, 120000, 'Mẫu cũ 2025, ngừng bán — xả nốt tồn tại Cầu Giấy.', 3, 0, false],
        'BAG-ME-STD' => ['Balo dây rút thể thao MEnglish', MerchandiseItem::CATEGORY_BACKPACK, 'Chiếc', 120000, 70000, 'Túi rút đa năng đựng tài liệu học tập.', 25, 0, true],
        'GIFT-BOT-01' => ['Bình giữ nhiệt MEnglish Eco 500ml', MerchandiseItem::CATEGORY_GIFT, 'Cái', 160000, 95000, 'Inox 304 giữ nhiệt 12h.', 12, 0, true],
        'STN-NOTE-01' => ['Combo Sổ tay từ vựng & Bút bi MEnglish', MerchandiseItem::CATEGORY_GIFT, 'Bộ', 60000, 30000, 'Sổ lò xo kẻ cột học từ vựng.', null, 100, true],
    ];

    /**
     * Xuất nhập kho theo thứ tự thời gian: [D-n, giờ, thao tác, chi nhánh, người làm, dữ liệu, ghi chú]. Thao tác:
     * import (dữ liệu: mã => SL; mã có tiền tố "legacy:" = phân bổ từ tồn cũ), count (mã => tồn thực tế), sale (khóa phiếu =>
     * [học viên thứ i của chi nhánh, mã => SL, duyệt?]), cancel (khóa phiếu cần hủy hóa đơn).
     */
    private const STOCK_STEPS = [
        [44, '10:00', 'import', 'CG', 'manager_cg', ['legacy:STN-NOTE-01' => 60], 'Phân bổ sổ tay về kho Cầu Giấy.'],
        [44, '10:30', 'import', 'BD', 'manager_bd', ['BOOK-CAM-S1' => 25, 'BOOK-CAM-S2' => 15, 'WB-CAM-S3' => 30, 'UNI-POLO-M' => 10, 'BAG-ME-STD' => 15, 'GIFT-BOT-01' => 3, 'legacy:STN-NOTE-01' => 30], 'Nhập kho đầu khóa K28 từ kho tổng.'],
        [43, '14:00', 'import', 'DD', 'admin', ['BOOK-CAM-S1' => 10, 'BOOK-IELTS-FD' => 6, 'UNI-POLO-M' => 8, 'BAG-ME-STD' => 6], 'Nhập kho chi nhánh Đống Đa.'],
        [30, '16:00', 'sale', 'CG', 'cm_cg', ['cg1' => [0, ['BOOK-CAM-S1' => 1, 'UNI-POLO-M' => 2], true]], 'Mua giáo trình + 2 áo đồng phục đầu khóa.'],
        [25, '10:00', 'sale', 'CG', 'cm_cg', ['cg2' => [1, ['UNI-POLO-S' => 1, 'BAG-ME-STD' => 1], true]], 'Mua áo size S + balo.'],
        [24, '09:30', 'cancel', 'CG', 'cm_cg', ['cg2'], 'Lập nhầm học viên (hàng đã giao cho em khác cùng lớp), hủy hóa đơn để lập lại phiếu đúng.'],
        [20, '15:00', 'sale', 'BD', 'cm_bd', ['bd1' => [0, ['GIFT-BOT-01' => 2, 'BOOK-CAM-S1' => 1], true]], 'Mua giáo trình + 2 bình giữ nhiệt làm quà.'],
        [15, '17:00', 'sale', 'CG', 'cm_cg', ['cg3' => [2, ['UNI-POLO-S' => 2], true]], 'Mua 2 áo size S (anh em sinh đôi).'],
        [12, '10:00', 'import', 'CG', 'manager_cg', ['UNI-POLO-S' => 20], 'Nhập 20 áo size S từ xưởng may.'],
        [10, '16:00', 'count', 'DD', 'admin', ['BOOK-IELTS-FD' => 4], 'Kiểm kê cuối tháng: 2 bộ ướt hỏng do dột mái kho.'],
        [10, '16:20', 'count', 'DD', 'admin', ['BAG-ME-STD' => 0], 'Kiểm kê: 6 balo đã phát cho lớp hè nhưng chưa ghi xuất.'],
        [8, '17:00', 'count', 'CG', 'manager_cg', ['WB-CAM-S3' => 52], 'Kiểm kê: phát hiện 2 cuốn ở kho phụ chưa ghi nhận.'],
        [6, '11:00', 'sale', 'BD', 'cm_bd', ['bd2' => [1, ['GIFT-BOT-01' => 2], true]], 'Mua 2 bình giữ nhiệt (giao trước, kho chưa đủ).'],
        [3, '15:30', 'sale', 'CG', 'cm_cg', ['cg4' => [3, ['BOOK-CAM-S2' => 1, 'WB-CAM-S3' => 1, 'STN-NOTE-01' => 2], true]], 'Mua sách Stage 2 + workbook + sổ tay.'],
        [1, '18:00', 'sale', 'BD', 'cm_bd', ['bd3' => [2, ['GIFT-BOT-01' => 1, 'UNI-POLO-M' => 1], false]], 'Mua bình giữ nhiệt + áo size M (chờ duyệt).'],
    ];

    private Carbon $realNow;

    /** @var array<string, User> */
    private array $staff = [];

    /** @var array<string, int> */
    private array $branches = [];

    /** @var array<string, ClassModel> */
    private array $classes = [];

    /** @var array<string, Collection<int, Student>> */
    private array $students = [];

    /** @var array<string, MerchandiseItem> */
    private array $items = [];

    /** @var array<string, TuitionReceipt> */
    private array $receipts = [];

    /** @var list<int> */
    private array $orderIds = [];

    public function run(): void
    {
        $staff = User::whereIn('email', self::STAFF)->get()->keyBy('email');
        $branches = Branch::whereIn('code', ['CG', 'BD', 'DD'])->pluck('id', 'code');
        $classes = ClassModel::whereIn('code', self::CLASSES)->get()->keyBy('code');
        $students = fn (string $prefix) => Student::where('status', 'studying')
            ->whereHas('currentClass', fn ($q) => $q->where('code', 'like', "DEMO-{$prefix}-%"))->orderBy('id')->get();
        $this->students = ['CG' => $students('CG'), 'BD' => $students('BD')];

        if ($staff->count() < count(self::STAFF) || $branches->count() < 3 || $classes->count() < count(self::CLASSES)
            || $this->students['CG']->count() < 4 || $this->students['BD']->count() < 3) {
            $this->command?->warn('DemoCoverageOpsSeeder: chưa có đủ tài khoản / chi nhánh / lớp / học viên demo — bỏ qua.');

            return;
        }
        if (JobPosting::where('title', self::MARKER)->exists()) {
            $this->command?->info('DemoCoverageOpsSeeder: đã có dữ liệu demo vận hành & nhân sự — bỏ qua.');

            return;
        }

        $this->staff = collect(self::STAFF)->map(fn (string $email) => $staff[$email])->all();
        $this->branches = $branches->map(fn ($id) => (int) $id)->all();
        $this->classes = $classes->all();
        $previousTestNow = Carbon::getTestNow();
        $this->realNow = now()->copy();

        try {
            DB::transaction(function () {
                $this->recruitment();
                $this->users();
                $this->materialOrders();
                $this->syncLogs();
                $this->merchandise();
            });
        } finally {
            Carbon::setTestNow($previousTestNow);
        }

        $this->command?->info('DemoCoverageOpsSeeder: '.JobPosting::where('title', 'like', '# %')->count().' tin tuyển dụng, '
            .CandidateCv::count().' CV, '.count($this->orderIds).' order học liệu, '.TimesheetSyncLog::count().' đợt đồng bộ, '
            .MerchandiseStockMovement::count().' dòng xuất nhập kho.');
    }

    /** Thời điểm D-$days lúc $time. */
    private function daysAgo(int $days, string $time): Carbon
    {
        return $this->realNow->copy()->subDays($days)->setTimeFromTimeString($time);
    }

    /** Thời điểm đã qua (trước "bây giờ" thật) thì đặt đồng hồ về đó và trả true. */
    private function travel(Carbon $at): bool
    {
        if ($at->gte($this->realNow)) {
            return false;
        }
        Carbon::setTestNow($at->copy());

        return true;
    }

    // ── Tuyển dụng ─────────────────────────────────────────────────────────────

    private function recruitment(): void
    {
        $jobs = [];
        foreach (self::JOBS as $key => [$title, $department, $branch, $type, $salary, $description, $requirements, $benefits, $postedAgo, $deadlineIn, $closedAgo]) {
            $this->travel($this->daysAgo($postedAgo, '09:30'));
            $poster = $branch === 'BD' ? $this->staff['manager_bd'] : $this->staff['manager_cg'];
            $this->asUser($poster, RecruitmentController::class, 'storeJob', array_filter([
                'title' => $title,
                'department' => $department,
                'branch_id' => $branch ? $this->branches[$branch] : null,
                'employment_type' => $type,
                'salary_range' => $salary,
                'location' => $branch ? Branch::find($this->branches[$branch])->name : 'Toàn hệ thống',
                'description' => $description,
                'requirements' => $requirements,
                'benefits' => $benefits,
                'deadline' => $this->realNow->copy()->addDays($deadlineIn)->toDateString(),
            ], fn ($v) => $v !== null));
            $jobs[$key] = JobPosting::where('title', $title)->latest('id')->firstOrFail();

            if ($closedAgo !== null && $this->travel($this->daysAgo($closedAgo, '17:00'))) {
                $this->asUser($poster, RecruitmentController::class, 'toggleJobStatus', [], ['id' => $jobs[$key]->id]);
            }
        }

        foreach (self::CANDIDATES as $i => [$job, $name, $email, $phone, $branch, $appliedAgo, $steps]) {
            $this->travel($this->daysAgo($appliedAgo, sprintf('%02d:%02d', 8 + $i % 12, 15 + $i)));
            $this->asUser($this->staff['admin'], RecruitmentController::class, 'portalSubmit', array_filter([
                'full_name' => $name,
                'email' => $email,
                'phone' => $phone,
                'job_posting_id' => $job ? $jobs[$job]->id : null,
                'applying_position' => $job ? $jobs[$job]->title : 'Giáo viên tiếng Anh THCS (ứng tuyển tự do)',
                'branch_id' => $branch ? $this->branches[$branch] : null,
                'portfolio_url' => $i % 3 === 0 ? 'https://www.linkedin.com/in/'.strtolower(str_replace(['.', '@example.com'], ['-', ''], $email)) : null,
                'cover_letter' => "Kính gửi Ban Nhân sự M English, tôi là {$name}, mong muốn được ứng tuyển vị trí này.",
            ], fn ($v) => $v !== null));
            $cv = CandidateCv::where('email', $email)->latest('id')->firstOrFail();

            $reviewer = $branch === 'BD' ? $this->staff['manager_bd'] : $this->staff['manager_cg'];
            foreach ($steps as [$status, $ago, $note]) {
                if ($this->travel($this->daysAgo($ago, '16:30'))) {
                    $this->asUser($reviewer, RecruitmentController::class, 'updateCvStatus', ['status' => $status, 'notes' => $note], ['id' => $cv->id]);
                }
            }
        }
        Carbon::setTestNow($this->realNow);
    }

    // ── Tài khoản: khóa, nghỉ việc, nhiều chi nhánh ───────────────────────────

    private function users(): void
    {
        foreach (self::USERS as $key => [$code, $name, $email, $phone, $branch, $role, $createdAgo, $contractType, $contractEndIn, $extraBranches]) {
            if (User::withTrashed()->where('email', $email)->orWhere('employee_code', $code)->exists()) {
                continue;
            }
            $this->travel($created = $this->daysAgo($createdAgo, '10:00'));
            $this->asUser($this->staff['admin'], UserController::class, 'store', [
                'employee_code' => $code,
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'branch_id' => $this->branches[$branch],
                'role' => $role,
                'password' => config('access.seed_password'),
                'contract_type' => $contractType,
                'contract_start_date' => $created->toDateString(),
                'contract_end_date' => $this->realNow->copy()->addDays($contractEndIn)->toDateString(),
                'graduation_school' => 'ĐH Ngoại ngữ – ĐHQG Hà Nội',
            ]);
            $user = User::where('email', $email)->firstOrFail();
            // Tài khoản demo đăng nhập được ngay (không bắt đổi mật khẩu lần đầu).
            $user->forceFill(['must_change_password' => false, 'email_verified_at' => $created])->saveQuietly();

            if ($key === 'locked' && $this->travel($this->daysAgo(9, '18:00'))) {
                $user->forceFill(['last_login_at' => $this->daysAgo(10, '08:05')])->saveQuietly();
                $this->asUser($this->staff['admin'], UserController::class, 'lock', [], ['user' => $user]);
            }
            if ($key === 'resigned' && $this->travel($this->daysAgo(-$contractEndIn, '17:30'))) {
                // Nghỉ việc khi hết HĐ: ngừng hoạt động (form nhân sự không có ô này — Admin tắt trực tiếp).
                $user->forceFill(['is_active' => false, 'last_login_at' => $this->daysAgo(-$contractEndIn, '09:00')])->save();
            }
            if ($extraBranches) {
                $user->branches()->attach(collect($extraBranches)->mapWithKeys(fn (string $b) => [$this->branches[$b] => ['created_at' => now(), 'updated_at' => now()]])->all());
            }
        }
        Carbon::setTestNow($this->realNow);
    }

    // ── Order học liệu ─────────────────────────────────────────────────────────

    private function materialOrders(): void
    {
        $service = app(MaterialOrderService::class);
        foreach (self::ORDERS as [$category, $classCode, $requester, $title, $quantity, $useIn, $created, $claim, $finish, $result, $note, $overdueAt]) {
            $class = $this->classes[$classCode];
            $use = $this->realNow->copy()->addDays($useIn)->startOfDay();
            $due = MaterialOrder::computeDueAt($category, $use);
            $at = fn (int|string $spec) => is_string($spec) ? $this->realNow->copy()->subHours((int) $spec) : $due->copy()->addHours($spec);

            if (! $this->travel($at($created))) {
                continue;
            }
            $order = $service->create($this->staff[$requester], [
                'category' => $category,
                'branch_id' => $class->branch_id,
                'class_id' => $class->id,
                'title' => $title,
                'description' => 'Dùng cho buổi học ngày '.$use->format('d/m/Y').'.',
                'quantity' => $quantity,
                'use_date' => $use->toDateString(),
            ]);
            $this->orderIds[] = $order->id;
            $processor = (int) $class->branch_id === $this->branches['BD'] ? $this->staff['cm_bd'] : $this->staff['cm_cg'];

            if ($overdueAt !== null && $this->travel($at($overdueAt))) {
                $this->markOverdue($service, $order);
            }
            if ($claim !== null && $this->travel($at($claim))) {
                $service->claim($order->refresh(), $processor, $finish === null ? $note : null);
            }
            if ($finish !== null && $this->travel($at($finish))) {
                $rejected = $result === MaterialOrder::STATUS_REJECTED;
                $service->finish($order->refresh(), $processor, $result, $rejected ? null : $note, $rejected ? $note : null);
            }
        }
        Carbon::setTestNow($this->realNow);
    }

    /**
     * Job "Quá hạn" (material-orders:mark-overdue) chạy lúc này: dùng service thật khi order quá hạn duy nhất là order của
     * seeder; nếu CSDL còn order cũ khác cũng đã quá hạn (không thuộc seeder) thì chỉ chuyển order này, báo người xử lý như job.
     */
    private function markOverdue(MaterialOrderService $service, MaterialOrder $order): void
    {
        $others = MaterialOrder::whereIn('status', [MaterialOrder::STATUS_PENDING, MaterialOrder::STATUS_PROCESSING])
            ->where('due_at', '<', now())->whereKeyNot($order->id)->exists();
        if (! $others) {
            $service->markOverdue();

            return;
        }
        $order->update(['status' => MaterialOrder::STATUS_OVERDUE]);
        foreach ($service->processors($order) as $user) {
            AdminNotification::create([
                'user_id' => $user->id,
                'type' => MaterialOrderService::NOTIFY_OVERDUE,
                'title' => "Order học liệu quá hạn: {$order->title}",
                'message' => "{$order->code} · ".MaterialOrder::CATEGORIES[$order->category].' · hạn '.$order->due_at->format('H:i d/m/Y').' — hãy xử lý sớm.',
                'data' => ['material_order_id' => $order->id, 'link' => route('material-orders.show', $order->id)],
                'is_read' => false,
            ]);
        }
    }

    // ── Lịch sử đồng bộ chấm công (chưa có tích hợp ghi bảng này → tạo trực tiếp) ──

    private function syncLogs(): void
    {
        foreach (self::SYNC_LOGS as [$ago, $time, $device, $source, $branch, $total, $matched, $failed, $skipped, $status, $code, $message, $rows]) {
            if (! $this->travel($this->daysAgo($ago, $time))) {
                continue;
            }
            TimesheetSyncLog::create([
                'branch_id' => $branch ? $this->branches[$branch] : null,
                'device_name' => $device,
                'device_ip' => $source === 'device' ? '192.168.'.(['CG' => 1, 'BD' => 2, 'DD' => 3][$branch]).'.20' : null,
                'source' => $source,
                'records_count' => $total,
                'matched_count' => $matched,
                'failed_count' => $failed,
                'skipped_count' => $skipped,
                'status' => $status,
                'error_code' => $code,
                'error_message' => $message,
                'error_rows' => $rows,
            ]);
        }
        Carbon::setTestNow($this->realNow);
    }

    // ── Kho hàng hóa ───────────────────────────────────────────────────────────

    private function merchandise(): void
    {
        $this->travel($this->daysAgo(45, '09:00'));
        foreach (self::ITEMS as $code => [$name, $category, $unit, $price, $cost, $description, $opening, $legacy, $active]) {
            if (! MerchandiseItem::withTrashed()->where('code', $code)->exists()) {
                $this->asUser($this->staff['admin'], MerchandiseItemController::class, 'store', array_filter([
                    'code' => $code,
                    'name' => $name,
                    'category' => $category,
                    'unit' => $unit,
                    'price' => $price,
                    'cost_price' => $cost,
                    'stock_quantity' => $opening ?? $legacy,
                    'stock_branch_id' => $opening !== null ? $this->branches['CG'] : null,
                    'is_active' => $active ? 1 : 0,
                    'description' => $description,
                ], fn ($v) => $v !== null));
            } elseif ($opening) {
                // Mặt hàng đã có sẵn (vd. đã chạy MerchandiseItemSeeder): không sửa, chỉ nhập kho Cầu Giấy.
                $this->stock('CG', 'admin', 'import', [$code => $opening], 'Nhập kho đầu kỳ.');
            }
            $this->items[$code] = MerchandiseItem::withTrashed()->where('code', $code)->firstOrFail();
        }

        foreach (self::STOCK_STEPS as [$ago, $time, $action, $branch, $by, $data, $note]) {
            if (! $this->travel($at = $this->daysAgo($ago, $time))) {
                continue;
            }
            match ($action) {
                'import', 'count' => $this->stock($branch, $by, $action, $data, $note),
                'sale' => $this->sale($branch, $by, $data, $note, $at),
                'cancel' => $this->cancelInvoice($by, $data[0], $note, $at),
            };
        }
        Carbon::setTestNow($this->realNow);
    }

    /** Nhập kho / kiểm kê qua modal Tồn kho theo chi nhánh. */
    private function stock(string $branch, string $by, string $type, array $lines, string $note): void
    {
        foreach ($lines as $code => $quantity) {
            $legacy = str_starts_with($code, 'legacy:');
            $item = $this->items[$legacy ? substr($code, 7) : $code] ?? MerchandiseItem::where('code', $code)->firstOrFail();
            if ($legacy && (int) $item->refresh()->stock_quantity < $quantity) {
                continue;
            }
            $this->asUser($this->staff[$by], MerchandiseStockController::class, 'store', array_filter([
                'type' => $type,
                'merchandise_item_id' => $item->id,
                'branch_id' => $this->branches[$branch],
                'quantity' => $quantity,
                'from_legacy' => $legacy ? 1 : null,
                'note' => $note,
            ], fn ($v) => $v !== null));
        }
    }

    /** Phiếu thu chỉ thu phụ thu hàng hóa (tiền mặt) do Học vụ lập; Admin duyệt sau 1,5 giờ → xuất kho tự động. */
    private function sale(string $branch, string $by, array $data, string $note, Carbon $at): void
    {
        foreach ($data as $key => [$studentIndex, $lines, $approve]) {
            $student = $this->students[$branch][$studentIndex];
            $total = collect($lines)->map(fn (int $qty, string $code) => (float) $this->items[$code]->price * $qty)->sum();
            $notes = "{$note} ".self::TAG;
            $this->asUser($this->staff[$by], TuitionController::class, 'storeReceipt', [
                'student_id' => $student->id,
                'amount' => $total,
                'tuition_amount' => 0,
                'surcharge_amount' => $total,
                'payment_method' => 'cash',
                'paper_invoice_number' => 'HDG-KHO-'.strtoupper($key),
                'payer_name' => $student->parent_name ?: $student->name,
                'collected_items' => collect($lines)->map(fn (int $qty, string $code) => ['id' => $this->items[$code]->id, 'quantity' => $qty])->values()->all(),
                'notes' => $notes,
                'submit_action' => 'submit',
            ]);
            $receipt = TuitionReceipt::where('student_id', $student->id)->where('notes', $notes)->latest('id')->firstOrFail();
            if ($approve && $this->travel($at->copy()->addMinutes(90))) {
                $this->asUser($this->staff['admin'], TuitionController::class, 'approveReceiptAction', [], ['id' => $receipt->id]);
            }
            $this->receipts[$key] = $receipt->refresh();
        }
    }

    /** Học vụ yêu cầu hủy hóa đơn phiếu bán hàng, Admin duyệt sau 2 giờ → hoàn kho. */
    private function cancelInvoice(string $by, string $key, string $reason, Carbon $at): void
    {
        $receipt = $this->receipts[$key]->refresh();
        if ($receipt->status !== TuitionReceipt::STATUS_APPROVED) {
            return;
        }
        $this->asUser($this->staff[$by], TuitionController::class, 'storeInvoiceCancellation', [
            'invoice_number' => $receipt->invoice_number,
            'amount' => (float) $receipt->amount,
            'reason' => $reason,
        ]);
        if ($this->travel($at->copy()->addHours(2))) {
            $cancellation = InvoiceCancellation::where('tuition_receipt_id', $receipt->id)->where('status', 'pending')->firstOrFail();
            $this->asUser($this->staff['admin'], TuitionController::class, 'approveInvoiceCancellation', [], ['id' => $cancellation->id]);
        }
    }
}
