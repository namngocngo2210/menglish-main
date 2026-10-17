package placement

import (
	"bytes"
	"fmt"
	"strings"
	"testing"
)

// testPDF dựng file PDF tối thiểu (Helvetica, mỗi dòng một lệnh Tj) để test đọc chữ / tải đề PDF.
func testPDF(pages ...[]string) []byte {
	var objs []string
	pageIDs := []string{}
	// 1: Catalog, 2: Pages, 3: Font, sau đó mỗi trang 2 đối tượng (Page, nội dung).
	for i, lines := range pages {
		pageID, contentID := 4+2*i, 5+2*i
		pageIDs = append(pageIDs, fmt.Sprintf("%d 0 R", pageID))
		var cs strings.Builder
		for j, l := range lines {
			esc := strings.NewReplacer(`\`, `\\`, "(", `\(`, ")", `\)`).Replace(l)
			fmt.Fprintf(&cs, "BT /F1 12 Tf 50 %d Td (%s) Tj ET\n", 780-j*18, esc)
		}
		objs = append(objs,
			fmt.Sprintf("<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R >> >> /Contents %d 0 R >>", contentID),
			fmt.Sprintf("<< /Length %d >>\nstream\n%sendstream", cs.Len(), cs.String()))
	}
	head := []string{
		"<< /Type /Catalog /Pages 2 0 R >>",
		fmt.Sprintf("<< /Type /Pages /Kids [%s] /Count %d >>", strings.Join(pageIDs, " "), len(pages)),
		"<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>",
	}
	objs = append(head, objs...)
	var b bytes.Buffer
	b.WriteString("%PDF-1.4\n")
	offsets := make([]int, len(objs))
	for i, o := range objs {
		offsets[i] = b.Len()
		fmt.Fprintf(&b, "%d 0 obj\n%s\nendobj\n", i+1, o)
	}
	xref := b.Len()
	fmt.Fprintf(&b, "xref\n0 %d\n0000000000 65535 f \n", len(objs)+1)
	for _, off := range offsets {
		fmt.Fprintf(&b, "%010d 00000 n \n", off)
	}
	fmt.Fprintf(&b, "trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF\n", len(objs)+1, xref)
	return b.Bytes()
}

// Đề mẫu như pdfUpload() của test Laravel (chữ ASCII vì font chuẩn không có tiếng Việt).
func samplePDF(withKey bool) []byte {
	page := []string{"PART 1. LISTENING", "Listen and tick the correct picture.", "1. What is Tom doing?",
		"Part 2: Read and choose", "2. She ___ to school.", "A. go  B. goes  C. going", "3. This is a ___ ."}
	if withKey {
		page = append(page, "ANSWER KEY", "1B 2B 3. pencil")
	}
	return testPDF(page)
}

func TestExtractPdfPages(t *testing.T) {
	b := testPDF([]string{"1. What is (Tom) doing?", "A. yes B. no"}, []string{"ANSWER KEY", "1A"})
	pages := ExtractPdfPages(bytes.NewReader(b), int64(len(b)), nil)
	if len(pages) != 2 || pages[0] != "1. What is (Tom) doing?\nA. yes B. no" || pages[1] != "ANSWER KEY\n1A" {
		t.Fatalf("trang %q", pages)
	}
	if p := AnswerKeyPage(pages); p == nil || *p != 2 {
		t.Fatalf("trang đáp án %v", p)
	}
	// File hỏng / không phải PDF → rỗng, không panic
	if got := ExtractPdfPages(bytes.NewReader([]byte("%PDF-1.4 hỏng")), 14, nil); len(got) != 0 {
		t.Fatalf("file hỏng: %q", got)
	}

	sheet := BuildSheet(strings.Join(ExtractPdfPages(bytes.NewReader(samplePDF(true)), int64(len(samplePDF(true))), nil), "\n"))
	if len(sheet.Questions) != 3 || sheet.AnswersFound != 3 || sheet.Questions[0].Skill != "listening" ||
		sheet.Questions[1].CorrectAnswer != "B" || sheet.Questions[2].CorrectAnswer != "pencil" {
		t.Fatalf("phiếu %+v", sheet)
	}
}
