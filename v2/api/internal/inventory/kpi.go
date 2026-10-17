package inventory

import (
	"context"
	"math"
	"strconv"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/inventory/inventorydb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/kpi/kpisrc"
)

// Phần việc phân hệ kho / order học liệu làm cho KPI (KpiAutoCounter của Laravel): học liệu xử lý trễ hạn + kiểm kê lệch,
// và order học liệu học thuật giao đúng SLA trước giờ dùng (hệ số theo thời gian còn lại).

// Giờ dùng mặc định của order khi lớp không có buổi học trong ngày sử dụng.
const orderDefaultUseTime = "08:00"

// KpiSources: nguồn material_stock, academic_order_on_time.
func (s *Service) KpiSources() kpisrc.Sources {
	return kpisrc.Sources{"material_stock": s.kpiMaterialStock, "academic_order_on_time": s.kpiAcademicOrders}
}

func (s *Service) kpiMaterialStock(ctx context.Context, userID uuid.UUID, from, to time.Time) (kpisrc.Result, error) {
	orders, err := s.q.KpiLateOrders(ctx, inventorydb.KpiLateOrdersParams{UserID: &userID, FromAt: from, ToAt: to})
	if err != nil {
		return kpisrc.Result{}, err
	}
	var out []kpisrc.Record
	for _, o := range orders {
		out = append(out, kpisrc.Record{At: o.ProcessedAt, Text: "Học liệu " + o.Code + " xử lý trễ hạn (" + o.Title + ")"})
	}
	counts, err := s.q.KpiStockCounts(ctx, inventorydb.KpiStockCountsParams{UserID: &userID, FromAt: from, ToAt: to})
	if err != nil {
		return kpisrc.Result{}, err
	}
	for _, c := range counts {
		sign := ""
		if c.QuantityChange > 0 {
			sign = "+"
		}
		out = append(out, kpisrc.Record{At: c.CreatedAt, Text: "Kiểm kê " + c.ItemName + " lệch " + sign + strconv.Itoa(int(c.QuantityChange))})
	}
	return kpisrc.Result{Records: out}, nil
}

// kpiAcademicOrders: theo thời gian còn lại trước giờ dùng lúc giao xong: ≥ 24 giờ 1; 12–24 giờ 0,5; 6–12 giờ 0,25; dưới 6 giờ 0;
// xong sau giờ dùng / quá giờ dùng chưa xong = 0 và là điều kiện chặn. Order tạo khi còn dưới 24 giờ tới giờ dùng là lỗi bên
// yêu cầu: hiện để đối chiếu, không tính.
func (s *Service) kpiAcademicOrders(ctx context.Context, _ uuid.UUID, from, to time.Time) (kpisrc.Result, error) {
	rows, err := s.q.KpiAcademicOrders(ctx, inventorydb.KpiAcademicOrdersParams{FromDate: from, ToDate: to})
	if err != nil {
		return kpisrc.Result{}, err
	}
	now := s.now()
	var out []kpisrc.Record
	for _, o := range rows {
		hm := o.StartTime
		if hm == "" {
			hm = orderDefaultUseTime
		}
		t, err := time.Parse("15:04", hm)
		if err != nil {
			t, _ = time.Parse("15:04", orderDefaultUseTime)
		}
		use := time.Date(o.UseDate.Year(), o.UseDate.Month(), o.UseDate.Day(), t.Hour(), t.Minute(), 0, 0, vnZone)
		var done *time.Time
		if o.Status == "done" {
			done = o.ProcessedAt
			if done == nil {
				done = &o.UpdatedAt
			}
		}
		lbl := "Order " + o.Code + " (" + o.Title
		if o.ClassCode != "" || o.ClassName != "" {
			c := o.ClassCode
			if c == "" {
				c = o.ClassName
			}
			lbl += ", lớp " + c
		}
		lbl += "): dùng " + use.Format("15:04 02/01")
		switch {
		case o.CreatedAt.After(use.Add(-24 * time.Hour)):
			out = append(out, kpisrc.Record{At: use, Text: lbl, Note: "Tạo sát giờ dùng dưới 24 giờ, lỗi bên yêu cầu, không tính"})
		case done == nil && now.Before(use):
		case done == nil || !done.Before(use):
			state := "quá giờ dùng chưa giao"
			if done != nil {
				state = "giao sau giờ dùng"
			}
			out = append(out, kpisrc.Record{At: use, Text: lbl + ", " + state, Factor: kpisrc.F(0), Cap: true})
		default:
			hours := float64(int(use.Sub(*done).Minutes())) / 60
			f := 0.0
			switch {
			case hours >= 24:
				f = 1
			case hours >= 12:
				f = 0.5
			case hours >= 6:
				f = 0.25
			}
			out = append(out, kpisrc.Record{At: use, Factor: kpisrc.F(f),
				Text: lbl + ", giao xong " + done.In(vnZone).Format("15:04 02/01") + " (trước " + strconv.Itoa(int(math.Floor(hours))) + " giờ)"})
		}
	}
	return kpisrc.Result{Records: out}, nil
}
