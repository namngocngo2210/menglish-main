package iam

import (
	"context"
	"database/sql"
	"fmt"
	"maps"
	"regexp"
	"slices"
	"strconv"
	"strings"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5/pgtype"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam/iamdb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/legacy"
)

// Nhập chi nhánh, tài khoản, vai trò, quyền theo vai trò và phân quyền cá nhân từ Laravel (menglish import-legacy).
// Bảng cũ: branches, users, user_branches, roles / model_has_roles / role_has_permissions (spatie), user_permission_overrides.

// LegacySteps: các bước của iam, chạy trước mọi phân hệ khác (phân hệ khác tra chi nhánh / tài khoản qua id_map).
func LegacySteps() []legacy.Step {
	return []legacy.Step{
		{Name: "branches", Run: importBranches},
		{Name: "users", Run: importUsers},
		{Name: "staff_profiles", Run: importStaffProfiles},
		{Name: "role_permissions", Run: importRolePermissions},
		{Name: "permission_overrides", Run: importOverrides},
	}
}

const (
	EntityBranch = "branch"
	EntityUser   = "user"
)

var nonCode = regexp.MustCompile(`[^A-Za-z0-9_-]+`)

// legacyCode: mã cũ có thể có dấu cách / ký tự lạ; v2 chỉ nhận chữ, số, gạch ngang, gạch dưới (tối đa 20).
func legacyCode(s string) string {
	c := strings.ToUpper(nonCode.ReplaceAllString(strings.TrimSpace(s), "-"))
	c = strings.Trim(c, "-")
	if len(c) > 20 {
		c = c[:20]
	}
	return c
}

func nullStr(s sql.NullString) *string {
	if !s.Valid || strings.TrimSpace(s.String) == "" {
		return nil
	}
	v := strings.TrimSpace(s.String)
	return &v
}

func nullFloat(s sql.NullString) *float64 {
	if !s.Valid {
		return nil
	}
	f, err := strconv.ParseFloat(s.String, 64)
	if err != nil {
		return nil
	}
	return &f
}

// nullClock: cột TIME của MySQL ("08:00:00") → "08:00"; rỗng / sai → nil.
func nullClock(s sql.NullString) *string {
	if !s.Valid || len(s.String) < 5 {
		return nil
	}
	if _, err := time.Parse("15:04", s.String[:5]); err != nil {
		return nil
	}
	v := s.String[:5]
	return &v
}

