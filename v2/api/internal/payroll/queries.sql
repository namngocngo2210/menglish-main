-- Phân hệ Lương & Phạt: kỳ lương, phiếu lương, biên bản vi phạm, đơn giá giáo viên, mốc hoa hồng, sổ hoa hồng.
-- Đọc trực tiếp bảng của phân hệ đã gộp (iam, crm.leads, finance.receipts / tuitions, academic.*, hr.kpi_*, ops.work_tasks).

-- ───────────── Tham số ─────────────

-- name: GetSettings :many
SELECT key, value FROM core.settings WHERE key = ANY (@keys::text[]);

-- name: UpsertSetting :exec
INSERT INTO core.settings (key, value, description, updated_by) VALUES (@key, @value, @description, @updated_by)
ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, description = coalesce(EXCLUDED.description, core.settings.description),
    updated_by = EXCLUDED.updated_by, updated_at = now();

-- name: SlaValue :one
SELECT coalesce(value, default_value)::int AS value FROM hr.sla_rules WHERE key = $1;

-- ───────────── Nhân sự ─────────────

-- name: StaffForPayroll :many
-- Nhân sự đang hoạt động + người còn ca dạy hợp lệ trong kỳ (extra), kèm vai trò, hồ sơ lương, chi nhánh chính.
SELECT u.id, u.full_name, coalesce(u.code::text, '')::text AS code, coalesce(u.email::text, '')::text AS email,
    u.status, u.created_at,
    coalesce((SELECT array_agg(ur.role ORDER BY ur.role) FROM iam.user_roles ur WHERE ur.user_id = u.id), '{}')::text[] AS roles,
    coalesce(p.contract_type, '')::text AS contract_type, p.contract_start_date, coalesce(p.base_salary, 0)::bigint AS base_salary,
    coalesce(p.hourly_rate, 0)::bigint AS hourly_rate, coalesce(p.academic_teaching, false)::bool AS academic_teaching,
    (SELECT ub.branch_id FROM iam.user_branches ub WHERE ub.user_id = u.id ORDER BY ub.is_primary DESC, ub.branch_id LIMIT 1) AS branch_id
FROM iam.users u
LEFT JOIN iam.staff_profiles p ON p.user_id = u.id
WHERE u.kind = 'staff' AND (u.status = 'active' OR u.id = ANY (@extra::uuid[]))
ORDER BY u.full_name, u.id;

-- name: UsersBrief :many
SELECT u.id, u.full_name, coalesce(u.code::text, '')::text AS code, coalesce(u.email::text, '')::text AS email,
    coalesce((SELECT array_agg(ur.role ORDER BY ur.role) FROM iam.user_roles ur WHERE ur.user_id = u.id), '{}')::text[] AS roles,
    coalesce((SELECT b.name FROM iam.user_branches ub JOIN core.branches b ON b.id = ub.branch_id
     WHERE ub.user_id = u.id ORDER BY ub.is_primary DESC, b.name LIMIT 1), '')::text AS branch_name
FROM iam.users u WHERE u.id = ANY (@ids::uuid[]);

-- name: UserBranchIDs :many
SELECT user_id, branch_id FROM iam.user_branches WHERE user_id = ANY (@ids::uuid[]);

-- name: ActiveStaffOptions :many
-- Nhân sự lập biên bản được (không gồm tài khoản học viên / phụ huynh).
SELECT u.id, u.full_name, coalesce(u.code::text, '')::text AS code, coalesce(u.email::text, '')::text AS email,
    coalesce((SELECT array_agg(ur.role ORDER BY ur.role) FROM iam.user_roles ur WHERE ur.user_id = u.id), '{}')::text[] AS roles
FROM iam.users u WHERE u.kind = 'staff' AND u.status = 'active' ORDER BY u.full_name, u.id;

-- name: StaffExists :one
SELECT EXISTS (SELECT 1 FROM iam.users WHERE id = $1 AND kind = 'staff')::bool;

-- name: ClassOptions :many
SELECT id, code::text AS code, name FROM academic.classes WHERE deleted_at IS NULL ORDER BY name, id;

-- name: ClassExists :one
SELECT EXISTS (SELECT 1 FROM academic.classes WHERE id = $1 AND deleted_at IS NULL)::bool;

-- name: BranchOptions :many
SELECT id, name FROM core.branches WHERE is_active ORDER BY name, id;

-- name: StaffWithRoles :many
-- Nhân sự đang hoạt động có một trong các vai trò (BXH Tư vấn / Học vụ, Đơn giá giáo viên).
SELECT u.id, u.full_name, coalesce(u.code::text, '')::text AS code, coalesce(u.email::text, '')::text AS email,
    coalesce((SELECT array_agg(ur.role ORDER BY ur.role) FROM iam.user_roles ur WHERE ur.user_id = u.id), '{}')::text[] AS roles,
    coalesce(p.contract_type, '')::text AS contract_type, coalesce(p.base_salary, 0)::bigint AS base_salary,
    coalesce(p.hourly_rate, 0)::bigint AS hourly_rate, coalesce(p.academic_teaching, false)::bool AS academic_teaching,
    (SELECT ub.branch_id FROM iam.user_branches ub WHERE ub.user_id = u.id ORDER BY ub.is_primary DESC, ub.branch_id LIMIT 1) AS branch_id,
    coalesce((SELECT b.name FROM iam.user_branches ub JOIN core.branches b ON b.id = ub.branch_id
     WHERE ub.user_id = u.id ORDER BY ub.is_primary DESC, b.name LIMIT 1), '')::text AS branch_name
FROM iam.users u
LEFT JOIN iam.staff_profiles p ON p.user_id = u.id
WHERE u.kind = 'staff' AND u.status = 'active'
  AND EXISTS (SELECT 1 FROM iam.user_roles ur WHERE ur.user_id = u.id AND ur.role = ANY (@roles::text[]))
ORDER BY u.full_name, u.id;

-- ───────────── Kỳ lương ─────────────

-- name: ListPeriods :many
SELECT p.* FROM hr.payroll_periods p
WHERE (@status::text = '' OR p.status = @status)
  AND (@search::text = '' OR p.code ILIKE '%' || @search || '%' OR p.title ILIKE '%' || @search || '%'
       OR EXISTS (SELECT 1 FROM hr.payroll_records r JOIN iam.users u ON u.id = r.user_id
                  WHERE r.period_id = p.id AND u.full_name ILIKE '%' || @search || '%'))
ORDER BY p.year DESC, p.month DESC, p.id DESC
LIMIT @lim OFFSET @off;

-- name: CountPeriods :one
SELECT count(*) FROM hr.payroll_periods p
WHERE (@status::text = '' OR p.status = @status)
  AND (@search::text = '' OR p.code ILIKE '%' || @search || '%' OR p.title ILIKE '%' || @search || '%'
       OR EXISTS (SELECT 1 FROM hr.payroll_records r JOIN iam.users u ON u.id = r.user_id
                  WHERE r.period_id = p.id AND u.full_name ILIKE '%' || @search || '%'));

