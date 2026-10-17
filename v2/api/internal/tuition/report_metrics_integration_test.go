package tuition

import (
	"context"
	"testing"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/reports"
)

// fakeDaily: provider gốc chỉ có mục "fees" chưa có nguồn (như skeleton của phân hệ Báo cáo).
type fakeDaily struct{ reports.MetricsProvider }

func (fakeDaily) Daily(context.Context, reports.Writer, time.Time, string) ([]reports.Section, map[string]any, error) {
	return []reports.Section{{Key: "enrollment", Items: []reports.Item{{Key: "fees", Label: "Số lượng thu phí", Unavailable: "chờ"}}}}, map[string]any{}, nil
}

func TestReportMetricsFees(t *testing.T) {
	e := setup(t)
	a, b, c := e.Branch("CN-A"), e.Branch("CN-B"), e.Branch("CN-C")
	s1 := e.student("HV-0001", "An", a, nil)
	s2 := e.student("HV-0002", "Bình", b, nil)
	s3 := e.student("HV-0003", "Chi", c, nil)
	day := e.svc.today()
	rc := func(code string, student uuid.UUID, status string, amount int64, date time.Time) {
		e.exec(`INSERT INTO finance.receipts (code, student_id, amount, payment_method, status, payment_date, approved_at)
			VALUES ($1, $2, $3, 'cash', $4, $5, CASE WHEN $4 = 'approved' THEN now() END)`,
			code, student, amount, status, date)
	}
	rc("PT-1", s1, StatusPending, 1_000_000, day)
	rc("PT-2", s2, StatusApproved, 2_500_000, day)
	rc("PT-3", s3, StatusApproved, 9_000_000, day) // chi nhánh ngoài báo cáo
	rc("PT-4", s1, StatusRejected, 3_000_000, day) // trả về: không tính
	rc("PT-5", s1, StatusApproved, 3_000_000, day.AddDate(0, 0, -1))

	m := e.svc.ReportMetrics(fakeDaily{})
	secs, fields, err := m.Daily(context.Background(), reports.Writer{BranchIDs: []uuid.UUID{a, b}}, day, reports.AudienceOperations)
	if err != nil {
		t.Fatal(err)
	}
	it := secs[0].Items[0]
	if it.Count == nil || *it.Count != 2 || it.Unavailable != "" || fields["fees"] != 2 || len(it.Details) != 5 ||
		it.Details[0].Text != "An · 1.000.000đ · Tiền mặt" || it.Details[0].Tag != "Chờ duyệt" ||
		it.Details[1].Tag != "Đã duyệt" || it.Details[1].Tone != "success" || it.Details[2].Text != "Tổng tiền: 3.500.000đ" ||
		it.Details[3].Text != "Chi nhánh CN-A: 1 phiếu" || it.Details[4].Text != "Chi nhánh CN-B: 1 phiếu" {
		t.Fatalf("mục thu phí: %+v %v", it, fields)
	}
	// Báo cáo Học thuật không có mục thu phí: giữ nguyên.
	secs, _, err = m.Daily(context.Background(), reports.Writer{}, day, reports.AudienceAcademic)
	if err != nil || secs[0].Items[0].Unavailable != "chờ" {
		t.Fatalf("báo cáo học thuật: %+v %v", secs, err)
	}
}
