package inventory

import (
	"context"
	"fmt"
	"strings"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/inventory/inventorydb"
)

// ───────────────────────── Danh mục hàng hóa & vật phẩm ─────────────────────────
// Quyền system_category.manage (như route merchandise.* của Laravel). Tồn kho đổi ở trang Tồn kho theo chi nhánh;
// thêm mới chỉ nhận tồn ban đầu của một chi nhánh, sửa không đổi số tồn.

// Category là nhóm phân loại hàng hóa (MerchandiseItem::CATEGORIES).
type Category struct {
	Value string `json:"value"`
	Label string `json:"label"`
	Icon  string `json:"icon"`
}

var Categories = []Category{
	{"book", "Sách & Giáo trình", "menu_book"},
	{"workbook", "Sách bài tập", "edit_note"},
	{"uniform", "Đồng phục học viên", "apparel"},
	{"backpack", "Balo & Túi xách", "backpack"},
	{"equipment", "Thiết bị & Giáo cụ", "devices"},
	{"gift", "Học phẩm & Quà tặng", "card_giftcard"},
	{"other", "Hàng hóa khác", "category"},
}

func categoryOf(key string) Category {
	for _, c := range Categories {
		if c.Value == key {
			return c
		}
	}
	return Categories[len(Categories)-1]
}

func validCategory(key string) bool {
	for _, c := range Categories {
		if c.Value == key {
			return true
		}
	}
	return false
}

const itemsPageSize = 15

type Item struct {
	ID            uuid.UUID `json:"id"`
	Code          string    `json:"code"`
	Name          string    `json:"name"`
	Category      string    `json:"category"`
	CategoryLabel string    `json:"category_label"`
	CategoryIcon  string    `json:"category_icon"`
	Unit          string    `json:"unit"`
	Price         int64     `json:"price"`
	CostPrice     *int64    `json:"cost_price"`
	// Tồn cũ chưa phân chi nhánh
	Unallocated int32   `json:"unallocated_quantity"`
	IsActive    bool    `json:"is_active"`
	Description *string `json:"description"`
	// Tổng tồn các chi nhánh + tồn cũ chưa phân chi nhánh (chỉ ở danh sách)
	StockQuantity *int64    `json:"stock_quantity,omitempty"`
	CreatedAt     time.Time `json:"created_at"`
}

func toItem(i inventorydb.OpsInventoryItem) Item {
	c := categoryOf(i.Category)
	return Item{
		ID: i.ID, Code: i.Code, Name: i.Name, Category: i.Category, CategoryLabel: c.Label, CategoryIcon: c.Icon,
		Unit: i.Unit, Price: i.Price, CostPrice: i.CostPrice, Unallocated: i.UnallocatedQuantity, IsActive: i.IsActive,
		Description: i.Description, CreatedAt: i.CreatedAt,
	}
}

type ItemFilter struct {
	Search   string
	Category string
	Status   string // active | inactive
	Page     int
}

type ItemMetrics struct {
	Total      int64 `json:"total"`
	Active     int64 `json:"active"`
	TotalStock int64 `json:"total_stock"`
	Books      int64 `json:"books"`
	Uniforms   int64 `json:"uniforms"`
}

type ItemList struct {
	Items      []Item      `json:"items"`
	Total      int64       `json:"total"`
	Page       int         `json:"page"`
	PageSize   int         `json:"page_size"`
	Metrics    ItemMetrics `json:"metrics"`
	Categories []Category  `json:"categories"`
	// Chi nhánh nhận tồn ban đầu ở form thêm mới
	Branches []Option `json:"branches"`
}

func (f ItemFilter) params() (search, category *string, active *bool) {
	search = likePattern(f.Search)
	if validCategory(f.Category) {
		category = &f.Category
	}
	switch f.Status {
	case "active":
		active = ptr(true)
	case "inactive":
		active = ptr(false)
	}
	return
}

