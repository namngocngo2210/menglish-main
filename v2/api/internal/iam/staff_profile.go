package iam

import (
	"context"
	"errors"
	"fmt"
	"slices"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgtype"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam/iamdb"
)

// Hồ sơ nhân sự: tab "Hồ sơ" và "Hợp đồng & Lương" của form Người dùng (UserController / UserRequest của Laravel).

// ContractTypes: loại hợp đồng gợi ý trên form (UserFields.vue); API nhận chuỗi bất kỳ tối đa 50 ký tự như Laravel.
var ContractTypes = []string{"Toàn thời gian", "Bán thời gian", "Thử việc", "Cộng tác viên"}

// contractWarningKey: SLA "Cảnh báo hợp đồng sắp hết hạn" (số ngày), mặc định 30 như config/sla.php.
const (
	contractWarningKey     = "hr.contract_warning"
	contractWarningDefault = 30
)

var vnZone = time.FixedZone("Asia/Ho_Chi_Minh", 7*3600)

// Badge: nhãn + màu (success | warning | error | neutral).
type Badge struct {
	Label string `json:"label"`
	Tone  string `json:"tone"`
}

// StaffProfile: hồ sơ trả cho màn hình. Trường nhạy cảm (CCCD, quê quán, nơi ở, liên lạc khẩn cấp, lương cơ bản,
// thù lao giờ) là null khi người xem không có quyền (CanViewSensitive = false), như canViewSensitive của Laravel.
type StaffProfile struct {
	CanViewSensitive  bool       `json:"can_view_sensitive"`
	IDCardNumber      *string    `json:"id_card_number"`
	Hometown          *string    `json:"hometown"`
	CurrentAddress    *string    `json:"current_address"`
	EmergencyContact  *string    `json:"emergency_contact"`
	GraduationSchool  *string    `json:"graduation_school"`
	Certificates      *string    `json:"certificates"`
	TeachingLevel     *string    `json:"teaching_level"`
	ContractType      *string    `json:"contract_type"`
	ContractStartDate *string    `json:"contract_start_date"`
	ContractEndDate   *string    `json:"contract_end_date"`
	BaseSalary        *int64     `json:"base_salary"`
	HourlyRate        *int64     `json:"hourly_rate"`
	AcademicTeaching  bool       `json:"academic_teaching"`
	AcademicLead      bool       `json:"academic_lead"` // có vai trò Học thuật: mới hiện ô "Kiêm nhiệm giảng dạy"
	ContractStatus    Badge      `json:"contract_status"`
	ContractRemaining *Badge     `json:"contract_remaining"`
	ContractTypes     []string   `json:"contract_types"`
	UpdatedAt         *time.Time `json:"updated_at"`
}

// StaffProfileInput: thân PUT /users/{id}/profile. Ngày dạng YYYY-MM-DD, trống = bỏ. Người không xem được trường
// nhạy cảm gửi lên cũng bị bỏ qua, giữ giá trị cũ.
type StaffProfileInput struct {
	IDCardNumber      string `json:"id_card_number"`
	Hometown          string `json:"hometown"`
	CurrentAddress    string `json:"current_address"`
	EmergencyContact  string `json:"emergency_contact"`
	GraduationSchool  string `json:"graduation_school"`
	Certificates      string `json:"certificates"`
	TeachingLevel     string `json:"teaching_level"`
	ContractType      string `json:"contract_type"`
	ContractStartDate string `json:"contract_start_date"`
	ContractEndDate   string `json:"contract_end_date"`
	BaseSalary        *int64 `json:"base_salary"`
	HourlyRate        *int64 `json:"hourly_rate"`
	AcademicTeaching  bool   `json:"academic_teaching"`
}

// CanViewSensitive: người xem được CCCD, lương cơ bản, thù lao của nhân sự khác: có payroll.view và xem được mọi
// phiếu lương (UserController::canViewSensitive).
func CanViewSensitive(a *Access) bool {
	return a != nil && a.Can("payroll.view") && a.Level("payroll") == ScopeAll
}

// ContractStatus: trạng thái hợp đồng theo ngày hôm nay (User::contractExpiryStatus + nhãn của Users/Show).
func ContractStatus(contractType *string, end *time.Time, today time.Time, warnDays int) (Badge, *Badge) {
	if (contractType == nil || *contractType == "") && end == nil {
		return Badge{"Chưa cập nhật", "neutral"}, nil
	}
	if end == nil {
		return Badge{"Đang hiệu lực", "success"}, nil
	}
	day := func(t time.Time) time.Time { return time.Date(t.Year(), t.Month(), t.Day(), 0, 0, 0, 0, time.UTC) }
	e, d := day(*end), day(today)
	switch {
	case e.Before(d):
		return Badge{"Đã hết hạn", "error"}, &Badge{"Đã hết hạn", "error"}
	case !e.After(d.AddDate(0, 0, warnDays)):
		left := int(e.Sub(d).Hours() / 24)
		return Badge{"Sắp hết hạn", "warning"}, &Badge{fmt.Sprintf("Còn %d ngày", left), "warning"}
	default:
		return Badge{"Đang hiệu lực", "success"}, &Badge{"Còn hiệu lực", "success"}
	}
}

