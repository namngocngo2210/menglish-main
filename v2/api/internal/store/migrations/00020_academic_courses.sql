-- Trình độ (CEFR) và khóa học & bảng giá học phí (Laravel: course_levels, courses).
-- Lớp học (làm sau) tham chiếu course_levels.id và courses.id.
-- +goose Up
CREATE SCHEMA IF NOT EXISTS academic;

CREATE TABLE academic.course_levels (
    id                     uuid PRIMARY KEY DEFAULT uuidv7(),
    -- Mã trình độ (A1, B1, IELTS-5.5...): duy nhất kể cả bản ghi đã xóa như Laravel (unique:course_levels,code)
    code                   citext NOT NULL UNIQUE CHECK (length(code) BETWEEN 1 AND 20),
    name                   text NOT NULL CHECK (length(name) BETWEEN 1 AND 255),
    description            text CHECK (length(description) <= 1000),
    -- Nhóm trình độ (KIDS, TEENS, IELTS...), luôn viết hoa
    level_group            text CHECK (length(level_group) BETWEEN 1 AND 50),
    -- Cấp độ test đầu vào ứng với trình độ (xếp lớp lọc theo cấp độ test của học viên)
    grade_levels           text[] NOT NULL DEFAULT '{}' CHECK (grade_levels <@ ARRAY[
        'mau_giao', 'lop_1', 'lop_2', 'lop_3', 'lop_4', 'lop_5', 'lop_6', 'lop_7', 'lop_8', 'lop_9']::text[]),
    target                 text NOT NULL CHECK (length(target) BETWEEN 1 AND 255),
    lessons_count          integer NOT NULL CHECK (lessons_count >= 1),
    syllabus_curriculum_id uuid, -- FK tới syllabus.curricula thêm khi gộp
    is_active              boolean NOT NULL DEFAULT true,
    sort_order             integer NOT NULL DEFAULT 0,
    created_by             uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    created_at             timestamptz NOT NULL DEFAULT now(),
    updated_at             timestamptz NOT NULL DEFAULT now(),
    deleted_at             timestamptz
);
CREATE INDEX course_levels_order_idx ON academic.course_levels (sort_order, level_group, code) WHERE deleted_at IS NULL;

CREATE TABLE academic.courses (
    id              uuid PRIMARY KEY DEFAULT uuidv7(),
    -- Mã do hệ thống cấp CS0001, không dùng lại kể cả khi khóa đã xóa
    code            citext NOT NULL UNIQUE CHECK (length(code) BETWEEN 1 AND 50),
    name            text NOT NULL CHECK (length(name) BETWEEN 1 AND 255),
    course_level_id uuid REFERENCES academic.course_levels (id),
    -- Học phí niêm yết trọn gói (đồng)
    tuition_fee     bigint NOT NULL CHECK (tuition_fee >= 0),
    total_lessons   integer NOT NULL CHECK (total_lessons >= 1),
    description     text CHECK (length(description) <= 1000),
    is_active       boolean NOT NULL DEFAULT true,
    created_by      uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    created_at      timestamptz NOT NULL DEFAULT now(),
    updated_at      timestamptz NOT NULL DEFAULT now(),
    deleted_at      timestamptz
);
CREATE INDEX courses_level_idx ON academic.courses (course_level_id) WHERE deleted_at IS NULL;

-- +goose Down
DROP TABLE academic.courses;
DROP TABLE academic.course_levels;
