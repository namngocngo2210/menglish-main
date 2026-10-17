package payroll

import (
	"context"
	"fmt"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/notify"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/payroll/payrolldb"
)

// Hàm cho phân hệ khác (adapter ở cmd/menglish): kỳ lương khóa chấm công, biên bản đi muộn tự động của Chấm công
// (StaffAttendanceService::syncLatePenalty), biên bản trễ mốc của Dự án học thuật (SlaBreachService::breach), biên bản
// trả kết quả Big Test trễ của Giáo trình (BigTestSlaService::enforceLateResults).

// LockedPeriod: khoảng ngày của một kỳ lương đã duyệt / đã chi trả.
type LockedPeriod struct {
	From, To time.Time // 0 giờ VN
	Code     string
	Title    string
}

// LockedPeriods: các kỳ lương đã duyệt / đã chi trả (PayrollPeriod::isLockedFor).
func (s *Service) LockedPeriods(ctx context.Context) ([]LockedPeriod, error) {
	rows, err := s.q.AllPeriods(ctx)
	if err != nil {
		return nil, err
	}
	out := []LockedPeriod{}
	for _, p := range rows {
		if locked(p.Status) {
			out = append(out, LockedPeriod{From: asDay(p.StartDate), To: asDay(p.EndDate), Code: p.Code, Title: p.Title})
		}
	}
	return out, nil
}

// PeriodBrief: trạng thái kỳ lương của một tháng.
type PeriodBrief struct {
	StatusLabel string
	Locked      bool
}

// MonthPeriod: kỳ lương của tháng chứa month; nil = chưa khởi tạo bảng lương.
func (s *Service) MonthPeriod(ctx context.Context, month time.Time) (*PeriodBrief, error) {
	m := month.In(vnZone)
	p, err := s.q.PeriodByMonth(ctx, payrolldb.PeriodByMonthParams{Year: int16(m.Year()), Month: int16(m.Month())})
	if isNoRows(err) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	return &PeriodBrief{StatusLabel: periodStatusLabels[p.Status], Locked: locked(p.Status)}, nil
}

// PenaltyRef: mã + trạng thái của một biên bản.
type PenaltyRef struct {
	ID     uuid.UUID
	Code   string
	Status string
}

// LatePenalty: dòng chấm công đi muộn tính lỗi.
type LatePenalty struct {
	UserID    uuid.UUID
	WorkDate  time.Time
	Violation string // "Đi muộn 12 phút (chấm công ngày 17/10/2026)"
	Notes     string
}

// SyncLatePenalty: biên bản đang gắn (current) chưa hủy → còn chờ giải trình thì cập nhật nội dung vi phạm; chưa có /
// đã hủy → lập biên bản mới (chờ giải trình, 0đ, nhóm Vận hành, người báo "Tự động"). created = vừa lập mới (phân hệ
// gọi tự gửi thông báo cho nhân sự). Chạy trong tx của Chấm công.
func (s *Service) SyncLatePenalty(ctx context.Context, tx pgxTx, current *uuid.UUID, in LatePenalty) (ref *PenaltyRef, created bool, err error) {
	q := s.q.WithTx(tx)
	if current != nil {
		p, err := q.LockPenalty(ctx, *current)
		if err != nil && !isNoRows(err) {
			return nil, false, err
		}
		if err == nil && p.Status != "cancelled" {
			if p.Status == "pending" && p.ViolationType != in.Violation {
				if err := q.SetPendingViolation(ctx, payrolldb.SetPendingViolationParams{ViolationType: in.Violation, ID: p.ID}); err != nil {
					return nil, false, err
				}
			}
			return &PenaltyRef{ID: p.ID, Code: p.Code, Status: p.Status}, false, nil
		}
	}
	cat := "operations"
	p, err := s.insertPenalty(ctx, q, payrolldb.InsertPenaltyParams{UserID: in.UserID, ViolationType: in.Violation,
		ErrorCategory: &cat, ViolationDate: dbDay(in.WorkDate), Amount: 0, Notes: trimPtr(&in.Notes)})
	if err != nil {
		return nil, false, err
	}
	if err := audit.Record(ctx, tx, audit.Entry{Module: audit.ModulePayroll, Event: audit.EventCreated, Action: "penalty.auto",
		SubjectType: "penalty", SubjectID: p.ID.String(), SubjectLabel: p.Code,
		Description: "Hệ thống tự lập biên bản " + p.Code + ": " + in.Violation,
		Properties:  map[string]any{"user_id": in.UserID, "source": "staff_attendance"}}); err != nil {
		return nil, false, err
	}
	return &PenaltyRef{ID: p.ID, Code: p.Code, Status: p.Status}, true, nil
}

