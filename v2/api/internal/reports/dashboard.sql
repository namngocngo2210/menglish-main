-- Báo cáo nhân sự cho Tổng quan và Báo cáo & sự vụ lớp (dashboard.Reports).

-- name: DashboardReturnedDaily :many
-- Báo cáo ngày Admin yêu cầu bổ sung (còn chờ nộp lại), mới trước.
SELECT report_date, review_note FROM ops.staff_reports
WHERE user_id = @user_id::uuid AND kind = 'daily_ops' AND status = 'returned'
ORDER BY report_date DESC;

-- name: DashboardTeacherMonthly :many
-- Báo cáo tháng giáo viên đã nộp của tháng.
SELECT user_id, data, updated_at FROM ops.staff_reports
WHERE user_id = ANY (@user_ids::uuid[]) AND kind = 'teacher_monthly' AND period_key = @period::text AND status = 'submitted';

-- name: DashboardSubmitted :many
-- Báo cáo đã nộp (bỏ bản nháp) theo nhóm: daily | weekly (tuần Học thuật + báo cáo tuần chữ) | weekly_kpi | monthly
-- (tháng / quý), báo cáo ngày mới trước.
SELECT r.id, r.kind, r.type, r.period_key, r.title, r.content, r.data, r.report_date, r.updated_at, u.full_name AS user_name
FROM ops.staff_reports r
JOIN iam.users u ON u.id = r.user_id
WHERE r.status <> 'draft'
  AND CASE @report_group::text
        WHEN 'daily' THEN r.type = 'daily'
        WHEN 'weekly' THEN r.type = 'weekly' AND (r.kind = 'academic_weekly' OR r.kind IS NULL)
        WHEN 'weekly_kpi' THEN r.kind = 'weekly_kpi'
        WHEN 'monthly' THEN r.type IN ('monthly', 'quarterly')
        ELSE false
      END
ORDER BY r.report_date DESC, r.id DESC
LIMIT @lim::int;

-- name: DashboardDailyOn :one
-- Báo cáo ngày của một ngày (như Laravel: đếm mọi bản, kể cả nháp).
SELECT count(*) FROM ops.staff_reports WHERE type = 'daily' AND report_date = @day::date;

-- name: DashboardJournals :many
-- Sự việc trong Nhật ký Học vụ: lọc mức độ, trạng thái (open = chưa xử lý xong), cơ sở (theo lớp; không gắn lớp thì theo
-- chi nhánh chính của người ghi), lớp. Kèm lượt follow-up mới nhất.
SELECT r.id, r.title, r.content, r.severity, r.status, r.class_id, c.code AS class_code,
       cb.name AS class_branch_name, ub.name AS user_branch_name, u.full_name AS user_name, f.content AS followup
FROM ops.staff_reports r
JOIN iam.users u ON u.id = r.user_id
LEFT JOIN ops.staff_report_followups f ON f.id = (
    SELECT lf.id FROM ops.staff_report_followups lf WHERE lf.staff_report_id = r.id
    ORDER BY lf.created_at DESC, lf.id DESC LIMIT 1)
LEFT JOIN academic.classes c ON c.id = r.class_id
LEFT JOIN core.branches cb ON cb.id = c.branch_id
LEFT JOIN iam.user_branches pb ON pb.user_id = r.user_id AND pb.is_primary
LEFT JOIN core.branches ub ON ub.id = pb.branch_id
WHERE r.type = 'journal'
  AND (sqlc.narg(severity)::text IS NULL OR r.severity = sqlc.narg(severity)::text)
  AND (sqlc.narg(status)::text IS NULL
       OR (sqlc.narg(status)::text = 'resolved' AND r.status = 'resolved')
       OR (sqlc.narg(status)::text = 'open' AND r.status <> 'resolved'))
  AND (sqlc.narg(class_id)::uuid IS NULL OR r.class_id = sqlc.narg(class_id)::uuid)
  AND (sqlc.narg(branch_id)::uuid IS NULL OR c.branch_id = sqlc.narg(branch_id)::uuid
       OR (r.class_id IS NULL AND pb.branch_id = sqlc.narg(branch_id)::uuid))
ORDER BY r.report_date DESC, r.id DESC
LIMIT @lim::int;
