package syllabus

import (
	"context"
	"fmt"
	"math"
	"strings"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/syllabus/syllabusdb"
)

// Bảng điểm & kết quả Big Test: GV nhập (lưu nháp / gửi duyệt), Admin duyệt, gửi phụ huynh qua Zalo ZNS
// (SyllabusController::bigTestResults / storeBigTestResults / approveBigTestResults / sendZaloResults /
// sendSingleZaloResult / approveAndSendResult). Duyệt & gửi đủ → đóng chặng (syncBigTest).

// MissingParentPhone: nhãn học viên chưa gửi được kết quả vì thiếu SĐT phụ huynh.
const MissingParentPhone = "Thiếu SĐT phụ huynh"

func resultLocked(status string) bool { return status == "approved" || status == "sent" }

type ResultTest struct {
	ID             uuid.UUID `json:"id"`
	Code           string    `json:"code"`
	Room           *string   `json:"room"`
	ClassName      *string   `json:"class_name"`
	ClassTeacher   *string   `json:"class_teacher"`
	IsDistributed  bool      `json:"is_distributed"`
	ResultsDue     string    `json:"results_due"`
	ResultsOverdue bool      `json:"results_overdue"`
	DaysLeft       *int      `json:"days_left"`
	StageBadge     *string   `json:"stage_badge"`
	StageLabel     string    `json:"stage_label"`
}

type ResultStats struct {
	Total   int     `json:"total"`
	Taken   int     `json:"taken"`
	Absent  int     `json:"absent"`
	Avg     float64 `json:"avg"`
	Highest float64 `json:"highest"`
}

type ResultCell struct {
	ID             uuid.UUID `json:"id"`
	Status         string    `json:"status"`
	StatusLabel    string    `json:"status_label"`
	Locked         bool      `json:"locked"`
	IsAbsent       bool      `json:"is_absent"`
	ListeningScore *float64  `json:"listening_score"`
	ReadingScore   *float64  `json:"reading_score"`
	WritingScore   *float64  `json:"writing_score"`
	SpeakingScore  *float64  `json:"speaking_score"`
	OverallScore   *float64  `json:"overall_score"`
	ProgressNote   *string   `json:"progress_note"`
	VideoURL       *string   `json:"video_url"`
	ParentNotified bool      `json:"parent_notified"`
	NotifiedAt     string    `json:"notified_at"`
	MissingPhone   bool      `json:"missing_phone"`
}

type ResultStudent struct {
	StudentID uuid.UUID   `json:"student_id"`
	Name      string      `json:"name"`
	Code      string      `json:"code"`
	Result    *ResultCell `json:"result"`
}

type SelectedResult struct {
	ResultCell
	Student     string  `json:"student"`
	StudentCode string  `json:"student_code"`
	Grader      *string `json:"grader"`
	Approver    *string `json:"approver"`
}

type ResultsPage struct {
	Test               *ResultTest     `json:"test"`
	AllTests           []Option        `json:"all_tests"`
	Stats              ResultStats     `json:"stats"`
	Rows               []ResultStudent `json:"rows"`
	SelectedResult     *SelectedResult `json:"selected_result"`
	IsApprover         bool            `json:"is_approver"`
	CanApprove         bool            `json:"can_approve"`
	CanGradeRole       bool            `json:"can_grade_role"`
	CanGrade           bool            `json:"can_grade"`
	CanBack            bool            `json:"can_back"`
	ResultDeadlineDays int             `json:"result_deadline_days"`
	MissingPhoneLabel  string          `json:"missing_phone_label"`
}

func studentCode(code string) string {
	if code == "" {
		return "—"
	}
	return code
}

func toCell(r syllabusdb.TestResultsRow) ResultCell {
	return ResultCell{
		ID: r.ID, Status: r.Status, StatusLabel: resultStatusLabel(r.Status), Locked: resultLocked(r.Status), IsAbsent: r.IsAbsent,
		ListeningScore: r.ListeningScore, ReadingScore: r.ReadingScore, WritingScore: r.WritingScore, SpeakingScore: r.SpeakingScore,
		OverallScore: r.OverallScore, ProgressNote: r.ProgressNote, VideoURL: r.VideoUrl, ParentNotified: r.ParentNotified,
		MissingPhone: !r.ParentNotified && !r.IsAbsent && strings.TrimSpace(r.ParentPhone) == "",
	}
}

