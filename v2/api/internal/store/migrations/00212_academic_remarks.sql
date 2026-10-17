-- Cổng giáo viên: nhận xét buổi học cho từng học sinh (Laravel academic_records module teacher_remarks, mã
-- {lớp}-{ngày}-s{buổi}, data = {học viên: {monsters_group, monsters_bonus, grammar, attitude, result, comment}} —
-- TeacherPortalController::remarks / remarksStore). Một phiếu mỗi buổi; "Lưu nháp" chưa hiện cho học viên.
-- +goose Up
CREATE TABLE academic.remark_sheets (
    id         uuid PRIMARY KEY DEFAULT uuidv7(),
    session_id uuid NOT NULL UNIQUE REFERENCES academic.class_sessions (id) ON DELETE CASCADE,
    class_id   uuid NOT NULL REFERENCES academic.classes (id) ON DELETE CASCADE,
    -- draft: lưu nháp (cổng học viên chưa thấy); completed: đã lưu nhận xét
    status     text NOT NULL CHECK (status IN ('draft', 'completed')),
    created_by uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    -- Người lưu gần nhất (Laravel user_id)
    updated_by uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX remark_sheets_class_idx ON academic.remark_sheets (class_id, created_at DESC);

CREATE TABLE academic.remarks (
    sheet_id       uuid NOT NULL REFERENCES academic.remark_sheets (id) ON DELETE CASCADE,
    student_id     uuid NOT NULL REFERENCES academic.students (id) ON DELETE CASCADE,
    monsters_group text CHECK (length(monsters_group) <= 2000),
    monsters_bonus text CHECK (length(monsters_bonus) <= 2000),
    grammar        text CHECK (length(grammar) <= 2000),
    attitude       text CHECK (length(attitude) <= 2000),
    result         text CHECK (length(result) <= 2000),
    comment        text CHECK (length(comment) <= 2000),
    PRIMARY KEY (sheet_id, student_id)
);
CREATE INDEX remarks_student_idx ON academic.remarks (student_id);

-- +goose Down
DROP TABLE academic.remarks;
DROP TABLE academic.remark_sheets;
