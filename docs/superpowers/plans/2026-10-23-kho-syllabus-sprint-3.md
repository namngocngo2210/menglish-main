# Kho syllabus — Sprint 3 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Học thuật imports one stage of a curriculum from an `.xlsx` in the Sheet 1 form — as a new stage or updating an existing one — with preview and confirm.

**Architecture:** New classes in `app/Services/Syllabus/StageImport/`: a reader turning Sheet 1 into a DTO, a planner diffing it against the stage, an importer applying the plan in one transaction and renumbering `session_no`, and a template builder. A new controller reuses `SyllabusImportStore` for the upload token. UI: buttons on the curriculum detail page and a preview page.

**Tech Stack:** Laravel 13, PhpSpreadsheet (already used by `SyllabusPackageReader`), maatwebsite/excel, Inertia 3 + Vue 3, PHPUnit.

**Spec:** `docs/kho-syllabus.md` §3.3. Builds on Sprint 1 (fields, `syllabus.library`, `syllabus.curriculums.show`) and Sprint 2 (`LogsCurriculumId`, history).

## Global Constraints

- Sheet 1 layout (verified on `docs/Syllabus_Quy_trinh_xu_ly.xlsx`, sheet "1. Syllabus mẫu"): labels in column A rows 3–7 with values in column D — "Chương trình / Giáo trình:" (D3), "Chặng:" (D4), "Phiên bản / Ngày cập nhật:" (D6). Header row: the row where A = "Chặng" and B starts with "Buổi" (row 9 in the sample); one sub-header row follows (row 10); data starts after it (row 11).
- Columns: A Chặng · B Buổi / Lesson · C Loại buổi · D UNIT · E Objectives của UNIT · F Content · G Quy trình tiết học chung · H Hoạt động gợi ý 1 · I Hoạt động gợi ý 2 · J Bài tập giấy · K Bài tập Quizizz · L Bài tập Video · M Link PPT · N Link Quizizz · O Sản phẩm đầu ra · P Mốc thông báo · Q Chỉnh sửa thủ công (ignored) · R Ghi chú.
- Example rows: a data row whose column-A fill colour equals the fill of the legend swatch left of the cell containing "Dòng ví dụ" (rows 3–8; `FFF2F2F2` in the sample) is skipped. Rows where only column B (and/or A) is filled are blank and skipped.
- Mapping: Loại buổi "Học"/empty → `lesson`, "Mini test" → `mini_test`, "Big test" → `big_test` (case/space-insensitive); other → row error. Mốc thông báo: contains "big test" → `order_big_test`, else contains "mini test" → `order_mini_test`, else contains "in ấn" → `order_print`, empty → null, other → row error. Link cells: value must be a URL or empty; the literal placeholders "link PPT", "link Quizizz", "link đề" (any case) count as empty. UNIT "Unit 1: My New School" → `unit_number` 1, title "My New School" (regex `/^unit\s*(\d+)\s*[:\-–]\s*(.+)$/iu`); non-empty UNIT not matching → row error; data row before the first UNIT → row error. Lesson `title` = first line of Content, trimmed, max 120 chars; empty Content → `Buổi {n}` (`Mini test` / `Big test` for those types).
- Update rule: lesson i of the file (in file order) overwrites lesson i of the stage (existing lessons ordered by `session_no`), keeping its `id`; extra file rows create lessons; extra stage lessons are soft-deleted. Units match by `unit_number` within the curriculum; a unit number already used by another stage → row error.
- After apply: renumber `session_no` 1..N over the whole curriculum by stage `position`, then current order within the stage; use a two-pass update (negative temporaries) because of the unique index (`session_no` is signed since `2026_11_19_090000`).
- Permission `syllabus.library`. Max file 20 MB, `.xlsx` only. Token storage: `SyllabusImportStore` (session-bound, TTL as today).
- Same global rules as Sprint 1 (Docker tests, ziggy, no `public/build`, Vietnamese UI copy, one-line commits).

## Review Focus

