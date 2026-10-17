package syllabus

import (
	"context"
	"fmt"
	"strings"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/notify"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/syllabus/syllabusdb"
)

// Điều chỉnh (giãn) tiến độ: GV xin thêm buổi cho chặng đang mở → Admin duyệt (thêm N buổi theo TKB của lớp, lùi ngày kết
// thúc lớp, cộng buổi giãn vào chặng) hoặc từ chối (SyllabusController::teacherAdjust / adjustmentRequests /
// storeAdjustmentRequest / approve- / rejectAdjustmentRequest, AdjustmentSlaService).

const (
	pacingPageSize   = 15
	MaxExtraSessions = 10
	ruleAdjustment   = "syllabus.adjustment_approval"
)

func pacingLink(id uuid.UUID) string { return "/syllabus/adjustment-requests?request=" + id.String() }

// adjustmentDays: hạn duyệt yêu cầu giãn tiến độ (SLA syllabus.adjustment_approval, mặc định 3 ngày).
func (s *Service) adjustmentDays(ctx context.Context) (int, bool) {
	r := s.rule(ctx, ruleAdjustment)
	return int(r.Value), r.Enabled
}

type PacingRow struct {
	ID              uuid.UUID `json:"id"`
	Teacher         string    `json:"teacher"`
	ClassStageLabel string    `json:"class_stage_label"`
	ExtraSessions   int16     `json:"extra_sessions"`
	Reason          string    `json:"reason"`
	AppliedNote     *string   `json:"applied_note"`
	CreatedAt       time.Time `json:"created_at"`
	Status          string    `json:"status"`
	StatusLabel     string    `json:"status_label"`
	RejectionReason *string   `json:"rejection_reason"`
	SlaOverdue      bool      `json:"sla_overdue"`
}

type PacingDetail struct {
	PacingRow
	TeacherCode  string  `json:"teacher_code"`
	RequestType  string  `json:"request_type"`
	ClassEndDate string  `json:"class_end_date"`
	ReviewedBy   string  `json:"reviewed_by"`
	Requested    *int16  `json:"requested_sessions"`
	ClassDeleted bool    `json:"class_deleted"`
	StageClosed  bool    `json:"stage_closed"`
	StageName    *string `json:"stage_name"`
}

func classStageLabel(className *string, classID uuid.UUID, stage *string) string {
	name := deref(className)
	if name == "" {
		name = "Lớp #" + classID.String()[:8]
	}
	if v := trimmed(stage); v != "" {
		name += " - " + v
	}
	return strings.TrimSpace(name)
}

func (s *Service) slaOverdue(status string, created time.Time, days int) bool {
	return status == "pending" && s.now().After(created.Add(time.Duration(days)*24*time.Hour))
}

func (s *Service) toPacingRow(r syllabusdb.ListPacingRow, days int) PacingRow {
	return PacingRow{
		ID: r.ID, Teacher: deref(r.TeacherName), ClassStageLabel: classStageLabel(r.ClassName, r.ClassID, r.AssignmentStage),
		ExtraSessions: deref(r.ExtraSessions), Reason: r.Reason, AppliedNote: r.AppliedNote, CreatedAt: r.CreatedAt, Status: r.Status,
		StatusLabel: statusLabel(r.Status), RejectionReason: r.RejectionReason, SlaOverdue: s.slaOverdue(r.Status, r.CreatedAt, days),
	}
}

type TeacherAdjustPage struct {
	Requests  []PacingRow `json:"requests"`
	Classes   []Option    `json:"classes"`
	SlaDays   int         `json:"sla_days"`
	CanReview bool        `json:"can_review"`
	MaxExtra  int         `json:"max_extra_sessions"`
	Page
}

