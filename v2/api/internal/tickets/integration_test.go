package tickets_test

import (
	"bytes"
	"context"
	"encoding/json"
	"strings"
	"testing"
	"time"

	"github.com/go-chi/chi/v5"
	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/dashboard"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/files"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam/iamtest"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/filestore"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/tickets"
)

var now = time.Date(2026, 10, 17, 9, 0, 0, 0, time.FixedZone("ICT", 7*3600))

var png = append([]byte("\x89PNG\r\n\x1a\n"), bytes.Repeat([]byte{3}, 80)...)

type env struct {
	*iamtest.Env
	svc *tickets.Service
}

func setup(t *testing.T) *env {
	e := iamtest.Setup(t, "ops.tickets", "ops.ticket_counters", "core.notifications", "core.mail_outbox", "core.settings",
		"core.files", "core.audit_log")
	store, err := filestore.NewLocal(t.TempDir(), files.APIBase+files.BlobPrefix, bytes.Repeat([]byte{9}, 32))
	if err != nil {
		t.Fatal(err)
	}
	fsvc := files.NewService(e.Pool, store, e.Log, 0)
	svc := tickets.NewService(e.Pool, e.Log).WithFiles(fsvc)
	svc.SetClock(func() time.Time { return now })
	fh := files.NewHandler(fsvc, e.Log)
	e.Serve(func(r chi.Router) {
		tickets.NewHandler(svc, e.Log).Routes(r)
		fh.Routes(r)
		fh.PublicRoutes(r)
	})
	return &env{e, svc}
}

func (e *env) exec(sql string, args ...any) {
	e.T.Helper()
	if _, err := e.Pool.Exec(context.Background(), sql, args...); err != nil {
		e.T.Fatal(err)
	}
}

func (e *env) setting(key string, value any) {
	b, _ := json.Marshal(value)
	e.exec(`INSERT INTO core.settings (key, value) VALUES ($1, $2) ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value`, key, b)
}

func (e *env) count(sql string, args ...any) int {
	e.T.Helper()
	var n int
	if err := e.Pool.QueryRow(context.Background(), sql, args...).Scan(&n); err != nil {
		e.T.Fatal(err)
	}
	return n
}

// notes: số thông báo loại typ của người dùng.
func (e *env) notes(user uuid.UUID, typ string) int {
	return e.count(`SELECT count(*) FROM core.notifications WHERE user_id = $1 AND type = $2`, user, typ)
}

func (e *env) mails(to string) int {
	return e.count(`SELECT count(*) FROM core.mail_outbox WHERE to_address = $1 AND kind = 'ticket'`, to)
}

func ticket(title string, extra map[string]any) map[string]any {
	in := map[string]any{"title": title, "category": "technical_issue", "priority": "high", "description": "Máy chiếu phòng 2 không lên hình."}
	for k, v := range extra {
		in[k] = v
	}
	return in
}

func TestTicketPermissions(t *testing.T) {
	e := setup(t)
	hn := e.Branch("HN01")
	iamtest.Expect(t, e.Anon().Do("GET", "/tickets", nil), 401, "")
	_, gv := e.Member("GV01", "Giáo viên A", []uuid.UUID{hn}, "teacher_fulltime")
	_, gv2 := e.Member("GV02", "Giáo viên B", []uuid.UUID{hn}, "teacher_fulltime")
	_, tv := e.Member("TV01", "Tư vấn", []uuid.UUID{hn}, "sales_consultant")
	e.Revoke("sales_consultant", "support_ticket.view", "support_ticket.create")
	iamtest.Expect(t, tv.Do("GET", "/tickets", nil), 403, "")
	iamtest.Expect(t, tv.Do("POST", "/tickets", ticket("Lỗi", nil)), 403, "")

	r := gv.Do("POST", "/tickets", ticket("Máy chiếu hỏng", nil))
	iamtest.Expect(t, r, 201, "")
	id := r.Body["id"].(string)
	// Người khác (không xử lý ticket) không thấy / không phản hồi được
	if n := len(iamtest.List(t, gv2.Do("GET", "/tickets", nil), "tickets")); n != 0 {
		t.Fatalf("GV02 không được thấy ticket của GV01, thấy %d", n)
	}
	iamtest.Expect(t, gv2.Do("GET", "/tickets/"+id, nil), 403, "forbidden")
	iamtest.Expect(t, gv2.Do("POST", "/tickets/"+id+"/messages", map[string]any{"message": "Tôi cũng bị"}), 403, "forbidden")
	// Người tạo không đổi trạng thái / phân công / ghi chú nội bộ / tự chọn người xử lý
	iamtest.Expect(t, gv.Do("POST", "/tickets/"+id+"/status", map[string]any{"status": "closed"}), 403, "")
	iamtest.Expect(t, gv.Do("POST", "/tickets/"+id+"/assign", map[string]any{"assignee_id": uuid.NewString()}), 403, "")
	iamtest.Expect(t, gv.Do("POST", "/tickets/"+id+"/messages", map[string]any{"message": "Ghi chú", "is_internal_note": true}), 403, "forbidden")
	iamtest.Expect(t, gv.Do("POST", "/tickets", ticket("Khác", map[string]any{"assignee_id": uuid.NewString()})), 403, "")
	iamtest.Expect(t, gv.Do("GET", "/tickets/"+uuid.NewString(), nil), 404, "ticket_not_found")
	// Mở bằng mã ticket
	iamtest.Expect(t, gv.Do("GET", "/tickets/"+strings.ToLower(r.Body["code"].(string)), nil), 200, "")
}

