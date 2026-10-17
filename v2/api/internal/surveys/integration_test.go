package surveys_test

import (
	"context"
	"strings"
	"testing"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam/iamtest"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/surveys"
)

func setup(t *testing.T) *iamtest.Env {
	e := iamtest.Setup(t, "ops.surveys", "ops.survey_responses", "academic.student_guardians", "academic.students", "core.audit_log")
	e.Serve(surveys.NewHandler(surveys.NewService(e.Pool, e.Log), e.Log).Routes)
	return e
}

func expectMsg(t *testing.T, r iamtest.Resp, status int, msg string) {
	t.Helper()
	if r.Status != status || r.Body["message"] != msg {
		t.Fatalf("muốn %d %q, nhận %d %s", status, msg, r.Status, r.Raw)
	}
}

func str(v any) string { s, _ := v.(string); return s }

var today = time.Now().In(time.FixedZone("VN", 7*3600)).Format("2006-01-02")

func TestSurveyPermissions(t *testing.T) {
	e := setup(t)
	hn := e.Branch("HN")
	_, staff := e.Member("HV01", "Học vụ", []uuid.UUID{hn}, iam.RoleAcademicStaff)
	_, teacher := e.Member("GV01", "Giáo viên", []uuid.UUID{hn}, iam.RoleTeacherFulltime)
	_, admin := e.Member("AD01", "Admin", []uuid.UUID{hn}, iam.RoleAdmin)
	_, stu := e.Member("ST01", "Học viên", []uuid.UUID{hn}, iam.RoleStudent)

	iamtest.Expect(t, staff.Do("GET", "/surveys", nil), 200, "")
	iamtest.Expect(t, admin.Do("GET", "/surveys", nil), 200, "")
	for _, c := range []*iamtest.Client{teacher, stu} {
		iamtest.Expect(t, c.Do("GET", "/surveys", nil), 403, "forbidden")
		iamtest.Expect(t, c.Do("POST", "/surveys", map[string]any{"title": "X"}), 403, "forbidden")
		iamtest.Expect(t, c.Do("PUT", "/surveys/"+uuid.NewString(), map[string]any{"title": "X"}), 403, "forbidden")
		iamtest.Expect(t, c.Do("DELETE", "/surveys/"+uuid.NewString(), nil), 403, "forbidden")
	}
	// Cổng PH/HS: quyền đối tượng portal.student, Admin / nhân sự không có
	iamtest.Expect(t, admin.Do("GET", "/surveys/portal", nil), 403, "forbidden")
	iamtest.Expect(t, staff.Do("POST", "/surveys/portal", map[string]any{}), 403, "forbidden")
	// Học viên chưa liên kết hồ sơ
	expectMsg(t, stu.Do("GET", "/surveys/portal", nil), 403, "Tài khoản chưa được liên kết với hồ sơ học viên.")
	iamtest.Expect(t, staff.Do("PUT", "/surveys/"+uuid.NewString(), map[string]any{"title": "X"}), 404, "survey_not_found")
	iamtest.Expect(t, staff.Do("DELETE", "/surveys/abc", nil), 404, "survey_not_found")
}

func TestSurveyValidation(t *testing.T) {
	e := setup(t)
	hn := e.Branch("HN")
	_, staff := e.Member("HV01", "Học vụ", []uuid.UUID{hn}, iam.RoleAcademicStaff)
	yesterday := time.Now().In(time.FixedZone("VN", 7*3600)).AddDate(0, 0, -1).Format("2006-01-02")
	for _, c := range []struct {
		body map[string]any
		msg  string
	}{
		{map[string]any{"title": "  "}, "Vui lòng nhập Tiêu đề."},
		{map[string]any{"title": strings.Repeat("a", 256)}, "Trường Tiêu đề không được vượt quá 255 ký tự."},
		{map[string]any{"title": "A", "description": strings.Repeat("ạ", 2001)}, "Trường Mô tả chi tiết không được vượt quá 2000 ký tự."},
		{map[string]any{"title": "A", "deadline": "31/12/2026"}, "Trường Hạn hoàn thành không phải là ngày hợp lệ."},
		{map[string]any{"title": "A", "deadline": yesterday}, "Trường Hạn hoàn thành phải là thời gian bắt đầu sau hoặc đúng bằng hôm nay."},
	} {
		expectMsg(t, staff.Do("POST", "/surveys", c.body), 422, c.msg)
	}
	r := staff.Do("POST", "/surveys", map[string]any{"title": "Đợt 1", "deadline": today})
	iamtest.Expect(t, r, 201, "")
	// Sửa được đặt hạn đã qua (update không có after_or_equal)
	r = staff.Do("PUT", "/surveys/"+str(r.Body["id"]), map[string]any{"title": "Đợt 1", "deadline": yesterday, "is_active": true})
	iamtest.Expect(t, r, 200, "")
	expectMsg(t, staff.Do("PUT", "/surveys/"+str(r.Body["id"]), map[string]any{"title": ""}), 422, "Vui lòng nhập Tiêu đề.")
}

