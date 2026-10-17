// API Chất lượng lớp (dự giờ vận hành, đánh giá dự giờ, checklist học phí & feedback, họp giáo viên) và Dự án học thuật
// (ObservationController, ClassChecklistController, TeacherMeetingReportController, AcademicProjectController của Laravel).
import { request } from '../../api'
import { query } from './logic'

export type Option = { value: string; label: string }
export type FieldDef = { key: string; label: string }
export type Meta = { page: number; per_page: number; total: number }
type Msg = { message: string }

// ───────────────────────── Dự giờ vận hành ─────────────────────────

export type Operation = {
  id: string
  observed_on: string
  teacher_id: string | null
  teacher: string | null
  teacher_code: string
  class_id: string
  class: string
  branch: string | null
  observer: string | null
  rating: string
  rating_label: string
  rating_color: string
  notes: Record<string, string | null>
}

export type FormClass = { value: string; label: string; course?: string | null; teacher_ids: string[] }

export type OperationsPage = {
  observations: Operation[]
  meta: Meta
  classes: FormClass[]
  teachers: Option[]
  filter_teachers: Option[]
  ratings: Option[]
  note_fields: FieldDef[]
  can_record: boolean
  today: string
}

export type OperationInput = {
  observed_on: string
  teacher_id: string
  class_id: string
  rating: string
  lesson_content: string | null
  attitude: string | null
  preparation: string | null
  technique: string | null
  suggestions: string | null
}

// ───────────────────────── Đánh giá dự giờ ─────────────────────────

export type Evaluation = {
  observed: boolean
  observed_on: string | null
  observer_id: string | null
  observer: string | null
  attendance_rate: number | null
  pass_rate: number | null
  action_notes: string | null
  criteria: Record<string, string | null>
}

export type AcademicClass = {
  id: string
  code: string
  name: string
  teacher: string | null
  course: string | null
  suggested_attendance: number | null
  evaluation: Evaluation | null
}

export type AcademicPage = {
  month: string
  months: Option[]
  classes: AcademicClass[]
  meta: Meta
  stats: { total: number; observed: number }
  criteria: FieldDef[]
  observers: Option[]
  can_record: boolean
  current_user_id: string
}

export type AcademicInput = {
  class_id: string
  month: string
  attendance_rate: number | null
  pass_rate: number | null
  observed: boolean
  observed_on: string | null
  observer_id: string | null
  action_notes: string | null
  teaching_quality: string | null
  lesson_content: string | null
  interaction: string | null
  attitude: string | null
  effectiveness: string | null
  overall: string | null
}

// ───────────────────────── Checklist ─────────────────────────

export type ChecklistClass = {
  id: string
  code: string
  name: string
  teacher: string | null
  values: Record<string, string | null>
  tuition_note: string | null
  gaps: string[]
}

export type ChecklistPage = {
  month: string
  months: Option[]
  classes: ChecklistClass[]
  meta: Meta
  items: FieldDef[]
  follow_ups: Record<string, string[]>
  answers: FieldDef[]
  can_edit: boolean
}

export type ChecklistRow = { class_id: string; tuition_note: string | null } & Record<string, string | null>

// ───────────────────────── Họp giáo viên ─────────────────────────

export type Meeting = {
  id: string
  week_start: string
  week_end: string
  teacher_id: string
  teacher: string | null
  class_id: string | null
  class: string | null
  course: string | null
  author: string | null
  syllabus_note: string | null
  scores_note: string | null
  class_note: string
  academic_order: string | null
  recommendation: string | null
  status: string
  status_label: string
  status_color: string
}

export type MeetingsPage = {
  reports: Meeting[]
  meta: Meta
  teachers: Option[]
  classes: FormClass[]
  statuses: Option[]
  can_manage: boolean
  current_week: string
}

export type MeetingInput = {
  week_start: string
  teacher_id: string
  class_id: string | null
  syllabus_note: string | null
  scores_note: string | null
  class_note: string
  academic_order: string | null
  recommendation: string | null
  status: string
}

// ───────────────────────── Dự án học thuật ─────────────────────────

export type ProjectSummary = {
  id: string
  code: string
  name: string
  type: string
  type_label: string
  status: string
  status_label: string
  status_color: string
  owner: string | null
  start_date: string | null
  deadline: string | null
  progress: number
  milestones_total: number
  milestones_done: number
  milestones_overdue: number
  next_milestone: { title: string; due_date: string } | null
  members_count: number
}

export type ProjectsPage = {
  data: ProjectSummary[]
  meta: Meta
  types: Option[]
  statuses: Option[]
  owners: Option[]
  can_manage: boolean
}

export type Milestone = {
  id: string
  title: string
  description: string | null
  assignee_id: string | null
  assignee: string | null
  start_date: string | null
  due_date: string
  target_quantity: number
  done_quantity: number
  quantity_label: string
  unit: string
  status: string
  status_label: string
  completed_at: string | null
  progress: number
  deadline_state: 'overdue' | 'soon' | null
  mine: boolean
  can_update: boolean
}

export type ProgressUpdate = {
  id: string
  user: string | null
  milestone: string | null
  created_at: string
  quantity: string | null
  content: string
  difficulties: string | null
  links: string[]
  marks_complete: boolean
  response: string | null
  responder: string | null
  responded_at: string | null
}

