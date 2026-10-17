-- Lương (Laravel: payroll_periods, payroll_records, teacher_rates, teacher_hourly_rates, commission_tiers,
-- commission_items, commission_adjustments — PayrollController, PayrollPeriod, SalesCommissionService).
-- Tiền là VND nguyên (bigint). Tham số tính lương nằm ở core.settings (khóa payroll_*).
-- Chấm công (hr.teacher_timesheets…) thuộc phân hệ Chấm công: phân hệ Lương đọc qua interface, không tham chiếu bảng.
-- +goose Up

-- Kỳ lương theo tháng: draft = Đang tính, reviewing = Đang soát (đã tính), approved = Đã duyệt (khóa), paid = Đã trả.
CREATE TABLE hr.payroll_periods (
    id            uuid PRIMARY KEY DEFAULT uuidv7(),
    code          text NOT NULL UNIQUE CHECK (length(code) BETWEEN 1 AND 40),
    title         text NOT NULL CHECK (length(title) BETWEEN 1 AND 255),
    month         smallint NOT NULL CHECK (month BETWEEN 1 AND 12),
    year          smallint NOT NULL CHECK (year BETWEEN 2020 AND 2100),
    start_date    date NOT NULL,
    end_date      date NOT NULL CHECK (end_date >= start_date),
    status        text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'reviewing', 'approved', 'paid')),
    total_staff   integer NOT NULL DEFAULT 0 CHECK (total_staff >= 0),
    total_hours   numeric(10, 2) NOT NULL DEFAULT 0,
    total_amount  bigint NOT NULL DEFAULT 0,
    calculated_at timestamptz,
    approved_at   timestamptz,
    approved_by   uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    paid_at       timestamptz,
    paid_by       uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    legacy_id     bigint UNIQUE,
    created_by    uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    created_at    timestamptz NOT NULL DEFAULT now(),
    updated_at    timestamptz NOT NULL DEFAULT now(),
    UNIQUE (year, month)
);
CREATE INDEX payroll_periods_status_idx ON hr.payroll_periods (status, start_date);

-- Phiếu lương một người một kỳ (công thức Q3: employee_type parttime / fulltime; NULL = phiếu trước Q3).
-- Khoản nhập tay giữ qua các lần tính lại: retention_tier, kpi_manual_amount, foreign_session_pay, tax_deduction,
-- manual_lines [{kind: earning|deduction, label, amount}], adjustment_notes.
CREATE TABLE hr.payroll_records (
    id                             uuid PRIMARY KEY DEFAULT uuidv7(),
    period_id                      uuid NOT NULL REFERENCES hr.payroll_periods (id) ON DELETE CASCADE,
    user_id                        uuid NOT NULL REFERENCES iam.users (id),
    department                     text CHECK (department IN ('teacher', 'fulltime', 'academic', 'operations')),
    employee_type                  text CHECK (employee_type IN ('parttime', 'fulltime')),
    salary_role                    text CHECK (length(salary_role) <= 40),
    base_salary                    bigint NOT NULL DEFAULT 0,
    actual_hours                   numeric(8, 2) NOT NULL DEFAULT 0,
    teaching_sessions              integer NOT NULL DEFAULT 0,
    teaching_salary                bigint NOT NULL DEFAULT 0,
    teaching_concurrent            boolean NOT NULL DEFAULT false,
    teaching_kpi_bonus             bigint NOT NULL DEFAULT 0,
    retention_base_students        integer NOT NULL DEFAULT 0,
    retention_students             integer NOT NULL DEFAULT 0,
    retention_tier                 bigint CHECK (retention_tier >= 0),
    kpi_source                     text CHECK (kpi_source IN ('retention', 'manual', 'academic_kpi', 'self')),
    kpi_manual_amount              bigint CHECK (kpi_manual_amount >= 0),
    kpi_score                      numeric(6, 2),
    kpi_bonus                      bigint NOT NULL DEFAULT 0,
    foreign_session_pay            bigint NOT NULL DEFAULT 0 CHECK (foreign_session_pay >= 0),
    foreign_teacher_sessions_count integer NOT NULL DEFAULT 0,
    renew_bonus                    bigint NOT NULL DEFAULT 0,
    commission_bonus               bigint NOT NULL DEFAULT 0,
    commission_base                bigint NOT NULL DEFAULT 0,
    commission_clawback            bigint NOT NULL DEFAULT 0,
    commission_deferred            bigint NOT NULL DEFAULT 0,
    commission_percent             numeric(5, 2),
    commission_closed_count        integer NOT NULL DEFAULT 0,
    allowance                      bigint NOT NULL DEFAULT 0,
    other_bonus                    bigint NOT NULL DEFAULT 0,
    other_deduction                bigint NOT NULL DEFAULT 0,
    insurance_deduction            bigint NOT NULL DEFAULT 0,
    union_deduction                bigint NOT NULL DEFAULT 0,
    tax_deduction                  bigint NOT NULL DEFAULT 0 CHECK (tax_deduction >= 0),
    penalty_deduction              bigint NOT NULL DEFAULT 0,
    -- Giảm trừ ca có GVNN (dữ liệu trước Q3)
    foreign_teacher_deduction      bigint NOT NULL DEFAULT 0,
    net_salary                     bigint NOT NULL DEFAULT 0,
    status                         text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'confirmed', 'paid')),
    notes                          text CHECK (length(notes) <= 2000),
    adjustment_notes               text CHECK (length(adjustment_notes) <= 1000),
    manual_lines                   jsonb NOT NULL DEFAULT '[]' CHECK (jsonb_typeof(manual_lines) = 'array'),
    calculation_details            jsonb NOT NULL DEFAULT '{}',
    legacy_id                      bigint UNIQUE,
    created_at                     timestamptz NOT NULL DEFAULT now(),
    updated_at                     timestamptz NOT NULL DEFAULT now(),
    UNIQUE (period_id, user_id)
);
CREATE INDEX payroll_records_user_idx ON hr.payroll_records (user_id);

