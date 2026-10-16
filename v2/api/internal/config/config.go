// Package config đọc cấu hình từ biến môi trường. Mọi bí mật đến từ env hoặc Docker secrets, không nằm trong repo.
package config

import (
	"fmt"
	"log/slog"
	"os"
	"time"
)

type Config struct {
	Env             string // dev | beta | staging | production
	HTTPAddr        string
	DatabaseURL     string
	RedisURL        string
	LogLevel        slog.Level
	ShutdownTimeout time.Duration
}

func Load() (Config, error) {
	c := Config{
		Env:             get("MENGLISH_ENV", "dev"),
		HTTPAddr:        get("HTTP_ADDR", ":8080"),
		DatabaseURL:     os.Getenv("DATABASE_URL"),
		RedisURL:        os.Getenv("REDIS_URL"),
		ShutdownTimeout: 15 * time.Second,
	}
	if err := c.LogLevel.UnmarshalText([]byte(get("LOG_LEVEL", "info"))); err != nil {
		return c, fmt.Errorf("LOG_LEVEL: %w", err)
	}
	if c.DatabaseURL == "" {
		return c, fmt.Errorf("thiếu DATABASE_URL")
	}
	return c, nil
}

func get(key, def string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return def
}
