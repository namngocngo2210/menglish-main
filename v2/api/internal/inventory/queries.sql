-- ───────────────────────── Hàng hóa & vật phẩm ─────────────────────────

-- name: ListItems :many
-- Tồn hiển thị = tổng tồn các chi nhánh + tồn cũ chưa phân chi nhánh (như Laravel).
SELECT i.*, COALESCE((SELECT sum(s.quantity) FROM ops.stock s WHERE s.item_id = i.id), 0)::bigint AS branch_total
FROM ops.inventory_items i
WHERE i.deleted_at IS NULL
  AND (sqlc.narg(search)::text IS NULL OR i.name ILIKE '%' || sqlc.narg(search) || '%'
       OR i.code ILIKE '%' || sqlc.narg(search) || '%' OR i.description ILIKE '%' || sqlc.narg(search) || '%')
  AND (sqlc.narg(category)::text IS NULL OR i.category = sqlc.narg(category))
  AND (sqlc.narg(active)::boolean IS NULL OR i.is_active = sqlc.narg(active))
ORDER BY i.category, i.name, i.id
LIMIT sqlc.arg(page_limit) OFFSET sqlc.arg(page_offset);

-- name: CountItems :one
SELECT count(*) FROM ops.inventory_items i
WHERE i.deleted_at IS NULL
  AND (sqlc.narg(search)::text IS NULL OR i.name ILIKE '%' || sqlc.narg(search) || '%'
       OR i.code ILIKE '%' || sqlc.narg(search) || '%' OR i.description ILIKE '%' || sqlc.narg(search) || '%')
  AND (sqlc.narg(category)::text IS NULL OR i.category = sqlc.narg(category))
  AND (sqlc.narg(active)::boolean IS NULL OR i.is_active = sqlc.narg(active));

-- name: ItemMetrics :one
SELECT
    count(*) AS total,
    count(*) FILTER (WHERE is_active) AS active,
    count(*) FILTER (WHERE category IN ('book', 'workbook')) AS books,
    count(*) FILTER (WHERE category IN ('uniform', 'backpack')) AS uniforms,
    (COALESCE(sum(unallocated_quantity), 0) + COALESCE((SELECT sum(quantity) FROM ops.stock), 0))::bigint AS total_stock
FROM ops.inventory_items WHERE deleted_at IS NULL;

-- name: GetItem :one
SELECT * FROM ops.inventory_items WHERE id = $1 AND deleted_at IS NULL;

-- name: GetItemAny :one
-- Kể cả mặt hàng đã xóa (xem nhật ký kho cũ).
SELECT * FROM ops.inventory_items WHERE id = $1;

-- name: LockItem :one
SELECT * FROM ops.inventory_items WHERE id = $1 FOR UPDATE;

-- name: CreateItem :one
INSERT INTO ops.inventory_items (code, name, category, unit, price, cost_price, unallocated_quantity, is_active, description, created_by)
VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10)
RETURNING *;

-- name: UpdateItem :one
UPDATE ops.inventory_items SET
    code = $2, name = $3, category = $4, unit = $5, price = $6, cost_price = $7, is_active = $8, description = $9,
    updated_at = now()
WHERE id = $1 AND deleted_at IS NULL
RETURNING *;

-- name: ToggleItem :one
UPDATE ops.inventory_items SET is_active = NOT is_active, updated_at = now()
WHERE id = $1 AND deleted_at IS NULL
RETURNING *;

-- name: SoftDeleteItem :one
UPDATE ops.inventory_items SET deleted_at = now(), updated_at = now()
WHERE id = $1 AND deleted_at IS NULL
RETURNING *;

-- name: TakeUnallocated :exec
UPDATE ops.inventory_items SET unallocated_quantity = unallocated_quantity - sqlc.arg(quantity)::int, updated_at = now()
WHERE id = $1;

-- name: ListActiveItems :many
SELECT * FROM ops.inventory_items WHERE deleted_at IS NULL AND is_active ORDER BY category, name, id;

-- ───────────────────────── Tồn kho theo chi nhánh ─────────────────────────

-- name: ListBranches :many
-- Chi nhánh trong phạm vi (ids NULL = mọi chi nhánh).
SELECT id, code, name, is_active FROM core.branches
WHERE sqlc.narg(ids)::uuid[] IS NULL OR id = ANY(sqlc.narg(ids)::uuid[])
ORDER BY name, id;

