# Kho syllabus & quy trình xử lý syllabus

Trạng thái: **Giai đoạn 1–5 — Hoàn thành** (mục 5–12, duyệt 23/10/2026)

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

1. Làm theo 5 giai đoạn, mỗi giai đoạn một PR riêng (thiết kế giai đoạn 2–5: mục 5–12).
2. **Chuyển chặng:** bỏ tự mở chặng kế tiếp; Big Test xong → Học thuật giao chặng tiếp theo bằng tay, GV xác nhận
   (giai đoạn 2).
3. **Syllabus của lớp:** không sao chép cả syllabus; lớp chỉ lưu **bản đè** cho buổi được sửa riêng. Sửa bản gốc → mọi
   lớp chưa sửa riêng buổi đó nhận thay đổi (Sheet 4 câu 3) (giai đoạn 2).
4. ~~Tiến độ tính theo buổi học thực tế~~ → **đổi 23/10/2026 (làm đúng 100% quy trình Bước 6): tiến độ chỉ chạy khi
   GV bấm "Đã dạy"**. Buổi học hủy / nghỉ không kéo tiến độ; lịch dự kiến các buổi còn lại tự dời theo TKB (giai đoạn 3).
5. **Tài liệu** gắn được theo **Buổi**, **Chặng** hoặc **Chung** cả giáo trình.
6. **Kho syllabus** (xem & sửa bản gốc) chỉ **Học thuật + Quản lý + Admin**. Học vụ không sửa bản gốc nhưng **xem được**
   Kho syllabus và mọi tài liệu (chỉ đọc) để order in ấn (chốt 23/10/2026). **Giao chặng: chỉ Học thuật** (đổi 23/10/2026
   theo Bước 3 — Học vụ thôi giao chặng, vẫn xem danh sách chặng của lớp, giữ Big Test / order).
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
    - Buổi: số buổi, tên, nhãn loại (Học / Ôn tập / Grammar Basic / Mini test / Big test), mở rộng xem đủ trường; tài liệu của buổi + tải lên.
    - Chặng: tên, Big Test cuối chặng, **Báo trước N buổi**, tổng số buổi / số mini test; tài liệu chặng + tải lên;
      **Import chặng từ file** (3.3).
  - **Tài liệu chung** — tài liệu không gắn chặng / buổi; tải lên, xem, xóa.
  - **Lịch sử** — nhật ký thay đổi của giáo trình, chặng, unit, buổi, tài liệu (3.4).
- Upload tài liệu: một modal dùng chung, phạm vi (buổi / chặng / chung) điền sẵn theo chỗ bấm; giữ các cờ hiện có
  (GV xem, TA xem, cho tải). Bỏ màn `Documents.vue`.

### 3.2. Dữ liệu (migration; mọi cột mới nullable / có mặc định)

| Bảng | Cột mới | Ghi chú |
|---|---|---|
| `syllabus_lessons` | `lesson_type` string(20) default `lesson` (`lesson` / `review` / `grammar` / `mini_test` / `big_test`) | Sheet 1 "Loại buổi"; Sheet 4 câu 6; Ôn tập / Grammar Basic theo syllabus FAM 2 |
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
  báo), Chặng (D4 → tên chặng), Phiên bản (D6). Dòng tiêu đề A = "Chặng", B = "Buổi…"; **cột nhận theo chữ tiêu đề**,
  không theo vị trí (form Học thuật thêm cột theo thời gian); có hoặc không dòng tiêu đề phụ đều được. Bỏ dòng trống
  (chỉ có số buổi) và dòng ví dụ của file mẫu.
- Cột → trường: Buổi (thứ tự trong chặng) · Loại buổi → `lesson_type` · UNIT ("Unit N: Tên" mở unit mới; ô trống =
  unit trên; chữ khác như "GRAMMAR BASIC 1 – TOPIC 1", "ÔN TẬP CHẶNG", "BIG TEST" là tiêu đề riêng của buổi, buổi vẫn
  thuộc unit đang mở) · Objectives của UNIT → `units.objectives` · Content · Quy trình · Hoạt động 1/2 · Homework giấy /
  Quizizz / Video · Link PPT · Link PPT GVNN → `ppt_foreign_url` · Link Quizizz · Link Mini test / Kiểm tra từ →
  `test_url` · Sản phẩm đầu ra · Mốc thông báo · Ghi chú. Cột "Chỉnh sửa thủ công" bỏ qua.
- Luồng: upload → **xem trước** (số unit / buổi, buổi sẽ thêm / sửa / xóa, lỗi theo dòng) → **xác nhận**; cùng cơ chế
  token / session của import hiện tại.
- Chặng **mới** (thêm cuối giáo trình) hoặc **cập nhật chặng đã có**: buổi thứ i của file ghi đè buổi thứ i của chặng
  (giữ `id`), thừa → tạo, thiếu → xóa mềm; unit khớp theo số unit. Xong thì **đánh lại `session_no` liên tục** theo thứ tự
  chặng.
