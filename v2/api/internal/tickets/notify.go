package tickets

import (
	"context"
	"errors"
	"regexp"
	"slices"
	"strings"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/notify"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/notify/mail"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/tickets/ticketsdb"
)

// Thông báo + email ticket như NotificationService::notifyTicketCreated / Message / StatusChanged / Assigned.
// Thư đi qua hàng đợi mail (gửi sau khi giao dịch xong); thông báo trong ứng dụng ghi cùng giao dịch.

var tagRe = regexp.MustCompile(`<[^>]*>`)

// preview: Str::limit(strip_tags($s), n).
func preview(s string, n int) string {
	s = strings.TrimSpace(tagRe.ReplaceAllString(s, ""))
	r := []rune(s)
	if len(r) <= n {
		return s
	}
	return strings.TrimRight(string(r[:n]), " ") + "..."
}

func link(t ticketsdb.OpsTicket) string { return "/tickets/" + t.ID.String() }

func baseData(t ticketsdb.OpsTicket) map[string]any {
	return map[string]any{"ticket_id": t.ID, "ticket_code": t.Code, "ticket_title": t.Title}
}

func withData(d map[string]any, k string, v any) map[string]any { d[k] = v; return d }

// dispatchers: người nhận ticket chưa phân công (NotificationService::ticketDispatcherIds). Người tạo luôn là người đang
// thao tác (a). Người tạo tự phân công được → chỉ Admin (Admin tự tạo → không ai); ngược lại người có
// support_ticket.assign cùng chi nhánh với người tạo, người tạo không có chi nhánh → mọi người phân công; không có ai →
// Admin.
func (s *Service) dispatchers(ctx context.Context, tx pgx.Tx, q *ticketsdb.Queries, a *iam.Access) ([]uuid.UUID, error) {
	drop := func(ids []uuid.UUID) []uuid.UUID {
		return slices.DeleteFunc(ids, func(id uuid.UUID) bool { return id == a.UserID })
	}
	if a.Can(PermAssign) {
		if a.SuperAdmin {
			return nil, nil
		}
		ids, err := iam.AdminIDs(ctx, tx)
		return drop(ids), err
	}
	branches, err := q.UserBranchIDs(ctx, a.UserID)
	if err != nil {
		return nil, err
	}
	var ids []uuid.UUID
	if len(branches) == 0 {
		if ids, err = iam.UserIDsWithPermission(ctx, tx, PermAssign, nil); err != nil {
			return nil, err
		}
	}
	for _, b := range branches {
		got, err := iam.UserIDsWithPermission(ctx, tx, PermAssign, &b)
		if err != nil {
			return nil, err
		}
		ids = append(ids, got...)
	}
	ids, err = s.unlocked(ctx, q, ids)
	if err != nil {
		return nil, err
	}
	if len(ids) == 0 {
		if ids, err = iam.AdminIDs(ctx, tx); err != nil {
			return nil, err
		}
		if ids, err = s.unlocked(ctx, q, ids); err != nil {
			return nil, err
		}
	}
	return drop(ids), nil
}

// unlocked: bỏ trùng, chỉ giữ tài khoản đang hoạt động, không bị khóa.
func (s *Service) unlocked(ctx context.Context, q *ticketsdb.Queries, ids []uuid.UUID) ([]uuid.UUID, error) {
	if len(ids) == 0 {
		return nil, nil
	}
	rows, err := q.ActiveUsers(ctx, ids)
	if err != nil {
		return nil, err
	}
	out := make([]uuid.UUID, 0, len(rows))
	for _, r := range rows {
		out = append(out, r.ID)
	}
	slices.SortFunc(out, func(x, y uuid.UUID) int { return strings.Compare(x.String(), y.String()) })
	return slices.Compact(out), nil
}

