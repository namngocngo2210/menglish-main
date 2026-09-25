# MEnglish — Báo cáo Audit (nghiệp vụ + giao diện) & Kế hoạch 4 phase

> **Ngày:** 25/09/2026 · **Người lập:** CTO
> **Chuẩn đối chiếu:** BPMN (2 file `.drawio`), `tai-lieu-su-dung-flow-tinh-nang.html` (gọi tắt: *flow doc*), `test-cases-unitest-flows.html`, `unitest-crm.xlsx`, `Thang điểm + hướng dẫn nhận xét.html`, `erp-database-schema.html`, mockup `code.html`.
> **Phương pháp:** đọc code, lần theo từng luồng route → controller → model → view. **Chưa chạy test hay chạy thử ứng dụng.** Mọi lỗi cần được kiểm chứng lại bằng test khi sửa.

---

## Phần A — Audit nghiệp vụ & bảo mật

### A1. Tổng quan

| Phần | Nghiêm trọng (P0) | Sai quy trình / lỗi chức năng (P1) | Tối ưu / giao diện (P2) |
|---|:-:|:-:|:-:|
| CRM & Test đầu vào | 3 | 15 | 8 |
| Lịch: lớp, TKB, điểm danh, học viên | 2 | 17 | 9 |
| Giáo trình & Big Test | 2 | 12 | 4 |
| Chấm công, Phạt & Lương | 4 | 9 | 7 |
| Học phí, Hóa đơn & SePay | 6 | 16 | 6 |
| Nền tảng, Bảo mật, Portal, Vận hành | 6 | 28 | 8 |
| **Tổng** | **23** | **97** | **42** |

- **P0:** lỗ hổng bảo mật, lộ dữ liệu, tính sai tiền/lương, hỏng dữ liệu. Phải sửa trước.
- **P1:** làm sai so với BPMN/flow doc/mockup, hoặc lỗi chức năng rõ ràng.
- **P2:** tối ưu tốc độ, dọn code, lệch giao diện.
- Một số lỗi xuất hiện ở nhiều phần (ví dụ cách sinh mã chứng từ, upload file). Con số trên có trùng lặp nhỏ.

**Đánh giá chung:** hệ thống đã có đủ khung cho hầu hết quy trình. Rủi ro lớn nằm ở 3 nhóm:
1. **Bảo mật:** chiếm quyền tài khoản, upload file chạy được code, học viên xem được dữ liệu người khác.
2. **Tiền:** lương và công nợ đang bị tính sai ở nhiều chỗ.
3. **Dữ liệu giả hoặc chức năng giả:** màn hình hiển thị số liệu mẫu, nút bấm chỉ hiện thông báo mà không lưu gì.

### A2. Các lỗi nghiêm trọng (P0), viết theo nghiệp vụ

**Bảo mật và lộ dữ liệu**

| # | Vấn đề | Hậu quả |
|---|---|---|
| 1 | Nhân viên Học vụ hoặc Quản lý đổi được mật khẩu, hạ quyền hoặc khóa tài khoản **Admin** | Chiếm quyền toàn hệ thống |
| 2 | Upload bài tập, ảnh minh chứng, ảnh bảng, phiếu thu **không chặn file chạy được code** | Người ngoài có thể chiếm máy chủ |
| 3 | Bộ màn hình mockup cũ vẫn chạy trên hệ thống thật, **ai đăng nhập cũng xem được bài tập, phản hồi, khảo sát của mọi học viên** | Lộ dữ liệu học viên |
| 4 | **Ghi chú nội bộ** của ticket hiện cho học viên và được gửi cả qua email | Lộ trao đổi nội bộ |
| 5 | Học viên và giáo viên xem được **toàn bộ lớp và danh sách học viên** của trung tâm | Lộ dữ liệu |
| 6 | **Khóa bí mật SePay và mật khẩu email** bị ghi rõ trong nhật ký thao tác | Có thể giả lệnh báo đã chuyển tiền |
| 7 | Trang làm test online: đổi số trên link là **lấy được tên, SĐT, email của mọi khách hàng** | Lộ toàn bộ danh sách khách |
| 8 | Trang làm test online: người lạ **sửa được điểm và kéo khách đã chốt về trạng thái cũ** | Mất doanh số, sai hoa hồng |
| 9 | Trang làm test online còn **đáp án viết cứng**, gửi đúng mẫu là được 8.5 điểm | Kết quả test không đáng tin |
| 10 | Giáo viên **nhập hoặc sửa được điểm Big Test của lớp khác** | Sai điểm học viên |
| 11 | Học viên không có SĐT thì **điểm Big Test bị gửi Zalo tới một số lạ viết cứng** | Lộ điểm, gửi nhầm người |

**Tính sai tiền và lương**

| # | Vấn đề | Hậu quả |
|---|---|---|
| 12 | **Hoa hồng sale không được lưu** vào bảng lương, sửa một khoản khác là mất hẳn | Trả thiếu hoa hồng |
| 13 | Đơn giá giờ dạy **luôn là 300.000đ/giờ**, bỏ qua đơn giá riêng của giáo viên | Trả sai lương dạy |
| 14 | Duyệt lương đánh dấu **mọi khoản phạt là "đã trừ"** kể cả khoản chưa trừ | Thất thoát tiền phạt |
| 15 | Kỳ lương đã chốt **vẫn nhận thêm công và phạt** nhưng không kỳ nào tính | Giáo viên không được trả, phạt không bị trừ |
| 16 | **Giảm giá và phụ thu** trên phiếu thu làm sai công nợ | Học viên bị báo nợ sai, bị nhắc nợ sai |
| 17 | Có cách để **phiếu thu tự duyệt**, không qua kế toán | Xóa nợ không kiểm soát |
| 18 | **Duyệt hủy hóa đơn không hoàn lại công nợ** dù hệ thống báo đã hoàn | Sổ sách sai |
| 19 | **Hoàn phí hoặc chuyển nhượng làm tăng nợ** của học viên nguồn | Học viên bị nhắc nợ sai |
| 20 | **Báo cáo doanh thu tính cả phiếu chưa duyệt**, một phiếu có thể bị đếm ở 2 chi nhánh | Doanh thu báo cáo cao hơn thực tế |
| 21 | **Nhắc nợ tự động lúc 08:30 lỗi ở mọi lần chạy**, chưa từng gửi được | Không có nhắc nợ |

