package reports

import (
	"context"
	"fmt"
	"time"

	"github.com/google/uuid"
)

// Số liệu báo cáo từ các phân hệ đã có ở v2 (CRM, test đầu vào). NewMetrics lấy bản khung (skeletonMetrics) rồi điền số
// thật vào các mục có nguồn; mục của phân hệ chưa chuyển (lớp học, học phí, ticket, dự án, giáo trình, KPI…) giữ nguyên
// "Chờ phân hệ … chuyển sang hệ thống mới".

// LeadBrief: khách CRM được đếm trong báo cáo.
type LeadBrief struct {
	ID        uuid.UUID
	Name      string
	Source    string
	BranchID  *uuid.UUID
	Contacted bool      // đã liên hệ được (gọi / nhắn / gặp) trước hết khoảng đếm
	At        time.Time // NewLeads: lúc tạo; Closed: lúc chuyển sang Chờ xếp lớp / Đã chốt
}

// TestTaken: bài test đầu vào làm trong khoảng.
type TestTaken struct {
	LeadID *uuid.UUID
	Name   string // tên khách (không có thì tên thí sinh)
	Score  string // điểm tổng ("" = chưa có)
	Level  string // CEFR ("" = chưa có)
	Graded bool
}

// LeadSource: số liệu khách CRM theo phạm vi người viết được xem (CrmCustomer::visibleTo). Khoảng [from, to) theo giờ VN.
type LeadSource interface {
	// NewLeads: khách tạo trong khoảng, chỉ trong chi nhánh của người viết (người viết không có chi nhánh: không lọc thêm).
	NewLeads(ctx context.Context, w Writer, from, to time.Time) ([]LeadBrief, error)
	// Appointments: số khách có lịch hẹn test trong khoảng.
	Appointments(ctx context.Context, w Writer, from, to time.Time) (int, error)
	// Closed: khách chuyển sang Chờ xếp lớp / Đã chốt trong khoảng (mỗi khách một lần), trong chi nhánh của người viết.
	Closed(ctx context.Context, w Writer, from, to time.Time) ([]LeadBrief, error)
	// Tests: bài test đầu vào làm trong khoảng của khách người viết xem được.
	Tests(ctx context.Context, w Writer, from, to time.Time) ([]TestTaken, error)
	// BranchNames: tên chi nhánh (tách số theo chi nhánh khi người viết phụ trách nhiều chi nhánh).
	BranchNames(ctx context.Context, ids []uuid.UUID) (map[uuid.UUID]string, error)
}

// NewMetrics: MetricsProvider điền số liệu tuyển sinh (CRM + test đầu vào) lên bản khung.
func NewMetrics(leads LeadSource) MetricsProvider {
	return sourcedMetrics{skeletonMetrics: skeletonMetrics{}, leads: leads}
}

type sourcedMetrics struct {
	skeletonMetrics
	leads LeadSource
}

func leadURL(id uuid.UUID) string { return "/crm/customers/" + id.String() }

func intItem(it *Item, n int) {
	v := fmt.Sprint(n)
	it.Value, it.Count, it.Unavailable = &v, &n, ""
}

// replaceItem: thay mục key trong các khối bằng fill (giữ nhãn / cách đếm của bản khung).
func replaceItem(sections []Section, key string, fill func(it *Item) error) error {
	for si := range sections {
		for ii := range sections[si].Items {
			if sections[si].Items[ii].Key == key {
				it := &sections[si].Items[ii]
				it.Details = []Detail{}
				return fill(it)
			}
		}
	}
	return nil
}

// bySource: "<nguồn>: <số>" theo thứ tự gặp đầu tiên (Laravel groupBy).
func bySource(leads []LeadBrief) []Detail {
	var order []string
	count := map[string]int{}
	for _, l := range leads {
		src := l.Source
		if src == "" {
			src = "Chưa rõ nguồn"
		}
		if count[src] == 0 {
			order = append(order, src)
		}
		count[src]++
	}
	out := make([]Detail, 0, len(order))
	for _, s := range order {
		out = append(out, Detail{Text: fmt.Sprintf("%s: %d", s, count[s])})
	}
	return out
}

