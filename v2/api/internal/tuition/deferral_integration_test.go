package tuition

import (
	"context"
	"strings"
	"testing"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

func TestReleaseDeferral(t *testing.T) {
	e := setup(t)
	a := e.Branch("CN-A")
	u, _ := e.Member("HV001", "Học vụ A", []uuid.UUID{a}, iam.RoleAcademicStaff)
	course := e.course("IE", 4_800_000, 24)
	class := e.class("IE-01", a, course)
	st := e.student("HV-0001", "Lê Bảo Lưu", a, &class)
	tid := e.tuition(st, class, a, 4_800_000, 24)
	today := e.svc.today()
	// Bảo lưu từ 10 ngày trước tới 5 ngày nữa; nhắc nợ dừng tới ngày học lại.
	e.exec(`UPDATE finance.tuitions SET deferred_from = $2::date - 10, deferred_until = $2::date + 5, frozen_remaining_sessions = 20,
		frozen_debt_amount = 4000000, reminder_paused_until = $2::date + 6, notes = 'Ghi chú cũ' WHERE id = $1`, tid, today)
	e.exec(`UPDATE academic.students SET status = 'deferred' WHERE id = $1`, st)

	until, err := e.svc.DeferredUntil(context.Background(), st)
	if err != nil || until == nil || !until.Equal(today.AddDate(0, 0, 5)) {
		t.Fatalf("hạn bảo lưu: %v %v", until, err)
	}
	release := func() {
		t.Helper()
		if err := pgx.BeginFunc(context.Background(), e.Pool, func(tx pgx.Tx) error {
			return e.svc.ReleaseDeferral(context.Background(), tx, st, u.ID)
		}); err != nil {
			t.Fatal(err)
		}
	}
	release()
	var from, to, paused *time.Time
	var frozen *int32
	var notes string
	if err := e.Pool.QueryRow(context.Background(), `SELECT deferred_from, deferred_until, frozen_remaining_sessions, reminder_paused_until, notes
		FROM finance.tuitions WHERE id = $1`, tid).Scan(&from, &to, &frozen, &paused, &notes); err != nil {
		t.Fatal(err)
	}
	if from != nil || to != nil || frozen != nil || paused == nil || !paused.Equal(today) ||
		!strings.HasPrefix(notes, "Ghi chú cũ\n[") || !strings.Contains(notes, "bỏ đóng băng số buổi và công nợ. Thực hiện bởi Học vụ A.") {
		t.Fatalf("sau khi kết thúc bảo lưu: %v %v %v %v %q", from, to, frozen, paused, notes)
	}
	// Idempotent: không còn khoảng bảo lưu thì không ghi thêm.
	release()
	var again string
	if err := e.Pool.QueryRow(context.Background(), `SELECT notes FROM finance.tuitions WHERE id = $1`, tid).Scan(&again); err != nil || again != notes {
		t.Fatalf("gọi lại: %q %v", again, err)
	}
}
