package portal

import (
	"bytes"
	"context"
	"strings"
	"testing"
	"time"

	"github.com/go-chi/chi/v5"
	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/files"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam/iamtest"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/notify"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/filestore"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/students"
)

// Test HTTP với Postgres / Redis thật (TEST_DATABASE_URL, TEST_REDIS_URL).

type fakeTuition struct{}

func (fakeTuition) PortalPayments(_ context.Context, id uuid.UUID) (TuitionTotals, any, error) {
	return TuitionTotals{HasTuition: true, FinalAmount: 3_000_000, PaidAmount: 1_000_000, DebtAmount: 2_000_000, Installments: 1},
		map[string]any{"student_id": id.String()}, nil
}

type env struct {
	*iamtest.Env
	t   *testing.T
	svc *Service
}

func setup(t *testing.T) *env {
	t.Helper()
	e := iamtest.Setup(t, "academic.students", "academic.classes", "finance.payment_reports", "academic.pronunciation_records",
		"academic.stage_feedbacks", "core.notifications", "core.files", "core.audit_log")
	store, err := filestore.NewLocal(t.TempDir(), files.APIBase+files.BlobPrefix, bytes.Repeat([]byte{7}, 32))
	if err != nil {
		t.Fatal(err)
	}
	fsvc := files.NewService(e.Pool, store, e.Log, 0)
	svc := NewService(e.Pool, fsvc, students.NewService(e.Pool, e.IAM, e.Log), e.Log).WithTuition(fakeTuition{})
	fh := files.NewHandler(fsvc, e.Log)
	e.Serve(func(r chi.Router) {
		NewHandler(svc, e.Log).Routes(r)
		fh.Routes(r)
		fh.PublicRoutes(r)
	})
	return &env{Env: e, t: t, svc: svc}
}

func (e *env) exec(sql string, args ...any) {
	e.t.Helper()
	if _, err := e.Pool.Exec(context.Background(), sql, args...); err != nil {
		e.t.Fatal(err)
	}
}

func (e *env) class(branch uuid.UUID, code string, teacher *uuid.UUID) uuid.UUID {
	e.t.Helper()
	var id uuid.UUID
	if err := e.Pool.QueryRow(context.Background(),
		"INSERT INTO academic.classes (code, name, branch_id, teacher_id, room, status) VALUES ($1, $2, $3, $4, '101', 'active') RETURNING id",
		code, "Lớp "+code, branch, teacher).Scan(&id); err != nil {
		e.t.Fatal(err)
	}
	return id
}

// account: tài khoản cổng (học viên đăng nhập bằng mã, phụ huynh bằng SĐT) đã đổi mật khẩu, vai trò student.
func (e *env) account(kind, code, phone, name string) iamtest.User {
	e.t.Helper()
	ctx := context.Background()
	in := iam.NewUser{Kind: kind, FullName: name}
	login := code
	if kind == "guardian" {
		in.Phone, login = phone, phone
	} else {
		in.Code = code
	}
	u, _, err := e.IAM.CreateUser(ctx, in)
	if err != nil {
		e.t.Fatal(err)
	}
	hash, _ := iam.HashPassword(ctx, iamtest.Password)
	e.exec("UPDATE iam.users SET password_hash = $2, must_change_password = false WHERE id = $1", u.ID, hash)
	if err := e.IAM.SetRolesUnchecked(ctx, u.ID, []string{iam.RoleStudent}); err != nil {
		e.t.Fatal(err)
	}
	return iamtest.User{ID: u.ID, Code: login}
}

// student: hồ sơ học viên (user nil = chưa có tài khoản cổng).
func (e *env) student(code, name string, branch uuid.UUID, class *uuid.UUID, user *uuid.UUID) uuid.UUID {
	e.t.Helper()
	var id uuid.UUID
	if err := e.Pool.QueryRow(context.Background(),
		`INSERT INTO academic.students (code, full_name, phone, branch_id, current_class_id, user_id, status)
		 VALUES ($1, $2, '0900000000', $3, $4, $5, 'studying') RETURNING id`, code, name, branch, class, user).Scan(&id); err != nil {
		e.t.Fatal(err)
	}
	return id
}

