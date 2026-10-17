// Package auditapi là màn "Nhật ký vận hành" (Laravel ActivityLogController): xem, lọc, thống kê, xuất CSV nhật ký
// do audit.Record ghi. Tách khỏi package audit vì cần iam (quyền, phạm vi) mà iam lại ghi nhật ký qua audit.
package auditapi

import (
	"context"
	"encoding/json"
	"fmt"
	"log/slog"
	"slices"
	"sort"
	"strconv"
	"strings"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5/pgxpool"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit/auditdb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

// Giờ Việt Nam cố định (UTC+7, không đổi giờ) để "hôm nay" và lọc theo ngày khớp với người dùng.
var vnZone = time.FixedZone("ICT", 7*3600)

type Service struct {
	q   *auditdb.Queries
	log *slog.Logger
	now func() time.Time
}

func NewService(pool *pgxpool.Pool, log *slog.Logger) *Service {
	return &Service{q: auditdb.New(pool), log: log, now: time.Now}
}

// Filter: bộ lọc của màn (giống query string của ActivityLogController::filteredQuery).
type Filter struct {
	Module   string // phân hệ cụ thể (log_name)
	Group    string // nhóm lọc nhanh (audit.Groups)
	Event    string
	ActorID  string
	DateFrom string // YYYY-MM-DD
	DateTo   string
	Search   string
	Page     int
	PerPage  int
}

const (
	defaultPerPage = 20
	maxPerPage     = 200 // "Tất cả" tối đa 200 dòng như Laravel
	exportLimit    = 20000
)

type DiffRow struct {
	Field string `json:"field"`
	Old   string `json:"old"`
	New   string `json:"new"`
}

type Log struct {
	ID           uuid.UUID      `json:"id"`
	CreatedAt    time.Time      `json:"created_at"`
	ActorID      *uuid.UUID     `json:"actor_id"`
	ActorName    *string        `json:"actor_name"`
	ActorCode    *string        `json:"actor_code"`
	ActorRole    *string        `json:"actor_role"`
	Module       string         `json:"module"`
	Event        string         `json:"event"`
	EventLabel   string         `json:"event_label"`
	Action       string         `json:"action"`
	Description  string         `json:"description"`
	SubjectType  *string        `json:"subject_type"`
	SubjectID    *string        `json:"subject_id"`
	SubjectLabel *string        `json:"subject_label"`
	Subject      *string        `json:"subject"`
	BranchID     *uuid.UUID     `json:"branch_id"`
	Diff         []DiffRow      `json:"diff"`
	Properties   map[string]any `json:"properties"`
	IP           *string        `json:"ip"`
	UserAgent    *string        `json:"user_agent"`
	RequestID    *uuid.UUID     `json:"request_id"`
	URL          *string        `json:"url"`
}

type Page struct {
	Logs    []Log `json:"logs"`
	Total   int64 `json:"total"`
	Page    int   `json:"page"`
	PerPage int   `json:"per_page"`
}

// scope: phạm vi activity_log.scope_* (own: thao tác của mình; branch: của mình và nhân sự chi nhánh mình; all).
func scope(actor *iam.Access) (*uuid.UUID, []uuid.UUID) {
	switch actor.Level("activity_log") {
	case iam.ScopeAll:
		return nil, nil
	case iam.ScopeBranch:
		return &actor.UserID, append([]uuid.UUID{}, actor.BranchIDs()...)
	}
	return &actor.UserID, nil
}

func parseDay(s, msg string) (*time.Time, error) {
	if s = strings.TrimSpace(s); s == "" {
		return nil, nil
	}
	t, err := time.ParseInLocation("2006-01-02", s, vnZone)
	if err != nil {
		return nil, iam.Invalid(msg)
	}
	return &t, nil
}

func (s *Service) params(actor *iam.Access, f Filter) (auditdb.ListAuditLogParams, error) {
	var p auditdb.ListAuditLogParams
	p.Viewer, p.BranchIds = scope(actor)
	from, err := parseDay(f.DateFrom, "Ngày bắt đầu không phải là ngày hợp lệ.")
	if err != nil {
		return p, err
	}
	to, err := parseDay(f.DateTo, "Ngày kết thúc không phải là ngày hợp lệ.")
	if err != nil {
		return p, err
	}
	if from != nil && to != nil && to.Before(*from) {
		return p, iam.Invalid("Ngày kết thúc phải sau hoặc bằng ngày bắt đầu.")
	}
	p.DateFrom = from
	if to != nil {
		end := to.AddDate(0, 0, 1)
		p.DateTo = &end
	}
	if m := strings.TrimSpace(f.Module); m != "" {
		p.Modules = []string{m}
	}
	for _, g := range audit.Groups {
		if g.Key == f.Group {
			if p.Modules != nil {
				// Lọc cả phân hệ lẫn nhóm: chỉ giữ phần giao.
				if !slices.Contains(g.Modules, p.Modules[0]) {
					p.Modules = []string{}
				}
			} else {
				p.Modules = g.Modules
			}
		}
	}
	if e := strings.TrimSpace(f.Event); e != "" {
		p.Event = &e
	}
	if f.ActorID != "" {
		id, err := uuid.Parse(f.ActorID)
		if err != nil {
			return p, iam.Invalid("Người thực hiện không hợp lệ.")
		}
		p.ActorID = &id
	}
	if q := strings.TrimSpace(f.Search); q != "" {
		like := "%" + strings.NewReplacer(`\`, `\\`, "%", `\%`, "_", `\_`).Replace(q) + "%"
		exact := strings.TrimPrefix(q, "#")
		p.Search, p.Exact = &like, &exact
	}
	return p, nil
}

func (s *Service) List(ctx context.Context, actor *iam.Access, f Filter) (Page, error) {
	p, err := s.params(actor, f)
	if err != nil {
		return Page{}, err
	}
	if f.PerPage <= 0 {
		f.PerPage = defaultPerPage
	}
	f.PerPage = min(f.PerPage, maxPerPage)
	f.Page = max(f.Page, 1)
	p.Lim, p.Off = int32(f.PerPage), int32((f.Page-1)*f.PerPage)
	rows, err := s.q.ListAuditLog(ctx, p)
	if err != nil {
		return Page{}, err
	}
	out := Page{Logs: make([]Log, 0, len(rows)), Page: f.Page, PerPage: f.PerPage}
	for _, r := range rows {
		out.Total = r.Total
		out.Logs = append(out.Logs, toLog(r))
	}
	return out, nil
}

// Export: mọi dòng theo bộ lọc (tối đa 20.000 dòng như bản xuất Excel của Laravel).
func (s *Service) Export(ctx context.Context, actor *iam.Access, f Filter) ([]Log, error) {
	p, err := s.params(actor, f)
	if err != nil {
		return nil, err
	}
	p.Lim = exportLimit
	rows, err := s.q.ListAuditLog(ctx, p)
	if err != nil {
		return nil, err
	}
	out := make([]Log, 0, len(rows))
	for _, r := range rows {
		out = append(out, toLog(r))
	}
	return out, nil
}

func toLog(r auditdb.ListAuditLogRow) Log {
	l := Log{
		ID: r.ID, CreatedAt: r.CreatedAt, ActorID: r.ActorID, ActorName: r.ActorName, ActorCode: r.ActorCode,
		Module: r.Module, Event: r.Event, EventLabel: audit.EventLabel(r.Event), Action: r.Action,
		Description: r.Description, SubjectType: r.SubjectType, SubjectID: r.SubjectID, SubjectLabel: r.SubjectLabel,
		BranchID: r.BranchID, Diff: []DiffRow{}, Properties: map[string]any{}, IP: r.Ip, UserAgent: r.UserAgent,
		RequestID: r.RequestID,
	}
	if r.ActorRole != "" {
		l.ActorRole = &r.ActorRole
	}
	if r.SubjectType != nil {
		subject := audit.SubjectName(*r.SubjectType)
		switch {
		case r.SubjectLabel != nil:
			subject += " " + *r.SubjectLabel
		case r.SubjectID != nil:
			subject += " #" + *r.SubjectID
		}
		l.Subject = &subject
	}
	if r.Method != nil && r.Path != nil {
		u := *r.Method + " " + *r.Path
		l.URL = &u
	}
	var changes audit.Changes
	_ = json.Unmarshal(r.Changes, &changes)
	fields := make([]string, 0, len(changes))
	for k := range changes {
		fields = append(fields, k)
	}
	sort.Strings(fields)
	for _, k := range fields {
		l.Diff = append(l.Diff, DiffRow{k, Stringify(changes[k].Old), Stringify(changes[k].New)})
	}
	_ = json.Unmarshal(r.Properties, &l.Properties)
	return l
}

// Stringify: giá trị trước / sau dạng chữ (ActivityLogController::stringify).
func Stringify(v any) string {
	switch t := v.(type) {
	case nil:
		return "—"
	case bool:
		if t {
			return "Có"
		}
		return "Không"
	case string:
		return t
	case float64:
		return strconv.FormatFloat(t, 'f', -1, 64)
	}
	b, err := json.Marshal(v)
	if err != nil {
		return fmt.Sprint(v)
	}
	return string(b)
}

type Option struct {
	Value string `json:"value"`
	Label string `json:"label"`
}

type Stats struct {
	Total       int64 `json:"total"`
	Today       int64 `json:"today"`
	ActiveUsers int64 `json:"active_users"`
}

type Options struct {
	Groups  []audit.Group `json:"groups"`
	Modules []string      `json:"modules"`
	Events  []Option      `json:"events"`
	Actors  []Option      `json:"actors"`
	Stats   Stats         `json:"stats"`
}

// Options: danh sách cho các ô lọc và thống kê nhanh (trong phạm vi người xem).
func (s *Service) Options(ctx context.Context, actor *iam.Access) (Options, error) {
	viewer, branches := scope(actor)
	out := Options{Groups: audit.Groups, Modules: append([]string{}, audit.Modules...), Events: []Option{}, Actors: []Option{}}
	mods, err := s.q.AuditModules(ctx)
	if err != nil {
		return out, err
	}
	for _, m := range mods {
		if !slices.Contains(out.Modules, m) {
			out.Modules = append(out.Modules, m)
		}
	}
	events, err := s.q.AuditEvents(ctx)
	if err != nil {
		return out, err
	}
	for _, e := range events {
		out.Events = append(out.Events, Option{e, audit.EventLabel(e)})
	}
	actors, err := s.q.AuditActors(ctx, auditdb.AuditActorsParams{Viewer: viewer, BranchIds: branches})
	if err != nil {
		return out, err
	}
	for _, a := range actors {
		label := a.FullName
		if a.Code != nil {
			label += " (" + *a.Code + ")"
		}
		out.Actors = append(out.Actors, Option{a.ID.String(), label})
	}
	now := s.now().In(vnZone)
	dayStart := time.Date(now.Year(), now.Month(), now.Day(), 0, 0, 0, 0, vnZone)
	st, err := s.q.AuditStats(ctx, auditdb.AuditStatsParams{DayStart: dayStart, Viewer: viewer, BranchIds: branches})
	if err != nil {
		return out, err
	}
	out.Stats = Stats{st.Total, st.Today, st.ActiveUsers}
	return out, nil
}
