package tuition

import (
	"bytes"
	"context"
	"encoding/csv"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"math"
	"regexp"
	"slices"
	"strconv"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"
	"github.com/xuri/excelize/v2"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/tuition/tuitiondb"
)

// Nhập học phí từ Excel / CSV (TuitionImportService + TuitionController::import*): 3 bước Tải file → Xem trước (lỗi từng
// dòng) → Kết quả. Dòng hợp lệ tạo hồ sơ học phí (học viên chưa có) và/hoặc phiếu thu CHỜ DUYỆT cho số tiền đã đóng
// (không bao giờ tự duyệt, không tự cấp số hóa đơn). Bản xem trước lưu ở finance.tuition_import_batches thay cho session.

const (
	ImportMaxRows  = 1000
	ImportMaxBytes = 10 << 20
)

type importColumn struct{ Key, Heading string }

// importColumns: cột của file mẫu (TEMPLATE_HEADINGS), đúng thứ tự.
var importColumns = []importColumn{
	{"student_code", "Mã học viên"}, {"class_code", "Mã lớp"}, {"total_amount", "Học phí niêm yết"}, {"discount_amount", "Giảm trừ"},
	{"other_fees", "Phí khác"}, {"due_date", "Hạn đóng"}, {"paid_amount", "Số tiền đã đóng"}, {"payment_method", "Hình thức"},
	{"transaction_code", "Mã giao dịch"}, {"payment_date", "Ngày đóng"}, {"notes", "Ghi chú"},
}

// importHeaderAliases: tiêu đề cột (đã slug) được chấp nhận → key nội bộ (HEADER_ALIASES).
var importHeaderAliases = map[string]string{
	"ma_hoc_vien": "student_code", "ma_hv": "student_code", "student_code": "student_code", "ma_hoc_sinh": "student_code",
	"ma_lop": "class_code", "class_code": "class_code",
	"hoc_phi_niem_yet": "total_amount", "hoc_phi": "total_amount", "tong_hoc_phi": "total_amount", "total_amount": "total_amount",
	"giam_tru": "discount_amount", "uu_dai": "discount_amount", "giam_gia": "discount_amount", "discount_amount": "discount_amount",
	"phi_khac": "other_fees", "phu_phi": "other_fees", "hoc_lieu": "other_fees", "other_fees": "other_fees",
	"han_dong": "due_date", "han_nop": "due_date", "han_thanh_toan": "due_date", "due_date": "due_date",
	"so_tien_da_dong": "paid_amount", "da_dong": "paid_amount", "so_tien_thu": "paid_amount", "paid_amount": "paid_amount",
	"hinh_thuc": "payment_method", "hinh_thuc_thanh_toan": "payment_method", "phuong_thuc": "payment_method", "payment_method": "payment_method",
	"ma_giao_dich": "transaction_code", "ma_gd": "transaction_code", "transaction_code": "transaction_code",
	"ngay_dong": "payment_date", "ngay_thu": "payment_date", "payment_date": "payment_date",
	"ghi_chu": "notes", "notes": "notes",
}

// Không nhận quẹt thẻ POS: trung tâm chỉ thu tiền mặt / chuyển khoản.
var importMethodAliases = map[string]string{
	"tien_mat": "cash", "tm": "cash", "cash": "cash",
	"chuyen_khoan": "transfer", "ck": "transfer", "transfer": "transfer", "vietqr": "vietqr",
}

var reSlugSep = regexp.MustCompile(`[^a-z0-9]+`)

// slugKey: Str::slug(Str::ascii(x), '_').
func slugKey(s string) string {
	s = strings.TrimPrefix(s, "\uFEFF")
	return strings.Trim(reSlugSep.ReplaceAllString(strings.ToLower(foldASCII(s)), "_"), "_")
}

func templateHeadings() string {
	h := make([]string, len(importColumns))
	for i, c := range importColumns {
		h[i] = c.Heading
	}
	return strings.Join(h, ", ")
}

