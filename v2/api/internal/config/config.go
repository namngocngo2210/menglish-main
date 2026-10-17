// Package config đọc cấu hình từ biến môi trường. Mọi bí mật đến từ env hoặc Docker secrets, không nằm trong repo.
package config

import (
	"fmt"
	"log/slog"
	"os"
	"strconv"
	"strings"
	"time"
)

type Config struct {
	Env             string // dev | beta | staging | production
	HTTPAddr        string
	DatabaseURL     string
	RedisURL        string
	EncryptionKey   string // APP_ENCRYPTION_KEY: 32 byte base64, mã hóa khóa TOTP và dữ liệu nhạy cảm
	PublicURL       string // PUBLIC_URL: địa chỉ hệ thống, gửi kèm khi gọi dịch vụ ngoài (Nominatim yêu cầu định danh)
	LogLevel        slog.Level
	ShutdownTimeout time.Duration
	// MAIL_BLOCK_OUTGOING: chặn mọi email gửi ra ngoài, kể cả SMTP lưu trong DB. Mặc định bật khi không phải
	// production (beta / staging chạy trên bản sao dữ liệu thật: email phụ huynh, nhân sự).
	MailBlockOutgoing bool
}

func Load() (Config, error) {
	c := Config{
		Env:             get("MENGLISH_ENV", "dev"),
		HTTPAddr:        get("HTTP_ADDR", ":8080"),
		DatabaseURL:     os.Getenv("DATABASE_URL"),
		RedisURL:        os.Getenv("REDIS_URL"),
		EncryptionKey:   os.Getenv("APP_ENCRYPTION_KEY"),
		PublicURL:       os.Getenv("PUBLIC_URL"),
		ShutdownTimeout: 15 * time.Second,
	}
	if err := c.LogLevel.UnmarshalText([]byte(get("LOG_LEVEL", "info"))); err != nil {
		return c, fmt.Errorf("LOG_LEVEL: %w", err)
	}
	block, err := parseBool(get("MAIL_BLOCK_OUTGOING", strconv.FormatBool(c.Env != "production")))
	if err != nil {
		return c, fmt.Errorf("MAIL_BLOCK_OUTGOING: %w", err)
	}
	c.MailBlockOutgoing = block
	if c.DatabaseURL == "" {
		return c, fmt.Errorf("thiếu DATABASE_URL")
	}
	return c, nil
}

func parseBool(s string) (bool, error) {
	switch strings.ToLower(strings.TrimSpace(s)) {
	case "1", "true", "yes", "on":
		return true, nil
	case "0", "false", "no", "off":
		return false, nil
	}
	return false, fmt.Errorf("giá trị %q không phải true / false", s)
}

func get(key, def string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return def
}
