package search_test

import (
	"context"
	"errors"
	"net/url"
	"strings"
	"testing"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam/iamtest"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/search"
)

func setup(t *testing.T, extra ...search.Searcher) *iamtest.Env {
	e := iamtest.Setup(t)
	reg := search.Builtin(e.IAM)
	for _, s := range extra {
		reg.Add(s)
	}
	e.Serve(search.NewHandler(reg, e.Log).Routes)
	return e
}

func run(t *testing.T, c *iamtest.Client, term string) iamtest.Resp {
	t.Helper()
	r := c.Do("GET", "/search?"+url.Values{"q": {term}}.Encode(), nil)
	iamtest.Expect(t, r, 200, "")
	return r
}

func group(r iamtest.Resp, key string) []map[string]any {
	for _, g := range r.Body["groups"].([]any) {
		g := g.(map[string]any)
		if g["key"] == key {
			var out []map[string]any
			for _, it := range g["items"].([]any) {
				out = append(out, it.(map[string]any))
			}
			return out
		}
	}
	return nil
}

func titles(items []map[string]any) []string {
	var out []string
	for _, it := range items {
		out = append(out, it["title"].(string))
	}
	return out
}

func TestSearchUsersAndBranches(t *testing.T) {
	e := setup(t)
	a, b := e.Branch("HN01"), e.Branch("HCM01")
	if _, err := e.Pool.Exec(context.Background(), "UPDATE core.branches SET name = 'Hà Nội Cầu Giấy', address = '12 Trần Thái Tông' WHERE id = $1", a); err != nil {
		t.Fatal(err)
	}
	_, admin := e.Member("MES001", "Quản trị", nil, iam.RoleAdmin)
	e.User("GV001", "Trần Thị Hà", []uuid.UUID{a}, iam.RoleTeacherFulltime)
	e.User("GV002", "Lê Văn Hà", []uuid.UUID{b}, iam.RoleTeacherFulltime)
	e.User("GV003", "Nguyễn Minh", []uuid.UUID{a}, iam.RoleTeacherFulltime)

	r := run(t, admin, "Hà")
	if got := titles(group(r, "users")); len(got) != 2 || !strings.Contains(strings.Join(got, ","), "Trần Thị Hà") {
		t.Fatalf("người dùng: %v", got)
	}
	br := group(r, "branches")
	if len(br) != 1 || br[0]["title"] != "Hà Nội Cầu Giấy" || br[0]["link"] != "/branches" || br[0]["subtitle"] != "12 Trần Thái Tông" {
		t.Fatalf("chi nhánh: %v", br)
	}
	if r.Body["total"] != 3.0 || len(r.Body["searched"].([]any)) != 2 {
		t.Fatalf("tổng: %v", r.Body)
	}
	u := group(r, "users")[0]
	if !strings.HasPrefix(u["link"].(string), "/users?") || !strings.Contains(u["link"].(string), "open="+u["id"].(string)) {
		t.Fatalf("đường dẫn người dùng: %v", u["link"])
	}
	// Tìm theo mã; ký tự đặc biệt của LIKE không làm khớp tất cả
	if got := titles(group(run(t, admin, "gv003"), "users")); len(got) != 1 || got[0] != "Nguyễn Minh" {
		t.Fatalf("theo mã: %v", got)
	}
	if r := run(t, admin, "%%"); r.Body["total"] != 0.0 {
		t.Fatalf("%%%% phải không khớp gì: %v", r.Body)
	}

	// Từ khóa quá ngắn: không tìm; quá dài: 422 kèm thông báo
	if r := run(t, admin, " H "); len(r.Body["searched"].([]any)) != 0 || r.Body["total"] != 0.0 {
		t.Fatalf("từ khóa ngắn: %v", r.Body)
	}
	r = admin.Do("GET", "/search?q="+strings.Repeat("a", 101), nil)
	iamtest.Expect(t, r, 422, "invalid")
	if r.Body["message"] != "Từ khóa tìm kiếm tối đa 100 ký tự." {
		t.Fatalf("thông báo: %v", r.Body)
	}
}

