package iam

import (
	"context"
	"errors"
	"fmt"
	"maps"
	"math"
	"regexp"
	"slices"
	"strings"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgconn"
	"github.com/jackc/pgx/v5/pgtype"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam/iamdb"
)

// Quản trị người dùng, vai trò, chi nhánh, phân quyền cá nhân. Mọi hàm nhận Access của người thực hiện và tự kiểm
// phạm vi + quan hệ cấp trên / cấp dưới (route đã kiểm quyền thao tác bằng Require).

var (
	ErrUserNotFound    = &Error{404, "user_not_found", "Không tìm thấy tài khoản."}
	ErrBranchNotFound  = &Error{404, "branch_not_found", "Không tìm thấy chi nhánh."}
	ErrRoleNotFound    = &Error{404, "role_not_found", "Không tìm thấy vai trò."}
	ErrRoleImmutable   = &Error{409, "role_immutable", "Vai trò Admin luôn có toàn quyền, không sửa quyền được."}
	ErrNotSubordinate  = &Error{403, "not_subordinate", "Bạn không có quyền thao tác trên tài khoản này."}
	ErrOutsideBranch   = &Error{403, "outside_branch", "Nhân sự này không thuộc chi nhánh bạn quản lý."}
	ErrNotOwnAccount   = &Error{403, "not_own_account", "Bạn chỉ thao tác được trên tài khoản do mình tạo."}
	ErrSelfAction      = &Error{422, "self_action", "Bạn không thể thực hiện thao tác này trên tài khoản của chính mình."}
	ErrSelfOverride    = &Error{403, "self_override", "Không thể tự phân quyền cho chính mình."}
	ErrAdminOnlyTarget = &Error{403, "admin_only_target", "Chỉ Admin được chỉnh quyền của Admin."}
)

func invalid(msg string) *Error { return &Error{422, "invalid", msg} }

// ───────────────────────── Chi nhánh ─────────────────────────

type Branch struct {
	ID        uuid.UUID     `json:"id"`
	Code      string        `json:"code"`
	Name      string        `json:"name"`
	Address   *string       `json:"address"`
	Phone     *string       `json:"phone"`
	IsActive  bool          `json:"is_active"`
	CreatedAt time.Time     `json:"created_at"`
	Checkin   BranchCheckin `json:"checkin"`
}

// BranchCheckin: cài đặt chấm công của chi nhánh. Configured = đã có tọa độ (nhân sự mới chấm công được).
type BranchCheckin struct {
	Latitude   *float64 `json:"latitude"`
	Longitude  *float64 `json:"longitude"`
	Radius     int      `json:"radius"`
	WorkStart  *string  `json:"work_start"`
	WorkEnd    *string  `json:"work_end"`
	Grace      int      `json:"grace"`
	Configured bool     `json:"configured"`
}

const defaultCheckinRadius = 100

func toBranch(b iamdb.CoreBranch) Branch {
	radius := int(b.CheckinRadius)
	if radius <= 0 {
		radius = defaultCheckinRadius
	}
	return Branch{b.ID, b.Code, b.Name, b.Address, b.Phone, b.IsActive, b.CreatedAt, BranchCheckin{
		Latitude: b.Latitude, Longitude: b.Longitude, Radius: radius,
		WorkStart: clock(b.WorkStartTime), WorkEnd: clock(b.WorkEndTime), Grace: int(b.LateGraceMinutes),
		Configured: b.Latitude != nil && b.Longitude != nil,
	}}
}

// clock: cột time → "HH:MM".
func clock(t pgtype.Time) *string {
	if !t.Valid {
		return nil
	}
	m := t.Microseconds / 60e6
	s := fmt.Sprintf("%02d:%02d", m/60, m%60)
	return &s
}

// parseClock: "HH:MM" → số phút trong ngày.
func parseClock(s string) (int, bool) {
	t, err := time.Parse("15:04", strings.TrimSpace(s))
	if err != nil {
		return 0, false
	}
	return t.Hour()*60 + t.Minute(), true
}

type BranchInput struct {
	Code    string `json:"code"`
	Name    string `json:"name"`
	Address string `json:"address"`
	Phone   string `json:"phone"`
}

var branchCodeRe = regexp.MustCompile(`^[A-Za-z0-9_-]{1,20}$`)

func (in *BranchInput) normalize() (code, name string, address, phone *string, err error) {
	code = strings.ToUpper(strings.TrimSpace(in.Code))
	name = strings.TrimSpace(in.Name)
	if !branchCodeRe.MatchString(code) {
		return "", "", nil, nil, invalid("Mã chi nhánh gồm 1–20 chữ, số, gạch ngang hoặc gạch dưới.")
	}
	if name == "" || len([]rune(name)) > 200 {
		return "", "", nil, nil, invalid("Tên chi nhánh không được trống (tối đa 200 ký tự).")
	}
	if a := strings.TrimSpace(in.Address); a != "" {
		if len([]rune(a)) > 500 {
			return "", "", nil, nil, invalid("Địa chỉ tối đa 500 ký tự.")
		}
		address = &a
	}
	if strings.TrimSpace(in.Phone) != "" {
		p := NormalizePhone(in.Phone)
		if p == "" {
			return "", "", nil, nil, invalid("Số điện thoại không hợp lệ.")
		}
		phone = &p
	}
	return code, name, address, phone, nil
}

func (s *Service) ListBranches(ctx context.Context) ([]Branch, error) {
	rows, err := s.q.ListBranches(ctx)
	if err != nil {
		return nil, err
	}
	out := make([]Branch, 0, len(rows))
	for _, b := range rows {
		out = append(out, toBranch(b))
	}
	return out, nil
}

