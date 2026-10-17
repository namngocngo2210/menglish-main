-- Dự án học thuật (AcademicProjectController, AcademicProjectSlaService).
-- Phạm vi (AcademicProject::visibleTo): see_all = có academic_project.view_all / manage; còn lại chỉ dự án mình phụ
-- trách / là thành viên / được giao mốc (mốc chưa xóa).

-- name: CountProjects :one
SELECT count(*)::bigint
FROM syllabus.projects p
WHERE p.deleted_at IS NULL
  AND (sqlc.arg(see_all)::bool OR p.owner_id = sqlc.arg(user_id)::uuid
       OR EXISTS (SELECT 1 FROM syllabus.project_members pm WHERE pm.project_id = p.id AND pm.user_id = sqlc.arg(user_id)::uuid)
       OR EXISTS (SELECT 1 FROM syllabus.milestones m WHERE m.project_id = p.id AND m.deleted_at IS NULL AND m.assignee_id = sqlc.arg(user_id)::uuid))
  AND (sqlc.narg(search)::text IS NULL OR p.name ILIKE sqlc.narg(search) OR p.code ILIKE sqlc.narg(search))
  AND (sqlc.narg(type)::text IS NULL OR p.type = sqlc.narg(type))
  AND (sqlc.narg(status)::text IS NULL OR p.status = sqlc.narg(status))
  AND (sqlc.narg(owner_id)::uuid IS NULL OR p.owner_id = sqlc.narg(owner_id));

-- name: ListProjects :many
-- Đang thực hiện → lên kế hoạch → tạm dừng → đã đóng, rồi deadline (chưa có deadline trước như MySQL), mới tạo trước.
SELECT p.id, p.code, p.name, p.type, p.status, p.start_date, p.deadline, p.owner_id, o.full_name AS owner_name,
       (SELECT count(*) FROM syllabus.project_members pm WHERE pm.project_id = p.id)::bigint AS members_count
FROM syllabus.projects p
LEFT JOIN iam.users o ON o.id = p.owner_id
WHERE p.deleted_at IS NULL
  AND (sqlc.arg(see_all)::bool OR p.owner_id = sqlc.arg(user_id)::uuid
       OR EXISTS (SELECT 1 FROM syllabus.project_members pm WHERE pm.project_id = p.id AND pm.user_id = sqlc.arg(user_id)::uuid)
       OR EXISTS (SELECT 1 FROM syllabus.milestones m WHERE m.project_id = p.id AND m.deleted_at IS NULL AND m.assignee_id = sqlc.arg(user_id)::uuid))
  AND (sqlc.narg(search)::text IS NULL OR p.name ILIKE sqlc.narg(search) OR p.code ILIKE sqlc.narg(search))
  AND (sqlc.narg(type)::text IS NULL OR p.type = sqlc.narg(type))
  AND (sqlc.narg(status)::text IS NULL OR p.status = sqlc.narg(status))
  AND (sqlc.narg(owner_id)::uuid IS NULL OR p.owner_id = sqlc.narg(owner_id))
ORDER BY CASE p.status WHEN 'active' THEN 0 WHEN 'planning' THEN 1 WHEN 'paused' THEN 2 ELSE 3 END,
         p.deadline NULLS FIRST, p.id DESC
LIMIT sqlc.arg(lim) OFFSET sqlc.arg(off);

-- name: ProjectVisible :one
SELECT EXISTS (
    SELECT 1 FROM syllabus.projects p
    WHERE p.id = sqlc.arg(id) AND p.deleted_at IS NULL
      AND (sqlc.arg(see_all)::bool OR p.owner_id = sqlc.arg(user_id)::uuid
           OR EXISTS (SELECT 1 FROM syllabus.project_members pm WHERE pm.project_id = p.id AND pm.user_id = sqlc.arg(user_id)::uuid)
           OR EXISTS (SELECT 1 FROM syllabus.milestones m WHERE m.project_id = p.id AND m.deleted_at IS NULL AND m.assignee_id = sqlc.arg(user_id)::uuid))
)::bool;