// importRow: một dòng đã kiểm tra (lưu trong bản xem trước).
type importRow struct {
	Line            int        `json:"line"`
	StudentID       *uuid.UUID `json:"student_id"`
	StudentCode     string     `json:"student_code"`
	StudentName     *string    `json:"student_name"`
	ClassID         *uuid.UUID `json:"class_id"`
	ClassCode       *string    `json:"class_code"`
	CreatesTuition  bool       `json:"creates_tuition"`
	TotalAmount     *int64     `json:"total_amount"`
	DiscountAmount  int64      `json:"discount_amount"`
	OtherFees       int64      `json:"other_fees"`
	FinalAmount     *int64     `json:"final_amount"`
	DueDate         *string    `json:"due_date"`
	PaidAmount      int64      `json:"paid_amount"`
	PaymentMethod   *string    `json:"payment_method"`
	TransactionCode *string    `json:"transaction_code"`
	PaymentDate     string     `json:"payment_date"`
	Notes           *string    `json:"notes"`
	Errors          []string   `json:"errors"`
}

// ImportPreviewRow: dòng hiển thị ở bước xem trước.
type ImportPreviewRow struct {
	Line            int      `json:"line"`
	StudentName     *string  `json:"student_name"`
	StudentCode     string   `json:"student_code"`
	CreatesTuition  bool     `json:"creates_tuition"`
	ClassCode       *string  `json:"class_code"`
	FinalAmount     *int64   `json:"final_amount"`
	DueDate         *string  `json:"due_date"`
	PaidAmount      int64    `json:"paid_amount"`
	MethodLabel     string   `json:"method_label"`
	TransactionCode *string  `json:"transaction_code"`
	Errors          []string `json:"errors"`
}

type ImportPreview struct {
	Token      uuid.UUID          `json:"token"`
	FileName   string             `json:"file_name"`
	BranchName string             `json:"branch_name"`
	Rows       []ImportPreviewRow `json:"rows"`
	ValidCount int                `json:"valid_count"`
	Committed  bool               `json:"committed"`
}

type ImportPage struct {
	Branches         []Option       `json:"branches"`
	TemplateHeadings string         `json:"template_headings"`
	Preview          *ImportPreview `json:"preview"`
}

type ImportFailure struct {
	Line  int    `json:"line"`
	Error string `json:"error"`
}

type ImportResult struct {
	Message  string          `json:"message"`
	FileName string          `json:"file_name"`
	Tuitions int             `json:"tuitions"`
	Receipts int             `json:"receipts"`
	Skipped  int             `json:"skipped"`
	Failed   []ImportFailure `json:"failed"`
}

var errImportScope = &iam.Error{Status: 403, Code: "forbidden", Message: "Bạn chỉ được nhập học phí cho chi nhánh mình quản lý."}

// ImportForm: màn nhập (chi nhánh đang hoạt động trong phạm vi; token → bản xem trước của chính người tải lên).
func (s *Service) ImportForm(ctx context.Context, actor *iam.Access, token *uuid.UUID) (*ImportPage, error) {
	bs, err := s.q.ScopeBranches(ctx, branchLimit(actor))
	if err != nil {
		return nil, err
	}
	out := &ImportPage{Branches: []Option{}, TemplateHeadings: templateHeadings()}
	for _, b := range bs {
		if b.IsActive {
			out.Branches = append(out.Branches, Option{Value: b.ID.String(), Label: b.Name})
		}
	}
	if token != nil {
		b, err := s.q.GetTuitionImport(ctx, tuitiondb.GetTuitionImportParams{ID: *token, CreatedBy: actor.UserID})
		if err != nil && !isNoRows(err) {
			return nil, err
		}
		if err == nil && b.Status == "preview" {
			if out.Preview, err = previewOf(b.ID, b.FileName, b.BranchName, b.Rows); err != nil {
				return nil, err
			}
		}
	}
	return out, nil
}