**Hỏng dữ liệu lịch học**

| # | Vấn đề | Hậu quả |
|---|---|---|
| 22 | **Đổi giáo viên hoặc phòng** của lớp thì ghi đè lên cả các buổi đã dạy | Mất dấu ai đã dạy, chấm công và lương sai |
| 23 | **Lưu lại TKB** thì nhân đôi các buổi đã qua, xóa cả buổi đã điểm danh | Hỏng lịch sử học |

### A3. Các nhóm lỗi P1, theo đầu việc

**CRM & Test đầu vào**
- Màn chốt khách lỗi với khách đã học thử hoặc đang chờ lớp. Không chọn được lớp sắp khai giảng.
- Chấm lại bài làm khách bị lùi trạng thái. Bài nộp online bị ghi "đã chấm" dù giáo viên chưa chấm, và không lưu câu trả lời.
- Hai nơi nhập điểm dùng hai thang khác nhau. Để trống điểm Viết thì hệ thống tự lấy điểm Đọc điền vào.
- Khách đã chốt vẫn sửa được giá trị hợp đồng. Sửa thông tin không ghi lịch sử.
- Không tạo lại được khách đã xóa, không có chức năng khôi phục.
- Không kiểm tra định dạng SĐT. Cảnh báo khách bị bỏ quên chỉ áp dụng khách mới, không báo cho sale phụ trách.
- Sale và giáo viên không có quyền nhập điểm test, trái bảng phân quyền.
- Xóa đề test thì mất hết bài làm.

**Lịch học, lớp, học viên**
- Sinh lịch học không bỏ qua ngày nghỉ lễ.
- Điểm danh gắn cứng vào ngày hôm nay: không điểm danh bù được buổi đã qua, không điểm danh được buổi học bù cùng ngày.
- Tự chấm công: Học vụ chấm công được cho lớp bất kỳ, không có buổi vẫn được tính 2 giờ.
- GVNN không được kiểm tra trùng lịch.
- Xếp lớp không kiểm tra sĩ số. Không có ngưỡng khai giảng. Không có bước "Xác nhận chính thức".
- Trạng thái học viên thiếu so với mockup. Cho nghỉ học vẫn để trong danh sách lớp.
- Sinh mã học viên bị trùng, gây lỗi hệ thống.
- Dashboard lớp theo ngày hiển thị dữ liệu giả. Lịch dạy của giáo viên không theo buổi học thật.
- Học viên vắng hoặc điểm dưới 7 không được đưa vào danh sách bổ trợ.

**Giáo trình & Big Test**
- Gửi Zalo kết quả: bấm lại là gửi lại cả lớp (tốn phí). Gửi lỗi vẫn báo thành công.
- Kết quả đã duyệt hoặc đã gửi vẫn sửa được. Học viên vắng bị lưu điểm 0.
- Duyệt giãn tiến độ không thay đổi gì trên lịch. Ô lý do từ chối không được lưu.
- Trình soạn giáo trình chỉ sửa được giáo trình đầu tiên.
- Một lớp có thể được giao 2 chặng cùng lúc.
- Order đề của giáo viên không tới được màn duyệt đề.
- Upload tài liệu giáo trình và đề xuất sửa giáo trình chỉ là giao diện, không lưu gì.
- Mọi giáo viên và trợ giảng xem được passcode đề thi.
- Công cụ quản lý file có thể di chuyển hoặc xóa file của module khác (CV, bài tập).

**Chấm công, Phạt, Lương**
- Tính lại lương không xóa bản ghi cũ, nên vẫn chi trả sai.
- Chưa tính thưởng tái tục.
- Kế toán và Quản lý đang có quyền **duyệt lương** (theo tài liệu chỉ Giám đốc được duyệt).
- Quy trình phạt thiếu các bước giải trình, HT/CM chốt theo loại lỗi, nộp trong 2 ngày.
- Chấm công tay không bắt buộc lý do. Chấm trùng buổi thì tính 2 lần.
- "Lương của tôi" hiện cả bảng lương chưa duyệt. Nhân viên tự chấm được KPI của mình.

**Học phí & Hóa đơn**
- Tiền chuyển khoản có thể bị ghi nhận 2 lần.
- Hai kế toán duyệt cùng lúc có thể làm sai công nợ.
- Dải số hóa đơn không tách theo chi nhánh, và có thể cấp lại số đã dùng.
- Mã QR có thể trỏ tới tài khoản ngân hàng viết cứng.
- Bảo lưu và khất nợ duyệt xong không có tác dụng gì.
- Nhập Excel học phí là giả.
- Sale xem được báo cáo thu chi toàn công ty.

**Nền tảng, Portal, Vận hành**
- Nhật ký thao tác không ghi được dữ liệu trước và sau khi sửa.
- Phân quyền cá nhân theo chi nhánh hoặc lớp chưa hoạt động.
- Chưa giới hạn dữ liệu theo chi nhánh cho Quản lý.
- Giao việc:
  - Ai cũng xem được mọi công việc.
  - Đổi trạng thái không kiểm tra người làm.
  - Tự duyệt được việc của mình.
  - Chưa giao việc 2 chiều (GV/TA giao ngược cho Admin).
  - Chưa có thông báo khi được giao việc.
- Báo cáo trực lớp không có bước duyệt. KPI tự động dùng số liệu giả.
- Ticket: thông báo gửi sai người, sai định dạng mã TK-, file đính kèm xem được mà không cần đăng nhập.
- Menu theo tên vai trò thay vì theo quyền, nên có mục bấm vào báo không có quyền.
- Portal học viên tự tạo dữ liệu giả mỗi lần mở trang. Điểm phát âm "AI" là số ngẫu nhiên.
- Tài khoản mới không bị bắt đổi mật khẩu. Người dùng tự xóa được tài khoản của mình.
- Chưa có cảnh báo hết hạn hợp đồng nhân sự, chưa có dashboard theo vai trò (BPMN 22).