export type Penalty = {
  id: string
  code: string
  user_id: string | null
  user: string | null
  milestone_id: string
  milestone: string | null
  status: string
  status_label: string
  amount: number
  created_at: string | null
  url: string
}

export type ProjectDetail = {
  project: ProjectSummary & {
    description: string | null
    owner_id: string | null
    member_ids: string[]
    members: { id: string; name: string }[]
    kickoff_notes: string | null
    kickoff_link: string | null
    plan_locked_at: string | null
    locker: string | null
    creator: string | null
    completed_at: string | null
  }
  milestones: Milestone[]
  updates: ProgressUpdate[]
  penalties: Penalty[]
  penalties_pending: boolean
  staff: Option[]
  types: Option[]
  milestone_statuses: Option[]
  can_manage: boolean
  can_update: boolean
  can_respond: boolean
  grace_hours: number
  sla_enabled: boolean
}

export type ProjectInput = {
  name: string
  type: string
  owner_id: string
  member_ids: string[]
  start_date: string | null
  deadline: string | null
  description: string | null
  kickoff_notes: string | null
  kickoff_link: string | null
}

export type MilestoneInput = {
  title: string
  assignee_id: string
  status: string
  start_date: string | null
  due_date: string | null
  target_quantity: number | null
  unit: string
  description: string | null
}

export type UpdateInput = {
  milestone_id: string
  quantity_done: string
  content: string
  difficulties: string
  links_text: string
  marks_complete: boolean
}

export type ProjectReport = {
  month: string
  months: Option[]
  status: string
  statuses: Option[]
  stats: { active: number; overdue: number; done_in_month: number; due_in_month: number; penalties_in_month: number; open_issues: number }
  projects: (ProjectSummary & { last_update: { at: string; user: string | null } | null; open_issues: number })[]
  members: {
    id: string | null
    name: string
    assigned: number
    done_on_time: number
    done_late: number
    overdue: number
    in_progress: number
    updates_in_month: number
    penalties: number
  }[]
  issues: { id: string; project_id: string; project: string | null; user: string | null; created_at: string; difficulties: string; responded: boolean }[]
  penalties: Penalty[]
  penalties_pending: boolean
}

export type ProjectFilter = { search: string; type: string; status: string; owner_id: string; page: number }

export const qualityApi = {
  operations: (f: { teacher_id: string; rating: string; page: number }) => request<OperationsPage>('GET', `/class-quality/operations${query(f)}`),
  createOperation: (in_: OperationInput) => request<Msg>('POST', '/class-quality/operations', in_),
  updateOperation: (id: string, in_: OperationInput) => request<Msg>('PUT', `/class-quality/operations/${id}`, in_),
  deleteOperation: (id: string) => request<Msg>('DELETE', `/class-quality/operations/${id}`),

  academic: (f: { month: string; status: string; search: string; page: number }) => request<AcademicPage>('GET', `/class-quality/academic${query(f)}`),
  saveAcademic: (in_: AcademicInput) => request<Msg>('PUT', '/class-quality/academic', in_),

  checklist: (f: { month: string; search: string; page: number }) => request<ChecklistPage>('GET', `/class-quality/checklist${query(f)}`),
  saveChecklist: (month: string, rows: ChecklistRow[]) => request<Msg>('PUT', '/class-quality/checklist', { month, rows }),

  meetings: (f: { week: string; teacher_id: string; status: string; page: number }) => request<MeetingsPage>('GET', `/class-quality/teacher-meetings${query(f)}`),
  createMeeting: (in_: MeetingInput) => request<Msg>('POST', '/class-quality/teacher-meetings', in_),
  updateMeeting: (id: string, in_: MeetingInput) => request<Msg>('PUT', `/class-quality/teacher-meetings/${id}`, in_),
  deleteMeeting: (id: string) => request<Msg>('DELETE', `/class-quality/teacher-meetings/${id}`),
}

export const projectsApi = {
  list: (f: ProjectFilter) => request<ProjectsPage>('GET', `/academic-projects${query(f)}`),
  get: (id: string) => request<ProjectDetail>('GET', `/academic-projects/${id}`),
  create: (in_: ProjectInput) => request<Msg & { id: string }>('POST', '/academic-projects', in_),
  update: (id: string, in_: ProjectInput) => request<Msg>('PUT', `/academic-projects/${id}`, in_),
  remove: (id: string) => request<Msg>('DELETE', `/academic-projects/${id}`),
  lock: (id: string) => request<Msg>('POST', `/academic-projects/${id}/lock`),
  status: (id: string, action: string) => request<Msg>('POST', `/academic-projects/${id}/status`, { action }),
  createMilestone: (id: string, in_: MilestoneInput) => request<Msg>('POST', `/academic-projects/${id}/milestones`, in_),
  updateMilestone: (id: string, in_: MilestoneInput) => request<Msg>('PUT', `/academic-projects/milestones/${id}`, in_),
  deleteMilestone: (id: string) => request<Msg>('DELETE', `/academic-projects/milestones/${id}`),
  postUpdate: (id: string, in_: UpdateInput) => request<Msg>('POST', `/academic-projects/${id}/updates`, in_),
  respond: (updateId: string, response: string) => request<Msg>('POST', `/academic-projects/updates/${updateId}/respond`, { response }),
  report: (month: string, status: string) => request<ProjectReport>('GET', `/academic-projects/report${query({ month, status })}`),
}