// branchSplit: người viết phụ trách từ 2 chi nhánh thì thêm dòng số theo từng chi nhánh.
func (m sourcedMetrics) branchSplit(ctx context.Context, w Writer, ids []*uuid.UUID, unit string) ([]Detail, error) {
	if len(w.BranchIDs) < 2 {
		return nil, nil
	}
	names, err := m.leads.BranchNames(ctx, w.BranchIDs)
	if err != nil {
		return nil, err
	}
	count := map[uuid.UUID]int{}
	for _, id := range ids {
		if id != nil {
			count[*id]++
		}
	}
	var out []Detail
	for _, b := range w.BranchIDs {
		if name, ok := names[b]; ok {
			out = append(out, Detail{Text: fmt.Sprintf("%s: %d %s", name, count[b], unit), Tag: "Chi nhánh"})
		}
	}
	return out, nil
}

func (m sourcedMetrics) Daily(ctx context.Context, w Writer, date time.Time, audience string) ([]Section, map[string]any, error) {
	sections, fields, err := m.skeletonMetrics.Daily(ctx, w, date, audience)
	if err != nil || audience == AudienceAcademic {
		return sections, fields, err
	}
	from, to := dayOf(date), dayOf(date).AddDate(0, 0, 1)
	err = replaceItem(sections, "new_data", func(it *Item) error {
		leads, err := m.leads.NewLeads(ctx, w, from, to)
		if err != nil {
			return err
		}
		intItem(it, len(leads))
		fields["new_data"] = len(leads)
		if len(leads) == 0 {
			return nil
		}
		contacted := 0
		branches := make([]*uuid.UUID, 0, len(leads))
		for _, l := range leads {
			if l.Contacted {
				contacted++
			}
			branches = append(branches, l.BranchID)
		}
		tone := "success"
		if contacted < len(leads) {
			tone = "warning"
		}
		it.Details = append(bySource(leads), Detail{Text: fmt.Sprintf("Đã liên hệ lần đầu trong ngày: %d/%d", contacted, len(leads)), Tone: tone})
		split, err := m.branchSplit(ctx, w, branches, "khách")
		it.Details = append(it.Details, split...)
		return err
	})
	if err != nil {
		return nil, nil, err
	}
	err = replaceItem(sections, "test_students", func(it *Item) error {
		tests, err := m.leads.Tests(ctx, w, from, to)
		if err != nil {
			return err
		}
		booked, err := m.leads.Appointments(ctx, w, from, to)
		if err != nil {
			return err
		}
		n := len(tests)
		v := fmt.Sprintf("%d / %d hẹn", n, booked)
		it.Value, it.Count, it.Unavailable = &v, &n, ""
		fields["test_students"] = n
		for _, t := range tests {
			d := Detail{Text: t.Name, Tag: "Chờ chấm"}
			if t.Score != "" {
				d.Text += " · " + t.Score
			}
			if t.Level != "" {
				d.Text += " · " + t.Level
			}
			if t.Graded {
				d.Tag = "Đã chấm"
			}
			if t.LeadID != nil {
				d.URL = leadURL(*t.LeadID)
			}
			it.Details = append(it.Details, d)
		}
		return nil
	})
	if err != nil {
		return nil, nil, err
	}
	return sections, fields, nil
}

func (m sourcedMetrics) WeeklyScale(ctx context.Context, w Writer, from, to time.Time) (Section, error) {
	sec, err := m.skeletonMetrics.WeeklyScale(ctx, w, from, to)
	if err != nil {
		return sec, err
	}
	start, end := dayOf(from), dayOf(to).AddDate(0, 0, 1)
	sections := []Section{sec}
	err = replaceItem(sections, "new_leads", func(it *Item) error {
		leads, err := m.leads.NewLeads(ctx, w, start, end)
		if err != nil {
			return err
		}
		intItem(it, len(leads))
		it.Details = bySource(leads)
		return nil
	})
	if err != nil {
		return sec, err
	}
	err = replaceItem(sections, "new_closed", func(it *Item) error {
		closed, err := m.leads.Closed(ctx, w, start, end)
		if err != nil {
			return err
		}
		intItem(it, len(closed))
		for _, l := range closed {
			it.Details = append(it.Details, Detail{Text: l.Name + " · " + l.At.In(vnZone).Format("02/01"), URL: leadURL(l.ID)})
		}
		return nil
	})
	return sections[0], err
}
