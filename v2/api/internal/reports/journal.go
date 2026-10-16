package reports

import (
	"context"
	"errors"
	"slices"
	"strings"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/reports/reportsdb"
)

// ═════════════ Nhật ký Học vụ (sự vụ) ═════════════

type Followup struct {
	ID        uuid.UUID `json:"id"`
	User      *string   `json:"user"`
	Content   string    `json:"content"`
	CreatedAt time.Time `json:"created_at"`
}

type ClassRef struct {
	ID   uuid.UUID `json:"id"`
	Code string    `json:"code"`
}

type JournalEntry struct {
	ID            uuid.UUID  `json:"id"`
	Title         string     `json:"title"`
	Content       *string    `json:"content"`
	Severity      *string    `json:"severity"`
	SeverityLabel string     `json:"severity_label"`
	KpiCode       *string    `json:"kpi_code"`
	Serious       bool       `json:"serious"`
	SeriousBy     *string    `json:"serious_by"`
	Status        string     `json:"status"`
	ReportDate    string     `json:"report_date"`
	CreatedAt     time.Time  `json:"created_at"`
	Class         *ClassRef  `json:"class"`
	ClassID       *uuid.UUID `json:"class_id"`
	User          string     `json:"user"`
	LoggedBy      *string    `json:"logged_by"`
	UserRole      *string    `json:"user_role"`
	Followups     []Followup `json:"followups"`
}

type JournalPage struct {
	Journals          []JournalEntry `json:"journals"`
	Page              Page           `json:"page"`
	IsPriv            bool           `json:"is_priv"`
	Classes           []Option       `json:"classes"`
	KpiOptions        []Option       `json:"kpi_options"`
	CanConfirmSerious bool           `json:"can_confirm_serious"`
	Subjects          []Option       `json:"subjects"`
	Statuses          []Option       `json:"statuses"`
	Today             string         `json:"today"`
}

type JournalFilter struct {
	Severity string
	Status   string
	ClassID  *uuid.UUID
	Page     int
}

// journalKpiCodes: mã mục KPI Học vụ đang áp dụng (mã → tên) để gắn vào nhật ký.
func (s *Service) journalKpiCodes(ctx context.Context) ([]KpiCriterion, error) {
	all, err := s.metrics.KpiCriteria(ctx, iam.RoleAcademicStaff)
	if err != nil {
		return nil, err
	}
	out := all[:0:0]
	for _, c := range all {
		if c.Code != "" {
			out = append(out, c)
		}
	}
	return out, nil
}

// journalSubjects: Học vụ đang làm trong phạm vi người xem — Admin / Quản lý ghi sự việc vào tên một Học vụ.
func (s *Service) journalSubjects(ctx context.Context, a *iam.Access) ([]reportsdb.ListActiveUsersWithRolesRow, error) {
	return s.q.ListActiveUsersWithRoles(ctx, reportsdb.ListActiveUsersWithRolesParams{
		Roles: []string{iam.RoleAcademicStaff}, ScopeBranches: viewerScope(a), ViewerID: a.UserID})
}

// journalOwner: người không xem tổng hợp chỉ thấy / xử lý sự vụ của mình.
func journalOwner(a *iam.Access) *uuid.UUID {
	if isPrivileged(a) {
		return nil
	}
	return &a.UserID
}

func (s *Service) followups(ctx context.Context, ids []uuid.UUID) (map[uuid.UUID][]Followup, error) {
	out := map[uuid.UUID][]Followup{}
	if len(ids) == 0 {
		return out, nil
	}
	rows, err := s.q.ListFollowups(ctx, ids)
	if err != nil {
		return nil, err
	}
	for _, f := range rows {
		out[f.StaffReportID] = append(out[f.StaffReportID], Followup{f.ID, f.UserName, f.Content, f.CreatedAt})
	}
	return out, nil
}

