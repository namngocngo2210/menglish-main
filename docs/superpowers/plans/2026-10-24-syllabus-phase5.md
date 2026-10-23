# Syllabus giai đoạn 5 — Homework 3 giỏ: Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Bài tập gắn 3 giỏ (giấy / Quizizz / video); GV giao bài điền sẵn từ syllabus buổi hôm nay; HS nộp theo bài tập & giỏ
(giấy = ảnh, video = video, Quizizz = HS tự bấm "Đã làm"); GV xem / đánh dấu đã xem; thống kê tỉ lệ nộp theo HS và lớp.

**Architecture:** Bảng `homework_submissions` (thay bản ghi `AcademicRecord` chung cho luồng mới, luồng cũ giữ nguyên để không
mất dữ liệu). Giỏ của bài tập suy từ `Homework.items` (`Homework::basketOf`). Thống kê ở `App\Services\Homework\HomeworkStats`.

**Tech Stack:** Laravel 13 / PHP 8.4, Inertia 3 + Vue 3, PHPUnit.

**Spec:** `docs/kho-syllabus.md` §10, §12; Sheet 4 câu 7.

## Global Constraints

- Như các giai đoạn trước; upload qua `SafeUploadService` (kiểm tra theo nội dung file); 1 lượt phpunit tại một thời điểm.

## Review Focus

- HS nộp lại cùng giỏ → cập nhật bài nộp cũ, không tạo 2 dòng.
- HS nộp bài của lớp mình không học / bài tập đã xoá → 404, không lộ dữ liệu.
- File sai loại (PDF cho giỏ video, video cho giỏ giấy) → báo lỗi rõ.
- Lớp chưa giao bài nào trong khoảng thống kê → tỉ lệ "—", không chia cho 0.
- Bài tập không có giỏ nào (chỉ ghi chú) → không tính vào thống kê.

---

### Task 1: `homework_submissions` + HS nộp theo bài tập & giỏ

**Files:** migration `2026_11_24_090000_create_homework_submissions_table.php` (unique (`homework_id`,`student_id`,`basket`) tên `hw_sub_unique`); `app/Models/HomeworkSubmission.php` (BASKETS paper/quizizz/video); `Homework::basketOf(string $category): string`, `Homework::baskets(): list<string>`; `StudentPortalController::submitBasket` (`POST student-homework/{homework}/{basket}` → `student.homework.basket`); `studentHomework` thêm `assignments` (bài tập 30 ngày của lớp HS, giỏ + trạng thái); `Portal/Homework.vue` mục "Bài tập được giao".
**Test:** `tests/Feature/HomeworkBasketsTest.php` — `test_basket_mapping_from_categories`, `test_student_submits_photo_paper_video_and_quizizz_done`, `test_resubmit_updates_same_row`, `test_wrong_file_type_rejected`, `test_cannot_submit_other_class_homework`.

### Task 2: GV giao bài theo syllabus + xem bài nộp + thống kê

**Files:** `TeacherPortalController::homework` (`?lesson=` → `prefill` items / quizizz_url từ buổi hiện tại của lớp), `Teacher/Homework.vue`; `TeacherLessonController` link "Giao bài tập" kèm `lesson`; `App\Services\Homework\HomeworkStats::forClass(ClassModel, Carbon from, Carbon to): array`; `TeacherHomeworkController` (`GET teacher/homework-submissions/{class}` → `teacher.homework-submissions`, `POST teacher/homework-submissions/{submission}/review` → `teacher.homework-submissions.review`); `Pages/Teacher/HomeworkSubmissions.vue`; tab "Homework" trang lớp (`ClassLifecycle::TABS`, `ClassManagementController::show`, `Tabs/HomeworkTab.vue`).
**Test:** `tests/Feature/HomeworkStatsTest.php` — `test_homework_form_prefilled_from_today_lesson`, `test_teacher_reviews_submission`, `test_stats_by_student_and_class_per_basket`, `test_empty_range_has_no_rate`, `test_class_tab_shows_stats_for_staff`.

### Task 3: Demo, tài liệu, kiểm thử toàn bộ

- [ ] Demo: CG FAM 1 có 2 bài tập theo syllabus, HS nộp vài giỏ (1 đã xem); README; §12 đánh dấu S5.x; trạng thái tài liệu "Hoàn thành".
- [ ] Test liên quan → full suite (một mình) → review → push, PR.
