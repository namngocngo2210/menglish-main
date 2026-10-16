-- Hồ sơ nhân sự (Laravel: các cột hồ sơ / hợp đồng / lương trên bảng users, form Người dùng tab "Hồ sơ" và
-- "Hợp đồng & Lương"). Tách khỏi iam.users vì chỉ nhân viên có; CCCD, lương cơ bản, thù lao giờ chỉ người xem được
-- mọi phiếu lương đọc / sửa. Tiền là VND nguyên (bigint).
-- +goose Up
CREATE TABLE iam.staff_profiles (
    user_id             uuid PRIMARY KEY REFERENCES iam.users (id) ON DELETE CASCADE,
    id_card_number      text CHECK (char_length(id_card_number) <= 30),
    hometown            text CHECK (char_length(hometown) <= 255),
    current_address     text CHECK (char_length(current_address) <= 255),
    emergency_contact   text CHECK (char_length(emergency_contact) <= 255),
    graduation_school   text CHECK (char_length(graduation_school) <= 255),
    certificates        text CHECK (char_length(certificates) <= 255),
    teaching_level      text CHECK (char_length(teaching_level) <= 255),
    contract_type       text CHECK (char_length(contract_type) <= 50),
    contract_start_date date,
    contract_end_date   date,
    base_salary         bigint NOT NULL DEFAULT 0 CHECK (base_salary >= 0),
    hourly_rate         bigint NOT NULL DEFAULT 0 CHECK (hourly_rate >= 0),
    -- Học thuật kiêm giảng dạy (chỉ có nghĩa khi vai trò chính là Học thuật)
    academic_teaching   boolean NOT NULL DEFAULT false,
    updated_at          timestamptz NOT NULL DEFAULT now(),
    updated_by          uuid REFERENCES iam.users (id) ON DELETE SET NULL
);

-- Cảnh báo hợp đồng sắp hết hạn
CREATE INDEX staff_profiles_contract_end ON iam.staff_profiles (contract_end_date) WHERE contract_end_date IS NOT NULL;

-- +goose Down
DROP TABLE iam.staff_profiles;
