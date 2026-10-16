package main

import (
	"github.com/go-chi/chi/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

// module là phần API của một phân hệ (students.Handler, inventory.Handler, tasks.Handler...).
type module interface{ Routes(chi.Router) }

// apiRoutes gắn mọi module vào /api/v1. Route nghiệp vụ phải gắn iam.Require / iam.RequireAny
// (TestEveryRouteHasPermission); chỉ các route đăng nhập trong publicRoutes được miễn.
func apiRoutes(auth *iam.Handler, modules ...module) func(chi.Router) {
	return func(r chi.Router) {
		r.Use(iam.SameOrigin, auth.LoadSession)
		auth.Routes(r)
		auth.AdminRoutes(r)
		for _, m := range modules {
			m.Routes(r)
		}
	}
}
