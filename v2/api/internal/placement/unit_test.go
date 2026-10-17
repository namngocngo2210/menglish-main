package placement

import (
	"encoding/json"
	"strings"
	"testing"
	"time"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/placement/placementdb"
)

func f64(v float64) *float64 { return &v }

func TestDetectGradeGroupAndLevel(t *testing.T) {
	groups := map[string]string{
		"TEST-G1-G2-01": "khoi_1_2", "TEST-G2-G3": "khoi_2_3", "test-g3-g4-a": "khoi_3_4", "G4-G6-SPEAKING": "khoi_4_5",
		"PRE-G1-G2": ManualGroup, "IELTS-01": ManualGroup,
	}
	for code, want := range groups {
		if got := DetectGradeGroup(code); got != want {
			t.Errorf("DetectGradeGroup(%q) = %q, muốn %q", code, got, want)
		}
	}
	levels := map[string]string{"TEST-G3-G4": "lop_3", "PRE-G1": "mau_giao", "ABC": ""}
	for code, want := range levels {
		if got := DetectGradeLevel(code); got != want {
			t.Errorf("DetectGradeLevel(%q) = %q, muốn %q", code, got, want)
		}
	}
	if RubricGroupForLevel("lop_3") != "khoi_3_4" || RubricGroupForLevel("lop_7") != ManualGroup {
		t.Error("RubricGroupForLevel sai")
	}
}

func TestEvaluateRubric(t *testing.T) {
	// Khối 3 lên 4: < 20 → FAM 2 (NỬA ĐẦU), ≤ 35 → FAM 2 (NỬA SAU), còn lại Luyện MOVERS.
	cases := []struct {
		l, rw, sp float64
		class     string
	}{
		{5, 10, 4.5, "FAM 2 (NỬA ĐẦU)"},
		{10, 10, 0, "FAM 2 (NỬA SAU)"},
		{15, 15, 5, "FAM 2 (NỬA SAU)"},
		{15, 15, 5.5, "Luyện MOVERS"},
	}
	for _, c := range cases {
		ev := Evaluate("khoi_3_4", c.l, c.rw, c.sp)
		if ev.SuggestedClass != c.class {
			t.Errorf("%v+%v+%v → %q, muốn %q", c.l, c.rw, c.sp, ev.SuggestedClass, c.class)
		}
	}
	ev := Evaluate("khoi_3_4", 7.25, 8.04, 1)
	if ev.Total != 16.3 {
		t.Errorf("tổng làm tròn 1 chữ số: %v", ev.Total)
	}
	if ev.Comments["listening"] == "" || ev.Comments["reading_writing"] == "" {
		t.Error("thiếu nhận xét gợi ý theo băng điểm")
	}
	if got := Evaluate(ManualGroup, 50, 50, 50); got.SuggestedClass != "" || got.Comments["listening"] != "" {
		t.Errorf("khối thủ công không có lớp đề xuất / nhận xét: %+v", got)
	}
	if MaxFor("khoi_3_4").Total() != 45 || MaxFor(ManualGroup).Listening != 100 {
		t.Error("MaxFor sai")
	}
	if SkillComment("khoi_3_4", "listening", nil) != "" {
		t.Error("chưa có điểm thì không có nhận xét")
	}
	cfg := Config()
	if len(cfg.Groups) != 5 || cfg.Groups[4].Key != ManualGroup || cfg.Groups[4].HasRubric {
		t.Errorf("Config groups: %+v", cfg.Groups)
	}
}

func TestAnswerMatches(t *testing.T) {
	ok := [][2]string{{"Seven", "7 | seven"}, {"  a  big   dog. ", "A big dog"}, {"B", "b"}, {"7", "7|seven"}}
	for _, c := range ok {
		if !AnswerMatches(c[0], c[1]) {
			t.Errorf("%q phải khớp %q", c[0], c[1])
		}
	}
	bad := [][2]string{{"", ""}, {"", " | "}, {"six", "7 | seven"}, {"a", "b"}}
	for _, c := range bad {
		if AnswerMatches(c[0], c[1]) {
			t.Errorf("%q không được khớp %q", c[0], c[1])
		}
	}
}

