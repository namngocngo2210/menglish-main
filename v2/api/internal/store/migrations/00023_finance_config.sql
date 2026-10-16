-- Cấu hình học phí: tài khoản ngân hàng nhận tiền (VietQR) và mốc nhắc nợ (Laravel: bank_accounts, debt_reminder_rules).
-- +goose Up
CREATE SCHEMA IF NOT EXISTS finance;

CREATE TABLE finance.bank_accounts (
    id                uuid PRIMARY KEY DEFAULT uuidv7(),
    -- company: tài khoản công ty; other: cá nhân / đại diện
    account_type      text NOT NULL DEFAULT 'company' CHECK (account_type IN ('company', 'other')),
    bank_code         text NOT NULL CHECK (length(bank_code) BETWEEN 1 AND 20),
    bank_name         text NOT NULL CHECK (length(bank_name) BETWEEN 1 AND 255),
    account_number    text NOT NULL CHECK (length(account_number) BETWEEN 1 AND 50),
    account_holder    text NOT NULL CHECK (length(account_holder) BETWEEN 1 AND 255),
    branch_location   text CHECK (length(branch_location) <= 255),
    -- Chi nhánh dùng tài khoản này (NULL: toàn hệ thống)
    branch_id         uuid REFERENCES core.branches (id) ON DELETE SET NULL,
    is_default_vietqr boolean NOT NULL DEFAULT false,
    is_active         boolean NOT NULL DEFAULT true,
    created_by        uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    created_at        timestamptz NOT NULL DEFAULT now(),
    updated_at        timestamptz NOT NULL DEFAULT now(),
    deleted_at        timestamptz,
    -- Tài khoản mặc định luôn đang dùng
    CHECK (NOT is_default_vietqr OR is_active)
);
-- Nhiều nhất một tài khoản mặc định VietQR
CREATE UNIQUE INDEX bank_accounts_default_uq ON finance.bank_accounts ((true)) WHERE is_default_vietqr AND deleted_at IS NULL;

-- Mốc nhắc nợ: gửi khi hôm nay = hạn đóng + offset_days (âm = trước hạn, 0 = đúng hạn, dương = quá hạn).
-- Bảng trống thì lệnh nhắc nợ dùng mốc mặc định T-7, T-3, T0, T+3.
CREATE TABLE finance.debt_reminder_rules (
    id               uuid PRIMARY KEY DEFAULT uuidv7(),
    milestone_key    text NOT NULL UNIQUE CHECK (length(milestone_key) BETWEEN 1 AND 30),
    offset_days      integer NOT NULL CHECK (offset_days BETWEEN -366 AND 366),
    title            text NOT NULL CHECK (length(title) BETWEEN 1 AND 255),
    template_content text NOT NULL CHECK (length(template_content) BETWEEN 1 AND 2000),
    channels         text[] NOT NULL DEFAULT '{portal,email}' CHECK (channels <@ ARRAY['portal', 'email']::text[]),
    is_enabled       boolean NOT NULL DEFAULT true,
    created_by       uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    created_at       timestamptz NOT NULL DEFAULT now(),
    updated_at       timestamptz NOT NULL DEFAULT now()
);

-- +goose Down
DROP TABLE finance.debt_reminder_rules;
DROP TABLE finance.bank_accounts;
