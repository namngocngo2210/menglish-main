package tuition

import (
	"io"
	"net/http"
	"strings"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/filestore"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/httpx"
)

func (h *Handler) importForm(w http.ResponseWriter, r *http.Request) {
	out, err := h.svc.ImportForm(r.Context(), actorOf(r), queryUUID(r, "token"))
	h.reply(w, r, out, err)
}

// importPreview: form multipart branch_id + excel_file (hoặc file).
func (h *Handler) importPreview(w http.ResponseWriter, r *http.Request) {
	form, err := filestore.ReadMultipart(w, r, ImportMaxBytes, "File tối đa 10MB.")
	if err != nil {
		h.fail(w, r, uploadErr(err))
		return
	}
	defer form.Close()
	var branch *uuid.UUID
	if v := strings.TrimSpace(form.Value("branch_id")); v != "" {
		id, err := uuid.Parse(v)
		if err != nil {
			h.fail(w, r, iam.Invalid("Giá trị đã chọn trong trường chi nhánh không hợp lệ."))
			return
		}
		branch = &id
	}
	u := form.File("excel_file")
	if u == nil {
		u = form.File("file")
	}
	var name string
	var data []byte
	if u != nil && u.Size > 0 {
		if u.Size > ImportMaxBytes {
			h.fail(w, r, iam.Invalid("File tối đa 10MB."))
			return
		}
		if data, err = io.ReadAll(u.Reader()); err != nil {
			h.fail(w, r, err)
			return
		}
		name = u.Name
	}
	out, err := h.svc.PreviewTuitionImport(r.Context(), actorOf(r), branch, name, data)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusCreated, out)
}

func (h *Handler) importCommit(w http.ResponseWriter, r *http.Request) {
	var in struct {
		Token string `json:"token"`
	}
	if !httpx.Decode(w, r, &in) {
		return
	}
	id, err := uuid.Parse(strings.TrimSpace(in.Token))
	if err != nil {
		h.fail(w, r, errImportExpired)
		return
	}
	out, err := h.svc.CommitTuitionImport(r.Context(), actorOf(r), id)
	h.reply(w, r, out, err)
}

func (h *Handler) importTemplate(w http.ResponseWriter, r *http.Request) {
	b, err := h.svc.ImportTemplate()
	if err != nil {
		h.fail(w, r, err)
		return
	}
	w.Header().Set("Content-Type", "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet")
	w.Header().Set("Content-Disposition", `attachment; filename="mau-nhap-hoc-phi.xlsx"`)
	_, _ = w.Write(b)
}
