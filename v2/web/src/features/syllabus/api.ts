// API Giáo trình & Big Test (Go: internal/syllabus). Lỗi tiếng Việt lấy từ máy chủ (errorMessage).
import { request, upload } from '../../api'

export type Option = { value: string; label: string }
export type Paged = { total: number; page: number; page_size: number }
// tone: success (mặc định) | warning (làm được một phần)
export type Message = {
  message: string
  id?: string
  tone?: 'success' | 'warning'
}
// Màu nhãn trạng thái của máy chủ
export type Color = 'warning' | 'success' | 'error' | 'neutral' | 'info'

// --- Tài liệu ---
export type DocKind = 'pdf' | 'image' | 'audio' | 'video' | 'word' | 'slide' | 'sheet' | 'file' | string
export type Document = {
  id: string
  title: string
  icon: string
  kind: DocKind
  curriculum_id: string
  curriculum: string
  extension: string
  size_human: string
  stage_label: string
  audience_labels: string[]
  downloadable: boolean
  can_download: boolean
  inline: boolean
  url: string
  viewed: boolean
  created_at: string
}
export type CurriculumRef = {
  id: string
  title: string
  code: string
  version: string
  stages: Option[]
}
export type DocumentList = Paged & {
  documents: Document[]
  curriculums: CurriculumRef[]
  can_upload: boolean
}
export type DocumentInput = {
  curriculum_id: string
  stage_id: string
  title: string
  visible_to_teachers: boolean
  visible_to_assistants: boolean
  downloadable: boolean
}

// --- Soạn syllabus ---
export type Lesson = {
  id: string
  session_no: number
  title: string
  unit_id: string
  objectives: string | null
  content: string | null
  homework_guide: string | null
  vocabulary_focus: string | null
  grammar_focus: string | null
}
export type Unit = {
  id: string
  stage_id: string | null
  unit_number: number
  title: string
  objectives: string | null
  vocabulary_focus: string | null
  grammar_focus: string | null
  homework_guide: string | null
  lessons: Lesson[]
}
export type Stage = {
  id: string
  position: number
  label: string
  name: string
  description: string | null
  overview_link: string | null
  big_test_title: string | null
  big_test_note: string | null
  open_classes: string
  units: Unit[]
}
export type Curriculum = {
  id: string
  title: string
  code: string
  version: string
  course_id: string | null
  description: string | null
  level_ids: string[]
}
export type BuilderPage = {
  curriculums: Option[]
  curriculum: Curriculum | null
  stages: Stage[]
  totals: {
    units: number
    lessons: number
    next_position: number
    next_unit_number: number
    next_session_no: number
  }
  unit_options: Option[]
  courses: Option[]
  levels: { id: string; code: string; name: string; other: boolean }[]
  new_code: string
  can_manage: boolean
}
export type CurriculumInput = {
  code: string
  title: string
  version: string
  course_id: string | null
  description?: string | null
  level_ids?: string[]
  stage_name?: string | null
}
export type StageInput = {
  curriculum_id: string
  name: string
  description: string | null
  overview_link: string | null
  big_test_title: string | null
  big_test_note: string | null
}
export type UnitInput = {
  curriculum_id: string
  stage_id: string | null
  unit_number: number
  title: string
  objectives: string | null
}
export type LessonInput = {
  unit_id: string | null
  session_no: number
  title: string
  objectives: string | null
  content: string | null
  homework_guide: string | null
  vocabulary_focus: string | null
  grammar_focus: string | null
}

