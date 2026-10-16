package students

import (
	"context"
	"slices"
	"testing"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/search"
)

// Nhóm "Học viên" của tìm kiếm chung: cùng phạm vi danh sách học viên.
func TestSearcher(t *testing.T) {
	e := setup(t)
	a, b := e.branch("CN-A"), e.branch("CN-B")
	admin := e.member("MES001", nil, iam.RoleAdmin)
	staffA := e.member("HV-A", []uuid.UUID{a}, iam.RoleAcademicStaff)
	e.student(admin, CreateInput{FullName: "Nguyễn An", Phone: "0912111222", BranchID: &a})
	e.student(admin, CreateInput{FullName: "Trần Bình An", Phone: "0912333444", BranchID: &b})

	find := func(m member, term string) []string {
		t.Helper()
		q, _, err := search.NewQuery(term, 10)
		if err != nil {
			t.Fatal(err)
		}
		hits, err := Searcher(e.svc).Search(context.Background(), e.access(m), q)
		if err != nil {
			t.Fatal(err)
		}
		var names []string
		for _, h := range hits {
			names = append(names, h.Title)
			if h.Link != "/students/"+h.ID || h.Code == "" {
				t.Fatalf("kết quả: %+v", h)
			}
		}
		return names
	}
	if got := find(admin, "an"); !slices.Equal(got, []string{"Nguyễn An", "Trần Bình An"}) {
		t.Fatalf("Admin tìm: %v", got)
	}
	if got := find(staffA, "an"); !slices.Equal(got, []string{"Nguyễn An"}) {
		t.Fatalf("Học vụ CN-A tìm: %v", got)
	}
	if got := find(admin, "333444"); !slices.Equal(got, []string{"Trần Bình An"}) {
		t.Fatalf("tìm theo SĐT: %v", got)
	}
}
