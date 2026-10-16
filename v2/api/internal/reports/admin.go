package reports

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"slices"
	"sort"
	"strings"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/reports/reportsdb"
)

// ═════════════ Tổng hợp báo cáo & nhật ký (Admin / Quản lý) ═════════════

func guardViewAll(a *iam.Access) error {
	if !isPrivileged(a) {
		return ErrViewAllDenied
	}
	return nil
}

// AllFilter: bộ lọc màn Tổng hợp. View = list | missing.
type AllFilter struct {
	View     string
	Type     string
	Review   string
	UserID   *uuid.UUID
	BranchID *uuid.UUID
	From     string
	To       string
	Page     int
	Open     *uuid.UUID
}

type AllRow struct {
	ID             uuid.UUID  `json:"id"`
	Type           string     `json:"type"`
	TypeLabel      string     `json:"type_label"`
	For            *string    `json:"for"`
	SeverityLabel  *string    `json:"severity_label"`
	Title          string     `json:"title"`
	Preview        *string    `json:"preview"`
	ReportDate     string     `json:"report_date"`
	SubmittedAt    time.Time  `json:"submitted_at"`
	UpdatedAt      *time.Time `json:"updated_at"`
	Late           bool       `json:"late"`
	User           string     `json:"user"`
	Branch         *string    `json:"branch"`
	FollowupsCount int64      `json:"followups_count"`
	Reviewable     bool       `json:"reviewable"`
	Status         string     `json:"status"`
	ReviewNote     *string    `json:"review_note"`
	ReviewedAt     *time.Time `json:"reviewed_at"`
	ReviewedBy     *string    `json:"reviewed_by"`
}

type AllStats struct {
	Journal          int64 `json:"journal"`
	Daily            int64 `json:"daily"`
	Weekly           int64 `json:"weekly"`
	Monthly          int64 `json:"monthly"`
	UrgentOpen       int64 `json:"urgent_open"`
	PendingReview    int64 `json:"pending_review"`
	Returned         int64 `json:"returned"`
	MissingYesterday int   `json:"missing_yesterday"`
}

type MissingRow struct {
	UserID uuid.UUID `json:"user_id"`
	User   string    `json:"user"`
	Branch *string   `json:"branch"`
	For    string    `json:"for"`
	Days   []DayLink `json:"days"`
}

type Missing struct {
	From string       `json:"from"`
	To   string       `json:"to"`
	Rows []MissingRow `json:"rows"`
}

type AllFilters struct {
	Type     *string    `json:"type"`
	Review   *string    `json:"review"`
	UserID   *uuid.UUID `json:"user_id"`
	BranchID *uuid.UUID `json:"branch_id"`
	From     *string    `json:"from"`
	To       *string    `json:"to"`
}

type AllPage struct {
	View      string     `json:"view"`
	Reports   []AllRow   `json:"reports"`
	Page      Page       `json:"page"`
	Missing   *Missing   `json:"missing"`
	Stats     AllStats   `json:"stats"`
	Filters   AllFilters `json:"filters"`
	CanReview bool       `json:"can_review"`
	Types     []Option   `json:"types"`
	Users     []Option   `json:"users"`
	Branches  []Option   `json:"branches"`
	OpenID    *uuid.UUID `json:"open_id"`
}

// kindLabel / kindFor: nhãn loại + "dành cho" của báo cáo (báo cáo ngày ghi theo chức danh người nộp).
func kindLabel(typ string, kind *string) string {
	if kind != nil {
		if k, ok := structured[*kind]; ok {
			return k.Label
		}
	}
	return typeLabel(typ)
}

func kindFor(kind *string, roles []string) *string {
	if kind == nil {
		return nil
	}
	if *kind == KindDailyOps {
		return ptr(dailyAudience(roles))
	}
	if k, ok := structured[*kind]; ok {
		return ptr(k.For)
	}
	return nil
}

