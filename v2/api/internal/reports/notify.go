package reports

import (
	"context"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/notify"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/reports/reportsdb"
)

// Thông báo của báo cáo & nhật ký (StaffReportController / StaffReportNotifier của Laravel). Gửi sau khi đã lưu
// (báo cáo đã nộp xong); lỗi gửi chỉ ghi log, không làm hỏng thao tác.

func init() {
	audit.RegisterSubject("staff_report", "Báo cáo / nhật ký")
}

func openLink(id uuid.UUID) string { return "/reports/all?open=" + id.String() }

func (s *Service) send(ctx context.Context, ids []uuid.UUID, n notify.Notification) {
	if _, err := notify.Send(ctx, s.pool, ids, n); err != nil {
		s.log.Error("reports: gửi thông báo", "err", err, "type", n.Type)
	}
}

func (s *Service) nameOf(ctx context.Context, id uuid.UUID) string {
	w, err := s.q.GetWriter(ctx, id)
	if err != nil {
		return "Người dùng"
	}
	return w.FullName
}

func (s *Service) admins(ctx context.Context) []uuid.UUID {
	ids, err := iam.AdminIDs(ctx, s.pool)
	if err != nil {
		s.log.Error("reports: danh sách Admin", "err", err)
	}
	return ids
}

// notifySubmitted: báo cáo vào hàng chờ kiểm tra → báo mọi Admin (trừ người nộp).
func (s *Service) notifySubmitted(ctx context.Context, a *iam.Access, r reportsdb.OpsStaffReport, updated bool) {
	title, verb := "Báo cáo mới: ", " vừa nộp "
	if updated {
		title, verb = "Báo cáo cập nhật: ", " vừa cập nhật "
	}
	s.send(ctx, s.admins(ctx), notify.Notification{
		Type: "staff_report_submitted", Title: title + r.Title, Body: s.nameOf(ctx, a.UserID) + verb + r.Title + ". Mở để kiểm tra.",
		Link: openLink(r.ID), Data: map[string]any{"staff_report_id": r.ID}, CreatedBy: &a.UserID, Except: &a.UserID,
	})
}

// notifyResubmitted: người viết nộp lại sau khi bị yêu cầu bổ sung → báo người đã trả về.
func (s *Service) notifyResubmitted(ctx context.Context, a *iam.Access, r reportsdb.OpsStaffReport, reviewer uuid.UUID) {
	s.send(ctx, []uuid.UUID{reviewer}, notify.Notification{
		Type: "staff_report_resubmitted", Title: "Đã bổ sung: " + r.Title,
		Body: s.nameOf(ctx, a.UserID) + " đã nộp lại " + r.Title + " sau khi bạn yêu cầu bổ sung.",
		Link: openLink(r.ID), Data: map[string]any{"staff_report_id": r.ID}, CreatedBy: &a.UserID, Except: &a.UserID,
	})
}

// notifyReviewed: Admin đánh dấu đã kiểm tra → báo người viết.
func (s *Service) notifyReviewed(ctx context.Context, a *iam.Access, r reportsdb.OpsStaffReport) {
	s.send(ctx, []uuid.UUID{r.UserID}, notify.Notification{
		Type: "staff_report_reviewed", Title: "Đã kiểm tra: " + r.Title,
		Body: s.nameOf(ctx, a.UserID) + " đã kiểm tra " + r.Title + ".", Link: WriterPath(r.Kind, r.PeriodKey),
		Data: map[string]any{"staff_report_id": r.ID}, CreatedBy: &a.UserID, Except: &a.UserID,
	})
}

// notifyReturned: Admin yêu cầu bổ sung → báo người viết kèm nhận xét.
func (s *Service) notifyReturned(ctx context.Context, a *iam.Access, r reportsdb.GetScopedReportRow, note string) {
	s.send(ctx, []uuid.UUID{r.UserID}, notify.Notification{
		Type: "staff_report_returned", Title: "Cần bổ sung: " + r.Title,
		Body: s.nameOf(ctx, a.UserID) + ": " + limitText(note, 200), Link: WriterPath(r.Kind, r.PeriodKey),
		Data: map[string]any{"staff_report_id": r.ID}, CreatedBy: &a.UserID, Except: &a.UserID,
	})
}

// notifyUrgentJournal: sự việc mức Khẩn → mọi Admin + Quản lý cơ sở chi nhánh của người được ghi.
func (s *Service) notifyUrgentJournal(ctx context.Context, a *iam.Access, id, subject uuid.UUID, title, className string) {
	ids := s.admins(ctx)
	if w, err := s.q.GetWriter(ctx, subject); err == nil {
		for _, b := range w.BranchIds {
			m, err := iam.UserIDsWithRole(ctx, s.pool, iam.RoleManager, &b)
			if err != nil {
				s.log.Error("reports: quản lý cơ sở", "err", err)
			}
			ids = append(ids, m...)
		}
	}
	body := s.nameOf(ctx, a.UserID) + " vừa ghi sự việc mức Khẩn"
	if className != "" {
		body += " ở lớp " + className
	}
	s.send(ctx, ids, notify.Notification{
		Type: "staff_journal_urgent", Title: "Sự việc khẩn: " + title, Body: body + ".", Link: openLink(id),
		Data: map[string]any{"staff_report_id": id}, CreatedBy: &a.UserID, Except: &a.UserID,
	})
}

// notifyFollowUpTask: việc tạo từ báo cáo ngày → báo người được giao.
func (s *Service) notifyFollowUpTask(ctx context.Context, a *iam.Access, taskID uuid.UUID, t FollowUpTask, reportDay string) {
	s.send(ctx, []uuid.UUID{t.AssigneeID}, notify.Notification{
		Type: "task_assigned", Title: "Bạn được giao việc: " + t.Title,
		Body: s.nameOf(ctx, a.UserID) + " đã giao việc từ báo cáo ngày " + reportDay + ", hạn " + t.DueDate.Format("02/01/2006") + ".",
		Link: "/tasks", Data: map[string]any{"task_id": taskID}, CreatedBy: &a.UserID, Except: &a.UserID,
	})
}
