package placement

import (
	"context"
	"encoding/json"
	"fmt"
	"strconv"
	"strings"
	"time"
	"unicode"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/placement/placementdb"
)

// scoreFields: phần điểm dùng chung để tóm tắt kết quả.
type scoreFields struct {
	GradeGroup        *string
	TotalScore        *float64
	OverallScore      *float64
	SuggestedClass    *string
	ChosenClass       *string
	RecommendedCourse *string
}

// finalClass: lớp xếp cuối cùng — lớp Học vụ chọn lại, nếu không thì lớp đề xuất.
func (f scoreFields) finalClass() string {
	for _, c := range []*string{f.ChosenClass, f.SuggestedClass, f.RecommendedCourse} {
		if c != nil && *c != "" {
			return *c
		}
	}
	return ""
}

func (f scoreFields) overridden() bool {
	return f.SuggestedClass != nil && f.ChosenClass != nil && *f.ChosenClass != *f.SuggestedClass
}

// summary: tóm tắt điểm dạng "31/40 · STARTERS (...)" — dùng cho CRM (test_score), danh sách; nil nếu chưa có điểm.
func (f scoreFields) summary() *string {
	if f.GradeGroup != nil && f.TotalScore != nil {
		score := formatScore(f.TotalScore, "")
		if HasRubric(*f.GradeGroup) {
			score += "/" + formatScore(ptr(MaxFor(*f.GradeGroup).Total()), "")
		} else {
			score += " điểm"
		}
		if c := f.finalClass(); c != "" {
			score += " · " + c
		}
		return &score
	}
	if f.OverallScore == nil {
		return nil
	}
	// Bài chấm theo cách cũ (trước BA Q2) — hiển thị nguyên giá trị đã lưu.
	out := formatScore(f.OverallScore, "")
	if f.RecommendedCourse != nil && *f.RecommendedCourse != "" {
		out += " · " + *f.RecommendedCourse
	}
	return &out
}

// SubmissionRow: một dòng trên bảng "Bài làm & kết quả chấm".
type SubmissionRow struct {
	ID             uuid.UUID  `json:"id"`
	Number         int64      `json:"number"`
	TestID         uuid.UUID  `json:"test_id"`
	TestTitle      string     `json:"test_title"`
	TestCode       string     `json:"test_code"`
	LeadID         *uuid.UUID `json:"lead_id"`
	CandidateName  string     `json:"candidate_name"`
	CandidatePhone string     `json:"candidate_phone"`
	ViolationCount int32      `json:"violation_count"`
	AutoSubmitted  bool       `json:"auto_submitted"`
	CreatedAt      time.Time  `json:"created_at"`
	Listening      string     `json:"listening"`
	ReadingWriting string     `json:"reading_writing"`
	Speaking       string     `json:"speaking"`
	IsPending      bool       `json:"is_pending"`
	Total          string     `json:"total"`
	ScoreSummary   *string    `json:"score_summary"`
	FinalClass     string     `json:"final_class"`
}

// SubmissionList: bài làm gần đây (tối đa 50), có thể lọc theo đề.
type SubmissionList struct {
	Submissions  []SubmissionRow `json:"submissions"`
	SelectedTest *struct {
		ID    uuid.UUID `json:"id"`
		Title string    `json:"title"`
		Code  string    `json:"code"`
	} `json:"selected_test"`
}

