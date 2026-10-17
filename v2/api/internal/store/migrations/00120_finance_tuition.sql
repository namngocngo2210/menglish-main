-- Học phí: khoản học phí của học viên, phiếu thu, dải số hóa đơn, hủy hóa đơn, hoàn / chuyển phí / khất nợ / bảo lưu,
-- nhật ký liên hệ nhắc phí, giao dịch SePay và cấu hình SePay.
-- Bản Laravel: student_tuitions, tuition_receipts, invoice_configurations, invoice_cancellations,
-- tuition_refund_requests, tuition_contact_logs, sepay_transactions, sepay_configurations.
-- Tài khoản ngân hàng và mốc nhắc nợ ở 00023_finance_config (đọc qua phân hệ Danh mục).
-- +goose Up
CREATE SCHEMA IF NOT EXISTS finance;

-- Khoản học phí (hợp đồng) của một học viên; mỗi lần gia hạn / mua thêm khóa là một khoản mới.
CREATE TABLE finance.tuitions (
    id                        uuid PRIMARY KEY DEFAULT uuidv7(),
    student_id                uuid NOT NULL REFERENCES academic.students (id),
    class_id                  uuid REFERENCES academic.classes (id) ON DELETE SET NULL,
    -- Chi nhánh của khoản thu (NULL: theo chi nhánh học viên, rồi chi nhánh lớp)
    branch_id                 uuid REFERENCES core.branches (id) ON DELETE SET NULL,
    -- Tài khoản nhận tiền ghi trên hợp đồng (VietQR)
    bank_account_id           uuid REFERENCES finance.bank_accounts (id) ON DELETE SET NULL,
    promotion_id              uuid REFERENCES crm.promotions (id) ON DELETE SET NULL,
    -- Học phí niêm yết của số buổi trong hợp đồng
    total_amount              bigint NOT NULL DEFAULT 0 CHECK (total_amount >= 0),
    session_count             integer CHECK (session_count >= 0),
    discount_amount           bigint NOT NULL DEFAULT 0 CHECK (discount_amount >= 0),
    -- Khoản thu thêm (sách, đồng phục...) ghi trong hợp đồng: [{item_id, name, unit_price, quantity, amount}]
    other_fees                bigint NOT NULL DEFAULT 0 CHECK (other_fees >= 0),
    fee_items                 jsonb NOT NULL DEFAULT '[]',
    -- Khoản thu trước lúc chốt (đặt cọc)
    prepaid_amount            bigint NOT NULL DEFAULT 0 CHECK (prepaid_amount >= 0),
    -- Phải nộp = niêm yết − giảm + khoản thêm; đã nộp / còn nợ tính lại từ phiếu đã duyệt
    final_amount              bigint NOT NULL DEFAULT 0 CHECK (final_amount >= 0),
    paid_amount               bigint NOT NULL DEFAULT 0,
    debt_amount               bigint NOT NULL DEFAULT 0 CHECK (debt_amount >= 0),
    due_date                  date,
    -- Khất nợ / bảo lưu: dừng nhắc nợ tới hết ngày này
    reminder_paused_until     date,
    deferred_from             date,
    deferred_until            date,
    -- Đóng băng lúc bảo lưu: số buổi còn tồn và công nợ giữ nguyên tới khi học lại
    frozen_remaining_sessions integer CHECK (frozen_remaining_sessions >= 0),
    frozen_debt_amount        bigint CHECK (frozen_debt_amount >= 0),
    status                    text NOT NULL DEFAULT 'unpaid' CHECK (status IN ('unpaid', 'partial', 'paid', 'overdue')),
    notes                     text CHECK (length(notes) <= 2000),
    -- Nội dung chuyển khoản gợi ý (tên + mã HV + lớp, không dấu); SePay đối chiếu theo chuỗi này
    transfer_memo             text CHECK (length(transfer_memo) <= 255),
    legacy_id                 bigint UNIQUE,
    created_by                uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    created_at                timestamptz NOT NULL DEFAULT now(),
    updated_at                timestamptz NOT NULL DEFAULT now(),
    CHECK (deferred_until IS NULL OR deferred_from IS NULL OR deferred_until >= deferred_from)
);
CREATE INDEX tuitions_student_idx ON finance.tuitions (student_id, created_at DESC, id DESC);
CREATE INDEX tuitions_branch_idx ON finance.tuitions (branch_id);
CREATE INDEX tuitions_class_idx ON finance.tuitions (class_id);
CREATE INDEX tuitions_debt_due_idx ON finance.tuitions (due_date) WHERE debt_amount > 0;

