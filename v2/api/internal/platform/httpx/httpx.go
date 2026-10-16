// Package httpx gom các hàm HTTP dùng chung: trả JSON, đọc JSON, lỗi chuẩn.
package httpx

import (
	"encoding/json"
	"errors"
	"io"
	"log/slog"
	"net/http"
	"strings"
)

func JSON(w http.ResponseWriter, status int, v any) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(v)
}

// ErrorBody là dạng lỗi chung của API: {"error": "<mã>", "message": "<tiếng Việt>"}.
type ErrorBody struct {
	Error   string `json:"error"`
	Message string `json:"message,omitempty"`
}

func Error(w http.ResponseWriter, status int, code, message string) {
	JSON(w, status, ErrorBody{Error: code, Message: message})
}

// InternalError ghi log chi tiết và chỉ trả thông báo chung cho người dùng.
func InternalError(w http.ResponseWriter, r *http.Request, log *slog.Logger, err error) {
	log.Error("internal error", "err", err, "method", r.Method, "path", r.URL.Path)
	Error(w, http.StatusInternalServerError, "internal", "Có lỗi xảy ra, vui lòng thử lại.")
}

const maxJSONBody = 64 << 10

// Decode đọc body JSON vào dst (giới hạn 64 KB, không nhận trường lạ). Lỗi thì đã trả 400.
func Decode(w http.ResponseWriter, r *http.Request, dst any) bool {
	if ct := r.Header.Get("Content-Type"); !strings.HasPrefix(ct, "application/json") {
		Error(w, http.StatusUnsupportedMediaType, "unsupported_media_type", "Cần gửi JSON.")
		return false
	}
	dec := json.NewDecoder(http.MaxBytesReader(w, r.Body, maxJSONBody))
	dec.DisallowUnknownFields()
	if err := dec.Decode(dst); err != nil {
		Error(w, http.StatusBadRequest, "bad_request", "Dữ liệu gửi lên không hợp lệ.")
		return false
	}
	if err := dec.Decode(&struct{}{}); !errors.Is(err, io.EOF) {
		Error(w, http.StatusBadRequest, "bad_request", "Dữ liệu gửi lên không hợp lệ.")
		return false
	}
	return true
}

// ClientIP lấy IP thật của người dùng. API chỉ nhận request qua Caddy nên tin các header proxy:
// CF-Connecting-IP (khi bật proxy Cloudflare), rồi IP đầu tiên trong X-Forwarded-For, cuối cùng là RemoteAddr.
func ClientIP(r *http.Request) string {
	if ip := strings.TrimSpace(r.Header.Get("CF-Connecting-IP")); ip != "" {
		return ip
	}
	if xff := r.Header.Get("X-Forwarded-For"); xff != "" {
		first, _, _ := strings.Cut(xff, ",")
		if ip := strings.TrimSpace(first); ip != "" {
			return ip
		}
	}
	host := r.RemoteAddr
	if i := strings.LastIndexByte(host, ':'); i > 0 && !strings.HasSuffix(host, "]") {
		host = host[:i]
	}
	return strings.Trim(host, "[]")
}
