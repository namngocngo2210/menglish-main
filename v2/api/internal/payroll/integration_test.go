package payroll_test

import (
	"bytes"
	"context"
	"encoding/csv"
	"strings"
	"testing"
	"time"

	"github.com/go-chi/chi/v5"
	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/files"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam/iamtest"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/payroll"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/filestore"
)

var vn = time.FixedZone("ICT", 7*3600)

// "Hôm nay" 17/10/2026 09:00: kỳ tháng 9 đã qua hạn chốt (02/10), ngoài khung trả lương 10–15/10.
var now = time.Date(2026, 10, 17, 9, 0, 0, 0, vn)

var png = append([]byte("\x89PNG\r\n\x1a\n"), bytes.Repeat([]byte{1}, 64)...)

// fakeAttendance: ca dạy hợp lệ do test dựng (thay phân hệ Chấm công).
type fakeAttendance struct {
	sheets  []payroll.Timesheet
	changed bool
}

func (f *fakeAttendance) ValidTimesheets(_ context.Context, from, to time.Time) ([]payroll.Timesheet, error) {
	var out []payroll.Timesheet
	for _, s := range f.sheets {
		if !s.Date.Before(from) && !s.Date.After(to) {
			out = append(out, s)
		}
	}
	return out, nil
}
func (f *fakeAttendance) Summary(context.Context, uuid.UUID, time.Time, time.Time) (map[string]any, error) {
	return nil, nil
}
func (f *fakeAttendance) ChangedSince(context.Context, time.Time, time.Time, time.Time) (bool, error) {
	return f.changed, nil
}
func (f *fakeAttendance) Pending() bool { return false }

type env struct {
	*iamtest.Env
	svc *payroll.Service
	att *fakeAttendance
}

func setup(t *testing.T) *env {
	e := iamtest.Setup(t, "hr.payroll_records", "hr.payroll_periods", "hr.penalties", "hr.teacher_rates", "hr.teacher_hourly_rates",
		"hr.commission_items", "hr.commission_adjustments", "core.audit_log", "core.notifications", "crm.leads", "finance.receipts",
		"finance.tuitions", "academic.students")
	ctx := context.Background()
	for _, q := range []string{
		`DELETE FROM core.settings WHERE key LIKE 'payroll\_%'`,
		`DELETE FROM hr.commission_tiers`,
		`INSERT INTO hr.commission_tiers (tier_name, min_students, max_students, new_sale_percent, effective_from) VALUES
			('Mốc 1', 0, 5, 3, '2025-01-01'), ('Mốc 2', 6, 10, 4, '2025-01-01'), ('Mốc 3', 11, NULL, 5, '2025-01-01')`,
	} {
		if _, err := e.Pool.Exec(ctx, q); err != nil {
			t.Fatal(err)
		}
	}
	store, err := filestore.NewLocal(t.TempDir(), files.APIBase+files.BlobPrefix, bytes.Repeat([]byte{7}, 32))
	if err != nil {
		t.Fatal(err)
	}
	fsvc := files.NewService(e.Pool, store, e.Log, 0)
	att := &fakeAttendance{}
	svc := payroll.NewService(e.Pool, e.Log).WithAttendance(att).WithFiles(fsvc)
	svc.SetClock(func() time.Time { return now })
	fh := files.NewHandler(fsvc, e.Log)
	e.Serve(func(r chi.Router) {
		payroll.NewHandler(svc, e.Log).Routes(r)
		fh.Routes(r)
	})
	return &env{Env: e, svc: svc, att: att}
}

func (e *env) exec(sql string, args ...any) {
	e.T.Helper()
	if _, err := e.Pool.Exec(context.Background(), sql, args...); err != nil {
		e.T.Fatal(err)
	}
}

func (e *env) id(sql string, args ...any) uuid.UUID {
	e.T.Helper()
	var id uuid.UUID
	if err := e.Pool.QueryRow(context.Background(), sql, args...).Scan(&id); err != nil {
		e.T.Fatal(err)
	}
	return id
}

func (e *env) count(sql string, args ...any) int {
	e.T.Helper()
	var n int
	if err := e.Pool.QueryRow(context.Background(), sql, args...).Scan(&n); err != nil {
		e.T.Fatal(err)
	}
	return n
}

func (e *env) profile(user uuid.UUID, base, hourly int64, contract string) {
	e.exec(`INSERT INTO iam.staff_profiles (user_id, base_salary, hourly_rate, contract_type) VALUES ($1, $2, $3, $4)`,
		user, base, hourly, contract)
}

// sale: học viên chốt từ khách CRM của owner (đủ 3 mốc chăm sóc), khoản học phí đầu tiên + phiếu thu đã duyệt.
func (e *env) sale(owner uuid.UUID, code string, convertedAt, approvedAt time.Time, amount int64) (student, receipt uuid.UUID) {
	e.T.Helper()
	student = e.id(`INSERT INTO academic.students (code, full_name, phone) VALUES ($1, $2, '0900000000') RETURNING id`, code, "Học viên "+code)
	e.exec(`INSERT INTO crm.leads (code, name, phone, stage, converted_student_id, converted_at, assigned_user_id, care_checklist, created_at)
		VALUES ($1, $2, '0900000000', 'won', $3, $4, $5, '{"session_1":true,"session_4_5":true,"day_30":true}', $4)`,
		"L"+code, "Khách "+code, student, convertedAt, owner)
	tuition := e.id(`INSERT INTO finance.tuitions (student_id, total_amount, final_amount) VALUES ($1, $2, $2) RETURNING id`, student, amount)
	receipt = e.id(`INSERT INTO finance.receipts (code, tuition_id, student_id, amount, tuition_amount, payment_method, status, approved_at)
		VALUES ($1, $2, $3, $4, $4, 'cash', 'approved', $5) RETURNING id`, "PT"+code, tuition, student, amount, approvedAt)
	return student, receipt
}

func expectMsg(t *testing.T, r iamtest.Resp, status int, msg string) {
	t.Helper()
	if r.Status != status || r.Body["message"] != msg {
		t.Fatalf("muốn %d %q, nhận %d %s", status, msg, r.Status, r.Raw)
	}
}

