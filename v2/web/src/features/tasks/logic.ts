// Logic thuần của màn công việc (tách ra để test).
import type { TaskStatus } from './api'

export const STATUS_OPTIONS: { value: TaskStatus; label: string }[] = [
  { value: 'overdue', label: 'Quá hạn' },
  { value: 'blocked', label: 'Bị chặn' },
  { value: 'pending_confirmation', label: 'Chờ xác nhận' },
  { value: 'in_progress', label: 'Đang thực hiện' },
  { value: 'new', label: 'Mới' },
  { value: 'completed', label: 'Hoàn thành' },
  { value: 'canceled', label: 'Đã hủy' },
]

// Màu nhãn trạng thái (giống status-* của bản Laravel).
export function statusTone(status: string): string {
  switch (status) {
    case 'in_progress':
      return 'bg-warning-container text-warning'
    case 'pending_confirmation':
      return 'bg-primary-light text-primary'
    case 'blocked':
    case 'overdue':
      return 'bg-error-container text-on-error-container'
    case 'completed':
      return 'bg-success-container text-on-success-container'
    default:
      return 'bg-surface-container-high text-on-surface-variant'
  }
}

// Bị chặn / Hủy bắt buộc ghi lý do.
export const reasonRequired = (next: string) => next === 'blocked' || next === 'canceled'

export function reasonLabel(next: string): string {
  if (reasonRequired(next)) return 'Ghi chú lý do'
  if (next === 'hand_back') return 'Ghi chú cho người giao (tùy chọn)'
  return 'Ghi chú / kết quả (tùy chọn)'
}

// Bước chọn sẵn: bước đầu tiên trừ "Hủy công việc" (phải bấm chọn chủ động).
export function firstSafe(values: string[]): string {
  return values.find((v) => v !== 'canceled') ?? ''
}

// "2026-10-16" → "16/10/2026".
export function formatDate(iso: string | null | undefined): string {
  if (!iso) return '—'
  const [y, m, d] = iso.slice(0, 10).split('-')
  return d && m && y ? `${d}/${m}/${y}` : iso
}

// Ngày giờ ISO → "HH:MM dd/mm/yyyy" theo giờ Việt Nam.
export function formatDateTime(iso: string | null | undefined): string {
  if (!iso) return '—'
  const t = new Date(iso)
  if (Number.isNaN(t.getTime())) return iso
  const vn = new Date(t.getTime() + 7 * 3600_000)
  const p = (n: number) => String(n).padStart(2, '0')
  return `${p(vn.getUTCHours())}:${p(vn.getUTCMinutes())} ${p(vn.getUTCDate())}/${p(vn.getUTCMonth() + 1)}/${vn.getUTCFullYear()}`
}

const toMinutes = (hhmm: string) => {
  const [h, m] = hhmm.split(':').map(Number)
  return h * 60 + m
}

// Giao nhiệm vụ trợ giảng trễ: ngày đã qua, hoặc hôm nay mà giờ hiện tại (giờ máy chủ + số phút đã mở form) quá mốc.
export function isLateAssign(assignDate: string, today: string, nowTime: string, cutoff: string, elapsedMinutes = 0) {
  if (!assignDate) return false
  if (assignDate < today) return true
  return assignDate === today && toMinutes(nowTime) + elapsedMinutes > toMinutes(cutoff)
}

export function percent(done: number, total: number) {
  return total ? Math.round((done / total) * 100) : 0
}

// '/tasks/<id>' → id chi tiết công việc; danh sách và các trang khác → null.
export function taskIdFromPath(path: string): string | null {
  const m = /^\/tasks\/([0-9a-f-]{36})$/i.exec(path)
  return m ? m[1] : null
}

export const DUE_TEXT: Record<string, string> = {
  before: 'khi lớp bắt đầu',
  during: 'khi lớp tan',
  after: '1 tiếng sau khi lớp tan',
}
