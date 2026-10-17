-- ═════════════ Nhân sự & chi nhánh chấm công ═════════════

-- name: StaffContext :one
-- Nhân sự + chi nhánh chính (cài đặt chấm công của chi nhánh đó).
SELECT u.id, u.full_name, u.code::text AS code, u.status,
       b.id AS branch_id, b.name AS branch_name, b.address AS branch_address, b.latitude, b.longitude,
       b.checkin_radius, b.work_start_time, b.work_end_time, b.late_grace_minutes
FROM iam.users u
LEFT JOIN iam.user_branches ub ON ub.user_id = u.id AND ub.is_primary
LEFT JOIN core.branches b ON b.id = ub.branch_id
WHERE u.id = sqlc.arg(user_id);

-- name: BranchCheckin :one
SELECT id, name, latitude, longitude, checkin_radius, work_start_time, work_end_time, late_grace_minutes
FROM core.branches WHERE id = sqlc.arg(id);

-- name: ScopeBranches :many
-- Chi nhánh đang hoạt động trong phạm vi (bộ lọc màn Chấm công hằng ngày, cảnh báo chưa cài tọa độ).
SELECT id, name, (latitude IS NOT NULL AND longitude IS NOT NULL)::bool AS configured
FROM core.branches
WHERE is_active AND (sqlc.arg(all_branches)::bool OR id = ANY(sqlc.arg(branch_ids)::uuid[]))
ORDER BY name;

-- name: UserBriefs :many
SELECT u.id, u.full_name, u.code::text AS code FROM iam.users u WHERE u.id = ANY(sqlc.arg(ids)::uuid[]);

-- ═════════════ Chấm công hằng ngày ═════════════

-- name: AttendanceForDay :one
SELECT * FROM hr.staff_attendances WHERE user_id = sqlc.arg(user_id) AND work_date = sqlc.arg(work_date);

-- name: AttendanceForDayLock :one
SELECT * FROM hr.staff_attendances WHERE user_id = sqlc.arg(user_id) AND work_date = sqlc.arg(work_date) FOR UPDATE;

-- name: UpsertAttendance :one
INSERT INTO hr.staff_attendances (
    user_id, branch_id, work_date, source, expected_start, expected_end,
    check_in_at, check_in_photo_id, check_in_lat, check_in_lng, check_in_distance, check_in_accuracy,
    check_out_at, check_out_photo_id, check_out_lat, check_out_lng, check_out_distance, check_out_accuracy,
    late_minutes, early_minutes, late_excused, penalty_id, note)
VALUES (
    sqlc.arg(user_id), sqlc.narg(branch_id), sqlc.arg(work_date), sqlc.arg(source), sqlc.narg(expected_start)::text::time, sqlc.narg(expected_end)::text::time,
    sqlc.narg(check_in_at), sqlc.narg(check_in_photo_id), sqlc.narg(check_in_lat), sqlc.narg(check_in_lng),
    sqlc.narg(check_in_distance), sqlc.narg(check_in_accuracy),
    sqlc.narg(check_out_at), sqlc.narg(check_out_photo_id), sqlc.narg(check_out_lat), sqlc.narg(check_out_lng),
    sqlc.narg(check_out_distance), sqlc.narg(check_out_accuracy),
    sqlc.arg(late_minutes), sqlc.arg(early_minutes), sqlc.arg(late_excused), sqlc.narg(penalty_id), sqlc.narg(note))
ON CONFLICT (user_id, work_date) DO UPDATE SET
    branch_id = EXCLUDED.branch_id, source = EXCLUDED.source,
    expected_start = EXCLUDED.expected_start, expected_end = EXCLUDED.expected_end,
    check_in_at = EXCLUDED.check_in_at, check_in_photo_id = EXCLUDED.check_in_photo_id,
    check_in_lat = EXCLUDED.check_in_lat, check_in_lng = EXCLUDED.check_in_lng,
    check_in_distance = EXCLUDED.check_in_distance, check_in_accuracy = EXCLUDED.check_in_accuracy,
    check_out_at = EXCLUDED.check_out_at, check_out_photo_id = EXCLUDED.check_out_photo_id,
    check_out_lat = EXCLUDED.check_out_lat, check_out_lng = EXCLUDED.check_out_lng,
    check_out_distance = EXCLUDED.check_out_distance, check_out_accuracy = EXCLUDED.check_out_accuracy,
    late_minutes = EXCLUDED.late_minutes, early_minutes = EXCLUDED.early_minutes, late_excused = EXCLUDED.late_excused,
    penalty_id = EXCLUDED.penalty_id, note = EXCLUDED.note, updated_at = now()
