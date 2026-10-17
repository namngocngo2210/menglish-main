package projects_test

import (
	"context"
	"fmt"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/catalogs"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam/iamtest"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/projects"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/reports"
)

var vn = time.FixedZone("ICT", 7*3600)

// "Hôm nay": Thứ Bảy 17/10/2026 09:00 giờ Việt Nam.
var now = time.Date(2026, 10, 17, 9, 0, 0, 0, vn)

type env struct {
	*iamtest.Env
	svc       *projects.Service
	penalties *fakePenalties
}

// fakePenalties ghi lại biên bản được lập (thay phân hệ Lương & biên bản).
type fakePenalties struct {
	mu       sync.Mutex
	breaches []projects.Breach
}

func (f *fakePenalties) Enabled() bool { return true }
func (f *fakePenalties) Breach(_ context.Context, b projects.Breach) (bool, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.breaches = append(f.breaches, b)
	return true, nil
}
func (f *fakePenalties) ForMilestones(_ context.Context, ids []uuid.UUID) ([]projects.Penalty, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	var out []projects.Penalty
	for i, b := range f.breaches {
		for _, id := range ids {
			if id == b.SubjectID {
				at := now
				out = append(out, projects.Penalty{ID: fmt.Sprint(i), Code: fmt.Sprintf("BB-%03d", i+1), UserID: b.UserID, MilestoneID: id, CreatedAt: &at})
			}
		}
	}
	return out, nil
}

type slaRules map[string]catalogs.SlaRule

func (r slaRules) SlaRule(_ context.Context, key string) (catalogs.SlaRule, error) {
	if v, ok := r[key]; ok {
		return v, nil
	}
	return catalogs.SlaRule{}, fmt.Errorf("không có %s", key)
}

func setup(t *testing.T) *env {
	e := iamtest.Setup(t, "syllabus.project_updates", "syllabus.milestones", "syllabus.project_members", "syllabus.projects",
		"core.notifications", "core.audit_log")
	if _, err := e.Pool.Exec(context.Background(), "DELETE FROM core.settings WHERE key = 'sla_academic_project_live_since'"); err != nil {
		t.Fatal(err)
	}
	pen := &fakePenalties{}
	svc := projects.NewService(e.Pool, e.Log).WithPenalties(pen)
	svc.SetClock(func() time.Time { return now })
	e.Serve(projects.NewHandler(svc, e.Log).Routes)
	return &env{Env: e, svc: svc, penalties: pen}
}

func msg(t *testing.T, r iamtest.Resp, status int, want string) {
	t.Helper()
	iamtest.Expect(t, r, status, "")
	if want != "" && r.Body["message"] != want {
		t.Fatalf("muốn thông báo %q, nhận %s", want, r.Raw)
	}
}

func (e *env) notices(user uuid.UUID, typ string) []string {
	e.T.Helper()
	rows, err := e.Pool.Query(context.Background(), "SELECT title FROM core.notifications WHERE user_id = $1 AND type = $2 ORDER BY created_at", user, typ)
	if err != nil {
		e.T.Fatal(err)
	}
	defer rows.Close()
	var out []string
	for rows.Next() {
		var s string
		_ = rows.Scan(&s)
		out = append(out, s)
	}
	return out
}

func project(owner uuid.UUID, members ...uuid.UUID) map[string]any {
	ids := []string{}
	for _, m := range members {
		ids = append(ids, m.String())
	}
	return map[string]any{"name": "Soạn sách IELTS Foundation", "type": "book", "owner_id": owner.String(), "member_ids": ids,
		"start_date": "2026-10-01", "deadline": "2026-12-31", "kickoff_link": "https://docs.example.com/bien-ban"}
}

func with(m map[string]any, kv ...any) map[string]any {
	out := map[string]any{}
	for k, v := range m {
		out[k] = v
	}
	for i := 0; i+1 < len(kv); i += 2 {
		out[kv[i].(string)] = kv[i+1]
	}
	return out
}

