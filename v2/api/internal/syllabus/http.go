package syllabus

import (
	"errors"
	"log/slog"
	"net/http"
	"strconv"

	"github.com/go-chi/chi/v5"
	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/filestore"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/httpx"
)

type Handler struct {
	svc *Service
	log *slog.Logger
}

func NewHandler(svc *Service, log *slog.Logger) *Handler { return &Handler{svc: svc, log: log} }

// Routes gắn API Giáo trình & Big Test vào /syllabus (cả nhóm cần syllabus.view, như Laravel) và màn order đề của Cổng GV
// vào /teacher/order-test. Nút duyệt / từ chối chỉ Admin (service kiểm).
func (h *Handler) Routes(r chi.Router) {
	view := iam.Require("syllabus.view")
	upload := iam.Require("syllabus.upload")
	manage := iam.Require("syllabus.manage")
	update := iam.Require("syllabus.update")
	propose := iam.Require("syllabus.propose_adjustment")
	approveAdj := iam.Require("syllabus.approve_adjustment")
	approveBT := iam.Require("big_test.approve")

	r.Route("/syllabus", func(r chi.Router) {
		r.With(view).Get("/documents", h.documents)
		r.With(view, upload).Post("/documents", h.uploadDocument)
		r.With(view, upload).Delete("/documents/{id}", h.deleteDocument)
		r.With(view).Post("/documents/{id}/viewed", h.markViewed)

		r.With(view).Get("/builder", h.builder)
		r.With(view, manage).Post("/curricula", h.createCurriculum)
		r.With(view, manage).Put("/curricula/{id}", h.updateCurriculum)
		r.With(view, manage).Delete("/curricula/{id}", h.deleteCurriculum)
		r.With(view, manage).Post("/stages", h.createStage)
		r.With(view, manage).Put("/stages/{id}", h.updateStage)
		r.With(view, manage).Delete("/stages/{id}", h.deleteStage)
		r.With(view, manage).Post("/stages/{id}/move", h.moveStage)
		r.With(view, manage).Post("/units", h.createUnit)
		r.With(view, manage).Put("/units/{id}", h.updateUnit)
		r.With(view, manage).Delete("/units/{id}", h.deleteUnit)
		r.With(view, manage).Post("/lessons", h.createLesson)
		r.With(view, manage).Put("/lessons/{id}", h.updateLesson)
		r.With(view, manage).Delete("/lessons/{id}", h.deleteLesson)

		r.With(view).Get("/assignments", h.assignments)
		r.With(view, manage).Post("/assignments", h.openAssignment)
		r.With(view, manage).Put("/assignments/{id}", h.updateAssignment)
		r.With(view, approveAdj).Post("/assignments/{id}/close", h.closeAssignment)
		r.With(view).Post("/assignments/{id}/expected-big-test-date", h.expectedDate)
		r.With(view).Get("/teaching-stages", h.teachingStages)
		r.With(view).Get("/teacher-view", h.teacherView)

		r.With(view).Get("/versions", h.versions)
		r.With(view).Get("/teacher-propose", h.teacherPropose)
		r.With(view, propose).Post("/proposals", h.createProposal)
		r.With(view, approveAdj).Post("/proposals/{id}/approve", h.approveProposal)
		r.With(view, approveAdj).Post("/proposals/{id}/reject", h.rejectProposal)

		r.With(view).Get("/teacher-adjust", h.teacherAdjust)
		r.With(view).Get("/adjustment-requests", h.adjustmentRequests)
		r.With(view, propose).Post("/adjustment-requests", h.createPacing)
		r.With(view, approveAdj).Post("/adjustment-requests/{id}/approve", h.approvePacing)
		r.With(view, approveAdj).Post("/adjustment-requests/{id}/reject", h.rejectPacing)

		r.With(view).Get("/big-tests/distribution", h.distribution)
		r.With(view, manage).Post("/big-tests", h.createBigTest)
		r.With(view, approveBT).Post("/big-tests/{id}/approve", h.approveBigTest)
		r.With(view, approveBT).Post("/big-tests/{id}/stage", h.assignStage)
		r.With(view, approveBT).Post("/big-test-orders/{id}/approve", h.approveOrder)
		r.With(view, approveBT).Post("/big-test-orders/{id}/reject", h.rejectOrder)
		r.With(view).Get("/big-tests/schedules", h.schedules)
		r.With(view, manage).Post("/big-tests/{id}/remind", h.remind)
		r.With(view).Get("/big-tests/results", h.results)
		r.With(view, update).Post("/big-tests/{id}/results", h.storeResults)
		r.With(view, approveBT).Post("/big-tests/{id}/results/approve", h.approveResults)
		r.With(view, approveBT).Post("/big-tests/{id}/send-zalo", h.sendZalo)
		r.With(view, approveBT).Post("/big-test-results/{id}/send-zalo", h.sendSingle)
		r.With(view, approveBT).Post("/big-test-results/{id}/approve-send", h.approveSend)
	})

	// Cổng GV: order đề test (người điểm danh lớp mình hoặc làm thay GV; lớp cụ thể service kiểm).
	teacher := iam.RequireAny("attendance_student.record", "attendance_student.record_any")
	r.With(teacher).Get("/teacher/order-test/{classId}", h.orderTest)
	r.With(teacher).Post("/teacher/order-test/{classId}", h.submitOrderTest)
}

