package mail

import (
	"context"
	"errors"
	"io"
	"mime"
	"mime/multipart"
	"net"
	"net/mail"
	"net/textproto"
	"strconv"
	"strings"
	"testing"
	"time"
)

func TestNormalizeAddress(t *testing.T) {
	for in, want := range map[string]string{
		" PH@Example.VN ":          "ph@example.vn",
		"a@b":                      "",
		"Tên <a@b.vn>":             "",
		"a@b.vn, c@d.vn":           "",
		"a@b.vn\r\nBcc: x@y.vn":    "",
		"":                         "",
		"nguyen.van.a+1@gmail.com": "nguyen.van.a+1@gmail.com",
	} {
		if got := NormalizeAddress(in); got != want {
			t.Errorf("NormalizeAddress(%q) = %q, muốn %q", in, got, want)
		}
	}
}

func TestParentRecipientAndDayLabel(t *testing.T) {
	if got := ParentRecipient("", "student12@student.menglish.edu.vn", "sai", "PH@Mail.vn"); got != "ph@mail.vn" {
		t.Fatalf("ParentRecipient = %q", got)
	}
	if got := ParentRecipient("hv@student.menglish.edu.vn"); got != "" {
		t.Fatalf("email tự sinh phải bị bỏ: %q", got)
	}
	if got := DayLabel(time.Date(2026, 10, 4, 0, 0, 0, 0, time.UTC)); got != "Chủ nhật 04/10/2026" {
		t.Fatalf("DayLabel = %q", got)
	}
	if got := DayLabel(time.Date(2026, 10, 3, 0, 0, 0, 0, time.UTC)); got != "Thứ Bảy 03/10/2026" {
		t.Fatalf("DayLabel = %q", got)
	}
}

func TestBackoff(t *testing.T) {
	want := []time.Duration{time.Minute, 2 * time.Minute, 4 * time.Minute, 8 * time.Minute, 16 * time.Minute, 32 * time.Minute, time.Hour, time.Hour}
	for i, w := range want {
		if got := Backoff(i + 1); got != w {
			t.Errorf("Backoff(%d) = %v, muốn %v", i+1, got, w)
		}
	}
}

func TestRenderNoticeEscapesAndLinks(t *testing.T) {
	data := []byte(`{"title":"Xác nhận lịch học thử — <b>An</b>","body":"Dòng 1\nDòng 2 <script>x</script>","action_url":"/placement/take/abc","action_text":"Vào làm bài test"}`)
	html, text, err := render(TemplateNotice, data, "https://beta.meducation.vn/")
	if err != nil {
		t.Fatal(err)
	}
	for _, s := range []string{"&lt;b&gt;An&lt;/b&gt;", "&lt;script&gt;", `href="https://beta.meducation.vn/placement/take/abc"`, "Vào làm bài test", "MENGLISH"} {
		if !strings.Contains(html, s) {
			t.Errorf("HTML thiếu %q", s)
		}
	}
	if strings.Contains(html, "<script>") {
		t.Error("HTML không được chứa thẻ script của người dùng")
	}
	if !strings.Contains(text, "Dòng 1\nDòng 2") || !strings.Contains(text, "Vào làm bài test: https://beta.meducation.vn/placement/take/abc") {
		t.Errorf("bản chữ: %q", text)
	}
	// Không có nút: không có link; URL lạ (javascript:) bị bỏ.
	html, _, err = render(TemplateNotice, []byte(`{"title":"T","body":"B","action_url":"javascript:alert(1)"}`), "")
	if err != nil {
		t.Fatal(err)
	}
	if strings.Contains(html, "javascript") || strings.Contains(html, "<a ") {
		t.Errorf("không được có nút với URL lạ: %s", html)
	}
	if _, _, err := render(TemplateNotice, []byte(`{"title":"T"}`), ""); err == nil {
		t.Error("thiếu nội dung phải lỗi")
	}
}

func TestRenderTicketDefaultsAndMoney(t *testing.T) {
	at := time.Date(2026, 10, 17, 2, 5, 0, 0, time.UTC)
	data := []byte(`{"code":"PT-1","title":"Giao dịch","content":"Số tiền: 13,500,000đ","type":"reply","sender_name":"Kế toán","status":"approved","status_label":"Đã duyệt","priority":"urgent","priority_label":"Gấp","category_label":"Tài chính","action_url":"/tuition/history"}`)
	html, text, err := renderAt(TemplateTicket, data, renderCtx{base: "https://x.vn", at: at})
	if err != nil {
		t.Fatal(err)
	}
	for _, s := range []string{"[#PT-1] Giao dịch", "13.500.000đ", "<strong style=\"color: #0f172a;\">Kế toán:</strong>", "● Đã duyệt", "⚡ Gấp", "📁 Tài chính",
		"background-color: #dcfce7", "background-color: #ffe4e6", "09:05 17/10/2026", `href="https://x.vn/tuition/history"`, "Xem Ticket #PT-1"} {
		if !strings.Contains(html, s) {
			t.Errorf("HTML thiếu %q", s)
		}
	}
	if !strings.Contains(text, "Kế toán: Số tiền: 13.500.000đ") || !strings.Contains(text, "Xem Ticket #PT-1: https://x.vn/tuition/history") {
		t.Errorf("bản chữ: %q", text)
	}
	html, _, err = renderAt(TemplateTicket, []byte(`{"content":"x"}`), renderCtx{base: "https://x.vn", at: at})
	if err != nil {
		t.Fatal(err)
	}
	for _, s := range []string{"Thông báo hệ thống", "● Thông báo", "Người gửi:</strong> Hệ thống", "Xem chi tiết", `href="https://x.vn/"`, "background-color: #fef3c7"} {
		if !strings.Contains(html, s) {
			t.Errorf("mặc định thiếu %q", s)
		}
	}
	if strings.Contains(html, "⚡") || strings.Contains(html, "📁") {
		t.Error("không có mức ưu tiên / danh mục thì không hiện nhãn")
	}
}

