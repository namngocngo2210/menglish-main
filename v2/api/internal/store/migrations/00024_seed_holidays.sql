-- Ngày nghỉ mẫu như HolidaySeeder của Laravel (idempotent theo mã). Ngày nghỉ theo chi nhánh chỉ gắn chi nhánh
-- có mã Q1 / Q7 nếu đã có; chưa có chi nhánh nào thì vẫn là ngày nghỉ theo chi nhánh, chưa áp dụng ở đâu.
-- +goose Up
INSERT INTO core.holidays (code, name, start_date, end_date, is_system_wide) VALUES
    ('HOL-2026-001', 'Tết Nguyên Đán 2026', '2026-02-08', '2026-02-14', true),
    ('HOL-2026-002', 'Ngày Giỗ Tổ Hùng Vương', '2026-04-18', '2026-04-18', false),
    ('HOL-2026-003', 'Ngày Giải phóng miền Nam', '2026-04-30', '2026-05-01', true)
ON CONFLICT (code) DO NOTHING;

INSERT INTO core.holiday_branches (holiday_id, branch_id)
SELECT h.id, b.id FROM core.holidays h JOIN core.branches b ON b.code IN ('Q1', 'Q7')
WHERE h.code = 'HOL-2026-002'
ON CONFLICT DO NOTHING;

-- +goose Down
DELETE FROM core.holidays WHERE code IN ('HOL-2026-001', 'HOL-2026-002', 'HOL-2026-003');
