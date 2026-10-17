-- Nhật ký vận hành (Laravel: bảng activity_log của spatie/laravel-activitylog, màn ActivityLogController).
-- Mỗi thao tác ghi một dòng: ai làm, phân hệ, loại thao tác, đối tượng, mô tả, dữ liệu trước / sau và thông tin request.
-- Ghi bằng audit.Record (internal/audit); không sửa / xóa dòng nào từ ứng dụng.
-- +goose Up
CREATE TABLE core.audit_log (
    id            uuid PRIMARY KEY DEFAULT uuidv7(),
    created_at    timestamptz NOT NULL DEFAULT now(),
    -- NULL = hệ thống tự động (lệnh chạy nền)
    actor_id      uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    -- Phân hệ (log_name của Laravel), vd. 'Người dùng & Phân quyền'
    module        text NOT NULL CHECK (length(module) BETWEEN 1 AND 100),
    -- created | updated | deleted | restored | tên thao tác khác
    event         text NOT NULL CHECK (length(event) BETWEEN 1 AND 50),
    -- Mã thao tác cho máy đọc, vd. 'user.lock' (lọc / thống kê)
    action        text NOT NULL DEFAULT '' CHECK (length(action) <= 100),
    subject_type  text CHECK (length(subject_type) <= 50),
    subject_id    text CHECK (length(subject_id) <= 100),
    subject_label text CHECK (length(subject_label) <= 300),
    -- Chi nhánh của dữ liệu bị tác động (nếu có)
    branch_id     uuid REFERENCES core.branches (id) ON DELETE SET NULL,
    description   text NOT NULL CHECK (length(description) BETWEEN 1 AND 1000),
    -- {"<trường>": {"old": ..., "new": ...}}; giá trị nhạy cảm đã che bằng ***
    changes       jsonb NOT NULL DEFAULT '{}',
    -- Thông tin thêm (vd. quyền thêm / bớt)
    properties    jsonb NOT NULL DEFAULT '{}',
    ip            text,
    user_agent    text,
    method        text,
    path          text,
    -- Gom các dòng ghi trong cùng một request (Transaction ID trên màn chi tiết)
    request_id    uuid
);
CREATE INDEX audit_log_created_idx ON core.audit_log (created_at DESC, id DESC);
CREATE INDEX audit_log_actor_idx ON core.audit_log (actor_id, created_at DESC);
CREATE INDEX audit_log_module_idx ON core.audit_log (module, created_at DESC);
CREATE INDEX audit_log_subject_idx ON core.audit_log (subject_type, subject_id);

-- +goose Down
DROP TABLE core.audit_log;