// TeacherAdjust: màn "Xin duyệt → Xin điều chỉnh tiến độ": chỉ chọn được lớp đang có chặng mở.
func (s *Service) TeacherAdjust(ctx context.Context, actor *iam.Access, page int) (*TeacherAdjustPage, error) {
	all := actor.Can("syllabus.approve_adjustment")
	lim, off, page := pageOf(page, pacingPageSize)
	rows, err := s.q.ListPacing(ctx, syllabusdb.ListPacingParams{AllRows: all, UserID: actor.UserID, Lim: lim, Off: off})
	if err != nil {
		return nil, err
	}
	total, err := s.q.CountPacing(ctx, syllabusdb.CountPacingParams{AllRows: all, UserID: actor.UserID})
	if err != nil {
		return nil, err
	}
	days, _ := s.adjustmentDays(ctx)
	out := &TeacherAdjustPage{Requests: make([]PacingRow, 0, len(rows)), Classes: []Option{}, SlaDays: days, CanReview: actor.SuperAdmin,
		MaxExtra: MaxExtraSessions, Page: Page{Total: total, Page: page, PageSize: pacingPageSize}}
	for _, r := range rows {
		out.Requests = append(out.Requests, s.toPacingRow(r, days))
	}
	as, err := s.openOfVisible(ctx, actor, true)
	if err != nil {
		return nil, err
	}
	for _, a := range as {
		if a.ClassID != nil {
			out.Classes = append(out.Classes, Option{a.ClassID.String(),
				a.ClassName + " - " + stageOr(a.StagePosition, a.StageTitle, deref(a.StageName)) + " (" + a.ClassCode + ")"})
		}
	}
	return out, nil
}

type AdjustmentRequestsPage struct {
	Requests      []PacingRow   `json:"requests"`
	Selected      *PacingDetail `json:"selected"`
	Status        string        `json:"status"`
	StatusOptions []Option      `json:"status_options"`
	ListTitle     string        `json:"list_title"`
	PendingCount  int64         `json:"pending_count"`
	MaxExtra      int           `json:"max_extra_sessions"`
	CanReview     bool          `json:"can_review"`
	Page
}

// AdjustmentRequests: danh sách yêu cầu giãn tiến độ. Người duyệt xem mọi yêu cầu (mặc định lọc chờ duyệt); người khác
// chỉ xem của mình. Chi tiết mở khi chọn (?request=).
func (s *Service) AdjustmentRequests(ctx context.Context, actor *iam.Access, status string, page int, selected *uuid.UUID) (*AdjustmentRequestsPage, error) {
	all := actor.Can("syllabus.approve_adjustment")
	if status == "" {
		status = "all"
		if all {
			status = "pending"
		}
	}
	st := validStatus(status)
	lim, off, page := pageOf(page, pacingPageSize)
	rows, err := s.q.ListPacing(ctx, syllabusdb.ListPacingParams{AllRows: all, UserID: actor.UserID, Status: st, PendingFirst: true, Lim: lim, Off: off})
	if err != nil {
		return nil, err
	}
	total, err := s.q.CountPacing(ctx, syllabusdb.CountPacingParams{AllRows: all, UserID: actor.UserID, Status: st})
	if err != nil {
		return nil, err
	}
	pending, err := s.q.CountPacing(ctx, syllabusdb.CountPacingParams{AllRows: all, UserID: actor.UserID, Status: ptr("pending")})
	if err != nil {
		return nil, err
	}
	days, _ := s.adjustmentDays(ctx)
	titles := map[string]string{"pending": "Danh sách chờ duyệt", "approved": "Đã duyệt", "rejected": "Đã từ chối"}
	title, okt := titles[status]
	if !okt {
		title = "Tất cả yêu cầu"
	}
	out := &AdjustmentRequestsPage{
		Requests: make([]PacingRow, 0, len(rows)), Status: status, StatusOptions: append([]Option{{"all", "Tất cả"}}, statusOptions()...),
		ListTitle: title, PendingCount: pending, MaxExtra: MaxExtraSessions, CanReview: actor.SuperAdmin,
		Page: Page{Total: total, Page: page, PageSize: pacingPageSize},
	}
	for _, r := range rows {
		out.Requests = append(out.Requests, s.toPacingRow(r, days))
	}
	if selected != nil {
		r, err := s.q.GetPacing(ctx, *selected)
		if isNoRows(err) || (err == nil && !all && r.UserID != actor.UserID) {
			return nil, ErrNotFound
		}
		if err != nil {
			return nil, err
		}
		reviewed := deref(r.ApproverName)
		if r.ReviewedAt != nil {
			reviewed = strings.TrimSpace(reviewed + " lúc " + fmtDateTime(r.ReviewedAt))
		}
		out.Selected = &PacingDetail{
			PacingRow: PacingRow{
				ID: r.ID, Teacher: deref(r.TeacherName), ClassStageLabel: classStageLabel(r.ClassName, r.ClassID, r.AssignmentStage),
				ExtraSessions: deref(r.ExtraSessions), Reason: r.Reason, AppliedNote: r.AppliedNote, CreatedAt: r.CreatedAt, Status: r.Status,
				StatusLabel: statusLabel(r.Status), RejectionReason: r.RejectionReason, SlaOverdue: s.slaOverdue(r.Status, r.CreatedAt, days),
			},
			TeacherCode: r.TeacherCode, RequestType: r.RequestType, ClassEndDate: fmtDate(r.ClassEndDate), ReviewedBy: reviewed,
			Requested: r.ExtraSessions, ClassDeleted: r.ClassDeletedAt != nil, StageClosed: r.AssignmentStatus != nil && *r.AssignmentStatus != "in_progress",
			StageName: r.AssignmentStage,
		}
	}
	return out, nil
}

