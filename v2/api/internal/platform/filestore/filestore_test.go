package filestore

import (
	"archive/zip"
	"bytes"
	"context"
	"io"
	"mime/multipart"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"
)

func testKey() []byte { return bytes.Repeat([]byte{7}, 32) }

func TestLocalPutOpenSignServe(t *testing.T) {
	ctx := context.Background()
	l, err := NewLocal(t.TempDir(), "/api/v1/files/blob", testKey())
	if err != nil {
		t.Fatal(err)
	}
	key := NewKey("staff_contract", "pdf", time.Date(2026, 10, 1, 0, 0, 0, 0, time.UTC))
	if !strings.HasPrefix(key, "staff_contract/2026/10/") || !ValidKey(key) {
		t.Fatalf("khóa %q", key)
	}
	body := []byte("%PDF-1.4 nội dung")
	if err := l.Put(ctx, key, bytes.NewReader(body), int64(len(body)), "application/pdf"); err != nil {
		t.Fatal(err)
	}
	rc, err := l.Open(ctx, key)
	if err != nil {
		t.Fatal(err)
	}
	got, _ := io.ReadAll(rc)
	rc.Close()
	if !bytes.Equal(got, body) {
		t.Fatalf("đọc lại %q", got)
	}

	u, err := l.SignedURL(ctx, key, SignOptions{Filename: "hợp đồng.pdf", ContentType: "application/pdf", Inline: true})
	if err != nil || !strings.HasPrefix(u, "/api/v1/files/blob/") {
		t.Fatalf("url %q %v", u, err)
	}
	tok := strings.TrimPrefix(u, "/api/v1/files/blob/")
	serve := func(tok string, hdr map[string]string) *httptest.ResponseRecorder {
		w := httptest.NewRecorder()
		r := httptest.NewRequest("GET", "/x", nil)
		for k, v := range hdr {
			r.Header.Set(k, v)
		}
		l.ServeSigned(w, r, tok)
		return w
	}
	w := serve(tok, nil)
	if w.Code != 200 || !bytes.Equal(w.Body.Bytes(), body) {
		t.Fatalf("serve %d %q", w.Code, w.Body.String())
	}
	if cd := w.Header().Get("Content-Disposition"); !strings.HasPrefix(cd, "inline") || !strings.Contains(cd, "filename*=utf-8''h%E1%BB%A3p") {
		t.Fatalf("Content-Disposition %q", cd)
	}
	if w.Header().Get("X-Content-Type-Options") != "nosniff" || w.Header().Get("Content-Type") != "application/pdf" {
		t.Fatalf("header %v", w.Header())
	}
	// Range: tua file nghe
	if w := serve(tok, map[string]string{"Range": "bytes=0-3"}); w.Code != http.StatusPartialContent || w.Body.String() != "%PDF" {
		t.Fatalf("range %d %q", w.Code, w.Body.String())
	}
	// Sửa token → 403
	if w := serve(tok[:len(tok)-2]+"xx", nil); w.Code != http.StatusForbidden {
		t.Fatalf("token sửa: %d", w.Code)
	}
	payload, _, _ := strings.Cut(tok, ".")
	if w := serve(payload+"."+l.sign(payload+"x"), nil); w.Code != http.StatusForbidden {
		t.Fatalf("chữ ký khác: %d", w.Code)
	}
	// Hết hạn → 403
	l.now = func() time.Time { return time.Now().Add(6 * time.Minute) }
	if w := serve(tok, nil); w.Code != http.StatusForbidden {
		t.Fatalf("hết hạn: %d", w.Code)
	}
	l.now = time.Now
	// Khóa khác không đọc được token
	l2, _ := NewLocal(t.TempDir(), "/x", bytes.Repeat([]byte{8}, 32))
	if _, ok := l2.verify(tok); ok {
		t.Fatal("token ký bằng khóa khác vẫn hợp lệ")
	}

	if err := l.Delete(ctx, key); err != nil {
		t.Fatal(err)
	}
	if _, err := l.Open(ctx, key); err != ErrNotFound {
		t.Fatalf("sau khi xóa: %v", err)
	}
	if err := l.Delete(ctx, key); err != nil {
		t.Fatalf("xóa lần 2: %v", err)
	}
}

