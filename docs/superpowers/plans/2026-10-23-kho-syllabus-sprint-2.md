# Kho syllabus — Sprint 2 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Documents attach to a lesson, a stage or the whole curriculum and are managed on the curriculum detail page; teachers only see documents of their classes' open stages; the detail page gets a change-history tab.

**Architecture:** One migration adds `syllabus_documents.syllabus_lesson_id`. Upload/delete move to `CurriculumShow.vue` through one shared modal component; the standalone Documents page is removed. Visibility for non-library users is narrowed in `SyllabusDocument::scopeVisibleTo`. History uses spatie activitylog via `AuditsChanges`, tagged with `curriculum_id`.

**Tech Stack:** Laravel 13, spatie/laravel-activitylog, Inertia 3 + Vue 3, PHPUnit.

**Spec:** `docs/kho-syllabus.md` §3.1 (Tài liệu chung, upload modal), §3.2 (`syllabus_lesson_id`), §3.4 (teacher scope, Lịch sử). Builds on Sprint 1 plan `2026-10-23-kho-syllabus-sprint-1.md` (routes `syllabus.curriculums.index|show`, permission `syllabus.library`, page `Syllabus/CurriculumShow`).

## Global Constraints

- Same as Sprint 1 (nullable columns only, no drops; `Roles::*`; ziggy after routes; Docker tests; no `public/build`).
- Document scope rule: `syllabus_lesson_id` set → lesson document (its `stage_id` is also set to the lesson's stage); `stage_id` set, no lesson → stage document; neither → "Tài liệu chung".
- Teacher / TA scope (no `syllabus.library`): curriculum + (shared OR stage = open stage) for every open `SyllabusAssignment` whose class has the user as `teacher_id`, `assistant_id` or `foreign_teacher_id`, or whose `user_id` is the user; existing `visible_to_teachers` / `visible_to_assistants` flags still apply. Out of scope → 404.
- Audit log name: `Giáo trình & Syllabus` (already used by `ActivityLogController` filters and `AuditOperationMiddleware`).

## Review Focus

1. A lesson document's scope after the lesson is moved to a unit in another stage — the document must follow the lesson's current stage for teacher visibility (store only `syllabus_lesson_id` as truth; derive stage at query time via the lesson, or update `stage_id` on lesson move). Task 2 test.
2. A teacher who teaches two classes on different curricula sees both open stages' documents, not only the first class's. Task 2 test.
3. Deleting a lesson (soft delete) leaves its documents reachable for library users on the stage, and hidden for teachers. Task 1 test.
4. Uploading with a `syllabus_lesson_id` from another curriculum → validation error, nothing stored. Task 1 test.
5. History tab of a curriculum does not show changes of another curriculum. Task 3 test.

---

### Task 1: Documents per lesson / stage / shared on the detail page; remove Documents page

**Files:**
- Create: `database/migrations/2026_11_20_090200_add_lesson_to_syllabus_documents.php` (`foreignId('syllabus_lesson_id')->nullable()->after('stage_id')->constrained('syllabus_lessons')->nullOnDelete()`; index)
- Modify: `app/Models/SyllabusDocument.php` (fillable, `lesson()` belongsTo `SyllabusLesson`, `syllabus_lesson_id`), `app/Models/SyllabusLesson.php` (`documents()` hasMany)
- Modify: `app/Http/Controllers/SyllabusController.php`:
  - `storeDocument`: add rule `syllabus_lesson_id` → `nullable`, `Rule::exists('syllabus_lessons','id')->whereNull('deleted_at')->where('curriculum_id', input curriculum_id)`; when set, `stage_id` := lesson's unit's `stage_id`. Redirect → `back()` (the detail page) with the same flash.
  - `show()`: add props `documents` = `['shared' => [...], 'stages' => [stage_id => [...]], 'lessons' => [lesson_id => [...]]]` built from one query `SyllabusDocument::where('curriculum_id', $id)->with('uploader:id,name')->latest()`; row via new `private function documentRow(SyllabusDocument $doc): array` = `id, title, icon, extension, size_human, audience_labels, downloadable, view_url (route documents.file), download_url (…, download=1)`. Tab values become `info | stages | documents | history` (history used in Task 3).
  - Delete `documents()` action; route `GET /syllabus/documents` → `fn () => redirect()->route('syllabus.curriculums.index')` keeping name `syllabus.documents` (middleware `can:syllabus.library`).
- Create: `resources/js/Components/Syllabus/DocumentUploadModal.vue` — props `show: Boolean`, `curriculumId: Number`, `stageId: Number|null`, `lessonId: Number|null`, `scopeLabel: String`; emits `close`. Form fields moved from `Documents.vue` upload modal (title, audience checkboxes, downloadable, file) + hidden `curriculum_id`, `stage_id`, `syllabus_lesson_id`; posts `syllabus.documents.store`; `reset-on-success`, closes on success.
- Create: `resources/js/Components/Syllabus/DocumentList.vue` — props `documents: Array`, `canManage: Boolean`; rows: icon, title, extension · size, audience badges; actions Xem (`view_url`, native link new tab), Tải (`download_url` if downloadable), Xóa (`UiForm` delete `syllabus.documents.destroy`, confirm). Empty text "Chưa có tài liệu".
- Modify: `resources/js/Pages/Syllabus/CurriculumShow.vue` — stage card: "Tài liệu chặng" `DocumentList` + "Tải tài liệu" button; expanded lesson: "Tài liệu buổi" list + button; new tab **Tài liệu chung** (`tab=documents`) with list + button. One `DocumentUploadModal` instance, opened with the target scope.
- Delete: `resources/js/Pages/Syllabus/Documents.vue`.
- Modify: `app/Support/Navigation/SidebarMenu.php` (remove `Tài liệu` item), `app/Http/Controllers/AcademicSystemController.php` (`01_quan_ly_tai_lieu_giao_trinh` → `syllabus.curriculums.index`), `resources/js/Pages/AcademicSystem/ScreenViewer.vue:29` (link → `syllabus.curriculums.index`).
- Test: `tests/Feature/SyllabusLibraryDocumentsTest.php` (new); update tests that GET `syllabus.documents` expecting a page (`Phase2SyllabusTest`, `Phase2MockupSyllabusTest`, `SyllabusNavigationCleanupTest`, `AcademicSystemTest` — grep `syllabus.documents'`/`/syllabus/documents`).

**Interfaces:**
- Produces: `documentRow()` shape above; props `documents.shared|stages|lessons`; components `DocumentUploadModal`, `DocumentList`; `SyllabusDocument::lesson()`.

- [ ] **Step 1: Write failing tests:**

```php
public function test_upload_to_lesson_sets_lesson_and_its_stage(): void
{
    Storage::fake(SyllabusDocument::DISK);
    $this->actingAs($this->lead)->post(route('syllabus.documents.store'), [
        'curriculum_id' => $this->curriculum->id, 'syllabus_lesson_id' => $this->lesson->id, 'title' => 'PPT buổi 1',
        'visible_to_teachers' => 1, 'file' => UploadedFile::fake()->create('b1.pdf', 10, 'application/pdf'),
    ])->assertSessionHasNoErrors();
    $doc = SyllabusDocument::firstOrFail();
    $this->assertSame($this->lesson->id, $doc->syllabus_lesson_id);
    $this->assertSame($this->lesson->unit->stage_id, $doc->stage_id);
}

public function test_upload_rejects_lesson_of_other_curriculum(): void
{
    // second curriculum + lesson; post with curriculum_id = first, syllabus_lesson_id = other lesson
    // ->assertSessionHasErrors('syllabus_lesson_id'); assertDatabaseCount('syllabus_documents', 0)
}

public function test_detail_page_groups_documents_by_scope(): void
{
    // create 3 docs: shared, stage-only, lesson; GET show ?tab=documents
    // assertInertia: documents.shared has 1, documents.stages.{stageId} has 1 (stage-only + lesson doc? -> only stage-only), documents.lessons.{lessonId} has 1
}

public function test_documents_url_redirects_to_library(): void
{
    $this->actingAs($this->lead)->get(route('syllabus.documents'))->assertRedirect(route('syllabus.curriculums.index'));
}

public function test_soft_deleted_lesson_documents_stay_on_stage_for_library(): void
{
    // lesson doc; delete lesson; GET show -> doc appears under documents.stages.{stageId} (lesson gone from tree)
}
```

Grouping rule for `show()`: a document goes to `lessons[lesson_id]` when its lesson exists (not deleted), otherwise to `stages[stage_id]` when set, otherwise `shared`.

- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement** the file list; ziggy regenerate.
- [ ] **Step 4: Run, expect PASS:** `SyllabusLibraryDocumentsTest`, `SyllabusLibraryTest`, `Phase2SyllabusTest`, `Phase2MockupSyllabusTest`, `SyllabusNavigationCleanupTest`, `AcademicSystemTest`, `NavigationPermissionTest`, `ZiggyRoutesTest`, import tests, `DemoPhase2SeederTest`.
- [ ] **Step 5: Commit** `feat: manage syllabus documents per lesson, stage and curriculum`

---

### Task 2: Teachers only see documents of their open stages

**Files:**
- Modify: `app/Models/SyllabusDocument.php::scopeVisibleTo` — non-library branch: keep the teacher/assistant flag filter AND add `whereExists` on `syllabus_assignments` (open: `closed_at` null — reuse `SyllabusAssignment::open()` semantics; check the scope's definition) joined to `classes` where `syllabus_assignments.curriculum_id = syllabus_documents.curriculum_id` and (`syllabus_documents.stage_id` is null OR = `syllabus_assignments.stage_id`) and (`classes.teacher_id|assistant_id|foreign_teacher_id` = user OR `syllabus_assignments.user_id` = user). `userManages()` already → `syllabus.library` (Sprint 1).
- Check: `app/Http/Controllers/SyllabusController.php` — no change expected (`teacherView`, `documentFile`, `markDocumentViewed` already go through `visibleTo` / `isVisibleTo`); verify `teacherView` list query uses `visibleTo`.
- Test: `tests/Feature/SyllabusLibraryDocumentsTest.php`; update existing teacher-document tests that relied on unrestricted visibility (create an open assignment for the teacher's class in their setup).

- [ ] **Step 1: Write failing tests:**

```php
public function test_teacher_sees_only_documents_of_open_stage_of_own_class(): void
{
    // curriculum with stage1 (open for $class taught by $teacher) and stage2; docs: shared, stage1, stage2, lesson-in-stage1 (all visible_to_teachers)
    $ids = SyllabusDocument::visibleTo($this->teacher)->pluck('id')->all();
    // assertEqualsCanonicalizing([$shared, $stage1, $lessonDoc], $ids)
    $this->actingAs($this->teacher)->get(route('syllabus.documents.file', $stage2Doc->id))->assertNotFound();
}

public function test_teacher_without_open_stage_sees_nothing(): void { /* no assignment → visibleTo empty */ }

public function test_teacher_of_two_classes_sees_both_open_stages(): void { /* two curricula, two classes → docs of both */ }

public function test_lesson_moved_to_another_stage_follows_new_stage(): void
{
    // lesson doc in stage1; update lesson unit_id to a unit of stage2 → teacher (stage1 open) no longer sees it
}
```

For the last test: in `updateLesson`, when `unit_id` changes stage, update `stage_id` of that lesson's documents (`$lesson->documents()->update(['stage_id' => $newStageId])`).

- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run, expect PASS:** `SyllabusLibraryDocumentsTest`, `Phase2SyllabusTest`, `Phase2MockupSyllabusTest`, `SyllabusStageProgressionTest`, `BranchIsolationRouteSweepTest`, PDF-annotator tests (`ls tests/Feature | grep -i -E 'annot|pdf'`).
- [ ] **Step 5: Commit** `feat: limit teacher syllabus documents to their open stages`

---

### Task 3: Change history tab

**Files:**
- Modify: `app/Models/SyllabusCurriculum.php`, `SyllabusStage.php`, `SyllabusUnit.php`, `SyllabusLesson.php`, `SyllabusDocument.php` — `use AuditsChanges;` and `use LogsCurriculumId;`
- Create: `app/Models/Concerns/LogsCurriculumId.php` — `public function tapActivity(\Spatie\Activitylog\Contracts\Activity $activity, string $eventName): void` sets `$activity->properties = $activity->properties->put('curriculum_id', $this instanceof SyllabusCurriculum ? $this->id : $this->curriculum_id)`. (Check `AuditsChanges`/`LogsActivity` doesn't already define `tapActivity`; if a model already has one, merge.)
- Modify: `app/Support/Audit.php::MODELS` — 5 entries, `'log' => 'Giáo trình & Syllabus'`, labels `giáo trình` / `chặng` / `unit` / `buổi học` / `tài liệu giáo trình`, `'undo' => []`.
- Modify: `SyllabusController::show` — when `tab === 'history'`, prop `history` = paginator (20) of `Activity::where('log_name', 'Giáo trình & Syllabus')->where('properties->curriculum_id', $id)->with('causer:id,name')->latest()`; rows `at (Y-m-d H:i), causer, event (created|updated|deleted → Tạo|Sửa|Xóa), subject (Audit label + name/title/label of subject or '#id'), changes: list of {field, old, new}` from `properties.old` / `properties.attributes` (skip fields in `Audit::NEVER_LOG`). Only load when the tab is open.
- Modify: `CurriculumShow.vue` — tab **Lịch sử** with a table (Thời gian · Người sửa · Thao tác · Đối tượng · Thay đổi) and `UiPagination`.
- Test: `tests/Feature/SyllabusLibraryHistoryTest.php`

- [ ] **Step 1: Write failing tests:**

```php
public function test_lesson_update_is_logged_with_curriculum_id(): void
{
    $this->actingAs($this->lead)->put(route('syllabus.lessons.update', $this->lesson->id), ['session_no' => 1, 'title' => 'Tên mới']);
    $this->actingAs($this->lead)->get(route('syllabus.curriculums.show', ['id' => $this->curriculum->id, 'tab' => 'history']))
        ->assertInertia(fn (AssertableInertia $p) => $p->where('history.data.0.event', 'Sửa')
            ->where('history.data.0.changes', fn ($c) => collect($c)->contains(fn ($x) => $x['field'] === 'title' && $x['new'] === 'Tên mới')));
}

public function test_history_is_scoped_to_curriculum(): void
{
    // edit a lesson of another curriculum; history of $this->curriculum has no row for it
}
```

- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run, expect PASS:** `SyllabusLibraryHistoryTest`, `ActivityLog*Test` (`ls tests/Feature | grep -i activ`), `SyllabusLibraryTest`, `SyllabusLibraryDocumentsTest`, `Phase2MockupSyllabusTest`, `SyllabusImporterTest` (import now writes many audit rows — fine; Sprint 3 adds the summary row).
- [ ] **Step 5: Commit** `feat: syllabus change history tab`

---

### Task 4: Wrap-up

- [ ] Guard tests: `NavigationPermissionTest`, `NavigationMenuPermissionTest`, `WorkspaceNavigationTest`, `RbacNoHardcodedRolesTest`, `AllRoutesSmokeTest`, `BranchIsolationRouteSweepTest`, `ZiggyRoutesTest`.
- [ ] Tick Sprint 2 in `docs/kho-syllabus.md`; commit `docs: mark syllabus library sprint 2 done`.
