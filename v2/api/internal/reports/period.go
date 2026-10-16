package reports

import (
	"fmt"
	"regexp"
	"strconv"
	"strings"
	"time"
)

// Kỳ báo cáo dạng khóa chuỗi như Laravel ReportPeriod: ngày "2026-10-16", tuần ISO "2026-W42", tháng "2026-10", quý "2026-Q4".
// Mọi phép "hôm nay" tính theo giờ Việt Nam (không có giờ mùa hè nên dùng múi cố định +7).

var vnZone = time.FixedZone("ICT", 7*3600)

var (
	datePattern    = regexp.MustCompile(`^\d{4}-\d{2}-\d{2}$`)
	weekPattern    = regexp.MustCompile(`^\d{4}-W\d{2}$`)
	monthPattern   = regexp.MustCompile(`^\d{4}-(0[1-9]|1[0-2])$`)
	quarterPattern = regexp.MustCompile(`^\d{4}-Q[1-4]$`)
)

// dayOf: 00:00 giờ Việt Nam của ngày chứa t.
func dayOf(t time.Time) time.Time {
	t = t.In(vnZone)
	return time.Date(t.Year(), t.Month(), t.Day(), 0, 0, 0, 0, vnZone)
}

func dateKey(t time.Time) string { return t.In(vnZone).Format("2006-01-02") }

// parseDate đọc "Y-m-d" (ngày có thật), lỗi thì ok = false.
func parseDate(s string) (time.Time, bool) {
	if !datePattern.MatchString(s) {
		return time.Time{}, false
	}
	t, err := time.ParseInLocation("2006-01-02", s, vnZone)
	return t, err == nil
}

func weekKey(t time.Time) string {
	y, w := t.In(vnZone).ISOWeek()
	return fmt.Sprintf("%04d-W%02d", y, w)
}

// weekRange: thứ Hai và Chủ nhật (00:00) của tuần ISO; ok = false nếu khóa sai hoặc tuần không có trong năm.
func weekRange(key string) (from, to time.Time, ok bool) {
	if !weekPattern.MatchString(key) {
		return
	}
	y, _ := strconv.Atoi(key[:4])
	w, _ := strconv.Atoi(key[6:])
	jan4 := time.Date(y, 1, 4, 0, 0, 0, 0, vnZone)
	monday1 := jan4.AddDate(0, 0, -((int(jan4.Weekday()) + 6) % 7))
	from = monday1.AddDate(0, 0, (w-1)*7)
	if gy, gw := from.ISOWeek(); gy != y || gw != w {
		return time.Time{}, time.Time{}, false
	}
	return from, from.AddDate(0, 0, 6), true
}

func monthKey(t time.Time) string { return t.In(vnZone).Format("2006-01") }

func monthRange(key string) (from, to time.Time, ok bool) {
	if !monthPattern.MatchString(key) {
		return
	}
	y, _ := strconv.Atoi(key[:4])
	m, _ := strconv.Atoi(key[5:])
	from = time.Date(y, time.Month(m), 1, 0, 0, 0, 0, vnZone)
	return from, from.AddDate(0, 1, -1), true
}

func quarterKey(t time.Time) string {
	t = t.In(vnZone)
	return fmt.Sprintf("%04d-Q%d", t.Year(), (int(t.Month())-1)/3+1)
}

func quarterRange(key string) (from, to time.Time, ok bool) {
	if !quarterPattern.MatchString(key) {
		return
	}
	y, _ := strconv.Atoi(key[:4])
	q, _ := strconv.Atoi(key[6:])
	from = time.Date(y, time.Month((q-1)*3+1), 1, 0, 0, 0, 0, vnZone)
	return from, from.AddDate(0, 3, -1), true
}

// quarterMonths: 3 tháng của quý, vd. 2026-Q3 → 2026-07, 2026-08, 2026-09.
func quarterMonths(key string) []string {
	from, _, ok := quarterRange(key)
	if !ok {
		return nil
	}
	return []string{monthKey(from), monthKey(from.AddDate(0, 1, 0)), monthKey(from.AddDate(0, 2, 0))}
}

// "Tuần 42 (12/10 – 18/10/2026)"
func weekLabel(key string) string {
	from, to, ok := weekRange(key)
	if !ok {
		return key
	}
	n, _ := strconv.Atoi(key[6:])
	return fmt.Sprintf("Tuần %d (%s – %s)", n, from.Format("02/01"), to.Format("02/01/2006"))
}

// "Tháng 10/2026"
func monthLabel(key string) string {
	from, _, ok := monthRange(key)
	if !ok {
		return key
	}
	return "Tháng " + from.Format("01/2006")
}

