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
	e, _ := setupSvc(t)
	return e
}

func setupSvc(t *testing.T) (*iamtest.Env, *surveys.Service) {
	e := iamtest.Setup(t, "ops.surveys", "ops.survey_responses", "academic.student_guardians", "academic.students", "core.audit_log")
	svc := surveys.NewService(e.Pool, e.Log)
	e.Serve(surveys.NewHandler(svc, e.Log).Routes)
	return e, svc
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

func TestSurveyManageAndResponses(t *testing.T) {
	e, svc := setupSvc(t)
	ctx := context.Background()
	hn, hcm := e.Branch("HN"), e.Branch("HCM")
	staffU, staff := e.Member("HV01", "Học vụ", []uuid.UUID{hn}, iam.RoleAcademicStaff)
	stuU, _ := e.Member("ST01", "Học viên A", []uuid.UUID{hn}, iam.RoleStudent)
	parentU, _ := e.Member("PH01", "Phụ huynh B", []uuid.UUID{hcm}, iam.RoleStudent)
	newStudent := func(code, name string, branch uuid.UUID, user *uuid.UUID) uuid.UUID {
		var id uuid.UUID
		err := e.Pool.QueryRow(ctx, `INSERT INTO academic.students (code, full_name, phone, branch_id, status, user_id)
			VALUES ($1, $2, '0900000000', $3, 'studying', $4) RETURNING id`, code, name, branch, user).Scan(&id)
		if err != nil {
			t.Fatal(err)
		}
		return id
	}
	a := newStudent("HS01", "Học viên A", hn, &stuU.ID)
	b := newStudent("HS02", "Bé B", hcm, nil)

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

	// Dữ liệu cho Cổng PH/HS: chỉ đợt đang mở; đợt đã đóng không nhận bài
	openList, err := svc.OpenSurveys(ctx)
	if err != nil || len(openList) != 1 || openList[0].ID.String() != open || openList[0].Deadline == nil {
		t.Fatalf("đợt đang mở: %v %v", openList, err)
	}
	respond := func(survey string, student, by uuid.UUID, rating int, fb string) error {
		tx, err := e.Pool.Begin(ctx)
		if err != nil {
			t.Fatal(err)
		}
		defer tx.Rollback(ctx) //nolint:errcheck
		if err := svc.Respond(ctx, tx, surveys.Answer{SurveyID: uuid.MustParse(survey), StudentID: student, By: by, Rating: rating, Feedback: fb}); err != nil {
			return err
		}
		return tx.Commit(ctx)
	}
	if err := respond(closed, a, stuU.ID, 5, "Rất tốt ạ"); err != surveys.ErrClosed {
		t.Fatalf("đợt đã đóng: %v", err)
	}
	if err := respond(uuid.NewString(), a, stuU.ID, 5, "Rất tốt ạ"); err != surveys.ErrClosed {
		t.Fatalf("đợt không tồn tại: %v", err)
	}
	if err := respond(open, a, stuU.ID, 4, "Phòng học sạch"); err != nil {
		t.Fatal(err)
	}
	if err := respond(open, b, parentU.ID, 5, "Con thích lớp"); err != nil {
		t.Fatal(err)
	}

	// Học vụ xem bài đã nộp
	res := staff.Do("GET", "/surveys/"+open+"/responses", nil)
	rows := iamtest.List(t, res, "responses")
	if len(rows) != 2 || res.Body["average"] != 4.5 || rows[0]["submitter"] != "Phụ huynh B" || rows[1]["student_code"] != "HS01" {
		t.Fatalf("bài đã nộp: %s", res.Raw)
	}

	// Xóa đợt: lịch sử học viên vẫn giữ tên đợt
	expectMsg(t, staff.Do("DELETE", "/surveys/"+open, nil), 200, `Đã xóa khảo sát "Cơ sở vật chất tháng 10".`)
	iamtest.Expect(t, staff.Do("DELETE", "/surveys/"+open, nil), 404, "survey_not_found")
	past, err := svc.StudentResponses(ctx, a)
	if err != nil || len(past) != 1 || past[0].Title != "Khảo sát: Cơ sở vật chất tháng 10 - Học viên A" || past[0].Rating != 4 ||
		past[0].Feedback != "Phòng học sạch" {
		t.Fatalf("lịch sử: %+v %v", past, err)
	}
	if owner, err := svc.ResponseStudent(ctx, past[0].ID); err != nil || owner != a {
		t.Fatalf("chủ bài: %v %v", owner, err)
	}

	var logs int
	if err := e.Pool.QueryRow(ctx, `SELECT count(*) FROM core.audit_log WHERE action LIKE 'survey.%' AND actor_id = $1`,
		staffU.ID).Scan(&logs); err != nil {
		t.Fatal(err)
	}
	if logs != 4 {
		t.Fatalf("nhật ký: %d", logs)
	}
}