### A4. Nguyên nhân gốc (sửa 1 lần cho cả hệ thống)
1. **Sinh mã chứng từ bằng cách đếm số bản ghi.** Bị trùng mã khi có bản ghi đã xóa hoặc 2 người tạo cùng lúc. → Làm 1 bộ sinh mã dùng chung.
2. **Upload file mỗi chỗ viết một kiểu.** → Làm 1 dịch vụ upload an toàn dùng chung, lưu file ở nơi không công khai.
3. **Phân quyền lệch tài liệu, nhiều trang chỉ cần đăng nhập.** → Chuẩn hóa bảng quyền theo 8 vai trò, kiểm tra quyền ở mọi trang.
4. **Đổi trạng thái không kiểm tra trạng thái hiện tại.** → Mỗi loại chứng từ có quy tắc chuyển trạng thái rõ ràng.
5. **Thao tác tiền và lương không khóa dữ liệu khi xử lý đồng thời.**
6. **Dữ liệu giả và chức năng giả còn sót từ giai đoạn mockup.** → Gỡ bỏ hoặc làm thật.
7. **Học viên thuộc lớp nào được lưu ở 2 nơi,** nên sĩ số, danh sách lớp và điểm danh lệch nhau.

### A5. Tài liệu mâu thuẫn, cần BA/Ban giám đốc chốt

| # | Nội dung | Các phương án |
|---|---|---|
| Q1 | Các bước CRM | Flow doc có 6 bước. BPMN thêm học thử và danh sách chờ (code đang theo BPMN). Mockup có thêm "Gửi kết quả", "Hủy chốt" và cho lùi bước |
| Q2 | Cách tính điểm test | Flow doc: trung bình 4 kỹ năng rồi quy ra CEFR. File thang điểm: cộng tổng 3 phần theo khối lớp rồi xếp thẳng vào khóa |
| Q3 | Công thức lương | Flow doc: KPI +1 triệu khi dạy ≥ 40 giờ, phụ cấp cố định 500k. Mockup: KPI theo số học viên giữ được, phí công đoàn 0.5%, thuế TNCN nhập tay, 4 mẫu phiếu lương |
| Q4 | Mô hình giáo trình | Flow doc: giáo trình → bài (code đang theo). BPMN bước 7 và mockup: chặng → buổi, 1 lớp chỉ 1 chặng đang học |
| Q5 | Trạng thái học viên | Flow doc và schema có 4 trạng thái. Mockup có 7 |
| Q6 | Chốt khách | Có xuất hóa đơn ngay không? Có cho "xếp lớp sau" không? Giá trị hợp đồng có tính tiền giáo trình, đồ dùng không (ảnh hưởng hoa hồng)? |
| Q7 | Phân quyền | Quản lý cơ sở thấy dữ liệu chi nhánh mình hay toàn bộ? Ai nhập điểm test? CM có phải Học vụ, HT có phải Học thuật không? Có vai trò Phụ huynh không? |
| Q8 | Vận hành | Ảnh bảng trong báo cáo trực lớp có bắt buộc không? Ai duyệt báo cáo trực lớp? Ticket chưa có người xử lý thì báo cho ai? |

**Nguyên tắc tạm thời nếu chưa chốt kịp:** theo **BPMN + mockup**, và ghi rõ vào mục Nhật ký giai đoạn.

### A6. Quyết định đã chốt

| Ngày | Chủ đề | Quyết định |
|---|---|---|
| 25/09/2026 | Thứ tự sửa | Sửa toàn bộ lỗi P0 (bảo mật, lương, công nợ) trước tiên trong Phase 1 |
| 25/09/2026 | Q2 — Điểm test | Là **test đầu vào**. Cách tính (trung bình 4 kỹ năng hay tổng theo khối lớp) đang chờ BA trả lời tiếp |
| 25/09/2026 | Q6 — Chốt khách | Khi chốt được **chọn lớp** (kiểm tra còn chỗ) **hoặc đưa vào lớp chờ** nếu chưa có lịch khớp. Học vụ gán lớp sau từ danh sách "Chờ xếp lớp" |
| 25/09/2026 | Q6 — Hoa hồng | Hoa hồng tính trên **tổng tiền thực thu** (phiếu thu đã duyệt), **gồm cả tiền giáo trình, đồ dùng**. Không tính trên giá trị hợp đồng |
| 25/09/2026 | Q6 — Hoa hồng: phạm vi | **Chỉ tính hoa hồng cho lần đầu** (khách mới). Không tính hoa hồng tái tục |
| 25/09/2026 | Q6 — Hoa hồng: hoàn phí | Khách hoàn phí ngay thì **thu hồi hoa hồng**. Đã học trên 1 tháng thì **không thu hồi**. Có thể quyết định tùy trường hợp. **Chuyển nhượng phí** cho học viên khác (trường hợp thường gặp) thì không thu hồi |
| 25/09/2026 | Q7 — Nhập điểm test | Chỉ **Học vụ** và **Admin cơ sở** nhập điểm test đầu vào, gồm cả phần Viết/Nói của bài test online. Sale và giáo viên không nhập |
| 25/09/2026 | Q7 — Xem khách | Admin cơ sở / Học vụ **chỉ thấy khách của chi nhánh mình**. Admin tổng thấy tất cả. Sale chỉ thấy khách được giao |
| 25/09/2026 | Q7 — Vai trò | "Admin cơ sở" chính là vai trò **Quản lý cơ sở** (`manager`) |
| 25/09/2026 | Q1 — Pipeline CRM | **8 bước**: Mới → Đang tư vấn → Hẹn test → Test → Đã test → Gửi kết quả → Chờ xếp lớp → Đã chốt, cộng **Thất bại**. Bỏ các bước học thử / chờ thanh toán |
| 25/09/2026 | Q1 — Ai chuyển bước | **CM** (Học vụ, Quản lý cơ sở; Admin) chuyển **tiến từng bước một**. Sale không đổi bước (vẫn sửa thông tin, ghi nhật ký). "Test" tự động khi khách mở link test; "Đã test" tự động khi Học vụ chấm xong. Nhánh không test: Đang tư vấn → Chốt thẳng |
| 25/09/2026 | Q1 — Lùi bước | **Chỉ Admin** được lùi bước, **bắt buộc lý do**, lưu lịch sử khách. **Không hủy chốt**: khách Chờ xếp lớp / Đã chốt không lùi, không sang Thất bại. Khách **Thất bại không mở lại** (giữ để đối soát) |
| 25/09/2026 | Q1 — Học thử | Học thử **không phải bước pipeline**: là hoạt động CM đặt trong lúc tư vấn (1–2 buổi của lớp thật cùng trình độ), gắn với **khách** (không phải học viên). Giáo viên buổi đó ghi phản hồi vào hồ sơ khách |
| 25/09/2026 | Q6 — Chốt không bắt buộc đóng phí | Có ô "Đã đóng học phí đăng ký". Chưa đóng → hệ thống **tự tạo task "Nhắc thu học phí"** cho người phụ trách khách |
| 25/09/2026 | Q6 — Chốt khi chưa có lớp | Chốt luôn tạo hồ sơ học viên + tài khoản + học phí. Có lớp → **Đã chốt**. "Xếp lớp sau" → **Chờ xếp lớp** → Học vụ **gán lớp** → **Đã chốt** |
| 25/09/2026 | Q6 — Học phí khi chưa có lớp | Tính theo **khóa học** đã chọn (giá niêm yết − ưu đãi), không phụ thuộc lớp |
| 25/09/2026 | Q5 — Trạng thái học viên | **6 trạng thái**: Chờ khai giảng, Đang học, Bảo lưu, Nghỉ hè, Hoàn thành khóa học, Thôi học (không có Học thử, Blacklist; Chuyển lớp không phải trạng thái). Khởi tạo khi chốt = **Chờ khai giảng** |

