package tuition

import (
	"encoding/json"
	"math"
	"strconv"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/tuition/tuitiondb"
)

// Trạng thái phiếu thu
const (
	StatusDraft     = "draft"
	StatusPending   = "pending"
	StatusApproved  = "approved"
	StatusRejected  = "rejected"
	StatusCancelled = "cancelled"
)

// Hình thức thu được chọn khi lập phiếu mới (POS đã bỏ; nhãn giữ cho phiếu cũ).
var inputMethods = []string{"transfer", "vietqr", "cash"}

func isTransfer(m string) bool      { return m == "transfer" || m == "vietqr" }
func proofRequired(m string) bool   { return m == "transfer" || m == "vietqr" || m == "pos" }
func editable(status string) bool   { return status == StatusDraft || status == StatusRejected }
func holdsReference(st string) bool { return st == StatusPending || st == StatusApproved }

var methodLabels = map[string]string{
	"transfer": "Chuyển khoản", "vietqr": "Chuyển khoản VietQR", "cash": "Tiền mặt", "pos": "Quẹt thẻ POS",
}

var methodIcons = map[string]string{"transfer": "account_balance", "vietqr": "qr_code_2", "cash": "payments", "pos": "credit_card"}

func methodLabel(m string) string {
	if l, ok := methodLabels[m]; ok {
		return l
	}
	if m == "" {
		return "—"
	}
	return m
}

// receiptStatusLabel / Color (TuitionReceipt::status_label / status_color)
func receiptStatusLabel(s string) string {
	switch s {
	case StatusDraft:
		return "Bản nháp"
	case StatusApproved:
		return "Đã duyệt thu"
	case StatusRejected:
		return "Bị từ chối"
	case StatusCancelled:
		return "Đã hủy hóa đơn"
	}
	return "Chờ duyệt"
}

func receiptStatusColor(s string) string {
	switch s {
	case StatusDraft, StatusCancelled:
		return "neutral"
	case StatusApproved:
		return "success"
	case StatusRejected:
		return "error"
	}
	return "warning"
}

var receiptStatuses = []Option{
	{StatusDraft, "Bản nháp"}, {StatusPending, "Chờ duyệt"}, {StatusApproved, "Đã duyệt thu"},
	{StatusRejected, "Bị từ chối"}, {StatusCancelled, "Đã hủy hóa đơn"},
}

// tuitionStatusLabel / Color (StudentTuition::status_label / status_color)
func tuitionStatusLabel(s string) string {
	switch s {
	case "paid":
		return "Đã hoàn thành"
	case "partial":
		return "Đang nợ (Đã cọc)"
	case "overdue":
		return "Quá hạn"
	}
	return "Chưa nộp"
}

func tuitionStatusColor(s string) string {
	switch s {
	case "paid":
		return "success"
	case "partial":
		return "warning"
	case "overdue":
		return "error"
	}
	return "neutral"
}

var tuitionStatuses = []Option{{"unpaid", "Chưa nộp"}, {"partial", "Đang nợ (Đã cọc)"}, {"paid", "Đã hoàn thành"}, {"overdue", "Quá hạn"}}

// feeLabel: "Học phí <khóa / lớp> + N khoản phụ".
func feeLabel(course, class *string, feeItems int32) string {
	name := "khóa học"
	if course != nil && *course != "" {
		name = *course
	} else if class != nil && *class != "" {
		name = *class
	}
	label := "Học phí " + name
	if feeItems > 0 {
		label += " + " + strconv.Itoa(int(feeItems)) + " khoản phụ"
	}
	return label
}

// ───────────── Hàng hóa kèm phiếu / hợp đồng ─────────────

// ItemLine: một dòng hàng hóa trong collected_items (phụ thu) / fee_items (hợp đồng).
type ItemLine struct {
	ID        uuid.UUID `json:"id"`
	Code      string    `json:"code,omitempty"`
	Name      string    `json:"name"`
	Unit      string    `json:"unit,omitempty"`
	Quantity  int       `json:"quantity"`
	UnitPrice int64     `json:"unit_price,omitempty"`
	Amount    int64     `json:"amount"`
	// surcharge: hàng ở phần phụ thu (xuất kho khi duyệt phiếu)
	Source string `json:"source,omitempty"`
	// Sách trong hợp đồng: xuất kho khi phiếu đầu tiên của hợp đồng được duyệt
	StockTracked bool `json:"stock_tracked,omitempty"`
}

