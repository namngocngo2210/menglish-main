-- CRM tuyển sinh: khách (lead) 9 giai đoạn, nhật ký (mọi chuyển giai đoạn ghi một dòng), yêu cầu chuyển cơ sở,
-- ưu đãi học phí, xác nhận chính thức nhập học, phiên nhập Excel. Theo crm_customers / crm_customer_histories /
-- crm_branch_transfers / promotions của bản Laravel.
-- Nguồn khách: Laravel đọc danh mục chung system_categories (type lead_source), lý do thất bại là chữ tự do;
-- ở đây giữ text (core.system_categories do phân hệ danh mục làm).
-- +goose Up
CREATE SCHEMA IF NOT EXISTS crm;

CREATE TABLE crm.leads (
    id                   uuid PRIMARY KEY DEFAULT uuidv7(),
    -- KH-<ULID> như Laravel (hiển thị rút gọn KH-<6 ký tự cuối>)
    code                 text NOT NULL UNIQUE CHECK (length(code) BETWEEN 1 AND 40),
    name                 text NOT NULL CHECK (length(name) BETWEEN 1 AND 255),
    phone                text NOT NULL CHECK (length(phone) BETWEEN 1 AND 20),
    -- Số chuẩn hoá (0xxxxxxxxx) để tìm và chặn trùng; khách bị xóa nhả về NULL để tạo lại được
    phone_normalized     text UNIQUE,
    email                citext UNIQUE CHECK (length(email) <= 255),
    -- Email cũ của khách đã xóa (khôi phục thì trả lại nếu chưa ai dùng)
    deleted_email        citext,
    parent_name          text CHECK (length(parent_name) <= 255),
    parent_phone         text CHECK (length(parent_phone) <= 20),
    dob                  date,
    gender               text CHECK (length(gender) <= 20),
    address              text CHECK (length(address) <= 255),
    school               text CHECK (length(school) <= 255),
    branch_id            uuid REFERENCES core.branches (id) ON DELETE SET NULL,
    course_interest      text CHECK (length(course_interest) <= 255),
    source               text CHECK (length(source) <= 255),
    assigned_user_id     uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    stage                text NOT NULL DEFAULT 'new' CHECK (stage IN (
                             'new', 'consulting', 'test_scheduled', 'testing', 'tested', 'result_sent',
                             'waiting_class', 'won', 'lost')),
    -- Giá trị hợp đồng (đồng)
    deal_value           bigint NOT NULL DEFAULT 0 CHECK (deal_value >= 0),
    -- Test đầu vào (phân hệ placement ghi các cột này qua crm.Service)
    test_decision        text CHECK (length(test_decision) <= 20),
    test_score           text CHECK (length(test_score) <= 255),
    appointment_at       timestamptz,
    appointment_type     text CHECK (appointment_type IN ('online', 'offline')),
    assigned_test_id     uuid, -- FK tới placement.tests thêm khi gộp
    examiner_id          uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    -- Hạn liên hệ tiếp theo do người phụ trách hẹn
    next_follow_up_at    timestamptz,
    -- Lần chăm sóc gần nhất (nhật ký loại chăm sóc, trừ liên hệ thất bại); ghi cùng lúc với nhật ký (InsertEvent)
    last_care_at         timestamptz,
    -- Chờ xếp lớp
    waiting_since        date,
    waiting_course_id    uuid, -- FK tới academic.courses thêm khi gộp
    waiting_branch_id    uuid REFERENCES core.branches (id) ON DELETE SET NULL,
    preferred_schedule   text CHECK (length(preferred_schedule) <= 255),
    desired_start_date   date,
    waiting_priority     integer,
    waiting_notes        text CHECK (length(waiting_notes) <= 2000),
    lost_reason          text CHECK (length(lost_reason) <= 255),
    lost_at              timestamptz,
    -- Chốt khách
    converted_student_id uuid, -- FK tới academic.students thêm khi gộp
    converted_by         uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    commission_user_id   uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    converted_at         timestamptz,
    fee_paid_at_closing  boolean NOT NULL DEFAULT false,
    notes                text CHECK (length(notes) <= 1000),
    -- Chăm sóc tháng đầu: {"session_1": {"done_at": "...", "by": "Tên"}, ...}
    care_checklist       jsonb,
    created_by           uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    created_at           timestamptz NOT NULL DEFAULT now(),
    updated_at           timestamptz NOT NULL DEFAULT now(),
    deleted_at           timestamptz
);
CREATE INDEX leads_branch_stage_idx ON crm.leads (branch_id, stage) WHERE deleted_at IS NULL;
CREATE INDEX leads_assigned_idx ON crm.leads (assigned_user_id) WHERE deleted_at IS NULL;
CREATE INDEX leads_stage_created_idx ON crm.leads (stage, created_at) WHERE deleted_at IS NULL;
CREATE INDEX leads_converted_student_idx ON crm.leads (converted_student_id) WHERE converted_student_id IS NOT NULL;

