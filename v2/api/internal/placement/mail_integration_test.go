package placement

import (
	"context"
	"io"
	"log/slog"
	"net/textproto"
	"strings"
	"testing"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/notify/mail"
)

// event: sự kiện gần nhất của khách có nội dung chứa substr.
func (f *fakeLeads) event(t *testing.T, lead uuid.UUID, substr string) LeadEvent {
	t.Helper()
	f.mu.Lock()
	defer f.mu.Unlock()
	for i := len(f.events) - 1; i >= 0; i-- {
		if f.events[i].LeadID == lead && strings.Contains(f.events[i].Content, substr) {
			return f.events[i]
		}
	}
	t.Fatalf("khách %s chưa có sự kiện %q: %+v", lead, substr, f.events)
	return LeadEvent{}
}

// Xác nhận lịch học thử cho phụ huynh (AppointmentConfirmation::forTrial / deliver).
func TestTrialConfirmation(t *testing.T) {
	e := setup(t)
	ctx := context.Background()
	if _, err := e.pg.Exec(ctx, `UPDATE core.branches SET address = '12 Lê Lợi', phone = '0243999888' WHERE id = $1`, e.branch); err != nil {
		t.Fatal(err)
	}
	vn := e.now().In(vnZone)
	e.clock = time.Date(vn.Year(), vn.Month(), vn.Day(), 9, 0, 0, 0, vnZone)
	day := func(add int) time.Time {
		d := e.clock.AddDate(0, 0, add)
		return time.Date(d.Year(), d.Month(), d.Day(), 0, 0, 0, 0, time.UTC)
	}
	staffM := e.member(t, "hv01", "lead.view", "lead.stage_forward", "class.view")
	staff := e.as(t, staffM)
	owner := e.member(t, "sale01", "lead.view")

	noEmail := Lead{ID: uuid.New(), Name: "Bé Mai", Phone: "0911111111", ParentPhone: "0922222222", Stage: "consulting", BranchID: &e.branch}
	withEmail := Lead{ID: uuid.New(), Name: "Bé Na", Phone: "0933333333", Email: "PH.Na@Example.vn", Stage: "consulting",
		BranchID: &e.branch, AssignedUserID: &owner.id}
	generated := Lead{ID: uuid.New(), Name: "Bé Tí", Phone: "0944444444", Email: "hv1@student.menglish.edu.vn", Stage: "consulting", BranchID: &e.branch}
	for _, l := range []Lead{noEmail, withEmail, generated} {
		e.addLead(l)
	}
	e.leads.visible[staffM.id] = []uuid.UUID{noEmail.ID, withEmail.ID, generated.ID}
	s1 := Session{ID: uuid.New(), ClassID: uuid.New(), ClassName: "Movers A", ClassStatus: "active", Status: "scheduled", BranchID: &e.branch,
		Date: day(1), Start: "17:30", End: "19:00"}
	e.sessions.sessions[s1.ID] = s1
	e.realSessions(t, s1)
	wantText := "Kính gửi Quý phụ huynh,\nMEnglish xác nhận lịch học thử của Bé Mai:\n• Thời gian: 17:30–19:00, " + mail.DayLabel(s1.Date) +
		"\n• Lớp: Movers A\n• Địa điểm: cơ sở Cơ sở 1 (12 Lê Lợi)\nNếu cần đổi lịch, phụ huynh vui lòng báo lại trung tâm qua số 0243999888. Cảm ơn Quý phụ huynh!"

	// Chưa có email: nhắc người xếp (khách chưa có người phụ trách) gửi Zalo, trả nội dung cho popup.
	r := staff.do("POST", "/trial-bookings", map[string]any{"lead_id": noEmail.ID, "class_session_id": s1.ID})
	expect(t, r, 201, "")
	c := obj(r.body["confirmation"])
	if c["emailed"] != nil || c["email_failed"] != false || c["text"] != wantText || c["phone"] != "0922222222" {
		t.Fatalf("xác nhận không có email: %s", r.raw)
	}
	if got := e.inbox(staffM.id, confirmNotifyType); got != "Gửi xác nhận lịch hẹn qua Zalo: Bé Mai" {
		t.Fatalf("thông báo gửi Zalo: %q", got)
	}
	var body, copyText string
	if err := e.pg.QueryRow(ctx, `SELECT body, data->>'copy_text' FROM core.notifications WHERE user_id = $1 AND type = $2`,
		staffM.id, confirmNotifyType).Scan(&body, &copyText); err != nil {
		t.Fatal(err)
	}
	if body != "Khách chưa có email. Sao chép nội dung xác nhận và gửi phụ huynh qua Zalo (0922222222)." || copyText != wantText {
		t.Fatalf("nội dung thông báo: %q / %q", body, copyText)
	}
	e.leads.event(t, noEmail.ID, "Khách chưa có email: đã nhắc người phụ trách gửi xác nhận lịch hẹn cho phụ huynh qua Zalo.")

	// Email tự sinh của học viên không phải email thật → như chưa có email.
	r = staff.do("POST", "/trial-bookings", map[string]any{"lead_id": generated.ID, "class_session_id": s1.ID})
	expect(t, r, 201, "")
	if obj(r.body["confirmation"])["emailed"] != nil {
		t.Fatalf("email tự sinh: %s", r.raw)
	}

	// Có email: xếp thư xác nhận (3 lần thử), nhật ký "Đã gửi email…".
	r = staff.do("POST", "/trial-bookings", map[string]any{"lead_id": withEmail.ID, "class_session_id": s1.ID})
	expect(t, r, 201, "")
	bookingID := r.body["id"].(string)
	if c := obj(r.body["confirmation"]); c["emailed"] != "ph.na@example.vn" || c["phone"] != "0933333333" {
		t.Fatalf("xác nhận có email: %s", r.raw)
	}
	e.leads.event(t, withEmail.ID, "Đã gửi email xác nhận lịch hẹn tới ph.na@example.vn.")
	var to, kind, refType, refID string
	var maxAttempts int
	if err := e.pg.QueryRow(ctx, `SELECT to_address, kind, max_attempts, ref_type, ref_id FROM core.mail_outbox`).
		Scan(&to, &kind, &maxAttempts, &refType, &refID); err != nil {
		t.Fatal(err)
	}
	if to != "ph.na@example.vn" || kind != mail.KindParentNotice || maxAttempts != 3 || refType != "trial_booking" || refID != bookingID {
		t.Fatalf("thư trong hàng đợi: %s %s %d %s %s", to, kind, maxAttempts, refType, refID)
	}

	// Vòng gửi: máy chủ từ chối địa chỉ → thư lỗi hẳn, người phụ trách khách nhận nhắc gửi Zalo.
	fake := &mail.FakeTransport{Err: func(mail.Outgoing) error { return &textproto.Error{Code: 550, Msg: "no such user"} }}
	svc := mail.NewService(e.pg, nil, slog.New(slog.NewTextHandler(io.Discard, nil)), mail.Options{Transport: fake, PublicURL: "https://beta.test"})
	if _, err := svc.ProcessDue(ctx); err != nil {
		t.Fatal(err)
	}
	if got := e.inbox(owner.id, confirmNotifyType); got != "Gửi xác nhận lịch hẹn qua Zalo: Bé Na" {
		t.Fatalf("nhắc người phụ trách khi email lỗi: %q", got)
	}
	if err := e.pg.QueryRow(ctx, `SELECT body FROM core.notifications WHERE user_id = $1 AND type = $2`, owner.id, confirmNotifyType).Scan(&body); err != nil {
		t.Fatal(err)
	}
	if body != "Gửi email tới ph.na@example.vn không thành công. Sao chép nội dung xác nhận và gửi phụ huynh qua Zalo (0933333333)." {
		t.Fatalf("nội dung nhắc: %q", body)
	}

	// Gửi được: thư theo mẫu ParentNotice.
	if _, err := e.pg.Exec(ctx, `UPDATE core.mail_outbox SET status = 'pending', attempts = 0, next_attempt_at = now()`); err != nil {
		t.Fatal(err)
	}
	fake.Err = nil
	if _, err := svc.ProcessDue(ctx); err != nil {
		t.Fatal(err)
	}
	sent := fake.Sent()
	if len(sent) != 1 || sent[0].Subject != "Xác nhận lịch học thử — Bé Na" || sent[0].ToName != "Bé Na" ||
		!strings.Contains(sent[0].Text, "MEnglish xác nhận lịch học thử của Bé Na:") || !strings.Contains(sent[0].HTML, "MENGLISH") {
		t.Fatalf("thư xác nhận: %+v", sent)
	}
}

