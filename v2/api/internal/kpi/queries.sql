-- KPI nhân sự (hr.kpi_criteria / kpi_sheets / kpi_items). Người dùng, vai trò, chi nhánh, hồ sơ nhân sự đọc từ iam (chỉ đọc);
-- cấu hình chu kỳ / quỹ ở core.settings.

-- ───────────── Tiêu chí ─────────────

-- name: ListCriteria :many
-- Màn Tiêu chí KPI: tiêu chí đang áp dụng trước, rồi theo thứ tự cấu hình, mã, id.
SELECT * FROM hr.kpi_criteria
WHERE role = $1 AND deleted_at IS NULL
ORDER BY is_active DESC, sort_order, code NULLS FIRST, id;

-- name: ActiveCriteria :many
SELECT * FROM hr.kpi_criteria
WHERE role = $1 AND is_active AND deleted_at IS NULL
ORDER BY sort_order, code NULLS FIRST, id;

-- name: CriteriaByIDs :many
-- Kể cả tiêu chí đã xóa / tạm tắt: phiếu đã duyệt theo bộ tiêu chí trước đây.
SELECT * FROM hr.kpi_criteria WHERE id = ANY(@ids::uuid[])
ORDER BY sort_order, code NULLS FIRST, id;

-- name: GetCriterion :one
SELECT * FROM hr.kpi_criteria WHERE id = $1 AND deleted_at IS NULL;

-- name: RolesWithActiveCriteria :many
SELECT DISTINCT role FROM hr.kpi_criteria WHERE is_active AND deleted_at IS NULL ORDER BY role;

-- name: NextSortOrder :one
SELECT (coalesce(max(sort_order), 0) + 1)::int AS next FROM hr.kpi_criteria WHERE role = $1 AND deleted_at IS NULL;

-- name: InsertCriterion :one
INSERT INTO hr.kpi_criteria (role, group_name, code, name, weight, threshold_full, threshold_half, max_full, max_half, unit,
                             measure, tiers, linear, full_at, per_month, knockout, allow_na, auto_source, description,
                             is_active, sort_order)
VALUES (@role, @group_name, @code, @name, @weight, @threshold_full, @threshold_half, @max_full, @max_half, @unit,
        @measure, @tiers, @linear, @full_at, @per_month, @knockout, @allow_na, @auto_source, @description, true, @sort_order)
RETURNING *;

-- name: UpdateCriterion :one
UPDATE hr.kpi_criteria SET
    group_name = @group_name, name = @name, weight = @weight, threshold_full = @threshold_full, threshold_half = @threshold_half,
    max_full = @max_full, max_half = @max_half, unit = @unit, measure = @measure, tiers = @tiers, linear = @linear,
    full_at = @full_at, per_month = @per_month, knockout = @knockout, allow_na = @allow_na, auto_source = @auto_source,
    description = @description, is_active = @is_active, updated_at = now()
WHERE id = @id AND deleted_at IS NULL
RETURNING *;

-- name: DeleteCriterion :exec
UPDATE hr.kpi_criteria SET deleted_at = now(), updated_at = now() WHERE id = $1 AND deleted_at IS NULL;

-- ───────────── Cấu hình ─────────────

-- name: GetSettings :many
SELECT key, value FROM core.settings WHERE key = ANY(@keys::text[]);

-- name: UpsertSetting :exec
INSERT INTO core.settings (key, value, description, updated_by) VALUES (@key, @value, @description, @updated_by)
ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, description = coalesce(EXCLUDED.description, core.settings.description),
    updated_by = EXCLUDED.updated_by, updated_at = now();

-- ───────────── Nhân sự ─────────────

-- name: RoleLabels :many
SELECT name, label FROM iam.roles ORDER BY sort, name;