// AutoCancelPenalty: biên bản còn chờ / đã giải trình tự hủy với ghi chú note (trạng thái khác giữ nguyên).
func (s *Service) AutoCancelPenalty(ctx context.Context, tx pgxTx, id uuid.UUID, note string) error {
	return s.q.WithTx(tx).AutoCancelPenalty(ctx, payrolldb.AutoCancelPenaltyParams{Note: &note, ID: id})
}

// PenaltyRefs: mã + trạng thái các biên bản theo id.
func (s *Service) PenaltyRefs(ctx context.Context, ids []uuid.UUID) (map[uuid.UUID]PenaltyRef, error) {
	out := map[uuid.UUID]PenaltyRef{}
	if len(ids) == 0 {
		return out, nil
	}
	rows, err := s.q.PenaltyRefs(ctx, ids)
	if err != nil {
		return nil, err
	}
	for _, r := range rows {
		out[r.ID] = PenaltyRef{ID: r.ID, Code: r.Code, Status: r.Status}
	}
	return out, nil
}

// ViolationCounts: số biên bản chưa hủy có ngày vi phạm trong [from, to] của từng người.
func (s *Service) ViolationCounts(ctx context.Context, userIDs []uuid.UUID, from, to time.Time) (map[uuid.UUID]int, error) {
	out := map[uuid.UUID]int{}
	if len(userIDs) == 0 {
		return out, nil
	}
	rows, err := s.q.ViolationCountsByUser(ctx, payrolldb.ViolationCountsByUserParams{UserIds: userIDs, FromDay: dbDay(from), ToDay: dbDay(to)})
	if err != nil {
		return nil, err
	}
	for _, r := range rows {
		out[r.UserID] = int(r.N)
	}
	return out, nil
}

// AutoPenaltyRow: biên bản tự động của một nguồn theo khóa.
type AutoPenaltyRow struct {
	ID          uuid.UUID
	Code        string
	UserID      uuid.UUID
	UserName    string
	Key         string
	Status      string
	StatusLabel string
	Amount      int64
	CreatedAt   time.Time
}

// AutoPenalties: biên bản tự động của nguồn source có khóa trong keys, mới nhất trước.
func (s *Service) AutoPenalties(ctx context.Context, source string, keys []string) ([]AutoPenaltyRow, error) {
	if len(keys) == 0 {
		return nil, nil
	}
	rows, err := s.q.AutoPenaltiesByKeys(ctx, payrolldb.AutoPenaltiesByKeysParams{AutoSource: &source, Keys: keys})
	if err != nil {
		return nil, err
	}
	labels := penaltyStatusLabels(s.paymentDueDays(ctx, s.q))
	out := make([]AutoPenaltyRow, 0, len(rows))
	for _, r := range rows {
		out = append(out, AutoPenaltyRow{ID: r.ID, Code: r.Code, UserID: r.UserID, UserName: r.UserName, Key: r.AutoKey,
			Status: r.Status, StatusLabel: labelOf(labels, r.Status), Amount: r.Amount, CreatedAt: r.CreatedAt})
	}
	return out, nil
}

// ───── Big Test trả kết quả trễ (BigTestSlaService::enforceLateResults) ─────

// AutoBigTestLate: nguồn biên bản trả kết quả Big Test trễ hạn (BigTestSlaService::AUTO_LATE_RESULTS).
const AutoBigTestLate = "big_test_late_results"

