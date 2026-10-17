-- Truy vấn của phân hệ CRM. Phạm vi dữ liệu: scope_owner (mức "của tôi": người phụ trách) và scope_branches
-- (mức chi nhánh: chi nhánh của người xem; mảng rỗng = không thấy gì); cả hai NULL = toàn hệ thống.
-- Loại nhật ký tính là một lần chăm sóc (Laravel CrmCustomerHistory::CARE_TYPES):
-- call, message, meet, test, result, trial, stage_change; liên hệ "failed" không tính.

-- ───────────────────────── Khách ─────────────────────────

-- name: ListLeads :many
-- Danh sách khách (Danh sách, Đã chốt, Thất bại, xuất Excel). date_col: created_at | converted_at | lost_at.
SELECT l.id, l.code, l.name, l.phone, l.parent_name, l.parent_phone, l.email, l.source, l.stage, l.course_interest,
       l.deal_value, l.branch_id, l.assigned_user_id, l.appointment_at, l.appointment_type, l.next_follow_up_at,
       l.lost_reason, l.lost_at, l.converted_at, l.converted_student_id, l.notes, l.created_at, l.updated_at,
       b.name AS branch_name, u.full_name AS assigned_user_name, l.last_care_at,
       coalesce(ec.class_name, '')::text AS class_name, ec.confirmed_at AS enrollment_confirmed_at,
       count(*) OVER () AS total
FROM crm.leads l
LEFT JOIN core.branches b ON b.id = l.branch_id
LEFT JOIN iam.users u ON u.id = l.assigned_user_id
LEFT JOIN LATERAL (SELECT c.class_name, c.confirmed_at FROM crm.enrollment_confirmations c
    WHERE c.lead_id = l.id AND c.status <> 'cancelled' ORDER BY c.enrolled_at DESC LIMIT 1) ec ON true
WHERE l.deleted_at IS NULL
  AND (sqlc.narg(scope_owner)::uuid IS NULL OR l.assigned_user_id = sqlc.narg(scope_owner)::uuid)
  AND (sqlc.narg(scope_branches)::uuid[] IS NULL OR l.branch_id = ANY (sqlc.narg(scope_branches)::uuid[]))
  AND (sqlc.narg(q)::text IS NULL OR l.name ILIKE sqlc.narg(q)::text OR l.phone ILIKE sqlc.narg(q)::text
       OR l.code ILIKE sqlc.narg(q)::text OR l.email::text ILIKE sqlc.narg(q)::text OR l.parent_name ILIKE sqlc.narg(q)::text
       OR (sqlc.arg(search_lost_reason)::bool AND l.lost_reason ILIKE sqlc.narg(q)::text)
       OR (sqlc.narg(q_digits)::text IS NOT NULL AND (l.phone_normalized LIKE sqlc.narg(q_digits)::text
           OR l.parent_phone ILIKE sqlc.narg(q)::text
           OR regexp_replace(coalesce(l.parent_phone, ''), '[ .-]', '', 'g') LIKE sqlc.narg(q_digits)::text)))
  AND (sqlc.narg(branch_id)::uuid IS NULL OR l.branch_id = sqlc.narg(branch_id)::uuid)
  AND (sqlc.narg(assigned_user_id)::uuid IS NULL OR l.assigned_user_id = sqlc.narg(assigned_user_id)::uuid)
  AND (sqlc.narg(source)::text IS NULL OR l.source = sqlc.narg(source)::text)
  AND (sqlc.narg(stage)::text IS NULL OR l.stage = sqlc.narg(stage)::text)
  AND (sqlc.narg(date_from)::timestamptz IS NULL OR (CASE sqlc.arg(date_col)::text
        WHEN 'converted_at' THEN l.converted_at WHEN 'lost_at' THEN l.lost_at ELSE l.created_at END) >= sqlc.narg(date_from)::timestamptz)
  AND (sqlc.narg(date_to)::timestamptz IS NULL OR (CASE sqlc.arg(date_col)::text
        WHEN 'converted_at' THEN l.converted_at WHEN 'lost_at' THEN l.lost_at ELSE l.created_at END) <= sqlc.narg(date_to)::timestamptz)
  AND (NOT sqlc.arg(only_stale)::bool OR (l.stage = 'new' AND l.created_at <= sqlc.arg(first_contact_cutoff)::timestamptz
        AND l.last_care_at IS NULL))
  AND (NOT sqlc.arg(only_follow_up)::bool OR (l.stage IN ('new', 'consulting', 'test_scheduled', 'testing', 'tested', 'result_sent')
        AND l.next_follow_up_at IS NOT NULL AND l.next_follow_up_at <= sqlc.arg(today_end)::timestamptz
        AND (l.last_care_at IS NULL OR l.last_care_at < l.next_follow_up_at)))
  AND (NOT sqlc.arg(only_test_today)::bool OR (l.appointment_at BETWEEN sqlc.arg(today_start)::timestamptz AND sqlc.arg(today_end)::timestamptz
        AND l.stage NOT IN ('lost', 'waiting_class', 'won')))
ORDER BY
  CASE WHEN sqlc.arg(sort)::text = 'follow_up' THEN l.next_follow_up_at END ASC NULLS LAST,
  CASE WHEN sqlc.arg(sort)::text = 'test_today' THEN l.appointment_at END ASC NULLS LAST,
  CASE WHEN sqlc.arg(sort)::text = 'converted' THEN l.converted_at END DESC NULLS LAST,
  CASE WHEN sqlc.arg(sort)::text = 'lost' THEN l.lost_at END DESC NULLS LAST,
  CASE WHEN sqlc.arg(sort)::text IN ('converted', 'lost') THEN l.created_at END DESC,
  l.updated_at DESC, l.id DESC
LIMIT sqlc.arg(lim) OFFSET sqlc.arg(off);

-- name: LeadTotals :one
-- Tổng cho màn Đã chốt: số khách và tổng giá trị hợp đồng theo cùng bộ lọc (không phân trang).
SELECT count(*) AS total, coalesce(sum(l.deal_value), 0)::bigint AS deal_total
FROM crm.leads l
WHERE l.deleted_at IS NULL AND l.stage = sqlc.arg(stage)::text
  AND (sqlc.narg(scope_owner)::uuid IS NULL OR l.assigned_user_id = sqlc.narg(scope_owner)::uuid)
  AND (sqlc.narg(scope_branches)::uuid[] IS NULL OR l.branch_id = ANY (sqlc.narg(scope_branches)::uuid[]))
  AND (sqlc.narg(q)::text IS NULL OR l.name ILIKE sqlc.narg(q)::text OR l.phone ILIKE sqlc.narg(q)::text
       OR l.code ILIKE sqlc.narg(q)::text OR l.email::text ILIKE sqlc.narg(q)::text OR l.parent_name ILIKE sqlc.narg(q)::text
       OR (sqlc.narg(q_digits)::text IS NOT NULL AND l.phone_normalized LIKE sqlc.narg(q_digits)::text))
  AND (sqlc.narg(branch_id)::uuid IS NULL OR l.branch_id = sqlc.narg(branch_id)::uuid)
  AND (sqlc.narg(assigned_user_id)::uuid IS NULL OR l.assigned_user_id = sqlc.narg(assigned_user_id)::uuid)
  AND (sqlc.narg(source)::text IS NULL OR l.source = sqlc.narg(source)::text)
  AND (sqlc.narg(date_from)::timestamptz IS NULL OR l.converted_at >= sqlc.narg(date_from)::timestamptz)
  AND (sqlc.narg(date_to)::timestamptz IS NULL OR l.converted_at <= sqlc.narg(date_to)::timestamptz);

