package main

import (
	"github.com/go-chi/chi/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

// module là phần API của một phân hệ (students.Handler, inventory.Handler, tasks.Handler, placement.Handler...).
type module interface{ Routes(chi.Router) }

// publicModule: module có thêm route không cần đăng nhập (phải liệt kê trong publicRoutes của routes_test.go).
type publicModule interface{ PublicRoutes(chi.Router) }

// apiRoutes gắn mọi module vào /api/v1. Route nghiệp vụ phải gắn iam.Require / iam.RequireAny
// (TestEveryRouteHasPermission); chỉ các route đăng nhập trong publicRoutes được miễn.
func apiRoutes(auth *iam.Handler, modules ...module) func(chi.Router) {
	return func(r chi.Router) {
		r.Use(iam.SameOrigin, auth.LoadSession)
		auth.Routes(r)
		auth.AdminRoutes(r)
		for _, m := range modules {
			m.Routes(r)
			// Route công khai của module (vd. làm bài test đầu vào bằng token link mời, có giới hạn tần suất).
			if p, ok := m.(publicModule); ok {
				p.PublicRoutes(r)
			}
		}
	}
}
