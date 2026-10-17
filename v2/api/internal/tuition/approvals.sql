-- Nguồn của hộp "Việc cần duyệt" (internal/approvals). Hộp chỉ dành cho Admin (phạm vi học phí toàn hệ thống) nên
-- không lọc chi nhánh.

-- name: ApprovalReceiptCount :one
SELECT count(*) FROM finance.receipts WHERE status = 'pending';

-- name: ApprovalReceipts :many
-- Phiếu thu chờ duyệt, mới trước; id khác NULL = một phiếu.
SELECT r.id, r.code, r.amount, r.payment_method, r.transaction_code, r.payer_name, r.notes, r.created_at,
       st.full_name AS student_name, coalesce(st.code::text, '')::text AS student_code, u.full_name AS creator_name
FROM finance.receipts r
LEFT JOIN finance.tuitions t ON t.id = r.tuition_id
LEFT JOIN academic.students st ON st.id = coalesce(t.student_id, r.student_id)
LEFT JOIN iam.users u ON u.id = r.creator_id
WHERE r.status = 'pending' AND (sqlc.narg(id)::uuid IS NULL OR r.id = sqlc.narg(id)::uuid)
ORDER BY r.created_at DESC, r.id DESC
LIMIT @lim::int;

-- name: ApprovalCancellationCount :one
SELECT count(*) FROM finance.invoice_cancellations WHERE status = 'pending';

-- name: ApprovalCancellations :many
SELECT c.id, c.invoice_number, c.amount, c.reason, c.created_at, st.full_name AS student_name,
       coalesce(st.code::text, '')::text AS student_code, r.code AS receipt_code, u.full_name AS requester_name
FROM finance.invoice_cancellations c
LEFT JOIN academic.students st ON st.id = c.student_id
LEFT JOIN finance.receipts r ON r.id = c.receipt_id
LEFT JOIN iam.users u ON u.id = c.requester_id
WHERE c.status = 'pending' AND (sqlc.narg(id)::uuid IS NULL OR c.id = sqlc.narg(id)::uuid)
ORDER BY c.created_at DESC, c.id DESC
LIMIT @lim::int;

-- name: ApprovalRefundCount :one
SELECT count(*) FROM finance.refund_requests WHERE status = 'pending' AND type = ANY (@types::text[]);

-- name: ApprovalRefunds :many
-- Hồ sơ hoàn tiền / chuyển nhượng / khất nợ / bảo lưu chờ duyệt thuộc loại người xem được duyệt.
SELECT q.id, q.type, q.refund_amount, q.reason, q.created_at, st.full_name AS student_name, st.code::text AS student_code,
       tg.full_name AS target_name, u.full_name AS requester_name
FROM finance.refund_requests q
JOIN academic.students st ON st.id = q.student_id
LEFT JOIN academic.students tg ON tg.id = q.target_student_id
LEFT JOIN iam.users u ON u.id = q.requester_id
WHERE q.status = 'pending' AND q.type = ANY (@types::text[]) AND (sqlc.narg(id)::uuid IS NULL OR q.id = sqlc.narg(id)::uuid)
ORDER BY q.created_at DESC, q.id DESC
LIMIT @lim::int;
