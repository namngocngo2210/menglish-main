package mail_test

import (
	"context"
	"crypto/rand"
	"encoding/base64"
	"encoding/json"
	"errors"
	"net/textproto"
	"strings"
	"testing"
	"time"

	"github.com/go-chi/chi/v5"
	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/catalogs"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam/iamtest"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/notify"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/notify/mail"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/notify/mail/mailapi"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/secretbox"
)

type env struct {
	*iamtest.Env
	box  *secretbox.Box
	fake *mail.FakeTransport
	svc  *mail.Service
	cat  *catalogs.Service
}

func newBox(t *testing.T) *secretbox.Box {
	key := make([]byte, 32)
	_, _ = rand.Read(key)
	box, err := secretbox.FromBase64(base64.StdEncoding.EncodeToString(key))
	if err != nil {
		t.Fatal(err)
	}
	return box
}

func setup(t *testing.T, opt mail.Options) *env {
	e := iamtest.Setup(t, "core.mail_outbox", "core.settings", "core.audit_log")
	box := newBox(t)
	fake := &mail.FakeTransport{}
	opt.Transport = fake
	if opt.PublicURL == "" {
		opt.PublicURL = "https://beta.test"
	}
	svc := mail.NewService(e.Pool, box, e.Log, opt)
	cat := catalogs.NewService(e.Pool, box, e.Log)
	cat.Mail = svc
	e.Serve(func(r chi.Router) {
		mailapi.NewHandler(svc, e.Log).Routes(r)
		catalogs.NewHandler(cat, e.Log).Routes(r)
	})
	return &env{Env: e, box: box, fake: fake, svc: svc, cat: cat}
}

func (e *env) process(t *testing.T) int {
	t.Helper()
	n, err := e.svc.ProcessDue(context.Background())
	if err != nil {
		t.Fatal(err)
	}
	return n
}

type row struct {
	Status, Data  string
	Attempts      int
	LastError     *string
	NextAttemptAt time.Time
	SentAt        *time.Time
}

func (e *env) row(t *testing.T, id uuid.UUID) row {
	t.Helper()
	var r row
	err := e.Pool.QueryRow(context.Background(), `SELECT status, data::text, attempts, last_error, next_attempt_at, sent_at
		FROM core.mail_outbox WHERE id = $1`, id).Scan(&r.Status, &r.Data, &r.Attempts, &r.LastError, &r.NextAttemptAt, &r.SentAt)
	if err != nil {
		t.Fatal(err)
	}
	return r
}

func (e *env) makeDue(t *testing.T) {
	t.Helper()
	if _, err := e.Pool.Exec(context.Background(), `UPDATE core.mail_outbox SET next_attempt_at = now() WHERE status = 'pending'`); err != nil {
		t.Fatal(err)
	}
}

func (e *env) setting(t *testing.T, key string, value any) {
	t.Helper()
	b, _ := json.Marshal(value)
	if _, err := e.Pool.Exec(context.Background(), `INSERT INTO core.settings (key, value) VALUES ($1, $2)
		ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value`, key, b); err != nil {
		t.Fatal(err)
	}
}