- Nút **Tải file mẫu** = file Sheet 1 (sinh từ code, cùng cấu trúc).
- Import **cả giáo trình mới** (cách cũ, zip) giữ cách làm cũ, nhưng sheet Buổi / Chặng **thêm các cột Sheet 1**
  (không bắt buộc: Loại buổi, Quy trình, Hoạt động gợi ý 1–2, Bài tập Quizizz / Video, Link PPT / PPT GVNN / Quizizz /
  Mini test – Kiểm tra từ, Sản phẩm
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
- [x] Đọc file chặng theo form mới của Học thuật (syllabus FAM 2): cột theo tiêu đề, 1 dòng tiêu đề, loại Ôn tập /
  Grammar Basic, ô UNIT là tiêu đề riêng của buổi, 2 cột link mới (`ppt_foreign_url`, `test_url`) cho cả import zip.
- [x] Dữ liệu test: `DemoSyllabusLibrarySeeder` (buổi đủ cột Sheet 1, tài liệu theo đối tượng, đề xuất sửa, giáo trình import zip) và bộ file thử import `docs/mau-test-kho-syllabus/` (giữ đúng bằng `SyllabusSampleFilesTest`).

### Bổ sung sau giai đoạn 5 (24/10/2026, xong)

- [x] Màn "Import giáo trình mới" nhận ra file 1 chặng (form Sheet 1) và chỉ sang "Thêm chặng từ file" của giáo trình thay
  cho 3 lỗi "Thiếu sheet".
- [x] `DemoSyllabusLibrarySeeder` đủ bước quy trình giai đoạn 2–5 cho mọi vai trò (nhắc 24h, Customize Giãn đã duyệt, order
  theo mốc, việc cuối chặng mới / quá hạn / hoàn thành, báo cáo tuần Học thuật có ghi chú Quản lý, buổi mini test chưa nhập
  điểm); `DemoSyllabusRoleScreensTest` mở các màn của từng vai trò trên dữ liệu demo.

## 5. Đối chiếu quy trình (Sheet 3) & câu hỏi (Sheet 4) → giai đoạn

| Quy trình / câu hỏi | Nội dung | Giai đoạn |
|---|---|---|
| Bước 1 | Upload syllabus gốc theo chặng vào Kho, lịch sử phiên bản | 1 (xong) |
| Bước 2, Sheet 2 | Báo trước N buổi / chặng (xong); **tự tính tổng số buổi chặng + buổi hệ thống báo Học thuật** | 1 + 2 |
| Bước 3, 11 | Học thuật chọn chương trình → chặng → lớp → ngày bắt đầu; lớp có syllabus riêng xếp theo buổi; chặng kế giao tay | 2 |
| Bước 4, câu 4 | GV XÁC NHẬN nhận syllabus; 24h chưa xác nhận → nhắc 1 lần GV + Học thuật + Quản lý | 2 |
| Bước 5, câu 3, câu 8 | Sửa riêng cho lớp (cờ Chỉnh sửa thủ công + lý do) → báo GV (ĐÃ XEM); sửa gốc áp cho mọi lớp đang chạy, bản sửa riêng không lan sang lớp khác; Học thuật sửa → GV nhận thông báo | 2 |
| Bước 6, câu 2, câu 12 | GV xem buổi hôm nay, bấm **Đã dạy** (ghi chú chậm / nhanh); lớp hủy buổi → lịch tự dời; GV muốn đổi tiến độ → **Customize syllabus** (xin giãn / dời), Học thuật duyệt; link PPT / Quizizz chỉ GV lớp được giao thấy | 3 |
| Bước 7, câu 5 | Nút ORDER luôn ở góc màn GV, sáng / nhấp nháy khi tới mốc; in ấn → vận hành cơ sở, đề test → Học thuật; xử lý trước ≥ 3 buổi trước test | 4 |
| Bước 8–10, câu 1, 9, 10 | Còn N buổi đến buổi cuối chặng **hoặc tới mốc "Báo chuẩn bị" đã đánh dấu trong syllabus** → thông báo + việc cho Học thuật (NHẬN VIỆC → Đang làm, hạn = buổi cuối chặng, quá hạn đỏ + nhắc hằng ngày, HOÀN THÀNH → báo cáo tuần; Quản lý + người làm xem & ghi chú); việc giao theo cơ sở và lớp | 4 |
| Câu 6, câu 11 | Mini test ở buổi đầu Unit sau, Big Test là buổi riêng (đã có trong form Sheet 1); sản phẩm đầu ra của buổi test = điểm → nút nhập điểm từ buổi test | 3 |
| Câu 12 | PPT / PPT GVNN của buổi **tải file lên app**, GV chặng đang mở trình chiếu trong app (iframe Office Online) — thay cho mở quyền Google Drive | 2 |
| Câu 7 | Homework 3 giỏ: HS nộp ảnh (bài giấy) / video; Quizizz HS tự bấm "Đã làm" (thống kê Quizizz "tùy chọn IT"); thống kê tỉ lệ nộp theo HS / lớp | 5 |
| Quy tắc thông báo (Sheet 3) | 6 loại thông báo, mỗi loại có nút hành động (XÁC NHẬN, ĐÃ XEM, NHẮC LẠI, NHẬN VIỆC, ORDER, XỬ LÝ) | 2 (khung) + 2–4 |

Khác quy trình có chủ đích (chủ dự án đã chốt): Bước 1 ghi "chỉ Học thuật & Quản lý được xem" Kho syllabus — Học vụ
**xem chỉ đọc** để order in ấn (chốt 23/10/2026). Câu 12 "mở quyền truy cập trên Drive": ban đầu hệ thống tự mở / đóng quyền xem
file Google Drive qua Drive API (chốt 23/10/2026); **đã bỏ (26/10/2026)** — PPT của buổi tải lên app và trình chiếu bằng iframe
Office Online (mục 7.4).

## 6. Nền chung cho giai đoạn 2–5

### 6.1. Thông báo có nút hành động

- `admin_notifications.data.action = {label, url, method}` (method `post` hoặc `get`). Chuông thông báo
  (`Layouts/Shell/NotificationMenu.vue`) và trang Thông báo hiện nút; bấm nút `post` gọi đúng hành động rồi đánh dấu đã đọc.
- `App\Services\Syllabus\SyllabusNotifier` gom mọi thông báo của quy trình (nội dung mẫu đúng Sheet 3 "Quy tắc thông báo");
  hàm theo sự kiện: `stageAssigned`, `classSyllabusUpdated`, `unconfirmedReminder`, `stageEnding`, `orderDue`, `taskOverdue`.
- ĐÃ XEM = `POST notifications.read` (chỉ đánh dấu đã đọc), không cần bảng riêng.

### 6.2. Bài học hiệu lực của lớp (bản gốc + bản đè)

- `App\Services\Syllabus\ClassSyllabus` — một chỗ duy nhất trả về các buổi của chặng lớp đang học, mỗi buổi = buổi gốc
  ghép bản đè của lớp (nếu có), kèm trạng thái đã dạy và ngày dự kiến. Màn lớp, màn GV, ORDER, cảnh báo cuối chặng,
  `SessionLessonService` đều đọc qua đây.

## 7. Giai đoạn 2 — Giao syllabus & syllabus của lớp

### 7.1. Dữ liệu

| Bảng | Cột | Ghi chú |
|---|---|---|
| `syllabus_assignments` | `start_date` date null, `planned_sessions` smallint null, `confirmed_at` timestamp null, `confirmed_by` FK users null, `confirm_reminded_at` timestamp null | Ngày bắt đầu + số buổi dự kiến từ form giao; backfill `start_date = date(opened_at)`, `planned_sessions` = số buổi của chặng. `deadline` (dự kiến kết thúc) **tự tính**, không nhập tay |
| `syllabus_lesson_slides` (mới, 26/10/2026; thay `syllabus_drive_grants` đã xoá) | `id`, `syllabus_lesson_id` FK, `kind` (`ppt` / `ppt_foreign`), `file_path`, `original_name`, `size_bytes`, `uploaded_by` FK users null, timestamps; unique (`syllabus_lesson_id`, `kind`) | PPT tải lên của buổi — mục 7.4 |
| `class_lesson_overrides` (mới) | `id`, `class_id` FK, `syllabus_lesson_id` FK, `data` json (chỉ các trường nội dung Sheet 1 bị sửa), `reason` text, `edited_by` FK users, timestamps; unique (`class_id`, `syllabus_lesson_id`) | Bản đè = "Chỉnh sửa thủ công: Có". Xoá bản đè = quay về bản gốc |

Không sao chép syllabus: buổi hiệu lực = gốc + `data` của bản đè. Sửa gốc → mọi lớp nhận ngay ở trường chưa bị đè
(đúng câu 3); bản đè chỉ thuộc 1 lớp.

### 7.2. Quyền

- Quyền mới `syllabus.assign` ("Giao chặng cho lớp"): Học thuật (+ Admin). Route mở / sửa / chuyển chặng đổi từ
  `syllabus.manage` sang `syllabus.assign`; Học vụ giữ `syllabus.manage` cho Big Test như cũ nhưng không giao chặng.
- Quyền mới `syllabus.class_edit` ("Sửa syllabus của lớp"): Học thuật (+ Admin).
- Migration cấp quyền; cập nhật `config/access.php`, `permission_catalog.php`, catalog v2.

### 7.3. Luồng

1. **Giao chặng** (màn Giao chặng hiện có, Học thuật): chương trình → chặng → lớp → **ngày bắt đầu (bắt buộc)** → **số buổi**
   (mặc định = số buổi syllabus của chặng) → GV. **Ngày dự kiến kết thúc tự tính** = ngày của buổi học thứ N (N = số buổi) của
   lớp tính từ ngày bắt đầu theo TKB (buổi chính khóa + học bù, bỏ buổi hủy / ngày nghỉ); TKB đổi (hủy buổi, nghỉ lễ, giãn
   tiến độ) → tính lại. Lớp chưa đủ buổi trong TKB → báo "TKB lớp mới có M buổi từ ngày bắt đầu".
   Tạo `SyllabusAssignment` (chưa xác nhận) → thông báo GV: "Bạn đã nhận syllabus mới: Lớp ABC – Giáo trình Y – Chặng X,
   bắt đầu dd/mm." nút **XÁC NHẬN**.
2. **XÁC NHẬN** (`POST syllabus.assignments.confirm`): chỉ GV được giao (hoặc GV chính / GVNN của lớp); ghi `confirmed_at`.
   Màn Giao chặng, tab Syllabus của lớp hiện "Chờ GV xác nhận / Đã xác nhận lúc …".
   **Bắt buộc trước "Đã dạy"** (chủ dự án 26/10/2026, `docs/luong-giao-vien.md`): chặng chưa xác nhận thì không đánh dấu Đã
   dạy được. **Hạn = 1 giờ trước buổi học đầu tiên của chặng** (giao sát giờ → buổi kế tiếp); quá hạn → SLA
   `gv.syllabus_confirm` lập biên bản cho GV (bậc tái phạm như nhận xét trễ, Học thuật chốt), báo Học thuật nút NHẮC LẠI.
   GV xác nhận ở thẻ "Chặng đang dạy", màn "Bài giảng hôm nay" hoặc "Syllabus & tài liệu" (Cổng GV).
   **Bắt đọc trước khi xác nhận** (chủ dự án 26/10/2026): mọi nút (thông báo, thẻ chặng, Bài giảng hôm nay, Syllabus &
   tài liệu) là **ĐỌC & XÁC NHẬN** → trang `syllabus.assignments.read`: thông tin chặng, mục tiêu chặng, các unit (tên, mục
   tiêu, số buổi — không có nội dung buổi, giữ quyết định 7), Big Test cuối chặng, tài liệu chặng. Nút XÁC NHẬN chỉ bật khi
   (1) mở trang đủ 30 giây (tính từ lần mở đầu, `syllabus_assignment_reads`) và cuộn tới cuối, (2) mọi tài liệu gắn chặng
   (không tính tài liệu buổi / tài liệu chung) đã "Đánh dấu đã xem" — nút này chỉ nhận sau khi mở xem tài liệu đủ 10 giây,
   (3) tick cam kết đã đọc. Máy chủ kiểm lại (1)(2)(3) (`ConfirmReading`); nút XÁC NHẬN cũ (POST thẳng) chuyển sang trang đọc.
   Khi xác nhận lưu `confirm_evidence` (thời gian đọc, tài liệu đã xem lúc nào) — Học thuật thấy "Đọc tổng quan … · mở n/n tài
   liệu chặng" ở Giao chặng và tab Syllabus của lớp; Học thuật mở trang đọc ở chế độ chỉ xem.
3. **Nhắc 24h** (lệnh `syllabus:remind-unconfirmed`, chạy mỗi giờ, idempotent theo `confirm_reminded_at`): chặng mở chưa
   xác nhận quá 24h → nhắc GV (nút XÁC NHẬN) + báo Học thuật + Quản lý chi nhánh lớp: "Lớp ABC chưa xác nhận syllabus
   chặng X." nút **NHẮC LẠI** (gửi lại thông báo cho GV). Chỉ nhắc 1 lần.
4. **Bỏ tự mở chặng kế** (Bước 11): Big Test chặng duyệt & gửi PH → đóng chặng, **không** mở chặng kế; báo Học thuật
   "Lớp ABC đã xong chặng X — giao chặng tiếp theo." (nút mở màn Giao chặng lọc sẵn lớp). Đóng tay chặng: bỏ ô "mở chặng kế".
5. **Tab "Syllabus" trong trang lớp** (`classes.show?tab=syllabus`): chặng đang học, trạng thái xác nhận, danh sách buổi
   hiệu lực (buổi, loại, nội dung rút gọn, ngày dự kiến, Đã dạy, cờ **Chỉnh sửa thủ công**). Học thuật bấm "Sửa cho lớp"
   → form các trường Sheet 1 + **lý do (bắt buộc)** → lưu bản đè → báo GV "Syllabus lớp ABC đã được cập nhật (buổi N)."
   nút **ĐÃ XEM**. "Bỏ chỉnh sửa riêng" xoá bản đè (cũng báo GV). Lịch sử ghi qua `AuditsChanges`.
6. **Sửa bản gốc → báo GV** (câu 8): lưu buổi / import chặng làm đổi buổi của chặng đang mở ở lớp nào → báo GV các lớp đó
   (gộp 1 thông báo / lớp / lần lưu) "Syllabus Chặng X (giáo trình Y) đã được Học thuật cập nhật." nút ĐÃ XEM.
7. **Sheet 2 tự tính**: trang chi tiết giáo trình, mỗi chặng hiện "N buổi · báo Học thuật ở buổi N − báo trước".

### 7.4. Bài giảng PowerPoint của buổi (câu 12)

Thay cho đồng bộ quyền xem Google Drive (bỏ 26/10/2026: bảng `syllabus_drive_grants`, lệnh `syllabus:sync-drive-access`,
bảng "Quyền xem Google Drive" ở tab Syllabus của lớp, biến `GOOGLE_DRIVE_SA_KEY_PATH`). Quyền đã mở trên Drive trước đó không bị
thu hồi tự động.

- **Tải lên**: Kho syllabus → buổi → "Bài giảng PPT": mỗi buổi 1 file **PPT** + 1 file **PPT GVNN** (`.pptx` / `.ppt`, tối đa
  100 MB, đuôi theo nội dung), tải lại = thay file. Chỉ `syllabus.library`. File nằm trên disk riêng tư `local`
  (`syllabus_slides/`, lên R2 khi `UPLOAD_STORAGE=r2`). Ô "Link PPT / Link PPT GVNN" bỏ khỏi form sửa buổi và form sửa riêng cho
  lớp; link cũ (dữ liệu trước đó, file import Sheet 1) vẫn hiện khi buổi chưa có file.
- **Trình chiếu**: route `syllabus.slides.show` — trang `Syllabus/SlideViewer` có iframe
  `https://view.officeapps.live.com/op/embed.aspx?src=…` (giữ hiệu ứng, liên kết nhảy slide, trigger; không chạy macro VBA) +
  nút Toàn màn hình. Mở từ chip PPT của buổi (Kho syllabus) và "Bài giảng hôm nay" của GV.
- **Ai xem**: người có `syllabus.library_view` / `library` / `class_edit` / `manage`; GV / GVNN / TG của lớp (kể cả dạy thay) hoặc
  GV được giao chặng — chỉ khi chặng của buổi **đang mở** cho lớp đó (như tài liệu buổi).
- **Link cho Microsoft**: trình xem Office tải file từ máy chủ của Microsoft nên cần link công khai: route `slides.public`
  `/slides/{id}/{expires}/{chữ ký}/slide.pptx` (không đăng nhập, chữ ký HMAC theo `APP_KEY` gồm đường dẫn file, hạn 4 giờ, thay
  file → link cũ hết hiệu lực, throttle 60/phút). Máy chạy thử (`APP_URL` localhost / IP nội bộ) không trình chiếu được — trang
  báo trước.

## 8. Giai đoạn 3 — GV vận hành theo buổi

### 8.1. Dữ liệu

| Bảng | Cột | Ghi chú |
|---|---|---|
| `class_lesson_progress` (mới) | `id`, `syllabus_assignment_id` FK, `class_id` FK, `syllabus_lesson_id` FK null, `class_session_id` FK null, `kind` (`lesson` / `extra`), `pace_note` text null, `taught_by` FK users, `taught_at` timestamp, timestamps; unique (`syllabus_assignment_id`, `syllabus_lesson_id`); index (`class_id`, `taught_at`) | `lesson` = đã dạy xong buổi syllabus; `extra` = dùng 1 buổi giãn đã duyệt (không qua buổi syllabus mới) |
| `syllabus_adjustment_requests` | `kind` (`extend` / `postpone`) default `extend` | Customize syllabus: giãn thêm buổi hoặc dời |

### 8.2. Tiến độ & lịch dự kiến

- **Buổi hiện tại** của lớp = buổi đầu tiên của chặng (theo `session_no`) chưa có dòng `lesson` trong `class_lesson_progress`.
  Tiến độ chỉ chạy khi GV bấm **Đã dạy** (quyết định 4).
- **Ngày dự kiến** các buổi còn lại = các buổi học sắp tới của lớp (chính khóa + học bù, không hủy, từ hôm nay, sau
  `start_date`) xếp lần lượt cho các buổi syllabus chưa dạy; buổi giãn đã duyệt (`extra_sessions`) dùng khi GV bấm "Dạy chưa
  xong" (đổi 24/10/2026 sau review: không giữ chỗ buổi giãn ở đầu lịch, để TKB / giao bài tập hiện đúng bài của buổi gần
  nhất). Dạy hết buổi của chặng → buổi còn lại ghi là buổi ôn / Big Test.
  Lớp hủy / nghỉ buổi → buổi đó biến mất khỏi TKB → lịch dự kiến tự dời (câu 2 "tự link báo cáo vận hành khi lớp cancel").