type PacingInput struct {
	ClassID       *uuid.UUID `json:"class_id"`
	RequestType   *string    `json:"request_type"`
	Reason        string     `json:"reason"`
	ExtraSessions *int       `json:"extra_sessions"`
}

// CreatePacing: GV gửi yêu cầu giãn tiến độ cho chặng đang mở của lớp; báo Admin (hạn duyệt theo SLA).
func (s *Service) CreatePacing(ctx context.Context, actor *iam.Access, in PacingInput) (*Message, error) {
	if in.ClassID == nil {
		return nil, iam.Invalid("Vui lòng chọn lớp.")
	}
	c, err := s.q.ClassInfo(ctx, *in.ClassID)
	if isNoRows(err) {
		return nil, iam.Invalid("Lớp đã chọn không tồn tại.")
	}
	if err != nil {
		return nil, err
	}
	reason := strings.TrimSpace(in.Reason)
	switch {
	case reason == "":
		return nil, iam.Invalid("Vui lòng nhập lý do.")
	case runeLen(reason) > 2000:
		return nil, iam.Invalid("Lý do tối đa 2000 ký tự.")
	case tooLong(in.RequestType, 255):
		return nil, iam.Invalid("Loại điều chỉnh tối đa 255 ký tự.")
	case in.ExtraSessions != nil && (*in.ExtraSessions < 0 || *in.ExtraSessions > MaxExtraSessions):
		return nil, iam.Invalid(fmt.Sprintf("Số buổi cần thêm từ 0 đến %d.", MaxExtraSessions))
	}
	visible, err := s.classVisible(ctx, actor, c.ID)
	if err != nil {
		return nil, err
	}
	if !visible {
		return nil, forbidden("Bạn không phụ trách lớp này.")
	}
	extra := deref(in.ExtraSessions)
	typ := trimmed(in.RequestType)
	if typ == "" {
		typ = "Xin điều chỉnh tiến độ"
		if extra > 0 {
			typ = fmt.Sprintf("Xin giãn tiến độ thêm %d buổi", extra)
		}
	}
	var extraPtr *int16
	if in.ExtraSessions != nil {
		extraPtr = ptr(int16(extra))
	}
	days, _ := s.adjustmentDays(ctx)
	var id uuid.UUID
	err = s.inTx(ctx, func(tx pgx.Tx, q *syllabusdb.Queries) error {
		// Giãn tiến độ luôn gắn chặng đang mở; lớp chưa mở chặng thì không nhận yêu cầu.
		open, err := q.OpenAssignmentOfClass(ctx, &c.ID)
		if isNoRows(err) {
			return iam.Invalid("Lớp " + c.Name + " không có chặng học nào đang mở — không thể xin điều chỉnh tiến độ.")
		}
		if err != nil {
			return err
		}
		row, err := q.InsertPacing(ctx, syllabusdb.InsertPacingParams{
			ClassID: c.ID, AssignmentID: &open.ID, UserID: actor.UserID, RequestType: typ, Reason: reason, ExtraSessions: extraPtr,
		})
		if err != nil {
			return err
		}
		id = row.ID
		admins, err := iam.AdminIDs(ctx, tx)
		if err != nil {
			return err
		}
		teacher, _ := q.UserName(ctx, actor.UserID)
		due := row.CreatedAt.Add(time.Duration(days) * 24 * time.Hour)
		if err := send(ctx, tx, admins, notify.Notification{
			Type: "adjustment_pending", Title: "Yêu cầu giãn tiến độ mới: " + c.Name,
			Body: fmt.Sprintf("%s gửi \"%s\" — hạn duyệt %s (%d ngày).", teacher, typ, fmtDateTime(&due), days),
			Link: pacingLink(id), Data: map[string]any{"request_id": id}, CreatedBy: &actor.UserID, Except: &actor.UserID,
		}); err != nil {
			return err
		}
		return record(ctx, tx, &actor.UserID, audit.EventCreated, "syllabus_pacing.create", "syllabus_pacing", id, c.Name,
			"Gửi yêu cầu điều chỉnh tiến độ lớp "+c.Name+": "+typ, &c.BranchID)
	})
	if err != nil {
		return nil, err
	}
	return okID("Đã gửi yêu cầu điều chỉnh tiến độ giáo trình!", id), nil
}