func TestEnqueueSendsOnlyAfterCommit(t *testing.T) {
	e := setup(t, mail.Options{})
	ctx := context.Background()

	// Rollback: thư mất cùng nghiệp vụ.
	_ = pgx.BeginFunc(ctx, e.Pool, func(tx pgx.Tx) error {
		if _, err := mail.Enqueue(ctx, tx, mail.ParentNotice("ph@example.vn", "Xác nhận", "Nội dung", "", "")); err != nil {
			t.Fatal(err)
		}
		return errors.New("rollback")
	})
	if n := e.process(t); n != 0 {
		t.Fatalf("rollback thì không có thư, lấy được %d", n)
	}

	var id uuid.UUID
	err := pgx.BeginFunc(ctx, e.Pool, func(tx pgx.Tx) error {
		var err error
		id, err = mail.Enqueue(ctx, tx, mail.ParentNotice(" PH@Example.vn ", "Xác nhận lịch học thử — An",
			"Kính gửi Quý phụ huynh,\n<b>An</b> học thử", "/placement/take/x", "Vào làm bài test"))
		if err != nil {
			return err
		}
		// Chưa commit: vòng gửi (kết nối khác) không thấy thư.
		if n := e.process(t); n != 0 {
			t.Fatalf("chưa commit mà đã gửi %d thư", n)
		}
		return nil
	})
	if err != nil {
		t.Fatal(err)
	}
	if n := e.process(t); n != 1 {
		t.Fatalf("sau commit phải gửi 1 thư, được %d", n)
	}
	sent := e.fake.Sent()
	if len(sent) != 1 || sent[0].To != "ph@example.vn" || sent[0].Subject != "Xác nhận lịch học thử — An" ||
		!strings.Contains(sent[0].HTML, `href="https://beta.test/placement/take/x"`) || !strings.Contains(sent[0].HTML, "&lt;b&gt;An&lt;/b&gt;") ||
		!strings.Contains(sent[0].Text, "Kính gửi Quý phụ huynh") || sent[0].ReferenceKind != mail.KindParentNotice {
		t.Fatalf("thư đã gửi: %+v", sent)
	}
	if r := e.row(t, id); r.Status != "sent" || r.SentAt == nil || r.Attempts != 1 {
		t.Fatalf("trạng thái: %+v", r)
	}
	// Gửi rồi thì không gửi lại.
	if n := e.process(t); n != 0 {
		t.Fatalf("gửi lại thư đã gửi: %d", n)
	}

	// Kiểm tra dữ liệu đầu vào.
	if _, err := mail.Enqueue(ctx, e.Pool, mail.ParentNotice("sai", "T", "B", "", "")); !errors.Is(err, mail.ErrInvalidAddress) {
		t.Fatalf("địa chỉ sai: %v", err)
	}
	if _, err := mail.Enqueue(ctx, e.Pool, mail.Message{To: "a@b.vn", Subject: "x", Template: "khong_co"}); err == nil {
		t.Fatal("mẫu lạ phải lỗi")
	}
	if _, err := mail.Enqueue(ctx, e.Pool, mail.Message{To: "a@b.vn", Subject: "x", Template: mail.TemplateNotice, Data: mail.Notice{Title: "x"}}); err == nil {
		t.Fatal("thiếu nội dung phải lỗi ngay khi xếp")
	}
}

func TestRetryBackoffThenFallback(t *testing.T) {
	e := setup(t, mail.Options{})
	ctx := context.Background()
	owner := e.User("MES010", "Người phụ trách", nil, "sales_consultant")
	e.fake.Err = func(mail.Outgoing) error { return &textproto.Error{Code: 421, Msg: "4.7.0 Try again later"} }

	id, err := mail.Enqueue(ctx, e.Pool, mail.Message{
		To: "ph@example.vn", Subject: "Xác nhận lịch học thử — An", Template: mail.TemplateNotice, Kind: mail.KindParentNotice,
		Data: mail.Notice{Title: "Xác nhận", Body: "Nội dung"}, MaxAttempts: 2, RefType: "trial_booking", RefID: "b1",
		Fallback: &mail.Fallback{UserIDs: []uuid.UUID{owner.ID}, Notification: notify.Notification{
			Type: "appointment_confirm_manual", Title: "Gửi xác nhận lịch hẹn qua Zalo: An",
			Body: "Gửi email tới ph@example.vn không thành công.", Data: map[string]any{"copy_text": "Nội dung"},
		}},
	})
	if err != nil {
		t.Fatal(err)
	}
	before := time.Now()
	e.process(t)
	r := e.row(t, id)
	if r.Status != "pending" || r.Attempts != 1 || r.LastError == nil || !strings.Contains(*r.LastError, "421") {
		t.Fatalf("lần 1 lỗi phải chờ thử lại: %+v", r)
	}
	if d := r.NextAttemptAt.Sub(before); d < 50*time.Second || d > 70*time.Second {
		t.Fatalf("lần thử sau phải khoảng 1 phút, là %v", d)
	}
	// Chưa đến hạn thì không thử.
	if n := e.process(t); n != 0 {
		t.Fatalf("chưa đến hạn mà thử lại: %d", n)
	}
	e.makeDue(t)
	e.process(t)
	if r := e.row(t, id); r.Status != "failed" || r.Attempts != 2 {
		t.Fatalf("hết lượt thử phải lỗi hẳn: %+v", r)
	}
	var title, copyText string
	if err := e.Pool.QueryRow(ctx, `SELECT title, data->>'copy_text' FROM core.notifications WHERE user_id = $1 AND type = 'appointment_confirm_manual'`,
		owner.ID).Scan(&title, &copyText); err != nil {
		t.Fatalf("thiếu thông báo dự phòng: %v", err)
	}
	if title != "Gửi xác nhận lịch hẹn qua Zalo: An" || copyText != "Nội dung" {
		t.Fatalf("thông báo dự phòng: %q %q", title, copyText)
	}
}

