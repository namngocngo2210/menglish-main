package projects

import (
	"context"
	"fmt"
	"math"
	"regexp"
	"strconv"
	"strings"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/projects/projectsdb"
)

// maxLinks: số link sản phẩm tối đa mỗi lần cập nhật (AcademicProjectUpdate::MAX_LINKS).
const maxLinks = 5

// UpdateInput: thành viên cập nhật tiến độ — việc đã làm, khối lượng xong đến nay (của mốc mình nhận), link, khó khăn.
// QuantityDone nhận số hoặc chuỗi số (ô nhập của form).
type UpdateInput struct {
	MilestoneID   string  `json:"milestone_id"`
	QuantityDone  any     `json:"quantity_done"`
	Content       *string `json:"content"`
	Difficulties  *string `json:"difficulties"`
	LinksText     *string `json:"links_text"`
	MarksComplete bool    `json:"marks_complete"`
}

// quantity: khối lượng đã nhập (nil = để trống).
func quantity(v any) (*float64, error) {
	var f float64
	switch x := v.(type) {
	case nil:
		return nil, nil
	case float64:
		f = x
	case string:
		x = strings.TrimSpace(x)
		if x == "" {
			return nil, nil
		}
		n, err := strconv.ParseFloat(strings.ReplaceAll(x, ",", "."), 64)
		if err != nil || math.IsNaN(n) || math.IsInf(n, 0) {
			return nil, iam.Invalid("Khối lượng phải là số.")
		}
		f = n
	default:
		return nil, iam.Invalid("Khối lượng phải là số.")
	}
	switch {
	case f < 0:
		return nil, iam.Invalid("Khối lượng không được âm.")
	case f > 99999:
		return nil, iam.Invalid("Khối lượng tối đa 99999.")
	}
	f = math.Round(f*100) / 100
	return &f, nil
}

var lineSplit = regexp.MustCompile(`\s*[\r\n]+\s*`)

// ParseLinks: mỗi dòng một link, bỏ trùng, tối đa 5, phải là http(s) đầy đủ, ≤ 500 ký tự.
func ParseLinks(text string) ([]string, error) {
	out := []string{}
	for _, l := range lineSplit.Split(strings.TrimSpace(text), -1) {
		if l == "" {
			continue
		}
		dup := false
		for _, o := range out {
			dup = dup || o == l
		}
		if !dup {
			out = append(out, l)
		}
	}
	if len(out) > maxLinks {
		return nil, iam.Invalid(fmt.Sprintf("Tối đa %d link mỗi lần cập nhật.", maxLinks))
	}
	for _, l := range out {
		if !validURL(l) || strings.ContainsAny(l, " \t") || len(l) > 500 {
			return nil, iam.Invalid("Link không hợp lệ: " + l + ". Dán đường dẫn đầy đủ (https://…), mỗi dòng một link.")
		}
	}
	return out, nil
}

func (s *Service) userName(ctx context.Context, id uuid.UUID) string {
	rows, err := s.q.StaffNames(ctx, []uuid.UUID{id})
	if err != nil || len(rows) == 0 {
		return "Người dùng"
	}
	return rows[0].FullName
}