// ListSubmissions: bài làm trong phạm vi được xem (bài chưa gắn khách, hoặc khách CRM người xem thấy).
func (s *Service) ListSubmissions(ctx context.Context, a *iam.Access, testID *uuid.UUID, limit int32) (*SubmissionList, error) {
	ids, all, err := s.leadScope(ctx, a)
	if err != nil {
		return nil, err
	}
	out := &SubmissionList{Submissions: []SubmissionRow{}}
	if testID != nil {
		t, err := s.q.GetTest(ctx, *testID)
		if err != nil {
			return nil, notFound(err, errTestNotFound)
		}
		out.SelectedTest = &struct {
			ID    uuid.UUID `json:"id"`
			Title string    `json:"title"`
			Code  string    `json:"code"`
		}{t.ID, t.Title, t.Code}
	}
	rows, err := s.q.ListSubmissions(ctx, placementdb.ListSubmissionsParams{TestID: testID, AllLeads: all, LeadIds: ids, Lim: limit})
	if err != nil {
		return nil, err
	}
	for _, r := range rows {
		f := scoreFields{r.GradeGroup, r.TotalScore, r.OverallScore, r.SuggestedClass, r.ChosenClass, r.RecommendedCourse}
		row := SubmissionRow{
			ID: r.ID, Number: r.Number, TestID: r.TestID, TestTitle: r.TestTitle, TestCode: r.TestCode, LeadID: r.LeadID,
			CandidateName: r.CandidateName, CandidatePhone: r.CandidatePhone, ViolationCount: r.ViolationCount,
			AutoSubmitted: r.AutoSubmitted, CreatedAt: r.CreatedAt, Listening: formatScore(r.ListeningScore, "—"),
			Speaking: formatScore(r.SpeakingScore, "—"), IsPending: r.Status != "graded", ScoreSummary: f.summary(),
			FinalClass: f.finalClass(),
		}
		rw := r.ReadingWritingScore
		if rw == nil {
			rw = r.ReadingScore
		}
		row.ReadingWriting = formatScore(rw, "—")
		switch {
		case r.TotalScore != nil:
			row.Total = formatScore(r.TotalScore, "")
			if r.GradeGroup != nil && HasRubric(*r.GradeGroup) {
				row.Total += " / " + formatScore(ptr(MaxFor(*r.GradeGroup).Total()), "")
			} else {
				row.Total += " điểm"
			}
		case row.ScoreSummary != nil:
			row.Total = *row.ScoreSummary
		default:
			row.Total = "—"
		}
		if row.FinalClass == "" {
			row.FinalClass = "—"
		}
		out.Submissions = append(out.Submissions, row)
	}
	return out, nil
}

// ViolationEntry: một lần rời bài (nhãn cho người chấm).
type ViolationEntry struct {
	At    string `json:"at"`
	Type  string `json:"type"`
	Label string `json:"label"`
}

// SubmissionDetail: bài làm đầy đủ cho màn chấm / bảng điểm.
type SubmissionDetail struct {
	ID                  uuid.UUID         `json:"id"`
	Number              int64             `json:"number"`
	TestID              uuid.UUID         `json:"test_id"`
	TestCode            string            `json:"test_code"`
	TestTitle           string            `json:"test_title"`
	TestPdfURL          string            `json:"test_pdf_url"`
	LeadID              *uuid.UUID        `json:"lead_id"`
	CandidateName       string            `json:"candidate_name"`
	CandidatePhone      string            `json:"candidate_phone"`
	CandidateEmail      string            `json:"candidate_email"`
	Status              string            `json:"status"`
	IsPending           bool              `json:"is_pending"`
	ScoreSummary        *string           `json:"score_summary"`
	SubmittedAt         time.Time         `json:"submitted_at"`
	GradedAt            *time.Time        `json:"graded_at"`
	GraderName          string            `json:"grader_name"`
	GradeGroup          string            `json:"grade_group"`
	GradeGroupLabel     string            `json:"grade_group_label"`
	HasRubric           bool              `json:"has_rubric"`
	HasRubricGrade      bool              `json:"has_rubric_grade"`
	MaxScores           MaxScores         `json:"max_scores"`
	ListeningScore      *float64          `json:"listening_score"`
	ReadingScore        *float64          `json:"reading_score"`
	WritingScore        *float64          `json:"writing_score"`
	SpeakingScore       *float64          `json:"speaking_score"`
	ReadingWritingScore *float64          `json:"reading_writing_score"`
	OverallScore        *float64          `json:"overall_score"`
	TotalScore          *float64          `json:"total_score"`
	SuggestedClass      string            `json:"suggested_class"`
	FinalClass          string            `json:"final_class"`
	Overridden          bool              `json:"overridden"`
	Comments            map[string]string `json:"comments"`
	TeacherComments     string            `json:"teacher_comments"`
	ViolationCount      int32             `json:"violation_count"`
	AutoSubmitted       bool              `json:"auto_submitted"`
	Violations          []ViolationEntry  `json:"violations"`
	WritingContent      string            `json:"writing_content"`
	WritingWordCount    int               `json:"writing_word_count"`
	SpeakingAudioURL    string            `json:"speaking_audio_url"`
	SpeakingSelfRate    string            `json:"speaking_self_rate"`
}