-- name: ScopedPeriodTotals :many
-- Tổng của các phiếu trong phạm vi xem (own: chính mình; branch: nhân sự thuộc chi nhánh của tôi hoặc chính mình).
SELECT r.period_id, count(*)::int AS staff, coalesce(sum(r.actual_hours), 0)::float8 AS hours,
    coalesce(sum(r.net_salary), 0)::bigint AS amount
FROM hr.payroll_records r
WHERE r.period_id = ANY (@period_ids::uuid[])
  AND (@all_scope::bool OR r.user_id = @me
       OR EXISTS (SELECT 1 FROM iam.user_branches ub WHERE ub.user_id = r.user_id AND ub.branch_id = ANY (@branches::uuid[])))
GROUP BY r.period_id;

-- name: AllPeriods :many
SELECT * FROM hr.payroll_periods ORDER BY year DESC, month DESC, id DESC;

-- name: GetPeriod :one
SELECT * FROM hr.payroll_periods WHERE id = $1;

-- name: LockPeriod :one
SELECT * FROM hr.payroll_periods WHERE id = $1 FOR UPDATE;

-- name: PeriodByMonth :one
SELECT * FROM hr.payroll_periods WHERE year = @year AND month = @month;

-- name: InsertPeriod :one
INSERT INTO hr.payroll_periods (code, title, month, year, start_date, end_date, status, created_by)
VALUES (@code, @title, @month, @year, @start_date, @end_date, 'draft', @created_by)
RETURNING *;

-- name: FinishCalculation :exec
UPDATE hr.payroll_periods p SET status = 'reviewing', calculated_at = @calculated_at,
    total_staff = t.staff, total_hours = t.hours, total_amount = t.amount, updated_at = now()
FROM (SELECT count(*)::int AS staff, coalesce(sum(actual_hours), 0)::numeric AS hours, coalesce(sum(net_salary), 0)::bigint AS amount
      FROM hr.payroll_records WHERE period_id = @id) t
WHERE p.id = @id;

-- name: RefreshPeriodTotals :exec
UPDATE hr.payroll_periods p SET total_staff = t.staff, total_hours = t.hours, total_amount = t.amount, updated_at = now()
FROM (SELECT count(*)::int AS staff, coalesce(sum(actual_hours), 0)::numeric AS hours, coalesce(sum(net_salary), 0)::bigint AS amount
      FROM hr.payroll_records WHERE period_id = @id) t
WHERE p.id = @id;

-- name: ApprovePeriod :exec
UPDATE hr.payroll_periods SET status = 'approved', approved_at = @at, approved_by = @by, updated_at = now() WHERE id = @id;

-- name: MarkPeriodPaid :exec
UPDATE hr.payroll_periods SET status = 'paid', paid_at = @at, paid_by = @by, updated_at = now() WHERE id = @id;

-- name: LockedOn :one
-- Ngày thuộc một kỳ lương đã duyệt / đã chi trả.
SELECT EXISTS (SELECT 1 FROM hr.payroll_periods WHERE status IN ('approved', 'paid') AND start_date <= @day AND end_date >= @day)::bool;

-- name: OpenPeriodsCount :one
SELECT count(*) FROM hr.payroll_periods WHERE status IN ('draft', 'reviewing');

-- name: LockedPeriodOfMonth :one
SELECT * FROM hr.payroll_periods WHERE year = @year AND month = @month AND status IN ('approved', 'paid');

-- name: SalaryOfPeriod :one
-- Tổng thực lĩnh / số phiếu của kỳ, lọc theo chi nhánh chính của nhân sự (nil = toàn hệ thống).
SELECT count(*)::int AS staff, coalesce(sum(r.net_salary), 0)::bigint AS amount
FROM hr.payroll_records r
WHERE r.period_id = @period_id
  AND (sqlc.narg(branch)::uuid IS NULL OR
       (SELECT ub.branch_id FROM iam.user_branches ub WHERE ub.user_id = r.user_id ORDER BY ub.is_primary DESC, ub.branch_id LIMIT 1) = sqlc.narg(branch)::uuid);

-- name: PeriodsForCalendar :many
-- Kỳ chưa khóa hoặc kỳ vừa kết thúc (nhắc lịch chốt lương / khung trả lương).
SELECT * FROM hr.payroll_periods WHERE end_date >= @since ORDER BY start_date;

-- ───────────── Phiếu lương ─────────────

-- name: PeriodRecords :many
SELECT r.*, u.full_name, coalesce(u.code::text, '')::text AS user_code, coalesce(u.email::text, '')::text AS user_email,
    coalesce((SELECT b.name FROM iam.user_branches ub JOIN core.branches b ON b.id = ub.branch_id
     WHERE ub.user_id = r.user_id ORDER BY ub.is_primary DESC, b.name LIMIT 1), '')::text AS branch_name,
    (SELECT ub.branch_id FROM iam.user_branches ub WHERE ub.user_id = r.user_id ORDER BY ub.is_primary DESC, ub.branch_id LIMIT 1) AS branch_id
FROM hr.payroll_records r JOIN iam.users u ON u.id = r.user_id
WHERE r.period_id = @period_id
  AND (@all_scope::bool OR r.user_id = @me
       OR EXISTS (SELECT 1 FROM iam.user_branches ub WHERE ub.user_id = r.user_id AND ub.branch_id = ANY (@branches::uuid[])))
ORDER BY u.full_name, r.id;

-- name: GetRecord :one
SELECT r.*, u.full_name, coalesce(u.code::text, '')::text AS user_code, coalesce(u.email::text, '')::text AS user_email,
    coalesce((SELECT b.name FROM iam.user_branches ub JOIN core.branches b ON b.id = ub.branch_id
     WHERE ub.user_id = r.user_id ORDER BY ub.is_primary DESC, b.name LIMIT 1), '')::text AS branch_name
FROM hr.payroll_records r JOIN iam.users u ON u.id = r.user_id
WHERE r.id = $1;

-- name: ExistingRecords :many
SELECT * FROM hr.payroll_records WHERE period_id = $1;

-- name: LockRecord :one
SELECT * FROM hr.payroll_records WHERE id = $1 FOR UPDATE;

