package students

import (
	"bytes"
	"context"
	"crypto/rand"
	"encoding/base64"
	"encoding/json"
	"io"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"os"
	"strings"
	"testing"
	"time"

	"github.com/go-chi/chi/v5"
	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgxpool"
	"github.com/pquerna/otp/totp"
	"github.com/redis/go-redis/v9"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/secretbox"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/store"
)

// Test qua HTTP với Postgres và Redis thật (TEST_DATABASE_URL, TEST_REDIS_URL; Redis bị xóa sạch).
type env struct {
	t   *testing.T
	pg  *pgxpool.Pool
	iam *iam.Service
	svc *Service
	srv *httptest.Server
	box *secretbox.Box
	rdb *redis.Client
}

const password = "Trung-tam-tieng-anh-2026"

func setup(t *testing.T) *env {
	t.Helper()
	dbURL, redisURL := os.Getenv("TEST_DATABASE_URL"), os.Getenv("TEST_REDIS_URL")
	if dbURL == "" || redisURL == "" {
		t.Skip("cần TEST_DATABASE_URL và TEST_REDIS_URL")
	}
	ctx := context.Background()
	pool, err := store.OpenPostgres(ctx, dbURL)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(pool.Close)
	if err := store.Migrate(ctx, pool, "up"); err != nil {
		t.Fatal(err)
	}
	if _, err := pool.Exec(ctx, "TRUNCATE iam.users, iam.roles, iam.role_permissions, core.branches, academic.students CASCADE"); err != nil {
		t.Fatal(err)
	}
	if _, err := iam.SyncCatalog(ctx, pool); err != nil {
		t.Fatal(err)
	}
	rdb, err := store.OpenRedis(redisURL)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { rdb.Close() })
	if err := rdb.FlushDB(ctx).Err(); err != nil {
		t.Fatal(err)
	}
	key := make([]byte, 32)
	_, _ = rand.Read(key)
	box, _ := secretbox.FromBase64(base64.StdEncoding.EncodeToString(key))
	log := slog.New(slog.NewTextHandler(io.Discard, nil))
	iamSvc := iam.NewService(pool, rdb, box, log)
	svc := NewService(pool, iamSvc, log)
	auth := iam.NewHandler(iamSvc, log)

	r := chi.NewRouter()
	r.Route("/api/v1", func(r chi.Router) {
		r.Use(iam.SameOrigin, auth.LoadSession)
		auth.Routes(r)
		NewHandler(svc, log).Routes(r)
	})
	srv := httptest.NewServer(r)
	t.Cleanup(srv.Close)
	return &env{t: t, pg: pool, iam: iamSvc, svc: svc, srv: srv, box: box, rdb: rdb}
}

func (e *env) exec(sql string, args ...any) {
	e.t.Helper()
	if _, err := e.pg.Exec(context.Background(), sql, args...); err != nil {
		e.t.Fatal(err)
	}
}

func (e *env) branch(code string) uuid.UUID {
	e.t.Helper()
	var id uuid.UUID
	if err := e.pg.QueryRow(context.Background(), "INSERT INTO core.branches (code, name) VALUES ($1, $2) RETURNING id",
		code, "Chi nhánh "+code).Scan(&id); err != nil {
		e.t.Fatal(err)
	}
	return id
}

// class: một lớp (academic.classes) của chi nhánh để gán làm lớp hiện tại.
func (e *env) class(branch uuid.UUID, code string) uuid.UUID {
	e.t.Helper()
	var id uuid.UUID
	if err := e.pg.QueryRow(context.Background(), "INSERT INTO academic.classes (code, name, branch_id) VALUES ($1, $2, $3) RETURNING id",
		code, "Lớp "+code, branch).Scan(&id); err != nil {
		e.t.Fatal(err)
	}
	return id
}

type member struct {
	id     uuid.UUID
	code   string
	secret string
}

