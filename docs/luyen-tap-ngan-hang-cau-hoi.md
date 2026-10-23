# Ngân hàng câu hỏi & Luyện tập theo khối

Trạng thái: **Sprint 1–3 — Hoàn thành** (Sprint 4 chưa làm) — 24/10/2026

Nguồn dữ liệu: thư mục Drive "FILE TỔNG HỢP ĐỀ CƯƠNG CÁC NĂM" (đề / đề cương THCS khối 6–9, năm học 2025–2026 và
2026–2027, 4 kỳ). Câu hỏi đã được tách ra từng câu theo kỹ năng (JSON + ảnh) ngoài repo; tính năng này đưa chúng vào
hệ thống và cho học viên luyện tập.

## 1. Bối cảnh

- Hồ sơ học viên (`students`) **không có khối**: chỉ có `dob`, `school`. Khối chỉ có ở test đầu vào
  (`placement_tests.grade_level`, `PlacementTest::GRADE_LEVELS` = Mẫu giáo, Lớp 1…9) và trình độ
  (`course_levels.grade_levels`).
- Cổng học viên (`StudentPortalController`, `Pages/Portal/*`, sidebar `portal.student`) có Trang chủ, Học tập & Nộp
  bài, Luyện phát âm, Thông báo, Khảo sát, Góp ý; chưa có luyện đề.
- Test đầu vào đã có khoá màn hình chống gian lận (`Pages/PlacementTests/Portal/Take.vue`: toàn màn hình, chặn copy /
  paste / chuột phải, đếm rời bài, `PlacementTestSubmission::MAX_VIOLATIONS = 3`) nhưng logic nằm trong 1 trang và số
  lần vi phạm chỉ gửi lên khi nộp.
- Đáp án phần lớn do AI giải (file gốc không kèm đáp án) → phải được Học thuật duyệt trước khi học viên dùng.

## 2. Quyết định (chủ dự án chốt 23/10/2026)

1. **Khối của học viên tự tính từ năm sinh**, có ghi đè: khối = năm bắt đầu năm học − năm sinh − 5 + `grade_adjust`.
   Năm học mới tính từ **1/8**. Thiếu ngày sinh → chưa có khối.
2. **Chỉ câu trắc nghiệm A–D** vào ngân hàng và vào đề. Không Nghe, không tự luận, không câu thiếu dữ liệu.
3. **Khung đề do Học thuật cấu hình** theo khối × kỳ (số câu theo kỹ năng / dạng bài), có mẫu mặc định.
4. **Kỳ:** Giữa kì 1 (`gk1`), Cuối kì 1 (`ck1`), Giữa kì 2 (`gk2`), Cuối kì 2 (`ck2`).
5. **Ngân hàng câu hỏi** có màn duyệt; import xong là *Chưa duyệt*; **chỉ câu đã duyệt được bốc vào đề**. Ban đầu duyệt
   hàng loạt được, sửa từng câu sau. Chỉ **Admin + Học thuật** (`academic_lead`) xem / quản lý.
6. **Làm bài kiểu thi thật:** có giờ, hết giờ tự nộp; nộp xong xem điểm + đáp án đúng từng câu. Làm lại không giới
   hạn, mỗi lần đề mới. Lịch sử được lưu.
7. **Chống gian lận như test đầu vào:** toàn màn hình, chặn copy / paste / chuột phải / bôi đen, rời bài lần 3 tự nộp.
8. **Ai xem kết quả:** học viên / phụ huynh (cổng học viên); Học thuật + Admin (báo cáo); giáo viên (học viên lớp mình).
9. **Không làm gì cho v2** — ngoại lệ duy nhất: chạy lại lệnh export danh mục quyền và commit
   `v2/api/internal/iam/catalog/catalog.json` (file sinh tự động; `v2-ci` chạy trên PR sửa `database/migrations/**` và
   so danh mục quyền). Không port tính năng sang v2.

## 3. Dữ liệu

### 3.1 Khối học viên

`students.grade_adjust` — `smallint`, mặc định `0`, NOT NULL.

`App\Support\Practice\SchoolGrade`:

