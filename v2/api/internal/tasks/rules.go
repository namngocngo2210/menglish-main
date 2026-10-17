package tasks

import (
	"fmt"
	"math"
	"slices"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5/pgtype"
)

// Trạng thái công việc (giống Laravel).
const (
	StatusNew                 = "new"
	StatusInProgress          = "in_progress"
	StatusPendingConfirmation = "pending_confirmation"
	StatusBlocked             = "blocked"
	StatusCompleted           = "completed"
	StatusOverdue             = "overdue"
	StatusCanceled            = "canceled"

	// HandBack: thao tác "Chuyển lại cho người giao" (không phải trạng thái): việc bị chặn về tay người giao, trạng thái Mới.
	HandBack = "hand_back"

	// KindTADaily: nhiệm vụ hằng ngày của trợ giảng, cố ý không có hạn (không bao giờ Quá hạn).
	KindTADaily = "ta_daily"

	// TAAssignCutoff: mốc khuyến nghị gửi nhiệm vụ trợ giảng trong ngày; gửi sau mốc này thì báo Admin.
	TAAssignCutoff = "15:30"
)

var statusLabels = map[string]string{
	StatusNew: "Mới", StatusInProgress: "Đang thực hiện", StatusPendingConfirmation: "Chờ xác nhận",
	StatusBlocked: "Bị chặn", StatusCompleted: "Hoàn thành", StatusOverdue: "Quá hạn", StatusCanceled: "Đã hủy",
}

func statusLabel(s string) string {
	if l, ok := statusLabels[s]; ok {
		return l
	}
	return s
}

var frequencyLabels = map[string]string{"daily": "Hàng ngày", "weekly": "Hàng tuần", "monthly": "Hàng tháng"}

// typeLabel: "Phát sinh" / "Lặp đi lặp lại (Hàng tuần)".
func typeLabel(taskType string, frequency *string) string {
	l := map[string]string{"one_time": "Phát sinh", "recurring": "Lặp đi lặp lại"}[taskType]
	if l == "" {
		l = taskType
	}
	if frequency != nil && *frequency != "" {
		f := frequencyLabels[*frequency]
		if f == "" {
			f = *frequency
		}
		l += " (" + f + ")"
	}
	return l
}

// Ca trực của trợ giảng và giờ hạn mặc định của ca khi không gắn buổi học.
var (
	slotOrder  = []string{"before", "during", "after"}
	slotLabels = map[string]string{"before": "Trước giờ học", "during": "Trong giờ học", "after": "Sau giờ học"}
	slotDue    = map[string]string{"before": "14:00", "during": "18:00", "after": "21:30"}
)

func slotLabel(s string) string {
	if l, ok := slotLabels[s]; ok {
		return l
	}
	return slotLabels["during"]
}

// statusTransitions: chuyển trạng thái hợp lệ (chưa xét người thao tác).
var statusTransitions = map[string][]string{
	StatusNew:                 {StatusInProgress, StatusBlocked, StatusPendingConfirmation, StatusCanceled},
	StatusInProgress:          {StatusBlocked, StatusPendingConfirmation, StatusCompleted, StatusCanceled},
	StatusBlocked:             {StatusInProgress, StatusCanceled},
	StatusPendingConfirmation: {StatusCompleted, StatusInProgress},
	StatusOverdue:             {StatusInProgress, StatusPendingConfirmation, StatusCompleted, StatusCanceled},
	StatusCompleted:           {},
	StatusCanceled:            {},
}

// validTarget: giá trị gửi lên ô "trạng thái mới" có hợp lệ không.
func validTarget(s string) bool {
	_, ok := statusTransitions[s]
	return ok || s == HandBack
}

// allowedTransitions: các bước người dùng được làm với việc (WorkTaskController::allowedTransitions):
//   - Người thực hiện: bắt đầu / báo bị chặn / gửi chờ xác nhận (không tự hoàn thành).
//   - Admin (khác người thực hiện): xác nhận hoàn thành, trả về làm tiếp.
//   - Hủy: chỉ người giao và Admin.
//   - Việc bị chặn (người giao khác người làm): người làm, người giao hoặc Admin "Chuyển lại cho người giao",
//     đứng ngay trước Hủy.
func allowedTransitions(status string, creator uuid.UUID, assignee *uuid.UUID, me uuid.UUID, isAdmin bool) []string {
	next := slices.Clone(statusTransitions[status])
	isAssignee := assignee != nil && *assignee == me
	isCreator := creator == me
	isApprover := !isAssignee && isAdmin

	if status == StatusBlocked && (assignee == nil || *assignee != creator) {
		i := slices.Index(next, StatusCanceled)
		next = slices.Insert(next, i, HandBack)
	}
	out := []string{}
	for _, s := range next {
		ok := false
		switch s {
		case StatusCompleted:
			ok = isApprover
		case StatusCanceled:
			ok = isCreator || isAdmin
		case StatusPendingConfirmation, StatusBlocked:
			ok = isAssignee
		case StatusInProgress:
			ok = isAssignee || (isApprover && status == StatusPendingConfirmation)
		case HandBack:
			ok = isAssignee || isCreator || isAdmin
		}
		if ok {
			out = append(out, s)
		}
	}
	return out
}

