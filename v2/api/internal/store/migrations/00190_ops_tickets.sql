-- Ticket hỗ trợ (bản Laravel: support_tickets, ticket_messages): nhân sự báo lỗi / yêu cầu hỗ trợ, người xử lý
-- (support_ticket.update / assign / close) phân công, trao đổi, ghi chú nội bộ, đổi trạng thái
-- Mới tiếp nhận → Đang xử lý → Đã giải quyết / Đã đóng (mở lại được). File đính kèm nằm ở core.files
-- (owner_module 'tickets', subject_type 'ticket_attachment', subject_id = id tin nhắn).
-- Schema ops dùng chung với công việc, báo cáo, kho: không xóa schema ở Down.
-- +goose Up
CREATE SCHEMA IF NOT EXISTS ops;

CREATE TABLE ops.tickets (
    id               uuid PRIMARY KEY DEFAULT uuidv7(),
    -- TK-2026-0001 (ticket cũ của Laravel mã 4 chữ số giữ nguyên)
    code             text NOT NULL UNIQUE CHECK (length(code) BETWEEN 1 AND 40),
    -- Mã lần gửi của form tạo ticket: cùng người tạo + cùng mã chỉ ra một ticket (gửi lặp khi mạng lỗi)
    submission_token text CHECK (length(submission_token) BETWEEN 1 AND 64),
    title            text NOT NULL CHECK (length(title) BETWEEN 1 AND 255),
    category         text NOT NULL DEFAULT 'technical_issue'
        CHECK (category IN ('technical_issue', 'facilities', 'curriculum', 'tuition', 'customer_complaint', 'other')),
    priority         text NOT NULL DEFAULT 'medium' CHECK (priority IN ('low', 'medium', 'high', 'urgent')),
    status           text NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'in_progress', 'resolved', 'closed')),
    creator_id       uuid NOT NULL REFERENCES iam.users (id),
    assignee_id      uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    description      text NOT NULL CHECK (length(description) BETWEEN 1 AND 20000),
    resolved_at      timestamptz,
    legacy_id        bigint UNIQUE,
    created_at       timestamptz NOT NULL DEFAULT now(),
    updated_at       timestamptz NOT NULL DEFAULT now(),
    UNIQUE (creator_id, submission_token)
);
CREATE INDEX tickets_created_idx ON ops.tickets (created_at DESC, id DESC);
CREATE INDEX tickets_creator_idx ON ops.tickets (creator_id);
CREATE INDEX tickets_assignee_idx ON ops.tickets (assignee_id) WHERE assignee_id IS NOT NULL;
CREATE INDEX tickets_open_idx ON ops.tickets (status, priority) WHERE status IN ('open', 'in_progress');

-- Số thứ tự mã ticket theo năm (UPDATE ... RETURNING, không trùng khi tạo đồng thời; dãy "support_ticket" của Laravel).
CREATE TABLE ops.ticket_counters (
    year        smallint PRIMARY KEY,
    last_number integer NOT NULL CHECK (last_number > 0)
);

CREATE TABLE ops.ticket_messages (
    id               uuid PRIMARY KEY DEFAULT uuidv7(),
    ticket_id        uuid NOT NULL REFERENCES ops.tickets (id) ON DELETE CASCADE,
    user_id          uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    message          text NOT NULL CHECK (length(message) BETWEEN 1 AND 20000),
    -- Ghi chú nội bộ: chỉ người xử lý ticket và người được phân công thấy
    is_internal_note boolean NOT NULL DEFAULT false,
    legacy_id        bigint UNIQUE,
    created_at       timestamptz NOT NULL DEFAULT now(),
    updated_at       timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX ticket_messages_ticket_idx ON ops.ticket_messages (ticket_id, created_at, id);

-- +goose Down
DROP TABLE ops.ticket_messages;
DROP TABLE ops.ticket_counters;
DROP TABLE ops.tickets;
