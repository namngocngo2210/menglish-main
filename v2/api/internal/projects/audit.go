package projects

import (
	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/notify"
)

// Nhật ký: dự án học thuật thuộc phân hệ "Giáo trình & Syllabus" (menu Giáo trình → Dự án soạn sách & chương trình).
// Thông báo: các loại AdminNotification của AcademicProjectController / AcademicProjectSlaService.
func init() {
	audit.RegisterSubject("academic_project", "Dự án học thuật")
	audit.RegisterSubject("academic_project_milestone", "Mốc dự án học thuật")
	audit.RegisterSubject("academic_project_update", "Cập nhật tiến độ dự án")
	audit.RegisterPath("/academic-projects", audit.ModuleSyllabus)

	notify.RegisterType("academic_project_member", notify.TypeInfo{Label: "Tham gia dự án học thuật", Icon: "group_add", Tone: notify.TonePrimary})
	notify.RegisterType("academic_project_locked", notify.TypeInfo{Label: "Dự án đã chốt tiến độ", Icon: "lock", Tone: notify.TonePrimary})
	notify.RegisterType("academic_project_milestone", notify.TypeInfo{Label: "Được giao mốc dự án", Icon: "flag", Tone: notify.TonePrimary})
	notify.RegisterType("academic_project_update", notify.TypeInfo{Label: "Cập nhật tiến độ dự án", Icon: "update", Tone: notify.ToneInfo})
	notify.RegisterType("academic_project_response", notify.TypeInfo{Label: "Phản hồi cập nhật dự án", Icon: "reply", Tone: notify.ToneInfo})
	notify.RegisterType("academic_project_due", notify.TypeInfo{Label: "Mốc dự án sắp đến hạn", Icon: "alarm", Tone: notify.ToneWarning})
}
