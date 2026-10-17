-- ───── Học viên của cổng ─────

-- name: LinkedStudents :many
-- Học viên gắn với tài khoản (tài khoản cổng của chính học viên hoặc phụ huynh), còn dùng cổng: Chờ khai giảng / Đang học /
-- Nghỉ hè (Laravel getActiveStudent).
SELECT s.id, s.code, s.full_name, c.name AS class_name
FROM academic.students s
LEFT JOIN academic.classes c ON c.id = s.current_class_id
WHERE s.deleted_at IS NULL AND s.status IN ('waiting_start', 'studying', 'summer_break')
  AND (s.user_id = sqlc.arg(user_id)
       OR EXISTS (SELECT 1 FROM academic.student_guardians g WHERE g.student_id = s.id AND g.guardian_id = sqlc.arg(user_id)))
ORDER BY s.full_name, s.id;

-- name: GetStudent :one
-- Hồ sơ cho cổng; linked = tài khoản user_id là học viên này hoặc phụ huynh của học viên.
SELECT s.id, s.code, s.full_name, s.phone, s.dob, s.address, s.notes, s.status, s.study_started_on, s.branch_id,
       s.current_class_id, s.user_id, c.name AS class_name, t.full_name AS teacher_name, co.tuition_fee AS course_fee,
       (s.user_id IS NOT DISTINCT FROM sqlc.arg(user_id) AND s.user_id IS NOT NULL
        OR EXISTS (SELECT 1 FROM academic.student_guardians g WHERE g.student_id = s.id AND g.guardian_id = sqlc.arg(user_id)))::bool AS linked
FROM academic.students s
LEFT JOIN academic.classes c ON c.id = s.current_class_id
LEFT JOIN iam.users t ON t.id = c.teacher_id
LEFT JOIN academic.courses co ON co.id = c.course_id
WHERE s.id = sqlc.arg(id) AND s.deleted_at IS NULL;

-- name: ActiveClassIDs :many
-- Lớp chính + lớp liên kết còn hiệu lực (Student::activeClassIds).
SELECT DISTINCT class_id::uuid AS class_id FROM (
    SELECT s.current_class_id AS class_id FROM academic.students s WHERE s.id = sqlc.arg(student_id) AND s.current_class_id IS NOT NULL
    UNION
    SELECT e.class_id FROM academic.enrollments e
    WHERE e.student_id = sqlc.arg(student_id) AND e.status IN ('pending', 'completed') AND e.left_at IS NULL
) x;

-- name: ClassNames :many
SELECT id, name FROM academic.classes WHERE id = ANY (sqlc.arg(ids)::uuid[]) AND deleted_at IS NULL ORDER BY name;

-- name: UpdateContact :execrows
-- Học viên / phụ huynh sửa liên hệ, ghi chú (updateProfile).
UPDATE academic.students
SET phone = sqlc.arg(phone), address = sqlc.narg(address), notes = sqlc.narg(notes), updated_at = now(), version = version + 1
WHERE id = sqlc.arg(id) AND deleted_at IS NULL;

-- name: AddStudentEvent :exec
INSERT INTO academic.student_events (student_id, kind, message, created_by) VALUES ($1, 'updated', $2, $3);

-- name: StudentRecipients :many
-- Người nhận thông báo cổng của học viên: tài khoản học viên, chưa có thì phụ huynh.
SELECT u FROM (
    SELECT s.user_id AS u FROM academic.students s WHERE s.id = sqlc.arg(student_id) AND s.user_id IS NOT NULL
    UNION ALL
    SELECT g.guardian_id FROM academic.student_guardians g
    WHERE g.student_id = sqlc.arg(student_id)
      AND NOT EXISTS (SELECT 1 FROM academic.students s WHERE s.id = sqlc.arg(student_id) AND s.user_id IS NOT NULL)
) x WHERE u IS NOT NULL;

-- ───── Trang chủ: lịch học, điểm danh ─────

-- name: AttendanceProgress :one
-- Chuyên cần đã duyệt (rà soát điểm danh): có mặt / đi muộn trên tổng số buổi đã duyệt.
SELECT count(*)::int AS total, count(*) FILTER (WHERE status IN ('present', 'late'))::int AS present
FROM academic.student_attendance WHERE student_id = $1 AND review_status = 'approved';

