# Import syllabus từ file mẫu — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Học thuật tải file mẫu zip ở màn Soạn syllabus, điền syllabus + tài liệu theo chặng, upload để kiểm tra (xem trước) rồi xác nhận tạo giáo trình mới; bỏ file mẫu PDF ở trang Tài liệu.

**Architecture:** Controller mỏng `SyllabusImportController` gọi các service trong `app/Services/Syllabus/Import`: `SyllabusImportStore` (file tạm + phiên theo token), `SyllabusPackageReader` (mở zip/xlsx an toàn → `SyllabusPackage`), `SyllabusPackageValidator` (quy tắc → `SyllabusImportPlan`), `SyllabusImporter` (ghi DB trong transaction + lưu tài liệu). Luồng upload → xem trước → xác nhận giống `ClassRosterImportController`; trang xem trước đọc lại file tạm theo token (không nhét cả gói vào session).

**Tech Stack:** Laravel 13 / PHP 8.4, maatwebsite/excel + PhpSpreadsheet, `ZipArchive`, Inertia 3 + Vue 3, PHPUnit.

**Spec:** `docs/import-syllabus.md`

## Global Constraints

- Code, comment, commit: comment/UI tiếng Việt như repo; commit message tiếng Anh, một dòng.
- Upload ≤ 100 MB (`max:102400`), chỉ `.zip` / `.xlsx`.
- Zip: ≤ 500 entry; tổng dung lượng giải nén ≤ 200 MB (`200 * 1024 * 1024`); mỗi tài liệu ≤ `SyllabusDocument::MAX_KB`; định dạng `SyllabusDocument::ALLOWED_EXTENSIONS`, kiểm tra theo nội dung bằng `SafeUploadService::extensionFor()`.
- Sheet: `Giáo trình`, `Chặng`, `Buổi` (+ `Hướng dẫn` không import). Tiêu đề cột đúng như bảng ở spec 3.1.
- Giới hạn trường (khớp `SyllabusController::*Rules`): mã ≤ 50, tên/tên chặng/tên unit/tên buổi/tên Big Test ≤ 255, phiên bản ≤ 20 (trống → `v1`), mô tả / ghi chú Big Test ≤ 5000, link mục lục là URL ≤ 500, unit số 1…500, buổi số 1…1000, tối đa 1000 buổi.
- Quyền: mọi route import cần `syllabus.manage`; gói có tài liệu cần thêm `syllabus.upload`. Không thêm quyền mới, không migration.
- Phiên kiểm tra hết hạn sau 2 giờ, gắn với người upload; file tạm quá 1 ngày bị job hằng ngày xoá.
- File tạm ghi bằng `storage_path('app/private/syllabus-imports')` (không dùng `Storage::disk('local')` — disk này là R2 khi `UPLOAD_STORAGE=r2`). Tài liệu chính thức lưu qua `SafeUploadService::store(..., SyllabusDocument::DIRECTORY, SyllabusDocument::ALLOWED_EXTENSIONS, 'file', SyllabusDocument::DISK)`.
- Mã trùng kiểm tra bằng `SyllabusCurriculum::withTrashed()` (unique index tính cả bản đã xoá).
- Cả tính năng nằm trong **một PR** duy nhất (nhánh `feat/syllabus-import`).
- Sau khi sửa `routes/web.php`: chạy `php artisan ziggy:generate resources/js/ziggy.js`.

**Chạy lệnh (không có PHP trên host).** Worktree `../wt-syllabus-import`: `cp -al ../wt-session-syllabus-link/vendor vendor` và `node_modules` (chỉ khi `composer.lock` / `package-lock.json` giống hệt), `cp ../wt-session-syllabus-link/.env .env`. Chạy test:

```bash
docker run --rm -u $(id -u):$(id -g) -e HOME=/tmp -e CACHE_STORE=database -v $PWD:$PWD -w $PWD \
  --entrypoint php menglish-test:local -d memory_limit=1G vendor/bin/phpunit tests/Feature/XTest.php
```

Gọi tắt bên dưới là `phpunit tests/Feature/XTest.php`. Artisan: cùng lệnh, thay `vendor/bin/phpunit …` bằng `artisan …`. Chỉ chạy các file test được nêu, không chạy full suite.

## Review Focus

