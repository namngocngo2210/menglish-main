// Package files: kho file chung của v2 (bảng core.files + nội dung trong internal/platform/filestore).
//
// Cách module khác dùng (vd. học phí gắn ảnh minh chứng hoàn tiền / phiếu thu):
//
//	form, err := filestore.ReadMultipart(w, r, policy.MaxBytes, policy.TooLarge) // trong handler; defer form.Close()
//	up := form.File("file"); err = up.Check(policy)                             // lỗi → iam.Invalid(err.Error())
//	f, err := fileSvc.Save(ctx, tx, up, files.SaveInput{
//	    Owner: files.Owner{Module: "tuition", SubjectType: "refund_proof", SubjectID: &refundID, BranchID: &branchID},
//	    By: &actor.UserID,
//	    Audit: audit.Entry{Module: audit.ModuleTuition, SubjectType: "refund", SubjectID: refundID.String(),
//	        SubjectLabel: "HT-00012", Description: "Tải ảnh minh chứng hoàn tiền"},
//	})
//	fileSvc.RegisterReader("refund_proof", func(ctx context.Context, a *iam.Access, f files.File) (string, error) {
//	    // kiểm actor xem được phiếu hoàn tiền *f.SubjectID không; trả tên file khi tải về ("" = tên gốc)
//	})
//
// Giao diện mở file qua f.URL (/api/v1/files/<id>): API kiểm quyền bằng hàm đã đăng ký theo subject_type rồi chuyển
// sang URL ký sẵn hạn 5 phút (driver local: /api/v1/files/blob/<token>; R2: URL ký của bucket).
package files

import (
	"context"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"strings"
	"sync"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgxpool"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/files/filesdb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/filestore"
)

const (
	// APIBase: gốc API; URL ổn định của file là APIBase + "/files/<id>".
	APIBase = "/api/v1"
	// BlobPrefix: route công khai phục vụ file của driver local bằng token ký (token là quyền đọc, có hạn).
	BlobPrefix = "/files/blob"
	// RetainDeleted: file xóa mềm được giữ nội dung 30 ngày rồi mới xóa hẳn (lỡ tay xóa vẫn lấy lại được).
	RetainDeleted = 30 * 24 * time.Hour
)

func init() {
	audit.RegisterSubject("file", "File")
	audit.RegisterPath("/files", audit.ModuleMedia)
}

// File: thông tin một file (không có khóa lưu trữ: chỉ tải được qua URL sau khi kiểm quyền).
type File struct {
	ID          uuid.UUID  `json:"id"`
	OwnerModule string     `json:"owner_module"`
	SubjectType string     `json:"subject_type"`
	SubjectID   *uuid.UUID `json:"subject_id"`
	BranchID    *uuid.UUID `json:"branch_id"`
	Name        string     `json:"name"`
	Extension   string     `json:"extension"`
	MimeType    string     `json:"mime_type"`
	Size        int64      `json:"size"`
	SHA256      string     `json:"sha256"`
	UploadedBy  *uuid.UUID `json:"uploaded_by"`
	CreatedAt   time.Time  `json:"created_at"`
	URL         string     `json:"url"`
	DeletedAt   *time.Time `json:"-"`
	key         string
}

// URL: đường dẫn ổn định của file trong ứng dụng (lưu được vào dữ liệu, vd. file nghe của câu hỏi).
func URL(id uuid.UUID) string { return APIBase + "/files/" + id.String() }

// ParseURL: id của file từ URL dạng URL(id) (có thể kèm tên miền); không phải URL kho file thì false.
func ParseURL(s string) (uuid.UUID, bool) {
	s = strings.TrimSpace(s)
	if i := strings.Index(s, "://"); i >= 0 {
		rest := s[i+3:]
		j := strings.IndexByte(rest, '/')
		if j < 0 {
			return uuid.Nil, false
		}
		s = rest[j:]
	}
	raw, ok := strings.CutPrefix(s, APIBase+"/files/")
	if !ok || len(raw) != 36 {
		return uuid.Nil, false
	}
	id, err := uuid.Parse(raw)
	return id, err == nil
}

func toFile(r filesdb.CoreFile) File {
	return File{
		ID: r.ID, OwnerModule: r.OwnerModule, SubjectType: r.SubjectType, SubjectID: r.SubjectID, BranchID: r.BranchID,
		Name: r.OriginalName, Extension: r.Extension, MimeType: r.MimeType, Size: r.SizeBytes,
		SHA256: hex.EncodeToString(r.Sha256), UploadedBy: r.UploadedBy, CreatedAt: r.CreatedAt, URL: URL(r.ID),
		DeletedAt: r.DeletedAt, key: r.StorageKey,
	}
}

