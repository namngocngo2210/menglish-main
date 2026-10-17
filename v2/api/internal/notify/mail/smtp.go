package mail

import (
	"bytes"
	"context"
	"crypto/rand"
	"crypto/tls"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"mime"
	"mime/quotedprintable"
	"net"
	"net/mail"
	"net/smtp"
	"net/textproto"
	"strconv"
	"strings"
	"time"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/notify/mail/maildb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/secretbox"
)

// SMTPConfig: hòm thư gửi lưu ở màn "Email nhận Ticket" (core.settings, như SystemSetting::getSmtpConfig của Laravel;
// mật khẩu mã hóa bằng secretbox với associated data là tên khóa).
type SMTPConfig struct {
	Host        string
	Port        int
	Encryption  string // tls (STARTTLS) | ssl (TLS ngay từ đầu, thường cổng 465) | null / "" (không bắt buộc mã hóa)
	Username    string
	Password    string
	FromAddress string
	FromName    string
}

// Khóa trong core.settings (catalogs ghi, xem catalogs/settings.go).
const keyMailPassword = "mail_password"

var smtpKeys = []string{"mail_host", "mail_port", "mail_encryption", "mail_username", keyMailPassword, "mail_from_address", "mail_from_name"}

// LoadSMTP đọc cấu hình SMTP; khóa chưa lưu dùng mặc định như màn cấu hình (smtp.gmail.com:587, TLS).
func LoadSMTP(ctx context.Context, db DB, box *secretbox.Box) (SMTPConfig, error) {
	rows, err := maildb.New(db).MailSettings(ctx, smtpKeys)
	if err != nil {
		return SMTPConfig{}, err
	}
	str := map[string]string{}
	var secret []byte
	encSet := false
	for _, r := range rows {
		if r.Key == keyMailPassword {
			secret = r.Secret
			continue
		}
		var s string
		if json.Unmarshal(r.Value, &s) != nil {
			var n json.Number
			if json.Unmarshal(r.Value, &n) == nil {
				s = n.String()
			}
		}
		if r.Key == "mail_encryption" && string(r.Value) != "null" {
			encSet = true
		}
		str[r.Key] = strings.TrimSpace(s)
	}
	def := func(k, d string) string {
		if v := str[k]; v != "" {
			return v
		}
		return d
	}
	c := SMTPConfig{
		Host: def("mail_host", "smtp.gmail.com"), Encryption: "tls", Username: str["mail_username"],
		FromAddress: def("mail_from_address", "noreply@menglish.edu.vn"), FromName: def("mail_from_name", "MEnglish Support"),
	}
	if encSet {
		c.Encryption = str["mail_encryption"]
	}
	c.Port, _ = strconv.Atoi(def("mail_port", "587"))
	if c.Port <= 0 {
		c.Port = 587
	}
	if len(secret) > 0 {
		if box == nil {
			return c, errors.New("mail: thiếu khóa giải mã mật khẩu SMTP")
		}
		pw, err := box.Open(secret, []byte(keyMailPassword))
		if err != nil {
			return c, fmt.Errorf("mail: không giải mã được mật khẩu SMTP: %w", err)
		}
		c.Password = string(pw)
	}
	return c, nil
}

// Outgoing: một thư đã render, sẵn sàng gửi.
type Outgoing struct {
	To, ToName    string
	Subject       string
	HTML, Text    string // HTML trống = thư chỉ có chữ
	MessageID     string
	Date          time.Time
	ReferenceKind string // loại thư (header X-MEnglish-Kind)
}

// Transport gửi một thư theo cấu hình SMTP. Test dùng bản giả (xem FakeTransport).
type Transport interface {
	Send(ctx context.Context, cfg SMTPConfig, m Outgoing) error
}

// SMTPTransport gửi qua net/smtp.
type SMTPTransport struct {
	Timeout time.Duration // cả phiên kết nối; 0 = 30 giây
}

