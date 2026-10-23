# Ngân hàng câu hỏi & Luyện tập — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Import a grade/term-tagged multiple-choice question bank, let Học thuật review it, and let students practise auto-generated, timed, anti-cheat tests for their own grade in the student portal, with history and reports.

**Architecture:** New domain in the Laravel app: models `QuestionBank*` / `Practice*`, services in `app/Services/QuestionBank` and `app/Services/Practice`, support constants in `app/Support/Practice`. Staff screens are Inertia pages under `Pages/QuestionBank` and `Pages/PracticeResults`; the student flow lives in `Pages/Portal/Practice*`. Attempts snapshot each question so later edits never change history.

**Tech Stack:** Laravel 13 / PHP 8.4, Inertia 3 + Vue 3 (`<script setup>`, plain JS — repo convention), Tailwind 3, Spatie permission, PHPUnit (SQLite + SSR), `node --test` for `resources/js/lib/*.test.js`.

**Spec:** `docs/luyen-tap-ngan-hang-cau-hoi.md` (read it first; section numbers below refer to it).

## Global Constraints

- Work only in worktree `/home/menglish/wt-practice`, branch `feat/practice-question-bank`. Never touch `v2/` except regenerating `v2/api/internal/iam/catalog/catalog.json` (Task 3).
- Follow repo style: no `declare(strict_types=1)` (no file in `app/` has it); Vietnamese UI strings / comments / docs; English code identifiers and commit messages (`feat: …`, one line, no Co-Authored-By).
- Terms: `gk1` Giữa kì 1, `ck1` Cuối kì 1, `gk2` Giữa kì 2, `ck2` Cuối kì 2. Grades in the bank: 6–9.
- Skills, in test order: `Ngữ âm`, `Từ vựng & Ngữ pháp`, `Giao tiếp & Biển báo`, `Đọc hiểu`, `Viết`. Only 4-option A–D questions with an answer in `A|B|C|D` exist in the bank.
- School year starts **1 August**. Grade = school-year start year − birth year − 5 + `grade_adjust`; `null` if no `dob` or result outside 1–12.
- Score: scale 10, `decimal(4,2)`, `round(correct / total * 10, 2)`; never float columns.
- Answer save grace: 30 seconds after `deadline_at`. Max violations: 3 (shared with placement test).
- Anti-repeat window: the student's last 3 attempts for the same grade × term.
- Authorize with `can:` middleware / `$user->can()`; never compare role names (`RbacNoHardcodedRolesTest`). Admin gets action permissions via `Gate::before`; grant migrations target `Roles::ACADEMIC_LEAD` and `Roles::TEACHERS`.
- Inertia props: only needed fields; money/score as numbers, dates ISO/`Y-m-d`; **no correct answer in any prop while an attempt is `in_progress`**.
- Underline markup `[x]` is rendered by splitting text, never `v-html`.
- Imports run synchronously (no queue worker in this repo).
- After editing `routes/web.php`: `php artisan ziggy:generate resources/js/ziggy.js` and run `ZiggyRoutesTest`.
- Running PHP (no PHP on host): `docker run --rm -u $(id -u):$(id -g) -e HOME=/tmp -v $PWD:$PWD -w $PWD --entrypoint php menglish-test:local -d memory_limit=1G vendor/bin/phpunit tests/Feature/XTest.php` (pass `--filter=…` as a normal argument). Artisan: same with `artisan …` instead of `vendor/bin/phpunit …`. JS tests: `node --test resources/js/lib/underline.test.js`.
- `npm run build` rewrites tracked `public/build/` — never commit it (`git checkout -- public/build && git clean -fd public/build`).

## Review Focus

1. **Student without `dob` or outside grades 6–9** opens Luyện tập → friendly message, no 500, no start button (Task 10).
2. **Late / duplicate submits** (answer after deadline, submit twice, two tabs, violation arriving after submit) → finalize is idempotent, score computed once, late answers rejected with 422 (Task 9, Task 10).
3. **Answer leakage** — the take page props (questions, passages, snapshot) contain no `answer` key while `in_progress` (Task 10).
4. **IDOR** — another family's attempt id on any attempt route → 404; staff without the student in scope → 404 (Task 10, Task 11).
5. **Re-import after edits / malformed files** — re-importing never overwrites approved, hidden or edited questions; one malformed JSON is reported in `errors` and the rest still import (Task 4).

---

## Sprint 1 — Nền dữ liệu & quyền

### Task 1: Schema, models, practice catalog constants

