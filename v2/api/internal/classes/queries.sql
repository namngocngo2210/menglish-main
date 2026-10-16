-- ───────────────────────── Phạm vi ─────────────────────────

-- name: VisibleClassIDs :many
-- Lớp người dùng được xem khi không ở mức "Toàn hệ thống" (ClassModel::visibleTo): lớp mình là GV / trợ giảng / GVNN
-- hoặc có buổi dạy (kể cả dạy thay), lớp thuộc chi nhánh mình (mức Chi nhánh), lớp / chi nhánh được cấp riêng.
SELECT c.id FROM academic.classes c
WHERE c.deleted_at IS NULL AND (
    c.teacher_id = sqlc.arg(user_id) OR c.assistant_id = sqlc.arg(user_id) OR c.foreign_teacher_id = sqlc.arg(user_id)
    OR EXISTS (SELECT 1 FROM academic.class_sessions s WHERE s.class_id = c.id
               AND (s.teacher_id = sqlc.arg(user_id) OR s.foreign_teacher_id = sqlc.arg(user_id) OR s.assistant_id = sqlc.arg(user_id)))
    OR c.branch_id = ANY (sqlc.arg(branch_ids)::uuid[])
    OR c.id = ANY (sqlc.arg(class_ids)::uuid[])
);

-- ───────────────────────── Danh sách lớp ─────────────────────────

-- name: ClassStatusCounts :many
SELECT c.status, count(*)::bigint AS total
FROM academic.classes c
WHERE c.deleted_at IS NULL
  AND (sqlc.arg(all_classes)::bool OR c.id = ANY (sqlc.arg(ids)::uuid[]))
  AND (sqlc.narg(branch_id)::uuid IS NULL OR c.branch_id = sqlc.narg(branch_id))
  AND (sqlc.narg(search)::text IS NULL OR c.name ILIKE sqlc.narg(search) OR c.code::text ILIKE sqlc.narg(search))
GROUP BY c.status;

-- name: ClassProgramCounts :many
-- Sơ đồ khối: số lớp theo chương trình (trong trạng thái đang lọc; không lọc thì bỏ lớp đã hủy).
SELECT c.program::text AS label, count(*)::bigint AS total
FROM academic.classes c
WHERE c.deleted_at IS NULL AND c.program IS NOT NULL AND c.program <> ''
  AND (sqlc.arg(all_classes)::bool OR c.id = ANY (sqlc.arg(ids)::uuid[]))
  AND (sqlc.narg(branch_id)::uuid IS NULL OR c.branch_id = sqlc.narg(branch_id))
  AND (sqlc.narg(search)::text IS NULL OR c.name ILIKE sqlc.narg(search) OR c.code::text ILIKE sqlc.narg(search))
  AND (CASE WHEN sqlc.narg(status)::text IS NULL THEN c.status <> 'cancelled' ELSE c.status = sqlc.narg(status) END)
GROUP BY c.program
ORDER BY total DESC, label;

-- name: ClassLevelCounts :many
SELECT c.level::text AS label, count(*)::bigint AS total
FROM academic.classes c
WHERE c.deleted_at IS NULL AND c.level IS NOT NULL AND c.level <> ''
  AND (sqlc.arg(all_classes)::bool OR c.id = ANY (sqlc.arg(ids)::uuid[]))
  AND (sqlc.narg(branch_id)::uuid IS NULL OR c.branch_id = sqlc.narg(branch_id))
  AND (sqlc.narg(search)::text IS NULL OR c.name ILIKE sqlc.narg(search) OR c.code::text ILIKE sqlc.narg(search))
  AND (CASE WHEN sqlc.narg(status)::text IS NULL THEN c.status <> 'cancelled' ELSE c.status = sqlc.narg(status) END)
  AND (sqlc.narg(program)::text IS NULL OR c.program = sqlc.narg(program))
GROUP BY c.level
ORDER BY total DESC, label;

-- name: ListClasses :many
-- Danh sách lớp: tiến độ thật (buổi không hủy, không tính phụ đạo), sĩ số theo danh sách lớp thật, buổi tới gần nhất.
SELECT c.id, c.code::text AS code, c.name, c.status, c.program, c.level, c.schedule_text, c.max_capacity, c.min_students,
       c.start_date, b.name AS branch_name, t.full_name AS teacher_name, f.full_name AS foreign_teacher_name,
       (SELECT count(*) FROM academic.class_sessions s WHERE s.class_id = c.id AND s.status <> 'cancelled' AND s.type <> 'support')::bigint AS total_sessions,
       (SELECT count(*) FROM academic.class_sessions s WHERE s.class_id = c.id AND s.status <> 'cancelled' AND s.type <> 'support'
            AND s.date <= sqlc.arg(today)::date)::bigint AS done_sessions,
       (SELECT count(DISTINCT r.student_id) FROM academic.class_roster r WHERE r.class_id = c.id)::bigint AS roster_count,
       count(*) OVER () AS total
FROM academic.classes c
JOIN core.branches b ON b.id = c.branch_id
LEFT JOIN iam.users t ON t.id = c.teacher_id
LEFT JOIN iam.users f ON f.id = c.foreign_teacher_id
WHERE c.deleted_at IS NULL
  AND (sqlc.arg(all_classes)::bool OR c.id = ANY (sqlc.arg(ids)::uuid[]))
  AND (sqlc.narg(branch_id)::uuid IS NULL OR c.branch_id = sqlc.narg(branch_id))
  AND (sqlc.narg(search)::text IS NULL OR c.name ILIKE sqlc.narg(search) OR c.code::text ILIKE sqlc.narg(search))
  AND (CASE WHEN sqlc.narg(status)::text IS NULL THEN c.status <> 'cancelled' ELSE c.status = sqlc.narg(status) END)
  AND (sqlc.narg(program)::text IS NULL OR c.program = sqlc.narg(program))
  AND (sqlc.narg(level)::text IS NULL OR c.level = sqlc.narg(level))
ORDER BY CASE c.status WHEN 'pending_schedule' THEN 0 WHEN 'upcoming' THEN 1 WHEN 'active' THEN 2 WHEN 'completed' THEN 3 ELSE 4 END,
         c.code
LIMIT sqlc.arg(lim) OFFSET sqlc.arg(off);

-- name: NextSessionDates :many
-- Buổi tới gần nhất (không hủy, không tính phụ đạo) của các lớp.
SELECT s.class_id, min(s.date)::date AS next_date
FROM academic.class_sessions s
WHERE s.class_id = ANY (sqlc.arg(class_ids)::uuid[]) AND s.status <> 'cancelled' AND s.type <> 'support'
  AND s.date >= sqlc.arg(today)::date
GROUP BY s.class_id;

-- name: ShortClasses :one
-- Lớp chưa khai giảng (Chờ lịch / Sắp khai giảng) còn thiếu học viên so với ngưỡng khai giảng.
SELECT count(*)::bigint FROM academic.classes c
WHERE c.deleted_at IS NULL AND c.status IN ('pending_schedule', 'upcoming')
  AND (sqlc.arg(all_classes)::bool OR c.id = ANY (sqlc.arg(ids)::uuid[]))
  AND (sqlc.narg(branch_id)::uuid IS NULL OR c.branch_id = sqlc.narg(branch_id))
  AND (sqlc.narg(search)::text IS NULL OR c.name ILIKE sqlc.narg(search) OR c.code::text ILIKE sqlc.narg(search))
  AND (SELECT count(DISTINCT r.student_id) FROM academic.class_roster r WHERE r.class_id = c.id) < c.min_students;

-- name: MissingAttendanceToday :one
-- Buổi hôm nay (chưa hủy) chưa điểm danh của các lớp trong phạm vi; phụ đạo đã hoàn thành coi như xong.
SELECT count(*)::bigint FROM academic.class_sessions s
JOIN academic.classes c ON c.id = s.class_id
WHERE s.date = sqlc.arg(today)::date AND s.status <> 'cancelled'
  AND NOT (s.type = 'support' AND s.status = 'completed')
  AND NOT EXISTS (SELECT 1 FROM academic.student_attendance a WHERE a.session_id = s.id)
  AND (sqlc.arg(all_classes)::bool OR c.id = ANY (sqlc.arg(ids)::uuid[]))
  AND (sqlc.narg(branch_id)::uuid IS NULL OR s.branch_id = sqlc.narg(branch_id));

-- name: ActiveBranches :many
SELECT id, code::text AS code, name FROM core.branches WHERE is_active ORDER BY name;