func importBranches(ctx context.Context, r *legacy.Run) error {
	rows, err := r.Src.QueryContext(ctx, `SELECT id, code, name, address, phone, is_active, deleted_at IS NOT NULL,
		latitude, longitude, checkin_radius, work_start_time, work_end_time, late_grace_minutes, created_at
		FROM branches ORDER BY id`)
	if err != nil {
		return err
	}
	defer rows.Close()
	for rows.Next() {
		var (
			id                   int64
			code, name           string
			address, phone       sql.NullString
			active, deleted      bool
			lat, lng, start, end sql.NullString
			radius, grace        sql.NullInt64
			createdAt            sql.NullTime
		)
		if err := rows.Scan(&id, &code, &name, &address, &phone, &active, &deleted, &lat, &lng, &radius, &start, &end, &grace, &createdAt); err != nil {
			return err
		}
		c := legacyCode(code)
		if c == "" {
			c = "CN" + strconv.FormatInt(id, 10)
		}
		if c != strings.ToUpper(strings.TrimSpace(code)) {
			r.Note("chi nhánh #%d: mã %q đổi thành %q", id, code, c)
		}
		var ph *string
		if p := nullStr(phone); p != nil {
			if n := NormalizePhone(*p); n != "" {
				ph = &n
			} else {
				r.Note("chi nhánh %s: bỏ số điện thoại không hợp lệ %q", c, *p)
			}
		}
		if deleted {
			active = false
			r.Note("chi nhánh %s đã xóa bên Laravel: nhập ở trạng thái ngừng hoạt động", c)
		}
		la, lo := nullFloat(lat), nullFloat(lng)
		if la == nil || lo == nil {
			la, lo = nil, nil
		}
		rad := int64(defaultCheckinRadius)
		if radius.Valid && radius.Int64 > 0 {
			rad = radius.Int64
		}
		created := time.Now()
		if createdAt.Valid {
			created = createdAt.Time
		}
		args := []any{c, strings.TrimSpace(name), nullStr(address), ph, active, la, lo, rad, nullClock(start), nullClock(end), max(grace.Int64, 0)}
		_, err := r.Upsert(ctx, EntityBranch, id,
			func() (uuid.UUID, error) {
				var nid uuid.UUID
				// Chi nhánh cùng mã đã tạo tay trên v2 thì nhận làm một, không tạo trùng.
				err := r.Tx.QueryRow(ctx, `INSERT INTO core.branches (code, name, address, phone, is_active, latitude, longitude,
					checkin_radius, work_start_time, work_end_time, late_grace_minutes, created_at)
					VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9::time, $10::time, $11, $12)
					ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, address = EXCLUDED.address, phone = EXCLUDED.phone,
					  is_active = EXCLUDED.is_active, latitude = EXCLUDED.latitude, longitude = EXCLUDED.longitude,
					  checkin_radius = EXCLUDED.checkin_radius, work_start_time = EXCLUDED.work_start_time,
					  work_end_time = EXCLUDED.work_end_time, late_grace_minutes = EXCLUDED.late_grace_minutes, updated_at = now()
					RETURNING id`, append(args, created)...).Scan(&nid)
				return nid, err
			},
			func(nid uuid.UUID) (bool, error) {
				return legacy.Updated(r.Tx.Exec(ctx, `UPDATE core.branches SET code = $2, name = $3, address = $4, phone = $5, is_active = $6,
					latitude = $7, longitude = $8, checkin_radius = $9, work_start_time = $10::time, work_end_time = $11::time,
					late_grace_minutes = $12, updated_at = now() WHERE id = $1`, append([]any{nid}, args...)...))
			})
		if err != nil {
			return err
		}
	}
	return rows.Err()
}

// Vai trò đã bỏ bên Laravel (Roles::RETIRED) → vai trò thay thế.
var retiredRoles = map[string]string{"accountant": RoleManager, "teacher": RoleTeacherFulltime}

type legacyUser struct {
	id                          int64
	code, name, email           sql.NullString
	phone, password             sql.NullString
	active, deleted, mustChange bool
	locked                      bool
	branchID, createdBy         sql.NullInt64
	lastLogin, createdAt        sql.NullTime
	roles                       []string
	branches                    []int64
}

