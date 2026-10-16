package inventory

import (
	"context"
	"sync"
	"testing"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

func item(code, name string, extra map[string]any) map[string]any {
	m := map[string]any{"code": code, "name": name, "category": "book", "unit": "Cuốn", "price": 200000, "is_active": true}
	for k, v := range extra {
		m[k] = v
	}
	return m
}

func TestItemsCRUDAndPermissions(t *testing.T) {
	e := setup(t)
	a := e.branch("CG", "Cầu Giấy")
	admin := e.as(e.member("MES001", []uuid.UUID{a}, iam.RoleAdmin))

	r := admin.do("POST", "/inventory/items", item("UNI-S", "Áo đồng phục S", map[string]any{
		"category": "uniform", "unit": "Chiếc", "cost_price": 140000, "stock_quantity": 15, "stock_branch_id": a,
		"description": "Áo polo",
	}))
	expect(t, r, 201, "")
	if r.body["message"] != "Đã thêm thành công mặt hàng [UNI-S] Áo đồng phục S!" {
		t.Fatalf("thông báo: %v", r.body["message"])
	}
	uni := r.body["item"].(map[string]any)
	uniID := uuid.MustParse(str(uni["id"]))
	// Tồn ban đầu vào kho chi nhánh đã chọn, không vào tồn chưa phân chi nhánh.
	if e.stockAt(uniID, a) != 15 || num(uni["unallocated_quantity"]) != 0 {
		t.Fatalf("tồn ban đầu: chi nhánh %d, chưa phân %v", e.stockAt(uniID, a), uni["unallocated_quantity"])
	}
	if n := e.audited("merchandise_item.create", uniID.String()); n != 1 {
		t.Fatalf("nhật ký tạo hàng hóa: %d dòng", n)
	}

	// Không chọn chi nhánh → tồn cũ chưa phân chi nhánh.
	r = admin.do("POST", "/inventory/items", item("BOOK-01", "Sách Kids Box 1", map[string]any{"stock_quantity": 30}))
	expect(t, r, 201, "")
	bookID := str(r.body["item"].(map[string]any)["id"])
	if num(r.body["item"].(map[string]any)["unallocated_quantity"]) != 30 {
		t.Fatalf("tồn chưa phân: %v", r.body)
	}

	// Kiểm dữ liệu
	expectMsg(t, admin.do("POST", "/inventory/items", item("book-01", "Trùng mã", nil)), 422, "Mã hàng hóa này đã tồn tại trong hệ thống.")
	expectMsg(t, admin.do("POST", "/inventory/items", item("X1", "", nil)), 422, "Tên hàng hóa không được để trống.")
	expectMsg(t, admin.do("POST", "/inventory/items", item("", "Không mã", nil)), 422, "Mã hàng hóa không được để trống.")
	expectMsg(t, admin.do("POST", "/inventory/items", map[string]any{"code": "X2", "name": "Không giá", "category": "book", "unit": "Bộ"}), 422, "Đơn giá niêm yết là bắt buộc.")
	expectMsg(t, admin.do("POST", "/inventory/items", item("X3", "Sai nhóm", map[string]any{"category": "food"})), 422, "nhóm phân loại")

	// Danh sách: thẻ số liệu, lọc nhóm, tìm kiếm, tổng tồn = tồn chi nhánh + tồn chưa phân
	r = admin.do("GET", "/inventory/items", nil)
	expect(t, r, 200, "")
	m := r.body["metrics"].(map[string]any)
	if num(r.body["total"]) != 2 || num(m["total_stock"]) != 45 || num(m["books"]) != 1 || num(m["uniforms"]) != 1 {
		t.Fatalf("danh sách: %v", r.body)
	}
	if rows := list(admin.do("GET", "/inventory/items?category=uniform", nil).body["items"]); len(rows) != 1 || num(rows[0]["stock_quantity"]) != 15 {
		t.Fatalf("lọc nhóm: %v", rows)
	}
	if rows := list(admin.do("GET", "/inventory/items?q=kids", nil).body["items"]); len(rows) != 1 || str(rows[0]["code"]) != "BOOK-01" {
		t.Fatalf("tìm kiếm: %v", rows)
	}

	// Sửa (không đổi tồn), trùng mã khi sửa
	r = admin.do("PUT", "/inventory/items/"+bookID, item("BOOK-01", "Sách Kids Box 1 (mới)", map[string]any{"category": "workbook", "price": 180000}))
	expect(t, r, 200, "")
	if str(r.body["item"].(map[string]any)["category"]) != "workbook" || num(r.body["item"].(map[string]any)["unallocated_quantity"]) != 30 {
		t.Fatalf("sửa: %v", r.body)
	}
	expectMsg(t, admin.do("PUT", "/inventory/items/"+bookID, item("UNI-S", "Trùng", nil)), 422, "Mã hàng hóa này đã tồn tại.")

	// Bật / tắt kinh doanh, xóa mềm
	r = admin.do("POST", "/inventory/items/"+bookID+"/toggle", nil)
	if r.status != 200 || r.body["item"].(map[string]any)["is_active"] != false {
		t.Fatalf("tắt kinh doanh: %v", r.body)
	}
	if rows := list(admin.do("GET", "/inventory/items/active", nil).body["items"]); len(rows) != 1 {
		t.Fatalf("mặt hàng đang bán: %v", rows)
	}
	if num(admin.do("GET", "/inventory/items?status=inactive", nil).body["total"]) != 1 {
		t.Fatal("lọc tạm ngừng")
	}
	expectMsg(t, admin.do("DELETE", "/inventory/items/"+bookID, nil), 200, "Đã xóa mặt hàng")
	expect(t, admin.do("GET", "/inventory/items/"+bookID, nil), 404, "item_not_found")
	if num(admin.do("GET", "/inventory/items", nil).body["total"]) != 1 {
		t.Fatal("mặt hàng đã xóa vẫn hiện")
	}
	// Mã của mặt hàng đã xóa dùng lại được
	expect(t, admin.do("POST", "/inventory/items", item("BOOK-01", "Sách mới", nil)), 201, "")

	// Người không có system_category.manage
	staff := e.as(e.member("HV001", []uuid.UUID{a}, iam.RoleAcademicStaff))
	expect(t, staff.do("GET", "/inventory/items", nil), 403, "forbidden")
	expect(t, staff.do("POST", "/inventory/items", item("Z", "Z", nil)), 403, "forbidden")
	teacher := e.as(e.member("GV001", []uuid.UUID{a}, iam.RoleTeacherFulltime))
	expect(t, teacher.do("DELETE", "/inventory/items/"+str(uni["id"]), nil), 403, "forbidden")
}

func TestStockImportCountAndBranchScope(t *testing.T) {
	e := setup(t)
	a, b := e.branch("CG", "Cầu Giấy"), e.branch("HD", "Hà Đông")
	admin := e.as(e.member("MES001", nil, iam.RoleAdmin))
	book := uuid.MustParse(str(admin.do("POST", "/inventory/items", item("KB1", "Sách Kids Box 1", nil)).body["item"].(map[string]any)["id"]))

	staff := e.as(e.member("HV001", []uuid.UUID{a}, iam.RoleAcademicStaff))
	r := staff.do("GET", "/inventory/stock", nil)
	expect(t, r, 200, "")
	if br := r.body["branch"].(map[string]any); str(br["id"]) != a.String() || len(r.body["branches"].([]any)) != 1 {
		t.Fatalf("Học vụ chỉ thấy kho chi nhánh mình: %v", r.body["branches"])
	}
	if rows := list(r.body["rows"]); len(rows) != 1 || str(rows[0]["level"]) != "out" || r.body["can_manage"] != true {
		t.Fatalf("dòng tồn: %v", r.body)
	}
	// Chi nhánh ngoài phạm vi trên URL bị bỏ qua
	if str(staff.do("GET", "/inventory/stock?branch_id="+b.String(), nil).body["branch"].(map[string]any)["id"]) != a.String() {
		t.Fatal("chọn được chi nhánh ngoài phạm vi")
	}

	expectMsg(t, staff.do("POST", "/inventory/stock/movements", map[string]any{"type": "import", "item_id": book, "branch_id": a, "quantity": 20}), 201,
		"Đã nhập 20 Cuốn Sách Kids Box 1 vào kho Cầu Giấy.")
	if e.stockAt(book, a) != 20 {
		t.Fatalf("tồn sau nhập: %d", e.stockAt(book, a))
	}
	expectMsg(t, staff.do("POST", "/inventory/stock/movements", map[string]any{"type": "count", "item_id": book, "branch_id": a, "quantity": 17, "note": "Kiểm kê cuối tháng"}), 201,
		"Đã kiểm kê Sách Kids Box 1 tại Cầu Giấy: tồn 17 (-3).")
	expectMsg(t, staff.do("POST", "/inventory/stock/movements", map[string]any{"type": "count", "item_id": book, "branch_id": a, "quantity": 17}), 201,
		"đã khớp (17), không cần điều chỉnh")
	if e.stockAt(book, a) != 17 {
		t.Fatalf("tồn sau kiểm kê: %d", e.stockAt(book, a))
	}

	// Kiểm dữ liệu và phạm vi chi nhánh
	expectMsg(t, staff.do("POST", "/inventory/stock/movements", map[string]any{"type": "import", "item_id": book, "branch_id": a, "quantity": 0}), 422, "Số lượng nhập phải lớn hơn 0.")
	expectMsg(t, staff.do("POST", "/inventory/stock/movements", map[string]any{"type": "import", "branch_id": a, "quantity": 1}), 422, "Chọn mặt hàng.")
	expectMsg(t, staff.do("POST", "/inventory/stock/movements", map[string]any{"type": "import", "item_id": book, "quantity": 1}), 422, "Chọn chi nhánh.")
	expectMsg(t, staff.do("POST", "/inventory/stock/movements", map[string]any{"type": "import", "item_id": book, "branch_id": a}), 422, "Nhập số lượng.")
	expectMsg(t, staff.do("POST", "/inventory/stock/movements", map[string]any{"type": "import", "item_id": book, "branch_id": a, "quantity": 100001}), 422, "100.000")
	expect(t, staff.do("POST", "/inventory/stock/movements", map[string]any{"type": "import", "item_id": book, "branch_id": b, "quantity": 5}), 403, "outside_branch")

	// Nhật ký của mặt hàng
	r = staff.do("GET", "/inventory/stock/items/"+book.String()+"/history?branch_id="+a.String(), nil)
	expect(t, r, 200, "")
	mv := list(r.body["movements"])
	if num(r.body["quantity"]) != 17 || len(mv) != 2 || str(mv[0]["type"]) != "count" || str(mv[0]["note"]) != "Kiểm kê cuối tháng" ||
		num(mv[0]["change"]) != -3 || num(mv[0]["balance"]) != 17 || str(mv[0]["user_name"]) != "Người HV001" {
		t.Fatalf("nhật ký: %v", r.body)
	}
	if len(list(staff.do("GET", "/inventory/stock", nil).body["movements"])) != 2 {
		t.Fatal("xuất nhập gần đây")
	}

	// Form: chỉ chi nhánh trong phạm vi, tồn theo chi nhánh
	r = staff.do("GET", "/inventory/stock/form-options", nil)
	expect(t, r, 200, "")
	if len(r.body["branches"].([]any)) != 1 || len(r.body["transfer_branches"].([]any)) != 2 ||
		num(r.body["stock_by_branch"].(map[string]any)[a.String()].(map[string]any)[book.String()]) != 17 {
		t.Fatalf("form: %v", r.body)
	}

	// Admin thấy mọi chi nhánh; giáo viên không có quyền xem kho; người chưa gán chi nhánh
	if len(admin.do("GET", "/inventory/stock", nil).body["branches"].([]any)) != 2 {
		t.Fatal("Admin phải thấy mọi chi nhánh")
	}
	teacher := e.as(e.member("GV001", []uuid.UUID{a}, iam.RoleTeacherFulltime))
	expect(t, teacher.do("GET", "/inventory/stock", nil), 403, "forbidden")
	noBranch := e.as(e.member("HV002", nil, iam.RoleAcademicStaff))
	if r := noBranch.do("GET", "/inventory/stock", nil); r.status != 200 || r.body["branch"] != nil {
		t.Fatalf("chưa gán chi nhánh: %v", r.body)
	}
	expect(t, noBranch.do("GET", "/inventory/stock/form-options", nil), 403, "no_branch")

	// Chặn quyền nhập kho (phân quyền cá nhân) → chỉ xem
	viewer := e.member("HV003", []uuid.UUID{a}, iam.RoleAcademicStaff)
	e.exec(`INSERT INTO iam.permission_overrides (user_id, permission, allow) VALUES ($1, 'merchandise_stock.manage', false)`, viewer.id)
	vc := e.as(viewer)
	if r := vc.do("GET", "/inventory/stock", nil); r.status != 200 || r.body["can_manage"] != false {
		t.Fatalf("chỉ xem: %v", r.body)
	}
	expect(t, vc.do("POST", "/inventory/stock/movements", map[string]any{"type": "import", "item_id": book, "branch_id": a, "quantity": 1}), 403, "forbidden")
}

func TestLegacyAllocationIssueAndTransfer(t *testing.T) {
	e := setup(t)
	a, b := e.branch("CG", "Cầu Giấy"), e.branch("HD", "Hà Đông")
	admin := e.as(e.member("MES001", nil, iam.RoleAdmin))
	book := uuid.MustParse(str(admin.do("POST", "/inventory/items", item("KB1", "Sách Kids Box 1", map[string]any{"stock_quantity": 12})).body["item"].(map[string]any)["id"]))
	staff := e.as(e.member("HV001", []uuid.UUID{a}, iam.RoleAcademicStaff))

	// Tồn cũ chưa phân chi nhánh: hiện cảnh báo, phân bổ khi nhập kho
	if r := staff.do("GET", "/inventory/stock", nil); num(r.body["legacy_total"]) != 12 {
		t.Fatalf("tồn cũ: %v", r.body["legacy_total"])
	}
	expectMsg(t, staff.do("POST", "/inventory/stock/movements", map[string]any{"type": "import", "item_id": book, "branch_id": a, "quantity": 20, "from_legacy": true}), 422,
		"Tồn cũ chưa phân chi nhánh của Sách Kids Box 1 chỉ còn 12.")
	expect(t, staff.do("POST", "/inventory/stock/movements", map[string]any{"type": "import", "item_id": book, "branch_id": a, "quantity": 12, "from_legacy": true, "note": "Lô cũ"}), 201, "")
	if e.stockAt(book, a) != 12 || num(admin.do("GET", "/inventory/items/"+book.String(), nil).body["unallocated_quantity"]) != 0 {
		t.Fatal("phân bổ tồn cũ")
	}

	// Xuất kho tay không được làm âm
	expectMsg(t, staff.do("POST", "/inventory/stock/movements", map[string]any{"type": "issue", "item_id": book, "branch_id": a, "quantity": 13}), 422,
		"Tồn Sách Kids Box 1 tại Cầu Giấy chỉ còn 12 Cuốn, không xuất được 13.")
	student := uuid.New()
	expect(t, staff.do("POST", "/inventory/stock/movements", map[string]any{"type": "issue", "item_id": book, "branch_id": a, "quantity": 2, "student_id": student, "note": "Cấp cho học viên"}), 201, "")
	if e.stockAt(book, a) != 10 {
		t.Fatalf("tồn sau xuất: %d", e.stockAt(book, a))
	}

	// Chuyển kho sang chi nhánh khác (chi nhánh nhận không cần trong phạm vi)
	expectMsg(t, staff.do("POST", "/inventory/stock/movements", map[string]any{"type": "transfer", "item_id": book, "branch_id": a, "to_branch_id": b, "quantity": 4}), 201,
		"Đã chuyển 4 Cuốn Sách Kids Box 1 từ Cầu Giấy sang Hà Đông.")
	if e.stockAt(book, a) != 6 || e.stockAt(book, b) != 4 {
		t.Fatalf("sau chuyển kho: %d / %d", e.stockAt(book, a), e.stockAt(book, b))
	}
	expectMsg(t, staff.do("POST", "/inventory/stock/movements", map[string]any{"type": "transfer", "item_id": book, "branch_id": a, "to_branch_id": b, "quantity": 7}), 422, "chỉ còn 6")
	expectMsg(t, staff.do("POST", "/inventory/stock/movements", map[string]any{"type": "transfer", "item_id": book, "branch_id": a, "to_branch_id": a, "quantity": 1}), 422, "Chi nhánh nhận phải khác")
	expectMsg(t, staff.do("POST", "/inventory/stock/movements", map[string]any{"type": "transfer", "item_id": book, "branch_id": a, "quantity": 1}), 422, "Chọn chi nhánh nhận.")
	// Chuyển từ chi nhánh ngoài phạm vi
	expect(t, staff.do("POST", "/inventory/stock/movements", map[string]any{"type": "transfer", "item_id": book, "branch_id": b, "to_branch_id": a, "quantity": 1}), 403, "outside_branch")

	r := admin.do("GET", "/inventory/stock/items/"+book.String()+"/history?branch_id="+b.String(), nil)
	mv := list(r.body["movements"])
	if len(mv) != 1 || str(mv[0]["type"]) != "transfer_in" || str(mv[0]["counterpart_branch"]) != "Cầu Giấy" {
		t.Fatalf("nhật ký chi nhánh nhận: %v", mv)
	}

	// Nhật ký không sửa / xóa được
	if _, err := e.pool.Exec(context.Background(), `UPDATE ops.stock_movements SET note = 'sửa'`); err == nil {
		t.Fatal("sửa được nhật ký kho")
	}
	if _, err := e.pool.Exec(context.Background(), `DELETE FROM ops.stock_movements`); err == nil {
		t.Fatal("xóa được nhật ký kho")
	}

	// Xuất đồng thời: khóa dòng giữ tồn không âm
	actor := e.access(e.member("MES002", nil, iam.RoleAdmin))
	var wg sync.WaitGroup
	var mu sync.Mutex
	ok, insufficient := 0, 0
	for range 10 {
		wg.Add(1)
		go func() {
			defer wg.Done()
			_, err := e.svc.Move(context.Background(), actor, MoveInput{Type: TypeIssue, ItemID: &book, BranchID: &a, Quantity: ptr(int32(1))})
			mu.Lock()
			defer mu.Unlock()
			if err == nil {
				ok++
			} else if ie, isErr := err.(*iam.Error); isErr && ie.Status == 422 {
				insufficient++
			} else {
				t.Errorf("xuất đồng thời: %v", err)
			}
		}()
	}
	wg.Wait()
	if ok != 6 || insufficient != 4 || e.stockAt(book, a) != 0 {
		t.Fatalf("xuất đồng thời: %d thành công, %d hết hàng, tồn %d", ok, insufficient, e.stockAt(book, a))
	}
}

// Xuất theo phiếu thu (module tài chính gọi Record) được làm âm kho như Laravel; trang tồn kho báo "Âm kho".
func TestRecordSaleMayGoNegative(t *testing.T) {
	e := setup(t)
	a := e.branch("CG", "Cầu Giấy")
	admin := e.as(e.member("MES001", []uuid.UUID{a}, iam.RoleAdmin))
	book := uuid.MustParse(str(admin.do("POST", "/inventory/items", item("KB1", "Sách Kids Box 1", map[string]any{"stock_quantity": 2, "stock_branch_id": a})).body["item"].(map[string]any)["id"]))

	receipt := uuid.New()
	err := pgx.BeginFunc(context.Background(), e.pool, func(tx pgx.Tx) error {
		_, err := e.svc.Record(context.Background(), tx, RecordInput{
			ItemID: book, BranchID: a, Type: TypeSale, Quantity: 5, Source: "surcharge", ReceiptID: &receipt, DocumentNumber: "PT-2026-000001",
		})
		return err
	})
	if err != nil {
		t.Fatal(err)
	}
	if e.stockAt(book, a) != -3 {
		t.Fatalf("tồn sau xuất bán: %d", e.stockAt(book, a))
	}
	r := admin.do("GET", "/inventory/stock?level=negative", nil)
	rows := list(r.body["rows"])
	if len(rows) != 1 || str(rows[0]["level"]) != "negative" || num(r.body["counts"].(map[string]any)["negative"]) != 1 {
		t.Fatalf("âm kho: %v", r.body)
	}
	mv := list(r.body["movements"])
	if str(mv[0]["type_label"]) != "Xuất theo phiếu thu" || str(mv[0]["document_number"]) != "PT-2026-000001" {
		t.Fatalf("nhật ký xuất bán: %v", mv[0])
	}
	// Mặt hàng ngừng bán vẫn hiện khi còn tồn khác 0
	admin.do("POST", "/inventory/items/"+book.String()+"/toggle", nil)
	if len(list(admin.do("GET", "/inventory/stock", nil).body["rows"])) != 1 {
		t.Fatal("mặt hàng ngừng bán còn tồn âm phải hiện")
	}
}
