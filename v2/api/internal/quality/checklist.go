package quality

import (
	"context"
	"fmt"
	"slices"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/quality/qualitydb"
)

// Checklist học phí & feedback theo lớp (ClassChecklistController): mỗi lớp có học trong tháng một dòng, 6 mục Có / Không
// / N-A + ghi chú học phí; lưu cả trang một lần. Mục "đến hạn" = Có mà việc cần làm = Không → dòng thiếu sót (gaps).

// ChecklistItems: các cột Có / Không / N-A theo thứ tự mockup (ClassChecklist::ITEMS).
var ChecklistItems = []Field{
	{"tuition_due", "Đến hạn nhắc học phí?"},
	{"tuition_reminded", "Đã nhắc đúng quy trình?"},
	{"tuition_collected", "Thu đúng học phí?"},
	{"big_test_due", "Đến mốc Big Test?"},
	{"feedback_on_time", "Feedback đúng hạn?"},
	{"negative_feedback_handled", "Feedback tiêu cực đã xử lý?"},
}

// FollowUps: mục "đến hạn" → các mục phải làm khi đến hạn.
var FollowUps = map[string][]string{
	"tuition_due":  {"tuition_reminded", "tuition_collected"},
	"big_test_due": {"feedback_on_time", "negative_feedback_handled"},
}

// Answers: Có / Không / N-A.
var Answers = []Field{{"yes", "Có"}, {"no", "Không"}, {"na", "N-A"}}

const checklistPerPage = 30

// Gaps: các mục bị thiếu — đến hạn (Có) nhưng mục cần làm đánh "Không" (ClassChecklist::gaps).
func Gaps(values map[string]*string) []string {
	out := []string{}
	for _, due := range []string{"tuition_due", "big_test_due"} {
		if deref(values[due]) != "yes" {
			continue
		}
		for _, item := range FollowUps[due] {
			if deref(values[item]) == "no" {
				out = append(out, item)
			}
		}
	}
	return out
}

type ChecklistClass struct {
	ID          uuid.UUID          `json:"id"`
	Code        string             `json:"code"`
	Name        string             `json:"name"`
	Teacher     *string            `json:"teacher"`
	Values      map[string]*string `json:"values"`
	TuitionNote *string            `json:"tuition_note"`
	Gaps        []string           `json:"gaps"`
}

type ChecklistPage struct {
	Month     string              `json:"month"`
	Months    []Option            `json:"months"`
	Classes   []ChecklistClass    `json:"classes"`
	Meta      Meta                `json:"meta"`
	Items     []Field             `json:"items"`
	FollowUps map[string][]string `json:"follow_ups"`
	Answers   []Field             `json:"answers"`
	CanEdit   bool                `json:"can_edit"`
}

type ChecklistFilter struct {
	Month  string
	Search string
	Page   int
}

type ChecklistRow struct {
	ClassID                 string  `json:"class_id"`
	TuitionDue              *string `json:"tuition_due"`
	TuitionReminded         *string `json:"tuition_reminded"`
	TuitionCollected        *string `json:"tuition_collected"`
	TuitionNote             *string `json:"tuition_note"`
	BigTestDue              *string `json:"big_test_due"`
	FeedbackOnTime          *string `json:"feedback_on_time"`
	NegativeFeedbackHandled *string `json:"negative_feedback_handled"`
}

type ChecklistInput struct {
	Month string         `json:"month"`
	Rows  []ChecklistRow `json:"rows"`
}

func checklistValues(c qualitydb.AcademicClassChecklist) map[string]*string {
	return map[string]*string{
		"tuition_due": c.TuitionDue, "tuition_reminded": c.TuitionReminded, "tuition_collected": c.TuitionCollected,
		"big_test_due": c.BigTestDue, "feedback_on_time": c.FeedbackOnTime, "negative_feedback_handled": c.NegativeFeedbackHandled,
	}
}

func (s *Service) Checklist(ctx context.Context, actor *iam.Access, f ChecklistFilter) (*ChecklistPage, error) {
	now := s.now()
	month := pickMonth(f.Month, now)
	from, to := monthRange(month)
	sc, err := s.visible(ctx, actor)
	if err != nil {
		return nil, err
	}
	search := likeArg(f.Search)
	total, err := s.q.CountRunningClasses(ctx, qualitydb.CountRunningClassesParams{
		AllClasses: sc.all, Ids: sc.ids, FromDate: from, ToDate: to, Search: search, Month: month,
	})
	if err != nil {
		return nil, err
	}
	meta, off := pageOf(f.Page, checklistPerPage, total)
	rows, err := s.q.RunningClasses(ctx, qualitydb.RunningClassesParams{
		AllClasses: sc.all, Ids: sc.ids, FromDate: from, ToDate: to, Search: search, Month: month, Lim: checklistPerPage, Off: off,
	})
	if err != nil {
		return nil, err
	}
	ids := make([]uuid.UUID, 0, len(rows))
	for _, r := range rows {
		ids = append(ids, r.ID)
	}
	lists, err := s.q.Checklists(ctx, qualitydb.ChecklistsParams{Month: month, ClassIds: ids})
	if err != nil {
		return nil, err
	}
	byClass := map[uuid.UUID]qualitydb.AcademicClassChecklist{}
	for _, c := range lists {
		byClass[c.ClassID] = c
	}
	out := &ChecklistPage{
		Month: month, Months: monthOptions(now, 6, 1), Classes: make([]ChecklistClass, 0, len(rows)), Meta: meta,
		Items: ChecklistItems, FollowUps: FollowUps, Answers: Answers, CanEdit: actor.Can(PermChecklist),
	}
	for _, r := range rows {
		c := ChecklistClass{ID: r.ID, Code: r.Code, Name: r.Name, Teacher: r.TeacherName, Gaps: []string{}}
		if row, ok := byClass[r.ID]; ok {
			c.Values, c.TuitionNote = checklistValues(row), row.TuitionNote
			c.Gaps = Gaps(c.Values)
		} else {
			c.Values = map[string]*string{}
			for _, it := range ChecklistItems {
				c.Values[it.Key] = nil
			}
		}
		out.Classes = append(out.Classes, c)
	}
	return out, nil
}

