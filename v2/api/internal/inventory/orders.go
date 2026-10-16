package inventory

import (
	"context"
	"fmt"
	"slices"
	"strings"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/inventory/inventorydb"
)

// ───────────────────────── Order học liệu ─────────────────────────
// Giáo viên tạo (material_order.create); Học vụ xử lý đạo cụ / in ấn / GVNN của chi nhánh mình
// (material_order.process_ops + phạm vi material_order), Trưởng Học thuật xử lý học liệu học thuật
// (material_order.process_academic). Người lập luôn thấy order của mình; view_all thấy theo phạm vi.

const orderModule = "material_order"

const (
	OrderPending    = "pending"
	OrderProcessing = "processing"
	OrderDone       = "done"
	OrderRejected   = "rejected"
	OrderOverdue    = "overdue"
)

var OrderCategories = []Option{
	{"props", "Đạo cụ"},
	{"printing", "In ấn"},
	{"foreign_teacher", "Order GVNN"},
	{"academic", "Học liệu học thuật"},
}

var OrderStatuses = []Option{
	{OrderPending, "Chờ xử lý"},
	{OrderProcessing, "Đang xử lý"},
	{OrderDone, "Hoàn thành"},
	{OrderRejected, "Từ chối"},
	{OrderOverdue, "Quá hạn"},
}

func labelOf(opts []Option, v string) string {
	for _, o := range opts {
		if o.Value == v {
			return o.Label
		}
	}
	return v
}

func validOption(opts []Option, v string) bool {
	return slices.ContainsFunc(opts, func(o Option) bool { return o.Value == v })
}

func isOpen(status string) bool {
	return status == OrderPending || status == OrderProcessing || status == OrderOverdue
}

// isOpsCategory: loại do Học vụ xử lý theo chi nhánh; học liệu học thuật do Trưởng Học thuật.
func isOpsCategory(category string) bool { return category != "academic" }

func processPermission(category string) string {
	if isOpsCategory(category) {
		return "material_order.process_ops"
	}
	return "material_order.process_academic"
}

// canProcess: người này được xử lý order (theo loại; Học vụ chỉ chi nhánh trong phạm vi). Admin có mọi quyền.
func canProcess(actor *iam.Access, category string, branch uuid.UUID) bool {
	if !actor.Can(processPermission(category)) {
		return false
	}
	return !isOpsCategory(category) || actor.CoversBranch(orderModule, &branch)
}

// DueAt: hạn xử lý theo loại + ngày sử dụng (giờ Việt Nam).
//   - Đạo cụ, In ấn: giờ chốt (mặc định 15:00) ngày hôm trước ngày sử dụng.
//   - GVNN, học thuật: 23:59 ngày N (mặc định 5) của tháng chứa ngày sử dụng, N không vượt số ngày của tháng.
func (c Config) DueAt(category string, useDate time.Time) time.Time {
	y, m, d := useDate.Date()
	if category == "props" || category == "printing" {
		return time.Date(y, m, d-1, c.DayBeforeCutoff/60, c.DayBeforeCutoff%60, 0, 0, vnZone)
	}
	last := time.Date(y, m+1, 0, 0, 0, 0, 0, vnZone).Day()
	return time.Date(y, m, min(max(1, c.MonthlyDeadline), last), 23, 59, 0, 0, vnZone)
}

// DeadlineState: ok | soon (dưới DueSoonHours giờ) | overdue | "" (đã xong / từ chối).
func (c Config) DeadlineState(status string, dueAt, now time.Time) string {
	switch {
	case !isOpen(status):
		return ""
	case dueAt.Before(now):
		return "overdue"
	case dueAt.Before(now.Add(time.Duration(c.DueSoonHours) * time.Hour)):
		return "soon"
	default:
		return "ok"
	}
}

