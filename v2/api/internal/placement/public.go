package placement

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"strings"
	"time"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/placement/placementdb"
)

// Trang làm bài công khai (không đăng nhập), chỉ vào được bằng token của link mời.

var (
	errLinkNotFound = &iam.Error{Status: http.StatusNotFound, Code: "link_not_found", Message: "Link làm bài không tồn tại. Vui lòng kiểm tra lại đường dẫn trung tâm đã gửi."}
	errLinkExpired  = &iam.Error{Status: http.StatusGone, Code: "link_expired", Message: "Link làm bài đã hết hạn hoặc đã bị thu hồi. Vui lòng liên hệ trung tâm để nhận link mới."}
	errLinkUsed     = &iam.Error{Status: http.StatusGone, Code: "link_used", Message: "Link này đã được dùng để nộp bài. Vui lòng liên hệ trung tâm nếu cần làm lại."}
	errTestPaused   = &iam.Error{Status: http.StatusGone, Code: "test_inactive", Message: "Đề kiểm tra này đang tạm ngừng. Vui lòng liên hệ trung tâm."}
)

// checkInvitation: link còn dùng được tới thời điểm until (lúc mở: hạn link; lúc nộp: hạn + thời gian làm bài + 60 phút).
func checkInvitation(inv placementdb.PlacementInvitation, now time.Time, grace time.Duration) error {
	switch {
	case inv.RevokedAt != nil:
		return errLinkExpired
	case now.After(inv.ExpiresAt.Add(grace)):
		return errLinkExpired
	case inv.MaxSubmissions != nil && inv.SubmissionCount >= *inv.MaxSubmissions:
		return errLinkUsed
	}
	return nil
}

// PublicTest: thông tin đề trên trang làm bài (không có đáp án).
type PublicTest struct {
	Code            string `json:"code"`
	Title           string `json:"title"`
	TargetLevel     string `json:"target_level"`
	DurationMinutes int32  `json:"duration_minutes"`
	QuestionsCount  int32  `json:"questions_count"`
	PdfURL          string `json:"pdf_url"`
	AudioSrc        string `json:"audio_src"`
}

// Prefill: thông tin thí sinh điền sẵn (link riêng của khách).
type Prefill struct {
	Name  string `json:"name"`
	Phone string `json:"phone"`
	Email string `json:"email"`
}

// PublicPage: dữ liệu trang làm bài.
type PublicPage struct {
	Test PublicTest `json:"test"`
	PortalContent
	Lead          *Prefill  `json:"lead"`
	MaxViolations int       `json:"max_violations"`
	ExpiresAt     time.Time `json:"expires_at"`
}

// OpenInvitation: thí sinh mở link. Link riêng của khách → khách tự chuyển sang "Test" (chỉ đi tiến).
func (s *Service) OpenInvitation(ctx context.Context, token string) (*PublicPage, error) {
	if token == "" || len(token) > 100 {
		return nil, errLinkNotFound
	}
	inv, err := s.q.InvitationByToken(ctx, hashToken(token))
	if err != nil {
		return nil, notFound(err, errLinkNotFound)
	}
	if err := checkInvitation(inv, s.now(), 0); err != nil {
		return nil, err
	}
	t, err := s.q.GetTest(ctx, inv.TestID)
	if err != nil {
		return nil, notFound(err, errTestPaused)
	}
	if !t.IsActive {
		return nil, errTestPaused
	}
	if err := s.q.MarkInvitationOpened(ctx, inv.ID); err != nil {
		return nil, err
	}
	page := &PublicPage{
		Test: PublicTest{Code: t.Code, Title: t.Title, TargetLevel: t.TargetLevel, DurationMinutes: t.DurationMinutes,
			QuestionsCount: t.QuestionsCount, PdfURL: pdfURL(t.PdfPath), AudioSrc: audioSrc(deref(t.AudioUrl))},
		PortalContent: portalContent(parseQuestions(t.Questions), t.PdfPath != nil),
		MaxViolations: MaxViolations, ExpiresAt: inv.ExpiresAt,
	}
	if inv.LeadID != nil {
		page.Lead = &Prefill{deref(inv.CandidateName), deref(inv.CandidatePhone), deref(inv.CandidateEmail)}
		s.record(ctx, LeadEvent{LeadID: *inv.LeadID, Type: "test", AdvanceTo: "testing",
			Content: fmt.Sprintf("Thí sinh mở link làm bài test [%s].", t.Code)})
	}
	return page, nil
}

