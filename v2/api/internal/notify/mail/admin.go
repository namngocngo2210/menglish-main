package mail

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"strings"
	"sync"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/notify/mail/maildb"
)

// ───── Gửi thử (SystemConfigController::sendTestTicketEmail) ─────

// TestResult: kết quả gửi thử cho khung "Gửi Thử Nghiệm".
type TestResult struct {
	OK      bool     `json:"ok"`
	Message string   `json:"message"`
	Detail  string   `json:"detail,omitempty"`
	Hints   []string `json:"hints"`
}

// SendTest gửi ngay (không qua hàng đợi) thư thử tới recipients bằng SMTP đang lưu, để kiểm tra kết nối vừa cấu hình.
// sender: "Tên (email)" người bấm gửi thử.
func (s *Service) SendTest(ctx context.Context, recipients []string, sender string) (TestResult, error) {
	if s.opt.BlockOutgoing {
		return TestResult{}, ErrBlocked
	}
	cfg, err := LoadSMTP(ctx, s.pool, s.box)
	if err != nil {
		return TestResult{}, err
	}
	subject := "🧪 [MEnglish Test] Kiểm tra kết nối Email nhận Ticket"
	t := Ticket{
		Code: "TEST", Title: "Kiểm tra đường truyền Email Thông báo Ticket", Subject: subject, Type: "status",
		Priority: "high", PriorityLabel: "Thử nghiệm", CategoryLabel: "Hệ thống IT", Status: "resolved", StatusLabel: "Hoạt động tốt",
		Content: fmt.Sprintf("Cổng gửi thư SMTP đã kết nối thành công qua máy chủ %s:%d (Driver: smtp).\nCác email nhận thông báo: %s",
			cfg.Host, cfg.Port, strings.Join(recipients, ", ")),
		SenderName: sender, ActionURL: "/system-config/ticket-emails", ActionText: "Quản lý Cấu hình Email",
	}
	data, _ := json.Marshal(t)
	r, err := s.renderJob(TemplateTicket, data, s.now())
	if err != nil {
		return TestResult{}, err
	}
	for _, to := range recipients {
		err := s.tr.Send(ctx, cfg, Outgoing{To: to, Subject: subject, HTML: r.HTML, Text: r.Text, Date: s.now(), ReferenceKind: KindTest})
		if err != nil {
			s.log.Warn("mail: gửi thử thất bại", "to", to, "host", cfg.Host, "err", err)
			res := TestResult{OK: false, Message: "Gửi thất bại.", Detail: err.Error(), Hints: []string{}}
			if h := smtpErrorHint(err.Error()); h != "" {
				res.Hints = append(res.Hints, h)
			}
			return res, nil
		}
	}
	s.log.Info("mail: gửi thử thành công", "recipients", len(recipients), "host", cfg.Host)
	res := TestResult{OK: true, Message: "Đã gửi thành công tới: " + strings.Join(recipients, ", "),
		Hints: []string{"Máy chủ SMTP đã nhận thư. Nếu chưa thấy trong Hộp thư đến, hãy xem thư mục Spam / Quảng cáo."}}
	if n := fromAddressNote(cfg); n != "" {
		res.Hints = append(res.Hints, n)
	}
	return res, nil
}

// smtpErrorHint: gợi ý cách sửa theo lỗi SMTP thường gặp (SystemConfigController::smtpErrorHint).
func smtpErrorHint(msg string) string {
	e := strings.ToLower(msg)
	has := func(list ...string) bool {
		for _, s := range list {
			if strings.Contains(e, s) {
				return true
			}
		}
		return false
	}
	switch {
	case has("535", "username and password not accepted", "authenticat"):
		return "Máy chủ từ chối đăng nhập: kiểm tra lại Tài khoản và Mật khẩu ứng dụng. Với Gmail phải bật Xác minh 2 bước rồi tạo Mật khẩu ứng dụng 16 ký tự, không dùng mật khẩu đăng nhập thường."
	case has("timed out", "timeout", "connection refused", "unable to connect", "network is unreachable", "deadline exceeded"):
		return "Không kết nối được tới máy chủ SMTP: hosting có thể chặn cổng này. Thử đổi sang SSL cổng 465, hoặc nhờ nhà cung cấp hosting mở cổng gửi thư ra ngoài."
	case has("no such host", "name or service not known", "server misbehaving", "chưa cấu hình máy chủ"):
		return "Không tìm thấy máy chủ SMTP: kiểm tra lại tên máy chủ (SMTP Host)."
	case has("ssl", "tls", "certificate", "x509", "crypto", "unencrypted connection"):
		return "Lỗi mã hóa: TLS đi với cổng 587, SSL đi với cổng 465. Kiểm tra lại cặp Cổng / Mã hóa."
	case has("sender", "from address", "người gửi", "553", "550"):
		return "Máy chủ từ chối địa chỉ người gửi: đặt Email người gửi hiển thị trùng với Tài khoản đăng nhập SMTP."
	}
	return ""
}

