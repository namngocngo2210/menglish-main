# Đối chiếu: Kho file (tải lên / tải về file)

Laravel: `SafeUploadService` (nhận file theo nội dung, chặn HTML / SVG / script), `MediaManagerService::safeExtension`,
disk `local` (riêng tư, `storage/app/private`) và `public` (`storage/app/public`, ai có link cũng mở được qua `/storage/...`),
`UserController::storeContractFile` / `downloadContract`, `UserRequest` (rule `contract_file`), `PlacementTestController::
uploadMedia` / `uploadPdf` / `parseAnswers`, `PlacementPdfAnswerSheet`, `Components/PlacementTests/PdfSheetEditor.vue`,
`Pages/PlacementTests/questionEditor.js` (`useMediaUpload`).

v2:

- API: `api/internal/platform/filestore` (driver local / S3, nhận loại file, đọc multipart) và `api/internal/files` (bảng
  `core.files`, migration `00140_core_files.sql`, lưu / gắn / xóa mềm / dọn, tải về có kiểm quyền).
- Giao diện: `web/src/components/FileUpload.tsx`, `web/src/lib/files.ts`, `upload()` trong `web/src/api.ts`.
- Cấu hình: biến `FILES_*` (xem mục *Kho file* trong `v2/README.md`).

## Thay đổi so với Laravel

- **Không còn kho công khai.** Laravel để file nghe / ảnh / đề PDF của đề test trên disk `public`: ai biết đường dẫn
  `/storage/placement_tests/...` là mở được, không hết hạn. v2 để mọi file trong kho riêng tư:
  - Người dùng mở file qua `/api/v1/files/<id>`. API kiểm quyền theo phân hệ sở hữu file rồi chuyển (302) sang link ký sẵn
    hạn 5 phút (`FILES_URL_TTL`).
  - Thí sinh không đăng nhập nhận link ký sẵn khi mở link làm bài. Link có hạn bằng thời gian làm bài + 60 phút. Chỉ ký file
    của đề test (`placement_media`, `placement_pdf`): dán URL file khác (vd. hợp đồng) vào câu hỏi cũng không lộ ra.
- **Thông tin file nằm trong DB** (`core.files`): phân hệ / bản ghi sở hữu, chi nhánh, tên gốc, đuôi, loại, dung lượng,
  SHA-256, người tải, thời điểm tải / xóa. Laravel chỉ lưu đường dẫn trong cột của bảng nghiệp vụ (`users.contract_file_path`,
  `placement_tests.pdf_path`, URL trong JSON câu hỏi).
- **Thay / bỏ file là xóa mềm.** Laravel xóa file cũ ngay khi thay hợp đồng. v2 giữ 30 ngày (khôi phục được khi lỡ tay),
  sau đó API tự dọn hẳn dòng + nội dung (chạy nền 6 giờ một lần).
- **Nhật ký vận hành** ghi mỗi lần tải lên (`file.upload`) / xóa (`file.delete`) kèm tên, dung lượng, SHA-256 của file.
  Laravel chỉ ghi khi thay file hợp đồng (qua cập nhật tài khoản).
- **Nhận loại file theo nội dung**, như `SafeUploadService`: magic bytes; Word / Excel / PowerPoint đọc cấu trúc zip
  (`[Content_Types].xml`) hoặc OLE; txt phải là UTF-8 và không giống HTML / SVG / PHP / XML. Content-Type khi tải về lấy theo
  loại nhận ra, không theo đuôi / Content-Type trình duyệt gửi.
- **Header khi phục vụ file** (driver local): `X-Content-Type-Options: nosniff`, CSP `sandbox` (ảnh / âm thanh / video),
  `frame-ancestors 'self'` (PDF xem nhúng trong trang), tài liệu văn phòng / txt luôn tải về (`attachment`).

## Bảng đối chiếu

