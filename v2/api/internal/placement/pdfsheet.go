package placement

import (
	"fmt"
	"regexp"
	"slices"
	"strconv"
	"strings"
	"unicode"
	"unicode/utf8"
)

// Tạo đề test đầu vào từ file PDF (Laravel PlacementPdfAnswerSheet): thí sinh xem nguyên file PDF và trả lời trên phiếu.
// Đọc chữ trong PDF để dựng sẵn phiếu: số câu, kỹ năng (theo tiêu đề phần), dạng câu (A/B/C/D hay điền từ) và đáp án
// nếu PDF có phần "Answer key" / "Đáp án". Kết quả chỉ là bản nháp để Học vụ / Học thuật kiểm tra trước khi lưu.
//
// Biểu thức chính quy giữ nguyên của Laravel; chỗ PCRE dùng lookbehind / lookahead (Go không có) được viết thành
// hàm quét tay cho cùng kết quả. \b, \w, \s của PCRE /u (không bật UCP) chỉ xét ASCII, giống Go.

const sheetMaxQuestions = 200

// sheetLetters: đáp án trắc nghiệm hợp lệ.
var sheetLetters = []string{"A", "B", "C", "D", "E", "F", "G", "H"}

// Số phương án mặc định khi PDF không ghi chữ phương án (câu chọn tranh A/B/C).
const sheetDefaultOptions = 3

// SheetOption: phương án trên phiếu.
type SheetOption struct {
	Key  string `json:"key"`
	Text string `json:"text"`
}

// SheetQuestion: một câu trên phiếu (cùng cấu trúc câu hỏi soạn tay nên tự chấm như cũ).
type SheetQuestion struct {
	ID            int           `json:"id"`
	Number        string        `json:"number"`
	Skill         string        `json:"skill"`
	Type          string        `json:"type"`
	Section       string        `json:"section"`
	Title         string        `json:"title"`
	AudioURL      string        `json:"audio_url"`
	Passage       string        `json:"passage"`
	Options       []SheetOption `json:"options"`
	CorrectAnswer string        `json:"correct_answer"`
	Points        float64       `json:"points"`
	Explanation   string        `json:"explanation"`
	TeacherNote   string        `json:"teacher_note"`
}

// KeyEntry: một mục đáp án (số câu → đáp án).
type KeyEntry struct {
	Number int    `json:"number"`
	Answer string `json:"answer"`
}

// Sheet: phiếu dựng từ chữ của PDF.
type Sheet struct {
	Questions    []SheetQuestion `json:"questions"`
	AnswersFound int             `json:"answers_found"`
	Warnings     []string        `json:"warnings"`
}

type foundQuestion struct {
	number  int
	text    string
	skill   string
	section string
}

// limitRunes: Str::limit (cắt theo ký tự, bỏ khoảng trắng cuối rồi nối end).
func limitRunes(s string, n int, end string) string {
	if utf8.RuneCountInString(s) <= n {
		return s
	}
	return strings.TrimRight(string([]rune(s)[:n]), " ") + end
}

// phpTrim: trim() mặc định của PHP.
func phpTrim(s string) string { return strings.Trim(s, " \t\n\r\x00\x0B") }

