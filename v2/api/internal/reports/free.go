package reports

import (
	"context"
	"strings"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/reports/reportsdb"
)

// Page là thông tin phân trang trả kèm danh sách.
type Page struct {
	Page     int   `json:"page"`
	PerPage  int   `json:"per_page"`
	Total    int64 `json:"total"`
	LastPage int   `json:"last_page"`
}

func newPage(page, per int, total int64) Page {
	last := int((total + int64(per) - 1) / int64(per))
	if last < 1 {
		last = 1
	}
	return Page{Page: page, PerPage: per, Total: total, LastPage: last}
}

func pageOffset(page, per int) (int, int32) {
	if page < 1 {
		page = 1
	}
	return page, int32((page - 1) * per)
}

// ═════════════ Báo cáo định kỳ của tôi (chữ tự do) ═════════════

type FreeReport struct {
	ID         uuid.UUID `json:"id"`
	Title      string    `json:"title"`
	Content    *string   `json:"content"`
	ReportDate string    `json:"report_date"`
}

type MonthlyDue struct {
	DueLabel   string `json:"due_label"`
	MonthLabel string `json:"month_label"`
	Submitted  bool   `json:"submitted"`
}

// MyReports: vai trò có màn có cấu trúc cho kỳ chính → PrimaryKind (giao diện mở màn đó); vai trò khác nộp báo cáo chữ.
type MyReports struct {
	PrimaryKind string       `json:"primary_kind,omitempty"`
	Type        string       `json:"type"`
	Label       string       `json:"label"`
	Reports     []FreeReport `json:"reports"`
	Page        Page         `json:"page"`
	Today       string       `json:"today"`
	MonthlyDue  *MonthlyDue  `json:"monthly_due"`
	Tabs        []Tab        `json:"tabs"`
	Kinds       []string     `json:"kinds"`
	Classes     []Option     `json:"classes"`
}

func (s *Service) My(ctx context.Context, a *iam.Access, page int) (*MyReports, error) {
	typ := reportCadence(a.Roles)
	out := &MyReports{Type: typ, Label: typeLabel(typ), Today: dateKey(s.now()), Tabs: tabs(a.Roles),
		Kinds: structuredKinds(a.Roles), Reports: []FreeReport{}, Classes: []Option{}}
	if k := primaryStructuredKind(a.Roles); k != "" {
		out.PrimaryKind = k
		return out, nil
	}
	page, off := pageOffset(page, 10)
	rows, err := s.q.ListFreeReports(ctx, reportsdb.ListFreeReportsParams{UserID: a.UserID, Type: typ, Limit: 10, Offset: off})
	if err != nil {
		return nil, err
	}
	var total int64
	for _, r := range rows {
		total = r.Total
		out.Reports = append(out.Reports, FreeReport{r.ID, r.Title, r.Content, dateKey(r.ReportDate)})
	}
	out.Page = newPage(page, 10, total)
	if typ == "monthly" { // trợ giảng: hạn Chủ nhật cuối tháng — chỉ nhắc, không phạt
		done, err := s.SubmittedCurrentPeriod(ctx, a.UserID, a.Roles, s.now())
		if err != nil {
			return nil, err
		}
		now := s.now().In(vnZone)
		out.MonthlyDue = &MonthlyDue{DueLabel: "Chủ nhật " + monthlyDueDate(now).Format("02/01"), MonthLabel: now.Format("01/2006"), Submitted: done}
	}
	opts, err := s.classes.Options(ctx, a)
	if err != nil {
		return nil, err
	}
	if opts != nil {
		out.Classes = opts
	}
	return out, nil
}

// SubmittedCurrentPeriod: đã nộp báo cáo định kỳ chính của kỳ chứa at chưa (bản có cấu trúc đã nộp theo kỳ, hoặc báo cáo
// chữ tự do có ngày báo cáo trong kỳ). Dùng cho thẻ Tổng quan cá nhân và nhắc hạn báo cáo tháng.
func (s *Service) SubmittedCurrentPeriod(ctx context.Context, uid uuid.UUID, roles []string, at time.Time) (bool, error) {
	cadence := reportCadence(roles)
	var from, to time.Time
	var period string
	switch cadence {
	case "weekly":
		period = weekKey(at)
		from, to, _ = weekRange(period)
	case "monthly":
		period = monthKey(at)
		from, to, _ = monthRange(period)
	default:
		from, to, period = dayOf(at), dayOf(at), dateKey(at)
	}
	if k := primaryStructuredKind(roles); k != "" {
		r, err := s.structuredReport(ctx, uid, k, period)
		if err != nil {
			return false, err
		}
		if r != nil && r.Status == "submitted" {
			return true, nil
		}
	}
	return s.q.FreeReportExistsBetween(ctx, reportsdb.FreeReportExistsBetweenParams{UserID: uid, Type: cadence, FromDate: from, ToDate: to})
}

type FreeReportInput struct {
	Title      string     `json:"title"`
	Content    string     `json:"content"`
	ReportDate string     `json:"report_date"`
	ClassID    *uuid.UUID `json:"class_id"`
}

// resolveClass: lớp gắn vào báo cáo / nhật ký phải nằm trong phạm vi người dùng được xem.
func (s *Service) resolveClass(ctx context.Context, a *iam.Access, id *uuid.UUID) (*uuid.UUID, string, error) {
	if id == nil || *id == uuid.Nil {
		return nil, "", nil
	}
	_, name, ok, err := s.classes.Visible(ctx, a, *id)
	if err != nil {
		return nil, "", err
	}
	if !ok {
		return nil, "", ErrClassOutside
	}
	return id, name, nil
}

func (s *Service) SaveMy(ctx context.Context, a *iam.Access, in FreeReportInput) (string, error) {
	typ := reportCadence(a.Roles)
	title := strings.TrimSpace(in.Title)
	content := strings.TrimSpace(in.Content)
	switch {
	case title == "":
		return "", iam.Invalid("Nhập tiêu đề báo cáo.")
	case runeLen(title) > 255:
		return "", iam.Invalid("Tiêu đề tối đa 255 ký tự.")
	case content == "":
		return "", iam.Invalid("Nhập nội dung báo cáo.")
	case runeLen(content) > 20000:
		return "", iam.Invalid("Nội dung tối đa 20000 ký tự.")
	}
	date := s.today()
	if in.ReportDate != "" {
		d, ok := parseDate(in.ReportDate)
		if !ok {
			return "", iam.Invalid("Ngày báo cáo không hợp lệ.")
		}
		date = d
	}
	classID, _, err := s.resolveClass(ctx, a, in.ClassID)
	if err != nil {
		return "", err
	}
	r, err := s.q.InsertFreeReport(ctx, reportsdb.InsertFreeReportParams{UserID: a.UserID, ClassID: classID, Type: typ,
		Title: title, Content: &content, ReportDate: date})
	if err != nil {
		return "", err
	}
	s.log.Info("staff report submitted", "by", a.UserID, "type", typ, "id", r.ID)
	// TODO(notify): báo mọi Admin, loại staff_report_submitted, "Báo cáo mới: <title>" — "<tên> vừa nộp <title>. Mở để
	// kiểm tra.", link Tổng hợp ?open=<id>.
	return "Đã nộp " + typeLabel(typ) + " thành công!", nil
}
