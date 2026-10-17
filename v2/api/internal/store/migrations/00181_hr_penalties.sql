-- Biên bản vi phạm (Laravel: penalties — PenaltyController, Penalty, BPMN 9b):
--   pending (chờ giải trình) → explained (đã giải trình) → confirmed (xác nhận lỗi) | fined (quyết phạt, hạn nộp)
--   | resolved (đóng, không phạt) | cancelled (hủy); fined → paid (nộp trực tiếp) | deducted (trừ vào kỳ lương đã duyệt).
-- Biên bản tự động (SLA của phân hệ khác) không có reporter_id; auto_source + auto_key chống lập trùng.
-- +goose Up
CREATE TABLE hr.penalties (
    id                uuid PRIMARY KEY DEFAULT uuidv7(),
    code              text NOT NULL UNIQUE CHECK (length(code) BETWEEN 1 AND 40),
    user_id           uuid NOT NULL REFERENCES iam.users (id),
    class_id          uuid REFERENCES academic.classes (id) ON DELETE SET NULL,
    work_task_id      uuid REFERENCES ops.work_tasks (id) ON DELETE SET NULL,
    big_test_id       uuid, -- FK tới bảng Big Test của phân hệ Giáo trình thêm khi gộp
    auto_source       text CHECK (length(auto_source) <= 60),
    auto_key          text CHECK (length(auto_key) <= 200),
    violation_type    text NOT NULL CHECK (length(violation_type) BETWEEN 1 AND 255),
    error_category    text CHECK (error_category IN ('academic', 'operations')),
    violation_date    date NOT NULL,
    violation_at      timestamptz,
    amount            bigint NOT NULL DEFAULT 0 CHECK (amount >= 0),
    reporter_id       uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    status            text NOT NULL DEFAULT 'pending'
        CHECK (status IN ('pending', 'explained', 'confirmed', 'fined', 'paid', 'deducted', 'resolved', 'cancelled')),
    payroll_record_id uuid REFERENCES hr.payroll_records (id) ON DELETE SET NULL,
    notes             text CHECK (length(notes) <= 2000),
    evidence_file_id  uuid REFERENCES core.files (id) ON DELETE SET NULL,
    explanation       text CHECK (length(explanation) <= 2000),
    explained_at      timestamptz,
    decided_by        uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    decided_at        timestamptz,
    decision_note     text CHECK (length(decision_note) <= 1000),
    due_date          date,
    paid_at           timestamptz,
    remedied_at       timestamptz,
    remedied_by       uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    remedy_note       text CHECK (length(remedy_note) <= 1000),
    legacy_id         bigint UNIQUE,
    created_at        timestamptz NOT NULL DEFAULT now(),
    updated_at        timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX penalties_user_idx ON hr.penalties (user_id, violation_date DESC);
CREATE INDEX penalties_status_idx ON hr.penalties (status, due_date);
CREATE INDEX penalties_record_idx ON hr.penalties (payroll_record_id) WHERE payroll_record_id IS NOT NULL;
CREATE UNIQUE INDEX penalties_auto_key ON hr.penalties (auto_source, auto_key) WHERE auto_key IS NOT NULL;

-- +goose Down
DROP TABLE hr.penalties;