var (
	asciiSpaces  = regexp.MustCompile(`[\t\n\v\f\r ]+`)
	multiSpace   = regexp.MustCompile(` {2,}`)
	keyPartRe    = regexp.MustCompile(`(?i)\b(?:part|phần|section|exercise|bài)\s*[\dIVX]+\b[^:\n\d]{0,40}:?`)
	keyQuestRe   = regexp.MustCompile(`(?i)\b(?:question|câu|q)\s*(\d)`)
	headingKeyRe = regexp.MustCompile(`(?i)^(?:answer\s*keys?|answers|đáp\s*án(?:\s*(?:đúng|chi\s*tiết))?)\s*[:.\-–]?\s*(.*)$`)
	headingRest  = regexp.MustCompile(`(?i)^(?:\d|part|phần|section)`)
	questionRe1  = regexp.MustCompile(`(?i)^(?:(?:question|câu|q)\s*)?(\d{1,3})\s*[.):]\s*(.*)$`)
	questionRe2  = regexp.MustCompile(`(?i)^(?:question|câu)\s+(\d{1,3})\b\s*(.*)$`)
	optionLineRe = regexp.MustCompile(`^\(?[A-H][.)]\s|^(?:[A-H]\s+)+[A-H]$`)
	partHeading  = regexp.MustCompile(`(?i)^(?:part|section|phần|exercise|ex\.|bài|task)\s*[\dIVX]+\b`)
	verbHeading  = regexp.MustCompile(`(?i)^(?:listen|look|read|write|choose|circle|tick|fill|complete|match|answer|nghe|đọc|viết|khoanh|điền|chọn|nối|trả lời)\b`)
	essayRe      = regexp.MustCompile(`(?i)\bwords?\b|paragraph|email|letter|story|essay|đoạn văn|bài viết`)
	blankRe      = regexp.MustCompile(`_{2,}|\.{3,}`)
	letterRe     = regexp.MustCompile(`^\(?([A-Ha-h])\)?\.?$`)
	skillRes     = []struct {
		skill string
		re    *regexp.Regexp
	}{
		{"listening", regexp.MustCompile(`(?i)\blisten|\bnghe\b|\baudio\b|\btrack\s*\d`)},
		{"speaking", regexp.MustCompile(`(?i)\bspeak|\bnói\b|\binterview`)},
		{"grammar", regexp.MustCompile(`(?i)\bgrammar|\bvocab|\bngữ pháp|\btừ vựng|use of english`)},
		{"reading", regexp.MustCompile(`(?i)\bread|\bđọc\b`)},
		{"writing", regexp.MustCompile(`(?i)\bwrit|\bviết\b`)},
	}
)

// sheetLines: các dòng không rỗng, đã gộp khoảng trắng.
func sheetLines(text string) []string {
	text = strings.NewReplacer("\r\n", "\n", "\r", "\n", "\t", " ", " ", " ", "…", "...").Replace(text)
	out := []string{}
	for _, l := range strings.Split(text, "\n") {
		if l = phpTrim(multiSpace.ReplaceAllString(l, " ")); l != "" {
			out = append(out, l)
		}
	}
	return out
}

// splitAnswerKey tách phần đáp án (từ dòng tiêu đề "Answer key" / "Đáp án" cuối cùng trở đi); không có → key nil.
func splitAnswerKey(lines []string) ([]string, *string) {
	for i := len(lines) - 1; i >= 0; i-- {
		if m := headingKeyRe.FindStringSubmatch(lines[i]); m != nil && (m[1] == "" || headingRest.MatchString(m[1])) {
			key := phpTrim(m[1] + "\n" + strings.Join(lines[i+1:], "\n"))
			return lines[:i], &key
		}
	}
	return lines, nil
}

// KeyText: phần đáp án của file; file chỉ có đáp án, không tiêu đề → cả file.
func KeyText(text string) string {
	lines := sheetLines(text)
	if _, key := splitAnswerKey(lines); key != nil {
		return *key
	}
	return strings.Join(lines, "\n")
}

// AnswerKeyPage: trang (từ 1) có phần đáp án — file phát cho thí sinh không được còn trang này. nil = không có.
func AnswerKeyPage(pages []string) *int {
	for i := len(pages) - 1; i >= 0; i-- {
		if _, key := splitAnswerKey(sheetLines(pages[i])); key != nil && len(ParseKey(*key)) > 0 {
			p := i + 1
			return &p
		}
	}
	return nil
}

func isASCIIWord(b byte) bool {
	return b >= 'a' && b <= 'z' || b >= 'A' && b <= 'Z' || b >= '0' && b <= '9' || b == '_'
}

func isASCIISpace(b byte) bool {
	return b == ' ' || b == '\t' || b == '\n' || b == '\v' || b == '\f' || b == '\r'
}

type keyMarker struct{ start, end, number int }

