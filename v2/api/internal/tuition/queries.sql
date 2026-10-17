-- Phạm vi chi nhánh của Học phí (TuitionBranchScope của Laravel):
-- khoản học phí thuộc chi nhánh của khoản, không có thì chi nhánh học viên, rồi chi nhánh lớp đang học;
-- phiếu thu theo khoản học phí của phiếu, không gắn khoản thì theo học viên.
-- sqlc.narg(branch_ids) NULL = mọi chi nhánh.

-- ───────────────────────── Khoản học phí ─────────────────────────

-- name: ListTuitions :many
-- Màn Công nợ học viên: mới nhất trước.
SELECT t.*, s.code AS student_code, s.full_name AS student_name, s.phone AS student_phone,
    c.name AS class_name, c.code AS class_code, co.name AS course_name, b.name AS branch_name
FROM finance.tuitions t
JOIN academic.students s ON s.id = t.student_id AND s.deleted_at IS NULL
LEFT JOIN academic.classes cc ON cc.id = s.current_class_id
LEFT JOIN academic.classes c ON c.id = t.class_id
LEFT JOIN academic.courses co ON co.id = c.course_id
LEFT JOIN core.branches b ON b.id = t.branch_id
WHERE (sqlc.narg(branch_ids)::uuid[] IS NULL OR COALESCE(t.branch_id, s.branch_id, cc.branch_id) = ANY (sqlc.narg(branch_ids)::uuid[]))
  AND (sqlc.narg(search)::text IS NULL OR s.full_name ILIKE '%' || sqlc.narg(search) || '%'
       OR s.phone ILIKE '%' || sqlc.narg(search) || '%' OR s.code ILIKE '%' || sqlc.narg(search) || '%')
  AND (sqlc.narg(status)::text IS NULL OR (sqlc.narg(status) = 'overdue' AND t.debt_amount > 0 AND t.due_date < sqlc.arg(today)::date
       AND (t.reminder_paused_until IS NULL OR t.reminder_paused_until <= sqlc.arg(today)::date))
       OR (sqlc.narg(status) <> 'overdue' AND t.status = sqlc.narg(status)))
  AND (sqlc.narg(branch_id)::uuid IS NULL OR t.branch_id = sqlc.narg(branch_id))
  AND (sqlc.narg(class_id)::uuid IS NULL OR t.class_id = sqlc.narg(class_id))
ORDER BY t.created_at DESC, t.id DESC
LIMIT sqlc.arg(page_limit) OFFSET sqlc.arg(page_offset);

-- name: CountTuitions :one
SELECT count(*)
FROM finance.tuitions t
JOIN academic.students s ON s.id = t.student_id AND s.deleted_at IS NULL
LEFT JOIN academic.classes cc ON cc.id = s.current_class_id
WHERE (sqlc.narg(branch_ids)::uuid[] IS NULL OR COALESCE(t.branch_id, s.branch_id, cc.branch_id) = ANY (sqlc.narg(branch_ids)::uuid[]))
  AND (sqlc.narg(search)::text IS NULL OR s.full_name ILIKE '%' || sqlc.narg(search) || '%'
       OR s.phone ILIKE '%' || sqlc.narg(search) || '%' OR s.code ILIKE '%' || sqlc.narg(search) || '%')
  AND (sqlc.narg(status)::text IS NULL OR (sqlc.narg(status) = 'overdue' AND t.debt_amount > 0 AND t.due_date < sqlc.arg(today)::date
       AND (t.reminder_paused_until IS NULL OR t.reminder_paused_until <= sqlc.arg(today)::date))
       OR (sqlc.narg(status) <> 'overdue' AND t.status = sqlc.narg(status)))
  AND (sqlc.narg(branch_id)::uuid IS NULL OR t.branch_id = sqlc.narg(branch_id))
  AND (sqlc.narg(class_id)::uuid IS NULL OR t.class_id = sqlc.narg(class_id));

-- name: TuitionStats :one
-- Thẻ thống kê: theo phạm vi + bộ lọc chi nhánh / lớp (không theo tìm kiếm / trạng thái).
SELECT COALESCE(sum(t.final_amount), 0)::bigint AS final_total, COALESCE(sum(t.paid_amount), 0)::bigint AS paid_total,
    COALESCE(sum(t.debt_amount), 0)::bigint AS debt_total,
    count(*) FILTER (WHERE t.debt_amount > 0 AND t.due_date < sqlc.arg(today)::date
        AND (t.reminder_paused_until IS NULL OR t.reminder_paused_until <= sqlc.arg(today)::date)) AS overdue_count
FROM finance.tuitions t
JOIN academic.students s ON s.id = t.student_id AND s.deleted_at IS NULL
LEFT JOIN academic.classes cc ON cc.id = s.current_class_id
WHERE (sqlc.narg(branch_ids)::uuid[] IS NULL OR COALESCE(t.branch_id, s.branch_id, cc.branch_id) = ANY (sqlc.narg(branch_ids)::uuid[]))
  AND (sqlc.narg(branch_id)::uuid IS NULL OR t.branch_id = sqlc.narg(branch_id))
  AND (sqlc.narg(class_id)::uuid IS NULL OR t.class_id = sqlc.narg(class_id));

-- name: DueTuitions :many
-- Khoản còn nợ đến hạn trong upcoming_days ngày tới hoặc đang tạm dừng nhắc (dueGroups), kèm số buổi và liên hệ gần nhất.
SELECT t.*, s.code AS student_code, s.full_name AS student_name, s.phone AS student_phone,
    c.name AS class_name, c.code AS class_code, co.name AS course_name, b.name AS branch_name,
    COALESCE(sco.total_lessons, 0)::int AS course_lessons,
    (SELECT count(*) FROM academic.class_sessions x
     WHERE x.class_id = COALESCE(t.class_id, s.current_class_id) AND x.status <> 'cancelled')::int AS scheduled_sessions,
    (SELECT count(*) FROM academic.student_attendance a
     WHERE a.student_id = t.student_id AND a.status IN ('present', 'late')
       AND (COALESCE(t.class_id, s.current_class_id) IS NULL OR a.class_id = COALESCE(t.class_id, s.current_class_id)))::int AS attended_sessions,
    COALESCE(t.class_id, s.current_class_id) AS stats_class_id
