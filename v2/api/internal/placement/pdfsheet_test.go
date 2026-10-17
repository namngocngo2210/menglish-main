package placement

import (
	"reflect"
	"strings"
	"testing"
)

// Các ca giống tests/Feature/PlacementPdfTestTest.php của Laravel.

func TestSheetFromNumberedQuestionsOptionsSectionsAndKey(t *testing.T) {
	sheet := BuildSheet(strings.Join([]string{
		"MENGLISH PLACEMENT TEST - GRADE 3",
		"PART 1. LISTENING",
		"Listen and tick the correct picture.",
		"1. What is Tom doing?",
		"2. Where is the cat?",
		"A B C D",
		"Part 2: Read and choose the correct answer.",
		"3. She ___ to school every day.",
		"A. go B. goes C. going D. gone",
		"4. They ___ football yesterday.",
		"A. play",
		"B. played",
		"5. Look and write: This is a ________ .",
		"WRITING",
		"6. Write about your family (30 - 40 words).",
		"ANSWER KEY",
		"Part 1: 1B 2A",
		"Part 2: 3. B 4. b 5. pencil | pen",
	}, "\n"))
	if len(sheet.Questions) != 6 || len(sheet.Warnings) != 0 || sheet.AnswersFound != 5 {
		t.Fatalf("phiếu %+v", sheet)
	}
	q := map[string]SheetQuestion{}
	for _, x := range sheet.Questions {
		q[x.Number] = x
	}
	keys := func(x SheetQuestion) []string {
		out := []string{}
		for _, o := range x.Options {
			out = append(out, o.Key)
		}
		return out
	}
	check := func(name string, got, want any) {
		t.Helper()
		if !reflect.DeepEqual(got, want) {
			t.Errorf("%s: nhận %v, muốn %v", name, got, want)
		}
	}
	// Câu chọn tranh không ghi chữ phương án → trắc nghiệm A–C, kỹ năng Nghe theo tiêu đề phần.
	check("câu 1", []any{q["1"].Skill, q["1"].Type, q["1"].CorrectAnswer, keys(q["1"])}, []any{"listening", "multiple_choice", "B", []string{"A", "B", "C"}})
	// Dòng nhãn tranh "A B C D" dưới câu hỏi → 4 phương án, không lẫn vào nội dung câu.
	check("câu 2 phương án", keys(q["2"]), []string{"A", "B", "C", "D"})
	check("câu 2 tiêu đề", q["2"].Title, "Where is the cat?")
	check("câu 3", []any{q["3"].Skill, q["3"].Type, q["3"].CorrectAnswer}, []any{"reading", "multiple_choice", "B"})
	texts := []string{}
	for _, o := range q["3"].Options {
		texts = append(texts, o.Text)
	}
	check("câu 3 phương án", texts, []string{"go", "goes", "going", "gone"})
	check("câu 3 tiêu đề", q["3"].Title, "She ___ to school every day.")
	check("câu 4", q["4"].CorrectAnswer, "B")
	check("câu 5", []any{q["5"].Type, q["5"].CorrectAnswer}, []any{"fill_blank", "pencil | pen"})
	check("câu 6", []any{q["6"].Skill, q["6"].Type, q["6"].CorrectAnswer}, []any{"writing", "essay", ""})
}

func TestSheetAnswersFollowOrderWhenPartsRestartNumbering(t *testing.T) {
	sheet := BuildSheet("Part 1 Listen and tick\n1. Cat\n2. Dog\nPart 2 Read and choose\n1. Sun\n2. Moon\nĐáp án:\nPart 1: 1C 2A\nPart 2: 1B 2C")
	var answers, skills []string
	var ids []int
	for _, q := range sheet.Questions {
		answers, skills, ids = append(answers, q.CorrectAnswer), append(skills, q.Skill), append(ids, q.ID)
	}
	if !reflect.DeepEqual(answers, []string{"C", "A", "B", "C"}) || !reflect.DeepEqual(skills, []string{"listening", "listening", "reading", "reading"}) ||
		!reflect.DeepEqual(ids, []int{1, 2, 3, 4}) {
		t.Fatalf("đáp án %v kỹ năng %v id %v", answers, skills, ids)
	}
}

