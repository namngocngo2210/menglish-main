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
| 25/09/2026 | Q1 — Học thử (bổ sung) | Khách **chưa chốt** vẫn được học thử 1–2 buổi của lớp thật đúng trình độ. GV nhận xét như học sinh chính thức, nhận xét lưu theo **khách** (khach_id → tuyen_sinh), không theo hoc_sinh_id. Hồ sơ học sinh chỉ tồn tại **sau khi chốt**; bỏ trạng thái "Học thử" khỏi hồ sơ học sinh (Epic 6) |
| 25/09/2026 | Q1 — Lùi bước / Hủy chốt / Thất bại (bản sửa) | CM chỉ chuyển **tiến từng bước**. Chỉ **Admin** sửa lùi, bắt buộc lý do, ghi nhat_ky_tuyen_sinh. **Không có Hủy chốt**. Chỉ khách **chưa chốt** mới chuyển sang Thất bại; khách đã chốt không có luồng quay ngược. Khách Thất bại **không mở lại**, giữ để audit |
| 25/09/2026 | Q2 — Cách chấm test đầu vào | Theo file "Thang điểm + hướng dẫn nhận xét" (dữ liệu: `database/scripts/rubric_dump.json`). **Chấm theo khối lớp**: cộng Listening + Reading&Writing + Speaking, tra **tổng điểm** ra lớp đề xuất (Pre-Starters FAM 0, Starters FAM 1, FAM 2, Luyện Movers…). Speaking luôn nhập tay. Nhận xét **gợi ý tự động theo băng điểm**, GV/CM sửa được. Cho phép **chọn lại lớp đề xuất** khi đánh giá. **Bỏ** cách "trung bình 4 kỹ năng thang 10 → A1–C1" (không có trong tài liệu) |
| 25/09/2026 | Q6 — Cọc lớp chờ | Không có khái niệm "cọc". Chốt không bắt buộc đã đóng phí; da_dong_hoc_phi = false → tự tạo task nhắc thu phí cho người phụ trách. Được chốt khi chưa có lớp rồi xếp lớp sau |
| 25/09/2026 | Q4 — Mô hình giáo trình | Phân cấp **Giáo trình** (gắn Trình độ) → **Chặng** (có Big Test cuối chặng) → **Unit** → **Buổi**. Mỗi lớp chỉ mở **1 chặng** tại một thời điểm. Chặng **đóng khi Big Test được duyệt và gửi**, chặng kế tiếp **tự mở** |
| 25/09/2026 | Q3 — Công thức lương Part-time | Tổng = **số buổi × đơn giá riêng từng GV** + **KPI theo số HS giữ được** (15k / 20k / 25k mỗi HS mỗi tháng, Admin chọn bậc tay) + lương buổi có GVNN (chờ làm rõ) + phụ cấp tự do (hỗ trợ thỏa thuận, gửi xe, thưởng khác) − các khoản trừ. Part-time **không** trừ BHXH/Công đoàn. Bỏ quy tắc "+1 triệu khi ≥ 40 giờ, phụ cấp 500k" (không có trong spec) |
| 25/09/2026 | Q3 — Công thức lương Full-time | GV Full-time / Học vụ / Học thuật: **Lương cơ bản + các khoản cộng − BHXH 10,5% − Công đoàn 0,5% − Thuế TNCN − trừ vi phạm**. BHXH, Công đoàn tính tự động trên lương cơ bản; Thuế TNCN Admin nhập tay. KPI: GV Full-time & Học thuật **nhập tự do**; Học vụ **tự động theo KPI 6 nhóm / 15 mục, quỹ 2 triệu/tháng** |
| 25/09/2026 | Hoa hồng tuyển sinh (bản sửa) | Hoa hồng = **% theo bậc** (bậc theo **số HS chốt trong kỳ**, Admin cấu hình, mặc định 3% / 4% / 5%) × **doanh thu tuyển sinh thật**. Hệ thống tự tính, Admin không sửa tay. **Gate kép**: đủ **30 ngày từ ngày chốt** và **tick đủ 3/3 mốc chăm sóc** (buổi 1, buổi 4–5, đủ 30 ngày); thiếu điều kiện → **hoãn sang kỳ sau**, không mất |
| 25/09/2026 | Thưởng tái tục | Khoản **riêng**: % theo số HS nghỉ trong lớp phụ trách (giữ đủ 100% → 1%, nghỉ 1 HS → 0,7%…) × doanh thu lớp |
| 25/09/2026 | Hoàn phí | Xử lý **trong 1 tuần** và **trong cùng tháng phát sinh**. **Admin** duyệt. **Ưu tiên chuyển nhượng** buổi dư, hoàn tiền là phương án cuối. Hoàn tiền **bắt buộc ảnh bằng chứng**. Quá hạn → gắn cờ **"Quá hạn xử lý"**, không chặn nút duyệt |
| 25/09/2026 | Q8 — Báo cáo trực lớp | Ảnh **không bắt buộc**. Có ≥ 1 ảnh → đầu việc "Trực lớp" tự **Hoàn thành**. Không ảnh → **Chờ xác nhận**, GV chính của lớp xác nhận; lớp chưa có GV chính thì **người giao việc** xác nhận |

**Còn chờ trả lời:**
- **Q2 — Học viên lớn:** chưa có thang điểm. File thang điểm chỉ có 4 khối (Khối 1-2 → Khối 4 lên 5); "Phân loại bài test" có bài cho lớp 5–9 (lớp 8–9 chỉ 3 kỹ năng, không nói) nhưng chưa có băng điểm / mapping lớp. Chờ Học thuật / chị Kiều Liên bổ sung.
- **Q4 — Cấu trúc bảng:** chang_buoi_hoc / buoi_giao_trinh có cần bảng UNIT riêng không; NOI_DUNG_BUOI_HOC đang tự đánh số (so_unit, so_buoi), chưa nối buoi_giao_trinh_id — hai hệ đánh số phải khớp. Chờ Mai Le Quel Owen / Nam Ngo xác nhận.
- **Q3 — Lương buổi có GVNN** (Part-time): chờ làm rõ với Mai Le Quel Owen.
- **Thưởng tái tục:** bảng % đầy đủ theo số HS nghỉ (mới có ví dụ 100% → 1%, nghỉ 1 → 0,7%).

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

| Phase | Nội dung | Trạng thái | % hoàn thành (ước lượng) | Ghi chú |
|---|---|---|---|---|
| 1 | Chặn rủi ro khẩn + Tuyển sinh → vào lớp | ✅ | 100% (phạm vi đã chốt) | Xong: 23 P0, CRM pipeline 8 bước + luật lùi bước/Thất bại/không hủy chốt, test đầu vào chấm theo khối lớp (Q2), học thử nhận xét theo khách, chốt & xếp lớp / Chờ xếp lớp / xác nhận chính thức, hồ sơ học viên, bộ sinh mã, nền giao diện + quét dữ liệu giả (B2), đối chiếu 12 màn mockup, dữ liệu demo + test nghiệm thu trọn luồng (725 test pass). **Chờ BA:** thang điểm học viên lớn (Q2); Học vụ có được chốt khách (`lead.convert`) không. Các mục "chưa làm" có lý do: xem nhật ký `feat/phase1-mockup-parity` |
| 2 | Vận hành lớp học | ✅ | 100% (phạm vi đã chốt) | Xong: lịch/TKB/dashboard lớp, GVNN, nghỉ lễ (+ xếp bù), trình độ, Giáo trình → Chặng → Unit → Buổi (1 chặng mở, tự đóng/mở theo Big Test), điểm danh theo buổi, bổ trợ tự động, Big Test (order → duyệt tạo đợt thi → nháp/gửi duyệt → gửi PH), SĐT phụ huynh, portal GV (mobile) / TA / học viên, chăm sóc tháng đầu 3 mốc, đối chiếu mockup toàn bộ màn Phase 2, dữ liệu demo + test nghiệm thu (757 test pass). **Chờ BA / người dùng:** Zalo OA/ZNS thật (token, template); Quản lý cơ sở có giữ quyền duyệt Big Test/giáo trình không; "Buổi 4–5" tính buổi học viên đi học hay buổi của lớp; ngày chốt cho học viên không qua CRM; Học vụ có bị giới hạn chi nhánh không |
| 3 | Chấm công → Lương | 🟦 | ~80% | Xong: chấm công tay, quy trình phạt, phiếu lương từng người, hoa hồng theo tiền thực thu; **công thức lương theo BA (Q3)**: Part-time (buổi × đơn giá buổi riêng + KPI giữ HS + GVNN nhập tay + phụ cấp tự do), Full-time (lương cơ bản + KPI / hoa hồng / tái tục − BHXH 10,5% − Công đoàn 0,5% − TNCN tay), KPI Học vụ tự động 6 nhóm / 15 mục, hoa hồng theo bậc số HS chốt + gate kép (hoãn, trả kỳ sau), thưởng tái tục theo lớp (nhánh `feat/phase3-formula`). **Chờ BA:** lương buổi có GVNN, bảng % tái tục đầy đủ, định nghĩa "số HS giữ được", ngưỡng bậc hoa hồng. Cần bảng lương Excel để đối chiếu |
| 4 | Thu học phí, hỗ trợ, nghiệm thu | 🟦 | ~55% | Xong: học phí (dải số theo chi nhánh, bảo lưu/khất nợ, quá hạn, chống trùng chuyển khoản), nhật ký, phân quyền cá nhân, giao việc, ticket, dashboard vai trò, dọn view chết. Chờ tới lượt: hoàn phí 1 tuần, trực lớp (Q8), đối chiếu mockup, nghiệm thu |

> Cập nhật 25/09/2026. Cách làm đã chốt: **làm trọn từng phase theo thứ tự**; chỉ làm việc của phase sau khi phase trước phụ thuộc vào nó.

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

#### Phase 1 — Sửa P0 bảo mật #1–#6 + B3 (nhánh `fix/p0-security`)
**Đã làm:**
- [x] #1 Học vụ/Quản lý không thao tác được trên tài khoản có vai trò cao hơn (Admin): sửa, khóa, reset, đổi vai trò, xóa, xem
- [x] B3 Danh sách/chi tiết nhân sự không còn nhúng CCCD, lương, đơn giá cho người không có quyền xem lương
- [x] #2 `SafeUploadService` (đuôi theo nội dung, danh sách cho phép, tên ngẫu nhiên) cho bài tập, ghi âm, minh chứng, ảnh bảng, phiếu thu, hủy HĐ, CV; `.htaccess` chặn chạy code trong thư mục upload
- [x] #3 Màn mockup cũ: chỉ Admin xem bản mockup; vai trò khác chuyển sang màn thật hoặc 403; API mockup chỉ Admin, bỏ miễn CSRF
- [x] #4 Ghi chú nội bộ ticket ẩn với người tạo, không gửi thông báo/email cho họ
- [x] #5 Giáo viên/trợ giảng chỉ thấy lớp mình dạy; học viên không vào màn quản lý lớp
- [x] #6 Nhật ký che mật khẩu/khóa/token/CCCD (đệ quy); migration che log cũ
**Việc vận hành:** sau deploy phải **đổi khóa SePay và mật khẩu email** (đã lộ trong log cũ).

#### Phase 1 — Hồ sơ học viên + bộ sinh mã dùng chung (nhánh `feat/phase1-students`)
**Đã làm:**
- [x] A4.1 — Bộ sinh mã dùng chung `App\Services\DocumentCodeGenerator` + bảng `document_sequences` (tăng trong transaction, khóa dòng; tự khởi tạo từ mã lớn nhất đang có, kể cả bản ghi đã xóa mềm). Áp dụng cho mã học viên `HV-00001`, Big Test `BT-YYYY-0001`, ticket `TK-YYYY-0001` (trước là 4 chữ số — sửa theo tài liệu schema; ticket cũ giữ mã cũ).
- [x] Trang "Hồ sơ học sinh (phân quyền)" render phía server theo quyền thật: học phí chỉ khi có `tuition.view`, bỏ số giả 12.500.000đ, bỏ đổi vai trò phía trình duyệt. Id/mã không tồn tại → 404 (cả trang chi tiết).
- [x] Q7 — Danh sách/chi tiết/sửa/đổi trạng thái học viên giới hạn theo chi nhánh cho Quản lý cơ sở, Học vụ (Admin thấy tất cả); GV/TA chỉ học viên lớp mình. Thêm lọc theo lớp (gồm học viên liên kết), giữ lọc 6 trạng thái.
- [x] Chi tiết học viên: lộ trình = buổi học thật của lớp học viên + điểm danh của chính học viên, có trạng thái trống; form sửa chỉ hiện khi có `student.update`; badge vai trò thật; khối học phí chỉ hiện khi có `tuition.view`; "Liên kết lớp khác" (kiểm tra cùng chi nhánh, trùng lớp, sĩ số) cho người có `student.assign_class`.
- [x] Q5 — Tạo hồ sơ mới khởi tạo "Chờ khai giảng". Thôi học → bỏ `current_class_id`, lượt xếp lớp chuyển `dropped` (không tính sĩ số), giữ điểm danh/học phí.
**Chưa làm / chuyển phase sau:**
- [ ] Biên bản phạt (`Penalty::generateCode`) và số hóa đơn chưa dùng bộ sinh mã → nhóm lương/học phí chuyển sang. ✅ Vòng 2
- [ ] Mã học viên khi chốt khách (CRM) vẫn dạng ULID `HV-01J…` — giữ nguyên, nhóm CRM quyết định.
- [ ] A4.7 (lớp lưu ở 2 nơi): danh sách lớp/điểm danh vẫn đọc `current_class_id`, nên học viên "liên kết lớp khác" chưa hiện trong điểm danh của lớp liên kết → Phase 2.
**Quyết định phát sinh:** Accountant/Trưởng học vụ được coi là vai trò theo chi nhánh (như Quản lý cơ sở) khi xem học viên.
**Lỗi còn tồn:** —
**MR:** —

#### Phase 2 — Giáo trình & Big Test (nhánh `feat/phase2-syllabus`)
**Đã làm:**
- [x] Soạn syllabus: chọn giáo trình bất kỳ, tạo/sửa/xóa giáo trình và bài (chặn trùng số buổi), lưu thông tin chặng (tên chặng, chính sách mở khóa, link tổng quan). Bỏ dòng "tự động lưu". Sửa bài chỉ dành cho quyền `syllabus.manage` (GV đi qua đề xuất sửa).
- [x] Giao chặng: lớp đang có chặng `in_progress` thì không giao chặng mới; thêm nút "Hoàn thành" để đóng chặng hiện tại.
- [x] Tài liệu giáo trình: upload file thật qua `SafeUploadService` (pdf, doc(x), ppt(x), xls(x), ảnh, mp3/wav/m4a/ogg, mp4/mov/webm; ≤100 MB) lưu disk private `storage/app/private/syllabus_documents`; lưu loại/dung lượng thật, chặng, đối tượng xem (GV / TG) và quyền tải; xem qua route có kiểm tra quyền (không tải được thì chỉ xem inline); xóa xóa cả file. Màn GV xem tài liệu đọc dữ liệu thật.
- [x] Đề xuất sửa giáo trình: bảng `syllabus_change_proposals` (giáo trình, bài, nội dung cũ/mới, lý do, file đính kèm). Học thuật (`syllabus.approve_adjustment`) duyệt / từ chối bắt buộc lý do; GV chỉ thấy đề xuất của mình; có thông báo cho GV.
- [x] Giãn tiến độ: lưu lý do từ chối (bắt buộc). Khi duyệt, nếu yêu cầu có "số buổi cần thêm" N thì hệ thống **thêm N buổi chính khóa nối tiếp sau buổi cuối của lớp** theo ca lặp tuần trong TKB (hoặc suy ra từ các buổi gần nhất), bỏ qua ngày nghỉ lễ, chặn trùng phòng/nhân sự, rồi lùi `classes.end_date`. Không thêm được (chưa có TKB, trùng lịch) thì không duyệt. N = 0 chỉ ghi nhận.
- [x] Media Manager chỉ quản lý `public/uploads/media` (whitelist). CV ứng viên, minh chứng công việc, bài nộp, ghi âm, báo cáo lớp, phiếu thu học phí, file ticket (`uploads/YYYY/MM/DD`), tài liệu giáo trình… nằm ngoài vùng này nên không liệt kê / di chuyển / xóa được.
- [x] Order đề: GV order lưu bảng `big_test_orders` (kèm ngày thi dự kiến, hạn xử lý = ngày thi − 3 ngày); màn Duyệt & phân phối đề hiện order, hạn xử lý / trễ hạn, duyệt bắt buộc link đề, từ chối bắt buộc lý do. Order cũ trong `academic_records` (ORDTEST-) được chuyển sang bảng mới khi migrate. Danh sách đợt thi / nhắc lịch / order chỉ hiện lớp của GV.
- [x] Nhắc lịch Big Test trước 7 ngày: lệnh `bigtests:remind-upcoming` chạy 07:45 hằng ngày, báo GV/GVNN/TG của lớp (đề chưa duyệt thì báo thêm Học thuật), mỗi đợt thi chỉ nhắc 1 lần (`big_tests.teacher_reminded_at`). Nút nhắc học viên thủ công giữ nguyên.
- [x] Kết quả Big Test: cờ "Vắng thi" (điểm để trống, không còn điểm 0; không tính vào điểm trung bình, không gửi Zalo), link video bài thi, nút "Gửi PH" từng học viên, cột "Đã gửi PH". Cổng HV hiện kết quả đã duyệt **hoặc** đã gửi.
**Chưa làm / chuyển phase sau:**
- [ ] Q4 (chặng hay bài) chưa chốt → giữ mô hình giáo trình → bài; bài chưa gắn vào từng buổi học nên giãn tiến độ = thêm buổi cuối khóa.
- [ ] Duyệt đề xuất sửa giáo trình chưa tự áp nội dung mới vào bài / chưa tăng phiên bản — Học thuật sửa tay ở màn Soạn syllabus.
- [ ] Duyệt order đề không tự tạo đợt Big Test (chỉ gắn vào đợt thi có sẵn nếu chọn).
**Quyết định phát sinh:** Hạn xử lý order = ngày thi − 3 ngày (theo mockup SLA). Media Manager chỉ sở hữu `uploads/media`.
**Triển khai:** chạy `php artisan migrate` (2 migration `2026_09_28_1200xx`); bật scheduler (`* * * * * php artisan schedule:run`). File media cũ ở `public/uploads/YYYY/MM` (lẫn file ticket) không còn hiện trong Media Manager — chuyển tay những file thuộc media sang `public/uploads/media/` nếu cần.

#### Phase 2 — Lớp và lịch học (nhánh `feat/phase2-schedule`)
**Đã làm:**
- [x] Trùng lịch GVNN: buổi học lưu riêng `foreign_teacher_id` (migration + backfill từ lớp). Kiểm tra trùng lịch khi xếp TKB, tạo lớp, sửa lớp tính cả GV chính, GVNN, trợ giảng. `check-availability` báo cả TA/GVNN, tính nhân sự trên mọi chi nhánh, ca nối tiếp không bị coi là trùng.
- [x] Ngày nghỉ thêm sau: khi thêm hoặc sửa ngày nghỉ, buổi sắp tới của chi nhánh bị ảnh hưởng (chưa điểm danh, chưa chấm công) chuyển **Đã hủy** và gắn ngày nghỉ. Mỗi buổi hủy được xếp **1 buổi học bù** vào ca kế tiếp của lớp, sau buổi cuối cùng, không trùng phòng hoặc nhân sự. Buổi đã có dữ liệu thì giữ nguyên, chỉ báo lại cho người dùng. Dời hoặc xóa ngày nghỉ thì khôi phục buổi đã hủy và gỡ buổi bù (nếu buổi bù chưa diễn ra). Dashboard và TKB đều hiện buổi nghỉ lễ và ngày học bù.
- [x] Dashboard lớp theo ngày/tuần lấy từ buổi học thật: phòng, GV, GVNN, TA, giờ, sĩ số, trạng thái điểm danh từng buổi. Bộ lọc chạy được. Ma trận tuần sinh từ buổi học. Giáo viên chỉ thấy lớp mình. Nút "Điểm danh / Chấm công" mở `teacher.attendance` kèm `session` và `date`.
- [x] Cấu hình trình độ: sửa, xóa (chặn khi đang có khóa học hoặc lớp dùng), bật/tắt trạng thái, tìm kiếm, lọc nhóm/trạng thái, thẻ thống kê, **nhóm trình độ** và **gắn Syllabus** (migration).
- [x] TKB: chọn và sửa được lớp đã có lịch (buổi quá khứ/đã có dữ liệu giữ nguyên). Danh sách lớp theo người xem. Bỏ banner giả "12 → 15". Báo cáo phòng/nhân sự lọc theo chi nhánh và 7 ngày, số liệu từ buổi học thật, lưu nhu cầu nhân sự theo chi nhánh + ngày.
- [x] Sĩ số: `ClassModel::occupiedSeats() / seatsLeft() / isFull() / hasSeatsFor()` (gộp bàn giao xếp lớp và `current_class_id`, chỉ tính học viên còn giữ chỗ). Dùng để chặn xếp lớp thủ công khi lớp đầy.
- [x] Portal trợ giảng: chỉ nhiệm vụ của ngày đang chọn (có chọn ngày, nhắc việc quá hạn). Admin/Quản lý chọn được TA (bỏ tài khoản TA viết cứng). Giao diện điện thoại có thanh điều hướng dưới.
- [x] Bảng KPI tự động theo tháng: chuyên cần từ điểm danh, hoàn thành bài tập từ bài nộp so với bài giao, hoàn thành công việc từ WorkTask, giữ chân từ học viên lớp. Bỏ số 96.8% và danh sách nhân sự đoán theo email. Không có dữ liệu thì hiện "Chưa có dữ liệu".