// Email vận hành khi thí sinh nộp bài (NotificationService::notifyHomeworkOrTestSubmission).
func TestSubmissionAlertEmail(t *testing.T) {
	e := setup(t)
	ctx := context.Background()
	if _, err := e.pg.Exec(ctx, `INSERT INTO core.settings (key, value) VALUES ('ticket_notification_emails', '["hocvu@x.vn"]')`); err != nil {
		t.Fatal(err)
	}
	subID := uuid.New()
	at := time.Date(2026, 10, 17, 3, 15, 0, 0, time.UTC)
	e.svc.notifySubmission(ctx, subID, 12, "Trần An", "", "Đề G3", nil, at)
	var to, subject, data string
	if err := e.pg.QueryRow(ctx, `SELECT to_address, subject, data::text FROM core.mail_outbox WHERE kind = 'ops_alert'`).Scan(&to, &subject, &data); err != nil {
		t.Fatal(err)
	}
	if to != "hocvu@x.vn" || subject != "[Học vụ] Học viên nộp bài: Trần An - Đề G3" {
		t.Fatalf("thư nộp bài: %s %s", to, subject)
	}
	for _, s := range []string{`"code": "TEST-12"`, "• Học viên: Trần An (SĐT: ---)", "• Thời gian nộp: 10:15 17/10/2026", "Chờ Học vụ chấm",
		"/placement-tests/submissions/" + subID.String(), "Xem Báo cáo Điểm số", "Cổng Portal Khảo thí"} {
		if !strings.Contains(data, s) {
			t.Errorf("dữ liệu thư thiếu %q: %s", s, data)
		}
	}
	// Tắt sự kiện "homework" thì không gửi.
	if _, err := e.pg.Exec(ctx, `INSERT INTO core.settings (key, value) VALUES ('ticket_notify_homework', 'false')`); err != nil {
		t.Fatal(err)
	}
	e.svc.notifySubmission(ctx, uuid.New(), 13, "B", "", "Đề G3", nil, at)
	var n int
	_ = e.pg.QueryRow(ctx, `SELECT count(*) FROM core.mail_outbox`).Scan(&n)
	if n != 1 {
		t.Fatalf("sự kiện tắt vẫn gửi: %d", n)
	}
}