-- Nhật ký khách: liên hệ, ghi chú, chuyển giai đoạn (from/to + lý do), sửa thông tin (changes trước / sau)
CREATE TABLE crm.lead_events (
    id         uuid PRIMARY KEY DEFAULT uuidv7(),
    lead_id    uuid NOT NULL REFERENCES crm.leads (id) ON DELETE CASCADE,
    user_id    uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    type       text NOT NULL CHECK (type IN (
                   'call', 'message', 'meet', 'test', 'result', 'trial', 'stage_change',
                   'update', 'assign', 'care', 'note', 'system')),
    -- Gọi / nhắn / gặp: liên hệ được hay không (không liên hệ được thì không tính là đã chăm sóc)
    outcome    text CHECK (outcome IN ('reached', 'failed')),
    content    text NOT NULL,
    from_stage text,
    to_stage   text,
    reason     text,
    changes    jsonb,
    created_at timestamptz NOT NULL DEFAULT now(),
    CHECK ((type = 'stage_change') = (to_stage IS NOT NULL))
);
CREATE INDEX lead_events_lead_idx ON crm.lead_events (lead_id, created_at DESC);

-- Đổi người phụ trách sang cơ sở khác: chờ Admin (lead.approve_transfer) duyệt
CREATE TABLE crm.branch_transfers (
    id             uuid PRIMARY KEY DEFAULT uuidv7(),
    lead_id        uuid NOT NULL REFERENCES crm.leads (id) ON DELETE CASCADE,
    from_branch_id uuid REFERENCES core.branches (id) ON DELETE SET NULL,
    to_branch_id   uuid NOT NULL REFERENCES core.branches (id),
    from_user_id   uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    to_user_id     uuid NOT NULL REFERENCES iam.users (id),
    requested_by   uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    reason         text CHECK (length(reason) <= 1000),
    status         text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'approved', 'rejected')),
    decided_by     uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    decided_at     timestamptz,
    decision_note  text CHECK (length(decision_note) <= 1000),
    created_at     timestamptz NOT NULL DEFAULT now(),
    updated_at     timestamptz NOT NULL DEFAULT now()
);
-- Mỗi khách tối đa một yêu cầu đang chờ
CREATE UNIQUE INDEX branch_transfers_pending_key ON crm.branch_transfers (lead_id) WHERE status = 'pending';
CREATE INDEX branch_transfers_status_idx ON crm.branch_transfers (status, created_at);

