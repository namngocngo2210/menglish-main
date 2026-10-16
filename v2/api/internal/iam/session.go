package iam

import (
	"context"
	"crypto/rand"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"errors"
	"time"

	"github.com/google/uuid"
	"github.com/redis/go-redis/v9"
)

const (
	SessionTTL         = 8 * time.Hour
	RememberSessionTTL = 30 * 24 * time.Hour
	lastSeenEvery      = time.Minute
)

// Session lưu trong Redis ở khóa session:<sha256(token)>; token gốc chỉ nằm trong cookie của trình duyệt.
type Session struct {
	ID        string    `json:"-"` // sha256(token) dạng hex, dùng làm mã phiên khi liệt kê / đăng xuất từ xa
	UserID    uuid.UUID `json:"uid"`
	CSRF      string    `json:"csrf"`
	Remember  bool      `json:"remember"`
	CreatedAt time.Time `json:"created_at"`
	LastSeen  time.Time `json:"last_seen"`
	IP        string    `json:"ip"`
	UserAgent string    `json:"ua"`
}

func (s *Session) ttl() time.Duration {
	if s.Remember {
		return RememberSessionTTL
	}
	return SessionTTL
}

var errNoSession = errors.New("không có phiên")

type sessionStore struct{ rdb *redis.Client }

func sessionKey(id string) string          { return "session:" + id }
func userSessionsKey(uid uuid.UUID) string { return "user_sessions:" + uid.String() }
func hashToken(token string) string {
	s := sha256.Sum256([]byte(token))
	return hex.EncodeToString(s[:])
}
func randomToken() string {
	b := make([]byte, 32)
	mustRand(b)
	return base64.RawURLEncoding.EncodeToString(b)
}
func mustRand(b []byte) {
	if _, err := rand.Read(b); err != nil {
		panic(err)
	}
}

// create cấp token mới (luôn mới khi đăng nhập, chống cố định phiên) và trả về token cho cookie.
func (st sessionStore) create(ctx context.Context, s *Session) (token string, err error) {
	token = randomToken()
	s.ID = hashToken(token)
	s.CSRF = randomToken()
	now := time.Now()
	s.CreatedAt, s.LastSeen = now, now
	data, err := json.Marshal(s)
	if err != nil {
		return "", err
	}
	_, err = st.rdb.TxPipelined(ctx, func(p redis.Pipeliner) error {
		p.Set(ctx, sessionKey(s.ID), data, s.ttl())
		p.SAdd(ctx, userSessionsKey(s.UserID), s.ID)
		p.Expire(ctx, userSessionsKey(s.UserID), RememberSessionTTL)
		return nil
	})
	return token, err
}

// get đọc phiên theo token và gia hạn trượt; ghi lại last_seen tối đa mỗi phút một lần.
func (st sessionStore) get(ctx context.Context, token string) (*Session, error) {
	if token == "" {
		return nil, errNoSession
	}
	return st.getByID(ctx, hashToken(token), true)
}

func (st sessionStore) getByID(ctx context.Context, id string, touch bool) (*Session, error) {
	data, err := st.rdb.Get(ctx, sessionKey(id)).Bytes()
	if errors.Is(err, redis.Nil) {
		return nil, errNoSession
	}
	if err != nil {
		return nil, err
	}
	var s Session
	if err := json.Unmarshal(data, &s); err != nil {
		return nil, err
	}
	s.ID = id
	if !touch {
		return &s, nil
	}
	if now := time.Now(); now.Sub(s.LastSeen) >= lastSeenEvery {
		s.LastSeen = now
		if data, err := json.Marshal(&s); err == nil {
			st.rdb.Set(ctx, sessionKey(id), data, s.ttl())
		}
	} else {
		st.rdb.Expire(ctx, sessionKey(id), s.ttl())
	}
	return &s, nil
}

func (st sessionStore) delete(ctx context.Context, uid uuid.UUID, id string) error {
	_, err := st.rdb.TxPipelined(ctx, func(p redis.Pipeliner) error {
		p.Del(ctx, sessionKey(id))
		p.SRem(ctx, userSessionsKey(uid), id)
		return nil
	})
	return err
}

// list trả về các phiên còn sống của người dùng và dọn mã phiên đã hết hạn khỏi tập.
func (st sessionStore) list(ctx context.Context, uid uuid.UUID) ([]*Session, error) {
	ids, err := st.rdb.SMembers(ctx, userSessionsKey(uid)).Result()
	if err != nil {
		return nil, err
	}
	var out []*Session
	for _, id := range ids {
		s, err := st.getByID(ctx, id, false)
		if errors.Is(err, errNoSession) {
			st.rdb.SRem(ctx, userSessionsKey(uid), id)
			continue
		}
		if err != nil {
			return nil, err
		}
		out = append(out, s)
	}
	return out, nil
}

// revokeAll xóa mọi phiên của người dùng, trừ phiên keepID (để trống thì xóa hết).
func (st sessionStore) revokeAll(ctx context.Context, uid uuid.UUID, keepID string) error {
	ids, err := st.rdb.SMembers(ctx, userSessionsKey(uid)).Result()
	if err != nil {
		return err
	}
	for _, id := range ids {
		if id == keepID {
			continue
		}
		if err := st.delete(ctx, uid, id); err != nil {
			return err
		}
	}
	return nil
}