func (s *Service) contractWarningDays(ctx context.Context) int {
	v, err := s.q.GetSlaValue(ctx, contractWarningKey)
	if err != nil || v < 0 {
		return contractWarningDefault
	}
	return int(v)
}

func dateString(d pgtype.Date) *string {
	if !d.Valid {
		return nil
	}
	v := d.Time.Format(time.DateOnly)
	return &v
}

func dateTime(d pgtype.Date) *time.Time {
	if !d.Valid {
		return nil
	}
	return &d.Time
}

func (s *Service) staffProfile(ctx context.Context, actor *Access, t *target, p iamdb.IamStaffProfile, found bool) StaffProfile {
	out := StaffProfile{
		CanViewSensitive: CanViewSensitive(actor), GraduationSchool: p.GraduationSchool, Certificates: p.Certificates,
		TeachingLevel: p.TeachingLevel, ContractType: p.ContractType, ContractStartDate: dateString(p.ContractStartDate),
		ContractEndDate: dateString(p.ContractEndDate), AcademicLead: slices.Contains(t.roles, RoleAcademicLead),
		ContractTypes: ContractTypes,
	}
	out.AcademicTeaching = out.AcademicLead && p.AcademicTeaching
	if out.CanViewSensitive {
		out.IDCardNumber, out.Hometown, out.CurrentAddress, out.EmergencyContact = p.IDCardNumber, p.Hometown, p.CurrentAddress, p.EmergencyContact
		out.BaseSalary, out.HourlyRate = &p.BaseSalary, &p.HourlyRate
	}
	if found {
		out.UpdatedAt = &p.UpdatedAt
	}
	out.ContractStatus, out.ContractRemaining = ContractStatus(p.ContractType, dateTime(p.ContractEndDate), s.now().In(vnZone), s.contractWarningDays(ctx))
	return out
}

func (s *Service) loadStaffProfile(ctx context.Context, q *iamdb.Queries, id uuid.UUID) (iamdb.IamStaffProfile, bool, error) {
	p, err := q.GetStaffProfile(ctx, id)
	if errors.Is(err, pgx.ErrNoRows) {
		return iamdb.IamStaffProfile{UserID: id}, false, nil
	}
	return p, err == nil, err
}

// GetStaffProfile: hồ sơ nhân sự của tài khoản actor quản lý được (cùng phạm vi màn Người dùng).
func (s *Service) GetStaffProfile(ctx context.Context, actor *Access, id uuid.UUID) (StaffProfile, error) {
	t, err := s.managedTarget(ctx, actor, id)
	if err != nil {
		return StaffProfile{}, err
	}
	if t.user.Kind != "staff" {
		return StaffProfile{}, invalid("Chỉ tài khoản nhân viên có hồ sơ nhân sự.")
	}
	p, found, err := s.loadStaffProfile(ctx, s.q, id)
	if err != nil {
		return StaffProfile{}, err
	}
	return s.staffProfile(ctx, actor, t, p, found), nil
}

// textField: cắt khoảng trắng, trống = NULL, kiểm độ dài như rule max của UserRequest.
func textField(v, label string, max int) (*string, error) {
	v = strings.TrimSpace(v)
	if v == "" {
		return nil, nil
	}
	if utf8.RuneCountInString(v) > max {
		return nil, invalid(fmt.Sprintf("%s tối đa %d ký tự.", label, max))
	}
	return &v, nil
}

func dateField(v, label string) (pgtype.Date, error) {
	v = strings.TrimSpace(v)
	if v == "" {
		return pgtype.Date{}, nil
	}
	t, err := time.Parse(time.DateOnly, v)
	if err != nil {
		return pgtype.Date{}, invalid(label + " không hợp lệ.")
	}
	return pgtype.Date{Time: t, Valid: true}, nil
}

