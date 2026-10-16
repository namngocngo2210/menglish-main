package secretbox

import (
	"bytes"
	"crypto/rand"
	"encoding/base64"
	"testing"
)

func newBox(t *testing.T) *Box {
	key := make([]byte, 32)
	_, _ = rand.Read(key)
	b, err := FromBase64(base64.StdEncoding.EncodeToString(key))
	if err != nil {
		t.Fatal(err)
	}
	return b
}

func TestSealOpen(t *testing.T) {
	b := newBox(t)
	ct := b.Seal([]byte("bí mật"), []byte("user-1"))
	pt, err := b.Open(ct, []byte("user-1"))
	if err != nil || !bytes.Equal(pt, []byte("bí mật")) {
		t.Fatalf("got %q, %v", pt, err)
	}
	if _, err := b.Open(ct, []byte("user-2")); err == nil {
		t.Fatal("mở được bằng ngữ cảnh khác")
	}
	ct[len(ct)-1] ^= 1
	if _, err := b.Open(ct, []byte("user-1")); err == nil {
		t.Fatal("mở được bản mã đã bị sửa")
	}
}

func TestBadKey(t *testing.T) {
	if _, err := FromBase64("abc"); err == nil {
		t.Fatal("nhận khóa sai")
	}
	if _, err := FromBase64(base64.StdEncoding.EncodeToString(make([]byte, 16))); err == nil {
		t.Fatal("nhận khóa 16 byte")
	}
}
