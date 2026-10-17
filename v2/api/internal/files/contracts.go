package files

import (
	"context"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/filestore"
)

// SubjectStaffContract: file hợp đồng lao động, subject_id = id nhân sự.
const SubjectStaffContract = "staff_contract"

// StaffContracts nối kho file với hồ sơ nhân sự (iam.ContractFiles) và đăng ký quyền tải file hợp đồng:
// chính chủ, hoặc có user.view và quản lý được tài khoản (iam.Service.ContractDownload).
func (s *Service) StaffContracts(svc *iam.Service) iam.ContractFiles {
	s.RegisterReader(SubjectStaffContract, func(ctx context.Context, a *iam.Access, f File) (string, error) {
		if f.SubjectID == nil {
			return "", errForbidden
		}
		return svc.ContractDownload(ctx, a, *f.SubjectID, f.Extension)
	})
	return staffContracts{s}
}

type staffContracts struct{ s *Service }

func contractOf(f File) iam.ContractFile {
	return iam.ContractFile{ID: f.ID, Name: f.Name, Size: f.Size, MimeType: f.MimeType, UploadedAt: f.CreatedAt, URL: f.URL}
}

func (c staffContracts) Replace(ctx context.Context, tx pgx.Tx, actor uuid.UUID, o iam.ContractOwner, up *filestore.Upload) (iam.ContractFile, error) {
	old, err := c.s.List(ctx, SubjectStaffContract, o.UserID)
	if err != nil {
		return iam.ContractFile{}, err
	}
	f, err := c.s.Save(ctx, tx, up, SaveInput{
		Owner: Owner{Module: "iam", SubjectType: SubjectStaffContract, SubjectID: &o.UserID, BranchID: o.BranchID},
		By:    &actor,
		Audit: audit.Entry{
			Module: audit.ModuleUsers, SubjectType: "user", SubjectID: o.UserID.String(), SubjectLabel: o.Label,
			BranchID: o.BranchID, Description: "Cập nhật file hợp đồng lao động",
		},
	})
	if err != nil {
		return iam.ContractFile{}, err
	}
	// File cũ: Laravel xóa luôn; v2 xóa mềm (dọn hẳn sau 30 ngày).
	if err := c.s.replaceOld(ctx, tx, old, f.ID, &actor); err != nil {
		return iam.ContractFile{}, err
	}
	return contractOf(f), nil
}

func (c staffContracts) Current(ctx context.Context, userID uuid.UUID) (*iam.ContractFile, error) {
	f, err := c.s.Current(ctx, nil, SubjectStaffContract, userID)
	if err != nil || f == nil {
		return nil, err
	}
	out := contractOf(*f)
	return &out, nil
}