func (h *Handler) fail(w http.ResponseWriter, r *http.Request, err error) {
	var ve *filestore.ValidationError
	if errors.As(err, &ve) {
		err = iam.Invalid(ve.Message)
	}
	iam.Fail(w, r, h.log, err)
}

func actor(r *http.Request) *iam.Access { return iam.AccessFrom(r.Context()) }

func pathID(w http.ResponseWriter, r *http.Request, name string) (uuid.UUID, bool) {
	id, err := uuid.Parse(chi.URLParam(r, name))
	if err != nil {
		httpx.Error(w, http.StatusNotFound, ErrNotFound.Code, ErrNotFound.Message)
		return uuid.Nil, false
	}
	return id, true
}

func idParam(w http.ResponseWriter, r *http.Request) (uuid.UUID, bool) { return pathID(w, r, "id") }

// queryID: tham số uuid trên URL; sai định dạng coi như không có.
func queryID(r *http.Request, name string) *uuid.UUID {
	id, err := uuid.Parse(r.URL.Query().Get(name))
	if err != nil {
		return nil
	}
	return &id
}

func queryInt(r *http.Request, name string) int {
	n, _ := strconv.Atoi(r.URL.Query().Get(name))
	return n
}

// reply: kết quả đọc (200) hoặc lỗi.
func (h *Handler) reply(w http.ResponseWriter, r *http.Request, v any, err error) {
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, v)
}

// --- Tài liệu ---

func (h *Handler) documents(w http.ResponseWriter, r *http.Request) {
	res, err := h.svc.Documents(r.Context(), actor(r), DocumentFilter{
		CurriculumID: queryID(r, "curriculum_id"), Search: r.URL.Query().Get("search"), Page: queryInt(r, "page"),
	})
	h.reply(w, r, res, err)
}

func formBool(f *filestore.Form, name string) bool {
	v := f.Value(name)
	return v == "1" || v == "true" || v == "on"
}

func (h *Handler) uploadDocument(w http.ResponseWriter, r *http.Request) {
	form, err := filestore.ReadMultipart(w, r, DocumentPolicy.MaxBytes, DocumentPolicy.TooLarge)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	defer form.Close()
	res, err := h.svc.UploadDocument(r.Context(), actor(r), DocumentInput{
		CurriculumID: form.Value("curriculum_id"), Title: form.Value("title"), StageID: form.Value("stage_id"),
		StageName: form.Value("stage_name"), VisibleToTeachers: formBool(form, "visible_to_teachers"),
		VisibleToAssistants: formBool(form, "visible_to_assistants"), Downloadable: formBool(form, "downloadable"),
	}, form.File("file"))
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusCreated, res)
}