FROM finance.tuitions t
JOIN academic.students s ON s.id = t.student_id AND s.deleted_at IS NULL
LEFT JOIN academic.classes cc ON cc.id = s.current_class_id
LEFT JOIN academic.classes c ON c.id = t.class_id
LEFT JOIN academic.courses co ON co.id = c.course_id
LEFT JOIN academic.classes sc ON sc.id = COALESCE(t.class_id, s.current_class_id)
LEFT JOIN academic.courses sco ON sco.id = sc.course_id
LEFT JOIN core.branches b ON b.id = t.branch_id
WHERE t.debt_amount > 0 AND t.due_date IS NOT NULL
  AND (t.due_date <= sqlc.arg(until)::date OR t.reminder_paused_until > sqlc.arg(today)::date)
  AND (sqlc.narg(branch_ids)::uuid[] IS NULL OR COALESCE(t.branch_id, s.branch_id, cc.branch_id) = ANY (sqlc.narg(branch_ids)::uuid[]))
  AND (sqlc.narg(search)::text IS NULL OR s.full_name ILIKE '%' || sqlc.narg(search) || '%'
       OR s.phone ILIKE '%' || sqlc.narg(search) || '%' OR s.code ILIKE '%' || sqlc.narg(search) || '%')
  AND (sqlc.narg(branch_id)::uuid IS NULL OR t.branch_id = sqlc.narg(branch_id))
  AND (sqlc.narg(class_id)::uuid IS NULL OR t.class_id = sqlc.narg(class_id))
ORDER BY t.due_date, t.id;

-- name: LastContactLogs :many
-- Liên hệ / báo cáo Admin gần nhất của từng khoản.
SELECT DISTINCT ON (l.tuition_id, l.action) l.tuition_id, l.action, l.note, l.contacted_at, u.full_name AS user_name
FROM finance.tuition_contact_logs l
LEFT JOIN iam.users u ON u.id = l.user_id
WHERE l.tuition_id = ANY (sqlc.arg(ids)::uuid[])
ORDER BY l.tuition_id, l.action, l.contacted_at DESC, l.id DESC;

-- name: DebtSummaryRows :many
-- Màn Quá hạn: toàn bộ khoản đang nợ trong phạm vi (thống kê theo chi nhánh / lớp).
SELECT t.id, t.branch_id, t.class_id, t.debt_amount, t.due_date, t.reminder_paused_until,
    b.name AS branch_name, c.name AS class_name, c.code AS class_code
FROM finance.tuitions t
JOIN academic.students s ON s.id = t.student_id AND s.deleted_at IS NULL
LEFT JOIN academic.classes cc ON cc.id = s.current_class_id
LEFT JOIN academic.classes c ON c.id = t.class_id
LEFT JOIN core.branches b ON b.id = t.branch_id
WHERE t.debt_amount > 0
  AND (sqlc.narg(branch_ids)::uuid[] IS NULL OR COALESCE(t.branch_id, s.branch_id, cc.branch_id) = ANY (sqlc.narg(branch_ids)::uuid[]))
ORDER BY t.id;

-- name: GetTuition :one
-- Khoản học phí kèm học viên / lớp / chi nhánh hiệu lực (kiểm phạm vi).
SELECT t.*, s.code AS student_code, s.full_name AS student_name, s.phone AS student_phone, s.email AS student_email,
    s.parent_name AS student_parent_name, s.parent_phone AS student_parent_phone, s.status AS student_status,
    s.current_class_id AS student_class_id, s.lead_id AS student_lead_id,
    c.name AS class_name, c.code AS class_code, c.course_id AS class_course_id, co.name AS course_name,
    cc.name AS current_class_name, cc.course_id AS current_course_id, b.name AS branch_name,
    COALESCE(t.branch_id, s.branch_id, cc.branch_id) AS scope_branch_id
FROM finance.tuitions t
JOIN academic.students s ON s.id = t.student_id
LEFT JOIN academic.classes cc ON cc.id = s.current_class_id
LEFT JOIN academic.classes c ON c.id = t.class_id
LEFT JOIN academic.courses co ON co.id = c.course_id
LEFT JOIN core.branches b ON b.id = t.branch_id
WHERE t.id = $1;

-- name: LockTuition :one
SELECT * FROM finance.tuitions WHERE id = $1 FOR UPDATE;

-- name: StudentTuitions :many
-- Mọi khoản của học viên (cũ trước) — lịch sử thu, sổ buổi.
SELECT t.*, c.name AS class_name, co.name AS course_name
FROM finance.tuitions t
LEFT JOIN academic.classes c ON c.id = t.class_id
LEFT JOIN academic.courses co ON co.id = c.course_id
WHERE t.student_id = $1
ORDER BY t.created_at, t.id;

-- name: LatestStudentTuition :one
-- $student->tuition của Laravel (hasOne: khoản mới nhất).
SELECT * FROM finance.tuitions WHERE student_id = $1 ORDER BY created_at DESC, id DESC LIMIT 1;

-- name: OpenTuitionFor :one
-- Khoản còn nợ mới nhất của học viên (SessionLedger::openTuitionFor).
SELECT * FROM finance.tuitions WHERE student_id = $1 AND debt_amount > 0 ORDER BY created_at DESC, id DESC LIMIT 1;

-- name: OpenTuitionsForStudents :many
-- Khoản còn nợ của nhiều học viên (cũ trước; lấy khoản đầu mỗi học viên, như openTuitionsFor).
SELECT * FROM finance.tuitions WHERE student_id = ANY (sqlc.arg(student_ids)::uuid[]) AND debt_amount > 0
ORDER BY created_at, id;

-- name: CreateTuition :one
INSERT INTO finance.tuitions (student_id, class_id, branch_id, bank_account_id, promotion_id, total_amount, session_count,
    discount_amount, other_fees, fee_items, prepaid_amount, final_amount, paid_amount, debt_amount, due_date, status, notes,
    transfer_memo, created_by)
VALUES (sqlc.arg(student_id), sqlc.narg(class_id), sqlc.narg(branch_id), sqlc.narg(bank_account_id), sqlc.narg(promotion_id),
    sqlc.arg(total_amount), sqlc.narg(session_count), sqlc.arg(discount_amount), sqlc.arg(other_fees), sqlc.arg(fee_items),
    sqlc.arg(prepaid_amount), sqlc.arg(final_amount), 0, sqlc.arg(debt_amount), sqlc.narg(due_date), sqlc.arg(status),
    sqlc.narg(notes), sqlc.narg(transfer_memo), sqlc.narg(created_by))
RETURNING *;

-- name: SetTuitionDebt :exec
UPDATE finance.tuitions SET paid_amount = $2, debt_amount = $3, status = $4, updated_at = now() WHERE id = $1;