- `SessionLessonService` (TKB, giao bài tập, báo cáo trực lớp): buổi đã qua lấy theo `class_lesson_progress.class_session_id`,
  buổi sắp tới theo lịch dự kiến. Bỏ cách đếm "buổi chính khóa thứ N ↔ session_no N".

### 8.3. Màn "Bài giảng hôm nay" (thay tab Bài học của `syllabus.teacher-view`)

- GV chọn lớp mình dạy (GV chính / GVNN / TG / dạy thay buổi hôm nay). Chỉ hiện khi lớp **có buổi học hôm nay**; hiện
  **buổi hiện tại** (bản hiệu lực): loại buổi, unit, content, quy trình, hoạt động gợi ý 1–2, homework 3 giỏ, link PPT /
  PPT GVNN / Quizizz / đề, sản phẩm đầu ra, ghi chú, tài liệu buổi. Không xem được buổi khác (quyết định 7, câu 12).
- Nút **Đã dạy** (GV / GVNN của lớp): ô ghi chú tiến độ (chậm / nhanh — đề xuất Bước 6). Ghi dòng `lesson` gắn buổi học
  hôm nay. Lớp còn buổi giãn đã duyệt → thêm lựa chọn "Dạy chưa xong — dùng 1 buổi giãn" (ghi dòng `extra`, buổi syllabus
  giữ nguyên). Buổi học đã qua chưa bấm → banner "Buổi dd/mm chưa đánh dấu Đã dạy" (bấm được cho buổi đó).
