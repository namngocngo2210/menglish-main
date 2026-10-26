# Ngân hàng câu hỏi & Luyện tập theo khối

Trạng thái: **Sprint 1–4 — Hoàn thành** (mọi sprint đã xong) — 24/10/2026

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
| `question_bank_questions` | `id`, `passage_id` FK null (cascade), `position` smallint, `grade`, `term`, `skill`, `type`, `knowledge` null, `instruction` text null, `stem` text, `options` json (A–D), `answer` char(1), `image_path` null, `status` (`draft`/`approved`/`hidden`), `approved_by` FK users null, `approved_at` null, `edited_at` null, `source_*`, `source_key` | unique `source_key`; index `(grade, term, status, type)`, `passage_id`; `answer ∈ {A,B,C,D}` kiểm tra ở tầng ứng dụng (importer + form sửa câu), **không** CHECK trong CSDL (giữ migration tương thích SQLite / MariaDB) |
| `practice_blueprints` | `id`, `grade`, `term`, `duration_minutes`, `items` json `[{skill, type, count}]` | unique `(grade, term)` |
| `practice_attempts` | `id`, `student_id` FK, `user_id` FK, `grade`, `term`, `status` (`in_progress`/`submitted`), `started_at`, `deadline_at`, `submitted_at` null, `auto_submitted` bool, `submit_reason` null (`timeout`/`violations`), `total`, `correct`, `score` decimal(4,2) null, `skill_scores` json null, `violation_count`, `violation_log` json null | index `(student_id, created_at)`, `(grade, term, submitted_at)`, `(status, deadline_at)` |
| `practice_attempt_items` | `id`, `attempt_id` FK (cascade), `question_id` FK null (null on delete), `position`, `skill`, `type`, `snapshot` json (instruction, passage {content, image_path}, image_path, stem, options, answer, knowledge), `chosen` char(1) null, `is_correct` bool null | unique `(attempt_id, position)`; index `(question_id, is_correct)` |

Ghi chú:

- **Snapshot** câu hỏi trong `practice_attempt_items` để sửa / ẩn câu trong ngân hàng không làm đổi bài đã làm. Ảnh lưu
  đường dẫn trong kho (`image_path`), URL dựng lúc trả props (`PracticeAttemptProps`); snapshot cũ có `image_url` vẫn đọc được.
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

Điều hướng (`SidebarMenu`): nhóm riêng **E-learning** trên sidebar (khu Đào tạo, tách khỏi Giáo trình) gồm hai tab
**Ngân hàng câu hỏi** (gồm Khung đề, Phân tích câu hỏi) và **Kết quả luyện tập**; **Cổng Giáo viên** thêm **Kết quả luyện tập** (cùng màn, lọc sẵn `?mine=1` — học viên lớp
mình dạy); **Cổng Phụ huynh & Học sinh** thêm **Luyện tập** (cả thanh điều hướng dưới trên di động).

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
  - Vì khoá chỉ dựa trên `file_no`, **đợt dữ liệu mới phải dùng `file_no` mới** (không bao giờ dùng lại số đã import),
    nếu không câu mới sẽ ghi đè / bị nhầm với câu cũ cùng khoá.
  - Màn import và lệnh artisan đều tắt nhật ký từng câu, ghi **một dòng nhật ký tổng** (tên file, số thêm / cập nhật /
    giữ / bỏ qua / lỗi).
  - Bảo mật ZIP: chặn `..` và đường dẫn tuyệt đối, chỉ `.json` / `.png` / `.jpg` / `.jpeg`, tối đa 100 MB, tối đa
    5.000 mục; ảnh đi qua `SafeUploadService` (kiểm tra theo nội dung).

### 5.2 Màn Ngân hàng câu hỏi

