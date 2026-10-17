package crm

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

	"github.com/namngocngo2210/menglish-main/v2/api/internal/crm/crmdb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

// Nhập khách hàng loạt từ Excel (Laravel CrmImportController): tải file → xem trước + lỗi từng dòng → nhập các dòng
// hợp lệ vào chi nhánh + người phụ trách đã chọn (hoặc người ở cột "Người phụ trách"). Bản xem trước lưu ở
// crm.import_batches thay cho session.

const (
	importMaxRows  = 1000
	ImportMaxBytes = 5 << 20
)

// Cột trong file (bỏ dấu, viết thường, chỉ giữ chữ / số) → trường khách hàng.
var importHeaderMap = map[string]string{
	"hoten": "name", "hovaten": "name", "ten": "name", "tenkhach": "name", "tenkhachhang": "name", "name": "name",
	"sodienthoai": "phone", "sdt": "phone", "dienthoai": "phone", "phone": "phone",
	"tenphuhuynh": "parent_name", "phuhuynh": "parent_name", "parentname": "parent_name",
	"sdtphuhuynh": "parent_phone", "sodienthoaiphuhuynh": "parent_phone", "parentphone": "parent_phone",
	"email":    "email",
	"ngaysinh": "dob", "dob": "dob",
	"gioitinh": "gender", "gender": "gender",
	"diachi": "address", "address": "address",
	"truonghoc": "school", "truong": "school", "school": "school",
	"nguon": "source", "nguonkhach": "source", "source": "source",
	"khoahocquantam": "course_interest", "khoaquantam": "course_interest", "khoahoc": "course_interest", "courseinterest": "course_interest",
	"ghichu": "notes", "notes": "notes",
	"nguoiphutrach": "owner", "phutrach": "owner", "hocvuphutrach": "owner", "owner": "owner",
}

var importTemplateHeadings = []string{"Họ tên", "Số điện thoại", "Tên phụ huynh", "SĐT phụ huynh", "Email", "Ngày sinh",
	"Giới tính", "Địa chỉ", "Trường học", "Nguồn", "Khóa học quan tâm", "Ghi chú", "Người phụ trách"}

// ImportData: dữ liệu một dòng (chuỗi rỗng = trống).
type ImportData struct {
	Name           string `json:"name"`
	Phone          string `json:"phone"`
	ParentName     string `json:"parent_name"`
	ParentPhone    string `json:"parent_phone"`
	Email          string `json:"email"`
	Dob            string `json:"dob"` // YYYY-MM-DD
	DobError       string `json:"dob_error,omitempty"`
	Gender         string `json:"gender"`
	Address        string `json:"address"`
	School         string `json:"school"`
	Source         string `json:"source"`
	CourseInterest string `json:"course_interest"`
	Notes          string `json:"notes"`
	Owner          string `json:"owner"`
	OwnerID        string `json:"owner_id,omitempty"`
	OwnerName      string `json:"owner_name,omitempty"`
}

func (d *ImportData) set(field, v string) {
	switch field {
	case "name":
		d.Name = v
	case "phone":
		d.Phone = v
	case "parent_name":
		d.ParentName = v
	case "parent_phone":
		d.ParentPhone = v
	case "email":
		d.Email = v
	case "gender":
		d.Gender = v
	case "address":
		d.Address = v
	case "school":
		d.School = v
	case "source":
		d.Source = v
	case "course_interest":
		d.CourseInterest = v
	case "notes":
		d.Notes = v
	case "owner":
		d.Owner = v
	}
}

func (d ImportData) empty() bool {
	return d.Name == "" && d.Phone == "" && d.ParentName == "" && d.ParentPhone == "" && d.Email == "" && d.Dob == "" &&
		d.Gender == "" && d.Address == "" && d.School == "" && d.Source == "" && d.CourseInterest == "" && d.Notes == "" &&
		d.Owner == ""
}

// ImportRow: một dòng xem trước.
type ImportRow struct {
	Line   int        `json:"line"`
	Data   ImportData `json:"data"`
	Errors []string   `json:"errors"`
}

// rawRow: một dòng đọc từ file kèm số dòng thật.
type rawRow struct {
	line  int
	cells []string
}