// keyMarkers: vị trí số câu trong một dòng đáp án, như regex Laravel
// /(?<![\w.,])(\d{1,3})(?:\s*[.):–-]\s*|\s*(?=[A-H](?![\w'’])))/u — "12." "12)" "12:" "12 -" hoặc dính liền chữ đáp án in hoa.
func keyMarkers(line string) []keyMarker {
	var out []keyMarker
	n := len(line)
	skip := func(i int) int {
		for i < n && isASCIISpace(line[i]) {
			i++
		}
		return i
	}
	for i := 0; i < n; {
		c := line[i]
		if c < '0' || c > '9' || (i > 0 && (isASCIIWord(line[i-1]) || line[i-1] == '.' || line[i-1] == ',')) {
			i++
			continue
		}
		j := i
		for j < n && line[j] >= '0' && line[j] <= '9' {
			j++
		}
		if j-i > 3 {
			i = j
			continue
		}
		num, _ := strconv.Atoi(line[i:j])
		k := skip(j)
		end := -1
		switch {
		case k < n && strings.ContainsRune(".):-", rune(line[k])):
			end = skip(k + 1)
		case strings.HasPrefix(line[k:], "–"):
			end = skip(k + len("–"))
		case k < n && line[k] >= 'A' && line[k] <= 'H':
			next := k + 1
			if next >= n || (!isASCIIWord(line[next]) && line[next] != '\'' && !strings.HasPrefix(line[next:], "’")) {
				end = k
			}
		}
		if end < 0 {
			i = j
			continue
		}
		out = append(out, keyMarker{i, end, num})
		i = max(end, j)
	}
	return out
}

func cleanAnswer(a string) string {
	return limitRunes(strings.Trim(asciiSpaces.ReplaceAllString(a, " "), " \t,;"), 255, "")
}

// ParseKey: đáp án dán nhanh "1A 2B 3. apple", "1-A, 2-B", "Câu 1: A" … → danh sách số câu → đáp án.
func ParseKey(text string) []KeyEntry {
	text = strings.NewReplacer("\r", "\n", "\t", " ", " ", " ").Replace(text)
	// "Part 1 – Listening:" trong bảng đáp án là tiêu đề, không phải câu 1.
	text = keyPartRe.ReplaceAllString(text, "\n")
	text = keyQuestRe.ReplaceAllString(text, "$1")
	entries := []KeyEntry{}
	for _, line := range strings.Split(text, "\n") {
		if line == "" {
			continue
		}
		ms := keyMarkers(line)
		for i, m := range ms {
			end := len(line)
			if i+1 < len(ms) {
				end = ms[i+1].start
			}
			if a := cleanAnswer(line[m.end:end]); a != "" {
				entries = append(entries, KeyEntry{m.number, a})
			}
		}
	}
	return entries
}

// BuildSheet dựng phiếu đáp án từ chữ của PDF.
func BuildSheet(text string) Sheet {
	lines := sheetLines(text)
	if len(lines) == 0 {
		return Sheet{Questions: []SheetQuestion{}, Warnings: []string{"Không đọc được chữ trong file PDF (có thể là bản scan). Nhập số câu để tạo phiếu rồi dán đáp án."}}
	}
	questionLines, keyText := splitAnswerKey(lines)
	key := []KeyEntry{}
	if keyText != nil {
		key = ParseKey(*keyText)
	}
	found := parseSheetQuestions(questionLines)
	// PDF không đánh số câu ở đầu dòng nhưng có bảng đáp án → mỗi mục đáp án là một câu.
	if len(found) == 0 && len(key) > 0 {
		for _, e := range key {
			found = append(found, foundQuestion{number: e.Number, skill: "reading"})
		}
	}
	warnings := []string{}
	if len(found) > sheetMaxQuestions {
		found = found[:sheetMaxQuestions]
	}
	answers := matchKey(found, key)
	switch {
	case len(found) == 0:
		warnings = append(warnings, "Không tìm thấy câu hỏi đánh số trong PDF. Nhập số câu để tạo phiếu rồi dán đáp án.")
	case len(key) == 0:
		warnings = append(warnings, `PDF chưa có phần đáp án (Answer key / Đáp án). Chọn đáp án đúng cho từng câu hoặc dán nhanh dạng "1A 2B 3 apple".`)
	}
	qs := make([]SheetQuestion, 0, len(found))
	for i, q := range found {
		qs = append(qs, sheetQuestion(i+1, q, answers[i]))
	}
	// Bài viết tự luận / Speaking không có đáp án: chỉ so với các câu chấm tự động.
	gradable, missing := 0, 0
	for _, q := range qs {
		if q.Type == "multiple_choice" || q.Type == "fill_blank" {
			gradable++
			if q.CorrectAnswer == "" {
				missing++
			}
		}
	}
	if len(qs) > 0 && len(key) > 0 {
		if len(key) != gradable {
			warnings = append(warnings, fmt.Sprintf("PDF có %d câu chấm tự động nhưng phần đáp án có %d mục — kiểm tra lại từng câu.", gradable, len(key)))
		}
		if missing > 0 {
			warnings = append(warnings, fmt.Sprintf("%d câu chưa có đáp án — những câu này không được tự chấm.", missing))
		}
	}
	n := 0
	for _, a := range answers {
		if a != "" {
			n++
		}
	}
	return Sheet{Questions: qs, AnswersFound: n, Warnings: warnings}
}

