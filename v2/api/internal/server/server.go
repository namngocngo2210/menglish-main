// Package server dựng HTTP router của API. Module nghiệp vụ gắn route vào nhóm /api/v1.
package server

import (
	"context"
	"log/slog"
	"net/http"
	"time"

	"github.com/go-chi/chi/v5"
	"github.com/go-chi/chi/v5/middleware"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/httpx"
)

// Pinger là phụ thuộc cần sống để API phục vụ được (Postgres, Redis).
type Pinger interface {
	Ping(ctx context.Context) error
}

type PingFunc func(ctx context.Context) error

func (f PingFunc) Ping(ctx context.Context) error { return f(ctx) }

type Deps struct {
	Log     *slog.Logger
	Version string
	Env     string
	Checks  map[string]Pinger
	// API gắn middleware và route của các module vào nhóm /api/v1.
	API func(r chi.Router)
	// Root: route ngoài /api/v1 giữ đúng đường dẫn cũ cho dịch vụ bên ngoài (vd. webhook SePay
	// /hook/sepay-gateway/v1/add-payment). Không qua middleware phiên / SameOrigin của /api/v1.
	Root func(r chi.Router)
}

func New(d Deps) http.Handler {
	r := chi.NewRouter()
	r.Use(middleware.RequestID)
	r.Use(requestLog(d.Log))
	r.Use(middleware.Recoverer)
	r.Use(securityHeaders)

	// Kiểm tra sức khỏe cho Docker / dịch vụ báo uptime: /healthz chỉ báo tiến trình sống,
	// /readyz kiểm cả các phụ thuộc.
	r.Get("/healthz", func(w http.ResponseWriter, _ *http.Request) {
		writeJSON(w, http.StatusOK, map[string]string{"status": "ok"})
	})
	r.Get("/readyz", readyz(d.Checks))
	if d.Root != nil {
		r.Group(func(r chi.Router) {
			r.Use(middleware.Timeout(30 * time.Second))
			d.Root(r)
		})
	}

	r.Route("/api/v1", func(r chi.Router) {
		r.Use(middleware.Timeout(30 * time.Second))
		r.Get("/meta", func(w http.ResponseWriter, _ *http.Request) {
			writeJSON(w, http.StatusOK, map[string]string{"version": d.Version, "env": d.Env})
		})
		if d.API != nil {
			// Group riêng để module được thêm middleware của mình (chi cấm Use sau khi đã gắn route).
			r.Group(d.API)
		}
	})

	r.NotFound(func(w http.ResponseWriter, _ *http.Request) {
		writeJSON(w, http.StatusNotFound, map[string]string{"error": "not_found"})
	})
	return r
}

func readyz(checks map[string]Pinger) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		ctx, cancel := context.WithTimeout(r.Context(), 2*time.Second)
		defer cancel()
		status, result := http.StatusOK, map[string]string{}
		for name, c := range checks {
			if err := c.Ping(ctx); err != nil {
				status, result[name] = http.StatusServiceUnavailable, "down"
				continue
			}
			result[name] = "ok"
		}
		writeJSON(w, status, result)
	}
}

func securityHeaders(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		h := w.Header()
		h.Set("X-Content-Type-Options", "nosniff")
		h.Set("Referrer-Policy", "strict-origin-when-cross-origin")
		h.Set("Cache-Control", "no-store")
		next.ServeHTTP(w, r)
	})
}

func requestLog(log *slog.Logger) func(http.Handler) http.Handler {
	return func(next http.Handler) http.Handler {
		return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
			start := time.Now()
			ww := middleware.NewWrapResponseWriter(w, r.ProtoMajor)
			next.ServeHTTP(ww, r)
			if r.URL.Path == "/healthz" {
				return
			}
			log.Info("http",
				"method", r.Method,
				"path", r.URL.Path,
				"status", ww.Status(),
				"dur_ms", time.Since(start).Milliseconds(),
				"ip", httpx.ClientIP(r),
				"req_id", middleware.GetReqID(r.Context()),
			)
		})
	}
}

func writeJSON(w http.ResponseWriter, status int, v any) { httpx.JSON(w, status, v) }
