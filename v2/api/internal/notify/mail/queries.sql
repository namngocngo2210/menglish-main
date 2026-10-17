-- name: InsertMail :one
INSERT INTO core.mail_outbox (kind, to_address, to_name, subject, template, data, sensitive, max_attempts, fallback,
                              ref_type, ref_id, created_by)
VALUES (sqlc.arg(kind), sqlc.arg(to_address), sqlc.arg(to_name), sqlc.arg(subject), sqlc.arg(template), sqlc.arg(data),
        sqlc.arg(sensitive), sqlc.arg(max_attempts), sqlc.narg(fallback), sqlc.narg(ref_type), sqlc.narg(ref_id),
        sqlc.narg(created_by))
RETURNING id;

-- name: ClaimDueMail :many
-- Lấy tối đa @lim thư đến hạn (hoặc đang gửi mà hết khóa) và khóa @lock_seconds giây; nhiều tiến trình chạy cùng lúc
-- không lấy trùng (SKIP LOCKED).
UPDATE core.mail_outbox o
SET status = 'sending', attempts = o.attempts + 1,
    locked_until = now() + make_interval(secs => sqlc.arg(lock_seconds)::int), updated_at = now()
WHERE o.id IN (
    SELECT m.id FROM core.mail_outbox m
    WHERE (m.status = 'pending' AND m.next_attempt_at <= now())
       OR (m.status = 'sending' AND m.locked_until < now())
    ORDER BY m.next_attempt_at, m.id
    LIMIT sqlc.arg(lim)
    FOR UPDATE SKIP LOCKED
)
RETURNING o.*;

-- name: MarkMailSent :exec
-- Thư nhạy cảm: gửi xong thì xóa dữ liệu (link đặt lại mật khẩu không nằm lại trong DB).
UPDATE core.mail_outbox
SET status = 'sent', sent_at = now(), locked_until = NULL, last_error = NULL, updated_at = now(),
    data = CASE WHEN sensitive THEN '{}'::jsonb ELSE data END
WHERE id = $1 AND status = 'sending';

-- name: MarkMailRetry :exec
UPDATE core.mail_outbox
SET status = 'pending', next_attempt_at = sqlc.arg(next_attempt_at), locked_until = NULL,
    last_error = sqlc.arg(last_error), updated_at = now()
WHERE id = sqlc.arg(id) AND status = 'sending';

-- name: MarkMailFailed :execrows
UPDATE core.mail_outbox
SET status = 'failed', locked_until = NULL, last_error = sqlc.arg(last_error), updated_at = now()
WHERE id = sqlc.arg(id) AND status = 'sending';

-- name: MarkMailBlocked :exec
UPDATE core.mail_outbox
SET status = 'blocked', locked_until = NULL, last_error = sqlc.arg(last_error), updated_at = now(),
    data = CASE WHEN sensitive THEN '{}'::jsonb ELSE data END
WHERE id = sqlc.arg(id) AND status = 'sending';

-- name: PurgeSentMail :execrows
-- Dọn thư đã gửi / đã hủy cũ hơn @before (giữ thư lỗi để xem lại).
DELETE FROM core.mail_outbox WHERE status IN ('sent', 'cancelled', 'blocked') AND updated_at < sqlc.arg(before);

-- name: ListMail :many
SELECT m.id, m.kind, m.to_address, m.to_name, m.subject, m.status, m.attempts, m.max_attempts, m.next_attempt_at,
       m.last_error, m.ref_type, m.ref_id, m.created_at, m.updated_at, m.sent_at, u.full_name AS created_by_name,
       count(*) OVER () AS total
FROM core.mail_outbox m
LEFT JOIN iam.users u ON u.id = m.created_by
WHERE (sqlc.narg(status)::text IS NULL OR m.status = sqlc.narg(status)::text)
  AND (sqlc.narg(kind)::text IS NULL OR m.kind = sqlc.narg(kind)::text)
  AND (sqlc.narg(q)::text IS NULL OR m.to_address ILIKE sqlc.narg(q)::text OR m.subject ILIKE sqlc.narg(q)::text)
ORDER BY m.created_at DESC, m.id DESC
LIMIT sqlc.arg(lim) OFFSET sqlc.arg(off);

-- name: MailStatusCounts :many
SELECT status, count(*) AS n FROM core.mail_outbox GROUP BY status;

-- name: GetMail :one
SELECT m.*, u.full_name AS created_by_name
FROM core.mail_outbox m
LEFT JOIN iam.users u ON u.id = m.created_by
WHERE m.id = $1;

-- name: RetryMail :one
-- Gửi lại thư lỗi / bị chặn / đã hủy: đếm lại số lần thử từ đầu.
UPDATE core.mail_outbox
SET status = 'pending', attempts = 0, next_attempt_at = now(), locked_until = NULL, updated_at = now()
WHERE id = $1 AND status IN ('failed', 'blocked', 'cancelled') AND NOT (sensitive AND data = '{}'::jsonb)
RETURNING id;

-- name: CancelMail :one
UPDATE core.mail_outbox
SET status = 'cancelled', locked_until = NULL, updated_at = now()
WHERE id = $1 AND status IN ('pending', 'failed')
RETURNING id;

-- name: MailSettings :many
SELECT key, value, secret FROM core.settings WHERE key = ANY (sqlc.arg(keys)::text[]);
