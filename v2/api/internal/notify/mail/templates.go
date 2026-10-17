package mail

import (
	"bytes"
	"embed"
	"encoding/json"
	"fmt"
	"html/template"
	"regexp"
	"strings"
	"time"
)

// Mẫu thư render bằng html/template (nội dung người dùng nhập tự được thoát ký tự) kèm bản chữ thuần cho trình đọc
// thư không hiện HTML. Mẫu HTML giữ giao diện các view Blade của Laravel (resources/views/emails/*).

//go:embed templates/*.html
var templateFS embed.FS

var htmlTemplates = template.Must(template.ParseFS(templateFS, "templates/*.html"))

// renderCtx: thông tin lúc gửi: địa chỉ hệ thống (ghép vào đường dẫn "/...") và thời điểm thư được xếp.
type renderCtx struct {
	base string
	at   time.Time
}

type renderer func(data []byte, rc renderCtx) (html, text string, err error)

var templates = map[string]renderer{
	TemplateNotice:        renderNotice,
	TemplateTicket:        renderTicket,
	TemplatePasswordReset: renderPasswordReset,
	TemplateText:          renderText,
}

var vnZone = time.FixedZone("ICT", 7*3600)

// render: mẫu + dữ liệu JSON → HTML và chữ thuần. base = PUBLIC_URL (trống thì giữ đường dẫn tương đối).
func render(name string, data []byte, base string) (string, string, error) {
	return renderAt(name, data, renderCtx{base: base, at: time.Now()})
}

func renderAt(name string, data []byte, rc renderCtx) (string, string, error) {
	fn, ok := templates[name]
	if !ok {
		return "", "", fmt.Errorf("mail: mẫu thư %q không có", name)
	}
	return fn(data, rc)
}

func execHTML(name string, v any) (string, error) {
	var b bytes.Buffer
	if err := htmlTemplates.ExecuteTemplate(&b, name, v); err != nil {
		return "", err
	}
	return b.String(), nil
}

// absURL ghép PUBLIC_URL vào đường dẫn trong ứng dụng ("/..."); URL http(s) giữ nguyên; còn lại bỏ.
func absURL(base, u string) string {
	u = strings.TrimSpace(u)
	switch {
	case u == "":
		return ""
	case strings.HasPrefix(u, "/") && !strings.HasPrefix(u, "//"):
		return strings.TrimRight(base, "/") + u
	case strings.HasPrefix(u, "https://") || strings.HasPrefix(u, "http://"):
		return u
	}
	return ""
}

func decode[T any](data []byte) (T, error) {
	var v T
	if err := json.Unmarshal(data, &v); err != nil {
		return v, fmt.Errorf("mail: dữ liệu mẫu không đúng kiểu: %w", err)
	}
	return v, nil
}

// ───── ParentNotice ─────

func renderNotice(data []byte, rc renderCtx) (string, string, error) {
	n, err := decode[Notice](data)
	if err != nil {
		return "", "", err
	}
	if strings.TrimSpace(n.Title) == "" || strings.TrimSpace(n.Body) == "" {
		return "", "", fmt.Errorf("mail: thư phụ huynh thiếu tiêu đề hoặc nội dung")
	}
	n.ActionURL = absURL(rc.base, n.ActionURL)
	if n.ActionText == "" {
		n.ActionText = "Mở"
	}
	html, err := execHTML("notice.html", n)
	if err != nil {
		return "", "", err
	}
	var t strings.Builder
	t.WriteString(n.Title + "\n\n" + n.Body + "\n")
	if n.ActionURL != "" {
		t.WriteString("\n" + n.ActionText + ": " + n.ActionURL + "\n")
	}
	t.WriteString("\n--\nEmail gửi tự động từ hệ thống MEnglish, vui lòng không trả lời email này. Cần hỗ trợ, phụ huynh vui lòng liên hệ trung tâm.\n")
	return html, t.String(), nil
}