type Order struct {
	ID            uuid.UUID  `json:"id"`
	Code          string     `json:"code"`
	Title         string     `json:"title"`
	Category      string     `json:"category"`
	CategoryLabel string     `json:"category_label"`
	Quantity      *int32     `json:"quantity"`
	BranchID      uuid.UUID  `json:"branch_id"`
	Branch        string     `json:"branch"`
	ClassID       *uuid.UUID `json:"class_id"`
	WorkTaskID    *uuid.UUID `json:"work_task_id"`
	RequesterID   uuid.UUID  `json:"requester_id"`
	Requester     string     `json:"requester"`
	UseDate       string     `json:"use_date"`
	DueAt         time.Time  `json:"due_at"`
	Status        string     `json:"status"`
	StatusLabel   string     `json:"status_label"`
	DeadlineState string     `json:"deadline_state"`
	CreatedLate   bool       `json:"created_late"`
	CreatedAt     time.Time  `json:"created_at"`
	// Chi tiết
	Description   *string    `json:"description,omitempty"`
	ProcessorNote *string    `json:"processor_note,omitempty"`
	RejectReason  *string    `json:"reject_reason,omitempty"`
	ProcessedBy   *string    `json:"processed_by,omitempty"`
	ProcessedAt   *time.Time `json:"processed_at,omitempty"`
	ProcessedLate bool       `json:"processed_late"`
}

type orderRow = inventorydb.GetOrderRow

func (s *Service) toOrder(r orderRow, now time.Time) Order {
	return Order{
		ID: r.ID, Code: r.Code, Title: r.Title, Category: r.Category, CategoryLabel: labelOf(OrderCategories, r.Category),
		Quantity: r.Quantity, BranchID: r.BranchID, Branch: r.BranchName, ClassID: r.ClassID, WorkTaskID: r.WorkTaskID,
		RequesterID: r.RequesterID, Requester: r.RequesterName, UseDate: r.UseDate.Format(time.DateOnly), DueAt: r.DueAt,
		Status: r.Status, StatusLabel: labelOf(OrderStatuses, r.Status),
		DeadlineState: s.cfg.DeadlineState(r.Status, r.DueAt, now), CreatedLate: r.CreatedLate, CreatedAt: r.CreatedAt,
		Description: r.Description, ProcessorNote: r.ProcessorNote, RejectReason: r.RejectReason,
		ProcessedBy: r.ProcessorName, ProcessedAt: r.ProcessedAt, ProcessedLate: r.ProcessedLate,
	}
}

// orderScope: phạm vi xem order. Không có view_all → chỉ order của mình; có → theo mức phạm vi (mức chi nhánh thấy
// thêm order của mình ngoài chi nhánh).
func orderScope(actor *iam.Access) (all bool, branches []uuid.UUID) {
	if !actor.Can("material_order.view_all") {
		return false, nil
	}
	switch actor.Level(orderModule) {
	case iam.ScopeAll:
		return true, nil
	case iam.ScopeBranch:
		return false, actor.BranchIDs()
	}
	return false, nil
}

func visible(actor *iam.Access, requester, branch uuid.UUID) bool {
	all, branches := orderScope(actor)
	return all || requester == actor.UserID || slices.Contains(branches, branch)
}

type OrderFilter struct {
	Search   string
	Status   string
	Category string
	BranchID *uuid.UUID
	Page     int
}

type OrderList struct {
	Orders     []Order  `json:"orders"`
	Total      int64    `json:"total"`
	Page       int      `json:"page"`
	PageSize   int      `json:"page_size"`
	Statuses   []Option `json:"statuses"`
	Categories []Option `json:"categories"`
	// Bộ lọc chi nhánh: chỉ khi được xem order người khác
	Branches  []Option `json:"branches"`
	CanCreate bool     `json:"can_create"`
}

const ordersPageSize = 15