func TestTicketValidation(t *testing.T) {
	e := setup(t)
	hn := e.Branch("HN01")
	_, gv := e.Member("GV01", "Giáo viên A", []uuid.UUID{hn}, "teacher_fulltime")
	_, ql := e.Member("QL01", "Quản lý", []uuid.UUID{hn}, "manager")
	cases := []struct {
		in  map[string]any
		msg string
	}{
		{ticket("  ", nil), "Vui lòng nhập Tiêu đề."},
		{ticket(strings.Repeat("a", 256), nil), "Trường Tiêu đề không được vượt quá 255 ký tự."},
		{ticket("A", map[string]any{"category": "abc"}), "Giá trị đã chọn trong trường Danh mục không hợp lệ."},
		{ticket("A", map[string]any{"priority": ""}), "Vui lòng nhập Mức độ ưu tiên."},
		{ticket("A", map[string]any{"description": " "}), "Vui lòng nhập Mô tả chi tiết."},
		{ticket("A", map[string]any{"submission_token": "có dấu"}), "Mã lần gửi không hợp lệ."},
	}
	for _, c := range cases {
		r := gv.Do("POST", "/tickets", c.in)
		iamtest.Expect(t, r, 422, "invalid")
		if r.Body["message"] != c.msg {
			t.Fatalf("muốn %q, nhận %s", c.msg, r.Raw)
		}
	}
	// File sai loại
	payload, _ := json.Marshal(ticket("Ảnh lỗi", nil))
	r := gv.Upload("/tickets", map[string]string{"payload": string(payload)}, "virus.exe", []byte("MZ\x90\x00"))
	iamtest.Expect(t, r, 422, "invalid")
	if e.count(`SELECT count(*) FROM ops.tickets`) != 0 {
		t.Fatal("ticket lỗi không được lưu")
	}
	// Người được giao phải có quyền xử lý ticket
	teacher := mustID(t, e, "GV01")
	r = ql.Do("POST", "/tickets", ticket("Giao cho GV", map[string]any{"assignee_id": teacher.String()}))
	iamtest.Expect(t, r, 422, "invalid")
	r = ql.Do("POST", "/tickets", ticket("Ticket", nil))
	iamtest.Expect(t, r, 201, "")
	id := r.Body["id"].(string)
	r = ql.Do("POST", "/tickets/"+id+"/status", map[string]any{"status": "done"})
	iamtest.Expect(t, r, 422, "invalid")
	r = ql.Do("POST", "/tickets/"+id+"/messages", map[string]any{"message": "  "})
	if r.Status != 422 || r.Body["message"] != "Vui lòng nhập Nội dung phản hồi." {
		t.Fatalf("phản hồi rỗng: %s", r.Raw)
	}
	r = ql.Do("POST", "/tickets/"+id+"/reopen", nil)
	if r.Status != 422 || r.Body["message"] != "Ticket đang mở, không cần mở lại." {
		t.Fatalf("mở lại ticket đang mở: %s", r.Raw)
	}
}