func num(v any) int64 {
	f, _ := v.(float64)
	return int64(f)
}

func recordOf(t *testing.T, r iamtest.Resp, user uuid.UUID) map[string]any {
	t.Helper()
	for _, x := range iamtest.List(t, r, "records") {
		if x["user_id"] == user.String() {
			return x
		}
	}
	t.Fatalf("không có phiếu của %s: %s", user, r.Raw)
	return nil
}

func TestPayrollPermissions(t *testing.T) {
	e := setup(t)
	hn := e.Branch("HN01")
	iamtest.Expect(t, e.Anon().Do("GET", "/payroll/periods", nil), 401, "")
	iamtest.Expect(t, e.Anon().Do("GET", "/penalties", nil), 401, "")

	_, teacher := e.Member("GV01", "Giáo viên", []uuid.UUID{hn}, iam.RoleTeacherParttime)
	for _, p := range []string{"/payroll/periods", "/payroll/periods/" + uuid.NewString(), "/payroll/records/" + uuid.NewString(),
		"/payroll/config/settings", "/payroll/config/teacher-rates", "/payroll/config/commission-tiers", "/payroll/kpi-leaderboard"} {
		iamtest.Expect(t, teacher.Do("GET", p, nil), 403, "")
	}
	iamtest.Expect(t, teacher.Do("POST", "/payroll/periods", map[string]any{"month": 9, "year": 2026}), 403, "")
	iamtest.Expect(t, teacher.Do("POST", "/penalties", map[string]any{}), 403, "")
	r := teacher.Do("GET", "/portal/my-salary", nil)
	iamtest.Expect(t, r, 200, "")
	if r.Body["record"] != nil {
		t.Fatalf("chưa có kỳ nào khóa: %s", r.Raw)
	}
	iamtest.Expect(t, teacher.Do("GET", "/penalties", nil), 200, "")

	_, student := e.Member("HS01", "Học viên", nil, iam.RoleStudent)
	iamtest.Expect(t, student.Do("GET", "/penalties", nil), 403, "")
	iamtest.Expect(t, student.Do("GET", "/portal/my-salary", nil), 403, "")

	// Quản lý: cấu hình lương được, bảng lương thì không (mặc định chỉ xem lương của mình).
	_, mgr := e.Member("QL01", "Quản lý", []uuid.UUID{hn}, iam.RoleManager)
	iamtest.Expect(t, mgr.Do("GET", "/payroll/periods", nil), 403, "")
	iamtest.Expect(t, mgr.Do("POST", "/payroll/periods/"+uuid.NewString()+"/approve", nil), 403, "")
	iamtest.Expect(t, mgr.Do("GET", "/payroll/config/settings", nil), 200, "")
	iamtest.Expect(t, mgr.Do("GET", "/payroll/config/commission-tiers", nil), 200, "")
	iamtest.Expect(t, mgr.Do("GET", "/payroll/kpi-leaderboard", nil), 200, "")

	// Kế toán không có violation.create: không lập biên bản
	_, sales := e.Member("TV01", "Tư vấn", []uuid.UUID{hn}, iam.RoleSalesConsultant)
	iamtest.Expect(t, sales.Do("POST", "/penalties", map[string]any{}), 403, "")
	iamtest.Expect(t, sales.Do("POST", "/penalties/"+uuid.NewString()+"/confirm", map[string]any{}), 403, "")
}

// lifecycle: kỳ tháng 9/2026 — GV part-time 2 ca, GV full-time có biên bản quá hạn nộp, tư vấn có hoa hồng.
type lifecycle struct {
	*env
	admin                *iamtest.Client
	pt, ft, sale         iamtest.User
	ptClient, saleClient *iamtest.Client
	hn, hcm              uuid.UUID
	receipt, student     uuid.UUID
	penalty              uuid.UUID
}

func newLifecycle(t *testing.T) *lifecycle {
	e := setup(t)
	l := &lifecycle{env: e}
	l.hn, l.hcm = e.Branch("HN01"), e.Branch("HCM01")
	_, l.admin = e.Member("AD01", "Quản trị", nil, iam.RoleAdmin)
	l.pt, l.ptClient = e.Member("GV01", "An Part-time", []uuid.UUID{l.hn}, iam.RoleTeacherParttime)
	l.ft = e.User("GV02", "Bình Full-time", []uuid.UUID{l.hn}, iam.RoleTeacherFulltime)
	l.sale, l.saleClient = e.Member("TV01", "Chi Tư vấn", []uuid.UUID{l.hcm}, iam.RoleSalesConsultant)
	e.profile(l.pt.ID, 0, 200000, "Bán thời gian")
	e.profile(l.ft.ID, 10000000, 0, "Toàn thời gian")
	e.profile(l.sale.ID, 8000000, 0, "Toàn thời gian")
	day := func(d int) time.Time { return time.Date(2026, 9, d, 0, 0, 0, 0, vn) }
	e.att.sheets = []payroll.Timesheet{
		{ID: uuid.New(), UserID: l.pt.ID, Date: day(10), ScheduledTime: "18:00-19:30", Hours: 1.5, Type: "regular"},
		{ID: uuid.New(), UserID: l.pt.ID, Date: day(17), ScheduledTime: "18:00-19:30", Hours: 1.5, LateMinutes: 10, Type: "regular"},
	}
	l.student, l.receipt = e.sale(l.sale.ID, "HV001", time.Date(2026, 8, 1, 10, 0, 0, 0, vn), time.Date(2026, 9, 5, 10, 0, 0, 0, vn), 5000000)
	l.penalty = e.id(`INSERT INTO hr.penalties (code, user_id, violation_type, error_category, violation_date, amount, status, due_date)
		VALUES ('BB-2026-901', $1, 'Nghỉ dạy không phép', 'operations', '2026-09-15', 200000, 'fined', '2026-09-20') RETURNING id`, l.ft.ID)
	return l
}

