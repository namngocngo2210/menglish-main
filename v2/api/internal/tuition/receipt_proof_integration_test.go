package tuition

import (
	"bytes"
	"encoding/json"
	"strings"
	"testing"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam/iamtest"
)

func TestReceiptProofFile(t *testing.T) {
	e := setup(t)
	withRefundDeps(t, e)
	f := newFixture(e)
	send := func(c *iamtest.Client, body map[string]any, name string, content []byte) iamtest.Resp {
		raw, _ := json.Marshal(body)
		return c.Upload("/tuition/receipts", map[string]string{"payload": string(raw)}, name, content)
	}
	body := transfer(f.ta, 500_000, "FT-P1")
	delete(body, "proof_url")

	// Không minh chứng → không gửi duyệt được; file sai loại / quá lớn bị chặn.
	expectMsg(t, f.mgrA.Do("POST", "/tuition/receipts", body), 422, "Bắt buộc đính kèm minh chứng")
	expectMsg(t, send(f.mgrA, body, "ghi-chu.txt", []byte("chỉ là chữ")), 422, "Định dạng file không được phép")
	big := append(append([]byte{}, pngProof...), bytes.Repeat([]byte{1}, 5<<20)...)
	expectMsg(t, send(f.mgrA, body, "lon.png", big), 422, "Dung lượng tập tin ảnh minh chứng không được vượt quá 5120 kB.")
	expectMsg(t, f.mgrA.Upload("/tuition/receipts", map[string]string{"payload": "{"}, "a.png", pngProof), 422, "Dữ liệu phiếu thu không hợp lệ.")

	r := send(f.mgrA, body, "uy-nhiem-chi.png", pngProof)
	expectMsg(t, r, 201, "Đã gửi duyệt phiếu thu")
	id := str(r.Body["id"])
	if n := e.count(`SELECT count(*) FROM finance.receipts r JOIN core.files f ON f.id = r.proof_file_id
		WHERE r.id = $1 AND r.proof_url = '/api/v1/files/' || f.id::text AND f.subject_type = 'tuition_receipt_proof' AND f.subject_id = r.id`, id); n != 1 {
		t.Fatalf("ảnh minh chứng phiếu thu chưa vào kho file")
	}
	detail := f.mgrA.Do("GET", "/tuition/receipts/"+id, nil)
	proof := str(obj(detail.Body["receipt"])["proof_url"])
	if proof == "" {
		proof = str(detail.Body["proof_url"])
	}
	if !strings.HasPrefix(proof, "/api/v1/files/") {
		t.Fatalf("link minh chứng: %s", detail.Raw)
	}
	if g := f.mgrA.Get(proof); g.Status != 302 {
		t.Fatalf("người trong chi nhánh mở minh chứng: %d %s", g.Status, g.Raw)
	}
	if g := f.mgrB.Get(proof); g.Status != 403 && g.Status != 404 {
		t.Fatalf("chi nhánh khác mở được minh chứng: %d", g.Status)
	}
	if g := f.teacher.Get(proof); g.Status != 403 && g.Status != 404 {
		t.Fatalf("giáo viên mở được minh chứng: %d", g.Status)
	}

	// Phiếu bị trả về → người lập sửa: gỡ minh chứng (lưu nháp) bỏ liên kết file.
	expectMsg(t, f.admin.Do("POST", "/tuition/receipts/"+id+"/reject", map[string]any{"rejection_reason": "Ảnh mờ"}), 200, "")
	upd := transfer(f.ta, 500_000, "FT-P1")
	delete(upd, "proof_url")
	upd["remove_proof"], upd["submit_action"] = true, "draft"
	expectMsg(t, f.mgrA.Do("PUT", "/tuition/receipts/"+id, upd), 200, "Đã lưu nháp phiếu thu")
	if n := e.count(`SELECT count(*) FROM finance.receipts WHERE id = $1 AND proof_file_id IS NULL AND proof_url IS NULL`, id); n != 1 {
		t.Fatalf("gỡ minh chứng")
	}
	// Tải ảnh mới khi sửa (multipart POST /receipts/<id>) rồi gửi duyệt lại.
	upd["remove_proof"], upd["submit_action"] = false, "submit"
	raw, _ := json.Marshal(upd)
	expectMsg(t, f.mgrA.Upload("/tuition/receipts/"+id, map[string]string{"payload": string(raw)}, "anh-moi.png", pngProof), 200, "Đã gửi duyệt lại phiếu thu")
	if n := e.count(`SELECT count(*) FROM finance.receipts WHERE id = $1 AND status = 'pending' AND proof_file_id IS NOT NULL
		AND proof_url = '/api/v1/files/' || proof_file_id::text`, id); n != 1 {
		t.Fatalf("minh chứng mới khi sửa")
	}
}