- Lọc: khối, kỳ, kỹ năng, dạng bài, trạng thái, tìm nội dung. Danh sách phân trang, hiển thị gạch chân và ảnh.
- **Duyệt / Ẩn các câu đã chọn** và **Duyệt / Ẩn tất cả câu đang lọc** (một câu lệnh `UPDATE`, ghi audit). Duyệt / ẩn
  không đặt `edited_at` (không phải sửa nội dung; import vốn giữ mọi câu khác `draft`); **chuyển về Chưa duyệt** thì đặt
  `edited_at` để import lại không đưa câu về bản gốc.
- Sửa câu: modal theo `RendersModals` (mẫu Holidays) — câu hỏi, A–D, đáp án, kiến thức, trạng thái; sửa → `edited_at`
  (nhãn "Đã sửa"). Sửa ngữ liệu dùng chung: modal riêng, đặt `edited_at` cho mọi câu của ngữ liệu. Mở trang đầy đủ từ
  Phân tích câu hỏi (`?return=` — chỉ nhận đường dẫn `/question-bank…`) → lưu xong quay về đúng trang phân tích.
- Không có "thêm câu bằng tay" ở bản đầu.

### 5.3 Màn Khung đề

Lưới khối × kỳ; mỗi ô: thời gian + danh sách {kỹ năng, dạng bài, số câu}, kèm **số câu đã duyệt hiện có** cho từng dòng
(đỏ khi thiếu).

## 6. Luyện tập ở cổng học viên (Sprint 3)

### 6.1 Màn Luyện tập

`PracticeController` (route dưới `portal.`, học viên lấy bằng cơ chế `getActiveStudent` hiện có — tách sang trait /
service dùng chung nếu cần):

- Đầu trang: "Khối 7 — năm học 2026–2027" (hoặc thông báo chưa xác định khối).
- 4 thẻ kỳ: số câu bốc được "~N câu" = Σ min(số câu khung cần, số câu đã duyệt) theo (kỹ năng, dạng bài) — một truy vấn
  `GROUP BY` (`QuestionAvailability`), thời gian; N = 0 → mờ, "Chưa có đề". Kỳ có lượt đang làm → nút **Làm tiếp**.
- Lịch sử (phân trang): ngày, kỳ, điểm, thời gian làm, nhãn "Tự nộp" / "Vi phạm" → mở trang kết quả.
- Nhân sự xem cổng (quyền quản lý học viên) chỉ xem lịch sử, không bắt đầu bài.

### 6.2 Sinh đề — `App\Services\Practice\PracticeTestGenerator`

1. Lấy khung đề `(grade, term)`; không có → dùng mặc định mục 3.3.
2. Duyệt các dòng theo thứ tự kỹ năng (Ngữ âm → Từ vựng & Ngữ pháp → Giao tiếp & Biển báo → Đọc hiểu → Viết).
3. Mỗi dòng khung đề: đơn vị bốc là **nguyên ngữ liệu** (mọi câu `approved` của ngữ liệu, theo `position`) hoặc một câu
   lẻ. Lặp tới khi đủ số câu: chỉ xét đơn vị **vừa với số câu còn thiếu**, lấy đơn vị **lớn nhất** (ngẫu nhiên giữa các
   đơn vị cùng cỡ). Không đơn vị nào vừa: nếu dòng chưa lấy được câu nào → lấy **một đơn vị nhỏ nhất** (có thể vượt số câu
   của dòng); đã lấy được một phần → dừng (dòng thiếu câu).
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
- Nộp lỗi (mất mạng / lỗi máy chủ): bỏ màn "Đang nộp bài…", báo lỗi kèm nút **Thử nộp lại**; còn giờ và chưa đủ lượt rời
  bài thì mở lại chọn đáp án và bật lại khoá màn hình.
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
- **Phân tích câu hỏi** (`question_bank.view`): câu có tỉ lệ sai cao (≥ 10 lượt làm), đáp án sai nhiều nhất, link sửa
  câu. Câu đã sửa (`edited_at`) chỉ tính lượt bắt đầu **sau lần sửa cuối** (lượt cũ chấm theo snapshot trước khi sửa, vd.
  khoá đáp án sai) — dòng ghi "Tính từ lần sửa dd/mm/yyyy".