-- Bảng đơn giá giờ dạy theo cấp bậc (tham khảo chung).
CREATE TABLE hr.teacher_rates (
    id                 uuid PRIMARY KEY DEFAULT uuidv7(),
    rank_title         text NOT NULL CHECK (length(rank_title) BETWEEN 1 AND 255),
    criteria           text CHECK (length(criteria) <= 1000),
    communication_rate bigint NOT NULL DEFAULT 0 CHECK (communication_rate >= 0),
    ielts_rate         bigint NOT NULL DEFAULT 0 CHECK (ielts_rate >= 0),
    sort_order         integer NOT NULL DEFAULT 0,
    created_at         timestamptz NOT NULL DEFAULT now(),
    updated_at         timestamptz NOT NULL DEFAULT now()
);

-- Đơn giá riêng của giáo viên theo phiên bản hiệu lực: session = đ/buổi, hour = đ/giờ, tuition = % học phí.
CREATE TABLE hr.teacher_hourly_rates (
    id             uuid PRIMARY KEY DEFAULT uuidv7(),
    user_id        uuid NOT NULL REFERENCES iam.users (id) ON DELETE CASCADE,
    rate           numeric(12, 2) NOT NULL CHECK (rate >= 0),
    rate_unit      text NOT NULL DEFAULT 'hour' CHECK (rate_unit IN ('session', 'hour', 'tuition')),
    teacher_type   text CHECK (teacher_type IN ('parttime', 'fulltime', 'foreign', 'assistant', 'academic')),
    effective_from date NOT NULL,
    note           text CHECK (length(note) <= 500),
    created_by     uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    created_at     timestamptz NOT NULL DEFAULT now(),
    updated_at     timestamptz NOT NULL DEFAULT now(),
    UNIQUE (user_id, effective_from)
);

