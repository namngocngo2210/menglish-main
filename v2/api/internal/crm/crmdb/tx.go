package crmdb

// DB trả kết nối / giao dịch Queries đang dùng, để ghi nhật ký vận hành và gửi thông báo cùng giao dịch.
// File viết tay (sqlc không sinh), giữ khi chạy sqlc generate.
func (q *Queries) DB() DBTX { return q.db }