- `schoolYearStart(CarbonInterface $on): int` — tháng ≥ 8 → năm hiện tại, ngược lại năm trước.
- `forStudent(Student $s, ?CarbonInterface $on = null): ?int` — `null` khi thiếu `dob`; kết quả ngoài 1–12 → `null`.
- Form hồ sơ học viên hiển thị ô **"Khối hiện tại"** (đã tính); nhân viên sửa thành N → lưu
  `grade_adjust = N − khối tính từ năm sinh` (năm sau tự lên lớp). Giữ nguyên số đang hiển thị (kể cả khi sửa ngày
  sinh) → không đổi `grade_adjust`, khối đi theo ngày sinh mới.

### 3.2 Bảng mới

| Bảng | Cột | Ràng buộc / index |
|---|---|---|
| `question_bank_passages` | `id`, `grade` tinyint, `term` varchar(3), `skill`, `type`, `instruction` text null, `content` text, `image_path` null, `source_school` null, `source_year` null, `source_file` null, `source_key` | unique `source_key`; index `(grade, term, type)` |
| `question_bank_questions` | `id`, `passage_id` FK null (cascade), `position` smallint, `grade`, `term`, `skill`, `type`, `knowledge` null, `instruction` text null, `stem` text, `options` json (A–D), `answer` char(1), `image_path` null, `status` (`draft`/`approved`/`hidden`), `approved_by` FK users null, `approved_at` null, `edited_at` null, `source_*`, `source_key` | unique `source_key`; index `(grade, term, status, type)`, `passage_id`; CHECK `answer IN ('A','B','C','D')` |
| `practice_blueprints` | `id`, `grade`, `term`, `duration_minutes`, `items` json `[{skill, type, count}]` | unique `(grade, term)` |
| `practice_attempts` | `id`, `student_id` FK, `user_id` FK, `grade`, `term`, `status` (`in_progress`/`submitted`), `started_at`, `deadline_at`, `submitted_at` null, `auto_submitted` bool, `submit_reason` null (`timeout`/`violations`), `total`, `correct`, `score` decimal(4,2) null, `skill_scores` json null, `violation_count`, `violation_log` json null | index `(student_id, created_at)`, `(grade, term, submitted_at)`, `(status, deadline_at)` |
| `practice_attempt_items` | `id`, `attempt_id` FK (cascade), `question_id` FK null (null on delete), `position`, `skill`, `type`, `snapshot` json (instruction, passage, image, stem, options, answer), `chosen` char(1) null, `is_correct` bool null | unique `(attempt_id, position)`; index `(question_id, is_correct)` |

Ghi chú:

- **Snapshot** câu hỏi trong `practice_attempt_items` để sửa / ẩn câu trong ngân hàng không làm đổi bài đã làm.
- **Gạch chân** lưu dạng `[x]` trong `stem` / `options` / `content`; Vue vẽ thành gạch chân (component `UnderlinedText`,
  tách chuỗi, không `v-html`).
- **Ảnh** lưu qua `SafeUploadService` (disk theo `UPLOAD_STORAGE`: local hoặc R2), thư mục `question_bank/Y/m`.
- **Điểm** thang 10, `decimal(4,2)`; không dùng float.
- `question_bank_questions` dùng `AuditsChanges`.
- Migration tương thích SQLite (test) và MariaDB (production, `v2-ci` chạy migration Laravel trên MariaDB).

### 3.3 Khung đề mặc định

Tạo bằng migration cho 4 khối (6–9) × 4 kỳ, 45 phút, 40 câu — Học thuật sửa được:

| Kỹ năng | Dạng bài | Số câu |
|---|---|---|
| Ngữ âm | Phát âm | 2 |
| Ngữ âm | Trọng âm | 2 |
| Từ vựng & Ngữ pháp | Chọn đáp án đúng | 12 |
| Từ vựng & Ngữ pháp | Tìm lỗi sai | 2 |
| Giao tiếp & Biển báo | Hội thoại | 2 |
| Giao tiếp & Biển báo | Biển báo / Thông báo | 2 |
| Đọc hiểu | Điền từ – đoạn văn | 5 |
| Đọc hiểu | Đọc hiểu – trả lời câu hỏi | 5 |
| Viết | Câu đồng nghĩa | 4 |
| Viết | Dựng câu từ gợi ý | 2 |
| Viết | Sắp xếp câu thành đoạn | 2 |

## 4. Quyền & điều hướng

`config/permission_catalog.php` (module mới), cấp qua `config/access.php` (vai trò mới tạo) **và** migration cấp cho vai
trò đang có (theo `docs/rbac.md`):

