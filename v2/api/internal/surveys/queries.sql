-- Đợt khảo sát (ops.surveys) và bài khảo sát đã nộp (ops.survey_responses).

-- name: ListSurveys :many
-- Màn quản lý: mọi đợt chưa xóa, mới tạo trước, kèm số bài đã nộp.
SELECT s.*, u.full_name AS creator_name,
       (SELECT count(*) FROM ops.survey_responses r WHERE r.survey_id = s.id) AS response_count
FROM ops.surveys s
LEFT JOIN iam.users u ON u.id = s.created_by
WHERE s.deleted_at IS NULL
ORDER BY s.created_at DESC, s.id DESC;

-- name: GetSurvey :one
SELECT * FROM ops.surveys WHERE id = $1 AND deleted_at IS NULL;

-- name: InsertSurvey :one
INSERT INTO ops.surveys (title, description, deadline, is_active, created_by)
VALUES (@title, sqlc.narg(description), sqlc.narg(deadline), true, @created_by)
RETURNING *;

-- name: UpdateSurvey :one
UPDATE ops.surveys
SET title = @title, description = sqlc.narg(description), deadline = sqlc.narg(deadline), is_active = @is_active,
    updated_at = now()
WHERE id = @id AND deleted_at IS NULL
RETURNING *;

-- name: SoftDeleteSurvey :one
UPDATE ops.surveys SET deleted_at = now(), updated_at = now() WHERE id = $1 AND deleted_at IS NULL RETURNING *;

-- name: OpenSurveys :many
-- Cổng PH/HS: đợt đang mở theo hạn tăng dần; đợt chưa đặt hạn lên đầu như orderBy('deadline') trên MySQL.
SELECT * FROM ops.surveys
WHERE is_active AND deleted_at IS NULL
ORDER BY deadline NULLS FIRST, created_at, id;

-- name: SurveyResponses :many
-- Bài đã nộp của một đợt (màn quản lý), mới trước.
SELECT r.*, st.full_name AS student_name, st.code::text AS student_code, u.full_name AS submitter_name
FROM ops.survey_responses r
JOIN academic.students st ON st.id = r.student_id
LEFT JOIN iam.users u ON u.id = r.user_id
WHERE r.survey_id = $1
ORDER BY r.created_at DESC, r.id DESC
LIMIT 500;

-- name: StudentResponses :many
-- Lịch sử khảo sát của một học viên (cổng PH/HS), mới trước.
SELECT r.*, st.full_name AS student_name
FROM ops.survey_responses r
JOIN academic.students st ON st.id = r.student_id
WHERE r.student_id = $1
ORDER BY r.created_at DESC, r.id DESC;

-- name: CountStudentResponses :one
SELECT count(*) FROM ops.survey_responses WHERE student_id = $1;

-- name: InsertResponse :one
INSERT INTO ops.survey_responses (survey_id, survey_title, student_id, user_id, rating, feedback)
VALUES (@survey_id, @survey_title, @student_id, @user_id, @rating, @feedback)
RETURNING *;

-- name: GetResponse :one
SELECT * FROM ops.survey_responses WHERE id = $1;

-- name: DeleteResponse :exec
DELETE FROM ops.survey_responses WHERE id = $1;