-- name: ActiveCourses :many
SELECT id, name, tuition_fee FROM academic.courses WHERE is_active AND deleted_at IS NULL ORDER BY name, id;

-- name: ActiveLevels :many
SELECT code::text AS code, name, target FROM academic.course_levels WHERE is_active AND deleted_at IS NULL
ORDER BY sort_order, level_group, code;

-- ───────────────────────── Một lớp ─────────────────────────

-- name: GetClass :one
SELECT c.*, b.name AS branch_name, co.name AS course_name,
       t.full_name AS teacher_name, a.full_name AS assistant_name, f.full_name AS foreign_teacher_name,
       EXISTS (SELECT 1 FROM academic.class_slots sl WHERE sl.class_id = c.id) AS has_slots,
       (SELECT count(DISTINCT r.student_id) FROM academic.class_roster r WHERE r.class_id = c.id)::bigint AS roster_count
FROM academic.classes c
JOIN core.branches b ON b.id = c.branch_id
LEFT JOIN academic.courses co ON co.id = c.course_id
LEFT JOIN iam.users t ON t.id = c.teacher_id
LEFT JOIN iam.users a ON a.id = c.assistant_id
LEFT JOIN iam.users f ON f.id = c.foreign_teacher_id
WHERE c.id = $1 AND c.deleted_at IS NULL;

-- name: LockClass :one
SELECT * FROM academic.classes WHERE id = $1 AND deleted_at IS NULL FOR UPDATE;

-- name: ClassCodeTaken :one
SELECT EXISTS (SELECT 1 FROM academic.classes WHERE code = sqlc.arg(code) AND deleted_at IS NULL AND id <> sqlc.arg(except_id));

-- name: BranchExists :one
SELECT EXISTS (SELECT 1 FROM core.branches WHERE id = $1);

-- name: CourseIDByName :one
SELECT id FROM academic.courses WHERE name = $1 AND deleted_at IS NULL ORDER BY created_at LIMIT 1;

-- name: GetRoom :one
SELECT id, branch_id, name::text AS name, capacity FROM core.rooms WHERE id = $1 AND deleted_at IS NULL;

-- name: CreateClass :one
INSERT INTO academic.classes (
    code, name, branch_id, course_id, program, level, teacher_id, foreign_teacher_id, room_id, room,
    max_capacity, min_students, tuition_fee, notes, status, start_date, end_date, schedule_text, created_by
) VALUES (
    sqlc.arg(code), sqlc.arg(name), sqlc.arg(branch_id), sqlc.narg(course_id), sqlc.narg(program), sqlc.narg(level),
    sqlc.narg(teacher_id), sqlc.narg(foreign_teacher_id), sqlc.narg(room_id), sqlc.narg(room), sqlc.arg(max_capacity),
    sqlc.arg(min_students), sqlc.narg(tuition_fee), sqlc.narg(notes), sqlc.arg(status), sqlc.narg(start_date),
    sqlc.narg(end_date), sqlc.narg(schedule_text), sqlc.narg(created_by)
)
RETURNING id;

-- name: UpdateClass :exec
UPDATE academic.classes SET
    code = sqlc.arg(code), name = sqlc.arg(name), branch_id = sqlc.arg(branch_id), course_id = sqlc.narg(course_id),
    program = sqlc.narg(program), level = sqlc.narg(level), max_capacity = sqlc.arg(max_capacity),
    min_students = sqlc.arg(min_students), room_id = sqlc.narg(room_id), room = sqlc.narg(room),
    teacher_id = sqlc.narg(teacher_id), assistant_id = sqlc.narg(assistant_id), foreign_teacher_id = sqlc.narg(foreign_teacher_id),
    tuition_fee = sqlc.narg(tuition_fee), notes = sqlc.narg(notes), status = sqlc.arg(status),
    start_date = sqlc.narg(start_date), end_date = sqlc.narg(end_date), schedule_text = sqlc.narg(schedule_text),
    updated_at = now(), version = version + 1
WHERE id = sqlc.arg(id);

-- name: SetClassForeignTeacher :exec
UPDATE academic.classes SET foreign_teacher_id = sqlc.narg(foreign_teacher_id), updated_at = now(), version = version + 1
WHERE id = sqlc.arg(id);

-- name: SetClassStatusRoom :exec
UPDATE academic.classes SET status = sqlc.arg(status), room_id = sqlc.narg(room_id), room = sqlc.narg(room),
    updated_at = now(), version = version + 1
WHERE id = sqlc.arg(id);

-- name: SetClassSchedule :exec
UPDATE academic.classes SET start_date = sqlc.narg(start_date), end_date = sqlc.narg(end_date),
    schedule_text = sqlc.narg(schedule_text), academic_year = sqlc.narg(academic_year), status = sqlc.arg(status),
    room_id = sqlc.narg(room_id), room = sqlc.narg(room), updated_at = now(), version = version + 1
WHERE id = sqlc.arg(id);

-- name: SetClassEndDate :exec
UPDATE academic.classes SET end_date = sqlc.narg(end_date), updated_at = now() WHERE id = sqlc.arg(id);

-- name: SoftDeleteClass :exec
UPDATE academic.classes SET deleted_at = now(), updated_at = now() WHERE id = $1 AND deleted_at IS NULL;

-- name: ActiveEnrollmentCount :one
SELECT count(*)::bigint FROM academic.enrollments WHERE class_id = $1 AND status IN ('pending', 'completed') AND left_at IS NULL;

-- name: UpcomingScheduledCount :one
SELECT count(*)::bigint FROM academic.class_sessions
WHERE class_id = sqlc.arg(class_id) AND status = 'scheduled' AND date >= sqlc.arg(today)::date;

-- name: SessionProgress :one
SELECT count(*)::bigint AS total,
       count(*) FILTER (WHERE date <= sqlc.arg(today)::date)::bigint AS done
FROM academic.class_sessions
WHERE class_id = sqlc.arg(class_id) AND status <> 'cancelled' AND type <> 'support';

-- name: NextSession :one
SELECT s.date, to_char(s.start_time, 'HH24:MI') AS start_time, to_char(s.end_time, 'HH24:MI') AS end_time, s.room,
       t.full_name AS teacher_name
FROM academic.class_sessions s
LEFT JOIN iam.users t ON t.id = s.teacher_id
WHERE s.class_id = sqlc.arg(class_id) AND s.status <> 'cancelled' AND s.type <> 'support' AND s.date >= sqlc.arg(today)::date
ORDER BY s.date, s.start_time
LIMIT 1;

-- name: UpcomingForeignTeachers :many
SELECT DISTINCT u.full_name
FROM academic.class_sessions s
JOIN iam.users u ON u.id = s.foreign_teacher_id
WHERE s.class_id = sqlc.arg(class_id) AND s.status <> 'cancelled' AND s.type <> 'support' AND s.date >= sqlc.arg(today)::date
ORDER BY u.full_name;

-- name: RosterStudents :many
SELECT st.id, st.code::text AS code, st.full_name, st.dob, st.target, st.address, st.parent_name, st.phone, st.notes,
       st.school, st.status, bool_or(r.is_main) AS is_main
FROM academic.class_roster r
JOIN academic.students st ON st.id = r.student_id
WHERE r.class_id = $1
GROUP BY st.id
ORDER BY st.full_name, st.id;

-- name: ListSlots :many
SELECT class_id, position, weekday, to_char(start_time, 'HH24:MI') AS start_time, to_char(end_time, 'HH24:MI') AS end_time, shift_name
FROM academic.class_slots
WHERE class_id = ANY (sqlc.arg(class_ids)::uuid[])
ORDER BY class_id, position;

-- name: DeleteSlots :exec
DELETE FROM academic.class_slots WHERE class_id = $1;

-- name: InsertSlot :exec
INSERT INTO academic.class_slots (class_id, position, weekday, start_time, end_time, shift_name)
VALUES (sqlc.arg(class_id), sqlc.arg(position), sqlc.arg(weekday), (sqlc.arg(start_time)::text)::time, (sqlc.arg(end_time)::text)::time, sqlc.arg(shift_name));

-- ───────────────────────── Buổi học ─────────────────────────

