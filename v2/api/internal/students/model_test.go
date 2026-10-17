package students

import (
	"testing"
	"time"
)

func day(s string) *time.Time {
	t, _ := time.Parse(time.DateOnly, s)
	return &t
}

func TestTenureLabel(t *testing.T) {
	today := *day("2026-10-16")
	cases := []struct {
		start  *time.Time
		status string
		want   string
		long   bool
	}{
		{nil, StatusStudying, "", false},
		{day("2026-10-01"), StatusStudying, "Dưới 1 tháng", false},
		{day("2026-09-16"), StatusStudying, "1 tháng", false},
		{day("2026-09-17"), StatusStudying, "Dưới 1 tháng", false},
		{day("2023-10-16"), StatusStudying, "3 năm", true},
		{day("2023-08-01"), StatusDeferred, "3 năm 2 tháng", true},
		{day("2025-01-31"), StatusStudying, "1 năm 8 tháng", false},
		{day("2020-01-01"), StatusDropped, "", false},
		{day("2026-12-01"), StatusWaitingStart, "", false},
	}
	for _, c := range cases {
		got := TenureLabel(c.start, c.status, today)
		if (got == nil) != (c.want == "") || (got != nil && *got != c.want) {
			t.Errorf("%v %s: muốn %q, nhận %v", fmtDate(c.start), c.status, c.want, got)
		}
		if LongTerm(c.start, c.status, today) != c.long {
			t.Errorf("%v %s: lâu năm phải là %v", fmtDate(c.start), c.status, c.long)
		}
	}
}

func TestDateOfUsesVietnamTime(t *testing.T) {
	// 18:30 UTC ngày 15 là 01:30 ngày 16 ở Việt Nam
	got := dateOf(time.Date(2026, 10, 15, 18, 30, 0, 0, time.UTC))
	if got.Format(time.DateOnly) != "2026-10-16" {
		t.Fatalf("dateOf = %s", got)
	}
}
