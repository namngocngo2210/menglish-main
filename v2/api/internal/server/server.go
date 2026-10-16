// Package server dựng HTTP router của API. Module nghiệp vụ gắn route vào nhóm /api/v1.
package server

import (
	"context"
	"encoding/json"
	"log/slog"
	"net/http"
	"time"

	"github.com/go-chi/chi/v5"
	"github.com/go-chi/chi/v5/middleware"
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
}

func New(d Deps) http.Handler {
	r := chi.NewRouter()
	r.Use(middleware.RequestID)
	r.Use(middleware.RealIP)
	r.Use(requestLog(d.Log))
	r.Use(middleware.Recoverer)
	r.Use(securityHeaders)

	// Kiểm tra sức khỏe cho Docker / dịch vụ báo uptime: /healthz chỉ báo tiến trình sống,
	// /readyz kiểm cả các phụ thuộc.
	r.Get("/healthz", func(w http.ResponseWriter, _ *http.Request) {
		writeJSON(w, http.StatusOK, map[string]string{"status": "ok"})
	})
	r.Get("/readyz", readyz(d.Checks))

	r.Route("/api/v1", func(r chi.Router) {
		r.Use(middleware.Timeout(30 * time.Second))
		r.Get("/meta", func(w http.ResponseWriter, _ *http.Request) {
			writeJSON(w, http.StatusOK, map[string]string{"version": d.Version, "env": d.Env})
		})
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
				"req_id", middleware.GetReqID(r.Context()),
			)
		})
	}
}

func writeJSON(w http.ResponseWriter, status int, v any) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(v)
}
