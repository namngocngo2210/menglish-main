// Package approvals: hộp "Việc cần duyệt" (ApprovalController + ApprovalInboxService của bản Laravel). Gom mục chờ duyệt
// của các phân hệ qua interface Source; nghiệp vụ duyệt / từ chối vẫn nằm ở phân hệ gốc (adapter gọi service sẵn có).
// Chỉ Admin dùng hộp này (yêu cầu 06/10/2026); Admin có phạm vi dữ liệu toàn hệ thống nên nguồn không lọc chi nhánh.
package approvals

import (
	"context"
	"encoding/json"
	"errors"
	"log/slog"
	"slices"
	"strconv"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/google/uuid"
	"github.com/redis/go-redis/v9"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

const (
	Approve = "approve"
	Reject  = "reject"
)

// Nhóm hiển thị trên chip lọc, theo thứ tự.
const (
	GroupTuition  = "Học phí"
	GroupAcademic = "Đào tạo"
	GroupWork     = "Công việc"
	GroupHR       = "Nhân sự"
)

var groupOrder = []string{GroupTuition, GroupAcademic, GroupWork, GroupHR}

// Slug của nhóm (Str::slug của Laravel) — tham số ?group= giữ như cũ.
var groupSlugs = map[string]string{GroupTuition: "hoc-phi", GroupAcademic: "dao-tao", GroupWork: "cong-viec", GroupHR: "nhan-su"}

// GroupSlug: slug của nhóm (nhóm lạ: chữ thường, khoảng trắng thành "-").
func GroupSlug(g string) string {
	if s, ok := groupSlugs[g]; ok {
		return s
	}
	return strings.ReplaceAll(strings.ToLower(strings.TrimSpace(g)), " ", "-")
}

func groupRank(g string) int {
	if i := slices.Index(groupOrder, g); i >= 0 {
		return i
	}
	return len(groupOrder)
}

const (
	// PerSource: số mục hiện tối đa mỗi nguồn (còn lại: "Xem tất cả" sang màn gốc).
	PerSource = 15
	// MaxBulk: số mục tối đa mỗi lần duyệt / từ chối hàng loạt.
	MaxBulk   = 100
	cacheTTL  = 60 * time.Second
	versionKy = "approvals:version"
)

// Meta: một dòng thông tin trong hộp chi tiết.
type Meta struct {
	Label string `json:"label"`
	Value string `json:"value"`
}

// Item: một dòng trong hộp (chỉ để hiển thị).
type Item struct {
	Source    string     `json:"source"`
	ID        uuid.UUID  `json:"id"`
	Title     string     `json:"title"`
	Subtitle  *string    `json:"subtitle"`
	URL       string     `json:"url"` // màn chi tiết / duyệt gốc của phân hệ
	CreatedAt *time.Time `json:"created_at"`
	Amount    *int64     `json:"amount"`
	Flag      *string    `json:"flag"`
	Meta      []Meta     `json:"meta,omitempty"`
}

// Ref: giá trị chọn / tham số bulk "<source>:<id>".
func (i Item) Ref() string { return i.Source + ":" + i.ID.String() }

// MetaOf dựng danh sách thông tin, bỏ dòng có giá trị rỗng (array_filter của Laravel).
func MetaOf(pairs ...string) []Meta {
	out := []Meta{}
	for i := 0; i+1 < len(pairs); i += 2 {
		if v := strings.TrimSpace(pairs[i+1]); v != "" {
			out = append(out, Meta{pairs[i], v})
		}
	}
	return out
}

// Limit: rút gọn chữ (gộp khoảng trắng, cắt n ký tự + "..."); rỗng → "".
func Limit(s *string, n int) string {
	if s == nil {
		return ""
	}
	t := strings.Join(strings.Fields(*s), " ")
	if utf8.RuneCountInString(t) <= n {
		return t
	}
	return strings.TrimRight(string([]rune(t)[:n]), " ") + "..."
}

// Source: một nguồn việc chờ duyệt. Adapter nằm cạnh phân hệ (tuition.Service.ApprovalSources()…), truy vấn đúng như
// màn duyệt gốc; Approve / Reject CHỈ gọi logic sẵn có của phân hệ. Nguồn cần nhập thêm thông tin khi duyệt (ảnh chứng
// từ, checklist…) trả Supports = false: hộp chỉ liệt kê + link sang màn gốc (nhúng LinkOnly).
type Source interface {
	Key() string
	Label() string
	Group() string
	IndexURL() string
	// CanView: người này duyệt được nguồn (chỉ xét quyền, không truy vấn DB).
	CanView(a *iam.Access) bool
	Count(ctx context.Context, a *iam.Access) (int64, error)
	// Pending: mới nhất trước.
	Pending(ctx context.Context, a *iam.Access, limit int) ([]Item, error)
	// Find: mục đang chờ (nil nếu không thấy / đã xử lý).
	Find(ctx context.Context, a *iam.Access, id uuid.UUID) (*Item, error)
	Supports(a *iam.Access, action string) bool
	// Approve / Reject: thông báo thành công; lỗi nghiệp vụ là *iam.Error.
	Approve(ctx context.Context, a *iam.Access, id uuid.UUID) (string, error)
	Reject(ctx context.Context, a *iam.Access, id uuid.UUID, reason string) (string, error)
}

// LinkOnly: nhúng vào nguồn không duyệt được trong hộp.
type LinkOnly struct{}

func (LinkOnly) Supports(*iam.Access, string) bool { return false }
func (LinkOnly) Approve(context.Context, *iam.Access, uuid.UUID) (string, error) {
	return "", iam.Invalid("Mục này cần duyệt ở màn gốc (cần nhập thêm thông tin).")
}
func (LinkOnly) Reject(context.Context, *iam.Access, uuid.UUID, string) (string, error) {
	return "", iam.Invalid("Mục này cần xử lý ở màn gốc.")
}

// ───────────── Service ─────────────

type Service struct {
	sources []Source
	rdb     *redis.Client
	log     *slog.Logger
}

// NewService: rdb nil = không cache số đếm.
func NewService(rdb *redis.Client, log *slog.Logger) *Service { return &Service{rdb: rdb, log: log} }

// Add đăng ký nguồn (khóa trùng thay nguồn cũ).
func (s *Service) Add(src ...Source) *Service {
	for _, x := range src {
		s.sources = slices.DeleteFunc(s.sources, func(o Source) bool { return o.Key() == x.Key() })
		s.sources = append(s.sources, x)
	}
	return s
}

var (
	ErrForbidden = &iam.Error{Status: 403, Code: "forbidden", Message: "Bạn không có quyền duyệt mục nào."}
	ErrNotFound  = &iam.Error{Status: 404, Code: "approval_not_found", Message: "Mục này không còn chờ duyệt hoặc ngoài phạm vi của bạn."}
)

// visible: nguồn người này duyệt được; người không phải Admin: không có nguồn nào.
func (s *Service) visible(a *iam.Access) []Source {
	if a == nil || !a.SuperAdmin {
		return nil
	}
	out := []Source{}
	for _, x := range s.sources {
		if x.CanView(a) {
			out = append(out, x)
		}
	}
	return out
}

// CanView: vào được hộp (menu, badge, /approvals): Admin và duyệt được ít nhất một nguồn.
func (s *Service) CanView(a *iam.Access) bool { return len(s.visible(a)) > 0 }

func (s *Service) source(a *iam.Access, key string) Source {
	for _, x := range s.visible(a) {
		if x.Key() == key {
			return x
		}
	}
	return nil
}

type cached struct {
	Version string           `json:"v"`
	Counts  map[string]int64 `json:"c"`
}

// counts: số mục chờ theo nguồn, cache 60 giây theo người dùng kèm "phiên bản" chung (đổi khi duyệt qua hộp).
func (s *Service) counts(ctx context.Context, a *iam.Access) (map[string]int64, error) {
	vis := s.visible(a)
	keys := make([]string, 0, len(vis))
	for _, x := range vis {
		keys = append(keys, x.Key())
	}
	ck := "counts:approvals:" + a.UserID.String()
	var version string
	if s.rdb != nil {
		vals, err := s.rdb.MGet(ctx, versionKy, ck).Result()
		if err == nil {
			version, _ = vals[0].(string)
			if raw, ok := vals[1].(string); ok {
				var c cached
				if json.Unmarshal([]byte(raw), &c) == nil && c.Version == version && sameKeys(c.Counts, keys) {
					return c.Counts, nil
				}
			}
		} else {
			s.log.Warn("approvals: đọc cache số đếm lỗi", "err", err)
		}
	}
	out := make(map[string]int64, len(vis))
	for _, x := range vis {
		n, err := x.Count(ctx, a)
		if err != nil {
			return nil, err
		}
		out[x.Key()] = n
	}
	if s.rdb != nil {
		b, _ := json.Marshal(cached{version, out})
		if err := s.rdb.Set(ctx, ck, b, cacheTTL).Err(); err != nil {
			s.log.Warn("approvals: ghi cache số đếm lỗi", "err", err)
		}
	}
	return out, nil
}

func sameKeys(m map[string]int64, keys []string) bool {
	if len(m) != len(keys) {
		return false
	}
	for _, k := range keys {
		if _, ok := m[k]; !ok {
			return false
		}
	}
	return true
}

// Invalidate: đổi phiên bản số đếm → mọi người tính lại ở lần đọc sau.
func (s *Service) Invalidate(ctx context.Context) {
	if s.rdb == nil {
		return
	}
	if err := s.rdb.Set(ctx, versionKy, strconv.FormatInt(time.Now().UnixNano(), 36), 0).Err(); err != nil {
		s.log.Warn("approvals: đổi phiên bản số đếm lỗi", "err", err)
	}
}

// Badge: số trên sidebar; nil = không hiện mục "Việc cần duyệt".
func (s *Service) Badge(ctx context.Context, a *iam.Access) (*int64, error) {
	if !s.CanView(a) {
		return nil, nil
	}
	c, err := s.counts(ctx, a)
	if err != nil {
		return nil, err
	}
	var n int64
	for _, v := range c {
		n += v
	}
	return &n, nil
}

// ───────────── Màn hộp ─────────────

type Group struct {
	Slug  string `json:"slug"`
	Label string `json:"label"`
	Count int64  `json:"count"`
}

type Section struct {
	Key      string `json:"key"`
	Label    string `json:"label"`
	Group    string `json:"group"`
	IndexURL string `json:"index_url"`
	Count    int64  `json:"count"`
	Approve  bool   `json:"approve"`
	Reject   bool   `json:"reject"`
	Items    []Item `json:"items"`
}

type Page struct {
	Groups   []Group   `json:"groups"`
	Group    *string   `json:"group"`
	Total    int64     `json:"total"`
	Sections []Section `json:"sections"`
}

// Index: chip lọc theo nhóm + danh sách theo nguồn (nguồn có mục chờ lên trước trong nhóm), tối đa PerSource mục.
func (s *Service) Index(ctx context.Context, a *iam.Access, group string) (*Page, error) {
	vis := s.visible(a)
	if len(vis) == 0 {
		return nil, ErrForbidden
	}
	counts, err := s.counts(ctx, a)
	if err != nil {
		return nil, err
	}
	out := &Page{Groups: []Group{}, Sections: []Section{}}
	for _, x := range vis {
		slug := GroupSlug(x.Group())
		i := slices.IndexFunc(out.Groups, func(g Group) bool { return g.Slug == slug })
		if i < 0 {
			out.Groups = append(out.Groups, Group{Slug: slug, Label: x.Group()})
			i = len(out.Groups) - 1
		}
		out.Groups[i].Count += counts[x.Key()]
		out.Total += counts[x.Key()]
	}
	slices.SortStableFunc(out.Groups, func(p, q Group) int { return groupRank(p.Label) - groupRank(q.Label) })
	if slices.ContainsFunc(out.Groups, func(g Group) bool { return g.Slug == group }) {
		out.Group = &group
	}
	for _, x := range vis {
		if out.Group != nil && GroupSlug(x.Group()) != *out.Group {
			continue
		}
		sec := Section{Key: x.Key(), Label: x.Label(), Group: x.Group(), IndexURL: x.IndexURL(), Count: counts[x.Key()],
			Approve: x.Supports(a, Approve), Reject: x.Supports(a, Reject), Items: []Item{}}
		if sec.Count > 0 {
			items, err := x.Pending(ctx, a, PerSource)
			if err != nil {
				return nil, err
			}
			for _, it := range items {
				it.Meta = nil
				sec.Items = append(sec.Items, it)
			}
		}
		out.Sections = append(out.Sections, sec)
	}
	slices.SortStableFunc(out.Sections, func(p, q Section) int {
		if r := groupRank(p.Group) - groupRank(q.Group); r != 0 {
			return r
		}
		return b2i(p.Count == 0) - b2i(q.Count == 0)
	})
	return out, nil
}

func b2i(b bool) int {
	if b {
		return 1
	}
	return 0
}

type Detail struct {
	Source     Meta `json:"source"` // label = tên nguồn, value = nhóm
	Item       Item `json:"item"`
	CanApprove bool `json:"can_approve"`
	CanReject  bool `json:"can_reject"`
}

// Show: chi tiết một mục + Duyệt / Từ chối nếu nguồn hỗ trợ.
func (s *Service) Show(ctx context.Context, a *iam.Access, key string, id uuid.UUID) (*Detail, error) {
	if !s.CanView(a) {
		return nil, ErrForbidden
	}
	x := s.source(a, key)
	if x == nil {
		return nil, ErrNotFound
	}
	it, err := x.Find(ctx, a, id)
	if err != nil {
		return nil, err
	}
	if it == nil {
		return nil, ErrNotFound
	}
	if it.Meta == nil {
		it.Meta = []Meta{}
	}
	return &Detail{Source: Meta{x.Label(), x.Group()}, Item: *it, CanApprove: x.Supports(a, Approve), CanReject: x.Supports(a, Reject)}, nil
}

// ───────────── Duyệt / từ chối hàng loạt ─────────────

type BulkInput struct {
	Action string   `json:"action"`
	Items  []string `json:"items"`
	Reason *string  `json:"reason"`
}

type ItemResult struct {
	Ref     string `json:"ref"`
	Title   string `json:"title"`
	OK      bool   `json:"ok"`
	Message string `json:"message"`
}

type BulkResult struct {
	Message string       `json:"message"`
	Tone    string       `json:"tone"` // success | warning | error
	Results []ItemResult `json:"results"`
}

// Bulk: duyệt / từ chối từng mục; mỗi mục một giao dịch riêng của phân hệ gốc nên mục lỗi không ảnh hưởng mục khác.
func (s *Service) Bulk(ctx context.Context, a *iam.Access, in BulkInput) (*BulkResult, error) {
	if !s.CanView(a) {
		return nil, ErrForbidden
	}
	if in.Action != Approve && in.Action != Reject {
		return nil, iam.Invalid("Thao tác không hợp lệ.")
	}
	if len(in.Items) == 0 {
		return nil, iam.Invalid("Chưa chọn mục nào.")
	}
	if len(in.Items) > MaxBulk {
		return nil, iam.Invalid("Mỗi lần xử lý tối đa " + strconv.Itoa(MaxBulk) + " mục.")
	}
	reason := ""
	if in.Reason != nil {
		reason = strings.TrimSpace(*in.Reason)
	}
	if in.Action == Reject && reason == "" {
		return nil, iam.Invalid("Vui lòng nhập lý do từ chối.")
	}
	if utf8.RuneCountInString(reason) > 1000 {
		return nil, iam.Invalid("Lý do từ chối không được vượt quá 1000 ký tự.")
	}
	refs := []string{}
	for _, r := range in.Items {
		if r = strings.TrimSpace(r); r != "" && len(r) <= 64 && !slices.Contains(refs, r) {
			refs = append(refs, r)
		}
	}
	out := &BulkResult{Results: []ItemResult{}}
	ok := 0
	for _, ref := range refs {
		res := s.one(ctx, a, in.Action, ref, reason)
		if res.OK {
			ok++
		}
		out.Results = append(out.Results, res)
	}
	s.Invalidate(ctx)
	failed := len(out.Results) - ok
	verb := "duyệt"
	if in.Action == Reject {
		verb = "từ chối"
	}
	switch {
	case len(out.Results) == 1:
		out.Message = out.Results[0].Message
	case failed > 0:
		out.Message = "Đã " + verb + " " + strconv.Itoa(ok) + "/" + strconv.Itoa(len(out.Results)) + " mục, " + strconv.Itoa(failed) + " mục không xử lý được."
	default:
		out.Message = "Đã " + verb + " " + strconv.Itoa(ok) + "/" + strconv.Itoa(len(out.Results)) + " mục."
	}
	switch {
	case failed == 0:
		out.Tone = "success"
	case ok > 0:
		out.Tone = "warning"
	default:
		out.Tone = "error"
	}
	return out, nil
}

func (s *Service) one(ctx context.Context, a *iam.Access, action, ref, reason string) ItemResult {
	gone := ItemResult{Ref: ref, Title: ref, Message: ErrNotFound.Message}
	key, raw, _ := strings.Cut(ref, ":")
	x := s.source(a, key)
	id, err := uuid.Parse(raw)
	if x == nil || err != nil {
		return gone
	}
	it, err := x.Find(ctx, a, id)
	if err != nil {
		s.log.Error("approvals: tìm mục lỗi", "ref", ref, "err", err)
		return ItemResult{Ref: ref, Title: ref, Message: "Có lỗi hệ thống khi xử lý mục này, vui lòng thử lại ở màn gốc."}
	}
	if it == nil {
		return gone
	}
	res := ItemResult{Ref: it.Ref(), Title: it.Title}
	if !x.Supports(a, action) {
		res.Message = "Mục này cần xử lý ở màn gốc."
		return res
	}
	var msg string
	if action == Approve {
		msg, err = x.Approve(ctx, a, id)
	} else {
		msg, err = x.Reject(ctx, a, id, reason)
	}
	var ie *iam.Error
	switch {
	case err == nil:
		res.OK, res.Message = true, msg
	case errors.As(err, &ie):
		res.Message = ie.Message
	default:
		s.log.Error("approvals: xử lý mục lỗi", "ref", ref, "action", action, "err", err)
		res.Message = "Có lỗi hệ thống khi xử lý mục này, vui lòng thử lại ở màn gốc."
	}
	return res
}
