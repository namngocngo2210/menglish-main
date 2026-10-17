package filestore

import (
	"context"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"mime"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"time"
)

// Local: kho file trên đĩa. Tải về qua URL <URLPrefix>/<token>, token ký HMAC-SHA256 kèm hạn dùng.
type Local struct {
	dir    string
	prefix string
	key    []byte
	now    func() time.Time
}

// NewLocal mở (tạo nếu chưa có) thư mục kho. prefix là đường dẫn route ServeSigned, key là khóa ký URL.
func NewLocal(dir, prefix string, key []byte) (*Local, error) {
	if dir == "" {
		return nil, errors.New("thiếu FILES_DIR")
	}
	if len(key) < 32 {
		return nil, errors.New("filestore: khóa ký URL phải dài ít nhất 32 byte")
	}
	abs, err := filepath.Abs(dir)
	if err != nil {
		return nil, err
	}
	if err := os.MkdirAll(abs, 0o750); err != nil {
		return nil, fmt.Errorf("tạo thư mục kho file %s: %w", abs, err)
	}
	return &Local{dir: abs, prefix: strings.TrimRight(prefix, "/"), key: key, now: time.Now}, nil
}

func (l *Local) Driver() string { return "local" }

func (l *Local) path(key string) (string, error) {
	if !ValidKey(key) {
		return "", fmt.Errorf("filestore: khóa không hợp lệ %q", key)
	}
	return filepath.Join(l.dir, filepath.FromSlash(key)), nil
}

// Put ghi ra file tạm cùng thư mục rồi đổi tên (không bao giờ thấy file ghi dở).
func (l *Local) Put(_ context.Context, key string, r io.Reader, size int64, _ string) error {
	p, err := l.path(key)
	if err != nil {
		return err
	}
	if err := os.MkdirAll(filepath.Dir(p), 0o750); err != nil {
		return err
	}
	tmp, err := os.CreateTemp(filepath.Dir(p), ".up-*")
	if err != nil {
		return err
	}
	defer os.Remove(tmp.Name())
	n, err := io.Copy(tmp, r)
	if err == nil && size >= 0 && n != size {
		err = fmt.Errorf("filestore: ghi %d byte, cần %d", n, size)
	}
	if err == nil {
		err = tmp.Sync()
	}
	if cerr := tmp.Close(); err == nil {
		err = cerr
	}
	if err != nil {
		return err
	}
	return os.Rename(tmp.Name(), p)
}

func (l *Local) Open(_ context.Context, key string) (io.ReadCloser, error) {
	p, err := l.path(key)
	if err != nil {
		return nil, err
	}
	f, err := os.Open(p)
	if errors.Is(err, fs.ErrNotExist) {
		return nil, ErrNotFound
	}
	return f, err
}

func (l *Local) Delete(_ context.Context, key string) error {
	p, err := l.path(key)
	if err != nil {
		return err
	}
	if err := os.Remove(p); err != nil && !errors.Is(err, fs.ErrNotExist) {
		return err
	}
	return nil
}

// token: nội dung URL ký (JSON gọn rồi base64url).
type token struct {
	Key    string `json:"k"`
	Exp    int64  `json:"e"`
	Name   string `json:"n,omitempty"`
	Type   string `json:"t,omitempty"`
	Inline bool   `json:"i,omitempty"`
}

var b64 = base64.RawURLEncoding

func (l *Local) sign(payload string) string {
	m := hmac.New(sha256.New, l.key)
	m.Write([]byte(payload))
	return b64.EncodeToString(m.Sum(nil))
}

func (l *Local) SignedURL(_ context.Context, key string, o SignOptions) (string, error) {
	if !ValidKey(key) {
		return "", fmt.Errorf("filestore: khóa không hợp lệ %q", key)
	}
	ttl := o.TTL
	if ttl <= 0 {
		ttl = DefaultTTL
	}
	b, _ := json.Marshal(token{Key: key, Exp: l.now().Add(ttl).Unix(), Name: o.Filename, Type: o.ContentType, Inline: o.Inline})
	payload := b64.EncodeToString(b)
	return l.prefix + "/" + payload + "." + l.sign(payload), nil
}

// verify kiểm chữ ký (so sánh hằng thời gian) và hạn của token.
func (l *Local) verify(raw string) (token, bool) {
	payload, sig, ok := strings.Cut(raw, ".")
	if !ok || len(raw) > 4096 || !hmac.Equal([]byte(sig), []byte(l.sign(payload))) {
		return token{}, false
	}
	b, err := b64.DecodeString(payload)
	if err != nil {
		return token{}, false
	}
	var t token
	if json.Unmarshal(b, &t) != nil || !ValidKey(t.Key) || l.now().Unix() > t.Exp {
		return token{}, false
	}
	return t, true
}

// ServeSigned phục vụ file theo token trong URL (route công khai: token chính là quyền đọc, có hạn).
// Hỗ trợ Range (tua file nghe), không cho trình duyệt đoán loại, tài liệu luôn tải về.
func (l *Local) ServeSigned(w http.ResponseWriter, r *http.Request, raw string) {
	t, ok := l.verify(raw)
	if !ok {
		http.Error(w, "Link tải file không hợp lệ hoặc đã hết hạn.", http.StatusForbidden)
		return
	}
	p, err := l.path(t.Key)
	if err != nil {
		http.Error(w, "Không tìm thấy file.", http.StatusNotFound)
		return
	}
	f, err := os.Open(p)
	if err != nil {
		http.Error(w, "Không tìm thấy file.", http.StatusNotFound)
		return
	}
	defer f.Close()
	st, err := f.Stat()
	if err != nil || st.IsDir() {
		http.Error(w, "Không tìm thấy file.", http.StatusNotFound)
		return
	}
	h := w.Header()
	ct := t.Type
	if ct == "" {
		ct = "application/octet-stream"
	}
	h.Set("Content-Type", ct)
	h.Set("Content-Disposition", Disposition(t.Inline, t.Name))
	h.Set("X-Content-Type-Options", "nosniff")
	h.Set("Cache-Control", "private, max-age=300")
	h.Set("Referrer-Policy", "no-referrer")
	// File chỉ được nhúng trong chính ứng dụng (khung PDF của đề test); ngoài PDF thì chạy trong sandbox.
	// Chrome không hiện PDF trong sandbox nên PDF chỉ chặn nhúng từ trang khác.
	h.Set("X-Frame-Options", "SAMEORIGIN")
	if ct == "application/pdf" {
		h.Set("Content-Security-Policy", "frame-ancestors 'self'")
	} else {
		h.Set("Content-Security-Policy", "default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'; frame-ancestors 'self'; sandbox")
	}
	http.ServeContent(w, r, "", st.ModTime(), f)
}

// Disposition: header Content-Disposition với tên file tiếng Việt (RFC 6266: filename ASCII + filename*).
func Disposition(inline bool, name string) string {
	kind := "attachment"
	if inline {
		kind = "inline"
	}
	if name == "" {
		return kind
	}
	if v := mime.FormatMediaType(kind, map[string]string{"filename": name}); v != "" {
		return v
	}
	return kind
}
