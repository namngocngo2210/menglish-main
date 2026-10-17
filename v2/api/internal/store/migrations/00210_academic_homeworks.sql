-- Cổng giáo viên: bài tập về nhà giao theo buổi (Laravel homeworks — TeacherPortalController::homework*) và bài nộp của
-- học viên (Laravel academic_records module student_portal, màn 03_hoc_tap_cua_toi_nop_bai_tap — StudentPortalController
-- submitHomework / teacherSubmissions / markSubmission). Cổng học viên nộp / sửa / xóa bài qua teacher.StudentPortal.
-- +goose Up
CREATE TABLE academic.homeworks (
    id            uuid PRIMARY KEY DEFAULT uuidv7(),
    class_id      uuid NOT NULL REFERENCES academic.classes (id) ON DELETE CASCADE,
    -- Buổi học được giao bài (form mới bắt buộc; bài theo form cũ không có buổi)
    session_id    uuid REFERENCES academic.class_sessions (id) ON DELETE SET NULL,
    -- Người giao (GV / Học vụ giao thay)
    teacher_id    uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    title         text NOT NULL CHECK (length(title) BETWEEN 1 AND 255),
    description   text CHECK (length(description) <= 20000),
    class_note    text CHECK (length(class_note) <= 2000),
    youtube_url   text CHECK (length(youtube_url) <= 500),
    quizizz_url   text CHECK (length(quizizz_url) <= 500),
    -- File nghe đính kèm (kho file, subject_type homework_audio)
    audio_file_id uuid REFERENCES core.files (id) ON DELETE SET NULL,
    -- Hạng mục → yêu cầu, vd. {"video": "…", "workbook": "…"} (khóa trùng homework_type của bài nộp); NULL = form cũ
    items         jsonb CHECK (items IS NULL OR jsonb_typeof(items) = 'object'),
    due_date      date,
    due_at        timestamptz,
    created_by    uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    created_at    timestamptz NOT NULL DEFAULT now(),
    updated_at    timestamptz NOT NULL DEFAULT now(),
    deleted_at    timestamptz
);
CREATE INDEX homeworks_class_idx ON academic.homeworks (class_id, due_date DESC) WHERE deleted_at IS NULL;
CREATE INDEX homeworks_created_idx ON academic.homeworks (created_at) WHERE deleted_at IS NULL;

CREATE TABLE academic.homework_submissions (
    id                 uuid PRIMARY KEY DEFAULT uuidv7(),
    student_id         uuid NOT NULL REFERENCES academic.students (id) ON DELETE CASCADE,
    -- Bài được nộp cho (dữ liệu Laravel không có liên kết này: bài nộp chỉ theo học viên + hạng mục + thời điểm)
    homework_id        uuid REFERENCES academic.homeworks (id) ON DELETE SET NULL,
    homework_type      text NOT NULL CHECK (homework_type IN ('video', 'vocabulary', 'workbook', 'extra_book', 'bgd_book', 'quiz')),
    notes              text CHECK (length(notes) <= 5000),
    -- File bài làm (kho file, subject_type homework_submission)
    attachment_file_id uuid REFERENCES core.files (id) ON DELETE SET NULL,
    attachment_name    text CHECK (length(attachment_name) <= 255),
    -- submitted: đã nộp; reviewed: GV đã chấm / đã xem
    status             text NOT NULL DEFAULT 'submitted' CHECK (status IN ('submitted', 'reviewed')),
    -- Điểm GV nhập dạng chữ ("9", "8.5/10", "A") như Laravel
    score              text CHECK (length(score) <= 20),
    feedback           text CHECK (length(feedback) <= 1000),
    reviewed_by        uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    reviewed_at        timestamptz,
    -- Tài khoản nộp (học viên / phụ huynh / nhân sự nộp hộ)
    submitted_by       uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    created_at         timestamptz NOT NULL DEFAULT now(),
    updated_at         timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX homework_submissions_student_idx ON academic.homework_submissions (student_id, created_at DESC);
CREATE INDEX homework_submissions_homework_idx ON academic.homework_submissions (homework_id) WHERE homework_id IS NOT NULL;
CREATE INDEX homework_submissions_created_idx ON academic.homework_submissions (created_at);

-- +goose Down
DROP TABLE academic.homework_submissions;
DROP TABLE academic.homeworks;
