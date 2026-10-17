package teacher

import (
	"bytes"
	"context"
	"testing"
	"time"

	"github.com/go-chi/chi/v5"
	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/catalogs"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/classes"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/files"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam/iamtest"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/filestore"
)

// Test qua HTTP với Postgres / Redis thật (TEST_DATABASE_URL, TEST_REDIS_URL). "Hôm nay": Thứ Hai 19/10/2026 10:00.
var fixedNow = time.Date(2026, 10, 19, 10, 0, 0, 0, vnZone)

var (
	pngBytes = append([]byte("\x89PNG\r\n\x1a\n"), bytes.Repeat([]byte{2}, 100)...)
	mp3Bytes = append([]byte("ID3\x03\x00\x00\x00\x00\x00\x00"), bytes.Repeat([]byte{0}, 100)...)
)

type fakeSLA struct{}

func (fakeSLA) SlaRule(_ context.Context, key string) (catalogs.SlaRule, error) {
	return catalogs.SlaRule{Key: key, Value: 24}, nil
}

// fakeWork: phân hệ chấm công giả — ghi lại các buổi được check-in.
type fakeWork struct {
	got []CheckinSession
}

func (*fakeWork) Available() bool { return true }
func (w *fakeWork) Checkins(_ context.Context, _ uuid.UUID, _ []uuid.UUID) (map[uuid.UUID]Checkin, error) {
	out := map[uuid.UUID]Checkin{}
	for _, s := range w.got {
		out[s.SessionID] = Checkin{Time: "10:00", Status: "pending_review"}
	}
	return out, nil
}
func (w *fakeWork) CheckIn(_ context.Context, _ uuid.UUID, _ time.Time, ss []CheckinSession) (CheckinResult, error) {
	w.got = append(w.got, ss...)
	return CheckinResult{Count: len(ss)}, nil
}
func (*fakeWork) Month(context.Context, uuid.UUID, time.Time, time.Time) (*WorkMonth, error) {
	return &WorkMonth{Hours: 3, Sessions: 2, ValidHours: 1.5, ValidSessions: 1,
		Rows: []TimesheetRow{{ID: uuid.New(), Date: "19/10/2026", ClassName: "Lớp Một", Type: "regular", Hours: 1.5, Status: "valid"}}}, nil
}

type fakeSupport struct{ got []MiniTestRef }

func (f *fakeSupport) SyncMiniTest(_ context.Context, _ pgx.Tx, m MiniTestRef) error {
	f.got = append(f.got, m)
	return nil
}

type env struct {
	*iamtest.Env
	t       *testing.T
	svc     *Service
	files   *files.Service
	support *fakeSupport
	a, b    uuid.UUID // chi nhánh
	// Lớp Một (CN A, GV gv) và Lớp B (CN B, GV gv2)
	c1, c2 uuid.UUID
	// Buổi của Lớp Một: hôm nay 18:00, hôm qua (CN) 18:00, thứ Sáu tuần trước, thứ Tư tới; buổi hôm nay của Lớp B
	s0, sYest, sPast, sNext, s2 uuid.UUID
	st1, st2, st3               uuid.UUID
	gv, gv2                     iamtest.User
}

