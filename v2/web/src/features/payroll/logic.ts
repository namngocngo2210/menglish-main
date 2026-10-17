// Logic thuần của màn Lương & Phạt (định dạng số như Payroll/format.js của Laravel, dựng dữ liệu gửi đi, gợi ý lỗi vi phạm).
import type { AdjustInput, ManualLine, PenaltiesPage, PenaltyRow, RecordPage, ViolationOption } from './api'

export type Tone = 'neutral' | 'success' | 'error' | 'info' | 'warning'

// vnd(1500000) → "1.500.000" (number_format($v, 0, ',', '.')).
export function vnd(n: number | null | undefined) {
  const v = Math.round(Number(n ?? 0))
  const s = Math.abs(v)
    .toString()
    .replace(/\B(?=(\d{3})+(?!\d))/g, '.')
  return v < 0 ? `-${s}` : s
}

export const money = (n: number | null | undefined) => `${vnd(n)} đ`

// trimNumber(10.5) → "10,5"; bỏ số 0 thừa sau dấu thập phân.
export function trimNumber(v: number | null | undefined, decimals = 2) {
  const text = Number(v ?? 0).toFixed(decimals)
  return text.replace(/\.?0+$/, '').replace('.', ',')
}

// Màu trạng thái của server (success | warning | info | error | primary | secondary | neutral) → tông Badge.
export function statusTone(color: string): Tone {
  if (['success', 'warning', 'error', 'info'].includes(color)) return color as Tone
  if (color === 'primary') return 'info'
  return 'neutral'
}

// Nhãn trạng thái kỳ như màn Laravel (Đã trả / Đã chốt / Đang tính).
export function periodBadge(status: string): [string, Tone] {
  if (status === 'paid') return ['Đã trả', 'neutral']
  if (status === 'approved') return ['Đã chốt', 'success']
  return ['Đang tính', 'info']
}

export const kpiTone: Record<string, Tone> = {
  done: 'success',
  pending: 'error',
  na: 'neutral',
  self: 'info',
}

// numOrNull: ô số (input type=number) rỗng → null (server báo "Vui lòng nhập…"), còn lại là số; dấu phẩy thập phân được nhận.
export function numOrNull(s: string | number | null | undefined): number | null {
  if (s === null || s === undefined) return null
  const t = String(s).trim().replace(',', '.')
  if (t === '') return null
  const n = Number(t)
  return Number.isFinite(n) ? n : null
}

export type LineDraft = { kind: string; label: string; amount: string }

export type AdjustDraft = {
  retention_tier: string
  kpi_manual_amount: string
  foreign_session_pay: string
  tax_deduction: string
  adjustment_notes: string
  lines: LineDraft[]
}

// adjustDraft: giá trị ban đầu của form "Lưu điều chỉnh" theo phiếu.
export function adjustDraft(p: RecordPage): AdjustDraft {
  const r = p.record
  return {
    retention_tier: r.retention_tier === null ? '' : String(r.retention_tier),
    kpi_manual_amount: r.kpi_manual_amount === null ? '' : String(r.kpi_manual_amount),
    foreign_session_pay: r.foreign_session_pay ? String(r.foreign_session_pay) : '',
    tax_deduction: r.tax_deduction ? String(r.tax_deduction) : '',
    adjustment_notes: r.adjustment_notes ?? '',
    lines: p.lines.map((l) => ({
      kind: l.kind,
      label: l.label,
      amount: l.amount === '' ? '' : String(l.amount),
    })),
  }
}

// adjustInput: chỉ gửi ô mà phiếu dùng; khoản tự do 0đ / trống bỏ qua (như Laravel không lưu khoản 0đ).
export function adjustInput(p: RecordPage, d: AdjustDraft): AdjustInput {
  const r = p.record
  const out: AdjustInput = {
    lines: [],
    adjustment_notes: d.adjustment_notes.trim() || null,
  }
  if (r.kpi_source === 'retention' || r.teaching_concurrent) out.retention_tier = numOrNull(d.retention_tier)
  if (r.kpi_source === 'manual') out.kpi_manual_amount = numOrNull(d.kpi_manual_amount)
  if (r.is_part_time) out.foreign_session_pay = numOrNull(d.foreign_session_pay) ?? 0
  if (r.is_full_time) out.tax_deduction = numOrNull(d.tax_deduction) ?? 0
  for (const l of d.lines) {
    const amount = numOrNull(l.amount)
    if (!amount && !l.label.trim()) continue
    if (!amount) continue
    out.lines.push({
      kind: l.kind === 'deduction' ? 'deduction' : 'earning',
      label: l.label.trim(),
      amount,
    } as ManualLine)
  }
  return out
}

// Tổng dự kiến của form (cập nhật khi gõ): cộng / trừ theo khoản tự do.
export function draftDelta(lines: LineDraft[]) {
  let earning = 0
  let deduction = 0
  for (const l of lines) {
    const n = numOrNull(l.amount) ?? 0
    if (l.kind === 'deduction') deduction += n
    else earning += n
  }
  return { earning, deduction }
}

// violationSuggestions: lỗi thường gặp theo vai trò của nhân sự đang chọn (gộp nhiều vai trò); chưa chọn / vai trò lạ → mọi lỗi.
export function violationSuggestions(page: Pick<PenaltiesPage, 'users' | 'role_violations' | 'all_violations'>, userId: string) {
  const user = page.users.find((u) => u.value === userId)
  const roles = (user?.roles ?? []).filter((r) => page.role_violations[r])
  if (roles.length === 0) return { roles: [] as string[], list: page.all_violations }
  const seen = new Map<string, ViolationOption>()
  for (const r of roles) for (const v of page.role_violations[r].violations) if (!seen.has(v.value)) seen.set(v.value, v)
  return {
    roles: roles.map((r) => page.role_violations[r].label),
    list: [...seen.values()],
  }
}

// Bước → màu (stepColors của Laravel).
export const stepTone: Record<string, Tone> = {
  recorded: 'warning',
  confirmed: 'error',
  fined: 'info',
  paid: 'success',
  remedied: 'info',
  resolved: 'neutral',
  cancelled: 'neutral',
}

export const decidable = (p: Pick<PenaltyRow, 'status'>) => ['pending', 'explained', 'confirmed'].includes(p.status)
export const showAmount = (p: Pick<PenaltyRow, 'status' | 'amount'>) => p.amount > 0 && !['pending', 'explained', 'confirmed', 'resolved'].includes(p.status)

// pad2(9) → "09"
export const pad2 = (n: number) => String(n).padStart(2, '0')
