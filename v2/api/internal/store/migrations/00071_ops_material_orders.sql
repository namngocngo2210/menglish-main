-- Order học liệu: giáo viên đặt đạo cụ / in ấn / học liệu GVNN / học liệu học thuật; Học vụ (theo chi nhánh) hoặc
-- Trưởng Học thuật xử lý trước hạn due_at (tính theo loại + ngày sử dụng). Bản Laravel: material_orders.
-- +goose Up
CREATE SCHEMA IF NOT EXISTS ops;

CREATE TABLE ops.material_orders (
    id             uuid PRIMARY KEY DEFAULT uuidv7(),
    -- OH-2026-0001
    code           text NOT NULL UNIQUE CHECK (code ~ '^OH-[0-9]{4}-[0-9]{4,}$'),
    branch_id      uuid NOT NULL REFERENCES core.branches (id),
    class_id       uuid, -- FK tới academic.classes: 00081_academic_enrollments_attendance
    work_task_id   uuid, -- FK tới ops.work_tasks thêm khi gộp (order tạo từ đầu việc)
    requester_id   uuid NOT NULL REFERENCES iam.users (id),
    category       text NOT NULL CHECK (category IN ('props', 'printing', 'foreign_teacher', 'academic')),
    title          text NOT NULL CHECK (length(title) BETWEEN 1 AND 255),
    description    text CHECK (length(description) <= 2000),
    quantity       integer CHECK (quantity BETWEEN 1 AND 100000),
    use_date       date NOT NULL,
    due_at         timestamptz NOT NULL,
    status         text NOT NULL DEFAULT 'pending'
        CHECK (status IN ('pending', 'processing', 'done', 'rejected', 'overdue')),
    -- Tạo khi đã quá hạn: vẫn nhận, chỉ cảnh báo
    created_late   boolean NOT NULL DEFAULT false,
    processed_by   uuid REFERENCES iam.users (id),
    processed_at   timestamptz,
    -- Hoàn thành / từ chối sau hạn (kể cả khi đã "Quá hạn")
    processed_late boolean NOT NULL DEFAULT false,
    processor_note text CHECK (length(processor_note) <= 2000),
    reject_reason  text CHECK (length(reject_reason) <= 2000),
    legacy_id      bigint UNIQUE,
    created_at     timestamptz NOT NULL DEFAULT now(),
    updated_at     timestamptz NOT NULL DEFAULT now(),
    deleted_at     timestamptz,
    CHECK (status <> 'rejected' OR reject_reason IS NOT NULL)
);
CREATE INDEX material_orders_branch_status_idx ON ops.material_orders (branch_id, status) WHERE deleted_at IS NULL;
CREATE INDEX material_orders_requester_idx ON ops.material_orders (requester_id) WHERE deleted_at IS NULL;
CREATE INDEX material_orders_open_due_idx ON ops.material_orders (due_at)
    WHERE status IN ('pending', 'processing') AND deleted_at IS NULL;

-- Số thứ tự mã order theo năm (UPDATE ... RETURNING, không trùng khi tạo đồng thời).
CREATE TABLE ops.material_order_counters (
    year        smallint PRIMARY KEY,
    last_number integer NOT NULL CHECK (last_number > 0)
);

-- +goose Down
DROP TABLE ops.material_order_counters;
DROP TABLE ops.material_orders;