-- name: GetProject :one
SELECT p.*, o.full_name AS owner_name, cr.full_name AS creator_name, lk.full_name AS locker_name
FROM syllabus.projects p
LEFT JOIN iam.users o ON o.id = p.owner_id
LEFT JOIN iam.users cr ON cr.id = p.created_by
LEFT JOIN iam.users lk ON lk.id = p.plan_locked_by
WHERE p.id = $1 AND p.deleted_at IS NULL;

-- name: ProjectMembers :many
SELECT u.id, u.full_name
FROM syllabus.project_members pm
JOIN iam.users u ON u.id = pm.user_id
WHERE pm.project_id = $1
ORDER BY u.full_name, u.id;

-- name: MilestonesOf :many
-- Mốc (chưa xóa) của các dự án: hạn sớm trước, rồi thứ tự thêm.
SELECT m.*, a.full_name AS assignee_name
FROM syllabus.milestones m
LEFT JOIN iam.users a ON a.id = m.assignee_id
WHERE m.project_id = ANY (sqlc.arg(project_ids)::uuid[]) AND m.deleted_at IS NULL
ORDER BY m.due_date, m.sort_order, m.id;

-- name: LockProjectCodes :exec
SELECT pg_advisory_xact_lock(hashtext('syllabus.projects.code'));

-- name: MaxProjectNumber :one
-- Số lớn nhất của mã DA-<năm>-NNN đã có (kể cả dự án đã xóa).
SELECT coalesce(max(substring(code FROM '^DA-\d{4}-(\d+)$')::int), 0)::int
FROM syllabus.projects WHERE code LIKE 'DA-' || sqlc.arg(year)::text || '-%';

-- name: CreateProject :one
INSERT INTO syllabus.projects (
    code, name, type, description, owner_id, start_date, deadline, status, kickoff_notes, kickoff_link, created_by
) VALUES (
    sqlc.arg(code), sqlc.arg(name), sqlc.arg(type), sqlc.narg(description), sqlc.arg(owner_id), sqlc.narg(start_date),
    sqlc.narg(deadline), 'planning', sqlc.narg(kickoff_notes), sqlc.narg(kickoff_link), sqlc.arg(created_by)
) RETURNING id;

-- name: UpdateProject :exec
UPDATE syllabus.projects SET
    name = sqlc.arg(name), type = sqlc.arg(type), description = sqlc.narg(description), owner_id = sqlc.arg(owner_id),
    start_date = sqlc.narg(start_date), deadline = sqlc.narg(deadline), kickoff_notes = sqlc.narg(kickoff_notes),
    kickoff_link = sqlc.narg(kickoff_link), updated_at = now()
WHERE id = sqlc.arg(id) AND deleted_at IS NULL;

-- name: DeleteProject :exec
UPDATE syllabus.projects SET deleted_at = now(), updated_at = now() WHERE id = $1 AND deleted_at IS NULL;

-- name: LockProject :exec
UPDATE syllabus.projects SET status = 'active', plan_locked_at = now(), plan_locked_by = sqlc.arg(by), updated_at = now()
WHERE id = sqlc.arg(id) AND status = 'planning' AND deleted_at IS NULL;

-- name: SetProjectStatus :exec
UPDATE syllabus.projects SET status = sqlc.arg(status),
    completed_at = CASE WHEN sqlc.arg(status)::text = 'completed' THEN now() ELSE completed_at END, updated_at = now()
WHERE id = sqlc.arg(id) AND deleted_at IS NULL;

-- name: RemoveMembersExcept :exec
DELETE FROM syllabus.project_members WHERE project_id = sqlc.arg(project_id) AND NOT (user_id = ANY (sqlc.arg(keep)::uuid[]));

-- name: AddMembers :many
-- Thêm thành viên; trả người mới được thêm.
INSERT INTO syllabus.project_members (project_id, user_id)
SELECT sqlc.arg(project_id), unnest(sqlc.arg(user_ids)::uuid[])
ON CONFLICT DO NOTHING
RETURNING user_id;

-- ───────────────────────── Mốc ─────────────────────────

