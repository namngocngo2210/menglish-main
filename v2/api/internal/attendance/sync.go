package attendance

import (
	"context"
	"encoding/json"
	"strconv"
	"strings"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/attendance/attendancedb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

// Lịch sử đồng bộ máy chấm công (PayrollController::syncHistory / exportSyncErrors). Chưa có tích hợp thiết bị nào ghi
// hr.timesheet_sync_logs (như Laravel) → màn hiển thị trạng thái trống trung thực.

// SyncStatuses: bộ lọc trạng thái (TimesheetSyncLog::STATUS_LABELS).
var SyncStatuses = []Option{
	{"success", "Thành công"},
	{"partial", "Lỗi một phần"},
	{"failed", "Lỗi toàn bộ"},
}

const syncPerPage = 10

type SyncErrorRow struct {
	EmployeeCode *string `json:"employee_code"`
	EmployeeName *string `json:"employee_name"`
	Code         *string `json:"code"`
	Message      string  `json:"message"`
}

type SyncLog struct {
	ID           uuid.UUID      `json:"id"`
	CreatedAt    string         `json:"created_at"`
	DeviceName   string         `json:"device_name"`
	Branch       string         `json:"branch"`
	RecordsCount int            `json:"records_count"`
	MatchedCount int            `json:"matched_count"`
	FailedCount  int            `json:"failed_count"`
	SkippedCount int            `json:"skipped_count"`
	Status       string         `json:"status"`
	StatusLabel  string         `json:"status_label"`
	ErrorCode    *string        `json:"error_code"`
	ErrorMessage *string        `json:"error_message"`
	ErrorRows    []SyncErrorRow `json:"error_rows"`
	HasErrors    bool           `json:"has_errors"`
}

type SyncPage struct {
	Logs          []SyncLog `json:"logs"`
	Meta          Meta      `json:"meta"`
	HasAnyLog     bool      `json:"has_any_log"`
	StatusOptions []Option  `json:"status_options"`
}

type SyncFilter struct {
	From, To, Status string
	Page, PerPage    int
}

// normalizedStatus: giá trị cũ "error" hiển thị như "failed".
func normalizedStatus(s string) string {
	if s == "error" {
		return "failed"
	}
	return s
}

func errorRows(raw []byte) []SyncErrorRow {
	out := []SyncErrorRow{}
	if len(raw) == 0 {
		return out
	}
	var rows []map[string]any
	if json.Unmarshal(raw, &rows) != nil {
		return out
	}
	str := func(v any) *string {
		switch x := v.(type) {
		case string:
			return &x
		case float64:
			return ptr(strconv.FormatFloat(x, 'f', -1, 64))
		}
		return nil
	}
	for _, r := range rows {
		out = append(out, SyncErrorRow{EmployeeCode: str(r["employee_code"]), EmployeeName: str(r["employee_name"]),
			Code: str(r["code"]), Message: deref(str(r["message"]))})
	}
	return out
}

// SyncHistory: các đợt đồng bộ, mới trước, 10 dòng mỗi trang.
func (s *Service) SyncHistory(ctx context.Context, f SyncFilter) (*SyncPage, error) {
	var from, to *time.Time
	if strings.TrimSpace(f.From) != "" {
		d, ok := parseDate(f.From)
		if !ok {
			return nil, iam.Invalid("Từ ngày không hợp lệ.")
		}
		from = &d
	}
	if strings.TrimSpace(f.To) != "" {
		d, ok := parseDate(f.To)
		if !ok {
			return nil, iam.Invalid("Đến ngày không hợp lệ.")
		}
		to = ptr(d.Add(24*time.Hour - time.Nanosecond))
	}
	page, per, lim, off := pageArgs(f.Page, f.PerPage, syncPerPage)
	rows, err := s.q.SyncLogs(ctx, attendancedb.SyncLogsParams{FromAt: from, ToAt: to, Status: optionOf(SyncStatuses, f.Status), Lim: lim, Off: off})
	if err != nil {
		return nil, err
	}
	out := &SyncPage{Logs: []SyncLog{}, Meta: Meta{Page: page, PerPage: per}, StatusOptions: SyncStatuses}
	for _, r := range rows {
		out.Meta.Total = r.Total
		branch := "Toàn hệ thống"
		if r.BranchName != nil {
			branch = *r.BranchName
		}
		st := normalizedStatus(r.Status)
		label := typeLabelOf(SyncStatuses, st)
		if label == "" {
			label = r.Status
		}
		er := errorRows(r.ErrorRows)
		out.Logs = append(out.Logs, SyncLog{ID: r.ID, CreatedAt: r.CreatedAt.In(vnZone).Format("15:04 02/01/2006"),
			DeviceName: r.DeviceName, Branch: branch, RecordsCount: int(r.RecordsCount), MatchedCount: int(r.MatchedCount),
			FailedCount: int(r.FailedCount), SkippedCount: int(r.SkippedCount), Status: st, StatusLabel: label,
			ErrorCode: r.ErrorCode, ErrorMessage: r.ErrorMessage, ErrorRows: er,
			HasErrors: len(er) > 0 || strings.TrimSpace(deref(r.ErrorMessage)) != ""})
	}
	if out.HasAnyLog, err = s.q.AnySyncLog(ctx); err != nil {
		return nil, err
	}
	return out, nil
}

// Sheet: bảng xuất CSV.
type Sheet struct {
	Name string
	Rows [][]string
}

// SyncErrors: file lỗi của một đợt đồng bộ (Mã NV, Tên, Mã lỗi, Nội dung).
func (s *Service) SyncErrors(ctx context.Context, id uuid.UUID) (*Sheet, error) {
	l, err := s.q.GetSyncLog(ctx, id)
	if isNoRows(err) {
		return nil, ErrSyncLogMissing
	}
	if err != nil {
		return nil, err
	}
	out := &Sheet{Name: "loi-dong-bo-cham-cong-" + l.ID.String() + ".csv",
		Rows: [][]string{{"Mã NV", "Tên nhân viên", "Mã lỗi", "Nội dung chi tiết"}}}
	for _, r := range errorRows(l.ErrorRows) {
		out.Rows = append(out.Rows, []string{deref(r.EmployeeCode), deref(r.EmployeeName), deref(r.Code), r.Message})
	}
	if len(out.Rows) == 1 && strings.TrimSpace(deref(l.ErrorMessage)) != "" {
		out.Rows = append(out.Rows, []string{"", "", deref(l.ErrorCode), *l.ErrorMessage})
	}
	return out, nil
}
