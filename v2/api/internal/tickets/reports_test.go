package tickets_test

import (
	"context"
	"strings"
	"testing"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/reports"
)

// Ticket "Cơ sở vật chất" trong báo cáo ngày Học vụ (DailyReportMetrics::facilityDetails) và dòng tham khảo của mục KPI
// material_stock ở báo cáo tuần (WeeklyReportMetrics::kpiHints): lọc theo chi nhánh người tạo.
func TestReportMetrics(t *testing.T) {
	e := setup(t)
	a, b := e.Branch("CN-A"), e.Branch("CN-B")
	hv, _ := e.Member("HV001", "Học vụ", []uuid.UUID{a}, iam.RoleAcademicStaff)
	other, _ := e.Member("HV002", "Học vụ B", []uuid.UUID{b}, iam.RoleAcademicStaff)
	ticket := func(code, category, status string, creator uuid.UUID, created string, resolved *string) {
		e.exec(`INSERT INTO ops.tickets (code, title, category, status, creator_id, description, created_at, resolved_at)
			VALUES ($1, 'Hỏng '||$1, $2, $3, $4, 'Mô tả', $5::timestamptz, $6::timestamptz)`, code, category, status, creator, created+"+07", resolved)
	}
	at := func(v string) *string { v += "+07"; return &v }
	ticket("TK-1", "facilities", "open", hv.ID, "2026-10-17 08:00", nil)
	ticket("TK-2", "facilities", "in_progress", hv.ID, "2026-10-15 08:00", nil)
	ticket("TK-3", "facilities", "resolved", hv.ID, "2026-10-14 08:00", at("2026-10-16 10:00")) // xong hôm qua
	ticket("TK-4", "facilities", "resolved", hv.ID, "2026-10-14 08:00", at("2026-10-17 07:00")) // xong hôm nay
	ticket("TK-5", "facilities", "open", other.ID, "2026-10-16 08:00", nil)                     // chi nhánh khác
	ticket("TK-6", "technical_issue", "open", hv.ID, "2026-10-16 08:00", nil)                   // không phải CSVC

	ctx := context.Background()
	m := e.svc.ReportMetrics(reports.Skeleton())
	w := reports.Writer{ID: hv.ID, BranchIDs: []uuid.UUID{a}}
	join := func(ds []reports.Detail) string {
		var out []string
		for _, d := range ds {
			out = append(out, d.Text+"|"+d.Tone)
		}
		return strings.Join(out, ";")
	}
	facilities := func(day time.Time) reports.Item {
		secs, _, err := m.Daily(ctx, w, day, reports.AudienceOperations)
		if err != nil {
			t.Fatal(err)
		}
		for _, s := range secs {
			for _, it := range s.Items {
				if it.Key == "facilities" {
					return it
				}
			}
		}
		return reports.Item{}
	}
	// Hôm nay: bỏ ticket đã xong
	if it := facilities(now); it.Unavailable != "" || join(it.Details) != "TK-2 · Hỏng TK-2|warning;TK-1 · Hỏng TK-1 (mới)|warning" {
		t.Fatalf("CSVC hôm nay: %+v", it)
	}
	// Ngày đã qua: ticket xong sau ngày đó vẫn hiện
	if it := facilities(now.AddDate(0, 0, -1)); join(it.Details) != "TK-3 · Hỏng TK-3|success;TK-4 · Hỏng TK-4|success;TK-2 · Hỏng TK-2|warning" {
		t.Fatalf("CSVC 16/10: %+v", it)
	}

	from := time.Date(2026, 10, 12, 0, 0, 0, 0, now.Location())
	hints, err := m.KpiHints(ctx, reports.KpiCriterion{AutoSource: "material_stock"}, w, from, from.AddDate(0, 0, 6))
	if err != nil || len(hints) != 4 || hints[0].Text != "Ticket CSVC TK-3 · Hỏng TK-3" || hints[0].Tag != "Tham khảo" {
		t.Fatalf("dòng tham khảo: %+v %v", hints, err)
	}
	if hints, _ := m.KpiHints(ctx, reports.KpiCriterion{AutoSource: "daily_report_late"}, w, from, from.AddDate(0, 0, 6)); len(hints) != 0 {
		t.Fatalf("mục khác: %+v", hints)
	}
}
