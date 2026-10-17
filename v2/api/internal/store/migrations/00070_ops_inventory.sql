-- Kho vật phẩm: danh mục hàng hóa (sách, đồng phục...), tồn theo chi nhánh và nhật ký xuất nhập kho.
-- Bản Laravel: merchandise_items, merchandise_stocks, merchandise_stock_movements.
-- Tồn chi nhánh được âm như Laravel (đã giao sách theo phiếu thu khi kho chưa nhập), nhưng thao tác tay
-- (xuất kho, chuyển kho) không được làm âm; kiểm ở service trong giao dịch có khóa dòng.
-- +goose Up
CREATE SCHEMA IF NOT EXISTS ops;

CREATE TABLE ops.inventory_items (
    id                   uuid PRIMARY KEY DEFAULT uuidv7(),
    code                 citext NOT NULL CHECK (length(code) BETWEEN 1 AND 50),
    name                 text NOT NULL CHECK (length(name) BETWEEN 1 AND 255),
    category             text NOT NULL DEFAULT 'book'
        CHECK (category IN ('book', 'workbook', 'uniform', 'backpack', 'equipment', 'gift', 'other')),
    unit                 text NOT NULL DEFAULT 'Bộ' CHECK (length(unit) BETWEEN 1 AND 50),
    -- Đơn giá niêm yết (tính vào hợp đồng / phiếu thu) và giá vốn, đồng
    price                bigint NOT NULL DEFAULT 0 CHECK (price >= 0),
    cost_price           bigint CHECK (cost_price >= 0),
    -- Tồn cũ chưa phân chi nhánh (merchandise_items.stock_quantity của Laravel), phân bổ dần khi nhập kho
    unallocated_quantity integer NOT NULL DEFAULT 0 CHECK (unallocated_quantity >= 0),
    is_active            boolean NOT NULL DEFAULT true,
    description          text CHECK (length(description) <= 1000),
    -- id bên Laravel, dùng cho import-legacy
    legacy_id            bigint UNIQUE,
    created_by           uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    created_at           timestamptz NOT NULL DEFAULT now(),
    updated_at           timestamptz NOT NULL DEFAULT now(),
    deleted_at           timestamptz
);
CREATE UNIQUE INDEX inventory_items_code_key ON ops.inventory_items (code) WHERE deleted_at IS NULL;
CREATE INDEX inventory_items_category_idx ON ops.inventory_items (category, name) WHERE deleted_at IS NULL;

-- Số tồn của một mặt hàng tại một chi nhánh; chỉ đổi cùng một dòng stock_movements trong cùng giao dịch.
CREATE TABLE ops.stock (
    branch_id  uuid NOT NULL REFERENCES core.branches (id),
    item_id    uuid NOT NULL REFERENCES ops.inventory_items (id),
    quantity   integer NOT NULL DEFAULT 0,
    updated_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (branch_id, item_id)
);
CREATE INDEX stock_item_idx ON ops.stock (item_id);

-- Nhật ký xuất nhập: không sửa, không xóa; sai thì ghi thêm một dòng điều chỉnh (kiểm kê).
-- opening: tồn ban đầu | import: nhập kho | count: kiểm kê điều chỉnh | sale: xuất theo phiếu thu |
-- return: hoàn kho khi hủy hóa đơn | issue: xuất kho tay (cấp cho học viên, dùng cho lớp...) |
-- transfer_out / transfer_in: chuyển kho giữa hai chi nhánh (cùng transfer_id)
CREATE TABLE ops.stock_movements (
    id                    uuid PRIMARY KEY DEFAULT uuidv7(),
    branch_id             uuid NOT NULL REFERENCES core.branches (id),
    item_id               uuid NOT NULL REFERENCES ops.inventory_items (id),
    type                  text NOT NULL
        CHECK (type IN ('opening', 'import', 'count', 'sale', 'return', 'issue', 'transfer_out', 'transfer_in')),
    -- Xuất bán: contract (sách trong hợp đồng học phí) | surcharge (hàng ở phần phụ thu của phiếu)
    source                text CHECK (source IN ('contract', 'surcharge')),
    quantity_change       integer NOT NULL CHECK (quantity_change <> 0),
    balance_after         integer NOT NULL,
    transfer_id           uuid,
    counterpart_branch_id uuid REFERENCES core.branches (id),
    receipt_id            uuid, -- FK tới finance.receipts thêm khi gộp
    tuition_id            uuid, -- FK tới finance.tuitions thêm khi gộp
    student_id            uuid, -- FK tới academic.students: 00110_academic_links_fk
    -- Số chứng từ hiển thị (số phiếu thu...), ghi lúc xuất để không phụ thuộc module tài chính
    document_number       text CHECK (length(document_number) <= 50),
    note                  text CHECK (length(note) <= 500),
    created_by            uuid REFERENCES iam.users (id),
    created_at            timestamptz NOT NULL DEFAULT now(),
    CHECK ((type IN ('transfer_out', 'transfer_in')) = (transfer_id IS NOT NULL AND counterpart_branch_id IS NOT NULL)),
    CHECK (type NOT IN ('import', 'opening', 'return', 'transfer_in') OR quantity_change > 0),
    CHECK (type NOT IN ('sale', 'issue', 'transfer_out') OR quantity_change < 0)
);
CREATE INDEX stock_movements_branch_time_idx ON ops.stock_movements (branch_id, created_at DESC, id DESC);
CREATE INDEX stock_movements_branch_item_time_idx ON ops.stock_movements (branch_id, item_id, created_at DESC, id DESC);
CREATE INDEX stock_movements_receipt_idx ON ops.stock_movements (receipt_id) WHERE receipt_id IS NOT NULL;
CREATE INDEX stock_movements_tuition_idx ON ops.stock_movements (tuition_id) WHERE tuition_id IS NOT NULL;
CREATE INDEX stock_movements_student_idx ON ops.stock_movements (student_id) WHERE student_id IS NOT NULL;

-- +goose StatementBegin
CREATE FUNCTION ops.stock_movements_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'ops.stock_movements không sửa / xóa được; hãy ghi một dòng điều chỉnh';
END;
$$;
-- +goose StatementEnd
CREATE TRIGGER stock_movements_immutable BEFORE UPDATE OR DELETE ON ops.stock_movements
    FOR EACH ROW EXECUTE FUNCTION ops.stock_movements_immutable();

-- +goose Down
DROP TABLE ops.stock_movements;
DROP FUNCTION ops.stock_movements_immutable();
DROP TABLE ops.stock;
DROP TABLE ops.inventory_items;
