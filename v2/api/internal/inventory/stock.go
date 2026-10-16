package inventory

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"slices"
	"strings"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/inventory/inventorydb"
)

// ───────────────────────── Tồn kho theo chi nhánh ─────────────────────────
// Xem: merchandise_stock.view, thao tác: merchandise_stock.manage, chi nhánh theo phạm vi merchandise_stock.
// Mọi thay đổi số tồn đi qua apply(): khóa dòng tồn (SELECT ... FOR UPDATE), cộng / trừ, ghi một dòng nhật ký.

const scopeModule = "merchandise_stock"

// Loại dòng nhật ký xuất nhập (MerchandiseStockMovement::TYPE_*; issue / transfer_* mới ở v2).
const (
	TypeOpening     = "opening"
	TypeImport      = "import"
	TypeCount       = "count"
	TypeSale        = "sale"
	TypeReturn      = "return"
	TypeIssue       = "issue"
	TypeTransferOut = "transfer_out"
	TypeTransferIn  = "transfer_in"
)

var typeLabels = map[string]string{
	TypeImport:      "Nhập kho",
	TypeCount:       "Kiểm kê điều chỉnh",
	TypeSale:        "Xuất theo phiếu thu",
	TypeReturn:      "Hoàn kho (hủy hóa đơn)",
	TypeOpening:     "Tồn đầu kỳ",
	TypeIssue:       "Xuất kho",
	TypeTransferOut: "Chuyển kho đi",
	TypeTransferIn:  "Nhận chuyển kho",
}

// LowThreshold: còn từ ngưỡng này trở xuống (và > 0) thì cảnh báo "Sắp hết" (MerchandiseStock::LOW_THRESHOLD).
const LowThreshold = 5

// Level: negative | out | low | ok theo số tồn.
func Level(qty int32) string {
	switch {
	case qty < 0:
		return "negative"
	case qty == 0:
		return "out"
	case qty <= LowThreshold:
		return "low"
	default:
		return "ok"
	}
}

var levelFilters = []string{"negative", "out", "low"}

type move struct {
	itemID, branchID uuid.UUID
	change           int32
	typ              string
	source           *string
	transferID       *uuid.UUID
	counterpart      *uuid.UUID
	receiptID        *uuid.UUID
	tuitionID        *uuid.UUID
	studentID        *uuid.UUID
	document         *string
	note             *string
	by               *uuid.UUID
	// Thao tác tay (xuất kho, chuyển kho) không được làm tồn âm; xuất theo phiếu thu thì được (như Laravel).
	noNegative bool
}

// errInsufficient: tồn không đủ để xuất (thông báo do nơi gọi dựng vì cần tên mặt hàng / chi nhánh).
type errInsufficient struct{ have int32 }

func (e *errInsufficient) Error() string { return fmt.Sprintf("tồn chỉ còn %d", e.have) }

// apply cộng / trừ tồn của mặt hàng tại chi nhánh và ghi nhật ký; phải chạy trong giao dịch (q từ withTx).
func (s *Service) apply(ctx context.Context, q *inventorydb.Queries, m move) (inventorydb.OpsStockMovement, error) {
	if err := q.EnsureStockRow(ctx, inventorydb.EnsureStockRowParams{BranchID: m.branchID, ItemID: m.itemID}); err != nil {
		return inventorydb.OpsStockMovement{}, err
	}
	cur, err := q.LockStock(ctx, inventorydb.LockStockParams{BranchID: m.branchID, ItemID: m.itemID})
	if err != nil {
		return inventorydb.OpsStockMovement{}, err
	}
	next := cur + m.change
	if m.noNegative && next < 0 {
		return inventorydb.OpsStockMovement{}, &errInsufficient{have: cur}
	}
	if err := q.SetStock(ctx, inventorydb.SetStockParams{BranchID: m.branchID, ItemID: m.itemID, Quantity: next}); err != nil {
		return inventorydb.OpsStockMovement{}, err
	}
	mv, err := q.InsertMovement(ctx, inventorydb.InsertMovementParams{
		BranchID: m.branchID, ItemID: m.itemID, Type: m.typ, Source: m.source, QuantityChange: m.change, BalanceAfter: next,
		TransferID: m.transferID, CounterpartBranchID: m.counterpart, ReceiptID: m.receiptID, TuitionID: m.tuitionID,
		StudentID: m.studentID, DocumentNumber: m.document, Note: m.note, CreatedBy: m.by,
	})
	if err != nil {
		return inventorydb.OpsStockMovement{}, err
	}
	// TODO(task): hết / âm kho sau một lần xuất (balance_after <= 0, quantity_change < 0) → giao Admin việc
	// "Nhập bù sách: <mặt hàng> — <chi nhánh>" (work_tasks.kind = merchandise_restock, hạn SLA stock.restock_task),
	// mỗi chi nhánh + mặt hàng một việc đang mở; tồn lên trên 0 → việc tự hoàn thành
	// ("Tự hoàn thành: chi nhánh đã nhập kho, tồn hiện tại <n>."). Xem App\Services\Merchandise\RestockTaskService.
	// TODO(notify): báo Admin "Việc mới: Nhập bù sách: ..." khi tạo việc trên.
	return mv, nil
}