func TestPermanentErrorFailsAtOnce(t *testing.T) {
	e := setup(t, mail.Options{})
	e.fake.Err = func(m mail.Outgoing) error {
		if m.To == "khongco@example.vn" {
			return &textproto.Error{Code: 550, Msg: "5.1.1 mailbox unavailable"}
		}
		return nil
	}
	ctx := context.Background()
	bad, _ := mail.Enqueue(ctx, e.Pool, mail.ParentNotice("khongco@example.vn", "T", "B", "", ""))
	good, _ := mail.Enqueue(ctx, e.Pool, mail.ParentNotice("ok@example.vn", "T", "B", "", ""))
	e.process(t)
	if r := e.row(t, bad); r.Status != "failed" || r.Attempts != 1 {
		t.Fatalf("địa chỉ bị từ chối phải lỗi ngay: %+v", r)
	}
	if r := e.row(t, good); r.Status != "sent" {
		t.Fatalf("thư khác vẫn gửi: %+v", r)
	}
}

func TestBlockOutgoingAndSensitive(t *testing.T) {
	ctx := context.Background()
	e := setup(t, mail.Options{BlockOutgoing: true})
	id, err := mail.Enqueue(ctx, e.Pool, mail.Message{To: "a@example.vn", Subject: "Đặt lại mật khẩu", Template: mail.TemplatePasswordReset,
		Kind: mail.KindPasswordReset, Sensitive: true, Data: mail.PasswordReset{URL: "/reset-password?token=bimat"}})
	if err != nil {
		t.Fatal(err)
	}
	e.process(t)
	r := e.row(t, id)
	if r.Status != "blocked" || r.Data != "{}" || len(e.fake.Sent()) != 0 || !strings.Contains(*r.LastError, "MAIL_BLOCK_OUTGOING") {
		t.Fatalf("chặn gửi: %+v, đã gửi %d", r, len(e.fake.Sent()))
	}

	// Không chặn: thư nhạy cảm gửi xong thì xóa dữ liệu, màn quản trị không hiện nội dung.
	e2 := setup(t, mail.Options{})
	id, _ = mail.Enqueue(ctx, e2.Pool, mail.Message{To: "a@example.vn", Subject: "Đặt lại mật khẩu", Template: mail.TemplatePasswordReset,
		Kind: mail.KindPasswordReset, Sensitive: true, Data: mail.PasswordReset{URL: "/reset-password?token=bimat"}})
	d, err := e2.svc.Get(ctx, id)
	if err != nil || d.Preview != nil || !d.Sensitive {
		t.Fatalf("thư nhạy cảm không hiện nội dung: %+v %v", d, err)
	}
	e2.process(t)
	if s := e2.fake.Sent(); len(s) != 1 || !strings.Contains(s[0].Text, "https://beta.test/reset-password?token=bimat") {
		t.Fatalf("thư đặt lại mật khẩu: %+v", s)
	}
	if r := e2.row(t, id); r.Status != "sent" || r.Data != "{}" {
		t.Fatalf("gửi xong phải xóa dữ liệu: %+v", r)
	}
}

