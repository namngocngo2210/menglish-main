-- Cổng giáo viên (TeacherPortalController, StudentPortalController::teacherSubmissions / markSubmission).
-- Đọc lớp / buổi / danh sách lớp thật của phân hệ Lớp học (chỉ đọc); ghi bài tập, bài nộp, điểm mini test, nhận xét.

-- ───────────── Lớp, buổi học ─────────────

-- name: GetClass :one
SELECT c.id, c.code::text AS code, c.name, c.branch_id, b.name AS branch_name, c.status, c.room, c.program,
       c.teacher_id, c.assistant_id, c.foreign_teacher_id, t.full_name AS teacher_name, co.name AS course_name
FROM academic.classes c
JOIN core.branches b ON b.id = c.branch_id
LEFT JOIN iam.users t ON t.id = c.teacher_id
LEFT JOIN academic.courses co ON co.id = c.course_id
WHERE c.id = $1 AND c.deleted_at IS NULL;

-- name: GetSession :one
SELECT s.id, s.class_id, s.date, to_char(s.start_time, 'HH24:MI')::text AS start_time, to_char(s.end_time, 'HH24:MI')::text AS end_time,
       s.type, s.status, s.room, s.shift_name, s.teacher_id, s.foreign_teacher_id, s.assistant_id, s.holiday_id,
       t.full_name AS teacher_name
FROM academic.class_sessions s
LEFT JOIN iam.users t ON t.id = s.teacher_id
WHERE s.id = $1;

-- name: SessionsOnDate :many
SELECT s.id, s.type, s.teacher_id, s.foreign_teacher_id, s.assistant_id
FROM academic.class_sessions s
WHERE s.class_id = sqlc.arg(class_id) AND s.date = sqlc.arg(day)::date AND s.status <> 'cancelled'
ORDER BY s.start_time, s.id;

-- name: StaffSessions :many
-- Buổi người dùng được phân công trong khoảng ngày: theo nhân sự của buổi, hoặc buổi chưa gán GV thì theo nhân sự của lớp
-- (TeacherPortalController::staffSessionsQuery). attended: buổi đã có điểm danh.
SELECT s.id, s.class_id, c.name AS class_name, c.code::text AS class_code, b.name AS branch_name, s.date,
       to_char(s.start_time, 'HH24:MI')::text AS start_time, to_char(s.end_time, 'HH24:MI')::text AS end_time,
       s.type, s.status, s.room, c.room AS class_room, s.shift_name,
       EXISTS (SELECT 1 FROM academic.student_attendance a WHERE a.session_id = s.id)::bool AS attended,
       (SELECT count(DISTINCT r.student_id) FROM academic.class_roster r WHERE r.class_id = c.id)::int AS roster_count
FROM academic.class_sessions s
JOIN academic.classes c ON c.id = s.class_id
JOIN core.branches b ON b.id = c.branch_id
WHERE c.deleted_at IS NULL
  AND s.date BETWEEN sqlc.arg(from_date)::date AND sqlc.arg(to_date)::date
  AND (s.teacher_id = sqlc.arg(user_id) OR s.foreign_teacher_id = sqlc.arg(user_id) OR s.assistant_id = sqlc.arg(user_id)
       OR (s.teacher_id IS NULL AND (c.teacher_id = sqlc.arg(user_id) OR c.assistant_id = sqlc.arg(user_id)
                                     OR c.foreign_teacher_id = sqlc.arg(user_id))))
ORDER BY s.date, s.start_time, s.id;

-- name: ClassSessionsBetween :many
-- Buổi của lớp trong khoảng ngày (mới nhất trước), kèm số lượt điểm danh.
SELECT s.id, s.date, to_char(s.start_time, 'HH24:MI')::text AS start_time, to_char(s.end_time, 'HH24:MI')::text AS end_time,
       s.type, s.status,
       (SELECT count(*) FROM academic.student_attendance a WHERE a.session_id = s.id)::int AS attendance_count
FROM academic.class_sessions s
WHERE s.class_id = sqlc.arg(class_id) AND s.date BETWEEN sqlc.arg(from_date)::date AND sqlc.arg(to_date)::date
ORDER BY s.date DESC, s.start_time DESC, s.id DESC;