**Chưa làm / chuyển phase sau:**
- [ ] Chặn sĩ số trong `StudentProfileController::storeEnrollment` → màn này thuộc nhóm Hồ sơ học viên (việc khác đang làm). Chỉ cần gọi `$class->hasSeatsFor()`.
- [ ] Điểm danh theo từng buổi (`teacher.attendance` hiện vẫn lấy buổi hôm nay) → thuộc việc điểm danh/chấm công. Link từ dashboard đã gửi sẵn `session` và `date`.
- [ ] Buổi học bù (type `makeup`) chưa tự đổi GV/TA/phòng khi sửa lớp (scope `replaceable` chỉ áp buổi chính khóa). ✅ Vòng 2

**Quyết định phát sinh (tạm theo nguyên tắc BPMN + mockup, cần BA xác nhận):**
- Ngày nghỉ thêm sau → **hủy + tự xếp bù cuối lịch** (không dồn lịch). Không đổi số buổi của khóa.
- Học viên "Bảo lưu" không tính vào sĩ số lớp.
- Tỷ lệ bài tập là số gần đúng, vì bài nộp chưa gắn với bài tập cụ thể.

**Lưu ý triển khai:** chạy `php artisan migrate` (2 migration: `class_sessions` thêm `foreign_teacher_id` / `holiday_id` / `rescheduled_from_id` kèm backfill; `course_levels` thêm `level_group` / `syllabus_curriculum_id`). Build lại asset (`npm run build`, `deploy.sh` đã tự làm) vì có class Tailwind mới.

#### Phase 3 — Từ chấm công đến lương
**Đã làm:**
- [x] Chấm công tay: bắt buộc lý do, nhập giờ vào/ra (tự tính số giờ, tối thiểu 30 phút), tự gắn buổi học thật (ClassSession) nếu có; chặn chấm trùng (chấm tay + check-in, hoặc 2 lần chấm tay cùng người/lớp/ngày hoặc cùng buổi).
- [x] Check-in giáo viên chỉ tính công khi có buổi học thật hôm nay được phân công cho người đó; bỏ mặc định 2 giờ khi không có buổi.
- [x] Lịch sử đồng bộ máy chấm công: hiển thị trạng thái trống trung thực (chưa có tích hợp thiết bị), bỏ dòng seed giả.
- [x] Kỷ luật: ghi nhận vi phạm (không cần số tiền) → nhân sự tự giải trình → chốt theo loại lỗi (lỗi chuyên môn: Học thuật `academic_lead`; lỗi vận hành: Học vụ/Quản lý `academic_staff`/`manager`; Admin luôn được; không ai tự chốt biên bản của mình) → quyết phạt kèm số tiền, hạn nộp 2 ngày → quá hạn chưa nộp thì trừ vào kỳ lương kế tiếp (tính khi bấm Tính lương), đã nộp thì không trừ. Khóa đổi trạng thái khi biên bản đã trừ trong kỳ đã duyệt. Danh sách có tìm kiếm, lọc theo bước/loại lỗi, phân trang.
- [x] Hoa hồng tính trên tiền thực thu (phiếu thu đã duyệt, gồm giáo trình/đồ dùng/phụ thu), vào tháng phiếu được duyệt (`tuition_receipts.approved_at`, phiếu cũ lấy `updated_at`), cho sale phụ trách khách lúc chốt; dùng chung cho bảng lương, BXH KPI và báo cáo CRM (`SalesCommissionService`).
- [x] Hoàn phí: người duyệt chọn có thu hồi hoa hồng hay không (mặc định "có" nếu học chưa tới 1 tháng tính từ buổi có mặt đầu tiên/ngày vào lớp), số tiền gợi ý = phần tiền hoàn × % hoa hồng sale được hưởng; khoản thu hồi trừ ở lần tính lương kế tiếp của sale. Chuyển nhượng phí không bao giờ thu hồi, tiền nhận chuyển nhượng không tính hoa hồng.
- [x] Đơn giá giáo viên theo từng người, có ngày hiệu lực và lịch sử; mốc hoa hồng có phiên bản (sửa = tạo phiên bản mới, ngừng áp dụng vẫn giữ lịch sử), kỳ lương dùng mốc hiệu lực tại ngày cuối kỳ.
- [x] Màn phiếu lương từng người (từ các bảng lương theo kỳ), Kế toán điều chỉnh phụ cấp / thưởng khác / khấu trừ khác / ghi chú khi kỳ chưa duyệt (giữ khi tính lại). "Lương của tôi" dùng cùng các dòng, chỉ kỳ đã duyệt. Nhân viên không tự chấm KPI của mình.
**Chưa làm / chuyển phase sau:**
- [ ] Công thức lương (Q3): giữ công thức hiện tại (lương cứng + giờ dạy + KPI theo ngưỡng giờ + phụ cấp − BHXH); thưởng tái tục để 0 → chờ BA chốt Q3. ✅ `feat/phase3-formula`
- [ ] Đối chiếu với bảng lương Excel đang dùng → cần file Excel thật từ Kế toán.
- [ ] Tích hợp máy chấm công (FaceID) thật → chưa có thiết bị/API.
- [ ] Phiếu thu bị hủy hóa đơn sau khi kỳ lương đã duyệt: chưa tự thu hồi hoa hồng (cần quy tắc từ BA). ✅ Vòng 2
**Quyết định phát sinh (tạm, chờ BA xác nhận):**
- "Lần đầu / khách mới" = mọi phiếu thu thuộc **khoản học phí đầu tiên** (`student_tuitions` id nhỏ nhất) của học viên được chuyển đổi từ khách CRM; khoản học phí sau là tái tục, không có hoa hồng. Phiếu không gắn khoản học phí chỉ tính nếu lập trước khi có khoản học phí thứ hai.
- "Tháng thực thu" = tháng phiếu thu được **duyệt**.
- "Hoàn phí ngay" = học **dưới 1 tháng**; chỉ là gợi ý, người duyệt quyết định và có thể sửa số tiền thu hồi.
- Loại lỗi → người chốt: chuyên môn/giảng dạy → HT (`academic_lead`); vận hành/nội quy → CM (`academic_staff`, `manager`). Cấp thêm quyền `violation.view/create/confirm_*` cho `academic_lead`, `academic_staff`.
**Lỗi còn tồn:** các màn lương theo khối (full-time / học thuật / vận hành) vẫn giữ giao diện cũ, chỉ sửa cột và thêm liên kết phiếu lương. ✅ cột theo công thức Q3 ở `feat/phase3-formula`
**MR:** nhánh `feat/phase3-payroll`

#### Phase 1 — CRM & Test đầu vào (nhánh `feat/phase1-crm`)
**Đã làm:**
- [x] SĐT: kiểm tra định dạng Việt Nam (di động 10 số 03/05/07/08/09, cố định 02x 11 số; `+84`/`84` chuẩn hoá về `0`) khi thêm / sửa khách, SĐT phụ huynh, nhập Excel.
- [x] Khách đã xóa: xóa (soft delete) nhả `phone_normalized` + email (lưu `deleted_email`) nên tạo lại được khách cùng SĐT; màn "Khách đã xóa" + Khôi phục (Admin / Quản lý cơ sở, theo chi nhánh), chặn khôi phục khi SĐT đã thuộc khách khác.
- [x] Khóa Giá trị hợp đồng / Cơ sở / Khóa đăng ký khi khách Chờ xếp lớp / Đã chốt (chặn server + readonly trên form).
- [x] Sửa thông tin khách ghi lịch sử trước → sau (`crm_customer_histories.changes`).
- [x] Cảnh báo khách bị bỏ quên: khách Mới > 24h (giữ nguyên) + mọi giai đoạn đang chăm sóc không có hoạt động N ngày (mặc định 3, `system_settings.crm_neglect_days`); báo Admin / Quản lý và thông báo cá nhân cho Sales phụ trách.
- [x] Xóa đề test không xóa bài làm (chặn xóa khi đã có bài, gợi ý tắt kích hoạt). Xem / chấm bài làm + danh sách bài gần đây theo phạm vi khách CRM (Quản lý / Học vụ chỉ chi nhánh mình).
- [x] Chốt & Xếp lớp chọn được lớp sắp khai giảng (chưa tới ngày bắt đầu), kiểm tra sĩ số; thẻ gợi ý lớp "Còn N chỗ", "Cần thêm N học viên để khai giảng" (cột mới `classes.min_students`, mặc định 6).
- [x] Màn "Khách chốt — Xác nhận chính thức": checklist đã gửi tài khoản / vào nhóm Zalo / nhận giáo trình, lưu tiến độ, xác nhận (lớp đã khai giảng → học viên "Đang học").
- [x] Pipeline: lọc (tìm kiếm, chi nhánh cho Admin, Sales, nguồn, khoảng ngày), badge Quá hạn / Sắp hết hạn theo `next_follow_up_at` (sửa trong form khách), modal "Sửa giai đoạn" theo A6.
- [x] Chi tiết khách: SĐT phụ huynh, Phân công lại (Admin / Quản lý, bắt buộc lý do, ghi lịch sử), In hồ sơ, thẻ "Trạng thái & Hạn xử lý", checklist chăm sóc tháng đầu, lọc nhật ký theo loại, khối thang điểm test (khối lớp, tổng điểm, gợi ý lớp).
- [x] Khách chốt: phân trang, lọc, cột lớp, xuất Excel / CSV. Khách không chốt: tìm kiếm, lọc ngày, phân trang, xuất file.
- [x] Báo cáo doanh số: bảng theo người phụ trách giới hạn phạm vi (Sales chỉ mình, Quản lý chi nhánh mình) + xuất file. Không đổi công thức hoa hồng.
- [x] Nhập khách hàng loạt từ Excel / CSV: xem trước, lỗi từng dòng (thiếu tên, SĐT sai / trùng trong file / trùng CRM, email), chọn chi nhánh + Sales, nhập các dòng hợp lệ. Màn nhập học phí giữ nguyên.
**Chưa làm / chuyển phase sau:**
- [ ] Q2 (thang 0–100 ở CRM vs 0–9 ở màn chấm bài online) → chờ BA; hiện chỉ ghi rõ thang trên từng màn.
- [ ] Ô "Ngưỡng khai giảng" trong form tạo / sửa lớp → thuộc nhóm lớp học (cột đã có, mặc định 6). ✅ Vòng 2
**Quyết định phát sinh:** Trường hợp đồng bị khóa sau chốt = Giá trị hợp đồng, Cơ sở, Khóa đăng ký. Xác nhận chính thức chỉ áp dụng ghi danh tạo từ CRM (có `customer_id`).
**Lỗi còn tồn:** —
**Test:** thêm `tests/Feature/Phase1CrmTest.php`, `Phase1EnrollmentTest.php`. Cập nhật `AcademicSystemTest`, `PlacementPortalSecurityTest`: khách trong test được gán chi nhánh của người chấm (hành vi cũ cho chấm khách ngoài phạm vi là sai theo A6).
**Triển khai:** chạy `php artisan migrate` (migration `2026_09_28_100000_add_phase1_crm_fields`).

#### Phase 4 (nền tảng) — Tài khoản, phân quyền, nhật ký, giao việc, ticket, portal, dashboard
**Đã làm:**
- [x] Nhật ký: mỗi request ghi **1 dòng** (middleware gom batch, chỉ ghi dòng chung khi request không sinh dòng nào). Model User, Student, ClassModel, CrmCustomer, TuitionReceipt, SystemCategory, Branch tự ghi **trước/sau** (trait `AuditsChanges`, cấu hình `App\Support\Audit`), che dữ liệu nhạy cảm bằng `SensitiveData`. Màn Nhật ký: lọc ngày/hành động, bảng so sánh trước/sau, **Xuất Excel (CSV)**, **Hoàn tác** (chỉ Admin, chỉ thao tác sửa đơn giản trên trường cho phép, từ chối nếu bản ghi đã đổi tiếp).
- [x] Phân quyền cá nhân dạng ma trận Xem/Thêm/Sửa/Xóa + quyền khác, chọn **phạm vi chi nhánh/lớp** theo module. Phạm vi được kiểm tra thật cho `class.view/update/delete` (`ClassModel::scopeVisibleTo`, `ClassModel::userCan`, Gate mở route khi có override phạm vi); module khác chỉ nhận "Toàn hệ thống".
- [x] Quản lý cơ sở chỉ thấy **chi nhánh mình** ở Lớp học (xem/sửa/xóa/tạo), Tài khoản (danh sách, thao tác, chọn chi nhánh) và Giao việc. Admin thấy tất cả.
- [x] Tài khoản: sửa không còn xóa vai trò kiêm nhiệm; bỏ dữ liệu giả "Cơ sở Cầu Giấy", "0912 345 678"; upload file hợp đồng (lưu riêng tư, tải qua route kiểm tra quyền); tạo tài khoản và đặt lại mật khẩu bắt buộc đổi mật khẩu. Người dùng **không tự xóa** tài khoản được nữa.
- [x] Cảnh báo hợp đồng: lệnh `hr:notify-expiring-contracts` chạy 07:50 hằng ngày, báo Admin + Quản lý cơ sở của chi nhánh (không trùng); nhãn "HĐ sắp hết hạn/đã hết hạn" trên danh sách nhân sự.
- [x] Danh mục: nút **Kích hoạt lại**.
- [x] Giao việc: người không có quyền duyệt chỉ thấy việc mình giao/được giao; đổi trạng thái theo quy tắc chuyển trạng thái và vai trò (người làm không tự hoàn thành, **không tự duyệt**); **giao việc 2 chiều** (GV/TA dùng quyền mới `work_task.request` giao ngược cho Admin/Quản lý/Học vụ/Học thuật); thông báo cá nhân khi được giao việc. **Báo cáo trực lớp** không có ảnh chờ **GV chính của lớp** hoặc Học vụ/Quản lý duyệt/trả về (Q8 tạm theo mockup: ảnh bảng không bắt buộc, có ảnh thì duyệt luôn).
- [x] Ticket: ticket chưa phân công báo riêng cho người có quyền phân công ticket **cùng chi nhánh** người tạo (không còn thông báo chung); chỉ phân công cho người có quyền xử lý; file đính kèm mới lưu **riêng tư**, xem qua route kiểm tra người trong luồng (file cũ `public/uploads` vẫn mở được qua route này).
- [x] Portal học viên: bỏ thông báo mẫu tự tạo; bài phát âm **chờ giáo viên chấm** (tab "Phát âm" ở màn chấm bài của giáo viên), không còn điểm "AI" ngẫu nhiên.
- [x] Dashboard theo vai trò: Admin (toàn hệ thống), Quản lý cơ sở (chi nhánh), Học thuật (lớp, đề xuất chờ duyệt, Big Test sắp tới). Vai trò khác giữ lưới lối tắt.
- [x] Gỡ view chết: `payroll/periods-index`, `periods-show`, `tuition/receipts-approve`, `receipts-create`, `syllabus/big-test-distribution`, `big-test-results`.

**Chưa làm / chuyển phase sau:**
- [ ] Phạm vi chi nhánh/lớp cho module khác (Học viên, Học phí, CRM...) → cần áp vào từng màn; hiện UI khóa ở "Toàn hệ thống".
- [ ] Học vụ/Học thuật chưa bị giới hạn chi nhánh ở Lớp học/Tài khoản (chỉ Quản lý cơ sở theo A6 Q7) → chờ BA xác nhận.
- [ ] Xuất nhật ký dạng CSV (mở bằng Excel), chưa phải .xlsx.

**Quyết định phát sinh:** Q8 tạm: báo cáo trực lớp không ảnh do GV chính của lớp duyệt (lớp chưa có GV chính → người có quyền duyệt công việc cùng chi nhánh). Ticket chưa phân công báo cho người có `support_ticket.assign` cùng chi nhánh người tạo; không có ai → Admin.

**Triển khai:** chạy `php artisan migrate` (thêm `users.contract_file_path`, `class_reports.rejection_reason`, quyền `work_task.request` cho vai trò giáo viên/trợ giảng); đảm bảo cron `schedule:run` chạy; thư mục `storage/app/private` ghi được.

#### Phase 4 — Học phí & Chuyển khoản (nhánh `feat/phase4-finance`)
**Đã làm:**
- [x] Dải số hóa đơn theo chi nhánh (dải cũ = dải mặc định dùng chung), không chồng lấn, không lùi số dưới số đã cấp, `tuition_receipts.invoice_number` UNIQUE (số trùng cũ đổi thành `-DUP{id}`); màn "Cấu hình dải số hóa đơn" theo mockup.
- [x] Khất nợ có hạn mới: duyệt xong dời hạn đóng, bỏ trạng thái quá hạn, tạm dừng nhắc nợ tới hạn mới. Thêm loại **Bảo lưu** (từ/đến ngày): học viên sang `deferred` (Bảo lưu), đóng băng số buổi còn lại + công nợ, dời hạn đóng rơi vào thời gian bảo lưu, tạm dừng nhắc nợ.
- [x] Nhập học phí từ Excel/CSV thật (maatwebsite/excel): xem trước kèm lỗi từng dòng, chỉ nhập dòng hợp lệ, khoản đã đóng thành phiếu **chờ duyệt**; file mẫu .xlsx.
- [x] Quyền `finance.view` riêng cho báo cáo thu chi (Admin, Kế toán, Quản lý cơ sở); Sale giữ `report.view` cho báo cáo CRM. Quản lý cơ sở chỉ thấy chi nhánh mình.
- [x] Phiếu thu: màn sửa phiếu nháp/bị trả về (dùng lại form lập phiếu) rồi gửi lại; bắt buộc minh chứng khi gửi duyệt chuyển khoản/VietQR/POS (tiền mặt, nháp được miễn); số buổi thật (khóa học/lịch lớp + điểm danh), thiếu dữ liệu thì ẩn; QR theo tài khoản hợp đồng → chi nhánh → mặc định.
- [x] Thông báo cá nhân cho Kế toán chi nhánh (và kế toán không gán chi nhánh) khi có phiếu chờ duyệt; báo người lập khi phiếu bị trả về.
- [x] Danh sách quá hạn chia nhóm ≥ N ngày / 1–(N-1) ngày (N = "Mốc quá hạn bắt buộc liên hệ", mặc định 7), cột số ngày quá hạn, "Đã liên hệ" (ghi chú + thời gian), "Báo cáo Admin".
- [x] Hoàn phí tính từ hợp đồng thật (đã nộp, tổng buổi/đã học theo điểm danh, phí quản trị `config/tuition.php`), thêm "Đánh dấu khất nợ".
- [x] Cấu hình nhắc nợ theo mockup: mốc theo số ngày trước/sau hạn, kênh (in-app, email), mẫu tin chỉ nhận biến hệ thống thay được.
- [x] Chống ghi nhận chuyển khoản 2 lần: mã giao dịch chuyển khoản duy nhất (`transfer_reference`), chặn phiếu tay trùng giao dịch SePay, cảnh báo cùng tiền/cùng học viên ±3 ngày (phải tick xác nhận); SePay bỏ qua giao dịch đã có phiếu tay; webhook chỉ gạch nợ khi tiền vào tài khoản ngân hàng đã cấu hình.

**Chưa làm / chuyển phase sau:**
- [ ] Hết thời gian bảo lưu chưa tự chuyển học viên về "Đang học" (nhắc nợ tự chạy lại) → cần job/luồng trạng thái học viên (nhóm Học viên). ✅ Vòng 2
- [ ] Phiếu hoàn/chuyển nhượng vẫn lấy số HĐ ở dải mặc định (hàm duyệt hoàn phí do nhánh lương sửa song song, tránh xung đột). ✅ Vòng 2
- [ ] Kênh Zalo ZNS/SMS cho nhắc nợ chưa tích hợp (màn cấu hình ghi rõ "chưa tích hợp").

**Triển khai:** chạy `php artisan migrate` (5 migration `2026_09_28_2000xx`); migration đã gán `finance.view` cho admin/accountant/manager, hoặc chạy lại `db:seed --class=PermissionSeeder` + `RoleSeeder`. Phải cấu hình số tài khoản ngân hàng nhận tiền trước khi bật SePay, nếu không webhook sẽ không gạch nợ.