**Còn chờ trả lời:**
- **Q2:** cách tính điểm test đầu vào; thang cho học viên lớn (THCS, IELTS, người đi làm).
- **Q3:** công thức lương.
- **Q4:** mô hình giáo trình.
- **Hoa hồng:** "lần đầu" là mọi đợt đóng của khóa đầu tiên, hay chỉ đợt đóng đầu tiên? Hoa hồng tính vào tháng thực thu? "Hoàn phí ngay" là trong bao nhiêu ngày?
- **Q8:** báo cáo trực lớp.

---

## Phần B — Audit giao diện (so với 66 màn mockup)

> **Phương pháp:** đọc view (Blade) và controller, đối chiếu với `code.html` của từng mockup. **Chưa chạy app, chưa so ảnh chụp màn hình.**
> **Mức độ:** P1 = người dùng thấy thiếu hoặc sai (thiếu màn, trường, cột, bộ lọc, nút; nút giả; dữ liệu giả). P2 = thẩm mỹ (nhãn chữ, icon, bố cục, màu).

### B1. Tổng quan

| Nhóm | Số màn | Khớp | Làm một phần | Thiếu / khác hẳn | P1 | P2 |
|---|:-:|:-:|:-:|:-:|:-:|:-:|
| CRM, test đầu vào, hồ sơ học viên, nhập Excel | 18 | 0 | 16 | 2 | 75 | 29 |
| Lịch, lớp, phân công công việc, trình độ, ngày nghỉ | 11 | 0 | 11 | 0 | 58 | 25 |
| Giáo trình & Big Test | 8 | 0 | 8 | 0 | 38 | 19 |
| Chấm công, Phạt, Lương | 13 | 0 | 13 | 0 | 53 | 21 |
| Học phí, hóa đơn, thu chi | 12 | 2 | 8 | 2 | 44 | 31 |
| Cấu hình hệ thống (tài khoản, phân quyền, danh mục, nhật ký) | 4 | 0 | 4 | 0 | 16 | 14 |
| **Tổng** | **66** | **2** | **60** | **4** | **284** | **139** |

**Đánh giá chung:**
- Hầu hết màn đã có route và có nối dữ liệu thật.
- Khoảng cách với mockup nằm ở 4 chỗ:
  - Thiếu bộ lọc, xuất file, phân trang.
  - Thiếu các thao tác nghiệp vụ mà mockup có.
  - Nhiều chỗ còn hiện dữ liệu giả.
  - Khung giao diện chung (font, màu, cỡ chữ) chưa theo bộ token của mockup.
- **4 màn thiếu hoặc làm khác hẳn:**
  - Nhập khách hàng loạt từ Excel: chức năng giả.
  - Khách chốt — Xác nhận chính thức: chưa có.
  - Cấu hình dải số hóa đơn: khác mô hình.
  - Cấu hình nhắc nợ: khác chức năng.
- **2 màn khớp:** Báo cáo doanh thu tạm tính, Khoản chi vận hành.

### B2. Các vấn đề chung trên toàn giao diện

1. **Khung giao diện lệch bộ token mockup:**
   - Font chữ là Inter thay vì Be Vietnam Pro, và tải từ Google Fonts thay vì lưu sẵn trên server.
   - Màu `primary` của app là màu cam dành cho nút, trong khi mockup dùng `primary` là nâu đỏ. Vì vậy markup copy từ mockup sẽ hiển thị sai màu.
   - Thiếu toàn bộ bộ cỡ chữ (`text-h1`, `text-label`…), bộ khoảng cách (`px-md`, `gap-sm`…), màu cột Kanban, màu trạng thái công việc.
   - Sidebar rộng 280px thay vì 240px.
   - Topbar thiếu ô tìm kiếm chung và nút "Tạo mới".
   - Font icon Material Symbols không bật được kiểu icon tô đầy.
