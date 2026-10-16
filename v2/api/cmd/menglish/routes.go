package main

import (
	"github.com/go-chi/chi/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

// apiRoutes gắn mọi module vào /api/v1. Route nghiệp vụ phải gắn iam.Require / iam.RequireAny
// (TestEveryRouteHasPermission); chỉ các route đăng nhập trong publicRoutes được miễn.
func apiRoutes(auth *iam.Handler) func(chi.Router) {
	return func(r chi.Router) {
		r.Use(iam.SameOrigin, auth.LoadSession)
		auth.Routes(r)
		auth.AdminRoutes(r)
	}
}