func TestSheetScannedPdf(t *testing.T) {
	sheet := BuildSheet("")
	if len(sheet.Questions) != 0 || !strings.Contains(sheet.Warnings[0], "bản scan") {
		t.Fatalf("%+v", sheet)
	}
	if len(BlankSheet(30)) != 30 || len(BlankSheet(999)) != 200 || len(BlankSheet(0)) != 1 {
		t.Fatal("phiếu trống")
	}
	b := BlankSheet(2)[1]
	if b.Number != "2" || b.Type != "multiple_choice" || len(b.Options) != 3 || b.Points != 1 {
		t.Fatalf("%+v", b)
	}
}

func TestParseKeyCommonFormats(t *testing.T) {
	got := ParseKey("1. A  2. b 3 - seven | 7 4) C\nCâu 12: D")
	want := []KeyEntry{{1, "A"}, {2, "b"}, {3, "seven | 7"}, {4, "C"}, {12, "D"}}
	if !reflect.DeepEqual(got, want) {
		t.Fatalf("nhận %v", got)
	}
	if got := ParseKey("1A 2B"); !reflect.DeepEqual(got, []KeyEntry{{1, "A"}, {2, "B"}}) {
		t.Fatalf("1A 2B: %v", got)
	}
	// "Part 1 – Listening:" là tiêu đề; năm 2026 không phải số câu; "1-A, 2-B"
	if got := ParseKey("Part 1 – Listening: 1-A, 2-B\nNăm 2026"); !reflect.DeepEqual(got, []KeyEntry{{1, "A"}, {2, "B"}}) {
		t.Fatalf("tiêu đề phần: %v", got)
	}
	// Chữ đáp án in hoa dính chữ khác ("12Apple") không phải đáp án chữ cái
	if got := ParseKey("12 Apple"); len(got) != 0 {
		t.Fatalf("12 Apple: %v", got)
	}
}

func TestAnswerKeyPageAndKeyText(t *testing.T) {
	pages := []string{"1. What?\n2. Who?", "ANSWER KEY\n1B 2B 3. bút chì"}
	if p := AnswerKeyPage(pages); p == nil || *p != 2 {
		t.Fatalf("trang đáp án %v", p)
	}
	if p := AnswerKeyPage(pages[:1]); p != nil {
		t.Fatalf("không có đáp án mà nhận trang %d", *p)
	}
	if got := ParseKey(KeyText(strings.Join(pages, "\n"))); !reflect.DeepEqual(got, []KeyEntry{{1, "B"}, {2, "B"}, {3, "bút chì"}}) {
		t.Fatalf("đáp án %v", got)
	}
	// File chỉ có đáp án, không có tiêu đề → cả file
	if KeyText("1A\n2C") != "1A\n2C" {
		t.Fatal(KeyText("1A\n2C"))
	}
}

func TestSplitOptionsResetsOnStrayA(t *testing.T) {
	stem, opts := splitOptions("Choose A. word\nA. cat B. dog")
	if strings.TrimSpace(stem) != "Choose A. word" || len(opts) != 2 || opts[0].Text != "cat" {
		t.Fatalf("%q %+v", stem, opts)
	}
	stem, opts = splitOptions("Pick (a) red (b) blue")
	if stem != "Pick " || len(opts) != 2 || opts[1].Key != "B" || opts[1].Text != "blue" {
		t.Fatalf("%q %+v", stem, opts)
	}
	if _, opts := splitOptions("Look at the picture\nA C B"); len(opts) != 0 {
		t.Fatalf("nhãn tranh không theo thứ tự: %+v", opts)
	}
}