2. **Dữ liệu giả hiện như dữ liệu thật:**
   - Người: "Cơ sở Cầu Giấy" (14 view), "Nguyễn Văn A/An", "John Doe".
   - Liên hệ và tài khoản: SĐT "0912 345 678", tài khoản VCB 1029384756, SĐT trung tâm viết cứng.
   - Số liệu: điểm test 6.0/6.5/5.5, 48/12/36 buổi, 12.500.000đ, "24 bản ghi", tỷ lệ chuyên cần 96.8%, "Phòng id+100".
   - Nội dung soạn sẵn trong form: câu hỏi test mẫu, nội dung báo cáo trực lớp mẫu, 3 dòng giao việc mẫu.
3. **Bộ lọc và phân trang giả:**
   - Nhiều ô lọc không nằm trong form nên controller bỏ qua.
   - Tìm kiếm chỉ chạy trên trình duyệt, trong khi danh sách tải toàn bộ bản ghi.
   - Nút phân trang tĩnh.
4. **Nút giả:**
   - 7 nút dùng `alert()` thay cho chức năng thật.
   - Khoảng 42 nút không có xử lý.
   - Mọi nút "Xuất PDF / Tải phiếu lương / Xuất Excel" chỉ gọi `window.print()`.
5. **Không hiện lỗi nhập liệu:** nhiều form không hiển thị lỗi validate, nên người dùng không biết vì sao lưu không được.
6. **Tiêu đề còn chữ kỹ thuật** như "(Database)", "(#2)", "Draft/Approved". Có chỗ trạng thái hiện mã tiếng Anh thô: `pending_review`, `valid`.
7. **Menu lệch với quyền:**
   - Có mục hiện ra nhưng bấm vào bị báo không có quyền: KPI, lớp học với sale, đề test với Quản lý.
   - Ngược lại, nhiều trang không kiểm tra quyền nên ai biết đường dẫn cũng mở được.
   - Có mục menu bị trùng: chấm công lặp 5 lần.
8. **Chưa có giao diện điện thoại** cho portal trợ giảng / giáo viên. Mockup có thanh điều hướng dưới đáy, nhưng code đang dùng layout máy tính.
9. **View chết:** `payroll/periods-index`, `payroll/periods-show`, `tuition/receipts-approve`, `tuition/receipts-create`, `syllabus/big-test-distribution`, `syllabus/big-test-results` không được dùng ở đâu.

### B3. Lỗi bảo mật phát hiện thêm khi audit giao diện

| Vấn đề | Mức |
|---|---|
| Trang danh sách nhân sự nhúng **toàn bộ hồ sơ** mỗi người vào HTML: số CCCD, lương cơ bản, đơn giá. Ai có quyền xem danh sách đều đọc được | P0 |
| Trang "Hồ sơ học sinh (phân quyền)" chỉ là bản demo đổi vai trò phía trình duyệt, **dữ liệu học phí vẫn được gửi xuống** cho mọi người xem | P1 |
| Pipeline: tên khách được chèn vào đoạn JavaScript, nên tên có dấu nháy có thể chạy mã độc (XSS) | P1 |
| Thông báo toast chèn nội dung vào JavaScript không an toàn | P2 |

### B4. Khoảng cách lớn nhất theo từng nhóm

**CRM & học viên**
- **Pipeline:** không có bộ lọc; thiếu hạn liên hệ (Quá hạn / Sắp hết hạn), tên phụ huynh, "Sửa giai đoạn".
- **Chi tiết khách:** thiếu SĐT phụ huynh, "Phân công lại", "In hồ sơ", thẻ "Trạng thái & Hạn xử lý", checklist chăm sóc tháng đầu, bộ lọc nhật ký. Khối thang điểm test (khối lớp, tổng điểm, gợi ý lớp) đã có code nhưng không hiển thị.
- **Khách chốt thành công:** thiếu mục "Chờ xếp lớp" kèm nút "Gán lớp", thiếu bộ lọc, cột lớp, xuất file.
- **Chốt & xếp lớp:** thiếu "Xếp lớp sau", thẻ gợi ý lớp (ngưỡng khai giảng, "cần thêm N học viên"), ô "Đã đóng học phí".
- **Khách không chốt:** thiếu tìm kiếm, lọc ngày, xuất file, phân trang.
- **Hồ sơ học viên:**
  - Thiếu 3 trạng thái, lọc theo lớp, "Liên kết lớp khác".
  - Lộ trình buổi học và điểm danh lấy sai nguồn.
  - Người không có quyền vẫn thấy form sửa.
- **Nhập Excel:** chức năng giả và sai đối tượng (đang là nhập học phí, mockup là nhập khách hàng).

**Lịch & vận hành**
- **Dashboard lớp:** ma trận tuần là HTML tĩnh. Bộ lọc không chạy. Phòng, GVNN, giờ học là dữ liệu giả. Không có trạng thái điểm danh. Nút chấm công không theo buổi.
- **Cấu hình trình độ:** không có sửa/xóa, trạng thái ghi cứng. Thiếu nhóm trình độ, gắn Syllabus, thẻ thống kê, tìm kiếm.
- **TKB:** không sửa được lịch lớp đã có. Banner số lớp là dữ liệu giả. Báo cáo phòng/nhân sự không lọc được.
- **Portal trợ giảng:** không đúng "hôm nay", không có giao diện điện thoại. Admin xem thì bị gán vào một tài khoản TA viết cứng.
- **Bảng KPI tự động:** chuyên cần ghi cứng, kỳ báo cáo ghi cứng.

**Giáo trình & Big Test**
- **Tài liệu:** không có ô chọn file; chặng, đối tượng xem, dung lượng đều là dữ liệu giả; nút xóa không hoạt động.
- **Đề xuất sửa giáo trình, giáo viên xem tài liệu, giáo viên đề xuất:** hoàn toàn tĩnh.
- **Soạn giáo trình theo chặng:** thông tin chặng không lưu được; không sửa/xóa được bài; dòng "tự động lưu" là sai sự thật.
- **Duyệt đề Big Test:** không thấy order của giáo viên; thiếu ô link đề, hạn xử lý, nút từ chối.
- **Nhắc lịch Big Test:** mục đích khác mockup.
- **Kết quả Big Test:** trạng thái hiện mã tiếng Anh; chưa có gửi từng học viên, cột "Đã gửi PH", link video.