// importUsers: tài khoản + vai trò + chi nhánh. Mật khẩu giữ nguyên chuỗi bcrypt của Laravel (đăng nhập lần đầu sẽ băm
// lại bằng argon2id). Tài khoản cùng mã / email đã tạo tay trên v2 thì nhận làm một; khi đó giữ mật khẩu và 2FA của v2.
func importUsers(ctx context.Context, r *legacy.Run) error {
	users := map[int64]*legacyUser{}
	var order []int64
	rows, err := r.Src.QueryContext(ctx, `SELECT id, employee_code, name, email, phone, password, is_active, deleted_at IS NOT NULL,
		must_change_password, locked_at IS NOT NULL, branch_id, created_by, last_login_at, created_at FROM users ORDER BY id`)
	if err != nil {
		return err
	}
	for rows.Next() {
		u := &legacyUser{}
		if err := rows.Scan(&u.id, &u.code, &u.name, &u.email, &u.phone, &u.password, &u.active, &u.deleted, &u.mustChange,
			&u.locked, &u.branchID, &u.createdBy, &u.lastLogin, &u.createdAt); err != nil {
			rows.Close()
			return err
		}
		users[u.id] = u
		order = append(order, u.id)
	}
	rows.Close()
	if err := rows.Err(); err != nil {
		return err
	}

	roleRows, err := r.Src.QueryContext(ctx, `SELECT mhr.model_id, r.name FROM model_has_roles mhr JOIN roles r ON r.id = mhr.role_id
		WHERE mhr.model_type LIKE '%User' ORDER BY mhr.model_id, r.name`)
	if err != nil {
		return err
	}
	for roleRows.Next() {
		var uid int64
		var role string
		if err := roleRows.Scan(&uid, &role); err != nil {
			roleRows.Close()
			return err
		}
		if u := users[uid]; u != nil {
			if to, ok := retiredRoles[role]; ok {
				role = to
			}
			if !GetCatalog().HasRole(role) {
				r.Note("tài khoản #%d: bỏ vai trò %q (không có trong 9 vai trò)", uid, role)
				continue
			}
			if !slices.Contains(u.roles, role) {
				u.roles = append(u.roles, role)
			}
		}
	}
	roleRows.Close()
	if err := roleRows.Err(); err != nil {
		return err
	}

	ubRows, err := r.Src.QueryContext(ctx, `SELECT user_id, branch_id FROM user_branches ORDER BY user_id, branch_id`)
	if err != nil {
		return err
	}
	for ubRows.Next() {
		var uid, bid int64
		if err := ubRows.Scan(&uid, &bid); err != nil {
			ubRows.Close()
			return err
		}
		if u := users[uid]; u != nil {
			u.branches = append(u.branches, bid)
		}
	}
	ubRows.Close()
	if err := ubRows.Err(); err != nil {
		return err
	}

	q := iamdb.New(r.Tx)
	for _, lid := range order {
		u := users[lid]
		if err := importUser(ctx, r, q, u); err != nil {
			return err
		}
	}
	// Người tạo: sau khi mọi tài khoản đã có uuid.
	for _, lid := range order {
		u := users[lid]
		if !u.createdBy.Valid {
			continue
		}
		id, ok, err := r.Lookup(ctx, EntityUser, lid)
		if err != nil || !ok {
			continue
		}
		if by, ok, err := r.Lookup(ctx, EntityUser, u.createdBy.Int64); err == nil && ok {
			if err := q.SetUserCreatedBy(ctx, iamdb.SetUserCreatedByParams{ID: id, CreatedBy: &by}); err != nil {
				return err
			}
		}
	}
	return nil
}