func previewOf(id uuid.UUID, file, branch string, raw []byte) (*ImportPreview, error) {
	var rows []importRow
	if err := json.Unmarshal(raw, &rows); err != nil {
		return nil, err
	}
	p := &ImportPreview{Token: id, FileName: file, BranchName: branch, Rows: make([]ImportPreviewRow, 0, len(rows))}
	for _, r := range rows {
		label := "—"
		if r.PaymentMethod != nil {
			label = methodLabel(*r.PaymentMethod)
		}
		var due *string
		if r.DueDate != nil {
			if t, err := time.Parse(time.DateOnly, *r.DueDate); err == nil {
				due = ptr(t.Format("02/01/2006"))
			}
		}
		if len(r.Errors) == 0 {
			p.ValidCount++
		}
		p.Rows = append(p.Rows, ImportPreviewRow{Line: r.Line, StudentName: r.StudentName, StudentCode: r.StudentCode, CreatesTuition: r.CreatesTuition,
			ClassCode: r.ClassCode, FinalAmount: r.FinalAmount, DueDate: due, PaidAmount: r.PaidAmount, MethodLabel: label,
			TransactionCode: r.TransactionCode, Errors: nonNil(r.Errors)})
	}
	return p, nil
}

func nonNil(v []string) []string {
	if v == nil {
		return []string{}
	}
	return v
}

// importCell: ô đọc từ file; numeric = giá trị thô của ô số trong .xlsx (không phải chuỗi gõ tay / CSV).
type importCell struct {
	text    string
	numeric bool
}

var errImportUnreadable = iam.Invalid("Không đọc được file. Vui lòng dùng file mẫu (.xlsx hoặc .csv UTF-8).")

// readImportSheet: dòng của sheet đầu (.xlsx) hoặc CSV (UTF-8, dấu , hoặc ;), bỏ dòng trống.
func readImportSheet(name string, data []byte) ([][]importCell, error) {
	lower := strings.ToLower(name)
	var out [][]importCell
	keep := func(cells []importCell) {
		if slices.ContainsFunc(cells, func(c importCell) bool { return strings.TrimSpace(c.text) != "" }) {
			out = append(out, cells)
		}
	}
	switch {
	case strings.HasSuffix(lower, ".xlsx"):
		f, err := excelize.OpenReader(bytes.NewReader(data))
		if err != nil {
			return nil, errImportUnreadable
		}
		defer f.Close()
		sheet := f.GetSheetName(0)
		rows, err := f.Rows(sheet)
		if err != nil {
			return nil, errImportUnreadable
		}
		for r := 1; rows.Next(); r++ {
			cols, err := rows.Columns(excelize.Options{RawCellValue: true})
			if err != nil {
				return nil, errImportUnreadable
			}
			cells := make([]importCell, len(cols))
			for i, v := range cols {
				cells[i].text = v
				if v != "" {
					ref, _ := excelize.CoordinatesToCellName(i+1, r)
					if t, err := f.GetCellType(sheet, ref); err == nil && (t == excelize.CellTypeNumber || t == excelize.CellTypeUnset || t == excelize.CellTypeDate) {
						_, perr := strconv.ParseFloat(v, 64)
						cells[i].numeric = perr == nil
					}
				}
			}
			keep(cells)
		}
		_ = rows.Close()
	case strings.HasSuffix(lower, ".csv") || strings.HasSuffix(lower, ".txt"):
		data = bytes.TrimPrefix(data, []byte("\xEF\xBB\xBF"))
		if !utf8.Valid(data) {
			return nil, errImportUnreadable
		}
		r := csv.NewReader(bytes.NewReader(data))
		first, _, _ := bytes.Cut(data, []byte("\n"))
		if bytes.Count(first, []byte(";")) > bytes.Count(first, []byte(",")) {
			r.Comma = ';'
		}
		r.FieldsPerRecord, r.LazyQuotes = -1, true
		for {
			rec, err := r.Read()
			if errors.Is(err, io.EOF) {
				break
			}
			if err != nil {
				return nil, errImportUnreadable
			}
			cells := make([]importCell, len(rec))
			for i, v := range rec {
				cells[i].text = v
			}
			keep(cells)
		}
	default:
		return nil, iam.Invalid("Chỉ hỗ trợ file .xlsx hoặc .csv (file .xls cũ: mở bằng Excel và lưu lại dạng .xlsx).")
	}
	return out, nil
}

