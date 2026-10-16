package crm

import (
	"context"
	"net/http"
	"slices"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/crm/crmdb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

// Chốt & Xếp lớp (Laravel processClosingWizard / assignClass), phần thuộc CRM: kiểm tra khách, gọi phân hệ học viên
// tạo hồ sơ / học phí / ghi danh, rồi ghi chốt lên khách, chuyển giai đoạn và tăng lượt dùng ưu đãi. Học phí, phiếu thu,
// tài khoản cổng học viên, task nhắc thu do phân hệ học viên / tài chính làm qua StudentCreator.

// LeadSnapshot: dữ liệu khách chuyển sang hồ sơ học viên.
type LeadSnapshot struct {
	ID          uuid.UUID
	Code        string
	Name        string
	Phone       string
	ParentName  *string
	ParentPhone *string
	Email       *string
	Dob         *time.Time
	Gender      *string
	Address     *string
	School      *string
	BranchID    *uuid.UUID
	AssignedTo  *uuid.UUID
}

// CloseInput: form Chốt & Xếp lớp.
type CloseInput struct {
	ClassID          *uuid.UUID `json:"class_id"`
	CourseID         *uuid.UUID `json:"course_id"`
	PromotionID      *uuid.UUID `json:"promotion_id"`
	FeePaidAtClosing bool       `json:"fee_paid_at_closing"`
	PaidAmount       int64      `json:"paid_amount"`
	PrepaidAmount    int64      `json:"prepaid_amount"`
	PaymentMethod    *string    `json:"payment_method"`
	StudentCode      *string    `json:"student_code"`
	BillNotes        *string    `json:"bill_notes"`
}

// ClosedStudent: kết quả phân hệ học viên trả về sau khi tạo hồ sơ.
type ClosedStudent struct {
	StudentID     uuid.UUID
	StudentCode   string
	StudentName   string
	ContractTotal int64       // tổng giá trị hợp đồng (học phí sau ưu đãi + thu khác)
	ClassName     *string     // có lớp → Đã chốt; không → Chờ xếp lớp
	CourseName    string      // khóa đã chốt (xếp lớp sau)
	CourseID      *uuid.UUID  // khóa chờ xếp lớp
	BranchID      *uuid.UUID  // cơ sở của lớp / khóa
	Enrollment    *Enrollment // lượt ghi danh khi có lớp
	Message       string      // phần thông báo của phân hệ học viên (vd "Khoản thu đang chờ Kế toán/Admin duyệt.")
}

// StudentCreator: phân hệ học viên / lớp học / học phí. Chạy trong cùng giao dịch với CRM.
type StudentCreator interface {
	// CreateFromLead tạo hồ sơ học viên, học phí (kèm ưu đãi), phiếu thu và ghi danh lớp (nếu chọn lớp).
	CreateFromLead(ctx context.Context, tx pgx.Tx, actor *iam.Access, lead LeadSnapshot, in CloseInput) (*ClosedStudent, error)
	// EnrollWaiting xếp lớp cho học viên đang Chờ xếp lớp.
	EnrollWaiting(ctx context.Context, tx pgx.Tx, actor *iam.Access, lead LeadSnapshot, studentID, classID uuid.UUID) (*Enrollment, error)
}

var errStudentsNotReady = &iam.Error{Status: http.StatusNotImplemented, Code: "students_not_ready",
	Message: "Chốt khách / xếp lớp cần phân hệ học viên, lớp học và học phí của hệ thống mới (chưa gộp)."}

func snapshot(l crmdb.CrmLead) LeadSnapshot {
	return LeadSnapshot{
		ID: l.ID, Code: l.Code, Name: l.Name, Phone: l.Phone, ParentName: l.ParentName, ParentPhone: l.ParentPhone,
		Email: l.Email, Dob: l.Dob, Gender: l.Gender, Address: l.Address, School: l.School, BranchID: l.BranchID,
		AssignedTo: l.AssignedUserID,
	}
}

// Close: chốt khách.
func (s *Service) Close(ctx context.Context, a *iam.Access, idOrCode string, in CloseInput) (*Saved, error) {
	if in.ClassID == nil && in.CourseID == nil {
		return nil, iam.Invalid("Chọn khóa học khi xếp lớp sau để tính học phí.")
	}
	if in.PaidAmount < 0 || in.PrepaidAmount < 0 {
		return nil, iam.Invalid("Số tiền thu không hợp lệ.")
	}
	if in.FeePaidAtClosing && in.PaidAmount+in.PrepaidAmount <= 0 {
		return nil, iam.Invalid(`Đã tích "Đã đóng học phí đăng ký" thì phải nhập số tiền đã thu.`)
	}
	if !in.FeePaidAtClosing && in.PaidAmount > 0 {
		return nil, iam.Invalid("Chưa đóng học phí thì không ghi nhận khoản thu; hệ thống sẽ tạo task nhắc thu.")
	}
	if in.PaidAmount+in.PrepaidAmount > 0 && (in.PaymentMethod == nil || !slices.Contains([]string{"cash", "transfer"}, *in.PaymentMethod)) {
		return nil, iam.Invalid("Vui lòng chọn phương thức thanh toán.")
	}
	if s.students == nil {
		// TODO(merge): gọi students.Service.Create (tạo hồ sơ học viên, tài khoản, học phí, phiếu thu, ghi danh).
		return nil, errStudentsNotReady
	}
	var out *Saved
	err := pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error {
		q := s.q.WithTx(tx)
		gl, err := s.lead(ctx, q, a, idOrCode)
		if err != nil {
			return err
		}
		l, err := lockLead(ctx, q, gl.ID)
		if err != nil {
			return err
		}
		if l.ConvertedStudentID != nil {
			out = &Saved{ID: l.ID, Message: "Lead này đã được chốt trước đó; hệ thống không tạo dữ liệu trùng."}
			return nil
		}
		if !slices.Contains(ClosableStages, l.Stage) {
			return iam.Invalid("Chỉ chốt được Lead ở bước Đang tư vấn, Đã test hoặc Gửi kết quả.")
		}
		var promo *crmdb.CrmPromotion
		if in.PromotionID != nil {
			p, err := q.LockPromotion(ctx, *in.PromotionID)
			if err != nil {
				return iam.Invalid("Ưu đãi không hợp lệ.")
			}
			if !promotionApplicable(p, l.BranchID, in.CourseID, s.now()) && !(p.IsSpecial && p.UsedCount == 0) {
				return iam.Invalid("Ưu đãi đã hết hiệu lực hoặc không áp dụng cho cơ sở / khóa này.")
			}
			promo = &p
		}
		st, err := s.students.CreateFromLead(ctx, tx, a, snapshot(l), in)
		if err != nil {
			return err
		}
		enrolled := st.ClassName != nil
		if err := q.MarkLeadConverted(ctx, crmdb.MarkLeadConvertedParams{
			ID: l.ID, DealValue: st.ContractTotal, ConvertedStudentID: &st.StudentID, ConvertedBy: &a.UserID,
			FeePaidAtClosing: in.FeePaidAtClosing, Enrolled: enrolled, WaitingCourseID: st.CourseID, WaitingBranchID: st.BranchID,
			Today: startOfDay(s.now()),
		}); err != nil {
			return err
		}
		if promo != nil {
			if err := q.UsePromotion(ctx, promo.ID); err != nil {
				return err
			}
		}
		target, where := StageWaitingClass, "khóa "+st.CourseName+", xếp lớp sau"
		if enrolled {
			target, where = StageWon, "lớp "+*st.ClassName
		}
		fee := ", chưa đóng học phí"
		if in.FeePaidAtClosing {
			fee = ", đã đóng học phí đăng ký"
		}
		if _, err := s.AdvanceTo(ctx, q, l.ID, target, &a.UserID,
			"Chốt hợp đồng qua Chốt & Xếp lớp (Tổng giá trị: "+formatMoney(st.ContractTotal)+", "+where+fee+")."); err != nil {
			return err
		}
		if st.Enrollment != nil {
			e := *st.Enrollment
			e.LeadID = l.ID
			if _, err := s.RecordEnrollment(ctx, q, e); err != nil {
				return err
			}
		}
		msg := "Đã chốt deal và tạo hồ sơ " + st.StudentCode + "."
		if !enrolled {
			msg += " Học viên đang ở danh sách Chờ xếp lớp."
		}
		if st.Message != "" {
			msg += " " + st.Message
		}
		out = &Saved{ID: l.ID, Message: msg}
		return nil
	})
	if err != nil {
		return nil, err
	}
	s.log.Info("crm: chốt khách", "lead", out.ID, "by", a.UserID)
	return out, nil
}

