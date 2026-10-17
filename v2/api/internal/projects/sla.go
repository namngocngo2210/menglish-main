package projects

import (
	"context"
	"encoding/json"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/catalogs"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/notify"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/projects/projectsdb"
)

// SLA dự án học thuật (AcademicProjectSlaService): mốc của dự án đã chốt tiến độ (Đang thực hiện) chưa hoàn thành khi
// qua hạn + ân hạn → biên bản "chờ giải trình" cho người nhận mốc (chưa giao thì người phụ trách dự án). Hoàn thành trễ
// mà lần quét chưa kịp bắt cũng lập biên bản. Trước hạn N giờ nhắc người nhận mốc một lần. Chỉ tính mốc có hạn sau thời
// điểm bật (core.settings sla_academic_project_live_since — không phạt hồi tố).

// SlaSubject: loại đối tượng của biên bản trễ mốc (AcademicProjectMilestone::SLA_SUBJECT).
const SlaSubject = "academic_project_milestone"

// Penalty là biên bản trễ deadline của một mốc (Penalty + SlaEvent của Laravel).
type Penalty struct {
	ID          string     `json:"id"`
	Code        string     `json:"code"`
	UserID      *uuid.UUID `json:"user_id"`
	User        *string    `json:"user"`
	MilestoneID uuid.UUID  `json:"milestone_id"`
	Milestone   *string    `json:"milestone"`
	Status      string     `json:"status"`
	StatusLabel string     `json:"status_label"`
	Amount      float64    `json:"amount"`
	CreatedAt   *time.Time `json:"created_at"`
	URL         string     `json:"url"`
}

type Fact struct{ Label, Value string }

// Breach: thông tin lập biên bản trễ mốc (SlaBreachService::open + breach [+ resolve khi đã hoàn thành trễ]).
type Breach struct {
	Rule        string
	SubjectType string
	SubjectID   uuid.UUID
	UserID      *uuid.UUID // người nhận mốc, chưa giao thì người phụ trách dự án
	DueAt       time.Time
	BreachAt    time.Time
	Label       string
	Facts       []Fact
	Link        string
	ResolvedAt  *time.Time // mốc đã hoàn thành (trễ)
}

// Penalties là phân hệ Lương & biên bản (penalties / sla_events của Laravel).
// TODO(gộp): phân hệ Lương & biên bản đang chuyển song song; khi gộp thì viết adapter và gắn bằng WithPenalties ở
// cmd/menglish. Breach phải bỏ qua mốc đã có biên bản (mỗi mốc một biên bản).
type Penalties interface {
	// Enabled: đã có nguồn biên bản (false: màn hình ghi "Chờ phân hệ Lương & biên bản").
	Enabled() bool
	// Breach lập biên bản; ok = đã lập (hoặc đã có từ trước) — mốc được đánh dấu để không quét lại.
	Breach(ctx context.Context, b Breach) (ok bool, err error)
	// ForMilestones: biên bản trễ deadline của các mốc, mới nhất trước.
	ForMilestones(ctx context.Context, milestoneIDs []uuid.UUID) ([]Penalty, error)
}

type noPenalties struct{}

func (noPenalties) Enabled() bool                                                 { return false }
func (noPenalties) Breach(context.Context, Breach) (bool, error)                  { return false, nil }
func (noPenalties) ForMilestones(context.Context, []uuid.UUID) ([]Penalty, error) { return nil, nil }

// rule: cấu hình SLA hiệu lực; chưa nối danh mục / chưa có dòng thì dùng mặc định (bật, def giờ).
func (s *Service) rule(ctx context.Context, key string, def int32) catalogs.SlaRule {
	if s.sla != nil {
		if r, err := s.sla.SlaRule(ctx, key); err == nil {
			return r
		}
	}
	return catalogs.SlaRule{Key: key, Value: def, Enabled: true}
}

func (s *Service) liveSince(ctx context.Context, now time.Time) (time.Time, error) {
	raw, err := s.q.EnsureLiveSince(ctx, now)
	if err != nil {
		return time.Time{}, err
	}
	var v string
	if err := json.Unmarshal(raw, &v); err == nil {
		for _, layout := range []string{time.RFC3339Nano, "2006-01-02T15:04:05.999999-07:00", "2006-01-02 15:04:05"} {
			if t, err := time.Parse(layout, v); err == nil {
				return t, nil
			}
		}
	}
	return now, nil
}

// SlaResult: số mốc đã lập biên bản / đã nhắc trong một lần quét.
type SlaResult struct {
	Breached int
	Reminded int
}

// RunSla quét một lần (lệnh sla:run của Laravel cho academic.milestone_late / milestone_remind).
func (s *Service) RunSla(ctx context.Context) (SlaResult, error) {
	now := s.now()
	var out SlaResult
	since, err := s.liveSince(ctx, now)
	if err != nil {
		return out, err
	}
	if late := s.rule(ctx, SlaLate, 1); late.Enabled {
		if out.Breached, err = s.breachLate(ctx, since, now, late.Value); err != nil {
			return out, err
		}
	}
	if r := s.rule(ctx, SlaRemind, 24); r.Enabled {
		if out.Reminded, err = s.remind(ctx, now, r.Value); err != nil {
			return out, err
		}
	}
	return out, nil
}