-- name: ListStaff :many
-- Nhân sự đang làm có vai trò trong @roles, hoặc (teaching_leads) Học thuật kiêm nhiệm giảng dạy. Phạm vi kpi:
-- own_only → chỉ viewer; scope_branches (khác null) → thuộc các chi nhánh đó hoặc chính viewer. branch_id lọc thêm cơ sở.
-- Vai trò xếp theo thứ tự vai trò của hệ thống (vai trò KPI = vai trò đầu tiên nằm trong danh sách có bộ tiêu chí).
SELECT u.id, u.full_name, coalesce(u.code::text, '')::text AS code,
       coalesce((SELECT array_agg(r.role ORDER BY ro.sort, r.role) FROM iam.user_roles r JOIN iam.roles ro ON ro.name = r.role
                 WHERE r.user_id = u.id), '{}')::text[] AS roles,
       coalesce((SELECT p.academic_teaching FROM iam.staff_profiles p WHERE p.user_id = u.id), false)::bool AS academic_teaching,
       coalesce((SELECT cb.name FROM iam.user_branches b JOIN core.branches cb ON cb.id = b.branch_id
                 WHERE b.user_id = u.id ORDER BY b.is_primary DESC, cb.name LIMIT 1), '')::text AS branch_name,
       count(*) OVER ()::int AS total
FROM iam.users u
WHERE u.status = 'active' AND u.kind = 'staff'
  AND (EXISTS (SELECT 1 FROM iam.user_roles r WHERE r.user_id = u.id AND r.role = ANY(@roles::text[]))
       OR (sqlc.arg(teaching_leads)::bool
           AND EXISTS (SELECT 1 FROM iam.staff_profiles p WHERE p.user_id = u.id AND p.academic_teaching)
           AND EXISTS (SELECT 1 FROM iam.user_roles r WHERE r.user_id = u.id AND r.role = 'academic_lead')))
  AND (NOT sqlc.arg(own_only)::bool OR u.id = sqlc.arg(viewer_id)::uuid)
  AND (sqlc.narg('scope_branches')::uuid[] IS NULL OR u.id = sqlc.arg(viewer_id)::uuid
       OR EXISTS (SELECT 1 FROM iam.user_branches b WHERE b.user_id = u.id AND b.branch_id = ANY(sqlc.narg('scope_branches')::uuid[])))
  AND (sqlc.narg('branch_id')::uuid IS NULL
       OR EXISTS (SELECT 1 FROM iam.user_branches b WHERE b.user_id = u.id AND b.branch_id = sqlc.narg('branch_id')::uuid))
  AND (sqlc.narg('user_id')::uuid IS NULL OR u.id = sqlc.narg('user_id')::uuid)
ORDER BY u.full_name, u.id
LIMIT sqlc.arg(lim)::int OFFSET sqlc.arg(off)::int;

-- name: GetStaff :one
-- Một nhân sự (kể cả đã nghỉ) trong phạm vi kpi của người xem.
SELECT u.id, u.full_name,
       coalesce((SELECT array_agg(r.role ORDER BY ro.sort, r.role) FROM iam.user_roles r JOIN iam.roles ro ON ro.name = r.role
                 WHERE r.user_id = u.id), '{}')::text[] AS roles,
       coalesce((SELECT p.academic_teaching FROM iam.staff_profiles p WHERE p.user_id = u.id), false)::bool AS academic_teaching,
       coalesce((SELECT cb.name FROM iam.user_branches b JOIN core.branches cb ON cb.id = b.branch_id
                 WHERE b.user_id = u.id ORDER BY b.is_primary DESC, cb.name LIMIT 1), '')::text AS branch_name
FROM iam.users u
WHERE u.id = sqlc.arg(id)::uuid AND u.kind = 'staff'
  AND (NOT sqlc.arg(own_only)::bool OR u.id = sqlc.arg(viewer_id)::uuid)
  AND (sqlc.narg('scope_branches')::uuid[] IS NULL OR u.id = sqlc.arg(viewer_id)::uuid
       OR EXISTS (SELECT 1 FROM iam.user_branches b WHERE b.user_id = u.id AND b.branch_id = ANY(sqlc.narg('scope_branches')::uuid[])));

-- name: BranchOptions :many
SELECT id, name FROM core.branches
WHERE sqlc.narg('ids')::uuid[] IS NULL OR id = ANY(sqlc.narg('ids')::uuid[])
ORDER BY name, id;

-- ───────────── Phiếu ─────────────

-- name: EnsureSheet :execrows
-- Phiếu "Chờ duyệt" của kỳ (đã có thì giữ nguyên).
INSERT INTO hr.kpi_sheets (user_id, month, year, track, period_months)
VALUES (@user_id, @month, @year, @track, @period_months)
ON CONFLICT (user_id, year, month, track) DO NOTHING;

