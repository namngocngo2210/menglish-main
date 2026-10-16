package notifyapi

import (
	"context"
	"slices"
	"strings"
	"testing"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam/iamtest"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/notify"
)

func setup(t *testing.T) *iamtest.Env {
	e := iamtest.Setup(t, "core.notifications")
	e.Serve(NewHandler(NewService(e.Pool), e.Log).Routes)
	return e
}

func send(t *testing.T, e *iamtest.Env, ids []uuid.UUID, n notify.Notification) int {
	t.Helper()
	got, err := notify.Send(context.Background(), e.Pool, ids, n)
	if err != nil {
		t.Fatal(err)
	}
	return got
}

// Gửi bỏ trùng / bỏ người loại trừ / bỏ tài khoản khóa; hộp thư chỉ thấy của mình; lọc, phân trang, đánh dấu đã đọc.
func TestSendAndInbox(t *testing.T) {
	e := setup(t)
	ctx := context.Background()
	t1, c1 := e.Member("GV001", "Giáo viên A", nil, iam.RoleTeacherFulltime)
	t2, c2 := e.Member("GV002", "Giáo viên B", nil, iam.RoleTeacherFulltime)
	t3 := e.User("GV003", "Giáo viên nghỉ", nil, iam.RoleTeacherFulltime)
	e.Grant(iam.RoleTeacherFulltime, "notification.view")
	if _, err := e.Pool.Exec(ctx, "UPDATE iam.users SET status = 'disabled' WHERE id = $1", t3.ID); err != nil {
		t.Fatal(err)
	}

	if n := send(t, e, []uuid.UUID{t1.ID, t1.ID, t2.ID, t3.ID, uuid.Nil}, notify.Notification{
		Type: "task_assigned", Title: "Bạn được giao việc mới", Body: "Chuẩn bị tài liệu lớp IELTS", Except: &t2.ID,
	}); n != 1 {
		t.Fatalf("gửi: %d thông báo, muốn 1 (bỏ trùng, bỏ người loại trừ, bỏ tài khoản khóa)", n)
	}
	send(t, e, []uuid.UUID{t1.ID}, notify.Notification{
		Type: "ticket_assigned", Title: "[#TK-00001] Máy chiếu hỏng", Link: "/tickets/1",
		Data: map[string]any{"copy_text": "Kính gửi phụ huynh"}, CreatedBy: &t2.ID,
	})
	send(t, e, []uuid.UUID{t2.ID}, notify.Notification{Type: "custom_type", Title: "Của B"})

	for _, bad := range []notify.Notification{
		{Type: "Sai Loai", Title: "x"},
		{Type: "task_assigned", Title: "   "},
		{Type: "task_assigned", Title: "x", Link: "https://evil.example"},
		{Type: "task_assigned", Title: "x", Link: "//evil.example"},
	} {
		if _, err := notify.Send(ctx, e.Pool, []uuid.UUID{t1.ID}, bad); err == nil {
			t.Fatalf("phải từ chối %+v", bad)
		}
	}
	if n := send(t, e, nil, notify.Notification{Type: "task_assigned", Title: "Không ai nhận"}); n != 0 {
		t.Fatal("danh sách rỗng phải gửi 0")
	}

	r := c1.Do("GET", "/notifications", nil)
	iamtest.Expect(t, r, 200, "")
	items := iamtest.List(t, r, "notifications")
	if len(items) != 2 || items[0]["type_label"] != "Phân công Ticket" || items[0]["icon"] != "assignment_ind" || items[0]["link"] != "/tickets/1" {
		t.Fatalf("hộp thư A: %v", items)
	}
	if items[0]["data"].(map[string]any)["copy_text"] != "Kính gửi phụ huynh" || items[1]["body"] != "Chuẩn bị tài liệu lớp IELTS" {
		t.Fatalf("dữ liệu kèm theo: %v", items)
	}
	if st := r.Body["stats"].(map[string]any); st["total"] != 2.0 || st["unread"] != 2.0 || st["alerts"] != 0.0 {
		t.Fatalf("thống kê: %v", st)
	}
	if types := iamtest.List(t, r, "types"); len(types) < 40 {
		t.Fatalf("danh sách loại: %d", len(types))
	}
	// Loại chưa khai báo hiện nhãn mặc định
	other := iamtest.List(t, c2.Do("GET", "/notifications", nil), "notifications")
	if len(other) != 1 || other[0]["type_label"] != "Thông báo hệ thống" || other[0]["title"] != "Của B" {
		t.Fatalf("hộp thư B: %v", other)
	}

	if l := iamtest.List(t, c1.Do("GET", "/notifications?type=ticket_assigned", nil), "notifications"); len(l) != 1 {
		t.Fatalf("lọc loại: %v", l)
	}
	r = c1.Do("GET", "/notifications?per_page=1&page=2", nil)
	if l := iamtest.List(t, r, "notifications"); len(l) != 1 || l[0]["type"] != "task_assigned" || r.Body["total"] != 2.0 {
		t.Fatalf("phân trang: %v %v", l, r.Body["total"])
	}
	r = c1.Do("GET", "/notifications/summary", nil)
	iamtest.Expect(t, r, 200, "")
	if r.Body["unread"] != 2.0 || len(iamtest.List(t, r, "items")) != 2 {
		t.Fatalf("chuông: %v", r.Body)
	}

	id := items[0]["id"].(string)
	// Người khác không đánh dấu được thông báo của A; id sai trả 404
	iamtest.Expect(t, c2.Do("POST", "/notifications/"+id+"/read", nil), 404, "not_found")
	iamtest.Expect(t, c1.Do("POST", "/notifications/khong-phai-id/read", nil), 404, "not_found")
	r = c1.Do("POST", "/notifications/"+id+"/read", nil)
	iamtest.Expect(t, r, 200, "")
	if r.Body["unread"] != 1.0 {
		t.Fatalf("còn chưa đọc: %v", r.Body)
	}
	iamtest.Expect(t, c1.Do("POST", "/notifications/"+id+"/read", nil), 200, "")
	if l := iamtest.List(t, c1.Do("GET", "/notifications?unread=1", nil), "notifications"); len(l) != 1 || l[0]["type"] != "task_assigned" {
		t.Fatalf("lọc chưa đọc: %v", l)
	}

	r = c1.Do("POST", "/notifications/read-all", nil)
	iamtest.Expect(t, r, 200, "")
	if r.Body["updated"] != 1.0 {
		t.Fatalf("đọc tất cả: %v", r.Body)
	}
	if r := c1.Do("GET", "/notifications/summary", nil); r.Body["unread"] != 0.0 {
		t.Fatalf("sau khi đọc tất cả: %v", r.Body)
	}
	// Của B không bị đánh dấu theo
	if r := c2.Do("GET", "/notifications/summary", nil); r.Body["unread"] != 1.0 {
		t.Fatalf("hộp thư B bị ảnh hưởng: %v", r.Body)
	}
}

