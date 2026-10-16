package crm

import (
	"context"
	"math"
	"slices"
	"sort"
	"strconv"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/crm/crmdb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

// Báo cáo CRM (Laravel CrmController::reports): cohort khách theo ngày tiếp nhận, khách chốt theo ngày chốt, thất bại
// theo ngày thất bại, so với kỳ trước liền kề; bảng hiệu suất theo người phụ trách.

var reportPresets = []struct{ Key, Label string }{
	{"today", "Hôm nay"}, {"yesterday", "Hôm qua"}, {"last_7_days", "7 ngày"}, {"last_week", "Tuần trước"},
	{"last_30_days", "30 ngày"}, {"last_60_days", "60 ngày"}, {"last_90_days", "90 ngày"}, {"last_6_months", "6 tháng"},
	{"last_year", "1 năm"}, {"custom", "Tùy chỉnh"},
}

var stageDescriptions = map[string]string{
	StageNew: "Chưa bắt đầu tư vấn", StageConsulting: "Đang xác định lộ trình", StageTestScheduled: "Đã đặt lịch kiểm tra",
	StageTesting: "Đang làm bài test", StageTested: "Đã có kết quả đầu vào", StageResultSent: "Đã gửi kết quả cho khách",
	StageWaitingClass: "Đã chốt, chờ Học vụ xếp lớp", StageWon: "Đã chốt và xếp lớp",
}

func startOfDay(t time.Time) time.Time { s, _ := dayBounds(t); return s }
func endOfDay(t time.Time) time.Time   { _, e := dayBounds(t); return e }

// rollingDays: N ngày gần nhất tính cả hôm nay, và N ngày liền trước (không chồng ngày).
func rollingDays(now time.Time, days int) (time.Time, time.Time, time.Time, time.Time) {
	start := startOfDay(now).AddDate(0, 0, -(days - 1))
	return start, endOfDay(now), start.AddDate(0, 0, -days), start.Add(-time.Second)
}

func startOfWeek(t time.Time) time.Time {
	d := startOfDay(t)
	wd := (int(d.Weekday()) + 6) % 7 // thứ Hai = 0
	return d.AddDate(0, 0, -wd)
}

// reportPeriod: kỳ báo cáo và kỳ trước theo preset (giờ Việt Nam).
func reportPeriod(preset string, now time.Time, from, to *time.Time) (string, time.Time, time.Time, time.Time, time.Time) {
	var s, e, ps, pe time.Time
	switch preset {
	case "today":
		s, e = startOfDay(now), endOfDay(now)
		ps, pe = s.AddDate(0, 0, -1), e.AddDate(0, 0, -1)
	case "yesterday":
		s, e = startOfDay(now).AddDate(0, 0, -1), endOfDay(now).AddDate(0, 0, -1)
		ps, pe = s.AddDate(0, 0, -1), e.AddDate(0, 0, -1)
	case "last_7_days":
		s, e, ps, pe = rollingDays(now, 7)
	case "last_week":
		s = startOfWeek(now).AddDate(0, 0, -7)
		e = s.AddDate(0, 0, 7).Add(-time.Nanosecond)
		ps, pe = s.AddDate(0, 0, -7), s.Add(-time.Nanosecond)
	case "last_60_days":
		s, e, ps, pe = rollingDays(now, 60)
	case "last_90_days":
		s, e, ps, pe = rollingDays(now, 90)
	case "last_6_months":
		s = startOfDay(now.In(vnZone).AddDate(0, -6, 1))
		e = endOfDay(now)
		ps, pe = s.AddDate(0, -6, 0), s.Add(-time.Second)
	case "last_year":
		s = startOfDay(now.In(vnZone).AddDate(-1, 0, 1))
		e = endOfDay(now)
		ps, pe = s.AddDate(-1, 0, 0), s.Add(-time.Second)
	case "custom":
		s = startOfDay(now).AddDate(0, 0, -29)
		if from != nil {
			s = startOfDay(*from)
		}
		e = endOfDay(now)
		if to != nil {
			e = endOfDay(*to)
		}
		if s.After(e) {
			// Chọn ngược "từ" / "đến" → đảo lại thay vì trả báo cáo rỗng.
			s, e = startOfDay(e), endOfDay(s)
		}
		days := int(math.Round(startOfDay(e).Sub(s).Hours()/24)) + 1
		ps, pe = s.AddDate(0, 0, -days), s.Add(-time.Second)
	default:
		preset = "last_30_days"
		s, e, ps, pe = rollingDays(now, 30)
	}
	return preset, s, e, ps, pe
}

// Report: dữ liệu màn Báo cáo.
type Report struct {
	Preset         string       `json:"preset"`
	PresetLabel    string       `json:"preset_label"`
	Presets        []Option     `json:"presets"`
	StartDate      time.Time    `json:"start_date"`
	EndDate        time.Time    `json:"end_date"`
	BranchID       *uuid.UUID   `json:"branch_id"`
	Branches       []Option     `json:"branches"`
	TotalLeads     int64        `json:"metric_total_leads"`
	WonDeals       int64        `json:"metric_won_deals"`
	ConversionRate float64      `json:"metric_conversion_rate"`
	LostDeals      int64        `json:"metric_lost_deals"`
	LeadDelta      float64      `json:"lead_delta_percent"`
	LeadDiff       int64        `json:"lead_diff"`
	WonDelta       float64      `json:"won_delta_percent"`
	WonDiff        int64        `json:"won_diff"`
	ConvDelta      float64      `json:"conversion_delta_percent"`
	LostDelta      float64      `json:"lost_delta_percent"`
	LostDiff       int64        `json:"lost_diff"`
	Funnel         []FunnelStep `json:"funnel_stages"`
	LostReasons    []LostItem   `json:"lost_reasons"`
	Reps           []RepRow     `json:"reps"`
	UpdatedAt      time.Time    `json:"updated_at"`
}

type FunnelStep struct {
	Stage   string  `json:"stage"`
	Name    string  `json:"name"`
	Count   int64   `json:"count"`
	Percent float64 `json:"percent"`
	Desc    string  `json:"desc"`
}

type LostItem struct {
	ID     uuid.UUID `json:"id"`
	Name   string    `json:"name"`
	At     time.Time `json:"at"`
	Reason *string   `json:"reason"`
	User   *string   `json:"user"`
}

// RepRow: một dòng bảng hiệu suất theo người phụ trách.
type RepRow struct {
	UserID           uuid.UUID `json:"user_id"`
	Name             string    `json:"name"`
	Role             string    `json:"role"`
	AvatarLetter     string    `json:"avatar_letter"`
	Leads            int64     `json:"leads"`
	Won              int64     `json:"won"`
	Rate             float64   `json:"rate"`
	Revenue          int64     `json:"revenue"`           // TODO(merge): học phí thực thu trong kỳ (phân hệ tài chính)
	CommissionAmount int64     `json:"commission_amount"` // TODO(merge): hoa hồng phát sinh (SalesCommissionService)
	TierName         string    `json:"tier_name"`
	Delta            string    `json:"delta"`
	Rating           string    `json:"rating"`
}

func round1(v float64) float64 { return math.Round(v*10) / 10 }

func pct(part, total int64) float64 {
	if total == 0 {
		return 0
	}
	return round1(float64(part) / float64(total) * 100)
}

func deltaPct(cur, prev int64) float64 {
	if prev > 0 {
		return round1(float64(cur-prev) / float64(prev) * 100)
	}
	if cur > 0 {
		return 100
	}
	return 0
}

// rating: Xuất sắc / Tốt / Đạt yêu cầu / Cần cải thiện theo doanh thu hoặc tỉ lệ chốt.
func rating(revenue int64, rate float64) string {
	switch {
	case revenue >= 100000000 || rate >= 30:
		return "Xuất sắc"
	case revenue >= 50000000 || rate >= 25:
		return "Tốt"
	case revenue >= 20000000 || rate >= 15:
		return "Đạt yêu cầu"
	}
	return "Cần cải thiện"
}

type cohort struct {
	total   int64
	closed  int64
	byStage map[string]int64
	byUser  map[uuid.UUID][2]int64 // [tổng, đã chốt]
}

func (s *Service) cohort(ctx context.Context, sc scope, branch *uuid.UUID, start, end time.Time) (cohort, error) {
	rows, err := s.q.ReportCohort(ctx, crmdb.ReportCohortParams{
		StartAt: start, EndAt: end, BranchID: branch, ScopeOwner: sc.owner, ScopeBranches: sc.branches,
	})
	c := cohort{byStage: map[string]int64{}, byUser: map[uuid.UUID][2]int64{}}
	if err != nil {
		return c, err
	}
	for _, r := range rows {
		c.total += r.N
		c.byStage[r.Stage] += r.N
		closed := isClosed(r.Stage)
		if closed {
			c.closed += r.N
		}
		if r.AssignedUserID != nil {
			v := c.byUser[*r.AssignedUserID]
			v[0] += r.N
			if closed {
				v[1] += r.N
			}
			c.byUser[*r.AssignedUserID] = v
		}
	}
	return c, nil
}

// ReportFilter: tham số màn Báo cáo.
type ReportFilter struct {
	Preset   string
	From, To *time.Time
	BranchID *uuid.UUID
}

func (s *Service) Report(ctx context.Context, a *iam.Access, f ReportFilter) (*Report, error) {
	now := s.now()
	preset, start, end, pstart, pend := reportPeriod(f.Preset, now, f.From, f.To)
	sc := scopeOf(a)
	level := a.Level("lead")
	r := &Report{Preset: preset, StartDate: start, EndDate: end, BranchID: f.BranchID, UpdatedAt: now, Branches: []Option{}}
	for _, p := range reportPresets {
		r.Presets = append(r.Presets, Option{p.Key, p.Label})
		if p.Key == preset {
			r.PresetLabel = p.Label
		}
	}
	// Toàn hệ thống chọn mọi chi nhánh đang hoạt động; còn lại chỉ chi nhánh của mình.
	bp := crmdb.ListBranchesBriefParams{OnlyActive: true}
	if level != iam.ScopeAll {
		bp.Ids = a.BranchIDs()
		if bp.Ids == nil {
			bp.Ids = []uuid.UUID{}
		}
	}
	branches, err := s.q.ListBranchesBrief(ctx, bp)
	if err != nil {
		return nil, err
	}
	for _, b := range branches {
		r.Branches = append(r.Branches, Option{b.ID.String(), b.Name})
	}
	cur, err := s.cohort(ctx, sc, f.BranchID, start, end)
	if err != nil {
		return nil, err
	}
	prev, err := s.cohort(ctx, sc, f.BranchID, pstart, pend)
	if err != nil {
		return nil, err
	}
	closedCount := func(st, en time.Time) (int64, error) {
		return s.q.ReportClosedCount(ctx, crmdb.ReportClosedCountParams{StartAt: st, EndAt: en, BranchID: f.BranchID, ScopeOwner: sc.owner, ScopeBranches: sc.branches})
	}
	won, err := closedCount(start, end)
	if err != nil {
		return nil, err
	}
	prevWon, err := closedCount(pstart, pend)
	if err != nil {
		return nil, err
	}
	lost, err := s.q.ReportLost(ctx, crmdb.ReportLostParams{StartAt: start, EndAt: end, BranchID: f.BranchID, ScopeOwner: sc.owner, ScopeBranches: sc.branches})
	if err != nil {
		return nil, err
	}
	prevLost, err := s.q.ReportLost(ctx, crmdb.ReportLostParams{StartAt: pstart, EndAt: pend, BranchID: f.BranchID, ScopeOwner: sc.owner, ScopeBranches: sc.branches})
	if err != nil {
		return nil, err
	}
	r.TotalLeads, r.WonDeals, r.LostDeals = cur.total, won, int64(len(lost))
	r.ConversionRate = pct(cur.closed, cur.total)
	r.LeadDiff, r.LeadDelta = cur.total-prev.total, deltaPct(cur.total, prev.total)
	r.WonDiff, r.WonDelta = won-prevWon, deltaPct(won, prevWon)
	r.ConvDelta = round1(r.ConversionRate - pct(prev.closed, prev.total))
	r.LostDiff, r.LostDelta = r.LostDeals-int64(len(prevLost)), deltaPct(r.LostDeals, int64(len(prevLost)))
	for _, st := range PipelineStages {
		n := cur.byStage[st]
		r.Funnel = append(r.Funnel, FunnelStep{Stage: st, Name: StageLabel(st), Count: n, Percent: pct(n, cur.total), Desc: stageDescriptions[st]})
	}
	r.LostReasons = []LostItem{}
	for i, l := range lost {
		if i == 10 {
			break
		}
		r.LostReasons = append(r.LostReasons, LostItem{ID: l.ID, Name: l.Name, At: l.At, Reason: l.LostReason, User: l.UserName})
	}
	// Khách chốt ghi cho người nhận hoa hồng (kể cả khi đã phân công lại); chỉ giới hạn chi nhánh.
	var creditBranches []uuid.UUID
	if level == iam.ScopeBranch {
		creditBranches = sc.branches
	}
	credited, err := s.q.ReportCredited(ctx, crmdb.ReportCreditedParams{StartAt: start, EndAt: end, BranchID: f.BranchID, ScopeBranches: creditBranches})
	if err != nil {
		return nil, err
	}
	wonBy := map[uuid.UUID]int64{}
	ids := []uuid.UUID{}
	for _, c := range credited {
		wonBy[c.UserID] = c.N
		ids = append(ids, c.UserID)
	}
	for id := range cur.byUser {
		ids = append(ids, id)
	}
	users, err := s.q.ListRepUsers(ctx, ids)
	if err != nil {
		return nil, err
	}
	r.Reps = []RepRow{}
	for _, u := range users {
		// Phạm vi: toàn hệ thống → tất cả; chi nhánh → nhân sự thuộc chi nhánh mình; của tôi → dòng của mình.
		switch level {
		case iam.ScopeOwn:
			if u.ID != a.UserID {
				continue
			}
		case iam.ScopeBranch:
			if !slices.ContainsFunc(u.BranchIds, func(b uuid.UUID) bool { return slices.Contains(sc.branches, b) }) {
				continue
			}
		}
		c := cur.byUser[u.ID]
		p := prev.byUser[u.ID]
		rate := pct(c[1], c[0])
		delta := round1(rate - pct(p[1], p[0]))
		row := RepRow{
			UserID: u.ID, Name: u.FullName, Role: repRole(u.Roles), Leads: c[0], Won: wonBy[u.ID], Rate: rate,
			Rating: rating(0, rate), TierName: "—",
		}
		if ch, _ := utf8.DecodeRuneInString(u.FullName); ch != utf8.RuneError {
			row.AvatarLetter = strings.ToUpper(string(ch))
		}
		if delta >= 0 {
			row.Delta = "+" + trimFloat(delta) + "%"
		} else {
			row.Delta = trimFloat(delta) + "%"
		}
		// Đang lọc 1 chi nhánh: bỏ dòng không có số liệu ở chi nhánh đó.
		if f.BranchID != nil && row.Leads == 0 && row.Won == 0 {
			continue
		}
		r.Reps = append(r.Reps, row)
	}
	sort.SliceStable(r.Reps, func(i, j int) bool { return r.Reps[i].Revenue > r.Reps[j].Revenue })
	return r, nil
}

func repRole(roles []string) string {
	if slices.Contains(roles, "sales_consultant") {
		return "Chuyên viên Tư vấn Tuyển sinh"
	}
	if len(roles) == 0 {
		return "Tư vấn viên"
	}
	for _, rd := range iam.GetCatalog().Roles {
		if rd.Name == roles[0] {
			return rd.ShortLabel
		}
	}
	return roles[0]
}

func trimFloat(v float64) string {
	s := strings.TrimRight(strings.TrimRight(strconv.FormatFloat(v, 'f', 1, 64), "0"), ".")
	if s == "-0" || s == "" {
		return "0"
	}
	return s
}