func (h *Handler) deleteDocument(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	if !ok {
		return
	}
	res, err := h.svc.DeleteDocument(r.Context(), actor(r), id)
	h.reply(w, r, res, err)
}

func (h *Handler) markViewed(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	if !ok {
		return
	}
	res, err := h.svc.MarkDocumentViewed(r.Context(), actor(r), id)
	h.reply(w, r, res, err)
}

// --- Soạn syllabus ---

func (h *Handler) builder(w http.ResponseWriter, r *http.Request) {
	res, err := h.svc.Builder(r.Context(), actor(r), queryID(r, "curriculum"))
	h.reply(w, r, res, err)
}

// create / update theo cùng một mẫu: đọc JSON in, gọi fn.
func decodeAnd[T any](h *Handler, w http.ResponseWriter, r *http.Request, status int, fn func(T) (*Message, error)) {
	var in T
	if !httpx.Decode(w, r, &in) {
		return
	}
	res, err := fn(in)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, status, res)
}

// withID: route có {id} và body JSON.
func withID[T any](h *Handler, w http.ResponseWriter, r *http.Request, fn func(uuid.UUID, T) (*Message, error)) {
	id, ok := idParam(w, r)
	if !ok {
		return
	}
	decodeAnd(h, w, r, http.StatusOK, func(in T) (*Message, error) { return fn(id, in) })
}

// byID: route có {id}, không cần body.
func (h *Handler) byID(w http.ResponseWriter, r *http.Request, fn func(uuid.UUID) (*Message, error)) {
	id, ok := idParam(w, r)
	if !ok {
		return
	}
	res, err := fn(id)
	h.reply(w, r, res, err)
}

func (h *Handler) createCurriculum(w http.ResponseWriter, r *http.Request) {
	decodeAnd(h, w, r, http.StatusCreated, func(in CurriculumInput) (*Message, error) {
		return h.svc.CreateCurriculum(r.Context(), actor(r), in)
	})
}

func (h *Handler) updateCurriculum(w http.ResponseWriter, r *http.Request) {
	withID(h, w, r, func(id uuid.UUID, in CurriculumInput) (*Message, error) {
		return h.svc.UpdateCurriculum(r.Context(), actor(r), id, in)
	})
}

func (h *Handler) deleteCurriculum(w http.ResponseWriter, r *http.Request) {
	h.byID(w, r, func(id uuid.UUID) (*Message, error) { return h.svc.DeleteCurriculum(r.Context(), actor(r), id) })
}

func (h *Handler) createStage(w http.ResponseWriter, r *http.Request) {
	decodeAnd(h, w, r, http.StatusCreated, func(in StageInput) (*Message, error) {
		return h.svc.CreateStage(r.Context(), actor(r), in)
	})
}

func (h *Handler) updateStage(w http.ResponseWriter, r *http.Request) {
	withID(h, w, r, func(id uuid.UUID, in StageInput) (*Message, error) {
		return h.svc.UpdateStage(r.Context(), actor(r), id, in)
	})
}

func (h *Handler) deleteStage(w http.ResponseWriter, r *http.Request) {
	h.byID(w, r, func(id uuid.UUID) (*Message, error) { return h.svc.DeleteStage(r.Context(), actor(r), id) })
}

func (h *Handler) moveStage(w http.ResponseWriter, r *http.Request) {
	withID(h, w, r, func(id uuid.UUID, in struct {
		Direction string `json:"direction"`
	}) (*Message, error) {
		return h.svc.MoveStage(r.Context(), actor(r), id, in.Direction)
	})
}