// ───── Ticket / cảnh báo vận hành ─────

// Ticket: dữ liệu mẫu TemplateTicket (biến của emails/ticket-notification.blade.php).
type Ticket struct {
	Code  string `json:"code,omitempty"`  // mã ticket / đối tượng, vd. "TK-0012", "TEST"
	Title string `json:"title,omitempty"` // tên ticket / đối tượng
	// Subject: tiêu đề hiện trong thư (subjectTitle); trống = "[#Code] Title" hoặc "Thông báo hệ thống"
	Subject string `json:"subject,omitempty"`
	Content string `json:"content"`
	// Type: new | reply | status | alert; reply thì nội dung có tên người gửi ở đầu
	Type          string `json:"type,omitempty"`
	SenderName    string `json:"sender_name,omitempty"`    // trống = "Hệ thống"
	Status        string `json:"status,omitempty"`         // khóa màu: resolved, in_progress, closed, overdue, ...
	StatusLabel   string `json:"status_label,omitempty"`   // trống = "Thông báo"
	Priority      string `json:"priority,omitempty"`       // khóa màu: urgent, high, medium, low
	PriorityLabel string `json:"priority_label,omitempty"` // trống = không hiện
	CategoryLabel string `json:"category_label,omitempty"` // trống = không hiện
	ActionURL     string `json:"action_url,omitempty"`     // "/..." hoặc http(s); trống = trang chủ
	ActionText    string `json:"action_text,omitempty"`    // trống = "Xem Ticket #Code" / "Xem chi tiết"
}

type ticketView struct {
	Title, Content, SenderName, StatusLabel, PriorityLabel, CategoryLabel, ActionURL, ActionText, Time string
	Reply                                                                                              bool
	StatusStyle, PriorityStyle                                                                         template.CSS
}

// Số tiền trong thư luôn dùng dấu chấm ngăn cách (13.500.000) như $formatMoneyDots của view Laravel.
var moneyCommaRe = regexp.MustCompile(`\b(\d{1,3}(?:,\d{3})+)\b`)

func moneyDots(s string) string {
	return moneyCommaRe.ReplaceAllStringFunc(s, func(m string) string { return strings.ReplaceAll(m, ",", ".") })
}

func inList(s string, list ...string) bool {
	for _, v := range list {
		if s == v {
			return true
		}
	}
	return false
}

func statusStyle(k string) template.CSS {
	switch {
	case inList(k, "resolved", "approved", "completed", "success"):
		return "background-color: #dcfce7; color: #15803d; border: 1px solid #bbf7d0;"
	case inList(k, "in_progress", "processing"):
		return "background-color: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd;"
	case inList(k, "closed", "rejected", "canceled"):
		return "background-color: #f1f5f9; color: #475569; border: 1px solid #cbd5e1;"
	case inList(k, "overdue", "urgent", "danger"):
		return "background-color: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5;"
	}
	return "background-color: #fef3c7; color: #b45309; border: 1px solid #fde68a;"
}

func priorityStyle(k string) template.CSS {
	k = strings.ToLower(k)
	switch {
	case strings.Contains(k, "urgent") || strings.Contains(k, "overdue"):
		return "background-color: #ffe4e6; color: #e11d48; border: 1px solid #fecdd3;"
	case strings.Contains(k, "high"):
		return "background-color: #fef3c7; color: #b45309; border: 1px solid #fde68a;"
	case strings.Contains(k, "medium"):
		return "background-color: #e0f2fe; color: #0284c7; border: 1px solid #bae6fd;"
	}
	return "background-color: #f1f5f9; color: #475569; border: 1px solid #cbd5e1;"
}

func ticketTitle(t Ticket) string {
	switch {
	case t.Subject != "":
		return t.Subject
	case t.Code != "":
		return "[#" + t.Code + "] " + t.Title
	}
	return "Thông báo hệ thống"
}

