// Hàm thuần của màn KPI (có test): tính mức đạt / tổng / xếp loại ngay khi điền phiếu như Kpi/Evaluate.vue.
import type { CriterionInput, CriterionView, DecideInput, EvaluatePage, GradeView, Line, Rule, TierInput } from './api'

// 1500000 → "1.500.000".
export function vnd(n: number) {
  const s = Math.round(Math.abs(n))
    .toString()
    .replace(/\B(?=(\d{3})+(?!\d))/g, '.')
  return `${n < 0 ? '-' : ''}${s}`
}
export const money = (n: number) => `${vnd(n)} đ`

// 92.456 → "92,46" (2 chữ số, bỏ số 0 thừa, dấu phẩy thập phân).
export const fmt = (n: number) => String(Math.round(n * 100) / 100).replace('.', ',')

// Bảng KPI tự động: 1 chữ số thập phân kiểu Việt Nam ("90,0%").
export const percent1 = (n: number) => new Intl.NumberFormat('vi-VN', { minimumFractionDigits: 1, maximumFractionDigits: 1 }).format(n) + '%'

export type Tone = 'success' | 'warning' | 'error' | 'info' | 'neutral'
export const levelTone = (l: number): Tone => (l >= 100 ? 'success' : l > 0 ? 'warning' : 'error')
export const statusTone = (color: string): Tone => (['success', 'warning', 'error', 'info'].includes(color) ? (color as Tone) : 'neutral')

// Cùng cách tính với KpiCriterion::levelFor: đếm số lần → bậc đầu tiên có số ≤ ngưỡng; tỉ lệ → bậc đầu tiên có tỉ lệ ≥ ngưỡng,
// hoặc thẳng theo tỉ lệ; vượt bậc cuối = 0%.
export function ruleLevel(rule: Rule, n: number) {
  if (rule.measure === 'rate' && rule.linear) return Math.round(Math.max(0, Math.min(100, (n / rule.full_at) * 100)) * 100) / 100
  const lower = rule.lower_better ?? rule.measure !== 'rate'
  for (const [at, pct] of rule.tiers) if (lower ? n <= at : n >= at) return pct
  return 0
}

export const allLines = (groups: { items: Line[] }[]) => groups.flatMap((g) => g.items)

// Số điền tay ban đầu (tiêu chí không tự động) và đánh dấu "Không phát sinh" (tiêu chí cho phép).
export function initialValues(lines: Line[]): Record<string, string> {
  return Object.fromEntries(lines.filter((c) => !c.auto).map((c) => [c.id, c.value === null ? '' : String(c.value).replace('.', ',')]))
}
export function initialNA(lines: Line[]): Record<string, boolean> {
  return Object.fromEntries(lines.filter((c) => c.allow_na).map((c) => [c.id, c.na]))
}

export type SheetForm = { values: Record<string, string>; na: Record<string, boolean> }

export const isNa = (c: Line, f: SheetForm) => (c.allow_na ? !!f.na[c.id] : c.na)

export function valueOf(c: Line, f: SheetForm): number | null {
  if (isNa(c, f)) return null
  if (c.auto) return c.value
  const raw = String(f.values[c.id] ?? '').trim()
  if (c.measure === 'rate') return /^\d+([.,]\d+)?$/.test(raw) ? Number(raw.replace(',', '.')) : null
  return /^\d+$/.test(raw) ? Number(raw) : null
}

// Mức đạt: số hệ thống / tiêu chí % cũ / phiếu đã duyệt dùng mức máy chủ đã tính; còn lại tính từ số điền.
export function levelOf(c: Line, f: SheetForm, status: string): number | null {
  if (isNa(c, f)) return null
  if (!c.count_based || c.auto || status === 'confirmed') return c.level
  const n = valueOf(c, f)
  return n === null ? null : ruleLevel(c.rule, n)
}

export function amountOf(c: Line, f: SheetForm, status: string): number | null {
  const l = levelOf(c, f, status)
  return c.max_amount === null || l === null ? null : Math.round((c.max_amount * l) / 100)
}

export type SheetTotals = {
  missing: number
  knockout: Line | null
  total: number
  totalMoney: number | null
  grade: GradeView | null
  bonus: number | null
}

// Tổng = Σ(mức đạt × trọng số) ÷ Σ trọng số các mục áp dụng (bỏ mục Không phát sinh); có điều kiện loại trừ → 0.
// Điều kiện chặn (grade_cap) giới hạn hạng cao nhất.
export function sheetTotals(p: Pick<EvaluatePage, 'status' | 'fund' | 'grades' | 'grade_cap' | 'bonus_fund'>, lines: Line[], f: SheetForm): SheetTotals {
  const missing = lines.filter((c) => levelOf(c, f, p.status) === null && !isNa(c, f)).length
  const knockout = lines.find((c) => c.knockout && (valueOf(c, f) ?? 0) > 0) ?? null
  const applicable = lines.filter((c) => !isNa(c, f))
  const weight = applicable.reduce((s, c) => s + c.weight, 0)
  const total = knockout || weight <= 0 ? 0 : applicable.reduce((s, c) => s + (levelOf(c, f, p.status) ?? 0) * c.weight, 0) / weight
  const capIndex = p.grade_cap ? p.grades.findIndex((g) => g.grade === p.grade_cap) : 0
  const grade = p.grades.find((g, i) => i >= capIndex && total >= g.from) ?? null
  return {
    missing,
    knockout,
    total,
    totalMoney: p.fund !== null ? Math.round((p.fund * total) / 100) : null,
    grade,
    bonus: p.bonus_fund && grade ? Math.round((p.bonus_fund * grade.pay) / 100) : null,
  }
}