-- name: ListActiveBranches :many
SELECT id, code, name, is_active FROM core.branches
WHERE is_active AND (sqlc.narg(ids)::uuid[] IS NULL OR id = ANY(sqlc.narg(ids)::uuid[]))
ORDER BY name, id;

-- name: GetBranch :one
SELECT id, code, name, is_active FROM core.branches WHERE id = $1;

-- name: ListBranchStock :many
-- Mọi mặt hàng (chưa xóa) kèm số tồn tại chi nhánh; chưa có dòng tồn = 0.
SELECT i.id, i.code, i.name, i.unit, i.category, i.is_active, i.unallocated_quantity,
       COALESCE(s.quantity, 0)::int AS quantity
FROM ops.inventory_items i
LEFT JOIN ops.stock s ON s.item_id = i.id AND s.branch_id = sqlc.arg(branch_id)
WHERE i.deleted_at IS NULL
  AND (sqlc.narg(search)::text IS NULL OR i.name ILIKE '%' || sqlc.narg(search) || '%'
       OR i.code ILIKE '%' || sqlc.narg(search) || '%' OR i.description ILIKE '%' || sqlc.narg(search) || '%')
  AND (sqlc.narg(category)::text IS NULL OR i.category = sqlc.narg(category))
ORDER BY i.category, i.name, i.id;

-- name: StockQuantity :one
SELECT COALESCE((SELECT quantity FROM ops.stock WHERE branch_id = $1 AND item_id = $2), 0)::int;

-- name: StockByBranches :many
SELECT branch_id, item_id, quantity FROM ops.stock WHERE branch_id = ANY(sqlc.arg(branch_ids)::uuid[]);

-- name: EnsureStockRow :exec
INSERT INTO ops.stock (branch_id, item_id) VALUES ($1, $2) ON CONFLICT DO NOTHING;

-- name: LockStock :one
SELECT quantity FROM ops.stock WHERE branch_id = $1 AND item_id = $2 FOR UPDATE;

-- name: SetStock :exec
UPDATE ops.stock SET quantity = $3, updated_at = now() WHERE branch_id = $1 AND item_id = $2;

-- name: InsertMovement :one
INSERT INTO ops.stock_movements (
    branch_id, item_id, type, source, quantity_change, balance_after, transfer_id, counterpart_branch_id,
    receipt_id, tuition_id, student_id, document_number, note, created_by
) VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, $13, $14)
RETURNING *;

-- name: ListMovements :many
-- Nhật ký của chi nhánh (item_id NULL = mọi mặt hàng), mới nhất trước.
SELECT m.*, i.name AS item_name, i.code AS item_code, u.full_name AS user_name, cb.name AS counterpart_branch_name
FROM ops.stock_movements m
JOIN ops.inventory_items i ON i.id = m.item_id
LEFT JOIN iam.users u ON u.id = m.created_by
LEFT JOIN core.branches cb ON cb.id = m.counterpart_branch_id
WHERE m.branch_id = sqlc.arg(branch_id)
  AND (sqlc.narg(item_id)::uuid IS NULL OR m.item_id = sqlc.narg(item_id))
ORDER BY m.created_at DESC, m.id DESC
LIMIT sqlc.arg(row_limit);

-- ───────────────────────── Order học liệu ─────────────────────────

-- name: NextOrderNumber :one
-- Năm mới: bắt đầu sau số lớn nhất đã có (kể cả order nhập từ Laravel).
INSERT INTO ops.material_order_counters (year, last_number)
VALUES (
    sqlc.arg(year)::smallint,
    COALESCE((SELECT max(substring(code FROM 9)::int) FROM ops.material_orders
              WHERE code LIKE 'OH-' || sqlc.arg(year)::smallint::text || '-%'), 0) + 1
)
ON CONFLICT (year) DO UPDATE SET last_number = ops.material_order_counters.last_number + 1
RETURNING last_number;

-- name: CreateOrder :one
INSERT INTO ops.material_orders (
    code, branch_id, class_id, work_task_id, requester_id, category, title, description, quantity, use_date, due_at,
    created_late
) VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12)
RETURNING id;

