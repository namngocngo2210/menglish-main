package placement

import (
	"bytes"
	"encoding/json"
	"fmt"
	"math"
	"regexp"
	"strconv"
	"strings"
	"time"
	"unicode/utf8"
)

// Cấp độ đề = lớp hiện tại của khách (Laravel PlacementTest::GRADE_LEVELS), theo thứ tự hiển thị.
var gradeLevels = []GradeGroup{
	{"mau_giao", "Mẫu giáo"}, {"lop_1", "Lớp 1"}, {"lop_2", "Lớp 2"}, {"lop_3", "Lớp 3"}, {"lop_4", "Lớp 4"},
	{"lop_5", "Lớp 5"}, {"lop_6", "Lớp 6"}, {"lop_7", "Lớp 7"}, {"lop_8", "Lớp 8"}, {"lop_9", "Lớp 9"},
}

// Thang điểm theo cấp độ; cấp độ không có thang → chấm thủ công.
var levelRubricGroups = map[string]string{"lop_1": "khoi_1_2", "lop_2": "khoi_2_3", "lop_3": "khoi_3_4", "lop_4": "khoi_4_5"}

// GradeCodeTokens: mã đề phải chứa khối lớp để hệ thống chấm theo thang điểm.
var GradeCodeTokens = map[string]string{"khoi_1_2": "G1-G2", "khoi_2_3": "G2-G3", "khoi_3_4": "G3-G4", "khoi_4_5": "G4-G5", ManualGroup: "KHAC"}

func gradeLevelLabel(level string) string {
	for _, l := range gradeLevels {
		if l.Value == level {
			return l.Label
		}
	}
	return ""
}

func validGradeLevel(level string) bool { return gradeLevelLabel(level) != "" }

// RubricGroupForLevel: khối chấm theo cấp độ.
func RubricGroupForLevel(level string) string {
	if g, ok := levelRubricGroups[level]; ok {
		return g
	}
	return ManualGroup
}

var gradeInCode = regexp.MustCompile(`(?:^|[-_])G([1-9])(?:[-_]|$)`)

// DetectGradeLevel: cấp độ mặc định theo mã đề (TEST-G3-G4 → Lớp 3), PRE-G1 → Mẫu giáo.
func DetectGradeLevel(code string) string {
	c := strings.ToUpper(code)
	if strings.Contains(c, "PRE-G1") || strings.Contains(c, "PRE_G1") {
		return "mau_giao"
	}
	if m := gradeInCode.FindStringSubmatch(c); m != nil {
		return "lop_" + m[1]
	}
	return ""
}

// flexString nhận chuỗi, số hoặc null trong JSON (đề cũ / đề do giao diện gửi có id là số).
type flexString string

func (f *flexString) UnmarshalJSON(b []byte) error {
	b = bytes.TrimSpace(b)
	switch {
	case bytes.Equal(b, []byte("null")):
		*f = ""
	case len(b) > 0 && b[0] == '"':
		var s string
		if err := json.Unmarshal(b, &s); err != nil {
			return err
		}
		*f = flexString(s)
	case bytes.Equal(b, []byte("true")), bytes.Equal(b, []byte("false")):
		*f = flexString(b)
	default:
		var n json.Number
		if err := json.Unmarshal(b, &n); err != nil {
			return fmt.Errorf("giá trị không hợp lệ: %s", b)
		}
		*f = flexString(n.String())
	}
	return nil
}

// flexNumber nhận số hoặc chuỗi số ("1.5"); rỗng / không phải số → nil.
type flexNumber struct{ v *float64 }

func (f *flexNumber) UnmarshalJSON(b []byte) error {
	var s flexString
	if err := s.UnmarshalJSON(b); err != nil {
		return err
	}
	f.v = nil
	if v, err := strconv.ParseFloat(strings.TrimSpace(string(s)), 64); err == nil && !math.IsNaN(v) && !math.IsInf(v, 0) {
		f.v = &v
	}
	return nil
}

func (f flexNumber) MarshalJSON() ([]byte, error) {
	if f.v == nil {
		return []byte("null"), nil
	}
	return json.Marshal(*f.v)
}

// Option: phương án trắc nghiệm.
type Option struct {
	Key      flexString `json:"key"`
	Text     flexString `json:"text"`
	ImageURL string     `json:"image_url,omitempty"`
}

