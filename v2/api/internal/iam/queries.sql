-- name: CreateUser :one
INSERT INTO iam.users (kind, code, email, phone, full_name, password_hash, must_change_password)
VALUES ($1, $2, $3, $4, $5, $6, true)
RETURNING *;

-- name: GetUser :one
SELECT * FROM iam.users WHERE id = $1;

-- name: GetUserByEmail :one
SELECT * FROM iam.users WHERE email = $1;

-- name: GetUserByCode :one
SELECT * FROM iam.users WHERE code = $1;

-- name: GetGuardianByPhone :one
SELECT * FROM iam.users WHERE kind = 'guardian' AND phone = $1;

-- name: RecordLoginFailure :one
-- Đủ 10 lần sai liên tiếp thì khóa 15 phút và đếm lại từ đầu.
UPDATE iam.users SET
    failed_logins = CASE WHEN failed_logins + 1 >= 10 THEN 0 ELSE failed_logins + 1 END,
    locked_until  = CASE WHEN failed_logins + 1 >= 10 THEN now() + interval '15 minutes' ELSE locked_until END,
    updated_at    = now()
WHERE id = $1
RETURNING locked_until;

-- name: RecordLoginSuccess :exec
UPDATE iam.users SET failed_logins = 0, locked_until = NULL, last_login_at = now(), updated_at = now()
WHERE id = $1;

-- name: UnlockUser :exec
UPDATE iam.users SET failed_logins = 0, locked_until = NULL, updated_at = now() WHERE id = $1;

-- name: RehashPassword :exec
UPDATE iam.users SET password_hash = $2, updated_at = now() WHERE id = $1;

-- name: SetPassword :exec
UPDATE iam.users SET
    password_hash = $2, must_change_password = $3, password_changed_at = now(), updated_at = now()
WHERE id = $1;

-- name: EnableTOTP :exec
UPDATE iam.users SET totp_secret = $2, totp_enabled_at = now(), updated_at = now() WHERE id = $1;

-- name: DisableTOTP :exec
UPDATE iam.users SET totp_secret = NULL, totp_enabled_at = NULL, updated_at = now() WHERE id = $1;

-- name: DeleteRecoveryCodes :exec
DELETE FROM iam.totp_recovery_codes WHERE user_id = $1;

-- name: InsertRecoveryCode :exec
INSERT INTO iam.totp_recovery_codes (user_id, code_hash) VALUES ($1, $2);

-- name: UseRecoveryCode :execrows
UPDATE iam.totp_recovery_codes SET used_at = now()
WHERE user_id = $1 AND code_hash = $2 AND used_at IS NULL;

-- name: CountUnusedRecoveryCodes :one
SELECT count(*) FROM iam.totp_recovery_codes WHERE user_id = $1 AND used_at IS NULL;

-- ───────────── Vai trò, quyền, chi nhánh ─────────────

-- name: ListPermissionNames :many
SELECT name FROM iam.permissions ORDER BY name;

-- name: UpsertPermission :exec
INSERT INTO iam.permissions (name, module, kind) VALUES ($1, $2, $3)
ON CONFLICT (name) DO UPDATE SET module = EXCLUDED.module, kind = EXCLUDED.kind;

-- name: DeletePermission :exec
DELETE FROM iam.permissions WHERE name = $1;

-- name: InsertRoleIfMissing :execrows
INSERT INTO iam.roles (name, label, short_label, sort) VALUES ($1, $2, $3, $4)
ON CONFLICT (name) DO NOTHING;

-- name: SetRoleSort :exec
UPDATE iam.roles SET sort = $2 WHERE name = $1 AND sort <> $2;

-- name: GrantRolePermission :exec
INSERT INTO iam.role_permissions (role, permission) VALUES ($1, $2) ON CONFLICT DO NOTHING;

-- name: DeleteRolePermissions :exec
DELETE FROM iam.role_permissions WHERE role = $1;

-- name: ListRoles :many
SELECT r.*, (SELECT count(*) FROM iam.user_roles ur WHERE ur.role = r.name)::bigint AS user_count
FROM iam.roles r ORDER BY r.sort, r.name;

-- name: GetRole :one
SELECT * FROM iam.roles WHERE name = $1;

-- name: UpdateRoleLabels :exec
UPDATE iam.roles SET label = $2, short_label = $3, updated_at = now() WHERE name = $1;

-- name: ListRolePermissions :many
SELECT role, permission FROM iam.role_permissions ORDER BY role, permission;

