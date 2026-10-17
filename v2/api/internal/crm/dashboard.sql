-- Số liệu CRM cho Tổng quan (dashboard.Leads). Phạm vi khách như danh sách khách: scope_owner = khách mình phụ trách,
-- scope_branches = khách thuộc chi nhánh của mình, cả hai NULL = mọi khách.

-- name: DashboardLeadCounts :one
SELECT
  count(*) FILTER (WHERE l.appointment_at BETWEEN @today_start::timestamptz AND @today_end::timestamptz
      AND l.stage NOT IN ('lost', 'waiting_class', 'won')) AS test_today,
  count(*) FILTER (WHERE l.appointment_at BETWEEN @today_start::timestamptz AND @today_end::timestamptz
      AND l.stage = 'test_scheduled') AS test_today_pending,
  count(*) FILTER (WHERE l.stage IN ('new', 'consulting', 'test_scheduled', 'testing', 'tested', 'result_sent')
      AND l.next_follow_up_at IS NOT NULL AND l.next_follow_up_at <= @today_end::timestamptz
      AND (l.last_care_at IS NULL OR l.last_care_at < l.next_follow_up_at)) AS follow_up,
  count(*) FILTER (WHERE l.stage IN ('new', 'consulting', 'test_scheduled', 'testing', 'tested', 'result_sent')
      AND l.next_follow_up_at IS NOT NULL AND l.next_follow_up_at < @now::timestamptz
      AND (l.last_care_at IS NULL OR l.last_care_at < l.next_follow_up_at)) AS follow_up_overdue,
  count(*) FILTER (WHERE l.stage = 'new' AND l.created_at <= @first_contact_cutoff::timestamptz
      AND l.last_care_at IS NULL) AS stale,
  count(*) FILTER (WHERE l.stage = 'waiting_class') AS waiting_class,
  count(*) FILTER (WHERE l.converted_at BETWEEN @month_start::timestamptz AND @month_end::timestamptz) AS closed_month
FROM crm.leads l
WHERE l.deleted_at IS NULL
  AND (sqlc.narg(scope_owner)::uuid IS NULL OR l.assigned_user_id = sqlc.narg(scope_owner)::uuid)
  AND (sqlc.narg(scope_branches)::uuid[] IS NULL OR l.branch_id = ANY (sqlc.narg(scope_branches)::uuid[]));

-- name: DashboardNewLeads :one
-- Khách tạo trong khoảng thuộc chi nhánh (bảng điều hành; NULL = mọi chi nhánh).
SELECT count(*) FROM crm.leads l
WHERE l.deleted_at IS NULL AND l.created_at BETWEEN @date_from::timestamptz AND @date_to::timestamptz
  AND (sqlc.narg(branches)::uuid[] IS NULL OR l.branch_id = ANY (sqlc.narg(branches)::uuid[]));

-- name: DashboardAppointments :many
-- Hẹn test đầu vào trong khoảng (bỏ khách thất bại / đã chốt).
SELECT l.id, l.name, l.stage, l.appointment_at::timestamptz AS appointment_at, l.appointment_type, u.full_name AS assigned_name
FROM crm.leads l
LEFT JOIN iam.users u ON u.id = l.assigned_user_id
WHERE l.deleted_at IS NULL AND l.stage NOT IN ('lost', 'waiting_class', 'won')
  AND l.appointment_at BETWEEN @date_from::timestamptz AND @date_to::timestamptz
  AND (sqlc.narg(scope_owner)::uuid IS NULL OR l.assigned_user_id = sqlc.narg(scope_owner)::uuid)
  AND (sqlc.narg(scope_branches)::uuid[] IS NULL OR l.branch_id = ANY (sqlc.narg(scope_branches)::uuid[]));

-- name: DashboardFollowUps :many
-- Hẹn gọi lại trong khoảng của khách đang chăm sóc.
SELECT l.id, l.name, l.stage, l.next_follow_up_at::timestamptz AS next_follow_up_at
FROM crm.leads l
WHERE l.deleted_at IS NULL AND l.stage IN ('new', 'consulting', 'test_scheduled', 'testing', 'tested', 'result_sent')
  AND l.next_follow_up_at BETWEEN @date_from::timestamptz AND @date_to::timestamptz
  AND (sqlc.narg(scope_owner)::uuid IS NULL OR l.assigned_user_id = sqlc.narg(scope_owner)::uuid)
  AND (sqlc.narg(scope_branches)::uuid[] IS NULL OR l.branch_id = ANY (sqlc.narg(scope_branches)::uuid[]));