-- Số chạy của mã phiếu thu PT-YYYY-NNNNNN
CREATE SEQUENCE finance.receipt_seq;

-- Phiếu thu: tiền học phí (giảm nợ) + phụ thu (không tính vào công nợ). Phiếu hoàn / chuyển phí do hệ thống lập có
-- số tiền âm. Duyệt xong mới cấp số hóa đơn điện tử; hóa đơn giấy (tiền mặt) cấp số ngay lúc lập.
CREATE TABLE finance.receipts (
    id                   uuid PRIMARY KEY DEFAULT uuidv7(),
    code                 text NOT NULL UNIQUE CHECK (length(code) BETWEEN 1 AND 60),
    -- manual: lập tay | closing / closing_prepaid: chốt khách ở CRM | sepay: SePay tự gạch nợ |
    -- refund / transfer_out / transfer_in: phiếu hệ thống khi duyệt hoàn / chuyển phí | import: nhập Excel | legacy
    source               text NOT NULL DEFAULT 'manual'
        CHECK (source IN ('manual', 'closing', 'closing_prepaid', 'sepay', 'refund', 'transfer_out', 'transfer_in', 'import', 'legacy')),
    invoice_number       text UNIQUE CHECK (length(invoice_number) <= 60),
    tuition_id           uuid REFERENCES finance.tuitions (id) ON DELETE SET NULL,
    student_id           uuid REFERENCES academic.students (id),
    -- Tổng tiền của phiếu = tiền học phí + phụ thu
    amount               bigint NOT NULL,
    tuition_amount       bigint NOT NULL DEFAULT 0,
    -- Thu theo buổi (sổ buổi): số buổi, đơn giá, phí kèm
    session_count        integer CHECK (session_count BETWEEN 0 AND 500),
    session_unit_price   bigint CHECK (session_unit_price >= 0),
    material_fee         bigint NOT NULL DEFAULT 0 CHECK (material_fee >= 0),
    exam_fee             bigint NOT NULL DEFAULT 0 CHECK (exam_fee >= 0),
    other_fee            bigint NOT NULL DEFAULT 0 CHECK (other_fee >= 0),
    other_fee_reason     text CHECK (length(other_fee_reason) <= 255),
    surcharge_amount     bigint NOT NULL DEFAULT 0 CHECK (surcharge_amount >= 0),
    surcharge_reason     text CHECK (length(surcharge_reason) <= 500),
    discount_amount      bigint NOT NULL DEFAULT 0 CHECK (discount_amount >= 0),
    promotion_id         uuid REFERENCES crm.promotions (id) ON DELETE SET NULL,
    discount_reason      text CHECK (length(discount_reason) <= 500),
    payment_method       text NOT NULL DEFAULT 'transfer' CHECK (payment_method IN ('transfer', 'vietqr', 'cash', 'pos')),
    transaction_code     text CHECK (length(transaction_code) <= 100),
    -- Mã giao dịch chuẩn hóa, chỉ giữ khi phiếu chuyển khoản đang chờ duyệt / đã duyệt: một mã chỉ ghi nhận một lần
    transfer_reference   text GENERATED ALWAYS AS (
        CASE WHEN payment_method IN ('transfer', 'vietqr') AND status IN ('pending', 'approved')
            THEN nullif(upper(regexp_replace(coalesce(transaction_code, ''), '\s+', '', 'g')), '') END) STORED,
    payer_name           text CHECK (length(payer_name) <= 255),
    payer_phone          text CHECK (length(payer_phone) <= 50),
    is_vat_invoice       boolean NOT NULL DEFAULT false,
    paper_invoice_number text CHECK (length(paper_invoice_number) <= 100),
    -- TODO(files): ảnh minh chứng lưu ở phân hệ tệp; tạm nhận đường dẫn / ghi chú
    proof_url            text CHECK (length(proof_url) <= 1000),
    proof_file_id        uuid, -- FK tới core.files thêm khi gộp
    payment_date         date,
    -- Hàng hóa giao kèm phiếu (phần phụ thu): [{item_id, name, unit_price, quantity, amount}]
    collected_items      jsonb NOT NULL DEFAULT '[]',
    status               text NOT NULL DEFAULT 'pending'
        CHECK (status IN ('draft', 'pending', 'approved', 'rejected', 'cancelled')),
    notes                text CHECK (length(notes) <= 1000),
    rejection_reason     text CHECK (length(rejection_reason) <= 1000),
    creator_id           uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    approver_id          uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    approved_at          timestamptz,
    -- Tiền mặt đã nộp về tài khoản công ty
    deposited_at         timestamptz,
    deposited_by         uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    legacy_id            bigint UNIQUE,
    created_at           timestamptz NOT NULL DEFAULT now(),
    updated_at           timestamptz NOT NULL DEFAULT now(),
    CHECK (status <> 'approved' OR approved_at IS NOT NULL),
    CHECK (surcharge_amount <= greatest(amount, 0))
);
CREATE UNIQUE INDEX receipts_transfer_reference_key ON finance.receipts (transfer_reference) WHERE transfer_reference IS NOT NULL;
CREATE INDEX receipts_tuition_idx ON finance.receipts (tuition_id);
CREATE INDEX receipts_student_idx ON finance.receipts (student_id, created_at DESC);
CREATE INDEX receipts_status_idx ON finance.receipts (status, created_at DESC, id DESC);
CREATE INDEX receipts_approved_idx ON finance.receipts (approved_at) WHERE status = 'approved';
CREATE INDEX receipts_creator_idx ON finance.receipts (creator_id);

