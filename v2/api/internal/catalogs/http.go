package catalogs

import (
	"log/slog"
	"net/http"
	"strconv"

	"github.com/go-chi/chi/v5"
	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/httpx"
)

type Handler struct {
	svc *Service
	log *slog.Logger
}

func NewHandler(svc *Service, log *slog.Logger) *Handler { return &Handler{svc: svc, log: log} }

// Routes gắn API danh mục & tổ chức vào /api/v1. Quyền giống middleware can: của routes/web.php bản Laravel.
func (h *Handler) Routes(r chi.Router) {
	r.Route("/courses", func(r chi.Router) {
		r.With(iam.Require("course.view")).Get("/", h.listCourses)
		r.With(iam.Require("course.create")).Post("/", h.createCourse)
		r.With(iam.Require("course.update")).Put("/{id}", h.updateCourse)
		r.With(iam.Require("course.update")).Post("/{id}/toggle", h.toggleCourse)
		r.With(iam.Require("course.delete")).Delete("/{id}", h.deleteCourse)
	})

	r.Route("/course-levels", func(r chi.Router) {
		r.With(iam.Require("level.view")).Get("/", h.listLevels)
		r.With(iam.Require("level.create")).Post("/", h.createLevel)
		r.With(iam.Require("level.update")).Post("/reorder", h.reorderLevels)
		r.With(iam.Require("level.update")).Put("/{id}", h.updateLevel)
		r.With(iam.Require("level.update")).Post("/{id}/toggle", h.toggleLevel)
		r.With(iam.Require("level.delete")).Delete("/{id}", h.deleteLevel)
	})

	// Phòng học: mọi route cần room.view (như nhóm route Laravel), thao tác cần thêm quyền riêng.
	r.Route("/rooms", func(r chi.Router) {
		r.Use(iam.Require("room.view"))
		r.With(iam.Require("room.view")).Get("/", h.listRooms)
		r.With(iam.Require("room.manage")).Post("/", h.createRoom)
		r.With(iam.Require("room.manage")).Get("/{id}", h.getRoom)
		r.With(iam.Require("room.manage")).Put("/{id}", h.updateRoom)
		r.With(iam.Require("room.delete")).Delete("/{id}", h.deleteRoom)
		r.With(iam.Require("room.view")).Get("/types", h.listRoomTypes)
		r.With(iam.Require("room.manage_types")).Post("/types", h.createRoomType)
		r.With(iam.Require("room.manage_types")).Put("/types/{id}", h.renameRoomType)
		r.With(iam.Require("room.manage_types")).Post("/types/{id}/toggle", h.toggleRoomType)
		r.With(iam.Require("room.manage_types")).Delete("/types/{id}", h.deleteRoomType)
	})

	// Khung giờ ca dạy: ai xếp lịch lớp (class.update) được xem, sửa cần teaching_shift.manage.
	r.Route("/teaching-shifts", func(r chi.Router) {
		r.Use(iam.Require("class.update"))
		r.With(iam.Require("class.update")).Get("/", h.listShifts)
		r.With(iam.Require("teaching_shift.manage")).Post("/", h.createShift)
		r.With(iam.Require("teaching_shift.manage")).Put("/{id}", h.updateShift)
		r.With(iam.Require("teaching_shift.manage")).Post("/{id}/toggle", h.toggleShift)
		r.With(iam.Require("teaching_shift.manage")).Delete("/{id}", h.deleteShift)
	})

	r.Route("/holidays", func(r chi.Router) {
		r.Use(iam.Require("holiday.manage"))
		r.With(iam.Require("holiday.manage")).Get("/", h.listHolidays)
		r.With(iam.Require("holiday.manage")).Post("/", h.createHoliday)
		r.With(iam.Require("holiday.manage")).Get("/{id}", h.getHoliday)
		r.With(iam.Require("holiday.manage")).Put("/{id}", h.updateHoliday)
		r.With(iam.Require("holiday.manage")).Delete("/{id}", h.deleteHoliday)
	})

	r.Route("/system-categories", func(r chi.Router) {
		r.Use(iam.Require("system_category.manage"))
		r.With(iam.Require("system_category.manage")).Get("/", h.listCategories)
		r.With(iam.Require("system_category.manage")).Post("/", h.createCategory)
		r.With(iam.Require("system_category.manage")).Put("/{id}", h.updateCategory)
		r.With(iam.Require("system_category.manage")).Post("/{id}/deactivate", h.setCategoryActive(false))
		r.With(iam.Require("system_category.manage")).Post("/{id}/reactivate", h.setCategoryActive(true))
	})

	r.Route("/system-config", func(r chi.Router) {
		r.With(iam.Require("sla.configure")).Get("/sla", h.slaConfig)
		r.With(iam.Require("sla.configure")).Post("/sla/settings", h.updateSlaSettings)
		r.With(iam.Require("sla.configure")).Put("/sla/{key}", h.updateSla)

		r.With(iam.Require("bank_account.manage")).Get("/bank-accounts", h.bankAccounts)
		r.With(iam.Require("bank_account.manage")).Post("/bank-accounts", h.createBankAccount)
		r.With(iam.Require("bank_account.manage")).Put("/bank-accounts/{id}", h.updateBankAccount)
		r.With(iam.Require("bank_account.manage")).Delete("/bank-accounts/{id}", h.deleteBankAccount)
		r.With(iam.Require("bank_account.manage")).Post("/bank-accounts/{id}/default", h.setDefaultBankAccount)

		r.With(iam.Require("fee_reminder_config.manage")).Get("/debt-reminders", h.debtReminders)
		r.With(iam.Require("fee_reminder_config.manage")).Post("/debt-reminders", h.saveDebtRule)
		r.With(iam.Require("fee_reminder_config.manage")).Post("/debt-reminders/settings", h.updateDebtSettings)

		// Email nhận thông báo vận hành toàn hệ thống + SMTP: chỉ mail_config.manage (routes/web.php system-config.ticket-emails).
		r.With(iam.Require("mail_config.manage")).Get("/ticket-emails", h.ticketEmails)
		r.With(iam.Require("mail_config.manage")).Post("/ticket-emails", h.updateTicketEmails)
		r.With(iam.Require("mail_config.manage")).Post("/ticket-emails/test", h.testTicketEmail)
	})
}

