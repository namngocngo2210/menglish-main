// Package tickets: Ticket hỗ trợ (SupportTicketController của bản Laravel). Nhân sự tạo ticket báo lỗi / yêu cầu hỗ trợ
// (kèm ảnh chụp, tài liệu), người xử lý (support_ticket.update / assign / close) phân công, trao đổi, ghi chú nội bộ và
// đổi trạng thái; người tạo mở lại được ticket đã giải quyết. Thông báo trong ứng dụng + email (hàng đợi mail) như
// NotificationService::notifyTicket*.
package tickets

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"regexp"
	"slices"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgxpool"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/files"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/filestore"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/tickets/ticketsdb"
)

// Quyền (danh mục quyền của Laravel).
const (
	PermView   = "support_ticket.view"
	PermCreate = "support_ticket.create"
	PermUpdate = "support_ticket.update"
	PermAssign = "support_ticket.assign"
	PermClose  = "support_ticket.close"
)

const (
	// SubjectAttachment: loại file đính kèm ticket trong kho file (subject_id = id tin nhắn).
	SubjectAttachment = "ticket_attachment"
	// Ticket giống hệt (cùng người tạo, tiêu đề, mô tả) tạo trong khoảng này coi là gửi lặp, không tạo thêm.
	duplicateWindow = 10 * time.Minute
	// Số file mỗi lần gửi (giới hạn của form multipart).
	MaxFiles  = 4
	maxText   = 20000
	perPage   = 15
	maxPerPge = 100
)

// AttachmentPolicy: attachments.* nullable|file|mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xlsx|max:15360.
var AttachmentPolicy = filestore.Policy{
	Types:    []string{"jpg", "jpeg", "png", "gif", "webp", "pdf", "doc", "docx", "xlsx"},
	MaxBytes: 15 << 20,
	TooLarge: "Dung lượng tập tin đính kèm không được vượt quá 15360 kB.",
}

func init() {
	audit.RegisterSubject("ticket", "Ticket hỗ trợ")
	audit.RegisterPath("/tickets", audit.ModuleTickets)
}

var vnZone = time.FixedZone("ICT", 7*3600)

// Option: lựa chọn của ô chọn.
type Option struct {
	Value string `json:"value"`
	Label string `json:"label"`
}

// Nhãn như SupportTicket::getCategoryLabelAttribute / getStatusLabelAttribute và form tạo ticket.
var (
	Categories = []Option{
		{"technical_issue", "Lỗi Hệ Thống / IT"},
		{"facilities", "Cơ Sở Vật Chất"},
		{"curriculum", "Giáo Trình / Học Vụ"},
		{"tuition", "Học Phí / Hóa Đơn"},
		{"customer_complaint", "Khiếu Nại Học Viên"},
		{"other", "Yêu Cầu Hỗ Trợ Khác"},
	}
	Priorities = []Option{
		{"low", "Thấp (Low)"},
		{"medium", "Trung bình (Medium)"},
		{"high", "Cao (High)"},
		{"urgent", "Khẩn cấp (Urgent)"},
	}
	Statuses = []Option{
		{"open", "Mới tiếp nhận"},
		{"in_progress", "Đang xử lý"},
		{"resolved", "Đã giải quyết"},
		{"closed", "Đã đóng ticket"},
	}
)

func labelOf(list []Option, v, fallback string) string {
	for _, o := range list {
		if o.Value == v {
			return o.Label
		}
	}
	return fallback
}

// CategoryLabel: danh mục lạ hiện "Yêu Cầu Hỗ Trợ Khác" như Laravel.
func CategoryLabel(c string) string { return labelOf(Categories, c, "Yêu Cầu Hỗ Trợ Khác") }

// StatusLabel: nhãn trạng thái (lạ thì giữ nguyên mã).
func StatusLabel(s string) string { return labelOf(Statuses, s, s) }

// PriorityLabel: "Khẩn cấp", "Cao"… (bỏ phần tiếng Anh trong ngoặc).
func PriorityLabel(p string) string {
	l := labelOf(Priorities, p, p)
	if i := strings.Index(l, " ("); i > 0 {
		return l[:i]
	}
	return l
}

func isFinished(status string) bool { return status == "resolved" || status == "closed" }

// ───────────── Quyền theo ticket (SupportTicket::userCanManage / userCanSeeInternalNotes / userCanReopen) ─────────────

// CanManage: người xử lý ticket (cập nhật / phân công / đóng) — thấy mọi ticket, viết ghi chú nội bộ.
func CanManage(a *iam.Access) bool { return a.CanAny(PermUpdate, PermAssign, PermClose) }

type ticketRef struct {
	CreatorID  uuid.UUID
	AssigneeID *uuid.UUID
}

func (t ticketRef) assignedTo(id uuid.UUID) bool { return t.AssigneeID != nil && *t.AssigneeID == id }

func canSeeInternal(a *iam.Access, t ticketRef) bool { return CanManage(a) || t.assignedTo(a.UserID) }

