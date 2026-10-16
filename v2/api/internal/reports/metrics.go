package reports

import (
	"context"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

// Số liệu tự động của báo cáo (Laravel DailyReportMetrics / WeeklyReportMetrics / TeachingQuality) lấy từ phân hệ khác:
// lớp học, buổi học, điểm danh, CRM, phiếu thu, ticket, order học liệu, dự án học thuật, giáo trình, KPI…
// Các phân hệ đó đang chuyển song song nên module báo cáo chỉ đọc qua interface dưới đây. Bản mặc định (skeletonMetrics)
// trả đúng khung mục / nhãn / cách tính như Laravel nhưng chưa có số (Item.Unavailable ghi lý do).
// TODO(gộp): cài MetricsProvider thật khi các phân hệ lớp học, CRM, học phí, ticket, order, dự án, giáo trình, KPI có ở v2.

// Writer là người viết báo cáo (phạm vi số liệu tính theo người này).
type Writer struct {
	ID        uuid.UUID
	Name      string
	Roles     []string
	BranchIDs []uuid.UUID
	CreatedAt time.Time
}

// Detail là một dòng chi tiết của mục số liệu (có thể kèm nhãn, mức màu, link).
type Detail struct {
	Text string `json:"text"`
	Tag  string `json:"tag,omitempty"`
	Tone string `json:"tone,omitempty"` // success | warning | error | muted | strong
	URL  string `json:"url,omitempty"`
}

// Item là một mục số liệu chỉ đọc: Value hiển thị, Count dùng cho ô tổng hợp, Info là cách đếm (icon "i").
type Item struct {
	Key         string   `json:"key"`
	Label       string   `json:"label"`
	Value       *string  `json:"value"`
	Count       *int     `json:"count"`
	Info        string   `json:"info"`
	Details     []Detail `json:"details"`
	Unavailable string   `json:"unavailable,omitempty"` // chưa có nguồn số liệu ở v2
}

// Section là một khối số liệu của báo cáo (Vận hành lớp học, Tuyển sinh…).
type Section struct {
	Key   string `json:"key"`
	Title string `json:"title"`
	Icon  string `json:"icon"`
	Items []Item `json:"items"`
	Empty string `json:"empty,omitempty"`
}

// KpiCriterion là mục KPI đang áp dụng cho một vai trò (KpiCriterion của Laravel). Auto = hệ thống tự đếm (KpiAutoCounter);
// không thì đếm sự vụ trong Nhật ký Học vụ có gắn mã KPI.
type KpiCriterion struct {
	ID         string
	Code       string
	Name       string
	Group      string
	Unit       string
	Auto       bool
	AutoSource string
	AutoLabel  string
}

// KpiItem / KpiGroup: bảng số lần phát sinh theo mục KPI của báo cáo tuần Học vụ.
type KpiItem struct {
	ID      string   `json:"id"`
	Code    string   `json:"code"`
	Name    string   `json:"name"`
	Unit    string   `json:"unit"`
	Source  string   `json:"source"` // auto | journal
	Count   int      `json:"count"`
	Info    string   `json:"info"`
	Details []Detail `json:"details"`
}

type KpiGroup struct {
	Name  string    `json:"name"`
	Items []KpiItem `json:"items"`
}

// WeeklyRow là một dòng của 3 bảng báo cáo tuần Học thuật. Source: mã nguồn dòng điền sẵn từ hệ thống (milestone:12…).
type WeeklyRow struct {
	Content  *string `json:"content"`
	Detail   *string `json:"detail"`
	Deadline *string `json:"deadline"`
	Progress *int    `json:"progress"`
	Link     *string `json:"link"`
	Report   *string `json:"report"`
	Support  *string `json:"support"`
	Note     *string `json:"note"`
	Source   *string `json:"source"`
}

// TeacherClass là lớp giáo viên giữ trong tháng (TeachingQuality::classesOf + classMetrics): chuyên cần / bài tập (%) để gợi ý.
type TeacherClass struct {
	ID           uuid.UUID `json:"id"`
	Name         string    `json:"name"`
	Attendance   *float64  `json:"attendance"`
	Homework     *float64  `json:"homework"`
	SessionsDone int       `json:"sessions_done"`
}

// Meeting là buổi họp giáo viên (class_quality) hiện trong báo cáo tháng Học thuật.
type Meeting struct {
	ID          string `json:"id"`
	WeekStart   string `json:"week_start"`
	Teacher     string `json:"teacher"`
	StatusLabel string `json:"status_label"`
	StatusColor string `json:"status_color"`
}

// MetricsProvider đọc số liệu từ phân hệ khác cho báo cáo.
type MetricsProvider interface {
	// Daily: các khối số liệu của báo cáo ngày theo chức danh (Học vụ / Học thuật) và các số chính theo khóa cũ (fields).
	Daily(ctx context.Context, w Writer, date time.Time, audience string) ([]Section, map[string]any, error)
	// WeeklyScale: 9 chỉ số quy mô của báo cáo tuần Học vụ.
	WeeklyScale(ctx context.Context, w Writer, from, to time.Time) (Section, error)
	// WeeklyAcademic: số liệu tuần + các dòng gợi ý điền sẵn vào 3 bảng báo cáo tuần Học thuật.
	WeeklyAcademic(ctx context.Context, w Writer, from, to time.Time) (Section, map[string][]WeeklyRow, error)
	// KpiCriteria: mục KPI đang áp dụng của vai trò (bảng KPI báo cáo tuần, mã KPI gắn vào nhật ký).
	KpiCriteria(ctx context.Context, role string) ([]KpiCriterion, error)
	// KpiEvidence: số lần + bản ghi đã đếm của mục KPI tự động trong khoảng.
	KpiEvidence(ctx context.Context, c KpiCriterion, w Writer, from, to time.Time) (int, []Detail, error)
	// TeacherClasses: lớp giáo viên giữ trong tháng kèm chuyên cần / bài tập / số buổi đã dạy.
	TeacherClasses(ctx context.Context, w Writer, month string) ([]TeacherClass, error)
	// TeacherMeetings: buổi họp giáo viên người viết đã ghi trong khoảng.
	TeacherMeetings(ctx context.Context, author uuid.UUID, from, to time.Time) ([]Meeting, error)
}

// TaskSummary là việc follow-up đã tạo từ báo cáo ngày.
type TaskSummary struct {
	ID          uuid.UUID `json:"id"`
	Title       string    `json:"title"`
	DueDate     *string   `json:"due_date"`
	Assignee    *string   `json:"assignee"`
	Status      string    `json:"status"`
	StatusLabel string    `json:"status_label"`
}

// FollowUpTask là việc tạo từ báo cáo ngày (WorkTask: hạn 18:00, loại một lần).
type FollowUpTask struct {
	Title       string
	Description string
	AssigneeID  uuid.UUID
	DueDate     time.Time
	BranchID    *uuid.UUID
}

// TaskService là phân hệ Công việc (ops.work_tasks) — đang làm song song.
// TODO(gộp): nối với module tasks (tạo việc, người được giao việc, tóm tắt việc).
type TaskService interface {
	Enabled() bool
	// Assignees: người mình giao việc được (ngoài chính mình), như màn Giao việc.
	Assignees(ctx context.Context, actor *iam.Access) ([]Option, error)
	Create(ctx context.Context, actor *iam.Access, t FollowUpTask) (uuid.UUID, error)
	Summaries(ctx context.Context, ids []uuid.UUID) ([]TaskSummary, error)
}

// ClassDirectory là phân hệ Lớp học (academic.classes) — lớp gắn được vào nhật ký / báo cáo chữ.
// TODO(gộp): nối với module classes (ClassModel::visibleTo).
type ClassDirectory interface {
	// Visible: lớp trong phạm vi người dùng được xem; trả mã + tên.
	Visible(ctx context.Context, actor *iam.Access, id uuid.UUID) (code, name string, ok bool, err error)
	// Options: lớp đang mở người dùng thấy (ô "Lớp liên quan").
	Options(ctx context.Context, actor *iam.Access) ([]Option, error)
	// Names: mã lớp theo id (hiện ở nhật ký).
	Codes(ctx context.Context, ids []uuid.UUID) (map[uuid.UUID]string, error)
}

// Calendar trả các ngày không phải nộp báo cáo ngày ngoài Chủ nhật: ngày lễ (toàn hệ thống / chi nhánh) và ngày nghỉ phép
// đã duyệt của người viết (DailyReportDue::requiredDays).
// TODO(gộp): nối core.holidays và đơn nghỉ phép (hr) khi có ở v2.
type Calendar interface {
	OffDays(ctx context.Context, userID uuid.UUID, branchID *uuid.UUID, from, to time.Time) (map[string]bool, error)
}

// ───────────── Bản mặc định khi chưa có phân hệ nguồn ─────────────

type skeletonMetrics struct{}

const (
	srcClasses  = "Chờ phân hệ Lớp học / buổi học chuyển sang hệ thống mới."
	srcCRM      = "Chờ phân hệ CRM chuyển sang hệ thống mới."
	srcTuition  = "Chờ phân hệ Học phí chuyển sang hệ thống mới."
	srcTests    = "Chờ phân hệ Test đầu vào chuyển sang hệ thống mới."
	srcOps      = "Chờ phân hệ Ticket / Order học liệu chuyển sang hệ thống mới."
	srcProjects = "Chờ phân hệ Dự án học thuật chuyển sang hệ thống mới."
	srcSyllabus = "Chờ phân hệ Giáo trình / Big Test chuyển sang hệ thống mới."
	srcQuality  = "Chờ phân hệ Chất lượng lớp / mini test chuyển sang hệ thống mới."
	srcTasks    = "Chờ phân hệ Công việc chuyển sang hệ thống mới."
)

func pending(key, label, info, src string) Item {
	return Item{Key: key, Label: label, Info: info, Details: []Detail{}, Unavailable: src}
}

func (skeletonMetrics) Daily(_ context.Context, _ Writer, _ time.Time, audience string) ([]Section, map[string]any, error) {
	if audience == AudienceAcademic {
		return []Section{
			{Key: "projects", Title: "Dự án học thuật", Icon: "rocket_launch", Items: []Item{
				pending("milestones_due", "Mốc đến hạn hôm nay", "Mốc của dự án học thuật bạn được xem có hạn là ngày báo cáo, chưa hoàn thành.", srcProjects),
				pending("milestones_overdue", "Mốc quá hạn", "Mốc đã qua hạn mà chưa hoàn thành.", srcProjects),
				pending("milestones_done", "Mốc hoàn thành trong ngày", "Mốc được đánh dấu hoàn thành trong ngày.", srcProjects),
			}},
			{Key: "materials", Title: "Học liệu & đề test", Icon: "inventory_2", Items: []Item{
				pending("academic_orders", "Order học liệu", "Order \"Học liệu học thuật\" tạo hoặc xử lý trong ngày, và order còn mở đã quá hạn.", srcOps),
				pending("big_test_orders", "Order đề test chờ duyệt", "Order đề Mini / Big Test đang chờ duyệt.", srcSyllabus),
			}},
			{Key: "syllabus", Title: "Giáo trình", Icon: "menu_book", Items: []Item{
				pending("syllabus_proposals", "Đề xuất sửa giáo trình", "Đề xuất sửa giáo trình đang chờ duyệt (kèm đề xuất mới gửi trong ngày).", srcSyllabus),
				pending("syllabus_adjustments", "Yêu cầu điều chỉnh syllabus", "Yêu cầu giãn / điều chỉnh syllabus của lớp đang chờ duyệt (kèm yêu cầu mới trong ngày).", srcSyllabus),
			}},
			{Key: "quality", Title: "Chất lượng giảng dạy", Icon: "fact_check", Items: []Item{
				pending("observations", "Buổi dự giờ", "Phiếu dự giờ có ngày dự là ngày báo cáo.", srcQuality),
				pending("mini_tests", "Kết quả mini test", "Điểm mini test có ngày test là ngày báo cáo. \"Dưới 7\" quy về thang 10.", srcQuality),
			}},
		}, map[string]any{}, nil
	}
	return []Section{
		{Key: "operations", Title: "Vận hành lớp học", Icon: "school", Items: []Item{
			pending("classes_planned", "Số lớp kế hoạch", "Số lớp có buổi học trong ngày theo lịch (gồm buổi học bù), không tính buổi đã hủy / nghỉ. Chỉ tính lớp thuộc chi nhánh của bạn.", srcClasses),
			pending("classes_actual", "Số lớp thực tế", "Số lớp có buổi đã được giáo viên điểm danh trong ngày.", srcClasses),
			pending("classes_list", "Danh sách lớp trong ngày", "Buổi học theo lịch: đã dạy (đã điểm danh), chưa điểm danh, nghỉ / hủy, học bù.", srcClasses),
			pending("teachers", "Số giáo viên", "Số giáo viên (gồm GVNN) đứng lớp trong ngày theo lịch buổi học.", srcClasses),
			pending("assistants", "Số trợ giảng", "Số trợ giảng trực lớp trong ngày theo lịch buổi học.", srcClasses),
			pending("class_situation", "Tình hình lớp học", "Học sinh vắng / đi muộn (theo điểm danh), sự vụ trong Nhật ký Học vụ và học sinh mới vào danh sách bổ trợ trong ngày.", srcClasses),
			pending("facilities", "Cơ sở vật chất", "Ticket hỗ trợ loại \"Cơ sở vật chất\" đang mở trong ngày, order đạo cụ / in ấn cần dùng hôm nay hoặc đang quá hạn.", srcOps),
		}},
		{Key: "enrollment", Title: "Tuyển sinh / Học viên", Icon: "group_add", Items: []Item{
			pending("support_students", "Số học sinh bổ trợ", "Buổi bổ trợ trong ngày: đã học / đã xếp (không tính buổi hủy).", srcClasses),
			pending("new_data", "Data mới nhận", "Khách mới tạo trong CRM trong ngày. \"Đã liên hệ\" là khách đã có lần gọi / nhắn / gặp.", srcCRM),
			pending("test_students", "Số học sinh test", "Bài test đầu vào làm trong ngày trên số khách có lịch hẹn test trong ngày.", srcTests),
			pending("fees", "Số lượng thu phí", "Phiếu thu có ngày thu là ngày báo cáo, đang chờ duyệt hoặc đã duyệt (không tính nháp, trả về, đã hủy).", srcTuition),
		}},
		{Key: "media", Title: "Truyền thông", Icon: "campaign", Items: []Item{},
			Empty: "Hệ thống chưa ghi nhận bài đăng truyền thông. Ghi bài đăng (kèm link) vào ô ghi chú."},
	}, map[string]any{}, nil
}

func (skeletonMetrics) WeeklyScale(context.Context, Writer, time.Time, time.Time) (Section, error) {
	return Section{Key: "metrics", Title: "Chỉ số quy mô", Icon: "monitoring", Items: []Item{
		pending("classes_running", "Số lớp đang chạy", "Số lớp có ít nhất một buổi học trong tuần (không tính buổi hủy / nghỉ và buổi bổ trợ).", srcClasses),
		pending("students", "Số học sinh", "Học sinh đang học (chưa rời lớp tính đến cuối tuần) của các lớp đang chạy.", srcClasses),
		pending("teachers", "Số GV phụ trách", "Số giáo viên (gồm GVNN) đứng lớp trong tuần theo lịch buổi học.", srcClasses),
		pending("new_leads", "Data mới nhận", "Khách mới tạo trong CRM trong tuần, chia theo nguồn (bằng tổng \"Data mới nhận\" các báo cáo ngày).", srcCRM),
		pending("new_closed", "HV mới chốt", "Khách CRM chuyển sang \"Chờ xếp lớp\" hoặc \"Đã chốt\" trong tuần.", srcCRM),
		pending("transfers", "HV chuyển lớp", "Học sinh rời một lớp và vào lớp khác trong tuần (suy ra từ ghi danh, hệ thống chưa có bản ghi \"chuyển lớp\" riêng).", srcClasses),
		pending("makeups", "HV học bù", "Học sinh có mặt ở buổi học bù trong tuần (theo điểm danh).", srcClasses),
		pending("care_due_classes", "Lớp đến hạn chăm sóc", "Lớp có học sinh đến mốc chăm sóc tháng đầu trong tuần (việc chăm sóc đến hạn hoặc quá hạn, chưa hoàn thành).", srcTasks),
		pending("upcoming_test_classes", "Số lớp sắp lịch test", "Lớp có Big Test lên lịch trong tuần này và tuần kế tiếp.", srcSyllabus),
	}}, nil
}

func (skeletonMetrics) WeeklyAcademic(context.Context, Writer, time.Time, time.Time) (Section, map[string][]WeeklyRow, error) {
	return Section{Key: "summary", Title: "Số liệu tuần", Icon: "monitoring", Items: []Item{
		pending("milestones_due", "Mốc đến hạn trong tuần", "Mốc dự án học thuật có hạn trong tuần (mọi trạng thái).", srcProjects),
		pending("milestones_done", "Mốc hoàn thành", "Mốc được đánh dấu hoàn thành trong tuần.", srcProjects),
		pending("milestones_overdue", "Mốc quá hạn", "Mốc đã qua hạn mà chưa hoàn thành (tính đến hôm nay, hoặc cuối tuần với tuần đã qua).", srcProjects),
		pending("academic_orders", "Order học liệu", "Order \"Học liệu học thuật\" tạo trong tuần.", srcOps),
		pending("observations", "Buổi dự giờ", "Phiếu dự giờ có ngày dự trong tuần.", srcQuality),
		pending("mini_tests", "Kết quả mini test", "Điểm mini test có ngày test trong tuần. \"Dưới 7\" quy về thang 10.", srcQuality),
	}}, map[string][]WeeklyRow{}, nil
}

func (skeletonMetrics) KpiCriteria(context.Context, string) ([]KpiCriterion, error) { return nil, nil }

func (skeletonMetrics) KpiEvidence(context.Context, KpiCriterion, Writer, time.Time, time.Time) (int, []Detail, error) {
	return 0, nil, nil
}

func (skeletonMetrics) TeacherClasses(context.Context, Writer, string) ([]TeacherClass, error) {
	return nil, nil
}

func (skeletonMetrics) TeacherMeetings(context.Context, uuid.UUID, time.Time, time.Time) ([]Meeting, error) {
	return nil, nil
}

type noTasks struct{}

func (noTasks) Enabled() bool { return false }
func (noTasks) Assignees(context.Context, *iam.Access) ([]Option, error) {
	return nil, nil
}
func (noTasks) Create(context.Context, *iam.Access, FollowUpTask) (uuid.UUID, error) {
	return uuid.Nil, &iam.Error{Status: 409, Code: "tasks_unavailable", Message: "Chưa tạo được task: phân hệ Công việc chưa chuyển sang hệ thống mới."}
}
func (noTasks) Summaries(context.Context, []uuid.UUID) ([]TaskSummary, error) { return nil, nil }

type noClasses struct{}

func (noClasses) Visible(context.Context, *iam.Access, uuid.UUID) (string, string, bool, error) {
	return "", "", false, nil
}
func (noClasses) Options(context.Context, *iam.Access) ([]Option, error) { return nil, nil }
func (noClasses) Codes(context.Context, []uuid.UUID) (map[uuid.UUID]string, error) {
	return map[uuid.UUID]string{}, nil
}

type noHolidays struct{}

func (noHolidays) OffDays(context.Context, uuid.UUID, *uuid.UUID, time.Time, time.Time) (map[string]bool, error) {
	return map[string]bool{}, nil
}
