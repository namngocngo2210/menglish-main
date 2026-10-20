-- Ticket hỗ trợ (ops.tickets, ops.ticket_messages). Phạm vi support_ticket.scope_* (SupportTicket::scopeVisibleTo):
-- all_scope = Toàn hệ thống; còn lại ticket mình tạo / được giao, cộng ticket do nhân sự thuộc branches (mức Chi nhánh) tạo.

-- name: ListTickets :many
SELECT t.id, t.code, t.title, t.category, t.priority, t.status, t.created_at,
       c.full_name AS creator_name, a.full_name AS assignee_name,
       count(*) OVER () AS total
FROM ops.tickets t
JOIN iam.users c ON c.id = t.creator_id
LEFT JOIN iam.users a ON a.id = t.assignee_id
WHERE (@all_scope::boolean OR t.creator_id = @me::uuid OR t.assignee_id = @me::uuid
       OR EXISTS (SELECT 1 FROM iam.user_branches ub WHERE ub.user_id = t.creator_id AND ub.branch_id = ANY (@branches::uuid[])))
  AND (sqlc.narg(status)::text IS NULL OR t.status = sqlc.narg(status)::text)
  AND (sqlc.narg(category)::text IS NULL OR t.category = sqlc.narg(category)::text)
  AND (sqlc.narg(search)::text IS NULL OR t.code ILIKE sqlc.narg(search)::text
       OR t.title ILIKE sqlc.narg(search)::text OR t.description ILIKE sqlc.narg(search)::text)
ORDER BY t.created_at DESC, t.id DESC
LIMIT @lim::int OFFSET @off::int;

-- name: TicketStats :one
-- Thẻ số liệu đầu danh sách (cùng phạm vi, không theo bộ lọc).
SELECT count(*) AS total,
       count(*) FILTER (WHERE t.status = 'open') AS open,
       count(*) FILTER (WHERE t.status = 'in_progress') AS in_progress,
       count(*) FILTER (WHERE t.status = 'resolved') AS resolved
FROM ops.tickets t
WHERE @all_scope::boolean OR t.creator_id = @me::uuid OR t.assignee_id = @me::uuid
       OR EXISTS (SELECT 1 FROM iam.user_branches ub WHERE ub.user_id = t.creator_id AND ub.branch_id = ANY (@branches::uuid[]));

-- name: GetTicket :one
-- Theo id hoặc mã (link cũ dạng /tickets/TK-2026-0001).
SELECT t.*, c.full_name AS creator_name, a.full_name AS assignee_name
FROM ops.tickets t
JOIN iam.users c ON c.id = t.creator_id
LEFT JOIN iam.users a ON a.id = t.assignee_id
WHERE t.id = sqlc.narg(id)::uuid OR upper(t.code) = upper(@code::text);

-- name: LockTicket :one
SELECT * FROM ops.tickets WHERE id = $1 FOR UPDATE;

-- name: TicketBySubmission :one
SELECT * FROM ops.tickets WHERE creator_id = @creator_id AND submission_token = @token::text;

-- name: RecentDuplicateTicket :one
-- Ticket giống hệt (cùng người tạo, tiêu đề, mô tả) tạo gần đây: coi là gửi lặp.
SELECT * FROM ops.tickets
WHERE creator_id = @creator_id AND title = @title AND description = @description AND created_at >= @since::timestamptz
ORDER BY created_at DESC, id DESC
LIMIT 1;

-- name: NextTicketNumber :one
-- Số thứ tự trong năm; lần đầu của năm tiếp nối mã lớn nhất đã có (ticket nhập từ Laravel).
INSERT INTO ops.ticket_counters (year, last_number)
VALUES (
    sqlc.arg(year)::smallint,
    COALESCE((SELECT max(substring(code FROM 9)::int) FROM ops.tickets
              WHERE code ~ ('^TK-' || sqlc.arg(year)::smallint::text || '-[0-9]+$')), 0) + 1
)
ON CONFLICT (year) DO UPDATE SET last_number = ops.ticket_counters.last_number + 1
RETURNING last_number;

-- name: TicketCodeTaken :one
SELECT EXISTS (SELECT 1 FROM ops.tickets WHERE code = $1);

-- name: InsertTicket :one
INSERT INTO ops.tickets (code, submission_token, title, category, priority, description, creator_id, assignee_id)
VALUES (@code, sqlc.narg(submission_token), @title, @category, @priority, @description, @creator_id, sqlc.narg(assignee_id))
ON CONFLICT (creator_id, submission_token) DO NOTHING
RETURNING *;

-- name: InsertMessage :one
INSERT INTO ops.ticket_messages (ticket_id, user_id, message, is_internal_note)
VALUES (@ticket_id, @user_id, @message, @is_internal_note)
RETURNING *;

-- name: ListMessages :many
-- Hội thoại cũ trước, mới nhất ở cuối.
SELECT m.*, u.full_name AS user_name
FROM ops.ticket_messages m
LEFT JOIN iam.users u ON u.id = m.user_id
WHERE m.ticket_id = $1
ORDER BY m.created_at, m.id;

-- name: GetMessage :one
SELECT * FROM ops.ticket_messages WHERE id = $1;

