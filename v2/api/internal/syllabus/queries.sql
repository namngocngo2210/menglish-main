-- Phân hệ Giáo trình & Big Test (SyllabusController, TeacherPortalController::orderTest, SyllabusProgressionService,
-- ScheduleExtensionService, BigTestSlaService, AdjustmentSlaService). Lớp / buổi / học viên đọc trực tiếp bảng academic.*;
-- phạm vi lớp lấy từ phân hệ Lớp học (Classes.VisibleClassIDs).

-- name: LockKey :exec
SELECT pg_advisory_xact_lock(hashtext(sqlc.arg(key)::text));

-- ───────────────────────── Giáo trình ─────────────────────────

-- name: ListCurricula :many
SELECT c.id, c.code::text AS code, c.title, c.version, c.course_id, co.name AS course_name, c.description
FROM syllabus.curricula c
LEFT JOIN academic.courses co ON co.id = c.course_id
WHERE c.deleted_at IS NULL
ORDER BY c.title, c.id;

-- name: GetCurriculum :one
SELECT id, code::text AS code, title, version, course_id, description
FROM syllabus.curricula WHERE id = $1 AND deleted_at IS NULL;

-- name: CurriculumCodeTaken :one
SELECT EXISTS (SELECT 1 FROM syllabus.curricula WHERE code = sqlc.arg(code)::text AND id IS DISTINCT FROM sqlc.narg(exclude)::uuid);

-- name: CreateCurriculum :one
INSERT INTO syllabus.curricula (code, title, course_id, version, description, created_by)
VALUES (sqlc.arg(code)::text, sqlc.arg(title), sqlc.narg(course_id), sqlc.arg(version), sqlc.narg(description), sqlc.narg(created_by))
RETURNING id;

-- name: UpdateCurriculum :exec
UPDATE syllabus.curricula SET code = sqlc.arg(code)::text, title = sqlc.arg(title), course_id = sqlc.narg(course_id),
    version = sqlc.arg(version), description = sqlc.narg(description), updated_at = now()
WHERE id = sqlc.arg(id);

-- name: SoftDeleteCurriculum :exec
WITH docs AS (UPDATE syllabus.documents SET deleted_at = now() WHERE curriculum_id = $1::uuid AND deleted_at IS NULL),
     les AS (UPDATE syllabus.lessons SET deleted_at = now() WHERE curriculum_id = $1::uuid AND deleted_at IS NULL),
     uni AS (UPDATE syllabus.units SET deleted_at = now() WHERE curriculum_id = $1::uuid AND deleted_at IS NULL),
     stg AS (UPDATE syllabus.stages SET deleted_at = now() WHERE curriculum_id = $1::uuid AND deleted_at IS NULL),
     lvl AS (UPDATE academic.course_levels SET syllabus_curriculum_id = NULL WHERE syllabus_curriculum_id = $1::uuid)
UPDATE syllabus.curricula SET deleted_at = now() WHERE id = $1::uuid;

-- name: OpenAssignmentsOfCurriculum :one
SELECT count(*) FROM syllabus.assignments WHERE curriculum_id = $1 AND status = 'in_progress';

-- name: ListCourses :many
SELECT id, name FROM academic.courses WHERE deleted_at IS NULL ORDER BY name;

-- name: CourseExists :one
SELECT EXISTS (SELECT 1 FROM academic.courses WHERE id = $1 AND deleted_at IS NULL);

-- name: ListLevels :many
SELECT id, code::text AS code, name, syllabus_curriculum_id FROM academic.course_levels WHERE deleted_at IS NULL ORDER BY name;

-- name: CountLevels :one
SELECT count(*) FROM academic.course_levels WHERE id = ANY (sqlc.arg(ids)::uuid[]) AND deleted_at IS NULL;

-- name: DetachLevels :exec
UPDATE academic.course_levels SET syllabus_curriculum_id = NULL, updated_at = now()
WHERE syllabus_curriculum_id = sqlc.arg(curriculum_id) AND NOT (id = ANY (sqlc.arg(keep)::uuid[]));

-- name: AttachLevels :exec
UPDATE academic.course_levels SET syllabus_curriculum_id = sqlc.arg(curriculum_id), updated_at = now()
WHERE id = ANY (sqlc.arg(ids)::uuid[]);

-- name: CurriculumOfLevel :many
-- Giáo trình theo trình độ của lớp: classes.level là mã trình độ (ưu tiên, rank 0); lớp tạo nơi khác lấy trình độ của
-- khóa học (rank 1).
SELECT 0 AS rank, l.syllabus_curriculum_id FROM academic.course_levels l
WHERE l.code = sqlc.arg(level)::text AND l.deleted_at IS NULL AND l.syllabus_curriculum_id IS NOT NULL
UNION ALL
SELECT 1 AS rank, l.syllabus_curriculum_id FROM academic.courses co JOIN academic.course_levels l ON l.id = co.course_level_id
WHERE co.id = sqlc.narg(course_id)::uuid AND l.syllabus_curriculum_id IS NOT NULL
ORDER BY rank;

-- name: LevelCurricula :many
SELECT code::text AS code, syllabus_curriculum_id FROM academic.course_levels
WHERE syllabus_curriculum_id IS NOT NULL AND deleted_at IS NULL;

-- ───────────────────────── Chặng / Unit / Buổi ─────────────────────────

-- name: ListStages :many
SELECT s.id, s.curriculum_id, s.position, s.name, s.description, s.overview_link, s.big_test_title, s.big_test_note
FROM syllabus.stages s
WHERE s.curriculum_id = ANY (sqlc.arg(curriculum_ids)::uuid[]) AND s.deleted_at IS NULL
ORDER BY s.curriculum_id, s.position, s.id;

-- name: GetStage :one
SELECT id, curriculum_id, position, name, description, overview_link, big_test_title, big_test_note
FROM syllabus.stages WHERE id = $1 AND deleted_at IS NULL;

-- name: MaxStagePosition :one
SELECT coalesce(max(position), 0)::int FROM syllabus.stages WHERE curriculum_id = $1 AND deleted_at IS NULL;

-- name: CreateStage :one
INSERT INTO syllabus.stages (curriculum_id, position, name, description, overview_link, big_test_title, big_test_note)
VALUES (sqlc.arg(curriculum_id), sqlc.arg(position), sqlc.arg(name), sqlc.narg(description), sqlc.narg(overview_link),
        sqlc.narg(big_test_title), sqlc.narg(big_test_note))
RETURNING id;

-- name: UpdateStage :exec
UPDATE syllabus.stages SET name = sqlc.arg(name), description = sqlc.narg(description), overview_link = sqlc.narg(overview_link),
    big_test_title = sqlc.narg(big_test_title), big_test_note = sqlc.narg(big_test_note), updated_at = now()
WHERE id = sqlc.arg(id);

-- name: SetStagePosition :exec
UPDATE syllabus.stages SET position = sqlc.arg(position), updated_at = now() WHERE id = sqlc.arg(id);

-- name: StageUsage :one
SELECT (SELECT count(*) FROM syllabus.stages x WHERE x.curriculum_id = s.curriculum_id AND x.deleted_at IS NULL)::int AS stages,
       (SELECT count(*) FROM syllabus.units u WHERE u.stage_id = s.id AND u.deleted_at IS NULL)::int AS units,
       (SELECT count(*) FROM syllabus.assignments a WHERE a.stage_id = s.id)::int AS assignments,
       (SELECT count(*) FROM syllabus.big_tests b WHERE b.stage_id = s.id)::int AS big_tests
FROM syllabus.stages s WHERE s.id = $1;

-- name: SoftDeleteStage :exec
WITH docs AS (UPDATE syllabus.documents SET stage_id = NULL WHERE stage_id = $1::uuid)
UPDATE syllabus.stages SET deleted_at = now() WHERE id = $1::uuid;

-- name: LastStageID :one
SELECT id FROM syllabus.stages WHERE curriculum_id = $1 AND deleted_at IS NULL ORDER BY position DESC, id DESC LIMIT 1;

-- name: FirstStageID :one
SELECT id FROM syllabus.stages WHERE curriculum_id = $1 AND deleted_at IS NULL ORDER BY position, id LIMIT 1;

-- name: NextStageID :one
-- Chặng kế tiếp trong cùng giáo trình (theo position, rồi id).
SELECT n.id FROM syllabus.stages s
JOIN syllabus.stages n ON n.curriculum_id = s.curriculum_id AND n.deleted_at IS NULL
    AND (n.position > s.position OR (n.position = s.position AND n.id > s.id))