func msg(t *testing.T, r iamtest.Resp, status int, want string) {
	t.Helper()
	if r.Status != status || r.Body["message"] != want {
		t.Fatalf("muốn %d %q, nhận %d %s", status, want, r.Status, r.Raw)
	}
}

func obj(t *testing.T, r iamtest.Resp, key string) map[string]any {
	t.Helper()
	m, ok := r.Body[key].(map[string]any)
	if !ok {
		t.Fatalf("thiếu %q: %s", key, r.Raw)
	}
	return m
}

var mp3 = []byte("ID3\x03\x00 bản ghi âm thử")

func TestPortalAccessAndHome(t *testing.T) {
	e := setup(t)
	a, b := e.Branch("CSA"), e.Branch("CSB")
	clsA := e.class(a, "L01", nil)
	hsU := e.account("student", "HS001", "", "Nguyễn An")
	hs := e.student("HS001", "Nguyễn An", a, &clsA, &hsU.ID)
	other := e.student("HS002", "Trần Bình", b, nil, nil)
	childA := e.student("HS003", "Lê Chi", a, &clsA, nil)
	childB := e.student("HS004", "Lê Dũng", b, nil, nil)
	ph := e.account("guardian", "", "0912345678", "Phụ huynh Lê")
	e.exec("INSERT INTO academic.student_guardians (student_id, guardian_id) VALUES ($1, $3), ($2, $3)", childA, childB, ph.ID)
	tomorrow := time.Now().In(vnZone).AddDate(0, 0, 1).Format("2006-01-02")
	e.exec(`INSERT INTO academic.class_sessions (class_id, branch_id, date, start_time, end_time, room)
		VALUES ($1, $2, $3, '18:00', '19:30', '101')`, clsA, a, tomorrow)

	iamtest.Expect(t, e.Anon().Do("GET", "/portal/home", nil), 401, "")
	_, teacher := e.Member("GV001", "Giáo viên", []uuid.UUID{a}, iam.RoleTeacherFulltime)
	iamtest.Expect(t, teacher.Do("GET", "/portal/home", nil), 403, "forbidden")

	// Học viên: thấy hồ sơ của mình, lịch học sắp tới, học phí; hồ sơ khác → 403
	c := e.Login(hsU)
	r := c.Do("GET", "/portal/home", nil)
	iamtest.Expect(t, r, 200, "")
	if obj(t, r, "student")["id"] != hs.String() || r.Body["can_write"] != true || r.Body["staff"] != false {
		t.Fatalf("học viên đang xem sai: %s", r.Raw)
	}
	up := iamtest.List(t, r, "upcoming_sessions")
	if len(up) != 1 || up[0]["start_time"] != "18:00" || up[0]["room_label"] != "Phòng 101" || up[0]["class_name"] != "Lớp L01" {
		t.Fatalf("lịch học sắp tới: %s", r.Raw)
	}
	if obj(t, r, "tuition")["debt_amount"] != float64(2_000_000) || obj(t, r, "profile")["status_label"] == "" {
		t.Fatalf("học phí / hồ sơ: %s", r.Raw)
	}
	iamtest.Expect(t, c.Do("GET", "/portal/home?student_id="+other.String(), nil), 403, "forbidden")
	iamtest.Expect(t, c.Do("GET", "/portal/tuition", nil), 200, "")
	iamtest.Expect(t, c.Do("GET", "/portal/app-shell", nil), 200, "")

	// Phụ huynh hai con: mặc định con đầu, đổi được sang con thứ hai
	pc := e.Login(ph)
	r = pc.Do("GET", "/portal/home", nil)
	iamtest.Expect(t, r, 200, "")
	if len(iamtest.List(t, r, "students")) != 2 {
		t.Fatalf("phụ huynh thấy %s", r.Raw)
	}
	r = pc.Do("GET", "/portal/home?student_id="+childB.String(), nil)
	if r.Status != 200 || obj(t, r, "student")["id"] != childB.String() {
		t.Fatalf("đổi học viên: %d %s", r.Status, r.Raw)
	}

	// Nhân sự học vụ chi nhánh A: xem hộ học viên trong phạm vi, ngoài chi nhánh → 403
	_, staffA := e.Member("HV001", "Học vụ A", []uuid.UUID{a}, iam.RoleAcademicStaff)
	r = staffA.Do("GET", "/portal/home", nil)
	if r.Status != 200 || r.Body["student"] != nil || r.Body["staff"] != true {
		t.Fatalf("nhân sự chưa chọn học viên: %d %s", r.Status, r.Raw)
	}
	r = staffA.Do("GET", "/portal/home?student_id="+hs.String(), nil)
	if r.Status != 200 || r.Body["can_write"] != true {
		t.Fatalf("nhân sự xem hộ: %d %s", r.Status, r.Raw)
	}
	iamtest.Expect(t, staffA.Do("GET", "/portal/home?student_id="+other.String(), nil), 403, "forbidden")
	// Hồ sơ đã nghỉ học không còn vào cổng
	e.exec("UPDATE academic.students SET status = 'dropped' WHERE id = $1", hs)
	iamtest.Expect(t, c.Do("GET", "/portal/home", nil), 403, "not_linked")
}

