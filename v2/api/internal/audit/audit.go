// Package audit ghi nhật ký vận hành (Laravel: App\Support\Audit + spatie activitylog). Module nào thay đổi dữ liệu
// thì gọi audit.Record ngay trong transaction của thao tác đó; màn xem nhật ký ở package audit/auditapi.
//
// Package này không phụ thuộc iam để chính iam (và mọi module) gọi được.
package audit

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"reflect"
	"strings"
	"sync"
	"unicode/utf8"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit/auditdb"
)

// DB là pool hoặc transaction (pgxpool.Pool, pgx.Tx) để ghi nhật ký cùng transaction với thao tác.
type DB = auditdb.DBTX

// Phân hệ (log_name) dùng trong nhật ký, giữ đúng tên của bản Laravel để lọc và xuất Excel như cũ.
const (
	ModuleCRM      = "CRM & Leads"
	ModuleTuition  = "Học phí & Thu chi"
	ModuleStudents = "Học viên & Lớp học"
	ModuleTests    = "Khảo sát & Đề thi"
	ModuleSyllabus = "Giáo trình & Syllabus"
	ModulePayroll  = "Bảng lương & Chấm công"
	ModuleTasks    = "Quản lý công việc"
	ModuleTickets  = "Ticket hỗ trợ"
	ModuleMedia    = "Quản lý Media"
	ModuleSystem   = "Cấu hình hệ thống"
	ModuleUsers    = "Người dùng & Phân quyền"
	ModuleAccount  = "Tài khoản & Hồ sơ"
	ModuleGeneral  = "Hệ thống chung"
)

// Modules: danh sách phân hệ cố định của bộ lọc (thứ tự như ActivityLogController::index).
var Modules = []string{
	ModuleCRM, ModuleTuition, ModuleStudents, ModuleTests, ModuleSyllabus, ModulePayroll,
	ModuleTasks, ModuleTickets, ModuleMedia, ModuleSystem, ModuleUsers, ModuleAccount,
}

// Group là nhóm lọc nhanh theo module trên màn nhật ký (ActivityLogController::MODULE_GROUPS).
type Group struct {
	Key     string   `json:"value"`
	Label   string   `json:"label"`
	Modules []string `json:"-"`
}

var Groups = []Group{
	{"crm", "CRM", []string{ModuleCRM, ModuleTests}},
	{"syllabus", "Giáo trình", []string{ModuleSyllabus}},
	{"class", "Lớp & Điểm danh", []string{ModuleStudents}},
	{"permission", "Phân quyền", []string{ModuleUsers, ModuleAccount}},
	{"finance", "Học phí", []string{ModuleTuition}},
	{"payroll", "Lương & Chấm công", []string{ModulePayroll}},
	{"tasks", "Công việc & Ticket", []string{ModuleTasks, ModuleTickets}},
	{"system", "Cấu hình", []string{ModuleSystem, ModuleMedia}},
}

// Loại thao tác chuẩn; module được dùng tên khác khi không khớp (hiện nguyên văn).
const (
	EventCreated  = "created"
	EventUpdated  = "updated"
	EventDeleted  = "deleted"
	EventRestored = "restored"
)

// EventLabel: nhãn tiếng Việt của loại thao tác (Audit::eventLabel).
func EventLabel(event string) string {
	switch event {
	case EventCreated:
		return "Tạo mới"
	case EventUpdated:
		return "Cập nhật"
	case EventDeleted:
		return "Xóa"
	case EventRestored:
		return "Khôi phục"
	case "":
		return "Thao tác"
	}
	return event
}

// Tên tiếng Việt của loại đối tượng (Audit::MODELS[...]['label']); module mới gọi RegisterSubject lúc khởi động.
var (
	subjectMu    sync.RWMutex
	subjectNames = map[string]string{"user": "Tài khoản", "branch": "Chi nhánh", "role": "Vai trò"}
)

func RegisterSubject(subjectType, label string) {
	subjectMu.Lock()
	defer subjectMu.Unlock()
	subjectNames[subjectType] = label
}

// SubjectName: tên hiển thị của loại đối tượng (chưa đăng ký thì giữ nguyên mã).
func SubjectName(subjectType string) string {
	subjectMu.RLock()
	defer subjectMu.RUnlock()
	if n, ok := subjectNames[subjectType]; ok {
		return n
	}
	return subjectType
}