-- name: UpsertRecord :one
INSERT INTO hr.payroll_records (
    period_id, user_id, department, employee_type, salary_role, base_salary, actual_hours, teaching_sessions, teaching_salary,
    teaching_concurrent, teaching_kpi_bonus, retention_base_students, retention_students, retention_tier, kpi_source,
    kpi_manual_amount, kpi_score, kpi_bonus, foreign_session_pay, foreign_teacher_sessions_count, renew_bonus,
    commission_bonus, commission_base, commission_clawback, commission_deferred, commission_percent, commission_closed_count,
    allowance, other_bonus, other_deduction, insurance_deduction, union_deduction, tax_deduction, penalty_deduction,
    foreign_teacher_deduction, net_salary, notes, adjustment_notes, manual_lines, calculation_details
) VALUES (
    @period_id, @user_id, @department, @employee_type, @salary_role, @base_salary, @actual_hours, @teaching_sessions, @teaching_salary,
    @teaching_concurrent, @teaching_kpi_bonus, @retention_base_students, @retention_students, @retention_tier, @kpi_source,
    @kpi_manual_amount, @kpi_score, @kpi_bonus, @foreign_session_pay, @foreign_teacher_sessions_count, @renew_bonus,
    @commission_bonus, @commission_base, @commission_clawback, @commission_deferred, @commission_percent, @commission_closed_count,
    @allowance, @other_bonus, @other_deduction, @insurance_deduction, @union_deduction, @tax_deduction, @penalty_deduction,
    @foreign_teacher_deduction, @net_salary, @notes, @adjustment_notes, @manual_lines, @calculation_details
)
ON CONFLICT (period_id, user_id) DO UPDATE SET
    department = EXCLUDED.department, employee_type = EXCLUDED.employee_type, salary_role = EXCLUDED.salary_role,
    base_salary = EXCLUDED.base_salary, actual_hours = EXCLUDED.actual_hours, teaching_sessions = EXCLUDED.teaching_sessions,
    teaching_salary = EXCLUDED.teaching_salary, teaching_concurrent = EXCLUDED.teaching_concurrent,
    teaching_kpi_bonus = EXCLUDED.teaching_kpi_bonus, retention_base_students = EXCLUDED.retention_base_students,
    retention_students = EXCLUDED.retention_students, retention_tier = EXCLUDED.retention_tier, kpi_source = EXCLUDED.kpi_source,
    kpi_manual_amount = EXCLUDED.kpi_manual_amount, kpi_score = EXCLUDED.kpi_score, kpi_bonus = EXCLUDED.kpi_bonus,
    foreign_session_pay = EXCLUDED.foreign_session_pay, foreign_teacher_sessions_count = EXCLUDED.foreign_teacher_sessions_count,
    renew_bonus = EXCLUDED.renew_bonus, commission_bonus = EXCLUDED.commission_bonus, commission_base = EXCLUDED.commission_base,
    commission_clawback = EXCLUDED.commission_clawback, commission_deferred = EXCLUDED.commission_deferred,
    commission_percent = EXCLUDED.commission_percent, commission_closed_count = EXCLUDED.commission_closed_count,
    allowance = EXCLUDED.allowance, other_bonus = EXCLUDED.other_bonus, other_deduction = EXCLUDED.other_deduction,
    insurance_deduction = EXCLUDED.insurance_deduction, union_deduction = EXCLUDED.union_deduction,
    tax_deduction = EXCLUDED.tax_deduction, penalty_deduction = EXCLUDED.penalty_deduction,
    foreign_teacher_deduction = EXCLUDED.foreign_teacher_deduction, net_salary = EXCLUDED.net_salary, notes = EXCLUDED.notes,
    adjustment_notes = EXCLUDED.adjustment_notes, manual_lines = EXCLUDED.manual_lines,
    calculation_details = EXCLUDED.calculation_details, updated_at = now()
RETURNING *;

-- name: UpdateRecordInputs :one
-- Lưu khoản nhập tay và các khoản phụ thuộc (PayrollRecord::applyManualInputs).
UPDATE hr.payroll_records SET retention_tier = @retention_tier, kpi_manual_amount = @kpi_manual_amount,
    foreign_session_pay = @foreign_session_pay, tax_deduction = @tax_deduction, manual_lines = @manual_lines,
    adjustment_notes = @adjustment_notes, notes = @notes, allowance = @allowance, other_deduction = @other_deduction,
    kpi_bonus = @kpi_bonus, teaching_kpi_bonus = @teaching_kpi_bonus, insurance_deduction = @insurance_deduction,
    union_deduction = @union_deduction, net_salary = @net_salary, updated_at = now()
WHERE id = @id
RETURNING *;

-- name: DeleteRecordsExcept :exec
DELETE FROM hr.payroll_records WHERE period_id = @period_id AND NOT (user_id = ANY (@keep::uuid[]));

-- name: SetRecordsStatus :exec
UPDATE hr.payroll_records SET status = @status, updated_at = now() WHERE period_id = @period_id;

-- name: MyLockedRecords :many
SELECT r.*, p.code AS period_code, p.title AS period_title, p.month, p.year, p.start_date, p.end_date, p.status AS period_status
FROM hr.payroll_records r JOIN hr.payroll_periods p ON p.id = r.period_id
WHERE r.user_id = $1 AND p.status IN ('approved', 'paid')
ORDER BY p.year DESC, p.month DESC, p.id DESC;

-- name: MissingStaff :many
-- Nhân sự đang làm (không phải Admin) chưa có phiếu trong kỳ, vào làm trước cuối kỳ.
SELECT u.id, u.full_name, coalesce(u.code::text, '')::text AS code,
    coalesce((SELECT array_agg(ur.role ORDER BY ur.role) FROM iam.user_roles ur WHERE ur.user_id = u.id), '{}')::text[] AS roles,
    coalesce(p.contract_type, '')::text AS contract_type, coalesce(p.base_salary, 0)::bigint AS base_salary,
    coalesce(p.academic_teaching, false)::bool AS academic_teaching
FROM iam.users u LEFT JOIN iam.staff_profiles p ON p.user_id = u.id
WHERE u.kind = 'staff' AND u.status = 'active'
  AND NOT EXISTS (SELECT 1 FROM iam.user_roles ur WHERE ur.user_id = u.id AND ur.role = 'admin')
  AND NOT EXISTS (SELECT 1 FROM hr.payroll_records r WHERE r.period_id = @period_id AND r.user_id = u.id)
  AND u.created_at < @end_at
  AND (p.contract_start_date IS NULL OR p.contract_start_date <= @end_day)
ORDER BY u.full_name, u.id;

-- name: RecordsDeductionMismatch :one
-- Tiền phạt trên phiếu khác tổng biên bản còn gắn với phiếu (nộp trực tiếp / miễn phạt sau lần tính).
SELECT EXISTS (
    SELECT 1 FROM hr.payroll_records r
    WHERE r.period_id = @period_id
      AND r.penalty_deduction <> coalesce((SELECT sum(x.amount) FROM hr.penalties x WHERE x.payroll_record_id = r.id), 0)
)::bool;

