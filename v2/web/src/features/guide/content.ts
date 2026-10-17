// Nội dung Hướng dẫn sử dụng, sinh từ resources/guides/*.php của bản Laravel (không sửa tay; sửa nội dung thì
// sửa file PHP rồi sinh lại). route = tên route Laravel, đổi sang đường dẫn v2 ở logic.ts (ROUTE_PATHS).
import type { GuideContent } from './logic'

export const GUIDES: GuideContent[] = [
  {
    "id": "admin",
    "label": "Admin",
    "icon": "admin_panel_settings",
    "summary": "Admin là người duy nhất duyệt phiếu thu, hoàn tiền, hủy hóa đơn và đơn chấm công, chốt bảng lương mỗi kỳ, quản lý tài khoản, vai trò, chi nhánh và toàn bộ cài đặt hệ thống.",
    "sections": [
      {
        "id": "can-duyet",
        "title": "Việc cần duyệt: một chỗ cho mọi yêu cầu",
        "icon": "fact_check",
        "menu": "Phê duyệt → Cần duyệt → Việc cần duyệt",
        "route": "approvals.index",
        "intro": "Mọi yêu cầu đang chờ Admin duyệt được gom về một trang, chia theo nhóm Học phí, Đào tạo, Công việc và Nhân sự. Số đỏ cạnh mục Cần duyệt ở menu là tổng số việc đang chờ.",
        "images": [
          {
            "src": "admin/can-duyet.webp",
            "caption": "Lọc theo nhóm, tick chọn nhiều mục rồi Duyệt hàng loạt",
            "mobile": false
          },
          {
            "src": "admin/can-duyet-chi-tiet.webp",
            "caption": "Bấm vào một dòng để xem chi tiết và duyệt",
            "mobile": false
          }
        ],
        "steps": [
          "Bấm **Cần duyệt** ở menu trái. Trang mở ở tab **Việc cần duyệt**.",
          "Bấm chip **Học phí**, **Đào tạo**, **Công việc** hoặc **Nhân sự** để chỉ xem một nhóm.",
          "Bấm vào một dòng để mở chi tiết, kiểm tra rồi bấm **Duyệt** hoặc **Từ chối** (từ chối phải nhập lý do). Cần xem đầy đủ hồ sơ thì bấm **Mở màn gốc**.",
          "Muốn duyệt nhiều mục cùng lúc: tick ô vuông đầu dòng (hoặc ô ở tiêu đề nhóm để chọn cả nhóm), rồi bấm **Duyệt hàng loạt** trên thanh hiện ở đáy trang.",
          "Mỗi nhóm chỉ hiện vài mục mới nhất. Bấm **Xem tất cả** để sang màn gốc của nhóm đó."
        ],
        "tips": [
          "Nhóm ghi \"duyệt tại màn gốc\" (ví dụ Xác nhận chính thức) không duyệt hàng loạt được, bạn phải mở màn gốc để xử lý.",
          "Khi duyệt hàng loạt, mục nào không hợp lệ sẽ được báo lại riêng, các mục khác vẫn được duyệt bình thường."
        ]
      },
      {
        "id": "duyet-phieu-thu",
        "title": "Duyệt phiếu thu học phí",
        "icon": "receipt_long",
        "menu": "Phê duyệt → Cần duyệt → Phiếu thu",
        "route": "tuition.receipts.approve",
        "intro": "Phiếu thu do Học vụ, Tư vấn hoặc Quản lý cơ sở lập chỉ trở thành doanh thu và gạch công nợ khi Admin duyệt. Bạn đối chiếu số tiền, chứng từ chuyển khoản hoặc biên lai tiền mặt trước khi duyệt.",
        "images": [
          {
            "src": "admin/duyet-phieu-thu.webp",
            "caption": "Hàng đợi phiếu thu chờ duyệt",
            "mobile": false
          },
          {
            "src": "admin/duyet-phieu-thu-chi-tiet.webp",
            "caption": "Chi tiết phiếu thu: duyệt hoặc từ chối",
            "mobile": false
          }
        ],
        "steps": [
          "Vào **Cần duyệt → Phiếu thu**. Tab **Chờ duyệt** liệt kê các phiếu đang chờ, lọc thêm theo cơ sở hoặc hình thức thanh toán nếu cần.",
          "Bấm **Xem** ở dòng phiếu cần xử lý để mở chi tiết.",
          "Kiểm tra học viên, bảng kê khoản thu và mục **Minh chứng chuyển khoản (UNC) / Biên lai** (bấm kính lúp để phóng to ảnh).",
          "Đúng thì bấm **Duyệt phiếu thu**, rồi **Xác nhận phê duyệt**. Hệ thống gạch công nợ và cấp số hóa đơn.",
          "Sai thì bấm **Từ chối phiếu thu**, ghi rõ lý do rồi **Xác nhận từ chối**. Người lập sẽ sửa và gửi duyệt lại."
        ],
        "tips": [
          "Phiếu tiền mặt có nhãn **Chưa nộp về TK** cho tới khi tiền được nộp về tài khoản công ty. Bấm **Xác nhận đã nộp về TK công ty** khi đã nhận tiền.",
          "Trang tự làm mới sau 90 giây, không cần tải lại."
        ]
      },
      {
        "id": "hoan-tien-huy-hoa-don",
        "title": "Hoàn tiền, khất nợ và hủy hóa đơn",
        "icon": "currency_exchange",
        "menu": "Phê duyệt → Cần duyệt → Hoàn tiền & Khất nợ / Hủy hóa đơn",
        "route": "tuition.refunds",
        "intro": "Các hồ sơ hoàn phí, chuyển nhượng, bảo lưu, khất nợ và yêu cầu hủy hóa đơn đều cần Admin duyệt. Hoàn tiền bắt buộc có ảnh bằng chứng chi tiền.",
        "images": [
          {
            "src": "admin/hoan-tien.webp",
            "caption": "Yêu cầu hoàn tiền, khất nợ chờ phê duyệt",
            "mobile": false
          },
          {
            "src": "admin/huy-hoa-don.webp",
            "caption": "Danh sách yêu cầu hủy hóa đơn",
            "mobile": false
          }
        ],
        "steps": [
          "Vào **Cần duyệt → Hoàn tiền & Khất nợ**. Khối **Yêu cầu chờ phê duyệt** ở đầu trang là các hồ sơ đang chờ bạn.",
          "Bấm nút dấu tích để duyệt. Với hoàn phí, tải lên **Ảnh bằng chứng chi tiền** (ủy nhiệm chi hoặc biên nhận đã ký), chọn thu hồi hoa hồng nếu có, rồi bấm **Duyệt**.",
          "Bấm nút dấu X để từ chối và nhập lý do.",
          "Với hóa đơn: vào tab **Hủy hóa đơn**, bấm **Xem** ở yêu cầu cần xử lý, rồi chọn **Duyệt hủy hóa đơn** (xác nhận lại bằng **Xác nhận duyệt hủy ngay**) hoặc **Từ chối hủy**."
        ],
        "tips": [
          "Hồ sơ hoàn phí nên xử lý trong 1 tuần và trong cùng tháng. Hồ sơ quá hạn có cờ \"Quá hạn xử lý\" nhưng vẫn duyệt được.",
          "Duyệt hủy một phiếu đã duyệt sẽ tự trừ lại doanh thu và trả lại công nợ cho học viên. Số hóa đơn đã hủy không được dùng lại."
        ]
      },
      {
        "id": "don-cham-cong",
        "title": "Duyệt đơn chấm công và xin nghỉ",
        "icon": "event_available",
        "menu": "Phê duyệt → Cần duyệt → Việc cần duyệt → chip Nhân sự",
        "route": "approvals.index",
        "intro": "Đơn bổ sung công, xin đi muộn, về sớm và xin nghỉ mà nhân sự gửi từ điện thoại đều về đây cho Admin duyệt.",
        "images": [
          {
            "src": "admin/don-cham-cong.webp",
            "caption": "Nhóm Nhân sự: Đơn chấm công & nghỉ",
            "mobile": false
          }
        ],
        "steps": [
          "Vào **Cần duyệt**, bấm chip **Nhân sự**.",
          "Đọc tên người gửi, loại đơn, ngày và lý do ngay trên dòng.",
          "Bấm vào dòng để mở chi tiết, rồi bấm **Duyệt** hoặc **Từ chối** (kèm lý do).",
          "Nhiều đơn hợp lệ: tick chọn rồi bấm **Duyệt hàng loạt**."
        ],
        "tips": [
          "Đơn rơi vào kỳ lương đã chốt sẽ không duyệt được nữa. Hãy xử lý đơn trước khi chốt bảng lương."
        ]
      },
      {
        "id": "ky-luong",
        "title": "Chốt và trả bảng lương theo kỳ",
        "icon": "payments",
        "menu": "Người dùng → Lương & Phạt → Bảng lương theo kỳ",
        "route": "payroll.periods.index",
        "intro": "Mỗi tháng có một kỳ lương. Admin tạo kỳ, tính lại khi số liệu thay đổi, chốt bảng lương khi đủ điều kiện và đánh dấu đã trả sau khi chi lương.",
        "images": [
          {
            "src": "admin/ky-luong.webp",
            "caption": "Danh sách kỳ lương và trạng thái",
            "mobile": false
          },
          {
            "src": "admin/ky-luong-chi-tiet.webp",
            "caption": "Chi tiết kỳ: tính lại, chốt, đánh dấu đã trả",
            "mobile": false
          }
        ],
        "steps": [
          "Vào **Lương & Phạt → Bảng lương theo kỳ**. Kỳ mới chưa có thì bấm **Tạo kỳ lương mới**, chọn tháng, năm rồi bấm **Khởi tạo & Tính toán**.",
          "Bấm **Chi tiết bảng lương** ở kỳ cần xử lý. Xem lịch chốt ở đầu trang: chốt KPI, chốt công, chốt lỗi và ngày trả lương.",
          "Khi chấm công, KPI hoặc biên bản vi phạm thay đổi, bấm **Đồng bộ & Tính lại**.",
          "Nếu có thông báo đỏ \"Còn N nhân sự chưa chốt KPI\", bấm **Xem danh sách** để xử lý cho xong, rồi tính lại.",
          "Đủ điều kiện thì bấm **Chốt bảng lương**. Sau khi chi lương, bấm **Đánh dấu đã trả**. Bấm **Xuất Excel** để lưu bảng lương."
        ],
        "tips": [
          "Nút **Chốt bảng lương** bị khóa khi còn nhân sự chưa chốt KPI. Bảng lương chỉ chốt được từ ngày ghi trong lịch chốt (sau hạn chốt công và chốt lỗi).",
          "Kỳ đã chốt sẽ khóa chấm công và đơn xin nghỉ của tháng đó.",
          "Bấm tên nhân sự trong bảng để mở phiếu lương chi tiết của người đó."
        ]
      },
      {
        "id": "nguoi-dung",
        "title": "Tạo và quản lý tài khoản người dùng",
        "icon": "manage_accounts",
        "menu": "Người dùng → Người dùng & Tài khoản",
        "route": "users.index",
        "intro": "Tạo tài khoản cho nhân sự mới, gán vai trò và cơ sở, nhập hợp đồng và lương, khóa tài khoản khi nghỉ việc và đặt lại mật khẩu khi có người quên.",
        "images": [
          {
            "src": "admin/nguoi-dung.webp",
            "caption": "Danh sách người dùng và các thao tác",
            "mobile": false
          },
          {
            "src": "admin/nguoi-dung-them.webp",
            "caption": "Thêm người dùng mới",
            "mobile": false
          }
        ],
        "steps": [
          "Bấm **Thêm người dùng mới**. Ở tab **Tài khoản**, nhập họ tên, email, cơ sở, **Vai trò & Chức vụ**, tick vai trò kiêm nhiệm nếu có, và mật khẩu khởi tạo.",
          "Sang tab **Hồ sơ** và **Hợp đồng & Lương** để nhập thông tin cá nhân, loại hợp đồng, lương cơ bản hoặc thù lao giờ dạy, rồi bấm **Lưu tài khoản**.",
          "Tìm người dùng bằng ô tìm kiếm hoặc lọc theo cơ sở, vai trò, trạng thái.",
          "Ở cột **Hành động**: biểu tượng khiên mở **Phân quyền cá nhân**, biểu tượng cấm để **Vô hiệu hóa** tài khoản, con mắt để xem hồ sơ nhanh.",
          "Bấm nút ba chấm để **Sửa thông tin**, đổi **Vai trò & kiêm nhiệm**, **Đặt lại mật khẩu** hoặc **Xóa tài khoản**."
        ],
        "tips": [
          "Tài khoản mới phải đổi mật khẩu ở lần đăng nhập đầu tiên.",
          "Nhân sự nghỉ việc nên vô hiệu hóa thay vì xóa, để giữ lại lịch sử thao tác và bảng lương.",
          "Dòng cảnh báo vàng ở đầu trang báo hợp đồng sắp hoặc đã hết hạn. Bấm **lọc danh sách** để xem đó là ai."
        ]
      },
      {
        "id": "vai-tro-quyen",
        "title": "Vai trò và phân quyền",
        "icon": "shield_person",
        "menu": "Cài đặt → Hệ thống → Vai trò / Quyền",
        "route": "roles.index",
        "intro": "Mỗi vai trò là một tập quyền. Đổi quyền của vai trò thì menu và nút của mọi người thuộc vai trò đó đổi theo ngay.",
        "images": [
          {
            "src": "admin/vai-tro.webp",
            "caption": "Danh sách vai trò",
            "mobile": false
          },
          {
            "src": "admin/vai-tro-cau-hinh.webp",
            "caption": "Ma trận quyền của một vai trò",
            "mobile": false
          }
        ],
        "steps": [
          "Bấm **Cài đặt** ở cuối menu trái, chọn **Vai trò**.",
          "Bấm **Cấu hình quyền** ở vai trò cần sửa.",
          "Trong **Ma trận quyền theo module**, tick hoặc bỏ tick các quyền Xem, Thêm, Sửa, Xóa và thao tác khác. Di chuột lên quyền để đọc mô tả.",
          "Bấm **Lưu vai trò**. Thay đổi có hiệu lực ngay và được ghi vào Nhật ký vận hành.",
          "Cần vai trò mới: bấm **Thêm vai trò mới**, hoặc **Nhân bản** một vai trò có sẵn rồi chỉnh lại."
        ],
        "tips": [
          "Vai trò Admin luôn có toàn quyền và không sửa được.",
          "Chỉ cần mở hoặc chặn quyền cho riêng một người: dùng **Phân quyền cá nhân** ở trang Người dùng & Tài khoản.",
          "Mục **Quyền** (hoặc nút **Danh mục quyền**) liệt kê mọi quyền và số vai trò đang dùng."
        ]
      },
      {
        "id": "chi-nhanh",
        "title": "Cơ sở, chi nhánh và vùng chấm công",
        "icon": "apartment",
        "menu": "Cài đặt → Tổ chức & Đào tạo → Cơ sở & Chi nhánh",
        "route": "branches.index",
        "intro": "Quản lý danh sách chi nhánh và cài tọa độ, bán kính, giờ làm để nhân sự chấm công bằng GPS trên điện thoại.",
        "images": [
          {
            "src": "admin/chi-nhanh.webp",
            "caption": "Danh sách cơ sở và chi nhánh",
            "mobile": false
          },
          {
            "src": "admin/chi-nhanh-cham-cong.webp",
            "caption": "Cài đặt chấm công của một chi nhánh",
            "mobile": false
          }
        ],
        "steps": [
          "Vào **Cài đặt → Cơ sở & Chi nhánh**. Bấm **Thêm Chi Nhánh Mới**, nhập mã, tên, địa chỉ, hotline rồi bấm **Tạo Chi Nhánh**.",
          "Ở cột **Thao tác**, bấm biểu tượng ghim bản đồ để mở **Cài đặt chấm công**.",
          "Nhập vĩ độ, kinh độ (dán từ Google Maps) hoặc đứng tại cơ sở và bấm **Lấy vị trí hiện tại**.",
          "Đặt **Bán kính được chấm công**, **Giờ vào**, **Giờ ra**, **Cho phép muộn**, rồi bấm **Lưu cài đặt**."
        ],
        "tips": [
          "Chi nhánh có nhãn \"Chưa cài toạ độ\" thì nhân sự ở đó chưa chấm công GPS được.",
          "Bán kính nên để 50 đến 150 m vì GPS điện thoại trong nhà thường lệch 10 đến 50 m."
        ]
      },
      {
        "id": "cai-dat",
        "title": "Cài đặt hệ thống",
        "icon": "settings",
        "menu": "Cài đặt (cuối menu trái)",
        "route": "settings.index",
        "intro": "Trang Cài đặt gom mọi danh mục và tham số: khóa học, phòng, ngày nghỉ lễ, tham số lương, đơn giá giáo viên, nhắc nợ, dải số hóa đơn, tài khoản ngân hàng, SLA và máy chủ.",
        "images": [
          {
            "src": "admin/cai-dat.webp",
            "caption": "Menu Cài đặt bên trái, ví dụ trang Cấu hình SLA",
            "mobile": false
          }
        ],
        "steps": [
          "Bấm **Cài đặt** ở cuối menu trái. Menu con hiện thành 4 nhóm: **Tổ chức & Đào tạo**, **Lương**, **Học phí**, **Hệ thống**.",
          "Đầu kỳ học: kiểm tra **Khóa học & Bảng giá**, **Phòng học**, **Ngày nghỉ lễ** và **Khung giờ ca dạy**.",
          "Về lương: chỉnh **Tham số lương** (BHXH, công đoàn, quỹ KPI), **Đơn giá giáo viên** và **Mốc hoa hồng / Thưởng**, rồi bấm lưu ở từng trang.",
          "Về thu tiền: cấu hình **Tài khoản ngân hàng** (tài khoản mặc định hiện mã QR trên phiếu thu), **Dải số hóa đơn** và **Nhắc nợ học phí**.",
          "Trong **Cấu hình SLA**, chọn nhóm rồi bấm vào từng mục để sửa thời hạn, việc tự giao và mức tự lập biên bản."
        ],
        "tips": [
          "Đổi tham số lương chỉ áp dụng khi tính hoặc tính lại các kỳ về sau. Kỳ đã chốt hoặc đã trả không bị ảnh hưởng.",
          "Đổi đơn giá giáo viên tạo một phiên bản mới có ngày hiệu lực. Buổi dạy cũ vẫn tính theo giá cũ."
        ]
      },
      {
        "id": "doanh-thu",
        "title": "Báo cáo doanh thu tạm tính",
        "icon": "query_stats",
        "menu": "Báo cáo → Doanh thu → Doanh thu tạm tính",
        "route": "finance.reports.revenue",
        "intro": "Xem nhanh tổng thu, chi phí vận hành và doanh thu tạm tính của tháng, toàn hệ thống hoặc từng chi nhánh.",
        "images": [
          {
            "src": "admin/doanh-thu.webp",
            "caption": "Báo cáo doanh thu tạm tính",
            "mobile": false
          }
        ],
        "steps": [
          "Vào **Doanh thu** ở nhóm Báo cáo trên menu trái.",
          "Chọn **Kỳ tháng** và chi nhánh (hoặc **Tất cả chi nhánh**), bấm **Lọc**.",
          "Ba thẻ đầu trang cho biết **Tổng thu thực tế**, **Chi phí vận hành** và **Doanh thu tạm tính** (thu trừ chi), kèm so sánh với tháng trước.",
          "Kéo xuống xem **Cơ cấu Nguồn Thu** và **Cơ cấu Chi Vận Hành**. Bấm **Xuất báo cáo** để tải file."
        ],
        "tips": [
          "Chỉ phiếu thu đã duyệt mới được tính. Phiếu nháp, chờ duyệt, bị từ chối hoặc đã hủy hóa đơn không được cộng.",
          "Chi lương chỉ được cộng vào chi phí khi kỳ lương đã chốt."
        ]
      },
      {
        "id": "xep-hang-kpi",
        "title": "Bảng xếp hạng KPI và thưởng",
        "icon": "emoji_events",
        "menu": "Báo cáo → Nhân sự & KPI → Xếp hạng KPI & Thưởng",
        "route": "payroll.kpi-leaderboard",
        "intro": "Bảng thi đua công khai về KPI giữ học sinh và hoa hồng theo từng kỳ lương. Bảng không hiện lương cơ bản hay khoản khấu trừ.",
        "images": [
          {
            "src": "admin/xep-hang-kpi.webp",
            "caption": "Bảng xếp hạng KPI & Hoa hồng",
            "mobile": false
          }
        ],
        "steps": [
          "Vào **Nhân sự & KPI**, chọn tab **Xếp hạng KPI & Thưởng**.",
          "Chọn **Kỳ lương** và **Chi nhánh**, bấm **Xem**.",
          "Xem thứ hạng, số học sinh giữ được, đơn giá và tổng KPI của từng người.",
          "Muốn đổi các mốc thưởng, bấm **Cấu hình mốc hoa hồng**."
        ],
        "tips": [
          "Kỳ chưa chốt có nhãn \"Số liệu tạm tính\". Số chỉ cố định sau khi bảng lương được chốt.",
          "Tab **Phiếu KPI tháng** là nơi duyệt phiếu KPI từng người trước khi chốt lương."
        ]
      },
      {
        "id": "nhat-ky",
        "title": "Nhật ký vận hành",
        "icon": "history",
        "menu": "Cài đặt → Hệ thống → Nhật ký vận hành",
        "route": "activity-logs.index",
        "intro": "Mọi thao tác thêm, sửa, xóa và đăng nhập đều được ghi lại kèm dữ liệu trước và sau. Dùng khi cần tra ai đã làm gì, lúc nào.",
        "images": [
          {
            "src": "admin/nhat-ky.webp",
            "caption": "Lọc nhật ký theo phân hệ",
            "mobile": false
          },
          {
            "src": "admin/nhat-ky-chi-tiet.webp",
            "caption": "Chi tiết đối chiếu dữ liệu trước và sau",
            "mobile": false
          }
        ],
        "steps": [
          "Vào **Cài đặt → Nhật ký vận hành**.",
          "Bấm chip phân hệ (ví dụ **Học phí**, **Phân quyền**, **Lương & Chấm công**) hoặc lọc theo người thực hiện, loại thao tác, khoảng thời gian.",
          "Bấm biểu tượng con mắt ở cuối dòng để mở **Chi tiết đối chiếu**. Phần **So sánh trước / sau** tô đỏ giá trị cũ, tô xanh giá trị mới.",
          "Với thao tác cho phép khôi phục, nút **Hoàn tác** hiện ở đáy khung chi tiết. Bấm và xác nhận để trả lại giá trị cũ.",
          "Bấm **Xuất Excel** để lưu nhật ký theo bộ lọc đang chọn."
        ],
        "tips": []
      }
    ]
  },
  {
    "id": "quan-ly",
    "label": "Quản lý cơ sở",
    "icon": "apartment",
    "summary": "Quản lý cơ sở điều hành một chi nhánh: theo dõi học phí và công nợ, lập phiếu thu, ghi khoản chi, quản lý kho, đối soát chấm công, chốt vi phạm, duyệt phiếu KPI và xem doanh thu của chi nhánh. Các màn tuyển sinh, học viên, lớp học dùng giống hướng dẫn Học vụ.",
    "sections": [
      {
        "id": "tong-quan",
        "title": "Tổng quan chi nhánh mỗi sáng",
        "icon": "dashboard",
        "menu": "Tổng quan",
        "route": "dashboard",
        "intro": "Trang đầu tiên sau khi đăng nhập cho biết tình hình chi nhánh: doanh thu tháng, học viên, lead mới, lớp đang chạy, việc quá hạn và hàng chờ cần xử lý.",
        "images": [
          {
            "src": "quan-ly/tong-quan.webp",
            "caption": "Tổng quan chi nhánh và hàng chờ cần xử lý",
            "mobile": false
          }
        ],
        "steps": [
          "Bấm **Tổng quan** ở đầu menu trái. Dòng \"Phạm vi\" cho biết bạn đang xem chi nhánh nào.",
          "Xem khối **Hàng chờ cần xử lý**: việc chờ xác nhận, phiếu thu chờ duyệt, ticket đang mở, lịch hẹn test hôm nay. Bấm vào một ô để mở danh sách tương ứng.",
          "Khối **Việc quá hạn cần xử lý** liệt kê việc đã trễ hạn và người phụ trách. Bấm **Xem danh sách** để xem hết.",
          "Kéo xuống phần **Chất lượng giảng dạy** để so sánh chuyên cần, bài về nhà, điểm trung bình, ngày công và vi phạm của từng giáo viên trong tháng."
        ],
        "tips": []
      },
      {
        "id": "cong-no",
        "title": "Công nợ học viên và lập phiếu thu",
        "icon": "account_balance_wallet",
        "menu": "Tài chính → Học phí → Công nợ học viên",
        "route": "tuition.students",
        "intro": "Sổ toàn bộ khoản học phí của chi nhánh: tổng phải thu, đã thu, còn nợ và hạn nộp của từng học viên. Từ đây bạn lập phiếu thu khi phụ huynh đóng tiền.",
        "images": [
          {
            "src": "quan-ly/cong-no.webp",
            "caption": "Sổ công nợ học viên",
            "mobile": false
          },
          {
            "src": "quan-ly/lap-phieu-thu.webp",
            "caption": "Lập phiếu thu học phí",
            "mobile": false
          }
        ],
        "steps": [
          "Vào **Học phí → Công nợ học viên**. Lọc theo lớp hoặc trạng thái (**Chưa nộp**, **Đang nợ (Đã cọc)**, **Quá hạn**) để tìm nhanh.",
          "Bấm biểu tượng tiền ở cột **Thao tác** của khoản cần thu, hoặc bấm **Lập phiếu thu** ở góc phải để tự chọn học viên.",
          "Kiểm tra khoản học phí, số buổi thu đợt này, ưu đãi, phụ thu (nếu có) và chọn hình thức thanh toán.",
          "Đính kèm ảnh chuyển khoản hoặc nhập số hóa đơn giấy với tiền mặt.",
          "Bấm **Lưu phiếu thu & Gửi duyệt**. Chưa đủ thông tin thì bấm **Lưu nháp** để làm tiếp sau."
        ],
        "tips": [
          "Phiếu thu chỉ gạch công nợ và tính doanh thu sau khi Admin duyệt. Phiếu bị từ chối sẽ hiện lý do, bạn sửa rồi bấm **Lưu & Gửi duyệt lại**.",
          "Tiền mặt phải nộp về tài khoản công ty trong ngày. Phiếu sẽ có nhãn \"Chưa nộp về TK\" cho tới khi Admin xác nhận.",
          "Có nhiều khoản cũ cần nhập một lần: dùng nút **Nhập Excel**."
        ]
      },
      {
        "id": "nhac-phi",
        "title": "Đôn đốc học phí quá hạn",
        "icon": "notifications_active",
        "menu": "Tài chính → Học phí → Quá hạn & Nhắc phí",
        "route": "tuition.overdue",
        "intro": "Danh sách khoản đã quá hạn và sắp đến hạn trong 14 ngày tới, để bạn gọi phụ huynh, gửi nhắc và báo cáo Admin các ca khó thu.",
        "images": [
          {
            "src": "quan-ly/nhac-phi.webp",
            "caption": "Nhóm quá hạn: lập phiếu thu, gửi nhắc, thao tác khác",
            "mobile": false
          }
        ],
        "steps": [
          "Vào tab **Quá hạn & Nhắc phí**. Nhóm **Quá hạn nghiêm trọng (≥ 7 ngày)** là các ca bắt buộc liên hệ trực tiếp.",
          "Phụ huynh đã đóng tiền: bấm **Lập phiếu thu** ngay trên thẻ học viên.",
          "Bấm **Gửi nhắc nợ** để gửi nhắc hạn học phí.",
          "Bấm nút ba chấm, chọn **Xác nhận đã liên hệ**, ghi nội dung trao đổi rồi bấm **Lưu liên hệ**.",
          "Ca khó thu: bấm nút ba chấm, chọn **Báo cáo Admin**, ghi tình hình và đề xuất rồi bấm **Gửi báo cáo**."
        ],
        "tips": [
          "Kéo xuống nhóm **Sắp đến hạn** để nhắc trước, tránh để khoản rơi vào quá hạn."
        ]
      },
      {
        "id": "thu-chi",
        "title": "Ghi khoản chi vận hành",
        "icon": "receipt",
        "menu": "Tài chính → Thu - Chi → Khoản chi vận hành",
        "route": "finance.expenses.index",
        "intro": "Sổ chi của chi nhánh: tiền thuê mặt bằng, điện nước, internet, văn phòng phẩm... Khoản chi được trừ vào doanh thu tạm tính của tháng.",
        "images": [
          {
            "src": "quan-ly/thu-chi.webp",
            "caption": "Sổ khoản chi vận hành",
            "mobile": false
          },
          {
            "src": "quan-ly/thu-chi-them.webp",
            "caption": "Thêm khoản chi mới",
            "mobile": false
          }
        ],
        "steps": [
          "Vào **Thu - Chi**. Chọn **Kỳ tháng** và chi nhánh để xem các khoản đã ghi.",
          "Bấm **Thêm khoản chi mới**.",
          "Nhập **Ngày chi**, **Nội dung khoản chi**, **Số tiền**, **Hình thức chi**, **Chi nhánh áp dụng**. Phân loại có thể để trống, hệ thống tự nhận diện theo nội dung.",
          "Ghi mã hóa đơn hoặc nhà cung cấp vào **Ghi chú & Thông tin chứng từ**, rồi bấm **Lưu khoản chi**.",
          "Ghi sai thì bấm biểu tượng bút để sửa hoặc thùng rác để xóa. Bấm **Xuất Excel** để gửi kế toán."
        ],
        "tips": [
          "Khoản chi được lưu ngay vào sổ, không cần duyệt. Hãy kiểm tra kỹ số tiền trước khi lưu.",
          "Dòng chi lương do hệ thống tự thêm khi kỳ lương đã chốt, không sửa ở đây."
        ]
      },
      {
        "id": "kho",
        "title": "Kho vật phẩm: nhập kho và kiểm kê",
        "icon": "warehouse",
        "menu": "Tài chính → Kho vật phẩm → Tồn kho theo chi nhánh",
        "route": "merchandise.stock.index",
        "intro": "Theo dõi số sách, đồng phục, quà tặng còn ở chi nhánh. Kho tự trừ khi phiếu thu kèm sách được duyệt và tự hoàn khi hóa đơn bị hủy.",
        "images": [
          {
            "src": "quan-ly/kho.webp",
            "caption": "Tồn kho theo chi nhánh",
            "mobile": false
          },
          {
            "src": "quan-ly/kho-nhap.webp",
            "caption": "Nhập kho hoặc kiểm kê một mặt hàng",
            "mobile": false
          }
        ],
        "steps": [
          "Vào **Kho vật phẩm**. Bấm chip **Âm kho**, **Hết hàng** hoặc **Sắp hết** để xem mặt hàng cần bổ sung.",
          "Khi nhận hàng: bấm **Nhập kho**, chọn **Mặt hàng**, nhập **Số lượng nhập thêm** và ghi chú phiếu nhập, rồi bấm **Nhập kho**.",
          "Khi đếm lại kho: bấm **Kiểm kê**, chọn mặt hàng và nhập **Số tồn thực tế đếm được**, rồi bấm **Lưu kiểm kê**.",
          "Bấm vào một dòng mặt hàng để xem lịch sử xuất nhập. Khối **Xuất nhập gần đây** ở cuối trang ghi các lần thay đổi mới nhất."
        ],
        "tips": [
          "Mặt hàng mới (sách, đồng phục...) do Admin tạo trong danh mục hàng hóa. Bạn chỉ nhập kho và kiểm kê cho chi nhánh của mình.",
          "\"Âm kho\" nghĩa là đã giao cho học viên nhiều hơn số đã nhập: cần nhập bổ sung hoặc kiểm kê lại."
        ]
      },
      {
        "id": "cham-cong",
        "title": "Theo dõi chấm công nhân sự",
        "icon": "fingerprint",
        "menu": "Người dùng → Chấm công → Chấm công hằng ngày",
        "route": "staff-attendance.index",
        "intro": "Xem ai đã chấm vào, ra, ai đi muộn hoặc chưa chấm công tại chi nhánh, kèm ảnh và khoảng cách GPS của từng lần chấm.",
        "images": [
          {
            "src": "quan-ly/cham-cong.webp",
            "caption": "Chấm công theo ngày",
            "mobile": false
          },
          {
            "src": "quan-ly/cham-cong-chi-tiet.webp",
            "caption": "Ảnh và vị trí của một lần chấm công",
            "mobile": false
          }
        ],
        "steps": [
          "Vào **Chấm công → Chấm công hằng ngày**. Tab **Theo ngày** hiện danh sách nhân sự của ngày đang chọn.",
          "Đổi ngày hoặc lọc **Tình trạng** (**Đi muộn**, **Chưa chấm công**, **Nghỉ có phép**) rồi bấm **Lọc**.",
          "Bấm vào một dòng đã chấm để xem ảnh lúc vào, lúc ra, khoảng cách tới cơ sở và vị trí trên bản đồ.",
          "Bấm **Tổng hợp tháng** để xem số công của từng người trong cả tháng."
        ],
        "tips": [
          "Đi muộn quá số phút cho phép sẽ tự lập biên bản vi phạm, bạn chốt ở mục Vi phạm & Phạt.",
          "Đơn bổ sung công, xin nghỉ, đi muộn, về sớm của nhân sự do Admin duyệt."
        ]
      },
      {
        "id": "gio-day",
        "title": "Đối soát giờ dạy và chốt bảng công giáo viên",
        "icon": "co_present",
        "menu": "Người dùng → Chấm công → Giờ dạy giáo viên",
        "route": "payroll.timesheets.teachers",
        "intro": "Trước khi tính lương, bạn rà từng ca dạy của giáo viên, trợ giảng: duyệt ca hợp lệ, từ chối ca sai, chấm tay khi có sự cố rồi chốt bảng công.",
        "images": [
          {
            "src": "quan-ly/gio-day.webp",
            "caption": "Ca dạy trong kỳ: duyệt, từ chối, chốt bảng công",
            "mobile": false
          }
        ],
        "steps": [
          "Vào **Chấm công → Giờ dạy giáo viên**. Lọc theo kỳ lương, lớp, giáo viên hoặc trạng thái **Chờ đối soát**.",
          "Khối **Chấm công theo lịch** liệt kê buổi học của ngày đang chọn. Bấm **Xác nhận** để tính công theo giờ lịch cho giáo viên dự kiến.",
          "Ở bảng **Ca dạy trong kỳ**, bấm **Duyệt** với ca đúng. Ca sai thì bấm **Từ chối**, nhập lý do rồi bấm **Từ chối ca dạy**.",
          "Giáo viên quên check-in hoặc chi nhánh mất mạng: bấm **Chấm công thủ công**, chọn nhân viên, lớp, ngày, giờ vào, giờ ra, ghi lý do rồi bấm **Lưu chấm công**.",
          "Tick các ca đã kiểm tra rồi bấm **Chốt bảng công**."
        ],
        "tips": [
          "Bấm biểu tượng bút ở cuối dòng để chỉnh tay giờ vào, giờ ra của một ca trước khi duyệt.",
          "Ca thuộc kỳ lương đã chốt hiện \"Đã khóa\" và không sửa được nữa."
        ]
      },
      {
        "id": "vi-pham",
        "title": "Chốt vi phạm và mức phạt",
        "icon": "gavel",
        "menu": "Người dùng → Lương & Phạt → Vi phạm & Phạt",
        "route": "penalties.index",
        "intro": "Quy trình xử lý vi phạm: ghi nhận, nhân sự giải trình, Quản lý chốt lỗi và mức phạt, nhân sự nộp phạt trong 2 ngày (quá hạn trừ lương), rồi ghi nhận khắc phục. Quản lý chốt các lỗi vận hành, nội quy.",
        "images": [
          {
            "src": "quan-ly/vi-pham.webp",
            "caption": "Danh sách vi phạm và nút Chốt lỗi",
            "mobile": false
          }
        ],
        "steps": [
          "Vào **Lương & Phạt → Vi phạm & Phạt**. Các thẻ đầu trang cho biết số biên bản chờ giải trình, chờ chốt, đã chốt phạt và quá hạn.",
          "Lập biên bản mới: bấm **Ghi nhận vi phạm mới**, chọn **Nhân sự vi phạm**, **Loại lỗi**, **Lỗi vi phạm**, thời điểm, tải **Bằng chứng vi phạm** rồi bấm **Ghi nhận**.",
          "Với biên bản ở bước Ghi nhận, bấm **Chốt lỗi**, xem giải trình rồi bấm **Chốt**. Tiếp theo bấm **Chốt mức phạt**, nhập **Số tiền phạt (VNĐ)** và bấm **Chốt**.",
          "Khi nhân sự nộp phạt trực tiếp, bấm **Đánh dấu đã nộp**. Sau đó bấm **Ghi nhận khắc phục** để đóng biên bản.",
          "Lỗi không đáng phạt: bấm nút ba chấm, chọn **Đóng - không phạt** hoặc **Hủy vi phạm**."
        ],
        "tips": [
          "Biên bản đi muộn được hệ thống tự lập từ chấm công. Lỗi chuyên môn, giảng dạy do Học thuật chốt.",
          "Khoản phạt quá hạn nộp sẽ tự trừ vào bảng lương cuối tháng. Hãy chốt biên bản trước hạn chốt lỗi của kỳ lương."
        ]
      },
      {
        "id": "kpi",
        "title": "Duyệt phiếu KPI tháng",
        "icon": "insights",
        "menu": "Báo cáo → Nhân sự & KPI → Phiếu KPI tháng",
        "route": "kpi.monthly",
        "intro": "Mỗi nhân sự có một phiếu KPI tự tạo đầu tháng theo tiêu chí của vai trò. Bạn điền số liệu còn thiếu và duyệt phiếu để thưởng KPI vào bảng lương.",
        "images": [
          {
            "src": "quan-ly/kpi.webp",
            "caption": "Danh sách phiếu KPI tháng",
            "mobile": false
          },
          {
            "src": "quan-ly/kpi-phieu.webp",
            "caption": "Mở phiếu: điền số liệu, Duyệt hoặc Không duyệt",
            "mobile": false
          }
        ],
        "steps": [
          "Vào **Nhân sự & KPI → Phiếu KPI tháng**. Chọn **Kỳ lương**, vai trò, cơ sở rồi bấm **Xem**.",
          "Bấm vào dòng của nhân sự để mở phiếu.",
          "Điền vào các ô **Số liệu** còn trống. Cột **Mức đạt** và **Thưởng** tự tính lại.",
          "Bấm **Duyệt** khi phiếu đã đủ số liệu. Chưa đạt yêu cầu thì bấm **Không duyệt**, nhập lý do rồi **Xác nhận không duyệt**."
        ],
        "tips": [
          "Nút **Duyệt** chỉ mở từ ngày cuối tháng. Trước đó bạn vẫn điền số liệu hoặc trả phiếu được.",
          "Phiếu chưa chốt sẽ chặn Admin chốt bảng lương, nên hãy duyệt xong ngay cuối tháng.",
          "Tab **Xếp hạng KPI & Thưởng** cho xem bảng thi đua KPI giữ học sinh của chi nhánh."
        ]
      },
      {
        "id": "doanh-thu",
        "title": "Doanh thu tạm tính của chi nhánh",
        "icon": "query_stats",
        "menu": "Báo cáo → Doanh thu → Doanh thu tạm tính",
        "route": "finance.reports.revenue",
        "intro": "Tổng thu từ phiếu thu đã duyệt trừ chi phí vận hành, cho biết chi nhánh lãi hay lỗ tạm tính trong tháng.",
        "images": [
          {
            "src": "quan-ly/doanh-thu.webp",
            "caption": "Báo cáo doanh thu tạm tính của chi nhánh",
            "mobile": false
          }
        ],
        "steps": [
          "Vào **Doanh thu** ở nhóm Báo cáo trên menu trái.",
          "Chọn **Kỳ tháng** rồi bấm **Lọc**.",
          "Xem **Tổng thu thực tế**, **Chi phí vận hành** và **Doanh thu tạm tính**, kèm so sánh với tháng trước.",
          "Kéo xuống xem cơ cấu nguồn thu và chi. Bấm **Xuất báo cáo** để tải file."
        ],
        "tips": [
          "Phiếu thu chờ duyệt chưa được tính. Nếu số thấp bất thường, kiểm tra xem còn phiếu nào đang chờ Admin duyệt."
        ]
      },
      {
        "id": "nguoi-dung",
        "title": "Tài khoản nhân sự và học viên của chi nhánh",
        "icon": "manage_accounts",
        "menu": "Người dùng → Người dùng & Tài khoản",
        "route": "users.index",
        "intro": "Xem tài khoản của chi nhánh, khóa tài khoản người đã nghỉ, đặt lại mật khẩu khi có người quên và gán vai trò kiêm nhiệm.",
        "images": [
          {
            "src": "quan-ly/nguoi-dung.webp",
            "caption": "Vô hiệu hóa, Vai trò & kiêm nhiệm, Đặt lại mật khẩu",
            "mobile": false
          }
        ],
        "steps": [
          "Vào **Người dùng & Tài khoản**. Tìm theo tên, email, số điện thoại hoặc lọc theo vai trò, trạng thái.",
          "Bấm biểu tượng con mắt để xem hồ sơ nhanh.",
          "Bấm biểu tượng cấm để **Vô hiệu hóa** tài khoản. Bấm lại để kích hoạt lại.",
          "Bấm nút ba chấm, chọn **Đặt lại mật khẩu** khi nhân sự hoặc học viên quên mật khẩu, hoặc **Vai trò & kiêm nhiệm** để thêm vai trò."
        ],
        "tips": [
          "Tạo tài khoản mới và sửa hợp đồng, lương do Admin làm. Gửi Admin thông tin nhân sự mới để được tạo tài khoản."
        ]
      },
      {
        "id": "cai-dat-luong",
        "title": "Đơn giá giáo viên và mốc hoa hồng",
        "icon": "price_change",
        "menu": "Cài đặt → Lương → Đơn giá giáo viên / Mốc hoa hồng / Thưởng",
        "route": "payroll.config.teacher-rates",
        "intro": "Đơn giá theo buổi của từng giáo viên, trợ giảng và các mốc hoa hồng là đầu vào để tính lương cuối tháng. Bảng lương của kỳ do Admin chốt.",
        "images": [
          {
            "src": "quan-ly/don-gia.webp",
            "caption": "Cấu hình đơn giá giáo viên",
            "mobile": false
          }
        ],
        "steps": [
          "Bấm **Cài đặt** ở cuối menu trái, chọn **Đơn giá giáo viên**.",
          "Xem đơn giá đang hiệu lực của từng người. Bấm **Xem** để xem lịch sử các lần đổi giá.",
          "Bấm **Cập nhật đơn giá**, chọn **Giáo viên**, **Ngày hiệu lực từ**, **Đơn vị tính** và **Mức đơn giá mới**, ghi lý do rồi bấm **Cập nhật đơn giá mới**.",
          "Bấm **Cấu hình hoa hồng** (hoặc mục **Mốc hoa hồng / Thưởng**) để chỉnh các mốc thưởng."
        ],
        "tips": [
          "Đổi giá tạo phiên bản mới có ngày hiệu lực. Buổi dạy trước ngày đó vẫn tính theo giá cũ.",
          "Muốn lương đúng: đối soát giờ dạy, chốt vi phạm và duyệt phiếu KPI đúng hạn. Lương của chính bạn xem ở **Của tôi → Lương của tôi**."
        ]
      }
    ]
  },
  {
    "id": "hoc-thuat",
    "label": "Học thuật",
    "icon": "auto_stories",
    "summary": "Học thuật soạn và giao giáo trình theo chặng, theo dõi đề xuất của giáo viên, lo đề và kết quả Big Test, dự giờ, chốt lỗi chuyên môn, quản lý dự án soạn sách và nộp báo cáo tuần.",
    "sections": [
      {
        "id": "tong-quan-hoc-thuat",
        "title": "Tổng quan học thuật và hàng chờ",
        "icon": "dashboard",
        "menu": "Tổng quan",
        "route": "dashboard",
        "intro": "Trang mở ra ngay sau khi đăng nhập. Bạn thấy số lớp đang chạy, đề xuất giáo trình đang chờ, Big Test sắp tới và những việc cần xử lý trong ngày.",
        "images": [
          {
            "src": "hoc-thuat/tong-quan.webp",
            "caption": "Khối Hàng chờ cần xử lý trên trang Tổng quan",
            "mobile": false
          }
        ],
        "steps": [
          "Bấm **Tổng quan** ở đầu menu trái. Phần **Tổng quan học thuật** hiện 3 ô số liệu: lớp đang chạy, đề xuất giáo trình / giãn tiến độ chờ duyệt, Big Test trong 14 ngày tới.",
          "Khối **Hàng chờ cần xử lý** gom 3 loại việc: **Đề xuất sửa giáo trình**, **Order đề Big Test chờ duyệt**, **Đợt Big Test có kết quả chờ duyệt**. Bấm vào một ô để mở đúng danh sách.",
          "Khối **Big Test sắp tới** và **Lịch hẹn 7 ngày tới** cho biết lịch thi, lịch test sắp diễn ra. Bấm **Lịch Big Test** để xem đầy đủ.",
          "Khối **Việc của tôi trong tuần** liệt kê việc được giao cho bạn (ví dụ dự giờ). Bấm **Xem tất cả** để mở Danh sách đầu việc.",
          "Kéo xuống dưới để xem **Chất lượng giảng dạy**, **Báo cáo tháng của giáo viên** (ai chưa nộp) và **Order học thuật**."
        ],
        "tips": []
      },
      {
        "id": "de-xuat-giao-trinh",
        "title": "Theo dõi đề xuất sửa giáo trình và xin điều chỉnh tiến độ",
        "icon": "edit_note",
        "menu": "Tổng quan → Hàng chờ cần xử lý, hoặc Phê duyệt → Xin duyệt → Xem trạng thái đề xuất",
        "route": "syllabus.versions",
        "intro": "Giáo viên gửi đề xuất sửa nội dung giáo trình hoặc xin giãn tiến độ lớp. Bạn đọc chi tiết, theo dõi kết quả và cập nhật giáo trình khi đề xuất được duyệt.",
        "images": [
          {
            "src": "hoc-thuat/de-xuat-giao-trinh.webp",
            "caption": "Chi tiết một đề xuất sửa giáo trình",
            "mobile": false
          }
        ],
        "steps": [
          "Ở **Tổng quan**, bấm ô **Đề xuất sửa giáo trình** trong khối Hàng chờ. Hoặc vào **Phê duyệt → Xin duyệt** rồi bấm **Xem trạng thái đề xuất**.",
          "Dùng ô lọc **Tất cả trạng thái** để xem riêng đề xuất chờ duyệt, đã duyệt hoặc bị từ chối. Bấm **Xem** ở một dòng để mở chi tiết.",
          "Trong chi tiết, so sánh **Nội dung cũ** với **Nội dung mới đề xuất**, đọc **Lý do thay đổi** và tải file đính kèm nếu có. Cột phải cho biết trạng thái và lịch sử xử lý.",
          "Khi đề xuất đã được duyệt, nội dung chưa tự áp vào giáo trình. Bấm **Mở buổi để cập nhật** để sửa buổi học đó ở màn Soạn syllabus.",
          "Yêu cầu xin giãn tiến độ: ở Tổng quan, khối **Đề xuất chờ duyệt**, bấm **Duyệt đề xuất**. Màn này liệt kê giáo viên, lớp, số buổi xin thêm và lý do; bấm **Xem** để đọc chi tiết."
        ],
        "tips": [
          "Nút phê duyệt và từ chối đề xuất, yêu cầu giãn tiến độ hiện chỉ Admin bấm được. Bạn theo dõi và trao đổi với giáo viên.",
          "Bạn cũng tự gửi được đề xuất ở **Phê duyệt → Xin duyệt → Gửi đề xuất**."
        ]
      },
      {
        "id": "big-test",
        "title": "Big Test: đợt thi, đề và kết quả",
        "icon": "assignment",
        "menu": "Big Test → Phân phối đề / Bảng điểm & Kết quả",
        "route": "syllabus.big-tests.distribution",
        "intro": "Big Test là bài thi cuối mỗi chặng. Bạn theo dõi order đề của giáo viên, tạo đợt thi, gắn chặng, xem bảng điểm và gửi kết quả đã duyệt cho phụ huynh.",
        "images": [
          {
            "src": "hoc-thuat/big-test-phan-phoi.webp",
            "caption": "Big Test → Phân phối đề: order chờ duyệt và đợt thi",
            "mobile": false
          },
          {
            "src": "hoc-thuat/big-test-ket-qua.webp",
            "caption": "Big Test → Bảng điểm & Kết quả",
            "mobile": false
          }
        ],
        "steps": [
          "Vào **Big Test → Phân phối đề**. Bảng **Yêu cầu chờ duyệt** là các order đề giáo viên gửi. Bấm **Xem** để đọc lớp, chặng, ngày thi dự kiến, hạn xử lý và yêu cầu của giáo viên.",
          "Bấm **Tạo đợt Big Test mới**, nhập **Tên đợt thi**, **Lớp thi**, **Loại kỳ thi**, **Thời gian thi**, **Phòng thi**, rồi bấm **Lưu bản nháp**.",
          "Ở bảng **Đợt thi Big Test**, bấm **Gắn chặng** để nối đợt thi với chặng của lớp, chọn chặng rồi bấm **Lưu**.",
          "Mở tab **Bảng điểm & Kết quả**, chọn kỳ thi ở ô **Chọn Kỳ Thi Big Test**. Bảng hiện điểm 4 kỹ năng, nhận xét, trạng thái từng học viên và ngày đã gửi phụ huynh.",
          "Khi kết quả đã được duyệt, bấm **Gửi phụ huynh** để gửi kết quả qua Zalo cho cả lớp, hoặc bấm **Gửi PH** ở từng dòng.",
          "Bấm **In bảng điểm** để in hoặc lưu PDF bảng điểm của lớp."
        ],
        "tips": [
          "Duyệt và phân phối đề, duyệt order và duyệt kết quả hiện chỉ Admin bấm được.",
          "Tab **Nhắc lịch** liệt kê chặng sắp thi mà chưa duyệt đề. Nút **Nhắc lịch** ở mỗi đợt thi gửi thông báo lịch thi vào Cổng Phụ huynh & Học sinh của lớp.",
          "Khi kết quả được duyệt và gửi đủ cho phụ huynh, chặng hiện tại tự đóng và chặng kế tiếp tự mở."
        ]
      },
      {
        "id": "soan-syllabus",
        "title": "Soạn syllabus theo chặng",
        "icon": "edit_document",
        "menu": "Giáo trình → Soạn syllabus",
        "route": "syllabus.builder",
        "intro": "Xây khung giáo trình theo thứ tự Giáo trình → Chặng (có Big Test cuối chặng) → Unit → Buổi. Giáo viên thấy nội dung từng buổi ở màn Xem bài giảng.",
        "images": [
          {
            "src": "hoc-thuat/soan-syllabus.webp",
            "caption": "Thông tin giáo trình và cây Chặng → Unit → Buổi",
            "mobile": false
          }
        ],
        "steps": [
          "Vào **Giáo trình → Soạn syllabus**, chọn giáo trình ở ô **Giáo trình đang soạn**. Muốn làm giáo trình mới, bấm **Tạo giáo trình mới**, nhập mã, phiên bản, tên, khóa học áp dụng, tên chặng đầu tiên rồi bấm **Tạo giáo trình**.",
          "Bấm **Thêm chặng** để thêm chặng: nhập **Tên chặng học**, **Mục tiêu / đầu ra của chặng**, **Big Test cuối chặng**, rồi bấm **Thêm chặng học**.",
          "Trên mỗi chặng, bấm **Unit** để thêm unit. Trên mỗi unit, bấm **Buổi** để thêm buổi học: tiêu đề, mục tiêu, nội dung chính, bài tập về nhà, trọng tâm từ vựng và ngữ pháp, rồi bấm **Thêm buổi học vào chặng**.",
          "Ở khối **Thông tin giáo trình**, tích **Trình độ áp dụng** để lớp thuộc trình độ đó mặc định học giáo trình này, rồi bấm **Lưu thông tin giáo trình**.",
          "Dùng **Sửa**, biểu tượng thùng rác và mũi tên lên xuống để sửa, xóa hoặc đổi thứ tự chặng."
        ],
        "tips": [
          "Số buổi đánh liên tục trong cả giáo trình, không đánh lại từ 1 ở mỗi chặng.",
          "Bấm **Xem như giáo viên** ở tab Tài liệu để kiểm tra giáo viên sẽ thấy gì."
        ]
      },
      {
        "id": "giao-chang",
        "title": "Giao chặng học cho giáo viên",
        "icon": "assignment_ind",
        "menu": "Giáo trình → Giao chặng",
        "route": "syllabus.assignments",
        "intro": "Mỗi lớp học một chặng tại một thời điểm. Giao chặng là mở quyền xem giáo trình của chặng đó cho giáo viên phụ trách lớp.",
        "images": [
          {
            "src": "hoc-thuat/giao-chang.webp",
            "caption": "Hộp thoại Thiết lập chặng mới",
            "mobile": false
          }
        ],
        "steps": [
          "Vào **Giáo trình → Giao chặng**. Bảng **Lịch sử phân quyền chặng học** cho biết lớp nào đang học chặng nào, giáo viên nào, thời hạn và trạng thái.",
          "Bấm **Thiết lập chặng mới**, chọn **Chọn lớp học**. Giáo viên, giáo trình và chặng để trống thì hệ thống lấy giáo viên chính, giáo trình theo trình độ và chặng kế tiếp của lớp.",
          "Chọn **Ngày bắt đầu**, **Dự kiến hoàn thành**, rồi bấm **Xác nhận giao chặng**.",
          "Muốn đổi giáo viên hoặc ngày của chặng đang mở, bấm biểu tượng bút ở dòng đó, sửa rồi bấm **Lưu thay đổi**.",
          "Trường hợp ngoại lệ cần đóng chặng sớm, bấm **Đóng tay**, nhập lý do rồi bấm **Đóng chặng**."
        ],
        "tips": [
          "Thông thường bạn không cần đóng chặng tay: chặng tự đóng khi kết quả Big Test được duyệt và gửi phụ huynh."
        ]
      },
      {
        "id": "tai-lieu",
        "title": "Tải lên tài liệu giáo trình",
        "icon": "upload_file",
        "menu": "Giáo trình → Tài liệu",
        "route": "syllabus.documents",
        "intro": "Lưu sách, slide, audio, video theo từng chặng và chọn ai được xem, ai được tải về.",
        "images": [
          {
            "src": "hoc-thuat/tai-lieu.webp",
            "caption": "Hộp thoại Tải lên tài liệu mới",
            "mobile": false
          }
        ],
        "steps": [
          "Vào **Giáo trình → Tài liệu**, bấm **Tải lên tài liệu**.",
          "Chọn giáo trình ở **Chọn giáo trình**, rồi chọn chặng ở **Chọn chặng học**. Danh sách chặng lấy từ màn Soạn syllabus.",
          "Nhập **Tên tài liệu**, tích các vai trò ở **Chọn đối tượng xem**. Tích **Cho phép GV/TG tải về** nếu muốn giáo viên, trợ giảng tải file; bỏ chọn thì họ chỉ xem trực tuyến.",
          "Kéo thả file hoặc bấm **Chọn file từ máy tính**, rồi bấm **Lưu tài liệu**.",
          "Ở danh sách, lọc theo giáo trình hoặc gõ tên để tìm. Dùng các nút ở cột Thao tác để tải về, xem hoặc xóa."
        ],
        "tips": [
          "Admin, Học vụ, Học thuật luôn xem được mọi tài liệu."
        ]
      },
      {
        "id": "order-hoc-lieu",
        "title": "Xử lý order học liệu",
        "icon": "inventory",
        "menu": "Danh sách đầu việc → Order học liệu",
        "route": "material-orders.index",
        "intro": "Giáo viên đặt đạo cụ, in ấn hoặc học liệu học thuật cho lớp. Học thuật và Học vụ nhận xử lý trước hạn.",
        "images": [
          {
            "src": "hoc-thuat/order-hoc-lieu.webp",
            "caption": "Danh sách order học liệu",
            "mobile": false
          },
          {
            "src": "hoc-thuat/order-chi-tiet.webp",
            "caption": "Chi tiết order và khối Xử lý order",
            "mobile": false
          }
        ],
        "steps": [
          "Vào **Danh sách đầu việc**, mở tab **Order học liệu**. Lọc theo trạng thái, loại order, chi nhánh rồi bấm **Lọc**. Order quá hạn có nhãn **Quá hạn**. Bấm vào một dòng để mở chi tiết.",
          "Ở khối **Xử lý order**, bấm **Nhận xử lý** để báo bạn đang làm order này.",
          "Làm xong, nhập **Ghi chú xử lý** (nếu cần) rồi bấm **Hoàn thành**.",
          "Không làm được, bấm **Từ chối**, nhập lý do rồi bấm **Từ chối order**."
        ],
        "tips": []
      },
      {
        "id": "danh-gia-du-gio",
        "title": "Đánh giá dự giờ theo tháng",
        "icon": "fact_check",
        "menu": "Đào tạo & Chất lượng lớp → Đánh giá dự giờ",
        "route": "class-quality.academic",
        "intro": "Ghi nhận đánh giá chuyên môn giảng dạy hằng tháng cho từng lớp: chuyên cần, tỉ lệ đạt yêu cầu và nhận xét theo từng tiêu chí.",
        "images": [
          {
            "src": "hoc-thuat/danh-gia-du-gio.webp",
            "caption": "Hộp thoại Đánh giá dự giờ của một lớp",
            "mobile": false
          }
        ],
        "steps": [
          "Vào **Đào tạo & Chất lượng lớp → Đánh giá dự giờ**. Ba ô đầu trang cho biết số lớp học trong tháng, đã dự giờ và chưa dự giờ.",
          "Chọn tháng, lọc lớp rồi bấm **Lọc**. Cột **Dự giờ tháng** cho biết lớp nào chưa được dự giờ.",
          "Bấm **Đánh giá** ở lớp cần chấm. Tỉ lệ chuyên cần được gợi ý sẵn theo điểm danh trong tháng; nhập thêm **Tỉ lệ % học sinh đạt yêu cầu**.",
          "Tích **Đã dự giờ**, chọn **Ngày dự giờ**, **Người dự giờ**, rồi ghi nhận xét từng tiêu chí (chất lượng giảng dạy, nội dung bài học, kỹ năng tương tác...).",
          "Ghi kế hoạch theo dõi ở **Ghi chú / Hành động**, rồi bấm **Lưu**."
        ],
        "tips": [
          "Các tab khác trong khu này: **Báo cáo & sự vụ lớp** (xem báo cáo ngày Học vụ, báo cáo tháng Giáo viên), **Họp giáo viên**, **Dự án học thuật**."
        ]
      },
      {
        "id": "chot-loi-chuyen-mon",
        "title": "Chốt lỗi chuyên môn của giáo viên",
        "icon": "gavel",
        "menu": "Lương & Phạt",
        "route": "penalties.index",
        "intro": "Biên bản lỗi chuyên môn / giảng dạy (ví dụ dạy sai tiến độ giáo trình) do Học thuật chốt. Lỗi vận hành do Học vụ hoặc Quản lý chốt.",
        "images": [
          {
            "src": "hoc-thuat/chot-loi.webp",
            "caption": "Hộp thoại Chốt lỗi một biên bản",
            "mobile": false
          }
        ],
        "steps": [
          "Vào **Lương & Phạt**. Bấm bước **Ghi nhận** ở dòng **Lọc theo bước** để xem biên bản mới; ô **Chờ HT/CM chốt** cho biết số biên bản đang chờ.",
          "Đọc cột **Lỗi vi phạm**: dòng ghi \"chốt bởi Học thuật (HT)\" là việc của bạn. Bấm **Xem bằng chứng** để mở ảnh hoặc file bằng chứng, bấm **...** để xem biên bản và giải trình của giáo viên.",
          "Bấm **Chốt lỗi**, chọn **Kết luận**: chỉ xác nhận có lỗi (chốt mức phạt sau), hoặc chốt mức phạt luôn và nhập **Số tiền phạt (VNĐ)**.",
          "Ghi **Ghi chú kết luận** rồi bấm **Chốt**.",
          "Biên bản đã chốt lỗi mà chưa có mức phạt: bấm **Chốt mức phạt** khi đã quyết định số tiền."
        ],
        "tips": [
          "Sau khi chốt mức phạt, nhân sự nộp phạt trong hạn; quá hạn sẽ bị trừ vào lương."
        ]
      },
      {
        "id": "du-an-soan-sach",
        "title": "Dự án soạn sách và chương trình",
        "icon": "auto_stories",
        "menu": "Giáo trình → Dự án soạn sách & chương trình",
        "route": "academic-projects.index",
        "intro": "Lập kế hoạch cho dự án soạn sách, xây chương trình: chia mốc, giao người nhận, đặt deadline và theo dõi tiến độ thành viên báo lên.",
        "images": [
          {
            "src": "hoc-thuat/du-an.webp",
            "caption": "Danh sách dự án",
            "mobile": false
          },
          {
            "src": "hoc-thuat/du-an-chi-tiet.webp",
            "caption": "Trang dự án: tiến độ, mốc và deadline",
            "mobile": false
          }
        ],
        "steps": [
          "Vào **Giáo trình → Dự án soạn sách & chương trình**. Bấm **Tạo dự án**, nhập tên, loại dự án, người phụ trách, ngày bắt đầu, deadline, mục tiêu, thành viên rồi bấm **Tạo dự án**.",
          "Bấm vào tên dự án để mở. Ở khối **Mốc tiến độ & deadline**, bấm **Thêm mốc**: nhập tên mốc, người nhận, deadline, khối lượng cần làm.",
          "Khi có tiến triển, bấm **Cập nhật tiến độ**: chọn mốc, khối lượng đã xong, việc đã làm, link sản phẩm, khó khăn, rồi bấm **Gửi cập nhật**.",
          "Ở khối **Báo cáo tiến độ**, bấm **Phản hồi** dưới cập nhật của thành viên để góp ý hoặc hướng xử lý.",
          "Bấm **Chốt tiến độ** khi muốn khóa kế hoạch. Dùng nút **⋮** để tạm dừng, hoàn thành hoặc hủy dự án."
        ],
        "tips": [
          "Sau khi chốt tiến độ, mốc nào quá deadline mà chưa xong sẽ tự lập biên bản chờ giải trình cho người nhận mốc."
        ]
      },
      {
        "id": "kpi-cua-toi",
        "title": "Xem KPI học thuật của tôi",
        "icon": "leaderboard",
        "menu": "Của tôi → KPI của tôi",
        "route": "kpi.mine",
        "intro": "Xem tỉ lệ đạt, xếp loại và thưởng KPI tạm tính của bạn trong kỳ lương, theo từng nhóm tiêu chí học thuật.",
        "images": [
          {
            "src": "hoc-thuat/kpi-cua-toi.webp",
            "caption": "KPI của tôi",
            "mobile": false
          }
        ],
        "steps": [
          "Bấm **Của tôi** ở menu trái rồi mở tab **KPI của tôi**.",
          "Chọn kỳ ở ô **Kỳ lương** góc phải để xem tháng khác.",
          "Khối đầu trang cho biết **Tỉ lệ đạt**, **Xếp loại tháng**, **Thưởng KPI** và trạng thái phiếu.",
          "Kéo xuống để xem từng tiêu chí theo nhóm (chương trình & học liệu, đào tạo GV, chất lượng đầu ra...). Bấm **Xem ... bản ghi** để xem dữ liệu đứng sau con số."
        ],
        "tips": [
          "Số liệu hệ thống tự ghi nhận cập nhật hằng ngày. Tiêu chí điền tay chỉ có số khi người chấm điền cuối kỳ."
        ]
      },
      {
        "id": "bao-cao-tuan",
        "title": "Nộp báo cáo tuần",
        "icon": "summarize",
        "menu": "Báo cáo của tôi → Báo cáo tuần",
        "route": "reports.my",
        "intro": "Học thuật nộp báo cáo tuần (và báo cáo tháng, quý) để quản lý nắm kết quả, việc tồn và kế hoạch.",
        "images": [
          {
            "src": "hoc-thuat/bao-cao-tuan.webp",
            "caption": "Hộp thoại Nộp báo cáo tuần mới",
            "mobile": false
          }
        ],
        "steps": [
          "Bấm **Báo cáo của tôi** ở menu trái, tab **Báo cáo tuần** mở sẵn.",
          "Bấm **Nộp báo cáo tuần**.",
          "Nhập **Tiêu đề**, kiểm tra **Ngày báo cáo**, ghi **Nội dung**: kết quả thực hiện, việc tồn đọng, kế hoạch tuần tới.",
          "Bấm **Nộp báo cáo**. Báo cáo đã nộp hiện ở **Lịch sử đã nộp**."
        ],
        "tips": [
          "Tab **Báo cáo tháng** và **Báo cáo quý** dùng cho báo cáo định kỳ dài hơn."
        ]
      }
    ]
  },
  {
    "id": "hoc-vu",
    "label": "Học vụ",
    "icon": "support_agent",
    "summary": "Học vụ chăm sóc khách và chốt, xếp lớp, quản lý học viên và lớp học, điểm danh thay giáo viên, lập phiếu thu, giao việc cho trợ giảng, đối soát giờ dạy, ghi nhận vi phạm và nộp báo cáo ngày.",
    "sections": [
      {
        "id": "crm",
        "title": "Chăm sóc khách hàng trên CRM",
        "icon": "person_search",
        "menu": "Khách hàng (CRM) → Kanban / Danh sách",
        "route": "crm.pipeline",
        "intro": "Mỗi khách đi qua các giai đoạn Mới → Đang tư vấn → Hẹn test → Test → Đã test → Gửi kết quả → Chờ xếp lớp → Đã chốt. Thẻ khách cho biết hạn chăm sóc để bạn không bỏ sót ai.",
        "images": [
          {
            "src": "hoc-vu/crm-kanban.webp",
            "caption": "Kanban tuyển sinh: mỗi cột là một giai đoạn",
            "mobile": false
          },
          {
            "src": "hoc-vu/crm-khach-hang.webp",
            "caption": "Hồ sơ một khách hàng",
            "mobile": false
          }
        ],
        "steps": [
          "Vào **Khách hàng (CRM)**, tab **Kanban** mở sẵn. Dùng ô tìm tên / số điện thoại, nguồn, người phụ trách, ngày tạo rồi bấm **Lọc**. Nhãn **Còn hạn**, **Sắp hết hạn**, **Quá hạn** trên thẻ cho biết hạn liên hệ hoặc chăm sóc.",
          "Bấm **Sang bước: ...** ở cuối thẻ để chuyển khách sang giai đoạn kế tiếp khi đã làm xong việc của giai đoạn này.",
          "Bấm vào thẻ khách để mở hồ sơ (ở tab Danh sách thì bấm vào tên). Tab **Đặt lịch & Kết quả** có lịch hẹn test, link test, kết quả, học thử; tab **Thông tin khách hàng** để sửa thông tin. Nút **Sang bước** và **Phân công lại** nằm góc trên phải.",
          "Có khách mới: bấm **Thêm khách mới**, nhập **Họ và tên**, **Số điện thoại**, **Nguồn khách**, **Chi nhánh**, rồi bấm **Lưu thông tin**.",
          "Mở tab **Danh sách** và bấm các nút lọc nhanh **Chưa liên hệ >24h**, **Hẹn test hôm nay**, **Cần gọi lại**, **Chờ xếp lớp** để xử lý việc gấp trước."
        ],
        "tips": [
          "Trang **Tổng quan** có khối **Cần xử lý** (khách chưa liên hệ quá 24h, khách chờ xếp lớp, học phí quá hạn) và **Lịch hẹn 7 ngày tới**.",
          "Có nhiều khách trong file Excel: bấm **Nhập Excel**."
        ]
      },
      {
        "id": "chot-xep-lop",
        "title": "Chốt khách và xếp lớp",
        "icon": "how_to_reg",
        "menu": "Khách hàng (CRM) → Kanban → Chốt & Xếp lớp",
        "route": "crm.closing-wizard",
        "intro": "Quy trình 4 bước biến khách thành học viên: xác nhận chốt, tính học phí và ưu đãi, chọn lớp (hoặc để Chờ xếp lớp), rồi thu phí đăng ký và xuất phiếu thu.",
        "images": [
          {
            "src": "hoc-vu/chot-xep-lop.webp",
            "caption": "Quy trình Chốt & Xếp lớp, Bước 1",
            "mobile": false
          },
          {
            "src": "hoc-vu/cho-xep-lop.webp",
            "caption": "Danh sách khách chờ xếp lớp",
            "mobile": false
          }
        ],
        "steps": [
          "Trên Kanban, bấm **Chốt & Xếp lớp** ở thẻ khách. Ở **Bước 1**, kiểm tra **Khách cần chốt** và chọn **Khóa học đăng ký**.",
          "Bấm **Tiếp tục: Tính học phí & Ưu đãi**. Kiểm tra học phí, chọn **Chương trình Ưu đãi / Voucher** nếu có, nhập khoản thu khác và số tiền thu trước, rồi bấm **Tiếp tục: Xếp lớp**.",
          "Ở **Bước 3**, chọn **Chọn lớp** và chọn một lớp đang học hoặc sắp khai giảng còn chỗ. Chưa có lớp phù hợp thì chọn **Xếp lớp sau**. Bấm **Tiếp tục: Xác nhận & Thu tiền**.",
          "Ở **Bước 4**, kiểm tra tóm tắt hợp đồng, nhập **Số tiền thu thực tế đợt 1**, chọn **Chuyển khoản (VietQR)** hoặc **Tiền mặt tại quầy**. Bấm **Xem & In Bill** nếu cần in, rồi bấm **Hoàn tất Chốt Deal, Xếp Lớp & Xuất Phiếu Thu**.",
          "Khách đã chọn Xếp lớp sau: vào tab **Danh sách**, bấm **Chờ xếp lớp**, chọn lớp ở dòng của khách rồi bấm **Xếp lớp**."
        ],
        "tips": [
          "Thu tiền mặt phải nhập số hóa đơn giấy và tải ảnh hóa đơn giấy.",
          "Chưa thu tiền khi chốt: hệ thống tự tạo việc \"Nhắc thu học phí\" cho người phụ trách khách, hạn 3 ngày."
        ]
      },
      {
        "id": "xac-nhan-nhap-hoc",
        "title": "Xác nhận nhập học và bàn giao lớp",
        "icon": "assignment_turned_in",
        "menu": "Học viên → Tiếp nhận & Xếp lớp",
        "route": "students.enrollments",
        "intro": "Sau khi học viên được xếp lớp, Học vụ hoàn tất bàn giao: phát giáo trình và thêm vào nhóm Zalo lớp.",
        "images": [
          {
            "src": "hoc-vu/xac-nhan-nhap-hoc.webp",
            "caption": "Màn Xác nhận nhập học",
            "mobile": false
          }
        ],
        "steps": [
          "Vào **Học viên**, bấm **Tiếp nhận & Xếp lớp** ở góc trên phải.",
          "Ở **Danh sách bàn giao học viên gần đây**, xem cột **Phát giáo trình** và **Nhóm Zalo lớp**. Làm xong việc nào thì tích vào ô đó rồi bấm **Lưu**; trạng thái chuyển sang **Hoàn tất** khi đủ.",
          "Dòng của học viên chốt từ CRM có link **Sửa ở Xác nhận chính thức**: bấm vào để cập nhật bàn giao ở màn đó.",
          "Cần xếp một học viên đã có hồ sơ vào lớp: bấm **Xếp lớp cho học viên**, chọn **Chọn Học viên** và **Chọn Lớp học mục tiêu**, rồi bấm **Xếp lớp**.",
          "Dòng báo đỏ \"khách chốt từ CRM đang chờ xếp lớp\" cho biết số khách còn chờ. Bấm **Xếp lớp** ở dòng đó để mở danh sách chờ."
        ],
        "tips": []
      },
      {
        "id": "hoc-vien",
        "title": "Tra cứu và cập nhật hồ sơ học viên",
        "icon": "school",
        "menu": "Học viên",
        "route": "students.index",
        "intro": "Xem toàn bộ học viên theo trạng thái, lớp, chi nhánh và sửa thông tin liên hệ, phụ huynh khi cần.",
        "images": [
          {
            "src": "hoc-vu/hoc-vien.webp",
            "caption": "Hồ sơ học sinh: danh sách",
            "mobile": false
          },
          {
            "src": "hoc-vu/hoc-vien-chi-tiet.webp",
            "caption": "Chi tiết hồ sơ học sinh",
            "mobile": false
          }
        ],
        "steps": [
          "Bấm **Học viên** ở menu trái. Bấm các nút **Trạng thái** (Chờ khai giảng, Đang học, Bảo lưu, Nghỉ hè, Hoàn thành khóa học, Thôi học) để lọc nhanh.",
          "Gõ tên hoặc số điện thoại, chọn chi nhánh, lớp, thâm niên rồi bấm **Lọc**.",
          "Bấm **Chi tiết** để mở hồ sơ. Sửa họ tên, số điện thoại, phụ huynh, SĐT phụ huynh (dùng để gửi kết quả qua Zalo), trường học, ghi chú, rồi bấm **Lưu thay đổi**.",
          "Học viên học song song thêm một lớp: bấm **Liên kết lớp khác** ở dòng học viên. Lớp chính không đổi."
        ],
        "tips": [
          "Nhớ nhập đúng **SĐT phụ huynh**: thiếu số này thì không gửi được kết quả Big Test cho phụ huynh."
        ]
      },
      {
        "id": "tao-lop",
        "title": "Tạo lớp mới",
        "icon": "add_home",
        "menu": "Lớp học → Tạo lớp mới",
        "route": "classes.create",
        "intro": "Tạo khung thông tin của lớp trước, sau đó mới xếp lịch định kỳ và xếp học viên vào lớp.",
        "images": [
          {
            "src": "hoc-vu/tao-lop.webp",
            "caption": "Màn Tạo lớp mới",
            "mobile": false
          }
        ],
        "steps": [
          "Vào **Lớp học**, bấm **Tạo lớp mới** ở góc phải hàng tab.",
          "Ở mục **1. Định danh & phân loại chương trình**, nhập **Tên lớp**, chọn **Chi nhánh**, **Chương trình**, **Cấp độ**. Mã lớp để trống thì hệ thống tự tạo.",
          "Ở mục **2. Sĩ số & phòng học dự kiến**, nhập **Sĩ số tối đa**, **Ngưỡng khai giảng** và chọn phòng (nếu đã biết).",
          "Ở mục **3. Nhân sự phụ trách**, chọn **Giáo viên chính** và **Giáo viên nước ngoài** (nếu có). Mục **4** để nhập học phí niêm yết và ghi chú nội bộ.",
          "Bấm **Lưu lớp mới**. Lớp ở trạng thái Chờ lịch; mở tab **Lịch & buổi học** của lớp để xếp lịch định kỳ."
        ],
        "tips": [
          "Ngưỡng khai giảng là số học viên tối thiểu để mở lớp, không vượt sĩ số tối đa."
        ]
      },
      {
        "id": "lop-hoc-diem-danh",
        "title": "Theo dõi lớp, lịch học và điểm danh thay giáo viên",
        "icon": "fact_check",
        "menu": "Lớp học → Danh sách lớp / Lịch học các lớp",
        "route": "classes.index",
        "intro": "Trang lớp gom mọi thông tin của một lớp. Khi giáo viên chưa điểm danh, Học vụ điểm danh thay ngay trên portal.",
        "images": [
          {
            "src": "hoc-vu/trang-lop.webp",
            "caption": "Trang lớp, tab Điểm danh & báo cáo buổi",
            "mobile": false
          },
          {
            "src": "hoc-vu/diem-danh.webp",
            "caption": "Màn điểm danh một buổi học",
            "mobile": false
          }
        ],
        "steps": [
          "Vào **Lớp học**, lọc theo trạng thái (Sắp khai giảng, Đang học...) và bấm **Mở lớp** ở lớp cần xem. Ô **Buổi hôm nay chưa điểm danh** đưa bạn thẳng tới các buổi còn thiếu.",
          "Trên trang lớp, thanh tiến trình cho biết lớp đang ở bước nào (Tạo lớp → Cấu hình lịch → Xếp học viên → Khai giảng → Vận hành → Kết thúc). Các tab: **Tổng quan**, **Học viên**, **Lịch & buổi học**, **Điểm danh & báo cáo buổi**, **Học thuật**, **Sự vụ**.",
          "Mở tab **Điểm danh & báo cáo buổi**, bấm **Điểm danh** ở buổi có nhãn **Chưa điểm danh**.",
          "Chọn trạng thái cho từng học sinh: **Đúng giờ**, **Đi muộn**, **Nghỉ có phép**, **Nghỉ không phép**, ghi chú nếu cần.",
          "Bấm **Lưu điểm danh**. Hệ thống ghi nhận bạn là người điểm danh thay giáo viên.",
          "Xem tất cả buổi học trong ngày ở tab **Lịch học các lớp** (**Theo ngày** / **Theo tuần**): số buổi đã và chưa điểm danh, trợ giảng làm việc hôm nay. Bấm **Xuất báo cáo** để tải về."
        ],
        "tips": [
          "Thanh màu trên màn điểm danh cho biết buổi có đang trong cửa sổ điểm danh hay không. Bấm **Xem quy định** để đọc quy định."
        ]
      },
      {
        "id": "lap-phieu-thu",
        "title": "Lập phiếu thu học phí",
        "icon": "add_card",
        "menu": "Học phí → Lập phiếu thu",
        "route": "tuition.receipts.create",
        "intro": "Ghi nhận tiền học viên đã nộp. Phiếu thu được gửi lên để duyệt; Học vụ lập phiếu, không tự duyệt.",
        "images": [
          {
            "src": "hoc-vu/cong-no.webp",
            "caption": "Học phí → Công nợ học viên",
            "mobile": false
          },
          {
            "src": "hoc-vu/lap-phieu-thu.webp",
            "caption": "Màn Lập phiếu thu học phí",
            "mobile": false
          }
        ],
        "steps": [
          "Vào **Học phí**, tab **Công nợ học viên** cho biết ai còn nợ, hạn nộp, trạng thái. Bấm **Lập phiếu thu** (hoặc biểu tượng thu tiền ở dòng học viên).",
          "Chọn **Khoản học phí đang nợ** và **Học viên được ghi nhận**. Kiểm tra số buổi thu đợt này, đơn giá và tiền học phí theo buổi.",
          "Nếu có, chọn ưu đãi hoặc nhập số tiền giảm trừ, thêm học liệu, phí thi, khoản thu khác, phụ thu. Khung tổng ở dưới cho biết **Tổng thực thu**.",
          "Chọn **Hình thức thu tiền** (**Chuyển khoản** hoặc **Tiền mặt**), điền người nộp tiền và tải ảnh ủy nhiệm chi hoặc biên lai ở **Minh chứng thanh toán**.",
          "Bấm **Lưu phiếu thu & Gửi duyệt**. Chưa đủ thông tin thì bấm **Lưu nháp** để làm tiếp sau."
        ],
        "tips": [
          "Theo dõi phiếu đã lập ở tab **Lịch sử thu**. Học viên trễ hạn nằm ở tab **Quá hạn & Nhắc phí**."
        ]
      },
      {
        "id": "giao-viec-tro-giang",
        "title": "Giao việc cho trợ giảng và theo dõi đầu việc",
        "icon": "support_agent",
        "menu": "Danh sách đầu việc → Giao việc cho Trợ giảng",
        "route": "tasks.ta-assign",
        "intro": "Giao nhiệm vụ theo ca (trước, trong, sau giờ học) cho trợ giảng, và theo dõi mọi việc bạn nhận hoặc giao.",
        "images": [
          {
            "src": "hoc-vu/giao-viec-tro-giang.webp",
            "caption": "Tạo lượt giao việc cho Trợ giảng",
            "mobile": false
          },
          {
            "src": "hoc-vu/danh-sach-dau-viec.webp",
            "caption": "Danh sách đầu việc",
            "mobile": false
          }
        ],
        "steps": [
          "Vào **Danh sách đầu việc**, bấm **Giao việc cho Trợ giảng** ở góc phải hàng tab (hoặc ở màn **Lịch học các lớp**).",
          "Chọn **Chọn Trợ giảng**, **Ngày giao việc** và **Chi nhánh**.",
          "Với mỗi đầu việc, chọn nhóm (**Trước giờ học**, **Trong giờ học**, **Sau giờ học**), nhập nội dung, tích **Gắn lớp?** nếu việc gắn với một buổi học. Bấm **Thêm đầu việc** để thêm dòng.",
          "Bấm **Gửi nhiệm vụ**. Trợ giảng thấy việc ở màn Nhiệm vụ hôm nay.",
          "Ở **Danh sách đầu việc**, tab **Của tôi** là việc bạn nhận, **Tôi giao** là việc bạn giao. Bấm **⋮** ở một dòng để đổi trạng thái. Giao việc cho nhân sự khác: bấm **Tạo đầu việc**."
        ],
        "tips": [
          "Nên gửi trước 15h30. Hạn từng ca: trước giờ học là lúc lớp bắt đầu, trong giờ học là lúc lớp tan, sau giờ học là 1 tiếng sau khi lớp tan."
        ]
      },
      {
        "id": "gio-day-giao-vien",
        "title": "Đối soát giờ dạy giáo viên",
        "icon": "schedule",
        "menu": "Chấm công → Giờ dạy giáo viên",
        "route": "payroll.timesheets.teachers",
        "intro": "Kiểm tra ca dạy thực tế (check-in hoặc chấm tay) của giáo viên, trợ giảng trước khi chốt bảng công và tính lương.",
        "images": [
          {
            "src": "hoc-vu/gio-day-giao-vien.webp",
            "caption": "Chấm công theo lịch trong ngày",
            "mobile": false
          },
          {
            "src": "hoc-vu/ca-day-trong-ky.webp",
            "caption": "Bảng Ca dạy trong kỳ",
            "mobile": false
          }
        ],
        "steps": [
          "Vào **Chấm công → Giờ dạy giáo viên**. Chọn kỳ lương, chi nhánh, lớp, giáo viên, trạng thái rồi bấm **Lọc**.",
          "Ở khối **Chấm công theo lịch**, chọn **Ngày**. Bấm **Xác nhận** ở buổi học để tính công theo giờ lịch cho giáo viên dự kiến.",
          "Ở bảng **Ca dạy trong kỳ**, ca **Chờ đối soát** có nút **Duyệt** và **Từ chối**. Bấm biểu tượng bút để chỉnh tay giờ vào, giờ ra, phút đi muộn, về sớm, nhập lý do rồi bấm **Lưu thay đổi**.",
          "Tích chọn nhiều ca chờ đối soát rồi bấm **Chốt bảng công** để duyệt cùng lúc.",
          "Buổi giáo viên quên chấm công hiện ở **Buổi thiếu chấm công**: bấm **Chấm công tay**. Gặp sự cố mất mạng, mất điện: dùng nút **Chấm công thủ công** (tab **Chấm công đơn lẻ**)."
        ],
        "tips": []
      },
      {
        "id": "vi-pham",
        "title": "Ghi nhận vi phạm và chốt lỗi vận hành",
        "icon": "gavel",
        "menu": "Lương & Phạt",
        "route": "penalties.index",
        "intro": "Ghi nhận vi phạm của nhân sự kèm bằng chứng. Lỗi vận hành / nội quy do Học vụ hoặc Quản lý chốt; lỗi chuyên môn do Học thuật chốt.",
        "images": [
          {
            "src": "hoc-vu/vi-pham.webp",
            "caption": "Danh sách vi phạm",
            "mobile": false
          },
          {
            "src": "hoc-vu/ghi-nhan-vi-pham.webp",
            "caption": "Hộp thoại Ghi nhận vi phạm mới",
            "mobile": false
          }
        ],
        "steps": [
          "Vào **Lương & Phạt**, bấm **Ghi nhận vi phạm mới**.",
          "Chọn **Nhân sự vi phạm**, **Loại lỗi**, **Lỗi vi phạm** (chọn lỗi thường gặp hoặc tự nhập), **Thời điểm vi phạm**, lớp liên quan. Tải **Bằng chứng vi phạm**, ghi **Mô tả sự việc**, rồi bấm **Ghi nhận**.",
          "Nhân sự sẽ giải trình. Khi đến lượt bạn, bấm **Chốt lỗi** ở dòng lỗi vận hành (ghi \"chốt bởi Học vụ / Quản lý (CM)\"), chọn **Kết luận**, nhập số tiền nếu chốt mức phạt, rồi bấm **Chốt**.",
          "Dùng **Lọc theo bước** (Ghi nhận, Đã chốt lỗi, Đã chốt phạt...) và các ô số liệu đầu trang để theo dõi. Bấm **...** để xem chi tiết biên bản."
        ],
        "tips": [
          "Chỉ ghi nhận được vi phạm trong vòng 24 giờ. Không cần nhập số tiền khi ghi nhận: mức phạt được chốt sau khi nhân sự giải trình."
        ]
      },
      {
        "id": "big-test-nhac-lich",
        "title": "Nhắc lịch Big Test",
        "icon": "notifications_active",
        "menu": "Big Test → Nhắc lịch",
        "route": "syllabus.big-tests.schedules",
        "intro": "Theo dõi lớp sắp thi Big Test và gửi thông báo nhắc lịch thi cho học viên, phụ huynh.",
        "images": [
          {
            "src": "hoc-vu/big-test-nhac-lich.webp",
            "caption": "Big Test → Nhắc lịch",
            "mobile": false
          }
        ],
        "steps": [
          "Vào **Big Test**, tab **Nhắc lịch** mở sẵn. Ô **Khẩn cấp (1-2 ngày)** cho biết chặng sắp thi gấp.",
          "Bảng **Chặng sắp thi, chưa duyệt đề** liệt kê lớp sắp thi trong 7 ngày mà đề chưa được duyệt. Nhắc giáo viên order đề và báo Học thuật.",
          "Ở bảng **Đợt thi Big Test đã tạo**, bấm **Nhắc lịch** rồi xác nhận để gửi thông báo lịch thi vào Cổng Phụ huynh & Học sinh của cả lớp.",
          "Bấm **Xem điểm** để mở bảng điểm của đợt thi, hoặc **Lịch dự kiến theo lớp** để xem lịch thi dự kiến."
        ],
        "tips": []
      },
      {
        "id": "bao-cao-ngay",
        "title": "Nộp báo cáo ngày",
        "icon": "summarize",
        "menu": "Báo cáo của tôi → Báo cáo ngày",
        "route": "reports.my",
        "intro": "Cuối mỗi ngày, Học vụ nộp báo cáo vận hành: việc đã làm, việc tồn và kế hoạch hôm sau.",
        "images": [
          {
            "src": "hoc-vu/bao-cao-ngay.webp",
            "caption": "Hộp thoại Nộp báo cáo ngày mới",
            "mobile": false
          }
        ],
        "steps": [
          "Bấm **Báo cáo của tôi** ở menu trái, tab **Báo cáo ngày** mở sẵn.",
          "Bấm **Nộp báo cáo ngày**.",
          "Nhập **Tiêu đề**, kiểm tra **Ngày báo cáo**, ghi **Nội dung**: kết quả thực hiện, tồn đọng, kế hoạch.",
          "Bấm **Nộp báo cáo**. Báo cáo đã nộp hiện ở **Lịch sử đã nộp**."
        ],
        "tips": [
          "Tab **Báo cáo tuần (KPI)** dùng cho báo cáo tuần theo KPI."
        ]
      }
    ]
  },
  {
    "id": "tu-van",
    "label": "Tư vấn viên",
    "icon": "headset_mic",
    "summary": "Tư vấn viên nhận và chăm sóc khách tuyển sinh của mình trong Khách hàng (CRM): thêm khách, ghi nhật ký liên hệ, hẹn test đầu vào, gửi kết quả cho phụ huynh và chốt khách vào lớp. Bạn chỉ thấy những khách mình phụ trách.",
    "sections": [
      {
        "id": "tong-quan",
        "title": "Xem việc cần làm hôm nay",
        "icon": "dashboard",
        "menu": "Tổng quan",
        "route": "dashboard",
        "intro": "Trang Tổng quan gom việc của riêng bạn: lịch hẹn test, khách cần gọi lại, khách sắp quá hạn liên hệ và số khách đã chốt trong tháng.",
        "images": [
          {
            "src": "tu-van/tong-quan.webp",
            "caption": "Tổng quan của Tư vấn viên: các thẻ số liệu, lịch hẹn 7 ngày tới và khối Cần xử lý",
            "mobile": false
          }
        ],
        "steps": [
          "Sau khi đăng nhập, bạn vào thẳng **Tổng quan**. Hàng thẻ trên cùng cho biết **Hẹn test hôm nay**, **Học thử 7 ngày tới**, **Cần gọi lại hôm nay**, việc đến hạn tuần này và **Khách chốt** trong tháng.",
          "Khối **Lịch hẹn 7 ngày tới** liệt kê lịch test đầu vào, học thử, hẹn gọi lại và support sau chốt (chăm sóc tháng đầu của khách đã chốt vào lớp) của khách bạn phụ trách, xếp theo ngày và giờ.",
          "Khối **Cần xử lý** nhắc những nhóm khách cần làm ngay, ví dụ **Khách chưa liên hệ >24h**. Bấm vào dòng đó để mở danh sách khách tương ứng.",
          "Khối **Việc của tôi trong tuần** hiện các đầu việc đến hạn. Bấm **Xem tất cả** để mở danh sách đầu việc."
        ],
        "tips": [
          "Mỗi sáng nên xử lý hết khối Cần xử lý trước, vì khách quá hạn liên hệ bị tính vào KPI."
        ]
      },
      {
        "id": "them-khach",
        "title": "Thêm khách mới hoặc nhập từ Excel",
        "icon": "person_add",
        "menu": "Khách hàng (CRM) → nút Thêm khách mới / Nhập Excel",
        "route": "crm.pipeline",
        "intro": "Mỗi khách quan tâm (gọi đến, nhắn tin, đến trung tâm) cần có hồ sơ trong CRM để được nhắc chăm sóc đúng hạn. Khách mới vào giai đoạn Mới và do bạn phụ trách.",
        "images": [
          {
            "src": "tu-van/them-khach.webp",
            "caption": "Hộp thoại Thêm khách mới",
            "mobile": false
          },
          {
            "src": "tu-van/nhap-excel.webp",
            "caption": "Nhập khách hàng loạt từ file Excel",
            "mobile": false
          }
        ],
        "steps": [
          "Vào **Khách hàng (CRM)**, bấm **Thêm khách mới** ở góc phải hàng tab.",
          "Nhập **Họ và tên**, **Số điện thoại** (10 số, bắt đầu bằng 0), chọn **Nguồn khách** và **Chi nhánh**. Có thể nhập thêm **Tên phụ huynh**.",
          "Mở **Thông tin bổ sung (tùy chọn)** để điền khóa học quan tâm, giá trị dự kiến, ngày sinh, email, hạn liên hệ tiếp theo và ghi chú ban đầu.",
          "Bấm **Lưu thông tin**. Khách xuất hiện ở cột **Mới** trên Kanban.",
          "Có nhiều khách từ sự kiện hoặc quảng cáo: bấm **Nhập Excel**, bấm **Tải file mẫu**, điền theo mẫu rồi chọn file, chọn **Chi nhánh nhận khách** và bấm **Kiểm tra dữ liệu** để xem trước trước khi nhập."
        ],
        "tips": [
          "Số điện thoại và email không được trùng với khách đang hoạt động. Nếu báo trùng, tìm khách đó trong Danh sách trước khi tạo mới.",
          "Mỗi file Excel tối đa 1.000 dòng. Cột bắt buộc: Họ tên và Số điện thoại."
        ]
      },
      {
        "id": "kanban",
        "title": "Theo dõi khách trên Kanban",
        "icon": "view_kanban",
        "menu": "Khách hàng (CRM) → Kanban",
        "route": "crm.pipeline",
        "intro": "Kanban xếp khách của bạn thành các cột theo giai đoạn: Mới, Đang tư vấn, Hẹn test, Test, Đã test, Gửi kết quả, Chờ xếp lớp, Đã chốt. Mỗi thẻ cho biết hạn chăm sóc tiếp theo.",
        "images": [
          {
            "src": "tu-van/kanban.webp",
            "caption": "Kanban tuyển sinh: mỗi cột là một giai đoạn, thẻ đỏ là khách quá hạn",
            "mobile": false
          }
        ],
        "steps": [
          "Vào **Khách hàng (CRM) → Kanban**. Dùng ô tìm kiếm, bộ lọc **Nguồn** và **Ngày tạo** rồi bấm **Lọc** để thu hẹp danh sách.",
          "Nhìn dòng hạn ở cuối mỗi thẻ: **Quá hạn** (đỏ) là khách cần liên hệ ngay.",
          "Bấm vào thẻ để mở hồ sơ khách và chăm sóc tiếp.",
          "Với khách đã sẵn sàng đăng ký, bấm **Chốt & Xếp lớp** ngay trên thẻ.",
          "Bảng rộng hơn màn hình: bấm mũi tên ở mép phải để xem các cột còn lại."
        ],
        "tips": [
          "Giai đoạn khách do Học vụ hoặc Quản lý cơ sở chuyển. Trên thẻ, nút **Sửa giai đoạn** của bạn chỉ dùng để đánh dấu khách Thất bại."
        ]
      },
      {
        "id": "danh-sach",
        "title": "Lọc nhanh khách cần xử lý",
        "icon": "filter_list",
        "menu": "Khách hàng (CRM) → Danh sách",
        "route": "crm.customers.index",
        "intro": "Danh sách khách có các nút lọc nhanh ở đầu trang, giúp bạn thấy ngay khách chưa liên hệ, khách có lịch test hôm nay hay khách cần gọi lại.",
        "images": [
          {
            "src": "tu-van/danh-sach.webp",
            "caption": "Danh sách khách với các nút lọc nhanh và cột Hạn SLA liên hệ",
            "mobile": false
          }
        ],
        "steps": [
          "Vào **Khách hàng (CRM) → Danh sách**.",
          "Bấm một nút lọc nhanh: **Chưa liên hệ >24h**, **Hẹn test hôm nay**, **Cần gọi lại** (chỉ hiện khi có khách), **Chờ xếp lớp**, **Đã nhập học** hoặc **Thất bại**. Số bên cạnh là số khách.",
          "Gõ tên hoặc số điện thoại vào ô **Tìm tên hoặc SĐT...**, chọn thêm **Nguồn**, **Giai đoạn** rồi bấm **Lọc**.",
          "Cột **Hạn SLA liên hệ** cho biết khách nào đã quá hạn. Bấm tên khách hoặc biểu tượng bút ở cột **Thao tác** để mở hồ sơ."
        ],
        "tips": []
      },
      {
        "id": "cham-soc",
        "title": "Ghi nhật ký chăm sóc và đặt hạn liên hệ",
        "icon": "call",
        "menu": "Khách hàng (CRM) → bấm vào khách → Lịch sử hoạt động",
        "route": "crm.customers.index",
        "intro": "Sau mỗi lần gọi, nhắn Zalo hay gặp khách, hãy ghi lại trong hồ sơ. Ghi chú này cập nhật \"Liên hệ gần nhất\" và giúp người khác nắm được tình hình khách.",
        "images": [
          {
            "src": "tu-van/ho-so-khach.webp",
            "caption": "Hồ sơ khách: nút Chốt & Xếp lớp và menu ⋯ ở góc phải",
            "mobile": false
          },
          {
            "src": "tu-van/ghi-chu.webp",
            "caption": "Ghi chú liên hệ mới trong Lịch sử hoạt động",
            "mobile": false
          },
          {
            "src": "tu-van/thong-tin-khach.webp",
            "caption": "Tab Thông tin khách hàng: sửa thông tin và Hạn liên hệ tiếp theo",
            "mobile": false
          }
        ],
        "steps": [
          "Mở hồ sơ khách từ Kanban hoặc Danh sách. Khối **Trạng thái & Hạn xử lý** cho biết giai đoạn hiện tại, lần liên hệ gần nhất và hạn chăm sóc tiếp theo.",
          "Kéo xuống khối **Lịch sử hoạt động**, gõ nội dung vào ô **Ghi chú nội dung liên hệ mới...**.",
          "Chọn **Hình thức**: **Gọi điện**, **Zalo/SMS**, **Trực tiếp**, **Test đầu vào** hoặc **Ghi chú**. Với gọi, nhắn, gặp, chọn thêm **Kết quả**: **Liên hệ được** hoặc **Không liên hệ được**.",
          "Bấm **Lưu ghi chú**. Ghi chú hiện ngay trong lịch sử, có tên bạn và giờ ghi.",
          "Hẹn khách gọi lại: bấm **Đặt hạn liên hệ** (hoặc mở tab **Thông tin khách hàng**), chọn **Hạn liên hệ tiếp theo** rồi bấm **Lưu thay đổi**. Cũng ở tab này bạn sửa số điện thoại, phụ huynh, khóa quan tâm, ghi chú nhu cầu."
        ],
        "tips": [
          "Khách báo \"Sắp hết hạn\" trước 24 giờ và \"Quá hạn\" khi qua hạn liên hệ. Ghi chú hoặc đặt hạn mới để tắt cảnh báo.",
          "Dùng ô **Tất cả hoạt động** phía trên để lọc lịch sử theo loại (Gọi điện, Hệ thống...)."
        ]
      },
      {
        "id": "hen-test",
        "title": "Hẹn test đầu vào và gửi link test online",
        "icon": "event_available",
        "menu": "Khách hàng (CRM) → bấm vào khách → tab Đặt lịch & Kết quả → Lịch hẹn Test",
        "route": "crm.customers.index",
        "intro": "Khi khách đang ở bước Đang tư vấn, bạn chọn đề test theo cấp độ, hẹn ngày giờ và gửi link để khách làm bài online. Khách tự chuyển sang giai đoạn Hẹn test.",
        "images": [
          {
            "src": "tu-van/hen-test.webp",
            "caption": "Khối Lịch hẹn Test: chọn cấp độ, đề, ngày giờ rồi Gửi link test online",
            "mobile": false
          }
        ],
        "steps": [
          "Mở hồ sơ khách, ở tab **Đặt lịch & Kết quả** tìm khối **Lịch hẹn Test** (nhãn **Chưa gửi đề**).",
          "Chọn **Chọn cấp độ** (ví dụ Lớp 2), rồi chọn đề trong **Danh sách đề tương ứng**.",
          "Chọn **Ngày hẹn làm test** và **Giờ hẹn**, bấm **Gửi link test online**.",
          "Nếu khách có email, hệ thống gửi email xác nhận lịch hẹn. Nếu không, một hộp thoại hiện nội dung xác nhận: bấm **Sao chép nội dung** rồi dán gửi phụ huynh qua Zalo.",
          "Sau khi gửi, khối hiện **Đã gửi link, chờ khách làm bài**. Dùng **Sao chép link test** hoặc **Gửi lại link** (link mới) để gửi lại cho khách qua Zalo/SMS."
        ],
        "tips": [
          "Khách ở bước Mới chưa hẹn test được: nhờ Học vụ / Quản lý cơ sở chuyển sang Đang tư vấn trước.",
          "Khách đã có kết quả nhưng cần test lại: bấm **Hẹn test lại** trong cùng khối."
        ]
      },
      {
        "id": "gui-ket-qua",
        "title": "Gửi kết quả test cho phụ huynh, theo dõi học thử",
        "icon": "forward_to_inbox",
        "menu": "Khách hàng (CRM) → bấm vào khách → tab Đặt lịch & Kết quả",
        "route": "crm.customers.index",
        "intro": "Khi khách làm xong bài test, điểm hiện ở khối Kết quả & Đánh giá. Bạn báo kết quả cho phụ huynh và ghi lại phản hồi của họ ngay trong hồ sơ.",
        "images": [
          {
            "src": "tu-van/gui-ket-qua.webp",
            "caption": "Kết quả test và khối Gửi kết quả & Phản hồi",
            "mobile": false
          }
        ],
        "steps": [
          "Mở hồ sơ khách đã có điểm. Khối **Kết quả & Đánh giá** hiện điểm tổng và điểm từng kỹ năng. Bấm **Tải kết quả (PDF)** nếu có để gửi phụ huynh.",
          "Sau khi báo kết quả, ở khối **Gửi kết quả & Phản hồi** chọn **Ngày gửi KQ phụ huynh**.",
          "Ghi ý kiến của phụ huynh vào ô **Phản hồi của phụ huynh**, bấm **Lưu gửi kết quả**.",
          "Khối **Học thử** cho biết khách đã học thử mấy buổi, ở lớp nào, và **Nhận xét học thử** của giáo viên sau buổi học."
        ],
        "tips": [
          "Học vụ / Quản lý cơ sở là người xếp buổi học thử. Bạn báo họ khi khách muốn học thử, rồi theo dõi nhận xét trong hồ sơ.",
          "Bấm **Thang điểm & hướng dẫn nhận xét** để giải thích điểm cho phụ huynh."
        ]
      },
      {
        "id": "chot-xep-lop",
        "title": "Chốt khách, áp ưu đãi và xếp lớp",
        "icon": "how_to_reg",
        "menu": "Khách hàng (CRM) → hồ sơ khách → Chốt & Xếp lớp",
        "route": "crm.closing-wizard",
        "intro": "Khi khách đồng ý đăng ký, quy trình Chốt & Xếp lớp tạo học viên, tài khoản, học phí, xếp lớp và phiếu thu trong 4 bước. Khách ở Đang tư vấn, Đã test hoặc Gửi kết quả mới chốt được.",
        "images": [
          {
            "src": "tu-van/chot-buoc-1.webp",
            "caption": "Bước 1: chọn khách và khóa học đăng ký",
            "mobile": false
          },
          {
            "src": "tu-van/chot-uu-dai.webp",
            "caption": "Bước 2: học phí niêm yết và chọn ưu đãi có sẵn",
            "mobile": false
          },
          {
            "src": "tu-van/chot-xep-lop.webp",
            "caption": "Bước 3: chọn lớp phù hợp hoặc Xếp lớp sau",
            "mobile": false
          },
          {
            "src": "tu-van/chot-thu-phi.webp",
            "caption": "Bước 4: tóm tắt hợp đồng, số tiền thu và phương thức thanh toán",
            "mobile": false
          }
        ],
        "steps": [
          "Trong hồ sơ khách (hoặc trên thẻ Kanban), bấm **Chốt & Xếp lớp**. Ở **Bước 1**, kiểm tra **Khách cần chốt**, chọn **Khóa học đăng ký**, bấm **Tiếp tục: Tính học phí & Ưu đãi**.",
          "Ở **Bước 2**, học phí niêm yết tự điền. Chọn ưu đãi trong **Chương trình Ưu đãi / Voucher** nếu khách thuộc diện áp dụng, thêm khoản thu khác (giáo trình, phụ phí) nếu có, xem tổng ở cuối khối rồi bấm **Tiếp tục: Xếp lớp**.",
          "Ở **Bước 3**, bấm **Chọn lớp này** trên lớp phù hợp (còn chỗ, đúng cấp độ). Chưa có lớp hợp: chọn **Xếp lớp sau**, khách vào mục Chờ xếp lớp. Bấm **Tiếp tục: Xác nhận & Thu tiền**.",
          "Ở **Bước 4**, kiểm tra **Tóm tắt hợp đồng đào tạo**. Nếu khách đã đóng, tích **Đã đóng học phí đăng ký**, nhập **Số tiền thu thực tế đợt 1**, chọn **Chuyển khoản (VietQR)** hoặc **Tiền mặt tại quầy**.",
          "Bấm **Xem & In Bill** để in phiếu báo cho phụ huynh nếu cần, rồi bấm **Hoàn tất Chốt Deal, Xếp Lớp & Xuất Phiếu Thu**."
        ],
        "tips": [
          "Chưa thu tiền thì bỏ tích Đã đóng học phí đăng ký: hệ thống tạo việc \"Nhắc thu học phí\" cho người phụ trách, hạn 3 ngày.",
          "Thu tiền mặt phải nhập số hóa đơn giấy và tải ảnh hóa đơn lên. Phiếu thu được gửi Kế toán / Admin duyệt.",
          "Lớp hoặc khóa chưa có học phí niêm yết thì chưa chốt được: báo người quản lý khóa học nhập giá."
        ]
      },
      {
        "id": "that-bai",
        "title": "Đánh dấu khách thất bại",
        "icon": "person_off",
        "menu": "Khách hàng (CRM) → hồ sơ khách → ⋯ → Thất bại",
        "route": "crm.lost-deals",
        "intro": "Khách không đăng ký (học phí, lịch học, không liên lạc được...) thì đánh dấu Thất bại kèm lý do. Khách thất bại được lưu để đối soát và không mở lại.",
        "images": [
          {
            "src": "tu-van/that-bai.webp",
            "caption": "Ghi nhận lý do thất bại",
            "mobile": false
          },
          {
            "src": "tu-van/khach-that-bai.webp",
            "caption": "Danh sách Khách thất bại với lý do không chốt",
            "mobile": false
          }
        ],
        "steps": [
          "Mở hồ sơ khách, bấm nút **⋯** ở góc phải tiêu đề, chọn **Thất bại**. Trên Kanban, bạn cũng có thể bấm **Sửa giai đoạn** trên thẻ.",
          "Nhập lý do cụ thể, ví dụ \"chưa phù hợp học phí\", rồi bấm **Xác nhận**.",
          "Xem lại các khách đã dừng ở **Danh sách → Thất bại**. Tìm theo lý do, tên hoặc số điện thoại, bấm **Xuất Excel** nếu cần báo cáo."
        ],
        "tips": [
          "Chỉ đánh dấu khi chắc chắn: khách thất bại không mở lại được."
        ]
      },
      {
        "id": "da-nhap-hoc",
        "title": "Theo dõi khách đã nhập học",
        "icon": "school",
        "menu": "Khách hàng (CRM) → Danh sách → Đã nhập học",
        "route": "crm.customers.won",
        "intro": "Màn này tổng hợp khách bạn đã chốt: tổng giá trị hợp đồng, số tiền đã thu được duyệt, công nợ còn lại và lớp của từng khách.",
        "images": [
          {
            "src": "tu-van/da-nhap-hoc.webp",
            "caption": "Khách đã nhập học: số liệu tổng và danh sách khách đã có lớp",
            "mobile": false
          }
        ],
        "steps": [
          "Vào **Khách hàng (CRM) → Danh sách**, bấm nút lọc **Đã nhập học**.",
          "Xem bốn thẻ: **Tổng khách đã chốt**, **Tổng giá trị hợp đồng**, **Thực thu đã duyệt**, **Công nợ còn lại**.",
          "Lọc theo **Lớp học** hoặc **Ngày chốt** rồi bấm **Lọc**. Cột **Tình trạng thu** cho biết khách đã đóng học phí chưa.",
          "Bấm biểu tượng con mắt để mở hồ sơ khách. Bấm **Xuất Excel** để tải danh sách."
        ],
        "tips": [
          "Khách chưa đóng đủ học phí là việc của bạn: liên hệ nhắc phụ huynh và ghi chú lại trong hồ sơ."
        ]
      },
      {
        "id": "kpi",
        "title": "Xem KPI của tôi",
        "icon": "insights",
        "menu": "Của tôi → KPI của tôi",
        "route": "kpi.mine",
        "intro": "KPI của Tư vấn viên tính theo từng kỳ lương, gồm chăm sóc khách hàng (khách trễ hạn liên hệ, báo cáo nộp muộn) và kết quả tuyển sinh (tỷ lệ chốt, phản ánh tư vấn sai).",
        "images": [
          {
            "src": "tu-van/kpi.webp",
            "caption": "KPI của tôi: tỉ lệ đạt và từng tiêu chí",
            "mobile": false
          }
        ],
        "steps": [
          "Bấm **Của tôi** ở menu trái, chọn tab **KPI của tôi**.",
          "Chọn kỳ ở ô **Kỳ lương** góc phải để xem tháng khác.",
          "Ô **Tỉ lệ đạt** là kết quả chung. Mỗi tiêu chí ghi số hiện tại và ngưỡng tính điểm, ví dụ \"≤ 2 case: 100%\".",
          "Ô **Phiếu** cho biết phiếu KPI đang chờ duyệt hay đã chốt."
        ],
        "tips": [
          "Số liệu hệ thống cập nhật hằng ngày. Tiêu chí chấm tay chỉ có số khi người chấm điền cuối kỳ."
        ]
      },
      {
        "id": "de-xuat-viec",
        "title": "Đề xuất việc và gửi Ticket hỗ trợ",
        "icon": "support_agent",
        "menu": "Giao việc → Tạo đầu việc / Danh sách đầu việc; Người dùng → Ticket hỗ trợ",
        "route": "tasks.index",
        "intro": "Cần Admin hoặc Học vụ làm giúp một việc (ví dụ xếp học thử, sửa thông tin khách), bạn đề xuất đầu việc. Gặp lỗi phần mềm hoặc cần hỗ trợ kỹ thuật thì gửi Ticket.",
        "images": [
          {
            "src": "tu-van/de-xuat-viec.webp",
            "caption": "Đề xuất việc cho Admin / Học vụ",
            "mobile": false
          },
          {
            "src": "tu-van/ticket.webp",
            "caption": "Tạo yêu cầu hỗ trợ (Ticket)",
            "mobile": false
          }
        ],
        "steps": [
          "Bấm **Tạo đầu việc** ở menu trái (hoặc **Đề xuất việc cho Admin / Học vụ** trong Danh sách đầu việc).",
          "Nhập **Tiêu đề công việc**, **Mô tả chi tiết**, chọn **Người nhận** và **Hạn hoàn thành**, bấm **Lưu và Giao việc**.",
          "Theo dõi tiến độ ở **Danh sách đầu việc**, tab **Tôi giao**.",
          "Gặp lỗi: vào **Ticket hỗ trợ**, bấm **Tạo Ticket Mới**, nhập tiêu đề, chọn danh mục và mức độ ưu tiên, mô tả lỗi, dán ảnh chụp màn hình (Ctrl + V) rồi bấm **Tạo & Gửi Ticket**."
        ],
        "tips": []
      }
    ]
  },
  {
    "id": "giao-vien",
    "label": "Giáo viên",
    "icon": "cast_for_education",
    "summary": "Giáo viên dùng Cổng Giáo viên để check-in ca dạy, điểm danh, nhận xét, giao và chấm bài, nhập điểm; ngoài ra order đề Big Test, xin duyệt giáo trình, nộp báo cáo tháng và theo dõi giờ dạy, lương, KPI. Giáo viên Full-time và Part-time dùng chung các màn hình này, chỉ khác cách tính lương.",
    "sections": [
      {
        "id": "check-in-ca-day",
        "title": "Check-in ca dạy",
        "icon": "how_to_reg",
        "menu": "Cá nhân → Cổng Giáo viên → Check-in & Điểm danh hôm nay",
        "route": "teacher.home",
        "intro": "Check-in là cách hệ thống ghi nhận công dạy của bạn để tính lương. Đây cũng là trang mở ra ngay sau khi bạn đăng nhập, nên dùng trên điện thoại khi tới lớp là tiện nhất.",
        "images": [
          {
            "src": "giao-vien/checkin.webp",
            "caption": "Tích chọn ca dạy rồi bấm Check-in các ca đã chọn",
            "mobile": true
          }
        ],
        "steps": [
          "Đăng nhập, bạn sẽ thấy ngay khối **Lịch dạy hôm nay** với từng ca dạy trong ngày.",
          "Tích vào ô vuông ở đầu thẻ ca dạy (có thể chọn nhiều ca cùng lúc).",
          "Bấm **Check-in các ca đã chọn**. Thẻ ca sẽ hiện \"Đã check-in\" kèm giờ.",
          "Dòng **Hạn chấm công** dưới mỗi ca cho biết bạn còn bao lâu để check-in."
        ],
        "tips": [
          "Bạn check-in được trong ngày dạy và tối đa 24 giờ sau giờ bắt đầu ca. Quá hạn, thẻ ca báo \"Quá hạn chấm công\", hãy liên hệ Học vụ.",
          "Cuối trang có các thẻ **Lương tạm tính**, **Báo cáo chấm công** và **Vi phạm & Khoản trừ** để bạn xem nhanh tình hình tháng."
        ]
      },
      {
        "id": "diem-danh",
        "title": "Điểm danh học sinh",
        "icon": "fact_check",
        "menu": "Cá nhân → Cổng Giáo viên → Check-in & Điểm danh hôm nay → Điểm danh",
        "route": "teacher.home",
        "intro": "Ghi nhận học sinh đúng giờ, muộn hay nghỉ cho từng buổi. Học sinh nghỉ sẽ tự vào danh sách cần bổ trợ.",
        "images": [
          {
            "src": "giao-vien/diem-danh.webp",
            "caption": "Chọn buổi cần điểm danh",
            "mobile": true
          },
          {
            "src": "giao-vien/diem-danh-luu.webp",
            "caption": "Chọn trạng thái từng học sinh rồi Lưu điểm danh",
            "mobile": true
          }
        ],
        "steps": [
          "Trên thẻ ca dạy, bấm **Điểm danh** (hoặc **Điểm danh ngay** ở khung nhắc ca sắp bắt đầu).",
          "Kiểm tra ô **Buổi điểm danh** đúng ngày, đúng giờ. Muốn điểm danh buổi khác thì chọn lại ở đây.",
          "Với từng học sinh, chọn **Đúng giờ**, **Muộn**, **Nghỉ có phép** hoặc **Nghỉ không phép**. Mặc định tất cả là Đúng giờ.",
          "Học sinh nghỉ (có phép hoặc không phép) thì bắt buộc ghi lý do vào ô ghi chú.",
          "Bấm **Lưu điểm danh**. Bạn có thể sửa và lưu lại nhiều lần trong thời gian cho phép."
        ],
        "tips": [
          "Giáo viên chỉ điểm danh được trong khoảng 24 giờ trước và sau giờ bắt đầu buổi học. Ngoài khoảng này nút lưu bị khóa, hãy nhờ Học vụ điểm danh bù.",
          "Khối **Buổi đã dạy chưa điểm danh (điểm danh bù)** trên trang chủ liệt kê các buổi bạn còn thiếu.",
          "Không điểm danh không làm mất công dạy, công dạy tính theo check-in."
        ]
      },
      {
        "id": "nhan-xet-buoi-hoc",
        "title": "Nhận xét buổi học",
        "icon": "rate_review",
        "menu": "Cá nhân → Cổng Giáo viên → thẻ ca dạy → Nhận xét",
        "route": "teacher.home",
        "intro": "Sau mỗi buổi, bạn nhận xét từng học sinh: điểm Monsters, thực hành ngữ pháp, tinh thần học tập, kết quả và nhận xét chi tiết.",
        "images": [
          {
            "src": "giao-vien/nhan-xet.webp",
            "caption": "Bảng nhận xét từng học sinh của buổi học",
            "mobile": false
          }
        ],
        "steps": [
          "Trên thẻ ca dạy ở trang chủ, bấm **Nhận xét**.",
          "Kiểm tra tên lớp và buổi học ở đầu trang. Muốn nhận xét buổi khác, chọn ở ô **Nhận xét buổi khác** cuối trang.",
          "Điền các cột **Monsters (Nhóm)**, **Monsters (Thưởng)**, **Thực hành ngữ pháp**, **Tinh thần học tập**, **Kết quả** và ô nhận xét chi tiết cho từng học sinh. Học sinh vắng bị khóa, không cần điền.",
          "Chưa xong thì bấm **Lưu nháp**. Xong hết thì bấm **Lưu nhận xét**."
        ],
        "tips": [
          "Bản nháp chưa hiện cho học viên, phụ huynh. Chỉ khi bấm Lưu nhận xét thì nhận xét mới được gửi.",
          "Nên điểm danh trước khi nhận xét để cột Điểm danh hiện đúng trạng thái.",
          "Nộp nhận xét chậm quá 24 giờ có thể bị ghi lỗi vi phạm."
        ]
      },
      {
        "id": "giao-bai-tap",
        "title": "Giao bài tập về nhà",
        "icon": "assignment",
        "menu": "Cá nhân → Cổng Giáo viên → thẻ ca dạy → Giao bài",
        "route": "teacher.home",
        "intro": "Giao bài tập cho cả lớp theo từng buổi học. Học viên thấy bài và nộp trên Cổng học viên.",
        "images": [
          {
            "src": "giao-vien/giao-bai.webp",
            "caption": "Chọn buổi, hạn nộp, hạng mục bài tập rồi Lưu bài tập",
            "mobile": false
          }
        ],
        "steps": [
          "Trên thẻ ca dạy, bấm **Giao bài**.",
          "Chọn **Buổi học** và **Hạn nộp**. Có thể thêm ghi chú nhắc cả lớp và tài liệu tham khảo (link YouTube, file nghe, link Quizizz).",
          "Ở **Hạng mục bài tập**, tích ít nhất một loại (**Quay video**, **Viết từ vựng**, **Workbook**, **Sách bổ trợ**, **Quiz**, **Sách bộ giáo dục**) và ghi yêu cầu chi tiết cho từng loại.",
          "Bấm **Lưu bài tập**. Bài vừa giao hiện ở danh sách **Bài tập đã giao** bên dưới.",
          "Muốn sửa hoặc xóa, bấm biểu tượng bút chì hoặc thùng rác ở bài đó."
        ],
        "tips": [
          "Hạng mục đã có học sinh nộp bài thì không bỏ được nữa (có biểu tượng ổ khóa)."
        ]
      },
      {
        "id": "cham-bai-nhap-diem",
        "title": "Chấm bài nộp và nhập điểm mini test",
        "icon": "grading",
        "menu": "Cá nhân → Cổng Giáo viên → Chấm bài nộp của lớp; thẻ ca dạy → Nhập điểm",
        "route": "portal.teacher.submissions",
        "intro": "Xem bài học viên đã nộp, cho điểm, nhận xét; và nhập điểm 4 kỹ năng của bài mini test cuối mỗi Unit.",
        "images": [
          {
            "src": "giao-vien/cham-bai.webp",
            "caption": "Chọn lớp, chọn loại bài rồi chấm từng bài nộp",
            "mobile": false
          },
          {
            "src": "giao-vien/nhap-diem.webp",
            "caption": "Nhập điểm mini test theo Unit và học sinh",
            "mobile": false
          }
        ],
        "steps": [
          "Chấm bài nộp: mở tab **Chấm bài nộp của lớp**, chọn lớp ở ô **Chọn lớp dạy**, rồi bấm loại bài (**Quay video**, **Viết từ vựng**, **Workbook**, **Phát âm**...).",
          "Mở tệp học viên nộp, nhập điểm (nếu có) và nhận xét, rồi bấm **Đánh dấu đã xem**. Với bài **Phát âm**, nghe bản ghi, nhập điểm trên thang 100 rồi bấm **Chấm**.",
          "Nhập điểm mini test: trên thẻ ca dạy, bấm **Nhập điểm**, chọn **Chọn Unit** và **Chọn học sinh**.",
          "Nhập điểm **Nghe**, **Nói**, **Đọc**, **Viết** (kiểm tra **Thang điểm tối đa**), thêm nhận xét nếu cần, rồi bấm **Lưu điểm**.",
          "Bảng **Điểm đã nhập** bên dưới cho biết học sinh nào còn thiếu. Bấm **Nhập** hoặc **Sửa** ở từng dòng để làm tiếp."
        ],
        "tips": [
          "Học sinh có điểm tổng mini test dưới 7/10 tự vào danh sách bổ trợ và hiện ở khối **Học sinh cần chú ý** trên trang chủ."
        ]
      },
      {
        "id": "khach-hoc-thu",
        "title": "Nhận xét khách học thử",
        "icon": "school",
        "menu": "Cá nhân → Cổng Giáo viên → Khách học thử",
        "route": "teacher.trial-guests",
        "intro": "Xem khách học thử được xếp vào buổi dạy của bạn và nhận xét sau buổi học. Nhận xét được lưu vào hồ sơ khách để Học vụ và tư vấn viên theo dõi.",
        "images": [
          {
            "src": "giao-vien/khach-hoc-thu.webp",
            "caption": "Tab Đã diễn ra: chọn trạng thái, điểm và nhận xét",
            "mobile": false
          }
        ],
        "steps": [
          "Mở tab **Khách học thử**. Tab **Hôm nay & sắp tới** cho biết ai sẽ học thử, kết quả test và ghi chú của Học vụ.",
          "Sau buổi học, chuyển sang tab **Đã diễn ra**.",
          "Chọn **Có mặt (đã học thử)** hoặc **Vắng mặt**, chọn điểm từ 1/5 đến 5/5.",
          "Điền **Thực hành ngữ pháp**, **Tinh thần học tập**, **Kết quả** và nhận xét chi tiết, rồi bấm **Lưu nhận xét**."
        ],
        "tips": [
          "Ô nhận xét chỉ mở từ ngày học thử. Trước đó dòng khách hiện \"Nhận xét được mở từ ngày học thử\".",
          "Khách học thử cũng hiện trên thẻ ca dạy ở trang chủ (dòng \"+N khách học thử\")."
        ]
      },
      {
        "id": "giao-trinh-order-test",
        "title": "Chặng đang dạy, order đề test và xem bài giảng",
        "icon": "menu_book",
        "menu": "Đào tạo → Giáo trình → Chặng đang dạy & Order Test / Xem bài giảng",
        "route": "syllabus.teaching-stages",
        "intro": "Theo dõi chặng học từng lớp đang dạy, đặt ngày dự kiến Big Test, gửi yêu cầu cấp đề cho Ban Học thuật và mở tài liệu, nội dung từng buổi học.",
        "images": [
          {
            "src": "giao-vien/chang-dang-day.webp",
            "caption": "Mỗi lớp một thẻ: chặng, trạng thái đề, ngày dự kiến Big Test",
            "mobile": false
          },
          {
            "src": "giao-vien/order-test.webp",
            "caption": "Gửi yêu cầu cấp đề Big Test hoặc Mini Test",
            "mobile": false
          },
          {
            "src": "giao-vien/xem-bai-giang.webp",
            "caption": "Xem bài giảng: tài liệu và nội dung từng buổi",
            "mobile": false
          }
        ],
        "steps": [
          "Vào **Giáo trình**, tab **Chặng đang dạy & Order Test**. Mỗi thẻ cho biết chặng đang học, ngày bắt đầu và trạng thái đề (ví dụ \"Đã order - Chờ HT duyệt\", \"Đã có đề\").",
          "Chọn **Ngày dự kiến Big Test** rồi bấm **Lưu** (hoặc **Cập nhật** nếu đổi ngày).",
          "Bấm **Order đề Big Test** (hoặc **Chi tiết** nếu đã order) để mở trang order. Chọn **Loại đề** (Big Test cuối chặng hoặc Mini Test), ngày thi dự kiến, ghi chú cho Học thuật, rồi bấm **Gửi yêu cầu tới Ban Học thuật**.",
          "Bảng **Yêu cầu đã gửi cho lớp này** cho biết yêu cầu đang chờ duyệt hay đã được duyệt và phân phối đề.",
          "Mở tab **Xem bài giảng** để xem tài liệu. Tab **Nội dung buổi học** cho biết chặng đang học, tiến độ và buổi tiếp theo; bấm biểu tượng mở ở từng buổi để xem chi tiết."
        ],
        "tips": [
          "Hạn xử lý của Học thuật là ngày thi trừ 3 ngày, nên order sớm.",
          "Một số tài liệu không cho tải về để bảo mật. Bạn vẫn xem trực tuyến được."
        ]
      },
      {
        "id": "xin-duyet-giao-trinh",
        "title": "Đề xuất sửa giáo trình, xin điều chỉnh tiến độ",
        "icon": "outgoing_mail",
        "menu": "Phê duyệt → Xin duyệt → Đề xuất sửa giáo trình / Xin điều chỉnh tiến độ",
        "route": "syllabus.teacher-propose",
        "intro": "Gửi Ban Học thuật đề xuất sửa lỗi hoặc nội dung giáo trình, hoặc xin thêm buổi khi lớp cần thêm thời gian cho một chặng.",
        "images": [
          {
            "src": "giao-vien/de-xuat-giao-trinh.webp",
            "caption": "Gửi đề xuất sửa giáo trình",
            "mobile": false
          },
          {
            "src": "giao-vien/xin-dieu-chinh-tien-do.webp",
            "caption": "Gửi yêu cầu điều chỉnh tiến độ",
            "mobile": false
          }
        ],
        "steps": [
          "Vào **Xin duyệt**, tab **Đề xuất sửa giáo trình**, bấm **Gửi đề xuất**.",
          "Chọn giáo trình, chọn buổi học (bỏ trống nếu áp dụng chung), mô tả thay đổi. Mở **Thông tin bổ sung (tùy chọn)** nếu muốn ghi loại đề xuất, nội dung hiện tại, lý do hoặc đính kèm file. Bấm **Gửi đề xuất**.",
          "Muốn xin thêm buổi: mở tab **Xin điều chỉnh tiến độ**, bấm **Gửi yêu cầu**.",
          "Chọn **Lớp học / Chặng học**, ghi lý do, chọn **Số buổi cần thêm** (1 hoặc 2 buổi), rồi bấm **Gửi yêu cầu**.",
          "Theo dõi kết quả ở bảng **Lịch sử đề xuất** hoặc **Danh sách yêu cầu đã gửi** (Chờ duyệt, Đã duyệt, Đã từ chối)."
        ],
        "tips": [
          "Khi yêu cầu điều chỉnh tiến độ được duyệt, hệ thống tự thêm đúng số buổi đó vào cuối lịch học của lớp.",
          "Yêu cầu điều chỉnh tiến độ được Ban Học thuật xem xét và phản hồi trong vòng 3 ngày."
        ]
      },
      {
        "id": "big-test",
        "title": "Nhập điểm Big Test",
        "icon": "assignment",
        "menu": "Đào tạo → Big Test",
        "route": "syllabus.big-tests.results",
        "intro": "Nhập điểm 4 kỹ năng, nhận xét và link video bài thi Big Test cho lớp bạn dạy, rồi gửi Học thuật duyệt trước khi gửi phụ huynh.",
        "images": [
          {
            "src": "giao-vien/big-test.webp",
            "caption": "Chọn kỳ thi rồi nhập điểm từng học viên",
            "mobile": false
          }
        ],
        "steps": [
          "Vào **Big Test**, chọn kỳ thi ở ô **Chọn Kỳ Thi Big Test**.",
          "Với mỗi học viên, nhập điểm **Listening**, **Reading**, **Writing**, **Speaking**. Cột **Overall** tự tính.",
          "Ghi nhận xét tiến độ và link video bài thi ở cột **Nhận xét & Video**. Học viên vắng thì tích **Vắng thi** và không cần nhập điểm.",
          "Bấm **Lưu nháp** nếu chưa xong (Học thuật chưa thấy). Xong thì bấm **Gửi duyệt**.",
          "Theo dõi cột **Trạng thái** và **Đã gửi PH** để biết bài nào đã được duyệt, đã gửi phụ huynh. Bấm **In bảng điểm** khi cần bản in."
        ],
        "tips": [
          "Gửi duyệt cần đủ 4 kỹ năng. Dòng để trống sẽ được bỏ qua.",
          "Điểm đã duyệt hoặc đã gửi phụ huynh thì không sửa được nữa."
        ]
      },
      {
        "id": "bao-cao-thang",
        "title": "Nộp báo cáo tháng",
        "icon": "summarize",
        "menu": "Báo cáo → Báo cáo của tôi → Báo cáo định kỳ",
        "route": "reports.periodic.teacher-monthly",
        "intro": "Mỗi tháng bạn nộp một báo cáo về tiến độ giảng dạy, khó khăn, đề xuất và tình hình từng lớp. Hạn nộp là Chủ nhật cuối tháng, hệ thống nhắc trước 3 ngày, 1 ngày và đúng ngày hạn.",
        "images": [
          {
            "src": "giao-vien/bao-cao-thang.webp",
            "caption": "Báo cáo tháng theo lớp",
            "mobile": false
          }
        ],
        "steps": [
          "Vào **Báo cáo của tôi**, tab **Báo cáo định kỳ**, chọn **Báo cáo tháng theo lớp**.",
          "Kiểm tra tháng báo cáo ở ô chọn tháng (bấm **Lọc** nếu đổi tháng).",
          "Điền **Báo cáo chung**: Tiến độ giảng dạy, Khó khăn, Đề xuất.",
          "Ở **Tình hình từng lớp**, điền Tình hình lớp, Học sinh cần chú ý, Giải pháp. Tích **Lớp cần hỗ trợ** và ghi rõ cần hỗ trợ gì nếu có. Số liệu chuyên cần, bài tập, điểm trung bình đã có sẵn ở đầu mỗi lớp.",
          "Bấm **Lưu báo cáo tháng** ở cuối trang. Tháng đã nộp hiện ở khung **Các tháng đã nộp**, bấm vào để xem lại hoặc sửa."
        ],
        "tips": [
          "Nếu chỉ muốn nộp nhanh một báo cáo dạng văn bản, chọn **Báo cáo tháng** rồi bấm **Nộp báo cáo tháng**.",
          "Tab **Báo cáo giảng dạy** tổng hợp giờ dạy, số buổi đã điểm danh, số điểm mini test đã nhập và các lớp bạn phụ trách trong tháng."
        ]
      },
      {
        "id": "gio-day",
        "title": "Xem giờ dạy của mình",
        "icon": "schedule",
        "menu": "Người dùng → Chấm công",
        "route": "payroll.timesheets.teachers",
        "intro": "Xem các ca dạy đã được ghi nhận trong kỳ lương, ca nào hợp lệ để tính lương, ca nào bị từ chối và buổi nào bạn còn thiếu chấm công.",
        "images": [
          {
            "src": "giao-vien/gio-day.webp",
            "caption": "Chấm công của tôi trong kỳ lương",
            "mobile": false
          }
        ],
        "steps": [
          "Vào **Chấm công**. Trang **Chấm công của tôi** mở kỳ lương hiện tại.",
          "Xem nhanh các ô **Chờ đối soát**, **Hợp lệ (tính lương)**, **Từ chối** và **Số lần thiếu chấm công trong tháng**.",
          "Bảng **Ca dạy trong kỳ** liệt kê từng ca: giờ vào, giờ ra, số giờ, tình trạng và người duyệt. Ca bị từ chối có ghi lý do.",
          "Muốn xem kỳ khác hoặc lọc theo trạng thái, loại ca, chọn ở thanh lọc rồi bấm **Lọc**."
        ],
        "tips": [
          "Quên check-in hoặc ca bị thiếu: báo Học vụ để được chấm công bù trước khi kỳ lương chốt.",
          "Trên điện thoại, nút **Bảng công** ở thanh dưới của Cổng Giáo viên mở nhanh báo cáo giờ dạy tháng."
        ]
      },
      {
        "id": "luong-kpi-vi-pham",
        "title": "Lương, KPI và vi phạm của tôi",
        "icon": "payments",
        "menu": "Cá nhân → Của tôi → Lương của tôi / KPI của tôi; Người dùng → Lương & Phạt",
        "route": "portal.my-salary",
        "intro": "Xem phiếu lương từng kỳ, điểm KPI tháng và các biên bản vi phạm của bạn. Full-time có lương cơ bản; Part-time tính theo số buổi dạy hợp lệ nhân đơn giá buổi.",
        "images": [
          {
            "src": "giao-vien/luong.webp",
            "caption": "Lương của tôi: thu nhập, khoản trừ, chi tiết buổi dạy",
            "mobile": false
          },
          {
            "src": "giao-vien/kpi.webp",
            "caption": "KPI của tôi theo từng tiêu chí",
            "mobile": false
          },
          {
            "src": "giao-vien/vi-pham.webp",
            "caption": "Danh sách vi phạm: bấm Giải trình khi được yêu cầu",
            "mobile": false
          }
        ],
        "steps": [
          "Vào **Của tôi → Lương của tôi**, chọn kỳ lương ở ô tháng để xem tổng thu nhập, khoản trừ, thực nhận và chi tiết từng buổi dạy.",
          "Bấm **Tải phiếu lương** để lưu phiếu về máy.",
          "Mở tab **KPI của tôi**, chọn kỳ lương để xem tỉ lệ đạt, xếp loại tháng và từng tiêu chí. Bấm dòng **Xem ... bản ghi** dưới tiêu chí để biết số liệu đến từ đâu.",
          "Vào **Lương & Phạt** để xem các biên bản vi phạm. Biên bản đang chờ bạn giải trình có nút **Giải trình**: bấm, nhập nội dung rồi bấm **Gửi giải trình**.",
          "Bấm nút **⋯** ở mỗi dòng để xem chi tiết biên bản, bằng chứng và kết luận."
        ],
        "tips": [
          "Thắc mắc về số buổi, KPI hoặc khoản trừ: phản hồi phòng Kế toán trước ngày 03 hằng tháng.",
          "Phạt đã chốt cần nộp trong 2 ngày. Quá hạn sẽ trừ vào lương."
        ]
      }
    ]
  },
  {
    "id": "tro-giang",
    "label": "Trợ giảng",
    "icon": "diversity_3",
    "summary": "Trợ giảng làm và đánh dấu xong các nhiệm vụ trực lớp mỗi ngày, nộp báo cáo trực lớp, check-in ca trợ giảng, điểm danh, chấm bài nộp, nộp báo cáo tháng và theo dõi giờ công, lương, KPI. Phần lớn việc hằng ngày làm trên điện thoại.",
    "sections": [
      {
        "id": "nhiem-vu-hom-nay",
        "title": "Nhiệm vụ hôm nay",
        "icon": "assignment",
        "menu": "Cá nhân → Cổng Giáo viên → Nhiệm vụ hôm nay của TA",
        "route": "portal.ta-tasks",
        "intro": "Trang mở ra ngay sau khi bạn đăng nhập. Nhiệm vụ trong ngày được chia theo ca Trước giờ học, Trong giờ học và Sau giờ học, mỗi nhiệm vụ có hạn và trạng thái riêng.",
        "images": [
          {
            "src": "tro-giang/nhiem-vu.webp",
            "caption": "Nhiệm vụ chia theo ca, bấm Hoàn thành khi làm xong",
            "mobile": true
          },
          {
            "src": "tro-giang/hoan-thanh.webp",
            "caption": "Tải ảnh bằng chứng rồi bấm Xác nhận",
            "mobile": true
          }
        ],
        "steps": [
          "Mở trang **Nhiệm vụ hằng ngày**. Bấm tên từng ca (**Trước giờ học**, **Trong giờ học**, **Sau giờ học**) để mở hoặc thu gọn danh sách.",
          "Làm xong một nhiệm vụ, bấm **Hoàn thành** trên thẻ nhiệm vụ đó (nhiệm vụ trễ hạn có nút **Hoàn thành gấp**).",
          "Trong hộp **Cập nhật tiến độ**, bấm **Nhấn để tải ảnh lên** để chụp hoặc chọn ảnh bằng chứng, thêm ghi chú nếu cần.",
          "Bấm **Xác nhận**. Có ảnh thì nhiệm vụ hoàn thành ngay; không có ảnh thì chuyển sang chờ Admin xác nhận.",
          "Muốn xem ngày khác, chọn ở ô **Ngày**; bấm **Hôm nay** để quay lại."
        ],
        "tips": [
          "Nhãn trên thẻ cho biết trạng thái: Mới, Đang thực hiện, Chờ xác nhận, Bị chặn (kèm lý do), Quá hạn, Hoàn thành.",
          "Khung vàng đầu trang nhắc bạn còn nhiệm vụ của các ngày trước chưa xong.",
          "Nút **Lớp học** ở thanh dưới cùng đưa bạn tới danh sách lớp trực trong ngày (giờ học, phòng, cơ sở)."
        ]
      },
      {
        "id": "bao-cao-truc-lop",
        "title": "Nộp báo cáo trực lớp",
        "icon": "rate_review",
        "menu": "Nhiệm vụ hôm nay của TA → Nộp báo cáo trực lớp (hoặc nút Báo cáo ở thanh dưới)",
        "route": "portal.ta-tasks",
        "intro": "Sau buổi trực lớp, bạn ghi lại nội dung bài học, nhật ký lớp và học sinh cần bổ trợ. Báo cáo có ảnh sẽ tự đánh dấu xong nhiệm vụ trực lớp.",
        "images": [
          {
            "src": "tro-giang/bao-cao-truc-lop.webp",
            "caption": "Form Nộp báo cáo trực lớp",
            "mobile": true
          }
        ],
        "steps": [
          "Trên thẻ nhiệm vụ có dòng \"Trực lớp\", bấm **Nộp báo cáo trực lớp**. Hoặc bấm **Báo cáo** ở thanh dưới cùng rồi chọn **Lớp học**.",
          "Kiểm tra lớp và buổi học. Nếu buổi không có trong danh sách, gõ tên buổi vào ô bên dưới.",
          "Điền **Hôm nay học gì** (bắt buộc) và **Nhật ký dạy** nếu có điều cần lưu ý.",
          "Bấm **Thêm ảnh** để đính kèm ảnh bảng hoặc lớp học (tối đa 10 ảnh).",
          "Có học sinh cần kèm thêm thì bấm **Thêm học sinh cần bổ trợ**, ghi lý do và kế hoạch xử lý. Cuối cùng bấm **Nộp báo cáo**."
        ],
        "tips": [
          "Ảnh không bắt buộc. Có ít nhất 1 ảnh, nhiệm vụ trực lớp tự hoàn thành; không có ảnh, nhiệm vụ chờ Admin xác nhận."
        ]
      },
      {
        "id": "check-in-ca-tro-giang",
        "title": "Check-in ca trợ giảng",
        "icon": "how_to_reg",
        "menu": "Cá nhân → Cổng Giáo viên → Check-in & Điểm danh hôm nay",
        "route": "teacher.home",
        "intro": "Check-in là cách hệ thống ghi nhận công trợ giảng để tính lương. Bạn check-in trên điện thoại khi tới lớp.",
        "images": [
          {
            "src": "tro-giang/checkin.webp",
            "caption": "Tích chọn ca rồi bấm Check-in các ca đã chọn",
            "mobile": true
          }
        ],
        "steps": [
          "Mở tab **Check-in & Điểm danh hôm nay**. Khối **Lịch dạy hôm nay** hiện các ca bạn trực trong ngày.",
          "Tích vào ô vuông ở đầu thẻ ca (có thể chọn nhiều ca).",
          "Bấm **Check-in các ca đã chọn**. Thẻ ca sẽ hiện \"Đã check-in\" kèm giờ.",
          "Theo dõi dòng **Hạn chấm công** dưới mỗi ca để không bị quá hạn."
        ],
        "tips": [
          "Bạn check-in được trong ngày học và tối đa 24 giờ sau giờ bắt đầu ca. Quá hạn, hãy liên hệ Học vụ.",
          "Đi muộn ca trợ giảng được tính vào KPI."
        ]
      },
      {
        "id": "diem-danh",
        "title": "Điểm danh học sinh",
        "icon": "fact_check",
        "menu": "Cá nhân → Cổng Giáo viên → Check-in & Điểm danh hôm nay → Điểm danh",
        "route": "teacher.home",
        "intro": "Trợ giảng điểm danh cho lớp mình trực giống như giáo viên. Người lưu điểm danh được ghi nhận theo tài khoản đang đăng nhập.",
        "images": [
          {
            "src": "tro-giang/diem-danh.webp",
            "caption": "Chọn trạng thái từng học sinh rồi Lưu điểm danh",
            "mobile": true
          }
        ],
        "steps": [
          "Trên thẻ ca ở trang chủ, bấm **Điểm danh** (hoặc **Điểm danh ngay** ở khung nhắc ca sắp bắt đầu).",
          "Kiểm tra ô **Buổi điểm danh** đúng ngày, đúng giờ.",
          "Chọn trạng thái cho từng học sinh: **Đúng giờ**, **Muộn**, **Nghỉ có phép**, **Nghỉ không phép**. Học sinh nghỉ thì bắt buộc ghi lý do.",
          "Bấm **Lưu điểm danh**. Bạn có thể sửa và lưu lại trong thời gian cho phép."
        ],
        "tips": [
          "Chỉ điểm danh được trong khoảng 24 giờ trước và sau giờ bắt đầu buổi học. Ngoài khoảng này, nhờ Học vụ điểm danh bù.",
          "Điểm danh và nhận xét buổi học đúng hạn là một tiêu chí KPI của trợ giảng."
        ]
      },
      {
        "id": "cham-bai",
        "title": "Chấm bài nộp của lớp",
        "icon": "grading",
        "menu": "Cá nhân → Cổng Giáo viên → Chấm bài nộp của lớp",
        "route": "portal.teacher.submissions",
        "intro": "Xem bài tập học viên lớp bạn trực đã nộp, cho điểm và nhận xét.",
        "images": [
          {
            "src": "tro-giang/cham-bai.webp",
            "caption": "Chọn lớp, chọn loại bài rồi chấm từng bài nộp",
            "mobile": false
          }
        ],
        "steps": [
          "Mở tab **Chấm bài nộp của lớp**, chọn lớp ở ô **Chọn lớp dạy**.",
          "Bấm loại bài cần chấm: **Quay video**, **Viết từ vựng**, **Workbook**, **Sách bổ trợ**, **Sách bộ giáo dục**, **Quiz** hoặc **Phát âm**.",
          "Mở tệp học viên nộp, nhập điểm (nếu có) và nhận xét, rồi bấm **Đánh dấu đã xem**.",
          "Với bài **Phát âm**, nghe bản ghi, nhập điểm trên thang 100 và nhận xét, rồi bấm **Chấm**."
        ],
        "tips": [
          "Nhắc và thu bài tập về nhà là một tiêu chí KPI của trợ giảng."
        ]
      },
      {
        "id": "gio-cong",
        "title": "Xem giờ công trợ giảng",
        "icon": "schedule",
        "menu": "Người dùng → Chấm công",
        "route": "payroll.timesheets.teachers",
        "intro": "Xem các ca đã được ghi nhận trong kỳ lương, ca nào hợp lệ, ca nào bị từ chối và buổi nào bạn còn thiếu chấm công.",
        "images": [
          {
            "src": "tro-giang/gio-day.webp",
            "caption": "Chấm công của tôi: ca trong kỳ và buổi thiếu chấm công",
            "mobile": false
          }
        ],
        "steps": [
          "Vào **Chấm công**. Trang **Chấm công của tôi** mở kỳ lương hiện tại.",
          "Xem các ô **Chờ đối soát**, **Hợp lệ (tính lương)**, **Từ chối** và **Số lần thiếu chấm công trong tháng**.",
          "Bảng **Ca dạy trong kỳ** liệt kê từng ca kèm giờ vào, giờ ra, số giờ và lý do nếu bị từ chối.",
          "Kéo xuống bảng **Buổi thiếu chấm công** để biết buổi nào bạn được phân công mà chưa check-in."
        ],
        "tips": [
          "Buổi thiếu chấm công: báo Học vụ để được chấm công bù trước khi kỳ lương chốt."
        ]
      },
      {
        "id": "bao-cao-thang",
        "title": "Nộp báo cáo tháng",
        "icon": "summarize",
        "menu": "Báo cáo → Báo cáo của tôi → Báo cáo định kỳ",
        "route": "reports.my",
        "intro": "Mỗi tháng bạn nộp một báo cáo tổng kết công việc. Hạn nộp là Chủ nhật cuối tháng, hệ thống nhắc trước 3 ngày, 1 ngày và đúng ngày hạn.",
        "images": [
          {
            "src": "tro-giang/bao-cao-thang.webp",
            "caption": "Nộp báo cáo tháng mới",
            "mobile": false
          }
        ],
        "steps": [
          "Vào **Báo cáo của tôi**, tab **Báo cáo định kỳ**. Khung vàng cho biết hạn nộp và tháng này bạn đã nộp chưa.",
          "Bấm **Nộp báo cáo tháng**.",
          "Nhập **Tiêu đề**, kiểm tra **Ngày báo cáo**, rồi ghi **Nội dung**: kết quả thực hiện, tồn đọng, kế hoạch.",
          "Bấm **Nộp báo cáo**. Báo cáo đã nộp hiện ở **Lịch sử đã nộp**."
        ],
        "tips": []
      },
      {
        "id": "luong",
        "title": "Lương của tôi",
        "icon": "payments",
        "menu": "Cá nhân → Của tôi → Lương của tôi",
        "route": "portal.my-salary",
        "intro": "Xem phiếu lương từng kỳ: số buổi hợp lệ, đơn giá buổi, các khoản cộng, khoản trừ và số thực nhận.",
        "images": [
          {
            "src": "tro-giang/luong.webp",
            "caption": "Lương của tôi",
            "mobile": false
          }
        ],
        "steps": [
          "Vào **Của tôi → Lương của tôi**.",
          "Chọn kỳ lương ở ô tháng góc trên bên phải.",
          "Xem **Tổng thu nhập**, **Tổng khoản trừ**, **Thực nhận**, rồi kéo xuống **Chi tiết thu nhập**, **Các khoản trừ** và **Chi tiết buổi dạy**.",
          "Bấm **Tải phiếu lương** để lưu phiếu về máy."
        ],
        "tips": [
          "Thắc mắc về số buổi, KPI hoặc khoản trừ: phản hồi phòng Kế toán trước ngày 03 hằng tháng.",
          "Biên bản vi phạm của bạn nằm ở **Người dùng → Lương & Phạt**. Biên bản đang chờ bạn giải trình có nút **Giải trình**."
        ]
      },
      {
        "id": "kpi",
        "title": "KPI của tôi",
        "icon": "insights",
        "menu": "Cá nhân → Của tôi → KPI của tôi",
        "route": "kpi.mine",
        "intro": "Xem tỉ lệ đạt KPI trong kỳ và từng tiêu chí: đi muộn ca trợ giảng, điểm danh và nhận xét đúng hạn, chuyên cần của lớp, nhắc thu bài tập, phản ánh từ giáo viên chính và phụ huynh.",
        "images": [
          {
            "src": "tro-giang/kpi.webp",
            "caption": "KPI của tôi theo từng tiêu chí",
            "mobile": false
          }
        ],
        "steps": [
          "Vào **Của tôi**, mở tab **KPI của tôi**.",
          "Chọn kỳ lương ở ô góc trên bên phải.",
          "Xem **Tỉ lệ đạt** và trạng thái phiếu, rồi xem từng nhóm tiêu chí bên dưới.",
          "Bấm dòng **Xem ... bản ghi** dưới tiêu chí để biết số liệu đến từ đâu."
        ],
        "tips": [
          "Số liệu hệ thống cập nhật hằng ngày. Tiêu chí chấm tay chỉ có số khi người chấm điền cuối kỳ."
        ]
      }
    ]
  },
  {
    "id": "hoc-vien",
    "label": "Học viên & Phụ huynh",
    "icon": "family_restroom",
    "summary": "Cổng Phụ huynh & Học sinh giúp học viên và phụ huynh xem lịch học, tiến độ, nộp bài tập, luyện phát âm, đọc thông báo, góp ý và báo đóng học phí ngay trên điện thoại. Phụ huynh đăng nhập bằng tài khoản của con.",
    "sections": [
      {
        "id": "dang-nhap",
        "title": "Đăng nhập bằng mã học viên",
        "icon": "login",
        "menu": "Màn hình đăng nhập",
        "intro": "Trung tâm cấp cho mỗi học viên một mã học viên và mật khẩu. Học viên và phụ huynh dùng chung tài khoản này.",
        "images": [
          {
            "src": "hoc-vien/dang-nhap.webp",
            "caption": "Màn hình đăng nhập trên điện thoại",
            "mobile": true
          }
        ],
        "steps": [
          "Mở trình duyệt trên điện thoại, vào địa chỉ portal trung tâm gửi.",
          "Ở ô **Mã nhân viên hoặc email**, học viên nhập mã học viên (ví dụ HV-...) hoặc email đã đăng ký.",
          "Nhập **Mật khẩu**, tích **Ghi nhớ đăng nhập** nếu dùng máy riêng, rồi bấm **Đăng nhập**.",
          "Lần đầu đăng nhập, nếu được yêu cầu đổi mật khẩu, hãy đặt mật khẩu mới trước khi dùng tiếp."
        ],
        "tips": [
          "Quên mật khẩu: liên hệ trung tâm (Học vụ) để được đặt lại.",
          "Mã học viên xem lại được ở mục Thông tin tài khoản (bấm biểu tượng người ở góc trên trang Trang chủ)."
        ]
      },
      {
        "id": "trang-chu",
        "title": "Xem lịch học và tiến độ",
        "icon": "home",
        "menu": "Thanh dưới → Trang chủ",
        "route": "portal.student.home",
        "intro": "Trang chủ cho biết các buổi học sắp tới, tiến độ học tập, lịch sử điểm danh, kết quả Big Test và thông tin học phí của học viên.",
        "images": [
          {
            "src": "hoc-vien/trang-chu.webp",
            "caption": "Trang chủ: Lịch học sắp tới",
            "mobile": true
          },
          {
            "src": "hoc-vien/tien-do.webp",
            "caption": "Tiến độ học tập và lịch sử điểm danh",
            "mobile": true
          }
        ],
        "steps": [
          "Sau khi đăng nhập, bạn vào thẳng **Trang chủ**. Thanh dưới màn hình có 5 mục: **Trang chủ**, **Học tập**, **Phát âm**, **Khảo sát**, **Thông báo**.",
          "Khối **Lịch học sắp tới** liệt kê từng buổi: ngày, giờ, lớp, phòng. Nhãn **Buổi học**, **Phụ đạo** hoặc **Nghỉ** cho biết loại buổi. Buổi nghỉ bị gạch ngang.",
          "Kéo xuống khối **Tiến độ học tập đã duyệt** để xem **Chuyên cần**, số **Bài tập** đã nộp và điểm **Big Test mới nhất**.",
          "Khối **Lịch sử điểm danh** cho biết từng buổi học viên **Có mặt**, **Đi muộn** hay **Vắng**. Khối **Kết quả Big Test** có điểm từng kỹ năng và nhận xét của giáo viên."
        ],
        "tips": [
          "Phụ huynh có nhiều con học tại trung tâm: chọn tên con ở ô chọn học viên trên đầu trang để xem từng bé.",
          "Menu **☰** góc trên bên trái mở lại các mục của cổng và trang **Hướng dẫn sử dụng** này."
        ]
      },
      {
        "id": "nop-bai-tap",
        "title": "Học tập và nộp bài tập",
        "icon": "upload_file",
        "menu": "Thanh dưới → Học tập",
        "route": "portal.student.homework",
        "intro": "Bài tập về nhà của lớp hiện ở đây, chia thành từng hạng mục: quay video, viết từ vựng, workbook, sách bổ trợ, quiz. Bạn chụp ảnh hoặc quay video bài làm rồi nộp ngay trên điện thoại.",
        "images": [
          {
            "src": "hoc-vien/bai-tap.webp",
            "caption": "Bài tập về nhà và các hạng mục cần nộp",
            "mobile": true
          },
          {
            "src": "hoc-vien/nop-bai.webp",
            "caption": "Hộp thoại Nộp bài tập",
            "mobile": true
          }
        ],
        "steps": [
          "Bấm **Học tập** ở thanh dưới. Khối **Bài tập về nhà** hiện tên bài, lớp và **Hạn nộp**.",
          "Ở hạng mục cần nộp (nhãn **Chưa nộp**), bấm nút tải lên, ví dụ **Tải lên video** hoặc **Tải lên bài viết**.",
          "Bấm vào khung **Kéo thả hoặc bấm để chọn tệp tải lên**, chọn video, ảnh chụp hoặc file PDF (tối đa 50MB). Có thể ghi lời nhắn ở ô **Ghi chú gửi thầy cô**.",
          "Bấm **Xác nhận nộp bài**. Hạng mục chuyển sang **Chờ giáo viên chấm**.",
          "Nộp nhầm: bấm **Sửa / Nộp lại** để thay tệp. Với hạng mục quiz, bấm **Mở link Quiz trực tuyến** để làm bài rồi nộp kết quả."
        ],
        "tips": [
          "Kéo xuống dưới để đọc **Nhận xét buổi học** của giáo viên và xem **Bảng điểm**."
        ]
      },
      {
        "id": "luyen-phat-am",
        "title": "Luyện phát âm",
        "icon": "mic",
        "menu": "Thanh dưới → Phát âm",
        "route": "portal.student.pronunciation",
        "intro": "Học viên nghe file mẫu giáo viên gửi, thu âm giọng đọc của mình và nộp để giáo viên chấm điểm, nhận xét.",
        "images": [
          {
            "src": "hoc-vien/phat-am.webp",
            "caption": "Đặt tên bài, chạm micro để thu âm, rồi Nộp bài ghi âm",
            "mobile": true
          }
        ],
        "steps": [
          "Bấm **Phát âm** ở thanh dưới. Nếu lớp có file nghe, khối **Bài nghe của lớp** hiện danh sách: nghe mẫu rồi bấm **Chọn** để luyện bài đó.",
          "Nếu chưa có file nghe, tự gõ tên bài vào ô **Bài đang luyện**, ví dụ \"Unit 1 - Greetings\".",
          "Chạm nút micro để bắt đầu ghi âm, cho phép trình duyệt dùng micro. Đọc xong, chạm lại để dừng.",
          "Bấm **Nghe lại** để kiểm tra. Chưa ưng thì bấm **Xóa bản ghi** và thu lại.",
          "Bấm **Nộp bài ghi âm**. Bài nộp hiện ở **Lịch sử của bạn**, kèm điểm khi giáo viên chấm xong."
        ],
        "tips": [
          "Nên thu âm ở chỗ yên tĩnh, để điện thoại cách miệng khoảng một gang tay."
        ]
      },
      {
        "id": "thong-bao",
        "title": "Đọc thông báo của trung tâm",
        "icon": "notifications",
        "menu": "Thanh dưới → Thông báo",
        "route": "portal.student.notifications",
        "intro": "Hộp thư thông báo nhận tin từ trung tâm như xác nhận đã thu học phí, nhắc học phí, chúc mừng sinh nhật, lịch nghỉ lễ.",
        "images": [
          {
            "src": "hoc-vien/thong-bao.webp",
            "caption": "Hộp thư thông báo: thông báo chưa đọc có nền màu và chấm đỏ",
            "mobile": true
          }
        ],
        "steps": [
          "Bấm **Thông báo** ở thanh dưới (số đỏ trên chuông là số thông báo chưa đọc). Thông báo mới nhất nằm trên cùng, thông báo chưa đọc có nền màu và chấm đỏ bên trái.",
          "Đọc xong một thông báo, bấm **Đánh dấu đã đọc**. Muốn đánh dấu hết, bấm **Đánh dấu tất cả đã đọc**.",
          "Thông báo không cần giữ: bấm **Xóa** rồi xác nhận."
        ],
        "tips": []
      },
      {
        "id": "khao-sat",
        "title": "Làm khảo sát",
        "icon": "assignment",
        "menu": "Thanh dưới → Khảo sát → Khảo sát định kỳ",
        "route": "portal.student.survey",
        "intro": "Trung tâm mở các đợt khảo sát định kỳ về chất lượng dạy học, cơ sở vật chất, giáo trình. Ý kiến của bạn giúp trung tâm cải thiện.",
        "images": [
          {
            "src": "hoc-vien/khao-sat.webp",
            "caption": "Khảo sát đang mở, kèm hạn trả lời",
            "mobile": true
          },
          {
            "src": "hoc-vien/khao-sat-tra-loi.webp",
            "caption": "Hộp thoại Phản hồi khảo sát: chọn số sao và viết ý kiến",
            "mobile": true
          }
        ],
        "steps": [
          "Bấm **Khảo sát** ở thanh dưới, chọn tab **Khảo sát định kỳ**.",
          "Mục **Khảo sát đang mở** liệt kê các đợt còn hạn, mỗi dòng ghi **Hạn** trả lời. Bấm vào một khảo sát để mở hộp thoại **Phản hồi khảo sát**.",
          "Chọn từ 1 đến 5 sao ở **Mức độ hài lòng chung** (mặc định 5 sao), viết ý kiến vào ô **Ý kiến của bạn** (bắt buộc), rồi bấm **Gửi phản hồi khảo sát**.",
          "Các bài đã gửi hiện ở **Khảo sát đã gửi**, kèm số sao bạn đã chấm."
        ],
        "tips": [
          "Nếu thấy \"Hiện chưa có khảo sát nào đang mở\", trung tâm chưa mở đợt mới."
        ]
      },
      {
        "id": "gop-y",
        "title": "Gửi góp ý theo chặng học",
        "icon": "rate_review",
        "menu": "Khảo sát → Feedback chặng học (hoặc menu ☰ → Gửi góp ý)",
        "route": "portal.student.feedback",
        "intro": "Khi lớp học xong một chặng, trung tâm mở cổng góp ý cho chặng đó. Phụ huynh, học sinh chấm sao và viết cảm nhận về giáo trình, giáo viên.",
        "images": [
          {
            "src": "hoc-vien/gop-y.webp",
            "caption": "Đánh giá chặng học đang mở thu thập",
            "mobile": true
          }
        ],
        "steps": [
          "Bấm **Khảo sát** ở thanh dưới, chọn tab **Feedback chặng học**. Khối **Chặng học đang mở thu thập** cho biết chặng và lớp đang được góp ý.",
          "Chọn số sao ở **Mức độ hài lòng chung** (1 đến 5).",
          "Tích **Lĩnh vực cần góp ý**: **Học thuật**, **Giáo viên** hoặc **Khác**.",
          "Viết cảm nhận ở **Nội dung feedback chi tiết**, rồi bấm **Gửi feedback**. Chỉ cần điền ít nhất một trong ba mục."
        ],
        "tips": [
          "Trong thời gian đợt góp ý còn mở, bạn vẫn sửa lại được nội dung đã gửi.",
          "Việc cần phản ánh gấp: liên hệ trực tiếp Cố vấn học tập hoặc hotline trung tâm."
        ]
      },
      {
        "id": "hoc-phi",
        "title": "Xem học phí và báo đã đóng học phí",
        "icon": "account_balance_wallet",
        "menu": "Trang chủ → Thông tin học phí",
        "route": "portal.student.home",
        "intro": "Khối Thông tin học phí cho biết tổng đã đóng, còn nợ và học phí dự kiến khóa tới. Sau khi chuyển khoản, bạn báo cho trung tâm để Kế toán đối soát.",
        "images": [
          {
            "src": "hoc-vien/hoc-phi.webp",
            "caption": "Khối Thông tin học phí với nút Báo đóng và Lịch sử",
            "mobile": true
          },
          {
            "src": "hoc-vien/bao-dong-hoc-phi.webp",
            "caption": "Hộp thoại Báo đóng học phí",
            "mobile": true
          }
        ],
        "steps": [
          "Ở **Trang chủ**, kéo xuống khối **Thông tin học phí**. Xem **Tổng đã đóng** và **Còn nợ**.",
          "Bấm **Lịch sử** (hoặc **Xem lịch sử thu học phí**) để xem các đợt đã nộp.",
          "Đã chuyển khoản học phí: bấm **Báo đóng**.",
          "Nhập **Số tiền đã chuyển (VNĐ)** và **Nội dung chuyển khoản / Ghi chú** (mã giao dịch hoặc nội dung chuyển tiền), bấm **Gửi xác nhận**.",
          "Khi Kế toán xác nhận đã thu, bạn nhận thông báo trong mục **Thông báo**."
        ],
        "tips": []
      },
      {
        "id": "sua-thong-tin",
        "title": "Sửa thông tin liên hệ của học viên",
        "icon": "edit",
        "menu": "Trang chủ → Thông tin học sinh → Sửa",
        "route": "portal.student.home",
        "intro": "Phụ huynh tự cập nhật số điện thoại liên hệ, địa chỉ và gửi ghi chú cho trung tâm, giáo viên (ví dụ bé bị dị ứng, xin vào muộn).",
        "images": [
          {
            "src": "hoc-vien/sua-thong-tin.webp",
            "caption": "Cập nhật thông tin học viên",
            "mobile": true
          }
        ],
        "steps": [
          "Ở **Trang chủ**, kéo xuống cuối tới khối **Thông tin học sinh**, bấm **Sửa**.",
          "Sửa **Số điện thoại liên hệ**, **Địa chỉ**, viết **Ghi chú cho trung tâm / giáo viên** nếu cần.",
          "Bấm **Lưu thay đổi**."
        ],
        "tips": [
          "Họ tên, ngày sinh, lớp học do trung tâm quản lý. Cần sửa thì báo Học vụ."
        ]
      },
      {
        "id": "doi-mat-khau",
        "title": "Đổi mật khẩu",
        "icon": "lock",
        "menu": "Biểu tượng người góc trên → Tài khoản & đổi mật khẩu",
        "route": "profile.edit",
        "intro": "Nên đổi mật khẩu trung tâm cấp sang mật khẩu riêng của gia đình để giữ an toàn cho tài khoản.",
        "images": [
          {
            "src": "hoc-vien/tai-khoan.webp",
            "caption": "Thông tin tài khoản: mã học viên và nút Tài khoản & đổi mật khẩu",
            "mobile": true
          },
          {
            "src": "hoc-vien/doi-mat-khau.webp",
            "caption": "Khối Đổi mật khẩu",
            "mobile": true
          }
        ],
        "steps": [
          "Ở **Trang chủ**, bấm biểu tượng người ở góc phải khung MENGLISH để mở trang tổng hợp của cổng.",
          "Kéo xuống khối **Thông tin tài khoản** (có **Mã học viên**), bấm **Tài khoản & đổi mật khẩu**.",
          "Ở khối **Đổi mật khẩu**, nhập **Mật khẩu hiện tại**, **Mật khẩu mới** và **Xác nhận mật khẩu mới**.",
          "Bấm **Cập nhật mật khẩu**. Lần đăng nhập sau dùng mật khẩu mới."
        ],
        "tips": [
          "Bấm biểu tượng con mắt trong ô mật khẩu để xem lại chữ vừa gõ."
        ]
      }
    ]
  },
  {
    "id": "chung",
    "label": "Dùng chung",
    "icon": "apps",
    "summary": "Những thao tác ai cũng dùng: đăng nhập, đổi mật khẩu, xem thông báo, tìm nhanh màn hình và chấm công trên điện thoại.",
    "sections": [
      {
        "id": "dang-nhap",
        "title": "Đăng nhập và đổi mật khẩu",
        "icon": "login",
        "menu": "Ảnh đại diện góc trên phải → Hồ sơ cá nhân → Cài đặt tài khoản",
        "route": "profile.edit",
        "intro": "Mỗi người có một tài khoản riêng. Nhân viên đăng nhập bằng mã nhân viên (dạng MES012), học viên bằng mã học viên do trung tâm cấp. Ai cũng vẫn đăng nhập được bằng email.",
        "images": [
          {
            "src": "chung/dang-nhap.webp",
            "caption": "Màn hình đăng nhập",
            "mobile": false
          },
          {
            "src": "chung/doi-mat-khau.webp",
            "caption": "Hồ sơ cá nhân → Cài đặt tài khoản → Đổi mật khẩu",
            "mobile": false
          }
        ],
        "steps": [
          "Mở portal, nhập mã của bạn vào ô **Mã nhân viên hoặc email** (học viên nhập mã học viên), nhập **Mật khẩu** rồi bấm **Đăng nhập**.",
          "Lần đầu đăng nhập, nếu hệ thống yêu cầu đổi mật khẩu, hãy đặt mật khẩu mới trước khi dùng tiếp.",
          "Muốn đổi mật khẩu sau này: bấm ảnh đại diện góc trên bên phải, chọn **Hồ sơ cá nhân**, rồi bấm **Cài đặt tài khoản**.",
          "Ở khối **Đổi mật khẩu**, nhập mật khẩu hiện tại, mật khẩu mới hai lần, rồi bấm **Cập nhật mật khẩu**."
        ],
        "tips": [
          "Quên mật khẩu: bấm **Quên mật khẩu?** ở màn đăng nhập để nhận link đặt lại qua email, hoặc báo Học vụ / Admin đặt lại giúp.",
          "Mã nhân viên của bạn hiện cạnh tên ở trang **Hồ sơ cá nhân**.",
          "Không dùng chung tài khoản: mọi thao tác đều được ghi lại theo người đăng nhập."
        ]
      },
      {
        "id": "thong-bao",
        "title": "Xem thông báo",
        "icon": "notifications",
        "menu": "Chuông góc trên phải, hoặc Của tôi → Thông báo",
        "route": "notifications.index",
        "intro": "Hệ thống báo cho bạn khi có việc mới được giao, đơn được duyệt hoặc trả về, khách cần chăm sóc và các nhắc hạn.",
        "images": [
          {
            "src": "chung/thong-bao.webp",
            "caption": "Trang Thông báo",
            "mobile": false
          }
        ],
        "steps": [
          "Bấm biểu tượng **chuông** trên thanh trên cùng để xem nhanh các thông báo mới nhất. Số đỏ là số thông báo chưa đọc.",
          "Bấm vào một thông báo để mở đúng màn hình cần xử lý.",
          "Bấm **Xem tất cả thông báo** (hoặc vào **Của tôi → Thông báo**) để xem toàn bộ, lọc **Chưa đọc**.",
          "Bấm **Đánh dấu tất cả đã đọc** khi đã xử lý xong."
        ],
        "tips": []
      },
      {
        "id": "tim-kiem",
        "title": "Tìm nhanh màn hình, khách hàng, học viên",
        "icon": "search",
        "menu": "Ô tìm kiếm trên thanh trên cùng",
        "intro": "Gõ tên màn hình (ví dụ \"lương\", \"điểm danh\"), tên hoặc số điện thoại khách hàng, tên học viên, mã lớp để mở thẳng, không cần nhớ nó nằm ở menu nào.",
        "images": [
          {
            "src": "chung/tim-kiem.webp",
            "caption": "Kết quả tìm kiếm chia theo nhóm: màn hình, khách hàng, học viên, lớp",
            "mobile": false
          }
        ],
        "steps": [
          "Bấm vào ô **Tìm màn hình, khách, học viên, lớp...** trên thanh trên cùng (trên điện thoại là biểu tượng kính lúp).",
          "Gõ ít nhất 2 ký tự, không cần gõ dấu, rồi nhấn **Enter**.",
          "Bấm vào kết quả để mở. Bạn chỉ thấy những gì vai trò của mình được xem."
        ],
        "tips": []
      },
      {
        "id": "menu",
        "title": "Làm quen menu và trang tổng quan",
        "icon": "menu",
        "menu": "Thanh menu bên trái",
        "intro": "Menu bên trái chỉ hiện những khu bạn có quyền dùng, chia theo nhóm: Phê duyệt, Giao việc, Tuyển sinh, Đào tạo, Tài chính, Người dùng, Báo cáo, Cá nhân. Mỗi khu có các tab ở đầu trang.",
        "images": [
          {
            "src": "chung/menu.webp",
            "caption": "(1) mục ở menu trái, (2) tab của khu, (3) nút hành động chính",
            "mobile": false
          }
        ],
        "steps": [
          "Bấm một mục ở menu trái để vào khu đó. Các màn hình con của khu hiện thành **tab** ở đầu trang.",
          "Nút hành động chính của khu (ví dụ **Thêm khách mới**, **Tạo lớp mới**) nằm ở góc phải hàng tab.",
          "Trên điện thoại, bấm biểu tượng **☰** ở góc trên bên trái để mở menu.",
          "Mục **Hướng dẫn sử dụng** ở cuối menu luôn mở lại trang này."
        ],
        "tips": []
      },
      {
        "id": "cham-cong-dien-thoai",
        "title": "Chấm công trên điện thoại (nhân sự)",
        "icon": "add_a_photo",
        "menu": "Trên điện thoại: Của tôi → Chấm công",
        "intro": "Nhân sự chấm công vào, ra bằng ảnh chụp và vị trí GPS ngay trên điện thoại, xem lại lịch sử công và gửi đơn bổ sung công, xin đi muộn, về sớm hoặc xin nghỉ.",
        "images": [
          {
            "src": "chung/cham-cong.webp",
            "caption": "Màn chấm công",
            "mobile": true
          },
          {
            "src": "chung/lich-su-cong.webp",
            "caption": "Lịch sử công",
            "mobile": true
          },
          {
            "src": "chung/xin-duyet.webp",
            "caption": "Gửi đơn xin duyệt",
            "mobile": true
          }
        ],
        "steps": [
          "Mở portal trên điện thoại, đăng nhập, mở menu **☰** rồi chọn **Của tôi → Chấm công**.",
          "Bật GPS, bấm **Chấm công vào** (cuối ngày là **Chấm công ra**) và cho phép trình duyệt dùng camera, vị trí.",
          "Chụp ảnh khuôn mặt tại cơ sở rồi xác nhận. Giờ chấm lấy theo máy chủ. Bạn phải đứng trong bán kính cơ sở (thường 150 m) mới chấm được; nếu đi muộn, hệ thống tự lập biên bản chờ bạn giải trình.",
          "Bấm **Lịch sử** ở thanh dưới để xem lại các ngày công.",
          "Quên chấm, đi muộn, về sớm hay xin nghỉ: bấm **Xin duyệt**, chọn **Bổ sung công**, **Xin đi muộn / về sớm** hoặc **Xin nghỉ**, nhập lý do rồi gửi. Kết quả duyệt hiện ở mục **Đơn của tôi**."
        ],
        "tips": [
          "Chỉ chấm được trên điện thoại. Trên máy tính, mục này không hiện. Nút **Bản đầy đủ** ở thanh dưới mở lại giao diện đầy đủ của portal.",
          "Giáo viên, trợ giảng check-in ca dạy ở Cổng Giáo viên (xem hướng dẫn Giáo viên / Trợ giảng)."
        ]
      }
    ]
  }
]