WHERE s.id = $1
ORDER BY n.position, n.id LIMIT 1;

-- name: StageRange :one
-- Phạm vi Unit / Buổi của chặng ("Unit 1–3 · Buổi 1–12").
SELECT coalesce(min(u.unit_number), 0)::int AS unit_min, coalesce(max(u.unit_number), 0)::int AS unit_max,
       coalesce((SELECT min(l.session_no) FROM syllabus.lessons l JOIN syllabus.units x ON x.id = l.unit_id
        WHERE x.stage_id = sqlc.arg(stage_id) AND x.deleted_at IS NULL AND l.deleted_at IS NULL), 0)::int AS session_min,
       coalesce((SELECT max(l.session_no) FROM syllabus.lessons l JOIN syllabus.units x ON x.id = l.unit_id
        WHERE x.stage_id = sqlc.arg(stage_id) AND x.deleted_at IS NULL AND l.deleted_at IS NULL), 0)::int AS session_max
FROM syllabus.units u WHERE u.stage_id = sqlc.arg(stage_id) AND u.deleted_at IS NULL;

-- name: ListUnits :many
SELECT id, curriculum_id, stage_id, unit_number, title, objectives, vocabulary_focus, grammar_focus, homework_guide
FROM syllabus.units WHERE curriculum_id = $1 AND deleted_at IS NULL ORDER BY unit_number, id;

-- name: GetUnit :one
SELECT id, curriculum_id, stage_id, unit_number, title, objectives, vocabulary_focus, grammar_focus, homework_guide
FROM syllabus.units WHERE id = $1 AND deleted_at IS NULL;

-- name: UnitNumberTaken :one
SELECT EXISTS (SELECT 1 FROM syllabus.units WHERE curriculum_id = sqlc.arg(curriculum_id) AND unit_number = sqlc.arg(unit_number)
    AND deleted_at IS NULL AND id IS DISTINCT FROM sqlc.narg(exclude)::uuid);

-- name: CreateUnit :one
INSERT INTO syllabus.units (curriculum_id, stage_id, unit_number, title, objectives, vocabulary_focus, grammar_focus, homework_guide)
VALUES (sqlc.arg(curriculum_id), sqlc.narg(stage_id), sqlc.arg(unit_number), sqlc.arg(title), sqlc.narg(objectives),
        sqlc.narg(vocabulary_focus), sqlc.narg(grammar_focus), sqlc.narg(homework_guide))
RETURNING id;

-- name: UpdateUnit :exec
UPDATE syllabus.units SET stage_id = coalesce(sqlc.narg(stage_id), stage_id), unit_number = sqlc.arg(unit_number), title = sqlc.arg(title),
    objectives = sqlc.narg(objectives), vocabulary_focus = sqlc.narg(vocabulary_focus), grammar_focus = sqlc.narg(grammar_focus),
    homework_guide = sqlc.narg(homework_guide), updated_at = now()
WHERE id = sqlc.arg(id);

-- name: SoftDeleteUnit :one
WITH les AS (UPDATE syllabus.lessons SET deleted_at = now() WHERE unit_id = $1::uuid AND deleted_at IS NULL RETURNING 1)
UPDATE syllabus.units SET deleted_at = now() WHERE id = $1::uuid RETURNING (SELECT count(*) FROM les)::int AS lessons;

-- name: ListLessons :many
SELECT l.id, l.curriculum_id, l.unit_id, l.session_no, l.title, l.objectives, l.content, l.vocabulary_focus, l.grammar_focus,
       l.homework_guide, u.unit_number, u.stage_id
FROM syllabus.lessons l JOIN syllabus.units u ON u.id = l.unit_id
WHERE l.curriculum_id = ANY (sqlc.arg(curriculum_ids)::uuid[]) AND l.deleted_at IS NULL
ORDER BY l.curriculum_id, l.session_no;

-- name: GetLesson :one
SELECT id, curriculum_id, unit_id, session_no, title, objectives, content, vocabulary_focus, grammar_focus, homework_guide
FROM syllabus.lessons WHERE id = $1 AND deleted_at IS NULL;

-- name: SessionNoTaken :one
SELECT EXISTS (SELECT 1 FROM syllabus.lessons WHERE curriculum_id = sqlc.arg(curriculum_id) AND session_no = sqlc.arg(session_no)
    AND deleted_at IS NULL AND id IS DISTINCT FROM sqlc.narg(exclude)::uuid);

-- name: CreateLesson :one
INSERT INTO syllabus.lessons (curriculum_id, unit_id, session_no, title, objectives, content, vocabulary_focus, grammar_focus, homework_guide)
VALUES (sqlc.arg(curriculum_id), sqlc.arg(unit_id), sqlc.arg(session_no), sqlc.arg(title), sqlc.narg(objectives), sqlc.narg(content),
        sqlc.narg(vocabulary_focus), sqlc.narg(grammar_focus), sqlc.narg(homework_guide))
RETURNING id;

-- name: UpdateLesson :exec
UPDATE syllabus.lessons SET unit_id = coalesce(sqlc.narg(unit_id), unit_id), session_no = sqlc.arg(session_no), title = sqlc.arg(title),
    objectives = sqlc.narg(objectives), content = sqlc.narg(content), vocabulary_focus = sqlc.narg(vocabulary_focus),
    grammar_focus = sqlc.narg(grammar_focus), homework_guide = sqlc.narg(homework_guide), updated_at = now()
WHERE id = sqlc.arg(id);

-- name: SoftDeleteLesson :exec
UPDATE syllabus.lessons SET deleted_at = now() WHERE id = $1;

-- name: StageLessons :many
-- Buổi của một chặng theo số buổi (vị trí dạy của lớp).
SELECT l.id, l.session_no, l.title, l.objectives, l.content, l.vocabulary_focus, l.grammar_focus, l.homework_guide, l.unit_id,
       u.unit_number, u.title AS unit_title
FROM syllabus.lessons l JOIN syllabus.units u ON u.id = l.unit_id
WHERE u.stage_id = $1 AND u.deleted_at IS NULL AND l.deleted_at IS NULL
ORDER BY l.session_no;

-- name: OpenClassesByStage :many
SELECT a.stage_id, c.name AS class_name
FROM syllabus.assignments a JOIN academic.classes c ON c.id = a.class_id
WHERE a.curriculum_id = $1 AND a.status = 'in_progress' AND a.stage_id IS NOT NULL
ORDER BY c.name;

-- ───────────────────────── Tài liệu ─────────────────────────

-- name: ListDocuments :many
SELECT d.id, d.title, d.curriculum_id, cu.title AS curriculum_title, d.stage_id, s.position AS stage_position, s.name AS stage_title,
       d.stage_name, d.visible_to_teachers, d.visible_to_assistants, d.downloadable, d.created_at,
       f.id AS file_id, f.extension, f.size_bytes, f.original_name
FROM syllabus.documents d
JOIN syllabus.curricula cu ON cu.id = d.curriculum_id
JOIN core.files f ON f.id = d.file_id
LEFT JOIN syllabus.stages s ON s.id = d.stage_id AND s.deleted_at IS NULL
WHERE d.deleted_at IS NULL
  AND (sqlc.arg(manage)::bool OR (sqlc.arg(teacher)::bool AND d.visible_to_teachers) OR (sqlc.arg(assistant)::bool AND d.visible_to_assistants))
  AND (sqlc.narg(curriculum_id)::uuid IS NULL OR d.curriculum_id = sqlc.narg(curriculum_id))
  AND (sqlc.narg(search)::text IS NULL OR d.title ILIKE sqlc.narg(search) OR f.original_name ILIKE sqlc.narg(search))
ORDER BY d.created_at DESC, d.id DESC
LIMIT sqlc.arg(lim) OFFSET sqlc.arg(off);

-- name: CountDocuments :one
SELECT count(*) FROM syllabus.documents d JOIN core.files f ON f.id = d.file_id
WHERE d.deleted_at IS NULL
  AND (sqlc.arg(manage)::bool OR (sqlc.arg(teacher)::bool AND d.visible_to_teachers) OR (sqlc.arg(assistant)::bool AND d.visible_to_assistants))
  AND (sqlc.narg(curriculum_id)::uuid IS NULL OR d.curriculum_id = sqlc.narg(curriculum_id))
  AND (sqlc.narg(search)::text IS NULL OR d.title ILIKE sqlc.narg(search) OR f.original_name ILIKE sqlc.narg(search));

