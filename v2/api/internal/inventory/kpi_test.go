package inventory

import (
	"context"
	"fmt"
	"testing"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

// Nguồn KPI: order học liệu học thuật giao đúng SLA trước giờ dùng, học liệu xử lý trễ hạn.
func TestKpiSources(t *testing.T) {
	e := setup(t)
	e.svc.now = func() time.Time { return vn("2026-10-17 09:00") }
	cg := e.branch("CG", "Cầu Giấy")
	gv := e.member("GV001", []uuid.UUID{cg}, iam.RoleTeacherFulltime)
	hv := e.member("HV001", []uuid.UUID{cg}, iam.RoleAcademicStaff)
	n := 0
	order := func(category, use, created, status string, processed *string, late bool) {
		n++
		e.exec(`INSERT INTO ops.material_orders (code, branch_id, requester_id, category, title, use_date, due_at, status, created_at,
			processed_by, processed_at, processed_late)
			VALUES ($1, $2, $3, $4, 'Sách', $5::date, $5::date, $6, $7::timestamptz, $8, $9::timestamptz, $10)`,
			fmt.Sprintf("OH-2026-%04d", n),
			cg, gv.id, category, use, status, created+"+07", hv.id, processed, late)
	}
	s := func(v string) *string { v += "+07"; return &v }
	order("academic", "2026-10-10", "2026-10-05 09:00", "done", s("2026-10-08 10:00"), false) // trước 46 giờ → 1
	order("academic", "2026-10-12", "2026-10-01 09:00", "done", s("2026-10-11 18:00"), false) // trước 14 giờ → 0,5
	order("academic", "2026-10-13", "2026-10-01 09:00", "pending", nil, false)                // quá giờ dùng → chặn
	order("academic", "2026-10-14", "2026-10-13 20:00", "pending", nil, false)                // tạo sát giờ → không tính
	order("academic", "2026-10-20", "2026-10-01 09:00", "pending", nil, false)                // chưa tới giờ dùng
	order("props", "2026-10-10", "2026-10-01 09:00", "done", s("2026-10-11 09:00"), true)     // không phải học thuật
	order("printing", "2026-10-09", "2026-10-01 09:00", "done", s("2026-10-09 10:00"), true)  // xử lý trễ hạn

	ctx := context.Background()
	from, to := vn("2026-10-01 00:00"), vn("2026-10-31 23:59")
	res, err := e.svc.KpiSources()["academic_order_on_time"](ctx, hv.id, from, to)
	if err != nil || len(res.Records) != 4 {
		t.Fatalf("order học thuật: %+v %v", res, err)
	}
	r := res.Records
	if *r[0].Factor != 1 || *r[1].Factor != 0.5 || r[1].Text != "Order OH-2026-0002 (Sách): dùng 08:00 12/10, giao xong 18:00 11/10 (trước 14 giờ)" {
		t.Errorf("giao đúng hạn: %+v %+v", r[0], r[1])
	}
	if !r[2].Cap || *r[2].Factor != 0 || r[2].Text != "Order OH-2026-0003 (Sách): dùng 08:00 13/10, quá giờ dùng chưa giao" {
		t.Errorf("quá giờ dùng: %+v", r[2])
	}
	if r[3].Factor != nil || r[3].Note == "" {
		t.Errorf("tạo sát giờ: %+v", r[3])
	}

	res, err = e.svc.KpiSources()["material_stock"](ctx, hv.id, from, to)
	if err != nil || len(res.Records) != 2 || res.Records[0].Text != "Học liệu OH-2026-0007 xử lý trễ hạn (Sách)" {
		t.Fatalf("học liệu trễ hạn: %+v %v", res, err)
	}
}