// preview: ghi chú người viết (báo cáo ngày / tuần KPI), không có thì nội dung chữ; gọn còn 280 ký tự.
func preview(kind *string, data []byte, content *string) *string {
	var d struct {
		Notes map[string]string `json:"notes"`
		Note  *string           `json:"note"`
		Auto  json.RawMessage   `json:"auto"`
	}
	_ = json.Unmarshal(data, &d)
	var parts []string
	keys := make([]string, 0, len(d.Notes))
	for k := range d.Notes {
		keys = append(keys, k)
	}
	// Thứ tự khối như màn nhập; khóa lạ xếp sau theo chữ cái.
	sort.SliceStable(keys, func(i, j int) bool {
		ii, jj := slices.Index(dailyNoteOrder, keys[i]), slices.Index(dailyNoteOrder, keys[j])
		if ii < 0 {
			ii = len(dailyNoteOrder)
		}
		if jj < 0 {
			jj = len(dailyNoteOrder)
		}
		if ii != jj {
			return ii < jj
		}
		return keys[i] < keys[j]
	})
	for _, k := range keys {
		if t := strings.TrimSpace(d.Notes[k]); t != "" {
			parts = append(parts, ucfirst(strings.TrimPrefix(noteLabel(k), "Ghi chú "))+": "+t)
		}
	}
	if d.Note != nil && strings.TrimSpace(*d.Note) != "" {
		parts = append(parts, "Tuần: "+*d.Note)
	}
	var text *string
	switch {
	case len(parts) > 0:
		text = ptr(strings.Join(parts, " · "))
	case kind != nil && *kind == KindDailyOps && len(d.Auto) > 0 && string(d.Auto) != "null":
		text = ptr("Không có ghi chú (chỉ số liệu hệ thống).")
	default:
		text = content
	}
	if text == nil {
		return nil
	}
	return ptr(limitText(squish(*text), 280))
}

// filterDate: "Y-m-d" hợp lệ, không thì nil.
func filterDate(v string) *time.Time {
	d, ok := parseDate(v)
	if !ok {
		return nil
	}
	return &d
}

