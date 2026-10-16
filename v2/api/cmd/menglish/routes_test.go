package main

import (
	"net/http"
	"reflect"
	"strings"
	"testing"

	"github.com/go-chi/chi/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/crm"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

// publicRoutes: route không cần quyền (đăng nhập và tự quản lý tài khoản của chính mình; đã có RequireAuth).
var publicRoutes = []string{"/auth/"}

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
	apiRoutes(iam.NewHandler(nil, nil), crm.NewHandler(nil, nil))(r)
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