func (s *Service) ListOrders(ctx context.Context, actor *iam.Access, f OrderFilter) (OrderList, error) {
	f.Search = strings.TrimSpace(f.Search)
	switch {
	case f.Status != "" && !validOption(OrderStatuses, f.Status):
		return OrderList{}, iam.Invalid("Trạng thái lọc không hợp lệ.")
	case f.Category != "" && !validOption(OrderCategories, f.Category):
		return OrderList{}, iam.Invalid("Loại order lọc không hợp lệ.")
	case len([]rune(f.Search)) > 100:
		return OrderList{}, iam.Invalid("Từ khóa tìm kiếm không được vượt quá 100 ký tự.")
	}
	if f.Page < 1 {
		f.Page = 1
	}
	all, branches := orderScope(actor)
	var status, category *string
	if f.Status != "" {
		status = &f.Status
	}
	if f.Category != "" {
		category = &f.Category
	}
	rows, err := s.q.ListOrders(ctx, inventorydb.ListOrdersParams{
		AllScope: all, UserID: actor.UserID, BranchIds: branches, Status: status, Category: category,
		BranchID: f.BranchID, Search: likePattern(f.Search),
		PageLimit: ordersPageSize, PageOffset: int32((f.Page - 1) * ordersPageSize),
	})
	if err != nil {
		return OrderList{}, err
	}
	total, err := s.q.CountOrders(ctx, inventorydb.CountOrdersParams{
		AllScope: all, UserID: actor.UserID, BranchIds: branches, Status: status, Category: category,
		BranchID: f.BranchID, Search: likePattern(f.Search),
	})
	if err != nil {
		return OrderList{}, err
	}
	now := s.now()
	out := OrderList{
		Orders: make([]Order, 0, len(rows)), Total: total, Page: f.Page, PageSize: ordersPageSize,
		Statuses: OrderStatuses, Categories: OrderCategories, Branches: []Option{},
		CanCreate: actor.Can("material_order.create"),
	}
	for _, r := range rows {
		o := s.toOrder(orderRow(r), now)
		o.Description, o.ProcessorNote, o.RejectReason, o.ProcessedBy, o.ProcessedAt = nil, nil, nil, nil, nil
		out.Orders = append(out.Orders, o)
	}
	if actor.Can("material_order.view_all") {
		limit := actor.BranchLimit(orderModule)
		if limit == nil || len(limit) > 0 {
			bs, err := s.q.ListActiveBranches(ctx, limit)
			if err != nil {
				return OrderList{}, err
			}
			for _, b := range bs {
				out.Branches = append(out.Branches, Option{b.ID.String(), b.Name})
			}
		}
	}
	return out, nil
}

type OrderActions struct {
	Claim    bool `json:"claim"`
	Complete bool `json:"complete"`
	Reject   bool `json:"reject"`
}

type OrderDetail struct {
	Order   Order        `json:"order"`
	Actions OrderActions `json:"actions"`
}

func (s *Service) loadOrder(ctx context.Context, id uuid.UUID) (orderRow, error) {
	r, err := s.q.GetOrder(ctx, id)
	if isNoRows(err) {
		return r, ErrOrderNotFound
	}
	return r, err
}

func (s *Service) GetOrder(ctx context.Context, actor *iam.Access, id uuid.UUID) (OrderDetail, error) {
	r, err := s.loadOrder(ctx, id)
	if err != nil {
		return OrderDetail{}, err
	}
	if !visible(actor, r.RequesterID, r.BranchID) {
		return OrderDetail{}, iam.ErrForbidden
	}
	can := canProcess(actor, r.Category, r.BranchID)
	return OrderDetail{
		Order: s.toOrder(r, s.now()),
		Actions: OrderActions{
			Claim:    can && (r.Status == OrderPending || r.Status == OrderOverdue),
			Complete: can && isOpen(r.Status),
			Reject:   can && isOpen(r.Status),
		},
	}, nil
}

