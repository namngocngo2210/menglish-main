package tuition

import (
	"bytes"
	"strings"
	"testing"
	"time"

	"github.com/xuri/excelize/v2"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam/iamtest"
)

const importHeader = "Mã học viên,Mã lớp,Học phí niêm yết,Giảm trừ,Phí khác,Hạn đóng,Số tiền đã đóng,Hình thức,Mã giao dịch,Ngày đóng,Ghi chú\n"

func errorsOf(row map[string]any) string {
	var out []string
	for _, e := range row["errors"].([]any) {
		out = append(out, e.(string))
	}
	return strings.Join(out, " | ")
}

func TestTuitionImport(t *testing.T) {
	e := setup(t)
	f := newFixture(e)
	e.exec(`DELETE FROM finance.tuition_import_batches`)
	e.student("HV0009", "Phạm Thu Hà", f.a, &f.class)
	upload := func(c *iamtest.Client, branch any, name, body string) iamtest.Resp {
		fields := map[string]string{}
		if branch != nil {
			fields["branch_id"] = branch.(interface{ String() string }).String()
		}
		return c.Upload("/tuition/import", fields, name, []byte(body))
	}

	// Quyền và phạm vi chi nhánh.
	iamtest.Expect(t, f.teacher.Do("GET", "/tuition/import", nil), 403, "forbidden")
	r := f.mgrA.Do("GET", "/tuition/import", nil)
	if br := list(r.Body["branches"]); r.Status != 200 || len(br) != 1 || str(br[0]["label"]) != "Chi nhánh CG" ||
		!strings.HasPrefix(str(r.Body["template_headings"]), "Mã học viên, Mã lớp") {
		t.Fatalf("màn nhập: %s", r.Raw)
	}
	iamtest.Expect(t, upload(f.teacher, f.a, "a.csv", importHeader), 403, "forbidden")
	expectMsg(t, upload(f.mgrA, f.b, "a.csv", importHeader+"HV0001,,,,,,1000,tien_mat\n"), 403, "Bạn chỉ được nhập học phí cho chi nhánh mình quản lý.")
	expectMsg(t, upload(f.mgrA, nil, "a.csv", importHeader), 422, "Vui lòng chọn chi nhánh.")
	expectMsg(t, upload(f.mgrA, f.a, "", ""), 422, "Vui lòng chọn file Excel (.xlsx) hoặc CSV để nhập.")
	expectMsg(t, upload(f.mgrA, f.a, "cu.xls", "xx"), 422, "Chỉ hỗ trợ file .xlsx hoặc .csv")
	expectMsg(t, upload(f.mgrA, f.a, "a.xlsx", "not a zip"), 422, "Không đọc được file. Vui lòng dùng file mẫu (.xlsx hoặc .csv UTF-8).")
	expectMsg(t, upload(f.mgrA, f.a, "a.csv", "Họ tên,Số tiền\nAn,1000\n"), 422, "File thiếu cột bắt buộc: Mã học viên. Vui lòng dùng file mẫu.")
	expectMsg(t, upload(f.mgrA, f.a, "a.csv", importHeader), 422, "File không có dòng dữ liệu nào.")

	// Xem trước: lỗi từng dòng, công nợ dự kiến theo các dòng trước trong file, mã giao dịch trùng trong file.
	csv := "\xEF\xBB\xBF" + importHeader +
		"HV0009,IE-CG-01,12.500.000,500.000,850.000,31/10/2026,5.000.000,chuyen_khoan,FT26100012345,17/10/2026,Đóng đợt 1\n" +
		"hv0009,,,,,,1.000.000,tien_mat,,,\n" +
		"HV0001,,,,,,500000,TM,,,\n" +
		"HV0001,,2000000,,,,,,,,\n" +
		"HV0002,,,,,,100000,tien_mat,,,\n" +
		"HV9999,,,,,,100000,tien_mat,,,\n" +
		"HV0001,,,,,,100000,chuyen_khoan,ft 26100012345,,\n" +
		"HV0001,,,,,,99999999,tien_mat,,,\n" +
		",IE-CG-01,,,,,,,,,\n" +
		"HV0001,,,,,,abc,tien_mat,,,\n" +
		"HV0001,,,,,,100000,the,,,\n" +
		"HV0001,IE-HD-01,,,,,,,,32/13/2026,\n" +
		",,,,,,,,,,\n"
	r = upload(f.mgrA, f.a, "hoc-phi.csv", csv)
	expectMsg(t, r, 201, "")
	rows := list(r.Body["rows"])
	if len(rows) != 12 || num(r.Body["valid_count"]) != 3 || str(r.Body["branch_name"]) != "Chi nhánh CG" || str(r.Body["file_name"]) != "hoc-phi.csv" {
		t.Fatalf("xem trước: %s", r.Raw)
	}
	if first := rows[0]; first["creates_tuition"] != true || num(first["final_amount"]) != 12_850_000 || str(first["due_date"]) != "31/10/2026" ||
		str(first["method_label"]) != "Chuyển khoản" || str(first["student_name"]) != "Phạm Thu Hà" || errorsOf(first) != "" {
		t.Fatalf("dòng tạo hồ sơ: %v", first)
	}
	wantErrs := map[int]string{
		1: "", 2: "",
		3:  `Học viên đã có hồ sơ học phí — không ghi đè giá trị hợp đồng, chỉ nhập "Số tiền đã đóng".`,
		4:  "Học viên HV0002 không thuộc chi nhánh đã chọn.",
		5:  "Không tìm thấy học viên mã HV9999.",
		6:  "Mã giao dịch ft 26100012345 đã được ghi nhận (trùng trong file hoặc đã có phiếu thu).",
		7:  "Số tiền đã đóng (99.999.999 đ) vượt công nợ còn lại (1.900.000 đ).",
		8:  "Thiếu mã học viên.",
		9:  "Số tiền đã đóng không phải số tiền hợp lệ (abc).",
		10: "Hình thức thanh toán không hợp lệ (tien_mat / chuyen_khoan).",
		11: "Lớp IE-HD-01 không thuộc chi nhánh đã chọn. | Ngày đóng không đúng định dạng ngày dd/mm/yyyy (32/13/2026).",
	}
	for i, want := range wantErrs {
		if got := errorsOf(rows[i]); got != want {
			t.Fatalf("dòng %d: muốn %q, nhận %q", num(rows[i]["line"]), want, got)
		}
	}
	token := str(r.Body["token"])

	// Bản xem trước chỉ của người tải lên.
	if p := obj(f.mgrA.Do("GET", "/tuition/import?token="+token, nil).Body["preview"]); len(list(p["rows"])) != 12 {
		t.Fatalf("mở lại bản xem trước: %v", p)
	}
	if p := f.mgrB.Do("GET", "/tuition/import?token="+token, nil).Body["preview"]; p != nil {
		t.Fatalf("người khác mở được bản xem trước: %v", p)
	}
	expectMsg(t, f.mgrB.Do("POST", "/tuition/import/confirm", map[string]any{"token": token}), 422, "Phiên xem trước đã hết hạn, vui lòng tải file lên lại.")

	// Xác nhận: hồ sơ học phí mới + phiếu thu chờ duyệt (không đổi công nợ tới khi duyệt).
	r = f.mgrA.Do("POST", "/tuition/import/confirm", map[string]any{"token": token})
	expectMsg(t, r, 200, "Đã nhập 1 hồ sơ học phí và 3 phiếu thu chờ duyệt.")
	if num(r.Body["skipped"]) != 9 || len(list(r.Body["failed"])) != 0 {
		t.Fatalf("kết quả nhập: %s", r.Raw)
	}
	if n := e.count(`SELECT count(*) FROM finance.tuitions t JOIN academic.students s ON s.id = t.student_id WHERE s.code = 'HV0009'
		AND t.final_amount = 12850000 AND t.debt_amount = 12850000 AND t.branch_id = $1 AND t.class_id = $2 AND t.due_date = DATE '2026-10-31'
		AND t.notes LIKE '%Nhập từ Excel (dòng 2) bởi Quản lý Cầu Giấy.' AND t.transfer_memo LIKE 'PHAMTHUHA HV0009%'`, f.a, f.class); n != 1 {
		t.Fatalf("hồ sơ học phí nhập: %d", n)
	}
	if n := e.count(`SELECT count(*) FROM finance.receipts WHERE source = 'import' AND status = 'pending' AND invoice_number IS NULL`); n != 3 {
		t.Fatalf("phiếu nhập: %d", n)
	}
	if n := e.count(`SELECT count(*) FROM finance.receipts WHERE source = 'import' AND transfer_reference = 'FT26100012345' AND payment_date = DATE '2026-10-17'
		AND notes = 'Nhập từ Excel (dòng 2), chờ Kế toán đối soát & duyệt. Đóng đợt 1'`); n != 1 {
		t.Fatalf("phiếu chuyển khoản nhập: %d", n)
	}
	if d := e.debtOf(f.ta); d.Debt != 2_400_000 {
		t.Fatalf("phiếu chờ duyệt không được trừ nợ: %+v", d)
	}
	expectMsg(t, f.mgrA.Do("POST", "/tuition/import/confirm", map[string]any{"token": token}), 422, "Phiên xem trước đã hết hạn")
	if n := e.count(`SELECT count(*) FROM core.audit_log WHERE action = 'tuition.import'`); n != 1 {
		t.Fatalf("nhật ký nhập: %d", n)
	}

	// Mã giao dịch đã có phiếu → báo ngay ở bước xem trước; file toàn lỗi thì không xác nhận được.
	r = upload(f.mgrA, f.a, "lai.csv", importHeader+"HV0001,,,,,,100000,ck,FT26100012345,,\n")
	if rows := list(r.Body["rows"]); len(rows) != 1 || !strings.Contains(errorsOf(rows[0]), "đã được ghi nhận") {
		t.Fatalf("trùng phiếu đã có: %s", r.Raw)
	}
	expectMsg(t, f.mgrA.Do("POST", "/tuition/import/confirm", map[string]any{"token": r.Body["token"]}), 422, "Không có dòng hợp lệ nào để nhập.")

	// Học viên được tạo hồ sơ ở nơi khác giữa lúc xem trước và xác nhận → chỉ dòng đó lỗi.
	s10 := e.student("HV0010", "Đỗ Minh", f.a, &f.class)
	r = upload(f.mgrA, f.a, "b.csv", importHeader+"HV0010,,3000000,,,01/11/2026,,,,,\nHV0001,,,,,,200000,tien_mat,,,\n")
	tok := str(r.Body["token"])
	e.tuition(s10, f.class, f.a, 1_000_000, 4)
	r = f.mgrA.Do("POST", "/tuition/import/confirm", map[string]any{"token": tok})
	expectMsg(t, r, 200, "Đã nhập 0 hồ sơ học phí và 1 phiếu thu chờ duyệt.")
	if failed := list(r.Body["failed"]); len(failed) != 1 || num(failed[0]["line"]) != 2 || str(failed[0]["error"]) != "Học viên vừa được tạo hồ sơ học phí ở nơi khác." {
		t.Fatalf("lỗi khi xác nhận: %s", r.Raw)
	}

	// File .xlsx: ô số / ô ngày thật của Excel.
	x := excelize.NewFile()
	sh := x.GetSheetName(0)
	for i, v := range []any{"Mã HV", "Học phí", "Hạn nộp", "Đã đóng", "Hình thức thanh toán", "Mã GD"} {
		col, _ := excelize.ColumnNumberToName(i + 1)
		_ = x.SetCellValue(sh, col+"1", v)
	}
	e.student("HV0011", "Vũ Lan", f.a, &f.class)
	for i, v := range []any{"HV0011", 3_000_000, time.Date(2026, 11, 20, 0, 0, 0, 0, time.UTC), 1_000_000, "Chuyển khoản", "FT-X1"} {
		col, _ := excelize.ColumnNumberToName(i + 1)
		_ = x.SetCellValue(sh, col+"2", v)
	}
	var buf bytes.Buffer
	_ = x.Write(&buf)
	r = upload(f.mgrA, f.a, "hoc-phi.xlsx", buf.String())
	if rows := list(r.Body["rows"]); r.Status != 201 || len(rows) != 1 || errorsOf(rows[0]) != "" || num(rows[0]["final_amount"]) != 3_000_000 ||
		str(rows[0]["due_date"]) != "20/11/2026" || num(rows[0]["paid_amount"]) != 1_000_000 || str(rows[0]["method_label"]) != "Chuyển khoản" {
		t.Fatalf("xem trước xlsx: %s", r.Raw)
	}

	// File mẫu.
	tpl := f.mgrA.Get("/api/v1/tuition/import/template")
	if tpl.Status != 200 || !bytes.HasPrefix(tpl.Raw, []byte("PK")) || !strings.Contains(tpl.Header.Get("Content-Disposition"), "mau-nhap-hoc-phi.xlsx") {
		t.Fatalf("file mẫu: %d %v", tpl.Status, tpl.Header)
	}
	xf, err := excelize.OpenReader(bytes.NewReader(tpl.Raw))
	if err != nil {
		t.Fatal(err)
	}
	if v, _ := xf.GetCellValue(xf.GetSheetName(0), "K1"); v != "Ghi chú" {
		t.Fatalf("tiêu đề file mẫu: %q", v)
	}
}
