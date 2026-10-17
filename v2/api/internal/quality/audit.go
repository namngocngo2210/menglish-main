package quality

import "github.com/namngocngo2210/menglish-main/v2/api/internal/audit"

// Nhật ký: dự giờ / checklist / họp giáo viên thuộc phân hệ "Học viên & Lớp học" như các màn lớp của Laravel.
func init() {
	audit.RegisterSubject("qa_observation", "Lượt dự giờ vận hành")
	audit.RegisterSubject("academic_observation", "Đánh giá dự giờ học thuật")
	audit.RegisterSubject("class_checklist", "Checklist học phí & feedback")
	audit.RegisterSubject("teacher_meeting_report", "Báo cáo họp giáo viên")
	audit.RegisterPath("/class-quality", audit.ModuleStudents)
}