func (l *lifecycle) create(t *testing.T) uuid.UUID {
	t.Helper()
	r := l.admin.Do("POST", "/payroll/periods", map[string]any{"month": 9, "year": 2026})
	iamtest.Expect(t, r, 201, "")
	if !strings.HasPrefix(r.Body["message"].(string), "Đã khởi tạo và tự động tính toán bảng lương") {
		t.Fatalf("thông báo tạo kỳ: %s", r.Raw)
	}
	return uuid.MustParse(r.Body["id"].(string))
}

func TestPayrollPeriodLifecycle(t *testing.T) {
	l := newLifecycle(t)
	admin := l.admin

	for _, c := range []struct {
		in  map[string]any
		msg string
	}{
		{map[string]any{"year": 2026}, "Vui lòng chọn tháng."},
		{map[string]any{"month": 13, "year": 2026}, "Tháng phải từ 1 đến 12."},
		{map[string]any{"month": 9}, "Vui lòng nhập năm."},
		{map[string]any{"month": 9, "year": 2024}, "Năm phải từ 2025 đến 2100."},
	} {
		expectMsg(t, admin.Do("POST", "/payroll/periods", c.in), 422, c.msg)
	}
	id := l.create(t)
	expectMsg(t, admin.Do("POST", "/payroll/periods", map[string]any{"month": 9, "year": 2026}), 422, "Đã tồn tại bảng lương cho tháng 9/2026.")

	r := admin.Do("GET", "/payroll/periods", nil)
	iamtest.Expect(t, r, 200, "")
	if ps := iamtest.List(t, r, "periods"); len(ps) != 1 || ps[0]["code"] != "PR-2026-09" || ps[0]["title"] != "Bảng lương Tháng 9/2026" {
		t.Fatalf("danh sách kỳ: %s", r.Raw)
	}

	r = admin.Do("GET", "/payroll/periods/"+id.String(), nil)
	iamtest.Expect(t, r, 200, "")
	if n := len(iamtest.List(t, r, "records")); n != 3 {
		t.Fatalf("muốn 3 phiếu, có %d: %s", n, r.Raw)
	}
	pt := recordOf(t, r, l.pt.ID)
	// 2 ca × 1,5h × 200.000 = 600.000; ca đi muộn 10 phút (dưới ngưỡng 15) trừ 10 × 5.000.
	if num(pt["teaching_salary"]) != 550000 || num(pt["teaching_sessions"]) != 2 || num(pt["net_salary"]) != 550000 ||
		num(pt["insurance_deduction"]) != 0 || pt["is_part_time"] != true {
		t.Fatalf("phiếu part-time: %v", pt)
	}
	ft := recordOf(t, r, l.ft.ID)
	if num(ft["base_salary"]) != 10000000 || num(ft["insurance_deduction"]) != 1050000 || num(ft["union_deduction"]) != 50000 ||
		num(ft["other_deductions"]) != 200000 || ft["kpi_state"].([]any)[0] != "pending" {
		t.Fatalf("phiếu full-time: %v", ft)
	}
	sale := recordOf(t, r, l.sale.ID)
	if num(sale["commission_bonus"]) != 150000 || num(sale["net_salary"]) != 8000000+150000-840000-40000 {
		t.Fatalf("phiếu tư vấn: %v", sale)
	}
	if e := l.count(`SELECT count(*) FROM hr.penalties WHERE payroll_record_id IS NOT NULL`); e != 1 {
		t.Fatalf("biên bản chưa gắn phiếu: %d", e)
	}

	// Chưa chốt KPI của 2 nhân sự full-time → chặn duyệt.
	r = admin.Do("POST", "/payroll/periods/"+id.String()+"/approve", nil)
	iamtest.Expect(t, r, 422, "invalid")
	if msg := r.Body["message"].(string); !strings.HasPrefix(msg, "Chưa thể chốt bảng lương: còn 2 nhân sự chưa chốt KPI (") {
		t.Fatalf("thông báo KPI: %s", msg)
	}

	// Nhập tay: validation rồi chốt KPI.
	ftID := ft["id"].(string)
	expectMsg(t, admin.Do("POST", "/payroll/records/"+ftID+"/adjust", map[string]any{"retention_tier": 12000}), 422,
		"Bậc KPI giữ học sinh phải là một trong: 15.000 đ / 20.000 đ / 25.000 đ.")
	expectMsg(t, admin.Do("POST", "/payroll/records/"+ftID+"/adjust", map[string]any{"lines": []map[string]any{{"kind": "earning", "label": " ", "amount": 1}}}),
		422, "Mỗi khoản cộng / trừ tự do cần có tên (VD: Hỗ trợ thỏa thuận, Gửi xe, Thưởng khác).")
	expectMsg(t, admin.Do("POST", "/payroll/records/"+ftID+"/adjust", map[string]any{"kpi_manual_amount": 500000,
		"lines": []map[string]any{{"kind": "earning", "label": "Gửi xe", "amount": 100000}}}), 200, "Đã lưu các khoản nhập tay — thực lĩnh mới 9.300.000 đ.")
	expectMsg(t, admin.Do("POST", "/payroll/records/"+sale["id"].(string)+"/adjust", map[string]any{"kpi_manual_amount": 0}), 200,
		"Đã lưu các khoản nhập tay — thực lĩnh mới 7.270.000 đ.")
	// Tính lại vẫn giữ khoản nhập tay
	expectMsg(t, admin.Do("POST", "/payroll/periods/"+id.String()+"/calculate", nil), 200, "Đã đồng bộ chấm công và tính lại bảng lương.")
	r = admin.Do("GET", "/payroll/records/"+ftID, nil)
	iamtest.Expect(t, r, 200, "")
	if rec := r.Body["record"].(map[string]any); num(rec["net_salary"]) != 9300000 || num(rec["kpi_bonus"]) != 500000 {
		t.Fatalf("phiếu sau tính lại: %v", rec)
	}

	// Chấm công đổi sau lần tính → phải tính lại.
	l.att.changed = true
	expectMsg(t, admin.Do("POST", "/payroll/periods/"+id.String()+"/approve", nil), 422,
		"Chấm công hoặc biên bản phạt của kỳ đã thay đổi sau lần tính gần nhất — vui lòng bấm \"Đồng bộ & Tính lại\" trước khi duyệt.")
	l.att.changed = false

	expectMsg(t, admin.Do("POST", "/payroll/periods/"+id.String()+"/approve", nil), 200, "Đã phê duyệt bảng lương Bảng lương Tháng 9/2026!")
	if n := l.count(`SELECT count(*) FROM hr.penalties WHERE id = $1 AND status = 'deducted'`, l.penalty); n != 1 {
		t.Fatal("biên bản chưa chuyển Đã trừ lương")
	}
	if n := l.count(`SELECT count(*) FROM hr.commission_items WHERE settled_at IS NOT NULL AND amount = 150000`); n != 1 {
		t.Fatal("khoản hoa hồng chưa chốt sổ")
	}
	if n := l.count(`SELECT count(*) FROM core.notifications WHERE type = 'payroll_approved'`); n != 3 {
		t.Fatalf("thông báo duyệt lương: %d", n)
	}
	if n := l.count(`SELECT count(*) FROM core.audit_log WHERE action = 'payroll_period.approve'`); n != 1 {
		t.Fatalf("nhật ký duyệt: %d", n)
	}
	expectMsg(t, admin.Do("POST", "/payroll/periods/"+id.String()+"/approve", nil), 422, "Kỳ lương đã khóa.")
	expectMsg(t, admin.Do("POST", "/payroll/periods/"+id.String()+"/calculate", nil), 422, "Không thể tính lại kỳ lương đã duyệt hoặc đã chi trả.")
	expectMsg(t, admin.Do("POST", "/payroll/records/"+ftID+"/adjust", map[string]any{"kpi_manual_amount": 1}), 422,
		"Không thể sửa phiếu lương của kỳ đã duyệt/đã chi trả.")

	// Khóa KPI theo kỳ lương
	if ok, err := l.svc.Locked(context.Background(), time.Date(2026, 9, 20, 0, 0, 0, 0, vn)); err != nil || !ok {
		t.Fatalf("ngày 20/09 phải khóa: %v %v", ok, err)
	}
	if ok, _ := l.svc.Locked(context.Background(), time.Date(2026, 10, 1, 0, 0, 0, 0, vn)); ok {
		t.Fatal("ngày 01/10 chưa khóa")
	}
	// Thu chi: dòng chi lương tự động của tháng 9
	sal, err := l.svc.Finance().Salary(context.Background(), time.Date(2026, 9, 1, 0, 0, 0, 0, vn), nil)
	if err != nil || sal == nil || sal.Amount != 550000+9300000+7270000 || sal.StaffCount != 3 || sal.Code != "PR-2026-09" {
		t.Fatalf("dòng chi lương: %+v %v", sal, err)
	}
	if sal, _ := l.svc.Finance().Salary(context.Background(), time.Date(2026, 9, 1, 0, 0, 0, 0, vn), &l.hcm); sal == nil || sal.Amount != 7270000 {
		t.Fatalf("dòng chi lương chi nhánh HCM: %+v", sal)
	}
	if n, _ := l.svc.OpenPeriods(context.Background()); n == nil || *n != 0 {
		t.Fatalf("kỳ chưa chốt: %v", n)
	}

	// Lương của tôi (kỳ đã khóa)
	r = l.ptClient.Do("GET", "/portal/my-salary", nil)
	iamtest.Expect(t, r, 200, "")
	if rec, _ := r.Body["record"].(map[string]any); rec == nil || num(rec["net_salary"]) != 550000 {
		t.Fatalf("lương của tôi: %s", r.Raw)
	}
	iamtest.Expect(t, l.ptClient.Do("GET", "/portal/my-salary?period_id="+uuid.NewString(), nil), 404, "")
	// GV không xem phiếu người khác
	iamtest.Expect(t, l.ptClient.Do("GET", "/payroll/records/"+ftID, nil), 403, "")

	// Xuất CSV
	r = admin.Do("GET", "/payroll/periods/"+id.String()+"/export", nil)
	iamtest.Expect(t, r, 200, "")
	rows, err := csv.NewReader(bytes.NewReader(bytes.TrimPrefix(r.Raw, []byte("\xEF\xBB\xBF")))).ReadAll()
	if err != nil || len(rows) != 4 || rows[0][0] != "Nhân sự" || !strings.Contains(r.Header.Get("Content-Disposition"), "bang-luong-pr-2026-09") {
		t.Fatalf("CSV: %v %v %s", err, rows, r.Header.Get("Content-Disposition"))
	}
	expectMsg(t, admin.Do("GET", "/payroll/periods/"+id.String()+"/export?department=abc", nil), 422, "Khối không hợp lệ.")
	r = admin.Do("GET", "/payroll/periods/"+id.String()+"/departments/operations", nil)
	iamtest.Expect(t, r, 200, "")

	// Chi trả: ngoài khung 10–15 chỉ cảnh báo
	r = admin.Do("POST", "/payroll/periods/"+id.String()+"/mark-paid", nil)
	iamtest.Expect(t, r, 200, "")
	if r.Body["message"] != "Đã ghi nhận chi trả bảng lương Bảng lương Tháng 9/2026!" ||
		r.Body["warning"] != "Lưu ý: lương thường trả từ ngày 10/10 đến 15/10 — hôm nay nằm ngoài khung trả lương." {
		t.Fatalf("chi trả: %s", r.Raw)
	}
	expectMsg(t, admin.Do("POST", "/payroll/periods/"+id.String()+"/mark-paid", nil), 422, "Chỉ kỳ lương đã duyệt mới được đánh dấu đã chi trả.")

	// Bảng xếp hạng KPI & Thưởng: 1 phiếu thu của HS chốt từ tháng 8 (tháng 9 chưa chốt HS mới)
	r = admin.Do("GET", "/payroll/kpi-leaderboard?month=9&year=2026", nil)
	iamtest.Expect(t, r, 200, "")
	sales := iamtest.List(t, r, "sales")
	if len(sales) != 1 || num(sales[0]["deals"]) != 1 || num(sales[0]["closed"]) != 0 || num(sales[0]["commission"]) != 150000 {
		t.Fatalf("xếp hạng tư vấn: %s", r.Raw)
	}

	// Hủy hóa đơn sau khi kỳ đã duyệt → khoản thu hồi ở kỳ sau.
	var claw *struct{ amt int64 }
	err = pgx.BeginFunc(context.Background(), l.Pool, func(tx pgx.Tx) error {
		c, err := l.svc.Commissions().CancellationClawback(context.Background(), tx, l.receipt, "HD0001", l.pt.ID)
		if c != nil {
			claw = &struct{ amt int64 }{c.Amount}
			if c.UserName != "Chi Tư vấn" {
				t.Errorf("người bị thu hồi: %s", c.UserName)
			}
		}
		return err
	})
	if err != nil || claw == nil || claw.amt != -150000 {
		t.Fatalf("thu hồi khi hủy hóa đơn: %v %v", claw, err)
	}
	if n := l.count(`SELECT count(*) FROM hr.commission_adjustments WHERE amount = -150000 AND reason LIKE 'Thu hồi hoa hồng do hủy hóa đơn HD0001 (phiếu PTHV001)%'`); n != 1 {
		t.Fatal("chưa ghi khoản thu hồi")
	}
	// Gợi ý thu hồi khi hoàn phí: min(hoàn, căn cứ đã trả) × 3%
	if v, err := l.svc.Commissions().SuggestedClawback(context.Background(), l.student, l.sale.ID, 2000000); err != nil || v != 60000 {
		t.Fatalf("gợi ý thu hồi: %d %v", v, err)
	}

	// Kỳ tháng 10: khoản thu hồi trừ vào phiếu tư vấn; chưa tới hạn chốt.
	r = admin.Do("POST", "/payroll/periods", map[string]any{"month": 10, "year": 2026})
	iamtest.Expect(t, r, 201, "")
	oct := r.Body["id"].(string)
	r = admin.Do("GET", "/payroll/periods/"+oct, nil)
	if s := recordOf(t, r, l.sale.ID); num(s["other_deductions"]) < 150000 {
		t.Fatalf("phiếu tháng 10 chưa trừ thu hồi: %v", s)
	}
	expectMsg(t, admin.Do("POST", "/payroll/periods/"+oct+"/approve", nil), 422,
		"Chưa thể chốt bảng lương: chốt công và chốt lỗi vào hết ngày 02/11 (cuối tháng + 2 ngày) — vui lòng chốt từ ngày 03/11.")
	if n, _ := l.svc.OpenPeriods(context.Background()); n == nil || *n != 1 {
		t.Fatalf("kỳ chưa chốt: %v", n)
	}
}

