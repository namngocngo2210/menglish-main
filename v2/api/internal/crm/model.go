// Package crm: khách hàng tiềm năng (lead) 9 giai đoạn, nhật ký chăm sóc, Kanban, phân công / chuyển cơ sở,
// xác nhận chính thức, ưu đãi học phí, báo cáo và nhập Excel. Theo CrmController / CrmCustomer của bản Laravel.
package crm

import (
	"fmt"
	"regexp"
	"slices"
	"strings"
	"time"

	"github.com/google/uuid"
)

// Giai đoạn khách (Laravel CrmCustomer::PIPELINE_STAGES + lost).
const (
	StageNew           = "new"
	StageConsulting    = "consulting"
	StageTestScheduled = "test_scheduled"
	StageTesting       = "testing"
	StageTested        = "tested"
	StageResultSent    = "result_sent"
	StageWaitingClass  = "waiting_class"
	StageWon           = "won"
	StageLost          = "lost"
)

// PipelineStages: 8 bước theo đúng thứ tự; Thất bại nằm ngoài pipeline.
var PipelineStages = []string{
	StageNew, StageConsulting, StageTestScheduled, StageTesting, StageTested, StageResultSent, StageWaitingClass, StageWon,
}

var stageLabels = map[string]string{
	StageNew: "Mới", StageConsulting: "Đang tư vấn", StageTestScheduled: "Hẹn test", StageTesting: "Test",
	StageTested: "Đã test", StageResultSent: "Gửi kết quả", StageWaitingClass: "Chờ xếp lớp", StageWon: "Đã chốt",
	StageLost: "Thất bại",
}

var (
	// ClosedStages: đã chốt (có hồ sơ học viên) — không lùi bước, không chuyển sang thất bại.
	ClosedStages = []string{StageWaitingClass, StageWon}
	// ClosableStages: được "Chốt & Xếp lớp" từ các bước này (consulting = nhánh không test).
	ClosableStages = []string{StageConsulting, StageTested, StageResultSent}
	// ActiveStages: khách đang chăm sóc (áp dụng SLA liên hệ, cảnh báo bỏ quên).
	ActiveStages = []string{StageNew, StageConsulting, StageTestScheduled, StageTesting, StageTested, StageResultSent}
	// TrialBookableStages: được đặt lịch học thử (phân hệ placement dùng).
	TrialBookableStages = []string{StageConsulting, StageTestScheduled, StageTesting, StageTested, StageResultSent}
)

// StageLabel: nhãn tiếng Việt của giai đoạn.
func StageLabel(stage string) string {
	if l, ok := stageLabels[stage]; ok {
		return l
	}
	return stage
}

func validStage(stage string) bool { _, ok := stageLabels[stage]; return ok }

func stageIndex(stage string) int { return slices.Index(PipelineStages, stage) }

func isClosed(stage string) bool { return slices.Contains(ClosedStages, stage) }
func isActive(stage string) bool { return slices.Contains(ActiveStages, stage) }

// Trường hợp đồng bị khóa khi khách đã chốt (Laravel CONTRACT_LOCKED_FIELDS).
var contractLockedFields = []struct{ Key, Label string }{
	{"deal_value", "Giá trị hợp đồng"},
	{"branch_id", "Cơ sở đăng ký"},
	{"course_interest", "Khóa học đăng ký"},
}

// Mốc chăm sóc tháng đầu của khách đã chốt.
var careChecklistItems = []struct{ Key, Label string }{
	{"session_1", "Buổi 1 — Hỏi phản hồi sau buổi học đầu tiên"},
	{"session_4_5", "Buổi 4–5 — Trao đổi tiến độ với phụ huynh"},
	{"day_30", "Đủ 30 ngày — Đánh giá cuối tháng đầu, mức độ hài lòng"},
}

// DefaultSources: nguồn khách mặc định khi chưa cấu hình danh mục "lead_source" (Danh mục hệ thống, xem leadSources).
var DefaultSources = []string{"Landing page", "Marketing", "Giới thiệu", "Vãng lai", "Tiktok", "Facebook", "Google Ads", "Chị Liên"}

// Loại nhật ký (Laravel CrmCustomerHistory::FILTER_TYPES).
var eventTypeLabels = []struct{ Key, Label string }{
	{"call", "Gọi điện"}, {"message", "Nhắn tin"}, {"meet", "Gặp trực tiếp"}, {"test", "Test đầu vào"},
	{"result", "Gửi kết quả"}, {"trial", "Học thử"}, {"stage_change", "Chuyển giai đoạn"}, {"update", "Sửa thông tin"},
	{"assign", "Phân công"}, {"care", "Chăm sóc tháng đầu"}, {"note", "Ghi chú"}, {"system", "Hệ thống"},
}