func actor(r *http.Request) *iam.Access { return iam.AccessFrom(r.Context()) }

func idParam(w http.ResponseWriter, r *http.Request) (uuid.UUID, bool) {
	id, err := uuid.Parse(chi.URLParam(r, "id"))
	if err != nil {
		httpx.Error(w, http.StatusNotFound, "not_found", "Không tìm thấy.")
		return uuid.Nil, false
	}
	return id, true
}

// optUUID đọc tham số lọc dạng uuid; sai định dạng thì bỏ qua (như bộ lọc rỗng).
func optUUID(v string) *uuid.UUID {
	id, err := uuid.Parse(v)
	if err != nil {
		return nil
	}
	return &id
}

func pageParam(r *http.Request) int {
	p, _ := strconv.Atoi(r.URL.Query().Get("page"))
	return p
}

func (h *Handler) fail(w http.ResponseWriter, r *http.Request, err error) { iam.Fail(w, r, h.log, err) }

// done trả thông báo thành công (như flash "status" của Laravel) kèm dữ liệu.
func done(w http.ResponseWriter, status int, msg string, extra map[string]any) {
	body := map[string]any{"message": msg}
	for k, v := range extra {
		body[k] = v
	}
	httpx.JSON(w, status, body)
}

// ───────────────────────── Khóa học ─────────────────────────

func (h *Handler) listCourses(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()
	out, err := h.svc.ListCourses(r.Context(), CourseFilter{
		Search: q.Get("search"), LevelID: optUUID(q.Get("course_level_id")), Status: q.Get("status"), Page: pageParam(r),
	})
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, out)
}

func (h *Handler) createCourse(w http.ResponseWriter, r *http.Request) {
	var in CourseInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	c, msg, err := h.svc.CreateCourse(r.Context(), actor(r), in)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	done(w, http.StatusCreated, msg, map[string]any{"id": c.ID, "code": c.Code})
}