var reNotDigit = regexp.MustCompile(`[^\d-]`)

// importMoney: số thật từ ô Excel dùng nguyên giá trị; chuỗi (CSV / ô text) coi "." và "," là dấu phân cách hàng nghìn.
func importMoney(c importCell, label string, errs *[]string) *int64 {
	v := strings.TrimSpace(c.text)
	if v == "" {
		return nil
	}
	var n float64
	if c.numeric {
		n, _ = strconv.ParseFloat(v, 64)
	} else {
		d := reNotDigit.ReplaceAllString(v, "")
		f, err := strconv.ParseFloat(d, 64)
		if d == "" || err != nil {
			*errs = append(*errs, fmt.Sprintf("%s không phải số tiền hợp lệ (%s).", label, v))
			return nil
		}
		n = f
	}
	if n < 0 {
		*errs = append(*errs, label+" không được âm.")
		return nil
	}
	return ptr(int64(math.Round(n)))
}

var importDateLayouts = []string{"2/1/2006", "2-1-2006", "2006-1-2", "2/1/06"}

// importDate: số seri Excel hoặc dd/mm/yyyy, dd-mm-yyyy, yyyy-mm-dd, dd/mm/yy (ngày phải tồn tại).
func importDate(c importCell, label string, errs *[]string) *string {
	v := strings.TrimSpace(c.text)
	if v == "" {
		return nil
	}
	if f, err := strconv.ParseFloat(v, 64); err == nil {
		if t, err := excelize.ExcelDateToTime(f, false); err == nil && f > 0 {
			return ptr(t.Format(time.DateOnly))
		}
	}
	for _, l := range importDateLayouts {
		if t, err := time.Parse(l, v); err == nil {
			return ptr(t.Format(time.DateOnly))
		}
	}
	*errs = append(*errs, fmt.Sprintf("%s không đúng định dạng ngày dd/mm/yyyy (%s).", label, v))
	return nil
}

// PreviewTuitionImport đọc file, kiểm tra từng dòng và lưu bản xem trước (importTuition + TuitionImportService::parse).
func (s *Service) PreviewTuitionImport(ctx context.Context, actor *iam.Access, branchID *uuid.UUID, fileName string, data []byte) (*ImportPreview, error) {
	if branchID == nil {
		return nil, iam.Invalid("Vui lòng chọn chi nhánh.")
	}
	bs, err := s.q.ScopeBranches(ctx, []uuid.UUID{*branchID})
	if err != nil {
		return nil, err
	}
	if len(bs) == 0 {
		return nil, iam.Invalid("Giá trị đã chọn trong trường chi nhánh không hợp lệ.")
	}
	if !covers(actor, branchID) {
		return nil, errImportScope
	}
	if data == nil {
		return nil, iam.Invalid("Vui lòng chọn file Excel (.xlsx) hoặc CSV để nhập.")
	}
	sheet, err := readImportSheet(fileName, data)
	if err != nil {
		return nil, err
	}
	if len(sheet) == 0 {
		return nil, iam.Invalid("File thiếu cột bắt buộc: " + templateHeadings() + ". Vui lòng dùng file mẫu.")
	}
	cols := map[int]string{}
	have := map[string]bool{}
	for i, h := range sheet[0] {
		if k := importHeaderAliases[slugKey(h.text)]; k != "" && !have[k] {
			cols[i], have[k] = k, true
		}
	}
	if !have["student_code"] {
		return nil, iam.Invalid("File thiếu cột bắt buộc: Mã học viên. Vui lòng dùng file mẫu.")
	}
	body := sheet[1:]
	if len(body) == 0 {
		return nil, iam.Invalid("File không có dòng dữ liệu nào.")
	}
	if len(body) > ImportMaxRows {
		body = body[:ImportMaxRows]
	}
	rows, err := s.checkImportRows(ctx, *branchID, cols, body)
	if err != nil {
		return nil, err
	}
	raw, err := json.Marshal(rows)
	if err != nil {
		return nil, err
	}
	b, err := s.q.CreateTuitionImport(ctx, tuitiondb.CreateTuitionImportParams{CreatedBy: actor.UserID, BranchID: *branchID,
		FileName: limitText(nonEmpty(fileName, "import"), 255), Rows: raw})
	if err != nil {
		return nil, err
	}
	s.log.Info("tuition import preview", "batch", b.ID, "rows", len(rows), "by", actor.UserID)
	return previewOf(b.ID, b.FileName, bs[0].Name, raw)
}

