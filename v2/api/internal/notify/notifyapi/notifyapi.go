// Package notifyapi là hộp thông báo của người dùng (Laravel AdminNotificationController + chuông trên topbar):
// danh sách có lọc, số chưa đọc, đánh dấu đã đọc. Tách khỏi notify vì cần iam (đăng nhập, quyền).
package notifyapi

import (
	"context"
	"encoding/json"
	"log/slog"
	"net/http"
	"strconv"
	"time"

	"github.com/go-chi/chi/v5"
	"github.com/google/uuid"
	"github.com/jackc/pgx/v5/pgxpool"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/notify"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/notify/notifydb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/httpx"
)

const (
	defaultPerPage = 15 // như $request->perPage(15)
	maxPerPage     = 100
	summarySize    = 6 // chuông hiện 6 thông báo mới nhất (AppShell::notificationMenu)
)

var errNotFound = &iam.Error{Status: http.StatusNotFound, Code: "not_found", Message: "Không tìm thấy thông báo."}

type Service struct {
	q *notifydb.Queries
}

func NewService(pool *pgxpool.Pool) *Service { return &Service{q: notifydb.New(pool)} }

// Item: một thông báo trên màn hình.
type Item struct {
	ID        uuid.UUID      `json:"id"`
	Type      string         `json:"type"`
	TypeLabel string         `json:"type_label"`
	Icon      string         `json:"icon"`
	Tone      string         `json:"tone"`
	Title     string         `json:"title"`
	Body      string         `json:"body"`
	Link      *string        `json:"link"`
	Data      map[string]any `json:"data"`
	IsRead    bool           `json:"is_read"`
	ReadAt    *time.Time     `json:"read_at"`
	CreatedAt time.Time      `json:"created_at"`
}

type Stats struct {
	Total  int64 `json:"total"`
	Unread int64 `json:"unread"`
	// Cảnh báo lead tồn đọng chưa đọc (notify.AlertTypes)
	Alerts int64 `json:"alerts"`
}

type Page struct {
	Notifications []Item              `json:"notifications"`
	Total         int64               `json:"total"`
	Page          int                 `json:"page"`
	PerPage       int                 `json:"per_page"`
	Stats         Stats               `json:"stats"`
	Types         []notify.TypeOption `json:"types"`
}

type Filter struct {
	Type    string
	Unread  bool
	Page    int
	PerPage int
}

func toItem(id uuid.UUID, typ, title, body string, link *string, data []byte, readAt *time.Time, created time.Time) Item {
	info := notify.Info(typ)
	it := Item{
		ID: id, Type: typ, TypeLabel: info.Label, Icon: info.Icon, Tone: info.Tone, Title: title, Body: body,
		Link: link, IsRead: readAt != nil, ReadAt: readAt, CreatedAt: created, Data: map[string]any{},
	}
	_ = json.Unmarshal(data, &it.Data)
	return it
}

// List: thông báo của người dùng, mới nhất trước (AdminNotificationController::index).
func (s *Service) List(ctx context.Context, uid uuid.UUID, f Filter) (*Page, error) {
	if f.Page < 1 {
		f.Page = 1
	}
	if f.PerPage < 1 {
		f.PerPage = defaultPerPage
	}
	f.PerPage = min(f.PerPage, maxPerPage)
	var typ *string
	if f.Type != "" {
		typ = &f.Type
	}
	rows, err := s.q.ListNotifications(ctx, notifydb.ListNotificationsParams{
		UserID: uid, Type: typ, UnreadOnly: f.Unread, Lim: int32(f.PerPage), Off: int32((f.Page - 1) * f.PerPage),
	})
	if err != nil {
		return nil, err
	}
	st, err := s.q.NotificationStats(ctx, notifydb.NotificationStatsParams{UserID: uid, AlertTypes: notify.AlertTypes})
	if err != nil {
		return nil, err
	}
	p := &Page{
		Notifications: make([]Item, 0, len(rows)), Page: f.Page, PerPage: f.PerPage,
		Stats: Stats{st.Total, st.Unread, st.Alerts}, Types: notify.Types(),
	}
	for _, r := range rows {
		p.Total = r.Total
		p.Notifications = append(p.Notifications, toItem(r.ID, r.Type, r.Title, r.Body, r.Link, r.Data, r.ReadAt, r.CreatedAt))
	}
	if len(rows) == 0 && f.Page > 1 {
		// Trang vượt quá: vẫn trả tổng đúng để giao diện quay về trang cuối.
		count, err := s.q.ListNotifications(ctx, notifydb.ListNotificationsParams{UserID: uid, Type: typ, UnreadOnly: f.Unread, Lim: 1})
		if err != nil {
			return nil, err
		}
		if len(count) > 0 {
			p.Total = count[0].Total
		}
	}
	return p, nil
}