func TestPortalContactAndPaymentReport(t *testing.T) {
	e := setup(t)
	a, b := e.Branch("CSA"), e.Branch("CSB")
	hsU := e.account("student", "HS001", "", "Nguyễn An")
	hs := e.student("HS001", "Nguyễn An", a, nil, &hsU.ID)
	c := e.Login(hsU)

	// Sửa liên hệ
	path := "/portal/students/" + hs.String() + "/contact"
	msg(t, c.Do("PATCH", path, map[string]any{"phone": " "}), 422, "Vui lòng nhập Số điện thoại.")
	msg(t, c.Do("PATCH", path, map[string]any{"phone": strings.Repeat("9", 21)}), 422, "Trường Số điện thoại không được vượt quá 20 ký tự.")
	msg(t, c.Do("PATCH", path, map[string]any{"phone": "0987654321", "address": "Hà Nội"}), 200, "Đã cập nhật thông tin học viên thành công!")
	var phone string
	_ = e.Pool.QueryRow(context.Background(), "SELECT phone FROM academic.students WHERE id = $1", hs).Scan(&phone)
	if phone != "0987654321" {
		t.Fatalf("SĐT chưa đổi: %s", phone)
	}
	_, staffB := e.Member("HV002", "Học vụ B", []uuid.UUID{b}, iam.RoleAcademicStaff)
	iamtest.Expect(t, staffB.Do("PATCH", path, map[string]any{"phone": "0911111111"}), 403, "forbidden")

	// Báo đóng học phí
	sid := hs.String()
	msg(t, c.Do("POST", "/portal/payment-reports", map[string]any{"student_id": sid, "content": "CK"}), 422, "Vui lòng nhập Số tiền.")
	msg(t, c.Do("POST", "/portal/payment-reports", map[string]any{"student_id": sid, "amount": 500, "content": "CK"}), 422,
		"Trường Số tiền phải tối thiểu là 1.000.")
	msg(t, c.Do("POST", "/portal/payment-reports", map[string]any{"student_id": sid, "amount": 1500000}), 422,
		"Vui lòng nhập nội dung chuyển khoản / ghi chú.")
	iamtest.Expect(t, staffB.Do("POST", "/portal/payment-reports", map[string]any{"student_id": sid, "amount": 1500000, "content": "CK"}), 403, "forbidden")
	r := c.Do("POST", "/portal/payment-reports", map[string]any{"student_id": sid, "amount": 1500000, "content": "CK HS001 đợt 2"})
	iamtest.Expect(t, r, 200, "")
	rep := obj(t, r, "report")
	if !strings.HasPrefix(rep["code"].(string), "YCHOCPHI-") || rep["status"] != "pending" {
		t.Fatalf("báo đóng: %s", r.Raw)
	}
	r = c.Do("GET", "/portal/home", nil)
	if l := iamtest.List(t, r, "payment_reports"); len(l) != 1 || l[0]["status_label"] != "Chờ kế toán xác nhận" {
		t.Fatalf("trang chủ chưa có yêu cầu: %s", r.Raw)
	}

	// Kế toán: học vụ không có tuition.approve → 403; Admin xác nhận / từ chối
	iamtest.Expect(t, staffB.Do("GET", "/payment-reports", nil), 403, "forbidden")
	_, admin := e.Member("AD001", "Quản trị", []uuid.UUID{a}, iam.RoleAdmin)
	list := iamtest.List(t, admin.Do("GET", "/payment-reports", nil), "data")
	if len(list) != 1 || list[0]["student_code"] != "HS001" {
		t.Fatalf("hộp duyệt: %v", list)
	}
	id := list[0]["id"].(string)
	msg(t, admin.Do("POST", "/payment-reports/"+id+"/reject", map[string]any{"reason": " "}), 422, "Vui lòng nhập lý do từ chối.")
	msg(t, admin.Do("POST", "/payment-reports/"+id+"/reject", map[string]any{"reason": "Chưa thấy tiền về"}), 200,
		"Đã từ chối, học viên được báo qua hộp thư.")
	msg(t, admin.Do("POST", "/payment-reports/"+id+"/confirm", nil), 409, "Yêu cầu không còn chờ xác nhận hoặc ngoài phạm vi của bạn.")
	r = c.Do("POST", "/portal/payment-reports", map[string]any{"student_id": sid, "amount": 2000000, "content": "CK lần 2"})
	id2 := obj(t, r, "report")["id"].(string)
	msg(t, admin.Do("POST", "/payment-reports/"+id2+"/confirm", nil), 200, "Đã xác nhận, học viên được báo qua hộp thư.")

	inbox := iamtest.List(t, c.Do("GET", "/portal/notifications", nil), "notifications")
	if len(inbox) != 2 || inbox[0]["title"] != "Kế toán đã xác nhận khoản đóng 2.000.000đ" ||
		inbox[1]["title"] != "Kế toán chưa xác nhận khoản đóng 1.500.000đ" ||
		!strings.Contains(inbox[1]["content"].(string), "Lý do: Chưa thấy tiền về.") {
		t.Fatalf("hộp thư: %v", inbox)
	}
	if l := iamtest.List(t, admin.Do("GET", "/payment-reports?status=rejected", nil), "data"); len(l) != 1 || l[0]["rejection_reason"] != "Chưa thấy tiền về" {
		t.Fatalf("lọc đã từ chối: %v", l)
	}
}

