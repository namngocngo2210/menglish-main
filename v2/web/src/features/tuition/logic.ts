// Tính tiền phiếu thu theo sổ buổi (giống calculate ở Go / @/lib/sessionReceipt của Laravel) và các hàm hiển thị.
import type { Promotion, Quote, Tone } from './api'

export function formatMoney(v: number | null | undefined): string {
  const n = Math.round(Number(v) || 0)
  const s = Math.abs(n)
    .toString()
    .replace(/\B(?=(\d{3})+(?!\d))/g, '.')
  return `${n < 0 ? '-' : ''}${s} đ`
}

export function toInt(v: string | number | null | undefined): number {
  const n = typeof v === 'number' ? v : parseInt(String(v ?? '').replace(/[^\d-]/g, ''), 10)
  return Number.isFinite(n) ? Math.trunc(n) : 0
}

// Tiền buổi: thu hết số buổi còn của hợp đồng thì lấy đúng phần học phí còn lại (không làm tròn theo đơn giá).
export function sessionValue(q: Quote | null, sessions: number): number {
  if (!q || sessions <= 0) return 0
  if (q.mode === 'contract' && q.contract_remaining_sessions !== null && sessions >= q.contract_remaining_sessions) {
    return q.tuition_remaining ?? 0
  }
  return Math.round(sessions * q.unit_price)
}

export type Calc = {
  sessionCount: number
  sessionValue: number
  feeDue: number
  materialFee: number
  examFee: number
  otherFee: number
  subtotal: number
  discount: number
  totalDue: number
  extra: number
  amount: number
  tuitionAmount: number
  surchargeAmount: number
}

export function calculate(
  q: Quote | null,
  p: { sessions: number; itemsTotal: number; examFee: number; otherFee: number; extra: number; discount: number },
): Calc {
  const sessions = Math.max(0, p.sessions)
  const value = sessionValue(q, sessions)
  const feeDue = sessions > 0 && q?.mode === 'contract' ? q.fee_due : 0
  const exam = Math.max(0, p.examFee)
  const other = Math.max(0, p.otherFee)
  const items = Math.max(0, p.itemsTotal)
  const extra = Math.max(0, p.extra)
  const discount = Math.min(Math.max(0, p.discount), value)
  const material = items + feeDue
  const subtotal = value + material + exam + other
  const total = subtotal - discount
  return {
    sessionCount: sessions,
    sessionValue: value,
    feeDue,
    materialFee: material,
    examFee: exam,
    otherFee: other,
    subtotal,
    discount,
    totalDue: total,
    extra,
    amount: total + extra,
    tuitionAmount: value + feeDue - discount,
    surchargeAmount: items + exam + other + extra,
  }
}

// Số tiền giảm theo ưu đãi (crm.CalculateDiscount).
export function promotionDiscount(p: Promotion | null, base: number): number {
  const b = Math.max(0, base)
  if (!p) return 0
  if (p.type === 'percent') {
    let d = (b * p.value) / 100
    if (p.max_discount_amount && d > p.max_discount_amount) d = p.max_discount_amount
    return Math.round(Math.min(b, d))
  }
  return Math.min(b, p.value)
}

export function promotionApplies(p: Promotion, branchId: string | null | undefined, courseId: string | null | undefined) {
  return (!p.branch_id || p.branch_id === (branchId ?? '')) && (!p.course_id || p.course_id === (courseId ?? ''))
}

export function promotionLabel(p: Promotion) {
  const value = p.type === 'percent' ? `${p.value}%` : formatMoney(p.value)
  const cap = p.type === 'percent' && p.max_discount_amount ? `, tối đa ${formatMoney(p.max_discount_amount)}` : ''
  return `${p.name} (${value}${cap})${p.is_default ? ' · Mặc định' : ''}`
}

// Ảnh VietQR (img.vietqr.io) theo tài khoản nhận, số tiền và nội dung chuyển khoản.
export function vietQrUrl(bank: { bank_code: string; account_number: string; account_holder: string } | null, amount: number, memo: string) {
  if (!bank || !bank.bank_code || !bank.account_number) return ''
  return (
    `https://img.vietqr.io/image/${encodeURIComponent(bank.bank_code)}-${encodeURIComponent(bank.account_number)}-compact2.png` +
    `?amount=${Math.max(0, amount)}&addInfo=${encodeURIComponent(memo)}&accountName=${encodeURIComponent(bank.account_holder || '')}`
  )
}

export const DEPOSIT: Record<string, { label: string; tone: Tone }> = {
  pending: { label: 'Chưa nộp về TK công ty', tone: 'warning' },
  on_time: { label: 'Đã nộp đúng hạn', tone: 'success' },
  late: { label: 'Nộp trễ hạn', tone: 'error' },
}

export function dueTone(days: number, seriousDays: number): Tone {
  return days >= seriousDays ? 'error' : 'warning'
}
