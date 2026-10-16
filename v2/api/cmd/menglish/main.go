// Một binary cho mọi vai trò: menglish api | migrate <lệnh goose> | healthcheck | version.
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

	"github.com/namngocngo2210/menglish-main/v2/api/internal/config"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/server"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/store"
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
	fmt.Fprintln(os.Stderr, "Dùng: menglish api | migrate <up|down|status|...> | healthcheck | version")
	os.Exit(2)
}

func runAPI(ctx context.Context) error {
	cfg, err := config.Load()
	if err != nil {
		return err
	}
	log := slog.New(slog.NewJSONHandler(os.Stdout, &slog.HandlerOptions{Level: cfg.LogLevel}))

	pool, err := store.OpenPostgres(ctx, cfg.DatabaseURL)
	if err != nil {
		return err
	}
	defer pool.Close()
	checks := map[string]server.Pinger{"postgres": pool}

	rdb, err := store.OpenRedis(cfg.RedisURL)
	if err != nil {
		return err
	}
	if rdb != nil {
		defer rdb.Close()
		checks["redis"] = server.PingFunc(func(ctx context.Context) error { return rdb.Ping(ctx).Err() })
	}

	srv := &http.Server{
		Addr:              cfg.HTTPAddr,
		Handler:           server.New(server.Deps{Log: log, Version: version, Env: cfg.Env, Checks: checks}),
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
	return store.Migrate(ctx, pool, args[0], args[1:]...)
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
