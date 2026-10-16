package main

import (
	"net/http"

	"github.com/go-chi/chi/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit/auditapi"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/notify/notifyapi"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/search"
)

// platform gom các phần nền tảng dùng chung: nhật ký vận hành, thông báo, tìm kiếm chung.
type platform struct {
	auditMW func(http.Handler) http.Handler // gắn thông tin request cho audit.Record (nil = bỏ qua)
	audit   *auditapi.Handler
	notify  *notifyapi.Handler
	search  *search.Handler
}

// apiRoutes gắn mọi module vào /api/v1. Route nghiệp vụ phải gắn iam.Require / iam.RequireAny
// (TestEveryRouteHasPermission); chỉ các route đăng nhập trong publicRoutes được miễn.
func apiRoutes(auth *iam.Handler, p platform) func(chi.Router) {
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
	}
}