func eventTypeLabel(t string) string {
	for _, e := range eventTypeLabels {
		if e.Key == t {
			return e.Label
		}
	}
	return t
}

var (
	contactTypes = []string{"call", "message", "meet"}
	careTypes    = []string{"call", "message", "meet", "test", "result", "trial", "stage_change"}
)

// SLA liên hệ (Laravel CrmCustomer + config/sla.php).
// TODO(merge): đọc ngưỡng từ trang Cấu hình SLA khi phân hệ SLA có (crm.first_contact, crm.follow_up).
const (
	firstContactSLAHours     = 24
	followUpSLAHours         = 72
	slaWarningRatio          = 0.25
	maxScheduledFollowUpDays = 14
	neglectDays              = 3
	kanbanColumnLimit        = 30
	kanbanWonRecentDays      = 30
)

// vnZone: giờ Việt Nam (+07:00, không đổi giờ mùa hè) — "hôm nay" của chip lọc, nhãn ngày giờ.
var vnZone = time.FixedZone("ICT", 7*3600)

func dayBounds(now time.Time) (time.Time, time.Time) {
	t := now.In(vnZone)
	start := time.Date(t.Year(), t.Month(), t.Day(), 0, 0, 0, 0, vnZone)
	return start, start.Add(24*time.Hour - time.Nanosecond)
}

func fmtDateTime(t time.Time) string { return t.In(vnZone).Format("15:04 02/01/2006") }
func fmtDate(t time.Time) string     { return t.In(vnZone).Format("02/01/2006") }

// ───────────────────────── SĐT, email, mã khách ─────────────────────────

var (
	nonDigit      = regexp.MustCompile(`\D+`)
	phoneBadChars = regexp.MustCompile(`[^\d\s.\-+()]`)
	vnPhone       = regexp.MustCompile(`^(0[35789]\d{8}|02\d{9})$`)
	emailRe       = regexp.MustCompile(`^[^@\s]+@[^@\s]+\.[^@\s]+$`)
	longCodeRe    = regexp.MustCompile(`^KH-[0-9A-Z]{26}$`)
)

// NormalizePhone: chỉ giữ chữ số; +84 (11 hoặc 12 số) đổi về 0.
func NormalizePhone(phone string) string {
	d := nonDigit.ReplaceAllString(phone, "")
	if (len(d) == 11 || len(d) == 12) && strings.HasPrefix(d, "84") {
		d = "0" + d[2:]
	}
	return d
}

// ValidPhone: di động 10 số (03/05/07/08/09) hoặc cố định 11 số (02x); chấp nhận khoảng trắng, dấu chấm, gạch, +84.
func ValidPhone(phone string) bool {
	raw := strings.TrimSpace(phone)
	if raw == "" || phoneBadChars.MatchString(raw) {
		return false
	}
	return vnPhone.MatchString(NormalizePhone(raw))
}

func validEmail(s string) bool { return len(s) <= 255 && emailRe.MatchString(s) }

const crockford = "0123456789ABCDEFGHJKMNPQRSTVWXYZ"

// leadCode: KH-<ULID> như Laravel; ULID lấy từ id uuidv7 (48 bit đầu là thời điểm tạo, mã tăng dần theo thời gian).
func leadCode(id uuid.UUID) string {
	var b [26]byte
	// 128 bit → 26 ký tự base32 Crockford (2 bit đệm ở đầu như ULID).
	hi := uint64(id[0])<<56 | uint64(id[1])<<48 | uint64(id[2])<<40 | uint64(id[3])<<32 |
		uint64(id[4])<<24 | uint64(id[5])<<16 | uint64(id[6])<<8 | uint64(id[7])
	lo := uint64(id[8])<<56 | uint64(id[9])<<48 | uint64(id[10])<<40 | uint64(id[11])<<32 |
		uint64(id[12])<<24 | uint64(id[13])<<16 | uint64(id[14])<<8 | uint64(id[15])
	for i := 25; i >= 0; i-- {
		b[i] = crockford[lo&31]
		lo = lo>>5 | hi<<59
		hi >>= 5
	}
	return "KH-" + string(b[:])
}

// ShortCode: mã dài KH-<ULID> rút gọn còn KH-<6 ký tự cuối>.
func ShortCode(code string) string {
	if longCodeRe.MatchString(code) {
		return "KH-" + code[len(code)-6:]
	}
	return code
}

// ───────────────────────── SLA liên hệ ─────────────────────────

