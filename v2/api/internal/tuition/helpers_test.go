package tuition

import (
	"context"
	"strings"
	"testing"

	"github.com/go-chi/chi/v5"
	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/catalogs"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam/iamtest"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/secretbox"
)

// Test HTTP với Postgres và Redis thật (TEST_DATABASE_URL, TEST_REDIS_URL; test xóa sạch DB Redis đó).

type env struct {
	*iamtest.Env
	svc *Service
}

func setup(t *testing.T) *env {
	t.Helper()
	e := iamtest.Setup(t, "academic.courses", "academic.classes", "academic.students", "finance.tuitions", "finance.receipts",
		"finance.invoice_ranges", "finance.bank_transactions", "finance.debt_reminder_rules", "core.notifications", "core.files")
	box, _ := secretbox.FromBase64("MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=")
	svc := NewService(e.Pool, box, catalogs.NewService(e.Pool, box, e.Log), e.Log)
	e.Serve(func(r chi.Router) { NewHandler(svc, e.Log).Routes(r) })
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

// course: khóa học niêm yết fee đồng cho lessons buổi.
func (e *env) course(code string, fee int64, lessons int) uuid.UUID {
	return e.id(`INSERT INTO academic.courses (code, name, tuition_fee, total_lessons) VALUES ($1, $2, $3, $4) RETURNING id`,
		code, "Khóa "+code, fee, lessons)
}

func (e *env) class(code string, branch, course uuid.UUID) uuid.UUID {
	return e.id(`INSERT INTO academic.classes (code, name, branch_id, course_id, status) VALUES ($1, $2, $3, $4, 'active') RETURNING id`,
		code, "Lớp "+code, branch, course)
}

func (e *env) student(code, name string, branch uuid.UUID, class *uuid.UUID) uuid.UUID {
	return e.id(`INSERT INTO academic.students (code, full_name, phone, branch_id, current_class_id, status, parent_name)
		VALUES ($1, $2, '0912345678', $3, $4, 'studying', 'Phụ huynh '||$2) RETURNING id`, code, name, branch, class)
}

// tuition: khoản học phí còn nợ toàn bộ.
func (e *env) tuition(student, class, branch uuid.UUID, total int64, sessions int) uuid.UUID {
	return e.id(`INSERT INTO finance.tuitions (student_id, class_id, branch_id, total_amount, session_count, final_amount,
		debt_amount, due_date, status) VALUES ($1, $2, $3, $4, $5, $4, $4, CURRENT_DATE + 10, 'unpaid') RETURNING id`,
		student, class, branch, total, sessions)
}

type debt struct {
	Paid, Debt int64
	Status     string
}

func (e *env) debtOf(id uuid.UUID) debt {
	e.T.Helper()
	var d debt
	if err := e.Pool.QueryRow(context.Background(), `SELECT paid_amount, debt_amount, status FROM finance.tuitions WHERE id = $1`, id).
		Scan(&d.Paid, &d.Debt, &d.Status); err != nil {
		e.T.Fatal(err)
	}
	return d
}

func (e *env) count(sql string, args ...any) int {
	e.T.Helper()
	var n int
	if err := e.Pool.QueryRow(context.Background(), sql, args...).Scan(&n); err != nil {
		e.T.Fatal(err)
	}
	return n
}

// notified: mã người nhận (sắp xếp, nối bằng dấu phẩy) của thông báo loại typ.
func (e *env) notified(typ string) string {
	e.T.Helper()
	var codes string
	if err := e.Pool.QueryRow(context.Background(), `SELECT coalesce(string_agg(u.code, ',' ORDER BY u.code), '')
		FROM core.notifications n JOIN iam.users u ON u.id = n.user_id WHERE n.type = $1`, typ).Scan(&codes); err != nil {
		e.T.Fatal(err)
	}
	return codes
}

func expectMsg(t *testing.T, r iamtest.Resp, status int, part string) {
	t.Helper()
	msg, _ := r.Body["message"].(string)
	if r.Status != status || !strings.Contains(msg, part) {
		t.Fatalf("muốn %d với thông báo chứa %q, nhận %d %s", status, part, r.Status, r.Raw)
	}
}

func str(v any) string { s, _ := v.(string); return s }

func num(v any) int64 { f, _ := v.(float64); return int64(f) }

func obj(v any) map[string]any { m, _ := v.(map[string]any); return m }

func list(v any) []map[string]any {
	var out []map[string]any
	raw, _ := v.([]any)
	for _, x := range raw {
		out = append(out, x.(map[string]any))
	}
	return out
}
