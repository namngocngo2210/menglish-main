<?php

/*
 * Hướng dẫn cho vai trò Quản lý cơ sở (xem App\Support\Guide\UserGuide và cấu trúc mục trong resources/guides/chung.php).
 * Tập trung vào việc vận hành chi nhánh: học phí & công nợ, thu chi, kho, chấm công, vi phạm, KPI, lương, báo cáo.
 */
return [
    'label' => 'Quản lý cơ sở',
    'icon' => 'apartment',
    'summary' => 'Quản lý cơ sở điều hành một chi nhánh: theo dõi học phí và công nợ, lập phiếu thu, ghi khoản chi, quản lý kho, đối soát chấm công, chốt vi phạm, duyệt phiếu KPI và xem doanh thu của chi nhánh. Các màn tuyển sinh, học viên, lớp học dùng giống hướng dẫn Học vụ.',
    'sections' => [
        [
            'id' => 'tong-quan',
            'title' => 'Tổng quan chi nhánh mỗi sáng',
            'icon' => 'dashboard',
            'menu' => 'Tổng quan',
            'route' => 'dashboard',
            'intro' => 'Trang đầu tiên sau khi đăng nhập cho biết tình hình chi nhánh: doanh thu tháng, học viên, lead mới, lớp đang chạy, việc quá hạn và hàng chờ cần xử lý.',
            'images' => [
                ['src' => 'quan-ly/tong-quan.webp', 'caption' => 'Tổng quan chi nhánh và hàng chờ cần xử lý'],
            ],
            'steps' => [
                'Bấm **Tổng quan** ở đầu menu trái. Dòng "Phạm vi" cho biết bạn đang xem chi nhánh nào.',
                'Xem khối **Hàng chờ cần xử lý**: việc chờ xác nhận, phiếu thu chờ duyệt, ticket đang mở, lịch hẹn test hôm nay. Bấm vào một ô để mở danh sách tương ứng.',
                'Khối **Việc quá hạn cần xử lý** liệt kê việc đã trễ hạn và người phụ trách. Bấm **Xem danh sách** để xem hết.',
                'Kéo xuống phần **Chất lượng giảng dạy** để so sánh chuyên cần, bài về nhà, điểm trung bình, ngày công và vi phạm của từng giáo viên trong tháng.',
            ],
        ],
        [
            'id' => 'cong-no',
            'title' => 'Công nợ học viên và lập phiếu thu',
            'icon' => 'account_balance_wallet',
            'menu' => 'Tài chính → Học phí → Công nợ học viên',
            'route' => 'tuition.students',
            'intro' => 'Sổ toàn bộ khoản học phí của chi nhánh: tổng phải thu, đã thu, còn nợ và hạn nộp của từng học viên. Từ đây bạn lập phiếu thu khi phụ huynh đóng tiền.',
            'images' => [
                ['src' => 'quan-ly/cong-no.webp', 'caption' => 'Sổ công nợ học viên'],
                ['src' => 'quan-ly/lap-phieu-thu.webp', 'caption' => 'Lập phiếu thu học phí'],
            ],
            'steps' => [
                'Vào **Học phí → Công nợ học viên**. Lọc theo lớp hoặc trạng thái (**Chưa nộp**, **Đang nợ (Đã cọc)**, **Quá hạn**) để tìm nhanh.',
                'Bấm biểu tượng tiền ở cột **Thao tác** của khoản cần thu, hoặc bấm **Lập phiếu thu** ở góc phải để tự chọn học viên.',
                'Kiểm tra khoản học phí, số buổi thu đợt này, ưu đãi, phụ thu (nếu có) và chọn hình thức thanh toán.',
                'Đính kèm ảnh chuyển khoản hoặc nhập số hóa đơn giấy với tiền mặt.',
                'Bấm **Lưu phiếu thu & Gửi duyệt**. Chưa đủ thông tin thì bấm **Lưu nháp** để làm tiếp sau.',
            ],
            'tips' => [
                'Phiếu thu chỉ gạch công nợ và tính doanh thu sau khi Admin duyệt. Phiếu bị từ chối sẽ hiện lý do, bạn sửa rồi bấm **Lưu & Gửi duyệt lại**.',
                'Tiền mặt phải nộp về tài khoản công ty trong ngày. Phiếu sẽ có nhãn "Chưa nộp về TK" cho tới khi Admin xác nhận.',
                'Có nhiều khoản cũ cần nhập một lần: dùng nút **Nhập Excel**.',
            ],
        ],
        [
            'id' => 'nhac-phi',
            'title' => 'Đôn đốc học phí quá hạn',
            'icon' => 'notifications_active',
            'menu' => 'Tài chính → Học phí → Quá hạn & Nhắc phí',
            'route' => 'tuition.overdue',
            'intro' => 'Danh sách khoản đã quá hạn và sắp đến hạn trong 14 ngày tới, để bạn gọi phụ huynh, gửi nhắc và báo cáo Admin các ca khó thu.',
            'images' => [
                ['src' => 'quan-ly/nhac-phi.webp', 'caption' => 'Nhóm quá hạn: lập phiếu thu, gửi nhắc, thao tác khác'],
            ],
            'steps' => [
                'Vào tab **Quá hạn & Nhắc phí**. Nhóm **Quá hạn nghiêm trọng (≥ 7 ngày)** là các ca bắt buộc liên hệ trực tiếp.',
                'Phụ huynh đã đóng tiền: bấm **Lập phiếu thu** ngay trên thẻ học viên.',
                'Bấm **Gửi nhắc nợ** để gửi nhắc hạn học phí.',
                'Bấm nút ba chấm, chọn **Xác nhận đã liên hệ**, ghi nội dung trao đổi rồi bấm **Lưu liên hệ**.',
                'Ca khó thu: bấm nút ba chấm, chọn **Báo cáo Admin**, ghi tình hình và đề xuất rồi bấm **Gửi báo cáo**.',
            ],
            'tips' => [
                'Kéo xuống nhóm **Sắp đến hạn** để nhắc trước, tránh để khoản rơi vào quá hạn.',
            ],
        ],
        [
            'id' => 'thu-chi',
            'title' => 'Ghi khoản chi vận hành',
            'icon' => 'receipt',
            'menu' => 'Tài chính → Thu - Chi → Khoản chi vận hành',
            'route' => 'finance.expenses.index',
            'intro' => 'Sổ chi của chi nhánh: tiền thuê mặt bằng, điện nước, internet, văn phòng phẩm... Khoản chi được trừ vào doanh thu tạm tính của tháng.',
            'images' => [
                ['src' => 'quan-ly/thu-chi.webp', 'caption' => 'Sổ khoản chi vận hành'],
                ['src' => 'quan-ly/thu-chi-them.webp', 'caption' => 'Thêm khoản chi mới'],
            ],
            'steps' => [
                'Vào **Thu - Chi**. Chọn **Kỳ tháng** và chi nhánh để xem các khoản đã ghi.',
                'Bấm **Thêm khoản chi mới**.',
                'Nhập **Ngày chi**, **Nội dung khoản chi**, **Số tiền**, **Hình thức chi**, **Chi nhánh áp dụng**. Phân loại có thể để trống, hệ thống tự nhận diện theo nội dung.',
                'Ghi mã hóa đơn hoặc nhà cung cấp vào **Ghi chú & Thông tin chứng từ**, rồi bấm **Lưu khoản chi**.',
                'Ghi sai thì bấm biểu tượng bút để sửa hoặc thùng rác để xóa. Bấm **Xuất Excel** để gửi kế toán.',
            ],
            'tips' => [
                'Khoản chi được lưu ngay vào sổ, không cần duyệt. Hãy kiểm tra kỹ số tiền trước khi lưu.',
                'Dòng chi lương do hệ thống tự thêm khi kỳ lương đã chốt, không sửa ở đây.',
            ],
        ],
        [
            'id' => 'kho',
            'title' => 'Kho vật phẩm: nhập kho và kiểm kê',
            'icon' => 'warehouse',
            'menu' => 'Tài chính → Kho vật phẩm → Hàng hóa & tồn kho',
            'route' => 'merchandise.index',
            'intro' => 'Theo dõi số sách, đồng phục, quà tặng còn ở chi nhánh. Kho tự trừ khi phiếu thu kèm sách được duyệt và tự hoàn khi hóa đơn bị hủy.',
            'images' => [
                ['src' => 'quan-ly/kho.webp', 'caption' => 'Hàng hóa & tồn kho'],
                ['src' => 'quan-ly/kho-nhap.webp', 'caption' => 'Nhập kho hoặc kiểm kê một mặt hàng'],
            ],
            'steps' => [
                'Vào **Kho vật phẩm**. Bấm chip **Âm kho**, **Hết hàng** hoặc **Sắp hết** để xem mặt hàng cần bổ sung.',
                'Khi nhận hàng: bấm **Nhập kho**, chọn **Mặt hàng**, nhập **Số lượng nhập thêm** và ghi chú phiếu nhập, rồi bấm **Nhập kho**.',
                'Khi đếm lại kho: bấm **Kiểm kê**, chọn mặt hàng và nhập **Số tồn thực tế đếm được**, rồi bấm **Lưu kiểm kê**.',
                'Mỗi dòng có cột **Tồn tại** chi nhánh và hai nút nhỏ Nhập kho / Kiểm kê cho riêng mặt hàng đó. Bấm vào một dòng mặt hàng để xem lịch sử xuất nhập. Khối **Xuất nhập gần đây** ở cuối trang ghi các lần thay đổi mới nhất.',
            ],
            'tips' => [
                'Mặt hàng mới (sách, đồng phục...) và giá bán do Admin tạo, sửa ngay trên trang này. Bạn xem được giá, chỉ nhập kho và kiểm kê cho chi nhánh của mình.',
                '"Âm kho" nghĩa là đã giao cho học viên nhiều hơn số đã nhập: cần nhập bổ sung hoặc kiểm kê lại.',
            ],
        ],
        [
            'id' => 'cham-cong',
            'title' => 'Theo dõi chấm công nhân sự',
            'icon' => 'fingerprint',
            'menu' => 'Người dùng → Chấm công → Chấm công hằng ngày',
            'route' => 'staff-attendance.index',
            'intro' => 'Xem ai đã chấm vào, ra, ai đi muộn hoặc chưa chấm công tại chi nhánh, kèm ảnh và khoảng cách GPS của từng lần chấm.',
            'images' => [
                ['src' => 'quan-ly/cham-cong.webp', 'caption' => 'Chấm công theo ngày'],
                ['src' => 'quan-ly/cham-cong-chi-tiet.webp', 'caption' => 'Ảnh và vị trí của một lần chấm công'],
            ],
            'steps' => [
                'Vào **Chấm công → Chấm công hằng ngày**. Tab **Theo ngày** hiện danh sách nhân sự của ngày đang chọn.',
                'Đổi ngày hoặc lọc **Tình trạng** (**Đi muộn**, **Chưa chấm công**, **Nghỉ có phép**) rồi bấm **Lọc**.',
                'Bấm vào một dòng đã chấm để xem ảnh lúc vào, lúc ra, khoảng cách tới cơ sở và vị trí trên bản đồ.',
                'Bấm **Tổng hợp tháng** để xem số công của từng người trong cả tháng.',
            ],
            'tips' => [
                'Đi muộn quá số phút cho phép sẽ tự lập biên bản vi phạm, bạn chốt ở mục Vi phạm & Phạt.',
                'Đơn bổ sung công, xin nghỉ, đi muộn, về sớm của nhân sự do Admin duyệt.',
            ],
        ],
        [
            'id' => 'gio-day',
            'title' => 'Đối soát giờ dạy và chốt bảng công giáo viên',
            'icon' => 'co_present',
            'menu' => 'Người dùng → Chấm công → Giờ dạy giáo viên',
            'route' => 'payroll.timesheets.teachers',
            'intro' => 'Trước khi tính lương, bạn rà từng ca dạy của giáo viên, trợ giảng: duyệt ca hợp lệ, từ chối ca sai, chấm tay khi có sự cố rồi chốt bảng công.',
            'images' => [
                ['src' => 'quan-ly/gio-day.webp', 'caption' => 'Ca dạy trong kỳ: duyệt, từ chối, chốt bảng công'],
            ],
            'steps' => [
                'Vào **Chấm công → Giờ dạy giáo viên**. Lọc theo kỳ lương, lớp, giáo viên hoặc trạng thái **Chờ đối soát**.',
                'Khối **Chấm công theo lịch** liệt kê buổi học của ngày đang chọn. Bấm **Xác nhận** để tính công theo giờ lịch cho giáo viên dự kiến.',
                'Ở bảng **Ca dạy trong kỳ**, bấm **Duyệt** với ca đúng. Ca sai thì bấm **Từ chối**, nhập lý do rồi bấm **Từ chối ca dạy**.',
                'Giáo viên quên check-in hoặc chi nhánh mất mạng: bấm **Chấm công thủ công**, chọn nhân viên, lớp, ngày, giờ vào, giờ ra, ghi lý do rồi bấm **Lưu chấm công**.',
                'Tick các ca đã kiểm tra rồi bấm **Chốt bảng công**.',
            ],
            'tips' => [
                'Bấm biểu tượng bút ở cuối dòng để chỉnh tay giờ vào, giờ ra của một ca trước khi duyệt.',
                'Ca thuộc kỳ lương đã chốt hiện "Đã khóa" và không sửa được nữa.',
            ],
        ],
        [
            'id' => 'vi-pham',
            'title' => 'Chốt vi phạm và mức phạt',
            'icon' => 'gavel',
            'menu' => 'Người dùng → Lương & Phạt → Vi phạm & Phạt',
            'route' => 'penalties.index',
            'intro' => 'Quy trình xử lý vi phạm: ghi nhận, nhân sự giải trình, Quản lý chốt lỗi và mức phạt, nhân sự nộp phạt trong 2 ngày (quá hạn trừ lương), rồi ghi nhận khắc phục. Quản lý chốt các lỗi vận hành, nội quy.',
            'images' => [
                ['src' => 'quan-ly/vi-pham.webp', 'caption' => 'Danh sách vi phạm và nút Chốt lỗi'],
            ],
            'steps' => [
                'Vào **Lương & Phạt → Vi phạm & Phạt**. Các thẻ đầu trang cho biết số biên bản chờ giải trình, chờ chốt, đã chốt phạt và quá hạn.',
                'Lập biên bản mới: bấm **Ghi nhận vi phạm mới**, chọn **Nhân sự vi phạm**, **Loại lỗi**, **Lỗi vi phạm**, thời điểm, tải **Bằng chứng vi phạm** rồi bấm **Ghi nhận**.',
                'Với biên bản ở bước Ghi nhận, bấm **Chốt lỗi**, xem giải trình rồi bấm **Chốt**. Tiếp theo bấm **Chốt mức phạt**, nhập **Số tiền phạt (VNĐ)** và bấm **Chốt**.',
                'Khi nhân sự nộp phạt trực tiếp, bấm **Đánh dấu đã nộp**. Sau đó bấm **Ghi nhận khắc phục** để đóng biên bản.',
                'Lỗi không đáng phạt: bấm nút ba chấm, chọn **Đóng - không phạt** hoặc **Hủy vi phạm**.',
            ],
            'tips' => [
                'Biên bản đi muộn được hệ thống tự lập từ chấm công. Lỗi chuyên môn, giảng dạy do Học thuật chốt.',
                'Khoản phạt quá hạn nộp sẽ tự trừ vào bảng lương cuối tháng. Hãy chốt biên bản trước hạn chốt lỗi của kỳ lương.',
            ],
        ],
        [
            'id' => 'kpi',
            'title' => 'Duyệt phiếu KPI tháng',
            'icon' => 'insights',
            'menu' => 'Báo cáo → Nhân sự & KPI → Phiếu KPI tháng',
            'route' => 'kpi.monthly',
            'intro' => 'Mỗi nhân sự có một phiếu KPI tự tạo đầu tháng theo tiêu chí của vai trò. Bạn điền số liệu còn thiếu và duyệt phiếu để thưởng KPI vào bảng lương.',
            'images' => [
                ['src' => 'quan-ly/kpi.webp', 'caption' => 'Danh sách phiếu KPI tháng'],
                ['src' => 'quan-ly/kpi-phieu.webp', 'caption' => 'Mở phiếu: điền số liệu, Duyệt hoặc Không duyệt'],
            ],
            'steps' => [
                'Vào **Nhân sự & KPI → Phiếu KPI tháng**. Chọn **Kỳ lương**, vai trò, cơ sở rồi bấm **Xem**.',
                'Bấm vào dòng của nhân sự để mở phiếu.',
                'Điền vào các ô **Số liệu** còn trống. Cột **Mức đạt** và **Thưởng** tự tính lại.',
                'Bấm **Duyệt** khi phiếu đã đủ số liệu. Chưa đạt yêu cầu thì bấm **Không duyệt**, nhập lý do rồi **Xác nhận không duyệt**.',
            ],
            'tips' => [
                'Nút **Duyệt** chỉ mở từ ngày cuối tháng. Trước đó bạn vẫn điền số liệu hoặc trả phiếu được.',
                'Phiếu chưa chốt sẽ chặn Admin chốt bảng lương, nên hãy duyệt xong ngay cuối tháng.',
                'Tab **Xếp hạng KPI & Thưởng** cho xem bảng thi đua KPI giữ học sinh của chi nhánh.',
            ],
        ],
        [
            'id' => 'doanh-thu',
            'title' => 'Doanh thu tạm tính của chi nhánh',
            'icon' => 'query_stats',
            'menu' => 'Báo cáo → Doanh thu → Doanh thu tạm tính',
            'route' => 'finance.reports.revenue',
            'intro' => 'Tổng thu từ phiếu thu đã duyệt trừ chi phí vận hành, cho biết chi nhánh lãi hay lỗ tạm tính trong tháng.',
            'images' => [
                ['src' => 'quan-ly/doanh-thu.webp', 'caption' => 'Báo cáo doanh thu tạm tính của chi nhánh'],
            ],
            'steps' => [
                'Vào **Doanh thu** ở nhóm Báo cáo trên menu trái.',
                'Chọn **Kỳ tháng** rồi bấm **Lọc**.',
                'Xem **Tổng thu thực tế**, **Chi phí vận hành** và **Doanh thu tạm tính**, kèm so sánh với tháng trước.',
                'Kéo xuống xem cơ cấu nguồn thu và chi. Bấm **Xuất báo cáo** để tải file.',
            ],
            'tips' => [
                'Phiếu thu chờ duyệt chưa được tính. Nếu số thấp bất thường, kiểm tra xem còn phiếu nào đang chờ Admin duyệt.',
            ],
        ],
        [
            'id' => 'nguoi-dung',
            'title' => 'Tài khoản nhân sự và học viên của chi nhánh',
            'icon' => 'manage_accounts',
            'menu' => 'Người dùng → Người dùng & Tài khoản',
            'route' => 'users.index',
            'intro' => 'Xem tài khoản của chi nhánh, khóa tài khoản người đã nghỉ, đặt lại mật khẩu khi có người quên và gán vai trò kiêm nhiệm.',
            'images' => [
                ['src' => 'quan-ly/nguoi-dung.webp', 'caption' => 'Vô hiệu hóa, Vai trò & kiêm nhiệm, Đặt lại mật khẩu'],
            ],
            'steps' => [
                'Vào **Người dùng & Tài khoản**. Tìm theo tên, email, số điện thoại hoặc lọc theo vai trò, trạng thái.',
                'Bấm biểu tượng con mắt để xem hồ sơ nhanh.',
                'Bấm biểu tượng cấm để **Vô hiệu hóa** tài khoản. Bấm lại để kích hoạt lại.',
                'Bấm nút ba chấm, chọn **Đặt lại mật khẩu** khi nhân sự hoặc học viên quên mật khẩu, hoặc **Vai trò & kiêm nhiệm** để thêm vai trò.',
            ],
            'tips' => [
                'Tạo tài khoản mới và sửa hợp đồng, lương do Admin làm. Gửi Admin thông tin nhân sự mới để được tạo tài khoản.',
            ],
        ],
        [
            'id' => 'cai-dat-luong',
            'title' => 'Đơn giá giáo viên và mốc hoa hồng',
            'icon' => 'price_change',
            'menu' => 'Cài đặt → Lương → Đơn giá giáo viên / Mốc hoa hồng / Thưởng',
            'route' => 'payroll.config.teacher-rates',
            'intro' => 'Đơn giá theo buổi của từng giáo viên, trợ giảng và các mốc hoa hồng là đầu vào để tính lương cuối tháng. Bảng lương của kỳ do Admin chốt.',
            'images' => [
                ['src' => 'quan-ly/don-gia.webp', 'caption' => 'Cấu hình đơn giá giáo viên'],
            ],
            'steps' => [
                'Bấm **Cài đặt** ở cuối menu trái, chọn **Đơn giá giáo viên**.',
                'Xem đơn giá đang hiệu lực của từng người. Bấm **Xem** để xem lịch sử các lần đổi giá.',
                'Bấm **Cập nhật đơn giá**, chọn **Giáo viên**, **Ngày hiệu lực từ**, **Đơn vị tính** và **Mức đơn giá mới**, ghi lý do rồi bấm **Cập nhật đơn giá mới**.',
                'Bấm **Cấu hình hoa hồng** (hoặc mục **Mốc hoa hồng / Thưởng**) để chỉnh các mốc thưởng.',
            ],
            'tips' => [
                'Đổi giá tạo phiên bản mới có ngày hiệu lực. Buổi dạy trước ngày đó vẫn tính theo giá cũ.',
                'Muốn lương đúng: đối soát giờ dạy, chốt vi phạm và duyệt phiếu KPI đúng hạn. Lương của chính bạn xem ở **Của tôi → Lương của tôi**.',
            ],
        ],
    ],
];