// readSheet: dòng của sheet đầu (xlsx) hoặc file CSV, bỏ dòng trống nhưng giữ số dòng thật.
func readSheet(name string, data []byte) ([]rawRow, error) {
	lower := strings.ToLower(name)
	var rows [][]string
	switch {
	case strings.HasSuffix(lower, ".xlsx"):
		f, err := excelize.OpenReader(bytes.NewReader(data))
		if err != nil {
			return nil, err
		}
		defer f.Close()
		// Giá trị thô: ngày là số seri, SĐT lưu dạng số không bị định dạng lại.
		rows, err = f.GetRows(f.GetSheetName(0), excelize.Options{RawCellValue: true})
		if err != nil {
			return nil, err
		}
	case strings.HasSuffix(lower, ".csv") || strings.HasSuffix(lower, ".txt"):
		data = bytes.TrimPrefix(data, []byte("\xEF\xBB\xBF"))
		if !utf8.Valid(data) {
			return nil, errors.New("file CSV không phải UTF-8")
		}
		r := csv.NewReader(bytes.NewReader(data))
		first, _, _ := bytes.Cut(data, []byte("\n"))
		if bytes.Count(first, []byte(";")) > bytes.Count(first, []byte(",")) {
			r.Comma = ';'
		}
		r.FieldsPerRecord = -1
		r.LazyQuotes = true
		for {
			rec, err := r.Read()
			if errors.Is(err, io.EOF) {
				break
			}
			if err != nil {
				return nil, err
			}
			rows = append(rows, rec)
		}
	default:
		return nil, errUnsupportedFile
	}
	var out []rawRow
	for i, r := range rows {
		if slices.ContainsFunc(r, func(c string) bool { return strings.TrimSpace(c) != "" }) {
			out = append(out, rawRow{line: i + 1, cells: r})
		}
	}
	return out, nil
}

var errUnsupportedFile = iam.Invalid("Chỉ hỗ trợ file .xlsx hoặc .csv (file .xls cũ: mở bằng Excel và lưu lại dạng .xlsx).")

var nonAlnum = regexp.MustCompile(`[^a-z0-9]`)

func mapHeader(header []string) []string {
	cols := make([]string, len(header))
	for i, h := range header {
		key := nonAlnum.ReplaceAllString(strings.ToLower(foldASCII(h)), "")
		cols[i] = importHeaderMap[key]
	}
	return cols
}

var (
	lostZeroRe = regexp.MustCompile(`^([1-9]\d{8}|2\d{9})$`)
	dmyRe      = regexp.MustCompile(`^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{4})$`)
	ymdRe      = regexp.MustCompile(`^(\d{4})-(\d{1,2})-(\d{1,2})(?:[ T].*)?$`)
)

// parseDob: số seri Excel, dd/mm/yyyy (chấp nhận - và .) hoặc yyyy-mm-dd. ok=false khi không hiểu / ngày không tồn tại.
func parseDob(v string) (string, bool) {
	v = strings.TrimSpace(v)
	if v == "" {
		return "", true
	}
	if f, err := strconv.ParseFloat(v, 64); err == nil {
		if f < 10000 {
			return "", false
		}
		t, err := excelize.ExcelDateToTime(f, false)
		if err != nil {
			return "", false
		}
		return t.Format("2006-01-02"), true
	}
	var y, m, d int
	if g := dmyRe.FindStringSubmatch(v); g != nil {
		d, _ = strconv.Atoi(g[1])
		m, _ = strconv.Atoi(g[2])
		y, _ = strconv.Atoi(g[3])
	} else if g := ymdRe.FindStringSubmatch(v); g != nil {
		y, _ = strconv.Atoi(g[1])
		m, _ = strconv.Atoi(g[2])
		d, _ = strconv.Atoi(g[3])
	} else {
		return "", false
	}
	t := time.Date(y, time.Month(m), d, 0, 0, 0, 0, time.UTC)
	if y < 1900 || t.Year() != y || int(t.Month()) != m || t.Day() != d {
		return "", false
	}
	return t.Format("2006-01-02"), true
}

