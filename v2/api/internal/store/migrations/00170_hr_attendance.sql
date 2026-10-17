-- Chấm công (Laravel: staff_attendances, staff_attendance_requests, teacher_timesheets, timesheet_sync_logs —
-- MyAttendanceController, StaffAttendanceController, StaffAttendanceService, phần chấm công của PayrollController).
-- Chấm công hằng ngày: mỗi nhân sự một dòng mỗi ngày, giờ vào / ra theo máy chủ, ảnh khuôn mặt ở kho file, GPS + khoảng cách
-- tới chi nhánh. Đơn chấm công: bổ sung công, xin đi muộn / về sớm, xin nghỉ. Giờ dạy giáo viên: ca dạy theo buổi học
-- (check-in ở cổng giáo viên, chấm tay của Học vụ, xác nhận theo lịch) để đối soát và tính lương.
-- +goose Up
CREATE SCHEMA IF NOT EXISTS hr;

CREATE TABLE hr.staff_attendances (
    id                 uuid PRIMARY KEY DEFAULT uuidv7(),
    user_id            uuid NOT NULL REFERENCES iam.users (id) ON DELETE CASCADE,
    -- Chi nhánh nơi chấm (chi nhánh chính của nhân sự lúc chấm)
    branch_id          uuid REFERENCES core.branches (id) ON DELETE SET NULL,
    work_date          date NOT NULL,
    -- mobile: tự chấm (ảnh + GPS); request: tạo / sửa bởi đơn bổ sung công đã duyệt
    source             text NOT NULL DEFAULT 'mobile' CHECK (source IN ('mobile', 'request')),
    -- Giờ phải có mặt / được về (buổi dạy đầu / cuối với GV, TA; giờ làm việc chi nhánh với người khác)
    expected_start     time,
    expected_end       time,
    check_in_at        timestamptz,
    check_in_photo_id  uuid REFERENCES core.files (id) ON DELETE SET NULL,
    check_in_lat       numeric(10, 7) CHECK (check_in_lat BETWEEN -90 AND 90),
    check_in_lng       numeric(10, 7) CHECK (check_in_lng BETWEEN -180 AND 180),
    check_in_distance  integer CHECK (check_in_distance >= 0),
    check_in_accuracy  integer CHECK (check_in_accuracy >= 0),
    check_out_at       timestamptz,
    check_out_photo_id uuid REFERENCES core.files (id) ON DELETE SET NULL,
    check_out_lat      numeric(10, 7) CHECK (check_out_lat BETWEEN -90 AND 90),
    check_out_lng      numeric(10, 7) CHECK (check_out_lng BETWEEN -180 AND 180),
    check_out_distance integer CHECK (check_out_distance >= 0),
    check_out_accuracy integer CHECK (check_out_accuracy >= 0),
    late_minutes       integer NOT NULL DEFAULT 0 CHECK (late_minutes BETWEEN 0 AND 65535),
    early_minutes      integer NOT NULL DEFAULT 0 CHECK (early_minutes BETWEEN 0 AND 65535),
    -- Muộn có phép: có đơn xin đi muộn / xin nghỉ đã duyệt cho ngày này
    late_excused       boolean NOT NULL DEFAULT false,
    -- Biên bản đi muộn tự động. FK tới bảng biên bản của phân hệ Lương & Phạt thêm khi gộp
    penalty_id         uuid,
    note               text CHECK (length(note) <= 500),
    created_at         timestamptz NOT NULL DEFAULT now(),
    updated_at         timestamptz NOT NULL DEFAULT now(),
    UNIQUE (user_id, work_date)
);
CREATE INDEX staff_attendances_branch_idx ON hr.staff_attendances (branch_id, work_date);
CREATE INDEX staff_attendances_date_idx ON hr.staff_attendances (work_date);

CREATE TABLE hr.staff_attendance_requests (
    id               uuid PRIMARY KEY DEFAULT uuidv7(),
    user_id          uuid NOT NULL REFERENCES iam.users (id) ON DELETE CASCADE,
    -- Chi nhánh chính của người gửi lúc gửi (phạm vi duyệt)
    branch_id        uuid REFERENCES core.branches (id) ON DELETE SET NULL,
    type             text NOT NULL CHECK (type IN ('correction', 'late_early', 'leave')),
    date_from        date NOT NULL,
    date_to          date NOT NULL CHECK (date_to >= date_from),
    -- Giờ khai của đơn bổ sung công
    check_in_time    time,
    check_out_time   time,
    reason           text NOT NULL CHECK (length(reason) BETWEEN 1 AND 1000),
    status           text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'approved', 'rejected', 'cancelled')),
    reviewed_by      uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    reviewed_at      timestamptz,
    rejection_reason text CHECK (length(rejection_reason) <= 1000),
    created_at       timestamptz NOT NULL DEFAULT now(),
    updated_at       timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX staff_attendance_requests_status_idx ON hr.staff_attendance_requests (status, branch_id);
CREATE INDEX staff_attendance_requests_user_idx ON hr.staff_attendance_requests (user_id, date_from);