func (h *Handler) updateCourse(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	var in CourseInput
	if !ok || !httpx.Decode(w, r, &in) {
		return
	}
	_, msg, err := h.svc.UpdateCourse(r.Context(), actor(r), id, in)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	done(w, http.StatusOK, msg, nil)
}

func (h *Handler) toggleCourse(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	if !ok {
		return
	}
	c, msg, err := h.svc.ToggleCourse(r.Context(), actor(r), id)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	done(w, http.StatusOK, msg, map[string]any{"is_active": c.IsActive})
}

func (h *Handler) deleteCourse(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	if !ok {
		return
	}
	msg, err := h.svc.DeleteCourse(r.Context(), actor(r), id)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	done(w, http.StatusOK, msg, nil)
}

// ───────────────────────── Trình độ ─────────────────────────

func (h *Handler) listLevels(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()
	out, err := h.svc.ListLevels(r.Context(), LevelFilter{Search: q.Get("search"), Status: q.Get("status"), Group: q.Get("group"), Page: pageParam(r)})
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, out)
}

func (h *Handler) createLevel(w http.ResponseWriter, r *http.Request) {
	var in LevelInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	lv, msg, err := h.svc.CreateLevel(r.Context(), actor(r), in)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	done(w, http.StatusCreated, msg, map[string]any{"id": lv.ID})
}

func (h *Handler) updateLevel(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	var in LevelInput
	if !ok || !httpx.Decode(w, r, &in) {
		return
	}
	_, msg, err := h.svc.UpdateLevel(r.Context(), actor(r), id, in)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	done(w, http.StatusOK, msg, nil)
}

func (h *Handler) toggleLevel(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	if !ok {
		return
	}
	lv, msg, err := h.svc.ToggleLevel(r.Context(), actor(r), id)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	done(w, http.StatusOK, msg, map[string]any{"is_active": lv.IsActive})
}

func (h *Handler) deleteLevel(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	if !ok {
		return
	}
	msg, err := h.svc.DeleteLevel(r.Context(), actor(r), id)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	done(w, http.StatusOK, msg, nil)
}

func (h *Handler) reorderLevels(w http.ResponseWriter, r *http.Request) {
	var in struct {
		IDs []uuid.UUID `json:"ids"`
	}
	if !httpx.Decode(w, r, &in) {
		return
	}
	if err := h.svc.ReorderLevels(r.Context(), actor(r), in.IDs); err != nil {
		h.fail(w, r, err)
		return
	}
	done(w, http.StatusOK, "Đã lưu thứ tự trình độ.", nil)
}

// ───────────────────────── Phòng học ─────────────────────────

func (h *Handler) listRooms(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()
	out, err := h.svc.ListRooms(r.Context(), actor(r), RoomFilter{
		BranchID: optUUID(q.Get("branch_id")), RoomTypeID: optUUID(q.Get("room_type_id")), Page: pageParam(r),
	})
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, out)
}

func (h *Handler) getRoom(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	if !ok {
		return
	}
	room, err := h.svc.GetRoom(r.Context(), actor(r), id)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, room)
}

func (h *Handler) createRoom(w http.ResponseWriter, r *http.Request) {
	var in RoomInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	id, msg, err := h.svc.CreateRoom(r.Context(), actor(r), in)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	done(w, http.StatusCreated, msg, map[string]any{"id": id})
}

func (h *Handler) updateRoom(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	var in RoomInput
	if !ok || !httpx.Decode(w, r, &in) {
		return
	}
	msg, err := h.svc.UpdateRoom(r.Context(), actor(r), id, in)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	done(w, http.StatusOK, msg, nil)
}

func (h *Handler) deleteRoom(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	if !ok {
		return
	}
	msg, err := h.svc.DeleteRoom(r.Context(), actor(r), id)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	done(w, http.StatusOK, msg, nil)
}

func (h *Handler) listRoomTypes(w http.ResponseWriter, r *http.Request) {
	out, err := h.svc.ListRoomTypes(r.Context(), actor(r))
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, out)
}