type OrderFormOptions struct {
	Categories      []Option   `json:"categories"`
	Branches        []Option   `json:"branches"`
	DefaultBranchID *uuid.UUID `json:"default_branch_id"`
	// Cho form báo hạn ngay khi chọn loại + ngày sử dụng
	StartOfMonthDay int    `json:"start_of_month_day"`
	DayBeforeCutoff string `json:"day_before_cutoff"`
	// TODO(classes): danh sách lớp (ClassModel::visibleTo) khi module lớp có; form hiện chưa chọn lớp.
}

// requesterBranches: chi nhánh người lập được chọn: chi nhánh của mình (mức all: mọi chi nhánh đang hoạt động).
func (s *Service) requesterBranches(ctx context.Context, actor *iam.Access) ([]inventorydb.ListActiveBranchesRow, error) {
	var ids []uuid.UUID
	if actor.Level(orderModule) != iam.ScopeAll {
		ids = actor.BranchIDs()
		if len(ids) == 0 {
			return nil, nil
		}
	}
	return s.q.ListActiveBranches(ctx, ids)
}

func (s *Service) OrderFormOptions(ctx context.Context, actor *iam.Access) (OrderFormOptions, error) {
	bs, err := s.requesterBranches(ctx, actor)
	if err != nil {
		return OrderFormOptions{}, err
	}
	out := OrderFormOptions{
		Categories: OrderCategories, Branches: make([]Option, 0, len(bs)), StartOfMonthDay: s.cfg.MonthlyDeadline,
		DayBeforeCutoff: fmt.Sprintf("%02d:%02d", s.cfg.DayBeforeCutoff/60, s.cfg.DayBeforeCutoff%60),
	}
	for _, b := range bs {
		out.Branches = append(out.Branches, Option{b.ID.String(), b.Name})
	}
	for _, ub := range actor.Branches {
		if ub.Primary && slices.ContainsFunc(bs, func(b inventorydb.ListActiveBranchesRow) bool { return b.ID == ub.ID }) {
			out.DefaultBranchID = &ub.ID
		}
	}
	if out.DefaultBranchID == nil && len(bs) > 0 {
		out.DefaultBranchID = &bs[0].ID
	}
	return out, nil
}

type OrderInput struct {
	Category    string     `json:"category"`
	BranchID    *uuid.UUID `json:"branch_id"`
	ClassID     *uuid.UUID `json:"class_id"`
	WorkTaskID  *uuid.UUID `json:"work_task_id"`
	Title       string     `json:"title"`
	Description *string    `json:"description"`
	Quantity    *int32     `json:"quantity"`
	UseDate     string     `json:"use_date"`
}

type OrderCreated struct {
	Order   Order  `json:"order"`
	Message string `json:"message"`
	// Tạo trễ: vẫn nhận, chỉ cảnh báo
	Warning string `json:"warning,omitempty"`
}