export const valueLabel = (c: Line, f: SheetForm) =>
  isNa(c, f) ? 'Không phát sinh' : c.value === null ? '—' : c.measure === 'rate' ? `${fmt(c.value)}%` : String(c.value)

// Thân request Duyệt / Không duyệt: số điền tay của mọi ô và đánh dấu Không phát sinh.
export function decideInput(p: EvaluatePage, f: SheetForm, action: 'approve' | 'reject', reason: string): DecideInput {
  return {
    month: p.month,
    year: p.year,
    track: p.track,
    action,
    actual: { ...f.values },
    na: { ...f.na },
    reject_reason: action === 'reject' ? reason : null,
  }
}

export function sheetNotice(p: EvaluatePage) {
  if (p.is_self) return 'Không tự chấm KPI của chính mình.'
  if (p.locked) return 'Kỳ lương tháng này đã duyệt, phiếu đã khóa.'
  if (p.can_decide && !p.can_close)
    return `Duyệt từ ngày cuối ${p.period_months > 1 ? 'quý' : 'tháng'} ${p.close_on}. Trước đó có thể điền số liệu và Không duyệt.`
  return null
}

// ───────────── Form tiêu chí ─────────────

export type TierRow = { at: string; percent: string }
export type CriterionForm = {
  group_name: string
  new_group: string
  name: string
  weight: string
  measure: string
  unit: string
  tiers: TierRow[]
  linear: boolean
  full_at: string
  per_month: boolean
  knockout: boolean
  allow_na: boolean
  auto_source: string
  description: string
  is_active: boolean
}

export function criterionForm(item: CriterionView | null): CriterionForm {
  if (!item)
    return {
      group_name: '',
      new_group: '',
      name: '',
      weight: '',
      measure: 'count',
      unit: '',
      tiers: [
        { at: '0', percent: '100' },
        { at: '2', percent: '50' },
      ],
      linear: false,
      full_at: '100',
      per_month: false,
      knockout: false,
      allow_na: false,
      auto_source: '',
      description: '',
      is_active: true,
    }
  return {
    group_name: item.group_name ?? '',
    new_group: '',
    name: item.name,
    weight: item.weight,
    measure: item.measure,
    unit: item.unit ?? '',
    tiers: item.tiers.length
      ? item.tiers.map(([at, percent]) => ({ at: String(at), percent: String(percent) }))
      : [{ at: item.measure === 'rate' ? '90' : '0', percent: '100' }],
    linear: item.linear,
    full_at: String(item.full_at ?? 100),
    per_month: item.per_month,
    knockout: item.knockout,
    allow_na: item.allow_na,
    auto_source: item.auto_source ?? '',
    description: item.description ?? '',
    is_active: item.is_active,
  }
}

export const tierLabel = (measure: string) => (measure === 'rate' ? 'Từ (%)' : measure !== 'count' ? 'Không quá (%)' : 'Không quá (số lần)')

const num = (s: string) => (s.trim() === '' ? null : Number(s.replace(',', '.')))
const text = (s: string) => (s.trim() === '' ? null : s.trim())

// Form → thân request: tỉ lệ bỏ đơn vị / loại trừ; tính thẳng hoặc loại trừ không gửi bậc.
export function criterionInput(f: CriterionForm, role: string | null): CriterionInput {
  const rate = f.measure !== 'count'
  const linear = f.measure === 'rate' && f.linear
  const knockout = !rate && f.knockout
  const tiers: TierInput[] = f.tiers.map((t) => ({ at: num(t.at), percent: num(t.percent) }))
  return {
    ...(role ? { role } : { is_active: f.is_active }),
    group_name: text(f.group_name),
    new_group: text(f.new_group),
    name: f.name,
    weight: num(f.weight),
    measure: f.measure,
    unit: rate ? null : text(f.unit),
    ...(knockout || linear ? {} : { tiers }),
    linear,
    full_at: linear ? num(f.full_at) : null,
    per_month: f.per_month,
    knockout,
    allow_na: !knockout && f.allow_na,
    description: text(f.description),
    auto_source: text(f.auto_source),
  }
}

export const gradeText = (grades: GradeView[]) => grades.map((g) => `${g.grade} ≥ ${g.from}% (hệ số ${g.pay}%)`).join(' · ')