-- name: GetDocument :one
SELECT d.id, d.title, d.curriculum_id, cu.title AS curriculum_title, d.stage_id, s.position AS stage_position, s.name AS stage_title,
       d.stage_name, d.visible_to_teachers, d.visible_to_assistants, d.downloadable, d.file_id,
       f.extension, f.size_bytes, f.original_name
FROM syllabus.documents d
JOIN syllabus.curricula cu ON cu.id = d.curriculum_id
JOIN core.files f ON f.id = d.file_id
LEFT JOIN syllabus.stages s ON s.id = d.stage_id AND s.deleted_at IS NULL
WHERE d.id = $1 AND d.deleted_at IS NULL;

-- name: DocumentByFile :one
SELECT id, visible_to_teachers, visible_to_assistants, downloadable FROM syllabus.documents WHERE file_id = $1 AND deleted_at IS NULL;

-- name: CreateDocument :one
INSERT INTO syllabus.documents (id, curriculum_id, stage_id, title, stage_name, file_id, visible_to_teachers, visible_to_assistants,
                                downloadable, uploaded_by)
VALUES (sqlc.arg(id), sqlc.arg(curriculum_id), sqlc.narg(stage_id), sqlc.arg(title), sqlc.narg(stage_name), sqlc.arg(file_id),
        sqlc.arg(visible_to_teachers), sqlc.arg(visible_to_assistants), sqlc.arg(downloadable), sqlc.narg(uploaded_by))
RETURNING id;

-- name: SoftDeleteDocument :exec
UPDATE syllabus.documents SET deleted_at = now() WHERE id = $1;

-- name: MarkDocumentViewed :exec
INSERT INTO syllabus.document_views (document_id, user_id) VALUES ($1, $2)
ON CONFLICT (document_id, user_id) DO UPDATE SET viewed_at = now();

-- name: ViewedDocumentIDs :many
SELECT document_id FROM syllabus.document_views WHERE user_id = $1;

-- ───────────────────────── Lớp (đọc từ phân hệ Lớp học) ─────────────────────────

-- name: ClassInfo :one
SELECT c.id, c.code::text AS code, c.name, c.branch_id, b.name AS branch_name, c.level, c.course_id, co.name AS course_name,
       c.teacher_id, c.foreign_teacher_id, c.assistant_id, c.room, c.status, c.end_date, t.full_name AS teacher_name
FROM academic.classes c
JOIN core.branches b ON b.id = c.branch_id
LEFT JOIN academic.courses co ON co.id = c.course_id
LEFT JOIN iam.users t ON t.id = c.teacher_id
WHERE c.id = $1 AND c.deleted_at IS NULL;

-- name: ClassesByIDs :many
SELECT c.id, c.code::text AS code, c.name, c.level, c.course_id, c.status, c.branch_id, c.teacher_id, c.foreign_teacher_id, c.assistant_id
FROM academic.classes c
WHERE c.id = ANY (sqlc.arg(ids)::uuid[]) AND c.deleted_at IS NULL
  AND (NOT sqlc.arg(skip_cancelled)::bool OR c.status <> 'cancelled')
ORDER BY c.name, c.id;

-- name: TeacherOptions :many
SELECT u.id, u.full_name, coalesce(u.code::text, '')::text AS code FROM iam.users u
WHERE u.status = 'active' AND EXISTS (SELECT 1 FROM iam.user_roles r WHERE r.user_id = u.id
    AND r.role IN ('teacher_fulltime', 'teacher_parttime', 'academic_lead'))
ORDER BY u.full_name;

-- name: UserExists :one
SELECT EXISTS (SELECT 1 FROM iam.users WHERE id = $1);

-- name: UserName :one
SELECT full_name FROM iam.users WHERE id = $1;

-- name: TaughtSessions :one
-- Buổi đã dạy của lớp từ ngày mở chặng: chính khóa / học bù không hủy, trước hôm nay.
SELECT count(*)::int FROM academic.class_sessions
WHERE class_id = sqlc.arg(class_id) AND type IN ('regular', 'makeup') AND status <> 'cancelled'
  AND date < sqlc.arg(today)::date AND (sqlc.narg(since)::date IS NULL OR date >= sqlc.narg(since)::date);

-- name: ClassRoster :many
-- Danh sách lớp thật (lớp chính + lớp liên kết) và học viên đã có kết quả của đợt thi.
SELECT s.id, s.code::text AS code, s.full_name
FROM academic.students s
WHERE s.deleted_at IS NULL AND (
    s.id IN (SELECT r.student_id FROM academic.class_roster r WHERE r.class_id = sqlc.arg(class_id))
    OR s.id IN (SELECT x.student_id FROM syllabus.big_test_results x WHERE x.big_test_id = sqlc.narg(big_test_id)::uuid))
ORDER BY s.full_name, s.id;

-- name: RosterIDs :many
SELECT DISTINCT r.student_id FROM academic.class_roster r WHERE r.class_id = $1;

-- name: ReminderStudents :many
-- Học viên đang học / bảo lưu của lớp (classModel->students()).
SELECT id, full_name, user_id FROM academic.students
WHERE current_class_id = $1 AND deleted_at IS NULL AND status IN ('studying', 'deferred')
ORDER BY full_name;

-- ───────────────────────── Chặng của lớp ─────────────────────────

-- name: ListAssignments :many
SELECT a.id, a.no, a.class_id, c.name AS class_name, c.code::text AS class_code, b.name AS branch_name, c.room AS class_room,
       cu.title AS curriculum_title, a.user_id, t.full_name AS teacher_name, coalesce(t.code::text, '')::text AS teacher_code,
       s.position AS stage_position, s.name AS stage_title, a.stage_name, a.assigned_chapters, a.extra_sessions,
       a.opened_at, a.created_at, a.closed_at, a.close_reason, a.deadline, a.status, a.curriculum_completed_at,
       bt.code AS closing_big_test
FROM syllabus.assignments a
LEFT JOIN academic.classes c ON c.id = a.class_id
LEFT JOIN core.branches b ON b.id = c.branch_id
LEFT JOIN syllabus.curricula cu ON cu.id = a.curriculum_id
LEFT JOIN iam.users t ON t.id = a.user_id
LEFT JOIN syllabus.stages s ON s.id = a.stage_id
LEFT JOIN syllabus.big_tests bt ON bt.id = a.closed_by_big_test_id
WHERE a.class_id = ANY (sqlc.arg(class_ids)::uuid[])
  AND (sqlc.narg(class_id)::uuid IS NULL OR a.class_id = sqlc.narg(class_id))
  AND (sqlc.narg(status)::text IS NULL OR a.status = sqlc.narg(status))
  AND (sqlc.narg(search)::text IS NULL OR a.stage_name ILIKE sqlc.narg(search) OR t.full_name ILIKE sqlc.narg(search)
       OR c.name ILIKE sqlc.narg(search) OR c.code ILIKE sqlc.narg(search) OR cu.title ILIKE sqlc.narg(search))
ORDER BY (a.status = 'in_progress') DESC, a.no DESC
LIMIT sqlc.arg(lim) OFFSET sqlc.arg(off);

-- name: CountAssignments :one
SELECT count(*) FROM syllabus.assignments a
LEFT JOIN academic.classes c ON c.id = a.class_id
LEFT JOIN syllabus.curricula cu ON cu.id = a.curriculum_id
LEFT JOIN iam.users t ON t.id = a.user_id
WHERE a.class_id = ANY (sqlc.arg(class_ids)::uuid[])
  AND (sqlc.narg(class_id)::uuid IS NULL OR a.class_id = sqlc.narg(class_id))
  AND (sqlc.narg(status)::text IS NULL OR a.status = sqlc.narg(status))
  AND (sqlc.narg(search)::text IS NULL OR a.stage_name ILIKE sqlc.narg(search) OR t.full_name ILIKE sqlc.narg(search)
       OR c.name ILIKE sqlc.narg(search) OR c.code ILIKE sqlc.narg(search) OR cu.title ILIKE sqlc.narg(search));

-- name: GetAssignment :one
SELECT a.id, a.no, a.user_id, a.curriculum_id, a.stage_id, a.class_id, a.stage_name, a.status, a.opened_at, a.created_at,
       a.expected_big_test_date, a.extra_sessions, a.deadline, a.big_test_reminded_for, c.name AS class_name
FROM syllabus.assignments a LEFT JOIN academic.classes c ON c.id = a.class_id
WHERE a.id = $1;

-- name: LockAssignment :one
SELECT a.id, a.user_id, a.curriculum_id, a.stage_id, a.class_id, a.stage_name, a.status
FROM syllabus.assignments a WHERE a.id = $1 FOR UPDATE;