func canReopen(a *iam.Access, t ticketRef) bool { return a.Can(PermClose) || t.CreatorID == a.UserID }

// ───────────── Service ─────────────

type Service struct {
	pool  *pgxpool.Pool
	q     *ticketsdb.Queries
	log   *slog.Logger
	now   func() time.Time
	files *files.Service
}

func NewService(pool *pgxpool.Pool, log *slog.Logger) *Service {
	return &Service{pool: pool, q: ticketsdb.New(pool), log: log, now: time.Now}
}

// WithFiles gắn kho file (đính kèm) và đăng ký quyền tải file đính kèm.
func (s *Service) WithFiles(x *files.Service) *Service {
	s.files = x
	if x != nil {
		x.RegisterReader(SubjectAttachment, s.readAttachment)
	}
	return s
}

// SetClock: đồng hồ giả cho test.
func (s *Service) SetClock(now func() time.Time) { s.now = now }

var (
	ErrNotFound    = &iam.Error{Status: 404, Code: "ticket_not_found", Message: "Không tìm thấy ticket."}
	errForbidden   = &iam.Error{Status: 403, Code: "forbidden", Message: "Bạn không có quyền thao tác trên ticket này."}
	errNotHandler  = iam.Invalid("Chỉ phân công ticket cho nhân sự có quyền xử lý ticket.")
	errNoFileStore = iam.Invalid("Kho file chưa được cấu hình, chưa gửi được tệp đính kèm.")
)

func isNoRows(err error) bool { return errors.Is(err, pgx.ErrNoRows) }

func (s *Service) inTx(ctx context.Context, fn func(tx pgx.Tx, q *ticketsdb.Queries) error) error {
	return pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error { return fn(tx, s.q.WithTx(tx)) })
}

// load: ticket theo id hoặc mã (TK-2026-0001).
func (s *Service) load(ctx context.Context, q *ticketsdb.Queries, idOrCode string) (ticketsdb.GetTicketRow, error) {
	p := ticketsdb.GetTicketParams{Code: strings.TrimSpace(idOrCode)}
	if id, err := uuid.Parse(p.Code); err == nil {
		p.ID, p.Code = &id, ""
	}
	if p.ID == nil && p.Code == "" {
		return ticketsdb.GetTicketRow{}, ErrNotFound
	}
	t, err := q.GetTicket(ctx, p)
	if isNoRows(err) {
		return t, ErrNotFound
	}
	return t, err
}

func refOf(t ticketsdb.GetTicketRow) ticketRef { return ticketRef{t.CreatorID, t.AssigneeID} }

// ───────────── Kiểm dữ liệu ─────────────

func runes(s string) int { return utf8.RuneCountInString(s) }

func required(v, msg string) error {
	if strings.TrimSpace(v) == "" {
		return iam.Invalid(msg)
	}
	return nil
}

func validOption(list []Option, v string) bool {
	return slices.ContainsFunc(list, func(o Option) bool { return o.Value == v })
}

// ensureHandler: người được phân công phải là nhân sự đang hoạt động có quyền support_ticket.update, trong phạm vi
// chi nhánh của người phân công (handlerCandidates).
func (s *Service) ensureHandler(ctx context.Context, a *iam.Access, id uuid.UUID) error {
	ids, err := s.handlerCandidates(ctx, a)
	if err != nil {
		return err
	}
	if !slices.Contains(ids, id) {
		return errNotHandler
	}
	users, err := s.q.ActiveUsers(ctx, []uuid.UUID{id})
	if err != nil {
		return err
	}
	if len(users) == 0 {
		return errNotHandler
	}
	return nil
}

// Handlers: người được phân công xử lý ticket (đang hoạt động, có support_ticket.update, trong phạm vi chi nhánh của
// người phân công), theo tên.
func (s *Service) Handlers(ctx context.Context, a *iam.Access) ([]Option, error) {
	ids, err := s.handlerCandidates(ctx, a)
	if err != nil {
		return nil, err
	}
	rows, err := s.q.ActiveUsers(ctx, ids)
	if err != nil {
		return nil, err
	}
	out := make([]Option, 0, len(rows))
	for _, r := range rows {
		out = append(out, Option{r.ID.String(), r.FullName})
	}
	return out, nil
}

// ───────────── Danh sách ─────────────

type Row struct {
	ID            uuid.UUID `json:"id"`
	Code          string    `json:"code"`
	Title         string    `json:"title"`
	Category      string    `json:"category"`
	CategoryLabel string    `json:"category_label"`
	Priority      string    `json:"priority"`
	PriorityLabel string    `json:"priority_label"`
	Status        string    `json:"status"`
	StatusLabel   string    `json:"status_label"`
	Creator       string    `json:"creator"`
	Assignee      *string   `json:"assignee"`
	CreatedAt     time.Time `json:"created_at"`
}

