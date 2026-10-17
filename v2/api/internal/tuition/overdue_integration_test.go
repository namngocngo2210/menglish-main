package tuition

import (
	"context"
	"slices"
	"strings"
	"testing"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam/iamtest"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/students"
)

func TestOverdueScreenAndActions(t *testing.T) {
	e := setup(t)
	f := newFixture(e)
	today := e.svc.today()
	// An quá hạn 10 ngày (≥ mốc bắt buộc liên hệ 7), Bình mới quá hạn 2 ngày.
	e.exec(`UPDATE finance.tuitions SET due_date = $2::date - 10, status = 'overdue' WHERE id = $1`, f.ta, today)
	e.exec(`UPDATE finance.tuitions SET due_date = $2::date - 2 WHERE id = $1`, f.tb, today)
	hsU, _ := e.Member("HS01", "Nguyễn Văn An", []uuid.UUID{f.a}, iam.RoleStudent)
	e.exec(`UPDATE academic.students SET user_id = $2 WHERE id = $1`, f.sa, hsU.ID)

	iamtest.Expect(t, f.teacher.Do("GET", "/tuition/overdue", nil), 403, "forbidden")
	r := f.mgrA.Do("GET", "/tuition/overdue", nil)
	iamtest.Expect(t, r, 200, "")
	due := obj(r.Body["due"])
	if rows := list(due["serious_overdue"]); len(rows) != 1 || str(rows[0]["id"]) != f.ta.String() || len(list(due["new_overdue"])) != 0 {
		t.Fatalf("nhóm quá hạn chi nhánh A: %s", r.Raw)
	}
	bs := list(r.Body["stats_by_branch"])
	if len(bs) != 1 || str(bs[0]["branch_name"]) != "Chi nhánh CG" || num(bs[0]["total_debt"]) != 2_400_000 || num(bs[0]["overdue_count"]) != 1 {
		t.Fatalf("thống kê chi nhánh: %v", bs)
	}
	cs := list(r.Body["stats_by_class"])
	if len(cs) != 1 || str(cs[0]["class_code"]) != "IE-CG-01" || num(cs[0]["count"]) != 1 {
		t.Fatalf("thống kê lớp: %v", cs)
	}
	if rows := list(obj(f.admin.Do("GET", "/tuition/overdue", nil).Body["due"])["new_overdue"]); len(rows) != 1 || str(rows[0]["id"]) != f.tb.String() {
		t.Fatalf("Admin thấy Bình mới quá hạn: %v", rows)
	}

	// Đã liên hệ: ngoài phạm vi, sai dữ liệu, thành công.
	path := "/tuition/overdue/" + f.ta.String()
	iamtest.Expect(t, f.teacher.Do("POST", path+"/contacted", nil), 403, "forbidden")
	expectMsg(t, f.mgrB.Do("POST", path+"/contacted", nil), 403, outOfScope)
	expectMsg(t, f.mgrA.Do("POST", path+"/contacted", map[string]any{"contacted_at": time.Now().Add(48 * time.Hour).Format("2006-01-02T15:04")}),
		422, "Thời gian liên hệ không được ở tương lai.")
	expectMsg(t, f.mgrA.Do("POST", path+"/contacted", map[string]any{"note": strings.Repeat("a", 1001)}), 422, "Ghi chú tối đa 1000 ký tự.")
	expectMsg(t, f.mgrA.Do("POST", "/tuition/overdue/"+uuid.New().String()+"/contacted", nil), 404, "Không tìm thấy khoản học phí.")
	expectMsg(t, f.mgrA.Do("POST", path+"/contacted", map[string]any{"note": "Phụ huynh hẹn thứ 6 chuyển khoản"}), 200,
		"Đã ghi nhận liên hệ với phụ huynh học viên Nguyễn Văn An — chờ thu.")
	row := list(obj(f.mgrA.Do("GET", "/tuition/overdue", nil).Body["due"])["serious_overdue"])[0]
	if c := obj(row["last_contact"]); str(c["user_name"]) != "Quản lý Cầu Giấy" || str(c["note"]) != "Phụ huynh hẹn thứ 6 chuyển khoản" {
		t.Fatalf("liên hệ gần nhất: %v", row)
	}

	// Báo cáo Admin: mọi Admin nhận thông báo.
	expectMsg(t, f.mgrB.Do("POST", path+"/report-admin", nil), 403, outOfScope)
	expectMsg(t, f.mgrA.Do("POST", path+"/report-admin", map[string]any{"note": "Gọi 3 lần không nghe"}), 200,
		"Đã báo cáo Admin (1 người nhận) về khoản quá hạn của học viên Nguyễn Văn An.")
	if got := e.notified("overdue_report"); got != "AD01" {
		t.Fatalf("người nhận báo cáo: %q", got)
	}
	var body string
	if err := e.Pool.QueryRow(context.Background(), `SELECT body FROM core.notifications WHERE type = 'overdue_report'`).Scan(&body); err != nil ||
		body != "Quản lý Cầu Giấy báo cáo học viên Nguyễn Văn An (HV0001) lớp Lớp IE-CG-01 quá hạn 10 ngày, còn nợ 2.400.000 đ. Ghi chú: Gọi 3 lần không nghe" {
		t.Fatalf("nội dung báo cáo: %q %v", body, err)
	}
	if row := list(obj(f.mgrA.Do("GET", "/tuition/overdue", nil).Body["due"])["serious_overdue"])[0]; row["last_report_date"] == nil {
		t.Fatalf("chưa có ngày báo cáo: %v", row)
	}

	// Gửi nhắc theo mốc: quá hạn 10 ngày → T+3 (mặc định); gửi lại trong ngày không trùng.
	expectMsg(t, f.mgrB.Do("POST", path+"/remind", nil), 403, outOfScope)
	expectMsg(t, f.mgrA.Do("POST", path+"/remind", nil), 200, "Đã gửi nhắc mốc T+3 cho học viên Nguyễn Văn An (0912345678): Đã gửi theo mốc Cảnh báo quá hạn.")
	expectMsg(t, f.mgrA.Do("POST", path+"/upcoming-remind", nil), 200, "Đã gửi mốc Cảnh báo quá hạn trong hôm nay.")
	if got := e.notified("debt_reminder"); got != "HS01" {
		t.Fatalf("học viên nhận nhắc: %q", got)
	}
	if err := e.Pool.QueryRow(context.Background(), `SELECT body FROM core.notifications WHERE type = 'debt_reminder'`).Scan(&body); err != nil ||
		!strings.Contains(body, "lớp Lớp IE-CG-01 còn nợ 2.400.000 đ, hạn đóng "+today.AddDate(0, 0, -10).Format("02/01/2006")) {
		t.Fatalf("nội dung nhắc: %q %v", body, err)
	}

	// Mốc cấu hình: tắt mốc → không gửi; mốc không chọn kênh → không gửi.
	e.exec(`INSERT INTO finance.debt_reminder_rules (milestone_key, offset_days, title, template_content, is_enabled, channels)
		VALUES ('T+5', 5, 'Quá hạn 5 ngày', 'Nhắc {TEN_HOC_VIEN} lớp {ten_lop} nợ {so_tien} hạn {han_nop} {la}', true, '{}')`)
	expectMsg(t, f.mgrA.Do("POST", path+"/remind", nil), 200, "Chưa gửi được: Mốc nhắc T+5 chưa chọn kênh gửi.")
	e.exec(`UPDATE finance.debt_reminder_rules SET channels = '{portal}'`)
	expectMsg(t, f.mgrA.Do("POST", path+"/remind", nil), 200, "Đã gửi nhắc mốc T+5")
	if err := e.Pool.QueryRow(context.Background(), `SELECT body FROM core.notifications WHERE type = 'debt_reminder' ORDER BY created_at DESC, id DESC LIMIT 1`).
		Scan(&body); err != nil || body != "Nhắc Nguyễn Văn An lớp Lớp IE-CG-01 nợ 2.400.000 đ hạn "+today.AddDate(0, 0, -10).Format("02/01/2006")+" {la}" {
		t.Fatalf("mẫu tin cấu hình: %q %v", body, err)
	}
	e.exec(`UPDATE finance.debt_reminder_rules SET is_enabled = false`)
	// Chưa bật mốc nào → chọn mốc mặc định (T+3) đã gửi hôm nay.
	expectMsg(t, f.mgrA.Do("POST", path+"/remind", nil), 200, "Đã gửi mốc Cảnh báo quá hạn trong hôm nay.")
	e.exec(`UPDATE finance.tuitions SET debt_amount = 0 WHERE id = $1`, f.ta)
	expectMsg(t, f.mgrA.Do("POST", path+"/remind", nil), 200, "Chưa gửi được: Hợp đồng chưa có hạn đóng hoặc đã hết nợ.")
}

