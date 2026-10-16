// Package store mở kết nối PostgreSQL / Redis và chạy migration.
package store

import (
	"context"
	"database/sql"
	"embed"
	"fmt"

	"github.com/jackc/pgx/v5/pgxpool"
	"github.com/jackc/pgx/v5/stdlib"
	"github.com/pressly/goose/v3"
	"github.com/redis/go-redis/v9"
)

//go:embed migrations/*.sql
var migrations embed.FS

func OpenPostgres(ctx context.Context, url string) (*pgxpool.Pool, error) {
	cfg, err := pgxpool.ParseConfig(url)
	if err != nil {
		return nil, fmt.Errorf("DATABASE_URL: %w", err)
	}
	pool, err := pgxpool.NewWithConfig(ctx, cfg)
	if err != nil {
		return nil, err
	}
	return pool, nil
}

// OpenRedis mở Redis (phiên đăng nhập, giới hạn tốc độ).
func OpenRedis(url string) (*redis.Client, error) {
	if url == "" {
		return nil, fmt.Errorf("thiếu REDIS_URL")
	}
	opt, err := redis.ParseURL(url)
	if err != nil {
		return nil, fmt.Errorf("REDIS_URL: %w", err)
	}
	return redis.NewClient(opt), nil
}

// Migrate chạy lệnh goose (up, down, status, ...) trên migration nhúng trong binary.
func Migrate(ctx context.Context, pool *pgxpool.Pool, command string, args ...string) error {
	db := stdlib.OpenDBFromPool(pool)
	defer db.Close()
	return runGoose(ctx, db, command, args...)
}

func runGoose(ctx context.Context, db *sql.DB, command string, args ...string) error {
	goose.SetBaseFS(migrations)
	if err := goose.SetDialect("postgres"); err != nil {
		return err
	}
	// Mỗi phân hệ giữ một dải số migration riêng nên dải thấp có thể gộp sau dải cao: cho phép chạy bù migration
	// còn thiếu thay vì báo lỗi.
	return goose.RunWithOptionsContext(ctx, command, db, "migrations", args, goose.WithAllowMissing())
}
