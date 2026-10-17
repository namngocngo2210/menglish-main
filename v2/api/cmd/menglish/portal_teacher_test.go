package main

import (
	"bytes"
	"context"
	"testing"
	"time"

	"github.com/go-chi/chi/v5"
	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/catalogs"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/classes"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/files"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam/iamtest"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/filestore"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/portal"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/students"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/teacher"
)

// Test tích hợp Cổng Phụ huynh & Học sinh ↔ Cổng Giáo viên qua HTTP với Postgres / Redis thật (TEST_DATABASE_URL,
// TEST_REDIS_URL): GV giao bài → học sinh nộp → GV chấm → học sinh thấy điểm; bản ghi âm → GV chấm → học sinh nhận
// thông báo.

var (
	pngBytes = append([]byte("\x89PNG\r\n\x1a\n"), bytes.Repeat([]byte{2}, 100)...)
	mp3Bytes = append([]byte("ID3\x03\x00\x00\x00\x00\x00\x00"), bytes.Repeat([]byte{0}, 100)...)
	vnZone   = time.FixedZone("ICT", 7*3600)
)

type ptEnv struct {
	*iamtest.Env
	t *testing.T
}

func ptSetup(t *testing.T) *ptEnv {
	t.Helper()
	e := iamtest.Setup(t, "academic.students", "academic.classes", "academic.pronunciation_records", "core.files",
		"core.notifications", "core.audit_log")
	store, err := filestore.NewLocal(t.TempDir(), files.APIBase+files.BlobPrefix, bytes.Repeat([]byte{7}, 32))
	if err != nil {
		t.Fatal(err)
	}
	fileSvc := files.NewService(e.Pool, store, e.Log, 0)
	cls := classes.NewService(e.Pool, catalogs.NewService(e.Pool, nil, e.Log), e.Log)
	teacherSvc := teacher.NewService(e.Pool, e.Log, teacher.Deps{Classes: cls.TeacherPortal(), SLA: fakeSLA{}, Files: fileSvc})
	portalSvc := portal.NewService(e.Pool, fileSvc, students.NewService(e.Pool, e.IAM, e.Log), e.Log)
	wirePortalTeacher(portalSvc, teacherSvc)
	fh := files.NewHandler(fileSvc, e.Log)
	e.Serve(func(r chi.Router) {
		portal.NewHandler(portalSvc, e.Log).Routes(r)
		teacher.NewHandler(teacherSvc, e.Log).Routes(r)
		fh.Routes(r)
		fh.PublicRoutes(r)
	})
	return &ptEnv{Env: e, t: t}
}

type fakeSLA struct{}

func (fakeSLA) SlaRule(_ context.Context, key string) (catalogs.SlaRule, error) {
	return catalogs.SlaRule{Key: key, Value: 24}, nil
}

func (e *ptEnv) scalar(dst any, sql string, args ...any) {
	e.t.Helper()
	if err := e.Pool.QueryRow(context.Background(), sql, args...).Scan(dst); err != nil {
		e.t.Fatal(err)
	}
}

func (e *ptEnv) class(code string, branch, teacherID uuid.UUID) uuid.UUID {
	var id uuid.UUID
	e.scalar(&id, `INSERT INTO academic.classes (code, name, branch_id, teacher_id, status, room) VALUES ($1, $2, $3, $4, 'active', 'P101')
		RETURNING id`, code, "Lớp "+code, branch, teacherID)
	return id
}

func (e *ptEnv) session(class, branch uuid.UUID, date time.Time) uuid.UUID {
	var id uuid.UUID
	e.scalar(&id, `INSERT INTO academic.class_sessions (class_id, branch_id, date, start_time, end_time, type, shift_name)
		VALUES ($1, $2, $3, '08:00', '09:30', 'regular', 'Ca 1') RETURNING id`, class, branch, date.Format(time.DateOnly))
	return id
}

// student: hồ sơ học viên đang học lớp, có tài khoản cổng (đăng nhập bằng mã) đã đổi mật khẩu.
func (e *ptEnv) student(code, name string, branch, class uuid.UUID) (uuid.UUID, *iamtest.Client) {
	e.t.Helper()
	ctx := context.Background()
	u, _, err := e.IAM.CreateUser(ctx, iam.NewUser{Kind: "student", Code: code, FullName: name})
	if err != nil {
		e.t.Fatal(err)
	}
	hash, _ := iam.HashPassword(ctx, iamtest.Password)
	if _, err := e.Pool.Exec(ctx, "UPDATE iam.users SET password_hash = $2, must_change_password = false WHERE id = $1", u.ID, hash); err != nil {
		e.t.Fatal(err)
	}
	if err := e.IAM.SetRolesUnchecked(ctx, u.ID, []string{iam.RoleStudent}); err != nil {
		e.t.Fatal(err)
	}
	var id uuid.UUID
	e.scalar(&id, `INSERT INTO academic.students (code, full_name, phone, branch_id, current_class_id, user_id, status)
		VALUES ($1, $2, '0912345678', $3, $4, $5, 'studying') RETURNING id`, code, name, branch, class, u.ID)
	return id, e.Login(iamtest.User{ID: u.ID, Code: code})
}