// member tạo nhân sự đã đổi mật khẩu, bật 2FA, có vai trò và chi nhánh (chi nhánh đầu là chi nhánh chính).
func (e *env) member(code string, branches []uuid.UUID, roles ...string) member {
	e.t.Helper()
	ctx := context.Background()
	u, _, err := e.iam.CreateUser(ctx, iam.NewUser{Kind: "staff", Code: code, FullName: "Người " + code})
	if err != nil {
		e.t.Fatal(err)
	}
	hash, _ := iam.HashPassword(ctx, password)
	key, err := totp.Generate(totp.GenerateOpts{Issuer: "MEnglish", AccountName: code})
	if err != nil {
		e.t.Fatal(err)
	}
	e.exec("UPDATE iam.users SET password_hash = $2, must_change_password = false, totp_secret = $3, totp_enabled_at = now() WHERE id = $1",
		u.ID, hash, e.box.Seal([]byte(key.Secret()), u.ID[:]))
	for _, r := range roles {
		e.exec("INSERT INTO iam.user_roles (user_id, role) VALUES ($1, $2)", u.ID, r)
	}
	for i, b := range branches {
		e.exec("INSERT INTO iam.user_branches (user_id, branch_id, is_primary) VALUES ($1, $2, $3)", u.ID, b, i == 0)
	}
	return member{id: u.ID, code: code, secret: key.Secret()}
}

func (e *env) access(m member) *iam.Access {
	e.t.Helper()
	a, err := e.iam.LoadAccess(context.Background(), m.id)
	if err != nil {
		e.t.Fatal(err)
	}
	return a
}

// student tạo hồ sơ như CRM chốt Lead.
func (e *env) student(by member, in CreateInput) *Created {
	e.t.Helper()
	if in.Phone == "" {
		in.Phone = "0912345678"
	}
	c, err := e.svc.Create(context.Background(), nil, e.access(by), in)
	if err != nil {
		e.t.Fatal(err)
	}
	return c
}

type client struct {
	e      *env
	cookie string
	csrf   string
}

type resp struct {
	status int
	body   map[string]any
}

func (c *client) do(method, path string, body any) resp {
	c.e.t.Helper()
	var rd io.Reader
	if body != nil {
		b, _ := json.Marshal(body)
		rd = bytes.NewReader(b)
	}
	req, _ := http.NewRequest(method, c.e.srv.URL+"/api/v1"+path, rd)
	if body != nil {
		req.Header.Set("Content-Type", "application/json")
	}
	req.Header.Set("Origin", c.e.srv.URL)
	if c.cookie != "" {
		req.AddCookie(&http.Cookie{Name: iam.CookieName, Value: c.cookie})
	}
	if c.csrf != "" {
		req.Header.Set("X-CSRF-Token", c.csrf)
	}
	res, err := http.DefaultClient.Do(req)
	if err != nil {
		c.e.t.Fatal(err)
	}
	defer res.Body.Close()
	for _, ck := range res.Cookies() {
		if ck.Name == iam.CookieName {
			c.cookie = ck.Value
		}
	}
	out := resp{status: res.StatusCode, body: map[string]any{}}
	_ = json.NewDecoder(res.Body).Decode(&out.body)
	if tok, ok := out.body["csrf_token"].(string); ok {
		c.csrf = tok
	}
	return out
}

func (e *env) login(identifier, pw string) (*client, resp) {
	c := &client{e: e}
	return c, c.do("POST", "/auth/login", map[string]any{"identifier": identifier, "password": pw})
}

// as đăng nhập (kể cả bước 2FA).
func (e *env) as(m member) *client {
	e.t.Helper()
	c, r := e.login(m.code, password)
	expect(e.t, r, 200, "")
	if r.body["status"] == "totp_required" {
		code, _ := totp.GenerateCode(m.secret, time.Now())
		expect(e.t, c.do("POST", "/auth/login/totp", map[string]any{"challenge": r.body["challenge"], "code": code}), 200, "")
	}
	return c
}

func expect(t *testing.T, r resp, status int, code string) {
	t.Helper()
	if r.status != status || (code != "" && r.body["error"] != code) {
		t.Fatalf("muốn %d %s, nhận %d %v", status, code, r.status, r.body)
	}
}

func expectMessage(t *testing.T, r resp, status int, msg string) {
	t.Helper()
	if r.status != status || r.body["message"] != msg {
		t.Fatalf("muốn %d %q, nhận %d %v", status, msg, r.status, r.body)
	}
}

func codes(t *testing.T, r resp) []string {
	t.Helper()
	out := []string{}
	for _, s := range r.body["students"].([]any) {
		out = append(out, s.(map[string]any)["code"].(string))
	}
	return out
}

func sameSet(a, b []string) bool {
	if len(a) != len(b) {
		return false
	}
	seen := map[string]int{}
	for _, x := range a {
		seen[x]++
	}
	for _, x := range b {
		seen[x]--
	}
	for _, n := range seen {
		if n != 0 {
			return false
		}
	}
	return true
}