-- name: ListPermissionsOfRole :many
SELECT permission FROM iam.role_permissions WHERE role = $1 ORDER BY permission;

-- name: ListUserRoles :many
SELECT role FROM iam.user_roles WHERE user_id = $1 ORDER BY role;

-- name: ListUserRolePermissions :many
SELECT DISTINCT rp.permission FROM iam.role_permissions rp
JOIN iam.user_roles ur ON ur.role = rp.role
WHERE ur.user_id = $1;

-- name: DeleteUserRoles :exec
DELETE FROM iam.user_roles WHERE user_id = $1;

-- name: AddUserRole :exec
INSERT INTO iam.user_roles (user_id, role) VALUES ($1, $2) ON CONFLICT DO NOTHING;

-- name: ListUserOverrides :many
SELECT permission, allow, scope_type, scope_id FROM iam.permission_overrides
WHERE user_id = $1 ORDER BY permission, scope_type;

-- name: DeleteUserOverridesAll :exec
DELETE FROM iam.permission_overrides WHERE user_id = $1 AND scope_type = 'all';

-- name: AddUserOverride :exec
INSERT INTO iam.permission_overrides (user_id, permission, allow, scope_type, scope_id, created_by)
VALUES ($1, $2, $3, $4, $5, $6);

-- name: ListUserBranches :many
SELECT ub.branch_id, ub.is_primary, b.code, b.name FROM iam.user_branches ub
JOIN core.branches b ON b.id = ub.branch_id
WHERE ub.user_id = $1 ORDER BY ub.is_primary DESC, b.name;

-- name: DeleteUserBranches :exec
DELETE FROM iam.user_branches WHERE user_id = $1;

-- name: AddUserBranch :exec
INSERT INTO iam.user_branches (user_id, branch_id, is_primary) VALUES ($1, $2, $3);

-- name: ListUsers :many
-- Bộ lọc NULL = không giới hạn. assignable: chỉ người có ít nhất một vai trò và mọi vai trò nằm trong danh sách
-- (cây vai trò); branch_ids: chi nhánh chính thuộc danh sách; created_by: tài khoản do người này tạo.
SELECT u.id, u.kind, u.code, u.email, u.phone, u.full_name, u.status, u.must_change_password,
       u.totp_enabled_at, u.locked_until, u.last_login_at, u.created_at,
       ARRAY(SELECT ur.role FROM iam.user_roles ur WHERE ur.user_id = u.id ORDER BY ur.role)::text[] AS roles,
       pb.branch_id AS primary_branch_id,
       count(*) OVER () AS total
FROM iam.users u
LEFT JOIN iam.user_branches pb ON pb.user_id = u.id AND pb.is_primary
WHERE (sqlc.narg(assignable)::text[] IS NULL OR (
        EXISTS (SELECT 1 FROM iam.user_roles ur WHERE ur.user_id = u.id)
        AND NOT EXISTS (SELECT 1 FROM iam.user_roles ur WHERE ur.user_id = u.id
                        AND NOT (ur.role = ANY (sqlc.narg(assignable)::text[])))))
  AND (sqlc.narg(branch_ids)::uuid[] IS NULL OR EXISTS (
        SELECT 1 FROM iam.user_branches ub WHERE ub.user_id = u.id AND ub.is_primary
        AND ub.branch_id = ANY (sqlc.narg(branch_ids)::uuid[])))
  AND (sqlc.narg(created_by)::uuid IS NULL OR u.created_by = sqlc.narg(created_by)::uuid)
  AND (sqlc.narg(search)::text IS NULL OR u.full_name ILIKE sqlc.narg(search)::text
       OR u.code ILIKE sqlc.narg(search)::text OR u.email ILIKE sqlc.narg(search)::text
       OR u.phone ILIKE sqlc.narg(search)::text)
  AND (sqlc.narg(role)::text IS NULL OR EXISTS (
        SELECT 1 FROM iam.user_roles ur WHERE ur.user_id = u.id AND ur.role = sqlc.narg(role)::text))
  AND (sqlc.narg(status)::text IS NULL OR u.status = sqlc.narg(status)::text)
ORDER BY u.full_name, u.id
LIMIT sqlc.arg(lim) OFFSET sqlc.arg(off);

-- name: UpdateUserProfile :one
UPDATE iam.users SET code = $2, email = $3, phone = $4, full_name = $5, updated_at = now()
WHERE id = $1 RETURNING *;