// Summary: số chưa đọc và vài thông báo mới nhất cho chuông (AdminNotificationController::dropdown).
type Summary struct {
	Unread int64  `json:"unread"`
	Items  []Item `json:"items"`
}

func (s *Service) Summary(ctx context.Context, uid uuid.UUID) (*Summary, error) {
	unread, err := s.q.CountUnread(ctx, uid)
	if err != nil {
		return nil, err
	}
	rows, err := s.q.ListNotifications(ctx, notifydb.ListNotificationsParams{UserID: uid, Lim: summarySize})
	if err != nil {
		return nil, err
	}
	out := &Summary{Unread: unread, Items: make([]Item, 0, len(rows))}
	for _, r := range rows {
		out.Items = append(out.Items, toItem(r.ID, r.Type, r.Title, r.Body, r.Link, r.Data, r.ReadAt, r.CreatedAt))
	}
	return out, nil
}

// MarkRead: chỉ thông báo của chính mình; id của người khác trả 404 như không tồn tại.
func (s *Service) MarkRead(ctx context.Context, uid, id uuid.UUID) (int64, error) {
	n, err := s.q.MarkNotificationRead(ctx, notifydb.MarkNotificationReadParams{ID: id, UserID: uid})
	if err != nil {
		return 0, err
	}
	if n == 0 {
		return 0, errNotFound
	}
	return s.q.CountUnread(ctx, uid)
}

func (s *Service) MarkAllRead(ctx context.Context, uid uuid.UUID) (int64, error) {
	return s.q.MarkAllNotificationsRead(ctx, uid)
}

// ───── HTTP ─────

type Handler struct {
	svc *Service
	log *slog.Logger
}

func NewHandler(svc *Service, log *slog.Logger) *Handler { return &Handler{svc: svc, log: log} }

// Routes: /notifications, quyền notification.view như route notifications.* của Laravel. Thông báo luôn là của
// người đang đăng nhập nên không có phạm vi chi nhánh.
func (h *Handler) Routes(r chi.Router) {
	r.Route("/notifications", func(r chi.Router) {
		r.Use(iam.Require("notification.view"))
		r.Get("/", h.list)
		r.Get("/summary", h.summary)
		r.Post("/read-all", h.readAll)
		r.Post("/{id}/read", h.read)
	})
}

func (h *Handler) list(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()
	page, _ := strconv.Atoi(q.Get("page"))
	perPage, _ := strconv.Atoi(q.Get("per_page"))
	unread := q.Get("unread") != "" && q.Get("unread") != "0" && q.Get("unread") != "false"
	out, err := h.svc.List(r.Context(), iam.AccessFrom(r.Context()).UserID, Filter{Type: q.Get("type"), Unread: unread, Page: page, PerPage: perPage})
	if err != nil {
		iam.Fail(w, r, h.log, err)
		return
	}
	httpx.JSON(w, http.StatusOK, out)
}

func (h *Handler) summary(w http.ResponseWriter, r *http.Request) {
	out, err := h.svc.Summary(r.Context(), iam.AccessFrom(r.Context()).UserID)
	if err != nil {
		iam.Fail(w, r, h.log, err)
		return
	}
	httpx.JSON(w, http.StatusOK, out)
}

func (h *Handler) read(w http.ResponseWriter, r *http.Request) {
	id, err := uuid.Parse(chi.URLParam(r, "id"))
	if err != nil {
		iam.Fail(w, r, h.log, errNotFound)
		return
	}
	unread, err := h.svc.MarkRead(r.Context(), iam.AccessFrom(r.Context()).UserID, id)
	if err != nil {
		iam.Fail(w, r, h.log, err)
		return
	}
	httpx.JSON(w, http.StatusOK, map[string]any{"unread": unread})
}

func (h *Handler) readAll(w http.ResponseWriter, r *http.Request) {
	n, err := h.svc.MarkAllRead(r.Context(), iam.AccessFrom(r.Context()).UserID)
	if err != nil {
		iam.Fail(w, r, h.log, err)
		return
	}
	httpx.JSON(w, http.StatusOK, map[string]any{"unread": 0, "updated": n})
}