| Laravel | v2 (API + màn) | Trạng thái | Ghi chú |
|---|---|---|---|
| `SafeUploadService::store` (nhận theo nội dung, danh sách IMAGES / DOCUMENTS / AUDIO / VIDEO, thông báo "Định dạng file không được phép. Chấp nhận: …") | `filestore.Detect`, `filestore.Policy`, `Upload.Check`; cùng danh sách và thông báo | Đã làm | Thêm thông báo "File tải lên bị rỗng." |
| Disk `local` / `public` | `filestore.Store`: driver `local` (mặc định, thư mục `FILES_DIR`) và `s3` (R2 / S3 / MinIO, bucket riêng tư, URL ký) | Khác | Không có kho công khai (xem trên). Driver S3 mới test với máy chủ S3 giả trong test, chưa chạy với MinIO / R2 thật. |
| `users.contract.download` — `downloadContract` (chính chủ, hoặc `user.view` + quản lý được tài khoản; tên `hop-dong-<slug>.<đuôi>`) | `GET /api/v1/files/<id>` → `iam.ContractDownload`; link "Tải về" ở mục Hồ sơ nhân sự | Đã làm | Như Laravel. Không có file / file đã thay → 404 "Không tìm thấy file hoặc file đã bị xóa." |
| `storeContractFile` qua `users.update` (rule `nullable\|file\|max:10240\|mimes:pdf,doc,docx,jpg,jpeg,png,webp`) | `POST /api/v1/users/{id}/contract` (quyền `user.update`, cùng phạm vi quản lý tài khoản), nút "Tải file hợp đồng" / "Thay file hợp đồng" | Khác | Tải riêng, không đi cùng form sửa tài khoản. Thông báo: "Dung lượng tập tin file hợp đồng không được vượt quá 10240 kB." (Laravel: "… contract file …"), sai loại dùng thông báo của `SafeUploadService` (Laravel: câu tiếng Anh mặc định của rule `mimes`). Chỉ tài khoản nhân viên. |
| `has_contract_file` trên `users.show` | `contract_file` (tên, dung lượng, ngày tải, `url`) trong `GET /users/{id}/profile` | Đã làm | |
| `placement-tests.media.store` — `uploadMedia` (audio 20 MB / ảnh 5 MB, "File quá lớn (âm thanh tối đa 20 MB, ảnh tối đa 5 MB).", quyền tạo hoặc sửa đề) | `POST /api/v1/placement-tests/media` (`kind=audio\|image`); nút "Tải file nghe" / "Tải ảnh lên" ở form soạn câu, "Tải file nghe (.mp3)" ở đề PDF | Đã làm | Trả `{id, url, path, name}`; `url` là `/api/v1/files/<id>` (Laravel: `/storage/...`). |
| `placement-tests.pdf.store` — `uploadPdf` (PDF 20 MB, đọc chữ, dựng phiếu, `answer_key_page`; `answers_only` không giữ file) | `POST /api/v1/placement-tests/pdf`; cách "Tải đề PDF" ở màn tạo / sửa đề (`PdfSheetEditor.tsx`) | Đã làm | Đọc chữ PDF bằng `ledongthuc/pdf` (Laravel: `smalot/pdfparser`): tối đa 60 trang, 20 giây; PDF scan / hỏng → phiếu trống + cảnh báo như Laravel. `answers_only` không ghi file vào kho. |
| `placement-tests.pdf.answers` — `parseAnswers` (`text` bắt buộc, tối đa 20000 ký tự) | `POST /api/v1/placement-tests/pdf/answers` | Đã làm | "Vui lòng nhập đáp án." / "Đáp án không được vượt quá 20000 ký tự." |
| `PlacementPdfAnswerSheet` (`build`, `parseKey`, `keyText`, `answerKeyPage`, `blank`) | `placement/pdfsheet.go` | Đã làm | Chép từng regex sang RE2 (lookbehind / lookahead viết thành hàm quét); test lấy từ `PlacementPdfTestTest.php`. |
| Lưu đề PDF: file còn trang đáp án thì không cho lưu (đọc lại file) | `CreateTest` / `UpdateTest` kiểm `pdf_path` | Đã làm | Chỉ nhận file tải lên qua `/placement-tests/pdf` (loại `placement_pdf`, chưa xóa); file Laravel cũ đang gắn với đề giữ nguyên. Lưu xong file được gắn với đề (`subject_id`). |
| `PdfSheetEditor.vue` (đổi / bỏ file, cảnh báo, dán đáp án, đọc file đáp án, phiếu trống N câu, kỹ năng / dạng câu / số phương án / điểm từng câu) | `web/src/features/placement/PdfSheetEditor.tsx`, logic `fillAnswers` / `blankSheet` … trong `logic.ts` | Đã làm | Xem PDF bằng `<iframe>` của trình duyệt (Laravel: `PdfViewer.vue` dùng pdf.js) để không thêm thư viện. |
| `useMediaUpload` (`questionEditor.js`) | `<FileUpload>` dùng chung, kiểm đuôi + dung lượng trước khi gửi | Đã làm | Ảnh của từng phương án chưa có ô tải (form v2 chưa có ô ảnh phương án). |
| Ảnh / file khác của Laravel: minh chứng công việc (`task_proofs`), ảnh bảng lớp (`class_reports`), file nghe bài tập (`homework_audio`), CV ứng viên, minh chứng phạt, ảnh chấm công | — | Chưa làm | Thuộc các phân hệ chưa chuyển; dùng `files.Service.Save` + `RegisterReader` khi chuyển (mẫu trong `internal/files/service.go`). |

