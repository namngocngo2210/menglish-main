-- Khung giờ ca dạy, luật SLA và cấu hình hệ thống dạng khóa – giá trị (Laravel: teaching_shifts, config/sla.php +
-- sla_settings, system_settings).
-- +goose Up
CREATE SCHEMA IF NOT EXISTS hr;

-- Mỗi khung có giờ chuẩn và (tùy chọn) giờ lệch; mỗi ca dạy 90 phút (kiểm ở API).
CREATE TABLE hr.teaching_shifts (
    id             uuid PRIMARY KEY DEFAULT uuidv7(),
    day_type       text NOT NULL CHECK (day_type IN ('weekday', 'saturday', 'sunday')),
    name           text NOT NULL CHECK (length(name) BETWEEN 1 AND 50),
    start_time     time NOT NULL,
    end_time       time NOT NULL CHECK (end_time > start_time),
    alt_start_time time,
    alt_end_time   time,
    note           text CHECK (length(note) <= 255),
    sort_order     integer NOT NULL DEFAULT 0,
    -- Ngừng dùng: không chọn được khi xếp TKB, buổi đã sinh giữ nguyên
    is_active      boolean NOT NULL DEFAULT true,
    created_by     uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    created_at     timestamptz NOT NULL DEFAULT now(),
    updated_at     timestamptz NOT NULL DEFAULT now(),
    deleted_at     timestamptz,
    CHECK ((alt_start_time IS NULL) = (alt_end_time IS NULL)),
    CHECK (alt_end_time IS NULL OR alt_end_time > alt_start_time)
);
CREATE INDEX teaching_shifts_day_idx ON hr.teaching_shifts (day_type, is_active) WHERE deleted_at IS NULL;

-- Khung giờ theo file "Khung giờ chấm công ME" (07/10/2026), như migration Laravel create_teaching_shifts_table.
INSERT INTO hr.teaching_shifts (day_type, name, start_time, end_time, alt_start_time, alt_end_time, note, sort_order) VALUES
    ('weekday', 'Ca 1', '18:00', '19:30', '18:10', '19:40', 'Một số lớp lệch 10 phút', 1),
    ('weekday', 'Ca 2', '19:30', '21:00', '19:40', '21:10', 'Một số lớp lệch 10 phút', 2),
    ('saturday', 'Ca chiều', '15:00', '16:30', '15:15', '16:45', 'Có lớp lệch 15 phút', 3),
    ('saturday', 'Ca chiều', '16:00', '17:30', '16:15', '17:45', 'Có lớp lệch 15 phút', 4),
    ('saturday', 'Ca chiều', '17:00', '18:30', '17:30', '19:00', 'Có lớp lệch 30 phút', 5),
    ('saturday', 'Ca chiều/tối', '18:00', '19:30', '18:30', '20:00', 'Có lớp lệch 30 phút', 6),
    ('sunday', 'Ca chiều', '15:00', '16:30', NULL, NULL, NULL, 7),
    ('sunday', 'Ca chiều', '16:00', '17:30', '16:15', '17:45', 'Có lớp lệch 15 phút', 8),
    ('sunday', 'Ca chiều', '16:30', '18:00', '16:45', '18:15', 'Có lớp lệch 15 phút', 9),
    ('sunday', 'Ca chiều', '18:00', '19:30', NULL, NULL, NULL, 10),
    ('sunday', 'Ca tối', '18:30', '20:00', NULL, NULL, NULL, 11);

-- Luật SLA: cột default_* đồng bộ từ code (catalogs.SyncSLA, sau mỗi lần migrate, nguồn là config/sla.php của Laravel);
-- cột không có tiền tố là giá trị Admin đổi trên trang "Cấu hình SLA", NULL = dùng mặc định.
CREATE TABLE hr.sla_rules (
    key             text PRIMARY KEY CHECK (key ~ '^[a-z_]+\.[a-z_]+$'),
    group_key       text NOT NULL CHECK (group_key IN ('crm', 'teacher', 'big_test', 'academic', 'operations')),
    label           text NOT NULL,
    description     text NOT NULL,
    -- hours | days | count | time (phút từ 00:00) | day_of_month (1–28)
    unit            text NOT NULL CHECK (unit IN ('hours', 'days', 'count', 'time', 'day_of_month')),
    default_value   integer NOT NULL,
    -- NULL: SLA chỉ nhắc / chặn thao tác, không lập biên bản
    default_penalty boolean,
    default_amount  bigint NOT NULL DEFAULT 0 CHECK (default_amount >= 0),
    -- Bậc phạt theo lần tái phạm (mảng số tiền, phần tử cuối lặp lại)
    default_ladder  jsonb CHECK (default_ladder IS NULL OR jsonb_typeof(default_ladder) = 'array'),
    amount_label    text,
    amount_per      text,
    task            boolean NOT NULL DEFAULT false,
    -- false: luôn áp dụng, không có nút tắt
    switchable      boolean NOT NULL DEFAULT true,
    -- Loại lỗi của biên bản (quyết định ai chốt)
    category        text NOT NULL DEFAULT 'operations',
    violation       text NOT NULL,
    sort            integer NOT NULL DEFAULT 0,
    value           integer,
    enabled         boolean,
    penalty         boolean,
    amount          bigint CHECK (amount >= 0),
    ladder          jsonb CHECK (ladder IS NULL OR jsonb_typeof(ladder) = 'array'),
    updated_by      uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    updated_at      timestamptz
);

-- Cấu hình hệ thống dạng khóa – giá trị (system_settings của Laravel). Giá trị bí mật (mật khẩu SMTP) nằm ở secret,
-- mã hóa AES-256-GCM bằng APP_ENCRYPTION_KEY, không bao giờ trả về giao diện.
CREATE TABLE core.settings (
    key         text PRIMARY KEY CHECK (key ~ '^[a-z0-9_.]{1,100}$'),
    value       jsonb NOT NULL DEFAULT 'null',
    secret      bytea,
    description text,
    updated_by  uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    updated_at  timestamptz NOT NULL DEFAULT now()
);

-- +goose Down
DROP TABLE core.settings;
DROP TABLE hr.sla_rules;
DROP TABLE hr.teaching_shifts;
