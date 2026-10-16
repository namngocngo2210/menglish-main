package geo

import (
	"slices"
	"testing"
)

func TestVariants(t *testing.T) {
	cases := map[string][]string{
		"Số 23 ngõ 209 Đội Cấn, Ba Đình, Hà Nội": {"Số 23 ngõ 209 Đội Cấn, Ba Đình, Hà Nội", "Đội Cấn, Ba Đình, Hà Nội", "Ba Đình, Hà Nội"},
		"Số 12, ngõ 34 Trần Duy Hưng, Cầu Giấy":  {"Số 12, ngõ 34 Trần Duy Hưng, Cầu Giấy", "Trần Duy Hưng, Cầu Giấy"},
		"25/3A hẻm 12 Lê Lợi, Quận 1":            {"25/3A hẻm 12 Lê Lợi, Quận 1", "Lê Lợi, Quận 1"},
		"Cầu Giấy, Hà Nội":                       {"Cầu Giấy, Hà Nội"},
		"Kiệt 5 Hùng Vương, Hải Châu, Đà Nẵng":   {"Kiệt 5 Hùng Vương, Hải Châu, Đà Nẵng", "Hùng Vương, Hải Châu, Đà Nẵng", "Hải Châu, Đà Nẵng"},
	}
	for in, want := range cases {
		if got := Variants(in); !slices.Equal(got, want) {
			t.Errorf("Variants(%q) = %q, muốn %q", in, got, want)
		}
	}
}