// resultsDue: hạn trả kết quả = ngày thi + N ngày; days = số ngày còn lại (âm = quá hạn).
func (s *Service) resultsDue(ctx context.Context, t syllabusdb.GetBigTestRow) (due string, days *int) {
	if t.ScheduledAt == nil {
		return "", nil
	}
	d := dateOf(*t.ScheduledAt).AddDate(0, 0, s.resultDeadlineDays(ctx))
	return fmtDay(d), ptr(daysBetween(s.today(), d))
}

// BigTestResults: màn "Bảng điểm & kết quả" của một đợt thi (mặc định đợt thi mới nhất trong phạm vi).
func (s *Service) BigTestResults(ctx context.Context, actor *iam.Access, testID, resultID *uuid.UUID) (*ResultsPage, error) {
	sc := scopeOf(actor)
	all, err := s.q.AccessibleBigTests(ctx, syllabusdb.AccessibleBigTestsParams{AllRows: sc.all, UserID: &sc.userID, BranchIds: sc.branches})
	if err != nil {
		return nil, err
	}
	isApprover := actor.Can("big_test.approve")
	gradeRole := actor.Can("syllabus.update") && !isApprover
	out := &ResultsPage{
		AllTests: []Option{}, Rows: []ResultStudent{}, IsApprover: isApprover, CanApprove: isApprover && actor.SuperAdmin,
		CanGradeRole: gradeRole, CanBack: actor.Can("syllabus.manage") || isApprover,
		ResultDeadlineDays: s.resultDeadlineDays(ctx), MissingPhoneLabel: MissingParentPhone,
	}
	for _, t := range all {
		out.AllTests = append(out.AllTests, Option{Value: t.ID.String(), Label: "[" + t.Code + "] " + t.Title + " · " + deref(t.ClassName)})
	}
	if testID == nil {
		if len(all) == 0 {
			if resultID != nil {
				return nil, ErrNotFound
			}
			return out, nil
		}
		testID = &all[0].ID
	}
	t, err := s.getBigTest(ctx, actor, *testID)
	if err != nil {
		return nil, err
	}
	due, days := s.resultsDue(ctx, t)
	rt := &ResultTest{
		ID: t.ID, Code: t.Code, Room: t.Room, ClassName: t.ClassName, ClassTeacher: t.ClassTeacher, IsDistributed: t.IsDistributed,
		ResultsDue: due, DaysLeft: days, ResultsOverdue: days != nil && *days < 0 && t.ResultsCompletedAt == nil,
	}
	if t.StageID != nil && t.StagePosition != nil {
		label := stageLabel(*t.StagePosition, deref(t.StageTitle))
		badge := "Big Test cuối " + label
		if t.ResultsCompletedAt != nil {
			badge += " · đã hoàn tất"
		}
		rt.StageBadge, rt.StageLabel = &badge, "Big Test - "+label
	} else if t.TestType == "final" {
		rt.StageLabel = "Big Test - Cuối khóa"
	} else {
		rt.StageLabel = "Big Test - Giữa kỳ"
	}
	out.Test = rt
	out.CanGrade = t.IsDistributed && gradeRole

	results, err := s.q.TestResults(ctx, t.ID)
	if err != nil {
		return nil, err
	}
	byStudent := map[uuid.UUID]syllabusdb.TestResultsRow{}
	var sum float64
	for _, r := range results {
		byStudent[r.StudentID] = r
		out.Stats.Total++
		if r.IsAbsent {
			out.Stats.Absent++
			continue
		}
		if r.OverallScore != nil {
			out.Stats.Taken++
			sum += *r.OverallScore
			out.Stats.Highest = max(out.Stats.Highest, *r.OverallScore)
		}
	}
	if out.Stats.Taken > 0 {
		out.Stats.Avg = math.Round(sum/float64(out.Stats.Taken)*10) / 10
	}
	if t.ClassID != nil && t.ClassDeletedAt == nil {
		roster, err := s.q.ClassRoster(ctx, syllabusdb.ClassRosterParams{ClassID: t.ClassID, BigTestID: &t.ID})
		if err != nil {
			return nil, err
		}
		for _, st := range roster {
			row := ResultStudent{StudentID: st.ID, Name: st.FullName, Code: studentCode(st.Code)}
			if r, found := byStudent[st.ID]; found {
				c := toCell(r)
				if r.NotifiedAt != nil {
					c.NotifiedAt = r.NotifiedAt.In(vnZone).Format("02/01 15:04")
				}
				row.Result = &c
			}
			out.Rows = append(out.Rows, row)
		}
	}
	if resultID != nil {
		for _, r := range results {
			if r.ID == *resultID {
				c := toCell(r)
				c.NotifiedAt = fmtDateTime(r.NotifiedAt)
				out.SelectedResult = &SelectedResult{ResultCell: c, Student: r.StudentName, StudentCode: studentCode(r.StudentCode),
					Grader: r.GraderName, Approver: r.ApproverName}
			}
		}
		if out.SelectedResult == nil {
			return nil, ErrNotFound
		}
	}
	return out, nil
}