-- name: ChangesSinceCalculation :one
-- Dữ liệu ảnh hưởng lương đổi sau lần tính gần nhất (PayrollPeriod::hasChangesSinceCalculation, phần không thuộc Chấm công).
SELECT (
    EXISTS (SELECT 1 FROM hr.penalties x1 WHERE x1.violation_date BETWEEN @start_day AND @end_day AND x1.status = 'fined' AND x1.updated_at > @since)
    OR EXISTS (SELECT 1 FROM hr.teacher_hourly_rates x2 WHERE x2.updated_at > @since)
    OR EXISTS (SELECT 1 FROM hr.teacher_rates x3 WHERE x3.updated_at > @since)
    OR EXISTS (SELECT 1 FROM core.settings x4 WHERE x4.key LIKE 'payroll\_%' AND x4.updated_at > @since)
    OR EXISTS (SELECT 1 FROM hr.kpi_criteria x5 WHERE x5.updated_at > @since)
    OR EXISTS (SELECT 1 FROM hr.penalties x6 JOIN hr.payroll_records r ON r.id = x6.payroll_record_id
               WHERE r.period_id = @period_id AND x6.updated_at > @since)
    OR EXISTS (SELECT 1 FROM finance.receipts x7 WHERE x7.approved_at BETWEEN @start_at AND @end_at AND x7.updated_at > @since)
    OR EXISTS (SELECT 1 FROM hr.commission_adjustments x8 WHERE x8.settled_at IS NULL AND x8.payroll_record_id IS NULL
               AND x8.created_at <= @end_at AND x8.created_at > @since)
    OR EXISTS (SELECT 1 FROM hr.commission_items x9 WHERE x9.settled_at IS NULL AND x9.updated_at > @since AND x9.earned_period_end <= @end_day)
    OR EXISTS (SELECT 1 FROM hr.commission_items x10 WHERE x10.settled_at IS NULL AND x10.status <> 'void' AND x10.payroll_record_id IS NULL
               AND (EXISTS (SELECT 1 FROM crm.leads l WHERE l.id = x11.lead_id AND l.updated_at > @since)
                    OR EXISTS (SELECT 1 FROM ops.work_tasks t WHERE t.student_id = x12.student_id AND t.care_milestone IS NOT NULL
                               AND t.updated_at > @since)))
    OR EXISTS (SELECT 1 FROM hr.kpi_sheets x13 WHERE x13.month = @month AND x13.year = @year AND x13.updated_at > @since)
    OR (EXISTS (SELECT 1 FROM hr.payroll_records r WHERE r.period_id = @period_id AND r.teaching_concurrent)
        AND (EXISTS (SELECT 1 FROM finance.tuitions x15 WHERE x15.updated_at > @since)
             OR EXISTS (SELECT 1 FROM academic.enrollments x16 WHERE x16.updated_at > @since)))
)::bool;

-- ───────────── Biên bản vi phạm ─────────────

-- name: ListPenalties :many
SELECT x.*, u.full_name AS user_name, coalesce(u.code::text, '')::text AS user_code, c.name AS class_name,
    rp.full_name AS reporter_name, dc.full_name AS decider_name, rm.full_name AS remedier_name
FROM hr.penalties x
JOIN iam.users u ON u.id = x.user_id
LEFT JOIN academic.classes c ON c.id = x.class_id
LEFT JOIN iam.users rp ON rp.id = x.reporter_id
LEFT JOIN iam.users dc ON dc.id = x.decided_by
LEFT JOIN iam.users rm ON rm.id = x.remedied_by
WHERE (sqlc.narg(owner)::uuid IS NULL OR x.user_id = sqlc.narg(owner)::uuid)
  AND (@search::text = '' OR x.code ILIKE '%' || @search || '%' OR x.violation_type ILIKE '%' || @search || '%'
       OR u.full_name ILIKE '%' || @search || '%' OR u.code::text ILIKE '%' || @search || '%')
  AND (@status::text = ''
       OR (@status = 'overdue' AND x.status = 'fined' AND x.due_date < @today)
       OR (@status = 'open' AND x.status IN ('pending', 'explained', 'confirmed', 'fined'))
       OR x.status = @status)
  AND (@category::text = '' OR x.error_category = @category)
  AND (@step::text = ''
       OR (@step = 'recorded' AND x.status IN ('pending', 'explained'))
       OR (@step = 'confirmed' AND x.status = 'confirmed')
       OR (@step = 'fined' AND x.status = 'fined')
       OR (@step = 'paid' AND x.status IN ('paid', 'deducted') AND x.remedied_at IS NULL)
       OR (@step = 'remedied' AND x.status IN ('paid', 'deducted') AND x.remedied_at IS NOT NULL)
       OR (@step = 'resolved' AND x.status = 'resolved')
       OR (@step = 'cancelled' AND x.status = 'cancelled'))
  AND (sqlc.narg(from_day)::date IS NULL OR x.violation_date >= sqlc.narg(from_day)::date)
  AND (sqlc.narg(to_day)::date IS NULL OR x.violation_date <= sqlc.narg(to_day)::date)
ORDER BY x.created_at DESC, x.id DESC
LIMIT @lim OFFSET @off;

-- name: CountPenalties :one
SELECT count(*) FROM hr.penalties x JOIN iam.users u ON u.id = x.user_id
WHERE (sqlc.narg(owner)::uuid IS NULL OR x.user_id = sqlc.narg(owner)::uuid)
  AND (@search::text = '' OR x.code ILIKE '%' || @search || '%' OR x.violation_type ILIKE '%' || @search || '%'
       OR u.full_name ILIKE '%' || @search || '%' OR u.code::text ILIKE '%' || @search || '%')
  AND (@status::text = ''
       OR (@status = 'overdue' AND x.status = 'fined' AND x.due_date < @today)
       OR (@status = 'open' AND x.status IN ('pending', 'explained', 'confirmed', 'fined'))
       OR x.status = @status)
  AND (@category::text = '' OR x.error_category = @category)
  AND (@step::text = ''
       OR (@step = 'recorded' AND x.status IN ('pending', 'explained'))
       OR (@step = 'confirmed' AND x.status = 'confirmed')
       OR (@step = 'fined' AND x.status = 'fined')
       OR (@step = 'paid' AND x.status IN ('paid', 'deducted') AND x.remedied_at IS NULL)
       OR (@step = 'remedied' AND x.status IN ('paid', 'deducted') AND x.remedied_at IS NOT NULL)
       OR (@step = 'resolved' AND x.status = 'resolved')
       OR (@step = 'cancelled' AND x.status = 'cancelled'))
  AND (sqlc.narg(from_day)::date IS NULL OR x.violation_date >= sqlc.narg(from_day)::date)
  AND (sqlc.narg(to_day)::date IS NULL OR x.violation_date <= sqlc.narg(to_day)::date);

-- name: PenaltyCounts :one
SELECT count(*) FILTER (WHERE status = 'pending')::int AS pending,
    count(*) FILTER (WHERE status IN ('explained', 'confirmed'))::int AS deciding,
    count(*) FILTER (WHERE status = 'fined')::int AS fined,
    count(*) FILTER (WHERE status = 'fined' AND due_date < @today)::int AS overdue
FROM hr.penalties WHERE sqlc.narg(owner)::uuid IS NULL OR user_id = sqlc.narg(owner)::uuid;