-- name: PipelineLeads :many
-- Kanban: mỗi giai đoạn tối đa col_limit thẻ mới nhất kèm tổng số / tổng giá trị của cột. Cột Đã chốt bỏ khách đã
-- xác nhận chính thức mà chốt trước won_cutoff (xem ở màn Đã chốt).
WITH base AS (
    SELECT l.id, l.code, l.name, l.phone, l.parent_name, l.source, l.stage, l.course_interest, l.deal_value,
           l.assigned_user_id, l.test_score, l.next_follow_up_at, l.appointment_at, l.converted_at, l.converted_student_id,
           l.created_at, l.updated_at, u.full_name AS assigned_user_name,
           l.last_care_at,
           EXISTS (SELECT 1 FROM crm.enrollment_confirmations c WHERE c.lead_id = l.id AND c.confirmed_at IS NOT NULL) AS confirmed
    FROM crm.leads l
    LEFT JOIN iam.users u ON u.id = l.assigned_user_id
    WHERE l.deleted_at IS NULL AND l.stage <> 'lost'
      AND (sqlc.narg(scope_owner)::uuid IS NULL OR l.assigned_user_id = sqlc.narg(scope_owner)::uuid)
      AND (sqlc.narg(scope_branches)::uuid[] IS NULL OR l.branch_id = ANY (sqlc.narg(scope_branches)::uuid[]))
      AND (sqlc.narg(q)::text IS NULL OR l.name ILIKE sqlc.narg(q)::text OR l.phone ILIKE sqlc.narg(q)::text
           OR l.code ILIKE sqlc.narg(q)::text OR l.email::text ILIKE sqlc.narg(q)::text OR l.parent_name ILIKE sqlc.narg(q)::text
           OR (sqlc.narg(q_digits)::text IS NOT NULL AND (l.phone_normalized LIKE sqlc.narg(q_digits)::text
               OR regexp_replace(coalesce(l.parent_phone, ''), '[ .-]', '', 'g') LIKE sqlc.narg(q_digits)::text)))
      AND (sqlc.narg(branch_id)::uuid IS NULL OR l.branch_id = sqlc.narg(branch_id)::uuid)
      AND (sqlc.narg(assigned_user_id)::uuid IS NULL OR l.assigned_user_id = sqlc.narg(assigned_user_id)::uuid)
      AND (sqlc.narg(source)::text IS NULL OR l.source = sqlc.narg(source)::text)
      AND (sqlc.narg(date_from)::timestamptz IS NULL OR l.created_at >= sqlc.narg(date_from)::timestamptz)
      AND (sqlc.narg(date_to)::timestamptz IS NULL OR l.created_at <= sqlc.narg(date_to)::timestamptz)
), visible AS (
    SELECT * FROM base
    WHERE NOT (base.stage = 'won' AND base.confirmed AND coalesce(base.converted_at, base.updated_at) < sqlc.arg(won_cutoff)::timestamptz)
), ranked AS (
    SELECT visible.*,
           count(*) OVER (PARTITION BY visible.stage) AS stage_count,
           sum(visible.deal_value) OVER (PARTITION BY visible.stage)::bigint AS stage_amount,
           row_number() OVER (PARTITION BY visible.stage ORDER BY visible.created_at DESC, visible.id DESC) AS rn
    FROM visible
)
SELECT ranked.id, ranked.code, ranked.name, ranked.phone, ranked.parent_name, ranked.source, ranked.stage,
       ranked.course_interest, ranked.deal_value, ranked.assigned_user_id, ranked.test_score, ranked.next_follow_up_at,
       ranked.appointment_at, ranked.converted_at, ranked.converted_student_id, ranked.created_at, ranked.updated_at,
       ranked.assigned_user_name, ranked.last_care_at, ranked.confirmed, ranked.stage_count, ranked.stage_amount
FROM ranked WHERE ranked.rn <= sqlc.arg(col_limit)::bigint
ORDER BY ranked.stage, ranked.rn;

-- name: ChipCounts :one
-- Số trên chip lọc nhanh của CRM (theo phạm vi dữ liệu).
SELECT
  count(*) FILTER (WHERE l.deleted_at IS NULL AND l.stage = 'new' AND l.created_at <= sqlc.arg(first_contact_cutoff)::timestamptz
      AND l.last_care_at IS NULL) AS sla,
  count(*) FILTER (WHERE l.deleted_at IS NULL AND l.appointment_at BETWEEN sqlc.arg(today_start)::timestamptz AND sqlc.arg(today_end)::timestamptz
      AND l.stage NOT IN ('lost', 'waiting_class', 'won')) AS test_today,
  count(*) FILTER (WHERE l.deleted_at IS NULL AND l.stage IN ('new', 'consulting', 'test_scheduled', 'testing', 'tested', 'result_sent')
      AND l.next_follow_up_at IS NOT NULL AND l.next_follow_up_at <= sqlc.arg(today_end)::timestamptz
      AND (l.last_care_at IS NULL OR l.last_care_at < l.next_follow_up_at)) AS follow_up,
  count(*) FILTER (WHERE l.deleted_at IS NULL AND l.stage = 'waiting_class') AS waiting_class,
  count(*) FILTER (WHERE l.deleted_at IS NULL AND l.stage = 'won') AS won,
  count(*) FILTER (WHERE l.deleted_at IS NULL AND l.stage = 'lost') AS lost,
  count(*) FILTER (WHERE l.deleted_at IS NOT NULL) AS deleted
FROM crm.leads l
WHERE (sqlc.narg(scope_owner)::uuid IS NULL OR l.assigned_user_id = sqlc.narg(scope_owner)::uuid)
  AND (sqlc.narg(scope_branches)::uuid[] IS NULL OR l.branch_id = ANY (sqlc.narg(scope_branches)::uuid[]));

-- name: GetLead :one
-- Khách trong phạm vi theo id hoặc mã (KH-...). Khách đã xóa không mở được.
SELECT l.*, b.name AS branch_name, u.full_name AS assigned_user_name, wb.name AS waiting_branch_name,
       ex.full_name AS examiner_name
FROM crm.leads l
LEFT JOIN core.branches b ON b.id = l.branch_id
LEFT JOIN core.branches wb ON wb.id = l.waiting_branch_id
LEFT JOIN iam.users u ON u.id = l.assigned_user_id
LEFT JOIN iam.users ex ON ex.id = l.examiner_id
WHERE l.deleted_at IS NULL
  AND (l.id = sqlc.narg(id)::uuid OR l.code = sqlc.narg(code)::text)
  AND (sqlc.narg(scope_owner)::uuid IS NULL OR l.assigned_user_id = sqlc.narg(scope_owner)::uuid)
  AND (sqlc.narg(scope_branches)::uuid[] IS NULL OR l.branch_id = ANY (sqlc.narg(scope_branches)::uuid[]));

-- name: LockLead :one
SELECT * FROM crm.leads WHERE id = $1 AND deleted_at IS NULL FOR UPDATE;