type nameInput struct {
	Name string `json:"name"`
}

func (h *Handler) createRoomType(w http.ResponseWriter, r *http.Request) {
	var in nameInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	t, msg, err := h.svc.CreateRoomType(r.Context(), actor(r), in.Name)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	done(w, http.StatusCreated, msg, map[string]any{"id": t.ID})
}

func (h *Handler) renameRoomType(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	var in nameInput
	if !ok || !httpx.Decode(w, r, &in) {
		return
	}
	msg, err := h.svc.RenameRoomType(r.Context(), actor(r), id, in.Name)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	done(w, http.StatusOK, msg, nil)
}

func (h *Handler) toggleRoomType(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	if !ok {
		return
	}
	msg, err := h.svc.ToggleRoomType(r.Context(), actor(r), id)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	done(w, http.StatusOK, msg, nil)
}

func (h *Handler) deleteRoomType(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	if !ok {
		return
	}
	msg, err := h.svc.DeleteRoomType(r.Context(), actor(r), id)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	done(w, http.StatusOK, msg, nil)
}

// ───────────────────────── Khung giờ ca dạy ─────────────────────────

func (h *Handler) listShifts(w http.ResponseWriter, r *http.Request) {
	out, err := h.svc.ListShifts(r.Context(), actor(r))
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, out)
}

func (h *Handler) createShift(w http.ResponseWriter, r *http.Request) {
	var in ShiftInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	id, msg, err := h.svc.CreateShift(r.Context(), actor(r), in)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	done(w, http.StatusCreated, msg, map[string]any{"id": id})
}

func (h *Handler) updateShift(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	var in ShiftInput
	if !ok || !httpx.Decode(w, r, &in) {
		return
	}
	msg, err := h.svc.UpdateShift(r.Context(), actor(r), id, in)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	done(w, http.StatusOK, msg, nil)
}

func (h *Handler) toggleShift(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	if !ok {
		return
	}
	msg, err := h.svc.ToggleShift(r.Context(), actor(r), id)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	done(w, http.StatusOK, msg, nil)
}

func (h *Handler) deleteShift(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	if !ok {
		return
	}
	msg, err := h.svc.DeleteShift(r.Context(), actor(r), id)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	done(w, http.StatusOK, msg, nil)
}

// ───────────────────────── Ngày nghỉ lễ ─────────────────────────

func (h *Handler) listHolidays(w http.ResponseWriter, r *http.Request) {
	out, err := h.svc.ListHolidays(r.Context(), r.URL.Query().Get("search"), pageParam(r))
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, out)
}

func (h *Handler) getHoliday(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	if !ok {
		return
	}
	out, err := h.svc.GetHoliday(r.Context(), id)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, out)
}

func (h *Handler) createHoliday(w http.ResponseWriter, r *http.Request) {
	var in HolidayInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	id, msg, err := h.svc.CreateHoliday(r.Context(), actor(r), in)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	done(w, http.StatusCreated, msg, map[string]any{"id": id})
}

func (h *Handler) updateHoliday(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	var in HolidayInput
	if !ok || !httpx.Decode(w, r, &in) {
		return
	}
	msg, err := h.svc.UpdateHoliday(r.Context(), actor(r), id, in)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	done(w, http.StatusOK, msg, nil)
}

func (h *Handler) deleteHoliday(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	if !ok {
		return
	}
	msg, err := h.svc.DeleteHoliday(r.Context(), actor(r), id)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	done(w, http.StatusOK, msg, nil)
}

// ───────────────────────── Danh mục hệ thống ─────────────────────────

func (h *Handler) listCategories(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()
	out, err := h.svc.ListCategories(r.Context(), q.Get("type"), q.Get("q"), pageParam(r))
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, out)
}

func (h *Handler) createCategory(w http.ResponseWriter, r *http.Request) {
	var in CategoryInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	c, msg, err := h.svc.CreateCategory(r.Context(), actor(r), in)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	done(w, http.StatusCreated, msg, map[string]any{"category": c})
}

