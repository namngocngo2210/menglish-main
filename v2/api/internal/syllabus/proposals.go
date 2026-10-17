package syllabus

import (
	"context"
	"fmt"
	"strings"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/files"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/notify"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/filestore"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/syllabus/syllabusdb"
)

// Đề xuất sửa giáo trình: GV gửi → Admin duyệt / từ chối (SyllabusController::versions / teacherPropose / storeProposal /
// approveProposal / rejectProposal / proposalAttachment). Người có syllabus.approve_adjustment xem mọi đề xuất, người
// khác chỉ xem đề xuất của mình; nút duyệt chỉ Admin.

const (
	proposalsPageSize     = 15
	teacherProposePerPage = 10
)

func proposalLink(id uuid.UUID) string { return "/syllabus/versions?proposal=" + id.String() }

type ProposalRow struct {
	ID          uuid.UUID `json:"id"`
	Curriculum  string    `json:"curriculum"`
	TargetLabel string    `json:"target_label"`
	Target      string    `json:"target"`
	Proposer    string    `json:"proposer"`
	NewContent  string    `json:"new_content"`
	CreatedAt   time.Time `json:"created_at"`
	Status      string    `json:"status"`
	StatusLabel string    `json:"status_label"`
	StatusColor string    `json:"status_color"`
	ReviewNote  *string   `json:"review_note"`
}

type ProposalDetail struct {
	ID                uuid.UUID  `json:"id"`
	CurriculumID      uuid.UUID  `json:"curriculum_id"`
	Curriculum        string     `json:"curriculum"`
	CurriculumVersion string     `json:"curriculum_version"`
	TargetLabel       string     `json:"target_label"`
	Proposer          string     `json:"proposer"`
	ProposerRoles     string     `json:"proposer_roles"`
	ProposalType      *string    `json:"proposal_type"`
	OldContent        *string    `json:"old_content"`
	NewContent        string     `json:"new_content"`
	Reason            *string    `json:"reason"`
	AttachmentName    *string    `json:"attachment_name"`
	AttachmentURL     *string    `json:"attachment_url"`
	Status            string     `json:"status"`
	StatusLabel       string     `json:"status_label"`
	StatusColor       string     `json:"status_color"`
	Reviewer          *string    `json:"reviewer"`
	ReviewNote        *string    `json:"review_note"`
	CreatedAt         time.Time  `json:"created_at"`
	ReviewedAt        *time.Time `json:"reviewed_at"`
	// Sửa buổi học được đề xuất ở màn soạn syllabus
	EditLessonID *uuid.UUID `json:"edit_lesson_id"`
}

type VersionsPage struct {
	Proposals     []ProposalRow   `json:"proposals"`
	Selected      *ProposalDetail `json:"selected"`
	PendingCount  int64           `json:"pending_count"`
	StatusOptions []Option        `json:"status_options"`
	CanReview     bool            `json:"can_review"`
	CanManage     bool            `json:"can_manage"`
	Page
}

func statusOptions() []Option {
	return []Option{{"pending", "Chờ duyệt"}, {"approved", "Đã duyệt"}, {"rejected", "Đã từ chối"}}
}

func validStatus(s string) *string {
	if _, okc := statusLabels[s]; okc {
		return &s
	}
	return nil
}

func toProposalRow(p syllabusdb.ListProposalsRow) ProposalRow {
	target := "Chung"
	switch {
	case p.SessionNo != nil:
		target = fmt.Sprintf("Buổi %d", *p.SessionNo)
	case p.UnitNumber != nil:
		target = fmt.Sprintf("Unit %d", *p.UnitNumber)
	}
	return ProposalRow{
		ID: p.ID, Curriculum: p.CurriculumTitle, TargetLabel: proposalTarget(p.SessionNo, p.LessonTitle, p.UnitNumber, p.UnitTitle),
		Target: target, Proposer: deref(p.ProposerName), NewContent: p.NewContent, CreatedAt: p.CreatedAt, Status: p.Status,
		StatusLabel: statusLabel(p.Status), StatusColor: statusColor(p.Status), ReviewNote: p.ReviewNote,
	}
}

func roleLabels(roles []string) string {
	c := iam.GetCatalog()
	out := make([]string, 0, len(roles))
	for _, r := range roles {
		label := r
		for _, d := range c.Roles {
			if d.Name == r {
				label = d.Label
				break
			}
		}
		out = append(out, label)
	}
	return strings.Join(out, ", ")
}

