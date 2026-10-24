# Import syllabus từ file mẫu

Trạng thái: **Hoàn thành** — 23/10/2026

## 1. Bối cảnh

Yêu cầu của chủ dự án:

- Bỏ "Tải file mẫu (.pdf)" ở trang Giáo trình → Tài liệu.
- Màn **Soạn syllabus** có một file mẫu (dạng zip) để Học thuật điền, rồi upload lên để import vào hệ thống thay vì
  nhập tay từng chặng / unit / buổi.

Hiện trạng:

- Cấu trúc: Giáo trình (`SyllabusCurriculum`) → Chặng (`SyllabusStage`) → Unit (`SyllabusUnit`) → Buổi
  (`SyllabusLesson`). Số buổi đánh liên tục trong cả giáo trình. Màn Soạn syllabus (`SyllabusController::builder`) tạo
  từng phần bằng form; unit chỉ có số + tên trên màn này.
- Tài liệu (`SyllabusDocument`) gắn giáo trình + chặng (chặng có thể trống = tài liệu chung), upload từng file ở trang
  Tài liệu qua `SafeUploadService` (kiểm tra theo nội dung), tối đa 100 MB/file, disk `SyllabusDocument::DISK`.
- `SyllabusCurriculum::booted()` tự tạo **Chặng 1** khi tạo giáo trình.
- `syllabus_curriculums.code` có unique index.
- Máy chủ giới hạn 100 MB mỗi request (`PHP_POST_MAX_SIZE` / `PHP_UPLOAD_MAX_FILE_SIZE` trong `docker-compose.yml`).
- Mẫu import có sẵn trong repo: Nhập danh sách lớp (`ClassRosterImportController`, `ClassRosterImportService`,
  `ClassRosterTemplateExport`) — upload → xem trước (lưu trong session theo token) → xác nhận.

## 2. Quyết định

1. File import là **zip gồm `syllabus.xlsx` + thư mục `tai-lieu/`** theo chặng. Không có tài liệu thì upload riêng
   `.xlsx` cũng được.
2. **Chỉ tạo giáo trình mới.** Mã đã tồn tại → lỗi, yêu cầu đổi mã. Không ghi đè giáo trình cũ (tránh lệch tiến độ các
   lớp đang học). Sửa giáo trình cũ vẫn làm ở màn Soạn syllabus; tài liệu bổ sung upload ở trang Tài liệu.
3. **Giữ giới hạn 100 MB** mỗi lần upload, không đổi cấu hình máy chủ. Video nặng upload riêng ở trang Tài liệu.
4. Luồng **upload → kiểm tra (xem trước) → xác nhận**, đồng bộ trong request (không dùng queue).
5. Cấp độ khoá học áp dụng giáo trình **không** có trong file; gán sau ở màn Soạn syllabus.
6. Quyền tài liệu chọn trên form import, áp cho mọi file của lần import; chỉnh từng file sau ở trang Tài liệu.

### Phương án đã cân nhắc

- **Import một bước, không xem trước**: ít code hơn, nhưng file đúng định dạng mà sai nội dung vẫn vào hệ thống và phải
  xoá tay.
- **Import chạy nền (queue)**: với 100 MB xử lý chỉ vài giây, queue chỉ thêm phức tạp.
- **Cập nhật giáo trình cũ khi trùng mã**: tiện sửa hàng loạt nhưng làm lệch số buổi đã dạy của lớp đang học; cần thêm
  quy tắc chặn. Để sau nếu có nhu cầu.
- **Nâng giới hạn upload lên ~500 MB**: import được cả video nhưng upload lâu, dễ đứt, tốn RAM/đĩa, phải đổi cấu hình
  production + staging.

## 3. Thiết kế chi tiết

### 3.1. File mẫu

`mau-import-syllabus.zip`:

```
mau-import-syllabus.zip
├── syllabus.xlsx
└── tai-lieu/
    ├── (file để thẳng ở đây = tài liệu chung của giáo trình, không gắn chặng)
    ├── chang-1/   slide-buoi-1.pdf, hinh-minh-hoa.png, audio-nghe.mp3 (ví dụ)
    └── chang-2/   video-mau.mp4 (ví dụ)
```

Tài liệu ví dụ (nguồn: `resources/syllabus-import-samples/`, tổng ~300 KB) giúp import thử file mẫu được ngay và cho
thấy các định dạng được nhận; Học thuật thay bằng tài liệu thật hoặc xoá đi.

`syllabus.xlsx` gồm 4 sheet, có sẵn dữ liệu ví dụ (2 chặng, vài buổi):