1. **Zip nén cả thư mục** (`mau-import-syllabus/syllabus.xlsx`, `mau-import-syllabus/tai-lieu/chang-1/x.pdf`) — người dùng Windows/Mac hay nén kiểu này; phải đọc như zip phẳng. Test: Task 1 `test_single_wrapping_folder_is_stripped`.
2. **Ô số trong Excel** — "Buổi số" có thể là `1`, `1.0`, `" 1 "`, `"01"` → đều là 1; `"1.5"`, `"abc"` → lỗi đúng dòng. Test: Task 2 `test_integer_cells_accept_excel_number_forms`.
3. **Tiêu đề cột lệch hoa thường / thừa khoảng trắng** (`"buổi số "`) → vẫn nhận. Test: Task 1 `test_headers_match_case_and_space_insensitively`.
4. **Tên file trong zip không phải UTF-8** (zip tạo bằng Explorer cũ, mã CP437/Windows-1258) → không lỗi 500, tiêu đề tài liệu là UTF-8 hợp lệ, có cảnh báo đổi tên. Test: Task 1 `test_non_utf8_entry_names_are_scrubbed` + Task 2 cảnh báo.
5. **Bấm Xác nhận hai lần / quay lại rồi bấm lại** → lần sau báo phiên hết hạn, chỉ có 1 giáo trình. Test: Task 7 `test_confirm_twice_creates_one_curriculum`.

---

## Sprint 1 — File mẫu + kiểm tra

### Task 1: `SyllabusPackageReader` + helper dựng gói trong test

**Files:**
- Create: `app/Services/Syllabus/Import/SyllabusPackage.php`, `app/Services/Syllabus/Import/PackageFile.php`, `app/Services/Syllabus/Import/SyllabusPackageException.php`, `app/Services/Syllabus/Import/SyllabusPackageReader.php`
- Create: `tests/Support/BuildsSyllabusPackages.php`
- Test: `tests/Feature/SyllabusPackageReaderTest.php`

**Interfaces:**
- Produces:
  - `final readonly class PackageFile(string $path /* trong zip, đã bỏ thư mục bọc, vd 'tai-lieu/chang-1/Slide.pdf' */, string $localPath, int $size, bool $renamed /* tên gốc không phải UTF-8, đã chuyển mã */)`
  - `final readonly class SyllabusPackage(array $curriculumRows, array $stageRows, array $lessonRows, array $files /* list<PackageFile> */, array $ignored /* list<string> đường dẫn bị bỏ */, array $missing /* list<string> lỗi thiếu sheet/cột */, bool $isZip)` — mỗi `*Rows` là `array<int $excelRow, array<string $key, ?string>>` (giá trị đã trim, ô trống = null).
  - `final class SyllabusPackageException extends \RuntimeException` — lỗi chặn cả file (message tiếng Việt, hiển thị thẳng).
  - `SyllabusPackageReader` với hằng `MAX_ENTRIES = 500`, `MAX_UNCOMPRESSED_BYTES = 209715200`, `SHEETS = ['curriculum' => 'Giáo trình', 'stages' => 'Chặng', 'lessons' => 'Buổi']`, `CURRICULUM_COLUMNS = ['code' => 'Mã', 'title' => 'Tên', 'version' => 'Phiên bản', 'description' => 'Mô tả']`, `STAGE_COLUMNS = ['position' => 'Thứ tự', 'name' => 'Tên chặng', 'description' => 'Mô tả', 'big_test_title' => 'Tên Big Test', 'big_test_note' => 'Ghi chú Big Test', 'overview_link' => 'Link mục lục']`, `LESSON_COLUMNS = ['stage' => 'Chặng', 'unit_number' => 'Unit số', 'unit_title' => 'Tên unit', 'session_no' => 'Buổi số', 'title' => 'Tên buổi', 'objectives' => 'Mục tiêu', 'content' => 'Hoạt động', 'vocabulary_focus' => 'Từ vựng', 'grammar_focus' => 'Ngữ pháp', 'homework_guide' => 'BTVN']`, `REQUIRED = ['curriculum' => ['code', 'title'], 'stages' => ['position', 'name'], 'lessons' => ['stage', 'unit_number', 'unit_title', 'session_no', 'title']]`.
  - `public function read(string $path, string $workDir): SyllabusPackage` — `$path` đuôi `.xlsx` hoặc `.zip`; zip được giải nén vào `$workDir` (bỏ qua giải nén nếu `$workDir` đã có file — trang xem trước đọc lại nhiều lần).
  - Trait `Tests\Support\BuildsSyllabusPackages`: `validSheets(): array` (gói hợp lệ 2 chặng, 2 unit, 4 buổi), `makeXlsx(array $sheets /* title => list<list<scalar|null>> gồm dòng tiêu đề */): string` (PhpSpreadsheet), `makeZip(array $entries /* tên entry => nội dung string */): string`, `pdfBytes(): string` (PDF tối thiểu hợp lệ). File tạo trong `storage_path('framework/testing/syllabus-packages')`, xoá ở `tearDown`.

