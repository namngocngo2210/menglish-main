-- name: LockKey :exec
-- Khóa theo tên trong giao dịch (cấp mã tuần tự, đổi tài khoản mặc định...).
SELECT pg_advisory_xact_lock(hashtext(sqlc.arg('name')::text));

-- ───────────────────────── Khóa học ─────────────────────────

-- name: ListCourses :many
SELECT c.id, c.code, c.name, c.description, c.course_level_id, c.tuition_fee, c.total_lessons, c.is_active, c.created_at,
       l.code AS level_code, l.name AS level_name, l.target AS level_target,
       count(*) OVER ()::bigint AS total
FROM academic.courses c
LEFT JOIN academic.course_levels l ON l.id = c.course_level_id
WHERE c.deleted_at IS NULL
  AND (sqlc.narg('search')::text IS NULL
       OR c.name ILIKE '%' || sqlc.narg('search') || '%'
       OR c.code ILIKE '%' || sqlc.narg('search') || '%'
       OR c.description ILIKE '%' || sqlc.narg('search') || '%')
  AND (sqlc.narg('level_id')::uuid IS NULL OR c.course_level_id = sqlc.narg('level_id'))
  AND (sqlc.narg('active')::boolean IS NULL OR c.is_active = sqlc.narg('active'))
ORDER BY c.created_at DESC, c.id DESC
LIMIT sqlc.arg('lim') OFFSET sqlc.arg('off');

-- name: CourseStats :one
SELECT count(*)::bigint AS total,
       count(*) FILTER (WHERE is_active)::bigint AS active,
       coalesce(round(avg(tuition_fee) FILTER (WHERE is_active)), 0)::bigint AS avg_tuition,
       coalesce(max(tuition_fee) FILTER (WHERE is_active), 0)::bigint AS max_tuition
FROM academic.courses
WHERE deleted_at IS NULL;

-- name: GetCourse :one
SELECT * FROM academic.courses WHERE id = $1 AND deleted_at IS NULL;

-- name: NextCourseNumber :one
-- Số kế tiếp của mã CS0001, tính cả khóa đã xóa (mã không dùng lại).
SELECT (coalesce(max(substring(code FROM '^CS([0-9]{4,})$')::bigint), 0) + 1)::bigint
FROM academic.courses;

-- name: CreateCourse :one
INSERT INTO academic.courses (code, name, course_level_id, tuition_fee, total_lessons, description, is_active, created_by)
VALUES ($1, $2, $3, $4, $5, $6, $7, $8)
RETURNING *;

-- name: UpdateCourse :one
UPDATE academic.courses
SET name = $2, course_level_id = $3, tuition_fee = $4, total_lessons = $5, description = $6, is_active = $7, updated_at = now()
WHERE id = $1 AND deleted_at IS NULL
RETURNING *;

-- name: ToggleCourse :one
UPDATE academic.courses SET is_active = NOT is_active, updated_at = now()
WHERE id = $1 AND deleted_at IS NULL
RETURNING *;

-- name: DeleteCourse :exec
UPDATE academic.courses SET deleted_at = now(), updated_at = now() WHERE id = $1 AND deleted_at IS NULL;

-- ───────────────────────── Trình độ ─────────────────────────

-- name: ListLevels :many
SELECT l.*, s.courses_count,
       count(*) OVER ()::bigint AS total
FROM academic.course_levels l
CROSS JOIN LATERAL (
    SELECT count(*)::bigint AS courses_count FROM academic.courses c WHERE c.course_level_id = l.id AND c.deleted_at IS NULL
) s
WHERE l.deleted_at IS NULL
  AND (sqlc.narg('search')::text IS NULL
       OR l.code ILIKE '%' || sqlc.narg('search') || '%'
       OR l.name ILIKE '%' || sqlc.narg('search') || '%'
       OR l.target ILIKE '%' || sqlc.narg('search') || '%'
       OR l.level_group ILIKE '%' || sqlc.narg('search') || '%')
  AND (sqlc.narg('active')::boolean IS NULL OR l.is_active = sqlc.narg('active'))
  AND (sqlc.narg('level_group')::text IS NULL OR l.level_group = sqlc.narg('level_group'))
ORDER BY l.sort_order, l.level_group NULLS FIRST, l.code
LIMIT sqlc.arg('lim') OFFSET sqlc.arg('off');

-- name: LevelStats :one
SELECT count(*)::bigint AS total,
       count(*) FILTER (WHERE is_active)::bigint AS active,
       count(DISTINCT syllabus_curriculum_id) FILTER (WHERE is_active)::bigint AS syllabus,
       count(DISTINCT level_group)::bigint AS groups