-- name: CreateMilestone :one
INSERT INTO syllabus.milestones (
    project_id, title, description, assignee_id, start_date, due_date, target_quantity, unit, status, completed_at, sort_order
) VALUES (
    sqlc.arg(project_id), sqlc.arg(title), sqlc.narg(description), sqlc.narg(assignee_id), sqlc.narg(start_date),
    sqlc.arg(due_date), sqlc.arg(target_quantity), sqlc.arg(unit), sqlc.arg(status),
    CASE WHEN sqlc.arg(status)::text = 'done' THEN sqlc.arg(now)::timestamptz END,
    coalesce((SELECT max(sort_order) FROM syllabus.milestones WHERE project_id = sqlc.arg(project_id)), 0) + 1
) RETURNING id;

-- name: GetMilestone :one
SELECT m.*, p.name AS project_name, p.code AS project_code, p.status AS project_status, p.owner_id AS project_owner_id
FROM syllabus.milestones m
JOIN syllabus.projects p ON p.id = m.project_id AND p.deleted_at IS NULL
WHERE m.id = $1 AND m.deleted_at IS NULL;

-- name: UpdateMilestone :exec
UPDATE syllabus.milestones SET
    title = sqlc.arg(title), description = sqlc.narg(description), assignee_id = sqlc.narg(assignee_id),
    start_date = sqlc.narg(start_date), due_date = sqlc.arg(due_date), target_quantity = sqlc.arg(target_quantity),
    unit = sqlc.arg(unit), status = sqlc.arg(status), completed_at = sqlc.narg(completed_at),
    reminded_at = sqlc.narg(reminded_at), updated_at = now()
WHERE id = sqlc.arg(id) AND deleted_at IS NULL;

-- name: SetMilestoneProgress :exec
UPDATE syllabus.milestones SET done_quantity = sqlc.arg(done_quantity), status = sqlc.arg(status),
    completed_at = sqlc.narg(completed_at), updated_at = now()
WHERE id = sqlc.arg(id) AND deleted_at IS NULL;

-- name: DeleteMilestone :exec
UPDATE syllabus.milestones SET deleted_at = now(), updated_at = now() WHERE id = $1 AND deleted_at IS NULL;

-- ───────────────────────── Cập nhật tiến độ ─────────────────────────

-- name: CreateUpdate :one
INSERT INTO syllabus.project_updates (project_id, milestone_id, user_id, quantity_done, content, difficulties, links, marks_complete)
VALUES (sqlc.arg(project_id), sqlc.narg(milestone_id), sqlc.arg(user_id), sqlc.narg(quantity_done), sqlc.arg(content),
        sqlc.narg(difficulties), sqlc.arg(links)::text[], sqlc.arg(marks_complete))
RETURNING id;

-- name: ProjectUpdates :many
-- Lịch sử cập nhật mới nhất trước (tối đa lim), kèm mốc (kể cả mốc đã xóa).
SELECT up.id, up.project_id, up.milestone_id, up.user_id, up.quantity_done, up.content, up.difficulties, up.links,
       up.marks_complete, up.response, up.responded_at, up.created_at,
       u.full_name AS user_name, m.title AS milestone_title, m.unit AS milestone_unit, r.full_name AS responder_name
FROM syllabus.project_updates up
LEFT JOIN iam.users u ON u.id = up.user_id
LEFT JOIN syllabus.milestones m ON m.id = up.milestone_id
LEFT JOIN iam.users r ON r.id = up.responded_by
WHERE up.project_id = ANY (sqlc.arg(project_ids)::uuid[])
ORDER BY up.created_at DESC, up.id DESC
LIMIT sqlc.arg(lim);

-- name: GetUpdate :one
SELECT up.id, up.project_id, up.user_id, up.response, p.name AS project_name, p.owner_id AS project_owner_id
FROM syllabus.project_updates up
JOIN syllabus.projects p ON p.id = up.project_id AND p.deleted_at IS NULL
WHERE up.id = $1;

-- name: RespondUpdate :exec
UPDATE syllabus.project_updates SET response = sqlc.arg(response), responded_by = sqlc.arg(responded_by),
    responded_at = now(), updated_at = now()
WHERE id = sqlc.arg(id);

-- ───────────────────────── Báo cáo ─────────────────────────

-- name: ReportProjects :many
-- Mặc định: dự án còn mở + dự án đóng (hoàn thành / hủy) cập nhật từ đầu tháng đang xem; có lọc thì theo trạng thái.
SELECT p.id, p.code, p.name, p.type, p.status, p.start_date, p.deadline, p.owner_id, o.full_name AS owner_name,
       (SELECT count(*) FROM syllabus.project_members pm WHERE pm.project_id = p.id)::bigint AS members_count
