import { ApiError, request } from '../../api'
import { toQuery } from './format'

// ───── Nhật ký vận hành ─────

export type AuditDiff = { field: string; old: string; new: string }
export type AuditLog = {
  id: string
  created_at: string
  actor_id: string | null
  actor_name: string | null
  actor_code: string | null
  actor_role: string | null
  module: string
  event: string
  event_label: string
  action: string
  description: string
  subject: string | null
  subject_id: string | null
  diff: AuditDiff[]
  properties: Record<string, unknown>
  ip: string | null
  user_agent: string | null
  request_id: string | null
  url: string | null
}
export type AuditFilter = {
  q?: string
  group?: string
  module?: string
  event?: string
  actor_id?: string
  date_from?: string
  date_to?: string
  page?: number
  per_page?: number
}
export type Option = { value: string; label: string }
export type AuditOptions = {
  groups: Option[]
  modules: string[]
  events: Option[]
  actors: Option[]
  stats: { total: number; today: number; active_users: number }
}

export const auditApi = {
  list: (f: AuditFilter) =>
    request<{ logs: AuditLog[]; total: number; page: number; per_page: number }>('GET', `/activity-logs?${toQuery(f)}`),
  options: () => request<AuditOptions>('GET', '/activity-logs/options'),
  exportUrl: (f: AuditFilter) => `/api/v1/activity-logs/export?${toQuery({ ...f, page: undefined, per_page: undefined })}`,
}

// ───── Thông báo ─────

export type NotificationTone = 'neutral' | 'error' | 'warning' | 'success' | 'info' | 'primary'
export type NotificationItem = {
  id: string
  type: string
  type_label: string
  icon: string
  tone: NotificationTone
  title: string
  body: string
  link: string | null
  data: Record<string, unknown>
  is_read: boolean
  read_at: string | null
  created_at: string
}
export type NotificationFilter = { type?: string; unread?: boolean; page?: number; per_page?: number }
export type NotificationPage = {
  notifications: NotificationItem[]
  total: number
  page: number
  per_page: number
  stats: { total: number; unread: number; alerts: number }
  types: Option[]
}

export const notifyApi = {
  list: (f: NotificationFilter) => request<NotificationPage>('GET', `/notifications?${toQuery(f)}`),
  summary: () => request<{ unread: number; items: NotificationItem[] }>('GET', '/notifications/summary'),
  read: (id: string) => request<{ unread: number }>('POST', `/notifications/${id}/read`),
  readAll: () => request<{ unread: number; updated: number }>('POST', '/notifications/read-all'),
}

// Màu thẻ loại thông báo (badge_color của Laravel).
export const TONE_CLASS: Record<NotificationTone, string> = {
  neutral: 'bg-surface-container-low text-on-surface-variant border-surface-container-highest',
  error: 'bg-error/10 text-error border-error/30',
  warning: 'bg-warning/10 text-warning border-warning/30',
  success: 'bg-success/10 text-success border-success/30',
  info: 'bg-info/10 text-info border-info/30',
  primary: 'bg-primary/10 text-primary border-primary/30',
}

// Số chưa đọc trên chuông: quá 9 hiện "9+".
export const unreadBadge = (n: number) => (n > 9 ? '9+' : String(n))

// ───── Tìm kiếm chung ─────

export type SearchHit = { id: string; title: string; code?: string; subtitle?: string; meta?: string[]; link: string }
export type SearchGroup = { key: string; label: string; icon: string; items: SearchHit[] }
export type SearchResult = { term: string; searched: string[]; groups: SearchGroup[]; total: number; forbidden?: boolean }

export const searchApi = {
  // Người không có quyền của nhóm nào (403) vẫn tìm được màn hình ở giao diện: trả kết quả rỗng kèm forbidden.
  search: async (q: string, limit?: number): Promise<SearchResult> => {
    try {
      return await request<SearchResult>('GET', `/search?${toQuery({ q, limit })}`)
    } catch (e) {
      if (e instanceof ApiError && e.status === 403) return { term: q, searched: [], groups: [], total: 0, forbidden: true }
      throw e
    }
  },
}