FROM academic.course_levels
WHERE deleted_at IS NULL;

-- name: LevelGroups :many
SELECT DISTINCT level_group::text FROM academic.course_levels
WHERE deleted_at IS NULL AND level_group IS NOT NULL
ORDER BY 1;

-- name: ActiveLevels :many
SELECT id, code, name, target FROM academic.course_levels
WHERE deleted_at IS NULL AND is_active
ORDER BY sort_order, level_group NULLS FIRST, code;

-- name: GetLevel :one
SELECT * FROM academic.course_levels WHERE id = $1 AND deleted_at IS NULL;

-- name: LevelCodeTaken :one
SELECT EXISTS (SELECT 1 FROM academic.course_levels WHERE code = $1);

-- name: CreateLevel :one
INSERT INTO academic.course_levels (code, name, description, level_group, grade_levels, target, lessons_count,
                                    syllabus_curriculum_id, is_active, sort_order, created_by)
VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9,
        (SELECT coalesce(max(sort_order), 0) + 1 FROM academic.course_levels WHERE deleted_at IS NULL), $10)
RETURNING *;

-- name: UpdateLevel :one
UPDATE academic.course_levels
SET name = $2, description = $3, level_group = $4, grade_levels = $5, target = $6, lessons_count = $7,
    syllabus_curriculum_id = $8, is_active = $9, updated_at = now()
WHERE id = $1 AND deleted_at IS NULL
RETURNING *;

-- name: ToggleLevel :one
UPDATE academic.course_levels SET is_active = NOT is_active, updated_at = now()
WHERE id = $1 AND deleted_at IS NULL
RETURNING *;

-- name: LevelCourseCount :one
SELECT count(*)::bigint FROM academic.courses WHERE course_level_id = $1 AND deleted_at IS NULL;

-- name: DeleteLevel :exec
UPDATE academic.course_levels SET deleted_at = now(), updated_at = now() WHERE id = $1 AND deleted_at IS NULL;

-- name: LockLevelOrder :many
-- Thứ tự toàn bộ trình độ (khóa dòng để hai người kéo thả cùng lúc không làm lệch thứ tự).
SELECT id FROM academic.course_levels
WHERE deleted_at IS NULL
ORDER BY sort_order, level_group NULLS FIRST, code
FOR UPDATE;

-- name: SetLevelSort :exec
UPDATE academic.course_levels SET sort_order = $2 WHERE id = $1 AND sort_order <> $2;

-- ───────────────────────── Phòng học ─────────────────────────

-- name: ScopeBranches :many
-- Chi nhánh đang hoạt động trong phạm vi (NULL = mọi chi nhánh).
SELECT id, code::text AS code, name FROM core.branches
WHERE is_active AND (sqlc.narg('ids')::uuid[] IS NULL OR id = ANY (sqlc.narg('ids')::uuid[]))
ORDER BY name;

-- name: BranchExists :one
SELECT EXISTS (SELECT 1 FROM core.branches WHERE id = $1);

-- name: ListRooms :many
SELECT r.id, r.branch_id, r.room_type_id, r.name::text AS name, r.capacity, r.description,
       b.name AS branch_name, t.name::text AS type_name, (t.is_active AND t.deleted_at IS NULL)::boolean AS type_active,
       count(*) OVER ()::bigint AS total
FROM core.rooms r
JOIN core.branches b ON b.id = r.branch_id
JOIN core.room_types t ON t.id = r.room_type_id
WHERE r.deleted_at IS NULL
  AND (sqlc.narg('scope')::uuid[] IS NULL OR r.branch_id = ANY (sqlc.narg('scope')::uuid[]))
  AND (sqlc.narg('branch_id')::uuid IS NULL OR r.branch_id = sqlc.narg('branch_id'))
  AND (sqlc.narg('room_type_id')::uuid IS NULL OR r.room_type_id = sqlc.narg('room_type_id'))
ORDER BY b.name, r.name
LIMIT sqlc.arg('lim') OFFSET sqlc.arg('off');

-- name: CountRooms :one
SELECT count(*)::bigint FROM core.rooms
WHERE deleted_at IS NULL AND (sqlc.narg('scope')::uuid[] IS NULL OR branch_id = ANY (sqlc.narg('scope')::uuid[]));