RETURNING *;

-- name: SetAttendancePenalty :exec
UPDATE hr.staff_attendances SET penalty_id = sqlc.narg(penalty_id), updated_at = now() WHERE id = sqlc.arg(id);

-- name: GetAttendance :one
SELECT a.*, u.full_name AS user_name, u.code::text AS user_code, b.name AS branch_name, b.checkin_radius AS branch_radius
FROM hr.staff_attendances a
JOIN iam.users u ON u.id = a.user_id
LEFT JOIN core.branches b ON b.id = a.branch_id
WHERE a.id = sqlc.arg(id);

-- name: AttendanceByPhoto :one
-- Dòng chấm công của ảnh (kiểm quyền mở file ảnh khuôn mặt).
SELECT id, user_id, branch_id FROM hr.staff_attendances
WHERE check_in_photo_id = sqlc.arg(photo_id) OR check_out_photo_id = sqlc.arg(photo_id)
LIMIT 1;

-- name: UserAttendances :many
-- Ngày công của một người trong khoảng, mới trước (Lịch sử công).
SELECT * FROM hr.staff_attendances
WHERE user_id = sqlc.arg(user_id) AND work_date BETWEEN sqlc.arg(from_date) AND sqlc.arg(to_date)
ORDER BY work_date DESC;

-- name: RangeAttendances :many
-- Ngày công của nhiều người trong khoảng (ngày / tháng của màn Chấm công hằng ngày, tổng hợp cho bảng lương).
SELECT * FROM hr.staff_attendances
WHERE user_id = ANY(sqlc.arg(user_ids)::uuid[]) AND work_date BETWEEN sqlc.arg(from_date) AND sqlc.arg(to_date)
ORDER BY work_date;

-- name: StaffPage :many
-- Nhân sự đang hoạt động có cổng nhân sự (ids) trong phạm vi chi nhánh, theo bộ lọc của màn Chấm công hằng ngày.
-- status (chỉ màn theo ngày): checked_in | late | leave | missing.
SELECT u.id, u.full_name, u.code::text AS code, b.id AS branch_id, b.name AS branch_name, count(*) OVER () AS total
FROM iam.users u
LEFT JOIN iam.user_branches ub ON ub.user_id = u.id AND ub.is_primary
LEFT JOIN core.branches b ON b.id = ub.branch_id
WHERE u.status = 'active' AND u.id = ANY(sqlc.arg(staff_ids)::uuid[])
  AND (sqlc.arg(all_branches)::bool OR ub.branch_id = ANY(sqlc.arg(branch_ids)::uuid[]))
  AND (sqlc.narg(branch_id)::uuid IS NULL OR ub.branch_id = sqlc.narg(branch_id))
  AND (sqlc.narg(user_id)::uuid IS NULL OR u.id = sqlc.narg(user_id))
  AND (sqlc.narg(q)::text IS NULL OR u.full_name ILIKE sqlc.narg(q) OR u.code::text ILIKE sqlc.narg(q))
  AND (sqlc.narg(status)::text IS NULL
    OR (sqlc.narg(status) = 'checked_in' AND EXISTS (SELECT 1 FROM hr.staff_attendances a
        WHERE a.user_id = u.id AND a.work_date = sqlc.arg(day) AND a.check_in_at IS NOT NULL))
    OR (sqlc.narg(status) = 'late' AND EXISTS (SELECT 1 FROM hr.staff_attendances a
        WHERE a.user_id = u.id AND a.work_date = sqlc.arg(day) AND a.late_minutes > 0))
    OR (sqlc.narg(status) = 'leave' AND EXISTS (SELECT 1 FROM hr.staff_attendance_requests r
        WHERE r.user_id = u.id AND r.status = 'approved' AND r.type = 'leave' AND r.date_from <= sqlc.arg(day) AND r.date_to >= sqlc.arg(day)))
    OR (sqlc.narg(status) = 'missing'
        AND NOT EXISTS (SELECT 1 FROM hr.staff_attendances a
            WHERE a.user_id = u.id AND a.work_date = sqlc.arg(day) AND a.check_in_at IS NOT NULL)
        AND NOT EXISTS (SELECT 1 FROM hr.staff_attendance_requests r
            WHERE r.user_id = u.id AND r.status = 'approved' AND r.type = 'leave' AND r.date_from <= sqlc.arg(day) AND r.date_to >= sqlc.arg(day))))