// BlankSheet: phiếu trống N câu (PDF scan): mỗi câu trắc nghiệm A–C, kỹ năng Đọc.
func BlankSheet(count int) []SheetQuestion {
	count = max(1, min(sheetMaxQuestions, count))
	out := make([]SheetQuestion, 0, count)
	for n := 1; n <= count; n++ {
		out = append(out, sheetQuestion(n, foundQuestion{number: n, skill: "reading"}, ""))
	}
	return out
}

// parseSheetQuestions: câu hỏi = dòng bắt đầu bằng số thứ tự ("1.", "2)", "Question 3:", "Câu 4."); các dòng sau nối
// vào câu cho tới câu kế tiếp. Dòng tiêu đề phần đổi kỹ năng cho các câu sau.
func parseSheetQuestions(lines []string) []foundQuestion {
	var out []foundQuestion
	var cur *foundQuestion
	skill, section := "reading", ""
	for _, line := range lines {
		m := questionRe1.FindStringSubmatch(line)
		if m == nil {
			m = questionRe2.FindStringSubmatch(line)
		}
		if m != nil {
			if cur != nil {
				out = append(out, *cur)
			}
			n, _ := strconv.Atoi(m[1])
			s := skill
			if skillOf(m[2]) == "listening" {
				s = "listening"
			}
			cur = &foundQuestion{number: n, text: m[2], skill: s, section: section}
			continue
		}
		if isSheetHeading(line) {
			if cur != nil {
				out = append(out, *cur)
				cur = nil
			}
			if s := skillOf(line); s != "" {
				skill = s
			}
			section = limitRunes(line, 120, "…")
			continue
		}
		if cur != nil && utf8.RuneCountInString(cur.text) < 2000 {
			cur.text += "\n" + line
		}
	}
	if cur != nil {
		out = append(out, *cur)
	}
	return out
}

func isSheetHeading(line string) bool {
	if optionLineRe.MatchString(line) {
		return false // dòng phương án "A. YES  B. NO" / nhãn tranh "A B C D"
	}
	if partHeading.MatchString(line) || verbHeading.MatchString(line) {
		return true
	}
	var letters []rune
	for _, r := range line {
		if unicode.IsLetter(r) {
			letters = append(letters, r)
		}
	}
	l := string(letters)
	return len(letters) >= 4 && utf8.RuneCountInString(line) <= 80 && l == strings.ToUpper(l)
}

func skillOf(text string) string {
	for _, s := range skillRes {
		if s.re.MatchString(text) {
			return s.skill
		}
	}
	return ""
}

