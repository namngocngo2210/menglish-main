package crm

import (
	"bytes"
	"context"
	"encoding/csv"
	"fmt"
	"net/http"
	"net/url"
	"time"

	"github.com/xuri/excelize/v2"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

// Xuất bảng ra Excel (.xlsx) hoặc CSV (UTF-8 BOM) như Laravel downloadTable.

// Table: bảng dữ liệu xuất file.
type Table struct {
	Name     string
	Headings []string
	Rows     [][]any
}

func xlsxBytes(t Table) ([]byte, error) {
	f := excelize.NewFile()
	defer f.Close()
	sheet := f.GetSheetName(0)
	head := make([]any, len(t.Headings))
	for i, h := range t.Headings {
		head[i] = h
	}
	if err := f.SetSheetRow(sheet, "A1", &head); err != nil {
		return nil, err
	}
	for i, row := range t.Rows {
		r := row
		if err := f.SetSheetRow(sheet, fmt.Sprintf("A%d", i+2), &r); err != nil {
			return nil, err
		}
	}
	if err := f.SetPanes(sheet, &excelize.Panes{Freeze: true, YSplit: 1, TopLeftCell: "A2", ActivePane: "bottomLeft"}); err != nil {
		return nil, err
	}
	var buf bytes.Buffer
	if err := f.Write(&buf); err != nil {
		return nil, err
	}
	return buf.Bytes(), nil
}

func csvBytes(t Table) ([]byte, error) {
	var buf bytes.Buffer
	buf.WriteString("\xEF\xBB\xBF")
	w := csv.NewWriter(&buf)
	if err := w.Write(t.Headings); err != nil {
		return nil, err
	}
	for _, row := range t.Rows {
		rec := make([]string, len(row))
		for i, v := range row {
			if v != nil {
				rec[i] = fmt.Sprint(v)
			}
		}
		if err := w.Write(rec); err != nil {
			return nil, err
		}
	}
	w.Flush()
	return buf.Bytes(), w.Error()
}

// writeTable gửi file về trình duyệt; tên file kèm thời điểm như Laravel (vd danh-sach-khach-20261016-093000.xlsx).
func writeTable(w http.ResponseWriter, t Table, format string, stamp bool, now time.Time) error {
	var data []byte
	var err error
	ext, ctype := "xlsx", "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
	if format == "csv" {
		ext, ctype = "csv", "text/csv; charset=utf-8"
		data, err = csvBytes(t)
	} else {
		data, err = xlsxBytes(t)
	}
	if err != nil {
		return err
	}
	name := t.Name
	if stamp {
		name += "-" + now.In(vnZone).Format("20060102-150405")
	}
	name += "." + ext
	w.Header().Set("Content-Type", ctype)
	w.Header().Set("Content-Disposition", "attachment; filename=\""+name+"\"; filename*=UTF-8''"+url.PathEscape(name))
	w.Header().Set("Cache-Control", "no-store")
	_, err = w.Write(data)
	return err
}

func nz(p *string) any {
	if p == nil {
		return nil
	}
	return *p
}

func fmtPtrDateTime(t *time.Time) any {
	if t == nil {
		return nil
	}
	return t.In(vnZone).Format("02/01/2006 15:04")
}

// ExportLeads: xuất danh sách khách / khách chốt / khách thất bại theo bộ lọc đang chọn.
func (s *Service) ExportLeads(ctx context.Context, a *iam.Access, f ListFilter, kind string) (Table, error) {
	k := listAll
	switch kind {
	case "won":
		k = listWon
	case "lost":
		k = listLost
	}
	rows, _, err := s.listLeads(ctx, a, f, k, true)
	if err != nil {
		return Table{}, err
	}
	switch k {
	case listWon:
		t := Table{Name: "khach-chot-thanh-cong", Headings: []string{"Mã KH", "Họ tên", "SĐT", "Phụ huynh", "Cơ sở", "Nguồn",
			"Khóa đăng ký", "Lớp", "Giá trị HĐ", "Đã thu (duyệt)", "Còn nợ", "Người phụ trách", "Ngày chốt"}}
		if err := s.fillTuition(ctx, rows); err != nil {
			return Table{}, err
		}
		for _, r := range rows {
			class := "Chưa xếp lớp"
			if r.ClassName != nil {
				class = *r.ClassName
			}
			// Đã thu / Còn nợ: khoản học phí của học viên (phân hệ Học phí).
			var paid, debt int64
			if r.Tuition != nil {
				paid, debt = r.Tuition.Paid, r.Tuition.Debt
			}
			t.Rows = append(t.Rows, []any{r.Code, r.Name, r.Phone, nz(r.ParentName), nz(r.Branch), nz(r.Source), nz(r.CourseInterest),
				class, r.DealValue, paid, debt, nz(r.AssignedUser), fmtPtrDateTime(r.ConvertedAt)})
		}
		return t, nil
	case listLost:
		t := Table{Name: "khach-khong-chot", Headings: []string{"Mã KH", "Họ tên", "SĐT", "Cơ sở", "Nguồn", "Khóa quan tâm",
			"Giá trị dự kiến", "Lý do thất bại", "Người phụ trách", "Ngày thất bại", "Ghi chú"}}
		for _, r := range rows {
			t.Rows = append(t.Rows, []any{r.Code, r.Name, r.Phone, nz(r.Branch), nz(r.Source), nz(r.CourseInterest), r.DealValue,
				nz(r.LostReason), nz(r.AssignedUser), fmtPtrDateTime(r.LostAt), nz(r.Notes)})
		}
		return t, nil
	}
	t := Table{Name: "danh-sach-khach", Headings: []string{"Mã KH", "Họ tên", "SĐT", "Phụ huynh", "SĐT phụ huynh", "Giai đoạn",
		"Hạn liên hệ", "Người phụ trách", "Nguồn", "Chi nhánh", "Khóa quan tâm", "Ngày tạo", "Cập nhật gần nhất"}}
	for _, r := range rows {
		var deadline any
		if r.SLA != nil {
			deadline = fmtPtrDateTime(&r.SLA.Deadline)
		}
		t.Rows = append(t.Rows, []any{r.ShortCode, r.Name, r.Phone, nz(r.ParentName), nz(r.ParentPhone), r.StageLabel, deadline,
			nz(r.AssignedUser), nz(r.Source), nz(r.Branch), nz(r.CourseInterest), fmtPtrDateTime(&r.CreatedAt), fmtPtrDateTime(&r.UpdatedAt)})
	}
	return t, nil
}

// ExportReps: bảng hiệu suất theo người phụ trách.
func (s *Service) ExportReps(ctx context.Context, a *iam.Access, f ReportFilter) (Table, error) {
	r, err := s.Report(ctx, a, f)
	if err != nil {
		return Table{}, err
	}
	t := Table{
		Name:     "bao-cao-doanh-so-" + r.StartDate.In(vnZone).Format("20060102") + "-" + r.EndDate.In(vnZone).Format("20060102"),
		Headings: []string{"Người phụ trách", "Số lead", "Chốt thành công", "% Chốt", "Doanh thu thực thu", "Hoa hồng", "Bậc hoa hồng", "Đánh giá"},
	}
	for _, rep := range r.Reps {
		t.Rows = append(t.Rows, []any{rep.Name, rep.Leads, rep.Won, rep.Rate, rep.Revenue, rep.CommissionAmount, rep.TierName, rep.Rating})
	}
	return t, nil
}
