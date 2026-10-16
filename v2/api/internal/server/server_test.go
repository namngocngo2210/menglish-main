package server

import (
	"context"
	"encoding/json"
	"errors"
	"io"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"testing"
)

func newTestServer(checks map[string]Pinger) http.Handler {
	return New(Deps{
		Log:     slog.New(slog.NewTextHandler(io.Discard, nil)),
		Version: "test",
		Env:     "dev",
		Checks:  checks,
	})
}

func get(t *testing.T, h http.Handler, path string) (*httptest.ResponseRecorder, map[string]string) {
	t.Helper()
	rec := httptest.NewRecorder()
	h.ServeHTTP(rec, httptest.NewRequest(http.MethodGet, path, nil))
	var body map[string]string
	if err := json.Unmarshal(rec.Body.Bytes(), &body); err != nil {
		t.Fatalf("%s: body không phải JSON: %q", path, rec.Body.String())
	}
	return rec, body
}

func TestHealthz(t *testing.T) {
	rec, body := get(t, newTestServer(nil), "/healthz")
	if rec.Code != http.StatusOK || body["status"] != "ok" {
		t.Fatalf("got %d %v", rec.Code, body)
	}
	if rec.Header().Get("X-Content-Type-Options") != "nosniff" {
		t.Fatal("thiếu header bảo mật")
	}
}

func TestReadyz(t *testing.T) {
	ok := PingFunc(func(context.Context) error { return nil })
	down := PingFunc(func(context.Context) error { return errors.New("boom") })

	rec, body := get(t, newTestServer(map[string]Pinger{"postgres": ok, "redis": ok}), "/readyz")
	if rec.Code != http.StatusOK || body["postgres"] != "ok" || body["redis"] != "ok" {
		t.Fatalf("got %d %v", rec.Code, body)
	}

	rec, body = get(t, newTestServer(map[string]Pinger{"postgres": ok, "redis": down}), "/readyz")
	if rec.Code != http.StatusServiceUnavailable || body["redis"] != "down" {
		t.Fatalf("got %d %v", rec.Code, body)
	}
}

func TestMetaAndNotFound(t *testing.T) {
	h := newTestServer(nil)
	rec, body := get(t, h, "/api/v1/meta")
	if rec.Code != http.StatusOK || body["version"] != "test" {
		t.Fatalf("got %d %v", rec.Code, body)
	}
	rec, body = get(t, h, "/api/v1/khong-co")
	if rec.Code != http.StatusNotFound || body["error"] != "not_found" {
		t.Fatalf("got %d %v", rec.Code, body)
	}
}