// ───────────── Nhập kết quả ─────────────

type ResultInput struct {
	StudentID      string   `json:"student_id"`
	ListeningScore *float64 `json:"listening_score"`
	ReadingScore   *float64 `json:"reading_score"`
	WritingScore   *float64 `json:"writing_score"`
	SpeakingScore  *float64 `json:"speaking_score"`
	ProgressNote   *string  `json:"progress_note"`
	VideoURL       *string  `json:"video_url"`
	IsAbsent       bool     `json:"is_absent"`
}

type ResultsInput struct {
	Action  string        `json:"action"` // draft | submit (mặc định)
	Results []ResultInput `json:"results"`
}

func (r ResultInput) scores() []*float64 {
	return []*float64{r.ListeningScore, r.ReadingScore, r.WritingScore, r.SpeakingScore}
}

func (r ResultInput) scoreCount() int {
	n := 0
	for _, v := range r.scores() {
		if v != nil {
			n++
		}
	}
	return n
}

// overallOf: điểm tổng = trung bình 4 kỹ năng làm tròn 1 chữ số; thiếu kỹ năng → nil.
func overallOf(scores []*float64) *float64 {
	var sum float64
	for _, v := range scores {
		if v == nil {
			return nil
		}
		sum += *v
	}
	return ptr(math.Round(sum/float64(len(scores))*10) / 10)
}

type parsedResult struct {
	ResultInput
	id uuid.UUID
}

// validateResults: kiểm tra các dòng đã nhập (dòng trống được bỏ qua). Gửi duyệt: đã nhập một kỹ năng thì phải đủ 4.
func validateResults(in ResultsInput, draft bool) ([]parsedResult, error) {
	if in.Action != "" && in.Action != "draft" && in.Action != "submit" {
		return nil, iam.Invalid("Thao tác không hợp lệ.")
	}
	if in.Results == nil {
		return nil, iam.Invalid("Vui lòng nhập kết quả.")
	}
	rows := []parsedResult{}
	for _, r := range in.Results {
		id, err := uuid.Parse(strings.TrimSpace(r.StudentID))
		if err != nil {
			return nil, iam.Invalid("Học viên không hợp lệ.")
		}
		for _, v := range r.scores() {
			if v != nil && (*v < 0 || *v > 10 || math.IsNaN(*v)) {
				return nil, iam.Invalid("Điểm từng kỹ năng phải từ 0 đến 10.")
			}
		}
		r.ProgressNote, r.VideoURL = optText(r.ProgressNote), optText(r.VideoURL)
		if tooLong(r.ProgressNote, 2000) {
			return nil, iam.Invalid("Nhận xét tiến bộ tối đa 2000 ký tự.")
		}
		if r.VideoURL != nil && (!validURL(*r.VideoURL) || runeLen(*r.VideoURL) > 500) {
			return nil, iam.Invalid("Link video không hợp lệ.")
		}
		n := r.scoreCount()
		if !draft && n > 0 && n < 4 {
			return nil, iam.Invalid("Gửi duyệt cần nhập đủ 4 kỹ năng (Listening, Reading, Writing, Speaking) cho học viên đã có điểm; nhập dở thì bấm Lưu nháp.")
		}
		if !r.IsAbsent && n == 0 {
			continue
		}
		if r.IsAbsent && n > 0 {
			return nil, iam.Invalid("Học viên đã đánh dấu \"Vắng thi\" thì không nhập điểm.")
		}
		rows = append(rows, parsedResult{ResultInput: r, id: id})
	}
	return rows, nil
}

