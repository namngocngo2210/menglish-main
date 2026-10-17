-- Kho file chung (Laravel: file rải ở storage/app/private, storage/app/public, public_html/uploads; mỗi bảng tự giữ
-- đường dẫn). v2: nội dung nằm trong kho riêng tư (thư mục local hoặc R2), bảng này giữ thông tin file và chủ sở hữu:
-- - owner_module / subject_type / subject_id: phân hệ sở hữu và bản ghi gắn file (vd. iam / staff_contract / id
--   nhân sự). Quyền tải do phân hệ sở hữu quyết định theo subject_type (internal/files: RegisterReader).
-- - storage_key: tên đối tượng ngẫu nhiên trong kho; tên gốc chỉ để hiển thị / đặt tên khi tải về.
-- - Xóa mềm (deleted_at); nội dung được dọn hẳn sau 30 ngày.
-- +goose Up
CREATE TABLE core.files (
    id            uuid PRIMARY KEY DEFAULT uuidv7(),
    owner_module  text NOT NULL CHECK (owner_module ~ '^[a-z][a-z0-9_]{0,49}$'),
    subject_type  text NOT NULL CHECK (subject_type ~ '^[a-z][a-z0-9_]{0,49}$'),
    -- Bản ghi gắn file; NULL khi file tải lên trước khi có bản ghi (vd. file nghe trên form soạn đề mới)
    subject_id    uuid,
    branch_id     uuid REFERENCES core.branches (id) ON DELETE SET NULL,
    storage_key   text NOT NULL UNIQUE CHECK (length(storage_key) BETWEEN 1 AND 200),
    original_name text NOT NULL CHECK (length(original_name) BETWEEN 1 AND 255),
    -- Loại nhận theo nội dung file (không theo đuôi trình duyệt gửi)
    extension     text NOT NULL CHECK (extension ~ '^[a-z0-9]{1,8}$'),
    mime_type     text NOT NULL CHECK (length(mime_type) BETWEEN 1 AND 150),
    size_bytes    bigint NOT NULL CHECK (size_bytes >= 0),
    sha256        bytea NOT NULL CHECK (length(sha256) = 32),
    -- Đường dẫn file ở Laravel khi nhập dữ liệu cũ (vd. contracts/12/2026….pdf trên disk local)
    legacy_path   text CHECK (length(legacy_path) <= 500),
    uploaded_by   uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    created_at    timestamptz NOT NULL DEFAULT now(),
    deleted_at    timestamptz,
    deleted_by    uuid REFERENCES iam.users (id) ON DELETE SET NULL
);

-- File đang dùng của một bản ghi (vd. hợp đồng hiện tại của nhân sự)
CREATE INDEX files_subject_idx ON core.files (subject_type, subject_id, created_at DESC) WHERE deleted_at IS NULL;
-- Dọn nội dung file đã xóa mềm quá 30 ngày
CREATE INDEX files_deleted_idx ON core.files (deleted_at) WHERE deleted_at IS NOT NULL;

-- +goose Down
DROP TABLE core.files;
