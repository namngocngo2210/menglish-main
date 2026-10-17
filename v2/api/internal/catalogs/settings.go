package catalogs

import (
	"context"
	"encoding/json"
	"net/mail"
	"slices"
	"strconv"
	"strings"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/catalogs/catalogsdb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

// Cấu hình hệ thống dạng khóa – giá trị (core.settings, như system_settings của Laravel) và màn "Email nhận Ticket"
// (SystemConfigController@ticketEmails / updateTicketEmails).

// settings đọc nhiều khóa một lúc (khóa chưa lưu thì không có trong map).
func (s *Service) settings(ctx context.Context, keys ...string) (map[string]json.RawMessage, error) {
	rows, err := s.q.GetSettings(ctx, keys)
	if err != nil {
		return nil, err
	}
	out := make(map[string]json.RawMessage, len(rows))
	for _, r := range rows {
		if len(r.Value) > 0 && string(r.Value) != "null" {
			out[r.Key] = r.Value
		}
	}
	return out, nil
}

func (s *Service) putSetting(ctx context.Context, q *catalogsdb.Queries, actor *iam.Access, key string, value any, description string) error {
	b, err := json.Marshal(value)
	if err != nil {
		return err
	}
	return q.PutSetting(ctx, catalogsdb.PutSettingParams{Key: key, Value: b, Description: optText(description), UpdatedBy: &actor.UserID})
}

func settingInt(vals map[string]json.RawMessage, key string, def int) int {
	v, ok := vals[key]
	if !ok {
		return def
	}
	var n int
	if json.Unmarshal(v, &n) == nil {
		return n
	}
	var str string
	if json.Unmarshal(v, &str) == nil {
		if n, err := strconv.Atoi(strings.TrimSpace(str)); err == nil {
			return n
		}
	}
	return def
}

func settingString(vals map[string]json.RawMessage, key, def string) string {
	v, ok := vals[key]
	if !ok {
		return def
	}
	var str string
	if json.Unmarshal(v, &str) == nil && str != "" {
		return str
	}
	return def
}

func settingBool(vals map[string]json.RawMessage, key string, def bool) bool {
	v, ok := vals[key]
	if !ok {
		return def
	}
	var b bool
	if json.Unmarshal(v, &b) == nil {
		return b
	}
	return def
}

// ───────────────────────── Email nhận Ticket ─────────────────────────

// TicketEvents: sự kiện gửi email thông báo (chưa lưu = bật).
var TicketEvents = []Option{
	{"created", "Thông báo khi có ticket mới"},
	{"comment", "Thông báo khi có tin nhắn / bình luận mới trong ticket"},
	{"status_changed", "Thông báo khi trạng thái ticket thay đổi"},
	{"stale_lead", "Thông báo khi có lead CRM bị trễ >24h chưa xử lý"},
	{"transaction", "Thông báo khi có giao dịch thanh toán / phiếu thu học phí mới"},
	{"overdue_debt", "Thông báo khi có phiếu thu / công nợ trễ hẹn"},
	{"homework", "Thông báo khi có học viên nộp bài / trễ nộp bài tập hoặc kiểm tra"},
}

const (
	keyTicketEmails = "ticket_notification_emails"
	keyMailPassword = "mail_password"
)

var mailKeys = []string{"mail_host", "mail_port", "mail_encryption", "mail_username", "mail_from_address", "mail_from_name"}

type MailConfig struct {
	Host        string `json:"host"`
	Port        int    `json:"port"`
	Encryption  string `json:"encryption"`
	Username    string `json:"username"`
	HasPassword bool   `json:"has_password"`
	FromAddress string `json:"from_address"`
	FromName    string `json:"from_name"`
}

type TicketEmailConfig struct {
	Emails []string        `json:"emails"`
	Events map[string]bool `json:"events"`
	// Hòm thư gửi SMTP: chỉ người có mail_config.manage xem / sửa; không bao giờ trả mật khẩu
	Mail          *MailConfig `json:"mail"`
	CanManageMail bool        `json:"can_manage_mail"`
	EventLabels   []Option    `json:"event_labels"`
}

func (s *Service) TicketEmails(ctx context.Context, actor *iam.Access) (TicketEmailConfig, error) {
	keys := append([]string{keyTicketEmails}, mailKeys...)
	for _, e := range TicketEvents {
		keys = append(keys, "ticket_notify_"+e.Value)
	}
	vals, err := s.settings(ctx, keys...)
	if err != nil {
		return TicketEmailConfig{}, err
	}
	out := TicketEmailConfig{Emails: []string{}, Events: map[string]bool{}, EventLabels: TicketEvents, CanManageMail: actor.Can("mail_config.manage")}
	if v, ok := vals[keyTicketEmails]; ok {
		var list []string
		_ = json.Unmarshal(v, &list)
		out.Emails = cleanEmails(list)
	}
	for _, e := range TicketEvents {
		out.Events[e.Value] = settingBool(vals, "ticket_notify_"+e.Value, true)
	}
	if out.CanManageMail {
		mc, err := s.mailConfig(ctx, vals)
		if err != nil {
			return out, err
		}
		out.Mail = &mc
	}
	return out, nil
}

func (s *Service) mailConfig(ctx context.Context, vals map[string]json.RawMessage) (MailConfig, error) {
	enc := "tls"
	if v, ok := vals["mail_encryption"]; ok {
		_ = json.Unmarshal(v, &enc)
	}
	secret, err := s.q.GetSecretSetting(ctx, keyMailPassword)
	if err != nil && !isNoRows(err) {
		return MailConfig{}, err
	}
	return MailConfig{
		Host:        settingString(vals, "mail_host", "smtp.gmail.com"),
		Port:        settingInt(vals, "mail_port", 587),
		Encryption:  enc,
		Username:    settingString(vals, "mail_username", ""),
		HasPassword: len(secret) > 0,
		FromAddress: settingString(vals, "mail_from_address", "noreply@menglish.edu.vn"),
		FromName:    settingString(vals, "mail_from_name", "MEnglish Support"),
	}, nil
}

// cleanEmails: chữ thường, bỏ địa chỉ sai định dạng và trùng.
func cleanEmails(list []string) []string {
	out := []string{}
	for _, e := range list {
		e = strings.ToLower(strings.TrimSpace(e))
		if e == "" {
			continue
		}
		if a, err := mail.ParseAddress(e); err != nil || a.Address != e || !strings.Contains(e, ".") {
			continue
		}
		if !slices.Contains(out, e) {
			out = append(out, e)
		}
	}
	return out
}

type TicketEmailInput struct {
	// Danh sách hoặc một chuỗi cách nhau bởi dấu phẩy / chấm phẩy / xuống dòng
	Emails json.RawMessage `json:"emails"`
	Events map[string]bool `json:"events"`
	// Chỉ người có mail_config.manage mới đổi được; nil = không đổi
	Mail *struct {
		Host        string `json:"host"`
		Port        int    `json:"port"`
		Encryption  string `json:"encryption"`
		Username    string `json:"username"`
		Password    string `json:"password"` // trống = giữ mật khẩu cũ
		FromAddress string `json:"from_address"`
		FromName    string `json:"from_name"`
	} `json:"mail"`
}

func splitEmails(raw json.RawMessage) []string {
	var list []string
	if json.Unmarshal(raw, &list) == nil {
		return list
	}
	var str string
	if json.Unmarshal(raw, &str) == nil {
		return strings.FieldsFunc(str, func(r rune) bool { return r == ',' || r == ';' || r == '\n' || r == '\r' })
	}
	return nil
}

func (s *Service) UpdateTicketEmails(ctx context.Context, actor *iam.Access, in TicketEmailInput) (string, error) {
	emails := cleanEmails(splitEmails(in.Emails))
	canMail := actor.Can("mail_config.manage")
	var cur MailConfig
	if in.Mail != nil && canMail {
		vals, err := s.settings(ctx, mailKeys...)
		if err != nil {
			return "", err
		}
		if cur, err = s.mailConfig(ctx, vals); err != nil {
			return "", err
		}
		host, user := strings.TrimSpace(in.Mail.Host), strings.TrimSpace(in.Mail.Username)
		// Đổi máy chủ hoặc tài khoản SMTP thì phải nhập lại mật khẩu, không dùng mật khẩu đã lưu với máy chủ khác.
		if ((host != "" && host != cur.Host) || user != cur.Username) && cur.HasPassword && strings.TrimSpace(in.Mail.Password) == "" {
			return "", iam.Invalid("Đổi máy chủ hoặc tài khoản SMTP thì nhập lại mật khẩu ứng dụng.")
		}
		if e := strings.TrimSpace(in.Mail.Encryption); e != "" && e != "tls" && e != "ssl" && e != "null" {
			return "", iam.Invalid("Giao thức mã hóa SMTP chỉ nhận tls, ssl hoặc null.")
		}
		if in.Mail.Port < 0 || in.Mail.Port > 65535 {
			return "", iam.Invalid("Cổng SMTP không hợp lệ.")
		}
	}
	err := s.tx(ctx, func(q *catalogsdb.Queries) error {
		if err := s.putSetting(ctx, q, actor, keyTicketEmails, emails, "Danh sách email nhận thông báo khi có ticket hỗ trợ kỹ thuật / báo lỗi"); err != nil {
			return err
		}
		for _, e := range TicketEvents {
			if err := s.putSetting(ctx, q, actor, "ticket_notify_"+e.Value, in.Events[e.Value], e.Label); err != nil {
				return err
			}
		}
		if in.Mail == nil || !canMail {
			return nil
		}
		m := in.Mail
		put := func(key string, v any, desc string) error { return s.putSetting(ctx, q, actor, key, v, desc) }
		if h := strings.TrimSpace(m.Host); h != "" {
			if err := put("mail_host", h, "Máy chủ gửi thư SMTP"); err != nil {
				return err
			}
		}
		if m.Port > 0 {
			if err := put("mail_port", strconv.Itoa(m.Port), "Cổng kết nối SMTP"); err != nil {
				return err
			}
		}
		if err := put("mail_encryption", strings.TrimSpace(m.Encryption), "Giao thức mã hóa SMTP (tls/ssl/null)"); err != nil {
			return err
		}
		if err := put("mail_username", strings.TrimSpace(m.Username), "Tài khoản / Email đăng nhập gửi thư"); err != nil {
			return err
		}
		if pw := strings.ReplaceAll(strings.TrimSpace(m.Password), " ", ""); pw != "" {
			// Bỏ khoảng trắng khi dán mật khẩu ứng dụng Google dạng "xxxx xxxx xxxx xxxx"; lưu mã hóa.
			if err := q.PutSecretSetting(ctx, catalogsdb.PutSecretSettingParams{
				Key: keyMailPassword, Secret: s.box.Seal([]byte(pw), []byte(keyMailPassword)),
				Description: optText("Mật khẩu ứng dụng / Mật khẩu SMTP"), UpdatedBy: &actor.UserID,
			}); err != nil {
				return err
			}
		}
		if a := strings.TrimSpace(m.FromAddress); a != "" {
			if err := put("mail_from_address", a, "Địa chỉ email người gửi hiển thị"); err != nil {
				return err
			}
		}
		if n := strings.TrimSpace(m.FromName); n != "" {
			if err := put("mail_from_name", n, "Tên người gửi hiển thị"); err != nil {
				return err
			}
		}
		return nil
	})
	if err != nil {
		return "", err
	}
	s.log.Info("ticket email settings updated", "recipients", len(emails), "mail", in.Mail != nil && canMail, "by", actor.UserID)
	if in.Mail == nil || !canMail {
		return "Đã lưu danh sách email nhận và các sự kiện gửi thông báo.", nil
	}
	return "Đã lưu cấu hình danh sách email nhận và tài khoản gửi thư SMTP thành công!", nil
}
