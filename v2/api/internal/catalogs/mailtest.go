package catalogs

import (
	"context"
	"errors"
	"net/http"
	"strings"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/notify/mail"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/httpx"
)

// Gửi thử email nhận Ticket (SystemConfigController::sendTestTicketEmail): gửi ngay qua SMTP đang lưu, không qua
// hàng đợi, để biết kết nối vừa cấu hình có chạy không.

// MailTester: bộ gửi thư (mail.Service) — gán ở cmd/menglish; nil = chưa có bộ gửi.
type MailTester interface {
	Blocked() bool
	SendTest(ctx context.Context, recipients []string, sender string) (mail.TestResult, error)
}

var (
	errMailBlocked = iam.Invalid("Môi trường này đang chặn gửi email ra ngoài (MAIL_BLOCK_OUTGOING), không gửi thử được.")
	errNoMailer    = &iam.Error{Status: http.StatusServiceUnavailable, Code: "mail_unavailable", Message: "Chưa có bộ gửi email."}
)

// TestTicketEmail gửi thử tới testEmail, để trống thì tới mọi email trong danh sách nhận Ticket.
func (s *Service) TestTicketEmail(ctx context.Context, actor *iam.Access, testEmail string) (mail.TestResult, error) {
	if s.Mail == nil {
		return mail.TestResult{}, errNoMailer
	}
	if s.Mail.Blocked() {
		return mail.TestResult{}, errMailBlocked
	}
	var recipients []string
	if e := strings.TrimSpace(testEmail); e != "" {
		a := mail.NormalizeAddress(e)
		if a == "" {
			return mail.TestResult{}, iam.Invalid(`Địa chỉ email kiểm tra "` + e + `" không đúng định dạng!`)
		}
		recipients = []string{a}
	} else {
		list, err := mail.TicketRecipients(ctx, s.pool)
		if err != nil {
			return mail.TestResult{}, err
		}
		recipients = list
	}
	if len(recipients) == 0 {
		return mail.TestResult{}, iam.Invalid("Chưa có email nào trong danh sách để gửi thử nghiệm!")
	}
	var name, email string
	if err := s.pool.QueryRow(ctx, `SELECT full_name, coalesce(email::text, '') FROM iam.users WHERE id = $1`, actor.UserID).
		Scan(&name, &email); err != nil {
		return mail.TestResult{}, err
	}
	sender := cmpStr(name, "Administrator") + " (" + cmpStr(email, "admin") + ")"
	res, err := s.Mail.SendTest(ctx, recipients, sender)
	if errors.Is(err, mail.ErrBlocked) {
		return res, errMailBlocked
	}
	if err != nil {
		return res, err
	}
	s.log.Info("ticket email test", "ok", res.OK, "recipients", len(recipients), "by", actor.UserID)
	return res, nil
}

func cmpStr(a, b string) string {
	if strings.TrimSpace(a) != "" {
		return a
	}
	return b
}

func (h *Handler) testTicketEmail(w http.ResponseWriter, r *http.Request) {
	var in struct {
		TestEmail string `json:"test_email"`
	}
	if !httpx.Decode(w, r, &in) {
		return
	}
	res, err := h.svc.TestTicketEmail(r.Context(), actor(r), in.TestEmail)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, res)
}