func TestPayrollBranchScope(t *testing.T) {
	l := newLifecycle(t)
	id := l.create(t)
	l.Grant(iam.RoleManager, "payroll.view", "payroll.scope_branch")
	_, mgr := l.Member("QL01", "Quản lý HN", []uuid.UUID{l.hn}, iam.RoleManager)
	r := mgr.Do("GET", "/payroll/periods/"+id.String(), nil)
	iamtest.Expect(t, r, 200, "")
	recs := iamtest.List(t, r, "records")
	if len(recs) != 2 {
		t.Fatalf("quản lý HN chỉ thấy 2 phiếu chi nhánh HN: %s", r.Raw)
	}
	for _, x := range recs {
		if x["user_id"] == l.sale.ID.String() {
			t.Fatal("thấy phiếu chi nhánh khác")
		}
	}
	all := l.admin.Do("GET", "/payroll/periods/"+id.String(), nil)
	saleID := recordOf(t, all, l.sale.ID)["id"].(string)
	iamtest.Expect(t, mgr.Do("GET", "/payroll/records/"+saleID, nil), 404, "")
	iamtest.Expect(t, mgr.Do("POST", "/payroll/records/"+saleID+"/adjust", map[string]any{}), 403, "")

	// Tư vấn chỉ xem phiếu của mình qua Lương của tôi (kỳ chưa khóa → chưa có)
	r = l.saleClient.Do("GET", "/portal/my-salary", nil)
	iamtest.Expect(t, r, 200, "")
	if r.Body["record"] != nil {
		t.Fatalf("kỳ chưa khóa không hiện: %s", r.Raw)
	}
}

