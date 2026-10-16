-- Công việc (ops.work_tasks). Phạm vi xem (WorkTask::scopeVisibleTo của Laravel) viết lặp ở các câu cần:
--   all: mọi việc; own: việc mình giao / được giao / đã chuyển lại từ mình;
--   branch: own + việc thuộc chi nhánh của mình hoặc người nhận có chi nhánh chính thuộc chi nhánh của mình.

-- name: ListTasks :many
-- Danh sách đầu việc: tab "Của tôi" (mine) hoặc "Tôi giao" (assigned), lọc trạng thái / loại / hạn / người nhận / từ khóa.
-- Thứ tự như Laravel: Quá hạn → Bị chặn → Chờ xác nhận → Đang thực hiện → Mới → Hoàn thành → Đã hủy, rồi mới tạo trước.
SELECT sqlc.embed(t),
       a.full_name AS assignee_name, c.full_name AS creator_name, hb.full_name AS handed_back_from_name,
       count(*) OVER () AS total
FROM ops.work_tasks t
LEFT JOIN iam.users a ON a.id = t.assignee_id
JOIN iam.users c ON c.id = t.creator_id
LEFT JOIN iam.users hb ON hb.id = t.handed_back_from_id
WHERE (CASE WHEN @tab::text = 'assigned' THEN t.creator_id = @me::uuid ELSE t.assignee_id = @me::uuid END)
  AND (@scope::text = 'all'
       OR t.creator_id = @me::uuid OR t.assignee_id = @me::uuid OR t.handed_back_from_id = @me::uuid
       OR (@scope::text = 'branch' AND (t.branch_id = ANY (@branch_ids::uuid[]) OR EXISTS (
             SELECT 1 FROM iam.user_branches ub WHERE ub.user_id = t.assignee_id AND ub.is_primary
               AND ub.branch_id = ANY (@branch_ids::uuid[])))))
  AND (sqlc.narg(status)::text IS NULL OR t.status = sqlc.narg(status)::text)
  AND (sqlc.narg(task_type)::text IS NULL OR t.task_type = sqlc.narg(task_type)::text)
  AND (sqlc.narg(date_from)::date IS NULL OR t.due_date >= sqlc.narg(date_from)::date)
  AND (sqlc.narg(date_to)::date IS NULL OR t.due_date <= sqlc.narg(date_to)::date)
  AND (sqlc.narg(assignee_id)::uuid IS NULL OR t.assignee_id = sqlc.narg(assignee_id)::uuid)
  AND (sqlc.narg(search)::text IS NULL OR t.title ILIKE sqlc.narg(search)::text
       OR t.description ILIKE sqlc.narg(search)::text OR a.full_name ILIKE sqlc.narg(search)::text)
ORDER BY CASE t.status
             WHEN 'overdue' THEN 1 WHEN 'blocked' THEN 2 WHEN 'pending_confirmation' THEN 3
             WHEN 'in_progress' THEN 4 WHEN 'new' THEN 5 WHEN 'completed' THEN 6 WHEN 'canceled' THEN 7 ELSE 8
         END, t.created_at DESC, t.id DESC
LIMIT @lim::int OFFSET @off::int;

-- name: TaskCounts :one
-- Số đếm trên đầu danh sách: việc của tôi, việc tôi giao, quá hạn / chờ xác nhận trong việc của tôi + việc tôi giao.
SELECT
    count(*) FILTER (WHERE t.assignee_id = @me::uuid) AS mine,
    count(*) FILTER (WHERE t.creator_id = @me::uuid) AS assigned,
    count(*) FILTER (WHERE t.status = 'overdue') AS overdue,
    count(*) FILTER (WHERE t.status = 'pending_confirmation') AS pending
FROM ops.work_tasks t
WHERE t.assignee_id = @me::uuid OR t.creator_id = @me::uuid;

-- name: ListOwnAssignees :many
-- Ô lọc "Nhân viên": người nhận của các việc tôi được giao hoặc tôi giao.
SELECT u.id, u.full_name,
       COALESCE((SELECT array_agg(r.short_label ORDER BY r.sort, r.name) FROM iam.user_roles ur
                 JOIN iam.roles r ON r.name = ur.role WHERE ur.user_id = u.id), '{}')::text[] AS role_labels
FROM iam.users u
WHERE u.id IN (SELECT t.assignee_id FROM ops.work_tasks t
               WHERE t.assignee_id IS NOT NULL AND (t.assignee_id = @me::uuid OR t.creator_id = @me::uuid))
ORDER BY u.full_name;

-- name: GetVisibleTask :one
-- Chi tiết công việc: chỉ việc trong phạm vi xem (ngoài phạm vi coi như không có).
SELECT sqlc.embed(t),
       a.full_name AS assignee_name, c.full_name AS creator_name, hb.full_name AS handed_back_from_name,
       cb.full_name AS confirmed_by_name, b.name AS branch_name
