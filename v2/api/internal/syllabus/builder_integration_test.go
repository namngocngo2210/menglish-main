package syllabus_test

import (
	"testing"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam/iamtest"
)

func TestBuilderAndDocuments(t *testing.T) {
	e := setup(t)
	a := e.Branch("CN-A")
	_, lead := e.Member("HT001", "Học thuật", []uuid.UUID{a}, iam.RoleAcademicLead)
	_, teacher := e.Member("GV001", "Giáo viên", []uuid.UUID{a}, iam.RoleTeacherFulltime)
	_, assistant := e.Member("TG001", "Trợ giảng", []uuid.UUID{a}, iam.RoleAssistant)
	_, student := e.Member("HV001", "Học viên", nil, iam.RoleStudent)

	// Không có quyền
	iamtest.Expect(t, student.Do("GET", "/syllabus/builder", nil), 403, "")
	iamtest.Expect(t, teacher.Do("POST", "/syllabus/curricula", map[string]any{"code": "CUR-1", "title": "A", "version": "v1"}), 403, "")

	// Kiểm tra dữ liệu
	expectMsg(t, lead.Do("POST", "/syllabus/curricula", map[string]any{"code": "CUR-1", "title": " ", "version": "v1"}), 422,
		"Vui lòng nhập tên giáo trình.")
	r := lead.Do("POST", "/syllabus/curricula", map[string]any{"code": "CUR-1", "title": "Starters", "version": "v1"})
	expectMsg(t, r, 201, "Đã tạo giáo trình Starters (kèm Chặng 1).")
	cur := newID(t, r)
	expectMsg(t, lead.Do("POST", "/syllabus/curricula", map[string]any{"code": "cur-1", "title": "Khác", "version": "v1"}), 422,
		"Mã giáo trình đã được sử dụng.")

	b := lead.Do("GET", "/syllabus/builder?curriculum="+cur, nil)
	iamtest.Expect(t, b, 200, "")
	stages := list(b.Body["stages"])
	if len(stages) != 1 || str(stages[0]["label"]) != "Chặng 1" {
		t.Fatalf("chặng mặc định: %s", b.Raw)
	}
	stage1 := str(stages[0]["id"])
	expectMsg(t, lead.Do("POST", "/syllabus/stages", map[string]any{"curriculum_id": cur, "name": "Movers"}), 201, "Đã thêm Chặng 2: Movers.")
	u := lead.Do("POST", "/syllabus/units", map[string]any{"curriculum_id": cur, "stage_id": stage1, "unit_number": 1, "title": "Hello"})
	iamtest.Expect(t, u, 201, "")
	expectMsg(t, lead.Do("POST", "/syllabus/units", map[string]any{"curriculum_id": cur, "unit_number": 1, "title": "Trùng"}), 422,
		"Unit 1 đã có trong giáo trình này.")
	iamtest.Expect(t, lead.Do("POST", "/syllabus/lessons", map[string]any{"unit_id": newID(t, u), "session_no": 1, "title": "Greetings"}), 201, "")
	expectMsg(t, lead.Do("POST", "/syllabus/lessons", map[string]any{"unit_id": newID(t, u), "session_no": 1, "title": "Lặp"}), 422,
		"Buổi 1 đã có trong giáo trình này")
	expectMsg(t, lead.Do("DELETE", "/syllabus/stages/"+stage1, nil), 422, "còn 1 unit")

	// Tài liệu: tải lên cần syllabus.upload; GV chỉ thấy tài liệu dành cho GV.
	pdf := append([]byte("%PDF-1.4\n"), make([]byte, 64)...)
	iamtest.Expect(t, teacher.Upload("/syllabus/documents", map[string]string{"curriculum_id": cur, "title": "Đề"}, "de.pdf", pdf), 403, "")
	expectMsg(t, lead.Upload("/syllabus/documents", map[string]string{"curriculum_id": cur, "title": " "}, "de.pdf", pdf), 422,
		"Vui lòng nhập tên tài liệu.")
	expectMsg(t, lead.Upload("/syllabus/documents", map[string]string{"curriculum_id": cur, "title": "Thiếu file"}, "", nil), 422,
		"Vui lòng chọn file tài liệu.")
	r = lead.Upload("/syllabus/documents", map[string]string{"curriculum_id": cur, "title": "Sách GV", "stage_id": stage1,
		"visible_to_teachers": "1"}, "sach.pdf", pdf)
	expectMsg(t, r, 201, "Đã tải lên tài liệu Sách GV")
	iamtest.Expect(t, lead.Upload("/syllabus/documents", map[string]string{"curriculum_id": cur, "title": "Nội bộ"}, "noibo.pdf", pdf), 201, "")

	docs := list(teacher.Do("GET", "/syllabus/documents", nil).Body["documents"])
	if len(docs) != 1 || str(docs[0]["title"]) != "Sách GV" {
		t.Fatalf("GV thấy tài liệu: %v", docs)
	}
	if n := len(list(assistant.Do("GET", "/syllabus/documents", nil).Body["documents"])); n != 0 {
		t.Fatalf("trợ giảng không được thấy tài liệu chỉ dành cho GV, thấy %d", n)
	}
	if n := len(list(lead.Do("GET", "/syllabus/documents", nil).Body["documents"])); n != 2 {
		t.Fatalf("Học thuật thấy %d tài liệu, muốn 2", n)
	}
	// Tải file qua kho file: GV xem được (PDF xem trực tuyến), trợ giảng không.
	fileURL := str(docs[0]["url"])
	if r := teacher.Get(fileURL); r.Status != 302 {
		t.Fatalf("GV mở file: %d %s", r.Status, r.Raw)
	}
	if r := assistant.Get(fileURL); r.Status != 404 && r.Status != 403 {
		t.Fatalf("trợ giảng mở file: %d %s", r.Status, r.Raw)
	}
	expectMsg(t, teacher.Do("POST", "/syllabus/documents/"+str(docs[0]["id"])+"/viewed", nil), 200, "Đã đánh dấu đã xem")
	if !list(teacher.Do("GET", "/syllabus/documents", nil).Body["documents"])[0]["viewed"].(bool) {
		t.Fatal("chưa ghi nhận đã xem")
	}
}