func TestTicketFlow(t *testing.T) {
	e := setup(t)
	hn, hcm := e.Branch("HN01"), e.Branch("HCM01")
	gvU, gv := e.Member("GV01", "Giáo viên A", []uuid.UUID{hn}, "teacher_fulltime")
	qlU, ql := e.Member("QL01", "Quản lý HN", []uuid.UUID{hn}, "manager")
	ql2U, ql2 := e.Member("QL02", "Quản lý HCM", []uuid.UUID{hcm}, "manager")
	e.exec(`UPDATE iam.users SET email = 'gv01@x.vn' WHERE id = $1`, gvU.ID)
	e.exec(`UPDATE iam.users SET email = 'ql02@x.vn' WHERE id = $1`, ql2U.ID)
	e.setting("ticket_notification_emails", []string{"it@x.vn"})
	e.setting("ticket_notify_status_changed", false)

	// Tạo kèm ảnh; gửi lặp cùng mã lần gửi không tạo thêm
	payload, _ := json.Marshal(ticket("Máy chiếu hỏng", map[string]any{"submission_token": "tok-1"}))
	r := gv.Upload("/tickets", map[string]string{"payload": string(payload)}, "loi.png", png)
	iamtest.Expect(t, r, 201, "")
	id, code := r.Body["id"].(string), r.Body["code"].(string)
	if code != "TK-2026-0001" {
		t.Fatalf("mã ticket: %s", code)
	}
	r = gv.Upload("/tickets", map[string]string{"payload": string(payload)}, "loi.png", png)
	iamtest.Expect(t, r, 200, "")
	if r.Body["duplicate"] != true || r.Body["id"] != id {
		t.Fatalf("gửi lặp: %s", r.Raw)
	}
	// Cùng nội dung không có mã lần gửi trong 10 phút cũng coi là lặp
	r = gv.Do("POST", "/tickets", ticket("Máy chiếu hỏng", nil))
	if r.Body["duplicate"] != true {
		t.Fatalf("gửi lặp theo nội dung: %s", r.Raw)
	}
	if n := e.count(`SELECT count(*) FROM ops.tickets`); n != 1 {
		t.Fatalf("muốn 1 ticket, có %d", n)
	}
	// Ticket chưa phân công: báo người phân công cùng chi nhánh với người tạo; email danh sách kỹ thuật
	if e.notes(qlU.ID, "ticket_new") != 1 || e.notes(ql2U.ID, "ticket_new") != 0 {
		t.Fatal("thông báo ticket mới phải theo chi nhánh người tạo")
	}
	if e.mails("it@x.vn") != 1 {
		t.Fatal("thiếu email ticket mới cho danh sách kỹ thuật")
	}

	// Phạm vi support_ticket.scope_branch (Quản lý cơ sở): thấy ticket do nhân sự chi nhánh mình tạo; quản lý HCM không
	// thấy ticket của giáo viên HN (SupportTicket::scopeVisibleTo, rà soát 19/10/2026) → danh sách trống, mở / thao tác → 403.
	list := ql.Do("GET", "/tickets?status=open", nil)
	if rows := iamtest.List(t, list, "tickets"); len(rows) != 1 || list.Body["can_manage"] != true || list.Body["stats"].(map[string]any)["total"] != float64(1) {
		t.Fatalf("danh sách của quản lý: %s", list.Raw)
	}
	if other := ql2.Do("GET", "/tickets", nil); len(iamtest.List(t, other, "tickets")) != 0 || other.Body["stats"].(map[string]any)["total"] != float64(0) {
		t.Fatalf("quản lý HCM thấy ticket chi nhánh HN: %s", other.Raw)
	}
	iamtest.Expect(t, ql2.Do("GET", "/tickets/"+id, nil), 403, "")
	iamtest.Expect(t, ql2.Do("POST", "/tickets/"+id+"/status", map[string]any{"status": "in_progress"}), 403, "")
	iamtest.Expect(t, ql2.Do("POST", "/tickets/"+id+"/messages", map[string]any{"message": "Xem hộ"}), 403, "")
	// Người phân công chi nhánh HN không giao được cho nhân sự chi nhánh khác (handlerCandidates).
	for _, o := range iamtest.List(t, ql.Do("GET", "/tickets/"+id, nil), "staffs") {
		if o["value"] == ql2U.ID.String() {
			t.Fatal("ô người xử lý lộ nhân sự chi nhánh khác")
		}
	}
	iamtest.Expect(t, ql.Do("POST", "/tickets/"+id+"/assign", map[string]any{"assignee_id": ql2U.ID.String()}), 422, "")
	if n := len(iamtest.List(t, ql.Do("GET", "/tickets?q=khong-co", nil), "tickets")); n != 0 {
		t.Fatalf("tìm kiếm: %d", n)
	}

	// Ảnh đính kèm: người tạo tải được; người ngoài luồng thì không
	show := gv.Do("GET", "/tickets/"+id, nil)
	iamtest.Expect(t, show, 200, "")
	msgs := iamtest.List(t, show, "messages")
	atts := msgs[0]["attachments"].([]any)
	if len(atts) != 1 {
		t.Fatalf("đính kèm: %s", show.Raw)
	}
	url := atts[0].(map[string]any)["url"].(string)
	if got := gv.Get(url); got.Status != 302 {
		t.Fatalf("người tạo tải file: %d %s", got.Status, got.Raw)
	}
	_, gv2 := e.Member("GV02", "Giáo viên B", []uuid.UUID{hn}, "teacher_fulltime")
	if got := gv2.Get(url); got.Status != 403 {
		t.Fatalf("người ngoài tải file: %d", got.Status)
	}

	// Quản lý phản hồi: Mới → Đang xử lý, người tạo được báo + email; ghi chú nội bộ người tạo không thấy
	iamtest.Expect(t, ql.Do("POST", "/tickets/"+id+"/messages", map[string]any{"message": "Đã nhận, kỹ thuật sẽ qua."}), 200, "")
	iamtest.Expect(t, ql.Do("POST", "/tickets/"+id+"/messages", map[string]any{"message": "Cần thay bóng đèn", "is_internal_note": true}), 200, "")
	show = gv.Do("GET", "/tickets/"+id, nil)
	if st := show.Body["ticket"].(map[string]any)["status"]; st != "in_progress" {
		t.Fatalf("trạng thái sau phản hồi: %v", st)
	}
	if n := len(iamtest.List(t, show, "messages")); n != 2 {
		t.Fatalf("người tạo chỉ thấy 2 tin (không thấy ghi chú nội bộ), thấy %d", n)
	}
	if n := len(iamtest.List(t, ql.Do("GET", "/tickets/"+id, nil), "messages")); n != 3 {
		t.Fatalf("người xử lý thấy 3 tin, thấy %d", n)
	}
	if e.notes(gvU.ID, "ticket_message") != 1 {
		t.Fatal("người tạo phải nhận đúng 1 thông báo phản hồi (không nhận ghi chú nội bộ)")
	}
	if e.mails("gv01@x.vn") != 1 {
		t.Fatalf("email phản hồi cho người tạo: %d", e.mails("gv01@x.vn"))
	}

	// Phân công (Admin, phạm vi toàn hệ thống): người được giao nhận thông báo + email, rồi thấy ticket được giao.
	_, admin := e.Member("AD01", "Quản trị", nil, "admin")
	r = admin.Do("POST", "/tickets/"+id+"/assign", map[string]any{"assignee_id": ql2U.ID.String()})
	iamtest.Expect(t, r, 200, "")
	if r.Body["message"] != "Đã phân công xử lý ticket cho Quản lý HCM!" {
		t.Fatalf("phân công: %s", r.Raw)
	}
	if e.notes(ql2U.ID, "ticket_assigned") != 1 || e.mails("ql02@x.vn") != 1 {
		t.Fatal("người được giao phải nhận thông báo + email")
	}
	iamtest.Expect(t, ql2.Do("GET", "/tickets/"+id, nil), 200, "")

	// Đã giải quyết: ghi thời điểm, báo người tạo + người được giao (email kỹ thuật tắt cho sự kiện này)
	itBefore := e.mails("it@x.vn")
	iamtest.Expect(t, ql.Do("POST", "/tickets/"+id+"/status", map[string]any{"status": "resolved"}), 200, "")
	if e.notes(gvU.ID, "ticket_status") != 1 || e.notes(ql2U.ID, "ticket_status") != 1 {
		t.Fatal("thông báo đổi trạng thái")
	}
	if e.mails("it@x.vn") != itBefore {
		t.Fatal("tắt status_changed thì không gửi danh sách kỹ thuật")
	}
	if e.count(`SELECT count(*) FROM ops.tickets WHERE resolved_at IS NOT NULL`) != 1 {
		t.Fatal("thiếu resolved_at")
	}
	// Người tạo trả lời ticket đã giải quyết → mở lại
	iamtest.Expect(t, gv.Do("POST", "/tickets/"+id+"/messages", map[string]any{"message": "Vẫn chưa được ạ"}), 200, "")
	if st := gv.Do("GET", "/tickets/"+id, nil).Body["ticket"].(map[string]any)["status"]; st != "in_progress" {
		t.Fatalf("trả lời ticket đã giải quyết: %v", st)
	}
	// Đóng rồi người tạo mở lại
	iamtest.Expect(t, ql.Do("POST", "/tickets/"+id+"/status", map[string]any{"status": "closed"}), 200, "")
	iamtest.Expect(t, gv.Do("POST", "/tickets/"+id+"/reopen", nil), 200, "")
	show = gv.Do("GET", "/tickets/"+id, nil)
	if st := show.Body["ticket"].(map[string]any)["status"]; st != "in_progress" {
		t.Fatalf("mở lại: %v", st)
	}
	if e.count(`SELECT count(*) FROM core.audit_log WHERE subject_type = 'ticket'`) < 6 {
		t.Fatal("thiếu nhật ký thao tác ticket")
	}

	// Tổng quan: ticket đang mở theo chi nhánh chính của người tạo; Nhật ký sự vụ
	d := e.svc.DashboardTickets()
	ctx := context.Background()
	c, err := d.Counts(ctx, []uuid.UUID{hn})
	if err != nil || c.Open != 1 || c.Unassigned != 0 {
		t.Fatalf("đếm HN: %+v %v", c, err)
	}
	if c, _ := d.Counts(ctx, []uuid.UUID{hcm}); c.Open != 0 {
		t.Fatalf("đếm HCM: %+v", c)
	}
	if c, _ := d.Counts(ctx, nil); c.Open != 1 {
		t.Fatalf("đếm toàn hệ thống: %+v", c)
	}
	acc, err := e.IAM.LoadAccess(ctx, gvU.ID)
	if err != nil {
		t.Fatal(err)
	}
	rows, ok, err := d.Incidents(ctx, acc, dashboard.TicketIncidentFilter{Priorities: []string{"urgent", "high"}, BranchID: &hn, Status: "open"}, 20)
	if err != nil || !ok || len(rows) != 1 || !rows[0].CanOpen || rows[0].Branch == nil || *rows[0].Branch == "" {
		t.Fatalf("sự vụ: %+v %v", rows, err)
	}
	acc2, _ := e.IAM.LoadAccess(ctx, mustID(t, e, "GV02"))
	rows, _, _ = d.Incidents(ctx, acc2, dashboard.TicketIncidentFilter{Priorities: []string{"high"}}, 20)
	if len(rows) != 1 || rows[0].CanOpen {
		t.Fatalf("GV02 thấy sự vụ nhưng không mở được: %+v", rows)
	}
	if rows, _, _ := d.Incidents(ctx, acc, dashboard.TicketIncidentFilter{Priorities: []string{"urgent"}}, 20); len(rows) != 0 {
		t.Fatalf("lọc mức khẩn: %+v", rows)
	}
}

