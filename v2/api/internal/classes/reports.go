package classes

import (
	"cmp"
	"context"
	"fmt"
	"math"
	"slices"
	"strings"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/classes/classesdb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/reports"
)

// Phần việc phân hệ Lớp học làm cho báo cáo: danh mục lớp gắn vào nhật ký / báo cáo chữ (reports.ClassDirectory) và
// số liệu lớp / buổi học / điểm danh của báo cáo ngày, tuần, tháng (DailyReportMetrics, WeeklyReportMetrics,
// TeachingQuality) — bọc một MetricsProvider khác, chỉ điền các mục về lớp.

// ───────────────────────── Danh mục lớp ─────────────────────────

type ReportClasses struct{ cls *Service }

func (s *Service) ReportClasses() ReportClasses { return ReportClasses{cls: s} }

var _ reports.ClassDirectory = ReportClasses{}

// Visible: lớp (chưa xóa) trong phạm vi người dùng được xem.
func (d ReportClasses) Visible(ctx context.Context, actor *iam.Access, id uuid.UUID) (string, string, bool, error) {
	sc, err := d.cls.visible(ctx, actor)
	if err != nil || !sc.has(id) {
		return "", "", false, err
	}
	c, err := d.cls.q.GetClass(ctx, id)
	if isNoRows(err) {
		return "", "", false, nil
	}
	if err != nil {
		return "", "", false, err
	}
	return c.Code, c.Name, true, nil
}

// Options: lớp người dùng thấy, chưa hủy — "Mã · Tên".
func (d ReportClasses) Options(ctx context.Context, actor *iam.Access) ([]reports.Option, error) {
	sc, err := d.cls.visible(ctx, actor)
	if err != nil {
		return nil, err
	}
	rows, err := d.cls.q.ReportClassOptions(ctx, classesdb.ReportClassOptionsParams{AllClasses: sc.all, Ids: nonNil(sc.ids)})
	if err != nil {
		return nil, err
	}
	out := make([]reports.Option, 0, len(rows))
	for _, r := range rows {
		out = append(out, reports.Option{Value: r.ID.String(), Label: r.Code + " · " + r.Name})
	}
	return out, nil
}

// Codes: mã lớp theo id (kể cả lớp đã xóa, để nhật ký cũ vẫn hiện mã).
func (d ReportClasses) Codes(ctx context.Context, ids []uuid.UUID) (map[uuid.UUID]string, error) {
	out := map[uuid.UUID]string{}
	if len(ids) == 0 {
		return out, nil
	}
	rows, err := d.cls.q.ClassCodesByIDs(ctx, ids)
	if err != nil {
		return nil, err
	}
	for _, r := range rows {
		out[r.ID] = r.Code
	}
	return out, nil
}

// ───────────────────────── Số liệu báo cáo ─────────────────────────

// AccessLoader: quyền / phạm vi của người viết báo cáo (iam.Service.LoadAccess).
type AccessLoader func(ctx context.Context, userID uuid.UUID) (*iam.Access, error)

// ReportMetrics: MetricsProvider gốc + các mục lớp học. Mục nào phân hệ lớp học chưa có nguồn (chấm công giờ dạy,
// báo cáo trực lớp, nhật ký / bổ trợ trong "Tình hình lớp học", bài tập) thì bỏ phần chi tiết đó.
type ReportMetrics struct {
	reports.MetricsProvider
	cls    *Service
	access AccessLoader
}

func (s *Service) ReportMetrics(base reports.MetricsProvider, access AccessLoader) *ReportMetrics {
	return &ReportMetrics{MetricsProvider: base, cls: s, access: access}
}

var _ reports.Partial = (*ReportMetrics)(nil)

// Pending: lớp của giáo viên đã có nguồn; phần khác theo provider gốc.
func (m *ReportMetrics) Pending(part string) bool {
	if part == "classes" {
		return false
	}
	p, ok := m.MetricsProvider.(reports.Partial)
	return ok && p.Pending(part)
}

// classIDs: lớp tính vào báo cáo — lớp người viết thấy, chỉ trong các chi nhánh của người viết (BuildsReportItems::classIds).
func (m *ReportMetrics) classIDs(ctx context.Context, w reports.Writer) ([]uuid.UUID, error) {
	a, err := m.access(ctx, w.ID)
	if err != nil {
		return nil, err
	}
	sc, err := m.cls.visible(ctx, a)
	if err != nil {
		return nil, err
	}
	ids, err := m.cls.q.ReportClassIDs(ctx, classesdb.ReportClassIDsParams{AllClasses: sc.all, Ids: nonNil(sc.ids), BranchIds: nonNil(w.BranchIDs)})
	return nonNil(ids), err
}

func classLink(id uuid.UUID) string { return "/classes/" + id.String() }

