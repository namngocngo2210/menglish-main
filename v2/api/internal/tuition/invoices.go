package tuition

import (
	"context"
	"fmt"
	"strconv"
	"strings"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/tuition/tuitiondb"
)

// Cấp số hóa đơn từ dải số (InvoiceConfiguration của Laravel): điện tử cấp lúc duyệt phiếu (dải chi nhánh trước, rồi
// dải mặc định dùng chung); giấy (tiền mặt) cấp ngay lúc lập phiếu từ cuốn hóa đơn của chi nhánh. Số dạng SERIES-0000123.

const (
	numberPad = 7
	// Còn dưới số này thì cảnh báo sắp hết dải
	lowRemainingThreshold = 50
)

var kindLabels = map[string]string{"electronic": "Hóa đơn điện tử", "paper": "Hóa đơn giấy (tiền mặt)"}

func formatInvoice(series string, n int64) string {
	return fmt.Sprintf("%s-%0*d", series, numberPad, n)
}

// invoiceNumberOf: phần số của số hóa đơn thuộc dải series ("C26MEN-0001001" → 1001).
func invoiceNumberOf(series, inv string) (int64, bool) {
	if !strings.HasPrefix(inv, series+"-") {
		return 0, false
	}
	n, err := strconv.ParseInt(inv[len(series)+1:], 10, 64)
	return n, err == nil
}

func errRangeExhausted() error {
	return invalidf("Dải số hóa đơn của chi nhánh và dải mặc định đã hết số hoặc đang ngừng dùng. Vui lòng cấu hình dải số mới.")
}

func errPaperExhausted() error {
	return invalidf("Dải số hóa đơn giấy của chi nhánh đã hết số hoặc đang ngừng dùng. Nhờ Kế toán thêm dải mới ở Cấu hình dải số hóa đơn.")
}

func exhausted(r tuitiondb.FinanceInvoiceRange) bool {
	return r.EndNumber != nil && r.CurrentNumber > *r.EndNumber
}

// consumeInvoiceNumber cấp số hóa đơn điện tử (trong giao dịch của thao tác duyệt). Chưa có dải mặc định thì tạo
// dải C26MEN bắt đầu 1001 như Laravel.
func (s *Service) consumeInvoiceNumber(ctx context.Context, q *tuitiondb.Queries, branch *uuid.UUID) (string, error) {
	ids, err := q.ElectronicRangeCandidates(ctx, branch)
	if err != nil {
		return "", err
	}
	if len(ids) == 0 {
		has, err := q.DefaultElectronicRangeExists(ctx)
		if err != nil {
			return "", err
		}
		if !has {
			if _, err := q.CreateInvoiceRange(ctx, tuitiondb.CreateInvoiceRangeParams{
				Kind: "electronic", TemplateCode: "1/001", SeriesCode: "C26MEN", StartNumber: 1, CurrentNumber: 1001,
				Provider: "vnpt", AutoIssue: true, IsActive: true,
			}); err != nil {
				return "", err
			}
			if ids, err = q.ElectronicRangeCandidates(ctx, branch); err != nil {
				return "", err
			}
		}
	}
	for _, id := range ids {
		r, err := q.LockInvoiceRange(ctx, id)
		if err != nil {
			return "", err
		}
		if !r.IsActive {
			continue
		}
		for !exhausted(r) {
			n := max(r.CurrentNumber, r.StartNumber)
			inv := formatInvoice(r.SeriesCode, n)
			r.CurrentNumber = n + 1
			if err := q.SetRangeCurrent(ctx, tuitiondb.SetRangeCurrentParams{ID: r.ID, CurrentNumber: r.CurrentNumber}); err != nil {
				return "", err
			}
			taken, err := q.InvoiceNumberTaken(ctx, &inv)
			if err != nil {
				return "", err
			}
			if !taken {
				return inv, nil
			}
		}
	}
	return "", errRangeExhausted()
}

// branchUsesPaperRange: chi nhánh có cuốn hóa đơn giấy đang dùng (phiếu tiền mặt được hệ thống cấp số).
func (s *Service) branchUsesPaperRange(ctx context.Context, q *tuitiondb.Queries, branch *uuid.UUID) (bool, error) {
	if branch == nil {
		return false, nil
	}
	rs, err := q.PaperRanges(ctx, branch)
	return len(rs) > 0, err
}

// peekPaperNumber: số hóa đơn giấy kế tiếp của chi nhánh (hiện trên form để người lập ghi lên hóa đơn).
func (s *Service) peekPaperNumber(ctx context.Context, q *tuitiondb.Queries, branch *uuid.UUID) (*string, error) {
	if branch == nil {
		return nil, nil
	}
	rs, err := q.PaperRanges(ctx, branch)
	if err != nil {
		return nil, err
	}
	for _, r := range rs {
		for n := max(r.CurrentNumber, r.StartNumber); r.EndNumber == nil || n <= *r.EndNumber; n++ {
			inv := formatInvoice(r.SeriesCode, n)
			taken, err := q.InvoiceNumberTaken(ctx, &inv)
			if err != nil {
				return nil, err
			}
			if !taken {
				return &inv, nil
			}
		}
	}
	return nil, nil
}

// consumePaperNumber cấp số hóa đơn giấy; gửi duyệt thẳng thì số phải đúng số người lập đã thấy (expected).
func (s *Service) consumePaperNumber(ctx context.Context, q *tuitiondb.Queries, branch uuid.UUID, expected *string) (string, error) {
	rs, err := q.PaperRanges(ctx, &branch)
	if err != nil {
		return "", err
	}
	for _, x := range rs {
		r, err := q.LockInvoiceRange(ctx, x.ID)
		if err != nil {
			return "", err
		}
		if !r.IsActive {
			continue
		}
		for !exhausted(r) {
			n := max(r.CurrentNumber, r.StartNumber)
			inv := formatInvoice(r.SeriesCode, n)
			taken, err := q.InvoiceNumberTaken(ctx, &inv)
			if err != nil {
				return "", err
			}
			if taken {
				r.CurrentNumber = n + 1
				if err := q.SetRangeCurrent(ctx, tuitiondb.SetRangeCurrentParams{ID: r.ID, CurrentNumber: r.CurrentNumber}); err != nil {
					return "", err
				}
				continue
			}
			if e := textOf(optText(expected)); e != "" && e != inv {
				return "", invalidf("Số hóa đơn giấy %s vừa được cấp cho phiếu khác. Số kế tiếp của chi nhánh là %s: ghi số %s lên hóa đơn giấy, chụp lại ảnh rồi gửi lại.", e, inv, inv)
			}
			if err := q.SetRangeCurrent(ctx, tuitiondb.SetRangeCurrentParams{ID: r.ID, CurrentNumber: n + 1}); err != nil {
				return "", err
			}
			return inv, nil
		}
	}
	return "", errPaperExhausted()
}