// RecordInput: xuất / hoàn kho từ module khác (tài chính: duyệt phiếu thu, hủy hóa đơn).
type RecordInput struct {
	ItemID, BranchID uuid.UUID
	Type             string // sale | return
	Quantity         int32  // số dương; sale trừ, return cộng
	Source           string // contract | surcharge
	ReceiptID        *uuid.UUID
	TuitionID        *uuid.UUID
	StudentID        *uuid.UUID
	DocumentNumber   string
	Note             string
	By               *uuid.UUID
}

// Record ghi xuất bán / hoàn kho trong giao dịch của module gọi. Kho được phép âm khi xuất bán (đã giao sách mà
// chưa nhập kho): màn lập / duyệt phiếu cảnh báo thay vì chặn thu tiền.
// TODO(finance): module tài chính gọi khi phiếu thu được duyệt / hủy hóa đơn (StockService::syncReceipt).
func (s *Service) Record(ctx context.Context, tx pgx.Tx, in RecordInput) (inventorydb.OpsStockMovement, error) {
	if in.Quantity <= 0 || (in.Type != TypeSale && in.Type != TypeReturn) {
		return inventorydb.OpsStockMovement{}, fmt.Errorf("inventory.Record: dữ liệu không hợp lệ %+v", in)
	}
	change := in.Quantity
	if in.Type == TypeSale {
		change = -change
	}
	var source *string
	if in.Source == "contract" || in.Source == "surcharge" {
		source = &in.Source
	}
	return s.apply(ctx, s.q.WithTx(tx), move{
		itemID: in.ItemID, branchID: in.BranchID, change: change, typ: in.Type, source: source,
		receiptID: in.ReceiptID, tuitionID: in.TuitionID, studentID: in.StudentID,
		document: optText(&in.DocumentNumber), note: optText(&in.Note), by: in.By,
	})
}

// ───────────────────────── Xem tồn kho ─────────────────────────

type StockRow struct {
	ID            uuid.UUID `json:"id"`
	Code          string    `json:"code"`
	Name          string    `json:"name"`
	Unit          string    `json:"unit"`
	CategoryLabel string    `json:"category_label"`
	CategoryIcon  string    `json:"category_icon"`
	IsActive      bool      `json:"is_active"`
	Quantity      int32     `json:"quantity"`
	Level         string    `json:"level"`
	Legacy        int32     `json:"legacy"`
}

type Movement struct {
	ID              uuid.UUID  `json:"id"`
	CreatedAt       time.Time  `json:"created_at"`
	ItemID          uuid.UUID  `json:"item_id"`
	ItemName        string     `json:"item_name"`
	ItemCode        string     `json:"item_code"`
	Type            string     `json:"type"`
	TypeLabel       string     `json:"type_label"`
	Change          int32      `json:"change"`
	Balance         int32      `json:"balance"`
	DocumentNumber  *string    `json:"document_number"`
	ReceiptID       *uuid.UUID `json:"receipt_id"`
	StudentID       *uuid.UUID `json:"student_id"`
	CounterpartName *string    `json:"counterpart_branch"`
	UserName        *string    `json:"user_name"`
	Note            *string    `json:"note"`
}

type StockFilter struct {
	BranchID *uuid.UUID
	Level    string
	Search   string
	Category string
}

type StockOverview struct {
	Branches     []BranchRef       `json:"branches"`
	Branch       *BranchRef        `json:"branch"`
	Rows         []StockRow        `json:"rows"`
	Counts       map[string]int    `json:"counts"`
	LegacyTotal  int64             `json:"legacy_total"`
	Movements    []Movement        `json:"movements"`
	Categories   []Category        `json:"categories"`
	Filters      map[string]any    `json:"filters"`
	LowThreshold int               `json:"low_threshold"`
	CanManage    bool              `json:"can_manage"`
	TypeLabels   map[string]string `json:"type_labels"`
}

