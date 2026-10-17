package payroll

import (
	"context"
	"fmt"
	"strings"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/payroll/payrolldb"
)

// Nhân sự giảng dạy (Roles::TEACHING + Học thuật + Quản lý cơ sở) — màn Đơn giá giáo viên.
var teachingRoles = []string{"teacher_fulltime", "teacher_parttime", "assistant", "academic_lead", "manager"}

var unitSuffix = map[string]string{"session": "VNĐ / buổi", "hour": "VNĐ / giờ", "tuition": "% học phí / buổi"}

type RankRate struct {
	ID                uuid.UUID `json:"id"`
	RankTitle         string    `json:"rank_title"`
	Criteria          *string   `json:"criteria"`
	CommunicationRate int64     `json:"communication_rate"`
	IeltsRate         int64     `json:"ielts_rate"`
}

type CurrentRate struct {
	Rate          float64 `json:"rate"`
	UnitLabel     string  `json:"unit_label"`
	EffectiveFrom string  `json:"effective_from"`
}

type TeacherRow struct {
	ID          uuid.UUID    `json:"id"`
	Name        string       `json:"name"`
	Code        string       `json:"employee_code"`
	Search      string       `json:"search"`
	Current     *CurrentRate `json:"current"`
	ProfileRate int64        `json:"profile_rate"`
}

type RateHistoryRow struct {
	ID               uuid.UUID `json:"id"`
	UserID           uuid.UUID `json:"user_id"`
	UserName         string    `json:"user_name"`
	TeacherTypeLabel string    `json:"teacher_type_label"`
	Rate             float64   `json:"hourly_rate"`
	Unit             string    `json:"unit"`
	UnitLabel        string    `json:"unit_label"`
	RateUnit         string    `json:"rate_unit"`
	EffectiveFrom    string    `json:"effective_from"`
	End              *string   `json:"end"`
	StateLabel       string    `json:"state_label"`
	StateColor       string    `json:"state_color"`
	Note             *string   `json:"note"`
	Creator          string    `json:"creator"`
	CreatedAt        string    `json:"created_at"`
}

type SelectedTeacher struct {
	ID          uuid.UUID         `json:"id"`
	Name        string            `json:"name"`
	Code        string            `json:"employee_code"`
	TypeLabel   string            `json:"type_label"`
	Type        string            `json:"type"`
	Current     map[string]string `json:"current"`
	ProfileRate *string           `json:"profile_rate"`
	History     []RateHistoryRow  `json:"history"`
}

type RatesPage struct {
	Rates        []RankRate       `json:"rates"`
	Teachers     []TeacherRow     `json:"teachers"`
	History      []RateHistoryRow `json:"history"`
	Paging       Paging           `json:"paging"`
	Selected     *SelectedTeacher `json:"selected_teacher"`
	TeacherTypes []Option         `json:"teacher_types"`
	DefaultRate  string           `json:"default_rate"`
	Today        string           `json:"today"`
	TodayDate    string           `json:"today_date"`
}

func (s *Service) historyRow(r payrolldb.HourlyRateHistoryRow, today time.Time) RateHistoryRow {
	row := RateHistoryRow{ID: r.ID, UserID: r.UserID, UserName: r.UserName, Rate: r.Rate, Unit: unitSuffix[r.RateUnit],
		UnitLabel: rateUnitLabels[r.RateUnit], RateUnit: r.RateUnit, EffectiveFrom: dmy(r.EffectiveFrom), Note: r.Note,
		Creator: "Hệ thống", CreatedAt: r.CreatedAt.In(vnZone).Format("02/01/2006 15:04"), TeacherTypeLabel: "—"}
	if r.TeacherType != nil {
		row.TeacherTypeLabel = labelOf(teacherTypes, *r.TeacherType)
	}
	if r.CreatorName != nil {
		row.Creator = *r.CreatorName
	}
	var end *time.Time
	if r.NextFrom.Year() < 9999 {
		e := asDay(r.NextFrom).AddDate(0, 0, -1)
		end = &e
		row.End = ptr(e.Format("02/01/2006"))
	}
	switch {
	case asDay(r.EffectiveFrom).After(today):
		row.StateLabel, row.StateColor = "Chưa hiệu lực", "info"
	case end != nil && end.Before(today):
		row.StateLabel, row.StateColor = "Đã hết hạn", "neutral"
	default:
		row.StateLabel, row.StateColor = "Đang áp dụng", "success"
	}
	return row
}