func (s *Service) checkImportRows(ctx context.Context, branch uuid.UUID, cols map[int]string, body [][]importCell) ([]importRow, error) {
	cell := func(cells []importCell, key string) importCell {
		for i, k := range cols {
			if k == key && i < len(cells) {
				return importCell{text: strings.TrimSpace(cells[i].text), numeric: cells[i].numeric}
			}
		}
		return importCell{}
	}
	var codes, classCodes []string
	for _, c := range body {
		if v := strings.ToUpper(cell(c, "student_code").text); v != "" {
			codes = append(codes, v)
		}
		if v := strings.ToUpper(cell(c, "class_code").text); v != "" {
			classCodes = append(classCodes, v)
		}
	}
	sts, err := s.q.ImportStudentsByCodes(ctx, codes)
	if err != nil {
		return nil, err
	}
	students := map[string]tuitiondb.ImportStudentsByCodesRow{}
	for _, st := range sts {
		students[strings.ToUpper(st.Code)] = st
	}
	cls, err := s.q.ImportClassesByCodes(ctx, classCodes)
	if err != nil {
		return nil, err
	}
	classes := map[string]tuitiondb.ImportClassesByCodesRow{}
	for _, c := range cls {
		classes[strings.ToUpper(c.Code)] = c
	}

	today := s.nowVN().Format(time.DateOnly)
	newTuitionFor := map[string]bool{}
	plannedDebt := map[string]int64{}
	refsInFile := map[string]bool{}
	out := make([]importRow, 0, len(body))
	for off, cells := range body {
		row := importRow{Line: off + 2, PaymentDate: today}
		var errs []string
		code := strings.ToUpper(cell(cells, "student_code").text)
		row.StudentCode = code
		st, found := students[code]
		switch {
		case code == "":
			errs = append(errs, "Thiếu mã học viên.")
		case !found:
			errs = append(errs, "Không tìm thấy học viên mã "+code+".")
		case st.BranchID == nil || *st.BranchID != branch:
			errs = append(errs, "Học viên "+code+" không thuộc chi nhánh đã chọn.")
		}
		if found {
			row.StudentID, row.StudentName = &st.ID, &st.FullName
		}
		if cc := cell(cells, "class_code").text; cc != "" {
			row.ClassCode = &cc
			if c, ok := classes[strings.ToUpper(cc)]; !ok {
				errs = append(errs, "Không tìm thấy lớp mã "+cc+".")
			} else if c.BranchID != branch {
				errs = append(errs, "Lớp "+cc+" không thuộc chi nhánh đã chọn.")
			} else {
				row.ClassID = &c.ID
			}
		}
		total := importMoney(cell(cells, "total_amount"), "Học phí niêm yết", &errs)
		row.TotalAmount = total
		row.DiscountAmount = val(importMoney(cell(cells, "discount_amount"), "Giảm trừ", &errs))
		row.OtherFees = val(importMoney(cell(cells, "other_fees"), "Phí khác", &errs))
		row.PaidAmount = val(importMoney(cell(cells, "paid_amount"), "Số tiền đã đóng", &errs))
		row.DueDate = importDate(cell(cells, "due_date"), "Hạn đóng", &errs)
		if d := importDate(cell(cells, "payment_date"), "Ngày đóng", &errs); d != nil {
			row.PaymentDate = *d
		}

		existing := found && st.TuitionID != uuid.Nil
		if found {
			if existing || newTuitionFor[code] {
				if total != nil && *total > 0 {
					errs = append(errs, `Học viên đã có hồ sơ học phí — không ghi đè giá trị hợp đồng, chỉ nhập "Số tiền đã đóng".`)
				}
			} else if total == nil || *total <= 0 {
				errs = append(errs, `Học viên chưa có hồ sơ học phí: bắt buộc nhập "Học phí niêm yết" > 0.`)
			} else {
				row.CreatesTuition = true
				if row.DueDate == nil {
					errs = append(errs, `Hồ sơ học phí mới cần "Hạn đóng".`)
				}
			}
		}
		if row.CreatesTuition {
			final := *total - row.DiscountAmount + row.OtherFees
			row.FinalAmount = &final
			if final < 0 {
				errs = append(errs, "Giảm trừ lớn hơn học phí + phí khác.")
			}
		}
		debtBefore := func() int64 {
			if d, ok := plannedDebt[code]; ok {
				return d
			}
			if existing {
				return st.TuitionDebt
			}
			return val(row.FinalAmount)
		}
		txn := cell(cells, "transaction_code").text
		if txn != "" {
			row.TransactionCode = &txn
		}
		var ref string
		if row.PaidAmount > 0 {
			method := importMethodAliases[slugKey(cell(cells, "payment_method").text)]
			if method == "" {
				errs = append(errs, "Hình thức thanh toán không hợp lệ (tien_mat / chuyen_khoan).")
			} else {
				row.PaymentMethod = &method
			}
			if method == "transfer" || method == "vietqr" {
				ref = normalizeReference(row.TransactionCode)
				if ref == "" {
					errs = append(errs, `Chuyển khoản cần "Mã giao dịch" để đối soát.`)
				} else {
					_, err := s.q.ReceiptByTransferReference(ctx, tuitiondb.ReceiptByTransferReferenceParams{Reference: ref})
					if err != nil && !isNoRows(err) {
						return nil, err
					}
					if refsInFile[ref] || err == nil {
						errs = append(errs, "Mã giao dịch "+txn+" đã được ghi nhận (trùng trong file hoặc đã có phiếu thu).")
					}
				}
			}
			if found && row.PaidAmount > debtBefore() {
				errs = append(errs, fmt.Sprintf("Số tiền đã đóng (%s) vượt công nợ còn lại (%s).", money(row.PaidAmount), money(debtBefore())))
			}
		}
		if n := cell(cells, "notes").text; n != "" {
			row.Notes = ptr(limitText(n, 500))
		}
		if len(errs) == 0 {
			if row.CreatesTuition {
				newTuitionFor[code] = true
			}
			plannedDebt[code] = debtBefore() - row.PaidAmount
			if ref != "" {
				refsInFile[ref] = true
			}
		}
		row.Errors = errs
		out = append(out, row)
	}
	return out, nil
}