func (h *Handler) updateCategory(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	var in CategoryInput
	if !ok || !httpx.Decode(w, r, &in) {
		return
	}
	c, msg, err := h.svc.UpdateCategory(r.Context(), actor(r), id, in)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	done(w, http.StatusOK, msg, map[string]any{"category": c})
}

func (h *Handler) setCategoryActive(active bool) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		id, ok := idParam(w, r)
		if !ok {
			return
		}
		msg, err := h.svc.SetCategoryActive(r.Context(), actor(r), id, active)
		if err != nil {
			h.fail(w, r, err)
			return
		}
		done(w, http.StatusOK, msg, nil)
	}
}

// ───────────────────────── SLA ─────────────────────────

func (h *Handler) slaConfig(w http.ResponseWriter, r *http.Request) {
	out, err := h.svc.SlaConfig(r.Context())
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, out)
}

func (h *Handler) updateSla(w http.ResponseWriter, r *http.Request) {
	var in SlaInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	msg, err := h.svc.UpdateSla(r.Context(), actor(r), chi.URLParam(r, "key"), in)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	done(w, http.StatusOK, msg, nil)
}

func (h *Handler) updateSlaSettings(w http.ResponseWriter, r *http.Request) {
	var in struct {
		LadderResetMonths *int `json:"ladder_reset_months"`
	}
	if !httpx.Decode(w, r, &in) {
		return
	}
	msg, err := h.svc.UpdateSlaSettings(r.Context(), actor(r), in.LadderResetMonths)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	done(w, http.StatusOK, msg, nil)
}

// ───────────────────────── Tài khoản ngân hàng ─────────────────────────

func (h *Handler) bankAccounts(w http.ResponseWriter, r *http.Request) {
	out, err := h.svc.BankAccounts(r.Context(), r.URL.Query().Get("q"))
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, out)
}

func (h *Handler) createBankAccount(w http.ResponseWriter, r *http.Request) {
	var in BankAccountInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	id, msg, err := h.svc.CreateBankAccount(r.Context(), actor(r), in)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	done(w, http.StatusCreated, msg, map[string]any{"id": id})
}

func (h *Handler) updateBankAccount(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	var in BankAccountInput
	if !ok || !httpx.Decode(w, r, &in) {
		return
	}
	msg, err := h.svc.UpdateBankAccount(r.Context(), actor(r), id, in)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	done(w, http.StatusOK, msg, nil)
}

func (h *Handler) deleteBankAccount(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	if !ok {
		return
	}
	msg, err := h.svc.DeleteBankAccount(r.Context(), actor(r), id)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	done(w, http.StatusOK, msg, nil)
}

func (h *Handler) setDefaultBankAccount(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	if !ok {
		return
	}
	msg, err := h.svc.SetDefaultBankAccount(r.Context(), actor(r), id)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	done(w, http.StatusOK, msg, nil)
}

// ───────────────────────── Nhắc nợ học phí ─────────────────────────

func (h *Handler) debtReminders(w http.ResponseWriter, r *http.Request) {
	out, err := h.svc.DebtReminders(r.Context())
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, out)
}

func (h *Handler) saveDebtRule(w http.ResponseWriter, r *http.Request) {
	var in DebtRuleInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	rule, msg, err := h.svc.SaveDebtRule(r.Context(), actor(r), in)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	done(w, http.StatusOK, msg, map[string]any{"rule": rule})
}

func (h *Handler) updateDebtSettings(w http.ResponseWriter, r *http.Request) {
	var in DebtSettingsInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	msg, err := h.svc.UpdateDebtSettings(r.Context(), actor(r), in)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	done(w, http.StatusOK, msg, nil)
}

// ───────────────────────── Email nhận Ticket ─────────────────────────

func (h *Handler) ticketEmails(w http.ResponseWriter, r *http.Request) {
	out, err := h.svc.TicketEmails(r.Context(), actor(r))
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, out)
}

func (h *Handler) updateTicketEmails(w http.ResponseWriter, r *http.Request) {
	var in TicketEmailInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	msg, err := h.svc.UpdateTicketEmails(r.Context(), actor(r), in)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	done(w, http.StatusOK, msg, nil)
}
