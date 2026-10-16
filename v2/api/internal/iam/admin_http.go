package iam

import (
	"errors"
	"net/http"
	"strconv"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/go-chi/chi/v5"
	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/geo"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/httpx"
)

// AdminRoutes gắn API quản trị chi nhánh, vai trò, người dùng và phân quyền cá nhân. Mỗi route gắn quyền riêng
// (Require); service kiểm thêm phạm vi và quan hệ cấp trên / cấp dưới.
func (h *Handler) AdminRoutes(r chi.Router) {
	r.Route("/branches", func(r chi.Router) {
		r.With(Require("branch.view")).Get("/", h.listBranches)
		r.With(Require("branch.create")).Post("/", h.createBranch)
		r.With(Require("branch.update")).Patch("/{id}", h.updateBranch)
		r.With(Require("branch.update")).Put("/{id}/checkin", h.updateBranchCheckin)
		r.With(Require("branch.update")).Get("/address-search", h.addressSearch)
		r.With(Require("branch.manage")).Post("/{id}/activate", h.setBranchActive(true))
		r.With(Require("branch.manage")).Post("/{id}/deactivate", h.setBranchActive(false))
	})

	r.With(RequireAny("role.view", "permission.override")).Get("/permissions/catalog", h.catalog)
	r.Route("/roles", func(r chi.Router) {
		r.With(Require("role.view")).Get("/", h.listRoles)
		r.With(Require("role.update")).Patch("/{name}", h.updateRole)
		r.With(Require("role.assign_permission")).Put("/{name}/permissions", h.setRolePermissions)
	})

	r.Route("/users", func(r chi.Router) {
		r.With(Require("user.view")).Get("/", h.listUsers)
		r.With(RequireAny("user.create", "user.update")).Get("/options", h.userOptions)
		r.With(Require("user.create")).Post("/", h.createUser)
		r.With(Require("user.view")).Get("/{id}", h.getUser)
		r.With(Require("user.update")).Patch("/{id}", h.updateUser)
		r.With(Require("user.assign_role")).Put("/{id}/roles", h.setUserRoles)
		r.With(Require("user.lock")).Post("/{id}/lock", h.setUserLocked(true))
		r.With(Require("user.lock")).Post("/{id}/unlock", h.setUserLocked(false))
		r.With(Require("user.reset_password")).Post("/{id}/reset-password", h.resetUserPassword)
		r.With(Require("user.reset_password")).Post("/{id}/reset-totp", h.resetUserTOTP)
		r.With(Require("permission.override")).Get("/{id}/permissions", h.getUserPermissions)
		r.With(Require("permission.override")).Put("/{id}/permissions", h.setUserPermissions)
	})
}

func idParam(w http.ResponseWriter, r *http.Request) (uuid.UUID, bool) {
	id, err := uuid.Parse(chi.URLParam(r, "id"))
	if err != nil {
		httpx.Error(w, http.StatusNotFound, "not_found", "Không tìm thấy.")
		return uuid.Nil, false
	}
	return id, true
}

func actorOf(r *http.Request) *Access { return AccessFrom(r.Context()) }

func (h *Handler) listBranches(w http.ResponseWriter, r *http.Request) {
	list, err := h.svc.ListBranches(r.Context())
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, map[string]any{"branches": list})
}

func (h *Handler) createBranch(w http.ResponseWriter, r *http.Request) {
	var in BranchInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	b, err := h.svc.CreateBranch(r.Context(), actorOf(r), in)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusCreated, b)
}

func (h *Handler) updateBranch(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	var in BranchInput
	if !ok || !httpx.Decode(w, r, &in) {
		return
	}
	b, err := h.svc.UpdateBranch(r.Context(), actorOf(r), id, in)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, b)
}

func (h *Handler) updateBranchCheckin(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	var in BranchCheckinInput
	if !ok || !httpx.Decode(w, r, &in) {
		return
	}
	b, err := h.svc.UpdateBranchCheckin(r.Context(), actorOf(r), id, in)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, b)
}

// Tìm địa chỉ cho bản đồ chấm công: 30 lần / phút mỗi người (giống throttle:30,1 bên Laravel).
const addressSearchPerMinute = 30

func (h *Handler) addressSearch(w http.ResponseWriter, r *http.Request) {
	q := strings.TrimSpace(r.URL.Query().Get("q"))
	if q == "" || utf8.RuneCountInString(q) > 300 {
		httpx.Error(w, http.StatusUnprocessableEntity, "invalid", "Nhập địa chỉ cần tìm (tối đa 300 ký tự).")
		return
	}
	if h.geo == nil {
		httpx.Error(w, http.StatusBadGateway, "address_search_unavailable", addressSearchDown)
		return
	}
	ctx := r.Context()
	key := "address-search:rate:" + actorOf(r).UserID.String()
	n, err := h.svc.rdb.Incr(ctx, key).Result()
	if err == nil && n == 1 {
		err = h.svc.rdb.Expire(ctx, key, time.Minute).Err()
	}
	if err != nil {
		h.fail(w, r, err)
		return
	}
	if n > addressSearchPerMinute {
		httpx.Error(w, http.StatusTooManyRequests, "too_many_requests", "Tìm quá nhiều lần liên tiếp, đợi 1 phút rồi thử lại.")
		return
	}
	res, err := h.geo.Search(ctx, q)
	if errors.Is(err, geo.ErrUnavailable) {
		httpx.Error(w, http.StatusBadGateway, "address_search_unavailable", addressSearchDown)
		return
	}
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, res)
}

