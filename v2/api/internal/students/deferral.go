package students

import (
	"context"
	"errors"
	"slices"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/students/studentsdb"
)

// Bảo lưu theo lịch (lệnh students:start-deferrals / students:end-deferrals của Laravel). Phân hệ Học phí chọn học viên
// tới ngày bắt đầu / hết bảo lưu theo khoản học phí và gọi các hàm này; người thao tác là hệ thống.

// StartScheduledDeferral: bảo lưu đã duyệt trước tới ngày bắt đầu → học viên chuyển "Bảo lưu" (chạy trong giao dịch
// của Học phí, cùng lúc đóng băng số buổi / công nợ). false: học viên đã bảo lưu hoặc không ở trạng thái học.
func (s *Service) StartScheduledDeferral(ctx context.Context, tx pgx.Tx, id uuid.UUID, until time.Time) (bool, error) {
	q := s.q.WithTx(tx)
	st, err := q.LockStudent(ctx, id)
	if errors.Is(err, pgx.ErrNoRows) {
		return false, nil
	}
	if err != nil {
		return false, err
	}
	if !slices.Contains([]string{StatusWaitingStart, StatusStudying, StatusSummerBreak}, st.Status) {
		return false, nil
	}
	next := StatusDeferred
	if err := q.SetStudentStatus(ctx, studentsdb.SetStudentStatusParams{ID: id, Status: next}); err != nil {
		return false, err
	}
	stamp := "[" + s.now().In(vnZone).Format("02/01/2006 15:04") + "] "
	if err := q.AppendStudentNote(ctx, studentsdb.AppendStudentNoteParams{ID: id,
		Line: stamp + "Bắt đầu bảo lưu tới " + until.Format("02/01/2006") + " (hệ thống tự chuyển)."}); err != nil {
		return false, err
	}
	if err := q.AddEvent(ctx, studentsdb.AddEventParams{StudentID: id, Kind: "status_changed",
		Message: "Bắt đầu bảo lưu tới " + until.Format("02/01/2006") + " (hệ thống tự chuyển).", FromStatus: &st.Status, ToStatus: &next}); err != nil {
		return false, err
	}
	s.log.Info("student deferral started", "student_id", id, "until", until.Format(time.DateOnly), "by", "system")
	return true, nil
}

// EndExpiredDeferral: hết thời gian bảo lưu → lớp đang học đã khai giảng thì Đang học, còn lại Chờ khai giảng; bỏ đóng
// băng học phí, báo Học vụ. false: học viên không còn ở trạng thái Bảo lưu.
func (s *Service) EndExpiredDeferral(ctx context.Context, id uuid.UUID) (bool, error) {
	ended := false
	err := pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error {
		q := s.q.WithTx(tx)
		st, err := q.LockStudent(ctx, id)
		if errors.Is(err, pgx.ErrNoRows) {
			return nil
		}
		if err != nil {
			return err
		}
		if st.Status != StatusDeferred {
			return nil
		}
		next := StatusWaitingStart
		if st.CurrentClassID != nil && s.Classes != nil {
			started, err := s.Classes.ClassStarted(ctx, *st.CurrentClassID)
			if err != nil {
				return err
			}
			if started {
				next = StatusStudying
			}
		}
		if s.Tuition != nil {
			if err := s.Tuition.ReleaseDeferral(ctx, tx, id, uuid.Nil); err != nil {
				return err
			}
		}
		if err := q.SetStudentStatus(ctx, studentsdb.SetStudentStatusParams{ID: id, Status: next}); err != nil {
			return err
		}
		text := "Kết thúc bảo lưu → " + StatusLabel(next) + ". Thực hiện bởi hệ thống (hết thời gian bảo lưu)."
		stamp := "[" + s.now().In(vnZone).Format("02/01/2006 15:04") + "] "
		if err := q.AppendStudentNote(ctx, studentsdb.AppendStudentNoteParams{ID: id, Line: stamp + text}); err != nil {
			return err
		}
		if err := q.AddEvent(ctx, studentsdb.AddEventParams{StudentID: id, Kind: "deferral_ended", Message: text, FromStatus: &st.Status, ToStatus: &next}); err != nil {
			return err
		}
		if err := notifyDeferralEnded(ctx, tx, nil, st, next); err != nil {
			return err
		}
		ended = true
		s.log.Info("student deferral ended", "student_id", id, "status", next, "by", "system")
		return nil
	})
	return ended, err
}