func (s *Service) CreateBranch(ctx context.Context, actor *Access, in BranchInput) (Branch, error) {
	code, name, address, phone, err := in.normalize()
	if err != nil {
		return Branch{}, err
	}
	var b iamdb.CoreBranch
	err = pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error {
		var err error
		b, err = s.q.WithTx(tx).CreateBranch(ctx, iamdb.CreateBranchParams{Code: code, Name: name, Address: address, Phone: phone, CreatedBy: &actor.UserID})
		if err != nil {
			return uniqueErr(err)
		}
		return recordTx(ctx, tx, actor, branchEntry(b, "branch.create",
			fmt.Sprintf("Thêm mới cơ sở chi nhánh: %s (%s)", name, code), audit.Diff(nil, branchSnapshot(b))))
	})
	if err != nil {
		return Branch{}, err
	}
	s.log.Info("branch created", "branch_id", b.ID, "by", actor.UserID)
	return toBranch(b), nil
}

func (s *Service) UpdateBranch(ctx context.Context, actor *Access, id uuid.UUID, in BranchInput) (Branch, error) {
	code, name, address, phone, err := in.normalize()
	if err != nil {
		return Branch{}, err
	}
	var b iamdb.CoreBranch
	err = pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error {
		q := s.q.WithTx(tx)
		old, err := q.GetBranch(ctx, id)
		if err != nil {
			return err
		}
		if b, err = q.UpdateBranch(ctx, iamdb.UpdateBranchParams{ID: id, Code: code, Name: name, Address: address, Phone: phone}); err != nil {
			return uniqueErr(err)
		}
		return recordTx(ctx, tx, actor, branchEntry(b, "branch.update",
			fmt.Sprintf("Cập nhật thông tin cơ sở chi nhánh: %s (%s)", name, code), audit.Diff(branchSnapshot(old), branchSnapshot(b))))
	})
	if errors.Is(err, pgx.ErrNoRows) {
		return Branch{}, ErrBranchNotFound
	}
	if err != nil {
		return Branch{}, err
	}
	s.log.Info("branch updated", "branch_id", id, "by", actor.UserID)
	return toBranch(b), nil
}

// BranchCheckinInput: giống validate của Laravel BranchController::updateAttendance.
type BranchCheckinInput struct {
	Latitude         *float64 `json:"latitude"`
	Longitude        *float64 `json:"longitude"`
	CheckinRadius    *int     `json:"checkin_radius"`
	WorkStartTime    string   `json:"work_start_time"`
	WorkEndTime      string   `json:"work_end_time"`
	LateGraceMinutes *int     `json:"late_grace_minutes"`
}

func (in BranchCheckinInput) params(id uuid.UUID) (iamdb.UpdateBranchCheckinParams, error) {
	p := iamdb.UpdateBranchCheckinParams{ID: id}
	switch {
	case in.Latitude == nil:
		return p, invalid(`Nhập vĩ độ của chi nhánh (hoặc bấm "Lấy vị trí hiện tại" khi đang đứng ở chi nhánh).`)
	case *in.Latitude < -90 || *in.Latitude > 90:
		return p, invalid("Vĩ độ phải nằm trong khoảng -90 đến 90.")
	case in.Longitude == nil:
		return p, invalid("Nhập kinh độ của chi nhánh.")
	case *in.Longitude < -180 || *in.Longitude > 180:
		return p, invalid("Kinh độ phải nằm trong khoảng -180 đến 180.")
	case in.CheckinRadius == nil || *in.CheckinRadius < 20:
		return p, invalid("Bán kính tối thiểu 20 m (GPS điện thoại thường lệch 5–20 m).")
	case *in.CheckinRadius > 5000:
		return p, invalid("Bán kính tối đa 5000 m.")
	case in.LateGraceMinutes == nil || *in.LateGraceMinutes < 0 || *in.LateGraceMinutes > 240:
		return p, invalid("Số phút cho phép muộn từ 0 đến 240.")
	}
	start, ok := parseClock(in.WorkStartTime)
	if !ok {
		return p, invalid("Giờ vào phải theo dạng HH:MM.")
	}
	end, ok := parseClock(in.WorkEndTime)
	if !ok {
		return p, invalid("Giờ ra phải theo dạng HH:MM.")
	}
	if end <= start {
		return p, invalid("Giờ ra phải sau giờ vào.")
	}
	lat, lng := math.Round(*in.Latitude*1e7)/1e7, math.Round(*in.Longitude*1e7)/1e7
	p.Latitude, p.Longitude = &lat, &lng
	p.CheckinRadius = int32(*in.CheckinRadius)
	p.WorkStartTime = pgtype.Time{Microseconds: int64(start) * 60e6, Valid: true}
	p.WorkEndTime = pgtype.Time{Microseconds: int64(end) * 60e6, Valid: true}
	p.LateGraceMinutes = int16(*in.LateGraceMinutes)
	return p, nil
}

func (s *Service) UpdateBranchCheckin(ctx context.Context, actor *Access, id uuid.UUID, in BranchCheckinInput) (Branch, error) {
	p, err := in.params(id)
	if err != nil {
		return Branch{}, err
	}
	var b iamdb.CoreBranch
	err = pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error {
		q := s.q.WithTx(tx)
		old, err := q.GetBranch(ctx, id)
		if err != nil {
			return err
		}
		if b, err = q.UpdateBranchCheckin(ctx, p); err != nil {
			return err
		}
		return recordTx(ctx, tx, actor, branchEntry(b, "branch.checkin",
			"Cập nhật cài đặt chấm công cơ sở: "+b.Name, audit.Diff(checkinSnapshot(old), checkinSnapshot(b))))
	})
	if errors.Is(err, pgx.ErrNoRows) {
		return Branch{}, ErrBranchNotFound
	}
	if err != nil {
		return Branch{}, err
	}
	s.log.Info("branch checkin updated", "branch_id", id, "by", actor.UserID)
	return toBranch(b), nil
}

func (s *Service) SetBranchActive(ctx context.Context, actor *Access, id uuid.UUID, active bool) (Branch, error) {
	var b iamdb.CoreBranch
	err := pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error {
		q := s.q.WithTx(tx)
		old, err := q.GetBranch(ctx, id)
		if err != nil {
			return err
		}
		if b, err = q.SetBranchActive(ctx, iamdb.SetBranchActiveParams{ID: id, IsActive: active}); err != nil {
			return err
		}
		status := "Tạm dừng"
		if active {
			status = "Đang hoạt động"
		}
		return recordTx(ctx, tx, actor, branchEntry(b, "branch.set_active",
			fmt.Sprintf("Chuyển trạng thái chi nhánh [%s] sang %s", b.Name, status), audit.Diff(branchSnapshot(old), branchSnapshot(b))))
	})
	if errors.Is(err, pgx.ErrNoRows) {
		return Branch{}, ErrBranchNotFound
	}
	if err != nil {
		return Branch{}, err
	}
	s.log.Info("branch status changed", "branch_id", id, "active", active, "by", actor.UserID)
	return toBranch(b), nil
}

