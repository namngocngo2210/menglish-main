package tuition

import (
	"context"
	"fmt"
	"regexp"
	"slices"
	"strings"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/catalogs"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/notify"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/tuition/tuitiondb"
)

// Màn "Thu phí quá hạn" (TuitionController::overdue): nhóm đến hạn / quá hạn, thống kê công nợ theo chi nhánh / lớp,
// ghi nhận đã liên hệ phụ huynh, báo cáo Admin, gửi nhắc theo mốc (NotificationService::notifyDebtReminderByMilestone).

type BranchDebtStat struct {
	BranchName   string `json:"branch_name"`
	TotalDebt    int64  `json:"total_debt"`
	Count        int    `json:"count"`
	OverdueCount int    `json:"overdue_count"`
}

type ClassDebtStat struct {
	ClassName    string `json:"class_name"`
	ClassCode    string `json:"class_code"`
	TotalDebt    int64  `json:"total_debt"`
	Count        int    `json:"count"`
	OverdueCount int    `json:"overdue_count"`
}

type OverduePage struct {
	Due           DueGroups        `json:"due"`
	StatsByBranch []BranchDebtStat `json:"stats_by_branch"`
	StatsByClass  []ClassDebtStat  `json:"stats_by_class"`
	Branches      []Option         `json:"branches"`
	Classes       []Option         `json:"classes"`
	Filters       map[string]any   `json:"filters"`
}

// Overdue: thống kê lấy toàn bộ khoản đang nợ trong phạm vi (không theo ô tìm kiếm), quá hạn = hạn đóng trước hôm nay
// và không đang tạm dừng nhắc.
func (s *Service) Overdue(ctx context.Context, actor *iam.Access, f TuitionFilter) (*OverduePage, error) {
	out := &OverduePage{StatsByBranch: []BranchDebtStat{}, StatsByClass: []ClassDebtStat{},
		Filters: map[string]any{"search": f.Search, "branch_id": f.BranchID, "class_id": f.ClassID, "type": f.Type}}
	var err error
	if out.Due, err = s.dueGroups(ctx, actor, f); err != nil {
		return nil, err
	}
	rows, err := s.q.DebtSummaryRows(ctx, branchLimit(actor))
	if err != nil {
		return nil, err
	}
	today := s.today()
	branchAt := map[uuid.UUID]int{}
	classAt := map[uuid.UUID]int{}
	for _, r := range rows {
		overdue := 0
		if r.DueDate != nil && r.DueDate.Before(today) && !pausedOn(r.ReminderPausedUntil, today) {
			overdue = 1
		}
		bk := val(r.BranchID)
		i, ok := branchAt[bk]
		if !ok {
			name := "Chưa gán chi nhánh"
			if r.BranchName != nil {
				name = *r.BranchName
			}
			i = len(out.StatsByBranch)
			branchAt[bk] = i
			out.StatsByBranch = append(out.StatsByBranch, BranchDebtStat{BranchName: name})
		}
		b := &out.StatsByBranch[i]
		b.TotalDebt += r.DebtAmount
		b.Count++
		b.OverdueCount += overdue

		ck := val(r.ClassID)
		j, ok := classAt[ck]
		if !ok {
			name, code := "Chưa xếp lớp", "—"
			if r.ClassName != nil {
				name = *r.ClassName
			}
			if r.ClassCode != nil {
				code = *r.ClassCode
			}
			j = len(out.StatsByClass)
			classAt[ck] = j
			out.StatsByClass = append(out.StatsByClass, ClassDebtStat{ClassName: name, ClassCode: code})
		}
		c := &out.StatsByClass[j]
		c.TotalDebt += r.DebtAmount
		c.Count++
		c.OverdueCount += overdue
	}
	if out.Branches, out.Classes, err = s.scopeOptions(ctx, actor, true); err != nil {
		return nil, err
	}
	return out, nil
}

// reminderTarget: khoản học phí trong phạm vi của người thao tác (TuitionBranchScope::allowsTuition).
func (s *Service) reminderTarget(ctx context.Context, actor *iam.Access, id uuid.UUID) (tuitiondb.ReminderTargetRow, error) {
	t, err := s.q.ReminderTarget(ctx, id)
	if isNoRows(err) {
		return t, ErrTuitionNotFound
	}
	if err != nil {
		return t, err
	}
	if !covers(actor, nonZero(t.ScopeBranchID)) {
		return t, ErrOutOfScope
	}
	return t, nil
}

