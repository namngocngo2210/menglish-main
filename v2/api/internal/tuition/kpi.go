package tuition

import (
	"context"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/kpi/kpisrc"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/tuition/tuitiondb"
)

// Nguồn KPI tự động của Học phí (KpiAutoCounter::tuitionNoReminder / receiptRejected).

// KpiSources: tuition_no_reminder, receipt_rejected.
func (s *Service) KpiSources() kpisrc.Sources {
	return kpisrc.Sources{"tuition_no_reminder": s.kpiNoReminder, "receipt_rejected": s.kpiReceiptRejected}
}

// kpiNoReminder: hồ sơ đến hạn trong kỳ mà quá hạn nhưng không có lần nhắc học phí nào từ 3 ngày trước hạn (tính cho người
// phụ trách khách đã chốt ra học viên). Kỳ chưa hết thì tính tới hiện tại.
func (s *Service) kpiNoReminder(ctx context.Context, userID uuid.UUID, from, to time.Time) (kpisrc.Result, error) {
	end := to
	if now := s.now(); now.Before(end) {
		end = now
	}
	rows, err := s.q.KpiTuitionNoReminder(ctx, tuitiondb.KpiTuitionNoReminderParams{UserID: &userID, FromDate: dateOf(from),
		EndDate: dateOf(end), EndAt: end})
	if err != nil {
		return kpisrc.Result{}, err
	}
	recs := make([]kpisrc.Record, 0, len(rows))
	for _, r := range rows {
		due := time.Date(r.DueDate.Year(), r.DueDate.Month(), r.DueDate.Day(), 0, 0, 0, 0, vnZone)
		recs = append(recs, kpisrc.Record{At: due, Text: r.StudentName + ": hạn " + due.Format("02/01") + ", chưa có lần nhắc học phí"})
	}
	return kpisrc.Result{Records: recs}, nil
}

// kpiReceiptRejected: phiếu thu bị trả về — theo thông báo "Phiếu thu bị trả về" gửi người lập (phiếu sửa và gửi lại vẫn còn
// dấu vết), mỗi phiếu một lần trong ngày.
func (s *Service) kpiReceiptRejected(ctx context.Context, userID uuid.UUID, from, to time.Time) (kpisrc.Result, error) {
	rows, err := s.q.KpiReceiptRejected(ctx, tuitiondb.KpiReceiptRejectedParams{UserID: userID, FromAt: from, ToAt: to})
	if err != nil {
		return kpisrc.Result{}, err
	}
	recs := make([]kpisrc.Record, 0, len(rows))
	for _, r := range rows {
		text := r.Body
		if text == "" {
			text = r.Title
		}
		recs = append(recs, kpisrc.Record{At: r.CreatedAt.In(vnZone), Text: text})
	}
	return kpisrc.Result{Records: recs}, nil
}
