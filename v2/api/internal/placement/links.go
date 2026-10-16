package placement

import (
	"context"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

// Liên kết với phân hệ khác. Khách CRM (crm.leads) do phân hệ CRM quản lý, lớp / buổi học (academic.classes,
// academic.class_sessions) do phân hệ lớp học quản lý: phân hệ này chỉ giữ id và hỏi qua hai interface dưới đây.
// Khi gộp: CRM cài Leads, lớp học cài Sessions, rồi truyền vào NewService (cmd/menglish/main.go).
// Chưa gộp thì dùng NoLeads / NoSessions: không có khách nên mọi bài làm là bài chưa gắn khách, không xếp được học thử.

// Lead: thông tin khách cần cho test đầu vào & học thử.
type Lead struct {
	ID             uuid.UUID
	Code           string
	Name           string
	ParentName     string
	Phone          string
	Email          string
	Stage          string // new, consulting, test_scheduled, testing, tested, result_sent, waiting_class, won, lost
	StageLabel     string
	BranchID       *uuid.UUID
	AssignedUserID *uuid.UUID
	AssignedName   string
	TestScore      string
}

// LeadEvent: việc cần ghi sang hồ sơ khách (nhật ký tuyển sinh, chuyển giai đoạn chỉ đi tiến, điểm test).
type LeadEvent struct {
	LeadID  uuid.UUID
	ActorID *uuid.UUID // nil: thí sinh tự làm qua link công khai
	// Loại nhật ký như Laravel CrmCustomerHistory.type: test | trial
	Type    string
	Content string
	// AdvanceTo: giai đoạn cần chuyển tới nếu khách đang ở giai đoạn trước đó ("" = không chuyển)
	AdvanceTo string
	// TestScore: tóm tắt điểm ghi vào khách (khách Thất bại giữ nguyên); nil = không đổi
	TestScore *string
}

// Leads: cổng sang phân hệ CRM.
type Leads interface {
	// VisibleIDs: khách người dùng được xem theo phạm vi lead (own: phụ trách, branch: chi nhánh); all = mọi khách.
	VisibleIDs(ctx context.Context, actor *iam.Access) (ids []uuid.UUID, all bool, err error)
	// Get: khách theo id (nil nếu không có / đã xóa).
	Get(ctx context.Context, id uuid.UUID) (*Lead, error)
	// Lookup: nhiều khách một lần (bỏ qua id không có).
	Lookup(ctx context.Context, ids []uuid.UUID) (map[uuid.UUID]Lead, error)
	// MatchByPhone: khách chưa chốt có SĐT (chuẩn hóa) khớp, hoặc khớp SĐT phụ huynh của đúng một khách chưa chốt.
	MatchByPhone(ctx context.Context, phone string) (*Lead, error)
	// Record: ghi nhật ký / chuyển giai đoạn / điểm test sang hồ sơ khách.
	Record(ctx context.Context, ev LeadEvent) error
}

// Session: buổi học thật của lớp (để xếp học thử).
type Session struct {
	ID          uuid.UUID
	ClassID     uuid.UUID
	ClassName   string
	CourseName  string
	ClassStatus string // active, upcoming, ...
	Status      string // scheduled, cancelled, ...
	BranchID    *uuid.UUID
	BranchName  string
	Date        time.Time
	Start, End  string // "15:04", rỗng nếu chưa có giờ
	TeacherName string
	// Giáo viên / trợ giảng của buổi (nhận thông báo khách học thử); nil = chưa phân công
	TeacherID, AssistantID *uuid.UUID
}

// Sessions: cổng sang phân hệ lớp học.
type Sessions interface {
	Get(ctx context.Context, id uuid.UUID) (*Session, error)
	Lookup(ctx context.Context, ids []uuid.UUID) (map[uuid.UUID]Session, error)
	// TeachingScope: buổi người dùng dạy / trợ giảng và lớp người dùng phụ trách (GV, trợ giảng, GVNN).
	TeachingScope(ctx context.Context, userID uuid.UUID) (sessionIDs, classIDs []uuid.UUID, err error)
}

// NoLeads: chưa có phân hệ CRM.
type NoLeads struct{}

func (NoLeads) VisibleIDs(context.Context, *iam.Access) ([]uuid.UUID, bool, error) {
	return nil, false, nil
}
func (NoLeads) Get(context.Context, uuid.UUID) (*Lead, error) { return nil, nil }
func (NoLeads) Lookup(context.Context, []uuid.UUID) (map[uuid.UUID]Lead, error) {
	return map[uuid.UUID]Lead{}, nil
}
func (NoLeads) MatchByPhone(context.Context, string) (*Lead, error) { return nil, nil }
func (NoLeads) Record(context.Context, LeadEvent) error             { return nil }

// NoSessions: chưa có phân hệ lớp học.
type NoSessions struct{}

func (NoSessions) Get(context.Context, uuid.UUID) (*Session, error) { return nil, nil }
func (NoSessions) Lookup(context.Context, []uuid.UUID) (map[uuid.UUID]Session, error) {
	return map[uuid.UUID]Session{}, nil
}
func (NoSessions) TeachingScope(context.Context, uuid.UUID) ([]uuid.UUID, []uuid.UUID, error) {
	return nil, nil, nil
}