-- name: OpenAssignmentOfClass :one
SELECT a.id, a.no, a.user_id, a.curriculum_id, a.stage_id, a.class_id, a.stage_name, a.status, a.opened_at, a.created_at,
       a.expected_big_test_date, a.extra_sessions, a.assigned_chapters, cu.title AS curriculum_title,
       s.position AS stage_position, s.name AS stage_title, s.big_test_title
FROM syllabus.assignments a
LEFT JOIN syllabus.curricula cu ON cu.id = a.curriculum_id
LEFT JOIN syllabus.stages s ON s.id = a.stage_id AND s.deleted_at IS NULL
WHERE a.class_id = $1 AND a.status = 'in_progress';

-- name: OpenAssignments :many
-- Chặng đang mở của các lớp (Chặng đang dạy, Nhắc lịch, Xin điều chỉnh tiến độ).
SELECT a.id, a.no, a.user_id, a.curriculum_id, a.stage_id, a.class_id, a.stage_name, a.opened_at, a.created_at,
       a.expected_big_test_date, a.big_test_reminded_for, s.position AS stage_position, s.name AS stage_title,
       c.name AS class_name, c.code::text AS class_code, c.teacher_id, c.foreign_teacher_id, c.assistant_id, c.status AS class_status
FROM syllabus.assignments a
JOIN academic.classes c ON c.id = a.class_id AND c.deleted_at IS NULL
LEFT JOIN syllabus.stages s ON s.id = a.stage_id AND s.deleted_at IS NULL
WHERE a.status = 'in_progress' AND (sqlc.arg(all_classes)::bool OR a.class_id = ANY (sqlc.arg(class_ids)::uuid[]))
ORDER BY c.name, c.id;

-- name: LastClosedStageID :one
SELECT a.stage_id FROM syllabus.assignments a
JOIN syllabus.stages s ON s.id = a.stage_id AND s.deleted_at IS NULL
WHERE a.class_id = sqlc.arg(class_id) AND a.curriculum_id = sqlc.arg(curriculum_id) AND a.status = 'completed'
ORDER BY a.closed_at DESC NULLS LAST, a.no DESC LIMIT 1;

-- name: ClosedStageIDs :many
SELECT stage_id FROM syllabus.assignments WHERE class_id = $1 AND status = 'completed' AND stage_id IS NOT NULL;

-- name: CurriculaOfClasses :many
-- Giáo trình các lượt chặng của lớp (gắn chặng cho đợt Big Test).
SELECT DISTINCT class_id, curriculum_id FROM syllabus.assignments WHERE class_id = ANY (sqlc.arg(class_ids)::uuid[]);

-- name: InsertAssignment :one
INSERT INTO syllabus.assignments (user_id, curriculum_id, stage_id, class_id, assigned_chapters, stage_name, deadline,
                                  progress_percent, status, opened_at, opened_by, open_reason)
VALUES (sqlc.arg(user_id), sqlc.arg(curriculum_id), sqlc.arg(stage_id), sqlc.arg(class_id), sqlc.arg(assigned_chapters),
        sqlc.arg(stage_name), sqlc.narg(deadline), 0, 'in_progress', sqlc.arg(opened_at), sqlc.narg(opened_by), sqlc.narg(open_reason))
RETURNING id;

-- name: CloseAssignment :exec
UPDATE syllabus.assignments SET status = 'completed', progress_percent = 100, closed_at = now(), closed_by = sqlc.narg(closed_by),
    close_reason = sqlc.arg(close_reason), closed_by_big_test_id = sqlc.narg(big_test_id),
    curriculum_completed_at = CASE WHEN sqlc.arg(finished)::bool THEN now() END, updated_at = now()
WHERE id = sqlc.arg(id);

-- name: EditAssignment :exec
UPDATE syllabus.assignments SET user_id = sqlc.arg(user_id), deadline = sqlc.narg(deadline),
    opened_at = coalesce(sqlc.narg(opened_at), opened_at), updated_at = now()
WHERE id = sqlc.arg(id);

-- name: SetExpectedBigTestDate :exec
UPDATE syllabus.assignments SET expected_big_test_date = sqlc.arg(day), updated_at = now() WHERE id = sqlc.arg(id);

-- name: AddExtraSessions :exec
UPDATE syllabus.assignments SET extra_sessions = extra_sessions + sqlc.arg(n), updated_at = now() WHERE id = sqlc.arg(id);

-- name: MarkStageReminded :exec
UPDATE syllabus.assignments SET big_test_reminded_for = sqlc.arg(day) WHERE id = sqlc.arg(id);

-- ───────────────────────── Đề xuất sửa giáo trình ─────────────────────────

-- name: ListProposals :many
SELECT p.id, p.curriculum_id, cu.title AS curriculum_title, p.unit_id, u.unit_number, u.title AS unit_title,
       p.lesson_id, l.session_no, l.title AS lesson_title, p.user_id, pu.full_name AS proposer_name, p.new_content,
       p.status, p.review_note, p.created_at
FROM syllabus.change_proposals p
JOIN syllabus.curricula cu ON cu.id = p.curriculum_id
LEFT JOIN syllabus.units u ON u.id = p.unit_id
LEFT JOIN syllabus.lessons l ON l.id = p.lesson_id
LEFT JOIN iam.users pu ON pu.id = p.user_id
WHERE (sqlc.arg(all_rows)::bool OR p.user_id = sqlc.arg(user_id))
  AND (sqlc.narg(status)::text IS NULL OR p.status = sqlc.narg(status))
ORDER BY (sqlc.arg(pending_first)::bool AND p.status = 'pending') DESC, p.created_at DESC, p.id DESC
LIMIT sqlc.arg(lim) OFFSET sqlc.arg(off);

-- name: CountProposals :one
SELECT count(*) FROM syllabus.change_proposals p
WHERE (sqlc.arg(all_rows)::bool OR p.user_id = sqlc.arg(user_id))
  AND (sqlc.narg(status)::text IS NULL OR p.status = sqlc.narg(status));

-- name: GetProposal :one
SELECT p.id, p.curriculum_id, cu.title AS curriculum_title, cu.version AS curriculum_version, p.unit_id, u.unit_number,
       u.title AS unit_title, p.lesson_id, l.session_no, l.title AS lesson_title, l.deleted_at AS lesson_deleted_at,
       p.user_id, pu.full_name AS proposer_name, p.proposal_type, p.old_content, p.new_content, p.reason,
       p.attachment_file_id, f.original_name AS attachment_name, p.status, p.reviewer_id, ru.full_name AS reviewer_name,
       p.reviewed_at, p.review_note, p.created_at,
       coalesce((SELECT array_agg(r.role ORDER BY r.role) FROM iam.user_roles r WHERE r.user_id = p.user_id), '{}')::text[] AS proposer_roles
FROM syllabus.change_proposals p
JOIN syllabus.curricula cu ON cu.id = p.curriculum_id
LEFT JOIN syllabus.units u ON u.id = p.unit_id
LEFT JOIN syllabus.lessons l ON l.id = p.lesson_id
LEFT JOIN iam.users pu ON pu.id = p.user_id
LEFT JOIN iam.users ru ON ru.id = p.reviewer_id
LEFT JOIN core.files f ON f.id = p.attachment_file_id AND f.deleted_at IS NULL
WHERE p.id = $1;

-- name: ProposalByFile :one
SELECT id, user_id FROM syllabus.change_proposals WHERE attachment_file_id = $1;

-- name: InsertProposal :one
INSERT INTO syllabus.change_proposals (id, curriculum_id, unit_id, lesson_id, user_id, proposal_type, old_content, new_content, reason,
                                       attachment_file_id)
VALUES (sqlc.arg(id), sqlc.arg(curriculum_id), sqlc.narg(unit_id), sqlc.narg(lesson_id), sqlc.arg(user_id), sqlc.narg(proposal_type),
        sqlc.narg(old_content), sqlc.arg(new_content), sqlc.narg(reason), sqlc.narg(attachment_file_id))
RETURNING id;

-- name: ReviewProposal :execrows
UPDATE syllabus.change_proposals SET status = sqlc.arg(status), reviewer_id = sqlc.arg(reviewer_id), reviewed_at = now(),
    review_note = sqlc.narg(review_note), updated_at = now()
WHERE id = sqlc.arg(id) AND status = 'pending';

-- name: UnitOfLesson :one
SELECT unit_id FROM syllabus.lessons WHERE id = $1;

-- name: UnitInCurriculum :one
SELECT EXISTS (SELECT 1 FROM syllabus.units WHERE id = sqlc.arg(id) AND curriculum_id = sqlc.arg(curriculum_id) AND deleted_at IS NULL);

