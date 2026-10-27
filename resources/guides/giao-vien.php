<?php

/*
 * Hướng dẫn cho Giáo viên Full-time và Part-time (cùng màn hình, chỉ khác cách tính lương).
 * Cấu trúc mỗi mục: xem resources/guides/chung.php.
 */
return [
    'label' => 'Giáo viên',
    'icon' => 'cast_for_education',
    'summary' => 'Giáo viên dùng Cổng Giáo viên để check-in ca dạy, điểm danh, nhận xét, giao và chấm bài, nhập điểm; ngoài ra order đề Big Test, xin duyệt giáo trình, nộp báo cáo tháng và theo dõi giờ dạy, lương, KPI. Giáo viên Full-time và Part-time dùng chung các màn hình này, chỉ khác cách tính lương.',
    'sections' => [
        [
            'id' => 'check-in-ca-day',
            'title' => 'Check-in ca dạy',
            'icon' => 'how_to_reg',
            'menu' => 'Cá nhân → Cổng Giáo viên → Check-in & Điểm danh hôm nay',
            'route' => 'teacher.home',
            'intro' => 'Check-in là cách hệ thống ghi nhận công dạy của bạn để tính lương. Đây cũng là trang mở ra ngay sau khi bạn đăng nhập, nên dùng trên điện thoại khi tới lớp là tiện nhất.',
            'images' => [
                ['src' => 'giao-vien/checkin.webp', 'caption' => 'Tích chọn ca dạy rồi bấm Check-in các ca đã chọn', 'mobile' => true],
            ],
            'steps' => [
                'Đăng nhập, bạn sẽ thấy ngay khối **Lịch dạy hôm nay** với từng ca dạy trong ngày.',
                'Tích vào ô vuông ở đầu thẻ ca dạy (có thể chọn nhiều ca cùng lúc).',
                'Bấm **Check-in các ca đã chọn**. Thẻ ca sẽ hiện "Đã check-in" kèm giờ.',
                'Dòng **Hạn chấm công** dưới mỗi ca cho biết bạn còn bao lâu để check-in.',
            ],
            'tips' => [
                'Bạn check-in được trong ngày dạy và tối đa 24 giờ sau giờ bắt đầu ca. Quá hạn, thẻ ca báo "Quá hạn chấm công", hãy liên hệ Học vụ.',
                'Cuối trang có các thẻ **Lương tạm tính**, **Báo cáo chấm công** và **Vi phạm & Khoản trừ** để bạn xem nhanh tình hình tháng.',
            ],
        ],
        [
            'id' => 'diem-danh',
            'title' => 'Điểm danh học sinh',
            'icon' => 'fact_check',
            'menu' => 'Cá nhân → Cổng Giáo viên → Check-in & Điểm danh hôm nay → Điểm danh',
            'route' => 'teacher.home',
            'intro' => 'Ghi nhận học sinh đúng giờ, muộn hay nghỉ cho từng buổi. Học sinh nghỉ sẽ tự vào danh sách cần bổ trợ.',
            'images' => [
                ['src' => 'giao-vien/diem-danh.webp', 'caption' => 'Chọn buổi cần điểm danh', 'mobile' => true],
                ['src' => 'giao-vien/diem-danh-luu.webp', 'caption' => 'Chọn trạng thái từng học sinh rồi Lưu điểm danh', 'mobile' => true],
            ],
            'steps' => [
                'Trên thẻ ca dạy, bấm **Điểm danh** (hoặc **Điểm danh ngay** ở khung nhắc ca sắp bắt đầu).',
                'Kiểm tra ô **Buổi điểm danh** đúng ngày, đúng giờ. Muốn điểm danh buổi khác thì chọn lại ở đây.',
                'Với từng học sinh, chọn **Đúng giờ**, **Muộn**, **Nghỉ có phép** hoặc **Nghỉ không phép**. Mặc định tất cả là Đúng giờ.',
                'Học sinh nghỉ (có phép hoặc không phép) thì bắt buộc ghi lý do vào ô ghi chú.',
                'Bấm **Lưu điểm danh**. Bạn có thể sửa và lưu lại nhiều lần trong thời gian cho phép.',
            ],
            'tips' => [
                'Giáo viên chỉ điểm danh được trong khoảng 24 giờ trước và sau giờ bắt đầu buổi học. Ngoài khoảng này nút lưu bị khóa, hãy nhờ Học vụ điểm danh bù.',
                'Khối **Buổi đã dạy chưa điểm danh (điểm danh bù)** trên trang chủ liệt kê các buổi bạn còn thiếu.',
                'Không điểm danh không làm mất công dạy, công dạy tính theo check-in.',
            ],
        ],
        [
            'id' => 'nhan-xet-buoi-hoc',
            'title' => 'Nhận xét buổi học',
            'icon' => 'rate_review',
            'menu' => 'Cá nhân → Cổng Giáo viên → thẻ ca dạy → Nhận xét',
            'route' => 'teacher.home',
            'intro' => 'Sau mỗi buổi, bạn nhận xét từng học sinh: điểm Monsters, thực hành ngữ pháp, tinh thần học tập, kết quả và nhận xét chi tiết.',
            'images' => [
                ['src' => 'giao-vien/nhan-xet.webp', 'caption' => 'Bảng nhận xét từng học sinh của buổi học'],
            ],
            'steps' => [
                'Trên thẻ ca dạy ở trang chủ, bấm **Nhận xét**.',
                'Kiểm tra tên lớp và buổi học ở đầu trang. Muốn nhận xét buổi khác, chọn ở ô **Nhận xét buổi khác** cuối trang.',
                'Điền các cột **Monsters (Nhóm)**, **Monsters (Thưởng)**, **Thực hành ngữ pháp**, **Tinh thần học tập**, **Kết quả** và ô nhận xét chi tiết cho từng học sinh. Học sinh vắng bị khóa, không cần điền.',
                'Chưa xong thì bấm **Lưu nháp**. Xong hết thì bấm **Lưu nhận xét**.',
            ],
            'tips' => [
                'Bản nháp chưa hiện cho học viên, phụ huynh. Chỉ khi bấm Lưu nhận xét thì nhận xét mới được gửi.',
                'Nên điểm danh trước khi nhận xét để cột Điểm danh hiện đúng trạng thái.',
                'Nộp nhận xét chậm quá 24 giờ có thể bị ghi lỗi vi phạm.',
            ],
        ],
        [
            'id' => 'giao-bai-tap',
            'title' => 'Giao bài tập về nhà',
            'icon' => 'assignment',
            'menu' => 'Cá nhân → Cổng Giáo viên → thẻ ca dạy → Giao bài',
            'route' => 'teacher.home',
            'intro' => 'Giao bài tập cho cả lớp theo từng buổi học. Học viên thấy bài và nộp trên Cổng học viên.',
            'images' => [
                ['src' => 'giao-vien/giao-bai.webp', 'caption' => 'Chọn buổi, hạn nộp, hạng mục bài tập rồi Lưu bài tập'],
            ],
            'steps' => [
                'Trên thẻ ca dạy, bấm **Giao bài**.',
                'Chọn **Buổi học** và **Hạn nộp**. Có thể thêm ghi chú nhắc cả lớp và tài liệu tham khảo (link YouTube, file nghe, link Quizizz).',
                'Ở **Hạng mục bài tập**, tích ít nhất một loại (**Quay video**, **Viết từ vựng**, **Workbook**, **Sách bổ trợ**, **Quiz**, **Sách bộ giáo dục**) và ghi yêu cầu chi tiết cho từng loại.',
                'Bấm **Lưu bài tập**. Bài vừa giao hiện ở danh sách **Bài tập đã giao** bên dưới.',
                'Muốn sửa hoặc xóa, bấm biểu tượng bút chì hoặc thùng rác ở bài đó.',
            ],
            'tips' => [
                'Hạng mục đã có học sinh nộp bài thì không bỏ được nữa (có biểu tượng ổ khóa).',
            ],
        ],
        [
            'id' => 'cham-bai-nhap-diem',
            'title' => 'Chấm bài nộp và nhập điểm mini test',
            'icon' => 'grading',
            'menu' => 'Cá nhân → Cổng Giáo viên → Chấm bài nộp của lớp; thẻ ca dạy → Nhập điểm',
            'route' => 'portal.teacher.submissions',
            'intro' => 'Xem bài học viên đã nộp, cho điểm, nhận xét; và nhập điểm 4 kỹ năng của bài mini test cuối mỗi Unit.',
            'images' => [
                ['src' => 'giao-vien/cham-bai.webp', 'caption' => 'Chọn lớp, chọn loại bài rồi chấm từng bài nộp'],
                ['src' => 'giao-vien/nhap-diem.webp', 'caption' => 'Nhập điểm mini test theo Unit và học sinh'],
            ],
            'steps' => [
                'Chấm bài nộp: mở tab **Chấm bài nộp của lớp**, chọn lớp ở ô **Chọn lớp dạy**, rồi bấm loại bài (**Quay video**, **Viết từ vựng**, **Workbook**, **Phát âm**...).',
                'Mở tệp học viên nộp, nhập điểm (nếu có) và nhận xét, rồi bấm **Đánh dấu đã xem**. Với bài **Phát âm**, nghe bản ghi, nhập điểm trên thang 100 rồi bấm **Chấm**.',
                'Nhập điểm mini test: trên thẻ ca dạy, bấm **Nhập điểm**, chọn **Chọn Unit** và **Chọn học sinh**.',
                'Nhập điểm **Nghe**, **Nói**, **Đọc**, **Viết** (kiểm tra **Thang điểm tối đa**), thêm nhận xét nếu cần, rồi bấm **Lưu điểm**.',
                'Bảng **Điểm đã nhập** bên dưới cho biết học sinh nào còn thiếu. Bấm **Nhập** hoặc **Sửa** ở từng dòng để làm tiếp.',
            ],
            'tips' => [
                'Học sinh có điểm tổng mini test dưới 7/10 tự vào danh sách bổ trợ và hiện ở khối **Học sinh cần chú ý** trên trang chủ.',
            ],
        ],
        [
            'id' => 'khach-hoc-thu',
            'title' => 'Nhận xét khách học thử',
            'icon' => 'school',
            'menu' => 'Cá nhân → Cổng Giáo viên → Khách học thử',
            'route' => 'teacher.trial-guests',
            'intro' => 'Xem khách học thử được xếp vào buổi dạy của bạn và nhận xét sau buổi học. Nhận xét được lưu vào hồ sơ khách để Học vụ và tư vấn viên theo dõi.',
            'images' => [
                ['src' => 'giao-vien/khach-hoc-thu.webp', 'caption' => 'Tab Đã diễn ra: chọn trạng thái, điểm và nhận xét'],
            ],
            'steps' => [
                'Mở tab **Khách học thử**. Tab **Hôm nay & sắp tới** cho biết ai sẽ học thử, kết quả test và ghi chú của Học vụ.',
                'Sau buổi học, chuyển sang tab **Đã diễn ra**.',
                'Chọn **Có mặt (đã học thử)** hoặc **Vắng mặt**, chọn điểm từ 1/5 đến 5/5.',
                'Điền **Thực hành ngữ pháp**, **Tinh thần học tập**, **Kết quả** và nhận xét chi tiết, rồi bấm **Lưu nhận xét**.',
            ],
            'tips' => [
                'Ô nhận xét chỉ mở từ ngày học thử. Trước đó dòng khách hiện "Nhận xét được mở từ ngày học thử".',
                'Khách học thử cũng hiện trên thẻ ca dạy ở trang chủ (dòng "+N khách học thử").',
            ],
        ],
        [
            'id' => 'giao-trinh-order-test',
            'title' => 'Chặng đang dạy, order đề test và xem bài giảng',
            'icon' => 'menu_book',
            'menu' => 'Đào tạo → Giáo trình → Chặng đang dạy & Order Test / Xem bài giảng',
            'route' => 'syllabus.teaching-stages',
            'intro' => 'Theo dõi chặng học từng lớp đang dạy, đặt ngày dự kiến Big Test, gửi yêu cầu cấp đề cho Ban Học thuật và mở tài liệu, nội dung từng buổi học.',
            'images' => [
                ['src' => 'giao-vien/chang-dang-day.webp', 'caption' => 'Mỗi lớp một thẻ: chặng, trạng thái đề, ngày dự kiến Big Test'],
                ['src' => 'giao-vien/order-test.webp', 'caption' => 'Gửi yêu cầu cấp đề Big Test hoặc Mini Test'],
                ['src' => 'giao-vien/xem-bai-giang.webp', 'caption' => 'Xem bài giảng: tài liệu và nội dung từng buổi'],
            ],
            'steps' => [
                'Vào **Giáo trình**, tab **Chặng đang dạy & Order Test**. Mỗi thẻ cho biết chặng đang học, ngày bắt đầu và trạng thái đề (ví dụ "Đã order - Chờ HT duyệt", "Đã có đề").',
                'Chọn **Ngày dự kiến Big Test** rồi bấm **Lưu** (hoặc **Cập nhật** nếu đổi ngày).',
                'Bấm **Order đề Big Test** (hoặc **Chi tiết** nếu đã order) để mở trang order. Chọn **Loại đề** (Big Test cuối chặng hoặc Mini Test), ngày thi dự kiến, ghi chú cho Học thuật, rồi bấm **Gửi yêu cầu tới Ban Học thuật**.',
                'Bảng **Yêu cầu đã gửi cho lớp này** cho biết yêu cầu đang chờ duyệt hay đã được duyệt và phân phối đề.',
                'Mở tab **Xem bài giảng** để xem tài liệu. Tab **Nội dung buổi học** cho biết chặng đang học, tiến độ và buổi tiếp theo; bấm biểu tượng mở ở từng buổi để xem chi tiết.',
            ],
            'tips' => [
                'Hạn xử lý của Học thuật là ngày thi trừ 3 ngày, nên order sớm.',
                'Một số tài liệu không cho tải về để bảo mật. Bạn vẫn xem trực tuyến được.',
            ],
        ],
        [
            'id' => 'xin-duyet-giao-trinh',
            'title' => 'Đề xuất sửa giáo trình, xin điều chỉnh tiến độ',
            'icon' => 'outgoing_mail',
            'menu' => 'Phê duyệt → Xin duyệt → Đề xuất sửa giáo trình / Customize syllabus',
            'route' => 'syllabus.teacher-propose',
            'intro' => 'Gửi Ban Học thuật đề xuất sửa lỗi hoặc nội dung giáo trình, hoặc xin thêm buổi khi lớp cần thêm thời gian cho một chặng.',
            'images' => [
                ['src' => 'giao-vien/de-xuat-giao-trinh.webp', 'caption' => 'Gửi đề xuất sửa giáo trình'],
                ['src' => 'giao-vien/xin-dieu-chinh-tien-do.webp', 'caption' => 'Gửi yêu cầu điều chỉnh tiến độ'],
            ],
            'steps' => [
                'Vào **Xin duyệt**, tab **Đề xuất sửa giáo trình**, bấm **Gửi đề xuất**.',
                'Chọn giáo trình, chọn buổi học (bỏ trống nếu áp dụng chung), mô tả thay đổi. Mở **Thông tin bổ sung (tùy chọn)** nếu muốn ghi loại đề xuất, nội dung hiện tại, lý do hoặc đính kèm file. Bấm **Gửi đề xuất**.',
                'Muốn xin thêm buổi: mở tab **Customize syllabus**, chọn **Giãn thêm buổi** hoặc **Dời tiến độ**, bấm **Gửi yêu cầu**.',
                'Chọn **Lớp học / Chặng học**, ghi lý do, chọn **Số buổi cần thêm** (1 hoặc 2 buổi), rồi bấm **Gửi yêu cầu**.',
                'Theo dõi kết quả ở bảng **Lịch sử đề xuất** hoặc **Danh sách yêu cầu đã gửi** (Chờ duyệt, Đã duyệt, Đã từ chối).',
            ],
            'tips' => [
                'Khi yêu cầu điều chỉnh tiến độ được duyệt, hệ thống tự thêm đúng số buổi đó vào cuối lịch học của lớp.',
                'Yêu cầu điều chỉnh tiến độ được Ban Học thuật xem xét và phản hồi trong vòng 3 ngày.',
            ],
        ],
        [
            'id' => 'big-test',
            'title' => 'Nhập điểm Big Test',
            'icon' => 'assignment',
            'menu' => 'Đào tạo → Big Test',
            'route' => 'syllabus.big-tests.results',
            'intro' => 'Nhập điểm 4 kỹ năng, nhận xét và link video bài thi Big Test cho lớp bạn dạy, rồi gửi Học thuật duyệt trước khi gửi phụ huynh.',
            'images' => [
                ['src' => 'giao-vien/big-test.webp', 'caption' => 'Chọn kỳ thi rồi nhập điểm từng học viên'],
            ],
            'steps' => [
                'Vào **Big Test**, chọn kỳ thi ở ô **Chọn Kỳ Thi Big Test**.',
                'Với mỗi học viên, nhập điểm **Listening**, **Reading**, **Writing**, **Speaking**. Cột **Overall** tự tính.',
                'Ghi nhận xét tiến độ và link video bài thi ở cột **Nhận xét & Video**. Học viên vắng thì tích **Vắng thi** và không cần nhập điểm.',
                'Bấm **Lưu nháp** nếu chưa xong (Học thuật chưa thấy). Xong thì bấm **Gửi duyệt**.',
                'Theo dõi cột **Trạng thái** và **Đã báo PH** để biết bài nào đã được duyệt, đã gửi phụ huynh. Bấm **In bảng điểm** khi cần bản in.',
            ],
            'tips' => [
                'Gửi duyệt cần đủ 4 kỹ năng. Dòng để trống sẽ được bỏ qua.',
                'Điểm đã duyệt hoặc đã gửi phụ huynh thì không sửa được nữa.',
            ],
        ],
        [
            'id' => 'bao-cao-thang',
            'title' => 'Nộp báo cáo tháng',
            'icon' => 'summarize',
            'menu' => 'Báo cáo → Báo cáo của tôi → Báo cáo định kỳ',
            'route' => 'reports.periodic.teacher-monthly',
            'intro' => 'Mỗi tháng bạn nộp một báo cáo về tiến độ giảng dạy, khó khăn, đề xuất và tình hình từng lớp. Hạn nộp là Chủ nhật cuối tháng, hệ thống nhắc trước 3 ngày, 1 ngày và đúng ngày hạn.',
            'images' => [
                ['src' => 'giao-vien/bao-cao-thang.webp', 'caption' => 'Báo cáo tháng theo lớp'],
            ],
            'steps' => [
                'Vào **Báo cáo của tôi**, tab **Báo cáo định kỳ**, chọn **Báo cáo tháng theo lớp**.',
                'Kiểm tra tháng báo cáo ở ô chọn tháng (bấm **Lọc** nếu đổi tháng).',
                'Điền **Báo cáo chung**: Tiến độ giảng dạy, Khó khăn, Đề xuất.',
                'Ở **Tình hình từng lớp**, điền Tình hình lớp, Học sinh cần chú ý, Giải pháp. Tích **Lớp cần hỗ trợ** và ghi rõ cần hỗ trợ gì nếu có. Số liệu chuyên cần, bài tập, điểm trung bình đã có sẵn ở đầu mỗi lớp.',
                'Bấm **Lưu báo cáo tháng** ở cuối trang. Tháng đã nộp hiện ở khung **Các tháng đã nộp**, bấm vào để xem lại hoặc sửa.',
            ],
            'tips' => [
                'Nếu chỉ muốn nộp nhanh một báo cáo dạng văn bản, chọn **Báo cáo tháng** rồi bấm **Nộp báo cáo tháng**.',
                'Tab **Báo cáo giảng dạy** tổng hợp giờ dạy, số buổi đã điểm danh, số điểm mini test đã nhập và các lớp bạn phụ trách trong tháng.',
            ],
        ],
        [
            'id' => 'gio-day',
            'title' => 'Xem giờ dạy của mình',
            'icon' => 'schedule',
            'menu' => 'Người dùng → Chấm công',
            'route' => 'payroll.timesheets.teachers',
            'intro' => 'Xem các ca dạy đã được ghi nhận trong kỳ lương, ca nào hợp lệ để tính lương, ca nào bị từ chối và buổi nào bạn còn thiếu chấm công.',
            'images' => [
                ['src' => 'giao-vien/gio-day.webp', 'caption' => 'Chấm công của tôi trong kỳ lương'],
            ],
            'steps' => [
                'Vào **Chấm công**. Trang **Chấm công của tôi** mở kỳ lương hiện tại.',
                'Xem nhanh các ô **Chờ đối soát**, **Hợp lệ (tính lương)**, **Từ chối** và **Số lần thiếu chấm công trong tháng**.',
                'Bảng **Ca dạy trong kỳ** liệt kê từng ca: giờ vào, giờ ra, số giờ, tình trạng và người duyệt. Ca bị từ chối có ghi lý do.',
                'Muốn xem kỳ khác hoặc lọc theo trạng thái, loại ca, chọn ở thanh lọc rồi bấm **Lọc**.',
            ],
            'tips' => [
                'Quên check-in hoặc ca bị thiếu: báo Học vụ để được chấm công bù trước khi kỳ lương chốt.',
                'Trên điện thoại, nút **Bảng công** ở thanh dưới của Cổng Giáo viên mở nhanh báo cáo giờ dạy tháng.',
            ],
        ],
        [
            'id' => 'luong-kpi-vi-pham',
            'title' => 'Lương, KPI và vi phạm của tôi',
            'icon' => 'payments',
            'menu' => 'Cá nhân → Của tôi → Lương của tôi / KPI của tôi; Người dùng → Lương & Phạt',
            'route' => 'portal.my-salary',
            'intro' => 'Xem phiếu lương từng kỳ, điểm KPI tháng và các biên bản vi phạm của bạn. Full-time có lương cơ bản; Part-time tính theo số buổi dạy hợp lệ nhân đơn giá buổi.',
            'images' => [
                ['src' => 'giao-vien/luong.webp', 'caption' => 'Lương của tôi: thu nhập, khoản trừ, chi tiết buổi dạy'],
                ['src' => 'giao-vien/kpi.webp', 'caption' => 'KPI của tôi theo từng tiêu chí'],
                ['src' => 'giao-vien/vi-pham.webp', 'caption' => 'Danh sách vi phạm: bấm Giải trình khi được yêu cầu'],
            ],
            'steps' => [
                'Vào **Của tôi → Lương của tôi**, chọn kỳ lương ở ô tháng để xem tổng thu nhập, khoản trừ, thực nhận và chi tiết từng buổi dạy.',
                'Bấm **Tải phiếu lương** để lưu phiếu về máy.',
                'Mở tab **KPI của tôi**, chọn kỳ lương để xem tỉ lệ đạt, xếp loại tháng và từng tiêu chí. Bấm dòng **Xem ... bản ghi** dưới tiêu chí để biết số liệu đến từ đâu.',
                'Vào **Lương & Phạt** để xem các biên bản vi phạm. Biên bản đang chờ bạn giải trình có nút **Giải trình**: bấm, nhập nội dung rồi bấm **Gửi giải trình**.',
                'Bấm nút **⋯** ở mỗi dòng để xem chi tiết biên bản, bằng chứng và kết luận.',
            ],
            'tips' => [
                'Thắc mắc về số buổi, KPI hoặc khoản trừ: phản hồi phòng Kế toán trước ngày 03 hằng tháng.',
                'Phạt đã chốt cần nộp trong 2 ngày. Quá hạn sẽ trừ vào lương.',
            ],
        ],
    ],
];