func TestCommissionRefundClawback(t *testing.T) {
	e := setup(t)
	hn := e.Branch("HN01")
	sale := e.User("TV01", "Chi Tư vấn", []uuid.UUID{hn}, iam.RoleSalesConsultant)
	approver := e.User("KT01", "Kế toán", []uuid.UUID{hn}, iam.RoleManager)
	// Phiếu duyệt 20/10 (chưa đủ 30 ngày từ ngày chốt 15/10): khoản hoa hồng còn hoãn.
	student, _ := e.sale(sale.ID, "HV002", time.Date(2026, 10, 15, 9, 0, 0, 0, vn), time.Date(2026, 10, 16, 9, 0, 0, 0, vn), 4000000)
	_, admin := e.Member("AD01", "Quản trị", nil, iam.RoleAdmin)
	r := admin.Do("POST", "/payroll/periods", map[string]any{"month": 10, "year": 2026})
	iamtest.Expect(t, r, 201, "")
	if n := e.count(`SELECT count(*) FROM hr.commission_items WHERE status <> 'void' AND deferred_reason LIKE 'Hoãn: chưa đủ 30 ngày%'`); n != 1 {
		t.Fatalf("khoản hoãn: %d", n)
	}
	// Chưa trả khoản nào → gợi ý 0
	if v, err := e.svc.Commissions().SuggestedClawback(context.Background(), student, sale.ID, 1000000); err != nil || v != 0 {
		t.Fatalf("gợi ý: %d %v", v, err)
	}
	refund := e.id(`INSERT INTO finance.refund_requests (student_id, type, reason, status) VALUES ($1, 'refund', 'Chuyển nhà', 'approved') RETURNING id`, student)
	err := pgx.BeginFunc(context.Background(), e.Pool, func(tx pgx.Tx) error {
		return e.svc.Commissions().RecordClawback(context.Background(), tx, refund, student, sale.ID, 50000, approver.ID)
	})
	if err != nil {
		t.Fatal(err)
	}
	ref := "#" + strings.ToUpper(refund.String()[len(refund.String())-6:])
	if n := e.count(`SELECT count(*) FROM hr.commission_items WHERE status = 'void' AND deferred_reason = $1`, "Hủy do duyệt hoàn phí "+ref+" có thu hồi hoa hồng"); n != 1 {
		t.Fatal("khoản hoãn chưa bị hủy")
	}
	if n := e.count(`SELECT count(*) FROM hr.commission_adjustments WHERE amount = -50000 AND reason = $1`,
		"Thu hồi hoa hồng do hoàn phí "+ref+" (học viên Học viên HV002)"); n != 1 {
		t.Fatal("chưa ghi khoản thu hồi")
	}
}