// CommitTuitionImport ghi các dòng hợp lệ của bản xem trước (confirmImport): mỗi dòng một giao dịch, lỗi dòng nào thì
// chỉ dòng đó không vào. Bản xem trước chỉ xác nhận được một lần.
func (s *Service) CommitTuitionImport(ctx context.Context, actor *iam.Access, token uuid.UUID) (*ImportResult, error) {
	var b tuitiondb.FinanceTuitionImportBatch
	err := s.inTx(ctx, func(_ pgx.Tx, q *tuitiondb.Queries) error {
		var err error
		if b, err = q.LockTuitionImport(ctx, tuitiondb.LockTuitionImportParams{ID: token, CreatedBy: actor.UserID}); err != nil {
			if isNoRows(err) {
				return errImportExpired
			}
			return err
		}
		if b.Status != "preview" {
			return errImportExpired
		}
		if !covers(actor, &b.BranchID) {
			return errImportScope
		}
		var rows []importRow
		if err := json.Unmarshal(b.Rows, &rows); err != nil {
			return err
		}
		if !slices.ContainsFunc(rows, func(r importRow) bool { return len(r.Errors) == 0 }) {
			return iam.Invalid("Không có dòng hợp lệ nào để nhập.")
		}
		// Giữ chỗ: đánh dấu đã xác nhận trước khi ghi để hai lần bấm không nhập trùng.
		return q.CommitTuitionImport(ctx, tuitiondb.CommitTuitionImportParams{ID: b.ID, Result: []byte(`{}`)})
	})
	if err != nil {
		return nil, err
	}
	var rows []importRow
	_ = json.Unmarshal(b.Rows, &rows)
	name := s.actorName(ctx, s.q, actor)
	res := &ImportResult{FileName: b.FileName, Failed: []ImportFailure{}}
	for _, row := range rows {
		if len(row.Errors) > 0 {
			res.Skipped++
			continue
		}
		var tuitions, receipts int
		err := s.inTx(ctx, func(tx pgx.Tx, q *tuitiondb.Queries) error {
			tuitions, receipts = 0, 0
			return s.importRow(ctx, tx, q, actor, name, b.BranchID, row, &tuitions, &receipts)
		})
		var ie *iam.Error
		switch {
		case err == nil:
			res.Tuitions += tuitions
			res.Receipts += receipts
		case errors.As(err, &ie):
			res.Failed = append(res.Failed, ImportFailure{Line: row.Line, Error: ie.Message})
		default:
			s.log.Error("tuition import row", "batch", b.ID, "line", row.Line, "err", err)
			res.Failed = append(res.Failed, ImportFailure{Line: row.Line, Error: "Lỗi ghi dữ liệu: " + err.Error()})
		}
	}
	res.Message = fmt.Sprintf("Đã nhập %d hồ sơ học phí và %d phiếu thu chờ duyệt.", res.Tuitions, res.Receipts)
	raw, _ := json.Marshal(res)
	err = s.inTx(ctx, func(tx pgx.Tx, q *tuitiondb.Queries) error {
		if err := q.CommitTuitionImport(ctx, tuitiondb.CommitTuitionImportParams{ID: b.ID, Result: raw}); err != nil {
			return err
		}
		return audit.Record(ctx, tx, audit.Entry{
			ActorID: &actor.UserID, Module: audit.ModuleTuition, Event: audit.EventCreated, Action: "tuition.import",
			SubjectType: "tuition_import", SubjectID: b.ID.String(), SubjectLabel: b.FileName, BranchID: &b.BranchID,
			Description: "Nhập học phí từ Excel: " + res.Message,
			Properties:  map[string]any{"tuitions": res.Tuitions, "receipts": res.Receipts, "skipped": res.Skipped, "failed": len(res.Failed)},
		})
	})
	if err != nil {
		return nil, err
	}
	s.log.Info("tuition import committed", "batch", b.ID, "tuitions", res.Tuitions, "receipts", res.Receipts, "failed", len(res.Failed), "by", actor.UserID)
	return res, nil
}