func TestValidKey(t *testing.T) {
	for _, k := range []string{"../etc/passwd.txt", "/abs/x.pdf", "a/../b.pdf", "A/b.pdf", "a/b", "a//b.pdf", "a/b.pdf/"} {
		if ValidKey(k) {
			t.Errorf("%q không được hợp lệ", k)
		}
	}
	l, _ := NewLocal(t.TempDir(), "/x", testKey())
	if err := l.Put(context.Background(), "../x.pdf", strings.NewReader("x"), 1, ""); err == nil {
		t.Fatal("Put nhận khóa ra ngoài thư mục")
	}
}

func docx(t *testing.T, inner string) []byte {
	var buf bytes.Buffer
	z := zip.NewWriter(&buf)
	for _, n := range []string{"[Content_Types].xml", inner} {
		w, _ := z.Create(n)
		w.Write([]byte("<x/>"))
	}
	z.Close()
	return buf.Bytes()
}

func TestDetect(t *testing.T) {
	cases := map[string][]byte{
		"jpg":  {0xFF, 0xD8, 0xFF, 0xE0, 0, 0},
		"png":  []byte("\x89PNG\r\n\x1a\n...."),
		"gif":  []byte("GIF89a...."),
		"webp": []byte("RIFF\x00\x00\x00\x00WEBPVP8 "),
		"wav":  []byte("RIFF\x00\x00\x00\x00WAVEfmt "),
		"pdf":  []byte("%PDF-1.7\n"),
		"mp3":  []byte("ID3\x03\x00...."),
		"ogg":  []byte("OggS\x00\x02...."),
		"m4a":  []byte("\x00\x00\x00\x20ftypM4A \x00\x00"),
		"mp4":  []byte("\x00\x00\x00\x20ftypisom\x00\x00"),
		"aac":  {0xFF, 0xF1, 0x50, 0x80, 0, 0},
		"txt":  []byte("1A 2B 3C\nđáp án"),
		"docx": docx(t, "word/document.xml"),
		"xlsx": docx(t, "xl/workbook.xml"),
	}
	cases["mp3-frame"] = []byte{0xFF, 0xFB, 0x90, 0x64, 0, 0}
	for want, b := range cases {
		got := Detect(b, bytes.NewReader(b), int64(len(b))).Ext
		if w, _, _ := strings.Cut(want, "-"); got != w {
			t.Errorf("%s: nhận %q", want, got)
		}
	}
	for name, b := range map[string][]byte{
		"html": []byte("<!DOCTYPE html><script>alert(1)</script>"),
		"svg":  []byte(`<svg xmlns="http://www.w3.org/2000/svg"><script/></svg>`),
		"php":  []byte("<?php echo 1;"),
		"zip": func() []byte {
			var b bytes.Buffer
			z := zip.NewWriter(&b)
			w, _ := z.Create("a.txt")
			w.Write([]byte("x"))
			z.Close()
			return b.Bytes()
		}(),
		"exe": []byte("MZ\x90\x00\x03\x00\x00\x00"),
	} {
		if got := Detect(b, bytes.NewReader(b), int64(len(b))).Ext; got != "" {
			t.Errorf("%s nhận thành %q, phải từ chối", name, got)
		}
	}
}

