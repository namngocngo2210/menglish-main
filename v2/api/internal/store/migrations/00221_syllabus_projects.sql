-- Dự án học thuật — soạn sách, xây chương trình (Laravel: academic_projects, academic_project_members,
-- academic_project_milestones, academic_project_updates). Schema syllabus theo tài liệu thiết kế; phân hệ Giáo trình
-- dùng chung schema nên Down chỉ xóa bảng của dự án.
-- +goose Up
CREATE SCHEMA IF NOT EXISTS syllabus;

CREATE TABLE syllabus.projects (
    id             uuid PRIMARY KEY DEFAULT uuidv7(),
    -- Mã DA-YYYY-001 (DocumentCodeGenerator::academicProjectCode), kể cả dự án đã xóa không dùng lại
    code           text NOT NULL UNIQUE CHECK (length(code) BETWEEN 1 AND 20),
    name           text NOT NULL CHECK (length(name) BETWEEN 1 AND 255),
    type           text NOT NULL DEFAULT 'book' CHECK (type IN ('book', 'curriculum', 'other')),
    description    text CHECK (length(description) <= 5000),
    owner_id       uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    start_date     date,
    deadline       date CHECK (deadline >= start_date),
    -- planning → (chốt tiến độ) active ⇄ paused → completed; cancelled từ mọi trạng thái còn mở
    status         text NOT NULL DEFAULT 'planning' CHECK (status IN ('planning', 'active', 'paused', 'completed', 'cancelled')),
    kickoff_notes  text CHECK (length(kickoff_notes) <= 10000),
    kickoff_link   text CHECK (length(kickoff_link) <= 500),
    plan_locked_at timestamptz,
    plan_locked_by uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    completed_at   timestamptz,
    created_by     uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    created_at     timestamptz NOT NULL DEFAULT now(),
    updated_at     timestamptz NOT NULL DEFAULT now(),
    deleted_at     timestamptz
);
CREATE INDEX projects_status_idx ON syllabus.projects (status) WHERE deleted_at IS NULL;
CREATE INDEX projects_owner_idx ON syllabus.projects (owner_id) WHERE deleted_at IS NULL;

CREATE TABLE syllabus.project_members (
    project_id uuid NOT NULL REFERENCES syllabus.projects (id) ON DELETE CASCADE,
    user_id    uuid NOT NULL REFERENCES iam.users (id) ON DELETE CASCADE,
    created_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (project_id, user_id)
);
CREATE INDEX project_members_user_idx ON syllabus.project_members (user_id);

-- Mốc tiến độ: hạn 23:59 ngày due_date, khối lượng cần làm / đã xong (lần cập nhật gần nhất), người nhận.
CREATE TABLE syllabus.milestones (
    id              uuid PRIMARY KEY DEFAULT uuidv7(),
    project_id      uuid NOT NULL REFERENCES syllabus.projects (id) ON DELETE CASCADE,
    title           text NOT NULL CHECK (length(title) BETWEEN 1 AND 255),
    description     text CHECK (length(description) <= 3000),
    assignee_id     uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    start_date      date,
    due_date        date NOT NULL CHECK (start_date IS NULL OR due_date >= start_date),
    target_quantity numeric(10, 2) NOT NULL DEFAULT 1 CHECK (target_quantity > 0),
    unit            text NOT NULL DEFAULT 'phần việc' CHECK (length(unit) BETWEEN 1 AND 30),
    done_quantity   numeric(10, 2) NOT NULL DEFAULT 0 CHECK (done_quantity >= 0),
    status          text NOT NULL DEFAULT 'todo' CHECK (status IN ('todo', 'in_progress', 'done')),
    completed_at    timestamptz,
    -- Đã nhắc sắp tới hạn (SLA academic.milestone_remind); đổi hạn thì nhắc lại
    reminded_at     timestamptz,
    sort_order      integer NOT NULL DEFAULT 0,
    created_at      timestamptz NOT NULL DEFAULT now(),
    updated_at      timestamptz NOT NULL DEFAULT now(),
    deleted_at      timestamptz
);
CREATE INDEX milestones_project_idx ON syllabus.milestones (project_id, due_date) WHERE deleted_at IS NULL;
CREATE INDEX milestones_status_idx ON syllabus.milestones (status, due_date) WHERE deleted_at IS NULL;
CREATE INDEX milestones_assignee_idx ON syllabus.milestones (assignee_id, due_date) WHERE deleted_at IS NULL;

-- Cập nhật tiến độ của thành viên: việc đã làm, khối lượng xong đến nay, link sản phẩm, khó khăn; phản hồi của Học thuật.
CREATE TABLE syllabus.project_updates (
    id             uuid PRIMARY KEY DEFAULT uuidv7(),
    project_id     uuid NOT NULL REFERENCES syllabus.projects (id) ON DELETE CASCADE,
    milestone_id   uuid REFERENCES syllabus.milestones (id) ON DELETE SET NULL,
    user_id        uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    quantity_done  numeric(10, 2) CHECK (quantity_done >= 0),
    content        text NOT NULL CHECK (length(content) BETWEEN 1 AND 5000),
    difficulties   text CHECK (length(difficulties) <= 5000),
    -- Tối đa 5 link http(s) mỗi lần cập nhật
    links          text[] NOT NULL DEFAULT '{}' CHECK (cardinality(links) <= 5),
    marks_complete boolean NOT NULL DEFAULT false,
    response       text CHECK (length(response) <= 3000),
    responded_by   uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    responded_at   timestamptz,
    created_at     timestamptz NOT NULL DEFAULT now(),
    updated_at     timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX project_updates_project_idx ON syllabus.project_updates (project_id, created_at DESC);
CREATE INDEX project_updates_user_idx ON syllabus.project_updates (user_id, created_at);

-- +goose Down
DROP TABLE syllabus.project_updates;
DROP TABLE syllabus.milestones;
DROP TABLE syllabus.project_members;
DROP TABLE syllabus.projects;