type ContactInput struct {
	Note        *string `json:"note"`
	ContactedAt *string `json:"contacted_at"`
}

// parseDateTime: ngày giờ từ ô nhập (giờ Việt Nam).
func parseDateTime(v string) (time.Time, bool) {
	v = strings.TrimSpace(v)
	for _, layout := range []string{time.RFC3339, "2006-01-02T15:04:05", "2006-01-02T15:04", "2006-01-02 15:04:05", "2006-01-02 15:04", "2006-01-02"} {
		if t, err := time.ParseInLocation(layout, v, vnZone); err == nil {
			return t, true
		}
	}
	return time.Time{}, false
}

// MarkContacted: "Đã liên hệ" — ghi nhật ký đôn đốc, dòng hiện "Đã liên hệ — chờ thu".
func (s *Service) MarkContacted(ctx context.Context, actor *iam.Access, id uuid.UUID, in ContactInput) (string, error) {
	note := optText(in.Note)
	if tooLong(note, 1000) {
		return "", iam.Invalid("Ghi chú tối đa 1000 ký tự.")
	}
	at := s.now()
	if v := optText(in.ContactedAt); v != nil {
		t, ok := parseDateTime(*v)
		if !ok {
			return "", iam.Invalid("Thời gian liên hệ không hợp lệ.")
		}
		if t.After(at) {
			return "", iam.Invalid("Thời gian liên hệ không được ở tương lai.")
		}
		at = t
	}
	t, err := s.reminderTarget(ctx, actor, id)
	if err != nil {
		return "", err
	}
	if err := s.q.InsertContactLog(ctx, tuitiondb.InsertContactLogParams{TuitionID: id, UserID: &actor.UserID, Action: "contacted", Note: note, ContactedAt: at}); err != nil {
		return "", err
	}
	s.log.Info("tuition overdue contacted", "tuition_id", id, "by", actor.UserID)
	return "Đã ghi nhận liên hệ với phụ huynh học viên " + t.StudentName + " — chờ thu.", nil
}

// ReportOverdue: "Báo cáo Admin" — thông báo tới mọi Admin đang hoạt động và ghi nhật ký đôn đốc.
func (s *Service) ReportOverdue(ctx context.Context, actor *iam.Access, id uuid.UUID, rawNote *string) (string, error) {
	note := optText(rawNote)
	if tooLong(note, 1000) {
		return "", iam.Invalid("Ghi chú tối đa 1000 ký tự.")
	}
	t, err := s.reminderTarget(ctx, actor, id)
	if err != nil {
		return "", err
	}
	var count int
	err = s.inTx(ctx, func(tx pgx.Tx, q *tuitiondb.Queries) error {
		reporter, err := q.UserFullName(ctx, actor.UserID)
		if err != nil && !isNoRows(err) {
			return err
		}
		days := s.daysOverdue(t.DueDate)
		class := "chưa xếp lớp"
		if t.ClassName != nil {
			class = *t.ClassName
		}
		msg := fmt.Sprintf("%s báo cáo học viên %s (%s) lớp %s quá hạn %d ngày, còn nợ %s", reporter, t.StudentName, t.StudentCode, class,
			max(0, days), money(t.DebtAmount))
		if note != nil {
			msg += ". Ghi chú: " + *note
		} else {
			msg += "."
		}
		admins, err := iam.AdminIDs(ctx, tx)
		if err != nil {
			return err
		}
		count = len(admins)
		if _, err := notify.Send(ctx, tx, admins, notify.Notification{
			Type: "overdue_report", Title: "Báo cáo học phí quá hạn cần xử lý", Body: msg,
			Link:      "/tuition/overdue?search=" + t.StudentCode,
			Data:      map[string]any{"student_tuition_id": t.ID, "student_id": t.StudentID, "days_overdue": days},
			CreatedBy: &actor.UserID,
		}); err != nil {
			return err
		}
		return q.InsertContactLog(ctx, tuitiondb.InsertContactLogParams{TuitionID: id, UserID: &actor.UserID, Action: "reported_admin", Note: note, ContactedAt: s.now()})
	})
	if err != nil {
		return "", err
	}
	s.log.Info("tuition overdue reported", "tuition_id", id, "admins", count, "by", actor.UserID)
	return fmt.Sprintf("Đã báo cáo Admin (%d người nhận) về khoản quá hạn của học viên %s.", count, t.StudentName), nil
}

