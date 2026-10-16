-- Phòng học theo chi nhánh, loại phòng, ngày nghỉ lễ và danh mục hệ thống (Laravel: rooms, room_types, holidays,
-- holiday_branches, system_categories).
-- +goose Up
CREATE TABLE core.room_types (
    id         uuid PRIMARY KEY DEFAULT uuidv7(),
    name       citext NOT NULL CHECK (length(name) BETWEEN 1 AND 100),
    -- Ngừng dùng: không chọn được cho phòng mới, phòng cũ giữ nguyên loại
    is_active  boolean NOT NULL DEFAULT true,
    created_by uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    deleted_at timestamptz
);
CREATE UNIQUE INDEX room_types_name_uq ON core.room_types (name) WHERE deleted_at IS NULL;

CREATE TABLE core.rooms (
    id           uuid PRIMARY KEY DEFAULT uuidv7(),
    branch_id    uuid NOT NULL REFERENCES core.branches (id),
    room_type_id uuid NOT NULL REFERENCES core.room_types (id),
    -- Tên duy nhất trong một chi nhánh (lớp và buổi học tra phòng theo chi nhánh + tên)
    name         citext NOT NULL CHECK (length(name) BETWEEN 1 AND 100),
    capacity     integer CHECK (capacity BETWEEN 1 AND 999),
    description  text CHECK (length(description) <= 2000),
    created_by   uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    created_at   timestamptz NOT NULL DEFAULT now(),
    updated_at   timestamptz NOT NULL DEFAULT now(),
    deleted_at   timestamptz
);
CREATE UNIQUE INDEX rooms_branch_name_uq ON core.rooms (branch_id, name) WHERE deleted_at IS NULL;
CREATE INDEX rooms_type_idx ON core.rooms (room_type_id) WHERE deleted_at IS NULL;

CREATE TABLE core.holidays (
    id             uuid PRIMARY KEY DEFAULT uuidv7(),
    -- HOL-2026-001 (tự sinh theo năm của ngày bắt đầu) hoặc mã tự nhập
    code           citext NOT NULL UNIQUE CHECK (length(code) BETWEEN 1 AND 50),
    name           text NOT NULL CHECK (length(name) BETWEEN 1 AND 255),
    start_date     date NOT NULL,
    end_date       date NOT NULL,
    -- true: áp dụng mọi chi nhánh; false: chỉ các chi nhánh trong holiday_branches
    is_system_wide boolean NOT NULL DEFAULT true,
    created_by     uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    created_at     timestamptz NOT NULL DEFAULT now(),
    updated_at     timestamptz NOT NULL DEFAULT now(),
    deleted_at     timestamptz,
    CHECK (end_date >= start_date)
);
CREATE INDEX holidays_dates_idx ON core.holidays (start_date, end_date) WHERE deleted_at IS NULL;

CREATE TABLE core.holiday_branches (
    holiday_id uuid NOT NULL REFERENCES core.holidays (id) ON DELETE CASCADE,
    branch_id  uuid NOT NULL REFERENCES core.branches (id) ON DELETE CASCADE,
    PRIMARY KEY (holiday_id, branch_id)
);
CREATE INDEX holiday_branches_branch_idx ON core.holiday_branches (branch_id);

-- Danh mục dùng chung theo nhóm (tab của màn "Danh mục hệ thống"). Ngừng dùng = is_active false, không xóa.
CREATE TABLE core.system_categories (
    id         uuid PRIMARY KEY DEFAULT uuidv7(),
    type       text NOT NULL CHECK (type IN ('lead_source', 'lost_reason', 'position', 'fine_level')),
    code       citext NOT NULL CHECK (length(code) BETWEEN 1 AND 50),
    name       text NOT NULL CHECK (length(name) BETWEEN 1 AND 255),
    sort_order integer NOT NULL DEFAULT 0 CHECK (sort_order >= 0),
    is_active  boolean NOT NULL DEFAULT true,
    created_by uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    deleted_at timestamptz,
    UNIQUE (type, code)
);
CREATE INDEX system_categories_type_idx ON core.system_categories (type, is_active, sort_order);

-- Dữ liệu tham chiếu ban đầu (SystemCategorySeeder của Laravel); chạy lại không ghi đè dữ liệu đã sửa.
INSERT INTO core.system_categories (type, code, name, sort_order) VALUES
    ('lead_source', 'SRC_01', 'Facebook Ads', 1),
    ('lead_source', 'SRC_02', 'TikTok Organic', 2),
    ('lead_source', 'SRC_03', 'Giới thiệu (Referral)', 3),
    ('lead_source', 'SRC_04', 'Google Search', 4),
    ('lead_source', 'SRC_05', 'Youtube Review', 5),
    ('lead_source', 'SRC_06', 'Landing page', 6),
    ('lead_source', 'SRC_07', 'Vãng lai (Walk-in)', 7),
    ('lost_reason', 'LOST_01', 'Học phí không phù hợp ngân sách', 1),
    ('lost_reason', 'LOST_02', 'Vị trí / lịch học không thuận tiện', 2),
    ('lost_reason', 'LOST_03', 'Đã đăng ký trung tâm khác', 3),
    ('lost_reason', 'LOST_04', 'Không phản hồi / mất liên lạc', 4),
    ('lost_reason', 'LOST_05', 'Đổi ý không đăng ký', 5),
    ('position', 'POS_01', 'Admin Hệ thống', 1),
    ('position', 'POS_02', 'Quản lý cấp cao', 2),
    ('position', 'POS_03', 'Tư vấn viên', 3),
    ('position', 'POS_04', 'Học vụ', 4),
    ('position', 'POS_05', 'Kế toán', 5),
    ('position', 'POS_06', 'Giáo viên Tiếng Anh', 6),
    ('position', 'POS_07', 'Giáo viên nước ngoài', 7),
    ('position', 'POS_08', 'Trợ giảng', 8),
    ('fine_level', 'FINE_01', 'Nhẹ - 100.000đ', 1),
    ('fine_level', 'FINE_02', 'Trung bình - 200.000đ', 2),
    ('fine_level', 'FINE_03', 'Nặng - 500.000đ', 3)
ON CONFLICT (type, code) DO NOTHING;

-- +goose Down
DROP TABLE core.system_categories;
DROP TABLE core.holiday_branches;
DROP TABLE core.holidays;
DROP TABLE core.rooms;
DROP TABLE core.room_types;