func rowData(cols []string, cells []string) ImportData {
	var d ImportData
	for i, field := range cols {
		if field == "" {
			continue
		}
		v := ""
		if i < len(cells) {
			v = strings.TrimSpace(cells[i])
		}
		if field == "dob" {
			dob, ok := parseDob(v)
			d.Dob = dob
			if !ok {
				d.DobError = v
			}
			continue
		}
		// Số nguyên Excel lưu dạng số thực ("912345678.0") → bỏ phần thập phân.
		if f, err := strconv.ParseFloat(v, 64); err == nil && f == math.Trunc(f) && strings.ContainsAny(v, ".eE") && !strings.Contains(v, "/") {
			v = strconv.FormatFloat(f, 'f', 0, 64)
		}
		// Excel hay mất số 0 đầu của SĐT: bổ sung nếu còn 9 số (di động) hoặc 10 số bắt đầu bằng 2 (máy bàn 02x).
		if (field == "phone" || field == "parent_phone") && lostZeroRe.MatchString(v) {
			v = "0" + v
		}
		limit := 255
		if field == "notes" {
			limit = 1000
		}
		d.set(field, cut(v, limit))
	}
	return d
}

// validateRow: lỗi của một dòng (rỗng = hợp lệ). seenPhones / seenEmails phát hiện trùng trong cùng file;
// chỉ dòng hợp lệ mới "giữ chỗ" SĐT / email.
func (s *Service) validateRow(ctx context.Context, q *crmdb.Queries, d ImportData, seenPhones map[string]int, seenEmails map[string]bool, line int) ([]string, error) {
	errs := []string{}
	var norm, email string
	if d.Name == "" {
		errs = append(errs, "Thiếu họ tên")
	}
	switch {
	case d.Phone == "":
		errs = append(errs, "Thiếu số điện thoại")
	case !ValidPhone(d.Phone):
		errs = append(errs, "SĐT sai định dạng (10 số bắt đầu bằng 0 hoặc +84)")
	default:
		norm = NormalizePhone(d.Phone)
		if l, ok := seenPhones[norm]; ok {
			errs = append(errs, fmt.Sprintf("Trùng SĐT với dòng %d trong file", l))
		} else {
			dup, err := q.FindLeadByPhone(ctx, crmdb.FindLeadByPhoneParams{Phone: norm})
			switch {
			case err == nil:
				errs = append(errs, "SĐT đã có trong CRM ("+ShortCode(dup.Code)+")")
			case !errors.Is(err, pgx.ErrNoRows):
				return nil, err
			}
		}
	}
	if d.ParentPhone != "" && (!ValidPhone(d.ParentPhone) || runeLen(d.ParentPhone) > 20) {
		errs = append(errs, "SĐT phụ huynh sai định dạng")
	}
	if d.Email != "" {
		email = strings.ToLower(d.Email)
		switch {
		case !validEmail(email):
			errs = append(errs, "Email sai định dạng")
		case seenEmails[email]:
			errs = append(errs, "Trùng email trong file")
		default:
			taken, err := q.EmailTaken(ctx, crmdb.EmailTakenParams{Email: email})
			if err != nil {
				return nil, err
			}
			if taken {
				errs = append(errs, "Email đã có trong CRM")
			}
		}
	}
	if d.DobError != "" {
		errs = append(errs, "Ngày sinh không hợp lệ ("+d.DobError+"), nhập dạng ngày/tháng/năm, vd. 15/08/2015")
	}
	if runeLen(d.Gender) > 20 {
		errs = append(errs, "Giới tính quá dài (tối đa 20 ký tự, vd. Nam / Nữ)")
	}
	if len(errs) == 0 && seenPhones != nil {
		if norm != "" {
			seenPhones[norm] = line
		}
		if email != "" {
			seenEmails[email] = true
		}
	}
	return errs, nil
}

// ImportOptions: lựa chọn của form nhập Excel.
type ImportOptions struct {
	Branches        []Option      `json:"branches"`
	Owners          []OwnerOption `json:"owners"`
	CanAssign       bool          `json:"can_assign"`
	DefaultBranchID *uuid.UUID    `json:"default_branch_id"`
	MaxRows         int           `json:"max_rows"`
}

