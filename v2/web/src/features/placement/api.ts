// API module Test đầu vào & học thử (v2/api/internal/placement).
import { request } from '../../api'

export type Opt = { value: string; label: string }
export type Skill = 'listening' | 'reading_writing' | 'speaking'
export type MaxScores = Record<Skill, number>
export type Band = { min: number; range: string; text: string }
export type Placement = { lt: number | null; lte: number | null; range: string; class: string }
export type Rubric = {
  key: string
  label: string
  level: string
  max: MaxScores
  bands: Partial<Record<Skill, Band[]>>
  placements: Placement[]
}
export type RubricGroupConfig = {
  key: string
  label: string
  has_rubric: boolean
  max: MaxScores
  bands: Partial<Record<Skill, Band[]>>
  placements: Placement[]
}
export type RubricConfig = {
  groups: RubricGroupConfig[]
  notice: string
  skills: Opt[]
  class_options: string[]
  rubrics: Rubric[]
}

export type QuestionOption = { key: string; text: string; image_url?: string }
export type Question = {
  id: string
  number?: string
  section?: string
  skill: string
  type: string
  title: string
  passage?: string
  audio_url?: string
  image_url?: string
  options?: QuestionOption[]
  correct_answer: string
  points: number | null
  explanation?: string
  teacher_note?: string
  rubric_note?: string
  cue_points?: string
  min_words?: number
}

export type TestSummary = {
  id: string
  code: string
  title: string
  is_preset: boolean
  is_active: boolean
  type: string
  grade_group: string
  grade_group_label: string
  target_level: string
  duration_minutes: number
  questions_count: number
  submissions_count: number
  created_at: string
}
export type TestStats = {
  total_tests: number
  active_tests: number
  hidden_tests: number
  total_submissions: number
  pending_submissions: number
}
export type TestList = { tests: TestSummary[]; total: number; page: number; per_page: number; stats: TestStats }
export type TestDetail = {
  id: string
  code: string
  title: string
  description: string
  target_level: string
  grade_level: string
  grade_group: string
  grade_group_label: string
  duration_minutes: number
  questions_count: number
  questions: Question[]
  pdf_path: string
  audio_url: string
  is_active: boolean
  is_preset: boolean
  created_at: string
  updated_at: string
}
export type TestInput = {
  code?: string
  title: string
  description: string | null
  target_level: string
  grade_level: string | null
  duration_minutes: number | null
  questions: Question[]
  // Không gửi mode: giữ đề PDF cũ (nhập từ Laravel); 'manual' = đề soạn từng câu
  mode?: 'manual'
  audio_url: string | null
  save_mode?: 'draft' | 'publish'
  is_active?: boolean
}
export type FormOptions = {
  grade_levels: Opt[]
  grade_groups: Opt[]
  level_rubric_groups: Record<string, string>
  grade_code_tokens: Record<string, string>
  suggested_code: string
  rubric: RubricConfig
  max_violations: number
}
export type WithMessage<T> = { data: T; message: string }

export type InvitationStatus = 'active' | 'expired' | 'revoked' | 'used_up'
export type Invitation = {
  id: string
  test_id: string
  kind: 'public' | 'lead'
  lead_id: string | null
  lead_name?: string
  candidate_name: string
  candidate_phone: string
  note: string
  expires_at: string
  max_submissions: number | null
  submission_count: number
  opened_at: string | null
  revoked_at: string | null
  created_by_name?: string
  created_at: string
  status: InvitationStatus
}
export type InvitationInput = {
  kind: 'public' | 'lead'
  lead_id?: string
  expires_in_days?: number
  max_submissions?: number
  note?: string
}
export type IssuedInvitation = { invitation: Invitation; token: string; path: string }