- [ ] **Step 1: Viết test thất bại** — `SyllabusPackageReaderTest` (extends `Tests\TestCase`, `use BuildsSyllabusPackages`):
  - `test_reads_xlsx_rows_by_header`: `read(makeXlsx(validSheets()))` → `curriculumRows[2]['code'] === 'KIDS-Q4'`, `count(lessonRows) === 4`, `lessonRows[2]['session_no'] === '1'`, `files === []`, `isZip === false`, `missing === []`.
  - `test_headers_match_case_and_space_insensitively`: đổi tiêu đề `'Buổi số'` thành `' buổi SỐ '` → vẫn có khoá `session_no`.
  - `test_missing_sheet_or_required_column_is_reported`: bỏ sheet `Chặng` → `missing` chứa `'Thiếu sheet "Chặng".'`; bỏ cột `Tên buổi` → `missing` chứa `'Sheet Buổi: thiếu cột "Tên buổi".'`.
  - `test_reads_zip_with_documents_and_ignores_junk`: zip gồm `syllabus.xlsx`, `tai-lieu/chang-1/Slide.pdf`, `tai-lieu/Chung.pdf`, `__MACOSX/._Slide.pdf`, `tai-lieu/.DS_Store`, `tai-lieu/chang-1/` (thư mục) → `files` có đúng 2 `path` trên, `ignored` có 2 file rác, `isZip === true`, file đã giải nén tồn tại ở `localPath`.
  - `test_single_wrapping_folder_is_stripped`: như trên nhưng mọi entry nằm dưới `mau-import-syllabus/` → `path` không còn tiền tố.
  - `test_non_utf8_entry_names_are_scrubbed`: entry `"tai-lieu/chang-1/B\xE0i.pdf"` → `mb_check_encoding($files[0]->path, 'UTF-8') === true`, `$files[0]->renamed === true`.
  - `test_rejects_unsafe_or_oversized_zip` (data provider, mỗi case `expectException(SyllabusPackageException::class)` + `expectExceptionMessage`): entry `../evil.pdf` → `'đường dẫn không hợp lệ'`; entry `/etc/x.pdf` → như trên; 501 entry → `'quá 500 file'`; zip không có `.xlsx` ở gốc → `'Không tìm thấy file syllabus.xlsx'`; 2 file `.xlsx` ở gốc → `'chỉ được có 1 file .xlsx'`; zip mã hoá (`ZipArchive::setEncryptionName`) → `'có mật khẩu'`; file không phải zip → `'Không đọc được file'`.
  - `test_rejects_zip_bomb_by_uncompressed_size`: zip 1 entry nén từ chuỗi `str_repeat('0', 210 * 1024 * 1024)` (nén còn vài trăm KB) → exception `'quá 200 MB sau giải nén'`.

- [ ] **Step 2: Chạy để thấy thất bại** — `phpunit tests/Feature/SyllabusPackageReaderTest.php` → FAIL (class not found).