// --- Giao chặng ---
export type Assignment = {
  id: string
  code: string
  class_id: string | null
  class_name: string
  class_place: string
  curriculum: string
  teacher: string
  teacher_code: string
  user_id: string
  stage_label: string
  assigned_chapters: string
  extra_sessions: number
  start_date: string
  closed_at: string
  close_reason: string | null
  deadline: string
  is_open: boolean
  status: string
  status_label: string
  curriculum_completed: boolean
  closing_big_test: string | null
}
export type AssignmentsPage = Paged & {
  assignments: Assignment[]
  teachers: Option[]
  curriculums: CurriculumRef[]
  classes: { id: string; name: string; code: string }[]
  class_curriculum: Record<string, string | null>
  class_open_stage: Record<string, string>
  can_manage: boolean
  can_override: boolean
  can_view_log: boolean
}
export type AssignmentInput = {
  class_id: string
  curriculum_id: string | null
  stage_id: string | null
  user_id: string | null
  start_date: string | null
  deadline: string | null
  replace_current: boolean
  reason: string | null
}
export type TeachingStage = {
  id: string
  class_id: string | null
  stage_label: string
  class_label: string
  start_date: string
  is_assistant: boolean
  role_label: string
  exam: 'approved' | 'pending' | 'none'
  date: string
  big_test_code: string | null
  expected_date: string
  can_set_date: boolean
  can_order: boolean
  order_status: string | null
  order_rejection: string | null
}
export type TeachingStagesPage = {
  assignments: TeachingStage[]
  today: string
  month: string
}

// --- Xem bài giảng ---
export type ViewStage = {
  id: string
  label: string
  state: 'open' | 'done' | 'todo'
  summary?: string
  overview_link?: string | null
}
export type TeacherViewPage = {
  documents: Document[]
  selected: {
    id: string
    title: string
    icon: string
    kind: DocKind
    extension: string
    meta: string
    can_download: boolean
    viewed: boolean
    url: string
  } | null
  search: string
  initial_tab: string
  classes: Option[]
  class_id: string | null
  overview_curriculum: string
  overview_stages: ViewStage[]
  stages: ViewStage[]
  assignment: {
    stage_label: string
    curriculum: string
    opened_at: string
    extra_sessions: number
    big_test_title: string
    has_stage: boolean
  } | null
  position: {
    taught: number
    lessons: number
    over: number
    current: {
      id: string
      session_no: number
      unit_number: number
      title: string
    } | null
  } | null
  stage_units: Unit[]
}

// --- Đề xuất sửa giáo trình ---
export type ProposalRow = {
  id: string
  curriculum: string
  target_label: string
  target: string
  proposer: string
  new_content: string
  created_at: string
  status: 'pending' | 'approved' | 'rejected'
  status_label: string
  status_color: Color
  review_note: string | null
}
export type ProposalDetail = {
  id: string
  curriculum_id: string
  curriculum: string
  curriculum_version: string
  target_label: string
  proposer: string
  proposer_roles: string
  proposal_type: string | null
  old_content: string | null
  new_content: string
  reason: string | null
  attachment_name: string | null
  attachment_url: string | null
  status: ProposalRow['status']
  status_label: string
  status_color: Color
  reviewer: string | null
  review_note: string | null
  created_at: string
  reviewed_at: string | null
  edit_lesson_id: string | null
}
export type VersionsPage = Paged & {
  proposals: ProposalRow[]
  selected: ProposalDetail | null
  pending_count: number
  status_options: Option[]
  can_review: boolean
  can_manage: boolean
}
export type TeacherProposePage = Paged & {
  proposals: ProposalRow[]
  curriculums: { id: string; label: string; lessons: Option[] }[]
  status_options: Option[]
  can_propose: boolean
}
export type ProposalInput = {
  curriculum_id: string
  lesson_id: string
  proposal_type: string
  old_content: string
  new_content: string
  reason: string
}

// --- Điều chỉnh tiến độ ---
export type PacingRow = {
  id: string
  teacher: string
  class_stage_label: string
  extra_sessions: number
  reason: string
  applied_note: string | null
  created_at: string
  status: 'pending' | 'approved' | 'rejected'
  status_label: string
  rejection_reason: string | null
  sla_overdue: boolean
}
export type PacingDetail = PacingRow & {
  teacher_code: string
  request_type: string
  class_end_date: string
  reviewed_by: string
  requested_sessions: number | null
  class_deleted: boolean
  stage_closed: boolean
  stage_name: string | null
}
export type TeacherAdjustPage = Paged & {
  requests: PacingRow[]
  classes: Option[]
  sla_days: number
  can_review: boolean
  max_extra_sessions: number
}
export type AdjustmentRequestsPage = Paged & {
  requests: PacingRow[]
  selected: PacingDetail | null
  status: string
  status_options: Option[]
  list_title: string
  pending_count: number
  max_extra_sessions: number
  can_review: boolean
}