- Tổng hợp bằng `GROUP BY` trên index; phân trang mọi danh sách; không N+1.

## 8. Kiểm thử

| File | Nội dung |
|---|---|
| `tests/Unit/SchoolGradeTest` | mốc 1/8, `grade_adjust` ±, thiếu `dob`, ngoài 1–12 |
| `tests/Feature/StudentSchoolGradeTest` | ô "Khối hiện tại" trong hồ sơ học viên ↔ `grade_adjust` |
| `tests/Unit/ExamViolationsTest` | loại vi phạm, giới hạn, làm sạch nhật ký (dùng chung test đầu vào) |
| `tests/Feature/QuestionBankPermissionTest` | quyền mới + migration cấp quyền (up / down) |
| `tests/Feature/PracticeSchemaTest` | bảng, khung đề mặc định, snapshot giữ khi xoá câu |
| `tests/Feature/QuestionBankImporterTest` | import ZIP + lệnh artisan (một dòng nhật ký tổng), chạy lại không trùng, bỏ câu không hợp lệ, không ghi đè câu đã duyệt / đã sửa, chặn path traversal |
| `tests/Feature/QuestionBankControllerTest` | quyền (chỉ Admin + Học thuật), duyệt / ẩn hàng loạt theo bộ lọc, sửa câu / ngữ liệu, quay về trang đã mở |
| `tests/Feature/PracticeBlueprintControllerTest` | màn khung đề (16 ô), số câu đã duyệt theo dòng, sửa khung, quyền |
| `tests/Feature/PracticeTestGeneratorTest` | đúng số câu, nguyên ngữ liệu, chỉ `approved`, tránh lặp, thiếu câu |
| `tests/Feature/PracticeAttemptServiceTest` | bắt đầu / làm tiếp, snapshot (đường dẫn ảnh), từ chối sau hạn, tự chốt quá hạn, vi phạm lần 3, chấm điểm |
| `tests/Feature/PracticePortalTest` | màn Luyện tập (số câu bốc được), props không lộ đáp án, nộp / hết giờ, IDOR → 404, nhân sự chỉ xem |
| `tests/Feature/PracticeResultsReportTest` | GV chỉ thấy lớp mình, Học thuật / Admin, bộ lọc, nút quay lại giữ bộ lọc, số truy vấn cố định |
| `tests/Feature/QuestionAnalysisTest` | tỉ lệ sai, đáp án sai nhiều nhất, ≥ 10 lượt, chỉ tính sau lần sửa cuối, số truy vấn cố định |
| `resources/js/lib/*.test.js` (`npm run test:js`) | gạch chân `[x]`, nhóm câu theo kỹ năng / ngữ liệu, `useExamLock` |
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
- Test: `PracticeTestGeneratorTest`, `PracticeAttemptServiceTest`, `PracticePortalTest`, hồi quy test đầu vào.

### Sprint 4 — Báo cáo (xong, 24/10/2026)

- Màn Kết quả luyện tập (Học thuật / Admin, GV), phân tích câu hỏi.
- Đợt sửa sau review cuối: phân tích chỉ tính sau lần sửa, số câu bốc được trên thẻ kỳ, snapshot lưu đường dẫn ảnh,
  nhật ký tổng cho import CLI, nộp lỗi có "Thử nộp lại", nút quay lại giữ bộ lọc.
- Test: `PracticeResultsReportTest`, `QuestionAnalysisTest`.

Một nhánh `feat/practice-question-bank`, một MR mở sau Sprint 1 và cập nhật sau mỗi sprint (cập nhật mục này done / not
done); chạy test liên quan + guard-rail trước mỗi lần push.

## 10. Ngoài phạm vi / rủi ro