-- name: GetPenalty :one
SELECT x.*, u.full_name AS user_name,
    coalesce((SELECT p.status FROM hr.payroll_records r JOIN hr.payroll_periods p ON p.id = r.period_id WHERE r.id = x.payroll_record_id), '')::text AS period_status
FROM hr.penalties x JOIN iam.users u ON u.id = x.user_id WHERE x.id = $1;

-- name: LockPenalty :one
SELECT * FROM hr.penalties WHERE id = $1 FOR UPDATE;

-- name: NextPenaltyNumber :one
-- Số kế tiếp của mã BB-YYYY-NNN (khóa theo năm để không trùng mã).
SELECT (coalesce(max(substring(code FROM '^BB-[0-9]{4}-([0-9]+)$')::int), 0) + 1)::int
FROM hr.penalties WHERE code LIKE 'BB-' || @year::text || '-%';

-- name: LockPenaltyCodes :exec
SELECT pg_advisory_xact_lock(hashtext('hr.penalties.code'));

-- name: InsertPenalty :one
INSERT INTO hr.penalties (code, user_id, class_id, work_task_id, big_test_id, auto_source, auto_key, violation_type, error_category,
    violation_date, violation_at, amount, reporter_id, status, notes, evidence_file_id)
VALUES (@code, @user_id, @class_id, @work_task_id, @big_test_id, @auto_source, @auto_key, @violation_type, @error_category,
    @violation_date, @violation_at, @amount, @reporter_id, 'pending', @notes, @evidence_file_id)
ON CONFLICT (auto_source, auto_key) WHERE auto_key IS NOT NULL DO NOTHING
RETURNING *;

-- name: SetPenaltyEvidence :exec
UPDATE hr.penalties SET evidence_file_id = @file_id, updated_at = now() WHERE id = @id;

-- name: ExplainPenalty :exec
UPDATE hr.penalties SET explanation = @explanation, explained_at = @at, status = 'explained', updated_at = now() WHERE id = @id;

-- name: DecidePenalty :exec
UPDATE hr.penalties SET status = @status, amount = @amount, due_date = @due_date, decided_by = @decided_by, decided_at = @at,
    decision_note = @decision_note, updated_at = now()
WHERE id = @id;

-- name: PayPenalty :exec
UPDATE hr.penalties SET status = 'paid', paid_at = @at, payroll_record_id = NULL, notes = @notes, updated_at = now() WHERE id = @id;

-- name: ResolvePenalty :exec
UPDATE hr.penalties SET status = 'resolved', payroll_record_id = NULL, updated_at = now() WHERE id = @id;

-- name: CancelPenalty :exec
UPDATE hr.penalties SET status = 'cancelled', updated_at = now() WHERE id = @id;

-- name: RemedyPenalty :exec
UPDATE hr.penalties SET remedied_at = @at, remedied_by = @by, remedy_note = @note, updated_at = now() WHERE id = @id;

-- name: DeductiblePenalties :many
-- Biên bản trừ vào kỳ (Penalty::scopeDeductibleFor): đã quyết phạt, quá hạn nộp (hạn ≤ cuối kỳ và trước hôm nay) hoặc
-- biên bản cũ chưa có hạn mà ngày vi phạm trong kỳ; chưa gắn kỳ khác.
SELECT x.id, x.user_id, x.amount FROM hr.penalties x
WHERE x.status = 'fined'
  AND ((x.due_date IS NOT NULL AND x.due_date <= @end_day AND x.due_date < @today)
       OR (x.due_date IS NULL AND x.violation_date BETWEEN @start_day AND @end_day))
  AND (x.payroll_record_id IS NULL
       OR x.payroll_record_id IN (SELECT r.id FROM hr.payroll_records r WHERE r.period_id = @period_id))
ORDER BY x.user_id, x.id;

-- name: UnlinkPeriodPenalties :exec
UPDATE hr.penalties SET payroll_record_id = NULL
WHERE payroll_record_id IN (SELECT r.id FROM hr.payroll_records r WHERE r.period_id = $1);

-- name: LinkPenalties :exec
UPDATE hr.penalties SET payroll_record_id = @record_id WHERE id = ANY (@ids::uuid[]);

-- name: DeductPeriodPenalties :exec
UPDATE hr.penalties SET status = 'deducted', updated_at = now()
WHERE status = 'fined' AND payroll_record_id IN (SELECT r.id FROM hr.payroll_records r WHERE r.period_id = $1);

-- name: RecordPenalties :many
SELECT x.id, x.code, x.violation_type, x.violation_date, x.amount, x.status, x.due_date FROM hr.penalties x
WHERE x.payroll_record_id = $1 ORDER BY x.violation_date, x.id;

-- name: PenaltiesDueSoon :many
-- Biên bản đã quyết phạt, hạn nộp hôm nay hoặc ngày mai (nhắc nhân sự nộp).
SELECT x.id, x.code, x.user_id, x.amount, x.due_date FROM hr.penalties x
WHERE x.status = 'fined' AND x.due_date BETWEEN @today AND @tomorrow ORDER BY x.due_date, x.id;

-- name: CountAutoPenalties :one
-- Số lần tái phạm của SLA tự lập biên bản trong khoảng (không tính biên bản đã hủy).
SELECT count(*)::int FROM hr.penalties
WHERE user_id = @user_id AND auto_source = @auto_source AND status <> 'cancelled' AND created_at >= @since;

-- ───────────── Đơn giá giáo viên ─────────────

-- name: ListTeacherRates :many
SELECT * FROM hr.teacher_rates ORDER BY sort_order, created_at, id;

-- name: DeleteTeacherRates :exec
DELETE FROM hr.teacher_rates;

-- name: InsertTeacherRate :exec
INSERT INTO hr.teacher_rates (rank_title, criteria, communication_rate, ielts_rate, sort_order)
VALUES (@rank_title, @criteria, @communication_rate, @ielts_rate, @sort_order);

-- name: HourlyRatesFor :many
SELECT * FROM hr.teacher_hourly_rates WHERE user_id = ANY (@ids::uuid[]) ORDER BY user_id, effective_from DESC;

-- name: HourlyRateHistory :many
SELECT h.*, u.full_name AS user_name, cb.full_name AS creator_name,
    coalesce(nx.next_from, '9999-12-31'::date)::date AS next_from -- 9999-12-31 = chưa có phiên bản sau
FROM hr.teacher_hourly_rates h JOIN iam.users u ON u.id = h.user_id LEFT JOIN iam.users cb ON cb.id = h.created_by
LEFT JOIN LATERAL (SELECT n.effective_from AS next_from FROM hr.teacher_hourly_rates n
                   WHERE n.user_id = h.user_id AND n.effective_from > h.effective_from ORDER BY n.effective_from LIMIT 1) nx ON true
WHERE (sqlc.narg(user_id)::uuid IS NULL OR h.user_id = sqlc.narg(user_id)::uuid)
ORDER BY h.effective_from DESC, h.created_at DESC, h.id DESC
LIMIT @lim OFFSET @off;

