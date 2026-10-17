package placement

import (
	"context"
	"net/http"
	"time"

	"github.com/redis/go-redis/v9"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/httpx"
)

// Giới hạn tần suất cho route công khai (không đăng nhập): đếm theo IP trong cửa sổ 1 phút.
const (
	rateWindow     = time.Minute
	openPerMinute  = 30
	submitPerMinut = 10
)

type rateLimiter struct{ rdb *redis.Client }

// allow tăng bộ đếm của bucket+ip; lỗi Redis thì cho qua (không chặn thí sinh vì Redis trục trặc).
func (l rateLimiter) allow(ctx context.Context, bucket, ip string, limit int64) (bool, error) {
	if l.rdb == nil {
		return true, nil
	}
	key := "placement:rl:" + bucket + ":" + ip
	var incr *redis.IntCmd
	_, err := l.rdb.TxPipelined(ctx, func(p redis.Pipeliner) error {
		incr = p.Incr(ctx, key)
		p.ExpireNX(ctx, key, rateWindow)
		return nil
	})
	if err != nil {
		return true, err
	}
	return incr.Val() <= limit, nil
}

func (h *Handler) limit(bucket string, n int64) func(http.Handler) http.Handler {
	return func(next http.Handler) http.Handler {
		return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
			ok, err := rateLimiter{h.rdb()}.allow(r.Context(), bucket, httpx.ClientIP(r), n)
			if err != nil {
				h.log.Warn("placement: rate limit lỗi Redis", "err", err)
			}
			if !ok {
				w.Header().Set("Retry-After", "60")
				httpx.Error(w, http.StatusTooManyRequests, "too_many_requests", "Bạn thao tác quá nhanh, vui lòng đợi 1 phút rồi thử lại.")
				return
			}
			next.ServeHTTP(w, r)
		})
	}
}
