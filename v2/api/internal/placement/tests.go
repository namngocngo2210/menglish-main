package placement

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"strings"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5/pgconn"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/placement/placementdb"
)

// TestSummary: một dòng trên danh sách đề.
type TestSummary struct {
	ID               uuid.UUID `json:"id"`
	Code             string    `json:"code"`
	Title            string    `json:"title"`
	IsPreset         bool      `json:"is_preset"`
	IsActive         bool      `json:"is_active"`
	Type             string    `json:"type"` // placement_test | speaking_test
	GradeGroup       string    `json:"grade_group"`
	GradeGroupLabel  string    `json:"grade_group_label"`
	TargetLevel      string    `json:"target_level"`
	DurationMinutes  int32     `json:"duration_minutes"`
	QuestionsCount   int32     `json:"questions_count"`
	SubmissionsCount int32     `json:"submissions_count"`
	CreatedAt        time.Time `json:"created_at"`
}

// TestStats: thẻ số liệu trên màn danh sách đề.
type TestStats struct {
	TotalTests         int   `json:"total_tests"`
	ActiveTests        int   `json:"active_tests"`
	HiddenTests        int   `json:"hidden_tests"`
	TotalSubmissions   int32 `json:"total_submissions"`
	PendingSubmissions int32 `json:"pending_submissions"`
}

// TestFilter: lọc danh sách đề (phía server như Laravel): tìm tên / mã, cấp độ (khối thang điểm), trạng thái.
type TestFilter struct {
	Search     string
	GradeGroup string
	Status     string // active | hidden
	Page       int
	PerPage    int
}

// TestList: kết quả danh sách đề.
type TestList struct {
	Tests   []TestSummary `json:"tests"`
	Total   int           `json:"total"`
	Page    int           `json:"page"`
	PerPage int           `json:"per_page"`
	Stats   TestStats     `json:"stats"`
}

func testType(code string) string {
	if strings.Contains(strings.ToUpper(code), "SPEAKING") {
		return "speaking_test"
	}
	return "placement_test"
}

// ListTests: danh sách đề kèm số lượt làm trong phạm vi khách người xem thấy.
func (s *Service) ListTests(ctx context.Context, a *iam.Access, f TestFilter) (*TestList, error) {
	ids, all, err := s.leadScope(ctx, a)
	if err != nil {
		return nil, err
	}
	rows, err := s.q.ListTests(ctx, placementdb.ListTestsParams{AllLeads: all, LeadIds: ids})
	if err != nil {
		return nil, err
	}
	stats, err := s.q.SubmissionStats(ctx, placementdb.SubmissionStatsParams{AllLeads: all, LeadIds: ids})
	if err != nil {
		return nil, err
	}
	out := &TestList{Tests: []TestSummary{}, Stats: TestStats{TotalTests: len(rows), TotalSubmissions: stats.Total, PendingSubmissions: stats.Pending}}
	search := strings.ToLower(strings.TrimSpace(f.Search))
	for _, r := range rows {
		if r.IsActive {
			out.Stats.ActiveTests++
		} else {
			out.Stats.HiddenTests++
		}
		g := DetectGradeGroup(r.Code)
		switch {
		case search != "" && !strings.Contains(strings.ToLower(r.Title), search) && !strings.Contains(strings.ToLower(r.Code), search):
			continue
		case IsValidGroup(f.GradeGroup) && g != f.GradeGroup:
			continue
		case f.Status == "active" && !r.IsActive, f.Status == "hidden" && r.IsActive:
			continue
		}
		label := GroupLabel(g)
		out.Tests = append(out.Tests, TestSummary{
			ID: r.ID, Code: r.Code, Title: r.Title, IsPreset: r.IsPreset, IsActive: r.IsActive, Type: testType(r.Code),
			GradeGroup: g, GradeGroupLabel: label, TargetLevel: r.TargetLevel, DurationMinutes: r.DurationMinutes,
			QuestionsCount: r.QuestionsCount, SubmissionsCount: r.SubmissionsCount, CreatedAt: r.CreatedAt,
		})
	}
	out.Total = len(out.Tests)
	out.PerPage = f.PerPage
	if out.PerPage <= 0 {
		out.PerPage = 20
	}
	out.Page = max(1, f.Page)
	from := (out.Page - 1) * out.PerPage
	if from > len(out.Tests) {
		from = len(out.Tests)
	}
	out.Tests = out.Tests[from:min(len(out.Tests), from+out.PerPage)]
	return out, nil
}