type Stats struct {
	Total      int64 `json:"total"`
	Open       int64 `json:"open"`
	InProgress int64 `json:"in_progress"`
	Resolved   int64 `json:"resolved"`
}

type ListFilter struct {
	Status, Category, Search string
	Page, PerPage            int
}

type ListPage struct {
	Tickets   []Row    `json:"tickets"`
	Total     int64    `json:"total"`
	Page      int      `json:"page"`
	PerPage   int      `json:"per_page"`
	Stats     Stats    `json:"stats"`
	Statuses  []Option `json:"statuses"`
	CanManage bool     `json:"can_manage"`
}

func likePattern(s string) *string {
	s = strings.TrimSpace(s)
	if s == "" {
		return nil
	}
	s = "%" + strings.NewReplacer(`\`, `\\`, `%`, `\%`, `_`, `\_`).Replace(s) + "%"
	return &s
}

// List: danh sách ticket mới trước, 15 / trang, theo phạm vi support_ticket (scopeOf).
func (s *Service) List(ctx context.Context, a *iam.Access, f ListFilter) (*ListPage, error) {
	manage := CanManage(a)
	per := f.PerPage
	if per <= 0 {
		per = perPage
	}
	per = min(per, maxPerPge)
	page := max(f.Page, 1)
	sc, err := s.scopeOf(ctx, a)
	if err != nil {
		return nil, err
	}
	p := ticketsdb.ListTicketsParams{AllScope: sc.all, Me: a.UserID, Branches: sc.branches, Search: likePattern(f.Search),
		Lim: int32(per), Off: int32((page - 1) * per)}
	if validOption(Statuses, f.Status) {
		p.Status = &f.Status
	}
	if validOption(Categories, f.Category) {
		p.Category = &f.Category
	}
	rows, err := s.q.ListTickets(ctx, p)
	if err != nil {
		return nil, err
	}
	st, err := s.q.TicketStats(ctx, ticketsdb.TicketStatsParams{AllScope: sc.all, Me: a.UserID, Branches: sc.branches})
	if err != nil {
		return nil, err
	}
	out := &ListPage{Tickets: make([]Row, 0, len(rows)), Page: page, PerPage: per, Statuses: Statuses, CanManage: manage,
		Stats: Stats{Total: st.Total, Open: st.Open, InProgress: st.InProgress, Resolved: st.Resolved}}
	for _, r := range rows {
		out.Total = r.Total
		out.Tickets = append(out.Tickets, Row{ID: r.ID, Code: r.Code, Title: r.Title, Category: r.Category,
			CategoryLabel: CategoryLabel(r.Category), Priority: r.Priority, PriorityLabel: PriorityLabel(r.Priority),
			Status: r.Status, StatusLabel: StatusLabel(r.Status), Creator: r.CreatorName, Assignee: r.AssigneeName,
			CreatedAt: r.CreatedAt})
	}
	return out, nil
}

// FormOptions: dữ liệu form tạo ticket (danh sách người xử lý chỉ khi được phân công).
type FormOptions struct {
	Categories []Option `json:"categories"`
	Priorities []Option `json:"priorities"`
	Staffs     []Option `json:"staffs"`
	CanAssign  bool     `json:"can_assign"`
}

func (s *Service) FormOptions(ctx context.Context, a *iam.Access) (*FormOptions, error) {
	out := &FormOptions{Categories: Categories, Priorities: Priorities, Staffs: []Option{}, CanAssign: a.Can(PermAssign)}
	if out.CanAssign {
		var err error
		if out.Staffs, err = s.Handlers(ctx, a); err != nil {
			return nil, err
		}
	}
	return out, nil
}

// ───────────── Chi tiết ─────────────

type Detail struct {
	ID            uuid.UUID  `json:"id"`
	Code          string     `json:"code"`
	Title         string     `json:"title"`
	Description   string     `json:"description"`
	Category      string     `json:"category"`
	CategoryLabel string     `json:"category_label"`
	Priority      string     `json:"priority"`
	PriorityLabel string     `json:"priority_label"`
	Status        string     `json:"status"`
	StatusLabel   string     `json:"status_label"`
	IsFinished    bool       `json:"is_finished"`
	CreatorID     uuid.UUID  `json:"creator_id"`
	Creator       string     `json:"creator"`
	AssigneeID    *uuid.UUID `json:"assignee_id"`
	Assignee      *string    `json:"assignee"`
	ResolvedAt    *time.Time `json:"resolved_at"`
	CreatedAt     time.Time  `json:"created_at"`
}

type Attachment struct {
	ID      uuid.UUID `json:"id"`
	Name    string    `json:"name"`
	Ext     string    `json:"ext"`
	Size    int64     `json:"size"`
	IsImage bool      `json:"is_image"`
	URL     string    `json:"url"`
}

type Message struct {
	ID             uuid.UUID    `json:"id"`
	UserID         *uuid.UUID   `json:"user_id"`
	User           *string      `json:"user"`
	Message        string       `json:"message"`
	IsInternalNote bool         `json:"is_internal_note"`
	CreatedAt      time.Time    `json:"created_at"`
	Attachments    []Attachment `json:"attachments"`
}

type ShowPage struct {
	Ticket          Detail    `json:"ticket"`
	Messages        []Message `json:"messages"`
	Staffs          []Option  `json:"staffs"`
	Statuses        []Option  `json:"statuses"`
	CanPostInternal bool      `json:"can_post_internal"`
	CanReopen       bool      `json:"can_reopen"`
	CanClose        bool      `json:"can_close"`
	CanAssign       bool      `json:"can_assign"`
}

func toDetail(t ticketsdb.GetTicketRow) Detail {
	return Detail{ID: t.ID, Code: t.Code, Title: t.Title, Description: t.Description, Category: t.Category,
		CategoryLabel: CategoryLabel(t.Category), Priority: t.Priority, PriorityLabel: PriorityLabel(t.Priority),
		Status: t.Status, StatusLabel: StatusLabel(t.Status), IsFinished: isFinished(t.Status), CreatorID: t.CreatorID,
		Creator: t.CreatorName, AssigneeID: t.AssigneeID, Assignee: t.AssigneeName, ResolvedAt: t.ResolvedAt,
		CreatedAt: t.CreatedAt}
}

var imageExts = []string{"jpg", "jpeg", "png", "gif", "webp"}

func (s *Service) attachments(ctx context.Context, messageID uuid.UUID) ([]Attachment, error) {
	out := []Attachment{}
	if s.files == nil {
		return out, nil
	}
	list, err := s.files.List(ctx, SubjectAttachment, messageID)
	if err != nil {
		return nil, err
	}
	// files.List trả mới trước; hiện theo thứ tự tải lên.
	for i := len(list) - 1; i >= 0; i-- {
		f := list[i]
		out = append(out, Attachment{ID: f.ID, Name: f.Name, Ext: f.Extension, Size: f.Size,
			IsImage: slices.Contains(imageExts, f.Extension), URL: f.URL})
	}
	return out, nil
}

// Show: chi tiết ticket + hội thoại (ghi chú nội bộ chỉ người xử lý / người được giao thấy).
func (s *Service) Show(ctx context.Context, a *iam.Access, idOrCode string) (*ShowPage, error) {
	t, err := s.load(ctx, s.q, idOrCode)
	if err != nil {
		return nil, err
	}
	ref := refOf(t)
	if ok, err := s.visible(ctx, a, ref); err != nil {
		return nil, err
	} else if !ok {
		return nil, errForbidden
	}
	internal := canSeeInternal(a, ref)
	msgs, err := s.q.ListMessages(ctx, t.ID)
	if err != nil {
		return nil, err
	}
	out := &ShowPage{Ticket: toDetail(t), Messages: []Message{}, Staffs: []Option{}, Statuses: Statuses,
		CanPostInternal: CanManage(a), CanReopen: canReopen(a, ref), CanClose: a.Can(PermClose), CanAssign: a.Can(PermAssign)}
	for _, m := range msgs {
		if m.IsInternalNote && !internal {
			continue
		}
		att, err := s.attachments(ctx, m.ID)
		if err != nil {
			return nil, err
		}
		out.Messages = append(out.Messages, Message{ID: m.ID, UserID: m.UserID, User: m.UserName, Message: m.Message,
			IsInternalNote: m.IsInternalNote, CreatedAt: m.CreatedAt, Attachments: att})
	}
	if out.CanAssign {
		if out.Staffs, err = s.Handlers(ctx, a); err != nil {
			return nil, err
		}
	}
	return out, nil
}

// readAttachment: quyền tải file đính kèm — người trong luồng ticket; file của ghi chú nội bộ chỉ người xem được
// ghi chú nội bộ mở được (SupportTicketController::attachment).
func (s *Service) readAttachment(ctx context.Context, a *iam.Access, f files.File) (string, error) {
	if f.SubjectID == nil || !a.Can(PermView) {
		return "", errForbidden
	}
	m, err := s.q.GetMessage(ctx, *f.SubjectID)
	if isNoRows(err) {
		return "", files.ErrNotFound
	}
	if err != nil {
		return "", err
	}
	t, err := s.load(ctx, s.q, m.TicketID.String())
	if err != nil {
		return "", err
	}
	ref := refOf(t)
	if ok, err := s.visible(ctx, a, ref); err != nil {
		return "", err
	} else if !ok || (m.IsInternalNote && !canSeeInternal(a, ref)) {
		return "", errForbidden
	}
	return "", nil
}

// ───────────── Tạo ticket ─────────────

type CreateInput struct {
	Title           string  `json:"title"`
	Category        string  `json:"category"`
	Priority        string  `json:"priority"`
	Description     string  `json:"description"`
	AssigneeID      *string `json:"assignee_id"`
	SubmissionToken *string `json:"submission_token"`
}

// Result: kết quả thao tác (thông báo hiện cho người dùng).
type Result struct {
	ID        uuid.UUID `json:"id"`
	Code      string    `json:"code"`
	Message   string    `json:"message"`
	Duplicate bool      `json:"duplicate,omitempty"`
}

var tokenRe = regexp.MustCompile(`^[A-Za-z0-9._:-]{1,64}$`)

func (in *CreateInput) normalize() (assignee *uuid.UUID, token *string, err error) {
	in.Title = strings.TrimSpace(in.Title)
	in.Description = strings.TrimSpace(in.Description)
	in.Category = strings.TrimSpace(in.Category)
	in.Priority = strings.TrimSpace(in.Priority)
	if err := required(in.Title, "Vui lòng nhập Tiêu đề."); err != nil {
		return nil, nil, err
	}
	if runes(in.Title) > 255 {
		return nil, nil, iam.Invalid("Trường Tiêu đề không được vượt quá 255 ký tự.")
	}
	if err := required(in.Category, "Vui lòng nhập Danh mục."); err != nil {
		return nil, nil, err
	}
	if !validOption(Categories, in.Category) {
		return nil, nil, iam.Invalid("Giá trị đã chọn trong trường Danh mục không hợp lệ.")
	}
	if err := required(in.Priority, "Vui lòng nhập Mức độ ưu tiên."); err != nil {
		return nil, nil, err
	}
	if !validOption(Priorities, in.Priority) {
		return nil, nil, iam.Invalid("Giá trị đã chọn trong trường Mức độ ưu tiên không hợp lệ.")
	}
	if err := required(in.Description, "Vui lòng nhập Mô tả chi tiết."); err != nil {
		return nil, nil, err
	}
	if runes(in.Description) > maxText {
		return nil, nil, iam.Invalid("Trường Mô tả chi tiết không được vượt quá 20000 ký tự.")
	}
	if in.AssigneeID != nil && strings.TrimSpace(*in.AssigneeID) != "" {
		id, err := uuid.Parse(strings.TrimSpace(*in.AssigneeID))
		if err != nil {
			return nil, nil, iam.Invalid("Giá trị đã chọn trong trường Người xử lý không tồn tại trong hệ thống.")
		}
		assignee = &id
	}
	if in.SubmissionToken != nil && strings.TrimSpace(*in.SubmissionToken) != "" {
		t := strings.TrimSpace(*in.SubmissionToken)
		if !tokenRe.MatchString(t) {
			return nil, nil, iam.Invalid("Mã lần gửi không hợp lệ.")
		}
		token = &t
	}
	return assignee, token, nil
}

// submitted: ticket lần gửi này đã tạo trước đó (cùng mã lần gửi, hoặc cùng tiêu đề + mô tả trong 10 phút).
func (s *Service) submitted(ctx context.Context, q *ticketsdb.Queries, creator uuid.UUID, token *string, in CreateInput) (*ticketsdb.OpsTicket, error) {
	if token != nil {
		t, err := q.TicketBySubmission(ctx, ticketsdb.TicketBySubmissionParams{CreatorID: creator, Token: *token})
		if err == nil {
			return &t, nil
		}
		if !isNoRows(err) {
			return nil, err
		}
	}
	t, err := q.RecentDuplicateTicket(ctx, ticketsdb.RecentDuplicateTicketParams{CreatorID: creator, Title: in.Title,
		Description: in.Description, Since: s.now().Add(-duplicateWindow)})
	if isNoRows(err) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	return &t, nil
}

func alreadyCreated(t ticketsdb.OpsTicket) *Result {
	return &Result{ID: t.ID, Code: t.Code, Duplicate: true,
		Message: "Ticket " + t.Code + " đã được tạo trước đó, hệ thống không tạo thêm bản trùng."}
}

// newCode: TK-<năm>-<số 4 chữ số> theo bộ đếm năm (không trùng khi tạo đồng thời).
func (s *Service) newCode(ctx context.Context, q *ticketsdb.Queries) (string, error) {
	year := s.now().In(vnZone).Year()
	for range 5 {
		n, err := q.NextTicketNumber(ctx, int16(year))
		if err != nil {
			return "", err
		}
		code := fmt.Sprintf("TK-%d-%04d", year, n)
		taken, err := q.TicketCodeTaken(ctx, code)
		if err != nil {
			return "", err
		}
		if !taken {
			return code, nil
		}
	}
	return "", errors.New("tickets: không cấp được mã ticket")
}

// checkUploads: kiểm từng file theo AttachmentPolicy.
func checkUploads(ups []*filestore.Upload) error {
	if len(ups) > MaxFiles {
		return iam.Invalid(fmt.Sprintf("Mỗi lần gửi tối đa %d tệp đính kèm.", MaxFiles))
	}
	for _, u := range ups {
		if err := u.Check(AttachmentPolicy); err != nil {
			var ve *filestore.ValidationError
			if errors.As(err, &ve) {
				return iam.Invalid(ve.Message)
			}
			return err
		}
	}
	return nil
}

// saveFiles lưu file đính kèm vào kho file, gắn với tin nhắn.
func (s *Service) saveFiles(ctx context.Context, tx pgx.Tx, a *iam.Access, t ticketsdb.OpsTicket, msgID uuid.UUID, ups []*filestore.Upload) (int, error) {
	if len(ups) == 0 {
		return 0, nil
	}
	if s.files == nil {
		return 0, errNoFileStore
	}
	for _, u := range ups {
		if _, err := s.files.Save(ctx, tx, u, files.SaveInput{
			Owner: files.Owner{Module: "tickets", SubjectType: SubjectAttachment, SubjectID: &msgID},
			By:    &a.UserID,
			Audit: audit.Entry{Module: audit.ModuleTickets, SubjectType: "ticket", SubjectID: t.ID.String(),
				SubjectLabel: t.Code, Description: "Đính kèm tệp vào Ticket #" + t.Code},
		}); err != nil {
			return 0, err
		}
	}
	return len(ups), nil
}

// Create: tạo ticket (trạng thái Mới tiếp nhận) + tin nhắn đầu tiên là mô tả, kèm file. Gửi lặp (cùng mã lần gửi hoặc
// cùng nội dung trong 10 phút) trả về ticket đã có.
func (s *Service) Create(ctx context.Context, a *iam.Access, in CreateInput, ups []*filestore.Upload) (*Result, error) {
	assignee, token, err := in.normalize()
	if err != nil {
		return nil, err
	}
	if assignee != nil {
		if !a.Can(PermAssign) {
			return nil, iam.ErrForbidden
		}
		if err := s.ensureHandler(ctx, a, *assignee); err != nil {
			return nil, err
		}
	}
	if err := checkUploads(ups); err != nil {
		return nil, err
	}
	if t, err := s.submitted(ctx, s.q, a.UserID, token, in); err != nil || t != nil {
		if err != nil {
			return nil, err
		}
		return alreadyCreated(*t), nil
	}
	var out *Result
	err = s.inTx(ctx, func(tx pgx.Tx, q *ticketsdb.Queries) error {
		code, err := s.newCode(ctx, q)
		if err != nil {
			return err
		}
		t, err := q.InsertTicket(ctx, ticketsdb.InsertTicketParams{Code: code, SubmissionToken: token, Title: in.Title,
			Category: in.Category, Priority: in.Priority, Description: in.Description, CreatorID: a.UserID, AssigneeID: assignee})
		if isNoRows(err) {
			// Hai request cùng mã lần gửi chạy song song: request kia đã lưu trước.
			return errDuplicateRace
		}
		if err != nil {
			return err
		}
		msg, err := q.InsertMessage(ctx, ticketsdb.InsertMessageParams{TicketID: t.ID, UserID: &a.UserID, Message: in.Description})
		if err != nil {
			return err
		}
		n, err := s.saveFiles(ctx, tx, a, t, msg.ID, ups)
		if err != nil {
			return err
		}
		creator, err := q.UserName(ctx, a.UserID)
		if err != nil {
			return err
		}
		if err := s.notifyCreated(ctx, tx, q, a, t, creator); err != nil {
			return err
		}
		if err := audit.Record(ctx, tx, audit.Entry{ActorID: &a.UserID, Module: audit.ModuleTickets, Event: audit.EventCreated,
			Action: "ticket.create", SubjectType: "ticket", SubjectID: t.ID.String(), SubjectLabel: t.Code,
			Description: "Tạo Ticket yêu cầu hỗ trợ: " + t.Title,
			Properties:  map[string]any{"category": t.Category, "priority": t.Priority, "attachments": n}}); err != nil {
			return err
		}
		out = &Result{ID: t.ID, Code: t.Code, Message: "Đã tạo phiếu yêu cầu hỗ trợ / báo lỗi " + t.Code + " thành công!"}
		return nil
	})
	if errors.Is(err, errDuplicateRace) {
		if t, err := s.submitted(ctx, s.q, a.UserID, token, in); err != nil || t != nil {
			if err != nil {
				return nil, err
			}
			return alreadyCreated(*t), nil
		}
	}
	if err != nil {
		return nil, err
	}
	s.log.Info("ticket created", "ticket", out.Code, "by", a.UserID)
	return out, nil
}

var errDuplicateRace = errors.New("tickets: gửi trùng song song")

// ───────────── Phản hồi ─────────────

type MessageInput struct {
	Message        string `json:"message"`
	IsInternalNote bool   `json:"is_internal_note"`
}

// AddMessage: gửi phản hồi / ghi chú nội bộ. Người xử lý (không phải người tạo) phản hồi ticket Mới → Đang xử lý;
// người tạo trả lời ticket Đã giải quyết → mở lại (Đang xử lý).
func (s *Service) AddMessage(ctx context.Context, a *iam.Access, idOrCode string, in MessageInput, ups []*filestore.Upload) (*Result, error) {
	cur, err := s.load(ctx, s.q, idOrCode)
	if err != nil {
		return nil, err
	}
	if ok, err := s.visible(ctx, a, refOf(cur)); err != nil {
		return nil, err
	} else if !ok {
		return nil, errForbidden
	}
	in.Message = strings.TrimSpace(in.Message)
	if err := required(in.Message, "Vui lòng nhập Nội dung phản hồi."); err != nil {
		return nil, err
	}
	if runes(in.Message) > maxText {
		return nil, iam.Invalid("Trường Nội dung phản hồi không được vượt quá 20000 ký tự.")
	}
	if in.IsInternalNote && !CanManage(a) {
		return nil, errForbidden
	}
	if err := checkUploads(ups); err != nil {
		return nil, err
	}
	err = s.inTx(ctx, func(tx pgx.Tx, q *ticketsdb.Queries) error {
		t, err := q.LockTicket(ctx, cur.ID)
		if err != nil {
			return err
		}
		msg, err := q.InsertMessage(ctx, ticketsdb.InsertMessageParams{TicketID: t.ID, UserID: &a.UserID, Message: in.Message,
			IsInternalNote: in.IsInternalNote})
		if err != nil {
			return err
		}
		n, err := s.saveFiles(ctx, tx, a, t, msg.ID, ups)
		if err != nil {
			return err
		}
		byCreator := a.UserID == t.CreatorID
		if (t.Status == "open" && !byCreator) || (t.Status == "resolved" && byCreator && !in.IsInternalNote) {
			if err := q.SetTicketStatus(ctx, ticketsdb.SetTicketStatusParams{ID: t.ID, Status: "in_progress", ResolvedAt: t.ResolvedAt}); err != nil {
				return err
			}
			t.Status = "in_progress"
		}
		sender, err := q.UserName(ctx, a.UserID)
		if err != nil {
			return err
		}
		if err := s.notifyMessage(ctx, tx, q, a, t, msg, sender); err != nil {
			return err
		}
		desc := "Gửi tin nhắn phản hồi trong Ticket #" + t.Code
		if in.IsInternalNote {
			desc = "Ghi chú nội bộ trong Ticket #" + t.Code
		}
		return audit.Record(ctx, tx, audit.Entry{ActorID: &a.UserID, Module: audit.ModuleTickets, Event: "commented",
			Action: "ticket.message", SubjectType: "ticket", SubjectID: t.ID.String(), SubjectLabel: t.Code, Description: desc,
			Properties: map[string]any{"internal": in.IsInternalNote, "attachments": n}})
	})
	if err != nil {
		return nil, err
	}
	s.log.Info("ticket message", "ticket", cur.Code, "internal", in.IsInternalNote, "by", a.UserID)
	return &Result{ID: cur.ID, Code: cur.Code, Message: "Đã gửi phản hồi thành công!"}, nil
}

// ───────────── Trạng thái / mở lại / phân công ─────────────

// SetStatus: đổi trạng thái (support_ticket.close). Đã giải quyết / Đã đóng ghi thời điểm giải quyết.
func (s *Service) SetStatus(ctx context.Context, a *iam.Access, idOrCode, status string) (*Result, error) {
	status = strings.TrimSpace(status)
	if status == "" {
		return nil, iam.Invalid("Vui lòng chọn Trạng thái.")
	}
	if !validOption(Statuses, status) {
		return nil, iam.Invalid("Giá trị đã chọn trong trường Trạng thái không hợp lệ.")
	}
	cur, err := s.load(ctx, s.q, idOrCode)
	if err != nil {
		return nil, err
	}
	if ok, err := s.visible(ctx, a, refOf(cur)); err != nil {
		return nil, err
	} else if !ok {
		return nil, errForbidden
	}
	err = s.inTx(ctx, func(tx pgx.Tx, q *ticketsdb.Queries) error {
		t, err := q.LockTicket(ctx, cur.ID)
		if err != nil {
			return err
		}
		var resolved *time.Time
		if isFinished(status) {
			now := s.now()
			resolved = &now
		}
		if err := q.SetTicketStatus(ctx, ticketsdb.SetTicketStatusParams{ID: t.ID, Status: status, ResolvedAt: resolved}); err != nil {
			return err
		}
		before := t.Status
		t.Status = status
		if err := s.notifyStatus(ctx, tx, q, a, t); err != nil {
			return err
		}
		return audit.Record(ctx, tx, audit.Entry{ActorID: &a.UserID, Module: audit.ModuleTickets, Event: audit.EventUpdated,
			Action: "ticket.status", SubjectType: "ticket", SubjectID: t.ID.String(), SubjectLabel: t.Code,
			Description: "Cập nhật trạng thái Ticket #" + t.Code + " sang " + StatusLabel(status),
			Changes:     audit.Changes{"status": {Old: before, New: status}}})
	})
	if err != nil {
		return nil, err
	}
	s.log.Info("ticket status", "ticket", cur.Code, "status", status, "by", a.UserID)
	return &Result{ID: cur.ID, Code: cur.Code, Message: "Đã cập nhật trạng thái ticket sang: " + StatusLabel(status) + "!"}, nil
}

// Reopen: mở lại ticket đã giải quyết / đã đóng → Đang xử lý (người có support_ticket.close hoặc người tạo); ghi một
// dòng vào hội thoại.
func (s *Service) Reopen(ctx context.Context, a *iam.Access, idOrCode string) (*Result, error) {
	cur, err := s.load(ctx, s.q, idOrCode)
	if err != nil {
		return nil, err
	}
	ref := refOf(cur)
	if ok, err := s.visible(ctx, a, ref); err != nil {
		return nil, err
	} else if !ok || !canReopen(a, ref) {
		return nil, errForbidden
	}
	err = s.inTx(ctx, func(tx pgx.Tx, q *ticketsdb.Queries) error {
		t, err := q.LockTicket(ctx, cur.ID)
		if err != nil {
			return err
		}
		if !isFinished(t.Status) {
			return iam.Invalid("Ticket đang mở, không cần mở lại.")
		}
		before := t.Status
		if err := q.SetTicketStatus(ctx, ticketsdb.SetTicketStatusParams{ID: t.ID, Status: "in_progress"}); err != nil {
			return err
		}
		t.Status = "in_progress"
		if _, err := q.InsertMessage(ctx, ticketsdb.InsertMessageParams{TicketID: t.ID, UserID: &a.UserID,
			Message: "Đã mở lại ticket, chuyển sang Đang xử lý."}); err != nil {
			return err
		}
		if err := s.notifyStatus(ctx, tx, q, a, t); err != nil {
			return err
		}
		return audit.Record(ctx, tx, audit.Entry{ActorID: &a.UserID, Module: audit.ModuleTickets, Event: "reopened",
			Action: "ticket.reopen", SubjectType: "ticket", SubjectID: t.ID.String(), SubjectLabel: t.Code,
			Description: "Mở lại Ticket #" + t.Code, Changes: audit.Changes{"status": {Old: before, New: "in_progress"}}})
	})
	if err != nil {
		return nil, err
	}
	s.log.Info("ticket reopened", "ticket", cur.Code, "by", a.UserID)
	return &Result{ID: cur.ID, Code: cur.Code, Message: "Đã mở lại ticket."}, nil
}

// Assign: phân công người xử lý (support_ticket.assign); người được giao phải có quyền xử lý ticket.
func (s *Service) Assign(ctx context.Context, a *iam.Access, idOrCode string, assigneeID string) (*Result, error) {
	assigneeID = strings.TrimSpace(assigneeID)
	if assigneeID == "" {
		return nil, iam.Invalid("Vui lòng chọn người phụ trách xử lý.")
	}
	id, err := uuid.Parse(assigneeID)
	if err != nil {
		return nil, iam.Invalid("Giá trị đã chọn trong trường Người xử lý không tồn tại trong hệ thống.")
	}
	if err := s.ensureHandler(ctx, a, id); err != nil {
		return nil, err
	}
	cur, err := s.load(ctx, s.q, idOrCode)
	if err != nil {
		return nil, err
	}
	if ok, err := s.visible(ctx, a, refOf(cur)); err != nil {
		return nil, err
	} else if !ok {
		return nil, errForbidden
	}
	var name string
	err = s.inTx(ctx, func(tx pgx.Tx, q *ticketsdb.Queries) error {
		t, err := q.LockTicket(ctx, cur.ID)
		if err != nil {
			return err
		}
		if err := q.SetTicketAssignee(ctx, ticketsdb.SetTicketAssigneeParams{ID: t.ID, AssigneeID: &id}); err != nil {
			return err
		}
		before := t.AssigneeID
		t.AssigneeID = &id
		if name, err = q.UserName(ctx, id); err != nil {
			return err
		}
		if err := s.notifyAssigned(ctx, tx, q, a, t, id); err != nil {
			return err
		}
		return audit.Record(ctx, tx, audit.Entry{ActorID: &a.UserID, Module: audit.ModuleTickets, Event: audit.EventUpdated,
			Action: "ticket.assign", SubjectType: "ticket", SubjectID: t.ID.String(), SubjectLabel: t.Code,
			Description: "Phân công xử lý Ticket #" + t.Code + " cho " + name,
			Changes:     audit.Changes{"assignee_id": {Old: before, New: id}}})
	})
	if err != nil {
		return nil, err
	}
	s.log.Info("ticket assigned", "ticket", cur.Code, "assignee", id, "by", a.UserID)
	return &Result{ID: cur.ID, Code: cur.Code, Message: "Đã phân công xử lý ticket cho " + name + "!"}, nil
}
