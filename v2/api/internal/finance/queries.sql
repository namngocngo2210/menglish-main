-- name: VisibleBranches :many
-- Chi nhánh đang hoạt động trong phạm vi (all_branches = phạm vi Toàn hệ thống), theo thứ tự tạo như Laravel orderBy('id').
SELECT id, name FROM core.branches
WHERE is_active AND (sqlc.arg('all_branches')::boolean OR id = ANY (sqlc.arg('ids')::uuid[]))
ORDER BY id;

-- name: BranchExists :one
SELECT EXISTS (SELECT 1 FROM core.branches WHERE id = $1);

-- name: ListExpenses :many
-- Khoản chi tự nhập trong khoảng ngày; lọc chi nhánh (NULL = mọi chi nhánh) và tìm theo nội dung / ghi chú / người lập.
SELECT e.*, b.name AS branch_name, u.full_name AS creator_name
FROM finance.expenses e
LEFT JOIN core.branches b ON b.id = e.branch_id
LEFT JOIN iam.users u ON u.id = e.created_by
WHERE e.deleted_at IS NULL
  AND e.expense_date BETWEEN sqlc.arg('from_date')::date AND sqlc.arg('to_date')::date
  AND (sqlc.narg('branch_id')::uuid IS NULL OR e.branch_id = sqlc.narg('branch_id'))
  AND (sqlc.narg('q')::text IS NULL
       OR e.title ILIKE '%' || sqlc.narg('q') || '%'
       OR e.notes ILIKE '%' || sqlc.narg('q') || '%'
       OR u.full_name ILIKE '%' || sqlc.narg('q') || '%')
ORDER BY
  CASE WHEN sqlc.arg('ascending')::boolean THEN e.expense_date END ASC,
  CASE WHEN NOT sqlc.arg('ascending')::boolean THEN e.expense_date END DESC,
  e.id;

-- name: ExpenseSums :many
-- Tổng khoản chi tự nhập trong khoảng ngày theo chi nhánh / nhóm chi / hình thức (báo cáo doanh thu tạm tính).
SELECT branch_id, category, payment_method, count(*)::int AS items, coalesce(sum(amount), 0)::bigint AS amount
FROM finance.expenses
WHERE deleted_at IS NULL AND expense_date BETWEEN sqlc.arg('from_date')::date AND sqlc.arg('to_date')::date
GROUP BY branch_id, category, payment_method;

-- name: GetExpense :one
SELECT * FROM finance.expenses WHERE id = $1 AND deleted_at IS NULL;

-- name: CreateExpense :one
INSERT INTO finance.expenses (expense_date, title, amount, payment_method, branch_id, category, notes, created_by)
VALUES ($1, $2, $3, $4, $5, $6, $7, $8)
RETURNING *;

-- name: UpdateExpense :one
UPDATE finance.expenses
SET expense_date = $2, title = $3, amount = $4, payment_method = $5, branch_id = $6, category = $7, notes = $8,
    updated_at = now()
WHERE id = $1 AND deleted_at IS NULL
RETURNING *;

-- name: DeleteExpense :execrows
UPDATE finance.expenses SET deleted_at = now(), updated_at = now() WHERE id = $1 AND deleted_at IS NULL;