func decodeItems(b []byte) []ItemLine {
	var out []ItemLine
	if len(b) == 0 || json.Unmarshal(b, &out) != nil {
		return []ItemLine{}
	}
	return out
}

func encodeItems(items []ItemLine) []byte {
	if items == nil {
		items = []ItemLine{}
	}
	b, _ := json.Marshal(items)
	return b
}

func itemsTotal(items []ItemLine) int64 {
	var t int64
	for _, i := range items {
		t += i.Amount
	}
	return t
}

// stockLines: gộp số lượng theo mặt hàng (StockService::stockLines).
func stockLines(items []ItemLine, keep func(ItemLine) bool) []ItemLine {
	idx := map[uuid.UUID]int{}
	var out []ItemLine
	for _, l := range items {
		if l.ID == uuid.Nil || !keep(l) {
			continue
		}
		q := max(1, l.Quantity)
		if i, ok := idx[l.ID]; ok {
			out[i].Quantity += q
			continue
		}
		idx[l.ID] = len(out)
		out = append(out, ItemLine{ID: l.ID, Name: l.Name, Quantity: q})
	}
	return out
}

func surchargeLines(items []ItemLine) []ItemLine {
	return stockLines(items, func(l ItemLine) bool { return l.Source == "surcharge" })
}

func contractLines(items []ItemLine) []ItemLine {
	return stockLines(items, func(l ItemLine) bool { return l.StockTracked })
}

// ───────────── Tính tiền của khoản học phí (StudentTuition) ─────────────

// contract: các số của khoản học phí dùng để tính sổ buổi.
type contract struct {
	Total, Discount, OtherFees, Final, Debt int64
	Sessions                                int
}

func contractOf(t tuitiondb.FinanceTuition) contract {
	return contract{Total: t.TotalAmount, Discount: t.DiscountAmount, OtherFees: t.OtherFees, Final: t.FinalAmount,
		Debt: t.DebtAmount, Sessions: int(val(t.SessionCount))}
}

func round2(v float64) float64 { return math.Round(v*100) / 100 }

// net: học phí sau giảm (không gồm khoản thu thêm).
func (c contract) net() int64 { return max(0, c.Total-c.Discount) }

// unitPrice: đơn giá một buổi của hợp đồng.
func (c contract) unitPrice() float64 {
	if c.Sessions <= 0 {
		return 0
	}
	return round2(float64(c.net()) / float64(c.Sessions))
}

// covered: phần đã cấn (đã nộp + giảm trừ trên phiếu).
func (c contract) covered() int64 { return max(0, c.Final-c.Debt) }

// feeRemaining: khoản thu thêm (sách...) chưa thu; tiền nộp cấn khoản thu thêm trước.
func (c contract) feeRemaining() int64 {
	fees := max(0, c.OtherFees)
	return fees - min(fees, c.covered())
}

func (c contract) tuitionCovered() int64 {
	fees := max(0, c.OtherFees)
	cov := c.covered()
	return min(c.net(), max(0, cov-min(fees, cov)))
}

func (c contract) tuitionRemaining() int64 { return max(0, c.net()-c.tuitionCovered()) }

// paidSessions: số buổi đã đóng theo tỷ lệ tiền học phí đã cấn.
func (c contract) paidSessions() int {
	if c.Sessions <= 0 {
		return 0
	}
	n := c.net()
	if n <= 0 {
		return c.Sessions
	}
	return min(c.Sessions, int(math.Floor(round2(float64(c.Sessions)*float64(c.tuitionCovered())/float64(n)))))
}

func (c contract) remainingSessions() int { return max(0, c.Sessions-c.paidSessions()) }

// debtStatus: trạng thái sau khi tính lại công nợ (recalculateDebt).
func debtStatus(debt, paid int64, overdue bool) string {
	switch {
	case debt <= 0:
		return "paid"
	case paid > 0:
		return "partial"
	case overdue:
		return "overdue"
	}
	return "unpaid"
}
