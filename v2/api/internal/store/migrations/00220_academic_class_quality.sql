-- Dự giờ & chất lượng lớp (Laravel: qa_observations, academic_observations, class_checklists, teacher_meeting_reports).
-- Hai loại dự giờ dùng chung academic.class_observations theo cột kind (tài liệu thiết kế):
--   operations: dự giờ vận hành (QA / Học vụ) — một buổi quan sát một giáo viên ở một lớp, xếp loại 4 mức;
--   academic:   đánh giá dự giờ học thuật — mỗi lớp tối đa một bản ghi / tháng (% chuyên cần, % đạt, 6 tiêu chí).
-- +goose Up
CREATE SCHEMA IF NOT EXISTS academic;

CREATE TABLE academic.class_observations (
    id               uuid PRIMARY KEY DEFAULT uuidv7(),
    kind             text NOT NULL CHECK (kind IN ('operations', 'academic')),
    class_id         uuid NOT NULL REFERENCES academic.classes (id),
    -- Ngày dự giờ và người dự giờ (học thuật: chỉ khi đã dự giờ)
    observed_on      date,
    observer_id      uuid REFERENCES iam.users (id),
    -- Vận hành: giáo viên được dự giờ (GV chính hoặc GVNN của lớp) và xếp loại
    teacher_id       uuid REFERENCES iam.users (id),
    rating           text CHECK (rating IN ('excellent', 'good', 'pass', 'needs_improvement')),
    -- Học thuật: tháng đánh giá YYYY-MM, tỉ lệ %, đã dự giờ hay chưa
    month            text CHECK (month ~ '^\d{4}-(0[1-9]|1[0-2])$'),
    attendance_rate  numeric(5, 2) CHECK (attendance_rate BETWEEN 0 AND 100),
    pass_rate        numeric(5, 2) CHECK (pass_rate BETWEEN 0 AND 100),
    observed         boolean NOT NULL DEFAULT false,
    -- Nội dung quan sát: lesson_content, attitude dùng chung hai loại; còn lại theo loại
    lesson_content   text CHECK (length(lesson_content) <= 5000),
    attitude         text CHECK (length(attitude) <= 5000),
    preparation      text CHECK (length(preparation) <= 5000),
    technique        text CHECK (length(technique) <= 5000),
    suggestions      text CHECK (length(suggestions) <= 5000),
    teaching_quality text CHECK (length(teaching_quality) <= 5000),
    interaction      text CHECK (length(interaction) <= 5000),
    effectiveness    text CHECK (length(effectiveness) <= 5000),
    overall          text CHECK (length(overall) <= 5000),
    action_notes     text CHECK (length(action_notes) <= 5000),
    created_by       uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    updated_by       uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    created_at       timestamptz NOT NULL DEFAULT now(),
    updated_at       timestamptz NOT NULL DEFAULT now(),
    -- Chỉ lượt dự giờ vận hành bị xóa (mềm) như Laravel
    deleted_at       timestamptz,
    CHECK (
        (kind = 'operations' AND month IS NULL AND observed_on IS NOT NULL AND observer_id IS NOT NULL
            AND teacher_id IS NOT NULL AND rating IS NOT NULL)
        OR (kind = 'academic' AND month IS NOT NULL AND teacher_id IS NULL AND rating IS NULL AND deleted_at IS NULL
            AND (NOT observed OR (observed_on IS NOT NULL AND observer_id IS NOT NULL)))
    )
);
CREATE UNIQUE INDEX class_observations_month_uq ON academic.class_observations (class_id, month) WHERE kind = 'academic';
CREATE INDEX class_observations_date_idx ON academic.class_observations (kind, observed_on DESC) WHERE deleted_at IS NULL;
CREATE INDEX class_observations_teacher_idx ON academic.class_observations (teacher_id) WHERE teacher_id IS NOT NULL;

-- Checklist học phí & feedback Big Test của lớp theo tháng: mỗi mục yes / no / na (NULL = chưa đánh dấu).
CREATE TABLE academic.class_checklists (
    id                        uuid PRIMARY KEY DEFAULT uuidv7(),
    class_id                  uuid NOT NULL REFERENCES academic.classes (id),
    month                     text NOT NULL CHECK (month ~ '^\d{4}-(0[1-9]|1[0-2])$'),
    tuition_due               text CHECK (tuition_due IN ('yes', 'no', 'na')),
    tuition_reminded          text CHECK (tuition_reminded IN ('yes', 'no', 'na')),
    tuition_collected         text CHECK (tuition_collected IN ('yes', 'no', 'na')),
    tuition_note              text CHECK (length(tuition_note) <= 1000),
    big_test_due              text CHECK (big_test_due IN ('yes', 'no', 'na')),
    feedback_on_time          text CHECK (feedback_on_time IN ('yes', 'no', 'na')),
    negative_feedback_handled text CHECK (negative_feedback_handled IN ('yes', 'no', 'na')),
    updated_by                uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    created_at                timestamptz NOT NULL DEFAULT now(),
    updated_at                timestamptz NOT NULL DEFAULT now(),
    UNIQUE (class_id, month)
);

-- Báo cáo họp giáo viên theo tuần (Học thuật ghi); week_start là thứ Hai của tuần.
CREATE TABLE academic.teacher_meeting_reports (
    id             uuid PRIMARY KEY DEFAULT uuidv7(),
    week_start     date NOT NULL CHECK (extract(isodow FROM week_start) = 1),
    teacher_id     uuid NOT NULL REFERENCES iam.users (id),
    class_id       uuid REFERENCES academic.classes (id),
    author_id      uuid NOT NULL REFERENCES iam.users (id),
    syllabus_note  text CHECK (length(syllabus_note) <= 5000),
    scores_note    text CHECK (length(scores_note) <= 5000),
    class_note     text NOT NULL CHECK (length(class_note) BETWEEN 1 AND 5000),
    academic_order text CHECK (length(academic_order) <= 5000),
    recommendation text CHECK (length(recommendation) <= 5000),
    status         text NOT NULL CHECK (status IN ('handled', 'in_progress', 'sketchy', 'not_reported', 'duplicate')),
    created_at     timestamptz NOT NULL DEFAULT now(),
    updated_at     timestamptz NOT NULL DEFAULT now(),
    deleted_at     timestamptz
);
CREATE INDEX teacher_meeting_reports_week_idx ON academic.teacher_meeting_reports (week_start DESC) WHERE deleted_at IS NULL;
CREATE INDEX teacher_meeting_reports_author_idx ON academic.teacher_meeting_reports (author_id, week_start) WHERE deleted_at IS NULL;

-- +goose Down
DROP TABLE academic.teacher_meeting_reports;
DROP TABLE academic.class_checklists;
DROP TABLE academic.class_observations;