-- name: FindLeadByPhone :one
-- Khách đang hoạt động trùng SĐT (chuẩn hoá); bỏ qua chính khách đang sửa.
SELECT id, code, stage, name FROM crm.leads
WHERE phone_normalized = sqlc.arg(phone)::text AND (sqlc.narg(ignore_id)::uuid IS NULL OR id <> sqlc.narg(ignore_id)::uuid)
LIMIT 1;

-- name: EmailTaken :one
SELECT EXISTS (SELECT 1 FROM crm.leads
    WHERE email = sqlc.arg(email)::citext AND (sqlc.narg(ignore_id)::uuid IS NULL OR id <> sqlc.narg(ignore_id)::uuid));

-- name: CreateLead :one
INSERT INTO crm.leads (id, code, name, phone, phone_normalized, email, parent_name, parent_phone, dob, gender, address,
                       school, branch_id, course_interest, source, assigned_user_id, stage, deal_value, notes,
                       next_follow_up_at, created_by)
VALUES (sqlc.arg(id), sqlc.arg(code), sqlc.arg(name), sqlc.arg(phone), sqlc.arg(phone_normalized), sqlc.narg(email),
        sqlc.narg(parent_name), sqlc.narg(parent_phone), sqlc.narg(dob), sqlc.narg(gender), sqlc.narg(address),
        sqlc.narg(school), sqlc.narg(branch_id), sqlc.narg(course_interest), sqlc.narg(source), sqlc.narg(assigned_user_id),
        'new', sqlc.arg(deal_value), sqlc.narg(notes), sqlc.narg(next_follow_up_at), sqlc.narg(created_by))
RETURNING *;

-- name: UpdateLeadInfo :exec
UPDATE crm.leads SET name = sqlc.arg(name), phone = sqlc.arg(phone), phone_normalized = sqlc.arg(phone_normalized),
    email = sqlc.narg(email), parent_name = sqlc.narg(parent_name), parent_phone = sqlc.narg(parent_phone),
    dob = sqlc.narg(dob), gender = sqlc.narg(gender), address = sqlc.narg(address), school = sqlc.narg(school),
    branch_id = sqlc.narg(branch_id), course_interest = sqlc.narg(course_interest), source = sqlc.narg(source),
    deal_value = sqlc.arg(deal_value), next_follow_up_at = sqlc.narg(next_follow_up_at), notes = sqlc.narg(notes),
    updated_at = now()
WHERE id = sqlc.arg(id);

-- name: SetLeadStage :exec
-- Đổi giai đoạn: lost_mode 'set' ghi lý do + thời điểm thất bại, 'clear' xóa (mở lại), 'keep' giữ nguyên.
UPDATE crm.leads SET stage = sqlc.arg(stage),
    lost_reason = CASE sqlc.arg(lost_mode)::text WHEN 'set' THEN sqlc.narg(lost_reason)::text WHEN 'clear' THEN NULL ELSE lost_reason END,
    lost_at = CASE sqlc.arg(lost_mode)::text WHEN 'set' THEN now() WHEN 'clear' THEN NULL ELSE lost_at END,
    waiting_since = CASE WHEN sqlc.arg(clear_waiting)::bool THEN NULL ELSE waiting_since END,
    updated_at = now()
WHERE id = sqlc.arg(id);

-- name: SetLeadOwner :exec
UPDATE crm.leads SET assigned_user_id = sqlc.arg(assigned_user_id), updated_at = now() WHERE id = sqlc.arg(id);

-- name: MoveLeadBranch :exec
-- Duyệt chuyển cơ sở: người phụ trách + cơ sở (và cơ sở chờ xếp lớp nếu có).
UPDATE crm.leads SET assigned_user_id = sqlc.arg(assigned_user_id), branch_id = sqlc.arg(branch_id)::uuid,
    waiting_branch_id = CASE WHEN waiting_branch_id IS NOT NULL THEN sqlc.arg(branch_id)::uuid ELSE NULL END,
    updated_at = now()
WHERE id = sqlc.arg(id);

-- name: SetFollowUp :exec
UPDATE crm.leads SET next_follow_up_at = sqlc.narg(next_follow_up_at), updated_at = now() WHERE id = sqlc.arg(id);

-- name: SetCareChecklist :exec
UPDATE crm.leads SET care_checklist = sqlc.arg(care_checklist), updated_at = now() WHERE id = sqlc.arg(id);

-- name: SetLeadTest :exec
-- Phân hệ test đầu vào (placement) gọi qua crm.Service: lịch hẹn, đề, người chấm, điểm.
UPDATE crm.leads SET appointment_at = sqlc.narg(appointment_at), appointment_type = sqlc.narg(appointment_type),
    assigned_test_id = sqlc.narg(assigned_test_id), examiner_id = sqlc.narg(examiner_id),
    test_score = sqlc.narg(test_score), test_decision = sqlc.narg(test_decision), updated_at = now()
WHERE id = sqlc.arg(id);

-- name: MarkLeadConverted :exec
-- Chốt khách: hồ sơ học viên đã tạo (phân hệ học viên), giá trị hợp đồng, thông tin chờ xếp lớp.
UPDATE crm.leads SET deal_value = sqlc.arg(deal_value), converted_student_id = sqlc.arg(converted_student_id),
    converted_by = sqlc.arg(converted_by), commission_user_id = coalesce(assigned_user_id, sqlc.arg(converted_by)),
    converted_at = now(), fee_paid_at_closing = sqlc.arg(fee_paid_at_closing),
    waiting_course_id = CASE WHEN sqlc.arg(enrolled)::bool THEN waiting_course_id ELSE sqlc.narg(waiting_course_id)::uuid END,
    waiting_branch_id = CASE WHEN sqlc.arg(enrolled)::bool THEN waiting_branch_id ELSE sqlc.narg(waiting_branch_id)::uuid END,
    waiting_since = CASE WHEN sqlc.arg(enrolled)::bool THEN waiting_since ELSE sqlc.arg(today)::date END,
    updated_at = now()
WHERE id = sqlc.arg(id);

-- name: ListLeadsByIDs :many
SELECT l.* FROM crm.leads l
WHERE l.deleted_at IS NULL AND l.id = ANY (sqlc.arg(ids)::uuid[])
  AND (sqlc.narg(scope_owner)::uuid IS NULL OR l.assigned_user_id = sqlc.narg(scope_owner)::uuid)
  AND (sqlc.narg(scope_branches)::uuid[] IS NULL OR l.branch_id = ANY (sqlc.narg(scope_branches)::uuid[]))
ORDER BY l.created_at;

-- name: ListSources :many
SELECT DISTINCT l.source::text AS source FROM crm.leads l
WHERE l.deleted_at IS NULL AND l.source IS NOT NULL
  AND (sqlc.narg(scope_owner)::uuid IS NULL OR l.assigned_user_id = sqlc.narg(scope_owner)::uuid)
  AND (sqlc.narg(scope_branches)::uuid[] IS NULL OR l.branch_id = ANY (sqlc.narg(scope_branches)::uuid[]))
ORDER BY 1;

-- name: ListCurrentOwners :many
-- Người đang phụ trách khách trong phạm vi (gồm cả người không còn nhận phụ trách) cho bộ lọc.
SELECT DISTINCT u.id, u.full_name FROM crm.leads l JOIN iam.users u ON u.id = l.assigned_user_id
WHERE l.deleted_at IS NULL
  AND (sqlc.narg(scope_owner)::uuid IS NULL OR l.assigned_user_id = sqlc.narg(scope_owner)::uuid)
  AND (sqlc.narg(scope_branches)::uuid[] IS NULL OR l.branch_id = ANY (sqlc.narg(scope_branches)::uuid[]))
