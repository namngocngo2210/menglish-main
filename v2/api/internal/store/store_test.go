package store

import (
	"context"
	"os"
	"testing"
)

// Chạy với Postgres thật khi có TEST_DATABASE_URL (CI đặt sẵn); máy dev không có thì bỏ qua.
func TestMigrateUpDown(t *testing.T) {
	url := os.Getenv("TEST_DATABASE_URL")
	if url == "" {
		t.Skip("không có TEST_DATABASE_URL")
	}
	ctx := context.Background()
	pool, err := OpenPostgres(ctx, url)
	if err != nil {
		t.Fatal(err)
	}
	defer pool.Close()

	if err := Migrate(ctx, pool, "up"); err != nil {
		t.Fatalf("up: %v", err)
	}
	var n int
	if err := pool.QueryRow(ctx, `SELECT count(*) FROM pg_namespace WHERE nspname IN
		('core','iam','crm','placement','academic','syllabus','finance','hr','payroll','ops')`).Scan(&n); err != nil {
		t.Fatal(err)
	}
	if n != 10 {
		t.Fatalf("có %d/10 schema sau migrate up", n)
	}
	// Mọi migration phải quay lại được (thêm trước xóa sau), rồi lên lại sạch.
	if err := Migrate(ctx, pool, "reset"); err != nil {
		t.Fatalf("reset: %v", err)
	}
	if err := Migrate(ctx, pool, "up"); err != nil {
		t.Fatalf("up lần 2: %v", err)
	}
}
