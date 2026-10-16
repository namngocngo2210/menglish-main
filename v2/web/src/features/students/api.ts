// API hồ sơ học viên (/api/v1/students).
import { request } from '../../api'

export type StudentStatus = 'waiting_start' | 'studying' | 'deferred' | 'summer_break' | 'completed' | 'dropped'
export type StatusColor = 'info' | 'success' | 'warning' | 'secondary' | 'primary' | 'error'
export type StatusOption = { value: StudentStatus; label: string; color: StatusColor }
export type Option = { value: string; label: string }

export type StudentRow = {
  id: string
  code: string
  full_name: string
  dob: string | null
  phone: string
  email: string | null
  branch_id: string | null
  branch_name: string | null
  current_class_id: string | null
  status: StudentStatus
  status_label: string
  study_started_on: string | null
  tenure_label: string | null
  long_term: boolean
  created_at: string
}

export type StudentList = {
  students: StudentRow[]
  total: number
  page: number
  per_page: number
  total_visible: number
  has_filters: boolean
}

export type StudentOptions = {
  branches: { id: string; code: string; name: string }[]
  statuses: StatusOption[]
  tenures: Option[]
  sorts: Option[]
}

export type Guardian = {
  id: string
  full_name: string
  phone: string | null
  relationship: string | null
  status: 'active' | 'disabled'
  must_change_password: boolean
  last_login_at: string | null
  children: number
  linked_at: string
}

export type StudentEvent = {
  id: string
  kind: string
  message: string
  from_status: StudentStatus | null
  to_status: StudentStatus | null
  actor_name: string | null
  created_at: string
}

export type StudentProfile = {
  id: string
  code: string
  full_name: string
  dob: string | null
  age: number | null
  gender: string | null
  phone: string | null
  email: string | null
  address: string | null
  parent_name: string | null
  parent_phone: string | null
  school: string | null
  target: string | null
  entrance_score: string | null
  notes: string | null
  branch_id: string | null
  branch_name: string | null
  current_class_id: string | null
  lead_id: string | null
  status: StudentStatus
  status_label: string
  study_started_on: string | null
  tenure_label: string | null
  long_term: boolean
  deferred_until: string | null
  created_at: string
  updated_at: string
  version: number
  account: { login: string | null; status: string; must_change_password: boolean; last_login_at: string | null } | null
  guardians: Guardian[]
  history: StudentEvent[]
  view: {
    scoped: boolean
    can_edit: boolean
    can_change_status: boolean
    can_assign_class: boolean
    can_delete: boolean
    can_view_academic: boolean
    can_view_contact: boolean
    can_view_tuition: boolean
  }
}

export type StudentInput = {
  full_name: string
  phone: string
  email: string
  dob: string
  gender: string
  target: string
  parent_name: string
  parent_phone: string
  school: string
  study_started_on: string
  address: string
  notes: string
  version?: number
}

export type ListParams = {
  q?: string
  branch_id?: string
  class_id?: string
  statuses?: string[]
  tenure?: string
  sort?: string
  without_class?: boolean
  page?: number
  per_page?: number
}

// listQuery dựng query string cho danh sách (bỏ tham số trống, trạng thái lặp lại statuses=…).
export function listQuery(p: ListParams): string {
  const qs = new URLSearchParams()
  if (p.q?.trim()) qs.set('q', p.q.trim())
  if (p.branch_id) qs.set('branch_id', p.branch_id)
  if (p.class_id) qs.set('class_id', p.class_id)
  for (const s of p.statuses ?? []) qs.append('statuses', s)
  if (p.tenure) qs.set('tenure', p.tenure)
  if (p.sort) qs.set('sort', p.sort)
  if (p.without_class) qs.set('without_class', '1')
  if (p.page && p.page > 1) qs.set('page', String(p.page))
  if (p.per_page) qs.set('per_page', String(p.per_page))
  const s = qs.toString()
  return s ? `?${s}` : ''
}

type Message = { message: string }
type TempAccount = { login: string; temporary_password: string }

export const studentsApi = {
  list: (p: ListParams) => request<StudentList>('GET', `/students${listQuery(p)}`),
  options: () => request<StudentOptions>('GET', '/students/options'),
  get: (id: string, scoped = false) =>
    request<StudentProfile>('GET', `/students/${encodeURIComponent(id)}${scoped ? '?view=scoped' : ''}`),
  update: (id: string, input: StudentInput) =>
    request<{ student: StudentProfile } & Message>('PATCH', `/students/${id}`, input),
  changeStatus: (id: string, status: StudentStatus) => request<Message>('PUT', `/students/${id}/status`, { status }),
  endDeferral: (id: string) => request<Message>('POST', `/students/${id}/end-deferral`),
  remove: (id: string) => request<Message>('DELETE', `/students/${id}`),
  addGuardian: (id: string, g: { phone: string; full_name: string; relationship: string }) =>
    request<{ guardian: Guardian; created: boolean } & Partial<TempAccount> & { login: string }>(
      'POST',
      `/students/${id}/guardians`,
      g,
    ),
  removeGuardian: (id: string, guardianId: string) => request<void>('DELETE', `/students/${id}/guardians/${guardianId}`),
  resetAccount: (id: string) => request<TempAccount & Message>('POST', `/students/${id}/reset-account`),
}