func (s *Service) All(ctx context.Context, a *iam.Access, f AllFilter) (*AllPage, error) {
	if err := guardViewAll(a); err != nil {
		return nil, err
	}
	scope := viewerScope(a)
	out := &AllPage{View: "list", Reports: []AllRow{}, CanReview: canReview(a), Types: typeLabels,
		Users: []Option{}, Branches: []Option{}}
	if f.View == "missing" {
		out.View = "missing"
	}
	from, to := filterDate(f.From), filterDate(f.To)
	p := reportsdb.ListAllParams{ScopeBranches: scope, ViewerID: a.UserID, UserID: f.UserID, BranchID: f.BranchID,
		FromDate: from, ToDate: to, Lim: 20}
	if slices.ContainsFunc(typeLabels, func(o Option) bool { return o.Value == f.Type }) {
		p.Type = &f.Type
		out.Filters.Type = &f.Type
	}
	if slices.Contains([]string{"pending", "done", "returned"}, f.Review) {
		p.Review = &f.Review
		out.Filters.Review = &f.Review
		p.OrderUpdated = f.Review == "pending"
	}
	out.Filters.UserID, out.Filters.BranchID = f.UserID, f.BranchID
	if from != nil {
		out.Filters.From = ptr(dateKey(*from))
	}
	if to != nil {
		out.Filters.To = ptr(dateKey(*to))
	}
	page, off := pageOffset(f.Page, 20)
	p.Off = off
	rows, err := s.q.ListAll(ctx, p)
	if err != nil {
		return nil, err
	}
	var total int64
	for _, r := range rows {
		total = r.Total
		row := AllRow{ID: r.ID, Type: r.Type, TypeLabel: kindLabel(r.Type, r.Kind), For: kindFor(r.Kind, r.UserRoles),
			Title: r.Title, Preview: preview(r.Kind, r.Data, r.Content), ReportDate: dateKey(r.ReportDate),
			SubmittedAt: r.CreatedAt, Late: isLate(r.Type, r.SubmittedAt, r.CreatedAt, r.ReportDate), User: r.UserName,
			FollowupsCount: r.FollowupsCount, Reviewable: r.Type != "journal", Status: r.Status, ReviewNote: r.ReviewNote,
			ReviewedAt: r.ReviewedAt, ReviewedBy: r.ReviewerName}
		if r.SubmittedAt != nil {
			row.SubmittedAt = *r.SubmittedAt
		}
		if r.UpdatedAt.Sub(row.SubmittedAt) >= time.Minute {
			row.UpdatedAt = ptr(r.UpdatedAt)
		}
		if r.Type == "journal" {
			row.SeverityLabel = ptr(severityLabel(r.Severity))
		}
		if r.BranchName != "" {
			row.Branch = ptr(r.BranchName)
		}
		out.Reports = append(out.Reports, row)
	}
	out.Page = newPage(page, 20, total)

	st, err := s.q.AllStats(ctx, reportsdb.AllStatsParams{ScopeBranches: scope, ViewerID: a.UserID})
	if err != nil {
		return nil, err
	}
	out.Stats = AllStats{Journal: st.Journal, Daily: st.Daily, Weekly: st.Weekly, Monthly: st.Monthly,
		UrgentOpen: st.UrgentOpen, PendingReview: st.PendingReview, Returned: st.Returned}
	writers, err := s.writers(ctx, scope, a.UserID)
	if err != nil {
		return nil, err
	}
	yesterday := s.today().AddDate(0, 0, -1)
	for _, w := range writers {
		days, err := s.missingDays(ctx, rowWriter(w), yesterday, yesterday)
		if err != nil {
			return nil, err
		}
		if len(days) > 0 {
			out.Stats.MissingYesterday++
		}
	}
	if out.View == "missing" {
		mf, mt := s.today().AddDate(0, 0, -7), yesterday
		if from != nil {
			mf = *from
		}
		if to != nil {
			mt = *to
		}
		if out.Missing, err = s.missingReports(ctx, writers, mf, mt, f.UserID, f.BranchID); err != nil {
			return nil, err
		}
	}

	users, err := s.q.AllWriterOptions(ctx, reportsdb.AllWriterOptionsParams{ScopeBranches: scope, ViewerID: a.UserID})
	if err != nil {
		return nil, err
	}
	for _, u := range users {
		out.Users = append(out.Users, Option{u.ID.String(), u.FullName})
	}
	branches, err := s.q.BranchOptions(ctx, scope)
	if err != nil {
		return nil, err
	}
	for _, b := range branches {
		out.Branches = append(out.Branches, Option{b.ID.String(), b.Name})
	}
	if f.Open != nil {
		if _, err := s.q.GetScopedReport(ctx, reportsdb.GetScopedReportParams{ID: *f.Open, ScopeBranches: scope, ViewerID: a.UserID}); err == nil {
			out.OpenID = f.Open
		} else if !errors.Is(err, pgx.ErrNoRows) {
			return nil, err
		}
	}
	return out, nil
}

// missingReports: tab "Chưa nộp" — người phải nộp báo cáo ngày còn thiếu ngày nào trong khoảng, thiếu nhiều xếp trước.
func (s *Service) missingReports(ctx context.Context, writers []reportsdb.ListActiveUsersWithRolesRow, from, to time.Time,
	userID, branchID *uuid.UUID) (*Missing, error) {
	out := &Missing{From: dateKey(from), To: dateKey(to), Rows: []MissingRow{}}
	for _, w := range writers {
		if userID != nil && w.ID != *userID {
			continue
		}
		if branchID != nil && !slices.Contains(w.BranchIds, *branchID) {
			continue
		}
		days, err := s.missingDays(ctx, rowWriter(w), from, to)
		if err != nil {
			return nil, err
		}
		if len(days) == 0 {
			continue
		}
		row := MissingRow{UserID: w.ID, User: w.FullName, For: dailyAudience(w.Roles), Days: []DayLink{}}
		if w.BranchName != "" {
			row.Branch = ptr(w.BranchName)
		}
		for _, d := range days {
			row.Days = append(row.Days, DayLink{dateKey(d), shortDayLabel(d)})
		}
		out.Rows = append(out.Rows, row)
	}
	sort.SliceStable(out.Rows, func(i, j int) bool { return len(out.Rows[i].Days) > len(out.Rows[j].Days) })
	return out, nil
}