const addressSearchDown = "Không kết nối được dịch vụ tìm địa chỉ. Thử lại sau, hoặc bấm thẳng vào bản đồ / dán tọa độ."

func (h *Handler) setBranchActive(active bool) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		id, ok := idParam(w, r)
		if !ok {
			return
		}
		b, err := h.svc.SetBranchActive(r.Context(), actorOf(r), id, active)
		if err != nil {
			h.fail(w, r, err)
			return
		}
		httpx.JSON(w, http.StatusOK, b)
	}
}

func (h *Handler) catalog(w http.ResponseWriter, r *http.Request) {
	httpx.JSON(w, http.StatusOK, GetCatalog())
}

func (h *Handler) listRoles(w http.ResponseWriter, r *http.Request) {
	list, err := h.svc.ListRoles(r.Context())
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, map[string]any{"roles": list})
}

func (h *Handler) updateRole(w http.ResponseWriter, r *http.Request) {
	var req struct {
		Label      string `json:"label"`
		ShortLabel string `json:"short_label"`
	}
	if !httpx.Decode(w, r, &req) {
		return
	}
	if err := h.svc.UpdateRoleLabels(r.Context(), actorOf(r), chi.URLParam(r, "name"), req.Label, req.ShortLabel); err != nil {
		h.fail(w, r, err)
		return
	}
	w.WriteHeader(http.StatusNoContent)
}

func (h *Handler) setRolePermissions(w http.ResponseWriter, r *http.Request) {
	var req struct {
		Permissions []string `json:"permissions"`
	}
	if !httpx.Decode(w, r, &req) {
		return
	}
	perms, err := h.svc.SetRolePermissions(r.Context(), actorOf(r), chi.URLParam(r, "name"), req.Permissions)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, map[string]any{"permissions": perms})
}

func (h *Handler) listUsers(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()
	page, _ := strconv.Atoi(q.Get("page"))
	list, total, err := h.svc.ListUsers(r.Context(), actorOf(r), UserFilter{
		Search: q.Get("q"), Role: q.Get("role"), Status: q.Get("status"), Page: page,
	})
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, map[string]any{"users": list, "total": total, "page_size": usersPageSize})
}

func (h *Handler) userOptions(w http.ResponseWriter, r *http.Request) {
	opts, err := h.svc.UserOptions(r.Context(), actorOf(r))
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, opts)
}

func (h *Handler) createUser(w http.ResponseWriter, r *http.Request) {
	var in UserInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	u, password, err := h.svc.CreateManagedUser(r.Context(), actorOf(r), in)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusCreated, map[string]any{"user": u, "temporary_password": password})
}

func (h *Handler) getUser(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	if !ok {
		return
	}
	u, err := h.svc.GetUserDetail(r.Context(), actorOf(r), id)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, u)
}

func (h *Handler) updateUser(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	var in UserInput
	if !ok || !httpx.Decode(w, r, &in) {
		return
	}
	u, err := h.svc.UpdateManagedUser(r.Context(), actorOf(r), id, in)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, u)
}

func (h *Handler) setUserRoles(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	var req struct {
		Roles []string `json:"roles"`
	}
	if !ok || !httpx.Decode(w, r, &req) {
		return
	}
	u, err := h.svc.SetUserRoles(r.Context(), actorOf(r), id, req.Roles)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, u)
}

func (h *Handler) setUserLocked(locked bool) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		id, ok := idParam(w, r)
		if !ok {
			return
		}
		if err := h.svc.SetUserLocked(r.Context(), actorOf(r), id, locked); err != nil {
			h.fail(w, r, err)
			return
		}
		w.WriteHeader(http.StatusNoContent)
	}
}

func (h *Handler) resetUserPassword(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	if !ok {
		return
	}
	password, err := h.svc.ResetManagedPassword(r.Context(), actorOf(r), id)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, map[string]string{"temporary_password": password})
}

func (h *Handler) resetUserTOTP(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	if !ok {
		return
	}
	if err := h.svc.ResetManagedTOTP(r.Context(), actorOf(r), id); err != nil {
		h.fail(w, r, err)
		return
	}
	w.WriteHeader(http.StatusNoContent)
}

func (h *Handler) getUserPermissions(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	if !ok {
		return
	}
	out, err := h.svc.GetUserPermissions(r.Context(), actorOf(r), id)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, out)
}

func (h *Handler) setUserPermissions(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	var req struct {
		Overrides []Override `json:"overrides"`
	}
	if !ok || !httpx.Decode(w, r, &req) {
		return
	}
	out, err := h.svc.SetUserOverrides(r.Context(), actorOf(r), id, req.Overrides)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, out)
}
