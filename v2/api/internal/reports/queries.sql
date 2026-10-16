-- Báo cáo & Nhật ký (ops.staff_reports). Tên người, vai trò, chi nhánh đọc từ iam (chỉ đọc).

-- ───────────── Người viết ─────────────

-- name: GetWriter :one
SELECT u.id, u.full_name, u.created_at,
       coalesce((SELECT array_agg(r.role ORDER BY r.role) FROM iam.user_roles r WHERE r.user_id = u.id), '{}')::text[] AS roles,
       coalesce((SELECT array_agg(b.branch_id ORDER BY b.is_primary DESC, b.branch_id) FROM iam.user_branches b WHERE b.user_id = u.id), '{}')::uuid[] AS branch_ids
FROM iam.users u WHERE u.id = $1;

-- name: UserName :one
SELECT full_name FROM iam.users WHERE id = $1;

-- name: ListActiveUsersWithRoles :many
-- Nhân sự đang làm có một trong các vai trò; scope_branches null = mọi chi nhánh, ngược lại chỉ người thuộc các chi nhánh đó
-- (cộng viewer_id).
SELECT u.id, u.full_name, u.created_at,
       coalesce((SELECT array_agg(r.role ORDER BY r.role) FROM iam.user_roles r WHERE r.user_id = u.id), '{}')::text[] AS roles,
       coalesce((SELECT array_agg(b.branch_id ORDER BY b.is_primary DESC, b.branch_id) FROM iam.user_branches b WHERE b.user_id = u.id), '{}')::uuid[] AS branch_ids,
       coalesce((SELECT cb.name FROM iam.user_branches b JOIN core.branches cb ON cb.id = b.branch_id
         WHERE b.user_id = u.id ORDER BY b.is_primary DESC, cb.name LIMIT 1), '')::text AS branch_name
FROM iam.users u
WHERE u.status = 'active' AND u.kind = 'staff'
  AND EXISTS (SELECT 1 FROM iam.user_roles r WHERE r.user_id = u.id AND r.role = ANY(@roles::text[]))
  AND (sqlc.narg('scope_branches')::uuid[] IS NULL
       OR u.id = sqlc.arg('viewer_id')::uuid
       OR EXISTS (SELECT 1 FROM iam.user_branches b WHERE b.user_id = u.id AND b.branch_id = ANY(sqlc.narg('scope_branches')::uuid[])))
ORDER BY u.full_name, u.id;

-- ───────────── Báo cáo có cấu trúc ─────────────

-- name: GetStructured :one
SELECT * FROM ops.staff_reports WHERE user_id = $1 AND kind = $2 AND period_key = $3;

-- name: GetStructuredForUpdate :one
SELECT * FROM ops.staff_reports WHERE user_id = $1 AND kind = $2 AND period_key = $3 FOR UPDATE;

-- name: InsertStructured :one
INSERT INTO ops.staff_reports (user_id, type, kind, period_key, title, content, data, report_date, status, submitted_at, created_by)
VALUES (@user_id, @type, @kind, @period_key, @title, @content, @data, @report_date, @status,
        CASE WHEN @status::text = 'submitted' THEN now() END, @user_id)
ON CONFLICT (user_id, kind, period_key) DO NOTHING
RETURNING *;

-- name: UpdateStructured :one
-- Nộp (hoặc sửa bản đã nộp / bản bị yêu cầu bổ sung) → về "Chờ kiểm tra": xóa người kiểm tra, nhận xét.
UPDATE ops.staff_reports SET
    title = @title, content = @content, data = @data, report_date = @report_date, status = @status,
    submitted_at = CASE WHEN @status::text = 'submitted' THEN coalesce(submitted_at, now()) ELSE submitted_at END,
    reviewed_by  = CASE WHEN @status::text = 'submitted' THEN NULL ELSE reviewed_by END,
    reviewed_at  = CASE WHEN @status::text = 'submitted' THEN NULL ELSE reviewed_at END,
    review_note  = CASE WHEN @status::text = 'submitted' THEN NULL ELSE review_note END,
    updated_at = now()
WHERE id = @id
RETURNING *;