-- Dải số hóa đơn: điện tử (theo chi nhánh hoặc dải mặc định dùng chung) và giấy (mỗi chi nhánh một cuốn).
CREATE TABLE finance.invoice_ranges (
    id             uuid PRIMARY KEY DEFAULT uuidv7(),
    branch_id      uuid REFERENCES core.branches (id) ON DELETE CASCADE,
    kind           text NOT NULL DEFAULT 'electronic' CHECK (kind IN ('electronic', 'paper')),
    template_code  text NOT NULL DEFAULT '1/001' CHECK (length(template_code) BETWEEN 1 AND 50),
    series_code    text NOT NULL CHECK (series_code ~ '^[A-Za-z0-9]{1,30}$'),
    start_number   bigint NOT NULL CHECK (start_number >= 1),
    end_number     bigint,
    -- Số sẽ cấp tiếp theo
    current_number bigint NOT NULL,
    provider       text NOT NULL DEFAULT 'VNPT Invoice' CHECK (length(provider) <= 100),
    auto_issue     boolean NOT NULL DEFAULT true,
    is_active      boolean NOT NULL DEFAULT true,
    created_by     uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    created_at     timestamptz NOT NULL DEFAULT now(),
    updated_at     timestamptz NOT NULL DEFAULT now(),
    CHECK (kind <> 'paper' OR branch_id IS NOT NULL),
    CHECK (end_number IS NULL OR end_number >= start_number),
    CHECK (current_number >= start_number AND (end_number IS NULL OR current_number <= end_number + 1))
);
CREATE INDEX invoice_ranges_lookup_idx ON finance.invoice_ranges (kind, branch_id, is_active);

