package filestore

import (
	"crypto/sha256"
	"errors"
	"fmt"
	"hash"
	"io"
	"mime"
	"net/http"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"unicode/utf8"
)

// Danh sách đuôi cho phép, giống hằng của SafeUploadService (Laravel).
var (
	Images    = []string{"jpg", "jpeg", "png", "gif", "webp"}
	Documents = []string{"pdf", "doc", "docx", "xls", "xlsx", "ppt", "pptx", "txt"}
	Audio     = []string{"mp3", "wav", "m4a", "ogg", "oga", "weba", "webm", "aac"}
	Video     = []string{"mp4", "mov", "webm", "m4v", "3gp"}
)

// Đuôi tương đương: nội dung nhận ra là X thì cũng hợp lệ khi danh sách cho phép ghi Y.
var aliases = map[string][]string{"jpg": {"jpeg"}, "ogg": {"oga"}, "webm": {"weba"}}

// Policy: luật kiểm một file tải lên (rule file|max|mimes của Laravel cho từng chỗ dùng).
type Policy struct {
	Types    []string // đuôi cho phép, theo nội dung file
	MaxBytes int64
	TooLarge string // thông báo khi quá dung lượng
	Required string // thông báo khi không chọn file (trống = "Vui lòng chọn file.")
}

// ValidationError: lỗi dữ liệu do người dùng (handler trả 422 với Message).
type ValidationError struct{ Message string }

func (e *ValidationError) Error() string { return e.Message }

func invalid(msg string) error { return &ValidationError{msg} }

// Upload: một file đã đọc từ request vào file tạm (không giữ cả file trong RAM), kèm SHA-256 và loại theo nội dung.
type Upload struct {
	Name   string // tên gốc (đã bỏ thư mục, cắt 255 ký tự)
	Size   int64
	SHA256 [32]byte
	Kind   Kind
	f      *os.File
	over   bool // vượt giới hạn đọc
}

// NewUpload đọc r (tối đa maxBytes) vào file tạm; dùng khi module tự sinh / nhận file không qua multipart.
func NewUpload(name string, r io.Reader, maxBytes int64) (*Upload, error) {
	u := &Upload{Name: cleanName(name)}
	f, err := os.CreateTemp("", "menglish-upload-*")
	if err != nil {
		return nil, err
	}
	u.f = f
	h := sha256.New()
	n, err := io.Copy(io.MultiWriter(f, h), io.LimitReader(r, maxBytes+1))
	if err != nil {
		u.Close()
		return nil, err
	}
	u.Size = n
	u.over = n > maxBytes
	u.finish(h)
	return u, nil
}

func (u *Upload) finish(h hash.Hash) {
	copy(u.SHA256[:], h.Sum(nil))
	head := make([]byte, 4096)
	n, _ := u.f.ReadAt(head, 0)
	u.Kind = Detect(head[:n], u.f, u.Size)
}

// Reader: đọc nội dung từ đầu (ReaderAt + Seeker để đọc PDF, ghi lên kho).
func (u *Upload) Reader() *io.SectionReader { return io.NewSectionReader(u.f, 0, u.Size) }

// Close xóa file tạm.
func (u *Upload) Close() error {
	if u == nil || u.f == nil {
		return nil
	}
	name := u.f.Name()
	u.f.Close()
	u.f = nil
	return os.Remove(name)
}

// Check kiểm dung lượng và loại theo Policy; lỗi là *ValidationError với thông báo tiếng Việt.
func (u *Upload) Check(p Policy) error {
	if u == nil {
		if p.Required != "" {
			return invalid(p.Required)
		}
		return invalid("Vui lòng chọn file.")
	}
	if u.over || (p.MaxBytes > 0 && u.Size > p.MaxBytes) {
		if p.TooLarge != "" {
			return invalid(p.TooLarge)
		}
		return invalid(fmt.Sprintf("Dung lượng tập tin không được vượt quá %d kB.", p.MaxBytes/1024))
	}
	if u.Size == 0 {
		return invalid("File tải lên bị rỗng.")
	}
	if !Allowed(u.Kind.Ext, p.Types) {
		return invalid("Định dạng file không được phép. Chấp nhận: " + strings.Join(p.Types, ", ") + ".")
	}
	return nil
}