func (h *Handler) createUnit(w http.ResponseWriter, r *http.Request) {
	decodeAnd(h, w, r, http.StatusCreated, func(in UnitInput) (*Message, error) {
		return h.svc.CreateUnit(r.Context(), actor(r), in)
	})
}

func (h *Handler) updateUnit(w http.ResponseWriter, r *http.Request) {
	withID(h, w, r, func(id uuid.UUID, in UnitInput) (*Message, error) {
		return h.svc.UpdateUnit(r.Context(), actor(r), id, in)
	})
}

func (h *Handler) deleteUnit(w http.ResponseWriter, r *http.Request) {
	h.byID(w, r, func(id uuid.UUID) (*Message, error) { return h.svc.DeleteUnit(r.Context(), actor(r), id) })
}

func (h *Handler) createLesson(w http.ResponseWriter, r *http.Request) {
	decodeAnd(h, w, r, http.StatusCreated, func(in LessonInput) (*Message, error) {
		return h.svc.CreateLesson(r.Context(), actor(r), in)
	})
}

func (h *Handler) updateLesson(w http.ResponseWriter, r *http.Request) {
	withID(h, w, r, func(id uuid.UUID, in LessonInput) (*Message, error) {
		return h.svc.UpdateLesson(r.Context(), actor(r), id, in)
	})
}

func (h *Handler) deleteLesson(w http.ResponseWriter, r *http.Request) {
	h.byID(w, r, func(id uuid.UUID) (*Message, error) { return h.svc.DeleteLesson(r.Context(), actor(r), id) })
}

// --- Giao chặng ---

func (h *Handler) assignments(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()
	res, err := h.svc.Assignments(r.Context(), actor(r), AssignmentFilter{
		Search: q.Get("search"), Status: q.Get("status"), ClassID: queryID(r, "class_id"), Page: queryInt(r, "page"),
	})
	h.reply(w, r, res, err)
}

func (h *Handler) openAssignment(w http.ResponseWriter, r *http.Request) {
	decodeAnd(h, w, r, http.StatusCreated, func(in AssignmentInput) (*Message, error) {
		return h.svc.OpenAssignment(r.Context(), actor(r), in)
	})
}

func (h *Handler) updateAssignment(w http.ResponseWriter, r *http.Request) {
	withID(h, w, r, func(id uuid.UUID, in AssignmentEdit) (*Message, error) {
		return h.svc.UpdateAssignment(r.Context(), actor(r), id, in)
	})
}

func (h *Handler) closeAssignment(w http.ResponseWriter, r *http.Request) {
	withID(h, w, r, func(id uuid.UUID, in struct {
		Reason   string `json:"reason"`
		OpenNext bool   `json:"open_next"`
	}) (*Message, error) {
		return h.svc.CloseAssignment(r.Context(), actor(r), id, in.Reason, in.OpenNext)
	})
}

func (h *Handler) expectedDate(w http.ResponseWriter, r *http.Request) {
	withID(h, w, r, func(id uuid.UUID, in struct {
		Date string `json:"expected_big_test_date"`
	}) (*Message, error) {
		return h.svc.SetExpectedBigTestDate(r.Context(), actor(r), id, in.Date)
	})
}

func (h *Handler) teachingStages(w http.ResponseWriter, r *http.Request) {
	res, err := h.svc.TeachingStages(r.Context(), actor(r))
	h.reply(w, r, res, err)
}

func (h *Handler) teacherView(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()
	res, err := h.svc.TeacherView(r.Context(), actor(r), TeacherViewFilter{
		ClassID: queryID(r, "class"), Search: q.Get("search"), DocumentID: queryID(r, "document"), Tab: q.Get("tab"),
	})
	h.reply(w, r, res, err)
}

// --- Đề xuất sửa giáo trình ---

func (h *Handler) versions(w http.ResponseWriter, r *http.Request) {
	res, err := h.svc.Versions(r.Context(), actor(r), r.URL.Query().Get("status"), queryInt(r, "page"), queryID(r, "proposal"))
	h.reply(w, r, res, err)
}

