-- Cấu hình webhook SePay đủ như sepay_configurations của Laravel: URL webhook khai trên SePay, định dạng dữ liệu,
-- tự gửi lại khi lỗi.
-- +goose Up
ALTER TABLE finance.payment_providers
    ADD COLUMN webhook_url text CHECK (length(webhook_url) <= 500),
    ADD COLUMN data_format text NOT NULL DEFAULT 'json' CHECK (data_format IN ('json', 'form', 'urlencoded')),
    ADD COLUMN auto_retry  boolean NOT NULL DEFAULT true;

-- +goose Down
ALTER TABLE finance.payment_providers DROP COLUMN auto_retry, DROP COLUMN data_format, DROP COLUMN webhook_url;
