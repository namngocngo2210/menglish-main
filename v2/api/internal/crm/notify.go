package crm

import (
	"context"
	"strconv"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/crm/crmdb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/notify"
)

// Nhật ký vận hành (CrmCustomer nằm trong Audit::MODELS: log "CRM & Leads", nhãn "khách hàng") và thông báo của CRM.
// Ghi trong giao dịch của thao tác qua q.DB().

func init() {
	audit.RegisterSubject("lead", "Khách hàng")
	notify.RegisterType("lead_assigned", notify.TypeInfo{Label: "Được giao khách", Icon: "person_add", Tone: notify.TonePrimary})
	notify.RegisterType("crm_transfer_pending", notify.TypeInfo{Label: "Chuyển cơ sở chờ duyệt", Icon: "swap_horiz", Tone: notify.ToneWarning})
}

// failedContactsThreshold: số lần liên hệ thất bại liên tiếp thì cảnh báo (mặc định SLA crm.failed_contacts).
const failedContactsThreshold = 3

func leadLink(id uuid.UUID) string { return "/crm/customers/" + id.String() }

func recordLead(ctx context.Context, q *crmdb.Queries, a *iam.Access, id uuid.UUID, code, name string, branch *uuid.UUID,
	event, action, desc string, changes audit.Changes) error {
	return audit.Record(ctx, q.DB(), audit.Entry{
		ActorID: &a.UserID, Module: audit.ModuleCRM, Event: event, Action: action, SubjectType: "lead", SubjectID: id.String(),
		SubjectLabel: name + " (" + ShortCode(code) + ")", BranchID: branch, Description: desc, Changes: changes,
	})
}

// leadAuditChanges: lịch sử "Sửa thông tin" (giá trị hiển thị) thành trước / sau của nhật ký.
func leadAuditChanges(keys []string, changes map[string]Change) audit.Changes {
	out := audit.Changes{}
	for _, k := range keys {
		c := changes[k]
		out[k] = audit.Change{Old: c.Old, New: c.New}
	}
	return out
}

// notifyNewOwner: báo người phụ trách mới (không báo khi tự nhận khách).
func notifyNewOwner(ctx context.Context, q *crmdb.Queries, actor uuid.UUID, l crmdb.CrmLead, owner uuid.UUID) error {
	_, err := notify.Send(ctx, q.DB(), []uuid.UUID{owner}, notify.Notification{
		Type: "lead_assigned", Title: "Bạn được giao phụ trách khách " + l.Name,
		Body: ShortCode(l.Code) + " · " + l.Phone, Link: leadLink(l.ID),
		Data:      map[string]any{"customer_id": l.ID, "customer_name": l.Name, "customer_phone": l.Phone},
		CreatedBy: &actor, Except: &actor,
	})
	return err
}

// notifyTransferPending: báo người duyệt chuyển cơ sở (lead.approve_transfer) có yêu cầu mới (Laravel hiện ở hộp
// Việc cần duyệt — "Chuyển cơ sở (CRM)").
func notifyTransferPending(ctx context.Context, q *crmdb.Queries, a *iam.Access, l crmdb.CrmLead, content string) error {
	ids, err := iam.UserIDsWithPermission(ctx, q.DB(), "lead.approve_transfer", nil)
	if err != nil {
		return err
	}
	_, err = notify.Send(ctx, q.DB(), ids, notify.Notification{
		Type: "crm_transfer_pending", Title: "Chuyển cơ sở chờ duyệt: " + l.Name + " (" + ShortCode(l.Code) + ")",
		Body: content, Link: "/crm/transfers", Data: map[string]any{"customer_id": l.ID, "customer_name": l.Name},
		CreatedBy: &a.UserID, Except: &a.UserID,
	})
	return err
}

// notifyFailedContacts: liên hệ thất bại liên tiếp chạm ngưỡng → báo người phụ trách + Admin một lần cho mỗi chuỗi
// (CrmSlaService::checkFailedContacts, loại sla_breach).
func notifyFailedContacts(ctx context.Context, q *crmdb.Queries, a *iam.Access, l crmdb.CrmLead, n int) error {
	ids, err := iam.AdminIDs(ctx, q.DB())
	if err != nil {
		return err
	}
	owner := "chưa phân công"
	if l.AssignedUserID != nil {
		ids = append(ids, *l.AssignedUserID)
		u, err := q.GetUserBrief(ctx, *l.AssignedUserID)
		if err != nil {
			return err
		}
		owner = u.FullName
	}
	_, err = notify.Send(ctx, q.DB(), ids, notify.Notification{
		Type:  "sla_breach",
		Title: "Cảnh báo: " + ShortCode(l.Code) + " liên hệ thất bại " + strconv.Itoa(n) + " lần liên tiếp",
		Body:  l.Name + " (" + l.Phone + ") — người phụ trách " + owner + ".", Link: leadLink(l.ID),
		Data:      map[string]any{"sla_rule": "crm.failed_contacts", "customer_id": l.ID, "customer_name": l.Name},
		CreatedBy: &a.UserID,
	})
	return err
}
