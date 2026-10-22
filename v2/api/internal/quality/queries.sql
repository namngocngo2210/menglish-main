-- Dự giờ & chất lượng lớp (ObservationController, ClassChecklistController, TeacherMeetingReportController).
-- Phạm vi lớp: all_classes (mức Toàn hệ thống) hoặc ids = lớp người dùng được xem (ClassModel::visibleTo).

-- ───────────────────────── Lớp ─────────────────────────

-- name: ActiveClasses :many
-- Lớp đang hoạt động trong phạm vi (ô chọn lớp của form dự giờ / họp giáo viên), kèm GV chính / GVNN và chương trình.
SELECT c.id, c.code::text AS code, c.name, c.teacher_id, c.foreign_teacher_id, co.name AS course_name
FROM academic.classes c
LEFT JOIN academic.courses co ON co.id = c.course_id
WHERE c.deleted_at IS NULL AND c.status = 'active'
  AND (sqlc.arg(all_classes)::bool OR c.id = ANY (sqlc.arg(ids)::uuid[]))
ORDER BY c.code;

-- name: GetClass :one
-- Lớp (kể cả đã xóa, như withTrashed) để kiểm tra form.
SELECT c.id, c.code::text AS code, c.name, c.status, c.branch_id, c.teacher_id, c.foreign_teacher_id, c.start_date, c.end_date,
       (c.deleted_at IS NOT NULL)::bool AS deleted
FROM academic.classes c WHERE c.id = $1;

-- name: CountRunningClasses :one
-- Số lớp có học trong tháng (ClassModel::runningBetween): đang hoạt động, hoặc đã kết thúc từ đầu tháng; đã khai giảng
-- trước cuối tháng (chưa có ngày khai giảng cũng tính).
SELECT count(*)::bigint
FROM academic.classes c
WHERE c.deleted_at IS NULL
  AND (sqlc.arg(all_classes)::bool OR c.id = ANY (sqlc.arg(ids)::uuid[]))
  AND (c.status = 'active' OR (c.status = 'completed' AND c.end_date >= sqlc.arg(from_date)::date))
  AND (c.start_date IS NULL OR c.start_date <= sqlc.arg(to_date)::date)
  AND (sqlc.narg(search)::text IS NULL OR c.code::text ILIKE sqlc.narg(search) OR c.name ILIKE sqlc.narg(search))
  AND (sqlc.narg(observed)::bool IS NULL OR sqlc.narg(observed)::bool = EXISTS (
        SELECT 1 FROM academic.class_observations o
        WHERE o.kind = 'academic' AND o.class_id = c.id AND o.month = sqlc.arg(month)::text AND o.observed));

-- name: RunningClasses :many
-- Lớp có học trong tháng, theo mã lớp (màn Đánh giá dự giờ / Checklist), lọc tìm kiếm và đã / chưa dự giờ.
SELECT c.id, c.code::text AS code, c.name, t.full_name AS teacher_name, co.name AS course_name
FROM academic.classes c
LEFT JOIN iam.users t ON t.id = c.teacher_id
LEFT JOIN academic.courses co ON co.id = c.course_id
WHERE c.deleted_at IS NULL
  AND (sqlc.arg(all_classes)::bool OR c.id = ANY (sqlc.arg(ids)::uuid[]))
  AND (c.status = 'active' OR (c.status = 'completed' AND c.end_date >= sqlc.arg(from_date)::date))
  AND (c.start_date IS NULL OR c.start_date <= sqlc.arg(to_date)::date)
  AND (sqlc.narg(search)::text IS NULL OR c.code::text ILIKE sqlc.narg(search) OR c.name ILIKE sqlc.narg(search))
  AND (sqlc.narg(observed)::bool IS NULL OR sqlc.narg(observed)::bool = EXISTS (
        SELECT 1 FROM academic.class_observations o
        WHERE o.kind = 'academic' AND o.class_id = c.id AND o.month = sqlc.arg(month)::text AND o.observed))
ORDER BY c.code
LIMIT sqlc.arg(lim) OFFSET sqlc.arg(off);