-- name: ClassSessions :many
-- Mọi buổi của lớp kèm nhân sự, ngày nghỉ, số điểm danh; staff_syncable: buổi sắp tới chưa có dữ liệu thực tế
-- (đổi được nhân sự / phòng).
SELECT s.id, s.date, to_char(s.start_time, 'HH24:MI') AS start_time, to_char(s.end_time, 'HH24:MI') AS end_time,
       s.type, s.status, s.room, s.shift_name, s.teacher_id, s.foreign_teacher_id, s.assistant_id, s.holiday_id,
       t.full_name AS teacher_name, f.full_name AS foreign_teacher_name, a.full_name AS assistant_name, h.name AS holiday_name,
       (SELECT count(*) FROM academic.student_attendance sa WHERE sa.session_id = s.id)::bigint AS attendance_count,
       (s.type IN ('regular', 'makeup') AND s.status = 'scheduled' AND s.date >= sqlc.arg(today)::date
        AND NOT EXISTS (SELECT 1 FROM academic.student_attendance sa WHERE sa.session_id = s.id))::bool AS staff_syncable
FROM academic.class_sessions s
LEFT JOIN iam.users t ON t.id = s.teacher_id
LEFT JOIN iam.users f ON f.id = s.foreign_teacher_id
LEFT JOIN iam.users a ON a.id = s.assistant_id
LEFT JOIN core.holidays h ON h.id = s.holiday_id
WHERE s.class_id = sqlc.arg(class_id)
ORDER BY s.date, s.start_time, s.id;

-- name: StaffSyncableSessions :many
-- Buổi chính khóa / học bù chưa diễn ra, chưa điểm danh (ClassSession::staffSyncable).
-- TODO(hr): bỏ buổi đã chấm công giờ dạy (hr.timesheets) và buổi gắn phụ đạo khi các bảng đó có.
SELECT s.id, s.date, to_char(s.start_time, 'HH24:MI') AS start_time, to_char(s.end_time, 'HH24:MI') AS end_time,
       s.room, s.teacher_id, s.foreign_teacher_id, s.assistant_id
FROM academic.class_sessions s
WHERE s.class_id = sqlc.arg(class_id) AND s.type IN ('regular', 'makeup') AND s.status = 'scheduled'
  AND s.date >= sqlc.arg(today)::date
  AND NOT EXISTS (SELECT 1 FROM academic.student_attendance sa WHERE sa.session_id = s.id)
ORDER BY s.date, s.start_time;

-- name: ReplaceableSessionIDs :many
-- Buổi chính khóa chưa diễn ra, chưa có dữ liệu thực tế: xóa được khi xếp lại TKB (ClassSession::replaceable).
-- TODO(hr): thêm điều kiện chưa chấm công / không gắn phụ đạo; TODO(crm): không có khách hẹn học thử.
SELECT s.id FROM academic.class_sessions s
WHERE s.class_id = sqlc.arg(class_id) AND s.type = 'regular' AND s.status = 'scheduled' AND s.date >= sqlc.arg(today)::date
  AND NOT EXISTS (SELECT 1 FROM academic.student_attendance sa WHERE sa.session_id = s.id);

-- name: KeptSessionsFrom :many
-- Buổi được giữ khi xếp lại TKB: không phải phụ đạo, chưa hủy, từ ngày xếp, không nằm trong danh sách sẽ xóa.
SELECT s.date, to_char(s.start_time, 'HH24:MI') AS start_time, to_char(s.end_time, 'HH24:MI') AS end_time, s.shift_name
FROM academic.class_sessions s
WHERE s.class_id = sqlc.arg(class_id) AND s.type <> 'support' AND s.status <> 'cancelled' AND s.date >= sqlc.arg(from_date)::date
  AND NOT (s.id = ANY (sqlc.arg(exclude)::uuid[]));

-- name: DeleteSessions :exec
DELETE FROM academic.class_sessions WHERE id = ANY (sqlc.arg(ids)::uuid[]);

-- name: InsertSession :one
INSERT INTO academic.class_sessions (
    class_id, branch_id, date, shift_name, type, start_time, end_time, room, teacher_id, foreign_teacher_id, assistant_id,
    status, notes, rescheduled_from_id, created_by
) VALUES (
    sqlc.arg(class_id), sqlc.arg(branch_id), sqlc.arg(date), sqlc.narg(shift_name), sqlc.arg(type),
    (sqlc.arg(start_time)::text)::time, (sqlc.arg(end_time)::text)::time, sqlc.narg(room), sqlc.narg(teacher_id),
    sqlc.narg(foreign_teacher_id), sqlc.narg(assistant_id), 'scheduled', sqlc.narg(notes), sqlc.narg(rescheduled_from_id),
    sqlc.narg(created_by)
)
RETURNING id;

-- name: SetSessionsForeignTeacher :exec
UPDATE academic.class_sessions SET foreign_teacher_id = sqlc.narg(foreign_teacher_id), updated_at = now()
WHERE id = ANY (sqlc.arg(ids)::uuid[]);

-- name: SetSessionsTeacher :exec
-- Lớp không có GV chính thì người đứng lớp của buổi là GVNN của chính buổi đó.
UPDATE academic.class_sessions SET teacher_id = coalesce(sqlc.narg(teacher_id)::uuid, foreign_teacher_id), updated_at = now()
WHERE id = ANY (sqlc.arg(ids)::uuid[]);

-- name: SetSessionsAssistant :exec
UPDATE academic.class_sessions SET assistant_id = sqlc.narg(assistant_id), updated_at = now()
WHERE id = ANY (sqlc.arg(ids)::uuid[]);

-- name: SetSessionsBranch :exec
UPDATE academic.class_sessions SET branch_id = sqlc.arg(branch_id), updated_at = now() WHERE id = ANY (sqlc.arg(ids)::uuid[]);

-- name: SetSessionsRoom :exec
-- Chỉ đổi buổi chưa có phòng riêng hoặc đang dùng phòng cũ của lớp.
UPDATE academic.class_sessions SET room = sqlc.narg(room), updated_at = now()
WHERE id = ANY (sqlc.arg(ids)::uuid[]) AND (room IS NULL OR room = sqlc.narg(previous_room));

-- name: ConflictCandidates :many
-- Buổi (chưa hủy) của lớp khác trong khoảng ngày trùng phòng cùng chi nhánh hoặc trùng nhân sự (SessionScheduleService::findConflict).
SELECT s.id, s.date, to_char(s.start_time, 'HH24:MI') AS start_time, to_char(s.end_time, 'HH24:MI') AS end_time,
       s.branch_id, s.room, s.teacher_id, s.foreign_teacher_id, s.assistant_id, c.name AS class_name, c.code::text AS class_code
FROM academic.class_sessions s
JOIN academic.classes c ON c.id = s.class_id
WHERE s.status <> 'cancelled'
  AND (sqlc.narg(exclude_class)::uuid IS NULL OR s.class_id <> sqlc.narg(exclude_class))
  AND s.date BETWEEN sqlc.arg(from_date)::date AND sqlc.arg(to_date)::date
  AND ((s.branch_id = sqlc.arg(branch_id) AND s.room = ANY (sqlc.arg(rooms)::text[]))
       OR s.teacher_id = ANY (sqlc.arg(staff)::uuid[]) OR s.foreign_teacher_id = ANY (sqlc.arg(staff)::uuid[])
       OR s.assistant_id = ANY (sqlc.arg(staff)::uuid[]))
ORDER BY s.date, s.start_time;

-- name: OverlappingSessions :many
-- Buổi (chưa hủy) chồng giờ trong ngày (kiểm tra phòng / nhân sự còn trống).
SELECT s.branch_id, s.room, s.teacher_id, s.foreign_teacher_id, s.assistant_id
FROM academic.class_sessions s
WHERE s.status <> 'cancelled' AND s.date = sqlc.arg(date)::date
  AND (sqlc.narg(exclude_class)::uuid IS NULL OR s.class_id <> sqlc.narg(exclude_class))
  AND s.start_time < (sqlc.arg(end_time)::text)::time AND s.end_time > (sqlc.arg(start_time)::text)::time;

-- name: OwnOverlap :one
SELECT EXISTS (
    SELECT 1 FROM academic.class_sessions
    WHERE class_id = sqlc.arg(class_id) AND status <> 'cancelled' AND date = sqlc.arg(date)::date
      AND start_time < (sqlc.arg(end_time)::text)::time AND end_time > (sqlc.arg(start_time)::text)::time
);

-- name: GetSession :one
SELECT s.id, s.class_id, s.branch_id, s.date, to_char(s.start_time, 'HH24:MI') AS start_time, to_char(s.end_time, 'HH24:MI') AS end_time,
       s.type, s.status, s.room, s.teacher_id, s.foreign_teacher_id, s.assistant_id, s.holiday_id,
       t.full_name AS teacher_name
