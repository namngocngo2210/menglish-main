package crm

import (
	"context"
	"slices"
	"testing"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/reports"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/search"
)

// Số liệu tuyển sinh cho báo cáo ngày / tuần và nhóm "Khách hàng (CRM)" của tìm kiếm chung, theo phạm vi danh sách khách.
func TestReportSourceAndSearch(t *testing.T) {
	e := setup(t)
	tm := newTeam(e)
	ctx := context.Background()
	src := e.svc.ReportSource()

	l1 := uuid.MustParse(tm.staffAC.create(t, newLead("Bé Một", "0912000001", tm.a)))
	zalo := newLead("Bé Hai", "0912000002", tm.a)
	zalo["source"] = "Zalo"
	l2 := uuid.MustParse(tm.staffAC.create(t, zalo))
	l3 := uuid.MustParse(tm.staffBC.create(t, newLead("Bé Ba", "0912000003", tm.b)))
	e.exec(`INSERT INTO crm.lead_events (lead_id, type, outcome, content) VALUES ($1, 'call', 'failed', 'Không nghe'),
		($2, 'call', 'reached', 'Đã gọi')`, l2, l1)
	e.exec("UPDATE crm.leads SET appointment_at = now() WHERE id = $1", l1)
	e.exec(`INSERT INTO crm.lead_events (lead_id, type, content, from_stage, to_stage) VALUES ($1, 'stage_change', 'Chốt', 'consulting', 'won')`, l2)
	var testID uuid.UUID
	if err := e.pg.QueryRow(ctx, `INSERT INTO placement.tests (code, title, target_level, duration_minutes)
		VALUES ('TEST-G3-G4-CRM', 'Đề CRM', 'A1', 30) RETURNING id`).Scan(&testID); err != nil {
		t.Fatal(err)
	}
	e.exec(`INSERT INTO placement.submissions (test_id, lead_id, candidate_name, candidate_phone, overall_score, cefr_level, status)
		VALUES ($1, $2, 'Thí sinh', '0912000001', 6.5, 'A2', 'graded'), ($1, $3, 'Thí sinh B', '0912000003', NULL, NULL, 'pending')`, testID, l1, l3)

	h := time.Now().In(vnZone)
	from := time.Date(h.Year(), h.Month(), h.Day(), 0, 0, 0, 0, vnZone)
	to := from.AddDate(0, 0, 1)
	writerA := reports.Writer{ID: tm.staffA.id, Name: tm.staffA.name, BranchIDs: []uuid.UUID{tm.a}}

	leads, err := src.NewLeads(ctx, writerA, from, to)
	if err != nil || len(leads) != 2 || leads[0].ID != l1 || !leads[0].Contacted || leads[1].Contacted || leads[1].Source != "Zalo" {
		t.Fatalf("khách mới (Học vụ CN-A): %+v %v", leads, err)
	}
	if all, err := src.NewLeads(ctx, reports.Writer{ID: tm.admin.id}, from, to); err != nil || len(all) != 3 {
		t.Fatalf("khách mới (Admin, không chi nhánh): %d %v", len(all), err)
	}
	if n, err := src.Appointments(ctx, writerA, from, to); err != nil || n != 1 {
		t.Fatalf("lịch hẹn test: %d %v", n, err)
	}
	if c, err := src.Closed(ctx, writerA, from, to); err != nil || len(c) != 1 || c[0].ID != l2 {
		t.Fatalf("khách mới chốt: %+v %v", c, err)
	}
	tests, err := src.Tests(ctx, writerA, from, to)
	if err != nil || len(tests) != 1 || tests[0].Name != "Bé Một" || tests[0].Score != "6.5" || tests[0].Level != "A2" || !tests[0].Graded {
		t.Fatalf("bài test: %+v %v", tests, err)
	}

	// Ghép vào báo cáo ngày qua reports.NewMetrics: mục có nguồn có số thật, mục khác vẫn chờ phân hệ.
	sections, fields, err := reports.NewMetrics(src).Daily(ctx, writerA, from, reports.AudienceOperations)
	if err != nil {
		t.Fatal(err)
	}
	items := map[string]reports.Item{}
	for _, s := range sections {
		for _, it := range s.Items {
			items[it.Key] = it
		}
	}
	nd, ts := items["new_data"], items["test_students"]
	if nd.Value == nil || *nd.Value != "2" || nd.Unavailable != "" || len(nd.Details) != 3 ||
		nd.Details[2].Text != "Đã liên hệ lần đầu trong ngày: 1/2" || nd.Details[2].Tone != "warning" {
		t.Fatalf("Data mới nhận: %+v", nd)
	}
	if ts.Value == nil || *ts.Value != "1 / 1 hẹn" || ts.Details[0].Text != "Bé Một · 6.5 · A2" || ts.Details[0].Tag != "Đã chấm" ||
		ts.Details[0].URL != leadLink(l1) || fields["new_data"] != 2 || fields["test_students"] != 1 {
		t.Fatalf("Số học sinh test: %+v %v", ts, fields)
	}
	if items["fees"].Unavailable == "" {
		t.Fatal("mục học phí phải chờ phân hệ")
	}
	scale, err := reports.NewMetrics(src).WeeklyScale(ctx, writerA, from, from)
	if err != nil {
		t.Fatal(err)
	}
	for _, it := range scale.Items {
		if (it.Key == "new_leads" && (it.Count == nil || *it.Count != 2)) || (it.Key == "new_closed" && (it.Count == nil || *it.Count != 1)) {
			t.Fatalf("chỉ số tuần %s: %+v", it.Key, it)
		}
	}

	// Tìm kiếm chung: theo tên / SĐT phụ huynh, trong phạm vi.
	find := func(who uuid.UUID, term string) []string {
		t.Helper()
		a, err := e.iam.LoadAccess(ctx, who)
		if err != nil {
			t.Fatal(err)
		}
		q, _, err := search.NewQuery(term, 10)
		if err != nil {
			t.Fatal(err)
		}
		hits, err := Searcher(e.svc).Search(ctx, a, q)
		if err != nil {
			t.Fatal(err)
		}
		var names []string
		for _, h := range hits {
			names = append(names, h.Title)
		}
		slices.Sort(names)
		return names
	}
	if got := find(tm.admin.id, "Bé"); !slices.Equal(got, []string{"Bé Ba", "Bé Hai", "Bé Một"}) {
		t.Fatalf("Admin tìm: %v", got)
	}
	if got := find(tm.staffB.id, "bé"); !slices.Equal(got, []string{"Bé Ba"}) {
		t.Fatalf("Học vụ CN-B tìm: %v", got)
	}
	if got := find(tm.staffA.id, "000002"); !slices.Equal(got, []string{"Bé Hai"}) {
		t.Fatalf("tìm theo SĐT: %v", got)
	}
}