-- name: LessonInCurriculum :one
SELECT EXISTS (SELECT 1 FROM syllabus.lessons WHERE id = sqlc.arg(id) AND curriculum_id = sqlc.arg(curriculum_id) AND deleted_at IS NULL);

-- name: StageInCurriculum :one
SELECT EXISTS (SELECT 1 FROM syllabus.stages WHERE id = sqlc.arg(id) AND curriculum_id = sqlc.arg(curriculum_id) AND deleted_at IS NULL);

-- ───────────────────────── Giãn tiến độ ─────────────────────────

-- name: ListPacing :many
SELECT r.id, r.class_id, c.name AS class_name, r.assignment_id, a.stage_name AS assignment_stage, r.user_id, t.full_name AS teacher_name,
       r.request_type, r.reason, r.extra_sessions, r.status, r.rejection_reason, r.applied_note, r.created_at
FROM syllabus.pacing_requests r
LEFT JOIN academic.classes c ON c.id = r.class_id
LEFT JOIN syllabus.assignments a ON a.id = r.assignment_id
LEFT JOIN iam.users t ON t.id = r.user_id
WHERE (sqlc.arg(all_rows)::bool OR r.user_id = sqlc.arg(user_id))
  AND (sqlc.narg(status)::text IS NULL OR r.status = sqlc.narg(status))
ORDER BY (sqlc.arg(pending_first)::bool AND r.status = 'pending') DESC, r.created_at DESC, r.id DESC
LIMIT sqlc.arg(lim) OFFSET sqlc.arg(off);

-- name: CountPacing :one
SELECT count(*) FROM syllabus.pacing_requests r
WHERE (sqlc.arg(all_rows)::bool OR r.user_id = sqlc.arg(user_id))
  AND (sqlc.narg(status)::text IS NULL OR r.status = sqlc.narg(status));

-- name: GetPacing :one
SELECT r.id, r.class_id, c.name AS class_name, c.end_date AS class_end_date, c.deleted_at AS class_deleted_at,
       r.assignment_id, a.stage_name AS assignment_stage, a.status AS assignment_status,
       r.user_id, t.full_name AS teacher_name, coalesce(t.code::text, '')::text AS teacher_code, r.request_type, r.reason, r.extra_sessions, r.status,
       r.approver_id, ap.full_name AS approver_name, r.reviewed_at, r.rejection_reason, r.applied_note, r.created_at
FROM syllabus.pacing_requests r
LEFT JOIN academic.classes c ON c.id = r.class_id
LEFT JOIN syllabus.assignments a ON a.id = r.assignment_id
LEFT JOIN iam.users t ON t.id = r.user_id
LEFT JOIN iam.users ap ON ap.id = r.approver_id
WHERE r.id = $1;

-- name: InsertPacing :one
INSERT INTO syllabus.pacing_requests (class_id, assignment_id, user_id, request_type, reason, extra_sessions)
VALUES (sqlc.arg(class_id), sqlc.arg(assignment_id), sqlc.arg(user_id), sqlc.arg(request_type), sqlc.arg(reason), sqlc.narg(extra_sessions))
RETURNING id, created_at;

-- name: ApprovePacing :execrows
UPDATE syllabus.pacing_requests SET status = 'approved', assignment_id = sqlc.narg(assignment_id), approver_id = sqlc.arg(approver_id),
    extra_sessions = sqlc.arg(extra_sessions), reviewed_at = now(), rejection_reason = NULL, applied_note = sqlc.arg(applied_note),
    updated_at = now()
WHERE id = sqlc.arg(id) AND status = 'pending';

-- name: RejectPacing :execrows
UPDATE syllabus.pacing_requests SET status = 'rejected', approver_id = sqlc.arg(approver_id), reviewed_at = now(),
    rejection_reason = sqlc.arg(rejection_reason), updated_at = now()
WHERE id = sqlc.arg(id) AND status = 'pending';

-- name: PacingBreaches :many
-- Yêu cầu chờ duyệt quá hạn SLA chưa báo (bỏ lớp đã xóa).
SELECT r.id, r.request_type, r.created_at, c.name AS class_name, t.full_name AS teacher_name
FROM syllabus.pacing_requests r
JOIN academic.classes c ON c.id = r.class_id AND c.deleted_at IS NULL
LEFT JOIN iam.users t ON t.id = r.user_id
WHERE r.status = 'pending' AND r.sla_notified_at IS NULL AND r.created_at <= sqlc.arg(cutoff)
ORDER BY r.created_at, r.id;

-- name: MarkPacingNotified :exec
UPDATE syllabus.pacing_requests SET sla_notified_at = now() WHERE id = $1;

-- ───────────────────────── Big Test ─────────────────────────

-- name: NextBigTestNumber :one
SELECT (coalesce(max(substring(code FROM '^BT-[0-9]{4}-([0-9]+)$')::int), 0) + 1)::int
FROM syllabus.big_tests WHERE code LIKE sqlc.arg(prefix)::text || '%';

-- name: InsertBigTest :one
INSERT INTO syllabus.big_tests (code, title, class_id, stage_id, test_type, scheduled_at, room, proctor_id, passcode, content_url,
                                speaking_url, created_by)
VALUES (sqlc.arg(code), sqlc.arg(title), sqlc.arg(class_id), sqlc.narg(stage_id), sqlc.arg(test_type), sqlc.arg(scheduled_at),
        sqlc.arg(room), sqlc.narg(proctor_id), sqlc.arg(passcode), sqlc.narg(content_url), sqlc.narg(speaking_url), sqlc.narg(created_by))
RETURNING id, code, scheduled_at;

-- name: ListBigTests :many
SELECT bt.id, bt.code, bt.title, bt.class_id, c.name AS class_name, coalesce(c.code::text, '')::text AS class_code, b.name AS branch_name,
       c.teacher_id, c.foreign_teacher_id, c.assistant_id, bt.stage_id, s.position AS stage_position, s.name AS stage_title,
       bt.test_type, bt.scheduled_at, bt.room, pu.full_name AS proctor_name, bt.passcode, bt.content_url, bt.speaking_url,
       bt.is_distributed, bt.results_completed_at
FROM syllabus.big_tests bt
LEFT JOIN academic.classes c ON c.id = bt.class_id
LEFT JOIN core.branches b ON b.id = c.branch_id
LEFT JOIN syllabus.stages s ON s.id = bt.stage_id
LEFT JOIN iam.users pu ON pu.id = bt.proctor_id
WHERE (sqlc.arg(all_rows)::bool
       OR c.teacher_id = sqlc.arg(user_id) OR c.foreign_teacher_id = sqlc.arg(user_id) OR c.assistant_id = sqlc.arg(user_id)
       OR c.branch_id = ANY (sqlc.arg(branch_ids)::uuid[]))
ORDER BY bt.created_at DESC, bt.id DESC
LIMIT sqlc.arg(lim) OFFSET sqlc.arg(off);

-- name: CountBigTests :one
SELECT count(*) FROM syllabus.big_tests bt LEFT JOIN academic.classes c ON c.id = bt.class_id
WHERE (sqlc.arg(all_rows)::bool
       OR c.teacher_id = sqlc.arg(user_id) OR c.foreign_teacher_id = sqlc.arg(user_id) OR c.assistant_id = sqlc.arg(user_id)
       OR c.branch_id = ANY (sqlc.arg(branch_ids)::uuid[]));

-- name: GetBigTest :one
SELECT bt.id, bt.code, bt.title, bt.class_id, c.name AS class_name, c.branch_id, c.teacher_id, c.foreign_teacher_id, c.assistant_id,
       c.deleted_at AS class_deleted_at, t.full_name AS class_teacher, bt.stage_id, s.position AS stage_position, s.name AS stage_title,
       bt.test_type, bt.scheduled_at, bt.room, bt.passcode, bt.content_url, bt.speaking_url, bt.is_distributed, bt.status,
       bt.results_completed_at
FROM syllabus.big_tests bt
LEFT JOIN academic.classes c ON c.id = bt.class_id
LEFT JOIN iam.users t ON t.id = c.teacher_id
LEFT JOIN syllabus.stages s ON s.id = bt.stage_id
WHERE bt.id = $1;

-- name: DistributeBigTest :execrows
UPDATE syllabus.big_tests SET status = 'distributed', is_distributed = true, approved_by = sqlc.arg(by), approved_at = now(),
    distributed_at = now(), updated_at = now()
WHERE id = sqlc.arg(id) AND NOT is_distributed;