**Lương & chấm công**
- **Chưa có màn phiếu lương từng người**, trong khi cả 4 mockup chi tiết lương là của một người và sửa được từng khoản.
- **3 màn chi tiết lương hiện sai cột:** "Hoa hồng tuyển sinh" lấy số KPI, "Thưởng tái tục" lấy số phụ cấp, "R&D Giáo trình" lấy lương dạy.
- **Tổng cộng không khớp** vì thiếu cột hoa hồng.
- **Đơn giá giáo viên và mốc hoa hồng** làm khác mô hình mockup: không theo từng giáo viên, không có ngày hiệu lực, không có lịch sử.
- **Chấm công tay:** thiếu giờ vào/ra, không bắt buộc lý do, không chặn kỳ đã khóa.
- **Lịch sử đồng bộ:** luôn trống do đọc sai tên cột, và trạng thái ghi cứng "thành công".
- **Lương của tôi:** không chọn được kỳ, số liệu không khớp, dấu "đã xác thực" ghi cứng.
- **Danh sách phạt:** không có tìm kiếm, lọc theo bước, phân trang; bắt nhập số tiền ngay khi tạo biên bản.

**Học phí**
- **Luồng phiếu thu:** "Lưu nháp" bị lưu thành "Từ chối". Không sửa hoặc gửi lại được. Không bắt buộc minh chứng.
- **Lập phiếu thu:** phụ thu mặc định 150k, số buổi học ghi cứng.
- **Duyệt phiếu thu:** khi không có minh chứng thì vẽ một ủy nhiệm chi giả, và luôn hiện "Khớp số tiền".
- **Danh sách thu phí / quá hạn:** chưa chia nhóm ≥7 ngày / 1–6 ngày; thiếu "Đã liên hệ", "Báo cáo Admin", số ngày quá hạn.
- **Hoàn phí:** số liệu ghi cứng, thiếu "Đánh dấu khất nợ".
- **Dải số hóa đơn, nhắc nợ:** làm khác mockup. Mẫu tin nhắc nợ hướng dẫn sai biến, nên tin gửi đi còn nguyên `{TEN_HOC_VIEN}`.

**Cấu hình hệ thống**
- **Tài khoản:** dữ liệu giả; thiếu kiêm nhiệm, upload hợp đồng.
- **Phân quyền cá nhân:** thiếu phạm vi chi nhánh/lớp; bố cục khác mockup (danh sách thay vì ma trận Xem/Thêm/Sửa/Xóa).
- **Danh mục:** thiếu "Kích hoạt lại".
- **Nhật ký:** chưa có so sánh trước/sau, hoàn tác, lọc ngày, xuất Excel. Mỗi thao tác bị ghi 2 lần.

### B5. Xếp vào các phase
- **Phase 1:**
  - Nền giao diện chung (mục B2), vì mọi màn làm sau đều phụ thuộc.
  - Các lỗi bảo mật ở mục B3.
  - Giao diện nhóm CRM & học viên.
- **Phase 2:** giao diện nhóm Lịch & vận hành và Giáo trình & Big Test. Portal trợ giảng dạng điện thoại.
- **Phase 3:** giao diện nhóm Lương & chấm công, gồm màn phiếu lương từng người.
- **Phase 4:** giao diện nhóm Học phí và Cấu hình hệ thống. Gỡ view chết.

---

## Phần C — Kế hoạch 4 phase

**Nguyên tắc:**
- Mỗi phase làm trọn một luồng nghiệp vụ theo BPMN: màn hình theo mockup, dữ liệu, phân quyền và test.
- Kết thúc mỗi phase có một luồng chạy được thật để demo.
- Mỗi phase tách thành nhiều MR nhỏ.
- Các lỗi nghiêm trọng (P0) về bảo mật, lương, công nợ được sửa **trước tiên** trong Phase 1, vì đang gây rủi ro trên hệ thống thật (đã chốt).

### Phase 1: Chặn rủi ro khẩn + Từ khách hàng đến học viên vào lớp
*BPMN bước 1 → 4*

**1. Sửa lỗi nghiêm trọng (làm trước tiên)**
- **Bảo mật:**
  - Chặn nhân viên chiếm quyền tài khoản Admin.
  - Chặn upload file có thể chạy code.
  - Tắt các màn mockup cũ đang lộ dữ liệu học viên.
  - Ẩn ghi chú nội bộ ticket khỏi học viên.
  - Học viên và giáo viên không xem được lớp của người khác.
  - Ẩn khóa bí mật trong nhật ký thao tác, sau đó đổi lại khóa SePay và mật khẩu email.
- **Lương:** hoa hồng bị mất, đơn giá luôn 300k/giờ, phạt bị đánh dấu "đã trừ" sai, kỳ đã chốt vẫn nhận thêm dữ liệu.
- **Công nợ:** giảm giá/phụ thu tính sai, phiếu thu tự duyệt, hủy hóa đơn không hoàn nợ, hoàn phí làm tăng nợ, doanh thu tính cả phiếu chưa duyệt, nhắc nợ tự động không chạy.
- **Lịch học:** đổi giáo viên hoặc lưu TKB làm hỏng các buổi đã dạy.

**2. Nền giao diện chung** (xem Phần B)
- Áp dụng đúng bộ token của mockup: font Be Vietnam Pro lưu sẵn trên server, màu, cỡ chữ, khoảng cách, sidebar 240px, topbar có tìm kiếm.
- Bộ component dùng chung: nút, badge, bảng có phân trang, bộ lọc, form có hiện lỗi, trạng thái trống.
- Gỡ dữ liệu giả, sửa menu theo đúng quyền, bỏ chữ kỹ thuật trong tiêu đề.

**3. Test đầu vào**
- Mỗi khách một link test riêng có hạn dùng, không lộ thông tin, không sửa được khách khác.
- Chỉ Học vụ và Quản lý cơ sở nhập điểm, gồm cả phần Viết/Nói. Lưu câu trả lời của thí sinh.
- Chấm theo thang điểm đã chốt (Q2).