- [ ] **Step 3: Implement** các class ở Interfaces. Cách làm:
  - xlsx: `PhpOffice\PhpSpreadsheet\IOFactory::createReader('Xlsx')->setReadDataOnly(true)->load()`; tìm sheet theo `SHEETS` (so `mb_strtolower(trim())`); dòng 1 là tiêu đề, map tiêu đề → khoá theo `*_COLUMNS` (so `mb_strtolower(preg_replace('/\s+/u', ' ', trim()))`); giá trị ô dùng `getFormattedValue()` rồi trim, `''` → null; bỏ dòng mà mọi ô null; khoá mảng = số dòng Excel.
  - zip: duyệt `statIndex()` trước khi giải nén — kiểm số entry, `encryption_method !== ZipArchive::EM_NONE`, tên chứa `\`, `:` hoặc đoạn `..`, bắt đầu `/`, tổng `size`; tên không phải UTF-8 → `mb_convert_encoding($name, 'UTF-8', 'CP437')`; bỏ entry thư mục; rác = đoạn đường dẫn `__MACOSX`, tên `.DS_Store` / `Thumbs.db` / bắt đầu `._`. Nếu mọi entry còn lại chung một thư mục gốc và gốc không có `.xlsx` → bỏ tiền tố đó. Giải nén từng entry hợp lệ bằng `getStream()` ghi ra `$workDir/<path đã chuẩn hoá>` (không dùng `extractTo` để tên đã scrub khớp đĩa). File ngoài `tai-lieu/` và khác xlsx gốc → `ignored`.

- [ ] **Step 4: Chạy test** — `phpunit tests/Feature/SyllabusPackageReaderTest.php` → PASS.

- [ ] **Step 5: Commit** — `git add app/Services/Syllabus/Import tests/Support/BuildsSyllabusPackages.php tests/Feature/SyllabusPackageReaderTest.php && git commit -m "feat: read syllabus import packages safely"`

### Task 2: `SyllabusPackageValidator` + `SyllabusImportPlan`

**Files:**
- Create: `app/Services/Syllabus/Import/SyllabusImportPlan.php`, `app/Services/Syllabus/Import/SyllabusPackageValidator.php`
- Test: `tests/Feature/SyllabusPackageValidatorTest.php`

**Interfaces:**
- Consumes: `SyllabusPackage`, `PackageFile` (Task 1).
- Produces:
  - `final readonly class SyllabusImportPlan(?array $curriculum /* {code,title,version,description} */, array $stages, array $sharedDocuments, array $errors /* list<string> */, array $warnings /* list<string> */)` với `ok(): bool` (= `$errors === []`) và `summary(): array{stages:int, units:int, lessons:int, documents:int}`.
    - `$stages`: `list<array{position:int, name:string, description:?string, big_test_title:?string, big_test_note:?string, overview_link:?string, units: list<array{unit_number:int, title:string, lessons: list<array{session_no:int, title:string, objectives:?string, content:?string, vocabulary_focus:?string, grammar_focus:?string, homework_guide:?string}>}>, documents: list<array{title:string, original_name:string, extension:string, size:int, local_path:string}>}>` — sắp theo `position`, unit theo `unit_number`, buổi theo `session_no`.
    - `$sharedDocuments`: cùng dạng `documents` (file ở gốc `tai-lieu/`).
  - `public function validate(SyllabusPackage $package, bool $canUpload): SyllabusImportPlan`.

- [ ] **Step 1: Viết test thất bại** — `SyllabusPackageValidatorTest` (`RefreshDatabase`, `BuildsSyllabusPackages`; dựng `SyllabusPackage` bằng Reader của Task 1):
  - `test_valid_package_builds_tree`: `ok()`; `summary() === ['stages' => 2, 'units' => 2, 'lessons' => 4, 'documents' => 0]`; `curriculum['version'] === 'v1'` khi bỏ trống.
  - `test_reports_every_rule_violation` (một gói sai nhiều chỗ, assert `errors` chứa đúng các chuỗi): `'Sheet Giáo trình: chỉ điền 1 dòng.'`; `'Sheet Giáo trình, dòng 2: thiếu Tên.'`; `'Sheet Chặng: thứ tự phải liên tục từ 1 (thiếu 2).'`; `'Sheet Buổi, dòng 5: thiếu Tên buổi.'`; `'Sheet Buổi: buổi số 3 bị trùng (dòng 4, 6).'`; `'Sheet Buổi: thiếu buổi số 7.'`; `'Sheet Buổi, dòng 8: Chặng 4 không có trong sheet Chặng.'`; `'Sheet Buổi: Unit 2 nằm ở nhiều chặng (1, 2).'`; `'Sheet Buổi: Unit 2 có nhiều tên khác nhau.'`; `'Chặng 2 chưa có buổi nào.'`; `'Sheet Chặng, dòng 3: Link mục lục không phải URL hợp lệ.'`; `'Sheet Giáo trình, dòng 2: Mã tối đa 50 ký tự.'`.
  - `test_integer_cells_accept_excel_number_forms`: `session_no` = `'1.0'`, `' 2 '`, `'03'` → hợp lệ; `'1.5'` → `'Sheet Buổi, dòng N: Buổi số phải là số nguyên.'`; `'abc'` → như trên.
  - `test_existing_code_is_rejected_including_trashed`: tạo `SyllabusCurriculum` mã `KIDS-Q4` rồi `delete()` → lỗi `'Mã KIDS-Q4 đã tồn tại — đổi mã khác.'`.
  - `test_more_than_1000_lessons_is_rejected` → `'Sheet Buổi: tối đa 1000 buổi.'`.
  - `test_documents_are_attached_by_stage_folder`: zip có `tai-lieu/chang-1/Slide Unit 1-3.pdf`, `tai-lieu/Chung.pdf` → `stages[0]['documents'][0]['title'] === 'Slide Unit 1-3'`, `sharedDocuments[0]['title'] === 'Chung'`; cảnh báo `'Chặng 2 chưa có tài liệu.'`.
  - `test_document_errors`: `tai-lieu/chang-4/x.pdf` → `'tai-lieu/chang-4/: không có Chặng 4.'`; `tai-lieu/chang-1/abc/x.pdf` → `'tai-lieu/chang-1/abc/x.pdf: chỉ đặt file trực tiếp trong thư mục chặng.'`; `tai-lieu/chang-1/virus.pdf` chứa `"MZ…"` → `'tai-lieu/chang-1/virus.pdf: định dạng không được phép.'`; `tai-lieu/khac/x.pdf` → `'tai-lieu/khac/: tên thư mục phải là chang-<thứ tự>.'`.
  - `test_documents_need_upload_permission`: `validate($pkg, canUpload: false)` có tài liệu → `'Bạn không có quyền upload tài liệu — bỏ thư mục tai-lieu hoặc nhờ người có quyền.'`.
  - `test_ignored_and_renamed_files_are_warnings`: 2 file rác → cảnh báo `'Đã bỏ qua 2 file hệ thống / ngoài thư mục tai-lieu.'`; tên được scrub từ Task 1 → cảnh báo `'<path>: tên file không đọc được — đổi tên ở trang Tài liệu sau khi import.'`.

- [ ] **Step 2: Chạy để thấy thất bại** — `phpunit tests/Feature/SyllabusPackageValidatorTest.php` → FAIL.

- [ ] **Step 3: Implement** `validate()`. `$package->missing` khác rỗng → trả plan `curriculum = null`, `errors = missing`. Số nguyên: `preg_match('/^\d+(\.0+)?$/', $v)` rồi `(int)`. URL: `filter_var(..., FILTER_VALIDATE_URL)`. Định dạng tài liệu: bọc `new UploadedFile($localPath, basename($path), null, null, true)` rồi `SafeUploadService::extensionFor(..., SyllabusDocument::ALLOWED_EXTENSIONS)`, bắt `ValidationException`; `extension` trong plan lấy từ kết quả này; size > `SyllabusDocument::MAX_KB * 1024` → `'<path>: quá 100 MB.'`. Cảnh báo "chưa có tài liệu" chỉ khi `$package->isZip`; cảnh báo đổi tên khi `PackageFile::$renamed`.

- [ ] **Step 4: Chạy test** — `phpunit tests/Feature/SyllabusPackageValidatorTest.php` → PASS.

- [ ] **Step 5: Commit** — `git commit -m "feat: validate syllabus import packages"`

### Task 3: File mẫu zip

**Files:**
- Create: `app/Exports/SyllabusImportTemplateExport.php`, `app/Services/Syllabus/Import/SyllabusImportTemplate.php`
- Test: `tests/Feature/SyllabusImportTemplateTest.php`

**Interfaces:**
- Consumes: hằng `SHEETS` / `*_COLUMNS` của `SyllabusPackageReader` (Task 1) làm tiêu đề — không lặp chuỗi.
- Produces: `SyllabusImportTemplateExport implements WithMultipleSheets` (4 sheet: `Hướng dẫn`, `Giáo trình`, `Chặng`, `Buổi`, theo kiểu `ClassRosterTemplateExport`); `SyllabusImportTemplate::build(): string` — đường dẫn file zip tạm gồm `syllabus.xlsx` (`Excel::raw(new SyllabusImportTemplateExport, \Maatwebsite\Excel\Excel::XLSX)`) + thư mục rỗng `tai-lieu/chang-1/`, `tai-lieu/chang-2/`.

- [ ] **Step 1: Viết test thất bại** — `test_template_zip_contains_workbook_and_stage_folders`: mở zip từ `build()` → có `syllabus.xlsx`, `tai-lieu/chang-1/`, `tai-lieu/chang-2/`; workbook có 4 sheet đúng tên. `test_template_passes_validation`: `validate(read(build()), true)->ok() === true` và `summary()['stages'] === 2`.
- [ ] **Step 2: Chạy để thấy thất bại** — `phpunit tests/Feature/SyllabusImportTemplateTest.php` → FAIL.
- [ ] **Step 3: Implement.** Dữ liệu ví dụ: giáo trình `MAU-01` / `Giáo trình mẫu` / `v1`; 2 chặng có Tên Big Test; 2 unit (Unit 1 ở chặng 1, Unit 2 ở chặng 2), 4 buổi đủ các cột. Sheet Hướng dẫn: các dòng mô tả quy tắc ở spec 3.1 (thứ tự liên tục, buổi số liên tục, thư mục `chang-<thứ tự>`, định dạng file, giới hạn 100 MB, mã chưa tồn tại).
- [ ] **Step 4: Chạy test** → PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: add syllabus import template"`