-- name: UpcomingSessions :many
-- Buổi chính khóa / học bù của các lớp trong khoảng ngày (buổi đã hủy vẫn hiện, gạch ngang).
SELECT cs.id, cs.date, to_char(cs.start_time, 'HH24:MI')::text AS start_time, to_char(cs.end_time, 'HH24:MI')::text AS end_time, cs.type, cs.status, coalesce(cs.room, c.room) AS room, c.name AS class_name
FROM academic.class_sessions cs
JOIN academic.classes c ON c.id = cs.class_id
WHERE cs.class_id = ANY (sqlc.arg(class_ids)::uuid[]) AND cs.type IN ('regular', 'makeup')
  AND cs.date BETWEEN sqlc.arg(from_date) AND sqlc.arg(to_date)
ORDER BY cs.date, cs.start_time
LIMIT 20;

-- name: AttendanceHistory :many
SELECT a.id, coalesce(cs.date, a.session_date) AS day, a.status, a.note, c.name AS class_name
FROM academic.student_attendance a
JOIN academic.classes c ON c.id = a.class_id
LEFT JOIN academic.class_sessions cs ON cs.id = a.session_id
WHERE a.student_id = $1
ORDER BY a.session_date DESC, a.id DESC
LIMIT 10;

-- ───── Hộp thư thông báo (core.notifications) ─────
-- Hộp thư của một học viên = thông báo gửi tài khoản cổng của học viên + thông báo gửi chính người xem (phụ huynh) về
-- học viên này hoặc không gắn học viên nào. viewer chỉ truyền khi người xem là học viên / phụ huynh (nhân sự xem hộ chỉ
-- thấy hộp thư của học viên).

-- name: InboxList :many
SELECT n.id, n.type, n.title, n.body, n.link, n.read_at, n.created_at
FROM core.notifications n
WHERE (n.user_id = sqlc.narg(student_user)
       OR (n.user_id = sqlc.narg(viewer)
           AND (n.data->>'student_id' IS NULL OR n.data->>'student_id' = sqlc.arg(student_id)::text)))
ORDER BY n.created_at DESC, n.id DESC
LIMIT 200;

-- name: InboxUnread :one
SELECT count(*)::int FROM core.notifications n
WHERE n.read_at IS NULL
  AND (n.user_id = sqlc.narg(student_user)
       OR (n.user_id = sqlc.narg(viewer)
           AND (n.data->>'student_id' IS NULL OR n.data->>'student_id' = sqlc.arg(student_id)::text)));

-- name: InboxMarkAll :execrows
UPDATE core.notifications n SET read_at = now()
WHERE n.read_at IS NULL
  AND (n.user_id = sqlc.narg(student_user)
       OR (n.user_id = sqlc.narg(viewer)
           AND (n.data->>'student_id' IS NULL OR n.data->>'student_id' = sqlc.arg(student_id)::text)));

-- name: InboxMarkOne :execrows
UPDATE core.notifications n SET read_at = coalesce(n.read_at, now())
WHERE n.id = sqlc.arg(id)
  AND (n.user_id = sqlc.narg(student_user)
       OR (n.user_id = sqlc.narg(viewer)
           AND (n.data->>'student_id' IS NULL OR n.data->>'student_id' = sqlc.arg(student_id)::text)));

-- name: InboxDelete :execrows
DELETE FROM core.notifications n
WHERE n.id = sqlc.arg(id)
  AND (n.user_id = sqlc.narg(student_user)
       OR (n.user_id = sqlc.narg(viewer)
           AND (n.data->>'student_id' IS NULL OR n.data->>'student_id' = sqlc.arg(student_id)::text)));