// ApprovePacing: duyệt giãn tiến độ. Có số buổi cần thêm thì thêm đúng N buổi nối tiếp theo TKB của lớp (bỏ ngày nghỉ,
// chặn trùng lịch) và lùi ngày kết thúc lớp; không thêm được thì không duyệt. Buổi giãn cộng vào đúng chặng của yêu cầu.
func (s *Service) ApprovePacing(ctx context.Context, actor *iam.Access, id uuid.UUID, extra *int) (*Message, error) {
	if err := requireAdmin(actor); err != nil {
		return nil, err
	}
	r, err := s.q.GetPacing(ctx, id)
	if isNoRows(err) {
		return nil, ErrNotFound
	}
	if err != nil {
		return nil, err
	}
	if r.Status != "pending" {
		return nil, errAlreadyHandled
	}
	if extra != nil && (*extra < 0 || *extra > MaxExtraSessions) {
		return nil, iam.Invalid(fmt.Sprintf("Số buổi cần thêm từ 0 đến %d.", MaxExtraSessions))
	}
	count := int(deref(r.ExtraSessions))
	if extra != nil {
		count = *extra
	}
	className := deref(r.ClassName)
	if count > 0 && r.ClassDeletedAt != nil {
		return nil, iam.Invalid("Lớp của yêu cầu đã bị xóa, chỉ có thể từ chối hoặc duyệt với 0 buổi.")
	}
	note := "Không thay đổi lịch học (yêu cầu không kèm số buổi cần thêm)."
	err = s.inTx(ctx, func(tx pgx.Tx, q *syllabusdb.Queries) error {
		target := r.AssignmentID
		var targetName string
		if target != nil {
			a, err := q.GetAssignment(ctx, *target)
			if err != nil && !isNoRows(err) {
				return err
			}
			if err == nil {
				targetName = deref(a.StageName)
				if count > 0 && a.Status != "in_progress" {
					return iam.Invalid(targetName + " đã đóng. Nhập 0 buổi để chỉ ghi nhận, hoặc từ chối yêu cầu.")
				}
			} else {
				target = nil
			}
		} else if a, err := q.OpenAssignmentOfClass(ctx, &r.ClassID); err == nil {
			target, targetName = &a.ID, deref(a.StageName)
		} else if !isNoRows(err) {
			return err
		}
		if count > 0 {
			if s.classes == nil {
				return iam.Invalid("Chưa nối phân hệ Lớp học nên không thể tự thêm buổi.")
			}
			ext, err := s.classes.ExtendSessions(ctx, tx, r.ClassID, count, fmt.Sprintf("Giãn tiến độ theo yêu cầu #%s", r.ID.String()[:8]), &actor.UserID)
			if err != nil {
				return err
			}
			days := make([]string, 0, len(ext.Dates))
			for _, d := range ext.Dates {
				days = append(days, fmtDay(d))
			}
			note = fmt.Sprintf("Đã thêm %d buổi: %s. Ngày kết thúc lớp: %s.", len(ext.Dates), strings.Join(days, ", "), fmtDate(ext.EndDate))
			if target != nil {
				if err := q.AddExtraSessions(ctx, syllabusdb.AddExtraSessionsParams{ID: *target, N: int32(len(ext.Dates))}); err != nil {
					return err
				}
				note += fmt.Sprintf(" %s: +%d buổi giãn tiến độ.", targetName, len(ext.Dates))
			}
		}
		rows, err := q.ApprovePacing(ctx, syllabusdb.ApprovePacingParams{
			ID: id, AssignmentID: target, ApproverID: &actor.UserID, ExtraSessions: ptr(int16(count)), AppliedNote: &note,
		})
		if err != nil {
			return err
		}
		if rows == 0 {
			return errAlreadyHandled
		}
		if err := notifyReview(ctx, tx, &r.UserID, "Yêu cầu giãn tiến độ đã được duyệt", "Lớp "+className+": "+note, pacingLink(id), actor.UserID); err != nil {
			return err
		}
		return record(ctx, tx, &actor.UserID, audit.EventUpdated, "syllabus_pacing.approve", "syllabus_pacing", id, className,
			"Duyệt yêu cầu điều chỉnh tiến độ lớp "+className+". "+note, nil)
	})
	if err != nil {
		return nil, err
	}
	return okID("Đã phê duyệt yêu cầu điều chỉnh tiến độ cho lớp "+className+". "+note, id), nil
}