FROM academic.class_sessions s
LEFT JOIN iam.users t ON t.id = s.teacher_id
WHERE s.id = $1;

-- name: SessionsOnDate :many
SELECT s.id, s.type, s.teacher_id, s.foreign_teacher_id, s.assistant_id
FROM academic.class_sessions s
WHERE s.class_id = sqlc.arg(class_id) AND s.date = sqlc.arg(date)::date AND s.status <> 'cancelled'
ORDER BY s.start_time, s.id;

-- name: RecentSessions :many
-- Buổi của lớp trong khoảng ngày (ô chọn buổi điểm danh / điểm danh bù), mới nhất trước.
SELECT s.id, s.date, to_char(s.start_time, 'HH24:MI') AS start_time, to_char(s.end_time, 'HH24:MI') AS end_time,
       s.type, s.status,
       (SELECT count(*) FROM academic.student_attendance sa WHERE sa.session_id = s.id)::bigint AS attendance_count
FROM academic.class_sessions s
WHERE s.class_id = sqlc.arg(class_id) AND s.date BETWEEN sqlc.arg(from_date)::date AND sqlc.arg(to_date)::date
ORDER BY s.date DESC, s.start_time DESC;

-- name: SetSessionCompleted :exec
UPDATE academic.class_sessions SET status = 'completed', updated_at = now() WHERE id = $1;

-- name: LastSessionDate :one
SELECT date FROM academic.class_sessions
WHERE class_id = $1 AND type <> 'support' AND status <> 'cancelled'
ORDER BY date DESC LIMIT 1;

-- name: LastRegularOrMakeupDate :one
SELECT date FROM academic.class_sessions
WHERE class_id = $1 AND type IN ('regular', 'makeup') AND status <> 'cancelled'
ORDER BY date DESC LIMIT 1;

-- name: RegularSessionSlots :many
-- Ca suy ra từ các buổi chính khóa đã sinh (lớp chưa có cấu hình TKB).
SELECT DISTINCT extract(isodow FROM date)::int AS weekday, to_char(start_time, 'HH24:MI') AS start_time,
       to_char(end_time, 'HH24:MI') AS end_time, coalesce(shift_name, '')::text AS shift_name
FROM academic.class_sessions
WHERE class_id = $1 AND type = 'regular';

-- ───────────────────────── Ngày nghỉ lễ ─────────────────────────

-- name: HolidayAffectedSessions :many
-- Buổi sắp tới (chưa hủy) của chi nhánh bị ảnh hưởng trong khoảng ngày nghỉ (HolidayRescheduleService::affectedQuery).
SELECT s.id, s.class_id, s.branch_id, s.date, to_char(s.start_time, 'HH24:MI') AS start_time, to_char(s.end_time, 'HH24:MI') AS end_time,
       s.shift_name, s.room, s.teacher_id, s.foreign_teacher_id, s.assistant_id, s.notes,
       EXISTS (SELECT 1 FROM academic.student_attendance sa WHERE sa.session_id = s.id) AS has_data
FROM academic.class_sessions s
WHERE s.status = 'scheduled' AND s.type IN ('regular', 'makeup') AND s.holiday_id IS NULL
  AND s.date BETWEEN sqlc.arg(from_date)::date AND sqlc.arg(to_date)::date
  AND (sqlc.arg(system_wide)::bool OR s.branch_id = ANY (sqlc.arg(branch_ids)::uuid[]))
ORDER BY s.class_id, s.date, s.start_time;

-- name: CancelSessionForHoliday :exec
UPDATE academic.class_sessions SET status = 'cancelled', holiday_id = sqlc.arg(holiday_id), notes = sqlc.narg(notes), updated_at = now()
WHERE id = sqlc.arg(id);

-- name: HolidayCancelledSessions :many
SELECT s.id, s.class_id, s.branch_id, s.date, s.notes,
       m.id AS makeup_id, m.date AS makeup_date, m.status AS makeup_status,
       EXISTS (SELECT 1 FROM academic.student_attendance sa WHERE sa.session_id = m.id) AS makeup_has_data
FROM academic.class_sessions s
LEFT JOIN academic.class_sessions m ON m.rescheduled_from_id = s.id
WHERE s.holiday_id = $1 AND s.status = 'cancelled';

-- name: RestoreSession :exec
UPDATE academic.class_sessions SET status = 'scheduled', holiday_id = NULL, notes = sqlc.narg(notes), updated_at = now()
WHERE id = sqlc.arg(id);

-- name: ClassForMakeup :one
SELECT id, status, branch_id, end_date FROM academic.classes WHERE id = $1 AND deleted_at IS NULL;

-- name: HolidaySessionsUpcoming :many
-- Buổi sắp tới bị hủy do ngày nghỉ lễ (kèm buổi học bù) của các lớp trong phạm vi.
SELECT s.id, c.name AS class_name, c.code::text AS class_code, s.date, to_char(s.start_time, 'HH24:MI') AS start_time,
       h.name AS holiday_name, m.date AS makeup_date, coalesce(to_char(m.start_time, 'HH24:MI'), '')::text AS makeup_start
FROM academic.class_sessions s
JOIN academic.classes c ON c.id = s.class_id
LEFT JOIN core.holidays h ON h.id = s.holiday_id
LEFT JOIN academic.class_sessions m ON m.rescheduled_from_id = s.id
WHERE s.holiday_id IS NOT NULL AND s.status = 'cancelled' AND s.date >= sqlc.arg(today)::date
  AND (sqlc.arg(all_classes)::bool OR s.class_id = ANY (sqlc.arg(ids)::uuid[]))
ORDER BY s.date
LIMIT 50;

-- ───────────────────────── Lịch & TKB lớp ─────────────────────────

-- name: ScheduleClasses :many
-- Lớp trong phạm vi cho màn Lịch & TKB lớp (ô chọn lớp, bảng lớp hiện tại).
SELECT c.id, c.code::text AS code, c.name, c.status, c.branch_id, c.room_id, c.room, c.schedule_text, c.academic_year,
       c.start_date, c.end_date, t.full_name AS teacher_name, f.full_name AS foreign_teacher_name,
       EXISTS (SELECT 1 FROM academic.class_slots sl WHERE sl.class_id = c.id) AS has_slots
FROM academic.classes c
LEFT JOIN iam.users t ON t.id = c.teacher_id
LEFT JOIN iam.users f ON f.id = c.foreign_teacher_id
WHERE c.deleted_at IS NULL AND (sqlc.arg(all_classes)::bool OR c.id = ANY (sqlc.arg(ids)::uuid[]))
ORDER BY c.name, c.code;

-- name: UpcomingRegularSessions :many
-- Buổi chính khóa sắp tới (chưa hủy) trong N ngày: để tìm buổi lệch khung giờ ca dạy.
SELECT s.class_id, c.name AS class_name, c.code::text AS class_code, b.name AS branch_name, s.date,
       to_char(s.start_time, 'HH24:MI') AS start_time, to_char(s.end_time, 'HH24:MI') AS end_time
FROM academic.class_sessions s
JOIN academic.classes c ON c.id = s.class_id
JOIN core.branches b ON b.id = c.branch_id
WHERE s.type = 'regular' AND s.status <> 'cancelled' AND c.deleted_at IS NULL
  AND s.date BETWEEN sqlc.arg(from_date)::date AND sqlc.arg(to_date)::date
  AND (sqlc.arg(all_classes)::bool OR s.class_id = ANY (sqlc.arg(ids)::uuid[]))
ORDER BY c.name, s.date, s.start_time;

-- ───────────────────────── Phòng học ─────────────────────────

-- name: RoomOpenClasses :many
-- Lớp còn mở (chưa kết thúc / hủy) đang gắn phòng.
SELECT c.id, c.room_id, c.name, c.code::text AS code, c.status, c.start_date, c.schedule_text
FROM academic.classes c
WHERE c.deleted_at IS NULL AND c.room_id = ANY (sqlc.arg(room_ids)::uuid[]) AND c.status NOT IN ('completed', 'cancelled')
  AND (sqlc.narg(exclude)::uuid IS NULL OR c.id <> sqlc.narg(exclude))
ORDER BY c.name, c.id;