// pdfURL: đề PDF nhập từ Laravel (file ở kho công khai /storage/...); v2 chưa có kho file nên giữ đường dẫn cũ.
func pdfURL(p *string) string {
	if p == nil || *p == "" {
		return ""
	}
	return "/storage/" + strings.TrimPrefix(*p, "/")
}

// SubmitInput: bài nộp từ trang làm bài.
type SubmitInput struct {
	CandidateName    string            `json:"candidate_name"`
	CandidatePhone   string            `json:"candidate_phone"`
	CandidateEmail   string            `json:"candidate_email"`
	Answers          map[string]string `json:"answers"`
	WritingContent   string            `json:"writing_content"`
	SpeakingSelfRate string            `json:"speaking_self_rate"`
	ViolationCount   int               `json:"violation_count"`
	ViolationLog     []Violation       `json:"violation_log"`
	AutoSubmitted    bool              `json:"auto_submitted"`
}

// SubmitResult: trang cảm ơn (thí sinh không xem điểm: Học vụ duyệt rồi mới gửi kết quả).
type SubmitResult struct {
	CandidateName string `json:"candidate_name"`
	TestTitle     string `json:"test_title"`
}

func validateSubmit(in *SubmitInput) error {
	in.CandidateName = strings.TrimSpace(in.CandidateName)
	in.CandidatePhone = strings.TrimSpace(in.CandidatePhone)
	in.CandidateEmail = strings.TrimSpace(in.CandidateEmail)
	switch {
	case in.CandidateName == "":
		return errInvalid("Vui lòng nhập họ và tên thí sinh.")
	case runeLen(in.CandidateName) > 255:
		return errInvalid("Họ và tên thí sinh không được vượt quá 255 ký tự.")
	case in.CandidatePhone == "":
		return errInvalid("Vui lòng nhập số điện thoại liên hệ.")
	case runeLen(in.CandidatePhone) > 20:
		return errInvalid("Số điện thoại không được vượt quá 20 ký tự.")
	case in.CandidateEmail != "" && (!emailRe.MatchString(in.CandidateEmail) || runeLen(in.CandidateEmail) > 255):
		return errInvalid("Email nhận bảng điểm không hợp lệ.")
	case len(in.Answers) > 500:
		return errInvalid("Bài làm có quá nhiều câu trả lời.")
	case runeLen(in.WritingContent) > 5000:
		return errInvalid("Bài viết không được vượt quá 5000 ký tự.")
	case in.SpeakingSelfRate != "" && in.SpeakingSelfRate != "beginner" && in.SpeakingSelfRate != "intermediate" && in.SpeakingSelfRate != "advanced":
		return errInvalid("Mức tự đánh giá kỹ năng nói không hợp lệ.")
	case in.ViolationCount < 0 || in.ViolationCount > 1000:
		return errInvalid("Số lần rời bài không hợp lệ.")
	case len(in.ViolationLog) > 1000:
		return errInvalid("Nhật ký rời bài không hợp lệ.")
	}
	for _, a := range in.Answers {
		if runeLen(a) > 1000 {
			return errInvalid("Mỗi câu trả lời không được vượt quá 1000 ký tự.")
		}
	}
	return nil
}

