package iam

import (
	"context"
	"time"

	"github.com/redis/go-redis/v9"
)

// Giới hạn đăng nhập sai (tài liệu thiết kế, mục Bảo mật): 5 lần mỗi tài khoản + IP và 30 lần mỗi IP trong 10 phút.
const (
	failWindow       = 10 * time.Minute
	maxFailsPerAcct  = 5
	maxFailsPerIP    = 30
	totpChallengeTTL = 5 * time.Minute
	maxTOTPAttempts  = 5
)

type limiter struct{ rdb *redis.Client }

func failIPKey(ip string) string         { return "login_fail:ip:" + ip }
func failAcctKey(acct, ip string) string { return "login_fail:acct:" + acct + ":" + ip }

func (l limiter) blocked(ctx context.Context, acct, ip string) (bool, error) {
	vals, err := l.rdb.MGet(ctx, failIPKey(ip), failAcctKey(acct, ip)).Result()
	if err != nil {
		return false, err
	}
	return atoi(vals[0]) >= maxFailsPerIP || atoi(vals[1]) >= maxFailsPerAcct, nil
}

func (l limiter) fail(ctx context.Context, acct, ip string) error {
	_, err := l.rdb.TxPipelined(ctx, func(p redis.Pipeliner) error {
		for _, k := range []string{failIPKey(ip), failAcctKey(acct, ip)} {
			p.Incr(ctx, k)
			p.ExpireNX(ctx, k, failWindow)
		}
		return nil
	})
	return err
}

func (l limiter) reset(ctx context.Context, acct, ip string) error {
	return l.rdb.Del(ctx, failAcctKey(acct, ip)).Err()
}

func atoi(v any) int {
	s, ok := v.(string)
	if !ok {
		return 0
	}
	n := 0
	for _, c := range s {
		if c < '0' || c > '9' {
			return 0
		}
		n = n*10 + int(c-'0')
	}
	return n
}