export type SubmissionRow = {
  id: string
  number: number
  test_id: string
  test_title: string
  test_code: string
  lead_id: string | null
  candidate_name: string
  candidate_phone: string
  violation_count: number
  auto_submitted: boolean
  created_at: string
  listening: string
  reading_writing: string
  speaking: string
  is_pending: boolean
  total: string
  score_summary: string | null
  final_class: string
}
export type SubmissionList = {
  submissions: SubmissionRow[]
  selected_test: { id: string; title: string; code: string } | null
}
export type ViolationEntry = { at: string; type: string; label: string }
export type SubmissionDetail = {
  id: string
  number: number
  test_id: string
  test_code: string
  test_title: string
  test_pdf_url: string
  lead_id: string | null
  candidate_name: string
  candidate_phone: string
  candidate_email: string
  status: 'pending' | 'graded'
  is_pending: boolean
  score_summary: string | null
  submitted_at: string
  graded_at: string | null
  grader_name: string
  grade_group: string
  grade_group_label: string
  has_rubric: boolean
  has_rubric_grade: boolean
  max_scores: MaxScores
  listening_score: number | null
  reading_score: number | null
  writing_score: number | null
  speaking_score: number | null
  reading_writing_score: number | null
  overall_score: number | null
  total_score: number | null
  suggested_class: string
  final_class: string
  overridden: boolean
  comments: Partial<Record<Skill, string>>
  teacher_comments: string
  violation_count: number
  auto_submitted: boolean
  violations: ViolationEntry[]
  writing_content: string
  writing_word_count: number
  speaking_audio_url: string
  speaking_self_rate: string
}
export type ReviewOption = { key: string; text: string; is_correct: boolean; is_chosen: boolean }
export type ReviewRow = {
  number: number
  pdf_number: string | null
  section?: string
  skill: string
  type: string
  title: string
  points: number
  audio_url?: string
  image_url?: string
  passage?: string
  rubric_note?: string
  cue_points?: string
  explanation?: string
  correct_answer: string
  candidate_answer: string
  is_objective: boolean
  is_correct: boolean
  is_incorrect: boolean
  options: ReviewOption[]
}
export type RubricInitial = {
  group: string
  listening: string
  reading_writing: string
  speaking: string
  chosen: string
  comments: Partial<Record<Skill, string>>
  teacher_comments: string
  edited: Partial<Record<Skill, boolean>>
}
export type GradingView = { submission: SubmissionDetail; questions: ReviewRow[]; rubric: RubricInitial }
export type GradeInput = {
  grade_group: string
  listening_score: string
  reading_writing_score: string
  speaking_score: string
  chosen_class: string
  listening_comment: string
  reading_writing_comment: string
  speaking_comment: string
  teacher_comments: string
  action: 'draft' | 'confirm'
}

export type TrialStatus = 'scheduled' | 'attended' | 'no_show' | 'cancelled'
export type TrialRow = {
  id: string
  status: TrialStatus
  status_label: string
  class_id: string
  class_session_id: string
  class_name: string
  course_name: string
  branch_name: string
  date: string
  start: string
  end: string
  lead_id: string
  customer_name: string
  parent_name: string
  stage_label: string
  test_score: string
  assigned_name: string
  booked_by: string
  notes: string
  rating: number | null
  remarks: Record<string, string>
  remarks_summary: string
  feedback: string
  feedback_by: string
  feedback_at: string | null
  started: boolean
}
export type TrialPage = { bookings: TrialRow[]; total: number; page: number; per_page: number }
export type TrialFilter = { scope?: 'upcoming' | 'past'; status?: string; page?: number }
export type FeedbackInput = { status: 'attended' | 'no_show'; rating: number | null; remarks: Record<string, string>; feedback: string }

