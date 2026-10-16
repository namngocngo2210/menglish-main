package reports

// Ghép số liệu từ nhiều phân hệ: mỗi phân hệ bọc một MetricsProvider gốc và chỉ điền phần của mình (vd. lớp học điền
// mục "Vận hành lớp học" của báo cáo ngày, chỉ số quy mô lớp của báo cáo tuần, lớp của giáo viên trong báo cáo tháng).
// cmd/menglish xếp lớp bọc: classes.ReportMetrics(<provider khác>(reports.Skeleton())).

// Skeleton: bản mặc định (đủ mục / nhãn / cách tính như Laravel, chưa có số) để phân hệ khác bọc lại.
func Skeleton() MetricsProvider { return skeletonMetrics{} }

// Partial: provider còn thiếu nguồn cho một phần số liệu (màn hình hiện ghi chú "Chờ phân hệ …").
// part: "classes" (lớp của giáo viên), "meetings" (buổi họp giáo viên).
type Partial interface {
	Pending(part string) bool
}

func (skeletonMetrics) Pending(string) bool { return true }

// pendingPart: phần số liệu part chưa có nguồn ở provider m.
func pendingPart(m MetricsProvider, part string) bool {
	p, ok := m.(Partial)
	return ok && p.Pending(part)
}