FROM ops.work_tasks t
LEFT JOIN iam.users a ON a.id = t.assignee_id
JOIN iam.users c ON c.id = t.creator_id
LEFT JOIN iam.users hb ON hb.id = t.handed_back_from_id
LEFT JOIN iam.users cb ON cb.id = t.confirmed_by
LEFT JOIN core.branches b ON b.id = t.branch_id
WHERE t.id = @id::uuid
  AND (@scope::text = 'all'
       OR t.creator_id = @me::uuid OR t.assignee_id = @me::uuid OR t.handed_back_from_id = @me::uuid
       OR (@scope::text = 'branch' AND (t.branch_id = ANY (@branch_ids::uuid[]) OR EXISTS (
             SELECT 1 FROM iam.user_branches ub WHERE ub.user_id = t.assignee_id AND ub.is_primary
               AND ub.branch_id = ANY (@branch_ids::uuid[])))));

-- name: LockTask :one
SELECT * FROM ops.work_tasks WHERE id = $1 FOR UPDATE;

-- name: InsertTask :one
INSERT INTO ops.work_tasks (
    title, description, creator_id, assignee_id, branch_id, class_id, lesson_session, time_slot_category,
    task_type, kind, frequency, due_date, due_time, status
) VALUES (
    @title, sqlc.narg(description), @creator_id, sqlc.narg(assignee_id), sqlc.narg(branch_id), sqlc.narg(class_id),
    sqlc.narg(lesson_session), @time_slot_category, @task_type, sqlc.narg(kind), sqlc.narg(frequency),
    sqlc.narg(due_date), sqlc.narg(due_time), 'new'
)
RETURNING *;

-- name: SaveTaskState :one
-- Ghi trạng thái sau khi đổi (service đọc bằng LockTask, sửa trong Go rồi ghi lại); tăng version.
UPDATE ops.work_tasks SET
    status = @status,
    assignee_id = sqlc.narg(assignee_id),
    handed_back_from_id = sqlc.narg(handed_back_from_id),
    handed_back_at = sqlc.narg(handed_back_at),
    blocked_reason = sqlc.narg(blocked_reason),
    rejection_reason = sqlc.narg(rejection_reason),
    completion_note = sqlc.narg(completion_note),
    completion_proof_url = sqlc.narg(completion_proof_url),
    confirmed_by = sqlc.narg(confirmed_by),
    confirmed_at = sqlc.narg(confirmed_at),
    completed_at = sqlc.narg(completed_at),
    version = version + 1,
    updated_at = now()
WHERE id = @id
RETURNING *;

-- name: RecurringOccurrenceExists :one
-- Lượt kế tiếp của việc lặp đã có chưa (cùng tiêu đề, người nhận, hạn).
SELECT EXISTS (
    SELECT 1 FROM ops.work_tasks
    WHERE title = @title AND assignee_id IS NOT DISTINCT FROM sqlc.narg(assignee_id)::uuid
      AND task_type = 'recurring' AND due_date = @due_date::date
);

-- name: ListAwaitingConfirmation :many
-- Việc chờ Admin xác nhận hoàn thành (không tự duyệt việc của mình). Chỉ gọi khi người xem là Admin.
-- TODO(module lớp): bỏ việc "Trực lớp" đang có báo cáo trực lớp chờ xác nhận (Laravel lọc whereDoesntHave classReport).
SELECT sqlc.embed(t),
       a.full_name AS assignee_name, c.full_name AS creator_name
FROM ops.work_tasks t
LEFT JOIN iam.users a ON a.id = t.assignee_id
JOIN iam.users c ON c.id = t.creator_id
WHERE t.status = 'pending_confirmation'
  AND (t.assignee_id IS NULL OR t.assignee_id <> @me::uuid)
  AND (sqlc.narg(assignee_id)::uuid IS NULL OR t.assignee_id = sqlc.narg(assignee_id)::uuid)
  AND (sqlc.narg(search)::text IS NULL OR t.title ILIKE sqlc.narg(search)::text OR a.full_name ILIKE sqlc.narg(search)::text)
ORDER BY t.updated_at DESC, t.id DESC;

-- name: CountAwaitingConfirmation :one
SELECT count(*) FROM ops.work_tasks t
WHERE t.status = 'pending_confirmation' AND (t.assignee_id IS NULL OR t.assignee_id <> @me::uuid);

-- name: ListAssignableUsers :many
-- Người nhận việc (WorkTaskController::assignableUsers): nhân sự đang hoạt động.
--   mode all: mọi người; branch: người có chi nhánh chính thuộc chi nhánh của mình, chính mình, Admin;
--   approvers (chỉ được đề xuất): người có quyền work_task.approve (vai trò hoặc phân quyền cá nhân) và Admin.
SELECT u.id, u.full_name,
       COALESCE((SELECT array_agg(r.short_label ORDER BY r.sort, r.name) FROM iam.user_roles ur
                 JOIN iam.roles r ON r.name = ur.role WHERE ur.user_id = u.id), '{}')::text[] AS role_labels