func memberOf(r payrolldb.StaffWithRolesRow) staffMember {
	return staffMember{ID: r.ID, Name: r.FullName, Code: r.Code, Roles: r.Roles, ContractType: r.ContractType, BaseSalary: r.BaseSalary,
		HourlyRate: r.HourlyRate, AcademicTeaching: r.AcademicTeaching}
}

func (s *Service) RatesPage(ctx context.Context, teacherID *uuid.UUID, page int) (*RatesPage, error) {
	today := s.today()
	ranks, err := s.q.ListTeacherRates(ctx)
	if err != nil {
		return nil, err
	}
	staff, err := s.q.StaffWithRoles(ctx, teachingRoles)
	if err != nil {
		return nil, err
	}
	ids := make([]uuid.UUID, 0, len(staff))
	for _, t := range staff {
		ids = append(ids, t.ID)
	}
	versions, err := s.q.HourlyRatesFor(ctx, ids)
	if err != nil {
		return nil, err
	}
	book := newRateBook(versions)
	page = pageOf(page)
	const per = 15
	hist, err := s.q.HourlyRateHistory(ctx, payrolldb.HourlyRateHistoryParams{Lim: per, Off: int32((page - 1) * per)})
	if err != nil {
		return nil, err
	}
	total, err := s.q.CountHourlyRates(ctx, nil)
	if err != nil {
		return nil, err
	}
	out := &RatesPage{Rates: []RankRate{}, Teachers: []TeacherRow{}, History: []RateHistoryRow{}, Paging: paging(page, per, total),
		TeacherTypes: teacherTypes, DefaultRate: money(defaultHourlyRate), Today: today.Format("02/01/2006"),
		TodayDate: today.Format("2006-01-02")}
	for _, r := range ranks {
		out.Rates = append(out.Rates, RankRate{ID: r.ID, RankTitle: r.RankTitle, Criteria: r.Criteria, CommunicationRate: r.CommunicationRate,
			IeltsRate: r.IeltsRate})
	}
	for _, h := range hist {
		out.History = append(out.History, s.historyRow(h, today))
	}
	for _, t := range staff {
		row := TeacherRow{ID: t.ID, Name: t.FullName, Code: t.Code, ProfileRate: t.HourlyRate,
			Search: strings.ToLower(t.FullName + " " + t.Code + " " + t.Email)}
		if c := book.effective(t.ID, today); c != nil {
			row.Current = &CurrentRate{Rate: c.Rate, UnitLabel: rateUnitLabels[c.RateUnit], EffectiveFrom: dmy(c.EffectiveFrom)}
		}
		out.Teachers = append(out.Teachers, row)
		if teacherID != nil && t.ID == *teacherID {
			m := memberOf(t)
			typ := defaultTeacherType(m)
			c := book.effective(t.ID, today)
			if c != nil && c.TeacherType != nil {
				typ = *c.TeacherType
			}
			sel := &SelectedTeacher{ID: t.ID, Name: t.FullName, Code: t.Code, Type: typ, TypeLabel: labelOf(teacherTypes, typ),
				History: []RateHistoryRow{}}
			if c != nil {
				rate := groupThousands(roundVND(c.Rate))
				if c.RateUnit == "tuition" {
					rate = percentLabel(c.Rate)
				}
				sel.Current = map[string]string{"rate": rate + " " + unitSuffix[c.RateUnit], "effective_from": dmy(c.EffectiveFrom)}
			}
			if t.HourlyRate > 0 {
				sel.ProfileRate = ptr(money(t.HourlyRate))
			}
			th, err := s.q.HourlyRateHistory(ctx, payrolldb.HourlyRateHistoryParams{UserID: &t.ID, Lim: 500})
			if err != nil {
				return nil, err
			}
			for _, h := range th {
				sel.History = append(sel.History, s.historyRow(h, today))
			}
			out.Selected = sel
		}
	}
	return out, nil
}