-- name: StructuredHistory :many
SELECT period_key, updated_at, status, reviewed_at FROM ops.staff_reports
WHERE user_id = $1 AND kind = $2 AND period_key IS NOT NULL
ORDER BY period_key DESC LIMIT 12;

-- name: StructuredByPeriods :many
SELECT * FROM ops.staff_reports WHERE user_id = $1 AND kind = $2 AND period_key = ANY(@periods::text[]);

-- name: DailyReportsBetween :many
-- Báo cáo ngày có cấu trúc của một người trong khoảng (ghi chú gộp vào báo cáo tuần Học vụ).
SELECT period_key, data FROM ops.staff_reports
WHERE user_id = $1 AND kind = 'daily_ops' AND period_key BETWEEN @from_key::text AND @to_key::text
ORDER BY period_key;

-- name: SubmittedDailyDates :many
-- Ngày đã có báo cáo ngày (bản có cấu trúc đã nộp hoặc báo cáo ngày chữ tự do), không tính nháp.
SELECT DISTINCT report_date FROM ops.staff_reports
WHERE user_id = $1 AND type = 'daily' AND status <> 'draft' AND report_date BETWEEN @from_date AND @to_date;

-- name: SubmittedDailyDatesForUsers :many
SELECT DISTINCT user_id, report_date FROM ops.staff_reports
WHERE user_id = ANY(@user_ids::uuid[]) AND type = 'daily' AND status <> 'draft' AND report_date BETWEEN @from_date AND @to_date;

-- ───────────── Báo cáo chữ tự do (vai trò chưa có màn có cấu trúc) ─────────────

-- name: ListFreeReports :many
SELECT id, title, content, report_date, count(*) OVER () AS total FROM ops.staff_reports
WHERE user_id = $1 AND type = $2 AND period_key IS NULL
ORDER BY report_date DESC, id DESC
LIMIT $3 OFFSET $4;

-- name: LegacyReports :many
SELECT id, title, content, report_date FROM ops.staff_reports
WHERE user_id = $1 AND type = $2 AND period_key IS NULL
ORDER BY report_date DESC, id DESC LIMIT 10;

-- name: LegacyWeeklyBetween :many
SELECT * FROM ops.staff_reports
WHERE user_id = $1 AND type = 'weekly' AND period_key IS NULL AND report_date BETWEEN @from_date AND @to_date
ORDER BY report_date;

-- name: FreeReportExistsBetween :one
SELECT EXISTS (SELECT 1 FROM ops.staff_reports
WHERE user_id = $1 AND type = $2 AND period_key IS NULL AND report_date BETWEEN @from_date AND @to_date);

-- name: InsertFreeReport :one
INSERT INTO ops.staff_reports (user_id, class_id, type, title, content, report_date, status, submitted_at, created_by)
VALUES ($1, $2, $3, $4, $5, $6, 'submitted', now(), $1)
RETURNING *;

-- ───────────── Nhật ký ─────────────

-- name: InsertJournal :one
INSERT INTO ops.staff_reports (user_id, class_id, type, title, content, severity, kpi_code, report_date, status, created_by)
VALUES (@user_id, @class_id, 'journal', @title, @content, @severity, @kpi_code, @report_date, 'open', @created_by)
RETURNING *;

-- name: ListJournal :many
-- only_user: người không có staff_report.view_all chỉ thấy của mình. scope_branches: Quản lý cơ sở chỉ thấy nhân sự
-- chi nhánh mình (cộng viewer_id); null = mọi người.
SELECT s.*, u.full_name AS user_name,
       cb.full_name AS created_by_name,
       sc.full_name AS serious_by_name,
       coalesce((SELECT ro.short_label FROM iam.user_roles r JOIN iam.roles ro ON ro.name = r.role
                 WHERE r.user_id = s.user_id ORDER BY ro.sort, r.role LIMIT 1), '')::text AS user_role,
       count(*) OVER () AS total