| Quyền | Admin | Học thuật | GV fulltime / parttime | Ghi chú |
|---|---|---|---|---|
| `question_bank.view` | ✓ | ✓ | | Xem ngân hàng, phân tích câu hỏi |
| `question_bank.manage` | ✓ | ✓ | | Import, sửa, duyệt / ẩn, khung đề |
| `practice.view_results` | ✓ | ✓ | ✓ | Phạm vi theo `Student::visibleTo()` (GV: học viên lớp mình) |

Học viên dùng quyền có sẵn `portal.student`. Sau khi sửa catalog: chạy
`php v2/api/internal/iam/catalog/export.php > v2/api/internal/iam/catalog/catalog.json` (quyết định 9).

Sidebar (`SidebarMenu`): nhóm Học thuật thêm **Ngân hàng câu hỏi**, **Kết quả luyện tập**; cổng giáo viên thêm
**Kết quả luyện tập**; cổng học viên thêm **Luyện tập** (cả `PortalBottomNav`).

## 5. Import & duyệt (Sprint 2)

### 5.1 Import ZIP

- Màn Ngân hàng câu hỏi → **Import**: tải ZIP gồm `data/*.json` (định dạng file tách câu) + `hinh/*`.
- **Chạy đồng bộ trong request** (repo không có queue worker — không có `ShouldQueue`, `docker-compose` không chạy
  `queue:work`), nên dữ liệu được đóng gói **mỗi khối một ZIP** cho nhẹ; xong hiển thị tổng kết (flash).
- `App\Services\QuestionBank\QuestionBankImporter` (dùng chung cho màn import và lệnh `question-bank:import {zip}`):
  - Kỳ: "Giữa kì I" → `gk1`, "Cuối kì I" → `ck1`, "Giữa kì II" → `gk2`, "Cuối kì II" → `ck2`; khối = `source.khoi`.
  - Chỉ nhận câu có đủ A–D và `answer ∈ {A,B,C,D}`, không `thieu`; còn lại → `skipped` kèm lý do.
  - `source_key` = `F{file_no}-{no}` (câu) / `F{file_no}-NL{n}` (ngữ liệu). Đã có và còn `draft` + chưa sửa
    (`edited_at` null) → cập nhật; đã duyệt / ẩn / đã sửa → giữ nguyên.
  - Bảo mật ZIP: chặn `..` và đường dẫn tuyệt đối, chỉ `.json` / `.png` / `.jpg` / `.jpeg`, tối đa 100 MB, tối đa
    5.000 mục; ảnh đi qua `SafeUploadService` (kiểm tra theo nội dung).

### 5.2 Màn Ngân hàng câu hỏi

- Lọc: khối, kỳ, kỹ năng, dạng bài, trạng thái, tìm nội dung. Danh sách phân trang, hiển thị gạch chân và ảnh.
- **Duyệt / Ẩn các câu đã chọn** và **Duyệt / Ẩn tất cả câu đang lọc** (một câu lệnh `UPDATE`, ghi audit).
- Sửa câu: modal theo `RendersModals` (mẫu Holidays) — câu hỏi, A–D, đáp án, kiến thức, trạng thái; sửa → `edited_at`.
  Sửa ngữ liệu dùng chung: modal riêng.
- Không có "thêm câu bằng tay" ở bản đầu.

### 5.3 Màn Khung đề

Lưới khối × kỳ; mỗi ô: thời gian + danh sách {kỹ năng, dạng bài, số câu}, kèm **số câu đã duyệt hiện có** cho từng dòng
(đỏ khi thiếu).

## 6. Luyện tập ở cổng học viên (Sprint 3)

### 6.1 Màn Luyện tập

`PracticeController` (route dưới `portal.`, học viên lấy bằng cơ chế `getActiveStudent` hiện có — tách sang trait /
service dùng chung nếu cần):

- Đầu trang: "Khối 7 — năm học 2026–2027" (hoặc thông báo chưa xác định khối).
- 4 thẻ kỳ: số câu, thời gian; kỳ không có câu đã duyệt → mờ, "Chưa có đề". Kỳ có lượt đang làm → nút **Làm tiếp**.
- Lịch sử (phân trang): ngày, kỳ, điểm, thời gian làm, nhãn "Tự nộp" / "Vi phạm" → mở trang kết quả.
- Nhân sự xem cổng (quyền quản lý học viên) chỉ xem lịch sử, không bắt đầu bài.