// ───────────── Nhắc học phí theo mốc ─────────────

type ReminderResult struct {
	Sent      bool    `json:"sent"`
	Milestone *string `json:"milestone"`
	Reason    string  `json:"reason"`
}

// defaultMilestones: mốc mặc định khi chưa cấu hình mốc nào (SendDebtRemindersCommand::DEFAULT_MILESTONES).
var defaultMilestones = []catalogs.DebtRule{
	{MilestoneKey: "T-7", OffsetDays: -7}, {MilestoneKey: "T-3", OffsetDays: -3}, {MilestoneKey: "T0", OffsetDays: 0}, {MilestoneKey: "T+3", OffsetDays: 3},
}

const defaultDebtTemplate = "Học viên {ten_hoc_vien} (mã {ma_hoc_vien}, SĐT {so_dien_thoai}) lớp {lop_hoc} còn nợ {so_tien}, hạn đóng {han_dong}. " +
	"Vui lòng liên hệ trung tâm để hoàn tất học phí."

func defaultDebtTitle(milestone string) string {
	switch milestone {
	case "T-7":
		return "Thông báo trước hạn 1 tuần"
	case "T-3":
		return "Nhắc trước hạn 3 ngày"
	case "T0":
		return "Nhắc đúng ngày đến hạn"
	}
	return "Cảnh báo quá hạn"
}

func (s *Service) debtRules(ctx context.Context) ([]catalogs.DebtRule, error) {
	if s.cat == nil {
		return nil, nil
	}
	c, err := s.cat.DebtReminders(ctx)
	if err != nil {
		return nil, err
	}
	return c.Rules, nil
}

func enabledRules(rules []catalogs.DebtRule) []catalogs.DebtRule {
	out := make([]catalogs.DebtRule, 0, len(rules))
	for _, r := range rules {
		if r.IsEnabled {
			out = append(out, r)
		}
	}
	slices.SortStableFunc(out, func(a, b catalogs.DebtRule) int { return int(a.OffsetDays - b.OffsetDays) })
	return out
}

// milestoneFor: mốc theo số ngày so với hạn đóng (diff = hôm nay − hạn): mốc bật cuối cùng đã tới, chưa tới mốc nào thì
// mốc sớm nhất; chưa bật mốc nào thì T-7 / T-3 / T0 / T+3 (NotificationService::debtMilestoneFor).
func milestoneFor(rules []catalogs.DebtRule, diff int) string {
	if on := enabledRules(rules); len(on) > 0 {
		pick := on[0].MilestoneKey
		for _, r := range on {
			if int(r.OffsetDays) <= diff {
				pick = r.MilestoneKey
			}
		}
		return pick
	}
	switch {
	case diff < -3:
		return "T-7"
	case diff < 0:
		return "T-3"
	case diff == 0:
		return "T0"
	}
	return "T+3"
}

var templateVar = regexp.MustCompile(`\{[^{}\s]+\}`)

var templateAliases = map[string]string{"{ten_lop}": "{lop_hoc}", "{han_nop}": "{han_dong}", "{han_chot}": "{han_dong}"}

// renderTemplate: biến mẫu dạng thường / IN HOA và biến đồng nghĩa cũ đều được thay (DebtReminderRule::render).
func renderTemplate(tpl string, vals map[string]string) string {
	return templateVar.ReplaceAllStringFunc(tpl, func(m string) string {
		k := strings.ToLower(m)
		if a, ok := templateAliases[k]; ok {
			k = a
		}
		if v, ok := vals[k]; ok {
			return v
		}
		return m
	})
}

// SendDebtReminder: "Gửi nhắc" trên màn Quá hạn / Sắp đến hạn — mốc theo số ngày so với hạn đóng hôm nay.
func (s *Service) SendDebtReminder(ctx context.Context, actor *iam.Access, id uuid.UUID) (*ReminderResult, string, error) {
	t, err := s.reminderTarget(ctx, actor, id)
	if err != nil {
		return nil, "", err
	}
	res, err := s.remindDebt(ctx, t, "", &actor.UserID)
	if err != nil {
		s.log.Warn("debt reminder failed", "tuition_id", id, "err", err, "by", actor.UserID)
		return &ReminderResult{Reason: "Gửi nhắc thất bại, vui lòng thử lại."}, "Gửi nhắc thất bại, vui lòng thử lại.", nil
	}
	s.log.Info("debt reminder", "tuition_id", id, "sent", res.Sent, "milestone", res.Milestone, "by", actor.UserID)
	if res.Sent {
		return res, fmt.Sprintf("Đã gửi nhắc mốc %s cho học viên %s (%s): %s", val(res.Milestone), t.StudentName, t.StudentPhone, res.Reason), nil
	}
	return res, "Chưa gửi được: " + res.Reason, nil
}