- Buổi Mini test / Big test: nút **Nhập điểm** (màn điểm mini test / kết quả Big Test hiện có) — câu 11. Vận hành kiểm
  soát sản phẩm đầu ra (câu 11): tab Syllabus của lớp đánh dấu **"Chưa nhập điểm"** ở buổi test đã dạy mà lớp chưa có điểm
  mini test (unit tương ứng) / kết quả Big Test của chặng; Học vụ (vận hành) xem được tab này.
- Tab Tài liệu giữ nguyên (tài liệu chặng đang mở). Tab "Tổng quan syllabus" chỉ còn danh sách chặng + trạng thái.

### 8.4. Customize syllabus (câu 2)

- Đổi tên màn "Xin giãn tiến độ" → **Customize syllabus**; loại yêu cầu: **Giãn** (thêm N buổi vào TKB — như hiện có) hoặc
  **Dời** (lùi N buổi: cộng N buổi giãn cho chặng, không thêm buổi vào TKB). Học thuật duyệt; duyệt xong buổi giãn mới
  dùng được ở nút "Dạy chưa xong". GV không tự đổi tiến độ khi chưa được duyệt.

## 9. Giai đoạn 4 — Cảnh báo, công việc Học thuật, ORDER

### 9.1. Dữ liệu

| Bảng | Cột | Ghi chú |
|---|---|---|
| `work_tasks` | `syllabus_assignment_id` FK null; `kind` thêm `syllabus_stage_end`; `last_reminded_on` date null | 1 việc / chặng của lớp (unique theo assignment + kind ở tầng code) |
| `material_orders`, `big_test_orders` | `syllabus_lesson_id` FK null, `syllabus_assignment_id` FK null | Order sinh từ mốc syllabus → biết mốc nào đã order |

