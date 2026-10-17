package mail

import (
	"context"
	"encoding/json"
	"errors"
	"log/slog"
	"strings"
	"sync"
	"time"

	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgxpool"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/notify"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/notify/mail/maildb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/secretbox"
)

// Options của Service.
type Options struct {
	// BlockOutgoing (MAIL_BLOCK_OUTGOING): không gửi thư nào ra ngoài; thư trong hàng đợi chuyển "Bị chặn".
	BlockOutgoing bool
	// PublicURL: địa chỉ hệ thống, ghép vào đường dẫn "/..." trong thư.
	PublicURL string
	// Transport: nil = SMTPTransport.
	Transport Transport
	// Batch: số thư mỗi lượt; 0 = 20.
	Batch int
	// KeepSent: giữ thư đã gửi / đã hủy / bị chặn bao lâu; 0 = 30 ngày.
	KeepSent time.Duration
}

// Service: vòng gửi nền, gửi thử và phần quản trị hàng đợi.
type Service struct {
	pool *pgxpool.Pool
	q    *maildb.Queries
	box  *secretbox.Box
	log  *slog.Logger
	opt  Options
	tr   Transport
	now  func() time.Time
	mu   sync.Mutex // một lượt gửi một lúc trong tiến trình
}

func NewService(pool *pgxpool.Pool, box *secretbox.Box, log *slog.Logger, opt Options) *Service {
	if opt.Batch <= 0 {
		opt.Batch = 20
	}
	if opt.KeepSent <= 0 {
		opt.KeepSent = 30 * 24 * time.Hour
	}
	tr := opt.Transport
	if tr == nil {
		tr = SMTPTransport{}
	}
	return &Service{pool: pool, q: maildb.New(pool), box: box, log: log, opt: opt, tr: tr, now: time.Now}
}

// Blocked: môi trường đang chặn gửi thư ra ngoài.
func (s *Service) Blocked() bool { return s.opt.BlockOutgoing }

// lockSeconds: thời gian giữ một thư đang gửi; quá hạn (tiến trình chết) thì lượt sau lấy lại.
const lockSeconds = 300

// Backoff: chờ trước lần thử thứ n+1 khi lần thử thứ n lỗi (1, 2, 4… phút, tối đa 1 giờ).
func Backoff(n int) time.Duration {
	if n < 1 {
		n = 1
	}
	d := time.Minute << min(n-1, 6)
	return min(d, time.Hour)
}

// RunLoop gửi thư đến hạn mỗi every (thư mới xếp đi ở lượt kế tiếp sau khi commit) và dọn thư cũ mỗi giờ.
func (s *Service) RunLoop(ctx context.Context, every time.Duration) {
	if s.opt.BlockOutgoing {
		s.log.Warn("mail: MAIL_BLOCK_OUTGOING bật, không gửi email ra ngoài")
	}
	t := time.NewTicker(every)
	defer t.Stop()
	lastPurge := time.Time{}
	for {
		for {
			n, err := s.ProcessDue(ctx)
			if err != nil && ctx.Err() == nil {
				s.log.Error("mail: lượt gửi", "err", err)
			}
			// Còn thư đến hạn (đủ một lượt) thì gửi tiếp ngay.
			if err != nil || n < s.opt.Batch || ctx.Err() != nil {
				break
			}
		}
		if s.now().Sub(lastPurge) > time.Hour {
			lastPurge = s.now()
			if n, err := s.q.PurgeSentMail(ctx, s.now().Add(-s.opt.KeepSent)); err != nil && ctx.Err() == nil {
				s.log.Error("mail: dọn thư cũ", "err", err)
			} else if n > 0 {
				s.log.Info("mail: dọn thư cũ", "deleted", n)
			}
		}
		select {
		case <-ctx.Done():
			return
		case <-t.C:
		}
	}
}

// ProcessDue gửi một lượt thư đến hạn; trả số thư đã lấy.
func (s *Service) ProcessDue(ctx context.Context) (int, error) {
	s.mu.Lock()
	defer s.mu.Unlock()
	jobs, err := s.q.ClaimDueMail(ctx, maildb.ClaimDueMailParams{LockSeconds: lockSeconds, Lim: int32(s.opt.Batch)})
	if err != nil || len(jobs) == 0 {
		return 0, err
	}
	var cfg SMTPConfig
	var cfgErr error
	if !s.opt.BlockOutgoing {
		cfg, cfgErr = LoadSMTP(ctx, s.pool, s.box)
	}
	for _, j := range jobs {
		if err := s.deliver(ctx, j, cfg, cfgErr); err != nil {
			return len(jobs), err
		}
	}
	return len(jobs), nil
}

