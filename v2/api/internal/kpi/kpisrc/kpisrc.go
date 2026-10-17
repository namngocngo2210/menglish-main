// Package kpisrc: kiểu dữ liệu chung giữa phân hệ KPI và các phân hệ cung cấp số liệu KPI tự động (KpiAutoCounter của
// Laravel). Tách riêng để phân hệ báo cáo / công việc / kho / lớp học viết adapter mà không phụ thuộc vòng vào internal/kpi.
package kpisrc

import (
	"context"
	"math"
	"strconv"
	"strings"
	"time"

	"github.com/google/uuid"
)

// Record là một dòng bằng chứng của số liệu KPI tự động.
type Record struct {
	// Thời điểm của bản ghi (sắp xếp, nhãn ngày d/m, khóa tháng); zero = dòng diễn giải không có ngày.
	At time.Time
	// Nhãn ngày / khóa tháng thay cho At (vd. báo cáo tháng: "10/2026", "2026-10").
	Date  string
	Month string
	Text  string
	// Uncounted: hiện để đối chiếu nhưng không đếm vào KPI.
	Uncounted bool
	Note      string
	// Cap: điều kiện chặn (mục tối đa 50%, phiếu tối đa loại B).
	Cap bool
	// Factor: hệ số đúng hạn của nguồn tính theo hệ số (1 / 0,5 / 0,25 / 0); nil = bản ghi không tính.
	Factor *float64
}

// Result là số liệu của một nguồn trong một khoảng.
type Result struct {
	// Value: nguồn tỉ lệ → % (nil = chưa có dữ liệu). Nguồn đếm / hệ số bỏ qua (KPI tự đếm từ Records).
	Value   *float64
	Records []Record
}

// Counter đếm số liệu của một nguồn cho nhân sự userID trong [from, to] (giờ Việt Nam, to là cuối ngày cuối kỳ).
type Counter func(ctx context.Context, userID uuid.UUID, from, to time.Time) (Result, error)

// Sources: nguồn theo khóa auto_source (KpiCriterion::AUTO_SOURCES).
type Sources map[string]Counter

// JournalEntry: sự vụ trong Nhật ký Học vụ có gắn mã KPI (gợi ý số lần cho mục KPI điền tay).
type JournalEntry struct {
	Code  string
	Date  time.Time
	Title string
}

// Option: lựa chọn của ô chọn (lớp…).
type Option struct {
	Value string `json:"value"`
	Label string `json:"label"`
}

// AttendanceRecord: một lượt điểm danh học viên (màn Rà soát điểm danh).
type AttendanceRecord struct {
	ID           uuid.UUID `json:"id"`
	Student      string    `json:"student"`
	Class        string    `json:"class"`
	Teacher      string    `json:"teacher"`
	Note         *string   `json:"note"`
	Status       string    `json:"status"`
	StatusLabel  string    `json:"status_label"`
	ReviewStatus string    `json:"review_status"`
	ReviewLabel  string    `json:"review_label"`
}

// ReviewedAttendance: lượt điểm danh vừa rà soát (ghi nhật ký).
type ReviewedAttendance struct {
	ID        uuid.UUID
	Label     string // "Học viên · Lớp · ngày"
	OldStatus string
	BranchID  *uuid.UUID
}

// ClassStats: số liệu lớp phụ trách của một nhân sự trong kỳ (bảng KPI tự động).
type ClassStats struct {
	Classes           int
	Students          int // học viên đã vào lớp (trừ chờ khai giảng)
	Retained          int // chưa thôi học
	AttendanceTotal   int
	AttendancePresent int
}

// TaskStats: việc được giao có hạn trong kỳ.
type TaskStats struct {
	Total int
	Done  int
}

// F: con trỏ số (hệ số / tỉ lệ).
func F(v float64) *float64 { return &v }

// Num: number_format(v, decimals, ',', '.') rồi bỏ số 0 thừa sau dấu phẩy: 62.5 → "62,5", 1500 → "1.500".
func Num(v float64, decimals int) string {
	neg := v < 0
	p := math.Pow(10, float64(decimals))
	s := strconv.FormatFloat(math.Round(math.Abs(v)*p)/p, 'f', decimals, 64)
	intPart, frac, _ := strings.Cut(s, ".")
	var b strings.Builder
	for i, r := range intPart {
		if i > 0 && (len(intPart)-i)%3 == 0 {
			b.WriteByte('.')
		}
		b.WriteRune(r)
	}
	out := b.String()
	if frac = strings.TrimRight(frac, "0"); frac != "" {
		out += "," + frac
	}
	if neg && out != "0" {
		out = "-" + out
	}
	return out
}

// Pct: tỉ lệ 1 chữ số lẻ kèm "%" (KpiAutoCounter::pct).
func Pct(v float64) string { return Num(v, 1) + "%" }

// DayFactor: hệ số trễ theo ngày (deliverable): đúng hạn 1; trễ 1–3 ngày 0,5; 4–7 ngày 0,25; từ 8 ngày 0.
func DayFactor(daysLate int) float64 {
	switch {
	case daysLate <= 0:
		return 1
	case daysLate <= 3:
		return 0.5
	case daysLate <= 7:
		return 0.25
	}
	return 0
}

// HourFactor: hệ số trễ theo giờ (task / phân bổ): đúng hạn 1; trễ ≤ 4 giờ 0,5; 4–24 giờ 0,25; quá 24 giờ 0.
func HourFactor(hoursLate float64) float64 {
	switch {
	case hoursLate <= 0:
		return 1
	case hoursLate <= 4:
		return 0.5
	case hoursLate <= 24:
		return 0.25
	}
	return 0
}

// LateText: "đúng hạn" / "trễ 25 phút" (làm tròn lên) / "trễ 3,5 giờ".
func LateText(hours float64) string {
	switch {
	case hours <= 0:
		return "đúng hạn"
	case hours < 1:
		return "trễ " + strconv.Itoa(int(math.Ceil(hours*60))) + " phút"
	}
	return "trễ " + Num(hours, 1) + " giờ"
}
