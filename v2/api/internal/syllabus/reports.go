package syllabus

import (
	"context"
	"fmt"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/reports"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/syllabus/syllabusdb"
)

// Phần việc Giáo trình & Big Test làm cho báo cáo (DailyReportMetrics::academic, WeeklyReportMetrics::scale /
// academicSuggestions): khối "Học liệu & đề test" (order đề chờ duyệt) và "Giáo trình" (đề xuất sửa giáo trình, yêu cầu
// điều chỉnh tiến độ) của báo cáo ngày Học thuật, chỉ số "Số lớp sắp lịch test" của báo cáo tuần Học vụ và các dòng gợi ý
// order đề / đề xuất / điều chỉnh của báo cáo tuần Học thuật. Bọc một MetricsProvider khác, chỉ điền các mục này.

// AccessLoader nạp quyền của người viết báo cáo (iam.Service.LoadAccess).
type AccessLoader func(ctx context.Context, userID uuid.UUID) (*iam.Access, error)

type ReportMetrics struct {
	reports.MetricsProvider
	svc    *Service
	access AccessLoader
}

func (s *Service) ReportMetrics(base reports.MetricsProvider, access AccessLoader) *ReportMetrics {
	return &ReportMetrics{MetricsProvider: base, svc: s, access: access}
}

var _ reports.Partial = (*ReportMetrics)(nil)

// Pending: phân hệ này không thêm phần nào của báo cáo tháng; theo provider gốc.
func (m *ReportMetrics) Pending(part string) bool {
	p, ok := m.MetricsProvider.(reports.Partial)
	return ok && p.Pending(part)
}

// noLimit: mốc "gửi trước" của báo cáo tuần (không lọc theo ngày gửi).
var noLimit = time.Date(9999, 1, 1, 0, 0, 0, 0, time.UTC)

func dayBounds(from, to time.Time) (time.Time, time.Time) {
	return startOfDayVN(dateOf(from)), startOfDayVN(dateOf(to)).AddDate(0, 0, 1).Add(-time.Nanosecond)
}

// strLimit: Str::limit (cắt n ký tự, bỏ khoảng trắng cuối, thêm "...").
func strLimit(s string, n int) string {
	if utf8.RuneCountInString(s) <= n {
		return s
	}
	return strings.TrimRight(string([]rune(s)[:n]), " ") + "..."
}

// classIDs: lớp người viết thấy, lọc theo chi nhánh của người viết (BuildsReportItems::classIds).
func (m *ReportMetrics) classIDs(ctx context.Context, a *iam.Access, w reports.Writer) ([]uuid.UUID, error) {
	ids, err := m.svc.visibleClassIDs(ctx, a)
	if err != nil || len(ids) == 0 {
		return []uuid.UUID{}, err
	}
	branches := w.BranchIDs
	if branches == nil {
		branches = []uuid.UUID{}
	}
	out, err := m.svc.q.ReportClassIDs(ctx, syllabusdb.ReportClassIDsParams{Ids: ids, BranchIds: branches})
	if out == nil {
		out = []uuid.UUID{}
	}
	return out, err
}

func (m *ReportMetrics) orders(ctx context.Context, a *iam.Access, createdBefore time.Time, reviewed bool, from, to time.Time) ([]syllabusdb.ReportOrdersRow, error) {
	sc := scopeOf(a)
	return m.svc.q.ReportOrders(ctx, syllabusdb.ReportOrdersParams{AllRows: sc.all, UserID: &sc.userID, BranchIds: sc.branches,
		CreatedBefore: createdBefore, WithReviewed: reviewed, FromAt: from, ToAt: to})
}

func (m *ReportMetrics) proposals(ctx context.Context, a *iam.Access, createdBefore time.Time, byReview bool, from, to time.Time) ([]syllabusdb.ReportProposalsRow, error) {
	return m.svc.q.ReportProposals(ctx, syllabusdb.ReportProposalsParams{AllRows: a.Can("syllabus.approve_adjustment"), UserID: a.UserID,
		CreatedBefore: createdBefore, ByReview: byReview, FromAt: from, ToAt: to})
}

func (m *ReportMetrics) pacing(ctx context.Context, a *iam.Access, w reports.Writer, createdBefore time.Time, byReview bool, from, to time.Time) ([]syllabusdb.ReportPacingRow, error) {
	ids, err := m.classIDs(ctx, a, w)
	if err != nil || len(ids) == 0 {
		return nil, err
	}
	return m.svc.q.ReportPacing(ctx, syllabusdb.ReportPacingParams{ClassIds: ids, CreatedBefore: createdBefore, ByReview: byReview,
		FromAt: from, ToAt: to})
}