-- name: GetRoom :one
SELECT r.id, r.branch_id, r.room_type_id, r.name::text AS name, r.capacity, r.description, b.name AS branch_name
FROM core.rooms r JOIN core.branches b ON b.id = r.branch_id
WHERE r.id = $1 AND r.deleted_at IS NULL;

-- name: CreateRoom :one
INSERT INTO core.rooms (branch_id, room_type_id, name, capacity, description, created_by)
VALUES ($1, $2, $3, $4, $5, $6)
RETURNING id;

-- name: UpdateRoom :exec
UPDATE core.rooms SET room_type_id = $2, name = $3, capacity = $4, description = $5, updated_at = now()
WHERE id = $1 AND deleted_at IS NULL;

-- name: DeleteRoom :exec
UPDATE core.rooms SET deleted_at = now(), updated_at = now() WHERE id = $1 AND deleted_at IS NULL;

-- name: ListRoomTypes :many
SELECT t.id, t.name::text AS name, t.is_active,
       EXISTS (SELECT 1 FROM core.rooms r WHERE r.room_type_id = t.id AND r.deleted_at IS NULL) AS in_use
FROM core.room_types t
WHERE t.deleted_at IS NULL
ORDER BY t.is_active DESC, t.name;

-- name: CountRoomTypes :one
SELECT count(*)::bigint FROM core.room_types WHERE deleted_at IS NULL;

-- name: GetRoomType :one
SELECT id, name::text AS name, is_active FROM core.room_types WHERE id = $1 AND deleted_at IS NULL;

-- name: CreateRoomType :one
INSERT INTO core.room_types (name, created_by) VALUES ($1, $2)
RETURNING id, name::text AS name, is_active;

-- name: RenameRoomType :one
UPDATE core.room_types SET name = $2, updated_at = now() WHERE id = $1 AND deleted_at IS NULL
RETURNING id, name::text AS name, is_active;

-- name: ToggleRoomType :one
UPDATE core.room_types SET is_active = NOT is_active, updated_at = now() WHERE id = $1 AND deleted_at IS NULL
RETURNING id, name::text AS name, is_active;

-- name: RoomTypeInUse :one
SELECT EXISTS (SELECT 1 FROM core.rooms WHERE room_type_id = $1 AND deleted_at IS NULL);

-- name: DeleteRoomType :exec
UPDATE core.room_types SET deleted_at = now(), updated_at = now() WHERE id = $1 AND deleted_at IS NULL;

-- ───────────────────────── Ngày nghỉ lễ ─────────────────────────

-- name: ListHolidays :many
SELECT h.id, h.code::text AS code, h.name, h.start_date::text AS start_date, h.end_date::text AS end_date, h.is_system_wide,
       coalesce((SELECT array_agg(b.name ORDER BY b.name) FROM core.holiday_branches hb JOIN core.branches b ON b.id = hb.branch_id
                 WHERE hb.holiday_id = h.id), '{}')::text[] AS branch_names,
       count(*) OVER ()::bigint AS total
FROM core.holidays h
WHERE h.deleted_at IS NULL
  AND (sqlc.narg('search')::text IS NULL
       OR h.name ILIKE '%' || sqlc.narg('search') || '%'
       OR h.code ILIKE '%' || sqlc.narg('search') || '%')
ORDER BY h.start_date DESC, h.id DESC
LIMIT sqlc.arg('lim') OFFSET sqlc.arg('off');

-- name: GetHoliday :one
SELECT h.id, h.code::text AS code, h.name, h.start_date::text AS start_date, h.end_date::text AS end_date, h.is_system_wide,
       coalesce((SELECT array_agg(hb.branch_id) FROM core.holiday_branches hb WHERE hb.holiday_id = h.id), '{}')::uuid[] AS branch_ids
FROM core.holidays h
WHERE h.id = $1 AND h.deleted_at IS NULL;

-- name: NextHolidayNumber :one
-- Số kế tiếp của mã HOL-<năm>-001 theo năm, tính cả ngày nghỉ đã xóa.
SELECT (coalesce(max(substring(code FROM '^HOL-' || sqlc.arg('year')::text || '-([0-9]+)$')::bigint), 0) + 1)::bigint
FROM core.holidays;

-- name: HolidayCodeTaken :one
SELECT EXISTS (SELECT 1 FROM core.holidays WHERE code = sqlc.arg('code')::text AND id <> sqlc.arg('except')::uuid);

-- name: CreateHoliday :one
INSERT INTO core.holidays (code, name, start_date, end_date, is_system_wide, created_by)
VALUES (sqlc.arg('code'), sqlc.arg('name'), (sqlc.arg('start_date')::text)::date, (sqlc.arg('end_date')::text)::date, sqlc.arg('is_system_wide'), sqlc.arg('created_by'))
RETURNING id;