**Files:**
- Create: `database/migrations/2026_11_21_090000_create_question_bank_and_practice_tables.php`
- Create: `app/Support/Practice/PracticeCatalog.php`
- Create: `app/Models/QuestionBankPassage.php`, `app/Models/QuestionBankQuestion.php`, `app/Models/PracticeBlueprint.php`, `app/Models/PracticeAttempt.php`, `app/Models/PracticeAttemptItem.php`
- Modify: `app/Models/Student.php` (fillable `grade_adjust`, cast int, `practiceAttempts()` hasMany)
- Test: `tests/Feature/PracticeSchemaTest.php`

**Interfaces:**
- Produces: `PracticeCatalog::TERMS` (`['gk1' => 'Giữa kì 1', 'ck1' => 'Cuối kì 1', 'gk2' => 'Giữa kì 2', 'ck2' => 'Cuối kì 2']`), `PracticeCatalog::SOURCE_TERMS` (`['Giữa kì I' => 'gk1', 'Cuối kì I' => 'ck1', 'Giữa kì II' => 'gk2', 'Cuối kì II' => 'ck2']`), `PracticeCatalog::SKILLS` (ordered list above), `PracticeCatalog::GRADES = [6, 7, 8, 9]`, `PracticeCatalog::DEFAULT_DURATION = 45`, `PracticeCatalog::DEFAULT_ITEMS` (spec §3.3 table as `list<array{skill: string, type: string, count: int}>`), `PracticeCatalog::skillOrder(string $skill): int` (index in SKILLS, unknown → 99).
- Produces: `QuestionBankQuestion::STATUSES = ['draft' => 'Chưa duyệt', 'approved' => 'Đã duyệt', 'hidden' => 'Đã ẩn']`, `scopeApproved()`, `passage()` belongsTo, `options` cast array, uses `AuditsChanges`; `QuestionBankPassage::questions()` hasMany ordered by `position`; `PracticeBlueprint::forGradeTerm(int $grade, string $term): self` (existing row or unsaved instance with defaults), `items` cast array; `PracticeAttempt` consts `IN_PROGRESS = 'in_progress'`, `SUBMITTED = 'submitted'`, casts (`started_at`/`deadline_at`/`submitted_at` datetime, `skill_scores`/`violation_log` array, `auto_submitted` bool, `score` `decimal:2`), `student()`, `user()`, `items()` (ordered by `position`); `PracticeAttemptItem` `snapshot` cast array, `attempt()`, `question()`.

- [ ] **Step 1: Write the failing test** `PracticeSchemaTest`:
  - `test_default_blueprints_cover_every_grade_and_term`: `PracticeBlueprint::count() === 16`; for grade 7 / `ck1`: `duration_minutes === 45`, `array_sum(array_column($bp->items, 'count')) === 40`.
  - `test_question_belongs_to_passage_and_orders_by_position`: create passage + 2 questions (positions 2, 1) → `$passage->questions->pluck('position')->all() === [1, 2]`; `QuestionBankQuestion::approved()` only returns `status = approved`.
  - `test_for_grade_term_falls_back_to_defaults`: delete grade 6 `gk1` row → `forGradeTerm(6, 'gk1')->items === PracticeCatalog::DEFAULT_ITEMS`, `exists === false`.
  - `test_attempt_item_keeps_snapshot_after_question_deleted`: delete question → item `question_id` null, `snapshot['stem']` intact.
- [ ] **Step 2: Run** `…phpunit tests/Feature/PracticeSchemaTest.php` → FAIL (classes/tables missing).
- [ ] **Step 3: Implement** the migration exactly per spec §3.2 (minus `question_bank_imports`) plus `students.grade_adjust smallint default 0`; `answer` CHECK via `$table->char('answer', 1)` + validation (SQLite-safe; skip DB CHECK); insert 16 default blueprint rows from `PracticeCatalog::DEFAULT_ITEMS` inside `up()`; `down()` drops tables and column. Models + catalog as in Interfaces.
- [ ] **Step 4: Run** the test → PASS.
- [ ] **Step 5: Commit** `feat: add question bank and practice schema`.

### Task 2: School grade calculation and "Khối hiện tại" on the student profile

**Files:**
- Create: `app/Support/Practice/SchoolGrade.php`
- Modify: `app/Http/Controllers/StudentProfileController.php` (`show` props, `updateStudent` validation + adjust), `resources/js/Pages/Students/Show.vue` (edit form field next to `dob`)
- Test: `tests/Unit/SchoolGradeTest.php`, `tests/Feature/StudentSchoolGradeTest.php`

