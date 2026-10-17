package placement

import (
	"bytes"
	"context"
	"encoding/json"
	"io"
	"mime/multipart"
	"net/http"
	"strings"
	"testing"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/files"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/filestore"
)

// Tải file nghe / ảnh / đề PDF (placement-tests.media.store, pdf.store, pdf.answers) qua kho file local.

// upload gửi multipart: các trường chữ + file ở trường "file" (filename rỗng = không file).
func (c *client) upload(path string, fields map[string]string, filename string, content []byte) resp {
	c.e.t.Helper()
	var buf bytes.Buffer
	mw := multipart.NewWriter(&buf)
	for k, v := range fields {
		_ = mw.WriteField(k, v)
	}
	if filename != "" {
		fw, _ := mw.CreateFormFile("file", filename)
		_, _ = fw.Write(content)
	}
	_ = mw.Close()
	req, _ := http.NewRequest("POST", c.e.srv.URL+"/api/v1"+path, &buf)
	req.Header.Set("Content-Type", mw.FormDataContentType())
	r, _ := c.send(req)
	return r
}

// get: GET đường dẫn tuyệt đối, không đi theo chuyển hướng.
func (c *client) get(path string) (resp, http.Header) {
	c.e.t.Helper()
	req, _ := http.NewRequest("GET", c.e.srv.URL+path, nil)
	return c.send(req)
}

func (c *client) send(req *http.Request) (resp, http.Header) {
	c.e.t.Helper()
	req.Header.Set("Origin", c.e.srv.URL)
	req.Header.Set("X-Forwarded-For", c.ip)
	if c.cookie != "" {
		req.AddCookie(&http.Cookie{Name: iam.CookieName, Value: c.cookie})
	}
	if c.csrf != "" {
		req.Header.Set("X-CSRF-Token", c.csrf)
	}
	cl := &http.Client{CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}
	res, err := cl.Do(req)
	if err != nil {
		c.e.t.Fatal(err)
	}
	defer res.Body.Close()
	raw, _ := io.ReadAll(res.Body)
	out := resp{status: res.StatusCode, body: map[string]any{}, raw: string(raw)}
	_ = json.Unmarshal(raw, &out.body)
	return out, res.Header
}

var (
	pngBytes = append([]byte("\x89PNG\r\n\x1a\n"), bytes.Repeat([]byte{2}, 100)...)
	mp3Bytes = append([]byte("ID3\x03\x00\x00\x00\x00\x00\x00"), bytes.Repeat([]byte{0}, 100)...)
)

