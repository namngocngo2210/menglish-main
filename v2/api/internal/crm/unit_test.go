package crm

import (
	"strings"
	"testing"
	"time"

	"github.com/google/uuid"
)

func TestPhone(t *testing.T) {
	ok := []string{"0912345678", "0912 345 678", "0912.345.678", "+84 912 345 678", "84912345678", "02412345678", "(+84) 912-345-678"}
	for _, p := range ok {
		if !ValidPhone(p) {
			t.Errorf("%q phải hợp lệ", p)
		}
	}
	bad := []string{"", "012345678", "0112345678", "091234567", "09123456789", "0912a45678", "1234567890"}
	for _, p := range bad {
		if ValidPhone(p) {
			t.Errorf("%q phải không hợp lệ", p)
		}
	}
	if got := NormalizePhone("+84 912.345.678"); got != "0912345678" {
		t.Fatalf("chuẩn hóa SĐT: %q", got)
	}
}

func TestLeadCode(t *testing.T) {
	a, _ := uuid.NewV7()
	time.Sleep(2 * time.Millisecond)
	b, _ := uuid.NewV7()
	ca, cb := leadCode(a), leadCode(b)
	if len(ca) != 29 || !strings.HasPrefix(ca, "KH-") || ca >= cb {
		t.Fatalf("mã khách phải dạng KH-<26 ký tự> và tăng dần theo thời gian: %s %s", ca, cb)
	}
	if s := ShortCode(ca); s != "KH-"+ca[23:] {
		t.Fatalf("mã ngắn: %s", s)
	}
	if ShortCode("KH-123") != "KH-123" {
		t.Fatal("mã cũ giữ nguyên")
	}
	// Mã toàn 0 → toàn ký tự "0"
	if leadCode(uuid.Nil) != "KH-"+strings.Repeat("0", 26) {
		t.Fatal("mã của uuid rỗng")
	}
}

func TestContactSLA(t *testing.T) {
	now := time.Date(2026, 10, 16, 10, 0, 0, 0, vnZone)
	created := now.Add(-20 * time.Hour)
	s := contactSLA(StageNew, created, nil, nil, now)
	if s.Kind != "first" || s.State != "due_soon" || s.Remaining != "Còn 4 giờ" {
		t.Fatalf("khách mới 20 giờ chưa liên hệ: %+v", s)
	}
	s = contactSLA(StageNew, now.Add(-25*time.Hour), nil, nil, now)
	if s.State != "overdue" || s.Remaining != "Quá hạn 1 giờ" {
		t.Fatalf("quá hạn liên hệ lần đầu: %+v", s)
	}
	care := now.Add(-time.Hour)
	s = contactSLA(StageConsulting, created, &care, nil, now)
	if s.Kind != "follow_up" || s.State != "on_time" || !s.Deadline.Equal(care.Add(72*time.Hour)) {
		t.Fatalf("hạn chăm sóc tiếp theo: %+v", s)
	}
	// Lịch hẹn trong 14 ngày dời hạn
	appt := now.Add(10 * 24 * time.Hour)
	s = contactSLA(StageConsulting, created, &care, &appt, now)
	if s.Kind != "appointment" || !s.Deadline.Equal(appt) {
		t.Fatalf("lịch hẹn liên hệ: %+v", s)
	}
	// Lịch hẹn quá 14 ngày không dời được hạn
	far := now.Add(20 * 24 * time.Hour)
	if s = contactSLA(StageConsulting, created, &care, &far, now); s.Kind != "follow_up" {
		t.Fatalf("lịch hẹn quá xa: %+v", s)
	}
	if contactSLA(StageWon, created, nil, nil, now) != nil || contactSLA(StageLost, created, nil, nil, now) != nil {
		t.Fatal("khách đã chốt / thất bại không có SLA")
	}
}

func TestRemainingLabel(t *testing.T) {
	now := time.Date(2026, 10, 16, 10, 0, 0, 0, vnZone)
	cases := map[time.Duration]string{
		30 * time.Second:                "Còn dưới 1 phút",
		2*time.Hour + 14*time.Minute:    "Còn 2 giờ 14 phút",
		-(27*time.Hour + 5*time.Minute): "Quá hạn 1 ngày 3 giờ",
		48 * time.Hour:                  "Còn 2 ngày",
	}
	for d, want := range cases {
		if got := remainingLabel(now.Add(d), now); got != want {
			t.Errorf("%v: %q, muốn %q", d, got, want)
		}
	}
}

