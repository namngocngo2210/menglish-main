# Kho syllabus — Sprint 1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the Sheet 1 lesson/stage fields, the `syllabus.library` permission, and replace "Soạn syllabus" with a Kho syllabus list → curriculum detail page.

**Architecture:** One migration adds nullable columns. A new permission guards every route that edits the original syllabus. `SyllabusController::builder` becomes `show($id)` rendering `Syllabus/CurriculumShow.vue` (the current Builder page plus Thông tin / Chặng & buổi tabs). A new `curriculums()` action renders `Syllabus/Curriculums.vue`. The old builder URL redirects. Editor modal (PR #195) is kept as is.

**Tech Stack:** Laravel 13 / PHP 8.4, Spatie permission, Inertia 3 + Vue 3 (`<script setup>`, plain JS), PHPUnit feature tests with SSR HTML.

**Spec:** `docs/kho-syllabus.md` (sections 3.1, 3.2, 3.4 — Sprint 1 items)

## Global Constraints

- New columns are nullable or have defaults; do not drop or rename any existing column (v2 CI runs Laravel migrations + `import-legacy`).
- `lesson_type` ∈ `lesson`, `mini_test`, `big_test` (default `lesson`); `notice_milestone` ∈ `order_print`, `order_mini_test`, `order_big_test` or null; `notice_before_sessions` default 6.
- Vietnamese labels: Loại buổi = Học / Mini test / Big test; Mốc thông báo = Order in ấn / Order mini test / Order Big Test; `homework_guide` label becomes "Bài tập giấy"; `content` label becomes "Content (nội dung từng tiết)".
- `syllabus.library` granted to `manager` and `academic_lead`; Admin via Super Admin. `academic_staff` keeps `syllabus.manage` (assignments, Big Test) but loses original-syllabus editing.
- Never compare role names as strings in app code (`Roles::*`); RbacNoHardcodedRolesTest.
- After changing `routes/web.php`: `php artisan ziggy:generate resources/js/ziggy.js`. After changing permission config: regenerate `v2/api/internal/iam/catalog/catalog.json` (`php v2/api/internal/iam/catalog/export.php > v2/api/internal/iam/catalog/catalog.json`).
- Tests in Docker from the worktree `/home/menglish/wt-kho-syllabus` (see CLAUDE.md "Running tests on this server"); never commit `public/build/`.
- Commit identity: Ngo Ngoc Nam <namngocngo2210@gmail.com>; one-line English messages.

## Review Focus

1. Approval inbox / Versions "Mở buổi để cập nhật" (`edit_lesson_url`) → opens the detail page with the lesson editor modal, not a 404 or a redirect loop. (Task 3 test)
2. Học vụ (`academic_staff`) opening an old bookmark `/syllabus/builder?curriculum=X` → 403, and their sidebar no longer shows Kho syllabus; they still reach Giao chặng and Big Test pages. (Task 2 / Task 4 tests)
3. Saving a lesson without the new fields (old forms, Phase 2 seeders) still works — all new fields optional, `lesson_type` defaults to `lesson`. (Task 1 test)
4. Detail page for a soft-deleted curriculum id → 404. (Task 3 test)
5. Curriculum list counts exclude soft-deleted stages/lessons and show 0 open classes for unused curricula without N+1 queries. (Task 3 test)

---

### Task 1: Sheet 1 fields on lessons and stages

**Files:**
- Create: `database/migrations/2026_11_20_090000_add_sheet1_fields_to_syllabus_lessons_and_stages.php` (pick a timestamp later than the newest file in `database/migrations/`)
- Modify: `app/Models/SyllabusLesson.php` (fillable + constants), `app/Models/SyllabusStage.php` (fillable, cast)
- Modify: `app/Http/Controllers/SyllabusController.php` — `lessonRules()`, `stageRules()`, `lessonData()`, `editorData()` stage model
- Modify: `resources/js/Pages/Syllabus/Builder.vue` (lesson + stage editor fields; lesson card shows type badge and new fields; `lessonFields` list)
- Test: `tests/Feature/SyllabusLibraryTest.php` (new)

**Interfaces:**
- Produces: `SyllabusLesson::TYPES = ['lesson' => 'Học', 'mini_test' => 'Mini test', 'big_test' => 'Big test']`, `SyllabusLesson::MILESTONES = ['order_print' => 'Order in ấn', 'order_mini_test' => 'Order mini test', 'order_big_test' => 'Order Big Test']`; columns listed in spec 3.2; `lessonData()` returns all of them plus `type_label`.

- [ ] **Step 1: Write failing tests** (`SyllabusLibraryTest`, setUp: branch + `academic_lead` user + curriculum with one unit as in `SyllabusNavigationCleanupTest::curriculumWithLesson`):

```php
public function test_lesson_saves_sheet1_fields(): void
{
    $this->actingAs($this->lead)->post(route('syllabus.lessons.store'), [
        'unit_id' => $this->unit->id, 'session_no' => 2, 'title' => 'Mini test Unit 1',
        'lesson_type' => 'mini_test', 'procedure' => 'Warm-up', 'activity_1' => 'Slap the board', 'activity_2' => 'Role-play',
        'homework_guide' => 'Workbook p4', 'homework_quizizz' => 'Vocab U1', 'homework_video' => 'Video 30s',
        'ppt_url' => 'https://example.com/ppt', 'quizizz_url' => 'https://quizizz.com/x', 'output_product' => '10 từ',
        'notice_milestone' => 'order_print', 'note' => 'In trước 3 buổi',
    ])->assertSessionHasNoErrors();
    $this->assertDatabaseHas('syllabus_lessons', ['session_no' => 2, 'lesson_type' => 'mini_test', 'notice_milestone' => 'order_print', 'quizizz_url' => 'https://quizizz.com/x']);
}

public function test_lesson_without_new_fields_defaults_to_normal_lesson(): void
{
    $this->actingAs($this->lead)->post(route('syllabus.lessons.store'), ['unit_id' => $this->unit->id, 'session_no' => 3, 'title' => 'Buổi 3'])
        ->assertSessionHasNoErrors();
    $this->assertSame('lesson', SyllabusLesson::where('session_no', 3)->value('lesson_type'));
}

public function test_lesson_rejects_unknown_type_milestone_and_bad_urls(): void
{
    $this->actingAs($this->lead)->post(route('syllabus.lessons.store'), [
        'unit_id' => $this->unit->id, 'session_no' => 4, 'title' => 'X',
        'lesson_type' => 'exam', 'notice_milestone' => 'order_food', 'ppt_url' => 'not a url',
    ])->assertSessionHasErrors(['lesson_type', 'notice_milestone', 'ppt_url']);
}

public function test_stage_saves_notice_before_sessions(): void
{
    $stage = $this->curriculum->stages()->firstOrFail();
    $this->actingAs($this->lead)->put(route('syllabus.stages.update', $stage->id), ['name' => 'Chặng 1', 'notice_before_sessions' => 4])
        ->assertSessionHasNoErrors();
    $this->assertSame(4, $stage->fresh()->notice_before_sessions);
    $this->actingAs($this->lead)->put(route('syllabus.stages.update', $stage->id), ['name' => 'Chặng 1', 'notice_before_sessions' => 0])
        ->assertSessionHasErrors('notice_before_sessions');
}
```

- [ ] **Step 2: Run, expect FAIL** (unknown columns / no validation errors).
- [ ] **Step 3: Implement.** Migration per spec 3.2 (lessons: `lesson_type` string(20) default 'lesson'; `procedure`, `activity_1`, `activity_2`, `homework_quizizz`, `homework_video`, `output_product`, `note` text nullable; `ppt_url`, `quizizz_url` string(500) nullable; `notice_milestone` string(30) nullable. stages: `notice_before_sessions` unsignedSmallInteger default 6), with `down()`. Rules: `lesson_type` `nullable|in:` keys of TYPES (store `?? 'lesson'`), `notice_milestone` `nullable|in:` keys of MILESTONES, urls `nullable|url|max:500`, texts `nullable|string`; stage `notice_before_sessions` `nullable|integer|min:1|max:50` (null → keep default). Builder.vue: lesson editor adds a Loại buổi select, Quy trình, Hoạt động gợi ý 1/2, three homework boxes (Bài tập giấy = `homework_guide`), Link PPT / Quizizz, Sản phẩm đầu ra, Mốc thông báo select, Ghi chú; stage editor adds "Báo trước (buổi)" number input; lesson card shows a type badge when not `lesson`.
- [ ] **Step 4: Run, expect PASS:** `SyllabusLibraryTest`, `SyllabusStageProgressionTest`, `Phase2MockupSyllabusTest`, `SyllabusNavigationCleanupTest`, `SyllabusImportTest*` (whatever `tests/Feature/*Import*Syllabus*`/`Syllabus*Import*` exist), `DemoPhase2SeederTest`.
- [ ] **Step 5: Commit** `feat: add Sheet 1 fields to syllabus lessons and stages`

---

### Task 2: `syllabus.library` permission

**Files:**
- Modify: `config/permission_catalog.php` (`syllabus.actions.library => ['Kho syllabus', 'Xem & sửa syllabus gốc: giáo trình, chặng, unit, buổi, tài liệu, import.']`), `config/access.php` (`manager` list: add `syllabus.library`; `academic_lead` already `syllabus.*`)
- Create: `database/migrations/2026_11_20_090100_grant_syllabus_library_permission.php` (pattern: `2026_10_20_150100_grant_confirm_deposit_permission.php`; ROLES = `Roles::MANAGER`, `Roles::ACADEMIC_LEAD`)
- Modify: `routes/web.php` — `can:syllabus.manage` → `can:syllabus.library` on builder, import group, curriculums/units/stages/lessons store/update/destroy/move; `can:syllabus.upload` → `can:syllabus.library` on documents store/destroy. Builder GET (`syllabus.builder`) gets `can:syllabus.library`.
- Modify: `app/Http/Controllers/SyllabusController.php` (`canManage`/`canUpload` props of Builder + Documents + `editor` gate use `syllabus.library`; Versions `canManage` for "Mở buổi" button uses `syllabus.library`), `app/Http/Controllers/SyllabusImportController.php` (`canUpload` → `syllabus.library`, docblock), `app/Models/SyllabusDocument.php::userManages()` → `$user->can('syllabus.library')`
- Modify: `v2/api/internal/iam/catalog/catalog.json` (regenerated)
- Test: `tests/Feature/SyllabusLibraryTest.php`

**Interfaces:** Produces permission name `syllabus.library` used by Tasks 3–4.

- [ ] **Step 1: Write failing tests:**

```php
public function test_library_permission_granted_to_lead_and_manager_only(): void
{
    $this->assertTrue($this->userWithRole(Roles::ACADEMIC_LEAD)->can('syllabus.library'));
    $this->assertTrue($this->userWithRole(Roles::MANAGER)->can('syllabus.library'));
    $this->assertFalse($this->userWithRole(Roles::ACADEMIC_STAFF)->can('syllabus.library'));
    $this->assertFalse($this->userWithRole(Roles::TEACHER_FULLTIME)->can('syllabus.library'));
}

public function test_academic_staff_cannot_edit_original_syllabus_but_keeps_assignments(): void
{
    $staff = $this->userWithRole(Roles::ACADEMIC_STAFF);
    $this->actingAs($staff)->post(route('syllabus.curriculums.store'), ['code' => 'X1', 'title' => 'X', 'version' => 'v1'])->assertForbidden();
    $this->actingAs($staff)->post(route('syllabus.lessons.store'), ['unit_id' => $this->unit->id, 'session_no' => 9, 'title' => 'X'])->assertForbidden();
    $this->actingAs($staff)->get(route('syllabus.import'))->assertForbidden();
    $this->actingAs($staff)->get(route('syllabus.assignments'))->assertOk();
}
```

(`userWithRole(string $role): User` — private helper in the test: active user in the test branch with that role.) If roles in `TestBaseSeeder` are seeded from config before the migration runs, verify the grant reaches `manager` through `config/access.php`; the migration covers existing production roles.

- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement** the file list above; regenerate ziggy and v2 catalog.
- [ ] **Step 4: Run, expect PASS:** `SyllabusLibraryTest`, `RbacNoHardcodedRolesTest`, `NavigationPermissionTest`, `NavigationMenuPermissionTest`, `SyllabusModuleTest`, `Phase2MockupSyllabusTest`, `Phase2AcceptanceTest`, `SyllabusStageProgressionTest`, import tests, `DemoPhase2SeederTest`, any `*Permission*`/`*Catalog*` test (`ls tests/Feature | grep -i -E 'permission|catalog|access'`). Fix tests that used an `academic_staff` user to edit the original syllabus by switching them to `academic_lead` — never by re-granting the permission.
- [ ] **Step 5: Commit** `feat: add syllabus.library permission for the original syllabus`

---

### Task 3: Kho syllabus list and curriculum detail page

**Files:**
- Modify: `routes/web.php` — inside the `syllabus.` group: `GET /curriculums` → `curriculums` name `curriculums.index`; `GET /curriculums/{id}` (whereNumber) → `show` name `curriculums.show`; both `can:syllabus.library`. `GET /builder` → `builderRedirect` (keep name `builder`).
- Modify: `app/Http/Controllers/SyllabusController.php`:
  - `curriculums(Request $request)` → `Inertia::render('Syllabus/Curriculums', ['curriculums' => paginator, 'search' => string, 'courses', 'levels', 'newCode'])`. Rows: `id, code, title, version, course (name|null), levels (comma names), stages_count, lessons_count, open_classes_count, updated_at (Y-m-d), url`. Use `withCount(['stages', 'lessons', 'assignments as open_classes_count' => fn ($q) => $q->open()])` + `with(['course:id,name', 'levels:id,name,syllabus_curriculum_id'])`; search `code`/`title` like; `orderBy('title')`; `paginate($request->perPage(15))->withQueryString()`.
  - `show(Request $request, int $id)` = current `builder()` body with `findOrFail($id)` instead of the query-param selection, plus prop `tab` (`info` | `stages`, default `stages`). Drop the `curriculums` dropdown prop.
  - `builderRedirect(Request $request)`: `curriculum` filled → `redirect()->route('syllabus.curriculums.show', ['id' => …] + editor query params (new_stage, edit_stage, new_unit, edit_unit, new_lesson, edit_lesson))`; else → `syllabus.curriculums.index`.
  - Every `redirect()->route('syllabus.builder', ['curriculum' => X])` → `redirect()->route('syllabus.curriculums.show', X)`; `destroyCurriculum` → `syllabus.curriculums.index`; `storeCurriculum` → show of the new one. Same in `SyllabusImportController::confirm`. Versions `edit_lesson_url` → `route('syllabus.curriculums.show', ['id' => …, 'edit_lesson' => …])` (no `#editor`).
- Rename: `resources/js/Pages/Syllabus/Builder.vue` → `resources/js/Pages/Syllabus/CurriculumShow.vue` (`git mv`). Header: curriculum title + code · version, `:back="route('syllabus.curriculums.index')"`, actions Import từ file. Remove the curriculum-select section and the "Tạo giáo trình" modal (moved to list). Tabs bar (same markup as `TeacherView.vue` tabs) `Thông tin | Chặng & buổi`, switching via `router.get(showUrl({ tab }), {}, { preserveScroll: true })` or a `Link`; `builderUrl()` → `showUrl(params)` = `route('syllabus.curriculums.show', { id: curriculum.id, ...params })`. Thông tin tab = current info form + Xóa giáo trình; Chặng & buổi = current tree + editor modal.
- Create: `resources/js/Pages/Syllabus/Curriculums.vue` — `UiPageHeader title="Kho syllabus"`, actions: Import giáo trình mới (`syllabus.import`), Tạo giáo trình (modal moved from Builder, posts `syllabus.curriculums.store`). `UiDataTable` + `GetForm` search; columns per the controller rows; row link `row.url`; `UiEmptyState` when empty; `UiPagination`.
- Modify: `resources/js/Pages/Syllabus/Import.vue` (back + Hủy → `syllabus.curriculums.index`), `resources/js/Pages/Syllabus/Documents.vue` ("Tạo giáo trình" link → `syllabus.curriculums.index`).
- Test: `tests/Feature/SyllabusLibraryTest.php`; update `tests/Feature/SyllabusNavigationCleanupTest.php`, `SyllabusStageProgressionTest.php`, `Phase2MockupSyllabusTest.php`, `SyllabusModuleTest.php` where they GET `syllabus.builder` or assert redirects to it.

**Interfaces:** Consumes `syllabus.library` (Task 2). Produces route names `syllabus.curriculums.index`, `syllabus.curriculums.show` (param `id`, query `tab`, editor params), page components `Syllabus/Curriculums`, `Syllabus/CurriculumShow`.

- [ ] **Step 1: Write failing tests:**

```php
public function test_library_lists_curricula_with_counts(): void
{
    // curriculum from setUp has 1 stage, 1 lesson; add a soft-deleted lesson to prove it is not counted
    $this->actingAs($this->lead)->get(route('syllabus.curriculums.index'))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->component('Syllabus/Curriculums')
            ->where('curriculums.data.0.code', $this->curriculum->code)
            ->where('curriculums.data.0.lessons_count', 1)
            ->where('curriculums.data.0.open_classes_count', 0)
            ->where('curriculums.data.0.url', route('syllabus.curriculums.show', $this->curriculum->id)));
}

public function test_detail_page_renders_tabs_and_editor_from_query(): void
{
    $this->actingAs($this->lead)->get(route('syllabus.curriculums.show', ['id' => $this->curriculum->id, 'edit_lesson' => $this->lesson->id]))
        ->assertOk()->assertSee('Chặng &amp; buổi', false)->assertSee('form="syllabus-editor-form"', false);
    $this->actingAs($this->lead)->get(route('syllabus.curriculums.show', ['id' => $this->curriculum->id, 'tab' => 'info']))
        ->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('Syllabus/CurriculumShow')->where('tab', 'info'));
}

public function test_old_builder_url_redirects_to_detail_keeping_editor(): void
{
    $this->actingAs($this->lead)->get(route('syllabus.builder', ['curriculum' => $this->curriculum->id, 'edit_lesson' => $this->lesson->id]))
        ->assertRedirect(route('syllabus.curriculums.show', ['id' => $this->curriculum->id, 'edit_lesson' => $this->lesson->id]));
    $this->actingAs($this->lead)->get(route('syllabus.builder'))->assertRedirect(route('syllabus.curriculums.index'));
}

public function test_deleted_curriculum_detail_is_404(): void
{
    $this->curriculum->delete();
    $this->actingAs($this->lead)->get(route('syllabus.curriculums.show', $this->curriculum->id))->assertNotFound();
}

public function test_saving_a_lesson_returns_to_detail_page(): void
{
    $this->actingAs($this->lead)->put(route('syllabus.lessons.update', $this->lesson->id), ['session_no' => 1, 'title' => 'Sửa'])
        ->assertRedirect(route('syllabus.curriculums.show', $this->curriculum->id));
}

public function test_proposal_edit_link_opens_lesson_on_detail_page(): void
{
    // create a SyllabusChangeProposal for $this->lesson (fields as in Phase2MockupSyllabusTest), GET versions with ?proposal=
    // assert prop selected.edit_lesson_url === route('syllabus.curriculums.show', ['id' => curriculum, 'edit_lesson' => lesson])
}
```

(Fill the last test's proposal creation from the existing proposal test in `Phase2MockupSyllabusTest` — search `SyllabusChangeProposal::create`.)

- [ ] **Step 2: Run, expect FAIL** (routes missing).
- [ ] **Step 3: Implement** per the file list; regenerate ziggy.
- [ ] **Step 4: Run, expect PASS:** `SyllabusLibraryTest`, `SyllabusNavigationCleanupTest`, `SyllabusStageProgressionTest`, `Phase2MockupSyllabusTest`, `SyllabusModuleTest`, `Phase2AcceptanceTest`, import tests, `ZiggyRoutesTest`, `DemoPhase2SeederTest`. Old tests: replace `route('syllabus.builder', ['curriculum' => X] + p)` with `route('syllabus.curriculums.show', ['id' => X] + p)` and redirect assertions likewise; keep their assertions otherwise.
- [ ] **Step 5: Commit** `feat: syllabus library list and curriculum detail page`

---

### Task 4: Navigation — sidebar, `/syllabus`, links

**Files:**
- Modify: `app/Support/Navigation/SidebarMenu.php` (workspace `syllabus`): replace `['label' => 'Soạn syllabus', 'route' => 'syllabus.builder']` with `['label' => 'Kho syllabus', 'route' => 'syllabus.curriculums.index', 'active' => ['syllabus.curriculums.*', 'syllabus.builder', 'syllabus.import*']]` anchored on `['syllabus.library']`, placed first; `Tài liệu` anchored on `['syllabus.library']` (removed in Sprint 2); `Giao chặng` stays on `SYLLABUS_MANAGER`; remove `Xem bài giảng` item (route `syllabus.teacher-view` stays, reachable from teacher Home / Attendance).
- Modify: `SyllabusController::index` — `syllabus.library` → `syllabus.curriculums.index`; else `syllabus.teaching-stages`.
- Modify: `app/Http/Controllers/ProfileController.php:83` (`academic_lead` quick link 'Giáo trình' → `syllabus.curriculums.index`), `app/Http/Controllers/AcademicSystemController.php:630,669` (mockup screen `02_soan_syllabus_theo_chang` → `syllabus.curriculums.index`).
- Modify: `tests/Feature/SyllabusNavigationCleanupTest.php` (index redirect expectation), `tests/Feature/FullBpmnSmokeTest.php:75`.
- Modify: `docs/kho-syllabus.md` — Sprint 1 checklist: tick done items; move "bỏ tab Tài liệu" to Sprint 2 (upload moves there).
- Test: `tests/Feature/SyllabusLibraryTest.php`

**Interfaces:** Consumes route names from Task 3 and permission from Task 2.

- [ ] **Step 1: Write failing tests:**

```php
public function test_sidebar_shows_library_for_lead_but_not_teaching_staff(): void
{
    $this->actingAs($this->lead)->get(route('syllabus.assignments'))->assertOk()
        ->assertSee('Kho syllabus')->assertDontSee('Soạn syllabus')->assertDontSee('Xem bài giảng');
    $this->actingAs($this->userWithRole(Roles::ACADEMIC_STAFF))->get(route('syllabus.assignments'))->assertOk()
        ->assertDontSee('Kho syllabus');
}

public function test_syllabus_index_sends_library_users_to_library(): void
{
    $this->actingAs($this->lead)->get('/syllabus')->assertRedirect(route('syllabus.curriculums.index'));
    $this->actingAs($this->userWithRole(Roles::TEACHER_FULLTIME))->get('/syllabus')->assertRedirect(route('syllabus.teaching-stages'));
}
```

(If the sidebar text check is ambiguous because a label appears elsewhere in the page, assert on the sidebar props via `assertInertia` using the shared prop the layout uses — check `HandleInertiaRequests` for the sidebar prop name.)

- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement** the file list.
- [ ] **Step 4: Run, expect PASS:** `SyllabusLibraryTest`, `SyllabusNavigationCleanupTest`, `FullBpmnSmokeTest`, `NavigationPermissionTest`, `NavigationMenuPermissionTest`, `WorkspaceNavigationTest`, `RbacNoHardcodedRolesTest`, `AllRoutesSmokeTest` (0 broken), `BranchIsolationRouteSweepTest`, `ZiggyRoutesTest`, `AcademicSystemTest`.
- [ ] **Step 5: Commit** `feat: Kho syllabus in sidebar and /syllabus landing`

---

### Task 5: Wrap-up

- [ ] `v2/tools/parity.sh origin/main` — note tracked areas in the PR body; confirm `catalog.json` diff only adds `syllabus.library`.
- [ ] `vendor/bin/pint` on new/changed PHP files only (not whole legacy files).
- [ ] Update `docs/kho-syllabus.md` status line to "Giai đoạn 1 — Sprint 1 xong"; commit `docs: mark syllabus library sprint 1 done`.
- [ ] No push yet: Sprints 2–3 continue on this branch; the PR opens at the end of Sprint 3.
