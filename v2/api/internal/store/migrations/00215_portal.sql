-- Cổng Phụ huynh & Học sinh (Laravel StudentPortalController: bản ghi academic_records theo màn 04_Cong_Phu_Huynh_Hoc_Sinh).
-- v2 tách bảng riêng cho phần cổng học viên tự giữ:
-- - finance.payment_reports: "Báo đóng học phí" học viên / phụ huynh gửi (PaymentReportService), kế toán xác nhận / từ chối.
-- - academic.pronunciation_records: bản ghi âm luyện phát âm, giáo viên chấm (màn Chấm bài nộp, tab Phát âm).
-- - academic.stage_feedbacks: phụ huynh góp ý chặng học (một bản mỗi học viên mỗi chặng, gửi lại thì cập nhật).
-- Bài nộp bài tập, khảo sát 5 sao thuộc phân hệ Cổng giáo viên / Khảo sát; hộp thư thông báo dùng core.notifications.
-- +goose Up
CREATE TABLE finance.payment_reports (
    id               uuid PRIMARY KEY DEFAULT uuidv7(),
    -- Mã như Laravel: YCHOCPHI-XXXXXX
    code             text NOT NULL UNIQUE CHECK (code ~ '^YCHOCPHI-[A-Z0-9]{6}$'),
    student_id       uuid NOT NULL REFERENCES academic.students (id) ON DELETE CASCADE,
    -- Chi nhánh của học viên lúc gửi (lọc theo phạm vi Học phí của người duyệt)
    branch_id        uuid REFERENCES core.branches (id),
    amount           bigint NOT NULL CHECK (amount >= 1000),
    content          text NOT NULL CHECK (length(content) BETWEEN 1 AND 500),
    status           text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'confirmed', 'rejected')),
    rejection_reason text CHECK (length(rejection_reason) <= 500),
    resolved_by      uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    resolved_at      timestamptz,
    created_by       uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    created_at       timestamptz NOT NULL DEFAULT now(),
    updated_at       timestamptz NOT NULL DEFAULT now(),
    CHECK (status <> 'rejected' OR rejection_reason IS NOT NULL)
);
CREATE INDEX payment_reports_pending_idx ON finance.payment_reports (branch_id, created_at) WHERE status = 'pending';
CREATE INDEX payment_reports_student_idx ON finance.payment_reports (student_id, created_at DESC);

CREATE TABLE academic.pronunciation_records (
    id            uuid PRIMARY KEY DEFAULT uuidv7(),
    code          text NOT NULL UNIQUE CHECK (code ~ '^AUDIO-[A-Z0-9]{6}$'),
    student_id    uuid NOT NULL REFERENCES academic.students (id) ON DELETE CASCADE,
    unit_title    text NOT NULL CHECK (length(unit_title) BETWEEN 1 AND 255),
    -- Thời lượng do trình duyệt đo, vd. "00:42"
    duration      text CHECK (length(duration) <= 20),
    audio_file_id uuid REFERENCES core.files (id) ON DELETE SET NULL,
    -- pending_review: chờ giáo viên chấm (chưa có dịch vụ chấm tự động); reviewed: đã chấm
    status        text NOT NULL DEFAULT 'pending_review' CHECK (status IN ('pending_review', 'reviewed')),
    -- Điểm giáo viên nhập (thang 100, dạng chữ như Laravel), nhận xét
    score         text CHECK (length(score) <= 20),
    feedback      text CHECK (length(feedback) <= 1000),
    reviewed_by   uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    reviewed_at   timestamptz,
    created_by    uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    created_at    timestamptz NOT NULL DEFAULT now(),
    updated_at    timestamptz NOT NULL DEFAULT now(),
    CHECK (status <> 'reviewed' OR score IS NOT NULL)
);
CREATE INDEX pronunciation_records_student_idx ON academic.pronunciation_records (student_id, created_at DESC);

CREATE TABLE academic.stage_feedbacks (
    id          uuid PRIMARY KEY DEFAULT uuidv7(),
    code        text NOT NULL UNIQUE CHECK (code ~ '^FB-[A-Z0-9]{6}$'),
    student_id  uuid NOT NULL REFERENCES academic.students (id) ON DELETE CASCADE,
    -- Chặng đang học của lớp lúc gửi (giao chặng của giáo trình) hoặc "Góp ý chung"
    stage_name  text NOT NULL CHECK (length(stage_name) BETWEEN 1 AND 255),
    class_id    uuid REFERENCES academic.classes (id) ON DELETE SET NULL,
    class_name  text CHECK (length(class_name) <= 255),
    -- Mức hài lòng 1–5, 0 = không chọn
    rating      smallint NOT NULL DEFAULT 0 CHECK (rating BETWEEN 0 AND 5),
    -- Lĩnh vực góp ý: Học thuật / Giáo viên / Khác
    fb_academic boolean NOT NULL DEFAULT false,
    fb_teacher  boolean NOT NULL DEFAULT false,
    fb_other    boolean NOT NULL DEFAULT false,
    content     text NOT NULL DEFAULT '' CHECK (length(content) <= 5000),
    created_by  uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    created_at  timestamptz NOT NULL DEFAULT now(),
    updated_at  timestamptz NOT NULL DEFAULT now(),
    -- Ít nhất một mục (R-04)
    CHECK (rating > 0 OR fb_academic OR fb_teacher OR fb_other OR content <> ''),
    UNIQUE (student_id, stage_name)
);

-- +goose Down
DROP TABLE academic.stage_feedbacks;
DROP TABLE academic.pronunciation_records;
DROP TABLE finance.payment_reports;