**4. Quản lý khách hàng**
- Quy trình theo BPMN: tư vấn → test → học thử → chốt, hoặc chuyển "không chốt" kèm lý do.
- Quản lý cơ sở và Học vụ chỉ thấy khách chi nhánh mình. Sale chỉ thấy khách được giao.
- Nhắc sale khi khách lâu không được chăm sóc. Kiểm tra SĐT. Khôi phục khách đã xóa. Khóa sửa hợp đồng sau khi chốt.

**5. Chốt khách và xếp lớp**
- Chốt khách: chọn lớp (kiểm tra còn chỗ) hoặc đưa vào lớp chờ.
- Học vụ gán lớp cho học viên từ danh sách "Chờ xếp lớp".
- Tạo hồ sơ học viên, tài khoản, sổ học phí. Có nút "Xác nhận chính thức".

**Màn hình (mockup):** Pipeline, Danh sách khách, Thêm/Sửa khách, Chi tiết khách, Khách không chốt, Báo cáo doanh số, Chốt & Xếp lớp, Khách chốt thành công, Xác nhận chính thức, Quản lý đề test, Tạo đề, Test online & thang điểm.

**Kết quả đạt được**
- Không còn lỗi nghiêm trọng nào. Lương và công nợ tính đúng ở những chỗ đang sai.
- Chạy trọn luồng: nhập khách → test online → học thử → chốt → học viên vào lớp hoặc vào lớp chờ.

### Phase 2: Vận hành lớp học
*BPMN bước 5 → 8, 10 → 13, 14, 21*

**1. Lớp và lịch học**
- Lịch học tự bỏ qua ngày nghỉ lễ.
- Cảnh báo trùng lịch giáo viên, GVNN, trợ giảng, phòng học.
- Dashboard lớp theo ngày/tuần dùng dữ liệu thật.

**2. Giảng dạy và điểm danh**
- Giáo viên xem lịch dạy theo ngày/tuần, điểm danh theo từng buổi.
- Học vụ điểm danh thay khi cần.
- Học viên vắng hoặc điểm dưới 7 được đưa vào danh sách bổ trợ, rồi xếp buổi bổ trợ.

**3. Giáo trình**
- Soạn bài, giao chặng cho lớp, tài liệu giáo trình.
- Giáo viên đề xuất sửa giáo trình, xin giãn tiến độ. Học thuật duyệt.

**4. Big Test**
- Giáo viên order đề, học thuật duyệt đề, nhắc lịch trước 7 ngày.
- Giáo viên chỉ nhập điểm lớp mình. Duyệt kết quả, gửi Zalo phụ huynh.

**5. Học viên**
- Hồ sơ có lộ trình và lịch sử điểm danh thật.
- Học viên xem được lịch, điểm danh, kết quả thi của mình.
- Chăm sóc học viên tháng đầu, sinh nhật.

**Màn hình (mockup):** Cấu hình trình độ, TKB, Dashboard lớp học, Ngày nghỉ, Hồ sơ học sinh (3 màn), Soạn syllabus, Tài liệu giáo trình, Đề xuất sửa GT, Giao chặng, Điều chỉnh tiến độ, Duyệt & phân phối đề, Nhắc lịch Big Test, Duyệt KQ & gửi PH.

**Kết quả đạt được**
- Chạy trọn luồng: mở lớp → sinh lịch → giao chặng → dạy và điểm danh → Big Test → phụ huynh nhận Zalo kết quả.

### Phase 3: Từ chấm công đến lương
*BPMN bước 9, 9b, 16, 17*

**1. Chấm công**
- Chỉ tính công cho buổi dạy có thật.
- Chấm công tay bắt buộc lý do, không chấm trùng.

**2. Kỷ luật**
- Ghi nhận vi phạm → giải trình → Học thuật/Quản lý chốt theo loại lỗi → nộp trong 2 ngày → quá hạn thì trừ lương.

**3. Hoa hồng**
- Chỉ tính cho khách mới, trên tổng tiền thực thu, gồm cả tiền giáo trình.
- Khi duyệt hoàn phí, người duyệt chọn có thu hồi hoa hồng hay không. Hệ thống gợi ý "có" nếu học dưới 1 tháng. Chuyển nhượng phí thì không thu hồi.

**4. Tính và duyệt lương**
- Tính đủ các khoản. Tính lại lương cho ra kết quả đúng.
- Kỳ đã duyệt thì khóa toàn bộ dữ liệu.
- Chỉ Giám đốc duyệt lương, Kế toán tính và soát xét.
- "Lương của tôi" chỉ hiện bảng lương đã duyệt. Nhân viên không tự chấm KPI của mình.

**Màn hình (mockup):** Chấm công thủ công, Chi tiết chấm công GV, Lịch sử đồng bộ, Danh sách vi phạm, Đơn giá GV, Mốc hoa hồng, Danh sách bảng lương, 4 màn chi tiết lương, BXH KPI, Lương của tôi.

**Kết quả đạt được**
- Lương một tháng tính trên hệ thống khớp với bảng Excel đang dùng.
- Chạy trọn luồng: buổi dạy → chấm công → phạt/hoa hồng/KPI → bảng lương → duyệt → nhân viên xem lương.

### Phase 4: Thu học phí, hỗ trợ và nghiệm thu
*BPMN bước 15, 15b, 18 → 20, 22*

**1. Học phí**
- Phiếu thu từng đợt: nháp → gửi duyệt → duyệt / trả về sửa.
- Dải số hóa đơn theo chi nhánh. Hủy hóa đơn gắn đúng phiếu thu.
- Hoàn phí, chuyển nhượng, bảo lưu, khất nợ.
- Nhắc nợ, danh sách quá hạn.

**2. Chuyển khoản**
- Tự đối soát, không ghi nhận 2 lần.
- Mã QR theo tài khoản của từng chi nhánh.

