-- Báo cáo & Nhật ký của nhân sự (Laravel: staff_reports, staff_report_followups — StaffReportController).
--  - type: journal (Nhật ký Học vụ / sự vụ) | daily | weekly | monthly | quarterly (loại kỳ, màn Tổng hợp lọc theo cột này).
--  - kind: báo cáo có cấu trúc theo vai trò (daily_ops, weekly_kpi, academic_weekly, academic_monthly, academic_quarterly,
--    teacher_monthly, teacher_attendance). Mỗi người một báo cáo / kind / kỳ (period_key: 2026-10-16, 2026-W42, 2026-10, 2026-Q4);
--    nộp lại trong kỳ là cập nhật. Báo cáo chữ tự do (kind, period_key null) giữ để xem lại.
--  - data: nội dung từng kind (ghi chú, bảng dòng, số liệu hệ thống đã chụp lúc nộp…), kiểm bằng struct Go trước khi lưu.
--  - content: bản chữ để màn Tổng hợp / thông báo đọc được như báo cáo thường.
-- +goose Up
CREATE SCHEMA IF NOT EXISTS ops;

CREATE TABLE ops.staff_reports (
    id                   uuid PRIMARY KEY DEFAULT uuidv7(),
    -- Người viết (nhật ký: người có sự việc; Admin / Quản lý ghi hộ thì created_by là người ghi)
    user_id              uuid NOT NULL REFERENCES iam.users (id) ON DELETE CASCADE,
    class_id             uuid, -- FK tới academic.classes thêm khi gộp
    type                 text NOT NULL CHECK (type IN ('journal', 'daily', 'weekly', 'monthly', 'quarterly')),
    kind                 text CHECK (kind IN ('daily_ops', 'weekly_kpi', 'academic_weekly', 'academic_monthly',
                                              'academic_quarterly', 'teacher_monthly', 'teacher_attendance')),
    period_key           text,
    title                text NOT NULL CHECK (length(title) BETWEEN 1 AND 255),
    content              text,
    data                 jsonb NOT NULL DEFAULT '{}'::jsonb,
    -- Nhật ký: mức độ, mã mục KPI Học vụ, Admin xác nhận "Lỗi nghiêm trọng"
    severity             text CHECK (severity IN ('normal', 'important', 'urgent')),
    kpi_code             text CHECK (length(kpi_code) <= 20),
    serious_confirmed_by uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    serious_confirmed_at timestamptz,
    report_date          date NOT NULL,
    -- Báo cáo: draft | submitted | returned (Admin yêu cầu bổ sung). Nhật ký: open | following | resolved.
    status               text NOT NULL CHECK (status IN ('draft', 'submitted', 'returned', 'open', 'following', 'resolved')),
    -- Lần nộp đầu (giờ nộp + nhãn Trễ ở màn Tổng hợp); bản nháp chưa có
    submitted_at         timestamptz,
    reviewed_by          uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    reviewed_at          timestamptz,
    review_note          text CHECK (length(review_note) <= 2000),
    created_by           uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    created_at           timestamptz NOT NULL DEFAULT now(),
    updated_at           timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT staff_reports_period_pair CHECK ((kind IS NULL) = (period_key IS NULL)),
    CONSTRAINT staff_reports_journal_kind CHECK (type <> 'journal' OR kind IS NULL),
    CONSTRAINT staff_reports_journal_status CHECK (
        (type = 'journal') = (status IN ('open', 'following', 'resolved'))
    ),
    CONSTRAINT staff_reports_period_format CHECK (
        period_key IS NULL
        OR (kind = 'daily_ops' AND period_key ~ '^\d{4}-\d{2}-\d{2}$')
        OR (kind IN ('weekly_kpi', 'academic_weekly') AND period_key ~ '^\d{4}-W\d{2}$')
        OR (kind IN ('academic_monthly', 'teacher_monthly', 'teacher_attendance') AND period_key ~ '^\d{4}-(0[1-9]|1[0-2])$')
        OR (kind = 'academic_quarterly' AND period_key ~ '^\d{4}-Q[1-4]$')
    ),
    CONSTRAINT staff_reports_serious_pair CHECK ((serious_confirmed_at IS NULL) = (serious_confirmed_by IS NULL)),
    -- Hai tab cùng bấm Lưu không tạo hai bản (Laravel staff_reports_user_kind_period_unique)
    CONSTRAINT staff_reports_user_kind_period_key UNIQUE (user_id, kind, period_key)
);
CREATE INDEX staff_reports_type_date_idx ON ops.staff_reports (type, report_date DESC);
CREATE INDEX staff_reports_user_type_idx ON ops.staff_reports (user_id, type, report_date DESC);
CREATE INDEX staff_reports_class_idx ON ops.staff_reports (class_id, type) WHERE class_id IS NOT NULL;
-- Hàng chờ Admin kiểm tra
CREATE INDEX staff_reports_pending_idx ON ops.staff_reports (updated_at DESC)
    WHERE type <> 'journal' AND status = 'submitted' AND reviewed_at IS NULL;

-- Lượt follow-up / xử lý của một sự việc trong nhật ký
CREATE TABLE ops.staff_report_followups (
    id              uuid PRIMARY KEY DEFAULT uuidv7(),
    staff_report_id uuid NOT NULL REFERENCES ops.staff_reports (id) ON DELETE CASCADE,
    user_id         uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    content         text NOT NULL CHECK (length(content) BETWEEN 1 AND 5000),
    created_at      timestamptz NOT NULL DEFAULT now(),
    updated_at      timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX staff_report_followups_report_idx ON ops.staff_report_followups (staff_report_id, created_at DESC);

-- +goose Down
DROP TABLE ops.staff_report_followups;
DROP TABLE ops.staff_reports;