FROM syllabus.projects p
LEFT JOIN iam.users o ON o.id = p.owner_id
WHERE p.deleted_at IS NULL
  AND (CASE WHEN sqlc.narg(status)::text IS NOT NULL THEN p.status = sqlc.narg(status)
            ELSE p.status IN ('planning', 'active', 'paused')
                 OR (p.status IN ('completed', 'cancelled') AND p.updated_at >= sqlc.arg(since)::timestamptz) END)
ORDER BY CASE p.status WHEN 'active' THEN 0 WHEN 'planning' THEN 1 WHEN 'paused' THEN 2 ELSE 3 END,
         p.deadline NULLS FIRST;

-- name: StaffNames :many
SELECT id, full_name FROM iam.users WHERE id = ANY (sqlc.arg(ids)::uuid[]) ORDER BY full_name, id;

-- ───────────────────────── Mốc cho phân hệ khác ─────────────────────────

-- name: VisibleMilestones :many
-- Mốc (chưa xóa) của dự án (chưa xóa) người dùng được xem, chạm vào khoảng: hạn trong khoảng, hoặc chưa xong mà hạn
-- trước cuối khoảng, hoặc hoàn thành trong khoảng (báo cáo ngày / tuần, lịch Tổng quan).
SELECT m.id, m.project_id, m.title, m.due_date, m.status, m.completed_at, m.assignee_id, m.target_quantity, m.done_quantity,
       m.unit, p.name AS project_name, p.code AS project_code, p.status AS project_status, a.full_name AS assignee_name
FROM syllabus.milestones m
JOIN syllabus.projects p ON p.id = m.project_id AND p.deleted_at IS NULL
LEFT JOIN iam.users a ON a.id = m.assignee_id
WHERE m.deleted_at IS NULL
  AND (sqlc.arg(see_all)::bool OR p.owner_id = sqlc.arg(user_id)::uuid
       OR EXISTS (SELECT 1 FROM syllabus.project_members pm WHERE pm.project_id = p.id AND pm.user_id = sqlc.arg(user_id)::uuid)
       OR EXISTS (SELECT 1 FROM syllabus.milestones mm WHERE mm.project_id = p.id AND mm.deleted_at IS NULL AND mm.assignee_id = sqlc.arg(user_id)::uuid))
  AND ((m.due_date BETWEEN sqlc.arg(from_date)::date AND sqlc.arg(to_date)::date)
       OR (m.status <> 'done' AND m.due_date <= sqlc.arg(to_date)::date)
       OR (m.completed_at >= sqlc.arg(from_at)::timestamptz AND m.completed_at <= sqlc.arg(to_at)::timestamptz))
ORDER BY m.due_date, m.id;

-- name: DeliverableMilestones :many
-- KPI "Mốc dự án học thuật đúng hạn": mốc hạn trong kỳ người này nhận, hoặc mốc chưa giao của dự án người này phụ trách.
SELECT m.id, m.title, m.due_date, m.completed_at, p.name AS project_name
FROM syllabus.milestones m
JOIN syllabus.projects p ON p.id = m.project_id AND p.deleted_at IS NULL
WHERE m.deleted_at IS NULL
  AND m.due_date BETWEEN sqlc.arg(from_date)::date AND sqlc.arg(to_date)::date
  AND (m.assignee_id = sqlc.arg(user_id)::uuid OR (m.assignee_id IS NULL AND p.owner_id = sqlc.arg(user_id)::uuid))
ORDER BY m.due_date, m.id;

-- ───────────────────────── SLA mốc ─────────────────────────

-- name: MilestonesToRemind :many
-- Mốc của dự án đang thực hiện, chưa xong, chưa nhắc, có người nhận, hạn trong [from, to].
SELECT m.id, m.project_id, m.title, m.due_date, m.assignee_id, p.name AS project_name
FROM syllabus.milestones m
JOIN syllabus.projects p ON p.id = m.project_id AND p.deleted_at IS NULL AND p.status = 'active'
WHERE m.deleted_at IS NULL AND m.status <> 'done' AND m.reminded_at IS NULL AND m.assignee_id IS NOT NULL
  AND m.due_date BETWEEN sqlc.arg(from_date)::date AND sqlc.arg(to_date)::date