// AssignClass: xếp lớp cho khách Chờ xếp lớp → Đã chốt.
func (s *Service) AssignClass(ctx context.Context, a *iam.Access, idOrCode string, classID *uuid.UUID) (*Saved, error) {
	if classID == nil {
		return nil, iam.Invalid("Vui lòng chọn lớp.")
	}
	if s.students == nil {
		// TODO(merge): gọi phân hệ lớp học xếp lớp (ghi danh) cho học viên.
		return nil, errStudentsNotReady
	}
	var out *Saved
	err := pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error {
		q := s.q.WithTx(tx)
		gl, err := s.lead(ctx, q, a, idOrCode)
		if err != nil {
			return err
		}
		l, err := lockLead(ctx, q, gl.ID)
		if err != nil {
			return err
		}
		if l.Stage != StageWaitingClass || l.ConvertedStudentID == nil {
			return iam.Invalid("Chỉ xếp lớp cho học viên đang Chờ xếp lớp.")
		}
		e, err := s.students.EnrollWaiting(ctx, tx, a, snapshot(l), *l.ConvertedStudentID, *classID)
		if err != nil {
			return err
		}
		e.LeadID = l.ID
		if _, err := s.RecordEnrollment(ctx, q, *e); err != nil {
			return err
		}
		if err := q.SetLeadStage(ctx, crmdb.SetLeadStageParams{ID: l.ID, Stage: l.Stage, LostMode: "keep", ClearWaiting: true}); err != nil {
			return err
		}
		if _, err := s.AdvanceTo(ctx, q, l.ID, StageWon, &a.UserID, "Học vụ xếp lớp "+e.ClassName+" cho học viên "+e.StudentCode+"."); err != nil {
			return err
		}
		out = &Saved{ID: l.ID, Message: "Đã xếp lớp cho học viên và chuyển Lead sang Đã chốt."}
		return nil
	})
	if err != nil {
		return nil, err
	}
	s.log.Info("crm: xếp lớp khách chờ", "lead", out.ID, "by", a.UserID)
	return out, nil
}

