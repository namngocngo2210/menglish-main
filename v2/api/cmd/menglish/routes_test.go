package main

import (
	"net/http"
	"reflect"
	"strings"
	"testing"

	"github.com/go-chi/chi/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit/auditapi"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/catalogs"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/classes"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/crm"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/dashboard"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/files"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/finance"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/inventory"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/kpi"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/notify/mail/mailapi"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/notify/notifyapi"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/placement"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/reports"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/search"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/students"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/tasks"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/tuition"
)

// publicRoutes: route không cần quyền (đăng nhập và tự quản lý tài khoản của chính mình; đã có RequireAuth).
var publicRoutes = []string{
	"/auth/",
	// Thí sinh làm bài test đầu vào không đăng nhập: chỉ vào được bằng token link mời (băm SHA-256, có hạn,
	// thu hồi được) và bị giới hạn tần suất theo IP.
	placement.PublicPrefix + "/",
	// Nội dung file của driver local: chỉ mở được bằng token ký HMAC còn hạn (5 phút), cấp sau khi đã kiểm quyền.
	files.BlobPrefix + "/",
	// Tải file: iam.RequireSignedIn (đăng nhập, CSRF, đổi mật khẩu, 2FA); quyền do phân hệ sở hữu file kiểm theo
	// từng file (files.Service.RegisterReader), như users.contract.download của Laravel.
	files.DownloadRoute,
}

// unguarded trả các route nghiệp vụ không gắn iam.Require / iam.RequireAny, và số route đã kiểm.
func unguarded(t *testing.T, r chi.Routes) ([]string, int) {
	guards := map[uintptr]bool{
		reflect.ValueOf(iam.Require("user.view")).Pointer():    true,
		reflect.ValueOf(iam.RequireAny("user.view")).Pointer(): true,
	}
	var bad []string
	count := 0
	err := chi.Walk(r, func(method, route string, _ http.Handler, mws ...func(http.Handler) http.Handler) error {
		for _, p := range publicRoutes {
			if strings.HasPrefix(route, p) {
				return nil
			}
		}
		count++
		for _, mw := range mws {
			if guards[reflect.ValueOf(mw).Pointer()] {
				return nil
			}
		}
		bad = append(bad, method+" "+route)
		return nil
	})
	if err != nil {
		t.Fatal(err)
	}
	return bad, count
}

// TestEveryRouteHasPermission: mọi route nghiệp vụ phải gắn iam.Require hoặc iam.RequireAny.
// Thêm module mới mà quên gắn quyền thì test này đỏ.
func TestEveryRouteHasPermission(t *testing.T) {
	r := chi.NewRouter()
	apiRoutes(iam.NewHandler(nil, nil), platform{audit: auditapi.NewHandler(nil, nil), notify: notifyapi.NewHandler(nil, nil), search: search.NewHandler(search.Builtin(nil), nil)}, students.NewHandler(nil, nil), inventory.NewHandler(nil, nil), catalogs.NewHandler(nil, nil), classes.NewHandler(nil, nil), tasks.NewHandler(nil, nil), placement.NewHandler(nil, nil), reports.NewHandler(nil, nil), crm.NewHandler(nil, nil), finance.NewHandler(nil, nil), kpi.NewHandler(nil, nil), mailapi.NewHandler(nil, nil), dashboard.NewHandler(nil, nil), files.NewHandler(nil, nil), tuition.NewHandler(nil, nil))(r)
	bad, count := unguarded(t, r)
	for _, b := range bad {
		t.Errorf("%s không gắn iam.Require / iam.RequireAny", b)
	}
	if count == 0 {
		t.Fatal("không tìm thấy route nghiệp vụ nào: test không còn kiểm gì")
	}
}

// TestGuardDetection: bản thân phép kiểm phải bắt được route thiếu quyền, kể cả gắn quyền theo nhóm.
func TestGuardDetection(t *testing.T) {
	ok := func(http.ResponseWriter, *http.Request) {}
	r := chi.NewRouter()
	r.Post("/open", ok)
	r.With(iam.Require("lead.create")).Post("/inline", ok)
	r.Group(func(r chi.Router) {
		r.Use(iam.RequireAny("lead.view", "lead.update"))
		r.Get("/group", ok)
	})
	bad, count := unguarded(t, r)
	if count != 3 || len(bad) != 1 || bad[0] != "POST /open" {
		t.Fatalf("count=%d bad=%v, muốn 3 route và chỉ POST /open thiếu quyền", count, bad)
	}
}

// TestFileDownloadNeedsSignIn: route tải file được miễn quyền cố định nhưng phải gắn iam.RequireSignedIn.
func TestFileDownloadNeedsSignIn(t *testing.T) {
	r := chi.NewRouter()
	files.NewHandler(nil, nil).Routes(r)
	want := reflect.ValueOf(iam.RequireSignedIn).Pointer()
	found := false
	_ = chi.Walk(r, func(method, route string, _ http.Handler, mws ...func(http.Handler) http.Handler) error {
		if route != files.DownloadRoute {
			return nil
		}
		for _, mw := range mws {
			if reflect.ValueOf(mw).Pointer() == want {
				found = true
			}
		}
		return nil
	})
	if !found {
		t.Fatalf("%s phải gắn iam.RequireSignedIn", files.DownloadRoute)
	}
}