-- Ưu đãi học phí: danh mục dùng lại (is_special = false) và ưu đãi riêng một khách (bắt buộc lý do, 1 lượt)
CREATE TABLE crm.promotions (
    id                  uuid PRIMARY KEY DEFAULT uuidv7(),
    code                text NOT NULL UNIQUE CHECK (length(code) BETWEEN 1 AND 40),
    name                text NOT NULL CHECK (length(name) BETWEEN 1 AND 255),
    type                text NOT NULL CHECK (type IN ('fixed', 'percent')),
    -- fixed: số đồng; percent: phần trăm (tối đa 100)
    value               numeric(15, 2) NOT NULL CHECK (value > 0),
    max_discount_amount bigint CHECK (max_discount_amount >= 0),
    description         text CHECK (length(description) <= 1000),
    reason              text CHECK (length(reason) <= 1000),
    branch_id           uuid REFERENCES core.branches (id) ON DELETE SET NULL,
    course_id           uuid, -- FK tới academic.courses thêm khi gộp
    starts_at           timestamptz,
    ends_at             timestamptz,
    usage_limit         integer CHECK (usage_limit >= 1),
    used_count          integer NOT NULL DEFAULT 0 CHECK (used_count >= 0),
    is_active           boolean NOT NULL DEFAULT true,
    is_default          boolean NOT NULL DEFAULT false,
    is_special          boolean NOT NULL DEFAULT false,
    created_by          uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    created_at          timestamptz NOT NULL DEFAULT now(),
    updated_at          timestamptz NOT NULL DEFAULT now(),
    CHECK (type <> 'percent' OR value <= 100),
    CHECK (ends_at IS NULL OR starts_at IS NULL OR ends_at > starts_at),
    CHECK (NOT (is_special AND is_default))
);

-- Xác nhận chính thức nhập học của học viên chốt từ CRM (Laravel: cột trên class_enrollments).
-- Một dòng cho mỗi lượt ghi danh tạo từ CRM; tên học viên / lớp chép lại lúc ghi danh để hiển thị.
CREATE TABLE crm.enrollment_confirmations (
    id                   uuid PRIMARY KEY DEFAULT uuidv7(),
    lead_id              uuid NOT NULL REFERENCES crm.leads (id) ON DELETE CASCADE,
    enrollment_id        uuid UNIQUE, -- FK tới academic.enrollments thêm khi gộp
    student_id           uuid NOT NULL, -- FK tới academic.students thêm khi gộp
    class_id             uuid NOT NULL, -- FK tới academic.classes thêm khi gộp
    student_code         text NOT NULL,
    student_name         text NOT NULL,
    class_name           text NOT NULL,
    class_code           text,
    class_branch_id      uuid REFERENCES core.branches (id) ON DELETE SET NULL,
    class_start_date     date,
    enrolled_at          timestamptz NOT NULL DEFAULT now(),
    account_sent         boolean NOT NULL DEFAULT false,
    zalo_group_added     boolean NOT NULL DEFAULT false,
    curriculum_delivered boolean NOT NULL DEFAULT false,
    status               text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'completed', 'cancelled')),
    confirmed_at         timestamptz,
    confirmed_by         uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    created_at           timestamptz NOT NULL DEFAULT now(),
    updated_at           timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX enrollment_confirmations_lead_idx ON crm.enrollment_confirmations (lead_id);
CREATE INDEX enrollment_confirmations_pending_idx ON crm.enrollment_confirmations (enrolled_at) WHERE confirmed_at IS NULL;

-- Nhập khách từ Excel: bản xem trước (các dòng + lỗi) giữ tới lúc bấm Nhập / Hủy
CREATE TABLE crm.import_batches (
    id               uuid PRIMARY KEY DEFAULT uuidv7(),
    created_by       uuid NOT NULL REFERENCES iam.users (id) ON DELETE CASCADE,
    branch_id        uuid NOT NULL REFERENCES core.branches (id),
    assigned_user_id uuid NOT NULL REFERENCES iam.users (id),
    file_name        text NOT NULL CHECK (length(file_name) <= 255),
    -- [{line, data: {...}, errors: [...]}]
    rows             jsonb NOT NULL,
    status           text NOT NULL DEFAULT 'preview' CHECK (status IN ('preview', 'committed', 'cancelled')),
    created_count    integer NOT NULL DEFAULT 0,
    skipped          jsonb,
    created_at       timestamptz NOT NULL DEFAULT now(),
    committed_at     timestamptz
);
CREATE INDEX import_batches_owner_idx ON crm.import_batches (created_by, created_at DESC);

-- +goose Down
DROP TABLE crm.import_batches;
DROP TABLE crm.enrollment_confirmations;
DROP TABLE crm.promotions;
DROP TABLE crm.branch_transfers;
DROP TABLE crm.lead_events;
DROP TABLE crm.leads;