ORDER BY b.name NULLS FIRST, u.full_name, u.id
LIMIT sqlc.arg(lim) OFFSET sqlc.arg(off);

-- name: DayStats :one
-- Thẻ số liệu màn theo ngày: nhân sự trong phạm vi, đã chấm vào, đi muộn (tính lỗi), nghỉ có phép.
WITH staff AS (
    SELECT u.id FROM iam.users u
    LEFT JOIN iam.user_branches ub ON ub.user_id = u.id AND ub.is_primary
    WHERE u.status = 'active' AND u.id = ANY(sqlc.arg(staff_ids)::uuid[])
      AND (sqlc.arg(all_branches)::bool OR ub.branch_id = ANY(sqlc.arg(branch_ids)::uuid[]))
      AND (sqlc.narg(branch_id)::uuid IS NULL OR ub.branch_id = sqlc.narg(branch_id))
)
SELECT (SELECT count(*) FROM staff)::int AS staff,
       (SELECT count(*) FROM hr.staff_attendances a JOIN staff s ON s.id = a.user_id
        WHERE a.work_date = sqlc.arg(day) AND a.check_in_at IS NOT NULL)::int AS checked_in,
       (SELECT count(*) FROM hr.staff_attendances a JOIN staff s ON s.id = a.user_id
        WHERE a.work_date = sqlc.arg(day) AND a.late_minutes > 0 AND NOT a.late_excused)::int AS late,
       (SELECT count(DISTINCT r.user_id) FROM hr.staff_attendance_requests r JOIN staff s ON s.id = r.user_id
        WHERE r.status = 'approved' AND r.type = 'leave' AND r.date_from <= sqlc.arg(day) AND r.date_to >= sqlc.arg(day))::int AS on_leave;

-- name: ExpectedSessions :many
-- Buổi dạy trong ngày của nhân sự (GV chính / GVNN / trợ giảng), chưa hủy: giờ phải có mặt của GV / TA.
SELECT s.id, s.shift_name, s.start_time, s.end_time, c.code::text AS class_code, c.name AS class_name
FROM academic.class_sessions s
JOIN academic.classes c ON c.id = s.class_id
WHERE s.date = sqlc.arg(day) AND s.status <> 'cancelled'
  AND (s.teacher_id = sqlc.arg(user_id) OR s.foreign_teacher_id = sqlc.arg(user_id) OR s.assistant_id = sqlc.arg(user_id))
ORDER BY s.start_time, s.id;

-- ═════════════ Đơn chấm công & nghỉ ═════════════

-- name: InsertRequest :one
INSERT INTO hr.staff_attendance_requests (user_id, branch_id, type, date_from, date_to, check_in_time, check_out_time, reason)
VALUES (sqlc.arg(user_id), sqlc.narg(branch_id), sqlc.arg(type), sqlc.arg(date_from), sqlc.arg(date_to), sqlc.narg(check_in_time)::text::time,
        sqlc.narg(check_out_time)::text::time, sqlc.arg(reason))
RETURNING *;

-- name: MyRequests :many
SELECT r.*, rv.full_name AS reviewer_name
FROM hr.staff_attendance_requests r
LEFT JOIN iam.users rv ON rv.id = r.reviewed_by
WHERE r.user_id = sqlc.arg(user_id)
ORDER BY r.created_at DESC, r.id DESC
LIMIT 30;

-- name: GetRequestLock :one
SELECT * FROM hr.staff_attendance_requests WHERE id = sqlc.arg(id) FOR UPDATE;

-- name: PendingDuplicate :one
SELECT EXISTS (SELECT 1 FROM hr.staff_attendance_requests
    WHERE user_id = sqlc.arg(user_id) AND type = sqlc.arg(type) AND status = 'pending' AND date_from <= sqlc.arg(date_to) AND date_to >= sqlc.arg(date_from))::bool;

