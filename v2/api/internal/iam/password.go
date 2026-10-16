package iam

import (
	"bufio"
	"context"
	"crypto/rand"
	"crypto/subtle"
	_ "embed"
	"encoding/base64"
	"errors"
	"fmt"
	"math/big"
	"strings"
	"sync"
	"unicode/utf8"

	"golang.org/x/crypto/argon2"
)

// Tham số argon2id theo tài liệu thiết kế: 64 MB, 3 vòng, 2 luồng.
const (
	argonMemory  = 64 * 1024
	argonTime    = 3
	argonThreads = 2
	argonKeyLen  = 32
	argonSaltLen = 16

	MinPasswordLen = 10
	MaxPasswordLen = 128
)

// Mỗi lần băm cần 64 MB RAM: giới hạn số lần băm chạy cùng lúc để API (giới hạn 256 MB) không bị kill.
var hashSlots = make(chan struct{}, 2)

func acquireHashSlot(ctx context.Context) error {
	select {
	case hashSlots <- struct{}{}:
		return nil
	case <-ctx.Done():
		return ctx.Err()
	}
}

func releaseHashSlot() { <-hashSlots }

// HashPassword trả về chuỗi dạng PHC: $argon2id$v=19$m=65536,t=3,p=2$<salt>$<hash>.
func HashPassword(ctx context.Context, password string) (string, error) {
	salt := make([]byte, argonSaltLen)
	if _, err := rand.Read(salt); err != nil {
		return "", err
	}
	if err := acquireHashSlot(ctx); err != nil {
		return "", err
	}
	defer releaseHashSlot()
	key := argon2.IDKey([]byte(password), salt, argonTime, argonMemory, argonThreads, argonKeyLen)
	b64 := base64.RawStdEncoding
	return fmt.Sprintf("$argon2id$v=%d$m=%d,t=%d,p=%d$%s$%s",
		argon2.Version, argonMemory, argonTime, argonThreads, b64.EncodeToString(salt), b64.EncodeToString(key)), nil
}

// VerifyPassword so mật khẩu với chuỗi băm (thời gian so sánh không phụ thuộc nội dung).
func VerifyPassword(ctx context.Context, password, encoded string) (bool, error) {
	var version int
	var memory uint32
	var time uint32
	var threads uint8
	parts := strings.Split(encoded, "$")
	if len(parts) != 6 || parts[1] != "argon2id" {
		return false, errors.New("chuỗi băm mật khẩu không hợp lệ")
	}
	if _, err := fmt.Sscanf(parts[2], "v=%d", &version); err != nil || version != argon2.Version {
		return false, errors.New("phiên bản argon2 không hỗ trợ")
	}
	if _, err := fmt.Sscanf(parts[3], "m=%d,t=%d,p=%d", &memory, &time, &threads); err != nil {
		return false, errors.New("tham số argon2 không hợp lệ")
	}
	b64 := base64.RawStdEncoding
	salt, err := b64.DecodeString(parts[4])
	if err != nil {
		return false, err
	}
	want, err := b64.DecodeString(parts[5])
	if err != nil {
		return false, err
	}
	if err := acquireHashSlot(ctx); err != nil {
		return false, err
	}
	defer releaseHashSlot()
	got := argon2.IDKey([]byte(password), salt, time, memory, threads, uint32(len(want)))
	return subtle.ConstantTimeCompare(got, want) == 1, nil
}

// dummyHash dùng khi không tìm thấy tài khoản: vẫn tốn đúng thời gian băm để không lộ tài khoản có tồn tại hay không.
var dummyHash = sync.OnceValue(func() string {
	h, err := HashPassword(context.Background(), "khong-co-tai-khoan-nay")
	if err != nil {
		panic(err)
	}
	return h
})

//go:embed common_passwords.txt
var commonPasswordsTxt string

var commonPasswords = func() map[string]struct{} {
	m := make(map[string]struct{}, 2500)
	sc := bufio.NewScanner(strings.NewReader(commonPasswordsTxt))
	for sc.Scan() {
		line := strings.TrimSpace(sc.Text())
		if line != "" && !strings.HasPrefix(line, "#") {
			m[line] = struct{}{}
		}
	}
	return m
}()

// CheckPasswordPolicy trả về thông báo lỗi tiếng Việt, hoặc "" nếu mật khẩu dùng được.
// identifiers là mã, email, SĐT của chính người dùng: mật khẩu không được trùng các giá trị này.
func CheckPasswordPolicy(password string, identifiers ...string) string {
	n := utf8.RuneCountInString(password)
	switch {
	case n < MinPasswordLen:
		return fmt.Sprintf("Mật khẩu cần ít nhất %d ký tự.", MinPasswordLen)
	case n > MaxPasswordLen:
		return fmt.Sprintf("Mật khẩu dài tối đa %d ký tự.", MaxPasswordLen)
	}
	lower := strings.ToLower(password)
	if _, ok := commonPasswords[lower]; ok {
		return "Mật khẩu này quá phổ biến, hãy chọn mật khẩu khác."
	}
	for _, id := range identifiers {
		if id != "" && strings.Contains(lower, strings.ToLower(id)) {
			return "Mật khẩu không được chứa mã, email hoặc số điện thoại của bạn."
		}
	}
	return ""
}

// RandomPassword tạo mật khẩu tạm 16 ký tự cho tài khoản mới hoặc khi cấp lại.
func RandomPassword() string {
	const alphabet = "abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789"
	b := make([]byte, 16)
	for i := range b {
		n, err := rand.Int(rand.Reader, big.NewInt(int64(len(alphabet))))
		if err != nil {
			panic(err)
		}
		b[i] = alphabet[n.Int64()]
	}
	return string(b)
}
