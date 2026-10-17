package quality

import (
	"context"
	"fmt"
	"time"
	"unicode/utf8"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/quality/qualitydb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/reports"
)

// Phần việc Chất lượng lớp làm cho báo cáo (DailyReportMetrics / WeeklyReportMetrics / báo cáo tháng Học thuật):
// mục "Buổi dự giờ" của báo cáo ngày / tuần Học thuật, dòng gợi ý "đã dự giờ" của báo cáo tuần và buổi họp giáo viên
// của báo cáo tháng. Bọc một MetricsProvider khác, chỉ điền các mục này.

// AccessLoader nạp quyền của người viết báo cáo (iam.Service.LoadAccess).
type AccessLoader func(ctx context.Context, userID uuid.UUID) (*iam.Access, error)

type ReportMetrics struct {
	reports.MetricsProvider
	svc    *Service
	access AccessLoader
}

// ReportMetrics: provider gốc + số liệu dự giờ / họp giáo viên.
func (s *Service) ReportMetrics(base reports.MetricsProvider, access AccessLoader) *ReportMetrics {
	return &ReportMetrics{MetricsProvider: base, svc: s, access: access}
}

var _ reports.Partial = (*ReportMetrics)(nil)

// Pending: buổi họp giáo viên đã có nguồn; phần khác theo provider gốc.
func (m *ReportMetrics) Pending(part string) bool {
	if part == "meetings" {
		return false
	}
	p, ok := m.MetricsProvider.(reports.Partial)
	return ok && p.Pending(part)
}

// maxSuggestions: số dòng gợi ý tối đa mỗi bảng của báo cáo tuần Học thuật (WeeklyReportMetrics::MAX_SUGGESTIONS).
const maxSuggestions = 30

// observations: phiếu dự giờ có ngày dự trong [from, to] của lớp người viết thấy, trong các chi nhánh của người viết.
func (m *ReportMetrics) observations(ctx context.Context, w reports.Writer, from, to time.Time) ([]qualitydb.ObservationsBetweenRow, error) {
	a, err := m.access(ctx, w.ID)
	if err != nil {
		return nil, err
	}
	sc, err := m.svc.visible(ctx, a)
	if err != nil {
		return nil, err
	}
	return m.svc.q.ObservationsBetween(ctx, qualitydb.ObservationsBetweenParams{
		FromDate: dateOf(from), ToDate: dateOf(to), AllClasses: sc.all, Ids: sc.ids, BranchIds: nonNil(w.BranchIDs),
	})
}

// limit: Str::limit của Laravel (cắt theo ký tự, thêm "...").
func limit(s string, n int) string {
	if utf8.RuneCountInString(s) <= n {
		return s
	}
	return string([]rune(s)[:n]) + "..."
}

func observationText(r qualitydb.ObservationsBetweenRow, weekly bool) string {
	t := r.ClassName
	if r.Kind == "operations" {
		if r.TeacherName != nil {
			t += " · GV " + *r.TeacherName
		}
		if weekly {
			return t + dayMonth(r.ObservedOn)
		}
		if r.Rating != nil {
			t += " · " + labelOf(Ratings, *r.Rating)
		}
		return t
	}
	if weekly {
		return t + dayMonth(r.ObservedOn)
	}
	if r.Overall != nil && *r.Overall != "" {
		t += " · " + limit(*r.Overall, 80)
	}
	return t
}

func dayMonth(t *time.Time) string {
	if t == nil {
		return ""
	}
	return " · " + t.Format("02/01")
}

func fillObservations(sections []reports.Section, rows []qualitydb.ObservationsBetweenRow, weekly bool) {
	details := make([]reports.Detail, 0, len(rows))
	for _, r := range rows {
		details = append(details, reports.Detail{Text: observationText(r, weekly)})
	}
	setItem(sections, "observations", len(rows), details)
}

// setItem: điền số + chi tiết của mục key (giữ nhãn / cách đếm của provider gốc).
func setItem(sections []reports.Section, key string, n int, details []reports.Detail) {
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
	rows, err := m.observations(ctx, w, date, date)
	if err != nil {
		return nil, nil, err
	}
	fillObservations(sections, rows, false)
	return sections, fields, nil
}