// Question: câu hỏi trong đề (JSONB placement.tests.questions). Đáp án, giải thích, ghi chú giáo viên chỉ người soạn / chấm thấy.
type Question struct {
	ID            flexString `json:"id"`
	Number        flexString `json:"number,omitempty"` // số câu in trong file PDF
	Section       string     `json:"section,omitempty"`
	Skill         string     `json:"skill"`
	Type          string     `json:"type"`
	Title         string     `json:"title"`
	Passage       string     `json:"passage,omitempty"`
	AudioURL      string     `json:"audio_url,omitempty"`
	ImageURL      string     `json:"image_url,omitempty"`
	Options       []Option   `json:"options,omitempty"`
	CorrectAnswer flexString `json:"correct_answer"`
	Points        flexNumber `json:"points"`
	Explanation   string     `json:"explanation,omitempty"`
	TeacherNote   string     `json:"teacher_note,omitempty"`
	RubricNote    string     `json:"rubric_note,omitempty"`
	CuePoints     string     `json:"cue_points,omitempty"`
	MinWords      flexNumber `json:"min_words,omitzero"`
}

func (f flexNumber) IsZero() bool { return f.v == nil }

var (
	questionSkills = map[string]bool{"listening": true, "reading": true, "grammar": true, "writing": true, "speaking": true, "general": true}
	questionTypes  = map[string]bool{"multiple_choice": true, "single_choice": true, "true_false": true, "fill_blank": true, "essay": true, "speaking_prompt": true}
)

const maxQuestions = 500

// points: điểm câu (không ghi hoặc ≤ 0 tính 1).
func (q Question) points() float64 {
	if q.Points.v != nil && *q.Points.v > 0 {
		return *q.Points.v
	}
	return 1
}

func tooLong(s string, n int) bool { return utf8.RuneCountInString(s) > n }

// normalizeQuestions kiểm tra và chuẩn hóa câu hỏi trước khi lưu: kỹ năng / loại hợp lệ, mỗi câu có id riêng
// (câu trả lời của thí sinh gắn theo id), điểm mặc định 1.
func normalizeQuestions(raw json.RawMessage) ([]Question, error) {
	raw = bytes.TrimSpace(raw)
	if len(raw) == 0 || bytes.Equal(raw, []byte("null")) {
		return []Question{}, nil
	}
	// Form cũ gửi câu hỏi dạng chuỗi JSON.
	if raw[0] == '"' {
		var s string
		if err := json.Unmarshal(raw, &s); err != nil {
			return nil, errInvalid("Danh sách câu hỏi không hợp lệ.")
		}
		raw = []byte(s)
	}
	var qs []Question
	if err := json.Unmarshal(raw, &qs); err != nil {
		return nil, errInvalid("Danh sách câu hỏi không hợp lệ.")
	}
	if len(qs) > maxQuestions {
		return nil, errInvalid(fmt.Sprintf("Đề thi tối đa %d câu hỏi.", maxQuestions))
	}
	seen := map[string]bool{}
	for i := range qs {
		q := &qs[i]
		n := i + 1
		q.Skill, q.Type = strings.TrimSpace(q.Skill), strings.TrimSpace(q.Type)
		if q.Skill == "" {
			q.Skill = "general"
		}
		if q.Type == "" {
			q.Type = "multiple_choice"
		}
		if !questionSkills[q.Skill] {
			return nil, errInvalid(fmt.Sprintf("Câu %d: kỹ năng không hợp lệ.", n))
		}
		if !questionTypes[q.Type] {
			return nil, errInvalid(fmt.Sprintf("Câu %d: loại câu hỏi không hợp lệ.", n))
		}
		if tooLong(q.Title, 5000) || tooLong(q.Passage, 20000) || tooLong(q.Explanation, 5000) || tooLong(q.TeacherNote, 5000) ||
			tooLong(q.RubricNote, 2000) || tooLong(q.CuePoints, 5000) || tooLong(string(q.CorrectAnswer), 1000) ||
			tooLong(q.Section, 255) || tooLong(string(q.Number), 20) || tooLong(q.AudioURL, 500) || tooLong(q.ImageURL, 500) {
			return nil, errInvalid(fmt.Sprintf("Câu %d: nội dung quá dài.", n))
		}
		if len(q.Options) > 12 {
			return nil, errInvalid(fmt.Sprintf("Câu %d: tối đa 12 phương án.", n))
		}
		for _, o := range q.Options {
			if tooLong(string(o.Key), 10) || tooLong(string(o.Text), 2000) || tooLong(o.ImageURL, 500) {
				return nil, errInvalid(fmt.Sprintf("Câu %d: phương án quá dài.", n))
			}
		}
		id := strings.TrimSpace(string(q.ID))
		if id == "" || seen[id] || tooLong(id, 40) || id == "speaking_self_rate" {
			id = strconv.Itoa(n)
			for seen[id] {
				id = "q" + id
			}
		}
		seen[id] = true
		q.ID = flexString(id)
		if q.Points.v == nil || *q.Points.v <= 0 {
			one := 1.0
			q.Points.v = &one
		}
	}
	return qs, nil
}

