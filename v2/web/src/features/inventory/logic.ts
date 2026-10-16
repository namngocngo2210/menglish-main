// Hàm thuần của Kho vật phẩm / Order học liệu (định dạng, nhãn, hạn xử lý dự kiến) — có test riêng.
import type { DeadlineState, OrderStatus, StockLevel } from './api'

const VN_OFFSET = 7 * 3600 * 1000 // giờ Việt Nam, không đổi theo mùa

const pad = (n: number) => String(n).padStart(2, '0')

// Giờ Việt Nam của một mốc thời gian (đọc bằng getUTC*).
function vnParts(iso: string | Date) {
  const d = new Date((typeof iso === 'string' ? Date.parse(iso) : iso.getTime()) + VN_OFFSET)
  return { y: d.getUTCFullYear(), m: d.getUTCMonth() + 1, d: d.getUTCDate(), hh: d.getUTCHours(), mm: d.getUTCMinutes() }
}

// "15:04 02/01/2006" như nhật ký kho của Laravel (H:i d/m/Y).
export function formatTimeDate(iso: string | Date) {
  const p = vnParts(iso)
  return `${pad(p.hh)}:${pad(p.mm)} ${pad(p.d)}/${pad(p.m)}/${p.y}`
}

// "02/01/2006 15:04" (hạn xử lý order).
export function formatDateTime(iso: string | Date) {
  const p = vnParts(iso)
  return `${pad(p.d)}/${pad(p.m)}/${p.y} ${pad(p.hh)}:${pad(p.mm)}`
}

// Ngày nghiệp vụ "2026-10-12" → "12/10/2026".
export function formatDate(ymd: string) {
  const [y, m, d] = ymd.split('-')
  return y && m && d ? `${d}/${m}/${y}` : ymd
}

// 200000 → "200.000 đ".
export function formatVnd(n: number) {
  return `${formatCount(n)} đ`
}

// Dấu chấm hàng nghìn.
export function formatCount(n: number) {
  const s = String(Math.abs(Math.trunc(n))).replace(/\B(?=(\d{3})+(?!\d))/g, '.')
  return n < 0 ? `-${s}` : s
}

export function signed(n: number) {
  return n > 0 ? `+${n}` : String(n)
}

export const LEVELS: Record<StockLevel, { label: string; tone: 'error' | 'warning' | 'success' }> = {
  negative: { label: 'Âm kho', tone: 'error' },
  out: { label: 'Hết hàng', tone: 'error' },
  low: { label: 'Sắp hết', tone: 'warning' },
  ok: { label: 'Còn hàng', tone: 'success' },
}

export const DEADLINES: Record<Exclude<DeadlineState, ''>, { label: string; tone: 'success' | 'warning' | 'error' }> = {
  ok: { label: 'Còn hạn', tone: 'success' },
  soon: { label: 'Sắp hết hạn', tone: 'warning' },
  overdue: { label: 'Quá hạn', tone: 'error' },
}

export const STATUS_TONES: Record<OrderStatus, 'neutral' | 'info' | 'success' | 'error' | 'warning'> = {
  pending: 'info',
  processing: 'warning',
  done: 'success',
  rejected: 'neutral',
  overdue: 'error',
}

// Hạn xử lý dự kiến của order (giống Config.DueAt ở server — server mới là nơi tính chính thức):
// đạo cụ / in ấn: giờ chốt ngày hôm trước; GVNN / học thuật: 23:59 ngày N của tháng sử dụng.
export function previewDueAt(category: string, useDate: string, startOfMonthDay: number, cutoff: string): Date | null {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(useDate)
  if (!m) return null
  const [y, mo, d] = [Number(m[1]), Number(m[2]), Number(m[3])]
  if (category === 'props' || category === 'printing') {
    const [hh, mm] = cutoff.split(':').map(Number)
    return new Date(Date.UTC(y, mo - 1, d - 1, hh, mm) - VN_OFFSET)
  }
  const last = new Date(Date.UTC(y, mo, 0)).getUTCDate()
  const day = Math.min(Math.max(1, startOfMonthDay), last)
  return new Date(Date.UTC(y, mo - 1, day, 23, 59) - VN_OFFSET)
}

export function dueRule(category: string, startOfMonthDay: number, cutoff: string) {
  if (category === 'props' || category === 'printing') return `Học vụ xử lý trước ${cutoff} ngày hôm trước ngày sử dụng.`
  if (category === 'academic') return `Trưởng Học thuật xử lý đầu tháng — hạn 23:59 ngày ${startOfMonthDay} của tháng sử dụng.`
  return `Học vụ xử lý đầu tháng — hạn 23:59 ngày ${startOfMonthDay} của tháng sử dụng.`
}

// Ô số trên form: rỗng → null, ngược lại số nguyên (NaN → null).
export function toInt(s: string): number | null {
  if (s.trim() === '') return null
  const n = Number(s)
  return Number.isFinite(n) ? Math.trunc(n) : null
}

// Số trang từ tổng số dòng.
export function pageCount(total: number, size: number) {
  return Math.max(1, Math.ceil(total / size))
}