// matchKey ghép đáp án vào câu: số câu trong đề và trong đáp án đều không trùng → theo số câu; đề đánh số lại từng
// phần → theo thứ tự.
func matchKey(qs []foundQuestion, key []KeyEntry) []string {
	unique := func(ns []int) bool {
		seen := map[int]bool{}
		for _, n := range ns {
			if seen[n] {
				return false
			}
			seen[n] = true
		}
		return true
	}
	qn := make([]int, len(qs))
	for i, q := range qs {
		qn[i] = q.number
	}
	kn := make([]int, len(key))
	byNum := map[int]string{}
	for i, k := range key {
		kn[i] = k.Number
		byNum[k.Number] = k.Answer
	}
	byNumber := unique(qn) && unique(kn)
	out := make([]string, len(qs))
	for i, q := range qs {
		if byNumber {
			out[i] = byNum[q.number]
		} else if i < len(key) {
			out[i] = key[i].Answer
		}
	}
	return out
}

func sheetLetter(answer string) string {
	if m := letterRe.FindStringSubmatch(phpTrim(answer)); m != nil {
		return strings.ToUpper(m[1])
	}
	return ""
}

func sheetQuestion(id int, f foundQuestion, answer string) SheetQuestion {
	stem, options := splitOptions(f.text)
	letter := sheetLetter(answer)
	var typ string
	switch {
	case len(options) >= 2:
		typ = "multiple_choice"
	case f.skill == "speaking":
		typ = "speaking_prompt"
	case answer == "" && f.skill == "writing" && essayRe.MatchString(stem):
		typ = "essay"
	case (answer != "" && letter == "") || (answer == "" && blankRe.MatchString(stem)):
		typ = "fill_blank"
	default:
		typ = "multiple_choice"
	}
	if typ == "multiple_choice" && len(options) < 2 {
		last := sheetDefaultOptions
		if letter != "" {
			last = max(last, slices.Index(sheetLetters, letter)+1)
		}
		options = []SheetOption{}
		for _, k := range sheetLetters[:last] {
			options = append(options, SheetOption{Key: k})
		}
	}
	if typ == "multiple_choice" {
		ok := false
		for _, o := range options {
			ok = ok || (letter != "" && o.Key == letter)
		}
		if ok {
			answer = letter
		} else {
			answer = ""
		}
	}
	if typ != "multiple_choice" {
		options = []SheetOption{}
	}
	if typ != "multiple_choice" && typ != "fill_blank" {
		answer = ""
	}
	return SheetQuestion{
		ID: id, Number: strconv.Itoa(f.number), Skill: f.skill, Type: typ, Section: f.section,
		Title:   limitRunes(phpTrim(asciiSpaces.ReplaceAllString(stem, " ")), 500, "…"),
		Options: options, CorrectAnswer: answer, Points: 1,
	}
}

type optMarker struct {
	key        string
	start, end int
}

// optionMatches: phương án "A." "B)" "(C)" "d)" đứng sau khoảng trắng / đầu chuỗi và trước khoảng trắng / cuối chuỗi
// (regex Laravel /(?:^|(?<=\s))(?:\(([A-Ha-h])\)|([A-H])[.)]|([a-h])\))(?=\s|$)/u).
func optionMatches(text string) []optMarker {
	var out []optMarker
	n := len(text)
	endOK := func(i int) bool { return i >= n || isASCIISpace(text[i]) }
	for i := 0; i < n; {
		if i > 0 && !isASCIISpace(text[i-1]) {
			i++
			continue
		}
		c := text[i]
		var key string
		w := 0
		switch {
		case c == '(' && i+2 < n && text[i+2] == ')' && ((text[i+1] >= 'A' && text[i+1] <= 'H') || (text[i+1] >= 'a' && text[i+1] <= 'h')):
			key, w = strings.ToUpper(text[i+1:i+2]), 3
		case c >= 'A' && c <= 'H' && i+1 < n && (text[i+1] == '.' || text[i+1] == ')'):
			key, w = string(c), 2
		case c >= 'a' && c <= 'h' && i+1 < n && text[i+1] == ')':
			key, w = strings.ToUpper(string(c)), 2
		}
		if key != "" && endOK(i+w) {
			out = append(out, optMarker{key, i, i + w})
			i += w
			continue
		}
		i++
	}
	return out
}

