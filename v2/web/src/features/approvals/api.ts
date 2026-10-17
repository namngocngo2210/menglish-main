// API hộp "Việc cần duyệt" (Go: internal/approvals). Thông báo lỗi tiếng Việt lấy từ máy chủ (errorMessage).
import { request } from '../../api'
import { toQuery } from '../platform/format'

export type Meta = { label: string; value: string }

export type ApprovalItem = {
  source: string
  id: string
  title: string
  subtitle: string | null
  url: string
  created_at: string | null
  amount: number | null
  flag: string | null
  meta?: Meta[]
}

export type Section = {
  key: string
  label: string
  group: string
  index_url: string
  count: number
  approve: boolean
  reject: boolean
  items: ApprovalItem[]
}

export type Group = { slug: string; label: string; count: number }

export type InboxPage = { groups: Group[]; group: string | null; total: number; sections: Section[] }

export type Detail = { source: Meta; item: ApprovalItem; can_approve: boolean; can_reject: boolean }

export type Action = 'approve' | 'reject'

export type ItemResult = { ref: string; title: string; ok: boolean; message: string }

export type BulkResult = { message: string; tone: 'success' | 'warning' | 'error'; results: ItemResult[] }

export const approvalsApi = {
  index: (group: string | null) => {
    const qs = toQuery({ group })
    return request<InboxPage>('GET', `/approvals${qs ? `?${qs}` : ''}`)
  },
  badge: () => request<{ count: number | null }>('GET', '/approvals/badge'),
  show: (source: string, id: string) => request<Detail>('GET', `/approvals/${encodeURIComponent(source)}/${encodeURIComponent(id)}`),
  bulk: (action: Action, items: string[], reason?: string) =>
    request<BulkResult>('POST', '/approvals/bulk', { action, items, reason: reason ?? null }),
}