// SLA: đồng hồ hạn liên hệ gửi xuống giao diện (Laravel contactSlaPayload).
type SLA struct {
	Kind          string    `json:"kind"` // first | follow_up | appointment
	Label         string    `json:"label"`
	Deadline      time.Time `json:"deadline"`
	DeadlineLabel string    `json:"deadline_label"`
	WarnSeconds   int64     `json:"warn_seconds"`
	State         string    `json:"state"` // on_time | due_soon | overdue
	Remaining     string    `json:"remaining"`
}

// contactSLA: hạn liên hệ lần đầu (24h từ lúc tạo) hoặc hạn chăm sóc tiếp theo (72h từ lần chăm sóc gần nhất);
// lịch hẹn liên hệ còn hiệu lực thay hạn nếu sớm hơn, hoặc dời hạn tối đa 14 ngày. Chỉ khách đang chăm sóc.
func contactSLA(stage string, createdAt time.Time, lastCare, nextFollowUp *time.Time, now time.Time) *SLA {
	if !isActive(stage) {
		return nil
	}
	kind, label, hours, anchor := "follow_up", "Hạn chăm sóc tiếp theo", followUpSLAHours, createdAt
	if lastCare == nil && stage == StageNew {
		kind, label, hours = "first", "Hạn liên hệ lần đầu", firstContactSLAHours
	} else if lastCare != nil {
		anchor = *lastCare
	}
	deadline := anchor.Add(time.Duration(hours) * time.Hour)
	if appt := activeAppointment(lastCare, nextFollowUp); appt != nil {
		if appt.Before(deadline) || !appt.After(anchor.AddDate(0, 0, maxScheduledFollowUpDays)) {
			kind, deadline = "appointment", *appt
			label = "Hạn chăm sóc tiếp theo (đã hẹn)"
			if stage == StageNew {
				label = "Hạn liên hệ (đã hẹn)"
			}
		}
	}
	warn := int64(float64(hours)*3600*slaWarningRatio + 0.5)
	state := "on_time"
	switch {
	case deadline.Before(now):
		state = "overdue"
	case int64(deadline.Sub(now).Seconds()) < warn:
		state = "due_soon"
	}
	return &SLA{
		Kind: kind, Label: label, Deadline: deadline, DeadlineLabel: fmtDateTime(deadline), WarnSeconds: warn,
		State: state, Remaining: remainingLabel(deadline, now),
	}
}

// activeAppointment: lịch hẹn liên hệ chưa được thực hiện (chưa có lần chăm sóc từ lúc hẹn trở đi).
func activeAppointment(lastCare, nextFollowUp *time.Time) *time.Time {
	if nextFollowUp == nil || (lastCare != nil && !lastCare.Before(*nextFollowUp)) {
		return nil
	}
	return nextFollowUp
}

// remainingLabel: "Còn 2 giờ 14 phút" / "Quá hạn 1 ngày 3 giờ" (có ngày thì bỏ phút).
func remainingLabel(at, now time.Time) string {
	d := at.Sub(now)
	prefix := "Còn "
	if d < 0 {
		prefix, d = "Quá hạn ", -d
	}
	days := int(d / (24 * time.Hour))
	h := int(d/time.Hour) % 24
	m := int(d/time.Minute) % 60
	var parts []string
	if days > 0 {
		parts = append(parts, fmt.Sprintf("%d ngày", days))
	}
	if h > 0 {
		parts = append(parts, fmt.Sprintf("%d giờ", h))
	}
	if days == 0 && m > 0 {
		parts = append(parts, fmt.Sprintf("%d phút", m))
	}
	if len(parts) == 0 {
		return prefix + "dưới 1 phút"
	}
	return prefix + strings.Join(parts, " ")
}

// formatMoney: 1500000 → "1.500.000 đ".
func formatMoney(v int64) string { return groupThousands(v) + " đ" }

func groupThousands(v int64) string {
	neg := v < 0
	if neg {
		v = -v
	}
	s := fmt.Sprintf("%d", v)
	var b strings.Builder
	for i, c := range s {
		if i > 0 && (len(s)-i)%3 == 0 {
			b.WriteByte('.')
		}
		b.WriteRune(c)
	}
	if neg {
		return "-" + b.String()
	}
	return b.String()
}

func deref[T any](p *T) T {
	var zero T
	if p == nil {
		return zero
	}
	return *p
}

func ptr[T any](v T) *T { return &v }

// strPtr: chuỗi đã cắt khoảng trắng, rỗng → nil.
func strPtr(s string) *string {
	s = strings.TrimSpace(s)
	if s == "" {
		return nil
	}
	return &s
}

func cut(s string, n int) string {
	r := []rune(s)
	if len(r) <= n {
		return s
	}
	return string(r[:n])
}

func runeLen(s string) int { return len([]rune(s)) }
