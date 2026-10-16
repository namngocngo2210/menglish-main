package main

import (
	"net/http"

	"github.com/go-chi/chi/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit/auditapi"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/notify/notifyapi"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/search"
)

// module là phần API của một phân hệ (students.Handler, inventory.Handler, tasks.Handler, placement.Handler...).
type module interface{ Routes(chi.Router) }

// publicModule: module có thêm route không cần đăng nhập (phải liệt kê trong publicRoutes của routes_test.go).
type publicModule interface{ PublicRoutes(chi.Router) }

// platform gom các phần nền tảng dùng chung: nhật ký vận hành, thông báo, tìm kiếm chung.
type platform struct {
	auditMW func(http.Handler) http.Handler // gắn thông tin request cho audit.Record (nil = bỏ qua)
	audit   *auditapi.Handler
	notify  *notifyapi.Handler
	search  *search.Handler
}

// apiRoutes gắn mọi module vào /api/v1. Route nghiệp vụ phải gắn iam.Require / iam.RequireAny
// (TestEveryRouteHasPermission); chỉ các route đăng nhập trong publicRoutes được miễn.
func apiRoutes(auth *iam.Handler, p platform, modules ...module) func(chi.Router) {
	return func(r chi.Router) {
		r.Use(iam.SameOrigin, auth.LoadSession)
		if p.auditMW != nil {
			r.Use(p.auditMW)
		}
		auth.Routes(r)
		auth.AdminRoutes(r)
		p.audit.Routes(r)
		p.notify.Routes(r)
		p.search.Routes(r)
		for _, m := range modules {
			m.Routes(r)
			// Route công khai của module (vd. làm bài test đầu vào bằng token link mời, có giới hạn tần suất).
			if pm, ok := m.(publicModule); ok {
				pm.PublicRoutes(r)
			}
		}
	}
}