-- name: SessionNumbers :many
-- Số thứ tự buổi chính khóa (1..n) của lớp (SessionLessonService::sessionNumbers).
SELECT s.id, row_number() OVER (ORDER BY s.date, s.start_time, s.id)::int AS no
FROM academic.class_sessions s
WHERE s.class_id = $1 AND s.type = 'regular' AND s.status <> 'cancelled';

-- name: SessionRoster :many
-- Học viên của buổi: danh sách lớp thật (lớp chính + liên kết, còn giữ chỗ) + học viên đã có điểm danh của buổi
-- (vd. đã thôi học sau đó) để sửa được dữ liệu cũ. TODO(gộp): buổi phụ đạo → học viên được xếp phụ đạo.
SELECT st.id, st.full_name, st.code::text AS code, (st.current_class_id IS NOT DISTINCT FROM sqlc.arg(class_id)::uuid)::bool AS is_main
FROM academic.students st
WHERE st.id IN (
    SELECT r.student_id FROM academic.class_roster r WHERE r.class_id = sqlc.arg(class_id)::uuid
    UNION
    SELECT a.student_id FROM academic.student_attendance a WHERE a.session_id = sqlc.narg(session_id)::uuid
)
ORDER BY lower(st.full_name), st.id;

-- name: RosterStudents :many
-- Danh sách lớp thật (lớp chính + liên kết), theo tên.
SELECT st.id, st.full_name, st.code::text AS code
FROM academic.students st
WHERE st.id IN (SELECT r.student_id FROM academic.class_roster r WHERE r.class_id = $1)
ORDER BY lower(st.full_name), st.id;

-- name: OnRoster :one
SELECT EXISTS (SELECT 1 FROM academic.class_roster r WHERE r.class_id = sqlc.arg(class_id) AND r.student_id = sqlc.arg(student_id))::bool;

-- name: SessionAttendanceStatuses :many
SELECT a.student_id, a.status FROM academic.student_attendance a WHERE a.session_id = $1;

-- name: AttentionScores :many
-- Học sinh cần chú ý: mini test dưới 7/10 trong 30 ngày của các lớp người dùng phụ trách (5 bài mới nhất).
SELECT m.id, st.full_name AS student_name, c.name AS class_name, m.name, m.score, m.max_score
FROM academic.mini_test_scores m
JOIN academic.students st ON st.id = m.student_id
JOIN academic.classes c ON c.id = m.class_id
WHERE (c.teacher_id = sqlc.arg(user_id) OR c.foreign_teacher_id = sqlc.arg(user_id) OR c.assistant_id = sqlc.arg(user_id))
  AND m.test_date >= sqlc.arg(from_date)::date AND m.max_score > 0 AND m.score * 10 < m.max_score * 7
ORDER BY m.test_date DESC, m.id DESC
LIMIT 5;

-- ───────────── Bài tập về nhà ─────────────

-- name: ClassHomeworks :many
SELECT h.id, h.session_id, h.teacher_id, h.title, h.description, h.class_note, h.youtube_url, h.quizizz_url, h.audio_file_id,
       h.items, h.due_date, h.due_at, h.created_at, t.full_name AS teacher_name, s.date AS session_date
FROM academic.homeworks h
LEFT JOIN iam.users t ON t.id = h.teacher_id
LEFT JOIN academic.class_sessions s ON s.id = h.session_id
WHERE h.class_id = $1 AND h.deleted_at IS NULL
ORDER BY h.due_date DESC NULLS LAST, h.created_at DESC, h.id DESC;

-- name: GetHomework :one
SELECT h.id, h.class_id, h.session_id, h.title, h.items, h.audio_file_id, h.created_at
FROM academic.homeworks h
WHERE h.id = sqlc.arg(id) AND h.class_id = sqlc.arg(class_id) AND h.deleted_at IS NULL;

