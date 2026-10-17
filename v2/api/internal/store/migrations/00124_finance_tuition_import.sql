-- Nhập học phí từ Excel: bản xem trước (Laravel giữ trong session "tuition_import.<token>") để bước xác nhận chỉ ghi
-- đúng các dòng người dùng đã xem; mỗi bản chỉ xác nhận một lần.
-- +goose Up
CREATE TABLE finance.tuition_import_batches (
    id           uuid PRIMARY KEY DEFAULT uuidv7(),
    created_by   uuid NOT NULL REFERENCES iam.users (id) ON DELETE CASCADE,
    branch_id    uuid NOT NULL REFERENCES core.branches (id),
    file_name    text NOT NULL CHECK (length(file_name) <= 255),
    -- [{line, student_id, student_code, student_name, class_id, class_code, creates_tuition, total_amount, ..., errors: [...]}]
    rows         jsonb NOT NULL,
    status       text NOT NULL DEFAULT 'preview' CHECK (status IN ('preview', 'committed')),
    -- {tuitions, receipts, skipped, failed: [{line, error}]}
    result       jsonb,
    created_at   timestamptz NOT NULL DEFAULT now(),
    committed_at timestamptz
);
CREATE INDEX tuition_import_batches_owner_idx ON finance.tuition_import_batches (created_by, created_at DESC);

-- +goose Down
DROP TABLE finance.tuition_import_batches;
