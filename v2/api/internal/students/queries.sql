-- name: NextStudentNumber :one
SELECT nextval('academic.student_code_seq')::bigint;

-- name: StudentCodeTaken :one
-- Kể cả hồ sơ đã xóa (Laravel kiểm withTrashed) để mã đã cấp không bị dùng lại.
SELECT EXISTS (SELECT 1 FROM academic.students WHERE code = $1);

-- name: CreateStudent :one
INSERT INTO academic.students (
    code, user_id, full_name, phone, email, dob, gender, address, school, parent_name, parent_phone,
    branch_id, current_class_id, lead_id, target, entrance_score, total_lessons, created_by
) VALUES (
    sqlc.arg(code), sqlc.narg(user_id), sqlc.arg(full_name), sqlc.arg(phone), sqlc.narg(email), sqlc.narg(dob),
    sqlc.narg(gender), sqlc.narg(address), sqlc.narg(school), sqlc.narg(parent_name), sqlc.narg(parent_phone),
    sqlc.narg(branch_id), sqlc.narg(current_class_id), sqlc.narg(lead_id), sqlc.narg(target),
    sqlc.narg(entrance_score), sqlc.narg(total_lessons), sqlc.narg(created_by)
)
RETURNING id;

-- name: StudentUserLinked :one
SELECT EXISTS (SELECT 1 FROM academic.students WHERE user_id = $1);

-- name: ListStudents :many
-- Phạm vi: limit_branches → chỉ chi nhánh trong branch_ids; limit_classes → chỉ học viên của các lớp class_ids.
SELECT s.id, s.code, s.full_name, s.dob, s.phone, s.email, s.branch_id, s.current_class_id, s.status,
       s.study_started_on, s.created_at, b.name AS branch_name, count(*) OVER () AS total
FROM academic.students s
LEFT JOIN core.branches b ON b.id = s.branch_id
WHERE s.deleted_at IS NULL
  AND (NOT sqlc.arg(limit_branches)::bool OR s.branch_id = ANY (sqlc.arg(branch_ids)::uuid[]))
  AND (NOT sqlc.arg(limit_classes)::bool OR s.current_class_id = ANY (sqlc.arg(class_ids)::uuid[]))
  AND (sqlc.narg(search)::text IS NULL OR s.full_name ILIKE sqlc.narg(search) OR s.phone ILIKE sqlc.narg(search)
       OR s.code::text ILIKE sqlc.narg(search) OR s.email::text ILIKE sqlc.narg(search))
  AND (sqlc.narg(branch_id)::uuid IS NULL OR s.branch_id = sqlc.narg(branch_id))
  AND (sqlc.narg(class_id)::uuid IS NULL OR s.current_class_id = sqlc.narg(class_id))
  AND (cardinality(sqlc.arg(statuses)::text[]) = 0 OR s.status = ANY (sqlc.arg(statuses)::text[]))
  AND (sqlc.narg(started_by)::date IS NULL
       OR (s.study_started_on <= sqlc.narg(started_by) AND s.status <> 'dropped'))
  AND (NOT sqlc.arg(without_class)::bool OR (s.current_class_id IS NULL AND s.status <> 'dropped'))
ORDER BY
  CASE WHEN sqlc.arg(sort_tenure)::bool THEN s.study_started_on IS NULL END,
  CASE WHEN sqlc.arg(sort_tenure)::bool THEN s.study_started_on END,
  CASE WHEN NOT sqlc.arg(sort_tenure)::bool THEN s.created_at END DESC,
  s.id DESC
LIMIT sqlc.arg(lim) OFFSET sqlc.arg(off);

-- name: CountVisibleStudents :one
SELECT count(*) FROM academic.students s
WHERE s.deleted_at IS NULL
  AND (NOT sqlc.arg(limit_branches)::bool OR s.branch_id = ANY (sqlc.arg(branch_ids)::uuid[]))
  AND (NOT sqlc.arg(limit_classes)::bool OR s.current_class_id = ANY (sqlc.arg(class_ids)::uuid[]));

-- name: GetStudent :one
-- Tìm theo id hoặc theo mã (Laravel findVisibleStudent nhận cả hai).
SELECT s.*, b.name AS branch_name,
       a.code AS account_code, a.status AS account_status, a.must_change_password AS account_must_change_password,
       a.last_login_at AS account_last_login_at
FROM academic.students s
LEFT JOIN core.branches b ON b.id = s.branch_id
LEFT JOIN iam.users a ON a.id = s.user_id
WHERE s.deleted_at IS NULL AND (s.id = sqlc.narg(id) OR s.code = sqlc.narg(code))
LIMIT 1;

