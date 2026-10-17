package approvals

import (
	"context"
	"errors"
	"io"
	"log/slog"
	"testing"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

// fake: nguồn giả giữ mục trong bộ nhớ.
type fake struct {
	key, group string
	items      []Item
	approve    bool
	fail       error
	done       []uuid.UUID
}

func (f *fake) Key() string                { return f.key }
func (f *fake) Label() string              { return "Nguồn " + f.key }
func (f *fake) Group() string              { return f.group }
func (f *fake) IndexURL() string           { return "/" + f.key }
func (f *fake) CanView(a *iam.Access) bool { return true }
func (f *fake) Count(context.Context, *iam.Access) (int64, error) {
	return int64(len(f.items)), nil
}
func (f *fake) Pending(_ context.Context, _ *iam.Access, limit int) ([]Item, error) {
	return f.items[:min(limit, len(f.items))], nil
}
func (f *fake) Find(_ context.Context, _ *iam.Access, id uuid.UUID) (*Item, error) {
	for i := range f.items {
		if f.items[i].ID == id {
			return &f.items[i], nil
		}
	}
	return nil, nil
}
func (f *fake) Supports(_ *iam.Access, action string) bool { return action == Reject || f.approve }
func (f *fake) Approve(_ context.Context, _ *iam.Access, id uuid.UUID) (string, error) {
	if f.fail != nil {
		return "", f.fail
	}
	f.done = append(f.done, id)
	return "Đã duyệt.", nil
}
func (f *fake) Reject(_ context.Context, _ *iam.Access, id uuid.UUID, reason string) (string, error) {
	f.done = append(f.done, id)
	return "Đã từ chối: " + reason, nil
}

func item(src, title string) Item {
	return Item{Source: src, ID: uuid.New(), Title: title, Meta: MetaOf("A", "1", "B", " ")}
}

func TestInboxGroupsSectionsAndBulk(t *testing.T) {
	ctx := context.Background()
	log := slog.New(slog.NewTextHandler(io.Discard, nil))
	work := &fake{key: "work", group: GroupWork, items: []Item{item("work", "Việc 1")}, approve: true}
	empty := &fake{key: "empty", group: GroupTuition}
	fee := &fake{key: "fee", group: GroupTuition, items: []Item{item("fee", "Phiếu 1"), item("fee", "Phiếu 2")}}
	broken := &fake{key: "broken", group: GroupAcademic, items: []Item{item("broken", "Lỗi")}, approve: true, fail: errors.New("db down")}
	svc := NewService(nil, log).Add(work, empty, fee, broken)
	admin := &iam.Access{UserID: uuid.New(), SuperAdmin: true}
	staff := &iam.Access{UserID: uuid.New(), Granted: map[string]bool{"tuition.view": true}}

	if svc.CanView(staff) {
		t.Fatal("chỉ Admin vào được hộp")
	}
	if _, err := svc.Index(ctx, staff, ""); !errors.Is(err, ErrForbidden) {
		t.Fatalf("người không phải Admin: %v", err)
	}
	if n, _ := svc.Badge(ctx, staff); n != nil {
		t.Fatal("không phải Admin thì không có badge")
	}
	if n, _ := svc.Badge(ctx, admin); n == nil || *n != 4 {
		t.Fatalf("badge: %v", n)
	}
	p, err := svc.Index(ctx, admin, "")
	if err != nil {
		t.Fatal(err)
	}
	// Nhóm theo thứ tự Học phí, Đào tạo, Công việc; nguồn rỗng xuống cuối nhóm; danh sách không kèm meta
	if len(p.Groups) != 3 || p.Groups[0].Slug != "hoc-phi" || p.Groups[0].Count != 2 || p.Groups[2].Slug != "cong-viec" {
		t.Fatalf("nhóm: %+v", p.Groups)
	}
	keys := []string{}
	for _, s := range p.Sections {
		keys = append(keys, s.Key)
	}
	if want := []string{"fee", "empty", "broken", "work"}; !equal(keys, want) {
		t.Fatalf("thứ tự nguồn %v, muốn %v", keys, want)
	}
	if p.Sections[0].Items[0].Meta != nil || p.Sections[0].Approve || !p.Sections[0].Reject {
		t.Fatalf("nguồn phiếu: %+v", p.Sections[0])
	}
	if p, _ := svc.Index(ctx, admin, "cong-viec"); len(p.Sections) != 1 || *p.Group != "cong-viec" {
		t.Fatalf("lọc nhóm: %+v", p)
	}
	if p, _ := svc.Index(ctx, admin, "khong-co"); p.Group != nil || len(p.Sections) != 4 {
		t.Fatal("nhóm lạ = mọi nhóm")
	}
	d, err := svc.Show(ctx, admin, "fee", fee.items[1].ID)
	if err != nil || d.CanApprove || !d.CanReject || len(d.Item.Meta) != 1 {
		t.Fatalf("chi tiết: %+v %v", d, err)
	}
	if _, err := svc.Show(ctx, admin, "nope", fee.items[1].ID); !errors.Is(err, ErrNotFound) {
		t.Fatalf("nguồn lạ: %v", err)
	}

	res, err := svc.Bulk(ctx, admin, BulkInput{Action: Approve, Items: []string{
		work.items[0].Ref(), fee.items[0].Ref(), broken.items[0].Ref(), "work:abc", work.items[0].Ref()}})
	if err != nil {
		t.Fatal(err)
	}
	if res.Message != "Đã duyệt 1/4 mục, 3 mục không xử lý được." || res.Tone != "warning" || len(work.done) != 1 {
		t.Fatalf("duyệt hàng loạt: %+v", res)
	}
	if res.Results[1].Message != "Mục này cần xử lý ở màn gốc." ||
		res.Results[2].Message != "Có lỗi hệ thống khi xử lý mục này, vui lòng thử lại ở màn gốc." ||
		res.Results[3].Message != ErrNotFound.Message {
		t.Fatalf("kết quả: %+v", res.Results)
	}
	// Lỗi nghiệp vụ của phân hệ gốc hiện nguyên thông báo
	broken.fail = iam.Invalid("Phiếu thu này đã được xử lý trước đó.")
	res, _ = svc.Bulk(ctx, admin, BulkInput{Action: Approve, Items: []string{broken.items[0].Ref()}})
	if res.Message != "Phiếu thu này đã được xử lý trước đó." || res.Tone != "error" {
		t.Fatalf("một mục lỗi: %+v", res)
	}
	reason := "  Sai  "
	res, _ = svc.Bulk(ctx, admin, BulkInput{Action: Reject, Reason: &reason, Items: []string{fee.items[0].Ref(), fee.items[1].Ref()}})
	if res.Message != "Đã từ chối 2/2 mục." || res.Tone != "success" {
		t.Fatalf("từ chối: %+v", res)
	}
	for _, c := range []struct {
		in  BulkInput
		msg string
	}{
		{BulkInput{Action: "x", Items: []string{"a:b"}}, "Thao tác không hợp lệ."},
		{BulkInput{Action: Approve}, "Chưa chọn mục nào."},
		{BulkInput{Action: Reject, Items: []string{"a:b"}}, "Vui lòng nhập lý do từ chối."},
	} {
		_, err := svc.Bulk(ctx, admin, c.in)
		var ie *iam.Error
		if !errors.As(err, &ie) || ie.Message != c.msg {
			t.Fatalf("muốn %q, nhận %v", c.msg, err)
		}
	}
}

func equal(a, b []string) bool {
	if len(a) != len(b) {
		return false
	}
	for i := range a {
		if a[i] != b[i] {
			return false
		}
	}
	return true
}

func TestHelpers(t *testing.T) {
	s := "  Một   hai\nba  "
	if got := Limit(&s, 20); got != "Một hai ba" {
		t.Fatalf("Limit: %q", got)
	}
	if got := Limit(&s, 6); got != "Một ha..." {
		t.Fatalf("Limit cắt: %q", got)
	}
	if Limit(nil, 5) != "" || GroupSlug(GroupHR) != "nhan-su" || GroupSlug("Khác Nữa") != "khác-nữa" {
		t.Fatal("helpers")
	}
}
