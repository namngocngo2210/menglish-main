import { expect, test } from 'vitest'
import type { CriterionView, EvaluatePage, Line, Rule } from './api'
import { criterionForm, criterionInput, decideInput, fmt, levelOf, percent1, ruleLevel, sheetNotice, sheetTotals, vnd } from './logic'

const count: Rule = {
  measure: 'count',
  lower_better: true,
  tiers: [
    [0, 100],
    [2, 50],
  ],
  linear: false,
  full_at: 100,
  has_rule: true,
  knockout: false,
}

function line(id: string, extra: Partial<Line> = {}): Line {
  return {
    id,
    code: null,
    name: `Tiêu chí ${id}`,
    description: null,
    weight: 50,
    weight_label: '50',
    max_amount: 1_000_000,
    amount: null,
    threshold_full: '0 lần',
    threshold_half: '≤ 2 lần',
    max_full: 0,
    max_half: 2,
    rule_label: '',
    rule: count,
    measure: 'count',
    unit: 'lần',
    per_month: false,
    knockout: false,
    count_based: true,
    auto: false,
    pending: null,
    value: null,
    level: null,
    allow_na: false,
    na: false,
    cap: false,
    evidence: [],
    suggestion: null,
    ...extra,
  }
}

test('ruleLevel: đếm số lần, tỉ lệ theo bậc, tính thẳng', () => {
  expect(ruleLevel(count, 0)).toBe(100)
  expect(ruleLevel(count, 2)).toBe(50)
  expect(ruleLevel(count, 3)).toBe(0)
  const rate: Rule = {
    ...count,
    measure: 'rate',
    lower_better: false,
    tiers: [
      [90, 100],
      [80, 50],
    ],
  }
  expect(ruleLevel(rate, 92)).toBe(100)
  expect(ruleLevel(rate, 85)).toBe(50)
  expect(ruleLevel(rate, 70)).toBe(0)
  expect(ruleLevel({ ...rate, linear: true, full_at: 10 }, 5)).toBe(50)
  expect(ruleLevel({ ...rate, linear: true, full_at: 10 }, 15)).toBe(100)
})

test('định dạng số', () => {
  expect(vnd(1_500_000)).toBe('1.500.000')
  expect(fmt(92.456)).toBe('92,46')
  expect(fmt(50)).toBe('50')
  expect(percent1(90)).toBe('90,0%')
})

const page = (extra: Partial<EvaluatePage> = {}) =>
  ({ status: 'draft', fund: 2_000_000, grades: [], grade_cap: null, bonus_fund: null, ...extra }) as EvaluatePage

test('sheetTotals: tổng theo trọng số, thiếu số liệu, Không phát sinh, loại trừ', () => {
  const auto = line('a', { auto: true, value: 1, level: 50, weight: 60 })
  const manual = line('m', { weight: 40, allow_na: true })
  const lines = [auto, manual]
  let t = sheetTotals(page(), lines, { values: { m: '' }, na: { m: false } })
  expect(t.missing).toBe(1)
  expect(t.total).toBe(30)
  t = sheetTotals(page(), lines, { values: { m: '0' }, na: { m: false } })
  expect([t.missing, t.total, t.totalMoney]).toEqual([0, 70, 1_400_000])
  // Không phát sinh: bỏ khỏi tử số và mẫu số
  t = sheetTotals(page(), lines, { values: { m: '5' }, na: { m: true } })
  expect([t.missing, t.total]).toEqual([0, 50])
  // Ô chữ không hợp lệ → chưa có số liệu
  expect(levelOf(manual, { values: { m: '1,5' }, na: {} }, 'draft')).toBeNull()
  // Phiếu đã duyệt dùng mức máy chủ đã chốt
  expect(levelOf(line('x', { level: 77 }), { values: { x: '0' }, na: {} }, 'confirmed')).toBe(77)
  // Điều kiện loại trừ có từ 1 lần → tổng 0
  const ko = line('k', { knockout: true, weight: 0, rule: { ...count, tiers: [[0, 100]] } })
  t = sheetTotals(page(), [...lines, ko], { values: { m: '0', k: '1' }, na: {} })
  expect(t.knockout?.id).toBe('k')
  expect(t.total).toBe(0)
})

test('sheetTotals: xếp loại, thưởng, điều kiện chặn', () => {
  const grades = [
    { grade: 'A', from: 95, label: 'Xuất sắc', pay: 100 },
    { grade: 'B', from: 85, label: 'Tốt', pay: 85 },
    { grade: 'C', from: 70, label: 'Đạt', pay: 70 },
  ]
  const lines = [line('a', { auto: true, level: 100, value: 0 })]
  let t = sheetTotals(page({ fund: null, grades, bonus_fund: 1_000_000 }), lines, { values: {}, na: {} })
  expect([t.grade?.grade, t.bonus, t.totalMoney]).toEqual(['A', 1_000_000, null])
  t = sheetTotals(page({ fund: null, grades, bonus_fund: 1_000_000, grade_cap: 'B' }), lines, { values: {}, na: {} })
  expect([t.grade?.grade, t.bonus]).toEqual(['B', 850_000])
})

test('decideInput, sheetNotice', () => {
  const p = page({
    month: 9,
    year: 2026,
    track: 'main',
    is_self: false,
    locked: false,
    can_decide: true,
    can_close: false,
    close_on: '30/09/2026',
    period_months: 1,
  })
  expect(decideInput(p, { values: { m: '1' }, na: { m: false } }, 'reject', 'Thiếu')).toEqual({
    month: 9,
    year: 2026,
    track: 'main',
    action: 'reject',
    actual: { m: '1' },
    na: { m: false },
    reject_reason: 'Thiếu',
  })
  expect(decideInput(p, { values: {}, na: {} }, 'approve', 'x').reject_reason).toBeNull()
  expect(sheetNotice(p)).toBe('Duyệt từ ngày cuối tháng 30/09/2026. Trước đó có thể điền số liệu và Không duyệt.')
  expect(sheetNotice({ ...p, is_self: true })).toBe('Không tự chấm KPI của chính mình.')
})

test('criterionInput: bậc, tỉ lệ thẳng, loại trừ', () => {
  const f = { ...criterionForm(null), name: 'Báo cáo trễ', weight: '50', unit: 'lan', auto_source: 'daily_report_late' }
  expect(criterionInput(f, 'academic_staff')).toMatchObject({
    role: 'academic_staff',
    weight: 50,
    measure: 'count',
    unit: 'lan',
    tiers: [
      { at: 0, percent: 100 },
      { at: 2, percent: 50 },
    ],
    auto_source: 'daily_report_late',
    group_name: null,
  })
  const linear = criterionInput({ ...f, measure: 'rate', linear: true, full_at: '10' }, null)
  expect(linear.tiers).toBeUndefined()
  expect([linear.unit, linear.full_at, linear.is_active]).toEqual([null, 10, true])
  const ko = criterionInput({ ...f, knockout: true, allow_na: true }, 'teacher_parttime')
  expect([ko.tiers, ko.allow_na, ko.knockout]).toEqual([undefined, false, true])

  const item = {
    id: 'c1',
    name: 'Tỉ lệ test',
    weight: '40',
    measure: 'rate',
    tiers: [[90, 100]],
    group_name: 'N1',
    unit: 'phan_tram',
    is_active: false,
  } as CriterionView
  const form = criterionForm(item)
  expect([form.weight, form.tiers, form.group_name, form.is_active]).toEqual(['40', [{ at: '90', percent: '100' }], 'N1', false])
})
