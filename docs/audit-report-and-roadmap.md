# MEnglish — Báo cáo Audit & Kế hoạch thực hiện 4 tuần

> **Ngày:** 25/09/2026 · **Người lập:** CTO
> **Chuẩn đối chiếu:** BPMN (2 file `.drawio`), `tai-lieu-su-dung-flow-tinh-nang.html` (gọi tắt: *flow doc*), `test-cases-unitest-flows.html`, `unitest-crm.xlsx`, `Thang điểm + hướng dẫn nhận xét.html`, `erp-database-schema.html`, mockup `code.html`.
> **Phương pháp:** đọc code, lần theo từng luồng route → controller → model → view. **Chưa chạy test hay chạy thử ứng dụng.** Mọi lỗi cần được kiểm chứng lại bằng test khi sửa.

---

## Phần A — Báo cáo Audit

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
| 25/09/2026 | Thứ tự sửa | Sửa toàn bộ lỗi P0 (bảo mật, lương, công nợ) trong 2 ngày đầu tuần 1 |
| 25/09/2026 | Q2 — Điểm test | Là **test đầu vào**. Cách tính (trung bình 4 kỹ năng hay tổng theo khối lớp) đang chờ BA trả lời tiếp |
| 25/09/2026 | Q6 — Chốt khách | Khi chốt được **chọn lớp** (kiểm tra còn chỗ) **hoặc đưa vào lớp chờ** nếu chưa có lịch khớp. Học vụ gán lớp sau từ danh sách "Chờ xếp lớp" |
| 25/09/2026 | Q6 — Hoa hồng | Hoa hồng tính trên **tổng tiền thực thu** (phiếu thu đã duyệt), **gồm cả tiền giáo trình, đồ dùng**. Không tính trên giá trị hợp đồng |
| 25/09/2026 | Q7 — Nhập điểm test | Chỉ **Học vụ** và **Admin cơ sở** nhập điểm test đầu vào, gồm cả phần Viết/Nói của bài test online. Sale và giáo viên không nhập |
| 25/09/2026 | Q7 — Xem khách | Admin cơ sở / Học vụ **chỉ thấy khách của chi nhánh mình**. Admin tổng thấy tất cả. Sale chỉ thấy khách được giao |
| 25/09/2026 | Q7 — Vai trò | "Admin cơ sở" chính là vai trò **Quản lý cơ sở** (`manager`) |

**Còn chờ trả lời:**
- **Q1:** có cho sửa lùi bước, hủy chốt, mở lại khách không chốt không?
- **Q2:** cách tính điểm test đầu vào; thang cho học viên lớn (THCS, IELTS, người đi làm).
- **Q3:** công thức lương.
- **Q4:** mô hình giáo trình.
- **Q5:** trạng thái học viên.
- **Q6:** khách vào lớp chờ có thu cọc ngay không.
- **Hoa hồng:** tính theo tháng thực thu? Có trừ lại khi hoàn phí? Có dùng tỷ lệ riêng cho tái tục không?
- **Q8:** báo cáo trực lớp.

---

## Phần B — Kế hoạch 4 tuần (28/09 – 25/10/2026)

**Nguyên tắc:**
- Mỗi giai đoạn làm trọn một luồng nghiệp vụ theo BPMN, từ màn hình đến dữ liệu, phân quyền và test, bám mockup.
- Cuối mỗi giai đoạn có một luồng chạy được thật để demo.
- Mỗi giai đoạn tách thành nhiều MR nhỏ.
- **Ngoại lệ:** các lỗi P0 về bảo mật và tiền đưa lên sửa ngay đầu tuần 1, vì đang gây rủi ro trên hệ thống thật (chờ xác nhận, xem mục B6).

### B1. Giai đoạn 1 (28/09 – 04/10): Chặn rủi ro khẩn + Từ khách hàng đến học viên vào lớp
*BPMN bước 1 → 4*

