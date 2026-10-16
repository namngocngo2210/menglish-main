-- Lớp học, ca học lặp tuần và buổi học (Laravel: classes, class_schedule_configs, class_sessions).
-- Nhân sự theo buổi (GV chính / GVNN / trợ giảng) là cột của buổi học như Laravel: GVNN và trợ giảng không cố định
-- theo lớp nên mỗi buổi giữ người thực tế của buổi đó.
-- +goose Up
CREATE SCHEMA IF NOT EXISTS academic;

CREATE TABLE academic.classes (
    id                 uuid PRIMARY KEY DEFAULT uuidv7(),
    -- Mã lớp viết hoa; để trống thì sinh từ chương trình (IELTS-AB12), trùng thì thêm -1, -2…
    code               citext NOT NULL CHECK (length(code) BETWEEN 1 AND 60),
    name               text NOT NULL CHECK (length(name) BETWEEN 1 AND 255),
    branch_id          uuid NOT NULL REFERENCES core.branches (id),
    -- Chương trình (tên khóa học hoặc giá trị cũ IELTS, TOEIC…) và khóa học khớp tên (nếu có)
    course_id          uuid REFERENCES academic.courses (id),
    program            text CHECK (length(program) <= 100),
    -- Cấp độ: mã trình độ (academic.course_levels.code) hoặc giá trị cũ B1, FOUNDATION…
    level              text CHECK (length(level) <= 100),
    teacher_id         uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    -- Trợ giảng cố định chỉ còn ở dữ liệu cũ (nay trợ giảng làm theo ca + giao việc)
    assistant_id       uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    -- GVNN mặc định: buổi sinh mới nhận GVNN này, từng buổi đổi được riêng
    foreign_teacher_id uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    room_id            uuid REFERENCES core.rooms (id),
    -- Tên phòng chép từ core.rooms (buổi học tra phòng theo chi nhánh + tên)
    room               text CHECK (length(room) <= 100),
    schedule_text      text CHECK (length(schedule_text) <= 500),
    academic_year      text CHECK (length(academic_year) <= 30),
    start_date         date,
    end_date           date,
    -- 0 = không giới hạn sĩ số (dữ liệu cũ); form bắt buộc 1–100
    max_capacity       integer NOT NULL DEFAULT 0 CHECK (max_capacity BETWEEN 0 AND 100),
    -- Ngưỡng khai giảng: số học viên tối thiểu để mở lớp
    min_students       integer NOT NULL DEFAULT 6 CHECK (min_students BETWEEN 0 AND 100),
    tuition_fee        bigint CHECK (tuition_fee >= 0),
    notes              text CHECK (length(notes) <= 5000),
    status             text NOT NULL DEFAULT 'pending_schedule'
        CHECK (status IN ('pending_schedule', 'upcoming', 'active', 'completed', 'cancelled')),
    created_by         uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    created_at         timestamptz NOT NULL DEFAULT now(),
    updated_at         timestamptz NOT NULL DEFAULT now(),
    deleted_at         timestamptz,
    version            integer NOT NULL DEFAULT 1
);
CREATE UNIQUE INDEX classes_code_uq ON academic.classes (code) WHERE deleted_at IS NULL;
CREATE INDEX classes_branch_idx ON academic.classes (branch_id, status) WHERE deleted_at IS NULL;
CREATE INDEX classes_course_idx ON academic.classes (course_id) WHERE deleted_at IS NULL;
CREATE INDEX classes_level_idx ON academic.classes (level) WHERE deleted_at IS NULL;
CREATE INDEX classes_room_idx ON academic.classes (room_id) WHERE deleted_at IS NULL;
CREATE INDEX classes_teacher_idx ON academic.classes (teacher_id) WHERE deleted_at IS NULL;
CREATE INDEX classes_foreign_idx ON academic.classes (foreign_teacher_id) WHERE deleted_at IS NULL;
CREATE INDEX classes_assistant_idx ON academic.classes (assistant_id) WHERE assistant_id IS NOT NULL;