#### Vòng 2 — Việc còn tồn vòng 1 (nhánh `feat/round2-leftovers`)
**Đã làm:**
- [x] Mã biên bản phạt `BB-YYYY-NNN` sinh qua `DocumentCodeGenerator::penaltyCode()` (dãy `penalty` theo năm, khởi tạo từ mã lớn nhất đang có, giữ 3 chữ số).
- [x] Phiếu hoàn phí / chuyển nhượng lấy số HĐ theo dải của chi nhánh học viên (bên nhận: chi nhánh học viên nhận), hết/không có dải → dải mặc định — như phiếu thu thường.
- [x] Kết thúc bảo lưu: lệnh `students:end-deferrals` chạy 06:50 hằng ngày — học viên "Bảo lưu" có `deferred_until` < hôm nay → "Đang học" (lớp đã khai giảng) hoặc "Chờ khai giảng"; bỏ đóng băng số buổi/công nợ, nhắc nợ chạy lại; báo Học vụ cùng chi nhánh (không có → Quản lý cơ sở → Admin), không trùng. Nút "Kết thúc bảo lưu" trên hồ sơ học viên (`student.change_status`). Đổi trạng thái tay khỏi "Bảo lưu" cũng bỏ đóng băng học phí.
- [x] Sửa lớp (GV/TA/GVNN/phòng) đồng bộ cả buổi học bù (`type = makeup`) chưa diễn ra, cùng điều kiện bảo vệ (chưa điểm danh/chấm công, không gắn phụ đạo) — scope `ClassSession::staffSyncable()`; `replaceable()` (xóa khi xếp lại TKB) giữ nguyên chỉ buổi chính khóa.
- [x] Form tạo/sửa lớp có "Ngưỡng khai giảng" (`min_students`, mặc định 6, không vượt sĩ số tối đa). Danh sách lớp và hồ sơ lớp hiện sĩ số giữ chỗ thật, "Còn N chỗ"/"Đã đủ", ngưỡng và số học viên còn thiếu để khai giảng (`ClassModel::seatSummary()`).
- [x] Hủy hóa đơn sau khi kỳ lương chứa phiếu đã duyệt/trả: tạo khoản thu hồi hoa hồng (CommissionAdjustment âm, cơ chế như hoàn phí) = tiền phiếu × % hoa hồng sale đã hưởng kỳ đó, trừ ở lần tính lương kế tiếp. Kỳ chưa duyệt → không tạo gì, tính lại tự loại phiếu đã hủy.
- [x] Phạm vi chi nhánh màn Học phí (danh sách học phí, lập phiếu, duyệt phiếu, lịch sử, hủy HĐ, hoàn phí/chuyển nhượng, quá hạn + các thao tác duyệt/từ chối/liên hệ/nhắc): `App\Support\TuitionBranchScope`.
**Quyết định phát sinh (tạm, chờ BA):**
- Phạm vi Học phí: Admin toàn hệ thống; Quản lý cơ sở / Học vụ / Học thuật chỉ chi nhánh mình; **Kế toán không gán chi nhánh = kế toán tổng, thấy tất cả**; kế toán có gán chi nhánh chỉ thấy các chi nhánh đó (cấp thêm qua chi nhánh phụ). Không có cờ "đa chi nhánh" riêng.
- Thu hồi khi hủy HĐ không tính phần thưởng vượt mốc và không xét việc sale tụt bậc hoa hồng (như hoàn phí).
- Hết bảo lưu mà học viên không có lớp → "Chờ khai giảng".
**Chưa làm:** màn Báo cáo thu chi (FinanceController) vẫn cho kế toán thấy toàn hệ thống như cũ; phạm vi chi nhánh ở nhập học phí Excel chưa áp.
**Test:** `tests/Feature/Round2LeftoversTest.php`.
**Triển khai:** không có migration. Đảm bảo cron `schedule:run` chạy (lệnh mới `students:end-deferrals` 06:50). Kế toán chi nhánh (có `branch_id`) từ nay chỉ thấy học phí chi nhánh mình — kiểm tra lại gán chi nhánh của tài khoản kế toán tổng (để trống chi nhánh).

#### Phase 2 (vòng 2) — Điểm danh, bổ trợ, cổng học viên, chăm sóc (nhánh `feat/phase2-attendance`)
**Đã làm:**
- [x] Điểm danh theo **từng buổi học** (`class_session_id`, `?session=` từ dashboard lớp/lịch dạy, `?date=`, mặc định buổi hôm nay). Điểm danh bù buổi đã qua, buổi học bù/phụ đạo trong ngày; chặn buổi đã hủy/chưa tới ngày. Học vụ / Học thuật / Quản lý cơ sở (lớp trong phạm vi) **điểm danh thay GV** — `user_id` giữ là GV của buổi, `recorded_by` là người lưu. Lưu điểm danh vẫn chốt buổi "completed".
- [x] Lịch dạy GV (Cổng GV) từ buổi học thật: hôm nay (check-in theo buổi), lịch tuần (GV/TA/GVNN), danh sách buổi đã qua chưa điểm danh (30 ngày).
- [x] A4.7 — `ClassModel::roster()` = học viên có lớp chính là lớp này ∪ lượt xếp lớp còn hiệu lực ("Liên kết lớp khác"), bỏ Thôi học/Hoàn thành/Bảo lưu. Dùng cho điểm danh, nhập điểm mini test, nhận xét, nhập kết quả Big Test, hồ sơ lớp, sĩ số danh sách lớp (`occupiedSeats()` dùng chung).
- [x] Danh sách bổ trợ tự động: vắng (kể cả vắng có phép), mini test < 7/10 (quy đổi theo điểm tối đa), Big Test < 7 (không tính vắng thi) → thêm vào `class_report_student_supports` (thêm lớp, nguồn, bản ghi nguồn, điểm; mỗi học viên + lớp + nguồn + bản ghi 1 dòng). Sửa lại thành có mặt / điểm ≥ 7 thì tự gỡ nếu chưa xếp buổi. Màn phụ đạo hiện nguồn/lý do, lọc theo nguồn, "Xếp buổi" từ dòng bổ trợ; kiểm tra trùng lịch bỏ qua buổi đã hủy, tính cả vai trò trợ giảng/GVNN của người dạy.
- [x] Cổng học viên: lịch học 14 ngày tới (lớp chính + lớp liên kết + buổi phụ đạo của mình), lịch sử điểm danh, kết quả Big Test đã duyệt/đã gửi; màn "Học tập của tôi" dùng nhận xét buổi học, điểm mini test/Big Test, bài tập giao thật. Bỏ dữ liệu mẫu (tên, ngày sinh, địa chỉ, lớp, số tiền, chặng feedback).
- [x] Chăm sóc tháng đầu: lệnh `students:schedule-first-month-care` (07:40 hằng ngày) tạo việc cho Học vụ chi nhánh ở ngày 3/7/14/30 kể từ buổi có mặt đầu tiên (chưa có thì ngày xếp lớp), idempotent (`work_tasks.student_id + care_milestone`). Mốc gắn với checklist chăm sóc tháng đầu của CRM — hoàn thành việc tự tick mục CRM. Hồ sơ học viên có khối "Chăm sóc tháng đầu".
- [x] Sinh nhật: bỏ học viên Thôi học/Hoàn thành; nhắc GV/GVNN/TA các lớp và Học vụ chi nhánh (1 lần/học viên/năm).
- [x] Chấm bài nộp (GV): bỏ 3 học viên mẫu, chỉ bài nộp thật của danh sách lớp đang chọn, lọc theo loại bài; không tự điền điểm "10/10" / nhận xét mẫu.
**Chưa làm / chuyển phase sau:**
- [ ] Nhận xét buổi học (`teacher.remarks`) vẫn lưu theo lớp + ngày (chưa theo buổi).
- [ ] Sửa điểm danh đã duyệt sẽ đưa dòng đó về "chờ duyệt" (giữ hành vi cũ).
- [ ] Học viên bắt đầu học > 37 ngày trước khi bật lệnh chăm sóc sẽ không được tạo việc bù (chỉ bù trong 7 ngày).
**Quyết định phát sinh (tạm, chờ BA):** "Vắng có phép" cũng vào danh sách bổ trợ; ngưỡng điểm 7/10 áp cho mini test (quy đổi) và Big Test (điểm tổng); việc chăm sóc giao cho Học vụ đầu tiên của chi nhánh (không có thì Quản lý cơ sở), người giao là Quản lý cơ sở/Admin.
**Test:** `tests/Feature/Phase2AttendanceTest.php` (11 test). Không phải sửa test cũ — các test điểm danh cũ (không truyền buổi) vẫn chạy theo mặc định "buổi hôm nay".
**Triển khai:** chạy `php artisan migrate` (migration `2026_09_29_100000_phase2_attendance_support_care`: bỏ unique `(class_id, student_id, session_date)` của điểm danh, thêm unique `(class_session_id, student_id)` + `recorded_by`, gắn buổi cho điểm danh cũ; mở rộng bảng bổ trợ; thêm `work_tasks.student_id/care_milestone`). Cron `schedule:run` phải chạy. Build lại asset (`npm run build`).

#### Rà soát giao diện B2 mục 2–7 (nhánh `feat/ui-sweep`)
**Đã làm:**
- [x] Dữ liệu giả → dữ liệu thật / trạng thái trống: dashboard báo cáo đào tạo (chuyên cần, báo cáo trực lớp, sĩ số theo điểm danh), dashboard sự vụ (ticket khẩn + nhật ký thật, lọc chi nhánh/mức độ/trạng thái), sơ đồ khối lớp (đếm theo `program`/`level`), danh sách lớp chi tiết (tiến độ buổi, Big Test, lọc chương trình/trình độ, phân trang), chi tiết lớp (chặng, buổi đã học, Big Test), hồ sơ lớp, đặt lịch học thử (nhập tên khách thay "Nguyễn Văn A"), hồ sơ nhân sự (lớp đang phụ trách), phiếu thu / hóa đơn / lịch sử thu, trang cá nhân. Form không còn nội dung soạn sẵn (đề test, báo cáo trực lớp, 3 dòng giao việc, phòng thi). Ba màn mockup tĩnh (dự giờ QA, checklist học phí & feedback, đánh giá dự giờ) hiện "Chức năng chưa triển khai".
- [x] SĐT / địa chỉ trung tâm: `App\Support\CenterInfo` (system_settings `center_phone`, `center_name`, `center_tax_code`, `center_website` → `config('app.center_*')`; địa chỉ lấy từ chi nhánh).
- [x] Bộ lọc / phân trang: kỳ lương (tìm kiếm + trạng thái phía server, phân trang), giao chặng (tìm phía server), bỏ nút phân trang tĩnh ở sổ khoản chi.
- [x] Nút giả: xuất Excel/CSV thật cho bảng lương theo kỳ/khối, dashboard lớp theo ngày/tuần, bảng KPI, báo cáo phòng/nhân sự; `alert()` → toast; bỏ nút "Thêm quyền (DEV ONLY)", "Xuất biên lai" luôn khóa, link `#`.
- [x] Lỗi validate: layout đã có alert lỗi chung; thêm lỗi theo từng trường + giữ `old()` cho form giao việc, ticket, hàng hóa, báo cáo trực lớp, giao việc TA, đặt học thử; trang làm bài test công khai có khối lỗi riêng.
- [x] Tiêu đề kỹ thuật: bỏ "(Flow N — Bước #N)", "(Lưu Database)", "(Permissions Tiếng Việt)"; mã trạng thái thô → `App\Support\StatusLabel`.
- [x] Menu: đã không trùng route (test cũ) + thêm test không trùng nhãn. Topbar: ô tìm kiếm chung `/search` (khách CRM, học viên, lớp theo tên/mã/SĐT, qua `visibleTo()`), thêm "Tạo ticket hỗ trợ" vào "Tạo mới".
**Chưa làm / để lại:**
- [ ] `classes/create`, `classes/edit`, `teacher/*`, `portal/*` (nhánh khác đang sửa) còn `alert()` / fallback giả ("Nguyễn Văn A", "IELTS Starter - M01"...).
- [ ] Dự giờ QA / checklist học phí & feedback / đánh giá dự giờ: chưa có mô hình dữ liệu.
- [ ] "In phiếu" (lịch sử thu, phiếu lương cá nhân, bảng điểm Big Test, bảng đánh giá test) vẫn dùng in của trình duyệt (đúng nhãn "In").
**Test:** `tests/Feature/UiSweepTest.php`.

#### BA 25/09 — Test đầu vào theo khối lớp, luật CRM, học thử (nhánh `feat/ba-placement-crm`)
**Đã làm:**
- [x] Q2: chấm test theo **khối lớp** (Khối 1-2, 2 lên 3, 3 lên 4, 4 lên 5) theo "Thang điểm + hướng dẫn nhận xét" (nguồn: file HTML trong `ui-full-tinh-nang-menglish`, khớp xlsx gốc): Tổng = Nghe + Đọc&Viết + Nói (điểm thô), tra tổng → lớp đề xuất, nhận xét từng kỹ năng gợi ý theo băng (sửa được), Nói luôn nhập tay, **chọn lại lớp** (lưu cả lớp đề xuất và lớp chọn). Bỏ "trung bình 4 kỹ năng thang 10 → CEFR" và hai thang 0–100 / 0–9: màn nhập điểm CRM và màn chấm bài dùng chung form, validate điểm tối đa từng kỹ năng theo khối. Khối chưa có thang (lớp 5–9, IELTS, người đi làm, mầm non) → "Chưa có thang điểm — Học thuật chọn lớp thủ công", bắt buộc nhập lớp. Kết quả hiện trên hồ sơ khách, bản in, scorecard.
- [x] Q1 (bản sửa): kiểm tra + test luật CM tiến 1 bước, chỉ Admin lùi (bắt buộc lý do, ghi lịch sử), không hủy chốt, chỉ khách chưa chốt sang Thất bại; khách Thất bại không mở lại, không xóa được (giữ đối soát), vẫn nằm trong "Khách không chốt".
- [x] Q1 học thử: trang "Nhận xét học thử" (`/teacher/trial-guests`, sắp tới / đã diễn ra) — GV buổi đó điểm danh + nhận xét như học sinh (ngữ pháp, tinh thần, kết quả, nhận xét chi tiết), lưu theo khách (`crm_trial_bookings.remarks`), ghi nhật ký tuyển sinh; chỉ nhận xét từ ngày học. Tối đa 2 buổi/khách; buổi của lớp khớp trình độ test được gắn nhãn và xếp đầu.
- [x] Q5/Q6: hồ sơ học viên không còn trạng thái "Học thử" (đã gỡ trước đó, thêm test khởi tạo "Chờ khai giảng"); chốt chưa đóng phí → task "Nhắc thu học phí", chốt chưa có lớp → "Chờ xếp lớp" (bổ sung test chốt có lớp nhưng chưa đóng phí).

**Chưa làm / chuyển phase sau:**
- [ ] Khớp trình độ lớp học thử chỉ là gợi ý (so từ khóa lớp xếp với tên lớp/khóa/trình độ), chưa chặn cứng.
- [ ] Màn cũ `classes/trial-booking` (lưu AcademicRecord, không gắn khách CRM) vẫn còn → nên chuyển về đặt học thử từ hồ sơ khách.
- [ ] Khối 1-2 tổng > 25: file HTML (bộ mô phỏng) xếp "STARTERS (FAM 1 _ NÂNG CAO)" nhưng bảng quy chuẩn không có dòng này → cần BA xác nhận.

**Triển khai:** chạy `php artisan migrate` (`2026_09_29_100000` thêm cột chấm theo khối vào `placement_test_submissions`, `2026_09_29_100100` thêm `crm_trial_bookings.remarks`). Bài đã chấm theo cách cũ giữ nguyên điểm và hiển thị "chấm theo cách cũ" cho tới khi Học vụ sửa điểm. Chạy lại `npm run build` nếu CSS thiếu class mới.

#### Q4 — Mô hình giáo trình theo chặng (nhánh `feat/ba-syllabus-stages`)
**Đã làm:**
- [x] Phân cấp **Giáo trình → Chặng → Unit → Buổi**: bảng mới `syllabus_stages` (thứ tự, tên, mục tiêu, link tổng quan, thông tin Big Test cuối chặng) và `syllabus_lessons` (nội dung từng buổi); `syllabus_units` thêm `stage_id`. Đánh số duy nhất: `unit_number` và `session_no` đều duy nhất trong cả giáo trình (buổi thứ N của lớp ↔ `session_no` N) — màn soạn, màn GV và đề xuất sửa dùng chung.
- [x] Soạn syllabus: thêm/sửa/xóa/đổi thứ tự chặng, thêm unit vào chặng, thêm buổi vào unit; gắn giáo trình cho **Trình độ** ngay trên màn soạn. Tạo giáo trình tự có "Chặng 1". Không xóa được chặng còn unit / đã giao lớp / đã gắn Big Test.
- [x] Chặng của lớp: mặc định giáo trình theo trình độ của lớp, chặng kế tiếp, GV chính. **Mỗi lớp 1 chặng mở** (kiểm tra ở service + UNIQUE `open_class_id` trong DB). Big Test tạo mới tự gắn chặng đang mở (`big_tests.syllabus_stage_id`).
- [x] **Chặng đóng khi Big Test của chặng được duyệt và gửi PH** (mọi kết quả "Đã gửi PH"; học viên vắng thi chỉ cần "Đã duyệt"; không còn kết quả chờ duyệt) — kiểm tra sau Duyệt kết quả, Gửi Zalo cả lớp, Gửi từng học viên. Chặng kế tiếp tự mở cùng GV, báo GV chính/GVNN; hết chặng → ghi "hoàn thành giáo trình" (`curriculum_completed_at`).
- [x] Học thuật (`syllabus.approve_adjustment`) **đóng tay** (bắt buộc lý do, tùy chọn mở chặng kế) và **chuyển chặng** (đóng chặng đang mở rồi mở chặng chọn, bắt buộc lý do). Lịch sử hiện người/lý do mở, đóng.
- [x] Màn GV xem giáo trình: chọn lớp, dải tiến trình các chặng, chặng đang học, số buổi đã dạy (buổi không hủy từ ngày mở chặng), **buổi tiếp theo** và nội dung Unit/Buổi của chặng.
- [x] Giãn tiến độ vẫn thêm buổi vào TKB như cũ; yêu cầu gắn chặng đang mở, duyệt xong cộng `extra_sessions` cho chặng.
**Chưa làm / câu hỏi mở:**
- [ ] **Q4 — Unit có cần bảng riêng?** Đang làm Unit là bảng riêng "nhẹ" (tên, số unit, mô tả); nội dung dạy ở Buổi. Nếu BA chốt Unit chỉ là số (`so_unit`), gộp thành cột trên `syllabus_lessons`, không đổi cách đánh số. Chưa có bảng NOI_DUNG_BUOI_HOC nào trong code — `syllabus_lessons` là bảng nội dung buổi duy nhất.
- [ ] Buổi học trong TKB (`class_sessions`) chưa lưu `session_no`; vị trí buổi tính theo số buổi đã dạy kể từ khi mở chặng.
- [ ] Order đề của GV (`big_test_orders.stage_name`, cổng GV) vẫn nhập tên chặng tự do — thuộc nhánh cổng GV. ✅ `feat/phase2-mockup-syllabus` (lấy chặng đang mở)
- [ ] Chưa có màn đổi chặng cho một đợt Big Test đã tạo (đợt thi cũ trước migrate không gắn chặng nên không tự đóng chặng; Học thuật đóng tay nếu cần). ✅ `feat/phase2-mockup-syllabus` ("Gắn chặng" ở màn Duyệt & phân phối đề)
**Quyết định phát sinh:** Big Test "đã duyệt và gửi" = không còn kết quả nháp/chờ duyệt, mọi kết quả có điểm đã gửi PH; học viên chưa có dòng kết quả không chặn đóng chặng. Chặng tự mở dùng GV của chặng trước.
**Triển khai:** chạy `php artisan migrate` (`2026_09_29_100000_create_syllabus_stage_hierarchy`). Migration chuyển dữ liệu: mỗi giáo trình → Chặng 1 (tên = `stage_name` cũ); mỗi bài cũ → Unit cùng id + 1 Buổi cùng số (trùng số → số trống kế tiếp), nội dung chép sang; chặng đã giao gắn Chặng 1, lớp có nhiều chặng đang áp dụng chỉ giữ bản mới nhất (bản cũ đóng kèm lý do); Big Test cũ **không** tự gắn chặng (tránh gửi kết quả một đợt thi cũ làm đóng chặng duy nhất và đánh dấu lớp xong giáo trình) — chỉ đợt thi tạo sau khi migrate mới tự gắn chặng đang mở. **Sau migrate:** Học thuật tách giáo trình cũ thành nhiều chặng/unit ở màn Soạn syllabus (dữ liệu cũ đều nằm trong Chặng 1) trước khi tạo Big Test cuối chặng mới. Sao lưu DB trước khi chạy.