// StoreResults: GV nhập kết quả. "Lưu nháp": trạng thái Nháp, nhập dở được. "Gửi duyệt": chuyển Chờ duyệt, kèm các bản
// nháp còn lại đã đủ điểm.
func (s *Service) StoreResults(ctx context.Context, actor *iam.Access, id uuid.UUID, in ResultsInput) (*Message, error) {
	t, err := s.getBigTest(ctx, actor, id)
	if err != nil {
		return nil, err
	}
	if !t.IsDistributed {
		return nil, ErrNotDistributed
	}
	draft := in.Action == "draft"
	rows, err := validateResults(in, draft)
	if err != nil {
		return nil, err
	}
	if len(rows) > 0 {
		ids := make([]uuid.UUID, 0, len(rows))
		for _, r := range rows {
			ids = append(ids, r.id)
		}
		n, err := s.q.StudentsExist(ctx, dedupe(ids))
		if err != nil {
			return nil, err
		}
		if int(n) != len(dedupe(ids)) {
			return nil, iam.Invalid("Học viên không hợp lệ.")
		}
	}
	existing, err := s.q.TestResults(ctx, t.ID)
	if err != nil {
		return nil, err
	}
	allowed := map[uuid.UUID]bool{}
	if t.ClassID != nil {
		roster, err := s.q.RosterIDs(ctx, t.ClassID)
		if err != nil {
			return nil, err
		}
		for _, sid := range roster {
			allowed[sid] = true
		}
	}
	byStudent := map[uuid.UUID]syllabusdb.TestResultsRow{}
	for _, r := range existing {
		allowed[r.StudentID] = true
		byStudent[r.StudentID] = r
	}
	inRows := map[uuid.UUID]bool{}
	for _, r := range rows {
		if !allowed[r.id] {
			return nil, iam.Invalid("Học viên không thuộc lớp thi.")
		}
		inRows[r.id] = true
	}
	drafts := []syllabusdb.TestResultsRow{}
	for _, r := range existing {
		if r.Status == "draft" && !inRows[r.StudentID] {
			drafts = append(drafts, r)
		}
	}
	if len(rows) == 0 && (draft || len(drafts) == 0) {
		return nil, iam.Invalid("Chưa nhập điểm (hoặc đánh dấu vắng thi) cho học viên nào.")
	}
	locked := []string{}
	for _, r := range rows {
		if e, found := byStudent[r.id]; found && resultLocked(e.Status) {
			locked = append(locked, e.StudentName)
		}
	}
	if len(locked) > 0 {
		return nil, iam.Invalid("Không thể sửa điểm đã được duyệt/đã gửi phụ huynh: " + strings.Join(locked, ", ") + ".")
	}

	status := "pending_review"
	if draft {
		status = "draft"
	}
	promoted := 0
	err = s.inTx(ctx, func(tx pgx.Tx, q *syllabusdb.Queries) error {
		for _, r := range rows {
			rowStatus := status
			// "Lưu nháp" không kéo dòng đã gửi duyệt về nháp.
			if e, found := byStudent[r.id]; draft && found && e.Status == "pending_review" {
				rowStatus = "pending_review"
			}
			scores := r.scores()
			if r.IsAbsent {
				scores = []*float64{nil, nil, nil, nil} // vắng thi: không lưu điểm để không kéo điểm trung bình
			}
			var overall *float64
			if !r.IsAbsent {
				overall = overallOf(scores)
			}
			if err := q.UpsertResult(ctx, syllabusdb.UpsertResultParams{
				BigTestID: t.ID, StudentID: r.id, ListeningScore: scores[0], ReadingScore: scores[1], WritingScore: scores[2],
				SpeakingScore: scores[3], OverallScore: overall, IsAbsent: r.IsAbsent, ProgressNote: r.ProgressNote,
				VideoUrl: r.VideoURL, Status: rowStatus, GradedBy: &actor.UserID,
			}); err != nil {
				return err
			}
		}
		if !draft {
			for _, d := range drafts {
				complete := d.IsAbsent || (d.ListeningScore != nil && d.ReadingScore != nil && d.WritingScore != nil && d.SpeakingScore != nil)
				if !complete {
					continue
				}
				if err := q.PromoteDraft(ctx, syllabusdb.PromoteDraftParams{By: &actor.UserID, ID: d.ID}); err != nil {
					return err
				}
				promoted++
			}
		}
		if err := s.resultsChanged(ctx, tx, t.ID); err != nil {
			return err
		}
		action := "Gửi duyệt"
		if draft {
			action = "Lưu nháp"
		}
		return record(ctx, tx, &actor.UserID, audit.EventUpdated, "big_test_result.save", "big_test_result", t.ID, t.Code+" · "+t.Title,
			fmt.Sprintf("%s kết quả %d học viên.", action, len(rows)+promoted), t.BranchID)
	})
	if err != nil {
		return nil, err
	}
	if draft {
		return ok(fmt.Sprintf("Đã lưu nháp kết quả %d học viên (chưa gửi Học thuật duyệt).", len(rows))), nil
	}
	left := len(drafts) - promoted
	msg := fmt.Sprintf("Đã gửi duyệt kết quả %d học viên; kết quả đang chờ Học thuật duyệt.", len(rows)+promoted)
	if left > 0 {
		msg += fmt.Sprintf(" Còn %d bản nháp chưa đủ điểm.", left)
	}
	return ok(msg), nil
}