-- name: ApplyOrderToBigTest :exec
-- Duyệt order gắn đợt thi: link đề (nếu đợt thi chưa có), link Speaking, chặng (nếu đợt thi chưa gắn).
UPDATE syllabus.big_tests SET content_url = coalesce(content_url, sqlc.arg(content_url)),
    speaking_url = coalesce(sqlc.narg(speaking_url), speaking_url),
    stage_id = coalesce(stage_id, sqlc.narg(stage_id)), updated_at = now()
WHERE id = sqlc.arg(id);

-- name: SetBigTestStage :exec
UPDATE syllabus.big_tests SET stage_id = sqlc.narg(stage_id), updated_at = now() WHERE id = sqlc.arg(id);

-- name: MarkResultsCompleted :exec
UPDATE syllabus.big_tests SET results_completed_at = now() WHERE id = $1 AND results_completed_at IS NULL;

-- name: ClassStageTests :many
-- Đợt Big Test gắn chặng của các lớp (kế hoạch thi của chặng đang mở), mới nhất trước.
SELECT id, code, class_id, stage_id, scheduled_at, is_distributed FROM syllabus.big_tests
WHERE class_id = ANY (sqlc.arg(class_ids)::uuid[]) AND stage_id IS NOT NULL
ORDER BY scheduled_at DESC NULLS LAST, id DESC;

-- name: OpenClassTests :many
-- Đợt thi chưa trả đủ kết quả của lớp (gắn order khi duyệt).
SELECT id, code, title, scheduled_at FROM syllabus.big_tests
WHERE class_id = $1 AND results_completed_at IS NULL ORDER BY scheduled_at DESC NULLS LAST, id DESC;

-- name: BigTestInClass :one
SELECT EXISTS (SELECT 1 FROM syllabus.big_tests WHERE id = sqlc.arg(id) AND class_id = sqlc.arg(class_id));

-- name: AccessibleBigTests :many
-- Đợt thi trong phạm vi (danh sách chọn ở màn Bảng điểm).
SELECT bt.id, bt.code, bt.title, c.name AS class_name
FROM syllabus.big_tests bt LEFT JOIN academic.classes c ON c.id = bt.class_id
WHERE (sqlc.arg(all_rows)::bool
       OR c.teacher_id = sqlc.arg(user_id) OR c.foreign_teacher_id = sqlc.arg(user_id) OR c.assistant_id = sqlc.arg(user_id)
       OR c.branch_id = ANY (sqlc.arg(branch_ids)::uuid[]))
ORDER BY bt.created_at DESC, bt.id DESC
LIMIT 500;

-- ───────────────────────── Order đề ─────────────────────────

-- name: ListOrders :many
SELECT o.id, o.code, o.class_id, c.name AS class_name, c.code::text AS class_code, o.stage_id, s.position AS stage_position,
       s.name AS stage_title, o.stage_name, o.test_type, t.full_name AS teacher_name, o.exam_date, o.due_date, o.status, o.created_at
FROM syllabus.big_test_orders o
JOIN academic.classes c ON c.id = o.class_id AND c.deleted_at IS NULL
LEFT JOIN syllabus.stages s ON s.id = o.stage_id
LEFT JOIN iam.users t ON t.id = o.teacher_id
WHERE (sqlc.arg(all_rows)::bool OR o.teacher_id = sqlc.arg(user_id)
       OR c.teacher_id = sqlc.arg(user_id) OR c.foreign_teacher_id = sqlc.arg(user_id) OR c.assistant_id = sqlc.arg(user_id)
       OR c.branch_id = ANY (sqlc.arg(branch_ids)::uuid[]))
  AND (sqlc.narg(status)::text IS NULL OR o.status = sqlc.narg(status))
  AND (sqlc.narg(search)::text IS NULL OR c.name ILIKE sqlc.narg(search) OR c.code ILIKE sqlc.narg(search))
ORDER BY o.due_date IS NULL, o.due_date, o.created_at DESC, o.id DESC
LIMIT sqlc.arg(lim) OFFSET sqlc.arg(off);

-- name: CountOrders :one
SELECT count(*) FROM syllabus.big_test_orders o
JOIN academic.classes c ON c.id = o.class_id AND c.deleted_at IS NULL
WHERE (sqlc.arg(all_rows)::bool OR o.teacher_id = sqlc.arg(user_id)
       OR c.teacher_id = sqlc.arg(user_id) OR c.foreign_teacher_id = sqlc.arg(user_id) OR c.assistant_id = sqlc.arg(user_id)
       OR c.branch_id = ANY (sqlc.arg(branch_ids)::uuid[]))
  AND (sqlc.narg(status)::text IS NULL OR o.status = sqlc.narg(status))
  AND (sqlc.narg(search)::text IS NULL OR c.name ILIKE sqlc.narg(search) OR c.code ILIKE sqlc.narg(search));

-- name: GetOrder :one
SELECT o.id, o.code, o.class_id, c.name AS class_name, c.code::text AS class_code, c.branch_id, b.name AS branch_name,
       co.name AS course_name, c.room AS class_room, c.teacher_id AS class_teacher_id, c.foreign_teacher_id, c.assistant_id,
       o.stage_id, s.position AS stage_position, s.name AS stage_title, o.stage_name, o.test_type, o.teacher_id,
       t.full_name AS teacher_name, o.exam_date, o.due_date, o.note, o.status, o.test_link, o.speaking_link, o.big_test_id,
       bt.code AS big_test_code, bt.scheduled_at AS big_test_scheduled_at, bt.room AS big_test_room,
       ru.full_name AS reviewer_name, o.reviewed_at, o.rejection_reason, o.created_at
FROM syllabus.big_test_orders o
LEFT JOIN academic.classes c ON c.id = o.class_id
LEFT JOIN core.branches b ON b.id = c.branch_id
LEFT JOIN academic.courses co ON co.id = c.course_id
LEFT JOIN syllabus.stages s ON s.id = o.stage_id
LEFT JOIN iam.users t ON t.id = o.teacher_id
LEFT JOIN iam.users ru ON ru.id = o.reviewed_by
LEFT JOIN syllabus.big_tests bt ON bt.id = o.big_test_id
WHERE o.id = $1;

-- name: InsertOrder :one
INSERT INTO syllabus.big_test_orders (code, class_id, stage_id, teacher_id, stage_name, test_type, exam_date, due_date, note)
VALUES (sqlc.arg(code), sqlc.arg(class_id), sqlc.narg(stage_id), sqlc.arg(teacher_id), sqlc.arg(stage_name), sqlc.arg(test_type),
        sqlc.narg(exam_date), sqlc.arg(due_date), sqlc.narg(note))
RETURNING id, code;

-- name: DuplicatePendingOrder :one
SELECT EXISTS (SELECT 1 FROM syllabus.big_test_orders
    WHERE class_id = sqlc.arg(class_id) AND status = 'pending' AND test_type = sqlc.arg(test_type)
      AND (CASE WHEN sqlc.narg(stage_id)::uuid IS NOT NULL THEN stage_id = sqlc.narg(stage_id) ELSE stage_name = sqlc.arg(stage_name) END));

-- name: ApproveOrder :execrows
UPDATE syllabus.big_test_orders SET status = 'approved', test_link = sqlc.arg(test_link), speaking_link = sqlc.narg(speaking_link),
    big_test_id = sqlc.narg(big_test_id), reviewed_by = sqlc.arg(reviewed_by), reviewed_at = now(), rejection_reason = NULL,
    updated_at = now()
WHERE id = sqlc.arg(id) AND status = 'pending';

-- name: RejectOrder :execrows
UPDATE syllabus.big_test_orders SET status = 'rejected', rejection_reason = sqlc.arg(rejection_reason),
    reviewed_by = sqlc.arg(reviewed_by), reviewed_at = now(), updated_at = now()
WHERE id = sqlc.arg(id) AND status = 'pending';

-- name: ClassBigOrders :many
-- Order Big Test của các lớp, mới nhất trước (kế hoạch thi chặng đang mở).
SELECT id, class_id, stage_id, stage_name, status, exam_date, rejection_reason FROM syllabus.big_test_orders
WHERE class_id = ANY (sqlc.arg(class_ids)::uuid[]) AND test_type = 'big'
ORDER BY created_at DESC, id DESC;

-- name: ClassOrderHistory :many
SELECT o.id, o.created_at, o.test_type, o.stage_id, s.position AS stage_position, s.name AS stage_title, o.stage_name, o.exam_date,
       o.due_date, o.note, o.status, o.test_link, o.speaking_link, o.rejection_reason