-- name: HasApproved :one
SELECT EXISTS (SELECT 1 FROM hr.staff_attendance_requests
    WHERE user_id = sqlc.arg(user_id) AND type = sqlc.arg(type) AND status = 'approved' AND date_from <= sqlc.arg(day) AND date_to >= sqlc.arg(day))::bool;

-- name: ApprovedLeaves :many
-- Đơn xin nghỉ đã duyệt trùng khoảng ngày (Lịch sử công, tổng hợp tháng, nghỉ có phép theo ngày).
SELECT id, user_id, date_from, date_to, reason FROM hr.staff_attendance_requests
WHERE user_id = ANY(sqlc.arg(user_ids)::uuid[]) AND status = 'approved' AND type = 'leave'
  AND date_from <= sqlc.arg(to_date) AND date_to >= sqlc.arg(from_date)
ORDER BY date_from DESC;

-- name: CancelRequest :exec
UPDATE hr.staff_attendance_requests SET status = 'cancelled', updated_at = now() WHERE id = sqlc.arg(id);

-- name: ReviewRequest :exec
UPDATE hr.staff_attendance_requests
SET status = sqlc.arg(status), reviewed_by = sqlc.arg(reviewed_by), reviewed_at = now(), rejection_reason = sqlc.narg(rejection_reason),
    updated_at = now()
WHERE id = sqlc.arg(id);

-- name: PendingRequests :many
-- Đơn chờ duyệt người duyệt xử lý được: trong phạm vi chi nhánh, không phải đơn của chính mình (reviewQuery), cũ trước.
SELECT r.*, u.full_name AS user_name, u.code::text AS user_code, b.name AS branch_name,
       a.check_in_at AS punch_in, a.check_out_at AS punch_out, a.late_minutes AS punch_late, a.early_minutes AS punch_early,
       a.late_excused AS punch_excused
FROM hr.staff_attendance_requests r
JOIN iam.users u ON u.id = r.user_id
LEFT JOIN core.branches b ON b.id = r.branch_id
LEFT JOIN hr.staff_attendances a ON a.user_id = r.user_id AND a.work_date = r.date_from
WHERE r.status = 'pending' AND r.user_id <> sqlc.arg(reviewer_id)
  AND (sqlc.arg(all_branches)::bool OR r.branch_id = ANY(sqlc.arg(branch_ids)::uuid[]))
ORDER BY r.created_at, r.id;

-- ═════════════ Giờ dạy giáo viên ═════════════

-- name: TimesheetPage :many
-- Ca dạy trong tháng theo bộ lọc (teacherTimesheets). class_ids: lớp trong phạm vi (all_classes = mọi lớp).
SELECT t.*, u.full_name AS teacher_name, u.code::text AS teacher_code, COALESCE(c.code::text, '')::text AS class_code, COALESCE(c.name, '')::text AS class_name,
       s.shift_name, rv.full_name AS reviewer_name, ad.full_name AS adjuster_name, count(*) OVER () AS total
FROM hr.teacher_timesheets t
JOIN iam.users u ON u.id = t.user_id
LEFT JOIN academic.classes c ON c.id = t.class_id
LEFT JOIN academic.class_sessions s ON s.id = t.class_session_id
LEFT JOIN iam.users rv ON rv.id = t.reviewed_by
LEFT JOIN iam.users ad ON ad.id = t.adjusted_by
WHERE t.teaching_date BETWEEN sqlc.arg(from_date) AND sqlc.arg(to_date)
  AND (sqlc.arg(all_classes)::bool OR t.class_id = ANY(sqlc.arg(class_ids)::uuid[]))
  AND (sqlc.narg(user_id)::uuid IS NULL OR t.user_id = sqlc.narg(user_id))
  AND (sqlc.narg(branch_id)::uuid IS NULL OR c.branch_id = sqlc.narg(branch_id))
  AND (sqlc.narg(class_id)::uuid IS NULL OR t.class_id = sqlc.narg(class_id))
  AND (sqlc.narg(status)::text IS NULL OR t.status = sqlc.narg(status))
  AND (sqlc.narg(type)::text IS NULL OR t.type = sqlc.narg(type))
  AND (sqlc.narg(q)::text IS NULL OR u.full_name ILIKE sqlc.narg(q) OR u.code::text ILIKE sqlc.narg(q))