// fill: thay số / chi tiết của mục key trong khối (giữ nhãn và cách đếm của provider gốc).
func fill(sec *reports.Section, key string, value *string, count *int, details []reports.Detail) {
	if details == nil {
		details = []reports.Detail{}
	}
	for i := range sec.Items {
		if sec.Items[i].Key == key {
			sec.Items[i].Value, sec.Items[i].Count, sec.Items[i].Details, sec.Items[i].Unavailable = value, count, details, ""
		}
	}
}

func num(n int) (*string, *int) {
	v := fmt.Sprint(n)
	return &v, &n
}

func sessionClock(r classesdb.ReportDaySessionsRow) string {
	if r.StartTime != "" && r.EndTime != "" {
		return r.StartTime + "–" + r.EndTime
	}
	if r.ShiftName != nil && *r.ShiftName != "" {
		return *r.ShiftName
	}
	return "Ca học"
}

// branchSplit: người viết nhiều chi nhánh → dòng số theo từng chi nhánh.
func (m *ReportMetrics) branchSplit(ctx context.Context, w reports.Writer, counts map[uuid.UUID]int, unit string) ([]reports.Detail, error) {
	if len(w.BranchIDs) < 2 {
		return nil, nil
	}
	var out []reports.Detail
	for _, b := range w.BranchIDs {
		name, err := m.cls.q.BranchName(ctx, b)
		if isNoRows(err) {
			continue
		}
		if err != nil {
			return nil, err
		}
		out = append(out, reports.Detail{Text: fmt.Sprintf("%s: %d %s", name, counts[b], unit), Tag: "Chi nhánh"})
	}
	return out, nil
}

// Daily: khối "Vận hành lớp học" của báo cáo ngày Học vụ; mục "Kết quả mini test" của báo cáo ngày Học thuật.
func (m *ReportMetrics) Daily(ctx context.Context, w reports.Writer, date time.Time, audience string) ([]reports.Section, map[string]any, error) {
	sections, fields, err := m.MetricsProvider.Daily(ctx, w, date, audience)
	if err != nil {
		return sections, fields, err
	}
	if audience == reports.AudienceAcademic {
		if err := m.fillMiniTests(ctx, sections, w, date, date); err != nil {
			return nil, nil, err
		}
		return sections, fields, nil
	}
	idx := slices.IndexFunc(sections, func(s reports.Section) bool { return s.Key == "operations" })
	if idx < 0 {
		return sections, fields, nil
	}
	ids, err := m.classIDs(ctx, w)
	if err != nil {
		return nil, nil, err
	}
	rows, err := m.cls.q.ReportDaySessions(ctx, classesdb.ReportDaySessionsParams{Day: date, ClassIds: ids})
	if err != nil {
		return nil, nil, err
	}
	planned, actual, branchOf := map[uuid.UUID]bool{}, map[uuid.UUID]bool{}, map[uuid.UUID]uuid.UUID{}
	teachers, assistants := map[uuid.UUID]bool{}, map[uuid.UUID]bool{}
	var list, teacherDetails []reports.Detail
	var running []uuid.UUID
	byID := map[uuid.UUID]classesdb.ReportDaySessionsRow{}
	for _, r := range rows {
		byID[r.ID] = r
		tag, tone := "Chưa điểm danh", "warning"
		switch r.Status {
		case "cancelled":
			tag, tone = "Nghỉ / hủy", "muted"
			if r.Holiday {
				tag = "Nghỉ lễ"
			}
		case "completed":
			tag, tone = "Đã dạy", "success"
		}
		text := r.ClassName + " · " + sessionClock(r)
		if room := strings.TrimSpace(deref(r.Room)); room != "" {
			if !strings.HasPrefix(strings.ToLower(room), "phòng") {
				room = "Phòng " + room
			}
			text += " · " + room
		}
		if r.Type == "makeup" {
			text += " · Học bù"
		}
		list = append(list, reports.Detail{Text: text, Tag: tag, Tone: tone, URL: classLink(r.ClassID)})
		if r.Status == "cancelled" {
			continue
		}
		running = append(running, r.ID)
		planned[r.ClassID], branchOf[r.ClassID] = true, r.ClassBranchID
		if r.Status == "completed" {
			actual[r.ClassID] = true
		}
		for _, t := range []*uuid.UUID{r.TeacherID, r.ForeignTeacherID} {
			if t != nil {
				teachers[*t] = true
			}
		}
		if r.AssistantID != nil {
			assistants[*r.AssistantID] = true
		}
		if r.TeacherID != nil && r.ClassTeacherID != nil && *r.TeacherID != *r.ClassTeacherID {
			teacherDetails = append(teacherDetails, reports.Detail{
				Text: cmp.Or(deref(r.TeacherName), "GV") + " dạy thay · " + r.ClassName + " · " + sessionClock(r), Tag: "Dạy thay"})
		}
	}
	perBranch := map[uuid.UUID]int{}
	for _, b := range branchOf {
		perBranch[b]++
	}
	split, err := m.branchSplit(ctx, w, perBranch, "lớp")
	if err != nil {
		return nil, nil, err
	}
	situation, err := m.situation(ctx, running, byID)
	if err != nil {
		return nil, nil, err
	}
	sec := &sections[idx]
	v, c := num(len(planned))
	fill(sec, "classes_planned", v, c, split)
	v, c = num(len(actual))
	fill(sec, "classes_actual", v, c, nil)
	fill(sec, "classes_list", nil, nil, list)
	v, c = num(len(teachers))
	fill(sec, "teachers", v, c, teacherDetails)
	v, c = num(len(assistants))
	fill(sec, "assistants", v, c, nil)
	fill(sec, "class_situation", nil, nil, situation)
	if fields == nil {
		fields = map[string]any{}
	}
	fields["classes_planned"], fields["classes_actual"] = len(planned), len(actual)
	fields["teachers"], fields["assistants"] = len(teachers), len(assistants)
	return sections, fields, nil
}

