// Package catalogs: danh mục & tổ chức (giai đoạn 1): khóa học & bảng giá, trình độ, phòng học & loại phòng,
// khung giờ ca dạy, ngày nghỉ lễ, danh mục hệ thống, cấu hình SLA và các màn cấu hình hệ thống (tài khoản ngân hàng,
// nhắc nợ học phí, email nhận ticket). Bám theo các controller tương ứng của bản Laravel.
package catalogs

import (
	"context"
	"errors"
	"log/slog"
	"strings"
	"unicode/utf8"

	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgconn"
	"github.com/jackc/pgx/v5/pgxpool"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/catalogs/catalogsdb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/secretbox"
)

type Service struct {
	pool *pgxpool.Pool
	q    *catalogsdb.Queries
	box  *secretbox.Box
	log  *slog.Logger
	// Classes: phân hệ Lớp học (đếm lớp, phòng đang dùng, xếp lại lịch theo ngày nghỉ); nil = chưa có lớp nào.
	Classes ClassHooks
	// Mail: bộ gửi email cho nút "Gửi thử" của màn Email nhận Ticket; nil = chưa có bộ gửi.
	Mail MailTester
}

func NewService(pool *pgxpool.Pool, box *secretbox.Box, log *slog.Logger) *Service {
	return &Service{pool: pool, q: catalogsdb.New(pool), box: box, log: log}
}

// Page là một trang danh sách.
type Page[T any] struct {
	Items    []T   `json:"items"`
	Total    int64 `json:"total"`
	Page     int   `json:"page"`
	PageSize int   `json:"page_size"`
}

func newPage[T any](items []T, total int64, page, size int) Page[T] {
	if items == nil {
		items = []T{}
	}
	return Page[T]{Items: items, Total: total, Page: page, PageSize: size}
}

// limits đổi số trang (từ 1) thành LIMIT / OFFSET.
func limits(page, size int) (int, int32, int32) {
	if page < 1 {
		page = 1
	}
	return page, int32(size), int32((page - 1) * size)
}

func notFound(msg string) *iam.Error { return &iam.Error{Status: 404, Code: "not_found", Message: msg} }

func conflict(msg string) *iam.Error { return &iam.Error{Status: 409, Code: "conflict", Message: msg} }

// likeArg: chuỗi tìm kiếm cho ILIKE (đã thoát % _ \), nil nếu rỗng.
func likeArg(s string) *string {
	s = strings.TrimSpace(s)
	if s == "" {
		return nil
	}
	if utf8.RuneCountInString(s) > 100 {
		s = string([]rune(s)[:100])
	}
	r := strings.NewReplacer(`\`, `\\`, `%`, `\%`, `_`, `\_`)
	out := r.Replace(s)
	return &out
}

// optText: chuỗi đã cắt khoảng trắng, nil nếu rỗng.
func optText(s string) *string {
	s = strings.TrimSpace(s)
	if s == "" {
		return nil
	}
	return &s
}

func tooLong(s string, max int) bool { return utf8.RuneCountInString(s) > max }

func deref[T any](p *T) T {
	var zero T
	if p == nil {
		return zero
	}
	return *p
}

// isUnique: lỗi trùng khóa duy nhất của Postgres (kèm tên ràng buộc).
func isUnique(err error, constraint string) bool {
	var pg *pgconn.PgError
	return errors.As(err, &pg) && pg.Code == "23505" && (constraint == "" || pg.ConstraintName == constraint)
}

func isNoRows(err error) bool { return errors.Is(err, pgx.ErrNoRows) }

// tx chạy fn trong một giao dịch.
func (s *Service) tx(ctx context.Context, fn func(q *catalogsdb.Queries) error) error {
	return pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error { return fn(s.q.WithTx(tx)) })
}
