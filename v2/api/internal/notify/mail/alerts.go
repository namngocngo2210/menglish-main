package mail

import (
	"context"
	"encoding/json"
	"slices"
	"strings"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/notify/mail/maildb"
)

// Sự kiện gửi email cho danh sách "Email nhận Ticket" (SystemSetting::isTicketEventEnabled, chưa lưu = bật).
const (
	EventTicketCreated = "created"
	EventTicketComment = "comment"
	EventTicketStatus  = "status_changed"
	EventStaleLead     = "stale_lead"
	EventTransaction   = "transaction"
	EventOverdueDebt   = "overdue_debt"
	EventHomework      = "homework"
)

const keyTicketEmails = "ticket_notification_emails"

// TicketRecipients: danh sách "Email nhận Ticket" (SystemSetting::getTicketEmails), đã bỏ địa chỉ sai và trùng.
func TicketRecipients(ctx context.Context, db DB) ([]string, error) {
	rows, err := maildb.New(db).MailSettings(ctx, []string{keyTicketEmails})
	if err != nil || len(rows) == 0 {
		return nil, err
	}
	var list []string
	if json.Unmarshal(rows[0].Value, &list) != nil {
		var s string
		if json.Unmarshal(rows[0].Value, &s) == nil {
			list = strings.FieldsFunc(s, func(r rune) bool { return r == ',' || r == ';' || r == '\n' || r == '\r' })
		}
	}
	out := []string{}
	for _, e := range list {
		if a := NormalizeAddress(e); a != "" && !slices.Contains(out, a) {
			out = append(out, a)
		}
	}
	return out, nil
}

// EventEnabled: sự kiện có bật gửi email không (chưa lưu = bật).
func EventEnabled(ctx context.Context, db DB, event string) (bool, error) {
	rows, err := maildb.New(db).MailSettings(ctx, []string{"ticket_notify_" + event})
	if err != nil || len(rows) == 0 {
		return true, err
	}
	var v any
	if json.Unmarshal(rows[0].Value, &v) != nil || v == nil {
		return true, nil
	}
	switch x := v.(type) {
	case bool:
		return x, nil
	case float64:
		return x == 1, nil
	case string:
		return slices.Contains([]string{"1", "true", "yes", "on"}, strings.ToLower(strings.TrimSpace(x))), nil
	}
	return false, nil
}

// Alert: tham số của OperationalAlert (mảng $params của sendOperationalAlertEmail).
type Alert struct {
	Code          string // trống = 4 chữ đầu của sự kiện viết hoa
	Title         string // trống = tiêu đề thư
	Status        string // trống = info
	StatusLabel   string // trống = "Thông báo"
	Priority      string // trống = medium
	PriorityLabel string // trống = "Thông thường"
	CategoryLabel string // trống = "Vận hành"
	SenderName    string // trống = "Hệ thống MEnglish"
	ActionURL     string // "/..." trong ứng dụng; trống = trang chủ
	ActionText    string // trống = "Xem chi tiết"
	Type          string // trống = alert
	// Đối tượng nghiệp vụ / người gây ra (màn quản trị hàng đợi)
	RefType, RefID string
	CreatedBy      *uuid.UUID
}

// OperationalAlert xếp email cảnh báo vận hành (lead trễ, giao dịch mới, nhắc nợ, nộp bài…) cho danh sách "Email nhận
// Ticket" khi sự kiện đang bật (NotificationService::sendOperationalAlertEmail). Trả số thư đã xếp.
func OperationalAlert(ctx context.Context, db DB, event, subject, content string, p Alert) (int, error) {
	on, err := EventEnabled(ctx, db, event)
	if err != nil || !on {
		return 0, err
	}
	to, err := TicketRecipients(ctx, db)
	if err != nil || len(to) == 0 {
		return 0, err
	}
	code := p.Code
	if code == "" {
		code = strings.ToUpper(event[:min(4, len(event))])
	}
	t := Ticket{
		Code: code, Title: cmpOr(p.Title, subject), Subject: subject, Content: content, Type: cmpOr(p.Type, "alert"),
		Status: cmpOr(p.Status, "info"), StatusLabel: cmpOr(p.StatusLabel, "Thông báo"),
		Priority: cmpOr(p.Priority, "medium"), PriorityLabel: cmpOr(p.PriorityLabel, "Thông thường"),
		CategoryLabel: cmpOr(p.CategoryLabel, "Vận hành"), SenderName: cmpOr(p.SenderName, "Hệ thống MEnglish"),
		ActionURL: cmpOr(p.ActionURL, "/"), ActionText: cmpOr(p.ActionText, "Xem chi tiết"),
	}
	n := 0
	for _, addr := range to {
		if _, err := Enqueue(ctx, db, Message{
			To: addr, Subject: subject, Template: TemplateTicket, Data: t, Kind: KindOpsAlert,
			RefType: p.RefType, RefID: p.RefID, CreatedBy: p.CreatedBy,
		}); err != nil {
			return n, err
		}
		n++
	}
	return n, nil
}

// Email tự sinh cho tài khoản học viên chưa có email ở bản Laravel (User::isGeneratedStudentEmail).
const generatedStudentDomain = "@student.menglish.edu.vn"

// ParentRecipient: email thật đầu tiên hợp lệ để gửi phụ huynh / khách (ParentMessenger::recipient), "" nếu không có.
func ParentRecipient(emails ...string) string {
	for _, e := range emails {
		if a := NormalizeAddress(e); a != "" && !strings.HasSuffix(a, generatedStudentDomain) {
			return a
		}
	}
	return ""
}

// DayLabel: "Thứ Bảy 04/10/2026" (ParentMessenger::dayLabel).
func DayLabel(d time.Time) string {
	names := []string{"Chủ nhật", "Thứ Hai", "Thứ Ba", "Thứ Tư", "Thứ Năm", "Thứ Sáu", "Thứ Bảy"}
	return names[d.Weekday()] + " " + d.Format("02/01/2006")
}