-- name: BranchRooms :many
SELECT r.id, r.branch_id, r.name::text AS name, r.capacity, rt.name::text AS type_name
FROM core.rooms r
JOIN core.room_types rt ON rt.id = r.room_type_id
WHERE r.deleted_at IS NULL AND r.branch_id = ANY (sqlc.arg(branch_ids)::uuid[])
ORDER BY r.name;

-- name: DetachRoomFromClass :exec
UPDATE academic.classes SET room_id = NULL, room = NULL, updated_at = now() WHERE id = $1;

-- name: ClearSessionRoom :exec
-- Buổi sắp tới (chưa có dữ liệu thực tế) đang dùng phòng: bỏ phòng.
UPDATE academic.class_sessions s SET room = NULL, updated_at = now()
WHERE s.class_id = sqlc.arg(class_id) AND s.room = sqlc.arg(room) AND s.type IN ('regular', 'makeup') AND s.status = 'scheduled'
  AND s.date >= sqlc.arg(today)::date
  AND NOT EXISTS (SELECT 1 FROM academic.student_attendance sa WHERE sa.session_id = s.id);

-- name: RenameRoomOnClasses :exec
UPDATE academic.classes SET room = sqlc.arg(name), updated_at = now() WHERE room_id = sqlc.arg(room_id);

-- name: RenameRoomOnSessions :exec
UPDATE academic.class_sessions s SET room = sqlc.arg(new_name), updated_at = now()
WHERE s.branch_id = sqlc.arg(branch_id) AND s.room = sqlc.arg(old_name) AND s.type IN ('regular', 'makeup') AND s.status = 'scheduled'
  AND s.date >= sqlc.arg(today)::date
  AND NOT EXISTS (SELECT 1 FROM academic.student_attendance sa WHERE sa.session_id = s.id);

-- ───────────────────────── Danh mục dùng lớp ─────────────────────────

-- name: CourseClassCounts :many
SELECT course_id, count(*)::bigint AS total FROM academic.classes
WHERE deleted_at IS NULL AND course_id = ANY (sqlc.arg(course_ids)::uuid[])
GROUP BY course_id;

-- name: LevelClassCounts :many
SELECT level::text AS level, count(*)::bigint AS total FROM academic.classes
WHERE deleted_at IS NULL AND level = ANY (sqlc.arg(codes)::text[])
GROUP BY level;

-- name: LevelStudentCounts :many
-- Học viên (chưa thôi học) có lớp chính dùng mã trình độ.
SELECT c.level::text AS level, count(*)::bigint AS total
FROM academic.students s
JOIN academic.classes c ON c.id = s.current_class_id
WHERE s.deleted_at IS NULL AND c.deleted_at IS NULL AND s.status <> 'dropped' AND c.level = ANY (sqlc.arg(codes)::text[])
GROUP BY c.level;

-- name: ClassOptions :many
-- Lớp (chưa xóa) trong phạm vi cho ô chọn lớp ở module khác.
SELECT c.id, c.name, c.code::text AS code, c.branch_id
FROM academic.classes c
WHERE c.deleted_at IS NULL AND (sqlc.arg(all_classes)::bool OR c.id = ANY (sqlc.arg(ids)::uuid[]))
ORDER BY c.name, c.code;

-- name: ClassExists :one
SELECT EXISTS (SELECT 1 FROM academic.classes WHERE id = $1 AND deleted_at IS NULL);

-- name: ClassStartInfo :one
SELECT status, start_date FROM academic.classes WHERE id = $1;

-- ───────────────────────── Nhân sự ─────────────────────────

-- name: UsersWithPermission :many
-- Nhân sự đang hoạt động có quyền (đối tượng) perm: theo vai trò, trừ khi bị chặn riêng; hoặc được cấp riêng.
SELECT u.id, u.full_name, coalesce(u.code::text, '')::text AS code, coalesce(u.email::text, '')::text AS email
FROM iam.users u
WHERE u.status = 'active' AND u.kind = 'staff' AND (
    EXISTS (SELECT 1 FROM iam.permission_overrides o WHERE o.user_id = u.id AND o.permission = sqlc.arg(perm) AND o.scope_type = 'all' AND o.allow)
    OR (EXISTS (SELECT 1 FROM iam.user_roles ur JOIN iam.role_permissions rp ON rp.role = ur.role
                WHERE ur.user_id = u.id AND rp.permission = sqlc.arg(perm))
        AND NOT EXISTS (SELECT 1 FROM iam.permission_overrides o WHERE o.user_id = u.id AND o.permission = sqlc.arg(perm)
                        AND o.scope_type = 'all' AND NOT o.allow))
)
ORDER BY u.full_name, u.id;

-- name: UserNames :many
SELECT id, full_name FROM iam.users WHERE id = ANY (sqlc.arg(ids)::uuid[]);

-- ───────────────────────── Xếp lớp ─────────────────────────

-- name: LockStudent :one
SELECT id, code::text AS code, full_name, branch_id, current_class_id, status
FROM academic.students WHERE id = $1 AND deleted_at IS NULL FOR UPDATE;

-- name: StudentInScope :one
-- Học viên (chưa xóa) trong phạm vi Học viên của người dùng (Student::visibleTo).
SELECT EXISTS (
    SELECT 1 FROM academic.students st
    WHERE st.id = sqlc.arg(id) AND st.deleted_at IS NULL
      AND (NOT sqlc.arg(limit_branches)::bool OR st.branch_id = ANY (sqlc.arg(branch_ids)::uuid[])
       OR (st.branch_id IS NULL AND EXISTS (SELECT 1 FROM academic.classes cc
                                            WHERE cc.id = st.current_class_id AND cc.branch_id = ANY (sqlc.arg(branch_ids)::uuid[]))))
      AND (NOT sqlc.arg(limit_classes)::bool OR st.current_class_id = ANY (sqlc.arg(class_ids)::uuid[])
       OR EXISTS (SELECT 1 FROM academic.enrollments ee WHERE ee.student_id = st.id AND ee.class_id = ANY (sqlc.arg(class_ids)::uuid[])
                  AND ee.status IN ('pending', 'completed')))
);

-- name: StartStudyingDue :execrows
-- Học viên Chờ khai giảng có lượt xếp lớp đã bàn giao xong ở lớp đã khai giảng → Đang học (students:start-studying).
UPDATE academic.students st SET status = 'studying', updated_at = now(), version = version + 1
WHERE st.status = 'waiting_start' AND st.deleted_at IS NULL AND EXISTS (
    SELECT 1 FROM academic.enrollments e JOIN academic.classes c ON c.id = e.class_id
    WHERE e.student_id = st.id AND e.status = 'completed' AND c.status = 'active' AND c.deleted_at IS NULL
      AND (c.start_date IS NULL OR c.start_date <= sqlc.arg(today)::date));

-- name: HasActiveEnrollment :one
SELECT EXISTS (SELECT 1 FROM academic.enrollments
               WHERE student_id = sqlc.arg(student_id) AND class_id = sqlc.arg(class_id)
                 AND status IN ('pending', 'completed') AND left_at IS NULL);

-- name: CreateEnrollment :one
INSERT INTO academic.enrollments (student_id, class_id, enrolled_at, status, created_by)
VALUES (sqlc.arg(student_id), sqlc.arg(class_id), sqlc.arg(enrolled_at), 'pending', sqlc.narg(created_by))
RETURNING id;

-- name: CloseEnrollments :exec
-- Chuyển lớp chính: lượt xếp lớp ở lớp chính cũ hết hiệu lực từ hôm nay.
UPDATE academic.enrollments SET left_at = greatest(enrolled_at, sqlc.arg(today)::date), updated_at = now()
WHERE student_id = sqlc.arg(student_id) AND class_id = sqlc.arg(class_id) AND left_at IS NULL AND status <> 'dropped';

-- name: SetStudentClass :exec
UPDATE academic.students SET current_class_id = sqlc.narg(class_id), updated_at = now(), version = version + 1
WHERE id = sqlc.arg(id);

-- name: DropEnrollments :exec
-- Thôi học: đóng các lượt xếp lớp còn hiệu lực.
UPDATE academic.enrollments SET status = 'dropped', left_at = coalesce(left_at, greatest(enrolled_at, sqlc.arg(today)::date)), updated_at = now()
WHERE student_id = sqlc.arg(student_id) AND status IN ('pending', 'completed');