func TestParseDob(t *testing.T) {
	cases := map[string]string{
		"15/08/2015": "2015-08-15", "5-8-2015": "2015-08-05", "2015-08-15": "2015-08-15", "42231": "2015-08-15", "": "",
	}
	for in, want := range cases {
		got, ok := parseDob(in)
		if !ok || got != want {
			t.Errorf("parseDob(%q) = %q %v, muốn %q", in, got, ok, want)
		}
	}
	for _, in := range []string{"31/02/2015", "abc", "123", "01/01/1800"} {
		if _, ok := parseDob(in); ok {
			t.Errorf("parseDob(%q) phải lỗi", in)
		}
	}
}

func TestRowDataRestoresLeadingZero(t *testing.T) {
	d := rowData([]string{"name", "phone", "parent_phone"}, []string{" Nguyễn An ", "912345678", "2412345678"})
	if d.Name != "Nguyễn An" || d.Phone != "0912345678" || d.ParentPhone != "02412345678" {
		t.Fatalf("dòng nhập: %+v", d)
	}
}

func TestCalculateDiscount(t *testing.T) {
	limit := int64(500000)
	if got := CalculateDiscount("percent", 10, nil, 3_000_000); got != 300000 {
		t.Fatalf("10%%: %v", got)
	}
	if got := CalculateDiscount("percent", 30, &limit, 3_000_000); got != 500000 {
		t.Fatalf("chặn trần: %v", got)
	}
	if got := CalculateDiscount("fixed", 5_000_000, nil, 3_000_000); got != 3_000_000 {
		t.Fatalf("không giảm quá học phí: %v", got)
	}
	if promotionPrefix("Ưu đãi hè 2026") != "UUDA" {
		t.Fatalf("tiền tố mã ưu đãi: %s", promotionPrefix("Ưu đãi hè 2026"))
	}
}

func TestReportPeriod(t *testing.T) {
	now := time.Date(2026, 10, 16, 15, 0, 0, 0, vnZone) // thứ Sáu
	p, s, e, ps, pe := reportPeriod("", now, nil, nil)
	if p != "last_30_days" || !s.Equal(time.Date(2026, 9, 17, 0, 0, 0, 0, vnZone)) || e.Before(now) || !pe.Equal(s.Add(-time.Second)) ||
		!ps.Equal(s.AddDate(0, 0, -30)) {
		t.Fatalf("30 ngày: %s %v %v %v %v", p, s, e, ps, pe)
	}
	_, s, e, _, _ = reportPeriod("last_week", now, nil, nil)
	if !s.Equal(time.Date(2026, 10, 5, 0, 0, 0, 0, vnZone)) || e.Weekday() != time.Sunday {
		t.Fatalf("tuần trước: %v → %v", s, e)
	}
	from, to := time.Date(2026, 10, 10, 0, 0, 0, 0, vnZone), time.Date(2026, 10, 1, 0, 0, 0, 0, vnZone)
	_, s, e, ps, _ = reportPeriod("custom", now, &from, &to)
	if !s.Equal(to) || e.Day() != 10 || !ps.Equal(to.AddDate(0, 0, -10)) {
		t.Fatalf("tùy chọn đảo ngày: %v %v %v", s, e, ps)
	}
}

func TestFormatMoney(t *testing.T) {
	if formatMoney(1500000) != "1.500.000 đ" || formatMoney(0) != "0 đ" {
		t.Fatal(formatMoney(1500000))
	}
}

func TestStageRules(t *testing.T) {
	if nextStage(StageNew) != StageConsulting || nextStage(StageWon) != "" || nextStage(StageLost) != "" {
		t.Fatal("bước kế tiếp")
	}
	if forwardBlockReason(StageTestScheduled, nil, nil) == "" || forwardBlockReason(StageTested, nil, ptr("")) == "" {
		t.Fatal("bước cần dữ liệu thật")
	}
	at := time.Now()
	if forwardBlockReason(StageTestScheduled, &at, nil) != "" {
		t.Fatal("có lịch hẹn thì được chuyển")
	}
}