FROM syllabus.big_test_orders o LEFT JOIN syllabus.stages s ON s.id = o.stage_id
WHERE o.class_id = $1
ORDER BY o.created_at DESC, o.id DESC LIMIT 10;

-- name: ClassAssignmentHistory :many
SELECT a.id, a.status, s.position AS stage_position, s.name AS stage_title, a.stage_name, a.opened_at, a.created_at, a.closed_at,
       a.assigned_chapters, a.expected_big_test_date
FROM syllabus.assignments a LEFT JOIN syllabus.stages s ON s.id = a.stage_id
WHERE a.class_id = $1
ORDER BY a.no DESC;

-- ───────────────────────── Kết quả ─────────────────────────

-- name: TestResults :many
SELECT r.id, r.student_id, st.full_name AS student_name, st.code::text AS student_code, r.listening_score, r.reading_score,
       r.writing_score, r.speaking_score, r.overall_score, r.is_absent, r.progress_note, r.video_url, r.status,
       r.parent_notified, r.notified_at, g.full_name AS grader_name, ap.full_name AS approver_name, r.graded_by,
       coalesce(nullif(st.parent_phone, ''), nullif(l.parent_phone, ''), '')::text AS parent_phone
FROM syllabus.big_test_results r
JOIN academic.students st ON st.id = r.student_id
LEFT JOIN crm.leads l ON l.id = st.lead_id
LEFT JOIN iam.users g ON g.id = r.graded_by
LEFT JOIN iam.users ap ON ap.id = r.approved_by
WHERE r.big_test_id = $1
ORDER BY st.full_name, r.id;

-- name: GetResult :one
SELECT r.id, r.big_test_id, r.student_id, st.full_name AS student_name, r.listening_score, r.reading_score, r.writing_score,
       r.speaking_score, r.overall_score, r.is_absent, r.progress_note, r.status, r.parent_notified,
       coalesce(nullif(st.parent_phone, ''), nullif(l.parent_phone, ''), '')::text AS parent_phone
FROM syllabus.big_test_results r
JOIN academic.students st ON st.id = r.student_id
LEFT JOIN crm.leads l ON l.id = st.lead_id
WHERE r.id = $1;

-- name: UpsertResult :exec
INSERT INTO syllabus.big_test_results (big_test_id, student_id, listening_score, reading_score, writing_score, speaking_score,
                                       overall_score, is_absent, progress_note, video_url, status, graded_by)
VALUES (sqlc.arg(big_test_id), sqlc.arg(student_id), sqlc.narg(listening_score), sqlc.narg(reading_score), sqlc.narg(writing_score),
        sqlc.narg(speaking_score), sqlc.narg(overall_score), sqlc.arg(is_absent), sqlc.narg(progress_note), sqlc.narg(video_url),
        sqlc.arg(status), sqlc.arg(graded_by))
ON CONFLICT (big_test_id, student_id) DO UPDATE SET
    listening_score = EXCLUDED.listening_score, reading_score = EXCLUDED.reading_score, writing_score = EXCLUDED.writing_score,
    speaking_score = EXCLUDED.speaking_score, overall_score = EXCLUDED.overall_score, is_absent = EXCLUDED.is_absent,
    progress_note = EXCLUDED.progress_note, video_url = EXCLUDED.video_url, status = EXCLUDED.status,
    graded_by = EXCLUDED.graded_by, approved_by = NULL, approved_at = NULL, updated_at = now();

-- name: PromoteDraft :exec
UPDATE syllabus.big_test_results SET status = 'pending_review', graded_by = coalesce(graded_by, sqlc.arg(by)), updated_at = now()
WHERE id = sqlc.arg(id);

-- name: ApproveTestResults :execrows
UPDATE syllabus.big_test_results SET status = 'approved', approved_by = sqlc.arg(by), approved_at = now(), updated_at = now()
WHERE big_test_id = sqlc.arg(big_test_id) AND status = 'pending_review';

-- name: ApproveResult :exec
UPDATE syllabus.big_test_results SET status = 'approved', approved_by = sqlc.arg(by), approved_at = now(), updated_at = now()
WHERE id = sqlc.arg(id) AND status = 'pending_review';

-- name: MarkResultSent :exec
UPDATE syllabus.big_test_results SET status = 'sent', parent_notified = true, notified_at = now(), updated_at = now() WHERE id = $1;

-- name: StudentsExist :one
SELECT count(*) FROM academic.students WHERE id = ANY (sqlc.arg(ids)::uuid[]);

-- ───────────────────────── Nhắc lịch / SLA ─────────────────────────

-- name: UpcomingUnreminded :many
-- Đợt thi trong N ngày tới chưa nhắc giáo viên.
SELECT bt.id, bt.code, bt.title, bt.scheduled_at, bt.room, bt.is_distributed, bt.class_id, c.name AS class_name,
       c.teacher_id, c.foreign_teacher_id, c.assistant_id
FROM syllabus.big_tests bt JOIN academic.classes c ON c.id = bt.class_id AND c.deleted_at IS NULL
WHERE bt.teacher_reminded_at IS NULL AND bt.scheduled_at BETWEEN sqlc.arg(from_at) AND sqlc.arg(to_at)
ORDER BY bt.scheduled_at;

-- name: MarkTeacherReminded :exec
UPDATE syllabus.big_tests SET teacher_reminded_at = now() WHERE id = $1;

-- name: UndistributedUpcoming :many
-- Đợt thi chưa phân phối đề trong khoảng (nhắc Admin duyệt đề, GV chưa nhận đề).
SELECT bt.id, bt.code, bt.title, bt.scheduled_at, bt.class_id, c.name AS class_name, c.branch_id
FROM syllabus.big_tests bt JOIN academic.classes c ON c.id = bt.class_id AND c.deleted_at IS NULL
WHERE NOT bt.is_distributed AND bt.scheduled_at BETWEEN sqlc.arg(from_at) AND sqlc.arg(to_at)
ORDER BY bt.scheduled_at;

-- name: StageHasBigTest :one
SELECT EXISTS (SELECT 1 FROM syllabus.big_tests WHERE class_id = sqlc.arg(class_id)
    AND (CASE WHEN sqlc.narg(stage_id)::uuid IS NOT NULL THEN stage_id = sqlc.narg(stage_id) ELSE created_at >= sqlc.arg(since) END));

-- name: LateResultTests :many
-- Đợt thi đã quá hạn trả kết quả, chưa trả đủ (quét 90 ngày gần nhất).
SELECT bt.id, bt.code, bt.title, bt.scheduled_at, bt.class_id, c.teacher_id
FROM syllabus.big_tests bt JOIN academic.classes c ON c.id = bt.class_id
WHERE bt.results_completed_at IS NULL AND bt.scheduled_at >= sqlc.arg(since) AND bt.scheduled_at < sqlc.arg(before)
ORDER BY bt.id;

-- name: ResultStatuses :many
SELECT student_id, status, is_absent FROM syllabus.big_test_results WHERE big_test_id = $1;

-- name: StudentBigTestResults :many
-- Kết quả Big Test đã duyệt / đã gửi phụ huynh của học viên (Cổng PH/HS), mới nhất trước.
SELECT r.id, bt.title, bt.scheduled_at, r.is_absent, r.overall_score, r.listening_score, r.reading_score, r.writing_score,
       r.speaking_score, r.progress_note
FROM syllabus.big_test_results r
JOIN syllabus.big_tests bt ON bt.id = r.big_test_id
WHERE r.student_id = sqlc.arg(student_id) AND r.status IN ('approved', 'sent')
ORDER BY r.approved_at DESC NULLS LAST, r.id DESC
LIMIT sqlc.arg(max_rows);

-- ───────────── Hộp "Việc cần duyệt" (approvals.go) ─────────────

-- name: InboxPacing :many
-- Yêu cầu giãn tiến độ chờ duyệt của lớp chưa xóa (AdjustmentApprovalSource), mới nhất trước.
SELECT r.id, r.class_id, c.name AS class_name, a.stage_name AS assignment_stage, t.full_name AS teacher_name,
       r.request_type, r.reason, r.extra_sessions, r.created_at
FROM syllabus.pacing_requests r
JOIN academic.classes c ON c.id = r.class_id AND c.deleted_at IS NULL
LEFT JOIN syllabus.assignments a ON a.id = r.assignment_id
LEFT JOIN iam.users t ON t.id = r.user_id
WHERE r.status = 'pending'
ORDER BY r.created_at DESC, r.id DESC;

-- ───── Số liệu báo cáo ngày / tuần (DailyReportMetrics::academic, WeeklyReportMetrics) ─────