func questions(t *testing.T, raw string) []Question {
	t.Helper()
	qs, err := normalizeQuestions(json.RawMessage(raw))
	if err != nil {
		t.Fatal(err)
	}
	return qs
}

func TestNormalizeQuestions(t *testing.T) {
	qs := questions(t, `[{"skill":"listening","title":"Q1","correct_answer":"A","points":0},{"id":"x","skill":"reading","type":"fill_blank","correct_answer":7},{"id":"x","title":"trùng id"}]`)
	if qs[0].ID != "1" || qs[1].ID != "x" || qs[2].ID != "3" {
		t.Errorf("id: %q %q %q", qs[0].ID, qs[1].ID, qs[2].ID)
	}
	if qs[0].points() != 1 || qs[2].Skill != "general" || qs[2].Type != "multiple_choice" || qs[1].CorrectAnswer != "7" {
		t.Errorf("chuẩn hóa sai: %+v", qs)
	}
	// Chuỗi JSON (form cũ) cũng nhận.
	if qs := questions(t, `"[{\"title\":\"a\"}]"`); len(qs) != 1 {
		t.Error("không đọc được câu hỏi dạng chuỗi")
	}
	for raw, msg := range map[string]string{
		`[{"skill":"math"}]`:      "Câu 1: kỹ năng không hợp lệ.",
		`[{},{"type":"drawing"}]`: "Câu 2: loại câu hỏi không hợp lệ.",
		`{"a":1}`:                 "Danh sách câu hỏi không hợp lệ.",
		`[{"title":"` + strings.Repeat("a", 5001) + `"}]`: "Câu 1: nội dung quá dài.",
	} {
		_, err := normalizeQuestions(json.RawMessage(raw))
		var e *iam.Error
		if err == nil || !asIAM(err, &e) || e.Message != msg {
			t.Errorf("%s: lỗi %v, muốn %q", raw[:min(len(raw), 30)], err, msg)
		}
	}
}

func asIAM(err error, e **iam.Error) bool {
	x, ok := err.(*iam.Error)
	*e = x
	return ok
}

func TestAutoGradeSkill(t *testing.T) {
	qs := questions(t, `[
		{"id":"l1","skill":"listening","correct_answer":"A"},
		{"id":"l2","skill":"listening","correct_answer":"B","points":3},
		{"id":"r1","skill":"reading","correct_answer":"cat|kitten"},
		{"id":"g1","skill":"grammar","correct_answer":"is"},
		{"id":"w1","skill":"writing","type":"essay"}
	]`)
	ans := keepAnswers(qs, map[string]string{"l1": "a", "l2": "c", "r1": " Kitten ", "g1": "", "khac": "x"})
	if _, ok := ans["khac"]; ok {
		t.Error("giữ câu trả lời của câu không có trong đề")
	}
	if _, ok := ans["g1"]; ok {
		t.Error("giữ câu trả lời rỗng")
	}
	// Nghe: đúng 1/4 điểm × 15 = 3.75 → làm tròn 0,5 = 4.
	if got := autoGradeSkill(qs, ans, []string{"listening"}, 15); got == nil || *got != 4 {
		t.Errorf("Nghe = %v", got)
	}
	// Đọc & Viết: đúng 1/2 (bài viết tự luận không tính) × 20 = 10.
	if got := autoGradeSkill(qs, ans, []string{"reading", "grammar", "writing"}, 20); got == nil || *got != 10 {
		t.Errorf("Đọc & Viết = %v", got)
	}
	if got := autoGradeSkill(qs, ans, []string{"speaking"}, 10); got != nil {
		t.Errorf("không có câu Nói có đáp án → nil, nhận %v", *got)
	}
}

