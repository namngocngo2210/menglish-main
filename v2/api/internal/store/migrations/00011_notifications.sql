-- Thông báo trong hệ thống (Laravel: bảng admin_notifications, AdminNotificationController, chuông trên topbar).
-- Mỗi người nhận một dòng riêng (Laravel còn có thông báo chung user_id NULL dùng chung trạng thái đã đọc; v2 gửi
-- riêng từng người qua notify.Send nên ai đọc thì chỉ dòng của người đó đổi trạng thái).
-- +goose Up
CREATE TABLE core.notifications (
    id         uuid PRIMARY KEY DEFAULT uuidv7(),
    user_id    uuid NOT NULL REFERENCES iam.users (id) ON DELETE CASCADE,
    -- Loại thông báo, vd. 'ticket_assigned', 'stale_lead_24h' (nhãn / biểu tượng khai báo trong internal/notify)
    type       text NOT NULL CHECK (type ~ '^[a-z][a-z0-9_.]{0,63}$'),
    title      text NOT NULL CHECK (length(title) BETWEEN 1 AND 255),
    body       text NOT NULL DEFAULT '' CHECK (length(body) <= 2000),
    -- Đường dẫn trong ứng dụng (bắt đầu bằng "/", không phải "//") cho nút "Xử lý ngay"
    link       text CHECK (link ~ '^/([^/].*)?$' AND length(link) <= 500),
    -- Dữ liệu kèm theo cho màn hình (vd. thông tin khách của cảnh báo lead, nội dung cần sao chép)
    data       jsonb NOT NULL DEFAULT '{}',
    read_at    timestamptz,
    -- Người gây ra thông báo (NULL = hệ thống)
    created_by uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX notifications_user_created_idx ON core.notifications (user_id, created_at DESC, id DESC);
CREATE INDEX notifications_unread_idx ON core.notifications (user_id, type) WHERE read_at IS NULL;

-- +goose Down
DROP TABLE core.notifications;