ORDER BY u.full_name;

-- ───────────────────────── Khách đã xóa ─────────────────────────

-- name: ListDeletedLeads :many
SELECT l.id, l.code, l.name, l.phone, l.stage, l.deleted_at, b.name AS branch_name, u.full_name AS assigned_user_name,
       count(*) OVER () AS total
FROM crm.leads l
LEFT JOIN core.branches b ON b.id = l.branch_id
LEFT JOIN iam.users u ON u.id = l.assigned_user_id
WHERE l.deleted_at IS NOT NULL
  AND (sqlc.narg(scope_owner)::uuid IS NULL OR l.assigned_user_id = sqlc.narg(scope_owner)::uuid)
  AND (sqlc.narg(scope_branches)::uuid[] IS NULL OR l.branch_id = ANY (sqlc.narg(scope_branches)::uuid[]))
  AND (sqlc.narg(q)::text IS NULL OR l.name ILIKE sqlc.narg(q)::text OR l.code ILIKE sqlc.narg(q)::text
       OR l.phone ILIKE sqlc.narg(q)::text)
ORDER BY l.deleted_at DESC, l.id DESC
LIMIT sqlc.arg(lim) OFFSET sqlc.arg(off);

-- name: GetDeletedLead :one
SELECT * FROM crm.leads l
WHERE l.id = sqlc.arg(id) AND l.deleted_at IS NOT NULL
  AND (sqlc.narg(scope_owner)::uuid IS NULL OR l.assigned_user_id = sqlc.narg(scope_owner)::uuid)
  AND (sqlc.narg(scope_branches)::uuid[] IS NULL OR l.branch_id = ANY (sqlc.narg(scope_branches)::uuid[]))
FOR UPDATE;

-- name: SoftDeleteLead :exec
-- Xóa mềm nhả SĐT / email khỏi ràng buộc UNIQUE (Laravel: CrmCustomer::deleting). Không có nút xóa trên giao diện
-- (Laravel đã bỏ chức năng xóa); dùng cho dữ liệu cũ / nhập dữ liệu.
UPDATE crm.leads SET deleted_at = now(), phone_normalized = NULL, deleted_email = email, email = NULL, updated_at = now()
WHERE id = $1 AND deleted_at IS NULL;

-- name: RestoreLead :exec
UPDATE crm.leads SET deleted_at = NULL, phone_normalized = sqlc.arg(phone_normalized), email = sqlc.narg(email),
    deleted_email = NULL, updated_at = now()
WHERE id = sqlc.arg(id);

-- ───────────────────────── Nhật ký ─────────────────────────

-- name: InsertEvent :one
-- Ghi nhật ký và đánh dấu khách vừa có hoạt động (Laravel $touches); loại chăm sóc (trừ liên hệ thất bại) cập nhật
-- luôn lần chăm sóc gần nhất của khách.
WITH ev AS (
    INSERT INTO crm.lead_events (lead_id, user_id, type, outcome, content, from_stage, to_stage, reason, changes)
    VALUES (sqlc.arg(lead_id), sqlc.narg(user_id), sqlc.arg(type), sqlc.narg(outcome), sqlc.arg(content),
            sqlc.narg(from_stage), sqlc.narg(to_stage), sqlc.narg(reason), sqlc.narg(changes))
    RETURNING *
), touched AS (
    UPDATE crm.leads SET updated_at = now(),
        last_care_at = CASE WHEN ev.type IN ('call', 'message', 'meet', 'test', 'result', 'trial', 'stage_change')
                             AND ev.outcome IS DISTINCT FROM 'failed' THEN ev.created_at ELSE crm.leads.last_care_at END
    FROM ev
    WHERE crm.leads.id = ev.lead_id
)
SELECT ev.id, ev.lead_id, ev.user_id, ev.type, ev.outcome, ev.content, ev.from_stage, ev.to_stage, ev.reason, ev.changes,
       ev.created_at
FROM ev;

-- name: ListEvents :many
SELECT e.*, u.full_name AS user_name FROM crm.lead_events e
LEFT JOIN iam.users u ON u.id = e.user_id
WHERE e.lead_id = $1
ORDER BY e.created_at DESC, e.id DESC;

-- name: ContactStreak :many
-- Các lần liên hệ (gọi / nhắn / gặp) mới nhất trước, để đếm chuỗi liên hệ thất bại liên tiếp.
SELECT outcome FROM crm.lead_events
WHERE lead_id = $1 AND type IN ('call', 'message', 'meet')
ORDER BY created_at DESC, id DESC
LIMIT 50;

-- ───────────────────────── Người dùng, chi nhánh (đọc) ─────────────────────────

-- name: ListOwnerCandidateIDs :many
-- Người có thể được nhận phụ trách khách (quyền đối tượng lead.be_assigned qua vai trò hoặc phân quyền cá nhân);
-- service tính lại bằng iam.Access để áp đúng thứ tự cho phép / chặn.
SELECT u.id FROM iam.users u
WHERE u.status = 'active' AND u.kind = 'staff' AND (
    EXISTS (SELECT 1 FROM iam.user_roles ur JOIN iam.role_permissions rp ON rp.role = ur.role
            WHERE ur.user_id = u.id AND rp.permission = 'lead.be_assigned')
    OR EXISTS (SELECT 1 FROM iam.permission_overrides po
               WHERE po.user_id = u.id AND po.permission = 'lead.be_assigned' AND po.allow))
ORDER BY u.full_name;

-- name: ListUsersBrief :many
SELECT u.id, u.full_name, coalesce(u.email::text, '')::text AS email, u.status, u.kind,
       pb.branch_id AS primary_branch_id, b.name AS primary_branch_name
FROM iam.users u
LEFT JOIN iam.user_branches pb ON pb.user_id = u.id AND pb.is_primary
LEFT JOIN core.branches b ON b.id = pb.branch_id
WHERE u.id = ANY (sqlc.arg(ids)::uuid[])
ORDER BY u.full_name;

-- name: GetUserBrief :one
SELECT u.id, u.full_name, u.status, u.kind FROM iam.users u WHERE u.id = $1;

-- name: ListBranchesBrief :many
-- Chi nhánh để chọn: ids NULL = mọi chi nhánh; only_active = chỉ chi nhánh đang hoạt động.
SELECT id, code::text AS code, name, is_active FROM core.branches
WHERE (sqlc.narg(ids)::uuid[] IS NULL OR id = ANY (sqlc.narg(ids)::uuid[]))
  AND (NOT sqlc.arg(only_active)::bool OR is_active)
ORDER BY name;

-- name: GetBranchBrief :one
SELECT id, code::text AS code, name, is_active FROM core.branches WHERE id = $1;

-- name: ListRepUsers :many
-- Người có dòng trên bảng hiệu suất: vai trò Tư vấn tuyển sinh hoặc có khách / khách chốt trong kỳ.
SELECT u.id, u.full_name,
       ARRAY(SELECT ur.role FROM iam.user_roles ur WHERE ur.user_id = u.id ORDER BY ur.role)::text[] AS roles,
       ARRAY(SELECT ub.branch_id FROM iam.user_branches ub WHERE ub.user_id = u.id)::uuid[] AS branch_ids