// TestDetail: đề đầy đủ (kể cả đáp án) cho người soạn / chấm.
type TestDetail struct {
	ID              uuid.UUID  `json:"id"`
	Code            string     `json:"code"`
	Title           string     `json:"title"`
	Description     string     `json:"description"`
	TargetLevel     string     `json:"target_level"`
	GradeLevel      string     `json:"grade_level"`
	GradeGroup      string     `json:"grade_group"`
	GradeGroupLabel string     `json:"grade_group_label"`
	DurationMinutes int32      `json:"duration_minutes"`
	QuestionsCount  int32      `json:"questions_count"`
	Questions       []Question `json:"questions"`
	PdfPath         string     `json:"pdf_path"`
	PdfURL          string     `json:"pdf_url"` // link mở file đề cho người soạn / chấm
	AudioURL        string     `json:"audio_url"`
	IsActive        bool       `json:"is_active"`
	IsPreset        bool       `json:"is_preset"`
	CreatedAt       time.Time  `json:"created_at"`
	UpdatedAt       time.Time  `json:"updated_at"`
}

func toDetail(t placementdb.PlacementTest) TestDetail {
	g := DetectGradeGroup(t.Code)
	return TestDetail{
		ID: t.ID, Code: t.Code, Title: t.Title, Description: deref(t.Description), TargetLevel: t.TargetLevel,
		GradeLevel: deref(t.GradeLevel), GradeGroup: g, GradeGroupLabel: GroupLabel(g), DurationMinutes: t.DurationMinutes,
		QuestionsCount: t.QuestionsCount, Questions: parseQuestions(t.Questions), PdfPath: deref(t.PdfPath),
		PdfURL: staffPdfURL(t.PdfPath), AudioURL: deref(t.AudioUrl), IsActive: t.IsActive, IsPreset: t.IsPreset, CreatedAt: t.CreatedAt, UpdatedAt: t.UpdatedAt,
	}
}

// GetTest: chi tiết đề.
func (s *Service) GetTest(ctx context.Context, id uuid.UUID) (*TestDetail, error) {
	t, err := s.q.GetTest(ctx, id)
	if err != nil {
		return nil, notFound(err, errTestNotFound)
	}
	d := toDetail(t)
	return &d, nil
}

// TestInput: tạo / sửa đề. Trường con trỏ: nil = giữ nguyên khi sửa (như form Laravel không gửi trường đó).
type TestInput struct {
	Code            string          `json:"code"`
	Title           string          `json:"title"`
	Description     *string         `json:"description"`
	TargetLevel     string          `json:"target_level"`
	GradeLevel      *string         `json:"grade_level"`
	GradeGroup      string          `json:"grade_group"`
	DurationMinutes *int            `json:"duration_minutes"`
	QuestionsCount  *int            `json:"questions_count"`
	Questions       json.RawMessage `json:"questions"`
	Mode            string          `json:"mode"` // manual | pdf
	PdfPath         *string         `json:"pdf_path"`
	AudioURL        *string         `json:"audio_url"`
	SaveMode        string          `json:"save_mode"` // draft | publish (tạo đề)
	IsActive        *bool           `json:"is_active"` // sửa đề
}

var (
	errPdfRequired = errInvalid("Vui lòng tải file đề PDF.")
	errEmptySheet  = errInvalid("Phiếu trả lời chưa có câu nào — tạo phiếu theo số câu của đề rồi điền đáp án.")
)

func validateCommon(in *TestInput, requireTarget bool) error {
	in.Title = strings.TrimSpace(in.Title)
	in.TargetLevel = strings.TrimSpace(in.TargetLevel)
	switch {
	case in.Title == "":
		return errInvalid("Vui lòng nhập tên đề thi.")
	case runeLen(in.Title) > 255:
		return errInvalid("Tên đề thi không được vượt quá 255 ký tự.")
	case in.Description != nil && runeLen(*in.Description) > 1000:
		return errInvalid("Mô tả không được vượt quá 1000 ký tự.")
	case in.GradeLevel != nil && *in.GradeLevel != "" && !validGradeLevel(*in.GradeLevel):
		return errInvalid("Cấp độ đã chọn không hợp lệ.")
	case requireTarget && in.TargetLevel == "":
		return errInvalid("Vui lòng nhập cấp độ mục tiêu.")
	case runeLen(in.TargetLevel) > 255:
		return errInvalid("Cấp độ mục tiêu không được vượt quá 255 ký tự.")
	case in.DurationMinutes == nil:
		return errInvalid("Vui lòng nhập thời gian làm bài.")
	case *in.DurationMinutes < 10:
		return errInvalid("Thời gian làm bài tối thiểu 10 phút.")
	case *in.DurationMinutes > 600:
		return errInvalid("Thời gian làm bài tối đa 600 phút.")
	case in.QuestionsCount != nil && *in.QuestionsCount < 1:
		return errInvalid("Số câu hỏi tối thiểu là 1.")
	case in.Mode != "" && in.Mode != "pdf" && in.Mode != "manual":
		return errInvalid("Cách soạn đề không hợp lệ.")
	case in.AudioURL != nil && runeLen(*in.AudioURL) > 500:
		return errInvalid("Đường dẫn file nghe không được vượt quá 500 ký tự.")
	}
	return nil
}

