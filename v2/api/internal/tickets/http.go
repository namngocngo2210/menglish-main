package tickets

import (
	"encoding/json"
	"errors"
	"log/slog"
	"mime"
	"net/http"
	"slices"
	"strconv"

	"github.com/go-chi/chi/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/filestore"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/httpx"
)

type Handler struct {
	svc *Service
	log *slog.Logger
}

func NewHandler(svc *Service, log *slog.Logger) *Handler { return &Handler{svc: svc, log: log} }

// Routes gắn API ticket vào /tickets, quyền theo routes/web.php của Laravel: xem / phản hồi / mở lại cần
// support_ticket.view, tạo cần support_ticket.create, đổi trạng thái cần support_ticket.close, phân công cần
// support_ticket.assign. Ai được xem ticket nào (người tạo / người được giao / người xử lý) do service kiểm.
func (h *Handler) Routes(r chi.Router) {
	view := iam.Require(PermView)
	r.Route("/tickets", func(r chi.Router) {
		r.With(view).Get("/", h.list)
		r.With(iam.Require(PermCreate)).Get("/form", h.form)
		r.With(iam.Require(PermCreate)).Post("/", h.create)
		r.With(view).Get("/{id}", h.show)
		r.With(view).Post("/{id}/messages", h.message)
		r.With(view).Post("/{id}/reopen", h.reopen)
		r.With(iam.Require(PermClose)).Post("/{id}/status", h.status)
		r.With(iam.Require(PermAssign)).Post("/{id}/assign", h.assign)
	})
}

func (h *Handler) fail(w http.ResponseWriter, r *http.Request, err error) { iam.Fail(w, r, h.log, err) }

func actor(r *http.Request) *iam.Access { return iam.AccessFrom(r.Context()) }

func (h *Handler) list(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()
	page, _ := strconv.Atoi(q.Get("page"))
	per, _ := strconv.Atoi(q.Get("per_page"))
	res, err := h.svc.List(r.Context(), actor(r), ListFilter{Status: q.Get("status"), Category: q.Get("category"),
		Search: q.Get("q"), Page: page, PerPage: per})
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, res)
}

func (h *Handler) form(w http.ResponseWriter, r *http.Request) {
	res, err := h.svc.FormOptions(r.Context(), actor(r))
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, res)
}

func (h *Handler) show(w http.ResponseWriter, r *http.Request) {
	res, err := h.svc.Show(r.Context(), actor(r), chi.URLParam(r, "id"))
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, res)
}

// input đọc JSON, hoặc multipart gồm trường payload (JSON) + các file đính kèm (mỗi trường một file, tối đa 4,
// thứ tự theo tên trường: attachments[0], attachments[1]…). done() xóa file tạm.
func (h *Handler) input(w http.ResponseWriter, r *http.Request, v any) ([]*filestore.Upload, func(), bool) {
	if mt, _, _ := mime.ParseMediaType(r.Header.Get("Content-Type")); mt != "multipart/form-data" {
		return nil, func() {}, httpx.Decode(w, r, v)
	}
	form, err := filestore.ReadMultipart(w, r, AttachmentPolicy.MaxBytes, AttachmentPolicy.TooLarge)
	if err != nil {
		var ve *filestore.ValidationError
		if errors.As(err, &ve) {
			err = iam.Invalid(ve.Message)
		}
		h.fail(w, r, err)
		return nil, nil, false
	}
	if err := json.Unmarshal([]byte(form.Value("payload")), v); err != nil {
		form.Close()
		h.fail(w, r, iam.Invalid("Dữ liệu gửi lên không hợp lệ."))
		return nil, nil, false
	}
	names := make([]string, 0, len(form.Files))
	for name := range form.Files {
		names = append(names, name)
	}
	slices.Sort(names)
	ups := make([]*filestore.Upload, 0, len(names))
	for _, name := range names {
		ups = append(ups, form.Files[name])
	}
	return ups, form.Close, true
}

func (h *Handler) create(w http.ResponseWriter, r *http.Request) {
	var in CreateInput
	ups, done, ok := h.input(w, r, &in)
	if !ok {
		return
	}
	defer done()
	res, err := h.svc.Create(r.Context(), actor(r), in, ups)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	code := http.StatusCreated
	if res.Duplicate {
		code = http.StatusOK
	}
	httpx.JSON(w, code, res)
}

func (h *Handler) message(w http.ResponseWriter, r *http.Request) {
	var in MessageInput
	ups, done, ok := h.input(w, r, &in)
	if !ok {
		return
	}
	defer done()
	res, err := h.svc.AddMessage(r.Context(), actor(r), chi.URLParam(r, "id"), in, ups)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, res)
}

func (h *Handler) status(w http.ResponseWriter, r *http.Request) {
	var in struct {
		Status string `json:"status"`
	}
	if !httpx.Decode(w, r, &in) {
		return
	}
	res, err := h.svc.SetStatus(r.Context(), actor(r), chi.URLParam(r, "id"), in.Status)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, res)
}

func (h *Handler) reopen(w http.ResponseWriter, r *http.Request) {
	res, err := h.svc.Reopen(r.Context(), actor(r), chi.URLParam(r, "id"))
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, res)
}

func (h *Handler) assign(w http.ResponseWriter, r *http.Request) {
	var in struct {
		AssigneeID string `json:"assignee_id"`
	}
	if !httpx.Decode(w, r, &in) {
		return
	}
	res, err := h.svc.Assign(r.Context(), actor(r), chi.URLParam(r, "id"), in.AssigneeID)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, res)
}
