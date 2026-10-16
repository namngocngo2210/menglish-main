// Một binary cho mọi vai trò: menglish api | migrate <lệnh goose> | user <lệnh> | import-legacy | healthcheck | version.
package main

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"

	"github.com/jackc/pgx/v5/pgxpool"
	"github.com/redis/go-redis/v9"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit/auditapi"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/catalogs"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/config"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/geo"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/inventory"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/notify/notifyapi"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/placement"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/secretbox"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/search"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/server"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/store"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/students"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/tasks"
)

// Gắn lúc build: -ldflags "-X main.version=<commit>".
var version = "dev"

func main() {
	if len(os.Args) < 2 {
		usage()
	}
	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()

	var err error
	switch os.Args[1] {
	case "api":
		err = runAPI(ctx)
	case "migrate":
		err = runMigrate(ctx, os.Args[2:])
	case "user":
		err = runUser(ctx, os.Args[2:])
	case "import-legacy":
		err = runImportLegacy(ctx, os.Args[2:])
	case "healthcheck":
		err = healthcheck()
	case "version":
		fmt.Println(version)
	default:
		usage()
	}
	if err != nil {
		fmt.Fprintln(os.Stderr, "lỗi:", err)
		os.Exit(1)
	}
}

func usage() {
	fmt.Fprintln(os.Stderr, "Dùng: menglish api | migrate <up|down|status|...> | user <create|set-roles|reset-password> | import-legacy [--from DSN] [--only ...] [--dry-run] | healthcheck | version")
	os.Exit(2)
}

// deps mở Postgres, Redis, khóa mã hóa và dựng dịch vụ iam (dùng chung cho api và lệnh user).
type deps struct {
	cfg  config.Config
	log  *slog.Logger
	pool *pgxpool.Pool
	rdb  *redis.Client
	box  *secretbox.Box
	iam  *iam.Service
}

func openDeps(ctx context.Context) (*deps, error) {
	cfg, err := config.Load()
	if err != nil {
		return nil, err
	}
	log := slog.New(slog.NewJSONHandler(os.Stdout, &slog.HandlerOptions{Level: cfg.LogLevel}))
	box, err := secretbox.FromBase64(cfg.EncryptionKey)
	if err != nil {
		return nil, err
	}
	pool, err := store.OpenPostgres(ctx, cfg.DatabaseURL)
	if err != nil {
		return nil, err
	}
	rdb, err := store.OpenRedis(cfg.RedisURL)
	if err != nil {
		pool.Close()
		return nil, err
	}
	return &deps{cfg: cfg, log: log, pool: pool, rdb: rdb, box: box, iam: iam.NewService(pool, rdb, box, log)}, nil
}

func (d *deps) close() {
	d.rdb.Close()
	d.pool.Close()
}