// uniqueErr đổi lỗi trùng khóa của Postgres thành thông báo cho người dùng.
func uniqueErr(err error) error {
	var pg *pgconn.PgError
	if !errors.As(err, &pg) || pg.Code != "23505" {
		return err
	}
	switch {
	case strings.Contains(pg.ConstraintName, "email"):
		return &Error{409, "email_taken", "Email đã được dùng cho tài khoản khác."}
	case strings.Contains(pg.ConstraintName, "phone"):
		return &Error{409, "phone_taken", "Số điện thoại đã được dùng cho phụ huynh khác."}
	case strings.HasPrefix(pg.ConstraintName, "branches"):
		return &Error{409, "code_taken", "Mã chi nhánh đã tồn tại."}
	default:
		return &Error{409, "code_taken", "Mã đăng nhập đã được dùng cho tài khoản khác."}
	}
}

// ───────────────────────── Vai trò ─────────────────────────

type RoleView struct {
	RoleRef
	SuperAdmin  bool     `json:"super_admin"`
	UserCount   int64    `json:"user_count"`
	Permissions []string `json:"permissions"`
}

func (s *Service) ListRoles(ctx context.Context) ([]RoleView, error) {
	roles, err := s.q.ListRoles(ctx)
	if err != nil {
		return nil, err
	}
	grants, err := s.q.ListRolePermissions(ctx)
	if err != nil {
		return nil, err
	}
	byRole := map[string][]string{}
	for _, g := range grants {
		byRole[g.Role] = append(byRole[g.Role], g.Permission)
	}
	out := make([]RoleView, 0, len(roles))
	for _, r := range roles {
		perms := byRole[r.Name]
		if perms == nil {
			perms = []string{}
		}
		out = append(out, RoleView{
			RoleRef:    RoleRef{r.Name, r.Label, r.ShortLabel},
			SuperAdmin: r.Name == RoleAdmin, UserCount: r.UserCount, Permissions: perms,
		})
	}
	return out, nil
}

func (s *Service) roleRefs(ctx context.Context, names []string) ([]RoleRef, error) {
	out := []RoleRef{}
	if len(names) == 0 {
		return out, nil
	}
	roles, err := s.q.ListRoles(ctx)
	if err != nil {
		return nil, err
	}
	for _, r := range roles {
		if slices.Contains(names, r.Name) {
			out = append(out, RoleRef{r.Name, r.Label, r.ShortLabel})
		}
	}
	return out, nil
}

func (s *Service) UpdateRoleLabels(ctx context.Context, actor *Access, name, label, short string) error {
	label, short = strings.TrimSpace(label), strings.TrimSpace(short)
	if label == "" || len([]rune(label)) > 100 || short == "" || len([]rune(short)) > 50 {
		return invalid("Tên vai trò không được trống (tối đa 100 ký tự, tên ngắn tối đa 50).")
	}
	old, err := s.q.GetRole(ctx, name)
	if errors.Is(err, pgx.ErrNoRows) {
		return ErrRoleNotFound
	} else if err != nil {
		return err
	}
	err = pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error {
		if err := s.q.WithTx(tx).UpdateRoleLabels(ctx, iamdb.UpdateRoleLabelsParams{Name: name, Label: label, ShortLabel: short}); err != nil {
			return err
		}
		return recordTx(ctx, tx, actor, audit.Entry{
			Module: audit.ModuleUsers, Event: audit.EventUpdated, Action: "role.update", SubjectType: "role", SubjectID: name,
			SubjectLabel: label, Description: fmt.Sprintf("Cập nhật vai trò %q", name),
			Changes: audit.Diff(map[string]any{"label": old.Label, "short_label": old.ShortLabel}, map[string]any{"label": label, "short_label": short}),
		})
	})
	if err != nil {
		return err
	}
	s.log.Info("role labels updated", "role", name, "by", actor.UserID)
	return nil
}

// normalizePermissions kiểm mọi quyền có trong danh mục và chỉ giữ mức phạm vi cao nhất của mỗi module
// (mức thấp nhất là mặc định nên không lưu).
func normalizePermissions(perms []string) ([]string, error) {
	c := GetCatalog()
	topLevel := map[string]int{}
	seen := map[string]bool{}
	out := []string{}
	for _, p := range perms {
		switch c.Kind(p) {
		case "":
			return nil, invalid(fmt.Sprintf("Quyền %q không có trong danh mục.", p))
		case KindScope:
			module := moduleOf(p)
			level := slices.Index(c.LevelsOf(module), strings.TrimPrefix(p, module+".scope_"))
			if level > topLevel[module] {
				topLevel[module] = level
			}
		default:
			if !seen[p] {
				seen[p] = true
				out = append(out, p)
			}
		}
	}
	for _, module := range slices.Sorted(maps.Keys(topLevel)) {
		out = append(out, ScopePermission(module, c.LevelsOf(module)[topLevel[module]]))
	}
	slices.Sort(out)
	return out, nil
}