-- name: ReportOrders :many
-- Order đề trong phạm vi Big Test của người viết: chờ duyệt tạo trước created_before, và (báo cáo tuần) đã duyệt trong
-- [from_at, to_at]; theo hạn duyệt.
SELECT o.id, o.code, c.name AS class_name, o.test_type, o.due_date, o.status
FROM syllabus.big_test_orders o
JOIN academic.classes c ON c.id = o.class_id AND c.deleted_at IS NULL
WHERE (sqlc.arg(all_rows)::bool OR o.teacher_id = sqlc.arg(user_id)
       OR c.teacher_id = sqlc.arg(user_id) OR c.foreign_teacher_id = sqlc.arg(user_id) OR c.assistant_id = sqlc.arg(user_id)
       OR c.branch_id = ANY (sqlc.arg(branch_ids)::uuid[]))
  AND ((o.status = 'pending' AND o.created_at <= sqlc.arg(created_before)::timestamptz)
       OR (sqlc.arg(with_reviewed)::bool AND o.status = 'approved'
           AND o.reviewed_at BETWEEN sqlc.arg(from_at)::timestamptz AND sqlc.arg(to_at)::timestamptz))
ORDER BY o.due_date IS NULL, o.due_date, o.created_at, o.id;

-- name: ReportProposals :many
-- Đề xuất sửa giáo trình người viết thấy (người duyệt thấy mọi đề xuất): chờ duyệt, hoặc gửi / duyệt trong [from_at, to_at]
-- (cờ by_review: theo ngày duyệt — báo cáo tuần; không: theo ngày gửi — báo cáo ngày), gửi trước created_before. Mới nhất trước.
SELECT p.id, cu.title AS curriculum_title, p.reason, p.status, pu.full_name AS proposer_name
FROM syllabus.change_proposals p
JOIN syllabus.curricula cu ON cu.id = p.curriculum_id
LEFT JOIN iam.users pu ON pu.id = p.user_id
WHERE (sqlc.arg(all_rows)::bool OR p.user_id = sqlc.arg(user_id))
  AND p.created_at <= sqlc.arg(created_before)::timestamptz
  AND (p.status = 'pending'
       OR (sqlc.arg(by_review)::bool AND p.reviewed_at BETWEEN sqlc.arg(from_at)::timestamptz AND sqlc.arg(to_at)::timestamptz)
       OR (NOT sqlc.arg(by_review)::bool AND p.created_at BETWEEN sqlc.arg(from_at)::timestamptz AND sqlc.arg(to_at)::timestamptz))
ORDER BY p.created_at DESC, p.id DESC;

-- name: ReportPacing :many
-- Yêu cầu giãn / điều chỉnh tiến độ của các lớp (lớp người viết thấy, lọc chi nhánh): như ReportProposals.
SELECT r.id, r.class_id, c.name AS class_name, r.reason, r.status, t.full_name AS teacher_name
FROM syllabus.pacing_requests r
JOIN academic.classes c ON c.id = r.class_id
LEFT JOIN iam.users t ON t.id = r.user_id
WHERE r.class_id = ANY (sqlc.arg(class_ids)::uuid[])
  AND r.created_at <= sqlc.arg(created_before)::timestamptz
  AND (r.status = 'pending'
       OR (sqlc.arg(by_review)::bool AND r.reviewed_at BETWEEN sqlc.arg(from_at)::timestamptz AND sqlc.arg(to_at)::timestamptz)
       OR (NOT sqlc.arg(by_review)::bool AND r.created_at BETWEEN sqlc.arg(from_at)::timestamptz AND sqlc.arg(to_at)::timestamptz))
ORDER BY r.created_at DESC, r.id DESC;

-- name: ReportUpcomingBigTests :many
-- Đợt Big Test của các lớp lên lịch trong [from_at, to_at] (tuần này và tuần kế tiếp).
SELECT b.id, b.class_id, coalesce(c.name, 'Lớp')::text AS class_name, b.scheduled_at
FROM syllabus.big_tests b
LEFT JOIN academic.classes c ON c.id = b.class_id
WHERE b.class_id = ANY (sqlc.arg(class_ids)::uuid[])
  AND b.scheduled_at BETWEEN sqlc.arg(from_at)::timestamptz AND sqlc.arg(to_at)::timestamptz
ORDER BY b.scheduled_at, b.id;

-- name: TeacherBigTestResults :many
-- Bảng điểm Big Test các lớp giáo viên phụ trách (GV chính hoặc GVNN), mới nhất trước (TeacherPortalController::bigTestReport).
SELECT r.id, s.full_name AS student_name, b.title AS test_title, c.name AS class_name, r.listening_score, r.reading_score,
       r.writing_score, r.speaking_score, r.overall_score, r.status
FROM syllabus.big_test_results r
JOIN syllabus.big_tests b ON b.id = r.big_test_id
JOIN academic.classes c ON c.id = b.class_id AND c.deleted_at IS NULL
LEFT JOIN academic.students s ON s.id = r.student_id AND s.deleted_at IS NULL
WHERE c.teacher_id = sqlc.arg(user_id) OR c.foreign_teacher_id = sqlc.arg(user_id)
ORDER BY r.created_at DESC, r.id DESC
LIMIT sqlc.arg(lim) OFFSET sqlc.arg(off);

-- name: CountTeacherBigTestResults :one
SELECT count(*) FROM syllabus.big_test_results r
JOIN syllabus.big_tests b ON b.id = r.big_test_id
JOIN academic.classes c ON c.id = b.class_id AND c.deleted_at IS NULL
WHERE c.teacher_id = sqlc.arg(user_id) OR c.foreign_teacher_id = sqlc.arg(user_id);

-- ───── Tổng quan (DashboardController::academicDashboard, AcademicDashboardController::reports, MyWorkBoard) ─────

-- name: DashboardCounts :one
SELECT (SELECT count(*) FROM syllabus.change_proposals WHERE status = 'pending')::bigint AS proposals,
       (SELECT count(*) FROM syllabus.big_test_orders WHERE status = 'pending')::bigint AS orders,
       (SELECT count(DISTINCT big_test_id) FROM syllabus.big_test_results WHERE status = 'pending_review')::bigint AS results,
       (SELECT count(*) FROM syllabus.pacing_requests WHERE status = 'pending')::bigint AS adjustments;

-- name: DashboardAdjustments :many
-- Yêu cầu giãn tiến độ chờ duyệt mới nhất.
SELECT r.id, c.name AS class_name, t.full_name AS teacher_name, r.reason
FROM syllabus.pacing_requests r
LEFT JOIN academic.classes c ON c.id = r.class_id
LEFT JOIN iam.users t ON t.id = r.user_id
WHERE r.status = 'pending'
ORDER BY r.created_at DESC, r.id DESC
LIMIT sqlc.arg(lim);

-- name: DashboardBigTests :many
-- Đợt Big Test lên lịch trong [from_at, to_at], theo giờ thi.
SELECT b.id, b.code, b.title, c.name AS class_name, b.scheduled_at
FROM syllabus.big_tests b
LEFT JOIN academic.classes c ON c.id = b.class_id
WHERE b.scheduled_at BETWEEN sqlc.arg(from_at)::timestamptz AND sqlc.arg(to_at)::timestamptz
ORDER BY b.scheduled_at, b.id
LIMIT sqlc.arg(lim);

-- name: DashboardWeek :one
SELECT (SELECT count(*) FROM syllabus.pacing_requests WHERE status = 'pending')::bigint AS adjustments_pending,
       (SELECT count(*) FROM syllabus.pacing_requests WHERE created_at >= sqlc.arg(week_start)::timestamptz)::bigint AS adjustments_week,
       (SELECT count(*) FROM syllabus.big_tests
        WHERE scheduled_at BETWEEN sqlc.arg(now_at)::timestamptz AND sqlc.arg(week_ahead)::timestamptz)::bigint AS big_tests_upcoming,
       (SELECT count(*) FROM syllabus.big_tests
        WHERE is_distributed AND distributed_at >= sqlc.arg(week_start)::timestamptz)::bigint AS big_tests_distributed;

-- name: ReportClassIDs :many
-- Lớp người viết thấy, lọc theo chi nhánh của người viết (BuildsReportItems::classIds; rỗng = không lọc thêm).
SELECT c.id FROM academic.classes c
WHERE c.deleted_at IS NULL AND c.id = ANY (sqlc.arg(ids)::uuid[])
  AND (cardinality(sqlc.arg(branch_ids)::uuid[]) = 0 OR c.branch_id = ANY (sqlc.arg(branch_ids)::uuid[]));
