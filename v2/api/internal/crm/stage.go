package crm

import (
	"context"
	"slices"
	"strings"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/crm/crmdb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

// Luật giai đoạn khách (Laravel CrmStageService):
//   - Chuyển tay (Kanban / hồ sơ): CM (lead.stage_forward) chỉ chuyển TIẾN đúng 1 bước; Chờ xếp lớp / Đã chốt chỉ đạt
//     qua Chốt & Xếp lớp / Gán lớp; lùi bước cần lead.stage_back + lý do; khách đã chốt không lùi, không sang thất bại.
//   - Hẹn test / Đã test cần lịch hẹn / điểm test (forwardBlockReason).
//   - Thất bại: lead.mark_lost + lý do. Khách Thất bại quay lại: "Mở lại" về Đang tư vấn (lead.stage_forward + lý do).
//   - AdvanceTo: hook tự động cho các phân hệ khác (hẹn test, chấm bài, chốt, gán lớp) — chỉ đi tiến.
// Mọi thay đổi ghi nhật ký stage_change (from/to, lý do).

const reopenStage = StageConsulting

// Nguồn hợp lệ cho bước chuyển tự động: đích → các nguồn.
var automaticSources = map[string][]string{
	StageConsulting:    {StageNew},
	StageTestScheduled: {StageConsulting},
	StageTesting:       {StageConsulting, StageTestScheduled},
	StageTested:        {StageConsulting, StageTestScheduled, StageTesting},
	StageResultSent:    {StageTested},
	StageWaitingClass:  ClosableStages,
	StageWon:           append(slices.Clone(ClosableStages), StageWaitingClass),
}

func nextStage(stage string) string {
	i := stageIndex(stage)
	if i < 0 || i+1 >= len(PipelineStages) {
		return ""
	}
	return PipelineStages[i+1]
}

// forwardBlockReason: bước cần dữ liệu thật ("Hẹn test" cần lịch hẹn, "Đã test" cần điểm).
func forwardBlockReason(stage string, appointment *time.Time, testScore *string) string {
	switch {
	case stage == StageTestScheduled && appointment == nil:
		return `Chưa có lịch hẹn test. Bấm "Hẹn test" trong tab Vận hành, khách tự chuyển sang Đã hẹn test.`
	case stage == StageTested && strings.TrimSpace(deref(testScore)) == "":
		return "Chưa có điểm test. Nhập điểm test đầu vào, khách tự chuyển sang Đã test."
	}
	return ""
}

func stageControls(a *iam.Access, stage string, appointment *time.Time, testScore *string) StageControls {
	c := StageControls{Backward: []Option{}}
	fwd, back := a.Can("lead.stage_forward"), a.Can("lead.stage_back")
	if next := nextStage(stage); next != "" {
		if blocked := forwardBlockReason(next, appointment, testScore); blocked != "" {
			c.NextBlocked = &blocked
		} else if fwd && !isClosed(next) {
			c.Next, c.NextLabel = &next, ptr(StageLabel(next))
		}
	}
	if back && !isClosed(stage) && stage != StageLost {
		for i := stageIndex(stage) - 1; i >= 0; i-- {
			c.Backward = append(c.Backward, Option{PipelineStages[i], StageLabel(PipelineStages[i])})
		}
	}
	c.CanLose = a.Can("lead.mark_lost") && !isClosed(stage) && stage != StageLost
	c.CanReopen = stage == StageLost && fwd
	c.CanCancelClosing = stage == StageWaitingClass && back
	return c
}

// StageInput: chuyển giai đoạn bằng tay.
type StageInput struct {
	Stage      string  `json:"stage"`
	LostReason *string `json:"lost_reason"`
	Reason     *string `json:"reason"`
}

// StageResult: kết quả chuyển giai đoạn.
type StageResult struct {
	Stage      string `json:"stage"`
	StageLabel string `json:"stage_label"`
	Message    string `json:"message"`
}

func (s *Service) MoveStage(ctx context.Context, a *iam.Access, idOrCode string, in StageInput) (*StageResult, error) {
	if !validStage(in.Stage) {
		return nil, iam.Invalid("Giai đoạn không hợp lệ.")
	}
	reason := in.Reason
	if in.Stage == StageLost {
		reason = in.LostReason
		if strings.TrimSpace(deref(reason)) == "" {
			return nil, iam.Invalid("Vui lòng nhập lý do thất bại.")
		}
		if runeLen(*reason) > 255 {
			return nil, iam.Invalid("Lý do thất bại không được vượt quá 255 ký tự.")
		}
	}
	if err := maxLen(reason, 1000, "Lý do"); err != nil {
		return nil, err
	}
	var out *StageResult
	err := s.tx(ctx, func(q *crmdb.Queries) error {
		l, err := s.lead(ctx, q, a, idOrCode)
		if err != nil {
			return err
		}
		// Khóa dòng khách: hai người cùng bấm thì người sau thấy giai đoạn mới, không ghi lịch sử trùng.
		locked, err := lockLead(ctx, q, l.ID)
		if err != nil {
			return err
		}
		if err := s.moveLocked(ctx, q, a, locked, in.Stage, reason); err != nil {
			return err
		}
		out = &StageResult{Stage: in.Stage, StageLabel: StageLabel(in.Stage),
			Message: "Đã chuyển khách hàng " + l.Name + " sang giai đoạn " + StageLabel(in.Stage) + "!"}
		return nil
	})
	if err != nil {
		return nil, err
	}
	s.log.Info("crm: chuyển giai đoạn", "lead", idOrCode, "to", in.Stage, "by", a.UserID)
	return out, nil
}

// NextStage: nút "Tiếp theo" — chuyển sang đúng bước kế tiếp.
func (s *Service) NextStage(ctx context.Context, a *iam.Access, idOrCode string, reason *string) (*StageResult, error) {
	l, err := s.lead(ctx, s.q, a, idOrCode)
	if err != nil {
		return nil, err
	}
	next := nextStage(l.Stage)
	if next == "" {
		return nil, iam.Invalid("Lead đã ở giai đoạn cuối hoặc đã thất bại.")
	}
	return s.MoveStage(ctx, a, l.ID.String(), StageInput{Stage: next, Reason: reason})
}

func (s *Service) moveLocked(ctx context.Context, q *crmdb.Queries, a *iam.Access, l crmdb.CrmLead, stage string, reason *string) error {
	from := l.Stage
	r := strings.TrimSpace(deref(reason))
	if stage == from {
		return nil
	}
	fwd := a.Can("lead.stage_forward")
	if from == StageLost {
		if stage != reopenStage {
			return iam.Invalid("Khách Thất bại chỉ mở lại được về bước " + StageLabel(reopenStage) + ".")
		}
		if !fwd {
			return forbidden("Chỉ Học vụ / Quản lý cơ sở / Admin được mở lại khách Thất bại.")
		}
		if r == "" {
			return iam.Invalid("Vui lòng nhập lý do mở lại khách.")
		}
		return applyStage(ctx, q, l, stage, &a.UserID, r, "clear", false)
	}
	if stage == StageLost && !a.Can("lead.mark_lost") {
		return forbidden("Bạn không có quyền đánh dấu khách Thất bại.")
	}
	if stage != StageLost && !fwd {
		return forbidden("Chỉ Học vụ / Quản lý cơ sở / Admin được chuyển giai đoạn Lead.")
	}
	if stage == StageLost {
		if isClosed(from) {
			return iam.Invalid("Lead đã chốt (đã có hồ sơ học viên) không thể chuyển sang thất bại.")
		}
		if r == "" {
			return iam.Invalid("Vui lòng nhập lý do thất bại.")
		}
		if err := applyStage(ctx, q, l, stage, &a.UserID, r, "set", false); err != nil {
			return err
		}
		// Buổi học thử đang hẹn của khách (crm.trial_bookings, phân hệ placement) hủy theo.
		_, err := q.CancelPendingTrials(ctx, crmdb.CancelPendingTrialsParams{LeadID: l.ID, Reason: "khách chuyển Thất bại"})
		return err
	}
	fromIdx, toIdx := stageIndex(from), stageIndex(stage)
	if fromIdx < 0 || toIdx < 0 {
		return iam.Invalid("Giai đoạn không hợp lệ.")
	}
	if toIdx < fromIdx {
		if !a.Can("lead.stage_back") {
			return forbidden("Chỉ Admin được lùi giai đoạn Lead.")
		}
		if isClosed(from) {
			return iam.Invalid("Lead đã chốt không thể lùi giai đoạn (không hỗ trợ hủy chốt).")
		}
		if r == "" {
			return iam.Invalid("Lùi giai đoạn bắt buộc nhập lý do.")
		}
		return applyStage(ctx, q, l, stage, &a.UserID, r, "keep", false)
	}
	if isClosed(stage) {
		if stage == StageWon && from == StageWaitingClass {
			return iam.Invalid("Hãy gán lớp cho học viên để chuyển sang Đã chốt.")
		}
		return iam.Invalid("Hãy dùng Chốt & Xếp lớp để chốt Lead (tạo hồ sơ học viên, học phí).")
	}
	if toIdx != fromIdx+1 {
		return iam.Invalid("Chỉ được chuyển tiến 1 bước: " + StageLabel(from) + " → " + StageLabel(nextStage(from)) + ".")
	}
	if blocked := forwardBlockReason(stage, l.AppointmentAt, l.TestScore); blocked != "" {
		return iam.Invalid(blocked)
	}
	return applyStage(ctx, q, l, stage, &a.UserID, r, "keep", false)
}

// applyStage đổi giai đoạn và ghi nhật ký stage_change. lostMode: set | clear | keep.
func applyStage(ctx context.Context, q *crmdb.Queries, l crmdb.CrmLead, stage string, actor *uuid.UUID, reason, lostMode string, clearWaiting bool) error {
	var lostReason *string
	if lostMode == "set" {
		lostReason = ptr(cut(reason, 255))
	}
	if err := q.SetLeadStage(ctx, crmdb.SetLeadStageParams{
		ID: l.ID, Stage: stage, LostMode: lostMode, LostReason: lostReason, ClearWaiting: clearWaiting,
	}); err != nil {
		return err
	}
	content := "Chuyển giai đoạn: " + StageLabel(l.Stage) + " → " + StageLabel(stage)
	if reason != "" {
		content += ". Lý do: " + reason
	} else {
		content += "."
	}
	_, err := q.InsertEvent(ctx, crmdb.InsertEventParams{
		LeadID: l.ID, UserID: actor, Type: "stage_change", Content: content, FromStage: &l.Stage, ToStage: &stage,
		Reason: strPtr(reason),
	})
	return err
}

// AdvanceTo: hook tự động cho phân hệ khác (vd placement hẹn test → test_scheduled, chấm xong → tested).
// Chỉ đi tiến; trả false (không làm gì) nếu khách đã ở / đã qua bước đó, đã chốt, thất bại, hoặc nguồn không hợp lệ.
// Gọi trong giao dịch của khách (q là Queries của giao dịch đó).
func (s *Service) AdvanceTo(ctx context.Context, q *crmdb.Queries, leadID uuid.UUID, stage string, actor *uuid.UUID, reason string) (bool, error) {
	if q == nil {
		q = s.q
	}
	l, err := lockLead(ctx, q, leadID)
	if err != nil {
		return false, err
	}
	if !slices.Contains(automaticSources[stage], l.Stage) {
		return false, nil
	}
	return true, applyStage(ctx, q, l, stage, actor, reason, "keep", false)
}

// CancelClosing: hủy chốt khách Chờ xếp lớp (gia đình hủy trước khi có lớp) — chỉ lead.stage_back.
func (s *Service) CancelClosing(ctx context.Context, a *iam.Access, idOrCode, reason string) (*Saved, error) {
	var out *Saved
	err := pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error {
		q := s.q.WithTx(tx)
		l, err := s.lead(ctx, q, a, idOrCode)
		if err != nil {
			return err
		}
		locked, err := lockLead(ctx, q, l.ID)
		if err != nil {
			return err
		}
		if locked.Stage != StageWaitingClass || !a.Can("lead.stage_back") {
			return forbidden("Chỉ Admin được huỷ chốt khách đang Chờ xếp lớp.")
		}
		r := strings.TrimSpace(reason)
		if r == "" {
			return iam.Invalid("Vui lòng nhập lý do huỷ chốt.")
		}
		if runeLen(r) < 5 {
			return iam.Invalid("Lý do huỷ chốt phải có ít nhất 5 ký tự.")
		}
		if runeLen(r) > 1000 {
			return iam.Invalid("Lý do huỷ chốt không được vượt quá 1000 ký tự.")
		}
		if err := applyStage(ctx, q, locked, StageLost, &a.UserID, "Huỷ chốt: "+r, "set", true); err != nil {
			return err
		}
		// Học viên chuyển Thôi học, hủy phiếu thu chưa duyệt, bỏ phần học phí còn nợ và lập hồ sơ hoàn phí chờ duyệt
		// cho phần đã thu (phân hệ học viên / học phí) — Laravel CrmController::cancelClosing.
		var refund int64
		if c, ok := s.students.(ClosingCanceller); ok && locked.ConvertedStudentID != nil {
			if refund, err = c.CancelClosing(ctx, tx, a, *locked.ConvertedStudentID, r); err != nil {
				return err
			}
		}
		msg := "Đã huỷ chốt " + l.Name + ": khách chuyển Thất bại"
		if l.ConvertedStudentID != nil {
			msg += ", học viên chuyển Thôi học"
		}
		msg += "."
		if refund > 0 {
			msg += " Đã lập hồ sơ hoàn " + formatMoney(refund) + " chờ duyệt ở mục Hoàn phí."
		}
		out = &Saved{ID: l.ID, Message: msg}
		return nil
	})
	if err != nil {
		return nil, err
	}
	s.log.Info("crm: huỷ chốt", "lead", out.ID, "by", a.UserID)
	return out, nil
}
