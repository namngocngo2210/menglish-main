-- Giáo trình 4 cấp: Giáo trình → Chặng → Unit → Buổi (Laravel: syllabus_curriculums, syllabus_stages, syllabus_units,
-- syllabus_lessons), kho tài liệu giáo trình (syllabus_documents, syllabus_document_views) và đề xuất sửa giáo trình
-- (syllabus_change_proposals). File tài liệu / file đính kèm đề xuất nằm ở core.files.
-- +goose Up
CREATE SCHEMA IF NOT EXISTS syllabus;

CREATE TABLE syllabus.curricula (
    id          uuid PRIMARY KEY DEFAULT uuidv7(),
    -- Mã giáo trình (CUR-AB12): duy nhất kể cả bản ghi đã xóa như Laravel (unique:syllabus_curriculums,code)
    code        citext NOT NULL UNIQUE CHECK (length(code) BETWEEN 1 AND 50),
    title       text NOT NULL CHECK (length(title) BETWEEN 1 AND 255),
    course_id   uuid REFERENCES academic.courses (id) ON DELETE SET NULL,
    -- Phiên bản nội dung (v3.2…), không phải khóa lạc quan
    version     text NOT NULL CHECK (length(version) BETWEEN 1 AND 20),
    description text CHECK (length(description) <= 5000),
    created_by  uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    created_at  timestamptz NOT NULL DEFAULT now(),
    updated_at  timestamptz NOT NULL DEFAULT now(),
    deleted_at  timestamptz
);
CREATE INDEX curricula_title_idx ON syllabus.curricula (title) WHERE deleted_at IS NULL;

-- Chặng: thứ tự position quyết định chặng tự mở sau Big Test cuối chặng.
CREATE TABLE syllabus.stages (
    id             uuid PRIMARY KEY DEFAULT uuidv7(),
    curriculum_id  uuid NOT NULL REFERENCES syllabus.curricula (id) ON DELETE CASCADE,
    position       smallint NOT NULL CHECK (position >= 1),
    name           text NOT NULL CHECK (length(name) BETWEEN 1 AND 255),
    description    text CHECK (length(description) <= 5000),
    overview_link  text CHECK (length(overview_link) <= 500),
    big_test_title text CHECK (length(big_test_title) <= 255),
    big_test_note  text CHECK (length(big_test_note) <= 5000),
    created_at     timestamptz NOT NULL DEFAULT now(),
    updated_at     timestamptz NOT NULL DEFAULT now(),
    deleted_at     timestamptz
);
CREATE INDEX stages_curriculum_idx ON syllabus.stages (curriculum_id, position) WHERE deleted_at IS NULL;

-- Unit: nhóm buổi trong một chặng; số unit duy nhất trong giáo trình.
CREATE TABLE syllabus.units (
    id               uuid PRIMARY KEY DEFAULT uuidv7(),
    curriculum_id    uuid NOT NULL REFERENCES syllabus.curricula (id) ON DELETE CASCADE,
    stage_id         uuid REFERENCES syllabus.stages (id) ON DELETE CASCADE,
    unit_number      integer NOT NULL CHECK (unit_number BETWEEN 1 AND 500),
    title            text NOT NULL CHECK (length(title) BETWEEN 1 AND 255),
    objectives       text,
    vocabulary_focus text,
    grammar_focus    text,
    homework_guide   text,
    created_at       timestamptz NOT NULL DEFAULT now(),
    updated_at       timestamptz NOT NULL DEFAULT now(),
    deleted_at       timestamptz
);
CREATE UNIQUE INDEX units_number_uq ON syllabus.units (curriculum_id, unit_number) WHERE deleted_at IS NULL;
CREATE INDEX units_stage_idx ON syllabus.units (stage_id) WHERE deleted_at IS NULL;

-- Buổi: session_no đánh liên tục trong cả giáo trình ("buổi thứ N của lớp" ↔ session_no = N).
CREATE TABLE syllabus.lessons (
    id               uuid PRIMARY KEY DEFAULT uuidv7(),
    curriculum_id    uuid NOT NULL REFERENCES syllabus.curricula (id) ON DELETE CASCADE,
    unit_id          uuid NOT NULL REFERENCES syllabus.units (id) ON DELETE CASCADE,
    session_no       integer NOT NULL CHECK (session_no BETWEEN 1 AND 1000),
    title            text NOT NULL CHECK (length(title) BETWEEN 1 AND 255),
    objectives       text,
    content          text,
    vocabulary_focus text,
    grammar_focus    text,
    homework_guide   text,
    created_at       timestamptz NOT NULL DEFAULT now(),
    updated_at       timestamptz NOT NULL DEFAULT now(),
    deleted_at       timestamptz
);
CREATE UNIQUE INDEX lessons_session_uq ON syllabus.lessons (curriculum_id, session_no) WHERE deleted_at IS NULL;
CREATE INDEX lessons_unit_idx ON syllabus.lessons (unit_id, session_no) WHERE deleted_at IS NULL;

