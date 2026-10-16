-- name: InsertNotifications :execrows
-- Một dòng cho mỗi người nhận còn hoạt động trong user_ids (id không tồn tại / đã khóa bị bỏ qua).
INSERT INTO core.notifications (user_id, type, title, body, link, data, created_by)
SELECT u.id, sqlc.arg(type)::text, sqlc.arg(title)::text, sqlc.arg(body)::text, sqlc.narg(link)::text,
       sqlc.arg(data)::jsonb, sqlc.narg(created_by)::uuid
FROM iam.users u
WHERE u.id = ANY (sqlc.arg(user_ids)::uuid[]) AND u.status = 'active';

-- name: ListNotifications :many
SELECT n.id, n.type, n.title, n.body, n.link, n.data, n.read_at, n.created_at, count(*) OVER () AS total
FROM core.notifications n
WHERE n.user_id = sqlc.arg(user_id)::uuid
  AND (sqlc.narg(type)::text IS NULL OR n.type = sqlc.narg(type)::text)
  AND (NOT sqlc.arg(unread_only)::boolean OR n.read_at IS NULL)
ORDER BY n.created_at DESC, n.id DESC
LIMIT sqlc.arg(lim) OFFSET sqlc.arg(off);

-- name: NotificationStats :one
-- Tổng số, số chưa đọc, số chưa đọc thuộc các loại cảnh báo lead tồn đọng (thẻ "Lead bị sót >24h").
SELECT count(*) AS total,
       count(*) FILTER (WHERE n.read_at IS NULL) AS unread,
       count(*) FILTER (WHERE n.read_at IS NULL AND n.type = ANY (sqlc.arg(alert_types)::text[])) AS alerts
FROM core.notifications n
WHERE n.user_id = sqlc.arg(user_id)::uuid;

-- name: CountUnread :one
SELECT count(*) FROM core.notifications WHERE user_id = $1 AND read_at IS NULL;

-- name: MarkNotificationRead :execrows
-- Chỉ thông báo của chính người dùng; đã đọc rồi thì giữ thời điểm đọc cũ.
UPDATE core.notifications SET read_at = coalesce(read_at, now())
WHERE id = $1 AND user_id = $2;

-- name: MarkAllNotificationsRead :execrows
UPDATE core.notifications SET read_at = now() WHERE user_id = $1 AND read_at IS NULL;