// Versions: màn "Duyệt đề xuất sửa giáo trình" / chi tiết đề xuất (?proposal=).
func (s *Service) Versions(ctx context.Context, actor *iam.Access, status string, page int, selected *uuid.UUID) (*VersionsPage, error) {
	all := actor.Can("syllabus.approve_adjustment")
	st := validStatus(status)
	lim, off, page := pageOf(page, proposalsPageSize)
	rows, err := s.q.ListProposals(ctx, syllabusdb.ListProposalsParams{AllRows: all, UserID: actor.UserID, Status: st, PendingFirst: true, Lim: lim, Off: off})
	if err != nil {
		return nil, err
	}
	total, err := s.q.CountProposals(ctx, syllabusdb.CountProposalsParams{AllRows: all, UserID: actor.UserID, Status: st})
	if err != nil {
		return nil, err
	}
	pending, err := s.q.CountProposals(ctx, syllabusdb.CountProposalsParams{AllRows: all, UserID: actor.UserID, Status: ptr("pending")})
	if err != nil {
		return nil, err
	}
	out := &VersionsPage{Proposals: make([]ProposalRow, 0, len(rows)), PendingCount: pending, StatusOptions: statusOptions(),
		CanReview: actor.SuperAdmin, CanManage: actor.Can("syllabus.manage"), Page: Page{Total: total, Page: page, PageSize: proposalsPageSize}}
	for _, r := range rows {
		out.Proposals = append(out.Proposals, toProposalRow(r))
	}
	if selected != nil {
		p, err := s.q.GetProposal(ctx, *selected)
		if isNoRows(err) || (err == nil && !all && p.UserID != actor.UserID) {
			return nil, ErrNotFound
		}
		if err != nil {
			return nil, err
		}
		d := &ProposalDetail{
			ID: p.ID, CurriculumID: p.CurriculumID, Curriculum: p.CurriculumTitle, CurriculumVersion: p.CurriculumVersion,
			TargetLabel: proposalTarget(p.SessionNo, p.LessonTitle, p.UnitNumber, p.UnitTitle), Proposer: deref(p.ProposerName),
			ProposerRoles: roleLabels(p.ProposerRoles), ProposalType: p.ProposalType, OldContent: p.OldContent, NewContent: p.NewContent,
			Reason: p.Reason, Status: p.Status, StatusLabel: statusLabel(p.Status), StatusColor: statusColor(p.Status),
			Reviewer: p.ReviewerName, ReviewNote: p.ReviewNote, CreatedAt: p.CreatedAt, ReviewedAt: p.ReviewedAt,
		}
		if p.AttachmentFileID != nil && p.AttachmentName != nil {
			d.AttachmentName, d.AttachmentURL = p.AttachmentName, ptr(files.URL(*p.AttachmentFileID))
		}
		if p.LessonID != nil && p.LessonDeletedAt == nil {
			d.EditLessonID = p.LessonID
		}
		out.Selected = d
	}
	return out, nil
}

type ProposeCurriculum struct {
	ID      uuid.UUID `json:"id"`
	Label   string    `json:"label"`
	Lessons []Option  `json:"lessons"`
}

type TeacherProposePage struct {
	Proposals     []ProposalRow       `json:"proposals"`
	Curriculums   []ProposeCurriculum `json:"curriculums"`
	StatusOptions []Option            `json:"status_options"`
	CanPropose    bool                `json:"can_propose"`
	Page
}

// TeacherPropose: màn "Xin duyệt → Đề xuất sửa giáo trình" — đề xuất của chính mình + form gửi.
func (s *Service) TeacherPropose(ctx context.Context, actor *iam.Access, status string, page int) (*TeacherProposePage, error) {
	st := validStatus(status)
	lim, off, page := pageOf(page, teacherProposePerPage)
	rows, err := s.q.ListProposals(ctx, syllabusdb.ListProposalsParams{UserID: actor.UserID, Status: st, Lim: lim, Off: off})
	if err != nil {
		return nil, err
	}
	total, err := s.q.CountProposals(ctx, syllabusdb.CountProposalsParams{UserID: actor.UserID, Status: st})
	if err != nil {
		return nil, err
	}
	out := &TeacherProposePage{Proposals: make([]ProposalRow, 0, len(rows)), Curriculums: []ProposeCurriculum{}, StatusOptions: statusOptions(),
		CanPropose: actor.Can("syllabus.propose_adjustment"), Page: Page{Total: total, Page: page, PageSize: teacherProposePerPage}}
	for _, r := range rows {
		out.Proposals = append(out.Proposals, toProposalRow(r))
	}
	cs, err := s.q.ListCurricula(ctx)
	if err != nil {
		return nil, err
	}
	ids := make([]uuid.UUID, 0, len(cs))
	for _, c := range cs {
		ids = append(ids, c.ID)
	}
	lessons, err := s.q.ListLessons(ctx, ids)
	if err != nil {
		return nil, err
	}
	by := map[uuid.UUID][]Option{}
	for _, l := range lessons {
		by[l.CurriculumID] = append(by[l.CurriculumID], Option{l.ID.String(), fmt.Sprintf("Buổi %d: %s (Unit %d)", l.SessionNo, l.Title, l.UnitNumber)})
	}
	for _, c := range cs {
		ls := by[c.ID]
		if ls == nil {
			ls = []Option{}
		}
		out.Curriculums = append(out.Curriculums, ProposeCurriculum{ID: c.ID, Label: c.Title + " (" + c.Version + ")", Lessons: ls})
	}
	return out, nil
}

