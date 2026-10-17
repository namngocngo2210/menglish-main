-- Sổ khoản chi vận hành (Laravel: operating_expenses — FinanceController, Epic 13). Khoản chi tự nhập lưu ngay, không cần
-- duyệt; chi lương lấy tự động từ bảng lương nên không nằm trong bảng này.
-- +goose Up
CREATE SCHEMA IF NOT EXISTS finance;

CREATE TABLE finance.expenses (
    id             uuid PRIMARY KEY DEFAULT uuidv7(),
    expense_date   date NOT NULL,
    title          text NOT NULL CHECK (length(title) BETWEEN 1 AND 255),
    -- Số tiền (đồng), tối thiểu 1.000đ như form Laravel
    amount         bigint NOT NULL CHECK (amount >= 1000),
    payment_method text NOT NULL DEFAULT 'chuyen_khoan' CHECK (payment_method IN ('chuyen_khoan', 'tien_mat')),
    -- NULL: khoản chi cũ không gắn chi nhánh ("Toàn hệ thống"); khoản mới luôn có chi nhánh
    branch_id      uuid REFERENCES core.branches (id) ON DELETE SET NULL,
    -- mat_bang_tien_ich: Mặt bằng & Tiện ích; giao_trinh_van_hanh: In ấn & Vận hành lớp; khac: Chi phí khác
    category       text NOT NULL DEFAULT 'khac' CHECK (category IN ('mat_bang_tien_ich', 'giao_trinh_van_hanh', 'khac')),
    notes          text CHECK (length(notes) <= 1000),
    created_by     uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    created_at     timestamptz NOT NULL DEFAULT now(),
    updated_at     timestamptz NOT NULL DEFAULT now(),
    deleted_at     timestamptz
);
CREATE INDEX expenses_date_branch_idx ON finance.expenses (expense_date, branch_id) WHERE deleted_at IS NULL;

-- +goose Down
DROP TABLE finance.expenses;
