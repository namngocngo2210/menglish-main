package tuition

import (
	"context"
	"strings"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/tuition/tuitiondb"
)

// Bảo lưu ở phía học phí (StudentDeferralService): khoảng bảo lưu và số buổi / công nợ đóng băng nằm trên khoản học
// phí; phân hệ Học viên gọi qua students.TuitionHooks.

// DeferredUntil: ngày cuối bảo lưu của khoản học phí mới nhất (students.TuitionHooks).
func (s *Service) DeferredUntil(ctx context.Context, studentID uuid.UUID) (*time.Time, error) {
	d, err := s.q.LatestTuitionDeferredUntil(ctx, studentID)
	if isNoRows(err) {
		return nil, nil
	}
	return d, err
}

// ReleaseDeferral: kết thúc bảo lưu (tay hoặc hết hạn) → khoảng bảo lưu đã duyệt đang chạy kết thúc hôm qua, bỏ đóng
// băng số buổi / công nợ, xóa khoảng bảo lưu (ghi lịch sử vào ghi chú), nhắc nợ đang dừng tới ngày học lại chạy lại từ
// hôm nay (releaseTuition). actor = uuid.Nil: hệ thống.
func (s *Service) ReleaseDeferral(ctx context.Context, tx pgx.Tx, studentID, actor uuid.UUID) error {
	q := s.q.WithTx(tx)
	today := s.today()
	if err := q.ShortenActiveDeferrals(ctx, tuitiondb.ShortenActiveDeferralsParams{Today: today, StudentID: studentID}); err != nil {
		return err
	}
	t, err := q.LockLatestTuitionDeferral(ctx, studentID)
	if isNoRows(err) {
		return nil
	}
	if err != nil {
		return err
	}
	if t.DeferredFrom == nil && t.DeferredUntil == nil && t.FrozenRemainingSessions == nil && t.FrozenDebtAmount == nil {
		return nil
	}
	by := "hệ thống"
	if actor != uuid.Nil {
		if n, err := q.UserFullName(ctx, actor); err == nil {
			by = n
		} else if !isNoRows(err) {
			return err
		}
	}
	rng := func(d *time.Time) string {
		if d == nil {
			return "?"
		}
		return d.Format("02/01/2006")
	}
	paused := t.ReminderPausedUntil
	if t.DeferredUntil != nil && paused != nil {
		resume := t.DeferredUntil.AddDate(0, 0, 1)
		if paused.Equal(resume) && paused.After(today) {
			paused = &today
		}
	}
	line := "[" + s.nowVN().Format("02/01/2006 15:04") + "] Kết thúc bảo lưu (" + rng(t.DeferredFrom) + " – " + rng(t.DeferredUntil) +
		"): bỏ đóng băng số buổi và công nợ. Thực hiện bởi " + by + "."
	notes := strings.TrimSpace(textOf(t.Notes) + "\n" + line)
	if err := q.ReleaseTuitionDeferral(ctx, tuitiondb.ReleaseTuitionDeferralParams{ID: t.ID, ReminderPausedUntil: paused, Notes: &notes}); err != nil {
		return err
	}
	s.log.Info("tuition deferral released", "tuition_id", t.ID, "student_id", studentID, "by", actor)
	return nil
}