func runAPI(ctx context.Context) error {
	d, err := openDeps(ctx)
	if err != nil {
		return err
	}
	defer d.close()
	cfg, log := d.cfg, d.log

	inv := inventory.NewService(d.pool, log, inventory.DefaultConfig())
	go inv.RunOverdueLoop(ctx, 15*time.Minute)
	auth := iam.NewHandler(d.iam, log).WithAddressSearch(geo.NewSearcher(d.rdb, log, cfg.PublicURL))
	taskSvc := tasks.NewService(d.pool, log)
	// Chuyển việc đã qua hạn sang "Quá hạn" (lệnh tasks:mark-overdue chạy theo lịch ở bản Laravel).
	go taskSvc.RunOverdueLoop(ctx, 10*time.Minute)
	// Leads / Sessions: CRM và lớp học nối vào khi gộp module (hiện dùng bản rỗng).
	place := placement.NewHandler(placement.NewService(d.pool, d.rdb, log, placement.Options{}), log)
	checks := map[string]server.Pinger{
		"postgres": d.pool,
		"redis":    server.PingFunc(func(ctx context.Context) error { return d.rdb.Ping(ctx).Err() }),
	}
	srv := &http.Server{
		Addr: cfg.HTTPAddr,
		Handler: server.New(server.Deps{
			Log: log, Version: version, Env: cfg.Env, Checks: checks,
			API: apiRoutes(auth, platform{
				auditMW: audit.Middleware(d.pool, log, "/api/v1", iam.CurrentUserID),
				audit:   auditapi.NewHandler(auditapi.NewService(d.pool, log), log),
				notify:  notifyapi.NewHandler(notifyapi.NewService(d.pool), log),
				// Module mới có nhóm tìm kiếm thì thêm: search.Builtin(d.iam).Add(<module>.Searcher(...)).
				search: search.NewHandler(search.Builtin(d.iam), log),
			},
				students.NewHandler(students.NewService(d.pool, d.iam, log), log),
				inventory.NewHandler(inv, log),
				catalogs.NewHandler(catalogs.NewService(d.pool, d.box, log), log),
				tasks.NewHandler(taskSvc, log),
				place,
			),
		}),
		ReadHeaderTimeout: 10 * time.Second,
		ReadTimeout:       60 * time.Second,
		IdleTimeout:       120 * time.Second,
	}
	errc := make(chan error, 1)
	go func() {
		log.Info("api started", "addr", cfg.HTTPAddr, "env", cfg.Env, "version", version)
		errc <- srv.ListenAndServe()
	}()

	select {
	case err := <-errc:
		return err
	case <-ctx.Done():
	}
	log.Info("shutting down")
	shutdownCtx, cancel := context.WithTimeout(context.Background(), cfg.ShutdownTimeout)
	defer cancel()
	if err := srv.Shutdown(shutdownCtx); err != nil && !errors.Is(err, http.ErrServerClosed) {
		return err
	}
	return nil
}

// runMigrate chạy goose; sau "up" thì đồng bộ danh mục quyền và 9 vai trò, rồi bỏ cache quyền của mọi người.
func runMigrate(ctx context.Context, args []string) error {
	if len(args) == 0 {
		args = []string{"up"}
	}
	cfg, err := config.Load()
	if err != nil {
		return err
	}
	pool, err := store.OpenPostgres(ctx, cfg.DatabaseURL)
	if err != nil {
		return err
	}
	defer pool.Close()
	if err := store.Migrate(ctx, pool, args[0], args[1:]...); err != nil || args[0] != "up" {
		return err
	}
	rep, err := iam.SyncCatalog(ctx, pool)
	if err != nil {
		return fmt.Errorf("đồng bộ danh mục quyền: %w", err)
	}
	fmt.Printf("Danh mục quyền: %d quyền; vai trò mới: %v; quyền đã bỏ: %v\n", rep.Permissions, rep.CreatedRoles, rep.RemovedPermissions)
	nSLA, err := catalogs.SyncSLA(ctx, pool)
	if err != nil {
		return fmt.Errorf("đồng bộ danh mục SLA: %w", err)
	}
	fmt.Printf("Danh mục SLA: %d luật\n", nSLA)
	if cfg.RedisURL == "" {
		return nil
	}
	rdb, err := store.OpenRedis(cfg.RedisURL)
	if err != nil {
		return err
	}
	defer rdb.Close()
	return iam.InvalidateAllAccess(ctx, rdb)
}

// healthcheck dùng cho HEALTHCHECK của Docker: image distroless không có curl.
func healthcheck() error {
	addr := os.Getenv("HTTP_ADDR")
	if addr == "" || addr[0] == ':' {
		addr = "127.0.0.1" + addr
		if addr == "127.0.0.1" {
			addr += ":8080"
		}
	}
	client := http.Client{Timeout: 3 * time.Second}
	resp, err := client.Get("http://" + addr + "/healthz")
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return fmt.Errorf("healthz trả về %d", resp.StatusCode)
	}
	return nil
}
