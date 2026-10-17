package finance

import "github.com/namngocngo2210/menglish-main/v2/api/internal/audit"

// Nhật ký: khoản chi vận hành (activity của OperatingExpense ở Laravel); đường dẫn /finance đã gắn phân hệ Học phí & Thu chi.
func init() {
	audit.RegisterSubject("operating_expense", "Khoản chi vận hành")
}
