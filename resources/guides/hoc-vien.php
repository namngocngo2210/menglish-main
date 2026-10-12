<?php

// Hướng dẫn cho Học viên và Phụ huynh (đăng nhập bằng tài khoản của học viên). Cấu trúc mục: xem resources/guides/chung.php.
// Cổng học viên dùng chủ yếu trên điện thoại nên mọi ảnh đều là ảnh điện thoại.
return [
    'label' => 'Học viên & Phụ huynh',
    'icon' => 'family_restroom',
    'summary' => 'Cổng Phụ huynh & Học sinh giúp học viên và phụ huynh xem lịch học, tiến độ, nộp bài tập, luyện phát âm, đọc thông báo, góp ý và báo đóng học phí ngay trên điện thoại. Phụ huynh đăng nhập bằng tài khoản của con.',
    'sections' => [
        [
            'id' => 'dang-nhap',
            'title' => 'Đăng nhập bằng mã học viên',
            'icon' => 'login',
            'menu' => 'Màn hình đăng nhập',
            'intro' => 'Trung tâm cấp cho mỗi học viên một mã học viên và mật khẩu. Học viên và phụ huynh dùng chung tài khoản này.',
            'images' => [
                ['src' => 'hoc-vien/dang-nhap.webp', 'caption' => 'Màn hình đăng nhập trên điện thoại', 'mobile' => true],
            ],
            'steps' => [
                'Mở trình duyệt trên điện thoại, vào địa chỉ portal trung tâm gửi.',
                'Ở ô **Email hoặc mã học viên**, nhập mã học viên (ví dụ HV-...) hoặc email đã đăng ký.',
                'Nhập **Mật khẩu**, tích **Ghi nhớ đăng nhập** nếu dùng máy riêng, rồi bấm **Đăng nhập**.',
                'Lần đầu đăng nhập, nếu được yêu cầu đổi mật khẩu, hãy đặt mật khẩu mới trước khi dùng tiếp.',
            ],
            'tips' => [
                'Quên mật khẩu: liên hệ trung tâm (Học vụ) để được đặt lại.',
                'Mã học viên xem lại được ở mục Thông tin tài khoản (bấm biểu tượng người ở góc trên trang Trang chủ).',
            ],
        ],
        [
            'id' => 'trang-chu',
            'title' => 'Xem lịch học và tiến độ',
            'icon' => 'home',
            'menu' => 'Thanh dưới → Trang chủ',
            'route' => 'portal.student.home',
            'intro' => 'Trang chủ cho biết các buổi học sắp tới, tiến độ học tập, lịch sử điểm danh, kết quả Big Test và thông tin học phí của học viên.',
            'images' => [
                ['src' => 'hoc-vien/trang-chu.webp', 'caption' => 'Trang chủ: Lịch học sắp tới', 'mobile' => true],
                ['src' => 'hoc-vien/tien-do.webp', 'caption' => 'Tiến độ học tập và lịch sử điểm danh', 'mobile' => true],
            ],
            'steps' => [
                'Sau khi đăng nhập, bạn vào thẳng **Trang chủ**. Thanh dưới màn hình có 5 mục: **Trang chủ**, **Học tập**, **Phát âm**, **Khảo sát**, **Thông báo**.',
                'Khối **Lịch học sắp tới** liệt kê từng buổi: ngày, giờ, lớp, phòng. Nhãn **Buổi học**, **Phụ đạo** hoặc **Nghỉ** cho biết loại buổi. Buổi nghỉ bị gạch ngang.',
                'Kéo xuống khối **Tiến độ học tập đã duyệt** để xem **Chuyên cần**, số **Bài tập** đã nộp và điểm **Big Test mới nhất**.',
                'Khối **Lịch sử điểm danh** cho biết từng buổi học viên **Có mặt**, **Đi muộn** hay **Vắng**. Khối **Kết quả Big Test** có điểm từng kỹ năng và nhận xét của giáo viên.',
            ],
            'tips' => [
                'Phụ huynh có nhiều con học tại trung tâm: chọn tên con ở ô chọn học viên trên đầu trang để xem từng bé.',
                'Menu **☰** góc trên bên trái mở lại các mục của cổng và trang **Hướng dẫn sử dụng** này.',
            ],
        ],
        [
            'id' => 'nop-bai-tap',
            'title' => 'Học tập và nộp bài tập',
            'icon' => 'upload_file',
            'menu' => 'Thanh dưới → Học tập',
            'route' => 'portal.student.homework',
            'intro' => 'Bài tập về nhà của lớp hiện ở đây, chia thành từng hạng mục: quay video, viết từ vựng, workbook, sách bổ trợ, quiz. Bạn chụp ảnh hoặc quay video bài làm rồi nộp ngay trên điện thoại.',
            'images' => [
                ['src' => 'hoc-vien/bai-tap.webp', 'caption' => 'Bài tập về nhà và các hạng mục cần nộp', 'mobile' => true],
                ['src' => 'hoc-vien/nop-bai.webp', 'caption' => 'Hộp thoại Nộp bài tập', 'mobile' => true],
            ],
            'steps' => [
                'Bấm **Học tập** ở thanh dưới. Khối **Bài tập về nhà** hiện tên bài, lớp và **Hạn nộp**.',
                'Ở hạng mục cần nộp (nhãn **Chưa nộp**), bấm nút tải lên, ví dụ **Tải lên video** hoặc **Tải lên bài viết**.',
                'Bấm vào khung **Kéo thả hoặc bấm để chọn tệp tải lên**, chọn video, ảnh chụp hoặc file PDF (tối đa 50MB). Có thể ghi lời nhắn ở ô **Ghi chú gửi thầy cô**.',
                'Bấm **Xác nhận nộp bài**. Hạng mục chuyển sang **Chờ giáo viên chấm**.',
                'Nộp nhầm: bấm **Sửa / Nộp lại** để thay tệp. Với hạng mục quiz, bấm **Mở link Quiz trực tuyến** để làm bài rồi nộp kết quả.',
            ],
            'tips' => [
                'Kéo xuống dưới để đọc **Nhận xét buổi học** của giáo viên và xem **Bảng điểm**.',
            ],
        ],
        [
            'id' => 'luyen-phat-am',
            'title' => 'Luyện phát âm',
            'icon' => 'mic',
            'menu' => 'Thanh dưới → Phát âm',
            'route' => 'portal.student.pronunciation',
            'intro' => 'Học viên nghe file mẫu giáo viên gửi, thu âm giọng đọc của mình và nộp để giáo viên chấm điểm, nhận xét.',
            'images' => [
                ['src' => 'hoc-vien/phat-am.webp', 'caption' => 'Đặt tên bài, chạm micro để thu âm, rồi Nộp bài ghi âm', 'mobile' => true],
            ],
            'steps' => [
                'Bấm **Phát âm** ở thanh dưới. Nếu lớp có file nghe, khối **Bài nghe của lớp** hiện danh sách: nghe mẫu rồi bấm **Chọn** để luyện bài đó.',
                'Nếu chưa có file nghe, tự gõ tên bài vào ô **Bài đang luyện**, ví dụ "Unit 1 - Greetings".',
                'Chạm nút micro để bắt đầu ghi âm, cho phép trình duyệt dùng micro. Đọc xong, chạm lại để dừng.',
                'Bấm **Nghe lại** để kiểm tra. Chưa ưng thì bấm **Xóa bản ghi** và thu lại.',
                'Bấm **Nộp bài ghi âm**. Bài nộp hiện ở **Lịch sử của bạn**, kèm điểm khi giáo viên chấm xong.',
            ],
            'tips' => [
                'Nên thu âm ở chỗ yên tĩnh, để điện thoại cách miệng khoảng một gang tay.',
            ],
        ],
        [
            'id' => 'thong-bao',
            'title' => 'Đọc thông báo của trung tâm',
            'icon' => 'notifications',
            'menu' => 'Thanh dưới → Thông báo',
            'route' => 'portal.student.notifications',
            'intro' => 'Hộp thư thông báo nhận tin từ trung tâm như xác nhận đã thu học phí, nhắc học phí, chúc mừng sinh nhật, lịch nghỉ lễ.',
            'images' => [
                ['src' => 'hoc-vien/thong-bao.webp', 'caption' => 'Hộp thư thông báo: thông báo chưa đọc có nền màu và chấm đỏ', 'mobile' => true],
            ],
            'steps' => [
                'Bấm **Thông báo** ở thanh dưới (số đỏ trên chuông là số thông báo chưa đọc). Thông báo mới nhất nằm trên cùng, thông báo chưa đọc có nền màu và chấm đỏ bên trái.',
                'Đọc xong một thông báo, bấm **Đánh dấu đã đọc**. Muốn đánh dấu hết, bấm **Đánh dấu tất cả đã đọc**.',
                'Thông báo không cần giữ: bấm **Xóa** rồi xác nhận.',
            ],
        ],
        [
            'id' => 'khao-sat',
            'title' => 'Làm khảo sát',
            'icon' => 'assignment',
            'menu' => 'Thanh dưới → Khảo sát → Khảo sát định kỳ',
            'route' => 'portal.student.survey',
            'intro' => 'Trung tâm mở các đợt khảo sát định kỳ về chất lượng dạy học, cơ sở vật chất, giáo trình. Ý kiến của bạn giúp trung tâm cải thiện.',
            'images' => [
                ['src' => 'hoc-vien/khao-sat.webp', 'caption' => 'Khảo sát đang mở, kèm hạn trả lời', 'mobile' => true],
                ['src' => 'hoc-vien/khao-sat-tra-loi.webp', 'caption' => 'Hộp thoại Phản hồi khảo sát: chọn số sao và viết ý kiến', 'mobile' => true],
            ],
            'steps' => [
                'Bấm **Khảo sát** ở thanh dưới, chọn tab **Khảo sát định kỳ**.',
                'Mục **Khảo sát đang mở** liệt kê các đợt còn hạn, mỗi dòng ghi **Hạn** trả lời. Bấm vào một khảo sát để mở hộp thoại **Phản hồi khảo sát**.',
                'Chọn từ 1 đến 5 sao ở **Mức độ hài lòng chung** (mặc định 5 sao), viết ý kiến vào ô **Ý kiến của bạn** (bắt buộc), rồi bấm **Gửi phản hồi khảo sát**.',
                'Các bài đã gửi hiện ở **Khảo sát đã gửi**, kèm số sao bạn đã chấm.',
            ],
            'tips' => [
                'Nếu thấy "Hiện chưa có khảo sát nào đang mở", trung tâm chưa mở đợt mới.',
            ],
        ],
        [
            'id' => 'gop-y',
            'title' => 'Gửi góp ý theo chặng học',
            'icon' => 'rate_review',
            'menu' => 'Khảo sát → Feedback chặng học (hoặc menu ☰ → Gửi góp ý)',
            'route' => 'portal.student.feedback',
            'intro' => 'Khi lớp học xong một chặng, trung tâm mở cổng góp ý cho chặng đó. Phụ huynh, học sinh chấm sao và viết cảm nhận về giáo trình, giáo viên.',
            'images' => [
                ['src' => 'hoc-vien/gop-y.webp', 'caption' => 'Đánh giá chặng học đang mở thu thập', 'mobile' => true],
            ],
            'steps' => [
                'Bấm **Khảo sát** ở thanh dưới, chọn tab **Feedback chặng học**. Khối **Chặng học đang mở thu thập** cho biết chặng và lớp đang được góp ý.',
                'Chọn số sao ở **Mức độ hài lòng chung** (1 đến 5).',
                'Tích **Lĩnh vực cần góp ý**: **Học thuật**, **Giáo viên** hoặc **Khác**.',
                'Viết cảm nhận ở **Nội dung feedback chi tiết**, rồi bấm **Gửi feedback**. Chỉ cần điền ít nhất một trong ba mục.',
            ],
            'tips' => [
                'Trong thời gian đợt góp ý còn mở, bạn vẫn sửa lại được nội dung đã gửi.',
                'Việc cần phản ánh gấp: liên hệ trực tiếp Cố vấn học tập hoặc hotline trung tâm.',
            ],
        ],
        [
            'id' => 'hoc-phi',
            'title' => 'Xem học phí và báo đã đóng học phí',
            'icon' => 'account_balance_wallet',
            'menu' => 'Trang chủ → Thông tin học phí',
            'route' => 'portal.student.home',
            'intro' => 'Khối Thông tin học phí cho biết tổng đã đóng, còn nợ và học phí dự kiến khóa tới. Sau khi chuyển khoản, bạn báo cho trung tâm để Kế toán đối soát.',
            'images' => [
                ['src' => 'hoc-vien/hoc-phi.webp', 'caption' => 'Khối Thông tin học phí với nút Báo đóng và Lịch sử', 'mobile' => true],
                ['src' => 'hoc-vien/bao-dong-hoc-phi.webp', 'caption' => 'Hộp thoại Báo đóng học phí', 'mobile' => true],
            ],
            'steps' => [
                'Ở **Trang chủ**, kéo xuống khối **Thông tin học phí**. Xem **Tổng đã đóng** và **Còn nợ**.',
                'Bấm **Lịch sử** (hoặc **Xem lịch sử thu học phí**) để xem các đợt đã nộp.',
                'Đã chuyển khoản học phí: bấm **Báo đóng**.',
                'Nhập **Số tiền đã chuyển (VNĐ)** và **Nội dung chuyển khoản / Ghi chú** (mã giao dịch hoặc nội dung chuyển tiền), bấm **Gửi xác nhận**.',
                'Khi Kế toán xác nhận đã thu, bạn nhận thông báo trong mục **Thông báo**.',
            ],
        ],
        [
            'id' => 'sua-thong-tin',
            'title' => 'Sửa thông tin liên hệ của học viên',
            'icon' => 'edit',
            'menu' => 'Trang chủ → Thông tin học sinh → Sửa',
            'route' => 'portal.student.home',
            'intro' => 'Phụ huynh tự cập nhật số điện thoại liên hệ, địa chỉ và gửi ghi chú cho trung tâm, giáo viên (ví dụ bé bị dị ứng, xin vào muộn).',
            'images' => [
                ['src' => 'hoc-vien/sua-thong-tin.webp', 'caption' => 'Cập nhật thông tin học viên', 'mobile' => true],
            ],
            'steps' => [
                'Ở **Trang chủ**, kéo xuống cuối tới khối **Thông tin học sinh**, bấm **Sửa**.',
                'Sửa **Số điện thoại liên hệ**, **Địa chỉ**, viết **Ghi chú cho trung tâm / giáo viên** nếu cần.',
                'Bấm **Lưu thay đổi**.',
            ],
            'tips' => [
                'Họ tên, ngày sinh, lớp học do trung tâm quản lý. Cần sửa thì báo Học vụ.',
            ],
        ],
        [
            'id' => 'doi-mat-khau',
            'title' => 'Đổi mật khẩu',
            'icon' => 'lock',
            'menu' => 'Biểu tượng người góc trên → Tài khoản & đổi mật khẩu',
            'route' => 'profile.edit',
            'intro' => 'Nên đổi mật khẩu trung tâm cấp sang mật khẩu riêng của gia đình để giữ an toàn cho tài khoản.',
            'images' => [
                ['src' => 'hoc-vien/tai-khoan.webp', 'caption' => 'Thông tin tài khoản: mã học viên và nút Tài khoản & đổi mật khẩu', 'mobile' => true],
                ['src' => 'hoc-vien/doi-mat-khau.webp', 'caption' => 'Khối Đổi mật khẩu', 'mobile' => true],
            ],
            'steps' => [
                'Ở **Trang chủ**, bấm biểu tượng người ở góc phải khung MENGLISH để mở trang tổng hợp của cổng.',
                'Kéo xuống khối **Thông tin tài khoản** (có **Mã học viên**), bấm **Tài khoản & đổi mật khẩu**.',
                'Ở khối **Đổi mật khẩu**, nhập **Mật khẩu hiện tại**, **Mật khẩu mới** và **Xác nhận mật khẩu mới**.',
                'Bấm **Cập nhật mật khẩu**. Lần đăng nhập sau dùng mật khẩu mới.',
            ],
            'tips' => [
                'Bấm biểu tượng con mắt trong ô mật khẩu để xem lại chữ vừa gõ.',
            ],
        ],
    ],
];