// TestUpdate: phân hệ test đầu vào (placement) ghi lịch hẹn / đề / người chấm / điểm lên khách.
type TestUpdate struct {
	AppointmentAt   *time.Time
	AppointmentType *string
	AssignedTestID  *uuid.UUID
	ExaminerID      *uuid.UUID
	TestScore       *string
	TestDecision    *string
}

// SetLeadTest: ghi thông tin test đầu vào (gọi trong giao dịch của phân hệ placement; q nil = ngoài giao dịch).
func (s *Service) SetLeadTest(ctx context.Context, q *crmdb.Queries, leadID uuid.UUID, t TestUpdate) error {
	if q == nil {
		q = s.q
	}
	return q.SetLeadTest(ctx, crmdb.SetLeadTestParams{
		ID: leadID, AppointmentAt: t.AppointmentAt, AppointmentType: t.AppointmentType, AssignedTestID: t.AssignedTestID,
		ExaminerID: t.ExaminerID, TestScore: t.TestScore, TestDecision: t.TestDecision,
	})
}

// Queries: truy vấn CRM gắn với giao dịch của phân hệ khác (dùng cho AdvanceTo / SetLeadTest / RecordEnrollment).
func (s *Service) Queries(tx pgx.Tx) *crmdb.Queries { return s.q.WithTx(tx) }