func ptMsg(t *testing.T, r iamtest.Resp, status int, want string) {
	t.Helper()
	if r.Status != status || r.Body["message"] != want {
		t.Fatalf("muốn %d %q, nhận %d %s", status, want, r.Status, r.Raw)
	}
}

func card(t *testing.T, r iamtest.Resp, key string) map[string]any {
	t.Helper()
	for _, c := range iamtest.List(t, r, "cards") {
		if c["key"] == key {
			sub, _ := c["submission"].(map[string]any)
			return sub
		}
	}
	t.Fatalf("thiếu hạng mục %q: %s", key, r.Raw)
	return nil
}

func TestPortalTeacherHomeworkFlow(t *testing.T) {
	e := ptSetup(t)
	a := e.Branch("CN-A")
	gv, gvC := e.Member("GV001", "Cô Hạnh", []uuid.UUID{a}, iam.RoleTeacherFulltime)
	gv2, gv2C := e.Member("GV002", "Thầy Nam", []uuid.UUID{a}, iam.RoleTeacherFulltime)
	c1 := e.class("L1", a, gv.ID)
	now := time.Now().In(vnZone)
	today := time.Date(now.Year(), now.Month(), now.Day(), 0, 0, 0, 0, time.UTC)
	s0 := e.session(c1, a, today)
	sYest := e.session(c1, a, today.AddDate(0, 0, -1))
	st, hsC := e.student("HS001", "Bé An", a, c1)
	_, otherC := e.student("HS002", "Bé Bình", a, e.class("L2", a, gv2.ID))

	// Chưa có bài GV giao: hạng mục trống, tiến độ 0/0
	r := hsC.Do("GET", "/portal/homework", nil)
	iamtest.Expect(t, r, 200, "")
	if r.Body["ready"] != true || r.Body["latest_homework"] != nil || r.Body["completed_count"] != 0.0 {
		t.Fatalf("học tập ban đầu: %s", r.Raw)
	}

	// GV giao bài (có file nghe) → học sinh thấy bài mới nhất, bài nghe mẫu, tiến độ bài tập
	base := "/teacher/classes/" + c1.String() + "/homework"
	up := gvC.Upload(base+"/audio", nil, "bai-nghe.mp3", mp3Bytes)
	iamtest.Expect(t, up, 201, "")
	audioID := up.Body["id"].(string)
	due := today.AddDate(0, 0, 1).Format(time.DateOnly) + "T20:00"
	ptMsg(t, gvC.Do("POST", base, map[string]any{"session_id": s0, "due_at": due, "categories": []string{"workbook"},
		"items": map[string]string{"workbook": "Trang 12-13"}, "audio_file_id": audioID}), 201, "Đã giao bài tập về nhà cho lớp!")
	r = hsC.Do("GET", "/portal/homework", nil)
	latest, _ := r.Body["latest_homework"].(map[string]any)
	if latest == nil || latest["class_name"] != "Lớp L1" || latest["due_date"] == nil || r.Body["homework_urgent"] != true {
		t.Fatalf("bài GV giao: %s", r.Raw)
	}
	if h := hsC.Do("GET", "/portal/home", nil); h.Body["progress"].(map[string]any)["homework_total"] != 1.0 {
		t.Fatalf("tiến độ bài tập: %s", h.Raw)
	}
	practice := iamtest.List(t, hsC.Do("GET", "/portal/pronunciation", nil), "practice_items")
	if len(practice) != 1 || practice[0]["class_name"] != "Lớp L1" || practice[0]["audio_url"] != "/api/v1/files/"+audioID {
		t.Fatalf("bài nghe mẫu: %v", practice)
	}
	// File nghe: học sinh lớp mở được, học sinh lớp khác thì không
	if g := hsC.Get("/api/v1/files/" + audioID); g.Status != 302 {
		t.Fatalf("học sinh mở file nghe: %d %s", g.Status, g.Raw)
	}
	if g := otherC.Get("/api/v1/files/" + audioID); g.Status != 403 {
		t.Fatalf("học sinh lớp khác mở file nghe: %d", g.Status)
	}

	// Học sinh nộp bài Workbook kèm ảnh
	sid := st.String()
	r = hsC.Upload("/portal/homework", map[string]string{"student_id": sid, "homework_type": "workbook", "notes": "Con làm xong trang 12"},
		"bai-lam.png", pngBytes)
	ptMsg(t, r, 200, "Đã nộp bài tập thành công! Giáo viên sẽ nhận được thông báo để kiểm tra và chấm điểm.")
	subID := r.Body["id"].(string)
	iamtest.Expect(t, otherC.Upload("/portal/homework", map[string]string{"student_id": sid, "homework_type": "video"}, "", nil), 403, "forbidden")
	r = hsC.Do("GET", "/portal/homework", nil)
	sub := card(t, r, "workbook")
	if sub == nil || sub["status"] != "submitted" || sub["notes"] != "Con làm xong trang 12" || sub["attachment_name"] != "bai-lam.png" ||
		r.Body["completed_count"] != 1.0 {
		t.Fatalf("bài đã nộp: %s", r.Raw)
	}
	attachment := sub["attachment_url"].(string)
	if g := hsC.Get(attachment); g.Status != 302 {
		t.Fatalf("học sinh mở bài nộp: %d %s", g.Status, g.Raw)
	}
	if g := otherC.Get(attachment); g.Status != 403 {
		t.Fatalf("học sinh khác mở bài nộp: %d", g.Status)
	}
	if sh := hsC.Do("GET", "/portal/app-shell", nil); sh.Body["submitted_homeworks"] != 1.0 || sh.Body["pending_homeworks"] != 5.0 {
		t.Fatalf("app shell: %s", sh.Raw)
	}

	// GV thấy bài ở màn chấm bài (tab Workbook), mở được file; GV không phụ trách lớp thì không
	r = gvC.Do("GET", "/teacher/submissions?type=workbook", nil)
	iamtest.Expect(t, r, 200, "")
	list := iamtest.List(t, r, "submissions")
	if len(list) != 1 || list[0]["id"] != subID || list[0]["student_name"] != "Bé An" || list[0]["homework_label"] != "Làm Workbook bài tập" ||
		list[0]["attachment_url"] != attachment || r.Body["pronunciation_pending"] != false {
		t.Fatalf("màn chấm bài: %s", r.Raw)
	}
	if g := gvC.Get(attachment); g.Status != 302 {
		t.Fatalf("GV mở bài nộp: %d", g.Status)
	}
	iamtest.Expect(t, hsC.Do("GET", "/teacher/submissions", nil), 403, "forbidden")
	mark := "/teacher/submissions/" + subID + "/mark"
	iamtest.Expect(t, gv2C.Do("POST", mark, map[string]any{"score": "9"}), 404, "not_found")
	ptMsg(t, gvC.Do("POST", mark, map[string]any{"score": "9/10", "feedback": "Làm tốt lắm"}), 200, "Đã chấm điểm và gửi phản hồi bài nộp thành công!")

	// Học sinh thấy điểm và nhận xét
	sub = card(t, hsC.Do("GET", "/portal/homework", nil), "workbook")
	if sub["status"] != "reviewed" || sub["score"] != "9/10" || sub["feedback"] != "Làm tốt lắm" {
		t.Fatalf("học sinh xem điểm: %v", sub)
	}
	// Như Laravel: bài đã chấm vẫn sửa ghi chú / hủy nộp được; học sinh khác không sửa được
	iamtest.Expect(t, otherC.Upload("/portal/homework/"+subID, map[string]string{"notes": "x"}, "", nil), 403, "forbidden")
	ptMsg(t, hsC.Upload("/portal/homework/"+subID, map[string]string{"notes": "Con bổ sung trang 13"}, "", nil), 200, "Đã cập nhật bài nộp thành công!")
	if sub = card(t, hsC.Do("GET", "/portal/homework", nil), "workbook"); sub["notes"] != "Con bổ sung trang 13" || sub["score"] != "9/10" {
		t.Fatalf("sửa ghi chú: %v", sub)
	}

	// Nhận xét buổi học (lưu chính thức) và điểm mini test hiện ở màn Học tập của học sinh
	ptMsg(t, gvC.Do("POST", "/teacher/classes/"+c1.String()+"/remarks", map[string]any{"session_id": sYest, "action": "final",
		"remarks": map[string]any{sid: map[string]string{"monsters_group": "Rồng", "monsters_bonus": "3", "grammar": "Thì hiện tại",
			"comment": "Con phát biểu tốt"}}}), 200, "Đã lưu nhận xét buổi học!")
	ptMsg(t, gvC.Do("POST", "/teacher/classes/"+c1.String()+"/scores", map[string]any{"name": "Mini Test", "student_id": st, "max_score": "10",
		"skills": map[string]string{"listening": "8", "speaking": "8", "reading": "9", "writing": "7"}}), 200, "Đã lưu điểm Mini Test cho Bé An.")
	r = hsC.Do("GET", "/portal/homework", nil)
	remarks := iamtest.List(t, r, "remarks")
	tests := iamtest.List(t, r, "mini_tests")
	if len(remarks) != 1 || remarks[0]["monsters"] != "Nhóm Rồng · Thưởng 3" || remarks[0]["grammar"] != "Thì hiện tại" ||
		remarks[0]["comment"] != "Con phát biểu tốt" || remarks[0]["attitude"] != nil {
		t.Fatalf("nhận xét buổi học: %s", r.Raw)
	}
	if len(tests) != 1 || tests[0]["name"] != "Mini Test" || tests[0]["score"] != 8.0 || tests[0]["max_score"] != 10.0 {
		t.Fatalf("điểm mini test: %s", r.Raw)
	}

	// Hủy nộp → hạng mục trống lại, file bài nộp bị xóa
	ptMsg(t, hsC.Do("DELETE", "/portal/homework/"+subID, nil), 200, "Đã xóa bài nộp thành công! Bạn có thể nộp lại bài mới.")
	iamtest.Expect(t, hsC.Do("DELETE", "/portal/homework/"+subID, nil), 404, "not_found")
	if sub = card(t, hsC.Do("GET", "/portal/homework", nil), "workbook"); sub != nil {
		t.Fatalf("bài đã hủy: %v", sub)
	}
}

