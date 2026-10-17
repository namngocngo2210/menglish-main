package syllabus

import (
	"context"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/files"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/filestore"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/syllabus/syllabusdb"
)

// Kho tài liệu giáo trình (SyllabusController::documents / storeDocument / destroyDocument / markDocumentViewed /
// documentFile). File thật ở kho file chung; tải qua /files/<id> sau khi kiểm đối tượng được xem.

const (
	subjectDocument = "syllabus_document"
	subjectProposal = "syllabus_proposal"
	docsPageSize    = 15
)

// DocumentPolicy: tài liệu, slide, ảnh, audio, video tối đa 100 MB (SyllabusDocument::ALLOWED_EXTENSIONS / MAX_KB).
var DocumentPolicy = filestore.Policy{
	Types: []string{"pdf", "doc", "docx", "ppt", "pptx", "xls", "xlsx", "jpg", "jpeg", "png", "gif", "webp",
		"mp3", "wav", "m4a", "ogg", "mp4", "mov", "webm", "m4v"},
	MaxBytes: 100 << 20,
	TooLarge: "Dung lượng file tối đa 100 MB.",
	Required: "Vui lòng chọn file tài liệu.",
}

// ProposalPolicy: file đính kèm đề xuất sửa giáo trình (tài liệu, ảnh, mp3/wav/m4a) tối đa 20 MB.
var ProposalPolicy = filestore.Policy{
	Types:    append(append(append([]string{}, filestore.Documents...), filestore.Images...), "mp3", "wav", "m4a"),
	MaxBytes: 20 << 20,
	TooLarge: "Dung lượng file đính kèm tối đa 20 MB.",
}

// docAudience: người quản lý kho tài liệu (syllabus.upload | syllabus.manage) xem mọi tài liệu; giáo viên / trợ giảng
// xem theo cờ đối tượng (quyền đối tượng Cổng giáo viên / Cổng trợ giảng).
type docAudience struct{ manage, teacher, assistant bool }

func audienceOf(actor *iam.Access) docAudience {
	return docAudience{
		manage:    actor.CanAny("syllabus.upload", "syllabus.manage"),
		teacher:   actor.Can("portal.teacher"),
		assistant: actor.Can("portal.assistant"),
	}
}

func (a docAudience) sees(teachers, assistants bool) bool {
	return a.manage || (a.teacher && teachers) || (a.assistant && assistants)
}

func (a docAudience) canDownload(teachers, assistants, downloadable bool) bool {
	return a.manage || (downloadable && a.sees(teachers, assistants))
}

// registerFileReaders: quyền tải file tài liệu / file đính kèm đề xuất.
func (s *Service) registerFileReaders(fs *files.Service) {
	fs.RegisterReader(subjectDocument, func(ctx context.Context, actor *iam.Access, f files.File) (string, error) {
		d, err := s.q.DocumentByFile(ctx, f.ID)
		if isNoRows(err) {
			return "", files.ErrNotFound
		}
		if err != nil {
			return "", err
		}
		au := audienceOf(actor)
		if !actor.Can("syllabus.view") || !au.sees(d.VisibleToTeachers, d.VisibleToAssistants) {
			return "", files.ErrNotFound
		}
		// Định dạng không xem được trên trình duyệt (Word/PowerPoint/Excel) chỉ tải về khi được phép.
		if !au.canDownload(d.VisibleToTeachers, d.VisibleToAssistants, d.Downloadable) && !filestore.InlineExt(f.Extension) {
			return "", forbidden("Định dạng này không xem trực tuyến được và bạn không có quyền tải về.")
		}
		return "", nil
	})
	fs.RegisterReader(subjectProposal, func(ctx context.Context, actor *iam.Access, f files.File) (string, error) {
		p, err := s.q.ProposalByFile(ctx, &f.ID)
		if isNoRows(err) {
			return "", files.ErrNotFound
		}
		if err != nil {
			return "", err
		}
		if !actor.Can("syllabus.view") || (!actor.Can("syllabus.approve_adjustment") && p.UserID != actor.UserID) {
			return "", files.ErrNotFound
		}
		return "", nil
	})
}

// Document: một tài liệu trong danh sách.
type Document struct {
	ID             uuid.UUID `json:"id"`
	Title          string    `json:"title"`
	Icon           string    `json:"icon"`
	Kind           string    `json:"kind"`
	CurriculumID   uuid.UUID `json:"curriculum_id"`
	Curriculum     string    `json:"curriculum"`
	Extension      string    `json:"extension"`
	SizeHuman      string    `json:"size_human"`
	StageLabel     string    `json:"stage_label"`
	AudienceLabels []string  `json:"audience_labels"`
	Downloadable   bool      `json:"downloadable"`
	CanDownload    bool      `json:"can_download"`
	Inline         bool      `json:"inline"`
	URL            string    `json:"url"`
	Viewed         bool      `json:"viewed"`
	CreatedAt      time.Time `json:"created_at"`
}

