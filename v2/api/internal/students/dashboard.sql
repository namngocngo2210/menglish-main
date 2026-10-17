-- Học viên cho Tổng quan (dashboard.Students).

-- name: DashboardStudying :one
-- Học viên đang học thuộc chi nhánh (NULL = mọi chi nhánh).
SELECT count(*) FROM academic.students s
WHERE s.deleted_at IS NULL AND s.status = 'studying'
  AND (sqlc.narg(branches)::uuid[] IS NULL OR s.branch_id = ANY (sqlc.narg(branches)::uuid[]));