func validAnswer(p *string) (*string, bool) {
	v := text(p)
	if v == nil {
		return nil, true
	}
	return v, hasKey(Answers, *v)
}

// SaveChecklist: lưu các dòng của trang; dòng chưa đánh dấu gì và chưa có bản ghi thì bỏ qua.
func (s *Service) SaveChecklist(ctx context.Context, actor *iam.Access, in ChecklistInput) (string, error) {
	if !validMonth(in.Month) {
		return "", iam.Invalid("Tháng theo dõi không hợp lệ.")
	}
	if len(in.Rows) == 0 {
		return "", iam.Invalid("Chưa có lớp nào trong checklist.")
	}
	if len(in.Rows) > 200 {
		return "", iam.Invalid("Mỗi lần lưu tối đa 200 lớp.")
	}
	params := make([]qualitydb.UpsertChecklistParams, 0, len(in.Rows))
	ids := make([]uuid.UUID, 0, len(in.Rows))
	for _, r := range in.Rows {
		id := parseUUID(r.ClassID)
		if id == nil {
			return "", iam.Invalid("Lớp không hợp lệ.")
		}
		if slices.Contains(ids, *id) {
			return "", iam.Invalid("Một lớp xuất hiện hai lần trong checklist.")
		}
		ids = append(ids, *id)
		p := qualitydb.UpsertChecklistParams{ClassID: *id, Month: in.Month, UpdatedBy: &actor.UserID, TuitionNote: text(r.TuitionNote)}
		if tooLong(p.TuitionNote, 1000) {
			return "", iam.Invalid("Ghi chú học phí không được dài quá 1000 ký tự.")
		}
		answers := []*string{r.TuitionDue, r.TuitionReminded, r.TuitionCollected, r.BigTestDue, r.FeedbackOnTime, r.NegativeFeedbackHandled}
		for i, a := range answers {
			v, ok := validAnswer(a)
			if !ok {
				return "", iam.Invalid(ChecklistItems[i].Label + " chỉ nhận Có / Không / N-A.")
			}
			answers[i] = v
		}
		p.TuitionDue, p.TuitionReminded, p.TuitionCollected = answers[0], answers[1], answers[2]
		p.BigTestDue, p.FeedbackOnTime, p.NegativeFeedbackHandled = answers[3], answers[4], answers[5]
		params = append(params, p)
	}
	from, to := monthRange(in.Month)
	sc, err := s.visible(ctx, actor)
	if err != nil {
		return "", err
	}
	allowed, err := s.q.RunningClassIDs(ctx, qualitydb.RunningClassIDsParams{IdsIn: ids, AllClasses: sc.all, Ids: sc.ids, FromDate: from, ToDate: to})
	if err != nil {
		return "", err
	}
	if len(allowed) != len(ids) {
		return "", iam.Invalid("Có lớp không học trong tháng đã chọn hoặc nằm ngoài phạm vi bạn được xem.")
	}
	existing, err := s.q.Checklists(ctx, qualitydb.ChecklistsParams{Month: in.Month, ClassIds: ids})
	if err != nil {
		return "", err
	}
	has := map[uuid.UUID]bool{}
	for _, e := range existing {
		has[e.ClassID] = true
	}
	saved := 0
	err = s.tx(ctx, func(tx pgx.Tx, q *qualitydb.Queries) error {
		for _, p := range params {
			empty := p.TuitionNote == nil && p.TuitionDue == nil && p.TuitionReminded == nil && p.TuitionCollected == nil &&
				p.BigTestDue == nil && p.FeedbackOnTime == nil && p.NegativeFeedbackHandled == nil
			if empty && !has[p.ClassID] {
				continue
			}
			if err := q.UpsertChecklist(ctx, p); err != nil {
				return err
			}
			saved++
		}
		if saved == 0 {
			return nil
		}
		return audit.Record(ctx, tx, audit.Entry{
			ActorID: &actor.UserID, Module: audit.ModuleStudents, Event: audit.EventUpdated, Action: "class_quality.checklist",
			SubjectType: "class_checklist", SubjectID: in.Month, SubjectLabel: monthLabel(in.Month),
			Description: fmt.Sprintf("Lưu checklist học phí & feedback %d lớp — %s", saved, monthLabel(in.Month)),
		})
	})
	if err != nil {
		return "", err
	}
	if saved == 0 {
		return "Chưa có lớp nào được đánh dấu.", nil
	}
	s.log.Info("lưu checklist lớp", "month", in.Month, "classes", saved, "by", actor.UserID)
	return fmt.Sprintf("Đã lưu checklist %d lớp — %s.", saved, monthLabel(in.Month)), nil
}