-- name: SetTuitionFinal :exec
-- Hoàn / chuyển phí: giảm số phải nộp của khoản nguồn.
UPDATE finance.tuitions SET final_amount = $2, updated_at = now() WHERE id = $1;

-- name: ApprovedReceiptTotals :one
-- Đã nộp = Σ(phần học phí) và Σ giảm trừ của phiếu đã duyệt (recalculateDebt).
SELECT COALESCE(sum(amount - surcharge_amount), 0)::bigint AS paid_total, COALESCE(sum(discount_amount), 0)::bigint AS discount_total
FROM finance.receipts WHERE tuition_id = $1 AND status = 'approved';

-- name: SetTuitionDue :exec
UPDATE finance.tuitions SET due_date = $2, reminder_paused_until = $3, updated_at = now() WHERE id = $1;

-- name: SetTuitionDeferral :exec
UPDATE finance.tuitions SET deferred_from = $2, deferred_until = $3, frozen_remaining_sessions = $4, frozen_debt_amount = $5,
    reminder_paused_until = $6, due_date = $7, updated_at = now()
WHERE id = $1;

-- name: SetTransferMemo :exec
UPDATE finance.tuitions SET transfer_memo = $2, updated_at = now() WHERE id = $1;

-- ───────────────────────── Học viên ─────────────────────────

-- name: GetStudent :one
-- Học viên kèm lớp đang học, chi nhánh hiệu lực (chi nhánh học viên, rồi chi nhánh lớp).
SELECT s.id, s.code, s.full_name, s.phone, s.email, s.parent_name, s.parent_phone, s.status, s.branch_id, s.current_class_id,
    s.lead_id, s.created_at, s.deleted_at, cc.name AS class_name, cc.code AS class_code, cc.course_id AS class_course_id,
    cc.tuition_fee AS class_tuition_fee, cc.branch_id AS class_branch_id, co.tuition_fee AS course_tuition_fee,
    co.total_lessons AS course_lessons, co.name AS course_name, b.name AS branch_name,
    COALESCE(s.branch_id, cc.branch_id) AS scope_branch_id
FROM academic.students s
LEFT JOIN academic.classes cc ON cc.id = s.current_class_id
LEFT JOIN academic.courses co ON co.id = cc.course_id
LEFT JOIN core.branches b ON b.id = s.branch_id
WHERE s.id = $1;

-- name: SearchStudents :many
-- Ô chọn học viên ở form lập phiếu: học viên chưa thôi học trong phạm vi.
SELECT s.id, s.code, s.full_name, s.phone, s.status, cc.name AS class_name, b.name AS branch_name
FROM academic.students s
LEFT JOIN academic.classes cc ON cc.id = s.current_class_id
LEFT JOIN core.branches b ON b.id = s.branch_id
WHERE s.deleted_at IS NULL AND s.status <> 'dropped'
  AND (sqlc.narg(branch_ids)::uuid[] IS NULL OR COALESCE(s.branch_id, cc.branch_id) = ANY (sqlc.narg(branch_ids)::uuid[]))
  AND (sqlc.narg(search)::text IS NULL OR s.full_name ILIKE '%' || sqlc.narg(search) || '%'
       OR s.phone ILIKE '%' || sqlc.narg(search) || '%' OR s.code ILIKE '%' || sqlc.narg(search) || '%')
ORDER BY s.full_name, s.id
LIMIT 30;

-- name: GetClass :one
SELECT c.id, c.name, c.code, c.branch_id, c.course_id, c.tuition_fee, co.tuition_fee AS course_tuition_fee,
    co.total_lessons AS course_lessons, co.name AS course_name
FROM academic.classes c
LEFT JOIN academic.courses co ON co.id = c.course_id
WHERE c.id = $1;

-- ───────────────────────── Sổ buổi ─────────────────────────

-- name: LedgerTuitions :many
SELECT id, student_id, total_amount, discount_amount, session_count, other_fees, final_amount, debt_amount
FROM finance.tuitions WHERE student_id = ANY (sqlc.arg(student_ids)::uuid[]);

-- name: LedgerPeriods :many
-- Khoảng học viên thuộc lớp: lượt xếp lớp [enrolled_at, left_at); lớp đang học chưa có lượt xếp lớp tính từ ngày tạo hồ sơ.
SELECT e.student_id, e.class_id, e.enrolled_at AS from_day,
    COALESCE(e.left_at, CASE WHEN e.status = 'dropped' THEN (e.updated_at AT TIME ZONE 'Asia/Ho_Chi_Minh')::date END) AS until_day
FROM academic.enrollments e WHERE e.student_id = ANY (sqlc.arg(student_ids)::uuid[])
UNION ALL
SELECT s.id, s.current_class_id, (s.created_at AT TIME ZONE 'Asia/Ho_Chi_Minh')::date, NULL::date
FROM academic.students s
WHERE s.id = ANY (sqlc.arg(student_ids)::uuid[]) AND s.current_class_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM academic.enrollments e WHERE e.student_id = s.id AND e.class_id = s.current_class_id);

-- name: CountedSessions :many
-- Buổi đã diễn ra tính vào sổ buổi: chính khóa + học bù, không hủy, đã tới giờ bắt đầu.
SELECT id, class_id, date FROM academic.class_sessions
WHERE class_id = ANY (sqlc.arg(class_ids)::uuid[]) AND type IN ('regular', 'makeup') AND status <> 'cancelled'
  AND (date < sqlc.arg(today)::date OR (date = sqlc.arg(today)::date AND start_time <= sqlc.arg(now_time)::time));

-- name: ApprovedDeferrals :many
SELECT student_id, defer_from, defer_to FROM finance.refund_requests
WHERE student_id = ANY (sqlc.arg(student_ids)::uuid[]) AND type = 'deferral' AND status = 'approved'
  AND defer_from IS NOT NULL AND defer_to IS NOT NULL;

-- ───────────────────────── Phiếu thu ─────────────────────────

-- name: NextReceiptSeq :one
SELECT nextval('finance.receipt_seq')::bigint;

-- name: CreateReceipt :one
INSERT INTO finance.receipts (code, source, invoice_number, tuition_id, student_id, amount, tuition_amount, session_count,
    session_unit_price, material_fee, exam_fee, other_fee, other_fee_reason, surcharge_amount, surcharge_reason,
    discount_amount, promotion_id, discount_reason, payment_method, transaction_code, payer_name, payer_phone,
    is_vat_invoice, paper_invoice_number, proof_url, payment_date, collected_items, status, notes, creator_id,
    approver_id, approved_at)