-- name: HomeworkSessions :many
-- Buổi chọn được khi giao bài: chính khóa / học bù chưa hủy, từ 30 ngày trước tới 14 ngày tới.
SELECT s.id, s.date, to_char(s.start_time, 'HH24:MI')::text AS start_time, s.type
FROM academic.class_sessions s
WHERE s.class_id = sqlc.arg(class_id) AND s.type IN ('regular', 'makeup') AND s.status <> 'cancelled'
  AND s.date BETWEEN sqlc.arg(from_date)::date AND sqlc.arg(to_date)::date
ORDER BY s.date, s.start_time, s.id;

-- name: RosterSubmissionTypes :many
-- Bài nộp của học viên đang trong lớp từ một thời điểm (khóa hạng mục đã có bài nộp).
SELECT sb.homework_type, sb.created_at
FROM academic.homework_submissions sb
WHERE sb.student_id IN (SELECT r.student_id FROM academic.class_roster r WHERE r.class_id = sqlc.arg(class_id))
  AND sb.created_at >= sqlc.arg(since);

-- name: InsertHomework :one
INSERT INTO academic.homeworks (class_id, session_id, teacher_id, title, description, class_note, youtube_url, quizizz_url,
                                audio_file_id, items, due_date, due_at, created_by)
VALUES (sqlc.arg(class_id), sqlc.narg(session_id), sqlc.narg(teacher_id), sqlc.arg(title), sqlc.narg(description),
        sqlc.narg(class_note), sqlc.narg(youtube_url), sqlc.narg(quizizz_url), sqlc.narg(audio_file_id), sqlc.narg(items),
        sqlc.narg(due_date), sqlc.narg(due_at), sqlc.narg(teacher_id))
RETURNING id;

-- name: UpdateHomework :exec
UPDATE academic.homeworks
SET session_id = sqlc.narg(session_id), title = sqlc.arg(title), description = sqlc.narg(description),
    class_note = sqlc.narg(class_note), youtube_url = sqlc.narg(youtube_url), quizizz_url = sqlc.narg(quizizz_url),
    audio_file_id = coalesce(sqlc.narg(audio_file_id), audio_file_id), items = sqlc.narg(items),
    due_date = sqlc.narg(due_date), due_at = sqlc.narg(due_at), updated_at = now()
WHERE id = sqlc.arg(id);

-- name: SetHomeworkAudio :exec
UPDATE academic.homeworks SET audio_file_id = sqlc.narg(audio_file_id), updated_at = now() WHERE id = sqlc.arg(id);

-- name: SoftDeleteHomework :exec
UPDATE academic.homeworks SET deleted_at = now(), updated_at = now() WHERE id = $1;

-- name: HomeworkAudioOwner :one
-- Lớp của bài tập gắn file nghe (kiểm quyền tải file).
SELECT h.class_id FROM academic.homeworks h WHERE h.audio_file_id = $1 AND h.deleted_at IS NULL LIMIT 1;

-- ───────────── Điểm mini test ─────────────

-- name: ScoresByName :many
SELECT m.student_id, m.score, m.max_score, m.skill_scores, m.note
FROM academic.mini_test_scores m
WHERE m.class_id = sqlc.arg(class_id) AND m.name = sqlc.arg(name)
ORDER BY m.test_date, m.updated_at;

-- name: FindScore :one
SELECT m.id FROM academic.mini_test_scores m
WHERE m.class_id = sqlc.arg(class_id) AND m.student_id = sqlc.arg(student_id) AND m.name = sqlc.arg(name)
ORDER BY m.test_date DESC, m.updated_at DESC
LIMIT 1;

-- name: InsertScore :one
INSERT INTO academic.mini_test_scores (class_id, student_id, teacher_id, syllabus_unit_id, name, score, max_score, skill_scores,
                                       test_date, note)
VALUES (sqlc.arg(class_id), sqlc.arg(student_id), sqlc.narg(teacher_id), sqlc.narg(syllabus_unit_id), sqlc.arg(name),
        sqlc.narg(score), sqlc.arg(max_score), sqlc.narg(skill_scores), sqlc.arg(test_date), sqlc.narg(note))
RETURNING id;