1. Importing into a stage that a class is currently studying: lesson ids are kept, so proposals and lesson documents stay attached; preview warns "N lớp đang học chặng này" before confirm. Task 3 test.
2. The real sample file (`docs/Syllabus_Quy_trinh_xu_ly.xlsx`) uploaded as-is: example rows skipped → plan error "File chưa có buổi nào (dòng ví dụ nền xám đã được bỏ qua)". Task 1 test.
3. Workbook whose first sheet is not Sheet 1 (e.g. the full process workbook reordered) → reader looks for the sheet containing the header row, not just the first sheet. Task 1 test.
4. Re-uploading the same file twice → second import changes nothing except `updated_at` (idempotent counts: 0 created / 0 deleted). Task 3 test.
5. Lesson documents of a lesson soft-deleted by the import remain visible to library users on the stage (Sprint 2 grouping). Task 3 test.

---

### Task 1: Sheet 1 reader + template

**Files:**
- Create: `app/Services/Syllabus/StageImport/StageSheet.php` — readonly DTO: `string $curriculumLabel`, `string $stageName`, `string $version`, `list<StageSheetRow> $rows`, `int $skippedExamples`.
- Create: `app/Services/Syllabus/StageImport/StageSheetRow.php` — readonly: `int $line`, `string $type`, `?string $unitCell`, `?string $unitObjectives`, `?string $content`, `?string $procedure`, `?string $activity1`, `?string $activity2`, `?string $homeworkPaper`, `?string $homeworkQuizizz`, `?string $homeworkVideo`, `?string $pptUrl`, `?string $quizizzUrl`, `?string $outputProduct`, `?string $milestone`, `?string $note` (raw cell strings, trimmed; `type` raw text).
- Create: `app/Services/Syllabus/StageImport/StageSheetReader.php` — `read(string $path): StageSheet`; throws `StageImportException` (new, extends `RuntimeException`) with Vietnamese message when no sheet has the header row ("File không đúng form Syllabus mẫu (Sheet 1).").
- Create: `app/Services/Syllabus/StageImport/StageImportTemplate.php` — `build(): string` (temp xlsx path) producing the Sheet 1 layout above: title rows, labels A3–A7 with yellow input cells D3–D7, legend H4–I7, header rows 9–10, rows numbered 1..40 in column B, no example rows. Use PhpSpreadsheet directly.
- Test: `tests/Feature/SyllabusStageImportReaderTest.php`

**Interfaces:** Produces `StageSheetReader::read(string): StageSheet`, `StageImportTemplate::build(): string`, `StageImportException`.

- [ ] **Step 1: Write failing tests:**

```php
public function test_reads_header_and_rows_from_template_filled_by_test(): void
{
    $path = $this->filledTemplate([ // helper: opens StageImportTemplate::build(), writes D3/D4/D6 and rows from row 11, saves
        ['B' => 1, 'C' => 'Học', 'D' => 'Unit 1: My New School', 'E' => 'Obj U1', 'F' => "Getting started\nchi tiết", 'M' => 'https://ppt/1', 'N' => 'link Quizizz'],
        ['B' => 2, 'C' => 'Mini test', 'F' => 'MINI TEST UNIT 1', 'P' => 'Order in ấn mini test'],
    ], curriculum: 'Global Success 6', stage: 'Chặng 1 – Unit 1 → Unit 3', version: 'v1.0');
    $sheet = app(StageSheetReader::class)->read($path);
    $this->assertSame('Chặng 1 – Unit 1 → Unit 3', $sheet->stageName);
    $this->assertCount(2, $sheet->rows);
    $this->assertSame('Unit 1: My New School', $sheet->rows[0]->unitCell);
    $this->assertSame(11, $sheet->rows[0]->line);
}

public function test_sample_workbook_example_rows_are_skipped(): void
{
    $sheet = app(StageSheetReader::class)->read(base_path('docs/Syllabus_Quy_trinh_xu_ly.xlsx'));
    $this->assertSame(6, $sheet->skippedExamples);
    $this->assertCount(0, $sheet->rows);
}

public function test_non_sheet1_file_is_rejected(): void
{
    $this->expectException(StageImportException::class);
    app(StageSheetReader::class)->read($this->xlsxWithText('Hello'));
}
```

