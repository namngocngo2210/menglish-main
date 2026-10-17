// Package filestore lưu nội dung file người dùng tải lên (kho riêng tư) và ký URL tải ngắn hạn.
//
// Hai driver cùng một interface:
//   - Local: thư mục trên đĩa (dev, test, bản beta). API tự phục vụ file qua URL ký HMAC ngắn hạn (ServeSigned).
//   - S3: Cloudflare R2 / MinIO qua API S3 (production theo tài liệu thiết kế); URL ký trỏ thẳng tới bucket.
//
// Gói này chỉ biết "khóa" (tên đối tượng) và nội dung; thông tin file (chủ sở hữu, tên gốc, quyền đọc) nằm ở
// bảng core.files của gói internal/files. Phần đọc file từ request (ReadMultipart, Policy, nhận loại theo nội dung)
// ở upload.go, sniff.go.
package filestore

import (
	"context"
	"crypto/hmac"
	"crypto/rand"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"regexp"
	"strings"
	"time"
)

// ErrNotFound: không có đối tượng với khóa này.
var ErrNotFound = errors.New("filestore: không tìm thấy file")

// SignOptions: tùy chọn của URL tải ký sẵn.
type SignOptions struct {
	TTL         time.Duration // hạn của URL (0 = 5 phút)
	Filename    string        // tên file khi tải về (Content-Disposition)
	ContentType string        // Content-Type trả về
	Inline      bool          // true: mở ngay trên trình duyệt (ảnh, PDF, âm thanh); false: tải về (tài liệu)
}

// DefaultTTL: hạn mặc định của URL ký (tài liệu thiết kế: 5 phút).
const DefaultTTL = 5 * time.Minute

// Store: kho nội dung file. Khóa do NewKey sinh (ngẫu nhiên, không đoán được).
type Store interface {
	// Put ghi nội dung (size byte) vào khóa key.
	Put(ctx context.Context, key string, r io.Reader, size int64, contentType string) error
	// Open đọc nội dung; không có thì ErrNotFound.
	Open(ctx context.Context, key string) (io.ReadCloser, error)
	// Delete xóa hẳn nội dung (không có cũng không lỗi).
	Delete(ctx context.Context, key string) error
	// SignedURL: URL tải trực tiếp, hết hạn sau opts.TTL. Chỉ gọi sau khi đã kiểm quyền đọc.
	SignedURL(ctx context.Context, key string, opts SignOptions) (string, error)
	// Driver: "local" | "s3".
	Driver() string
}

var keyRe = regexp.MustCompile(`^[a-z0-9_-]+(/[a-z0-9_-]+)*\.[a-z0-9]{1,8}$`)

// ValidKey: khóa chỉ gồm chữ thường, số, "_", "-", "/" và một đuôi file; không có ".." hay đường dẫn tuyệt đối.
func ValidKey(key string) bool { return len(key) <= 200 && keyRe.MatchString(key) }

var (
	prefixRe = regexp.MustCompile(`[^a-z0-9_-]+`)
	extRe    = regexp.MustCompile(`^[a-z0-9]{1,8}$`)
)

// NewKey sinh khóa ngẫu nhiên: <prefix>/<năm>/<tháng>/<32 ký tự hex>.<đuôi>, vd. staff_contract/2026/10/9f…c1.pdf.
func NewKey(prefix, ext string, now time.Time) string {
	b := make([]byte, 16)
	if _, err := rand.Read(b); err != nil {
		panic(err)
	}
	prefix = strings.Trim(prefixRe.ReplaceAllString(strings.ToLower(prefix), "_"), "_")
	if prefix == "" {
		prefix = "file"
	}
	ext = strings.ToLower(strings.TrimPrefix(ext, "."))
	if !extRe.MatchString(ext) {
		ext = "bin"
	}
	return fmt.Sprintf("%s/%04d/%02d/%s.%s", prefix, now.Year(), int(now.Month()), hex.EncodeToString(b), ext)
}

// DeriveKey: khóa ký URL của driver local, suy ra từ APP_ENCRYPTION_KEY (không cần thêm bí mật mới).
func DeriveKey(encryptionKeyBase64 string) ([]byte, error) {
	raw, err := base64.StdEncoding.DecodeString(encryptionKeyBase64)
	if err != nil || len(raw) != 32 {
		return nil, errors.New("APP_ENCRYPTION_KEY phải là 32 byte base64")
	}
	m := hmac.New(sha256.New, raw)
	m.Write([]byte("menglish/filestore/signed-url/v1"))
	return m.Sum(nil), nil
}

// Config: cấu hình kho file (đọc từ env ở internal/config).
type Config struct {
	Driver string // local | s3
	Dir    string // driver local: thư mục gốc
	// Driver local: đường dẫn gốc của route phục vụ file ký sẵn, vd. "/api/v1/files/blob".
	URLPrefix string
	SignKey   []byte // driver local: khóa HMAC (DeriveKey)

	S3Endpoint  string // vd. <account>.r2.cloudflarestorage.com hoặc localhost:9000 (MinIO)
	S3Bucket    string
	S3AccessKey string
	S3SecretKey string
	S3Region    string // R2: "auto"
	S3UseSSL    bool
}

// New dựng kho theo cấu hình.
func New(c Config) (Store, error) {
	switch c.Driver {
	case "", "local":
		return NewLocal(c.Dir, c.URLPrefix, c.SignKey)
	case "s3":
		return NewS3(c)
	}
	return nil, fmt.Errorf("FILES_DRIVER không hợp lệ: %q (local | s3)", c.Driver)
}