| Sheet | Cột | Quy tắc |
|---|---|---|
| Hướng dẫn | Mô tả cách điền | Không import |
| Giáo trình (1 dòng) | Mã · Tên · Phiên bản · Mô tả | Mã, Tên bắt buộc; mã ≤ 50 ký tự, chưa có giáo trình đang dùng (xoá giáo trình nhả mã: `code#deleted-id`); Phiên bản ≤ 20 ký tự, trống → `v1` |
| Chặng | Thứ tự · Tên chặng · Mô tả · Tên Big Test · Ghi chú Big Test · Link mục lục · Báo trước (buổi) | Thứ tự liên tục 1, 2, 3…; Tên bắt buộc; Báo trước 1–50, trống = 6 |
| Buổi | Chặng (thứ tự) · Unit số · Tên unit · Buổi số · Tên buổi · Mục tiêu · Content (nội dung từng tiết) · Từ vựng · Ngữ pháp · BTVN · Loại buổi · Quy trình tiết học · Hoạt động gợi ý 1 · Hoạt động gợi ý 2 · Bài tập Quizizz · Bài tập Video · Link PPT · Link Quizizz · Sản phẩm đầu ra · Mốc thông báo · Ghi chú · Link PPT GVNN · Link Mini test / Kiểm tra từ | Buổi số liên tục 1…N, không trùng; một unit chỉ thuộc một chặng; các dòng cùng unit cùng tên unit; mỗi chặng ≥ 1 buổi. Cột từ Loại buổi trở đi theo form Sheet 1, không bắt buộc (file cũ thiếu vẫn import được; cột "Hoạt động" cũ vẫn được hiểu là Content). Loại buổi Học / Ôn tập / Grammar Basic / Mini test / Big test; Mốc thông báo Order in ấn / Order mini test / Order Big Test; link phải là http(s) |

Thư mục `tai-lieu/`:

- `chang-<thứ tự>` phải khớp một chặng trong sheet Chặng; file ở gốc `tai-lieu/` là tài liệu chung.
- Tên tài liệu = tên file bỏ đuôi; tên gốc giữ ở `original_name`.
- Định dạng = `SyllabusDocument::ALLOWED_EXTENSIONS`; kiểm tra theo nội dung (`SafeUploadService`).
- Bỏ qua file hệ thống: `.DS_Store`, `__MACOSX/`, `Thumbs.db`, file bắt đầu bằng `._`.
- File nằm sâu hơn một cấp (`chang-1/abc/x.pdf`) → lỗi.

Sheet tìm theo tên (`Giáo trình`, `Chặng`, `Buổi`); cột đọc **theo tên tiêu đề** ở dòng 1 (không theo vị trí, không
phân biệt hoa thường / khoảng trắng); thiếu sheet / cột bắt buộc → lỗi. Zip nén cả thư mục (mọi file nằm trong một
thư mục gốc duy nhất, vd. `mau-import-syllabus/syllabus.xlsx`) được chấp nhận như zip phẳng.

### 3.2. Luồng màn hình

- Màn Soạn syllabus: nút **"Import từ file"** (chỉ người có `syllabus.manage`) → trang `syllabus.import`
  (`Pages/Syllabus/Import.vue`), bố cục 3 bước như Nhập danh sách lớp, nút **"Tải file mẫu (.zip)"** trên header.
- **① Tải file lên**: chọn `.zip` / `.xlsx` (≤ 100 MB), 3 ô quyền tài liệu (GV xem được — mặc định bật; TA xem được;
  Cho tải về), bấm **Kiểm tra file**.
- **② Kiểm tra**: tóm tắt (mã, tên, số chặng / unit / buổi / tài liệu); **lỗi** (chặn import) và **cảnh báo** (không
  chặn: chặng chưa có tài liệu, đã bỏ qua file hệ thống) theo vị trí — "Sheet Buổi, dòng 14: thiếu Tên buổi",
  "tai-lieu/chang-4/: không có Chặng 4"; cây Chặng → Unit → Buổi + tài liệu dưới từng chặng. Còn lỗi thì khoá nút
  **Xác nhận import**; nút **Tải file khác** quay về ①.
- **③ Hoàn tất**: tạo toàn bộ trong một transaction, chuyển về `syllabus.builder?curriculum=<id>` với thông báo
  "Đã import giáo trình KIDS-Q4: 3 chặng, 36 buổi, 12 tài liệu. Gán cấp độ khoá học để lớp dùng được giáo trình."
- Phiên kiểm tra hết hạn sau **2 giờ** → "Phiên kiểm tra đã hết hạn, vui lòng tải file lên lại".
- Trang Tài liệu: bỏ nút "Tải file mẫu (.pdf)", bỏ câu "Soạn theo file mẫu…" trong form upload, xoá
  `public/files/mau-tai-lieu-giao-trinh.pdf`.

