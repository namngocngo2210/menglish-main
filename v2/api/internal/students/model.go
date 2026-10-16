// Package students là hồ sơ học viên (bản Laravel: StudentProfileController, model Student): danh sách theo phạm vi,
// hồ sơ, sửa, đổi trạng thái, kết thúc bảo lưu, xóa mềm, phụ huynh và tài khoản cổng học viên. Học viên chỉ sinh ra từ
// luồng chốt Lead của CRM qua Service.Create.
package students

import (
	"fmt"
	"regexp"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

// 6 trạng thái hồ sơ (BA chốt 2026-09-25): không có "Học thử", "Chuyển lớp" không phải trạng thái.
const (
	StatusWaitingStart = "waiting_start"
	StatusStudying     = "studying"
	StatusDeferred     = "deferred"
	StatusSummerBreak  = "summer_break"
	StatusCompleted    = "completed"
	StatusDropped      = "dropped"
)

// StatusOption: trạng thái kèm nhãn và màu badge (giống Student::STATUSES / STATUS_COLORS).
type StatusOption struct {
	Value string `json:"value"`
	Label string `json:"label"`
	Color string `json:"color"`
}

var Statuses = []StatusOption{
	{StatusWaitingStart, "Chờ khai giảng", "info"},
	{StatusStudying, "Đang học", "success"},
	{StatusDeferred, "Bảo lưu", "warning"},
	{StatusSummerBreak, "Nghỉ hè", "secondary"},
	{StatusCompleted, "Hoàn thành khóa học", "primary"},
	{StatusDropped, "Thôi học", "error"},
}

// StatusLabel trả nhãn tiếng Việt của trạng thái (không có thì trả nguyên mã).
func StatusLabel(s string) string {
	for _, o := range Statuses {
		if o.Value == s {
			return o.Label
		}
	}
	return s
}

func validStatus(s string) bool {
	for _, o := range Statuses {
		if o.Value == s {
			return true
		}
	}
	return false
}

// Mốc thâm niên (năm) để lọc học viên lâu năm; từ TenureHighlightYears năm trở lên thì đánh dấu "Học viên lâu năm".
var TenureFilters = []int{1, 3, 5}

const TenureHighlightYears = 3

// vnZone: giờ Việt Nam (không có giờ mùa hè) để tính "hôm nay".
var vnZone = time.FixedZone("ICT", 7*3600)

// dateOf đưa thời điểm về ngày (00:00 UTC) theo giờ Việt Nam, cùng dạng với cột date đọc từ Postgres.
func dateOf(t time.Time) time.Time {
	y, m, d := t.In(vnZone).Date()
	return time.Date(y, m, d, 0, 0, 0, 0, time.UTC)
}

// monthsBetween: số tháng tròn từ a tới b (giống floor(Carbon::diffInMonths)).
func monthsBetween(a, b time.Time) int {
	months := (b.Year()-a.Year())*12 + int(b.Month()) - int(a.Month())
	if b.Day() < a.Day() {
		months--
	}
	return months
}

// Tenure: số năm / tháng đã học tính tới today; ok=false khi chưa vào học, đã thôi học hoặc ngày vào học ở tương lai.
func Tenure(start *time.Time, status string, today time.Time) (years, months int, ok bool) {
	if start == nil || status == StatusDropped || start.After(today) {
		return 0, 0, false
	}
	m := monthsBetween(*start, today)
	if m < 0 {
		m = 0
	}
	return m / 12, m % 12, true
}

// TenureLabel: "3 năm 2 tháng", "5 tháng", "Dưới 1 tháng"; nil khi chưa vào học hoặc đã thôi học.
func TenureLabel(start *time.Time, status string, today time.Time) *string {
	y, m, ok := Tenure(start, status, today)
	if !ok {
		return nil
	}
	var parts []string
	if y > 0 {
		parts = append(parts, fmt.Sprintf("%d năm", y))
	}
	if m > 0 {
		parts = append(parts, fmt.Sprintf("%d tháng", m))
	}
	label := "Dưới 1 tháng"
	if len(parts) > 0 {
		label = strings.Join(parts, " ")
	}
	return &label
}

// LongTerm: học viên lâu năm (≥ 3 năm, còn theo học).
func LongTerm(start *time.Time, status string, today time.Time) bool {
	y, _, ok := Tenure(start, status, today)
	return ok && y >= TenureHighlightYears
}

// Email tự sinh cho học viên chưa có email thật ở bản Laravel: không hiện ra danh sách.
const legacyStudentEmailDomain = "@student.menglish.edu.vn"

func realEmail(e *string) *string {
	if e == nil || strings.HasSuffix(strings.ToLower(*e), legacyStudentEmailDomain) {
		return nil
	}
	return e
}

func fmtDate(t *time.Time) *string {
	if t == nil {
		return nil
	}
	s := t.Format(time.DateOnly)
	return &s
}

// ───────────────────────── Kiểm dữ liệu nhập ─────────────────────────

var (
	emailRe       = regexp.MustCompile(`^[^@\s]{1,64}@[^@\s]+\.[^@\s]+$`)
	parentPhoneRe = regexp.MustCompile(`^[0-9+\s().-]{8,20}$`)
	codeRe        = regexp.MustCompile(`^HV-[0-9]{5,}$`)
)

type checker struct{ err error }

func (c *checker) fail(msg string) {
	if c.err == nil {
		c.err = iam.Invalid(msg)
	}
}

func (c *checker) required(v, attr string) {
	if strings.TrimSpace(v) == "" {
		c.fail("Vui lòng nhập " + attr + ".")
	}
}

func (c *checker) max(v, attr string, n int) {
	if utf8.RuneCountInString(strings.TrimSpace(v)) > n {
		c.fail(fmt.Sprintf("Trường %s không được vượt quá %d ký tự.", attr, n))
	}
}

func (c *checker) email(v string) {
	v = strings.TrimSpace(v)
	if v != "" && !emailRe.MatchString(v) {
		c.fail("Trường Địa chỉ Email phải là một địa chỉ email hợp lệ.")
	}
	c.max(v, "Địa chỉ Email", 255)
}

// date đọc ngày dạng YYYY-MM-DD; trống trả nil.
func (c *checker) date(v, attr string) *time.Time {
	v = strings.TrimSpace(v)
	if v == "" {
		return nil
	}
	t, err := time.Parse(time.DateOnly, v)
	if err != nil {
		c.fail("Trường " + attr + " không phải là ngày hợp lệ.")
		return nil
	}
	return &t
}

func strPtr(s string) *string {
	if s = strings.TrimSpace(s); s == "" {
		return nil
	}
	return &s
}

// UpdateInput: form sửa hồ sơ (StudentProfileController::updateStudent). Status chỉ nhận khi có quyền đổi trạng thái.
type UpdateInput struct {
	FullName       string `json:"full_name"`
	Phone          string `json:"phone"`
	ParentName     string `json:"parent_name"`
	ParentPhone    string `json:"parent_phone"`
	Email          string `json:"email"`
	DOB            string `json:"dob"`
	Gender         string `json:"gender"`
	Target         string `json:"target"`
	Address        string `json:"address"`
	School         string `json:"school"`
	StudyStartedOn string `json:"study_started_on"`
	Status         string `json:"status"`
	Notes          string `json:"notes"`
	// Phiên bản hồ sơ lúc mở form; khác bản hiện tại thì báo người khác vừa sửa.
	Version *int32 `json:"version"`
}

type updateValues struct {
	dob, studyStartedOn *time.Time
}

func (in *UpdateInput) validate(today time.Time) (updateValues, error) {
	var c checker
	var v updateValues
	c.required(in.FullName, "Họ và tên")
	c.max(in.FullName, "Họ và tên", 255)
	c.required(in.Phone, "Số điện thoại")
	c.max(in.Phone, "Số điện thoại", 20)
	c.max(in.ParentName, "Họ tên phụ huynh", 255)
	if p := strings.TrimSpace(in.ParentPhone); p != "" && !parentPhoneRe.MatchString(p) {
		c.fail("Số điện thoại phụ huynh không hợp lệ.")
	}
	c.email(in.Email)
	if v.dob = c.date(in.DOB, "ngày sinh"); v.dob != nil {
		if !v.dob.Before(today) {
			c.fail("Trường ngày sinh phải là một ngày trước hôm nay.")
		} else if !v.dob.After(time.Date(1940, 1, 1, 0, 0, 0, 0, time.UTC)) {
			c.fail("Trường ngày sinh phải là một ngày sau ngày 01/01/1940.")
		}
	}
	c.max(in.Gender, "giới tính", 20)
	c.max(in.Target, "Mục tiêu học tập", 100)
	c.max(in.Address, "Địa chỉ liên hệ", 255)
	c.max(in.School, "Trường học", 255)
	if v.studyStartedOn = c.date(in.StudyStartedOn, "ngày bắt đầu học"); v.studyStartedOn != nil {
		if v.studyStartedOn.After(today) {
			c.fail("Ngày bắt đầu học không được sau hôm nay.")
		} else if !v.studyStartedOn.After(time.Date(1990, 1, 1, 0, 0, 0, 0, time.UTC)) {
			c.fail("Trường ngày bắt đầu học phải là một ngày sau ngày 01/01/1990.")
		}
	}
	if s := strings.TrimSpace(in.Status); s != "" && !validStatus(s) {
		c.fail("Giá trị đã chọn trong trường trạng thái không hợp lệ.")
	}
	c.max(in.Notes, "Ghi chú", 500)
	return v, c.err
}

// CreateInput: hồ sơ học viên mới khi chốt Lead (CrmController::storeClosing). Các trường lấy từ Lead.
type CreateInput struct {
	// Mã đã cấp sẵn trên màn chốt (NextCode) để khớp nội dung chuyển khoản; trống / sai dạng / đã dùng thì cấp mã mới.
	Code           string
	LeadID         *uuid.UUID
	FullName       string
	Phone          string
	Email          string
	ParentName     string
	ParentPhone    string
	DOB            *time.Time
	Gender         string
	Address        string
	School         string
	BranchID       *uuid.UUID
	CurrentClassID *uuid.UUID
	Target         string
	EntranceScore  string
	TotalLessons   *int32
}

func (in *CreateInput) validate() error {
	var c checker
	c.required(in.FullName, "Họ và tên")
	c.max(in.FullName, "Họ và tên", 255)
	c.required(in.Phone, "Số điện thoại")
	c.max(in.Phone, "Số điện thoại", 20)
	c.email(in.Email)
	c.max(in.ParentName, "Họ tên phụ huynh", 255)
	c.max(in.ParentPhone, "Số điện thoại phụ huynh", 20)
	c.max(in.Gender, "giới tính", 20)
	c.max(in.Address, "Địa chỉ liên hệ", 255)
	c.max(in.School, "Trường học", 255)
	c.max(in.Target, "Mục tiêu học tập", 255)
	c.max(in.EntranceScore, "Điểm test đầu vào", 255)
	return c.err
}

// GuardianInput: gắn phụ huynh (tài khoản đăng nhập bằng SĐT) với học viên.
type GuardianInput struct {
	Phone        string `json:"phone"`
	FullName     string `json:"full_name"`
	Relationship string `json:"relationship"`
}

func (in *GuardianInput) validate() error {
	var c checker
	if iam.NormalizePhone(in.Phone) == "" {
		c.fail("Số điện thoại phụ huynh không hợp lệ.")
	}
	c.required(in.FullName, "họ tên phụ huynh")
	c.max(in.FullName, "Họ tên phụ huynh", 200)
	c.max(in.Relationship, "Quan hệ", 50)
	return c.err
}