ORDER BY t.teaching_date DESC, t.id DESC
LIMIT sqlc.arg(lim) OFFSET sqlc.arg(off);

-- name: TimesheetSummary :many
SELECT t.status, count(*)::int AS total
FROM hr.teacher_timesheets t
JOIN iam.users u ON u.id = t.user_id
LEFT JOIN academic.classes c ON c.id = t.class_id
WHERE t.teaching_date BETWEEN sqlc.arg(from_date) AND sqlc.arg(to_date)
  AND (sqlc.arg(all_classes)::bool OR t.class_id = ANY(sqlc.arg(class_ids)::uuid[]))
  AND (sqlc.narg(user_id)::uuid IS NULL OR t.user_id = sqlc.narg(user_id))
  AND (sqlc.narg(branch_id)::uuid IS NULL OR c.branch_id = sqlc.narg(branch_id))
  AND (sqlc.narg(class_id)::uuid IS NULL OR t.class_id = sqlc.narg(class_id))
  AND (sqlc.narg(status)::text IS NULL OR t.status = sqlc.narg(status))
  AND (sqlc.narg(type)::text IS NULL OR t.type = sqlc.narg(type))
  AND (sqlc.narg(q)::text IS NULL OR u.full_name ILIKE sqlc.narg(q) OR u.code::text ILIKE sqlc.narg(q))
GROUP BY t.status;

-- name: SubPendingCount :one
SELECT count(*)::int FROM hr.teacher_timesheets
WHERE type = 'sub' AND status = 'pending_review'
  AND (sqlc.arg(all_classes)::bool OR class_id = ANY(sqlc.arg(class_ids)::uuid[]));

-- name: GetTimesheetLock :one
SELECT t.*, u.full_name AS teacher_name, COALESCE(c.code::text, '')::text AS class_code
FROM hr.teacher_timesheets t
JOIN iam.users u ON u.id = t.user_id
LEFT JOIN academic.classes c ON c.id = t.class_id
WHERE t.id = sqlc.arg(id)
FOR UPDATE OF t;

-- name: TimesheetsByIDs :many
SELECT * FROM hr.teacher_timesheets WHERE id = ANY(sqlc.arg(ids)::uuid[]) ORDER BY teaching_date, id FOR UPDATE;

-- name: FindDuplicate :one
-- Ca đang có hiệu lực (chưa bị từ chối) trùng ca định ghi: cùng người + cùng buổi, hoặc cùng người + lớp + ngày khi ca cũ /
-- ca mới không gắn buổi học (TeacherTimesheet::findDuplicate).
SELECT * FROM hr.teacher_timesheets
WHERE user_id = sqlc.arg(user_id) AND status <> 'invalid'
  AND (sqlc.narg(ignore_id)::uuid IS NULL OR id <> sqlc.narg(ignore_id))
  AND ((sqlc.narg(session_id)::uuid IS NOT NULL
        AND (class_session_id = sqlc.narg(session_id)
             OR (class_session_id IS NULL AND class_id = sqlc.narg(class_id) AND teaching_date = sqlc.arg(day))))
    OR (sqlc.narg(session_id)::uuid IS NULL AND class_id = sqlc.narg(class_id) AND teaching_date = sqlc.arg(day)))
ORDER BY id
LIMIT 1;

-- name: TimesheetBySession :one
SELECT * FROM hr.teacher_timesheets WHERE user_id = sqlc.arg(user_id) AND class_session_id = sqlc.arg(session_id) FOR UPDATE;

-- name: InsertTimesheet :one
INSERT INTO hr.teacher_timesheets (
    user_id, class_id, class_session_id, teaching_date, scheduled_time, checkin_time, checkout_time, hours,
    late_minutes, early_leave_minutes, late_notified, hourly_rate, type, source, status, reviewed_by, reviewed_at,
    rejection_reason, notes, created_by)
VALUES (
    sqlc.arg(user_id), sqlc.narg(class_id), sqlc.narg(class_session_id), sqlc.arg(teaching_date), sqlc.narg(scheduled_time),
    sqlc.narg(checkin_time)::text::time, sqlc.narg(checkout_time)::text::time, sqlc.arg(hours),
    sqlc.arg(late_minutes), sqlc.arg(early_leave_minutes), sqlc.arg(late_notified), sqlc.narg(hourly_rate), sqlc.arg(type), sqlc.arg(source), sqlc.arg(status),
    sqlc.narg(reviewed_by), sqlc.narg(reviewed_at), NULL, sqlc.narg(notes), sqlc.narg(created_by))
