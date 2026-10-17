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
	Files             Files
}

// Files: kho file người dùng tải lên (internal/platform/filestore). Mặc định driver local ghi vào FILES_DIR.
type Files struct {
	Driver      string        // FILES_DRIVER: local (mặc định) | s3 (Cloudflare R2 / MinIO)
	Dir         string        // FILES_DIR: thư mục của driver local (mặc định ./data/files)
	URLTTL      time.Duration // FILES_URL_TTL: hạn URL tải file ký sẵn (mặc định 5m)
	S3Endpoint  string        // FILES_S3_ENDPOINT: vd. <account>.r2.cloudflarestorage.com, localhost:9000
	S3Bucket    string        // FILES_S3_BUCKET
	S3AccessKey string        // FILES_S3_ACCESS_KEY
	S3SecretKey string        // FILES_S3_SECRET_KEY
	S3Region    string        // FILES_S3_REGION (R2: auto)
	S3UseSSL    bool          // FILES_S3_USE_SSL (mặc định true; MinIO dev: false)
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
	c.Files = Files{
		Driver: get("FILES_DRIVER", "local"), Dir: get("FILES_DIR", "data/files"), URLTTL: 5 * time.Minute,
		S3Endpoint: os.Getenv("FILES_S3_ENDPOINT"), S3Bucket: os.Getenv("FILES_S3_BUCKET"),
		S3AccessKey: os.Getenv("FILES_S3_ACCESS_KEY"), S3SecretKey: os.Getenv("FILES_S3_SECRET_KEY"),
		S3Region: get("FILES_S3_REGION", "auto"), S3UseSSL: get("FILES_S3_USE_SSL", "true") != "false",
	}
	if v := os.Getenv("FILES_URL_TTL"); v != "" {
		d, err := time.ParseDuration(v)
		if err != nil || d < 30*time.Second || d > 24*time.Hour {
			return c, fmt.Errorf("FILES_URL_TTL không hợp lệ (30s … 24h): %q", v)
		}
		c.Files.URLTTL = d
	}
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