func TestPortalInbox(t *testing.T) {
	e := setup(t)
	a := e.Branch("CSA")
	ph := e.account("guardian", "", "0912345678", "Phụ huynh")
	hsU := e.account("student", "HS001", "", "Con lớn")
	big := e.student("HS001", "Con lớn", a, nil, &hsU.ID)
	small := e.student("HS002", "Con nhỏ", a, nil, nil)
	e.exec("INSERT INTO academic.student_guardians (student_id, guardian_id) VALUES ($1, $3), ($2, $3)", big, small, ph.ID)
	ctx := context.Background()
	send := func(to uuid.UUID, title string, data map[string]any) {
		if _, err := notify.Send(ctx, e.Pool, []uuid.UUID{to}, notify.Notification{Type: "tuition_payment_report", Title: title, Body: "Nội dung", Data: data}); err != nil {
			t.Fatal(err)
		}
	}
	send(hsU.ID, "Gửi tài khoản con lớn", nil)
	send(ph.ID, "Gửi phụ huynh về con lớn", map[string]any{"student_id": big.String()})
	send(ph.ID, "Gửi phụ huynh về con nhỏ", map[string]any{"student_id": small.String()})
	send(ph.ID, "Gửi phụ huynh chung", nil)

	pc := e.Login(ph)
	r := pc.Do("GET", "/portal/notifications?student_id="+big.String(), nil)
	if l := iamtest.List(t, r, "notifications"); len(l) != 3 || r.Body["unread_count"] != float64(3) {
		t.Fatalf("hộp thư con lớn: %s", r.Raw)
	}
	small3 := iamtest.List(t, pc.Do("GET", "/portal/notifications?student_id="+small.String(), nil), "notifications")
	if len(small3) != 2 {
		t.Fatalf("hộp thư con nhỏ: %v", small3)
	}
	if r := pc.Do("GET", "/portal/summary", nil); r.Body["unread_count"] != float64(4) {
		t.Fatalf("chuông: %s", r.Raw)
	}
	// Đánh dấu một / tất cả, xóa; thông báo của con khác → 404
	var first, ofBig string
	for _, n := range small3 {
		if n["title"] == "Gửi phụ huynh về con nhỏ" {
			first = n["id"].(string)
		}
	}
	for _, n := range iamtest.List(t, pc.Do("GET", "/portal/notifications?student_id="+big.String(), nil), "notifications") {
		if n["title"] == "Gửi phụ huynh về con lớn" {
			ofBig = n["id"].(string)
		}
	}
	msg(t, pc.Do("POST", "/portal/notifications/"+first+"/read", map[string]any{"student_id": small.String()}), 200, "Đã đánh dấu thông báo là đã đọc.")
	iamtest.Expect(t, pc.Do("POST", "/portal/notifications/"+ofBig+"/read", map[string]any{"student_id": small.String()}), 404, "not_found")
	msg(t, pc.Do("POST", "/portal/notifications/read-all", map[string]any{"student_id": big.String()}), 200, "Đã đánh dấu tất cả thông báo là đã đọc!")
	if r := pc.Do("GET", "/portal/summary", nil); r.Body["unread_count"] != float64(0) {
		t.Fatalf("còn chưa đọc: %s", r.Raw)
	}
	msg(t, pc.Do("DELETE", "/portal/notifications/"+ofBig+"?student_id="+big.String(), nil), 200, "Đã xóa thông báo thành công.")
	iamtest.Expect(t, pc.Do("DELETE", "/portal/notifications/"+ofBig+"?student_id="+big.String(), nil), 404, "not_found")
	// Học viên không xóa được hộp thư của em (không gắn)
	iamtest.Expect(t, e.Login(hsU).Do("POST", "/portal/notifications/read-all", map[string]any{"student_id": small.String()}), 403, "forbidden")
}