func questionsCount(qs []Question, fallback *int, def int32) int32 {
	if len(qs) > 0 {
		return int32(len(qs))
	}
	if fallback != nil {
		return int32(max(1, *fallback))
	}
	return max(1, def)
}

// CreateTest: tạo đề. Cấp độ = khối lớp; mã đề phải chứa khối (vd. G3-G4) để chấm đúng thang điểm.
// "Lưu nháp" = đề ẩn (chưa phát hành link làm bài).
func (s *Service) CreateTest(ctx context.Context, a *iam.Access, in TestInput) (*TestDetail, string, error) {
	in.Code = strings.TrimSpace(in.Code)
	switch {
	case in.Code == "":
		return nil, "", errInvalid("Vui lòng nhập mã đề.")
	case runeLen(in.Code) > 50:
		return nil, "", errInvalid("Mã đề không được vượt quá 50 ký tự.")
	case in.GradeGroup != "" && !IsValidGroup(in.GradeGroup):
		return nil, "", errInvalid("Khối lớp đã chọn không hợp lệ.")
	case in.SaveMode != "" && in.SaveMode != "draft" && in.SaveMode != "publish":
		return nil, "", errInvalid("Cách lưu không hợp lệ.")
	}
	gradeLevel := ""
	if in.GradeLevel != nil {
		gradeLevel = *in.GradeLevel
	}
	// target_level bắt buộc khi không chọn cấp độ / khối.
	if err := validateCommon(&in, gradeLevel == "" && in.GradeGroup == ""); err != nil {
		return nil, "", err
	}
	// Cách "Tải đề PDF": chỉ nhận file tải lên qua /placement-tests/pdf, không còn trang đáp án.
	var pdf *string
	if in.PdfPath != nil {
		var err error
		if pdf, err = s.validatedPdf(ctx, *in.PdfPath, ""); err != nil {
			return nil, "", err
		}
	}
	if in.Mode == "pdf" && pdf == nil {
		return nil, "", errPdfRequired
	}
	group := in.GradeGroup
	if gradeLevel != "" {
		group = RubricGroupForLevel(gradeLevel)
	} else {
		gradeLevel = DetectGradeLevel(in.Code)
	}
	if group != "" && HasRubric(group) && DetectGradeGroup(in.Code) != group {
		return nil, "", errInvalid(fmt.Sprintf(`Mã đề phải chứa "%s" để hệ thống chấm theo thang điểm %s.`, GradeCodeTokens[group], GroupLabel(group)))
	}
	target := in.TargetLevel
	if target == "" {
		if target = gradeLevelLabel(gradeLevel); target == "" {
			target = GroupLabel(group)
		}
	}
	qs, err := normalizeQuestions(in.Questions)
	if err != nil {
		return nil, "", err
	}
	if pdf != nil && len(qs) == 0 {
		return nil, "", errEmptySheet
	}
	audio := (*string)(nil)
	if pdf != nil && in.AudioURL != nil {
		audio = strOrNil(*in.AudioURL)
	}
	if taken, err := s.q.TestCodeTaken(ctx, in.Code); err != nil {
		return nil, "", err
	} else if taken {
		return nil, "", errInvalid("Mã đề đã tồn tại, vui lòng chọn mã khác.")
	}
	qb, _ := json.Marshal(qs)
	desc := (*string)(nil)
	if in.Description != nil {
		desc = strOrNil(*in.Description)
	}
	def := int32(10)
	t, err := s.q.CreateTest(ctx, placementdb.CreateTestParams{
		Code: in.Code, Title: in.Title, Description: desc, TargetLevel: target, GradeLevel: strOrNil(gradeLevel),
		DurationMinutes: int32(*in.DurationMinutes), QuestionsCount: questionsCount(qs, in.QuestionsCount, def),
		Questions: qb, PdfPath: pdf, AudioUrl: audio, IsActive: in.SaveMode != "draft", CreatedBy: &a.UserID,
	})
	if err != nil {
		var pe *pgconn.PgError
		if errors.As(err, &pe) && pe.Code == "23505" {
			return nil, "", errInvalid("Mã đề đã tồn tại, vui lòng chọn mã khác.")
		}
		return nil, "", err
	}
	s.attachPdf(ctx, pdf, t.ID)
	s.log.Info("placement: tạo đề", "test", t.ID, "code", t.Code, "by", a.UserID)
	d := toDetail(t)
	if t.IsActive {
		return &d, fmt.Sprintf("Đã tạo đề kiểm tra trình độ %s (%s) thành công!", t.Title, t.Code), nil
	}
	return &d, fmt.Sprintf("Đã lưu nháp đề %s (%s) — đề đang ẩn, bấm Kích hoạt khi sẵn sàng.", t.Title, t.Code), nil
}