func TestPlacementFiles(t *testing.T) {
	e := setup(t)
	authorM := e.member(t, "gv01", "placement_test.view", "placement_test.create", "placement_test.update", "placement_test.distribute")
	author := e.as(t, authorM)
	viewer := e.as(t, e.member(t, "xem01", "placement_test.view"))
	outsider := e.as(t, e.member(t, "kt01", "lead.view"))

	// Không có quyền tạo / sửa đề
	expect(t, viewer.upload("/placement-tests/media", map[string]string{"kind": "image"}, "a.png", pngBytes), 403, "forbidden")
	expect(t, viewer.upload("/placement-tests/pdf", nil, "de.pdf", samplePDF(false)), 403, "forbidden")
	expect(t, viewer.do("POST", "/placement-tests/pdf/answers", map[string]any{"text": "1A"}), 403, "forbidden")
	expect(t, e.anon("198.51.100.9").upload("/placement-tests/media", map[string]string{"kind": "image"}, "a.png", pngBytes), 401, "")

	// File nghe / ảnh: luật như uploadMedia của Laravel
	expectMsg(t, author.upload("/placement-tests/media", map[string]string{"kind": "video"}, "a.png", pngBytes), "Loại file đã chọn không hợp lệ.")
	expectMsg(t, author.upload("/placement-tests/media", map[string]string{"kind": "image"}, "", nil), "Vui lòng chọn file.")
	expectMsg(t, author.upload("/placement-tests/media", map[string]string{"kind": "audio"}, "a.mp3", pngBytes),
		"Định dạng file không được phép. Chấp nhận: mp3, wav, m4a, ogg, oga, weba, webm, aac.")
	expectMsg(t, author.upload("/placement-tests/media", map[string]string{"kind": "image"}, "a.svg", []byte(`<svg onload="alert(1)"></svg>`)),
		"Định dạng file không được phép. Chấp nhận: jpg, jpeg, png, gif, webp.")
	expectMsg(t, author.upload("/placement-tests/media", map[string]string{"kind": "image"}, "a.png", append(pngBytes, make([]byte, 5120<<10)...)),
		"File quá lớn (âm thanh tối đa 20 MB, ảnh tối đa 5 MB).")
	r := author.upload("/placement-tests/media", map[string]string{"kind": "image"}, "tranh.png", pngBytes)
	expect(t, r, 200, "")
	imageURL := r.body["url"].(string)
	if !strings.HasPrefix(imageURL, "/api/v1/files/") || r.body["path"] != imageURL || r.body["name"] != "tranh.png" {
		t.Fatalf("ảnh: %s", r.raw)
	}
	r = author.upload("/placement-tests/media", map[string]string{"kind": "audio"}, "bai-nghe.mp3", mp3Bytes)
	expect(t, r, 200, "")
	audioURL := r.body["url"].(string)

	// Đọc nhanh đáp án dán vào
	expectMsg(t, author.do("POST", "/placement-tests/pdf/answers", map[string]any{"text": "  "}), "Vui lòng nhập đáp án.")
	expectMsg(t, author.do("POST", "/placement-tests/pdf/answers", map[string]any{"text": strings.Repeat("a", 20001)}), "Đáp án không được vượt quá 20000 ký tự.")
	r = author.do("POST", "/placement-tests/pdf/answers", map[string]any{"text": "1A 2. b 3. apple | apples"})
	expect(t, r, 200, "")
	if got := list(r.body["answers"]); len(got) != 3 || obj(got[2])["answer"] != "apple | apples" || obj(got[1])["number"] != float64(2) {
		t.Fatalf("đáp án dán: %s", r.raw)
	}

	// Tải đề PDF: dựng phiếu + đáp án; file còn trang đáp án được báo trang
	expectMsg(t, author.upload("/placement-tests/pdf", nil, "de.pdf", pngBytes), "Định dạng file không được phép. Chấp nhận: pdf.")
	r = author.upload("/placement-tests/pdf", nil, "de-co-dap-an.pdf", samplePDF(true))
	expect(t, r, 200, "")
	if r.body["answer_key_page"] != float64(1) || r.body["answers_found"] != float64(3) || len(list(r.body["questions"])) != 3 ||
		!strings.HasPrefix(r.body["pdf_path"].(string), "/api/v1/files/") {
		t.Fatalf("tải đề PDF: %s", r.raw)
	}
	keyed := r.body["pdf_path"].(string)
	sheet := r.body["questions"]

	// File đáp án riêng: chỉ đọc đáp án, không lưu file
	var before, after int
	_ = e.pg.QueryRow(context.Background(), "SELECT count(*) FROM core.files").Scan(&before)
	r = author.upload("/placement-tests/pdf", map[string]string{"answers_only": "1"}, "dap-an.pdf", samplePDF(true))
	expect(t, r, 200, "")
	_ = e.pg.QueryRow(context.Background(), "SELECT count(*) FROM core.files").Scan(&after)
	if got := list(r.body["answers"]); len(got) != 3 || obj(got[2])["answer"] != "pencil" || after != before {
		t.Fatalf("file đáp án: %s (file %d → %d)", r.raw, before, after)
	}

	base := map[string]any{"code": "TEST-G3-G4-PDF", "title": "Đề PDF", "grade_level": "lop_3", "duration_minutes": 45, "mode": "pdf", "questions": sheet}
	with := func(k string, v any) map[string]any {
		m := map[string]any{}
		for kk, vv := range base {
			m[kk] = vv
		}
		m[k] = v
		return m
	}
	// Không lưu đề khi file còn trang đáp án / file không phải đề PDF đã tải lên
	expectMsg(t, author.do("POST", "/placement-tests", with("pdf_path", keyed)),
		`File đề PDF còn phần đáp án (trang 1) — thí sinh sẽ thấy đáp án. Hãy bấm "Đổi file" và tải file đề không có trang đáp án; phiếu và đáp án đã đọc được vẫn giữ nguyên.`)
	bad := "File đề PDF không hợp lệ hoặc đã bị xoá — hãy tải lại file PDF."
	expectMsg(t, author.do("POST", "/placement-tests", with("pdf_path", imageURL)), bad)
	expectMsg(t, author.do("POST", "/placement-tests", with("pdf_path", "/api/v1/files/"+uuid.NewString())), bad)
	expectMsg(t, author.do("POST", "/placement-tests", with("pdf_path", "placement_tests/pdfs/cu.pdf")), bad)

	r = author.upload("/placement-tests/pdf", nil, "de.pdf", samplePDF(false))
	expect(t, r, 200, "")
	if r.body["answer_key_page"] != nil {
		t.Fatalf("đề không đáp án: %s", r.raw)
	}
	pdfPath := r.body["pdf_path"].(string)
	empty := with("pdf_path", pdfPath)
	empty["questions"] = []any{}
	expectMsg(t, author.do("POST", "/placement-tests", empty), "Phiếu trả lời chưa có câu nào — tạo phiếu theo số câu của đề rồi điền đáp án.")
	m := with("pdf_path", pdfPath)
	m["audio_url"] = audioURL
	r = author.do("POST", "/placement-tests", m)
	expect(t, r, 201, "")
	d := obj(r.body["data"])
	testID := d["id"].(string)
	if d["pdf_path"] != pdfPath || d["pdf_url"] != pdfPath || d["audio_url"] != audioURL {
		t.Fatalf("đề PDF: %s", r.raw)
	}
	var subject *uuid.UUID
	pid, _ := files.ParseURL(pdfPath)
	_ = e.pg.QueryRow(context.Background(), "SELECT subject_id FROM core.files WHERE id = $1", pid).Scan(&subject)
	if subject == nil || subject.String() != testID {
		t.Fatalf("file đề chưa gắn vào đề: %v", subject)
	}
	// Sửa đề giữ nguyên file cũ không đọc lại
	r = author.do("PUT", "/placement-tests/"+testID, map[string]any{"code": "TEST-G3-G4-PDF", "title": "Đề PDF sửa", "grade_level": "lop_3",
		"target_level": "Khối 3 lên 4", "duration_minutes": 45, "mode": "pdf", "questions": sheet, "pdf_path": pdfPath, "is_active": true})
	expect(t, r, 200, "")

	// Người xem đề mở được file (302 sang link ký); người ngoài thì không
	res, h := viewer.get(pdfPath)
	if res.status != 302 || !strings.HasPrefix(h.Get("Location"), "/api/v1/files/blob/") {
		t.Fatalf("mở file đề: %d %s", res.status, res.raw)
	}
	res, _ = outsider.get(pdfPath)
	expect(t, res, 403, "forbidden")

	// Trang làm bài: file đề / file nghe ký sẵn, thí sinh mở không cần đăng nhập
	r = author.do("POST", "/placement-tests/"+testID+"/invitations", map[string]any{})
	expect(t, r, 201, "")
	anon := e.anon("198.51.100.2")
	r = anon.do("GET", "/public/placement/"+r.body["token"].(string), nil)
	expect(t, r, 200, "")
	pub := obj(r.body["test"])
	pdfSigned, audioSigned := pub["pdf_url"].(string), pub["audio_src"].(string)
	if !strings.HasPrefix(pdfSigned, "/api/v1/files/blob/") || !strings.HasPrefix(audioSigned, "/api/v1/files/blob/") || len(list(r.body["sheet"])) != 3 {
		t.Fatalf("trang làm bài: %s", r.raw)
	}
	res, h = anon.get(pdfSigned)
	if res.status != 200 || h.Get("Content-Type") != "application/pdf" || h.Get("Content-Security-Policy") != "frame-ancestors 'self'" ||
		!bytes.Equal([]byte(res.raw), samplePDF(false)) {
		t.Fatalf("file đề cho thí sinh: %d %v", res.status, h)
	}
	if res, h = anon.get(audioSigned); res.status != 200 || h.Get("Content-Type") != "audio/mpeg" {
		t.Fatalf("file nghe cho thí sinh: %d %v", res.status, h)
	}

	// Đề soạn từng câu: ảnh của đề được ký; URL file khác (vd. hợp đồng) dán vào câu hỏi không lộ ra
	up, err := filestore.NewUpload("hd.png", bytes.NewReader(pngBytes), 1<<20)
	if err != nil {
		t.Fatal(err)
	}
	defer up.Close()
	other, err := e.files.Save(context.Background(), nil, up, files.SaveInput{Owner: files.Owner{Module: "iam", SubjectType: "staff_contract", SubjectID: &authorM.id}, By: &authorM.id})
	if err != nil {
		t.Fatal(err)
	}
	r = author.do("POST", "/placement-tests", map[string]any{"code": "TEST-G3-G4-ANH", "title": "Đề ảnh", "grade_level": "lop_3", "duration_minutes": 45,
		"questions": []map[string]any{
			{"id": "l1", "skill": "listening", "title": "Nghe", "audio_url": audioURL, "image_url": imageURL, "correct_answer": "A",
				"options": []map[string]any{{"key": "A", "text": "cat", "image_url": imageURL}, {"key": "B", "text": "dog", "image_url": other.URL}}},
			{"id": "r1", "skill": "reading", "title": "Đọc", "image_url": other.URL, "correct_answer": "x", "type": "fill_blank"},
		}})
	expect(t, r, 201, "")
	r = author.do("POST", "/placement-tests/"+obj(r.body["data"])["id"].(string)+"/invitations", map[string]any{})
	expect(t, r, 201, "")
	r = anon.do("GET", "/public/placement/"+r.body["token"].(string), nil)
	expect(t, r, 200, "")
	l1, r1 := obj(list(r.body["listening"])[0]), obj(list(r.body["reading"])[0])
	opts := list(l1["options"])
	if !strings.HasPrefix(l1["image_url"].(string), "/api/v1/files/blob/") || !strings.HasPrefix(l1["audio_src"].(string), "/api/v1/files/blob/") ||
		!strings.HasPrefix(obj(opts[0])["image_url"].(string), "/api/v1/files/blob/") || obj(opts[1])["image_url"] != nil || r1["image_url"] != nil ||
		strings.Contains(r.raw, other.ID.String()) {
		t.Fatalf("ảnh câu hỏi: %s", r.raw)
	}
}