### 6.2 Sinh đề — `App\Services\Practice\PracticeTestGenerator`

1. Lấy khung đề `(grade, term)`; không có → dùng mặc định mục 3.3.
2. Duyệt các dòng theo thứ tự kỹ năng (Ngữ âm → Từ vựng & Ngữ pháp → Giao tiếp & Biển báo → Đọc hiểu → Viết).
3. Dạng có ngữ liệu chung: bốc ngẫu nhiên **nguyên ngữ liệu** (mọi câu `approved` của ngữ liệu, theo `position`),
   ưu tiên ngữ liệu có số câu ≤ số còn thiếu, dừng khi đủ hoặc hết. Dạng khác: bốc ngẫu nhiên từng câu.
4. Ưu tiên câu học viên **chưa gặp trong 3 lượt gần nhất** cùng khối × kỳ; thiếu mới lấy câu đã gặp.
5. Không đảo A–D. Thiếu câu → sinh với số câu có được; 0 câu → không cho bắt đầu.
6. Tạo `practice_attempts` + `practice_attempt_items` (snapshot) trong 1 transaction; `deadline_at = started_at + duration`.
7. Mỗi học viên × kỳ chỉ có **1 lượt `in_progress`**: bấm lại → làm tiếp lượt đó (nếu chưa quá hạn).

### 6.3 Làm bài

- Props trang làm bài **không chứa đáp án** (`snapshot.answer` bị loại khi `in_progress`).
- Đồng hồ đếm ngược theo **số giây còn lại do server gửi**; hết giờ → trình duyệt gọi nộp.
- Chọn đáp án → `POST …/answer` (tự lưu, debounce). Server từ chối sau `deadline_at + 30 giây`.
- Hết giờ trên trình duyệt → gửi nộp kèm `timeout: true`; server ghi là "Tự nộp – hết giờ" khi đã quá hạn hoặc cờ đến trong
  5 giây cuối (sớm hơn thì tính là nộp tay).
- `PracticeAttemptService::finalize()` (idempotent): chấm, ghi `total` / `correct` / `score` / `skill_scores`,
  `status = submitted`. Gọi khi: học viên nộp; mở lại lượt đã quá hạn; vi phạm lần 3; lệnh định kỳ
  `practice:close-expired` (mỗi 5 phút, `routes/console.php`, chốt lượt `in_progress` quá hạn).

### 6.4 Chống gian lận

- Tách logic khoá màn hình của `Take.vue` thành composable **`useExamLock()`** (toàn màn hình, chặn
  `contextmenu/copy/cut/paste/drop/dragstart/selectstart`, phím tắt, gộp blur + visibilitychange + thoát toàn màn hình
  thành 1 lần rời bài, màn "Quay lại làm bài"). Test đầu vào và Luyện tập cùng dùng; hành vi test đầu vào giữ nguyên.
- Tách `PlacementTestSubmission::VIOLATION_TYPES` / `MAX_VIOLATIONS` và `sanitizeViolationLog` sang
  `App\Support\ExamViolations` dùng chung.
- Luyện tập: mỗi lần rời bài → `POST …/violation`; server cộng `violation_count`, ghi `violation_log`; đạt 3 → server
  `finalize()` với `submit_reason = violations` (tải lại trang không xoá được số lần).
- Giới hạn: chỉ mang tính răn đe trên trình duyệt (không chặn chụp màn hình / máy thứ hai; Safari iPhone không có toàn
  màn hình).

### 6.5 Kết quả

Điểm, điểm theo kỹ năng, số lần rời bài, từng câu (đã chọn / đáp án đúng, câu sai tô đỏ, kèm ngữ liệu / ảnh). Component
dùng chung cho cổng học viên và màn báo cáo (chế độ chỉ xem).

## 7. Báo cáo (Sprint 4)

- **Kết quả luyện tập** (`practice.view_results`): lọc khối, kỳ, lớp, học viên, khoảng ngày; thẻ tổng quan (số lượt,
  điểm TB, số học viên đã luyện, điểm TB theo kỹ năng); bảng theo học viên (số lượt, điểm TB, điểm gần nhất, kỹ năng
  yếu nhất, số lượt có vi phạm) → danh sách lượt → xem lại bài. Phạm vi: `whereIn('student_id',
  Student::visibleTo($user)->select('id'))`.