func (s *Service) RejectPacing(ctx context.Context, actor *iam.Access, id uuid.UUID, reason string) (*Message, error) {
	if err := requireAdmin(actor); err != nil {
		return nil, err
	}
	r, err := s.q.GetPacing(ctx, id)
	if isNoRows(err) {
		return nil, ErrNotFound
	}
	if err != nil {
		return nil, err
	}
	reason = strings.TrimSpace(reason)
	if reason == "" {
		return nil, iam.Invalid("Vui lòng nhập lý do từ chối.")
	}
	if runeLen(reason) > 2000 {
		return nil, iam.Invalid("Lý do tối đa 2000 ký tự.")
	}
	if r.Status != "pending" {
		return nil, errAlreadyHandled
	}
	className := deref(r.ClassName)
	err = s.inTx(ctx, func(tx pgx.Tx, q *syllabusdb.Queries) error {
		rows, err := q.RejectPacing(ctx, syllabusdb.RejectPacingParams{ID: id, ApproverID: &actor.UserID, RejectionReason: &reason})
		if err != nil {
			return err
		}
		if rows == 0 {
			return errAlreadyHandled
		}
		if err := notifyReview(ctx, tx, &r.UserID, "Yêu cầu giãn tiến độ bị từ chối", "Lớp "+className+" — Lý do: "+reason, pacingLink(id), actor.UserID); err != nil {
			return err
		}
		return record(ctx, tx, &actor.UserID, audit.EventUpdated, "syllabus_pacing.reject", "syllabus_pacing", id, className,
			"Từ chối yêu cầu điều chỉnh tiến độ lớp "+className+": "+reason, nil)
	})
	if err != nil {
		return nil, err
	}
	return okID("Đã từ chối yêu cầu điều chỉnh tiến độ cho lớp "+className+"!", id), nil
}

// notifyPacingBreaches: yêu cầu còn chờ duyệt quá SLA → báo Admin đúng 1 lần (AdjustmentSlaService::notifyBreaches).
func (s *Service) notifyPacingBreaches(ctx context.Context) (int, error) {
	days, enabled := s.adjustmentDays(ctx)
	if !enabled {
		return 0, nil
	}
	cutoff := s.now().Add(-time.Duration(days) * 24 * time.Hour)
	rows, err := s.q.PacingBreaches(ctx, cutoff)
	if err != nil || len(rows) == 0 {
		return 0, err
	}
	err = s.inTx(ctx, func(tx pgx.Tx, q *syllabusdb.Queries) error {
		admins, err := iam.AdminIDs(ctx, tx)
		if err != nil {
			return err
		}
		for _, r := range rows {
			teacher := deref(r.TeacherName)
			if teacher == "" {
				teacher = "Giáo viên"
			}
			if err := send(ctx, tx, admins, notify.Notification{
				Type: "adjustment_sla", Title: "Quá hạn duyệt giãn tiến độ: " + r.ClassName,
				Body: fmt.Sprintf("%s gửi \"%s\" ngày %s — đã quá %d ngày chưa được duyệt.", teacher, r.RequestType, fmtDay(r.CreatedAt.In(vnZone)), days),
				Link: pacingLink(r.ID), Data: map[string]any{"request_id": r.ID},
			}); err != nil {
				return err
			}
			if err := q.MarkPacingNotified(ctx, r.ID); err != nil {
				return err
			}
		}
		return nil
	})
	return len(rows), err
}