func TestPermissions(t *testing.T) {
	e := setup(t)
	a := e.branch("CN-A")
	admin := e.member("MES001", nil, iam.RoleAdmin)
	st := e.student(admin, CreateInput{FullName: "Nguyễn An", BranchID: &a})

	// Giáo viên không có student.view
	teacher := e.as(e.member("GV001", []uuid.UUID{a}, iam.RoleTeacherFulltime))
	expect(t, teacher.do("GET", "/students", nil), 403, "forbidden")
	expect(t, teacher.do("GET", "/students/"+st.ID.String(), nil), 403, "forbidden")

	// Học thuật chỉ xem: không sửa, đổi trạng thái, xóa, cấp lại mật khẩu
	lead := e.as(e.member("HT001", []uuid.UUID{a}, iam.RoleAcademicLead))
	expect(t, lead.do("GET", "/students", nil), 200, "")
	expect(t, lead.do("GET", "/students/"+st.Code, nil), 200, "")
	expect(t, lead.do("PATCH", "/students/"+st.ID.String(), map[string]any{"full_name": "X", "phone": "0900000000"}), 403, "forbidden")
	expect(t, lead.do("PUT", "/students/"+st.ID.String()+"/status", map[string]any{"status": "dropped"}), 403, "forbidden")
	expect(t, lead.do("DELETE", "/students/"+st.ID.String(), nil), 403, "forbidden")
	expect(t, lead.do("POST", "/students/"+st.ID.String()+"/reset-account", nil), 403, "forbidden")
	expect(t, lead.do("POST", "/students/"+st.ID.String()+"/guardians", map[string]any{"phone": "0987000111", "full_name": "PH"}), 403, "forbidden")

	// Xem theo phân quyền: Học thuật không có student.update / tuition.view → ẩn liên hệ, vẫn xem lớp học (attendance_student.view)
	r := lead.do("GET", "/students/"+st.ID.String()+"?view=scoped", nil)
	expect(t, r, 200, "")
	view := r.body["view"].(map[string]any)
	if r.body["phone"] != nil || view["can_view_contact"] != false || view["can_view_academic"] != true || view["can_edit"] != false {
		t.Fatalf("hồ sơ theo phân quyền của Học thuật: %v", r.body)
	}
	if r = lead.do("GET", "/students/"+st.ID.String(), nil); r.body["phone"] != "0912345678" {
		t.Fatalf("màn chi tiết vẫn hiện liên hệ: %v", r.body)
	}
	expect(t, (&client{e: e}).do("GET", "/students", nil), 401, "unauthenticated")
}