func (h *Handler) teacherPropose(w http.ResponseWriter, r *http.Request) {
	res, err := h.svc.TeacherPropose(r.Context(), actor(r), r.URL.Query().Get("status"), queryInt(r, "page"))
	h.reply(w, r, res, err)
}

func (h *Handler) createProposal(w http.ResponseWriter, r *http.Request) {
	form, err := filestore.ReadMultipart(w, r, ProposalPolicy.MaxBytes, ProposalPolicy.TooLarge)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	defer form.Close()
	res, err := h.svc.CreateProposal(r.Context(), actor(r), ProposalInput{
		CurriculumID: form.Value("curriculum_id"), UnitID: form.Value("unit_id"), LessonID: form.Value("lesson_id"),
		ProposalType: form.Value("proposal_type"), OldContent: form.Value("old_content"), NewContent: form.Value("new_content"),
		Reason: form.Value("reason"),
	}, form.File("file"))
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusCreated, res)
}

type reviewBody struct {
	Note string `json:"review_note"`
}

func (h *Handler) approveProposal(w http.ResponseWriter, r *http.Request) {
	withID(h, w, r, func(id uuid.UUID, in reviewBody) (*Message, error) {
		return h.svc.ReviewProposal(r.Context(), actor(r), id, true, in.Note)
	})
}

func (h *Handler) rejectProposal(w http.ResponseWriter, r *http.Request) {
	withID(h, w, r, func(id uuid.UUID, in reviewBody) (*Message, error) {
		return h.svc.ReviewProposal(r.Context(), actor(r), id, false, in.Note)
	})
}

// --- Điều chỉnh tiến độ ---

func (h *Handler) teacherAdjust(w http.ResponseWriter, r *http.Request) {
	res, err := h.svc.TeacherAdjust(r.Context(), actor(r), queryInt(r, "page"))
	h.reply(w, r, res, err)
}

func (h *Handler) adjustmentRequests(w http.ResponseWriter, r *http.Request) {
	res, err := h.svc.AdjustmentRequests(r.Context(), actor(r), r.URL.Query().Get("status"), queryInt(r, "page"), queryID(r, "request"))
	h.reply(w, r, res, err)
}

func (h *Handler) createPacing(w http.ResponseWriter, r *http.Request) {
	decodeAnd(h, w, r, http.StatusCreated, func(in PacingInput) (*Message, error) {
		return h.svc.CreatePacing(r.Context(), actor(r), in)
	})
}

func (h *Handler) approvePacing(w http.ResponseWriter, r *http.Request) {
	withID(h, w, r, func(id uuid.UUID, in struct {
		ExtraSessions *int `json:"extra_sessions"`
	}) (*Message, error) {
		return h.svc.ApprovePacing(r.Context(), actor(r), id, in.ExtraSessions)
	})
}

func (h *Handler) rejectPacing(w http.ResponseWriter, r *http.Request) {
	withID(h, w, r, func(id uuid.UUID, in struct {
		Reason string `json:"rejection_reason"`
	}) (*Message, error) {
		return h.svc.RejectPacing(r.Context(), actor(r), id, in.Reason)
	})
}

// --- Big Test ---

func (h *Handler) distribution(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()
	status := "pending"
	if q.Has("order_status") {
		status = q.Get("order_status")
	}
	res, err := h.svc.BigTestDistribution(r.Context(), actor(r), DistributionFilter{
		OrderStatus: status, OrderSearch: q.Get("order_search"), OrdersPage: queryInt(r, "orders_page"),
		TestsPage: queryInt(r, "tests_page"), OrderID: queryID(r, "order"),
	})
	h.reply(w, r, res, err)
}

func (h *Handler) createBigTest(w http.ResponseWriter, r *http.Request) {
	decodeAnd(h, w, r, http.StatusCreated, func(in BigTestInput) (*Message, error) {
		return h.svc.CreateBigTest(r.Context(), actor(r), in)
	})
}