FROM ops.staff_reports s
JOIN iam.users u ON u.id = s.user_id
LEFT JOIN iam.users cb ON cb.id = s.created_by
LEFT JOIN iam.users sc ON sc.id = s.serious_confirmed_by
WHERE s.type = 'journal'
  AND (sqlc.narg('only_user')::uuid IS NULL OR s.user_id = sqlc.narg('only_user')::uuid)
  AND (sqlc.narg('scope_branches')::uuid[] IS NULL
       OR s.user_id = sqlc.arg('viewer_id')::uuid
       OR EXISTS (SELECT 1 FROM iam.user_branches b WHERE b.user_id = s.user_id AND b.branch_id = ANY(sqlc.narg('scope_branches')::uuid[])))
  AND (sqlc.narg('severity')::text IS NULL OR s.severity = sqlc.narg('severity')::text)
  AND (sqlc.narg('status')::text IS NULL OR s.status = sqlc.narg('status')::text)
  AND (sqlc.narg('class_id')::uuid IS NULL OR s.class_id = sqlc.narg('class_id')::uuid)
ORDER BY s.report_date DESC, s.id DESC
LIMIT sqlc.arg('lim') OFFSET sqlc.arg('off');

-- name: GetJournalEntry :one
SELECT s.* FROM ops.staff_reports s
WHERE s.id = sqlc.arg('id') AND s.type = 'journal'
  AND (sqlc.narg('only_user')::uuid IS NULL OR s.user_id = sqlc.narg('only_user')::uuid)
  AND (sqlc.narg('scope_branches')::uuid[] IS NULL
       OR s.user_id = sqlc.arg('viewer_id')::uuid
       OR EXISTS (SELECT 1 FROM iam.user_branches b WHERE b.user_id = s.user_id AND b.branch_id = ANY(sqlc.narg('scope_branches')::uuid[])));

-- name: SetJournalStatus :exec
UPDATE ops.staff_reports SET status = $2, updated_at = now() WHERE id = $1 AND type = 'journal';

-- name: SetJournalSerious :exec
UPDATE ops.staff_reports SET serious_confirmed_by = $2, serious_confirmed_at = CASE WHEN $2::uuid IS NULL THEN NULL ELSE now() END,
    updated_at = now()
WHERE id = $1 AND type = 'journal';

-- name: InsertFollowup :one
INSERT INTO ops.staff_report_followups (staff_report_id, user_id, content) VALUES ($1, $2, $3) RETURNING *;

-- name: ListFollowups :many
SELECT f.id, f.staff_report_id, f.content, f.created_at, u.full_name AS user_name
FROM ops.staff_report_followups f LEFT JOIN iam.users u ON u.id = f.user_id
WHERE f.staff_report_id = ANY(@report_ids::uuid[])
ORDER BY f.created_at DESC, f.id DESC;

-- name: JournalWithKpiBetween :many
-- Sự việc có gắn mã KPI của một người trong khoảng (đếm mục KPI nguồn "Nhật ký" của báo cáo tuần Học vụ).
SELECT id, kpi_code, title, content, report_date FROM ops.staff_reports
WHERE type = 'journal' AND user_id = $1 AND kpi_code IS NOT NULL AND report_date BETWEEN @from_date AND @to_date
ORDER BY report_date, id;

-- name: JournalOnDate :many
-- Sự việc trong ngày của người viết (khối "Tình hình lớp học" của báo cáo ngày).
SELECT s.id, s.class_id, s.title, s.content, s.serious_confirmed_at, u.full_name AS user_name
FROM ops.staff_reports s JOIN iam.users u ON u.id = s.user_id
WHERE s.type = 'journal' AND s.report_date = $2
  AND (s.user_id = $1 OR s.class_id = ANY(@class_ids::uuid[]))
ORDER BY s.created_at, s.id;

-- ───────────── Tổng hợp & kiểm tra (Admin / Quản lý) ─────────────