// scopeBranches: chi nhánh trong phạm vi tồn kho của người dùng.
func (s *Service) scopeBranches(ctx context.Context, actor *iam.Access) ([]BranchRef, error) {
	limit := actor.BranchLimit(scopeModule)
	if limit != nil && len(limit) == 0 {
		return []BranchRef{}, nil
	}
	rows, err := s.q.ListBranches(ctx, limit)
	if err != nil {
		return nil, err
	}
	out := make([]BranchRef, 0, len(rows))
	for _, b := range rows {
		out = append(out, BranchRef{ID: b.ID, Name: b.Name})
	}
	return out, nil
}

func movementRows[T any](rows []T, conv func(T) Movement) []Movement {
	out := make([]Movement, 0, len(rows))
	for _, r := range rows {
		out = append(out, conv(r))
	}
	return out
}

func toMovement(r inventorydb.ListMovementsRow) Movement {
	label := typeLabels[r.Type]
	if label == "" {
		label = r.Type
	}
	return Movement{
		ID: r.ID, CreatedAt: r.CreatedAt, ItemID: r.ItemID, ItemName: r.ItemName, ItemCode: r.ItemCode, Type: r.Type,
		TypeLabel: label, Change: r.QuantityChange, Balance: r.BalanceAfter, DocumentNumber: r.DocumentNumber,
		ReceiptID: r.ReceiptID, StudentID: r.StudentID, CounterpartName: r.CounterpartBranchName, UserName: r.UserName,
		Note: r.Note,
	}
}

func (s *Service) Overview(ctx context.Context, actor *iam.Access, f StockFilter) (StockOverview, error) {
	branches, err := s.scopeBranches(ctx, actor)
	if err != nil {
		return StockOverview{}, err
	}
	if !slices.Contains(levelFilters, f.Level) {
		f.Level = ""
	}
	f.Search = strings.TrimSpace(f.Search)
	out := StockOverview{
		Branches: branches, Rows: []StockRow{}, Counts: map[string]int{"negative": 0, "out": 0, "low": 0},
		Movements: []Movement{}, Categories: Categories, LowThreshold: LowThreshold,
		Filters:   map[string]any{"level": f.Level, "q": f.Search, "category": f.Category},
		CanManage: actor.Can("merchandise_stock.manage"), TypeLabels: typeLabels,
	}
	out.Branch = pickBranch(actor, branches, f.BranchID)
	if out.Branch == nil {
		return out, nil
	}
	var category *string
	if validCategory(f.Category) {
		category = &f.Category
	}
	rows, err := s.q.ListBranchStock(ctx, inventorydb.ListBranchStockParams{
		BranchID: out.Branch.ID, Search: likePattern(f.Search), Category: category,
	})
	if err != nil {
		return StockOverview{}, err
	}
	for _, r := range rows {
		// Mặt hàng ngừng bán chỉ hiện khi chi nhánh còn tồn (khác 0) để không mất dấu số tồn.
		if !r.IsActive && r.Quantity == 0 {
			continue
		}
		c := categoryOf(r.Category)
		row := StockRow{
			ID: r.ID, Code: r.Code, Name: r.Name, Unit: r.Unit, CategoryLabel: c.Label, CategoryIcon: c.Icon,
			IsActive: r.IsActive, Quantity: r.Quantity, Level: Level(r.Quantity), Legacy: max(0, r.UnallocatedQuantity),
		}
		if _, ok := out.Counts[row.Level]; ok {
			out.Counts[row.Level]++
		}
		out.LegacyTotal += int64(row.Legacy)
		if f.Level == "" || row.Level == f.Level {
			out.Rows = append(out.Rows, row)
		}
	}
	mv, err := s.q.ListMovements(ctx, inventorydb.ListMovementsParams{BranchID: out.Branch.ID, RowLimit: 15})
	if err != nil {
		return StockOverview{}, err
	}
	out.Movements = movementRows(mv, toMovement)
	return out, nil
}

type StockHistory struct {
	Item      Item       `json:"item"`
	Branch    BranchRef  `json:"branch"`
	Quantity  int32      `json:"quantity"`
	Movements []Movement `json:"movements"`
}