-- name: SetTicketStatus :exec
UPDATE ops.tickets SET status = @status, resolved_at = sqlc.narg(resolved_at), updated_at = now() WHERE id = @id;

-- name: SetTicketAssignee :exec
UPDATE ops.tickets SET assignee_id = @assignee_id, updated_at = now() WHERE id = @id;

-- name: TicketParticipantIDs :many
-- Người từng trả lời / bình luận trong ticket.
SELECT DISTINCT user_id::uuid FROM ops.ticket_messages WHERE ticket_id = $1 AND user_id IS NOT NULL;

-- name: ActiveUsers :many
-- Tên + email của các tài khoản đang hoạt động trong danh sách (người xử lý, người nhận email).
SELECT id, full_name, coalesce(email::text, '')::text AS email
FROM iam.users
WHERE id = ANY (@ids::uuid[]) AND status = 'active' AND (locked_until IS NULL OR locked_until < now())
ORDER BY full_name, id;

-- name: UserName :one
SELECT full_name FROM iam.users WHERE id = $1;

-- name: UserBranchIDs :many
-- Chi nhánh (chính + cấp thêm) của một người.
SELECT branch_id FROM iam.user_branches WHERE user_id = $1;

-- name: DashboardTicketCounts :one
-- Tổng quan: ticket đang mở / chưa có người xử lý, lọc theo chi nhánh chính của người tạo (branches NULL = mọi chi nhánh).
SELECT count(*) FILTER (WHERE t.status IN ('open', 'in_progress')) AS open,
       count(*) FILTER (WHERE t.status = 'open' AND t.assignee_id IS NULL) AS unassigned
FROM ops.tickets t
WHERE sqlc.narg(branch_ids)::uuid[] IS NULL OR EXISTS (
    SELECT 1 FROM iam.user_branches ub
    WHERE ub.user_id = t.creator_id AND ub.is_primary AND ub.branch_id = ANY (sqlc.narg(branch_ids)::uuid[]));

-- name: IncidentTickets :many
-- Nhật ký sự vụ: ticket theo mức ưu tiên / trạng thái / chi nhánh chính của người tạo, mới trước.
SELECT t.id, t.code, t.title, t.description, t.category, t.priority, t.status, t.creator_id, t.assignee_id,
       c.full_name AS creator_name, a.full_name AS assignee_name, b.name AS branch_name
FROM ops.tickets t
JOIN iam.users c ON c.id = t.creator_id
LEFT JOIN iam.users a ON a.id = t.assignee_id
LEFT JOIN iam.user_branches ub ON ub.user_id = t.creator_id AND ub.is_primary
LEFT JOIN core.branches b ON b.id = ub.branch_id
WHERE t.priority = ANY (@priorities::text[])
  AND (sqlc.narg(branch_id)::uuid IS NULL OR ub.branch_id = sqlc.narg(branch_id)::uuid)
  AND (sqlc.narg(statuses)::text[] IS NULL OR t.status = ANY (sqlc.narg(statuses)::text[]))
ORDER BY t.created_at DESC, t.id DESC
LIMIT @lim::int;

-- name: FacilityTickets :many
-- Ticket "Cơ sở vật chất" còn mở (báo cáo ngày / tuần).
SELECT t.id, t.code, t.title, t.status, t.created_at
FROM ops.tickets t
WHERE t.category = 'facilities' AND t.status IN ('open', 'in_progress')
ORDER BY t.created_at DESC, t.id DESC
LIMIT @lim::int;

-- name: ReportDayFacilityTickets :many
-- Báo cáo ngày Học vụ (DailyReportMetrics::facilityDetails): ticket CSVC tạo trước cuối ngày, chưa xử lý xong trước ngày
-- (báo cáo hôm nay bỏ ticket đã xong); người tạo thuộc chi nhánh của người viết (rỗng = không lọc).
SELECT t.id, t.code, t.title, t.status, t.created_at
FROM ops.tickets t
WHERE t.category = 'facilities' AND t.created_at <= sqlc.arg(day_end)::timestamptz
  AND (t.resolved_at IS NULL OR t.resolved_at >= sqlc.arg(day_start)::timestamptz)
  AND (NOT sqlc.arg(open_only)::bool OR t.status NOT IN ('resolved', 'closed'))
  AND (cardinality(sqlc.arg(branch_ids)::uuid[]) = 0 OR EXISTS (
        SELECT 1 FROM iam.user_branches ub WHERE ub.user_id = t.creator_id AND ub.branch_id = ANY (sqlc.arg(branch_ids)::uuid[])))
ORDER BY t.created_at, t.id;

-- name: ReportWeekFacilityTickets :many
-- Dòng tham khảo của mục KPI material_stock (WeeklyReportMetrics::facilityTickets): ticket CSVC tạo trong tuần.
SELECT t.id, t.code, t.title
FROM ops.tickets t
WHERE t.category = 'facilities' AND t.created_at BETWEEN sqlc.arg(from_at)::timestamptz AND sqlc.arg(to_at)::timestamptz
  AND (cardinality(sqlc.arg(branch_ids)::uuid[]) = 0 OR EXISTS (
        SELECT 1 FROM iam.user_branches ub WHERE ub.user_id = t.creator_id AND ub.branch_id = ANY (sqlc.arg(branch_ids)::uuid[])))
ORDER BY t.created_at, t.id;