func TestPortalTeacherPronunciationFlow(t *testing.T) {
	e := ptSetup(t)
	a := e.Branch("CN-A")
	gv, gvC := e.Member("GV001", "Cô Hạnh", []uuid.UUID{a}, iam.RoleTeacherFulltime)
	_, gv2C := e.Member("GV002", "Thầy Nam", []uuid.UUID{a}, iam.RoleTeacherFulltime)
	c1 := e.class("L1", a, gv.ID)
	st, hsC := e.student("HS001", "Bé An", a, c1)

	r := hsC.Upload("/portal/pronunciation", map[string]string{"student_id": st.String(), "unit_title": "Unit 1: Hello", "duration": "00:12"},
		"rec.mp3", mp3Bytes)
	ptMsg(t, r, 200, "Đã nộp bài ghi âm! Bài đang chờ giáo viên chấm.")
	rec := r.Body["record"].(map[string]any)
	id := rec["id"].(string)

	// Tab Phát âm của màn chấm bài (Cổng giáo viên) đọc bản ghi âm của cổng học viên
	r = gvC.Do("GET", "/teacher/submissions?type=pronunciation", nil)
	list := iamtest.List(t, r, "submissions")
	if r.Body["active_tab"] != "pronunciation" || r.Body["pronunciation_pending"] != false || len(list) != 1 || list[0]["id"] != id ||
		list[0]["student_name"] != "Bé An" || list[0]["unit_title"] != "Unit 1: Hello" || list[0]["audio_url"] != rec["audio_url"] ||
		list[0]["status"] != "pending_review" {
		t.Fatalf("tab phát âm: %s", r.Raw)
	}
	if g := gvC.Get(rec["audio_url"].(string)); g.Status != 302 {
		t.Fatalf("GV nghe bản ghi âm: %d", g.Status)
	}
	mark := "/teacher/submissions/" + id + "/mark"
	ptMsg(t, gvC.Do("POST", mark, map[string]any{"feedback": "Tốt"}), 422, "Vui lòng nhập điểm bài phát âm.")
	iamtest.Expect(t, gv2C.Do("POST", mark, map[string]any{"score": "90"}), 404, "not_found")
	ptMsg(t, gvC.Do("POST", mark, map[string]any{"score": "90", "feedback": "Phát âm rõ"}), 200, "Đã chấm điểm và gửi phản hồi bài nộp thành công!")
	if l := iamtest.List(t, gvC.Do("GET", "/teacher/submissions?type=pronunciation", nil), "submissions"); l[0]["status"] != "reviewed" || l[0]["score"] != "90" {
		t.Fatalf("sau chấm (GV): %v", l)
	}

	// Học sinh thấy điểm ở lịch sử luyện tập và nhận thông báo ở hộp thư cổng
	h := iamtest.List(t, hsC.Do("GET", "/portal/pronunciation", nil), "history")
	if h[0]["status"] != "reviewed" || h[0]["score"] != "90" || h[0]["feedback"] != "Phát âm rõ" {
		t.Fatalf("sau chấm (học sinh): %v", h)
	}
	n := iamtest.List(t, hsC.Do("GET", "/portal/notifications", nil), "notifications")
	if len(n) != 1 || n[0]["type"] != "pronunciation_reviewed" || n[0]["title"] != "Bài luyện phát âm đã được chấm" {
		t.Fatalf("thông báo: %v", n)
	}
	if s := hsC.Do("GET", "/portal/summary", nil); s.Body["unread_count"] != 1.0 {
		t.Fatalf("chưa đọc: %s", s.Raw)
	}
}