-- name: RunningClassIDs :many
-- Trong các lớp ids_in, lớp nào có học trong tháng và nằm trong phạm vi.
SELECT c.id
FROM academic.classes c
WHERE c.deleted_at IS NULL AND c.id = ANY (sqlc.arg(ids_in)::uuid[])
  AND (sqlc.arg(all_classes)::bool OR c.id = ANY (sqlc.arg(ids)::uuid[]))
  AND (c.status = 'active' OR (c.status = 'completed' AND c.end_date >= sqlc.arg(from_date)::date))
  AND (c.start_date IS NULL OR c.start_date <= sqlc.arg(to_date)::date);

-- name: AttendanceRates :many
-- Chuyên cần theo lớp trong khoảng ngày: (có mặt + đi muộn) / tổng lượt điểm danh.
SELECT a.class_id, count(*)::bigint AS total,
       count(*) FILTER (WHERE a.status IN ('present', 'late'))::bigint AS attended
FROM academic.student_attendance a
WHERE a.class_id = ANY (sqlc.arg(class_ids)::uuid[])
  AND a.session_date BETWEEN sqlc.arg(from_date)::date AND sqlc.arg(to_date)::date
GROUP BY a.class_id;

-- name: UserNames :many
SELECT id, full_name, coalesce(code::text, '')::text AS code FROM iam.users WHERE id = ANY (sqlc.arg(ids)::uuid[]);

-- name: UserExists :one
SELECT EXISTS (SELECT 1 FROM iam.users WHERE id = $1)::bool;

-- ───────────────────────── Dự giờ vận hành ─────────────────────────

-- name: CountOperations :one
SELECT count(*)::bigint
FROM academic.class_observations o
WHERE o.kind = 'operations' AND o.deleted_at IS NULL
  AND (sqlc.arg(all_classes)::bool OR o.class_id = ANY (sqlc.arg(ids)::uuid[]))
  AND (sqlc.narg(teacher_id)::uuid IS NULL OR o.teacher_id = sqlc.narg(teacher_id))
  AND (sqlc.narg(rating)::text IS NULL OR o.rating = sqlc.narg(rating));

-- name: ListOperations :many
-- Lượt dự giờ vận hành mới nhất trước (latest observed_on, latest id).
SELECT o.id, o.observed_on, o.teacher_id, o.class_id, o.rating,
       o.lesson_content, o.attitude, o.preparation, o.technique, o.suggestions,
       t.full_name AS teacher_name, coalesce(t.code::text, '')::text AS teacher_code,
       c.code::text AS class_code, c.name AS class_name, b.name AS branch_name, ob.full_name AS observer_name
FROM academic.class_observations o
JOIN academic.classes c ON c.id = o.class_id
LEFT JOIN core.branches b ON b.id = c.branch_id
LEFT JOIN iam.users t ON t.id = o.teacher_id
LEFT JOIN iam.users ob ON ob.id = o.observer_id
WHERE o.kind = 'operations' AND o.deleted_at IS NULL
  AND (sqlc.arg(all_classes)::bool OR o.class_id = ANY (sqlc.arg(ids)::uuid[]))
  AND (sqlc.narg(teacher_id)::uuid IS NULL OR o.teacher_id = sqlc.narg(teacher_id))
  AND (sqlc.narg(rating)::text IS NULL OR o.rating = sqlc.narg(rating))
ORDER BY o.observed_on DESC, o.id DESC
LIMIT sqlc.arg(lim) OFFSET sqlc.arg(off);

-- name: ObservedTeachers :many
-- Giáo viên đã có lượt dự giờ vận hành ở lớp trong phạm vi (bộ lọc "Giáo viên", không lộ GV chi nhánh khác).
SELECT DISTINCT u.id, u.full_name
FROM academic.class_observations o
JOIN iam.users u ON u.id = o.teacher_id
WHERE o.kind = 'operations' AND o.deleted_at IS NULL
  AND (sqlc.arg(all_classes)::bool OR o.class_id = ANY (sqlc.arg(ids)::uuid[]))
ORDER BY u.full_name, u.id;

-- name: GetOperation :one
SELECT o.id, o.class_id, o.teacher_id, o.observed_on, o.rating, c.code::text AS class_code, t.full_name AS teacher_name
FROM academic.class_observations o
JOIN academic.classes c ON c.id = o.class_id
LEFT JOIN iam.users t ON t.id = o.teacher_id
WHERE o.id = $1 AND o.kind = 'operations' AND o.deleted_at IS NULL;