// History: 100 lần xuất nhập gần nhất của một mặt hàng tại chi nhánh (kể cả mặt hàng đã xóa).
func (s *Service) History(ctx context.Context, actor *iam.Access, itemID uuid.UUID, branchID *uuid.UUID) (StockHistory, error) {
	branches, err := s.scopeBranches(ctx, actor)
	if err != nil {
		return StockHistory{}, err
	}
	branch := pickBranch(actor, branches, branchID)
	if branch == nil {
		return StockHistory{}, ErrNoBranch
	}
	it, err := s.q.GetItemAny(ctx, itemID)
	if isNoRows(err) {
		return StockHistory{}, ErrItemNotFound
	}
	if err != nil {
		return StockHistory{}, err
	}
	qty, err := s.q.StockQuantity(ctx, inventorydb.StockQuantityParams{BranchID: branch.ID, ItemID: itemID})
	if err != nil {
		return StockHistory{}, err
	}
	mv, err := s.q.ListMovements(ctx, inventorydb.ListMovementsParams{BranchID: branch.ID, ItemID: &itemID, RowLimit: 100})
	if err != nil {
		return StockHistory{}, err
	}
	return StockHistory{Item: toItem(it), Branch: *branch, Quantity: qty, Movements: movementRows(mv, toMovement)}, nil
}

// ───────────────────────── Nhập kho / kiểm kê / xuất kho / chuyển kho ─────────────────────────

type FormItem struct {
	Value  string `json:"value"`
	Label  string `json:"label"`
	Unit   string `json:"unit"`
	Legacy int32  `json:"legacy"`
}

type StockFormOptions struct {
	Items []FormItem `json:"items"`
	// Chi nhánh được thao tác (trong phạm vi)
	Branches []Option `json:"branches"`
	// Chi nhánh nhận khi chuyển kho (mọi chi nhánh đang hoạt động)
	TransferBranches []Option `json:"transfer_branches"`
	// [chi nhánh][mặt hàng] = số tồn (chỉ các dòng đã có)
	StockByBranch map[string]map[string]int32 `json:"stock_by_branch"`
}

func (s *Service) FormOptions(ctx context.Context, actor *iam.Access) (StockFormOptions, error) {
	branches, err := s.scopeBranches(ctx, actor)
	if err != nil {
		return StockFormOptions{}, err
	}
	if len(branches) == 0 {
		return StockFormOptions{}, ErrNoBranch
	}
	items, err := s.q.ListActiveItems(ctx)
	if err != nil {
		return StockFormOptions{}, err
	}
	all, err := s.q.ListActiveBranches(ctx, nil)
	if err != nil {
		return StockFormOptions{}, err
	}
	ids := make([]uuid.UUID, 0, len(branches))
	out := StockFormOptions{
		Items: make([]FormItem, 0, len(items)), Branches: make([]Option, 0, len(branches)),
		TransferBranches: make([]Option, 0, len(all)), StockByBranch: map[string]map[string]int32{},
	}
	for _, it := range items {
		out.Items = append(out.Items, FormItem{
			Value: it.ID.String(), Label: fmt.Sprintf("[%s] %s", it.Code, it.Name), Unit: it.Unit,
			Legacy: max(0, it.UnallocatedQuantity),
		})
	}
	for _, b := range branches {
		ids = append(ids, b.ID)
		out.Branches = append(out.Branches, Option{b.ID.String(), b.Name})
	}
	for _, b := range all {
		out.TransferBranches = append(out.TransferBranches, Option{b.ID.String(), b.Name})
	}
	stock, err := s.q.StockByBranches(ctx, ids)
	if err != nil {
		return StockFormOptions{}, err
	}
	for _, st := range stock {
		k := st.BranchID.String()
		if out.StockByBranch[k] == nil {
			out.StockByBranch[k] = map[string]int32{}
		}
		out.StockByBranch[k][st.ItemID.String()] = st.Quantity
	}
	return out, nil
}

type MoveInput struct {
	Type       string     `json:"type"` // import | count | issue | transfer
	ItemID     *uuid.UUID `json:"item_id"`
	BranchID   *uuid.UUID `json:"branch_id"`
	Quantity   *int32     `json:"quantity"`
	FromLegacy bool       `json:"from_legacy"`
	Note       *string    `json:"note"`
	// Chuyển kho: chi nhánh nhận
	ToBranchID *uuid.UUID `json:"to_branch_id"`
	// Xuất kho cho học viên (tùy chọn)
	StudentID *uuid.UUID `json:"student_id"`
}

