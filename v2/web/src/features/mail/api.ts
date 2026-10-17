// Hàng đợi email (Go: internal/notify/mail/mailapi): thư đã xếp, lỗi, gửi lại / hủy.
import { request } from '../../api'
import { toQuery } from '../platform/format'

export type MailStatus = 'pending' | 'sending' | 'sent' | 'failed' | 'blocked' | 'cancelled'
export type Option = { value: string; label: string }

export type MailItem = {
  id: string
  kind: string
  to: string
  to_name: string
  subject: string
  status: MailStatus
  attempts: number
  max_attempts: number
  next_attempt_at: string
  last_error: string | null
  ref_type: string | null
  ref_id: string | null
  created_by: string | null
  created_at: string
  updated_at: string
  sent_at: string | null
}

export type MailPage = {
  items: MailItem[]
  total: number
  page: number
  per_page: number
  counts: Partial<Record<MailStatus, number>>
  blocked: boolean
  kinds: Option[]
  statuses: Option[]
}

export type MailDetail = MailItem & {
  sensitive: boolean
  preview: string | null
  has_html: boolean
  has_fallback: boolean
}

export type MailFilter = {
  status?: string
  kind?: string
  q?: string
  page?: number
}

export const mailApi = {
  list: (f: MailFilter) => request<MailPage>('GET', `/mail-outbox?${toQuery(f)}`),
  get: (id: string) => request<MailDetail>('GET', `/mail-outbox/${id}`),
  retry: (id: string) => request<{ message: string }>('POST', `/mail-outbox/${id}/retry`),
  cancel: (id: string) => request<{ message: string }>('POST', `/mail-outbox/${id}/cancel`),
}

// Thao tác được trên thư theo trạng thái (khớp RetryMail / CancelMail ở Go).
// Thư nhạy cảm đã xóa dữ liệu (đặt lại mật khẩu đã gửi / bị chặn) thì Go từ chối gửi lại: yêu cầu link mới.
export function canRetry(m: Pick<MailItem, 'status'>) {
  return m.status === 'failed' || m.status === 'blocked' || m.status === 'cancelled'
}
export function canCancel(m: Pick<MailItem, 'status'>) {
  return m.status === 'pending' || m.status === 'failed'
}

export const statusTone: Record<MailStatus, 'neutral' | 'success' | 'error' | 'info' | 'warning'> = {
  pending: 'info',
  sending: 'info',
  sent: 'success',
  failed: 'error',
  blocked: 'warning',
  cancelled: 'neutral',
}

// "Lần 2/8" kèm giờ thử lại khi còn chờ.
export function attemptLabel(m: Pick<MailItem, 'status' | 'attempts' | 'max_attempts'>) {
  if (m.attempts === 0) return 'Chưa gửi'
  return `Lần ${m.attempts}/${m.max_attempts}`
}
