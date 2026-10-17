-- Nguồn "Xác nhận nhập học" của hộp "Việc cần duyệt" (internal/approvals): lượt xếp lớp chưa bàn giao xong. Hộp chỉ
-- dành cho Admin (phạm vi học viên toàn hệ thống, thấy CRM) nên không lọc phạm vi và bỏ lượt từ CRM (đã ở nguồn
-- "Xác nhận chính thức (CRM)").

-- name: ApprovalEnrollmentCount :one
SELECT count(*) FROM academic.enrollments e
JOIN academic.students st ON st.id = e.student_id AND st.deleted_at IS NULL
WHERE e.status = 'pending' AND e.lead_id IS NULL;

-- name: ApprovalEnrollments :many
SELECT e.id, e.enrolled_at, e.created_at, e.curriculum_delivered, e.zalo_group_added, st.full_name AS student_name,
       st.code::text AS student_code, c.name AS class_name
FROM academic.enrollments e
JOIN academic.students st ON st.id = e.student_id AND st.deleted_at IS NULL
JOIN academic.classes c ON c.id = e.class_id
WHERE e.status = 'pending' AND e.lead_id IS NULL AND (sqlc.narg(id)::uuid IS NULL OR e.id = sqlc.narg(id)::uuid)
ORDER BY e.created_at DESC, e.id DESC
LIMIT @lim::int;