func TestMilestoneFor(t *testing.T) {
	for diff, want := range map[int]string{-9: "T-7", -4: "T-7", -3: "T-3", -1: "T-3", 0: "T0", 1: "T+3", 30: "T+3"} {
		if got := milestoneFor(nil, diff); got != want {
			t.Errorf("mặc định diff %d: %s, muốn %s", diff, got, want)
		}
	}
	rules := slices.Clone(defaultMilestones)
	for i := range rules {
		rules[i].IsEnabled = true
	}
	for diff, want := range map[int]string{-9: "T-7", -5: "T-7", -2: "T-3", 0: "T0", 2: "T0", 3: "T+3"} {
		if got := milestoneFor(rules, diff); got != want {
			t.Errorf("cấu hình diff %d: %s, muốn %s", diff, got, want)
		}
	}
	if got := renderTemplate("{TEN_HOC_VIEN}-{han_chot}-{x}", map[string]string{"{ten_hoc_vien}": "A", "{han_dong}": "B"}); got != "A-B-{x}" {
		t.Errorf("mẫu tin: %s", got)
	}
}

func TestScheduledTuitionJobs(t *testing.T) {
	e := setup(t)
	f := newFixture(e)
	ctx := context.Background()
	today := e.svc.today()
	at := func(h, m int) {
		e.svc.now = func() time.Time {
			return dayStart(today).Add(time.Duration(h)*time.Hour + time.Duration(m)*time.Minute)
		}
	}

	// Nhắc nợ hằng ngày: hạn đóng sau 3 ngày → T-3; khoản đang khất nợ bỏ qua.
	e.exec(`UPDATE finance.tuitions SET due_date = $2::date + 3 WHERE id = $1`, f.ta, today)
	e.exec(`UPDATE finance.tuitions SET due_date = $2::date + 3, reminder_paused_until = $2::date + 20 WHERE id = $1`, f.tb, today)
	at(8, 30)
	if n, err := e.svc.SendDueDebtReminders(ctx); err != nil || n != 1 {
		t.Fatalf("nhắc nợ: %d %v", n, err)
	}
	if n, _ := e.svc.SendDueDebtReminders(ctx); n != 1 || e.count(`SELECT count(*) FROM finance.debt_reminder_logs WHERE milestone = 'T-3'`) != 1 {
		t.Fatalf("chạy lại vẫn một bản ghi nhắc")
	}

	// SLA tiền mặt: phiếu tiền mặt hôm nay chưa nộp → sau 19:00 báo người xác nhận nộp tiền (Admin) + người thu.
	_, cashier := e.Member("TN01", "Thu ngân", []uuid.UUID{f.a}, iam.RoleSalesConsultant)
	_ = cashier
	e.exec(`INSERT INTO finance.receipts (code, tuition_id, student_id, amount, payment_method, status, payment_date, creator_id)
		VALUES ('PT-TM', $1, $2, 500000, 'cash', 'pending', $3, (SELECT id FROM iam.users WHERE code = 'TN01'))`, f.ta, f.sa, today)
	at(18, 0)
	if n, _ := e.svc.NotifyUndepositedCash(ctx); n != 0 {
		t.Fatalf("trước giờ chốt đã báo %d", n)
	}
	at(19, 30)
	if n, err := e.svc.NotifyUndepositedCash(ctx); err != nil || n == 0 {
		t.Fatalf("SLA tiền mặt: %d %v", n, err)
	}
	if n, _ := e.svc.NotifyUndepositedCash(ctx); n != 0 {
		t.Fatalf("báo trùng %d", n)
	}
	if got := e.notified("cash_deposit_overdue"); !strings.Contains(got, "AD01") || !strings.Contains(got, "TN01") || strings.Contains(got, "QL02") {
		t.Fatalf("người nhận SLA tiền mặt: %q", got)
	}

	// SLA hoàn phí: hồ sơ hoàn tiền lập 8 ngày trước đã quá hạn (7 ngày) → người duyệt hoàn tiền (Admin).
	e.exec(`INSERT INTO finance.refund_requests (student_id, type, reason, created_at) VALUES ($1, 'refund', 'Chuyển nhà', now() - interval '8 days')`, f.sa)
	if n, err := e.svc.NotifyRefundDeadlines(ctx); err != nil || n != 1 {
		t.Fatalf("SLA hoàn phí: %d %v", n, err)
	}
	if n, _ := e.svc.NotifyRefundDeadlines(ctx); n != 0 {
		t.Fatalf("SLA hoàn phí trùng")
	}
	if got := e.notified("refund_deadline"); got != "AD01" {
		t.Fatalf("người nhận SLA hoàn phí: %q", got)
	}

	// Bảo lưu theo lịch: bắt đầu hôm nay → đóng băng + Bảo lưu; hết hạn → học lại.
	stu := students.NewService(e.Pool, e.IAM, e.Log)
	stu.Tuition = e.svc
	e.svc.WithStudents(stu)
	e.exec(`UPDATE finance.tuitions SET deferred_from = $2::date, deferred_until = $2::date + 30 WHERE id = $1`, f.ta, today)
	if n, err := e.svc.StartDueDeferrals(ctx); err != nil || n != 1 {
		t.Fatalf("bắt đầu bảo lưu: %d %v", n, err)
	}
	var status string
	var frozen *int32
	var frozenDebt *int64
	if err := e.Pool.QueryRow(ctx, `SELECT s.status, t.frozen_remaining_sessions, t.frozen_debt_amount FROM academic.students s
		JOIN finance.tuitions t ON t.student_id = s.id WHERE s.id = $1`, f.sa).Scan(&status, &frozen, &frozenDebt); err != nil ||
		status != "deferred" || frozen == nil || *frozen != 24 || frozenDebt == nil || *frozenDebt != 2_400_000 {
		t.Fatalf("sau khi bắt đầu bảo lưu: %s %v %v %v", status, frozen, frozenDebt, err)
	}
	if n, _ := e.svc.StartDueDeferrals(ctx); n != 0 {
		t.Fatalf("bắt đầu bảo lưu hai lần")
	}
	e.exec(`UPDATE finance.tuitions SET deferred_from = $2::date - 40, deferred_until = $2::date - 1 WHERE id = $1`, f.ta, today)
	if n, err := e.svc.EndDueDeferrals(ctx); err != nil || n != 1 {
		t.Fatalf("hết bảo lưu: %d %v", n, err)
	}
	if err := e.Pool.QueryRow(ctx, `SELECT s.status, t.frozen_remaining_sessions FROM academic.students s
		JOIN finance.tuitions t ON t.student_id = s.id WHERE s.id = $1`, f.sa).Scan(&status, &frozen); err != nil || status != "waiting_start" || frozen != nil {
		t.Fatalf("sau khi hết bảo lưu: %s %v %v", status, frozen, err)
	}
	if !strings.Contains(e.notifiedBody("deferral_ended"), "Hết thời gian bảo lưu — học viên Nguyễn Văn An") {
		t.Fatalf("thông báo hết bảo lưu: %q", e.notifiedBody("deferral_ended"))
	}
}

func (e *env) notifiedBody(typ string) string {
	e.T.Helper()
	var body string
	_ = e.Pool.QueryRow(context.Background(), `SELECT body FROM core.notifications WHERE type = $1 ORDER BY created_at DESC LIMIT 1`, typ).Scan(&body)
	return body
}
