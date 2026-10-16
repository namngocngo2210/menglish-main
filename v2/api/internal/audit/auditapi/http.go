package auditapi

import (
	"encoding/csv"
	"fmt"
	"log/slog"
	"net/http"
	"net/url"
	"strconv"
	"strings"

	"github.com/go-chi/chi/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/httpx"
)

type Handler struct {
	svc *Service
	log *slog.Logger
}

func NewHandler(svc *Service, log *slog.Logger) *Handler { return &Handler{svc: svc, log: log} }

// Routes: /activity-logs (quyền activity_log.view như route activity-logs.* của Laravel).
func (h *Handler) Routes(r chi.Router) {
	r.Route("/activity-logs", func(r chi.Router) {
		r.Use(iam.Require("activity_log.view"))
		r.Get("/", h.list)
		r.Get("/options", h.options)
		r.Get("/export", h.export)
	})
}

func filterFrom(q url.Values) Filter {
	page, _ := strconv.Atoi(q.Get("page"))
	perPage, _ := strconv.Atoi(q.Get("per_page"))
	return Filter{
		Module: q.Get("module"), Group: q.Get("group"), Event: q.Get("event"), ActorID: q.Get("actor_id"),
		DateFrom: q.Get("date_from"), DateTo: q.Get("date_to"), Search: q.Get("q"), Page: page, PerPage: perPage,
	}
}

func (h *Handler) list(w http.ResponseWriter, r *http.Request) {
	page, err := h.svc.List(r.Context(), iam.AccessFrom(r.Context()), filterFrom(r.URL.Query()))
	if err != nil {
		iam.Fail(w, r, h.log, err)
		return
	}
	httpx.JSON(w, http.StatusOK, page)
}

func (h *Handler) options(w http.ResponseWriter, r *http.Request) {
	opts, err := h.svc.Options(r.Context(), iam.AccessFrom(r.Context()))
	if err != nil {
		iam.Fail(w, r, h.log, err)
		return
	}
	httpx.JSON(w, http.StatusOK, opts)
}

// export: CSV UTF-8 có BOM (mở thẳng bằng Excel), cột như bản xuất của Laravel.
func (h *Handler) export(w http.ResponseWriter, r *http.Request) {
	logs, err := h.svc.Export(r.Context(), iam.AccessFrom(r.Context()), filterFrom(r.URL.Query()))
	if err != nil {
		iam.Fail(w, r, h.log, err)
		return
	}
	name := "nhat-ky-van-hanh-" + h.svc.now().In(vnZone).Format("20060102-150405") + ".csv"
	w.Header().Set("Content-Type", "text/csv; charset=utf-8")
	w.Header().Set("Content-Disposition", `attachment; filename="`+name+`"`)
	_, _ = w.Write([]byte("\xEF\xBB\xBF"))
	cw := csv.NewWriter(w)
	_ = cw.Write([]string{"ID", "Thời điểm", "Người thực hiện", "Mã", "Phân hệ", "Hành động", "Nội dung", "Đối tượng", "Trường thay đổi (trước → sau)", "IP"})
	for _, l := range logs {
		var diff []string
		for _, d := range l.Diff {
			diff = append(diff, fmt.Sprintf("%s: %s → %s", d.Field, d.Old, d.New))
		}
		_ = cw.Write([]string{
			l.ID.String(), l.CreatedAt.In(vnZone).Format("02/01/2006 15:04:05"), deref(l.ActorName, "Hệ thống tự động"),
			deref(l.ActorCode, ""), l.Module, audit.EventLabel(l.Event), csvSafe(l.Description), csvSafe(deref(l.Subject, "")),
			csvSafe(strings.Join(diff, "; ")), deref(l.IP, ""),
		})
	}
	cw.Flush()
}

func deref(s *string, def string) string {
	if s == nil {
		return def
	}
	return *s
}

// csvSafe chặn công thức Excel (ô bắt đầu bằng = + - @) trong dữ liệu người dùng nhập.
func csvSafe(s string) string {
	if s != "" && strings.ContainsRune("=+-@", rune(s[0])) {
		return "'" + s
	}
	return s
}
