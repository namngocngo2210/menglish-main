# Hoa hồng tuyển sinh

Áp dụng quyết định chủ sở hữu Q7, ticket TK-2026-0021. `SalesCommissionService` là nguồn tính chung cho bảng lương, bảng xếp hạng KPI, báo cáo CRM và bảng hoa hồng tạm tính trên trang cá nhân.

## Căn cứ và người nhận

Hoa hồng = **học phí thực thu × % theo thứ tự chốt**. Chỉ phiếu thu đã duyệt, số tiền dương mới được tính; không dùng giá trị hợp đồng CRM. Loại phụ thu của phiếu và phần phí sách / giáo trình / Thu khác trong khoản học phí theo tỉ lệ `other_fees / final_amount`. Phiếu chỉ thu các khoản này không phát sinh hoa hồng.

Chỉ tính khách mới được chuyển đổi từ CRM, với các đợt đóng thuộc **khoản học phí đầu tiên** của học viên (`StudentTuition` có id nhỏ nhất). Khoản tái tục không có hoa hồng tuyển mới. Phiếu cũ không gắn khoản học phí chỉ tính khi được lập trước khoản học phí thứ hai. Không tính phiếu âm (hoàn phí, chuyển nhượng đi) hoặc phiếu nhận chuyển nhượng `XFER-IN-%`, vì đây không phải tiền thực thu mới.

Người nhận là người phụ trách được ghi nhận lúc chuyển đổi CRM (`commission_user_id`); hồ sơ cũ chưa có trường này dùng `assigned_user_id`. Việc đổi người chăm sóc sau đó không chuyển hoa hồng đã gắn người nhận.

## Ngày chốt và thứ tự chốt

**Ngày chốt hoa hồng là ngày đóng học phí thành công đầu tiên**: `approved_at` sớm nhất của phiếu đã duyệt, số tiền dương, không phải `XFER-IN-%`, thuộc khoản học phí đầu tiên theo bộ lọc trên. Không yêu cầu căn cứ hoa hồng của phiếu này lớn hơn 0. Chưa có phiếu hợp lệ thì chưa chốt: không có thứ tự chốt, không tính vào số học viên chốt hoặc mốc hoa hồng.

Ví dụ: chuyển đổi qua Chốt & Xếp lớp tháng 10, nhưng phiếu đầu tiên được duyệt ngày 10/11 thì học viên được tính chốt trong **tháng 11**, xếp thứ tự tháng 11 và bắt đầu đếm 30 ngày từ 10/11. Không tính chốt hoa hồng tháng 10. Số khách thắng trong phễu CRM vẫn phản ánh thời điểm chuyển đổi CRM.

Thứ tự chốt tính riêng từng người nhận trong từng tháng, trên mọi chi nhánh: ngày giờ chốt tăng dần, trùng thời điểm thì id khách tăng dần. Mỗi tháng bắt đầu lại từ 1. Mặc định:

| Thứ tự học viên chốt trong tháng | Tỉ lệ |
| --- | --- |
| 1–5 | 3% |
| 6–10 | 4% |
| Từ 11 | 5% |

Đây là tỉ lệ tăng tiến theo **từng học viên**, không áp dụng ngược tỉ lệ cuối tháng cho cả tháng. Admin có thể cấu hình mốc và phiên bản theo ngày hiệu lực.

Mọi phiếu và khoản hoa hồng của một học viên dùng **bảng mốc hiệu lực tại ngày chốt**. Ví dụ học viên chốt tháng 10 ở mức 3%, bảng mới từ tháng 11 là 4% thì đợt đóng tháng 11 vẫn dùng 3%; khoản tháng 10 còn hoãn cũng giữ 3%. Mốc hiện tại hiển thị để tham khảo có thể dùng bảng đang hiệu lực cuối tháng xem.

## Phát sinh, điều kiện trả và hoãn

Khoản hoa hồng phát sinh vào tháng phiếu thu được duyệt, ghi trong `commission_items`. Để trả phải đồng thời đạt:

1. Đủ **30 ngày từ ngày chốt hoa hồng** (ngày đóng học phí thành công đầu tiên), xét đến cuối kỳ lương.
2. Hoàn thành **3/3 mốc chăm sóc tháng đầu**, theo công việc chăm sóc đã hoàn thành hoặc checklist CRM tương ứng.

Lịch chăm sóc vẫn theo thời điểm chuyển đổi CRM và quy tắc của `FirstMonthCareService`; việc thay ngày chốt hoa hồng không dời lịch chăm sóc.

Chưa đạt một trong hai điều kiện thì **hoãn sang kỳ sau**, có lý do. Kỳ đầu tiên đủ cả hai điều kiện sẽ trả, giữ tỉ lệ của bảng tại ngày chốt. Bảng trang cá nhân / KPI / CRM là số phát sinh hoặc tạm tính; số thực trả căn cứ phiếu lương. Khoản đã trả không được tính trả lại.

## Hoàn phí và hủy hóa đơn

**Không thu hồi hoa hồng đã trả** khi hoàn phí hoặc hủy hóa đơn. Duyệt hoàn phí không còn lựa chọn thu hồi và không tạo `CommissionAdjustment`. Các trường thu hồi trên hồ sơ mới được đặt về không thu hồi, số tiền 0 và không có người bị thu hồi; cột dữ liệu vẫn giữ để xem lịch sử.

- Hoàn phí: hủy (`void`) mọi khoản hoa hồng **chưa trả** của học viên thuộc người nhận hoa hồng, kể cả khoản đang hoãn hoặc gắn phiếu lương chưa khóa; gỡ liên kết phiếu lương. Khoản đã hủy không mở lại khi tính lại lương.
- Hủy hóa đơn: hủy khoản **chưa trả của phiếu bị hủy**, gỡ liên kết phiếu lương; giữ nguyên khoản đã trả. Các phiếu hợp lệ khác vẫn xét theo quy tắc chung.
- Chuyển nhượng học phí: giữ nguyên sổ hoa hồng; phiếu chuyển nhượng vào / ra không tạo hoa hồng mới.

Các dòng `CommissionAdjustment` **lịch sử hoặc thủ công vẫn được giữ**, và dòng chưa quyết toán vẫn được áp dụng khi tính lương theo cơ chế cũ. Nhãn thu hồi và huy hiệu trên hồ sơ cũ chỉ phản ánh dữ liệu lịch sử; luồng hoàn phí / hủy hóa đơn mới không tạo khoản thu hồi.