-- name: CreateOperation :one
INSERT INTO academic.class_observations (
    kind, class_id, teacher_id, observer_id, observed_on, rating,
    lesson_content, attitude, preparation, technique, suggestions, created_by, updated_by
) VALUES (
    'operations', sqlc.arg(class_id), sqlc.arg(teacher_id), sqlc.arg(observer_id), sqlc.arg(observed_on), sqlc.arg(rating),
    sqlc.narg(lesson_content), sqlc.narg(attitude), sqlc.narg(preparation), sqlc.narg(technique), sqlc.narg(suggestions),
    sqlc.arg(observer_id), sqlc.arg(observer_id)
) RETURNING id;

-- name: UpdateOperation :exec
UPDATE academic.class_observations SET
    class_id = sqlc.arg(class_id), teacher_id = sqlc.arg(teacher_id), observed_on = sqlc.arg(observed_on),
    rating = sqlc.arg(rating), lesson_content = sqlc.narg(lesson_content), attitude = sqlc.narg(attitude),
    preparation = sqlc.narg(preparation), technique = sqlc.narg(technique), suggestions = sqlc.narg(suggestions),
    updated_by = sqlc.arg(updated_by), updated_at = now()
WHERE id = sqlc.arg(id) AND kind = 'operations' AND deleted_at IS NULL;

-- name: DeleteOperation :exec
UPDATE academic.class_observations SET deleted_at = now(), updated_at = now()
WHERE id = $1 AND kind = 'operations' AND deleted_at IS NULL;

-- ───────────────────────── Đánh giá dự giờ học thuật ─────────────────────────

-- name: AcademicEvaluations :many
SELECT o.class_id, o.observed, o.observed_on, o.observer_id, o.attendance_rate, o.pass_rate, o.action_notes,
       o.teaching_quality, o.lesson_content, o.interaction, o.attitude, o.effectiveness, o.overall,
       ob.full_name AS observer_name
FROM academic.class_observations o
LEFT JOIN iam.users ob ON ob.id = o.observer_id
WHERE o.kind = 'academic' AND o.month = sqlc.arg(month)::text AND o.class_id = ANY (sqlc.arg(class_ids)::uuid[]);

-- name: CountObservedAcademic :one
-- Lớp có học trong tháng (trong phạm vi) đã dự giờ.
SELECT count(*)::bigint
FROM academic.class_observations o
JOIN academic.classes c ON c.id = o.class_id
WHERE o.kind = 'academic' AND o.month = sqlc.arg(month)::text AND o.observed
  AND c.deleted_at IS NULL
  AND (sqlc.arg(all_classes)::bool OR c.id = ANY (sqlc.arg(ids)::uuid[]))
  AND (c.status = 'active' OR (c.status = 'completed' AND c.end_date >= sqlc.arg(from_date)::date))
  AND (c.start_date IS NULL OR c.start_date <= sqlc.arg(to_date)::date);

-- name: GetAcademicEvaluation :one
SELECT * FROM academic.class_observations WHERE kind = 'academic' AND class_id = $1 AND month = $2;

-- name: UpsertAcademicEvaluation :one
-- Mỗi lớp một đánh giá / tháng (updateOrCreate theo class_id + month).
INSERT INTO academic.class_observations (
    kind, class_id, month, observed, observed_on, observer_id, attendance_rate, pass_rate, action_notes,
    teaching_quality, lesson_content, interaction, attitude, effectiveness, overall, created_by, updated_by
) VALUES (
    'academic', sqlc.arg(class_id), sqlc.arg(month), sqlc.arg(observed), sqlc.narg(observed_on), sqlc.narg(observer_id),
    sqlc.narg(attendance_rate), sqlc.narg(pass_rate), sqlc.narg(action_notes), sqlc.narg(teaching_quality),
    sqlc.narg(lesson_content), sqlc.narg(interaction), sqlc.narg(attitude), sqlc.narg(effectiveness), sqlc.narg(overall),
    sqlc.arg(updated_by), sqlc.arg(updated_by)
)
ON CONFLICT (class_id, month) WHERE kind = 'academic' DO UPDATE SET
    observed = EXCLUDED.observed, observed_on = EXCLUDED.observed_on, observer_id = EXCLUDED.observer_id,
    attendance_rate = EXCLUDED.attendance_rate, pass_rate = EXCLUDED.pass_rate, action_notes = EXCLUDED.action_notes,
    teaching_quality = EXCLUDED.teaching_quality, lesson_content = EXCLUDED.lesson_content,
    interaction = EXCLUDED.interaction, attitude = EXCLUDED.attitude, effectiveness = EXCLUDED.effectiveness,
    overall = EXCLUDED.overall, updated_by = EXCLUDED.updated_by, updated_at = now()