-- name: UpdateHoliday :execrows
UPDATE core.holidays
SET code = sqlc.arg('code'), name = sqlc.arg('name'), start_date = (sqlc.arg('start_date')::text)::date, end_date = (sqlc.arg('end_date')::text)::date,
    is_system_wide = sqlc.arg('is_system_wide'), updated_at = now()
WHERE id = sqlc.arg('id') AND deleted_at IS NULL;

-- name: ClearHolidayBranches :exec
DELETE FROM core.holiday_branches WHERE holiday_id = $1;

-- name: AddHolidayBranches :exec
INSERT INTO core.holiday_branches (holiday_id, branch_id)
SELECT sqlc.arg('holiday_id'), unnest(sqlc.arg('branch_ids')::uuid[])
ON CONFLICT DO NOTHING;

-- name: DeleteHoliday :execrows
UPDATE core.holidays SET deleted_at = now(), updated_at = now() WHERE id = $1 AND deleted_at IS NULL;

-- name: HolidaysBetween :many
-- Ngày nghỉ áp dụng cho chi nhánh trong khoảng ngày (cho xếp lịch lớp, tra phòng trống).
SELECT h.id, h.code::text AS code, h.name, h.start_date::text AS start_date, h.end_date::text AS end_date
FROM core.holidays h
WHERE h.deleted_at IS NULL
  AND h.start_date <= (sqlc.arg('to_date')::text)::date AND h.end_date >= (sqlc.arg('from_date')::text)::date
  AND (h.is_system_wide OR EXISTS (SELECT 1 FROM core.holiday_branches hb WHERE hb.holiday_id = h.id AND hb.branch_id = sqlc.arg('branch_id')))
ORDER BY h.start_date;

-- ───────────────────────── Khung giờ ca dạy ─────────────────────────

-- name: ListShifts :many
SELECT id, day_type, name, to_char(start_time, 'HH24:MI') AS start_time, to_char(end_time, 'HH24:MI') AS end_time,
       coalesce(to_char(alt_start_time, 'HH24:MI'), '')::text AS alt_start_time, coalesce(to_char(alt_end_time, 'HH24:MI'), '')::text AS alt_end_time,
       note, is_active
FROM hr.teaching_shifts
WHERE deleted_at IS NULL
ORDER BY CASE day_type WHEN 'weekday' THEN 1 WHEN 'saturday' THEN 2 ELSE 3 END, start_time, sort_order;

-- name: CreateShift :one
INSERT INTO hr.teaching_shifts (day_type, name, start_time, end_time, alt_start_time, alt_end_time, note, sort_order, created_by)
VALUES (sqlc.arg('day_type'), sqlc.arg('name'), (sqlc.arg('start_time')::text)::time, (sqlc.arg('end_time')::text)::time,
        (sqlc.narg('alt_start_time')::text)::time, (sqlc.narg('alt_end_time')::text)::time, sqlc.narg('note'),
        (SELECT coalesce(max(sort_order), 0) + 1 FROM hr.teaching_shifts), sqlc.arg('created_by'))
RETURNING id;

-- name: UpdateShift :execrows
UPDATE hr.teaching_shifts
SET day_type = sqlc.arg('day_type'), name = sqlc.arg('name'), start_time = (sqlc.arg('start_time')::text)::time, end_time = (sqlc.arg('end_time')::text)::time,
    alt_start_time = (sqlc.narg('alt_start_time')::text)::time, alt_end_time = (sqlc.narg('alt_end_time')::text)::time, note = sqlc.narg('note'), updated_at = now()
WHERE id = sqlc.arg('id') AND deleted_at IS NULL;

-- name: ToggleShift :execrows
UPDATE hr.teaching_shifts SET is_active = NOT is_active, updated_at = now() WHERE id = $1 AND deleted_at IS NULL;

-- name: DeleteShift :execrows
UPDATE hr.teaching_shifts SET deleted_at = now(), updated_at = now() WHERE id = $1 AND deleted_at IS NULL;

-- ───────────────────────── Danh mục hệ thống ─────────────────────────

-- name: ListCategories :many
SELECT id, type, code::text AS code, name, sort_order, is_active,
       count(*) OVER ()::bigint AS total