#### Nghiệm thu Phase 1 — Trọn luồng khách → học viên vào lớp (nhánh `feat/phase1-seed-acceptance`)
**Kịch bản đã kiểm thử** (`tests/Feature/Phase1AcceptanceTest.php`, qua HTTP bằng đúng vai trò, theo A6):
- [x] Sale thêm khách: SĐT sai định dạng / SĐT PH sai / trùng SĐT (khác cách viết, `+84`) bị chặn; Sale không đổi bước nhưng ghi được nhật ký.
- [x] CM (Học vụ) tiến **từng bước**, không nhảy cóc; hẹn test (gán đề + người chấm) → "Hẹn test"; khách mở **link test riêng có chữ ký** → "Test" (link bị sửa `lead` không điền sẵn thông tin); nộp bài gắn đúng khách nhờ token (kể cả nhập SĐT khác), trắc nghiệm tự chấm theo thang khối, bài ở "chờ chấm".
- [x] Sale không chấm được (403); Học vụ chấm theo **thang khối lớp** (Nói bắt buộc nhập tay, chặn điểm vượt tối đa), tổng → lớp đề xuất, **chọn lại lớp** → "Đã test"; CM → "Gửi kết quả".
- [x] Học thử: Sale không đặt được; CM đặt **tối đa 2 buổi** lớp thật (buổi thứ 3 bị chặn), stage không đổi; GV của buổi thấy khách ở "Nhận xét học thử", GV khác 403; nhận xét lưu theo khách + nhật ký, hiện trên hồ sơ khách; chưa có hồ sơ học viên.
- [x] Chốt (a) vào lớp còn chỗ, đã đóng phí → học viên **Chờ khai giảng**, tài khoản (bắt buộc đổi mật khẩu), học phí, ghi danh, phiếu thu chờ duyệt, không có task nhắc thu, "Đã chốt"; Xác nhận chính thức thiếu checklist bị chặn, đủ → "Đang học" (lớp đã khai giảng). Khách đã chốt: không sang Thất bại, không lùi (kể cả Admin), khóa giá trị hợp đồng.
- [x] Chốt (b) nhánh không test, **"xếp lớp sau"** (bắt buộc chọn khóa), chưa đóng phí → "Chờ xếp lớp", học phí theo khóa, task **"Nhắc thu học phí"** cho Sale phụ trách; không kéo tay sang "Đã chốt"; Sale không gán lớp (403); Học vụ gán lớp từ "Chờ xếp lớp" → "Đã chốt" + ghi danh + học phí gắn lớp; Xác nhận chính thức (lớp sắp khai giảng giữ "Chờ khai giảng"). Lớp hết chỗ → chốt bị chặn.
- [x] Luật bước: CM không lùi (403, cả JSON); CM không kéo sang Đã chốt; **Admin lùi bắt buộc lý do**, lịch sử lưu từ/đến/lý do/người; Thất bại bắt buộc lý do; khách Thất bại không mở lại (kể cả Admin, nút "Tiếp theo"), không đặt học thử, không chốt, không xóa, vẫn trong "Khách không chốt".
- [x] Phạm vi chi nhánh: Quản lý chi nhánh khác không xem / chuyển bước / chốt được (404), pipeline và danh sách không lộ khách; Sale khác không thấy khách không được giao; lớp chi nhánh khác không nhận khách.
- [x] Buổi học sinh bằng `SessionScheduleService` bỏ ngày nghỉ của chi nhánh.

**Lỗi phát hiện và đã sửa:**
- [x] `ClassModel::loadRosterCounts()` lỗi 500 khi các lớp chỉ có lượt xếp lớp mà không học viên nào có `current_class_id` (merge mảng vào Eloquent Collection rỗng) — ảnh hưởng mọi màn nạp sĩ số theo danh sách lớp (sau khi sửa lỗi dưới thì gồm cả Chốt & Xếp lớp).
- [x] Chốt & Xếp lớp, gợi ý lớp ở "Chờ xếp lớp" và "Gán lớp" đếm sĩ số chỉ theo `class_enrollments` → học viên xếp lớp từ hồ sơ (chỉ có `current_class_id`) không được tính (**nhận quá sĩ số**), học viên Bảo lưu vẫn bị tính. Nay dùng cùng sĩ số với màn Lớp học (`ClassModel::roster` / `hasSeatsFor()`), `CrmController` (4 chỗ).
- [x] `WorkTaskSeeder` không idempotent (chạy lại `db:seed` nhân bản việc mẫu / báo cáo trực lớp).

**Dữ liệu demo:** `Database\Seeders\DemoPhase1Seeder` (gọi từ `DatabaseSeeder` khi `local`/`testing`/`staging` hoặc `SEED_DEMO=true`), chi nhánh CG và BD, dùng tài khoản UserSeeder, đi qua controller / service thật (thêm khách, chuyển bước, hẹn test, chấm theo khối, đặt học thử, GV nhận xét, Chốt & Xếp lớp, Kế toán duyệt phiếu, gán lớp, xác nhận chính thức). Mỗi chi nhánh: 14 khách phủ đủ 8 bước pipeline, trong đó 2 Thất bại (có lý do), hạn liên hệ quá hạn / sắp hết hạn, khách bị bỏ quên; bài test 5 khối (4 khối có thang + nhóm thủ công) đã chấm + 1 bài chờ chấm; 3 buổi học thử (2 đã hẹn, 1 đã học có nhận xét); 3 lớp (FAM 1 đang học còn chỗ, FAM 2 sắp khai giảng thiếu 3 HV, FAM 0 đầy) với 112 buổi, bỏ ngày nghỉ `HOL-DEMO-<CN>`; 3 khách Đã chốt (đã đóng phí + đã xác nhận / chưa đóng phí + task nhắc thu / gán lớp từ lớp chờ) + 1 Chờ xếp lớp. Idempotent (chạy lại không đổi số dòng), ~3,5 giây. Test: `tests/Feature/DemoPhase1SeederTest.php`. Hướng dẫn + tài khoản demo: `README.md`.

**Còn tồn / cần BA xác nhận:**
- [ ] Học vụ (`academic_staff`) không có quyền `lead.convert` nên không tự **Chốt & Xếp lớp** (Sale / Quản lý cơ sở / Admin chốt; Học vụ gán lớp). A6 gọi "CM" gồm cả Học vụ → cần BA xác nhận Học vụ có được chốt không.
- [ ] Hồ sơ học viên (`StudentProfileController::assertClassHasSeat`, "Liên kết lớp khác") vẫn đếm sĩ số theo `class_enrollments` (cùng lỗi đã sửa ở CRM) → nhóm Hồ sơ học viên.
- [ ] Ghép trình độ lớp học thử vẫn chỉ là gợi ý; màn cũ `classes/trial-booking` vẫn còn (xem mục BA 25/09).
- [ ] Nghiệm thu chạy ở mức HTTP/test; chưa đối chiếu ảnh chụp 12 màn mockup Phase 1 (nhánh giao diện đang làm song song).
**Triển khai:** không có migration. Môi trường demo/staging: `php artisan migrate:fresh --seed` hoặc `php artisan db:seed --class=DemoPhase1Seeder`; production **không** đặt `SEED_DEMO=true`.

#### Phase 1 — Đối chiếu 12 màn mockup (nhánh `feat/phase1-mockup-parity`)
**Phương pháp:** mở `code.html` của từng mockup (`ui-full-tinh-nang-menglish/crm-ui-mockup/*`, `quan-ly-de-dau-vao-crm/*`, `epic-6/khach-hang-chot-thanh-cong-xac-nhan`, file BA "Thang điểm + hướng dẫn nhận xét.html") cạnh view Blade; sửa hết P1 (thiếu trường / cột / lọc / nút / khối, dữ liệu sai, nút giả) rồi P2 (nhãn, icon, bố cục, màu theo token). Phần tử mockup trái A6 thì bỏ (ghi rõ bên dưới).

| Màn | P1 đã sửa | P2 đã sửa | Chưa làm / bỏ theo A6 (lý do) |
|---|---|---|---|
| **Pipeline** | Thẻ khách đủ trường mockup: Phụ huynh, Phụ trách, nguồn, SĐT; trạng thái hạn **Quá hạn / Sắp hết hạn / Còn hạn** + "Hạn liên hệ / Hạn chăm sóc tiếp theo: 10:30 Hôm nay"; nút "Sang bước tiếp theo" (CM), "Sửa giai đoạn" trên thẻ; cột Đã chốt hiện "Đã hoàn tất hồ sơ" khi đã Xác nhận chính thức; lọc có nhãn Nguồn / Người phụ trách / Chi nhánh | Tiêu đề cột = chấm màu + TÊN (số lượng), viền trái thẻ theo hạn, màu 8 cột theo mockup (`CrmCustomer::stageStyle/stageBadge`), modal & toast theo token, bỏ thẻ tổng tiền trùng | Bỏ "Hủy chốt" (A6). Thẻ gom sẵn nút "Chốt & Xếp lớp" / "Gán lớp" (hành động thật, mockup không có) |
| **Danh sách khách** | Lọc Từ khóa (tên / SĐT / phụ huynh), **Nguồn**, **Người phụ trách**, Giai đoạn, Chi nhánh (server); cột **Tên phụ huynh**, **Cập nhật gần nhất**; Quản lý nhiều chi nhánh lọc được trong chi nhánh của mình | Bảng `x-ui.data-table`, avatar người phụ trách, badge giai đoạn theo màu cột, phân trang "x - y trong tổng số N khách" | Cột checkbox chọn nhiều: mockup không có thao tác hàng loạt nào → không làm (sẽ là nút giả) |
| **Thêm / Sửa khách** | Nguồn mặc định theo mockup (Landing page, Marketing, Giới thiệu, Vãng lai, Tiktok, Facebook, Google Ads, Chị Liên) khi chưa cấu hình danh mục; chi nhánh chọn sẵn theo người tạo; ghi chú "Không thể thay đổi nếu học viên đã có lớp"; bỏ chữ kỹ thuật "Closing Wizard / Won" | Bố cục hộp 560px: Họ tên + SĐT 2 cột, Tên phụ huynh, Nguồn, Chi nhánh; trường phụ gom vào "Thông tin bổ sung"; nút Hủy / Lưu thông tin (Lưu thay đổi) theo mockup, dùng `x-ui.input/select` (hiện lỗi từng trường) | Mockup là popup; giữ trang riêng (redirect + lỗi validate đã chạy trên trang) |
| **Chi tiết khách** | Header "Chi tiết Khách hàng" + Thất bại / In hồ sơ / Phân công lại; thẻ thông tin có **Chi nhánh**; "Hạn liên hệ tiếp theo" có đồng hồ "Còn 2 giờ 14 phút / Quá hạn …"; tab **Đặt lịch & Kết quả / Thông tin mở rộng**; khối **Gửi kết quả & Phản hồi** (ngày gửi KQ cho phụ huynh + phản hồi, lưu vào lịch sử loại mới `result`); **Nhận xét học thử** luôn hiện (trống: "Chưa có nhận xét từ buổi học thử."); "Tải kết quả (PDF)" (bảng điểm in được); Lịch sử hoạt động: lọc "Tất cả hoạt động", ô ghi chú nhanh + Hình thức (Gọi điện / Zalo/SMS / Trực tiếp) + "Lưu ghi chú", khối "Lý do thất bại", mốc "Bắt đầu tạo hồ sơ" | Bố cục 4/8 cột như mockup, avatar 2 chữ cái, token màu/cỡ chữ, bỏ emoji ở nút loại ghi chú, modal theo token | Bỏ ô CEFR A1/A2/B1 (A6 Q2 — chấm theo khối lớp); "Thất bại / Hủy" chỉ còn "Thất bại" và chỉ khách chưa chốt (A6). "Nhóm tiến độ: Ưu tiên cao" chưa làm — khách CRM chưa có trường/luật ưu tiên (chỉ khách Chờ xếp lớp có `waiting_priority`). Checklist chăm sóc tháng đầu giữ 5 mục hiện có (khóa gắn với việc chăm sóc tự động ngày 3/7/14/30 của Phase 2), chưa đổi nhãn theo 4 mục mockup và chưa có ngày "Dự kiến" → `feat/phase2-final-gaps`: đổi thành 3 mốc gate hoa hồng A6 |
| **Khách không chốt** | Thẻ **Tổng số khách không chốt**; tìm **theo lý do không chốt** (server); "Xuất báo cáo"; cột "Người phụ trách trước khi fail", "Thời điểm dừng" (giờ + ngày); "Nhu cầu: …" dưới tên | Lý do trong khung nền đỏ nhạt, avatar, bảng + phân trang theo token | Menu ⋮ chỉ còn "Xem chi tiết": A6 không cho mở lại / xóa khách Thất bại |
| **Báo cáo doanh số** | Khối **Lý do khách không chốt** (khách, thời điểm ghi nhận, nội dung lý do, nhân viên) + "Tổng cộng N hồ sơ thất bại trong kỳ" + "Xem thêm lý do không chốt" (sang Khách không chốt đúng kỳ/chi nhánh); chi nhánh chỉ liệt kê phạm vi của người xem; sửa thẻ số liệu luôn hiện "↗ Tăng +x" kể cả khi giảm (sai dữ liệu); "Cập nhật: giờ tạo báo cáo" thay "Realtime" | Icon từng giai đoạn như mockup, thẻ số liệu/phễu/bảng theo token, bỏ chữ kỹ thuật "[Plugin: crm_sales_report_tab]", thêm "Ghi chú về nguồn dữ liệu" | Giữ nút chọn nhanh kỳ và bảng hiệu suất theo người phụ trách (có từ trước, mockup không có) |
| **Chốt & Xếp lớp** | Bước 1 có thẻ khách: tên, SĐT, giai đoạn, **trình độ** (lớp xếp sau test), "Đã đóng học phí đăng ký", cảnh báo "Chưa hoàn thành phí đăng ký — hệ thống tự tạo nhắc việc thu phí", "Khi Chốt, hồ sơ khách sẽ được nâng cấp thành tài khoản học viên chính thức"; Bước lớp: "Lớp học phù hợp đề xuất — dựa trên trình độ …", nhãn **Phù hợp trình độ**, lịch học, **giáo viên**, thanh sĩ số, "Số học viên hiện có x / max (ngưỡng khai giảng n)", "Chọn lớp này"; "Xếp lớp sau — Khách sẽ xuất hiện trong mục Chờ xếp lớp"; bỏ học phí giả 12.500.000đ và mã học viên đoán trước "HS000xxx" | Tiêu đề "Quy trình Chốt & Xếp lớp" (bỏ "(Closing Wizard)"), nhãn stepper theo mockup, khung bước theo token | Bỏ khái niệm "cọc" (A6 Q6). Mockup 2 bước, hệ thống giữ 4 bước vì còn học phí / ưu đãi / thu tiền (Q6, Phase 4); popup "Thành công" thay bằng thông báo sau chuyển trang |
| **Khách chốt thành công** (gồm Chờ xếp lớp + Gán lớp) | Khối **Chờ xếp lớp (Cần xử lý gấp)** + Gán lớp (có số ngày chờ); lọc **Lớp học** (mới) + Chi nhánh + tìm kiếm; khối **Khách đã có lớp** + số lượng + **Tải báo cáo chi tiết**; cột Thời điểm chốt (giờ + ngày), chấm lớp | Bảng/thống kê/alert theo `x-ui.*`, avatar ở Chờ xếp lớp | Bỏ "Hủy chốt" (A6). Cột Khóa/Giá trị HĐ gộp vào dòng phụ + thẻ thống kê để bảng đúng mockup |
| **Xác nhận chính thức** | Tiêu đề "Khách hàng đã chốt thành công" + số học viên; thẻ **Chờ xếp lớp** + Gán lớp; lọc **Chi nhánh / Lớp học** + tìm kiếm; cột SĐT, Chi nhánh, "Lớp ID", **Ngày chốt**, **Trạng thái** học viên; **popup Xác nhận học viên** trước khi xác nhận; "Đã là học viên" | Bảng, badge, avatar, tab Chờ xác nhận / Đã xác nhận theo token | Không có trạng thái "Học thử" (A6 Q5). Giữ checklist hồ sơ nhập học (tài khoản, Zalo, giáo trình) của Phase 1 — mockup không có nhưng là điều kiện xác nhận |
| **Quản lý đề test** | Lọc **Cấp độ** (khối lớp theo thang điểm), **Trạng thái** (Hoạt động / Ẩn), **Tìm kiếm tên đề**, "Làm mới" — chạy phía server + phân trang (trước chỉ lọc trên trình duyệt); cột **Loại đề**, **Trạng thái**; nút **Ẩn / Kích hoạt** ngay trên danh sách (route mới `placement-tests.toggle-active`, quyền `placement_test.update`); bảng bài làm theo thang A6 (Nghe, Đọc & Viết, Nói, Tổng/max, Lớp xếp) thay Listening/Reading/Writing/Overall Band; đếm lượt làm theo phạm vi xem | Page header + stat card + data-table theo token; bỏ "khung CEFR/IELTS", "CEFR & Cambridge" | "Loại đề" suy từ mã đề (`placement_test` / `speaking_test`) vì bảng đề chưa có cột loại |
| **Tạo đề** | "Cấp độ" = **khối lớp** (A6 Q2); mã đề tự gợi ý theo khối (`TEST-G3-G4-…`) và server chặn mã không khớp khối (để chấm đúng thang); **Tải file nghe (.mp3)** và **Tải ảnh lên** cho phương án (route mới `placement-tests.media.store`, `SafeUploadService`, đuôi theo nội dung, disk public); **Lưu nháp** (đề ở trạng thái Ẩn), **Lưu và Tiếp theo** (sang câu kế); màn sửa đề bỏ 4 câu hỏi mẫu + file nghe giả khi đề chưa có câu | Tiêu đề "Tạo đề thi mới", bỏ các mức IELTS/Flyers không có thang | Màn Sửa đề vẫn giữ bố cục cũ (sửa câu qua modal), chưa đổi sang bố cục 2 cột của mockup Tạo đề; trình phát audio dùng thẻ `<audio>` của trình duyệt |
| **Test online & thang điểm** | Khối test trên hồ sơ khách: trạng thái 1 có **Chọn cấp độ → Danh sách đề tương ứng**; trạng thái 2 có "Cấp độ: …" + **Gửi lại link**; trạng thái 3 có nhãn "Thang điểm tự động". Form chấm (dùng chung hồ sơ khách + màn chấm bài): **Tự động tạo nhận xét & Xếp lớp**, ô điểm "/ max" + thanh tiến độ + nhận xét gợi ý từng kỹ năng, "Nhận xét gợi ý (Tự động theo Thang điểm)" + "Đã đồng bộ Thang điểm", **Tổng điểm hệ thống / max**, **Đề xuất xếp lớp tự động**; **Lưu bản nháp** (bài vẫn Chờ chấm, khách chưa sang "Đã test") / **Xác nhận kết quả**. Trang Thang điểm: bộ mô phỏng dùng đúng cấu hình `PlacementRubricService` (trước chép tay, nhận xét bị cắt), bỏ mô tả "band điểm 4 kỹ năng" | Form chấm, khối kết quả theo token (màu Nghe/Đọc&Viết/Nói = secondary/tertiary/primary) | Mockup gộp 1 ô nhận xét; giữ 3 ô nhận xét từng kỹ năng (A6 Q2). "Gửi lại link" tạo và sao chép link mới — chưa có kênh gửi SMS/Zalo tự động. Khối thông tin khách bên phải mockup (ảnh, "Ghi chú CM") nằm ở cột trái Chi tiết khách; chưa có trường ảnh học viên |

**Route mới:** `placement-tests.toggle-active` (POST), `placement-tests.media.store` (POST). Không có migration.
**Dữ liệu mới:** lịch sử khách loại `result` ("Gửi kết quả") trong `crm_customer_histories` (cột `type` là chuỗi, không cần migrate).
**Test:** thêm `tests/Feature/Phase1MockupParityTest.php` (12 test, 1 test / màn). Sửa test đang khẳng định UI cũ: `CrmSalesDataScopeTest` ("Sale:" → "Phụ trách:" theo mockup Pipeline), `CrmTest` ("Đã Làm Bài Test (…)" → "Đã làm bài test (…)").
**Assets:** build lại `public/build` (commit riêng "chore: rebuild assets").
**Triển khai:** chạy `php artisan storage:link` nếu máy chủ chưa có (file nghe / ảnh đề lưu disk `public`).

#### Nghiệm thu Phase 2 — Mở lớp → sinh lịch → giao chặng → dạy & điểm danh → Big Test → Zalo PH (nhánh `feat/phase2-seed-acceptance`)
**Kịch bản đã kiểm thử** (`tests/Feature/Phase2AcceptanceTest.php`, một luồng liền mạch qua HTTP bằng đúng vai trò, theo A6/Q4):
- [x] Học thuật soạn giáo trình: tạo giáo trình gắn Trình độ (Chặng 1 tự có), thêm Chặng 2, Unit, Buổi; trùng số buổi bị chặn.
- [x] Học vụ mở lớp có TKB: **trùng GVNN** với lớp khác bị chặn, **trùng phòng** bị chặn, **ngày nghỉ chi nhánh bị bỏ** (13/14 buổi), buổi lưu GV / GVNN / TA. Admin thêm ngày nghỉ sau → buổi trùng bị **hủy + xếp 1 buổi học bù** sau buổi cuối. Dashboard lớp theo ngày hiện lớp.
- [x] Học thuật **mở Chặng 1** (giáo trình lấy theo Trình độ, GV = GV chính); mở chặng thứ hai khi đang có chặng mở → bị từ chối, vẫn 1 chặng mở.
- [x] GV thấy buổi hôm nay ở Lịch dạy; **điểm danh theo buổi** (vắng → danh sách bổ trợ); điểm danh bù buổi đã qua do **Học vụ điểm danh thay** (`user_id` = GV, `recorded_by` = Học vụ); buổi chưa tới bị chặn; nhận xét buổi học; mini test (12/20 = 6/10 → bổ trợ, 15/20 thì không). GV không mở / không lưu được lớp khác (403); GV khác không mở được buổi của lớp này.
- [x] Học vụ thấy dòng bổ trợ và **xếp buổi bổ trợ** (tạo buổi `support`, dòng bổ trợ không còn ở "chờ xếp").
- [x] GV **order đề** (hạn xử lý = ngày thi − 3), GV không tự duyệt được; Học thuật tạo đợt Big Test (tự gắn **chặng đang mở**) rồi **duyệt order kèm link + gắn đợt thi** → đợt thi được phân phối; lệnh **nhắc lịch 7 ngày** báo GV / GVNN / TA, chạy lại không nhắc trùng.
- [x] GV nhập kết quả: 1 HV **vắng thi** (điểm để trống, không lưu 0), điểm < 7 → bổ trợ. GV lớp khác bị 404 cả khi xem lẫn khi lưu. Kết quả chưa duyệt không hiện ở cổng HV. GV không duyệt được; Học thuật duyệt; **kết quả đã duyệt / đã gửi không sửa được**.
- [x] **Gửi Zalo ZNS** ở chế độ live, API Zalo giả lập bằng `Http::fake`: gửi tới **SĐT phụ huynh** (hồ sơ khách CRM) kèm `access_token`, không gửi cho HV vắng thi. Số bị Zalo báo lỗi thì kết quả đó **không** bị đánh dấu đã gửi và chặng chưa đóng. Gửi lại **chỉ gửi phần còn thiếu**; lần thứ ba báo không còn gì để gửi và không gọi API.
- [x] Big Test đã duyệt và gửi đủ → **Chặng 1 đóng** (`closed_by_big_test_id`), **Chặng 2 tự mở** cùng GV, GV nhận thông báo.
- [x] Cổng học viên: lịch học sắp tới của lớp mình, lịch sử điểm danh của mình, kết quả Big Test đã gửi, điểm mini test, nhận xét buổi học; mở hồ sơ học viên khác → 403.
- [x] Quản lý cơ sở không chấm công tay cho lớp của chi nhánh khác (403).