func TestPermission(t *testing.T) {
	e := setup(t)
	_, teacher := e.Member("GV001", "Giáo viên A", nil, iam.RoleTeacherFulltime)
	e.Revoke(iam.RoleTeacherFulltime, "notification.view")
	iamtest.Expect(t, teacher.Do("GET", "/notifications", nil), 403, "forbidden")
	iamtest.Expect(t, teacher.Do("GET", "/notifications/summary", nil), 403, "forbidden")
	iamtest.Expect(t, teacher.Do("POST", "/notifications/read-all", nil), 403, "forbidden")

	iamtest.Expect(t, e.Anon().Do("GET", "/notifications/summary", nil), 401, "unauthenticated")
	e.Grant(iam.RoleTeacherFulltime, "notification.view")
	iamtest.Expect(t, teacher.Do("GET", "/notifications", nil), 200, "")
}

// Người nhận theo quyền: cùng thứ tự quyết định với Access.Can, lọc chi nhánh, bỏ tài khoản khóa.
func TestRecipients(t *testing.T) {
	e := setup(t)
	ctx := context.Background()
	a, b := e.Branch("HN01"), e.Branch("HN02")
	admin := e.User("MES001", "Quản trị", nil, iam.RoleAdmin)
	s1 := e.User("HV001", "Học vụ A", []uuid.UUID{a}, iam.RoleAcademicStaff)
	s2 := e.User("HV002", "Học vụ B", []uuid.UUID{b}, iam.RoleAcademicStaff)
	denied := e.User("HV003", "Học vụ bị chặn", []uuid.UUID{a}, iam.RoleAcademicStaff)
	mgr := e.User("QL001", "Quản lý", []uuid.UUID{a}, iam.RoleManager)
	off := e.User("HV004", "Học vụ nghỉ", []uuid.UUID{a}, iam.RoleAcademicStaff)
	teacher := e.User("GV001", "Giáo viên", []uuid.UUID{a}, iam.RoleTeacherFulltime)
	e.Grant(iam.RoleAcademicStaff, "activity_log.view")
	e.Revoke(iam.RoleManager, "activity_log.view")
	e.Revoke(iam.RoleTeacherFulltime, "activity_log.view")
	for _, q := range []struct {
		sql  string
		args []any
	}{
		{"INSERT INTO iam.permission_overrides (user_id, permission, allow) VALUES ($1, 'activity_log.view', false)", []any{denied.ID}},
		{"INSERT INTO iam.permission_overrides (user_id, permission, allow) VALUES ($1, 'activity_log.view', true)", []any{mgr.ID}},
		{"UPDATE iam.users SET status = 'disabled' WHERE id = $1", []any{off.ID}},
	} {
		if _, err := e.Pool.Exec(ctx, q.sql, q.args...); err != nil {
			t.Fatal(err)
		}
	}
	check := func(name string, got []uuid.UUID, err error, want ...iamtest.User) {
		t.Helper()
		if err != nil {
			t.Fatal(err)
		}
		var ids []uuid.UUID
		for _, u := range want {
			ids = append(ids, u.ID)
		}
		slices.SortFunc(ids, func(x, y uuid.UUID) int { return strings.Compare(x.String(), y.String()) })
		if !slices.Equal(got, ids) {
			t.Fatalf("%s: %v, muốn %v", name, got, ids)
		}
	}
	got, err := iam.UserIDsWithPermission(ctx, e.Pool, "activity_log.view", nil)
	check("mọi chi nhánh", got, err, admin, s1, s2, mgr)
	got, err = iam.UserIDsWithPermission(ctx, e.Pool, "activity_log.view", &a)
	check("chi nhánh HN01", got, err, s1, mgr)
	// Quyền chỉ Admin: phân quyền cá nhân không mở được
	if _, err := e.Pool.Exec(ctx, "INSERT INTO iam.permission_overrides (user_id, permission, allow) VALUES ($1, 'tuition.approve', true)", teacher.ID); err != nil {
		t.Fatal(err)
	}
	got, err = iam.UserIDsWithPermission(ctx, e.Pool, "tuition.approve", nil)
	check("quyền chỉ Admin", got, err, admin)
	got, err = iam.AdminIDs(ctx, e.Pool)
	check("Admin", got, err, admin)
	if _, err := iam.UserIDsWithPermission(ctx, e.Pool, "khong.ton_tai", nil); err == nil {
		t.Fatal("quyền không có trong danh mục phải báo lỗi")
	}
}