-- name: ListAll :many
-- Báo cáo & nhật ký đã nộp (không có nháp) trong phạm vi người xem. Lọc từ ngày: báo cáo tuần / tháng / quý tính theo kỳ
-- giao với khoảng (tuần: report_date ≥ from − 6 ngày; tháng: ≥ đầu tháng của from; quý: ≥ đầu quý của from).
SELECT s.id, s.type, s.kind, s.title, s.content, s.data, s.severity, s.report_date, s.status, s.user_id,
       s.submitted_at, s.created_at, s.updated_at, s.reviewed_at, s.review_note,
       u.full_name AS user_name,
       coalesce((SELECT cb.name FROM iam.user_branches b JOIN core.branches cb ON cb.id = b.branch_id
         WHERE b.user_id = s.user_id ORDER BY b.is_primary DESC, cb.name LIMIT 1), '')::text AS branch_name,
       coalesce((SELECT array_agg(r.role ORDER BY r.role) FROM iam.user_roles r WHERE r.user_id = s.user_id), '{}')::text[] AS user_roles,
       rv.full_name AS reviewer_name,
       (SELECT count(*) FROM ops.staff_report_followups f WHERE f.staff_report_id = s.id) AS followups_count,
       count(*) OVER () AS total
FROM ops.staff_reports s
JOIN iam.users u ON u.id = s.user_id
LEFT JOIN iam.users rv ON rv.id = s.reviewed_by
WHERE s.status <> 'draft'
  AND (sqlc.narg('scope_branches')::uuid[] IS NULL
       OR s.user_id = sqlc.arg('viewer_id')::uuid
       OR EXISTS (SELECT 1 FROM iam.user_branches b WHERE b.user_id = s.user_id AND b.branch_id = ANY(sqlc.narg('scope_branches')::uuid[])))
  AND (sqlc.narg('type')::text IS NULL OR s.type = sqlc.narg('type')::text)
  AND (sqlc.narg('user_id')::uuid IS NULL OR s.user_id = sqlc.narg('user_id')::uuid)
  AND (sqlc.narg('branch_id')::uuid IS NULL
       OR EXISTS (SELECT 1 FROM iam.user_branches b WHERE b.user_id = s.user_id AND b.branch_id = sqlc.narg('branch_id')::uuid))
  AND (sqlc.narg('to_date')::date IS NULL OR s.report_date <= sqlc.narg('to_date')::date)
  AND (sqlc.narg('from_date')::date IS NULL
       OR (s.type NOT IN ('weekly', 'monthly', 'quarterly') AND s.report_date >= sqlc.narg('from_date')::date)
       OR (s.type = 'weekly' AND s.report_date >= sqlc.narg('from_date')::date - 6)
       OR (s.type = 'monthly' AND s.report_date >= date_trunc('month', sqlc.narg('from_date')::date)::date)
       OR (s.type = 'quarterly' AND s.report_date >= date_trunc('quarter', sqlc.narg('from_date')::date)::date))
  AND (sqlc.narg('review')::text IS NULL
       OR (sqlc.narg('review')::text = 'pending' AND s.type <> 'journal' AND s.status = 'submitted' AND s.reviewed_at IS NULL)
       OR (sqlc.narg('review')::text = 'done' AND s.type <> 'journal' AND s.reviewed_at IS NOT NULL)
       OR (sqlc.narg('review')::text = 'returned' AND s.type <> 'journal' AND s.status = 'returned'))
ORDER BY CASE WHEN sqlc.arg('order_updated')::bool THEN s.updated_at END DESC NULLS LAST, s.report_date DESC, s.id DESC
LIMIT sqlc.arg('lim') OFFSET sqlc.arg('off');

-- name: AllStats :one
SELECT
    count(*) FILTER (WHERE type = 'journal') AS journal,
    count(*) FILTER (WHERE type = 'daily') AS daily,
    count(*) FILTER (WHERE type = 'weekly') AS weekly,
    count(*) FILTER (WHERE type = 'monthly') AS monthly,
    count(*) FILTER (WHERE type = 'journal' AND severity = 'urgent' AND status <> 'resolved') AS urgent_open,
    count(*) FILTER (WHERE type <> 'journal' AND status = 'submitted' AND reviewed_at IS NULL) AS pending_review,
    count(*) FILTER (WHERE type <> 'journal' AND status = 'returned') AS returned
FROM ops.staff_reports s
WHERE (sqlc.narg('scope_branches')::uuid[] IS NULL
       OR s.user_id = sqlc.arg('viewer_id')::uuid
       OR EXISTS (SELECT 1 FROM iam.user_branches b WHERE b.user_id = s.user_id AND b.branch_id = ANY(sqlc.narg('scope_branches')::uuid[])));

