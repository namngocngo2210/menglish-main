-- Order học liệu cho Tổng quan (dashboard.Orders).

-- name: DashboardAcademicOrders :many
-- Order học thuật của các giáo viên, tạo hoặc dùng trong khoảng, mới trước.
SELECT o.id, o.code, o.title, o.status, o.use_date, u.full_name AS requester_name, c.name AS class_name
FROM ops.material_orders o
JOIN iam.users u ON u.id = o.requester_id
LEFT JOIN academic.classes c ON c.id = o.class_id
WHERE o.deleted_at IS NULL AND o.category = 'academic' AND o.requester_id = ANY (@requesters::uuid[])
  AND (o.created_at BETWEEN @from_at::timestamptz AND @to_at::timestamptz OR o.use_date BETWEEN @date_from::date AND @date_to::date)
ORDER BY o.created_at DESC, o.id DESC
LIMIT @lim::int;