// Reader: phân hệ sở hữu quyết định actor có được tải file không (lỗi *iam.Error 403 / 404), và tên file khi tải về
// ("" = tên gốc). Mỗi subject_type một hàm, đăng ký bằng RegisterReader lúc khởi động.
type Reader func(ctx context.Context, actor *iam.Access, f File) (downloadName string, err error)

var (
	ErrNotFound   = &iam.Error{Status: http.StatusNotFound, Code: "file_not_found", Message: "Không tìm thấy file hoặc file đã bị xóa."}
	errForbidden  = &iam.Error{Status: http.StatusForbidden, Code: "forbidden", Message: "Bạn không có quyền tải file này."}
	errNoStore    = &iam.Error{Status: http.StatusServiceUnavailable, Code: "files_unavailable", Message: "Kho file chưa được cấu hình."}
	errUnreadable = &iam.Error{Status: http.StatusUnprocessableEntity, Code: "invalid", Message: "Không nhận ra loại file."}
)

type Service struct {
	pool    *pgxpool.Pool
	q       *filesdb.Queries
	store   filestore.Store
	log     *slog.Logger
	ttl     time.Duration
	now     func() time.Time
	mu      sync.RWMutex
	readers map[string]Reader
}

// NewService: ttl là hạn URL tải ký sẵn (0 = 5 phút).
func NewService(pool *pgxpool.Pool, store filestore.Store, log *slog.Logger, ttl time.Duration) *Service {
	if log == nil {
		log = slog.Default()
	}
	if ttl <= 0 {
		ttl = filestore.DefaultTTL
	}
	s := &Service{pool: pool, store: store, log: log, ttl: ttl, now: time.Now, readers: map[string]Reader{}}
	if pool != nil {
		s.q = filesdb.New(pool)
	}
	return s
}

// Store: kho nội dung (để phân hệ đọc lại file, vd. đọc chữ trong PDF đề test).
func (s *Service) Store() filestore.Store { return s.store }

// RegisterReader đăng ký hàm kiểm quyền tải cho một loại file. Loại chưa đăng ký thì không ai tải được (403).
func (s *Service) RegisterReader(subjectType string, fn Reader) {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.readers[subjectType] = fn
}

func (s *Service) reader(subjectType string) Reader {
	s.mu.RLock()
	defer s.mu.RUnlock()
	return s.readers[subjectType]
}

func (s *Service) queries(tx pgx.Tx) *filesdb.Queries {
	if tx != nil {
		return s.q.WithTx(tx)
	}
	return s.q
}

// Owner: phân hệ và bản ghi sở hữu file.
type Owner struct {
	Module      string     // "iam", "placement", "tuition"…
	SubjectType string     // "staff_contract", "placement_pdf", "refund_proof"…
	SubjectID   *uuid.UUID // nil: tải lên trước khi có bản ghi (gắn sau bằng Attach)
	BranchID    *uuid.UUID
}

// SaveInput: thông tin lưu một file đã kiểm (filestore.Upload.Check).
type SaveInput struct {
	Owner
	By   *uuid.UUID // người tải lên (nil = hệ thống)
	Name string     // tên hiển thị; trống = tên gốc của file tải lên
	// Nhật ký vận hành trong cùng transaction. Description trống = "Tải lên file <tên>"; SubjectType trống = chính
	// file; Module trống = Quản lý Media. ActorID, Event, Action, thông tin file do Save điền.
	Audit      audit.Entry
	LegacyPath *string // đường dẫn ở Laravel (nhập dữ liệu cũ)
}

// Save ghi nội dung lên kho rồi ghi thông tin vào core.files và nhật ký trong tx (nil = tự mở transaction).
// Upload phải qua Check trước. Lỗi khi ghi DB thì xóa nội dung vừa ghi.
func (s *Service) Save(ctx context.Context, tx pgx.Tx, up *filestore.Upload, in SaveInput) (File, error) {
	if s.store == nil || s.q == nil {
		return File{}, errNoStore
	}
	if up == nil || up.Kind.Ext == "" {
		return File{}, errUnreadable
	}
	if in.Module == "" || in.SubjectType == "" {
		return File{}, errors.New("files: thiếu Owner.Module / Owner.SubjectType")
	}
	key := filestore.NewKey(in.SubjectType, up.Kind.Ext, s.now())
	if err := s.store.Put(ctx, key, up.Reader(), up.Size, up.Kind.Mime); err != nil {
		return File{}, fmt.Errorf("files: ghi nội dung: %w", err)
	}
	name := strings.TrimSpace(in.Name)
	if name == "" {
		name = up.Name
	}
	var saved File
	write := func(tx pgx.Tx) error {
		row, err := s.q.WithTx(tx).InsertFile(ctx, filesdb.InsertFileParams{
			OwnerModule: in.Module, SubjectType: in.SubjectType, SubjectID: in.SubjectID, BranchID: in.BranchID,
			StorageKey: key, OriginalName: truncate(name, 255), Extension: up.Kind.Ext, MimeType: up.Kind.Mime,
			SizeBytes: up.Size, Sha256: up.SHA256[:], LegacyPath: in.LegacyPath, UploadedBy: in.By,
		})
		if err != nil {
			return err
		}
		saved = toFile(row)
		return s.audit(ctx, tx, in.Audit, saved, in.By, audit.EventCreated, "file.upload", "Tải lên file "+saved.Name)
	}
	var err error
	if tx != nil {
		err = write(tx)
	} else {
		err = pgx.BeginFunc(ctx, s.pool, write)
	}
	if err != nil {
		if derr := s.store.Delete(context.WithoutCancel(ctx), key); derr != nil {
			s.log.Error("files: xóa nội dung sau khi lưu lỗi", "err", derr, "key", key)
		}
		return File{}, err
	}
	s.log.Info("file uploaded", "file", saved.ID, "subject_type", saved.SubjectType, "size", saved.Size, "by", in.By)
	return saved, nil
}