FROM iam.users u
WHERE u.kind = 'staff' AND (u.id = ANY (sqlc.arg(ids)::uuid[])
    OR EXISTS (SELECT 1 FROM iam.user_roles ur WHERE ur.user_id = u.id AND ur.role = 'sales_consultant'))
ORDER BY u.full_name;

-- ───────────────────────── Chuyển cơ sở ─────────────────────────

-- name: PendingTransferOfLead :one
SELECT t.*, tu.full_name AS to_user_name, fb.name AS from_branch_name, tb.name AS to_branch_name,
       rq.full_name AS requester_name
FROM crm.branch_transfers t
LEFT JOIN iam.users tu ON tu.id = t.to_user_id
LEFT JOIN core.branches fb ON fb.id = t.from_branch_id
LEFT JOIN core.branches tb ON tb.id = t.to_branch_id
LEFT JOIN iam.users rq ON rq.id = t.requested_by
WHERE t.lead_id = $1 AND t.status = 'pending';

-- name: CreateTransfer :one
INSERT INTO crm.branch_transfers (lead_id, from_branch_id, to_branch_id, from_user_id, to_user_id, requested_by, reason)
VALUES (sqlc.arg(lead_id), sqlc.narg(from_branch_id), sqlc.arg(to_branch_id), sqlc.narg(from_user_id),
        sqlc.arg(to_user_id), sqlc.arg(requested_by), sqlc.narg(reason))
RETURNING *;

-- name: LockTransfer :one
SELECT * FROM crm.branch_transfers WHERE id = $1 FOR UPDATE;

-- name: DecideTransfer :exec
UPDATE crm.branch_transfers SET status = sqlc.arg(status), decided_by = sqlc.arg(decided_by), decided_at = now(),
    decision_note = sqlc.narg(decision_note), updated_at = now()
WHERE id = sqlc.arg(id);

-- name: ListTransfers :many
-- Hộp duyệt chuyển cơ sở (Laravel: "Chuyển cơ sở (CRM)" trong Việc cần duyệt).
SELECT t.*, l.name AS lead_name, l.code AS lead_code, l.converted_student_id,
       fu.full_name AS from_user_name, tu.full_name AS to_user_name, fb.name AS from_branch_name,
       tb.name AS to_branch_name, rq.full_name AS requester_name, dc.full_name AS decider_name,
       count(*) OVER () AS total
FROM crm.branch_transfers t
JOIN crm.leads l ON l.id = t.lead_id AND l.deleted_at IS NULL
LEFT JOIN iam.users fu ON fu.id = t.from_user_id
LEFT JOIN iam.users tu ON tu.id = t.to_user_id
LEFT JOIN core.branches fb ON fb.id = t.from_branch_id
LEFT JOIN core.branches tb ON tb.id = t.to_branch_id
LEFT JOIN iam.users rq ON rq.id = t.requested_by
LEFT JOIN iam.users dc ON dc.id = t.decided_by
WHERE (sqlc.narg(status)::text IS NULL OR t.status = sqlc.narg(status)::text)
ORDER BY t.created_at DESC
LIMIT sqlc.arg(lim) OFFSET sqlc.arg(off);

-- ───────────────────────── Ưu đãi ─────────────────────────

-- name: ListPromotions :many
SELECT p.*, b.name AS branch_name, u.full_name AS creator_name, c.name AS course_name, count(*) OVER () AS total
FROM crm.promotions p
LEFT JOIN core.branches b ON b.id = p.branch_id
LEFT JOIN iam.users u ON u.id = p.created_by
LEFT JOIN academic.courses c ON c.id = p.course_id AND c.deleted_at IS NULL
WHERE p.is_special = sqlc.arg(special)::bool
  AND (sqlc.narg(q)::text IS NULL OR p.name ILIKE sqlc.narg(q)::text OR p.code ILIKE sqlc.narg(q)::text
       OR p.reason ILIKE sqlc.narg(q)::text)
  AND (sqlc.arg(status)::text = ''
       OR (sqlc.arg(status)::text = 'active' AND p.is_active AND (p.starts_at IS NULL OR p.starts_at <= now())
           AND (p.ends_at IS NULL OR p.ends_at >= now()) AND (p.usage_limit IS NULL OR p.used_count < p.usage_limit))
       OR (sqlc.arg(status)::text = 'inactive' AND NOT p.is_active)
       OR (sqlc.arg(status)::text = 'default' AND p.is_default))
ORDER BY p.is_active DESC, p.is_default DESC, p.created_at DESC, p.id DESC
LIMIT sqlc.arg(lim) OFFSET sqlc.arg(off);

-- name: CountPromotions :one
SELECT count(*) FILTER (WHERE NOT is_special) AS catalog, count(*) FILTER (WHERE is_special) AS special
FROM crm.promotions;

-- name: GetPromotion :one
SELECT * FROM crm.promotions WHERE id = $1;

-- name: LockPromotion :one
SELECT * FROM crm.promotions WHERE id = $1 FOR UPDATE;

-- name: PromotionCodeTaken :one
SELECT EXISTS (SELECT 1 FROM crm.promotions WHERE code = $1);

-- name: CreatePromotion :one
INSERT INTO crm.promotions (code, name, type, value, max_discount_amount, description, reason, branch_id, course_id,
                            starts_at, ends_at, usage_limit, is_active, is_default, is_special, created_by)
VALUES (sqlc.arg(code), sqlc.arg(name), sqlc.arg(type), sqlc.arg(value), sqlc.narg(max_discount_amount),
        sqlc.narg(description), sqlc.narg(reason), sqlc.narg(branch_id), sqlc.narg(course_id), sqlc.narg(starts_at),
        sqlc.narg(ends_at), sqlc.narg(usage_limit), sqlc.arg(is_active), sqlc.arg(is_default), sqlc.arg(is_special),
        sqlc.narg(created_by))
RETURNING *;

-- name: UpdatePromotion :one
UPDATE crm.promotions SET name = sqlc.arg(name), type = sqlc.arg(type), value = sqlc.arg(value),
    max_discount_amount = sqlc.narg(max_discount_amount), description = sqlc.narg(description), reason = sqlc.narg(reason),
    branch_id = sqlc.narg(branch_id), course_id = sqlc.narg(course_id), starts_at = sqlc.narg(starts_at),
    ends_at = sqlc.narg(ends_at), usage_limit = sqlc.narg(usage_limit), is_active = sqlc.arg(is_active),
    is_default = sqlc.arg(is_default), updated_at = now()
WHERE id = sqlc.arg(id)
RETURNING *;

-- name: TogglePromotion :one
UPDATE crm.promotions SET is_active = NOT is_active, updated_at = now() WHERE id = $1 RETURNING *;

-- name: UsePromotion :exec
UPDATE crm.promotions SET used_count = used_count + 1, updated_at = now() WHERE id = $1;

-- name: AvailablePromotions :many
-- Ưu đãi trong danh mục đang hiệu lực (chọn khi chốt khách).
SELECT * FROM crm.promotions
WHERE NOT is_special AND is_active AND (starts_at IS NULL OR starts_at <= now()) AND (ends_at IS NULL OR ends_at >= now())
  AND (usage_limit IS NULL OR used_count < usage_limit)
ORDER BY is_default DESC, name;

-- ───────────────────────── Xác nhận chính thức ─────────────────────────

-- name: ListConfirmations :many
SELECT c.*, l.phone AS lead_phone, l.converted_at AS lead_converted_at, b.name AS branch_name,
       cb.full_name AS confirmed_by_name, count(*) OVER () AS total