ORDER BY m.id;

-- name: MarkReminded :exec
UPDATE syllabus.milestones SET reminded_at = now() WHERE id = $1;

-- name: LateMilestones :many
-- Mốc của dự án đang thực hiện có hạn ≤ hôm nay mà chưa xong, hoặc xong sau ngày hạn (lọc thô; giờ chính xác so ở Go).
SELECT m.id, m.project_id, m.title, m.due_date, m.status, m.completed_at, m.assignee_id, m.target_quantity,
       m.done_quantity, m.unit, p.code AS project_code, p.name AS project_name, p.owner_id AS project_owner_id,
       a.full_name AS assignee_name
FROM syllabus.milestones m
JOIN syllabus.projects p ON p.id = m.project_id AND p.deleted_at IS NULL AND p.status = 'active'
LEFT JOIN iam.users a ON a.id = m.assignee_id
WHERE m.deleted_at IS NULL AND m.breached_at IS NULL AND m.due_date <= sqlc.arg(today)::date
  AND (m.status <> 'done' OR m.completed_at > m.due_date::timestamptz)
ORDER BY m.id;

-- name: MarkBreached :exec
UPDATE syllabus.milestones SET breached_at = now() WHERE id = $1;

-- ───────────────────────── Tổng quan (MyWorkBoard) ─────────────────────────

-- name: HasOpenAssignment :one
-- Người dùng đang nhận mốc chưa xong của dự án đang lên kế hoạch / đang thực hiện.
SELECT EXISTS (
    SELECT 1 FROM syllabus.milestones m
    JOIN syllabus.projects p ON p.id = m.project_id AND p.deleted_at IS NULL AND p.status IN ('planning', 'active')
    WHERE m.deleted_at IS NULL AND m.status <> 'done' AND m.assignee_id = sqlc.arg(user_id)::uuid
)::bool;

-- name: AgendaMilestones :many
-- Hạn mốc trên lịch: mốc chưa xong của dự án đang lên kế hoạch / đang thực hiện người dùng được xem, hạn trong
-- [from, to]; người không quản lý dự án chỉ thấy mốc mình nhận.
SELECT m.id, m.project_id, m.title, m.due_date, p.name AS project_name
FROM syllabus.milestones m
JOIN syllabus.projects p ON p.id = m.project_id AND p.deleted_at IS NULL AND p.status IN ('planning', 'active')
WHERE m.deleted_at IS NULL AND m.status <> 'done'
  AND m.due_date BETWEEN sqlc.arg(from_date)::date AND sqlc.arg(to_date)::date
  AND (sqlc.arg(manage)::bool OR m.assignee_id = sqlc.arg(user_id)::uuid)
  AND (sqlc.arg(see_all)::bool OR p.owner_id = sqlc.arg(user_id)::uuid
       OR EXISTS (SELECT 1 FROM syllabus.project_members pm WHERE pm.project_id = p.id AND pm.user_id = sqlc.arg(user_id)::uuid)
       OR EXISTS (SELECT 1 FROM syllabus.milestones mm WHERE mm.project_id = p.id AND mm.deleted_at IS NULL AND mm.assignee_id = sqlc.arg(user_id)::uuid))
ORDER BY m.due_date, m.id;

-- name: EnsureLiveSince :one
-- Thời điểm bắt đầu áp dụng SLA trễ mốc (mốc hạn trước đó không bị phạt hồi tố): lần chạy đầu ghi now, sau đó đọc lại.
WITH ins AS (
    INSERT INTO core.settings (key, value, description)
    VALUES ('sla_academic_project_live_since', to_jsonb(sqlc.arg(now)::timestamptz),
            'Thời điểm bắt đầu áp dụng SLA trễ mốc dự án học thuật (mốc hạn trước đó không bị phạt hồi tố)')
    ON CONFLICT (key) DO NOTHING
    RETURNING value
)
SELECT (SELECT value FROM ins UNION ALL SELECT value FROM core.settings WHERE key = 'sla_academic_project_live_since' LIMIT 1)::jsonb AS value;