func (s *Service) Journal(ctx context.Context, a *iam.Access, f JournalFilter) (*JournalPage, error) {
	priv := isPrivileged(a)
	p := reportsdb.ListJournalParams{OnlyUser: journalOwner(a), ViewerID: a.UserID, ClassID: f.ClassID, Lim: 15}
	if priv {
		p.ScopeBranches = viewerScope(a)
	}
	if slices.Contains(journalSeverities, f.Severity) {
		p.Severity = &f.Severity
	}
	if slices.ContainsFunc(journalStatuses, func(o Option) bool { return o.Value == f.Status }) {
		p.Status = &f.Status
	}
	page, off := pageOffset(f.Page, 15)
	p.Off = off
	rows, err := s.q.ListJournal(ctx, p)
	if err != nil {
		return nil, err
	}
	ids := make([]uuid.UUID, 0, len(rows))
	var classIDs []uuid.UUID
	for _, r := range rows {
		ids = append(ids, r.ID)
		if r.ClassID != nil {
			classIDs = append(classIDs, *r.ClassID)
		}
	}
	fu, err := s.followups(ctx, ids)
	if err != nil {
		return nil, err
	}
	codes := map[uuid.UUID]string{}
	if len(classIDs) > 0 {
		if codes, err = s.classes.Codes(ctx, classIDs); err != nil {
			return nil, err
		}
	}
	out := &JournalPage{Journals: []JournalEntry{}, IsPriv: priv, Classes: []Option{}, KpiOptions: []Option{},
		CanConfirmSerious: canReview(a), Subjects: []Option{}, Statuses: journalStatuses, Today: dateKey(s.now())}
	var total int64
	for _, r := range rows {
		total = r.Total
		e := JournalEntry{ID: r.ID, Title: r.Title, Content: r.Content, Severity: r.Severity, SeverityLabel: severityLabel(r.Severity),
			KpiCode: r.KpiCode, Serious: r.SeriousConfirmedAt != nil, SeriousBy: r.SeriousByName, Status: r.Status,
			ReportDate: dateKey(r.ReportDate), CreatedAt: r.CreatedAt, ClassID: r.ClassID, User: r.UserName,
			Followups: fu[r.ID]}
		if e.Followups == nil {
			e.Followups = []Followup{}
		}
		if r.ClassID != nil {
			if c, ok := codes[*r.ClassID]; ok {
				e.Class = &ClassRef{*r.ClassID, c}
			}
		}
		if r.CreatedBy != nil && *r.CreatedBy != r.UserID {
			e.LoggedBy = r.CreatedByName
		}
		if r.UserRole != "" {
			e.UserRole = ptr(r.UserRole)
		}
		out.Journals = append(out.Journals, e)
	}
	out.Page = newPage(page, 15, total)
	opts, err := s.classes.Options(ctx, a)
	if err != nil {
		return nil, err
	}
	if opts != nil {
		out.Classes = opts
	}
	kpis, err := s.journalKpiCodes(ctx)
	if err != nil {
		return nil, err
	}
	for _, c := range kpis {
		out.KpiOptions = append(out.KpiOptions, Option{c.Code, "KPI " + c.Code + " · " + c.Name})
	}
	if priv {
		subs, err := s.journalSubjects(ctx, a)
		if err != nil {
			return nil, err
		}
		for _, u := range subs {
			out.Subjects = append(out.Subjects, Option{u.ID.String(), u.FullName})
		}
	}
	return out, nil
}

// JournalInput: Nhật ký Học vụ chỉ có mã KPI + nội dung; tab "Sự vụ" của Trang lớp gửi thêm tiêu đề / mức độ / lớp.
type JournalInput struct {
	Title         *string    `json:"title"`
	Content       *string    `json:"content"`
	Severity      *string    `json:"severity"`
	KpiCode       *string    `json:"kpi_code"`
	ReportDate    string     `json:"report_date"`
	ClassID       *uuid.UUID `json:"class_id"`
	SubjectUserID *uuid.UUID `json:"subject_user_id"`
}