### 3.3. Kiểm tra và an toàn

- Báo **mọi lỗi một lượt**, không dừng ở lỗi đầu. Bỏ dòng trống, trim khoảng trắng; độ dài theo cột DB (tên ≤ 255).
- Tối đa **1.000 buổi** (giới hạn `session_no` của màn Soạn syllabus); unit số 1…500.
- Zip: đúng **1 file `.xlsx` ở gốc**; từ chối cả file khi có đường dẫn `../` / tuyệt đối (zip slip), zip mã hoá,
  > **500 entry**, hoặc tổng dung lượng giải nén > **200 MB** (zip bomb). Mỗi tài liệu ≤ `SyllabusDocument::MAX_KB`.
- Mã trùng: kiểm tra ở bước ②, kiểm tra lại khi xác nhận; unique index trên `code` chặn trường hợp hai người import
  cùng mã cùng lúc (bắt `UniqueConstraintViolationException` → báo lỗi trùng mã).
- Xác nhận: cập nhật Chặng 1 do `booted()` tạo bằng dòng đầu sheet Chặng (không tạo thêm), tạo các chặng / unit / buổi
  còn lại + tài liệu trong `DB::transaction`. Lỗi giữa chừng → rollback và xoá các file đã lưu trong lần import đó.
  Audit log như tạo tay.
- File tạm: `storage/app/private/syllabus-imports/<uuid>.<ext>` (ghi thẳng bằng `storage_path()`, không qua disk
  `local` vì disk này chuyển sang R2 khi `UPLOAD_STORAGE=r2`), giải nén vào `syllabus-imports/<uuid>/`; session lưu token → đường dẫn, user id, quyền tài liệu,
  thời điểm. Chỉ người upload xác nhận được. Xác nhận xong xoá file tạm; job hằng ngày trong `routes/console.php` xoá file
  tạm quá 1 ngày.

### 3.4. Quyền

- Trang import, kiểm tra, xác nhận, tải mẫu: `syllabus.manage` (giống tạo giáo trình tay).
- Zip có tài liệu: thêm `syllabus.upload`; thiếu → lỗi "Bạn không có quyền upload tài liệu — bỏ thư mục tai-lieu hoặc
  nhờ người có quyền".
- Không thêm quyền mới → không cần migration cấp quyền, catalog v2 không đổi.

### 3.5. Cấu trúc code

| Thành phần | Việc |
|---|---|
| `App\Http\Controllers\SyllabusImportController` | `index` (trang, xem trước theo token), `template`, `preview`, `confirm` — validate request, gọi service |
| `App\Services\Syllabus\Import\SyllabusPackageReader` | Mở zip / xlsx an toàn (giới hạn 3.3), đọc 3 sheet + danh sách file; không ghi DB |
| `App\Services\Syllabus\Import\SyllabusPackageValidator` | Áp quy tắc 3.1 → cây Chặng → Unit → Buổi + tài liệu, lỗi, cảnh báo (DTO readonly) |
| `App\Services\Syllabus\Import\SyllabusImporter` | Ghi DB trong transaction, lưu tài liệu, rollback + xoá file khi lỗi |
| `App\Exports\SyllabusImportTemplateExport` | `syllabus.xlsx` 4 sheet có dữ liệu ví dụ (`WithMultipleSheets`), đóng gói zip mẫu |
| `resources/js/Pages/Syllabus/Import.vue` | Trang 3 bước; nút trên `Builder.vue`; bỏ file mẫu PDF ở `Documents.vue` |
| `routes/web.php`, `routes/console.php` | 4 route trong nhóm `syllabus` (+ `ziggy:generate`), job dọn file tạm |

Không đổi schema → không có migration.

## 4. Kiểm thử

- Unit (`SyllabusPackageReader` / `SyllabusPackageValidator`, zip / xlsx dựng trong test): gói hợp lệ; từng lỗi ở 3.1;
  zip slip; quá số entry / quá dung lượng giải nén; zip mã hoá; file hệ thống được bỏ qua; file giả đuôi.
- Feature:
  - Tải mẫu → zip có `syllabus.xlsx` 4 sheet; import lại chính file mẫu → hợp lệ.
  - Kiểm tra không ghi DB.
  - Xác nhận tạo đúng chặng / unit / buổi; tài liệu gắn đúng chặng (và tài liệu chung), đúng quyền; không có 2 Chặng 1.
  - Trùng mã; thiếu `syllabus.manage` → 403; có tài liệu mà thiếu `syllabus.upload`; token của người khác; token hết hạn.
  - Lỗi giữa chừng → không còn dữ liệu / file; job dọn file tạm.
  - Trang Tài liệu không còn nút file mẫu.