func setItem(sections []reports.Section, key string, n int, details []reports.Detail) {
	if details == nil {
		details = []reports.Detail{}
	}
	for si := range sections {
		for ii := range sections[si].Items {
			if it := &sections[si].Items[ii]; it.Key == key {
				v := fmt.Sprint(n)
				it.Value, it.Count, it.Details, it.Unavailable = &v, &n, details, ""
			}
		}
	}
}

func (m *ReportMetrics) Daily(ctx context.Context, w reports.Writer, date time.Time, audience string) ([]reports.Section, map[string]any, error) {
	sections, fields, err := m.MetricsProvider.Daily(ctx, w, date, audience)
	if err != nil || audience != reports.AudienceAcademic {
		return sections, fields, err
	}
	a, err := m.access(ctx, w.ID)
	if err != nil {
		return nil, nil, err
	}
	start, end := dayBounds(date, date)
	day := dateOf(date)

	orders, err := m.orders(ctx, a, end, false, start, end)
	if err != nil {
		return nil, nil, err
	}
	od := make([]reports.Detail, 0, len(orders))
	for _, o := range orders {
		t := o.ClassName + " · " + orderTypeLabel(o.TestType)
		if o.Code != "" {
			t = o.Code + " · " + t
		}
		d := reports.Detail{Text: t}
		if o.DueDate != nil {
			d.Text += " · hạn " + o.DueDate.Format("02/01")
			if !o.DueDate.After(day) {
				d.Tone = "warning"
			}
		}
		od = append(od, d)
	}
	setItem(sections, "big_test_orders", len(orders), od)

	props, err := m.proposals(ctx, a, end, false, start, end)
	if err != nil {
		return nil, nil, err
	}
	pd, pending := make([]reports.Detail, 0, len(props)), 0
	for _, p := range props {
		t := p.CurriculumTitle + " · " + strLimit(deref(p.Reason), 100)
		if p.ProposerName != nil {
			t += " — " + *p.ProposerName
		}
		pd = append(pd, reports.Detail{Text: t, Tag: statusLabels[p.Status]})
		if p.Status == "pending" {
			pending++
		}
	}
	setItem(sections, "syllabus_proposals", pending, pd)

	reqs, err := m.pacing(ctx, a, w, end, false, start, end)
	if err != nil {
		return nil, nil, err
	}
	rd, pending := make([]reports.Detail, 0, len(reqs)), 0
	for _, r := range reqs {
		t := r.ClassName + " · " + strLimit(r.Reason, 100)
		if r.TeacherName != nil {
			t += " — " + *r.TeacherName
		}
		rd = append(rd, reports.Detail{Text: t, Tag: statusLabels[r.Status]})
		if r.Status == "pending" {
			pending++
		}
	}
	setItem(sections, "syllabus_adjustments", pending, rd)
	return sections, fields, nil
}

// WeeklyScale: "Số lớp sắp lịch test" — lớp có Big Test lên lịch trong tuần này và tuần kế tiếp.
func (m *ReportMetrics) WeeklyScale(ctx context.Context, w reports.Writer, from, to time.Time) (reports.Section, error) {
	sec, err := m.MetricsProvider.WeeklyScale(ctx, w, from, to)
	if err != nil {
		return sec, err
	}
	a, err := m.access(ctx, w.ID)
	if err != nil {
		return sec, err
	}
	ids, err := m.classIDs(ctx, a, w)
	if err != nil {
		return sec, err
	}
	start, end := dayBounds(from, to)
	var tests []syllabusdb.ReportUpcomingBigTestsRow
	if len(ids) > 0 {
		if tests, err = m.svc.q.ReportUpcomingBigTests(ctx, syllabusdb.ReportUpcomingBigTestsParams{ClassIds: ids, FromAt: start,
			ToAt: end.AddDate(0, 0, 7)}); err != nil {
			return sec, err
		}
	}
	classes := map[uuid.UUID]bool{}
	details := make([]reports.Detail, 0, len(tests))
	for _, t := range tests {
		d := reports.Detail{Text: t.ClassName}
		if t.ScheduledAt != nil {
			d.Text += " · " + t.ScheduledAt.In(vnZone).Format("02/01")
		}
		if t.ClassID != nil {
			classes[*t.ClassID] = true
			d.URL = "/classes/" + t.ClassID.String()
		}
		details = append(details, d)
	}
	sections := []reports.Section{sec}
	setItem(sections, "upcoming_test_classes", len(classes), details)
	return sections[0], nil
}

