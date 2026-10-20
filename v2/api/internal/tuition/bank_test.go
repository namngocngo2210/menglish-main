package tuition

import (
	"testing"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/tuition/tuitiondb"
)

// resolveBank theo BankAccount::resolveFor: không bao giờ lấy tài khoản của cơ sở khác.
func TestResolveBank(t *testing.T) {
	a, b := uuid.New(), uuid.New()
	nameA, nameB := "Cầu Giấy", "Hà Đông"
	acc := func(branch *uuid.UUID, name *string, def bool) tuitiondb.ActiveBankAccountsRow {
		return tuitiondb.ActiveBankAccountsRow{ID: uuid.New(), BankCode: "VCB", BankName: "Vietcombank", AccountNumber: "001",
			AccountHolder: "MENGLISH", BranchID: branch, BranchName: name, IsDefaultVietqr: def}
	}
	// Thứ tự như ActiveBankAccounts: cờ mặc định VietQR trước, dùng chung trước, tạo trước.
	defB := acc(&b, &nameB, true)
	shared := acc(nil, nil, false)
	ownA := acc(&a, &nameA, false)
	all := []tuitiondb.ActiveBankAccountsRow{defB, shared, ownA}

	cases := []struct {
		name        string
		accounts    []tuitiondb.ActiveBankAccountsRow
		own, branch *uuid.UUID
		want        *uuid.UUID
		scope       string
	}{
		{"tài khoản riêng của cơ sở trước tài khoản mặc định cơ sở khác", all, nil, &a, &ownA.ID, "Tài khoản cơ sở Cầu Giấy"},
		{"không có tài khoản riêng: dùng chung", []tuitiondb.ActiveBankAccountsRow{defB, shared}, nil, &a, &shared.ID, "Tài khoản dùng chung các cơ sở"},
		{"chỉ có tài khoản cơ sở khác: không có", []tuitiondb.ActiveBankAccountsRow{defB}, nil, &a, nil, ""},
		{"tài khoản hợp đồng thuộc cơ sở khác: bỏ qua", all, &defB.ID, &a, &ownA.ID, "Tài khoản cơ sở Cầu Giấy"},
		{"tài khoản hợp đồng dùng chung", all, &shared.ID, &a, &shared.ID, "Tài khoản gắn với hợp đồng"},
		{"tài khoản hợp đồng đúng cơ sở", all, &defB.ID, &b, &defB.ID, "Tài khoản gắn với hợp đồng"},
		{"chưa rõ cơ sở: tài khoản mặc định hệ thống", all, nil, nil, &defB.ID, "Tài khoản cơ sở Hà Đông"},
		{"chưa rõ cơ sở, không có cờ mặc định: dùng chung trước", []tuitiondb.ActiveBankAccountsRow{shared, ownA}, nil, nil, &shared.ID, "Tài khoản dùng chung các cơ sở"},
		{"không có tài khoản nào", nil, nil, &a, nil, ""},
	}
	for _, c := range cases {
		got := resolveBank(c.accounts, c.own, c.branch)
		switch {
		case c.want == nil && got != nil:
			t.Errorf("%s: muốn không có tài khoản, nhận %+v", c.name, got)
		case c.want != nil && (got == nil || got.ID != *c.want || got.Scope != c.scope):
			t.Errorf("%s: muốn %s %q, nhận %+v", c.name, c.want, c.scope, got)
		}
	}
}
