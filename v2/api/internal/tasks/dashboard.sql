-- Công việc cho Tổng quan (dashboard.Tasks).
-- Việc của tôi: được giao cho tôi, còn mở (Mới, Đang thực hiện, Quá hạn, Bị chặn).
-- Bảng điều hành: branches NULL = mọi chi nhánh; còn lại = việc gắn chi nhánh hoặc người nhận có chi nhánh chính thuộc đó.

-- name: DashboardMyTasks :many
-- Việc hạn trong tuần (gồm quá hạn) hoặc không hạn, hạn sớm trước, không hạn sau cùng.
SELECT t.id, t.title, t.status, t.due_date, t.due_time
FROM ops.work_tasks t
WHERE t.assignee_id = @me::uuid AND t.status IN ('new', 'in_progress', 'overdue', 'blocked')
  AND (t.due_date IS NULL OR t.due_date <= @week_end::date)
ORDER BY t.due_date IS NULL, t.due_date, t.due_time, t.created_at
LIMIT 6;

-- name: DashboardMyTaskCounts :one
SELECT
    count(*) FILTER (WHERE t.due_date IS NULL OR t.due_date <= @week_end::date) AS total,
    count(*) FILTER (WHERE t.due_date IS NOT NULL AND t.due_date <= @week_end::date) AS due_week,
    count(*) FILTER (WHERE t.due_date IS NOT NULL AND t.due_date < @today::date) AS overdue_week
FROM ops.work_tasks t
WHERE t.assignee_id = @me::uuid AND t.status IN ('new', 'in_progress', 'overdue', 'blocked');

-- name: DashboardOpsTaskCounts :one
-- Việc quá hạn (bỏ nhiệm vụ hằng ngày của TA — không có hạn) và việc chờ xác nhận.
SELECT
    count(*) FILTER (WHERE t.kind IS DISTINCT FROM 'ta_daily' AND t.status NOT IN ('completed', 'canceled')
        AND (t.status = 'overdue' OR t.due_date < @today::date)) AS overdue,
    count(*) FILTER (WHERE t.status = 'pending_confirmation') AS pending_confirmation
FROM ops.work_tasks t
WHERE sqlc.narg(branches)::uuid[] IS NULL OR t.branch_id = ANY (sqlc.narg(branches)::uuid[]) OR EXISTS (
    SELECT 1 FROM iam.user_branches ub WHERE ub.user_id = t.assignee_id AND ub.is_primary
      AND ub.branch_id = ANY (sqlc.narg(branches)::uuid[]));

-- name: DashboardOverdueTasks :many
SELECT t.id, t.title, t.due_date, a.full_name AS assignee_name
FROM ops.work_tasks t
LEFT JOIN iam.users a ON a.id = t.assignee_id
WHERE t.kind IS DISTINCT FROM 'ta_daily' AND t.status NOT IN ('completed', 'canceled')
  AND (t.status = 'overdue' OR t.due_date < @today::date)
  AND (sqlc.narg(branches)::uuid[] IS NULL OR t.branch_id = ANY (sqlc.narg(branches)::uuid[]) OR EXISTS (
    SELECT 1 FROM iam.user_branches ub WHERE ub.user_id = t.assignee_id AND ub.is_primary
      AND ub.branch_id = ANY (sqlc.narg(branches)::uuid[])))
ORDER BY t.due_date, t.id
LIMIT 5;