- Không có: câu Nghe, tự luận, đúng/sai, điền từ; thêm câu bằng tay; đảo phương án; port sang v2.
- Đề cố định (§11): chưa có đề chỉ có PDF, giao đề theo lớp, hẹn giờ mở đề.
- Học viên thiếu `dob` không luyện được → cần bổ sung ngày sinh trong hồ sơ.
- Lớp 6–9 chưa có thang điểm rubric test đầu vào (không ảnh hưởng luyện tập).
- Chất lượng đề phụ thuộc duyệt đáp án; tab Phân tích câu hỏi giúp phát hiện đáp án sai sau khi chạy.

## 11. Đề cố định (26/10/2026)

Bên cạnh đề trộn, học viên **chọn từng mã đề** (vd. đề GK1 khối 6 mã 601, 602, 603) để làm. Đề giữ nguyên thứ tự câu và
phương án như đề in. Phân theo khối: học viên chỉ thấy đề đã mở của khối mình. Làm bài, chấm, chống gian lận, kết quả
dùng lại toàn bộ §6.

### 11.1 Dữ liệu

| Bảng / cột | Nội dung |
|---|---|
| `practice_exams` | `grade`, `term`, `code` (mã đề), `title`, `duration_minutes`, `status` (`draft` Nháp / `published` Đã mở / `hidden` Đã ẩn), `source_file`, `import_warnings` json, `published_at`; unique `(grade, term, code)` |
| `question_bank_questions.exam_id`, `question_bank_passages.exam_id` | FK null, cascade. Câu / ngữ liệu của đề nằm trong bảng ngân hàng (dùng lại form sửa câu, Phân tích câu hỏi) nhưng **không vào kho trộn**: scope `inBank()` (`exam_id` null) ở sinh đề, `QuestionAvailability`, danh sách / duyệt hàng loạt Ngân hàng câu hỏi, danh sách dạng bài của Khung đề |
| `practice_attempts.exam_id` | FK null (null on delete); null = đề trộn. Index `(student_id, exam_id, status)` |

Câu của đề có `status = approved` (mở / ẩn theo cả đề, form sửa câu ẩn ô trạng thái, câu Phát âm / Trọng âm được để
trống đề bài). `position` = số câu trong đề.

### 11.2 Import & quản lý (Ngân hàng câu hỏi → tab **Đề cố định**, quyền `question_bank.*`)