// remindDebt: engine nhắc nợ. Mốc tắt / chưa chọn kênh thì không gửi; mỗi (khoản, mốc, ngày) gửi một lần
// (finance.debt_reminder_logs). Kênh portal: thông báo vào tài khoản cổng của học viên; kênh email: thư cảnh báo nội bộ.
func (s *Service) remindDebt(ctx context.Context, t tuitiondb.ReminderTargetRow, milestone string, by *uuid.UUID) (*ReminderResult, error) {
	if t.DueDate == nil || t.DebtAmount <= 0 {
		return &ReminderResult{Reason: "Hợp đồng chưa có hạn đóng hoặc đã hết nợ."}, nil
	}
	rules, err := s.debtRules(ctx)
	if err != nil {
		return nil, err
	}
	today := s.today()
	if milestone == "" {
		milestone = milestoneFor(rules, daysBetween(*t.DueDate, today))
	}
	res := &ReminderResult{Milestone: &milestone}
	rule := catalogs.DebtRule{MilestoneKey: milestone, Title: defaultDebtTitle(milestone), TemplateContent: defaultDebtTemplate,
		IsEnabled: true, Channels: []string{"portal", "email"}}
	for _, r := range rules {
		if r.MilestoneKey == milestone {
			rule = r
			break
		}
	}
	if !rule.IsEnabled {
		res.Reason = "Mốc nhắc " + milestone + " chưa được bật trong cấu hình."
		return res, nil
	}
	class := "Chưa phân lớp"
	if t.ClassName != nil {
		class = *t.ClassName
	}
	dash := func(v string) string {
		if strings.TrimSpace(v) == "" {
			return "—"
		}
		return v
	}
	content := renderTemplate(rule.TemplateContent, map[string]string{
		"{ten_hoc_vien}": t.StudentName, "{ma_hoc_vien}": dash(t.StudentCode), "{so_dien_thoai}": dash(t.StudentPhone),
		"{lop_hoc}": class, "{so_tien}": money(t.DebtAmount), "{han_dong}": t.DueDate.Format("02/01/2006"), "{moc_nhac}": rule.Title,
	})
	var channels []string
	for _, c := range rule.Channels {
		if c == "portal" || c == "email" {
			channels = append(channels, c)
		}
	}
	if len(channels) == 0 {
		res.Reason = "Mốc nhắc " + milestone + " chưa chọn kênh gửi."
		return res, nil
	}
	res.Sent = true
	err = s.inTx(ctx, func(tx pgx.Tx, q *tuitiondb.Queries) error {
		n, err := q.InsertDebtReminderLog(ctx, tuitiondb.InsertDebtReminderLogParams{TuitionID: t.ID, Milestone: milestone, SentOn: today,
			Title: limitText(rule.Title, 255), Content: limitText(content, 5000), Channels: channels, SentBy: by})
		if err != nil {
			return err
		}
		if n == 0 {
			res.Reason = "Đã gửi mốc " + rule.Title + " trong hôm nay."
			return nil
		}
		res.Reason = "Đã gửi theo mốc " + rule.Title + "."
		if slices.Contains(channels, "portal") && t.StudentUserID != nil {
			if _, err := notify.Send(ctx, tx, []uuid.UUID{*t.StudentUserID}, notify.Notification{
				Type: "debt_reminder", Title: rule.Title, Body: content,
				Data: map[string]any{"student_tuition_id": t.ID, "student_id": t.StudentID, "milestone": milestone}, CreatedBy: by,
			}); err != nil {
				return err
			}
		}
		if slices.Contains(channels, "email") {
			// TODO(email): thư cảnh báo nội bộ "[Nhắc nợ <mốc>] <học viên> — <số tiền>" (sendOperationalAlertEmail overdue_debt),
			// thêm dòng "(Đã đồng thời gửi thông báo vào Cổng PH/HS của học viên.)" khi bật kênh portal.
			_ = content
		}
		return nil
	})
	if err != nil {
		return nil, err
	}
	return res, nil
}