// RubricInitial: giá trị ban đầu của form chấm (Laravel rubricFormState). Nhận xét đang lưu trùng gợi ý theo băng điểm
// thì coi như chưa sửa tay: đổi điểm là nhận xét đổi theo. Nhận xét đã sửa tay thì giữ nguyên.
type RubricInitial struct {
	Group           string            `json:"group"`
	Listening       string            `json:"listening"`
	ReadingWriting  string            `json:"reading_writing"`
	Speaking        string            `json:"speaking"`
	Chosen          string            `json:"chosen"`
	Comments        map[string]string `json:"comments"`
	TeacherComments string            `json:"teacher_comments"`
	Edited          map[string]bool   `json:"edited"`
}

// GradingView: màn chấm bài.
type GradingView struct {
	Submission SubmissionDetail `json:"submission"`
	Questions  []ReviewRow      `json:"questions"`
	Rubric     RubricInitial    `json:"rubric"`
}

// wordCount gần giống str_word_count của PHP: đếm cụm có chữ cái.
func wordCount(s string) int {
	n := 0
	for _, w := range strings.Fields(s) {
		if strings.IndexFunc(w, unicode.IsLetter) >= 0 {
			n++
		}
	}
	return n
}

func (s *Service) loadSubmission(ctx context.Context, a *iam.Access, id uuid.UUID) (placementdb.GetSubmissionRow, error) {
	r, err := s.q.GetSubmission(ctx, id)
	if err != nil {
		return r, notFound(err, errSubNotFound)
	}
	// Bài gắn với khách CRM chỉ xem / chấm được khi khách thuộc phạm vi CRM của người dùng.
	if r.LeadID != nil {
		ok, err := s.leadVisible(ctx, a, *r.LeadID)
		if err != nil {
			return r, err
		}
		if !ok {
			return r, errSubNotFound
		}
	}
	return r, nil
}

