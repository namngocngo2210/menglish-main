# Syllabus giai đoạn 3 — GV vận hành theo buổi: Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tiến độ chặng chỉ chạy khi GV bấm "Đã dạy"; lịch dự kiến các buổi còn lại tự dời theo TKB; GV xem đúng buổi hôm
nay; buổi test có nút nhập điểm, vận hành thấy "Chưa nhập điểm"; Customize syllabus (Giãn / Dời) do Học thuật duyệt.

**Architecture:** Bảng `class_lesson_progress` + mở rộng `App\Services\Syllabus\ClassSyllabus` (tiến độ + lịch dự kiến) là
nguồn duy nhất; `SyllabusProgressionService::position()` và `SessionLessonService::lessonsFor()` đọc từ đó. Controller mới
`TeacherLessonController` cho màn Bài giảng hôm nay.

**Tech Stack:** Laravel 13 / PHP 8.4, Inertia 3 + Vue 3, PHPUnit (SQLite).

**Spec:** `docs/kho-syllabus.md` §8, §11, §12 (giai đoạn 3); quyết định 4, 7 ở §2.

## Global Constraints

- Như kế hoạch giai đoạn 2 (ngôn ngữ, commit, Pint chỉ file sửa, Ziggy sau khi đổi route / .vue).
- Tiến độ chỉ chạy khi GV bấm Đã dạy (quyết định 4); GV chỉ xem buổi hôm nay (quyết định 7).
- Đã dạy: GV được giao chặng, GV chính, GVNN của lớp, hoặc GV / GVNN dạy thay buổi đó — không phải trợ giảng.

## Review Focus

- Lớp có buổi học bù sau buổi hủy → lịch dự kiến dùng buổi bù, không dùng buổi hủy.
- Bấm Đã dạy 2 lần (bấm đúp / 2 tab) → không ghi trùng, không nhảy 2 buổi.
- Hết buổi syllabus của chặng mà vẫn còn buổi học → không lỗi, màn hiện "Đã dạy hết buổi của chặng".
- Lớp không có buổi học hôm nay → màn hiện rõ, không cho bấm Đã dạy (trừ buổi đã qua chưa đánh dấu).
- Lớp đang chạy lúc triển khai → backfill không đưa lớp về buổi 1 và không vượt số buổi của chặng.

---

### Task 1: `class_lesson_progress`, tiến độ & lịch dự kiến, backfill

**Files:**
- Create: migration `2026_11_22_090000_create_class_lesson_progress_table.php` (+ backfill), `app/Models/ClassLessonProgress.php`
- Modify: `app/Services/Syllabus/ClassSyllabus.php`, `app/Services/SyllabusProgressionService.php` (`position`), `app/Services/SessionLessonService.php` (`lessonsFor`)
- Test: `tests/Feature/ClassLessonProgressTest.php`

**Interfaces:**
- `ClassLessonProgress`: `KIND_LESSON = 'lesson'`, `KIND_EXTRA = 'extra'`; fillable syllabus_assignment_id, class_id, syllabus_lesson_id, class_session_id, kind, pace_note, taught_by, taught_at.
- `ClassSyllabus::timeline(SyllabusAssignment $a): array{rows: Collection, current: ?array, taught: int, extra_left: int, unmarked_sessions: Collection<int, ClassSession>}` — `rows` = `lessons()` + `taught_at` (?Carbon), `taught_session_id`, `planned_date` (?Carbon cho buổi chưa dạy); `current` = row đầu tiên chưa dạy; `extra_left` = `extra_sessions` − số dòng extra; `unmarked_sessions` = buổi học (chính khóa + bù, không hủy) từ `start_date` đến hôm qua chưa gắn dòng progress nào.
- Lịch dự kiến: buổi học từ hôm nay (bỏ buổi hôm nay nếu đã gắn progress) xếp cho `extra_left` buổi giãn trước, rồi các buổi syllabus chưa dạy.
- `position()` giữ shape cũ: `taught` = số dòng lesson, `current`, `over` = số dòng extra.

- [ ] Tests: `test_progress_only_moves_when_marked`, `test_planned_dates_skip_cancelled_sessions_and_use_makeup`, `test_extra_sessions_are_planned_before_remaining_lessons`, `test_session_lesson_service_maps_past_by_progress_and_future_by_plan`, `test_backfill_marks_lessons_taught_from_past_sessions_capped_at_stage_size` (gọi lại closure backfill của migration qua `ClassLessonProgress::backfill()`).
- [ ] RED → cài đặt → GREEN; `--filter='ClassLessonProgress|SyllabusStageProgression|SessionLesson|Phase2|MissedLesson|WorkTask|StudentProfile'`; commit `feat: track lesson progress by taught marks`.