FROM core.system_categories
WHERE deleted_at IS NULL AND type = sqlc.arg('type')
  AND (sqlc.narg('search')::text IS NULL
       OR code ILIKE '%' || sqlc.narg('search') || '%'
       OR name ILIKE '%' || sqlc.narg('search') || '%')
ORDER BY sort_order, id
LIMIT sqlc.arg('lim') OFFSET sqlc.arg('off');

-- name: CategoryCodes :many
SELECT code::text FROM core.system_categories WHERE type = $1;

-- name: MaxCategorySort :one
SELECT coalesce(max(sort_order), 0)::integer FROM core.system_categories WHERE type = $1 AND deleted_at IS NULL;

-- name: GetCategory :one
SELECT id, type, code::text AS code, name, sort_order, is_active FROM core.system_categories WHERE id = $1 AND deleted_at IS NULL;

-- name: CreateCategory :one
INSERT INTO core.system_categories (type, code, name, sort_order, is_active, created_by)
VALUES ($1, $2, $3, $4, $5, $6)
RETURNING id, type, code::text AS code, name, sort_order, is_active;

-- name: UpdateCategory :one
UPDATE core.system_categories SET type = $2, code = $3, name = $4, sort_order = $5, is_active = $6, updated_at = now()
WHERE id = $1 AND deleted_at IS NULL
RETURNING id, type, code::text AS code, name, sort_order, is_active;

-- name: SetCategoryActive :one
UPDATE core.system_categories SET is_active = $2, updated_at = now()
WHERE id = $1 AND deleted_at IS NULL
RETURNING id, type, code::text AS code, name, sort_order, is_active;

-- name: ActiveCategories :many
SELECT id, type, code::text AS code, name, sort_order, is_active FROM core.system_categories
WHERE deleted_at IS NULL AND is_active AND type = $1
ORDER BY sort_order, id;

-- ───────────────────────── SLA ─────────────────────────

-- name: ListSlaRules :many
SELECT * FROM hr.sla_rules ORDER BY sort;

-- name: GetSlaRule :one
SELECT * FROM hr.sla_rules WHERE key = $1;

-- name: UpsertSlaDefault :exec
INSERT INTO hr.sla_rules (key, group_key, label, description, unit, default_value, default_penalty, default_amount, default_ladder,
                          amount_label, amount_per, task, switchable, category, violation, sort)
VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, $13, $14, $15, $16)
ON CONFLICT (key) DO UPDATE SET
    group_key = EXCLUDED.group_key, label = EXCLUDED.label, description = EXCLUDED.description, unit = EXCLUDED.unit,
    default_value = EXCLUDED.default_value, default_penalty = EXCLUDED.default_penalty, default_amount = EXCLUDED.default_amount,
    default_ladder = EXCLUDED.default_ladder, amount_label = EXCLUDED.amount_label, amount_per = EXCLUDED.amount_per,
    task = EXCLUDED.task, switchable = EXCLUDED.switchable, category = EXCLUDED.category, violation = EXCLUDED.violation,
    sort = EXCLUDED.sort;

-- name: DeleteSlaRulesExcept :many
DELETE FROM hr.sla_rules WHERE NOT (key = ANY (sqlc.arg('keys')::text[])) RETURNING key;

-- name: SetSlaOverride :exec
UPDATE hr.sla_rules SET value = $2, enabled = $3, penalty = $4, amount = $5, ladder = $6, updated_by = $7, updated_at = now()
WHERE key = $1;

-- ───────────────────────── Cấu hình khóa – giá trị ─────────────────────────

-- name: GetSettings :many
SELECT key, value, (secret IS NOT NULL)::boolean AS has_secret FROM core.settings WHERE key = ANY (sqlc.arg('keys')::text[]);

-- name: GetSecretSetting :one
SELECT secret FROM core.settings WHERE key = $1;

-- name: PutSetting :exec
INSERT INTO core.settings (key, value, description, updated_by) VALUES ($1, $2, $3, $4)
ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, description = coalesce(EXCLUDED.description, core.settings.description),
    updated_by = EXCLUDED.updated_by, updated_at = now();

-- name: PutSecretSetting :exec
INSERT INTO core.settings (key, secret, description, updated_by) VALUES ($1, $2, $3, $4)
ON CONFLICT (key) DO UPDATE SET secret = EXCLUDED.secret, description = coalesce(EXCLUDED.description, core.settings.description),
    updated_by = EXCLUDED.updated_by, updated_at = now();

-- ───────────────────────── Tài khoản ngân hàng ─────────────────────────