FROM crm.enrollment_confirmations c
JOIN crm.leads l ON l.id = c.lead_id AND l.deleted_at IS NULL
LEFT JOIN core.branches b ON b.id = c.class_branch_id
LEFT JOIN iam.users cb ON cb.id = c.confirmed_by
WHERE c.status IN ('pending', 'completed')
  AND (sqlc.narg(scope_owner)::uuid IS NULL OR l.assigned_user_id = sqlc.narg(scope_owner)::uuid)
  AND (sqlc.narg(scope_branches)::uuid[] IS NULL OR l.branch_id = ANY (sqlc.narg(scope_branches)::uuid[]))
  AND (CASE WHEN sqlc.arg(confirmed)::bool THEN c.confirmed_at IS NOT NULL ELSE c.confirmed_at IS NULL END)
  AND (sqlc.narg(q)::text IS NULL OR c.student_name ILIKE sqlc.narg(q)::text OR c.student_code ILIKE sqlc.narg(q)::text
       OR l.phone ILIKE sqlc.narg(q)::text)
  AND (sqlc.narg(branch_id)::uuid IS NULL OR c.class_branch_id = sqlc.narg(branch_id)::uuid)
  AND (sqlc.narg(class_id)::uuid IS NULL OR c.class_id = sqlc.narg(class_id)::uuid)
ORDER BY c.enrolled_at DESC, c.id DESC
LIMIT sqlc.arg(lim) OFFSET sqlc.arg(off);

-- name: ConfirmationCounts :one
SELECT count(*) FILTER (WHERE c.confirmed_at IS NULL) AS pending, count(*) AS total
FROM crm.enrollment_confirmations c
JOIN crm.leads l ON l.id = c.lead_id AND l.deleted_at IS NULL
WHERE c.status IN ('pending', 'completed')
  AND (sqlc.narg(scope_owner)::uuid IS NULL OR l.assigned_user_id = sqlc.narg(scope_owner)::uuid)
  AND (sqlc.narg(scope_branches)::uuid[] IS NULL OR l.branch_id = ANY (sqlc.narg(scope_branches)::uuid[]));

-- name: ConfirmationFilterOptions :many
-- Lớp / chi nhánh có trong danh sách xác nhận mà người xem được thấy.
SELECT DISTINCT c.class_id, c.class_name, c.class_branch_id, b.name AS branch_name
FROM crm.enrollment_confirmations c
JOIN crm.leads l ON l.id = c.lead_id AND l.deleted_at IS NULL
LEFT JOIN core.branches b ON b.id = c.class_branch_id
WHERE c.status IN ('pending', 'completed')
  AND (sqlc.narg(scope_owner)::uuid IS NULL OR l.assigned_user_id = sqlc.narg(scope_owner)::uuid)
  AND (sqlc.narg(scope_branches)::uuid[] IS NULL OR l.branch_id = ANY (sqlc.narg(scope_branches)::uuid[]))
ORDER BY c.class_name;

-- name: LockConfirmation :one
SELECT c.* FROM crm.enrollment_confirmations c
JOIN crm.leads l ON l.id = c.lead_id AND l.deleted_at IS NULL
WHERE c.id = sqlc.arg(id)
  AND (sqlc.narg(scope_owner)::uuid IS NULL OR l.assigned_user_id = sqlc.narg(scope_owner)::uuid)
  AND (sqlc.narg(scope_branches)::uuid[] IS NULL OR l.branch_id = ANY (sqlc.narg(scope_branches)::uuid[]))
FOR UPDATE OF c;

-- name: SaveConfirmation :exec
UPDATE crm.enrollment_confirmations SET account_sent = sqlc.arg(account_sent), zalo_group_added = sqlc.arg(zalo_group_added),
    curriculum_delivered = sqlc.arg(curriculum_delivered),
    status = CASE WHEN sqlc.arg(confirm)::bool THEN 'completed' ELSE status END,
    confirmed_at = CASE WHEN sqlc.arg(confirm)::bool THEN now() ELSE confirmed_at END,
    confirmed_by = CASE WHEN sqlc.arg(confirm)::bool THEN sqlc.narg(confirmed_by)::uuid ELSE confirmed_by END,
    updated_at = now()
WHERE id = sqlc.arg(id);

-- name: CreateConfirmation :one
INSERT INTO crm.enrollment_confirmations (lead_id, enrollment_id, student_id, class_id, student_code, student_name,
                                          class_name, class_code, class_branch_id, class_start_date)
VALUES (sqlc.arg(lead_id), sqlc.narg(enrollment_id), sqlc.arg(student_id), sqlc.arg(class_id), sqlc.arg(student_code),
        sqlc.arg(student_name), sqlc.arg(class_name), sqlc.narg(class_code), sqlc.narg(class_branch_id),
        sqlc.narg(class_start_date))
RETURNING *;

-- ───────────────────────── Báo cáo ─────────────────────────

-- name: ReportCohort :many
-- Khách tạo trong kỳ theo giai đoạn và người phụ trách (phạm vi người xem).
SELECT l.stage, l.assigned_user_id, count(*) AS n
FROM crm.leads l
WHERE l.deleted_at IS NULL AND l.created_at BETWEEN sqlc.arg(start_at)::timestamptz AND sqlc.arg(end_at)::timestamptz
  AND (sqlc.narg(branch_id)::uuid IS NULL OR l.branch_id = sqlc.narg(branch_id)::uuid)
  AND (sqlc.narg(scope_owner)::uuid IS NULL OR l.assigned_user_id = sqlc.narg(scope_owner)::uuid)
  AND (sqlc.narg(scope_branches)::uuid[] IS NULL OR l.branch_id = ANY (sqlc.narg(scope_branches)::uuid[]))
GROUP BY l.stage, l.assigned_user_id;

-- name: ReportClosedCount :one
-- Khách chốt (Chờ xếp lớp / Đã chốt) theo ngày chốt; dữ liệu cũ không có ngày chốt thì theo ngày tạo.
SELECT count(*) FROM crm.leads l
WHERE l.deleted_at IS NULL AND l.stage IN ('waiting_class', 'won')
  AND (l.converted_at BETWEEN sqlc.arg(start_at)::timestamptz AND sqlc.arg(end_at)::timestamptz
       OR (l.converted_at IS NULL AND l.created_at BETWEEN sqlc.arg(start_at)::timestamptz AND sqlc.arg(end_at)::timestamptz))
  AND (sqlc.narg(branch_id)::uuid IS NULL OR l.branch_id = sqlc.narg(branch_id)::uuid)
  AND (sqlc.narg(scope_owner)::uuid IS NULL OR l.assigned_user_id = sqlc.narg(scope_owner)::uuid)
  AND (sqlc.narg(scope_branches)::uuid[] IS NULL OR l.branch_id = ANY (sqlc.narg(scope_branches)::uuid[]));