-- name: UpdateScore :exec
UPDATE academic.mini_test_scores
SET teacher_id = sqlc.narg(teacher_id), syllabus_unit_id = sqlc.narg(syllabus_unit_id), score = sqlc.narg(score),
    max_score = sqlc.arg(max_score), skill_scores = sqlc.narg(skill_scores), test_date = sqlc.arg(test_date),
    note = sqlc.narg(note), updated_at = now()
WHERE id = sqlc.arg(id);

-- ───────────── Nhận xét buổi học ─────────────

-- name: RemarkSheet :one
SELECT r.id, r.status FROM academic.remark_sheets r WHERE r.session_id = $1;

-- name: SheetRemarks :many
SELECT student_id, monsters_group, monsters_bonus, grammar, attitude, result, comment
FROM academic.remarks WHERE sheet_id = $1;

-- name: UpsertRemarkSheet :one
INSERT INTO academic.remark_sheets (session_id, class_id, status, created_by, updated_by)
VALUES (sqlc.arg(session_id), sqlc.arg(class_id), sqlc.arg(status), sqlc.arg(by_user), sqlc.arg(by_user))
ON CONFLICT (session_id) DO UPDATE SET status = excluded.status, updated_by = excluded.updated_by, updated_at = now()
RETURNING id;

-- name: ClearRemarks :exec
DELETE FROM academic.remarks WHERE sheet_id = $1;

-- name: InsertRemark :exec
INSERT INTO academic.remarks (sheet_id, student_id, monsters_group, monsters_bonus, grammar, attitude, result, comment)
VALUES ($1, $2, $3, $4, $5, $6, $7, $8);

-- ───────────── Báo cáo giảng dạy ─────────────

-- name: AttendanceMarkedCount :one
SELECT count(*)::int FROM academic.student_attendance a
WHERE a.teacher_id = sqlc.arg(user_id) AND a.session_date BETWEEN sqlc.arg(from_date)::date AND sqlc.arg(to_date)::date;

-- name: ScoresEnteredCount :one
SELECT count(*)::int FROM academic.mini_test_scores m
WHERE m.teacher_id = sqlc.arg(user_id) AND m.test_date BETWEEN sqlc.arg(from_date)::date AND sqlc.arg(to_date)::date;

-- name: MyClasses :many
-- Lớp GV chính / GVNN (trừ lớp đã hủy).
SELECT c.id, c.code::text AS code, c.name, c.status, c.program, co.name AS course_name, c.teacher_id, c.foreign_teacher_id
FROM academic.classes c
LEFT JOIN academic.courses co ON co.id = c.course_id
WHERE c.deleted_at IS NULL AND c.status <> 'cancelled'
  AND (c.teacher_id = sqlc.arg(user_id) OR c.foreign_teacher_id = sqlc.arg(user_id))
ORDER BY c.name, c.id;

-- ───────────── Chấm bài nộp ─────────────

-- name: GradableClasses :many
-- Lớp chấm bài được: lớp mình dạy / trợ giảng / GVNN; phạm vi Lớp học "Toàn hệ thống" → mọi lớp (trừ lớp đã hủy).
SELECT c.id, c.code::text AS code, c.name
FROM academic.classes c
WHERE c.deleted_at IS NULL AND c.status <> 'cancelled'
  AND (sqlc.arg(all_classes)::bool OR c.teacher_id = sqlc.arg(user_id) OR c.assistant_id = sqlc.arg(user_id)
       OR c.foreign_teacher_id = sqlc.arg(user_id))
ORDER BY c.name, c.id;

-- name: ClassSubmissions :many
-- Bài nộp của học viên đang trong lớp theo hạng mục, mới nhất trước.
SELECT sb.id, sb.student_id, st.full_name AS student_name, sb.homework_type, sb.notes, sb.attachment_file_id, sb.attachment_name,
       sb.status, sb.score, sb.feedback, sb.created_at, sb.reviewed_at, h.title AS homework_title
FROM academic.homework_submissions sb
JOIN academic.students st ON st.id = sb.student_id
LEFT JOIN academic.homeworks h ON h.id = sb.homework_id
WHERE sb.student_id IN (SELECT r.student_id FROM academic.class_roster r WHERE r.class_id = sqlc.arg(class_id))
  AND sb.homework_type = sqlc.arg(homework_type)