func TestCreateFromLeadWithStudentAccount(t *testing.T) {
	e := setup(t)
	a := e.branch("CN-A")
	staff := e.member("HV001", []uuid.UUID{a}, iam.RoleAcademicStaff)
	lead := uuid.New()
	ctx := context.Background()

	code, err := e.svc.NextCode(ctx, nil)
	if err != nil {
		t.Fatal(err)
	}
	c := e.student(staff, CreateInput{Code: code, LeadID: &lead, FullName: "Trần Bình", ParentName: "Trần Hoa", ParentPhone: "0987654321", BranchID: &a, EntranceScore: "Chưa test"})
	if c.Code != code || c.Login != code || c.TemporaryPassword == "" || !strings.HasPrefix(code, "HV-") || len(code) != 8 {
		t.Fatalf("hồ sơ vừa tạo: %+v", c)
	}
	// Mã đã dùng thì cấp mã mới
	c2 := e.student(staff, CreateInput{Code: code, FullName: "Lê Chi", BranchID: &a})
	if c2.Code == code {
		t.Fatalf("mã %s bị dùng hai lần", code)
	}

	// Học viên đăng nhập bằng mã với mật khẩu tạm, phải đổi mật khẩu
	cl, r := e.login(strings.ToLower(c.Code), c.TemporaryPassword)
	expect(t, r, 200, "")
	if r.body["user"].(map[string]any)["must_change_password"] != true {
		t.Fatalf("tài khoản học viên phải đổi mật khẩu lần đầu: %v", r.body)
	}
	expect(t, cl.do("GET", "/students", nil), 403, "password_change_required")

	var kind string
	var roles []string
	var branch uuid.UUID
	if err := e.pg.QueryRow(ctx, `SELECT u.kind, array_agg(r.role), max(b.branch_id::text)::uuid FROM iam.users u
		JOIN iam.user_roles r ON r.user_id = u.id JOIN iam.user_branches b ON b.user_id = u.id AND b.is_primary
		WHERE u.id = $1 GROUP BY u.kind`, c.UserID).Scan(&kind, &roles, &branch); err != nil {
		t.Fatal(err)
	}
	if kind != "student" || len(roles) != 1 || roles[0] != iam.RoleStudent || branch != a {
		t.Fatalf("tài khoản học viên: kind=%s roles=%v branch=%s", kind, roles, branch)
	}

	// Email Lead thuộc tài khoản nhân sự → báo lỗi, không tạo gì
	e.exec("UPDATE iam.users SET email = 'gv@meducation.vn' WHERE id = $1", staff.id)
	_, err = e.svc.Create(ctx, nil, e.access(staff), CreateInput{FullName: "Phạm Dũng", Phone: "0911111111", Email: "GV@meducation.vn"})
	var ie *iam.Error
	if !errorsAs(err, &ie) || ie.Message != "Email của Lead đã thuộc một tài khoản khác. Vui lòng cập nhật email riêng cho học viên." {
		t.Fatalf("email trùng nhân sự: %v", err)
	}
	// Tài khoản cổng học viên chưa gắn hồ sơ (đã khóa) → dùng lại và kích hoạt, không cấp mật khẩu mới
	u, _, err := e.iam.CreateUser(ctx, iam.NewUser{Kind: "student", Email: "hs@gmail.com", FullName: "HS"})
	if err != nil {
		t.Fatal(err)
	}
	e.exec("UPDATE iam.users SET status = 'disabled' WHERE id = $1", u.ID)
	c3 := e.student(staff, CreateInput{FullName: "Hồ Sơ", Email: "HS@gmail.com"})
	if c3.UserID != u.ID || c3.TemporaryPassword != "" || c3.Login != "hs@gmail.com" {
		t.Fatalf("dùng lại tài khoản học viên: %+v", c3)
	}
	var status string
	_ = e.pg.QueryRow(ctx, "SELECT status FROM iam.users WHERE id = $1", u.ID).Scan(&status)
	if status != "active" {
		t.Fatalf("tài khoản dùng lại phải được kích hoạt, đang %s", status)
	}
	// Đã gắn hồ sơ rồi thì không dùng lại lần nữa
	if _, err := e.svc.Create(ctx, nil, e.access(staff), CreateInput{FullName: "Khác", Phone: "0911111111", Email: "hs@gmail.com"}); err == nil {
		t.Fatal("tài khoản học viên đã gắn hồ sơ bị dùng lại")
	}

	// Tạo trong transaction của CRM: CRM rollback thì không còn hồ sơ / tài khoản
	err = pgx.BeginFunc(ctx, e.pg, func(tx pgx.Tx) error {
		if _, err := e.svc.Create(ctx, tx, e.access(staff), CreateInput{FullName: "Rollback", Phone: "0922222222"}); err != nil {
			return err
		}
		return io.EOF
	})
	var n int
	_ = e.pg.QueryRow(ctx, "SELECT count(*) FROM academic.students WHERE full_name = 'Rollback'").Scan(&n)
	if err != io.EOF || n != 0 {
		t.Fatalf("rollback: err=%v còn %d hồ sơ", err, n)
	}

	// Hồ sơ hiện trên màn chi tiết với lịch sử "tạo" và tài khoản
	r = e.as(staff).do("GET", "/students/"+c.ID.String(), nil)
	expect(t, r, 200, "")
	if r.body["lead_id"] != lead.String() || r.body["status"] != StatusWaitingStart || r.body["parent_phone"] != "0987654321" ||
		r.body["account"].(map[string]any)["login"] != code || len(r.body["history"].([]any)) != 1 {
		t.Fatalf("hồ sơ: %v", r.body)
	}
}

func errorsAs(err error, target **iam.Error) bool {
	e, ok := err.(*iam.Error)
	if ok {
		*target = e
	}
	return ok
}