func TestRenderPasswordResetAndText(t *testing.T) {
	html, text, err := render(TemplatePasswordReset, []byte(`{"name":"An","url":"/reset-password?token=abc&email=a%40b.vn","expire_minutes":60}`), "https://x.vn")
	if err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(html, "Xin chào An!") || !strings.Contains(html, `href="https://x.vn/reset-password?token=abc&amp;email=a%40b.vn"`) ||
		!strings.Contains(html, "hết hạn sau 60 phút") {
		t.Errorf("HTML: %s", html)
	}
	if !strings.Contains(text, "Đặt lại mật khẩu: https://x.vn/reset-password?token=abc&email=a%40b.vn") {
		t.Errorf("bản chữ: %q", text)
	}
	if _, _, err := render(TemplatePasswordReset, []byte(`{"url":""}`), "x"); err == nil {
		t.Error("thiếu link phải lỗi")
	}
	html, text, err = render(TemplateText, []byte(`{"body":"Chào bạn"}`), "")
	if err != nil || html != "" || text != "Chào bạn" {
		t.Fatalf("Text: %q %q %v", html, text, err)
	}
	if _, _, err := render("khong_co", []byte(`{}`), ""); err == nil {
		t.Error("mẫu lạ phải lỗi")
	}
}

func TestBuildMIME(t *testing.T) {
	cfg := SMTPConfig{FromAddress: "noreply@menglish.edu.vn", FromName: "MEnglish Hỗ trợ"}
	raw := buildMIME(cfg, Outgoing{To: "ph@example.vn", ToName: "Phụ huynh", Subject: "Xác nhận lịch\r\nBcc: x@y.vn", HTML: "<p>Chào <b>bạn</b></p>", Text: "Chào bạn\nDòng 2"})
	msg, err := mail.ReadMessage(strings.NewReader(string(raw)))
	if err != nil {
		t.Fatal(err)
	}
	dec := new(mime.WordDecoder)
	subj, _ := dec.DecodeHeader(msg.Header.Get("Subject"))
	if subj != "Xác nhận lịch Bcc: x@y.vn" || msg.Header.Get("Bcc") != "" {
		t.Fatalf("tiêu đề: %q, bcc: %q", subj, msg.Header.Get("Bcc"))
	}
	from, err := mail.ParseAddress(msg.Header.Get("From"))
	if err != nil {
		t.Fatal(err)
	}
	if from.Name != "MEnglish Hỗ trợ" || from.Address != "noreply@menglish.edu.vn" {
		t.Fatalf("From: %+v", from)
	}
	mt, params, err := mime.ParseMediaType(msg.Header.Get("Content-Type"))
	if err != nil || mt != "multipart/alternative" {
		t.Fatalf("Content-Type: %v %v", mt, err)
	}
	mr := multipart.NewReader(msg.Body, params["boundary"])
	var parts []string
	for {
		p, err := mr.NextPart()
		if err == io.EOF {
			break
		}
		if err != nil {
			t.Fatal(err)
		}
		b, _ := io.ReadAll(p) // multipart tự giải quoted-printable
		parts = append(parts, p.Header.Get("Content-Type")+"|"+string(b))
	}
	if len(parts) != 2 || !strings.HasPrefix(parts[0], "text/plain") || !strings.Contains(parts[0], "Chào bạn\r\nDòng 2") ||
		!strings.HasPrefix(parts[1], "text/html") || !strings.Contains(parts[1], "<b>bạn</b>") {
		t.Fatalf("các phần: %q", parts)
	}
	// Thư chỉ chữ
	raw = buildMIME(cfg, Outgoing{To: "a@b.vn", Subject: "S", Text: "chữ"})
	msg, _ = mail.ReadMessage(strings.NewReader(string(raw)))
	if !strings.HasPrefix(msg.Header.Get("Content-Type"), "text/plain") {
		t.Fatalf("Content-Type: %q", msg.Header.Get("Content-Type"))
	}
}