// UpdateTest: sửa đề (đề mẫu hệ thống khóa sửa — nhân bản để tùy biến).
func (s *Service) UpdateTest(ctx context.Context, a *iam.Access, id uuid.UUID, in TestInput) (*TestDetail, string, error) {
	cur, err := s.q.GetTest(ctx, id)
	if err != nil {
		return nil, "", notFound(err, errTestNotFound)
	}
	if cur.IsPreset {
		return nil, "", conflict("preset_locked", fmt.Sprintf("Không thể cập nhật đề thi mẫu hệ thống đã khóa [%s]!", cur.Code))
	}
	if err := validateCommon(&in, true); err != nil {
		return nil, "", err
	}
	// Không gửi pdf_path → giữ file PDF; gửi rỗng → chuyển về đề soạn từng câu; file mới → kiểm như lúc tạo.
	pdf := cur.PdfPath
	if in.PdfPath != nil {
		if pdf, err = s.validatedPdf(ctx, *in.PdfPath, deref(cur.PdfPath)); err != nil {
			return nil, "", err
		}
	}
	if in.Mode == "manual" {
		pdf = nil
	}
	if in.Mode == "pdf" && pdf == nil {
		return nil, "", errPdfRequired
	}
	qb := cur.Questions
	qs := parseQuestions(cur.Questions)
	if len(in.Questions) > 0 && string(in.Questions) != "null" {
		if qs, err = normalizeQuestions(in.Questions); err != nil {
			return nil, "", err
		}
		qb, _ = json.Marshal(qs)
	}
	if pdf != nil && len(qs) == 0 {
		return nil, "", errEmptySheet
	}
	desc := cur.Description
	if in.Description != nil {
		desc = strOrNil(*in.Description)
	}
	level := cur.GradeLevel
	if in.GradeLevel != nil {
		level = strOrNil(*in.GradeLevel)
	}
	audio := (*string)(nil)
	if pdf != nil {
		audio = cur.AudioUrl
		if in.AudioURL != nil {
			audio = strOrNil(*in.AudioURL)
		}
	}
	active := cur.IsActive
	if in.IsActive != nil {
		active = *in.IsActive
	}
	t, err := s.q.UpdateTest(ctx, placementdb.UpdateTestParams{
		ID: id, Title: in.Title, Description: desc, TargetLevel: in.TargetLevel, GradeLevel: level,
		DurationMinutes: int32(*in.DurationMinutes), QuestionsCount: questionsCount(qs, in.QuestionsCount, cur.QuestionsCount),
		Questions: qb, PdfPath: pdf, AudioUrl: audio, IsActive: active,
	})
	if err != nil {
		return nil, "", notFound(err, errTestNotFound)
	}
	s.attachPdf(ctx, pdf, t.ID)
	s.log.Info("placement: sửa đề", "test", t.ID, "by", a.UserID)
	d := toDetail(t)
	return &d, fmt.Sprintf("Đã cập nhật đề thi %s (%s) thành công!", t.Title, t.Code), nil
}