-- name: CountHourlyRates :one
SELECT count(*) FROM hr.teacher_hourly_rates WHERE sqlc.narg(user_id)::uuid IS NULL OR user_id = sqlc.narg(user_id)::uuid;

-- name: HourlyRateExists :one
SELECT EXISTS (SELECT 1 FROM hr.teacher_hourly_rates WHERE user_id = @user_id AND effective_from = @effective_from)::bool;

-- name: InsertHourlyRate :one
INSERT INTO hr.teacher_hourly_rates (user_id, rate, rate_unit, teacher_type, effective_from, note, created_by)
VALUES (@user_id, @rate, @rate_unit, @teacher_type, @effective_from, @note, @created_by)
RETURNING *;

-- ───────────── Mốc hoa hồng ─────────────

-- name: TiersAt :many
-- Mốc hiệu lực tại một ngày, theo min_students tăng dần.
SELECT * FROM hr.commission_tiers
WHERE (effective_from IS NULL OR effective_from <= @day) AND (effective_to IS NULL OR effective_to >= @day)
ORDER BY min_students, id;

-- name: TierHistory :many
SELECT t.*, u.full_name AS creator_name FROM hr.commission_tiers t LEFT JOIN iam.users u ON u.id = t.created_by
ORDER BY coalesce(t.effective_from, '2000-01-01'::date) DESC, t.created_at DESC, t.id DESC
LIMIT @lim OFFSET @off;

-- name: CountTiers :one
SELECT count(*) FROM hr.commission_tiers;

-- name: LockTier :one
SELECT * FROM hr.commission_tiers WHERE id = $1 FOR UPDATE;

-- name: InsertTier :one
INSERT INTO hr.commission_tiers (tier_name, min_students, max_students, new_sale_percent, effective_from, replaces_id, created_by)
VALUES (@tier_name, @min_students, @max_students, @new_sale_percent, @effective_from, @replaces_id, @created_by)
RETURNING *;

-- name: UpdateTierInPlace :one
UPDATE hr.commission_tiers SET tier_name = @tier_name, min_students = @min_students, max_students = @max_students,
    new_sale_percent = @new_sale_percent, effective_from = @effective_from, updated_at = now()
WHERE id = @id RETURNING *;

-- name: CloseTier :exec
UPDATE hr.commission_tiers SET effective_to = @effective_to, updated_at = now() WHERE id = @id;

-- name: DeleteTier :exec
DELETE FROM hr.commission_tiers WHERE id = $1;

-- ───────────── Hoa hồng ─────────────

-- name: CommissionableReceipts :many
-- Phiếu thu làm căn cứ hoa hồng trong [start, end] (SalesCommissionService::commissionableReceipts): phiếu đã duyệt, số
-- dương, không phải nhận chuyển nhượng (XFER-IN), của học viên chốt từ khách CRM, thuộc khoản học phí ĐẦU TIÊN.
-- rank = thứ tự chốt của khách trong tháng chốt của người nhận hoa hồng (theo giờ chốt rồi id).
WITH owners AS (
    SELECT DISTINCT ON (l.converted_student_id) l.id AS lead_id, l.converted_student_id AS student_id,
        coalesce(l.commission_user_id, l.assigned_user_id) AS owner_id, l.converted_at
    FROM crm.leads l
    WHERE l.converted_student_id IS NOT NULL AND coalesce(l.commission_user_id, l.assigned_user_id) IS NOT NULL
      AND l.deleted_at IS NULL
    ORDER BY l.converted_student_id, l.created_at DESC, l.id DESC
), ranks AS (
    SELECT l.id AS lead_id, row_number() OVER (
        PARTITION BY coalesce(l.commission_user_id, l.assigned_user_id),
            date_trunc('month', coalesce(l.converted_at, l.created_at) AT TIME ZONE 'Asia/Ho_Chi_Minh')
        ORDER BY coalesce(l.converted_at, l.created_at), l.id) AS rank
    FROM crm.leads l
    WHERE l.converted_student_id IS NOT NULL AND coalesce(l.commission_user_id, l.assigned_user_id) IS NOT NULL
      AND l.deleted_at IS NULL
), ordered_tuitions AS (
    SELECT ft.id, ft.student_id, ft.created_at, row_number() OVER (PARTITION BY ft.student_id ORDER BY ft.created_at, ft.id) AS n
    FROM finance.tuitions ft WHERE ft.student_id IN (SELECT student_id FROM owners)
)
SELECT r.id, r.code, r.amount, r.surcharge_amount, r.approved_at::timestamptz AS approved_at, r.created_at,
    o.student_id::uuid AS student_id, s.full_name AS student_name, o.lead_id, o.owner_id::uuid AS owner_id, o.converted_at,
    coalesce(rk.rank, 1)::int AS closing_rank,
    t.final_amount AS tuition_final, t.other_fees AS tuition_other_fees
FROM finance.receipts r
LEFT JOIN finance.tuitions t ON t.id = r.tuition_id
JOIN owners o ON o.student_id = coalesce(r.student_id, t.student_id)
JOIN academic.students s ON s.id = o.student_id
LEFT JOIN ranks rk ON rk.lead_id = o.lead_id
WHERE r.status = 'approved' AND r.amount > 0
  AND (r.transaction_code IS NULL OR r.transaction_code NOT LIKE 'XFER-IN-%')
  AND r.approved_at BETWEEN @start_at AND @end_at
  AND (sqlc.narg(owner)::uuid IS NULL OR o.owner_id = sqlc.narg(owner)::uuid)
  AND (
      (r.tuition_id IS NOT NULL AND r.tuition_id = (SELECT f.id FROM ordered_tuitions f WHERE f.student_id = o.student_id AND f.n = 1))
      OR (r.tuition_id IS NULL AND NOT EXISTS (
          SELECT 1 FROM ordered_tuitions f WHERE f.student_id = o.student_id AND f.n = 2 AND f.created_at <= r.created_at))
  )
ORDER BY r.approved_at, r.id;

-- name: ClosedCountsBySales :many
-- Số HS chốt trong khoảng theo người nhận hoa hồng.
SELECT coalesce(commission_user_id, assigned_user_id)::uuid AS owner_id, count(*)::int AS closed
FROM crm.leads
WHERE converted_student_id IS NOT NULL AND converted_at BETWEEN @start_at AND @end_at AND deleted_at IS NULL
  AND coalesce(commission_user_id, assigned_user_id) IS NOT NULL
GROUP BY 1;

-- name: CommissionItemsByReceipts :many
SELECT i.*, coalesce((SELECT p.status FROM hr.payroll_records r JOIN hr.payroll_periods p ON p.id = r.period_id
             WHERE r.id = i.payroll_record_id), '')::text AS period_status
FROM hr.commission_items i WHERE i.receipt_id = ANY (@ids::uuid[]);

-- name: InsertCommissionItem :exec
INSERT INTO hr.commission_items (user_id, receipt_id, student_id, lead_id, base_amount, percent, amount, closed_count,
    closing_rank, earned_period_start, earned_period_end, closed_at, status)
