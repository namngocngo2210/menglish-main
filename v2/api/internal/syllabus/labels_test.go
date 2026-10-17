package syllabus

import (
	"testing"
	"time"
)

func TestLabels(t *testing.T) {
	cases := []struct{ got, want string }{
		{stageLabel(2, "Movers"), "Chặng 2: Movers"},
		{stageLabel(1, "Chặng 1"), "Chặng 1"},
		{formatMoney(50000), "50.000"},
		{formatMoney(1250000), "1.250.000"},
		{formatMoney(900), "900"},
		{fmtScore(nil), "-"},
		{fmtScore(ptr(7.5)), "7.5"},
		{fmtScore(ptr(8.0)), "8"},
		{normalizePhone("0912 345 678"), "84912345678"},
	}
	for _, c := range cases {
		if c.got != c.want {
			t.Errorf("nhận %q, muốn %q", c.got, c.want)
		}
	}
	no := int64(7)
	opened := time.Date(2026, 3, 1, 0, 0, 0, 0, time.UTC)
	if got := assignmentCode(&no, &opened, opened); got != "CH-2026-007" {
		t.Errorf("mã chặng: %q", got)
	}
}

func TestOverallAndValidation(t *testing.T) {
	if v := overallOf([]*float64{ptr(8.0), ptr(7.0), ptr(9.0), ptr(6.5)}); v == nil || *v != 7.6 {
		t.Fatalf("điểm tổng: %v", v)
	}
	if overallOf([]*float64{ptr(8.0), nil, ptr(9.0), ptr(6.5)}) != nil {
		t.Fatal("thiếu kỹ năng thì không có điểm tổng")
	}
	sid := "0190d8b4-6b0a-7c4b-9a8e-111111111111"
	// Lưu nháp cho nhập dở; gửi duyệt thì không.
	in := ResultsInput{Results: []ResultInput{{StudentID: sid, ListeningScore: ptr(5.0)}}}
	if _, err := validateResults(in, true); err != nil {
		t.Fatalf("lưu nháp nhập dở: %v", err)
	}
	if _, err := validateResults(in, false); err == nil {
		t.Fatal("gửi duyệt phải đủ 4 kỹ năng")
	}
	// Dòng trống bị bỏ qua.
	rows, err := validateResults(ResultsInput{Results: []ResultInput{{StudentID: sid}}}, false)
	if err != nil || len(rows) != 0 {
		t.Fatalf("dòng trống: %v %v", rows, err)
	}
}

func TestPaperWarning(t *testing.T) {
	now := time.Date(2026, 10, 17, 3, 0, 0, 0, time.UTC) // 10:00 giờ Việt Nam
	s := &Service{now: func() time.Time { return now }}
	w := paperWindow{warn: 7, approve: 3}
	at := func(days int) *time.Time { t := now.AddDate(0, 0, days); return &t }
	if s.paperWarning(w, false, at(10)) != nil {
		t.Fatal("ngoài 7 ngày không cảnh báo")
	}
	if p := s.paperWarning(w, false, at(5)); p == nil || p.Level != "warn" {
		t.Fatalf("còn 5 ngày: %v", p)
	}
	if p := s.paperWarning(w, false, at(2)); p == nil || p.Level != "overdue" || p.Label != "Quá hạn duyệt đề (trước 3 ngày) — còn 2 ngày" {
		t.Fatalf("còn 2 ngày: %v", p)
	}
	if s.paperWarning(w, true, at(2)) != nil || s.paperWarning(w, false, at(-1)) != nil {
		t.Fatal("đã phân phối / đã qua ngày thi thì không cảnh báo")
	}
}
