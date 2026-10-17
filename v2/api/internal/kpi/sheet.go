package kpi

import (
	"context"
	"encoding/json"
	"math"
	"slices"
	"strconv"
	"strings"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/kpi/kpidb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/kpi/kpisrc"
)

// Suggestion: gợi ý số lần từ Nhật ký Học vụ cho mục KPI điền tay có mã (người chấm bấm để điền).
type Suggestion struct {
	Count    int             `json:"count"`
	Evidence []SuggestionRow `json:"evidence"`
}

type SuggestionRow struct {
	Date string `json:"date"`
	Text string `json:"text"`
}

// Line là một dòng tiêu chí của phiếu.
type Line struct {
	C          Criterion
	Auto       bool   // số hệ thống đếm
	Pending    string // nguồn tự động chưa có ở v2 (điền tay)
	Suggestion *Suggestion
	Value      *float64
	Evidence   []Evidence
	Level      *float64
	NA         bool
	Cap        bool
	MaxAmount  *float64
	Amount     *float64
}

// Sheet là nội dung phiếu: dòng tiêu chí, tổng % đạt theo trọng số (mục chưa có số = 0), tiền KPI (Học vụ), xếp loại A–E.
type Sheet struct {
	Role      string
	Track     string
	Fund      *float64
	Period    Period
	Lines     []Line
	Missing   int
	Total     float64
	Amount    *float64
	Knockout  string
	Grade     *Grade
	BonusFund *float64
	Bonus     *float64
}

// sheetRec: phiếu đã lưu kèm tên người duyệt.
type sheetRec struct {
	kpidb.HrKpiSheet
	EvaluatorName *string
}

// sheetInput: dữ liệu đã nạp để tính phiếu (tách khỏi truy vấn cho dễ test).
type sheetInput struct {
	role, track string
	period      Period
	criteria    []Criterion
	items       map[uuid.UUID]kpidb.HrKpiItem
	frozen      bool    // phiếu đã duyệt: dùng số và mức đạt đã chốt
	frozenTotal float64 // tổng đã chốt
	measured    map[uuid.UUID]measured
	journal     map[string][]kpisrc.JournalEntry
	fund        *float64
	gradeFund   *float64
}

func parseActual(p *string) *float64 {
	if p == nil {
		return nil
	}
	v, err := strconv.ParseFloat(strings.TrimSpace(*p), 64)
	if err != nil {
		return nil
	}
	return &v
}

// castValue: tỉ lệ làm tròn 2 chữ số, số lần lấy phần nguyên.
func castValue(c *Criterion, v *float64) *float64 {
	if v == nil {
		return nil
	}
	if c.IsRate() {
		return ptr(round2(*v))
	}
	return ptr(math.Trunc(*v))
}

func hasCap(ev []Evidence) bool {
	return slices.ContainsFunc(ev, func(e Evidence) bool { return e.Cap })
}