// Change là giá trị trước / sau của một trường.
type Change struct {
	Old any `json:"old"`
	New any `json:"new"`
}

// Changes: trường → trước / sau.
type Changes map[string]Change

// Entry là một dòng nhật ký.
type Entry struct {
	ActorID      *uuid.UUID // người thực hiện; nil = hệ thống tự động
	Module       string     // phân hệ (Module*); trống = ModuleGeneral
	Event        string     // Event*; trống = EventUpdated
	Action       string     // mã thao tác cho máy đọc, vd. "user.lock"
	SubjectType  string     // loại đối tượng, vd. "user", "branch", "role"
	SubjectID    string     // id đối tượng (uuid, mã…)
	SubjectLabel string     // tên dễ đọc của đối tượng, vd. "Nguyễn Văn A (MES001)"
	BranchID     *uuid.UUID // chi nhánh của dữ liệu (nếu có)
	Description  string     // bắt buộc, tiếng Việt, vd. "Cập nhật vai trò nhân viên"
	Changes      Changes    // dữ liệu trước / sau; khóa nhạy cảm tự che
	Properties   map[string]any
}

// Record ghi một dòng nhật ký bằng db (truyền transaction của thao tác để nhật ký và dữ liệu cùng commit).
// Thông tin request (IP, trình duyệt, đường dẫn, mã request) lấy từ context nếu đi qua Middleware.
func Record(ctx context.Context, db DB, e Entry) error {
	desc := strings.TrimSpace(e.Description)
	if desc == "" {
		return errors.New("audit: thiếu Description")
	}
	if e.Module == "" {
		e.Module = ModuleGeneral
	}
	if e.Event == "" {
		e.Event = EventUpdated
	}
	changes, err := json.Marshal(maskChanges(e.Changes))
	if err != nil {
		return fmt.Errorf("audit: changes: %w", err)
	}
	props, err := json.Marshal(Mask(e.Properties))
	if err != nil {
		return fmt.Errorf("audit: properties: %w", err)
	}
	p := auditdb.InsertAuditLogParams{
		ActorID: e.ActorID, Module: limit(e.Module, 100), Event: limit(e.Event, 50), Action: limit(e.Action, 100),
		SubjectType: optional(e.SubjectType, 50), SubjectID: optional(e.SubjectID, 100), SubjectLabel: optional(e.SubjectLabel, 300),
		BranchID: e.BranchID, Description: limit(desc, 1000), Changes: changes, Properties: props,
	}
	if info := requestFrom(ctx); info != nil {
		p.Ip, p.UserAgent, p.Method, p.Path, p.RequestID = optional(info.IP, 100), optional(info.UserAgent, 255),
			optional(info.Method, 10), optional(info.Path, 500), &info.ID
		info.logged.Store(true)
	}
	return auditdb.New(db).InsertAuditLog(ctx, p)
}

// Diff so sánh hai ảnh chụp dữ liệu (trường → giá trị) và trả các trường khác nhau. before nil = tạo mới,
// after nil = xóa.
func Diff(before, after map[string]any) Changes {
	out := Changes{}
	for k, nv := range after {
		ov, ok := before[k]
		if !ok || !same(ov, nv) {
			out[k] = Change{Old: ov, New: nv}
		}
	}
	for k, ov := range before {
		if _, ok := after[k]; !ok {
			out[k] = Change{Old: ov, New: nil}
		}
	}
	return out
}

// same so sánh theo JSON (con trỏ nil và nil như nhau, *string và string cùng giá trị như nhau).
func same(a, b any) bool {
	ja, _ := json.Marshal(a)
	jb, _ := json.Marshal(b)
	return string(ja) == string(jb) || reflect.DeepEqual(a, b)
}

func limit(s string, n int) string {
	if utf8.RuneCountInString(s) <= n {
		return s
	}
	r := []rune(s)
	return string(r[:n-1]) + "…"
}

func optional(s string, n int) *string {
	if s = strings.TrimSpace(s); s == "" {
		return nil
	}
	s = limit(s, n)
	return &s
}
