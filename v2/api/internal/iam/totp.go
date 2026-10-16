package iam

import (
	"crypto/rand"
	"crypto/sha256"
	"encoding/base32"
	"strings"
	"time"

	"github.com/pquerna/otp"
	"github.com/pquerna/otp/totp"
)

const (
	totpIssuer        = "MEnglish"
	totpPeriod        = 30
	recoveryCodeCount = 10
)

var totpOpts = totp.ValidateOpts{Period: totpPeriod, Digits: otp.DigitsSix, Algorithm: otp.AlgorithmSHA1}

// newTOTPKey tạo khóa bí mật mới; trả về secret (base32) và URL otpauth:// để hiện mã QR.
func newTOTPKey(account string) (secret, url string, err error) {
	key, err := totp.Generate(totp.GenerateOpts{Issuer: totpIssuer, AccountName: account, Period: totpPeriod})
	if err != nil {
		return "", "", err
	}
	return key.Secret(), key.URL(), nil
}

// matchTOTP kiểm mã 6 số, cho lệch đồng hồ ±1 bước (30 giây). Trả về số bước khớp để chặn dùng lại cùng một mã.
func matchTOTP(secret, code string, now time.Time) (step int64, ok bool) {
	code = strings.TrimSpace(code)
	if len(code) != 6 {
		return 0, false
	}
	for _, d := range []int64{0, -1, 1} {
		t := now.Add(time.Duration(d*totpPeriod) * time.Second)
		want, err := totp.GenerateCodeCustom(secret, t, totpOpts)
		if err == nil && want == code {
			return t.Unix() / totpPeriod, true
		}
	}
	return 0, false
}

var recoveryEncoding = base32.NewEncoding("abcdefghijkmnpqrstuvwxyz23456789").WithPadding(base32.NoPadding)

// newRecoveryCodes tạo 10 mã dự phòng dạng "xxxxx-xxxxx", kèm bản băm để lưu DB.
func newRecoveryCodes() (codes []string, hashes [][]byte) {
	for range recoveryCodeCount {
		b := make([]byte, 7)
		if _, err := rand.Read(b); err != nil {
			panic(err)
		}
		s := recoveryEncoding.EncodeToString(b)[:10]
		codes = append(codes, s[:5]+"-"+s[5:])
		hashes = append(hashes, hashRecoveryCode(s))
	}
	return codes, hashes
}

func hashRecoveryCode(code string) []byte {
	code = strings.ToLower(strings.NewReplacer("-", "", " ", "").Replace(code))
	sum := sha256.Sum256([]byte(code))
	return sum[:]
}

func looksLikeRecoveryCode(code string) bool {
	return len(strings.NewReplacer("-", "", " ", "").Replace(code)) == 10
}