// PersonalRateInput: thêm phiên bản đơn giá riêng của một GV.
type PersonalRateInput struct {
	UserID        string   `json:"user_id"`
	Rate          *float64 `json:"hourly_rate"`
	RateUnit      string   `json:"rate_unit"`
	TeacherType   string   `json:"teacher_type"`
	EffectiveFrom string   `json:"effective_from"`
	Note          *string  `json:"note"`
}

func (s *Service) lockedMessage(day time.Time) string {
	return "Ngày " + dmy(day) + " thuộc kỳ lương đã duyệt/đã chi trả — không thể ghi nhận hoặc thay đổi dữ liệu lương cho ngày này."
}

func (s *Service) AddPersonalRate(ctx context.Context, a *iam.Access, in PersonalRateInput) (string, error) {
	uid, err := uuid.Parse(strings.TrimSpace(in.UserID))
	if err != nil {
		return "", iam.Invalid("Vui lòng chọn giáo viên.")
	}
	unit := in.RateUnit
	if unit == "" {
		unit = "hour"
	}
	if _, ok := rateUnitLabels[unit]; !ok {
		return "", iam.Invalid("Đơn vị đơn giá không hợp lệ.")
	}
	if in.Rate == nil {
		return "", iam.Invalid("Vui lòng nhập đơn giá.")
	}
	if unit == "tuition" {
		if *in.Rate < 0.1 || *in.Rate > 100 {
			return "", iam.Invalid("% học phí phải từ 0,1 đến 100.")
		}
	} else if *in.Rate < 1000 {
		return "", iam.Invalid("Đơn giá tối thiểu 1.000đ.")
	}
	if in.TeacherType != "" && labelOf(teacherTypes, in.TeacherType) == in.TeacherType {
		return "", iam.Invalid("Loại giáo viên không hợp lệ.")
	}
	from, err := parseDayParam(in.EffectiveFrom)
	if err != nil || from == nil {
		return "", iam.Invalid("Vui lòng chọn ngày hiệu lực.")
	}
	note := trimPtr(in.Note)
	if note != nil && runeLen(*note) > 500 {
		return "", iam.Invalid("Ghi chú tối đa 500 ký tự.")
	}
	users, err := s.q.UsersBrief(ctx, []uuid.UUID{uid})
	if err != nil {
		return "", err
	}
	if len(users) == 0 {
		return "", iam.Invalid("Giáo viên không tồn tại.")
	}
	u := users[0]
	var msg string
	err = s.tx(ctx, func(tx pgxTx, q *payrolldb.Queries) error {
		if dup, err := q.HourlyRateExists(ctx, payrolldb.HourlyRateExistsParams{UserID: uid, EffectiveFrom: *from}); err != nil {
			return err
		} else if dup {
			return iam.Invalid("Giáo viên đã có đơn giá hiệu lực từ ngày này — chọn ngày hiệu lực khác để tạo phiên bản mới.")
		}
		if locked, err := q.LockedOn(ctx, *from); err != nil {
			return err
		} else if locked {
			return iam.Invalid(s.lockedMessage(*from))
		}
		typ := in.TeacherType
		if typ == "" {
			sm, err := q.StaffWithRoles(ctx, u.Roles)
			if err != nil {
				return err
			}
			m := staffMember{ID: uid, Roles: u.Roles}
			for _, x := range sm {
				if x.ID == uid {
					m = memberOf(x)
				}
			}
			typ = defaultTeacherType(m)
		}
		r, err := q.InsertHourlyRate(ctx, payrolldb.InsertHourlyRateParams{UserID: uid, Rate: *in.Rate, RateUnit: unit, TeacherType: &typ,
			EffectiveFrom: *from, Note: note, CreatedBy: actorPtr(a)})
		if err != nil {
			return err
		}
		label := groupThousands(roundVND(*in.Rate))
		if unit == "tuition" {
			label = percentLabel(*in.Rate)
		}
		msg = fmt.Sprintf("Đã thêm đơn giá %s %s hiệu lực từ %s.", label, rateUnitLabels[unit], dmy(*from))
		return audit.Record(ctx, tx, audit.Entry{ActorID: actorPtr(a), Module: audit.ModulePayroll, Event: audit.EventCreated,
			Action: "teacher_rate.personal", SubjectType: "teacher_rate", SubjectID: r.ID.String(), SubjectLabel: u.FullName,
			Description: "Thêm đơn giá riêng (" + rateUnitLabels[unit] + ") cho GV " + u.FullName,
			Properties:  map[string]any{"rate": *in.Rate, "rate_unit": unit, "effective_from": dmy(*from), "teacher_type": typ}})
	})
	if err != nil {
		return "", err
	}
	s.log.Info("teacher rate added", "by", a.UserID, "teacher", uid)
	return msg, nil
}