// Quyền: không có quyền của nhóm nào thì 403; nhóm người dùng theo phạm vi chi nhánh như màn Người dùng.
func TestSearchPermissionAndScope(t *testing.T) {
	e := setup(t)
	a, b := e.Branch("HN01"), e.Branch("HCM01")
	e.User("MES001", "Quản trị", nil, iam.RoleAdmin)
	_, manager := e.Member("QL001", "Quản lý Hà", []uuid.UUID{a}, iam.RoleManager)
	e.User("GV001", "Giáo viên Hà A", []uuid.UUID{a}, iam.RoleTeacherFulltime)
	e.User("GV002", "Giáo viên Hà B", []uuid.UUID{b}, iam.RoleTeacherFulltime)
	_, teacher := e.Member("GV003", "Giáo viên C", []uuid.UUID{a}, iam.RoleTeacherFulltime)
	e.Revoke(iam.RoleTeacherFulltime, "user.view", "branch.view")

	iamtest.Expect(t, teacher.Do("GET", "/search?q=Hà", nil), 403, "forbidden")
	iamtest.Expect(t, e.Anon().Do("GET", "/search?q=Hà", nil), 401, "unauthenticated")

	e.Grant(iam.RoleManager, "user.view")
	e.Revoke(iam.RoleManager, "branch.view")
	r := run(t, manager, "Giáo viên Hà")
	if got := titles(group(r, "users")); len(got) != 1 || got[0] != "Giáo viên Hà A" {
		t.Fatalf("Quản lý chỉ thấy người chi nhánh mình: %v", got)
	}
	if s := r.Body["searched"].([]any); len(s) != 1 || s[0] != "users" {
		t.Fatalf("không có branch.view thì không tìm chi nhánh: %v", s)
	}
}

// Module khác đăng ký nhóm của mình; lỗi của nhóm trả 500, khai báo sai thì panic lúc khởi động.
func TestRegistry(t *testing.T) {
	var got search.Query
	e := setup(t, search.Searcher{
		Key: "students", Label: "Học viên", Icon: "school", Perms: []string{"student.view"},
		Search: func(_ context.Context, _ *iam.Access, q search.Query) ([]search.Hit, error) {
			got = q
			if q.Term == "lỗi" {
				return nil, errors.New("hỏng")
			}
			return []search.Hit{{ID: "1", Title: "Học viên " + q.Term, Link: "/students/1"}, {ID: "2", Title: "Thừa", Link: "/students/2"}}, nil
		},
	})
	_, staff := e.Member("HV001", "Học vụ", nil, iam.RoleAcademicStaff)
	e.Grant(iam.RoleAcademicStaff, "student.view")
	r := staff.Do("GET", "/search?q=0912-345-678&limit=1", nil)
	iamtest.Expect(t, r, 200, "")
	if items := group(r, "students"); len(items) != 1 || items[0]["title"] != "Học viên 0912-345-678" {
		t.Fatalf("nhóm đăng ký thêm: %v", r.Body)
	}
	if got.Phone != "%0912345678%" || got.Like != "%0912-345-678%" || got.Limit != 1 {
		t.Fatalf("query: %+v", got)
	}
	iamtest.Expect(t, staff.Do("GET", "/search?q=lỗi", nil), 500, "")

	mustPanic := func(name string, s search.Searcher) {
		t.Helper()
		defer func() {
			if recover() == nil {
				t.Fatalf("%s: phải panic", name)
			}
		}()
		search.NewRegistry().Add(s).Add(s)
	}
	ok := func(context.Context, *iam.Access, search.Query) ([]search.Hit, error) { return nil, nil }
	mustPanic("trùng khóa", search.Searcher{Key: "x", Label: "X", Perms: []string{"user.view"}, Search: ok})
	mustPanic("quyền sai", search.Searcher{Key: "y", Label: "Y", Perms: []string{"khong.co"}, Search: ok})
	mustPanic("thiếu quyền", search.Searcher{Key: "z", Label: "Z", Search: ok})
}
