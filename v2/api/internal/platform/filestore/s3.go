package filestore

import (
	"context"
	"errors"
	"fmt"
	"io"
	"net/url"
	"time"

	"github.com/minio/minio-go/v7"
	"github.com/minio/minio-go/v7/pkg/credentials"
)

// S3: Cloudflare R2 / MinIO qua API S3. Bucket riêng tư; tải về bằng URL ký sẵn trỏ thẳng tới bucket
// (file không đi qua máy chủ, không cùng tên miền với ứng dụng).
type S3 struct {
	c      *minio.Client
	bucket string
}

// NewS3 kết nối bucket (không tạo bucket: bucket và token quyền chỉ trên bucket đó do người vận hành tạo).
func NewS3(c Config) (*S3, error) {
	if c.S3Endpoint == "" || c.S3Bucket == "" || c.S3AccessKey == "" || c.S3SecretKey == "" {
		return nil, errors.New("FILES_DRIVER=s3 cần FILES_S3_ENDPOINT, FILES_S3_BUCKET, FILES_S3_ACCESS_KEY, FILES_S3_SECRET_KEY")
	}
	region := c.S3Region
	if region == "" {
		region = "auto"
	}
	cl, err := minio.New(c.S3Endpoint, &minio.Options{
		Creds:  credentials.NewStaticV4(c.S3AccessKey, c.S3SecretKey, ""),
		Secure: c.S3UseSSL,
		Region: region,
	})
	if err != nil {
		return nil, fmt.Errorf("kết nối kho S3: %w", err)
	}
	return &S3{c: cl, bucket: c.S3Bucket}, nil
}

func (s *S3) Driver() string { return "s3" }

func (s *S3) Put(ctx context.Context, key string, r io.Reader, size int64, contentType string) error {
	if !ValidKey(key) {
		return fmt.Errorf("filestore: khóa không hợp lệ %q", key)
	}
	_, err := s.c.PutObject(ctx, s.bucket, key, r, size, minio.PutObjectOptions{ContentType: contentType})
	return err
}

func (s *S3) Open(ctx context.Context, key string) (io.ReadCloser, error) {
	if !ValidKey(key) {
		return nil, fmt.Errorf("filestore: khóa không hợp lệ %q", key)
	}
	obj, err := s.c.GetObject(ctx, s.bucket, key, minio.GetObjectOptions{})
	if err != nil {
		return nil, err
	}
	// GetObject chỉ gửi request khi đọc lần đầu: Stat để báo ErrNotFound ngay.
	if _, err := obj.Stat(); err != nil {
		obj.Close()
		if minio.ToErrorResponse(err).Code == "NoSuchKey" {
			return nil, ErrNotFound
		}
		return nil, err
	}
	return obj, nil
}

func (s *S3) Delete(ctx context.Context, key string) error {
	if !ValidKey(key) {
		return fmt.Errorf("filestore: khóa không hợp lệ %q", key)
	}
	return s.c.RemoveObject(ctx, s.bucket, key, minio.RemoveObjectOptions{})
}

// SignedURL: URL ký sẵn (SigV4) kèm Content-Type / Content-Disposition trả về.
func (s *S3) SignedURL(ctx context.Context, key string, o SignOptions) (string, error) {
	if !ValidKey(key) {
		return "", fmt.Errorf("filestore: khóa không hợp lệ %q", key)
	}
	ttl := o.TTL
	if ttl <= 0 {
		ttl = DefaultTTL
	}
	if ttl > 7*24*time.Hour {
		ttl = 7 * 24 * time.Hour // giới hạn của SigV4
	}
	q := url.Values{}
	q.Set("response-content-disposition", Disposition(o.Inline, o.Filename))
	if o.ContentType != "" {
		q.Set("response-content-type", o.ContentType)
	}
	u, err := s.c.PresignedGetObject(ctx, s.bucket, key, ttl, q)
	if err != nil {
		return "", err
	}
	return u.String(), nil
}