-- Mốc hoa hồng theo thứ tự HS chốt trong tháng (phiên bản: effective_from / effective_to, NULL = không giới hạn).
CREATE TABLE hr.commission_tiers (
    id               uuid PRIMARY KEY DEFAULT uuidv7(),
    tier_name        text NOT NULL CHECK (length(tier_name) BETWEEN 1 AND 255),
    min_students     integer NOT NULL CHECK (min_students >= 0),
    max_students     integer CHECK (max_students IS NULL OR max_students >= min_students),
    new_sale_percent numeric(5, 2) NOT NULL CHECK (new_sale_percent BETWEEN 0 AND 100),
    effective_from   date,
    effective_to     date,
    replaces_id      uuid REFERENCES hr.commission_tiers (id) ON DELETE SET NULL,
    created_by       uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    created_at       timestamptz NOT NULL DEFAULT now(),
    updated_at       timestamptz NOT NULL DEFAULT now(),
    CHECK (effective_to IS NULL OR effective_from IS NULL OR effective_to >= effective_from - 1)
);
INSERT INTO hr.commission_tiers (tier_name, min_students, max_students, new_sale_percent) VALUES
    ('Bậc 1 (0–5 HS chốt)', 0, 5, 3),
    ('Bậc 2 (6–10 HS chốt)', 6, 10, 4),
    ('Bậc 3 (từ 11 HS chốt)', 11, NULL, 5);

-- Sổ hoa hồng: mỗi phiếu thu khách mới một khoản. deferred = hoãn (chưa đạt gate kép), payable = trả trong kỳ đang tính,
-- paid = đã trả (kỳ đã duyệt), void = hủy (phiếu bị hủy / hoàn phí có thu hồi).
CREATE TABLE hr.commission_items (
    id                  uuid PRIMARY KEY DEFAULT uuidv7(),
    user_id             uuid NOT NULL REFERENCES iam.users (id),
    receipt_id          uuid NOT NULL UNIQUE REFERENCES finance.receipts (id) ON DELETE CASCADE,
    student_id          uuid REFERENCES academic.students (id) ON DELETE SET NULL,
    lead_id             uuid REFERENCES crm.leads (id) ON DELETE SET NULL,
    base_amount         bigint NOT NULL DEFAULT 0,
    percent             numeric(5, 2) NOT NULL DEFAULT 0,
    amount              bigint NOT NULL DEFAULT 0,
    closed_count        integer NOT NULL DEFAULT 0,
    closing_rank        integer NOT NULL DEFAULT 1,
    earned_period_start date NOT NULL,
    earned_period_end   date NOT NULL,
    closed_at           date,
    status              text NOT NULL DEFAULT 'deferred' CHECK (status IN ('deferred', 'payable', 'paid', 'void')),
    deferred_reason     text CHECK (length(deferred_reason) <= 1000),
    payroll_record_id   uuid REFERENCES hr.payroll_records (id) ON DELETE SET NULL,
    settled_at          timestamptz,
    created_at          timestamptz NOT NULL DEFAULT now(),
    updated_at          timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX commission_items_user_idx ON hr.commission_items (user_id, status);
CREATE INDEX commission_items_record_idx ON hr.commission_items (payroll_record_id);
CREATE INDEX commission_items_student_idx ON hr.commission_items (student_id);

-- Thu hồi hoa hồng (số âm): hoàn phí có thu hồi / hủy hóa đơn sau khi đã trả; trừ ở lần tính lương kế tiếp.
CREATE TABLE hr.commission_adjustments (
    id                uuid PRIMARY KEY DEFAULT uuidv7(),
    user_id           uuid NOT NULL REFERENCES iam.users (id),
    refund_request_id uuid REFERENCES finance.refund_requests (id) ON DELETE SET NULL,
    student_id        uuid REFERENCES academic.students (id) ON DELETE SET NULL,
    amount            bigint NOT NULL,
    reason            text NOT NULL CHECK (length(reason) BETWEEN 1 AND 1000),
    payroll_record_id uuid REFERENCES hr.payroll_records (id) ON DELETE SET NULL,
    settled_at        timestamptz,
    created_by        uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    created_at        timestamptz NOT NULL DEFAULT now(),
    updated_at        timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX commission_adjustments_user_idx ON hr.commission_adjustments (user_id) WHERE settled_at IS NULL;

-- +goose Down
DROP TABLE hr.commission_adjustments;
DROP TABLE hr.commission_items;
DROP TABLE hr.commission_tiers;
DROP TABLE hr.teacher_hourly_rates;
DROP TABLE hr.teacher_rates;
DROP TABLE hr.payroll_records;
DROP TABLE hr.payroll_periods;