ORDER BY sb.created_at DESC, sb.id DESC;

-- name: GetSubmission :one
SELECT sb.id, sb.student_id, st.full_name AS student_name, sb.homework_type, sb.status, sb.attachment_file_id
FROM academic.homework_submissions sb
JOIN academic.students st ON st.id = sb.student_id
WHERE sb.id = $1;

-- name: SubmissionByFile :one
SELECT sb.id, sb.student_id FROM academic.homework_submissions sb WHERE sb.attachment_file_id = $1 LIMIT 1;

-- name: StudentGradable :one
-- Học viên thuộc một lớp người dùng chấm bài được (lớp chính hoặc lớp liên kết, như Student::activeClassIds).
SELECT EXISTS (
    SELECT 1 FROM academic.classes c
    WHERE c.deleted_at IS NULL
      AND (sqlc.arg(all_classes)::bool OR c.teacher_id = sqlc.arg(user_id) OR c.assistant_id = sqlc.arg(user_id)
           OR c.foreign_teacher_id = sqlc.arg(user_id))
      AND c.id IN (
          SELECT st.current_class_id FROM academic.students st WHERE st.id = sqlc.arg(student_id) AND st.current_class_id IS NOT NULL
          UNION
          SELECT e.class_id FROM academic.enrollments e
          WHERE e.student_id = sqlc.arg(student_id) AND e.status IN ('pending', 'completed') AND e.left_at IS NULL)
)::bool;

-- name: MarkSubmission :exec
UPDATE academic.homework_submissions
SET status = 'reviewed', score = sqlc.narg(score), feedback = sqlc.narg(feedback), reviewed_by = sqlc.arg(reviewed_by),
    reviewed_at = now(), updated_at = now()
WHERE id = sqlc.arg(id);

-- ───────────── Cổng học viên (teacher.StudentPortal) ─────────────

-- name: InsertSubmission :one
INSERT INTO academic.homework_submissions (student_id, homework_id, homework_type, notes, attachment_file_id, attachment_name, submitted_by)
VALUES (sqlc.arg(student_id), sqlc.narg(homework_id), sqlc.arg(homework_type), sqlc.narg(notes), sqlc.narg(attachment_file_id),
        sqlc.narg(attachment_name), sqlc.narg(submitted_by))
RETURNING id, created_at;

-- name: UpdateSubmission :exec
UPDATE academic.homework_submissions
SET notes = CASE WHEN sqlc.arg(set_notes)::bool THEN sqlc.narg(notes) ELSE notes END,
    attachment_file_id = coalesce(sqlc.narg(attachment_file_id), attachment_file_id),
    attachment_name = coalesce(sqlc.narg(attachment_name), attachment_name),
    updated_at = now()
WHERE id = sqlc.arg(id);

-- name: DeleteSubmission :exec
DELETE FROM academic.homework_submissions WHERE id = $1;

-- name: StudentSubmissions :many
SELECT sb.id, sb.student_id, sb.homework_id, sb.homework_type, sb.notes, sb.attachment_file_id, sb.attachment_name, sb.status,
       sb.score, sb.feedback, sb.reviewed_at, sb.created_at, sb.updated_at
FROM academic.homework_submissions sb
WHERE sb.student_id = $1
ORDER BY sb.created_at DESC, sb.id DESC;

-- name: GetStudentSubmission :one
SELECT sb.id, sb.student_id, sb.homework_id, sb.homework_type, sb.notes, sb.attachment_file_id, sb.attachment_name, sb.status,
       sb.score, sb.feedback, sb.reviewed_at, sb.created_at, sb.updated_at
FROM academic.homework_submissions sb
WHERE sb.id = $1;

-- name: ClassesHomeworks :many
-- Bài tập của các lớp (cổng học viên: bài mới nhất, hạn nộp), mới nhất trước.
SELECT h.id, h.class_id, c.name AS class_name, h.session_id, h.title, h.description, h.class_note, h.youtube_url, h.quizizz_url,
       h.audio_file_id, h.items, h.due_date, h.due_at, h.created_at
