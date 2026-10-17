package audit

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"strings"
	"sync"
	"sync/atomic"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/httpx"
)

// requestInfo là thông tin request gắn vào context để Record tự điền IP, trình duyệt, đường dẫn, mã request.
type requestInfo struct {
	ID        uuid.UUID
	IP        string
	UserAgent string
	Method    string
	Path      string
	logged    atomic.Bool // đã có dòng nhật ký trong request này
}

type ctxKey struct{}

func requestFrom(ctx context.Context) *requestInfo {
	info, _ := ctx.Value(ctxKey{}).(*requestInfo)
	return info
}

// WithRequest gắn thông tin request thủ công (lệnh chạy nền, test).
func WithRequest(ctx context.Context, ip, userAgent, method, path string) context.Context {
	id, _ := uuid.NewV7()
	return context.WithValue(ctx, ctxKey{}, &requestInfo{ID: id, IP: ip, UserAgent: userAgent, Method: method, Path: path})
}

// Phân hệ theo tiền tố đường dẫn API, dùng cho dòng nhật ký chung (AuditOperationMiddleware::resolveModule).
var (
	pathMu      sync.RWMutex
	pathModules = []struct{ prefix, module string }{
		{"/auth/", ModuleAccount},
		{"/users", ModuleUsers}, {"/roles", ModuleUsers}, {"/permissions", ModuleUsers},
		{"/branches", ModuleSystem}, {"/settings", ModuleSystem}, {"/system", ModuleSystem}, {"/catalogs", ModuleSystem},
		{"/crm", ModuleCRM}, {"/leads", ModuleCRM}, {"/customers", ModuleCRM},
		{"/tuition", ModuleTuition}, {"/receipts", ModuleTuition}, {"/finance", ModuleTuition},
		{"/students", ModuleStudents}, {"/classes", ModuleStudents},
		{"/placement", ModuleTests}, {"/trial", ModuleTests}, {"/surveys", ModuleTests},
		{"/syllabus", ModuleSyllabus},
		{"/payroll", ModulePayroll}, {"/attendance", ModulePayroll}, {"/timesheets", ModulePayroll},
		{"/tasks", ModuleTasks}, {"/work-tasks", ModuleTasks}, {"/reports", ModuleTasks},
		{"/tickets", ModuleTickets},
		{"/media", ModuleMedia},
	}
	// Request ghi không cần dòng nhật ký chung (đăng nhập / đăng xuất, đánh dấu đã đọc thông báo).
	skipPrefixes = []string{"/auth/login", "/auth/logout", "/notifications"}
)

// RegisterPath gắn phân hệ cho tiền tố đường dẫn API của một module (gọi lúc khởi động, trước khi phục vụ).
func RegisterPath(prefix, module string) {
	pathMu.Lock()
	defer pathMu.Unlock()
	pathModules = append([]struct{ prefix, module string }{{prefix, module}}, pathModules...)
}

func moduleForPath(path string) string {
	pathMu.RLock()
	defer pathMu.RUnlock()
	for _, p := range pathModules {
		if strings.HasPrefix(path, p.prefix) {
			return p.module
		}
	}
	return ModuleGeneral
}

// Middleware gắn thông tin request vào context. Request ghi (POST/PUT/PATCH/DELETE) của người đã đăng nhập, thành
// công (2xx) mà handler không gọi Record thì ghi một dòng chung (như AuditOperationMiddleware của Laravel): mỗi thao
// tác luôn có đúng một nguồn nhật ký. prefix là phần đầu đường dẫn bỏ đi khi đoán phân hệ (vd. "/api/v1");
// actor trả id người đang đăng nhập (iam.CurrentUserID).
func Middleware(db DB, log *slog.Logger, prefix string, actor func(context.Context) (uuid.UUID, bool)) func(http.Handler) http.Handler {
	return func(next http.Handler) http.Handler {
		return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
			id, _ := uuid.NewV7()
			info := &requestInfo{
				ID: id, IP: httpx.ClientIP(r), UserAgent: r.UserAgent(), Method: r.Method, Path: maskURL(r.URL),
			}
			ctx := context.WithValue(r.Context(), ctxKey{}, info)
			path := strings.TrimPrefix(r.URL.Path, prefix)
			if !isWrite(r.Method) || skipped(path) {
				next.ServeHTTP(w, r.WithContext(ctx))
				return
			}
			body := &capped{max: 64 << 10}
			if r.Body != nil {
				r.Body = struct {
					io.Reader
					io.Closer
				}{io.TeeReader(r.Body, body), r.Body}
			}
			rec := &statusWriter{ResponseWriter: w}
			r = r.WithContext(ctx)
			next.ServeHTTP(rec, r)
			if info.logged.Load() || rec.status() >= 300 {
				return
			}
			uid, ok := actor(r.Context())
			if !ok {
				return
			}
			if err := recordGeneric(ctx, db, uid, path, r.Method, rec.status(), body.buf); err != nil {
				// Lỗi ghi nhật ký không được làm hỏng thao tác đã xong.
				log.Error("audit fallback", "err", err, "path", r.URL.Path)
			}
		})
	}
}

func recordGeneric(ctx context.Context, db DB, uid uuid.UUID, path, method string, status int, raw []byte) error {
	module := moduleForPath(path)
	event := map[string]string{http.MethodPost: "Tạo mới / Thao tác", http.MethodPut: "Cập nhật", http.MethodPatch: "Cập nhật", http.MethodDelete: "Xóa"}[method]
	var payload map[string]any
	desc := fmt.Sprintf("%s dữ liệu thuộc phân hệ %s", event, module)
	// Đường dẫn đăng nhập / tài khoản (mật khẩu, mã 2FA): không lưu dữ liệu gửi lên.
	if !strings.HasPrefix(path, "/auth/") {
		_ = json.Unmarshal(raw, &payload)
	}
	for _, k := range []string{"name", "full_name", "title", "code"} {
		if s, ok := payload[k].(string); ok && strings.TrimSpace(s) != "" {
			desc = fmt.Sprintf("%s %s (%s)", event, module, strings.TrimSpace(s))
			break
		}
	}
	props := map[string]any{"status_code": status}
	if payload != nil {
		props["payload"] = payload
	}
	return Record(ctx, db, Entry{ActorID: &uid, Module: module, Event: event, Description: desc, Properties: props})
}

func isWrite(m string) bool {
	return m == http.MethodPost || m == http.MethodPut || m == http.MethodPatch || m == http.MethodDelete
}

func skipped(path string) bool {
	for _, p := range skipPrefixes {
		if strings.HasPrefix(path, p) {
			return true
		}
	}
	return false
}

// capped giữ tối đa max byte đầu của body (để ghi payload đã che vào nhật ký chung).
type capped struct {
	buf []byte
	max int
}

func (c *capped) Write(p []byte) (int, error) {
	if room := c.max - len(c.buf); room > 0 {
		c.buf = append(c.buf, p[:min(room, len(p))]...)
	}
	return len(p), nil
}

type statusWriter struct {
	http.ResponseWriter
	code int
}

func (s *statusWriter) WriteHeader(code int) {
	if s.code == 0 {
		s.code = code
	}
	s.ResponseWriter.WriteHeader(code)
}

func (s *statusWriter) Write(b []byte) (int, error) {
	if s.code == 0 {
		s.code = http.StatusOK
	}
	return s.ResponseWriter.Write(b)
}

func (s *statusWriter) status() int {
	if s.code == 0 {
		return http.StatusOK
	}
	return s.code
}