export type PortalOption = { key: string; text: string; image_url?: string }
export type PortalQuestion = {
  number: number
  answer_key: string
  type: string
  title: string
  passage?: string
  image_url?: string
  audio_src?: string
  options: PortalOption[]
}
export type SheetRow = { number: string; section: string; answer_key: string; type: string; options: string[] }
export type PublicPage = {
  test: {
    code: string
    title: string
    target_level: string
    duration_minutes: number
    questions_count: number
    pdf_url: string
    audio_src: string
  }
  listening: PortalQuestion[]
  reading: PortalQuestion[]
  writing_prompt: string | null
  speaking: { title: string; cue_points: string } | null
  sheet: SheetRow[]
  lead: { name: string; phone: string; email: string } | null
  max_violations: number
  expires_at: string
}
export type Violation = { type: 'hidden' | 'blur' | 'fullscreen_exit'; at: string }
export type SubmitInput = {
  candidate_name: string
  candidate_phone: string
  candidate_email: string
  answers: Record<string, string>
  writing_content: string
  speaking_self_rate: string
  violation_count: number
  violation_log: Violation[]
  auto_submitted: boolean
}

function qs(params: Record<string, string | number | undefined | null>) {
  const q = new URLSearchParams()
  for (const [k, v] of Object.entries(params)) if (v !== undefined && v !== null && v !== '') q.set(k, String(v))
  const s = q.toString()
  return s ? `?${s}` : ''
}

export const placementApi = {
  tests: (f: { search?: string; grade_group?: string; status?: string; page?: number }) =>
    request<TestList>('GET', `/placement-tests${qs(f)}`),
  options: () => request<FormOptions>('GET', '/placement-tests/options'),
  rubric: () => request<RubricConfig>('GET', '/placement-tests/rubric'),
  test: (id: string) => request<TestDetail>('GET', `/placement-tests/${id}`),
  createTest: (t: TestInput) => request<WithMessage<TestDetail>>('POST', '/placement-tests', t),
  updateTest: (id: string, t: TestInput) => request<WithMessage<TestDetail>>('PUT', `/placement-tests/${id}`, t),
  duplicateTest: (id: string) => request<WithMessage<TestDetail>>('POST', `/placement-tests/${id}/duplicate`),
  toggleTest: (id: string) => request<WithMessage<TestDetail>>('POST', `/placement-tests/${id}/toggle-active`),
  deleteTest: (id: string) => request<WithMessage<null>>('DELETE', `/placement-tests/${id}`),
  distribute: (id: string) => request<WithMessage<IssuedInvitation>>('POST', `/placement-tests/${id}/distribute`),
  invitations: (id: string) => request<{ invitations: Invitation[] }>('GET', `/placement-tests/${id}/invitations`),
  createInvitation: (id: string, inv: InvitationInput) =>
    request<IssuedInvitation>('POST', `/placement-tests/${id}/invitations`, inv),
  revokeInvitation: (id: string) => request<Invitation>('POST', `/placement-invitations/${id}/revoke`),

  submissions: (testId?: string) => request<SubmissionList>('GET', `/placement-submissions${qs({ test_id: testId })}`),
  grading: (id: string) => request<GradingView>('GET', `/placement-submissions/${id}`),
  grade: (id: string, g: GradeInput) => request<GradingView & { message: string }>('POST', `/placement-submissions/${id}/grade`, g),
  scorecard: (id: string) => request<SubmissionDetail>('GET', `/placement-submissions/${id}/scorecard`),

  trialSchedule: (f: TrialFilter) => request<TrialPage>('GET', `/trial-bookings${qs(f)}`),
  teacherTrials: (f: TrialFilter) => request<TrialPage>('GET', `/trial-bookings/teacher${qs(f)}`),
  trialFeedback: (id: string, f: FeedbackInput) =>
    request<WithMessage<TrialRow>>('POST', `/trial-bookings/${id}/feedback`, f),
  cancelTrial: (id: string, reason: string) => request<TrialRow>('POST', `/trial-bookings/${id}/cancel`, { reason }),

  publicTest: (token: string) => request<PublicPage>('GET', `/public/placement/${encodeURIComponent(token)}`),
  submitTest: (token: string, s: SubmitInput) =>
    request<{ candidate_name: string; test_title: string }>('POST', `/public/placement/${encodeURIComponent(token)}/submit`, s),
}