func TestOperationalAlert(t *testing.T) {
	e := setup(t, mail.Options{})
	ctx := context.Background()
	send := func() int {
		n, err := mail.OperationalAlert(ctx, e.Pool, mail.EventHomework, "[Học vụ] Học viên nộp bài: An - Đề A", "Học viên vừa nộp bài",
			mail.Alert{Code: "TEST-1", Status: "completed", StatusLabel: "Chờ Học vụ chấm", PriorityLabel: "Học vụ", CategoryLabel: "Học vụ & Đào tạo",
				ActionURL: "/placement-tests/submissions/x", ActionText: "Xem Báo cáo Điểm số"})
		if err != nil {
			t.Fatal(err)
		}
		return n
	}
	if n := send(); n != 0 {
		t.Fatalf("chưa có email nhận thì không xếp: %d", n)
	}
	e.setting(t, "ticket_notification_emails", []string{"IT@x.vn", "sai", "it@x.vn", "ops@x.vn"})
	if n := send(); n != 2 {
		t.Fatalf("muốn 2 thư (bỏ trùng, bỏ sai), được %d", n)
	}
	e.setting(t, "ticket_notify_homework", false)
	if n := send(); n != 0 {
		t.Fatalf("sự kiện tắt thì không xếp: %d", n)
	}
	e.process(t)
	sent := e.fake.Sent()
	if len(sent) != 2 || sent[0].Subject != "[Học vụ] Học viên nộp bài: An - Đề A" || !strings.Contains(sent[0].HTML, "● Chờ Học vụ chấm") ||
		!strings.Contains(sent[0].HTML, "https://beta.test/placement-tests/submissions/x") || sent[0].ReferenceKind != mail.KindOpsAlert {
		t.Fatalf("thư cảnh báo: %+v", sent)
	}
}

func TestLoadSMTPDecryptsPassword(t *testing.T) {
	e := setup(t, mail.Options{})
	ctx := context.Background()
	cfg, err := mail.LoadSMTP(ctx, e.Pool, e.box)
	if err != nil || cfg.Host != "smtp.gmail.com" || cfg.Port != 587 || cfg.Encryption != "tls" || cfg.FromAddress != "noreply@menglish.edu.vn" {
		t.Fatalf("mặc định: %+v %v", cfg, err)
	}
	e.setting(t, "mail_host", "smtp.office365.com")
	e.setting(t, "mail_port", "465")
	e.setting(t, "mail_encryption", "ssl")
	e.setting(t, "mail_username", "it@x.vn")
	if _, err := e.Pool.Exec(ctx, `INSERT INTO core.settings (key, secret) VALUES ('mail_password', $1)`,
		e.box.Seal([]byte("abcdefghijklmnop"), []byte("mail_password"))); err != nil {
		t.Fatal(err)
	}
	cfg, err = mail.LoadSMTP(ctx, e.Pool, e.box)
	if err != nil || cfg.Host != "smtp.office365.com" || cfg.Port != 465 || cfg.Encryption != "ssl" || cfg.Username != "it@x.vn" || cfg.Password != "abcdefghijklmnop" {
		t.Fatalf("cấu hình: %+v %v", cfg, err)
	}
	if _, err := mail.LoadSMTP(ctx, e.Pool, newBox(t)); err == nil {
		t.Fatal("khóa khác phải không giải mã được")
	}
}