### Task 2: Màn Bài giảng hôm nay + Đã dạy + nhập điểm + cờ Chưa nhập điểm

**Files:**
- Create: `app/Http/Controllers/TeacherLessonController.php`, `resources/js/Pages/Teacher/LessonToday.vue`
- Modify: `routes/web.php` (`GET teacher/lesson-today` → `teacher.lesson-today`; `POST teacher/lesson-today/{class}/taught` → `teacher.lesson-today.taught`), `resources/js/Pages/Teacher/Home.vue` (nút "Bài giảng" trên buổi hôm nay), `resources/js/Pages/Syllabus/TeacherView.vue` + `SyllabusController::teacherView` (bỏ danh sách mọi buổi của chặng; link sang Bài giảng hôm nay), `ClassSyllabusTab` + `SyllabusTab.vue` (cột Đã dạy / Ngày dự kiến / Chưa nhập điểm)
- Test: `tests/Feature/TeacherLessonTodayTest.php`

**Interfaces:**
- Payload trang: `classes` (lớp có buổi hôm nay mà user dạy: GV / GVNN / TG / dạy thay), `classId`, `lesson` (row hiện tại: data hiệu lực + documents buổi + `score_url` khi lesson_type mini_test / big_test), `session` (buổi hôm nay hoặc null), `unmarked` (list {id, date}), `can_mark` (bool), `extra_left`, `finished` (đã dạy hết chặng).
- `POST taught`: body `class_session_id` (bắt buộc; buổi của lớp, ≤ hôm nay, chưa gắn progress), `pace_note` (≤ 1000), `use_extra` (bool; chỉ khi `extra_left > 0`). Ghi trong transaction + lock assignment; trùng (unique / đã gắn) → báo "Buổi này đã được đánh dấu".
- "Chưa nhập điểm": buổi `mini_test` đã dạy mà lớp không có `MiniTestScore` (`syllabus_unit_id` = unit của buổi hoặc `test_date` = ngày dạy); buổi `big_test` đã dạy mà không có `BigTestResult` của `BigTest` (class + `syllabus_stage_id`).

- [ ] Tests: `test_teacher_sees_only_today_lesson_with_effective_content_and_links`, `test_no_session_today_shows_notice_and_cannot_mark`, `test_mark_taught_advances_and_records_pace_note`, `test_double_mark_is_rejected`, `test_use_extra_requires_approved_extra_sessions`, `test_unmarked_past_session_can_be_marked`, `test_assistant_cannot_mark`, `test_test_lesson_has_score_link_and_class_tab_flags_missing_scores`, `test_teacher_view_no_longer_lists_all_stage_lessons`.
- [ ] RED → cài đặt → GREEN; `ZiggyRoutesTest`; commit `feat: add today's lesson screen with taught marking`.

### Task 3: Customize syllabus (Giãn / Dời)

**Files:**
- Create: migration `2026_11_22_090100_add_kind_to_syllabus_adjustment_requests.php` (`kind` string(20) default `extend`)
- Modify: `SyllabusAdjustmentRequest` (KINDS = extend 'Giãn thêm buổi', postpone 'Dời tiến độ'), `SyllabusController::storeAdjustmentRequest/approveAdjustmentRequest/teacherAdjust/adjustmentRequests`, `resources/js/Pages/Syllabus/TeacherAdjust.vue` (tiêu đề "Customize syllabus", chọn loại), `AdjustmentRequests.vue` (cột loại), sidebar label nếu có
- Test: `tests/Feature/CustomizeSyllabusTest.php`

- [ ] Tests: `test_postpone_adds_extra_sessions_without_new_schedule_sessions`, `test_extend_still_adds_sessions_to_schedule`, `test_teacher_cannot_use_extra_before_approval` (gắn với Task 2), `test_screen_titled_customize_syllabus`.
- [ ] RED → cài đặt → GREEN; commit `feat: add customize syllabus requests`.

### Task 4: Demo, tài liệu, kiểm thử toàn bộ

- [ ] Seeder demo: lớp CG FAM 1 có vài buổi Đã dạy (kèm 1 ghi chú tiến độ), 1 buổi đã qua chưa đánh dấu; 1 yêu cầu Customize "Dời" chờ duyệt.
- [ ] `docs/kho-syllabus.md` §12 đánh dấu S3.x xong; README demo.
- [ ] Test liên quan + full suite → push, PR.