func detailOf(r placementdb.GetSubmissionRow) SubmissionDetail {
	f := scoreFields{r.GradeGroup, r.TotalScore, r.OverallScore, r.SuggestedClass, r.ChosenClass, r.RecommendedCourse}
	group := deref(r.GradeGroup)
	resolved := group
	if resolved == "" {
		resolved = DetectGradeGroup(r.TestCode)
	}
	var answers map[string]string
	_ = json.Unmarshal(r.Answers, &answers)
	d := SubmissionDetail{
		ID: r.ID, Number: r.Number, TestID: r.TestID, TestCode: r.TestCode, TestTitle: r.TestTitle, TestPdfURL: pdfURL(r.TestPdfPath),
		LeadID: r.LeadID, CandidateName: r.CandidateName, CandidatePhone: r.CandidatePhone, CandidateEmail: deref(r.CandidateEmail),
		Status: r.Status, IsPending: r.Status != "graded", ScoreSummary: f.summary(), SubmittedAt: r.CreatedAt, GradedAt: r.GradedAt,
		GraderName: deref(r.GraderName), GradeGroup: group, GradeGroupLabel: GroupLabel(resolved), HasRubric: HasRubric(resolved),
		HasRubricGrade: r.GradeGroup != nil && r.TotalScore != nil, MaxScores: MaxFor(resolved),
		ListeningScore: r.ListeningScore, ReadingScore: r.ReadingScore, WritingScore: r.WritingScore, SpeakingScore: r.SpeakingScore,
		ReadingWritingScore: r.ReadingWritingScore, OverallScore: r.OverallScore, TotalScore: r.TotalScore,
		SuggestedClass: deref(r.SuggestedClass), FinalClass: f.finalClass(), Overridden: f.overridden(),
		Comments:        map[string]string{"listening": deref(r.ListeningComment), "reading_writing": deref(r.ReadingWritingComment), "speaking": deref(r.SpeakingComment)},
		TeacherComments: deref(r.TeacherComments), ViolationCount: r.ViolationCount, AutoSubmitted: r.AutoSubmitted,
		Violations: []ViolationEntry{}, WritingContent: deref(r.WritingContent), WritingWordCount: wordCount(deref(r.WritingContent)),
		SpeakingAudioURL: deref(r.SpeakingAudioUrl), SpeakingSelfRate: answers["speaking_self_rate"],
	}
	var vlog []Violation
	_ = json.Unmarshal(r.ViolationLog, &vlog)
	for _, v := range vlog {
		label := ViolationTypes[v.Type]
		if label == "" {
			label = v.Type
		}
		d.Violations = append(d.Violations, ViolationEntry{At: v.At, Type: v.Type, Label: label})
	}
	return d
}

// GetGrading: màn chấm bài — điểm tự chấm, đối chiếu từng câu, giá trị ban đầu của form chấm.
func (s *Service) GetGrading(ctx context.Context, a *iam.Access, id uuid.UUID) (*GradingView, error) {
	r, err := s.loadSubmission(ctx, a, id)
	if err != nil {
		return nil, err
	}
	var answers map[string]string
	_ = json.Unmarshal(r.Answers, &answers)
	d := detailOf(r)
	return &GradingView{Submission: d, Questions: reviewRows(parseQuestions(r.TestQuestions), answers, d.WritingContent), Rubric: rubricInitial(r)}, nil
}

// GetScorecard: bảng điểm (không có câu trả lời / đáp án).
func (s *Service) GetScorecard(ctx context.Context, a *iam.Access, id uuid.UUID) (*SubmissionDetail, error) {
	r, err := s.loadSubmission(ctx, a, id)
	if err != nil {
		return nil, err
	}
	d := detailOf(r)
	d.WritingContent, d.Violations = "", []ViolationEntry{}
	return &d, nil
}

func rubricInitial(r placementdb.GetSubmissionRow) RubricInitial {
	group := deref(r.GradeGroup)
	if group == "" {
		group = DetectGradeGroup(r.TestCode)
	}
	rw := r.ReadingWritingScore
	if rw == nil {
		rw = r.ReadingScore
	}
	in := RubricInitial{
		Group: group, Listening: formatScore(r.ListeningScore, ""), ReadingWriting: formatScore(rw, ""),
		Speaking: formatScore(r.SpeakingScore, ""), Chosen: deref(r.ChosenClass), TeacherComments: deref(r.TeacherComments),
		Comments: map[string]string{"listening": deref(r.ListeningComment), "reading_writing": deref(r.ReadingWritingComment), "speaking": deref(r.SpeakingComment)},
		Edited:   map[string]bool{},
	}
	saved := map[string]*float64{"listening": r.ListeningScore, "reading_writing": r.ReadingWritingScore, "speaking": r.SpeakingScore}
	for _, skill := range Skills {
		comment := in.Comments[skill]
		auto := ""
		if r.GradeGroup != nil && saved[skill] != nil {
			auto = SkillComment(*r.GradeGroup, skill, saved[skill])
		}
		in.Edited[skill] = comment != "" && comment != auto
	}
	return in
}

