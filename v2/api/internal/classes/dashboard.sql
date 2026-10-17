-- Lớp học cho Tổng quan và Báo cáo & sự vụ lớp (dashboard.Classes). Phạm vi lớp (visible): all = mọi lớp, còn lại ids.

-- name: DashboardRunningInBranches :one
SELECT count(*) FROM academic.classes c
WHERE c.deleted_at IS NULL AND c.status = 'active'
  AND (sqlc.narg(branches)::uuid[] IS NULL OR c.branch_id = ANY (sqlc.narg(branches)::uuid[]));

-- name: DashboardRunningVisible :one
SELECT count(*) FROM academic.classes c
WHERE c.deleted_at IS NULL AND c.status = 'active' AND (@all_classes::bool OR c.id = ANY (@ids::uuid[]));

-- name: DashboardTeachingClassIDs :many
-- Lớp người này đang giữ: lớp đang chạy mà là GV chính / trợ giảng / GVNN, cộng lớp có buổi người này dạy trong khoảng.
SELECT c.id FROM academic.classes c
WHERE c.deleted_at IS NULL
  AND ((c.status = 'active' AND (c.teacher_id = @user_id::uuid OR c.assistant_id = @user_id::uuid OR c.foreign_teacher_id = @user_id::uuid))
       OR c.id IN (SELECT cs.class_id FROM academic.class_sessions cs
                   WHERE cs.date BETWEEN @date_from::date AND @date_to::date
                     AND (cs.teacher_id = @user_id::uuid OR cs.foreign_teacher_id = @user_id::uuid OR cs.assistant_id = @user_id::uuid)));

-- name: DashboardTeamClassIDs :many
-- Lớp đang chạy trong phạm vi có GV chính mang vai trò giáo viên (Full-time / Part-time).
SELECT c.id FROM academic.classes c
WHERE c.deleted_at IS NULL AND c.status = 'active' AND (@all_classes::bool OR c.id = ANY (@ids::uuid[]))
  AND EXISTS (SELECT 1 FROM iam.user_roles ur WHERE ur.user_id = c.teacher_id
              AND ur.role IN ('teacher_fulltime', 'teacher_parttime'));

-- name: DashboardClassStats :many
-- Số liệu từng lớp trong khoảng: sĩ số hiện tại (danh sách lớp thật), học viên mới / nghỉ, lượt điểm danh.
SELECT c.id, c.code::text AS code, c.name, c.teacher_id,
       (SELECT count(*) FROM academic.class_roster r WHERE r.class_id = c.id)::int AS students,
       (SELECT count(*) FROM academic.enrollments e WHERE e.class_id = c.id AND e.status <> 'dropped'
          AND e.enrolled_at BETWEEN @date_from::date AND @date_to::date)::int AS new_students,
       (SELECT count(*) FROM academic.enrollments e WHERE e.class_id = c.id AND e.status = 'dropped'
          AND e.updated_at BETWEEN @from_at::timestamptz AND @to_at::timestamptz)::int AS dropped,
       (SELECT count(*) FROM academic.student_attendance sa WHERE sa.class_id = c.id
          AND sa.session_date BETWEEN @date_from::date AND @date_to::date)::int AS attendance_total,
       (SELECT count(*) FROM academic.student_attendance sa WHERE sa.class_id = c.id AND sa.status IN ('present', 'late')
          AND sa.session_date BETWEEN @date_from::date AND @date_to::date)::int AS attended
FROM academic.classes c
WHERE c.id = ANY (@ids::uuid[]) AND c.deleted_at IS NULL
ORDER BY c.name, c.id;

-- name: DashboardUserNames :many
SELECT id, full_name FROM iam.users WHERE id = ANY (@ids::uuid[]);

-- name: DashboardClassNames :many
SELECT id, name FROM academic.classes WHERE id = ANY (@ids::uuid[]);

-- name: DashboardClassOverview :one
-- Tổng số lớp / lớp đang chạy và lượt điểm danh của một ngày.
SELECT
    (SELECT count(*) FROM academic.classes WHERE deleted_at IS NULL) AS total,
    (SELECT count(*) FROM academic.classes WHERE deleted_at IS NULL AND status = 'active') AS active,
    (SELECT count(*) FROM academic.student_attendance WHERE session_date = @day::date) AS attendance_total,
    (SELECT count(*) FROM academic.student_attendance WHERE session_date = @day::date AND status IN ('present', 'late')) AS attended;

-- name: DashboardClassOptions :many
-- Lớp chưa hủy trong phạm vi (ô lọc nhật ký sự vụ), theo mã.
SELECT c.id, c.code::text AS code FROM academic.classes c
WHERE c.deleted_at IS NULL AND c.status <> 'cancelled' AND (@all_classes::bool OR c.id = ANY (@ids::uuid[]))
ORDER BY c.code;