-- Yêu cầu hủy hóa đơn (kể cả số hóa đơn giấy ghi sai của phiếu chưa duyệt)
CREATE TABLE finance.invoice_cancellations (
    id               uuid PRIMARY KEY DEFAULT uuidv7(),
    invoice_number   text NOT NULL CHECK (length(invoice_number) BETWEEN 1 AND 100),
    receipt_id       uuid REFERENCES finance.receipts (id) ON DELETE SET NULL,
    student_id       uuid REFERENCES academic.students (id),
    amount           bigint NOT NULL,
    reason           text NOT NULL CHECK (length(reason) BETWEEN 1 AND 1000),
    rejection_reason text CHECK (length(rejection_reason) <= 1000),
    -- TODO(files): ảnh minh chứng
    proof_url        text CHECK (length(proof_url) <= 1000),
    proof_file_id    uuid, -- FK tới core.files thêm khi gộp
    status           text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'approved', 'rejected')),
    requester_id     uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    approver_id      uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    approved_at      timestamptz,
    legacy_id        bigint UNIQUE,
    created_at       timestamptz NOT NULL DEFAULT now(),
    updated_at       timestamptz NOT NULL DEFAULT now()
);
-- Một hóa đơn chỉ có một yêu cầu hủy đang chờ
CREATE UNIQUE INDEX invoice_cancellations_pending_key ON finance.invoice_cancellations (receipt_id) WHERE status = 'pending';
CREATE INDEX invoice_cancellations_status_idx ON finance.invoice_cancellations (status, created_at DESC);

-- Hoàn phí / chuyển phí sang học viên khác / khất nợ (gia hạn đóng) / bảo lưu
CREATE TABLE finance.refund_requests (
    id                 uuid PRIMARY KEY DEFAULT uuidv7(),
    student_id         uuid NOT NULL REFERENCES academic.students (id),
    -- Khoản học phí lúc lập yêu cầu (khoản mới nhất của học viên)
    tuition_id         uuid REFERENCES finance.tuitions (id) ON DELETE SET NULL,
    type               text NOT NULL CHECK (type IN ('refund', 'transfer', 'extension', 'deferral')),
    total_paid         bigint NOT NULL DEFAULT 0,
    attended_lessons   integer NOT NULL DEFAULT 0 CHECK (attended_lessons >= 0),
    admin_fee          bigint NOT NULL DEFAULT 0 CHECK (admin_fee >= 0),
    refund_amount      bigint NOT NULL DEFAULT 0 CHECK (refund_amount >= 0),
    target_student_id  uuid REFERENCES academic.students (id),
    extended_due_date  date,
    defer_from         date,
    defer_to           date,
    reason             text NOT NULL CHECK (length(reason) BETWEEN 1 AND 2000),
    -- Hoàn tiền: lý do không chuyển phí được
    no_transfer_reason text CHECK (length(no_transfer_reason) <= 2000),
    -- TODO(files): ảnh bằng chứng chi tiền (ủy nhiệm chi / biên nhận)
    proof_url          text CHECK (length(proof_url) <= 1000),
    proof_file_id      uuid, -- FK tới core.files thêm khi gộp
    rejection_reason   text CHECK (length(rejection_reason) <= 1000),
    status             text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'approved', 'rejected')),
    requester_id       uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    approver_id        uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    approved_at        timestamptz,
    legacy_id          bigint UNIQUE,
    created_at         timestamptz NOT NULL DEFAULT now(),
    updated_at         timestamptz NOT NULL DEFAULT now(),
    CHECK (type <> 'transfer' OR (target_student_id IS NOT NULL AND target_student_id <> student_id)),
    CHECK (type <> 'extension' OR extended_due_date IS NOT NULL),
    CHECK (type <> 'deferral' OR (defer_from IS NOT NULL AND defer_to IS NOT NULL AND defer_to >= defer_from))
);
CREATE INDEX refund_requests_student_idx ON finance.refund_requests (student_id, type, status);
CREATE INDEX refund_requests_status_idx ON finance.refund_requests (status, created_at DESC);

