# Syllabus giai đoạn 2 — Giao syllabus & syllabus của lớp: Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Học thuật giao chặng (ngày bắt đầu + số buổi), GV xác nhận (nhắc 24h), lớp có syllabus riêng (bản đè theo buổi),
thông báo có nút hành động, không tự mở chặng kế, tự mở / đóng quyền xem file Google Drive theo chặng.

**Architecture:** Laravel service mỏng theo module `app/Services/Syllabus` (`SyllabusNotifier`, `ClassSyllabus`,
`StageSchedule`, `DriveAccessSync`) + client Drive tự viết `app/Services/GoogleDrive`. Controller mới
`SyllabusClassController` cho tab Syllabus của lớp (không làm phình `SyllabusController`). Việc nền chạy bằng lệnh theo lịch
(repo không có queue worker).

**Tech Stack:** Laravel 13 / PHP 8.4, Inertia 3 + Vue 3 (JS), Tailwind, PHPUnit (SQLite), Spatie permission.

**Spec:** `docs/kho-syllabus.md` mục 5, 6, 7, 11, 12 (giai đoạn 2).

## Global Constraints

- UI / comment / doc tiếng Việt; code / commit tiếng Anh; commit `Ngo Ngoc Nam <namngocngo2210@gmail.com>`, không trailer.
- Không so sánh tên vai trò bằng chuỗi — dùng `can()` / `Roles::*`; quyền mới cần migration cấp quyền + `config/access.php`
  + `permission_catalog.php` + xuất lại `v2/api/internal/iam/catalog/catalog.json`.
- Sau khi đổi route / .vue: chạy `ZiggyRoutesTest` (và `php artisan ziggy:generate` nếu repo theo dõi `resources/js/ziggy.js`).
- Nội dung thông báo đúng mẫu Sheet 3 "Quy tắc thông báo".
- Pint chỉ chạy trên file đã sửa; hoàn tác thay đổi format ngoài phạm vi.
- Khóa service account Google không commit; đọc từ `config('services.google_drive.key_path')`.

## Review Focus

- Lớp không có GV chính / GV được giao bị đổi → thông báo XÁC NHẬN gửi đúng người, người cũ không xác nhận được.
- Số buổi lớn hơn số buổi có trong TKB → không lỗi 500; `deadline` null + thông báo rõ.
- Hai chặng đang mở ở 2 lớp cùng dùng 1 file Drive cho cùng 1 GV → đóng 1 chặng không thu hồi quyền còn cần.
- Người đã có quyền trên file từ trước (`pre_existing`) → không bao giờ bị thu hồi.
- Drive chưa cấu hình → lệnh đồng bộ không gọi API, không lỗi; tab hiện "Chưa kết nối Google Drive".

---

### Task 1: Thông báo có nút hành động + `SyllabusNotifier`

**Files:**
- Modify: `app/Support/Navigation/AppShell.php` (`notificationMenu` thêm `action`), `app/Http/Controllers/AdminNotificationController.php` (`index` thêm `action`)
- Modify: `resources/js/Layouts/Shell/NotificationMenu.vue`, `resources/js/Pages/Notifications/Index.vue`
- Create: `resources/js/Components/NotificationAction.vue`, `app/Services/Syllabus/SyllabusNotifier.php`
- Test: `tests/Feature/NotificationActionTest.php`, `tests/Feature/SyllabusNotifierTest.php`

**Interfaces:**
- Produces: `data.action = ['label' => string, 'url' => ?string, 'method' => 'post'|'get'|'read']` trên `admin_notifications`
  (`read` = chỉ đánh dấu đã đọc — nút ĐÃ XEM). Payload chuông / trang thông báo có `action` (null nếu không có).
- Produces `SyllabusNotifier` (type thông báo `syllabus_workflow`):
  `stageAssigned(SyllabusAssignment $a): void` (GV được giao + GVNN: "Bạn đã nhận syllabus mới: Lớp ABC – Giáo trình Y – Chặng X, bắt đầu dd/mm.", action XÁC NHẬN → `syllabus.assignments.confirm`),
  `unconfirmedReminder(SyllabusAssignment $a): void` (GV: lại XÁC NHẬN; Học thuật (`syllabus.assign`) + Quản lý chi nhánh (`class.update` phạm vi chi nhánh): "Lớp ABC chưa xác nhận syllabus chặng X.", action NHẮC LẠI → `syllabus.assignments.remind`),
  `classSyllabusUpdated(ClassModel $class, Collection $lessonNumbers): void` ("Syllabus lớp ABC đã được cập nhật (buổi 3, 5).", action ĐÃ XEM),
  `stageContentUpdated(SyllabusStage $stage): void` (GV các lớp đang mở chặng: "Syllabus Chặng X (giáo trình Y) đã được Học thuật cập nhật.", ĐÃ XEM),
  `stageFinished(SyllabusAssignment $closed): void` (Học thuật: "Lớp ABC đã xong chặng X — giao chặng tiếp theo.", action get → `syllabus.assignments?class_id=`).
  Người nhận GV của lớp: `teacherIds(SyllabusAssignment): Collection` = user_id + class.teacher_id + class.foreign_teacher_id (bỏ trùng / null).