// Allowed: đuôi (theo nội dung) nằm trong danh sách cho phép, tính cả đuôi tương đương.
func Allowed(ext string, types []string) bool {
	if ext == "" {
		return false
	}
	if slices.Contains(types, ext) {
		return true
	}
	for _, a := range aliases[ext] {
		if slices.Contains(types, a) {
			return true
		}
	}
	return false
}

// cleanName: tên gốc chỉ để hiển thị / đặt tên khi tải về: bỏ thư mục, ký tự điều khiển, tối đa 255 ký tự.
func cleanName(name string) string {
	name = filepath.Base(strings.ReplaceAll(name, `\`, "/"))
	name = strings.Map(func(r rune) rune {
		if r < 0x20 || r == 0x7F || r == '"' {
			return -1
		}
		return r
	}, name)
	name = strings.TrimSpace(name)
	if name == "" || name == "." || name == "/" {
		name = "file"
	}
	for utf8.RuneCountInString(name) > 255 {
		r := []rune(name)
		name = string(r[len(r)-255:])
	}
	return name
}

// Form: form multipart đã đọc: trường chữ + file (mỗi trường một file).
type Form struct {
	Values map[string]string
	Files  map[string]*Upload
}

// Value: trường chữ (đã cắt khoảng trắng).
func (f *Form) Value(name string) string { return strings.TrimSpace(f.Values[name]) }

// File: file của trường (nil nếu không gửi).
func (f *Form) File(name string) *Upload { return f.Files[name] }

// Close xóa mọi file tạm.
func (f *Form) Close() {
	if f == nil {
		return
	}
	for _, u := range f.Files {
		u.Close()
	}
}

const (
	maxFormValues = 64 << 10
	maxFormFiles  = 4
)

// ReadMultipart đọc form multipart/form-data theo luồng: mỗi file ghi thẳng ra file tạm, tối đa maxBytes (file lớn
// hơn được đánh dấu để Check báo tooLarge), cả request tối đa maxBytes × số file + 64 KB. Lỗi người dùng là
// *ValidationError. Nhớ defer form.Close().
func ReadMultipart(w http.ResponseWriter, r *http.Request, maxBytes int64, tooLarge string) (*Form, error) {
	ct, _, err := mime.ParseMediaType(r.Header.Get("Content-Type"))
	if err != nil || ct != "multipart/form-data" {
		return nil, invalid("Cần gửi file bằng form multipart/form-data.")
	}
	r.Body = http.MaxBytesReader(w, r.Body, (maxBytes+1)*maxFormFiles+maxFormValues)
	mr, err := r.MultipartReader()
	if err != nil {
		return nil, invalid("Dữ liệu gửi lên không hợp lệ.")
	}
	form := &Form{Values: map[string]string{}, Files: map[string]*Upload{}}
	fail := func(err error) (*Form, error) {
		form.Close()
		var tooBig *http.MaxBytesError
		if errors.As(err, &tooBig) {
			if tooLarge == "" {
				tooLarge = "File quá lớn."
			}
			return nil, invalid(tooLarge)
		}
		var ve *ValidationError
		if errors.As(err, &ve) {
			return nil, err
		}
		return nil, invalid("Dữ liệu gửi lên không hợp lệ.")
	}
	valueBytes := 0
	for {
		p, err := mr.NextPart()
		if errors.Is(err, io.EOF) {
			return form, nil
		}
		if err != nil {
			return fail(err)
		}
		name := p.FormName()
		if name == "" {
			p.Close()
			continue
		}
		if p.FileName() == "" {
			b, err := io.ReadAll(io.LimitReader(p, maxFormValues-int64(valueBytes)+1))
			p.Close()
			if err != nil {
				return fail(err)
			}
			if valueBytes += len(b); valueBytes > maxFormValues {
				return fail(invalid("Dữ liệu gửi lên quá lớn."))
			}
			form.Values[name] = string(b)
			continue
		}
		if _, dup := form.Files[name]; dup || len(form.Files) >= maxFormFiles {
			p.Close()
			return fail(invalid("Mỗi lần chỉ tải một file."))
		}
		u, err := NewUpload(p.FileName(), p, maxBytes)
		if err != nil {
			p.Close()
			return fail(err)
		}
		form.Files[name] = u
		// Close đọc bỏ phần còn lại (file quá lớn: Check báo lỗi dung lượng).
		if err := p.Close(); err != nil {
			return fail(err)
		}
	}
}
