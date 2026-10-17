package placement

import (
	"context"
	"errors"
	"strings"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/notify"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/notify/mail"
)

// Xác nhận lịch hẹn cho phụ huynh (Laravel App\Services\Crm\AppointmentConfirmation): ngay khi Học vụ xếp lịch,
// khách có email thật → xếp email xác nhận (ParentNotice); chưa có email → nhắc người phụ trách khách (thông báo
// appointment_confirm_manual kèm nội dung để sao chép gửi Zalo) và trả nội dung cho màn hình hiện popup.
// Khác Laravel: email đi qua hàng đợi sau khi lưu; gửi không được (hết lượt thử, khoảng 3 phút) thì vòng gửi tự nhắc
// người phụ trách như trường hợp chưa có email.

const confirmNotifyType = "appointment_confirm_manual"

// confirmMaxAttempts: thư xác nhận chỉ thử 3 lần (1, 2 phút) rồi chuyển người phụ trách gửi Zalo.
const confirmMaxAttempts = 3

// Confirmation: kết quả gửi xác nhận (appointment_confirmation của Laravel).
type Confirmation struct {
	// Email đã xếp gửi; nil = chưa gửi được qua email (hiện popup sao chép gửi Zalo).
	Emailed *string `json:"emailed"`
	// Laravel: gửi email lỗi ngay lúc xác nhận. v2 gửi sau qua hàng đợi nên luôn false ở đây.
	EmailFailed bool   `json:"email_failed"`
	Text        string `json:"text"`
	Phone       string `json:"phone"`
}

type confirmMessage struct {
	subject, text, link string
}

type branchInfo struct {
	name, address, phone string
}

func (s *Service) branchInfo(ctx context.Context, db pgx.Tx, id *uuid.UUID) (*branchInfo, error) {
	if id == nil {
		return nil, nil
	}
	var b branchInfo
	err := db.QueryRow(ctx, `SELECT name, coalesce(address, ''), coalesce(phone, '') FROM core.branches WHERE id = $1`, *id).
		Scan(&b.name, &b.address, &b.phone)
	if errors.Is(err, pgx.ErrNoRows) {
		return nil, nil
	}
	return &b, err
}

func place(b *branchInfo) string {
	if b == nil {
		return "trung tâm MEnglish"
	}
	out := "cơ sở " + b.name
	if b.address != "" {
		out += " (" + b.address + ")"
	}
	return out
}

func wrapConfirmation(intro string, lines []string, b *branchInfo) string {
	contact := ""
	if b != nil && b.phone != "" {
		contact = " qua số " + b.phone
	}
	return "Kính gửi Quý phụ huynh,\n" + intro + "\n" + strings.Join(lines, "\n") +
		"\nNếu cần đổi lịch, phụ huynh vui lòng báo lại trung tâm" + contact + ". Cảm ơn Quý phụ huynh!"
}

// trialConfirmation: nội dung xác nhận lịch học thử (AppointmentConfirmation::forTrial).
func trialConfirmation(lead *Lead, sess *Session, b *branchInfo) confirmMessage {
	name := "học viên"
	if lead != nil && lead.Name != "" {
		name = lead.Name
	}
	when := sess.Start
	if sess.End != "" {
		when += "–" + sess.End
	}
	class := sess.ClassName
	if class == "" {
		class = "—"
	}
	return confirmMessage{
		subject: "Xác nhận lịch học thử — " + name,
		text: wrapConfirmation("MEnglish xác nhận lịch học thử của "+name+":", []string{
			"• Thời gian: " + when + ", " + mail.DayLabel(sess.Date),
			"• Lớp: " + class,
			"• Địa điểm: " + place(b),
		}, b),
	}
}

// deliverConfirmation (AppointmentConfirmation::deliver) chạy trong transaction của thao tác xếp lịch: xếp email hoặc
// nhắc người phụ trách. Trả kết quả cho màn hình và nội dung nhật ký ghi sang hồ sơ khách.
func (s *Service) deliverConfirmation(ctx context.Context, tx pgx.Tx, a *iam.Access, lead *Lead, msg confirmMessage,
	refType string, refID uuid.UUID) (*Confirmation, string, error) {
	phone := lead.ParentPhone
	if phone == "" {
		phone = lead.Phone
	}
	owner := a.UserID
	if lead.AssignedUserID != nil {
		owner = *lead.AssignedUserID
	}
	link := "/crm/customers/" + lead.ID.String()
	manual := func(reason string) notify.Notification {
		body := reason + ". Sao chép nội dung xác nhận và gửi phụ huynh qua Zalo"
		if phone != "" {
			body += " (" + phone + ")"
		}
		return notify.Notification{
			Type: confirmNotifyType, Title: "Gửi xác nhận lịch hẹn qua Zalo: " + lead.Name, Body: body + ".", Link: link,
			Data:      map[string]any{"copy_text": msg.text, "customer_id": lead.ID, "customer_name": lead.Name, "customer_phone": phone},
			CreatedBy: &a.UserID,
		}
	}
	out := &Confirmation{Text: msg.text, Phone: phone}
	if email := mail.ParentRecipient(lead.Email); email != "" {
		actionText := ""
		if msg.link != "" {
			actionText = "Vào làm bài test"
		}
		m := mail.ParentNotice(email, msg.subject, msg.text, msg.link, actionText)
		m.ToName, m.MaxAttempts, m.RefType, m.RefID, m.CreatedBy = lead.Name, confirmMaxAttempts, refType, refID.String(), &a.UserID
		m.Fallback = &mail.Fallback{UserIDs: []uuid.UUID{owner}, Notification: manual("Gửi email tới " + email + " không thành công")}
		if _, err := mail.Enqueue(ctx, tx, m); err != nil {
			return nil, "", err
		}
		out.Emailed = &email
		return out, "Đã gửi email xác nhận lịch hẹn tới " + email + ".", nil
	}
	if _, err := notify.Send(ctx, tx, []uuid.UUID{owner}, manual("Khách chưa có email")); err != nil {
		return nil, "", err
	}
	return out, "Khách chưa có email: đã nhắc người phụ trách gửi xác nhận lịch hẹn cho phụ huynh qua Zalo.", nil
}