VALUES (@user_id, @receipt_id, @student_id, @lead_id, @base_amount, @percent, @amount, @closed_count,
    @closing_rank, @earned_period_start, @earned_period_end, @closed_at, 'deferred');

-- name: UpdateCommissionItem :exec
UPDATE hr.commission_items SET user_id = @user_id, student_id = @student_id, lead_id = @lead_id, base_amount = @base_amount,
    percent = @percent, amount = @amount, closed_count = @closed_count, closing_rank = @closing_rank,
    earned_period_start = @earned_period_start, earned_period_end = @earned_period_end, closed_at = @closed_at, updated_at = now()
WHERE id = @id;

-- name: VoidStaleItems :exec
-- Phiếu phát sinh trong kỳ nhưng không còn là căn cứ → hủy khoản chưa trả (khoản đã trả không sửa).
UPDATE hr.commission_items i SET status = 'void', payroll_record_id = NULL,
    deferred_reason = 'Phiếu thu không còn là căn cứ hoa hồng', updated_at = now()
WHERE i.settled_at IS NULL AND i.status <> 'void' AND i.earned_period_start = @start_day
  AND NOT (i.receipt_id = ANY (@keep::uuid[]))
  AND NOT EXISTS (SELECT 1 FROM hr.payroll_records r JOIN hr.payroll_periods p ON p.id = r.period_id
                  WHERE r.id = i.payroll_record_id AND p.status IN ('approved', 'paid'));

-- name: VoidCancelledReceiptItems :exec
-- Khoản hoãn từ kỳ trước mà phiếu đã bị hủy / không còn duyệt → hủy.
UPDATE hr.commission_items i SET status = 'void', deferred_reason = 'Phiếu thu đã bị hủy / không còn duyệt', updated_at = now()
FROM finance.receipts r
WHERE r.id = i.receipt_id AND r.status <> 'approved' AND i.settled_at IS NULL AND i.status <> 'void'
  AND i.payroll_record_id IS NULL AND i.earned_period_end < @start_day;

-- name: OpenUnlinkedItems :many
-- Khoản chưa trả, chưa gắn phiếu lương, phát sinh tới cuối kỳ (xét gate kép).
SELECT * FROM hr.commission_items
WHERE settled_at IS NULL AND status <> 'void' AND payroll_record_id IS NULL AND earned_period_end <= @end_day
ORDER BY earned_period_start, id;

-- name: CareMilestones :many
-- Số mốc chăm sóc tháng đầu đã xong (checklist CRM hoặc việc chăm sóc hoàn thành) của học viên.
SELECT s.id::uuid AS student_id, (
    (CASE WHEN EXISTS (SELECT 1 FROM ops.work_tasks w WHERE w.student_id = s.id AND w.care_milestone = 1 AND w.status = 'completed')
            OR EXISTS (SELECT 1 FROM crm.leads l WHERE l.converted_student_id = s.id AND l.deleted_at IS NULL
                       AND l.care_checklist ? 'session_1'
                       AND (l.care_checklist -> 'session_1') NOT IN ('null', 'false', '""', '{}', '[]', '0')) THEN 1 ELSE 0 END)
    + (CASE WHEN EXISTS (SELECT 1 FROM ops.work_tasks w WHERE w.student_id = s.id AND w.care_milestone = 4 AND w.status = 'completed')
            OR EXISTS (SELECT 1 FROM crm.leads l WHERE l.converted_student_id = s.id AND l.deleted_at IS NULL
                       AND l.care_checklist ? 'session_4_5'
                       AND (l.care_checklist -> 'session_4_5') NOT IN ('null', 'false', '""', '{}', '[]', '0')) THEN 1 ELSE 0 END)
    + (CASE WHEN EXISTS (SELECT 1 FROM ops.work_tasks w WHERE w.student_id = s.id AND w.care_milestone = 30 AND w.status = 'completed')
            OR EXISTS (SELECT 1 FROM crm.leads l WHERE l.converted_student_id = s.id AND l.deleted_at IS NULL
                       AND l.care_checklist ? 'day_30'
                       AND (l.care_checklist -> 'day_30') NOT IN ('null', 'false', '""', '{}', '[]', '0')) THEN 1 ELSE 0 END)
)::int AS done
FROM academic.students s WHERE s.id = ANY (@ids::uuid[]);

-- name: UnlinkPeriodItems :exec
UPDATE hr.commission_items SET payroll_record_id = NULL, status = 'deferred'
WHERE settled_at IS NULL AND payroll_record_id IN (SELECT r.id FROM hr.payroll_records r WHERE r.period_id = $1);

-- name: LinkPayableItems :exec
UPDATE hr.commission_items SET payroll_record_id = @record_id, status = 'payable', deferred_reason = NULL
WHERE id = ANY (@ids::uuid[]);

-- name: SetDeferredReason :exec
UPDATE hr.commission_items SET deferred_reason = @reason WHERE id = @id;

-- name: SettlePeriodItems :exec
UPDATE hr.commission_items SET settled_at = @at, status = 'paid', updated_at = now()
WHERE settled_at IS NULL AND payroll_record_id IN (SELECT r.id FROM hr.payroll_records r WHERE r.period_id = @period_id);

-- name: RecordItems :many
SELECT i.*, s.full_name AS student_name, r.code AS receipt_code FROM hr.commission_items i
LEFT JOIN academic.students s ON s.id = i.student_id JOIN finance.receipts r ON r.id = i.receipt_id
WHERE i.payroll_record_id = $1 ORDER BY i.earned_period_start, i.id;

-- name: ItemsByIDs :many
SELECT i.*, s.full_name AS student_name, r.code AS receipt_code FROM hr.commission_items i
LEFT JOIN academic.students s ON s.id = i.student_id JOIN finance.receipts r ON r.id = i.receipt_id
WHERE i.id = ANY (@ids::uuid[]) ORDER BY i.earned_period_start, i.id;

-- name: UnsettledAdjustments :many
SELECT id, user_id, amount FROM hr.commission_adjustments
WHERE settled_at IS NULL AND payroll_record_id IS NULL AND created_at <= @end_at ORDER BY user_id, id;

-- name: UnlinkPeriodAdjustments :exec
UPDATE hr.commission_adjustments SET payroll_record_id = NULL
WHERE settled_at IS NULL AND payroll_record_id IN (SELECT r.id FROM hr.payroll_records r WHERE r.period_id = $1);

-- name: LinkAdjustments :exec
UPDATE hr.commission_adjustments SET payroll_record_id = @record_id WHERE id = ANY (@ids::uuid[]);

-- name: SettlePeriodAdjustments :exec
UPDATE hr.commission_adjustments SET settled_at = @at, updated_at = now()
WHERE settled_at IS NULL AND payroll_record_id IN (SELECT r.id FROM hr.payroll_records r WHERE r.period_id = @period_id);