// Submit: nộp bài qua link. Tự chấm theo đáp án lưu trong đề: Nghe trên thang Nghe; Đọc & Viết (câu Đọc / Ngữ pháp /
// Viết có đáp án) trên thang Đọc & Viết của khối (theo mã đề). Bài viết tự luận và phần Nói do Học vụ chấm.
// Điểm + nhận xét tự động chỉ là bản nháp: bài vẫn "Chờ chấm".
func (s *Service) Submit(ctx context.Context, token string, in SubmitInput) (*SubmitResult, error) {
	if token == "" || len(token) > 100 {
		return nil, errLinkNotFound
	}
	if err := validateSubmit(&in); err != nil {
		return nil, err
	}
	var (
		sub      placementdb.PlacementSubmission
		test     placementdb.PlacementTest
		viaLink  bool
		hasLeadL bool
	)
	err := s.tx(ctx, func(q *placementdb.Queries) error {
		inv, err := q.LockInvitationByToken(ctx, hashToken(token))
		if err != nil {
			return notFound(err, errLinkNotFound)
		}
		if test, err = q.GetTest(ctx, inv.TestID); err != nil {
			return notFound(err, errTestPaused)
		}
		if !test.IsActive {
			return errTestPaused
		}
		if err := checkInvitation(inv, s.now(), time.Duration(test.DurationMinutes)*time.Minute+submitGrace); err != nil {
			return err
		}
		qs := parseQuestions(test.Questions)
		answers := keepAnswers(qs, in.Answers)
		group := DetectGradeGroup(test.Code)
		scale := onlineManualScale
		if HasRubric(group) {
			scale = MaxFor(group)
		}
		listening := autoGradeSkill(qs, answers, []string{"listening"}, scale.Listening)
		rw := autoGradeSkill(qs, answers, []string{"reading", "grammar", "writing"}, scale.ReadingWriting)

		// Không tin khách do trình duyệt gửi: khách chỉ gắn khi link là link riêng của khách, hoặc SĐT khớp khách chưa chốt.
		leadID := inv.LeadID
		viaLink = leadID != nil
		if leadID == nil {
			if phone := NormalizePhone(in.CandidatePhone); len(phone) >= 9 {
				lead, err := s.leads.MatchByPhone(ctx, phone)
				if err != nil {
					return err
				}
				if lead != nil {
					leadID = &lead.ID
				}
			}
		}
		hasLeadL = leadID != nil
		stored := map[string]string{}
		for k, v := range answers {
			stored[k] = v
		}
		if in.SpeakingSelfRate != "" {
			stored["speaking_self_rate"] = in.SpeakingSelfRate
		}
		ab, _ := json.Marshal(stored)
		var vlog []byte
		if v := sanitizeViolations(in.ViolationLog); len(v) > 0 {
			vlog, _ = json.Marshal(v)
		}
		sub, err = q.CreateSubmission(ctx, placementdb.CreateSubmissionParams{
			TestID: test.ID, InvitationID: &inv.ID, LeadID: leadID, CandidateName: in.CandidateName,
			CandidatePhone: in.CandidatePhone, CandidateEmail: strOrNil(in.CandidateEmail), GradeGroup: &group,
			ListeningScore: listening, ReadingScore: rw, ReadingWritingScore: rw,
			ListeningComment:      strOrNil(SkillComment(group, "listening", listening)),
			ReadingWritingComment: strOrNil(SkillComment(group, "reading_writing", rw)),
			WritingContent:        strOrNil(in.WritingContent), Answers: ab,
			ViolationCount: int32(in.ViolationCount), ViolationLog: vlog, AutoSubmitted: in.AutoSubmitted,
		})
		if err != nil {
			return err
		}
		return q.CountInvitationUse(ctx, inv.ID)
	})
	if err != nil {
		return nil, err
	}
	s.log.Info("placement: thí sinh nộp bài", "submission", sub.ID, "test", test.ID, "violations", sub.ViolationCount, "auto", sub.AutoSubmitted)
	if hasLeadL {
		// Nộp bài chưa phải "Đã test": chỉ khi Học vụ chấm xong mới chuyển.
		content := fmt.Sprintf("Học viên đã nộp bài test trực tuyến [%s] (bài #%d), chờ Học vụ chấm điểm.", test.Title, sub.Number)
		if viaLink {
			content += " Nộp qua link test riêng của lead."
		} else {
			content += " Khớp lead theo số điện thoại."
		}
		s.record(ctx, LeadEvent{LeadID: *sub.LeadID, Type: "test", AdvanceTo: "testing", Content: content})
	}
	// TODO(notify): NotificationService::notifyHomeworkOrTestSubmission — báo Học vụ có bài test mới chờ chấm.
	return &SubmitResult{CandidateName: sub.CandidateName, TestTitle: test.Title}, nil
}