-- name: ListEnrollments :many
SELECT e.id, e.student_id, e.class_id, e.enrolled_at, e.left_at, e.curriculum_delivered, e.zalo_group_added, e.status,
       e.lead_id, e.confirmed_at, st.full_name AS student_name, st.code::text AS student_code, c.name AS class_name,
       c.code::text AS class_code, t.full_name AS teacher_name, b.name AS branch_name, count(*) OVER () AS total
FROM academic.enrollments e
JOIN academic.students st ON st.id = e.student_id
JOIN academic.classes c ON c.id = e.class_id
JOIN core.branches b ON b.id = c.branch_id
LEFT JOIN iam.users t ON t.id = c.teacher_id
WHERE st.deleted_at IS NULL
  AND (sqlc.narg(student_id)::uuid IS NULL OR e.student_id = sqlc.narg(student_id))
  AND (sqlc.narg(class_id)::uuid IS NULL OR e.class_id = sqlc.narg(class_id))
  AND (NOT sqlc.arg(limit_branches)::bool OR st.branch_id = ANY (sqlc.arg(branch_ids)::uuid[])
       OR (st.branch_id IS NULL AND EXISTS (SELECT 1 FROM academic.classes cc
                                            WHERE cc.id = st.current_class_id AND cc.branch_id = ANY (sqlc.arg(branch_ids)::uuid[]))))
  AND (NOT sqlc.arg(limit_classes)::bool OR st.current_class_id = ANY (sqlc.arg(class_ids)::uuid[])
       OR EXISTS (SELECT 1 FROM academic.enrollments ee WHERE ee.student_id = st.id AND ee.class_id = ANY (sqlc.arg(class_ids)::uuid[])
                  AND ee.status IN ('pending', 'completed')))
ORDER BY e.created_at DESC, e.id DESC
LIMIT sqlc.arg(lim) OFFSET sqlc.arg(off);

-- name: GetEnrollment :one
SELECT e.id, e.student_id, e.class_id, e.status, e.curriculum_delivered, e.zalo_group_added,
       st.branch_id AS student_branch_id, st.current_class_id, st.status AS student_status, c.branch_id AS class_branch_id
FROM academic.enrollments e
JOIN academic.students st ON st.id = e.student_id AND st.deleted_at IS NULL
JOIN academic.classes c ON c.id = e.class_id
WHERE e.id = $1;

-- name: UpdateHandoff :exec
UPDATE academic.enrollments SET curriculum_delivered = sqlc.arg(curriculum_delivered), zalo_group_added = sqlc.arg(zalo_group_added),
    status = sqlc.arg(status), updated_at = now()
WHERE id = sqlc.arg(id);

-- name: StartStudying :exec
-- Bàn giao xong vào lớp đã khai giảng → học viên Chờ khai giảng sang Đang học (ClassStartActivation).
UPDATE academic.students SET status = 'studying', updated_at = now(), version = version + 1
WHERE id = $1 AND status = 'waiting_start';

-- name: EnrollableStudents :many
-- Học viên (chưa thôi học) trong phạm vi cho ô chọn ở màn Tiếp nhận & Xếp lớp.
SELECT st.id, st.code::text AS code, st.full_name, st.branch_id, b.name AS branch_name
FROM academic.students st
LEFT JOIN core.branches b ON b.id = st.branch_id
WHERE st.deleted_at IS NULL AND st.status <> 'dropped'
  AND (NOT sqlc.arg(limit_branches)::bool OR st.branch_id = ANY (sqlc.arg(branch_ids)::uuid[])
       OR (st.branch_id IS NULL AND EXISTS (SELECT 1 FROM academic.classes cc
                                            WHERE cc.id = st.current_class_id AND cc.branch_id = ANY (sqlc.arg(branch_ids)::uuid[]))))
  AND (NOT sqlc.arg(limit_classes)::bool OR st.current_class_id = ANY (sqlc.arg(class_ids)::uuid[])
       OR EXISTS (SELECT 1 FROM academic.enrollments ee WHERE ee.student_id = st.id AND ee.class_id = ANY (sqlc.arg(class_ids)::uuid[])
                  AND ee.status IN ('pending', 'completed')))
ORDER BY st.full_name, st.id;

-- name: EnrollableClasses :many
-- Lớp chọn được khi xếp lớp (theo phạm vi Học viên), kèm sĩ số.
SELECT c.id, c.name, c.code::text AS code, c.branch_id, c.course_id, c.max_capacity, c.status, c.start_date,
       (SELECT count(DISTINCT r.student_id) FROM academic.class_roster r WHERE r.class_id = c.id)::bigint AS roster_count
FROM academic.classes c
WHERE c.deleted_at IS NULL
  AND (sqlc.arg(all_classes)::bool OR c.id = ANY (sqlc.arg(ids)::uuid[]))
  AND (NOT sqlc.arg(limit_branches)::bool OR c.branch_id = ANY (sqlc.arg(branch_ids)::uuid[]))
ORDER BY c.name, c.code;

-- name: StudentClasses :many
-- Lớp đang học của học viên: lớp chính + lớp liên kết còn hiệu lực.
SELECT c.id, c.name, c.code::text AS code, c.status, c.schedule_text, c.branch_id,
       (c.id = st.current_class_id)::bool AS is_main
FROM academic.students st
JOIN academic.classes c ON c.deleted_at IS NULL AND (c.id = st.current_class_id OR EXISTS (
    SELECT 1 FROM academic.enrollments e WHERE e.student_id = st.id AND e.class_id = c.id
      AND e.status IN ('pending', 'completed') AND e.left_at IS NULL))
WHERE st.id = $1
ORDER BY is_main DESC, c.name;

-- ───────────────────────── Điểm danh ─────────────────────────

-- name: SessionAttendance :many
SELECT a.student_id, a.status, a.note, a.teacher_id, a.recorded_by, r.full_name AS recorder_name
FROM academic.student_attendance a
LEFT JOIN iam.users r ON r.id = a.recorded_by
WHERE a.session_id = $1;

-- name: StudentsByIDs :many
SELECT id, code::text AS code, full_name, current_class_id FROM academic.students WHERE id = ANY (sqlc.arg(ids)::uuid[]);

-- name: UpsertAttendance :exec
INSERT INTO academic.student_attendance (
    session_id, class_id, student_id, session_date, status, note, teacher_id, recorded_by, review_status
) VALUES (
    sqlc.arg(session_id), sqlc.arg(class_id), sqlc.arg(student_id), sqlc.arg(session_date), sqlc.arg(status), sqlc.narg(note),
    sqlc.narg(teacher_id), sqlc.narg(recorded_by), 'pending_review'
)
ON CONFLICT (session_id, student_id) DO UPDATE SET
    class_id = excluded.class_id, session_date = excluded.session_date, status = excluded.status, note = excluded.note,
    teacher_id = excluded.teacher_id, recorded_by = excluded.recorded_by, review_status = 'pending_review',
    reviewed_by = NULL, reviewed_at = NULL, review_note = NULL, updated_at = now();

-- name: RecordStudyStart :exec
-- Buổi có mặt / đi muộn đầu tiên = ngày bắt đầu vào học (Student::recordStudyStart): chỉ điền khi chưa có hoặc sớm hơn.
UPDATE academic.students SET study_started_on = sqlc.arg(day)::date
WHERE id = ANY (sqlc.arg(ids)::uuid[]) AND (study_started_on IS NULL OR study_started_on > sqlc.arg(day)::date);

-- ───────────────────────── Chốt & Xếp lớp (CRM) ─────────────────────────

-- name: LockClassForClosing :one
-- Lớp được chọn khi chốt / xếp lớp khách CRM (khóa dòng lớp), kèm khóa học, trình độ của lớp và sĩ số.
SELECT c.id, c.name, c.code::text AS code, c.branch_id, c.course_id, c.status, c.start_date, c.max_capacity,
       co.name AS course_name, co.is_active AS course_active, co.tuition_fee AS course_fee, co.total_lessons AS course_lessons,
       cl.id AS class_level_id, co.course_level_id,
       (SELECT count(DISTINCT r.student_id) FROM academic.class_roster r WHERE r.class_id = c.id)::bigint AS roster_count
FROM academic.classes c
LEFT JOIN academic.courses co ON co.id = c.course_id AND co.deleted_at IS NULL
LEFT JOIN academic.course_levels cl ON cl.code = c.level AND cl.deleted_at IS NULL
WHERE c.id = $1 AND c.deleted_at IS NULL
FOR UPDATE OF c;