**Lỗi phát hiện và đã sửa:**
- [x] **Lưu nhận xét buổi học lỗi 500**: `TeacherPortalController::remarksStore` không ghi `academic_records.screen_key` (cột NOT NULL), nên GV không lưu được nhận xét nào và cổng HV không có nhận xét. Đã thêm `screen_key` (`TeacherPortalController::REMARKS_SCREEN_KEY`).
- [x] Mở chặng mặc định chỉ tìm giáo trình theo `classes.level` = mã trình độ. Lớp tạo từ CRM hoặc dữ liệu cũ lưu tên hiển thị ("STARTERS (FAM 1)") nên bị báo "trình độ của lớp chưa gắn giáo trình". Nay lấy thêm theo Trình độ của khóa học (`SyllabusController::storeAssignment`).
- [x] Duyệt & phân phối order đề có gắn đợt Big Test nhưng đợt thi vẫn ở "nháp", nên GV không nhập được kết quả ("Đề thi chưa được duyệt và phân phối"). Nay duyệt order có gắn đợt thi thì đợt thi được phân phối (`approveBigTestOrder`).
- [x] Gửi Zalo kết quả dùng SĐT của **học viên** chứ không phải phụ huynh. Nay ưu tiên `crm_customers.parent_phone` của khách đã chốt thành học viên này (không có thì dùng SĐT học viên). Mẫu ZNS lấy tên và hotline trung tâm từ `CenterInfo` thay cho chuỗi viết cứng.
- [x] Chấm công tay không kiểm tra phạm vi lớp (A3). Nay chỉ cho lớp người dùng được xem (`ClassModel::visibleTo`): Quản lý cơ sở chỉ chấm được lớp chi nhánh mình.
- [x] `MasterEntitySeeder` nhân bản 1 dòng chấm công mỗi lần `db:seed` (so `teaching_date` kiểu ngày với chuỗi).

**Đối chiếu A3 + Phase 2 (Phần C):**

| Mục | Kết quả | Bằng chứng / ghi chú |
|---|---|---|
| A3 · Sinh lịch không bỏ ngày nghỉ | ✅ Xong | `SessionScheduleService::generate/withoutHolidays`, tạo lớp bỏ ngày nghỉ; test nghiệm thu |
| A3 · Ngày nghỉ thêm sau (dời lịch) | ✅ Xong | `HolidayRescheduleService`: hủy + xếp bù cuối lịch, khôi phục khi xóa ngày nghỉ; test nghiệm thu + demo `HOL-DEMO2-*` |
| A3 · Điểm danh gắn cứng hôm nay | ✅ Xong | Điểm danh theo `class_session_id`, điểm danh bù, Học vụ điểm danh thay; test |
| A3 · Tự chấm công (lớp bất kỳ, mặc định 2 giờ) | ✅ Xong + sửa thêm | Check-in chỉ tính buổi thật (Phase 3). **Sửa trong nhánh này:** chấm công tay chỉ cho lớp trong phạm vi. Học vụ vẫn xem được mọi lớp → xem câu hỏi BA |
| A3 · GVNN không kiểm tra trùng lịch | ✅ Xong | `findConflict` tính GV / GVNN / TA / phòng; test (GVNN bận → chặn) |
| A3 · Sĩ số / ngưỡng khai giảng / Xác nhận chính thức | ✅ Xong (Phase 1) | `hasSeatsFor`, `min_students`, màn Xác nhận chính thức; `StudentProfileController::assertClassHasSeat` đã dùng `hasSeatsFor()` |
| A3 · Trạng thái HV / nghỉ học vẫn trong lớp | ✅ Xong | Q5 6 trạng thái; Thôi học bỏ `current_class_id`; `roster()` bỏ Bảo lưu / Thôi học |
| A3 · Sinh mã HV trùng | ✅ Xong | `DocumentCodeGenerator` |
| A3 · Dashboard lớp / lịch dạy GV dùng dữ liệu giả | ✅ Xong | `ClassDashboardService`, `teacher.home` lấy từ `class_sessions`; test |
| A3 · Vắng / điểm < 7 → bổ trợ | ✅ Xong | `SupportListService` (vắng, mini test, Big Test) + xếp buổi bổ trợ; test + demo |
| A3 · Zalo gửi lại cả lớp / lỗi vẫn báo thành công | ✅ Xong | Chỉ gửi kết quả `parent_notified = false`, chỉ đánh dấu đã gửi khi API trả `error = 0`; test với `Http::fake` |
| A3 · Kết quả đã duyệt/gửi vẫn sửa được; vắng lưu 0 | ✅ Xong | `LOCKED_STATUSES`, cờ vắng thi (điểm null); test |
| A3 · Giãn tiến độ không đổi lịch; lý do từ chối | ✅ Xong | `ScheduleExtensionService`; test có sẵn `Phase2SyllabusTest` |
| A3 · Trình soạn chỉ sửa được giáo trình đầu tiên | ✅ Xong | Chọn được giáo trình bất kỳ; test |
| A3 · 1 lớp được giao 2 chặng cùng lúc | ✅ Xong | Service + UNIQUE `open_class_id`; test nghiệm thu |
| A3 · Order đề không tới màn duyệt | ✅ Xong + sửa thêm | Bảng `big_test_orders`; test. **Sửa trong nhánh này:** duyệt order có gắn đợt thi thì phân phối đợt thi |
| A3 · Upload tài liệu / đề xuất sửa chỉ là giao diện | ✅ Xong | `SyllabusDocument`, `SyllabusChangeProposal`; test có sẵn |
| A3 · Mọi GV/TA xem được passcode đề | ✅ Xong | `BigTest::passcodeVisibleTo` dùng ở cả 2 view |
| A3 · Media Manager đụng file module khác | ✅ Xong | Chỉ quản lý `uploads/media` |
| C2.1 Lớp và lịch học | ✅ Xong | Như các dòng trên |
| C2.2 Giảng dạy, điểm danh, bổ trợ | ✅ Xong + sửa thêm | **Sửa trong nhánh này:** lỗi 500 khi lưu nhận xét |
| C2.3 Giáo trình (soạn, giao chặng, tài liệu, đề xuất, giãn tiến độ) | ✅ Xong + sửa thêm | **Sửa trong nhánh này:** mở chặng theo trình độ của khóa học |
| C2.4 Big Test (order, duyệt, nhắc 7 ngày, GV chỉ nhập lớp mình, duyệt, Zalo PH) | ✅ Xong (Zalo còn thiếu cấu hình thật) | Xem dòng Zalo ZNS |
| C2.5 Học viên (lộ trình, điểm danh thật, cổng HV, chăm sóc tháng đầu, sinh nhật) | ✅ Xong | `FirstMonthCareService` (ngày 3/7/14/30), lệnh sinh nhật; test + demo |
| Zalo ZNS | ⚠️ Có tích hợp thật, **chưa vận hành** | `ZaloZnsService`: chế độ `live` gọi `POST business.openapi.zalo.me/message/template` với `access_token`, template `ZALO_ZNS_TEMPLATE_BIGTEST`. Mặc định `sandbox` chỉ ghi log **nhưng vẫn trả thành công**, nên kết quả bị đánh dấu "Đã gửi PH" dù không có tin nào. Chưa có: làm mới access token (hết hạn sau khoảng 25 giờ), lưu `msg_id` / trạng thái giao tin, template ZNS đã được Zalo duyệt (id mặc định `342918` chỉ là giá trị giả định) |
| Mockup các màn Phase 2 | 🟦 Nhánh khác đang làm | Nhánh này không sửa view |

**Dữ liệu demo:** `Database\Seeders\DemoPhase2Seeder` (`DatabaseSeeder` gọi ngay sau `DemoPhase1Seeder`, cùng điều kiện môi trường). Seeder đi qua controller / service thật, **ép Zalo về sandbox**, idempotent, chạy khoảng 1,5 giây. Số dòng sau `migrate:fresh --seed` trên SQLite trắng:
- Giáo trình: 2 giáo trình (5 chặng / 10 unit / 30 buổi). Chặng của lớp: 4 đang mở, 1 đã đóng (CG FAM 1 đã sang Chặng 2).
- Giảng dạy: 132 lượt điểm danh (18 vắng, 10 vắng có phép); 12 nhận xét buổi; 40 điểm mini test; 8 bài tập / 12 bài nộp.
- Bổ trợ: danh sách 45 dòng (vắng 28, mini test 12, Big Test 5); 8 buổi bổ trợ (4 đã dạy xong).
- Big Test: 6 order đề (4 đã duyệt, 1 chờ duyệt, 1 bị từ chối); 4 đợt thi; 20 kết quả (6 đã gửi PH, 7 đã duyệt, 7 chờ duyệt; 3 vắng thi).
- Lịch và việc: 2 buổi hủy do nghỉ lễ + 2 buổi học bù; 12 việc trực ca TA; 68 việc chăm sóc tháng đầu; 7 nhắc sinh nhật; 2 nhắc lịch Big Test.

Test: `tests/Feature/DemoPhase2SeederTest.php` kiểm tra chạy lại không đổi số dòng, không gọi HTTP ra ngoài và mở được các màn bằng tài khoản demo. Tài khoản demo: xem `README.md`.

**Còn tồn / cần BA xác nhận:**
- [ ] **Zalo vận hành thật:** cần OA, template ZNS đã được duyệt, cơ chế làm mới access token, lưu mã tin / trạng thái giao tin. Ở sandbox hệ thống vẫn đánh dấu "Đã gửi PH" → cần BA/PO chọn: đổi nhãn thành "Đã gửi (thử nghiệm)" hay không đánh dấu khi chạy sandbox.
- [ ] **SĐT phụ huynh:** hồ sơ học viên chưa có cột SĐT phụ huynh, chỉ học viên chốt từ CRM mới có. Có thêm cột `students.parent_phone` không? ✅ `feat/phase2-final-gaps`
- [ ] **Ai duyệt order đề / kết quả Big Test:** quyền `syllabus.approve_adjustment` đang cấp cả cho Học vụ (`academic_staff` có `syllabus.*`), trong khi luồng ghi "Học thuật duyệt". Có thu hồi quyền này của Học vụ không? ✅ `feat/phase2-final-gaps` (chỉ Học thuật + Admin)
- [ ] **Phạm vi của Học vụ:** Học vụ xem / điểm danh thay / chấm công tay được **mọi lớp ở mọi chi nhánh** (chỉ Quản lý cơ sở bị giới hạn chi nhánh), trong khi A6 giới hạn Học vụ theo chi nhánh ở CRM và hồ sơ học viên. Có giới hạn lớp theo chi nhánh cho Học vụ không?
- [ ] Duyệt order đề **không tự tạo** đợt Big Test: Học thuật tạo đợt thi rồi gắn vào order. Order vẫn nhập tên chặng tự do. ✅ `feat/phase2-final-gaps`
- [ ] Nhận xét buổi học vẫn lưu theo **lớp + ngày**, chưa theo buổi. Trạng thái kết quả "Nháp" chưa được dùng: GV lưu là thành "Chờ duyệt" luôn. ✅ nhận xét theo buổi (`feat/phase2-mockup-classes`), nháp kết quả (`feat/phase2-final-gaps`)
- [ ] Mốc chăm sóc tháng đầu (ngày 3/7/14/30) khác 3 mốc của gate hoa hồng A6 (buổi 1, buổi 4–5, đủ 30 ngày) → chốt khi làm gate hoa hồng ở Phase 3. ✅ `feat/phase2-final-gaps`
- [ ] Nghiệm thu mới chạy ở mức HTTP/test. Đối chiếu ảnh chụp mockup các màn Phase 2 do nhánh giao diện làm song song.

**Test:** toàn bộ bộ test xanh — 727 test (thêm `Phase2AcceptanceTest`, `DemoPhase2SeederTest`). Sửa 1 test cũ: `PayrollModuleTest::test_can_record_manual_timesheet` tạo Quản lý cơ sở và lớp không có chi nhánh → nay cùng chi nhánh (theo quy tắc phạm vi mới).
**Triển khai:** không có migration. Môi trường demo/staging: `php artisan migrate:fresh --seed`, hoặc `php artisan db:seed --class=DemoPhase2Seeder` (chạy sau Phase 1). Production **không** đặt `SEED_DEMO=true`. Muốn gửi Zalo thật phải đặt `ZALO_ZNS_MODE=live` + `ZALO_ACCESS_TOKEN` + `ZALO_ZNS_TEMPLATE_BIGTEST`.

#### Phase 2 — Đối chiếu mockup nhóm Giáo trình & Big Test (nhánh `feat/phase2-mockup-syllabus`)
**Phương pháp:** mở `code.html` của `public/roundcuoi-kieulien/01_Web_Admin/01..08_*` và `03_Cong_Giao_Vien/07..11, 14_*` (bản trùng trong `ui-full-tinh-nang-menglish/sylabuss`) cạnh view Blade; sửa hết P1 rồi P2 (token `font-h*`, `text-body-*`, `surface-*`, `x-ui.*`). Phần tử mockup trái A6 (Q4: Giáo trình → Chặng (Big Test cuối chặng) → Unit → Buổi, mỗi lớp 1 chặng mở, chặng đóng khi Big Test duyệt & gửi, chặng kế tự mở) thì theo A6.

| Màn | P1 đã sửa | P2 đã sửa | Chưa làm / bỏ theo A6 (lý do) |
|---|---|---|---|
| **Soạn syllabus theo chặng** (Admin 02) | Buổi có **Nội dung bài học chính** (cột mới `syllabus_lessons.content`) cạnh Mục tiêu (Target) / Bài tập về nhà (Homework); khối "Thông tin chung chặng học" có **Chính sách mở khóa** (chỉ đọc, theo A6), link tổng quan + **Xem thử** + khung preview ảnh; mỗi chặng có "Tổng số: NN buổi" và nút **Thêm buổi học mới vào chặng**; thanh hành động dính đáy Hủy / Lưu chặng học | Tiêu đề/mô tả theo mockup, icon tô đầy, badge tổng buổi | Không cho chọn "Mở khóa theo tuần / thủ công" (A6: chặng chỉ tự mở sau Big Test). Bỏ "Đã tự động lưu nháp" (không có autosave — giữ sự thật). Thanh định dạng rich-text (B/I/list) chưa làm: nội dung lưu dạng văn bản thuần |
| **Quản lý tài liệu giáo trình** (Admin 01) | **Chọn chặng học** lấy từ chặng thật của giáo trình (cột mới `syllabus_documents.stage_id`, kiểm tra chặng thuộc giáo trình; tài liệu cũ vẫn hiện `stage_name`); đối tượng xem Admin / Học vụ / Học thuật (luôn xem, khóa chọn) + Giáo viên / Trợ giảng; banner **"Khóa tải xuống — Giáo viên chỉ được phép xem trực tuyến"**; vùng **kéo thả** file; bảng: Trạng thái Có thể tải / Chỉ xem online, thao tác Tải xuống (chỉ khi cho tải) / Xem chi tiết / Xóa | Tiêu đề "Danh sách tài liệu đã tải lên", badge đối tượng, nút theo token | Mockup giới hạn "PDF, DOCX, PPTX (50MB)" — giữ danh sách định dạng + 100 MB đã chốt Phase 2 |
| **Xem tài liệu giáo trình** (GV 08) | 3 tab **Tài liệu / Tổng quan syllabus / Nội dung buổi học**; **Tìm kiếm + Lọc**; danh sách nhóm theo giáo trình · chặng, nhãn Có thể tải / Chỉ xem / Đã xem; khung xem có **watermark "MENGLISH INTERNAL ONLY" + email người xem** khi không cho tải, "Bảo mật nội dung", **Toàn màn hình**, **Đánh dấu đã xem** (bảng mới `syllabus_document_views`); tab Tổng quan: thẻ từng chặng (mô tả, trạng thái Đang học / Đã xong / Chưa mở, **Xem mục lục chặng**); nội dung buổi có **Hoạt động** | Breadcrumb "Giáo trình & Tài liệu › giáo trình", token | Thu/phóng %, "Trang 1/24" của mockup: dùng trình xem PDF của trình duyệt |
| **Đề xuất sửa giáo trình** (GV 09) | **Chọn buổi học (Tùy chọn)** (cột mới `syllabus_change_proposals.lesson_id`, kiểm tra buổi thuộc giáo trình, tự gắn Unit); "Mô tả thay đổi đề xuất"; **Lịch sử đề xuất** có **Lọc** trạng thái (server), cột Giáo trình / Buổi, lý do từ chối dưới trạng thái | Form gọn như mockup, trường phụ (loại, nội dung cũ, lý do, file) gom vào "Thông tin bổ sung" | Nhãn "Đã áp dụng" của mockup giữ "Đã duyệt" vì chưa tự áp nội dung (xem dưới) |
| **Chi tiết đề xuất sửa giáo trình** (Admin 05) | Thông tin chung: Giáo trình, **Buổi học/Unit cần sửa**, Người đề xuất + **vai trò**; Nội dung cũ / mới / lý do; Trạng thái phê duyệt: trạng thái hiện tại, **Người phê duyệt** ("Chưa phân công — Ban Học thuật"), **một ô "Phản hồi từ người duyệt"** dùng chung cho Phê duyệt / Từ chối (từ chối bắt buộc), **Lịch sử xử lý**; sau khi duyệt có nút **Mở buổi để cập nhật** (màn Soạn syllabus) | Breadcrumb "Quản lý giáo trình › Chi tiết đề xuất", bố cục 2 cột + danh sách bên trái | **Duyệt chưa tự áp nội dung mới vào buổi / chưa tăng phiên bản** — Part D/BA chưa có quyết định cho phép; Học thuật sửa tay (có nút dẫn thẳng tới buổi) |
| **Giao chặng học cho giáo viên** (Admin 03) | Tiêu đề mockup; form "Thiết lập chặng mới": Chọn lớp / **Chọn giáo viên (ID nhân viên)** / Chọn chặng / **Ngày bắt đầu** (→ `opened_at`, mốc tính tiến độ) / dự kiến hoàn thành; **Lưu ý nghiệp vụ (R19)**; "Xác nhận giao chặng"; bảng **Lịch sử phân quyền chặng học**: lớp + **cơ sở - phòng**, GV (avatar + ID), chặng, **Thời hạn** (ngày bắt đầu + "Tự động đóng theo sự kiện" / ngày đóng), trạng thái, lọc **trạng thái** (server); hành động **Chỉnh sửa** chặng đang hiệu lực (đổi GV / ngày, báo GV mới — route `syllabus.assignments.update`); **Xem log hệ thống**; thẻ "Cần hỗ trợ?" | Token, badge chặng | "Gia hạn" chặng đã thu hồi: bỏ (A6 — chặng đã đóng không mở lại; mở chặng khác qua "Chuyển chặng"). Nhãn trạng thái giữ "Đang học / Đã đóng" (kèm "Đang hiệu lực") thay "ĐÃ THU HỒI" |
| **Xin điều chỉnh tiến độ** (GV 14) | Chỉ chọn **lớp đang có chặng mở** ("Lớp - Chặng N (mã)"), trạng thái trống **"Không có chặng học nào đang mở"**; **Số buổi cần thêm 1 / 2 buổi** (radio); bỏ "Loại điều chỉnh" soạn sẵn (server tự đặt "Xin giãn tiến độ thêm N buổi"); bảng Ngày gửi / Lớp / Chặng / Lý do / Số buổi thêm / Trạng thái (+ lý do từ chối) | Tiêu đề bỏ "(Cổng Giáo viên)", SLA 24 giờ đồng bộ với màn duyệt | Server vẫn nhận yêu cầu cho lớp chưa mở chặng (API cũ, không gắn chặng) — UI không còn cho chọn ✅ `feat/phase2-final-gaps` |
| **Duyệt yêu cầu điều chỉnh tiến độ** (Admin 04) | Master–detail dạng thẻ: GV, **SLA Còn hạn / Quá hạn** (24 giờ từ lúc gửi), **Lớp - Chặng**, ngày gửi, **Xin thêm N buổi**; **Lọc** trạng thái (mặc định "chờ duyệt", có "Tất cả"); chi tiết có mã nhân viên, lý do (trích dẫn); luồng **Từ chối → nhập lý do → Xác nhận từ chối / Hủy** | Token, avatar | — |
| **Chặng đang dạy & Order Test** (GV 07) + **Lịch dự kiến Big Test** (GV 10/11) | Màn mới `syllabus.teaching-stages` (menu "Giáo trình & Big Test (GV)"): thẻ mỗi lớp đang mở chặng — chặng, **Bắt đầu**, vai trò (GV chính / **Trợ giảng (Chỉ xem)**), trạng thái đề **Đã order - Chờ HT duyệt / Đã có đề**, **Ngày dự kiến Big Test** (GV chính / Học thuật **Chọn ngày → Lưu / Sửa ngày → Cập nhật**, cột mới `syllabus_assignments.expected_big_test_date`), nút **Order đề Big Test**; trạng thái trống "Chưa được giao chặng nào". **Order đề lấy chặng đang mở của lớp** (`big_test_orders.syllabus_stage_id`), bỏ ô nhập tên chặng tự do; lớp chưa mở chặng → không order được; chặn order trùng khi order cùng chặng còn chờ duyệt | Màn order: khối "Chặng đang dạy" chỉ đọc, nhãn "Đã order - Chờ HT duyệt" | GV 11 "Report tháng" trùng nội dung GV 10 → gộp; mockup hub trỏ 07/10 sang màn mới |
| **Duyệt & phân phối đề Big Test** (Admin 06) | Danh sách **Yêu cầu chờ duyệt** + **Tìm tên lớp** (server) + **Cảnh báo SLA** (hạn hôm nay/ngày mai) / Trễ hạn, chặng · GV · ngày thi · "Order: x giờ trước"; chi tiết **CLASS ID**, khóa học · cơ sở, ngày thi dự kiến, GV, chặng; "Yêu cầu từ Giáo viên"; "Phân phối đề": link folder + **Xem trước tệp**, cảnh báo trễ/sắp hết hạn SLA kèm hạn chót, **Ghi chú nghiệp vụ** (GV chỉ xem Speaking sau phân phối), **Từ chối yêu cầu → Xác nhận**; bảng đợt thi có cột chặng + **Gắn chặng** (đổi chặng cho đợt Big Test đã tạo, route `syllabus.big-tests.stage`, chỉ chặng thuộc giáo trình của lớp; gắn xong kiểm tra đóng chặng như luồng thường). Duyệt order có gắn đợt thi chưa có chặng → gắn chặng của order | Bố cục 40/60 theo mockup, token | Duyệt order vẫn không tự tạo đợt Big Test (phòng thi/giờ thi chưa có trong order). ✅ `feat/phase2-final-gaps` "GV chỉ xem phần Speaking" là ghi chú, hệ thống chưa tách quyền theo phần đề ✅ `feat/phase2-final-gaps` (link riêng phần Speaking) |
| **Nhắc lịch Big Test** (Admin 08) | Đổi đúng mục đích mockup: danh sách **chặng đang mở sắp đến hạn thi (≤ 7 ngày) mà đề chưa duyệt** — **Mã chặng** (`CH-năm-id`), lớp / chặng, **Ngày thi dự kiến** (đợt Big Test của chặng → ngày GV đặt → ngày thi trong order), **Trạng thái đề** (Chưa order / Chưa duyệt đề), **Số ngày còn lại**; thẻ **Tổng số chặng / Khẩn cấp (1-2 ngày)**; trạng thái trống **"Tất cả đều ổn!"**. Giữ bảng đợt thi đã tạo (nhắc lịch học viên, xem điểm) | Token, stat card | Lệnh `bigtests:remind-upcoming` vẫn chỉ nhắc theo đợt thi đã tạo (chưa nhắc theo ngày dự kiến GV đặt) ✅ `feat/phase2-final-gaps` |
| **Duyệt kết quả Big Test & gửi phụ huynh** (Admin 07) | Tiêu đề + breadcrumb mockup; **khung xét duyệt từng học viên** (`?result=`, link "Xem & duyệt" ở mỗi dòng): học viên + mã HV, lớp + GV, chặng, **Điểm chi tiết**, **Link video bài thi**, **Nhận xét của giáo viên/HT**, **Tổng điểm**, **Hạn trả kết quả "Còn N ngày / Quá hạn"**, **Trạng thái dữ liệu**, **Người gửi kết quả** (người chấm), **Người duyệt (Hiện tại)**, **Duyệt & Gửi phụ huynh** một học viên (route `syllabus.big-tests.results.approve-send`: duyệt + gửi Zalo; vắng thi chỉ duyệt; sau đó đóng chặng nếu đủ điều kiện), Quay lại, thẻ **Hợp lệ / Đã gửi phụ huynh**; bảng có hạn trả KQ | Nút theo `x-ui.button` | — |