// SetRolePermissions thay toàn bộ quyền của một vai trò (trừ Admin). Người đang giữ vai trò này không được tự bỏ
// quyền quản trị phân quyền của mình.
func (s *Service) SetRolePermissions(ctx context.Context, actor *Access, role string, perms []string) ([]string, error) {
	if role == RoleAdmin {
		return nil, ErrRoleImmutable
	}
	if _, err := s.q.GetRole(ctx, role); errors.Is(err, pgx.ErrNoRows) {
		return nil, ErrRoleNotFound
	} else if err != nil {
		return nil, err
	}
	perms, err := normalizePermissions(perms)
	if err != nil {
		return nil, err
	}
	if slices.Contains(actor.Roles, role) {
		if err := s.ensureKeepsAccessManagement(ctx, actor, actor.Roles, map[string][]string{role: perms}, nil); err != nil {
			return nil, err
		}
	}
	err = pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error {
		q := s.q.WithTx(tx)
		before, err := q.ListPermissionsOfRole(ctx, role)
		if err != nil {
			return err
		}
		if err := q.DeleteRolePermissions(ctx, role); err != nil {
			return err
		}
		for _, p := range perms {
			if err := q.GrantRolePermission(ctx, iamdb.GrantRolePermissionParams{Role: role, Permission: p}); err != nil {
				return err
			}
		}
		added, removed := setDiff(before, perms)
		desc := fmt.Sprintf("Cập nhật vai trò %q", role)
		if len(added)+len(removed) > 0 {
			desc += fmt.Sprintf(" (+%d / −%d quyền)", len(added), len(removed))
		}
		return recordTx(ctx, tx, actor, audit.Entry{
			Module: audit.ModuleUsers, Event: audit.EventUpdated, Action: "role.permissions", SubjectType: "role", SubjectID: role,
			Description: desc, Changes: audit.Changes{"permissions": {Old: before, New: perms}},
			Properties: map[string]any{"added": added, "removed": removed},
		})
	})
	if err != nil {
		return nil, err
	}
	s.log.Info("role permissions updated", "role", role, "count", len(perms), "by", actor.UserID)
	return perms, InvalidateAllAccess(ctx, s.rdb)
}

// ensureKeepsAccessManagement chặn thay đổi làm actor mất quyền quản trị phân quyền đang có.
// roles: tập vai trò sau thay đổi; roleChanges: vai trò → tập quyền mới; overrides: phân quyền cá nhân mới (nil = giữ).
func (s *Service) ensureKeepsAccessManagement(ctx context.Context, actor *Access, roles []string, roleChanges map[string][]string, overrides map[string]bool) error {
	after := &Access{
		UserID: actor.UserID, Roles: roles, SuperAdmin: slices.Contains(roles, RoleAdmin),
		Granted: map[string]bool{}, Overrides: actor.Overrides, Scoped: actor.Scoped,
	}
	if overrides != nil {
		after.Overrides = overrides
	}
	for _, r := range roles {
		perms, ok := roleChanges[r]
		if !ok {
			var err error
			if perms, err = s.q.ListPermissionsOfRole(ctx, r); err != nil {
				return err
			}
		}
		for _, p := range perms {
			after.Granted[p] = true
		}
	}
	var lost []string
	for _, p := range AccessManagement {
		if actor.Can(p) && !after.Can(p) {
			lost = append(lost, p)
		}
	}
	if len(lost) > 0 {
		return &Error{422, "keeps_access_management", "Không thể tự làm mất quyền quản trị phân quyền của chính mình (" +
			strings.Join(lost, ", ") + "). Nhờ một quản trị viên khác thực hiện."}
	}
	return nil
}

// ───────────────────────── Người dùng ─────────────────────────

type UserSummary struct {
	ID                 uuid.UUID  `json:"id"`
	Kind               string     `json:"kind"`
	Code               *string    `json:"code"`
	Email              *string    `json:"email"`
	Phone              *string    `json:"phone"`
	FullName           string     `json:"full_name"`
	Status             string     `json:"status"`
	MustChangePassword bool       `json:"must_change_password"`
	TOTPEnabled        bool       `json:"totp_enabled"`
	Locked             bool       `json:"locked"`
	LastLoginAt        *time.Time `json:"last_login_at"`
	CreatedAt          time.Time  `json:"created_at"`
	Roles              []string   `json:"roles"`
	PrimaryBranchID    *uuid.UUID `json:"primary_branch_id"`
}

type UserDetail struct {
	UserSummary
	Branches []UserBranch `json:"branches"`
}

type UserFilter struct {
	Search string
	Role   string
	Status string
	Page   int
}

const usersPageSize = 50

func strOrNil(s string) *string {
	if s = strings.TrimSpace(s); s == "" {
		return nil
	}
	return &s
}