RETURNING id, (xmax = 0)::bool AS inserted;

-- ───────────────────────── Checklist học phí & feedback ─────────────────────────

-- name: Checklists :many
SELECT * FROM academic.class_checklists WHERE month = sqlc.arg(month)::text AND class_id = ANY (sqlc.arg(class_ids)::uuid[]);

-- name: UpsertChecklist :exec
INSERT INTO academic.class_checklists (
    class_id, month, tuition_due, tuition_reminded, tuition_collected, tuition_note,
    big_test_due, feedback_on_time, negative_feedback_handled, updated_by
) VALUES (
    sqlc.arg(class_id), sqlc.arg(month), sqlc.narg(tuition_due), sqlc.narg(tuition_reminded), sqlc.narg(tuition_collected),
    sqlc.narg(tuition_note), sqlc.narg(big_test_due), sqlc.narg(feedback_on_time), sqlc.narg(negative_feedback_handled),
    sqlc.arg(updated_by)
)
ON CONFLICT (class_id, month) DO UPDATE SET
    tuition_due = EXCLUDED.tuition_due, tuition_reminded = EXCLUDED.tuition_reminded,
    tuition_collected = EXCLUDED.tuition_collected, tuition_note = EXCLUDED.tuition_note,
    big_test_due = EXCLUDED.big_test_due, feedback_on_time = EXCLUDED.feedback_on_time,
    negative_feedback_handled = EXCLUDED.negative_feedback_handled, updated_by = EXCLUDED.updated_by, updated_at = now();

-- ───────────────────────── Họp giáo viên ─────────────────────────

-- name: CountMeetings :one
SELECT count(*)::bigint
FROM academic.teacher_meeting_reports m
WHERE m.deleted_at IS NULL
  AND (sqlc.arg(all_classes)::bool OR m.class_id = ANY (sqlc.arg(ids)::uuid[])
       OR (m.class_id IS NULL AND (m.author_id = sqlc.arg(me)::uuid
           OR EXISTS (SELECT 1 FROM iam.user_branches ub WHERE ub.user_id = m.teacher_id AND ub.branch_id = ANY (sqlc.arg(branches)::uuid[])))))
  AND (sqlc.narg(week)::date IS NULL OR m.week_start = sqlc.narg(week))
  AND (sqlc.narg(teacher_id)::uuid IS NULL OR m.teacher_id = sqlc.narg(teacher_id))
  AND (sqlc.narg(status)::text IS NULL OR m.status = sqlc.narg(status));

-- name: ListMeetings :many
SELECT m.id, m.week_start, m.teacher_id, m.class_id, m.syllabus_note, m.scores_note, m.class_note, m.academic_order,
       m.recommendation, m.status, t.full_name AS teacher_name, a.full_name AS author_name,
       c.code AS class_code, c.name AS class_name, co.name AS course_name
FROM academic.teacher_meeting_reports m
LEFT JOIN iam.users t ON t.id = m.teacher_id
LEFT JOIN iam.users a ON a.id = m.author_id
LEFT JOIN academic.classes c ON c.id = m.class_id
LEFT JOIN academic.courses co ON co.id = c.course_id
WHERE m.deleted_at IS NULL
  AND (sqlc.arg(all_classes)::bool OR m.class_id = ANY (sqlc.arg(ids)::uuid[])
       OR (m.class_id IS NULL AND (m.author_id = sqlc.arg(me)::uuid
           OR EXISTS (SELECT 1 FROM iam.user_branches ub WHERE ub.user_id = m.teacher_id AND ub.branch_id = ANY (sqlc.arg(branches)::uuid[])))))
  AND (sqlc.narg(week)::date IS NULL OR m.week_start = sqlc.narg(week))
  AND (sqlc.narg(teacher_id)::uuid IS NULL OR m.teacher_id = sqlc.narg(teacher_id))
  AND (sqlc.narg(status)::text IS NULL OR m.status = sqlc.narg(status))
ORDER BY m.week_start DESC, m.id DESC
LIMIT sqlc.arg(lim) OFFSET sqlc.arg(off);