### 9.2. Sắp hết chặng → việc cho Học thuật (Bước 8–10)

- Mốc mới trong cột "Mốc thông báo": **`notify_academic` — "Báo chuẩn bị Big test / syllabus chặng mới"** (đọc từ ô có
  chữ "báo chuẩn bị", như syllabus FAM 2; hiện đang bị hiểu nhầm là Order Big Test). Thêm vào `SyllabusLesson::MILESTONES`,
  `LessonSheetCells::milestone`, file mẫu, form sửa buổi.
- Lệnh `syllabus:stage-end-alerts` (mỗi giờ, idempotent): chặng mở **(a) có số buổi syllabus chưa dạy ≤ báo trước của chặng**
  (mặc định 6) **hoặc (b) buổi hiện tại đã tới / qua buổi có mốc `notify_academic`** (Bước 8 "hoặc tới mốc đã đánh dấu"), và
  chưa có việc → tạo `WorkTask` kind `syllabus_stage_end`: tiêu đề "Chuẩn bị test chặng X – giáo trình Y và
  syllabus mới cho lớp ABC", chi nhánh + lớp của lớp, **người nhận** = người giao chặng nếu còn quyền `syllabus.assign`,
  không thì người có `syllabus.assign` phụ trách chi nhánh lớp (câu 10), hạn = ngày dự kiến buổi cuối chặng, trạng thái Mới.
  Thông báo kèm nút **NHẬN VIỆC**.
