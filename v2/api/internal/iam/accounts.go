package iam

import (
	"context"
	"errors"
	"strings"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam/iamdb"
)

// Tài khoản do module khác tạo / tra cứu trong transaction của chính module đó (vd. chốt Lead tạo hồ sơ học viên kèm
// tài khoản cổng học viên, cấp tài khoản phụ huynh). Module gọi tự kiểm quyền nghiệp vụ của mình.

// AccountInput: tài khoản mới kèm vai trò, chi nhánh chính và người tạo.
type AccountInput struct {
	NewUser
	Roles     []string
	BranchID  *uuid.UUID
	CreatedBy uuid.UUID
}

// Account là tài khoản kèm trạng thái và vai trò (để module gọi quyết định có dùng lại được không).
type Account struct {
	User
	Status string   `json:"status"`
	Roles  []string `json:"roles"`
}

// CreateAccountTx tạo tài khoản với mật khẩu tạm (bắt đổi ở lần đăng nhập đầu) trong tx của module gọi.
func (s *Service) CreateAccountTx(ctx context.Context, tx pgx.Tx, in AccountInput) (User, string, error) {
	password := RandomPassword()
	hash, err := HashPassword(ctx, password)
	if err != nil {
		return User{}, "", err
	}
	q := s.q.WithTx(tx)
	u, err := q.CreateUser(ctx, iamdb.CreateUserParams{
		Kind: in.Kind, Code: upperOrNil(in.Code), Email: lowerOrNil(in.Email),
		Phone: phoneOrNil(in.Phone), FullName: strings.TrimSpace(in.FullName), PasswordHash: hash,
	})
	if err != nil {
		return User{}, "", uniqueErr(err)
	}
	if in.CreatedBy != uuid.Nil {
		if err := q.SetUserCreatedBy(ctx, iamdb.SetUserCreatedByParams{ID: u.ID, CreatedBy: &in.CreatedBy}); err != nil {
			return User{}, "", err
		}
	}
	for _, r := range dedupe(in.Roles) {
		if err := q.AddUserRole(ctx, iamdb.AddUserRoleParams{UserID: u.ID, Role: r}); err != nil {
			return User{}, "", err
		}
	}
	if in.BranchID != nil {
		if err := q.AddUserBranch(ctx, iamdb.AddUserBranchParams{UserID: u.ID, BranchID: *in.BranchID, IsPrimary: true}); err != nil {
			return User{}, "", err
		}
	}
	return toUser(u), password, nil
}

func (s *Service) account(ctx context.Context, q *iamdb.Queries, u iamdb.IamUser, err error) (*Account, error) {
	if errors.Is(err, pgx.ErrNoRows) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	roles, err := q.ListUserRoles(ctx, u.ID)
	if err != nil {
		return nil, err
	}
	return &Account{User: toUser(u), Status: u.Status, Roles: roles}, nil
}

// AccountByEmailTx: tài khoản có email này, nil nếu chưa có.
func (s *Service) AccountByEmailTx(ctx context.Context, tx pgx.Tx, email string) (*Account, error) {
	q := s.q.WithTx(tx)
	key := strings.ToLower(strings.TrimSpace(email))
	u, err := q.GetUserByEmail(ctx, &key)
	return s.account(ctx, q, u, err)
}

// AccountByIDTx: tài khoản theo id, nil nếu không có.
func (s *Service) AccountByIDTx(ctx context.Context, tx pgx.Tx, id uuid.UUID) (*Account, error) {
	q := s.q.WithTx(tx)
	u, err := q.GetUser(ctx, id)
	return s.account(ctx, q, u, err)
}

// GuardianByPhoneTx: tài khoản phụ huynh có SĐT này (SĐT phụ huynh là duy nhất), nil nếu chưa có.
func (s *Service) GuardianByPhoneTx(ctx context.Context, tx pgx.Tx, phone string) (*Account, error) {
	q := s.q.WithTx(tx)
	key := NormalizePhone(phone)
	u, err := q.GetGuardianByPhone(ctx, &key)
	return s.account(ctx, q, u, err)
}

// ActivateAccountTx kích hoạt lại tài khoản đã vô hiệu hóa (dùng lại tài khoản cũ).
func (s *Service) ActivateAccountTx(ctx context.Context, tx pgx.Tx, id uuid.UUID) error {
	return s.q.WithTx(tx).SetUserStatus(ctx, iamdb.SetUserStatusParams{ID: id, Status: "active"})
}

// ResetAccountPassword cấp mật khẩu tạm mới, kích hoạt lại, mở khóa và đăng xuất mọi thiết bị.
// Module gọi đã kiểm quyền (vd. cấp lại mật khẩu cổng học viên).
func (s *Service) ResetAccountPassword(ctx context.Context, uid uuid.UUID) (string, error) {
	if err := s.q.SetUserStatus(ctx, iamdb.SetUserStatusParams{ID: uid, Status: "active"}); err != nil {
		return "", err
	}
	return s.resetPassword(ctx, uid)
}
