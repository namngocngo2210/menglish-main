// Package legacy chạy lệnh `menglish import-legacy`: đọc MySQL của Laravel (chỉ đọc), ghi sang Postgres.
//
// Mỗi phân hệ có hàm LegacySteps() trả các Step; cmd/menglish ghép theo thứ tự phụ thuộc. Step đọc bảng cũ qua Run.Src, ghi bảng mới trong
// Run.Tx và dùng Run.Map / Run.Lookup (bảng legacy.id_map) để chạy lại nhiều lần không tạo trùng: bản ghi đã nhập thì
// cập nhật, bản ghi mới thì thêm. Cả lần chạy nằm trong một transaction: lỗi giữa chừng thì không ghi gì;
// --dry-run chạy hết rồi rollback để xem báo cáo.
package legacy

import (
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"log/slog"
	"slices"
	"strings"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgconn"
	"github.com/jackc/pgx/v5/pgxpool"
)

type Step struct {
	Name string // vd. "branches"; --only nhận tên này; bước sau dùng id_map của bước trước
	Run  func(ctx context.Context, r *Run) error
}

type Run struct {
	Src    *sql.DB
	Tx     pgx.Tx
	Log    *slog.Logger
	report map[string]*Count
	step   string
}

// Count: số bản ghi theo bước; Skipped kèm lý do để người chạy biết dữ liệu nào không mang sang.
type Count struct {
	Inserted int      `json:"inserted"`
	Updated  int      `json:"updated"`
	Skipped  int      `json:"skipped"`
	Notes    []string `json:"notes,omitempty"`
}

// Count: bộ đếm của bước đang chạy (bước tự đếm khi không dùng Upsert).
func (r *Run) Count() *Count { return r.count() }

func (r *Run) count() *Count {
	c := r.report[r.step]
	if c == nil {
		c = &Count{}
		r.report[r.step] = c
	}
	return c
}

// Skip ghi một bản ghi bị bỏ qua và lý do (tối đa 50 lý do mỗi bước để báo cáo gọn).
func (r *Run) Skip(format string, args ...any) {
	c := r.count()
	c.Skipped++
	if len(c.Notes) < 50 {
		c.Notes = append(c.Notes, fmt.Sprintf(format, args...))
	}
}

// Note ghi một lưu ý (dữ liệu bị sửa cho hợp lệ) vào báo cáo của bước, tối đa 50 dòng.
func (r *Run) Note(format string, args ...any) {
	c := r.count()
	if len(c.Notes) < 50 {
		c.Notes = append(c.Notes, fmt.Sprintf(format, args...))
	}
}

// Lookup: uuid mới của bản ghi cũ (entity, id cũ); ok=false nếu chưa nhập.
func (r *Run) Lookup(ctx context.Context, entity string, legacyID int64) (uuid.UUID, bool, error) {
	var id uuid.UUID
	err := r.Tx.QueryRow(ctx, "SELECT new_id FROM legacy.id_map WHERE entity = $1 AND legacy_id = $2", entity, legacyID).Scan(&id)
	if errors.Is(err, pgx.ErrNoRows) {
		return uuid.Nil, false, nil
	}
	return id, err == nil, err
}

// Upsert: nếu bản ghi cũ đã nhập thì gọi update(id) rồi đếm Updated; chưa thì insert() trả uuid mới, lưu id_map, đếm Inserted.
// update trả false khi bản ghi mới đã bị xóa trên v2: khi đó nhập lại như bản ghi mới.
func (r *Run) Upsert(ctx context.Context, entity string, legacyID int64, insert func() (uuid.UUID, error), update func(uuid.UUID) (bool, error)) (uuid.UUID, error) {
	id, ok, err := r.Lookup(ctx, entity, legacyID)
	if err != nil {
		return uuid.Nil, err
	}
	if ok {
		found, err := update(id)
		if err != nil {
			return uuid.Nil, fmt.Errorf("%s #%d: %w", entity, legacyID, err)
		}
		if found {
			r.count().Updated++
			return id, nil
		}
		if _, err := r.Tx.Exec(ctx, "DELETE FROM legacy.id_map WHERE entity = $1 AND legacy_id = $2", entity, legacyID); err != nil {
			return uuid.Nil, err
		}
	}
	id, err = insert()
	if err != nil {
		return uuid.Nil, fmt.Errorf("%s #%d: %w", entity, legacyID, err)
	}
	if _, err := r.Tx.Exec(ctx, "INSERT INTO legacy.id_map (entity, legacy_id, new_id) VALUES ($1, $2, $3)", entity, legacyID, id); err != nil {
		return uuid.Nil, err
	}
	r.count().Inserted++
	return id, nil
}

// Updated: tiện cho hàm update của Upsert — true nếu câu UPDATE chạm ít nhất một dòng.
func Updated(tag pgconn.CommandTag, err error) (bool, error) {
	return err == nil && tag.RowsAffected() > 0, err
}

type Options struct {
	Only   []string // rỗng = mọi bước
	DryRun bool
}

// Import chạy các bước trong một transaction và trả báo cáo theo bước.
func Import(ctx context.Context, src *sql.DB, pool *pgxpool.Pool, log *slog.Logger, steps []Step, opt Options) (map[string]*Count, error) {
	names := make([]string, len(steps))
	for i, s := range steps {
		names[i] = s.Name
	}
	for _, name := range opt.Only {
		if !slices.Contains(names, name) {
			return nil, fmt.Errorf("không có bước %q (có: %s)", name, strings.Join(names, ", "))
		}
	}
	tx, err := pool.Begin(ctx)
	if err != nil {
		return nil, err
	}
	defer tx.Rollback(ctx)
	r := &Run{Src: src, Tx: tx, Log: log, report: map[string]*Count{}}
	for _, s := range steps {
		if len(opt.Only) > 0 && !slices.Contains(opt.Only, s.Name) {
			continue
		}
		r.step = s.Name
		r.count()
		log.Info("import-legacy: bắt đầu", "step", s.Name)
		if err := s.Run(ctx, r); err != nil {
			return r.report, fmt.Errorf("bước %s: %w", s.Name, err)
		}
	}
	data, _ := json.Marshal(r.report)
	if _, err := tx.Exec(ctx, "INSERT INTO legacy.runs (finished_at, dry_run, report) VALUES (now(), $1, $2)", opt.DryRun, data); err != nil {
		return r.report, err
	}
	if opt.DryRun {
		return r.report, nil
	}
	return r.report, tx.Commit(ctx)
}