var errImportExpired = iam.Invalid("Phiên xem trước đã hết hạn, vui lòng tải file lên lại.")

func (s *Service) importRow(ctx context.Context, tx pgx.Tx, q *tuitiondb.Queries, actor *iam.Access, actorName string, branch uuid.UUID,
	row importRow, tuitions, receipts *int) error {
	t, err := q.LockLatestStudentTuition(ctx, *row.StudentID)
	if err != nil && !isNoRows(err) {
		return err
	}
	has := err == nil
	if row.CreatesTuition {
		if has {
			return iam.Invalid("Học viên vừa được tạo hồ sơ học phí ở nơi khác.")
		}
		var due *time.Time
		if row.DueDate != nil {
			if d, err := time.Parse(time.DateOnly, *row.DueDate); err == nil {
				due = &d
			}
		}
		final := val(row.FinalAmount)
		t, err = s.insertTuition(ctx, q, tuitiondb.CreateTuitionParams{
			StudentID: *row.StudentID, ClassID: row.ClassID, BranchID: &branch, TotalAmount: val(row.TotalAmount),
			DiscountAmount: row.DiscountAmount, OtherFees: row.OtherFees, FeeItems: []byte("[]"), FinalAmount: final, DebtAmount: final,
			DueDate: due, Status: "unpaid", CreatedBy: &actor.UserID,
			Notes: ptr(fmt.Sprintf("%sNhập từ Excel (dòng %d) bởi %s.", s.stamp(), row.Line, actorName)),
		})
		if err != nil {
			return err
		}
		if t, err = s.recalculateDebt(ctx, q, t.ID); err != nil {
			return err
		}
		has = true
		*tuitions++
	}
	if row.PaidAmount <= 0 {
		return nil
	}
	if !has {
		return iam.Invalid("Không tìm thấy hồ sơ học phí để ghi nhận khoản đã đóng.")
	}
	if ref := normalizeReference(row.TransactionCode); ref != "" && (val(row.PaymentMethod) == "transfer" || val(row.PaymentMethod) == "vietqr") {
		if _, err := q.ReceiptByTransferReference(ctx, tuitiondb.ReceiptByTransferReferenceParams{Reference: ref}); err == nil {
			return iam.Invalid("Mã giao dịch " + textOf(row.TransactionCode) + " đã được ghi nhận (trùng trong file hoặc đã có phiếu thu).")
		} else if !isNoRows(err) {
			return err
		}
	}
	code, err := s.newReceiptCode(ctx, q)
	if err != nil {
		return err
	}
	day, err := time.Parse(time.DateOnly, row.PaymentDate)
	if err != nil {
		day = dateOf(s.now())
	}
	notes := strings.TrimSpace(fmt.Sprintf("Nhập từ Excel (dòng %d), chờ Kế toán đối soát & duyệt. %s", row.Line, textOf(row.Notes)))
	r, err := q.CreateReceipt(ctx, tuitiondb.CreateReceiptParams{
		Code: code, Source: "import", TuitionID: &t.ID, StudentID: row.StudentID, Amount: row.PaidAmount, TuitionAmount: row.PaidAmount,
		PaymentMethod: val(row.PaymentMethod), TransactionCode: row.TransactionCode, PaymentDate: &day, CollectedItems: []byte("[]"),
		Status: StatusPending, Notes: &notes, CreatorID: &actor.UserID,
	})
	if err != nil {
		return err
	}
	*receipts++
	return s.recordReceipt(ctx, tx, actor, r, textOf(row.StudentName), "tuition.receipt.import",
		fmt.Sprintf("Nhập từ Excel (dòng %d) phiếu thu chờ duyệt %s", row.Line, money(r.Amount)), &branch)
}