func (s *Service) audit(ctx context.Context, tx pgx.Tx, e audit.Entry, f File, by *uuid.UUID, event, action, desc string) error {
	e.ActorID, e.Event, e.Action = by, event, action
	if e.Module == "" {
		e.Module = audit.ModuleMedia
	}
	if e.Description == "" {
		e.Description = desc
	}
	if e.SubjectType == "" {
		e.SubjectType, e.SubjectID, e.SubjectLabel = "file", f.ID.String(), f.Name
	}
	if e.BranchID == nil {
		e.BranchID = f.BranchID
	}
	props := map[string]any{
		"file_id": f.ID.String(), "file_name": f.Name, "subject_type": f.SubjectType, "size": f.Size,
		"mime_type": f.MimeType, "sha256": f.SHA256,
	}
	for k, v := range e.Properties {
		props[k] = v
	}
	e.Properties = props
	return audit.Record(ctx, tx, e)
}

// Get: thông tin file còn dùng (đã xóa → ErrNotFound).
func (s *Service) Get(ctx context.Context, id uuid.UUID) (File, error) {
	if s.q == nil {
		return File{}, errNoStore
	}
	r, err := s.q.GetFile(ctx, id)
	if errors.Is(err, pgx.ErrNoRows) || (err == nil && r.DeletedAt != nil) {
		return File{}, ErrNotFound
	}
	if err != nil {
		return File{}, err
	}
	return toFile(r), nil
}

// Current: file mới nhất còn dùng của bản ghi (nil nếu chưa có), vd. hợp đồng hiện tại của nhân sự.
func (s *Service) Current(ctx context.Context, tx pgx.Tx, subjectType string, subjectID uuid.UUID) (*File, error) {
	if s.q == nil {
		return nil, nil
	}
	r, err := s.queries(tx).CurrentFile(ctx, filesdb.CurrentFileParams{SubjectType: subjectType, SubjectID: &subjectID})
	if errors.Is(err, pgx.ErrNoRows) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	f := toFile(r)
	return &f, nil
}

// List: mọi file còn dùng của bản ghi, mới nhất trước.
func (s *Service) List(ctx context.Context, subjectType string, subjectID uuid.UUID) ([]File, error) {
	rows, err := s.q.ListSubjectFiles(ctx, filesdb.ListSubjectFilesParams{SubjectType: subjectType, SubjectID: &subjectID})
	if err != nil {
		return nil, err
	}
	out := make([]File, 0, len(rows))
	for _, r := range rows {
		out = append(out, toFile(r))
	}
	return out, nil
}

// Attach gắn file tải lên trước (subject_id NULL) vào bản ghi; file khác loại / đã gắn bản ghi khác → ErrNotFound.
func (s *Service) Attach(ctx context.Context, tx pgx.Tx, id uuid.UUID, subjectType string, subjectID uuid.UUID) (File, error) {
	r, err := s.queries(tx).AttachFile(ctx, filesdb.AttachFileParams{ID: id, SubjectType: subjectType, SubjectID: &subjectID})
	if errors.Is(err, pgx.ErrNoRows) {
		return File{}, ErrNotFound
	}
	if err != nil {
		return File{}, err
	}
	return toFile(r), nil
}