-- name: AllWriterOptions :many
-- Người viết có báo cáo / nhật ký trong phạm vi (ô lọc "Người viết").
SELECT u.id, u.full_name FROM iam.users u
WHERE EXISTS (SELECT 1 FROM ops.staff_reports s WHERE s.user_id = u.id)
  AND (sqlc.narg('scope_branches')::uuid[] IS NULL
       OR u.id = sqlc.arg('viewer_id')::uuid
       OR EXISTS (SELECT 1 FROM iam.user_branches b WHERE b.user_id = u.id AND b.branch_id = ANY(sqlc.narg('scope_branches')::uuid[])))
ORDER BY u.full_name, u.id;

-- name: BranchOptions :many
SELECT id, name FROM core.branches
WHERE is_active AND (sqlc.narg('only')::uuid[] IS NULL OR id = ANY(sqlc.narg('only')::uuid[]))
ORDER BY name;

-- name: GetScopedReport :one
-- Báo cáo / nhật ký đã nộp (không phải nháp) trong phạm vi người xem, kèm tên.
SELECT s.*, u.full_name AS user_name, rv.full_name AS reviewer_name,
       coalesce((SELECT array_agg(r.role ORDER BY r.role) FROM iam.user_roles r WHERE r.user_id = s.user_id), '{}')::text[] AS user_roles
FROM ops.staff_reports s
JOIN iam.users u ON u.id = s.user_id
LEFT JOIN iam.users rv ON rv.id = s.reviewed_by
WHERE s.id = sqlc.arg('id') AND s.status <> 'draft'
  AND (sqlc.narg('scope_branches')::uuid[] IS NULL
       OR s.user_id = sqlc.arg('viewer_id')::uuid
       OR EXISTS (SELECT 1 FROM iam.user_branches b WHERE b.user_id = s.user_id AND b.branch_id = ANY(sqlc.narg('scope_branches')::uuid[])));

-- name: NextPendingID :one
-- Báo cáo kế tiếp đang chờ kiểm tra (nút "Kế tiếp" trong chi tiết).
SELECT s.id FROM ops.staff_reports s
WHERE s.type <> 'journal' AND s.status = 'submitted' AND s.reviewed_at IS NULL AND s.id <> sqlc.arg('id')
  AND (sqlc.narg('scope_branches')::uuid[] IS NULL
       OR s.user_id = sqlc.arg('viewer_id')::uuid
       OR EXISTS (SELECT 1 FROM iam.user_branches b WHERE b.user_id = s.user_id AND b.branch_id = ANY(sqlc.narg('scope_branches')::uuid[])))
ORDER BY s.updated_at DESC, s.id DESC LIMIT 1;

-- name: SetReviewed :one
-- Đánh dấu đã kiểm tra (bản bị yêu cầu bổ sung về lại "đã nộp") hoặc bỏ đánh dấu.
UPDATE ops.staff_reports SET
    reviewed_by = CASE WHEN @reviewed::bool THEN @reviewer::uuid END,
    reviewed_at = CASE WHEN @reviewed::bool THEN now() END,
    status      = CASE WHEN @reviewed::bool AND status = 'returned' THEN 'submitted' ELSE status END,
    review_note = CASE WHEN @reviewed::bool THEN NULL ELSE review_note END
WHERE id = @id
RETURNING *;

-- name: RequestChanges :one
UPDATE ops.staff_reports SET status = 'returned', review_note = @note, reviewed_by = @reviewer, reviewed_at = NULL
WHERE id = @id
RETURNING *;

-- name: PendingInIDs :many
-- Báo cáo đang chờ kiểm tra trong danh sách chọn (kiểm tra hàng loạt), trong phạm vi người xem.
SELECT s.id FROM ops.staff_reports s
WHERE s.id = ANY(@ids::uuid[]) AND s.type <> 'journal' AND s.status = 'submitted' AND s.reviewed_at IS NULL
  AND (sqlc.narg('scope_branches')::uuid[] IS NULL
       OR s.user_id = sqlc.arg('viewer_id')::uuid
       OR EXISTS (SELECT 1 FROM iam.user_branches b WHERE b.user_id = s.user_id AND b.branch_id = ANY(sqlc.narg('scope_branches')::uuid[])));