RETURNING *;

-- name: OverwriteTimesheet :one
-- Ghi đè ca bị từ chối của cùng buổi (unique người + buổi) khi chấm lại.
UPDATE hr.teacher_timesheets SET
    class_id = sqlc.narg(class_id), class_session_id = sqlc.narg(class_session_id), teaching_date = sqlc.arg(teaching_date),
    scheduled_time = sqlc.narg(scheduled_time), checkin_time = sqlc.narg(checkin_time)::text::time,
    checkout_time = sqlc.narg(checkout_time)::text::time, hours = sqlc.arg(hours), late_minutes = sqlc.arg(late_minutes),
    early_leave_minutes = sqlc.arg(early_leave_minutes), late_notified = sqlc.arg(late_notified), hourly_rate = sqlc.narg(hourly_rate),
    type = sqlc.arg(type), source = sqlc.arg(source), status = sqlc.arg(status), reviewed_by = sqlc.narg(reviewed_by),
    reviewed_at = sqlc.narg(reviewed_at), rejection_reason = NULL, notes = sqlc.narg(notes),
    adjusted_at = NULL, adjusted_by = NULL, adjustment_reason = NULL, updated_at = now()
WHERE id = sqlc.arg(id)
RETURNING *;

-- name: ReviewTimesheet :exec
UPDATE hr.teacher_timesheets
SET status = sqlc.arg(status), reviewed_by = sqlc.arg(reviewed_by), reviewed_at = now(), rejection_reason = sqlc.narg(rejection_reason),
    updated_at = now()
WHERE id = sqlc.arg(id);

-- name: AdjustTimesheet :one
UPDATE hr.teacher_timesheets
SET checkin_time = sqlc.arg(checkin_time)::text::time, checkout_time = sqlc.arg(checkout_time)::text::time, hours = sqlc.arg(hours),
    late_minutes = sqlc.arg(late_minutes), early_leave_minutes = sqlc.arg(early_leave_minutes), late_notified = sqlc.arg(late_notified),
    status = 'pending_review', reviewed_by = NULL, reviewed_at = NULL, rejection_reason = NULL,
    adjusted_at = now(), adjusted_by = sqlc.arg(adjusted_by), adjustment_reason = sqlc.arg(adjustment_reason), updated_at = now()
WHERE id = sqlc.arg(id)
RETURNING *;

-- name: MissingSessions :many
-- Buổi được phân công đã diễn ra trong khoảng mà chưa có ca chấm công còn hiệu lực (ca chấm tay không gắn buổi cùng lớp +
-- ngày cũng coi là đã chấm).
SELECT s.id, s.class_id, s.date, s.start_time, s.end_time, c.code::text AS class_code, c.name AS class_name, c.branch_id
FROM academic.class_sessions s
JOIN academic.classes c ON c.id = s.class_id
WHERE (s.teacher_id = sqlc.arg(user_id) OR s.foreign_teacher_id = sqlc.arg(user_id) OR s.assistant_id = sqlc.arg(user_id))
  AND s.status <> 'cancelled' AND s.date BETWEEN sqlc.arg(from_date) AND sqlc.arg(to_date)
  AND (sqlc.narg(class_id)::uuid IS NULL OR s.class_id = sqlc.narg(class_id))
  AND NOT EXISTS (SELECT 1 FROM hr.teacher_timesheets t
      WHERE t.user_id = sqlc.arg(user_id) AND t.status <> 'invalid'
        AND (t.class_session_id = s.id OR (t.class_session_id IS NULL AND t.class_id = s.class_id AND t.teaching_date = s.date)))
ORDER BY s.date, s.start_time;

-- name: ScheduleSessions :many
-- Buổi học của ngày (đã phân công GV, chưa hủy) thuộc lớp trong phạm vi + ca chấm công còn hiệu lực của GV dự kiến.
SELECT s.id, s.class_id, s.date, s.start_time, s.end_time, s.teacher_id, c.code::text AS class_code, c.name AS class_name,
       tu.full_name AS teacher_name, ts.id AS ts_id, ts.status AS ts_status, ts.source AS ts_source, rv.full_name AS ts_reviewer