type ProposalInput struct {
	CurriculumID string
	UnitID       string
	LessonID     string
	ProposalType string
	OldContent   string
	NewContent   string
	Reason       string
}

func optUUID(s string) (*uuid.UUID, bool) {
	s = strings.TrimSpace(s)
	if s == "" {
		return nil, true
	}
	id, err := uuid.Parse(s)
	return &id, err == nil
}

// CreateProposal: gửi đề xuất sửa giáo trình (kèm file đính kèm tùy chọn); báo riêng người duyệt.
func (s *Service) CreateProposal(ctx context.Context, actor *iam.Access, in ProposalInput, up *filestore.Upload) (*Message, error) {
	curID, okc := optUUID(in.CurriculumID)
	if !okc || curID == nil {
		return nil, iam.Invalid("Vui lòng chọn giáo trình.")
	}
	cur, err := s.q.GetCurriculum(ctx, *curID)
	if isNoRows(err) {
		return nil, iam.Invalid("Giáo trình đã chọn không tồn tại.")
	}
	if err != nil {
		return nil, err
	}
	unitID, okc := optUUID(in.UnitID)
	if !okc {
		return nil, iam.Invalid("Unit không thuộc giáo trình đã chọn.")
	}
	lessonID, okc := optUUID(in.LessonID)
	if !okc {
		return nil, iam.Invalid("Buổi học không thuộc giáo trình đã chọn.")
	}
	if unitID != nil {
		in, err := s.q.UnitInCurriculum(ctx, syllabusdb.UnitInCurriculumParams{ID: *unitID, CurriculumID: *curID})
		if err != nil {
			return nil, err
		}
		if !in {
			return nil, iam.Invalid("Unit không thuộc giáo trình đã chọn.")
		}
	}
	if lessonID != nil {
		in, err := s.q.LessonInCurriculum(ctx, syllabusdb.LessonInCurriculumParams{ID: *lessonID, CurriculumID: *curID})
		if err != nil {
			return nil, err
		}
		if !in {
			return nil, iam.Invalid("Buổi học không thuộc giáo trình đã chọn.")
		}
		// Chọn buổi học → gắn luôn Unit của buổi đó.
		u, err := s.q.UnitOfLesson(ctx, *lessonID)
		if err != nil {
			return nil, err
		}
		unitID = &u
	}
	typ, old, newC, reason := optText(&in.ProposalType), optText(&in.OldContent), strings.TrimSpace(in.NewContent), optText(&in.Reason)
	switch {
	case newC == "":
		return nil, iam.Invalid("Vui lòng nhập mô tả thay đổi đề xuất.")
	case runeLen(newC) > 5000:
		return nil, iam.Invalid("Mô tả thay đổi tối đa 5000 ký tự.")
	case tooLong(typ, 255):
		return nil, iam.Invalid("Loại đề xuất tối đa 255 ký tự.")
	case tooLong(old, 5000):
		return nil, iam.Invalid("Nội dung hiện tại tối đa 5000 ký tự.")
	case tooLong(reason, 2000):
		return nil, iam.Invalid("Lý do tối đa 2000 ký tự.")
	}
	if up != nil {
		if err := up.Check(ProposalPolicy); err != nil {
			return nil, iam.Invalid(err.Error())
		}
		if s.files == nil {
			return nil, &iam.Error{Status: 503, Code: "files_unavailable", Message: "Kho file chưa được cấu hình."}
		}
	}
	id := uuid.New()
	var target string
	err = s.inTx(ctx, func(tx pgx.Tx, q *syllabusdb.Queries) error {
		var fileID *uuid.UUID
		if up != nil {
			f, err := s.files.Save(ctx, tx, up, files.SaveInput{
				Owner: files.Owner{Module: "syllabus", SubjectType: subjectProposal, SubjectID: &id}, By: &actor.UserID,
				Audit: audit.Entry{Module: audit.ModuleSyllabus, SubjectType: subjectProposal, SubjectID: id.String(),
					Description: "Tải file đính kèm đề xuất sửa giáo trình " + cur.Title},
			})
			if err != nil {
				return err
			}
			fileID = &f.ID
		}
		if _, err := q.InsertProposal(ctx, syllabusdb.InsertProposalParams{
			ID: id, CurriculumID: *curID, UnitID: unitID, LessonID: lessonID, UserID: actor.UserID, ProposalType: typ,
			OldContent: old, NewContent: newC, Reason: reason, AttachmentFileID: fileID,
		}); err != nil {
			return err
		}
		p, err := q.GetProposal(ctx, id)
		if err != nil {
			return err
		}
		target = proposalTarget(p.SessionNo, p.LessonTitle, p.UnitNumber, p.UnitTitle)
		approvers, err := iam.UserIDsWithPermission(ctx, tx, "syllabus.approve_adjustment", nil)
		if err != nil {
			return err
		}
		// Báo riêng người duyệt đề xuất (Học thuật, Admin), không phát thông báo chung.
		if err := send(ctx, tx, approvers, notify.Notification{
			Type: "syllabus_proposal", Title: "Đề xuất sửa giáo trình mới",
			Body: deref(p.ProposerName) + " đề xuất sửa giáo trình " + cur.Title + " — " + target + ".",
			Link: proposalLink(id), Data: map[string]any{"proposal_id": id}, CreatedBy: &actor.UserID, Except: &actor.UserID,
		}); err != nil {
			return err
		}
		return record(ctx, tx, &actor.UserID, audit.EventCreated, "syllabus_proposal.create", "syllabus_proposal", id,
			cur.Title+" — "+target, "Gửi đề xuất sửa giáo trình "+cur.Title+" — "+target, nil)
	})
	if err != nil {
		return nil, err
	}
	return okID("Đã gửi đề xuất sửa giáo trình tới Ban Học thuật.", id), nil
}