func audienceLabels(teachers, assistants bool) []string {
	out := []string{"Admin", "Học vụ", "Học thuật"}
	if teachers {
		out = append(out, "Giáo viên")
	}
	if assistants {
		out = append(out, "Trợ giảng")
	}
	return out
}

func toDocument(r syllabusdb.ListDocumentsRow, au docAudience, viewed map[uuid.UUID]bool) Document {
	kind := docKind(r.Extension)
	return Document{
		ID: r.ID, Title: r.Title, Icon: docIcon(kind), Kind: kind, CurriculumID: r.CurriculumID, Curriculum: r.CurriculumTitle,
		Extension: r.Extension, SizeHuman: sizeHuman(r.SizeBytes), StageLabel: stageOr(r.StagePosition, r.StageTitle, deref(r.StageName)),
		AudienceLabels: audienceLabels(r.VisibleToTeachers, r.VisibleToAssistants), Downloadable: r.Downloadable,
		CanDownload: au.canDownload(r.VisibleToTeachers, r.VisibleToAssistants, r.Downloadable), Inline: inlineKind(kind),
		URL: files.URL(r.FileID), Viewed: viewed[r.ID], CreatedAt: r.CreatedAt,
	}
}

// CurriculumRef: giáo trình kèm các chặng (ô chọn của form tải tài liệu, giao chặng).
type CurriculumRef struct {
	ID      uuid.UUID `json:"id"`
	Title   string    `json:"title"`
	Code    string    `json:"code"`
	Version string    `json:"version"`
	Stages  []Option  `json:"stages"`
}

func (s *Service) curriculumRefs(ctx context.Context) ([]CurriculumRef, error) {
	cs, err := s.q.ListCurricula(ctx)
	if err != nil {
		return nil, err
	}
	ids := make([]uuid.UUID, 0, len(cs))
	for _, c := range cs {
		ids = append(ids, c.ID)
	}
	stages, err := s.q.ListStages(ctx, ids)
	if err != nil {
		return nil, err
	}
	by := map[uuid.UUID][]Option{}
	for _, st := range stages {
		by[st.CurriculumID] = append(by[st.CurriculumID], Option{st.ID.String(), stageLabel(st.Position, st.Name)})
	}
	out := make([]CurriculumRef, 0, len(cs))
	for _, c := range cs {
		st := by[c.ID]
		if st == nil {
			st = []Option{}
		}
		out = append(out, CurriculumRef{ID: c.ID, Title: c.Title, Code: c.Code, Version: c.Version, Stages: st})
	}
	return out, nil
}

type DocumentFilter struct {
	CurriculumID *uuid.UUID
	Search       string
	Page         int
}

type DocumentList struct {
	Documents   []Document      `json:"documents"`
	Curriculums []CurriculumRef `json:"curriculums"`
	CanUpload   bool            `json:"can_upload"`
	Page
}

func (s *Service) Documents(ctx context.Context, actor *iam.Access, f DocumentFilter) (*DocumentList, error) {
	au := audienceOf(actor)
	lim, off, page := pageOf(f.Page, docsPageSize)
	search := likePattern(f.Search)
	rows, err := s.q.ListDocuments(ctx, syllabusdb.ListDocumentsParams{
		Manage: au.manage, Teacher: au.teacher, Assistant: au.assistant, CurriculumID: f.CurriculumID, Search: search, Lim: lim, Off: off,
	})
	if err != nil {
		return nil, err
	}
	total, err := s.q.CountDocuments(ctx, syllabusdb.CountDocumentsParams{
		Manage: au.manage, Teacher: au.teacher, Assistant: au.assistant, CurriculumID: f.CurriculumID, Search: search,
	})
	if err != nil {
		return nil, err
	}
	refs, err := s.curriculumRefs(ctx)
	if err != nil {
		return nil, err
	}
	out := &DocumentList{Documents: make([]Document, 0, len(rows)), Curriculums: refs, CanUpload: actor.Can("syllabus.upload"),
		Page: Page{Total: total, Page: page, PageSize: docsPageSize}}
	for _, r := range rows {
		out.Documents = append(out.Documents, toDocument(r, au, nil))
	}
	return out, nil
}

type DocumentInput struct {
	CurriculumID        string
	Title               string
	StageID             string
	StageName           string
	VisibleToTeachers   bool
	VisibleToAssistants bool
	Downloadable        bool
}

