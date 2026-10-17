package syllabus_test

import (
	"context"
	"encoding/json"
	"strings"
	"testing"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam/iamtest"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/reports"
)

func reportItem(sections []reports.Section, key string) reports.Item {
	for _, s := range sections {
		for _, it := range s.Items {
			if it.Key == key {
				return it
			}
		}
	}
	return reports.Item{}
}

func detailTexts(it reports.Item) []string {
	out := []string{}
	for _, d := range it.Details {
		out = append(out, d.Text+"|"+d.Tag+"|"+d.Tone)
	}
	return out
}

// Số liệu Giáo trình & Big Test của báo cáo (DailyReportMetrics::academic, WeeklyReportMetrics): order đề chờ duyệt, đề
// xuất sửa giáo trình, yêu cầu điều chỉnh tiến độ, lớp sắp lịch Big Test và dòng gợi ý của báo cáo tuần Học thuật.
func TestReportMetrics(t *testing.T) {
	e := setup(t)
	ctx := context.Background()
	a := e.Branch("CN-A")
	_, lead := e.Member("HT001", "Học thuật", []uuid.UUID{a}, iam.RoleAcademicLead)
	gv, teacher := e.Member("GV001", "Giáo viên A", []uuid.UUID{a}, iam.RoleTeacherFulltime)
	leadU, _ := e.Member("HT002", "Học thuật 2", []uuid.UUID{a}, iam.RoleAcademicLead)

	cur, _ := e.curriculum(lead, "CUR-RP", 1)
	e.level("RPT", uuid.MustParse(cur))
	class := e.class("RP-01", a, &gv.ID, "RPT")
	iamtest.Expect(t, lead.Do("POST", "/syllabus/assignments", map[string]any{"class_id": class.String()}), 201, "")
	newID(t, teacher.Upload("/syllabus/proposals", map[string]string{"curriculum_id": cur, "new_content": "Thêm bài nghe",
		"reason": "Học viên yếu nghe"}, "", nil))
	r := teacher.Do("POST", "/syllabus/adjustment-requests", map[string]any{"class_id": class.String(), "reason": "Lớp học chậm",
		"extra_sessions": 0})
	expectMsg(t, r, 201, "")
	exam := vnNow().AddDate(0, 0, 10).Format(time.DateOnly)
	newID(t, teacher.Do("POST", "/teacher/order-test/"+class.String(), map[string]any{"test_type": "big", "exam_date": exam}))
	e.exec(`INSERT INTO syllabus.big_tests (code, title, class_id, test_type, scheduled_at, room)
		VALUES ('BT-RP', 'Thi chặng', $1, 'stage_end', now() + interval '3 days', 'P1')`, class)

	m := e.svc.ReportMetrics(reports.Skeleton(), e.IAM.LoadAccess)
	today := vnNow()
	writer := reports.Writer{ID: leadU.ID, BranchIDs: []uuid.UUID{a}}
	sections, _, err := m.Daily(ctx, writer, today, reports.AudienceAcademic)
	if err != nil {
		t.Fatal(err)
	}
	orders := reportItem(sections, "big_test_orders")
	if orders.Unavailable != "" || orders.Count == nil || *orders.Count != 1 || len(orders.Details) != 1 ||
		!strings.Contains(orders.Details[0].Text, "Lớp RP-01 · Big Test · hạn ") {
		t.Fatalf("order đề chờ duyệt: %+v", orders)
	}
	if it := reportItem(sections, "syllabus_proposals"); it.Count == nil || *it.Count != 1 ||
		strings.Join(detailTexts(it), ";") != "Giáo trình CUR-RP · Học viên yếu nghe — Giáo viên A|Chờ duyệt|" {
		t.Fatalf("đề xuất sửa giáo trình: %+v %q", it, detailTexts(it))
	}
	if it := reportItem(sections, "syllabus_adjustments"); it.Count == nil || *it.Count != 1 ||
		strings.Join(detailTexts(it), ";") != "Lớp RP-01 · Lớp học chậm — Giáo viên A|Chờ duyệt|" {
		t.Fatalf("yêu cầu điều chỉnh: %+v %q", it, detailTexts(it))
	}

	// Báo cáo tuần Học vụ: lớp có Big Test trong tuần này / tuần sau
	weekStart := today.AddDate(0, 0, -int(today.Weekday()+6)%7)
	scale, err := m.WeeklyScale(ctx, writer, weekStart, weekStart.AddDate(0, 0, 6))
	if err != nil {
		t.Fatal(err)
	}
	if it := reportItem([]reports.Section{scale}, "upcoming_test_classes"); it.Count == nil || *it.Count != 1 ||
		len(it.Details) != 1 || it.Details[0].URL != "/classes/"+class.String() {
		t.Fatalf("lớp sắp lịch test: %+v", it)
	}

	// Báo cáo tuần Học thuật: 3 việc đang chờ duyệt vào bảng "Quản lý yêu cầu"
	_, rows, err := m.WeeklyAcademic(ctx, writer, weekStart, weekStart.AddDate(0, 0, 6))
	if err != nil {
		t.Fatal(err)
	}
	var got []string
	for _, r := range rows["requests"] {
		got = append(got, strings.SplitN(*r.Source, ":", 2)[0]+" "+*r.Content)
	}
	if strings.Join(got, ";") != "bto Order đề Big Test · Lớp RP-01;proposal Đề xuất sửa giáo trình · Giáo trình CUR-RP;adjust Điều chỉnh syllabus · Lớp RP-01" {
		t.Fatalf("dòng gợi ý: %q", got)
	}
	if len(rows["done"]) != 0 {
		t.Fatalf("đã làm: %+v", rows["done"])
	}

	// Tổng quan: hàng chờ chuyên môn, số liệu tuần, Big Test trên lịch
	src := e.svc.Dashboard()
	b, err := src.Board(ctx, time.Now())
	if err != nil || b.PendingProposals != 1 || b.PendingOrders != 1 || b.PendingAdjustments != 1 || b.PendingResults != 0 ||
		len(b.Adjustments) != 1 || b.Adjustments[0].Reason != "Lớp học chậm" || *b.Adjustments[0].Teacher != "Giáo viên A" ||
		len(b.UpcomingBigTests) != 1 || b.UpcomingBigTests[0].Title != "Thi chặng" {
		t.Fatalf("bảng học thuật: %+v %v", b, err)
	}
	if wk, err := src.Week(ctx, time.Now()); err != nil || wk.AdjustmentsPending != 1 || wk.AdjustmentsWeek != 1 || wk.BigTestsUpcoming != 1 {
		t.Fatalf("số liệu tuần: %+v %v", wk, err)
	}
	items, err := src.Agenda(ctx, time.Now().Add(-time.Hour), time.Now().AddDate(0, 0, 6))
	if err != nil || len(items) != 1 || items[0].Title != "Lớp RP-01" || items[0].Subtitle != "Thi chặng" || *items[0].Href != "/syllabus/big-tests/schedules" {
		t.Fatalf("lịch Big Test: %+v %v", items, err)
	}
}

