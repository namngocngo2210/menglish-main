package search

import (
	"context"
	"net/url"
	"strings"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

// Builtin: các nhóm có sẵn của nền tảng (người dùng, chi nhánh). Module khác thêm nhóm của mình bằng Add, vd.
//
//	reg := search.Builtin(iamSvc).Add(crm.Searcher(crmSvc)).Add(students.Searcher(studentSvc))
func Builtin(svc *iam.Service) *Registry {
	return NewRegistry().Add(Users(svc)).Add(Branches(svc))
}

// Users: tài khoản theo tên / mã / email / SĐT, cùng phạm vi màn Người dùng (cây vai trò + phạm vi module user).
func Users(svc *iam.Service) Searcher {
	return Searcher{
		Key: "users", Label: "Người dùng", Icon: "group", Perms: []string{"user.view"},
		Search: func(ctx context.Context, actor *iam.Access, q Query) ([]Hit, error) {
			list, _, err := svc.ListUsers(ctx, actor, iam.UserFilter{Search: q.Term})
			if err != nil {
				return nil, err
			}
			hits := make([]Hit, 0, min(len(list), q.Limit))
			for _, u := range list {
				h := Hit{ID: u.ID.String(), Title: u.FullName, Meta: []string{userStatus(u)}}
				if u.Code != nil {
					h.Code = *u.Code
				}
				switch {
				case u.Email != nil:
					h.Subtitle = *u.Email
				case u.Phone != nil:
					h.Subtitle = *u.Phone
				}
				find := h.Code
				if find == "" {
					find = u.FullName
				}
				h.Link = "/users?" + url.Values{"q": {find}, "open": {h.ID}}.Encode()
				hits = append(hits, h)
				if len(hits) == q.Limit {
					break
				}
			}
			return hits, nil
		},
	}
}

func userStatus(u iam.UserSummary) string {
	switch {
	case u.Status != "active":
		return "Đã vô hiệu hóa"
	case u.Locked:
		return "Tạm khóa đăng nhập"
	default:
		return "Đang hoạt động"
	}
}

// Branches: chi nhánh theo mã / tên / địa chỉ / SĐT (người có branch.view xem được mọi chi nhánh).
func Branches(svc *iam.Service) Searcher {
	return Searcher{
		Key: "branches", Label: "Cơ sở & Chi nhánh", Icon: "apartment", Perms: []string{"branch.view"},
		Search: func(ctx context.Context, _ *iam.Access, q Query) ([]Hit, error) {
			list, err := svc.ListBranches(ctx)
			if err != nil {
				return nil, err
			}
			term := strings.ToLower(q.Term)
			var hits []Hit
			for _, b := range list {
				fields := []string{b.Code, b.Name}
				if b.Address != nil {
					fields = append(fields, *b.Address)
				}
				if b.Phone != nil {
					fields = append(fields, *b.Phone)
				}
				if !containsAny(fields, term) {
					continue
				}
				status := "Đang hoạt động"
				if !b.IsActive {
					status = "Tạm dừng"
				}
				h := Hit{ID: b.ID.String(), Title: b.Name, Code: b.Code, Meta: []string{status}, Link: "/branches"}
				if b.Address != nil {
					h.Subtitle = *b.Address
				}
				hits = append(hits, h)
				if len(hits) == q.Limit {
					break
				}
			}
			return hits, nil
		},
	}
}

func containsAny(fields []string, term string) bool {
	for _, f := range fields {
		if strings.Contains(strings.ToLower(f), term) {
			return true
		}
	}
	return false
}