func TestPayrollConfig(t *testing.T) {
	e := setup(t)
	hn := e.Branch("HN01")
	_, mgr := e.Member("QL01", "Quản lý", []uuid.UUID{hn}, iam.RoleManager)
	teacher := e.User("GV01", "Giáo viên", []uuid.UUID{hn}, iam.RoleTeacherParttime)

	r := mgr.Do("GET", "/payroll/config/settings", nil)
	iamtest.Expect(t, r, 200, "")
	base := map[string]any{"insurance_rate_percent": 10.5, "union_rate_percent": 0.5, "academic_kpi_fund": 2000000}
	with := func(k string, v any) map[string]any {
		out := map[string]any{}
		for a, b := range base {
			out[a] = b
		}
		out[k] = v
		return out
	}
	for _, c := range []struct {
		in  map[string]any
		msg string
	}{
		{with("insurance_rate_percent", nil), "Vui lòng nhập đầy đủ các tham số."},
		{with("union_rate_percent", 120), "Tỉ lệ BHXH / Công đoàn phải từ 0 đến 100%."},
		{with("academic_kpi_fund", -1), "Quỹ KPI Học vụ không được âm."},
		{with("late_threshold_minutes", 0), "Ngưỡng phút đi muộn phải từ 1 đến 120."},
		{with("late_deduction_per_minute", 3000), "Đơn giá trừ mỗi phút đi muộn phải từ 4.000đ đến 5.000đ."},
		{with("renewal", []map[string]any{{"quits": 0, "percent": 1}, {"quits": 0, "percent": 2}}), "Mỗi mức số HS nghỉ chỉ được khai báo một lần."},
	} {
		expectMsg(t, mgr.Do("POST", "/payroll/config/settings", c.in), 422, c.msg)
	}
	expectMsg(t, mgr.Do("POST", "/payroll/config/settings", with("insurance_rate_percent", 8)), 200,
		"Đã lưu tham số tính lương — áp dụng cho các lần tính/tính lại kỳ lương sau.")
	r = mgr.Do("GET", "/payroll/config/settings", nil)
	if st, _ := r.Body["settings"].(map[string]any); st == nil || st["insurance_rate_percent"] != 8.0 {
		t.Fatalf("tham số sau lưu: %s", r.Raw)
	}
	expectMsg(t, mgr.Do("POST", "/payroll/config/renewal-bonus", map[string]any{"renewal": []any{}}), 422, "Cần ít nhất một mốc thưởng tái tục.")
	expectMsg(t, mgr.Do("POST", "/payroll/config/renewal-bonus", map[string]any{"renewal": []map[string]any{{"quits": 0, "percent": 1.5}}}), 200,
		"Đã lưu bảng thưởng tái tục — áp dụng cho các lần tính / tính lại kỳ lương sau.")

	// Đơn giá giáo viên
	rate := func(patch map[string]any) map[string]any {
		in := map[string]any{"user_id": teacher.ID.String(), "hourly_rate": 220000, "rate_unit": "hour", "teacher_type": "parttime",
			"effective_from": "2026-10-01"}
		for k, v := range patch {
			in[k] = v
		}
		return in
	}
	for _, c := range []struct {
		patch map[string]any
		msg   string
	}{
		{map[string]any{"user_id": ""}, "Vui lòng chọn giáo viên."},
		{map[string]any{"rate_unit": "day"}, "Đơn vị đơn giá không hợp lệ."},
		{map[string]any{"hourly_rate": nil}, "Vui lòng nhập đơn giá."},
		{map[string]any{"hourly_rate": 500}, "Đơn giá tối thiểu 1.000đ."},
		{map[string]any{"rate_unit": "tuition", "hourly_rate": 120}, "% học phí phải từ 0,1 đến 100."},
		{map[string]any{"teacher_type": "robot"}, "Loại giáo viên không hợp lệ."},
		{map[string]any{"effective_from": ""}, "Vui lòng chọn ngày hiệu lực."},
		{map[string]any{"user_id": uuid.NewString()}, "Giáo viên không tồn tại."},
	} {
		expectMsg(t, mgr.Do("POST", "/payroll/config/teacher-rates/personal", rate(c.patch)), 422, c.msg)
	}
	r = mgr.Do("POST", "/payroll/config/teacher-rates/personal", rate(nil))
	iamtest.Expect(t, r, 200, "")
	if !strings.HasPrefix(r.Body["message"].(string), "Đã thêm đơn giá") || !strings.HasSuffix(r.Body["message"].(string), "hiệu lực từ 01/10/2026.") {
		t.Fatalf("thêm đơn giá: %s", r.Raw)
	}
	expectMsg(t, mgr.Do("POST", "/payroll/config/teacher-rates/personal", rate(nil)), 422,
		"Giáo viên đã có đơn giá hiệu lực từ ngày này — chọn ngày hiệu lực khác để tạo phiên bản mới.")
	iamtest.Expect(t, mgr.Do("GET", "/payroll/config/teacher-rates?teacher_id="+teacher.ID.String(), nil), 200, "")
	if rate, unit, err := e.svc.RateOn(context.Background(), teacher.ID, time.Date(2026, 10, 20, 0, 0, 0, 0, vn)); err != nil || rate == nil || *rate != 220000 || unit != "hour" {
		t.Fatalf("đơn giá hiệu lực: %v %s %v", rate, unit, err)
	}
	if rate, _, _ := e.svc.RateOn(context.Background(), teacher.ID, time.Date(2026, 9, 30, 0, 0, 0, 0, vn)); rate != nil {
		t.Fatalf("trước ngày hiệu lực: %v", *rate)
	}
	expectMsg(t, mgr.Do("POST", "/payroll/config/teacher-rates", map[string]any{"rates": []map[string]any{{"rank_title": " "}}}), 422,
		"Vui lòng nhập tên cấp bậc.")
	expectMsg(t, mgr.Do("POST", "/payroll/config/teacher-rates", map[string]any{"rates": []map[string]any{
		{"rank_title": "Cấp 1", "communication_rate": 200000, "ielts_rate": 250000}}}), 200, "Đã lưu đơn giá giờ dạy mới!")

	// Mốc hoa hồng
	for _, c := range []struct {
		in  map[string]any
		msg string
	}{
		{map[string]any{"new_sale_percent": 3}, "Vui lòng nhập số HS bắt đầu của mốc."},
		{map[string]any{"min_students": -1, "new_sale_percent": 3}, "Số HS bắt đầu không được âm."},
		{map[string]any{"min_students": 5, "max_students": 2, "new_sale_percent": 3}, "Số HS kết thúc phải lớn hơn hoặc bằng số HS bắt đầu."},
		{map[string]any{"min_students": 1}, "Vui lòng nhập % hoa hồng."},
		{map[string]any{"min_students": 1, "new_sale_percent": 101}, "% hoa hồng phải từ 0 đến 100."},
	} {
		expectMsg(t, mgr.Do("POST", "/payroll/config/commission-tiers", c.in), 422, c.msg)
	}
	expectMsg(t, mgr.Do("POST", "/payroll/config/commission-tiers", map[string]any{"tier_name": "Mốc 4", "min_students": 21,
		"new_sale_percent": 6, "effective_from": "2026-11-01"}), 201, "Đã lưu mốc hoa hồng mới, hiệu lực từ 01/11/2026!")
	tierID := e.id(`SELECT id FROM hr.commission_tiers WHERE tier_name = 'Mốc 4'`)
	expectMsg(t, mgr.Do("DELETE", "/payroll/config/commission-tiers/"+tierID.String(), nil), 200, "Đã xoá mốc hoa hồng chưa có hiệu lực: Mốc 4")
	if n := e.count(`SELECT count(*) FROM core.audit_log WHERE action LIKE 'commission_tier.%'`); n != 2 {
		t.Fatalf("nhật ký mốc hoa hồng: %d", n)
	}
	iamtest.Expect(t, mgr.Do("DELETE", "/payroll/config/commission-tiers/"+uuid.NewString(), nil), 404, "")
}