// resultsChanged: báo phân hệ khác kết quả của đợt thi vừa đổi (nil = chưa gắn).
func (s *Service) resultsChanged(ctx context.Context, tx pgx.Tx, testID uuid.UUID) error {
	if s.support == nil {
		return nil
	}
	return s.support.SyncBigTestResults(ctx, tx, testID)
}

// ───────────── Duyệt & gửi phụ huynh ─────────────

// syncAfter: kiểm tra đóng chặng sau khi duyệt / gửi (trả câu mô tả nối sau thông báo chính).
func (s *Service) syncAfter(ctx context.Context, actor *iam.Access, testID uuid.UUID) (string, error) {
	var o *outcome
	err := s.inTx(ctx, func(tx pgx.Tx, q *syllabusdb.Queries) error {
		if err := s.resultsChanged(ctx, tx, testID); err != nil {
			return err
		}
		var err error
		o, err = s.syncBigTest(ctx, tx, q, testID, &actor.UserID)
		return err
	})
	return describe(o), err
}

// ApproveResults: Admin duyệt mọi kết quả đang chờ duyệt của đợt thi.
func (s *Service) ApproveResults(ctx context.Context, actor *iam.Access, id uuid.UUID) (*Message, error) {
	if err := requireAdmin(actor); err != nil {
		return nil, err
	}
	t, err := s.getBigTest(ctx, actor, id)
	if err != nil {
		return nil, err
	}
	var o *outcome
	err = s.inTx(ctx, func(tx pgx.Tx, q *syllabusdb.Queries) error {
		n, err := q.ApproveTestResults(ctx, syllabusdb.ApproveTestResultsParams{By: &actor.UserID, BigTestID: id})
		if err != nil {
			return err
		}
		if n > 0 {
			if err := record(ctx, tx, &actor.UserID, audit.EventUpdated, "big_test_result.approve", "big_test_result", t.ID,
				t.Code+" · "+t.Title, fmt.Sprintf("Duyệt kết quả %d học viên.", n), t.BranchID); err != nil {
				return err
			}
		}
		if err := s.resultsChanged(ctx, tx, id); err != nil {
			return err
		}
		o, err = s.syncBigTest(ctx, tx, q, id, &actor.UserID)
		return err
	})
	if err != nil {
		return nil, err
	}
	return ok("Đã duyệt kết quả Big Test; có thể gửi cho phụ huynh." + describe(o)), nil
}