-- name: ListBankAccounts :many
SELECT a.id, a.account_type, a.bank_code, a.bank_name, a.account_number, a.account_holder, a.branch_location, a.branch_id,
       a.is_default_vietqr, a.is_active, b.name AS branch_name
FROM finance.bank_accounts a
LEFT JOIN core.branches b ON b.id = a.branch_id
WHERE a.deleted_at IS NULL
  AND (sqlc.narg('search')::text IS NULL
       OR a.bank_name ILIKE '%' || sqlc.narg('search') || '%'
       OR a.bank_code ILIKE '%' || sqlc.narg('search') || '%'
       OR a.account_number ILIKE '%' || sqlc.narg('search') || '%'
       OR a.account_holder ILIKE '%' || sqlc.narg('search') || '%'
       OR b.name ILIKE '%' || sqlc.narg('search') || '%')
ORDER BY a.is_default_vietqr DESC, a.is_active DESC, a.bank_name, a.id;

-- name: CountBankAccounts :one
SELECT count(*)::bigint FROM finance.bank_accounts WHERE deleted_at IS NULL;

-- name: GetBankAccount :one
SELECT * FROM finance.bank_accounts WHERE id = $1 AND deleted_at IS NULL;

-- name: DefaultBankAccount :one
-- Tài khoản mặc định toàn hệ thống: đang dùng, ưu tiên cờ mặc định VietQR, rồi tài khoản không gắn chi nhánh.
SELECT * FROM finance.bank_accounts
WHERE deleted_at IS NULL AND is_active
ORDER BY is_default_vietqr DESC, (branch_id IS NOT NULL), created_at, id
LIMIT 1;

-- name: ClearDefaultBankAccount :exec
UPDATE finance.bank_accounts SET is_default_vietqr = false, updated_at = now()
WHERE is_default_vietqr AND deleted_at IS NULL AND id <> sqlc.arg('except')::uuid;

-- name: CreateBankAccount :one
INSERT INTO finance.bank_accounts (account_type, bank_code, bank_name, account_number, account_holder, branch_location, branch_id,
                                   is_default_vietqr, created_by)
VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9)
RETURNING *;

-- name: UpdateBankAccount :one
UPDATE finance.bank_accounts
SET account_type = $2, bank_code = $3, bank_name = $4, account_number = $5, account_holder = $6, branch_location = $7,
    branch_id = $8, is_default_vietqr = $9, is_active = $10, updated_at = now()
WHERE id = $1 AND deleted_at IS NULL
RETURNING *;

-- name: SetDefaultBankAccount :one
UPDATE finance.bank_accounts SET is_default_vietqr = true, is_active = true, updated_at = now()
WHERE id = $1 AND deleted_at IS NULL
RETURNING *;

-- name: DeleteBankAccount :exec
UPDATE finance.bank_accounts SET deleted_at = now(), is_default_vietqr = false, updated_at = now() WHERE id = $1 AND deleted_at IS NULL;

-- name: PromoteFirstBankAccount :exec
-- Sau khi xóa tài khoản mặc định: tài khoản đang dùng tạo sớm nhất thành mặc định.
UPDATE finance.bank_accounts SET is_default_vietqr = true, updated_at = now()
WHERE id = (SELECT a.id FROM finance.bank_accounts a WHERE a.deleted_at IS NULL AND a.is_active ORDER BY a.created_at, a.id LIMIT 1)
  AND NOT EXISTS (SELECT 1 FROM finance.bank_accounts d WHERE d.is_default_vietqr AND d.deleted_at IS NULL);

-- ───────────────────────── Nhắc nợ học phí ─────────────────────────

-- name: ListDebtRules :many
SELECT * FROM finance.debt_reminder_rules ORDER BY offset_days, milestone_key;

-- name: GetDebtRuleByKey :one
SELECT * FROM finance.debt_reminder_rules WHERE milestone_key = $1;

-- name: UpsertDebtRule :one
INSERT INTO finance.debt_reminder_rules (milestone_key, offset_days, title, template_content, channels, is_enabled, created_by)
VALUES ($1, $2, $3, $4, $5, $6, $7)
ON CONFLICT (milestone_key) DO UPDATE SET offset_days = EXCLUDED.offset_days, title = EXCLUDED.title,
    template_content = EXCLUDED.template_content, channels = EXCLUDED.channels, is_enabled = EXCLUDED.is_enabled, updated_at = now()
RETURNING *;

-- name: SetDebtRuleEnabled :exec
UPDATE finance.debt_reminder_rules SET is_enabled = $2, updated_at = now() WHERE id = $1;