func (h *Handler) approveBigTest(w http.ResponseWriter, r *http.Request) {
	h.byID(w, r, func(id uuid.UUID) (*Message, error) { return h.svc.ApproveBigTest(r.Context(), actor(r), id) })
}

func (h *Handler) assignStage(w http.ResponseWriter, r *http.Request) {
	withID(h, w, r, func(id uuid.UUID, in struct {
		StageID string `json:"syllabus_stage_id"`
	}) (*Message, error) {
		return h.svc.AssignBigTestStage(r.Context(), actor(r), id, in.StageID)
	})
}

func (h *Handler) approveOrder(w http.ResponseWriter, r *http.Request) {
	withID(h, w, r, func(id uuid.UUID, in OrderApproval) (*Message, error) {
		return h.svc.ApproveOrder(r.Context(), actor(r), id, in)
	})
}

func (h *Handler) rejectOrder(w http.ResponseWriter, r *http.Request) {
	withID(h, w, r, func(id uuid.UUID, in struct {
		Reason string `json:"rejection_reason"`
	}) (*Message, error) {
		return h.svc.RejectOrder(r.Context(), actor(r), id, in.Reason)
	})
}

func (h *Handler) schedules(w http.ResponseWriter, r *http.Request) {
	res, err := h.svc.BigTestSchedules(r.Context(), actor(r), queryInt(r, "page"))
	h.reply(w, r, res, err)
}

func (h *Handler) remind(w http.ResponseWriter, r *http.Request) {
	h.byID(w, r, func(id uuid.UUID) (*Message, error) { return h.svc.SendBigTestReminder(r.Context(), actor(r), id) })
}

func (h *Handler) results(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()
	test := queryID(r, "test_id")
	if q.Get("test_id") != "" && test == nil {
		h.fail(w, r, ErrTestNotFound)
		return
	}
	res, err := h.svc.BigTestResults(r.Context(), actor(r), test, queryID(r, "result"))
	h.reply(w, r, res, err)
}

func (h *Handler) storeResults(w http.ResponseWriter, r *http.Request) {
	withID(h, w, r, func(id uuid.UUID, in ResultsInput) (*Message, error) {
		return h.svc.StoreResults(r.Context(), actor(r), id, in)
	})
}

func (h *Handler) approveResults(w http.ResponseWriter, r *http.Request) {
	h.byID(w, r, func(id uuid.UUID) (*Message, error) { return h.svc.ApproveResults(r.Context(), actor(r), id) })
}

func (h *Handler) sendZalo(w http.ResponseWriter, r *http.Request) {
	h.byID(w, r, func(id uuid.UUID) (*Message, error) { return h.svc.SendZaloResults(r.Context(), actor(r), id) })
}

func (h *Handler) sendSingle(w http.ResponseWriter, r *http.Request) {
	h.byID(w, r, func(id uuid.UUID) (*Message, error) { return h.svc.SendSingleResult(r.Context(), actor(r), id) })
}

func (h *Handler) approveSend(w http.ResponseWriter, r *http.Request) {
	h.byID(w, r, func(id uuid.UUID) (*Message, error) { return h.svc.ApproveAndSend(r.Context(), actor(r), id) })
}

// --- Cổng GV: order đề ---

func (h *Handler) orderTest(w http.ResponseWriter, r *http.Request) {
	id, ok := pathID(w, r, "classId")
	if !ok {
		return
	}
	res, err := h.svc.OrderTest(r.Context(), actor(r), id)
	h.reply(w, r, res, err)
}

func (h *Handler) submitOrderTest(w http.ResponseWriter, r *http.Request) {
	id, ok := pathID(w, r, "classId")
	if !ok {
		return
	}
	decodeAnd(h, w, r, http.StatusCreated, func(in OrderTestInput) (*Message, error) {
		return h.svc.SubmitOrderTest(r.Context(), actor(r), id, in)
	})
}