-- name: RecordAdjustments :many
SELECT a.id, a.amount, a.reason, a.created_at, s.full_name AS student_name FROM hr.commission_adjustments a
LEFT JOIN academic.students s ON s.id = a.student_id WHERE a.payroll_record_id = $1 ORDER BY a.created_at, a.id;

-- name: InsertAdjustment :exec
INSERT INTO hr.commission_adjustments (user_id, refund_request_id, student_id, amount, reason, created_by)
VALUES (@user_id, @refund_request_id, @student_id, @amount, @reason, @created_by);

-- name: StudentItems :many
-- Khoản hoa hồng (chưa hủy) của học viên cho một người nhận, kèm trạng thái kỳ đã trả.
SELECT i.*, coalesce((SELECT p.status FROM hr.payroll_records r JOIN hr.payroll_periods p ON p.id = r.period_id
             WHERE r.id = i.payroll_record_id), '')::text AS period_status
FROM hr.commission_items i
WHERE i.student_id = @student_id AND i.user_id = @user_id AND i.status <> 'void'
ORDER BY i.created_at, i.id;

-- name: VoidItem :exec
UPDATE hr.commission_items SET status = 'void', payroll_record_id = NULL, deferred_reason = @reason, updated_at = now() WHERE id = @id;

-- name: ItemByReceipt :one
SELECT i.*, coalesce((SELECT p.status FROM hr.payroll_records r JOIN hr.payroll_periods p ON p.id = r.period_id
             WHERE r.id = i.payroll_record_id), '')::text AS period_status,
    coalesce((SELECT coalesce(nullif(p.title, ''), p.code) FROM hr.payroll_records r JOIN hr.payroll_periods p ON p.id = r.period_id
     WHERE r.id = i.payroll_record_id), '')::text AS period_title,
    coalesce((SELECT u.full_name FROM iam.users u WHERE u.id = i.user_id), '')::text AS user_name
FROM hr.commission_items i WHERE i.receipt_id = $1;

-- name: ReceiptBrief :one
SELECT id, code, status, amount, approved_at, student_id FROM finance.receipts WHERE id = $1;

-- name: StudentName :one
SELECT full_name FROM academic.students WHERE id = $1;

-- ───────────── Công thức ─────────────

-- name: MainKpiSheet :one
-- Phiếu KPI chính của kỳ chứa tháng (phiếu quý lưu ở tháng đầu quý).
SELECT id, total_score::float8 AS total_score, status, month FROM hr.kpi_sheets
WHERE user_id = @user_id AND track = 'main' AND year = @year
  AND (month = @month OR (period_months = 3 AND @month::int BETWEEN month AND month + 2))
ORDER BY month DESC LIMIT 1;

-- name: AcademicKpiCriteria :many
SELECT id, code, group_name, name, weight::float8 AS weight FROM hr.kpi_criteria
WHERE role = 'academic_staff' AND is_active AND deleted_at IS NULL
ORDER BY sort_order, code, id;

-- name: SheetItems :many
SELECT criterion_id, score::float8 AS score FROM hr.kpi_items WHERE sheet_id = $1;

-- name: ClassesOfTeacher :many
SELECT id, code::text AS code, name FROM academic.classes WHERE teacher_id = $1 AND deleted_at IS NULL ORDER BY created_at, id;

-- name: ClassInfo :many
-- Lớp kèm học phí niêm yết trọn khóa và số buổi một khóa (SessionLedger::classUnitPrice).
SELECT c.id, c.code::text AS code, c.name, c.foreign_teacher_id,
    coalesce(nullif(c.tuition_fee, 0), co.tuition_fee, 0)::bigint AS fee, coalesce(co.total_lessons, 0)::int AS course_sessions
FROM academic.classes c LEFT JOIN academic.courses co ON co.id = c.course_id
WHERE c.id = ANY (@ids::uuid[]);

-- name: RetentionEnrollments :many
-- Lượt xếp lớp hiệu lực tại đầu kỳ của các lớp, kèm ngày nghỉ (lượt xếp lớp dropped / học viên Thôi học).
SELECT e.student_id, e.class_id, e.status, s.status AS student_status,
    -- 9999-12-31 = chưa nghỉ
    coalesce(CASE WHEN e.status = 'dropped' THEN coalesce(e.left_at, e.updated_at::date) END, '9999-12-31'::date)::date AS dropped_on,
    coalesce(CASE WHEN s.status = 'dropped' THEN coalesce(
        (SELECT min(ev.created_at)::date FROM academic.student_events ev
         WHERE ev.student_id = s.id AND ev.kind = 'status_changed' AND ev.to_status = 'dropped'),
        s.updated_at::date) END, '9999-12-31'::date)::date AS lost_on
FROM academic.enrollments e JOIN academic.students s ON s.id = e.student_id
WHERE e.class_id = ANY (@class_ids::uuid[]) AND e.enrolled_at <= @start_day AND s.deleted_at IS NULL;

-- name: ClassRevenue :many
-- Doanh thu lớp trong kỳ: phiếu thu đã duyệt (số dương, không nhận chuyển nhượng) của khoản học phí gắn lớp.
SELECT t.class_id::uuid AS class_id, coalesce(sum(r.amount), 0)::bigint AS revenue
FROM finance.receipts r JOIN finance.tuitions t ON t.id = r.tuition_id
WHERE t.class_id = ANY (@class_ids::uuid[]) AND r.status = 'approved' AND r.amount > 0
  AND (r.transaction_code IS NULL OR r.transaction_code NOT LIKE 'XFER-IN-%')
  AND r.approved_at BETWEEN @start_at AND @end_at
GROUP BY t.class_id;

-- name: ClassMembers :many
-- Học viên của lớp theo khoảng ngày (TeachingShareService::membersByClass).
SELECT e.class_id, e.student_id, e.enrolled_at AS from_day,
    coalesce(e.left_at, CASE WHEN e.status = 'dropped' THEN e.updated_at::date END, '9999-12-31'::date)::date AS until_day
FROM academic.enrollments e WHERE e.class_id = ANY (@class_ids::uuid[]);

-- name: TuitionUnitPrices :many
SELECT student_id, class_id, total_amount, discount_amount, coalesce(session_count, 0)::int AS session_count
FROM finance.tuitions WHERE student_id = ANY (@student_ids::uuid[]) ORDER BY created_at, id;

-- name: StudentDeferrals :many
SELECT student_id, defer_from::date AS defer_from, defer_to::date AS defer_to FROM finance.refund_requests
WHERE student_id = ANY (@student_ids::uuid[]) AND type = 'deferral' AND status = 'approved'
  AND defer_from IS NOT NULL AND defer_to IS NOT NULL;

-- name: StudentNames :many
SELECT id, code::text AS code, full_name FROM academic.students WHERE id = ANY (@ids::uuid[]) ORDER BY full_name, id;

-- name: RoleLabels :many
SELECT name, label, short_label FROM iam.roles ORDER BY sort, name;