- [ ] **Step 2: Run, expect FAIL** (classes missing).
- [ ] **Step 3: Implement.** Find the sheet by scanning each worksheet's rows 1–20 for the header; example fill from the legend; read data rows until the last row with any value in C–R.
- [ ] **Step 4: Run, expect PASS.**
- [ ] **Step 5: Commit** `feat: read syllabus stage sheet (Sheet 1 form)`

---

### Task 2: Planner — validate and diff against the stage

**Files:**
- Create: `app/Services/Syllabus/StageImport/StageImportPlan.php` — readonly: `?int $stageId` (null = new stage), `string $stageName`, `list<array{line:int, unit_number:int, unit_title:?string, unit_objectives:?string, attributes: array<string, mixed>, lesson_id:?int}> $lessons` (attributes = lesson columns from Sprint 1 incl. `title`, `lesson_type`, `content`, `procedure`, `activity_1`, `activity_2`, `homework_guide`, `homework_quizizz`, `homework_video`, `ppt_url`, `quizizz_url`, `output_product`, `notice_milestone`, `note`), `list<int> $deleteLessonIds`, `list<array{unit_number:int,title:string,objectives:?string,unit_id:?int}> $units`, `list<array{line:int,message:string}> $errors`, `list<string> $warnings`, `int $openClasses`; `ok(): bool`, `summary(): array{create:int, update:int, delete:int, units:int}`.
- Create: `app/Services/Syllabus/StageImport/StageImportPlanner.php` — `plan(StageSheet $sheet, SyllabusCurriculum $curriculum, ?SyllabusStage $stage): StageImportPlan` applying every mapping rule in Global Constraints; warnings: curriculum label in D3 differs from curriculum title/code ("Ô Giáo trình trong file là …"), stage has open classes ("N lớp đang học chặng này — nội dung mới áp dụng ngay"); error "File chưa có buổi nào (dòng ví dụ nền xám đã được bỏ qua)." when no rows.
- Test: `tests/Feature/SyllabusStageImportPlannerTest.php`

**Interfaces:** Consumes `StageSheet`/`StageSheetRow` (Task 1). Produces `StageImportPlanner::plan(...)`, `StageImportPlan`.

- [ ] **Step 1: Write failing tests** (build `StageSheet` objects directly):

```php
public function test_maps_types_milestones_units_and_titles(): void
{
    $plan = $this->planner->plan($this->sheet([
        $this->row(11, type: 'Học', unit: 'Unit 1: My New School', content: "Getting started\nx", ppt: 'link PPT'),
        $this->row(12, type: 'mini test', content: '', milestone: 'Order in ấn mini test'),
    ]), $this->curriculum, null);
    $this->assertTrue($plan->ok());
    $this->assertSame(['lesson', 'mini_test'], array_column(array_column($plan->lessons, 'attributes'), 'lesson_type'));
    $this->assertSame('Getting started', $plan->lessons[0]['attributes']['title']);
    $this->assertSame('Mini test', $plan->lessons[1]['attributes']['title']);
    $this->assertNull($plan->lessons[0]['attributes']['ppt_url']);
    $this->assertSame('order_mini_test', $plan->lessons[1]['attributes']['notice_milestone']);
    $this->assertSame(1, $plan->lessons[1]['unit_number']);
}

public function test_row_errors(): void
{
    $plan = $this->planner->plan($this->sheet([
        $this->row(11, content: 'trước unit'),                                  // no unit yet
        $this->row(12, unit: 'Bài 2', content: 'x'),                            // bad unit cell
        $this->row(13, unit: 'Unit 3: A', type: 'Thi', ppt: 'ftp:/x', milestone: 'Gọi PH'),
    ]), $this->curriculum, null);
    $this->assertFalse($plan->ok());
    $this->assertEqualsCanonicalizing([11, 12, 13, 13, 13], array_column($plan->errors, 'line'));
}

public function test_update_matches_lessons_by_order_and_deletes_extra(): void
{
    // stage with 3 lessons (session 1,2,3); sheet with 2 rows → lessons[0].lesson_id = first id, [1] = second id, deleteLessonIds = [third id]
}

public function test_unit_number_owned_by_other_stage_is_error(): void { /* unit 1 exists in stage 2; importing into stage 1 a row 'Unit 1: …' → error */ }

public function test_warns_about_open_classes_and_curriculum_mismatch(): void { /* open assignment on stage → warning contains '1 lớp đang học' ; D3 'Khác' → warning */ }

public function test_empty_sheet_is_error(): void { /* rows [] → error message 'File chưa có buổi nào' */ }
```

- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run, expect PASS.**
- [ ] **Step 5: Commit** `feat: plan syllabus stage import against existing stage`

---

### Task 3: Importer — apply plan, renumber, audit summary

**Files:**
- Create: `app/Services/Syllabus/StageImport/StageImporter.php` — `import(StageImportPlan $plan, SyllabusCurriculum $curriculum, User $user): SyllabusStage`. In `DB::transaction`: create stage at `max(position)+1` when `stageId` null (name = `stageName`), else rename stage only if the file's name is non-empty; upsert units (`stage_id` = target stage); update lessons by `lesson_id`, create new ones (`curriculum_id`, `unit_id`), soft-delete `deleteLessonIds`; then `renumberSessions(int $curriculumId): void` (public, two-pass). Wrap model writes with `activity()->withoutLogs(...)` (or the package's `disableLogging`) and write **one** activity: log `Giáo trình & Syllabus`, subject = stage, causer = user, description `Import chặng từ file: {create} thêm, {update} sửa, {delete} xóa`, properties `curriculum_id`.
- Test: `tests/Feature/SyllabusStageImporterTest.php`

**Interfaces:** Consumes `StageImportPlan` (Task 2). Produces `StageImporter::import(...)`, `StageImporter::renumberSessions(int)`.

- [ ] **Step 1: Write failing tests:**

```php
public function test_new_stage_is_appended_and_sessions_renumbered(): void
{
    // curriculum: stage1 with lessons 1..2; import plan (new stage, 3 lessons, unit 2)
    // assert stage position 2, lessons of stage2 have session_no 3,4,5, unit 2 belongs to stage2
}

public function test_update_keeps_lesson_ids_and_soft_deletes_extra(): void
{
    // stage1 with 3 lessons + a lesson document + a change proposal on lesson 1; plan with 2 rows
    // ids of first two unchanged with new titles; third soft-deleted; proposal->lesson_id still resolves; stage2 lessons renumbered to continue from 3
}

public function test_import_writes_one_history_row(): void
{
    // Activity count for log 'Giáo trình & Syllabus' increases by exactly 1, description starts 'Import chặng từ file'
}

public function test_reimporting_same_plan_is_idempotent(): void
{
    // import twice from same sheet → second summary create=0 delete=0, lesson count unchanged
}
```

- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run, expect PASS:** this test + `SyllabusLibraryHistoryTest`, `SyllabusStageProgressionTest`.
- [ ] **Step 5: Commit** `feat: apply syllabus stage import and renumber sessions`

---

### Task 4: Controller, routes, UI, docs

**Files:**
- Modify: `app/Services/Syllabus/Import/SyllabusImportStore.php` — `put(UploadedFile $file, User $user, array $visibility, array $context = []): string` stores `context` in the session meta; `get()` returns it (default `[]`). Existing callers unchanged.
- Create: `app/Http/Controllers/SyllabusStageImportController.php`:
  - `template()` → download `mau-syllabus-chang.xlsx` from `StageImportTemplate::build()` (deleteFileAfterSend).
  - `preview(Request $request, int $curriculum)` — validate `file` (`required|file|max:20480|extensions:xlsx`), `stage_id` (`nullable`, exists in this curriculum, not deleted); store with context `['curriculum_id' => …, 'stage_id' => …]`; read once to fail fast (`StageImportException` → back with `file` error); redirect to `show`.
  - `show(Request $request, int $curriculum)` — `?token=`; reload sheet + plan; render `Syllabus/StageImport` with `curriculum {id,title,code}`, `stage {id,label}|null`, `plan` (`summary`, `errors`, `warnings`, `lessons` → rows `line, unit_number, title, lesson_type label, action (Thêm|Sửa)`, `deletions` → titles), `token`. Expired token → redirect to curriculum detail with error.
  - `confirm(Request $request, int $curriculum)` — reload plan; not ok → back with error; `StageImporter::import`; forget token; redirect `syllabus.curriculums.show` (`tab=stages`) with status `Đã import {stage label}: {create} thêm, {update} sửa, {delete} xóa.`