-- name: CourseForClosing :one
SELECT co.id, co.name, co.is_active, co.tuition_fee, co.total_lessons, co.course_level_id, cl.name AS level_name
FROM academic.courses co
LEFT JOIN academic.course_levels cl ON cl.id = co.course_level_id
WHERE co.id = $1 AND co.deleted_at IS NULL;

-- name: BranchName :one
SELECT name FROM core.branches WHERE id = $1;

-- name: CreateLeadEnrollment :one
-- Lượt xếp lớp tạo từ CRM (Chốt & Xếp lớp / Gán lớp cho khách Chờ xếp lớp).
INSERT INTO academic.enrollments (student_id, class_id, lead_id, enrolled_at, status, created_by)
VALUES (sqlc.arg(student_id), sqlc.arg(class_id), sqlc.arg(lead_id), sqlc.arg(enrolled_at), 'pending', sqlc.narg(created_by))
RETURNING id;

-- name: WaitingLeadOfStudent :one
-- Khách CRM Chờ xếp lớp đã chốt ra học viên này (WaitingLeadPlacement::waitingLeadFor).
SELECT id FROM crm.leads WHERE converted_student_id = $1 AND stage = 'waiting_class' AND deleted_at IS NULL
ORDER BY converted_at DESC NULLS LAST LIMIT 1;

-- ───────────────────────── Phân hệ khác: test đầu vào / báo cáo / công việc ─────────────────────────

-- name: SessionsByIDs :many
-- Buổi học (lớp chưa xóa) cho học thử / thẻ việc trợ giảng.
SELECT s.id, s.class_id, c.name AS class_name, c.code::text AS class_code, coalesce(co.name, c.program, '')::text AS course_name,
       c.status AS class_status, s.status, s.type, s.branch_id, b.name AS branch_name, s.date, s.room, s.shift_name,
       to_char(s.start_time, 'HH24:MI') AS start_time, to_char(s.end_time, 'HH24:MI') AS end_time,
       t.full_name AS teacher_name
FROM academic.class_sessions s
JOIN academic.classes c ON c.id = s.class_id AND c.deleted_at IS NULL
JOIN core.branches b ON b.id = s.branch_id
LEFT JOIN academic.courses co ON co.id = c.course_id
LEFT JOIN iam.users t ON t.id = coalesce(s.teacher_id, s.foreign_teacher_id)
WHERE s.id = ANY (sqlc.arg(ids)::uuid[]);

-- name: TeachingSessionIDs :many
-- Buổi người dùng dạy / dạy GVNN / trợ giảng (kể cả dạy thay).
SELECT s.id FROM academic.class_sessions s
JOIN academic.classes c ON c.id = s.class_id AND c.deleted_at IS NULL
WHERE s.teacher_id = sqlc.arg(user_id) OR s.foreign_teacher_id = sqlc.arg(user_id) OR s.assistant_id = sqlc.arg(user_id);

-- name: ResponsibleClassIDs :many
-- Lớp người dùng phụ trách (GV chính, trợ giảng cố định, GVNN mặc định).
SELECT id FROM academic.classes
WHERE deleted_at IS NULL AND (teacher_id = sqlc.arg(user_id) OR assistant_id = sqlc.arg(user_id) OR foreign_teacher_id = sqlc.arg(user_id));

-- name: ReportClassOptions :many
-- Lớp gắn được vào nhật ký / báo cáo chữ: lớp người dùng thấy, chưa hủy.
SELECT c.id, c.code::text AS code, c.name
FROM academic.classes c
WHERE c.deleted_at IS NULL AND c.status <> 'cancelled' AND (sqlc.arg(all_classes)::bool OR c.id = ANY (sqlc.arg(ids)::uuid[]))
ORDER BY c.code;

-- name: ClassCodesByIDs :many
SELECT id, code::text AS code, name FROM academic.classes WHERE id = ANY (sqlc.arg(ids)::uuid[]);

-- name: ReportClassIDs :many
-- Lớp tính vào báo cáo của người viết: lớp người viết thấy, chỉ trong các chi nhánh của người viết (rỗng = không lọc).
SELECT c.id FROM academic.classes c
WHERE c.deleted_at IS NULL AND (sqlc.arg(all_classes)::bool OR c.id = ANY (sqlc.arg(ids)::uuid[]))
  AND (cardinality(sqlc.arg(branch_ids)::uuid[]) = 0 OR c.branch_id = ANY (sqlc.arg(branch_ids)::uuid[]));

-- name: ReportDaySessions :many
-- Buổi học trong ngày (không tính phụ đạo) của các lớp tính vào báo cáo ngày.
SELECT s.id, s.class_id, c.name AS class_name, c.branch_id AS class_branch_id, c.teacher_id AS class_teacher_id,
       s.status, s.type, s.room, s.shift_name, to_char(s.start_time, 'HH24:MI') AS start_time, to_char(s.end_time, 'HH24:MI') AS end_time,
       s.teacher_id, s.foreign_teacher_id, s.assistant_id, (s.holiday_id IS NOT NULL)::bool AS holiday,
       t.full_name AS teacher_name
FROM academic.class_sessions s
JOIN academic.classes c ON c.id = s.class_id
LEFT JOIN iam.users t ON t.id = s.teacher_id
WHERE s.date = sqlc.arg(day)::date AND s.type <> 'support' AND s.class_id = ANY (sqlc.arg(class_ids)::uuid[])
ORDER BY s.start_time, c.name;

-- name: SessionAttendanceIssues :many
-- Học sinh vắng / vắng có phép / đi muộn của các buổi.
SELECT a.session_id, a.status, st.full_name AS student_name
FROM academic.student_attendance a
JOIN academic.students st ON st.id = a.student_id
WHERE a.session_id = ANY (sqlc.arg(session_ids)::uuid[]) AND a.status IN ('absent', 'excused', 'late')
ORDER BY st.full_name;

-- name: ReportRangeSessions :many
-- Buổi đã / sẽ diễn ra trong khoảng (không tính phụ đạo, buổi hủy) của các lớp tính vào báo cáo tuần.
SELECT s.class_id, c.name AS class_name, s.teacher_id, s.foreign_teacher_id
FROM academic.class_sessions s
JOIN academic.classes c ON c.id = s.class_id
WHERE s.date BETWEEN sqlc.arg(from_date)::date AND sqlc.arg(to_date)::date AND s.type <> 'support' AND s.status <> 'cancelled'
  AND s.class_id = ANY (sqlc.arg(class_ids)::uuid[]);

-- name: ActiveStudentsAsOf :one
-- Học sinh đang học của các lớp tính đến ngày (lượt xếp lớp còn hiệu lực, chưa rời lớp).
SELECT count(DISTINCT e.student_id)::bigint FROM academic.enrollments e
WHERE e.class_id = ANY (sqlc.arg(class_ids)::uuid[]) AND e.status IN ('pending', 'completed')
  AND e.enrolled_at <= sqlc.arg(as_of)::date AND (e.left_at IS NULL OR e.left_at > sqlc.arg(as_of)::date);

-- name: ClassTransfers :many
-- Học sinh rời một lớp (không phải thôi học) trong khoảng và vào lớp khác trong khoảng.
SELECT DISTINCT st.full_name AS student_name, c1.name AS from_class, c2.name AS to_class
FROM academic.enrollments o
JOIN academic.enrollments i ON i.student_id = o.student_id AND i.class_id <> o.class_id
     AND i.enrolled_at BETWEEN sqlc.arg(from_date)::date AND sqlc.arg(to_date)::date
JOIN academic.students st ON st.id = o.student_id
JOIN academic.classes c1 ON c1.id = o.class_id
JOIN academic.classes c2 ON c2.id = i.class_id
WHERE o.class_id = ANY (sqlc.arg(class_ids)::uuid[]) AND o.status <> 'dropped'
  AND o.left_at BETWEEN sqlc.arg(from_date)::date AND sqlc.arg(to_date)::date
ORDER BY st.full_name;

-- name: MakeupAttendees :many
-- Học sinh có mặt ở buổi học bù trong khoảng (mỗi học sinh một dòng).
SELECT DISTINCT ON (a.student_id) st.full_name AS student_name, c.name AS class_name
FROM academic.student_attendance a
JOIN academic.class_sessions s ON s.id = a.session_id AND s.type = 'makeup'
JOIN academic.students st ON st.id = a.student_id
JOIN academic.classes c ON c.id = s.class_id
WHERE a.status IN ('present', 'late') AND s.class_id = ANY (sqlc.arg(class_ids)::uuid[])
  AND s.date BETWEEN sqlc.arg(from_date)::date AND sqlc.arg(to_date)::date