// ReviewProposal: Admin duyệt (ghi chú tùy chọn) / từ chối (bắt buộc lý do) đề xuất.
func (s *Service) ReviewProposal(ctx context.Context, actor *iam.Access, id uuid.UUID, approve bool, note string) (*Message, error) {
	if err := requireAdmin(actor); err != nil {
		return nil, err
	}
	p, err := s.q.GetProposal(ctx, id)
	if isNoRows(err) {
		return nil, ErrNotFound
	}
	if err != nil {
		return nil, err
	}
	n := optText(&note)
	if !approve && n == nil {
		return nil, iam.Invalid("Vui lòng nhập lý do từ chối.")
	}
	if tooLong(n, 2000) {
		return nil, iam.Invalid("Ghi chú tối đa 2000 ký tự.")
	}
	if p.Status != "pending" {
		return nil, errAlreadyHandled
	}
	status, msg := "approved", "Đã phê duyệt đề xuất sửa giáo trình."
	title, body := "Đề xuất sửa giáo trình đã được duyệt", "Đề xuất sửa "+p.CurriculumTitle+" đã được Học thuật phê duyệt."
	if !approve {
		status, msg = "rejected", "Đã từ chối đề xuất sửa giáo trình."
		title, body = "Đề xuất sửa giáo trình bị từ chối", "Lý do: "+deref(n)
	}
	err = s.inTx(ctx, func(tx pgx.Tx, q *syllabusdb.Queries) error {
		rows, err := q.ReviewProposal(ctx, syllabusdb.ReviewProposalParams{ID: id, Status: status, ReviewerID: &actor.UserID, ReviewNote: n})
		if err != nil {
			return err
		}
		if rows == 0 {
			return errAlreadyHandled
		}
		if err := notifyReview(ctx, tx, &p.UserID, title, body, proposalLink(id), actor.UserID); err != nil {
			return err
		}
		return record(ctx, tx, &actor.UserID, audit.EventUpdated, "syllabus_proposal."+map[bool]string{true: "approve", false: "reject"}[approve],
			"syllabus_proposal", id, p.CurriculumTitle, statusLabel(status)+" đề xuất sửa giáo trình "+p.CurriculumTitle, nil)
	})
	if err != nil {
		return nil, err
	}
	return okID(msg, id), nil
}