- **NHẬN VIỆC** (`POST tasks.accept`): Mới → Đang làm. **HOÀN THÀNH**: việc kind này hoàn thành ngay (không cần ảnh /
  xác nhận), ghi `completed_at` → tự vào bảng "Công việc đã làm trong tuần" của **Báo cáo tuần Học thuật** (đã có — gợi ý từ
  `WorkTask` hoàn thành).
- **Báo cáo tuần Học thuật** (câu 9): phần tóm tắt thêm 3 số — **việc hoàn thành**, **việc quá hạn**, **lớp đã giao
  syllabus** trong tuần (kèm danh sách). **Quản lý** (`staff_report.view_all`) và **chính người làm** xem và **ghi chú**
  vào báo cáo: thêm ghi chú dạng bình luận (`staff_report_notes`: `staff_report_id`, `user_id`, `note`, timestamps) —
  hiện chỉ Admin duyệt / trả lại được, Quản lý chưa ghi chú được.
- **Quá hạn**: `tasks:mark-overdue` (đã có) chuyển Quá hạn; việc kind này hiện **đỏ** trong danh sách; lệnh
  `syllabus:remind-overdue-tasks` (mỗi ngày 08:00, idempotent theo `last_reminded_on`) nhắc người nhận + Quản lý chi nhánh:
  "Việc … của lớp ABC đã quá hạn." nút **XỬ LÝ** (mở việc).