-- name: GetSheet :one
SELECT sqlc.embed(s), e.full_name AS evaluator_name
FROM hr.kpi_sheets s LEFT JOIN iam.users e ON e.id = s.evaluator_id
WHERE s.user_id = @user_id AND s.year = @year AND s.month = @month AND s.track = @track;

-- name: LockSheet :one
SELECT * FROM hr.kpi_sheets WHERE id = $1 FOR UPDATE;

-- name: SheetsOfUsers :many
-- Phiếu của các nhân sự lưu ở các tháng (phiếu tháng ở tháng đó, phiếu quý ở tháng đầu quý).
SELECT sqlc.embed(s), e.full_name AS evaluator_name
FROM hr.kpi_sheets s LEFT JOIN iam.users e ON e.id = s.evaluator_id
WHERE s.user_id = ANY(@user_ids::uuid[]) AND s.year = @year AND s.month = ANY(@months::smallint[]);

-- name: ItemsOfSheets :many
SELECT * FROM hr.kpi_items WHERE sheet_id = ANY(@sheet_ids::uuid[]);

-- name: SetItemNA :exec
-- Không phát sinh: đánh dấu thì xóa số liệu; bỏ đánh dấu chỉ đổi cờ.
INSERT INTO hr.kpi_items (sheet_id, criterion_id, not_applicable) VALUES (@sheet_id, @criterion_id, @not_applicable)
ON CONFLICT (sheet_id, criterion_id) DO UPDATE SET
    actual = CASE WHEN EXCLUDED.not_applicable THEN NULL ELSE hr.kpi_items.actual END,
    not_applicable = EXCLUDED.not_applicable, updated_at = now();

-- name: SetItemActual :exec
INSERT INTO hr.kpi_items (sheet_id, criterion_id, actual) VALUES (@sheet_id, @criterion_id, @actual)
ON CONFLICT (sheet_id, criterion_id) DO UPDATE SET actual = EXCLUDED.actual, not_applicable = false, updated_at = now();

-- name: SaveItem :exec
-- Chốt dòng lúc duyệt / không duyệt: số liệu, mức đạt, bằng chứng hệ thống đã đếm.
INSERT INTO hr.kpi_items (sheet_id, criterion_id, actual, score, evidence, not_applicable)
VALUES (@sheet_id, @criterion_id, @actual, @score, @evidence, @not_applicable)
ON CONFLICT (sheet_id, criterion_id) DO UPDATE SET actual = EXCLUDED.actual, score = EXCLUDED.score,
    evidence = EXCLUDED.evidence, not_applicable = EXCLUDED.not_applicable, updated_at = now();

-- name: DecideSheet :exec
UPDATE hr.kpi_sheets SET evaluator_id = @evaluator_id, total_score = @total_score, status = @status,
    reject_reason = @reject_reason, decided_at = now(), updated_at = now()
WHERE id = @id;

-- name: UserName :one
SELECT full_name FROM iam.users WHERE id = $1;

-- ───────────── Bảng KPI tự động ─────────────

-- name: BoardStaff :many
-- Nhân sự giảng dạy + Học vụ đang làm (KpiBoardService::staffQuery) trong phạm vi Công việc; user_id lọc một người.
SELECT u.id, u.full_name, coalesce(u.code::text, '')::text AS code, count(*) OVER ()::int AS total
FROM iam.users u
WHERE u.status = 'active' AND u.kind = 'staff'
  AND EXISTS (SELECT 1 FROM iam.user_roles r WHERE r.user_id = u.id AND r.role = ANY(@roles::text[]))
  AND (NOT sqlc.arg(own_only)::bool OR u.id = sqlc.arg(viewer_id)::uuid)
  AND (sqlc.narg('scope_branches')::uuid[] IS NULL
       OR EXISTS (SELECT 1 FROM iam.user_branches b WHERE b.user_id = u.id AND b.branch_id = ANY(sqlc.narg('scope_branches')::uuid[])))
  AND (sqlc.narg('user_id')::uuid IS NULL OR u.id = sqlc.narg('user_id')::uuid)
ORDER BY u.full_name, u.id
LIMIT sqlc.arg(lim)::int OFFSET sqlc.arg(off)::int;
