package portal

import (
	"context"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/notify"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/portal/portaldb"
)

// Hộp thư thông báo của cổng (studentNotifications): thông báo hệ thống gửi tài khoản cổng của học viên (học phí, nhắc
// nợ, kết quả báo đóng, chấm bài…) và thông báo gửi phụ huynh về học viên này. Laravel giữ ở academic_records màn
// 05_danh_sach_thong_bao; v2 dùng chung core.notifications nên trạng thái đã đọc là của từng dòng thông báo.

type InboxItem struct {
	ID      uuid.UUID `json:"id"`
	Type    string    `json:"type"`
	Title   string    `json:"title"`
	Content string    `json:"content"`
	Link    *string   `json:"link"`
	Unread  bool      `json:"unread"`
	Time    time.Time `json:"time"`
	Icon    string    `json:"icon"`
	Tone    string    `json:"tone"`
}

type Inbox struct {
	Context
	Notifications []InboxItem `json:"notifications"`
}

// inbox: tham số lọc hộp thư của học viên đang xem.
func (s *Service) inbox(a *active) portaldb.InboxUnreadParams {
	return portaldb.InboxUnreadParams{StudentUser: a.row.UserID, Viewer: a.viewer, StudentID: a.row.ID.String()}
}

// Notifications: hộp thư của học viên đang xem.
func (s *Service) Notifications(ctx context.Context, actor *iam.Access, studentID *uuid.UUID) (*Inbox, error) {
	a, err := s.resolve(ctx, actor, studentID)
	if err != nil {
		return nil, err
	}
	out := &Inbox{Context: a.Context, Notifications: []InboxItem{}}
	if a.Student == nil {
		return out, nil
	}
	p := s.inbox(a)
	rows, err := s.q.InboxList(ctx, portaldb.InboxListParams(p))
	if err != nil {
		return nil, err
	}
	for _, n := range rows {
		info := notify.Info(n.Type)
		out.Notifications = append(out.Notifications, InboxItem{ID: n.ID, Type: n.Type, Title: n.Title, Content: n.Body,
			Link: n.Link, Unread: n.ReadAt == nil, Time: n.CreatedAt, Icon: info.Icon, Tone: info.Tone})
	}
	return out, nil
}

// inboxFor: học viên được ghi (đánh dấu đọc / xóa cần quyền ghi như Laravel) và tham số hộp thư.
func (s *Service) inboxFor(ctx context.Context, actor *iam.Access, studentID uuid.UUID) (portaldb.InboxUnreadParams, error) {
	st, err := s.writable(ctx, actor, studentID)
	if err != nil {
		return portaldb.InboxUnreadParams{}, err
	}
	p := portaldb.InboxUnreadParams{StudentUser: st.UserID, StudentID: st.ID.String()}
	if st.Linked {
		p.Viewer = &actor.UserID
	}
	return p, nil
}

// MarkAllRead: đánh dấu mọi thông báo của học viên là đã đọc.
func (s *Service) MarkAllRead(ctx context.Context, actor *iam.Access, studentID uuid.UUID) (int64, error) {
	p, err := s.inboxFor(ctx, actor, studentID)
	if err != nil {
		return 0, err
	}
	return s.q.InboxMarkAll(ctx, portaldb.InboxMarkAllParams(p))
}

// MarkRead: đánh dấu một thông báo đã đọc.
func (s *Service) MarkRead(ctx context.Context, actor *iam.Access, studentID, id uuid.UUID) error {
	p, err := s.inboxFor(ctx, actor, studentID)
	if err != nil {
		return err
	}
	n, err := s.q.InboxMarkOne(ctx, portaldb.InboxMarkOneParams{ID: id, StudentUser: p.StudentUser, Viewer: p.Viewer, StudentID: p.StudentID})
	if err == nil && n == 0 {
		return ErrNotFound
	}
	return err
}

// DeleteNotification: xóa một thông báo khỏi hộp thư.
func (s *Service) DeleteNotification(ctx context.Context, actor *iam.Access, studentID, id uuid.UUID) error {
	p, err := s.inboxFor(ctx, actor, studentID)
	if err != nil {
		return err
	}
	n, err := s.q.InboxDelete(ctx, portaldb.InboxDeleteParams{ID: id, StudentUser: p.StudentUser, Viewer: p.Viewer, StudentID: p.StudentID})
	if err != nil {
		return err
	}
	if n == 0 {
		return ErrNotFound
	}
	s.log.Info("portal notification deleted", "notification", id, "student", studentID, "by", actor.UserID)
	return nil
}
