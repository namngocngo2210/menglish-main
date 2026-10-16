-- name: InsertAuditLog :exec
INSERT INTO core.audit_log (actor_id, module, event, action, subject_type, subject_id, subject_label, branch_id,
                            description, changes, properties, ip, user_agent, method, path, request_id)
VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, $13, $14, $15, $16);

-- name: ListAuditLog :many
-- Phạm vi (như ActivityLogController::scoped): viewer NULL = mọi thao tác; ngược lại thao tác của chính viewer và của
-- nhân sự thuộc branch_ids (NULL = chỉ của mình). Các bộ lọc NULL = không lọc.
SELECT a.id, a.created_at, a.actor_id, a.module, a.event, a.action, a.subject_type, a.subject_id, a.subject_label,
       a.branch_id, a.description, a.changes, a.properties, a.ip, a.user_agent, a.method, a.path, a.request_id,
       u.full_name AS actor_name, u.code AS actor_code,
       coalesce((SELECT r.short_label FROM iam.user_roles ur JOIN iam.roles r ON r.name = ur.role
                 WHERE ur.user_id = a.actor_id ORDER BY r.sort, r.name LIMIT 1), '')::text AS actor_role,
       count(*) OVER () AS total
FROM core.audit_log a
LEFT JOIN iam.users u ON u.id = a.actor_id
WHERE (sqlc.narg(viewer)::uuid IS NULL OR a.actor_id = sqlc.narg(viewer)::uuid
       OR a.actor_id IN (SELECT ub.user_id FROM iam.user_branches ub WHERE ub.branch_id = ANY (sqlc.narg(branch_ids)::uuid[])))
  AND (sqlc.narg(modules)::text[] IS NULL OR a.module = ANY (sqlc.narg(modules)::text[]))
  AND (sqlc.narg(event)::text IS NULL OR a.event = sqlc.narg(event)::text)
  AND (sqlc.narg(actor_id)::uuid IS NULL OR a.actor_id = sqlc.narg(actor_id)::uuid)
  AND (sqlc.narg(date_from)::timestamptz IS NULL OR a.created_at >= sqlc.narg(date_from)::timestamptz)
  AND (sqlc.narg(date_to)::timestamptz IS NULL OR a.created_at < sqlc.narg(date_to)::timestamptz)
  AND (sqlc.narg(search)::text IS NULL OR a.description ILIKE sqlc.narg(search)::text
       OR a.module ILIKE sqlc.narg(search)::text OR a.event ILIKE sqlc.narg(search)::text
       OR a.subject_label ILIKE sqlc.narg(search)::text OR u.full_name ILIKE sqlc.narg(search)::text
       OR u.email ILIKE sqlc.narg(search)::text OR u.code ILIKE sqlc.narg(search)::text
       OR a.id::text = sqlc.narg(exact)::text OR a.subject_id = sqlc.narg(exact)::text
       OR a.request_id::text = sqlc.narg(exact)::text)
ORDER BY a.created_at DESC, a.id DESC
LIMIT sqlc.arg(lim) OFFSET sqlc.arg(off);

-- name: AuditStats :one
-- Thống kê nhanh trong phạm vi: tổng số dòng, số dòng từ day_start, số người thao tác từ day_start.
SELECT count(*) AS total,
       count(*) FILTER (WHERE a.created_at >= sqlc.arg(day_start)::timestamptz) AS today,
       count(DISTINCT a.actor_id) FILTER (WHERE a.created_at >= sqlc.arg(day_start)::timestamptz) AS active_users
FROM core.audit_log a
WHERE sqlc.narg(viewer)::uuid IS NULL OR a.actor_id = sqlc.narg(viewer)::uuid
   OR a.actor_id IN (SELECT ub.user_id FROM iam.user_branches ub WHERE ub.branch_id = ANY (sqlc.narg(branch_ids)::uuid[]));

-- name: AuditActors :many
-- Người đã có thao tác trong phạm vi (danh sách lọc "Người thực hiện").
SELECT u.id, u.full_name, u.code
FROM iam.users u
WHERE u.id IN (SELECT DISTINCT a.actor_id FROM core.audit_log a
               WHERE a.actor_id IS NOT NULL
                 AND (sqlc.narg(viewer)::uuid IS NULL OR a.actor_id = sqlc.narg(viewer)::uuid
                      OR a.actor_id IN (SELECT ub.user_id FROM iam.user_branches ub
                                        WHERE ub.branch_id = ANY (sqlc.narg(branch_ids)::uuid[]))))
ORDER BY u.full_name, u.id;

-- name: AuditModules :many
SELECT DISTINCT module FROM core.audit_log ORDER BY module;

-- name: AuditEvents :many
SELECT DISTINCT event FROM core.audit_log ORDER BY event;
