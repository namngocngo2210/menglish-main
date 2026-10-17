// Package mail gửi email ra ngoài qua hàng đợi core.mail_outbox (Laravel: Mail::to()->send, Mail::html, Mail::raw
// gọi đồng bộ trong request).
//
// Cách dùng: trong transaction của nghiệp vụ gọi Enqueue; commit xong thì vòng gửi nền (Service.RunLoop, chạy trong
// `menglish api`) lấy thư, render mẫu, gửi qua SMTP cấu hình ở màn "Email nhận Ticket" (core.settings, mật khẩu mã
// hóa), lỗi thì thử lại theo lũy thừa (1, 2, 4… phút, tối đa 1 giờ). Thư hỏng hẳn nằm ở màn "Hàng đợi email"
// (/system-config/mail-outbox) để xem lỗi và gửi lại. Đặt MAIL_BLOCK_OUTGOING=true (mặc định ngoài production) thì
// không thư nào ra ngoài, kể cả SMTP lưu trong DB.
//
//	err := pgx.BeginFunc(ctx, pool, func(tx pgx.Tx) error {
//	    // ... ghi phiếu thu ...
//	    _, err := mail.Enqueue(ctx, tx, mail.ParentNotice("ph@example.com",
//	        "Xác nhận đã nhận học phí — Nguyễn Văn A", "Kính gửi Quý phụ huynh,\n...", "", ""))
//	    return err
//	})
//
// Thư cảnh báo vận hành gửi danh sách "Email nhận Ticket" (sendOperationalAlertEmail của Laravel): OperationalAlert.
//
// Gói này không phụ thuộc iam để mọi module (kể cả iam) gọi được; màn quản trị nằm ở mail/mailapi.
package mail

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net/mail"
	"regexp"
	"strings"
	"unicode/utf8"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/notify"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/notify/mail/maildb"
)

// DB: pool hoặc transaction (pgx). Xếp thư trong transaction của nghiệp vụ thì rollback cùng thao tác.
type DB = maildb.DBTX

// Mẫu thư (render lúc gửi, xem templates.go). Data của Message phải đúng kiểu của mẫu.
const (
	// TemplateNotice: thư gửi phụ huynh / khách (App\Mail\ParentNotice, emails/parent-notice.blade.php). Data: Notice.
	TemplateNotice = "notice"
	// TemplateTicket: thư ticket / cảnh báo vận hành / gửi thử (emails/ticket-notification.blade.php). Data: Ticket.
	TemplateTicket = "ticket"
	// TemplatePasswordReset: link đặt lại mật khẩu (ResetPassword của Laravel). Data: PasswordReset.
	TemplatePasswordReset = "password_reset"
	// TemplateText: thư chỉ có chữ (Mail::raw). Data: Text.
	TemplateText = "text"
)

// Loại thư hay dùng (cột kind, lọc ở màn quản trị). Module tự đặt loại khác được (chữ thường, số, "_", ".").
const (
	KindParentNotice  = "parent_notice"
	KindOpsAlert      = "ops_alert"
	KindTicket        = "ticket"
	KindPasswordReset = "password_reset"
	KindTest          = "test"
)

// Kinds: nhãn tiếng Việt của các loại thư cho ô lọc.
var Kinds = []struct {
	Value string `json:"value"`
	Label string `json:"label"`
}{
	{KindParentNotice, "Thư phụ huynh / khách"},
	{KindOpsAlert, "Cảnh báo vận hành"},
	{KindTicket, "Ticket hỗ trợ"},
	{KindPasswordReset, "Đặt lại mật khẩu"},
	{KindTest, "Gửi thử"},
}

// DefaultMaxAttempts: số lần thử mặc định (khoảng 4 giờ với lũy thừa 1, 2, 4… phút, trần 1 giờ).
const DefaultMaxAttempts = 8

// Message là một thư cho MỘT người nhận.
type Message struct {
	To      string // địa chỉ email người nhận (bắt buộc)
	ToName  string // tên hiển thị (tùy chọn)
	Subject string // tiêu đề (bắt buộc, tối đa 255 ký tự, cắt bớt nếu dài)
	// Template + Data: mẫu thư và dữ liệu đúng kiểu (Notice, Ticket, PasswordReset, Text). Đường dẫn trong Data bắt
	// đầu bằng "/" được ghép PUBLIC_URL lúc gửi.
	Template string
	Data     any
	// Kind: loại thư cho màn quản trị; trống = theo mẫu.
	Kind string
	// MaxAttempts: số lần thử tối đa; 0 = DefaultMaxAttempts.
	MaxAttempts int
	// Sensitive: thư chứa bí mật (link đặt lại mật khẩu): màn quản trị không hiện nội dung, gửi xong thì xóa dữ liệu.
	Sensitive bool
	// RefType / RefID: đối tượng nghiệp vụ (vd. "trial_booking", id) để lọc / tra cứu.
	RefType, RefID string
	// CreatedBy: người thao tác gây ra thư (nil = hệ thống).
	CreatedBy *uuid.UUID
	// Fallback: thư hỏng hẳn (hết lượt thử hoặc máy chủ từ chối địa chỉ) thì gửi thông báo này trong ứng dụng, vd.
	// nhắc người phụ trách gửi xác nhận cho phụ huynh qua Zalo (AppointmentConfirmation của Laravel).
	Fallback *Fallback
}