// DuplicateTest: nhân bản đề (kể cả đề mẫu) thành đề tùy biến đang hoạt động.
func (s *Service) DuplicateTest(ctx context.Context, a *iam.Access, id uuid.UUID) (*TestDetail, string, error) {
	src, err := s.q.GetTest(ctx, id)
	if err != nil {
		return nil, "", notFound(err, errTestNotFound)
	}
	var t placementdb.PlacementTest
	for range 5 {
		t, err = s.q.CreateTest(ctx, placementdb.CreateTestParams{
			Code: "TEST-CUSTOM-" + randomCode(5), Title: truncate(src.Title+" (Bản sao tùy biến)", 255),
			Description: src.Description, TargetLevel: src.TargetLevel, GradeLevel: src.GradeLevel,
			DurationMinutes: src.DurationMinutes, QuestionsCount: src.QuestionsCount, Questions: src.Questions,
			PdfPath: src.PdfPath, AudioUrl: src.AudioUrl, IsActive: true, CreatedBy: &a.UserID,
		})
		var pe *pgconn.PgError
		if !errors.As(err, &pe) || pe.Code != "23505" {
			break
		}
	}
	if err != nil {
		return nil, "", err
	}
	s.log.Info("placement: nhân bản đề", "from", src.ID, "test", t.ID, "by", a.UserID)
	d := toDetail(t)
	return &d, fmt.Sprintf("Đã nhân bản thành công đề thi sang mã %s. Bạn có thể tùy ý sửa đổi câu hỏi và cấu hình!", t.Code), nil
}

func truncate(s string, n int) string {
	r := []rune(s)
	if len(r) <= n {
		return s
	}
	return string(r[:n])
}

// ToggleActive: ẩn / kích hoạt đề (đề đã có bài làm không xóa được — ẩn để ngừng phát hành).
func (s *Service) ToggleActive(ctx context.Context, a *iam.Access, id uuid.UUID) (*TestDetail, string, error) {
	cur, err := s.q.GetTest(ctx, id)
	if err != nil {
		return nil, "", notFound(err, errTestNotFound)
	}
	t, err := s.q.SetTestActive(ctx, placementdb.SetTestActiveParams{ID: id, IsActive: !cur.IsActive})
	if err != nil {
		return nil, "", notFound(err, errTestNotFound)
	}
	s.log.Info("placement: đổi trạng thái đề", "test", id, "active", t.IsActive, "by", a.UserID)
	d := toDetail(t)
	if t.IsActive {
		return &d, fmt.Sprintf("Đã kích hoạt đề %s.", t.Code), nil
	}
	return &d, fmt.Sprintf("Đã ẩn đề %s — link làm bài của đề này ngừng hoạt động.", t.Code), nil
}

// DeleteTest: xóa đề (xóa mềm). Đề mẫu và đề đã có bài làm không xóa được (bài làm là dữ liệu tuyển sinh).
func (s *Service) DeleteTest(ctx context.Context, a *iam.Access, id uuid.UUID) (string, error) {
	cur, err := s.q.GetTest(ctx, id)
	if err != nil {
		return "", notFound(err, errTestNotFound)
	}
	if cur.IsPreset {
		return "", conflict("preset_locked", fmt.Sprintf("Đề thi mẫu hệ thống [%s] đã khóa và không thể xóa!", cur.Code))
	}
	n, err := s.q.CountTestSubmissions(ctx, id)
	if err != nil {
		return "", err
	}
	if n > 0 {
		return "", conflict("has_submissions", fmt.Sprintf(`Đề [%s] đã có %d bài làm nên không thể xóa. Hãy bấm "Ẩn" trên danh sách đề để ngừng phát hành.`, cur.Code, n))
	}
	if err := s.q.SoftDeleteTest(ctx, id); err != nil {
		return "", err
	}
	s.log.Info("placement: xóa đề", "test", id, "code", cur.Code, "by", a.UserID)
	return fmt.Sprintf("Đã xóa đề thi %s!", cur.Title), nil
}

// FormOptions: dữ liệu cho form tạo / sửa đề và form chấm.
type FormOptions struct {
	GradeLevels       []GradeGroup      `json:"grade_levels"`
	GradeGroups       []GradeGroup      `json:"grade_groups"`
	LevelRubricGroups map[string]string `json:"level_rubric_groups"`
	GradeCodeTokens   map[string]string `json:"grade_code_tokens"`
	SuggestedCode     string            `json:"suggested_code"`
	Rubric            RubricConfig      `json:"rubric"`
	MaxViolations     int               `json:"max_violations"`
}

// Options trả dữ liệu dùng chung của form (cấp độ, khối, thang điểm).
func (s *Service) Options() FormOptions {
	m := map[string]string{}
	for _, l := range gradeLevels {
		m[l.Value] = RubricGroupForLevel(l.Value)
	}
	return FormOptions{
		GradeLevels: gradeLevels, GradeGroups: GradeGroups(), LevelRubricGroups: m, GradeCodeTokens: GradeCodeTokens,
		SuggestedCode: "TEST-" + GradeCodeTokens[RubricGroupForLevel("lop_3")] + "-" + testCodeStamp(s.now().In(vnZone)),
		Rubric:        Config(), MaxViolations: MaxViolations,
	}
}
