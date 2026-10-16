package audit

import (
	"net/url"
	"strings"
)

// MaskValue thay giá trị nhạy cảm trong nhật ký (SensitiveData::MASK).
const MaskValue = "***"

// Khóa bị bỏ hẳn và các mảnh tên khóa coi là nhạy cảm (như App\Support\SensitiveData).
var (
	droppedKeys        = map[string]bool{"_token": true, "_method": true}
	sensitiveFragments = []string{
		"password", "passwd", "secret", "token", "api_key", "apikey", "private_key",
		"access_key", "signature", "authorization", "credential", "id_card", "cccd", "totp", "recovery",
	}
)

// IsSensitiveKey: tên khóa (không phân biệt hoa thường) chứa mảnh nhạy cảm, hoặc đúng là "key".
func IsSensitiveKey(key string) bool {
	k := strings.ToLower(key)
	if k == "key" {
		return true
	}
	for _, f := range sensitiveFragments {
		if strings.Contains(k, f) {
			return true
		}
	}
	return false
}

const maxValueLen = 500

// Mask che giá trị của khóa nhạy cảm (đệ quy qua map / mảng lồng nhau) và cắt chuỗi quá dài.
func Mask(data map[string]any) map[string]any {
	if data == nil {
		return map[string]any{}
	}
	out := make(map[string]any, len(data))
	for k, v := range data {
		if droppedKeys[k] {
			continue
		}
		if IsSensitiveKey(k) {
			out[k] = maskScalar(v)
			continue
		}
		out[k] = maskAny(v)
	}
	return out
}

func maskAny(v any) any {
	switch t := v.(type) {
	case map[string]any:
		return Mask(t)
	case []any:
		list := make([]any, len(t))
		for i, x := range t {
			list[i] = maskAny(x)
		}
		return list
	case string:
		return limit(t, maxValueLen)
	}
	return v
}

func maskScalar(v any) any {
	if v == nil || v == "" {
		return v
	}
	return MaskValue
}

func maskChanges(c Changes) Changes {
	out := make(Changes, len(c))
	for k, ch := range c {
		if IsSensitiveKey(k) {
			out[k] = Change{Old: maskScalar(ch.Old), New: maskScalar(ch.New)}
			continue
		}
		out[k] = Change{Old: maskAny(ch.Old), New: maskAny(ch.New)}
	}
	return out
}

// maskURL che tham số nhạy cảm trong query string (SensitiveData::maskUrl).
func maskURL(u *url.URL) string {
	if u.RawQuery == "" {
		return u.Path
	}
	q := u.Query()
	for k := range q {
		if IsSensitiveKey(k) {
			q.Set(k, MaskValue)
		}
	}
	return u.Path + "?" + q.Encode()
}