CREATE TABLE hr.teacher_timesheets (
    id                  uuid PRIMARY KEY DEFAULT uuidv7(),
    user_id             uuid NOT NULL REFERENCES iam.users (id) ON DELETE CASCADE,
    class_id            uuid REFERENCES academic.classes (id) ON DELETE SET NULL,
    class_session_id    uuid REFERENCES academic.class_sessions (id) ON DELETE SET NULL,
    teaching_date       date NOT NULL,
    -- Giờ theo lịch buổi học "HH:MM-HH:MM" (ca chấm tay không gắn buổi: trống)
    scheduled_time      text CHECK (length(scheduled_time) <= 20),
    checkin_time        time,
    checkout_time       time,
    hours               numeric(5, 2) NOT NULL DEFAULT 0 CHECK (hours >= 0),
    late_minutes        integer NOT NULL DEFAULT 0 CHECK (late_minutes BETWEEN 0 AND 1440),
    early_leave_minutes integer NOT NULL DEFAULT 0 CHECK (early_leave_minutes BETWEEN 0 AND 1440),
    -- Đi muộn / về sớm có báo trước (trả theo số phút thực dạy)
    late_notified       boolean NOT NULL DEFAULT false,
    -- Đơn giá giờ riêng của ca (VND); trống = đơn giá của giáo viên (phân hệ Lương)
    hourly_rate         bigint CHECK (hourly_rate >= 0),
    type                text NOT NULL DEFAULT 'regular' CHECK (type IN ('regular', 'sub', '1on1', 'grading', 'workshop')),
    -- checkin: GV tự check-in ở cổng giáo viên; manual: Học vụ chấm tay; schedule: xác nhận theo lịch buổi học
    source              text NOT NULL CHECK (source IN ('checkin', 'manual', 'schedule')),
    status              text NOT NULL DEFAULT 'pending_review' CHECK (status IN ('pending_review', 'valid', 'invalid')),
    reviewed_by         uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    reviewed_at         timestamptz,
    rejection_reason    text CHECK (length(rejection_reason) <= 1000),
    adjusted_at         timestamptz,
    adjusted_by         uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    adjustment_reason   text CHECK (length(adjustment_reason) <= 500),
    notes               text CHECK (length(notes) <= 2000),
    created_by          uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    created_at          timestamptz NOT NULL DEFAULT now(),
    updated_at          timestamptz NOT NULL DEFAULT now()
);
-- Một ca mỗi người mỗi buổi học (ca bị từ chối được ghi đè khi chấm lại)
CREATE UNIQUE INDEX teacher_timesheets_session_uq ON hr.teacher_timesheets (user_id, class_session_id)
    WHERE class_session_id IS NOT NULL;
CREATE INDEX teacher_timesheets_user_idx ON hr.teacher_timesheets (user_id, teaching_date);
CREATE INDEX teacher_timesheets_date_idx ON hr.teacher_timesheets (teaching_date, status);
CREATE INDEX teacher_timesheets_class_idx ON hr.teacher_timesheets (class_id, teaching_date);
CREATE INDEX teacher_timesheets_session_idx ON hr.teacher_timesheets (class_session_id) WHERE class_session_id IS NOT NULL;

-- Một đợt đồng bộ chấm công từ thiết bị / AppSheet (chưa có tích hợp nào ghi, như Laravel).
CREATE TABLE hr.timesheet_sync_logs (
    id            uuid PRIMARY KEY DEFAULT uuidv7(),
    branch_id     uuid REFERENCES core.branches (id) ON DELETE SET NULL,
    device_name   text NOT NULL CHECK (length(device_name) BETWEEN 1 AND 255),
    device_ip     text CHECK (length(device_ip) <= 64),
    source        text CHECK (length(source) <= 50),
    records_count integer NOT NULL DEFAULT 0 CHECK (records_count >= 0),
    matched_count integer NOT NULL DEFAULT 0 CHECK (matched_count >= 0),
    failed_count  integer NOT NULL DEFAULT 0 CHECK (failed_count >= 0),
    -- Bỏ qua vì kỳ lương đã chốt
    skipped_count integer NOT NULL DEFAULT 0 CHECK (skipped_count >= 0),
    -- error: giá trị cũ, hiển thị như failed
    status        text NOT NULL DEFAULT 'success' CHECK (status IN ('success', 'partial', 'failed', 'error')),
    error_code    text CHECK (length(error_code) <= 100),
    error_message text CHECK (length(error_message) <= 5000),
    -- [{employee_code, employee_name, code, message}]
    error_rows    jsonb CHECK (error_rows IS NULL OR jsonb_typeof(error_rows) = 'array'),
    created_at    timestamptz NOT NULL DEFAULT now(),
    updated_at    timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX timesheet_sync_logs_created_idx ON hr.timesheet_sync_logs (created_at DESC);

-- +goose Down
DROP TABLE hr.timesheet_sync_logs;
DROP TABLE hr.teacher_timesheets;
DROP TABLE hr.staff_attendance_requests;
DROP TABLE hr.staff_attendances;
