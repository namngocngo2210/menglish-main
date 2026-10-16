// Package notify gửi thông báo trong hệ thống cho người dùng (Laravel: AdminNotification::notifyUser / notifyUsers,
// NotificationService). Màn hình và API của người nhận nằm ở notify/notifyapi.
//
// Gói này không phụ thuộc iam để mọi module (kể cả iam) gọi được. Tìm người nhận theo quyền bằng
// iam.UserIDsWithPermission / iam.AdminIDs rồi truyền danh sách id vào Send.
package notify

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"regexp"
	"slices"
	"sort"
	"strings"
	"sync"
	"unicode/utf8"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/notify/notifydb"
)

// DB: pool hoặc transaction (pgx). Gửi trong transaction của nghiệp vụ thì thông báo rollback cùng thao tác.
type DB = notifydb.DBTX

// Notification là nội dung một thông báo; mỗi người nhận có một bản riêng (trạng thái đã đọc riêng).
type Notification struct {
	// Loại thông báo, vd. "ticket_assigned" (chữ thường, số, "_" hoặc "."); nhãn / biểu tượng khai báo bằng RegisterType.
	Type  string
	Title string
	Body  string
	// Đường dẫn trong ứng dụng cho nút "Xử lý ngay", vd. "/crm/customers/<id>"; rỗng = không có nút.
	Link string
	// Dữ liệu kèm theo cho màn hình (vd. {"customer_name": ..., "copy_text": ...}); không chứa dữ liệu nhạy cảm.
	Data map[string]any
	// Người gây ra thông báo; nil = hệ thống.
	CreatedBy *uuid.UUID
	// Không gửi cho người này (thường là chính người thao tác), như $exceptUserId của Laravel.
	Except *uuid.UUID
}

const (
	maxTitle = 255
	maxBody  = 2000
	maxLink  = 500
)

var typeRe = regexp.MustCompile(`^[a-z][a-z0-9_.]{0,63}$`)

// Send gửi n cho từng người trong userIDs (bỏ trùng, bỏ uuid.Nil, bỏ n.Except; tài khoản không tồn tại hoặc đã khóa
// bị bỏ qua). Trả về số thông báo đã tạo. Tiêu đề / nội dung quá dài bị cắt; loại hoặc đường dẫn sai trả lỗi.
func Send(ctx context.Context, db DB, userIDs []uuid.UUID, n Notification) (int, error) {
	if !typeRe.MatchString(n.Type) {
		return 0, fmt.Errorf("notify: loại thông báo %q không hợp lệ", n.Type)
	}
	title := truncate(strings.TrimSpace(n.Title), maxTitle)
	if title == "" {
		return 0, errors.New("notify: thiếu tiêu đề")
	}
	var link *string
	if n.Link != "" {
		if !ValidLink(n.Link) {
			return 0, fmt.Errorf("notify: đường dẫn %q phải là đường dẫn trong ứng dụng (bắt đầu bằng /)", n.Link)
		}
		link = &n.Link
	}
	ids := make([]uuid.UUID, 0, len(userIDs))
	for _, id := range userIDs {
		if id == uuid.Nil || (n.Except != nil && id == *n.Except) || slices.Contains(ids, id) {
			continue
		}
		ids = append(ids, id)
	}
	if len(ids) == 0 {
		return 0, nil
	}
	data := []byte("{}")
	if len(n.Data) > 0 {
		var err error
		if data, err = json.Marshal(n.Data); err != nil {
			return 0, fmt.Errorf("notify: dữ liệu kèm theo: %w", err)
		}
	}
	rows, err := notifydb.New(db).InsertNotifications(ctx, notifydb.InsertNotificationsParams{
		Type: n.Type, Title: title, Body: truncate(strings.TrimSpace(n.Body), maxBody), Link: link,
		Data: data, CreatedBy: n.CreatedBy, UserIds: ids,
	})
	return int(rows), err
}

// ValidLink: đường dẫn trong ứng dụng ("/..."), không phải "//host" hay URL tuyệt đối (tránh chuyển hướng ra ngoài).
func ValidLink(s string) bool {
	return strings.HasPrefix(s, "/") && !strings.HasPrefix(s, "//") && !strings.ContainsAny(s, "\\\r\n") &&
		len(s) <= maxLink
}

func truncate(s string, max int) string {
	if utf8.RuneCountInString(s) <= max {
		return s
	}
	r := []rune(s)
	return string(r[:max-1]) + "…"
}

// ───── Loại thông báo ─────

// Tone: màu thẻ trên giao diện.
const (
	ToneNeutral = "neutral"
	ToneError   = "error"
	ToneWarning = "warning"
	ToneSuccess = "success"
	ToneInfo    = "info"
	TonePrimary = "primary"
)

// TypeInfo: nhãn, biểu tượng (Material Symbols) và màu của một loại thông báo.
type TypeInfo struct {
	Label string `json:"label"`
	Icon  string `json:"icon"`
	Tone  string `json:"tone"`
}

var (
	typesMu sync.RWMutex
	types   = map[string]TypeInfo{}
)

// Mặc định cho loại chưa khai báo (như default của AdminNotification::getTypeLabelAttribute).
var defaultType = TypeInfo{"Thông báo hệ thống", "notifications", ToneNeutral}

// AlertTypes: cảnh báo lead tồn đọng, đếm riêng trên thẻ "Lead bị sót >24h" và nút lọc nhanh.
var AlertTypes = []string{"stale_lead_24h", "stale_lead_care"}