func (s *Service) CreateUpdate(ctx context.Context, actor *iam.Access, projectID uuid.UUID, in UpdateInput) (string, error) {
	p, err := s.visibleProject(ctx, actor, projectID)
	if err != nil {
		return "", err
	}
	members, err := s.q.ProjectMembers(ctx, projectID)
	if err != nil {
		return "", err
	}
	ms, err := s.q.MilestonesOf(ctx, []uuid.UUID{projectID})
	if err != nil {
		return "", err
	}
	canManage := actor.Can(PermManage)
	if !canManage && !involves(actor.UserID, p.OwnerID, members, ms) {
		return "", forbidden("Bạn không tham gia dự án này nên không cập nhật tiến độ được.")
	}
	if !isOpen(p.Status) {
		return "", conflict("Dự án đã đóng, không cập nhật tiến độ được nữa.")
	}
	content, difficulties, linksText := text(in.Content), text(in.Difficulties), text(in.LinksText)
	switch {
	case content == nil:
		return "", iam.Invalid("Nhập nội dung đã làm / tiến độ.")
	case tooLong(content, 5000):
		return "", iam.Invalid("Nội dung không được dài quá 5000 ký tự.")
	case tooLong(difficulties, 5000):
		return "", iam.Invalid("Khó khăn không được dài quá 5000 ký tự.")
	case tooLong(linksText, 3000):
		return "", iam.Invalid("Danh sách link không được dài quá 3000 ký tự.")
	}
	qty, err := quantity(in.QuantityDone)
	if err != nil {
		return "", err
	}
	var m *projectsdb.MilestonesOfRow
	if raw := strings.TrimSpace(in.MilestoneID); raw != "" {
		id := parseUUID(raw)
		for i := range ms {
			if id != nil && ms[i].ID == *id {
				m = &ms[i]
			}
		}
		if m == nil {
			return "", iam.Invalid("Mốc không thuộc dự án này.")
		}
		if !canManage && (m.AssigneeID == nil || *m.AssigneeID != actor.UserID) {
			return "", iam.Invalid("Bạn chỉ cập nhật được mốc mình được giao.")
		}
	} else if qty != nil || in.MarksComplete {
		return "", iam.Invalid("Chọn mốc để ghi khối lượng hoàn thành.")
	}
	links, err := ParseLinks(deref(linksText))
	if err != nil {
		return "", err
	}
	err = s.tx(ctx, func(tx pgx.Tx, q *projectsdb.Queries) error {
		var mid *uuid.UUID
		if m != nil {
			mid = &m.ID
		}
		id, err := q.CreateUpdate(ctx, projectsdb.CreateUpdateParams{
			ProjectID: projectID, MilestoneID: mid, UserID: &actor.UserID, QuantityDone: qty, Content: *content,
			Difficulties: difficulties, Links: links, MarksComplete: in.MarksComplete,
		})
		if err != nil {
			return err
		}
		if m != nil {
			done, status, at := m.DoneQuantity, m.Status, m.CompletedAt
			if qty != nil {
				done = *qty
			}
			if in.MarksComplete && status != "done" {
				now := s.now()
				status, at, done = "done", &now, math.Max(done, m.TargetQuantity)
			} else if status == "todo" {
				status = "in_progress"
			}
			if err := q.SetMilestoneProgress(ctx, projectsdb.SetMilestoneProgressParams{ID: m.ID, DoneQuantity: done, Status: status, CompletedAt: at}); err != nil {
				return err
			}
		}
		label := p.Code
		if m != nil {
			label += ` · mốc "` + m.Title + `"`
		}
		if err := audit.Record(ctx, tx, audit.Entry{
			ActorID: &actor.UserID, Module: audit.ModuleSyllabus, Event: audit.EventCreated, Action: PermView,
			SubjectType: "academic_project_update", SubjectID: id.String(), SubjectLabel: label,
			Description: "Cập nhật tiến độ dự án học thuật " + label,
		}); err != nil {
			return err
		}
		if p.OwnerID == nil || *p.OwnerID == actor.UserID {
			return nil
		}
		title, body := "Cập nhật tiến độ — "+p.Name, *content
		if difficulties != nil {
			title, body = "Khó khăn cần hỗ trợ — "+p.Name, *difficulties
		}
		who := s.userName(ctx, actor.UserID)
		if m != nil {
			who += ` (mốc "` + m.Title + `")`
		}
		return send(ctx, tx, []uuid.UUID{*p.OwnerID}, actor.UserID, "academic_project_update", title, who+": "+limit(body, 160), projectID)
	})
	if err != nil {
		return "", err
	}
	s.log.Info("academic project update posted", "project_id", projectID, "by", actor.UserID)
	if m != nil && in.MarksComplete {
		return "Đã cập nhật và đánh dấu hoàn thành mốc.", nil
	}
	return "Đã gửi cập nhật tiến độ.", nil
}

// Respond: Học thuật (hoặc người phụ trách dự án) phản hồi khó khăn / cập nhật của thành viên.
func (s *Service) Respond(ctx context.Context, actor *iam.Access, updateID uuid.UUID, response *string) (string, error) {
	u, err := s.q.GetUpdate(ctx, updateID)
	if isNoRows(err) {
		return "", errUpdateNotFound
	}
	if err != nil {
		return "", err
	}
	if !actor.Can(PermManage) && (u.ProjectOwnerID == nil || *u.ProjectOwnerID != actor.UserID) {
		return "", forbidden("Chỉ Học thuật hoặc người phụ trách dự án phản hồi được.")
	}
	r := text(response)
	switch {
	case r == nil:
		return "", iam.Invalid("Nhập nội dung phản hồi.")
	case tooLong(r, 3000):
		return "", iam.Invalid("Phản hồi không được dài quá 3000 ký tự.")
	}
	err = s.tx(ctx, func(tx pgx.Tx, q *projectsdb.Queries) error {
		if err := q.RespondUpdate(ctx, projectsdb.RespondUpdateParams{ID: updateID, Response: r, RespondedBy: &actor.UserID}); err != nil {
			return err
		}
		if err := audit.Record(ctx, tx, audit.Entry{
			ActorID: &actor.UserID, Module: audit.ModuleSyllabus, Event: audit.EventUpdated, Action: PermView,
			SubjectType: "academic_project_update", SubjectID: updateID.String(), SubjectLabel: u.ProjectName,
			Description: "Phản hồi cập nhật tiến độ dự án " + u.ProjectName,
			Changes:     audit.Diff(map[string]any{"response": deref(u.Response)}, map[string]any{"response": *r}),
		}); err != nil {
			return err
		}
		if u.UserID == nil || *u.UserID == actor.UserID {
			return nil
		}
		return send(ctx, tx, []uuid.UUID{*u.UserID}, actor.UserID, "academic_project_response", "Phản hồi cập nhật dự án "+u.ProjectName,
			s.userName(ctx, actor.UserID)+": "+limit(*r, 160), u.ProjectID)
	})
	if err != nil {
		return "", err
	}
	s.log.Info("academic project update answered", "update_id", updateID, "by", actor.UserID)
	return "Đã gửi phản hồi.", nil
}

// inRange: from ≤ t ≤ to.
func inRange(t time.Time, from, to time.Time) bool { return !t.Before(from) && !t.After(to) }