func TestListFiltersAndBranchScope(t *testing.T) {
	e := setup(t)
	a, b := e.branch("CN-A"), e.branch("CN-B")
	admin := e.member("MES001", nil, iam.RoleAdmin)
	an := e.student(admin, CreateInput{FullName: "Nguyễn An", BranchID: &a, Phone: "0901000001"})
	binh := e.student(admin, CreateInput{FullName: "Trần Bình", BranchID: &a, Phone: "0901000002"})
	chi := e.student(admin, CreateInput{FullName: "Lê Chi", BranchID: &b, Phone: "0901000003"})
	none := e.student(admin, CreateInput{FullName: "Không Chi Nhánh"})
	class := e.class(a, "L-A1")
	e.exec("UPDATE academic.students SET status = 'studying', study_started_on = current_date - interval '4 years', current_class_id = $2 WHERE id = $1", an.ID, class)
	e.exec("UPDATE academic.students SET status = 'deferred', study_started_on = current_date - interval '2 years' WHERE id = $1", binh.ID)

	manager := e.as(e.member("QL001", []uuid.UUID{a}, iam.RoleManager))
	r := manager.do("GET", "/students", nil)
	expect(t, r, 200, "")
	if got := codes(t, r); !sameSet(got, []string{an.Code, binh.Code}) || r.body["total_visible"] != float64(2) {
		t.Fatalf("Quản lý chi nhánh A thấy %v (%v)", got, r.body["total_visible"])
	}
	expect(t, manager.do("GET", "/students/"+chi.ID.String(), nil), 404, "student_not_found")
	expect(t, manager.do("GET", "/students/"+none.Code, nil), 404, "student_not_found")
	expect(t, manager.do("PATCH", "/students/"+chi.ID.String(), map[string]any{"full_name": "X", "phone": "0900000000"}), 404, "student_not_found")
	expect(t, manager.do("PUT", "/students/"+chi.ID.String()+"/status", map[string]any{"status": "dropped"}), 404, "student_not_found")
	expect(t, manager.do("DELETE", "/students/"+chi.ID.String(), nil), 404, "student_not_found")
	if br := manager.do("GET", "/students/options", nil).body["branches"].([]any); len(br) != 1 {
		t.Fatalf("Quản lý chỉ chọn được chi nhánh của mình: %v", br)
	}

	ad := e.as(admin)
	if got := codes(t, ad.do("GET", "/students", nil)); len(got) != 4 {
		t.Fatalf("Admin thấy %v", got)
	}
	cases := map[string][]string{
		"?q=bình":                              {binh.Code},
		"?q=0901000003":                        {chi.Code},
		"?q=" + strings.ToLower(an.Code):       {an.Code},
		"?branch_id=" + b.String():             {chi.Code},
		"?statuses=studying&statuses=deferred": {an.Code, binh.Code},
		"?status=deferred":                     {binh.Code},
		"?tenure=3":                            {an.Code},
		"?tenure=1":                            {an.Code, binh.Code},
		"?class_id=" + class.String():          {an.Code},
		"?without_class=1":                     {binh.Code, chi.Code, none.Code},
		"?q=khongco":                           {},
	}
	for qs, want := range cases {
		r := ad.do("GET", "/students"+qs, nil)
		expect(t, r, 200, "")
		if got := codes(t, r); !sameSet(got, want) {
			t.Errorf("%s: muốn %v, nhận %v", qs, want, got)
		}
		if r.body["has_filters"] != true {
			t.Errorf("%s: has_filters phải true", qs)
		}
	}
	// Sắp xếp "Học lâu nhất": An (4 năm), Bình (2 năm), rồi học viên chưa vào học
	if got := codes(t, ad.do("GET", "/students?sort=tenure", nil)); got[0] != an.Code || got[1] != binh.Code {
		t.Fatalf("sắp xếp thâm niên: %v", got)
	}
	r = ad.do("GET", "/students?per_page=10&page=1&tenure=3", nil)
	s0 := r.body["students"].([]any)[0].(map[string]any)
	if s0["tenure_label"] != "4 năm" || s0["long_term"] != true || s0["status_label"] != "Đang học" {
		t.Fatalf("thâm niên: %v", s0)
	}

	// Phạm vi "Của tôi": học viên thuộc lớp giáo viên được xem (phân hệ Lớp học gắn qua ClassHooks)
	teacher := e.member("GV001", []uuid.UUID{a}, iam.RoleTeacherFulltime)
	e.exec("INSERT INTO iam.permission_overrides (user_id, permission, allow) VALUES ($1, 'student.view', true)", teacher.id)
	tc := e.as(teacher)
	if got := codes(t, tc.do("GET", "/students", nil)); len(got) != 0 {
		t.Fatalf("chưa có phân hệ Lớp học thì giáo viên không thấy học viên nào: %v", got)
	}
	e.svc.Classes = fakeClasses{classes: []uuid.UUID{class}}
	if got := codes(t, tc.do("GET", "/students", nil)); !sameSet(got, []string{an.Code}) {
		t.Fatalf("giáo viên thấy học viên lớp mình: %v", got)
	}
}