**Interfaces:**
- Consumes: `Student::grade_adjust` (Task 1).
- Produces: `SchoolGrade::schoolYearStart(CarbonInterface $on): int`, `SchoolGrade::baseGrade(?CarbonInterface $dob, CarbonInterface $on): ?int` (no adjust), `SchoolGrade::forStudent(Student $student, ?CarbonInterface $on = null): ?int`, `SchoolGrade::adjustFor(Student $student, int $grade, ?CarbonInterface $on = null): int` (value to store in `grade_adjust`; requires `dob`), `SchoolGrade::schoolYearLabel(CarbonInterface $on): string` (`"2026–2027"`).

- [ ] **Step 1: Unit tests** `SchoolGradeTest`: on `2026-07-31` start year is 2025, on `2026-08-01` it is 2026; dob `2014-03-10` on `2026-10-23` → grade 7; same with `grade_adjust = -1` → 6; dob null → null; dob `2024-01-01` → null (grade < 1); `adjustFor(dob 2014, 6)` → `-1`; `schoolYearLabel(2026-10-23) === '2026–2027'`.
- [ ] **Step 2: Feature test** `StudentSchoolGradeTest`: admin PUT `students.update` with valid profile payload + `school_grade = 6` for dob 2014 student → `grade_adjust === -1`; `school_grade` empty → `grade_adjust` unchanged; `school_grade` with no `dob` → validation error on `school_grade` ("Cần ngày sinh để đặt khối."); `students.show` Inertia prop `student.school_grade === 7` (dob 2014, travel to 2026-10-23).
- [ ] **Step 3: Run both** → FAIL.
- [ ] **Step 4: Implement** `SchoolGrade` (uses `now()` when `$on` null); in `updateStudent` validate `'school_grade' => 'nullable|integer|between:1,12'`, compute after `dob` is applied, unset from `$validated` before `update`; expose `school_grade` and `school_year` in `show` props; add numeric input "Khối hiện tại" (hint "Tự tính từ ngày sinh; chỉ sửa khi học sớm / muộn.") beside `dob` in `Show.vue`.
- [ ] **Step 5: Run** both tests + `tests/Feature/Phase1StudentsTest.php` → PASS.
- [ ] **Step 6: Commit** `feat: derive student school grade from birth year`.

### Task 3: Permissions

**Files:**
- Modify: `config/permission_catalog.php` (modules `question_bank` group `academic`, icon `quiz`, actions `view` / `manage`; `practice` group `academic`, icon `fact_check`, action `view_results`), `config/access.php` (`academic_lead`: `question_bank.*`, `practice.view_results`; `teacher_fulltime` and `teacher_parttime`: `practice.view_results`)
- Create: `database/migrations/2026_11_21_090100_grant_question_bank_and_practice_permissions.php` (pattern: `2026_11_20_090100_grant_syllabus_library_permission.php`)
- Modify: `v2/api/internal/iam/catalog/catalog.json` (regenerated only)
- Test: `tests/Feature/QuestionBankPermissionTest.php`

**Interfaces:**
- Produces permissions `question_bank.view`, `question_bank.manage`, `practice.view_results` used by Tasks 5, 6, 12, 13.

- [ ] **Step 1: Test** `QuestionBankPermissionTest` (use `runSeederForReal()` where seeders are exercised, see `tests/TestCase.php`): after migrations, role `academic_lead` has all three; `academic_staff` and `manager` have none of `question_bank.*`; `teacher_fulltime` / `teacher_parttime` have only `practice.view_results`; admin user `can('question_bank.manage')`.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** catalog + access + grant migration (`ROLES_BY_PERMISSION` map, `findOrCreate`, `forgetCachedPermissions`, reversible `down`).
- [ ] **Step 4: Regenerate** the v2 catalog: `docker run … --entrypoint php menglish-test:local v2/api/internal/iam/catalog/export.php > v2/api/internal/iam/catalog/catalog.json`; `git diff --stat v2/` shows only `catalog.json`.
- [ ] **Step 5: Run** `QuestionBankPermissionTest`, `RbacNoHardcodedRolesTest`, `tests/Feature/*Permission*Catalog*` (whatever `ls tests/Feature | grep -i catalog` lists) → PASS.
- [ ] **Step 6: Commit** `feat: add question bank and practice permissions`.

**Sprint 1 close (controller):** update spec §9 Sprint 1 → "(xong)", push branch, open draft MR, send link.

---

## Sprint 2 — Ngân hàng câu hỏi

### Task 4: Importer service and artisan command

**Files:**
- Create: `app/Services/QuestionBank/QuestionBankImporter.php`, `app/Services/QuestionBank/ImportSummary.php`, `app/Console/Commands/ImportQuestionBankCommand.php`
- Test: `tests/Feature/QuestionBankImporterTest.php` (+ fixture builder inside the test that writes a ZIP to a temp dir)