-- name: GetOrder :one
SELECT o.*, b.name AS branch_name, r.full_name AS requester_name, p.full_name AS processor_name
FROM ops.material_orders o
JOIN core.branches b ON b.id = o.branch_id
JOIN iam.users r ON r.id = o.requester_id
LEFT JOIN iam.users p ON p.id = o.processed_by
WHERE o.id = $1 AND o.deleted_at IS NULL;

-- name: ListOrders :many
-- Phạm vi: all_scope = mọi order; còn lại order của mình hoặc thuộc branch_ids (NULL = chỉ của mình).
SELECT o.*, b.name AS branch_name, r.full_name AS requester_name, p.full_name AS processor_name
FROM ops.material_orders o
JOIN core.branches b ON b.id = o.branch_id
JOIN iam.users r ON r.id = o.requester_id
LEFT JOIN iam.users p ON p.id = o.processed_by
WHERE o.deleted_at IS NULL
  AND (sqlc.arg(all_scope)::boolean OR o.requester_id = sqlc.arg(user_id)
       OR o.branch_id = ANY(COALESCE(sqlc.narg(branch_ids)::uuid[], '{}')))
  AND (sqlc.narg(status)::text IS NULL OR o.status = sqlc.narg(status))
  AND (sqlc.narg(category)::text IS NULL OR o.category = sqlc.narg(category))
  AND (sqlc.narg(branch_id)::uuid IS NULL OR o.branch_id = sqlc.narg(branch_id))
  AND (sqlc.narg(search)::text IS NULL OR o.title ILIKE '%' || sqlc.narg(search) || '%'
       OR o.code ILIKE '%' || sqlc.narg(search) || '%')
ORDER BY CASE WHEN o.status = 'overdue' THEN 0 WHEN o.status IN ('pending', 'processing') THEN 1 ELSE 2 END,
         o.due_at, o.created_at DESC, o.id DESC
LIMIT sqlc.arg(page_limit) OFFSET sqlc.arg(page_offset);

-- name: CountOrders :one
SELECT count(*) FROM ops.material_orders o
WHERE o.deleted_at IS NULL
  AND (sqlc.arg(all_scope)::boolean OR o.requester_id = sqlc.arg(user_id)
       OR o.branch_id = ANY(COALESCE(sqlc.narg(branch_ids)::uuid[], '{}')))
  AND (sqlc.narg(status)::text IS NULL OR o.status = sqlc.narg(status))
  AND (sqlc.narg(category)::text IS NULL OR o.category = sqlc.narg(category))
  AND (sqlc.narg(branch_id)::uuid IS NULL OR o.branch_id = sqlc.narg(branch_id))
  AND (sqlc.narg(search)::text IS NULL OR o.title ILIKE '%' || sqlc.narg(search) || '%'
       OR o.code ILIKE '%' || sqlc.narg(search) || '%');

-- name: ClaimOrder :one
-- Nhận xử lý: chỉ order Chờ xử lý / Quá hạn.
UPDATE ops.material_orders SET status = 'processing', processed_by = $2, updated_at = now()
WHERE id = $1 AND deleted_at IS NULL AND status IN ('pending', 'overdue')
RETURNING id;

-- name: FinishOrder :one
-- Hoàn thành / từ chối order còn mở; xử lý trễ = sau hạn (kể cả order đã "Quá hạn").
UPDATE ops.material_orders SET
    status = sqlc.arg(status), processed_by = sqlc.arg(processed_by), processed_at = sqlc.arg(processed_at),
    processed_late = due_at < sqlc.arg(processed_at),
    processor_note = COALESCE(sqlc.narg(processor_note), processor_note),
    reject_reason = sqlc.narg(reject_reason), updated_at = now()
WHERE id = sqlc.arg(id) AND deleted_at IS NULL AND status IN ('pending', 'processing', 'overdue')
RETURNING processed_late;

-- name: MarkOverdue :many
-- Order Chờ / Đang xử lý đã qua hạn → Quá hạn; chạy lại không đổi gì thêm.
UPDATE ops.material_orders SET status = 'overdue', updated_at = now()
WHERE deleted_at IS NULL AND status IN ('pending', 'processing') AND due_at < $1
RETURNING id, code, title, category, branch_id, due_at;