// GradeInput: chấm theo thang điểm khối lớp. Điểm nhận số hoặc chuỗi số (ô nhập của form).
type GradeInput struct {
	GradeGroup            string      `json:"grade_group"`
	ListeningScore        *flexString `json:"listening_score"`
	ReadingWritingScore   *flexString `json:"reading_writing_score"`
	SpeakingScore         *flexString `json:"speaking_score"`
	ChosenClass           string      `json:"chosen_class"`
	ListeningComment      string      `json:"listening_comment"`
	ReadingWritingComment string      `json:"reading_writing_comment"`
	SpeakingComment       string      `json:"speaking_comment"`
	TeacherComments       string      `json:"teacher_comments"`
	Action                string      `json:"action"` // draft | confirm
}

// parseScore: điểm của một kỹ năng; nil nếu bỏ trống (required kiểm ở ngoài).
func parseScore(v *flexString, label string, max float64, groupLabel string) (*float64, error) {
	if v == nil || strings.TrimSpace(string(*v)) == "" {
		return nil, nil
	}
	f, err := strconv.ParseFloat(strings.TrimSpace(string(*v)), 64)
	if err != nil {
		return nil, errInvalid(fmt.Sprintf("Điểm %s phải là số.", label))
	}
	if f < 0 {
		return nil, errInvalid(fmt.Sprintf("Điểm %s không được nhỏ hơn 0.", label))
	}
	if f > max {
		return nil, errInvalid(fmt.Sprintf("Điểm %s tối đa %s (%s).", label, formatScore(&max, ""), groupLabel))
	}
	return &f, nil
}

// GradeResult: kết quả chấm (đã chấm hoặc bản nháp).
type GradeResult struct {
	GradingView
	Message string `json:"message"`
}

// gradeParams kiểm tra điểm theo thang của khối và tính tổng, lớp đề xuất, nhận xét (Laravel applyRubricGrade).
// Khối không có thang điểm được để trống Nói (lưu null, không coi là 0) nhưng bắt buộc chọn lớp thủ công.
func gradeParams(in GradeInput) (placementdb.SaveGradeParams, error) {
	var p placementdb.SaveGradeParams
	g := strings.TrimSpace(in.GradeGroup)
	if g == "" || !IsValidGroup(g) {
		return p, errInvalid("Vui lòng chọn khối lớp để chấm theo thang điểm.")
	}
	max, label, rub := MaxFor(g), GroupLabel(g), HasRubric(g)
	l, err := parseScore(in.ListeningScore, "Nghe", max.Listening, label)
	if err != nil {
		return p, err
	}
	if l == nil {
		return p, errInvalid("Vui lòng nhập điểm Nghe.")
	}
	rw, err := parseScore(in.ReadingWritingScore, "Đọc & Viết", max.ReadingWriting, label)
	if err != nil {
		return p, err
	}
	if rw == nil {
		return p, errInvalid("Vui lòng nhập điểm Đọc & Viết.")
	}
	sp, err := parseScore(in.SpeakingScore, "Nói", max.Speaking, label)
	if err != nil {
		return p, err
	}
	if sp == nil && rub {
		return p, errInvalid("Điểm Nói (Speaking) luôn do người chấm nhập tay.")
	}
	chosen := strings.TrimSpace(in.ChosenClass)
	switch {
	case chosen == "" && !rub:
		return p, errInvalid(NoRubricNotice + ": vui lòng nhập lớp xếp cho học viên.")
	case runeLen(chosen) > 255:
		return p, errInvalid("Lớp xếp không được vượt quá 255 ký tự.")
	case runeLen(in.ListeningComment) > 3000, runeLen(in.ReadingWritingComment) > 3000, runeLen(in.SpeakingComment) > 3000,
		runeLen(in.TeacherComments) > 3000:
		return p, errInvalid("Nhận xét không được vượt quá 3000 ký tự.")
	}
	speaking := 0.0
	if sp != nil {
		speaking = *sp
	}
	ev := Evaluate(g, *l, *rw, speaking)
	typed := map[string]string{"listening": in.ListeningComment, "reading_writing": in.ReadingWritingComment, "speaking": in.SpeakingComment}
	comments := map[string]*string{}
	for _, skill := range Skills {
		c := strings.TrimSpace(typed[skill])
		if c == "" {
			c = ev.Comments[skill]
		}
		comments[skill] = strOrNil(c)
	}
	if chosen == "" {
		chosen = ev.SuggestedClass
	}
	return placementdb.SaveGradeParams{
		GradeGroup: &g, ListeningScore: l, ReadingWritingScore: rw, SpeakingScore: sp, TotalScore: &ev.Total,
		SuggestedClass: strOrNil(ev.SuggestedClass), ChosenClass: strOrNil(chosen), ListeningComment: comments["listening"],
		ReadingWritingComment: comments["reading_writing"], SpeakingComment: comments["speaking"],
		TeacherComments: strOrNil(in.TeacherComments),
	}, nil
}

