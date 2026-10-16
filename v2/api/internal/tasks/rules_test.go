package tasks

import (
	"slices"
	"testing"
	"time"

	"github.com/google/uuid"
)

func TestAllowedTransitions(t *testing.T) {
	creator, assignee, admin, other := uuid.New(), uuid.New(), uuid.New(), uuid.New()
	cases := []struct {
		name    string
		status  string
		me      uuid.UUID
		isAdmin bool
		want    []string
	}{
		{"người làm việc mới", StatusNew, assignee, false, []string{StatusInProgress, StatusBlocked, StatusPendingConfirmation}},
		{"người giao việc mới chỉ hủy", StatusNew, creator, false, []string{StatusCanceled}},
		{"Admin việc đang làm: hoàn thành, hủy", StatusInProgress, admin, true, []string{StatusCompleted, StatusCanceled}},
		{"người làm không tự hoàn thành", StatusInProgress, assignee, false, []string{StatusBlocked, StatusPendingConfirmation}},
		{"bị chặn: người làm chuyển lại trước hủy", StatusBlocked, assignee, false, []string{StatusInProgress, HandBack}},
		{"bị chặn: người giao nhận lại hoặc hủy", StatusBlocked, creator, false, []string{HandBack, StatusCanceled}},
		{"chờ xác nhận: Admin hoàn thành hoặc trả về", StatusPendingConfirmation, admin, true, []string{StatusCompleted, StatusInProgress}},
		{"chờ xác nhận: người giao không duyệt", StatusPendingConfirmation, creator, false, []string{}},
		{"quá hạn: người làm", StatusOverdue, assignee, false, []string{StatusInProgress, StatusPendingConfirmation}},
		{"hoàn thành: không còn bước", StatusCompleted, admin, true, []string{}},
		{"người ngoài", StatusNew, other, false, []string{}},
	}
	for _, c := range cases {
		got := allowedTransitions(c.status, creator, &assignee, c.me, c.isAdmin)
		if !slices.Equal(got, c.want) {
			t.Errorf("%s: nhận %v, muốn %v", c.name, got, c.want)
		}
	}
	// Admin tự làm việc của mình: không tự xác nhận hoàn thành
	if got := allowedTransitions(StatusPendingConfirmation, creator, &admin, admin, true); slices.Contains(got, StatusCompleted) {
		t.Errorf("Admin tự duyệt việc của mình: %v", got)
	}
	// Người giao tự làm việc bị chặn của mình: không có "chuyển lại"
	if got := allowedTransitions(StatusBlocked, creator, &creator, creator, false); slices.Contains(got, HandBack) {
		t.Errorf("người giao = người làm vẫn có chuyển lại: %v", got)
	}
}

func TestTransitionLabels(t *testing.T) {
	got := transitionOptions(StatusPendingConfirmation, []string{StatusCompleted, StatusInProgress}, false)
	if got[1].Label != "Trả về làm tiếp" {
		t.Fatalf("nhãn trả về: %v", got)
	}
	if transitionOptions(StatusBlocked, []string{HandBack}, true)[0].Label != "Nhận lại việc" {
		t.Fatal("người giao: Nhận lại việc")
	}
	if transitionOptions(StatusBlocked, []string{HandBack}, false)[0].Label != "Chuyển lại cho người giao" {
		t.Fatal("người làm: Chuyển lại cho người giao")
	}
}

func TestLateHoursAndDue(t *testing.T) {
	day := time.Date(2026, 10, 16, 0, 0, 0, 0, time.UTC)
	clock := pgTimeOf("18:00")
	at := func(h, m int) time.Time { return time.Date(2026, 10, 16, h, m, 0, 0, vnZone) }
	if lateHours(nil, &day, clock, at(18, 0)) != 0 {
		t.Fatal("đúng hạn chưa trễ")
	}
	if lateHours(nil, &day, clock, at(18, 1)) != 1 || lateHours(nil, &day, clock, at(20, 30)) != 3 {
		t.Fatal("trễ làm tròn lên theo giờ")
	}
	kind := KindTADaily
	if lateHours(&kind, &day, clock, at(23, 0)) != 0 {
		t.Fatal("nhiệm vụ hằng ngày của trợ giảng không bao giờ trễ")
	}
	// Không có giờ hạn → 23:59
	var none = pgTimeOf("")
	if lateHours(nil, &day, none, at(23, 59)) != 0 || lateHours(nil, &day, none, at(23, 59).Add(2*time.Minute)) != 1 {
		t.Fatal("không giờ hạn thì hạn là 23:59")
	}
	if *formatClock(pgTimeOf("07:05")) != "07:05" {
		t.Fatal("định dạng giờ")
	}
}

func TestNextOccurrence(t *testing.T) {
	d := func(y int, m time.Month, day int) time.Time { return time.Date(y, m, day, 0, 0, 0, 0, time.UTC) }
	daily, monthly := "daily", "monthly"
	if !nextOccurrence(d(2026, 10, 31), &daily).Equal(d(2026, 11, 1)) {
		t.Fatal("hàng ngày")
	}
	if !nextOccurrence(d(2026, 10, 16), nil).Equal(d(2026, 10, 23)) {
		t.Fatal("mặc định hàng tuần")
	}
	if !nextOccurrence(d(2026, 1, 31), &monthly).Equal(d(2026, 2, 28)) {
		t.Fatal("hàng tháng không tràn sang tháng sau")
	}
}

func TestLateAssignment(t *testing.T) {
	now := time.Date(2026, 10, 16, 15, 31, 0, 0, vnZone)
	today := time.Date(2026, 10, 16, 0, 0, 0, 0, time.UTC)
	if !isLateAssignment(today, now) || isLateAssignment(today, now.Add(-2*time.Minute)) {
		t.Fatal("mốc 15:30 trong ngày")
	}
	if !isLateAssignment(today.AddDate(0, 0, -1), now.Add(-8*time.Hour)) || isLateAssignment(today.AddDate(0, 0, 1), now) {
		t.Fatal("ngày đã qua luôn trễ, ngày mai không trễ")
	}
}

func TestTypeLabelAndLinks(t *testing.T) {
	w := "weekly"
	if typeLabel("recurring", &w) != "Lặp đi lặp lại (Hàng tuần)" || typeLabel("one_time", nil) != "Phát sinh" {
		t.Fatal("nhãn loại công việc")
	}
	note := "Xem https://a.vn/x và https://a.vn/x, http://b.vn"
	if got := extractLinks(&note); !slices.Equal(got, []string{"https://a.vn/x", "http://b.vn"}) {
		t.Fatalf("liên kết: %v", got)
	}
}