func (s *Service) ListItems(ctx context.Context, f ItemFilter) (ItemList, error) {
	search, category, active := f.params()
	if f.Page < 1 {
		f.Page = 1
	}
	rows, err := s.q.ListItems(ctx, inventorydb.ListItemsParams{
		Search: search, Category: category, Active: active,
		PageLimit: itemsPageSize, PageOffset: int32((f.Page - 1) * itemsPageSize),
	})
	if err != nil {
		return ItemList{}, err
	}
	total, err := s.q.CountItems(ctx, inventorydb.CountItemsParams{Search: search, Category: category, Active: active})
	if err != nil {
		return ItemList{}, err
	}
	m, err := s.q.ItemMetrics(ctx)
	if err != nil {
		return ItemList{}, err
	}
	out := ItemList{
		Items: make([]Item, 0, len(rows)), Total: total, Page: f.Page, PageSize: itemsPageSize, Categories: Categories,
		Metrics: ItemMetrics{Total: m.Total, Active: m.Active, TotalStock: m.TotalStock, Books: m.Books, Uniforms: m.Uniforms},
	}
	branches, err := s.q.ListBranches(ctx, nil)
	if err != nil {
		return ItemList{}, err
	}
	out.Branches = make([]Option, 0, len(branches))
	for _, b := range branches {
		out.Branches = append(out.Branches, Option{b.ID.String(), b.Name})
	}
	for _, r := range rows {
		it := toItem(inventorydb.OpsInventoryItem{
			ID: r.ID, Code: r.Code, Name: r.Name, Category: r.Category, Unit: r.Unit, Price: r.Price, CostPrice: r.CostPrice,
			UnallocatedQuantity: r.UnallocatedQuantity, IsActive: r.IsActive, Description: r.Description, CreatedAt: r.CreatedAt,
		})
		it.StockQuantity = ptr(r.BranchTotal + int64(max(0, r.UnallocatedQuantity)))
		out.Items = append(out.Items, it)
	}
	return out, nil
}

func (s *Service) GetItem(ctx context.Context, id uuid.UUID) (Item, error) {
	it, err := s.q.GetItem(ctx, id)
	if isNoRows(err) {
		return Item{}, ErrItemNotFound
	}
	if err != nil {
		return Item{}, err
	}
	return toItem(it), nil
}

// ActiveItems: mặt hàng đang kinh doanh (chọn khi lập hóa đơn / phiếu thu; MerchandiseItemController::apiList).
func (s *Service) ActiveItems(ctx context.Context) ([]Item, error) {
	rows, err := s.q.ListActiveItems(ctx)
	if err != nil {
		return nil, err
	}
	out := make([]Item, 0, len(rows))
	for _, r := range rows {
		out = append(out, toItem(r))
	}
	return out, nil
}

type ItemInput struct {
	Code        string  `json:"code"`
	Name        string  `json:"name"`
	Category    string  `json:"category"`
	Unit        string  `json:"unit"`
	Price       *int64  `json:"price"`
	CostPrice   *int64  `json:"cost_price"`
	IsActive    *bool   `json:"is_active"`
	Description *string `json:"description"`
	// Chỉ khi thêm mới: tồn ban đầu và chi nhánh nhận (không chọn → tồn cũ chưa phân chi nhánh)
	StockQuantity *int32     `json:"stock_quantity"`
	StockBranchID *uuid.UUID `json:"stock_branch_id"`
}

func (in *ItemInput) normalize(creating bool) error {
	in.Code = strings.TrimSpace(in.Code)
	in.Name = strings.TrimSpace(in.Name)
	in.Unit = strings.TrimSpace(in.Unit)
	in.Description = optText(in.Description)
	switch {
	case in.Code == "":
		return iam.Invalid("Mã hàng hóa không được để trống.")
	case tooLong(&in.Code, 50):
		return iam.Invalid("Mã hàng hóa không được vượt quá 50 ký tự.")
	case in.Name == "":
		return iam.Invalid("Tên hàng hóa không được để trống.")
	case tooLong(&in.Name, 255):
		return iam.Invalid("Tên hàng hóa không được vượt quá 255 ký tự.")
	case !validCategory(in.Category):
		return iam.Invalid("Vui lòng chọn nhóm phân loại hợp lệ.")
	case in.Unit == "":
		return iam.Invalid("Vui lòng nhập đơn vị tính.")
	case tooLong(&in.Unit, 50):
		return iam.Invalid("Đơn vị tính không được vượt quá 50 ký tự.")
	case in.Price == nil:
		return iam.Invalid("Đơn giá niêm yết là bắt buộc.")
	case *in.Price < 0:
		return iam.Invalid("Đơn giá niêm yết không được âm.")
	case in.CostPrice != nil && *in.CostPrice < 0:
		return iam.Invalid("Giá vốn không được âm.")
	case tooLong(in.Description, 1000):
		return iam.Invalid("Mô tả không được vượt quá 1000 ký tự.")
	}
	if creating && in.StockQuantity != nil && (*in.StockQuantity < 0 || *in.StockQuantity > 100000) {
		return iam.Invalid("Số lượng tồn ban đầu phải từ 0 đến 100.000.")
	}
	return nil
}