func TestOutboxAdminScreen(t *testing.T) {
	e := setup(t, mail.Options{})
	ctx := context.Background()
	b := e.Branch("HN01")
	_, manager := e.Member("MES002", "Quản lý", []uuid.UUID{b}, "manager")
	_, admin := e.Member("MES001", "Quản trị", nil, "admin")

	iamtest.Expect(t, manager.Do("GET", "/mail-outbox", nil), 403, "forbidden")
	iamtest.Expect(t, manager.Do("POST", "/mail-outbox/"+uuid.NewString()+"/retry", nil), 403, "forbidden")

	e.fake.Err = func(m mail.Outgoing) error {
		if strings.HasPrefix(m.To, "loi") {
			return &textproto.Error{Code: 550, Msg: "mailbox unavailable"}
		}
		return nil
	}
	failed, _ := mail.Enqueue(ctx, e.Pool, mail.ParentNotice("loi@example.vn", "Thư lỗi", "Nội dung lỗi", "", ""))
	_, _ = mail.Enqueue(ctx, e.Pool, mail.ParentNotice("ok@example.vn", "Thư tốt", "Nội dung", "", ""))
	e.process(t)
	pending, _ := mail.Enqueue(ctx, e.Pool, mail.Message{To: "cho@example.vn", Subject: "Đang chờ", Template: mail.TemplateText, Data: mail.Text{Body: "x"}, Kind: mail.KindOpsAlert})
	// Thư chờ lần thử sau (chưa đến hạn).
	if _, err := e.Pool.Exec(ctx, `UPDATE core.mail_outbox SET next_attempt_at = now() + interval '1 hour' WHERE id = $1`, pending); err != nil {
		t.Fatal(err)
	}

	r := admin.Do("GET", "/mail-outbox", nil)
	iamtest.Expect(t, r, 200, "")
	if r.Body["total"] != float64(3) || len(iamtest.List(t, r, "items")) != 3 {
		t.Fatalf("danh sách: %s", r.Raw)
	}
	counts := r.Body["counts"].(map[string]any)
	if counts["failed"] != float64(1) || counts["sent"] != float64(1) || counts["pending"] != float64(1) {
		t.Fatalf("số theo trạng thái: %v", counts)
	}
	items := iamtest.List(t, admin.Do("GET", "/mail-outbox?status=failed", nil), "items")
	if len(items) != 1 || items[0]["id"] != failed.String() || !strings.Contains(items[0]["last_error"].(string), "550") {
		t.Fatalf("lọc thư lỗi: %v", items)
	}
	if items := iamtest.List(t, admin.Do("GET", "/mail-outbox?kind=ops_alert", nil), "items"); len(items) != 1 {
		t.Fatalf("lọc loại: %v", items)
	}
	if items := iamtest.List(t, admin.Do("GET", "/mail-outbox?q=OK%40", nil), "items"); len(items) != 1 || items[0]["to"] != "ok@example.vn" {
		t.Fatalf("tìm theo địa chỉ: %v", items)
	}
	d := admin.Do("GET", "/mail-outbox/"+failed.String(), nil)
	iamtest.Expect(t, d, 200, "")
	if !strings.Contains(d.Body["preview"].(string), "Nội dung lỗi") || d.Body["has_html"] != true {
		t.Fatalf("chi tiết: %s", d.Raw)
	}
	iamtest.Expect(t, admin.Do("GET", "/mail-outbox/"+uuid.NewString(), nil), 404, "not_found")

	// Gửi lại thư lỗi; thư đang chờ thì không gửi lại được.
	r = admin.Do("POST", "/mail-outbox/"+pending.String()+"/retry", nil)
	iamtest.Expect(t, r, 422, "invalid")
	r = admin.Do("POST", "/mail-outbox/"+failed.String()+"/retry", nil)
	iamtest.Expect(t, r, 200, "")
	if r.Body["message"] != "Đã xếp thư gửi lại." {
		t.Fatalf("gửi lại: %s", r.Raw)
	}
	if row := e.row(t, failed); row.Status != "pending" || row.Attempts != 0 {
		t.Fatalf("sau gửi lại: %+v", row)
	}
	e.fake.Err = nil
	e.process(t)
	if row := e.row(t, failed); row.Status != "sent" {
		t.Fatalf("gửi lại xong phải đi: %+v", row)
	}
	// Hủy thư đang chờ; thư đã gửi không hủy được.
	iamtest.Expect(t, admin.Do("POST", "/mail-outbox/"+failed.String()+"/cancel", nil), 422, "invalid")
	iamtest.Expect(t, admin.Do("POST", "/mail-outbox/"+pending.String()+"/cancel", nil), 200, "")
	if row := e.row(t, pending); row.Status != "cancelled" {
		t.Fatalf("hủy: %+v", row)
	}
	var n int
	_ = e.Pool.QueryRow(ctx, `SELECT count(*) FROM core.audit_log WHERE action IN ('mail.retry', 'mail.cancel')`).Scan(&n)
	if n != 2 {
		t.Fatalf("nhật ký gửi lại / hủy: %d", n)
	}
}

