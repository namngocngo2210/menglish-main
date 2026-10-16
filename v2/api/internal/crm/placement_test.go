package crm

import (
	"context"
	"slices"
	"testing"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/placement"
)

// Cổng khách CRM cho phân hệ test đầu vào (placement.Leads) chạy trên dữ liệu thật.
func TestPlacementLeads(t *testing.T) {
	e := setup(t)
	tm := newTeam(e)
	ctx := context.Background()
	pl := e.svc.PlacementLeads()

	withParent := func(name, phone, parent string) map[string]any {
		b := newLead(name, phone, tm.a)
		b["parent_name"], b["parent_phone"] = "Phụ huynh "+name, parent
		return b
	}
	na := uuid.MustParse(tm.staffAC.create(t, newLead("Bé Na", "0912 345 678", tm.a)))
	bo := uuid.MustParse(tm.staffAC.create(t, withParent("Bé Bo", "0933333331", "0944.555.666")))
	tm.staffAC.create(t, withParent("Anh Tí", "0933333332", "0977 888 999"))
	tm.staffAC.create(t, withParent("Em Tèo", "0933333333", "0977888999"))

	// Phạm vi như danh sách khách CRM.
	for _, c := range []struct {
		who  uuid.UUID
		sees bool
		all  bool
	}{{tm.admin.id, false, true}, {tm.staffA.id, true, false}, {tm.staffB.id, false, false}} {
		a, err := e.iam.LoadAccess(ctx, c.who)
		if err != nil {
			t.Fatal(err)
		}
		ids, all, err := pl.VisibleIDs(ctx, a)
		if err != nil || all != c.all || slices.Contains(ids, na) != c.sees {
			t.Fatalf("phạm vi %s: all=%v thấy=%v err=%v", c.who, all, slices.Contains(ids, na), err)
		}
	}

	l, err := pl.Get(ctx, na)
	if err != nil || l == nil || l.Name != "Bé Na" || l.StageLabel != "Mới" || l.AssignedName != tm.staffA.name || *l.BranchID != tm.a {
		t.Fatalf("Get: %+v %v", l, err)
	}
	if m, err := pl.Lookup(ctx, []uuid.UUID{na, bo, uuid.New()}); err != nil || len(m) != 2 || m[bo].ParentName != "Phụ huynh Bé Bo" {
		t.Fatalf("Lookup: %v %v", m, err)
	}

	// Khớp SĐT: của khách, của phụ huynh (đúng một khách), anh em chung SĐT phụ huynh thì không đoán.
	for phone, want := range map[string]uuid.UUID{"0912345678": na, "0944555666": bo, "0977888999": uuid.Nil, "0900000000": uuid.Nil} {
		m, err := pl.MatchByPhone(ctx, phone)
		if err != nil || (m == nil) != (want == uuid.Nil) || (m != nil && m.ID != want) {
			t.Fatalf("MatchByPhone %s: %+v %v", phone, m, err)
		}
	}

	lastEvent := func(id uuid.UUID, typ string) string {
		t.Helper()
		var s string
		if err := e.pg.QueryRow(ctx, `SELECT coalesce((SELECT content FROM crm.lead_events WHERE lead_id = $1 AND type = $2
			ORDER BY created_at DESC, id DESC LIMIT 1), '')`, id, typ).Scan(&s); err != nil {
			t.Fatal(err)
		}
		return s
	}
	leadRow := func(id uuid.UUID) (stage, score string) {
		t.Helper()
		if err := e.pg.QueryRow(ctx, `SELECT stage, coalesce(test_score, '') FROM crm.leads WHERE id = $1`, id).Scan(&stage, &score); err != nil {
			t.Fatal(err)
		}
		return
	}

	// Khách Mới không tự nhảy sang Test: giữ giai đoạn, nhật ký ghi rõ.
	if err := pl.Record(ctx, placement.LeadEvent{LeadID: na, Type: "test", AdvanceTo: "testing", Content: "Thí sinh mở link làm bài test [T1]."}); err != nil {
		t.Fatal(err)
	}
	if st, _ := leadRow(na); st != StageNew || lastEvent(na, "test") != "Thí sinh mở link làm bài test [T1]. Giữ nguyên giai đoạn hiện tại (Mới)." {
		t.Fatalf("giữ giai đoạn: %s %q", st, lastEvent(na, "test"))
	}

	// Đang tư vấn → chấm xong: Đã test, điểm ghi sang khách, có nhật ký chuyển giai đoạn.
	e.exec("UPDATE crm.leads SET stage = 'consulting' WHERE id = $1", na)
	score := "Listening 5 · Reading & Writing 7"
	if err := pl.Record(ctx, placement.LeadEvent{LeadID: na, ActorID: &tm.staffA.id, Type: "test", AdvanceTo: "tested",
		Content: "Học vụ đã chấm bài test #1.", TestScore: &score}); err != nil {
		t.Fatal(err)
	}
	if st, sc := leadRow(na); st != StageTested || sc != score || lastEvent(na, "test") != "Học vụ đã chấm bài test #1." {
		t.Fatalf("chấm xong: %s %q %q", st, sc, lastEvent(na, "test"))
	}
	var moved int
	if err := e.pg.QueryRow(ctx, `SELECT count(*) FROM crm.lead_events WHERE lead_id = $1 AND type = 'stage_change'
		AND to_stage = 'tested' AND reason = 'Học vụ đã chấm bài test #1.'`, na).Scan(&moved); err != nil || moved != 1 {
		t.Fatalf("nhật ký chuyển giai đoạn: %d %v", moved, err)
	}

	// Khách Thất bại giữ nguyên điểm; khách đã xóa bỏ qua.
	e.exec("UPDATE crm.leads SET stage = 'lost', lost_reason = 'x', lost_at = now() WHERE id = $1", bo)
	if err := pl.Record(ctx, placement.LeadEvent{LeadID: bo, Type: "test", AdvanceTo: "tested", Content: "Chấm.", TestScore: &score}); err != nil {
		t.Fatal(err)
	}
	if _, sc := leadRow(bo); sc != "" || lastEvent(bo, "test") != "Chấm. Giữ nguyên giai đoạn hiện tại (Thất bại)." {
		t.Fatalf("khách thất bại: %q %q", sc, lastEvent(bo, "test"))
	}
	if err := pl.Record(ctx, placement.LeadEvent{LeadID: uuid.New(), Type: "trial", Content: "x"}); err != nil {
		t.Fatalf("khách không có: %v", err)
	}

	// Khách chuyển Thất bại → buổi học thử đang hẹn hủy theo (buổi đã học giữ nguyên).
	e.exec(`INSERT INTO crm.trial_bookings (lead_id, class_id, class_session_id, session_date, status, notes) VALUES
		($1, $2, $3, current_date + 1, 'scheduled', 'Phụ huynh đưa đón'), ($1, $2, $4, current_date - 1, 'attended', NULL)`,
		na, uuid.New(), uuid.New(), uuid.New())
	expect(t, tm.adminC.do("POST", "/crm/leads/"+na.String()+"/stage", map[string]any{"stage": StageLost, "lost_reason": "Học phí cao"}), 200, "")
	var trials string
	if err := e.pg.QueryRow(ctx, `SELECT string_agg(status || ':' || coalesce(notes, ''), '|' ORDER BY session_date DESC)
		FROM crm.trial_bookings WHERE lead_id = $1`, na).Scan(&trials); err != nil {
		t.Fatal(err)
	}
	if trials != "cancelled:Phụ huynh đưa đón\nHủy: khách chuyển Thất bại|attended:" {
		t.Fatalf("học thử sau khi thất bại: %q", trials)
	}
}
