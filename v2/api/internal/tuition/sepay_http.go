package tuition

import (
	"io"
	"net/http"
	"strings"
	"sync"
	"time"

	"github.com/go-chi/chi/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/httpx"
)

// RootRoutes: route ngoài /api/v1 giữ đúng đường dẫn Laravel cho SePay (đã khai trên cổng SePay):
// POST /hook/sepay-gateway/v1/add-payment và /api/sepay/webhook (công khai, xác thực HMAC / API key trong service,
// throttle:120,1) và GET /api/sepay/transactions (đăng nhập + tuition.view). session: middleware phiên của /api/v1.
func (h *Handler) RootRoutes(session ...func(http.Handler) http.Handler) func(chi.Router) {
	return func(r chi.Router) {
		limit := newHookLimiter(120, time.Minute)
		r.With(limit.middleware).Post("/hook/sepay-gateway/v1/add-payment", h.sepayWebhook)
		r.With(limit.middleware).Post("/api/sepay/webhook", h.sepayWebhook)
		r.Group(func(r chi.Router) {
			r.Use(session...)
			r.With(iam.Require("tuition.view")).Get("/api/sepay/transactions", h.sepayTransactions)
		})
	}
}

func (h *Handler) sepayWebhook(w http.ResponseWriter, r *http.Request) {
	body, err := io.ReadAll(http.MaxBytesReader(w, r.Body, 1<<20))
	if err != nil {
		httpx.JSON(w, http.StatusRequestEntityTooLarge, map[string]any{"success": false, "message": "Dữ liệu webhook quá lớn."})
		return
	}
	out, err := h.svc.HandleWebhook(r.Context(), WebhookRequest{Body: body, ContentType: r.Header.Get("Content-Type"), Header: r.Header.Get})
	if err != nil {
		// Lỗi hệ thống: trả 500 để SePay gửi lại (đã rollback, chưa ghi giao dịch).
		h.log.Error("sepay webhook", "err", err)
		httpx.JSON(w, http.StatusInternalServerError, map[string]any{"success": false, "message": "Lỗi hệ thống khi xử lý webhook SePay."})
		return
	}
	httpx.JSON(w, out.Status, out.Body)
}

// sepayTransactions: 20 giao dịch gần nhất dạng {success, data} như getRecentTransactions.
func (h *Handler) sepayTransactions(w http.ResponseWriter, r *http.Request) {
	rows, enabled, err := h.svc.RecentTransactions(r.Context(), 20)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	if !enabled {
		httpx.JSON(w, http.StatusServiceUnavailable, map[string]any{"success": false, "message": "SePay webhook hiện đang tắt trên hệ thống.", "data": []BankTxn{}})
		return
	}
	httpx.JSON(w, http.StatusOK, map[string]any{"success": true, "data": rows})
}

func (h *Handler) sepayPage(w http.ResponseWriter, r *http.Request) {
	scheme := "http"
	if r.TLS != nil {
		scheme = "https"
	}
	if p := r.Header.Get("X-Forwarded-Proto"); p != "" {
		scheme = strings.TrimSpace(strings.Split(p, ",")[0])
	}
	host := r.Host
	if x := r.Header.Get("X-Forwarded-Host"); x != "" {
		host = strings.TrimSpace(strings.Split(x, ",")[0])
	}
	out, err := h.svc.SepayPage(r.Context(), scheme, host)
	h.reply(w, r, out, err)
}

func (h *Handler) updateSepayConfig(w http.ResponseWriter, r *http.Request) {
	var in SepayInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	out, err := h.svc.UpdateSepayConfig(r.Context(), actorOf(r), in)
	h.reply(w, r, out, err)
}

// hookLimiter: giới hạn số webhook mỗi IP trong một cửa sổ (throttle:120,1 của Laravel), giữ trong bộ nhớ tiến trình.
type hookLimiter struct {
	mu     sync.Mutex
	limit  int
	window time.Duration
	start  time.Time
	hits   map[string]int
}

func newHookLimiter(limit int, window time.Duration) *hookLimiter {
	return &hookLimiter{limit: limit, window: window, hits: map[string]int{}}
}

func (l *hookLimiter) allow(ip string, now time.Time) bool {
	l.mu.Lock()
	defer l.mu.Unlock()
	if now.Sub(l.start) >= l.window {
		l.start, l.hits = now, map[string]int{}
	}
	l.hits[ip]++
	return l.hits[ip] <= l.limit
}

func (l *hookLimiter) middleware(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if !l.allow(httpx.ClientIP(r), time.Now()) {
			w.Header().Set("Retry-After", "60")
			httpx.JSON(w, http.StatusTooManyRequests, map[string]any{"success": false, "message": "Too Many Attempts."})
			return
		}
		next.ServeHTTP(w, r)
	})
}
