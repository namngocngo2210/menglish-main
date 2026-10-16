-- Nhập dữ liệu từ Laravel (menglish import-legacy): bảng nối id cũ (bigint của MySQL) với uuid mới, để chạy lại nhiều
-- lần mà không tạo trùng (mỗi lần cập nhật bản ghi đã nhập) và để bảng sau tìm khóa ngoại của bảng trước.
-- +goose Up
CREATE SCHEMA IF NOT EXISTS legacy;

CREATE TABLE legacy.id_map (
    entity      text NOT NULL,
    legacy_id   bigint NOT NULL,
    new_id      uuid NOT NULL,
    imported_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (entity, legacy_id),
    UNIQUE (entity, new_id)
);

CREATE TABLE legacy.runs (
    id          uuid PRIMARY KEY DEFAULT uuidv7(),
    started_at  timestamptz NOT NULL DEFAULT now(),
    finished_at timestamptz,
    dry_run     boolean NOT NULL,
    report      jsonb
);

-- +goose Down
DROP TABLE legacy.runs;
DROP TABLE legacy.id_map;
DROP SCHEMA legacy;
