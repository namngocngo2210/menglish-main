// API công việc (Go: internal/tasks). Thông báo lỗi tiếng Việt lấy từ máy chủ (errorMessage).
import { request } from '../../api'

export type TaskStatus = 'new' | 'in_progress' | 'pending_confirmation' | 'blocked' | 'completed' | 'overdue' | 'canceled'
export type Transition = { value: TaskStatus | 'hand_back'; label: string }
export type Option = { id: string; label: string }

export type Task = {
  id: string
  no: number
  title: string
  description: string | null
  status: TaskStatus
  status_label: string
  task_type: 'one_time' | 'recurring'
  type_label: string
  kind: string | null
  class_id: string | null
  lesson_session: string | null
  slot_label: string | null
  blocked_reason: string | null
  handed_back_from: string | null
  assignee_id: string | null
  assignee: string | null
  creator_id: string
  creator: string
  due_date: string | null
  due_time: string | null
  allowed: Transition[]
  created_at: string
  updated_at: string
  version: number
  // Chi tiết
  creator_is_me?: boolean
  branch_id?: string | null
  branch?: string | null
  handed_back_at?: string | null
  confirmed_by?: string | null
  confirmed_at?: string | null
  completed_at?: string | null
  rejection_reason?: string | null
  completion_note?: string | null
  completion_proof_url?: string | null
}

export type Counts = { mine: number; assigned: number; overdue: number; pending: number; approvals: number }

export type TaskList = {
  tasks: Task[]
  total: number
  page: number
  per_page: number
  tab: 'mine' | 'assigned'
  counts: Counts
  assignees: Option[]
}

export type ListParams = {
  tab: 'mine' | 'assigned'
  q?: string
  status?: string
  date_from?: string
  date_to?: string
  assignee_id?: string
  page?: number
  per_page?: number
}

export type CreateOptions = {
  title: string
  users: Option[]
  branches: Option[]
  default_due_date: string
  can_ta_assign: boolean
}

export type CreateInput = {
  title: string
  description: string
  assignee_id: string
  due_date: string
  due_time: string
  task_type: 'one_time' | 'recurring'
  frequency: string
  branch_id: string
}

export type ApprovalItem = {
  id: string
  no: number
  kind: 'task'
  title: string
  class_id: string | null
  lesson_session: string | null
  person: string | null
  creator: string
  assigned_date: string
  due: string | null
  note: string | null
  links: string[]
  updated_at: string
}
export type Approvals = { items: ApprovalItem[]; assignee_options: Option[]; can_approve: boolean }

export type SlotKey = 'before' | 'during' | 'after'
export type TAAssignOptions = {
  assistants: Option[]
  branches: Option[]
  slots: { value: SlotKey; label: string; due: string }[]
  today: string
  now_time: string
  cutoff: string
}
export type TAAssignInput = {
  assistant_id: string
  assign_date: string
  branch_id: string
  tasks: { category: SlotKey; content: string }[]
}

export type TACard = {
  id: string
  title: string
  status: TaskStatus
  status_label: string
  class_id: string | null
  class_label: string | null
  room: string | null
  session_time: string | null
  creator: string
  has_proof: boolean
  blocked_reason: string | null
  late_hours: number
  completed_at: string | null
  due_label: string
  rejection_reason: string | null
  pending_label: string | null
}
export type TAPortal = {
  title: string
  ta_user: { id: string; name: string; branch: string | null } | null
  done: number
  total: number
  date: string
  is_today: boolean
  can_pick_ta: boolean
  assistants: Option[]
  overdue_count: number
  can_complete: boolean
  has_tasks: boolean
  first_open: SlotKey
  groups: { key: SlotKey; title: string; tasks: TACard[] }[]
  sessions: unknown[]
}

type Message = { message: string }

export function query(params: Record<string, string | number | undefined>) {
  const qs = new URLSearchParams()
  for (const [k, v] of Object.entries(params)) if (v !== undefined && v !== '') qs.set(k, String(v))
  const s = qs.toString()
  return s ? `?${s}` : ''
}

export const tasksApi = {
  list: (p: ListParams) => request<TaskList>('GET', `/tasks${query(p)}`),
  get: (id: string) => request<Task>('GET', `/tasks/${id}`),
  options: () => request<CreateOptions>('GET', '/tasks/options'),
  create: (input: CreateInput) => request<{ task: Task; message: string }>('POST', '/tasks', input),
  changeStatus: (id: string, status: string, reason: string) =>
    request<{ task: Task; message: string }>('POST', `/tasks/${id}/status`, { status, reason }),
  approvals: (q: string, assignee_id: string) => request<Approvals>('GET', `/tasks/approvals${query({ q, assignee_id })}`),
  approve: (id: string, admin_note: string) => request<Message>('POST', `/tasks/${id}/approve`, { admin_note }),
  reject: (id: string, admin_note: string) => request<Message>('POST', `/tasks/${id}/reject`, { admin_note }),
  taAssignOptions: () => request<TAAssignOptions>('GET', '/tasks/ta-assign/options'),
  taAssign: (input: TAAssignInput) =>
    request<{ created: number; late: boolean; message: string }>('POST', '/tasks/ta-assign', input),
  taPortal: (date: string, ta_id: string) => request<TAPortal>('GET', `/tasks/ta-portal${query({ date, ta_id })}`),
  complete: (id: string, note: string, proof_image_url: string) =>
    request<Message>('POST', `/tasks/${id}/complete`, { note, proof_image_url }),
}