VALUES (sqlc.arg(code), sqlc.arg(source), sqlc.narg(invoice_number), sqlc.narg(tuition_id), sqlc.narg(student_id),
    sqlc.arg(amount), sqlc.arg(tuition_amount), sqlc.narg(session_count), sqlc.narg(session_unit_price),
    sqlc.arg(material_fee), sqlc.arg(exam_fee), sqlc.arg(other_fee), sqlc.narg(other_fee_reason),
    sqlc.arg(surcharge_amount), sqlc.narg(surcharge_reason), sqlc.arg(discount_amount), sqlc.narg(promotion_id),
    sqlc.narg(discount_reason), sqlc.arg(payment_method), sqlc.narg(transaction_code), sqlc.narg(payer_name),
    sqlc.narg(payer_phone), sqlc.arg(is_vat_invoice), sqlc.narg(paper_invoice_number), sqlc.narg(proof_url),
    sqlc.narg(payment_date), sqlc.arg(collected_items), sqlc.arg(status), sqlc.narg(notes), sqlc.narg(creator_id),
    sqlc.narg(approver_id), sqlc.narg(approved_at))
RETURNING *;

-- name: UpdateReceipt :one
UPDATE finance.receipts SET
    tuition_id = sqlc.narg(tuition_id), amount = sqlc.arg(amount), tuition_amount = sqlc.arg(tuition_amount),
    session_count = sqlc.narg(session_count), session_unit_price = sqlc.narg(session_unit_price),
    material_fee = sqlc.arg(material_fee), exam_fee = sqlc.arg(exam_fee), other_fee = sqlc.arg(other_fee),
    other_fee_reason = sqlc.narg(other_fee_reason), surcharge_amount = sqlc.arg(surcharge_amount),
    surcharge_reason = sqlc.narg(surcharge_reason), discount_amount = sqlc.arg(discount_amount),
    promotion_id = sqlc.narg(promotion_id), discount_reason = sqlc.narg(discount_reason),
    payment_method = sqlc.arg(payment_method), transaction_code = sqlc.narg(transaction_code),
    invoice_number = sqlc.narg(invoice_number), paper_invoice_number = sqlc.narg(paper_invoice_number),
    payer_name = sqlc.narg(payer_name), payer_phone = sqlc.narg(payer_phone), is_vat_invoice = sqlc.arg(is_vat_invoice),
    proof_url = sqlc.narg(proof_url), collected_items = sqlc.arg(collected_items), notes = sqlc.narg(notes),
    status = sqlc.arg(status), approver_id = NULL, rejection_reason = sqlc.narg(rejection_reason), updated_at = now()
WHERE id = sqlc.arg(id)
RETURNING *;

-- name: GetReceipt :one
-- Phiếu thu kèm học viên (của khoản học phí, không có thì của phiếu), lớp, chi nhánh hiệu lực, người lập / duyệt / nộp.
SELECT r.*, st.code AS student_code, st.full_name AS student_name, st.phone AS student_phone, st.email AS student_email,
    st.parent_name AS student_parent_name, st.parent_phone AS student_parent_phone, st.lead_id AS student_lead_id,
    st.id AS owner_student_id,
    tc.name AS tuition_class_name, cc.name AS current_class_name, tco.name AS course_name,
    sb.name AS branch_name, COALESCE(t.branch_id, st.branch_id, cc.branch_id) AS scope_branch_id,
    t.due_date AS tuition_due_date, t.final_amount AS tuition_final, t.debt_amount AS tuition_debt,
    jsonb_array_length(COALESCE(t.fee_items, '[]')) AS tuition_fee_item_count, t.bank_account_id AS tuition_bank_account_id,
    t.branch_id AS tuition_branch_id, tp.name AS contract_promotion_name, tp.reason AS contract_promotion_reason,
    p.name AS promotion_name, cu.full_name AS creator_name, au.full_name AS approver_name, du.full_name AS depositor_name
FROM finance.receipts r
LEFT JOIN finance.tuitions t ON t.id = r.tuition_id
LEFT JOIN academic.students st ON st.id = COALESCE(t.student_id, r.student_id)
LEFT JOIN academic.classes cc ON cc.id = st.current_class_id
LEFT JOIN academic.classes tc ON tc.id = t.class_id
LEFT JOIN academic.courses tco ON tco.id = tc.course_id
LEFT JOIN core.branches sb ON sb.id = st.branch_id
LEFT JOIN crm.promotions tp ON tp.id = t.promotion_id
LEFT JOIN crm.promotions p ON p.id = r.promotion_id
LEFT JOIN iam.users cu ON cu.id = r.creator_id
LEFT JOIN iam.users au ON au.id = r.approver_id
LEFT JOIN iam.users du ON du.id = r.deposited_by
WHERE r.id = $1;

-- name: LockReceipt :one
SELECT * FROM finance.receipts WHERE id = $1 FOR UPDATE;

-- name: ReceiptTuitionID :one
SELECT tuition_id FROM finance.receipts WHERE id = $1;

-- name: ApproveReceipt :one
UPDATE finance.receipts SET invoice_number = $2, status = 'approved', approver_id = $3, approved_at = now(),
    rejection_reason = NULL, tuition_id = $4, updated_at = now()
WHERE id = $1
RETURNING *;

-- name: RejectReceipt :one
UPDATE finance.receipts SET status = 'rejected', approver_id = $2, rejection_reason = $3, updated_at = now()
WHERE id = $1
RETURNING *;

-- name: CancelReceipt :one
UPDATE finance.receipts SET status = 'cancelled', notes = $2, updated_at = now() WHERE id = $1 RETURNING *;

-- name: ConfirmDeposit :one
UPDATE finance.receipts SET deposited_at = now(), deposited_by = $2, updated_at = now()
WHERE id = $1 AND deposited_at IS NULL
RETURNING *;

-- name: ReceiptByTransferReference :one
-- Phiếu khác (chờ duyệt / đã duyệt) đang giữ mã giao dịch này.
SELECT id, code, status FROM finance.receipts
WHERE transfer_reference = sqlc.arg(reference)::text AND (sqlc.narg(ignore_id)::uuid IS NULL OR id <> sqlc.narg(ignore_id))
LIMIT 1;

-- name: InvoiceNumberTaken :one
SELECT EXISTS (SELECT 1 FROM finance.receipts WHERE invoice_number = $1);

