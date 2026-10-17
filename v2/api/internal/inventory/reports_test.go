package inventory

import (
	"context"
	"strings"
	"testing"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/reports"
)

func reportItem(secs []reports.Section, key string) reports.Item {
	for _, s := range secs {
		for _, it := range s.Items {
			if it.Key == key {
				return it
			}
		}
	}
	return reports.Item{}
}

func joinDetails(it reports.Item) string {
	var out []string
	for _, d := range it.Details {
		out = append(out, d.Text+"|"+d.Tag+"|"+d.Tone)
	}
	return strings.Join(out, ";")
}

// Số liệu Order học liệu của báo cáo (DailyReportMetrics::facilityDetails / academic, WeeklyReportMetrics::academicSummary /
// academicSuggestions).
func TestReportMetrics(t *testing.T) {
	e := setup(t)
	cg := e.branch("CG", "Cầu Giấy")
	admin := e.member("AD001", nil, iam.RoleAdmin)
	gv := e.member("GV001", []uuid.UUID{cg}, iam.RoleTeacherFulltime)
	order := func(code, category, use, due, created, status string, processed *string) {
		e.exec(`INSERT INTO ops.material_orders (code, branch_id, requester_id, category, title, use_date, due_at, status, created_at,
			processed_at) VALUES ('OH-2026-' || $1, $2, $3, $4, 'Sách '||$1, $5::date, $6::timestamptz, $7, $8::timestamptz, $9::timestamptz)`,
			code, cg, gv.id, category, use, due+"+07", status, created+"+07", processed)
	}
	at := func(v string) *string { v += "+07"; return &v }
	order("1001", "academic", "2026-11-02", "2026-10-31 23:59", "2026-10-17 08:00", "pending", nil)
	order("1002", "academic", "2026-10-21", "2026-10-20 23:59", "2026-10-01 09:00", "done", at("2026-10-17 10:00"))
	order("1003", "academic", "2026-10-11", "2026-10-10 23:59", "2026-10-01 09:00", "overdue", nil)
	order("1004", "academic", "2026-10-26", "2026-10-25 23:59", "2026-10-01 09:00", "pending", nil)
	order("1005", "academic", "2026-11-26", "2026-11-25 23:59", "2026-10-18 09:00", "pending", nil)
	order("2001", "props", "2026-10-17", "2026-10-16 15:00", "2026-10-10 09:00", "pending", nil)
	order("2002", "printing", "2026-10-20", "2026-10-15 15:00", "2026-10-10 09:00", "overdue", nil)
	order("2003", "printing", "2026-10-25", "2026-10-24 15:00", "2026-10-10 09:00", "pending", nil)
	order("3001", "foreign_teacher", "2026-10-17", "2026-10-05 23:59", "2026-10-01 09:00", "pending", nil)

	ctx := context.Background()
	m := e.svc.ReportMetrics(reports.Skeleton(), e.iam.LoadAccess)
	w := reports.Writer{ID: admin.id}
	day := vn("2026-10-17 00:00")
	secs, _, err := m.Daily(ctx, w, day, reports.AudienceAcademic)
	if err != nil {
		t.Fatal(err)
	}
	if it := reportItem(secs, "academic_orders"); it.Unavailable != "" || *it.Count != 3 ||
		joinDetails(it) != "OH-2026-1003 · Sách 1003|Quá hạn|error;OH-2026-1002 · Sách 1002|Hoàn thành|success;OH-2026-1001 · Sách 1001|Chờ xử lý|" {
		t.Fatalf("order học liệu ngày: %+v %s", it, joinDetails(it))
	}
	secs, _, err = m.Daily(ctx, w, day, reports.AudienceOperations)
	if err != nil {
		t.Fatal(err)
	}
	if it := reportItem(secs, "facilities"); it.Unavailable != "" || it.Count != nil ||
		joinDetails(it) != "OH-2026-2002 · Sách 2002|In ấn · Quá hạn|error;OH-2026-2001 · Sách 2001|Đạo cụ · Chờ xử lý|" {
		t.Fatalf("cơ sở vật chất: %+v %s", it, joinDetails(it))
	}

	sum, rows, err := m.WeeklyAcademic(ctx, w, vn("2026-10-12 00:00"), vn("2026-10-18 00:00"))
	if err != nil {
		t.Fatal(err)
	}
	if it := reportItem([]reports.Section{sum}, "academic_orders"); *it.Count != 2 {
		t.Fatalf("order học liệu tuần: %+v", it)
	}
	text := func(rs []reports.WeeklyRow) string {
		var out []string
		for _, r := range rs {
			out = append(out, *r.Source+"|"+*r.Content+"|"+*r.Deadline)
		}
		return strings.Join(out, ";")
	}
	var a2 string
	if err := e.pool.QueryRow(ctx, `SELECT id::text FROM ops.material_orders WHERE code = 'OH-2026-1002'`).Scan(&a2); err != nil {
		t.Fatal(err)
	}
	if got := text(rows["done"]); got != "order:"+a2+"|Order học liệu OH-2026-1002: Sách 1002|2026-10-20" {
		t.Fatalf("đã làm: %s", got)
	}
	if got := text(rows["requests"]); strings.Count(got, "order:") != 4 || !strings.Contains(got, "Order học liệu OH-2026-1003: Sách 1003|2026-10-10") ||
		*rows["requests"][0].Detail != "Quá hạn" {
		t.Fatalf("yêu cầu: %s", got)
	}
}
