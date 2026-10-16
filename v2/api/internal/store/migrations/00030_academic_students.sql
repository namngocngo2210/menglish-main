-- Hồ sơ học viên (bản Laravel: bảng students), phụ huynh gắn với học viên và lịch sử hồ sơ.
-- Học viên chỉ sinh ra từ luồng Lead → Chốt (CRM); mỗi học viên có một tài khoản cổng học viên (iam.users kind 'student',
-- đăng nhập bằng mã HV-00001). Phụ huynh là tài khoản iam.users kind 'guardian' (đăng nhập bằng SĐT), một phụ huynh
-- gắn được nhiều con qua academic.student_guardians.
-- +goose Up
CREATE SCHEMA IF NOT EXISTS academic;

-- Số thứ tự cho mã học viên HV-00001 (mã cũ dạng HV-<ULID> nạp từ Laravel giữ nguyên).
CREATE SEQUENCE academic.student_code_seq;

CREATE TABLE academic.students (
    id               uuid PRIMARY KEY DEFAULT uuidv7(),
    code             citext NOT NULL CHECK (length(code) BETWEEN 1 AND 40),
    -- Tài khoản cổng học viên (phụ huynh nhiều con dùng chung email ở Laravel; v2 mỗi học viên một tài khoản)
    user_id          uuid UNIQUE REFERENCES iam.users (id) ON DELETE SET NULL,
    full_name        text NOT NULL CHECK (length(full_name) BETWEEN 1 AND 255),
    phone            text NOT NULL CHECK (length(phone) BETWEEN 1 AND 20),
    email            citext CHECK (length(email) <= 255),
    dob              date,
    gender           text CHECK (length(gender) <= 20),
    address          text CHECK (length(address) <= 255),
    school           text CHECK (length(school) <= 255),
    -- Liên hệ phụ huynh chép từ Lead (gửi kết quả Big Test qua Zalo)
    parent_name      text CHECK (length(parent_name) <= 255),
    parent_phone     text CHECK (length(parent_phone) <= 20),
    branch_id        uuid REFERENCES core.branches (id),
    current_class_id uuid, -- FK tới academic.classes: 00081_academic_enrollments_attendance
    lead_id          uuid, -- FK tới crm.leads: 00110_academic_links_fk (Lead đã chốt ra học viên này)
    target           text CHECK (length(target) <= 255),
    entrance_score   text CHECK (length(entrance_score) <= 255),
    total_lessons    integer CHECK (total_lessons >= 0),
    status           text NOT NULL DEFAULT 'waiting_start'
        CHECK (status IN ('waiting_start', 'studying', 'deferred', 'summer_break', 'completed', 'dropped')),
    -- Ngày vào học (buổi có mặt đầu tiên hoặc Học vụ nhập tay) để tính thâm niên
    study_started_on date,
    notes            text CHECK (length(notes) <= 5000),
    created_by       uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    created_at       timestamptz NOT NULL DEFAULT now(),
    updated_at       timestamptz NOT NULL DEFAULT now(),
    deleted_at       timestamptz,
    version          integer NOT NULL DEFAULT 1
);
-- Mã học viên không trùng, bản ghi đã xóa không chiếm chỗ
CREATE UNIQUE INDEX students_code_uq ON academic.students (code) WHERE deleted_at IS NULL;
CREATE INDEX students_branch_idx ON academic.students (branch_id, created_at DESC) WHERE deleted_at IS NULL;
CREATE INDEX students_class_idx ON academic.students (current_class_id) WHERE deleted_at IS NULL;
CREATE INDEX students_lead_idx ON academic.students (lead_id) WHERE lead_id IS NOT NULL;

-- Phụ huynh của học viên: tài khoản iam.users kind 'guardian'.
CREATE TABLE academic.student_guardians (
    student_id   uuid NOT NULL REFERENCES academic.students (id) ON DELETE CASCADE,
    guardian_id  uuid NOT NULL REFERENCES iam.users (id) ON DELETE CASCADE,
    relationship text CHECK (length(relationship) <= 50),
    created_by   uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    created_at   timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (student_id, guardian_id)
);
CREATE INDEX student_guardians_guardian_idx ON academic.student_guardians (guardian_id);

-- Lịch sử hồ sơ (Laravel ghi nối vào ghi chú và activity log): tạo, sửa, đổi trạng thái, bảo lưu, xóa, phụ huynh, tài khoản.
CREATE TABLE academic.student_events (
    id          uuid PRIMARY KEY DEFAULT uuidv7(),
    student_id  uuid NOT NULL REFERENCES academic.students (id) ON DELETE CASCADE,
    kind        text NOT NULL CHECK (kind IN ('created', 'updated', 'status_changed', 'deferral_ended', 'deleted',
                                              'guardian_linked', 'guardian_unlinked', 'account_reset')),
    message     text NOT NULL CHECK (length(message) BETWEEN 1 AND 2000),
    from_status text,
    to_status   text,
    created_by  uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    created_at  timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX student_events_student_idx ON academic.student_events (student_id, created_at DESC);

-- +goose Down
DROP TABLE academic.student_events;
DROP TABLE academic.student_guardians;
DROP TABLE academic.students;
DROP SEQUENCE academic.student_code_seq;