FROM academic.class_sessions s
JOIN academic.classes c ON c.id = s.class_id
LEFT JOIN iam.users tu ON tu.id = s.teacher_id
LEFT JOIN hr.teacher_timesheets ts ON ts.id = (
    SELECT t.id FROM hr.teacher_timesheets t
    WHERE t.user_id = s.teacher_id AND t.status <> 'invalid'
      AND (t.class_session_id = s.id OR (t.class_session_id IS NULL AND t.class_id = s.class_id AND t.teaching_date = s.date))
    ORDER BY (t.class_session_id IS NOT NULL) DESC, t.id
    LIMIT 1)
LEFT JOIN iam.users rv ON rv.id = ts.reviewed_by
WHERE s.date = sqlc.arg(day) AND s.status <> 'cancelled' AND s.teacher_id IS NOT NULL AND c.deleted_at IS NULL
  AND (sqlc.arg(all_classes)::bool OR s.class_id = ANY(sqlc.arg(class_ids)::uuid[]))
  AND (sqlc.narg(branch_id)::uuid IS NULL OR c.branch_id = sqlc.narg(branch_id))
ORDER BY s.start_time, s.id;

-- name: GetSession :one
SELECT s.id, s.class_id, s.date, s.start_time, s.end_time, s.status, s.type, s.teacher_id, c.code::text AS class_code,
       c.branch_id
FROM academic.class_sessions s
JOIN academic.classes c ON c.id = s.class_id
WHERE s.id = sqlc.arg(id);

-- name: ClassSessionsOn :many
-- Buổi học của lớp trong ngày (gắn ca chấm tay vào buổi thật: TeacherTimesheet::matchSession).
SELECT id, start_time, end_time, teacher_id, assistant_id, foreign_teacher_id
FROM academic.class_sessions
WHERE class_id = sqlc.arg(class_id) AND date = sqlc.arg(day)
ORDER BY start_time, id;

-- name: ScopeClasses :many
-- Lớp (chưa xóa) trong phạm vi chấm công; statuses rỗng = mọi trạng thái.
SELECT c.id, c.code::text AS code, c.name, c.branch_id, b.name AS branch_name
FROM academic.classes c
JOIN core.branches b ON b.id = c.branch_id
WHERE c.deleted_at IS NULL
  AND (sqlc.arg(all_classes)::bool OR c.id = ANY(sqlc.arg(class_ids)::uuid[]))
  AND (cardinality(sqlc.arg(statuses)::text[]) = 0 OR c.status = ANY(sqlc.arg(statuses)::text[]))
ORDER BY c.name, c.id;

-- name: BranchClassIDs :many
SELECT id FROM academic.classes WHERE deleted_at IS NULL AND branch_id = ANY(sqlc.arg(branch_ids)::uuid[]);

-- name: GetClass :one
SELECT id, code::text AS code, name, branch_id FROM academic.classes WHERE id = sqlc.arg(id) AND deleted_at IS NULL;

-- name: TeachingStaff :many
-- Nhân sự giảng dạy đang hoạt động (GV, TA, Học thuật, Quản lý cơ sở) cho ô chọn của chấm tay / bộ lọc.
SELECT u.id, u.full_name, u.code::text AS code, COALESCE(u.email::text, '')::text AS email,
       string_agg(r.label, ', ' ORDER BY r.label)::text AS role_labels
FROM iam.users u
JOIN iam.user_roles ur ON ur.user_id = u.id
JOIN iam.roles r ON r.name = ur.role
WHERE u.status = 'active' AND r.name = ANY(sqlc.arg(roles)::text[])
GROUP BY u.id
ORDER BY u.full_name, u.id;

-- ═════════════ Lịch sử đồng bộ ═════════════

-- name: SyncLogs :many
SELECT l.*, b.name AS branch_name, count(*) OVER () AS total
FROM hr.timesheet_sync_logs l
LEFT JOIN core.branches b ON b.id = l.branch_id
WHERE (sqlc.narg(from_at)::timestamptz IS NULL OR l.created_at >= sqlc.narg(from_at))
  AND (sqlc.narg(to_at)::timestamptz IS NULL OR l.created_at <= sqlc.narg(to_at))
  AND (sqlc.narg(status)::text IS NULL
    OR (sqlc.narg(status) = 'failed' AND l.status IN ('failed', 'error'))
    OR (sqlc.narg(status) <> 'failed' AND l.status = sqlc.narg(status)))