func setup(t *testing.T) *env {
	t.Helper()
	base := iamtest.Setup(t, "academic.students", "academic.classes", "core.files")
	ctx := context.Background()
	fstore, err := filestore.NewLocal(t.TempDir(), files.APIBase+files.BlobPrefix, bytes.Repeat([]byte{7}, 32))
	if err != nil {
		t.Fatal(err)
	}
	fileSvc := files.NewService(base.Pool, fstore, base.Log, 0)
	cls := classes.NewService(base.Pool, catalogs.NewService(base.Pool, nil, base.Log), base.Log)
	sup := &fakeSupport{}
	svc := NewService(base.Pool, base.Log, Deps{Classes: cls.TeacherPortal(), SLA: fakeSLA{}, Files: fileSvc, SupportList: sup})
	svc.now = func() time.Time { return fixedNow }
	e := &env{Env: base, t: t, svc: svc, files: fileSvc, support: sup}
	base.Serve(func(r chi.Router) {
		NewHandler(svc, base.Log).Routes(r)
		fh := files.NewHandler(fileSvc, base.Log)
		fh.Routes(r)
		fh.PublicRoutes(r)
	})
	e.a, e.b = base.Branch("CN-A"), base.Branch("CN-B")
	e.gv = base.User("GV001", "Cô Hạnh", []uuid.UUID{e.a}, iam.RoleTeacherFulltime)
	e.gv2 = base.User("GV002", "Thầy Nam", []uuid.UUID{e.b}, iam.RoleTeacherFulltime)
	e.c1 = e.class("L1", "Lớp Một", e.a, e.gv.ID)
	e.c2 = e.class("LB", "Lớp B", e.b, e.gv2.ID)
	e.s0 = e.session(e.c1, e.a, "2026-10-19", "regular")
	e.sYest = e.session(e.c1, e.a, "2026-10-18", "regular")
	e.sPast = e.session(e.c1, e.a, "2026-10-16", "regular")
	e.sNext = e.session(e.c1, e.a, "2026-10-21", "regular")
	e.s2 = e.session(e.c2, e.b, "2026-10-19", "regular")
	e.st1 = e.student("HV-00001", "Bé An", e.a, e.c1)
	e.st2 = e.student("HV-00002", "Bé Bình", e.a, e.c1)
	e.st3 = e.student("HV-00003", "Bé Cúc", e.b, e.c2)
	_ = ctx
	return e
}

func (e *env) exec(sql string, args ...any) {
	e.t.Helper()
	if _, err := e.Pool.Exec(context.Background(), sql, args...); err != nil {
		e.t.Fatal(err)
	}
}

func (e *env) scalar(dst any, sql string, args ...any) {
	e.t.Helper()
	if err := e.Pool.QueryRow(context.Background(), sql, args...).Scan(dst); err != nil {
		e.t.Fatal(err)
	}
}

func (e *env) class(code, name string, branch, teacher uuid.UUID) uuid.UUID {
	var id uuid.UUID
	e.scalar(&id, `INSERT INTO academic.classes (code, name, branch_id, teacher_id, status, room) VALUES ($1, $2, $3, $4, 'active', 'P101')
		RETURNING id`, code, name, branch, teacher)
	return id
}

func (e *env) session(class, branch uuid.UUID, date, typ string) uuid.UUID {
	var id uuid.UUID
	e.scalar(&id, `INSERT INTO academic.class_sessions (class_id, branch_id, date, start_time, end_time, type, shift_name)
		VALUES ($1, $2, $3, '18:00', '19:30', $4, 'Ca 1') RETURNING id`, class, branch, date, typ)
	return id
}

func (e *env) student(code, name string, branch, class uuid.UUID) uuid.UUID {
	var id uuid.UUID
	e.scalar(&id, `INSERT INTO academic.students (code, full_name, phone, branch_id, current_class_id, status)
		VALUES ($1, $2, '0912345678', $3, $4, 'studying') RETURNING id`, code, name, branch, class)
	return id
}

func (e *env) access(u iamtest.User) *iam.Access {
	e.t.Helper()
	a, err := e.IAM.LoadAccess(context.Background(), u.ID)
	if err != nil {
		e.t.Fatal(err)
	}
	return a
}

// expectMsg: mã HTTP + thông báo (lỗi: "message"; thành công: "message").
func expectMsg(t *testing.T, r iamtest.Resp, status int, msg string) {
	t.Helper()
	if r.Status != status || r.Body["message"] != msg {
		t.Fatalf("muốn %d %q, nhận %d %s", status, msg, r.Status, r.Raw)
	}
}

func upload(t *testing.T, name string, content []byte) *filestore.Upload {
	t.Helper()
	up, err := filestore.NewUpload(name, bytes.NewReader(content), 1<<20)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { up.Close() })
	return up
}