-- name: UnreadForUser :one
-- Chuông của tài khoản cổng: chưa đọc của mọi học viên gắn với tài khoản + của chính tài khoản
-- (PortalNotifications::unreadCountForUser).
SELECT count(*)::int FROM core.notifications n
WHERE n.read_at IS NULL
  AND (n.user_id = sqlc.arg(user_id)
       OR n.user_id IN (SELECT s.user_id FROM academic.students s
                        JOIN academic.student_guardians g ON g.student_id = s.id
                        WHERE g.guardian_id = sqlc.arg(user_id) AND s.user_id IS NOT NULL AND s.deleted_at IS NULL));

-- ───── Báo đóng học phí ─────

-- name: InsertPaymentReport :one
INSERT INTO finance.payment_reports (code, student_id, branch_id, amount, content, created_by)
VALUES ($1, $2, $3, $4, $5, $6)
RETURNING *;

-- name: StudentPaymentReports :many
SELECT * FROM finance.payment_reports WHERE student_id = $1 ORDER BY created_at DESC LIMIT 20;

-- name: ListPaymentReports :many
-- Hộp duyệt "Học viên báo đã đóng học phí" (PaymentReportApprovalSource): theo trạng thái, phạm vi chi nhánh Học phí
-- (học viên chưa gán chi nhánh thì theo chi nhánh lớp đang học).
SELECT p.*, s.code AS student_code, s.full_name AS student_name, s.current_class_id, c.name AS class_name,
       r.full_name AS resolver_name, u.full_name AS creator_name, t.id AS tuition_id
FROM finance.payment_reports p
JOIN academic.students s ON s.id = p.student_id
LEFT JOIN academic.classes c ON c.id = s.current_class_id
LEFT JOIN finance.tuitions t ON t.id = (SELECT ft.id FROM finance.tuitions ft WHERE ft.student_id = s.id ORDER BY ft.created_at DESC LIMIT 1)
LEFT JOIN iam.users r ON r.id = p.resolved_by
LEFT JOIN iam.users u ON u.id = p.created_by
WHERE p.status = sqlc.arg(status)
  AND (NOT sqlc.arg(limit_branches)::bool OR coalesce(p.branch_id, s.branch_id, c.branch_id) = ANY (sqlc.arg(branch_ids)::uuid[]))
ORDER BY CASE WHEN sqlc.arg(status) = 'pending' THEN p.created_at END, p.created_at DESC
LIMIT 200;

-- name: LockPaymentReport :one
SELECT p.*, s.full_name AS student_name, s.code AS student_code,
       coalesce(p.branch_id, s.branch_id, c.branch_id, '00000000-0000-0000-0000-000000000000'::uuid)::uuid AS scope_branch_id
FROM finance.payment_reports p
JOIN academic.students s ON s.id = p.student_id
LEFT JOIN academic.classes c ON c.id = s.current_class_id
WHERE p.id = $1
FOR UPDATE OF p;

-- name: ResolvePaymentReport :exec
UPDATE finance.payment_reports
SET status = sqlc.arg(status), rejection_reason = sqlc.narg(reason), resolved_by = sqlc.arg(resolved_by), resolved_at = now(),
    updated_at = now()
WHERE id = sqlc.arg(id);

-- name: PendingPaymentReportCount :one
SELECT count(*)::int FROM finance.payment_reports p
JOIN academic.students s ON s.id = p.student_id
LEFT JOIN academic.classes c ON c.id = s.current_class_id
WHERE p.status = 'pending'
  AND (NOT sqlc.arg(limit_branches)::bool OR coalesce(p.branch_id, s.branch_id, c.branch_id) = ANY (sqlc.arg(branch_ids)::uuid[]));

-- ───── Luyện phát âm ─────

-- name: InsertPronunciation :one
INSERT INTO academic.pronunciation_records (code, student_id, unit_title, duration, audio_file_id, created_by)
VALUES ($1, $2, $3, $4, $5, $6)
RETURNING *;

-- name: StudentPronunciations :many
SELECT * FROM academic.pronunciation_records WHERE student_id = $1 ORDER BY created_at DESC, id DESC;

-- name: CountPronunciations :one
SELECT count(*)::int FROM academic.pronunciation_records WHERE student_id = $1;

-- name: GetPronunciation :one
SELECT * FROM academic.pronunciation_records WHERE id = $1;