// Grade: chấm bài (Học vụ / Quản lý cơ sở / Admin). "Lưu bản nháp" giữ bài Chờ chấm (chưa đồng bộ sang khách);
// "Xác nhận kết quả" chốt điểm và đồng bộ sang hồ sơ khách (khách chuyển "Đã test", chỉ đi tiến). Bài đã chấm không lùi về nháp.
func (s *Service) Grade(ctx context.Context, a *iam.Access, id uuid.UUID, in GradeInput) (*GradeResult, error) {
	if in.Action != "" && in.Action != "draft" && in.Action != "confirm" {
		return nil, errInvalid("Thao tác không hợp lệ.")
	}
	if _, err := s.loadSubmission(ctx, a, id); err != nil {
		return nil, err
	}
	p, err := gradeParams(in)
	if err != nil {
		return nil, err
	}
	p.ID, p.GraderID = id, &a.UserID
	var saved placementdb.PlacementSubmission
	err = s.tx(ctx, func(q *placementdb.Queries) error {
		cur, err := q.LockSubmission(ctx, id)
		if err != nil {
			return notFound(err, errSubNotFound)
		}
		p.Status = "graded"
		if in.Action == "draft" && cur.Status != "graded" {
			p.Status = "pending"
		}
		saved, err = q.SaveGrade(ctx, p)
		return err
	})
	if err != nil {
		return nil, err
	}
	s.log.Info("placement: chấm bài", "submission", id, "status", saved.Status, "by", a.UserID)
	f := scoreFields{saved.GradeGroup, saved.TotalScore, saved.OverallScore, saved.SuggestedClass, saved.ChosenClass, saved.RecommendedCourse}
	msg := "Đã lưu bản nháp điểm — bài vẫn ở trạng thái Chờ chấm cho tới khi Xác nhận kết quả."
	if saved.Status == "graded" {
		summary := deref(f.summary())
		msg = "Đã chấm và lưu kết quả bài test: " + summary + "."
		if saved.LeadID != nil {
			content := fmt.Sprintf("Học vụ đã chấm bài test #%d (%s): %s", saved.Number, GroupLabel(deref(saved.GradeGroup)), summary)
			if f.overridden() {
				content += fmt.Sprintf(" (lớp đề xuất theo thang điểm: %s)", deref(saved.SuggestedClass))
			}
			s.record(ctx, LeadEvent{LeadID: *saved.LeadID, ActorID: &a.UserID, Type: "test", AdvanceTo: "tested", Content: content, TestScore: &summary})
		}
	}
	view, err := s.GetGrading(ctx, a, id)
	if err != nil {
		return nil, err
	}
	return &GradeResult{GradingView: *view, Message: msg}, nil
}