- **Phân tích câu hỏi** (`question_bank.view`): câu có tỉ lệ sai cao (≥ 10 lượt làm), link sửa câu.
- Tổng hợp bằng `GROUP BY` trên index; phân trang mọi danh sách; không N+1.

## 8. Kiểm thử

| File | Nội dung |
|---|---|
| `tests/Unit/SchoolGradeTest` | mốc 1/8, `grade_adjust` ±, thiếu `dob`, ngoài 1–12 |
| `tests/Feature/QuestionBankImporterTest` | import ZIP, chạy lại không trùng, bỏ câu không hợp lệ, không ghi đè câu đã duyệt / đã sửa, chặn path traversal |
| `tests/Feature/QuestionBankControllerTest` | quyền (chỉ Admin + Học thuật), duyệt / ẩn hàng loạt theo bộ lọc, sửa câu, khung đề |
| `tests/Feature/PracticeTestGeneratorTest` | đúng số câu, nguyên ngữ liệu, chỉ `approved`, tránh lặp, thiếu câu |
| `tests/Feature/PracticeAttemptFlowTest` | bắt đầu / làm tiếp, tự lưu, props không lộ đáp án, từ chối sau hạn, tự chốt quá hạn, vi phạm lần 3, chấm điểm |
| `tests/Feature/PracticeResultsReportTest` | GV chỉ thấy lớp mình, Học thuật / Admin, phân tích câu hỏi |
| Test test đầu vào hiện có | hồi quy sau khi tách `useExamLock` / `ExamViolations` |
| Guard-rail | `RbacNoHardcodedRolesTest`, `AllRoutesSmokeTest`, `BranchIsolationRouteSweepTest`, `NavigationPermissionTest`, `ZiggyRoutesTest` |

Sau khi đổi `routes/web.php`: `php artisan ziggy:generate resources/js/ziggy.js`.

## 9. Sprint

### Sprint 1 — Nền dữ liệu & quyền (xong)

- Migration 5 bảng + `students.grade_adjust` + khung đề mặc định; model + quan hệ.
- `SchoolGrade`; ô "Khối hiện tại" trong form hồ sơ học viên.
- Quyền mới (catalog + `access.php` + migration cấp quyền) + export `catalog.json`.
- Test: `SchoolGradeTest`, phần quyền.

### Sprint 2 — Ngân hàng câu hỏi (xong)

- Importer (đồng bộ) + lệnh artisan; màn danh sách / duyệt / sửa; màn khung đề; sidebar.
- Đóng gói dữ liệu đã tách (khối 6–9) thành 4 ZIP (`Ngan_hang_cau_hoi_K6..K9.zip`); import thử vào CSDL tạm: 5.088 câu, 0 lỗi.
  Import trên staging + duyệt hàng loạt: làm sau khi deploy nhánh lên staging (cần chủ dự án chạy deploy).
- Test: `QuestionBankImporterTest`, `QuestionBankControllerTest`.

### Sprint 3 — Luyện tập ở cổng học viên (xong)

- Generator, attempt service, `PracticeController`, trang Luyện tập / làm bài / kết quả, lịch sử.
- `useExamLock()` + `ExamViolations` (refactor test đầu vào), lệnh `practice:close-expired`.
- Test: `PracticeTestGeneratorTest`, `PracticeAttemptFlowTest`, hồi quy test đầu vào.

### Sprint 4 — Báo cáo (chưa làm)

- Màn Kết quả luyện tập (Học thuật / Admin, GV), phân tích câu hỏi.
- Test: `PracticeResultsReportTest`.

Một nhánh `feat/practice-question-bank`, một MR mở sau Sprint 1 và cập nhật sau mỗi sprint (cập nhật mục này done / not
done); chạy test liên quan + guard-rail trước mỗi lần push.

## 10. Ngoài phạm vi / rủi ro

- Không có: câu Nghe, tự luận, đúng/sai, điền từ; thêm câu bằng tay; đảo phương án; port sang v2.
- Học viên thiếu `dob` không luyện được → cần bổ sung ngày sinh trong hồ sơ.
- Lớp 6–9 chưa có thang điểm rubric test đầu vào (không ảnh hưởng luyện tập).
- Chất lượng đề phụ thuộc duyệt đáp án; tab Phân tích câu hỏi giúp phát hiện đáp án sai sau khi chạy.
