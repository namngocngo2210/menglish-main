<?php

// Hướng dẫn cho Tư vấn viên (Sales). Cấu trúc mục: xem resources/guides/chung.php.
return [
    'label' => 'Tư vấn viên',
    'icon' => 'headset_mic',
    'summary' => 'Tư vấn viên nhận và chăm sóc khách tuyển sinh của mình trong Khách hàng (CRM): thêm khách, ghi nhật ký liên hệ, hẹn test đầu vào, gửi kết quả cho phụ huynh và chốt khách vào lớp. Bạn chỉ thấy những khách mình phụ trách.',
    'sections' => [
        [
            'id' => 'tong-quan',
            'title' => 'Xem việc cần làm hôm nay',
            'icon' => 'dashboard',
            'menu' => 'Tổng quan',
            'route' => 'dashboard',
            'intro' => 'Trang Tổng quan gom việc của riêng bạn: lịch hẹn test, khách cần gọi lại, khách sắp quá hạn liên hệ và số khách đã chốt trong tháng.',
            'images' => [
                ['src' => 'tu-van/tong-quan.webp', 'caption' => 'Tổng quan của Tư vấn viên: các thẻ số liệu, lịch hẹn 7 ngày tới và khối Cần xử lý'],
            ],
            'steps' => [
                'Sau khi đăng nhập, bạn vào thẳng **Tổng quan**. Hàng thẻ trên cùng cho biết **Hẹn test hôm nay**, **Học thử 7 ngày tới**, **Cần gọi lại hôm nay**, việc đến hạn tuần này và **Khách chốt** trong tháng.',
                'Khối **Lịch hẹn 7 ngày tới** liệt kê lịch test đầu vào, học thử và hẹn gọi lại của khách bạn phụ trách, xếp theo ngày và giờ.',
                'Khối **Cần xử lý** nhắc những nhóm khách cần làm ngay, ví dụ **Khách chưa liên hệ >24h**. Bấm vào dòng đó để mở danh sách khách tương ứng.',
                'Khối **Việc của tôi trong tuần** hiện các đầu việc đến hạn. Bấm **Xem tất cả** để mở danh sách đầu việc.',
            ],
            'tips' => [
                'Mỗi sáng nên xử lý hết khối Cần xử lý trước, vì khách quá hạn liên hệ bị tính vào KPI.',
            ],
        ],
        [
            'id' => 'them-khach',
            'title' => 'Thêm khách mới hoặc nhập từ Excel',
            'icon' => 'person_add',
            'menu' => 'Khách hàng (CRM) → nút Thêm khách mới / Nhập Excel',
            'route' => 'crm.pipeline',
            'intro' => 'Mỗi khách quan tâm (gọi đến, nhắn tin, đến trung tâm) cần có hồ sơ trong CRM để được nhắc chăm sóc đúng hạn. Khách mới vào giai đoạn Mới và do bạn phụ trách.',
            'images' => [
                ['src' => 'tu-van/them-khach.webp', 'caption' => 'Hộp thoại Thêm khách mới'],
                ['src' => 'tu-van/nhap-excel.webp', 'caption' => 'Nhập khách hàng loạt từ file Excel'],
            ],
            'steps' => [
                'Vào **Khách hàng (CRM)**, bấm **Thêm khách mới** ở góc phải hàng tab.',
                'Nhập **Họ và tên**, **Số điện thoại** (10 số, bắt đầu bằng 0), chọn **Nguồn khách** và **Chi nhánh**. Có thể nhập thêm **Tên phụ huynh**.',
                'Mở **Thông tin bổ sung (tùy chọn)** để điền khóa học quan tâm, giá trị dự kiến, ngày sinh, email, hạn liên hệ tiếp theo và ghi chú ban đầu.',
                'Bấm **Lưu thông tin**. Khách xuất hiện ở cột **Mới** trên Kanban.',
                'Có nhiều khách từ sự kiện hoặc quảng cáo: bấm **Nhập Excel**, bấm **Tải file mẫu**, điền theo mẫu rồi chọn file, chọn **Chi nhánh nhận khách** và bấm **Kiểm tra dữ liệu** để xem trước trước khi nhập.',
            ],
            'tips' => [
                'Số điện thoại và email không được trùng với khách đang hoạt động. Nếu báo trùng, tìm khách đó trong Danh sách trước khi tạo mới.',
                'Mỗi file Excel tối đa 1.000 dòng. Cột bắt buộc: Họ tên và Số điện thoại.',
            ],
        ],
        [
            'id' => 'kanban',
            'title' => 'Theo dõi khách trên Kanban',
            'icon' => 'view_kanban',
            'menu' => 'Khách hàng (CRM) → Kanban',
            'route' => 'crm.pipeline',
            'intro' => 'Kanban xếp khách của bạn thành các cột theo giai đoạn: Mới, Đang tư vấn, Hẹn test, Test, Đã test, Gửi kết quả, Chờ xếp lớp, Đã chốt. Mỗi thẻ cho biết hạn chăm sóc tiếp theo.',
            'images' => [
                ['src' => 'tu-van/kanban.webp', 'caption' => 'Kanban tuyển sinh: mỗi cột là một giai đoạn, thẻ đỏ là khách quá hạn'],
            ],
            'steps' => [
                'Vào **Khách hàng (CRM) → Kanban**. Dùng ô tìm kiếm, bộ lọc **Nguồn** và **Ngày tạo** rồi bấm **Lọc** để thu hẹp danh sách.',
                'Nhìn dòng hạn ở cuối mỗi thẻ: **Quá hạn** (đỏ) là khách cần liên hệ ngay.',
                'Bấm vào thẻ để mở hồ sơ khách và chăm sóc tiếp.',
                'Với khách đã sẵn sàng đăng ký, bấm **Chốt & Xếp lớp** ngay trên thẻ.',
                'Bảng rộng hơn màn hình: bấm mũi tên ở mép phải để xem các cột còn lại.',
            ],
            'tips' => [
                'Giai đoạn khách do Học vụ hoặc Quản lý cơ sở chuyển. Trên thẻ, nút **Sửa giai đoạn** của bạn chỉ dùng để đánh dấu khách Thất bại.',
            ],
        ],
        [
            'id' => 'danh-sach',
            'title' => 'Lọc nhanh khách cần xử lý',
            'icon' => 'filter_list',
            'menu' => 'Khách hàng (CRM) → Danh sách',
            'route' => 'crm.customers.index',
            'intro' => 'Danh sách khách có các nút lọc nhanh ở đầu trang, giúp bạn thấy ngay khách chưa liên hệ, khách có lịch test hôm nay hay khách cần gọi lại.',
            'images' => [
                ['src' => 'tu-van/danh-sach.webp', 'caption' => 'Danh sách khách với các nút lọc nhanh và cột Hạn SLA liên hệ'],
            ],
            'steps' => [
                'Vào **Khách hàng (CRM) → Danh sách**.',
                'Bấm một nút lọc nhanh: **Chưa liên hệ >24h**, **Hẹn test hôm nay**, **Cần gọi lại** (chỉ hiện khi có khách), **Chờ xếp lớp**, **Đã nhập học** hoặc **Thất bại**. Số bên cạnh là số khách.',
                'Gõ tên hoặc số điện thoại vào ô **Tìm tên hoặc SĐT...**, chọn thêm **Nguồn**, **Giai đoạn** rồi bấm **Lọc**.',
                'Cột **Hạn SLA liên hệ** cho biết khách nào đã quá hạn. Bấm tên khách hoặc biểu tượng bút ở cột **Thao tác** để mở hồ sơ.',
            ],
        ],
        [
            'id' => 'cham-soc',
            'title' => 'Ghi nhật ký chăm sóc và đặt hạn liên hệ',
            'icon' => 'call',
            'menu' => 'Khách hàng (CRM) → bấm vào khách → Lịch sử hoạt động',
            'route' => 'crm.customers.index',
            'intro' => 'Sau mỗi lần gọi, nhắn Zalo hay gặp khách, hãy ghi lại trong hồ sơ. Ghi chú này cập nhật "Liên hệ gần nhất" và giúp người khác nắm được tình hình khách.',
            'images' => [
                ['src' => 'tu-van/ho-so-khach.webp', 'caption' => 'Hồ sơ khách: nút Chốt & Xếp lớp và menu ⋯ ở góc phải'],
                ['src' => 'tu-van/ghi-chu.webp', 'caption' => 'Ghi chú liên hệ mới trong Lịch sử hoạt động'],
                ['src' => 'tu-van/thong-tin-khach.webp', 'caption' => 'Tab Thông tin khách hàng: sửa thông tin và Hạn liên hệ tiếp theo'],
            ],
            'steps' => [
                'Mở hồ sơ khách từ Kanban hoặc Danh sách. Khối **Trạng thái & Hạn xử lý** cho biết giai đoạn hiện tại, lần liên hệ gần nhất và hạn chăm sóc tiếp theo.',
                'Kéo xuống khối **Lịch sử hoạt động**, gõ nội dung vào ô **Ghi chú nội dung liên hệ mới...**.',
                'Chọn **Hình thức**: **Gọi điện**, **Zalo/SMS**, **Trực tiếp**, **Test đầu vào** hoặc **Ghi chú**. Với gọi, nhắn, gặp, chọn thêm **Kết quả**: **Liên hệ được** hoặc **Không liên hệ được**.',
                'Bấm **Lưu ghi chú**. Ghi chú hiện ngay trong lịch sử, có tên bạn và giờ ghi.',
                'Hẹn khách gọi lại: bấm **Đặt hạn liên hệ** (hoặc mở tab **Thông tin khách hàng**), chọn **Hạn liên hệ tiếp theo** rồi bấm **Lưu thay đổi**. Cũng ở tab này bạn sửa số điện thoại, phụ huynh, khóa quan tâm, ghi chú nhu cầu.',
            ],
            'tips' => [
                'Khách báo "Sắp hết hạn" trước 24 giờ và "Quá hạn" khi qua hạn liên hệ. Ghi chú hoặc đặt hạn mới để tắt cảnh báo.',
                'Dùng ô **Tất cả hoạt động** phía trên để lọc lịch sử theo loại (Gọi điện, Hệ thống...).',
            ],
        ],
        [
            'id' => 'hen-test',
            'title' => 'Hẹn test đầu vào và gửi link test online',
            'icon' => 'event_available',
            'menu' => 'Khách hàng (CRM) → bấm vào khách → tab Đặt lịch & Kết quả → Lịch hẹn Test',
            'route' => 'crm.customers.index',
            'intro' => 'Khi khách đang ở bước Đang tư vấn, bạn chọn đề test theo cấp độ, hẹn ngày giờ và gửi link để khách làm bài online. Khách tự chuyển sang giai đoạn Hẹn test.',
            'images' => [
                ['src' => 'tu-van/hen-test.webp', 'caption' => 'Khối Lịch hẹn Test: chọn cấp độ, đề, ngày giờ rồi Gửi link test online'],
            ],
            'steps' => [
                'Mở hồ sơ khách, ở tab **Đặt lịch & Kết quả** tìm khối **Lịch hẹn Test** (nhãn **Chưa gửi đề**).',
                'Chọn **Chọn cấp độ** (ví dụ Lớp 2), rồi chọn đề trong **Danh sách đề tương ứng**.',
                'Chọn **Ngày hẹn làm test** và **Giờ hẹn**, bấm **Gửi link test online**.',
                'Nếu khách có email, hệ thống gửi email xác nhận lịch hẹn. Nếu không, một hộp thoại hiện nội dung xác nhận: bấm **Sao chép nội dung** rồi dán gửi phụ huynh qua Zalo.',
                'Sau khi gửi, khối hiện **Đã gửi link, chờ khách làm bài**. Dùng **Sao chép link test** hoặc **Gửi lại link** (link mới) để gửi lại cho khách qua Zalo/SMS.',
            ],
            'tips' => [
                'Khách ở bước Mới chưa hẹn test được: nhờ Học vụ / Quản lý cơ sở chuyển sang Đang tư vấn trước.',
                'Khách đã có kết quả nhưng cần test lại: bấm **Hẹn test lại** trong cùng khối.',
            ],
        ],
        [
            'id' => 'gui-ket-qua',
            'title' => 'Gửi kết quả test cho phụ huynh, theo dõi học thử',
            'icon' => 'forward_to_inbox',
            'menu' => 'Khách hàng (CRM) → bấm vào khách → tab Đặt lịch & Kết quả',
            'route' => 'crm.customers.index',
            'intro' => 'Khi khách làm xong bài test, điểm hiện ở khối Kết quả & Đánh giá. Bạn báo kết quả cho phụ huynh và ghi lại phản hồi của họ ngay trong hồ sơ.',
            'images' => [
                ['src' => 'tu-van/gui-ket-qua.webp', 'caption' => 'Kết quả test và khối Gửi kết quả & Phản hồi'],
            ],
            'steps' => [
                'Mở hồ sơ khách đã có điểm. Khối **Kết quả & Đánh giá** hiện điểm tổng và điểm từng kỹ năng. Bấm **Tải kết quả (PDF)** nếu có để gửi phụ huynh.',
                'Sau khi báo kết quả, ở khối **Gửi kết quả & Phản hồi** chọn **Ngày gửi KQ phụ huynh**.',
                'Ghi ý kiến của phụ huynh vào ô **Phản hồi của phụ huynh**, bấm **Lưu gửi kết quả**.',
                'Khối **Học thử** cho biết khách đã học thử mấy buổi, ở lớp nào, và **Nhận xét học thử** của giáo viên sau buổi học.',
            ],
            'tips' => [
                'Học vụ / Quản lý cơ sở là người xếp buổi học thử. Bạn báo họ khi khách muốn học thử, rồi theo dõi nhận xét trong hồ sơ.',
                'Bấm **Thang điểm & hướng dẫn nhận xét** để giải thích điểm cho phụ huynh.',
            ],
        ],
        [
            'id' => 'chot-xep-lop',
            'title' => 'Chốt khách, áp ưu đãi và xếp lớp',
            'icon' => 'how_to_reg',
            'menu' => 'Khách hàng (CRM) → hồ sơ khách → Chốt & Xếp lớp',
            'route' => 'crm.closing-wizard',
            'intro' => 'Khi khách đồng ý đăng ký, quy trình Chốt & Xếp lớp tạo học viên, tài khoản, học phí, xếp lớp và phiếu thu trong 4 bước. Khách ở Đang tư vấn, Đã test hoặc Gửi kết quả mới chốt được.',
            'images' => [
                ['src' => 'tu-van/chot-buoc-1.webp', 'caption' => 'Bước 1: chọn khách và khóa học đăng ký'],
                ['src' => 'tu-van/chot-uu-dai.webp', 'caption' => 'Bước 2: học phí niêm yết và chọn ưu đãi có sẵn'],
                ['src' => 'tu-van/chot-xep-lop.webp', 'caption' => 'Bước 3: chọn lớp phù hợp hoặc Xếp lớp sau'],
                ['src' => 'tu-van/chot-thu-phi.webp', 'caption' => 'Bước 4: tóm tắt hợp đồng, số tiền thu và phương thức thanh toán'],
            ],
            'steps' => [
                'Trong hồ sơ khách (hoặc trên thẻ Kanban), bấm **Chốt & Xếp lớp**. Ở **Bước 1**, kiểm tra **Khách cần chốt**, chọn **Khóa học đăng ký**, bấm **Tiếp tục: Tính học phí & Ưu đãi**.',
                'Ở **Bước 2**, học phí niêm yết tự điền. Chọn ưu đãi trong **Chương trình Ưu đãi / Voucher** nếu khách thuộc diện áp dụng, thêm khoản thu khác (giáo trình, phụ phí) nếu có, xem tổng ở cuối khối rồi bấm **Tiếp tục: Xếp lớp**.',
                'Ở **Bước 3**, bấm **Chọn lớp này** trên lớp phù hợp (còn chỗ, đúng cấp độ). Chưa có lớp hợp: chọn **Xếp lớp sau**, khách vào mục Chờ xếp lớp. Bấm **Tiếp tục: Xác nhận & Thu tiền**.',
                'Ở **Bước 4**, kiểm tra **Tóm tắt hợp đồng đào tạo**. Nếu khách đã đóng, tích **Đã đóng học phí đăng ký**, nhập **Số tiền thu thực tế đợt 1**, chọn **Chuyển khoản (VietQR)** hoặc **Tiền mặt tại quầy**.',
                'Bấm **Xem & In Bill** để in phiếu báo cho phụ huynh nếu cần, rồi bấm **Hoàn tất Chốt Deal, Xếp Lớp & Xuất Phiếu Thu**.',
            ],
            'tips' => [
                'Chưa thu tiền thì bỏ tích Đã đóng học phí đăng ký: hệ thống tạo việc "Nhắc thu học phí" cho người phụ trách, hạn 3 ngày.',
                'Thu tiền mặt phải nhập số hóa đơn giấy và tải ảnh hóa đơn lên. Phiếu thu được gửi Kế toán / Admin duyệt.',
                'Lớp hoặc khóa chưa có học phí niêm yết thì chưa chốt được: báo người quản lý khóa học nhập giá.',
            ],
        ],
        [
            'id' => 'that-bai',
            'title' => 'Đánh dấu khách thất bại',
            'icon' => 'person_off',
            'menu' => 'Khách hàng (CRM) → hồ sơ khách → ⋯ → Thất bại',
            'route' => 'crm.lost-deals',
            'intro' => 'Khách không đăng ký (học phí, lịch học, không liên lạc được...) thì đánh dấu Thất bại kèm lý do. Khách thất bại được lưu để đối soát và không mở lại.',
            'images' => [
                ['src' => 'tu-van/that-bai.webp', 'caption' => 'Ghi nhận lý do thất bại'],
                ['src' => 'tu-van/khach-that-bai.webp', 'caption' => 'Danh sách Khách thất bại với lý do không chốt'],
            ],
            'steps' => [
                'Mở hồ sơ khách, bấm nút **⋯** ở góc phải tiêu đề, chọn **Thất bại**. Trên Kanban, bạn cũng có thể bấm **Sửa giai đoạn** trên thẻ.',
                'Nhập lý do cụ thể, ví dụ "chưa phù hợp học phí", rồi bấm **Xác nhận**.',
                'Xem lại các khách đã dừng ở **Danh sách → Thất bại**. Tìm theo lý do, tên hoặc số điện thoại, bấm **Xuất Excel** nếu cần báo cáo.',
            ],
            'tips' => [
                'Chỉ đánh dấu khi chắc chắn: khách thất bại không mở lại được.',
            ],
        ],
        [
            'id' => 'da-nhap-hoc',
            'title' => 'Theo dõi khách đã nhập học',
            'icon' => 'school',
            'menu' => 'Khách hàng (CRM) → Danh sách → Đã nhập học',
            'route' => 'crm.customers.won',
            'intro' => 'Màn này tổng hợp khách bạn đã chốt: tổng giá trị hợp đồng, số tiền đã thu được duyệt, công nợ còn lại và lớp của từng khách.',
            'images' => [
                ['src' => 'tu-van/da-nhap-hoc.webp', 'caption' => 'Khách đã nhập học: số liệu tổng và danh sách khách đã có lớp'],
            ],
            'steps' => [
                'Vào **Khách hàng (CRM) → Danh sách**, bấm nút lọc **Đã nhập học**.',
                'Xem bốn thẻ: **Tổng khách đã chốt**, **Tổng giá trị hợp đồng**, **Thực thu đã duyệt**, **Công nợ còn lại**.',
                'Lọc theo **Lớp học** hoặc **Ngày chốt** rồi bấm **Lọc**. Cột **Tình trạng thu** cho biết khách đã đóng học phí chưa.',
                'Bấm biểu tượng con mắt để mở hồ sơ khách. Bấm **Xuất Excel** để tải danh sách.',
            ],
            'tips' => [
                'Khách chưa đóng đủ học phí là việc của bạn: liên hệ nhắc phụ huynh và ghi chú lại trong hồ sơ.',
            ],
        ],
        [
            'id' => 'kpi',
            'title' => 'Xem KPI của tôi',
            'icon' => 'insights',
            'menu' => 'Của tôi → KPI của tôi',
            'route' => 'kpi.mine',
            'intro' => 'KPI của Tư vấn viên tính theo từng kỳ lương, gồm chăm sóc khách hàng (khách trễ hạn liên hệ, báo cáo nộp muộn) và kết quả tuyển sinh (tỷ lệ chốt, phản ánh tư vấn sai).',
            'images' => [
                ['src' => 'tu-van/kpi.webp', 'caption' => 'KPI của tôi: tỉ lệ đạt và từng tiêu chí'],
            ],
            'steps' => [
                'Bấm **Của tôi** ở menu trái, chọn tab **KPI của tôi**.',
                'Chọn kỳ ở ô **Kỳ lương** góc phải để xem tháng khác.',
                'Ô **Tỉ lệ đạt** là kết quả chung. Mỗi tiêu chí ghi số hiện tại và ngưỡng tính điểm, ví dụ "≤ 2 case: 100%".',
                'Ô **Phiếu** cho biết phiếu KPI đang chờ duyệt hay đã chốt.',
            ],
            'tips' => [
                'Số liệu hệ thống cập nhật hằng ngày. Tiêu chí chấm tay chỉ có số khi người chấm điền cuối kỳ.',
            ],
        ],
        [
            'id' => 'de-xuat-viec',
            'title' => 'Đề xuất việc và gửi Ticket hỗ trợ',
            'icon' => 'support_agent',
            'menu' => 'Giao việc → Tạo đầu việc / Danh sách đầu việc; Người dùng → Ticket hỗ trợ',
            'route' => 'tasks.index',
            'intro' => 'Cần Admin hoặc Học vụ làm giúp một việc (ví dụ xếp học thử, sửa thông tin khách), bạn đề xuất đầu việc. Gặp lỗi phần mềm hoặc cần hỗ trợ kỹ thuật thì gửi Ticket.',
            'images' => [
                ['src' => 'tu-van/de-xuat-viec.webp', 'caption' => 'Đề xuất việc cho Admin / Học vụ'],
                ['src' => 'tu-van/ticket.webp', 'caption' => 'Tạo yêu cầu hỗ trợ (Ticket)'],
            ],
            'steps' => [
                'Bấm **Tạo đầu việc** ở menu trái (hoặc **Đề xuất việc cho Admin / Học vụ** trong Danh sách đầu việc).',
                'Nhập **Tiêu đề công việc**, **Mô tả chi tiết**, chọn **Người nhận** và **Hạn hoàn thành**, bấm **Lưu và Giao việc**.',
                'Theo dõi tiến độ ở **Danh sách đầu việc**, tab **Tôi giao**.',
                'Gặp lỗi: vào **Ticket hỗ trợ**, bấm **Tạo Ticket Mới**, nhập tiêu đề, chọn danh mục và mức độ ưu tiên, mô tả lỗi, dán ảnh chụp màn hình (Ctrl + V) rồi bấm **Tạo & Gửi Ticket**.',
            ],
        ],
    ],
];