-- name: LockStudent :one
SELECT * FROM academic.students WHERE id = $1 AND deleted_at IS NULL FOR UPDATE;

-- name: UpdateStudent :one
UPDATE academic.students SET
    full_name = sqlc.arg(full_name), phone = sqlc.arg(phone), email = sqlc.narg(email), dob = sqlc.narg(dob),
    gender = sqlc.narg(gender), target = sqlc.narg(target), address = sqlc.narg(address), school = sqlc.narg(school),
    parent_name = sqlc.narg(parent_name), parent_phone = sqlc.narg(parent_phone),
    study_started_on = sqlc.narg(study_started_on), notes = sqlc.narg(notes),
    updated_at = now(), version = version + 1
WHERE id = sqlc.arg(id) AND deleted_at IS NULL
RETURNING version;

-- name: SetStudentStatus :exec
-- Thôi học: bỏ khỏi lớp đang học (giữ hồ sơ, điểm danh, học phí để tra cứu).
UPDATE academic.students SET
    status = sqlc.arg(status),
    current_class_id = CASE WHEN sqlc.arg(status)::text = 'dropped' THEN NULL ELSE current_class_id END,
    updated_at = now(), version = version + 1
WHERE id = sqlc.arg(id);

-- name: SoftDeleteStudent :exec
UPDATE academic.students SET deleted_at = now(), updated_at = now(), version = version + 1
WHERE id = $1 AND deleted_at IS NULL;

-- name: AddEvent :exec
INSERT INTO academic.student_events (student_id, kind, message, from_status, to_status, created_by)
VALUES (sqlc.arg(student_id), sqlc.arg(kind), sqlc.arg(message), sqlc.narg(from_status), sqlc.narg(to_status),
        sqlc.narg(created_by));

-- name: ListEvents :many
SELECT e.id, e.kind, e.message, e.from_status, e.to_status, e.created_at, u.full_name AS actor_name
FROM academic.student_events e
LEFT JOIN iam.users u ON u.id = e.created_by
WHERE e.student_id = $1
ORDER BY e.created_at DESC, e.id DESC
LIMIT 100;

-- name: ListGuardians :many
SELECT g.guardian_id, g.relationship, g.created_at, u.full_name, u.phone, u.status, u.must_change_password,
       u.last_login_at,
       (SELECT count(*) FROM academic.student_guardians o
        JOIN academic.students os ON os.id = o.student_id AND os.deleted_at IS NULL
        WHERE o.guardian_id = g.guardian_id) AS children
FROM academic.student_guardians g
JOIN iam.users u ON u.id = g.guardian_id
WHERE g.student_id = $1
ORDER BY g.created_at, u.full_name;

-- name: LinkGuardian :execrows
INSERT INTO academic.student_guardians (student_id, guardian_id, relationship, created_by)
VALUES (sqlc.arg(student_id), sqlc.arg(guardian_id), sqlc.narg(relationship), sqlc.narg(created_by))
ON CONFLICT DO NOTHING;

-- name: UnlinkGuardian :execrows
DELETE FROM academic.student_guardians WHERE student_id = $1 AND guardian_id = $2;

-- name: ListBranchOptions :many
SELECT id, code, name FROM core.branches
WHERE (NOT sqlc.arg(limited)::bool OR id = ANY (sqlc.arg(ids)::uuid[]))
ORDER BY name;

-- name: BranchExists :one
SELECT EXISTS (SELECT 1 FROM core.branches WHERE id = $1);

-- name: SearchStudents :many
-- Tìm kiếm chung (topbar, Laravel GlobalSearchController): tên / mã / email, SĐT khi từ khóa có số; phạm vi như ListStudents.
SELECT s.id, s.code, s.full_name, s.phone, s.status, b.name AS branch_name
FROM academic.students s
LEFT JOIN core.branches b ON b.id = s.branch_id
WHERE s.deleted_at IS NULL
  AND (NOT sqlc.arg(limit_branches)::bool OR s.branch_id = ANY (sqlc.arg(branch_ids)::uuid[]))
  AND (NOT sqlc.arg(limit_classes)::bool OR s.current_class_id = ANY (sqlc.arg(class_ids)::uuid[]))
  AND (s.full_name ILIKE sqlc.arg(term)::text OR s.code::text ILIKE sqlc.arg(term)::text OR s.email::text ILIKE sqlc.arg(term)::text
       OR (sqlc.arg(phone)::text <> '' AND s.phone ILIKE sqlc.arg(phone)::text))
ORDER BY s.full_name, s.id
LIMIT sqlc.arg(lim);