### Task 4: `SyllabusImportStore` + job dọn file tạm

**Files:**
- Create: `app/Services/Syllabus/Import/SyllabusImportStore.php`, `app/Console/Commands/PruneSyllabusImportsCommand.php`
- Modify: `routes/console.php` (thêm lịch, cạnh các `Schedule::command` khác)
- Test: `tests/Feature/SyllabusImportStoreTest.php`

**Interfaces:**
- Produces (`SyllabusImportStore`, đọc/ghi phiên bằng helper `session()` của request hiện tại):
  - `const TTL_MINUTES = 120`, `public static function root(): string` (= `storage_path('app/private/syllabus-imports')`)
  - `put(UploadedFile $file, User $user, array $visibility /* {visible_to_teachers:bool, visible_to_assistants:bool, downloadable:bool} */): string` — trả token uuid; lưu `<root>/<token>.<zip|xlsx>`; session `syllabus_import.<token>` = `{path, file_name, user_id, created_at (timestamp), visibility}`.
  - `get(string $token, User $user): ?array` — meta kèm `work_dir` (= `<root>/<token>`); null khi không có / khác user / quá `TTL_MINUTES` / file mất.
  - `forget(string $token): void` — xoá file, thư mục giải nén, khoá session.
  - `prune(\DateTimeInterface $before): int` — xoá file/thư mục trong `root()` có mtime < `$before`, trả số mục đã xoá.