// ───────────── Chi tiết một báo cáo ─────────────

type DetailFollowup struct {
	User      *string   `json:"user"`
	Content   string    `json:"content"`
	CreatedAt time.Time `json:"created_at"`
}

type DetailReport struct {
	ID            uuid.UUID        `json:"id"`
	Title         string           `json:"title"`
	TypeLabel     string           `json:"type_label"`
	For           *string          `json:"for"`
	User          string           `json:"user"`
	ReportDate    string           `json:"report_date"`
	SubmittedAt   time.Time        `json:"submitted_at"`
	UpdatedAt     time.Time        `json:"updated_at"`
	Late          bool             `json:"late"`
	Deadline      *string          `json:"deadline"`
	Status        string           `json:"status"`
	ReviewNote    *string          `json:"review_note"`
	Reviewable    bool             `json:"reviewable"`
	ReviewedAt    *time.Time       `json:"reviewed_at"`
	ReviewedBy    *string          `json:"reviewed_by"`
	Content       *string          `json:"content"`
	SeverityLabel *string          `json:"severity_label"`
	KpiCode       *string          `json:"kpi_code"`
	Class         *string          `json:"class"`
	Followups     []DetailFollowup `json:"followups"`
}

// DetailLegacy: báo cáo nhập tay bản cũ — đủ mọi ô như màn nhập lúc đó (ô trống hiện "—").
type DetailLegacy struct {
	Fields  []LabeledValue `json:"fields,omitempty"`
	Groups  []KpiGroup     `json:"groups,omitempty"`
	Metrics []LabeledValue `json:"metrics,omitempty"`
}

type ReportDetail struct {
	Report     DetailReport           `json:"report"`
	View       string                 `json:"view"` // journal | daily | daily_legacy | weekly_kpi | weekly_legacy | academic_weekly | text
	Auto       any                    `json:"auto"`
	Notes      map[string]string      `json:"notes"`
	NoteLabels map[string]string      `json:"note_labels"`
	Note       *string                `json:"note"`
	Tasks      []TaskSummary          `json:"tasks"`
	CanReview  bool                   `json:"can_review"`
	NextID     *uuid.UUID             `json:"next_id"`
	Legacy     *DetailLegacy          `json:"legacy"`
	Rows       map[string][]WeeklyRow `json:"rows"`
	Sections   []Option               `json:"sections"`
	Columns    []Option               `json:"columns"`
}

