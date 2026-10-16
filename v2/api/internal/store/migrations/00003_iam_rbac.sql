-- Chi nhánh, vai trò, quyền, gán vai trò / chi nhánh cho người dùng và phân quyền cá nhân.
-- Danh mục quyền và 9 vai trò được đồng bộ từ code (iam/catalog) mỗi lần chạy `menglish migrate`.
-- +goose Up
CREATE TABLE core.branches (
    id         uuid PRIMARY KEY DEFAULT uuidv7(),
    code       citext NOT NULL UNIQUE CHECK (code ~ '^[A-Za-z0-9_-]{1,20}$'),
    name       text NOT NULL CHECK (length(name) BETWEEN 1 AND 200),
    address    text CHECK (length(address) <= 500),
    phone      text CHECK (phone ~ '^0[0-9]{8,10}$'),
    is_active  boolean NOT NULL DEFAULT true,
    created_by uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);

-- Người tạo tài khoản: phạm vi "Của tôi" của module user là các tài khoản do mình tạo.
ALTER TABLE iam.users ADD COLUMN created_by uuid REFERENCES iam.users (id) ON DELETE SET NULL;

CREATE TABLE iam.roles (
    name        text PRIMARY KEY CHECK (name ~ '^[a-z][a-z0-9_]{1,49}$'),
    label       text NOT NULL CHECK (length(label) BETWEEN 1 AND 100),
    short_label text NOT NULL CHECK (length(short_label) BETWEEN 1 AND 50),
    sort        integer NOT NULL DEFAULT 0,
    created_at  timestamptz NOT NULL DEFAULT now(),
    updated_at  timestamptz NOT NULL DEFAULT now()
);

-- kind: action (module.action), scope (module.scope_<mức>), audience (đối tượng), dynamic (user.assign_role.<vai trò>)
CREATE TABLE iam.permissions (
    name   text PRIMARY KEY,
    module text NOT NULL,
    kind   text NOT NULL CHECK (kind IN ('action', 'scope', 'audience', 'dynamic'))
);

CREATE TABLE iam.role_permissions (
    role       text NOT NULL REFERENCES iam.roles (name) ON UPDATE CASCADE ON DELETE CASCADE,
    permission text NOT NULL REFERENCES iam.permissions (name) ON DELETE CASCADE,
    PRIMARY KEY (role, permission)
);

CREATE TABLE iam.user_roles (
    user_id uuid NOT NULL REFERENCES iam.users (id) ON DELETE CASCADE,
    role    text NOT NULL REFERENCES iam.roles (name) ON UPDATE CASCADE ON DELETE CASCADE,
    PRIMARY KEY (user_id, role)
);
CREATE INDEX user_roles_role_idx ON iam.user_roles (role);

-- Chi nhánh của người dùng: một chi nhánh chính + các chi nhánh được cấp thêm.
CREATE TABLE iam.user_branches (
    user_id    uuid NOT NULL REFERENCES iam.users (id) ON DELETE CASCADE,
    branch_id  uuid NOT NULL REFERENCES core.branches (id),
    is_primary boolean NOT NULL DEFAULT false,
    PRIMARY KEY (user_id, branch_id)
);
CREATE UNIQUE INDEX user_branches_primary_key ON iam.user_branches (user_id) WHERE is_primary;
CREATE INDEX user_branches_branch_idx ON iam.user_branches (branch_id);

-- Phân quyền cá nhân: cho phép hoặc chặn một quyền, thắng quyền theo vai trò.
-- scope_type all: mọi nơi; branch / class: chỉ trong chi nhánh / lớp scope_id (module tự kiểm).
CREATE TABLE iam.permission_overrides (
    user_id    uuid NOT NULL REFERENCES iam.users (id) ON DELETE CASCADE,
    permission text NOT NULL REFERENCES iam.permissions (name) ON DELETE CASCADE,
    allow      boolean NOT NULL,
    scope_type text NOT NULL DEFAULT 'all' CHECK (scope_type IN ('all', 'branch', 'class')),
    scope_id   uuid,
    created_by uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    CHECK ((scope_type = 'all') = (scope_id IS NULL)),
    UNIQUE NULLS NOT DISTINCT (user_id, permission, scope_type, scope_id)
);

-- +goose Down
DROP TABLE iam.permission_overrides;
DROP TABLE iam.user_branches;
DROP TABLE iam.user_roles;
DROP TABLE iam.role_permissions;
DROP TABLE iam.permissions;
DROP TABLE iam.roles;
ALTER TABLE iam.users DROP COLUMN created_by;
DROP TABLE core.branches;