-- name: StudentReceipts :many
-- Phiếu của học viên (gắn học viên hoặc gắn khoản học phí của học viên), kèm người lập / duyệt.
SELECT r.*, cu.full_name AS creator_name, au.full_name AS approver_name
FROM finance.receipts r
LEFT JOIN iam.users cu ON cu.id = r.creator_id
LEFT JOIN iam.users au ON au.id = r.approver_id
WHERE r.student_id = sqlc.arg(student_id)::uuid
   OR r.tuition_id IN (SELECT t.id FROM finance.tuitions t WHERE t.student_id = sqlc.arg(student_id)::uuid);

-- name: LatestRejection :one
SELECT id, code, rejection_reason, status, creator_id FROM finance.receipts
WHERE tuition_id = $1 AND status = 'rejected' AND rejection_reason IS NOT NULL
ORDER BY created_at DESC, id DESC LIMIT 1;

-- name: ReceiptMetrics :one
-- Màn Duyệt phiếu thu: chờ duyệt, đã duyệt / từ chối hôm nay trong phạm vi.
SELECT count(*) FILTER (WHERE r.status = 'pending') AS pending_count,
    COALESCE(sum(r.amount) FILTER (WHERE r.status = 'pending'), 0)::bigint AS pending_total,
    count(*) FILTER (WHERE r.status = 'approved' AND r.approved_at >= sqlc.arg(day_start)::timestamptz
        AND r.approved_at < sqlc.arg(day_end)::timestamptz) AS approved_today,
    count(*) FILTER (WHERE r.status = 'rejected' AND r.updated_at >= sqlc.arg(day_start)::timestamptz
        AND r.updated_at < sqlc.arg(day_end)::timestamptz) AS rejected_today
FROM finance.receipts r
LEFT JOIN finance.tuitions t ON t.id = r.tuition_id
LEFT JOIN academic.students st ON st.id = COALESCE(t.student_id, r.student_id)
LEFT JOIN academic.classes cc ON cc.id = st.current_class_id
WHERE sqlc.narg(branch_ids)::uuid[] IS NULL OR COALESCE(t.branch_id, st.branch_id, cc.branch_id) = ANY (sqlc.narg(branch_ids)::uuid[]);

-- name: ListReceipts :many
-- Màn Duyệt phiếu thu và Lịch sử thu (lọc theo trạng thái / hình thức / chi nhánh / học viên / ngày thu / tái tục).
SELECT r.*, st.code AS student_code, st.full_name AS student_name, tc.name AS tuition_class_name, cc.name AS current_class_name,
    tco.name AS course_name, sb.name AS branch_name,
    jsonb_array_length(COALESCE(t.fee_items, '[]')) AS tuition_fee_item_count,
    cu.full_name AS creator_name, au.full_name AS approver_name, du.full_name AS depositor_name
FROM finance.receipts r
LEFT JOIN finance.tuitions t ON t.id = r.tuition_id
LEFT JOIN academic.students st ON st.id = COALESCE(t.student_id, r.student_id)
LEFT JOIN academic.classes cc ON cc.id = st.current_class_id
LEFT JOIN academic.classes tc ON tc.id = t.class_id
LEFT JOIN academic.courses tco ON tco.id = tc.course_id
LEFT JOIN core.branches sb ON sb.id = st.branch_id
LEFT JOIN iam.users cu ON cu.id = r.creator_id
LEFT JOIN iam.users au ON au.id = r.approver_id
LEFT JOIN iam.users du ON du.id = r.deposited_by
WHERE (sqlc.narg(branch_ids)::uuid[] IS NULL OR COALESCE(t.branch_id, st.branch_id, cc.branch_id) = ANY (sqlc.narg(branch_ids)::uuid[]))
  AND (sqlc.narg(status)::text IS NULL OR r.status = sqlc.narg(status))
  AND (sqlc.narg(method)::text IS NULL OR r.payment_method = sqlc.narg(method))
  AND (sqlc.narg(branch_id)::uuid IS NULL OR st.branch_id = sqlc.narg(branch_id))
  AND (sqlc.narg(student_id)::uuid IS NULL OR COALESCE(t.student_id, r.student_id) = sqlc.narg(student_id))
  AND (sqlc.narg(from_day)::date IS NULL OR r.payment_date >= sqlc.narg(from_day))
  AND (sqlc.narg(to_day)::date IS NULL OR r.payment_date <= sqlc.narg(to_day))
  AND (NOT sqlc.arg(renewal_only)::boolean OR (t.id IS NOT NULL AND EXISTS (
        SELECT 1 FROM finance.tuitions t0 WHERE t0.student_id = t.student_id AND (t0.created_at, t0.id) < (t.created_at, t.id))))
  AND (sqlc.narg(search)::text IS NULL OR r.code ILIKE '%' || sqlc.narg(search) || '%'
       OR r.transaction_code ILIKE '%' || sqlc.narg(search) || '%' OR r.payer_name ILIKE '%' || sqlc.narg(search) || '%'
       OR r.invoice_number ILIKE '%' || sqlc.narg(search) || '%'
       OR st.full_name ILIKE '%' || sqlc.narg(search) || '%' OR st.code ILIKE '%' || sqlc.narg(search) || '%')
ORDER BY r.created_at DESC, r.id DESC
LIMIT sqlc.arg(page_limit) OFFSET sqlc.arg(page_offset);

-- name: CountReceipts :one
SELECT count(*), COALESCE(sum(r.amount), 0)::bigint AS amount_total
FROM finance.receipts r
LEFT JOIN finance.tuitions t ON t.id = r.tuition_id
LEFT JOIN academic.students st ON st.id = COALESCE(t.student_id, r.student_id)
LEFT JOIN academic.classes cc ON cc.id = st.current_class_id
WHERE (sqlc.narg(branch_ids)::uuid[] IS NULL OR COALESCE(t.branch_id, st.branch_id, cc.branch_id) = ANY (sqlc.narg(branch_ids)::uuid[]))
  AND (sqlc.narg(status)::text IS NULL OR r.status = sqlc.narg(status))
  AND (sqlc.narg(method)::text IS NULL OR r.payment_method = sqlc.narg(method))
  AND (sqlc.narg(branch_id)::uuid IS NULL OR st.branch_id = sqlc.narg(branch_id))
  AND (sqlc.narg(student_id)::uuid IS NULL OR COALESCE(t.student_id, r.student_id) = sqlc.narg(student_id))
  AND (sqlc.narg(from_day)::date IS NULL OR r.payment_date >= sqlc.narg(from_day))
  AND (sqlc.narg(to_day)::date IS NULL OR r.payment_date <= sqlc.narg(to_day))
  AND (NOT sqlc.arg(renewal_only)::boolean OR (t.id IS NOT NULL AND EXISTS (
        SELECT 1 FROM finance.tuitions t0 WHERE t0.student_id = t.student_id AND (t0.created_at, t0.id) < (t.created_at, t.id))))
  AND (sqlc.narg(search)::text IS NULL OR r.code ILIKE '%' || sqlc.narg(search) || '%'
       OR r.transaction_code ILIKE '%' || sqlc.narg(search) || '%' OR r.payer_name ILIKE '%' || sqlc.narg(search) || '%'
       OR r.invoice_number ILIKE '%' || sqlc.narg(search) || '%'
       OR st.full_name ILIKE '%' || sqlc.narg(search) || '%' OR st.code ILIKE '%' || sqlc.narg(search) || '%');

