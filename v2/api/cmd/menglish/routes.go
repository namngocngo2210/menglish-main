package main

import (
	"github.com/go-chi/chi/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/placement"
)

// apiRoutes gắn mọi module vào /api/v1. Route nghiệp vụ phải gắn iam.Require / iam.RequireAny
// (TestEveryRouteHasPermission); chỉ các route đăng nhập trong publicRoutes được miễn.
func apiRoutes(auth *iam.Handler, place *placement.Handler) func(chi.Router) {
	return func(r chi.Router) {
		r.Use(iam.SameOrigin, auth.LoadSession)
		auth.Routes(r)
		auth.AdminRoutes(r)
		place.Routes(r)
		// Làm bài test đầu vào công khai: chỉ bằng token link mời, có giới hạn tần suất.
		place.PublicRoutes(r)
	}
}