func milestone(title, due string, qty float64, assignee *uuid.UUID) map[string]any {
	m := map[string]any{"title": title, "due_date": due, "target_quantity": qty, "unit": "unit"}
	if assignee != nil {
		m["assignee_id"] = assignee.String()
	}
	return m
}

// create: tạo dự án qua API, trả id.
func create(t *testing.T, c *iamtest.Client, body map[string]any) string {
	t.Helper()
	r := c.Do("POST", "/academic-projects", body)
	iamtest.Expect(t, r, 201, "")
	return r.Body["id"].(string)
}

func milestoneID(t *testing.T, c *iamtest.Client, pid, title string) string {
	t.Helper()
	r := c.Do("GET", "/academic-projects/"+pid, nil)
	iamtest.Expect(t, r, 200, "")
	for _, m := range iamtest.List(t, r, "milestones") {
		if m["title"] == title {
			return m["id"].(string)
		}
	}
	t.Fatalf("không thấy mốc %q: %s", title, r.Raw)
	return ""
}

func TestPermissions(t *testing.T) {
	e := setup(t)
	hn := e.Branch("HN01")
	iamtest.Expect(t, e.Anon().Do("GET", "/academic-projects", nil), 401, "")
	// Tư vấn không có quyền dự án học thuật
	_, sales := e.Member("TV01", "Lê Tư Vấn", []uuid.UUID{hn}, "sales_consultant")
	iamtest.Expect(t, sales.Do("GET", "/academic-projects", nil), 403, "")
	// Giáo viên: xem dự án mình tham gia, không tạo, không xem báo cáo
	_, gv := e.Member("GV01", "Nguyễn Thị Hoa", []uuid.UUID{hn}, "teacher_fulltime")
	r := gv.Do("GET", "/academic-projects", nil)
	iamtest.Expect(t, r, 200, "")
	if r.Body["can_manage"] != false {
		t.Fatalf("giáo viên không quản lý dự án: %s", r.Raw)
	}
	iamtest.Expect(t, gv.Do("POST", "/academic-projects", map[string]any{}), 403, "")
	iamtest.Expect(t, gv.Do("GET", "/academic-projects/report", nil), 403, "")
	// Quản lý cơ sở: xem mọi dự án + báo cáo, không tạo / chốt
	_, mgr := e.Member("QL01", "Phạm Quản Lý", []uuid.UUID{hn}, "manager")
	iamtest.Expect(t, mgr.Do("GET", "/academic-projects/report", nil), 200, "")
	iamtest.Expect(t, mgr.Do("POST", "/academic-projects", map[string]any{}), 403, "")
	iamtest.Expect(t, mgr.Do("POST", "/academic-projects/"+uuid.NewString()+"/lock", nil), 403, "")
	iamtest.Expect(t, mgr.Do("DELETE", "/academic-projects/milestones/"+uuid.NewString(), nil), 403, "")
}