-- name: PendingCancellationExists :one
SELECT EXISTS (SELECT 1 FROM finance.invoice_cancellations WHERE receipt_id = $1 AND status = 'pending');

-- ───────────────────────── Ưu đãi / hàng hóa / tài khoản ─────────────────────────

-- name: GetPromotion :one
SELECT * FROM crm.promotions WHERE id = $1;

-- name: AvailablePromotions :many
-- Ưu đãi trong danh mục (không phải ưu đãi riêng) còn hiệu lực, cộng ưu đãi phiếu đang sửa đã chọn.
SELECT * FROM crm.promotions
WHERE NOT is_special AND (
    (is_active AND (starts_at IS NULL OR starts_at <= now()) AND (ends_at IS NULL OR ends_at >= now())
     AND (usage_limit IS NULL OR used_count < usage_limit))
    OR id = sqlc.narg(keep_id))
ORDER BY is_default DESC, name, id;

-- name: IncrementPromotionUse :exec
UPDATE crm.promotions SET used_count = used_count + 1, updated_at = now() WHERE id = $1;

-- name: ActiveItemsByIDs :many
SELECT id, code, name, unit, price FROM ops.inventory_items
WHERE id = ANY (sqlc.arg(ids)::uuid[]) AND deleted_at IS NULL AND is_active;

-- name: ActiveItems :many
SELECT id, code, name, unit, price, category FROM ops.inventory_items WHERE deleted_at IS NULL AND is_active ORDER BY category, name, id;

-- name: BranchStock :many
SELECT item_id, quantity FROM ops.stock WHERE branch_id = $1;

-- name: ActiveBankAccounts :many
SELECT a.id, a.bank_code, a.bank_name, a.account_number, a.account_holder, a.branch_id, a.is_default_vietqr, b.name AS branch_name
FROM finance.bank_accounts a
LEFT JOIN core.branches b ON b.id = a.branch_id
WHERE a.deleted_at IS NULL AND a.is_active
ORDER BY a.is_default_vietqr DESC, (a.branch_id IS NOT NULL), a.created_at, a.id;

-- name: ScopeBranches :many
SELECT id, code, name, is_active FROM core.branches
WHERE sqlc.narg(ids)::uuid[] IS NULL OR id = ANY (sqlc.narg(ids)::uuid[])
ORDER BY name, id;

-- name: ScopeClasses :many
SELECT id, code, name, branch_id FROM academic.classes
WHERE deleted_at IS NULL AND (sqlc.narg(ids)::uuid[] IS NULL OR branch_id = ANY (sqlc.narg(ids)::uuid[]))
ORDER BY name, id;

-- ───────────────────────── Dải số hóa đơn ─────────────────────────

-- name: ElectronicRangeCandidates :many
-- Dải điện tử đang dùng của chi nhánh trước, rồi dải mặc định.
SELECT id FROM finance.invoice_ranges
WHERE kind = 'electronic' AND is_active AND (branch_id = sqlc.narg(branch_id) OR branch_id IS NULL)
ORDER BY (branch_id IS NULL), start_number, id;

-- name: DefaultElectronicRangeExists :one
SELECT EXISTS (SELECT 1 FROM finance.invoice_ranges WHERE kind = 'electronic' AND branch_id IS NULL);

-- name: PaperRanges :many
-- Dải giấy còn số của chi nhánh.
SELECT * FROM finance.invoice_ranges
WHERE kind = 'paper' AND branch_id = $1 AND is_active AND (end_number IS NULL OR current_number <= end_number)
ORDER BY start_number, id;

-- name: LockInvoiceRange :one
SELECT * FROM finance.invoice_ranges WHERE id = $1 FOR UPDATE;

-- name: SetRangeCurrent :exec
UPDATE finance.invoice_ranges SET current_number = $2, updated_at = now() WHERE id = $1;

-- name: CreateInvoiceRange :one
INSERT INTO finance.invoice_ranges (branch_id, kind, template_code, series_code, start_number, end_number, current_number,
    provider, auto_issue, is_active, created_by)
VALUES (sqlc.narg(branch_id), sqlc.arg(kind), sqlc.arg(template_code), sqlc.arg(series_code), sqlc.arg(start_number),
    sqlc.narg(end_number), sqlc.arg(current_number), sqlc.arg(provider), sqlc.arg(auto_issue), sqlc.arg(is_active),
    sqlc.narg(created_by))
RETURNING *;

-- name: UpdateInvoiceRange :one
UPDATE finance.invoice_ranges SET template_code = $2, series_code = $3, start_number = $4, end_number = $5,
    current_number = $6, provider = $7, auto_issue = $8, updated_at = now()
WHERE id = $1
RETURNING *;

-- name: ToggleInvoiceRange :one
UPDATE finance.invoice_ranges SET is_active = NOT is_active, updated_at = now() WHERE id = $1 RETURNING *;

-- name: GetInvoiceRange :one
SELECT r.*, b.name AS branch_name FROM finance.invoice_ranges r LEFT JOIN core.branches b ON b.id = r.branch_id WHERE r.id = $1;

-- name: ListInvoiceRanges :many
-- Dải của chi nhánh trong phạm vi + dải mặc định.
SELECT r.*, b.name AS branch_name FROM finance.invoice_ranges r
LEFT JOIN core.branches b ON b.id = r.branch_id
WHERE sqlc.narg(branch_ids)::uuid[] IS NULL OR r.branch_id IS NULL OR r.branch_id = ANY (sqlc.narg(branch_ids)::uuid[])
ORDER BY (r.branch_id IS NOT NULL), b.name, r.kind, r.start_number, r.id;

-- name: SeriesRanges :many
SELECT * FROM finance.invoice_ranges WHERE series_code = $1 AND id <> sqlc.arg(ignore_id)::uuid;