// importFormOptions: chi nhánh (toàn hệ thống: mọi chi nhánh đang hoạt động; còn lại: chi nhánh của mình) và người
// phụ trách chọn được (có lead.assign: Admin + Học vụ các chi nhánh đó; không có: chỉ chính mình).
func (s *Service) importFormOptions(ctx context.Context, q *crmdb.Queries, a *iam.Access) ([]crmdb.ListBranchesBriefRow, []*owner, bool, error) {
	var branches []crmdb.ListBranchesBriefRow
	var err error
	all := a.Level("lead") == iam.ScopeAll
	if all {
		branches, err = q.ListBranchesBrief(ctx, crmdb.ListBranchesBriefParams{OnlyActive: true})
	} else if ids := a.BranchIDs(); len(ids) > 0 {
		branches, err = q.ListBranchesBrief(ctx, crmdb.ListBranchesBriefParams{Ids: ids})
	}
	if err != nil {
		return nil, nil, false, err
	}
	canAssign := a.Can("lead.assign")
	var owners []*owner
	if canAssign {
		var limit []uuid.UUID
		if !all {
			limit = []uuid.UUID{}
			for _, b := range branches {
				limit = append(limit, b.ID)
			}
		}
		owners, err = s.candidates(ctx, q, limit)
	} else {
		var me *owner
		me, err = s.loadOwner(ctx, q, a.UserID)
		if me != nil {
			owners = []*owner{me}
		}
	}
	return branches, owners, canAssign, err
}

func (s *Service) ImportOptions(ctx context.Context, a *iam.Access) (*ImportOptions, error) {
	branches, owners, canAssign, err := s.importFormOptions(ctx, s.q, a)
	if err != nil {
		return nil, err
	}
	o := &ImportOptions{Branches: []Option{}, Owners: ownerOptions(owners), CanAssign: canAssign, MaxRows: importMaxRows}
	for _, b := range branches {
		o.Branches = append(o.Branches, Option{b.ID.String(), b.Name})
	}
	if len(branches) == 1 {
		o.DefaultBranchID = &branches[0].ID
	}
	return o, nil
}

// ImportPreviewInput: tham số bước xem trước.
type ImportPreviewInput struct {
	FileName       string
	Data           []byte
	BranchID       *uuid.UUID
	AssignedUserID *uuid.UUID
	DefaultSource  string
}

// ImportPreview: bản xem trước.
type ImportPreview struct {
	ID               uuid.UUID   `json:"id"`
	FileName         string      `json:"file_name"`
	BranchName       string      `json:"branch_name"`
	AssignedUserName string      `json:"assigned_user_name"`
	Status           string      `json:"status"`
	ValidCount       int         `json:"valid_count"`
	ErrorCount       int         `json:"error_count"`
	Rows             []ImportRow `json:"rows"`
	CreatedCount     int32       `json:"created_count"`
	Skipped          []string    `json:"skipped"`
}