// BigTestLate: số liệu trễ hôm nay của một đợt thi.
type BigTestLate struct {
	BigTestID   uuid.UUID
	ClassID     *uuid.UUID
	UserID      uuid.UUID // người chịu trách nhiệm; uuid.Nil = không ai (đã nhập đủ, chờ duyệt)
	Violation   string
	DueDate     time.Time // hạn trả kết quả
	Amount      int64
	Notes       string
	EnteredOnly bool   // GV đã nhập đủ, chỉ còn chờ duyệt: dừng tăng mức phạt
	Message     string // nội dung thông báo khi lập mới
}

// SyncBigTestLate: đã có biên bản → còn chờ / đã giải trình và chưa nhập đủ thì cập nhật số ngày trễ + mức gợi ý (còn chờ
// thì chuyển cho người đang chịu trách nhiệm); chưa có và có người chịu trách nhiệm → lập mới (Học thuật) và báo người đó.
// created = vừa lập mới. Chạy trong tx của Giáo trình.
func (s *Service) SyncBigTestLate(ctx context.Context, tx pgxTx, in BigTestLate) (created bool, err error) {
	q := s.q.WithTx(tx)
	src := AutoBigTestLate
	p, err := q.LockBigTestAutoPenalty(ctx, payrolldb.LockBigTestAutoPenaltyParams{BigTestID: &in.BigTestID, AutoSource: &src})
	if err == nil {
		if (p.Status == "pending" || p.Status == "explained") && !in.EnteredOnly {
			var user *uuid.UUID
			if p.Status == "pending" && in.UserID != uuid.Nil {
				user = &in.UserID
			}
			return false, q.UpdateLateResultPenalty(ctx, payrolldb.UpdateLateResultPenaltyParams{ViolationType: in.Violation,
				Amount: max(0, in.Amount), UserID: user, ID: p.ID})
		}
		return false, nil
	}
	if !isNoRows(err) {
		return false, err
	}
	if in.UserID == uuid.Nil {
		return false, nil
	}
	pen, err := s.CreateAutoPenalty(ctx, tx, AutoPenalty{UserID: in.UserID, Source: src, Key: in.BigTestID.String(),
		ViolationType: in.Violation, Category: "academic", ViolationAt: in.DueDate, Amount: in.Amount, ClassID: in.ClassID,
		BigTestID: &in.BigTestID, Notes: in.Notes, Subject: "trả kết quả Big Test trễ hạn", Body: in.Message,
		Data: map[string]any{"big_test_id": in.BigTestID.String()}})
	return pen != nil, err
}

// ───── Biên bản SLA quá hạn (SlaBreachService::breach) ─────

// SlaBreach: một đối tượng quá hạn SLA. Rule* là cấu hình SLA hiệu lực (catalogs.SlaRule) do phân hệ gọi truyền vào.
type SlaBreach struct {
	RuleKey     string
	RuleLabel   string
	Violation   string // tên lỗi của SLA
	Category    string
	Penalize    bool    // SLA bật "Tự lập biên bản"
	Amount      int64   // mức phạt khi không có bậc
	Ladder      []int64 // bậc phạt theo lần tái phạm
	ResetMonths int     // số tháng cộng dồn lần tái phạm
	Key         string  // khóa đối tượng (mỗi đối tượng một biên bản)
	OwnerID     *uuid.UUID
	Subject     string // nhãn đối tượng
	DueAt       time.Time
	Now         time.Time // lúc phát hiện quá hạn
	Link        string    // màn của đối tượng (thông báo khi không lập biên bản)
}

// amountFor: Sla::amountForOccurrence.
func amountFor(in SlaBreach, occurrence int) int64 {
	if len(in.Ladder) == 0 {
		return in.Amount
	}
	return in.Ladder[min(occurrence, len(in.Ladder))-1]
}