func (t SMTPTransport) Send(ctx context.Context, cfg SMTPConfig, m Outgoing) error {
	if strings.TrimSpace(cfg.Host) == "" {
		return errors.New("chưa cấu hình máy chủ SMTP")
	}
	from := NormalizeAddress(cfg.FromAddress)
	if from == "" {
		return errors.New("email người gửi không hợp lệ")
	}
	timeout := t.Timeout
	if timeout <= 0 {
		timeout = 30 * time.Second
	}
	ctx, cancel := context.WithTimeout(ctx, timeout)
	defer cancel()
	addr := net.JoinHostPort(cfg.Host, strconv.Itoa(cfg.Port))
	tlsCfg := &tls.Config{ServerName: cfg.Host, MinVersion: tls.VersionTLS12}
	var conn net.Conn
	var err error
	d := &net.Dialer{}
	if cfg.Encryption == "ssl" {
		conn, err = (&tls.Dialer{NetDialer: d, Config: tlsCfg}).DialContext(ctx, "tcp", addr)
	} else {
		conn, err = d.DialContext(ctx, "tcp", addr)
	}
	if err != nil {
		return err
	}
	defer conn.Close()
	if dl, ok := ctx.Deadline(); ok {
		_ = conn.SetDeadline(dl)
	}
	c, err := smtp.NewClient(conn, cfg.Host)
	if err != nil {
		return err
	}
	defer c.Close()
	if cfg.Encryption != "ssl" {
		if ok, _ := c.Extension("STARTTLS"); ok {
			if err := c.StartTLS(tlsCfg); err != nil {
				return err
			}
		} else if cfg.Encryption == "tls" {
			return errors.New("máy chủ SMTP không hỗ trợ STARTTLS (tls)")
		}
	}
	if cfg.Username != "" && cfg.Password != "" {
		if ok, _ := c.Extension("AUTH"); ok {
			if err := c.Auth(smtp.PlainAuth("", cfg.Username, cfg.Password, cfg.Host)); err != nil {
				return err
			}
		}
	}
	if err := c.Mail(from); err != nil {
		return err
	}
	if err := c.Rcpt(m.To); err != nil {
		return err
	}
	w, err := c.Data()
	if err != nil {
		return err
	}
	if _, err := w.Write(buildMIME(cfg, m)); err != nil {
		return err
	}
	if err := w.Close(); err != nil {
		return err
	}
	return c.Quit()
}

// permanent: máy chủ từ chối hẳn địa chỉ / thư (5xx khi gửi), thử lại vô ích. Lỗi đăng nhập (535) không tính: sửa
// cấu hình xong thì các lần thử sau đi được.
func permanent(err error) bool {
	var te *textproto.Error
	if errors.As(err, &te) {
		return te.Code >= 550 && te.Code <= 554
	}
	return errors.Is(err, ErrInvalidAddress)
}

func newMessageID(domain string) string {
	b := make([]byte, 12)
	_, _ = rand.Read(b)
	if domain == "" {
		domain = "menglish.local"
	}
	return "<" + hex.EncodeToString(b) + "@" + domain + ">"
}

// buildMIME: thư UTF-8, multipart/alternative (chữ + HTML) mã quoted-printable, tiêu đề mã hóa theo RFC 2047.
func buildMIME(cfg SMTPConfig, m Outgoing) []byte {
	var b bytes.Buffer
	h := func(k, v string) { b.WriteString(k + ": " + v + "\r\n") }
	from := mail.Address{Name: cfg.FromName, Address: NormalizeAddress(cfg.FromAddress)}
	to := mail.Address{Name: m.ToName, Address: m.To}
	date := m.Date
	if date.IsZero() {
		date = time.Now()
	}
	_, domain, _ := strings.Cut(from.Address, "@")
	id := m.MessageID
	if id == "" {
		id = newMessageID(domain)
	}
	h("From", from.String())
	h("To", to.String())
	h("Subject", mime.BEncoding.Encode("UTF-8", oneLine(m.Subject)))
	h("Date", date.Format(time.RFC1123Z))
	h("Message-ID", id)
	h("MIME-Version", "1.0")
	h("Auto-Submitted", "auto-generated")
	if m.ReferenceKind != "" {
		h("X-MEnglish-Kind", m.ReferenceKind)
	}
	part := func(ctype, body string) {
		b.WriteString("Content-Type: " + ctype + "; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n")
		qp := quotedprintable.NewWriter(&b)
		_, _ = qp.Write([]byte(strings.ReplaceAll(strings.ReplaceAll(body, "\r\n", "\n"), "\n", "\r\n")))
		_ = qp.Close()
		b.WriteString("\r\n")
	}
	if m.HTML == "" {
		part("text/plain", m.Text)
		return b.Bytes()
	}
	rb := make([]byte, 12)
	_, _ = rand.Read(rb)
	boundary := "mg-" + base64.RawURLEncoding.EncodeToString(rb)
	h("Content-Type", `multipart/alternative; boundary="`+boundary+`"`)
	b.WriteString("\r\n")
	b.WriteString("--" + boundary + "\r\n")
	part("text/plain", m.Text)
	b.WriteString("--" + boundary + "\r\n")
	part("text/html", m.HTML)
	b.WriteString("--" + boundary + "--\r\n")
	return b.Bytes()
}