-- name: DashboardTrials :many
-- Học thử đã hẹn (chưa diễn ra / chưa điểm danh) có buổi trong khoảng ngày, của khách trong phạm vi.
SELECT tb.lead_id, l.name AS lead_name, tb.session_date, tb.start_time, tb.end_time, c.name AS class_name
FROM crm.trial_bookings tb
JOIN crm.leads l ON l.id = tb.lead_id AND l.deleted_at IS NULL
LEFT JOIN academic.classes c ON c.id = tb.class_id
WHERE tb.status = 'scheduled' AND tb.session_date BETWEEN @date_from::date AND @date_to::date
  AND (sqlc.narg(scope_owner)::uuid IS NULL OR l.assigned_user_id = sqlc.narg(scope_owner)::uuid)
  AND (sqlc.narg(scope_branches)::uuid[] IS NULL OR l.branch_id = ANY (sqlc.narg(scope_branches)::uuid[]));

-- name: DashboardTrialsCount :one
SELECT count(*)
FROM crm.trial_bookings tb
JOIN crm.leads l ON l.id = tb.lead_id AND l.deleted_at IS NULL
WHERE tb.status = 'scheduled' AND tb.session_date BETWEEN @date_from::date AND @date_to::date
  AND (sqlc.narg(scope_owner)::uuid IS NULL OR l.assigned_user_id = sqlc.narg(scope_owner)::uuid)
  AND (sqlc.narg(scope_branches)::uuid[] IS NULL OR l.branch_id = ANY (sqlc.narg(scope_branches)::uuid[]));

-- name: DashboardCareLeads :many
-- Khách đã chốt gần đây có học viên Chờ khai giảng / Đang học (support sau chốt): lớp chính, đã đi học buổi nào chưa,
-- buổi học sắp tới đầu tiên của lớp trong khoảng.
SELECT l.id, l.name, l.converted_at::timestamptz AS converted_at, l.care_checklist, s.id AS student_id,
       s.current_class_id, c.name AS class_name,
       EXISTS (SELECT 1 FROM academic.student_attendance sa
               WHERE sa.student_id = s.id AND sa.status IN ('present', 'late')) AS attended,
       fs.date AS first_session_date, fs.start_time AS first_session_start
FROM crm.leads l
JOIN academic.students s ON s.id = l.converted_student_id AND s.deleted_at IS NULL
LEFT JOIN academic.classes c ON c.id = s.current_class_id
LEFT JOIN academic.class_sessions fs ON fs.id = (
    SELECT cs.id FROM academic.class_sessions cs
    WHERE cs.class_id = s.current_class_id AND cs.status <> 'cancelled'
      AND cs.date BETWEEN @date_from::date AND @date_to::date
    ORDER BY cs.date, cs.start_time LIMIT 1)
WHERE l.deleted_at IS NULL AND l.stage IN ('waiting_class', 'won')
  AND l.converted_at >= @converted_since::timestamptz
  AND s.status IN ('waiting_start', 'studying')
  AND (sqlc.narg(scope_owner)::uuid IS NULL OR l.assigned_user_id = sqlc.narg(scope_owner)::uuid)
  AND (sqlc.narg(scope_branches)::uuid[] IS NULL OR l.branch_id = ANY (sqlc.narg(scope_branches)::uuid[]));

-- name: DashboardCareTasks :many
-- Việc chăm sóc tháng đầu (care_milestone) của các học viên.
SELECT t.student_id::uuid AS student_id, t.care_milestone::smallint AS care_milestone, t.status, t.due_date, t.due_time,
       u.full_name AS assignee_name
FROM ops.work_tasks t
LEFT JOIN iam.users u ON u.id = t.assignee_id
WHERE t.student_id = ANY (@student_ids::uuid[]) AND t.care_milestone IS NOT NULL;