// Delete xóa mềm file và ghi nhật ký (a: như SaveInput.Audit; Description trống = "Xóa file <tên>").
// Nội dung giữ thêm RetainDeleted rồi RunPurgeLoop mới xóa hẳn.
func (s *Service) Delete(ctx context.Context, tx pgx.Tx, id uuid.UUID, by *uuid.UUID, a audit.Entry) (File, error) {
	var out File
	del := func(tx pgx.Tx) error {
		r, err := s.q.WithTx(tx).SoftDeleteFile(ctx, filesdb.SoftDeleteFileParams{ID: id, DeletedBy: by})
		if errors.Is(err, pgx.ErrNoRows) {
			return ErrNotFound
		}
		if err != nil {
			return err
		}
		out = toFile(r)
		return s.audit(ctx, tx, a, out, by, audit.EventDeleted, "file.delete", "Xóa file "+out.Name)
	}
	var err error
	if tx != nil {
		err = del(tx)
	} else {
		err = pgx.BeginFunc(ctx, s.pool, del)
	}
	if err != nil {
		return File{}, err
	}
	s.log.Info("file deleted", "file", id, "by", by)
	return out, nil
}

// replaceOld xóa mềm các file cũ của bản ghi (trừ keep) trong cùng thao tác thay file, không ghi nhật ký riêng.
func (s *Service) replaceOld(ctx context.Context, tx pgx.Tx, old []File, keep uuid.UUID, by *uuid.UUID) error {
	q := s.q.WithTx(tx)
	for _, f := range old {
		if f.ID == keep {
			continue
		}
		if _, err := q.SoftDeleteFile(ctx, filesdb.SoftDeleteFileParams{ID: f.ID, DeletedBy: by}); err != nil && !errors.Is(err, pgx.ErrNoRows) {
			return err
		}
	}
	return nil
}

// Open đọc nội dung file.
func (s *Service) Open(ctx context.Context, f File) (io.ReadCloser, error) {
	if s.store == nil {
		return nil, errNoStore
	}
	rc, err := s.store.Open(ctx, f.key)
	if errors.Is(err, filestore.ErrNotFound) {
		return nil, ErrNotFound
	}
	return rc, err
}

// SignedURL: URL tải trực tiếp có hạn (ttl 0 = hạn mặc định). Chỉ gọi sau khi đã kiểm quyền đọc.
// Ảnh, PDF, âm thanh mở ngay trên trình duyệt; tài liệu văn phòng luôn tải về.
func (s *Service) SignedURL(ctx context.Context, f File, ttl time.Duration, name string) (string, error) {
	if s.store == nil {
		return "", errNoStore
	}
	if ttl <= 0 {
		ttl = s.ttl
	}
	if name == "" {
		name = f.Name
	}
	return s.store.SignedURL(ctx, f.key, filestore.SignOptions{
		TTL: ttl, Filename: name, ContentType: f.MimeType, Inline: filestore.InlineExt(f.Extension),
	})
}

// Authorize: actor được tải file không (theo hàm của phân hệ sở hữu); trả tên file khi tải về.
func (s *Service) Authorize(ctx context.Context, actor *iam.Access, f File) (string, error) {
	fn := s.reader(f.SubjectType)
	if fn == nil || actor == nil {
		return "", errForbidden
	}
	name, err := fn(ctx, actor, f)
	if err != nil {
		return "", err
	}
	if name == "" {
		name = f.Name
	}
	return name, nil
}

// PurgeDeleted xóa hẳn nội dung + dòng của file đã xóa mềm trước before. Trả số file đã dọn.
func (s *Service) PurgeDeleted(ctx context.Context, before time.Time) (int, error) {
	rows, err := s.q.PurgeCandidates(ctx, filesdb.PurgeCandidatesParams{Before: &before, MaxRows: 500})
	if err != nil {
		return 0, err
	}
	n := 0
	for _, r := range rows {
		if err := s.store.Delete(ctx, r.StorageKey); err != nil {
			s.log.Error("files: xóa nội dung file đã xóa", "err", err, "file", r.ID)
			continue
		}
		if err := s.q.DeleteFileRow(ctx, r.ID); err != nil {
			return n, err
		}
		n++
	}
	return n, nil
}

// RunPurgeLoop dọn file đã xóa mềm quá RetainDeleted, chạy định kỳ tới khi ctx hủy.
func (s *Service) RunPurgeLoop(ctx context.Context, every time.Duration) {
	t := time.NewTicker(every)
	defer t.Stop()
	for {
		if n, err := s.PurgeDeleted(ctx, s.now().Add(-RetainDeleted)); err != nil {
			s.log.Error("files: dọn file đã xóa", "err", err)
		} else if n > 0 {
			s.log.Info("files: đã dọn file xóa quá 30 ngày", "count", n)
		}
		select {
		case <-ctx.Done():
			return
		case <-t.C:
		}
	}
}

func truncate(s string, n int) string {
	r := []rune(s)
	if len(r) <= n {
		return s
	}
	return string(r[:n])
}
