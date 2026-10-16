package reports

import (
	"encoding/json"
	"slices"
	"testing"
	"time"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

func vn(y int, m time.Month, d, h, min int) time.Time {
	return time.Date(y, m, d, h, min, 0, 0, vnZone)
}

func TestPeriods(t *testing.T) {
	if k := weekKey(vn(2026, 10, 16, 23, 0)); k != "2026-W42" {
		t.Fatalf("weekKey = %s", k)
	}
	// 00:30 giờ VN thứ Hai là Chủ nhật theo UTC: vẫn tính tuần mới.
	if k := weekKey(vn(2026, 10, 19, 0, 30).UTC()); k != "2026-W43" {
		t.Fatalf("weekKey múi giờ = %s", k)
	}
	from, to, ok := weekRange("2026-W42")
	if !ok || dateKey(from) != "2026-10-12" || dateKey(to) != "2026-10-18" {
		t.Fatalf("weekRange = %v %v %v", from, to, ok)
	}
	if _, _, ok := weekRange("2025-W53"); ok {
		t.Fatal("2025 không có tuần 53")
	}
	if _, _, ok := weekRange("2026-W53"); !ok {
		t.Fatal("2026 (bắt đầu thứ Năm) có tuần 53")
	}
	if _, _, ok := weekRange("2026-W5"); ok {
		t.Fatal("khóa tuần sai dạng")
	}
	if l := weekLabel("2026-W42"); l != "Tuần 42 (12/10 – 18/10/2026)" {
		t.Fatalf("weekLabel = %s", l)
	}
	if l := monthLabel("2026-10"); l != "Tháng 10/2026" {
		t.Fatalf("monthLabel = %s", l)
	}
	if l := quarterLabel("2026-Q4"); l != "Quý 4/2026 (tháng 10 – tháng 12)" {
		t.Fatalf("quarterLabel = %s", l)
	}
	if m := quarterMonths("2026-Q3"); !slices.Equal(m, []string{"2026-07", "2026-08", "2026-09"}) {
		t.Fatalf("quarterMonths = %v", m)
	}
	if l := dateLabelVI(vn(2026, 10, 16, 8, 0)); l != "Thứ sáu, 16 tháng 10, 2026" {
		t.Fatalf("dateLabelVI = %s", l)
	}
	if l := shortDayLabel(vn(2026, 10, 18, 8, 0)); l != "CN 18/10" {
		t.Fatalf("shortDayLabel = %s", l)
	}
	opts := weekOptions(vn(2026, 10, 16, 8, 0))
	if len(opts) != 9 || opts[0].Label != "Tuần này · Tuần 42 (12/10 – 18/10/2026)" || opts[1].Value != "2026-W41" {
		t.Fatalf("weekOptions = %v", opts[:2])
	}
	if q := quarterOptions(vn(2026, 2, 1, 8, 0)); q[1].Value != "2025-Q4" || len(q) != 5 {
		t.Fatalf("quarterOptions = %v", q)
	}
}

func TestMonthlyDue(t *testing.T) {
	// Tháng 10/2026: Chủ nhật cuối là 25/10.
	if d := monthlyDueDate(vn(2026, 10, 3, 8, 0)); dateKey(d) != "2026-10-25" {
		t.Fatalf("monthlyDueDate = %s", dateKey(d))
	}
	// Tháng 5/2026 kết thúc Chủ nhật 31/5.
	if d := monthlyDueDate(vn(2026, 5, 3, 8, 0)); dateKey(d) != "2026-05-31" {
		t.Fatalf("monthlyDueDate tháng 5 = %s", dateKey(d))
	}
	cases := map[int]string{22: "due_minus_3", 24: "due_minus_1", 25: "due", 23: "", 26: ""}
	for day, want := range cases {
		if got := monthlyReminderKind(vn(2026, 10, day, 8, 0)); got != want {
			t.Errorf("ngày %d: %q, muốn %q", day, got, want)
		}
	}
}

func TestLateDaily(t *testing.T) {
	date := vn(2026, 10, 15, 0, 0)
	onTime, late := vn(2026, 10, 16, 8, 59), vn(2026, 10, 16, 9, 1)
	if isLate("daily", &onTime, late, date) {
		t.Fatal("nộp lần đầu trước 09:00 hôm sau không trễ (dù sửa sau)")
	}
	if !isLate("daily", &late, late, date) {
		t.Fatal("nộp sau 09:00 hôm sau là trễ")
	}
	if isLate("weekly", &late, late, date) {
		t.Fatal("chỉ báo cáo ngày có hạn trễ")
	}
	if dailyDeadline(date).Format("15:04 02/01") != "09:00 16/10" {
		t.Fatalf("deadline = %v", dailyDeadline(date))
	}
}

func TestStaffType(t *testing.T) {
	cases := []struct {
		roles   []string
		cadence string
		primary string
		kinds   []string
	}{
		{[]string{iam.RoleAcademicStaff}, "daily", KindDailyOps, []string{KindDailyOps, KindWeeklyKpi}},
		{[]string{iam.RoleAcademicLead}, "weekly", KindAcademicWeekly,
			[]string{KindDailyOps, KindAcademicWeekly, KindAcademicMonthly, KindAcademicQuarterly}},
		{[]string{iam.RoleTeacherParttime}, "monthly", KindTeacherMonthly, []string{KindTeacherMonthly}},
		{[]string{iam.RoleAssistant}, "monthly", "", nil},
		{[]string{iam.RoleManager}, "daily", "", nil},
	}
	for _, c := range cases {
		if got := reportCadence(c.roles); got != c.cadence {
			t.Errorf("%v cadence = %s", c.roles, got)
		}
		if got := primaryStructuredKind(c.roles); got != c.primary {
			t.Errorf("%v primary = %s", c.roles, got)
		}
		if got := structuredKinds(c.roles); !slices.Equal(got, c.kinds) {
			t.Errorf("%v kinds = %v", c.roles, got)
		}
	}
	if dailyAudience([]string{iam.RoleAcademicLead}) != AudienceAcademic || dailyAudience([]string{iam.RoleAcademicLead, iam.RoleAcademicStaff}) != AudienceOperations {
		t.Fatal("dailyAudience")
	}
	// Một tab thì không hiện thanh tab; trợ giảng chỉ có báo cáo chữ.
	if len(tabs([]string{iam.RoleTeacherFulltime})) != 0 || len(tabs([]string{iam.RoleAssistant})) != 0 {
		t.Fatal("một tab thì không hiện")
	}
	if tb := tabs([]string{iam.RoleAcademicStaff}); len(tb) != 2 || tb[0].Kind != KindDailyOps {
		t.Fatalf("tabs học vụ = %v", tb)
	}
	if tb := tabs([]string{iam.RoleAssistant, iam.RoleTeacherParttime}); len(tb) != 0 {
		t.Fatalf("tabs = %v", tb)
	}
}

func TestPreview(t *testing.T) {
	kind := KindDailyOps
	data, _ := json.Marshal(map[string]any{"auto": map[string]any{}, "notes": map[string]string{"media": "Đăng 2 bài", "operations": "Lớp A1   vắng"}})
	if p := preview(&kind, data, nil); p == nil || *p != "Vận hành lớp học: Lớp A1 vắng · Truyền thông: Đăng 2 bài" {
		t.Fatalf("preview = %v", *p)
	}
	data, _ = json.Marshal(map[string]any{"auto": map[string]any{}})
	if p := preview(&kind, data, nil); *p != "Không có ghi chú (chỉ số liệu hệ thống)." {
		t.Fatalf("preview trống = %v", *p)
	}
	content := "Nội dung chữ"
	if p := preview(nil, []byte(`{}`), &content); *p != content {
		t.Fatalf("preview chữ = %v", *p)
	}
	week := KindWeeklyKpi
	if p := preview(&week, []byte(`{"note":"Ổn"}`), nil); *p != "Tuần: Ổn" {
		t.Fatalf("preview tuần = %v", *p)
	}
}

func TestWriterPath(t *testing.T) {
	k, p := KindAcademicWeekly, "2026-W42"
	if got := WriterPath(&k, &p); got != "/reports/academic-weekly?week=2026-W42" {
		t.Fatalf("WriterPath = %s", got)
	}
	k, p = KindTeacherAttendance, "2026-10"
	if got := WriterPath(&k, &p); got != "/reports/teacher-monthly?month=2026-10" {
		t.Fatalf("WriterPath = %s", got)
	}
	if got := WriterPath(nil, nil); got != "/reports" {
		t.Fatalf("WriterPath tự do = %s", got)
	}
}

func TestLimitText(t *testing.T) {
	if got := limitText("Lớp học tiếng Anh", 7); got != "Lớp học..." {
		t.Fatalf("limitText = %q", got)
	}
	if got := lcfirst("Tuần 42"); got != "tuần 42" {
		t.Fatalf("lcfirst = %q", got)
	}
}