// fromAddressNote: cảnh báo khi địa chỉ người gửi khác tài khoản đăng nhập SMTP.
func fromAddressNote(c SMTPConfig) string {
	from, user := strings.ToLower(strings.TrimSpace(c.FromAddress)), strings.ToLower(strings.TrimSpace(c.Username))
	if from == "" || user == "" || from == user || !strings.Contains(user, "@") {
		return ""
	}
	if strings.Contains(strings.ToLower(c.Host), "gmail") {
		return fmt.Sprintf("Email người gửi hiển thị (%s) khác tài khoản đăng nhập (%s): Gmail sẽ tự đổi người gửi thành %s. Nên đặt hai ô này trùng nhau.", from, user, user)
	}
	return fmt.Sprintf("Email người gửi hiển thị (%s) khác tài khoản đăng nhập (%s): một số máy chủ sẽ từ chối hoặc đưa thư vào Spam.", from, user)
}

// ───── Quản trị hàng đợi ─────

// Statuses: nhãn trạng thái thư.
var Statuses = []struct {
	Value string `json:"value"`
	Label string `json:"label"`
}{
	{"pending", "Chờ gửi"},
	{"sending", "Đang gửi"},
	{"sent", "Đã gửi"},
	{"failed", "Lỗi"},
	{"blocked", "Bị chặn"},
	{"cancelled", "Đã hủy"},
}

// Filter của danh sách thư.
type Filter struct {
	Status, Kind, Q string
	Page, PerPage   int
}

// Item: một dòng danh sách.
type Item struct {
	ID            uuid.UUID  `json:"id"`
	Kind          string     `json:"kind"`
	To            string     `json:"to"`
	ToName        string     `json:"to_name"`
	Subject       string     `json:"subject"`
	Status        string     `json:"status"`
	Attempts      int        `json:"attempts"`
	MaxAttempts   int        `json:"max_attempts"`
	NextAttemptAt time.Time  `json:"next_attempt_at"`
	LastError     *string    `json:"last_error"`
	RefType       *string    `json:"ref_type"`
	RefID         *string    `json:"ref_id"`
	CreatedBy     *string    `json:"created_by"`
	CreatedAt     time.Time  `json:"created_at"`
	UpdatedAt     time.Time  `json:"updated_at"`
	SentAt        *time.Time `json:"sent_at"`
}

// Page: danh sách thư + số thư theo trạng thái.
type Page struct {
	Items   []Item           `json:"items"`
	Total   int64            `json:"total"`
	Page    int              `json:"page"`
	PerPage int              `json:"per_page"`
	Counts  map[string]int64 `json:"counts"`
	Blocked bool             `json:"blocked"`
}