ORDER BY a.student_id, st.full_name;

-- name: TeacherMonthClasses :many
-- Lớp giáo viên giữ trong tháng (TeachingQuality::classesOf): lớp đang học mình là GV / trợ giảng / GVNN, hoặc có buổi
-- mình đứng trong tháng; kèm chuyên cần (%) của tháng và số buổi mình đã đứng lớp (tới hôm nay, không tính buổi hủy).
SELECT c.id, c.code::text AS code, c.name,
       (SELECT count(*) FROM academic.student_attendance a
        WHERE a.class_id = c.id AND a.session_date BETWEEN sqlc.arg(from_date)::date AND sqlc.arg(to_date)::date)::bigint AS attendance_total,
       (SELECT count(*) FROM academic.student_attendance a
        WHERE a.class_id = c.id AND a.session_date BETWEEN sqlc.arg(from_date)::date AND sqlc.arg(to_date)::date
          AND a.status IN ('present', 'late'))::bigint AS attended,
       (SELECT count(*) FROM academic.class_sessions s
        WHERE s.class_id = c.id AND s.status <> 'cancelled' AND s.date BETWEEN sqlc.arg(from_date)::date AND sqlc.arg(until)::date
          AND (s.teacher_id = sqlc.arg(user_id) OR s.foreign_teacher_id = sqlc.arg(user_id) OR s.assistant_id = sqlc.arg(user_id)))::bigint AS sessions_done
FROM academic.classes c
WHERE c.deleted_at IS NULL AND (
    (c.status = 'active' AND (c.teacher_id = sqlc.arg(user_id) OR c.assistant_id = sqlc.arg(user_id) OR c.foreign_teacher_id = sqlc.arg(user_id)))
    OR EXISTS (SELECT 1 FROM academic.class_sessions s WHERE s.class_id = c.id
               AND s.date BETWEEN sqlc.arg(from_date)::date AND sqlc.arg(to_date)::date
               AND (s.teacher_id = sqlc.arg(user_id) OR s.foreign_teacher_id = sqlc.arg(user_id) OR s.assistant_id = sqlc.arg(user_id))))
ORDER BY c.name;

-- name: TaskClassOptions :many
-- Lớp gắn vào công việc: lớp người dùng thấy, chưa xóa, theo trạng thái.
SELECT c.id, c.code::text AS code, c.name, c.schedule_text
FROM academic.classes c
WHERE c.deleted_at IS NULL AND c.status = ANY (sqlc.arg(statuses)::text[])
  AND (sqlc.arg(all_classes)::bool OR c.id = ANY (sqlc.arg(ids)::uuid[]))
ORDER BY c.name, c.code;

-- name: TaskSessions :many
-- Buổi học (không hủy, không phụ đạo) của các lớp trong khoảng ngày, kèm thứ tự buổi chính khóa (SessionLessonService::
-- sessionNumbers: buổi regular không hủy của lớp, theo ngày / giờ).
WITH numbered AS (
    SELECT n.id, row_number() OVER (PARTITION BY n.class_id ORDER BY n.date, n.start_time, n.id) AS lesson_no
    FROM academic.class_sessions n
    WHERE n.class_id = ANY (sqlc.arg(class_ids)::uuid[]) AND n.type = 'regular' AND n.status <> 'cancelled'
)
SELECT s.id, s.class_id, c.name AS class_name, s.date, s.shift_name, s.type, s.room, s.branch_id, b.name AS branch_name,
       to_char(s.start_time, 'HH24:MI') AS start_time, to_char(s.end_time, 'HH24:MI') AS end_time,
       coalesce(nb.lesson_no, 0)::bigint AS lesson_no
FROM academic.class_sessions s
JOIN academic.classes c ON c.id = s.class_id AND c.deleted_at IS NULL
JOIN core.branches b ON b.id = s.branch_id
LEFT JOIN numbered nb ON nb.id = s.id
WHERE s.class_id = ANY (sqlc.arg(class_ids)::uuid[]) AND s.status <> 'cancelled' AND s.type <> 'support'
  AND s.date BETWEEN sqlc.arg(from_date)::date AND sqlc.arg(to_date)::date
ORDER BY s.date, s.start_time, s.id;

-- name: TaskSession :one
-- Một buổi học (lớp chưa xóa) kèm thứ tự buổi chính khóa.
SELECT s.id, s.class_id, c.name AS class_name, s.date, s.shift_name, s.type, s.room, s.branch_id, b.name AS branch_name,
       to_char(s.start_time, 'HH24:MI') AS start_time, to_char(s.end_time, 'HH24:MI') AS end_time,
       coalesce((SELECT x.lesson_no FROM (
           SELECT n.id, row_number() OVER (ORDER BY n.date, n.start_time, n.id) AS lesson_no
           FROM academic.class_sessions n
           WHERE n.class_id = s.class_id AND n.type = 'regular' AND n.status <> 'cancelled') x
        WHERE x.id = s.id), 0)::bigint AS lesson_no
FROM academic.class_sessions s
JOIN academic.classes c ON c.id = s.class_id AND c.deleted_at IS NULL
JOIN core.branches b ON b.id = s.branch_id
WHERE s.id = $1;

-- name: TaskDaySessions :many
-- Lớp trực trong ngày của trợ giảng: buổi không hủy mình dạy / dạy GVNN / trợ giảng, hoặc thuộc các lớp có việc giao.
SELECT s.id, s.class_id, c.name AS class_name, s.date, s.shift_name, s.type, s.room, s.branch_id, b.name AS branch_name,
       to_char(s.start_time, 'HH24:MI') AS start_time, to_char(s.end_time, 'HH24:MI') AS end_time
FROM academic.class_sessions s
JOIN academic.classes c ON c.id = s.class_id AND c.deleted_at IS NULL
JOIN core.branches b ON b.id = s.branch_id
WHERE s.date = sqlc.arg(day)::date AND s.status <> 'cancelled'
  AND (s.teacher_id = sqlc.arg(staff_id)::uuid OR s.foreign_teacher_id = sqlc.arg(staff_id)::uuid
       OR s.assistant_id = sqlc.arg(staff_id)::uuid OR s.class_id = ANY (sqlc.arg(class_ids)::uuid[]))
ORDER BY s.start_time, s.id;

-- name: ClassStartInfos :many
-- Trạng thái / ngày khai giảng của các lớp (màn Xác nhận nhập học bên CRM).
SELECT id, status, start_date FROM academic.classes WHERE id = ANY (sqlc.arg(ids)::uuid[]);

-- name: ClosingClassChoices :many
-- Lớp nhận ghi danh khi chốt / xếp lớp khách CRM (đang học, hoặc sắp khai giảng chưa tới ngày bắt đầu) của một chi nhánh
-- (NULL = mọi chi nhánh), kèm trình độ và sĩ số; lớp sắp khai giảng lên đầu, rồi theo ngày khai giảng.
SELECT c.id, c.name, c.code::text AS code, c.branch_id, c.course_id, c.status, c.start_date, c.max_capacity, c.min_students,
       cl.id AS class_level_id, co.course_level_id, cl.name AS class_level_name, ccl.name AS course_level_name,
       (SELECT count(DISTINCT r.student_id) FROM academic.class_roster r WHERE r.class_id = c.id)::bigint AS roster_count
FROM academic.classes c
LEFT JOIN academic.courses co ON co.id = c.course_id AND co.deleted_at IS NULL
LEFT JOIN academic.course_levels cl ON cl.code = c.level AND cl.deleted_at IS NULL
LEFT JOIN academic.course_levels ccl ON ccl.id = co.course_level_id
WHERE c.deleted_at IS NULL AND (sqlc.narg(branch_id)::uuid IS NULL OR c.branch_id = sqlc.narg(branch_id)::uuid)
  AND (c.status = 'active' OR (c.status = 'upcoming' AND (c.start_date IS NULL OR c.start_date >= sqlc.arg(today)::date)))
ORDER BY (c.status = 'upcoming') DESC, c.start_date NULLS LAST, c.name;

-- name: ClosingCourses :many
-- Khóa học đang mở (chốt khách, xếp lớp sau).
SELECT id, name, tuition_fee FROM academic.courses WHERE deleted_at IS NULL AND is_active ORDER BY name;

-- name: StudentBranchID :one
SELECT branch_id FROM academic.students WHERE id = $1;
