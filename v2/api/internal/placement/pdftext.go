package placement

import (
	"io"
	"log/slog"
	"math"
	"strings"

	"github.com/ledongthuc/pdf"
)

// maxPdfPages: chỉ đọc chữ tối đa chừng này trang (đề test vài chục trang; tránh file lạ làm treo máy chủ).
const maxPdfPages = 60

// ExtractPdfPages: chữ của từng trang PDF (Laravel PlacementPdfAnswerSheet::extractPages dùng smalot/pdfparser).
// PDF scan (chỉ có ảnh) hoặc file hỏng → mảng rỗng / trang rỗng, không lỗi.
func ExtractPdfPages(ra io.ReaderAt, size int64, log *slog.Logger) (pages []string) {
	defer func() {
		if r := recover(); r != nil {
			if log != nil {
				log.Info("placement: không đọc được chữ trong PDF đề test", "err", r)
			}
			pages = []string{}
		}
	}()
	r, err := pdf.NewReader(ra, size)
	if err != nil {
		if log != nil {
			log.Info("placement: không đọc được chữ trong PDF đề test", "err", err)
		}
		return []string{}
	}
	n := min(r.NumPage(), maxPdfPages)
	pages = make([]string, 0, n)
	for i := 1; i <= n; i++ {
		pages = append(pages, pageText(r.Page(i)))
	}
	return pages
}

// pageText ghép các ký tự theo vị trí thành dòng: đổi dòng khi tọa độ dọc đổi quá nửa cỡ chữ, thêm khoảng trắng
// khi hai ký tự cách nhau rõ (chữ trong PDF không nhất thiết có ký tự cách).
func pageText(p pdf.Page) (out string) {
	defer func() {
		if recover() != nil {
			out = ""
		}
	}()
	if p.V.IsNull() {
		return ""
	}
	var b strings.Builder
	var lastX, lastY, lastW, lastSize float64
	first, space := true, false
	for _, t := range p.Content().Text {
		if t.S == "" {
			continue
		}
		size := t.FontSize
		if size <= 0 {
			size = 10
		}
		switch {
		case first:
		case math.Abs(t.Y-lastY) > math.Max(size, lastSize)*0.5:
			b.WriteByte('\n')
		case t.X-(lastX+lastW) > size*0.2 || t.X < lastX-size:
			if !space && t.S != " " {
				b.WriteByte(' ')
			}
		}
		b.WriteString(t.S)
		first, space = false, strings.HasSuffix(t.S, " ")
		lastX, lastY, lastW, lastSize = t.X, t.Y, t.W, size
	}
	return b.String()
}
