-- Nhắc học phí theo mốc (NotificationService::notifyDebtReminderByMilestone): mỗi (khoản, mốc, ngày) gửi một lần — thay
-- bản ghi AcademicRecord "DEBTREMIND-<mốc>-<id>-<ngày>" của Laravel. Thu hồi hoa hồng khi duyệt hoàn phí (A6).
-- +goose Up
CREATE TABLE finance.debt_reminder_logs (
    id         uuid PRIMARY KEY DEFAULT uuidv7(),
    tuition_id uuid NOT NULL REFERENCES finance.tuitions (id) ON DELETE CASCADE,
    milestone  text NOT NULL CHECK (length(milestone) BETWEEN 1 AND 20),
    sent_on    date NOT NULL,
    title      text NOT NULL CHECK (length(title) <= 255),
    content    text NOT NULL CHECK (length(content) <= 5000),
    channels   text[] NOT NULL DEFAULT '{}',
    -- Người bấm gửi (NULL = lịch tự động)
    sent_by    uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (tuition_id, milestone, sent_on)
);

-- Người duyệt hoàn phí chọn có thu hồi hoa hồng của sale hay không (SalesCommissionService::recordRefundDecision).
ALTER TABLE finance.refund_requests
    ADD COLUMN clawback_commission boolean,
    ADD COLUMN clawback_amount     bigint CHECK (clawback_amount >= 0),
    ADD COLUMN clawback_user_id    uuid REFERENCES iam.users (id) ON DELETE SET NULL;

-- +goose Down
ALTER TABLE finance.refund_requests DROP COLUMN clawback_user_id, DROP COLUMN clawback_amount, DROP COLUMN clawback_commission;
DROP TABLE finance.debt_reminder_logs;