func TestSMTPErrorHints(t *testing.T) {
	cases := map[string]string{
		"535 5.7.8 Username and Password not accepted": "Mật khẩu ứng dụng",
		"dial tcp 1.2.3.4:587: i/o timeout":            "SSL cổng 465",
		"dial tcp: lookup smtp.sai.vn: no such host":   "SMTP Host",
		"tls: failed to verify certificate":            "TLS đi với cổng 587",
		"553 sender rejected":                          "Email người gửi",
	}
	for msg, want := range cases {
		if h := smtpErrorHint(msg); !strings.Contains(h, want) {
			t.Errorf("gợi ý cho %q = %q", msg, h)
		}
	}
	if smtpErrorHint("lạ") != "" {
		t.Error("lỗi lạ không có gợi ý")
	}
	if n := fromAddressNote(SMTPConfig{Host: "smtp.gmail.com", Username: "a@gmail.com", FromAddress: "b@x.vn"}); !strings.Contains(n, "Gmail sẽ tự đổi") {
		t.Errorf("ghi chú From: %q", n)
	}
	if fromAddressNote(SMTPConfig{Username: "a@x.vn", FromAddress: "A@x.vn"}) != "" {
		t.Error("trùng nhau thì không ghi chú")
	}
}

// fakeSMTP: máy chủ SMTP tối giản (không TLS, không AUTH) để thử SMTPTransport. rcptCode khác 250 thì từ chối RCPT.
func fakeSMTP(t *testing.T, rcptCode int) (addr string, got chan string) {
	t.Helper()
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { ln.Close() })
	got = make(chan string, 1)
	go func() {
		conn, err := ln.Accept()
		if err != nil {
			return
		}
		defer conn.Close()
		tp := textproto.NewConn(conn)
		_ = tp.PrintfLine("220 fake ESMTP")
		var data strings.Builder
		for {
			line, err := tp.ReadLine()
			if err != nil {
				return
			}
			cmd := strings.ToUpper(strings.SplitN(line, " ", 2)[0])
			switch cmd {
			case "EHLO", "HELO":
				_ = tp.PrintfLine("250-fake\r\n250 8BITMIME")
			case "MAIL":
				_ = tp.PrintfLine("250 OK")
			case "RCPT":
				if rcptCode != 250 {
					_ = tp.PrintfLine("%d 5.1.1 mailbox unavailable", rcptCode)
				} else {
					_ = tp.PrintfLine("250 OK")
				}
			case "DATA":
				_ = tp.PrintfLine("354 go")
				b, _ := io.ReadAll(tp.DotReader())
				data.Write(b)
				_ = tp.PrintfLine("250 queued")
			case "QUIT":
				_ = tp.PrintfLine("221 bye")
				got <- data.String()
				return
			default:
				_ = tp.PrintfLine("250 OK")
			}
		}
	}()
	return ln.Addr().String(), got
}

func TestSMTPTransport(t *testing.T) {
	addr, got := fakeSMTP(t, 250)
	host, p := hostPort(addr)
	cfg := SMTPConfig{Host: host, Port: p, Encryption: "null", FromAddress: "noreply@menglish.edu.vn", FromName: "MEnglish"}
	err := SMTPTransport{Timeout: 5 * time.Second}.Send(context.Background(), cfg, Outgoing{To: "ph@example.vn", Subject: "Thử", Text: "Xin chào", HTML: "<p>Xin chào</p>"})
	if err != nil {
		t.Fatal(err)
	}
	select {
	case d := <-got:
		if !strings.Contains(d, "To: <ph@example.vn>") || !strings.Contains(d, "multipart/alternative") {
			t.Fatalf("thư nhận được: %s", d)
		}
	case <-time.After(5 * time.Second):
		t.Fatal("máy chủ không nhận được thư")
	}

	// Máy chủ từ chối địa chỉ: lỗi vĩnh viễn, không thử lại.
	addr, _ = fakeSMTP(t, 550)
	host, p = hostPort(addr)
	cfg.Host, cfg.Port = host, p
	err = SMTPTransport{Timeout: 5 * time.Second}.Send(context.Background(), cfg, Outgoing{To: "x@example.vn", Subject: "Thử", Text: "x"})
	if err == nil || !permanent(err) {
		t.Fatalf("muốn lỗi vĩnh viễn, được %v", err)
	}
	// Mã hóa tls bắt buộc STARTTLS mà máy chủ không hỗ trợ → lỗi (không gửi rõ).
	addr, _ = fakeSMTP(t, 250)
	host, p = hostPort(addr)
	cfg.Host, cfg.Port, cfg.Encryption = host, p, "tls"
	err = SMTPTransport{Timeout: 5 * time.Second}.Send(context.Background(), cfg, Outgoing{To: "x@example.vn", Subject: "Thử", Text: "x"})
	if err == nil || !strings.Contains(err.Error(), "STARTTLS") || permanent(err) {
		t.Fatalf("muốn lỗi STARTTLS, được %v", err)
	}
	if err := (SMTPTransport{}).Send(context.Background(), SMTPConfig{}, Outgoing{}); err == nil || errors.Is(err, ErrInvalidAddress) {
		t.Fatalf("thiếu máy chủ: %v", err)
	}
}

func hostPort(addr string) (string, int) {
	h, p, _ := net.SplitHostPort(addr)
	n, _ := strconv.Atoi(p)
	return h, n
}
