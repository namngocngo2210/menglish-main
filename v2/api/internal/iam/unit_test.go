package iam

import (
	"context"
	"strings"
	"testing"
	"time"

	"github.com/pquerna/otp/totp"
)

func TestHashAndVerifyPassword(t *testing.T) {
	ctx := context.Background()
	h, err := HashPassword(ctx, "mat-khau-dai-du-10")
	if err != nil {
		t.Fatal(err)
	}
	if !strings.HasPrefix(h, "$argon2id$v=19$m=65536,t=3,p=2$") {
		t.Fatalf("sai định dạng: %s", h)
	}
	if ok, err := VerifyPassword(ctx, "mat-khau-dai-du-10", h); !ok || err != nil {
		t.Fatalf("mật khẩu đúng bị từ chối: %v", err)
	}
	if ok, _ := VerifyPassword(ctx, "mat-khau-sai-roi", h); ok {
		t.Fatal("mật khẩu sai được chấp nhận")
	}
	if _, err := VerifyPassword(ctx, "x", "$bcrypt$abc"); err == nil {
		t.Fatal("nhận chuỗi băm lạ")
	}
}

func TestPasswordPolicy(t *testing.T) {
	cases := []struct {
		pw   string
		ids  []string
		okay bool
	}{
		{"ngan", nil, false},
		{"password123", nil, false},
		{"Password123", nil, false}, // so không phân biệt hoa thường
		{"matkhau123", nil, false},
		{"xyzMES001abc", []string{"MES001"}, false},
		{"Trung-tam-tieng-anh-2026", []string{"MES001"}, true},
		{"mười ký tự có dấu", nil, true},
		{strings.Repeat("a", 129), nil, false},
	}
	for _, c := range cases {
		if got := CheckPasswordPolicy(c.pw, c.ids...) == ""; got != c.okay {
			t.Errorf("%q: chấp nhận=%v, muốn %v", c.pw, got, c.okay)
		}
	}
}

func TestRandomPasswordPassesPolicy(t *testing.T) {
	for range 20 {
		p := RandomPassword()
		if len(p) != 16 || CheckPasswordPolicy(p) != "" {
			t.Fatalf("mật khẩu tạm không hợp lệ: %q", p)
		}
	}
}

func TestNormalizePhone(t *testing.T) {
	cases := map[string]string{
		"0912 345 678": "0912345678",
		"+84912345678": "0912345678",
		"84912345678":  "0912345678",
		"091.234.5678": "0912345678",
		"MES001":       "",
		"12345":        "",
		"a@b.vn":       "",
	}
	for in, want := range cases {
		if got := NormalizePhone(in); got != want {
			t.Errorf("NormalizePhone(%q) = %q, muốn %q", in, got, want)
		}
	}
}

func TestMatchTOTP(t *testing.T) {
	secret, _, err := newTOTPKey("MES001")
	if err != nil {
		t.Fatal(err)
	}
	now := time.Now()
	code, _ := totp.GenerateCode(secret, now)
	step, ok := matchTOTP(secret, code, now)
	if !ok || step != now.Unix()/totpPeriod {
		t.Fatalf("mã đúng không khớp: %v %d", ok, step)
	}
	old, _ := totp.GenerateCode(secret, now.Add(-30*time.Second))
	if _, ok := matchTOTP(secret, old, now); !ok {
		t.Fatal("không cho lệch 1 bước")
	}
	tooOld, _ := totp.GenerateCode(secret, now.Add(-90*time.Second))
	if _, ok := matchTOTP(secret, tooOld, now); ok && tooOld != code {
		t.Fatal("nhận mã quá cũ")
	}
	if _, ok := matchTOTP(secret, "12345", now); ok {
		t.Fatal("nhận mã 5 số")
	}
}

func TestRecoveryCodes(t *testing.T) {
	codes, hashes := newRecoveryCodes()
	if len(codes) != 10 || len(hashes) != 10 {
		t.Fatalf("có %d mã", len(codes))
	}
	seen := map[string]bool{}
	for i, c := range codes {
		if len(c) != 11 || c[5] != '-' || !looksLikeRecoveryCode(c) || seen[c] {
			t.Fatalf("mã dự phòng sai: %q", c)
		}
		seen[c] = true
		// Người dùng gõ hoa, bỏ gạch vẫn khớp
		if string(hashRecoveryCode(strings.ToUpper(strings.ReplaceAll(c, "-", "")))) != string(hashes[i]) {
			t.Fatalf("băm không ổn định với %q", c)
		}
	}
}