type fakeClasses struct {
	classes []uuid.UUID
	started bool
}

func (f fakeClasses) VisibleClassIDs(context.Context, *iam.Access) ([]uuid.UUID, error) {
	return f.classes, nil
}
func (f fakeClasses) ClassStarted(context.Context, uuid.UUID) (bool, error)    { return f.started, nil }
func (f fakeClasses) DropEnrollments(context.Context, pgx.Tx, uuid.UUID) error { return nil }

func TestUpdateValidation(t *testing.T) {
	e := setup(t)
	a := e.branch("CN-A")
	staff := e.member("HV001", []uuid.UUID{a}, iam.RoleAcademicStaff)
	st := e.student(staff, CreateInput{FullName: "Nguyễn An", BranchID: &a})
	c := e.as(staff)
	path := "/students/" + st.ID.String()
	valid := map[string]any{"full_name": "Nguyễn Văn An", "phone": "0912 345 678"}
	with := func(k string, v any) map[string]any {
		out := map[string]any{}
		for key, val := range valid {
			out[key] = val
		}
		out[k] = v
		return out
	}
	tomorrow := dateOf(time.Now()).AddDate(0, 0, 1).Format(time.DateOnly)
	for body, msg := range map[*map[string]any]string{
		ptr(with("full_name", " ")):                   "Vui lòng nhập Họ và tên.",
		ptr(with("phone", "")):                        "Vui lòng nhập Số điện thoại.",
		ptr(with("phone", strings.Repeat("1", 21))):   "Trường Số điện thoại không được vượt quá 20 ký tự.",
		ptr(with("parent_phone", "abc")):              "Số điện thoại phụ huynh không hợp lệ.",
		ptr(with("email", "khong-phai-email")):        "Trường Địa chỉ Email phải là một địa chỉ email hợp lệ.",
		ptr(with("dob", tomorrow)):                    "Trường ngày sinh phải là một ngày trước hôm nay.",
		ptr(with("dob", "1930-05-01")):                "Trường ngày sinh phải là một ngày sau ngày 01/01/1940.",
		ptr(with("dob", "31/12/2015")):                "Trường ngày sinh không phải là ngày hợp lệ.",
		ptr(with("study_started_on", tomorrow)):       "Ngày bắt đầu học không được sau hôm nay.",
		ptr(with("status", "trial")):                  "Giá trị đã chọn trong trường trạng thái không hợp lệ.",
		ptr(with("notes", strings.Repeat("ă", 501))):  "Trường Ghi chú không được vượt quá 500 ký tự.",
		ptr(with("target", strings.Repeat("x", 101))): "Trường Mục tiêu học tập không được vượt quá 100 ký tự.",
	} {
		r := c.do("PATCH", path, *body)
		expect(t, r, 422, "invalid")
		if r.body["message"] != msg {
			t.Errorf("%v: muốn %q, nhận %q", *body, msg, r.body["message"])
		}
	}

	r := c.do("PATCH", path, map[string]any{
		"full_name": "Nguyễn Văn An", "phone": "0912345678", "email": "An@Gmail.com", "dob": "2015-03-02",
		"gender": "Nam", "parent_name": "Nguyễn Hoa", "parent_phone": "0987 654 321", "school": "THCS Đoàn Thị Điểm",
		"study_started_on": "2022-01-10", "notes": "Dị ứng hải sản", "status": "studying", "version": 1,
	})
	expectMessage(t, r, 200, "Cập nhật hồ sơ học viên Nguyễn Văn An thành công!")
	p := r.body["student"].(map[string]any)
	if p["email"] != "an@gmail.com" || p["dob"] != "2015-03-02" || p["status"] != StatusStudying || p["version"] != float64(3) ||
		p["tenure_label"] == nil || p["school"] != "THCS Đoàn Thị Điểm" {
		t.Fatalf("sau khi sửa: %v", p)
	}
	hist := p["history"].([]any)
	if len(hist) != 3 || hist[0].(map[string]any)["kind"] != "status_changed" || !strings.Contains(hist[1].(map[string]any)["message"].(string), "họ tên") {
		t.Fatalf("lịch sử: %v", hist)
	}
	// Mở form từ bản cũ → báo người khác vừa sửa
	expect(t, c.do("PATCH", path, with("version", 1)), 409, "stale")

	// Người không có change_status: trạng thái trong form bị bỏ qua
	e.exec("INSERT INTO iam.permission_overrides (user_id, permission, allow) VALUES ($1, 'student.change_status', false)", staff.id)
	e.flushAccess()
	r = c.do("PATCH", path, with("status", "dropped"))
	expect(t, r, 200, "")
	if r.body["student"].(map[string]any)["status"] != StatusStudying {
		t.Fatal("trạng thái đổi được dù không có student.change_status")
	}
}

