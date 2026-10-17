package placement

import (
	"context"
	"errors"
	"fmt"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/files"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/filestore"
)

// File của đề test trong kho file chung (Laravel: disk public placement_tests/..., ai có link cũng mở được).
// v2: kho riêng tư; người soạn / chấm mở qua /api/v1/files/<id> (kiểm quyền), thí sinh nhận URL ký sẵn có hạn khi
// mở link làm bài.
const (
	SubjectMedia = "placement_media" // file nghe / ảnh câu hỏi (placement-tests.media.store)
	SubjectPdf   = "placement_pdf"   // file đề PDF (placement-tests.pdf.store); gắn subject_id = id đề khi lưu đề
)

// Luật tải file như PlacementTestController::uploadMedia / uploadPdf.
var (
	mediaTooLarge = "File quá lớn (âm thanh tối đa 20 MB, ảnh tối đa 5 MB)."
	AudioPolicy   = filestore.Policy{Types: filestore.Audio, MaxBytes: 20480 << 10, TooLarge: mediaTooLarge}
	ImagePolicy   = filestore.Policy{Types: filestore.Images, MaxBytes: 5120 << 10, TooLarge: mediaTooLarge}
	PdfPolicy     = filestore.Policy{Types: []string{"pdf"}, MaxBytes: 20480 << 10, TooLarge: "File PDF tối đa 20 MB."}
)

// mediaReadPerms: người soạn / chấm / xem đề mở được file của đề.
var mediaReadPerms = []string{"placement_test.view", "placement_test.create", "placement_test.update", "placement_test.grade", "entrance_test.view", "entrance_test.grade"}

var errNoFiles = errInvalid("Kho file chưa được cấu hình.")

func (s *Service) registerFileReaders() {
	read := func(_ context.Context, a *iam.Access, _ files.File) (string, error) {
		if a.CanAny(mediaReadPerms...) {
			return "", nil
		}
		return "", iam.ErrForbidden
	}
	s.files.RegisterReader(SubjectMedia, read)
	s.files.RegisterReader(SubjectPdf, read)
}

// MediaFile: file vừa tải lên (url dán vào câu hỏi / đề).
type MediaFile struct {
	ID   uuid.UUID `json:"id"`
	URL  string    `json:"url"`
	Path string    `json:"path"`
	Name string    `json:"name"`
}

// UploadMedia: tải file nghe (kind=audio) hoặc ảnh (kind=image) cho câu hỏi. up đã qua Check theo kind.
func (s *Service) UploadMedia(ctx context.Context, a *iam.Access, kind string, up *filestore.Upload) (*MediaFile, error) {
	if s.files == nil {
		return nil, errNoFiles
	}
	label := map[string]string{"audio": "file nghe", "image": "ảnh"}[kind]
	f, err := s.files.Save(ctx, nil, up, files.SaveInput{
		Owner: files.Owner{Module: "placement", SubjectType: SubjectMedia},
		By:    &a.UserID,
		Audit: audit.Entry{Module: audit.ModuleTests, Description: "Tải " + label + " cho đề test đầu vào"},
	})
	if err != nil {
		return nil, err
	}
	return &MediaFile{ID: f.ID, URL: f.URL, Path: f.URL, Name: f.Name}, nil
}

// PdfUpload: kết quả tải file đề PDF: file đã lưu + phiếu dựng sẵn từ chữ trong file.
type PdfUpload struct {
	FileID        uuid.UUID `json:"file_id"`
	PdfPath       string    `json:"pdf_path"`
	PdfURL        string    `json:"pdf_url"`
	AnswerKeyPage *int      `json:"answer_key_page"`
	Sheet
}

// pdfPages: chữ từng trang, tối đa 20 giây (file lạ không giữ request mãi).
func (s *Service) pdfPages(ctx context.Context, r interface {
	ReadAt([]byte, int64) (int, error)
}, size int64) []string {
	done := make(chan []string, 1)
	go func() { done <- ExtractPdfPages(r, size, s.log) }()
	select {
	case p := <-done:
		return p
	case <-time.After(20 * time.Second):
		s.log.Warn("placement: đọc chữ PDF quá lâu, bỏ qua")
		return []string{}
	case <-ctx.Done():
		return []string{}
	}
}

// UploadPdf: lưu file đề PDF và dựng phiếu trả lời + đáp án để người tạo đề kiểm tra trước khi lưu.
func (s *Service) UploadPdf(ctx context.Context, a *iam.Access, up *filestore.Upload) (*PdfUpload, error) {
	if s.files == nil {
		return nil, errNoFiles
	}
	pages := s.pdfPages(ctx, up.Reader(), up.Size)
	f, err := s.files.Save(ctx, nil, up, files.SaveInput{
		Owner: files.Owner{Module: "placement", SubjectType: SubjectPdf},
		By:    &a.UserID,
		Audit: audit.Entry{Module: audit.ModuleTests, Description: "Tải file đề PDF cho đề test đầu vào"},
	})
	if err != nil {
		return nil, err
	}
	return &PdfUpload{
		FileID: f.ID, PdfPath: f.URL, PdfURL: f.URL,
		// File còn trang đáp án: dùng để đọc đáp án, nhưng phải đổi sang file không có đáp án trước khi lưu đề.
		AnswerKeyPage: AnswerKeyPage(pages), Sheet: BuildSheet(strings.Join(pages, "\n")),
	}, nil
}