// RankRatesInput: lưu bảng đơn giá theo cấp bậc (thay toàn bộ bảng).
type RankRatesInput struct {
	Rates []struct {
		RankTitle         string   `json:"rank_title"`
		Criteria          *string  `json:"criteria"`
		CommunicationRate *float64 `json:"communication_rate"`
		IeltsRate         *float64 `json:"ielts_rate"`
	} `json:"rates"`
}

func (s *Service) SaveRankRates(ctx context.Context, a *iam.Access, in RankRatesInput) (string, error) {
	if len(in.Rates) > 50 {
		return "", iam.Invalid("Bảng đơn giá tối đa 50 cấp bậc.")
	}
	for _, r := range in.Rates {
		if strings.TrimSpace(r.RankTitle) == "" {
			return "", iam.Invalid("Vui lòng nhập tên cấp bậc.")
		}
		if runeLen(r.RankTitle) > 255 {
			return "", iam.Invalid("Tên cấp bậc tối đa 255 ký tự.")
		}
		if r.CommunicationRate == nil || r.IeltsRate == nil {
			return "", iam.Invalid("Vui lòng nhập đơn giá Giao tiếp và IELTS.")
		}
		if *r.CommunicationRate < 0 || *r.IeltsRate < 0 {
			return "", iam.Invalid("Đơn giá không được âm.")
		}
		if c := trimPtr(r.Criteria); c != nil && runeLen(*c) > 1000 {
			return "", iam.Invalid("Tiêu chí tối đa 1000 ký tự.")
		}
	}
	err := s.tx(ctx, func(tx pgxTx, q *payrolldb.Queries) error {
		if err := q.DeleteTeacherRates(ctx); err != nil {
			return err
		}
		for i, r := range in.Rates {
			if err := q.InsertTeacherRate(ctx, payrolldb.InsertTeacherRateParams{RankTitle: strings.TrimSpace(r.RankTitle),
				Criteria: trimPtr(r.Criteria), CommunicationRate: roundVND(*r.CommunicationRate), IeltsRate: roundVND(*r.IeltsRate),
				SortOrder: int32(i)}); err != nil {
				return err
			}
		}
		return audit.Record(ctx, tx, audit.Entry{ActorID: actorPtr(a), Module: audit.ModulePayroll, Action: "teacher_rate.ranks",
			SubjectType: "teacher_rate", SubjectID: "ranks", SubjectLabel: "Bảng đơn giá theo cấp bậc",
			Description: fmt.Sprintf("Cập nhật bảng đơn giá giờ dạy (%d cấp bậc)", len(in.Rates))})
	})
	if err != nil {
		return "", err
	}
	s.log.Info("teacher rank rates saved", "by", a.UserID, "rows", len(in.Rates))
	return "Đã lưu đơn giá giờ dạy mới!", nil
}

// LockedOn: ngày thuộc kỳ lương đã duyệt / đã chi trả (PayrollPeriod::isLockedFor) — cho phân hệ Chấm công, KPI.
func (s *Service) LockedOn(ctx context.Context, day time.Time) (bool, error) {
	return s.q.LockedOn(ctx, dbDay(day))
}

// LockedMessage: thông báo khi ghi dữ liệu vào ngày thuộc kỳ đã khóa.
func (s *Service) LockedMessage(day time.Time) string { return s.lockedMessage(dbDay(day)) }
