-- Lượt xếp lớp (Laravel class_enrollments) và điểm danh học viên theo buổi (Laravel student_attendances).
-- Gắn khóa ngoại lớp cho các cột đã chờ sẵn ở module khác (academic.students.current_class_id,
-- ops.material_orders.class_id).
-- +goose Up
CREATE TABLE academic.enrollments (
    id                   uuid PRIMARY KEY DEFAULT uuidv7(),
    student_id           uuid NOT NULL REFERENCES academic.students (id) ON DELETE CASCADE,
    class_id             uuid NOT NULL REFERENCES academic.classes (id) ON DELETE CASCADE,
    lead_id              uuid, -- FK tới crm.leads thêm khi gộp (lượt xếp lớp từ CRM)
    enrolled_at          date NOT NULL,
    -- Ngày rời lớp (chuyển lớp chính / thôi học): từ ngày này không còn trong danh sách lớp, không trừ buổi
    left_at              date,
    -- Checklist bàn giao / xác nhận chính thức
    curriculum_delivered boolean NOT NULL DEFAULT false,
    zalo_group_added     boolean NOT NULL DEFAULT false,
    account_sent         boolean NOT NULL DEFAULT false,
    -- pending: chờ bàn giao; completed: đã bàn giao xong; dropped: học viên thôi học
    status               text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'completed', 'dropped')),
    confirmed_at         timestamptz,
    confirmed_by         uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    created_by           uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    created_at           timestamptz NOT NULL DEFAULT now(),
    updated_at           timestamptz NOT NULL DEFAULT now(),
    CHECK (left_at IS NULL OR left_at >= enrolled_at)
);
-- Một học viên chỉ có một lượt còn hiệu lực trong một lớp
CREATE UNIQUE INDEX enrollments_active_uq ON academic.enrollments (student_id, class_id)
    WHERE status <> 'dropped' AND left_at IS NULL;
CREATE INDEX enrollments_class_idx ON academic.enrollments (class_id, status);
CREATE INDEX enrollments_student_idx ON academic.enrollments (student_id, created_at DESC);

CREATE TABLE academic.student_attendance (
    id            uuid PRIMARY KEY DEFAULT uuidv7(),
    session_id    uuid NOT NULL REFERENCES academic.class_sessions (id) ON DELETE CASCADE,
    class_id      uuid NOT NULL REFERENCES academic.classes (id) ON DELETE CASCADE,
    student_id    uuid NOT NULL REFERENCES academic.students (id) ON DELETE CASCADE,
    session_date  date NOT NULL,
    status        text NOT NULL CHECK (status IN ('present', 'absent', 'late', 'excused')),
    note          text CHECK (length(note) <= 500),
    -- Giáo viên của buổi (Học vụ điểm danh thay thì vẫn ghi GV của buổi) và người thực sự lưu
    teacher_id    uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    recorded_by   uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    -- Rà soát điểm danh (KPI): lưu lại điểm danh thì về chờ rà soát
    review_status text NOT NULL DEFAULT 'pending_review' CHECK (review_status IN ('pending_review', 'approved', 'rejected')),
    reviewed_by   uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    reviewed_at   timestamptz,
    review_note   text CHECK (length(review_note) <= 1000),
    created_at    timestamptz NOT NULL DEFAULT now(),
    updated_at    timestamptz NOT NULL DEFAULT now(),
    UNIQUE (session_id, student_id)
);
CREATE INDEX student_attendance_class_idx ON academic.student_attendance (class_id, session_date);
CREATE INDEX student_attendance_student_idx ON academic.student_attendance (student_id, session_date DESC);

-- Danh sách lớp thật: học viên có lớp chính là lớp này + học viên có lượt xếp lớp còn hiệu lực ("Liên kết lớp khác"),
-- chỉ học viên còn giữ chỗ (Chờ khai giảng / Đang học / Nghỉ hè). Dùng cho sĩ số, điểm danh, tab Học viên.
CREATE VIEW academic.class_roster AS
SELECT s.current_class_id AS class_id, s.id AS student_id, true AS is_main
FROM academic.students s
WHERE s.deleted_at IS NULL AND s.current_class_id IS NOT NULL AND s.status IN ('waiting_start', 'studying', 'summer_break')
UNION ALL
SELECT e.class_id, e.student_id, false AS is_main
FROM academic.enrollments e
JOIN academic.students s ON s.id = e.student_id
WHERE s.deleted_at IS NULL AND s.status IN ('waiting_start', 'studying', 'summer_break')
  AND e.status IN ('pending', 'completed') AND e.left_at IS NULL
  AND e.class_id IS DISTINCT FROM s.current_class_id;

ALTER TABLE academic.students
    ADD CONSTRAINT students_current_class_fk FOREIGN KEY (current_class_id) REFERENCES academic.classes (id) ON DELETE SET NULL;
ALTER TABLE ops.material_orders
    ADD CONSTRAINT material_orders_class_fk FOREIGN KEY (class_id) REFERENCES academic.classes (id) ON DELETE SET NULL;

-- +goose Down
ALTER TABLE ops.material_orders DROP CONSTRAINT material_orders_class_fk;
ALTER TABLE academic.students DROP CONSTRAINT students_current_class_fk;
DROP VIEW academic.class_roster;
DROP TABLE academic.student_attendance;
DROP TABLE academic.enrollments;