func (s *Service) Detail(ctx context.Context, a *iam.Access, id uuid.UUID) (*ReportDetail, error) {
	if err := guardViewAll(a); err != nil {
		return nil, err
	}
	scope := viewerScope(a)
	r, err := s.q.GetScopedReport(ctx, reportsdb.GetScopedReportParams{ID: id, ScopeBranches: scope, ViewerID: a.UserID})
	if errors.Is(err, pgx.ErrNoRows) {
		return nil, ErrNotFound
	}
	if err != nil {
		return nil, err
	}
	var raw struct {
		Auto    json.RawMessage        `json:"auto"`
		Fields  map[string]any         `json:"fields"`
		Notes   map[string]string      `json:"notes"`
		Note    *string                `json:"note"`
		TaskIDs []uuid.UUID            `json:"task_ids"`
		Counts  []weeklyKpiCount       `json:"counts"`
		Metrics map[string]any         `json:"metrics"`
		Rows    map[string][]WeeklyRow `json:"rows"`
	}
	_ = json.Unmarshal(r.Data, &raw)
	hasAuto := len(raw.Auto) > 0 && string(raw.Auto) != "null"
	kind := strOr(r.Kind, "")
	view := "text"
	switch {
	case r.Type == "journal":
		view = "journal"
	case kind == KindDailyOps && hasAuto:
		view = "daily"
	case kind == KindDailyOps && raw.Fields != nil:
		view = "daily_legacy"
	case kind == KindWeeklyKpi && hasAuto:
		view = "weekly_kpi"
	case kind == KindWeeklyKpi:
		view = "weekly_legacy"
	case kind == KindAcademicWeekly && raw.Rows != nil:
		view = "academic_weekly"
	}
	d := &ReportDetail{View: view, Notes: map[string]string{}, NoteLabels: dailyNoteLabels, Note: raw.Note,
		Tasks: []TaskSummary{}, CanReview: canReview(a), Rows: map[string][]WeeklyRow{}, Sections: weeklySections,
		Columns: weeklyColumns}
	rep := DetailReport{ID: r.ID, Title: r.Title, TypeLabel: kindLabel(r.Type, r.Kind), For: kindFor(r.Kind, r.UserRoles),
		User: r.UserName, ReportDate: dateKey(r.ReportDate), SubmittedAt: r.CreatedAt, UpdatedAt: r.UpdatedAt,
		Late: isLate(r.Type, r.SubmittedAt, r.CreatedAt, r.ReportDate), Status: r.Status, ReviewNote: r.ReviewNote,
		Reviewable: r.Type != "journal", ReviewedAt: r.ReviewedAt, ReviewedBy: r.ReviewerName, Content: r.Content,
		KpiCode: r.KpiCode, Followups: []DetailFollowup{}}
	if r.SubmittedAt != nil {
		rep.SubmittedAt = *r.SubmittedAt
	}
	if r.Type == "daily" {
		rep.Deadline = ptr(dailyDeadline(r.ReportDate).Format("15:04 02/01"))
	}
	if r.Type == "journal" {
		rep.SeverityLabel = ptr(severityLabel(r.Severity))
	}
	if r.ClassID != nil {
		codes, err := s.classes.Codes(ctx, []uuid.UUID{*r.ClassID})
		if err != nil {
			return nil, err
		}
		if c, ok := codes[*r.ClassID]; ok {
			rep.Class = &c
		}
	}
	fu, err := s.followups(ctx, []uuid.UUID{r.ID})
	if err != nil {
		return nil, err
	}
	for _, f := range fu[r.ID] {
		rep.Followups = append(rep.Followups, DetailFollowup{f.User, f.Content, f.CreatedAt})
	}
	d.Report = rep
	if raw.Notes != nil {
		d.Notes = raw.Notes
	}
	switch view {
	case "daily", "weekly_kpi":
		var auto any
		_ = json.Unmarshal(raw.Auto, &auto)
		d.Auto = auto
	case "daily_legacy":
		// Báo cáo ngày nhập tay cũ chỉ lưu con số: tính lại số liệu của ngày đó theo phạm vi người viết.
		w, err := s.writerByID(ctx, r.UserID)
		if err != nil {
			return nil, err
		}
		aud := dailyAudience(w.Roles)
		sections, _, err := s.dailyMetrics(ctx, w, r.ReportDate, aud)
		if err != nil {
			return nil, err
		}
		d.Auto = dailyAuto{Audience: aud, Sections: sections, ComputedAt: s.now()}
		lg := &DetailLegacy{Fields: []LabeledValue{}}
		for _, f := range legacyDailyFields {
			v := "—"
			if x, ok := raw.Fields[f.Value]; ok && x != nil && fmt.Sprint(x) != "" {
				v = fmt.Sprint(x)
			}
			lg.Fields = append(lg.Fields, LabeledValue{f.Label, v})
		}
		d.Legacy = lg
	case "weekly_legacy":
		lg := &DetailLegacy{Groups: []KpiGroup{}, Metrics: []LabeledValue{}}
		idx := map[string]int{}
		for _, c := range raw.Counts {
			g := c.Group
			if g == "" {
				g = "Khác"
			}
			i, ok := idx[g]
			if !ok {
				i = len(lg.Groups)
				idx[g] = i
				lg.Groups = append(lg.Groups, KpiGroup{Name: g, Items: []KpiItem{}})
			}
			id := c.ID
			if id == "" {
				id = strings.TrimSpace(c.Code + " " + c.Name)
			}
			lg.Groups[i].Items = append(lg.Groups[i].Items, KpiItem{ID: id, Code: c.Code, Name: c.Name, Count: c.Count, Details: []Detail{}})
		}
		for _, m := range weeklyMetricLabels {
			v := "—"
			if x, ok := raw.Metrics[m.Value]; ok && x != nil {
				v = fmt.Sprint(x)
			}
			lg.Metrics = append(lg.Metrics, LabeledValue{m.Label, v})
		}
		d.Legacy = lg
	case "academic_weekly":
		d.Rows = raw.Rows
	}
	if len(raw.TaskIDs) > 0 {
		tasks, err := s.tasks.Summaries(ctx, raw.TaskIDs)
		if err != nil {
			return nil, err
		}
		if tasks != nil {
			d.Tasks = tasks
		}
	}
	if r.Type != "journal" {
		next, err := s.q.NextPendingID(ctx, reportsdb.NextPendingIDParams{ID: r.ID, ScopeBranches: scope, ViewerID: a.UserID})
		if err == nil {
			d.NextID = &next
		} else if !errors.Is(err, pgx.ErrNoRows) {
			return nil, err
		}
	}
	return d, nil
}

