package kpi

import "github.com/namngocngo2210/menglish-main/v2/api/internal/audit"

// Nhật ký: đường dẫn /kpi không gắn phân hệ riêng (Laravel resolveModule) nên vào "Hệ thống chung".
func init() {
	audit.RegisterSubject("kpi_criterion", "Tiêu chí KPI")
	audit.RegisterSubject("kpi_sheet", "Phiếu KPI")
	audit.RegisterSubject("kpi_setting", "Cấu hình KPI")
	audit.RegisterSubject("student_attendance", "Điểm danh học viên")
}
