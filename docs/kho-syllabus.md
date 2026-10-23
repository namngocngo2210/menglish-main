# Kho syllabus & quy trình xử lý syllabus

Trạng thái: **Giai đoạn 1 — Hoàn thành** (giai đoạn 2–5 chưa làm) — 23/10/2026

Nguồn yêu cầu: `docs/Syllabus_Quy_trinh_xu_ly.xlsx` (Sheet 1 form syllabus mẫu, Sheet 2 chia chặng & test,
Sheet 3 quy trình 11 bước, Sheet 4 câu hỏi đã trả lời). Tiếp nối `docs/giao-trinh-don-giao-dien.md` (PR #195).

## 1. Bối cảnh

Quy trình mới: Học thuật upload syllabus gốc theo chặng vào **Kho syllabus** → giao chặng cho lớp (bản riêng của lớp,
chạy theo buổi) → GV xác nhận → GV dạy theo buổi hôm nay, order in ấn / test theo mốc → còn N buổi đến cuối chặng thì
hệ thống tạo việc cho Học thuật (Nhận việc → Hoàn thành → Báo cáo tuần) → Học thuật giao chặng tiếp theo.

Hiện trạng (khảo sát 23/10/2026):

- Buổi học (`syllabus_lessons`) chỉ có mục tiêu, nội dung ("Hoạt động"), BTVN 1 ô, từ vựng, ngữ pháp; không có loại buổi,
  link PPT / Quizizz, homework theo giỏ, sản phẩm đầu ra, mốc thông báo.
- Tài liệu (`syllabus_documents`) gắn giáo trình + chặng (chặng trống = chung); không gắn được buổi. GV xem mọi tài liệu
  có cờ "cho GV xem", không giới hạn theo lớp mình dạy.
- Không có lịch sử phiên bản (`version` là nhãn chữ; các model syllabus chưa dùng `AuditsChanges`).
- Import (`docs/import-syllabus.md`) chỉ tạo **cả giáo trình mới**; không cập nhật được, không import theo chặng.
- Mọi cảnh báo tính theo **ngày**; tiến độ lớp suy ra từ lịch (`SyllabusProgressionService::position`).
- Chặng kế tiếp **tự mở** khi Big Test chặng trước duyệt & gửi PH.
- Học vụ (`academic_staff`) có `syllabus.manage` → đang sửa được giáo trình gốc.

## 2. Quyết định chung (chủ dự án chốt 23/10/2026)

1. Làm theo 5 giai đoạn, mỗi giai đoạn một thiết kế chi tiết + PR riêng (mục 6).
2. **Chuyển chặng:** bỏ tự mở chặng kế tiếp; Big Test xong → Học thuật giao chặng tiếp theo bằng tay, GV xác nhận
   (giai đoạn 2).
3. **Syllabus của lớp:** không sao chép cả syllabus; lớp chỉ lưu **bản đè** cho buổi được sửa riêng. Sửa bản gốc → mọi
   lớp chưa sửa riêng buổi đó nhận thay đổi (Sheet 4 câu 3) (giai đoạn 2).
4. **Tiến độ vẫn tính theo buổi học thực tế** (buổi không hủy); GV không phải bấm "Đã dạy" để tiến độ chạy
   (giai đoạn 3).
5. **Tài liệu** gắn được theo **Buổi**, **Chặng** hoặc **Chung** cả giáo trình.
6. **Kho syllabus** (xem & sửa bản gốc) chỉ **Học thuật + Quản lý + Admin**. Học vụ giữ giao chặng, Big Test, order;
   không sửa bản gốc nhưng **xem được** Kho syllabus và mọi tài liệu (chỉ đọc) để order in ấn (chốt 23/10/2026).
7. GV chỉ xem bài giảng **buổi hôm nay**, của chặng lớp đang học (giai đoạn 3).

## 3. Giai đoạn 1 — Kho syllabus

### 3.1. Màn hình & điều hướng

- Workspace **Giáo trình**: `Kho syllabus | Giao chặng | Chặng đang dạy & Order Test | Dự án soạn sách & chương trình`.
  Bỏ tab **Tài liệu** và **Xem bài giảng**.
  - `syllabus.documents` → redirect Kho syllabus. `syllabus.builder?curriculum=X` → redirect trang chi tiết X.
  - Trang `syllabus.teacher-view` **giữ** (đích của nút "Mở giáo trình" ở Trang chủ GV / Điểm danh) cho tới khi giai
    đoạn 3 thay bằng màn bài giảng hôm nay; bỏ khỏi sidebar.
  - `/syllabus` (`syllabus.index`): có `syllabus.library_view` → Kho syllabus; còn lại → Chặng đang dạy.
- **Kho syllabus** (`syllabus.curriculums.index`): bảng Mã · Tên · Phiên bản · Khóa · Trình độ áp dụng ·
  Số chặng / buổi · Số lớp đang học · Cập nhật lần cuối; tìm theo mã / tên; phân trang. Nút **Tạo giáo trình** (modal)
  và **Import giáo trình mới** (import cả giáo trình như cũ). Bấm dòng → trang chi tiết.
- **Chi tiết giáo trình** (`syllabus.curriculums.show`), tab qua query `?tab=`:
  - **Thông tin** — tên, mã, phiên bản, khóa, trình độ áp dụng, mô tả (form đang ở đầu Builder); Xóa giáo trình.
  - **Chặng & buổi** (mặc định) — cây Chặng → Unit → Buổi, sửa trong modal (cơ chế PR #195).
    - Buổi: số buổi, tên, nhãn loại (Học / Mini test / Big test), mở rộng xem đủ trường; tài liệu của buổi + tải lên.
    - Chặng: tên, Big Test cuối chặng, **Báo trước N buổi**, tổng số buổi / số mini test; tài liệu chặng + tải lên;
      **Import chặng từ file** (3.3).
  - **Tài liệu chung** — tài liệu không gắn chặng / buổi; tải lên, xem, xóa.
  - **Lịch sử** — nhật ký thay đổi của giáo trình, chặng, unit, buổi, tài liệu (3.4).
- Upload tài liệu: một modal dùng chung, phạm vi (buổi / chặng / chung) điền sẵn theo chỗ bấm; giữ các cờ hiện có
  (GV xem, TA xem, cho tải). Bỏ màn `Documents.vue`.

### 3.2. Dữ liệu (migration; mọi cột mới nullable / có mặc định)

| Bảng | Cột mới | Ghi chú |
|---|---|---|
| `syllabus_lessons` | `lesson_type` string(20) default `lesson` (`lesson` / `mini_test` / `big_test`) | Sheet 1 "Loại buổi"; Sheet 4 câu 6 |
| | `procedure` text | "Quy trình tiết học chung" |
| | `activity_1`, `activity_2` text | "Hoạt động gợi ý" (tối đa 2) |
| | `homework_quizizz`, `homework_video` text | Homework giỏ Quizizz / Video; `homework_guide` có sẵn = **Bài tập giấy** (đổi nhãn) |
| | `ppt_url`, `quizizz_url` string(500) | |
| | `output_product` text | "Sản phẩm đầu ra" |
| | `notice_milestone` string(30) nullable (`order_print` / `order_mini_test` / `order_big_test`) | "Mốc thông báo"; giai đoạn 4 dùng |
| | `note` text | "Ghi chú" |
| `syllabus_stages` | `notice_before_sessions` unsigned smallint default 6 | Sheet 2 "Báo trước"; giai đoạn 4 dùng |
| `syllabus_documents` | `syllabus_lesson_id` FK nullable, index | Phạm vi: có lesson → buổi; có stage → chặng; không → chung |

- `content` giữ, đổi nhãn thành **Content** (nội dung từng tiết). `vocabulary_focus` / `grammar_focus` giữ (đang dùng
  cho "Hôm nay học gì" của báo cáo trực lớp).
- Không xóa / đổi tên cột cũ (v2 CI chạy migration Laravel + `import-legacy`).
- "Chỉnh sửa thủ công" thuộc syllabus của lớp → giai đoạn 2.

### 3.3. Import chặng từ file (form Sheet 1)

- Mỗi file `.xlsx` = **1 chặng** của giáo trình đang mở. Đọc đầu sheet: Giáo trình (D3, đối chiếu tên / mã, lệch → cảnh
  báo), Chặng (D4 → tên chặng), Phiên bản (D6). Bảng từ dòng 11, cột A–R như Sheet 1; bỏ dòng trống (chỉ có số buổi)
  và dòng ví dụ của file mẫu.
- Cột → trường: Buổi (thứ tự trong chặng) · Loại buổi → `lesson_type` · UNIT (ô có giá trị mở unit mới; ô trống =
  unit trên) · Objectives của UNIT → `units.objectives` · Content · Quy trình · Hoạt động 1/2 · Homework giấy /
  Quizizz / Video · Link PPT · Link Quizizz · Sản phẩm đầu ra · Mốc thông báo · Ghi chú. Cột "Chỉnh sửa thủ công" bỏ qua.
- Luồng: upload → **xem trước** (số unit / buổi, buổi sẽ thêm / sửa / xóa, lỗi theo dòng) → **xác nhận**; cùng cơ chế
  token / session của import hiện tại.
- Chặng **mới** (thêm cuối giáo trình) hoặc **cập nhật chặng đã có**: buổi thứ i của file ghi đè buổi thứ i của chặng
  (giữ `id`), thừa → tạo, thiếu → xóa mềm; unit khớp theo số unit. Xong thì **đánh lại `session_no` liên tục** theo thứ tự
  chặng.
- Nút **Tải file mẫu** = file Sheet 1 (sinh từ code, cùng cấu trúc).
- Import **cả giáo trình mới** (cách cũ, zip) giữ cách làm cũ, nhưng sheet Buổi / Chặng **thêm các cột Sheet 1**
  (không bắt buộc: Loại buổi, Quy trình, Hoạt động gợi ý 1–2, Bài tập Quizizz / Video, Link PPT / Quizizz, Sản phẩm
  đầu ra, Mốc thông báo, Ghi chú; chặng: Báo trước (buổi)). Cột "Hoạt động" đổi tên "Content (nội dung từng tiết)",
  tên cũ vẫn nhận. Đọc ô dùng chung `App\Services\Syllabus\LessonSheetCells` với import chặng.

### 3.4. Quyền & lịch sử

- Quyền mới **`syllabus.library`** ("Kho syllabus — xem & sửa syllabus gốc") trong `config/permission_catalog.php`;
  mặc định cho `manager`, `academic_lead` (`syllabus.*`), Admin (Super Admin). Migration cấp cho các vai trò này đang
  có trong DB. Xuất lại catalog v2.
- Route Kho syllabus, chi tiết, CRUD giáo trình / chặng / unit / buổi, import, upload / xóa tài liệu:
  `can:syllabus.library` (thay `syllabus.manage` / `syllabus.upload`). Học vụ mất các route sửa; giữ giao chặng, Big Test (xem: `library_view` bên dưới).
- Quyền **`syllabus.library_view`** ("Xem Kho syllabus", chỉ đọc): danh sách + trang chi tiết + xem / tải mọi tài liệu,
  không ô soạn thảo, không nút sửa / import / tải lên. Cấp cho `academic_staff` (Học vụ), `manager`, `academic_lead`
  (migration `2026_11_20_090400`). Route xem dùng `can:syllabus.library_view`; mọi thao tác sửa vẫn `can:syllabus.library`.
- GV / TA (không có `syllabus.library_view`): chỉ xem / tải tài liệu thuộc **chặng đang mở của lớp mình** (tài liệu chung
  của giáo trình + tài liệu chặng + tài liệu buổi trong chặng), vẫn theo cờ GV / TA xem. Áp dụng ở `teacher-view` và
  `documents/{id}/file`; ngoài phạm vi → 404.
- **Lịch sử**: thêm `AuditsChanges` cho `SyllabusCurriculum`, `SyllabusStage`, `SyllabusUnit`, `SyllabusLesson`,
  `SyllabusDocument` (khai báo trong `App\Support\Audit::MODELS`), ghi kèm `curriculum_id` vào properties để lọc. Tab
  Lịch sử: thời gian · người sửa · đối tượng · trường đổi (trước → sau). Import ghi 1 dòng tổng hợp (chặng, số buổi
  thêm / sửa / xóa) thay vì từng buổi.

### 3.5. Rủi ro

- Đánh lại `session_no` khi import chặng làm đổi ánh xạ "buổi thứ N của lớp ↔ bài" trong `SessionLessonService`
  (đang ánh xạ theo số buổi toàn giáo trình). Chấp nhận ở giai đoạn 1 (sửa bản gốc vốn áp dụng cho mọi lớp — câu 3);
  giai đoạn 3 chuyển ánh xạ sang **vị trí trong chặng đang mở**.
- Học vụ mất quyền sửa giáo trình gốc (vẫn xem được) → báo trước cho người dùng.
- v2: migration mới chạy trong v2 CI; catalog quyền phải xuất lại; các màn Syllabus v2 lệch thêm (ghi ở
  `v2/doi-chieu-laravel.txt` khi đồng bộ).

## 4. Sprint giai đoạn 1

### Sprint 1 — Dữ liệu, quyền, Kho syllabus & trang chi tiết (xong)

- [x] Migration cột mới (3.2) + model / validation các trường buổi, chặng.
- [x] Quyền `syllabus.library` + migration cấp quyền + catalog v2; đổi middleware route.
- [x] Kho syllabus (danh sách) + trang chi tiết (Thông tin, Chặng & buổi) thay Builder; form buổi đủ trường Sheet 1.
- [x] Sidebar: Kho syllabus thay Soạn syllabus, bỏ Xem bài giảng; redirect URL cũ; `/syllabus` theo quyền mới.
  Tab **Tài liệu** giữ tới Sprint 2 (lúc upload chuyển vào trang chi tiết).
- [x] Test: quyền theo vai trò, redirect, CRUD buổi đủ trường, guard-rail (Navigation, AllRoutesSmoke, BranchIsolation).

### Sprint 2 — Tài liệu theo buổi / chặng / chung & lịch sử (xong)

- [x] `syllabus_lesson_id` cho tài liệu; modal upload dùng chung; tab Tài liệu chung; bỏ `Documents.vue` và tab
  **Tài liệu** trên sidebar.
- [x] Giới hạn tài liệu của GV / TA theo chặng đang mở của lớp (teacher-view + file).
- [x] `AuditsChanges` + tab Lịch sử.
- [x] Test: phạm vi tài liệu, GV ngoài lớp bị 404, lịch sử ghi đúng.

### Sprint 3 — Import chặng theo form Sheet 1 (xong)

- [x] Đọc / kiểm tra file Sheet 1, xem trước, xác nhận; tạo chặng mới / cập nhật chặng đã có; đánh lại `session_no`.
- [x] File mẫu Sheet 1; cập nhật `docs/import-syllabus.md`.
- [x] Test: file mẫu, chặng mới, cập nhật (thêm / sửa / xóa buổi, giữ id), file lỗi, dòng ví dụ bị bỏ.

### Bổ sung sau review (23/10/2026, xong)

- [x] Học vụ xem Kho syllabus chỉ đọc + mọi tài liệu (`syllabus.library_view`).
- [x] File mẫu zip (import cả giáo trình) thêm cột Sheet 1; file cũ vẫn import được.

## 5. Ngoài phạm vi giai đoạn 1

Mọi mục ở giai đoạn 2–5 bên dưới.

## 6. Lộ trình các giai đoạn sau (thiết kế chi tiết khi bắt đầu từng giai đoạn)

| GĐ | Nội dung | Bước quy trình / câu hỏi |
|---|---|---|
| 2 | Giao syllabus & syllabus của lớp: bản đè theo buổi + cờ chỉnh sửa thủ công + lý do; tab "Syllabus của lớp" trong trang lớp; GV xác nhận nhận syllabus, nhắc sau 24h cho Học thuật + Quản lý; thông báo có nút (XÁC NHẬN / ĐÃ XEM); bỏ tự mở chặng, Học thuật giao chặng kế; đề xuất sửa được duyệt → báo GV | Bước 3, 4, 5, 11; câu 3, 4, 8 |
| 3 | GV vận hành: màn **bài giảng hôm nay** (chỉ buổi hôm nay, chặng đang mở) thay teacher-view; ánh xạ buổi theo vị trí trong chặng, thống nhất buổi học bù / giãn tiến độ; hủy buổi thủ công kéo theo tiến độ; "Customize syllabus" (xin dời / giãn, Học thuật duyệt) | Bước 6; câu 2 |
| 4 | Cảnh báo & công việc: còn N buổi đến cuối chặng → việc cho Học thuật (NHẬN VIỆC, hạn = buổi cuối chặng, quá hạn nhắc hằng ngày, vào báo cáo tuần); nút ORDER theo mốc syllabus (in ấn → vận hành cơ sở, test → Học thuật, xử lý trước ≥ 3 buổi) | Bước 7–10; câu 1, 5, 9, 10 |
| 5 | Homework 3 giỏ: bài nộp gắn bài tập, thống kê tỉ lệ nộp theo HS / lớp (Quizizz tùy IT) | Câu 7 |