**3. Tài khoản và phân quyền**
- Đúng vai trò. Phân quyền cá nhân theo chi nhánh hoặc lớp. Menu theo quyền.
- Nhật ký có dữ liệu trước và sau khi sửa.
- Bắt đổi mật khẩu lần đầu. Cảnh báo hết hạn hợp đồng.

**4. Vận hành và hỗ trợ**
- Giao việc 2 chiều, trợ giảng 3 ca.
- Báo cáo trực lớp có bước duyệt. KPI tự động dùng số liệu thật.
- Ticket thông báo đúng người.

**5. Dashboard và nghiệm thu**
- Dashboard riêng cho Admin, Học thuật, Quản lý cơ sở.
- Kiểm tra lại toàn bộ luồng, người dùng thử, dọn dữ liệu giả.

**Màn hình (mockup):** DS thu phí, Lập / Duyệt phiếu thu, Lịch sử thu, Duyệt hủy HĐ, Hoàn tiền & khất nợ, Thu phí quá hạn, Dải số HĐ, Tài khoản NH, Nhắc nợ, Báo cáo doanh thu, Khoản chi, Tài khoản & vai trò, Phân quyền cá nhân, Danh mục, Nhật ký vận hành, 7 màn Phân công công việc.

**Kết quả đạt được**
- Chạy trọn luồng: lập phiếu thu → duyệt → xuất hóa đơn → công nợ về 0.
- Toàn bộ luồng BPMN 1–22 chạy được, sẵn sàng đưa vào sử dụng.

### Câu hỏi còn chờ trả lời

| Câu hỏi | Cần trước |
|---|---|
| Có cho sửa lùi bước, hủy chốt, mở lại khách "không chốt" không? (Q1) | Phase 1 |
| Cách tính điểm test đầu vào? Thang điểm cho học viên lớn? (Q2) | Phase 1 |
| Khách vào lớp chờ có thu cọc ngay không? | Phase 1 |
| Mô hình giáo trình (chặng hay bài)? Trạng thái học viên (4 hay 7)? (Q4, Q5) | Phase 2 |
| Công thức lương (Q3)? Hoa hồng "lần đầu" là cả khóa đầu hay chỉ đợt đóng đầu? "Hoàn phí ngay" là trong bao lâu? | Phase 3 |
| Báo cáo trực lớp: ảnh bảng có bắt buộc không, ai duyệt? (Q8) | Phase 4 |

### Rủi ro

| Rủi ro | Mức | Cách giảm |
|---|---|---|
| Phase 1 rất nặng: vừa sửa lỗi khẩn vừa làm luồng tuyển sinh | **Cao** | Làm P0 trước. Việc không kịp chuyển phase sau và ghi vào nhật ký, không bỏ sót |
| BA chốt chậm các câu hỏi còn lại | Cao | Tạm theo BPMN + mockup, chốt lại ở buổi demo cuối phase |
| Sửa lương và công nợ ảnh hưởng số liệu đang dùng | Cao | Sao lưu dữ liệu trước khi sửa, có script đối chiếu trước/sau, chạy song song với Excel |
| Phải đổi khóa SePay và mật khẩu email sau khi sửa lỗi lộ nhật ký | Trung bình | Phối hợp kế toán, đổi vào ngoài giờ |

---

## Phần D — Theo dõi tiến độ

> Cập nhật cuối mỗi phase. Trạng thái: ⬜ Chưa làm · 🟦 Đang làm · ✅ Xong · ⚠️ Trễ/Rủi ro

| Phase | Nội dung | Trạng thái | % hoàn thành | Ghi chú |
|---|---|---|---|---|
| 1 | Chặn rủi ro khẩn + Tuyển sinh → vào lớp | ⬜ | 0% | Chờ chốt Q1, Q2, cọc lớp chờ |
| 2 | Vận hành lớp học | ⬜ | 0% | Chờ chốt Q4, Q5 |
| 3 | Chấm công → Lương | ⬜ | 0% | Chờ chốt Q3, hoa hồng; cần bảng lương Excel để đối chiếu |
| 4 | Thu học phí, hỗ trợ, nghiệm thu | ⬜ | 0% | Chờ chốt Q8 |

### Nhật ký phase (điền sau mỗi phase)

```markdown
#### Phase X — <tên>
**Đã làm:**
- [x] ...
**Chưa làm / chuyển phase sau:**
- [ ] ... → lý do, chuyển sang Phase Y
**Quyết định phát sinh:** ...
**Lỗi còn tồn:** ...
**MR:** #...
```

---

## Phụ lục — Vị trí kỹ thuật các lỗi P0 (cho dev)

| # | File chính |
|---|---|
| 1 | `app/Http/Controllers/UserController.php` (update, resetPassword, updateRoles, lock) |
| 2 | `StudentPortalController.php` (homework, pronunciation), `WorkTaskController.php` (proof_image, board_image), `TuitionController.php` (upload phiếu thu, hủy HĐ), `public/.htaccess` |
| 3 | `routes/web.php` (academic-system, mockup-hub), `AcademicSystemController.php` |
| 4 | `resources/views/support-tickets/show.blade.php`, `NotificationService::notifyTicketMessage` |
| 5 | `config/access.php` (student/teacher `class.view`), `ClassManagementController.php`, route `academic/*` |
| 6 | `app/Http/Middleware/AuditOperationMiddleware.php` |
| 7–9 | `PlacementTestController.php` (portal take/submit), `portal-take.blade.php`, `portal-scorecard.blade.php` |
| 10–11 | `SyllabusController.php` (storeResults, sendZaloResults) |
| 12–15 | `app/Models/PayrollPeriod.php` (calculate), `PayrollRecord.php`, `PayrollController.php` (approvePeriod, storeTimesheet), `PenaltyController.php`, migration `teacher_timesheets.hourly_rate` |
| 16–21 | `create-receipt.blade.php`, `StudentTuition::recalculateDebt`, `TuitionController.php` (storeReceipt, cancellations, refunds), `FinanceController.php` (revenue), `SendDebtRemindersCommand.php` |
| 22–23 | `ClassManagementController::update`, `WorkTaskController::updateScheduleConfig` |
