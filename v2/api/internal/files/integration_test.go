package files_test

import (
	"bytes"
	"context"
	"strings"
	"testing"
	"time"

	"github.com/go-chi/chi/v5"
	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/files"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam/iamtest"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/filestore"
)

// Test HTTP với Postgres / Redis thật: file hợp đồng nhân sự (tải lên, thay, tải về có kiểm quyền) qua kho local.

func setup(t *testing.T) (*iamtest.Env, *files.Service) {
	t.Helper()
	e := iamtest.Setup(t, "core.files", "core.audit_log")
	store, err := filestore.NewLocal(t.TempDir(), files.APIBase+files.BlobPrefix, bytes.Repeat([]byte{7}, 32))
	if err != nil {
		t.Fatal(err)
	}
	svc := files.NewService(e.Pool, store, e.Log, 0)
	e.IAM.SetContractFiles(svc.StaffContracts(e.IAM))
	h := files.NewHandler(svc, e.Log)
	e.Serve(func(r chi.Router) {
		h.Routes(r)
		h.PublicRoutes(r)
	})
	return e, svc
}

var pdfBody = []byte("%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n")

func TestStaffContract(t *testing.T) {
	e, svc := setup(t)
	a, b := e.Branch("CSA"), e.Branch("CSB")
	teacher, teacherC := e.Member("GV001", "Nguyễn Văn Đạt", []uuid.UUID{a}, iam.RoleTeacherFulltime)
	_, other := e.Member("GV002", "Giáo viên khác", []uuid.UUID{a}, iam.RoleTeacherFulltime)
	_, staffA := e.Member("HV001", "Học vụ A", []uuid.UUID{a}, iam.RoleAcademicStaff)
	_, staffB := e.Member("HV002", "Học vụ B", []uuid.UUID{b}, iam.RoleAcademicStaff)
	_, manager := e.Member("QL001", "Quản lý", []uuid.UUID{a}, iam.RoleManager)
	path := "/users/" + teacher.ID.String() + "/contract"

	// Không có quyền user.update / khác chi nhánh
	iamtest.Expect(t, manager.Upload(path, nil, "hd.pdf", pdfBody), 403, "forbidden")
	iamtest.Expect(t, staffB.Upload(path, nil, "hd.pdf", pdfBody), 403, "outside_branch")
	iamtest.Expect(t, e.Anon().Upload(path, nil, "hd.pdf", pdfBody), 401, "")

	// Kiểm file như UserRequest: thiếu file, sai loại (nhận theo nội dung, không theo đuôi), quá 10240 kB
	msg := func(r iamtest.Resp, want string) {
		t.Helper()
		if r.Status != 422 || r.Body["message"] != want {
			t.Fatalf("muốn 422 %q, nhận %d %s", want, r.Status, r.Raw)
		}
	}
	msg(staffA.Upload(path, nil, "", nil), "Vui lòng chọn file hợp đồng.")
	msg(staffA.Upload(path, nil, "hd.pdf", []byte("<html><script>alert(1)</script></html>")),
		"Định dạng file không được phép. Chấp nhận: pdf, doc, docx, jpg, jpeg, png, webp.")
	msg(staffA.Upload(path, nil, "hd.txt", []byte("hợp đồng dạng chữ")),
		"Định dạng file không được phép. Chấp nhận: pdf, doc, docx, jpg, jpeg, png, webp.")
	big := append(append([]byte{}, pdfBody...), bytes.Repeat([]byte{' '}, 10240<<10)...)
	msg(staffA.Upload(path, nil, "hd.pdf", big), "Dung lượng tập tin file hợp đồng không được vượt quá 10240 kB.")

	// Tải lên thành công (tên file có đường dẫn / ký tự lạ được làm sạch)
	r := staffA.Upload(path, nil, `C:\tai lieu\HĐ lao động.pdf`, pdfBody)
	iamtest.Expect(t, r, 200, "")
	cf := r.Body["contract_file"].(map[string]any)
	if cf["name"] != "HĐ lao động.pdf" || cf["mime_type"] != "application/pdf" || cf["size"] != float64(len(pdfBody)) ||
		!strings.HasPrefix(cf["url"].(string), "/api/v1/files/") || r.Body["message"] != "Đã cập nhật file hợp đồng." {
		t.Fatalf("file hợp đồng: %s", r.Raw)
	}
	first := cf["url"].(string)

	// Hồ sơ nhân sự trả file hợp đồng hiện tại
	p := staffA.Do("GET", "/users/"+teacher.ID.String()+"/profile", nil)
	iamtest.Expect(t, p, 200, "")
	if got, _ := p.Body["contract_file"].(map[string]any); got == nil || got["url"] != first {
		t.Fatalf("hồ sơ: %s", p.Raw)
	}

	// Nhật ký vận hành
	var n int
	var desc, label string
	if err := e.Pool.QueryRow(context.Background(), `SELECT count(*) OVER (), description, subject_label FROM core.audit_log
		WHERE action = 'file.upload' AND subject_type = 'user' AND subject_id = $1`, teacher.ID.String()).Scan(&n, &desc, &label); err != nil {
		t.Fatal(err)
	}
	if n != 1 || desc != "Cập nhật file hợp đồng lao động" || !strings.Contains(label, "GV001") {
		t.Fatalf("nhật ký: %d %q %q", n, desc, label)
	}

	// Tải về: chính chủ được, chuyển 302 sang link ký sẵn, tên file không dấu
	d := teacherC.Get(first)
	if d.Status != 302 || d.Header.Get("Cache-Control") != "no-store" {
		t.Fatalf("tải về: %d %s", d.Status, d.Raw)
	}
	blob := d.Header.Get("Location")
	if !strings.HasPrefix(blob, "/api/v1/files/blob/") {
		t.Fatalf("link ký: %q", blob)
	}
	c := e.Anon().Get(blob) // link ký sẵn không cần phiên (mở trong tab mới / thẻ <img>)
	if c.Status != 200 || !bytes.Equal(c.Raw, pdfBody) || c.Header.Get("Content-Type") != "application/pdf" ||
		!strings.Contains(c.Header.Get("Content-Disposition"), "filename=hop-dong-nguyen-van-dat.pdf") ||
		c.Header.Get("X-Content-Type-Options") != "nosniff" {
		t.Fatalf("nội dung: %d %v", c.Status, c.Header)
	}
	// Token bị sửa → 403
	iamtest.Expect(t, e.Anon().Get(blob[:len(blob)-2]+"xx"), 403, "")

	// Người khác: không có user.view → 403; khác chi nhánh → 403; chưa đăng nhập → 401
	iamtest.Expect(t, other.Get(first), 403, "forbidden")
	iamtest.Expect(t, staffB.Get(first), 403, "outside_branch")
	iamtest.Expect(t, e.Anon().Get(first), 401, "")
	iamtest.Expect(t, staffA.Get("/api/v1/files/"+uuid.NewString()), 404, "file_not_found")
	iamtest.Expect(t, staffA.Get("/api/v1/files/khong-phai-id"), 404, "file_not_found")
	j := staffA.Get(first + "?format=json")
	iamtest.Expect(t, j, 200, "")
	if j.Body["name"] != "hop-dong-nguyen-van-dat.pdf" || j.Body["expires_in"] != float64(300) || !strings.HasPrefix(j.Body["url"].(string), "/api/v1/files/blob/") {
		t.Fatalf("json: %s", j.Raw)
	}

	// Thay file: file cũ bị xóa mềm (link cũ 404), file mới là ảnh PNG
	png := append([]byte("\x89PNG\r\n\x1a\n"), bytes.Repeat([]byte{1}, 64)...)
	r = staffA.Upload(path, nil, "scan.png", png)
	iamtest.Expect(t, r, 200, "")
	second := r.Body["contract_file"].(map[string]any)["url"].(string)
	iamtest.Expect(t, staffA.Get(first), 404, "file_not_found")
	d = staffA.Get(second)
	if d.Status != 302 {
		t.Fatalf("file mới: %d %s", d.Status, d.Raw)
	}
	if c := e.Anon().Get(d.Header.Get("Location")); c.Status != 200 || c.Header.Get("Content-Type") != "image/png" ||
		!strings.HasPrefix(c.Header.Get("Content-Disposition"), "inline") || !strings.Contains(c.Header.Get("Content-Security-Policy"), "sandbox") {
		t.Fatalf("ảnh: %d %v", c.Status, c.Header)
	}

	// Tài khoản học viên không có hồ sơ nhân sự
	stu := e.User("HS001", "Học sinh", []uuid.UUID{a}, iam.RoleStudent)
	if _, err := e.Pool.Exec(context.Background(), "UPDATE iam.users SET kind = 'student' WHERE id = $1", stu.ID); err != nil {
		t.Fatal(err)
	}
	msg(staffA.Upload("/users/"+stu.ID.String()+"/contract", nil, "hd.pdf", pdfBody), "Chỉ tài khoản nhân viên có hồ sơ nhân sự.")

	// Dọn file đã xóa mềm quá hạn: xóa hẳn dòng và nội dung
	purged, err := svc.PurgeDeleted(context.Background(), time.Now().Add(time.Minute))
	if err != nil || purged != 1 {
		t.Fatalf("dọn: %d %v", purged, err)
	}
	var left int
	_ = e.Pool.QueryRow(context.Background(), "SELECT count(*) FROM core.files").Scan(&left)
	if left != 1 {
		t.Fatalf("còn %d file", left)
	}
}