func parseQuestions(b []byte) []Question {
	var qs []Question
	if len(b) > 0 && json.Unmarshal(b, &qs) == nil {
		return qs
	}
	return []Question{}
}

// answerKey: khóa câu trả lời của câu hỏi (id; đề cũ thiếu id dùng vị trí).
func (q Question) answerKey(idx int) string {
	if q.ID != "" {
		return string(q.ID)
	}
	return strconv.Itoa(idx)
}

// AnswerMatches so đáp án không phân biệt hoa thường, bỏ khoảng trắng thừa và dấu chấm câu cuối.
// Đáp án chuẩn có thể liệt kê nhiều cách viết được chấp nhận, ngăn cách bằng "|" (VD: "7 | seven").
func AnswerMatches(answer, correct string) bool {
	given := normalizeAnswer(answer)
	for _, accepted := range strings.Split(correct, "|") {
		if a := normalizeAnswer(accepted); a != "" && given == a {
			return true
		}
	}
	return false
}

var spaces = regexp.MustCompile(`\s+`)

func normalizeAnswer(s string) string {
	return strings.ToLower(strings.TrimRight(spaces.ReplaceAllString(strings.TrimSpace(s), " "), " .!?"))
}

// keepAnswers: chỉ giữ câu trả lời (đã cắt khoảng trắng, không rỗng) của các câu có trong đề.
func keepAnswers(qs []Question, answers map[string]string) map[string]string {
	kept := map[string]string{}
	for i, q := range qs {
		if a, ok := answers[q.answerKey(i)]; ok {
			if a = strings.TrimSpace(a); a != "" {
				kept[q.answerKey(i)] = a
			}
		}
	}
	return kept
}

// autoGradeSkill chấm tự động các câu của kỹ năng theo đáp án chuẩn, quy về thang của khối
// (điểm câu đúng / tổng điểm câu × điểm tối đa, làm tròn 0,5). Chỉ tính câu có đáp án; bài viết tự luận Học vụ chấm.
// Đề không có câu nào của kỹ năng (hoặc không có đáp án) → nil, không bịa điểm.
func autoGradeSkill(qs []Question, answers map[string]string, skills []string, max float64) *float64 {
	var total, correct float64
	for i, q := range qs {
		key := strings.TrimSpace(string(q.CorrectAnswer))
		if key == "" || !contains(skills, q.Skill) {
			continue
		}
		p := q.points()
		total += p
		if a := answers[q.answerKey(i)]; a != "" && AnswerMatches(a, key) {
			correct += p
		}
	}
	if total <= 0 {
		return nil
	}
	v := math.Round(correct/total*max*2) / 2
	return &v
}

func contains(list []string, s string) bool {
	for _, x := range list {
		if x == s {
			return true
		}
	}
	return false
}

// Violation: một lần rời bài thi (khóa màn hình).
type Violation struct {
	Type string `json:"type"`
	At   string `json:"at"`
}

// ViolationTypes: loại vi phạm trang làm bài ghi nhận được → nhãn cho người chấm.
var ViolationTypes = map[string]string{
	"hidden":          "Chuyển sang tab / ứng dụng khác",
	"blur":            "Rời khỏi cửa sổ bài thi",
	"fullscreen_exit": "Thoát chế độ toàn màn hình",
}

// MaxViolations: rời bài tới lần thứ này thì bài tự nộp.
const MaxViolations = 3

// sanitizeViolations: chỉ giữ loại vi phạm đã biết và thời điểm, tối đa 50 dòng.
func sanitizeViolations(in []Violation) []Violation {
	var out []Violation
	for i, v := range in {
		if i >= 50 {
			break
		}
		if _, ok := ViolationTypes[v.Type]; !ok {
			continue
		}
		at := v.At
		if len(at) > 40 {
			at = at[:40]
		}
		out = append(out, Violation{v.Type, at})
	}
	return out
}

