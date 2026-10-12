<?php

/*
 * Hướng dẫn dùng chung cho mọi vai trò (xem App\Support\Guide\UserGuide). Cấu trúc mỗi mục:
 *   id      — neo trên trang (#id), duy nhất trong file
 *   title   — tên chức năng
 *   icon    — Material Symbols
 *   menu    — đường dẫn trong menu ("Khu → Mục"), để người đọc biết bấm ở đâu
 *   route   — (tuỳ chọn) route mở màn hình; nút "Mở màn hình" chỉ hiện khi người xem có quyền
 *   intro   — 1–2 câu: chức năng này dùng để làm gì
 *   images  — ảnh trong public/images/huong-dan/: [src, caption, mobile (ảnh điện thoại)]
 *   steps   — các bước; **chữ đậm** = tên nút / mục cần bấm
 *   tips    — lưu ý (tuỳ chọn)
 */
return [
    'label' => 'Dùng chung',
    'icon' => 'apps',
    'summary' => 'Những thao tác ai cũng dùng: đăng nhập, đổi mật khẩu, xem thông báo, tìm nhanh màn hình và chấm công trên điện thoại.',
    'sections' => [
        [
            'id' => 'dang-nhap',
            'title' => 'Đăng nhập và đổi mật khẩu',
            'icon' => 'login',
            'menu' => 'Ảnh đại diện góc trên phải → Hồ sơ cá nhân → Cài đặt tài khoản',
            'route' => 'profile.edit',
            'intro' => 'Mỗi người có một tài khoản riêng. Học viên đăng nhập bằng email hoặc mã học viên do trung tâm cấp.',
            'images' => [
                ['src' => 'chung/dang-nhap.webp', 'caption' => 'Màn hình đăng nhập'],
                ['src' => 'chung/doi-mat-khau.webp', 'caption' => 'Hồ sơ cá nhân → Cài đặt tài khoản → Đổi mật khẩu'],
            ],
            'steps' => [
                'Mở portal, nhập **Email hoặc mã học viên** và **Mật khẩu**, bấm **Đăng nhập**.',
                'Lần đầu đăng nhập, nếu hệ thống yêu cầu đổi mật khẩu, hãy đặt mật khẩu mới trước khi dùng tiếp.',
                'Muốn đổi mật khẩu sau này: bấm ảnh đại diện góc trên bên phải, chọn **Hồ sơ cá nhân**, rồi bấm **Cài đặt tài khoản**.',
                'Ở khối **Đổi mật khẩu**, nhập mật khẩu hiện tại, mật khẩu mới hai lần, rồi bấm **Cập nhật mật khẩu**.',
            ],
            'tips' => [
                'Quên mật khẩu: bấm **Quên mật khẩu?** ở màn đăng nhập để nhận link đặt lại qua email, hoặc báo Học vụ / Admin đặt lại giúp.',
                'Không dùng chung tài khoản: mọi thao tác đều được ghi lại theo người đăng nhập.',
            ],
        ],
        [
            'id' => 'thong-bao',
            'title' => 'Xem thông báo',
            'icon' => 'notifications',
            'menu' => 'Chuông góc trên phải, hoặc Của tôi → Thông báo',
            'route' => 'notifications.index',
            'intro' => 'Hệ thống báo cho bạn khi có việc mới được giao, đơn được duyệt hoặc trả về, khách cần chăm sóc và các nhắc hạn.',
            'images' => [
                ['src' => 'chung/thong-bao.webp', 'caption' => 'Trang Thông báo'],
            ],
            'steps' => [
                'Bấm biểu tượng **chuông** trên thanh trên cùng để xem nhanh các thông báo mới nhất. Số đỏ là số thông báo chưa đọc.',
                'Bấm vào một thông báo để mở đúng màn hình cần xử lý.',
                'Bấm **Xem tất cả thông báo** (hoặc vào **Của tôi → Thông báo**) để xem toàn bộ, lọc **Chưa đọc**.',
                'Bấm **Đánh dấu tất cả đã đọc** khi đã xử lý xong.',
            ],
        ],
        [
            'id' => 'tim-kiem',
            'title' => 'Tìm nhanh màn hình, khách hàng, học viên',
            'icon' => 'search',
            'menu' => 'Ô tìm kiếm trên thanh trên cùng',
            'intro' => 'Gõ tên màn hình (ví dụ "lương", "điểm danh"), tên hoặc số điện thoại khách hàng, tên học viên, mã lớp để mở thẳng, không cần nhớ nó nằm ở menu nào.',
            'images' => [
                ['src' => 'chung/tim-kiem.webp', 'caption' => 'Kết quả tìm kiếm chia theo nhóm: màn hình, khách hàng, học viên, lớp'],
            ],
            'steps' => [
                'Bấm vào ô **Tìm màn hình, khách, học viên, lớp...** trên thanh trên cùng (trên điện thoại là biểu tượng kính lúp).',
                'Gõ ít nhất 2 ký tự, không cần gõ dấu, rồi nhấn **Enter**.',
                'Bấm vào kết quả để mở. Bạn chỉ thấy những gì vai trò của mình được xem.',
            ],
        ],
        [
            'id' => 'menu',
            'title' => 'Làm quen menu và trang tổng quan',
            'icon' => 'menu',
            'menu' => 'Thanh menu bên trái',
            'intro' => 'Menu bên trái chỉ hiện những khu bạn có quyền dùng, chia theo nhóm: Phê duyệt, Giao việc, Tuyển sinh, Đào tạo, Tài chính, Người dùng, Báo cáo, Cá nhân. Mỗi khu có các tab ở đầu trang.',
            'images' => [
                ['src' => 'chung/menu.webp', 'caption' => '(1) mục ở menu trái, (2) tab của khu, (3) nút hành động chính'],
            ],
            'steps' => [
                'Bấm một mục ở menu trái để vào khu đó. Các màn hình con của khu hiện thành **tab** ở đầu trang.',
                'Nút hành động chính của khu (ví dụ **Thêm khách mới**, **Tạo lớp mới**) nằm ở góc phải hàng tab.',
                'Trên điện thoại, bấm biểu tượng **☰** ở góc trên bên trái để mở menu.',
                'Mục **Hướng dẫn sử dụng** ở cuối menu luôn mở lại trang này.',
            ],
        ],
        [
            'id' => 'cham-cong-dien-thoai',
            'title' => 'Chấm công trên điện thoại (nhân sự)',
            'icon' => 'add_a_photo',
            'menu' => 'Trên điện thoại: Của tôi → Chấm công',
            'intro' => 'Nhân sự chấm công vào, ra bằng ảnh chụp và vị trí GPS ngay trên điện thoại, xem lại lịch sử công và gửi đơn bổ sung công, xin đi muộn, về sớm hoặc xin nghỉ.',
            'images' => [
                ['src' => 'chung/cham-cong.webp', 'caption' => 'Màn chấm công', 'mobile' => true],
                ['src' => 'chung/lich-su-cong.webp', 'caption' => 'Lịch sử công', 'mobile' => true],
                ['src' => 'chung/xin-duyet.webp', 'caption' => 'Gửi đơn xin duyệt', 'mobile' => true],
            ],
            'steps' => [
                'Mở portal trên điện thoại, đăng nhập, mở menu **☰** rồi chọn **Của tôi → Chấm công**.',
                'Bật GPS, bấm **Chấm công vào** (cuối ngày là **Chấm công ra**) và cho phép trình duyệt dùng camera, vị trí.',
                'Chụp ảnh khuôn mặt tại cơ sở rồi xác nhận. Giờ chấm lấy theo máy chủ. Bạn phải đứng trong bán kính cơ sở (thường 150 m) mới chấm được; nếu đi muộn, hệ thống tự lập biên bản chờ bạn giải trình.',
                'Bấm **Lịch sử** ở thanh dưới để xem lại các ngày công.',
                'Quên chấm, đi muộn, về sớm hay xin nghỉ: bấm **Xin duyệt**, chọn **Bổ sung công**, **Xin đi muộn / về sớm** hoặc **Xin nghỉ**, nhập lý do rồi gửi. Kết quả duyệt hiện ở mục **Đơn của tôi**.',
            ],
            'tips' => [
                'Chỉ chấm được trên điện thoại. Trên máy tính, mục này không hiện. Nút **Bản đầy đủ** ở thanh dưới mở lại giao diện đầy đủ của portal.',
                'Giáo viên, trợ giảng check-in ca dạy ở Cổng Giáo viên (xem hướng dẫn Giáo viên / Trợ giảng).',
            ],
        ],
    ],
];