**Route mới:** `syllabus.documents.viewed`, `syllabus.assignments.update` (PUT), `syllabus.teaching-stages`, `syllabus.assignments.expected-date`, `syllabus.big-tests.stage`, `syllabus.big-tests.results.approve-send`.
**Migration:** `2026_09_30_120000` (`syllabus_lessons.content`), `120100` (`syllabus_documents.stage_id` + bảng `syllabus_document_views`), `120200` (`syllabus_change_proposals.lesson_id`), `120300` (`big_test_orders.syllabus_stage_id`), `120400` (`syllabus_assignments.expected_big_test_date`). Chỉ thêm cột/bảng, không chuyển dữ liệu.
**Menu:** nhóm mới "Giáo trình & Big Test (GV)" (GV/TG + Học thuật/Quản lý): Chặng đang dạy & Order Test, Xem bài giảng, Đề xuất sửa giáo trình, Xin điều chỉnh tiến độ (3 mục sau chuyển từ nhóm "Syllabus & Giáo trình" để GV thấy được).
**Quyết định tạm (chờ BA):** SLA duyệt giãn tiến độ = 24 giờ (khớp thông báo SLA trên màn GV); hạn trả kết quả Big Test = ngày thi + 7 ngày (`BigTest::RESULT_DEADLINE_DAYS`); "Cảnh báo SLA" order đề = hạn xử lý hôm nay/ngày mai; ngày dự kiến Big Test do GV chính (hoặc Học thuật) đặt, trợ giảng chỉ xem.
**Không thuộc phạm vi:** GV 12/13 "Xác nhận Quiz" (bài tập/quiz — nhánh cổng GV).
**Test:** `tests/Feature/Phase2MockupSyllabusTest.php` (8 test, 1 test / màn hoặc cặp màn). Sửa test đang khẳng định UI/hành vi cũ: `UiSweepTest` ("Chặng của các lớp" → "Lịch sử phân quyền chặng học"), `SyllabusModuleTest` (tiêu đề "Bảng Điểm & Kết Quả Khảo Thí Big Test" → "Duyệt kết quả Big Test & gửi phụ huynh"), `Phase2BigTestTest::test_teacher_order_reaches_approval_screen_with_deadline` (order phải có chặng đang mở, tên chặng lấy từ chặng thay cho ô tự do; nhãn "Đã order - Chờ HT duyệt"), `SyllabusStageMigrationTest` (gỡ / dựng lại 4 migration mới có khóa ngoại tới chặng/buổi quanh bước down()/up() của migration Q4).
**Triển khai:** `php artisan migrate`; build lại asset (`npm run build`).

#### Phase 2 — Đối chiếu mockup nhóm Lớp học & Cổng giáo viên (nhánh `feat/phase2-mockup-classes`)
**Phương pháp:** mở `code.html` của mockup (`ui-full-tinh-nang-menglish/cau-hinh-trinh-do`, `phan-cong-cong-viec/tkb_*`, `phan-cong-cong-viec/dashboard_*`, `epic-5/cau-hinh-ngay-nghi`, `epic-6/ho-so-hoc-sinh-*`, `epic-6/chi-tiet-ho-so-hoc-sinh-*`, `public/roundcuoi-kieulien/03_Cong_Giao_Vien/01–06, 16`) cạnh view Blade; sửa hết P1 rồi P2 (token màu/chữ/khoảng cách, `x-ui.*`). Phần tử mockup trái A6 thì bỏ (ghi rõ).

| Màn | P1 đã sửa | P2 đã sửa | Chưa làm / bỏ theo A6 (lý do) |
|---|---|---|---|
| **Cấu hình trình độ** | Ô **Mô tả**; công tắc **Trạng thái hoạt động** cả khi tạo mới (trước luôn "Hoạt động"); **kéo thả sắp xếp** (cột STT, `course_levels.sort_order`, route `course-levels.reorder`, chỉ khi không lọc); cảnh báo xóa theo mockup "**Không thể xóa** — Trình độ này đang có N lớp học và M học sinh tham chiếu…" (số học sinh thật theo lớp chính) | Form thêm/sửa là **panel trượt phải** "Thêm/Sửa Trình độ đào tạo" (Thông tin chung / Thiết lập Syllabus), thẻ Syllabus "Cập nhật: … \| Trạng thái: Hiện tại" + nút gỡ (link_off) + "Gắn Syllabus mới", placeholder "Tìm kiếm trình độ...", badge trạng thái dạng pill | Danh sách **nhiều phiên bản Syllabus** (V1 lưu trữ, V2 hiện tại) → chưa có lịch sử gắn giáo trình theo trình độ (mỗi trình độ 1 giáo trình, Q4); giữ thêm ô Chuẩn đầu ra / Số buổi / Thời lượng (dữ liệu đang dùng ở khóa học) |
| **TKB** | Banner **"Cảnh báo xung đột lịch"** ngay trong khung cấu hình khi lưu bị trùng (trước chỉ báo lỗi chung); **tìm lớp phía server** (`class_q`, trước lọc trên trình duyệt); "Năm học áp dụng" là **ô chọn** | Nút "Xuất Excel" theo mockup (bỏ nút Dashboard lớp trùng menu), cột Giảng viên "GV: …", icon info "Giá trị tự động tính toán từ số ca" ở ô nhân sự chưa lưu | Giữ thêm cột Phòng / TA có ca và bảng "Buổi học bị hủy do ngày nghỉ" (dữ liệu thật, mockup không có) |
| **Dashboard lớp học (ngày/tuần)** | Cảnh báo **"Tài khoản chưa gán chi nhánh, liên hệ Quản trị viên."**; **"Lọc thêm"** (giáo viên, trạng thái điểm danh — server); nút **Chấm công** theo cửa sổ 24h: chưa tới giờ học → khóa + "Chỉ được chấm công trong vòng 24h sau giờ học", quá 24h → "Điểm danh bù" (`ClassDashboardService::attendanceWindow`); "Xem tất cả trợ giảng"; chân bảng "Hiển thị N buổi học của M lớp học" | Mô tả trang, nhãn "Xuất báo cáo", "Chọn ngày / Chọn tuần", "Trợ giảng làm việc hôm nay" | Quá 24h **không khóa** điểm danh (Phase 2 vòng 2 cho điểm danh bù 30 ngày, Học vụ rà soát) — chỉ đổi nhãn. Bảng ngày không phân trang (số buổi/ngày nhỏ) |
| **Ngày nghỉ** | Gộp **danh sách + form Thêm/Sửa bên phải** trên một trang (bỏ trang form riêng); khối **"Lưu ý nghiệp vụ"**; **tìm kiếm** tên/mã (server); **mã tự sinh `HOL-YYYY-NNN`** khi để trống (`DocumentCodeGenerator::holidayCode`); **không chọn chi nhánh = Toàn hệ thống** ("* Để trống nếu muốn áp dụng cho tất cả chi nhánh.") | Mũi tên ngày bắt đầu → kết thúc, badge phạm vi, dòng đang sửa tô nền, khối "verified" ghi đúng hành vi thật (hủy buổi trùng + xếp bù), `x-ui.*` | Cấu hình ngày nghỉ theo **từng lớp**: mockup ghi "đang phát triển" — không làm |
| **Hồ sơ học sinh — danh sách** | Cột **Họ tên & Ngày sinh**, **Thông tin liên hệ** (SĐT + email), **Lớp hiện tại** (chip mã lớp / "Chưa có lớp"); **chip trạng thái chọn nhiều** (`statuses[]`, vẫn nhận `status`); nút **"Liên kết lớp khác"** trên từng dòng (popup chọn lớp cùng chi nhánh, sĩ số giữ chỗ thật) cho người có `student.assign_class`; ô **"Tổng số học sinh"**; "Số hàng mỗi trang" 10/20/50 | Tiêu đề "Hồ sơ học sinh", nhãn lọc theo mockup, avatar, badge màu theo trạng thái (`Student::STATUS_COLORS`), nút "Lọc dữ liệu" | Chip **"Học thử"** bỏ theo A6 Q5 (6 trạng thái). Bỏ cột Mục tiêu/Chuyên cần giả (tính từ cột cũ `attended_lessons/total_lessons`, không phải điểm danh thật) |
| **Hồ sơ học sinh — chi tiết** | Ô **Trường học** (`students.school`); lộ trình có cột **Nội dung bài học** (Unit + tên buổi theo giáo trình lớp — `SessionLessonService`: buổi chính khóa thứ N ↔ `session_no` N), trạng thái **Đã hoàn thành / Sắp diễn ra / Chưa bắt đầu / Đã hủy**; nút **lọc** buổi + **tải Excel** lộ trình; **"Xem toàn bộ N buổi học"** (hiện 10 buổi đầu); menu **"Đổi trạng thái"** (6 trạng thái A6, xác nhận khi Thôi học); thẻ lớp có **Lịch học / Thời gian / "Còn N tháng"** + "Chi tiết lộ trình"; chuyên cần có **Tổng số buổi**; học phí có "Thêm phiếu thu", trạng thái phiếu "Đã thanh toán / Chờ xử lý" | Breadcrumb + tiêu đề "Chi tiết hồ sơ học sinh", thẻ hồ sơ/sửa theo bento mockup, "Quyền: <vai trò>", vòng chuyên cần, toàn bộ màu/chữ theo token | Cột **"Học thử"** bỏ theo A6 Q5/Q1 (học thử gắn với khách CRM, không với hồ sơ học sinh). **Ảnh học sinh** (nút sửa ảnh) chưa làm — chưa có trường ảnh |
| **Hồ sơ học sinh (phân quyền)** | Cùng bố cục màn chi tiết (partial `students/partials/profile`), server chỉ render module có quyền: lớp học/điểm danh (`attendance_student.view` hoặc `student.update`), liên hệ, học phí (`tuition.view`); **không có quyền sửa → ô khóa + "Bạn không có quyền sửa thông tin này"**, không có quyền đổi trạng thái → nút khóa + **"Quyền xem duy nhất"** | Thanh "Đang xem với vai trò" + 3 chip quyền ✓/✕ | — |
| **Cổng GV — Trang chủ (App shell)** | **"Tổng quan hôm nay"**, "Lịch dạy hôm nay — Thứ …, dd/mm", "Bạn có N ca dạy…", banner **"Ca dạy lúc HH:MM sắp bắt đầu!" + "Điểm danh ngay"**; ca dạy hiện "HH:MM - HH:MM • Phòng …, Chi nhánh"; 4 thẻ dữ liệu thật: **Học sinh cần chú ý** (mini test < 7/10, 30 ngày), **Lương tạm tính** (giờ đã chấm công × đơn giá riêng hiệu lực), **Báo cáo chấm công** (ca chờ duyệt, buổi chưa điểm danh), **Vi phạm & Khoản trừ** (biên bản trong tháng); nút Nhận xét / Giao bài theo **buổi** | **Giao diện điện thoại + thanh điều hướng dưới** (Lịch dạy · Bảng công · Thông báo · Cá nhân — `teacher/partials/bottom-nav`, dùng cho mọi màn cổng GV), token màu/chữ | Sidebar riêng của mockup (Giáo trình, Đề xuất sửa đổi…) dùng sidebar chung của app. Lương tạm tính chưa gồm phụ cấp/KPI/khấu trừ (ghi rõ trên thẻ) |
| **Cổng GV — Điểm danh** | Tiêu đề "Điểm danh — Lớp, dd/mm/yyyy" + **Khung giờ / Phòng · Chi nhánh / Sĩ số lớp**; chỉ báo **cửa sổ ±24h**; **4 bộ đếm** Đúng giờ / Đi muộn / Nghỉ có phép / Nghỉ không phép (cập nhật trực tiếp); khối **Quy tắc nghiệp vụ**; nhãn 4 trạng thái theo mockup; **ghi chú bắt buộc khi Nghỉ có phép / Nghỉ không phép (kiểm tra cả server)** | Bảng Roster STT / Học sinh / Trạng thái / Ghi chú, tô màu dòng theo trạng thái, chân "Phiếu điểm danh sẽ được ghi đè (upsert)…" | Ngoài cửa sổ 24h **không khóa** (điểm danh bù Phase 2) — chỉ báo "Ngoài cửa sổ 24h — điểm danh bù". Tên tiếng Anh học sinh (Lucas…) — chưa có trường |
| **Cổng GV — Nhận xét buổi học** | **Lưu theo BUỔI học** (việc tồn Phase 2 vòng 2: trước theo lớp + ngày) — mã `{lớp}-{ngày}-s{buổi}`, chọn buổi, điền sẵn từ bản cũ; cột **Monsters (Nhóm)** + **Monsters (Thưởng)**; **"Lưu nháp"** (không hiện ở cổng học viên) / "Lưu nhận xét"; "Buổi N: dd/mm/yyyy"; **sửa lỗi lưu nhận xét luôn 500** (thiếu `screen_key` NOT NULL) | Bảng theo token, avatar, badge điểm danh, trạng thái Bản nháp/Đã lưu | — |
| **Cổng GV — Giao bài tập** | Chọn **Buổi học** (Buổi N + nội dung giáo trình), **Hạn nộp ngày giờ**, **Ghi chú nhắc cả lớp**, **Link YouTube / File nghe (SafeUploadService) / Link Quizizz**, **6 hạng mục** (Quay video, Viết từ vựng, Workbook, Sách bổ trợ, Quiz, Sách bộ giáo dục — cùng khóa `homework_type` khi học viên nộp) mỗi hạng mục bắt buộc yêu cầu, ≥ 1 hạng mục; **Sửa bài đã giao** (route `teacher.homework.update`); hạng mục đã có học sinh nộp → **khóa** ("Đã có học sinh nộp"), bài đã có bài nộp không xóa được | Bố cục 2 khối theo mockup, thẻ hạng mục chọn/bỏ, token | Bài nộp của học viên chưa gắn `homework_id` → "đã có học sinh nộp" xác định theo loại bài nộp của học sinh trong lớp từ lúc giao bài |
| **Cổng GV — Nhập điểm mini test** | **Chọn Unit** (Unit của giáo trình lớp) + **Chọn học sinh** + **điểm 4 kỹ năng Nghe/Nói/Đọc/Viết bắt buộc đủ** ("Cần nhập đủ điểm 4 kỹ năng.") + **Nhận xét chung**; điểm tổng = trung bình 4 kỹ năng (`mini_test_scores.skill_scores`, `syllabus_unit_id`); bảng điểm đã nhập theo Unit (bấm để sửa) | Banner lỗi, lưới 4 ô điểm, token | Lớp chưa gắn giáo trình có Unit → nhập "Tên bài kiểm tra" thay cho Unit. Giữ nhập nhanh cả lớp (1 điểm tổng) qua API cũ |

**Việc tồn Phase 2 đã xử lý:** nhận xét theo buổi (vòng 2); `StudentProfileController::assertClassHasSeat` đã dùng `hasSeatsFor()` (sĩ số chung) — không cần sửa thêm.
**Quyết định phát sinh (tạm, chờ BA):** quá 24h sau giờ học vẫn điểm danh bù được (chỉ đổi nhãn, Học vụ rà soát); điểm mini test tổng = trung bình 4 kỹ năng cùng thang; "Lương tạm tính" = giờ dạy đã chấm công (trừ bị từ chối) × đơn giá riêng hiệu lực.
**Route mới:** `course-levels.reorder` (POST), `teacher.homework.update` (PUT).
**Migration:** `2026_09_30_110000` (`course_levels.description`, `sort_order`), `2026_09_30_110100` (`students.school`), `2026_09_30_110200` (`homeworks.class_session_id`, `due_at`, `class_note`, `youtube_url`, `quizizz_url`, `audio_path`, `items`), `2026_09_30_110300` (`mini_test_scores.syllabus_unit_id`, `skill_scores`).
**Test:** thêm `tests/Feature/Phase2MockupClassesTest.php` (1 test / màn, 12 test). Sửa test cũ do luật mới "nghỉ phải ghi chú": `Phase2AttendanceTest` (2 chỗ gửi `excused` / `absent` thêm `note`).
**Assets:** build lại `public/build` (commit riêng "chore: rebuild assets").
**Triển khai:** `php artisan migrate`; `php artisan storage:link` (file nghe bài tập lưu disk `public`, thư mục `homework_audio`).