### 9.3. Nút ORDER (Bước 7, câu 5)

- Nút **ORDER** cố định ở góc **mọi màn của cổng Giáo viên** (layout cổng GV, không chỉ màn Bài giảng hôm nay — Bước 7
  "góc màn hình luôn có nút ORDER"); **nhấp nháy** khi một lớp GV dạy có mốc đến hạn chưa order: buổi có
  `notice_milestone` thuộc chặng đang mở, buổi đó là buổi hiện tại hoặc đã dạy, chưa có order gắn buổi đó.
- Bấm ORDER → màn order (chọn lớp nếu GV có nhiều lớp đến hạn): danh sách mốc (đến hạn / đã order) + form; mỗi loại
  order hiện rõ **bộ phận đích** (câu 5): "Gửi tới: Vận hành cơ sở <tên cơ sở>" / "Gửi tới: Học thuật":
  - **Order in ấn** (`order_print`) → tạo `MaterialOrder` loại In ấn (vận hành cơ sở xử lý — có sẵn), ngày dùng = ngày dự kiến
    buổi test kế tiếp.
  - **Order mini test / Big Test** (`order_mini_test` / `order_big_test`) → tạo `BigTestOrder` (Học thuật duyệt — có sẵn), ngày
    thi = ngày dự kiến buổi Mini test / Big test kế tiếp; **hạn xử lý = ngày của buổi thứ 3 trước buổi test**.
- Mốc đến hạn → thông báo GV "Đến hạn order in ấn mini test / big test lớp ABC." nút **ORDER** (lệnh mỗi giờ, 1 lần / mốc).

## 10. Giai đoạn 5 — Homework 3 giỏ (câu 7)

### 10.1. Dữ liệu

| Bảng | Cột | Ghi chú |
|---|---|---|
| `homework_submissions` (mới) | `id`, `homework_id` FK, `student_id` FK, `basket` (`paper` / `quizizz` / `video`), `file_path` null, `original_name` null, `note` null, `status` (`submitted` / `reviewed`), `submitted_at`, `reviewed_by` null, `reviewed_at` null, timestamps; unique (`homework_id`, `student_id`, `basket`); index (`student_id`) | Thay bản ghi `AcademicRecord` chung chung hiện tại |

Giỏ của bài tập: `Homework::CATEGORIES` → giỏ (`video` → video; `quiz` → quizizz; còn lại → giấy).

### 10.2. Luồng

- GV giao bài tập: từ màn Bài giảng hôm nay bấm "Giao bài tập theo syllabus" → form giao bài (đã có) điền sẵn 3 giỏ từ
  buổi hiện tại (Bài tập giấy / Quizizz / Video + link Quizizz).
- HS nộp (cổng học viên): theo từng bài tập & giỏ — **giấy = ảnh**, **video = video**; Quizizz: HS bấm "Đã làm" (tự khai) —
  không tích hợp API Quizizz (câu 7 "tùy chọn IT").
- GV xem bài nộp theo bài tập, đánh dấu "Đã xem" (màn **Bài nộp homework** của Cổng GV). File bài nộp lưu riêng tư, chỉ học
  viên / phụ huynh, GV lớp và nhân sự xem được học viên mở được.
- Cổng học viên (chủ dự án: nộp bài gắn với BTVN được giao của từng buổi): **chỉ nộp vào giỏ của BTVN GV giao**. Mỗi thẻ hiện
  tên lớp, **Buổi N** (số thứ tự buổi chính khóa của lớp; kèm Unit / bài syllabus nếu có), ngày học, nội dung theo giỏ, ghi chú
  GV, link Quizizz, hạn nộp; bài cũ không có `class_session_id` hiện nhãn "Không gắn buổi". Danh sách: bài của các buổi trong
  30 ngày gần nhất + mọi bài chưa nộp đủ giỏ (bất kể ngày), buổi mới nhất trước; không có bài → "Chưa có bài tập về nhà được
  giao". Đã gỡ 6 hạng mục nộp tự do và route `portal.student.homework.submit/update/destroy`; bản ghi `AcademicRecord` cũ
  (`03_hoc_tap_cua_toi_nop_bai_tap`) giữ nguyên, GV vẫn xem ở màn chấm bài nộp cũ.