func TestProjectLifecycle(t *testing.T) {
	e := setup(t)
	hn := e.Branch("HN01")
	lead, lc := e.Member("HT01", "Trần Học Thuật", []uuid.UUID{hn}, "academic_lead")
	gv, gvc := e.Member("GV01", "Nguyễn Thị Hoa", []uuid.UUID{hn}, "teacher_fulltime")
	gv2, gv2c := e.Member("GV02", "Phạm Văn Nam", []uuid.UUID{hn}, "teacher_fulltime")
	sales := e.User("TV01", "Lê Tư Vấn", []uuid.UUID{hn}, "sales_consultant")

	// Kiểm tra form
	base := project(lead.ID, gv.ID)
	msg(t, lc.Do("POST", "/academic-projects", with(base, "name", "  ")), 422, "Nhập tên dự án.")
	msg(t, lc.Do("POST", "/academic-projects", with(base, "owner_id", "")), 422, "Chọn người phụ trách.")
	msg(t, lc.Do("POST", "/academic-projects", with(base, "deadline", "2026-09-01")), 422, "Deadline phải sau ngày bắt đầu.")
	msg(t, lc.Do("POST", "/academic-projects", with(base, "kickoff_link", "docs.example.com")), 422,
		"Link biên bản họp phải là đường dẫn đầy đủ (https://…).")
	msg(t, lc.Do("POST", "/academic-projects", with(base, "member_ids", []string{sales.ID.String()})), 422,
		"Có người được chọn không còn hoạt động hoặc không có quyền dự án học thuật.")

	// Tạo: mã DA-<năm>-NNN, thành viên = danh sách + người phụ trách, báo thành viên
	r := lc.Do("POST", "/academic-projects", base)
	msg(t, r, 201, `Đã tạo dự án DA-2026-001. Thêm các mốc rồi bấm "Chốt tiến độ".`)
	pid := r.Body["id"].(string)
	if n := e.notices(gv.ID, "academic_project_member"); len(n) != 1 || n[0] != "Bạn tham gia dự án Soạn sách IELTS Foundation" {
		t.Fatalf("thông báo thành viên: %v", n)
	}
	if n := e.notices(lead.ID, "academic_project_member"); len(n) != 0 {
		t.Fatalf("không báo cho người tạo: %v", n)
	}
	other := create(t, lc, with(project(lead.ID), "name", "Chương trình Kids", "type", "curriculum"))
	r = lc.Do("GET", "/academic-projects/"+other, nil)
	if r.Body["project"].(map[string]any)["code"] != "DA-2026-002" {
		t.Fatalf("mã dự án thứ hai: %s", r.Raw)
	}

	// Phạm vi: GV2 chưa tham gia → không thấy; GV1 thấy dự án mình là thành viên
	if l := iamtest.List(t, gv2c.Do("GET", "/academic-projects", nil), "data"); len(l) != 0 {
		t.Fatalf("GV2 không thấy dự án: %v", l)
	}
	iamtest.Expect(t, gv2c.Do("GET", "/academic-projects/"+pid, nil), 404, "")
	if l := iamtest.List(t, gvc.Do("GET", "/academic-projects", nil), "data"); len(l) != 1 || l[0]["code"] != "DA-2026-001" {
		t.Fatalf("GV1 thấy dự án tham gia: %v", l)
	}
	// Lọc theo loại / tìm kiếm
	if l := iamtest.List(t, lc.Do("GET", "/academic-projects?type=curriculum", nil), "data"); len(l) != 1 {
		t.Fatalf("lọc loại: %v", l)
	}
	if l := iamtest.List(t, lc.Do("GET", "/academic-projects?search=DA-2026-001", nil), "data"); len(l) != 1 {
		t.Fatalf("tìm theo mã: %v", l)
	}

	// Chốt khi chưa có mốc
	msg(t, lc.Do("POST", "/academic-projects/"+pid+"/lock", nil), 409, "Thêm ít nhất một mốc (deadline + khối lượng) trước khi chốt tiến độ.")

	// Mốc: kiểm tra form, người nhận tự thành thành viên + được báo
	msg(t, lc.Do("POST", "/academic-projects/"+pid+"/milestones", milestone("", "2026-10-30", 12, nil)), 422, "Nhập tên mốc.")
	msg(t, lc.Do("POST", "/academic-projects/"+pid+"/milestones", milestone("Unit 1-4", "", 12, nil)), 422, "Chọn deadline của mốc.")
	msg(t, lc.Do("POST", "/academic-projects/"+pid+"/milestones", milestone("Unit 1-4", "2026-10-30", 0, nil)), 422, "Khối lượng phải lớn hơn 0.")
	msg(t, lc.Do("POST", "/academic-projects/"+pid+"/milestones", with(milestone("Unit 1-4", "2026-10-30", 12, nil), "unit", " ")), 422,
		"Nhập đơn vị khối lượng (unit, trang, bài…).")
	msg(t, lc.Do("POST", "/academic-projects/"+pid+"/milestones", milestone("Unit 1-4", "2026-10-30", 12, &sales.ID)), 422,
		"Người nhận không còn hoạt động hoặc không có quyền dự án học thuật.")
	msg(t, lc.Do("POST", "/academic-projects/"+pid+"/milestones", milestone("Unit 1-4", "2026-10-30", 12, &gv2.ID)), 201, `Đã thêm mốc "Unit 1-4".`)
	if n := e.notices(gv2.ID, "academic_project_milestone"); len(n) != 1 || n[0] != `Bạn được giao mốc "Unit 1-4"` {
		t.Fatalf("thông báo giao mốc: %v", n)
	}
	r = gv2c.Do("GET", "/academic-projects/"+pid, nil)
	iamtest.Expect(t, r, 200, "")
	if r.Body["can_update"] != true || r.Body["can_manage"] != false || r.Body["can_respond"] != false {
		t.Fatalf("quyền của người nhận mốc: %s", r.Raw)
	}
	ms := iamtest.List(t, r, "milestones")
	if ms[0]["mine"] != true || ms[0]["quantity_label"] != "0 / 12 unit" || ms[0]["status_label"] != "Chưa bắt đầu" {
		t.Fatalf("dòng mốc: %v", ms[0])
	}
	if p := r.Body["project"].(map[string]any); p["members_count"] != float64(3) || p["status_label"] != "Lên kế hoạch" {
		t.Fatalf("dự án: %v", p)
	}

	// Sửa mốc: đổi người nhận → báo người mới
	mid := milestoneID(t, lc, pid, "Unit 1-4")
	msg(t, lc.Do("PUT", "/academic-projects/milestones/"+mid, with(milestone("Unit 1-4", "2026-10-31", 12.5, &gv.ID), "description", "Bản nháp")), 200,
		`Đã lưu mốc "Unit 1-4".`)
	if n := e.notices(gv.ID, "academic_project_milestone"); len(n) != 1 {
		t.Fatalf("báo người nhận mới: %v", n)
	}

	// Chốt tiến độ → Đang thực hiện, báo người tham gia
	msg(t, lc.Do("POST", "/academic-projects/"+pid+"/lock", nil), 200, `Đã chốt tiến độ. Dự án chuyển sang "Đang thực hiện".`)
	msg(t, lc.Do("POST", "/academic-projects/"+pid+"/lock", nil), 409, "Chỉ chốt tiến độ khi dự án đang lên kế hoạch.")
	if n := e.notices(gv2.ID, "academic_project_locked"); len(n) != 1 {
		t.Fatalf("báo chốt tiến độ: %v", n)
	}
	r = lc.Do("GET", "/academic-projects/"+pid, nil)
	if p := r.Body["project"].(map[string]any); p["status"] != "active" || p["locker"] != "Trần Học Thuật" {
		t.Fatalf("sau chốt: %v", p)
	}

	// Trạng thái
	msg(t, lc.Do("POST", "/academic-projects/"+pid+"/status", map[string]any{"action": "resume"}), 409,
		"Trạng thái hiện tại của dự án không cho phép thao tác này.")
	msg(t, lc.Do("POST", "/academic-projects/"+pid+"/status", map[string]any{"action": "pause"}), 200,
		"Đã tạm dừng dự án (mốc không bị tính trễ khi tạm dừng).")
	msg(t, lc.Do("POST", "/academic-projects/"+pid+"/status", map[string]any{"action": "complete"}), 200, "Đã đóng dự án: hoàn thành.")
	msg(t, lc.Do("POST", "/academic-projects/"+pid+"/status", map[string]any{"action": "cancel"}), 409, "")

	// Sửa dự án: bỏ GV1 khỏi thành viên, thêm GV2 → báo GV2
	msg(t, lc.Do("PUT", "/academic-projects/"+other, with(project(lead.ID, gv2.ID), "name", "Chương trình Kids 2027")), 200, "Đã lưu dự án.")
	if n := e.notices(gv2.ID, "academic_project_member"); len(n) != 1 || n[0] != "Bạn tham gia dự án Chương trình Kids 2027" {
		t.Fatalf("báo thành viên mới: %v", n)
	}
	msg(t, lc.Do("DELETE", "/academic-projects/"+other, nil), 200, "Đã xóa dự án DA-2026-002.")
	iamtest.Expect(t, lc.Do("GET", "/academic-projects/"+other, nil), 404, "")
	msg(t, lc.Do("DELETE", "/academic-projects/milestones/"+mid, nil), 200, `Đã xóa mốc "Unit 1-4".`)

	var n int
	_ = e.Pool.QueryRow(context.Background(), "SELECT count(*) FROM core.audit_log WHERE subject_type LIKE 'academic_project%'").Scan(&n)
	if n < 8 {
		t.Fatalf("nhật ký dự án: %d dòng", n)
	}
}

