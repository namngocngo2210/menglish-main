-- Nguồn của hộp "Việc cần duyệt" (internal/approvals). Hộp chỉ dành cho Admin (phạm vi khách toàn hệ thống) nên
-- không lọc phạm vi.

-- name: ApprovalTransferCount :one
SELECT count(*) FROM crm.branch_transfers t
JOIN crm.leads l ON l.id = t.lead_id AND l.deleted_at IS NULL
WHERE t.status = 'pending';

-- name: ApprovalTransfers :many
-- Yêu cầu chuyển cơ sở chờ duyệt, mới trước; id khác NULL = một yêu cầu.
SELECT t.id, t.lead_id, t.reason, t.created_at, l.name AS lead_name, l.code AS lead_code, l.converted_student_id,
       fb.name AS from_branch_name, tb.name AS to_branch_name, fu.full_name AS from_user_name,
       tu.full_name AS to_user_name, rq.full_name AS requester_name
FROM crm.branch_transfers t
JOIN crm.leads l ON l.id = t.lead_id AND l.deleted_at IS NULL
LEFT JOIN core.branches fb ON fb.id = t.from_branch_id
LEFT JOIN core.branches tb ON tb.id = t.to_branch_id
LEFT JOIN iam.users fu ON fu.id = t.from_user_id
LEFT JOIN iam.users tu ON tu.id = t.to_user_id
LEFT JOIN iam.users rq ON rq.id = t.requested_by
WHERE t.status = 'pending' AND (sqlc.narg(id)::uuid IS NULL OR t.id = sqlc.narg(id)::uuid)
ORDER BY t.created_at DESC, t.id DESC
LIMIT @lim::int;

-- name: ApprovalConfirmationCount :one
SELECT count(*) FROM crm.enrollment_confirmations c
JOIN crm.leads l ON l.id = c.lead_id AND l.deleted_at IS NULL
WHERE c.status IN ('pending', 'completed') AND c.confirmed_at IS NULL;

-- name: ApprovalConfirmations :many
-- Học viên chốt từ CRM chờ xác nhận chính thức, mới trước.
SELECT c.id, c.student_code, c.student_name, c.class_name, c.enrolled_at, c.created_at, c.account_sent,
       c.zalo_group_added, c.curriculum_delivered, au.full_name AS consultant_name
FROM crm.enrollment_confirmations c
JOIN crm.leads l ON l.id = c.lead_id AND l.deleted_at IS NULL
LEFT JOIN iam.users au ON au.id = l.assigned_user_id
WHERE c.status IN ('pending', 'completed') AND c.confirmed_at IS NULL
  AND (sqlc.narg(id)::uuid IS NULL OR c.id = sqlc.narg(id)::uuid)
ORDER BY c.created_at DESC, c.id DESC
LIMIT @lim::int;
