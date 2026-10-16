package classes

import (
	"fmt"
	"time"
)

// Vòng đời một lớp (ClassLifecycle): Tạo lớp → Cấu hình lịch → Xếp học viên → Khai giảng → Vận hành → Kết thúc,
// suy ra từ trạng thái lớp và sĩ số so với ngưỡng khai giảng. Danh sách lớp (cột "Việc tiếp theo") và thanh bước
// trên Trang lớp dùng chung để luôn nói cùng một điều.

const (
	StatusPendingSchedule = "pending_schedule"
	StatusUpcoming        = "upcoming"
	StatusActive          = "active"
	StatusCompleted       = "completed"
	StatusCancelled       = "cancelled"
)

// DefaultMinStudents: ngưỡng khai giảng mặc định.
const DefaultMinStudents = 6

// StatusMeta: nhãn + màu badge theo trạng thái lớp (thứ tự = thứ tự chip lọc trên Danh sách lớp).
type StatusMeta struct {
	Key   string `json:"key"`
	Label string `json:"label"`
	Color string `json:"color"` // warning | secondary | success | neutral | error
}

var Statuses = []StatusMeta{
	{StatusPendingSchedule, "Chờ lịch", "warning"},
	{StatusUpcoming, "Sắp khai giảng", "secondary"},
	{StatusActive, "Đang học", "success"},
	{StatusCompleted, "Đã kết thúc", "neutral"},
	{StatusCancelled, "Đã hủy", "error"},
}

func statusMeta(key string) StatusMeta {
	for _, s := range Statuses {
		if s.Key == key {
			return s
		}
	}
	return StatusMeta{key, key, "neutral"}
}

func validStatus(key string) bool { return statusMeta(key).Label != key }

// Tab: tab con của Trang lớp.
type Tab struct {
	Key   string `json:"key"`
	Label string `json:"label"`
}

var Tabs = []Tab{
	{"overview", "Tổng quan"},
	{"students", "Học viên"},
	{"schedule", "Lịch & buổi học"},
	{"attendance", "Điểm danh & báo cáo buổi"},
	{"academic", "Học thuật"},
	{"incidents", "Sự vụ"},
}

// Seat: sĩ số so với sức chứa và ngưỡng khai giảng (ClassModel::seatSummary).
type Seat struct {
	Occupied   int64  `json:"occupied"`
	Capacity   int32  `json:"capacity"`
	Left       *int64 `json:"left"` // nil = không giới hạn sĩ số
	Min        int32  `json:"min"`
	Needed     int64  `json:"needed"` // còn thiếu bao nhiêu học viên để khai giảng (lớp chưa khai giảng)
	NotStarted bool   `json:"not_started"`
}

func seatSummary(status string, start *time.Time, occupied int64, capacity, minStudents int32, today time.Time) Seat {
	notStarted := status == StatusPendingSchedule || status == StatusUpcoming || (start != nil && start.After(today))
	s := Seat{Occupied: occupied, Capacity: capacity, Min: minStudents, NotStarted: notStarted}
	if capacity > 0 {
		left := max(0, int64(capacity)-occupied)
		s.Left = &left
	}
	if notStarted {
		s.Needed = max(0, int64(minStudents)-occupied)
	}
	return s
}

// Step: một bước vòng đời với trạng thái done / current / todo.
type Step struct {
	Key   string  `json:"key"`
	Label string  `json:"label"`
	State string  `json:"state"`
	Hint  *string `json:"hint"`
	Tab   string  `json:"tab"`
}

func fmtDMY(t *time.Time) *string {
	if t == nil {
		return nil
	}
	s := t.Format("02/01/2006")
	return &s
}

func lifecycleSteps(status string, start, end *time.Time, seat Seat) []Step {
	launched := status == StatusActive || status == StatusCompleted
	scheduled := status != StatusPendingSchedule
	enough := launched || seat.Needed <= 0
	hint := fmt.Sprintf("%d/%d ngưỡng", seat.Occupied, seat.Min)
	type raw struct {
		Step
		done bool
	}
	steps := []raw{
		{Step{Key: "created", Label: "Tạo lớp", Tab: "overview"}, true},
		{Step{Key: "schedule", Label: "Cấu hình lịch", Tab: "schedule"}, scheduled},
		{Step{Key: "students", Label: "Xếp học viên", Hint: &hint, Tab: "students"}, enough && scheduled},
		{Step{Key: "launch", Label: "Khai giảng", Hint: fmtDMY(start), Tab: "overview"}, launched},
		{Step{Key: "running", Label: "Vận hành", Tab: "attendance"}, status == StatusCompleted},
		{Step{Key: "finished", Label: "Kết thúc", Hint: fmtDMY(end), Tab: "overview"}, status == StatusCompleted},
	}
	currentFound := status == StatusCancelled
	out := make([]Step, 0, len(steps))
	for _, s := range steps {
		switch {
		case s.done:
			s.State = "done"
		case currentFound:
			s.State = "todo"
		default:
			s.State = "current"
			currentFound = true
		}
		out = append(out, s.Step)
	}
	return out
}

// NextAction: việc cần làm tiếp theo cho lớp (cột "Việc tiếp theo" + nút chính trên Trang lớp).
type NextAction struct {
	Label string `json:"label"`
	Tone  string `json:"tone"` // error | warning | success | neutral | primary
	Tab   string `json:"tab"`
}

// nextAction; nextSession là ngày buổi tới gần nhất (nil = chưa có).
// TODO(academic): Big Test trong 14 ngày tới → "Big Test dd/mm" (tab Học thuật) khi có bảng Big Test.
func nextAction(status string, seat Seat, nextSession *time.Time) *NextAction {
	switch status {
	case StatusPendingSchedule:
		return &NextAction{"Cấu hình lịch", "error", "schedule"}
	case StatusUpcoming:
		if seat.Needed > 0 {
			return &NextAction{fmt.Sprintf("Thiếu %d HV để khai giảng", seat.Needed), "warning", "students"}
		}
		return &NextAction{"Đủ ngưỡng, sẵn sàng khai giảng", "success", "overview"}
	case StatusActive:
		if nextSession != nil {
			return &NextAction{"Buổi tới " + nextSession.Format("02/01"), "neutral", "schedule"}
		}
		return &NextAction{"Chưa có buổi sắp tới", "warning", "schedule"}
	}
	return nil
}