// ListUsers: chỉ cấp dưới trong cây vai trò (Admin thấy tất cả), lọc thêm theo phạm vi module user:
// chi nhánh → nhân sự có chi nhánh chính thuộc chi nhánh mình; của tôi → tài khoản do mình tạo.
func (s *Service) ListUsers(ctx context.Context, actor *Access, f UserFilter) ([]UserSummary, int64, error) {
	p := iamdb.ListUsersParams{Role: strOrNil(f.Role), Status: strOrNil(f.Status), Lim: usersPageSize}
	if f.Page > 1 {
		p.Off = int32((f.Page - 1) * usersPageSize)
	}
	if q := strings.TrimSpace(f.Search); q != "" {
		like := "%" + strings.NewReplacer(`\`, `\\`, "%", `\%`, "_", `\_`).Replace(q) + "%"
		p.Search = &like
	}
	if !actor.SuperAdmin {
		p.Assignable = actor.AssignableRoles()
		if len(p.Assignable) == 0 {
			return []UserSummary{}, 0, nil
		}
		switch actor.Level("user") {
		case ScopeBranch:
			p.BranchIds = append([]uuid.UUID{}, actor.BranchIDs()...)
		case ScopeOwn:
			p.CreatedBy = &actor.UserID
		}
	}
	rows, err := s.q.ListUsers(ctx, p)
	if err != nil {
		return nil, 0, err
	}
	out := make([]UserSummary, 0, len(rows))
	var total int64
	now := s.now()
	for _, r := range rows {
		total = r.Total
		out = append(out, UserSummary{
			ID: r.ID, Kind: r.Kind, Code: r.Code, Email: r.Email, Phone: r.Phone, FullName: r.FullName,
			Status: r.Status, MustChangePassword: r.MustChangePassword, TOTPEnabled: r.TotpEnabledAt != nil,
			Locked: r.LockedUntil != nil && r.LockedUntil.After(now), LastLoginAt: r.LastLoginAt,
			CreatedAt: r.CreatedAt, Roles: r.Roles, PrimaryBranchID: r.PrimaryBranchID,
		})
	}
	return out, total, nil
}

type target struct {
	user     iamdb.IamUser
	roles    []string
	branches []iamdb.ListUserBranchesRow
}

func (t *target) primaryBranch() *uuid.UUID {
	for _, b := range t.branches {
		if b.IsPrimary {
			return &b.BranchID
		}
	}
	return nil
}

func (s *Service) loadTarget(ctx context.Context, q *iamdb.Queries, id uuid.UUID) (*target, error) {
	u, err := q.GetUser(ctx, id)
	if errors.Is(err, pgx.ErrNoRows) {
		return nil, ErrUserNotFound
	}
	if err != nil {
		return nil, err
	}
	roles, err := q.ListUserRoles(ctx, id)
	if err != nil {
		return nil, err
	}
	branches, err := q.ListUserBranches(ctx, id)
	if err != nil {
		return nil, err
	}
	return &target{u, roles, branches}, nil
}

// canManage là policy "actor được thao tác trên tài khoản target": Admin mọi tài khoản; người khác chỉ cấp dưới
// (mọi vai trò của target đều là vai trò actor gán được), trong chi nhánh mình (mức branch) hoặc do mình tạo (mức own).
func canManage(actor *Access, t *target) error {
	if actor.SuperAdmin {
		return nil
	}
	if len(t.roles) == 0 || slices.ContainsFunc(t.roles, func(r string) bool { return !actor.CanAssignRole(r) }) {
		return ErrNotSubordinate
	}
	switch actor.Level("user") {
	case ScopeBranch:
		if pb := t.primaryBranch(); pb == nil || !slices.Contains(actor.BranchIDs(), *pb) {
			return ErrOutsideBranch
		}
	case ScopeOwn:
		if t.user.CreatedBy == nil || *t.user.CreatedBy != actor.UserID {
			return ErrNotOwnAccount
		}
	}
	return nil
}

func (s *Service) managedTarget(ctx context.Context, actor *Access, id uuid.UUID) (*target, error) {
	t, err := s.loadTarget(ctx, s.q, id)
	if err != nil {
		return nil, err
	}
	return t, canManage(actor, t)
}

func (s *Service) detail(t *target) UserDetail {
	u := t.user
	d := UserDetail{UserSummary: UserSummary{
		ID: u.ID, Kind: u.Kind, Code: u.Code, Email: u.Email, Phone: u.Phone, FullName: u.FullName,
		Status: u.Status, MustChangePassword: u.MustChangePassword, TOTPEnabled: u.TotpEnabledAt != nil,
		Locked: u.LockedUntil != nil && u.LockedUntil.After(s.now()), LastLoginAt: u.LastLoginAt,
		CreatedAt: u.CreatedAt, Roles: t.roles, PrimaryBranchID: t.primaryBranch(),
	}, Branches: make([]UserBranch, 0, len(t.branches))}
	for _, b := range t.branches {
		d.Branches = append(d.Branches, UserBranch{ID: b.BranchID, Code: b.Code, Name: b.Name, Primary: b.IsPrimary})
	}
	return d
}

func (s *Service) GetUserDetail(ctx context.Context, actor *Access, id uuid.UUID) (UserDetail, error) {
	t, err := s.managedTarget(ctx, actor, id)
	if err != nil {
		return UserDetail{}, err
	}
	return s.detail(t), nil
}

// UserOptions: vai trò và chi nhánh người thực hiện được chọn khi tạo / sửa tài khoản.
type UserOptions struct {
	Roles    []RoleRef `json:"roles"`
	Branches []Branch  `json:"branches"`
}

func (s *Service) UserOptions(ctx context.Context, actor *Access) (UserOptions, error) {
	roles, err := s.roleRefs(ctx, actor.AssignableRoles())
	if err != nil {
		return UserOptions{}, err
	}
	all, err := s.ListBranches(ctx)
	if err != nil {
		return UserOptions{}, err
	}
	limit := actor.BranchLimit("user")
	branches := []Branch{}
	for _, b := range all {
		if b.IsActive && (limit == nil || slices.Contains(limit, b.ID)) {
			branches = append(branches, b)
		}
	}
	return UserOptions{roles, branches}, nil
}

type UserInput struct {
	Kind            string      `json:"kind"`
	Code            string      `json:"code"`
	Email           string      `json:"email"`
	Phone           string      `json:"phone"`
	FullName        string      `json:"full_name"`
	Roles           []string    `json:"roles"`
	PrimaryBranchID *uuid.UUID  `json:"primary_branch_id"`
	BranchIDs       []uuid.UUID `json:"branch_ids"`
}

var (
	emailRe = regexp.MustCompile(`^[^@\s]{1,64}@[^@\s]+\.[^@\s]+$`)
	codeRe  = regexp.MustCompile(`^[A-Za-z0-9_-]{2,30}$`)
)

func (in *UserInput) validateProfile() error {
	if n := strings.TrimSpace(in.FullName); n == "" || len([]rune(n)) > 200 {
		return invalid("Họ tên không được trống (tối đa 200 ký tự).")
	}
	if c := strings.TrimSpace(in.Code); c != "" && !codeRe.MatchString(c) {
		return invalid("Mã đăng nhập gồm 2–30 chữ, số, gạch ngang hoặc gạch dưới.")
	}
	if e := strings.TrimSpace(in.Email); e != "" && (!emailRe.MatchString(e) || len(e) > 200) {
		return invalid("Email không hợp lệ.")
	}
	if strings.TrimSpace(in.Phone) != "" && NormalizePhone(in.Phone) == "" {
		return invalid("Số điện thoại không hợp lệ.")
	}
	if strings.TrimSpace(in.Code) == "" && strings.TrimSpace(in.Email) == "" && NormalizePhone(in.Phone) == "" {
		return invalid("Cần ít nhất một trong mã đăng nhập, email, số điện thoại.")
	}
	return nil
}

// branchSet kiểm chi nhánh chính / cấp thêm: tồn tại, đang hoạt động, trong phạm vi người thực hiện.
func (s *Service) branchSet(ctx context.Context, actor *Access, primary *uuid.UUID, extra []uuid.UUID) ([]uuid.UUID, error) {
	if primary == nil {
		if len(extra) > 0 {
			return nil, invalid("Chọn chi nhánh chính trước khi cấp thêm chi nhánh.")
		}
		if actor.BranchLimit("user") != nil {
			return nil, invalid("Chọn chi nhánh cho nhân sự.")
		}
		return nil, nil
	}
	ids := []uuid.UUID{*primary}
	for _, id := range extra {
		if !slices.Contains(ids, id) {
			ids = append(ids, id)
		}
	}
	n, err := s.q.CountActiveBranches(ctx, ids)
	if err != nil {
		return nil, err
	}
	if int(n) != len(ids) {
		return nil, invalid("Chi nhánh không tồn tại hoặc đã ngừng hoạt động.")
	}
	if limit := actor.BranchLimit("user"); limit != nil {
		for _, id := range ids {
			if !slices.Contains(limit, id) {
				return nil, invalid("Bạn chỉ được quản lý nhân sự thuộc chi nhánh của mình.")
			}
		}
	}
	return ids, nil
}

func checkAssignable(actor *Access, roles []string) error {
	c := GetCatalog()
	for _, r := range roles {
		if !c.HasRole(r) {
			return invalid(fmt.Sprintf("Vai trò %q không tồn tại.", r))
		}
		if !actor.CanAssignRole(r) {
			return &Error{403, "role_not_assignable", fmt.Sprintf("Bạn không được gán vai trò %q.", r)}
		}
	}
	return nil
}

func replaceBranches(ctx context.Context, q *iamdb.Queries, uid uuid.UUID, ids []uuid.UUID) error {
	if err := q.DeleteUserBranches(ctx, uid); err != nil {
		return err
	}
	for i, id := range ids {
		if err := q.AddUserBranch(ctx, iamdb.AddUserBranchParams{UserID: uid, BranchID: id, IsPrimary: i == 0}); err != nil {
			return err
		}
	}
	return nil
}

func replaceRoles(ctx context.Context, q *iamdb.Queries, uid uuid.UUID, roles []string) error {
	if err := q.DeleteUserRoles(ctx, uid); err != nil {
		return err
	}
	for _, r := range roles {
		if err := q.AddUserRole(ctx, iamdb.AddUserRoleParams{UserID: uid, Role: r}); err != nil {
			return err
		}
	}
	return nil
}

func dedupe(in []string) []string {
	out := []string{}
	for _, s := range in {
		if s = strings.TrimSpace(s); s != "" && !slices.Contains(out, s) {
			out = append(out, s)
		}
	}
	return out
}

// CreateManagedUser tạo tài khoản kèm vai trò, chi nhánh; trả về mật khẩu tạm (bắt đổi ở lần đăng nhập đầu).
func (s *Service) CreateManagedUser(ctx context.Context, actor *Access, in UserInput) (UserDetail, string, error) {
	if in.Kind == "" {
		in.Kind = "staff"
	}
	if !slices.Contains([]string{"staff", "student", "guardian"}, in.Kind) {
		return UserDetail{}, "", invalid("Loại tài khoản không hợp lệ.")
	}
	if err := in.validateProfile(); err != nil {
		return UserDetail{}, "", err
	}
	if in.Kind == "guardian" && NormalizePhone(in.Phone) == "" {
		return UserDetail{}, "", invalid("Tài khoản phụ huynh cần số điện thoại.")
	}
	in.Roles = dedupe(in.Roles)
	if len(in.Roles) == 0 && !actor.SuperAdmin {
		return UserDetail{}, "", invalid("Chọn ít nhất một vai trò.")
	}
	if err := checkAssignable(actor, in.Roles); err != nil {
		return UserDetail{}, "", err
	}
	branches, err := s.branchSet(ctx, actor, in.PrimaryBranchID, in.BranchIDs)
	if err != nil {
		return UserDetail{}, "", err
	}
	password := RandomPassword()
	hash, err := HashPassword(ctx, password)
	if err != nil {
		return UserDetail{}, "", err
	}
	var t *target
	err = pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error {
		q := s.q.WithTx(tx)
		u, err := q.CreateUser(ctx, iamdb.CreateUserParams{
			Kind: in.Kind, Code: upperOrNil(in.Code), Email: lowerOrNil(in.Email),
			Phone: phoneOrNil(in.Phone), FullName: strings.TrimSpace(in.FullName), PasswordHash: hash,
		})
		if err != nil {
			return uniqueErr(err)
		}
		if err := q.SetUserCreatedBy(ctx, iamdb.SetUserCreatedByParams{ID: u.ID, CreatedBy: &actor.UserID}); err != nil {
			return err
		}
		if err := replaceRoles(ctx, q, u.ID, in.Roles); err != nil {
			return err
		}
		if err := replaceBranches(ctx, q, u.ID, branches); err != nil {
			return err
		}
		if t, err = s.loadTarget(ctx, q, u.ID); err != nil {
			return err
		}
		after := userSnapshot(t)
		after["kind"], after["roles"] = t.user.Kind, t.roles
		return recordTx(ctx, tx, actor, userEntry(t, audit.EventCreated, "user.create", "Tạo tài khoản nhân viên mới", audit.Diff(nil, after)))
	})
	if err != nil {
		return UserDetail{}, "", err
	}
	s.log.Info("user created", "user_id", t.user.ID, "roles", in.Roles, "by", actor.UserID)
	return s.detail(t), password, nil
}

// UpdateManagedUser sửa hồ sơ đăng nhập và chi nhánh. Người bị giới hạn chi nhánh giữ nguyên các chi nhánh cấp thêm
// ngoài phạm vi của mình (do người khác cấp).
func (s *Service) UpdateManagedUser(ctx context.Context, actor *Access, id uuid.UUID, in UserInput) (UserDetail, error) {
	t, err := s.managedTarget(ctx, actor, id)
	if err != nil {
		return UserDetail{}, err
	}
	if err := in.validateProfile(); err != nil {
		return UserDetail{}, err
	}
	if t.user.Kind == "guardian" && NormalizePhone(in.Phone) == "" {
		return UserDetail{}, invalid("Tài khoản phụ huynh cần số điện thoại.")
	}
	branches, err := s.branchSet(ctx, actor, in.PrimaryBranchID, in.BranchIDs)
	if err != nil {
		return UserDetail{}, err
	}
	if limit := actor.BranchLimit("user"); limit != nil {
		for _, b := range t.branches {
			if !b.IsPrimary && !slices.Contains(limit, b.BranchID) && !slices.Contains(branches, b.BranchID) {
				branches = append(branches, b.BranchID)
			}
		}
	}
	before := userSnapshot(t)
	err = pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error {
		q := s.q.WithTx(tx)
		_, err := q.UpdateUserProfile(ctx, iamdb.UpdateUserProfileParams{
			ID: id, Code: upperOrNil(in.Code), Email: lowerOrNil(in.Email),
			Phone: phoneOrNil(in.Phone), FullName: strings.TrimSpace(in.FullName),
		})
		if err != nil {
			return uniqueErr(err)
		}
		if err := replaceBranches(ctx, q, id, branches); err != nil {
			return err
		}
		if t, err = s.loadTarget(ctx, q, id); err != nil {
			return err
		}
		return recordTx(ctx, tx, actor, userEntry(t, audit.EventUpdated, "user.update", "Cập nhật tài khoản nhân viên", audit.Diff(before, userSnapshot(t))))
	})
	if err != nil {
		return UserDetail{}, err
	}
	s.log.Info("user updated", "user_id", id, "by", actor.UserID)
	return s.detail(t), s.invalidateAccess(ctx, id)
}

// SetUserRoles đổi vai trò; chỉ được thêm / bớt vai trò mình gán được, không tự bỏ quyền quản trị phân quyền.
func (s *Service) SetUserRoles(ctx context.Context, actor *Access, id uuid.UUID, roles []string) (UserDetail, error) {
	t, err := s.managedTarget(ctx, actor, id)
	if err != nil {
		return UserDetail{}, err
	}
	roles = dedupe(roles)
	var changed []string
	for _, r := range roles {
		if !slices.Contains(t.roles, r) {
			changed = append(changed, r)
		}
	}
	for _, r := range t.roles {
		if !slices.Contains(roles, r) {
			changed = append(changed, r)
		}
	}
	if err := checkAssignable(actor, changed); err != nil {
		return UserDetail{}, err
	}
	if !actor.SuperAdmin && len(roles) == 0 {
		return UserDetail{}, invalid("Tài khoản cần ít nhất một vai trò.")
	}
	if id == actor.UserID {
		if err := s.ensureKeepsAccessManagement(ctx, actor, roles, nil, nil); err != nil {
			return UserDetail{}, err
		}
	}
	err = pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error {
		if err := replaceRoles(ctx, s.q.WithTx(tx), id, roles); err != nil {
			return err
		}
		after := slices.Sorted(slices.Values(roles))
		return recordTx(ctx, tx, actor, userEntry(t, audit.EventUpdated, "user.roles", "Cập nhật vai trò nhân viên",
			audit.Changes{"roles": {Old: t.roles, New: after}}))
	})
	if err != nil {
		return UserDetail{}, err
	}
	t.roles = roles
	slices.Sort(t.roles)
	s.log.Info("user roles updated", "user_id", id, "roles", roles, "by", actor.UserID)
	return s.detail(t), s.invalidateAccess(ctx, id)
}

// SetUserLocked vô hiệu hóa (đăng xuất mọi thiết bị ngay) hoặc kích hoạt lại tài khoản.
func (s *Service) SetUserLocked(ctx context.Context, actor *Access, id uuid.UUID, locked bool) error {
	t, err := s.managedTarget(ctx, actor, id)
	if err != nil {
		return err
	}
	if id == actor.UserID {
		return ErrSelfAction
	}
	status := "active"
	if locked {
		status = "disabled"
	}
	desc, action := "Kích hoạt lại tài khoản", "user.unlock"
	if locked {
		desc, action = "Vô hiệu hóa tài khoản", "user.lock"
	}
	err = pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error {
		if err := s.q.WithTx(tx).SetUserStatus(ctx, iamdb.SetUserStatusParams{ID: id, Status: status}); err != nil {
			return err
		}
		return recordTx(ctx, tx, actor, userEntry(t, audit.EventUpdated, action, desc, audit.Changes{"status": {Old: t.user.Status, New: status}}))
	})
	if err != nil {
		return err
	}
	if locked {
		if err := s.sessions.revokeAll(ctx, id, ""); err != nil {
			return err
		}
	} else if err := s.q.UnlockUser(ctx, id); err != nil {
		return err
	}
	s.log.Info("user status changed", "user_id", t.user.ID, "status", status, "by", actor.UserID)
	return nil
}

// ResetManagedPassword cấp mật khẩu tạm mới cho tài khoản cấp dưới.
func (s *Service) ResetManagedPassword(ctx context.Context, actor *Access, id uuid.UUID) (string, error) {
	t, err := s.managedTarget(ctx, actor, id)
	if err != nil {
		return "", err
	}
	if id == actor.UserID {
		return "", ErrSelfAction
	}
	password, err := s.resetPassword(ctx, t.user.ID)
	if err != nil {
		return "", err
	}
	s.log.Info("password reset", "user_id", id, "by", actor.UserID)
	s.record(ctx, actor, userEntry(t, audit.EventUpdated, "user.reset_password", "Đặt lại mật khẩu (bắt buộc đổi ở lần đăng nhập tới)", nil))
	return password, nil
}

// ResetManagedTOTP tắt 2FA của người mất điện thoại; họ phải bật lại ở lần đăng nhập tới nếu vai trò bắt buộc.
func (s *Service) ResetManagedTOTP(ctx context.Context, actor *Access, id uuid.UUID) error {
	t, err := s.managedTarget(ctx, actor, id)
	if err != nil {
		return err
	}
	if id == actor.UserID {
		return ErrSelfAction
	}
	err = pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error {
		q := s.q.WithTx(tx)
		if err := q.DisableTOTP(ctx, id); err != nil {
			return err
		}
		if err := q.DeleteRecoveryCodes(ctx, id); err != nil {
			return err
		}
		return recordTx(ctx, tx, actor, userEntry(t, audit.EventUpdated, "user.reset_totp", "Đặt lại xác thực hai bước (người dùng phải bật lại)",
			audit.Changes{"totp_enabled": {Old: t.user.TotpEnabledAt != nil, New: false}}))
	})
	if err != nil {
		return err
	}
	s.log.Info("totp reset", "user_id", id, "by", actor.UserID)
	return s.sessions.revokeAll(ctx, id, "")
}

// ───────────────────────── Phân quyền cá nhân ─────────────────────────

type Override struct {
	Permission string `json:"permission"`
	Allow      bool   `json:"allow"`
}

type UserPermissions struct {
	User            UserDetail `json:"user"`
	RolePermissions []string   `json:"role_permissions"`
	Overrides       []Override `json:"overrides"`
	Effective       []string   `json:"effective"`
}

func (s *Service) overrideTarget(ctx context.Context, actor *Access, id uuid.UUID) (*target, error) {
	t, err := s.loadTarget(ctx, s.q, id)
	if err != nil {
		return nil, err
	}
	switch {
	case id == actor.UserID && !actor.SuperAdmin:
		return nil, ErrSelfOverride
	case slices.Contains(t.roles, RoleAdmin) && !actor.SuperAdmin:
		return nil, ErrAdminOnlyTarget
	case id == actor.UserID:
		return t, nil
	}
	return t, canManage(actor, t)
}

func (s *Service) GetUserPermissions(ctx context.Context, actor *Access, id uuid.UUID) (UserPermissions, error) {
	t, err := s.overrideTarget(ctx, actor, id)
	if err != nil {
		return UserPermissions{}, err
	}
	acc, err := s.computeAccess(ctx, s.q, id)
	if err != nil {
		return UserPermissions{}, err
	}
	out := UserPermissions{User: s.detail(t), RolePermissions: []string{}, Overrides: []Override{}, Effective: acc.Permissions()}
	for p := range acc.Granted {
		out.RolePermissions = append(out.RolePermissions, p)
	}
	slices.Sort(out.RolePermissions)
	for _, p := range slices.Sorted(maps.Keys(acc.Overrides)) {
		out.Overrides = append(out.Overrides, Override{p, acc.Overrides[p]})
	}
	if out.Effective == nil {
		out.Effective = []string{}
	}
	return out, nil
}

// SetUserOverrides thay toàn bộ phân quyền cá nhân phạm vi "all" của một người.
func (s *Service) SetUserOverrides(ctx context.Context, actor *Access, id uuid.UUID, list []Override) (UserPermissions, error) {
	t, err := s.overrideTarget(ctx, actor, id)
	if err != nil {
		return UserPermissions{}, err
	}
	c := GetCatalog()
	next := map[string]bool{}
	for _, o := range list {
		if c.Kind(o.Permission) == "" {
			return UserPermissions{}, invalid(fmt.Sprintf("Quyền %q không có trong danh mục.", o.Permission))
		}
		next[o.Permission] = o.Allow
	}
	if id == actor.UserID {
		if err := s.ensureKeepsAccessManagement(ctx, actor, actor.Roles, nil, next); err != nil {
			return UserPermissions{}, err
		}
	}
	err = pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error {
		q := s.q.WithTx(tx)
		old, err := q.ListUserOverrides(ctx, id)
		if err != nil {
			return err
		}
		prev := map[string]bool{}
		for _, o := range old {
			if o.ScopeType == "all" {
				prev[o.Permission] = o.Allow
			}
		}
		if err := q.DeleteUserOverridesAll(ctx, id); err != nil {
			return err
		}
		for _, p := range slices.Sorted(maps.Keys(next)) {
			err := q.AddUserOverride(ctx, iamdb.AddUserOverrideParams{
				UserID: id, Permission: p, Allow: next[p], ScopeType: "all", CreatedBy: &actor.UserID,
			})
			if err != nil {
				return err
			}
		}
		before, after := overrideList(prev), overrideList(next)
		added, removed := setDiff(before, after)
		e := userEntry(t, audit.EventUpdated, "user.overrides", "Cập nhật phân quyền chi tiết cá nhân",
			audit.Changes{"overrides": {Old: before, New: after}})
		e.Properties = map[string]any{"added": added, "removed": removed}
		return recordTx(ctx, tx, actor, e)
	})
	if err != nil {
		return UserPermissions{}, err
	}
	if err := s.invalidateAccess(ctx, id); err != nil {
		return UserPermissions{}, err
	}
	s.log.Info("user overrides updated", "user_id", id, "count", len(next), "by", actor.UserID)
	return s.GetUserPermissions(ctx, actor, id)
}

// SetRolesByIdentifier gán vai trò từ dòng lệnh trên máy chủ (tạo Admin đầu tiên); không qua kiểm cây vai trò.
func (s *Service) SetRolesByIdentifier(ctx context.Context, identifier string, roles []string) (User, error) {
	u, _, err := s.findUser(ctx, identifier)
	if err != nil {
		return User{}, err
	}
	return toUser(u), s.SetRolesUnchecked(ctx, u.ID, roles)
}

// SetRolesUnchecked thay vai trò của một tài khoản, chỉ kiểm vai trò tồn tại (dùng cho dòng lệnh).
func (s *Service) SetRolesUnchecked(ctx context.Context, uid uuid.UUID, roles []string) error {
	roles = dedupe(roles)
	for _, r := range roles {
		if !GetCatalog().HasRole(r) {
			return fmt.Errorf("vai trò %q không tồn tại", r)
		}
	}
	err := pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error { return replaceRoles(ctx, s.q.WithTx(tx), uid, roles) })
	if err != nil {
		return err
	}
	s.log.Info("user roles set from cli", "user_id", uid, "roles", roles)
	return s.invalidateAccess(ctx, uid)
}