func (s *Service) AddJournal(ctx context.Context, a *iam.Access, in JournalInput) (uuid.UUID, string, error) {
	title, content := trimPtr(in.Title), trimPtr(in.Content)
	if title == nil && content == nil {
		return uuid.Nil, "", iam.Invalid("Vui lòng nhập nội dung sự việc.")
	}
	if title != nil && runeLen(*title) > 255 {
		return uuid.Nil, "", iam.Invalid("Tiêu đề tối đa 255 ký tự.")
	}
	if content != nil && runeLen(*content) > 20000 {
		return uuid.Nil, "", iam.Invalid("Nội dung tối đa 20000 ký tự.")
	}
	severity := "normal"
	if sv := trimPtr(in.Severity); sv != nil {
		if !slices.Contains(journalSeverities, *sv) {
			return uuid.Nil, "", iam.Invalid("Mức độ không hợp lệ.")
		}
		severity = *sv
	}
	kpi := trimPtr(in.KpiCode)
	if kpi != nil {
		codes, err := s.journalKpiCodes(ctx)
		if err != nil {
			return uuid.Nil, "", err
		}
		if !slices.ContainsFunc(codes, func(c KpiCriterion) bool { return c.Code == *kpi }) {
			return uuid.Nil, "", iam.Invalid("Mã KPI không có trong bộ KPI Học vụ đang áp dụng.")
		}
	}
	date := s.today()
	if in.ReportDate != "" {
		d, ok := parseDate(in.ReportDate)
		if !ok {
			return uuid.Nil, "", iam.Invalid("Ngày ghi nhận không hợp lệ.")
		}
		date = d
	}
	classID, className, err := s.resolveClass(ctx, a, in.ClassID)
	if err != nil {
		return uuid.Nil, "", err
	}
	subject := a.UserID
	if in.SubjectUserID != nil && *in.SubjectUserID != uuid.Nil && *in.SubjectUserID != a.UserID {
		allowed := false
		if isPrivileged(a) {
			subs, err := s.journalSubjects(ctx, a)
			if err != nil {
				return uuid.Nil, "", err
			}
			allowed = slices.ContainsFunc(subs, func(u reportsdb.ListActiveUsersWithRolesRow) bool { return u.ID == *in.SubjectUserID })
		}
		if !allowed {
			return uuid.Nil, "", &iam.Error{Status: 403, Code: "forbidden", Message: "Bạn không ghi nhật ký cho người này được."}
		}
		subject = *in.SubjectUserID
	}
	// Không có tiêu đề riêng → lấy dòng đầu nội dung (danh sách sự vụ hiện tiêu đề).
	t := ""
	if title != nil {
		t = *title
	} else {
		first, _, _ := strings.Cut(*content, "\n")
		t = limitText(squish(first), 120)
	}
	if t == "" {
		t = limitText(squish(*content), 120)
	}
	e, err := s.q.InsertJournal(ctx, reportsdb.InsertJournalParams{UserID: subject, ClassID: classID, Title: t, Content: content,
		Severity: &severity, KpiCode: kpi, ReportDate: date, CreatedBy: &a.UserID})
	if err != nil {
		return uuid.Nil, "", err
	}
	s.log.Info("staff journal added", "by", a.UserID, "id", e.ID, "subject", subject, "severity", severity)
	if severity == "urgent" {
		s.notifyUrgentJournal(ctx, a, e.ID, subject, t, className)
	}
	return e.ID, "Đã thêm nhật ký!", nil
}

// journalEntry: sự vụ người dùng được xử lý — của chính mình, hoặc người xem tổng hợp (trong phạm vi chi nhánh).
func (s *Service) journalEntry(ctx context.Context, a *iam.Access, id uuid.UUID) (reportsdb.OpsStaffReport, error) {
	p := reportsdb.GetJournalEntryParams{ID: id, OnlyUser: journalOwner(a), ViewerID: a.UserID}
	if isPrivileged(a) {
		p.ScopeBranches = viewerScope(a)
	}
	e, err := s.q.GetJournalEntry(ctx, p)
	if errors.Is(err, pgx.ErrNoRows) {
		return e, ErrNotFound
	}
	return e, err
}