func mustID(t *testing.T, e *env, code string) uuid.UUID {
	var id uuid.UUID
	if err := e.Pool.QueryRow(context.Background(), `SELECT id FROM iam.users WHERE code = $1`, code).Scan(&id); err != nil {
		t.Fatal(err)
	}
	return id
}

// Người tạo tự phân công được (Quản lý) → chỉ báo Admin; Admin tự tạo → không báo ai.
func TestTicketDispatchers(t *testing.T) {
	e := setup(t)
	hn := e.Branch("HN01")
	adU, ad := e.Member("AD01", "Admin", []uuid.UUID{hn}, iam.RoleAdmin)
	qlU, ql := e.Member("QL01", "Quản lý", []uuid.UUID{hn}, "manager")
	ql2U := e.User("QL02", "Quản lý 2", []uuid.UUID{hn}, "manager")
	iamtest.Expect(t, ql.Do("POST", "/tickets", ticket("Cần Admin", nil)), 201, "")
	if e.notes(adU.ID, "ticket_new") != 1 || e.notes(ql2U.ID, "ticket_new") != 0 || e.notes(qlU.ID, "ticket_new") != 0 {
		t.Fatal("quản lý tạo ticket: chỉ báo Admin")
	}
	iamtest.Expect(t, ad.Do("POST", "/tickets", ticket("Admin tự tạo", nil)), 201, "")
	if n := e.count(`SELECT count(*) FROM core.notifications WHERE type = 'ticket_new'`); n != 1 {
		t.Fatalf("Admin tự tạo không báo ai, có %d thông báo", n)
	}
	// Học viên / nhân sự không có chi nhánh: báo mọi người phân công
	_, gv := e.Member("GV09", "Giáo viên không chi nhánh", nil, "teacher_parttime")
	iamtest.Expect(t, gv.Do("POST", "/tickets", ticket("Không chi nhánh", nil)), 201, "")
	if e.notes(qlU.ID, "ticket_new") != 1 || e.notes(ql2U.ID, "ticket_new") != 1 {
		t.Fatal("người tạo không có chi nhánh: báo mọi người phân công")
	}
}
