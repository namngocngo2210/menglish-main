-- Hàng đợi email gửi ra ngoài (Laravel gửi Mail:: đồng bộ, có chỗ gửi ngay trong transaction duyệt phiếu thu).
-- v2: nghiệp vụ gọi mail.Enqueue trong transaction của mình (rollback thì thư cũng mất), vòng gửi nền lấy thư sau khi
-- commit, gửi qua SMTP lưu trong core.settings, lỗi thì thử lại theo lũy thừa; màn quản trị xem thư lỗi và gửi lại.
-- Mỗi dòng một người nhận để thử lại riêng từng địa chỉ.
-- +goose Up
CREATE TABLE core.mail_outbox (
    id              uuid PRIMARY KEY DEFAULT uuidv7(),
    -- Loại thư cho màn quản trị, vd. 'parent_notice', 'ops_alert', 'ticket', 'password_reset', 'test'
    kind            text NOT NULL CHECK (kind ~ '^[a-z][a-z0-9_.]{0,63}$'),
    to_address      text NOT NULL CHECK (length(to_address) BETWEEN 3 AND 254 AND to_address ~ '^[^@\s]+@[^@\s]+$'),
    to_name         text NOT NULL DEFAULT '' CHECK (length(to_name) <= 255),
    subject         text NOT NULL CHECK (length(subject) BETWEEN 1 AND 255),
    -- Mẫu thư (internal/notify/mail/templates) và dữ liệu điền vào mẫu; render lúc gửi
    template        text NOT NULL CHECK (template ~ '^[a-z][a-z0-9_]{0,63}$'),
    data            jsonb NOT NULL DEFAULT '{}',
    -- Thư chứa bí mật (link đặt lại mật khẩu): màn quản trị không hiện nội dung, gửi xong thì xóa dữ liệu
    sensitive       boolean NOT NULL DEFAULT false,
    status          text NOT NULL DEFAULT 'pending'
                    CHECK (status IN ('pending', 'sending', 'sent', 'failed', 'blocked', 'cancelled')),
    attempts        integer NOT NULL DEFAULT 0 CHECK (attempts >= 0),
    max_attempts    integer NOT NULL DEFAULT 8 CHECK (max_attempts BETWEEN 1 AND 20),
    next_attempt_at timestamptz NOT NULL DEFAULT now(),
    -- Đang gửi: hết hạn khóa thì vòng gửi khác lấy lại (máy chết giữa chừng)
    locked_until    timestamptz,
    last_error      text CHECK (length(last_error) <= 2000),
    -- Thông báo trong ứng dụng gửi khi thư hỏng hẳn (vd. nhắc người phụ trách gửi xác nhận qua Zalo)
    fallback        jsonb,
    -- Đối tượng nghiệp vụ của thư (vd. 'trial_booking', '<uuid>') để lọc
    ref_type        text CHECK (ref_type ~ '^[a-z][a-z0-9_.]{0,63}$'),
    ref_id          text CHECK (length(ref_id) <= 100),
    created_by      uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    created_at      timestamptz NOT NULL DEFAULT now(),
    updated_at      timestamptz NOT NULL DEFAULT now(),
    sent_at         timestamptz
);
-- Vòng gửi: thư đến hạn
CREATE INDEX mail_outbox_due_idx ON core.mail_outbox (next_attempt_at) WHERE status IN ('pending', 'sending');
CREATE INDEX mail_outbox_status_created_idx ON core.mail_outbox (status, created_at DESC, id DESC);
CREATE INDEX mail_outbox_created_idx ON core.mail_outbox (created_at DESC, id DESC);
CREATE INDEX mail_outbox_ref_idx ON core.mail_outbox (ref_type, ref_id) WHERE ref_type IS NOT NULL;

-- +goose Down
DROP TABLE core.mail_outbox;
