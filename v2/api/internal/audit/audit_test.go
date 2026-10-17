package audit

import (
	"net/url"
	"strings"
	"testing"
)

func TestDiff(t *testing.T) {
	name := "Hà Nội"
	got := Diff(
		map[string]any{"name": "HN", "phone": nil, "address": &name, "gone": 1},
		map[string]any{"name": "Hà Nội", "phone": (*string)(nil), "address": "Hà Nội", "new": true},
	)
	if len(got) != 3 || got["name"].Old != "HN" || got["gone"].New != nil || got["new"].Old != nil {
		t.Fatalf("diff: %#v", got)
	}
	if _, ok := got["phone"]; ok {
		t.Fatal("nil và con trỏ nil phải coi như nhau")
	}
	if _, ok := got["address"]; ok {
		t.Fatal("*string và string cùng giá trị phải coi như nhau")
	}
	if created := Diff(nil, map[string]any{"code": "A"}); created["code"].Old != nil || created["code"].New != "A" {
		t.Fatalf("tạo mới: %#v", created)
	}
}

func TestMask(t *testing.T) {
	c := maskChanges(Changes{"password_hash": {Old: "a", New: "b"}, "totp_secret": {Old: nil, New: "x"}, "name": {Old: "A", New: strings.Repeat("x", 600)}})
	if c["password_hash"].Old != MaskValue || c["totp_secret"].Old != nil || c["totp_secret"].New != MaskValue {
		t.Fatalf("che trường nhạy cảm: %#v", c)
	}
	if s := c["name"].New.(string); len([]rune(s)) != maxValueLen {
		t.Fatalf("cắt chuỗi dài: %d", len([]rune(s)))
	}
	u, _ := url.Parse("/api/v1/x?token=abc&q=1")
	if got := maskURL(u); got != "/api/v1/x?q=1&token=%2A%2A%2A" {
		t.Fatalf("che URL: %s", got)
	}
	if !IsSensitiveKey("Api_Key") || !IsSensitiveKey("key") || IsSensitiveKey("name") {
		t.Fatal("nhận diện khóa nhạy cảm")
	}
	if EventLabel("deleted") != "Xóa" || EventLabel("") != "Thao tác" || EventLabel("Tạo mới / Thao tác") != "Tạo mới / Thao tác" {
		t.Fatal("nhãn loại thao tác")
	}
}
