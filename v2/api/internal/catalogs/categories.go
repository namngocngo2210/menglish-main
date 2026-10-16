package catalogs

import (
	"context"
	"fmt"
	"regexp"
	"strconv"
	"strings"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/catalogs/catalogsdb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

// Danh mục hệ thống (SystemCategoryController): 4 nhóm (tab), thêm / sửa, ngừng sử dụng / kích hoạt lại (không xóa).

const categoriesPageSize = 20

var errCategoryNotFound = notFound("Không tìm thấy danh mục.")

// CategoryTypes: nhóm danh mục theo đúng các tab, kèm tiền tố mã gợi ý (SRC_06).
var CategoryTypes = []struct {
	Value, Label, Prefix string
}{
	{"lead_source", "Nguồn khách hàng", "SRC"},
	{"lost_reason", "Lý do không chốt", "LOST"},
	{"position", "Chức vụ", "POS"},
	{"fine_level", "Mức phạt", "FINE"},
}

func categoryPrefix(t string) (string, bool) {
	for _, c := range CategoryTypes {
		if c.Value == t {
			return c.Prefix, true
		}
	}
	return "", false
}

type Category struct {
	ID        uuid.UUID `json:"id"`
	Type      string    `json:"type"`
	Code      string    `json:"code"`
	Name      string    `json:"name"`
	SortOrder int32     `json:"sort_order"`
	IsActive  bool      `json:"is_active"`
}

type CategoryList struct {
	Page[Category]
	Type  string   `json:"type"`
	Types []Option `json:"types"`
	// Gợi ý cho form Thêm: mã kế tiếp của nhóm (tính cả bản ghi đã ngừng) và thứ tự kế tiếp
	SuggestedCode string `json:"suggested_code"`
	NextOrder     int32  `json:"next_order"`
}

// validType: nhóm không hợp lệ thì về "Nguồn khách hàng" như Laravel.
func validType(t string) string {
	if _, ok := categoryPrefix(t); ok {
		return t
	}
	return "lead_source"
}

func (s *Service) ListCategories(ctx context.Context, typ, search string, page int) (CategoryList, error) {
	typ = validType(typ)
	page, lim, off := limits(page, categoriesPageSize)
	rows, err := s.q.ListCategories(ctx, catalogsdb.ListCategoriesParams{Type: typ, Search: likeArg(search), Lim: lim, Off: off})
	if err != nil {
		return CategoryList{}, err
	}
	var total int64
	items := make([]Category, 0, len(rows))
	for _, r := range rows {
		total = r.Total
		items = append(items, Category{ID: r.ID, Type: r.Type, Code: r.Code, Name: r.Name, SortOrder: r.SortOrder, IsActive: r.IsActive})
	}
	code, err := s.suggestCode(ctx, typ)
	if err != nil {
		return CategoryList{}, err
	}
	maxSort, err := s.q.MaxCategorySort(ctx, typ)
	if err != nil {
		return CategoryList{}, err
	}
	types := make([]Option, 0, len(CategoryTypes))
	for _, t := range CategoryTypes {
		types = append(types, Option{t.Value, t.Label})
	}
	return CategoryList{Page: newPage(items, total, page, categoriesPageSize), Type: typ, Types: types,
		SuggestedCode: code, NextOrder: maxSort + 1}, nil
}

// suggestCode: TIỀNTỐ_NN kế tiếp của nhóm (tính cả bản ghi đã ngừng / đã xóa mềm).
func (s *Service) suggestCode(ctx context.Context, typ string) (string, error) {
	prefix, _ := categoryPrefix(typ)
	codes, err := s.q.CategoryCodes(ctx, typ)
	if err != nil {
		return "", err
	}
	re := regexp.MustCompile(`^` + regexp.QuoteMeta(prefix) + `_(\d+)$`)
	maxN := 0
	for _, c := range codes {
		if m := re.FindStringSubmatch(c); m != nil {
			if n, _ := strconv.Atoi(m[1]); n > maxN {
				maxN = n
			}
		}
	}
	return fmt.Sprintf("%s_%02d", prefix, maxN+1), nil
}

// ActiveCategories: danh mục đang dùng của một nhóm (cho module khác: nguồn khách, lý do không chốt...).
func (s *Service) ActiveCategories(ctx context.Context, typ string) ([]Category, error) {
	rows, err := s.q.ActiveCategories(ctx, typ)
	if err != nil {
		return nil, err
	}
	out := make([]Category, 0, len(rows))
	for _, r := range rows {
		out = append(out, Category{ID: r.ID, Type: r.Type, Code: r.Code, Name: r.Name, SortOrder: r.SortOrder, IsActive: r.IsActive})
	}
	return out, nil
}

type CategoryInput struct {
	Type      string `json:"type"`
	Code      string `json:"code"`
	Name      string `json:"name"`
	SortOrder *int32 `json:"sort_order"`
	IsActive  *bool  `json:"is_active"`
}

func (in *CategoryInput) validate() error {
	in.Code = strings.TrimSpace(in.Code)
	in.Name = strings.TrimSpace(in.Name)
	if _, ok := categoryPrefix(in.Type); !ok {
		return iam.Invalid("Nhóm danh mục không hợp lệ.")
	}
	switch {
	case in.Code == "":
		return iam.Invalid("Vui lòng nhập mã danh mục.")
	case tooLong(in.Code, 50):
		return iam.Invalid("Mã danh mục không được vượt quá 50 ký tự.")
	case in.Name == "":
		return iam.Invalid("Vui lòng nhập tên danh mục.")
	case tooLong(in.Name, 255):
		return iam.Invalid("Tên danh mục không được vượt quá 255 ký tự.")
	case in.SortOrder != nil && *in.SortOrder < 0:
		return iam.Invalid("Thứ tự hiển thị phải từ 0 trở lên.")
	}
	return nil
}

func categoryErr(err error) error {
	if isUnique(err, "system_categories_type_code_key") {
		return iam.Invalid("Mã danh mục đã tồn tại trong nhóm này, vui lòng chọn mã khác.")
	}
	return err
}

func (s *Service) CreateCategory(ctx context.Context, actor *iam.Access, in CategoryInput) (Category, string, error) {
	if err := in.validate(); err != nil {
		return Category{}, "", err
	}
	sort := in.SortOrder
	if sort == nil {
		m, err := s.q.MaxCategorySort(ctx, in.Type)
		if err != nil {
			return Category{}, "", err
		}
		m++
		sort = &m
	}
	r, err := s.q.CreateCategory(ctx, catalogsdb.CreateCategoryParams{
		Type: in.Type, Code: in.Code, Name: in.Name, SortOrder: *sort, IsActive: in.IsActive == nil || *in.IsActive, CreatedBy: &actor.UserID,
	})
	if err != nil {
		return Category{}, "", categoryErr(err)
	}
	// TODO(audit): "Tạo danh mục hệ thống"
	s.log.Info("system category created", "category_id", r.ID, "type", r.Type, "by", actor.UserID)
	c := Category{ID: r.ID, Type: r.Type, Code: r.Code, Name: r.Name, SortOrder: r.SortOrder, IsActive: r.IsActive}
	return c, fmt.Sprintf("Đã thêm danh mục \"%s\".", c.Name), nil
}

func (s *Service) UpdateCategory(ctx context.Context, actor *iam.Access, id uuid.UUID, in CategoryInput) (Category, string, error) {
	old, err := s.q.GetCategory(ctx, id)
	if isNoRows(err) {
		return Category{}, "", errCategoryNotFound
	}
	if err != nil {
		return Category{}, "", err
	}
	if err := in.validate(); err != nil {
		return Category{}, "", err
	}
	sort, active := old.SortOrder, old.IsActive
	if in.SortOrder != nil {
		sort = *in.SortOrder
	}
	if in.IsActive != nil {
		active = *in.IsActive
	}
	r, err := s.q.UpdateCategory(ctx, catalogsdb.UpdateCategoryParams{ID: id, Type: in.Type, Code: in.Code, Name: in.Name, SortOrder: sort, IsActive: active})
	if err != nil {
		return Category{}, "", categoryErr(err)
	}
	// TODO(audit): "Cập nhật danh mục hệ thống"
	s.log.Info("system category updated", "category_id", id, "by", actor.UserID)
	return Category{ID: r.ID, Type: r.Type, Code: r.Code, Name: r.Name, SortOrder: r.SortOrder, IsActive: r.IsActive}, "Đã cập nhật danh mục.", nil
}

// SetCategoryActive: ngừng sử dụng (thay cho xóa) hoặc kích hoạt lại.
func (s *Service) SetCategoryActive(ctx context.Context, actor *iam.Access, id uuid.UUID, active bool) (string, error) {
	old, err := s.q.GetCategory(ctx, id)
	if isNoRows(err) {
		return "", errCategoryNotFound
	}
	if err != nil {
		return "", err
	}
	if active && old.IsActive {
		return "Danh mục đang được sử dụng.", nil
	}
	if _, err := s.q.SetCategoryActive(ctx, catalogsdb.SetCategoryActiveParams{ID: id, IsActive: active}); err != nil {
		return "", err
	}
	s.log.Info("system category status changed", "category_id", id, "active", active, "by", actor.UserID)
	if active {
		// TODO(audit): "Kích hoạt lại danh mục hệ thống"
		return fmt.Sprintf("Đã kích hoạt lại danh mục \"%s\".", old.Name), nil
	}
	// TODO(audit): "Ngừng sử dụng danh mục hệ thống"
	return fmt.Sprintf("Đã ngừng sử dụng \"%s\".", old.Name), nil
}