func renderTicket(data []byte, rc renderCtx) (string, string, error) {
	t, err := decode[Ticket](data)
	if err != nil {
		return "", "", err
	}
	v := ticketView{
		Title: moneyDots(ticketTitle(t)), Content: moneyDots(t.Content), SenderName: t.SenderName,
		StatusLabel: t.StatusLabel, PriorityLabel: t.PriorityLabel, CategoryLabel: t.CategoryLabel,
		ActionURL: absURL(rc.base, t.ActionURL), ActionText: t.ActionText, Reply: t.Type == "reply",
		StatusStyle: statusStyle(cmpOr(t.Status, "info")), PriorityStyle: priorityStyle(cmpOr(t.Priority, "medium")),
		Time: rc.at.In(vnZone).Format("15:04 02/01/2006"),
	}
	if v.SenderName == "" {
		v.SenderName = "Hệ thống"
	}
	if v.StatusLabel == "" {
		v.StatusLabel = "Thông báo"
	}
	if v.ActionURL == "" {
		v.ActionURL = absURL(rc.base, "/")
	}
	if v.ActionText == "" {
		v.ActionText = "Xem chi tiết"
		if t.Code != "" {
			v.ActionText = "Xem Ticket #" + t.Code
		}
	}
	html, err := execHTML("ticket.html", v)
	if err != nil {
		return "", "", err
	}
	var b strings.Builder
	b.WriteString(v.Title + "\n\n")
	if v.Reply {
		b.WriteString(v.SenderName + ": ")
	}
	b.WriteString(v.Content + "\n\n")
	tags := []string{"● " + v.StatusLabel}
	if v.PriorityLabel != "" {
		tags = append(tags, "⚡ "+v.PriorityLabel)
	}
	if v.CategoryLabel != "" {
		tags = append(tags, "📁 "+v.CategoryLabel)
	}
	b.WriteString(strings.Join(tags, " · ") + "\n")
	b.WriteString("Người gửi: " + v.SenderName + " • " + v.Time + "\n")
	b.WriteString(v.ActionText + ": " + v.ActionURL + "\n")
	return html, b.String(), nil
}

func cmpOr(a, b string) string {
	if a != "" {
		return a
	}
	return b
}

// ───── Đặt lại mật khẩu ─────

func renderPasswordReset(data []byte, rc renderCtx) (string, string, error) {
	p, err := decode[PasswordReset](data)
	if err != nil {
		return "", "", err
	}
	url := absURL(rc.base, p.URL)
	if url == "" {
		return "", "", fmt.Errorf("mail: thiếu link đặt lại mật khẩu")
	}
	if p.ExpireMinutes <= 0 {
		p.ExpireMinutes = 60
	}
	greeting := "Xin chào!"
	if n := strings.TrimSpace(p.Name); n != "" {
		greeting = "Xin chào " + n + "!"
	}
	v := struct {
		Greeting, URL string
		ExpireMinutes int
	}{greeting, url, p.ExpireMinutes}
	html, err := execHTML("password_reset.html", v)
	if err != nil {
		return "", "", err
	}
	text := fmt.Sprintf("%s\n\nBạn nhận được email này vì có yêu cầu đặt lại mật khẩu cho tài khoản của bạn.\n\n"+
		"Đặt lại mật khẩu: %s\n\nLink đặt lại mật khẩu sẽ hết hạn sau %d phút.\n"+
		"Nếu bạn không yêu cầu đặt lại mật khẩu, hãy bỏ qua email này.\n\nTrân trọng,\nMEnglish\n", greeting, url, p.ExpireMinutes)
	return html, text, nil
}

// ───── Chỉ chữ (Mail::raw) ─────

func renderText(data []byte, _ renderCtx) (string, string, error) {
	t, err := decode[Text](data)
	if err != nil {
		return "", "", err
	}
	if strings.TrimSpace(t.Body) == "" {
		return "", "", fmt.Errorf("mail: thư trống")
	}
	return "", t.Body, nil
}