- Command `syllabus:prune-imports` gọi `prune(now()->subDay())`; lịch `Schedule::command('syllabus:prune-imports')->dailyAt('03:10');` kèm comment tiếng Việt một dòng.

- [ ] **Step 1: Viết test thất bại** — `test_put_and_get_roundtrip`; `test_get_rejects_other_user`; `test_get_expires_after_two_hours` (`travel(121)->minutes()`); `test_forget_removes_files_and_session`; `test_prune_command_removes_only_old_entries` (`touch()` lùi mtime 2 ngày cho một file, file còn lại giữ nguyên; `artisan('syllabus:prune-imports')->assertSuccessful()`).
- [ ] **Step 2: Chạy để thấy thất bại** — `phpunit tests/Feature/SyllabusImportStoreTest.php` → FAIL.
- [ ] **Step 3: Implement** theo Interfaces (dùng `Illuminate\Support\Facades\File`).
- [ ] **Step 4: Chạy test** → PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: store syllabus import uploads temporarily"`

### Task 5: Trang Import (bước ① ②), route, nút trên Soạn syllabus

**Files:**
- Create: `app/Http/Controllers/SyllabusImportController.php`, `resources/js/Pages/Syllabus/Import.vue`
- Modify: `routes/web.php` (trong nhóm `Route::prefix('syllabus')->name('syllabus.')`, cạnh route `builder`), `resources/js/ziggy.js` (generate), `resources/js/Pages/Syllabus/Builder.vue` (nút ở `#actions`)
- Test: `tests/Feature/SyllabusImportPageTest.php`

**Interfaces:**
- Consumes: Task 1–4.
- Produces: route trong nhóm `can:syllabus.manage`:
  - `GET /syllabus/import` → `index` (`syllabus.import`, query `token` tuỳ chọn)
  - `GET /syllabus/import/template` → `template` (`syllabus.import.template`, tải `mau-import-syllabus.zip`, `deleteFileAfterSend()`)
  - `POST /syllabus/import/preview` → `preview` (`syllabus.import.preview`; field `file` `required|file|max:102400|extensions:zip,xlsx`, `visible_to_teachers`/`visible_to_assistants`/`downloadable` boolean) → redirect `syllabus.import?token=…`; `SyllabusPackageException` → `forget` + redirect `withErrors(['file' => message])`
  - `POST /syllabus/import/confirm` → `confirm` (Task 7)
  - Props `Syllabus/Import`: `canUpload: bool`, `preview: null | {token, file_name, curriculum: {code, title, version}, summary, errors, warnings, stages: list<{position, name, units: list<{unit_number, title, lessons: list<{session_no, title}>}>, documents: list<{title, extension, size}>}>, shared_documents, visibility}`. Không gửi nội dung chi tiết buổi (mục tiêu, hoạt động…) sang trang.

- [ ] **Step 1: Viết test thất bại** — `SyllabusImportPageTest` (`RefreshDatabase`, `BuildsSyllabusPackages`, `Storage::fake(SyllabusDocument::DISK)`; người dùng: Học thuật `Roles::ACADEMIC_LEAD`, giáo viên `Roles::TEACHER_FULLTIME`):
  - `test_teacher_cannot_open_import`: GET `syllabus.import`, `syllabus.import.template`, POST `syllabus.import.preview` → 403.
  - `test_template_download`: 200, header `content-disposition` chứa `mau-import-syllabus.zip`.
  - `test_preview_shows_tree_without_writing`: POST file hợp lệ → redirect có `token`; GET trang → `assertInertia` `preview.summary.lessons === 4`, `preview.errors === []`; `SyllabusCurriculum::count()` không đổi; `assertSee('Buổi 1')`.
  - `test_preview_lists_errors`: gói có lỗi → `preview.errors` có phần tử, HTML có thông báo lỗi.
  - `test_fatal_package_error_returns_to_upload`: zip có `../evil.pdf` → `assertSessionHasErrors('file')`, thư mục `SyllabusImportStore::root()` không còn file của token.
  - `test_builder_shows_import_button_for_managers`: GET `syllabus.builder` với Học thuật → `assertSee('Import từ file')`.