**Ngày 1–2: chặn rủi ro khẩn**
- Chặn chiếm quyền tài khoản Admin.
- Làm an toàn mọi chỗ upload file.
- Tắt các màn mockup cũ trên hệ thống thật.
- Ẩn ghi chú nội bộ ticket với học viên.
- Thu hẹp quyền xem lớp của học viên và giáo viên.
- Ẩn khóa bí mật trong nhật ký, rồi đổi lại khóa SePay và mật khẩu email.
- Sửa 4 lỗi tính sai lương (P0 #12–15) và 6 lỗi sai công nợ (P0 #16–21).

**Ngày 3–7: luồng tuyển sinh**
- Trang làm test online an toàn: mỗi khách một link riêng có hạn dùng, bỏ đáp án viết cứng, không lộ thông tin, không sửa được khách khác.
- Chấm test theo thang điểm đã chốt (Q2). Giáo viên chấm phần Viết/Nói. Lưu câu trả lời.
- Quản lý khách đúng các bước BPMN: tư vấn → test → học thử → chốt, hoặc đưa vào danh sách chờ lớp, hoặc chuyển "không chốt" kèm lý do.
- Nhắc sale khi khách lâu không được chăm sóc.
- Kiểm tra SĐT. Khôi phục khách đã xóa. Khóa sửa hợp đồng sau khi chốt. Ghi lịch sử mọi thay đổi.
- Màn chốt khách:
  - Chạy được cho mọi trường hợp: khách vừa test, khách đã học thử, khách đang chờ lớp.
  - Tạo hồ sơ học viên, tài khoản, xếp lớp (kiểm tra sĩ số, hiện "cần thêm N học viên").
  - Tạo sổ học phí.
- Nút "Xác nhận chính thức" để học vụ xác nhận học viên vào lớp.
- Bộ sinh mã chứng từ dùng chung. Mỗi học viên chỉ lưu thuộc lớp nào ở một nơi.

**Màn hình (mockup):** Pipeline, Danh sách khách, Thêm/Sửa khách, Chi tiết khách, Khách không chốt, Báo cáo doanh số, Chốt & Xếp lớp, Khách chốt thành công, Xác nhận chính thức, Quản lý đề test, Tạo đề, Test online & thang điểm.

**Hoàn thành khi:** demo được từ lúc nhập khách mới, làm test online, học thử, chốt, đến học viên có tên trong lớp. Hết toàn bộ P0.

### B2. Giai đoạn 2 (05/10 – 11/10): Vận hành lớp học
*BPMN bước 5 → 8, 10 → 13, 14, 21*

- Sửa lỗi đổi giáo viên hoặc lưu lại TKB làm hỏng các buổi đã dạy.
- Lịch học tự bỏ qua ngày nghỉ lễ. Sửa ngày nghỉ thì lịch tự cập nhật.
- Cảnh báo trùng lịch giáo viên, GVNN, trợ giảng, phòng học.
- Trình độ: không cho xóa khi đang có lớp dùng, chỉ cho ngừng hoạt động.
- Giáo viên xem lịch dạy theo ngày/tuần.
- Điểm danh theo từng buổi, khóa sau 24 giờ. Học vụ điểm danh thay khi cần.
- Học viên vắng hoặc điểm dưới 7 được đưa vào danh sách bổ trợ. Xếp buổi bổ trợ.
- Giáo trình:
  - Soạn giáo trình theo mô hình đã chốt (Q4).
  - Giao chặng cho lớp, mỗi lớp 1 chặng đang học.
  - Tải tài liệu, chọn ai được xem, khóa tải về.
  - Giáo viên đề xuất sửa giáo trình, học thuật duyệt.
  - Xin giãn tiến độ, duyệt xong thì lịch tự thay đổi.
- Big Test:
  - Giáo viên order đề, học thuật duyệt đề có hạn xử lý.
  - Nhắc lịch trước 7 ngày.
  - Giáo viên chỉ nhập điểm lớp mình.
  - Duyệt kết quả, gửi Zalo phụ huynh 1 lần và báo đúng nếu gửi lỗi.
- Dashboard lớp theo ngày và ma trận tuần, dùng dữ liệu thật.
- Hồ sơ học viên: đủ trạng thái (Q5), lộ trình buổi học và lịch sử điểm danh thật. Người không có quyền chỉ xem được.
- Portal học viên: xem lịch, điểm danh, kết quả thi của mình. Gỡ bỏ dữ liệu giả.
- Chăm sóc học viên tháng đầu, sinh nhật.

**Màn hình (mockup):** Cấu hình trình độ, TKB, Dashboard lớp học, Ngày nghỉ, Hồ sơ học sinh (3 màn), Soạn syllabus, Tài liệu giáo trình, Đề xuất sửa GT, Giao chặng, Điều chỉnh tiến độ, Duyệt & phân phối đề, Nhắc lịch Big Test, Duyệt KQ & gửi PH.

**Hoàn thành khi:** demo được từ lúc mở lớp, sinh lịch, giao chặng, dạy và điểm danh, Big Test, đến phụ huynh nhận Zalo kết quả.

### B3. Giai đoạn 3 (12/10 – 18/10): Từ chấm công đến lương
*BPMN bước 9, 9b, 16, 17*

- Chấm công chỉ tính cho buổi dạy có thật và đúng người dạy. Chấm công tay bắt buộc lý do. Không chấm trùng buổi.
- Lịch sử đồng bộ chấm công hiển thị đúng.
- Quy trình phạt: ghi nhận vi phạm → giải trình → HT chốt lỗi học thuật / CM chốt lỗi vận hành → nộp trong 2 ngày → quá hạn thì trừ lương.
- Tính lương theo công thức đã chốt (Q3):
  - Đơn giá riêng từng giáo viên, dạy thay.
  - Hoa hồng tuyển sinh và thưởng tái tục.
  - KPI, phụ cấp, bảo hiểm, thuế.
- Tính lại lương cho ra kết quả đúng. Kỳ đã duyệt thì khóa toàn bộ dữ liệu nguồn.
- Chỉ Giám đốc duyệt lương. Kế toán tính và soát xét.
- "Lương của tôi": chỉ hiện bảng lương đã duyệt, có chọn kỳ, cộng trừ khớp với thực nhận.
- Bảng xếp hạng KPI & hoa hồng dùng số liệu của đúng kỳ, không lộ lương.
- Không cho tự chấm KPI của mình.

**Màn hình (mockup):** Chấm công thủ công, Chi tiết chấm công GV, Lịch sử đồng bộ, Danh sách vi phạm, Đơn giá GV, Mốc hoa hồng & tái tục, Danh sách bảng lương, 4 màn chi tiết lương, BXH KPI, Lương của tôi.

**Hoàn thành khi:** chạy song song lương tháng 09/2026 trên hệ thống và trên Excel, số liệu khớp nhau.

### B4. Giai đoạn 4 (19/10 – 25/10): Thu học phí, hỗ trợ và nghiệm thu
*BPMN bước 15, 15b, 18 → 20, 22*

- Học phí:
  - Lập phiếu thu có thu từng đợt.
  - Nháp → gửi duyệt → duyệt / trả về sửa.
  - Dải số hóa đơn theo chi nhánh, không cấp lại số đã dùng.
  - Hủy hóa đơn phải gắn với đúng phiếu thu.
  - Hoàn phí, chuyển nhượng, bảo lưu, khất nợ.
  - Nhắc nợ theo cấu hình. Danh sách quá hạn có "đã liên hệ" / "báo Admin".
- Chuyển khoản tự đối soát, khớp với phiếu đang chờ, không ghi 2 lần. Tài khoản ngân hàng và mã QR theo chi nhánh.
- Tài khoản và phân quyền:
  - Đúng 8 vai trò (Q7).
  - Phân quyền cá nhân theo chi nhánh hoặc lớp.
  - Quản lý chỉ thấy chi nhánh mình.
  - Menu theo quyền.
  - Nhật ký thao tác có dữ liệu trước và sau khi sửa.
  - Bắt đổi mật khẩu lần đầu.
  - Cảnh báo hết hạn hợp đồng.
- Giao việc 2 chiều, trợ giảng 3 ca, thông báo khi được giao việc. Báo cáo trực lớp có bước duyệt. KPI tự động dùng dữ liệu thật.
- Ticket: đúng mã TK-, thông báo đúng người, file đính kèm cần đăng nhập mới xem được.
- Dashboard riêng cho Admin, Học thuật, Quản lý cơ sở (BPMN 22).
- Nhập khách hàng từ Excel (nếu chưa làm ở giai đoạn 1).
- **Nghiệm thu:** kiểm tra lại toàn bộ luồng chính, người dùng thử, sửa lỗi phát sinh, dọn file thừa và dữ liệu giả.

**Màn hình (mockup):** DS thu phí, Lập / Duyệt phiếu thu, Lịch sử thu, Duyệt hủy HĐ, Hoàn tiền & khất nợ, Thu phí quá hạn, Dải số HĐ, Tài khoản NH, Nhắc nợ, Báo cáo doanh thu, Khoản chi, Tài khoản & vai trò, Phân quyền cá nhân, Danh mục, Nhật ký vận hành, 7 màn Phân công công việc.

**Hoàn thành khi:** demo được từ lúc lập phiếu thu, duyệt, xuất hóa đơn, đến công nợ về 0. Toàn bộ luồng BPMN 1–22 chạy được.

### B5. Rủi ro

| Rủi ro | Mức | Cách giảm |
|---|---|---|
| Khối lượng lớn (162 đầu lỗi + phần còn thiếu) trong 4 tuần | **Cao** | Làm P0 trước. Việc không kịp chuyển giai đoạn sau và ghi vào nhật ký giai đoạn, không bỏ sót |
| BA chốt chậm các câu hỏi Q1–Q8 | Cao | Tạm theo BPMN + mockup, chốt lại trong buổi demo cuối tuần |
| Sửa lương và công nợ ảnh hưởng số liệu đang dùng | Cao | Sao lưu dữ liệu trước khi sửa, có script đối chiếu trước/sau, chạy song song với Excel |
| Chưa có môi trường chạy test | Trung bình | Dựng môi trường test bằng Docker ngay ngày 1 |
| Phải đổi khóa SePay và mật khẩu email sau khi sửa lỗi lộ nhật ký | Trung bình | Phối hợp kế toán, đổi vào ngoài giờ |

### B6. Cần quyết định ngay
1. **Đồng ý đưa P0 lương và công nợ lên đầu tuần 1** (đề xuất của CTO) hay giữ đúng giai đoạn của luồng?
2. **Lịch chốt các câu hỏi Q1–Q8.** Đề xuất họp BA ngày 28–29/09.

---

## Phần C — Theo dõi tiến độ

> Cập nhật cuối mỗi giai đoạn. Trạng thái: ⬜ Chưa làm · 🟦 Đang làm · ✅ Xong · ⚠️ Trễ/Rủi ro

| Giai đoạn | Thời gian | Trạng thái | % hoàn thành | Ngày demo | Ghi chú |
|---|---|---|---|---|---|
| 1. Chặn rủi ro khẩn + Tuyển sinh → vào lớp | 28/09 – 04/10 | ⬜ | 0% | 04/10 | Chờ chốt Q1, Q2, Q6, Q7 |
| 2. Vận hành lớp học | 05/10 – 11/10 | ⬜ | 0% | 11/10 | Chờ chốt Q4, Q5 |
| 3. Chấm công → Lương | 12/10 – 18/10 | ⬜ | 0% | 18/10 | Chờ chốt Q3; cần bảng lương Excel tháng 09 |
| 4. Thu học phí, hỗ trợ, nghiệm thu | 19/10 – 25/10 | ⬜ | 0% | 25/10 | Chờ chốt Q8 |

### Nhật ký giai đoạn (điền sau mỗi giai đoạn)

```markdown
#### Giai đoạn X — <tên> (dd/mm – dd/mm)
**Đã làm:**
- [x] ...
**Chưa làm / chuyển giai đoạn sau:**
- [ ] ... → lý do, chuyển sang giai đoạn Y
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