-- name: ReportLost :many
-- Khách thất bại theo ngày thất bại (dữ liệu cũ: ngày tạo), mới nhất trước.
SELECT l.id, l.name, l.lost_reason, coalesce(l.lost_at, l.created_at)::timestamptz AS at, u.full_name AS user_name
FROM crm.leads l
LEFT JOIN iam.users u ON u.id = l.assigned_user_id
WHERE l.deleted_at IS NULL AND l.stage = 'lost'
  AND (l.lost_at BETWEEN sqlc.arg(start_at)::timestamptz AND sqlc.arg(end_at)::timestamptz
       OR (l.lost_at IS NULL AND l.created_at BETWEEN sqlc.arg(start_at)::timestamptz AND sqlc.arg(end_at)::timestamptz))
  AND (sqlc.narg(branch_id)::uuid IS NULL OR l.branch_id = sqlc.narg(branch_id)::uuid)
  AND (sqlc.narg(scope_owner)::uuid IS NULL OR l.assigned_user_id = sqlc.narg(scope_owner)::uuid)
  AND (sqlc.narg(scope_branches)::uuid[] IS NULL OR l.branch_id = ANY (sqlc.narg(scope_branches)::uuid[]))
ORDER BY coalesce(l.lost_at, l.created_at) DESC;

-- name: ReportCredited :many
-- Khách chốt trong kỳ ghi cho người nhận hoa hồng (kể cả khi đã phân công lại); chỉ giới hạn chi nhánh.
SELECT coalesce(l.commission_user_id, l.assigned_user_id)::uuid AS user_id, count(*) AS n
FROM crm.leads l
WHERE l.deleted_at IS NULL AND l.stage IN ('waiting_class', 'won')
  AND coalesce(l.commission_user_id, l.assigned_user_id) IS NOT NULL
  AND (l.converted_at BETWEEN sqlc.arg(start_at)::timestamptz AND sqlc.arg(end_at)::timestamptz
       OR (l.converted_at IS NULL AND l.created_at BETWEEN sqlc.arg(start_at)::timestamptz AND sqlc.arg(end_at)::timestamptz))
  AND (sqlc.narg(branch_id)::uuid IS NULL OR l.branch_id = sqlc.narg(branch_id)::uuid)
  AND (sqlc.narg(scope_branches)::uuid[] IS NULL OR l.branch_id = ANY (sqlc.narg(scope_branches)::uuid[]))
GROUP BY 1;

-- ───────────────────────── Nhập Excel ─────────────────────────

-- name: CreateImportBatch :one
INSERT INTO crm.import_batches (created_by, branch_id, assigned_user_id, file_name, rows)
VALUES ($1, $2, $3, $4, $5)
RETURNING *;

-- name: GetImportBatch :one
SELECT ib.*, b.name AS branch_name, u.full_name AS assigned_user_name
FROM crm.import_batches ib
JOIN core.branches b ON b.id = ib.branch_id
JOIN iam.users u ON u.id = ib.assigned_user_id
WHERE ib.id = sqlc.arg(id) AND ib.created_by = sqlc.arg(created_by);

-- name: LockImportBatch :one
SELECT * FROM crm.import_batches WHERE id = sqlc.arg(id) AND created_by = sqlc.arg(created_by) FOR UPDATE;

-- name: FinishImportBatch :exec
UPDATE crm.import_batches SET status = sqlc.arg(status), created_count = sqlc.arg(created_count),
    skipped = sqlc.narg(skipped), committed_at = now()
WHERE id = sqlc.arg(id);

-- name: WaitingLeads :many
-- Danh sách Chờ xếp lớp (cũ nhất trước).
SELECT l.id, l.code, l.name, l.phone, l.converted_at, l.converted_student_id, l.waiting_course_id, l.course_interest,
       l.fee_paid_at_closing, coalesce(wb.name, b.name, '')::text AS branch_name, u.full_name AS assigned_user_name
FROM crm.leads l
LEFT JOIN core.branches b ON b.id = l.branch_id
LEFT JOIN core.branches wb ON wb.id = l.waiting_branch_id
LEFT JOIN iam.users u ON u.id = l.assigned_user_id
WHERE l.deleted_at IS NULL AND l.stage = 'waiting_class'
  AND (sqlc.narg(scope_owner)::uuid IS NULL OR l.assigned_user_id = sqlc.narg(scope_owner)::uuid)
  AND (sqlc.narg(scope_branches)::uuid[] IS NULL OR l.branch_id = ANY (sqlc.narg(scope_branches)::uuid[]))
ORDER BY l.converted_at NULLS LAST, l.id;

-- ───────────────────────── Cổng cho phân hệ test đầu vào (placement) ─────────────────────────

-- name: ListLeadIDsInScope :many
-- Id khách người dùng được xem (phạm vi lead): placement lọc bài test / học thử theo khách.
SELECT l.id FROM crm.leads l
WHERE l.deleted_at IS NULL
  AND (sqlc.narg(scope_owner)::uuid IS NULL OR l.assigned_user_id = sqlc.narg(scope_owner)::uuid)
  AND (sqlc.narg(scope_branches)::uuid[] IS NULL OR l.branch_id = ANY (sqlc.narg(scope_branches)::uuid[]));

-- name: PlacementLeads :many
-- Khách (kèm tên người phụ trách) theo danh sách id, không lọc phạm vi.
SELECT l.id, l.code, l.name, l.parent_name, l.phone, l.email, l.stage, l.branch_id, l.assigned_user_id, l.test_score,
       coalesce(u.full_name, '')::text AS assigned_user_name
FROM crm.leads l
LEFT JOIN iam.users u ON u.id = l.assigned_user_id
WHERE l.deleted_at IS NULL AND l.id = ANY (sqlc.arg(ids)::uuid[]);

-- name: MatchLeadByPhone :one
-- Khách chưa chốt (Laravel PHONE_MATCH_STAGES) có SĐT chuẩn hoá khớp.
SELECT l.id FROM crm.leads l
WHERE l.deleted_at IS NULL AND l.phone_normalized = sqlc.arg(phone)::text
  AND l.stage IN ('new', 'consulting', 'test_scheduled', 'testing', 'tested', 'result_sent')
LIMIT 1;

-- name: MatchLeadsByParentPhone :many
-- Khách chưa chốt có SĐT phụ huynh (bỏ khoảng trắng / chấm / gạch) kết thúc bằng 9 số cuối; Go so khớp chuẩn hoá.
SELECT l.id, coalesce(l.parent_phone, '')::text AS parent_phone FROM crm.leads l
WHERE l.deleted_at IS NULL
  AND l.stage IN ('new', 'consulting', 'test_scheduled', 'testing', 'tested', 'result_sent')
  AND regexp_replace(coalesce(l.parent_phone, ''), '[ .-]', '', 'g') LIKE '%' || sqlc.arg(tail)::text
LIMIT 5;

-- name: CancelPendingTrials :execrows
-- Hủy buổi học thử đang hẹn của khách (Laravel CrmCustomer::cancelPendingTrialBookings), ghi lý do vào ghi chú.
UPDATE crm.trial_bookings
SET status = 'cancelled',
    notes = left(trim(coalesce(notes || E'\n', '') || 'Hủy: ' || sqlc.arg(reason)::text), 3000),
    updated_at = now(), version = version + 1
WHERE lead_id = sqlc.arg(lead_id) AND status = 'scheduled';

-- ───────────────────────── Số liệu cho báo cáo ngày / tuần (phân hệ reports) ─────────────────────────
-- Phạm vi như danh sách khách (scope_owner / scope_branches); only_branches: chi nhánh của người viết (NULL = không lọc).