- [ ] **Step 2: Chạy để thấy thất bại** — `phpunit tests/Feature/SyllabusImportPageTest.php` → FAIL.
- [ ] **Step 3: Implement** controller (quyền chỉ qua middleware `can:syllabus.manage` của nhóm route), route, `artisan ziggy:generate resources/js/ziggy.js`. `Import.vue` theo bố cục `Pages/Classes/Import.vue` (thanh 3 bước, `router.post` với `FormData`, nút "Tải file mẫu (.zip)" ở `#actions`); bước ②: tóm tắt, `UiAlert type="error"` liệt kê lỗi, `UiAlert type="warning"` cảnh báo, cây chặng → unit → buổi + tài liệu, nút "Tải file khác" (GET `syllabus.import` không token). Nút "Xác nhận import" chưa có ở Sprint 1 (Task 7 thêm cùng route). Builder: `<UiButton v-if="canManage" variant="secondary" icon="upload_file" :href="route('syllabus.import')">Import từ file</UiButton>`.
- [ ] **Step 4: Chạy test** — `phpunit tests/Feature/SyllabusImportPageTest.php` → PASS; sau đó các test bảo vệ: `AllRoutesSmokeTest`, `RbacNoHardcodedRolesTest`, `BranchIsolationRouteSweepTest`, `NavigationPermissionTest`, `Phase2SyllabusTest`, `Phase2MockupSyllabusTest` → PASS.
- [ ] **Step 5: Commit + kết thúc Sprint 1** — commit `feat: add syllabus import preview page`; cập nhật bảng Sprint trong `docs/import-syllabus.md` (Sprint 1 → **Xong**), commit `docs: mark syllabus import sprint 1 done`. Chưa push — toàn bộ tính năng là **một PR** mở ở cuối Task 8.

---

## Sprint 2 — Import + dọn dẹp

### Task 6: `SyllabusImporter`

**Files:**
- Create: `app/Services/Syllabus/Import/SyllabusImporter.php`, `app/Services/Syllabus/Import/SyllabusImportException.php`
- Test: `tests/Feature/SyllabusImporterTest.php`

**Interfaces:**
- Consumes: `SyllabusImportPlan` (Task 2).
- Produces: `public function import(SyllabusImportPlan $plan, User $user, array $visibility): SyllabusCurriculum` — yêu cầu `$plan->ok()`; ném `SyllabusImportException('Mã <code> đã tồn tại — đổi mã khác.')` khi trùng mã (kiểm `withTrashed()` trong transaction + bắt `Illuminate\Database\UniqueConstraintViolationException`).

- [ ] **Step 1: Viết test thất bại** (`RefreshDatabase`, `Storage::fake(SyllabusDocument::DISK)`):
  - `test_imports_full_tree`: giáo trình có đúng 2 chặng (`position` 1, 2 — **không có chặng thứ 3** do `booted()`), chặng 1 có `big_test_title` từ file, unit gắn đúng `stage_id` + `curriculum_id`, buổi có `curriculum_id`, `session_no` 1…4 và nội dung các cột.
  - `test_imports_documents_with_visibility`: 1 tài liệu chặng 1 + 1 tài liệu chung → `SyllabusDocument` có `stage_id` đúng / null, `stage_name` = label chặng / null, `visible_to_teachers`, `visible_to_assistants`, `downloadable` theo `$visibility`, `uploaded_by = $user->id`, file tồn tại trên disk fake.
  - `test_duplicate_code_throws`: tạo sẵn mã → `expectException(SyllabusImportException::class)`, số giáo trình không đổi.
  - `test_failure_rolls_back_and_removes_stored_files`: plan có 2 tài liệu, tài liệu thứ 2 có `local_path` không tồn tại → exception; `SyllabusCurriculum::withTrashed()->count() === 0`, `Storage::disk(SyllabusDocument::DISK)->allFiles(SyllabusDocument::DIRECTORY) === []`.
- [ ] **Step 2: Chạy để thấy thất bại** — `phpunit tests/Feature/SyllabusImporterTest.php` → FAIL.
- [ ] **Step 3: Implement.** Trong `DB::transaction`: tạo `SyllabusCurriculum` với `stage_name` = tên chặng 1 (để `booted()` tạo đúng Chặng 1) rồi `update` chặng 1 bằng các trường còn lại; tạo chặng 2…n, unit, buổi; lưu tài liệu bằng `SafeUploadService::store(new UploadedFile($localPath, $originalName, null, null, true), …)`, ghi lại path đã lưu; `catch (\Throwable)` → xoá các path đã lưu trên `SyllabusDocument::DISK` rồi ném lại.
- [ ] **Step 4: Chạy test** → PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: import syllabus packages into curriculum"`

### Task 7: Bước xác nhận (③)