// Transition là một bước chuyển kèm nhãn nút.
type Transition struct {
	Value string `json:"value"`
	Label string `json:"label"`
}

// transitionOptions: nhãn nút ("Trả về làm tiếp" khi việc đang chờ xác nhận; "Nhận lại việc" khi người giao tự nhận lại).
func transitionOptions(status string, allowed []string, isCreator bool) []Transition {
	labels := map[string]string{
		StatusInProgress: "Đang thực hiện", StatusBlocked: "Bị chặn", StatusPendingConfirmation: "Gửi chờ xác nhận",
		StatusCompleted: "Xác nhận hoàn thành", StatusCanceled: "Hủy công việc", HandBack: "Chuyển lại cho người giao",
	}
	if status == StatusPendingConfirmation {
		labels[StatusInProgress] = "Trả về làm tiếp"
	}
	if isCreator {
		labels[HandBack] = "Nhận lại việc"
	}
	out := make([]Transition, 0, len(allowed))
	for _, s := range allowed {
		out = append(out, Transition{s, labels[s]})
	}
	return out
}

// ───── Ngày giờ (theo giờ Việt Nam) ─────

// vnZone: múi giờ nghiệp vụ (Việt Nam không đổi giờ mùa hè nên dùng múi cố định +7).
var vnZone = time.FixedZone("Asia/Ho_Chi_Minh", 7*3600)

// dateOnly: ngày (theo giờ Việt Nam) dạng time.Time 00:00 UTC — dạng ghi / đọc cột date.
func dateOnly(t time.Time) time.Time {
	t = t.In(vnZone)
	return time.Date(t.Year(), t.Month(), t.Day(), 0, 0, 0, 0, time.UTC)
}

func parseDate(s string) (time.Time, bool) {
	t, err := time.Parse(time.DateOnly, s)
	return t, err == nil
}

func formatDate(t *time.Time) *string {
	if t == nil {
		return nil
	}
	s := t.Format(time.DateOnly)
	return &s
}

// parseClock đọc giờ "HH:MM" (hoặc "HH:MM:SS") thành pgtype.Time.
func parseClock(s string) (pgtype.Time, bool) {
	for _, layout := range []string{"15:04", "15:04:05"} {
		if t, err := time.Parse(layout, s); err == nil {
			us := int64(t.Hour())*3600e6 + int64(t.Minute())*60e6 + int64(t.Second())*1e6
			return pgtype.Time{Microseconds: us, Valid: true}, true
		}
	}
	return pgtype.Time{}, false
}

// formatClock: "HH:MM" hoặc nil.
func formatClock(t pgtype.Time) *string {
	if !t.Valid {
		return nil
	}
	m := t.Microseconds / 60e6
	s := fmt.Sprintf("%02d:%02d", m/60, m%60)
	return &s
}

// dueAt: thời điểm hết hạn = ngày hạn + giờ hạn (không có giờ → 23:59), theo giờ Việt Nam.
func dueAt(day *time.Time, clock pgtype.Time) (time.Time, bool) {
	if day == nil {
		return time.Time{}, false
	}
	minutes := int64(23*60 + 59)
	if clock.Valid {
		minutes = clock.Microseconds / 60e6
	}
	return time.Date(day.Year(), day.Month(), day.Day(), int(minutes/60), int(minutes%60), 0, 0, vnZone), true
}

// lateHours: số giờ trễ hạn (làm tròn lên); 0 nếu chưa quá hạn hoặc là nhiệm vụ hằng ngày của trợ giảng.
func lateHours(kind *string, day *time.Time, clock pgtype.Time, now time.Time) int {
	if kind != nil && *kind == KindTADaily {
		return 0
	}
	at, ok := dueAt(day, clock)
	if !ok || !now.After(at) {
		return 0
	}
	minutes := math.Floor(now.Sub(at).Minutes())
	return int(math.Ceil(minutes / 60))
}

// nextOccurrence: hạn của lượt kế tiếp của việc lặp (+1 ngày / +1 tuần / +1 tháng, không tràn sang tháng sau).
func nextOccurrence(day time.Time, frequency *string) time.Time {
	f := "weekly"
	if frequency != nil && *frequency != "" {
		f = *frequency
	}
	switch f {
	case "daily":
		return day.AddDate(0, 0, 1)
	case "monthly":
		y, m, d := day.Date()
		first := time.Date(y, m+1, 1, 0, 0, 0, 0, time.UTC)
		last := first.AddDate(0, 1, -1).Day()
		return time.Date(first.Year(), first.Month(), min(d, last), 0, 0, 0, 0, time.UTC)
	default:
		return day.AddDate(0, 0, 7)
	}
}

// isLateAssignment: giao nhiệm vụ trợ giảng cho ngày đã qua, hoặc cho hôm nay sau 15:30.
func isLateAssignment(day, now time.Time) bool {
	today := dateOnly(now)
	if day.Before(today) {
		return true
	}
	return day.Equal(today) && now.In(vnZone).Format("15:04") > TAAssignCutoff
}