**Interfaces:**
- Consumes: models + `PracticeCatalog::SOURCE_TERMS` (Task 1).
- Produces: `QuestionBankImporter::importZip(string $zipPath): ImportSummary`; `final class ImportSummary { public function __construct(public readonly int $created, public readonly int $updated, public readonly int $kept, public readonly int $skipped, public readonly array $errors) {} public function message(): string }` (Vietnamese one-line summary, e.g. "Thêm 120, cập nhật 3, giữ nguyên 5, bỏ qua 14."); command `question-bank:import {zip}` prints `message()` and each error.

ZIP format = the extraction JSON (example: spec's source files; schema: `{"source": {file_no, path, nam_hoc, ky, khoi, truong, …}, "groups": [{skill, type, instruction, passage?, questions: [{no, stem, options{A..D}, answer, kien_thuc?, anh?, thieu?, …}]}]}`) under `data/*.json`, images under `hinh/`.

Rules (spec §5.1): `grade = source.khoi`; `term = SOURCE_TERMS[source.ky]` (unknown → whole file in `errors`); question accepted only if options keys are exactly A,B,C,D non-empty, `answer ∈ A–D`, `thieu` not true, `skill !== 'Nghe'` — else `skipped++`; `source_key` question `sprintf('F%03d-%s', file_no, no)`, passage `sprintf('F%03d-NL%02d', file_no, n)` where n counts passage-bearing groups 1-based; passage created only when ≥1 of its questions is accepted; existing key with `status = draft` and `edited_at` null → update (`updated++`), otherwise `kept++`; images: `hinh/<anh>` stored via `SafeUploadService::store(new UploadedFile($tmp, $name, null, null, true), 'question_bank/'.now()->format('Y/m'), SafeUploadService::IMAGES)`; missing image → question still imported, error line added. ZIP safety: reject entry names containing `..`, starting with `/` or `\`, extensions other than json/png/jpg/jpeg, more than 5000 entries (whole import fails with `InvalidArgumentException`, Vietnamese message). Everything per file in one DB transaction; a malformed JSON file adds an error and continues.

- [ ] **Step 1: Tests** `QuestionBankImporterTest` (`Storage::fake('public')`):
  - `test_imports_mcq_questions_with_passages_and_images`: fixture with 1 file (grade 9, "Giữa kì I"): group A (no passage, 2 MCQ, one with `anh`), group B (passage, 2 MCQ), group C (one open question without options) → summary created 4, skipped 1; question rows have `grade 9`, `term 'gk1'`, `status 'draft'`; passage `F070-NL01` has 2 questions positions 1,2; image path exists on fake disk.
  - `test_reimport_updates_untouched_drafts_and_keeps_reviewed`: import, then approve one, set `edited_at` on another, change stems in fixture, re-import → `updated 2, kept 2`, approved/edited stems unchanged.
  - `test_malformed_json_is_reported_and_others_import`: 2 files, one invalid JSON → `errors` has 1 entry naming the file; other file's questions created.
  - `test_rejects_zip_with_path_traversal`: entry `../evil.json` → `InvalidArgumentException`, no rows.
  - `test_skips_questions_marked_missing_or_without_answer`.
  - `test_command_prints_summary`: `artisan('question-bank:import', ['zip' => $path])->expectsOutputToContain('Thêm 4')->assertSuccessful()`.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** importer, summary, command.
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `feat: import question bank from zip`.

### Task 5: Question bank screen (list, bulk review, edit, import)

**Files:**
- Create: `app/Http/Controllers/QuestionBankController.php`, `resources/js/Pages/QuestionBank/Index.vue`, `resources/js/Pages/QuestionBank/QuestionForm.vue`, `resources/js/Pages/QuestionBank/PassageForm.vue`, `resources/js/Components/Practice/UnderlinedText.vue`, `resources/js/lib/underline.js`, `resources/js/lib/underline.test.js`
- Modify: `routes/web.php` (auth group, prefix `question-bank`, name `question-bank.`), `resources/js/ziggy.js`, `app/Support/Navigation/SidebarMenu.php` (Học thuật group: "Ngân hàng câu hỏi" → `question-bank.index`, active `question-bank.*`, `can` `question_bank.view`)
- Test: `tests/Feature/QuestionBankControllerTest.php`

**Interfaces:**
- Consumes: Task 1 models, Task 3 permissions, Task 4 importer.
- Produces routes: `question-bank.index` GET `/question-bank` (view); `question-bank.bulk` POST `/question-bank/bulk` (manage) body `{action: approve|hide|draft, ids?: int[], all_matching?: bool, filters…}`; `question-bank.questions.edit` GET + `question-bank.questions.update` PUT `/question-bank/questions/{question}` (manage, `RendersModals`); `question-bank.passages.edit` / `question-bank.passages.update` `/question-bank/passages/{passage}`; `question-bank.import` POST `/question-bank/import` (manage, `file` required zip max 102400 KB).
- Produces JS: `splitUnderline(text: string): Array<{text: string, underline: boolean}>` in `lib/underline.js`; `<UnderlinedText :text="…" />` renders it.

- [ ] **Step 1: JS test** `underline.test.js`: `splitUnderline('c[oa]st')` → `[{text:'c',underline:false},{text:'oa',underline:true},{text:'st',underline:false}]`; text without brackets → one plain part; unmatched `[` stays literal; `'<b>[x]</b>'` returns the tags as plain text parts.
- [ ] **Step 2: PHP tests** `QuestionBankControllerTest`:
  - access: `academic_lead` 200 on index; `academic_staff` and a teacher 403; guest redirected.
  - filters: grade/term/skill/status/q narrow `questions.total`; prop `questions.data.0` has `stem`, `options`, `answer`, `status`, `passage` (id, content) — staff screen may show answers.
  - `test_bulk_approve_all_matching_filter`: 3 draft grade 7 + 2 draft grade 8, POST bulk `{action: approve, all_matching: true, grade: 7}` → 3 approved with `approved_by` / `approved_at`, grade 8 untouched.
  - `test_bulk_with_ids_hides_selected`.
  - `test_update_question_sets_edited_at_and_validates_answer`: PUT with `answer = 'E'` → error; valid PUT → `edited_at` not null, `modalSaved` redirect.
  - `test_import_endpoint_shows_summary`: upload fixture zip (reuse Task 4 builder via a small `tests/Support/QuestionBankZip.php` helper moved there) → session `status` contains "Thêm".
- [ ] **Step 3: Run** → FAIL.
- [ ] **Step 4: Implement** controller (paginate 30, `->with('passage')`, filter options from `PracticeCatalog`, status counts), bulk `UPDATE` in one query (approve sets `approved_by/at`), Vue pages following `resources/js/README.md` and `Pages/Holidays/*` (modal + full page), sidebar entry, ziggy regenerate.
- [ ] **Step 5: Run** controller test, `node --test resources/js/lib/underline.test.js`, `AllRoutesSmokeTest`, `NavigationPermissionTest`, `ZiggyRoutesTest`, `BranchIsolationRouteSweepTest` → PASS.
- [ ] **Step 6: Commit** `feat: add question bank review screen`.

### Task 6: Blueprint screen

**Files:**
- Create: `app/Http/Controllers/PracticeBlueprintController.php`, `resources/js/Pages/QuestionBank/Blueprints.vue`, `resources/js/Pages/QuestionBank/BlueprintForm.vue`
- Create: `app/Services/Practice/QuestionAvailability.php`
- Modify: `routes/web.php`, `resources/js/ziggy.js`, `Pages/QuestionBank/Index.vue` (tab link)
- Test: `tests/Feature/PracticeBlueprintControllerTest.php`

**Interfaces:**
- Produces: `QuestionAvailability::counts(int $grade, string $term): array<string, int>` keyed `"{skill}|{type}"` = approved question count; routes `question-bank.blueprints.index` GET `/question-bank/blueprints` (view), `question-bank.blueprints.edit` GET / `question-bank.blueprints.update` PUT `/question-bank/blueprints/{grade}/{term}` (manage; grade ∈ GRADES, term ∈ TERMS keys else 404).
- Validation: `duration_minutes` integer 5–180; `items` array 1–30; each `skill` in `PracticeCatalog::SKILLS`, `type` string ≤100, `count` integer 0–100.

- [ ] **Step 1: Tests**: index returns 16 cells with `total` 40 and `available` per row (`short = available < count`: with the default "Phát âm" count 2, seeding 2 approved grade 7 ck1 → that row `available === 2`, `short === false`; seeding 1 → `short === true`); update persists items and duration; invalid skill → 422; academic_staff 403.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** + `ZiggyRoutesTest`, `AllRoutesSmokeTest` → PASS.
- [ ] **Step 5: Commit** `feat: configure practice blueprints`.

**Sprint 2 close (controller, not a subagent):** build per-grade ZIPs from `/home/menglish/question-bank-work/{data,hinh}` (`Ngan_hang_cau_hoi_K6.zip` … `K9.zip` in `/home/menglish/`), import into staging with `docker/menglish.sh staging artisan question-bank:import` after deploy, update spec §9, push, update MR.

---

## Sprint 3 — Luyện tập ở cổng học viên

### Task 7: Shared exam lock (placement test refactor)

**Files:**
- Create: `app/Support/ExamViolations.php`, `resources/js/lib/useExamLock.js`
- Modify: `app/Models/PlacementTestSubmission.php` (constants delegate: `MAX_VIOLATIONS = ExamViolations::MAX`, `VIOLATION_TYPES = ExamViolations::TYPES`), `app/Http/Controllers/PlacementTestController.php` (`sanitizeViolationLog` → `ExamViolations::sanitizeLog`), `resources/js/Pages/PlacementTests/Portal/Take.vue` (use composable)
- Test: `tests/Unit/ExamViolationsTest.php`; regression: `tests/Feature/PlacementPortalSecurityTest.php` and every test matching `ls tests/Feature | grep -i placement`

**Interfaces:**
- Produces PHP: `ExamViolations::MAX = 3`, `ExamViolations::TYPES` (the 3 current types/labels), `ExamViolations::sanitizeLog(mixed $raw): ?array` (accepts JSON string or array; max 50 entries; unknown types dropped; `at` ≤ 40 chars; empty → null), `ExamViolations::isValidType(string $type): bool`.
- Produces JS: `useExamLock({ maxViolations, onViolation(type, count), onLimit() })` returns `{ active, log, showWarning, start(), resume(), stop() }` — owns fullscreen, blocked events (`contextmenu copy cut paste drop dragstart`), `selectstart` (except input/textarea), shortcut blocking, back-button lock, `beforeunload`, 1.5 s de-duplication, listener cleanup on unmount; exactly the behaviour currently in `Take.vue` lines 40–201.

- [ ] **Step 1: Unit test** `ExamViolationsTest`: JSON string with 2 valid + 1 unknown type → 2 entries; 60 entries → 50; non-JSON → null.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement** PHP + composable; rewrite `Take.vue` to call it (start → `lock.start()`, auto-submit in `onLimit`, hidden inputs still read `lock.log`).
- [ ] **Step 4: Run** unit test + all placement feature tests + `npm run build` (then restore `public/build`) → PASS / build ok.
- [ ] **Step 5: Commit** `refactor: share exam lock between placement test and practice`.

### Task 8: Practice test generator

**Files:**
- Create: `app/Services/Practice/PracticeTestGenerator.php`
- Test: `tests/Feature/PracticeTestGeneratorTest.php`

**Interfaces:**
- Consumes: Task 1 models/catalog.
- Produces: `PracticeTestGenerator::generate(PracticeBlueprint $blueprint, array $recentQuestionIds = []): Collection<int, QuestionBankQuestion>` (ordered, `passage` loaded); `PracticeTestGenerator::hasQuestions(int $grade, string $term): bool`.

Algorithm (spec §6.2): rows sorted by `PracticeCatalog::skillOrder` (stable within skill); per row, candidates = approved questions of grade/term/skill/type. If candidates have a `passage_id`, work in passages: shuffle passages, prefer those with no recent question, take a passage while `taken < count` and (its approved size ≤ `count − taken` or no smaller passage remains), include all its approved questions by `position`. Otherwise shuffle questions, fresh first, take `count`. Never return a question twice. Randomness via `Collection::shuffle()` (tests assert sets/counts, not order).

- [ ] **Step 1: Tests**: blueprint `[Phát âm 2, Đọc hiểu – trả lời câu hỏi 5]` with 5 approved Phát âm + passages of 5 and 3 questions → result has 2 Phát âm first, then exactly the 5-question passage in position order; drafts/hidden never returned; with `recentQuestionIds` = 3 of the 5 Phát âm → the 2 fresh ones chosen; only 1 approved Phát âm → returns 1 (shortage) without error; `hasQuestions(9, 'gk1')` false when none approved.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `feat: generate practice tests from blueprints`.

### Task 9: Attempt service and expiry command

**Files:**
- Create: `app/Services/Practice/PracticeAttemptService.php`, `app/Services/Practice/PracticeException.php`, `app/Console/Commands/ClosePracticeAttemptsCommand.php`
- Modify: `routes/console.php` (`Schedule::command('practice:close-expired')->everyFiveMinutes();`)
- Test: `tests/Feature/PracticeAttemptServiceTest.php`

**Interfaces:**
- Consumes: `SchoolGrade` (Task 2), generator (Task 8), `ExamViolations` (Task 7).
- Produces: `start(Student $student, User $user, string $term): PracticeAttempt` (resumes a non-expired `in_progress` attempt for same student+term; finalizes an expired one first; throws `PracticeException` "Chưa xác định khối…" when grade null, "Chưa có đề cho khối X – <kỳ>." when no questions); `saveAnswer(PracticeAttempt $a, int $position, ?string $choice): void` (choice in A–D or null; throws `PracticeException` when submitted or `now() > deadline_at + 30s`); `recordViolation(PracticeAttempt $a, string $type): PracticeAttempt` (ignored when not in progress or unknown type; at `ExamViolations::MAX` → `finalize($a, 'violations')`); `finalize(PracticeAttempt $a, ?string $reason = null): PracticeAttempt` (row lock + status check → idempotent; sets `is_correct` per item, `total`, `correct`, `score`, `skill_scores` = `{skill: {correct, total}}`, `submitted_at`, `auto_submitted = reason !== null`, `submit_reason`); `closeExpired(): int`.
- Snapshot written at start: `{instruction, passage: {content, image_url}|null, image_url, stem, options, answer, knowledge}`.

- [ ] **Step 1: Tests** (travel time with `$this->travelTo`): start creates 40-item attempt with `deadline_at = started + 45 min`; start again same term → same attempt id; start after deadline → old one finalized (`submit_reason 'timeout'`) and a new one created; saveAnswer at deadline+29s ok, +31s throws; finalize twice → score unchanged and `submitted_at` unchanged; 3 violations → submitted with `submit_reason 'violations'`; violation after submit ignored; `closeExpired` finalizes only expired in-progress attempts and returns count; command `practice:close-expired` runs; grade null → exception message; score `7.50` for 30/40.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `feat: practice attempt lifecycle`.

### Task 10: Student portal pages

**Files:**
- Create: `app/Http/Controllers/PracticeController.php`, `resources/js/Pages/Portal/Practice.vue`, `resources/js/Pages/Portal/PracticeTake.vue`, `resources/js/Pages/Portal/PracticeResult.vue`, `resources/js/Components/Practice/AttemptReview.vue`
- Modify: `app/Http/Controllers/StudentPortalController.php` (make `getActiveStudent` / `portalProps` reusable: move them to trait `app/Http/Concerns/ResolvesPortalStudent.php` used by both controllers — no behaviour change), `routes/web.php` (inside the existing `portal` prefix group, attempt routes before `/practice/{studentId?}`), `resources/js/ziggy.js`, `app/Support/Navigation/SidebarMenu.php` (portal group: "Luyện tập" → `portal.student.practice`, active `portal.student.practice*`), `resources/js/Pages/Portal/PortalBottomNav.vue` (item `practice`, icon `quiz`, label "Luyện tập")
- Test: `tests/Feature/PracticePortalTest.php`

**Interfaces:**
- Consumes: Task 2, 8, 9; `ExamViolations::MAX`.
- Produces routes: `portal.student.practice` GET `/portal/practice/{studentId?}`; `portal.student.practice.start` POST `/portal/practice/start` `{student_id, term}`; `portal.student.practice.attempt` GET `/portal/practice/attempts/{attempt}` (take page when in progress, result page when submitted); `portal.student.practice.answer` POST `…/{attempt}/answer` JSON `{position, choice}` → `{ok: true}` / 422 `{message}`; `portal.student.practice.violation` POST `…/{attempt}/violation` JSON `{type}` → `{count, submitted}`; `portal.student.practice.submit` POST `…/{attempt}/submit` → redirect to attempt page.
- Access: writes (start/answer/violation/submit) only when `$student->isLinkedTo($user)`; reads also for staff with `student.view` and the student in `Student::visibleTo($user)`; otherwise 404.
- Props: `Practice` → `{student, students, unreadCount, grade, schoolYear, terms: [{key, label, questionCount, duration, available, inProgressAttemptId}], history: paginated [{id, term_label, started_at, duration_seconds, score, auto_submitted, submit_reason}], canStart}`; `PracticeTake` → `{attempt: {id, term_label, remainingSeconds, violationCount, maxViolations}, items: [{position, skill, type, instruction, passage, image_url, stem, options, chosen}]}` (**no `answer`**); `PracticeResult` → `{attempt: {…, score, correct, total, skill_scores, violation_log}, items: [{…, chosen, answer, is_correct}]}`.
- Take page: `useExamLock` (Task 7) with `onViolation` → POST violation (submitted → `router.visit` attempt page), countdown from `remainingSeconds`, answers POSTed on change via `postJson`, timer 0 → submit; passage shown once per group (consecutive items sharing passage content); `UnderlinedText` for stems/options.

- [ ] **Step 1: Tests** `PracticePortalTest` (student user linked via `user_id`, dob 2014, travel 2026-10-23, approved questions seeded for grade 7 ck1):
  - index shows grade 7, 4 terms, `ck1.available` true, `gk1.available` false; student without dob → prop `grade` null, `canStart` false, sees "Chưa xác định khối".
  - start → redirect to attempt page; take props: `items` count matches, and `json_encode($page->toArray()['props'])` does **not** contain `"answer"`.
  - answer endpoint saves `chosen`; after deadline+31 s → 422.
  - violation ×3 → third response `submitted: true`, attempt submitted.
  - submit → result props include `answer`, `is_correct`, `score`.
  - IDOR: another parent's user GET/POST any attempt route → 404; staff with `student.view` but student outside scope → 404; academic_lead in scope can GET result but POST start → 403.
  - sidebar/bottom nav: portal page HTML contains "Luyện tập".
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement** (extract trait first, run `Flow4StudentPortalTest` to confirm no regression).
- [ ] **Step 4: Run** `PracticePortalTest`, `Flow4StudentPortalTest`, `ZiggyRoutesTest`, `AllRoutesSmokeTest`, `NavigationPermissionTest`, `BranchIsolationRouteSweepTest` → PASS; `npm run build` ok (restore `public/build`).
- [ ] **Step 5: Commit** `feat: student practice in portal`.

**Sprint 3 close (controller):** update spec §9, push, update MR.

---

## Sprint 4 — Báo cáo

### Task 11: Practice results report (Học thuật / Admin / teachers)

**Files:**
- Create: `app/Http/Controllers/PracticeResultsController.php`, `app/Services/Practice/PracticeReport.php`, `resources/js/Pages/PracticeResults/Index.vue`, `resources/js/Pages/PracticeResults/Student.vue`, `resources/js/Pages/PracticeResults/Attempt.vue`
- Modify: `routes/web.php`, `resources/js/ziggy.js`, `SidebarMenu.php` (Học thuật group and Cổng Giáo viên group: "Kết quả luyện tập" → `practice-results.index`, `can` `practice.view_results`)
- Test: `tests/Feature/PracticeResultsReportTest.php`

**Interfaces:**
- Produces: `PracticeReport::summary(User $viewer, array $filters): array{attempts: int, avg_score: ?float, students: int, skills: array<string, ?float>}`; `PracticeReport::studentRows(User $viewer, array $filters): LengthAwarePaginator` rows `{student_id, name, code, class_name, attempts, avg_score, last_score, weakest_skill, violation_attempts}`; filters `grade, term, class_id, student_id, from, to` (dates `Y-m-d`). Scope: submitted attempts with `student_id IN Student::visibleTo($viewer)->select('id')`; aggregates via `GROUP BY student_id` (skill averages computed from `practice_attempt_items` grouped by skill — no N+1).
- Routes: `practice-results.index` GET `/practice-results`; `practice-results.student` GET `/practice-results/students/{student}` (student must be in `visibleTo`, else 404); `practice-results.attempt` GET `/practice-results/attempts/{attempt}` (read-only `AttemptReview`, includes violations).

- [ ] **Step 1: Tests**: teacher of class A sees only class A students' rows; academic_lead sees all in scope; `summary.avg_score` correct for 2 attempts (6.00, 8.00 → 7.00); filter by term; teacher opening a class-B student → 404; `in_progress` attempts excluded; academic_staff without permission → 403.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** + guard-rail tests → PASS.
- [ ] **Step 5: Commit** `feat: practice results report`.

### Task 12: Question analysis

**Files:**
- Modify: `app/Http/Controllers/QuestionBankController.php` (action `analysis`), `app/Services/Practice/PracticeReport.php` (`questionStats`)
- Create: `resources/js/Pages/QuestionBank/Analysis.vue`
- Modify: `routes/web.php` (`question-bank.analysis` GET `/question-bank/analysis`, `can:question_bank.view`), `resources/js/ziggy.js`, `Pages/QuestionBank/Index.vue` (tab link)
- Test: `tests/Feature/QuestionAnalysisTest.php`

**Interfaces:**
- Produces: `PracticeReport::questionStats(array $filters, int $minAttempts = 10): LengthAwarePaginator` rows `{question_id, stem, grade, term, skill, type, answer, attempts, wrong, wrong_rate, top_wrong_choice}` sorted by `wrong_rate` desc, only submitted attempts' items with `question_id` not null and `attempts >= minAttempts`.

- [ ] **Step 1: Tests**: question answered 10 times with 8 wrong (mostly "B") → `wrong_rate 0.8`, `top_wrong_choice 'B'`; question with 9 attempts excluded; row links to `question-bank.questions.edit`; teacher 403.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `feat: question difficulty analysis`.

**Sprint 4 close (controller):** run every test file created in this plan + guard-rail tests (`RbacNoHardcodedRolesTest`, `AllRoutesSmokeTest`, `BranchIsolationRouteSweepTest`, `NavigationPermissionTest`, `ZiggyRoutesTest`) + placement tests; `vendor/bin/pint` on changed PHP files; update spec status to done; push, update MR, send link.
