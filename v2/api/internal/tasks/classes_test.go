package tasks

import (
	"testing"
	"time"
)

func TestSlotDueForAndSessionLabel(t *testing.T) {
	se := &ClassSession{Start: "18:00", End: "19:30", Date: time.Date(2026, 10, 19, 0, 0, 0, 0, time.UTC)}
	for cat, want := range map[string]string{"before": "18:00", "during": "19:30", "after": "20:30"} {
		if got := slotDueFor(cat, se); got != want {
			t.Errorf("%s: %s, cần %s", cat, got, want)
		}
	}
	if got := slotDueFor("after", &ClassSession{Start: "21:30", End: "23:15"}); got != "23:59" {
		t.Errorf("sau giờ học tối đa 23:59: %s", got)
	}
	if got := slotDueFor("before", nil); got != "14:00" {
		t.Errorf("không gắn buổi: %s", got)
	}
	se.LessonNo = 3
	if got := sessionLabel(*se); got != "Buổi 3 (19/10)" {
		t.Errorf("tên buổi: %s", got)
	}
	shift := "Ca 1"
	se.LessonNo, se.ShiftName = 0, &shift
	if got := sessionLabel(*se); got != "Ca 1 (19/10)" {
		t.Errorf("buổi không đánh số: %s", got)
	}
	se.ShiftName = nil
	if got := sessionLabel(*se); got != "Buổi học (19/10)" {
		t.Errorf("buổi không tên: %s", got)
	}
}
