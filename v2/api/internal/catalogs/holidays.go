package catalogs

import (
	"context"
	"fmt"
	"strings"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/catalogs/catalogsdb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

// Ngày nghỉ lễ (HolidayController): toàn hệ thống hoặc theo chi nhánh; mã HOL-<năm>-001 tự sinh nếu để trống.

const holidaysPageSize = 15

var errHolidayNotFound = notFound("Không tìm thấy ngày nghỉ.")

type Holiday struct {
	ID           uuid.UUID   `json:"id"`
	Code         string      `json:"code"`
	Name         string      `json:"name"`
	StartDate    string      `json:"start_date"`
	EndDate      string      `json:"end_date"`
	IsSystemWide bool        `json:"is_system_wide"`
	Branches     []string    `json:"branches,omitempty"`
	BranchIDs    []uuid.UUID `json:"branch_ids,omitempty"`
}

type HolidayList struct {
	Page[Holiday]
	// Chi nhánh đang hoạt động cho form Thêm / Sửa
	BranchOptions []BranchOption `json:"branch_options"`
}

func (s *Service) ListHolidays(ctx context.Context, search string, page int) (HolidayList, error) {
	page, lim, off := limits(page, holidaysPageSize)
	rows, err := s.q.ListHolidays(ctx, catalogsdb.ListHolidaysParams{Search: likeArg(search), Lim: lim, Off: off})
	if err != nil {
		return HolidayList{}, err
	}
	var total int64
	items := make([]Holiday, 0, len(rows))
	for _, r := range rows {
		total = r.Total
		items = append(items, Holiday{ID: r.ID, Code: r.Code, Name: r.Name, StartDate: r.StartDate, EndDate: r.EndDate,
			IsSystemWide: r.IsSystemWide, Branches: nonNil(r.BranchNames)})
	}
	branches, err := s.q.ScopeBranches(ctx, nil)
	if err != nil {
		return HolidayList{}, err
	}
	opts := make([]BranchOption, 0, len(branches))
	for _, b := range branches {
		opts = append(opts, BranchOption{ID: b.ID, Code: b.Code, Name: b.Name})
	}
	return HolidayList{Page: newPage(items, total, page, holidaysPageSize), BranchOptions: opts}, nil
}

func (s *Service) GetHoliday(ctx context.Context, id uuid.UUID) (Holiday, error) {
	h, err := s.q.GetHoliday(ctx, id)
	if isNoRows(err) {
		return Holiday{}, errHolidayNotFound
	}
	if err != nil {
		return Holiday{}, err
	}
	return Holiday{ID: h.ID, Code: h.Code, Name: h.Name, StartDate: h.StartDate, EndDate: h.EndDate,
		IsSystemWide: h.IsSystemWide, BranchIDs: nonNil(h.BranchIds)}, nil
}

type HolidayInput struct {
	Code         string      `json:"code"`
	Name         string      `json:"name"`
	StartDate    string      `json:"start_date"`
	EndDate      string      `json:"end_date"`
	IsSystemWide bool        `json:"is_system_wide"`
	BranchIDs    []uuid.UUID `json:"branch_ids"`
}

func (s *Service) validateHoliday(ctx context.Context, in *HolidayInput, except uuid.UUID) (time.Time, error) {
	in.Code = strings.TrimSpace(in.Code)
	in.Name = strings.TrimSpace(in.Name)
	switch {
	case tooLong(in.Code, 50):
		return time.Time{}, iam.Invalid("Mã ngày nghỉ không được vượt quá 50 ký tự.")
	case in.Name == "":
		return time.Time{}, iam.Invalid("Vui lòng nhập tên ngày nghỉ.")
	case tooLong(in.Name, 255):
		return time.Time{}, iam.Invalid("Tên ngày nghỉ không được vượt quá 255 ký tự.")
	case in.StartDate == "":
		return time.Time{}, iam.Invalid("Vui lòng nhập ngày bắt đầu.")
	case in.EndDate == "":
		return time.Time{}, iam.Invalid("Vui lòng nhập ngày kết thúc.")
	}
	start, err := time.Parse(time.DateOnly, in.StartDate)
	if err != nil {
		return start, iam.Invalid("Ngày bắt đầu không hợp lệ.")
	}
	end, err := time.Parse(time.DateOnly, in.EndDate)
	if err != nil {
		return start, iam.Invalid("Ngày kết thúc không hợp lệ.")
	}
	if end.Before(start) {
		return start, iam.Invalid("Ngày kết thúc phải sau hoặc bằng ngày bắt đầu.")
	}
	for _, b := range in.BranchIDs {
		if ok, err := s.q.BranchExists(ctx, b); err != nil {
			return start, err
		} else if !ok {
			return start, iam.Invalid("Chi nhánh đã chọn không tồn tại trong hệ thống.")
		}
	}
	if in.Code != "" {
		if taken, err := s.q.HolidayCodeTaken(ctx, catalogsdb.HolidayCodeTakenParams{Code: in.Code, Except: except}); err != nil {
			return start, err
		} else if taken {
			return start, iam.Invalid("Mã ngày nghỉ đã tồn tại trên hệ thống, vui lòng chọn mã khác.")
		}
	}
	// Không chọn chi nhánh nào = áp dụng toàn hệ thống.
	in.IsSystemWide = in.IsSystemWide || len(in.BranchIDs) == 0
	if in.IsSystemWide {
		in.BranchIDs = nil
	}
	return start, nil
}

func holidayCodeErr(err error) error {
	if isUnique(err, "holidays_code_key") {
		return iam.Invalid("Mã ngày nghỉ đã tồn tại trên hệ thống, vui lòng chọn mã khác.")
	}
	return err
}

func (s *Service) CreateHoliday(ctx context.Context, actor *iam.Access, in HolidayInput) (uuid.UUID, string, error) {
	start, err := s.validateHoliday(ctx, &in, uuid.Nil)
	if err != nil {
		return uuid.Nil, "", err
	}
	var id uuid.UUID
	err = s.tx(ctx, func(q *catalogsdb.Queries) error {
		code := in.Code
		if code == "" {
			year := fmt.Sprint(start.Year())
			if err := q.LockKey(ctx, "core.holiday_code"); err != nil {
				return err
			}
			n, err := q.NextHolidayNumber(ctx, year)
			if err != nil {
				return err
			}
			code = fmt.Sprintf("HOL-%s-%03d", year, n)
		}
		var err error
		id, err = q.CreateHoliday(ctx, catalogsdb.CreateHolidayParams{
			Code: code, Name: in.Name, StartDate: in.StartDate, EndDate: in.EndDate, IsSystemWide: in.IsSystemWide, CreatedBy: &actor.UserID,
		})
		if err != nil {
			return err
		}
		if len(in.BranchIDs) > 0 {
			return q.AddHolidayBranches(ctx, catalogsdb.AddHolidayBranchesParams{HolidayID: id, BranchIds: in.BranchIDs})
		}
		return nil
	})
	if err != nil {
		return uuid.Nil, "", holidayCodeErr(err)
	}
	// TODO(audit): "Tạo ngày nghỉ mới"
	s.log.Info("holiday created", "holiday_id", id, "by", actor.UserID)
	summary, err := s.applyHoliday(ctx, id, in)
	if err != nil {
		return id, "", err
	}
	return id, "Đã thêm ngày nghỉ." + summary, nil
}

// applyHoliday: hủy buổi học trùng ngày nghỉ, xếp bù cuối lịch (HolidayRescheduleService::apply); trả phần tóm tắt.
func (s *Service) applyHoliday(ctx context.Context, id uuid.UUID, in HolidayInput) (string, error) {
	if s.Classes == nil {
		return "", nil
	}
	start, _ := time.Parse(time.DateOnly, in.StartDate)
	end, _ := time.Parse(time.DateOnly, in.EndDate)
	sum, err := s.Classes.HolidayApplied(ctx, HolidayScope{
		ID: id, Name: in.Name, StartDate: start, EndDate: end, SystemWide: in.IsSystemWide, BranchIDs: in.BranchIDs,
	})
	if err != nil {
		return "", err
	}
	return sum.Text(), nil
}

func (s *Service) UpdateHoliday(ctx context.Context, actor *iam.Access, id uuid.UUID, in HolidayInput) (string, error) {
	old, err := s.q.GetHoliday(ctx, id)
	if isNoRows(err) {
		return "", errHolidayNotFound
	}
	if err != nil {
		return "", err
	}
	if _, err := s.validateHoliday(ctx, &in, id); err != nil {
		return "", err
	}
	code := in.Code
	if code == "" {
		code = old.Code
	}
	err = s.tx(ctx, func(q *catalogsdb.Queries) error {
		n, err := q.UpdateHoliday(ctx, catalogsdb.UpdateHolidayParams{
			ID: id, Code: code, Name: in.Name, StartDate: in.StartDate, EndDate: in.EndDate, IsSystemWide: in.IsSystemWide,
		})
		if err != nil {
			return err
		}
		if n == 0 {
			return errHolidayNotFound
		}
		if err := q.ClearHolidayBranches(ctx, id); err != nil {
			return err
		}
		if len(in.BranchIDs) > 0 {
			return q.AddHolidayBranches(ctx, catalogsdb.AddHolidayBranchesParams{HolidayID: id, BranchIds: in.BranchIDs})
		}
		return nil
	})
	if err != nil {
		return "", holidayCodeErr(err)
	}
	// TODO(audit): "Cập nhật ngày nghỉ"
	s.log.Info("holiday updated", "holiday_id", id, "by", actor.UserID)
	summary, err := s.applyHoliday(ctx, id, in)
	if err != nil {
		return "", err
	}
	return "Đã cập nhật ngày nghỉ." + summary, nil
}

func (s *Service) DeleteHoliday(ctx context.Context, actor *iam.Access, id uuid.UUID) (string, error) {
	n, err := s.q.DeleteHoliday(ctx, id)
	if err != nil {
		return "", err
	}
	if n == 0 {
		return "", errHolidayNotFound
	}
	// TODO(audit): "Xóa ngày nghỉ"
	s.log.Info("holiday deleted", "holiday_id", id, "by", actor.UserID)
	msg := "Đã xóa ngày nghỉ."
	// Khôi phục buổi học bị hủy do ngày nghỉ này và gỡ buổi bù chưa diễn ra (HolidayRescheduleService::release).
	if s.Classes != nil {
		restored, err := s.Classes.HolidayReleased(ctx, id)
		if err != nil {
			return "", err
		}
		if restored > 0 {
			msg += fmt.Sprintf(" Đã khôi phục %d buổi học bị hủy do ngày nghỉ này (buổi bù tương ứng đã được gỡ).", restored)
		}
	}
	return msg, nil
}

// HolidaysBetween: ngày nghỉ áp dụng cho chi nhánh trong khoảng ngày (dùng cho xếp lịch lớp, tra phòng trống).
func (s *Service) HolidaysBetween(ctx context.Context, branch uuid.UUID, from, to time.Time) ([]Holiday, error) {
	rows, err := s.q.HolidaysBetween(ctx, catalogsdb.HolidaysBetweenParams{
		BranchID: branch, FromDate: from.Format(time.DateOnly), ToDate: to.Format(time.DateOnly),
	})
	if err != nil {
		return nil, err
	}
	out := make([]Holiday, 0, len(rows))
	for _, r := range rows {
		out = append(out, Holiday{ID: r.ID, Code: r.Code, Name: r.Name, StartDate: r.StartDate, EndDate: r.EndDate})
	}
	return out, nil
}
