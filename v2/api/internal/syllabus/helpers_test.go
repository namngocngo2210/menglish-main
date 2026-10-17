package syllabus_test

import (
	"bytes"
	"context"
	"strings"
	"testing"
	"time"

	"github.com/go-chi/chi/v5"
	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/catalogs"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/classes"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/files"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam/iamtest"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/filestore"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/secretbox"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/syllabus"
)

// Test HTTP với Postgres và Redis thật (TEST_DATABASE_URL, TEST_REDIS_URL; test xóa sạch DB Redis đó).
// Giáo trình nối Lớp học (lớp được xem, giãn tiến độ), kho file và Danh mục như cmd/menglish.

type env struct {
	*iamtest.Env
	svc *syllabus.Service
}

func setup(t *testing.T) *env {
	t.Helper()
	e := iamtest.Setup(t, "syllabus.curricula", "syllabus.big_tests", "syllabus.big_test_orders", "syllabus.pacing_requests",
		"syllabus.assignments", "academic.course_levels", "academic.courses", "academic.classes", "academic.students",
		"core.notifications", "core.files", "core.holidays")
	box, _ := secretbox.FromBase64("MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=")
	cat := catalogs.NewService(e.Pool, box, e.Log)
	cls := classes.NewService(e.Pool, cat, e.Log)
	store, err := filestore.NewLocal(t.TempDir(), files.APIBase+files.BlobPrefix, bytes.Repeat([]byte{7}, 32))
	if err != nil {
		t.Fatal(err)
	}
	fsvc := files.NewService(e.Pool, store, e.Log, 0)
	svc := syllabus.NewService(e.Pool, e.Log, syllabus.Deps{Classes: cls.SyllabusClasses(), Files: fsvc, SLA: cat})
	fh := files.NewHandler(fsvc, e.Log)
	e.Serve(func(r chi.Router) {
		syllabus.NewHandler(svc, e.Log).Routes(r)
		fh.Routes(r)
		fh.PublicRoutes(r)
	})
	return &env{Env: e, svc: svc}
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

func (e *env) text(sql string, args ...any) string {
	e.T.Helper()
	var s string
	if err := e.Pool.QueryRow(context.Background(), sql, args...).Scan(&s); err != nil {
		e.T.Fatal(err)
	}
	return s
}

// class: lớp đang học có GV chính, trình độ (mã) và phòng P101.
func (e *env) class(code string, branch uuid.UUID, teacher *uuid.UUID, level string) uuid.UUID {
	return e.id(`INSERT INTO academic.classes (code, name, branch_id, status, teacher_id, level, room)
		VALUES ($1, $2, $3, 'active', $4, nullif($5, ''), 'P101') RETURNING id`, code, "Lớp "+code, branch, teacher, level)
}

// level: trình độ gắn giáo trình.
func (e *env) level(code string, curriculum uuid.UUID) uuid.UUID {
	return e.id(`INSERT INTO academic.course_levels (code, name, target, lessons_count, syllabus_curriculum_id)
		VALUES ($1, $2, 'Thiếu nhi', 24, $3) RETURNING id`, code, "Trình độ "+code, curriculum)
}

// student: học viên đang học lớp class; phone rỗng = thiếu SĐT phụ huynh.
func (e *env) student(code, name string, branch, class uuid.UUID, parentPhone string) uuid.UUID {
	return e.id(`INSERT INTO academic.students (code, full_name, phone, branch_id, current_class_id, status, parent_phone)
		VALUES ($1, $2, '0900000000', $3, $4, 'studying', nullif($5, '')) RETURNING id`, code, name, branch, class, parentPhone)
}

// notified: mã người nhận (sắp xếp, nối bằng dấu phẩy) của thông báo loại typ.
func (e *env) notified(typ string) string {
	e.T.Helper()
	return e.text(`SELECT coalesce(string_agg(u.code, ',' ORDER BY u.code), '')
		FROM core.notifications n JOIN iam.users u ON u.id = n.user_id WHERE n.type = $1`, typ)
}

func expectMsg(t *testing.T, r iamtest.Resp, status int, part string) {
	t.Helper()
	msg, _ := r.Body["message"].(string)
	if r.Status != status || !strings.Contains(msg, part) {
		t.Fatalf("muốn %d với thông báo chứa %q, nhận %d %s", status, part, r.Status, r.Raw)
	}
}

func str(v any) string { s, _ := v.(string); return s }

func obj(v any) map[string]any { m, _ := v.(map[string]any); return m }

func list(v any) []map[string]any {
	var out []map[string]any
	raw, _ := v.([]any)
	for _, x := range raw {
		out = append(out, x.(map[string]any))
	}
	return out
}

// newID: id trả về trong thông báo thành công.
func newID(t *testing.T, r iamtest.Resp) string {
	t.Helper()
	id := str(r.Body["id"])
	if id == "" {
		t.Fatalf("thiếu id: %d %s", r.Status, r.Raw)
	}
	return id
}

// curriculum: giáo trình có n chặng (Chặng 1 tạo kèm), mỗi chặng 1 unit 2 buổi. Trả id giáo trình và id các chặng.
func (e *env) curriculum(c *iamtest.Client, code string, stages int) (string, []string) {
	e.T.Helper()
	r := c.Do("POST", "/syllabus/curricula", map[string]any{"code": code, "title": "Giáo trình " + code, "version": "v1"})
	iamtest.Expect(e.T, r, 201, "")
	cur := newID(e.T, r)
	for i := 2; i <= stages; i++ {
		iamtest.Expect(e.T, c.Do("POST", "/syllabus/stages", map[string]any{"curriculum_id": cur, "name": "Phần " + string(rune('A'+i-1))}), 201, "")
	}
	b := c.Do("GET", "/syllabus/builder?curriculum="+cur, nil)
	iamtest.Expect(e.T, b, 200, "")
	ids := []string{}
	session := int32(1)
	for i, st := range list(b.Body["stages"]) {
		ids = append(ids, str(st["id"]))
		u := c.Do("POST", "/syllabus/units", map[string]any{"curriculum_id": cur, "stage_id": st["id"], "unit_number": i + 1,
			"title": "Unit " + string(rune('A'+i))})
		iamtest.Expect(e.T, u, 201, "")
		for range 2 {
			iamtest.Expect(e.T, c.Do("POST", "/syllabus/lessons", map[string]any{"unit_id": newID(e.T, u), "session_no": session,
				"title": "Buổi học"}), 201, "")
			session++
		}
	}
	return cur, ids
}

func vnNow() time.Time { return time.Now().In(time.FixedZone("ICT", 7*3600)) }