func escapeLike(s string) string {
	return strings.NewReplacer(`\`, `\\`, "%", `\%`, "_", `\_`).Replace(s)
}

// List: thư mới nhất trước.
func (s *Service) List(ctx context.Context, f Filter) (*Page, error) {
	if f.Page < 1 {
		f.Page = 1
	}
	if f.PerPage < 1 || f.PerPage > 100 {
		f.PerPage = 20
	}
	p := maildb.ListMailParams{Lim: int32(f.PerPage), Off: int32((f.Page - 1) * f.PerPage)}
	if f.Status != "" {
		p.Status = &f.Status
	}
	if f.Kind != "" {
		p.Kind = &f.Kind
	}
	if q := strings.TrimSpace(f.Q); q != "" {
		like := "%" + escapeLike(q) + "%"
		p.Q = &like
	}
	rows, err := s.q.ListMail(ctx, p)
	if err != nil {
		return nil, err
	}
	out := &Page{Items: make([]Item, 0, len(rows)), Page: f.Page, PerPage: f.PerPage, Counts: map[string]int64{}, Blocked: s.opt.BlockOutgoing}
	for _, r := range rows {
		out.Total = r.Total
		out.Items = append(out.Items, Item{
			ID: r.ID, Kind: r.Kind, To: r.ToAddress, ToName: r.ToName, Subject: r.Subject, Status: r.Status,
			Attempts: int(r.Attempts), MaxAttempts: int(r.MaxAttempts), NextAttemptAt: r.NextAttemptAt, LastError: r.LastError,
			RefType: r.RefType, RefID: r.RefID, CreatedBy: r.CreatedByName, CreatedAt: r.CreatedAt, UpdatedAt: r.UpdatedAt, SentAt: r.SentAt,
		})
	}
	counts, err := s.q.MailStatusCounts(ctx)
	if err != nil {
		return nil, err
	}
	for _, c := range counts {
		out.Counts[c.Status] = c.N
	}
	return out, nil
}

// Detail: một thư kèm bản xem trước (thư nhạy cảm không có nội dung).
type Detail struct {
	Item
	Sensitive bool    `json:"sensitive"`
	Preview   *string `json:"preview"` // bản chữ thuần
	HasHTML   bool    `json:"has_html"`
	Fallback  bool    `json:"has_fallback"`
}

// ErrNotFound: không có thư.
var ErrNotFound = errors.New("mail: không tìm thấy thư")

// ErrStatus: trạng thái thư không cho thao tác.
var ErrStatus = errors.New("mail: trạng thái thư không cho thao tác này")

func (s *Service) Get(ctx context.Context, id uuid.UUID) (*Detail, error) {
	r, err := s.q.GetMail(ctx, id)
	if errors.Is(err, pgx.ErrNoRows) {
		return nil, ErrNotFound
	}
	if err != nil {
		return nil, err
	}
	d := &Detail{Item: Item{
		ID: r.ID, Kind: r.Kind, To: r.ToAddress, ToName: r.ToName, Subject: r.Subject, Status: r.Status,
		Attempts: int(r.Attempts), MaxAttempts: int(r.MaxAttempts), NextAttemptAt: r.NextAttemptAt, LastError: r.LastError,
		RefType: r.RefType, RefID: r.RefID, CreatedBy: r.CreatedByName, CreatedAt: r.CreatedAt, UpdatedAt: r.UpdatedAt, SentAt: r.SentAt,
	}, Sensitive: r.Sensitive, Fallback: len(r.Fallback) > 0}
	if !r.Sensitive {
		if rd, err := s.renderJob(r.Template, r.Data, r.CreatedAt); err == nil {
			d.Preview, d.HasHTML = &rd.Text, rd.HTML != ""
		}
	}
	return d, nil
}

// Retry: gửi lại thư lỗi / bị chặn / đã hủy (đếm lại số lần thử). after (tùy chọn) chạy trong cùng transaction, vd. ghi
// nhật ký vận hành.
func (s *Service) Retry(ctx context.Context, id uuid.UUID, after func(ctx context.Context, db DB, subject, to string) error) error {
	return s.change(ctx, id, (*maildb.Queries).RetryMail, after)
}

// Cancel: hủy thư đang chờ / lỗi.
func (s *Service) Cancel(ctx context.Context, id uuid.UUID, after func(ctx context.Context, db DB, subject, to string) error) error {
	return s.change(ctx, id, (*maildb.Queries).CancelMail, after)
}

func (s *Service) change(ctx context.Context, id uuid.UUID, fn func(*maildb.Queries, context.Context, uuid.UUID) (uuid.UUID, error),
	after func(ctx context.Context, db DB, subject, to string) error) error {
	err := pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error {
		q := s.q.WithTx(tx)
		if _, err := fn(q, ctx, id); err != nil {
			return err
		}
		if after == nil {
			return nil
		}
		m, err := q.GetMail(ctx, id)
		if err != nil {
			return err
		}
		return after(ctx, tx, m.Subject, m.ToAddress)
	})
	if !errors.Is(err, pgx.ErrNoRows) {
		return err
	}
	if _, err := s.q.GetMail(ctx, id); errors.Is(err, pgx.ErrNoRows) {
		return ErrNotFound
	} else if err != nil {
		return err
	}
	return ErrStatus
}

// ───── Bộ gửi giả cho test ─────

// FakeTransport ghi lại thư thay vì gửi (dùng trong test của các module). Err trả lỗi cho thư (nil = gửi được).
type FakeTransport struct {
	mu   sync.Mutex
	sent []Outgoing
	Err  func(m Outgoing) error
}

func (f *FakeTransport) Send(_ context.Context, _ SMTPConfig, m Outgoing) error {
	f.mu.Lock()
	defer f.mu.Unlock()
	if f.Err != nil {
		if err := f.Err(m); err != nil {
			return err
		}
	}
	f.sent = append(f.sent, m)
	return nil
}

// Sent: các thư đã "gửi".
func (f *FakeTransport) Sent() []Outgoing {
	f.mu.Lock()
	defer f.mu.Unlock()
	return append([]Outgoing(nil), f.sent...)
}