func TestPortalPronunciationAndGrading(t *testing.T) {
	e := setup(t)
	a := e.Branch("CSA")
	gv, gvC := e.Member("GV001", "Cô Hoa", []uuid.UUID{a}, iam.RoleTeacherFulltime)
	cls := e.class(a, "L01", &gv.ID)
	cls2 := e.class(a, "L02", nil)
	hsU := e.account("student", "HS001", "", "Nguyễn An")
	hs := e.student("HS001", "Nguyễn An", a, &cls, &hsU.ID)
	otherU := e.account("student", "HS002", "", "Trần Bình")
	e.student("HS002", "Trần Bình", a, &cls2, &otherU.ID)
	c := e.Login(hsU)
	sid := hs.String()

	msg(t, c.Upload("/portal/pronunciation", map[string]string{"student_id": sid, "unit_title": "Unit 1"}, "", nil), 422,
		"Chưa có bản ghi âm. Hãy chạm micro để thu âm trước khi nộp.")
	msg(t, c.Upload("/portal/pronunciation", map[string]string{"student_id": sid}, "rec.mp3", mp3), 422, "Vui lòng nhập bài đang luyện.")
	r := c.Upload("/portal/pronunciation", map[string]string{"student_id": sid, "unit_title": "Unit 1: Hello", "duration": "00:12"}, "rec.mp3", mp3)
	iamtest.Expect(t, r, 200, "")
	rec := obj(t, r, "record")
	if rec["status"] != "pending_review" || rec["audio_url"] == nil || rec["title"] != "Bản ghi âm: Unit 1: Hello - Nguyễn An" {
		t.Fatalf("bản ghi âm: %s", r.Raw)
	}
	id := rec["id"].(string)
	if l := iamtest.List(t, c.Do("GET", "/portal/pronunciation", nil), "history"); len(l) != 1 {
		t.Fatalf("lịch sử: %v", l)
	}
	// File ghi âm: chủ hồ sơ và giáo viên lớp tải được, học viên khác thì không
	audio := rec["audio_url"].(string)
	if r := c.Get(audio); r.Status != 302 {
		t.Fatalf("chủ hồ sơ tải file: %d %s", r.Status, r.Raw)
	}
	if r := gvC.Get(audio); r.Status != 302 {
		t.Fatalf("giáo viên tải file: %d %s", r.Status, r.Raw)
	}
	oc := e.Login(otherU)
	if r := oc.Get(audio); r.Status != 403 {
		t.Fatalf("học viên khác tải file: %d", r.Status)
	}
	iamtest.Expect(t, oc.Do("DELETE", "/portal/pronunciation/"+id, nil), 403, "forbidden")

	// Tab Phát âm của màn chấm bài (phân hệ Cổng giáo viên gọi qua adapter): bản ghi âm theo danh sách lớp, chấm điểm
	ctx := context.Background()
	recs, err := e.svc.ClassPronunciations(ctx, cls)
	if err != nil || len(recs) != 1 || recs[0].StudentName != "Nguyễn An" || recs[0].AudioURL == nil {
		t.Fatalf("tab phát âm: %+v %v", recs, err)
	}
	if recs, _ := e.svc.ClassPronunciations(ctx, cls2); len(recs) != 0 {
		t.Fatalf("lớp khác: %+v", recs)
	}
	deny := func(uuid.UUID) (bool, error) { return false, nil }
	allow := func(st uuid.UUID) (bool, error) { return st == hs, nil }
	if ok, err := e.svc.MarkPronunciation(ctx, uuid.MustParse(id), gv.ID, "90", "", deny); ok || err != nil {
		t.Fatalf("ngoài lớp phụ trách: %v %v", ok, err)
	}
	if ok, err := e.svc.MarkPronunciation(ctx, uuid.New(), gv.ID, "90", "", allow); ok || err != nil {
		t.Fatalf("không có bản ghi: %v %v", ok, err)
	}
	if ok, err := e.svc.MarkPronunciation(ctx, uuid.MustParse(id), gv.ID, " 90 ", " Phát âm rõ ", allow); !ok || err != nil {
		t.Fatalf("chấm: %v %v", ok, err)
	}
	h := iamtest.List(t, c.Do("GET", "/portal/pronunciation", nil), "history")
	if h[0]["status"] != "reviewed" || h[0]["score"] != "90" || h[0]["feedback"] != "Phát âm rõ" {
		t.Fatalf("sau chấm: %v", h)
	}
	if l := iamtest.List(t, c.Do("GET", "/portal/notifications", nil), "notifications"); len(l) != 1 || l[0]["type"] != "pronunciation_reviewed" {
		t.Fatalf("thông báo chấm: %v", l)
	}
	// Chưa gắn phân hệ Cổng giáo viên: nộp bài báo chờ phân hệ
	hw := c.Do("GET", "/portal/homework", nil)
	if hw.Body["ready"] != false || len(iamtest.List(t, hw, "cards")) != 6 {
		t.Fatalf("học tập: %s", hw.Raw)
	}
	msg(t, c.Upload("/portal/homework", map[string]string{"student_id": sid, "homework_type": "khác"}, "", nil), 422, "Loại bài tập không hợp lệ.")
	iamtest.Expect(t, c.Upload("/portal/homework", map[string]string{"student_id": sid, "homework_type": "video"}, "", nil), 503, "module_pending")

	msg(t, c.Do("DELETE", "/portal/pronunciation/"+id, nil), 200, "Đã xóa bản ghi âm thành công!")
	if l := iamtest.List(t, c.Do("GET", "/portal/pronunciation", nil), "history"); len(l) != 0 {
		t.Fatalf("chưa xóa: %v", l)
	}
}