func (s *Service) PreviewImport(ctx context.Context, a *iam.Access, in ImportPreviewInput) (*ImportPreview, error) {
	if len(in.Data) == 0 {
		return nil, iam.Invalid("Vui lòng chọn file Excel / CSV.")
	}
	if len(in.Data) > ImportMaxBytes {
		return nil, iam.Invalid("File không được lớn hơn 5 MB.")
	}
	defaultSource := strings.TrimSpace(in.DefaultSource)
	if runeLen(defaultSource) > 255 {
		return nil, iam.Invalid("Nguồn mặc định không được vượt quá 255 ký tự.")
	}
	branches, owners, canAssign, err := s.importFormOptions(ctx, s.q, a)
	if err != nil {
		return nil, err
	}
	bi := -1
	if in.BranchID != nil {
		bi = slices.IndexFunc(branches, func(b crmdb.ListBranchesBriefRow) bool { return b.ID == *in.BranchID })
	}
	if bi < 0 {
		return nil, iam.Invalid("Chi nhánh không hợp lệ hoặc ngoài phạm vi của bạn.")
	}
	branch := branches[bi]
	// Người phụ trách mặc định: không có lead.assign hoặc không chọn → chính mình.
	var assignee *owner
	if canAssign && in.AssignedUserID != nil {
		i := slices.IndexFunc(owners, func(o *owner) bool { return o.ID == *in.AssignedUserID })
		if i < 0 {
			return nil, iam.Invalid("Người phụ trách không hợp lệ hoặc ngoài chi nhánh của bạn.")
		}
		assignee = owners[i]
	} else if assignee, err = s.loadOwner(ctx, s.q, a.UserID); err != nil {
		return nil, err
	}
	if assignee.ID != a.UserID && !assignee.belongsToBranch(&branch.ID) {
		return nil, iam.Invalid(assignee.Name + " không thuộc chi nhánh nhận khách. Chọn Học vụ cùng cơ sở hoặc Admin.")
	}
	// Cột "Người phụ trách" từng dòng: Học vụ của chi nhánh nhận khách hoặc Admin (theo tên / email).
	var rowOwners []*owner
	if canAssign {
		for _, o := range owners {
			if o.belongsToBranch(&branch.ID) {
				rowOwners = append(rowOwners, o)
			}
		}
	}
	raw, err := readSheet(in.FileName, in.Data)
	if err != nil {
		var ie *iam.Error
		if errors.As(err, &ie) {
			return nil, err
		}
		return nil, iam.Invalid("Không đọc được file: hãy dùng file mẫu .xlsx hoặc .csv (UTF-8). Chi tiết: " + cut(err.Error(), 160))
	}
	if len(raw) < 2 {
		return nil, iam.Invalid("File không có dữ liệu (dòng 1 là tiêu đề, dữ liệu từ dòng 2).")
	}
	cols := mapHeader(raw[0].cells)
	if !slices.Contains(cols, "name") || !slices.Contains(cols, "phone") {
		return nil, iam.Invalid(`File phải có cột "Họ tên" và "Số điện thoại" ở dòng tiêu đề (tải file mẫu để xem định dạng).`)
	}
	if len(raw)-1 > importMaxRows {
		return nil, iam.Invalid("Mỗi lần chỉ nhập tối đa " + strconv.Itoa(importMaxRows) + " dòng.")
	}
	rows := []ImportRow{}
	seenPhones, seenEmails := map[string]int{}, map[string]bool{}
	for _, r := range raw[1:] {
		d := rowData(cols, r.cells)
		if d.empty() && d.DobError == "" {
			continue
		}
		if d.Source == "" {
			d.Source = defaultSource
		}
		if d.Source == "" {
			d.Source = "Nhập Excel"
		}
		ownerErr := resolveRowOwner(&d, canAssign, rowOwners)
		errs, err := s.validateRow(ctx, s.q, d, seenPhones, seenEmails, r.line)
		if err != nil {
			return nil, err
		}
		if ownerErr != "" {
			errs = append(errs, ownerErr)
		}
		rows = append(rows, ImportRow{Line: r.line, Data: d, Errors: errs})
	}
	data, err := json.Marshal(rows)
	if err != nil {
		return nil, err
	}
	b, err := s.q.CreateImportBatch(ctx, crmdb.CreateImportBatchParams{
		CreatedBy: a.UserID, BranchID: branch.ID, AssignedUserID: assignee.ID, FileName: cut(in.FileName, 255), Rows: data,
	})
	if err != nil {
		return nil, err
	}
	s.log.Info("crm: xem trước nhập Excel", "batch", b.ID, "rows", len(rows), "by", a.UserID)
	return s.GetImport(ctx, a, b.ID)
}

// resolveRowOwner gán owner_id / owner_name cho dòng có cột "Người phụ trách"; trả lỗi nếu không hợp lệ.
func resolveRowOwner(d *ImportData, canAssign bool, owners []*owner) string {
	d.OwnerID, d.OwnerName = "", ""
	v := strings.TrimSpace(d.Owner)
	if v == "" {
		return ""
	}
	if !canAssign {
		return "Bạn không có quyền chọn người phụ trách (xóa cột Người phụ trách)"
	}
	o := matchOwner(owners, v)
	if o == nil {
		return `Người phụ trách "` + v + `" không phải Học vụ của chi nhánh nhận khách hoặc Admin (ghi đúng họ tên hoặc email)`
	}
	d.OwnerID, d.OwnerName = o.ID.String(), o.Name
	return ""
}

func (s *Service) GetImport(ctx context.Context, a *iam.Access, id uuid.UUID) (*ImportPreview, error) {
	b, err := s.q.GetImportBatch(ctx, crmdb.GetImportBatchParams{ID: id, CreatedBy: a.UserID})
	if errors.Is(err, pgx.ErrNoRows) {
		return nil, errNotFound
	}
	if err != nil {
		return nil, err
	}
	p := &ImportPreview{ID: b.ID, FileName: b.FileName, BranchName: b.BranchName, AssignedUserName: b.AssignedUserName,
		Status: b.Status, CreatedCount: b.CreatedCount, Skipped: []string{}}
	if err := json.Unmarshal(b.Rows, &p.Rows); err != nil {
		return nil, err
	}
	for _, r := range p.Rows {
		if len(r.Errors) == 0 {
			p.ValidCount++
		} else {
			p.ErrorCount++
		}
	}
	if len(b.Skipped) > 0 {
		_ = json.Unmarshal(b.Skipped, &p.Skipped)
	}
	return p, nil
}

