// API Ticket hỗ trợ (Go: internal/tickets). Thông báo lỗi tiếng Việt lấy từ máy chủ (errorMessage).
import { request, upload } from '../../api'
import { toQuery } from '../platform/format'
import { ticketForm } from './logic'

export type Option = { value: string; label: string }
export type TicketStatus = 'open' | 'in_progress' | 'resolved' | 'closed'
export type Priority = 'low' | 'medium' | 'high' | 'urgent'

export type TicketRow = {
  id: string
  code: string
  title: string
  category: string
  category_label: string
  priority: Priority
  priority_label: string
  status: TicketStatus
  status_label: string
  creator: string
  assignee: string | null
  created_at: string
}

export type TicketStats = { total: number; open: number; in_progress: number; resolved: number }

export type TicketList = {
  tickets: TicketRow[]
  total: number
  page: number
  per_page: number
  stats: TicketStats
  statuses: Option[]
  can_manage: boolean
}

export type ListParams = { status?: string; category?: string; q?: string; page?: number }

export type FormOptions = { categories: Option[]; priorities: Option[]; staffs: Option[]; can_assign: boolean }

export type Attachment = { id: string; name: string; ext: string; size: number; is_image: boolean; url: string }

export type TicketMessage = {
  id: string
  user_id: string | null
  user: string | null
  message: string
  is_internal_note: boolean
  created_at: string
  attachments: Attachment[]
}

export type TicketDetail = {
  id: string
  code: string
  title: string
  description: string
  category: string
  category_label: string
  priority: Priority
  priority_label: string
  status: TicketStatus
  status_label: string
  is_finished: boolean
  creator_id: string
  creator: string
  assignee_id: string | null
  assignee: string | null
  resolved_at: string | null
  created_at: string
}

export type ShowPage = {
  ticket: TicketDetail
  messages: TicketMessage[]
  staffs: Option[]
  statuses: Option[]
  can_post_internal: boolean
  can_reopen: boolean
  can_close: boolean
  can_assign: boolean
}

export type CreateInput = {
  title: string
  category: string
  priority: string
  description: string
  assignee_id: string | null
  submission_token: string
}

export type Result = { id: string; code: string; message: string; duplicate?: boolean }

export type MessageInput = { message: string; is_internal_note: boolean }

const base = '/tickets'

export const ticketsApi = {
  list: (p: ListParams) => {
    const qs = toQuery({ status: p.status, category: p.category, q: p.q, page: p.page && p.page > 1 ? p.page : undefined })
    return request<TicketList>('GET', `${base}${qs ? `?${qs}` : ''}`)
  },
  form: () => request<FormOptions>('GET', `${base}/form`),
  show: (id: string) => request<ShowPage>('GET', `${base}/${encodeURIComponent(id)}`),
  create: (input: CreateInput, files: File[]) =>
    files.length ? upload<Result>(base, ticketForm(input, files)) : request<Result>('POST', base, input),
  message: (id: string, input: MessageInput, files: File[]) =>
    files.length
      ? upload<Result>(`${base}/${id}/messages`, ticketForm(input, files))
      : request<Result>('POST', `${base}/${id}/messages`, input),
  status: (id: string, status: string) => request<Result>('POST', `${base}/${id}/status`, { status }),
  reopen: (id: string) => request<Result>('POST', `${base}/${id}/reopen`),
  assign: (id: string, assigneeId: string) => request<Result>('POST', `${base}/${id}/assign`, { assignee_id: assigneeId }),
}