## Nhập file cũ từ Laravel (chưa làm, ghi lại cách ánh xạ)

Chép file từ `storage/` của Laravel không nằm trong phần này. Khi làm `import-legacy` cho file:

| Laravel | v2 `core.files` | Gắn vào |
|---|---|---|
| `users.contract_file_path` (disk `local`, `contracts/<user_id>/<tên>`) | `owner_module='iam'`, `subject_type='staff_contract'`, `subject_id` = id người dùng v2 (bảng ánh xạ của `import-legacy`), `branch_id` = chi nhánh chính | Hợp đồng hiện tại = file chưa xóa mới nhất của người đó |
| `placement_tests.pdf_path` (disk `public`, `placement_tests/pdf/<Y>/<m>/<tên>.pdf`) | `owner_module='placement'`, `subject_type='placement_pdf'`, `subject_id` = id đề v2 | Đổi `placement.tests.pdf_path` thành `/api/v1/files/<id>` |
| URL `/storage/placement_tests/<Y>/<m>/...` trong `placement_tests.questions` (`audio_url`, `image_url`, ảnh phương án) và `audio_url` của đề | `subject_type='placement_media'`, `subject_id` = id đề | Thay URL trong JSON câu hỏi bằng `/api/v1/files/<id>` |

Mỗi file: đọc nội dung, chạy `filestore.Detect` (file không nhận ra thì bỏ qua và ghi cảnh báo), tính SHA-256, ghi vào
store bằng `filestore.NewKey`, ghi `legacy_path` = đường dẫn cũ (chạy lại nhiều lần thì bỏ qua file đã có cùng
`legacy_path`), `uploaded_by` = NULL. Trong lúc chưa nhập, đề PDF cũ vẫn mở bằng `/storage/<pdf_path>` (`staffPdfURL`),
cần phục vụ thư mục file cũ nếu chạy v2 tách khỏi Laravel.

## Cần khi gộp (liên module)

- **Học phí (`internal/tuition`)**: ảnh minh chứng hoàn tiền / phiếu thu dùng `files.Service.Save(ctx, tx, up, …)` trong
  transaction của phiếu và `RegisterReader("<subject_type>", …)` để kiểm quyền tải. Cần truyền `*files.Service` vào
  `tuition` trong `cmd/menglish/main.go` (biến `fileSvc`). `TODO(gộp)`.
- **Email (mailer)**: email đính kèm / link file nên dùng `files.Service.SignedURL` với hạn ngắn, không gửi `/api/v1/files/<id>`
  (người nhận chưa đăng nhập).
- **Driver S3 trên beta / production**: thêm host bucket vào `img-src`, `media-src`, `frame-src` của CSP trong
  `deploy/Caddyfile.web`; tạo bucket riêng tư và khóa API chỉ quyền đọc / ghi bucket đó.
- **Sao lưu**: driver local thì sao lưu volume `files` cùng `backup-db` (lệnh mẫu trong `v2/README.md`).