func (s *Service) UploadDocument(ctx context.Context, actor *iam.Access, in DocumentInput, up *filestore.Upload) (*Message, error) {
	if s.files == nil {
		return nil, &iam.Error{Status: 503, Code: "files_unavailable", Message: "Kho file chưa được cấu hình."}
	}
	curID, err := uuid.Parse(in.CurriculumID)
	if err != nil {
		return nil, iam.Invalid("Vui lòng chọn giáo trình.")
	}
	cur, err := s.q.GetCurriculum(ctx, curID)
	if isNoRows(err) {
		return nil, iam.Invalid("Giáo trình đã chọn không tồn tại.")
	}
	if err != nil {
		return nil, err
	}
	title := trimmedStr(in.Title)
	if title == "" {
		return nil, iam.Invalid("Vui lòng nhập tên tài liệu.")
	}
	if runeLen(title) > 255 {
		return nil, iam.Invalid("Tên tài liệu tối đa 255 ký tự.")
	}
	var stageID *uuid.UUID
	stageName := optText(&in.StageName)
	if in.StageID != "" {
		id, err := uuid.Parse(in.StageID)
		if err != nil {
			return nil, iam.Invalid("Chặng không thuộc giáo trình đã chọn.")
		}
		st, err := s.q.GetStage(ctx, id)
		if isNoRows(err) || (err == nil && st.CurriculumID != curID) {
			return nil, iam.Invalid("Chặng không thuộc giáo trình đã chọn.")
		}
		if err != nil {
			return nil, err
		}
		stageID = &id
		stageName = ptr(stageLabel(st.Position, st.Name))
	}
	if tooLong(stageName, 255) {
		return nil, iam.Invalid("Tên chặng tối đa 255 ký tự.")
	}
	if err := up.Check(DocumentPolicy); err != nil {
		return nil, iam.Invalid(err.Error())
	}
	docID := uuid.New()
	var size string
	err = s.inTx(ctx, func(tx pgx.Tx, q *syllabusdb.Queries) error {
		f, err := s.files.Save(ctx, tx, up, files.SaveInput{
			Owner: files.Owner{Module: "syllabus", SubjectType: subjectDocument, SubjectID: &docID},
			By:    &actor.UserID,
			Audit: audit.Entry{Module: audit.ModuleSyllabus, SubjectType: subjectDocument, SubjectID: docID.String(), SubjectLabel: title,
				Description: "Tải lên tài liệu giáo trình " + title + " (" + cur.Title + ")"},
		})
		if err != nil {
			return err
		}
		size = sizeHuman(f.Size)
		_, err = q.CreateDocument(ctx, syllabusdb.CreateDocumentParams{
			ID: docID, CurriculumID: curID, StageID: stageID, Title: title, StageName: stageName, FileID: f.ID,
			VisibleToTeachers: in.VisibleToTeachers, VisibleToAssistants: in.VisibleToAssistants, Downloadable: in.Downloadable,
			UploadedBy: &actor.UserID,
		})
		return err
	})
	if err != nil {
		return nil, err
	}
	return okID("Đã tải lên tài liệu "+title+" ("+size+").", docID), nil
}

func trimmedStr(s string) string { return trimmed(&s) }

// DeleteDocument: xóa mềm — giữ file để có thể khôi phục.
func (s *Service) DeleteDocument(ctx context.Context, actor *iam.Access, id uuid.UUID) (*Message, error) {
	d, err := s.q.GetDocument(ctx, id)
	if isNoRows(err) {
		return nil, ErrNotFound
	}
	if err != nil {
		return nil, err
	}
	err = s.inTx(ctx, func(tx pgx.Tx, q *syllabusdb.Queries) error {
		if err := q.SoftDeleteDocument(ctx, id); err != nil {
			return err
		}
		return record(ctx, tx, &actor.UserID, audit.EventDeleted, "syllabus_document.delete", subjectDocument, id, d.Title,
			"Xóa tài liệu giáo trình "+d.Title, nil)
	})
	if err != nil {
		return nil, err
	}
	return ok("Đã xóa tài liệu " + d.Title + "."), nil
}

// MarkDocumentViewed: giáo viên / trợ giảng "Đánh dấu đã xem" tài liệu được chia sẻ.
func (s *Service) MarkDocumentViewed(ctx context.Context, actor *iam.Access, id uuid.UUID) (*Message, error) {
	d, err := s.q.GetDocument(ctx, id)
	if isNoRows(err) || (err == nil && !audienceOf(actor).sees(d.VisibleToTeachers, d.VisibleToAssistants)) {
		return nil, ErrNotFound
	}
	if err != nil {
		return nil, err
	}
	if err := s.q.MarkDocumentViewed(ctx, syllabusdb.MarkDocumentViewedParams{DocumentID: id, UserID: actor.UserID}); err != nil {
		return nil, err
	}
	return ok("Đã đánh dấu đã xem tài liệu " + d.Title + "."), nil
}