- Bộ đếm cổng (trang chủ `submittedHomeworksCount` / `pendingHomeworksCount`, "Tiến độ học tập", "Đã hoàn thành x/y"): số giỏ
  đã nộp / tổng giỏ của mọi BTVN GV giao cho (các) lớp học viên.
- **Thống kê**: tab Homework trong trang lớp + màn GV: tỉ lệ nộp theo **giỏ × học sinh** và theo **lớp** (số bài nộp / số
  bài được giao trong khoảng ngày).

## 11. Rủi ro giai đoạn 2–5

- **Tiến độ chỉ chạy khi bấm Đã dạy**: GV quên bấm → lớp đứng yên, cảnh báo cuối chặng và ORDER trễ. Giảm thiểu: banner
  buổi chưa đánh dấu, cột "Đã dạy" trong tab Syllabus của lớp để Học thuật theo dõi.
- **Lớp đang chạy khi triển khai**: chưa có dòng `class_lesson_progress` → migration backfill: với mỗi chặng đang mở, đánh
  dấu Đã dạy số buổi = số buổi học đã qua kể từ ngày mở chặng (cách đếm cũ), để lớp không quay về buổi 1.
- `SessionLessonService` đổi cách đánh số buổi → các màn TKB / giao bài tập / báo cáo trực lớp đổi nhãn buổi; test hồi quy.
- **PPT trình chiếu trong app**: ai có link công khai (còn hạn) đều tải được file — link chỉ nằm trong iframe của trang đã kiểm
  quyền, hết hạn sau 4 giờ. Trình xem Office Online không chạy macro VBA.
- v2 parity: `iam.rbac` (2 quyền mới), `web.menu`, các bảng mới — đồng bộ ở đợt v2 sau.

## 12. Sprint giai đoạn 2–5 (mỗi giai đoạn 1 PR)

### Giai đoạn 2 (xong 24/10/2026)
- [x] S2.1 Thông báo có nút hành động (data.action, chuông + trang Thông báo) + `SyllabusNotifier`.
- [x] S2.2 Quyền `syllabus.assign` / `syllabus.class_edit` + migration; giao chặng chỉ Học thuật; ngày bắt đầu bắt buộc.
- [x] S2.3 GV xác nhận + nhắc 24h; bỏ tự mở chặng kế, báo Học thuật giao chặng tiếp.
- [x] S2.4 `ClassSyllabus` + bản đè + tab Syllabus trong trang lớp + báo GV khi sửa riêng / sửa gốc; Sheet 2 tự tính.
- [x] S2.5 Giao chặng: số buổi → ngày dự kiến kết thúc tự tính theo TKB.
- [x] S2.6 Google Drive: `DriveClient`, `syllabus_drive_grants`, lệnh đồng bộ mở / đóng quyền xem, bảng trạng thái, tài liệu cài đặt (đã bỏ 26/10/2026 → PPT tải lên + iframe, mục 7.4).

### Giai đoạn 3 (xong 24/10/2026)
- [x] S3.1 `class_lesson_progress` + backfill + tiến độ / lịch dự kiến trong `ClassSyllabus`; `SessionLessonService` dùng lịch mới.
- [x] S3.2 Màn Bài giảng hôm nay + Đã dạy (ghi chú tiến độ, buổi giãn, banner buổi chưa đánh dấu) + Nhập điểm buổi test
  + cờ "Chưa nhập điểm" cho vận hành.
- [x] S3.3 Customize syllabus (Giãn / Dời).

### Giai đoạn 4 (xong 24/10/2026)
- [x] S4.1 Mốc `notify_academic` + việc cuối chặng cho Học thuật (lệnh, NHẬN VIỆC, hoàn thành ngay, quá hạn đỏ + nhắc hằng ngày).
- [x] S4.2 Báo cáo tuần Học thuật: 3 số tóm tắt + Quản lý / người làm ghi chú.
- [x] S4.3 Nút ORDER trên mọi màn cổng GV + màn order theo mốc (bộ phận đích) + thông báo đến hạn order.

### Giai đoạn 5 (xong 24/10/2026)
- [x] S5.1 `homework_submissions` + giao bài tập theo syllabus + HS nộp theo giỏ.
- [x] S5.2 GV xem bài nộp + thống kê tỉ lệ nộp theo HS / lớp.

Mỗi sprint: test feature (TDD), cập nhật seeder demo (`DemoSyllabusLibrarySeeder` / `DemoPhase2Seeder`) khi luồng đổi,
`ZiggyRoutesTest` khi đổi route / .vue, guard-rail tests; cập nhật mục này (xong / chưa xong).