-- name: ReportNewLeads :many
-- Khách tạo trong khoảng; contacted: đã có lần gọi / nhắn / gặp liên hệ được trước hết khoảng.
SELECT l.id, l.name, coalesce(l.source, '')::text AS source, l.branch_id, l.created_at,
       EXISTS (SELECT 1 FROM crm.lead_events e WHERE e.lead_id = l.id AND e.type IN ('call', 'message', 'meet')
               AND e.outcome IS DISTINCT FROM 'failed' AND e.created_at < sqlc.arg(until)::timestamptz) AS contacted
FROM crm.leads l
WHERE l.deleted_at IS NULL
  AND l.created_at >= sqlc.arg(since)::timestamptz AND l.created_at < sqlc.arg(until)::timestamptz
  AND (sqlc.narg(scope_owner)::uuid IS NULL OR l.assigned_user_id = sqlc.narg(scope_owner)::uuid)
  AND (sqlc.narg(scope_branches)::uuid[] IS NULL OR l.branch_id = ANY (sqlc.narg(scope_branches)::uuid[]))
  AND (sqlc.narg(only_branches)::uuid[] IS NULL OR l.branch_id = ANY (sqlc.narg(only_branches)::uuid[]))
ORDER BY l.created_at, l.id;

-- name: ReportAppointments :one
-- Số khách có lịch hẹn test trong khoảng.
SELECT count(*) FROM crm.leads l
WHERE l.deleted_at IS NULL
  AND l.appointment_at >= sqlc.arg(since)::timestamptz AND l.appointment_at < sqlc.arg(until)::timestamptz
  AND (sqlc.narg(scope_owner)::uuid IS NULL OR l.assigned_user_id = sqlc.narg(scope_owner)::uuid)
  AND (sqlc.narg(scope_branches)::uuid[] IS NULL OR l.branch_id = ANY (sqlc.narg(scope_branches)::uuid[]));

-- name: ReportClosedLeads :many
-- Khách chuyển sang Chờ xếp lớp / Đã chốt trong khoảng (lần đầu trong khoảng của mỗi khách).
SELECT DISTINCT ON (l.id) l.id, l.name, e.created_at AS closed_at
FROM crm.lead_events e
JOIN crm.leads l ON l.id = e.lead_id
WHERE e.type = 'stage_change' AND e.to_stage IN ('waiting_class', 'won')
  AND e.created_at >= sqlc.arg(since)::timestamptz AND e.created_at < sqlc.arg(until)::timestamptz
  AND l.deleted_at IS NULL
  AND (sqlc.narg(scope_owner)::uuid IS NULL OR l.assigned_user_id = sqlc.narg(scope_owner)::uuid)
  AND (sqlc.narg(scope_branches)::uuid[] IS NULL OR l.branch_id = ANY (sqlc.narg(scope_branches)::uuid[]))
  AND (sqlc.narg(only_branches)::uuid[] IS NULL OR l.branch_id = ANY (sqlc.narg(only_branches)::uuid[]))
ORDER BY l.id, e.created_at;

-- name: ReportTests :many
-- Bài test đầu vào (placement.submissions) làm trong khoảng của khách trong phạm vi.
SELECT s.lead_id, l.name, coalesce(s.overall_score::text, '')::text AS overall_score,
       coalesce(s.cefr_level, '')::text AS cefr_level, s.status
FROM placement.submissions s
JOIN crm.leads l ON l.id = s.lead_id
WHERE s.created_at >= sqlc.arg(since)::timestamptz AND s.created_at < sqlc.arg(until)::timestamptz
  AND l.deleted_at IS NULL
  AND (sqlc.narg(scope_owner)::uuid IS NULL OR l.assigned_user_id = sqlc.narg(scope_owner)::uuid)
  AND (sqlc.narg(scope_branches)::uuid[] IS NULL OR l.branch_id = ANY (sqlc.narg(scope_branches)::uuid[]))
ORDER BY s.created_at, s.id;

-- name: SearchLeads :many
-- Tìm kiếm chung (topbar, Laravel GlobalSearchController): tên / mã / email, SĐT khách / phụ huynh khi từ khóa có số.
SELECT l.id, l.code, l.name, l.phone, l.stage, b.name AS branch_name
FROM crm.leads l
LEFT JOIN core.branches b ON b.id = l.branch_id
WHERE l.deleted_at IS NULL
  AND (sqlc.narg(scope_owner)::uuid IS NULL OR l.assigned_user_id = sqlc.narg(scope_owner)::uuid)
  AND (sqlc.narg(scope_branches)::uuid[] IS NULL OR l.branch_id = ANY (sqlc.narg(scope_branches)::uuid[]))
  AND (l.name ILIKE sqlc.arg(term)::text OR l.code ILIKE sqlc.arg(term)::text OR l.email::text ILIKE sqlc.arg(term)::text
       OR (sqlc.arg(phone)::text <> '' AND (l.phone ILIKE sqlc.arg(phone)::text OR l.phone_normalized LIKE sqlc.arg(phone)::text
           OR l.parent_phone ILIKE sqlc.arg(phone)::text)))
ORDER BY l.created_at DESC, l.id DESC
LIMIT sqlc.arg(lim);

-- name: ListLeadSourceNames :many
-- Nguồn khách từ danh mục chung (core.system_categories, type lead_source) như Laravel SystemCategory::where('type', 'lead_source').
SELECT name FROM core.system_categories
WHERE type = 'lead_source' AND deleted_at IS NULL
ORDER BY sort_order, name;

-- name: GetCourseName :one
-- Tên khóa học (danh mục academic.courses) chưa xóa.
SELECT name FROM academic.courses WHERE id = $1 AND deleted_at IS NULL;

-- name: WonStudentIDs :many
-- Học viên của khách Đã chốt theo cùng bộ lọc LeadTotals (không phân trang) — tổng Đã thu / Còn nợ từ phân hệ Học phí.
SELECT l.converted_student_id::uuid AS student_id
FROM crm.leads l
WHERE l.deleted_at IS NULL AND l.stage = sqlc.arg(stage)::text AND l.converted_student_id IS NOT NULL
  AND (sqlc.narg(scope_owner)::uuid IS NULL OR l.assigned_user_id = sqlc.narg(scope_owner)::uuid)
  AND (sqlc.narg(scope_branches)::uuid[] IS NULL OR l.branch_id = ANY (sqlc.narg(scope_branches)::uuid[]))
  AND (sqlc.narg(q)::text IS NULL OR l.name ILIKE sqlc.narg(q)::text OR l.phone ILIKE sqlc.narg(q)::text
       OR l.code ILIKE sqlc.narg(q)::text OR l.email::text ILIKE sqlc.narg(q)::text OR l.parent_name ILIKE sqlc.narg(q)::text
       OR (sqlc.narg(q_digits)::text IS NOT NULL AND l.phone_normalized LIKE sqlc.narg(q_digits)::text))
  AND (sqlc.narg(branch_id)::uuid IS NULL OR l.branch_id = sqlc.narg(branch_id)::uuid)
  AND (sqlc.narg(assigned_user_id)::uuid IS NULL OR l.assigned_user_id = sqlc.narg(assigned_user_id)::uuid)
  AND (sqlc.narg(source)::text IS NULL OR l.source = sqlc.narg(source)::text)
  AND (sqlc.narg(date_from)::timestamptz IS NULL OR l.converted_at >= sqlc.narg(date_from)::timestamptz)
  AND (sqlc.narg(date_to)::timestamptz IS NULL OR l.converted_at <= sqlc.narg(date_to)::timestamptz);