func (s *Service) AddFollowup(ctx context.Context, a *iam.Access, id uuid.UUID, content string) (string, error) {
	content = strings.TrimSpace(content)
	if content == "" {
		return "", iam.Invalid("Vui lòng nhập nội dung tác vụ / follow-up.")
	}
	if runeLen(content) > 5000 {
		return "", iam.Invalid("Nội dung follow-up tối đa 5000 ký tự.")
	}
	err := pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error {
		e, err := s.journalEntry(ctx, a, id)
		if err != nil {
			return err
		}
		q := s.q.WithTx(tx)
		if _, err := q.InsertFollowup(ctx, reportsdb.InsertFollowupParams{StaffReportID: e.ID, UserID: &a.UserID, Content: content}); err != nil {
			return err
		}
		if e.Status == "open" {
			return q.SetJournalStatus(ctx, reportsdb.SetJournalStatusParams{ID: e.ID, Status: "following"})
		}
		return nil
	})
	if err != nil {
		return "", err
	}
	s.log.Info("staff journal followup", "by", a.UserID, "id", id)
	return "Đã thêm follow-up cho sự vụ!", nil
}

func (s *Service) SetJournalStatus(ctx context.Context, a *iam.Access, id uuid.UUID, status string) (string, error) {
	if !slices.ContainsFunc(journalStatuses, func(o Option) bool { return o.Value == status }) {
		return "", iam.Invalid("Trạng thái sự vụ không hợp lệ.")
	}
	e, err := s.journalEntry(ctx, a, id)
	if err != nil {
		return "", err
	}
	if err := s.q.SetJournalStatus(ctx, reportsdb.SetJournalStatusParams{ID: e.ID, Status: status}); err != nil {
		return "", err
	}
	s.log.Info("staff journal status", "by", a.UserID, "id", id, "status", status)
	return "Đã cập nhật trạng thái sự vụ!", nil
}

// SetJournalSerious: chỉ Admin bật / tắt "Lỗi nghiêm trọng"; lưu người xác nhận để Học vụ thấy "Xác nhận bởi".
func (s *Service) SetJournalSerious(ctx context.Context, a *iam.Access, id uuid.UUID, serious bool) (string, error) {
	if !canReview(a) {
		return "", ErrAdminOnly
	}
	e, err := s.journalEntry(ctx, a, id)
	if err != nil {
		return "", err
	}
	var by *uuid.UUID
	if serious {
		by = &a.UserID
	}
	desc := "Bỏ xác nhận lỗi nghiêm trọng của sự vụ: " + e.Title
	if serious {
		desc = "Xác nhận lỗi nghiêm trọng của sự vụ: " + e.Title
	}
	err = pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error {
		if err := s.q.WithTx(tx).SetJournalSerious(ctx, reportsdb.SetJournalSeriousParams{ID: e.ID, SeriousConfirmedBy: by}); err != nil {
			return err
		}
		return audit.Record(ctx, tx, audit.Entry{
			ActorID: &a.UserID, Module: audit.ModuleTasks, Event: audit.EventUpdated, Action: "staff_journal.serious",
			SubjectType: "staff_report", SubjectID: e.ID.String(), SubjectLabel: e.Title, Description: desc,
			Changes: audit.Changes{"serious": {Old: e.SeriousConfirmedBy != nil, New: serious}},
		})
	})
	if err != nil {
		return "", err
	}
	s.log.Info("staff journal serious", "by", a.UserID, "id", id, "serious", serious)
	if serious {
		return "Đã xác nhận lỗi nghiêm trọng.", nil
	}
	return "Đã bỏ đánh dấu lỗi nghiêm trọng.", nil
}