// emails: email của các người nhận + danh sách "Email nhận Ticket" khi sự kiện được bật (event rỗng = không thêm).
func (s *Service) emails(ctx context.Context, tx pgx.Tx, q *ticketsdb.Queries, ids []uuid.UUID, event string) ([]string, error) {
	var out []string
	add := func(e string) {
		if a := mail.NormalizeAddress(e); a != "" && !slices.Contains(out, a) {
			out = append(out, a)
		}
	}
	if len(ids) > 0 {
		rows, err := q.ActiveUsers(ctx, ids)
		if err != nil {
			return nil, err
		}
		for _, r := range rows {
			add(r.Email)
		}
	}
	if event != "" {
		on, err := mail.EventEnabled(ctx, tx, event)
		if err != nil {
			return nil, err
		}
		if on {
			list, err := mail.TicketRecipients(ctx, tx)
			if err != nil {
				return nil, err
			}
			for _, e := range list {
				add(e)
			}
		}
	}
	return out, nil
}

// sendMail xếp thư ticket cho từng địa chỉ; địa chỉ sai bị bỏ qua (Laravel nuốt lỗi gửi thư).
func (s *Service) sendMail(ctx context.Context, tx pgx.Tx, a *iam.Access, t ticketsdb.OpsTicket, to []string, subject string, data mail.Ticket) error {
	data.Code, data.Title, data.Subject = t.Code, t.Title, subject
	data.Status, data.StatusLabel = t.Status, StatusLabel(t.Status)
	data.Priority, data.PriorityLabel = t.Priority, PriorityLabel(t.Priority)
	data.CategoryLabel = CategoryLabel(t.Category)
	data.ActionURL = link(t)
	for _, addr := range to {
		_, err := mail.Enqueue(ctx, tx, mail.Message{To: addr, Subject: subject, Template: mail.TemplateTicket, Data: data,
			Kind: mail.KindTicket, RefType: "ticket", RefID: t.ID.String(), CreatedBy: &a.UserID})
		if errors.Is(err, mail.ErrInvalidAddress) {
			s.log.Warn("ticket mail: địa chỉ sai", "ticket", t.Code)
			continue
		}
		if err != nil {
			return err
		}
	}
	return nil
}

func (s *Service) notifyCreated(ctx context.Context, tx pgx.Tx, q *ticketsdb.Queries, a *iam.Access, t ticketsdb.OpsTicket, creator string) error {
	title := "[#" + t.Code + "] " + t.Title
	if t.AssigneeID != nil && *t.AssigneeID != t.CreatorID {
		if _, err := notify.Send(ctx, tx, []uuid.UUID{*t.AssigneeID}, notify.Notification{Type: "ticket_assigned",
			Title: title, Body: creator + " đã giao cho bạn: " + preview(t.Description, 120), Link: link(t),
			Data: withData(baseData(t), "creator_name", creator), CreatedBy: &a.UserID}); err != nil {
			return err
		}
	}
	if t.AssigneeID == nil {
		ids, err := s.dispatchers(ctx, tx, q, a)
		if err != nil {
			return err
		}
		if _, err := notify.Send(ctx, tx, ids, notify.Notification{Type: "ticket_new", Title: title,
			Body: creator + ": " + preview(t.Description, 120), Link: link(t),
			Data: withData(baseData(t), "creator_name", creator), CreatedBy: &a.UserID}); err != nil {
			return err
		}
	}
	to, err := s.emails(ctx, tx, q, nil, mail.EventTicketCreated)
	if err != nil {
		return err
	}
	return s.sendMail(ctx, tx, a, t, to, title, mail.Ticket{Type: "new", Content: preview(t.Description, maxText),
		SenderName: creator, ActionText: "Xử lý Ticket #" + t.Code})
}