// --- Big Test ---
export type PaperWarning = { level: 'warn' | 'overdue'; label: string }
export type BigTestRow = {
  id: string
  code: string
  title: string
  content_url: string | null
  speaking_url: string | null
  class_id: string | null
  class_name: string | null
  stage_label: string | null
  stage_id: string
  scheduled_at: string
  room: string | null
  place?: string
  proctor: string | null
  passcode: string
  is_distributed: boolean
  paper_warning: PaperWarning | null
  stage_options?: Option[]
}
export type OrderRow = {
  id: string
  class_label: string
  ordered_at: string
  stage_type: string
  teacher: string | null
  exam_date: string
  due_date: string
  overdue: boolean
  sla_warning: boolean
  status: string
  status_label: string
  status_color: Color
}
export type OrderDetail = {
  id: string
  code: string
  class_code: string
  class_label: string
  summary: string
  status: string
  status_label: string
  status_color: Color
  exam_date: string
  teacher: string | null
  due_date: string
  overdue: boolean
  sla_warning: boolean
  note: string | null
  reviewer: string | null
  reviewed_at: string
  rejection_reason: string | null
  test_link: string | null
  speaking_link: string | null
  big_test: {
    code: string
    scheduled_at: string
    room: string | null
  } | null
  reviewing: boolean
  is_big: boolean
  default_scheduled_at: string
  default_room: string | null
  class_tests: Option[]
}
export type DistributionPage = {
  classes: Option[]
  default_scheduled_at: string
  orders: OrderRow[]
  orders_page: Paged
  order_status: string
  order_search: string
  order_status_options: Option[]
  pending_orders: number
  big_tests: BigTestRow[]
  tests_page: Paged
  selected_order: OrderDetail | null
  lead_days: number
  can_review: boolean
  can_approve: boolean
  can_manage: boolean
}
export type DistributionParams = {
  order_status: string
  order_search?: string
  orders_page?: number
  tests_page?: number
  order?: string
}
export type BigTestInput = {
  title: string
  class_id: string
  test_type: string
  scheduled_at: string
  room: string
}
export type OrderApproval = {
  test_link: string
  speaking_link: string
  big_test_id: string
  scheduled_at: string
  room: string
}
export type SchedulesPage = {
  upcoming: {
    id: string
    code: string
    class_name: string
    stage_label: string
    date: string
    exam: string
    days_left: number
  }[]
  urgent: number
  big_tests: BigTestRow[]
  page: Paged
  can_manage: boolean
}

export type ResultCell = {
  id: string
  status: 'draft' | 'pending_review' | 'approved' | 'sent'
  status_label: string
  locked: boolean
  is_absent: boolean
  listening_score: number | null
  reading_score: number | null
  writing_score: number | null
  speaking_score: number | null
  overall_score: number | null
  progress_note: string | null
  video_url: string | null
  parent_notified: boolean
  notified_at: string
  missing_phone: boolean
}
export type ResultsPage = {
  test: {
    id: string
    code: string
    room: string | null
    class_name: string | null
    class_teacher: string | null
    is_distributed: boolean
    results_due: string
    results_overdue: boolean
    days_left: number | null
    stage_badge: string | null
    stage_label: string
  } | null
  all_tests: Option[]
  stats: {
    total: number
    taken: number
    absent: number
    avg: number
    highest: number
  }
  rows: {
    student_id: string
    name: string
    code: string
    result: ResultCell | null
  }[]
  selected_result:
    | (ResultCell & {
        student: string
        student_code: string
        grader: string | null
        approver: string | null
      })
    | null
  is_approver: boolean
  can_approve: boolean
  can_grade_role: boolean
  can_grade: boolean
  can_back: boolean
  result_deadline_days: number
  missing_phone_label: string
}
export type ResultInput = {
  student_id: string
  listening_score: number | null
  reading_score: number | null
  writing_score: number | null
  speaking_score: number | null
  progress_note: string | null
  video_url: string | null
  is_absent: boolean
}