// deliver: gửi 1 kết quả qua Zalo ZNS; chỉ đánh dấu đã gửi khi gửi thành công. Trả sent | skipped | failed.
func (s *Service) deliver(ctx context.Context, actor *iam.Access, t syllabusdb.GetBigTestRow, r syllabusdb.TestResultsRow) string {
	if strings.TrimSpace(r.ParentPhone) == "" {
		return "skipped"
	}
	className := deref(t.ClassName)
	if className == "" {
		className = "Lớp MEnglish"
	}
	err := s.zalo.SendBigTestResult(ctx, ZaloResult{
		Phone: r.ParentPhone, StudentName: r.StudentName, ClassName: className, TestTitle: t.Title,
		Listening: fmtScore(r.ListeningScore), Reading: fmtScore(r.ReadingScore), Writing: fmtScore(r.WritingScore),
		Speaking: fmtScore(r.SpeakingScore), Overall: fmtScore(r.OverallScore), ProgressNote: deref(r.ProgressNote),
	})
	if err != nil {
		s.log.Warn("gửi Zalo ZNS kết quả Big Test lỗi", "result", r.ID, "err", err)
		return "failed"
	}
	err = s.inTx(ctx, func(tx pgx.Tx, q *syllabusdb.Queries) error {
		if err := q.MarkResultSent(ctx, r.ID); err != nil {
			return err
		}
		return record(ctx, tx, &actor.UserID, audit.EventUpdated, "big_test_result.send", "big_test_result", r.ID, r.StudentName,
			"Gửi kết quả "+t.Code+" cho phụ huynh em "+r.StudentName+" qua Zalo ZNS.", t.BranchID)
	})
	if err != nil {
		s.log.Error("đánh dấu đã gửi kết quả Big Test lỗi", "result", r.ID, "err", err)
		return "failed"
	}
	return "sent"
}

// SendZaloResults: gửi mọi kết quả đã duyệt, chưa gửi (bỏ vắng thi) của đợt thi.
func (s *Service) SendZaloResults(ctx context.Context, actor *iam.Access, id uuid.UUID) (*Message, error) {
	t, err := s.getBigTest(ctx, actor, id)
	if err != nil {
		return nil, err
	}
	if !t.IsDistributed {
		return nil, ErrNotDistributed
	}
	results, err := s.q.TestResults(ctx, t.ID)
	if err != nil {
		return nil, err
	}
	sent, failed, skipped := 0, []string{}, []string{}
	for _, r := range results {
		if r.Status != "approved" || r.ParentNotified || r.IsAbsent {
			continue
		}
		switch s.deliver(ctx, actor, t, r) {
		case "sent":
			sent++
		case "skipped":
			skipped = append(skipped, r.StudentName)
		default:
			failed = append(failed, r.StudentName)
		}
	}
	if sent == 0 && len(failed) == 0 && len(skipped) == 0 {
		return nil, iam.Invalid("Không có kết quả đã duyệt nào chưa gửi phụ huynh.")
	}
	msg := fmt.Sprintf("Kỳ thi [%s]: đã gửi Zalo ZNS %d phụ huynh", t.Title, sent)
	note := ""
	if sent > 0 {
		if note, err = s.syncAfter(ctx, actor, t.ID); err != nil {
			return nil, err
		}
	}
	if len(failed) == 0 && len(skipped) == 0 {
		return ok(msg + "." + note), nil
	}
	if len(failed) > 0 {
		msg += fmt.Sprintf("; gửi lỗi %d (%s)", len(failed), strings.Join(failed, ", "))
	}
	if len(skipped) > 0 {
		msg += fmt.Sprintf("; bỏ qua %d — %s (%s)", len(skipped), MissingParentPhone, strings.Join(skipped, ", "))
	}
	return &Message{Message: msg + "." + note, Tone: "warning"}, nil
}

// resultOf: kết quả + đợt thi trong phạm vi người dùng.
func (s *Service) resultOf(ctx context.Context, actor *iam.Access, id uuid.UUID) (syllabusdb.GetBigTestRow, syllabusdb.TestResultsRow, error) {
	var row syllabusdb.TestResultsRow
	g, err := s.q.GetResult(ctx, id)
	if isNoRows(err) {
		return syllabusdb.GetBigTestRow{}, row, ErrNotFound
	}
	if err != nil {
		return syllabusdb.GetBigTestRow{}, row, err
	}
	t, err := s.getBigTest(ctx, actor, g.BigTestID)
	if err != nil {
		return t, row, err
	}
	rs, err := s.q.TestResults(ctx, t.ID)
	if err != nil {
		return t, row, err
	}
	for _, r := range rs {
		if r.ID == id {
			return t, r, nil
		}
	}
	return t, row, ErrNotFound
}

