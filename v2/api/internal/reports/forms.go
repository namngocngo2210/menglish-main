package reports

import (
	"slices"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

// Biểu mẫu báo cáo theo vai trò (Laravel StaffReportController::STRUCTURED / TEACHER_* / WEEKLY_* … và StaffType).
// Giữ đúng khóa và nhãn của Laravel để dữ liệu nhập từ hệ thống cũ đọc được.

const (
	KindDailyOps          = "daily_ops"
	KindWeeklyKpi         = "weekly_kpi"
	KindAcademicWeekly    = "academic_weekly"
	KindAcademicMonthly   = "academic_monthly"
	KindAcademicQuarterly = "academic_quarterly"
	KindTeacherMonthly    = "teacher_monthly"
	KindTeacherAttendance = "teacher_attendance"
)

// structuredKind: nhãn, loại kỳ (cột type, màn Tổng hợp lọc theo cột này), dành cho ai; Tab = false thì không có tab riêng
// (chấm công tháng nằm trong màn báo cáo tháng của giáo viên).
type structuredKind struct {
	Label string
	Type  string
	For   string
	Tab   bool
}

var structured = map[string]structuredKind{
	KindDailyOps:          {"Báo cáo ngày", "daily", "Học vụ / Học thuật", true},
	KindWeeklyKpi:         {"Báo cáo tuần (KPI)", "weekly", "Học vụ", true},
	KindAcademicWeekly:    {"Báo cáo tuần", "weekly", "Học thuật", true},
	KindAcademicMonthly:   {"Báo cáo tháng", "monthly", "Học thuật", true},
	KindAcademicQuarterly: {"Báo cáo quý", "quarterly", "Học thuật", true},
	KindTeacherMonthly:    {"Báo cáo tháng", "monthly", "giáo viên", true},
	KindTeacherAttendance: {"Báo cáo chấm công tháng", "monthly", "giáo viên", false},
}

// typeLabels: nhãn loại báo cáo (StaffReport::TYPE_LABELS), cũng là danh sách ô lọc "Loại" ở màn Tổng hợp.
var typeLabels = []Option{
	{"journal", "Nhật ký sự vụ"},
	{"daily", "Báo cáo ngày"},
	{"weekly", "Báo cáo tuần"},
	{"monthly", "Báo cáo tháng"},
	{"quarterly", "Báo cáo quý"},
}

func typeLabel(t string) string {
	for _, o := range typeLabels {
		if o.Value == t {
			return o.Label
		}
	}
	return t
}

func severityLabel(s *string) string {
	if s == nil {
		return "—"
	}
	switch *s {
	case "urgent":
		return "Khẩn cấp"
	case "important":
		return "Quan trọng"
	case "normal":
		return "Bình thường"
	}
	return "—"
}

// ───────────── Vai trò → kỳ báo cáo (StaffType) ─────────────

var teacherRoles = []string{iam.RoleTeacherFulltime, iam.RoleTeacherParttime}
var teachingRoles = []string{iam.RoleTeacherFulltime, iam.RoleTeacherParttime, iam.RoleAssistant}

func hasAny(roles []string, want ...string) bool {
	return slices.ContainsFunc(want, func(r string) bool { return slices.Contains(roles, r) })
}

// reportCadence: kỳ báo cáo định kỳ chính — Học vụ ngày, Học thuật tuần, giáo viên / trợ giảng tháng, còn lại ngày.
func reportCadence(roles []string) string {
	switch {
	case slices.Contains(roles, iam.RoleAcademicStaff):
		return "daily"
	case slices.Contains(roles, iam.RoleAcademicLead):
		return "weekly"
	case hasAny(roles, teachingRoles...):
		return "monthly"
	}
	return "daily"
}

// structuredKinds: báo cáo có cấu trúc theo vai trò — Học vụ: ngày + tuần KPI; Học thuật: ngày + tuần + tháng + quý;
// giáo viên: tháng theo lớp (kèm chấm công tháng).
func structuredKinds(roles []string) []string {
	var out []string
	staff, lead := slices.Contains(roles, iam.RoleAcademicStaff), slices.Contains(roles, iam.RoleAcademicLead)
	if staff || lead {
		out = append(out, KindDailyOps)
	}
	if staff {
		out = append(out, KindWeeklyKpi)
	}
	if lead {
		out = append(out, KindAcademicWeekly, KindAcademicMonthly, KindAcademicQuarterly)
	}
	if hasAny(roles, teacherRoles...) {
		out = append(out, KindTeacherMonthly)
	}
	return out
}

// primaryStructuredKind: màn có cấu trúc thay cho báo cáo định kỳ chính (cùng loại kỳ), "" nếu không có.
func primaryStructuredKind(roles []string) string {
	cadence := reportCadence(roles)
	for _, k := range structuredKinds(roles) {
		if structured[k].Type == cadence {
			return k
		}
	}
	return ""
}

// dailyAudience: nhãn chức danh của báo cáo ngày — Học vụ, hoặc Học thuật khi không kiêm Học vụ.
func dailyAudience(roles []string) string {
	if slices.Contains(roles, iam.RoleAcademicStaff) || !slices.Contains(roles, iam.RoleAcademicLead) {
		return AudienceOperations
	}
	return AudienceAcademic
}

const (
	AudienceOperations = "Học vụ"
	AudienceAcademic   = "Học thuật"
)

// ───────────── Báo cáo ngày ─────────────

// dailyNoteLabels: ô ghi chú nhập tay của từng khối (DailyReportMetrics::NOTE_LABELS).
var dailyNoteLabels = map[string]string{
	"operations": "Ghi chú vận hành lớp học",
	"enrollment": "Ghi chú tuyển sinh / học viên",
	"media":      "Ghi chú truyền thông",
	"projects":   "Ghi chú dự án",
	"materials":  "Ghi chú học liệu & đề test",
	"syllabus":   "Ghi chú giáo trình",
	"quality":    "Ghi chú chất lượng giảng dạy",
}

// dailyNoteOrder: thứ tự khối khi gộp ghi chú (báo cáo tuần).
var dailyNoteOrder = []string{"operations", "enrollment", "media", "projects", "materials", "syllabus", "quality"}

// legacyDailyFields: ô nhập tay của báo cáo ngày Học vụ bản cũ (trước 15/10/2026), chỉ để hiện lại báo cáo cũ.
var legacyDailyFields = []Option{
	{"classes_planned", "Số lớp kế hoạch"},
	{"classes_actual", "Số lớp thực tế"},
	{"classes_note", "Ghi chú lớp học"},
	{"teachers", "Số giáo viên"},
	{"teachers_note", "Ghi chú giáo viên"},
	{"assistants", "Số trợ giảng"},
	{"assistants_note", "Ghi chú trợ giảng"},
	{"class_situation", "Tình hình lớp học"},
	{"facilities", "Cơ sở vật chất"},
	{"support_students", "Số học sinh bổ trợ"},
	{"support_note", "Ghi chú bổ trợ"},
	{"new_data", "Data mới nhận"},
	{"data_note", "Ghi chú data"},
	{"test_students", "Số học sinh test"},
	{"test_note", "Ghi chú test"},
	{"fees", "Số lượng thu phí"},
	{"fee_detail", "Chi tiết thu phí"},
	{"media_posts", "Bài đăng truyền thông"},
}

// ───────────── Báo cáo tuần ─────────────

// weeklySections: 3 bảng của báo cáo tuần Học thuật.
var weeklySections = []Option{
	{"done", "Công việc đã làm trong tuần"},
	{"planned", "Công việc dự kiến tuần tới"},
	{"requests", "Quản lý yêu cầu"},
}

// weeklyColumns: cột của mỗi dòng báo cáo tuần Học thuật (ngoài STT / thao tác).
var weeklyColumns = []Option{
	{"content", "Nội dung"},
	{"detail", "Chi tiết"},
	{"deadline", "Deadline"},
	{"progress", "Tiến độ"},
	{"link", "Link sản phẩm"},
	{"report", "Báo cáo"},
	{"support", "Đề xuất HT"},
	{"note", "Ghi chú"},
}

var weeklyProgress = []int{0, 25, 50, 75, 100}

// weeklyMetricLabels: chỉ số quy mô báo cáo tuần Học vụ (nhãn để đọc báo cáo nhập tay cũ).
var weeklyMetricLabels = []Option{
	{"classes_running", "Số lớp đang chạy"},
	{"students", "Số học sinh"},
	{"teachers", "Số GV phụ trách"},
	{"new_leads", "Data mới nhận"},
	{"new_closed", "HV mới chốt"},
	{"transfers", "HV chuyển lớp"},
	{"makeups", "HV học bù"},
	{"care_due_classes", "Lớp đến hạn chăm sóc"},
	{"upcoming_test_classes", "Số lớp sắp lịch test"},
}

// ───────────── Báo cáo tháng / quý Học thuật ─────────────

var monthlyFields = []Option{
	{"test_syllabus_review", "Rà soát test & syllabus"},
	{"materials_transfers", "Chuẩn bị giáo trình & chuyển lớp"},
	{"overall", "Đánh giá chung tháng"},
	{"teacher_notes", "Nhận xét riêng theo từng giáo viên (tùy chọn)"},
}

// FieldGroup là một nhóm ô chữ có tiêu đề.
type FieldGroup struct {
	Title  string   `json:"title"`
	Fields []Option `json:"fields"`
}

var quarterlySections = []FieldGroup{
	{"Nội dung báo cáo", []Option{
		{"academic_work", "Công việc học thuật"},
		{"teacher_staffing", "Nhân sự giáo viên"},
		{"next_plan", "Kế hoạch công việc kỳ sau"},
	}},
	{"Đánh giá chung", []Option{
		{"program_progress", "Tiến độ chương trình"},
		{"implementation_quality", "Chất lượng triển khai"},
		{"improvement_priorities", "Ưu tiên cải thiện"},
	}},
}

func quarterlyFields() []Option {
	var out []Option
	for _, g := range quarterlySections {
		out = append(out, g.Fields...)
	}
	return out
}

// ───────────── Báo cáo tháng giáo viên ─────────────

var teacherGeneralFields = []Option{
	{"progress", "Tiến độ giảng dạy"},
	{"difficulties", "Khó khăn"},
	{"proposals", "Đề xuất"},
}

// Check là mục tích; Note khác rỗng thì có ô ghi chú (placeholder) đi kèm.
type Check struct {
	Key   string `json:"key"`
	Label string `json:"label"`
	Note  string `json:"note,omitempty"`
}

var teacherAcademicChecks = []Check{
	{"syllabus_provided", "Syllabus đã cung cấp", ""},
	{"syllabus_adjust", "Syllabus cần điều chỉnh", ""},
	{"actual_dates", "Đã điền ngày thực tế vào syllabus", ""},
	{"materials_printed", "Tài liệu in ấn đầy đủ", ""},
	{"materials_order", "Tài liệu cần order thêm", "Ghi chú loại tài liệu..."},
	{"academic_issue", "Tình hình báo cáo vấn đề học thuật", "Ghi chú vấn đề..."},
}

var teacherOperationChecks = []Check{
	{"ops_support", "Hỗ trợ từ bộ phận vận hành", "Ghi chú chi tiết..."},
	{"facilities", "Thiết bị/CSVC (order trước 17h)", "Ghi chú tình trạng thiết bị..."},
	{"punctuality", "Giờ giấc (đúng giờ 100%)", "Ghi chú lý do đi trễ (nếu có)..."},
	{"report_on_time", "Nộp report lớp đúng giờ", "Ghi chú..."},
	{"parent_contact", "Báo cáo/Tương tác phụ huynh", "Ghi chú..."},
}

var teacherQualityChecks = []Check{
	{"grading", "GV chấm chữa bài đầy đủ", "Ghi chú..."},
	{"voice_feedback", "GV nhận xét voice", "Ghi chú..."},
	{"test_held", "Có tổ chức mini/big test", "Tên bài test..."},
}

func teacherAllChecks() []Check {
	return slices.Concat(teacherAcademicChecks, teacherOperationChecks, teacherQualityChecks)
}

// teacherClassTexts: ô chữ của từng lớp (khóa giữ như bản 04/10 để Tổng quan đọc được).
var teacherClassTexts = []Option{
	{"foreign_feedback", "Phản hồi của giáo viên nước ngoài"},
	{"academic_note", "Ghi chú học thuật khác"},
	{"operations_note", "Ghi chú vận hành khác"},
	{"test_result", "Kết quả test (tỉ lệ đạt)"},
	{"attitude", "Thái độ học viên chung"},
	{"attention", "Học sinh cần lưu ý"},
	{"situation", "Vấn đề phát sinh trong lớp"},
	{"solution", "Giải pháp đã thực hiện"},
	{"support_note", "Đề xuất hỗ trợ từ quản lý"},
}

// Choice là lựa chọn hai mức của khối A / C.
type Choice struct {
	Key     string   `json:"key"`
	Label   string   `json:"label"`
	Options []Option `json:"options"`
}

var teacherChoices = []Choice{
	{"program_progress", "Tiến độ chương trình", []Option{{"on_track", "Đúng tiến độ"}, {"behind", "Chậm tiến độ"}}},
	{"attendance_level", "Chuyên cần học sinh", []Option{{"high", "95%-100%"}, {"low", "< 95%"}}},
	{"homework_level", "Tỉ lệ hoàn thành bài tập", []Option{{"high", "Cao (>80%)"}, {"low", "Thấp"}}},
}

func optionLabel(opts []Option, key string) string {
	for _, o := range opts {
		if o.Value == key {
			return o.Label
		}
	}
	return key
}

// ───────────── Nhật ký ─────────────

var journalStatuses = []Option{{"open", "Mới"}, {"following", "Đang theo dõi"}, {"resolved", "Đã xử lý"}}
var journalSeverities = []string{"normal", "important", "urgent"}