ORDER BY l.created_at DESC, l.id DESC
LIMIT sqlc.arg(lim) OFFSET sqlc.arg(off);

-- name: AnySyncLog :one
SELECT EXISTS (SELECT 1 FROM hr.timesheet_sync_logs)::bool;

-- name: GetSyncLog :one
SELECT * FROM hr.timesheet_sync_logs WHERE id = sqlc.arg(id);

-- ═════════════ Đọc cho phân hệ khác (Lương, KPI, Tổng quan) ═════════════

-- name: RangeTimesheets :many
-- Ca dạy của nhiều người trong khoảng; only_valid = chỉ ca hợp lệ (tính lương), không thì mọi ca chưa bị từ chối
-- (with_invalid = cả ca bị từ chối).
SELECT t.*, COALESCE(c.code::text, '')::text AS class_code, COALESCE(c.name, '')::text AS class_name
FROM hr.teacher_timesheets t
LEFT JOIN academic.classes c ON c.id = t.class_id
WHERE (sqlc.arg(all_users)::bool OR t.user_id = ANY(sqlc.arg(user_ids)::uuid[]))
  AND t.teaching_date BETWEEN sqlc.arg(from_date) AND sqlc.arg(to_date)
  AND (CASE WHEN sqlc.arg(only_valid)::bool THEN t.status = 'valid' ELSE (sqlc.arg(with_invalid)::bool OR t.status <> 'invalid') END)
ORDER BY t.teaching_date, t.id;

-- name: StaffSessionsRange :many
-- Buổi dạy (chưa hủy) của nhân sự trong khoảng: nguồn KPI vắng dạy / nghỉ có phép.
SELECT s.id, s.class_id, s.date, s.start_time, c.code::text AS class_code, c.name AS class_name
FROM academic.class_sessions s
JOIN academic.classes c ON c.id = s.class_id
WHERE (s.teacher_id = sqlc.arg(user_id) OR s.foreign_teacher_id = sqlc.arg(user_id) OR s.assistant_id = sqlc.arg(user_id))
  AND s.status <> 'cancelled' AND s.date BETWEEN sqlc.arg(from_date) AND sqlc.arg(to_date)
ORDER BY s.date, s.start_time;

-- name: ChangedSince :one
-- Có ca dạy / ngày công / đơn nghỉ trong khoảng được thêm hoặc sửa sau thời điểm (kỳ lương phải tính lại).
SELECT (EXISTS (SELECT 1 FROM hr.teacher_timesheets t
            WHERE t.teaching_date BETWEEN sqlc.arg(from_date)::date AND sqlc.arg(to_date)::date AND t.updated_at > sqlc.arg(since)::timestamptz)
     OR EXISTS (SELECT 1 FROM hr.staff_attendances a
            WHERE a.work_date BETWEEN sqlc.arg(from_date)::date AND sqlc.arg(to_date)::date AND a.updated_at > sqlc.arg(since)::timestamptz)
     OR EXISTS (SELECT 1 FROM hr.staff_attendance_requests r
            WHERE r.date_from <= sqlc.arg(to_date)::date AND r.date_to >= sqlc.arg(from_date)::date AND r.updated_at > sqlc.arg(since)::timestamptz))::bool;

-- name: SetAttendancePhotos :exec
UPDATE hr.staff_attendances
SET check_in_photo_id = sqlc.narg(check_in_photo_id), check_out_photo_id = sqlc.narg(check_out_photo_id), updated_at = now()
WHERE id = sqlc.arg(id);

-- name: SessionCheckins :many
-- Ca dạy của một người theo buổi, mọi trạng thái (cổng giáo viên tự bỏ ca "không hợp lệ" ở lịch tuần).
SELECT class_session_id::uuid AS session_id, checkin_time, status FROM hr.teacher_timesheets
WHERE user_id = sqlc.arg(user_id) AND class_session_id = ANY(sqlc.arg(session_ids)::uuid[]);