// SendSingleResult: gửi lại / gửi riêng kết quả đã duyệt của 1 học viên.
func (s *Service) SendSingleResult(ctx context.Context, actor *iam.Access, id uuid.UUID) (*Message, error) {
	t, r, err := s.resultOf(ctx, actor, id)
	if err != nil {
		return nil, err
	}
	name := r.StudentName
	if r.ParentNotified || r.Status == "sent" {
		return nil, iam.Invalid("Kết quả của em " + name + " đã được gửi phụ huynh trước đó.")
	}
	if r.Status != "approved" {
		return nil, iam.Invalid("Kết quả chưa được Học thuật duyệt.")
	}
	if r.IsAbsent {
		return nil, iam.Invalid("Học viên " + name + " vắng thi — không có điểm để gửi phụ huynh.")
	}
	switch s.deliver(ctx, actor, t, r) {
	case "sent":
		note, err := s.syncAfter(ctx, actor, t.ID)
		if err != nil {
			return nil, err
		}
		return ok("Đã gửi thông báo điểm qua Zalo ZNS đến Phụ huynh em " + name + " thành công!" + note), nil
	case "skipped":
		return nil, iam.Invalid("Không gửi được cho em " + name + ": " + MissingParentPhone + " (nhập SĐT phụ huynh ở hồ sơ học viên).")
	}
	return nil, iam.Invalid("Gửi Zalo ZNS cho phụ huynh em " + name + " thất bại, vui lòng thử lại.")
}

// ApproveAndSend: "Duyệt & Gửi phụ huynh" 1 học viên (vắng thi: chỉ duyệt), rồi kiểm tra đóng chặng.
func (s *Service) ApproveAndSend(ctx context.Context, actor *iam.Access, id uuid.UUID) (*Message, error) {
	if err := requireAdmin(actor); err != nil {
		return nil, err
	}
	t, r, err := s.resultOf(ctx, actor, id)
	if err != nil {
		return nil, err
	}
	if !t.IsDistributed {
		return nil, ErrNotDistributed
	}
	name := r.StudentName
	if r.ParentNotified || r.Status == "sent" {
		return nil, iam.Invalid("Kết quả của em " + name + " đã được gửi phụ huynh trước đó.")
	}
	if r.Status != "pending_review" && r.Status != "approved" {
		return nil, iam.Invalid("Kết quả của em " + name + " chưa được giáo viên nhập / gửi duyệt.")
	}
	if r.Status == "pending_review" {
		if err := s.inTx(ctx, func(tx pgx.Tx, q *syllabusdb.Queries) error {
			if err := q.ApproveResult(ctx, syllabusdb.ApproveResultParams{By: &actor.UserID, ID: r.ID}); err != nil {
				return err
			}
			return record(ctx, tx, &actor.UserID, audit.EventUpdated, "big_test_result.approve", "big_test_result", r.ID, name,
				"Duyệt kết quả "+t.Code+" của em "+name+".", t.BranchID)
		}); err != nil {
			return nil, err
		}
		r.Status = "approved"
	}
	if r.IsAbsent {
		note, err := s.syncAfter(ctx, actor, t.ID)
		if err != nil {
			return nil, err
		}
		return ok("Đã duyệt kết quả em " + name + " (vắng thi — không gửi phụ huynh)." + note), nil
	}
	switch s.deliver(ctx, actor, t, r) {
	case "sent":
		note, err := s.syncAfter(ctx, actor, t.ID)
		if err != nil {
			return nil, err
		}
		return ok("Đã duyệt và gửi kết quả em " + name + " cho phụ huynh qua Zalo ZNS." + note), nil
	case "skipped":
		return &Message{Message: "Đã duyệt kết quả em " + name + ", nhưng chưa gửi được: " + MissingParentPhone +
			" (nhập SĐT phụ huynh ở hồ sơ học viên).", Tone: "warning"}, nil
	}
	return &Message{Message: "Đã duyệt kết quả em " + name + ", nhưng gửi Zalo ZNS thất bại — vui lòng bấm Gửi PH lại.",
		Tone: "warning"}, nil
}