// situation: học sinh vắng / vắng có phép / đi muộn theo điểm danh, mỗi buổi một dòng.
func (m *ReportMetrics) situation(ctx context.Context, sessions []uuid.UUID, byID map[uuid.UUID]classesdb.ReportDaySessionsRow) ([]reports.Detail, error) {
	if len(sessions) == 0 {
		return nil, nil
	}
	rows, err := m.cls.q.SessionAttendanceIssues(ctx, sessions)
	if err != nil {
		return nil, err
	}
	names := map[uuid.UUID]map[string][]string{}
	for _, r := range rows {
		if names[r.SessionID] == nil {
			names[r.SessionID] = map[string][]string{}
		}
		names[r.SessionID][r.Status] = append(names[r.SessionID][r.Status], r.StudentName)
	}
	var out []reports.Detail
	for _, id := range sessions {
		got, ok := names[id]
		if !ok {
			continue
		}
		var parts []string
		for _, st := range []struct{ key, label string }{{"absent", "vắng"}, {"excused", "vắng có phép"}, {"late", "đi muộn"}} {
			if list := got[st.key]; len(list) > 0 {
				parts = append(parts, fmt.Sprintf("%s %d (%s)", st.label, len(list), strings.Join(list, ", ")))
			}
		}
		s := byID[id]
		out = append(out, reports.Detail{Text: s.ClassName + ": " + strings.Join(parts, "; "), Tag: "Điểm danh", URL: classLink(s.ClassID)})
	}
	return out, nil
}

// WeeklyScale: lớp đang chạy, học sinh, GV phụ trách, chuyển lớp, học bù của báo cáo tuần Học vụ.
func (m *ReportMetrics) WeeklyScale(ctx context.Context, w reports.Writer, from, to time.Time) (reports.Section, error) {
	sec, err := m.MetricsProvider.WeeklyScale(ctx, w, from, to)
	if err != nil {
		return sec, err
	}
	ids, err := m.classIDs(ctx, w)
	if err != nil {
		return sec, err
	}
	rows, err := m.cls.q.ReportRangeSessions(ctx, classesdb.ReportRangeSessionsParams{FromDate: from, ToDate: to, ClassIds: ids})
	if err != nil {
		return sec, err
	}
	running := map[uuid.UUID]string{}
	teacherSet := map[uuid.UUID]bool{}
	for _, r := range rows {
		running[r.ClassID] = r.ClassName
		for _, t := range []*uuid.UUID{r.TeacherID, r.ForeignTeacherID} {
			if t != nil {
				teacherSet[*t] = true
			}
		}
	}
	runIDs := make([]uuid.UUID, 0, len(running))
	var classDetails []reports.Detail
	for id, name := range running {
		runIDs = append(runIDs, id)
		classDetails = append(classDetails, reports.Detail{Text: name, URL: classLink(id)})
	}
	slices.SortFunc(classDetails, func(a, b reports.Detail) int { return strings.Compare(a.Text, b.Text) })
	students, err := m.cls.q.ActiveStudentsAsOf(ctx, classesdb.ActiveStudentsAsOfParams{ClassIds: runIDs, AsOf: to})
	if err != nil {
		return sec, err
	}
	teacherIDs := make([]uuid.UUID, 0, len(teacherSet))
	for id := range teacherSet {
		teacherIDs = append(teacherIDs, id)
	}
	names, err := m.cls.q.UserNames(ctx, teacherIDs)
	if err != nil {
		return sec, err
	}
	slices.SortFunc(names, func(a, b classesdb.UserNamesRow) int { return strings.Compare(a.FullName, b.FullName) })
	teacherDetails := make([]reports.Detail, 0, len(names))
	for _, n := range names {
		teacherDetails = append(teacherDetails, reports.Detail{Text: n.FullName})
	}
	transfers, err := m.cls.q.ClassTransfers(ctx, classesdb.ClassTransfersParams{FromDate: from, ToDate: to, ClassIds: ids})
	if err != nil {
		return sec, err
	}
	transferDetails := make([]reports.Detail, 0, len(transfers))
	for _, t := range transfers {
		transferDetails = append(transferDetails, reports.Detail{Text: t.StudentName + ": " + t.FromClass + " → " + t.ToClass})
	}
	makeups, err := m.cls.q.MakeupAttendees(ctx, classesdb.MakeupAttendeesParams{FromDate: from, ToDate: to, ClassIds: ids})
	if err != nil {
		return sec, err
	}
	makeupDetails := make([]reports.Detail, 0, len(makeups))
	for _, r := range makeups {
		makeupDetails = append(makeupDetails, reports.Detail{Text: r.StudentName + " · " + r.ClassName})
	}
	v, c := num(len(running))
	fill(&sec, "classes_running", v, c, classDetails)
	v, c = num(int(students))
	fill(&sec, "students", v, c, nil)
	v, c = num(len(names))
	fill(&sec, "teachers", v, c, teacherDetails)
	v, c = num(len(transferDetails))
	fill(&sec, "transfers", v, c, transferDetails)
	v, c = num(len(makeupDetails))
	fill(&sec, "makeups", v, c, makeupDetails)
	care, err := m.careDue(ctx, ids, from, to)
	if err != nil {
		return sec, err
	}
	v, c = num(len(care))
	fill(&sec, "care_due_classes", v, c, care)
	return sec, nil
}