-- Nhật ký liên hệ phụ huynh về khoản nợ: contacted (đã gọi) | reported_admin (đã báo Admin)
CREATE TABLE finance.tuition_contact_logs (
    id           uuid PRIMARY KEY DEFAULT uuidv7(),
    tuition_id   uuid NOT NULL REFERENCES finance.tuitions (id) ON DELETE CASCADE,
    user_id      uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    action       text NOT NULL CHECK (action IN ('contacted', 'reported_admin')),
    note         text CHECK (length(note) <= 1000),
    contacted_at timestamptz NOT NULL,
    created_at   timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX tuition_contact_logs_tuition_idx ON finance.tuition_contact_logs (tuition_id, action, contacted_at DESC);

-- Cổng thanh toán (SePay): một dòng cho mỗi nhà cung cấp; khóa bí mật mã hóa bằng secretbox.
CREATE TABLE finance.payment_providers (
    provider          text PRIMARY KEY CHECK (provider IN ('sepay')),
    webhook_name      text NOT NULL DEFAULT 'Xác Thực Thanh Toán Meducation' CHECK (length(webhook_name) <= 255),
    transaction_type  text NOT NULL DEFAULT 'in' CHECK (transaction_type IN ('in', 'out', 'all')),
    auth_method       text NOT NULL DEFAULT 'hmac_sha256' CHECK (auth_method IN ('hmac_sha256', 'api_key', 'none')),
    api_key_enc       bytea,
    secret_key_enc    bytea,
    is_active         boolean NOT NULL DEFAULT false,
    updated_by        uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    created_at        timestamptz NOT NULL DEFAULT now(),
    updated_at        timestamptz NOT NULL DEFAULT now()
);

-- Giao dịch ngân hàng nhận qua webhook; (provider, provider_txn_id) duy nhất nên webhook gửi lại không ghi 2 lần.
CREATE TABLE finance.bank_transactions (
    id                 uuid PRIMARY KEY DEFAULT uuidv7(),
    provider           text NOT NULL DEFAULT 'sepay',
    provider_txn_id    text NOT NULL CHECK (length(provider_txn_id) BETWEEN 1 AND 100),
    gateway            text CHECK (length(gateway) <= 100),
    transaction_date   timestamptz,
    account_number     text CHECK (length(account_number) <= 50),
    sub_account        text CHECK (length(sub_account) <= 100),
    transfer_type      text NOT NULL DEFAULT 'in',
    transfer_amount    bigint NOT NULL DEFAULT 0,
    accumulated        bigint NOT NULL DEFAULT 0,
    content            text,
    reference_code     text CHECK (length(reference_code) <= 100),
    raw_payload        jsonb,
    status             text NOT NULL DEFAULT 'pending'
        CHECK (status IN ('pending', 'ignored', 'rejected_account', 'matched', 'duplicate_manual', 'needs_review',
                          'overpaid', 'unmatched', 'manual_matched')),
    matched_student_id uuid REFERENCES academic.students (id) ON DELETE SET NULL,
    matched_tuition_id uuid REFERENCES finance.tuitions (id) ON DELETE SET NULL,
    matched_receipt_id uuid REFERENCES finance.receipts (id) ON DELETE SET NULL,
    response_message   text,
    created_at         timestamptz NOT NULL DEFAULT now(),
    updated_at         timestamptz NOT NULL DEFAULT now(),
    UNIQUE (provider, provider_txn_id)
);
CREATE INDEX bank_transactions_reference_idx ON finance.bank_transactions (reference_code);
CREATE INDEX bank_transactions_receipt_idx ON finance.bank_transactions (matched_receipt_id);
CREATE INDEX bank_transactions_time_idx ON finance.bank_transactions (created_at DESC, id DESC);

-- +goose Down
DROP TABLE finance.bank_transactions;
DROP TABLE finance.payment_providers;
DROP TABLE finance.tuition_contact_logs;
DROP TABLE finance.refund_requests;
DROP TABLE finance.invoice_cancellations;
DROP TABLE finance.invoice_ranges;
DROP TABLE finance.receipts;
DROP SEQUENCE finance.receipt_seq;
DROP TABLE finance.tuitions;