func TestTicketEmailSendTest(t *testing.T) {
	e := setup(t, mail.Options{})
	b := e.Branch("HN01")
	_, teacher := e.Member("MES003", "Giáo viên", []uuid.UUID{b}, "teacher_fulltime")
	_, manager := e.Member("MES002", "Quản lý", []uuid.UUID{b}, "manager") // support_ticket.update

	iamtest.Expect(t, teacher.Do("POST", "/system-config/ticket-emails/test", map[string]any{}), 403, "forbidden")
	msg := func(r iamtest.Resp) string { return r.Body["message"].(string) }
	r := manager.Do("POST", "/system-config/ticket-emails/test", map[string]any{"test_email": "sai@"})
	if r.Status != 422 || msg(r) != `Địa chỉ email kiểm tra "sai@" không đúng định dạng!` {
		t.Fatalf("email sai: %s", r.Raw)
	}
	r = manager.Do("POST", "/system-config/ticket-emails/test", map[string]any{"test_email": ""})
	if r.Status != 422 || msg(r) != "Chưa có email nào trong danh sách để gửi thử nghiệm!" {
		t.Fatalf("danh sách trống: %s", r.Raw)
	}
	e.setting(t, "ticket_notification_emails", []string{"it@x.vn", "ops@x.vn"})
	e.setting(t, "mail_host", "smtp.gmail.com")
	e.setting(t, "mail_username", "it@gmail.com")
	e.setting(t, "mail_from_address", "noreply@x.vn")
	r = manager.Do("POST", "/system-config/ticket-emails/test", map[string]any{"test_email": ""})
	iamtest.Expect(t, r, 200, "")
	if r.Body["ok"] != true || msg(r) != "Đã gửi thành công tới: it@x.vn, ops@x.vn" || len(r.Body["hints"].([]any)) != 2 ||
		!strings.Contains(r.Body["hints"].([]any)[1].(string), "Gmail sẽ tự đổi người gửi") {
		t.Fatalf("gửi thử: %s", r.Raw)
	}
	sent := e.fake.Sent()
	if len(sent) != 2 || sent[0].Subject != "🧪 [MEnglish Test] Kiểm tra kết nối Email nhận Ticket" ||
		!strings.Contains(sent[0].HTML, "smtp.gmail.com:587") || !strings.Contains(sent[0].HTML, "Quản lý (") ||
		!strings.Contains(sent[0].HTML, "https://beta.test/system-config/ticket-emails") {
		t.Fatalf("thư gửi thử: %+v", sent)
	}
	// Gửi thử không đi qua hàng đợi.
	var n int
	_ = e.Pool.QueryRow(context.Background(), `SELECT count(*) FROM core.mail_outbox`).Scan(&n)
	if n != 0 {
		t.Fatalf("gửi thử không được xếp hàng đợi: %d", n)
	}
	e.fake.Err = func(mail.Outgoing) error {
		return &textproto.Error{Code: 535, Msg: "5.7.8 Username and Password not accepted"}
	}
	r = manager.Do("POST", "/system-config/ticket-emails/test", map[string]any{"test_email": "a@x.vn"})
	iamtest.Expect(t, r, 200, "")
	if r.Body["ok"] != false || msg(r) != "Gửi thất bại." || !strings.Contains(r.Body["detail"].(string), "535") ||
		!strings.Contains(r.Body["hints"].([]any)[0].(string), "Mật khẩu ứng dụng") {
		t.Fatalf("gửi thử lỗi: %s", r.Raw)
	}
	if g := manager.Do("GET", "/system-config/ticket-emails", nil); g.Body["mail_blocked"] != false {
		t.Fatalf("mail_blocked: %s", g.Raw)
	}

	// Môi trường chặn gửi: báo lỗi như Laravel.
	e2 := setup(t, mail.Options{BlockOutgoing: true})
	_, m2 := e2.Member("MES002", "Quản lý", []uuid.UUID{e2.Branch("HN01")}, "manager")
	r = m2.Do("POST", "/system-config/ticket-emails/test", map[string]any{"test_email": "a@x.vn"})
	if r.Status != 422 || msg(r) != "Môi trường này đang chặn gửi email ra ngoài (MAIL_BLOCK_OUTGOING), không gửi thử được." {
		t.Fatalf("chặn gửi: %s", r.Raw)
	}
	if g := m2.Do("GET", "/system-config/ticket-emails", nil); g.Body["mail_blocked"] != true {
		t.Fatalf("mail_blocked: %s", g.Raw)
	}
}