// Fallback: người nhận và nội dung thông báo trong ứng dụng khi thư không gửi được.
type Fallback struct {
	UserIDs      []uuid.UUID         `json:"user_ids"`
	Notification notify.Notification `json:"notification"`
}

// Notice: dữ liệu mẫu TemplateNotice (ParentNotice: tiêu đề, nội dung giữ xuống dòng, nút hành động tùy chọn).
type Notice struct {
	Title      string `json:"title"`
	Body       string `json:"body"`
	ActionURL  string `json:"action_url,omitempty"`
	ActionText string `json:"action_text,omitempty"` // trống = "Mở"
}

// Text: dữ liệu mẫu TemplateText (Mail::raw).
type Text struct {
	Body string `json:"body"`
}

// PasswordReset: dữ liệu mẫu TemplatePasswordReset.
type PasswordReset struct {
	Name          string `json:"name"`
	URL           string `json:"url"` // đường dẫn đặt lại ("/reset-password?..."), ghép PUBLIC_URL lúc gửi
	ExpireMinutes int    `json:"expire_minutes"`
}

// ParentNotice dựng thư gửi phụ huynh / khách (ParentMessenger::send của Laravel).
func ParentNotice(to, subject, body, actionURL, actionText string) Message {
	return Message{To: to, Subject: subject, Template: TemplateNotice, Kind: KindParentNotice,
		Data: Notice{Title: subject, Body: body, ActionURL: actionURL, ActionText: actionText}}
}

var kindRe = regexp.MustCompile(`^[a-z][a-z0-9_.]{0,63}$`)

// ErrInvalidAddress: địa chỉ người nhận sai định dạng.
var ErrInvalidAddress = errors.New("mail: địa chỉ người nhận không hợp lệ")

// NormalizeAddress trả địa chỉ email chữ thường nếu hợp lệ (một địa chỉ, không kèm tên), ngược lại "".
func NormalizeAddress(s string) string {
	s = strings.ToLower(strings.TrimSpace(s))
	if s == "" || len(s) > 254 || strings.ContainsAny(s, " \t\r\n<>,;\"") {
		return ""
	}
	a, err := mail.ParseAddress(s)
	if err != nil || a.Address != s {
		return ""
	}
	_, domain, _ := strings.Cut(s, "@")
	if !strings.Contains(domain, ".") || strings.HasPrefix(domain, ".") || strings.HasSuffix(domain, ".") {
		return ""
	}
	return s
}

// Enqueue xếp một thư vào hàng đợi bằng db (truyền transaction của nghiệp vụ để thư chỉ đi khi nghiệp vụ commit).
// Trả id thư. Địa chỉ sai định dạng trả ErrInvalidAddress; mẫu lạ hoặc dữ liệu không đúng kiểu trả lỗi.
func Enqueue(ctx context.Context, db DB, m Message) (uuid.UUID, error) {
	to := NormalizeAddress(m.To)
	if to == "" {
		return uuid.Nil, ErrInvalidAddress
	}
	subject := truncate(oneLine(m.Subject), 255)
	if subject == "" {
		return uuid.Nil, errors.New("mail: thiếu tiêu đề")
	}
	if _, ok := templates[m.Template]; !ok {
		return uuid.Nil, fmt.Errorf("mail: mẫu thư %q không có", m.Template)
	}
	data, err := json.Marshal(m.Data)
	if err != nil {
		return uuid.Nil, fmt.Errorf("mail: dữ liệu mẫu: %w", err)
	}
	// Render thử ngay để lỗi dữ liệu lộ ra ở nơi gọi, không nằm im trong hàng đợi.
	if _, _, err := render(m.Template, data, ""); err != nil {
		return uuid.Nil, err
	}
	kind := m.Kind
	if kind == "" {
		kind = m.Template
	}
	if !kindRe.MatchString(kind) {
		return uuid.Nil, fmt.Errorf("mail: loại thư %q không hợp lệ", kind)
	}
	max := m.MaxAttempts
	if max <= 0 {
		max = DefaultMaxAttempts
	}
	max = min(max, 20)
	var fb []byte
	if m.Fallback != nil && len(m.Fallback.UserIDs) > 0 {
		if fb, err = json.Marshal(m.Fallback); err != nil {
			return uuid.Nil, fmt.Errorf("mail: thông báo dự phòng: %w", err)
		}
	}
	var refType, refID *string
	if m.RefType != "" {
		if !kindRe.MatchString(m.RefType) {
			return uuid.Nil, fmt.Errorf("mail: loại đối tượng %q không hợp lệ", m.RefType)
		}
		refType = &m.RefType
		id := truncate(m.RefID, 100)
		refID = &id
	}
	return maildb.New(db).InsertMail(ctx, maildb.InsertMailParams{
		Kind: kind, ToAddress: to, ToName: truncate(oneLine(m.ToName), 255), Subject: subject, Template: m.Template,
		Data: data, Sensitive: m.Sensitive, MaxAttempts: int32(max), Fallback: fb, RefType: refType, RefID: refID,
		CreatedBy: m.CreatedBy,
	})
}

// oneLine bỏ xuống dòng (chặn chèn header) và khoảng trắng thừa.
func oneLine(s string) string {
	return strings.Join(strings.Fields(s), " ")
}

func truncate(s string, max int) string {
	if utf8.RuneCountInString(s) <= max {
		return s
	}
	r := []rune(s)
	return string(r[:max-1]) + "…"
}