func TestSurveyManageAndPortalFlow(t *testing.T) {
	e := setup(t)
	ctx := context.Background()
	hn, hcm := e.Branch("HN"), e.Branch("HCM")
	staffU, staff := e.Member("HV01", "Học vụ", []uuid.UUID{hn}, iam.RoleAcademicStaff)
	stuU, stu := e.Member("ST01", "Học viên A", []uuid.UUID{hn}, iam.RoleStudent)
	parentU, parent := e.Member("PH01", "Phụ huynh B", []uuid.UUID{hcm}, iam.RoleStudent)
	newStudent := func(code, name string, branch uuid.UUID, user *uuid.UUID, status string) uuid.UUID {
		var id uuid.UUID
		err := e.Pool.QueryRow(ctx, `INSERT INTO academic.students (code, full_name, phone, branch_id, status, user_id)
			VALUES ($1, $2, '0900000000', $3, $4, $5) RETURNING id`, code, name, branch, status, user).Scan(&id)
		if err != nil {
			t.Fatal(err)
		}
		return id
	}
	a := newStudent("HS01", "Học viên A", hn, &stuU.ID, "studying")
	b := newStudent("HS02", "Bé B", hcm, nil, "waiting_start")
	newStudent("HS03", "Bé C đã nghỉ", hcm, nil, "dropped")
	for _, s := range []string{"HS02", "HS03"} {
		if _, err := e.Pool.Exec(ctx, `INSERT INTO academic.student_guardians (student_id, guardian_id) SELECT id, $1 FROM academic.students WHERE code = $2`,
			parentU.ID, s); err != nil {
			t.Fatal(err)
		}
	}

	// Học vụ tạo 2 đợt, đóng 1 đợt
	r := staff.Do("POST", "/surveys", map[string]any{"title": "Cơ sở vật chất tháng 10", "description": " Phòng học ", "deadline": today})
	iamtest.Expect(t, r, 201, "")
	if r.Body["message"] != "Đã tạo đợt khảo sát mới." {
		t.Fatalf("tạo: %s", r.Raw)
	}
	open := str(r.Body["id"])
	r = staff.Do("POST", "/surveys", map[string]any{"title": "Đợt sẽ đóng"})
	closed := str(r.Body["id"])
	r = staff.Do("PUT", "/surveys/"+closed, map[string]any{"title": "Đợt đã đóng"})
	expectMsg(t, r, 200, `Đã cập nhật khảo sát "Đợt đã đóng".`)

	list := iamtest.List(t, staff.Do("GET", "/surveys", nil), "surveys")
	if len(list) != 2 || list[0]["title"] != "Đợt đã đóng" || list[0]["is_active"] != false || list[1]["deadline_today"] != true ||
		list[1]["description"] != "Phòng học" || list[1]["creator_name"] != "Học vụ" || list[1]["deadline_label"] == nil {
		t.Fatalf("danh sách: %v", list)
	}

	// Học viên: thấy đợt đang mở, nộp bài
	p := stu.Do("GET", "/surveys/portal", nil)
	iamtest.Expect(t, p, 200, "")
	svs := iamtest.List(t, p, "surveys")
	if len(svs) != 1 || svs[0]["status_text"] != "Hạn: Hôm nay" || svs[0]["is_urgent"] != true || str(p.Body["student"].(map[string]any)["id"]) != a.String() {
		t.Fatalf("cổng: %s", p.Raw)
	}
	for _, c := range []struct {
		body map[string]any
		msg  string
	}{
		{map[string]any{"survey_id": open, "feedback": "Rất tốt ạ"}, "Vui lòng nhập Học viên."},
		{map[string]any{"student_id": a.String(), "feedback": "Rất tốt ạ"}, "Vui lòng chọn đợt khảo sát."},
		{map[string]any{"student_id": a.String(), "survey_id": open, "feedback": " "}, "Vui lòng nhập Góp ý."},
		{map[string]any{"student_id": a.String(), "survey_id": open, "feedback": "Tốt"}, "Trường Góp ý phải có tối thiểu 5 ký tự."},
		{map[string]any{"student_id": a.String(), "survey_id": open, "feedback": "Rất tốt ạ", "rating": 0}, "Trường Số sao phải tối thiểu là 1."},
		{map[string]any{"student_id": a.String(), "survey_id": open, "feedback": "Rất tốt ạ", "rating": 6}, "Trường Số sao không được lớn hơn 5."},
		{map[string]any{"student_id": a.String(), "survey_id": closed, "feedback": "Rất tốt ạ"}, "Đợt khảo sát đã đóng hoặc không còn tồn tại."},
	} {
		expectMsg(t, stu.Do("POST", "/surveys/portal", c.body), 422, c.msg)
	}
	// Không nộp hộ học viên không liên kết
	expectMsg(t, stu.Do("POST", "/surveys/portal", map[string]any{"student_id": b.String(), "survey_id": open, "feedback": "Rất tốt ạ"}), 403,
		"Bạn không được phép truy cập hồ sơ học viên này.")
	r = stu.Do("POST", "/surveys/portal", map[string]any{"student_id": a.String(), "survey_id": open, "rating": 4, "feedback": "Phòng học sạch"})
	iamtest.Expect(t, r, 201, "")
	if r.Body["message"] != "Cảm ơn bạn đã gửi khảo sát! Trung tâm sẽ tiếp nhận và cải thiện chất lượng dịch vụ." {
		t.Fatalf("nộp: %s", r.Raw)
	}
	mine := str(r.Body["id"])

	// Phụ huynh: chỉ thấy con đang học (bỏ học viên đã nghỉ), mặc định chọn con đầu; không xem được học viên khác
	p = parent.Do("GET", "/surveys/portal", nil)
	if sts := iamtest.List(t, p, "students"); len(sts) != 1 || sts[0]["id"] != b.String() || len(iamtest.List(t, p, "past_surveys")) != 0 {
		t.Fatalf("phụ huynh: %s", p.Raw)
	}
	expectMsg(t, parent.Do("GET", "/surveys/portal?student_id="+a.String(), nil), 403, "Bạn không được phép truy cập hồ sơ học viên này.")
	expectMsg(t, parent.Do("DELETE", "/surveys/portal/responses/"+mine, nil), 403, "Bạn không được phép truy cập hồ sơ học viên này.")
	iamtest.Expect(t, parent.Do("POST", "/surveys/portal", map[string]any{"student_id": b.String(), "survey_id": open, "feedback": "Con thích lớp"}), 201, "")

	// Học vụ xem bài đã nộp
	res := staff.Do("GET", "/surveys/"+open+"/responses", nil)
	rows := iamtest.List(t, res, "responses")
	if len(rows) != 2 || res.Body["average"] != 4.5 || rows[0]["submitter"] != "Phụ huynh B" || rows[1]["student_code"] != "HS01" {
		t.Fatalf("bài đã nộp: %s", res.Raw)
	}

	// Xóa đợt: lịch sử học viên vẫn giữ tên đợt
	expectMsg(t, staff.Do("DELETE", "/surveys/"+open, nil), 200, `Đã xóa khảo sát "Cơ sở vật chất tháng 10".`)
	iamtest.Expect(t, staff.Do("DELETE", "/surveys/"+open, nil), 404, "survey_not_found")
	p = stu.Do("GET", "/surveys/portal", nil)
	past := iamtest.List(t, p, "past_surveys")
	if len(iamtest.List(t, p, "surveys")) != 0 || len(past) != 1 || past[0]["title"] != "Khảo sát: Cơ sở vật chất tháng 10 - Học viên A" ||
		past[0]["rating"] != float64(4) || past[0]["feedback"] != "Phòng học sạch" {
		t.Fatalf("lịch sử: %s", p.Raw)
	}
	// Học viên xóa bài của mình để làm lại
	expectMsg(t, stu.Do("DELETE", "/surveys/portal/responses/"+mine, nil), 200, "Đã xóa bài khảo sát thành công! Bạn có thể thực hiện lại.")
	iamtest.Expect(t, stu.Do("DELETE", "/surveys/portal/responses/"+mine, nil), 404, "survey_response_not_found")

	var logs int
	if err := e.Pool.QueryRow(ctx, `SELECT count(*) FROM core.audit_log WHERE action LIKE 'survey.%' AND actor_id IN ($1, $2, $3)`,
		staffU.ID, stuU.ID, parentU.ID).Scan(&logs); err != nil {
		t.Fatal(err)
	}
	if logs != 7 {
		t.Fatalf("nhật ký: %d", logs)
	}
}
