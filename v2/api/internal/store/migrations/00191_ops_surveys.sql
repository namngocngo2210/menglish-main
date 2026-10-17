-- Khảo sát chất lượng đào tạo (bản Laravel: surveys + academic_records screen_key 04_.../06_khao_sat): Học vụ tạo đợt
-- khảo sát + hạn, học viên / phụ huynh chấm sao và góp ý ở Cổng PH/HS. v2: câu trả lời nằm ở bảng riêng có khóa ngoại.
-- Schema ops dùng chung: không xóa schema ở Down.
-- +goose Up
CREATE SCHEMA IF NOT EXISTS ops;

CREATE TABLE ops.surveys (
    id          uuid PRIMARY KEY DEFAULT uuidv7(),
    title       text NOT NULL CHECK (length(title) BETWEEN 1 AND 255),
    description text CHECK (length(description) <= 2000),
    deadline    date,
    is_active   boolean NOT NULL DEFAULT true,
    created_by  uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    legacy_id   bigint UNIQUE,
    created_at  timestamptz NOT NULL DEFAULT now(),
    updated_at  timestamptz NOT NULL DEFAULT now(),
    deleted_at  timestamptz
);
CREATE INDEX surveys_active_idx ON ops.surveys (deadline) WHERE is_active AND deleted_at IS NULL;

-- Bài khảo sát học viên / phụ huynh đã nộp. Đợt bị xóa (mềm) vẫn giữ lịch sử; survey_title chụp tên đợt lúc nộp.
CREATE TABLE ops.survey_responses (
    id           uuid PRIMARY KEY DEFAULT uuidv7(),
    survey_id    uuid REFERENCES ops.surveys (id) ON DELETE SET NULL,
    survey_title text NOT NULL CHECK (length(survey_title) BETWEEN 1 AND 255),
    student_id   uuid NOT NULL REFERENCES academic.students (id) ON DELETE CASCADE,
    -- Tài khoản gửi (học viên hoặc phụ huynh)
    user_id      uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    rating       smallint NOT NULL DEFAULT 5 CHECK (rating BETWEEN 1 AND 5),
    feedback     text NOT NULL CHECK (length(feedback) BETWEEN 5 AND 5000),
    legacy_id    bigint UNIQUE,
    created_at   timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX survey_responses_student_idx ON ops.survey_responses (student_id, created_at DESC);
CREATE INDEX survey_responses_survey_idx ON ops.survey_responses (survey_id, created_at DESC);

-- +goose Down
DROP TABLE ops.survey_responses;
DROP TABLE ops.surveys;