// flushAccess bỏ cache quyền (sau khi sửa phân quyền bằng SQL).
func (e *env) flushAccess() {
	e.t.Helper()
	if err := iam.InvalidateAllAccess(context.Background(), e.rdb); err != nil {
		e.t.Fatal(err)
	}
}

func TestStatusDeferralAndDelete(t *testing.T) {
	e := setup(t)
	a := e.branch("CN-A")
	manager := e.member("QL001", []uuid.UUID{a}, iam.RoleManager)
	st := e.student(manager, CreateInput{FullName: "Nguyễn An", BranchID: &a, CurrentClassID: ptr(e.class(a, "L-A1"))})
	c := e.as(manager)
	path := "/students/" + st.ID.String()

	expect(t, c.do("PUT", path+"/status", map[string]any{"status": ""}), 422, "invalid")
	expectMessage(t, c.do("POST", path+"/end-deferral", nil), 422, "Học viên không ở trạng thái Bảo lưu.")
	expectMessage(t, c.do("PUT", path+"/status", map[string]any{"status": "deferred"}), 200,
		`Đã chuyển trạng thái học viên Nguyễn An từ "Chờ khai giảng" sang "Bảo lưu".`)
	expectMessage(t, c.do("POST", path+"/end-deferral", nil), 200, `Đã kết thúc bảo lưu cho học viên Nguyễn An: chuyển sang "Chờ khai giảng".`)

	// Lớp hiện tại đã khai giảng → về Đang học
	e.svc.Classes = fakeClasses{started: true}
	expect(t, c.do("PUT", path+"/status", map[string]any{"status": "deferred"}), 200, "")
	expectMessage(t, c.do("POST", path+"/end-deferral", nil), 200, `Đã kết thúc bảo lưu cho học viên Nguyễn An: chuyển sang "Đang học".`)

	expectMessage(t, c.do("PUT", path+"/status", map[string]any{"status": "dropped"}), 200,
		`Đã chuyển trạng thái học viên Nguyễn An từ "Đang học" sang "Thôi học". Học viên đã được đưa ra khỏi danh sách lớp đang học; lịch sử học tập vẫn được giữ.`)
	r := c.do("GET", path, nil)
	if r.body["current_class_id"] != nil || r.body["status"] != StatusDropped {
		t.Fatalf("thôi học phải rời lớp đang học: %v", r.body)
	}

	expectMessage(t, c.do("DELETE", path, nil), 200, "Đã xóa hồ sơ học viên Nguyễn An!")
	expect(t, c.do("GET", path, nil), 404, "student_not_found")
	if got := codes(t, c.do("GET", "/students", nil)); len(got) != 0 {
		t.Fatalf("hồ sơ đã xóa vẫn trong danh sách: %v", got)
	}
	var kinds []string
	if err := e.pg.QueryRow(context.Background(), "SELECT array_agg(kind ORDER BY created_at, id) FROM academic.student_events WHERE student_id = $1", st.ID).Scan(&kinds); err != nil {
		t.Fatal(err)
	}
	if strings.Join(kinds, ",") != "created,status_changed,deferral_ended,status_changed,deferral_ended,status_changed,deleted" {
		t.Fatalf("lịch sử: %v", kinds)
	}
}