- Modify: `routes/web.php` (inside `syllabus.` group, `can:syllabus.library`): `GET /stage-import/template` `stage-import.template`; `POST /curriculums/{curriculum}/stage-import` `stage-import.preview`; `GET /curriculums/{curriculum}/stage-import` `stage-import.show`; `POST /curriculums/{curriculum}/stage-import/confirm` `stage-import.confirm` (`whereNumber('curriculum')`). Ziggy regenerate.
- Create: `resources/js/Pages/Syllabus/StageImport.vue` — header "Import chặng từ file" with `:back` to the curriculum detail; warnings (`UiAlert warning`), errors table (Dòng · Lỗi), summary badges, lessons table, deletions list; buttons Xác nhận import (disabled when errors) / Tải file khác.
- Create: `resources/js/Components/Syllabus/StageImportModal.vue` — props `show`, `curriculumId`, `stage` (`{id,label}|null`); file input + link "Tải file mẫu"; posts `stage-import.preview`.
- Modify: `resources/js/Pages/Syllabus/CurriculumShow.vue` — stage header button "Import từ file" (update that stage), section header button "Thêm chặng từ file" (new stage).
- Modify: `docs/import-syllabus.md` — add section "Import từng chặng (form Sheet 1)" with the rules from Global Constraints.
- Test: `tests/Feature/SyllabusStageImportPageTest.php`

**Interfaces:** Consumes Tasks 1–3, `SyllabusImportStore`.

- [ ] **Step 1: Write failing tests:**

```php
public function test_full_flow_new_stage(): void
{
    $file = new UploadedFile($this->filledTemplatePath(), 'chang-2.xlsx', null, null, true);
    $resp = $this->actingAs($this->lead)->post(route('syllabus.stage-import.preview', $this->curriculum->id), ['file' => $file]);
    $resp->assertRedirect();
    $show = $this->actingAs($this->lead)->get($resp->headers->get('Location'))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->component('Syllabus/StageImport')->where('plan.summary.create', 2));
    $token = /* from Location query */;
    $this->actingAs($this->lead)->post(route('syllabus.stage-import.confirm', $this->curriculum->id), ['token' => $token])
        ->assertRedirect(route('syllabus.curriculums.show', ['id' => $this->curriculum->id, 'tab' => 'stages']));
    $this->assertSame(2, $this->curriculum->stages()->count());
}

public function test_errors_block_confirm(): void { /* file with bad UNIT → confirm redirects back with errors, no DB change */ }

public function test_academic_staff_forbidden(): void { /* academic_staff → preview 403, template 403 */ }

public function test_template_downloads(): void { /* GET template → 200, content-disposition contains mau-syllabus-chang.xlsx */ }

public function test_stage_of_other_curriculum_rejected(): void { /* stage_id from other curriculum → session error stage_id */ }
```

- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run, expect PASS:** this test + `SyllabusImportPageTest`, `SyllabusImportStoreTest`, `SyllabusImporterTest`, `ZiggyRoutesTest`, `AllRoutesSmokeTest`.
- [ ] **Step 5: Commit** `feat: import a syllabus stage from the Sheet 1 form`

---

### Task 5: Wrap-up (end of Phase 1)

- [ ] Guard tests: `NavigationPermissionTest`, `NavigationMenuPermissionTest`, `WorkspaceNavigationTest`, `RbacNoHardcodedRolesTest`, `AllRoutesSmokeTest`, `BranchIsolationRouteSweepTest`, `ZiggyRoutesTest`, `DemoPhase1SeederTest`…`DemoPhase4SeederTest`, `Phase1..4AcceptanceTest`, `FullBpmnSmokeTest`.
- [ ] `docs/kho-syllabus.md`: tick Sprint 3; status "Giai đoạn 1 — Hoàn thành"; commit `docs: mark syllabus library phase 1 done`.
- [ ] `v2/tools/parity.sh origin/main`; note in PR.
- [ ] Final whole-branch review; push; open PR; send link.