// maxSuggestions: số dòng gợi ý tối đa mỗi bảng (WeeklyReportMetrics::MAX_SUGGESTIONS).
const maxSuggestions = 30

func weeklyRow(source, content string, detail *string, deadline *time.Time, progress int, link string) reports.WeeklyRow {
	c := strLimit(content, 480)
	r := reports.WeeklyRow{Source: &source, Content: &c, Detail: detail, Progress: &progress}
	if deadline != nil {
		d := deadline.Format("2006-01-02")
		r.Deadline = &d
	}
	if link != "" {
		r.Link = &link
	}
	return r
}

// appendRows: thêm dòng vào cuối bảng key, bỏ dòng trùng source, tối đa maxSuggestions dòng.
func appendRows(m map[string][]reports.WeeklyRow, key string, rows []reports.WeeklyRow) map[string][]reports.WeeklyRow {
	if m == nil {
		m = map[string][]reports.WeeklyRow{}
	}
	if len(rows) == 0 {
		return m
	}
	seen := map[string]bool{}
	out := make([]reports.WeeklyRow, 0, len(m[key])+len(rows))
	for _, r := range append(append([]reports.WeeklyRow{}, m[key]...), rows...) {
		if r.Source != nil {
			if seen[*r.Source] {
				continue
			}
			seen[*r.Source] = true
		}
		if len(out) == maxSuggestions {
			break
		}
		out = append(out, r)
	}
	m[key] = out
	return m
}

func detailOf(s *string, n int) *string {
	if s == nil || *s == "" {
		return nil
	}
	v := strLimit(*s, n)
	return &v
}

// WeeklyAcademic: dòng gợi ý "đã làm" (order đề đã duyệt, đề xuất / điều chỉnh đã xử lý trong tuần) và "đề xuất / yêu cầu"
// (order đề, đề xuất, điều chỉnh đang chờ duyệt), sau các dòng mốc / việc / order học liệu như Laravel.
func (m *ReportMetrics) WeeklyAcademic(ctx context.Context, w reports.Writer, from, to time.Time) (reports.Section, map[string][]reports.WeeklyRow, error) {
	summary, suggestions, err := m.MetricsProvider.WeeklyAcademic(ctx, w, from, to)
	if err != nil {
		return summary, suggestions, err
	}
	a, err := m.access(ctx, w.ID)
	if err != nil {
		return summary, nil, err
	}
	start, end := dayBounds(from, to)
	var done, requests []reports.WeeklyRow
	orders, err := m.orders(ctx, a, noLimit, true, start, end)
	if err != nil {
		return summary, nil, err
	}
	const distribution = "/syllabus/big-tests/distribution"
	for _, o := range orders {
		if o.Status == "approved" {
			done = append(done, weeklyRow("bto:"+o.ID.String(), "Duyệt đề "+orderTypeLabel(o.TestType)+" · "+o.ClassName, nil,
				o.DueDate, 100, distribution))
		}
	}
	for _, o := range orders {
		if o.Status == "pending" {
			pending := "Chờ duyệt"
			requests = append(requests, weeklyRow("bto:"+o.ID.String(), "Order đề "+orderTypeLabel(o.TestType)+" · "+o.ClassName,
				&pending, o.DueDate, 0, distribution))
		}
	}
	props, err := m.proposals(ctx, a, noLimit, true, start, end)
	if err != nil {
		return summary, nil, err
	}
	for _, p := range props {
		r := weeklyRow("proposal:"+p.ID.String(), "Đề xuất sửa giáo trình · "+p.CurriculumTitle, detailOf(p.Reason, 300), nil, 0, "")
		if p.Status == "pending" {
			requests = append(requests, r)
		} else {
			*r.Progress = 100
			done = append(done, r)
		}
	}
	reqs, err := m.pacing(ctx, a, w, noLimit, true, start, end)
	if err != nil {
		return summary, nil, err
	}
	for _, q := range reqs {
		r := weeklyRow("adjust:"+q.ID.String(), "Điều chỉnh syllabus · "+q.ClassName, detailOf(&q.Reason, 300), nil, 0,
			"/syllabus/adjustment-requests")
		if q.Status == "pending" {
			requests = append(requests, r)
		} else {
			*r.Progress = 100
			done = append(done, r)
		}
	}
	suggestions = appendRows(suggestions, "done", done)
	return summary, appendRows(suggestions, "requests", requests), nil
}