FROM academic.homeworks h
JOIN academic.classes c ON c.id = h.class_id
WHERE h.class_id = ANY (sqlc.arg(class_ids)::uuid[]) AND h.deleted_at IS NULL
ORDER BY h.due_date DESC NULLS LAST, h.created_at DESC, h.id DESC
LIMIT sqlc.arg(lim);

-- name: StudentRemarks :many
-- Nhận xét đã lưu (không phải nháp) của học viên ở các lớp, buổi mới nhất trước.
SELECT s.date, r.monsters_group, r.monsters_bonus, r.grammar, r.attitude, r.result, r.comment
FROM academic.remarks r
JOIN academic.remark_sheets sh ON sh.id = r.sheet_id
JOIN academic.class_sessions s ON s.id = sh.session_id
WHERE r.student_id = sqlc.arg(student_id) AND sh.status = 'completed'
  AND sh.class_id = ANY (sqlc.arg(class_ids)::uuid[])
  AND coalesce(r.monsters_group, r.monsters_bonus, r.grammar, r.attitude, r.result, r.comment) IS NOT NULL
ORDER BY s.date DESC, s.start_time DESC
LIMIT sqlc.arg(lim);

-- name: StudentMiniTests :many
SELECT m.id, m.class_id, m.name, m.test_date, m.score, m.max_score, m.skill_scores, m.note
FROM academic.mini_test_scores m
WHERE m.student_id = sqlc.arg(student_id)
ORDER BY m.test_date DESC, m.id DESC
LIMIT sqlc.arg(lim);

-- ───────────── KPI / Tổng quan ─────────────

-- name: HomeworksCreatedIn :many
-- Bài giao trong khoảng của các lớp (TeachingQuality::homeworkRates).
SELECT h.id, h.class_id, h.created_at, h.due_date, h.due_at
FROM academic.homeworks h
WHERE h.class_id = ANY (sqlc.arg(class_ids)::uuid[]) AND h.deleted_at IS NULL
  AND h.created_at BETWEEN sqlc.arg(from_at) AND sqlc.arg(to_at);

-- name: RosterOfClasses :many
SELECT r.class_id, r.student_id FROM academic.class_roster r WHERE r.class_id = ANY (sqlc.arg(class_ids)::uuid[]);

-- name: SubmissionTimes :many
SELECT sb.student_id, sb.created_at
FROM academic.homework_submissions sb
WHERE sb.student_id = ANY (sqlc.arg(student_ids)::uuid[]) AND sb.created_at BETWEEN sqlc.arg(from_at) AND sqlc.arg(to_at);

-- name: ClassMiniAverages :many
-- Điểm mini test trung bình quy về thang 10 (tối đa 10) theo lớp.
SELECT m.class_id, avg(least(10, m.score / m.max_score * 10))::float8 AS avg_score
FROM academic.mini_test_scores m
WHERE m.class_id = ANY (sqlc.arg(class_ids)::uuid[]) AND m.score IS NOT NULL AND m.max_score > 0
  AND m.test_date BETWEEN sqlc.arg(from_date)::date AND sqlc.arg(to_date)::date
GROUP BY m.class_id;

-- name: KpiTeacherClasses :many
-- Lớp giáo viên giữ trong khoảng (TeachingQuality::classesOf), kèm sĩ số.
SELECT c.id, c.code::text AS code, c.name,
       (SELECT count(DISTINCT r.student_id) FROM academic.class_roster r WHERE r.class_id = c.id)::int AS students
FROM academic.classes c
WHERE c.deleted_at IS NULL AND (
    (c.status = 'active' AND (c.teacher_id = sqlc.arg(user_id) OR c.assistant_id = sqlc.arg(user_id) OR c.foreign_teacher_id = sqlc.arg(user_id)))
    OR EXISTS (SELECT 1 FROM academic.class_sessions s WHERE s.class_id = c.id
               AND s.date BETWEEN sqlc.arg(from_date)::date AND sqlc.arg(to_date)::date
               AND (s.teacher_id = sqlc.arg(user_id) OR s.foreign_teacher_id = sqlc.arg(user_id) OR s.assistant_id = sqlc.arg(user_id))))
ORDER BY c.name, c.id;