func (s *Service) CreateOrder(ctx context.Context, actor *iam.Access, in OrderInput) (OrderCreated, error) {
	in.Title = strings.TrimSpace(in.Title)
	in.Description = optText(in.Description)
	bs, err := s.requesterBranches(ctx, actor)
	if err != nil {
		return OrderCreated{}, err
	}
	useDate, dateErr := time.ParseInLocation(time.DateOnly, strings.TrimSpace(in.UseDate), vnZone)
	switch {
	case in.Category == "":
		return OrderCreated{}, iam.Invalid("Vui lòng chọn loại order.")
	case !validOption(OrderCategories, in.Category):
		return OrderCreated{}, iam.Invalid("Loại order không hợp lệ.")
	case in.BranchID == nil:
		return OrderCreated{}, iam.Invalid("Vui lòng chọn chi nhánh.")
	case !slices.ContainsFunc(bs, func(b inventorydb.ListActiveBranchesRow) bool { return b.ID == *in.BranchID }):
		return OrderCreated{}, iam.Invalid("Chi nhánh không hợp lệ: bạn chỉ chọn được chi nhánh của mình.")
	case in.Title == "":
		return OrderCreated{}, iam.Invalid("Vui lòng nhập nội dung order.")
	case tooLong(&in.Title, 255):
		return OrderCreated{}, iam.Invalid("Nội dung order không được vượt quá 255 ký tự.")
	case tooLong(in.Description, 2000):
		return OrderCreated{}, iam.Invalid("Mô tả chi tiết không được vượt quá 2000 ký tự.")
	case in.Quantity != nil && (*in.Quantity < 1 || *in.Quantity > 100000):
		return OrderCreated{}, iam.Invalid("Số lượng phải từ 1 đến 100.000.")
	case strings.TrimSpace(in.UseDate) == "":
		return OrderCreated{}, iam.Invalid("Vui lòng nhập ngày sử dụng.")
	case dateErr != nil:
		return OrderCreated{}, iam.Invalid("Ngày sử dụng không phải là ngày hợp lệ.")
	}
	// TODO(classes): kiểm class_id thuộc lớp chưa xóa (Rule::exists('classes')) khi module lớp có.
	now := s.now()
	due := s.cfg.DueAt(in.Category, useDate)
	var id uuid.UUID
	err = s.withTx(ctx, func(q *inventorydb.Queries) error {
		year := now.In(vnZone).Year()
		n, err := q.NextOrderNumber(ctx, int16(year))
		if err != nil {
			return err
		}
		id, err = q.CreateOrder(ctx, inventorydb.CreateOrderParams{
			Code: fmt.Sprintf("OH-%d-%04d", year, n), BranchID: *in.BranchID, ClassID: in.ClassID, WorkTaskID: in.WorkTaskID,
			RequesterID: actor.UserID, Category: in.Category, Title: in.Title, Description: in.Description,
			Quantity: in.Quantity, UseDate: useDate, DueAt: due, CreatedLate: due.Before(now),
		})
		return err
	})
	if err != nil {
		return OrderCreated{}, err
	}
	r, err := s.loadOrder(ctx, id)
	if err != nil {
		return OrderCreated{}, err
	}
	o := s.toOrder(r, now)
	s.log.Info("tạo order học liệu", "order", id, "code", o.Code, "by", actor.UserID)
	// TODO(notify): báo người xử lý (process_ops + phụ trách chi nhánh của order, hoặc process_academic) loại
	// "material_order_new": tiêu đề "Order học liệu mới: <title>", nội dung
	// "<code> · <loại> · hạn <H:i d/m/Y>[ (tạo trễ)].", link /material-orders/<id>.
	res := OrderCreated{Order: o, Message: fmt.Sprintf("Đã tạo order %s.", o.Code)}
	if o.CreatedLate {
		res.Warning = fmt.Sprintf("Order được tạo trễ: đã quá hạn xử lý (%s). Học vụ vẫn tiếp nhận nhưng có thể không kịp.", formatVN(o.DueAt))
	}
	return res, nil
}

var errNotWaiting = &iam.Error{Status: 409, Code: "invalid_status", Message: "Order không còn ở trạng thái chờ xử lý."}
var errFinished = &iam.Error{Status: 409, Code: "invalid_status", Message: "Order đã được xử lý xong."}

func (s *Service) processable(ctx context.Context, actor *iam.Access, id uuid.UUID) (orderRow, error) {
	r, err := s.loadOrder(ctx, id)
	if err != nil {
		return r, err
	}
	if !canProcess(actor, r.Category, r.BranchID) {
		return r, ErrNotProcessable
	}
	return r, nil
}

// ClaimOrder: nhận xử lý order Chờ xử lý / Quá hạn → Đang xử lý.
func (s *Service) ClaimOrder(ctx context.Context, actor *iam.Access, id uuid.UUID) (string, error) {
	if _, err := s.processable(ctx, actor, id); err != nil {
		return "", err
	}
	if _, err := s.q.ClaimOrder(ctx, inventorydb.ClaimOrderParams{ID: id, ProcessedBy: &actor.UserID}); isNoRows(err) {
		return "", errNotWaiting
	} else if err != nil {
		return "", err
	}
	s.log.Info("nhận xử lý order học liệu", "order", id, "by", actor.UserID)
	return "Đã nhận xử lý order.", nil
}