- [ ] Step 1: Test `NotificationActionTest::test_menu_and_index_expose_action` — tạo thông báo có `data.action` → `get('/')` (hoặc trang bất kỳ) prop `shell.notifications.items.0.action` = action; `notifications.index` có `notifications.data.0.action`.
- [ ] Step 2: Test `SyllabusNotifierTest`: `test_stage_assigned_goes_to_teacher_and_foreign_teacher_with_confirm_action`, `test_class_update_lists_lesson_numbers_with_read_action`, `test_stage_finished_goes_to_academic_leads`.
- [ ] Step 3: Chạy → FAIL. Step 4: Cài đặt; `NotificationAction.vue` nhận `action` + `notificationId`: `post` → `router.post(url)` rồi `router.post(route('notifications.read', id))`; `get` → `router.visit(url)` + đánh dấu đọc; `read` → chỉ đánh dấu đọc. Step 5: PASS + commit `feat: add action buttons to notifications`.

### Task 2: Quyền `syllabus.assign` / `syllabus.class_edit`; giao chặng chỉ Học thuật

**Files:**
- Modify: `config/permission_catalog.php` (`assign` => ['Giao chặng cho lớp', 'Giao / chuyển / sửa chặng của lớp (Học thuật).'], `class_edit` => ['Sửa syllabus của lớp', 'Chỉnh sửa thủ công syllabus riêng của một lớp.']), `config/access.php` (academic_lead có `syllabus.*`; không thêm cho manager / academic_staff)
- Create: `database/migrations/2026_11_21_090000_grant_syllabus_assign_permissions.php` (cấp cho `Roles::ACADEMIC_LEAD`, reversible)
- Modify: `routes/web.php` (`assignments.store` / `assignments.update` / `assignments.close` → `can:syllabus.assign`), `SyllabusController::assignments` (`canManage` → `can('syllabus.assign')`), `resources/js/Pages/Classes/Tabs/AcademicTab.vue` (link Giao chặng theo `syllabus.assign`)
- Modify: `v2/api/internal/iam/catalog/catalog.json` (xuất lại)
- Test: `tests/Feature/SyllabusAssignPermissionTest.php`

- [ ] Step 1: Test `test_lead_has_assign_permissions_staff_and_manager_do_not`, `test_academic_staff_cannot_assign_stage` (POST `syllabus.assignments.store` → 403), `test_lead_can_assign_stage`.
- [ ] Step 2: FAIL → cài đặt → PASS; sửa test cũ đang giao chặng bằng Học vụ (tìm `assignments.store` trong tests) sang Học thuật. Chạy `RbacNoHardcodedRolesTest|NavigationPermissionTest|AllRoutesSmokeTest`. Commit `feat: restrict stage assignment to academic leads`.

### Task 3: Ngày bắt đầu + số buổi → ngày dự kiến kết thúc; xác nhận & nhắc 24h; bỏ tự mở chặng kế

**Files:**
- Create: `database/migrations/2026_11_21_090100_add_confirmation_and_plan_to_syllabus_assignments.php` (`start_date` date null, `planned_sessions` unsignedSmallInteger null, `confirmed_at`, `confirmed_by` FK null, `confirm_reminded_at`; backfill `start_date = date(opened_at)`, `planned_sessions` = số buổi của chặng)
- Create: `app/Services/Syllabus/StageSchedule.php`, `app/Console/Commands/RemindUnconfirmedSyllabusCommand.php` (`syllabus:remind-unconfirmed`), `app/Console/Commands/RefreshStageDeadlinesCommand.php` (`syllabus:refresh-stage-deadlines`)
- Modify: `app/Models/SyllabusAssignment.php`, `SyllabusController::storeAssignment/updateAssignment/closeAssignment`, `SyllabusProgressionService::close` (bỏ mở chặng kế; gọi `SyllabusNotifier::stageFinished`), `routes/web.php` (`POST syllabus/assignments/{id}/confirm` → `assignments.confirm`; `POST syllabus/assignments/{id}/remind` → `assignments.remind`, `can:syllabus.assign`), `routes/console.php` (remind hourlyAt(25), refresh hourlyAt(5)), `resources/js/Pages/Syllabus/Assignments.vue`, `resources/js/Pages/Syllabus/TeacherView.vue` (banner XÁC NHẬN nếu chưa xác nhận)
- Test: `tests/Feature/SyllabusStageScheduleTest.php`, `tests/Feature/SyllabusAssignmentConfirmTest.php`; sửa test tự mở chặng kế (`SyllabusProgression*Test`, `DemoPhase2SeederTest`)

