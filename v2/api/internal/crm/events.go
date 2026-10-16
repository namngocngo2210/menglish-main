package crm

import (
	"context"
	"encoding/json"
	"slices"
	"strings"
	"time"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/crm/crmdb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

// NoteInput: ghi nhật ký chăm sóc (gọi / nhắn / gặp / test / ghi chú / gửi kết quả).
type NoteInput struct {
	Type           string  `json:"type"`
	Content        *string `json:"content"`
	Outcome        *string `json:"outcome"`           // reached | failed (liên hệ)
	SentAt         *string `json:"sent_at"`           // gửi kết quả: lúc gửi cho phụ huynh
	NextFollowUpAt *string `json:"next_follow_up_at"` // hẹn liên hệ tiếp
}

var noteTypes = []string{"call", "message", "meet", "test", "note", "result"}

// AddNote (Laravel addNote): liên hệ được lần đầu → khách Mới tự sang Đang tư vấn; liên hệ được sau lịch hẹn → bỏ lịch hẹn.
func (s *Service) AddNote(ctx context.Context, a *iam.Access, idOrCode string, in NoteInput) (*Saved, error) {
	if !slices.Contains(noteTypes, in.Type) {
		return nil, iam.Invalid("Loại nhật ký không hợp lệ.")
	}
	content := strings.TrimSpace(deref(in.Content))
	if content == "" && in.Type != "result" {
		return nil, iam.Invalid("Vui lòng nhập nội dung ghi chú.")
	}
	if runeLen(content) > 1000 {
		return nil, iam.Invalid("Nội dung ghi chú không được vượt quá 1000 ký tự.")
	}
	outcome := strings.TrimSpace(deref(in.Outcome))
	if outcome != "" && outcome != "reached" && outcome != "failed" {
		return nil, iam.Invalid("Kết quả liên hệ không hợp lệ.")
	}
	now := s.now()
	if in.Type == "result" {
		if strings.TrimSpace(deref(in.SentAt)) == "" {
			return nil, iam.Invalid("Vui lòng chọn ngày gửi kết quả cho phụ huynh.")
		}
		sent, ok := parseDateTime(*in.SentAt)
		if !ok {
			return nil, iam.Invalid("Ngày gửi kết quả không hợp lệ.")
		}
		if sent.After(now) {
			return nil, iam.Invalid("Ngày gửi kết quả không được ở tương lai.")
		}
		text := "Đã gửi kết quả test cho phụ huynh lúc " + fmtDateTime(sent) + "."
		if content != "" {
			text += "\nPhản hồi của phụ huynh: " + content
		}
		content = text
	}
	var followUp *time.Time
	if strings.TrimSpace(deref(in.NextFollowUpAt)) != "" {
		t, ok := parseDateTime(*in.NextFollowUpAt)
		if !ok {
			return nil, iam.Invalid("Hẹn liên hệ tiếp không hợp lệ.")
		}
		if !t.After(now) {
			return nil, iam.Invalid("Hẹn liên hệ tiếp phải sau thời điểm hiện tại.")
		}
		followUp = &t
	}
	isContact := slices.Contains(contactTypes, in.Type)
	var outcomePtr *string
	if isContact {
		if outcome == "" {
			outcome = "reached"
		}
		outcomePtr = &outcome
	}
	reached := isContact && outcome == "reached"
	var out *Saved
	err := s.tx(ctx, func(q *crmdb.Queries) error {
		l, err := s.lead(ctx, q, a, idOrCode)
		if err != nil {
			return err
		}
		locked, err := lockLead(ctx, q, l.ID)
		if err != nil {
			return err
		}
		if _, err := q.InsertEvent(ctx, crmdb.InsertEventParams{
			LeadID: l.ID, UserID: &a.UserID, Type: in.Type, Outcome: outcomePtr, Content: content,
		}); err != nil {
			return err
		}
		switch {
		case followUp != nil:
			err = q.SetFollowUp(ctx, crmdb.SetFollowUpParams{ID: l.ID, NextFollowUpAt: followUp})
		case reached && locked.NextFollowUpAt != nil && !locked.NextFollowUpAt.After(now):
			// Liên hệ được sau lịch hẹn → lịch hẹn đã xong, khách không còn "Quá hạn" / "Cần gọi lại".
			err = q.SetFollowUp(ctx, crmdb.SetFollowUpParams{ID: l.ID})
		}
		if err != nil {
			return err
		}
		if isContact && !reached {
			streak, err := q.ContactStreak(ctx, l.ID)
			if err != nil {
				return err
			}
			n := 0
			for _, o := range streak {
				if deref(o) != "failed" {
					break
				}
				n++
			}
			if n >= 3 {
				// TODO(notify): báo người phụ trách + quản lý "Khách {tên} đã liên hệ thất bại {n} lần liên tiếp"
				// (Laravel CrmSlaService::checkFailedContacts, SLA crm.failed_contacts).
				s.log.Info("crm: liên hệ thất bại liên tiếp", "lead", l.ID, "count", n)
			}
		}
		advanced := false
		if reached {
			if advanced, err = s.AdvanceTo(ctx, q, l.ID, StageConsulting, &a.UserID,
				"Liên hệ được lần đầu ("+strings.ToLower(eventTypeLabel(in.Type))+")."); err != nil {
				return err
			}
		}
		msg := "Đã lưu nhật ký chăm sóc thành công!"
		if advanced {
			msg = "Đã lưu nhật ký. Khách chuyển sang Đang tư vấn."
		}
		out = &Saved{ID: l.ID, Message: msg}
		return nil
	})
	if err != nil {
		return nil, err
	}
	s.log.Info("crm: ghi nhật ký", "lead", out.ID, "type", in.Type, "by", a.UserID)
	return out, nil
}

// CareInput: checklist chăm sóc tháng đầu.
type CareInput struct {
	Items []string `json:"items"`
	Note  *string  `json:"note"`
}

func (s *Service) UpdateCare(ctx context.Context, a *iam.Access, idOrCode string, in CareInput) (*Saved, error) {
	note := strings.TrimSpace(deref(in.Note))
	if runeLen(note) > 1000 {
		return nil, iam.Invalid("Ghi chú không được vượt quá 1000 ký tự.")
	}
	for _, it := range in.Items {
		if !slices.ContainsFunc(careChecklistItems, func(c struct{ Key, Label string }) bool { return c.Key == it }) {
			return nil, iam.Invalid("Mốc chăm sóc không hợp lệ.")
		}
	}
	var out *Saved
	err := s.tx(ctx, func(q *crmdb.Queries) error {
		l, err := s.lead(ctx, q, a, idOrCode)
		if err != nil {
			return err
		}
		locked, err := lockLead(ctx, q, l.ID)
		if err != nil {
			return err
		}
		if locked.ConvertedStudentID == nil {
			return iam.Invalid("Checklist chăm sóc tháng đầu chỉ áp dụng cho khách đã chốt.")
		}
		name, err := s.userName(ctx, q, &a.UserID)
		if err != nil {
			return err
		}
		actorName := deref(name)
		var prev careState
		if len(locked.CareChecklist) > 0 {
			_ = json.Unmarshal(locked.CareChecklist, &prev)
		}
		state := careState{}
		var done, undone []string
		for _, it := range careChecklistItems {
			old := prev[it.Key]
			if slices.Contains(in.Items, it.Key) {
				if old == nil {
					old = &struct {
						DoneAt string `json:"done_at"`
						By     string `json:"by"`
					}{DoneAt: s.now().Format(time.RFC3339), By: actorName}
					done = append(done, it.Label)
				}
				state[it.Key] = old
			} else {
				state[it.Key] = nil
				if old != nil {
					undone = append(undone, it.Label)
				}
			}
		}
		data, err := json.Marshal(state)
		if err != nil {
			return err
		}
		if err := q.SetCareChecklist(ctx, crmdb.SetCareChecklistParams{ID: l.ID, CareChecklist: data}); err != nil {
			return err
		}
		if len(done) > 0 || len(undone) > 0 || note != "" {
			content := "Cập nhật chăm sóc tháng đầu"
			if len(done) > 0 {
				content += ". Hoàn thành: " + strings.Join(done, "; ")
			}
			if len(undone) > 0 {
				content += ". Bỏ đánh dấu: " + strings.Join(undone, "; ")
			}
			if note != "" {
				content += ". Ghi chú: " + note
			} else {
				content += "."
			}
			if _, err := q.InsertEvent(ctx, crmdb.InsertEventParams{LeadID: l.ID, UserID: &a.UserID, Type: "care", Content: content}); err != nil {
				return err
			}
		}
		out = &Saved{ID: l.ID, Message: "Đã lưu checklist chăm sóc tháng đầu."}
		return nil
	})
	if err != nil {
		return nil, err
	}
	s.log.Info("crm: chăm sóc tháng đầu", "lead", out.ID, "by", a.UserID)
	return out, nil
}
