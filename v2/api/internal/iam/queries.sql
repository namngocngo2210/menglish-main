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