**Interfaces:**
- Produces `StageSchedule::sessionDates(ClassModel $class, Carbon $from): Collection<int, Carbon>` (buổi chính khóa + học bù, không hủy, `date >= from`, sắp theo ngày / giờ) và `StageSchedule::endDate(ClassModel $class, Carbon $from, int $sessions): ?Carbon` (ngày buổi thứ N; null nếu TKB chưa đủ).
- `SyllabusAssignment::isConfirmed(): bool`; `canConfirm(User $u): bool` (u là user_id / teacher_id / foreign_teacher_id của lớp).

- [ ] Step 1: Test `SyllabusStageScheduleTest`: `test_end_date_is_nth_active_session_from_start` (6 buổi, 1 hủy, 1 học bù → ngày buổi thứ 5), `test_returns_null_when_schedule_too_short`.
- [ ] Step 2: Test `SyllabusAssignmentConfirmTest`: `test_assign_requires_start_date_and_sets_deadline_from_planned_sessions`, `test_teacher_confirms_and_others_cannot`, `test_reminder_after_24h_once_to_teacher_lead_and_manager`, `test_big_test_completion_closes_stage_without_opening_next_and_notifies_lead`.
- [ ] Step 3: FAIL → cài đặt. Form giao: `start_date` required, `planned_sessions` (min 1, max 200, mặc định số buổi của chặng chọn), bỏ ô "Dự kiến hoàn thành" (hiện ngày tự tính). Sửa chặng: đổi được `planned_sessions`. Đóng tay: bỏ ô "Mở chặng kế". Cột trạng thái xác nhận trên màn Giao chặng.
- [ ] Step 4: PASS + chạy `--filter='Syllabus|DemoPhase2|Phase2Acceptance'`; commit `feat: confirm stage assignments and stop auto-opening next stage`.

### Task 4: Syllabus của lớp — bản đè, tab Syllabus, báo GV, Sheet 2 tự tính

**Files:**
- Create: migration `2026_11_21_090200_create_class_lesson_overrides_table.php`; `app/Models/ClassLessonOverride.php` (`FIELDS` = title, lesson_type, objectives, content, procedure, activity_1, activity_2, homework_guide, homework_quizizz, homework_video, ppt_url, ppt_foreign_url, quizizz_url, test_url, output_product, notice_milestone, note; `AuditsChanges`)
- Create: `app/Services/Syllabus/ClassSyllabus.php`, `app/Http/Controllers/SyllabusClassController.php`, `resources/js/Pages/Classes/Tabs/SyllabusTab.vue`
- Modify: `app/Support/ClassLifecycle.php` (`'syllabus' => ['label' => 'Syllabus', 'icon' => 'menu_book']` sau `academic`), `ClassManagementController::show` (tab syllabus → `SyllabusClassController::tabData`), `resources/js/Pages/Classes/Show.vue`, `routes/web.php` (`PUT classes/{class}/syllabus/lessons/{lesson}` → `classes.syllabus.override`, `DELETE` → `classes.syllabus.override.destroy`, `can:syllabus.class_edit`)
- Modify: `SyllabusController::updateLesson` + `StageImporter::import` → `SyllabusNotifier::stageContentUpdated`; `SyllabusController::show` + `CurriculumShow.vue` (mỗi chặng: "N buổi · báo Học thuật ở buổi N − báo trước")
- Test: `tests/Feature/ClassSyllabusTest.php`

**Interfaces:**
- Produces `ClassSyllabus::lessons(SyllabusAssignment $a): Collection<int, array{lesson: SyllabusLesson, data: array<string, mixed>, overridden: bool, override_reason: ?string, unit_number: int, unit_title: string}>` (`data` = các trường `ClassLessonOverride::FIELDS` sau khi ghép bản đè; thứ tự `session_no`). Giai đoạn 3 mở rộng thêm tiến độ.
- Produces `ClassSyllabus::effective(SyllabusLesson $lesson, ?ClassLessonOverride $override): array`.

- [ ] Step 1: Tests `test_override_changes_only_that_class`, `test_original_edit_reaches_classes_without_override_for_that_field`, `test_override_requires_reason_and_notifies_teachers`, `test_remove_override_restores_original`, `test_staff_and_teacher_can_view_tab_but_not_edit`, `test_editing_original_lesson_notifies_teachers_of_open_classes`, `test_curriculum_show_lists_stage_session_count_and_alert_session`.
- [ ] Step 2: FAIL → cài đặt (validate như `lessonRules` + `reason` required; url max 500) → PASS; `ZiggyRoutesTest`; commit `feat: add per-class syllabus overrides`.