func TestPenaltyFlow(t *testing.T) {
	e := setup(t)
	hn := e.Branch("HN01")
	teacher, tc := e.Member("GV01", "Giáo viên A", []uuid.UUID{hn}, iam.RoleTeacherParttime)
	_, hv := e.Member("HV01", "Học vụ", []uuid.UUID{hn}, iam.RoleAcademicStaff)
	_, admin := e.Member("AD01", "Quản trị", nil, iam.RoleAdmin)

	fields := func(patch map[string]string) map[string]string {
		in := map[string]string{"user_id": teacher.ID.String(), "violation_type": "Nghỉ dạy không phép", "violation_at": "2026-10-17T07:00",
			"amount": "50000"}
		for k, v := range patch {
			in[k] = v
		}
		return in
	}
	for _, c := range []struct {
		patch map[string]string
		file  string
		msg   string
	}{
		{map[string]string{"user_id": ""}, "a.png", "Vui lòng chọn nhân sự vi phạm."},
		{map[string]string{"user_id": uuid.NewString()}, "a.png", "Nhân sự vi phạm không tồn tại."},
		{map[string]string{"violation_type": " "}, "a.png", "Vui lòng nhập lỗi vi phạm."},
		{map[string]string{"violation_at": ""}, "a.png", "Vui lòng nhập thời điểm vi phạm."},
		{map[string]string{"violation_at": "2026-10-17T10:00"}, "a.png", "Thời điểm vi phạm không được ở tương lai."},
		{map[string]string{"violation_at": "2026-10-15T07:00"}, "a.png", "Vi phạm đã quá 24h — không thể ghi nhận."},
		{map[string]string{"error_category": "x"}, "a.png", "Loại lỗi không hợp lệ."},
		{nil, "", "Bắt buộc đính kèm bằng chứng vi phạm (ảnh hoặc PDF)."},
		{nil, "a.txt", "Bằng chứng phải là ảnh (JPG, PNG, GIF, WEBP) hoặc PDF."},
	} {
		content := png
		if c.file == "a.txt" {
			content = []byte("hello")
		}
		expectMsg(t, hv.Upload("/penalties", fields(c.patch), c.file, content), 422, c.msg)
	}
	expectMsg(t, hv.Upload("/penalties", fields(nil), "a.png", png), 201,
		"Đã ghi nhận vi phạm BB-2026-001 — chờ nhân sự giải trình, sau đó Học vụ / Quản lý (CM) chốt.")
	p1 := e.id(`SELECT id FROM hr.penalties WHERE code = 'BB-2026-001'`)
	if n := e.count(`SELECT count(*) FROM core.audit_log WHERE action = 'penalty.create'`); n != 1 {
		t.Fatalf("nhật ký lập biên bản: %d", n)
	}

	// Nhân sự vi phạm thấy biên bản của mình, xem được bằng chứng
	r := tc.Do("GET", "/penalties", nil)
	iamtest.Expect(t, r, 200, "")
	rows := iamtest.List(t, r, "penalties")
	if len(rows) != 1 || rows[0]["can_explain"] != true || rows[0]["evidence_url"] == nil {
		t.Fatalf("biên bản của tôi: %s", r.Raw)
	}
	if g := tc.Get(rows[0]["evidence_url"].(string)); g.Status != 200 && g.Status != 302 {
		t.Fatalf("tải bằng chứng: %d", g.Status)
	}
	expectMsg(t, tc.Do("POST", "/penalties/"+p1.String()+"/explain", map[string]any{"explanation": "ngắn"}), 422,
		"Nội dung giải trình cần ít nhất 10 ký tự.")
	iamtest.Expect(t, hv.Do("POST", "/penalties/"+p1.String()+"/explain", map[string]any{"explanation": "Không phải tôi vi phạm"}), 403, "")
	expectMsg(t, tc.Do("POST", "/penalties/"+p1.String()+"/explain", map[string]any{"explanation": "Em bị ốm đột xuất, đã báo qua Zalo"}), 200,
		"Đã gửi giải trình biên bản BB-2026-001 — chờ Học vụ / Quản lý (CM) chốt.")
	iamtest.Expect(t, tc.Do("POST", "/penalties/"+p1.String()+"/confirm", map[string]any{"decision": "fine", "amount": 50000}), 403, "")

	expectMsg(t, hv.Do("POST", "/penalties/"+p1.String()+"/confirm", map[string]any{"decision": "fine"}), 422, "Vui lòng nhập số tiền phạt khi quyết phạt.")
	expectMsg(t, hv.Do("POST", "/penalties/"+p1.String()+"/confirm", map[string]any{"decision": "fine", "amount": 500}), 422, "Số tiền phạt tối thiểu 1.000đ.")
	expectMsg(t, hv.Do("POST", "/penalties/"+p1.String()+"/confirm", map[string]any{"decision": "fine", "amount": 100000}), 200,
		"Đã quyết phạt BB-2026-001: 100.000 đ — hạn nộp 19/10/2026, quá hạn chưa nộp sẽ trừ vào kỳ lương.")
	if n := e.count(`SELECT count(*) FROM core.notifications WHERE type = 'penalty_fined' AND user_id = $1`, teacher.ID); n != 1 {
		t.Fatalf("thông báo quyết phạt: %d", n)
	}
	expectMsg(t, hv.Do("POST", "/penalties/"+p1.String()+"/confirm", map[string]any{"decision": "error"}), 422, "Biên bản không ở trạng thái cho phép chốt.")
	iamtest.Expect(t, hv.Do("POST", "/penalties/"+p1.String()+"/cancel", nil), 403, "")
	expectMsg(t, admin.Do("POST", "/penalties/"+p1.String()+"/cancel", nil), 422, "Biên bản đã vào vòng phạt không thể hủy — dùng Đóng vụ nếu cần miễn.")
	expectMsg(t, admin.Do("POST", "/penalties/"+p1.String()+"/remedy", map[string]any{}), 422, "Chỉ biên bản đã nộp / đã trừ lương mới ghi nhận khắc phục.")
	expectMsg(t, admin.Do("POST", "/penalties/"+p1.String()+"/mark-paid", nil), 200,
		"Đã ghi nhận nhân sự nộp phạt BB-2026-001 trực tiếp — không trừ vào bảng lương.")
	expectMsg(t, admin.Do("POST", "/penalties/"+p1.String()+"/remedy", map[string]any{"remedy_note": "Đã dạy bù"}), 200,
		"Đã ghi nhận khắc phục biên bản BB-2026-001.")

	// Lỗi chuyên môn: Học vụ không chốt được
	expectMsg(t, hv.Upload("/penalties", fields(map[string]string{"violation_type": "Gửi nhận xét sau buổi học trễ"}), "a.png", png), 201,
		"Đã ghi nhận vi phạm BB-2026-002 — chờ nhân sự giải trình, sau đó Học thuật (HT) chốt.")
	p2 := e.id(`SELECT id FROM hr.penalties WHERE code = 'BB-2026-002'`)
	expectMsg(t, hv.Do("POST", "/penalties/"+p2.String()+"/confirm", map[string]any{"decision": "error"}), 403,
		"Biên bản Lỗi chuyên môn / giảng dạy do Học thuật (HT) chốt.")
	expectMsg(t, admin.Do("POST", "/penalties/"+p2.String()+"/cancel", nil), 200, "Đã hủy bỏ biên bản vi phạm BB-2026-002!")

	// Lọc
	expectMsg(t, admin.Do("GET", "/penalties?status=abc", nil), 422, "Trạng thái lọc không hợp lệ.")
	expectMsg(t, admin.Do("GET", "/penalties?from=17-10-2026", nil), 422, "Ngày lọc không hợp lệ.")
	r = admin.Do("GET", "/penalties?status=cancelled", nil)
	if rows := iamtest.List(t, r, "penalties"); len(rows) != 1 || rows[0]["code"] != "BB-2026-002" {
		t.Fatalf("lọc đã hủy: %s", r.Raw)
	}

	// Nhắc hạn nộp phạt: 1 lần / biên bản / ngày
	e.exec(`INSERT INTO hr.penalties (code, user_id, violation_type, violation_date, amount, status, due_date)
		VALUES ('BB-2026-050', $1, 'Vi phạm nội quy trung tâm', '2026-10-15', 30000, 'fined', '2026-10-18')`, teacher.ID)
	if n, err := e.svc.RemindPenaltiesDue(context.Background()); err != nil || n != 1 {
		t.Fatalf("nhắc hạn nộp: %d %v", n, err)
	}
	if n, _ := e.svc.RemindPenaltiesDue(context.Background()); n != 0 {
		t.Fatalf("nhắc trùng: %d", n)
	}

	// Thẻ "Vi phạm tháng này" của cổng giáo viên
	n, latest, err := e.svc.Violations(context.Background(), teacher.ID, time.Date(2026, 10, 1, 0, 0, 0, 0, vn), time.Date(2026, 10, 31, 0, 0, 0, 0, vn))
	if err != nil || n != 3 || latest == nil || latest.Type == "" {
		t.Fatalf("vi phạm tháng: %d %+v %v", n, latest, err)
	}
}