// Cổng giáo viên: bảng điểm Big Test các lớp GV chính / GVNN phụ trách, mới nhất trước (TeacherPortalController::bigTestReport).
func TestTeacherBigTestReport(t *testing.T) {
	e := setup(t)
	a := e.Branch("CN-A")
	gv, teacher := e.Member("GV001", "Giáo viên A", []uuid.UUID{a}, iam.RoleTeacherFulltime)
	gvnn, foreign := e.Member("GV002", "Giáo viên B", []uuid.UUID{a}, iam.RoleTeacherFulltime)
	_, other := e.Member("GV003", "Giáo viên C", []uuid.UUID{a}, iam.RoleTeacherFulltime)
	_, sale := e.Member("SA001", "Tư vấn", []uuid.UUID{a}, iam.RoleSalesConsultant)

	c1 := e.class("BR-01", a, &gv.ID, "")
	e.exec(`UPDATE academic.classes SET foreign_teacher_id = $1 WHERE id = $2`, gvnn.ID, c1)
	c2 := e.class("BR-02", a, &gvnn.ID, "")
	s1 := e.student("HV1", "Học viên Một", a, c1, "")
	s2 := e.student("HV2", "Học viên Hai", a, c2, "")
	t1 := e.id(`INSERT INTO syllabus.big_tests (code, title, class_id, test_type, scheduled_at)
		VALUES ('BT-1', 'Thi chặng 1', $1, 'stage_end', now()) RETURNING id`, c1)
	t2 := e.id(`INSERT INTO syllabus.big_tests (code, title, class_id, test_type, scheduled_at)
		VALUES ('BT-2', 'Thi chặng 2', $1, 'stage_end', now()) RETURNING id`, c2)
	e.exec(`INSERT INTO syllabus.big_test_results (big_test_id, student_id, listening_score, overall_score, status, created_at)
		VALUES ($1, $2, 7.5, 7.0, 'approved', now() - interval '1 hour')`, t1, s1)
	e.exec(`INSERT INTO syllabus.big_test_results (big_test_id, student_id, status) VALUES ($1, $2, 'pending_review')`, t2, s2)

	type row struct {
		StudentName    string   `json:"student_name"`
		TestTitle      string   `json:"test_title"`
		ClassName      string   `json:"class_name"`
		ListeningScore *float64 `json:"listening_score"`
		ReadingScore   *float64 `json:"reading_score"`
		OverallScore   *float64 `json:"overall_score"`
		Approved       bool     `json:"approved"`
	}
	type page struct {
		Results []row `json:"results"`
		Page    struct {
			Total    int `json:"total"`
			PageSize int `json:"page_size"`
		} `json:"page"`
	}
	read := func(c *iamtest.Client) page {
		t.Helper()
		r := c.Do("GET", "/teacher/big-test-report", nil)
		iamtest.Expect(t, r, 200, "")
		var p page
		if err := json.Unmarshal(r.Raw, &p); err != nil {
			t.Fatal(err)
		}
		return p
	}

	// GV chính lớp 1: chỉ thấy lớp 1
	p := read(teacher)
	if p.Page.Total != 1 || p.Page.PageSize != 20 || len(p.Results) != 1 || p.Results[0].StudentName != "Học viên Một" ||
		p.Results[0].TestTitle != "Thi chặng 1" || p.Results[0].ClassName != "Lớp BR-01" || p.Results[0].ListeningScore == nil ||
		*p.Results[0].ListeningScore != 7.5 || p.Results[0].ReadingScore != nil || !p.Results[0].Approved {
		t.Fatalf("GV chính: %+v", p)
	}
	// GVNN lớp 1 + GV chính lớp 2: cả hai, mới nhất trước
	p = read(foreign)
	if p.Page.Total != 2 || len(p.Results) != 2 || p.Results[0].ClassName != "Lớp BR-02" || p.Results[0].Approved ||
		p.Results[0].OverallScore != nil || p.Results[1].ClassName != "Lớp BR-01" {
		t.Fatalf("GVNN: %+v", p)
	}
	if p := read(other); p.Page.Total != 0 || len(p.Results) != 0 {
		t.Fatalf("GV khác: %+v", p)
	}
	iamtest.Expect(t, sale.Do("GET", "/teacher/big-test-report", nil), 403, "")
}
