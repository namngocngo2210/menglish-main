# Syllabus UI Cleanup Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the Syllabus pages less tangled without merging screens: `/syllabus` lands by permission, page headers keep only their own actions, wrong back links go away, and the Builder editor opens in a modal instead of jumping to the top.

**Architecture:** Laravel controller + Inertia/Vue page edits only. No route names, schema, permissions or sidebar changes. The Builder keeps its server-driven `editor` prop (from `?new_stage|edit_stage|new_unit|edit_unit|new_lesson|edit_lesson`); only its rendering moves into `UiModal` with `dismiss-url`.

**Tech Stack:** Laravel 13 / PHP 8.4, Inertia 3, Vue 3 (`<script setup>`, JS — the Syllabus pages are not TypeScript), PHPUnit feature tests with SSR-rendered HTML.

**Spec:** `docs/giao-trinh-don-giao-dien.md`

## Global Constraints

- Do **not** touch `app/Support/Navigation/SidebarMenu.php` (labels, order, permissions unchanged).
- Do not change route names / URLs / permissions / schema. Big Test pages: only the `BigTestSchedules` back link.
- UI strings and code comments in Vietnamese (repo convention); commit messages in English, one line.
- Run tests in Docker (no PHP on host), from the worktree `/home/menglish/wt-syllabus-ui-cleanup`:
  `docker run --rm -u $(id -u):$(id -g) -e HOME=/tmp -v $PWD:$PWD -w $PWD --entrypoint php menglish-test:local -d memory_limit=1G vendor/bin/phpunit <file> [--filter=...]`
- Worktree setup before the first test run: `cp -al ../wt-<existing>/vendor vendor` and same for `node_modules` (only if `composer.lock` / `package-lock.json` match), copy a local-test `.env` with `APP_KEY` (never `.env.production`).
- Never commit `public/build/` changes.

## Review Focus

1. Teacher opens `/syllabus` (bookmark / old link) → lands on `syllabus.teaching-stages`, not a 403 or the management list. (Task 1 test)
2. Accountant (no `syllabus.view`) opens `/syllabus` → still 403 (`ReviewRoundFinalTest` keeps passing). (Task 1 test)
3. Builder opened directly with `?edit_lesson=<id>` (link from Versions "Mở buổi để cập nhật") → editor is rendered open in the modal with the lesson's values. (Task 3 test)
4. Builder with an `edit_*` id from another curriculum → no editor (server already nulls it; modal must stay closed, page renders). (Task 3 test)
5. Saving the stage editor with a validation error → modal stays open with errors (redirect keeps the query, `editor` still set). Manual check in Task 3 Step 6.

---

### Task 1: `/syllabus` redirects by permission

**Files:**
- Modify: `app/Http/Controllers/SyllabusController.php` (add `index`)
- Modify: `routes/web.php:917` (point `syllabus.index` to `index`)
- Modify: `tests/Feature/FullBpmnSmokeTest.php:75`
- Create: `tests/Feature/SyllabusNavigationCleanupTest.php`

**Interfaces:**
- Produces: `SyllabusController::index(Request $request): RedirectResponse`; test class `Tests\Feature\SyllabusNavigationCleanupTest` with `setUp()` creating `$this->academic` (`Roles::ACADEMIC_LEAD`) and `$this->teacher` (`Roles::TEACHER_FULLTIME`), reused by Tasks 2–3.

- [ ] **Step 1: Write failing tests** in the new file (setup mirrors `Phase2MockupSyllabusTest::setUp`: branch + users; roles are pre-seeded by `TestBaseSeeder`):

```php
public function test_syllabus_index_sends_managers_to_documents(): void
{
    $this->actingAs($this->academic)->get('/syllabus')->assertRedirect(route('syllabus.documents'));
}

public function test_syllabus_index_sends_teachers_to_teaching_stages(): void
{
    $this->actingAs($this->teacher)->get('/syllabus')->assertRedirect(route('syllabus.teaching-stages'));
}
```

- [ ] **Step 2: Run, expect FAIL** (currently 200): `… vendor/bin/phpunit tests/Feature/SyllabusNavigationCleanupTest.php`

- [ ] **Step 3: Implement** `index()`: `syllabus.manage` → `redirect()->route('syllabus.documents')`, else `redirect()->route('syllabus.teaching-stages')`. Change the `/syllabus` route action to `'index'` (keep `can:syllabus.view` middleware and name). Update `FullBpmnSmokeTest:75` to `->assertRedirect(route('syllabus.documents'))`.

- [ ] **Step 4: Run, expect PASS:** the new test file, `tests/Feature/FullBpmnSmokeTest.php`, `tests/Feature/ReviewRoundFinalTest.php`.

- [ ] **Step 5: Commit** `feat: redirect /syllabus by permission`