func (in StaffProfileInput) params(id uuid.UUID, old iamdb.IamStaffProfile, sensitive, academicLead bool) (iamdb.UpsertStaffProfileParams, error) {
	p := iamdb.UpsertStaffProfileParams{
		UserID: id, IDCardNumber: old.IDCardNumber, Hometown: old.Hometown, CurrentAddress: old.CurrentAddress,
		EmergencyContact: old.EmergencyContact, BaseSalary: old.BaseSalary, HourlyRate: old.HourlyRate,
		AcademicTeaching: academicLead && in.AcademicTeaching,
	}
	type field struct {
		dst   **string
		v     string
		label string
		max   int
	}
	fields := []field{
		{&p.GraduationSchool, in.GraduationSchool, "Tốt nghiệp", 255},
		{&p.Certificates, in.Certificates, "Chứng chỉ", 255},
		{&p.TeachingLevel, in.TeachingLevel, "Level giảng dạy / Chuyên môn", 255},
		{&p.ContractType, in.ContractType, "Loại hợp đồng", 50},
	}
	if sensitive {
		fields = append(fields,
			field{&p.IDCardNumber, in.IDCardNumber, "Số CCCD", 30},
			field{&p.Hometown, in.Hometown, "Quê quán", 255},
			field{&p.CurrentAddress, in.CurrentAddress, "Nơi ở hiện tại", 255},
			field{&p.EmergencyContact, in.EmergencyContact, "Liên lạc khẩn cấp", 255},
		)
	}
	for _, f := range fields {
		v, err := textField(f.v, f.label, f.max)
		if err != nil {
			return p, err
		}
		*f.dst = v
	}
	var err error
	if p.ContractStartDate, err = dateField(in.ContractStartDate, "Ngày bắt đầu hợp đồng"); err != nil {
		return p, err
	}
	if p.ContractEndDate, err = dateField(in.ContractEndDate, "Ngày kết thúc hợp đồng"); err != nil {
		return p, err
	}
	if sensitive {
		// Ô lương để trống → 0 (UserRequest::prepareForValidation)
		for _, m := range []struct {
			dst   *int64
			v     *int64
			label string
		}{{&p.BaseSalary, in.BaseSalary, "Lương cơ bản"}, {&p.HourlyRate, in.HourlyRate, "Thù lao theo giờ"}} {
			*m.dst = 0
			if m.v != nil {
				if *m.v < 0 {
					return p, invalid(m.label + " không được âm.")
				}
				*m.dst = *m.v
			}
		}
	}
	return p, nil
}

// profileSnapshot: giá trị so trước / sau cho nhật ký vận hành (CCCD tự che ***).
func profileSnapshot(p iamdb.IamStaffProfile) map[string]any {
	str := func(v *string) any {
		if v == nil {
			return nil
		}
		return *v
	}
	date := func(d pgtype.Date) any {
		if s := dateString(d); s != nil {
			return *s
		}
		return nil
	}
	return map[string]any{
		"id_card_number": str(p.IDCardNumber), "hometown": str(p.Hometown), "current_address": str(p.CurrentAddress),
		"emergency_contact": str(p.EmergencyContact), "graduation_school": str(p.GraduationSchool),
		"certificates": str(p.Certificates), "teaching_level": str(p.TeachingLevel), "contract_type": str(p.ContractType),
		"contract_start_date": date(p.ContractStartDate), "contract_end_date": date(p.ContractEndDate),
		"base_salary": p.BaseSalary, "hourly_rate": p.HourlyRate, "academic_teaching": p.AcademicTeaching,
	}
}

// UpdateStaffProfile lưu hồ sơ nhân sự; người không xem được lương chỉ sửa được phần không nhạy cảm.
func (s *Service) UpdateStaffProfile(ctx context.Context, actor *Access, id uuid.UUID, in StaffProfileInput) (StaffProfile, error) {
	t, err := s.managedTarget(ctx, actor, id)
	if err != nil {
		return StaffProfile{}, err
	}
	if t.user.Kind != "staff" {
		return StaffProfile{}, invalid("Chỉ tài khoản nhân viên có hồ sơ nhân sự.")
	}
	var saved iamdb.IamStaffProfile
	err = pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error {
		q := s.q.WithTx(tx)
		old, _, err := s.loadStaffProfile(ctx, q, id)
		if err != nil {
			return err
		}
		params, err := in.params(id, old, CanViewSensitive(actor), slices.Contains(t.roles, RoleAcademicLead))
		if err != nil {
			return err
		}
		params.UpdatedBy = &actor.UserID
		if saved, err = q.UpsertStaffProfile(ctx, params); err != nil {
			return err
		}
		changes := audit.Diff(profileSnapshot(old), profileSnapshot(saved))
		if len(changes) == 0 {
			return nil
		}
		return recordTx(ctx, tx, actor, userEntry(t, audit.EventUpdated, "user.update", "Cập nhật hồ sơ nhân sự", changes))
	})
	if err != nil {
		return StaffProfile{}, err
	}
	s.log.Info("staff profile updated", "user_id", id, "by", actor.UserID)
	return s.staffProfile(ctx, actor, t, saved, true), nil
}