// splitOptions tách phương án A./B./C. (hoặc (A), a)) khỏi nội dung câu. Chỉ nhận dãy liên tiếp bắt đầu từ A.
func splitOptions(text string) (string, []SheetOption) {
	ms := optionMatches(text)
	if len(ms) == 0 {
		return pictureOptions(text)
	}
	var markers []optMarker
	expected := 0
	for _, m := range ms {
		if m.key == sheetLetters[0] && expected > 0 && len(markers) < 2 {
			// "A" sau một "A" lẻ (VD: chữ "A." trong câu) → bắt đầu lại dãy.
			markers, expected = nil, 0
		}
		if expected < len(sheetLetters) && m.key == sheetLetters[expected] {
			markers = append(markers, m)
			expected++
		}
	}
	if len(markers) < 2 {
		return pictureOptions(text)
	}
	opts := make([]SheetOption, 0, len(markers))
	for i, m := range markers {
		end := len(text)
		if i+1 < len(markers) {
			end = markers[i+1].start
		}
		opts = append(opts, SheetOption{Key: m.key, Text: limitRunes(phpTrim(asciiSpaces.ReplaceAllString(text[m.end:end], " ")), 255, "…")})
	}
	return text[:markers[0].start], opts
}

// pictureOptions: câu chọn tranh — dưới câu hỏi chỉ có dòng nhãn tranh "A B C" (chữ không kèm dấu chấm) → số
// phương án theo nhãn, nội dung trống (regex Laravel /\n\s*((?:[A-H]\s+){1,7}[A-H])\s*(?=\n|$)/u).
func pictureOptions(text string) (string, []SheetOption) {
	n := len(text)
	for nl := strings.IndexByte(text, '\n'); nl >= 0; {
		if end, letters, ok := pictureAt(text, nl); ok {
			want := sheetLetters[:min(len(letters), len(sheetLetters))]
			if !slices.Equal(letters, want) {
				return text, []SheetOption{}
			}
			opts := make([]SheetOption, 0, len(letters))
			for _, l := range letters {
				opts = append(opts, SheetOption{Key: l})
			}
			return text[:nl] + text[end:], opts
		}
		next := strings.IndexByte(text[nl+1:], '\n')
		if next < 0 || nl+1 >= n {
			break
		}
		nl += 1 + next
	}
	return text, []SheetOption{}
}

// pictureAt thử khớp nhãn tranh bắt đầu từ ký tự xuống dòng ở nl; trả vị trí kết thúc của cả cụm và các chữ.
func pictureAt(text string, nl int) (int, []string, bool) {
	n := len(text)
	i := nl + 1
	for i < n && isASCIISpace(text[i]) {
		i++
	}
	// Các chữ A–H đơn lẻ, cách nhau bằng khoảng trắng.
	type tok struct{ pos, after int }
	var toks []tok
	for i < n && text[i] >= 'A' && text[i] <= 'H' && len(toks) < 8 {
		j := i + 1
		if j < n && !isASCIISpace(text[j]) {
			// chữ dính ký tự khác: chỉ dùng được làm chữ cuối nếu lookahead đúng (không đúng vì sau nó không phải \n / hết)
			toks = append(toks, tok{i, j})
			break
		}
		toks = append(toks, tok{i, j})
		for j < n && isASCIISpace(text[j]) {
			j++
		}
		i = j
	}
	// Tham lam như PCRE: thử dãy dài nhất (2..8 chữ) trước.
	for k := len(toks); k >= 2; k-- {
		last := toks[k-1]
		j := last.after
		lastNL := -1
		for j < n && isASCIISpace(text[j]) {
			if text[j] == '\n' {
				lastNL = j
			}
			j++
		}
		var end int
		switch {
		case j >= n:
			end = n
		case lastNL >= 0:
			end = lastNL
		default:
			continue
		}
		if last.after < n && !isASCIISpace(text[last.after]) {
			continue
		}
		letters := make([]string, 0, k)
		for _, t := range toks[:k] {
			letters = append(letters, text[t.pos:t.pos+1])
		}
		return end, letters, true
	}
	return 0, nil, false
}