// --- Cổng GV: order đề ---
export type OrderTestPage = {
  classroom: { id: string; name: string; program: string | null }
  open_assignment: {
    label: string
    started_at: string
    assigned_chapters: string
    expected_big_test_date: string
  } | null
  closed_assignments: { id: string; label: string; closed_at: string }[]
  lead_days: number
  today: string
  requests: {
    id: string
    created_at: string
    type_label: string
    stage_label: string
    exam_date: string
    due_date: string
    note: string | null
    status: string
    status_label: string
    status_color: Color
    test_link: string | null
    speaking_link: string | null
    rejection_reason: string | null
  }[]
}

export function query(params: Record<string, string | number | undefined | null>) {
  const qs = new URLSearchParams()
  for (const [k, v] of Object.entries(params)) if (v !== undefined && v !== null && v !== '') qs.set(k, String(v))
  const s = qs.toString()
  return s ? `?${s}` : ''
}

const S = '/syllabus'
const post = (path: string, body?: unknown) => request<Message>('POST', S + path, body ?? {})

export const syllabusApi = {
  documents: (p: { curriculum_id?: string; search?: string; page?: number }) => request<DocumentList>('GET', `${S}/documents${query(p)}`),
  uploadDocument: (input: DocumentInput, file: File) => {
    const f = new FormData()
    f.append('curriculum_id', input.curriculum_id)
    f.append('stage_id', input.stage_id)
    f.append('title', input.title)
    if (input.visible_to_teachers) f.append('visible_to_teachers', '1')
    if (input.visible_to_assistants) f.append('visible_to_assistants', '1')
    if (input.downloadable) f.append('downloadable', '1')
    f.append('file', file)
    return upload<Message>(`${S}/documents`, f)
  },
  deleteDocument: (id: string) => request<Message>('DELETE', `${S}/documents/${id}`),
  markViewed: (id: string) => post(`/documents/${id}/viewed`),

  builder: (curriculum?: string) => request<BuilderPage>('GET', `${S}/builder${query({ curriculum })}`),
  createCurriculum: (input: CurriculumInput) => post('/curricula', input),
  updateCurriculum: (id: string, input: CurriculumInput) => request<Message>('PUT', `${S}/curricula/${id}`, input),
  deleteCurriculum: (id: string) => request<Message>('DELETE', `${S}/curricula/${id}`),
  createStage: (input: StageInput) => post('/stages', input),
  updateStage: (id: string, input: StageInput) => request<Message>('PUT', `${S}/stages/${id}`, input),
  deleteStage: (id: string) => request<Message>('DELETE', `${S}/stages/${id}`),
  moveStage: (id: string, direction: 'up' | 'down') => post(`/stages/${id}/move`, { direction }),
  createUnit: (input: UnitInput) => post('/units', input),
  updateUnit: (id: string, input: UnitInput) => request<Message>('PUT', `${S}/units/${id}`, input),
  deleteUnit: (id: string) => request<Message>('DELETE', `${S}/units/${id}`),
  createLesson: (input: LessonInput) => post('/lessons', input),
  updateLesson: (id: string, input: LessonInput) => request<Message>('PUT', `${S}/lessons/${id}`, input),
  deleteLesson: (id: string) => request<Message>('DELETE', `${S}/lessons/${id}`),

  assignments: (p: { search?: string; status?: string; class_id?: string; page?: number }) =>
    request<AssignmentsPage>('GET', `${S}/assignments${query(p)}`),
  openAssignment: (input: AssignmentInput) => post('/assignments', input),
  updateAssignment: (
    id: string,
    input: {
      user_id: string
      start_date: string | null
      deadline: string | null
    },
  ) => request<Message>('PUT', `${S}/assignments/${id}`, input),
  closeAssignment: (id: string, reason: string, open_next: boolean) => post(`/assignments/${id}/close`, { reason, open_next }),
  setExpectedDate: (id: string, expected_big_test_date: string) =>
    post(`/assignments/${id}/expected-big-test-date`, {
      expected_big_test_date,
    }),
  teachingStages: () => request<TeachingStagesPage>('GET', `${S}/teaching-stages`),
  teacherView: (p: { class?: string; search?: string; document?: string; tab?: string }) =>
    request<TeacherViewPage>('GET', `${S}/teacher-view${query(p)}`),

  versions: (p: { status?: string; page?: number; proposal?: string }) => request<VersionsPage>('GET', `${S}/versions${query(p)}`),
  teacherPropose: (p: { status?: string; page?: number }) => request<TeacherProposePage>('GET', `${S}/teacher-propose${query(p)}`),
  createProposal: (input: ProposalInput, file: File | null) => {
    const f = new FormData()
    for (const [k, v] of Object.entries(input)) if (v) f.append(k, v)
    if (file) f.append('file', file)
    return upload<Message>(`${S}/proposals`, f)
  },
  approveProposal: (id: string, review_note: string) => post(`/proposals/${id}/approve`, { review_note }),
  rejectProposal: (id: string, review_note: string) => post(`/proposals/${id}/reject`, { review_note }),

  teacherAdjust: (page?: number) => request<TeacherAdjustPage>('GET', `${S}/teacher-adjust${query({ page })}`),
  adjustmentRequests: (p: { status?: string; page?: number; request?: string }) =>
    request<AdjustmentRequestsPage>('GET', `${S}/adjustment-requests${query(p)}`),
  createPacing: (input: { class_id: string; reason: string; extra_sessions: number | null; request_type?: string }) =>
    post('/adjustment-requests', input),
  approvePacing: (id: string, extra_sessions: number | null) => post(`/adjustment-requests/${id}/approve`, { extra_sessions }),
  rejectPacing: (id: string, rejection_reason: string) => post(`/adjustment-requests/${id}/reject`, { rejection_reason }),

  // order_status luôn gửi (rỗng = mọi trạng thái; thiếu tham số thì máy chủ lọc "chờ duyệt").
  distribution: (p: DistributionParams) => {
    const rest = query({ ...p, order_status: undefined })
    return request<DistributionPage>('GET', `${S}/big-tests/distribution?order_status=${encodeURIComponent(p.order_status)}${rest.replace('?', '&')}`)
  },
  createBigTest: (input: BigTestInput) => post('/big-tests', input),
  approveBigTest: (id: string) => post(`/big-tests/${id}/approve`),
  assignStage: (id: string, stage: string) => post(`/big-tests/${id}/stage`, { syllabus_stage_id: stage }),
  approveOrder: (id: string, input: OrderApproval) => post(`/big-test-orders/${id}/approve`, input),
  rejectOrder: (id: string, rejection_reason: string) => post(`/big-test-orders/${id}/reject`, { rejection_reason }),
  schedules: (page?: number) => request<SchedulesPage>('GET', `${S}/big-tests/schedules${query({ page })}`),
  remind: (id: string) => post(`/big-tests/${id}/remind`),
  results: (test_id?: string, result?: string) => request<ResultsPage>('GET', `${S}/big-tests/results${query({ test_id, result })}`),
  storeResults: (id: string, action: 'draft' | 'submit', results: ResultInput[]) => post(`/big-tests/${id}/results`, { action, results }),
  approveResults: (id: string) => post(`/big-tests/${id}/results/approve`),
  sendZalo: (id: string) => post(`/big-tests/${id}/send-zalo`),
  sendSingle: (id: string) => post(`/big-test-results/${id}/send-zalo`),
  approveSend: (id: string) => post(`/big-test-results/${id}/approve-send`),

  orderTest: (classId: string) => request<OrderTestPage>('GET', `/teacher/order-test/${classId}`),
  submitOrderTest: (classId: string, input: { test_type: string; exam_date: string; note: string }) =>
    request<Message>('POST', `/teacher/order-test/${classId}`, input),
}
