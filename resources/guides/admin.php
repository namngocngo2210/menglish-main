<?php

/*
 * Hướng dẫn cho vai trò Admin (xem App\Support\Guide\UserGuide và cấu trúc mục trong resources/guides/chung.php).
 * Chỉ tập trung vào việc riêng của Admin: duyệt, chốt lương, tài khoản, phân quyền, chi nhánh, cài đặt, báo cáo.
 */
return [
    'label' => 'Admin',
    'icon' => 'admin_panel_settings',
    'summary' => 'Admin là người duy nhất duyệt phiếu thu, hoàn tiền, hủy hóa đơn và đơn chấm công, chốt bảng lương mỗi kỳ, quản lý tài khoản, vai trò, chi nhánh và toàn bộ cài đặt hệ thống.',
    'sections' => [
        [
            'id' => 'can-duyet',
            'title' => 'Việc cần duyệt: một chỗ cho mọi yêu cầu',
            'icon' => 'fact_check',
            'menu' => 'Phê duyệt → Cần duyệt → Việc cần duyệt',
            'route' => 'approvals.index',
            'intro' => 'Mọi yêu cầu đang chờ Admin duyệt được gom về một trang, chia theo nhóm Học phí, Đào tạo, Công việc và Nhân sự. Số đỏ cạnh mục Cần duyệt ở menu là tổng số việc đang chờ.',
            'images' => [
                ['src' => 'admin/can-duyet.webp', 'caption' => 'Lọc theo nhóm, tick chọn nhiều mục rồi Duyệt hàng loạt'],
                ['src' => 'admin/can-duyet-chi-tiet.webp', 'caption' => 'Bấm vào một dòng để xem chi tiết và duyệt'],
            ],
            'steps' => [
                'Bấm **Cần duyệt** ở menu trái. Trang mở ở tab **Việc cần duyệt**.',
                'Bấm chip **Học phí**, **Đào tạo**, **Công việc** hoặc **Nhân sự** để chỉ xem một nhóm.',
                'Bấm vào một dòng để mở chi tiết, kiểm tra rồi bấm **Duyệt** hoặc **Từ chối** (từ chối phải nhập lý do). Cần xem đầy đủ hồ sơ thì bấm **Mở màn gốc**.',
                'Muốn duyệt nhiều mục cùng lúc: tick ô vuông đầu dòng (hoặc ô ở tiêu đề nhóm để chọn cả nhóm), rồi bấm **Duyệt hàng loạt** trên thanh hiện ở đáy trang.',
                'Mỗi nhóm chỉ hiện vài mục mới nhất. Bấm **Xem tất cả** để sang màn gốc của nhóm đó.',
            ],
            'tips' => [
                'Nhóm ghi "duyệt tại màn gốc" (ví dụ Xác nhận chính thức) không duyệt hàng loạt được, bạn phải mở màn gốc để xử lý.',
                'Khi duyệt hàng loạt, mục nào không hợp lệ sẽ được báo lại riêng, các mục khác vẫn được duyệt bình thường.',
            ],
        ],
        [
            'id' => 'duyet-phieu-thu',
            'title' => 'Duyệt phiếu thu học phí',
            'icon' => 'receipt_long',
            'menu' => 'Phê duyệt → Cần duyệt → Phiếu thu',
            'route' => 'tuition.receipts.approve',
            'intro' => 'Phiếu thu do Học vụ, Tư vấn hoặc Quản lý cơ sở lập chỉ trở thành doanh thu và gạch công nợ khi Admin duyệt. Bạn đối chiếu số tiền, chứng từ chuyển khoản hoặc biên lai tiền mặt trước khi duyệt.',
            'images' => [
                ['src' => 'admin/duyet-phieu-thu.webp', 'caption' => 'Hàng đợi phiếu thu chờ duyệt'],
                ['src' => 'admin/duyet-phieu-thu-chi-tiet.webp', 'caption' => 'Chi tiết phiếu thu: duyệt hoặc từ chối'],
            ],
            'steps' => [
                'Vào **Cần duyệt → Phiếu thu**. Tab **Chờ duyệt** liệt kê các phiếu đang chờ, lọc thêm theo cơ sở hoặc hình thức thanh toán nếu cần.',
                'Bấm **Xem** ở dòng phiếu cần xử lý để mở chi tiết.',
                'Kiểm tra học viên, bảng kê khoản thu và mục **Minh chứng chuyển khoản (UNC) / Biên lai** (bấm kính lúp để phóng to ảnh).',
                'Đúng thì bấm **Duyệt phiếu thu**, rồi **Xác nhận phê duyệt**. Hệ thống gạch công nợ và cấp số hóa đơn.',
                'Sai thì bấm **Từ chối phiếu thu**, ghi rõ lý do rồi **Xác nhận từ chối**. Người lập sẽ sửa và gửi duyệt lại.',
            ],
            'tips' => [
                'Phiếu tiền mặt có nhãn **Chưa nộp về TK** cho tới khi tiền được nộp về tài khoản công ty. Bấm **Xác nhận đã nộp về TK công ty** khi đã nhận tiền.',
                'Trang tự làm mới sau 90 giây, không cần tải lại.',
            ],
        ],
        [
            'id' => 'hoan-tien-huy-hoa-don',
            'title' => 'Hoàn tiền, khất nợ và hủy hóa đơn',
            'icon' => 'currency_exchange',
            'menu' => 'Phê duyệt → Cần duyệt → Hoàn tiền & Khất nợ / Hủy hóa đơn',
            'route' => 'tuition.refunds',
            'intro' => 'Các hồ sơ hoàn phí, chuyển nhượng, bảo lưu, khất nợ và yêu cầu hủy hóa đơn đều cần Admin duyệt. Hoàn tiền bắt buộc có ảnh bằng chứng chi tiền.',
            'images' => [
                ['src' => 'admin/hoan-tien.webp', 'caption' => 'Yêu cầu hoàn tiền, khất nợ chờ phê duyệt'],
                ['src' => 'admin/huy-hoa-don.webp', 'caption' => 'Danh sách yêu cầu hủy hóa đơn'],
            ],
            'steps' => [
                'Vào **Cần duyệt → Hoàn tiền & Khất nợ**. Khối **Yêu cầu chờ phê duyệt** ở đầu trang là các hồ sơ đang chờ bạn.',
                'Bấm nút dấu tích để duyệt. Với hoàn phí, tải lên **Ảnh bằng chứng chi tiền** (ủy nhiệm chi hoặc biên nhận đã ký), chọn thu hồi hoa hồng nếu có, rồi bấm **Duyệt**.',
                'Bấm nút dấu X để từ chối và nhập lý do.',
                'Với hóa đơn: vào tab **Hủy hóa đơn**, bấm **Xem** ở yêu cầu cần xử lý, rồi chọn **Duyệt hủy hóa đơn** (xác nhận lại bằng **Xác nhận duyệt hủy ngay**) hoặc **Từ chối hủy**.',
            ],
            'tips' => [
                'Hồ sơ hoàn phí nên xử lý trong 1 tuần và trong cùng tháng. Hồ sơ quá hạn có cờ "Quá hạn xử lý" nhưng vẫn duyệt được.',
                'Duyệt hủy một phiếu đã duyệt sẽ tự trừ lại doanh thu và trả lại công nợ cho học viên. Số hóa đơn đã hủy không được dùng lại.',
            ],
        ],
        [
            'id' => 'don-cham-cong',
            'title' => 'Duyệt đơn chấm công và xin nghỉ',
            'icon' => 'event_available',
            'menu' => 'Phê duyệt → Cần duyệt → Việc cần duyệt → chip Nhân sự',
            'route' => 'approvals.index',
            'intro' => 'Đơn bổ sung công, xin đi muộn, về sớm và xin nghỉ mà nhân sự gửi từ điện thoại đều về đây cho Admin duyệt.',
            'images' => [
                ['src' => 'admin/don-cham-cong.webp', 'caption' => 'Nhóm Nhân sự: Đơn chấm công & nghỉ'],
            ],
            'steps' => [
                'Vào **Cần duyệt**, bấm chip **Nhân sự**.',
                'Đọc tên người gửi, loại đơn, ngày và lý do ngay trên dòng.',
                'Bấm vào dòng để mở chi tiết, rồi bấm **Duyệt** hoặc **Từ chối** (kèm lý do).',
                'Nhiều đơn hợp lệ: tick chọn rồi bấm **Duyệt hàng loạt**.',
            ],
            'tips' => [
                'Đơn rơi vào kỳ lương đã chốt sẽ không duyệt được nữa. Hãy xử lý đơn trước khi chốt bảng lương.',
            ],
        ],
        [
            'id' => 'ky-luong',
            'title' => 'Chốt và trả bảng lương theo kỳ',
            'icon' => 'payments',
            'menu' => 'Người dùng → Lương & Phạt → Bảng lương theo kỳ',
            'route' => 'payroll.periods.index',
            'intro' => 'Mỗi tháng có một kỳ lương. Admin tạo kỳ, tính lại khi số liệu thay đổi, chốt bảng lương khi đủ điều kiện và đánh dấu đã trả sau khi chi lương.',
            'images' => [
                ['src' => 'admin/ky-luong.webp', 'caption' => 'Danh sách kỳ lương và trạng thái'],
                ['src' => 'admin/ky-luong-chi-tiet.webp', 'caption' => 'Chi tiết kỳ: tính lại, chốt, đánh dấu đã trả'],
            ],
            'steps' => [
                'Vào **Lương & Phạt → Bảng lương theo kỳ**. Kỳ mới chưa có thì bấm **Tạo kỳ lương mới**, chọn tháng, năm rồi bấm **Khởi tạo & Tính toán**.',
                'Bấm **Chi tiết bảng lương** ở kỳ cần xử lý. Xem lịch chốt ở đầu trang: chốt KPI, chốt công, chốt lỗi và ngày trả lương.',
                'Khi chấm công, KPI hoặc biên bản vi phạm thay đổi, bấm **Đồng bộ & Tính lại**.',
                'Nếu có thông báo đỏ "Còn N nhân sự chưa chốt KPI", bấm **Xem danh sách** để xử lý cho xong, rồi tính lại.',
                'Đủ điều kiện thì bấm **Chốt bảng lương**. Sau khi chi lương, bấm **Đánh dấu đã trả**. Bấm **Xuất Excel** để lưu bảng lương.',
            ],
            'tips' => [
                'Nút **Chốt bảng lương** bị khóa khi còn nhân sự chưa chốt KPI. Bảng lương chỉ chốt được từ ngày ghi trong lịch chốt (sau hạn chốt công và chốt lỗi).',
                'Kỳ đã chốt sẽ khóa chấm công và đơn xin nghỉ của tháng đó.',
                'Bấm tên nhân sự trong bảng để mở phiếu lương chi tiết của người đó.',
            ],
        ],
        [
            'id' => 'nguoi-dung',
            'title' => 'Tạo và quản lý tài khoản người dùng',
            'icon' => 'manage_accounts',
            'menu' => 'Người dùng → Người dùng & Tài khoản',
            'route' => 'users.index',
            'intro' => 'Tạo tài khoản cho nhân sự mới, gán vai trò và cơ sở, nhập hợp đồng và lương, khóa tài khoản khi nghỉ việc và đặt lại mật khẩu khi có người quên.',
            'images' => [
                ['src' => 'admin/nguoi-dung.webp', 'caption' => 'Danh sách người dùng và các thao tác'],
                ['src' => 'admin/nguoi-dung-them.webp', 'caption' => 'Thêm người dùng mới'],
            ],
            'steps' => [
                'Bấm **Thêm người dùng mới**. Ở tab **Tài khoản**, nhập họ tên, email, cơ sở, **Vai trò & Chức vụ**, tick vai trò kiêm nhiệm nếu có, và mật khẩu khởi tạo.',
                'Sang tab **Hồ sơ** và **Hợp đồng & Lương** để nhập thông tin cá nhân, loại hợp đồng, lương cơ bản hoặc thù lao giờ dạy, rồi bấm **Lưu tài khoản**.',
                'Tìm người dùng bằng ô tìm kiếm hoặc lọc theo cơ sở, vai trò, trạng thái.',
                'Ở cột **Hành động**: biểu tượng khiên mở **Phân quyền cá nhân**, biểu tượng cấm để **Vô hiệu hóa** tài khoản, con mắt để xem hồ sơ nhanh.',
                'Bấm nút ba chấm để **Sửa thông tin**, đổi **Vai trò & kiêm nhiệm**, **Đặt lại mật khẩu** hoặc **Xóa tài khoản**.',
            ],
            'tips' => [
                'Tài khoản mới phải đổi mật khẩu ở lần đăng nhập đầu tiên.',
                'Nhân sự nghỉ việc nên vô hiệu hóa thay vì xóa, để giữ lại lịch sử thao tác và bảng lương.',
                'Dòng cảnh báo vàng ở đầu trang báo hợp đồng sắp hoặc đã hết hạn. Bấm **lọc danh sách** để xem đó là ai.',
            ],
        ],
        [
            'id' => 'vai-tro-quyen',
            'title' => 'Vai trò và phân quyền',
            'icon' => 'shield_person',
            'menu' => 'Cài đặt → Hệ thống → Vai trò / Quyền',
            'route' => 'roles.index',
            'intro' => 'Mỗi vai trò là một tập quyền. Đổi quyền của vai trò thì menu và nút của mọi người thuộc vai trò đó đổi theo ngay.',
            'images' => [
                ['src' => 'admin/vai-tro.webp', 'caption' => 'Danh sách vai trò'],
                ['src' => 'admin/vai-tro-cau-hinh.webp', 'caption' => 'Ma trận quyền của một vai trò'],
            ],
            'steps' => [
                'Bấm **Cài đặt** ở cuối menu trái, chọn **Vai trò**.',
                'Bấm **Cấu hình quyền** ở vai trò cần sửa.',
                'Trong **Ma trận quyền theo module**, tick hoặc bỏ tick các quyền Xem, Thêm, Sửa, Xóa và thao tác khác. Di chuột lên quyền để đọc mô tả.',
                'Bấm **Lưu vai trò**. Thay đổi có hiệu lực ngay và được ghi vào Nhật ký vận hành.',
                'Cần vai trò mới: bấm **Thêm vai trò mới**, hoặc **Nhân bản** một vai trò có sẵn rồi chỉnh lại.',
            ],
            'tips' => [
                'Vai trò Admin luôn có toàn quyền và không sửa được.',
                'Chỉ cần mở hoặc chặn quyền cho riêng một người: dùng **Phân quyền cá nhân** ở trang Người dùng & Tài khoản.',
                'Mục **Quyền** (hoặc nút **Danh mục quyền**) liệt kê mọi quyền và số vai trò đang dùng.',
            ],
        ],
        [
            'id' => 'chi-nhanh',
            'title' => 'Cơ sở, chi nhánh và vùng chấm công',
            'icon' => 'apartment',
            'menu' => 'Cài đặt → Tổ chức & Đào tạo → Cơ sở & Chi nhánh',
            'route' => 'branches.index',
            'intro' => 'Quản lý danh sách chi nhánh và cài tọa độ, bán kính, giờ làm để nhân sự chấm công bằng GPS trên điện thoại.',
            'images' => [
                ['src' => 'admin/chi-nhanh.webp', 'caption' => 'Danh sách cơ sở và chi nhánh'],
                ['src' => 'admin/chi-nhanh-cham-cong.webp', 'caption' => 'Cài đặt chấm công của một chi nhánh'],
            ],
            'steps' => [
                'Vào **Cài đặt → Cơ sở & Chi nhánh**. Bấm **Thêm Chi Nhánh Mới**, nhập mã, tên, địa chỉ, hotline rồi bấm **Tạo Chi Nhánh**.',
                'Ở cột **Thao tác**, bấm biểu tượng ghim bản đồ để mở **Cài đặt chấm công**.',
                'Nhập vĩ độ, kinh độ (dán từ Google Maps) hoặc đứng tại cơ sở và bấm **Lấy vị trí hiện tại**.',
                'Đặt **Bán kính được chấm công**, **Giờ vào**, **Giờ ra**, **Cho phép muộn**, rồi bấm **Lưu cài đặt**.',
            ],
            'tips' => [
                'Chi nhánh có nhãn "Chưa cài toạ độ" thì nhân sự ở đó chưa chấm công GPS được.',
                'Bán kính nên để 50 đến 150 m vì GPS điện thoại trong nhà thường lệch 10 đến 50 m.',
            ],
        ],
        [
            'id' => 'cai-dat',
            'title' => 'Cài đặt hệ thống',
            'icon' => 'settings',
            'menu' => 'Cài đặt (cuối menu trái)',
            'route' => 'settings.index',
            'intro' => 'Trang Cài đặt gom mọi danh mục và tham số: khóa học, phòng, ngày nghỉ lễ, tham số lương, đơn giá giáo viên, nhắc nợ, dải số hóa đơn, tài khoản ngân hàng, SLA và máy chủ.',
            'images' => [
                ['src' => 'admin/cai-dat.webp', 'caption' => 'Menu Cài đặt bên trái, ví dụ trang Cấu hình SLA'],
            ],
            'steps' => [
                'Bấm **Cài đặt** ở cuối menu trái. Menu con hiện thành 4 nhóm: **Tổ chức & Đào tạo**, **Lương**, **Học phí**, **Hệ thống**.',
                'Đầu kỳ học: kiểm tra **Khóa học & Bảng giá**, **Phòng học**, **Ngày nghỉ lễ** và **Khung giờ ca dạy**.',
                'Về lương: chỉnh **Tham số lương** (BHXH, công đoàn, quỹ KPI), **Đơn giá giáo viên** và **Mốc hoa hồng / Thưởng**, rồi bấm lưu ở từng trang.',
                'Về thu tiền: cấu hình **Tài khoản ngân hàng** (mỗi tài khoản gắn một hoặc nhiều cơ sở, để trống là dùng chung; mã QR trên phiếu thu lấy tài khoản của cơ sở học viên), **Dải số hóa đơn** và **Nhắc nợ học phí**.',
                'Trong **Cấu hình SLA**, chọn nhóm rồi bấm vào từng mục để sửa thời hạn, việc tự giao và mức tự lập biên bản.',
            ],
            'tips' => [
                'Đổi tham số lương chỉ áp dụng khi tính hoặc tính lại các kỳ về sau. Kỳ đã chốt hoặc đã trả không bị ảnh hưởng.',
                'Đổi đơn giá giáo viên tạo một phiên bản mới có ngày hiệu lực. Buổi dạy cũ vẫn tính theo giá cũ.',
            ],
        ],
        [
            'id' => 'doanh-thu',
            'title' => 'Báo cáo doanh thu tạm tính',
            'icon' => 'query_stats',
            'menu' => 'Báo cáo → Doanh thu → Doanh thu tạm tính',
            'route' => 'finance.reports.revenue',
            'intro' => 'Xem nhanh tổng thu, chi phí vận hành và doanh thu tạm tính của tháng, toàn hệ thống hoặc từng chi nhánh.',
            'images' => [
                ['src' => 'admin/doanh-thu.webp', 'caption' => 'Báo cáo doanh thu tạm tính'],
            ],
            'steps' => [
                'Vào **Doanh thu** ở nhóm Báo cáo trên menu trái.',
                'Chọn **Kỳ tháng** và chi nhánh (hoặc **Tất cả chi nhánh**), bấm **Lọc**.',
                'Ba thẻ đầu trang cho biết **Tổng thu thực tế**, **Chi phí vận hành** và **Doanh thu tạm tính** (thu trừ chi), kèm so sánh với tháng trước.',
                'Kéo xuống xem **Cơ cấu Nguồn Thu** và **Cơ cấu Chi Vận Hành**. Bấm **Xuất báo cáo** để tải file.',
            ],
            'tips' => [
                'Chỉ phiếu thu đã duyệt mới được tính. Phiếu nháp, chờ duyệt, bị từ chối hoặc đã hủy hóa đơn không được cộng.',
                'Chi lương chỉ được cộng vào chi phí khi kỳ lương đã chốt.',
            ],
        ],
        [
            'id' => 'xep-hang-kpi',
            'title' => 'Bảng xếp hạng KPI và thưởng',
            'icon' => 'emoji_events',
            'menu' => 'Báo cáo → Nhân sự & KPI → Xếp hạng KPI & Thưởng',
            'route' => 'payroll.kpi-leaderboard',
            'intro' => 'Bảng thi đua công khai về KPI giữ học sinh và hoa hồng theo từng kỳ lương. Bảng không hiện lương cơ bản hay khoản khấu trừ.',
            'images' => [
                ['src' => 'admin/xep-hang-kpi.webp', 'caption' => 'Bảng xếp hạng KPI & Hoa hồng'],
            ],
            'steps' => [
                'Vào **Nhân sự & KPI**, chọn tab **Xếp hạng KPI & Thưởng**.',
                'Chọn **Kỳ lương** và **Chi nhánh**, bấm **Xem**.',
                'Xem thứ hạng, số học sinh giữ được, đơn giá và tổng KPI của từng người.',
                'Muốn đổi các mốc thưởng, bấm **Cấu hình mốc hoa hồng**.',
            ],
            'tips' => [
                'Kỳ chưa chốt có nhãn "Số liệu tạm tính". Số chỉ cố định sau khi bảng lương được chốt.',
                'Tab **Phiếu KPI tháng** là nơi duyệt phiếu KPI từng người trước khi chốt lương.',
            ],
        ],
        [
            'id' => 'nhat-ky',
            'title' => 'Nhật ký vận hành',
            'icon' => 'history',
            'menu' => 'Cài đặt → Hệ thống → Nhật ký vận hành',
            'route' => 'activity-logs.index',
            'intro' => 'Mọi thao tác thêm, sửa, xóa và đăng nhập đều được ghi lại kèm dữ liệu trước và sau. Dùng khi cần tra ai đã làm gì, lúc nào.',
            'images' => [
                ['src' => 'admin/nhat-ky.webp', 'caption' => 'Lọc nhật ký theo phân hệ'],
                ['src' => 'admin/nhat-ky-chi-tiet.webp', 'caption' => 'Chi tiết đối chiếu dữ liệu trước và sau'],
            ],
            'steps' => [
                'Vào **Cài đặt → Nhật ký vận hành**.',
                'Bấm chip phân hệ (ví dụ **Học phí**, **Phân quyền**, **Lương & Chấm công**) hoặc lọc theo người thực hiện, loại thao tác, khoảng thời gian.',
                'Bấm biểu tượng con mắt ở cuối dòng để mở **Chi tiết đối chiếu**. Phần **So sánh trước / sau** tô đỏ giá trị cũ, tô xanh giá trị mới.',
                'Với thao tác cho phép khôi phục, nút **Hoàn tác** hiện ở đáy khung chi tiết. Bấm và xác nhận để trả lại giá trị cũ.',
                'Bấm **Xuất Excel** để lưu nhật ký theo bộ lọc đang chọn.',
            ],
        ],
    ],
];
