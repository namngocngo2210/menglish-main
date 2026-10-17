package iam

import (
	"context"
	"net/http"
	"strings"
	"time"
	"unicode"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"
	"golang.org/x/text/unicode/norm"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/filestore"
)

// File hợp đồng lao động của nhân sự (Laravel: users.contract_file_path trên disk riêng tư, UserController::
// storeContractFile / downloadContract). Nội dung nằm trong kho file chung (gói files) với loại "staff_contract",
// gắn với id nhân sự.

// ContractPolicy: rule của UserRequest (contract_file: file|max:10240|mimes:pdf,doc,docx,jpg,jpeg,png,webp).
var ContractPolicy = filestore.Policy{
	Types:    []string{"pdf", "doc", "docx", "jpg", "jpeg", "png", "webp"},
	MaxBytes: 10240 << 10,
	TooLarge: "Dung lượng tập tin file hợp đồng không được vượt quá 10240 kB.",
	Required: "Vui lòng chọn file hợp đồng.",
}

// ContractFile: file hợp đồng hiện tại.
type ContractFile struct {
	ID         uuid.UUID `json:"id"`
	Name       string    `json:"name"`
	Size       int64     `json:"size"`
	MimeType   string    `json:"mime_type"`
	UploadedAt time.Time `json:"uploaded_at"`
	// URL tải: /api/v1/files/<id> kiểm quyền (ContractDownload) rồi chuyển sang link ký sẵn hạn 5 phút.
	URL string `json:"url"`
}

// ContractOwner: nhân sự nhận file hợp đồng (cho nhật ký vận hành).
type ContractOwner struct {
	UserID   uuid.UUID
	Label    string // "Nguyễn Văn A (MES001)"
	BranchID *uuid.UUID
}

// ContractFiles: kho file chung (gói files hiện thực bằng files.Service.StaffContracts, nối trong cmd/menglish).
type ContractFiles interface {
	// Replace lưu file làm hợp đồng hiện tại, xóa mềm file cũ, ghi nhật ký "Cập nhật file hợp đồng lao động" trong tx.
	Replace(ctx context.Context, tx pgx.Tx, actor uuid.UUID, owner ContractOwner, up *filestore.Upload) (ContractFile, error)
	// Current: file hợp đồng hiện tại (nil nếu chưa có).
	Current(ctx context.Context, userID uuid.UUID) (*ContractFile, error)
}

// SetContractFiles nối kho file (gọi một lần lúc khởi động).
func (s *Service) SetContractFiles(c ContractFiles) { s.contracts = c }

var errNoContractStore = &Error{Status: http.StatusServiceUnavailable, Code: "files_unavailable", Message: "Kho file chưa được cấu hình."}

// UploadContract: tải lên / thay file hợp đồng của nhân viên actor quản lý được (quyền user.update ở route).
// up đã qua ContractPolicy.Check.
func (s *Service) UploadContract(ctx context.Context, actor *Access, id uuid.UUID, up *filestore.Upload) (ContractFile, error) {
	t, err := s.managedTarget(ctx, actor, id)
	if err != nil {
		return ContractFile{}, err
	}
	if t.user.Kind != "staff" {
		return ContractFile{}, invalid("Chỉ tài khoản nhân viên có hồ sơ nhân sự.")
	}
	if s.contracts == nil {
		return ContractFile{}, errNoContractStore
	}
	var out ContractFile
	err = pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error {
		out, err = s.contracts.Replace(ctx, tx, actor.UserID, ContractOwner{UserID: id, Label: userLabel(t.user), BranchID: t.primaryBranch()}, up)
		return err
	})
	if err != nil {
		return ContractFile{}, err
	}
	s.log.Info("staff contract uploaded", "user_id", id, "file", out.ID, "by", actor.UserID)
	return out, nil
}

// ContractDownload: actor tải được file hợp đồng của nhân sự userID không: chính chủ, hoặc có user.view và quản lý
// được tài khoản đó (downloadContract của Laravel). Trả tên file khi tải: hop-dong-<tên không dấu>.<đuôi>.
func (s *Service) ContractDownload(ctx context.Context, actor *Access, userID uuid.UUID, ext string) (string, error) {
	t, err := s.loadTarget(ctx, s.q, userID)
	if err != nil {
		return "", err
	}
	if actor.UserID != userID {
		if !actor.Can("user.view") {
			return "", ErrForbidden
		}
		if err := canManage(actor, t); err != nil {
			return "", err
		}
	}
	name := "hop-dong"
	if slug := Slug(t.user.FullName); slug != "" {
		name += "-" + slug
	}
	return name + "." + ext, nil
}

// Slug: chuỗi không dấu, chữ thường, nối bằng "-" (Str::slug của Laravel), vd. "Nguyễn Văn Đạt" → "nguyen-van-dat".
func Slug(s string) string {
	var b strings.Builder
	dash := false
	for _, r := range norm.NFD.String(strings.ToLower(s)) {
		switch {
		case unicode.Is(unicode.Mn, r):
			continue
		case r == 'đ':
			r = 'd'
		}
		if (r >= 'a' && r <= 'z') || (r >= '0' && r <= '9') {
			if dash && b.Len() > 0 {
				b.WriteByte('-')
			}
			b.WriteRune(r)
			dash = false
		} else {
			dash = true
		}
	}
	return b.String()
}

func (s *Service) currentContract(ctx context.Context, id uuid.UUID) *ContractFile {
	if s.contracts == nil {
		return nil
	}
	f, err := s.contracts.Current(ctx, id)
	if err != nil {
		s.log.Error("staff contract lookup", "err", err, "user_id", id)
		return nil
	}
	return f
}