#### Phase 2 — hoàn thiện (nhánh `feat/phase2-final-gaps`)
**Đã làm:**
- [x] **Quyền duyệt theo BPMN (Học thuật duyệt, Học vụ không):** quyền mới `big_test.approve` (duyệt & phân phối order đề / đợt thi, gắn chặng cho đợt thi, duyệt kết quả, Duyệt & Gửi PH, gửi Zalo) chỉ cấp cho **Admin + Học thuật** (`academic_lead`). `syllabus.approve_adjustment` (duyệt đề xuất sửa giáo trình, duyệt/từ chối giãn tiến độ, đóng tay / chuyển chặng) **thu hồi khỏi Học vụ (`academic_staff`) và Quản lý cơ sở (`manager`)** — trước đây hai vai trò này có `syllabus.*`. Học vụ / Quản lý vẫn giữ `syllabus.view/update/manage/upload/propose_adjustment` (soạn giáo trình, mở chặng, tạo đợt thi nháp, nhập điểm). "Nhắc lịch" học viên cho một đợt thi chuyển sang `syllabus.manage` (Học vụ làm được). Passcode đề hiện cho người có `big_test.approve`.
- [x] **SĐT phụ huynh trên hồ sơ học viên:** cột `students.parent_name`, `students.parent_phone` (backfill từ khách CRM đã chốt ra học viên, chép khi Chốt & Xếp lớp), sửa ở form hồ sơ học viên (`student.update`, kiểm tra định dạng số), hiện ở chế độ chỉ xem. Gửi kết quả Big Test qua Zalo: **SĐT PH trên hồ sơ HV → SĐT PH của khách CRM → không gửi**, trạng thái **"Thiếu SĐT phụ huynh"** (flash + cột "Đã gửi PH"); **không còn dùng SĐT của chính học viên** (`Student::parentContactPhone()`).
- [x] **Duyệt order tự tạo đợt Big Test:** form "Phân phối đề" của order Big Test có 2 lựa chọn — **Tạo đợt thi mới** (mặc định: ngày giờ thi điền sẵn theo ngày thi dự kiến 08:00, phòng thi mặc định phòng lớp; server bắt buộc khi không gắn đợt có sẵn) hoặc **Gắn đợt thi có sẵn** (giữ luồng cũ). Đợt thi tạo mới gắn **chặng đang mở** của lớp (không có thì chặng của order), mã `BT-…`, link đề, **đã phân phối** → GV nhập kết quả ngay; GV được báo mã đợt thi / giờ / phòng. Order Mini test chỉ cần link đề.
- [x] **Lưu nháp kết quả:** màn nhập điểm có **"Lưu nháp"** (trạng thái Nháp, cho nhập dở, giữ cờ Vắng thi, sửa tiếp được, **không vào hàng chờ duyệt**: "Duyệt kết quả" bỏ qua, không có link "Xem & duyệt", "Duyệt & Gửi PH" từ chối; chưa vào danh sách bổ trợ) và **"Gửi duyệt"** (bắt buộc đủ 4 kỹ năng hoặc Vắng thi → Chờ duyệt; các bản nháp còn lại đã đủ điểm của đợt thi được gửi cùng, báo số bản nháp còn thiếu điểm).
- [x] **Giãn tiến độ:** server từ chối yêu cầu khi lớp **không có chặng đang mở** ("Lớp … không có chặng học nào đang mở"), yêu cầu luôn gắn chặng đang mở.
- [x] **Nhắc 7 ngày theo ngày dự kiến:** `bigtests:remind-upcoming` nhắc thêm các **chặng đang mở có ngày dự kiến Big Test (GV chính đặt) trong 7 ngày mà chưa có đợt Big Test của chặng** — báo GV chính / GVNN / trợ giảng + 1 thông báo chung cho Học thuật. Idempotent theo ngày dự kiến (`syllabus_assignments.big_test_reminded_for`); GV đổi ngày thì nhắc lại theo ngày mới; chặng đã có đợt thi thì nhắc theo luồng đợt thi như cũ.
- [x] **Chăm sóc tháng đầu = 3 mốc gate hoa hồng A6:** **Buổi 1** (việc tạo sau buổi có mặt/đi muộn đầu tiên, hạn = hôm sau), **Buổi 4–5** (sau buổi có mặt thứ 4, hạn = hôm sau), **Đủ 30 ngày** (ngày chốt + 30; ngày chốt = `crm_customers.converted_at`, học viên không qua CRM: ngày xếp lớp sớm nhất → ngày tạo hồ sơ). Checklist CRM còn đúng 3 mục `session_1 / session_4_5 / day_30`. Một mốc được tính khi **việc hoàn thành hoặc CM tick ở checklist CRM** (hoàn thành việc tự tick CRM như cũ). Helper `FirstMonthCareService::careMilestonesCompleted(CrmCustomer|Student): int` (0–3) cho gate hoa hồng Phase 3. Hồ sơ học viên hiện "x/3 mốc", ngày chốt, hạn từng mốc. Lệnh chỉ tạo mốc có sự kiện kích hoạt trong 30 ngày gần nhất (đủ cho điểm danh bù), idempotent.
- [x] **GV chỉ xem phần Speaking:** Học thuật nhập thêm **"Link phần Speaking"** khi duyệt order (`big_test_orders.speaking_link` → `big_tests.speaking_url`). Sau phân phối, GV chính / GVNN chỉ thấy **"Phần Speaking"** (Cổng GV — order đề, bảng đợt thi); **link đề đầy đủ chỉ người có `big_test.approve` thấy**; trợ giảng không thấy. Chưa có link Speaking → "Chưa có link phần Speaking".
- [x] Sửa kèm (dữ liệu demo / test cũ lệch sau khi gộp nhánh giao diện): `DemoPhase2Seeder` ghi chú bắt buộc cho "Nghỉ không phép" và lưu nhận xét theo buổi; `Phase2AcceptanceTest` kiểm tra redirect nhận xét theo buổi; `MasterEntitySeeder` ghi rõ trạng thái kết quả Big Test mẫu (Chờ duyệt) để chạy lại seeder không làm lệch danh sách bổ trợ.

**Chuyển dữ liệu chăm sóc tháng đầu (migration `2026_10_01_100300`):**
- Checklist CRM: `first_session_feedback` → `session_1`, `week2_parent_update` → `session_4_5`, `month_end_review` → `day_30`. Bỏ `welcome_call` (gọi chào mừng — trước buổi 1, không thuộc gate) và `materials_check` (trùng checklist hồ sơ nhập học Phase 1); nếu đã tick thì ghi 1 dòng lịch sử khách (loại `care`) để không mất dấu vết. Bảng map: `CrmCustomer::LEGACY_CARE_CHECKLIST_MAP`.
- Việc chăm sóc (`work_tasks.care_milestone`): 3 → 1 (Buổi 1), 14 → 4 (Buổi 4–5, ~2 buổi/tuần), 30 → 30; việc ngày 7 thành việc thường (`care_milestone = NULL`, giữ tiêu đề/trạng thái). `down()` đảo ngược (mục bỏ không khôi phục được).

**Chưa làm / lý do:**
- [ ] **Tách phần Speaking ngay trong đề:** đề Big Test chỉ lưu dưới dạng **link thư mục/tệp ngoài** (`content_url`, Google Drive/OneDrive) — hệ thống không lưu nội dung đề theo phần, nên không cắt được phần Speaking từ đề gốc. Đã làm cách thay thế: Học thuật dán link riêng phần Speaking; quyền xem trên chính Drive vẫn do người chia sẻ link quản lý.
- [ ] Hủy tick ở checklist CRM không đổi trạng thái việc chăm sóc đã hoàn thành (mốc vẫn tính đủ vì việc đã xong).
- [ ] Gửi duyệt kết quả chưa báo riêng cho Học thuật (vẫn xem ở màn Duyệt kết quả).

**Câu hỏi BA:**
- [ ] **Quản lý cơ sở có được duyệt order đề / kết quả Big Test / giãn tiến độ không?** Nhánh này làm đúng "chỉ Học thuật + Admin" (Quản lý cơ sở cũng bị thu hồi). Nếu cần, cấp lại qua Phân quyền cá nhân hoặc thêm `big_test.approve` cho `manager`.
- [ ] Mốc "Buổi 4–5": đang tạo việc sau **buổi có mặt thứ 4** (vắng không tính). Có cần tính theo buổi học của lớp (kể cả buổi HV vắng) không? Ngày chốt cho học viên không qua CRM lấy ngày xếp lớp — đúng chưa?
- [ ] Mục "Gọi chào mừng" và "Kiểm tra giáo trình / tài khoản" bị bỏ khỏi checklist tháng đầu (không thuộc gate A6) — có cần giữ làm việc phụ không?
- [ ] "Nhắc lịch" học viên theo đợt thi: đang cho Học vụ (`syllabus.manage`) — đúng người không?

**Migration:** `2026_10_01_100000` (quyền `big_test.approve`; thu hồi `syllabus.approve_adjustment` của `academic_staff`, `manager`; xóa cache quyền), `2026_10_01_100100` (`students.parent_name/parent_phone` + backfill), `2026_10_01_100200` (`big_test_orders.speaking_link`, `big_tests.speaking_url`, `syllabus_assignments.big_test_reminded_for`), `2026_10_01_100300` (chuyển checklist / mốc chăm sóc tháng đầu).
**Test:** thêm `tests/Feature/Phase2FinalGapsTest.php` (7 test). Sửa test cũ theo hành vi mới: `BigTestResultsSecurityTest` (người duyệt = Học thuật; Quản lý cơ sở / Học vụ bị 403; SĐT PH, fallback CRM, "Thiếu SĐT phụ huynh"), `BpmnWorkflowIntegrationTest` (Quản lý không duyệt đề), `AcademicSystemTest` (giãn tiến độ cần chặng mở; HV có SĐT PH), `Phase2SyllabusTest` (mở chặng trước khi xin giãn), `Phase2BigTestTest` (duyệt order tạo đợt thi, GV chỉ thấy Speaking), `Phase2AttendanceTest` (3 mốc A6 + migration), `Phase1CrmTest` (khóa checklist mới), `SyllabusModuleTest` / `Phase2MockupSyllabusTest` / `SyllabusStageProgressionTest` (HV có SĐT PH), `Phase2AcceptanceTest` (Học vụ 403 khi duyệt; SĐT PH trên hồ sơ HV), `DemoPhase2SeederTest` (kết quả nháp, đợt thi tự tạo + link Speaking, nhắc theo ngày dự kiến, HV demo có SĐT PH). Toàn bộ bộ test xanh: **757 test, 8451 assertion**.
**Triển khai:** `php artisan migrate` (4 migration trên — sao lưu DB trước vì có chuyển dữ liệu chăm sóc tháng đầu); nếu chạy `db:seed --class=RoleSeeder` sau đó thì kết quả giống migration (config đã cập nhật). **Kiểm tra lại quyền:** Học vụ và Quản lý cơ sở mất quyền duyệt; ai cần duyệt phải có vai trò Học thuật hoặc được cấp `big_test.approve` / `syllabus.approve_adjustment` qua Phân quyền cá nhân. Bổ sung SĐT phụ huynh cho học viên không đến từ CRM trước khi gửi kết quả. Build lại asset (`npm run build`, commit "chore: rebuild assets"). Cron `schedule:run` giữ nguyên (lệnh `bigtests:remind-upcoming`, `students:schedule-first-month-care` không đổi lịch).

#### Phase 3 — Công thức lương theo BA (nhánh `feat/phase3-formula`)
Làm theo A6 (25/09/2026): "Q3 — Công thức lương Part-time", "Q3 — Công thức lương Full-time", "Hoa hồng tuyển sinh (bản sửa)", "Thưởng tái tục" + các dòng hoa hồng trước đó chưa bị thay (tiền thực thu gồm giáo trình, chỉ khách mới, thu hồi khi hoàn phí / hủy hóa đơn).

**Đã làm:**
- [x] **Loại nhân sự ghi rõ trên phiếu lương** (`payroll_records.employee_type` = `parttime` / `fulltime`, `salary_role`), suy từ vai trò + hợp đồng (`PayrollFormulaService::profile`): `teacher_parttime` → Part-time; `teacher_fulltime`, `academic_staff` (Học vụ), `academic_lead` (Học thuật), Sale, nhân sự khác → Full-time; `teacher` / `assistant` (và nhân sự chưa gán vai trò): HĐ "Bán thời gian" / "Cộng tác viên" → Part-time, "Toàn thời gian" → Full-time, chưa rõ → có lương cơ bản thì Full-time, không thì Part-time. Khối trên các bảng kỳ: Part-time → "GV Part-time", GV Full-time → "GV Full-time", Học thuật → "Khối Học thuật", Học vụ → "Học vụ & Vận hành", còn lại theo `users.department` (mặc định Vận hành).
- [x] **Part-time** = số buổi × đơn giá buổi riêng + KPI giữ HS + buổi có GVNN + phụ cấp tự do − khoản trừ. Mỗi ca chấm công hợp lệ = 1 buổi. Bảng đơn giá riêng (`teacher_hourly_rates`) thêm đơn vị `rate_unit` (`session` đ/buổi — mặc định trên form từ nay; `hour` đ/giờ — các dòng cũ), vẫn theo ngày hiệu lực + lịch sử. Chưa có đơn giá buổi thì tính số giờ × đơn giá giờ như trước (ca có đơn giá tay → đơn giá giờ riêng → hồ sơ → mặc định). **Không BHXH / Công đoàn / TNCN**, không dùng lương cơ bản của hồ sơ. **Bỏ** "+1 triệu khi ≥ 40 giờ" và phụ cấp cố định 500k.
- [x] **KPI giữ học sinh (Part-time):** số HS giữ được × bậc 15.000 / 20.000 / 25.000đ (config `payroll.retention_tiers`); Admin / Kế toán chọn bậc trên phiếu lương cho từng GV từng kỳ (chưa chọn = 0đ), giữ khi tính lại.
- [x] **Buổi có GVNN (chờ BA):** dòng cộng riêng "Buổi có GVNN (chờ BA chốt)", Kế toán nhập tổng tiền (phiếu lương hoặc nút "Buổi GVNN" trên bảng kỳ); hệ thống chỉ đếm số buổi có GVNN cùng lớp để gợi ý. **Bỏ quy tắc cũ trừ 50.000đ/buổi có GVNN** (A6 ghi là khoản cộng).
- [x] **Full-time** = lương cơ bản + KPI + hoa hồng (nếu có) + thưởng tái tục + phụ cấp tự do − BHXH 10,5% − Công đoàn 0,5% (tự động trên lương cơ bản) − thuế TNCN (nhập tay) − trừ vi phạm − thu hồi hoa hồng − khấu trừ tự do. Tỉ lệ ở `config/payroll.php`, Admin ghi đè ở "Tham số tính lương". GV Full-time không được trả thêm theo buổi/giờ (buổi dạy chỉ hiện để đối soát).
- [x] **KPI theo vai trò:** GV Full-time, Học thuật, Sale, khác: nhập tự do (số tiền). **Học vụ: tự động** = quỹ 2.000.000đ × điểm KPI có trọng số của đánh giá tháng đã chốt; điểm = Σ(điểm mục × trọng số) / Σ trọng số các mục đang áp dụng (mục chưa chấm = 0; màn đánh giá tính cùng cách). Seed **6 nhóm / 15 mục** theo mockup `04_kpi_thang` (Chăm sóc học viên, Chất lượng giảng dạy, Vận hành lớp, Quản lý Giáo viên, Phát triển Trung tâm, Chuyên môn khác), mỗi mục có mã 1.1…6.2, ngưỡng 100% / 50%, trọng số % quỹ (tổng 100% = 2.000.000đ). Màn Cấu hình KPI nhóm theo nhóm, hiện tiền từng mục và trạng thái "khớp quỹ"; màn Đánh giá hiện tiền KPI dự tính. Không tự chấm KPI của mình (giữ nguyên).
- [x] **Hoa hồng (bản sửa):** % theo **bậc chọn bằng số HS chốt của sale trong kỳ** (`commission_tiers.min_students/max_students`; mặc định 0–5 HS → 3%, 6–10 → 4%, từ 11 → 5%) × tiền thực thu khách mới; tự tính, không sửa tay; bỏ "thưởng vượt mốc" và bậc theo doanh thu (giữ làm lịch sử). **Gate kép từng khách:** đủ 30 ngày từ ngày chốt (tính tới cuối kỳ) **và** `FirstMonthCareService::careMilestonesCompleted = 3`. Chưa đạt → khoản **hoãn** (sổ `commission_items`, ghi lý do: chưa đủ ngày / x/3 mốc), trả ở kỳ đầu tiên đạt, **giữ % của kỳ phát sinh**. Kỳ duyệt → khoản đã trả `settled_at`, không trả lại.
- [x] **Thu hồi với căn cứ mới:** hủy hóa đơn phiếu **đã trả** hoa hồng → thu hồi đúng số hoa hồng đã trả của phiếu (trừ kỳ sau); phiếu còn **hoãn** → hủy khoản trong sổ, không tạo thu hồi. Duyệt hoàn phí có thu hồi: số gợi ý tính trên khoản đã trả; các khoản còn hoãn của học viên bị hủy. Chuyển nhượng vẫn không thu hồi.
- [x] **Thưởng tái tục** (dòng riêng, GV Full-time phụ trách lớp = GV chính): với mỗi lớp có HS đầu kỳ: % theo số HS nghỉ trong kỳ × doanh thu lớp (phiếu thu đã duyệt trong kỳ của khoản học phí gắn lớp, trừ tiền nhận chuyển nhượng). Bảng %: 0 nghỉ → 1%, 1 nghỉ → 0,7% (BA chốt); 2, 3 nghỉ và "nhiều hơn" → 0% **đánh dấu chờ BA** — Admin sửa ở "Tham số tính lương" (bảng mốc + cờ chờ BA). Phiếu lương hiện chi tiết từng lớp.
- [x] **Phiếu lương & 4 bảng kỳ:** mỗi khoản lưu thành cột riêng (`teaching_sessions`, `retention_*`, `kpi_source/kpi_score/kpi_manual_amount`, `foreign_session_pay`, `commission_deferred/percent/closed_count`, `union_deduction`, `manual_lines`, `calculation_details`). Phiếu lương hiện dòng theo loại nhân sự + căn cứ (buổi dạy & đơn giá, HS giữ được / HS nghỉ, bảng KPI Học vụ, tái tục từng lớp, hoa hồng trả / hoãn kèm lý do, phạt / thu hồi) và form nhập tay: bậc KPI giữ HS, buổi có GVNN, KPI tự do, thuế TNCN, dòng cộng / trừ tự do có tên (VD Hỗ trợ thỏa thuận, Gửi xe, Thưởng khác, Tạm ứng). Bảng "Toàn bộ / GV Part-time" có cột loại, buổi dạy, KPI, GVNN, hoa hồng (+ hoãn), tái tục, BHXH + CĐ, TNCN; 3 bảng Full-time dùng chung partial (BHXH / CĐ / TNCN / phạt tách cột). Xuất Excel theo cột mới. "Lương của tôi" dùng cùng các dòng. Phiếu trước Q3 (`employee_type` NULL) vẫn hiện theo cột cũ.
- [x] **Tính lại giữ khoản nhập tay** (bậc KPI, KPI tự do, TNCN, GVNN, dòng tự do, ghi chú). Duyệt kỳ vẫn chỉ Admin; kỳ đã duyệt khóa. "Cần tính lại trước khi duyệt" thêm: khoản hoa hồng bị hủy / đổi, khách có khoản hoãn được tick mốc chăm sóc, đánh giá KPI tháng thay đổi sau lần tính.

**Diễn giải (tạm, chờ BA xác nhận):**
- **"Số HS giữ được"** = trong các lớp của GV (GV chính + lớp có ca dạy hợp lệ trong kỳ — `payroll.retention.class_scope`), HS có lượt xếp lớp hiệu lực **tại ngày đầu kỳ** mà tới **hết ngày cuối kỳ** không chuyển sang trạng thái mất (`payroll.retention.lost_statuses`, mặc định chỉ **Thôi học**). Bảo lưu / Nghỉ hè vẫn tính là giữ; HS vào lớp giữa kỳ không vào mẫu số; HS thôi một lớp nhưng còn học lớp khác của cùng GV không tính là mất. Số HS nghỉ của thưởng tái tục dùng cùng cách đếm cho từng lớp.
- Ngày chốt của gate = `crm_customers.converted_at` (học viên không qua CRM: ngày xếp lớp sớm nhất → ngày tạo hồ sơ, như Phase 2). Mốc chăm sóc xét **trạng thái hiện tại** khi bấm tính (không theo ngày tick).
- Bậc hoa hồng: số HS chốt = khách của sale có `converted_at` trong kỳ; khoản phát sinh ở kỳ nào dùng bậc của kỳ đó (kể cả khi trả ở kỳ sau). Ngưỡng 0–5 / 6–10 / từ 11 là giá trị mặc định do dev đặt.
- Thưởng tái tục chỉ cho **Full-time** (công thức Part-time của A6 không có dòng này); "lớp phụ trách" = lớp GV là GV chính.
- Học vụ là Full-time; Sale là Full-time (lương cơ bản + hoa hồng − BHXH / CĐ).
- Phụ cấp / thưởng khác / khấu trừ khác không còn là ô cố định mà là các dòng tự do có tên; điều chỉnh tay kiểu cũ ở kỳ chưa khóa được chuyển thành dòng tự do khi migrate.
- Bậc KPI giữ HS do người có `payroll.edit` (Kế toán, Admin) chọn — A6 ghi "Admin chọn bậc tay".

