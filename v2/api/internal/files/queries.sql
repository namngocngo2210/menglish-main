-- name: InsertFile :one
INSERT INTO core.files (owner_module, subject_type, subject_id, branch_id, storage_key, original_name, extension,
                        mime_type, size_bytes, sha256, legacy_path, uploaded_by)
VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12)
RETURNING *;

-- name: GetFile :one
SELECT * FROM core.files WHERE id = $1;

-- name: CurrentFile :one
-- File mới nhất còn dùng của một bản ghi.
SELECT * FROM core.files
WHERE subject_type = $1 AND subject_id = $2 AND deleted_at IS NULL
ORDER BY created_at DESC, id DESC
LIMIT 1;

-- name: ListSubjectFiles :many
SELECT * FROM core.files
WHERE subject_type = $1 AND subject_id = $2 AND deleted_at IS NULL
ORDER BY created_at DESC, id DESC;

-- name: AttachFile :one
-- Gắn file tải lên trước (chưa có bản ghi) vào bản ghi vừa tạo; chỉ file cùng loại, chưa gắn hoặc đã gắn đúng bản ghi.
UPDATE core.files SET subject_id = sqlc.arg(subject_id)
WHERE id = sqlc.arg(id) AND subject_type = sqlc.arg(subject_type) AND deleted_at IS NULL
  AND (subject_id IS NULL OR subject_id = sqlc.arg(subject_id))
RETURNING *;

-- name: SoftDeleteFile :one
UPDATE core.files SET deleted_at = now(), deleted_by = sqlc.narg(deleted_by)
WHERE id = sqlc.arg(id) AND deleted_at IS NULL
RETURNING *;

-- name: PurgeCandidates :many
-- File đã xóa mềm quá hạn giữ: xóa nội dung rồi xóa dòng.
SELECT id, storage_key FROM core.files
WHERE deleted_at IS NOT NULL AND deleted_at < sqlc.arg(before)
ORDER BY deleted_at
LIMIT sqlc.arg(max_rows);

-- name: DeleteFileRow :exec
DELETE FROM core.files WHERE id = $1 AND deleted_at IS NOT NULL;