-- name: GetMeeting :one
SELECT m.id, m.week_start, m.teacher_id, m.class_id, m.author_id, m.status, t.full_name AS teacher_name
FROM academic.teacher_meeting_reports m
LEFT JOIN iam.users t ON t.id = m.teacher_id
WHERE m.id = $1 AND m.deleted_at IS NULL;

-- name: CreateMeeting :one
INSERT INTO academic.teacher_meeting_reports (
    week_start, teacher_id, class_id, author_id, syllabus_note, scores_note, class_note, academic_order, recommendation, status
) VALUES (
    sqlc.arg(week_start), sqlc.arg(teacher_id), sqlc.narg(class_id), sqlc.arg(author_id), sqlc.narg(syllabus_note),
    sqlc.narg(scores_note), sqlc.arg(class_note), sqlc.narg(academic_order), sqlc.narg(recommendation), sqlc.arg(status)
) RETURNING id;

-- name: UpdateMeeting :exec
UPDATE academic.teacher_meeting_reports SET
    week_start = sqlc.arg(week_start), teacher_id = sqlc.arg(teacher_id), class_id = sqlc.narg(class_id),
    syllabus_note = sqlc.narg(syllabus_note), scores_note = sqlc.narg(scores_note), class_note = sqlc.arg(class_note),
    academic_order = sqlc.narg(academic_order), recommendation = sqlc.narg(recommendation), status = sqlc.arg(status),
    updated_at = now()
WHERE id = sqlc.arg(id) AND deleted_at IS NULL;

-- name: DeleteMeeting :exec
UPDATE academic.teacher_meeting_reports SET deleted_at = now(), updated_at = now() WHERE id = $1 AND deleted_at IS NULL;

-- name: MeetingsByAuthor :many
-- Buổi họp người viết đã ghi có tuần chạm vào khoảng (báo cáo tháng Học thuật).
SELECT m.id, m.week_start, m.status, t.full_name AS teacher_name
FROM academic.teacher_meeting_reports m
LEFT JOIN iam.users t ON t.id = m.teacher_id
WHERE m.deleted_at IS NULL AND m.author_id = sqlc.arg(author_id)
  AND m.week_start BETWEEN sqlc.arg(from_date)::date AND sqlc.arg(to_date)::date
ORDER BY m.week_start DESC, m.id DESC;

-- ───────────────────────── Số liệu cho báo cáo ─────────────────────────

-- name: ObservationsBetween :many
-- Phiếu dự giờ (vận hành + học thuật) có ngày dự trong khoảng, của các lớp người viết thấy, chỉ trong các chi nhánh
-- của người viết (rỗng = không lọc) — BuildsReportItems::classIds. Vận hành trước như Laravel.
SELECT o.id, o.kind, o.class_id, o.observed_on, o.rating, o.overall, c.name AS class_name, t.full_name AS teacher_name
FROM academic.class_observations o
JOIN academic.classes c ON c.id = o.class_id AND c.deleted_at IS NULL
LEFT JOIN iam.users t ON t.id = o.teacher_id
WHERE o.deleted_at IS NULL AND o.observed_on BETWEEN sqlc.arg(from_date)::date AND sqlc.arg(to_date)::date
  AND (sqlc.arg(all_classes)::bool OR o.class_id = ANY (sqlc.arg(ids)::uuid[]))
  AND (cardinality(sqlc.arg(branch_ids)::uuid[]) = 0 OR c.branch_id = ANY (sqlc.arg(branch_ids)::uuid[]))
ORDER BY o.kind DESC, o.observed_on, o.id;

-- name: ObservationsByObserver :many
-- Phiếu dự giờ người này dự trong khoảng (dòng gợi ý "đã làm" của báo cáo tuần Học thuật).
SELECT o.id, o.kind, o.class_id, o.observed_on, o.rating, o.overall, c.name AS class_name, t.full_name AS teacher_name
FROM academic.class_observations o
JOIN academic.classes c ON c.id = o.class_id
LEFT JOIN iam.users t ON t.id = o.teacher_id
WHERE o.deleted_at IS NULL AND o.observer_id = sqlc.arg(observer_id)
  AND o.observed_on BETWEEN sqlc.arg(from_date)::date AND sqlc.arg(to_date)::date
ORDER BY o.kind DESC, o.observed_on, o.id;