// careDue: lớp có học sinh đến mốc chăm sóc tháng đầu (việc chăm sóc còn mở có hạn tới cuối tuần), 3 tên đầu + số còn lại;
// lớp có việc quá hạn trước tuần tô cảnh báo.
func (m *ReportMetrics) careDue(ctx context.Context, ids []uuid.UUID, from, to time.Time) ([]reports.Detail, error) {
	if len(ids) == 0 {
		return []reports.Detail{}, nil
	}
	rows, err := m.cls.q.ReportCareDue(ctx, classesdb.ReportCareDueParams{ToDate: to, ClassIds: ids})
	if err != nil {
		return nil, err
	}
	out := []reports.Detail{}
	fromDay := dateOf(from)
	for i := 0; i < len(rows); {
		j, names, late := i, []string{}, false
		for ; j < len(rows) && rows[j].ClassID == rows[i].ClassID; j++ {
			if !slices.Contains(names, rows[j].Name) {
				names = append(names, rows[j].Name)
			}
			late = late || (rows[j].DueDate != nil && rows[j].DueDate.Before(fromDay))
		}
		text := strings.Join(names[:min(3, len(names))], ", ")
		if len(names) > 3 {
			text += fmt.Sprintf(" +%d", len(names)-3)
		}
		d := reports.Detail{Text: rows[i].ClassName + ": " + text, URL: classLink(rows[i].ClassID)}
		if late {
			d.Tone = "warning"
		}
		out = append(out, d)
		i = j
	}
	return out, nil
}

// TeacherClasses: lớp giáo viên giữ trong tháng, chuyên cần (%) của tháng và số buổi đã đứng lớp (tới hôm nay).
// Tỷ lệ bài tập do Cổng giáo viên điền (teacher.ReportMetrics bọc ngoài).
func (m *ReportMetrics) TeacherClasses(ctx context.Context, w reports.Writer, month string) ([]reports.TeacherClass, error) {
	from, err := time.Parse("2006-01", month)
	if err != nil {
		return nil, iam.Invalid("Tháng không hợp lệ.")
	}
	to := from.AddDate(0, 1, -1)
	until := to
	if today := m.cls.today(); today.Before(until) {
		until = today
	}
	rows, err := m.cls.q.TeacherMonthClasses(ctx, classesdb.TeacherMonthClassesParams{FromDate: from, ToDate: to, Until: until, UserID: &w.ID})
	if err != nil {
		return nil, err
	}
	out := make([]reports.TeacherClass, 0, len(rows))
	for _, r := range rows {
		c := reports.TeacherClass{ID: r.ID, Name: r.Name, SessionsDone: int(r.SessionsDone)}
		if r.AttendanceTotal > 0 {
			v := math.Round(float64(r.Attended)/float64(r.AttendanceTotal)*1000) / 10
			c.Attendance = &v
		}
		out = append(out, c)
	}
	return out, nil
}