// BreachSla: quá hạn → biên bản chờ giải trình cho người phụ trách (bậc phạt theo lần tái phạm trong N tháng, không tính
// biên bản đã hủy) + báo người phụ trách và Admin; SLA tắt phạt hoặc không có người phụ trách → chỉ báo "Quá hạn SLA".
// Đối tượng đã có biên bản (cùng RuleKey + Key) thì không lập / báo lại. Trả biên bản vừa lập (nil khi không lập).
func (s *Service) BreachSla(ctx context.Context, in SlaBreach) (*PenaltyRef, error) {
	var ref *PenaltyRef
	err := pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error {
		q := s.q.WithTx(tx)
		ownerName := ""
		if in.OwnerID != nil {
			n, err := q.UserFullName(ctx, *in.OwnerID)
			if err != nil && !isNoRows(err) {
				return err
			}
			ownerName = n
		}
		due := in.DueAt.In(vnZone).Format("15:04 02/01/2006")
		typ, title, body, link := "sla_breach", "Quá hạn SLA: "+in.RuleLabel+" — "+in.Subject, "Hạn "+due+" đã qua.", in.Link
		if in.Penalize && in.OwnerID != nil {
			n, err := s.AutoOccurrences(ctx, tx, *in.OwnerID, in.RuleKey, in.Now.AddDate(0, -max(1, in.ResetMonths), 0))
			if err != nil {
				return err
			}
			occurrence := n + 1
			amount := amountFor(in, occurrence)
			ladderNote := ""
			if len(in.Ladder) > 0 {
				ladderNote = fmt.Sprintf(" Lần thứ %d trong %d tháng", occurrence, max(1, in.ResetMonths))
				if amount > 0 {
					ladderNote += "."
				} else {
					ladderNote += " (mức nhắc nhở, không phạt tiền)."
				}
			}
			cat := in.Category
			if cat == "" {
				cat = "operations"
			}
			violation := in.Violation + ": " + in.Subject
			// Người phụ trách nhận thông báo ở CreateAutoPenalty; Admin nhận bên dưới.
			p, err := s.CreateAutoPenalty(ctx, tx, AutoPenalty{UserID: *in.OwnerID, Source: in.RuleKey, Key: in.Key,
				ViolationType: violation, Category: cat, ViolationAt: in.DueAt, Amount: amount,
				Notes:   in.RuleLabel + ": hạn " + due + ", quá hạn lúc phát hiện " + in.Now.In(vnZone).Format("15:04 02/01/2006") + "." + ladderNote,
				Subject: in.Violation + " — " + in.Subject})
			if err != nil || p == nil {
				return err // p == nil: đối tượng đã có biên bản
			}
			ref = &PenaltyRef{ID: p.ID, Code: p.Code, Status: p.Status}
			typ, title = "penalty_created", "Biên bản "+p.Code+": "+in.Violation+" — "+in.Subject
			body = "Hãy xử lý và gửi giải trình; biên bản chờ CM / Học thuật / Admin xác nhận trước khi tính vào lương."
			link = "/penalties?search=" + p.Code
		}
		admins, err := iam.AdminIDs(ctx, tx)
		if err != nil {
			return err
		}
		send := func(ids []uuid.UUID, prefix string) error {
			if len(ids) == 0 {
				return nil
			}
			_, err := notify.Send(ctx, tx, ids, notify.Notification{Type: typ, Title: title, Body: prefix + body, Link: link,
				Data: map[string]any{"sla_rule": in.RuleKey}})
			return err
		}
		if in.OwnerID != nil && ref == nil {
			if err := send([]uuid.UUID{*in.OwnerID}, "Bạn là người phụ trách. "); err != nil {
				return err
			}
		}
		others := make([]uuid.UUID, 0, len(admins))
		for _, id := range admins {
			if in.OwnerID == nil || id != *in.OwnerID {
				others = append(others, id)
			}
		}
		prefix := "Chưa có người phụ trách. "
		if in.OwnerID != nil {
			prefix = "Người phụ trách: " + ownerName + ". "
		}
		return send(others, prefix)
	})
	return ref, err
}