func TestProgressUpdates(t *testing.T) {
	e := setup(t)
	hn := e.Branch("HN01")
	lead, lc := e.Member("HT01", "Trần Học Thuật", []uuid.UUID{hn}, "academic_lead")
	gv, gvc := e.Member("GV01", "Nguyễn Thị Hoa", []uuid.UUID{hn}, "teacher_fulltime")
	gv2, gv2c := e.Member("GV02", "Phạm Văn Nam", []uuid.UUID{hn}, "teacher_fulltime")
	_, mgr := e.Member("QL01", "Phạm Quản Lý", []uuid.UUID{hn}, "manager")
	pid := create(t, lc, project(gv.ID, gv2.ID))
	msg(t, lc.Do("POST", "/academic-projects/"+pid+"/milestones", milestone("Unit 1-4", "2026-10-30", 12, &gv2.ID)), 201, "")
	msg(t, lc.Do("POST", "/academic-projects/"+pid+"/milestones", milestone("Unit 5-8", "2026-11-15", 8, &gv.ID)), 201, "")
	m1 := milestoneID(t, lc, pid, "Unit 1-4")
	url := "/academic-projects/" + pid + "/updates"

	// Kiểm tra form
	msg(t, gv2c.Do("POST", url, map[string]any{"content": " "}), 422, "Nhập nội dung đã làm / tiến độ.")
	msg(t, gv2c.Do("POST", url, map[string]any{"content": "Xong unit 1", "quantity_done": "abc", "milestone_id": m1}), 422, "Khối lượng phải là số.")
	msg(t, gv2c.Do("POST", url, map[string]any{"content": "Xong unit 1", "quantity_done": 3}), 422, "Chọn mốc để ghi khối lượng hoàn thành.")
	msg(t, gv2c.Do("POST", url, map[string]any{"content": "Xong", "milestone_id": uuid.NewString()}), 422, "Mốc không thuộc dự án này.")
	msg(t, gvc.Do("POST", url, map[string]any{"content": "Xong", "milestone_id": m1, "quantity_done": 1}), 422,
		"Bạn chỉ cập nhật được mốc mình được giao.")
	msg(t, gv2c.Do("POST", url, map[string]any{"content": "Xong", "links_text": "https://a.vn/1\nftp://b.vn"}), 422,
		"Link không hợp lệ: ftp://b.vn. Dán đường dẫn đầy đủ (https://…), mỗi dòng một link.")
	msg(t, gv2c.Do("POST", url, map[string]any{"content": "Xong", "links_text": "https://a.vn/1\nhttps://a.vn/2\nhttps://a.vn/3\nhttps://a.vn/4\nhttps://a.vn/5\nhttps://a.vn/6"}),
		422, "Tối đa 5 link mỗi lần cập nhật.")
	// Quản lý cơ sở (view_all) thấy dự án nhưng không tham gia → không cập nhật được
	iamtest.Expect(t, mgr.Do("POST", url, map[string]any{"content": "Xong"}), 403, "")

	// Cập nhật khối lượng: mốc chuyển Đang làm, báo người phụ trách (GV1) kèm khó khăn
	msg(t, gv2c.Do("POST", url, map[string]any{"content": "Xong unit 1-2", "quantity_done": "6", "milestone_id": m1,
		"difficulties": "Thiếu audio", "links_text": "https://drive.example.com/u1\n\nhttps://drive.example.com/u1"}), 201, "Đã gửi cập nhật tiến độ.")
	if n := e.notices(gv.ID, "academic_project_update"); len(n) != 1 || n[0] != "Khó khăn cần hỗ trợ — Soạn sách IELTS Foundation" {
		t.Fatalf("báo người phụ trách: %v", n)
	}
	r := gv2c.Do("GET", "/academic-projects/"+pid, nil)
	ms := iamtest.List(t, r, "milestones")
	if ms[0]["status"] != "in_progress" || ms[0]["progress"] != float64(50) || ms[0]["done_quantity"] != float64(6) {
		t.Fatalf("mốc sau cập nhật: %v", ms[0])
	}
	ups := iamtest.List(t, r, "updates")
	if len(ups) != 1 || ups[0]["quantity"] != "6 unit" || len(ups[0]["links"].([]any)) != 1 || ups[0]["user"] != "Phạm Văn Nam" {
		t.Fatalf("lịch sử cập nhật: %v", ups)
	}
	if p := r.Body["project"].(map[string]any); p["progress"] != float64(25) {
		t.Fatalf("tiến độ dự án = trung bình mốc: %v", p["progress"])
	}
	upID := ups[0]["id"].(string)

	// Phản hồi: GV2 không phải người phụ trách → 403; GV1 (phụ trách) phản hồi được
	iamtest.Expect(t, gv2c.Do("POST", "/academic-projects/updates/"+upID+"/respond", map[string]any{"response": "ok"}), 403, "")
	msg(t, gvc.Do("POST", "/academic-projects/updates/"+upID+"/respond", map[string]any{"response": ""}), 422, "Nhập nội dung phản hồi.")
	msg(t, gvc.Do("POST", "/academic-projects/updates/"+upID+"/respond", map[string]any{"response": "Đã gửi audio"}), 200, "Đã gửi phản hồi.")
	if n := e.notices(gv2.ID, "academic_project_response"); len(n) != 1 {
		t.Fatalf("báo phản hồi: %v", n)
	}

	// Đánh dấu hoàn thành: khối lượng xong = khối lượng cần làm
	msg(t, gv2c.Do("POST", url, map[string]any{"content": "Hoàn thành", "milestone_id": m1, "marks_complete": true}), 201,
		"Đã cập nhật và đánh dấu hoàn thành mốc.")
	r = lc.Do("GET", "/academic-projects/"+pid, nil)
	ms = iamtest.List(t, r, "milestones")
	if ms[0]["status"] != "done" || ms[0]["done_quantity"] != float64(12) || ms[0]["completed_at"] == nil {
		t.Fatalf("mốc hoàn thành: %v", ms[0])
	}

	// Dự án đã đóng: không cập nhật nữa
	msg(t, lc.Do("POST", "/academic-projects/"+pid+"/status", map[string]any{"action": "cancel"}), 200, "Đã hủy dự án.")
	msg(t, gv2c.Do("POST", url, map[string]any{"content": "Thêm"}), 409, "Dự án đã đóng, không cập nhật tiến độ được nữa.")
	_ = lead
}

