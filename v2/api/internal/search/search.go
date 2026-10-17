// Package search là tìm kiếm chung trên topbar (Laravel GlobalSearchController): mỗi module đăng ký một Searcher
// (nhóm kết quả) vào Registry; một nhóm chỉ chạy khi người dùng có quyền của nhóm đó và Searcher tự lọc theo phạm vi
// dữ liệu của người dùng (như visibleTo của Laravel) nên không lộ bản ghi ngoài phạm vi. Tìm màn hình theo tên chạy ở
// giao diện (searchScreens trong web/src/nav.ts).
package search

import (
	"context"
	"fmt"
	"log/slog"
	"net/http"
	"regexp"
	"slices"
	"strconv"
	"strings"
	"unicode/utf8"

	"github.com/go-chi/chi/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/httpx"
)

const (
	MinTermLen   = 2   // như mb_strlen($term) >= 2
	MaxTermLen   = 100 // chặn từ khóa dài bất thường
	DefaultLimit = 20  // GlobalSearchController::LIMIT
)

// Query: từ khóa đã chuẩn hóa cho Searcher.
type Query struct {
	Term string // đã bỏ khoảng trắng hai đầu
	// Like: mẫu ILIKE "%term%" đã thoát %, _ và \
	Like string
	// Phone: mẫu ILIKE "%chữ số%" khi từ khóa có ít nhất 3 chữ số, rỗng nếu không (tìm theo SĐT)
	Phone string
	Limit int
}

// Hit: một kết quả.
type Hit struct {
	ID       string   `json:"id"`
	Title    string   `json:"title"`
	Code     string   `json:"code,omitempty"`
	Subtitle string   `json:"subtitle,omitempty"`
	Meta     []string `json:"meta,omitempty"` // vd. trạng thái, chi nhánh
	Link     string   `json:"link"`           // đường dẫn trong ứng dụng
}

// Searcher: một nhóm kết quả. Perms: cần ít nhất một quyền; Search phải tự giới hạn theo phạm vi của actor.
type Searcher struct {
	Key    string // vd. "users"
	Label  string // vd. "Người dùng"
	Icon   string // Material Symbols
	Perms  []string
	Search func(ctx context.Context, actor *iam.Access, q Query) ([]Hit, error)
}

// Registry giữ các Searcher theo thứ tự đăng ký (thứ tự nhóm trên màn hình).
type Registry struct {
	searchers []Searcher
}

func NewRegistry() *Registry { return &Registry{} }

var keyRe = regexp.MustCompile(`^[a-z][a-z0-9_]{0,31}$`)

// Add đăng ký một nhóm; gọi trước khi gắn route (quyền của route tính từ các nhóm đã đăng ký). Sai khóa, trùng khóa,
// thiếu quyền hoặc quyền không có trong danh mục thì panic ngay lúc khởi động.
func (r *Registry) Add(s Searcher) *Registry {
	if !keyRe.MatchString(s.Key) || s.Label == "" || s.Search == nil || len(s.Perms) == 0 {
		panic(fmt.Sprintf("search: nhóm %q khai báo thiếu", s.Key))
	}
	if slices.ContainsFunc(r.searchers, func(x Searcher) bool { return x.Key == s.Key }) {
		panic("search: trùng nhóm " + s.Key)
	}
	for _, p := range s.Perms {
		if iam.GetCatalog().Kind(p) == "" {
			panic("search: quyền " + p + " không có trong danh mục")
		}
	}
	r.searchers = append(r.searchers, s)
	return r
}

// Perms: mọi quyền của các nhóm (người có ít nhất một quyền mới gọi được API tìm kiếm).
func (r *Registry) Perms() []string {
	var out []string
	for _, s := range r.searchers {
		for _, p := range s.Perms {
			if !slices.Contains(out, p) {
				out = append(out, p)
			}
		}
	}
	return out
}

// Group: kết quả của một nhóm.
type Group struct {
	Key   string `json:"key"`
	Label string `json:"label"`
	Icon  string `json:"icon"`
	Hits  []Hit  `json:"items"`
}

// Result: phản hồi của GET /search.
type Result struct {
	Term     string   `json:"term"`
	Searched []string `json:"searched"` // nhóm đã tìm (người dùng có quyền)
	Groups   []Group  `json:"groups"`   // chỉ nhóm có kết quả
	Total    int      `json:"total"`
}

var likeEscaper = strings.NewReplacer(`\`, `\\`, "%", `\%`, "_", `\_`)

// NewQuery chuẩn hóa từ khóa; quá dài trả lỗi 422. Từ khóa ngắn hơn MinTermLen trả ok=false (không tìm).
func NewQuery(term string, limit int) (Query, bool, error) {
	term = strings.TrimSpace(term)
	if utf8.RuneCountInString(term) > MaxTermLen {
		return Query{}, false, iam.Invalid(fmt.Sprintf("Từ khóa tìm kiếm tối đa %d ký tự.", MaxTermLen))
	}
	if limit < 1 || limit > DefaultLimit {
		limit = DefaultLimit
	}
	q := Query{Term: term, Like: "%" + likeEscaper.Replace(term) + "%", Limit: limit}
	var digits strings.Builder
	for _, c := range term {
		if c >= '0' && c <= '9' {
			digits.WriteRune(c)
		}
	}
	if digits.Len() >= 3 {
		q.Phone = "%" + digits.String() + "%"
	}
	return q, utf8.RuneCountInString(term) >= MinTermLen, nil
}

// Run chạy các nhóm người dùng có quyền.
func (r *Registry) Run(ctx context.Context, actor *iam.Access, term string, limit int) (*Result, error) {
	q, ok, err := NewQuery(term, limit)
	if err != nil {
		return nil, err
	}
	res := &Result{Term: q.Term, Searched: []string{}, Groups: []Group{}}
	if !ok {
		return res, nil
	}
	for _, s := range r.searchers {
		if !actor.CanAny(s.Perms...) {
			continue
		}
		res.Searched = append(res.Searched, s.Key)
		hits, err := s.Search(ctx, actor, q)
		if err != nil {
			return nil, fmt.Errorf("search %s: %w", s.Key, err)
		}
		if len(hits) > q.Limit {
			hits = hits[:q.Limit]
		}
		if len(hits) > 0 {
			res.Groups = append(res.Groups, Group{Key: s.Key, Label: s.Label, Icon: s.Icon, Hits: hits})
			res.Total += len(hits)
		}
	}
	return res, nil
}

// ───── HTTP ─────

type Handler struct {
	reg *Registry
	log *slog.Logger
}

func NewHandler(reg *Registry, log *slog.Logger) *Handler { return &Handler{reg: reg, log: log} }

// Routes: GET /search?q=&limit=. Laravel chỉ cần đăng nhập; v2 bắt buộc gắn quyền nên route mở cho người có quyền
// của ít nhất một nhóm (không có quyền nào thì giao diện chỉ tìm màn hình).
func (h *Handler) Routes(r chi.Router) {
	r.With(iam.RequireAny(h.reg.Perms()...)).Get("/search", h.search)
}

func (h *Handler) search(w http.ResponseWriter, r *http.Request) {
	limit, _ := strconv.Atoi(r.URL.Query().Get("limit"))
	res, err := h.reg.Run(r.Context(), iam.AccessFrom(r.Context()), r.URL.Query().Get("q"), limit)
	if err != nil {
		iam.Fail(w, r, h.log, err)
		return
	}
	httpx.JSON(w, http.StatusOK, res)
}
