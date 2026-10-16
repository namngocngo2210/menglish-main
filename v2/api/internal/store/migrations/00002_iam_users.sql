-- Tài khoản đăng nhập (nhân sự, học viên, phụ huynh) và mã dự phòng 2FA.
-- +goose Up
CREATE TABLE iam.users (
    id                   uuid PRIMARY KEY DEFAULT uuidv7(),
    kind                 text NOT NULL CHECK (kind IN ('staff', 'student', 'guardian')),
    -- Mã đăng nhập: MES001 (nhân sự), HV-00001 (học viên)
    code                 citext UNIQUE,
    email                citext UNIQUE,
    -- Chỉ số, dạng 0xxxxxxxxx. Phụ huynh đăng nhập bằng SĐT nên SĐT phụ huynh là duy nhất.
    phone                text CHECK (phone ~ '^0[0-9]{8,10}$'),
    full_name            text NOT NULL CHECK (length(full_name) BETWEEN 1 AND 200),
    password_hash        text NOT NULL,
    must_change_password boolean NOT NULL DEFAULT true,
    status               text NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'disabled')),
    -- Sai mật khẩu liên tiếp; đủ 10 lần thì khóa tạm tới locked_until
    failed_logins        integer NOT NULL DEFAULT 0,
    locked_until         timestamptz,
    -- Khóa bí mật TOTP đã mã hóa (AES-256-GCM, APP_ENCRYPTION_KEY)
    totp_secret          bytea,
    totp_enabled_at      timestamptz,
    password_changed_at  timestamptz,
    last_login_at        timestamptz,
    created_at           timestamptz NOT NULL DEFAULT now(),
    updated_at           timestamptz NOT NULL DEFAULT now(),
    CHECK (code IS NOT NULL OR email IS NOT NULL OR phone IS NOT NULL),
    CHECK ((totp_enabled_at IS NULL) = (totp_secret IS NULL))
);
CREATE UNIQUE INDEX users_guardian_phone_key ON iam.users (phone) WHERE kind = 'guardian';

CREATE TABLE iam.totp_recovery_codes (
    user_id   uuid NOT NULL REFERENCES iam.users (id) ON DELETE CASCADE,
    -- SHA-256 của mã; mã gốc chỉ hiện một lần lúc bật 2FA
    code_hash bytea NOT NULL,
    used_at   timestamptz,
    PRIMARY KEY (user_id, code_hash)
);

-- +goose Down
DROP TABLE iam.totp_recovery_codes;
DROP TABLE iam.users;