func TestPortalContentHidesAnswers(t *testing.T) {
	qs := questions(t, `[
		{"id":"l1","skill":"listening","title":"Nghe","correct_answer":"BÍ_MẬT_1","explanation":"BÍ_MẬT_2","teacher_note":"BÍ_MẬT_3","options":[{"key":"A","text":"x"}]},
		{"id":"r1","skill":"reading","title":"Đọc","correct_answer":"BÍ_MẬT_4","rubric_note":"BÍ_MẬT_5"},
		{"id":"w1","skill":"writing","type":"essay","title":"Viết về gia đình"},
		{"id":"s1","skill":"speaking","type":"speaking_prompt","title":"Nói","cue_points":"gợi ý"}
	]`)
	c := portalContent(qs, false)
	b, _ := json.Marshal(c)
	if strings.Contains(string(b), "BÍ_MẬT") {
		t.Fatalf("trang làm bài lộ đáp án: %s", b)
	}
	if len(c.Listening) != 1 || len(c.Reading) != 1 || c.WritingPrompt == nil || c.Speaking == nil {
		t.Errorf("thiếu phần: %s", b)
	}
	pdf := portalContent(qs, true)
	b, _ = json.Marshal(pdf)
	if strings.Contains(string(b), "BÍ_MẬT") || len(pdf.Sheet) != 2 {
		t.Errorf("phiếu trả lời đề PDF: %s", b)
	}
}

func TestSanitizeViolations(t *testing.T) {
	in := []Violation{{"hidden", "2026-10-16T10:00:00Z"}, {"hack", "x"}, {"blur", strings.Repeat("9", 60)}}
	for range 60 {
		in = append(in, Violation{"fullscreen_exit", "t"})
	}
	out := sanitizeViolations(in)
	if len(out) != 49 || out[0].Type != "hidden" || out[1].Type != "blur" || len(out[1].At) != 40 {
		t.Errorf("sanitize: %d dòng, %+v", len(out), out[:2])
	}
}

func TestCheckInvitationStatus(t *testing.T) {
	now := time.Date(2026, 10, 16, 10, 0, 0, 0, time.UTC)
	two := int32(2)
	base := placementdb.PlacementInvitation{ExpiresAt: now.Add(time.Hour), MaxSubmissions: &two}
	if err := checkInvitation(base, now, 0); err != nil {
		t.Errorf("link còn hạn: %v", err)
	}
	expired := base
	expired.ExpiresAt = now.Add(-time.Minute)
	if err := checkInvitation(expired, now, 0); err != errLinkExpired {
		t.Errorf("link hết hạn: %v", err)
	}
	if err := checkInvitation(expired, now, time.Hour); err != nil {
		t.Errorf("còn trong thời gian nộp bài bù: %v", err)
	}
	used := base
	used.SubmissionCount = 2
	if err := checkInvitation(used, now, 0); err != errLinkUsed {
		t.Errorf("link đã dùng hết lượt: %v", err)
	}
	revoked := base
	revoked.RevokedAt = &now
	if err := checkInvitation(revoked, now, time.Hour); err != errLinkExpired {
		t.Errorf("link đã thu hồi: %v", err)
	}
	if invitationStatus(used, now) != "used_up" || invitationStatus(revoked, now) != "revoked" || invitationStatus(expired, now) != "expired" {
		t.Error("invitationStatus sai")
	}
}

func TestTrialState(t *testing.T) {
	now := time.Date(2026, 10, 16, 3, 0, 0, 0, time.UTC) // 10:00 giờ Việt Nam
	day := func(d int) time.Time { return time.Date(2026, 10, d, 0, 0, 0, 0, time.UTC) }
	end := parseHHMM("17:30")
	st := trialState([]placementdb.CrmTrialBooking{
		{Status: "attended", SessionDate: day(10), EndTime: end},
		{Status: "no_show", SessionDate: day(12), EndTime: end},
		{Status: "scheduled", SessionDate: day(16), EndTime: end},
	}, now)
	if st.Used != 2 || !st.Exhausted || st.PendingID == nil || st.Remaining != 0 {
		t.Errorf("trialState: %+v", st)
	}
	if hhmm(end) != "17:30" || hhmm(parseHHMM("sai")) != "" {
		t.Error("hhmm / parseHHMM sai")
	}
}

func TestFormatScore(t *testing.T) {
	if formatScore(f64(7.5), "-") != "7.5" || formatScore(f64(8), "-") != "8" || formatScore(nil, "-") != "-" {
		t.Error("formatScore sai")
	}
}