// Rendered: thư đã render (bản xem trước ở màn quản trị).
type Rendered struct {
	HTML string `json:"html"`
	Text string `json:"text"`
}

func (s *Service) renderJob(template string, data []byte, at time.Time) (Rendered, error) {
	html, text, err := renderAt(template, data, renderCtx{base: s.opt.PublicURL, at: at})
	return Rendered{html, text}, err
}

func (s *Service) deliver(ctx context.Context, j maildb.CoreMailOutbox, cfg SMTPConfig, cfgErr error) error {
	if s.opt.BlockOutgoing {
		s.log.Info("mail: chặn gửi (MAIL_BLOCK_OUTGOING)", "id", j.ID, "kind", j.Kind, "to", j.ToAddress, "subject", j.Subject)
		msg := "Môi trường này đang chặn gửi email ra ngoài (MAIL_BLOCK_OUTGOING)."
		return s.q.MarkMailBlocked(ctx, maildb.MarkMailBlockedParams{ID: j.ID, LastError: &msg})
	}
	r, err := s.renderJob(j.Template, j.Data, j.CreatedAt)
	if err != nil {
		// Dữ liệu hỏng thì thử lại cũng vô ích.
		return s.fail(ctx, j, "Không dựng được nội dung thư: "+err.Error())
	}
	if cfgErr != nil {
		return s.retryOrFail(ctx, j, cfgErr)
	}
	_, domain, _ := strings.Cut(NormalizeAddress(cfg.FromAddress), "@")
	err = s.tr.Send(ctx, cfg, Outgoing{
		To: j.ToAddress, ToName: j.ToName, Subject: j.Subject, HTML: r.HTML, Text: r.Text,
		MessageID: "<" + j.ID.String() + "@" + cmpOr(domain, "menglish.local") + ">", Date: s.now(), ReferenceKind: j.Kind,
	})
	if err == nil {
		s.log.Info("mail: đã gửi", "id", j.ID, "kind", j.Kind, "to", j.ToAddress, "attempt", j.Attempts)
		return s.q.MarkMailSent(ctx, j.ID)
	}
	if ctx.Err() != nil {
		return ctx.Err()
	}
	if permanent(err) {
		return s.fail(ctx, j, err.Error())
	}
	return s.retryOrFail(ctx, j, err)
}

func (s *Service) retryOrFail(ctx context.Context, j maildb.CoreMailOutbox, err error) error {
	msg := truncate(err.Error(), 2000)
	if j.Attempts >= j.MaxAttempts {
		return s.fail(ctx, j, msg)
	}
	next := s.now().Add(Backoff(int(j.Attempts)))
	s.log.Warn("mail: gửi lỗi, thử lại sau", "id", j.ID, "kind", j.Kind, "to", j.ToAddress, "attempt", j.Attempts, "next", next, "err", msg)
	return s.q.MarkMailRetry(ctx, maildb.MarkMailRetryParams{ID: j.ID, NextAttemptAt: next, LastError: &msg})
}

// fail: thư hỏng hẳn; gửi thông báo dự phòng (nếu có) trong cùng transaction.
func (s *Service) fail(ctx context.Context, j maildb.CoreMailOutbox, reason string) error {
	reason = truncate(reason, 2000)
	s.log.Error("mail: gửi thất bại", "id", j.ID, "kind", j.Kind, "to", j.ToAddress, "attempts", j.Attempts, "err", reason)
	return pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error {
		n, err := s.q.WithTx(tx).MarkMailFailed(ctx, maildb.MarkMailFailedParams{ID: j.ID, LastError: &reason})
		if err != nil || n == 0 || len(j.Fallback) == 0 {
			return err
		}
		var fb Fallback
		if err := json.Unmarshal(j.Fallback, &fb); err != nil {
			s.log.Error("mail: thông báo dự phòng hỏng", "id", j.ID, "err", err)
			return nil
		}
		// Savepoint: thông báo lỗi thì vẫn ghi được trạng thái thư.
		if err := pgx.BeginFunc(ctx, tx, func(sp pgx.Tx) error {
			_, err := notify.Send(ctx, sp, fb.UserIDs, fb.Notification)
			return err
		}); err != nil {
			s.log.Error("mail: gửi thông báo dự phòng", "id", j.ID, "err", err)
		}
		return nil
	})
}

// ErrBlocked: gửi thử khi môi trường chặn thư ra ngoài.
var ErrBlocked = errors.New("mail: MAIL_BLOCK_OUTGOING")