---

### Task 2: Page headers keep only their own actions; fix back links

**Files (Modify):** `resources/js/Pages/Syllabus/{Documents,Builder,Assignments,TeacherView,TeacherPropose,TeacherAdjust,Versions,AdjustmentRequests,BigTestSchedules,Import}.vue`; Test: `tests/Feature/SyllabusNavigationCleanupTest.php`

**Interfaces:** Consumes Task 1 test setup.

Removals per spec §3.3 (exact buttons):

| File | Remove | Add |
|---|---|---|
| `Documents.vue` | header "Soạn syllabus", "Xem như giáo viên" | — |
| `Builder.vue` | header "Giao chặng"; the "Tạo giáo trình mới" button next to the curriculum select (line ~86 — keep the header one and the empty-state one); bottom bar "Quay lại / Tiếp tục: Chặng của lớp" + `useBackLink` import/const; `:back` | — |
| `Assignments.vue` | "Màn GV xem giáo trình" | — |
| `TeacherView.vue` | `:back` | — |
| `TeacherPropose.vue` | `:back`, "Xem trạng thái đề xuất", "Xin điều chỉnh tiến độ" | — |
| `TeacherAdjust.vue` | `:back`, "Duyệt yêu cầu tiến độ" (drop `canReview` prop usage only if unused elsewhere in file) | — |
| `Versions.vue` | `:back`, "Gửi đề xuất" | — |
| `AdjustmentRequests.vue` | "Gửi yêu cầu mới" | — |
| `BigTestSchedules.vue` | `:back` | — |
| `Import.vue` | — | `:back="route('syllabus.builder')"` on `UiPageHeader` |

Leave controller props that become unused (e.g. `canReview` on TeacherAdjust) in place unless the file no longer reads them; if removed from Vue, remove from the controller payload too.

- [ ] **Step 1: Write failing tests:**

```php
public function test_manager_pages_have_no_cross_links_or_back_button(): void
{
    $this->actingAs($this->academic)->get(route('syllabus.documents'))->assertOk()
        ->assertDontSee('Xem như giáo viên')->assertDontSee('data-back-link', false);
    $this->actingAs($this->academic)->get(route('syllabus.builder'))->assertOk()
        ->assertDontSee('Tiếp tục: Chặng của lớp')->assertDontSee('data-back-link', false);
    $this->actingAs($this->academic)->get(route('syllabus.assignments'))->assertOk()
        ->assertDontSee('Màn GV xem giáo trình');
    $this->actingAs($this->academic)->get(route('syllabus.versions'))->assertOk()
        ->assertDontSee('data-back-link', false);
    $this->actingAs($this->academic)->get(route('syllabus.adjustment-requests'))->assertOk()
        ->assertDontSee('Gửi yêu cầu mới');
    $this->actingAs($this->academic)->get(route('syllabus.big-tests.schedules'))->assertOk()
        ->assertDontSee('data-back-link', false);
}

public function test_teacher_pages_have_no_cross_links_or_back_button(): void
{
    $this->actingAs($this->teacher)->get(route('syllabus.teacher-view'))->assertOk()
        ->assertDontSee('data-back-link', false);
    $this->actingAs($this->teacher)->get(route('syllabus.teacher-propose'))->assertOk()
        ->assertDontSee('Xem trạng thái đề xuất')->assertDontSee('data-back-link', false);
    $this->actingAs($this->teacher)->get(route('syllabus.teacher-adjust'))->assertOk()
        ->assertDontSee('Duyệt yêu cầu tiến độ')->assertDontSee('data-back-link', false);
}

public function test_import_is_a_child_page_with_back_to_builder(): void
{
    $this->actingAs($this->academic)->get(route('syllabus.import'))->assertOk()
        ->assertSee('data-back-link', false)
        ->assertSee('href="'.route('syllabus.builder', [], false).'"', false);
}
```

(If a route needs `syllabus.manage`/other permission the teacher/academic lacks, use `$this->admin` with `Roles::ADMIN` for that line instead of weakening the assertion. Adjust the `href` assertion to how `UiButtonBack` renders hrefs — relative vs absolute — by checking the HTML once.)

- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Apply the removals/additions in the table.**
- [ ] **Step 4: Run, expect PASS:** `SyllabusNavigationCleanupTest`, `Phase2MockupSyllabusTest`, `Phase2AcceptanceTest`, `SyllabusModuleTest`, `SyllabusStageProgressionTest`, `AcademicSystemTest`, `ZiggyRoutesTest`. Fix any old assertion on a removed button by deleting that assertion only.
- [ ] **Step 5: Commit** `refactor: drop cross-page buttons and wrong back links in syllabus pages`

---

### Task 3: Builder editor in a modal