func importUser(ctx context.Context, r *legacy.Run, q *iamdb.Queries, u *legacyUser) error {
	label := fmt.Sprintf("tài khoản #%d", u.id)
	code := strings.TrimSpace(u.code.String)
	if code != "" {
		label = "tài khoản " + code
	}
	name := strings.TrimSpace(u.name.String)
	if name == "" {
		name = code
	}
	if len([]rune(name)) > 200 {
		name = string([]rune(name)[:200])
	}
	email := nullStr(u.email)
	if email != nil && !strings.Contains(*email, "@") {
		email = nil
	}
	var phone *string
	if p := nullStr(u.phone); p != nil {
		if n := NormalizePhone(*p); n != "" {
			phone = &n
		} else {
			r.Note("%s: bỏ số điện thoại không hợp lệ %q", label, *p)
		}
	}
	var codePtr *string
	if code != "" {
		codePtr = &code
	}
	if codePtr == nil && email == nil && phone == nil {
		r.Skip("%s: không có mã, email hay số điện thoại để đăng nhập", label)
		return nil
	}
	if !u.password.Valid || !IsLegacyHash(u.password.String) {
		r.Skip("%s: mật khẩu không phải bcrypt của Laravel", label)
		return nil
	}
	if name == "" {
		r.Skip("%s: thiếu họ tên", label)
		return nil
	}
	kind := "staff"
	if len(u.roles) == 1 && u.roles[0] == RoleStudent {
		kind = "student"
	}
	status := "active"
	if !u.active || u.deleted || u.locked {
		status = "disabled"
	}

	id, err := r.Upsert(ctx, EntityUser, u.id,
		func() (uuid.UUID, error) {
			// Đã có trên v2 (tạo tay, vd. Admin đầu tiên) theo mã hoặc email → nhận làm một, giữ mật khẩu / 2FA của v2.
			var existing uuid.UUID
			err := r.Tx.QueryRow(ctx, `SELECT id FROM iam.users WHERE ($1::citext IS NOT NULL AND code = $1::citext)
				OR ($2::citext IS NOT NULL AND email = $2::citext) ORDER BY (code = $1::citext) DESC NULLS LAST LIMIT 1`, codePtr, email).Scan(&existing)
			if err == nil {
				r.Note("%s: nhận làm một với tài khoản đã có trên v2", label)
				_, err := r.Tx.Exec(ctx, `UPDATE iam.users SET full_name = $2, phone = $3, status = $4, updated_at = now() WHERE id = $1`,
					existing, name, phone, status)
				return existing, err
			}
			var nid uuid.UUID
			err = r.Tx.QueryRow(ctx, `INSERT INTO iam.users (kind, code, email, phone, full_name, password_hash, must_change_password,
				status, last_login_at, created_at) VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, coalesce($10, now())) RETURNING id`,
				kind, codePtr, email, phone, name, u.password.String, u.mustChange, status, nullTime(u.lastLogin), nullTime(u.createdAt)).Scan(&nid)
			return nid, err
		},
		func(id uuid.UUID) (bool, error) {
			// Mật khẩu chỉ cập nhật khi người dùng chưa đổi / đăng nhập trên v2 (chuỗi băm vẫn là bcrypt cũ).
			return legacy.Updated(r.Tx.Exec(ctx, `UPDATE iam.users SET code = $2, email = $3, phone = $4, full_name = $5, status = $6,
				password_hash = CASE WHEN password_hash LIKE '$2%' THEN $7 ELSE password_hash END,
				must_change_password = CASE WHEN password_hash LIKE '$2%' THEN $8 ELSE must_change_password END,
				updated_at = now() WHERE id = $1`, id, codePtr, email, phone, name, status, u.password.String, u.mustChange))
		})
	if err != nil {
		return err
	}

	// Vai trò và chi nhánh lấy đúng theo Laravel (chi nhánh chính = users.branch_id).
	if err := replaceRoles(ctx, q, id, u.roles); err != nil {
		return err
	}
	var branches []uuid.UUID
	var primary *uuid.UUID
	ids := u.branches
	if u.branchID.Valid && !slices.Contains(ids, u.branchID.Int64) {
		ids = append([]int64{u.branchID.Int64}, ids...)
	}
	for _, bid := range ids {
		b, ok, err := r.Lookup(ctx, EntityBranch, bid)
		if err != nil {
			return err
		}
		if !ok {
			r.Note("%s: chi nhánh #%d chưa nhập, bỏ qua", label, bid)
			continue
		}
		if u.branchID.Valid && bid == u.branchID.Int64 {
			primary = &b
		}
		branches = append(branches, b)
	}
	if primary != nil {
		// replaceBranches lấy phần tử đầu làm chi nhánh chính.
		branches = slices.DeleteFunc(branches, func(b uuid.UUID) bool { return b == *primary })
		branches = append([]uuid.UUID{*primary}, branches...)
	}
	return replaceBranches(ctx, q, id, branches)
}

func nullTime(t sql.NullTime) *time.Time {
	if !t.Valid {
		return nil
	}
	return &t.Time
}