func TestUploadCheck(t *testing.T) {
	pdf := []byte("%PDF-1.4\n...")
	u, err := NewUpload(`C:\Users\a\Hợp đồng.pdf`, bytes.NewReader(pdf), 1024)
	if err != nil {
		t.Fatal(err)
	}
	defer u.Close()
	if u.Name != "Hợp đồng.pdf" || u.Size != int64(len(pdf)) || u.Kind.Ext != "pdf" {
		t.Fatalf("upload %+v", u)
	}
	contract := Policy{Types: []string{"pdf", "doc", "docx", "jpg", "jpeg", "png", "webp"}, MaxBytes: 1024, TooLarge: "quá lớn"}
	if err := u.Check(contract); err != nil {
		t.Fatal(err)
	}
	if err := u.Check(Policy{Types: Images, MaxBytes: 1024}); err == nil || err.Error() != "Định dạng file không được phép. Chấp nhận: jpg, jpeg, png, gif, webp." {
		t.Fatalf("sai loại: %v", err)
	}
	big, _ := NewUpload("a.pdf", bytes.NewReader(append(pdf, make([]byte, 2000)...)), 1024)
	defer big.Close()
	if err := big.Check(contract); err == nil || err.Error() != "quá lớn" {
		t.Fatalf("quá lớn: %v", err)
	}
	// Đuổi .pdf nhưng nội dung là PHP → từ chối (Laravel: test_upload_rejects_files_that_are_not_pdf)
	php, _ := NewUpload("de.pdf", strings.NewReader("<?php echo 1;"), 1024)
	defer php.Close()
	if err := php.Check(Policy{Types: []string{"pdf"}, MaxBytes: 1024}); err == nil {
		t.Fatal("nhận file PHP đổi đuôi .pdf")
	}
	var none *Upload
	if err := none.Check(Policy{Required: "Vui lòng chọn file hợp đồng."}); err == nil || err.Error() != "Vui lòng chọn file hợp đồng." {
		t.Fatalf("thiếu file: %v", err)
	}
}

func multipartReq(t *testing.T, fields map[string]string, file string, content []byte) *http.Request {
	var buf bytes.Buffer
	mw := multipart.NewWriter(&buf)
	for k, v := range fields {
		mw.WriteField(k, v)
	}
	if file != "" {
		fw, _ := mw.CreateFormFile("file", file)
		fw.Write(content)
	}
	mw.Close()
	r := httptest.NewRequest("POST", "/up", &buf)
	r.Header.Set("Content-Type", mw.FormDataContentType())
	return r
}

func TestReadMultipart(t *testing.T) {
	r := multipartReq(t, map[string]string{"kind": " audio "}, "nghe.mp3", []byte("ID3\x03\x00 âm thanh"))
	f, err := ReadMultipart(httptest.NewRecorder(), r, 1<<20, "quá lớn")
	if err != nil {
		t.Fatal(err)
	}
	defer f.Close()
	if f.Value("kind") != "audio" || f.File("file") == nil || f.File("file").Kind.Ext != "mp3" || f.File("missing") != nil {
		t.Fatalf("form %+v", f)
	}
	// File quá giới hạn: đọc bỏ phần thừa, Check báo lỗi dung lượng
	r = multipartReq(t, nil, "a.pdf", append([]byte("%PDF-"), make([]byte, 6000)...))
	f2, err := ReadMultipart(httptest.NewRecorder(), r, 1000, "quá lớn")
	if err != nil {
		t.Fatal(err)
	}
	defer f2.Close()
	if err := f2.File("file").Check(Policy{Types: []string{"pdf"}, MaxBytes: 1000, TooLarge: "quá lớn"}); err == nil || err.Error() != "quá lớn" {
		t.Fatalf("file quá lớn: %v", err)
	}
	// Vượt cả giới hạn request → thông báo quá lớn ngay
	r = multipartReq(t, nil, "a.pdf", make([]byte, 100_000))
	if _, err := ReadMultipart(httptest.NewRecorder(), r, 1000, "quá lớn"); err == nil || err.Error() != "quá lớn" {
		t.Fatalf("request quá lớn: %v", err)
	}
	// Không phải multipart
	r = httptest.NewRequest("POST", "/up", strings.NewReader("{}"))
	r.Header.Set("Content-Type", "application/json")
	if _, err := ReadMultipart(httptest.NewRecorder(), r, 1000, ""); err == nil {
		t.Fatal("nhận JSON")
	}
}

func TestDisposition(t *testing.T) {
	if got := Disposition(false, "hop-dong.pdf"); got != "attachment; filename=hop-dong.pdf" {
		t.Fatal(got)
	}
	if got := Disposition(true, ""); got != "inline" {
		t.Fatal(got)
	}
}
