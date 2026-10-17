package syllabus_test

import (
	"testing"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam/iamtest"
)

// section: mục của một nguồn ở nhóm Đào tạo của hộp "Việc cần duyệt".
func section(t *testing.T, c *iamtest.Client, key string) map[string]any {
	t.Helper()
	r := c.Do("GET", "/approvals?group=dao-tao", nil)
	iamtest.Expect(t, r, 200, "")
	for _, s := range list(r.Body["sections"]) {
		if s["key"] == key {
			return s
		}
	}
	return nil
}

func inboxMeta(t *testing.T, c *iamtest.Client, key, id string) map[string]string {
	t.Helper()
	r := c.Do("GET", "/approvals/"+key+"/"+id, nil)
	iamtest.Expect(t, r, 200, "")
	out := map[string]string{}
	for _, m := range list(obj(r.Body["item"])["meta"]) {
		out[str(m["label"])] = str(m["value"])
	}
	return out
}

func decideInbox(c *iamtest.Client, action, ref, reason string) iamtest.Resp {
	body := map[string]any{"action": action, "items": []string{ref}}
	if reason != "" {
		body["reason"] = reason
	}
	return c.Do("POST", "/approvals/bulk", body)
}

// Đề xuất sửa giáo trình, giãn tiến độ, order đề Big Test hiện ở hộp "Việc cần duyệt" (nhóm Đào tạo) và xử lý ngay trong
// hộp bằng nghiệp vụ của màn gốc (ProposalApprovalSource / AdjustmentApprovalSource / BigTestOrderApprovalSource).
func TestApprovalInboxSources(t *testing.T) {
	e := setup(t)
	a := e.Branch("CN-A")
	_, admin := e.Member("AD001", "Quản trị", nil, iam.RoleAdmin)
	_, lead := e.Member("HT001", "Học thuật", []uuid.UUID{a}, iam.RoleAcademicLead)
	gv, teacher := e.Member("GV001", "Giáo viên A", []uuid.UUID{a}, iam.RoleTeacherFulltime)

	cur, _ := e.curriculum(lead, "CUR-IN", 1)
	e.level("INB", uuid.MustParse(cur))
	class := e.class("IN-01", a, &gv.ID, "INB")
	iamtest.Expect(t, lead.Do("POST", "/syllabus/assignments", map[string]any{"class_id": class.String()}), 201, "")

	// Gửi 2 đề xuất, 1 yêu cầu giãn tiến độ, 1 order đề
	fields := map[string]string{"curriculum_id": cur, "new_content": "Thêm bài nghe", "reason": "Học viên yếu nghe"}
	p1 := newID(t, teacher.Upload("/syllabus/proposals", fields, "", nil))
	p2 := newID(t, teacher.Upload("/syllabus/proposals", map[string]string{"curriculum_id": cur, "new_content": "Bỏ bài 3"}, "", nil))
	r := teacher.Do("POST", "/syllabus/adjustment-requests", map[string]any{"class_id": class.String(), "reason": "Lớp học chậm",
		"extra_sessions": 0})
	expectMsg(t, r, 201, "")
	pacing := newID(t, r)
	exam := vnNow().AddDate(0, 0, 10).Format(time.DateOnly)
	order := newID(t, teacher.Do("POST", "/teacher/order-test/"+class.String(), map[string]any{"test_type": "big", "exam_date": exam}))

	if b := admin.Do("GET", "/approvals/badge", nil); b.Body["count"] != 4.0 {
		t.Fatalf("badge: %s", b.Raw)
	}
	props := section(t, admin, "syllabus_proposal")
	if props == nil || props["count"] != 2.0 || props["approve"] != true || props["reject"] != true ||
		str(props["index_url"]) != "/syllabus/versions?status=pending" {
		t.Fatalf("nguồn đề xuất: %v", props)
	}
	if items := list(props["items"]); len(items) != 2 || items[0]["id"] != p2 || str(items[0]["url"]) != "/syllabus/versions?proposal="+p2 {
		t.Fatalf("đề xuất mới nhất trước: %v", items)
	}
	if m := inboxMeta(t, admin, "syllabus_proposal", p1); m["Nội dung đề xuất"] != "Thêm bài nghe" || m["Lý do"] != "Học viên yếu nghe" ||
		m["Người đề xuất"] != "Giáo viên A" {
		t.Fatalf("chi tiết đề xuất: %v", m)
	}
	adj := section(t, admin, "syllabus_adjustment")
	if adj == nil || adj["count"] != 1.0 || list(adj["items"])[0]["id"] != pacing {
		t.Fatalf("nguồn giãn tiến độ: %v", adj)
	}
	if m := inboxMeta(t, admin, "syllabus_adjustment", pacing); m["Lý do"] != "Lớp học chậm" || m["Giáo viên"] != "Giáo viên A" || m["Hạn SLA"] == "" {
		t.Fatalf("chi tiết giãn tiến độ: %v", m)
	}
	ord := section(t, admin, "big_test_order")
	if ord == nil || ord["count"] != 1.0 || ord["approve"] != false || ord["reject"] != true || list(ord["items"])[0]["id"] != order {
		t.Fatalf("nguồn order đề: %v", ord)
	}

	// Xử lý ngay trong hộp: thông báo của màn gốc
	expectMsg(t, decideInbox(admin, "approve", "syllabus_proposal:"+p1, ""), 200, "Đã phê duyệt đề xuất sửa giáo trình.")
	expectMsg(t, decideInbox(admin, "reject", "syllabus_proposal:"+p2, "Trùng nội dung"), 200, "Đã từ chối đề xuất sửa giáo trình.")
	expectMsg(t, decideInbox(admin, "approve", "syllabus_proposal:"+p1, ""), 200, "Mục này không còn chờ duyệt hoặc ngoài phạm vi của bạn.")
	expectMsg(t, decideInbox(admin, "reject", "syllabus_adjustment:"+pacing, "Chưa cần"), 200,
		"Đã từ chối yêu cầu điều chỉnh tiến độ cho lớp Lớp IN-01!")
	// Order đề cần nhập link đề + lịch thi → chỉ từ chối được trong hộp
	if r := decideInbox(admin, "approve", "big_test_order:"+order, ""); list(r.Body["results"])[0]["ok"] != false {
		t.Fatalf("duyệt order trong hộp: %s", r.Raw)
	}
	expectMsg(t, decideInbox(admin, "reject", "big_test_order:"+order, "Trùng đề"), 200, "Đã từ chối order")
	if e.count(`SELECT count(*) FROM syllabus.change_proposals WHERE status = 'pending'`) != 0 ||
		e.count(`SELECT count(*) FROM syllabus.pacing_requests WHERE status = 'rejected' AND rejection_reason = 'Chưa cần'`) != 1 ||
		e.count(`SELECT count(*) FROM syllabus.big_test_orders WHERE status = 'rejected'`) != 1 {
		t.Fatal("trạng thái sau khi xử lý ở hộp")
	}
	if b := admin.Do("GET", "/approvals/badge", nil); b.Body["count"] != 0.0 {
		t.Fatalf("badge sau xử lý: %s", b.Raw)
	}
}