// ───────────── Kiểm tra / yêu cầu bổ sung (chỉ Admin) ─────────────

// reviewable: báo cáo Admin kiểm tra được (không phải nhật ký, không phải nháp), trong phạm vi người xem.
func (s *Service) reviewable(ctx context.Context, a *iam.Access, id uuid.UUID) (reportsdb.GetScopedReportRow, error) {
	if !canReview(a) {
		return reportsdb.GetScopedReportRow{}, ErrAdminOnly
	}
	r, err := s.q.GetScopedReport(ctx, reportsdb.GetScopedReportParams{ID: id, ScopeBranches: viewerScope(a), ViewerID: a.UserID})
	if errors.Is(err, pgx.ErrNoRows) || (err == nil && r.Type == "journal") {
		return r, ErrNotFound
	}
	return r, err
}

func (s *Service) markReviewed(ctx context.Context, a *iam.Access, id uuid.UUID, reviewed bool, wasReviewed bool, writer uuid.UUID) error {
	r, err := s.q.SetReviewed(ctx, reportsdb.SetReviewedParams{Reviewed: reviewed, Reviewer: a.UserID, ID: id})
	if err != nil {
		return err
	}
	s.log.Info("staff report reviewed", "by", a.UserID, "id", id, "reviewed", reviewed)
	if reviewed && !wasReviewed && writer != a.UserID {
		_ = r
		// TODO(notify): báo người viết, loại staff_report_reviewed, "Đã kiểm tra: <title>" — "<tên Admin> đã kiểm tra
		// <title>.", link màn nhập đúng kỳ (writerPath).
	}
	return nil
}

func (s *Service) Review(ctx context.Context, a *iam.Access, id uuid.UUID, reviewed bool) (string, error) {
	r, err := s.reviewable(ctx, a, id)
	if err != nil {
		return "", err
	}
	if err := s.markReviewed(ctx, a, r.ID, reviewed, r.ReviewedAt != nil, r.UserID); err != nil {
		return "", err
	}
	if reviewed {
		return "Đã đánh dấu đã kiểm tra.", nil
	}
	return "Đã chuyển về chờ kiểm tra.", nil
}