func (s *Service) lockImport(ctx context.Context, q *crmdb.Queries, a *iam.Access, id uuid.UUID) (crmdb.CrmImportBatch, error) {
	b, err := q.LockImportBatch(ctx, crmdb.LockImportBatchParams{ID: id, CreatedBy: a.UserID})
	if errors.Is(err, pgx.ErrNoRows) {
		return b, errNotFound
	}
	if err != nil {
		return b, err
	}
	if b.Status != "preview" {
		return b, iam.Invalid("Chưa có dữ liệu xem trước. Vui lòng tải file lên lại.")
	}
	return b, nil
}

func (s *Service) CancelImport(ctx context.Context, a *iam.Access, id uuid.UUID) (*Saved, error) {
	err := s.tx(ctx, func(q *crmdb.Queries) error {
		b, err := s.lockImport(ctx, q, a, id)
		if err != nil {
			return err
		}
		return q.FinishImportBatch(ctx, crmdb.FinishImportBatchParams{ID: b.ID, Status: "cancelled"})
	})
	if err != nil {
		return nil, err
	}
	return &Saved{ID: id, Message: "Đã hủy phiên nhập khách."}, nil
}

// ImportResult: kết quả bước Nhập.
type ImportResult struct {
	Created int      `json:"created"`
	Skipped []string `json:"skipped"`
	Message string   `json:"message"`
}

func (s *Service) CommitImport(ctx context.Context, a *iam.Access, id uuid.UUID) (*ImportResult, error) {
	var res *ImportResult
	err := pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error {
		q := s.q.WithTx(tx)
		b, err := s.lockImport(ctx, q, a, id)
		if err != nil {
			return err
		}
		branches, _, _, err := s.importFormOptions(ctx, q, a)
		if err != nil {
			return err
		}
		bi := slices.IndexFunc(branches, func(x crmdb.ListBranchesBriefRow) bool { return x.ID == b.BranchID })
		if bi < 0 {
			return forbidden("Chi nhánh không hợp lệ hoặc ngoài phạm vi của bạn.")
		}
		// Người phụ trách chọn lúc xem trước có thể đã bị khoá / đổi vai trò trước khi bấm Nhập.
		assignee, err := s.loadOwner(ctx, q, b.AssignedUserID)
		if err != nil {
			return err
		}
		if assignee == nil || !assignee.Active || (assignee.ID != a.UserID && !assignee.Candidate) {
			return iam.Invalid("Người phụ trách đã chọn không còn hoạt động. Vui lòng chọn lại và kiểm tra dữ liệu lần nữa.")
		}
		var rows []ImportRow
		if err := json.Unmarshal(b.Rows, &rows); err != nil {
			return err
		}
		res = &ImportResult{Skipped: []string{}}
		for _, row := range rows {
			if len(row.Errors) > 0 {
				res.Skipped = append(res.Skipped, "Dòng "+strconv.Itoa(row.Line)+": "+strings.Join(row.Errors, "; "))
				continue
			}
			d := row.Data
			// Kiểm tra lại lúc nhập: có thể đã có người tạo khách cùng SĐT sau khi xem trước.
			errs, err := s.validateRow(ctx, q, d, nil, nil, row.Line)
			if err != nil {
				return err
			}
			owner := assignee.ID
			if d.OwnerID != "" {
				oid, perr := uuid.Parse(d.OwnerID)
				o, err := s.loadOwner(ctx, q, oid)
				if err != nil {
					return err
				}
				if perr != nil || o == nil || !o.Candidate {
					errs = append(errs, "Người phụ trách "+d.OwnerName+" không còn hoạt động")
				} else {
					owner = o.ID
				}
			}
			if len(errs) > 0 {
				res.Skipped = append(res.Skipped, "Dòng "+strconv.Itoa(row.Line)+": "+strings.Join(errs, "; "))
				continue
			}
			// Mỗi dòng một savepoint: một dòng lỗi không làm hỏng cả lần nhập.
			if err := pgx.BeginFunc(ctx, tx, func(sp pgx.Tx) error {
				return s.insertImported(ctx, s.q.WithTx(sp), a, b, d, owner)
			}); err != nil {
				if isUniqueViolation(err) {
					res.Skipped = append(res.Skipped, "Dòng "+strconv.Itoa(row.Line)+": SĐT hoặc email vừa được tạo bởi phiên khác.")
					continue
				}
				s.log.Warn("crm: nhập Excel lỗi một dòng", "batch", b.ID, "line", row.Line, "err", err)
				res.Skipped = append(res.Skipped, "Dòng "+strconv.Itoa(row.Line)+": dữ liệu không lưu được, vui lòng kiểm tra lại các cột của dòng này.")
				continue
			}
			res.Created++
		}
		skipped, err := json.Marshal(res.Skipped)
		if err != nil {
			return err
		}
		if err := q.FinishImportBatch(ctx, crmdb.FinishImportBatchParams{ID: b.ID, Status: "committed", CreatedCount: int32(res.Created), Skipped: skipped}); err != nil {
			return err
		}
		res.Message = "Đã nhập " + strconv.Itoa(res.Created) + " khách hàng mới vào " + branches[bi].Name + "."
		if len(res.Skipped) > 0 {
			res.Message += " Bỏ qua " + strconv.Itoa(len(res.Skipped)) + " dòng lỗi."
		}
		return nil
	})
	if err != nil {
		return nil, err
	}
	s.log.Info("crm: nhập Excel", "batch", id, "created", res.Created, "skipped", len(res.Skipped), "by", a.UserID)
	return res, nil
}

