package crm

import (
	"context"
	"errors"
	"net/http"
	"slices"

	"github.com/go-chi/chi/v5"
	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

// Lựa chọn khi Chốt & Xếp lớp / Gán lớp (CrmController::closingWizard, waitingClassData): lớp nhận ghi danh cùng cơ sở
// còn chỗ, khóa học đang mở, ưu đãi trong danh mục; lớp gợi ý cho khách Chờ xếp lớp (đúng khóa đã chốt hoặc cùng cấp độ,
// đúng cơ sở, còn chỗ; lớp đúng khóa lên đầu). Lớp / khóa do phân hệ Lớp học trả (StudentCreator cài thêm ClosingChoiceSource).

type ClassChoice struct {
	ID       uuid.UUID  `json:"id"`
	Label    string     `json:"label"`
	CourseID *uuid.UUID `json:"course_id"`
}

type CourseChoice struct {
	ID    uuid.UUID `json:"id"`
	Label string    `json:"label"`
	Fee   int64     `json:"fee"`
}

type ClosingChoices struct {
	Classes    []ClassChoice  `json:"classes"`
	Courses    []CourseChoice `json:"courses"`
	Promotions []Option       `json:"promotions"`
}

type ClosingChoiceSource interface {
	// ClosingClasses: lớp nhận ghi danh (đang học / sắp khai giảng chưa tới ngày) cùng cơ sở với khách, còn chỗ.
	ClosingClasses(ctx context.Context, l LeadSnapshot) ([]ClassChoice, error)
	ClosingCourses(ctx context.Context) ([]CourseChoice, error)
	// WaitingClasses: lớp xếp được cho khách Chờ xếp lớp (hồ sơ học viên studentID).
	WaitingClasses(ctx context.Context, l LeadSnapshot, studentID uuid.UUID) ([]ClassChoice, error)
}

func (s *Service) choiceSource() ClosingChoiceSource {
	src, _ := s.students.(ClosingChoiceSource)
	return src
}

func (s *Service) leadSnapshot(ctx context.Context, a *iam.Access, idOrCode string) (LeadSnapshot, *uuid.UUID, string, error) {
	gl, err := s.lead(ctx, s.q, a, idOrCode)
	if err != nil {
		return LeadSnapshot{}, nil, "", err
	}
	l, err := s.q.LockLead(ctx, gl.ID)
	if errors.Is(err, pgx.ErrNoRows) {
		return LeadSnapshot{}, nil, "", errLeadNotFound
	}
	if err != nil {
		return LeadSnapshot{}, nil, "", err
	}
	return snapshot(l), l.ConvertedStudentID, l.Stage, nil
}

// ClosingChoices: lớp / khóa / ưu đãi cho hộp Chốt & Xếp lớp của một khách.
func (s *Service) ClosingChoices(ctx context.Context, a *iam.Access, idOrCode string) (*ClosingChoices, error) {
	l, _, _, err := s.leadSnapshot(ctx, a, idOrCode)
	if err != nil {
		return nil, err
	}
	out := &ClosingChoices{Classes: []ClassChoice{}, Courses: []CourseChoice{}, Promotions: []Option{}}
	if src := s.choiceSource(); src != nil {
		if out.Classes, err = src.ClosingClasses(ctx, l); err != nil {
			return nil, err
		}
		if out.Courses, err = src.ClosingCourses(ctx); err != nil {
			return nil, err
		}
	}
	promos, err := s.q.AvailablePromotions(ctx)
	if err != nil {
		return nil, err
	}
	// Ưu đãi chung + ưu đãi của chi nhánh trong phạm vi khách CRM, cộng chi nhánh của khách đang chốt
	// (CrmController::closingWizard, rà soát 19/10/2026).
	limit := a.BranchLimit(scopeLead)
	if limit != nil && l.BranchID != nil {
		limit = append(slices.Clone(limit), *l.BranchID)
	}
	for _, p := range promos {
		if limit != nil && p.BranchID != nil && !slices.Contains(limit, *p.BranchID) {
			continue
		}
		out.Promotions = append(out.Promotions, Option{Value: p.ID.String(), Label: p.Name + " (" + valueLabel(p.Type, p.Value) + ")"})
	}
	return out, nil
}

// WaitingChoices: lớp gợi ý để xếp lớp cho khách Chờ xếp lớp.
func (s *Service) WaitingChoices(ctx context.Context, a *iam.Access, idOrCode string) ([]ClassChoice, error) {
	l, student, stage, err := s.leadSnapshot(ctx, a, idOrCode)
	if err != nil {
		return nil, err
	}
	out := []ClassChoice{}
	src := s.choiceSource()
	if src == nil || stage != StageWaitingClass || student == nil {
		return out, nil
	}
	return src.WaitingClasses(ctx, l, *student)
}

func (h *Handler) closingChoices(w http.ResponseWriter, r *http.Request) {
	v, err := h.svc.ClosingChoices(r.Context(), actor(r), chi.URLParam(r, "id"))
	h.ok(w, r, v, err)
}

func (h *Handler) waitingChoices(w http.ResponseWriter, r *http.Request) {
	v, err := h.svc.WaitingChoices(r.Context(), actor(r), chi.URLParam(r, "id"))
	h.ok(w, r, map[string]any{"classes": v}, err)
}