- Tải lên ZIP (nén nguyên thư mục) hoặc các file `.xlsx` + `.docx` (`PracticeExamImporter`). Mỗi sheet Excel là một mã
  đề: tiêu đề "ĐỀ KIỂM TRA GIỮA HỌC KÌ I … TIẾNG ANH 6 – MÃ ĐỀ 601" → khối, kỳ, mã đề; "Thời gian làm bài: 60 phút".
  Dòng "Câu N" (dạng bài, câu hỏi, A–D, đáp án) → câu; dòng chỉ có cột đầu → hướng dẫn ("Mark the letter…", "Read the
  following…"), ngữ liệu chung của các câu sau, hoặc "Câu N: …" (phần đề tách dòng, mẫu khối 8) ghép vào câu N.
- Excel làm mất gạch chân: đọc file Word cùng mã đề ("Mã đề: 601"), lấy phương án / đề bài có gạch chân `[x]` khi bỏ
  gạch chân thì trùng chữ trong Excel. Câu Phát âm không có gạch chân → cảnh báo. Ngoặc vuông có sẵn trong Excel đổi
  thành ngoặc tròn (không bị hiểu là gạch chân).
- Bảng đáp án cuối sheet khác cột Đáp án → cảnh báo (dùng cột Đáp án); thiếu số câu → cảnh báo.
- Dạng bài → kỹ năng: Phát âm / Trọng âm → Ngữ âm; Đọc – Biển báo / Tin nhắn / Thông báo → Giao tiếp & Biển báo;
  Đọc… → Đọc hiểu; Viết… → Viết; còn lại → Từ vựng & Ngữ pháp.
- Đề mới / đề còn Nháp → ghi (thay toàn bộ câu); đề đã mở / đã ẩn → giữ nguyên. Bảo mật ZIP: chặn `..` / đường dẫn
  tuyệt đối, chỉ lấy `.xlsx` / `.docx`, tối đa 300 mục, 50 MB.
- Import chạy nền (hàng đợi Laravel, service `queue` trong `docker-compose.yml`): màn lưu file vào
  `storage/app/private/exam-imports/{id}`, tạo bản ghi `practice_exam_imports` (Đang chờ → Đang import → Xong / Lỗi) và
  đưa job `ImportPracticeExams` vào hàng đợi; job chạy importer, lưu kết quả từng đề, ghi một dòng nhật ký, xoá file.
  Màn Đề cố định hiện 5 lần import gần nhất (lần mới nhất kèm kết quả chi tiết), tự tải lại mỗi 3 giây khi còn lần đang
  chờ / đang chạy; chờ quá 2 phút → nhắc kiểm tra queue. Job chạy một lần, tối đa 900 giây.
- Danh sách theo khối: kỳ, số câu / phút, trạng thái, số lượt / học viên, điểm TB (so giữa các mã đề). Trang một đề: mọi
  câu kèm đáp án, ngữ liệu một lần trên nhóm câu, sửa câu / ngữ liệu (modal, `?return=` về đề), sửa tên / thời gian,
  **Mở cho học viên** / **Ẩn** / **Về nháp** (chỉ khi chưa có lượt làm), **Xoá** (chỉ khi chưa có lượt làm).

### 11.3 Cổng học viên

- Màn Luyện tập thêm mục **Đề cố định khối N**: đề đã mở của khối, nhóm theo kỳ; mỗi đề: số câu, thời gian, số lần đã
  làm, điểm cao nhất, nút **Làm đề / Làm lại / Làm tiếp**.
- `POST /practice/start` nhận `exam_id` → `PracticeAttemptService::startExam()`: đề phải đã mở, cùng khối với học
  viên; mỗi học viên × đề một lượt đang làm (bấm lại → làm tiếp); `deadline_at` theo thời gian của đề. Lượt đề trộn
  (`exam_id` null) và lượt đề cố định cùng kỳ độc lập; cửa sổ chống lặp câu của đề trộn chỉ xét lượt đề trộn.
- Trang làm bài / kết quả / lịch sử ghi "Đề 601" (đề trộn: "Đề trộn").

### 11.4 Báo cáo

Kết quả luyện tập (tổng hợp và trang một học viên) thêm bộ lọc **Đề**: Đề trộn hoặc từng đề cố định đã mở / đã ẩn;
danh sách lượt ghi mã đề.

### 11.5 Kiểm thử

| File | Nội dung |
|---|---|
| `tests/Feature/PracticeExamImporterTest` | ZIP Excel + Word: khối / kỳ / mã đề / thời gian, thứ tự câu, gạch chân từ Word, ngữ liệu chung, dòng "Câu N: …", ngoặc vuông, cảnh báo thiếu gạch chân / lệch đáp án, import lại (Nháp thay, đã mở giữ), sheet lỗi, path traversal |
| `tests/Feature/PracticeExamPortalTest` | chỉ đề đã mở của khối mình, bắt đầu / làm tiếp, không lộ đáp án, chấm điểm, nhãn mã đề, độc lập với đề trộn, chặn đề nháp / ẩn / khác khối |
| `tests/Feature/PracticeExamControllerTest` | quyền, import qua màn (job nền: trạng thái, kết quả, xoá file, lỗi file), xem đề, mở / ẩn / về nháp / xoá, sửa câu của đề, bộ lọc đề ở báo cáo |
| `tests/Support/PracticeExamFixture` | sinh file Excel / Word / ZIP theo mẫu file thật |
