package placement

import (
	"context"
	"fmt"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/notify"
)

// Thông báo của test đầu vào & học thử (NotificationService::notifyTrialBooked / notifyTrialFeedback, phát hành đề).
// Gửi sau khi thao tác đã lưu (như ghi lịch sử sang khách CRM): lỗi chỉ ghi log, không làm hỏng thao tác.

func init() {
	audit.RegisterSubject("placement_test", "Đề test đầu vào")
	audit.RegisterSubject("trial_booking", "Lịch học thử")
	notify.RegisterType("info", notify.TypeInfo{Label: "Thông tin", Icon: "info", Tone: notify.ToneInfo})
	notify.RegisterType("placement_submitted", notify.TypeInfo{Label: "Bài test chờ chấm", Icon: "assignment_turned_in", Tone: notify.TonePrimary})
}

func (s *Service) send(ctx context.Context, ids []uuid.UUID, n notify.Notification) {
	if _, err := notify.Send(ctx, s.pool, ids, n); err != nil {
		s.log.Error("placement: gửi thông báo", "err", err, "type", n.Type)
	}
}

func (s *Service) userName(ctx context.Context, id uuid.UUID) string {
	var name string
	if err := s.pool.QueryRow(ctx, `SELECT full_name FROM iam.users WHERE id = $1`, id).Scan(&name); err != nil {
		return "Người dùng"
	}
	return name
}

func idList(list ...*uuid.UUID) []uuid.UUID {
	out := make([]uuid.UUID, 0, len(list))
	for _, id := range list {
		if id != nil {
			out = append(out, *id)
		}
	}
	return out
}

// notifyDistributed: người phát hành nhận link làm bài (để dán gửi khách).
func (s *Service) notifyDistributed(ctx context.Context, a *iam.Access, testID uuid.UUID, code, title, path string) {
	s.send(ctx, []uuid.UUID{a.UserID}, notify.Notification{
		Type: "info", Title: "Phát hành đề test đầu vào: " + title,
		Body: fmt.Sprintf("Đề [%s] %s đã được phát hành. Link làm bài: %s", code, title, path),
		Link: "/placement-tests/" + testID.String(), Data: map[string]any{"copy_text": path}, CreatedBy: &a.UserID,
	})
}

// notifyTrialBooked: báo giáo viên / trợ giảng của buổi để chuẩn bị (tên khách + ghi chú của Học vụ).
func (s *Service) notifyTrialBooked(ctx context.Context, a *iam.Access, bookingID uuid.UUID, lead *Lead, sess *Session, start, notes string) {
	className := sess.ClassName
	if className == "" {
		className = "lớp"
	}
	body := lead.Name + " học thử buổi này."
	if notes != "" {
		body += " Ghi chú của Học vụ: " + truncate(notes, 160)
	}
	s.send(ctx, idList(sess.TeacherID, sess.AssistantID), notify.Notification{
		Type: "trial_booked", Title: "Khách học thử buổi " + dmy(sess.Date) + " " + start + " · " + className,
		Body: body + " Sau buổi học, vui lòng nhận xét khách.", Link: "/teacher/trial-guests",
		Data: map[string]any{"trial_booking_id": bookingID}, CreatedBy: &a.UserID, Except: &a.UserID,
	})
}

// notifyTrialFeedback: giáo viên nhận xét xong → báo Học vụ đã xếp + người phụ trách khách để chăm sóc sau học thử.
func (s *Service) notifyTrialFeedback(ctx context.Context, a *iam.Access, bookingID uuid.UUID, bookedBy *uuid.UUID, lead *Lead,
	label string, attended bool, rating *int, feedback string) {
	name := "Khách"
	var owner *uuid.UUID
	if lead != nil {
		name, owner = lead.Name, lead.AssignedUserID
	}
	actor := s.userName(ctx, a.UserID)
	body := actor + " báo khách vắng buổi học thử — liên hệ lại khách."
	if attended && rating != nil {
		body = fmt.Sprintf("%s đã nhận xét (%d/5): %s — chăm sóc khách sau học thử.", actor, *rating, truncate(feedback, 160))
	}
	n := notify.Notification{
		Type: "trial_feedback", Title: "Nhận xét học thử: " + name + " · " + label, Body: body,
		Data: map[string]any{"trial_booking_id": bookingID}, CreatedBy: &a.UserID, Except: &a.UserID,
	}
	if lead != nil {
		n.Link = "/crm/customers/" + lead.ID.String()
		n.Data["customer_id"] = lead.ID
	}
	s.send(ctx, idList(bookedBy, owner), n)
}

// notifySubmission: thí sinh nộp bài → báo người chấm bài test (Laravel gửi email vận hành "homework" cho Học vụ).
func (s *Service) notifySubmission(ctx context.Context, subID uuid.UUID, candidate, phone, testTitle string, branch *uuid.UUID, at time.Time) {
	list, err := iam.UserIDsWithPermission(ctx, s.pool, "placement_test.grade", branch)
	if err != nil {
		s.log.Error("placement: người chấm bài", "err", err)
		return
	}
	if phone == "" {
		phone = "---"
	}
	s.send(ctx, list, notify.Notification{
		Type: "placement_submitted", Title: "[Học vụ] Học viên nộp bài: " + candidate + " - " + testTitle,
		Body: fmt.Sprintf("%s (SĐT: %s) vừa nộp bài %s lúc %s, chờ Học vụ chấm.", candidate, phone, testTitle, at.In(vnZone).Format("15:04 02/01/2006")),
		Link: "/placement-tests/submissions/" + subID.String(), Data: map[string]any{"submission_id": subID},
	})
}