-- Tài liệu giáo trình (file thật ở core.files). Admin / Học vụ / Học thuật (syllabus.upload | syllabus.manage) xem mọi
-- tài liệu; giáo viên / trợ giảng xem theo cờ đối tượng; không cho tải thì chỉ xem trực tuyến.
CREATE TABLE syllabus.documents (
    id                    uuid PRIMARY KEY DEFAULT uuidv7(),
    curriculum_id         uuid NOT NULL REFERENCES syllabus.curricula (id) ON DELETE CASCADE,
    stage_id              uuid REFERENCES syllabus.stages (id) ON DELETE SET NULL,
    title                 text NOT NULL CHECK (length(title) BETWEEN 1 AND 255),
    -- Tên chặng nhập tự do của dữ liệu cũ (khi chưa gắn chặng thật)
    stage_name            text CHECK (length(stage_name) <= 255),
    file_id               uuid NOT NULL REFERENCES core.files (id),
    visible_to_teachers   boolean NOT NULL DEFAULT false,
    visible_to_assistants boolean NOT NULL DEFAULT false,
    downloadable          boolean NOT NULL DEFAULT false,
    uploaded_by           uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    created_at            timestamptz NOT NULL DEFAULT now(),
    updated_at            timestamptz NOT NULL DEFAULT now(),
    deleted_at            timestamptz
);
CREATE INDEX documents_curriculum_idx ON syllabus.documents (curriculum_id, created_at DESC) WHERE deleted_at IS NULL;
CREATE INDEX documents_file_idx ON syllabus.documents (file_id);

-- Giáo viên / trợ giảng "Đánh dấu đã xem".
CREATE TABLE syllabus.document_views (
    document_id uuid NOT NULL REFERENCES syllabus.documents (id) ON DELETE CASCADE,
    user_id     uuid NOT NULL REFERENCES iam.users (id) ON DELETE CASCADE,
    viewed_at   timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (document_id, user_id)
);
CREATE INDEX document_views_user_idx ON syllabus.document_views (user_id);

-- Đề xuất sửa giáo trình: giáo viên gửi, Admin duyệt / từ chối (kèm lý do).
CREATE TABLE syllabus.change_proposals (
    id                 uuid PRIMARY KEY DEFAULT uuidv7(),
    curriculum_id      uuid NOT NULL REFERENCES syllabus.curricula (id) ON DELETE CASCADE,
    unit_id            uuid REFERENCES syllabus.units (id) ON DELETE SET NULL,
    lesson_id          uuid REFERENCES syllabus.lessons (id) ON DELETE SET NULL,
    user_id            uuid NOT NULL REFERENCES iam.users (id) ON DELETE CASCADE,
    proposal_type      text CHECK (length(proposal_type) <= 255),
    old_content        text CHECK (length(old_content) <= 5000),
    new_content        text NOT NULL CHECK (length(new_content) BETWEEN 1 AND 5000),
    reason             text CHECK (length(reason) <= 2000),
    attachment_file_id uuid REFERENCES core.files (id),
    status             text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'approved', 'rejected')),
    reviewer_id        uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    reviewed_at        timestamptz,
    review_note        text CHECK (length(review_note) <= 2000),
    created_at         timestamptz NOT NULL DEFAULT now(),
    updated_at         timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX change_proposals_status_idx ON syllabus.change_proposals (status, created_at DESC);
CREATE INDEX change_proposals_user_idx ON syllabus.change_proposals (user_id, created_at DESC);

-- +goose Down
DROP TABLE syllabus.change_proposals;
DROP TABLE syllabus.document_views;
DROP TABLE syllabus.documents;
DROP TABLE syllabus.lessons;
DROP TABLE syllabus.units;
DROP TABLE syllabus.stages;
DROP TABLE syllabus.curricula;
