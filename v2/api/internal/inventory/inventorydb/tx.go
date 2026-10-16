package inventorydb

// DB: kết nối / giao dịch của Queries (để phân hệ khác ghi cùng giao dịch, vd. giao việc nhập bù). Viết tay, sqlc không sinh.
func (q *Queries) DB() DBTX { return q.db }
