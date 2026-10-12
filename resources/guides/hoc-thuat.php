<?php

// Hướng dẫn cho vai trò Học thuật (Trưởng học thuật). Cấu trúc mỗi mục: xem resources/guides/chung.php.
return [
    'label' => 'Học thuật',
    'icon' => 'auto_stories',
    'summary' => 'Học thuật soạn và giao giáo trình theo chặng, theo dõi đề xuất của giáo viên, lo đề và kết quả Big Test, dự giờ, chốt lỗi chuyên môn, quản lý dự án soạn sách và nộp báo cáo tuần.',
    'sections' => [
        [
            'id' => 'tong-quan-hoc-thuat',
            'title' => 'Tổng quan học thuật và hàng chờ',
            'icon' => 'dashboard',
            'menu' => 'Tổng quan',
            'route' => 'dashboard',
            'intro' => 'Trang mở ra ngay sau khi đăng nhập. Bạn thấy số lớp đang chạy, đề xuất giáo trình đang chờ, Big Test sắp tới và những việc cần xử lý trong ngày.',
            'images' => [
                ['src' => 'hoc-thuat/tong-quan.webp', 'caption' => 'Khối Hàng chờ cần xử lý trên trang Tổng quan'],
            ],
            'steps' => [
                'Bấm **Tổng quan** ở đầu menu trái. Phần **Tổng quan học thuật** hiện 3 ô số liệu: lớp đang chạy, đề xuất giáo trình / giãn tiến độ chờ duyệt, Big Test trong 14 ngày tới.',
                'Khối **Hàng chờ cần xử lý** gom 3 loại việc: **Đề xuất sửa giáo trình**, **Order đề Big Test chờ duyệt**, **Đợt Big Test có kết quả chờ duyệt**. Bấm vào một ô để mở đúng danh sách.',
                'Khối **Big Test sắp tới** và **Lịch hẹn 7 ngày tới** cho biết lịch thi, lịch test sắp diễn ra. Bấm **Lịch Big Test** để xem đầy đủ.',
                'Khối **Việc của tôi trong tuần** liệt kê việc được giao cho bạn (ví dụ dự giờ). Bấm **Xem tất cả** để mở Danh sách đầu việc.',
                'Kéo xuống dưới để xem **Chất lượng giảng dạy**, **Báo cáo tháng của giáo viên** (ai chưa nộp) và **Order học thuật**.',
            ],
        ],
        [
            'id' => 'de-xuat-giao-trinh',
            'title' => 'Theo dõi đề xuất sửa giáo trình và xin điều chỉnh tiến độ',
            'icon' => 'edit_note',
            'menu' => 'Tổng quan → Hàng chờ cần xử lý, hoặc Phê duyệt → Xin duyệt → Xem trạng thái đề xuất',
            'route' => 'syllabus.versions',
            'intro' => 'Giáo viên gửi đề xuất sửa nội dung giáo trình hoặc xin giãn tiến độ lớp. Bạn đọc chi tiết, theo dõi kết quả và cập nhật giáo trình khi đề xuất được duyệt.',
            'images' => [
                ['src' => 'hoc-thuat/de-xuat-giao-trinh.webp', 'caption' => 'Chi tiết một đề xuất sửa giáo trình'],
            ],
            'steps' => [
                'Ở **Tổng quan**, bấm ô **Đề xuất sửa giáo trình** trong khối Hàng chờ. Hoặc vào **Phê duyệt → Xin duyệt** rồi bấm **Xem trạng thái đề xuất**.',
                'Dùng ô lọc **Tất cả trạng thái** để xem riêng đề xuất chờ duyệt, đã duyệt hoặc bị từ chối. Bấm **Xem** ở một dòng để mở chi tiết.',
                'Trong chi tiết, so sánh **Nội dung cũ** với **Nội dung mới đề xuất**, đọc **Lý do thay đổi** và tải file đính kèm nếu có. Cột phải cho biết trạng thái và lịch sử xử lý.',
                'Khi đề xuất đã được duyệt, nội dung chưa tự áp vào giáo trình. Bấm **Mở buổi để cập nhật** để sửa buổi học đó ở màn Soạn syllabus.',
                'Yêu cầu xin giãn tiến độ: ở Tổng quan, khối **Đề xuất chờ duyệt**, bấm **Duyệt đề xuất**. Màn này liệt kê giáo viên, lớp, số buổi xin thêm và lý do; bấm **Xem** để đọc chi tiết.',
            ],
            'tips' => [
                'Nút phê duyệt và từ chối đề xuất, yêu cầu giãn tiến độ hiện chỉ Admin bấm được. Bạn theo dõi và trao đổi với giáo viên.',
                'Bạn cũng tự gửi được đề xuất ở **Phê duyệt → Xin duyệt → Gửi đề xuất**.',
            ],
        ],
        [
            'id' => 'big-test',
            'title' => 'Big Test: đợt thi, đề và kết quả',
            'icon' => 'assignment',
            'menu' => 'Big Test → Phân phối đề / Bảng điểm & Kết quả',
            'route' => 'syllabus.big-tests.distribution',
            'intro' => 'Big Test là bài thi cuối mỗi chặng. Bạn theo dõi order đề của giáo viên, tạo đợt thi, gắn chặng, xem bảng điểm và gửi kết quả đã duyệt cho phụ huynh.',
            'images' => [
                ['src' => 'hoc-thuat/big-test-phan-phoi.webp', 'caption' => 'Big Test → Phân phối đề: order chờ duyệt và đợt thi'],
                ['src' => 'hoc-thuat/big-test-ket-qua.webp', 'caption' => 'Big Test → Bảng điểm & Kết quả'],
            ],
            'steps' => [
                'Vào **Big Test → Phân phối đề**. Bảng **Yêu cầu chờ duyệt** là các order đề giáo viên gửi. Bấm **Xem** để đọc lớp, chặng, ngày thi dự kiến, hạn xử lý và yêu cầu của giáo viên.',
                'Bấm **Tạo đợt Big Test mới**, nhập **Tên đợt thi**, **Lớp thi**, **Loại kỳ thi**, **Thời gian thi**, **Phòng thi**, rồi bấm **Lưu bản nháp**.',
                'Ở bảng **Đợt thi Big Test**, bấm **Gắn chặng** để nối đợt thi với chặng của lớp, chọn chặng rồi bấm **Lưu**.',
                'Mở tab **Bảng điểm & Kết quả**, chọn kỳ thi ở ô **Chọn Kỳ Thi Big Test**. Bảng hiện điểm 4 kỹ năng, nhận xét, trạng thái từng học viên và ngày đã gửi phụ huynh.',
                'Khi kết quả đã được duyệt, bấm **Gửi phụ huynh** để gửi kết quả qua Zalo cho cả lớp, hoặc bấm **Gửi PH** ở từng dòng.',
                'Bấm **In bảng điểm** để in hoặc lưu PDF bảng điểm của lớp.',
            ],
            'tips' => [
                'Duyệt và phân phối đề, duyệt order và duyệt kết quả hiện chỉ Admin bấm được.',
                'Tab **Nhắc lịch** liệt kê chặng sắp thi mà chưa duyệt đề. Nút **Nhắc lịch** ở mỗi đợt thi gửi thông báo lịch thi vào Cổng Phụ huynh & Học sinh của lớp.',
                'Khi kết quả được duyệt và gửi đủ cho phụ huynh, chặng hiện tại tự đóng và chặng kế tiếp tự mở.',
            ],
        ],
        [
            'id' => 'soan-syllabus',
            'title' => 'Soạn syllabus theo chặng',
            'icon' => 'edit_document',
            'menu' => 'Giáo trình → Soạn syllabus',
            'route' => 'syllabus.builder',
            'intro' => 'Xây khung giáo trình theo thứ tự Giáo trình → Chặng (có Big Test cuối chặng) → Unit → Buổi. Giáo viên thấy nội dung từng buổi ở màn Xem bài giảng.',
            'images' => [
                ['src' => 'hoc-thuat/soan-syllabus.webp', 'caption' => 'Thông tin giáo trình và cây Chặng → Unit → Buổi'],
            ],
            'steps' => [
                'Vào **Giáo trình → Soạn syllabus**, chọn giáo trình ở ô **Giáo trình đang soạn**. Muốn làm giáo trình mới, bấm **Tạo giáo trình mới**, nhập mã, phiên bản, tên, khóa học áp dụng, tên chặng đầu tiên rồi bấm **Tạo giáo trình**.',
                'Bấm **Thêm chặng** để thêm chặng: nhập **Tên chặng học**, **Mục tiêu / đầu ra của chặng**, **Big Test cuối chặng**, rồi bấm **Thêm chặng học**.',
                'Trên mỗi chặng, bấm **Unit** để thêm unit. Trên mỗi unit, bấm **Buổi** để thêm buổi học: tiêu đề, mục tiêu, nội dung chính, bài tập về nhà, trọng tâm từ vựng và ngữ pháp, rồi bấm **Thêm buổi học vào chặng**.',
                'Ở khối **Thông tin giáo trình**, tích **Trình độ áp dụng** để lớp thuộc trình độ đó mặc định học giáo trình này, rồi bấm **Lưu thông tin giáo trình**.',
                'Dùng **Sửa**, biểu tượng thùng rác và mũi tên lên xuống để sửa, xóa hoặc đổi thứ tự chặng.',
            ],
            'tips' => [
                'Số buổi đánh liên tục trong cả giáo trình, không đánh lại từ 1 ở mỗi chặng.',
                'Bấm **Xem như giáo viên** ở tab Tài liệu để kiểm tra giáo viên sẽ thấy gì.',
            ],
        ],
        [
            'id' => 'giao-chang',
            'title' => 'Giao chặng học cho giáo viên',
            'icon' => 'assignment_ind',
            'menu' => 'Giáo trình → Giao chặng',
            'route' => 'syllabus.assignments',
            'intro' => 'Mỗi lớp học một chặng tại một thời điểm. Giao chặng là mở quyền xem giáo trình của chặng đó cho giáo viên phụ trách lớp.',
            'images' => [
                ['src' => 'hoc-thuat/giao-chang.webp', 'caption' => 'Hộp thoại Thiết lập chặng mới'],
            ],
            'steps' => [
                'Vào **Giáo trình → Giao chặng**. Bảng **Lịch sử phân quyền chặng học** cho biết lớp nào đang học chặng nào, giáo viên nào, thời hạn và trạng thái.',
                'Bấm **Thiết lập chặng mới**, chọn **Chọn lớp học**. Giáo viên, giáo trình và chặng để trống thì hệ thống lấy giáo viên chính, giáo trình theo trình độ và chặng kế tiếp của lớp.',
                'Chọn **Ngày bắt đầu**, **Dự kiến hoàn thành**, rồi bấm **Xác nhận giao chặng**.',
                'Muốn đổi giáo viên hoặc ngày của chặng đang mở, bấm biểu tượng bút ở dòng đó, sửa rồi bấm **Lưu thay đổi**.',
                'Trường hợp ngoại lệ cần đóng chặng sớm, bấm **Đóng tay**, nhập lý do rồi bấm **Đóng chặng**.',
            ],
            'tips' => [
                'Thông thường bạn không cần đóng chặng tay: chặng tự đóng khi kết quả Big Test được duyệt và gửi phụ huynh.',
            ],
        ],
        [
            'id' => 'tai-lieu',
            'title' => 'Tải lên tài liệu giáo trình',
            'icon' => 'upload_file',
            'menu' => 'Giáo trình → Tài liệu',
            'route' => 'syllabus.documents',
            'intro' => 'Lưu sách, slide, audio, video theo từng chặng và chọn ai được xem, ai được tải về.',
            'images' => [
                ['src' => 'hoc-thuat/tai-lieu.webp', 'caption' => 'Hộp thoại Tải lên tài liệu mới'],
            ],
            'steps' => [
                'Vào **Giáo trình → Tài liệu**, bấm **Tải lên tài liệu**.',
                'Chọn giáo trình ở **Chọn giáo trình**, rồi chọn chặng ở **Chọn chặng học**. Danh sách chặng lấy từ màn Soạn syllabus.',
                'Nhập **Tên tài liệu**, tích các vai trò ở **Chọn đối tượng xem**. Tích **Cho phép GV/TG tải về** nếu muốn giáo viên, trợ giảng tải file; bỏ chọn thì họ chỉ xem trực tuyến.',
                'Kéo thả file hoặc bấm **Chọn file từ máy tính**, rồi bấm **Lưu tài liệu**.',
                'Ở danh sách, lọc theo giáo trình hoặc gõ tên để tìm. Dùng các nút ở cột Thao tác để tải về, xem hoặc xóa.',
            ],
            'tips' => [
                'Admin, Học vụ, Học thuật luôn xem được mọi tài liệu.',
            ],
        ],
        [
            'id' => 'order-hoc-lieu',
            'title' => 'Xử lý order học liệu',
            'icon' => 'inventory',
            'menu' => 'Danh sách đầu việc → Order học liệu',
            'route' => 'material-orders.index',
            'intro' => 'Giáo viên đặt đạo cụ, in ấn hoặc học liệu học thuật cho lớp. Học thuật và Học vụ nhận xử lý trước hạn.',
            'images' => [
                ['src' => 'hoc-thuat/order-hoc-lieu.webp', 'caption' => 'Danh sách order học liệu'],
                ['src' => 'hoc-thuat/order-chi-tiet.webp', 'caption' => 'Chi tiết order và khối Xử lý order'],
            ],
            'steps' => [
                'Vào **Danh sách đầu việc**, mở tab **Order học liệu**. Lọc theo trạng thái, loại order, chi nhánh rồi bấm **Lọc**. Order quá hạn có nhãn **Quá hạn**. Bấm vào một dòng để mở chi tiết.',
                'Ở khối **Xử lý order**, bấm **Nhận xử lý** để báo bạn đang làm order này.',
                'Làm xong, nhập **Ghi chú xử lý** (nếu cần) rồi bấm **Hoàn thành**.',
                'Không làm được, bấm **Từ chối**, nhập lý do rồi bấm **Từ chối order**.',
            ],
        ],
        [
            'id' => 'danh-gia-du-gio',
            'title' => 'Đánh giá dự giờ theo tháng',
            'icon' => 'fact_check',
            'menu' => 'Đào tạo & Chất lượng lớp → Đánh giá dự giờ',
            'route' => 'class-quality.academic',
            'intro' => 'Ghi nhận đánh giá chuyên môn giảng dạy hằng tháng cho từng lớp: chuyên cần, tỉ lệ đạt yêu cầu và nhận xét theo từng tiêu chí.',
            'images' => [
                ['src' => 'hoc-thuat/danh-gia-du-gio.webp', 'caption' => 'Hộp thoại Đánh giá dự giờ của một lớp'],
            ],
            'steps' => [
                'Vào **Đào tạo & Chất lượng lớp → Đánh giá dự giờ**. Ba ô đầu trang cho biết số lớp học trong tháng, đã dự giờ và chưa dự giờ.',
                'Chọn tháng, lọc lớp rồi bấm **Lọc**. Cột **Dự giờ tháng** cho biết lớp nào chưa được dự giờ.',
                'Bấm **Đánh giá** ở lớp cần chấm. Tỉ lệ chuyên cần được gợi ý sẵn theo điểm danh trong tháng; nhập thêm **Tỉ lệ % học sinh đạt yêu cầu**.',
                'Tích **Đã dự giờ**, chọn **Ngày dự giờ**, **Người dự giờ**, rồi ghi nhận xét từng tiêu chí (chất lượng giảng dạy, nội dung bài học, kỹ năng tương tác...).',
                'Ghi kế hoạch theo dõi ở **Ghi chú / Hành động**, rồi bấm **Lưu**.',
            ],
            'tips' => [
                'Các tab khác trong khu này: **Báo cáo & sự vụ lớp** (xem báo cáo ngày Học vụ, báo cáo tháng Giáo viên), **Họp giáo viên**, **Dự án học thuật**.',
            ],
        ],
        [
            'id' => 'chot-loi-chuyen-mon',
            'title' => 'Chốt lỗi chuyên môn của giáo viên',
            'icon' => 'gavel',
            'menu' => 'Lương & Phạt',
            'route' => 'penalties.index',
            'intro' => 'Biên bản lỗi chuyên môn / giảng dạy (ví dụ dạy sai tiến độ giáo trình) do Học thuật chốt. Lỗi vận hành do Học vụ hoặc Quản lý chốt.',
            'images' => [
                ['src' => 'hoc-thuat/chot-loi.webp', 'caption' => 'Hộp thoại Chốt lỗi một biên bản'],
            ],
            'steps' => [
                'Vào **Lương & Phạt**. Bấm bước **Ghi nhận** ở dòng **Lọc theo bước** để xem biên bản mới; ô **Chờ HT/CM chốt** cho biết số biên bản đang chờ.',
                'Đọc cột **Lỗi vi phạm**: dòng ghi "chốt bởi Học thuật (HT)" là việc của bạn. Bấm **Xem bằng chứng** để mở ảnh hoặc file bằng chứng, bấm **...** để xem biên bản và giải trình của giáo viên.',
                'Bấm **Chốt lỗi**, chọn **Kết luận**: chỉ xác nhận có lỗi (chốt mức phạt sau), hoặc chốt mức phạt luôn và nhập **Số tiền phạt (VNĐ)**.',
                'Ghi **Ghi chú kết luận** rồi bấm **Chốt**.',
                'Biên bản đã chốt lỗi mà chưa có mức phạt: bấm **Chốt mức phạt** khi đã quyết định số tiền.',
            ],
            'tips' => [
                'Sau khi chốt mức phạt, nhân sự nộp phạt trong hạn; quá hạn sẽ bị trừ vào lương.',
            ],
        ],
        [
            'id' => 'du-an-soan-sach',
            'title' => 'Dự án soạn sách và chương trình',
            'icon' => 'auto_stories',
            'menu' => 'Giáo trình → Dự án soạn sách & chương trình',
            'route' => 'academic-projects.index',
            'intro' => 'Lập kế hoạch cho dự án soạn sách, xây chương trình: chia mốc, giao người nhận, đặt deadline và theo dõi tiến độ thành viên báo lên.',
            'images' => [
                ['src' => 'hoc-thuat/du-an.webp', 'caption' => 'Danh sách dự án'],
                ['src' => 'hoc-thuat/du-an-chi-tiet.webp', 'caption' => 'Trang dự án: tiến độ, mốc và deadline'],
            ],
            'steps' => [
                'Vào **Giáo trình → Dự án soạn sách & chương trình**. Bấm **Tạo dự án**, nhập tên, loại dự án, người phụ trách, ngày bắt đầu, deadline, mục tiêu, thành viên rồi bấm **Tạo dự án**.',
                'Bấm vào tên dự án để mở. Ở khối **Mốc tiến độ & deadline**, bấm **Thêm mốc**: nhập tên mốc, người nhận, deadline, khối lượng cần làm.',
                'Khi có tiến triển, bấm **Cập nhật tiến độ**: chọn mốc, khối lượng đã xong, việc đã làm, link sản phẩm, khó khăn, rồi bấm **Gửi cập nhật**.',
                'Ở khối **Báo cáo tiến độ**, bấm **Phản hồi** dưới cập nhật của thành viên để góp ý hoặc hướng xử lý.',
                'Bấm **Chốt tiến độ** khi muốn khóa kế hoạch. Dùng nút **⋮** để tạm dừng, hoàn thành hoặc hủy dự án.',
            ],
            'tips' => [
                'Sau khi chốt tiến độ, mốc nào quá deadline mà chưa xong sẽ tự lập biên bản chờ giải trình cho người nhận mốc.',
            ],
        ],
        [
            'id' => 'kpi-cua-toi',
            'title' => 'Xem KPI học thuật của tôi',
            'icon' => 'leaderboard',
            'menu' => 'Của tôi → KPI của tôi',
            'route' => 'kpi.mine',
            'intro' => 'Xem tỉ lệ đạt, xếp loại và thưởng KPI tạm tính của bạn trong kỳ lương, theo từng nhóm tiêu chí học thuật.',
            'images' => [
                ['src' => 'hoc-thuat/kpi-cua-toi.webp', 'caption' => 'KPI của tôi'],
            ],
            'steps' => [
                'Bấm **Của tôi** ở menu trái rồi mở tab **KPI của tôi**.',
                'Chọn kỳ ở ô **Kỳ lương** góc phải để xem tháng khác.',
                'Khối đầu trang cho biết **Tỉ lệ đạt**, **Xếp loại tháng**, **Thưởng KPI** và trạng thái phiếu.',
                'Kéo xuống để xem từng tiêu chí theo nhóm (chương trình & học liệu, đào tạo GV, chất lượng đầu ra...). Bấm **Xem ... bản ghi** để xem dữ liệu đứng sau con số.',
            ],
            'tips' => [
                'Số liệu hệ thống tự ghi nhận cập nhật hằng ngày. Tiêu chí điền tay chỉ có số khi người chấm điền cuối kỳ.',
            ],
        ],
        [
            'id' => 'bao-cao-tuan',
            'title' => 'Nộp báo cáo tuần',
            'icon' => 'summarize',
            'menu' => 'Báo cáo của tôi → Báo cáo tuần',
            'route' => 'reports.my',
            'intro' => 'Học thuật nộp báo cáo tuần (và báo cáo tháng, quý) để quản lý nắm kết quả, việc tồn và kế hoạch.',
            'images' => [
                ['src' => 'hoc-thuat/bao-cao-tuan.webp', 'caption' => 'Hộp thoại Nộp báo cáo tuần mới'],
            ],
            'steps' => [
                'Bấm **Báo cáo của tôi** ở menu trái, tab **Báo cáo tuần** mở sẵn.',
                'Bấm **Nộp báo cáo tuần**.',
                'Nhập **Tiêu đề**, kiểm tra **Ngày báo cáo**, ghi **Nội dung**: kết quả thực hiện, việc tồn đọng, kế hoạch tuần tới.',
                'Bấm **Nộp báo cáo**. Báo cáo đã nộp hiện ở **Lịch sử đã nộp**.',
            ],
            'tips' => [
                'Tab **Báo cáo tháng** và **Báo cáo quý** dùng cho báo cáo định kỳ dài hơn.',
            ],
        ],
    ],
];