// CompleteOrder: hoàn thành (kể cả order đã Quá hạn — ghi nhận xử lý trễ).
func (s *Service) CompleteOrder(ctx context.Context, actor *iam.Access, id uuid.UUID, note *string) (string, error) {
	note = optText(note)
	if tooLong(note, 2000) {
		return "", iam.Invalid("Ghi chú xử lý không được vượt quá 2000 ký tự.")
	}
	if _, err := s.processable(ctx, actor, id); err != nil {
		return "", err
	}
	late, err := s.finish(ctx, actor, id, OrderDone, note, nil)
	if err != nil {
		return "", err
	}
	if late {
		return "Đã hoàn thành order (xử lý trễ hạn).", nil
	}
	return "Đã hoàn thành order.", nil
}

// RejectOrder: từ chối, bắt buộc có lý do.
func (s *Service) RejectOrder(ctx context.Context, actor *iam.Access, id uuid.UUID, reason *string) (string, error) {
	if _, err := s.processable(ctx, actor, id); err != nil {
		return "", err
	}
	reason = optText(reason)
	if reason == nil {
		return "", iam.Invalid("Vui lòng nhập lý do từ chối.")
	}
	if tooLong(reason, 2000) {
		return "", iam.Invalid("Lý do từ chối không được vượt quá 2000 ký tự.")
	}
	if _, err := s.finish(ctx, actor, id, OrderRejected, nil, reason); err != nil {
		return "", err
	}
	return "Đã từ chối order.", nil
}

func (s *Service) finish(ctx context.Context, actor *iam.Access, id uuid.UUID, status string, note, reason *string) (bool, error) {
	now := s.now()
	late, err := s.q.FinishOrder(ctx, inventorydb.FinishOrderParams{
		ID: id, Status: status, ProcessedBy: &actor.UserID, ProcessedAt: &now, ProcessorNote: note, RejectReason: reason,
	})
	if isNoRows(err) {
		return false, errFinished
	}
	if err != nil {
		return false, err
	}
	s.log.Info("xử lý xong order học liệu", "order", id, "status", status, "late", late, "by", actor.UserID)
	return late, nil
}

// MarkOverdue: order Chờ / Đang xử lý đã qua hạn → "Quá hạn" (idempotent). Trả số order được chuyển.
func (s *Service) MarkOverdue(ctx context.Context) (int, error) {
	rows, err := s.q.MarkOverdue(ctx, s.now())
	if err != nil {
		return 0, err
	}
	for _, r := range rows {
		s.log.Info("order học liệu quá hạn", "order", r.ID, "code", r.Code)
		// TODO(notify): báo người xử lý (như lúc tạo) loại "material_order_overdue": tiêu đề
		// "Order học liệu quá hạn: <title>", nội dung "<code> · <loại> · hạn <H:i d/m/Y> — hãy xử lý sớm.".
	}
	return len(rows), nil
}

// RunOverdueLoop chạy MarkOverdue định kỳ (Laravel: lệnh material-orders:mark-overdue mỗi 15 phút) tới khi ctx dừng.
// TODO(jobs): chuyển sang bộ lập lịch chung khi có.
func (s *Service) RunOverdueLoop(ctx context.Context, every time.Duration) {
	t := time.NewTicker(every)
	defer t.Stop()
	for {
		if n, err := s.MarkOverdue(ctx); err != nil && ctx.Err() == nil {
			s.log.Error("đánh dấu order học liệu quá hạn", "err", err)
		} else if n > 0 {
			s.log.Info("đã chuyển order học liệu sang Quá hạn", "count", n)
		}
		select {
		case <-ctx.Done():
			return
		case <-t.C:
		}
	}
}