// importRolePermissions: ma trận quyền theo vai trò đã chỉnh trên Laravel (role_has_permissions). Admin luôn toàn quyền.
func importRolePermissions(ctx context.Context, r *legacy.Run) error {
	rows, err := r.Src.QueryContext(ctx, `SELECT r.name, p.name FROM role_has_permissions rhp
		JOIN roles r ON r.id = rhp.role_id JOIN permissions p ON p.id = rhp.permission_id ORDER BY r.name, p.name`)
	if err != nil {
		return err
	}
	perms := map[string][]string{}
	for rows.Next() {
		var role, perm string
		if err := rows.Scan(&role, &perm); err != nil {
			rows.Close()
			return err
		}
		if to, ok := retiredRoles[role]; ok {
			role = to
		}
		if !GetCatalog().HasRole(role) || role == RoleAdmin {
			continue
		}
		if GetCatalog().Kind(perm) == "" {
			r.Note("vai trò %s: bỏ quyền %q (không có trong danh mục)", role, perm)
			continue
		}
		perms[role] = append(perms[role], perm)
	}
	rows.Close()
	if err := rows.Err(); err != nil {
		return err
	}
	q := iamdb.New(r.Tx)
	for _, role := range slices.Sorted(maps.Keys(perms)) {
		list, err := normalizePermissions(perms[role])
		if err != nil {
			return err
		}
		if err := q.DeleteRolePermissions(ctx, role); err != nil {
			return err
		}
		for _, p := range list {
			if err := q.GrantRolePermission(ctx, iamdb.GrantRolePermissionParams{Role: role, Permission: p}); err != nil {
				return err
			}
		}
		r.Note("vai trò %s: %d quyền", role, len(list))
	}
	return nil
}

// importOverrides: phân quyền cá nhân. Phạm vi lớp (scope_type = class) chờ phân hệ lớp học nhập xong.
func importOverrides(ctx context.Context, r *legacy.Run) error {
	rows, err := r.Src.QueryContext(ctx, `SELECT id, user_id, module, action, allow, scope_type, scope_id, created_by
		FROM user_permission_overrides ORDER BY user_id, id`)
	if err != nil {
		return err
	}
	type ov struct {
		id, user         int64
		perm, scope      string
		allow            bool
		scopeID, created sql.NullInt64
	}
	var list []ov
	for rows.Next() {
		var o ov
		var module, action string
		if err := rows.Scan(&o.id, &o.user, &module, &action, &o.allow, &o.scope, &o.scopeID, &o.created); err != nil {
			rows.Close()
			return err
		}
		o.perm = module + "." + action
		list = append(list, o)
	}
	rows.Close()
	if err := rows.Err(); err != nil {
		return err
	}
	q := iamdb.New(r.Tx)
	cleared := map[uuid.UUID]bool{}
	for _, o := range list {
		uid, ok, err := r.Lookup(ctx, EntityUser, o.user)
		if err != nil {
			return err
		}
		if !ok {
			r.Skip("phân quyền #%d: tài khoản #%d chưa nhập", o.id, o.user)
			continue
		}
		if !cleared[uid] {
			if err := q.DeleteUserOverridesAll(ctx, uid); err != nil {
				return err
			}
			cleared[uid] = true
		}
		if GetCatalog().Kind(o.perm) == "" {
			r.Skip("phân quyền #%d: quyền %q không có trong danh mục", o.id, o.perm)
			continue
		}
		p := iamdb.AddUserOverrideParams{UserID: uid, Permission: o.perm, Allow: o.allow, ScopeType: o.scope}
		switch o.scope {
		case "all":
		case "branch":
			b, ok, err := r.Lookup(ctx, EntityBranch, o.scopeID.Int64)
			if err != nil {
				return err
			}
			if !ok {
				r.Skip("phân quyền #%d: chi nhánh #%d chưa nhập", o.id, o.scopeID.Int64)
				continue
			}
			p.ScopeID = &b
		default:
			r.Skip("phân quyền #%d (%s, phạm vi %s): chờ nhập lớp học", o.id, o.perm, o.scope)
			continue
		}
		if o.created.Valid {
			if by, ok, _ := r.Lookup(ctx, EntityUser, o.created.Int64); ok {
				p.CreatedBy = &by
			}
		}
		if err := q.AddUserOverride(ctx, p); err != nil {
			return err
		}
		r.Count().Inserted++
	}
	return nil
}

