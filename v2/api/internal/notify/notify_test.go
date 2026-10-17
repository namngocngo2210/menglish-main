package notify

import (
	"strings"
	"testing"
	"unicode/utf8"
)

func TestValidLink(t *testing.T) {
	for link, want := range map[string]bool{
		"/":                            true,
		"/crm/customers/1?x=1":         true,
		"//evil.example":               false,
		"https://evil.example":         false,
		"/a\\b":                        false,
		"/a\nb":                        false,
		"tickets/1":                    false,
		"/" + strings.Repeat("a", 600): false,
	} {
		if ValidLink(link) != want {
			t.Errorf("ValidLink(%q) = %v", link, !want)
		}
	}
}

func TestTypes(t *testing.T) {
	if Info("stale_lead_24h").Label != "Lead sót >24h" || Info("khong_co").Label != "Thông báo hệ thống" {
		t.Fatal("nhãn loại thông báo")
	}
	RegisterType("zz_test", TypeInfo{"Ă thử", "science", ToneInfo})
	if Info("zz_test").Icon != "science" {
		t.Fatal("RegisterType")
	}
	found := false
	for _, o := range Types() {
		found = found || o.Value == "zz_test"
	}
	if !found {
		t.Fatal("Types thiếu loại vừa khai báo")
	}
	if s := truncate(strings.Repeat("ă", 300), maxTitle); utf8.RuneCountInString(s) != maxTitle || !strings.HasSuffix(s, "…") {
		t.Fatalf("cắt tiêu đề: %d", utf8.RuneCountInString(s))
	}
}