func TestGuardiansAndAccountReset(t *testing.T) {
	e := setup(t)
	a := e.branch("CN-A")
	staff := e.member("HV001", []uuid.UUID{a}, iam.RoleAcademicStaff)
	an := e.student(staff, CreateInput{FullName: "Nguyễn An", BranchID: &a})
	binh := e.student(staff, CreateInput{FullName: "Nguyễn Bình", BranchID: &a})
	c := e.as(staff)

	expectMessage(t, c.do("POST", "/students/"+an.ID.String()+"/guardians", map[string]any{"phone": "12", "full_name": "Hoa"}), 422, "Số điện thoại phụ huynh không hợp lệ.")
	expectMessage(t, c.do("POST", "/students/"+an.ID.String()+"/guardians", map[string]any{"phone": "0987654321", "full_name": ""}), 422, "Vui lòng nhập họ tên phụ huynh.")

	r := c.do("POST", "/students/"+an.ID.String()+"/guardians", map[string]any{"phone": "+84 987 654 321", "full_name": "Nguyễn Hoa", "relationship": "Mẹ"})
	expect(t, r, 201, "")
	pw, _ := r.body["temporary_password"].(string)
	if r.body["created"] != true || pw == "" || r.body["login"] != "0987654321" {
		t.Fatalf("gắn phụ huynh mới: %v", r.body)
	}
	// Phụ huynh đăng nhập bằng SĐT
	_, lr := e.login("0987.654.321", pw)
	expect(t, lr, 200, "")
	// Con thứ hai: dùng lại tài khoản phụ huynh, không cấp mật khẩu mới
	r = c.do("POST", "/students/"+binh.ID.String()+"/guardians", map[string]any{"phone": "0987654321", "full_name": "Tên khác"})
	expect(t, r, 201, "")
	g := r.body["guardian"].(map[string]any)
	if r.body["created"] != false || r.body["temporary_password"] != nil || g["full_name"] != "Nguyễn Hoa" || g["children"] != float64(2) {
		t.Fatalf("phụ huynh nhiều con: %v", r.body)
	}
	expectMessage(t, c.do("POST", "/students/"+binh.ID.String()+"/guardians", map[string]any{"phone": "0987654321", "full_name": "Hoa"}), 422, "Phụ huynh này đã được gắn với học viên.")

	p := c.do("GET", "/students/"+an.ID.String(), nil)
	gs := p.body["guardians"].([]any)
	if len(gs) != 1 || gs[0].(map[string]any)["relationship"] != "Mẹ" {
		t.Fatalf("phụ huynh trên hồ sơ: %v", gs)
	}
	gid := gs[0].(map[string]any)["id"].(string)
	expect(t, c.do("DELETE", "/students/"+an.ID.String()+"/guardians/"+gid, nil), 204, "")
	expect(t, c.do("DELETE", "/students/"+an.ID.String()+"/guardians/"+gid, nil), 404, "guardian_not_found")

	// Cấp mật khẩu tạm cho tài khoản cổng học viên
	r = c.do("POST", "/students/"+an.ID.String()+"/reset-account", nil)
	expect(t, r, 200, "")
	if r.body["login"] != an.Code || r.body["temporary_password"] == an.TemporaryPassword {
		t.Fatalf("cấp lại mật khẩu: %v", r.body)
	}
	_, lr = e.login(an.Code, r.body["temporary_password"].(string))
	expect(t, lr, 200, "")
	_, lr = e.login(an.Code, an.TemporaryPassword)
	expect(t, lr, 401, "invalid_credentials")
	// Tài khoản có thêm vai trò nhân sự → không cấp ở đây
	e.exec("INSERT INTO iam.user_roles (user_id, role) VALUES ($1, 'assistant')", binh.UserID)
	expectMessage(t, c.do("POST", "/students/"+binh.ID.String()+"/reset-account", nil), 422,
		"Học viên chưa có tài khoản cổng học viên riêng, liên hệ Admin để cấp tài khoản.")
}

// Học viên chưa gán chi nhánh: phạm vi Chi nhánh xét theo chi nhánh của lớp đang học (Student::scopeVisibleTo).
func TestBranchScopeFollowsClassBranch(t *testing.T) {
	e := setup(t)
	a, b := e.branch("CN-A"), e.branch("CN-B")
	admin := e.member("MES001", nil, iam.RoleAdmin)
	st := e.student(admin, CreateInput{FullName: "Không Chi Nhánh", CurrentClassID: ptr(e.class(a, "L-A1"))})
	ma := e.as(e.member("QL001", []uuid.UUID{a}, iam.RoleManager))
	mb := e.as(e.member("QL002", []uuid.UUID{b}, iam.RoleManager))
	if got := codes(t, ma.do("GET", "/students", nil)); !sameSet(got, []string{st.Code}) {
		t.Fatalf("Quản lý chi nhánh A phải thấy học viên của lớp chi nhánh A: %v", got)
	}
	expect(t, ma.do("GET", "/students/"+st.ID.String(), nil), 200, "")
	if got := codes(t, mb.do("GET", "/students", nil)); len(got) != 0 {
		t.Fatalf("Quản lý chi nhánh B không thấy: %v", got)
	}
	expect(t, mb.do("GET", "/students/"+st.ID.String(), nil), 404, "student_not_found")
}