func (m *ReportMetrics) WeeklyAcademic(ctx context.Context, w reports.Writer, from, to time.Time) (reports.Section, map[string][]reports.WeeklyRow, error) {
	summary, suggestions, err := m.MetricsProvider.WeeklyAcademic(ctx, w, from, to)
	if err != nil {
		return summary, suggestions, err
	}
	rows, err := m.observations(ctx, w, from, to)
	if err != nil {
		return summary, nil, err
	}
	sections := []reports.Section{summary}
	fillObservations(sections, rows, true)
	// Dòng gợi ý "đã làm": phiếu dự giờ chính người viết dự trong tuần.
	mine, err := m.svc.q.ObservationsByObserver(ctx, qualitydb.ObservationsByObserverParams{
		ObserverID: &w.ID, FromDate: dateOf(from), ToDate: dateOf(to),
	})
	if err != nil {
		return summary, nil, err
	}
	extra := make([]reports.WeeklyRow, 0, len(mine))
	for _, o := range mine {
		var content, source string
		var detail *string
		if o.Kind == "operations" {
			source, content = "qa:"+o.ID.String(), "Dự giờ "+o.ClassName
			if o.TeacherName != nil {
				content += " · GV " + *o.TeacherName
			}
			if o.Rating != nil {
				d := "Đánh giá: " + labelOf(Ratings, *o.Rating)
				detail = &d
			}
		} else {
			source, content = "obs:"+o.ID.String(), "Dự giờ đánh giá "+o.ClassName
			if o.Overall != nil && *o.Overall != "" {
				d := limit(*o.Overall, 300)
				detail = &d
			}
		}
		extra = append(extra, weeklyRow(source, content, detail, o.ObservedOn, 100, "/classes/"+o.ClassID.String()))
	}
	return sections[0], appendSuggestions(suggestions, "done", extra, false), nil
}

// weeklyRow: một dòng gợi ý của báo cáo tuần Học thuật (WeeklyReportMetrics::row).
func weeklyRow(source, content string, detail *string, deadline *time.Time, progress int, link string) reports.WeeklyRow {
	c := limit(content, 480)
	r := reports.WeeklyRow{Source: &source, Content: &c, Detail: detail, Deadline: ymd(deadline), Progress: &progress}
	if link != "" {
		r.Link = &link
	}
	return r
}

// appendSuggestions: thêm dòng vào bảng key (đầu hoặc cuối), bỏ dòng trùng source, tối đa maxSuggestions dòng.
func appendSuggestions(m map[string][]reports.WeeklyRow, key string, rows []reports.WeeklyRow, prepend bool) map[string][]reports.WeeklyRow {
	if m == nil {
		m = map[string][]reports.WeeklyRow{}
	}
	if len(rows) == 0 {
		return m
	}
	all := append(append([]reports.WeeklyRow{}, m[key]...), rows...)
	if prepend {
		all = append(append([]reports.WeeklyRow{}, rows...), m[key]...)
	}
	seen := map[string]bool{}
	out := make([]reports.WeeklyRow, 0, len(all))
	for _, r := range all {
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

// TeacherMeetings: buổi họp giáo viên người viết đã ghi có tuần trong [from, to] (báo cáo tháng Học thuật).
func (m *ReportMetrics) TeacherMeetings(ctx context.Context, author uuid.UUID, from, to time.Time) ([]reports.Meeting, error) {
	rows, err := m.svc.q.MeetingsByAuthor(ctx, qualitydb.MeetingsByAuthorParams{AuthorID: author, FromDate: dateOf(from), ToDate: dateOf(to)})
	if err != nil {
		return nil, err
	}
	out := make([]reports.Meeting, 0, len(rows))
	for _, r := range rows {
		out = append(out, reports.Meeting{
			ID: r.ID.String(), WeekStart: r.WeekStart.Format("2006-01-02"), Teacher: deref(r.TeacherName),
			StatusLabel: labelOf(MeetingStatuses, r.Status), StatusColor: colorOr(MeetingStatusColors, r.Status),
		})
	}
	return out, nil
}