-- name: MaxIssuedInvoice :one
-- Số lớn nhất đã cấp trong dải (số đệm đủ 7 chữ số nên so chuỗi đúng thứ tự).
SELECT max(invoice_number)::text FROM finance.receipts
WHERE invoice_number LIKE sqlc.arg(series)::text || '-_______' AND invoice_number BETWEEN sqlc.arg(low)::text AND sqlc.arg(high)::text;

-- name: CountIssuedInvoices :one
SELECT count(*) FROM finance.receipts
WHERE invoice_number LIKE sqlc.arg(series)::text || '-_______' AND invoice_number BETWEEN sqlc.arg(low)::text AND sqlc.arg(high)::text;

-- ───────────────────────── SePay (đối soát khi duyệt) ─────────────────────────

-- name: AppliedBankTransaction :one
-- Giao dịch SePay cùng mã (mã giao dịch / mã tham chiếu) đã gạch nợ bằng một phiếu khác.
SELECT bt.id, bt.provider_txn_id, bt.transfer_amount, bt.transaction_date, r.code AS receipt_code
FROM finance.bank_transactions bt
LEFT JOIN finance.receipts r ON r.id = bt.matched_receipt_id
WHERE bt.matched_receipt_id IS NOT NULL
  AND (sqlc.narg(ignore_id)::uuid IS NULL OR bt.matched_receipt_id <> sqlc.narg(ignore_id))
  AND (upper(bt.provider_txn_id) = sqlc.arg(reference)::text OR upper(bt.reference_code) = sqlc.arg(reference)::text)
ORDER BY bt.created_at
LIMIT 1;

-- name: SimilarBankTransactions :many
-- Giao dịch SePay đã gạch nợ cho cùng học viên / khoản học phí trong khoảng ngày (cảnh báo trùng).
SELECT bt.id, bt.provider_txn_id, bt.transfer_amount, bt.transaction_date, r.code AS receipt_code, r.amount AS receipt_amount
FROM finance.bank_transactions bt
LEFT JOIN finance.receipts r ON r.id = bt.matched_receipt_id
WHERE bt.matched_receipt_id IS NOT NULL AND bt.matched_receipt_id <> sqlc.arg(receipt_id)::uuid
  AND ((sqlc.narg(tuition_id)::uuid IS NOT NULL AND bt.matched_tuition_id = sqlc.narg(tuition_id))
       OR (sqlc.narg(student_id)::uuid IS NOT NULL AND bt.matched_student_id = sqlc.narg(student_id)))
  AND bt.transaction_date BETWEEN sqlc.arg(from_time)::timestamptz AND sqlc.arg(to_time)::timestamptz
ORDER BY bt.transaction_date;

-- name: StatementForReference :one
-- Sao kê SePay khớp mã của phiếu (trạng thái đối soát).
SELECT id, provider_txn_id, transfer_amount, transaction_date, account_number FROM finance.bank_transactions
WHERE upper(provider_txn_id) = sqlc.arg(reference)::text OR upper(reference_code) = sqlc.arg(reference)::text
ORDER BY transaction_date DESC NULLS LAST
LIMIT 1;

-- name: LinkUnappliedBankTransactions :exec
-- Giao dịch cùng mã chưa gạch nợ → đã đối soát bằng phiếu tay.
UPDATE finance.bank_transactions SET status = 'manual_matched', matched_receipt_id = sqlc.arg(receipt_id),
    matched_tuition_id = sqlc.narg(tuition_id), matched_student_id = sqlc.narg(student_id),
    response_message = sqlc.arg(message), updated_at = now()
WHERE matched_receipt_id IS NULL
  AND (upper(provider_txn_id) = sqlc.arg(reference)::text OR upper(reference_code) = sqlc.arg(reference)::text);

-- name: PaymentConfirmTarget :one
-- Học viên nhận xác nhận đã nộp học phí + người phụ trách khách CRM đã chốt ra học viên.
SELECT s.id, s.full_name, s.phone, s.parent_phone, s.email, s.user_id, l.assigned_user_id AS owner_id, l.email AS lead_email
FROM academic.students s
LEFT JOIN LATERAL (SELECT x.assigned_user_id, x.email FROM crm.leads x WHERE x.converted_student_id = s.id
                   ORDER BY x.converted_at DESC NULLS LAST, x.id DESC LIMIT 1) l ON true
WHERE s.id = $1;

-- ───────────────────────── Kho (xuất / hoàn theo phiếu thu) ─────────────────────────

-- name: IssuedByReceipt :many
SELECT item_id, branch_id, source, sum(quantity_change)::int AS net
FROM ops.stock_movements WHERE receipt_id = $1
GROUP BY item_id, branch_id, source;

-- name: IssuedByTuitionContract :many
SELECT item_id, sum(quantity_change)::int AS net
FROM ops.stock_movements WHERE tuition_id = $1 AND source = 'contract'
GROUP BY item_id;

-- name: TuitionFeeItems :one
SELECT fee_items FROM finance.tuitions WHERE id = $1;

-- name: ItemsByIDs :many
SELECT id, name, unit FROM ops.inventory_items WHERE id = ANY (sqlc.arg(ids)::uuid[]);

-- ───────────────────────── Chốt khách (CRM) ─────────────────────────

-- name: GetCourse :one
SELECT id, name, tuition_fee, total_lessons, is_active FROM academic.courses WHERE id = $1 AND deleted_at IS NULL;

-- name: SetWaitingTuitionClass :exec
-- Xếp lớp cho học viên Chờ xếp lớp: khoản học phí chưa gắn lớp nhận lớp vừa xếp (WaitingLeadPlacement::complete).
UPDATE finance.tuitions SET class_id = sqlc.arg(class_id), updated_at = now() WHERE student_id = sqlc.arg(student_id) AND class_id IS NULL;

-- name: LeadOfStudent :one
-- Khách CRM đã chốt ra học viên (mới nhất).
SELECT id FROM crm.leads WHERE converted_student_id = $1 ORDER BY converted_at DESC NULLS LAST, id DESC LIMIT 1;

-- name: PendingReceiptTotal :one
-- Tiền của phiếu đang chờ duyệt của khoản học phí (bill "Thông báo nộp học phí").
SELECT COALESCE(sum(amount), 0)::bigint FROM finance.receipts WHERE tuition_id = $1 AND status = 'pending';