type MoveResult struct {
	Message   string     `json:"message"`
	Movements []Movement `json:"movements"`
	BranchID  uuid.UUID  `json:"branch_id"`
}

func (s *Service) Move(ctx context.Context, actor *iam.Access, in MoveInput) (MoveResult, error) {
	in.Note = optText(in.Note)
	switch {
	case !slices.Contains([]string{TypeImport, TypeCount, TypeIssue, "transfer"}, in.Type):
		return MoveResult{}, iam.Invalid("Loại thao tác kho không hợp lệ.")
	case in.ItemID == nil:
		return MoveResult{}, iam.Invalid("Chọn mặt hàng.")
	case in.BranchID == nil:
		return MoveResult{}, iam.Invalid("Chọn chi nhánh.")
	case in.Quantity == nil:
		return MoveResult{}, iam.Invalid("Nhập số lượng.")
	case *in.Quantity < 0 || *in.Quantity > 100000:
		return MoveResult{}, iam.Invalid("Số lượng phải từ 0 đến 100.000.")
	case tooLong(in.Note, 500):
		return MoveResult{}, iam.Invalid("Ghi chú không được vượt quá 500 ký tự.")
	}
	item, err := s.q.GetItem(ctx, *in.ItemID)
	if isNoRows(err) {
		return MoveResult{}, iam.Invalid("Mặt hàng không tồn tại.")
	}
	if err != nil {
		return MoveResult{}, err
	}
	branch, err := s.q.GetBranch(ctx, *in.BranchID)
	if isNoRows(err) {
		return MoveResult{}, iam.Invalid("Chi nhánh không tồn tại.")
	}
	if err != nil {
		return MoveResult{}, err
	}
	if !actor.CoversBranch(scopeModule, &branch.ID) {
		return MoveResult{}, ErrOutsideBranch
	}
	qty := *in.Quantity
	var (
		msg   string
		moved []inventorydb.OpsStockMovement
	)
	insufficient := func(err error) error {
		var e *errInsufficient
		if errors.As(err, &e) {
			return iam.Invalid(fmt.Sprintf("Tồn %s tại %s chỉ còn %d %s, không xuất được %d.", item.Name, branch.Name, e.have, item.Unit, qty))
		}
		return err
	}

	switch in.Type {
	case TypeCount:
		// Kiểm kê: đặt số tồn thực tế, ghi phần chênh lệch (khớp thì không ghi gì).
		err = s.withTx(ctx, func(q *inventorydb.Queries) error {
			if err := q.EnsureStockRow(ctx, inventorydb.EnsureStockRowParams{BranchID: branch.ID, ItemID: item.ID}); err != nil {
				return err
			}
			cur, err := q.LockStock(ctx, inventorydb.LockStockParams{BranchID: branch.ID, ItemID: item.ID})
			if err != nil || cur == qty {
				return err
			}
			mv, err := s.apply(ctx, q, move{itemID: item.ID, branchID: branch.ID, change: qty - cur, typ: TypeCount, note: in.Note, by: &actor.UserID})
			moved = append(moved, mv)
			return err
		})
		if err == nil {
			if len(moved) == 0 {
				msg = fmt.Sprintf("Số tồn %s tại %s đã khớp (%d), không cần điều chỉnh.", item.Name, branch.Name, qty)
			} else {
				msg = fmt.Sprintf("Đã kiểm kê %s tại %s: tồn %d (%+d).", item.Name, branch.Name, qty, moved[0].QuantityChange)
			}
		}

	case TypeImport:
		if qty < 1 {
			return MoveResult{}, iam.Invalid("Số lượng nhập phải lớn hơn 0.")
		}
		err = s.withTx(ctx, func(q *inventorydb.Queries) error {
			note := in.Note
			if in.FromLegacy {
				locked, err := q.LockItem(ctx, item.ID)
				if err != nil {
					return err
				}
				if qty > locked.UnallocatedQuantity {
					return iam.Invalid(fmt.Sprintf("Tồn cũ chưa phân chi nhánh của %s chỉ còn %d.", item.Name, locked.UnallocatedQuantity))
				}
				if err := q.TakeUnallocated(ctx, inventorydb.TakeUnallocatedParams{ID: item.ID, Quantity: qty}); err != nil {
					return err
				}
				text := "Phân bổ từ tồn cũ chưa phân chi nhánh."
				if note != nil {
					text += " " + *note
				}
				note = &text
			}
			mv, err := s.apply(ctx, q, move{itemID: item.ID, branchID: branch.ID, change: qty, typ: TypeImport, note: note, by: &actor.UserID})
			moved = append(moved, mv)
			return err
		})
		msg = fmt.Sprintf("Đã nhập %d %s %s vào kho %s.", qty, item.Unit, item.Name, branch.Name)

	case TypeIssue:
		if qty < 1 {
			return MoveResult{}, iam.Invalid("Số lượng xuất phải lớn hơn 0.")
		}
		if in.StudentID != nil {
			if ok, err := s.q.StudentExists(ctx, *in.StudentID); err != nil {
				return MoveResult{}, err
			} else if !ok {
				return MoveResult{}, iam.Invalid("Học viên đã chọn không tồn tại.")
			}
		}
		err = s.withTx(ctx, func(q *inventorydb.Queries) error {
			mv, err := s.apply(ctx, q, move{
				itemID: item.ID, branchID: branch.ID, change: -qty, typ: TypeIssue, studentID: in.StudentID,
				note: in.Note, by: &actor.UserID, noNegative: true,
			})
			moved = append(moved, mv)
			return insufficient(err)
		})
		msg = fmt.Sprintf("Đã xuất %d %s %s khỏi kho %s.", qty, item.Unit, item.Name, branch.Name)

	case "transfer":
		if qty < 1 {
			return MoveResult{}, iam.Invalid("Số lượng chuyển phải lớn hơn 0.")
		}
		if in.ToBranchID == nil {
			return MoveResult{}, iam.Invalid("Chọn chi nhánh nhận.")
		}
		if *in.ToBranchID == branch.ID {
			return MoveResult{}, iam.Invalid("Chi nhánh nhận phải khác chi nhánh chuyển.")
		}
		to, err2 := s.q.GetBranch(ctx, *in.ToBranchID)
		if isNoRows(err2) || (err2 == nil && !to.IsActive) {
			return MoveResult{}, iam.Invalid("Chi nhánh nhận không tồn tại hoặc đã ngừng hoạt động.")
		}
		if err2 != nil {
			return MoveResult{}, err2
		}
		transferID := uuid.Must(uuid.NewV7())
		out := move{
			itemID: item.ID, branchID: branch.ID, change: -qty, typ: TypeTransferOut, transferID: &transferID,
			counterpart: &to.ID, note: in.Note, by: &actor.UserID, noNegative: true,
		}
		inn := move{
			itemID: item.ID, branchID: to.ID, change: qty, typ: TypeTransferIn, transferID: &transferID,
			counterpart: &branch.ID, note: in.Note, by: &actor.UserID,
		}
		err = s.withTx(ctx, func(q *inventorydb.Queries) error {
			// Khóa dòng tồn theo thứ tự chi nhánh cố định để hai lần chuyển ngược chiều không khóa chéo nhau.
			first, second := out, inn
			if bytes.Compare(to.ID[:], branch.ID[:]) < 0 {
				first, second = inn, out
			}
			for _, m := range []move{first, second} {
				if err := q.EnsureStockRow(ctx, inventorydb.EnsureStockRowParams{BranchID: m.branchID, ItemID: m.itemID}); err != nil {
					return err
				}
				if _, err := q.LockStock(ctx, inventorydb.LockStockParams{BranchID: m.branchID, ItemID: m.itemID}); err != nil {
					return err
				}
			}
			mvOut, err := s.apply(ctx, q, out)
			if err != nil {
				return insufficient(err)
			}
			mvIn, err := s.apply(ctx, q, inn)
			moved = append(moved, mvOut, mvIn)
			return err
		})
		msg = fmt.Sprintf("Đã chuyển %d %s %s từ %s sang %s.", qty, item.Unit, item.Name, branch.Name, to.Name)
	}
	if err != nil {
		return MoveResult{}, err
	}
	s.log.Info("thao tác kho", "type", in.Type, "item", item.ID, "branch", branch.ID, "quantity", qty, "by", actor.UserID)
	res := MoveResult{Message: msg, BranchID: branch.ID, Movements: []Movement{}}
	for _, m := range moved {
		res.Movements = append(res.Movements, Movement{
			ID: m.ID, CreatedAt: m.CreatedAt, ItemID: m.ItemID, ItemName: item.Name, ItemCode: item.Code, Type: m.Type,
			TypeLabel: typeLabels[m.Type], Change: m.QuantityChange, Balance: m.BalanceAfter, StudentID: m.StudentID, Note: m.Note,
		})
	}
	return res, nil
}
