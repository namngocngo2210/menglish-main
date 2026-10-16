import { request } from '../../api'
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