-- name: BillBankAccounts :many
-- Tài khoản nhận tiền cho bill: tài khoản của hợp đồng, rồi tài khoản VietQR mặc định của chi nhánh / hệ thống.
SELECT id, bank_code, bank_name, account_number, account_holder, branch_id, is_default_vietqr
FROM finance.bank_accounts WHERE deleted_at IS NULL AND is_active ORDER BY created_at, id;

-- name: BillLead :one
SELECT id, code, name FROM crm.leads WHERE id = $1;

-- name: StudentTotalLessons :one
SELECT total_lessons FROM academic.students WHERE id = $1;

-- name: StudentsTuitionTotals :one
-- Tổng đã thu / còn nợ của các học viên (màn Khách chốt thành công của CRM).
SELECT coalesce(sum(paid_amount), 0)::bigint AS paid, coalesce(sum(debt_amount), 0)::bigint AS debt
FROM finance.tuitions WHERE student_id = ANY (sqlc.arg(student_ids)::uuid[]);

-- name: StudentsLatestTuition :many
-- Khoản học phí mới nhất của mỗi học viên kèm tổng phiếu thu đang chờ duyệt.
SELECT DISTINCT ON (t.student_id) t.student_id, t.id, t.status, t.paid_amount, t.debt_amount,
       coalesce((SELECT sum(r.amount) FROM finance.receipts r WHERE r.tuition_id = t.id AND r.status = 'pending'), 0)::bigint AS pending
FROM finance.tuitions t
WHERE t.student_id = ANY (sqlc.arg(student_ids)::uuid[])
ORDER BY t.student_id, t.created_at DESC, t.id DESC;

-- name: BillReceipts :many
-- Các đợt thu của khoản học phí (bảng "Lịch sử thanh toán các đợt" trên bill).
SELECT id, code, invoice_number, paper_invoice_number, status, payment_method, payment_date, amount, notes, created_at
FROM finance.receipts WHERE tuition_id = $1
ORDER BY created_at, id;

-- name: BillClass :one
SELECT schedule_text, start_date, end_date FROM academic.classes WHERE id = $1;

-- name: CenterSettings :many
-- Thông tin trung tâm in trên bill (CenterInfo của Laravel).
SELECT key, coalesce(value #>> '{}', '')::text AS value FROM core.settings
WHERE key IN ('center_name', 'center_phone');

-- name: CenterBranches :many
SELECT name, address::text AS address FROM core.branches
WHERE is_active AND address IS NOT NULL AND address <> ''
ORDER BY created_at, id;

-- name: LockLatestTuitionDeferral :one
-- Khoản học phí mới nhất của học viên (khoảng bảo lưu / đóng băng) — StudentDeferralService.
SELECT id, deferred_from, deferred_until, frozen_remaining_sessions, frozen_debt_amount, reminder_paused_until, notes
FROM finance.tuitions WHERE student_id = $1
ORDER BY created_at DESC, id DESC LIMIT 1 FOR UPDATE;

-- name: LatestTuitionDeferredUntil :one
SELECT deferred_until FROM finance.tuitions WHERE student_id = $1 ORDER BY created_at DESC, id DESC LIMIT 1;

-- name: ShortenActiveDeferrals :exec
-- Kết thúc bảo lưu sớm: khoảng bảo lưu đã duyệt đang chạy kết thúc hôm qua (không trước ngày bắt đầu).
UPDATE finance.refund_requests SET defer_to = greatest(defer_from, sqlc.arg(today)::date - 1), updated_at = now()
WHERE student_id = sqlc.arg(student_id) AND type = 'deferral' AND status = 'approved'
  AND defer_from <= sqlc.arg(today)::date AND defer_to >= sqlc.arg(today)::date;

-- name: ReleaseTuitionDeferral :exec
UPDATE finance.tuitions SET deferred_from = NULL, deferred_until = NULL, frozen_remaining_sessions = NULL,
    frozen_debt_amount = NULL, reminder_paused_until = sqlc.narg(reminder_paused_until), notes = sqlc.narg(notes), updated_at = now()
WHERE id = sqlc.arg(id);

-- name: UserFullName :one
SELECT full_name FROM iam.users WHERE id = $1;

-- name: ReportDayReceipts :many
-- Báo cáo ngày Học vụ "Số lượng thu phí": phiếu thu có ngày thu là ngày báo cáo, đang chờ duyệt hoặc đã duyệt, của học
-- viên thuộc chi nhánh người viết (DailyReportMetrics).
SELECT r.id, r.amount, r.payment_method, r.status, s.full_name AS student_name, s.branch_id AS student_branch_id
FROM finance.receipts r
LEFT JOIN finance.tuitions t ON t.id = r.tuition_id
JOIN academic.students s ON s.id = coalesce(r.student_id, t.student_id)
WHERE r.payment_date = sqlc.arg(day)::date AND r.status IN ('pending', 'approved') AND s.deleted_at IS NULL
  AND (sqlc.narg(branch_ids)::uuid[] IS NULL OR s.branch_id = ANY (sqlc.narg(branch_ids)::uuid[]))
ORDER BY r.created_at, r.id;

-- name: BranchNames :many
SELECT id, name FROM core.branches WHERE id = ANY (sqlc.arg(ids)::uuid[]);

-- name: CancelOpenTuitionReceipts :execrows
-- Hủy chốt khách: phiếu nháp / chờ duyệt của khoản học phí chuyển Đã hủy (chưa xuất kho, chưa gạch nợ).
UPDATE finance.receipts SET status = 'cancelled', updated_at = now()
WHERE tuition_id = $1 AND status IN ('draft', 'pending');

-- name: SetTuitionFinalAndNotes :exec
UPDATE finance.tuitions SET final_amount = sqlc.arg(final_amount), notes = sqlc.narg(notes), updated_at = now() WHERE id = sqlc.arg(id);

-- name: CreateRefundRequest :one
INSERT INTO finance.refund_requests (student_id, tuition_id, type, total_paid, attended_lessons, admin_fee, refund_amount,
    target_student_id, extended_due_date, defer_from, defer_to, reason, no_transfer_reason, proof_url, requester_id, status)
VALUES (sqlc.arg(student_id), sqlc.narg(tuition_id), sqlc.arg(type), sqlc.arg(total_paid), sqlc.arg(attended_lessons),
    sqlc.arg(admin_fee), sqlc.arg(refund_amount), sqlc.narg(target_student_id), sqlc.narg(extended_due_date),
    sqlc.narg(defer_from), sqlc.narg(defer_to), sqlc.arg(reason), sqlc.narg(no_transfer_reason), sqlc.narg(proof_url),
    sqlc.narg(requester_id), 'pending')
RETURNING *;