// ReviewBulk: "Đã kiểm tra" hàng loạt — chỉ các báo cáo đang chờ kiểm tra trong phạm vi.
func (s *Service) ReviewBulk(ctx context.Context, a *iam.Access, ids []uuid.UUID) (string, error) {
	if !canReview(a) {
		return "", ErrAdminOnly
	}
	if len(ids) == 0 {
		return "", iam.Invalid("Chọn ít nhất một báo cáo.")
	}
	if len(ids) > 200 {
		return "", iam.Invalid("Mỗi lần kiểm tra tối đa 200 báo cáo.")
	}
	pending, err := s.q.PendingInIDs(ctx, reportsdb.PendingInIDsParams{Ids: ids, ScopeBranches: viewerScope(a), ViewerID: a.UserID})
	if err != nil {
		return "", err
	}
	for _, id := range pending {
		r, err := s.q.GetScopedReport(ctx, reportsdb.GetScopedReportParams{ID: id, ScopeBranches: viewerScope(a), ViewerID: a.UserID})
		if err != nil {
			return "", err
		}
		if err := s.markReviewed(ctx, a, id, true, false, r.UserID); err != nil {
			return "", err
		}
	}
	return fmt.Sprintf("Đã đánh dấu %d báo cáo đã kiểm tra.", len(pending)), nil
}

// RequestChanges: Admin "Yêu cầu bổ sung" — báo cáo về trạng thái returned kèm nhận xét; người viết sửa và nộp lại.
func (s *Service) RequestChanges(ctx context.Context, a *iam.Access, id uuid.UUID, note string) (string, error) {
	r, err := s.reviewable(ctx, a, id)
	if err != nil {
		return "", err
	}
	note = strings.TrimSpace(note)
	if note == "" {
		return "", iam.Invalid("Nhập nội dung cần bổ sung.")
	}
	if runeLen(note) > 2000 {
		return "", iam.Invalid("Nội dung cần bổ sung tối đa 2000 ký tự.")
	}
	if _, err := s.q.RequestChanges(ctx, reportsdb.RequestChangesParams{Note: &note, Reviewer: &a.UserID, ID: r.ID}); err != nil {
		return "", err
	}
	s.log.Info("staff report returned", "by", a.UserID, "id", r.ID)
	// TODO(notify): báo người viết, loại staff_report_returned, "Cần bổ sung: <title>" — "<tên Admin>: <note cắt 200 ký tự>",
	// link màn nhập đúng kỳ (writerPath).
	return "Đã gửi yêu cầu bổ sung cho " + r.UserName + ".", nil
}

// WriterPath: đường dẫn màn nhập của người viết đúng kỳ báo cáo (link trong thông báo).
func WriterPath(kind, period *string) string {
	if kind == nil || period == nil {
		return "/reports"
	}
	path, ok := kindPaths[*kind]
	if !ok {
		return "/reports"
	}
	param := map[string]string{"daily": "date", "weekly": "week", "quarterly": "quarter"}[structured[*kind].Type]
	if param == "" {
		param = "month"
	}
	return path + "?" + param + "=" + *period
}

// kindPaths: màn nhập trên giao diện mới theo loại báo cáo có cấu trúc.
var kindPaths = map[string]string{
	KindDailyOps:          "/reports/daily",
	KindWeeklyKpi:         "/reports/weekly-kpi",
	KindAcademicWeekly:    "/reports/academic-weekly",
	KindAcademicMonthly:   "/reports/academic-monthly",
	KindAcademicQuarterly: "/reports/academic-quarterly",
	KindTeacherMonthly:    "/reports/teacher-monthly",
	KindTeacherAttendance: "/reports/teacher-monthly",
}