-- name: SetUserStatus :exec
UPDATE iam.users SET status = $2, updated_at = now() WHERE id = $1;

-- name: SetUserCreatedBy :exec
UPDATE iam.users SET created_by = $2 WHERE id = $1;

-- name: ListBranches :many
SELECT * FROM core.branches ORDER BY is_active DESC, name;

-- name: GetBranch :one
SELECT * FROM core.branches WHERE id = $1;

-- name: CreateBranch :one
INSERT INTO core.branches (code, name, address, phone, created_by) VALUES ($1, $2, $3, $4, $5)
RETURNING *;

-- name: UpdateBranch :one
UPDATE core.branches SET code = $2, name = $3, address = $4, phone = $5, updated_at = now()
WHERE id = $1 RETURNING *;

-- name: SetBranchActive :one
UPDATE core.branches SET is_active = $2, updated_at = now() WHERE id = $1 RETURNING *;

-- name: CountActiveBranches :one
SELECT count(*) FROM core.branches WHERE is_active AND id = ANY (sqlc.arg(ids)::uuid[]);

-- name: UpdateBranchCheckin :one
UPDATE core.branches SET latitude = $2, longitude = $3, checkin_radius = $4, work_start_time = $5, work_end_time = $6,
    late_grace_minutes = $7, updated_at = now()
WHERE id = $1 RETURNING *;

-- name: ListUserIDsWithPermission :many
-- Người dùng đang hoạt động có quyền @perm, cùng thứ tự quyết định với Access.Can (bỏ qua phân quyền theo chi nhánh /
-- lớp). admin_all: Admin được quyền này mặc định; role_allowed: quyền không thuộc nhóm chỉ Admin. branch_id NULL = mọi
-- chi nhánh, ngược lại chỉ người thuộc chi nhánh đó.
SELECT u.id
FROM iam.users u
WHERE u.status = 'active'
  AND (sqlc.narg(branch_id)::uuid IS NULL
       OR EXISTS (SELECT 1 FROM iam.user_branches ub WHERE ub.user_id = u.id AND ub.branch_id = sqlc.narg(branch_id)::uuid))
  AND ((sqlc.arg(admin_all)::boolean
        AND EXISTS (SELECT 1 FROM iam.user_roles ur WHERE ur.user_id = u.id AND ur.role = sqlc.arg(admin_role)::text))
       OR (sqlc.arg(role_allowed)::boolean AND coalesce(
             (SELECT o.allow FROM iam.permission_overrides o
              WHERE o.user_id = u.id AND o.permission = sqlc.arg(perm)::text AND o.scope_type = 'all' LIMIT 1),
             EXISTS (SELECT 1 FROM iam.user_roles ur JOIN iam.role_permissions rp ON rp.role = ur.role
                     WHERE ur.user_id = u.id AND rp.permission = sqlc.arg(perm)::text))))
ORDER BY u.id;

-- name: GetStaffProfile :one
SELECT * FROM iam.staff_profiles WHERE user_id = $1;

-- name: UpsertStaffProfile :one
INSERT INTO iam.staff_profiles (user_id, id_card_number, hometown, current_address, emergency_contact, graduation_school,
    certificates, teaching_level, contract_type, contract_start_date, contract_end_date, base_salary, hourly_rate,
    academic_teaching, updated_by)
VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, $13, $14, $15)
ON CONFLICT (user_id) DO UPDATE SET
    id_card_number = EXCLUDED.id_card_number, hometown = EXCLUDED.hometown, current_address = EXCLUDED.current_address,
    emergency_contact = EXCLUDED.emergency_contact, graduation_school = EXCLUDED.graduation_school,
    certificates = EXCLUDED.certificates, teaching_level = EXCLUDED.teaching_level, contract_type = EXCLUDED.contract_type,
    contract_start_date = EXCLUDED.contract_start_date, contract_end_date = EXCLUDED.contract_end_date,
    base_salary = EXCLUDED.base_salary, hourly_rate = EXCLUDED.hourly_rate, academic_teaching = EXCLUDED.academic_teaching,
    updated_by = EXCLUDED.updated_by, updated_at = now()
RETURNING *;

-- name: GetSlaValue :one
-- Giá trị SLA (bảng của phân hệ danh mục); không có dòng thì dùng mặc định ở code.
SELECT COALESCE(value, default_value)::integer AS value FROM hr.sla_rules WHERE key = $1;
