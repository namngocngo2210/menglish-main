package students

import (
	"context"
	"fmt"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/notify"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/students/studentsdb"
)

// Nhật ký vận hành (Student nằm trong Audit::MODELS của Laravel: log "Học viên & Lớp học") và thông báo kết thúc
// bảo lưu (StudentDeferralService::notifyAcademicStaff).

func init() {
	audit.RegisterSubject("student", "Học viên")
	notify.RegisterType("deferral_ended", notify.TypeInfo{Label: "Kết thúc bảo lưu", Icon: "event_available", Tone: notify.ToneInfo})
}

func studentLabel(st studentsdb.AcademicStudent) string {
	return fmt.Sprintf("%s (%s)", st.FullName, st.Code)
}

func studentSnapshot(st studentsdb.AcademicStudent) map[string]any {
	return map[string]any{
		"full_name": st.FullName, "phone": st.Phone, "email": st.Email, "dob": st.Dob, "gender": st.Gender,
		"target": st.Target, "address": st.Address, "school": st.School, "parent_name": st.ParentName,
		"parent_phone": st.ParentPhone, "study_started_on": st.StudyStartedOn, "notes": st.Notes,
	}
}

func recordStudent(ctx context.Context, tx pgx.Tx, actor *iam.Access, st studentsdb.AcademicStudent, event, action, desc string, changes audit.Changes) error {
	return audit.Record(ctx, tx, audit.Entry{
		ActorID: &actor.UserID, Module: audit.ModuleStudents, Event: event, Action: action,
		SubjectType: "student", SubjectID: st.ID.String(), SubjectLabel: studentLabel(st), BranchID: st.BranchID,
		Description: desc, Changes: changes,
	})
}

// deferralRecipients: Học vụ cùng chi nhánh với học viên; không có thì Quản lý cơ sở; vẫn không có thì Admin.
func deferralRecipients(ctx context.Context, tx pgx.Tx, branch *uuid.UUID) ([]uuid.UUID, error) {
	if branch != nil {
		for _, role := range []string{iam.RoleAcademicStaff, iam.RoleManager} {
			ids, err := iam.UserIDsWithRole(ctx, tx, role, branch)
			if err != nil || len(ids) > 0 {
				return ids, err
			}
		}
	}
	return iam.AdminIDs(ctx, tx)
}

// notifyDeferralEnded báo kết thúc bảo lưu (sớm bằng tay, hoặc hết thời gian khi by = nil) cho người sắp xếp lớp, trừ
// người thao tác.
func notifyDeferralEnded(ctx context.Context, tx pgx.Tx, by *uuid.UUID, st studentsdb.AcademicStudent, next string) error {
	ids, err := deferralRecipients(ctx, tx, st.BranchID)
	if err != nil {
		return err
	}
	why := "Bảo lưu được kết thúc sớm"
	if by == nil {
		why = "Hết thời gian bảo lưu"
	}
	_, err = notify.Send(ctx, tx, ids, notify.Notification{
		Type:  "deferral_ended",
		Title: fmt.Sprintf("Học viên %s kết thúc bảo lưu", st.FullName),
		Body: fmt.Sprintf("%s — học viên %s (%s) đã chuyển sang \"%s\". Vui lòng sắp xếp lớp / lịch học lại.",
			why, st.FullName, st.Code, StatusLabel(next)),
		Link: "/students/" + st.Code, Data: map[string]any{"student_id": st.ID},
		CreatedBy: by, Except: by,
	})
	return err
}