func TestPortalFeedbackAndSurvey(t *testing.T) {
	e := setup(t)
	a := e.Branch("CSA")
	cls := e.class(a, "L01", nil)
	hsU := e.account("student", "HS001", "", "Nguyễn An")
	hs := e.student("HS001", "Nguyễn An", a, &cls, &hsU.ID)
	otherU := e.account("student", "HS002", "", "Trần Bình")
	e.student("HS002", "Trần Bình", a, nil, &otherU.ID)
	c := e.Login(hsU)
	sid := hs.String()

	msg(t, c.Do("POST", "/portal/feedback", map[string]any{"student_id": sid}), 422,
		"Vui lòng điền ít nhất 1 mục (Nội dung, Lĩnh vực góp ý hoặc Chọn mức hài lòng) để gửi feedback.")
	r := c.Do("POST", "/portal/feedback", map[string]any{"student_id": sid, "rating": 4})
	if r.Status != 200 || r.Body["created"] != true {
		t.Fatalf("gửi góp ý: %d %s", r.Status, r.Raw)
	}
	r = c.Do("POST", "/portal/feedback", map[string]any{"student_id": sid, "rating": 5, "fb_giao_vien": true, "content": "Cô dạy dễ hiểu"})
	if r.Status != 200 || r.Body["created"] != false {
		t.Fatalf("sửa góp ý: %d %s", r.Status, r.Raw)
	}
	r = c.Do("GET", "/portal/feedback", nil)
	if r.Body["stage_name"] != GeneralStage || r.Body["class_name"] != "Lớp L01" || len(iamtest.List(t, r, "history")) != 1 {
		t.Fatalf("màn góp ý: %s", r.Raw)
	}
	last := obj(t, r, "last_feedback")
	if last["rating"] != float64(5) || last["content"] != "Cô dạy dễ hiểu" {
		t.Fatalf("góp ý gần nhất: %v", last)
	}
	fid := last["id"].(string)
	iamtest.Expect(t, e.Login(otherU).Do("DELETE", "/portal/feedback/"+fid, nil), 403, "forbidden")
	msg(t, c.Do("DELETE", "/portal/feedback/"+fid, nil), 200, "Đã xóa feedback thành công! Trạng thái đã được đặt lại ban đầu.")

	// Khảo sát: kiểm dữ liệu như Laravel, gửi thật chờ phân hệ Khảo sát
	sv := uuid.NewString()
	msg(t, c.Do("POST", "/portal/surveys", map[string]any{"student_id": sid, "survey_id": sv, "feedback": "Tốt"}), 422,
		"Trường ý kiến phải có tối thiểu 5 ký tự.")
	iamtest.Expect(t, c.Do("POST", "/portal/surveys", map[string]any{"student_id": sid, "survey_id": sv, "rating": 5, "feedback": "Trung tâm rất tốt"}),
		503, "module_pending")
	if r := c.Do("GET", "/portal/surveys", nil); r.Status != 200 || r.Body["ready"] != false {
		t.Fatalf("màn khảo sát: %d %s", r.Status, r.Raw)
	}
}