func (s *Service) notifyMessage(ctx context.Context, tx pgx.Tx, q *ticketsdb.Queries, a *iam.Access, t ticketsdb.OpsTicket, msg ticketsdb.OpsTicketMessage, sender string) error {
	var ids []uuid.UUID
	if t.CreatorID != a.UserID {
		ids = append(ids, t.CreatorID)
	}
	if t.AssigneeID != nil && *t.AssigneeID != a.UserID {
		ids = append(ids, *t.AssigneeID)
	}
	parts, err := q.TicketParticipantIDs(ctx, t.ID)
	if err != nil {
		return err
	}
	ids = append(ids, parts...)
	ids = slices.DeleteFunc(ids, func(id uuid.UUID) bool { return id == a.UserID })
	if msg.IsInternalNote {
		// Ghi chú nội bộ: chỉ người xử lý ticket / người được giao.
		managers := map[uuid.UUID]bool{}
		for _, p := range []string{PermUpdate, PermAssign, PermClose} {
			got, err := iam.UserIDsWithPermission(ctx, tx, p, nil)
			if err != nil {
				return err
			}
			for _, id := range got {
				managers[id] = true
			}
		}
		ref := ticketRef{t.CreatorID, t.AssigneeID}
		ids = slices.DeleteFunc(ids, func(id uuid.UUID) bool { return !managers[id] && !ref.assignedTo(id) })
	}
	if t.AssigneeID == nil && t.CreatorID == a.UserID {
		more, err := s.dispatchers(ctx, tx, q, a)
		if err != nil {
			return err
		}
		ids = append(ids, more...)
	}
	if ids, err = s.unlocked(ctx, q, ids); err != nil {
		return err
	}
	title := "Re: [#" + t.Code + "] " + t.Title
	if _, err := notify.Send(ctx, tx, ids, notify.Notification{Type: "ticket_message", Title: title,
		Body: sender + ": " + preview(msg.Message, 100), Link: link(t),
		Data: withData(baseData(t), "sender_name", sender), CreatedBy: &a.UserID}); err != nil {
		return err
	}
	to, err := s.emails(ctx, tx, q, ids, mail.EventTicketComment)
	if err != nil {
		return err
	}
	return s.sendMail(ctx, tx, a, t, to, title, mail.Ticket{Type: "reply", Content: preview(msg.Message, maxText),
		SenderName: sender, ActionText: "Phản hồi Ticket #" + t.Code})
}

func (s *Service) notifyStatus(ctx context.Context, tx pgx.Tx, q *ticketsdb.Queries, a *iam.Access, t ticketsdb.OpsTicket) error {
	var ids []uuid.UUID
	if t.CreatorID != a.UserID {
		ids = append(ids, t.CreatorID)
	}
	if t.AssigneeID != nil && *t.AssigneeID != a.UserID {
		ids = append(ids, *t.AssigneeID)
	}
	actor, err := q.UserName(ctx, a.UserID)
	if err != nil {
		return err
	}
	label := StatusLabel(t.Status)
	title := "Re: [#" + t.Code + "] [" + label + "] " + t.Title
	if _, err := notify.Send(ctx, tx, ids, notify.Notification{Type: "ticket_status", Title: title,
		Body: actor + " đã cập nhật sang " + label + ".", Link: link(t),
		Data: withData(baseData(t), "actor_name", actor), CreatedBy: &a.UserID}); err != nil {
		return err
	}
	to, err := s.emails(ctx, tx, q, ids, mail.EventTicketStatus)
	if err != nil {
		return err
	}
	return s.sendMail(ctx, tx, a, t, to, title, mail.Ticket{Type: "status",
		Content: actor + " đã cập nhật trạng thái thành: " + label, SenderName: actor, ActionText: "Xem Ticket #" + t.Code})
}

func (s *Service) notifyAssigned(ctx context.Context, tx pgx.Tx, q *ticketsdb.Queries, a *iam.Access, t ticketsdb.OpsTicket, assignee uuid.UUID) error {
	if assignee == a.UserID {
		return nil
	}
	actor, err := q.UserName(ctx, a.UserID)
	if err != nil {
		return err
	}
	title := "[#" + t.Code + "] " + t.Title
	if _, err := notify.Send(ctx, tx, []uuid.UUID{assignee}, notify.Notification{Type: "ticket_assigned", Title: title,
		Body: actor + " đã phân công xử lý cho bạn.", Link: link(t),
		Data: withData(baseData(t), "actor_name", actor), CreatedBy: &a.UserID}); err != nil {
		return err
	}
	to, err := s.emails(ctx, tx, q, []uuid.UUID{assignee}, "")
	if err != nil {
		return err
	}
	// Laravel gửi thư chữ thuần; v2 dùng mẫu ticket (có nút mở ticket, link tuyệt đối theo PUBLIC_URL).
	return s.sendMail(ctx, tx, a, t, to, "[MEnglish Phân công] Ticket #"+t.Code+": "+t.Title, mail.Ticket{Type: "new",
		Content: "Bạn vừa được phân công xử lý ticket này. Người phân công: " + actor + ".", SenderName: actor,
		ActionText: "Xem và giải quyết Ticket #" + t.Code})
}