**Chưa làm / chờ BA:**
- [ ] **Lương buổi có GVNN** (Part-time): mới có dòng nhập tay "chờ BA chốt"; chưa tự tính.
- [ ] **Bảng % thưởng tái tục đầy đủ** theo số HS nghỉ (mới có 0 → 1%, 1 → 0,7%); các mốc khác 0% "chờ BA", Admin sửa được.
- [ ] **Định nghĩa "số HS giữ được"** (mẫu số / trạng thái nào là mất / lớp nào tính) và ngưỡng số HS của 3 bậc hoa hồng — cần BA xác nhận.
- [ ] Part-time có thưởng tái tục không? Có khấu trừ thuế TNCN 10% với cộng tác viên không? (hiện Part-time không trừ TNCN; dùng dòng trừ tự do nếu cần).
- [ ] Đối chiếu với bảng lương Excel thật của Kế toán (vẫn chưa có file).

**Migration:** `2026_10_02_100000` (cột công thức Q3 trên `payroll_records`; chuyển điều chỉnh tay kiểu cũ của kỳ chưa khóa thành dòng tự do), `2026_10_02_100100` (`teacher_hourly_rates.rate_unit`, dòng cũ = `hour`), `2026_10_02_100200` (`commission_tiers.min_students/max_students` + 3 bậc mặc định 3/4/5% nếu chưa có bậc theo số HS), `2026_10_02_100300` (bảng `commission_items`), `2026_10_02_100400` (`kpi_criteria.group_name/code/threshold_full/threshold_half/sort_order` + 15 mục KPI Học vụ nếu chưa có mục có mã).
**Test:** thêm `tests/Feature/Phase3FormulaTest.php` (11 test, ví dụ số: PT 8 buổi × 250k + 9 HS × 20k + GVNN 300k + phụ cấp 300k − tạm ứng 50k − phạt 100k = 2.630.000đ; FT 10tr + KPI 1,5tr + phụ cấp 500k − BHXH 1,05tr − CĐ 50k − TNCN 250k − phạt 200k = 10.450.000đ; Học vụ 82,5% × 2tr = 1.650.000đ; hoa hồng 6 HS chốt → 4%, hoãn 1,2tr tháng 9 → trả 800k tháng 10 → 200k tháng 11 + thu hồi hủy hóa đơn; tái tục 1% × 20tr + 0,7% × 10tr = 270.000đ). Sửa test cũ mã hóa luật đã thay: `PayrollBusinessTest` (bậc theo số HS; FT không trả giờ dạy / không phụ cấp 500k / có Công đoàn; tham số lương mới; GVNN không còn trừ), `PayrollP0FixesTest` (hoa hồng qua gate + không thưởng vượt mốc; dòng GVNN là khoản cộng; bảng Full-time tách cột), `Phase3CommissionTest` (bậc theo số HS, khách đã tick đủ 3/3 mốc; sale Full-time có Công đoàn), `Phase3PayslipTest` (GV có lương cơ bản = Full-time; dòng tự do có tên thay ô phụ cấp / thưởng / khấu trừ), `Round2LeftoversTest` (hủy hóa đơn: khách qua gate trong tháng 9), `RecruitmentAndDashboardsTest` (dòng "Buổi có GVNN"). Toàn bộ bộ test: **768 test, 8635 assertion** xanh.
**Triển khai:** sao lưu DB rồi `php artisan migrate` (5 migration trên). Sau migrate: (1) vào **Mốc hoa hồng** kiểm tra 3 bậc mặc định theo số HS; bậc cũ theo doanh thu còn trong lịch sử nhưng không dùng; (2) vào **Đơn giá GV** thêm đơn giá **theo buổi** cho từng GV Part-time (chưa có thì vẫn tính theo giờ); (3) gán đúng vai trò `teacher_parttime` / `teacher_fulltime` hoặc loại hợp đồng cho GV `teacher`; (4) **Cấu hình KPI** kiểm tra 15 mục Học vụ (tổng 100%); (5) kỳ lương chưa duyệt bấm "Đồng bộ & Tính lại" để chuyển sang công thức mới. Hoa hồng của khách chốt trước khi triển khai chỉ vào sổ từ kỳ đầu tiên được tính lại (phiếu thu duyệt trong kỳ đó). Build lại asset (`npm run build`, đã commit `public/build`).

#### Phase 3 — Đối chiếu mockup nhóm Chấm công, Phạt, Lương, KPI (nhánh `feat/phase3-mockup-parity`)
**Phương pháp:** mở `code.html` của mockup (`ui-full-tinh-nang-menglish/epic-7/*`, `epic-8-danh-sach-phat`, `public/roundcuoi-kieulien/01_Web_Admin/09–11`, `02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu/01–04, 11`, `03_Cong_Giao_Vien/15–16`) cạnh view Blade; sửa hết P1 (thiếu trường / cột / lọc / nút / khối, dữ liệu giả) rồi P2 (nhãn, icon, bố cục, token màu / chữ, `x-ui.*`). Phần tử mockup trái A6 thì theo A6 (ghi rõ).

| Màn | P1 đã sửa | P2 đã sửa | Chưa làm / bỏ theo A6 (lý do) |
|---|---|---|---|
| **Chấm công thủ công** | Ô **Nhân viên** tìm theo tên / **mã NV**; ô **Chi nhánh** (lọc lớp; server báo lỗi nếu lớp không thuộc chi nhánh); chọn **Ngày làm việc** thuộc kỳ đã khóa → cảnh báo "Kỳ lương của ngày này đã bị khóa. Không thể chấm công." + khóa nút lưu (server vẫn chặn); lưu ý "Mỗi lần lưu chỉ tạo đúng 1 dòng chấm công"; "Lý do điều chỉnh" placeholder theo mockup; nhận tham số điền sẵn (từ "Buổi thiếu chấm công") | Tiêu đề / mô tả / icon timer theo mockup, form 1 khung, loại ca + đơn giá riêng gom vào "Thông tin bổ sung" | Danh sách lớp chỉ trong phạm vi người chấm (trước liệt kê mọi lớp rồi mới chặn khi lưu) |
| **Chi tiết chấm công GV** (+ roundcuoi 09 Chấm công theo lịch, 10 Đối soát, 11 Dạy thay) | Lọc **kỳ lương (tháng)** / chi nhánh / lớp / giáo viên / trạng thái / **loại ca** / tìm GV; chọn GV → thẻ **Mã GV, Cơ sở**, "**Kỳ lương đã chốt, không thể sửa**", "**Số lần thiếu chấm công trong tháng**" = buổi được phân công đã qua chưa có ca + bảng buổi thiếu với "Chấm công tay" điền sẵn; cột **Ca học / Giờ vào / Giờ ra / Tình trạng** (Đầy đủ, Thiếu vào, Thiếu ra, **Chỉnh tay**); **"Chỉnh tay bổ sung"** (giờ vào / ra + lý do bắt buộc, ca về Chờ đối soát, chặn kỳ khóa, ghi nhật ký); **"Chốt bảng công (N)"** duyệt hàng loạt; **Từ chối bắt buộc lý do** (bỏ lý do ghi cứng "Dữ liệu check-in cần xác minh"); **Chấm công theo lịch**: buổi học của ngày, GV dự kiến, Tự động / GV tự check-in / Đã xác nhận + "Xác nhận" (tạo / duyệt ca theo giờ lịch, nguồn mới `schedule`); cảnh báo + lọc **buổi dạy thay chờ xác nhận** | Token, badge trạng thái, thẻ thống kê Chờ đối soát / Hợp lệ / Từ chối, phân trang "ca dạy" | Mức phạt thiếu chấm công "vượt ngưỡng nhẹ −30.000đ / nặng không tính công" (mockup) **không làm** — không có trong A6, phạt đi theo luồng biên bản vi phạm. Luồng "Nháp → Gửi báo cáo" của cổng GV (mockup 16) không làm: check-in đã vào thẳng "Chờ đối soát" (mockup 16 trỏ về màn chấm công của GV). "Mở khóa" ca đã chốt (mockup 10) không làm — mở khóa phải qua kỳ lương |
| **Lịch sử đồng bộ** | Lọc **Từ ngày / Đến ngày / Trạng thái** (Thành công, Lỗi một phần, Lỗi toàn bộ) + phân trang; cột **Tổng số dòng / Thành công / Lỗi / Bỏ qua** (chú thích "không ghi đè dữ liệu nhân sự đã chốt kỳ lương"); **"Xem chi tiết lỗi"** (bảng Mã NV / Tên / Mã lỗi / Nội dung, hoặc lỗi hệ thống) + **"Xuất file Excel lỗi"**; "Làm mới" | Tiêu đề / mô tả / icon trạng thái theo mockup, token | **"Đồng bộ ngay"** hiển thị nhưng khóa kèm lý do — chưa có tích hợp AppSheet / máy chấm công (không làm nút giả). Bảng mới sẵn cột để tích hợp ghi vào |
| **Danh sách vi phạm** (+ tạo / giải trình / chốt) | Tìm theo **tên hoặc mã NV**; **lọc theo bước** (Ghi nhận / Đã chốt lỗi / Đã chốt phạt / Đã nộp / **Đã khắc phục** / Đóng - không phạt / Đã hủy) + **Bộ lọc nâng cao** (loại lỗi, trạng thái, khoảng ngày); cột **ID NV, Nguồn (Thủ công / Tự động), Bước hiện tại, Trạng thái GV** (Chờ xác nhận / Đã xác nhận / Bỏ qua); thao tác theo bước **Chốt lỗi → Chốt mức phạt** (khóa + banner "Kỳ lương hiện tại của nhân viên … đã khóa. Không thể thực hiện chốt mức phạt"), **Đóng - không phạt, Hủy vi phạm, Đánh dấu đã nộp, Ghi nhận khắc phục** (mốc mới, không đổi tiền), xem chi tiết biên bản | Tiêu đề "Danh sách vi phạm", chip lọc, badge bước, token | "Chốt lỗi" vẫn cho quyết phạt luôn (chọn trong hộp) — giữ luồng Phase 3 |
| **Đơn giá GV** | **1. Chọn giáo viên** (tìm tên / mã NV, trạng thái "Đang giảng dạy"), thẻ **Đơn giá hiện hành** (loại GV, mức đang áp dụng + đơn vị, hiệu lực từ), form **2. Cập nhật đơn giá mới** (**Loại giáo viên**, ngày hiệu lực, đơn vị buổi / giờ, mức kèm đơn vị, ghi chú / lý do); lịch sử có **Loại GV, Đơn vị tính, Đến ngày, Trạng thái** (Đang áp dụng / Đã hết hạn / Chưa hiệu lực) | Bố cục 2 cột theo mockup, token | Đơn vị **VNĐ/phút** cho GVNN không làm — lương buổi có GVNN chờ BA (A6), Kế toán nhập tay trên phiếu. Loại GV trên đơn giá chỉ để hiển thị; công thức vẫn theo loại nhân sự Q3 |
| **Mốc hoa hồng** (& thưởng tái tục) | Chọn **Hoa hồng tuyển sinh / Thưởng tái tục**; "Thêm mốc mới"; bảng **Ngưỡng từ (HV) / Ngưỡng đến (HV) "Không giới hạn" / Tỷ lệ (%) / Ngày hiệu lực từ**; form **Thêm mốc cấu hình mới** (Từ / Đến "Để trống = Max" / Tỷ lệ / Hiệu lực) — **tên bậc không bắt buộc** (tự đặt theo ngưỡng); tab **Thưởng tái tục**: bảng % theo **số HS nghỉ** (A6) + cờ chờ BA, lưu riêng (`payroll.config.renewal.store`) | Tiêu đề / thông báo "áp dụng cho kỳ tiếp theo kể từ ngày hiệu lực…", token | Bảng thưởng tái tục **chưa có ngày hiệu lực / lịch sử phiên bản** (áp dụng cho lần tính / tính lại sau; kỳ đã duyệt không đổi) — cần đổi công thức, để sau. Mockup ghi tỷ lệ 10–25% theo số HV: theo A6 (mặc định 3 / 4 / 5%) |
| **Danh sách bảng lương theo kỳ** | Trang kỳ: **chọn Kỳ lương**, **tìm giáo viên** (tên / mã / email), lọc loại nhân sự & **Trạng thái KPI**; cột **Trạng thái bảng lương** (Đang tính / Đã chốt / Đã trả + cảnh báo) và **Trạng thái KPI** (Đã chốt / Chưa chốt / Không áp dụng); banner "**Chưa thể chốt bảng lương kỳ MM/YYYY do: Còn N nhân sự chưa chốt KPI (tên…)**"; "Đánh dấu đã trả"; phân trang; "Chi tiết" → phiếu lương. **Xuất Excel** thêm: Mã NV, vai trò lương, số giờ, nguồn / trạng thái KPI, HS giữ / đầu kỳ, bậc KPI, điểm KPI Học vụ, số buổi GVNN, số HS chốt, % & căn cứ hoa hồng, chi tiết dòng cộng / trừ tự do, tổng thu nhập, tổng khấu trừ, ghi chú | Danh sách kỳ + trang kỳ theo token `x-ui.*`, tạo kỳ bằng modal | Mockup **chặn** chốt khi còn người chưa chốt KPI: làm **cảnh báo + hộp xác nhận**, không chặn (A6 không quy định; GV không có HS đầu kỳ / nhân sự KPI 0đ vẫn phải được trả lương). 3 bảng theo khối (Full-time / Học thuật / Học vụ & Vận hành) giữ bố cục cũ (đã sửa cột ở `feat/phase3-formula`) |
| **4 màn chi tiết lương** (phiếu lương từng người) | Chọn mẫu theo loại: **GV Part-time** (Số buổi dạy / **Đơn giá cơ bản** / Thành tiền; **Bậc KPI giữ học sinh** + "Gợi ý 15.000 / 20.000 / 25.000đ", **Số học sinh duy trì**, **Chốt KPI**; **Phụ cấp mở rộng**: Lớp GVNN đan xen + dòng gợi ý Hỗ trợ thỏa thuận / Phụ cấp gửi xe / Thưởng khác; **Chi tiết buổi dạy** Ngày / Ca / Lớp / Đơn giá / Thành tiền / Ghi chú "Dạy thay" + "Xem toàn bộ (N buổi)"), **GV Full-time** (Thu nhập chính, Lương KPI nhập tự do, Phụ cấp mở rộng thêm / xoá, Trừ vi phạm + Thêm khoản trừ, **Khấu trừ bắt buộc** BHXH / Công đoàn tự động + TNCN nhập tay), **Học vụ** (KPI tự động 6 nhóm / 15 mục, **bảng kê theo nhóm**, link chốt đánh giá tháng, **Hoa hồng & Thưởng tái tục (chỉ đọc)** + "Chi tiết bậc áp dụng"), **Học thuật** (Lương cứng, gợi ý Lương giảng dạy / Hỗ trợ). Header: mã NV, kỳ, loại, trạng thái, "Quay lại danh sách", **Chốt bảng lương**, **Đánh dấu đã trả**, **"In phiếu lương / Xuất PDF"** — bản in A4 riêng (thông tin trung tâm, nhân sự, khoản cộng / trừ, thực nhận, chữ ký; "BẢN TẠM TÍNH" khi chưa duyệt). Chốt bảng lương gửi **thông báo trong app** cho từng nhân sự | Bố cục 8 / 4 cột, thẻ tổng kết thực nhận, token | Học thuật: "Chế độ lương giảng dạy Theo ca / Theo % doanh thu" và Học vụ: tách "Lương cơ bản (căn cứ BHXH)" với "Lương cứng" **không làm** — A6 chỉ có lương cơ bản + khoản cộng; dùng dòng cộng tự do có tên. Sửa lương cơ bản ngay trên phiếu không làm (lấy từ hồ sơ nhân sự). Mockup Học vụ "hoa hồng theo ngưỡng doanh thu" → theo A6 (bậc theo số HS chốt). "Theo danh mục phạt" trên phiếu → sang màn Danh sách vi phạm |
| **BXH KPI** | Lọc **Kỳ lương + Chi nhánh**, phân trang; bảng **KPI giữ học sinh** lấy từ phiếu lương của kỳ (**Số HS Giữ, Đơn giá (VNĐ/hs), Tổng KPI**, cờ "tạm tính" khi kỳ chưa chốt) — bỏ số "HS duy trì" giả (đếm HS đang học cả chi nhánh); bảng **hoa hồng sale** (Số HS chốt, Tỷ lệ %, thực thu); không lộ lương cơ bản / khấu trừ / thực nhận | Tiêu đề / mô tả công khai theo mockup, huy hiệu top 3, token | — |
| **KPI Học vụ (6 nhóm / 15 mục)** | Phiếu tháng dạng bảng **Mã / Tiêu chí / Quỹ / Ngưỡng 100 / Ngưỡng 50 / Thực tế / % Đạt / Tiền KPI** theo nhóm, **"Lỗi nghiêm trọng"** đưa % đạt về 0, **Tổng tiền KPI dự tính** trực tiếp; **Chi tiết điểm theo nhóm** (số tiêu chí, quỹ, tiền đạt, % đạt), **Xếp loại tháng**, **Cảnh báo hiệu suất** (≤50%, 0%), nhận xét **Điểm tốt / Cần cải thiện / Hành động tháng sau**; **Lưu nháp** (không vào lương) / **Chốt KPI tháng**; chọn nhân sự + kỳ. Tổng hợp tháng: tìm, lọc vai trò, xếp loại, tiền KPI dự tính, trạng thái Nháp / Đã chốt. Cấu hình: banner "Trạng thái quỹ KPI … (Khớp)", "Thêm mục mới" | Token cho 3 màn KPI | **"Hiệu lực từ"** của từng mục KPI (mockup 01/02) chưa làm — mục KPI chưa có phiên bản theo ngày. "Lỗi nghiêm trọng" bật được ở mọi mục (mockup chỉ vài mục) — chưa có cờ cấu hình theo mục. Mốc xếp loại A ≥ 95 / B 85–94 (mockup) / C 70–84 / D < 70 do dev đặt — chờ BA. Số liệu "Thực tế" nhập tay (chưa tự lấy từ điểm danh / học phí) |
| **Lương của tôi** | **Chọn kỳ** (chỉ kỳ đã duyệt / đã chi trả), "Kỳ lương hiện tại: …", **Tải phiếu lương** (bản in / PDF chung với phiếu lương); thẻ **Tổng thu nhập (± % so với tháng trước)**, **Tổng khoản trừ** ("Bao gồm phạt và các khoản khác"), **Thực nhận**; bảng **Chi tiết thu nhập** (Hạng mục / Số lượng / Thành tiền), **Các khoản trừ** (biên bản đã trừ kèm ngày, lớp), **Chi tiết buổi dạy** (thời gian, lớp, Cover, đơn giá, thành tiền, lọc lớp, "Xem thêm") | Token, bỏ khung "xác thực" ghi cứng | Cột **"Hệ số" (x1.2 cuối tuần)** không làm — không có trong A6. "Sẽ được thanh toán vào ngày …" không làm — chưa có ngày chi trả trên kỳ lương |

**Sửa kèm:** các form `data-confirm` (hủy / đóng biên bản, ngừng mốc hoa hồng, chốt bảng lương, xoá mục KPI) trước đây không hỏi xác nhận vì thiếu listener → thêm partial `partials.data-confirm` (@once).
**Quyết định phát sinh (tạm, chờ BA):** "Trạng thái KPI" = Part-time đã chọn bậc (không có HS đầu kỳ → không áp dụng), Học vụ đã có đánh giá tháng **đã chốt**, Full-time / Học thuật / Sale đã nhập KPI (kể cả 0đ); chốt bảng lương khi còn người chưa chốt KPI chỉ cảnh báo. Ca "Xác nhận theo lịch" tính giờ theo lịch buổi học, hợp lệ ngay (người xác nhận là người duyệt).
**Route mới:** `payroll.timesheets.bulk-review` (POST), `payroll.timesheets.adjust` (PUT), `payroll.timesheets.sessions.confirm` (POST), `payroll.timesheets.sync-history.errors` (GET), `payroll.config.renewal.store` (POST), `penalties.remedy` (POST).
**Migration:** `2026_10_03_100000` (`teacher_timesheets.adjusted_at/adjusted_by/adjustment_reason`), `2026_10_03_100100` (`timesheet_sync_logs.source/failed_count/skipped_count/error_code/error_message/error_rows`), `2026_10_03_100200` (`penalties.remedied_at/remedied_by/remedy_note`), `2026_10_03_100300` (`teacher_hourly_rates.teacher_type`), `2026_10_03_100400` (`kpi_evaluation_items.actual/critical_error`, `kpi_evaluations.strengths/improvements/next_actions`).
**Test:** thêm `tests/Feature/Phase3MockupParityTest.php` (1 test / màn — 4 màn chi tiết lương gộp 1 test, BXH KPI + KPI Học vụ gộp 1 test; 10 test). Sửa test khẳng định UI / luật cũ: `Phase3TimesheetTest` ("Chưa kết nối máy chấm công" → "Chưa kết nối nguồn đồng bộ"), `PayrollP0FixesTest` (lịch sử đồng bộ: "42 lượt", "40 / 42", "Thất bại" → cột số + "Lỗi toàn bộ"), `PayrollBusinessTest` (tên bậc hoa hồng không còn bắt buộc; tiêu đề "Lương của tôi").
**Triển khai:** `php artisan migrate` (5 migration trên, chỉ thêm cột). Build lại asset (`npm run build`, commit riêng "chore: rebuild assets").

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