**Files:**
- Modify: `app/Http/Controllers/SyllabusImportController.php` (`confirm`), `routes/web.php`, `resources/js/ziggy.js`, `resources/js/Pages/Syllabus/Import.vue`
- Test: `tests/Feature/SyllabusImportPageTest.php` (thêm test)

**Interfaces:**
- Consumes: `SyllabusImportStore::get/forget`, Reader, Validator, `SyllabusImporter::import`.
- Produces: `POST /syllabus/import/confirm` (`syllabus.import.confirm`, field `token`). Thành công → `forget`, redirect `syllabus.builder?curriculum=<id>` với `status` = `"Đã import giáo trình {code}: {stages} chặng, {lessons} buổi, {documents} tài liệu. Gán cấp độ khoá học để lớp dùng được giáo trình."`. Hết hạn → redirect `syllabus.import` `withErrors(['file' => 'Phiên kiểm tra đã hết hạn, vui lòng tải file lên lại.'])`. Plan còn lỗi / `SyllabusImportException` → redirect `syllabus.import?token=…` `withErrors(['file' => …])`.

- [ ] **Step 1: Viết test thất bại:**
  - `test_confirm_creates_curriculum_and_redirects_to_builder`: trang xem trước `assertSee('Xác nhận import')`; preview → confirm → redirect đúng URL + flash `status` chứa `'Đã import giáo trình KIDS-Q4: 2 chặng, 4 buổi, 0 tài liệu.'`; thư mục tạm của token đã xoá.
  - `test_confirm_twice_creates_one_curriculum`: confirm lần 2 cùng token → lỗi phiên hết hạn; `SyllabusCurriculum::count() === 1`.
  - `test_confirm_with_other_users_token_is_rejected`; `test_confirm_after_expiry_is_rejected` (`travel(121)->minutes()`).
  - `test_confirm_rechecks_duplicate_code`: preview xong, tạo giáo trình cùng mã, confirm → lỗi trùng mã, không tạo thêm.
  - `test_confirm_with_documents_requires_upload_permission`: Học thuật bị chặn upload bằng `UserPermissionOverride::create(['user_id' => …, 'module' => 'syllabus', 'action' => 'upload', 'scope_type' => UserPermissionOverride::SCOPE_ALL, 'allow' => false])` (như `AclRoleUserTest`) → preview có lỗi quyền, confirm bị chặn.
- [ ] **Step 2: Chạy để thấy thất bại** → FAIL.
- [ ] **Step 3: Implement** `confirm` (đọc lại gói, validate lại với `can('syllabus.upload')`, gọi importer); route; ziggy; `Import.vue` thêm nút "Xác nhận import" (disabled khi `preview.errors.length`, POST `syllabus.import.confirm` với `token`).
- [ ] **Step 4: Chạy test** — `phpunit tests/Feature/SyllabusImportPageTest.php` → PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: confirm syllabus import"`

### Task 8: Bỏ file mẫu PDF ở trang Tài liệu + kết thúc Sprint 2

**Files:**
- Modify: `resources/js/Pages/Syllabus/Documents.vue` (bỏ hằng `SAMPLE_PDF` + comment dòng 31–32, nút "Tải file mẫu (.pdf)" dòng 56, đoạn `<p>` "Soạn theo … file mẫu" dòng 188–190)
- Delete: `public/files/mau-tai-lieu-giao-trinh.pdf`
- Test: `tests/Feature/SyllabusImportPageTest.php` (thêm test), `docs/import-syllabus.md`

- [ ] **Step 1: Viết test thất bại** — `test_documents_page_has_no_sample_pdf`: GET `syllabus.documents` (Học thuật) → `assertDontSee('Tải file mẫu (.pdf)')`, `assertDontSee('mau-tai-lieu-giao-trinh.pdf')`.
- [ ] **Step 2: Chạy để thấy thất bại** → FAIL.
- [ ] **Step 3: Sửa `Documents.vue`, xoá file PDF.**
- [ ] **Step 4: Chạy test** — `SyllabusImportPageTest`, `SyllabusImporterTest`, rồi test bảo vệ: `AllRoutesSmokeTest`, `RbacNoHardcodedRolesTest`, `BranchIsolationRouteSweepTest`, `NavigationPermissionTest`, `Phase2SyllabusTest`, `Phase2MockupSyllabusTest`, `SyllabusStageProgressionTest` → PASS.
- [ ] **Step 5: Commit + kết thúc** — commit `feat: remove sample pdf from syllabus documents`; cập nhật `docs/import-syllabus.md` (trạng thái **Hoàn thành**, Sprint 2 → **Xong**), commit `docs: mark syllabus import done`; push nhánh `feat/syllabus-import`, mở **một PR** (base `main`) cho cả 2 sprint.
