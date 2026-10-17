package catalogs

import (
	"context"
	"log/slog"
	"maps"
	"slices"
	"testing"
	"time"

	"github.com/google/uuid"
)

// Ngày không phải nộp báo cáo ngày: ngày nghỉ toàn hệ thống + của chi nhánh người viết, cắt trong khoảng hỏi.
func TestReportCalendar(t *testing.T) {
	e := setup(t)
	hn, hcm := e.branch("HN01"), e.branch("HCM01")
	admin := e.as(nil, "admin")
	expect(t, admin.do("POST", "/holidays", map[string]any{"name": "Tết", "start_date": "2027-02-05", "end_date": "2027-02-08"}), 201, "")
	expect(t, admin.do("POST", "/holidays", map[string]any{"name": "Nghỉ HN", "start_date": "2027-02-10", "end_date": "2027-02-10",
		"branch_ids": []string{hn.String()}}), 201, "")

	cal := NewService(e.pg, e.box, slog.Default()).ReportCalendar()
	zone := time.FixedZone("ICT", 7*3600)
	from, to := time.Date(2027, 2, 7, 0, 0, 0, 0, zone), time.Date(2027, 2, 12, 0, 0, 0, 0, zone)
	days := func(branch *uuid.UUID) []string {
		t.Helper()
		off, err := cal.OffDays(context.Background(), uuid.New(), branch, from, to)
		if err != nil {
			t.Fatal(err)
		}
		return slices.Sorted(maps.Keys(off))
	}
	if got := days(&hn); !slices.Equal(got, []string{"2027-02-07", "2027-02-08", "2027-02-10"}) {
		t.Fatalf("chi nhánh HN: %v", got)
	}
	if got := days(&hcm); !slices.Equal(got, []string{"2027-02-07", "2027-02-08"}) {
		t.Fatalf("chi nhánh HCM: %v", got)
	}
	if got := days(nil); !slices.Equal(got, []string{"2027-02-07", "2027-02-08"}) {
		t.Fatalf("không chi nhánh: %v", got)
	}
}