**Files:** Modify `resources/js/Pages/Syllabus/Builder.vue`; Test `tests/Feature/SyllabusNavigationCleanupTest.php`

**Interfaces:** Consumes the existing `editor` prop shape (`{ type: 'stage'|'unit'|'lesson', model, parent }`) from `SyllabusController::builder` — unchanged.

Decisions:
- `editorOpen = ref(!!props.editor)`; `watch(() => props.editor, (e) => { editorOpen.value = !!e; link.value = e?.type === 'stage' ? (e.model?.overview_link ?? '') : ''; preview.value = link.value; })`.
- `<UiModal :show="editorOpen" :title="editorTitle" max-width="3xl" :dismiss-url="builderUrl()" @close="editorOpen = false">`, body wrapper keeps `id="editor"` (existing `SyllabusStageProgressionTest` asserts it).
- `editorTitle` (computed), copy fixed: stage → `'Thông tin chung chặng học · ' + (model ? 'Sửa ' + model.label : 'Thêm chặng mới (Chặng ' + totals.next_position + ')')`; unit/lesson → the current `<h2>` texts verbatim (`Sửa Unit N: …` / `Thêm Unit vào …` / `Sửa Buổi N: …` / `Thêm buổi vào Unit N: …`). Remove the inner `<h2>`/badge and the left accent bar.
- Each of the three `UiForm`s gets `id="syllabus-editor-form"`; Hủy / Lưu buttons move to the modal `#footer` (`type="submit" form="syllabus-editor-form"`; Hủy = `@click="editorOpen = false"` + `history.replaceState` handled by `dismiss-url`, i.e. just close). Drop the sticky bottom bar of the stage form.
- All `builderUrl({...}) + '#editor'` links (stage Sửa / + Unit, unit + Buổi / Sửa, lesson Sửa buổi, "Thêm chặng", the dashed "Thêm buổi học mới" `Link`) drop `#editor` and add `preserve-scroll` (`UiButton preserve-scroll`, `<Link preserve-scroll>`).
- Update the file's doc comment (line 3) to say the editor opens in a modal.

- [ ] **Step 1: Write failing tests:**

```php
public function test_builder_editor_opens_in_modal_from_query(): void
{
    // curriculum + stage + unit + lesson created like Phase2MockupSyllabusTest::setUp
    $this->actingAs($this->academic)
        ->get(route('syllabus.builder', ['curriculum' => $this->curriculum->id, 'edit_lesson' => $this->lesson->id]))
        ->assertOk()
        ->assertSee('form="syllabus-editor-form"', false)
        ->assertSee('Sửa Buổi '.$this->lesson->session_no.': '.$this->lesson->title)
        ->assertDontSee('#editor', false);
}

public function test_builder_ignores_editor_id_from_other_curriculum(): void
{
    $other = SyllabusCurriculum::create([...]); // second curriculum with one lesson
    $this->actingAs($this->academic)
        ->get(route('syllabus.builder', ['curriculum' => $this->curriculum->id, 'edit_lesson' => $otherLesson->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('editor', null));
}
```

- [ ] **Step 2: Run, expect FAIL** on the first test (no `form="syllabus-editor-form"`, `#editor` present); the second may already pass (guards existing behavior).
- [ ] **Step 3: Implement the decisions above in `Builder.vue`.**
- [ ] **Step 4: Run, expect PASS:** `SyllabusNavigationCleanupTest`, `SyllabusStageProgressionTest`, `Phase2MockupSyllabusTest`, `ZiggyRoutesTest`.
- [ ] **Step 5: Commit** `feat: open syllabus builder editor in a modal`
- [ ] **Step 6: Manual check** (`composer dev` or staging-like local): open Builder, scroll to a lower stage, click "Sửa buổi" → modal opens, page does not jump; Esc → URL back to `?curriculum=X`; save → modal closes, scroll kept; submit stage form with empty name → modal stays open with error. Note result in the PR.

---

### Task 4: Wrap-up

- [ ] Run guard-rail tests: `NavigationPermissionTest`, `NavigationMenuPermissionTest`, `WorkspaceNavigationTest`, `RbacNoHardcodedRolesTest`, `AllRoutesSmokeTest`, `BranchIsolationRouteSweepTest`. Expected: all pass.
- [ ] `vendor/bin/pint --dirty` (in Docker). `git checkout -- public/build && git clean -fd public/build` if a build touched it.
- [ ] Tick Sprint 1 items and set status **Hoàn thành** in `docs/giao-trinh-don-giao-dien.md`; commit `docs: mark syllabus UI cleanup done`.
- [ ] `v2/tools/parity.sh origin/main` — if v2 areas track the changed Laravel files, note it in the PR (no v2 change expected: UI only + one redirect).
- [ ] Push branch, open PR to `main`, send the link.
