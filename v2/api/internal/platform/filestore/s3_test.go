package filestore

import (
	"bytes"
	"context"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"net/url"
	"strings"
	"sync"
	"testing"
	"time"
)

// fakeS3: máy chủ S3 tối thiểu (PUT / GET / HEAD / DELETE một đối tượng) để test driver S3 không cần MinIO.
type fakeS3 struct {
	mu   sync.Mutex
	objs map[string][]byte
}

func (f *fakeS3) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	f.mu.Lock()
	defer f.mu.Unlock()
	if r.Header.Get("Authorization") == "" {
		http.Error(w, "unsigned", http.StatusForbidden)
		return
	}
	switch r.Method {
	case http.MethodPut:
		b, _ := io.ReadAll(r.Body)
		if strings.HasPrefix(r.Header.Get("X-Amz-Content-Sha256"), "STREAMING-") {
			b = unchunk(b) // http thường: minio-go gửi aws-chunked (https thì không)
		}
		f.objs[r.URL.Path] = b
		w.Header().Set("ETag", `"etag"`)
	case http.MethodGet, http.MethodHead:
		b, ok := f.objs[r.URL.Path]
		if !ok {
			w.Header().Set("Content-Type", "application/xml")
			w.WriteHeader(http.StatusNotFound)
			if r.Method == http.MethodGet {
				fmt.Fprint(w, `<?xml version="1.0" encoding="UTF-8"?><Error><Code>NoSuchKey</Code><Message>no</Message></Error>`)
			}
			return
		}
		w.Header().Set("ETag", `"etag"`)
		w.Header().Set("Last-Modified", time.Now().UTC().Format(http.TimeFormat))
		w.Header().Set("Content-Length", fmt.Sprint(len(b)))
		if r.Method == http.MethodGet {
			w.Write(b)
		}
	case http.MethodDelete:
		delete(f.objs, r.URL.Path)
		w.WriteHeader(http.StatusNoContent)
	}
}

// unchunk: bỏ khung aws-chunked "<size hex>;chunk-signature=...\r\n<data>\r\n".
func unchunk(b []byte) []byte {
	var out []byte
	for len(b) > 0 {
		line, rest, ok := bytes.Cut(b, []byte("\r\n"))
		if !ok {
			break
		}
		var n int
		fmt.Sscanf(string(line), "%x", &n)
		if n == 0 || len(rest) < n {
			break
		}
		out = append(out, rest[:n]...)
		b = rest[n+2:]
	}
	return out
}

func TestS3Driver(t *testing.T) {
	srv := httptest.NewServer(&fakeS3{objs: map[string][]byte{}})
	defer srv.Close()
	u, _ := url.Parse(srv.URL)
	st, err := New(Config{Driver: "s3", S3Endpoint: u.Host, S3Bucket: "menglish-files", S3AccessKey: "ak", S3SecretKey: "sk"})
	if err != nil {
		t.Fatal(err)
	}
	ctx := context.Background()
	key := NewKey("placement_pdf", "pdf", time.Now())
	if err := st.Put(ctx, key, bytes.NewReader([]byte("%PDF-1.4")), 8, "application/pdf"); err != nil {
		t.Fatal(err)
	}
	rc, err := st.Open(ctx, key)
	if err != nil {
		t.Fatal(err)
	}
	b, _ := io.ReadAll(rc)
	rc.Close()
	if string(b) != "%PDF-1.4" {
		t.Fatalf("đọc lại %q", b)
	}
	signed, err := st.SignedURL(ctx, key, SignOptions{TTL: time.Minute, Filename: "đề.pdf", ContentType: "application/pdf", Inline: true})
	if err != nil {
		t.Fatal(err)
	}
	su, _ := url.Parse(signed)
	q := su.Query()
	if su.Host != u.Host || !strings.HasSuffix(su.Path, "/menglish-files/"+key) || q.Get("X-Amz-Expires") != "60" ||
		q.Get("X-Amz-Signature") == "" || !strings.HasPrefix(q.Get("response-content-disposition"), "inline; filename*=") {
		t.Fatalf("URL ký %s", signed)
	}
	if err := st.Delete(ctx, key); err != nil {
		t.Fatal(err)
	}
	if _, err := st.Open(ctx, key); err != ErrNotFound {
		t.Fatalf("sau khi xóa: %v", err)
	}
	if _, err := New(Config{Driver: "s3"}); err == nil {
		t.Fatal("thiếu cấu hình S3 vẫn chạy")
	}
}
