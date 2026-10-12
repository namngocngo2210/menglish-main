<?php

/*
 * Hướng dẫn cho Trợ giảng. Cấu trúc mỗi mục: xem resources/guides/chung.php.
 */
return [
    'label' => 'Trợ giảng',
    'icon' => 'diversity_3',
    'summary' => 'Trợ giảng làm và đánh dấu xong các nhiệm vụ trực lớp mỗi ngày, nộp báo cáo trực lớp, check-in ca trợ giảng, điểm danh, chấm bài nộp, nộp báo cáo tháng và theo dõi giờ công, lương, KPI. Phần lớn việc hằng ngày làm trên điện thoại.',
    'sections' => [
        [
            'id' => 'nhiem-vu-hom-nay',
            'title' => 'Nhiệm vụ hôm nay',
            'icon' => 'assignment',
            'menu' => 'Cá nhân → Cổng Giáo viên → Nhiệm vụ hôm nay của TA',
            'route' => 'portal.ta-tasks',
            'intro' => 'Trang mở ra ngay sau khi bạn đăng nhập. Nhiệm vụ trong ngày được chia theo ca Trước giờ học, Trong giờ học và Sau giờ học, mỗi nhiệm vụ có hạn và trạng thái riêng.',
            'images' => [
                ['src' => 'tro-giang/nhiem-vu.webp', 'caption' => 'Nhiệm vụ chia theo ca, bấm Hoàn thành khi làm xong', 'mobile' => true],
                ['src' => 'tro-giang/hoan-thanh.webp', 'caption' => 'Tải ảnh bằng chứng rồi bấm Xác nhận', 'mobile' => true],
            ],
            'steps' => [
                'Mở trang **Nhiệm vụ hằng ngày**. Bấm tên từng ca (**Trước giờ học**, **Trong giờ học**, **Sau giờ học**) để mở hoặc thu gọn danh sách.',
                'Làm xong một nhiệm vụ, bấm **Hoàn thành** trên thẻ nhiệm vụ đó (nhiệm vụ trễ hạn có nút **Hoàn thành gấp**).',
                'Trong hộp **Cập nhật tiến độ**, bấm **Nhấn để tải ảnh lên** để chụp hoặc chọn ảnh bằng chứng, thêm ghi chú nếu cần.',
                'Bấm **Xác nhận**. Có ảnh thì nhiệm vụ hoàn thành ngay; không có ảnh thì chuyển sang chờ Admin xác nhận.',
                'Muốn xem ngày khác, chọn ở ô **Ngày**; bấm **Hôm nay** để quay lại.',
            ],
            'tips' => [
                'Nhãn trên thẻ cho biết trạng thái: Mới, Đang thực hiện, Chờ xác nhận, Bị chặn (kèm lý do), Quá hạn, Hoàn thành.',
                'Khung vàng đầu trang nhắc bạn còn nhiệm vụ của các ngày trước chưa xong.',
                'Nút **Lớp học** ở thanh dưới cùng đưa bạn tới danh sách lớp trực trong ngày (giờ học, phòng, cơ sở).',
            ],
        ],
        [
            'id' => 'bao-cao-truc-lop',
            'title' => 'Nộp báo cáo trực lớp',
            'icon' => 'rate_review',
            'menu' => 'Nhiệm vụ hôm nay của TA → Nộp báo cáo trực lớp (hoặc nút Báo cáo ở thanh dưới)',
            'route' => 'portal.ta-tasks',
            'intro' => 'Sau buổi trực lớp, bạn ghi lại nội dung bài học, nhật ký lớp và học sinh cần bổ trợ. Báo cáo có ảnh sẽ tự đánh dấu xong nhiệm vụ trực lớp.',
            'images' => [
                ['src' => 'tro-giang/bao-cao-truc-lop.webp', 'caption' => 'Form Nộp báo cáo trực lớp', 'mobile' => true],
            ],
            'steps' => [
                'Trên thẻ nhiệm vụ có dòng "Trực lớp", bấm **Nộp báo cáo trực lớp**. Hoặc bấm **Báo cáo** ở thanh dưới cùng rồi chọn **Lớp học**.',
                'Kiểm tra lớp và buổi học. Nếu buổi không có trong danh sách, gõ tên buổi vào ô bên dưới.',
                'Điền **Hôm nay học gì** (bắt buộc) và **Nhật ký dạy** nếu có điều cần lưu ý.',
                'Bấm **Thêm ảnh** để đính kèm ảnh bảng hoặc lớp học (tối đa 10 ảnh).',
                'Có học sinh cần kèm thêm thì bấm **Thêm học sinh cần bổ trợ**, ghi lý do và kế hoạch xử lý. Cuối cùng bấm **Nộp báo cáo**.',
            ],
            'tips' => [
                'Ảnh không bắt buộc. Có ít nhất 1 ảnh, nhiệm vụ trực lớp tự hoàn thành; không có ảnh, nhiệm vụ chờ Admin xác nhận.',
            ],
        ],
        [
            'id' => 'check-in-ca-tro-giang',
            'title' => 'Check-in ca trợ giảng',
            'icon' => 'how_to_reg',
            'menu' => 'Cá nhân → Cổng Giáo viên → Check-in & Điểm danh hôm nay',
            'route' => 'teacher.home',
            'intro' => 'Check-in là cách hệ thống ghi nhận công trợ giảng để tính lương. Bạn check-in trên điện thoại khi tới lớp.',
            'images' => [
                ['src' => 'tro-giang/checkin.webp', 'caption' => 'Tích chọn ca rồi bấm Check-in các ca đã chọn', 'mobile' => true],
            ],
            'steps' => [
                'Mở tab **Check-in & Điểm danh hôm nay**. Khối **Lịch dạy hôm nay** hiện các ca bạn trực trong ngày.',
                'Tích vào ô vuông ở đầu thẻ ca (có thể chọn nhiều ca).',
                'Bấm **Check-in các ca đã chọn**. Thẻ ca sẽ hiện "Đã check-in" kèm giờ.',
                'Theo dõi dòng **Hạn chấm công** dưới mỗi ca để không bị quá hạn.',
            ],
            'tips' => [
                'Bạn check-in được trong ngày học và tối đa 24 giờ sau giờ bắt đầu ca. Quá hạn, hãy liên hệ Học vụ.',
                'Đi muộn ca trợ giảng được tính vào KPI.',
            ],
        ],
        [
            'id' => 'diem-danh',
            'title' => 'Điểm danh học sinh',
            'icon' => 'fact_check',
            'menu' => 'Cá nhân → Cổng Giáo viên → Check-in & Điểm danh hôm nay → Điểm danh',
            'route' => 'teacher.home',
            'intro' => 'Trợ giảng điểm danh cho lớp mình trực giống như giáo viên. Người lưu điểm danh được ghi nhận theo tài khoản đang đăng nhập.',
            'images' => [
                ['src' => 'tro-giang/diem-danh.webp', 'caption' => 'Chọn trạng thái từng học sinh rồi Lưu điểm danh', 'mobile' => true],
            ],
            'steps' => [
                'Trên thẻ ca ở trang chủ, bấm **Điểm danh** (hoặc **Điểm danh ngay** ở khung nhắc ca sắp bắt đầu).',
                'Kiểm tra ô **Buổi điểm danh** đúng ngày, đúng giờ.',
                'Chọn trạng thái cho từng học sinh: **Đúng giờ**, **Muộn**, **Nghỉ có phép**, **Nghỉ không phép**. Học sinh nghỉ thì bắt buộc ghi lý do.',
                'Bấm **Lưu điểm danh**. Bạn có thể sửa và lưu lại trong thời gian cho phép.',
            ],
            'tips' => [
                'Chỉ điểm danh được trong khoảng 24 giờ trước và sau giờ bắt đầu buổi học. Ngoài khoảng này, nhờ Học vụ điểm danh bù.',
                'Điểm danh và nhận xét buổi học đúng hạn là một tiêu chí KPI của trợ giảng.',
            ],
        ],
        [
            'id' => 'cham-bai',
            'title' => 'Chấm bài nộp của lớp',
            'icon' => 'grading',
            'menu' => 'Cá nhân → Cổng Giáo viên → Chấm bài nộp của lớp',
            'route' => 'portal.teacher.submissions',
            'intro' => 'Xem bài tập học viên lớp bạn trực đã nộp, cho điểm và nhận xét.',
            'images' => [
                ['src' => 'tro-giang/cham-bai.webp', 'caption' => 'Chọn lớp, chọn loại bài rồi chấm từng bài nộp'],
            ],
            'steps' => [
                'Mở tab **Chấm bài nộp của lớp**, chọn lớp ở ô **Chọn lớp dạy**.',
                'Bấm loại bài cần chấm: **Quay video**, **Viết từ vựng**, **Workbook**, **Sách bổ trợ**, **Sách bộ giáo dục**, **Quiz** hoặc **Phát âm**.',
                'Mở tệp học viên nộp, nhập điểm (nếu có) và nhận xét, rồi bấm **Đánh dấu đã xem**.',
                'Với bài **Phát âm**, nghe bản ghi, nhập điểm trên thang 100 và nhận xét, rồi bấm **Chấm**.',
            ],
            'tips' => [
                'Nhắc và thu bài tập về nhà là một tiêu chí KPI của trợ giảng.',
            ],
        ],
        [
            'id' => 'gio-cong',
            'title' => 'Xem giờ công trợ giảng',
            'icon' => 'schedule',
            'menu' => 'Người dùng → Chấm công',
            'route' => 'payroll.timesheets.teachers',
            'intro' => 'Xem các ca đã được ghi nhận trong kỳ lương, ca nào hợp lệ, ca nào bị từ chối và buổi nào bạn còn thiếu chấm công.',
            'images' => [
                ['src' => 'tro-giang/gio-day.webp', 'caption' => 'Chấm công của tôi: ca trong kỳ và buổi thiếu chấm công'],
            ],
            'steps' => [
                'Vào **Chấm công**. Trang **Chấm công của tôi** mở kỳ lương hiện tại.',
                'Xem các ô **Chờ đối soát**, **Hợp lệ (tính lương)**, **Từ chối** và **Số lần thiếu chấm công trong tháng**.',
                'Bảng **Ca dạy trong kỳ** liệt kê từng ca kèm giờ vào, giờ ra, số giờ và lý do nếu bị từ chối.',
                'Kéo xuống bảng **Buổi thiếu chấm công** để biết buổi nào bạn được phân công mà chưa check-in.',
            ],
            'tips' => [
                'Buổi thiếu chấm công: báo Học vụ để được chấm công bù trước khi kỳ lương chốt.',
            ],
        ],
        [
            'id' => 'bao-cao-thang',
            'title' => 'Nộp báo cáo tháng',
            'icon' => 'summarize',
            'menu' => 'Báo cáo → Báo cáo của tôi → Báo cáo định kỳ',
            'route' => 'reports.my',
            'intro' => 'Mỗi tháng bạn nộp một báo cáo tổng kết công việc. Hạn nộp là Chủ nhật cuối tháng, hệ thống nhắc trước 3 ngày, 1 ngày và đúng ngày hạn.',
            'images' => [
                ['src' => 'tro-giang/bao-cao-thang.webp', 'caption' => 'Nộp báo cáo tháng mới'],
            ],
            'steps' => [
                'Vào **Báo cáo của tôi**, tab **Báo cáo định kỳ**. Khung vàng cho biết hạn nộp và tháng này bạn đã nộp chưa.',
                'Bấm **Nộp báo cáo tháng**.',
                'Nhập **Tiêu đề**, kiểm tra **Ngày báo cáo**, rồi ghi **Nội dung**: kết quả thực hiện, tồn đọng, kế hoạch.',
                'Bấm **Nộp báo cáo**. Báo cáo đã nộp hiện ở **Lịch sử đã nộp**.',
            ],
        ],
        [
            'id' => 'luong',
            'title' => 'Lương của tôi',
            'icon' => 'payments',
            'menu' => 'Cá nhân → Của tôi → Lương của tôi',
            'route' => 'portal.my-salary',
            'intro' => 'Xem phiếu lương từng kỳ: số buổi hợp lệ, đơn giá buổi, các khoản cộng, khoản trừ và số thực nhận.',
            'images' => [
                ['src' => 'tro-giang/luong.webp', 'caption' => 'Lương của tôi'],
            ],
            'steps' => [
                'Vào **Của tôi → Lương của tôi**.',
                'Chọn kỳ lương ở ô tháng góc trên bên phải.',
                'Xem **Tổng thu nhập**, **Tổng khoản trừ**, **Thực nhận**, rồi kéo xuống **Chi tiết thu nhập**, **Các khoản trừ** và **Chi tiết buổi dạy**.',
                'Bấm **Tải phiếu lương** để lưu phiếu về máy.',
            ],
            'tips' => [
                'Thắc mắc về số buổi, KPI hoặc khoản trừ: phản hồi phòng Kế toán trước ngày 03 hằng tháng.',
                'Biên bản vi phạm của bạn nằm ở **Người dùng → Lương & Phạt**. Biên bản đang chờ bạn giải trình có nút **Giải trình**.',
            ],
        ],
        [
            'id' => 'kpi',
            'title' => 'KPI của tôi',
            'icon' => 'insights',
            'menu' => 'Cá nhân → Của tôi → KPI của tôi',
            'route' => 'kpi.mine',
            'intro' => 'Xem tỉ lệ đạt KPI trong kỳ và từng tiêu chí: đi muộn ca trợ giảng, điểm danh và nhận xét đúng hạn, chuyên cần của lớp, nhắc thu bài tập, phản ánh từ giáo viên chính và phụ huynh.',
            'images' => [
                ['src' => 'tro-giang/kpi.webp', 'caption' => 'KPI của tôi theo từng tiêu chí'],
            ],
            'steps' => [
                'Vào **Của tôi**, mở tab **KPI của tôi**.',
                'Chọn kỳ lương ở ô góc trên bên phải.',
                'Xem **Tỉ lệ đạt** và trạng thái phiếu, rồi xem từng nhóm tiêu chí bên dưới.',
                'Bấm dòng **Xem ... bản ghi** dưới tiêu chí để biết số liệu đến từ đâu.',
            ],
            'tips' => [
                'Số liệu hệ thống cập nhật hằng ngày. Tiêu chí chấm tay chỉ có số khi người chấm điền cuối kỳ.',
            ],
        ],
    ],
];