// "Quý 4/2026 (tháng 10 – tháng 12)"
func quarterLabel(key string) string {
	from, to, ok := quarterRange(key)
	if !ok {
		return key
	}
	return fmt.Sprintf("Quý %s/%d (tháng %d – tháng %d)", key[6:], from.Year(), int(from.Month()), int(to.Month()))
}

var vnWeekdays = [...]string{"chủ nhật", "thứ hai", "thứ ba", "thứ tư", "thứ năm", "thứ sáu", "thứ bảy"}
var vnWeekdaysShort = [...]string{"CN", "T2", "T3", "T4", "T5", "T6", "T7"}

func ucfirst(s string) string {
	for i := range s {
		if i > 0 {
			return strings.ToUpper(s[:i]) + s[i:]
		}
	}
	return strings.ToUpper(s)
}

// "Thứ sáu, 16 tháng 10, 2026" (Carbon isoFormat('dddd, D [tháng] M, YYYY') tiếng Việt).
func dateLabelVI(t time.Time) string {
	t = t.In(vnZone)
	return fmt.Sprintf("%s, %d tháng %d, %d", ucfirst(vnWeekdays[t.Weekday()]), t.Day(), int(t.Month()), t.Year())
}

// "T6 16/10" (isoFormat('dd D/M')).
func shortDayLabel(t time.Time) string {
	t = t.In(vnZone)
	return fmt.Sprintf("%s %d/%d", vnWeekdaysShort[t.Weekday()], t.Day(), int(t.Month()))
}

// Option là một lựa chọn cho ô chọn (value + nhãn).
type Option struct {
	Value string `json:"value"`
	Label string `json:"label"`
}

// weekOptions: tuần hiện tại và 8 tuần trước.
func weekOptions(now time.Time) []Option {
	out := make([]Option, 0, 9)
	for i := 0; i <= 8; i++ {
		key := weekKey(now.AddDate(0, 0, -7*i))
		prefix := ""
		switch i {
		case 0:
			prefix = "Tuần này · "
		case 1:
			prefix = "Tuần trước · "
		}
		out = append(out, Option{key, prefix + weekLabel(key)})
	}
	return out
}

// monthOptions: tháng hiện tại và 6 tháng trước.
func monthOptions(now time.Time) []Option {
	first := time.Date(now.In(vnZone).Year(), now.In(vnZone).Month(), 1, 0, 0, 0, 0, vnZone)
	out := make([]Option, 0, 7)
	for i := 0; i <= 6; i++ {
		key := monthKey(first.AddDate(0, -i, 0))
		out = append(out, Option{key, monthLabel(key)})
	}
	return out
}

// quarterOptions: quý hiện tại và 4 quý trước.
func quarterOptions(now time.Time) []Option {
	from, _, _ := quarterRange(quarterKey(now))
	out := make([]Option, 0, 5)
	for i := 0; i <= 4; i++ {
		key := quarterKey(from.AddDate(0, -3*i, 0))
		out = append(out, Option{key, quarterLabel(key)})
	}
	return out
}

// monthlyDueDate: hạn báo cáo tháng của GV / trợ giảng là Chủ nhật cuối cùng của tháng chứa t (MonthlyReportDue).
func monthlyDueDate(t time.Time) time.Time {
	t = t.In(vnZone)
	end := time.Date(t.Year(), t.Month()+1, 0, 0, 0, 0, 0, vnZone)
	return end.AddDate(0, 0, -int(end.Weekday()))
}

// monthlyReminderKind: loại nhắc của ngày today so với hạn tháng đó (còn 3 ngày / còn 1 ngày / đúng hạn), khác thì "".
func monthlyReminderKind(today time.Time) string {
	days := int(monthlyDueDate(today).Sub(dayOf(today)).Hours() / 24)
	switch days {
	case 3:
		return "due_minus_3"
	case 1:
		return "due_minus_1"
	case 0:
		return "due"
	}
	return ""
}

// Hạn nộp báo cáo ngày: 09:00 sáng hôm sau (DailyReportDue::DEADLINE). Trước 10:00 mà hôm qua chưa nộp thì màn báo cáo ngày
// mở sẵn hôm qua (OPEN_YESTERDAY_BEFORE).
const (
	dailyDeadlineHour   = 9
	openYesterdayBefore = 10
)

func dailyDeadline(date time.Time) time.Time {
	d := dayOf(date)
	return d.AddDate(0, 0, 1).Add(dailyDeadlineHour * time.Hour)
}
