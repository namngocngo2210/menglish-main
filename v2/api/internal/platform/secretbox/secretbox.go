// Package secretbox mã hóa dữ liệu nhạy cảm lưu trong DB (khóa TOTP, sau này CCCD, số tài khoản)
// bằng AES-256-GCM. Bản mã có 1 byte phiên bản khóa ở đầu để sau này xoay khóa mà vẫn đọc được bản cũ.
package secretbox

import (
	"crypto/aes"
	"crypto/cipher"
	"crypto/rand"
	"encoding/base64"
	"errors"
	"fmt"
)

const keyVersion byte = 1

type Box struct{ aead cipher.AEAD }

// FromBase64 nhận khóa 32 byte mã base64 (tạo bằng: openssl rand -base64 32).
func FromBase64(s string) (*Box, error) {
	key, err := base64.StdEncoding.DecodeString(s)
	if err != nil {
		return nil, fmt.Errorf("APP_ENCRYPTION_KEY không phải base64: %w", err)
	}
	if len(key) != 32 {
		return nil, fmt.Errorf("APP_ENCRYPTION_KEY phải dài 32 byte, đang là %d", len(key))
	}
	block, err := aes.NewCipher(key)
	if err != nil {
		return nil, err
	}
	aead, err := cipher.NewGCM(block)
	if err != nil {
		return nil, err
	}
	return &Box{aead: aead}, nil
}

// Seal mã hóa plaintext; associated gắn bản mã với ngữ cảnh (vd. id người dùng) để không chép sang hàng khác được.
func (b *Box) Seal(plaintext, associated []byte) []byte {
	nonce := make([]byte, b.aead.NonceSize())
	if _, err := rand.Read(nonce); err != nil {
		panic(err)
	}
	out := append([]byte{keyVersion}, nonce...)
	return b.aead.Seal(out, nonce, plaintext, associated)
}

func (b *Box) Open(ciphertext, associated []byte) ([]byte, error) {
	n := b.aead.NonceSize()
	if len(ciphertext) < 1+n || ciphertext[0] != keyVersion {
		return nil, errors.New("secretbox: bản mã không hợp lệ")
	}
	return b.aead.Open(nil, ciphertext[1:1+n], ciphertext[1+n:], associated)
}