FROM iam.users u
WHERE u.kind = 'staff' AND u.status = 'active'
  AND (@mode::text = 'all'
       OR EXISTS (SELECT 1 FROM iam.user_roles ur WHERE ur.user_id = u.id AND ur.role = 'admin')
       OR (@mode::text = 'branch' AND (u.id = @me::uuid OR EXISTS (
             SELECT 1 FROM iam.user_branches ub WHERE ub.user_id = u.id AND ub.is_primary
               AND ub.branch_id = ANY (@branch_ids::uuid[]))))
       OR (@mode::text = 'approvers' AND (
             (EXISTS (SELECT 1 FROM iam.user_roles ur JOIN iam.role_permissions rp ON rp.role = ur.role
                      WHERE ur.user_id = u.id AND rp.permission = 'work_task.approve')
              AND NOT EXISTS (SELECT 1 FROM iam.permission_overrides o WHERE o.user_id = u.id
                              AND o.permission = 'work_task.approve' AND o.scope_type = 'all' AND NOT o.allow))
             OR EXISTS (SELECT 1 FROM iam.permission_overrides o WHERE o.user_id = u.id
                        AND o.permission = 'work_task.approve' AND o.scope_type = 'all' AND o.allow))))
ORDER BY u.full_name;

-- name: ListAssistants :many
-- Trợ giảng được giao việc theo ca: có quyền đối tượng "Cổng trợ giảng" (portal.assistant), đang hoạt động;
-- branch_ids khác NULL thì chỉ người có chi nhánh chính thuộc các chi nhánh đó.
SELECT u.id, u.full_name, b.name AS branch_name
FROM iam.users u
LEFT JOIN iam.user_branches pb ON pb.user_id = u.id AND pb.is_primary
LEFT JOIN core.branches b ON b.id = pb.branch_id
WHERE u.status = 'active'
  AND ((EXISTS (SELECT 1 FROM iam.user_roles ur JOIN iam.role_permissions rp ON rp.role = ur.role
                WHERE ur.user_id = u.id AND rp.permission = 'portal.assistant')
        AND NOT EXISTS (SELECT 1 FROM iam.permission_overrides o WHERE o.user_id = u.id
                        AND o.permission = 'portal.assistant' AND o.scope_type = 'all' AND NOT o.allow))
       OR EXISTS (SELECT 1 FROM iam.permission_overrides o WHERE o.user_id = u.id
                  AND o.permission = 'portal.assistant' AND o.scope_type = 'all' AND o.allow))
  AND (sqlc.narg(branch_ids)::uuid[] IS NULL OR pb.branch_id = ANY (sqlc.narg(branch_ids)::uuid[]))
ORDER BY u.full_name;

-- name: ListActiveBranches :many
-- Chi nhánh đang hoạt động; branch_ids khác NULL thì chỉ các chi nhánh đó.
SELECT id, name FROM core.branches
WHERE is_active AND (sqlc.narg(branch_ids)::uuid[] IS NULL OR id = ANY (sqlc.narg(branch_ids)::uuid[]))
ORDER BY name;

-- name: GetActiveBranch :one
SELECT id, name FROM core.branches WHERE id = $1 AND is_active;

-- name: GetUserBrief :one
SELECT u.id, u.full_name, b.name AS branch_name
FROM iam.users u
LEFT JOIN iam.user_branches pb ON pb.user_id = u.id AND pb.is_primary
LEFT JOIN core.branches b ON b.id = pb.branch_id
WHERE u.id = $1;

-- name: ListAssigneeTasksOn :many
-- Nhiệm vụ trong ngày của một người (Portal trợ giảng), theo giờ hạn.
SELECT sqlc.embed(t), c.full_name AS creator_name
FROM ops.work_tasks t
JOIN iam.users c ON c.id = t.creator_id
WHERE t.assignee_id = @assignee_id::uuid AND t.due_date = @day::date
ORDER BY t.due_time NULLS LAST, t.no;

-- name: CountOverdueBefore :one
-- Việc có hạn của các ngày trước chưa xong (nhiệm vụ hằng ngày của TA không có hạn nên không tính).
SELECT count(*) FROM ops.work_tasks
WHERE assignee_id = @assignee_id::uuid AND due_date < @day::date
  AND (kind IS NULL OR kind <> 'ta_daily')
  AND status NOT IN ('completed', 'pending_confirmation', 'canceled');

-- name: MarkOverdue :many
-- Việc Mới / Đang thực hiện đã qua hạn (ngày + giờ hạn, không giờ = 23:59) ít nhất 1 phút → Quá hạn.
-- now_local: giờ Việt Nam hiện tại (không múi giờ). Idempotent.
UPDATE ops.work_tasks SET status = 'overdue', version = version + 1, updated_at = now()
WHERE status IN ('new', 'in_progress')
  AND (kind IS NULL OR kind <> 'ta_daily')
  AND due_date IS NOT NULL
  AND due_date + COALESCE(due_time, time '23:59') + interval '1 minute' <= @now_local::timestamp
RETURNING id, title, assignee_id, due_date, due_time;
