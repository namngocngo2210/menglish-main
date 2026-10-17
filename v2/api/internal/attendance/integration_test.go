package attendance_test

import (
	"bytes"
	"context"
	"encoding/csv"
	"fmt"
	"strings"
	"testing"
	"time"

	"github.com/go-chi/chi/v5"
	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/attendance"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/files"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam/iamtest"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/filestore"
)

var vn = time.FixedZone("ICT", 7*3600)

// Ảnh khuôn mặt giả (PNG hợp lệ theo chữ ký đầu file).
var photo = append([]byte("\x89PNG\r\n\x1a\n"), bytes.Repeat([]byte{1}, 64)...)

// Tọa độ chi nhánh HN và một điểm cách ~40 m / ~2 km.
const (
	hnLat, hnLng   = 21.0285, 105.8542
	nearLat        = 21.0288
	farLat, farLng = 21.0465, 105.8542
)

// fakeClasses: lớp người dùng được xem = lớp mình là GV chính / trợ giảng / GVNN (Lớp học của tôi).
type fakeClasses struct{ e *iamtest.Env }

func (f fakeClasses) VisibleClassIDs(ctx context.Context, a *iam.Access) ([]uuid.UUID, error) {
	rows, err := f.e.Pool.Query(ctx, `SELECT id FROM academic.classes WHERE deleted_at IS NULL
		AND (teacher_id = $1 OR assistant_id = $1 OR foreign_teacher_id = $1)`, a.UserID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var ids []uuid.UUID
	for rows.Next() {
		var id uuid.UUID
		if err := rows.Scan(&id); err != nil {
			return nil, err
		}
		ids = append(ids, id)
	}
	return ids, rows.Err()
}

// fakeLock: kỳ lương đã khóa (phân hệ Lương chưa gộp).
type fakeLock struct{ ranges []attendance.LockedRange }

func (f fakeLock) LockedRanges(context.Context) ([]attendance.LockedRange, error) { return f.ranges, nil }
func (f fakeLock) Period(_ context.Context, m time.Time) (*attendance.PayrollPeriod, error) {
	for _, r := range f.ranges {
		if r.From.Format("2006-01") == m.Format("2006-01") {
			return &attendance.PayrollPeriod{StatusLabel: "Đã duyệt", Locked: true}, nil
		}
	}
	return nil, nil
}

type env struct {
	*iamtest.Env
	svc *attendance.Service
	now time.Time
}

func (e *env) exec(sql string, args ...any) {
	e.T.Helper()
	if _, err := e.Pool.Exec(context.Background(), sql, args...); err != nil {
		e.T.Fatal(err)
	}
}

func (e *env) scalar(sql string, args ...any) any {
	e.T.Helper()
	var v any
	if err := e.Pool.QueryRow(context.Background(), sql, args...).Scan(&v); err != nil {
		e.T.Fatal(err)
	}
	return v
}

func (e *env) at(day, hhmm string) {
	t, err := time.ParseInLocation("2006-01-02 15:04", day+" "+hhmm, vn)
	if err != nil {
		e.T.Fatal(err)
	}
	e.now = t
}

func setup(t *testing.T, lock attendance.PayrollLock) *env {
	ie := iamtest.Setup(t, "hr.staff_attendances", "hr.staff_attendance_requests", "hr.teacher_timesheets",
		"hr.timesheet_sync_logs", "core.files", "core.audit_log", "core.notifications")
	store, err := filestore.NewLocal(t.TempDir(), files.APIBase+files.BlobPrefix, bytes.Repeat([]byte{7}, 32))
	if err != nil {
		t.Fatal(err)
	}
	fsvc := files.NewService(ie.Pool, store, ie.Log, 0)
	e := &env{Env: ie}
	e.at("2026-10-17", "08:12")
	e.svc = attendance.NewService(ie.Pool, ie.IAM, ie.Log).WithFiles(fsvc).WithClasses(fakeClasses{ie}).WithPayrollLock(lock)
	e.svc.SetClock(func() time.Time { return e.now })
	fh := files.NewHandler(fsvc, ie.Log)
	ie.Serve(func(r chi.Router) {
		attendance.NewHandler(e.svc, ie.Log).Routes(r)
		fh.Routes(r)
		fh.PublicRoutes(r)
	})
	return e
}

// fixture: chi nhánh HN (có tọa độ, giờ làm 08:00–17:00, cho phép muộn 5 phút) và HCM (chưa cài tọa độ).
type fixture struct {
	hn, hcm                                uuid.UUID
	admin, mgr, sales, teacher, hv, salesB iamtest.User
	cAdmin, cMgr, cSales, cTeacher, cHV    *iamtest.Client
	cSalesB, cMgrB                         *iamtest.Client
}

func newFixture(e *env) *fixture {
	f := &fixture{hn: e.Branch("HN01"), hcm: e.Branch("HCM01")}
	e.exec(`UPDATE core.branches SET latitude = $2, longitude = $3, checkin_radius = 100, work_start_time = '08:00',
		work_end_time = '17:00', late_grace_minutes = 5 WHERE id = $1`, f.hn, hnLat, hnLng)
	f.admin, f.cAdmin = e.Member("AD01", "Quản trị", []uuid.UUID{f.hn}, "admin")
	f.mgr, f.cMgr = e.Member("QL01", "Quản lý HN", []uuid.UUID{f.hn}, "manager")
	f.sales, f.cSales = e.Member("SA01", "Tư vấn HN", []uuid.UUID{f.hn}, "sales_consultant")
	f.teacher, f.cTeacher = e.Member("GV01", "Giáo viên A", []uuid.UUID{f.hn}, "teacher_fulltime")
	f.hv, f.cHV = e.Member("HV01", "Học vụ", []uuid.UUID{f.hn}, "academic_staff")
	f.salesB, f.cSalesB = e.Member("SA02", "Tư vấn HCM", []uuid.UUID{f.hcm}, "sales_consultant")
	_, f.cMgrB = e.Member("QL02", "Quản lý HCM", []uuid.UUID{f.hcm}, "manager")
	return f
}

// class: lớp đang học của chi nhánh, GV chính teacher.
func (e *env) class(code string, branch uuid.UUID, teacher *uuid.UUID) uuid.UUID {
	e.T.Helper()
	var id uuid.UUID
	if err := e.Pool.QueryRow(context.Background(), `INSERT INTO academic.classes (code, name, branch_id, teacher_id, status)
		VALUES ($1, $2, $3, $4, 'active') RETURNING id`, code, "Lớp "+code, branch, teacher).Scan(&id); err != nil {
		e.T.Fatal(err)
	}
	return id
}

func (e *env) session(class, branch uuid.UUID, day, start, end string, teacher *uuid.UUID) uuid.UUID {
	e.T.Helper()
	var id uuid.UUID
	if err := e.Pool.QueryRow(context.Background(), `INSERT INTO academic.class_sessions (class_id, branch_id, date, start_time,
		end_time, teacher_id) VALUES ($1, $2, $3, $4, $5, $6) RETURNING id`, class, branch, day, start, end, teacher).Scan(&id); err != nil {
		e.T.Fatal(err)
	}
	return id
}

func punch(c *iamtest.Client, kind string, lat, lng float64, withPhoto bool) iamtest.Resp {
	fields := map[string]string{"kind": kind, "latitude": fmt.Sprint(lat), "longitude": fmt.Sprint(lng), "accuracy": "12"}
	if !withPhoto {
		return c.Upload("/attendance/me/punch", fields, "", nil)
	}
	return c.Upload("/attendance/me/punch", fields, "face.png", photo)
}

func message(t *testing.T, r iamtest.Resp, status int, want string) {
	t.Helper()
	if r.Status != status || r.Body["message"] != want {
		t.Fatalf("muốn %d %q, nhận %d %s", status, want, r.Status, r.Raw)
	}
}

func TestAttendancePermissions(t *testing.T) {
	e := setup(t, nil)
	f := newFixture(e)
	iamtest.Expect(t, e.Anon().Do("GET", "/attendance/me", nil), 401, "")
	// Đơn chấm công chỉ Admin duyệt (staff_checkin.approve admin-only, cấp cho vai trò khác không có tác dụng)
	e.Grant("manager", "staff_checkin.approve")
	iamtest.Expect(t, f.cMgr.Do("GET", "/attendance/approvals", nil), 403, "")
	iamtest.Expect(t, f.cAdmin.Do("GET", "/attendance/approvals", nil), 200, "")
	iamtest.Expect(t, f.cSales.Do("GET", "/attendance/daily", nil), 403, "")
	iamtest.Expect(t, f.cTeacher.Do("GET", "/attendance/daily/"+uuid.NewString(), nil), 403, "")
	iamtest.Expect(t, f.cTeacher.Do("GET", "/timesheets/manual", nil), 403, "")
	iamtest.Expect(t, f.cTeacher.Do("POST", "/timesheets/teachers/bulk-review", map[string]any{"ids": []string{}}), 403, "")
	iamtest.Expect(t, f.cMgr.Do("GET", "/timesheets/sync-history", nil), 403, "")
	iamtest.Expect(t, f.cSales.Do("GET", "/timesheets/teachers", nil), 403, "")
	r := f.cTeacher.Do("GET", "/timesheets/teachers", nil)
	iamtest.Expect(t, r, 200, "")
	if r.Body["can_view_all"] != false || r.Body["schedule_day"] != nil {
		t.Errorf("giáo viên chỉ xem ca của mình: %s", r.Raw)
	}
	// Tài khoản không có cổng nhân sự
	e.Revoke("sales_consultant", "portal.staff")
	iamtest.Expect(t, f.cSales.Do("GET", "/attendance/me", nil), 403, "")
}

func TestPunch(t *testing.T) {
	e := setup(t, nil)
	f := newFixture(e)

	home := f.cSales.Do("GET", "/attendance/me", nil)
	iamtest.Expect(t, home, 200, "")
	exp := home.Body["expected"].(map[string]any)
	if exp["start"] != "08:00" || exp["end"] != "17:00" || exp["basis"] != "Theo giờ làm việc cơ sở" || home.Body["today"] != nil {
		t.Fatalf("home: %s", home.Raw)
	}

	// Kiểm tra đầu vào
	r := f.cSales.Upload("/attendance/me/punch", map[string]string{"kind": "in"}, "face.png", photo)
	message(t, r, 422, "Chưa lấy được vị trí GPS của điện thoại. Hãy bật định vị và cho phép trình duyệt truy cập vị trí.")
	message(t, punch(f.cSales, "in", nearLat, hnLng, false), 422, "Hãy chụp ảnh khuôn mặt trước khi chấm công.")
	message(t, punch(f.cSales, "sideways", nearLat, hnLng, true), 422, "Loại chấm công không hợp lệ.")
	r = f.cSales.Upload("/attendance/me/punch", map[string]string{"kind": "in", "latitude": "21", "longitude": "105"}, "face.txt", []byte("hello"))
	message(t, r, 422, "Ảnh chụp không hợp lệ, hãy chụp lại.")
	message(t, punch(f.cSales, "in", farLat, farLng, true), 422,
		"Bạn đang cách Chi nhánh HN01 khoảng 2.002 m, chỉ chấm công được trong bán kính 100 m quanh cơ sở.")
	message(t, punch(f.cSalesB, "in", nearLat, hnLng, true), 422,
		"Cơ sở Chi nhánh HCM01 chưa cài toạ độ chấm công. Hãy báo Admin vào Cài đặt → Cơ sở & chi nhánh → Chấm công.")
	message(t, punch(f.cSales, "out", nearLat, hnLng, true), 422,
		`Hôm nay bạn chưa chấm công vào. Nếu quên chấm vào, hãy gửi đơn "Bổ sung công".`)

	// Chấm vào lúc 08:12 → muộn 12 phút (quá 5 phút cho phép)
	r = punch(f.cSales, "in", nearLat, hnLng, true)
	message(t, r, 200, "Đã chấm công vào lúc 08:12. Bạn đi muộn 12 phút.")
	today := r.Body["today"].(map[string]any)
	if r.Body["warning"] != true || today["late_minutes"] != 12.0 || today["status_label"] != "Đi muộn 12 phút" ||
		today["check_in_distance"] == nil || today["check_in_photo"] == nil {
		t.Fatalf("today: %s", r.Raw)
	}
	message(t, punch(f.cSales, "in", nearLat, hnLng, true), 422, "Hôm nay bạn đã chấm công vào lúc 08:12.")

	// Ảnh khuôn mặt: chính chủ và quản lý chi nhánh mở được, nhân sự khác / quản lý chi nhánh khác thì không
	ph := strings.TrimPrefix(today["check_in_photo"].(string), "")
	if g := f.cSales.Get(ph); g.Status != 302 {
		t.Fatalf("chính chủ mở ảnh: %d %s", g.Status, g.Raw)
	}
	if g := f.cMgr.Get(ph); g.Status != 302 {
		t.Fatalf("quản lý mở ảnh: %d %s", g.Status, g.Raw)
	}
	for _, c := range []*iamtest.Client{f.cTeacher, f.cMgrB} {
		if g := c.Get(ph); g.Status != 403 {
			t.Fatalf("người ngoài mở ảnh: %d %s", g.Status, g.Raw)
		}
	}

	// Chấm ra sớm 16:30 → về sớm 30 phút
	e.at("2026-10-17", "16:30")
	message(t, punch(f.cSales, "out", nearLat, hnLng, true), 200, "Đã chấm công ra lúc 16:30.")
	// Chấm ra lần nữa (về muộn hơn) thay giờ và ảnh cũ
	e.at("2026-10-17", "17:05")
	r = punch(f.cSales, "out", nearLat, hnLng, true)
	message(t, r, 200, "Đã chấm công ra lúc 17:05.")
	if d := r.Body["today"].(map[string]any); d["early_minutes"] != 0.0 || d["check_out"] != "17:05" {
		t.Fatalf("chấm ra lại: %s", r.Raw)
	}
	if n := e.scalar(`SELECT count(*) FROM core.files WHERE subject_type = 'staff_attendance_photo' AND deleted_at IS NULL`); n != int64(2) {
		t.Errorf("ảnh còn lại = %v, muốn 2 (ảnh ra cũ đã xóa)", n)
	}

	// Giáo viên: giờ phải có mặt theo buổi dạy trong ngày
	cls := e.class("IELTS-01", f.hn, &f.teacher.ID)
	e.session(cls, f.hn, "2026-10-17", "18:00", "19:30", &f.teacher.ID)
	e.session(cls, f.hn, "2026-10-17", "19:45", "21:00", &f.teacher.ID)
	e.at("2026-10-17", "17:50")
	home = f.cTeacher.Do("GET", "/attendance/me", nil)
	exp = home.Body["expected"].(map[string]any)
	if exp["start"] != "18:00" || exp["end"] != "21:00" || len(exp["sessions"].([]any)) != 2 {
		t.Fatalf("giờ phải có mặt GV: %s", home.Raw)
	}
	r = punch(f.cTeacher, "in", nearLat, hnLng, true)
	message(t, r, 200, "Đã chấm công vào lúc 17:50.")

	// Lịch sử công
	r = f.cSales.Do("GET", "/attendance/me/history?month=2026-10", nil)
	iamtest.Expect(t, r, 200, "")
	sum := r.Body["summary"].(map[string]any)
	if sum["days"] != 1.0 || sum["late_count"] != 1.0 || sum["late_minutes"] != 12.0 || len(iamtest.List(t, r, "rows")) != 1 ||
		r.Body["next_month"] != nil || r.Body["prev_month"] != "2026-09" {
		t.Fatalf("lịch sử: %s", r.Raw)
	}
}

func TestPunchLockedPeriod(t *testing.T) {
	from := time.Date(2026, 10, 1, 0, 0, 0, 0, vn)
	e := setup(t, fakeLock{[]attendance.LockedRange{{From: from, To: from.AddDate(0, 1, -1), Title: "Lương tháng 10/2026"}}})
	f := newFixture(e)
	msg := "Ngày 17/10/2026 thuộc kỳ lương đã duyệt/đã chi trả — không thể ghi nhận hoặc thay đổi dữ liệu lương cho ngày này."
	message(t, punch(f.cSales, "in", nearLat, hnLng, true), 422, msg)
	if r := f.cSales.Do("GET", "/attendance/me", nil); r.Body["locked"] != msg {
		t.Fatalf("home locked: %s", r.Raw)
	}
	r := f.cSales.Do("POST", "/attendance/me/requests", map[string]any{"type": "leave", "date_from": "2026-10-20", "reason": "Việc gia đình"})
	message(t, r, 422, "Ngày 20/10/2026 thuộc kỳ lương đã duyệt/đã chi trả — không thể ghi nhận hoặc thay đổi dữ liệu lương cho ngày này.")
}

func TestRequests(t *testing.T) {
	e := setup(t, nil)
	f := newFixture(e)
	submit := func(c *iamtest.Client, body map[string]any) iamtest.Resp { return c.Do("POST", "/attendance/me/requests", body) }

	for _, c := range []struct {
		body map[string]any
		msg  string
	}{
		{map[string]any{"date_from": "2026-10-17", "reason": "x"}, "Chọn loại đơn."},
		{map[string]any{"type": "overtime", "date_from": "2026-10-17", "reason": "x"}, "Loại đơn không hợp lệ."},
		{map[string]any{"type": "leave", "reason": "x"}, "Chọn ngày."},
		{map[string]any{"type": "leave", "date_from": "17/10/2026", "reason": "x"}, "Ngày không hợp lệ."},
		{map[string]any{"type": "leave", "date_from": "2026-10-17"}, "Nhập lý do."},
		{map[string]any{"type": "leave", "date_from": "2026-10-17", "reason": strings.Repeat("a", 1001)}, "Lý do tối đa 1000 ký tự."},
		{map[string]any{"type": "leave", "date_from": "2026-10-17", "date_to": "2026-10-16", "reason": "x"}, "Ngày kết thúc phải từ ngày bắt đầu trở đi."},
		{map[string]any{"type": "leave", "date_from": "2026-10-01", "date_to": "2026-11-05", "reason": "x"}, "Mỗi đơn xin nghỉ tối đa 31 ngày."},
		{map[string]any{"type": "correction", "date_from": "2026-10-18", "check_in_time": "08:00", "reason": "x"}, "Chỉ bổ sung công cho hôm nay hoặc ngày đã qua."},
		{map[string]any{"type": "correction", "date_from": "2026-10-16", "reason": "x"}, "Nhập giờ vào hoặc giờ ra cần bổ sung."},
		{map[string]any{"type": "correction", "date_from": "2026-10-16", "check_in_time": "8h", "reason": "x"}, "Giờ vào không hợp lệ (định dạng HH:MM)."},
		{map[string]any{"type": "correction", "date_from": "2026-10-16", "check_in_time": "17:00", "check_out_time": "08:00", "reason": "x"}, "Giờ ra phải sau giờ vào."},
	} {
		message(t, submit(f.cSales, c.body), 422, c.msg)
	}

	// Chấm vào muộn rồi xin đi muộn → Admin duyệt → muộn có phép
	message(t, punch(f.cSales, "in", nearLat, hnLng, true), 200, "Đã chấm công vào lúc 08:12. Bạn đi muộn 12 phút.")
	message(t, submit(f.cSales, map[string]any{"type": "late_early", "date_from": "2026-10-17", "reason": "Kẹt xe"}), 201,
		"Đã gửi đơn Xin đi muộn / về sớm, chờ Admin duyệt.")
	message(t, submit(f.cSales, map[string]any{"type": "late_early", "date_from": "2026-10-17", "reason": "Kẹt xe lần 2"}), 422,
		`Bạn đã có đơn "Xin đi muộn / về sớm" đang chờ duyệt cho ngày này.`)
	// Quên chấm hôm qua → bổ sung công
	message(t, submit(f.cSales, map[string]any{"type": "correction", "date_from": "2026-10-16", "check_in_time": "08:00",
		"check_out_time": "17:30", "reason": "Quên chấm công"}), 201, "Đã gửi đơn Bổ sung công, chờ Admin duyệt.")
	// Xin nghỉ 2 ngày (ngày kết thúc chỉ áp dụng cho đơn nghỉ)
	message(t, submit(f.cSales, map[string]any{"type": "leave", "date_from": "2026-10-20", "date_to": "2026-10-21", "reason": "Về quê"}), 201,
		"Đã gửi đơn Xin nghỉ, chờ Admin duyệt.")
	// Admin tự gửi đơn: không tự duyệt được, không thấy trong danh sách chờ
	message(t, submit(f.cAdmin, map[string]any{"type": "leave", "date_from": "2026-10-22", "reason": "Đi khám"}), 201, "Đã gửi đơn Xin nghỉ, chờ Admin duyệt.")

	mine := iamtest.List(t, f.cSales.Do("GET", "/attendance/me/requests", nil), "requests")
	if len(mine) != 3 || mine[0]["period"] != "20/10/2026 → 21/10/2026 (2 ngày)" || mine[1]["period"] != "16/10/2026 · vào 08:00, ra 17:30" ||
		mine[0]["status_label"] != "Chờ duyệt" {
		t.Fatalf("đơn của tôi: %v", mine)
	}
	items := iamtest.List(t, f.cAdmin.Do("GET", "/attendance/approvals", nil), "items")
	if len(items) != 3 {
		t.Fatalf("đơn chờ duyệt: %v", items)
	}
	byType := map[string]map[string]any{}
	for _, it := range items {
		byType[it["type"].(string)] = it
	}
	if byType["late_early"]["current_punch"] != "Vào 08:12, ra — · Đi muộn 12 phút" || byType["correction"]["current_punch"] != "Chưa chấm" ||
		byType["leave"]["current_punch"] != nil {
		t.Fatalf("giờ đã chấm để đối chiếu: %v", items)
	}
	var adminReq string
	if err := e.Pool.QueryRow(context.Background(), `SELECT id::text FROM hr.staff_attendance_requests WHERE user_id = $1`, f.admin.ID).Scan(&adminReq); err != nil {
		t.Fatal(err)
	}
	message(t, f.cAdmin.Do("POST", "/attendance/approvals/"+adminReq+"/approve", nil), 422, "Đơn không còn chờ duyệt hoặc ngoài phạm vi duyệt của bạn.")

	late := byType["late_early"]["id"].(string)
	message(t, f.cAdmin.Do("POST", "/attendance/approvals/"+late+"/approve", nil), 200, "Đã duyệt đơn Xin đi muộn / về sớm của Tư vấn HN.")
	message(t, f.cAdmin.Do("POST", "/attendance/approvals/"+late+"/approve", nil), 422, "Đơn không còn chờ duyệt hoặc ngoài phạm vi duyệt của bạn.")
	home := f.cSales.Do("GET", "/attendance/me", nil)
	if d := home.Body["today"].(map[string]any); d["late_excused"] != true || d["status_label"] != "Muộn có phép" {
		t.Fatalf("muộn có phép: %s", home.Raw)
	}
	corr := byType["correction"]["id"].(string)
	message(t, f.cAdmin.Do("POST", "/attendance/approvals/"+corr+"/approve", nil), 200, "Đã duyệt đơn Bổ sung công của Tư vấn HN.")
	if src := e.scalar(`SELECT source || ' ' || to_char(check_in_at AT TIME ZONE 'Asia/Ho_Chi_Minh', 'HH24:MI') FROM hr.staff_attendances
		WHERE user_id = $1 AND work_date = '2026-10-16'`, f.sales.ID); src != "request 08:00" {
		t.Errorf("bổ sung công: %v", src)
	}
	leave := byType["leave"]["id"].(string)
	message(t, f.cAdmin.Do("POST", "/attendance/approvals/"+leave+"/reject", map[string]any{"reason": " "}), 422, "Vui lòng nhập lý do từ chối.")
	message(t, f.cAdmin.Do("POST", "/attendance/approvals/"+leave+"/reject", map[string]any{"reason": "Trùng lịch thi"}), 200,
		"Đã từ chối đơn Xin nghỉ của Tư vấn HN.")
	mine = iamtest.List(t, f.cSales.Do("GET", "/attendance/me/requests", nil), "requests")
	if mine[0]["status"] != "rejected" || mine[0]["rejection_reason"] != "Trùng lịch thi" || mine[0]["reviewer"] != "Quản trị" {
		t.Fatalf("đơn bị từ chối: %v", mine[0])
	}
	if n := e.scalar(`SELECT count(*) FROM core.notifications WHERE user_id = $1 AND type = 'staff_attendance_request'`, f.sales.ID); n != int64(3) {
		t.Errorf("thông báo kết quả duyệt = %v", n)
	}

	// Rút đơn: chỉ đơn của mình đang chờ duyệt
	message(t, submit(f.cSales, map[string]any{"type": "leave", "date_from": "2026-10-23", "reason": "Việc riêng"}), 201, "Đã gửi đơn Xin nghỉ, chờ Admin duyệt.")
	var pending string
	if err := e.Pool.QueryRow(context.Background(), `SELECT id::text FROM hr.staff_attendance_requests WHERE user_id = $1 AND status = 'pending'`, f.sales.ID).Scan(&pending); err != nil {
		t.Fatal(err)
	}
	message(t, f.cTeacher.Do("POST", "/attendance/me/requests/"+pending+"/cancel", nil), 422, "Chỉ rút được đơn của bạn đang chờ duyệt.")
	message(t, f.cSales.Do("POST", "/attendance/me/requests/"+pending+"/cancel", nil), 200, "Đã rút đơn.")
	message(t, f.cSales.Do("POST", "/attendance/me/requests/"+late+"/cancel", nil), 422, "Chỉ rút được đơn của bạn đang chờ duyệt.")
	iamtest.Expect(t, f.cSales.Do("POST", "/attendance/me/requests/"+uuid.NewString()+"/cancel", nil), 404, "request_not_found")
}

func TestDaily(t *testing.T) {
	e := setup(t, nil)
	f := newFixture(e)
	message(t, punch(f.cSales, "in", nearLat, hnLng, true), 200, "Đã chấm công vào lúc 08:12. Bạn đi muộn 12 phút.")
	e.at("2026-10-17", "07:55")
	message(t, punch(f.cMgr, "in", nearLat, hnLng, true), 200, "Đã chấm công vào lúc 07:55.")
	e.exec(`INSERT INTO hr.staff_attendance_requests (user_id, branch_id, type, date_from, date_to, reason, status)
		VALUES ($1, $2, 'leave', '2026-10-16', '2026-10-18', 'Ốm', 'approved')`, f.hv.ID, f.hn)

	r := f.cMgr.Do("GET", "/attendance/daily?date=2026-10-17", nil)
	iamtest.Expect(t, r, 200, "")
	rows := iamtest.List(t, r, "rows")
	// Quản lý HN chỉ thấy nhân sự chi nhánh HN: Quản trị, Quản lý, Tư vấn, Giáo viên, Học vụ
	if len(rows) != 5 {
		t.Fatalf("nhân sự HN: %s", r.Raw)
	}
	st := r.Body["stats"].(map[string]any)
	if st["staff"] != 5.0 || st["checked_in"] != 2.0 || st["late"] != 1.0 || st["leave"] != 1.0 {
		t.Fatalf("thống kê ngày: %v", st)
	}
	if len(r.Body["unconfigured"].([]any)) != 0 || len(r.Body["branches"].([]any)) != 1 {
		t.Fatalf("chi nhánh trong phạm vi: %s", r.Raw)
	}
	late := iamtest.List(t, f.cMgr.Do("GET", "/attendance/daily?date=2026-10-17&status=late", nil), "rows")
	if len(late) != 1 || late[0]["user"].(map[string]any)["code"] != "SA01" {
		t.Fatalf("lọc đi muộn: %v", late)
	}
	leave := iamtest.List(t, f.cMgr.Do("GET", "/attendance/daily?date=2026-10-17&status=leave", nil), "rows")
	if len(leave) != 1 || leave[0]["leave"] != "Ốm" {
		t.Fatalf("lọc nghỉ phép: %v", leave)
	}
	missing := iamtest.List(t, f.cMgr.Do("GET", "/attendance/daily?date=2026-10-17&status=missing&search=gi%C3%A1o", nil), "rows")
	if len(missing) != 1 || missing[0]["attendance"] != nil {
		t.Fatalf("lọc chưa chấm + tìm kiếm: %v", missing)
	}
	message(t, f.cMgr.Do("GET", "/attendance/daily?status=away", nil), 422, "Trạng thái không hợp lệ.")

	// Tổng hợp tháng
	month := iamtest.List(t, f.cMgr.Do("GET", "/attendance/daily?view=month&month=2026-10&search=SA01", nil), "rows")
	if len(month) != 1 || month[0]["summary"].(map[string]any)["late_count"] != 1.0 {
		t.Fatalf("tổng hợp tháng: %v", month)
	}
	hv := iamtest.List(t, f.cMgr.Do("GET", "/attendance/daily?view=month&month=2026-10&search=HV01", nil), "rows")
	if hv[0]["summary"].(map[string]any)["leave_days"] != 3.0 {
		t.Fatalf("ngày nghỉ có phép: %v", hv)
	}

	// Admin thấy mọi chi nhánh, cảnh báo chi nhánh chưa cài tọa độ
	r = f.cAdmin.Do("GET", "/attendance/daily", nil)
	if u := r.Body["unconfigured"].([]any); len(u) != 1 || u[0] != "Chi nhánh HCM01" || r.Body["meta"].(map[string]any)["total"] != 7.0 {
		t.Fatalf("admin: %s", r.Raw)
	}

	// Chi tiết ngày công
	id := rows[0]["attendance"]
	for _, row := range rows {
		if row["user"].(map[string]any)["code"] == "SA01" {
			id = row["attendance"].(map[string]any)["id"]
		}
	}
	d := f.cMgr.Do("GET", fmt.Sprintf("/attendance/daily/%v", id), nil)
	iamtest.Expect(t, d, 200, "")
	in := d.Body["in"].(map[string]any)
	if in["time"] != "08:12:00" || !strings.HasPrefix(in["map"].(string), "https://www.google.com/maps/search/?api=1&query=21.0288,105.8542") ||
		d.Body["expected_start"] != "08:00" || d.Body["out"] != nil {
		t.Fatalf("chi tiết: %s", d.Raw)
	}
	iamtest.Expect(t, f.cMgrB.Do("GET", fmt.Sprintf("/attendance/daily/%v", id), nil), 403, "")
	iamtest.Expect(t, f.cMgr.Do("GET", "/attendance/daily/"+uuid.NewString(), nil), 404, "attendance_not_found")
}

func TestTimesheets(t *testing.T) {
	e := setup(t, nil)
	f := newFixture(e)
	cls := e.class("IELTS-01", f.hn, &f.teacher.ID)
	clsB := e.class("HCM-01", f.hcm, nil)
	today := e.session(cls, f.hn, "2026-10-17", "08:00", "09:30", &f.teacher.ID)
	yesterday := e.session(cls, f.hn, "2026-10-16", "18:00", "19:30", &f.teacher.ID)
	e.session(cls, f.hn, "2026-10-15", "18:00", "19:30", &f.teacher.ID)
	future := e.session(cls, f.hn, "2026-10-20", "18:00", "19:30", &f.teacher.ID)

	manual := func(c *iamtest.Client, patch map[string]any) iamtest.Resp {
		body := map[string]any{"user_id": f.teacher.ID.String(), "branch_id": f.hn.String(), "class_id": cls.String(),
			"teaching_date": "2026-10-16", "time_in": "18:00", "time_out": "19:30", "type": "regular", "notes": "GV quên check-in"}
		for k, v := range patch {
			body[k] = v
		}
		return c.Do("POST", "/timesheets/manual", body)
	}
	for _, c := range []struct {
		patch map[string]any
		msg   string
	}{
		{map[string]any{"time_in": ""}, "Vui lòng nhập giờ vào."},
		{map[string]any{"time_out": ""}, "Vui lòng nhập giờ ra."},
		{map[string]any{"time_out": "17:00"}, "Giờ ra phải sau giờ vào."},
		{map[string]any{"time_out": "18:20"}, "Ca dạy phải kéo dài ít nhất 30 phút."},
		{map[string]any{"notes": ""}, "Chấm công tay bắt buộc ghi lý do."},
		{map[string]any{"notes": "quên"}, "Lý do chấm công tay cần ít nhất 5 ký tự."},
		{map[string]any{"teaching_date": "2026-10-20"}, "Không chấm công tay trước cho ngày chưa diễn ra."},
		{map[string]any{"branch_id": f.hcm.String()}, "Lớp đã chọn không thuộc chi nhánh đã chọn."},
		{map[string]any{"hourly_rate": 500}, "Đơn giá giờ tối thiểu 1.000đ."},
		{map[string]any{"late_minutes": 601}, "Số phút đi muộn phải từ 0 đến 600 phút."},
		{map[string]any{"type": "overtime"}, "Chọn loại ca."},
	} {
		message(t, manual(f.cHV, c.patch), 422, c.msg)
	}
	// Quản lý HN không chấm tay lớp chi nhánh khác
	r := manual(f.cMgr, map[string]any{"class_id": clsB.String(), "branch_id": ""})
	iamtest.Expect(t, r, 403, "outside_scope")

	form := f.cHV.Do("GET", "/timesheets/manual", nil)
	iamtest.Expect(t, form, 200, "")
	if len(iamtest.List(t, form, "classes")) != 2 || len(iamtest.List(t, form, "teachers")) < 2 {
		t.Fatalf("form chấm tay: %s", form.Raw)
	}
	if mf := f.cMgr.Do("GET", "/timesheets/manual", nil); len(iamtest.List(t, mf, "classes")) != 1 || mf.Body["default_branch"] != f.hn.String() {
		t.Fatalf("form của quản lý HN: %s", mf.Raw)
	}

	message(t, manual(f.cHV, nil), 201, "Đã ghi nhận chấm công tay (1.5h, gắn buổi học ngày 16/10/2026) — chờ duyệt.")
	message(t, manual(f.cHV, map[string]any{"time_in": "18:10"}), 422,
		"Nhân sự đã được chấm công (chấm tay) cho lớp này ngày 16/10/2026 — không thể tính công 2 lần. Nếu bản ghi cũ sai, hãy từ chối bản ghi đó trước.")
	// Ngày không có buổi học trên lịch
	message(t, manual(f.cHV, map[string]any{"teaching_date": "2026-10-10", "type": "workshop"}), 201,
		"Đã ghi nhận chấm công tay (1.5h, không có buổi học trên lịch) — chờ duyệt.")

	// Giáo viên check-in ở cổng giáo viên (buổi hôm nay, muộn 12 phút)
	out, err := e.svc.RecordCheckin(context.Background(), attendance.CheckinInput{UserID: f.teacher.ID, SessionID: today, At: e.now})
	if err != nil || !out.Recorded {
		t.Fatalf("check-in: %+v %v", out, err)
	}
	out, _ = e.svc.RecordCheckin(context.Background(), attendance.CheckinInput{UserID: f.teacher.ID, SessionID: today, At: e.now})
	if out.Recorded || out.Skip != "bạn đã check-in buổi này lúc 08:12" {
		t.Fatalf("check-in lại: %+v", out)
	}
	out, _ = e.svc.RecordCheckin(context.Background(), attendance.CheckinInput{UserID: f.teacher.ID, SessionID: yesterday, At: e.now})
	if out.Recorded || out.Skip != "buổi này đã được Học vụ chấm công tay" {
		t.Fatalf("check-in buổi đã chấm tay: %+v", out)
	}

	// Màn giờ dạy của Học vụ (mọi lớp)
	page := f.cHV.Do("GET", "/timesheets/teachers?month=2026-10&user_id="+f.teacher.ID.String(), nil)
	iamtest.Expect(t, page, 200, "")
	rows := iamtest.List(t, page, "timesheets")
	if len(rows) != 3 || page.Body["summary"].(map[string]any)["pending_review"] != 3.0 {
		t.Fatalf("ca dạy: %s", page.Raw)
	}
	var checkin, manualRow map[string]any
	for _, row := range rows {
		switch {
		case row["source"] == "checkin":
			checkin = row
		case row["teaching_date"] == "2026-10-16":
			manualRow = row
		}
	}
	if checkin["late_label"] != "Muộn 12p (không báo trước)" || checkin["display_checkout"] != "09:30" || checkin["punch_state"] != "full" ||
		manualRow["notes_short"] != "GV quên check-in" || manualRow["source_label"] != "Chấm tay" || manualRow["hours"] != "1.5" {
		t.Fatalf("dòng ca dạy: %v / %v", checkin, manualRow)
	}
	// Thẻ giáo viên: buổi 15/10 chưa có ca chấm công
	ms := iamtest.List(t, page, "missing_sessions")
	if len(ms) != 1 || ms[0]["date"] != "2026-10-15" || page.Body["teacher"].(map[string]any)["employee_code"] != "GV01" {
		t.Fatalf("buổi thiếu chấm công: %v", ms)
	}
	sched := iamtest.List(t, page, "schedule_sessions")
	if len(sched) != 1 || sched[0]["state"] != "checkin" || sched[0]["can_confirm"] != true {
		t.Fatalf("chấm công theo lịch: %v", sched)
	}
	// Giáo viên chỉ thấy ca của mình, không có bảng theo lịch
	own := f.cTeacher.Do("GET", "/timesheets/teachers?month=2026-10&user_id="+f.hv.ID.String(), nil)
	if len(iamtest.List(t, own, "timesheets")) != 3 || own.Body["teacher"].(map[string]any)["employee_code"] != "GV01" {
		t.Fatalf("giáo viên xem ca: %s", own.Raw)
	}
	// Quản lý HCM không thấy ca của lớp HN
	if b := f.cMgrB.Do("GET", "/timesheets/teachers?month=2026-10", nil); len(iamtest.List(t, b, "timesheets")) != 0 {
		t.Fatalf("quản lý HCM: %s", b.Raw)
	}

	// Đối soát
	id := manualRow["id"].(string)
	message(t, f.cHV.Do("POST", "/timesheets/teachers/"+id+"/review", map[string]any{"decision": "invalid"}), 422, "Vui lòng nhập lý do từ chối ca dạy.")
	iamtest.Expect(t, f.cMgrB.Do("POST", "/timesheets/teachers/"+id+"/review", map[string]any{"decision": "valid"}), 403, "")
	message(t, f.cHV.Do("POST", "/timesheets/teachers/"+id+"/review", map[string]any{"decision": "invalid", "rejection_reason": "Sai giờ"}), 200, "Đã từ chối ca dạy.")
	// Ca bị từ chối của cùng buổi được ghi đè khi chấm lại
	message(t, manual(f.cHV, map[string]any{"time_in": "18:05"}), 201, "Đã ghi nhận chấm công tay (1.42h, gắn buổi học ngày 16/10/2026) — chờ duyệt.")
	if n := e.scalar(`SELECT count(*) FROM hr.teacher_timesheets WHERE class_session_id = $1`, yesterday); n != int64(1) {
		t.Errorf("ghi đè ca bị từ chối: %v", n)
	}

	// Xác nhận theo lịch: buổi 15/10 chưa có ca → tạo ca hợp lệ; buổi tương lai thì không
	var s15 string
	if err := e.Pool.QueryRow(context.Background(), `SELECT id::text FROM academic.class_sessions WHERE date = '2026-10-15'`).Scan(&s15); err != nil {
		t.Fatal(err)
	}
	message(t, f.cHV.Do("POST", "/timesheets/sessions/"+s15+"/confirm", nil), 200, "Đã xác nhận chấm công buổi IELTS-01 ngày 15/10/2026.")
	message(t, f.cHV.Do("POST", "/timesheets/sessions/"+future.String()+"/confirm", nil), 422, "Chưa tới ngày học — không thể xác nhận chấm công trước.")
	if v := e.scalar(`SELECT source || ' ' || status || ' ' || hours::text FROM hr.teacher_timesheets WHERE class_session_id = $1`, s15); v != "schedule valid 1.50" {
		t.Errorf("ca xác nhận theo lịch: %v", v)
	}
	// Xác nhận buổi đã check-in → duyệt ca check-in
	message(t, f.cHV.Do("POST", "/timesheets/sessions/"+today.String()+"/confirm", nil), 200, "Đã xác nhận chấm công buổi IELTS-01 ngày 17/10/2026.")
	if v := e.scalar(`SELECT source || ' ' || status FROM hr.teacher_timesheets WHERE class_session_id = $1`, today); v != "checkin valid" {
		t.Errorf("duyệt ca check-in: %v", v)
	}

	// Chốt bảng công hàng loạt
	var ids []string
	rws, _ := e.Pool.Query(context.Background(), `SELECT id::text FROM hr.teacher_timesheets ORDER BY id`)
	for rws.Next() {
		var s string
		_ = rws.Scan(&s)
		ids = append(ids, s)
	}
	rws.Close()
	message(t, f.cHV.Do("POST", "/timesheets/teachers/bulk-review", map[string]any{"ids": []string{}}), 422, "Chọn ít nhất một ca dạy để chốt.")
	message(t, f.cHV.Do("POST", "/timesheets/teachers/bulk-review", map[string]any{"ids": ids}), 200,
		"Đã chốt 2 ca dạy vào bảng công — bỏ qua 2 ca (không còn chờ duyệt hoặc thuộc kỳ lương đã khóa).")

	// Chỉnh tay → ca về Chờ duyệt
	adj := func(c *iamtest.Client, body map[string]any) iamtest.Resp {
		return c.Do("PUT", "/timesheets/teachers/"+checkin["id"].(string)+"/adjust", body)
	}
	message(t, adj(f.cHV, map[string]any{"time_in": "08:00", "time_out": "09:30"}), 422, "Chỉnh tay bắt buộc ghi lý do.")
	message(t, adj(f.cHV, map[string]any{"time_in": "08:00", "time_out": "09:30", "adjustment_reason": "sai"}), 422, "Lý do điều chỉnh cần ít nhất 5 ký tự.")
	message(t, adj(f.cHV, map[string]any{"time_in": "08:05", "time_out": "09:30", "adjustment_reason": "GV báo giờ thực tế"}), 200,
		"Đã chỉnh tay giờ vào/ra (1.42h) — ca chuyển về Chờ duyệt để đối soát lại.")
	if v := e.scalar(`SELECT status || ' ' || late_minutes FROM hr.teacher_timesheets WHERE id = $1`, checkin["id"]); v != "pending_review 12" {
		t.Errorf("sau chỉnh tay (giữ phút muộn): %v", v)
	}
	iamtest.Expect(t, adj(f.cMgrB, map[string]any{"time_in": "08:05", "time_out": "09:30", "adjustment_reason": "GV báo giờ thực tế"}), 403, "")
	if n := e.scalar(`SELECT count(*) FROM core.audit_log WHERE subject_type = 'teacher_timesheet'`); n.(int64) < 6 {
		t.Errorf("nhật ký ca dạy = %v", n)
	}
}

func TestTimesheetsLocked(t *testing.T) {
	from := time.Date(2026, 9, 1, 0, 0, 0, 0, vn)
	e := setup(t, fakeLock{[]attendance.LockedRange{{From: from, To: from.AddDate(0, 1, -1), Title: "Lương tháng 9/2026"}}})
	f := newFixture(e)
	cls := e.class("IELTS-01", f.hn, &f.teacher.ID)
	s := e.session(cls, f.hn, "2026-09-20", "18:00", "19:30", &f.teacher.ID)
	r := f.cHV.Do("POST", "/timesheets/manual", map[string]any{"user_id": f.teacher.ID.String(), "class_id": cls.String(),
		"teaching_date": "2026-09-20", "time_in": "18:00", "time_out": "19:30", "type": "regular", "notes": "GV quên check-in"})
	message(t, r, 422, "Ngày 20/09/2026 thuộc kỳ lương đã duyệt/đã chi trả — không thể ghi nhận hoặc thay đổi dữ liệu lương cho ngày này.")
	message(t, f.cHV.Do("POST", "/timesheets/sessions/"+s.String()+"/confirm", nil), 422,
		"Ngày 20/09/2026 thuộc kỳ lương đã duyệt/đã chi trả — không thể ghi nhận hoặc thay đổi dữ liệu lương cho ngày này.")
	page := f.cHV.Do("GET", "/timesheets/teachers?month=2026-09", nil)
	if page.Body["period_locked"] != true || page.Body["period"].(map[string]any)["status_label"] != "Đã duyệt" {
		t.Fatalf("kỳ đã khóa: %s", page.Raw)
	}
	form := f.cHV.Do("GET", "/timesheets/manual", nil)
	if lr := iamtest.List(t, form, "locked_ranges"); len(lr) != 1 || lr[0]["to"] != "2026-09-30" {
		t.Fatalf("khoảng khóa: %s", form.Raw)
	}
}

func TestSyncHistory(t *testing.T) {
	e := setup(t, nil)
	f := newFixture(e)
	e.Grant("academic_staff", "attendance_staff.sync")
	r := f.cHV.Do("GET", "/timesheets/sync-history", nil)
	iamtest.Expect(t, r, 200, "")
	if r.Body["has_any_log"] != false || len(iamtest.List(t, r, "logs")) != 0 {
		t.Fatalf("chưa có đợt đồng bộ: %s", r.Raw)
	}
	e.exec(`INSERT INTO hr.timesheet_sync_logs (device_name, records_count, matched_count, failed_count, status, error_rows, created_at)
		VALUES ('Máy HN', 10, 8, 2, 'partial', '[{"employee_code": "GV01", "employee_name": "Giáo viên A", "code": "E01", "message": "Không khớp mã"}]', '2026-10-16 10:00+07'),
		       ('Máy cũ', 5, 0, 5, 'error', NULL, '2026-10-15 09:00+07')`)
	e.exec(`UPDATE hr.timesheet_sync_logs SET error_code = 'TIMEOUT', error_message = 'Mất kết nối', branch_id = $1 WHERE device_name = 'Máy cũ'`, f.hn)
	logs := iamtest.List(t, f.cHV.Do("GET", "/timesheets/sync-history", nil), "logs")
	if len(logs) != 2 || logs[0]["created_at"] != "10:00 16/10/2026" || logs[0]["branch"] != "Toàn hệ thống" || logs[1]["status_label"] != "Lỗi toàn bộ" {
		t.Fatalf("đợt đồng bộ: %v", logs)
	}
	failed := iamtest.List(t, f.cHV.Do("GET", "/timesheets/sync-history?status=failed", nil), "logs")
	if len(failed) != 1 || failed[0]["status"] != "failed" || failed[0]["has_errors"] != true {
		t.Fatalf("lọc lỗi toàn bộ: %v", failed)
	}
	if got := iamtest.List(t, f.cHV.Do("GET", "/timesheets/sync-history?from=2026-10-16&to=2026-10-16", nil), "logs"); len(got) != 1 {
		t.Fatalf("lọc ngày: %v", got)
	}
	for _, c := range []struct {
		id   any
		want [][]string
	}{
		{logs[0]["id"], [][]string{{"Mã NV", "Tên nhân viên", "Mã lỗi", "Nội dung chi tiết"}, {"GV01", "Giáo viên A", "E01", "Không khớp mã"}}},
		{logs[1]["id"], [][]string{{"Mã NV", "Tên nhân viên", "Mã lỗi", "Nội dung chi tiết"}, {"", "", "TIMEOUT", "Mất kết nối"}}},
	} {
		x := f.cHV.Do("GET", fmt.Sprintf("/timesheets/sync-history/%v/errors", c.id), nil)
		if x.Status != 200 || !bytes.HasPrefix(x.Raw, []byte("\xEF\xBB\xBF")) {
			t.Fatalf("file lỗi: %d %s", x.Status, x.Raw)
		}
		rows, err := csv.NewReader(bytes.NewReader(bytes.TrimPrefix(x.Raw, []byte("\xEF\xBB\xBF")))).ReadAll()
		if err != nil || fmt.Sprint(rows) != fmt.Sprint(c.want) {
			t.Fatalf("file lỗi: %v %v", rows, err)
		}
	}
	iamtest.Expect(t, f.cHV.Do("GET", "/timesheets/sync-history/"+uuid.NewString()+"/errors", nil), 404, "sync_log_not_found")
}

func TestReadsKpiDashboard(t *testing.T) {
	e := setup(t, nil)
	f := newFixture(e)
	ctx := context.Background()
	cls := e.class("IELTS-01", f.hn, &f.teacher.ID)
	s14 := e.session(cls, f.hn, "2026-10-14", "18:00", "19:30", &f.teacher.ID)
	e.session(cls, f.hn, "2026-10-15", "18:00", "19:30", &f.teacher.ID)
	e.session(cls, f.hn, "2026-10-16", "18:00", "19:30", &f.teacher.ID)
	e.session(cls, f.hn, "2026-10-17", "18:00", "19:30", &f.teacher.ID) // hôm nay: chưa tính vắng
	// 14/10: check-in muộn 7 phút rồi được duyệt; 16/10: nghỉ có phép; 15/10: vắng không phép
	at := time.Date(2026, 10, 14, 18, 7, 0, 0, vn)
	if out, err := e.svc.RecordCheckin(ctx, attendance.CheckinInput{UserID: f.teacher.ID, SessionID: s14, At: at}); err != nil || !out.Recorded {
		t.Fatal(out, err)
	}
	e.exec(`UPDATE hr.teacher_timesheets SET status = 'valid'`)
	e.exec(`INSERT INTO hr.staff_attendance_requests (user_id, type, date_from, date_to, reason, status)
		VALUES ($1, 'leave', '2026-10-16', '2026-10-16', 'Ốm', 'approved')`, f.teacher.ID)
	// Nhân sự văn phòng: muộn 12 phút (tính KPI), muộn 8 phút (dưới ngưỡng 10 phút)
	message(t, punch(f.cSales, "in", nearLat, hnLng, true), 200, "Đã chấm công vào lúc 08:12. Bạn đi muộn 12 phút.")
	e.exec(`INSERT INTO hr.staff_attendances (user_id, branch_id, work_date, check_in_at, late_minutes)
		VALUES ($1, $2, '2026-10-16', '2026-10-16 08:08+07', 8)`, f.sales.ID, f.hn)

	from, to := time.Date(2026, 10, 1, 0, 0, 0, 0, vn), time.Date(2026, 10, 31, 23, 59, 0, 0, vn)
	src := e.svc.KpiSources()
	texts := func(key string, user uuid.UUID) []string {
		r, err := src[key](ctx, user, from, to)
		if err != nil {
			t.Fatal(err)
		}
		var out []string
		for _, x := range r.Records {
			out = append(out, x.At.Format("02/01")+" "+x.Text)
		}
		return out
	}
	if got := texts("staff_late", f.sales.ID); fmt.Sprint(got) != "[17/10 Đi muộn 12 phút (check-in 08:12)]" {
		t.Errorf("staff_late: %v", got)
	}
	if got := texts("teacher_late", f.teacher.ID); fmt.Sprint(got) != "[14/10 Lớp IELTS-01: muộn 7 phút]" {
		t.Errorf("teacher_late: %v", got)
	}
	if got := texts("teacher_absent_unexcused", f.teacher.ID); fmt.Sprint(got) != "[15/10 Buổi IELTS-01 18:00: không có giờ dạy, không có đơn nghỉ được duyệt]" {
		t.Errorf("teacher_absent_unexcused: %v", got)
	}
	if got := texts("teacher_leave", f.teacher.ID); fmt.Sprint(got) != "[16/10 Nghỉ có phép: IELTS-01]" {
		t.Errorf("teacher_leave: %v", got)
	}

	stats, ok, err := e.svc.DashboardWork().Stats(ctx, []uuid.UUID{f.teacher.ID, f.sales.ID, f.hv.ID}, from, to)
	if err != nil || !ok {
		t.Fatal(ok, err)
	}
	if w := stats[f.teacher.ID]; w.WorkDays != 1 || w.Late != 1 || w.LeaveDays != 1 {
		t.Errorf("Tổng quan GV: %+v", w)
	}
	if w := stats[f.sales.ID]; w.WorkDays != 2 || w.Late != 2 || w.LeaveDays != 0 {
		t.Errorf("Tổng quan nhân sự: %+v", w)
	}

	// API cho phân hệ Lương
	hours, err := e.svc.TeachingHours(ctx, []uuid.UUID{f.teacher.ID, f.hv.ID}, from, to)
	if err != nil || hours[f.teacher.ID] != 1.5 || hours[f.hv.ID] != 0 {
		t.Errorf("giờ dạy: %v %v", hours, err)
	}
	sums, err := e.svc.Summaries(ctx, []uuid.UUID{f.sales.ID}, from, to)
	if err != nil || sums[f.sales.ID].Days != 2 || sums[f.sales.ID].LateCount != 2 {
		t.Errorf("tổng hợp công: %+v %v", sums, err)
	}
	changed, err := e.svc.ChangedSince(ctx, from, to, time.Now().Add(-time.Hour))
	if err != nil || !changed {
		t.Errorf("ChangedSince: %v %v", changed, err)
	}
	changed, _ = e.svc.ChangedSince(ctx, from, to, time.Now().Add(time.Hour))
	if changed {
		t.Error("ChangedSince sau thời điểm sửa cuối")
	}
}