- Test bảo vệ: `AllRoutesSmokeTest`, `RbacNoHardcodedRolesTest`, `BranchIsolationRouteSweepTest`,
  `NavigationPermissionTest`, các test module giáo trình (`Phase2SyllabusTest`, `Phase2MockupSyllabusTest`,
  `SyllabusStageProgressionTest`). Chỉ chạy các file liên quan, không chạy full suite.

## 5. Rủi ro

- **Zip độc / file lớn**: giới hạn số entry, dung lượng giải nén, đường dẫn, kiểm tra nội dung từng file (3.3).
- **File tạm bỏ dở chiếm đĩa**: xoá khi xác nhận + job dọn hằng ngày.
- **Excel người dùng sửa lệch mẫu** (đổi tên cột, gộp ô): đọc theo tên tiêu đề, báo lỗi rõ cột thiếu.
- **v2 parity**: v2 mirror `SyllabusController`; tính năng nằm ở controller mới nên workflow `v2-parity` có thể cảnh
  báo (không chặn). Port sang v2 ngoài phạm vi.

## 6. Sprint

| Sprint | Nội dung | Trạng thái |
|---|---|---|
| 1 — File mẫu + kiểm tra | Template zip, `SyllabusPackageReader`, `SyllabusPackageValidator`, trang Import bước ① ② (chưa ghi DB), route, test | **Xong** |
| 2 — Import + dọn dẹp | Bước xác nhận (`SyllabusImporter`), lưu tài liệu, job dọn file tạm, bỏ file mẫu PDF ở trang Tài liệu, test | **Xong** |

## Import từng chặng (form Sheet 1) — Kho syllabus, 23/10/2026

Thiết kế: `docs/kho-syllabus.md` §3.3. Trang chi tiết giáo trình → tab Chặng & buổi: **Thêm chặng từ file** (chặng mới cuối
giáo trình) hoặc **Import từ file** trên từng chặng (cập nhật chặng đó); **File mẫu chặng** tải form Sheet 1. Tải file chặng
vào màn **Import giáo trình mới** (gói 3 sheet) → báo một lỗi chỉ sang đúng nút này thay cho "Thiếu sheet…".

- File `.xlsx` (tối đa 20 MB) theo form "Syllabus mẫu" của Học thuật (`docs/Syllabus_Quy_trinh_xu_ly.xlsx`, sheet 1). Sheet
  được nhận diện bằng dòng tiêu đề A = "Chặng", B bắt đầu bằng "Buổi"; cột nhận theo chữ tiêu đề (thứ tự cột tùy ý, cột lạ bỏ
  qua); dữ liệu từ ngay sau dòng tiêu đề, hoặc sau dòng tiêu đề phụ (Hoạt động gợi ý 1, Bài tập giấy…) nếu có. Ô D3 Giáo trình
  (lệch → cảnh báo), D4 Chặng (tên chặng), D6 Phiên bản.
- Cột: Loại buổi (Học / Ôn tập / Grammar Basic / Mini test / Big test), UNIT ("Unit 1: Tên" ở buổi đầu unit, ô trống = unit
  dòng trên; chữ khác như "GRAMMAR BASIC 1 – TOPIC 1", "ÔN TẬP CHẶNG", "BIG TEST" là tiêu đề của riêng buổi đó, buổi vẫn thuộc
  unit đang mở), Objectives của UNIT, Content (dòng đầu = tên buổi), Quy trình, Hoạt động gợi ý 1/2, Bài tập giấy / Quizizz /
  Video, Link PPT / PPT GVNN / Quizizz / Mini test – Kiểm tra từ (URL, ô có hyperlink lấy URL; ô giữ chỗ "link PPT"… coi như
  trống), Sản phẩm đầu ra, Mốc thông báo (Order in ấn / mini test / Big Test), Ghi chú.
  "Chỉnh sửa thủ công" bỏ qua.
- Dòng ví dụ (cột A cùng màu nền ô chú thích "Dòng ví dụ") và dòng chỉ có số buổi bị bỏ qua.
- Cập nhật chặng: buổi thứ i của file ghi đè buổi thứ i của chặng (giữ id → đề xuất sửa, tài liệu buổi vẫn gắn), thừa → tạo,
  thiếu → xóa mềm; unit khớp theo số unit (unit của chặng khác → lỗi). Sau đó đánh lại số buổi liên tục cả giáo trình.
- Xem trước liệt kê lỗi theo dòng, cảnh báo (số lớp đang học chặng), buổi thêm / sửa / xóa; còn lỗi thì không xác nhận được.
  Lịch sử ghi 1 dòng "Import chặng từ file".
- Code: `app/Services/Syllabus/StageImport/*`, `SyllabusStageImportController`, `Pages/Syllabus/StageImport.vue`.