// ReadAnswerKeyPdf: file đáp án riêng — chỉ đọc đáp án, không lưu file.
func (s *Service) ReadAnswerKeyPdf(ctx context.Context, up *filestore.Upload) []KeyEntry {
	return ParseKey(KeyText(strings.Join(s.pdfPages(ctx, up.Reader(), up.Size), "\n")))
}

// ParseAnswers: dán nhanh đáp án ("1A 2B 3 apple") lên phiếu.
func ParseAnswers(text string) ([]KeyEntry, error) {
	switch {
	case strings.TrimSpace(text) == "":
		return nil, errInvalid("Vui lòng nhập đáp án.")
	case utf8.RuneCountInString(text) > 20000:
		return nil, errInvalid("Đáp án không được vượt quá 20000 ký tự.")
	}
	return ParseKey(text), nil
}

var errBadPdf = errInvalid("File đề PDF không hợp lệ hoặc đã bị xoá — hãy tải lại file PDF.")

// validatedPdf: chỉ nhận file tải lên qua UploadPdf (kho file, loại placement_pdf, chưa xóa). Thí sinh xem nguyên
// file nên file mới gắn vào đề không được còn trang đáp án; current là file đề đang dùng (không đọc lại).
func (s *Service) validatedPdf(ctx context.Context, path, current string) (*string, error) {
	path = strings.TrimSpace(path)
	if path == "" {
		return nil, nil
	}
	if path == current {
		return &path, nil // đề đang dùng (kể cả đề PDF nhập từ Laravel)
	}
	id, ok := files.ParseURL(path)
	if !ok || s.files == nil {
		return nil, errBadPdf
	}
	f, err := s.files.Get(ctx, id)
	if errors.Is(err, files.ErrNotFound) || (err == nil && f.SubjectType != SubjectPdf) {
		return nil, errBadPdf
	}
	if err != nil {
		return nil, err
	}
	rc, err := s.files.Open(ctx, f)
	if err != nil {
		if errors.Is(err, files.ErrNotFound) {
			return nil, errBadPdf
		}
		return nil, err
	}
	up, err := filestore.NewUpload(f.Name, rc, PdfPolicy.MaxBytes)
	rc.Close()
	if err != nil {
		return nil, err
	}
	defer up.Close()
	if page := AnswerKeyPage(s.pdfPages(ctx, up.Reader(), up.Size)); page != nil {
		return nil, errInvalid(fmt.Sprintf(`File đề PDF còn phần đáp án (trang %d) — thí sinh sẽ thấy đáp án. Hãy bấm "Đổi file" và tải file đề không có trang đáp án; phiếu và đáp án đã đọc được vẫn giữ nguyên.`, *page))
	}
	url := files.URL(f.ID)
	return &url, nil
}

// attachPdf gắn file đề PDF vào đề vừa lưu (để biết file thuộc đề nào); file đã gắn đề khác thì giữ nguyên.
func (s *Service) attachPdf(ctx context.Context, path *string, testID uuid.UUID) {
	if s.files == nil || path == nil {
		return
	}
	id, ok := files.ParseURL(*path)
	if !ok {
		return
	}
	if _, err := s.files.Attach(ctx, nil, id, SubjectPdf, testID); err != nil && !errors.Is(err, files.ErrNotFound) {
		s.log.Error("placement: gắn file PDF vào đề", "err", err, "test", testID)
	}
}

// staffPdfURL: link mở file đề cho người soạn / chấm: file kho mới qua /api/v1/files/<id>; đề nhập từ Laravel giữ
// đường dẫn kho công khai cũ /storage/... (cần phục vụ thư mục file cũ khi chuyển hẳn).
func staffPdfURL(p *string) string {
	if p == nil || *p == "" {
		return ""
	}
	if _, ok := files.ParseURL(*p); ok {
		return *p
	}
	return "/storage/" + strings.TrimPrefix(*p, "/")
}

// publicSigner: đổi URL file của đề (kho mới) thành URL ký sẵn cho thí sinh, hạn ttl. Chỉ ký file của đề test
// (placement_media / placement_pdf): dán URL file khác (vd. hợp đồng) vào câu hỏi cũng không lộ ra ngoài.
// URL khác (link ngoài, file Laravel cũ) giữ nguyên.
func (s *Service) publicSigner(ctx context.Context, ttl time.Duration) func(string) string {
	cache := map[string]string{}
	return func(u string) string {
		id, ok := files.ParseURL(u)
		if !ok {
			return u
		}
		if v, ok := cache[u]; ok {
			return v
		}
		out := ""
		if s.files != nil {
			if f, err := s.files.Get(ctx, id); err == nil && (f.SubjectType == SubjectMedia || f.SubjectType == SubjectPdf) {
				if signed, err := s.files.SignedURL(ctx, f, ttl, ""); err == nil {
					out = signed
				} else {
					s.log.Error("placement: ký URL file của đề", "err", err, "file", id)
				}
			}
		}
		cache[u] = out
		return out
	}
}

// signPortal ký lại mọi file nghe / ảnh trong nội dung trang làm bài.
func signPortal(c *PortalContent, sign func(string) string) {
	for _, list := range [][]PortalQuestion{c.Listening, c.Reading} {
		for i := range list {
			q := &list[i]
			q.ImageURL, q.AudioSrc = sign(q.ImageURL), sign(q.AudioSrc)
			for j := range q.Options {
				q.Options[j].ImageURL = sign(q.Options[j].ImageURL)
			}
		}
	}
}
