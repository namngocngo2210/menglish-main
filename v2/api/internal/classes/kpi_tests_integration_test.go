package classes

import (
	"context"
	"slices"
	"testing"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/kpi/kpisrc"
)

func recordTexts(rs []kpisrc.Record) []string {
	out := make([]string, 0, len(rs))
	for _, r := range rs {
		out = append(out, r.Text)
	}
	return out
}

// Nguồn KPI từ điểm test (KpiAutoCounter::testResult / testProgress / studentPassRate): Big Test đã duyệt / đã gửi (bỏ bài
// nháp, vắng thi) + mini test quy thang 10, lớp giáo viên dạy; đầu ra học sinh theo phạm vi lớp, cuốn chiếu 3 tháng.
func TestKpiTestSources(t *testing.T) {
	e := setup(t)
	ctx := context.Background()
	a, b := e.branch("CN-A"), e.branch("CN-B")
	admin := e.member("MES001", nil, iam.RoleAdmin)
	adminC := e.as(admin)
	gv := e.member("GV001", []uuid.UUID{a}, iam.RoleTeacherFulltime)
	c1 := e.newClass(adminC, map[string]any{"name": "Lớp Một", "code": "L1", "branch_id": a, "teacher_id": gv.id})
	cb := e.newClass(adminC, map[string]any{"name": "Lớp B", "code": "LB", "branch_id": b})
	e.exec("UPDATE academic.classes SET status = 'active' WHERE id IN ($1, $2)", c1, cb)
	st1, st2 := e.student("HV-00001", "Bé An", &a), e.student("HV-00002", "Bé Bình", &a)
	st3, st4 := e.student("HV-00003", "Bé Cúc", &b), e.student("HV-00004", "Bé Dung", &a)
	bigTest := func(code string, class uuid.UUID, at time.Time) uuid.UUID {
		var id uuid.UUID
		e.scalar(&id, `INSERT INTO syllabus.big_tests (code, title, class_id, test_type, scheduled_at, room)
			VALUES ($1, $1, $2, 'stage_end', $3, 'P1') RETURNING id`, code, class, at)
		return id
	}
	result := func(test, student uuid.UUID, score float64, status string, absent bool) {
		e.exec(`INSERT INTO syllabus.big_test_results (big_test_id, student_id, overall_score, status, is_absent)
			VALUES ($1, $2, $3, $4, $5)`, test, student, score, status, absent)
	}
	mini := func(class, student uuid.UUID, score, maxScore float64, day string) {
		e.exec(`INSERT INTO academic.mini_test_scores (class_id, student_id, name, score, max_score, test_date)
			VALUES ($1, $2, 'Unit 1', $3, $4, $5)`, class, student, score, maxScore, day)
	}
	bt1 := bigTest("BT-1", c1, time.Date(2026, 10, 5, 9, 0, 0, 0, vnZone))
	result(bt1, st1, 8, "approved", false)
	result(bt1, st2, 6, "sent", false)
	result(bt1, st4, 9, "draft", false) // nháp: chưa tính
	result(bt1, st3, 0, "approved", true)
	mini(c1, st1, 9, 10, "2026-10-20")
	mini(c1, st2, 15, 20, "2026-10-20")
	mini(c1, st1, 2, 10, "2026-07-30") // ngoài 3 tháng
	btb := bigTest("BT-B", cb, time.Date(2026, 8, 15, 9, 0, 0, 0, vnZone))
	result(btb, st3, 10, "approved", false)

	from := time.Date(2026, 10, 1, 0, 0, 0, 0, vnZone)
	to := time.Date(2026, 10, 31, 23, 59, 59, 0, vnZone)
	src := e.svc.KpiTestSources(func(_ context.Context, uid uuid.UUID) (*iam.Access, error) {
		if uid == admin.id {
			return e.access(admin), nil
		}
		return e.access(gv), nil
	})

	// Điểm 8, 6 (ngày 5/10) và 9, 7,5 (ngày 20/10): TB 7,625 ÷ 10 × 50 + 3/4 đạt × 50 = 75,6
	res, err := src["test_result"](ctx, gv.id, from, to)
	if err != nil || res.Value == nil || *res.Value != 75.6 || len(res.Records) != 1 ||
		res.Records[0].Text != "Lớp L1: 4 bài, điểm TB 7,6, đạt chuẩn 75%" {
		t.Fatalf("kết quả test: %+v %v", res, err)
	}
	res, err = src["test_progress"](ctx, gv.id, from, to)
	if err != nil || res.Value == nil || *res.Value != 17.9 || len(res.Records) != 1 || res.Records[0].Text != "Lớp L1: 7 → 8,3 (+17,9%)" {
		t.Fatalf("tiến bộ: %+v %v", res, err)
	}
	// Chỉ một ngày test trong kỳ → chưa đủ dữ liệu
	if res, err = src["test_progress"](ctx, gv.id, from, time.Date(2026, 10, 10, 23, 59, 59, 0, vnZone)); err != nil || res.Value != nil {
		t.Fatalf("tiến bộ một ngày: %+v %v", res, err)
	}
	if res, err = src["test_result"](ctx, admin.id, from, to); err != nil || res.Value != nil {
		t.Fatalf("admin không dạy lớp: %+v %v", res, err)
	}

	// Đầu ra học sinh từ 01/08: giáo viên chỉ thấy lớp L1 (3/4), admin thấy thêm LB (4/5)
	res, err = src["student_pass_rate"](ctx, gv.id, from, to)
	if err != nil || res.Value == nil || *res.Value != 75 {
		t.Fatalf("đầu ra GV: %+v %v", res, err)
	}
	if got := recordTexts(res.Records); !slices.Equal(got, []string{"Từ 01/08/2026 đến 31/10/2026: 3/4 bài đạt chuẩn",
		"Lớp L1: 3/4 bài đạt chuẩn (75%)"}) {
		t.Fatalf("diễn giải GV: %q", got)
	}
	res, err = src["student_pass_rate"](ctx, admin.id, from, to)
	if err != nil || res.Value == nil || *res.Value != 80 {
		t.Fatalf("đầu ra admin: %+v %v", res, err)
	}
	if got := recordTexts(res.Records); !slices.Equal(got, []string{"Từ 01/08/2026 đến 31/10/2026: 4/5 bài đạt chuẩn",
		"Lớp LB: 1/1 bài đạt chuẩn (100%)", "Lớp L1: 3/4 bài đạt chuẩn (75%)"}) {
		t.Fatalf("diễn giải admin: %q", got)
	}
}