// RunSlaLoop: quét ngay rồi sau mỗi tick tới khi ctx hủy.
func (s *Service) RunSlaLoop(ctx context.Context, tick time.Duration) {
	t := time.NewTicker(tick)
	defer t.Stop()
	for {
		if res, err := s.RunSla(ctx); err != nil {
			if ctx.Err() != nil {
				return
			}
			s.log.Error("academic project sla failed", "err", err)
		} else if res.Breached+res.Reminded > 0 {
			s.log.Info("academic project sla", "breached", res.Breached, "reminded", res.Reminded)
		}
		select {
		case <-ctx.Done():
			return
		case <-t.C:
		}
	}
}

func (s *Service) breachLate(ctx context.Context, since, now time.Time, grace int32) (int, error) {
	rows, err := s.q.LateMilestones(ctx, dateOf(now))
	if err != nil {
		return 0, err
	}
	n := 0
	for _, m := range rows {
		due := dueAt(m.DueDate)
		at := due.Add(time.Duration(grace) * time.Hour)
		if due.Before(since) || at.After(now) {
			continue
		}
		if m.Status == "done" && (m.CompletedAt == nil || !m.CompletedAt.After(at)) {
			continue
		}
		user := m.AssigneeID
		if user == nil {
			user = m.ProjectOwnerID
		}
		assignee, doneAt := "Chưa giao (tính cho người phụ trách dự án)", "Chưa hoàn thành"
		if m.AssigneeName != nil {
			assignee = *m.AssigneeName
		}
		if m.CompletedAt != nil {
			doneAt = m.CompletedAt.In(vnZone).Format("15:04 02/01/2006") + " (trễ hạn)"
		}
		ok, err := s.penalties.Breach(ctx, Breach{
			Rule: SlaLate, SubjectType: SlaSubject, SubjectID: m.ID, UserID: user, DueAt: due, BreachAt: at,
			// Tên biên bản lưu cột 255 ký tự: rút gọn tên dự án / mốc dài.
			Label: m.ProjectCode + " " + limit(m.ProjectName, 60) + ` — mốc "` + limit(m.Title, 80) + `"`,
			Facts: []Fact{
				{"Dự án", m.ProjectCode + " · " + m.ProjectName},
				{"Mốc", m.Title},
				{"Người nhận mốc", assignee},
				{"Deadline mốc", m.DueDate.Format("02/01/2006")},
				{"Khối lượng", FormatQuantity(m.DoneQuantity) + " / " + FormatQuantity(m.TargetQuantity) + " " + m.Unit},
				{"Hoàn thành lúc", doneAt},
			},
			Link: projectLink(m.ProjectID), ResolvedAt: m.CompletedAt,
		})
		if err != nil {
			return n, err
		}
		if !ok {
			continue
		}
		if err := s.q.MarkBreached(ctx, m.ID); err != nil {
			return n, err
		}
		n++
	}
	return n, nil
}

// remind: nhắc người nhận mốc trước hạn hours giờ (một lần; đổi hạn thì nhắc lại).
func (s *Service) remind(ctx context.Context, now time.Time, hours int32) (int, error) {
	window := time.Duration(hours) * time.Hour
	rows, err := s.q.MilestonesToRemind(ctx, projectsdb.MilestonesToRemindParams{FromDate: dateOf(now), ToDate: dateOf(now.Add(window))})
	if err != nil {
		return 0, err
	}
	n := 0
	for _, m := range rows {
		due := dueAt(m.DueDate)
		if due.Add(-window).After(now) || due.Before(now) {
			continue
		}
		err := s.tx(ctx, func(tx pgx.Tx, q *projectsdb.Queries) error {
			if _, err := notify.Send(ctx, tx, []uuid.UUID{*m.AssigneeID}, notify.Notification{
				Type: "academic_project_due", Title: `Sắp đến hạn mốc "` + m.Title + `"`,
				Body: "Dự án " + m.ProjectName + ": hạn 23:59 " + m.DueDate.Format("02/01/2006") +
					". Cập nhật tiến độ và đánh dấu hoàn thành trước hạn để không bị lập biên bản trễ deadline.",
				Link: projectLink(m.ProjectID),
			}); err != nil {
				return err
			}
			return q.MarkReminded(ctx, m.ID)
		})
		if err != nil {
			return n, err
		}
		n++
	}
	return n, nil
}

// withTitles: điền tên mốc cho biên bản.
func withTitles(ps []Penalty, titles map[uuid.UUID]string) []Penalty {
	for i := range ps {
		if t, ok := titles[ps[i].MilestoneID]; ok && ps[i].Milestone == nil {
			ps[i].Milestone = &t
		}
	}
	return nonNil(ps)
}