-- name: DeletePronunciation :exec
DELETE FROM academic.pronunciation_records WHERE id = $1;

-- name: ClassPronunciations :many
-- Tab "Phát âm" của màn Chấm bài nộp: bản ghi âm của học viên trong danh sách lớp.
SELECT p.*, s.full_name AS student_name
FROM academic.pronunciation_records p
JOIN academic.students s ON s.id = p.student_id
WHERE p.student_id = ANY (sqlc.arg(student_ids)::uuid[])
ORDER BY p.created_at DESC, p.id DESC
LIMIT 300;

-- name: ReviewPronunciation :exec
UPDATE academic.pronunciation_records
SET status = 'reviewed', score = sqlc.arg(score), feedback = sqlc.narg(feedback), reviewed_by = sqlc.arg(reviewed_by),
    reviewed_at = now(), updated_at = now()
WHERE id = sqlc.arg(id);

-- ───── Góp ý chặng học ─────

-- name: UpsertStageFeedback :one
INSERT INTO academic.stage_feedbacks (code, student_id, stage_name, class_id, class_name, rating, fb_academic, fb_teacher,
                                      fb_other, content, created_by)
VALUES (sqlc.arg(code), sqlc.arg(student_id), sqlc.arg(stage_name), sqlc.narg(class_id), sqlc.narg(class_name), sqlc.arg(rating),
        sqlc.arg(fb_academic), sqlc.arg(fb_teacher), sqlc.arg(fb_other), sqlc.arg(content), sqlc.narg(created_by))
ON CONFLICT (student_id, stage_name) DO UPDATE
SET class_id = EXCLUDED.class_id, class_name = EXCLUDED.class_name, rating = EXCLUDED.rating,
    fb_academic = EXCLUDED.fb_academic, fb_teacher = EXCLUDED.fb_teacher, fb_other = EXCLUDED.fb_other,
    content = EXCLUDED.content, created_by = EXCLUDED.created_by, updated_at = now()
RETURNING *, (xmax = 0)::bool AS inserted;

-- name: StudentFeedbacks :many
SELECT * FROM academic.stage_feedbacks WHERE student_id = $1 ORDER BY updated_at DESC, id DESC;

-- name: GetStageFeedback :one
SELECT * FROM academic.stage_feedbacks WHERE id = $1;

-- name: DeleteStageFeedback :exec
DELETE FROM academic.stage_feedbacks WHERE id = $1;

-- ───── Cổng giáo viên: chấm bài nộp ─────

-- name: TeacherClasses :many
-- Lớp mình dạy / trợ giảng / GVNN; phạm vi Lớp học "Toàn hệ thống" → mọi lớp (teacherClassesQuery). Bỏ lớp đã hủy.
SELECT c.id, c.code, c.name
FROM academic.classes c
WHERE c.deleted_at IS NULL AND c.status <> 'cancelled'
  AND (sqlc.arg(all_classes)::bool OR c.teacher_id = sqlc.arg(user_id) OR c.assistant_id = sqlc.arg(user_id)
       OR c.foreign_teacher_id = sqlc.arg(user_id))
ORDER BY c.name, c.id;

-- name: ClassRoster :many
SELECT r.student_id::uuid FROM academic.class_roster r WHERE r.class_id = sqlc.arg(class_id)::uuid;

-- name: TeacherCoversStudent :one
-- Giáo viên chấm được bài của học viên: học viên thuộc (một) lớp giáo viên phụ trách (markSubmission).
SELECT EXISTS (
    SELECT 1 FROM academic.classes c
    WHERE c.deleted_at IS NULL
      AND (sqlc.arg(all_classes)::bool OR c.teacher_id = sqlc.arg(user_id) OR c.assistant_id = sqlc.arg(user_id)
           OR c.foreign_teacher_id = sqlc.arg(user_id))
      AND (c.id = (SELECT s.current_class_id FROM academic.students s WHERE s.id = sqlc.arg(student_id))
           OR c.id IN (SELECT e.class_id FROM academic.enrollments e WHERE e.student_id = sqlc.arg(student_id)
                       AND e.status IN ('pending', 'completed') AND e.left_at IS NULL))
);