-- Ca học lặp tuần của lớp (Laravel class_schedule_configs slot1/slot2): tối đa 2 ca.
CREATE TABLE academic.class_slots (
    class_id   uuid NOT NULL REFERENCES academic.classes (id) ON DELETE CASCADE,
    position   smallint NOT NULL CHECK (position BETWEEN 1 AND 2),
    -- Thứ theo ISO: 1 = Thứ 2 … 7 = Chủ nhật
    weekday    smallint NOT NULL CHECK (weekday BETWEEN 1 AND 7),
    start_time time NOT NULL,
    end_time   time NOT NULL CHECK (end_time > start_time),
    -- Tên ca theo khung giờ ca dạy (Ca 1, Ca 2, Ca chiều, Ca theo TKB…)
    shift_name text NOT NULL CHECK (length(shift_name) BETWEEN 1 AND 50),
    created_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (class_id, position)
);

CREATE TABLE academic.class_sessions (
    id                  uuid PRIMARY KEY DEFAULT uuidv7(),
    class_id            uuid NOT NULL REFERENCES academic.classes (id) ON DELETE CASCADE,
    -- Chi nhánh của buổi (lịch, nghỉ lễ, chấm công lọc theo cột này)
    branch_id           uuid NOT NULL REFERENCES core.branches (id),
    date                date NOT NULL,
    shift_name          text CHECK (length(shift_name) <= 50),
    -- regular: chính khóa; makeup: học bù (nghỉ lễ); support: phụ đạo 1-1
    type                text NOT NULL DEFAULT 'regular' CHECK (type IN ('regular', 'support', 'makeup')),
    start_time          time NOT NULL,
    end_time            time NOT NULL CHECK (end_time > start_time),
    room                text CHECK (length(room) <= 100),
    teacher_id          uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    foreign_teacher_id  uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    assistant_id        uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    -- completed: đã điểm danh (buổi đã diễn ra, không bị xếp lại)
    status              text NOT NULL DEFAULT 'scheduled' CHECK (status IN ('scheduled', 'completed', 'cancelled')),
    notes               text CHECK (length(notes) <= 2000),
    -- Ngày nghỉ lễ đã làm buổi bị hủy
    holiday_id          uuid REFERENCES core.holidays (id) ON DELETE SET NULL,
    -- Buổi học bù: buổi gốc (bị hủy vì nghỉ lễ) mà buổi này thay thế
    rescheduled_from_id uuid REFERENCES academic.class_sessions (id) ON DELETE SET NULL,
    created_by          uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    created_at          timestamptz NOT NULL DEFAULT now(),
    updated_at          timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX class_sessions_class_idx ON academic.class_sessions (class_id, date, start_time);
CREATE INDEX class_sessions_branch_idx ON academic.class_sessions (branch_id, date) WHERE status <> 'cancelled';
CREATE INDEX class_sessions_date_idx ON academic.class_sessions (date) WHERE status <> 'cancelled';
CREATE INDEX class_sessions_teacher_idx ON academic.class_sessions (teacher_id, date) WHERE teacher_id IS NOT NULL;
CREATE INDEX class_sessions_foreign_idx ON academic.class_sessions (foreign_teacher_id, date) WHERE foreign_teacher_id IS NOT NULL;
CREATE INDEX class_sessions_assistant_idx ON academic.class_sessions (assistant_id, date) WHERE assistant_id IS NOT NULL;
CREATE INDEX class_sessions_holiday_idx ON academic.class_sessions (holiday_id) WHERE holiday_id IS NOT NULL;
CREATE UNIQUE INDEX class_sessions_makeup_uq ON academic.class_sessions (rescheduled_from_id) WHERE rescheduled_from_id IS NOT NULL;

-- +goose Down
DROP TABLE academic.class_sessions;
DROP TABLE academic.class_slots;
DROP TABLE academic.classes;
