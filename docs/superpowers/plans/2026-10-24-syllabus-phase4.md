# Syllabus giai đoạn 4 — Cảnh báo, công việc Học thuật, ORDER: Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Còn N buổi đến cuối chặng (hoặc tới mốc "Báo Học thuật chuẩn bị") → việc cho Học thuật có NHẬN VIỆC / HOÀN THÀNH,
quá hạn đỏ + nhắc hằng ngày, vào báo cáo tuần (Quản lý + người làm ghi chú); nút ORDER ở mọi màn cổng GV theo mốc syllabus.

**Architecture:** Đọc tiến độ qua `ClassSyllabus::timeline()` (giai đoạn 3). Việc dùng `WorkTask` (kind `syllabus_stage_end`).
Order dùng `MaterialOrderService::create` (in ấn → vận hành cơ sở) và `BigTestOrder` (đề → Học thuật), gắn buổi syllabus.
Mốc đã báo lưu ở `syllabus_milestone_notices` để lệnh theo lịch idempotent.

**Tech Stack:** Laravel 13 / PHP 8.4, Inertia 3 + Vue 3, PHPUnit.

**Spec:** `docs/kho-syllabus.md` §9, §11, §12; Sheet 3 Bước 7–10; Sheet 4 câu 1, 5, 9, 10.

## Global Constraints

- Như giai đoạn 2–3. Thông báo qua `SyllabusNotifier` (nút: NHẬN VIỆC post, XỬ LÝ get, ORDER get).
- Không chạy 2 lượt phpunit cùng lúc trên một worktree (chung file SQLite).

## Review Focus

- Mốc "Báo Học thuật chuẩn bị" và "còn N buổi" cùng tới → chỉ 1 việc / chặng của lớp.
- Không ai có `syllabus.assign` phụ trách chi nhánh lớp → việc vẫn tạo (giao Học thuật bất kỳ / Admin), không lỗi.
- Bấm ORDER 2 lần cùng mốc → không tạo 2 order.
- GV có nhiều lớp đến hạn → nút ORDER đếm đúng tổng, màn order chọn lớp.
- Lịch dự kiến chưa có (TKB thiếu buổi) → hạn việc / ngày dùng order lấy `deadline` chặng hoặc để trống, không lỗi.

---

### Task 1: Mốc `notify_academic` + việc cuối chặng cho Học thuật

**Files:** migration `2026_11_23_090000_add_syllabus_fields_to_work_tasks.php` (`syllabus_assignment_id` FK null + index, `last_reminded_on` date null); migration `2026_11_23_090100_create_syllabus_milestone_notices_table.php` (`syllabus_assignment_id`, `syllabus_lesson_id` null, `kind` string(30), `notified_at`; unique (assignment, lesson, kind)); `SyllabusLesson::MILESTONES` + `LessonSheetCells::milestone` ("báo chuẩn bị" / "báo học thuật" → `notify_academic`, kiểm tra trước "big test"); `StageImportTemplate` hướng dẫn; `app/Services/Syllabus/StageEndAlerts.php`; lệnh `syllabus:stage-end-alerts` (hourlyAt(35)); `syllabus:remind-overdue-tasks` (dailyAt 08:00); `WorkTaskController::accept` (`POST tasks/{id}/accept` → `tasks.accept`) + `completeTask` (kind này hoàn thành ngay); `SyllabusNotifier::stageEnding`, `taskOverdue`.
**Test:** `tests/Feature/SyllabusStageEndTaskTest.php`.

- [ ] `test_task_created_when_remaining_lessons_reach_notice` (notice 2, 6 buổi, đã dạy 4 → 1 việc, tiêu đề "Chuẩn bị test chặng Chặng 1: Làm quen – giáo trình Family and Friends và syllabus mới cho lớp Lớp QT 01", người nhận = người giao chặng, hạn = ngày dự kiến buổi cuối, thông báo NHẬN VIỆC)
- [ ] `test_task_created_when_notify_milestone_reached_and_only_once`
- [ ] `test_accept_moves_new_to_in_progress_only_for_assignee`
- [ ] `test_complete_without_proof_marks_completed_and_appears_in_weekly_report`
- [ ] `test_overdue_task_reminds_assignee_and_branch_manager_daily_once`
- [ ] `test_milestone_text_parsing_notify_academic` (LessonSheetCells)

### Task 2: Báo cáo tuần Học thuật — 3 số + ghi chú Quản lý / người làm

**Files:** migration `staff_report_notes` (`staff_report_id` FK, `user_id` FK, `note` text, timestamps); `StaffReportNote` model; `WeeklyReportMetrics::academicSummary` thêm `syllabus_tasks_done`, `syllabus_tasks_overdue`, `classes_assigned`; `StaffReportController::noteStore` (`POST reports/{id}/notes` → `reports.notes.store`: người làm báo cáo hoặc `staff_report.view_all`) + `notes` trong payload báo cáo tuần Học thuật và trang chi tiết; Vue `AcademicWeekly.vue`, trang chi tiết báo cáo.
**Test:** `tests/Feature/AcademicWeeklyNotesTest.php`.

- [ ] `test_summary_counts_syllabus_tasks_and_assigned_classes`
- [ ] `test_manager_and_author_can_note_others_cannot`
- [ ] `test_notes_listed_on_report`

### Task 3: Nút ORDER + màn order theo mốc

**Files:** migration thêm `syllabus_assignment_id`, `syllabus_lesson_id` (FK null) vào `material_orders`, `big_test_orders`; `app/Services/Syllabus/SyllabusOrders.php` (`due(User): Collection` mốc đến hạn chưa order theo lớp GV dạy; `place(User, SyllabusAssignment, SyllabusLesson, array $input)`); `TeacherOrderController` (`GET teacher/orders` → `teacher.orders`, `POST teacher/orders/{class}` → `teacher.orders.store`); `AppShell` (`shell.order` = {count, url} cho người có `portal.teacher`, cache 5 phút / người, xoá khi tạo order); `AppLayout`/Shell: nút ORDER nổi góc phải, nhấp nháy khi count > 0; lệnh `syllabus:order-due-alerts` (hourlyAt(40)) báo GV 1 lần / mốc (nút ORDER); `Pages/Teacher/Orders.vue`.
**Test:** `tests/Feature/SyllabusOrderTest.php`.

- [ ] `test_order_button_counts_due_milestones_for_teacher`
- [ ] `test_print_order_goes_to_branch_operations_with_use_date_of_next_test`
- [ ] `test_test_order_goes_to_academic_with_due_three_sessions_before_test`
- [ ] `test_same_milestone_cannot_be_ordered_twice`
- [ ] `test_due_alert_sent_once_per_milestone`

### Task 4: Demo, tài liệu, kiểm thử

- [ ] Demo: CG FAM 1 có việc cuối chặng "Đang làm"; 1 mốc order đến hạn; README; §12 đánh dấu S4.x.
- [ ] Test liên quan → full suite (một mình) → review → push, PR.