// importStaffProfiles: hồ sơ nhân sự (cột hồ sơ / hợp đồng / lương trên bảng users của Laravel). File hợp đồng
// (contract_file_path) chờ phân hệ lưu file. Lương làm tròn về đồng.
func importStaffProfiles(ctx context.Context, r *legacy.Run) error {
	rows, err := r.Src.QueryContext(ctx, `SELECT id, id_card_number, hometown, current_address, emergency_contact,
		graduation_school, certificates, teaching_level, contract_type, contract_start_date, contract_end_date,
		COALESCE(ROUND(base_salary), 0), COALESCE(ROUND(hourly_rate), 0), COALESCE(academic_teaching, 0) FROM users ORDER BY id`)
	if err != nil {
		return err
	}
	type profile struct {
		id                                                 int64
		idCard, hometown, address, emergency, school, cert sql.NullString
		level, contract                                    sql.NullString
		start, end                                         sql.NullTime
		base, hourly                                       int64
		teaching                                           bool
	}
	var list []profile
	for rows.Next() {
		var p profile
		if err := rows.Scan(&p.id, &p.idCard, &p.hometown, &p.address, &p.emergency, &p.school, &p.cert, &p.level,
			&p.contract, &p.start, &p.end, &p.base, &p.hourly, &p.teaching); err != nil {
			rows.Close()
			return err
		}
		list = append(list, p)
	}
	rows.Close()
	if err := rows.Err(); err != nil {
		return err
	}
	q := iamdb.New(r.Tx)
	text := func(v sql.NullString, max int) *string {
		s := nullStr(v)
		if s != nil && len([]rune(*s)) > max {
			cut := string([]rune(*s)[:max])
			s = &cut
		}
		return s
	}
	date := func(t sql.NullTime) pgtype.Date { return pgtype.Date{Time: t.Time, Valid: t.Valid} }
	for _, p := range list {
		id, ok, err := r.Lookup(ctx, EntityUser, p.id)
		if err != nil {
			return err
		}
		if !ok {
			continue // tài khoản bị bỏ ở bước users
		}
		var kind string
		if err := r.Tx.QueryRow(ctx, "SELECT kind FROM iam.users WHERE id = $1", id).Scan(&kind); err != nil {
			return err
		}
		empty := !p.idCard.Valid && !p.hometown.Valid && !p.address.Valid && !p.emergency.Valid && !p.school.Valid &&
			!p.cert.Valid && !p.level.Valid && !p.contract.Valid && !p.start.Valid && !p.end.Valid && p.base == 0 && p.hourly == 0 && !p.teaching
		if kind != "staff" || empty {
			continue
		}
		if p.base < 0 || p.hourly < 0 {
			r.Note("tài khoản #%d: lương âm, nhập thành 0", p.id)
			p.base, p.hourly = max(p.base, 0), max(p.hourly, 0)
		}
		_, err = q.UpsertStaffProfile(ctx, iamdb.UpsertStaffProfileParams{
			UserID: id, IDCardNumber: text(p.idCard, 30), Hometown: text(p.hometown, 255), CurrentAddress: text(p.address, 255),
			EmergencyContact: text(p.emergency, 255), GraduationSchool: text(p.school, 255), Certificates: text(p.cert, 255),
			TeachingLevel: text(p.level, 255), ContractType: text(p.contract, 50), ContractStartDate: date(p.start),
			ContractEndDate: date(p.end), BaseSalary: p.base, HourlyRate: p.hourly, AcademicTeaching: p.teaching,
		})
		if err != nil {
			return fmt.Errorf("hồ sơ tài khoản #%d: %w", p.id, err)
		}
		r.Count().Inserted++
	}
	return nil
}