// formatScore: điểm dạng "7.5" / "8" (bỏ số 0 thừa); chưa có điểm → empty.
func formatScore(v *float64, empty string) string {
	if v == nil {
		return empty
	}
	return strconv.FormatFloat(math.Round(*v*10)/10, 'f', -1, 64)
}

// ---- Trang làm bài công khai: chỉ phần thí sinh cần thấy (không đáp án, giải thích, ghi chú giáo viên) ----

// PortalOption: phương án trên trang làm bài.
type PortalOption struct {
	Key      string `json:"key"`
	Text     string `json:"text"`
	ImageURL string `json:"image_url,omitempty"`
}

// PortalQuestion: câu hỏi trên trang làm bài.
type PortalQuestion struct {
	Number    int            `json:"number"`
	AnswerKey string         `json:"answer_key"`
	Type      string         `json:"type"`
	Title     string         `json:"title"`
	Passage   string         `json:"passage,omitempty"`
	ImageURL  string         `json:"image_url,omitempty"`
	AudioSrc  string         `json:"audio_src,omitempty"`
	Options   []PortalOption `json:"options"`
}

// SheetRow: một dòng phiếu trả lời của đề PDF.
type SheetRow struct {
	Number    string   `json:"number"`
	Section   string   `json:"section"`
	AnswerKey string   `json:"answer_key"`
	Type      string   `json:"type"`
	Options   []string `json:"options"`
}

// SpeakingPrompt: gợi ý chủ đề Speaking.
type SpeakingPrompt struct {
	Title     string `json:"title"`
	CuePoints string `json:"cue_points"`
}

// PortalContent: câu hỏi cho trang làm bài.
type PortalContent struct {
	Listening     []PortalQuestion `json:"listening"`
	Reading       []PortalQuestion `json:"reading"`
	WritingPrompt *string          `json:"writing_prompt"`
	Speaking      *SpeakingPrompt  `json:"speaking"`
	Sheet         []SheetRow       `json:"sheet"`
}

const defaultWritingPrompt = "Hãy viết một đoạn văn ngắn giới thiệu về bản thân, sở thích hoặc một chuyến đi đáng nhớ của bạn."

// audioSrc: đường dẫn file nghe cho thẻ <audio> (URL đầy đủ hoặc đường dẫn gốc web).
func audioSrc(u string) string {
	u = strings.TrimSpace(u)
	if u == "" || strings.HasPrefix(u, "http") || strings.HasPrefix(u, "/") {
		return u
	}
	return "/" + u
}

// portalContent: đề PDF → phiếu trả lời theo số câu in trong PDF (mọi câu trắc nghiệm / điền từ); phần Viết chỉ khi
// đề có câu tự luận. Đề soạn từng câu → câu Nghe, câu Đọc + Ngữ pháp, đề Viết, gợi ý Nói.
func portalContent(qs []Question, pdfMode bool) PortalContent {
	c := PortalContent{Listening: []PortalQuestion{}, Reading: []PortalQuestion{}, Sheet: []SheetRow{}}
	if pdfMode {
		var essay *Question
		for i, q := range qs {
			if q.Type == "essay" && essay == nil {
				essay = &qs[i]
			}
			if q.Type != "multiple_choice" && q.Type != "fill_blank" {
				continue
			}
			num := strings.TrimSpace(string(q.Number))
			if num == "" {
				num = strconv.Itoa(i + 1)
			}
			keys := []string{}
			for _, o := range q.Options {
				if o.Key != "" {
					keys = append(keys, string(o.Key))
				}
			}
			c.Sheet = append(c.Sheet, SheetRow{Number: num, Section: q.Section, AnswerKey: q.answerKey(i), Type: q.Type, Options: keys})
		}
		if essay != nil {
			p := "Làm bài viết theo "
			if essay.Number != "" {
				p += "câu " + string(essay.Number)
			} else {
				p += "đề bài"
			}
			p += " trong file đề"
			if essay.Title != "" {
				p += ": " + essay.Title
			} else {
				p += "."
			}
			c.WritingPrompt = &p
		}
		return c
	}
	item := func(i int, q Question) PortalQuestion {
		pq := PortalQuestion{Number: i + 1, AnswerKey: q.answerKey(i), Type: q.Type, Title: q.Title, Passage: q.Passage,
			ImageURL: q.ImageURL, AudioSrc: audioSrc(q.AudioURL), Options: []PortalOption{}}
		for _, o := range q.Options {
			pq.Options = append(pq.Options, PortalOption{string(o.Key), string(o.Text), o.ImageURL})
		}
		return pq
	}
	prompt := defaultWritingPrompt
	var writingFound, speakingFound bool
	for i, q := range qs {
		switch q.Skill {
		case "listening":
			c.Listening = append(c.Listening, item(i, q))
		case "reading", "grammar":
			c.Reading = append(c.Reading, item(i, q))
		case "writing":
			if !writingFound {
				writingFound = true
				prompt = q.Title
			}
		case "speaking":
			if !speakingFound {
				speakingFound = true
				if q.CuePoints != "" {
					c.Speaking = &SpeakingPrompt{q.Title, q.CuePoints}
				}
			}
		}
	}
	c.WritingPrompt = &prompt
	return c
}