### Task 5: Quyền xem file Google Drive theo chặng

**Files:**
- Create: `app/Services/GoogleDrive/DriveClient.php`, `app/Services/GoogleDrive/DriveException.php`, `app/Services/GoogleDrive/DriveLinks.php`, migration `2026_11_21_090300_create_syllabus_drive_grants_table.php`, `app/Models/SyllabusDriveGrant.php`, `app/Services/Syllabus/DriveAccessSync.php`, `app/Console/Commands/SyncSyllabusDriveAccessCommand.php` (`syllabus:sync-drive-access`, everyMinute withoutOverlapping), `docs/google-drive-syllabus.md`
- Modify: `config/services.php` (`google_drive.key_path` = env `GOOGLE_DRIVE_SA_KEY_PATH`), `routes/console.php`, `SyllabusClassController` (bảng quyền trong tab + `POST classes/{class}/syllabus/drive-sync` → `classes.syllabus.drive-sync`, `can:syllabus.assign`), `SyllabusTab.vue`
- Test: `tests/Feature/SyllabusDriveAccessTest.php`, `tests/Unit/DriveLinksTest.php`

**Interfaces:**
- `DriveLinks::parse(?string $url): ?array{id: string, kind: 'file'|'folder'}` — nhận `docs.google.com/{document|presentation|spreadsheets|forms}/d/{id}`, `drive.google.com/file/d/{id}`, `drive.google.com/drive/(u/N/)folders/{id}`, `drive.google.com/open?id={id}`; còn lại null.
- `DriveClient::configured(): bool`; `permissions(string $fileId): list<array{id: string, emailAddress: ?string, role: string}>`; `grantReader(string $fileId, string $email): string` (permission id; `sendNotificationEmail=false`, `supportsAllDrives=true`); `restrictCopy(string $fileId): void` (`copyRequiresWriterPermission=true`; thư mục bỏ qua); `revoke(string $fileId, string $permissionId): void` (404 coi như xong). Token: JWT RS256 (`openssl_sign`) → `https://oauth2.googleapis.com/token`, cache 50 phút. Lỗi HTTP → `DriveException` (thông điệp từ `error.message`).
- `DriveAccessSync::desired(): Collection<string, array{email: string, file_id: string, kind: string}>` (khóa `email|file_id`; từ mọi chặng mở: link Drive trong `ClassSyllabus::lessons` + `stage.overview_link`; người: GV được giao + GV chính + GVNN, email đăng nhập, user active); `sync(): array{granted: int, revoked: int, failed: int}`.
- Trạng thái: pending → granted / failed (attempts ≤ 5, báo Học thuật 1 lần khi `failed` lần đầu); không còn trong desired → revoking → revoked (`pre_existing` → bỏ qua, xoá khỏi bảng).

- [ ] Step 1: `DriveLinksTest` (6 dạng URL + Quizizz null).
- [ ] Step 2: `SyllabusDriveAccessTest` với `Http::fake` + khóa RSA sinh trong test: `test_not_configured_does_nothing`, `test_open_stage_grants_reader_to_teacher_and_foreign_teacher_and_restricts_copy`, `test_closing_stage_revokes_unless_still_needed_by_other_open_stage`, `test_pre_existing_permission_is_never_revoked`, `test_failure_is_recorded_and_retried_up_to_five_times`, `test_class_tab_shows_grant_status`.
- [ ] Step 3: FAIL → cài đặt → PASS; commit `feat: grant and revoke Google Drive view access per syllabus stage`.

### Task 6: Demo, tài liệu, guard rails

- [ ] `DemoPhase2Seeder` / `DemoSyllabusLibrarySeeder`: giao chặng bằng Học thuật (start_date + planned_sessions), 1 lớp đã xác nhận / 1 lớp chờ xác nhận, 1 bản đè có lý do; cập nhật `DemoPhase2SeederTest`, `DemoSyllabusLibrarySeederTest`.
- [ ] `docs/kho-syllabus.md` mục 12: đánh dấu S2.1–S2.6 xong; README demo nếu đổi tài khoản / luồng.
- [ ] Chạy test liên quan: `--filter='Syllabus|Notification|DemoPhase|Phase[1-4]Acceptance|FullBpmn|ClassManagement|ZiggyRoutes|AllRoutesSmoke|RbacNoHardcoded|NavigationPermission|BranchIsolation'` → xanh; rồi full suite trước khi push.
- [ ] Push, PR "feat: syllabus workflow phase 2", gửi link.