func (s *Service) CreateItem(ctx context.Context, actor *iam.Access, in ItemInput) (Item, string, error) {
	if err := in.normalize(true); err != nil {
		return Item{}, "", err
	}
	initial := int32(0)
	if in.StockQuantity != nil {
		initial = *in.StockQuantity
	}
	var branch *inventorydb.GetBranchRow
	if in.StockBranchID != nil {
		b, err := s.q.GetBranch(ctx, *in.StockBranchID)
		if isNoRows(err) {
			return Item{}, "", iam.Invalid("Chi nhánh nhập tồn ban đầu không tồn tại.")
		}
		if err != nil {
			return Item{}, "", err
		}
		branch = &b
	}
	var item inventorydb.OpsInventoryItem
	err := s.withTx(ctx, func(q *inventorydb.Queries) error {
		// Có chọn chi nhánh → tồn ban đầu vào kho chi nhánh đó; không chọn → tồn chưa phân chi nhánh.
		unallocated := initial
		if branch != nil {
			unallocated = 0
		}
		var err error
		item, err = q.CreateItem(ctx, inventorydb.CreateItemParams{
			Code: in.Code, Name: in.Name, Category: in.Category, Unit: in.Unit, Price: *in.Price, CostPrice: in.CostPrice,
			UnallocatedQuantity: unallocated, IsActive: in.IsActive == nil || *in.IsActive, Description: in.Description,
			CreatedBy: &actor.UserID,
		})
		if isUnique(err) {
			return ErrCodeTaken
		}
		if err != nil {
			return err
		}
		if branch != nil && initial > 0 {
			_, err = s.apply(ctx, q, move{
				itemID: item.ID, branchID: branch.ID, change: initial, typ: TypeOpening,
				note: ptr("Tồn ban đầu khi thêm mặt hàng"), by: &actor.UserID,
			})
		}
		return err
	})
	if err != nil {
		return Item{}, "", err
	}
	s.log.Info("tạo hàng hóa", "item", item.ID, "code", item.Code, "by", actor.UserID)
	// TODO(audit): "Tạo mới hàng hóa: <tên>"
	return toItem(item), fmt.Sprintf("Đã thêm thành công mặt hàng [%s] %s!", item.Code, item.Name), nil
}

func (s *Service) UpdateItem(ctx context.Context, actor *iam.Access, id uuid.UUID, in ItemInput) (Item, string, error) {
	if err := in.normalize(false); err != nil {
		return Item{}, "", err
	}
	// Số tồn đổi qua trang Tồn kho theo chi nhánh (nhập kho / kiểm kê), không sửa ở đây.
	item, err := s.q.UpdateItem(ctx, inventorydb.UpdateItemParams{
		ID: id, Code: in.Code, Name: in.Name, Category: in.Category, Unit: in.Unit, Price: *in.Price, CostPrice: in.CostPrice,
		IsActive: in.IsActive == nil || *in.IsActive, Description: in.Description,
	})
	switch {
	case isUnique(err):
		return Item{}, "", iam.Invalid("Mã hàng hóa này đã tồn tại.")
	case isNoRows(err):
		return Item{}, "", ErrItemNotFound
	case err != nil:
		return Item{}, "", err
	}
	s.log.Info("cập nhật hàng hóa", "item", id, "by", actor.UserID)
	// TODO(audit): "Cập nhật hàng hóa: <tên>"
	return toItem(item), fmt.Sprintf("Đã cập nhật thông tin mặt hàng [%s] %s!", item.Code, item.Name), nil
}

func (s *Service) ToggleItem(ctx context.Context, actor *iam.Access, id uuid.UUID) (Item, string, error) {
	item, err := s.q.ToggleItem(ctx, id)
	if isNoRows(err) {
		return Item{}, "", ErrItemNotFound
	}
	if err != nil {
		return Item{}, "", err
	}
	s.log.Info("bật/tắt kinh doanh hàng hóa", "item", id, "active", item.IsActive, "by", actor.UserID)
	status := "Tạm ngừng kinh doanh"
	if item.IsActive {
		status = "Kích hoạt kinh doanh"
	}
	return toItem(item), fmt.Sprintf("Đã %s mặt hàng %s!", status, item.Name), nil
}

// DeleteItem xóa mềm: không còn chọn được khi lập hóa đơn; nhật ký kho cũ vẫn giữ.
func (s *Service) DeleteItem(ctx context.Context, actor *iam.Access, id uuid.UUID) (string, error) {
	item, err := s.q.SoftDeleteItem(ctx, id)
	if isNoRows(err) {
		return "", ErrItemNotFound
	}
	if err != nil {
		return "", err
	}
	s.log.Info("xóa hàng hóa", "item", id, "by", actor.UserID)
	// TODO(audit): "Xóa hàng hóa: <tên>"
	return fmt.Sprintf("Đã xóa mặt hàng %s vào thùng rác.", item.Name), nil
}