func TestReportSlaKpi(t *testing.T) {
	e := setup(t)
	ctx := context.Background()
	hn := e.Branch("HN01")
	lead, lc := e.Member("HT01", "Trần Học Thuật", []uuid.UUID{hn}, "academic_lead")
	gv, gvc := e.Member("GV01", "Nguyễn Thị Hoa", []uuid.UUID{hn}, "teacher_fulltime")
	_, mgr := e.Member("QL01", "Phạm Quản Lý", []uuid.UUID{hn}, "manager")
	pid := create(t, lc, project(lead.ID, gv.ID))
	// Quá hạn (15/10), đến hạn hôm nay (17/10), mốc chưa giao của người phụ trách (hạn 20/10, đã xong hôm nay)
	msg(t, lc.Do("POST", "/academic-projects/"+pid+"/milestones", milestone("Unit 1-4", "2026-10-15", 12, &gv.ID)), 201, "")
	msg(t, lc.Do("POST", "/academic-projects/"+pid+"/milestones", milestone("Unit 5-8", "2026-10-17", 8, &gv.ID)), 201, "")
	msg(t, lc.Do("POST", "/academic-projects/"+pid+"/milestones", with(milestone("Đề cương", "2026-10-20", 1, nil), "status", "done")), 201, "")
	msg(t, lc.Do("POST", "/academic-projects/"+pid+"/lock", nil), 200, "")
	msg(t, gvc.Do("POST", "/academic-projects/"+pid+"/updates", map[string]any{"content": "Đang làm", "difficulties": "Cần thêm người"}), 201, "")

	// SLA: lần chạy đầu ghi thời điểm bật → mốc hạn trước đó không bị phạt hồi tố; chỉ nhắc mốc hạn hôm nay
	res, err := e.svc.RunSla(ctx)
	if err != nil || res.Breached != 0 || res.Reminded != 1 {
		t.Fatalf("SLA lần đầu: %+v %v", res, err)
	}
	if n := e.notices(gv.ID, "academic_project_due"); len(n) != 1 || n[0] != `Sắp đến hạn mốc "Unit 5-8"` {
		t.Fatalf("nhắc hạn: %v", n)
	}
	// Bật SLA từ trước → mốc quá hạn bị lập biên bản cho người nhận, một lần
	if _, err := e.Pool.Exec(ctx, `UPDATE core.settings SET value = to_jsonb('2026-10-01T00:00:00+07:00'::text) WHERE key = 'sla_academic_project_live_since'`); err != nil {
		t.Fatal(err)
	}
	res, err = e.svc.RunSla(ctx)
	if err != nil || res.Breached != 1 || res.Reminded != 0 {
		t.Fatalf("SLA lập biên bản: %+v %v", res, err)
	}
	b := e.penalties.breaches[0]
	if b.Rule != projects.SlaLate || *b.UserID != gv.ID || !strings.Contains(b.Label, `mốc "Unit 1-4"`) || b.Facts[3].Value != "15/10/2026" {
		t.Fatalf("biên bản: %+v", b)
	}
	if res, _ = e.svc.RunSla(ctx); res.Breached != 0 {
		t.Fatalf("không lập lại biên bản: %+v", res)
	}
	// SLA tắt: không quét trễ
	off := projects.NewService(e.Pool, e.Log).WithSla(slaRules{projects.SlaLate: {Value: 1, Enabled: false}, projects.SlaRemind: {Value: 24, Enabled: false}})
	off.SetClock(func() time.Time { return now })
	if res, _ = off.RunSla(ctx); res.Breached+res.Reminded != 0 {
		t.Fatalf("SLA tắt: %+v", res)
	}

	// Báo cáo
	r := mgr.Do("GET", "/academic-projects/report?month=2026-10", nil)
	iamtest.Expect(t, r, 200, "")
	st := r.Body["stats"].(map[string]any)
	if st["active"] != float64(1) || st["overdue"] != float64(1) || st["due_in_month"] != float64(3) || st["done_in_month"] != float64(1) ||
		st["open_issues"] != float64(1) || st["penalties_in_month"] != float64(1) {
		t.Fatalf("số liệu báo cáo: %v", st)
	}
	mem := iamtest.List(t, r, "members")
	if len(mem) != 1 || mem[0]["name"] != "Nguyễn Thị Hoa" || mem[0]["overdue"] != float64(1) || mem[0]["in_progress"] != float64(1) ||
		mem[0]["updates_in_month"] != float64(1) || mem[0]["penalties"] != float64(1) {
		t.Fatalf("theo thành viên: %v", mem)
	}
	if is := iamtest.List(t, r, "issues"); len(is) != 1 || is[0]["responded"] != false {
		t.Fatalf("khó khăn: %v", is)
	}
	if p := iamtest.List(t, r, "penalties"); len(p) != 1 || p[0]["milestone"] != "Unit 1-4" {
		t.Fatalf("biên bản trong tháng: %v", p)
	}

	// KPI "Mốc dự án học thuật đúng hạn": GV1 — Unit 1-4 trễ 2 ngày (0,5); Unit 5-8 chưa tới hạn → chưa tính
	src := e.svc.KpiSources()["academic_deliverable_on_time"]
	from := time.Date(2026, 10, 1, 0, 0, 0, 0, vn)
	to := time.Date(2026, 10, 31, 23, 59, 59, 0, vn)
	got, err := src(ctx, gv.ID, from, to)
	if err != nil || len(got.Records) != 1 || *got.Records[0].Factor != 0.5 ||
		got.Records[0].Text != `Mốc "Unit 1-4" (Soạn sách IELTS Foundation): hạn 15/10, chưa xong, đã trễ 2 ngày` {
		t.Fatalf("KPI người nhận: %+v %v", got, err)
	}
	// Người phụ trách: mốc chưa giao đã xong đúng hạn
	got, _ = src(ctx, lead.ID, from, to)
	if len(got.Records) != 1 || *got.Records[0].Factor != 1 || !strings.HasSuffix(got.Records[0].Text, "xong đúng hạn") {
		t.Fatalf("KPI người phụ trách: %+v", got)
	}

	// Báo cáo ngày Học thuật (17/10): 1 mốc đến hạn, 1 quá hạn, 1 hoàn thành trong ngày
	m := e.svc.ReportMetrics(reports.Skeleton(), e.IAM.LoadAccess)
	secs, _, err := m.Daily(ctx, reports.Writer{ID: lead.ID}, time.Date(2026, 10, 17, 0, 0, 0, 0, vn), reports.AudienceAcademic)
	if err != nil {
		t.Fatal(err)
	}
	items := map[string]reports.Item{}
	for _, it := range secs[0].Items {
		items[it.Key] = it
	}
	if *items["milestones_due"].Count != 1 || *items["milestones_overdue"].Count != 1 || *items["milestones_done"].Count != 1 ||
		items["milestones_due"].Unavailable != "" {
		t.Fatalf("báo cáo ngày: %+v", items)
	}
	// Báo cáo tuần 12–18/10: 2 mốc đến hạn, dòng gợi ý "kế hoạch" từ mốc chưa xong
	sum, sug, err := m.WeeklyAcademic(ctx, reports.Writer{ID: gv.ID}, time.Date(2026, 10, 12, 0, 0, 0, 0, vn), time.Date(2026, 10, 18, 0, 0, 0, 0, vn))
	if err != nil {
		t.Fatal(err)
	}
	if *sum.Items[0].Count != 2 || len(sug["planned"]) != 2 || *sug["planned"][0].Detail != "Đã làm 0/12 unit · Phụ trách: Nguyễn Thị Hoa · Quá hạn" {
		t.Fatalf("báo cáo tuần: %+v %+v", sum.Items[0], sug["planned"])
	}

	// Lịch Tổng quan: GV1 thấy mốc mình nhận còn hạn
	dash := e.svc.DashboardMilestones()
	ga, _ := e.IAM.LoadAccess(ctx, gv.ID)
	if ok, _ := dash.Sees(ctx, ga); !ok {
		t.Fatal("GV1 đang nhận mốc → có lịch")
	}
	ag, err := dash.Agenda(ctx, ga, time.Date(2026, 10, 17, 0, 0, 0, 0, vn), time.Date(2026, 10, 23, 23, 59, 0, 0, vn))
	if err != nil || len(ag) != 1 || ag[0].Title != "Unit 5-8" || ag[0].KindLabel != "Hạn mốc dự án" {
		t.Fatalf("lịch: %+v %v", ag, err)
	}
}

func TestFormatQuantity(t *testing.T) {
	for v, want := range map[float64]string{12: "12", 12.5: "12,5", 1234.25: "1.234,25", 0: "0", 0.1: "0,1", 1000000: "1.000.000"} {
		if got := projects.FormatQuantity(v); got != want {
			t.Errorf("FormatQuantity(%v) = %q, muốn %q", v, got, want)
		}
	}
	links, err := projects.ParseLinks("  https://a.vn/1 \r\n\r\nhttps://a.vn/1\nhttp://b.vn ")
	if err != nil || len(links) != 2 {
		t.Fatalf("ParseLinks: %v %v", links, err)
	}
}