-- name: BoardClasses :many
-- Lớp có nhân sự là GV / trợ giảng / GVNN (KpiBoardService::metricsForMany).
SELECT c.id, c.teacher_id, c.assistant_id, c.foreign_teacher_id
FROM academic.classes c
WHERE c.deleted_at IS NULL AND (c.teacher_id = ANY (sqlc.arg(user_ids)::uuid[]) OR c.assistant_id = ANY (sqlc.arg(user_ids)::uuid[])
                                OR c.foreign_teacher_id = ANY (sqlc.arg(user_ids)::uuid[]));

-- name: BoardHomeworkCounts :many
-- Số bài giao trong kỳ theo lớp: hạn nộp trong kỳ, hoặc không có hạn thì ngày giao trong kỳ.
SELECT h.class_id, count(*)::int AS total
FROM academic.homeworks h
WHERE h.class_id = ANY (sqlc.arg(class_ids)::uuid[]) AND h.deleted_at IS NULL
  AND ((h.due_date IS NOT NULL AND h.due_date BETWEEN sqlc.arg(from_date)::date AND sqlc.arg(to_date)::date)
       OR (h.due_date IS NULL AND (h.created_at AT TIME ZONE 'Asia/Ho_Chi_Minh')::date BETWEEN sqlc.arg(from_date)::date AND sqlc.arg(to_date)::date))
GROUP BY h.class_id;

-- name: BoardMainStudents :many
-- Học viên giữ chỗ có lớp chính là các lớp này.
SELECT st.id, st.current_class_id
FROM academic.students st
WHERE st.deleted_at IS NULL AND st.current_class_id = ANY (sqlc.arg(class_ids)::uuid[])
  AND st.status IN ('waiting_start', 'studying', 'summer_break');

-- name: BoardSubmissionCount :one
SELECT count(*)::int FROM academic.homework_submissions sb
WHERE sb.student_id = ANY (sqlc.arg(student_ids)::uuid[])
  AND (sb.created_at AT TIME ZONE 'Asia/Ho_Chi_Minh')::date BETWEEN sqlc.arg(from_date)::date AND sqlc.arg(to_date)::date;

-- name: UserName :one
SELECT full_name FROM iam.users WHERE id = $1;

-- name: StaffSessionByID :one
-- Buổi (lớp chưa xóa) được phân công cho người dùng (cùng điều kiện StaffSessions).
SELECT s.id, s.class_id, c.name AS class_name, s.date, to_char(s.start_time, 'HH24:MI')::text AS start_time,
       to_char(s.end_time, 'HH24:MI')::text AS end_time, s.type, s.status
FROM academic.class_sessions s
JOIN academic.classes c ON c.id = s.class_id
WHERE s.id = sqlc.arg(id) AND c.deleted_at IS NULL
  AND (s.teacher_id = sqlc.arg(user_id) OR s.foreign_teacher_id = sqlc.arg(user_id) OR s.assistant_id = sqlc.arg(user_id)
       OR (s.teacher_id IS NULL AND (c.teacher_id = sqlc.arg(user_id) OR c.assistant_id = sqlc.arg(user_id)
                                     OR c.foreign_teacher_id = sqlc.arg(user_id))));

-- name: SessionClassName :one
SELECT c.name FROM academic.class_sessions s JOIN academic.classes c ON c.id = s.class_id WHERE s.id = $1;

-- name: StudentName :one
SELECT full_name FROM academic.students WHERE id = $1;

-- name: HomeworkOfStudent :one
-- Bài tập thuộc lớp chính / lớp liên kết của học viên.
SELECT EXISTS (
    SELECT 1 FROM academic.homeworks h
    WHERE h.id = sqlc.arg(id) AND h.deleted_at IS NULL
      AND h.class_id IN (
          SELECT st.current_class_id FROM academic.students st WHERE st.id = sqlc.arg(student_id) AND st.current_class_id IS NOT NULL
          UNION
          SELECT e.class_id FROM academic.enrollments e
          WHERE e.student_id = sqlc.arg(student_id) AND e.status IN ('pending', 'completed') AND e.left_at IS NULL)
)::bool;