// ---- Màn chấm: đối chiếu từng câu với câu trả lời của thí sinh ----

// ReviewOption: phương án kèm đánh dấu đúng / thí sinh chọn.
type ReviewOption struct {
	Key       string `json:"key"`
	Text      string `json:"text"`
	IsCorrect bool   `json:"is_correct"`
	IsChosen  bool   `json:"is_chosen"`
}

// ReviewRow: một câu trên màn chấm.
type ReviewRow struct {
	Number          int            `json:"number"`
	PDFNumber       *string        `json:"pdf_number"`
	Section         string         `json:"section,omitempty"`
	Skill           string         `json:"skill"`
	Type            string         `json:"type"`
	Title           string         `json:"title"`
	Points          float64        `json:"points"`
	AudioURL        string         `json:"audio_url,omitempty"`
	ImageURL        string         `json:"image_url,omitempty"`
	Passage         string         `json:"passage,omitempty"`
	RubricNote      string         `json:"rubric_note,omitempty"`
	CuePoints       string         `json:"cue_points,omitempty"`
	Explanation     string         `json:"explanation,omitempty"`
	CorrectAnswer   string         `json:"correct_answer"`
	CandidateAnswer string         `json:"candidate_answer"`
	IsObjective     bool           `json:"is_objective"`
	IsCorrect       bool           `json:"is_correct"`
	IsIncorrect     bool           `json:"is_incorrect"`
	Options         []ReviewOption `json:"options"`
}

// reviewRows: so câu trả lời với đáp án như lúc tự chấm (không phân biệt hoa thường, nhiều cách viết "7 | seven").
func reviewRows(qs []Question, answers map[string]string, writing string) []ReviewRow {
	rows := make([]ReviewRow, 0, len(qs))
	for i, q := range qs {
		correct := strings.TrimSpace(string(q.CorrectAnswer))
		cand := strings.TrimSpace(answers[q.answerKey(i)])
		if cand == "" {
			cand = strings.TrimSpace(answers[strconv.Itoa(i)])
		}
		if q.Skill == "writing" && cand == "" {
			cand = writing
		}
		objective := q.Type == "multiple_choice" || q.Type == "fill_blank" || q.Type == "single_choice"
		ok := objective && cand != "" && correct != "" && AnswerMatches(cand, correct)
		title := q.Title
		if title == "" {
			title = "Câu hỏi trắc nghiệm"
		}
		r := ReviewRow{Number: i + 1, Section: q.Section, Skill: q.Skill, Type: q.Type, Title: title, Points: q.points(),
			AudioURL: q.AudioURL, ImageURL: q.ImageURL, Passage: q.Passage, RubricNote: q.RubricNote, CuePoints: q.CuePoints,
			Explanation: q.Explanation, CorrectAnswer: correct, CandidateAnswer: cand, IsObjective: objective,
			IsCorrect: ok, IsIncorrect: objective && cand != "" && correct != "" && !ok, Options: []ReviewOption{}}
		if q.Number != "" {
			n := string(q.Number)
			r.PDFNumber = &n
		}
		if q.Type == "multiple_choice" {
			for _, o := range q.Options {
				r.Options = append(r.Options, ReviewOption{string(o.Key), string(o.Text),
					strings.EqualFold(string(o.Key), correct), strings.EqualFold(string(o.Key), cand)})
			}
		}
		rows = append(rows, r)
	}
	return rows
}

// testCodeStamp: phần thời gian của mã đề gợi ý (TEST-G3-G4-260916-143005).
func testCodeStamp(t time.Time) string { return t.Format("060102-150405") }