// buildSheet: KpiSheetService::sheet.
func buildSheet(in sheetInput) Sheet {
	weightTotal := 0.0
	for _, c := range in.criteria {
		weightTotal += c.Weight
	}
	out := Sheet{Role: in.role, Track: in.track, Fund: in.fund, Period: in.period, Lines: make([]Line, 0, len(in.criteria))}
	for _, c := range in.criteria {
		item, hasItem := in.items[c.ID]
		l := Line{C: c, Evidence: []Evidence{}}
		if in.frozen {
			l.Value = castValue(&c, parseActual(item.Actual))
			if hasItem && len(item.Evidence) > 0 && string(item.Evidence) != "null" {
				_ = json.Unmarshal(item.Evidence, &l.Evidence)
				l.Auto = c.IsAuto()
			}
			l.NA = item.NotApplicable
			if hasItem && !l.NA {
				l.Level = ptr(item.Score)
			}
		} else {
			if c.IsAuto() {
				m, live := in.measured[c.ID]
				if live && m.Live {
					l.Evidence = nonNil(m.Evidence)
					// Nguồn tỉ lệ chưa có dữ liệu trong kỳ → người chấm điền tay.
					l.Auto = m.Value != nil || !c.IsRateSource()
					if l.Auto {
						l.Value = castValue(&c, m.Value)
					}
				} else {
					l.Pending = PendingLabel(c.AutoSource)
				}
			}
			if !l.Auto {
				l.Value = castValue(&c, parseActual(item.Actual))
				// Không phát sinh: người chấm đánh dấu; nguồn tự động không có dữ liệu thì mặc định Không phát sinh.
				if hasItem {
					l.NA = c.AllowNA && item.NotApplicable
				} else {
					l.NA = c.AllowNA && c.IsRateSource()
				}
				if l.NA {
					l.Value = nil
				}
			}
			switch {
			case !c.HasRule():
				// Tiêu chí % cũ không có quy tắc: giữ % đã chấm.
				if hasItem {
					l.Level = ptr(item.Score)
				}
			case l.Value == nil:
			case l.Auto && c.PerMonth && in.period.Months > 1:
				// Phiếu quý: mức đạt từng tháng rồi lấy trung bình.
				sum := 0.0
				keys := in.period.monthKeys()
				for _, k := range keys {
					n := 0
					for _, e := range l.Evidence {
						if e.Counted && e.Month == k {
							n++
						}
					}
					sum += *c.LevelFor(float64(n))
				}
				l.Level = ptr(round2(sum / float64(len(keys))))
			default:
				l.Level = c.LevelFor(*l.Value)
			}
			if item.CriticalError {
				l.Level = ptr(0.0)
			}
			if l.Level != nil && hasCap(l.Evidence) {
				l.Level = ptr(math.Min(*l.Level, CapLevel))
			}
			if !l.Auto && c.Code != "" && !c.IsRate() {
				rows := in.journal[c.Code]
				sg := &Suggestion{Count: len(rows), Evidence: []SuggestionRow{}}
				for _, r := range rows {
					sg.Evidence = append(sg.Evidence, SuggestionRow{Date: r.Date.In(vnZone).Format("02/01"), Text: r.Title})
				}
				l.Suggestion = sg
			}
		}
		l.Cap = hasCap(l.Evidence)
		if in.fund != nil && weightTotal > 0 {
			l.MaxAmount = ptr(*in.fund * c.Weight / weightTotal)
			if l.Level != nil {
				l.Amount = ptr(*l.MaxAmount * *l.Level / 100)
			}
		}
		out.Lines = append(out.Lines, l)
	}

	applicable, weighted, anyCap := 0.0, 0.0, false
	for _, l := range out.Lines {
		if out.Knockout == "" && l.C.Knockout && l.Value != nil && *l.Value > 0 {
			out.Knockout = l.C.Name
		}
		if l.Level == nil && !l.NA {
			out.Missing++
		}
		anyCap = anyCap || l.Cap
		// Mục Không phát sinh bỏ khỏi cả tử số và mẫu số.
		if l.NA {
			continue
		}
		applicable += l.C.Weight
		if l.Level != nil {
			weighted += *l.Level * l.C.Weight
		}
	}
	if applicable > 0 {
		out.Total = round2(weighted / applicable)
	}
	if out.Knockout != "" {
		out.Total = 0
	}
	if in.frozen {
		out.Total = in.frozenTotal
	}
	if HasGrades(in.role, in.period.Months) {
		capGrade := ""
		if anyCap {
			capGrade = CapGrade
		}
		g := GradeFor(out.Total, capGrade)
		out.Grade = &g
		out.BonusFund = in.gradeFund
		if out.BonusFund != nil {
			out.Bonus = ptr(math.Round(*out.BonusFund * float64(g.Pay) / 100))
		}
	}
	if in.fund != nil {
		out.Amount = ptr(math.Round(*in.fund * out.Total / 100))
	}
	return out
}

// sheet: nạp tiêu chí / dòng phiếu / số liệu tự động / nhật ký rồi tính phiếu của nhân sự cho kỳ chứa tháng này.
func (l *loader) sheet(ctx context.Context, st staff, month, year int, rec *sheetRec, items []kpidb.HrKpiItem, track string) (Sheet, error) {
	role := st.roleFor(track)
	p := makePeriod(l.cfg.periodMonths(role), month, year)
	criteria, err := l.activeCriteria(ctx, role)
	if err != nil {
		return Sheet{}, err
	}
	frozen := rec != nil && rec.Status == StatusApproved
	byID := map[uuid.UUID]kpidb.HrKpiItem{}
	for _, it := range items {
		byID[it.CriterionID] = it
	}
	// Phiếu đã duyệt theo bộ tiêu chí trước đây: hiện đúng các tiêu chí đã chấm (phiếu chờ duyệt dùng bộ hiện hành).
	if frozen && len(items) > 0 {
		legacy := slices.ContainsFunc(items, func(it kpidb.HrKpiItem) bool {
			return !slices.ContainsFunc(criteria, func(c Criterion) bool { return c.ID == it.CriterionID })
		})
		if legacy {
			ids := make([]uuid.UUID, 0, len(items))
			for _, it := range items {
				ids = append(ids, it.CriterionID)
			}
			rows, err := l.q.CriteriaByIDs(ctx, ids)
			if err != nil {
				return Sheet{}, err
			}
			criteria = criteria[:0:0]
			for _, r := range rows {
				criteria = append(criteria, fromRow(r))
			}
		}
	}
	in := sheetInput{role: role, track: track, period: p, criteria: criteria, items: byID, frozen: frozen,
		measured: map[uuid.UUID]measured{}, journal: map[string][]kpisrc.JournalEntry{}}
	if frozen {
		in.frozenTotal = rec.TotalScore
	} else {
		for i := range criteria {
			c := &criteria[i]
			if !l.s.live(c) {
				continue
			}
			m, err := l.s.measure(ctx, c.AutoSource, st.ID, p.From, p.To)
			if err != nil {
				return Sheet{}, err
			}
			in.measured[c.ID] = m
		}
		entries, err := l.s.journal.KpiJournal(ctx, st.ID, p.From, p.To)
		if err != nil {
			return Sheet{}, err
		}
		for _, e := range entries {
			in.journal[e.Code] = append(in.journal[e.Code], e)
		}
	}
	if role == iam.RoleAcademicStaff {
		in.fund = ptr(l.cfg.fund)
	}
	in.gradeFund = l.cfg.gradeFund(role)
	return buildSheet(in), nil
}