// RegisterType khai báo (hoặc ghi đè) nhãn / biểu tượng / màu của một loại thông báo. Gọi trong init() của module.
func RegisterType(key string, info TypeInfo) {
	typesMu.Lock()
	defer typesMu.Unlock()
	types[key] = info
}

// Info trả thông tin hiển thị của loại thông báo.
func Info(key string) TypeInfo {
	typesMu.RLock()
	defer typesMu.RUnlock()
	if t, ok := types[key]; ok {
		return t
	}
	return defaultType
}

// TypeOption: một loại thông báo cho ô lọc.
type TypeOption struct {
	Value string `json:"value"`
	Label string `json:"label"`
}

// Types trả các loại đã khai báo, sắp theo nhãn.
func Types() []TypeOption {
	typesMu.RLock()
	defer typesMu.RUnlock()
	out := make([]TypeOption, 0, len(types))
	for k, t := range types {
		out = append(out, TypeOption{k, t.Label})
	}
	sort.Slice(out, func(i, j int) bool {
		if out[i].Label != out[j].Label {
			return out[i].Label < out[j].Label
		}
		return out[i].Value < out[j].Value
	})
	return out
}

// Các loại thông báo của Laravel (AdminNotification::getIconAttribute / getBadgeColorAttribute / getTypeLabelAttribute).
func init() {
	for k, t := range map[string]TypeInfo{
		"stale_lead_24h":             {"Lead sót >24h", "person_alert", ToneError},
		"stale_lead_care":            {"Khách bị bỏ quên", "person_alert", ToneError},
		"receipt_pending":            {"Phiếu thu chờ duyệt", "receipt_long", ToneWarning},
		"receipt_approved":           {"Phiếu thu đã duyệt", "verified", ToneSuccess},
		"receipt_rejected":           {"Phiếu thu bị trả về", "assignment_return", ToneError},
		"overdue_report":             {"Báo cáo nợ quá hạn", "running_with_errors", ToneError},
		"test_today":                 {"Lịch test trong ngày", "event", ToneInfo},
		"urgent_ticket":              {"Ticket khẩn cấp", "report_problem", ToneError},
		"ticket_new":                 {"Ticket hỗ trợ mới", "report_problem", ToneError},
		"ticket_assigned":            {"Phân công Ticket", "assignment_ind", TonePrimary},
		"ticket_message":             {"Phản hồi Ticket", "chat", ToneInfo},
		"ticket_status":              {"Cập nhật Ticket", "published_with_changes", ToneSuccess},
		"contract_expiring":          {"Hợp đồng sắp hết hạn", "contract", ToneWarning},
		"task_assigned":              {"Được giao việc", "assignment", ToneSuccess},
		"task_blocked":               {"SOS · Việc bị chặn", "report_problem", ToneError},
		"class_report_pending":       {"Báo cáo trực lớp chờ duyệt", "fact_check", TonePrimary},
		"staff_report_submitted":     {"Báo cáo chờ kiểm tra", "summarize", ToneInfo},
		"staff_report_resubmitted":   {"Báo cáo đã bổ sung", "summarize", ToneInfo},
		"staff_report_digest":        {"Tổng hợp báo cáo ngày", "summarize", ToneInfo},
		"staff_report_reviewed":      {"Báo cáo đã kiểm tra", "task_alt", ToneSuccess},
		"staff_report_returned":      {"Báo cáo cần bổ sung", "assignment_return", ToneWarning},
		"daily_report_due":           {"Nhắc nộp báo cáo ngày", "edit_calendar", ToneWarning},
		"staff_journal_urgent":       {"Sự việc khẩn", "report_problem", ToneError},
		"trial_booked":               {"Khách học thử", "person_search", ToneInfo},
		"trial_feedback":             {"Nhận xét học thử", "rate_review", ToneInfo},
		"class_assigned":             {"Xếp dạy lớp", "co_present", TonePrimary},
		"care_overdue":               {"Quá hạn chăm sóc", "volunteer_activism", ToneError},
		"cash_deposit_overdue":       {"Tiền mặt chưa nộp về TK", "savings", ToneError},
		"refund_deadline":            {"Hạn xử lý hoàn phí", "hourglass_bottom", ToneError},
		"monthly_report_due":         {"Nhắc báo cáo giảng dạy tháng", "edit_calendar", ToneWarning},
		"big_test_paper_due":         {"Nhắc duyệt đề Big Test", "quiz", ToneError},
		"adjustment_pending":         {"Yêu cầu giãn tiến độ", "tune", ToneWarning},
		"adjustment_sla":             {"Giãn tiến độ quá hạn duyệt", "tune", ToneError},
		"material_order_new":         {"Order học liệu mới", "inventory_2", TonePrimary},
		"material_order_overdue":     {"Order học liệu quá hạn", "running_with_errors", ToneError},
		"penalty_created":            {"Biên bản vi phạm", "gavel", ToneError},
		"sla_breach":                 {"Quá hạn SLA", "timer_off", ToneError},
		"penalty_fined":              {"Quyết phạt", "payments", ToneError},
		"penalty_due_reminder":       {"Nhắc hạn nộp phạt", "payments", ToneWarning},
		"payroll_calendar":           {"Lịch chốt lương", "event_upcoming", ToneWarning},
		"appointment_confirm_manual": {"Xác nhận lịch hẹn qua Zalo", "event_available", ToneWarning},
		"payment_confirm_manual":     {"Báo đã nhận học phí qua Zalo", "paid", ToneWarning},
	} {
		types[k] = t
	}
}