// ImportTemplate: file mẫu mau-nhap-hoc-phi.xlsx (TuitionImportTemplateExport, 1 dòng ví dụ).
func (s *Service) ImportTemplate() ([]byte, error) {
	f := excelize.NewFile()
	defer f.Close()
	sheet := f.GetSheetName(0)
	now := s.nowVN()
	example := []any{"HV-000001", "IELTS-A1-01", 12500000, 500000, 850000, now.AddDate(0, 0, 14).Format("02/01/2006"), 5000000,
		"chuyen_khoan", "FT26100012345", now.Format("02/01/2006"), "Đóng đợt 1"}
	for i, c := range importColumns {
		col, _ := excelize.ColumnNumberToName(i + 1)
		_ = f.SetCellValue(sheet, col+"1", c.Heading)
		_ = f.SetCellValue(sheet, col+"2", example[i])
		_ = f.SetColWidth(sheet, col, col, float64(max(14, utf8.RuneCountInString(c.Heading)+4)))
	}
	style, err := f.NewStyle(&excelize.Style{Font: &excelize.Font{Bold: true}})
	if err == nil {
		last, _ := excelize.ColumnNumberToName(len(importColumns))
		_ = f.SetCellStyle(sheet, "A1", last+"1", style)
	}
	var buf bytes.Buffer
	if err := f.Write(&buf); err != nil {
		return nil, err
	}
	return buf.Bytes(), nil
}