func (s *Service) insertImported(ctx context.Context, q *crmdb.Queries, a *iam.Access, b crmdb.CrmImportBatch, d ImportData, owner uuid.UUID) error {
	id, err := uuid.NewV7()
	if err != nil {
		return err
	}
	norm := NormalizePhone(d.Phone)
	var dob *time.Time
	if d.Dob != "" {
		if t, ok := parseDate(d.Dob); ok {
			dob = &t
		}
	}
	var email *string
	if d.Email != "" {
		email = ptr(strings.ToLower(d.Email))
	}
	l, err := q.CreateLead(ctx, crmdb.CreateLeadParams{
		ID: id, Code: leadCode(id), Name: d.Name, Phone: d.Phone, PhoneNormalized: &norm, Email: email,
		ParentName: strPtr(d.ParentName), ParentPhone: strPtr(d.ParentPhone), Dob: dob, Gender: strPtr(d.Gender),
		Address: strPtr(d.Address), School: strPtr(d.School), BranchID: &b.BranchID, CourseInterest: strPtr(d.CourseInterest),
		Source: strPtr(d.Source), AssignedUserID: &owner, Notes: strPtr(d.Notes), CreatedBy: &a.UserID,
	})
	if err != nil {
		return err
	}
	_, err = q.InsertEvent(ctx, crmdb.InsertEventParams{
		LeadID: l.ID, UserID: &a.UserID, Type: "system",
		Content: "Thêm khách hàng qua nhập Excel (file " + b.FileName + ", nguồn: " + d.Source + ").",
	})
	return err
}

// ImportTemplate: file mẫu nhập khách.
func ImportTemplate() Table {
	return Table{Name: "mau-nhap-khach-hang", Headings: importTemplateHeadings, Rows: [][]any{{
		"Nguyễn Văn An", "0912345678", "Nguyễn Văn Bình", "0987654321", "an.nguyen@example.com", "15/08/2015", "Nam",
		"Hà Nội", "Tiểu học Kim Đồng", "Facebook Ads", "Starters", "Muốn học buổi tối", "",
	}}}
}

// ImportErrors: các dòng lỗi của bản xem trước (cùng cột file mẫu + cột "Lỗi") để sửa rồi tải lại.
func (s *Service) ImportErrors(ctx context.Context, a *iam.Access, id uuid.UUID) (Table, error) {
	p, err := s.GetImport(ctx, a, id)
	if err != nil {
		return Table{}, err
	}
	t := Table{Name: "dong-loi-nhap-khach", Headings: append(slices.Clone(importTemplateHeadings), "Lỗi")}
	for _, r := range p.Rows {
		if len(r.Errors) == 0 {
			continue
		}
		d := r.Data
		dob := d.DobError
		if dob == "" && d.Dob != "" {
			if t, ok := parseDate(d.Dob); ok {
				dob = t.Format("02/01/2006")
			}
		}
		t.Rows = append(t.Rows, []any{d.Name, d.Phone, d.ParentName, d.ParentPhone, d.Email, dob, d.Gender, d.Address, d.School,
			d.Source, d.CourseInterest, d.Notes, d.Owner, "Dòng " + strconv.Itoa(r.Line) + ": " + strings.Join(r.Errors, "; ")})
	}
	return t, nil
}
