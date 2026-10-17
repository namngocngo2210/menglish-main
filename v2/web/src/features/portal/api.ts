// API Cổng Phụ huynh & Học sinh (/api/v1/portal/*) và màn kế toán xác nhận báo đóng học phí (/payment-reports).
import { request, upload } from '../../api'
import type { StudentPayments } from '../tuition/api'
import type { NotificationTone } from '../platform/api'

export type StudentOption = { id: string; code: string; name: string; class_name: string | null }

// Phần chung của mọi màn cổng: học viên đang xem, danh sách đổi học viên, số thông báo chưa đọc.
export type PortalContext = {
  student: StudentOption | null
  students: StudentOption[]
  unread_count: number
  // Được nộp / sửa / xóa trên hồ sơ (chủ hồ sơ hoặc nhân sự có student.update)
  can_write: boolean
  // Nhân sự xem hộ (tài khoản không gắn học viên nào)
  staff: boolean
}

export type TuitionTotals = {
  has_tuition: boolean
  final_amount: number
  paid_amount: number
  debt_amount: number
  installments: number
}

export type Profile = {
  status_label: string
  dob: string | null
  phone: string
  address: string | null
  notes: string | null
  teacher_name: string | null
  study_started_on: string | null
  tenure_label: string | null
}

export type SessionItem = {
  id: string
  date: string
  start_time: string
  end_time: string
  class_name: string
  room_label: string | null
  cancelled: boolean
  type: 'regular' | 'support' | 'makeup'
}

export type AttendanceItem = { id: string; date: string; class_name: string; note: string | null; present: boolean; status_label: string }

export type BigTestResult = {
  id: string
  title: string
  scheduled_at: string | null
  is_absent: boolean
  overall_score: number | null
  listening_score: number | null
  reading_score: number | null
  writing_score: number | null
  speaking_score: number | null
  progress_note: string | null
}

export type PaymentReportStatus = 'pending' | 'confirmed' | 'rejected'
export type PaymentReport = {
  id: string
  code: string
  amount: number
  content: string
  status: PaymentReportStatus
  status_label: string
  rejection_reason: string | null
  created_at: string
  resolved_at: string | null
}

export type Home = PortalContext & {
  profile: Profile | null
  tuition: TuitionTotals
  next_term_fee: number
  progress: {
    attendance_present: number
    attendance_total: number
    homework_submitted: number
    homework_total: number
    latest_big_test: number | null
  }
  upcoming_days: number
  upcoming_sessions: SessionItem[]
  attendance_history: AttendanceItem[]
  big_test_results: BigTestResult[]
  student_classes: string[]
  payment_reports: PaymentReport[]
}

export type AppShell = PortalContext & {
  status_label: string | null
  phone: string | null
  tuition: TuitionTotals
  pending_homeworks: number
  submitted_homeworks: number
  pronunciation_count: number
  survey_count: number
  has_feedback: boolean
  homework_types: number
}

export type Submission = {
  id: string
  homework_type: string
  status: 'submitted' | 'reviewed'
  submitted_at: string
  score: string | null
  feedback: string | null
  notes: string | null
  attachment_url: string | null
  attachment_name: string | null
}

export type Remark = {
  date: string
  monsters: string | null
  grammar: string | null
  attitude: string | null
  result: string | null
  comment: string | null
}

export type Homework = PortalContext & {
  cards: { key: string; title: string; submission: Submission | null }[]
  completed_count: number
  remarks: Remark[]
  mini_tests: { id: string; name: string; test_date: string | null; score: number | null; max_score: number }[]
  big_test_results: BigTestResult[]
  latest_homework: { title: string; description: string | null; class_name: string | null; due_date: string | null } | null
  homework_urgent: boolean
  // Phân hệ Cổng giáo viên đã gắn (nộp bài được)
  ready: boolean
}

export type PronunciationItem = {
  id: string
  code: string
  title: string
  unit_title: string
  status: 'pending_review' | 'reviewed'
  submitted_at: string
  duration: string | null
  score: string | null
  feedback: string | null
  audio_url: string | null
}

export type Pronunciation = PortalContext & {
  history: PronunciationItem[]
  practice_items: { title: string; class_name: string | null; audio_url: string }[]
}

export type InboxItem = {
  id: string
  type: string
  title: string
  content: string
  link: string | null
  unread: boolean
  time: string
  icon: string
  tone: NotificationTone
}

export type Inbox = PortalContext & { notifications: InboxItem[] }

export type SurveyItem = { id: string; title: string; description: string | null; deadline: string | null; is_urgent: boolean; status_text: string }
export type SurveyResponse = { id: string; survey_id: string | null; title: string; rating: number; feedback: string; submitted_at: string }
export type SurveyPage = PortalContext & { surveys: SurveyItem[]; past_surveys: SurveyResponse[]; ready: boolean }

export type StageFeedback = {
  id: string
  code: string
  stage_name: string
  class_name: string | null
  rating: number
  fb_hoc_thuat: boolean
  fb_giao_vien: boolean
  fb_khac: boolean
  content: string
  submitted_at: string
}

export type FeedbackPage = PortalContext & {
  stage_name: string
  class_name: string
  last_feedback: StageFeedback | null
  history: StageFeedback[]
}

export type FeedbackInput = {
  student_id: string
  rating: number
  fb_hoc_thuat: boolean
  fb_giao_vien: boolean
  fb_khac: boolean
  content: string
}

// Kế toán: "Học viên báo đã đóng học phí".
export type ReportRow = PaymentReport & {
  student_id: string
  student_code: string
  student_name: string
  class_name: string | null
  resolver_name: string | null
  creator_name: string | null
  tuition_id: string | null
}

type Msg = { message: string }

const q = (studentId?: string | null) => (studentId ? `?student_id=${encodeURIComponent(studentId)}` : '')

export const portalApi = {
  summary: () => request<{ unread_count: number }>('GET', '/portal/summary'),
  home: (studentId?: string | null) => request<Home>('GET', `/portal/home${q(studentId)}`),
  tuition: (studentId: string) => request<{ history: StudentPayments | null }>('GET', `/portal/tuition${q(studentId)}`),
  appShell: (studentId?: string | null) => request<AppShell>('GET', `/portal/app-shell${q(studentId)}`),
  updateContact: (id: string, body: { phone: string; address: string; notes: string }) =>
    request<Msg>('PATCH', `/portal/students/${id}/contact`, body),
  reportPayment: (body: { student_id: string; amount: number | null; content: string }) =>
    request<Msg & { report: PaymentReport }>('POST', '/portal/payment-reports', body),

  homework: (studentId?: string | null) => request<Homework>('GET', `/portal/homework${q(studentId)}`),
  submitHomework: (form: FormData) => upload<Msg & { id: string }>('/portal/homework', form),
  updateHomework: (id: string, form: FormData) => upload<Msg>(`/portal/homework/${id}`, form),
  deleteHomework: (id: string) => request<Msg>('DELETE', `/portal/homework/${id}`),

  pronunciation: (studentId?: string | null) => request<Pronunciation>('GET', `/portal/pronunciation${q(studentId)}`),
  submitPronunciation: (form: FormData) => upload<Msg & { record: PronunciationItem }>('/portal/pronunciation', form),
  deletePronunciation: (id: string) => request<Msg>('DELETE', `/portal/pronunciation/${id}`),

  notifications: (studentId?: string | null) => request<Inbox>('GET', `/portal/notifications${q(studentId)}`),
  readAll: (studentId: string) => request<Msg>('POST', '/portal/notifications/read-all', { student_id: studentId }),
  read: (studentId: string, id: string) => request<Msg>('POST', `/portal/notifications/${id}/read`, { student_id: studentId }),
  deleteNotification: (studentId: string, id: string) => request<Msg>('DELETE', `/portal/notifications/${id}${q(studentId)}`),

  surveys: (studentId?: string | null) => request<SurveyPage>('GET', `/portal/surveys${q(studentId)}`),
  submitSurvey: (body: { student_id: string; survey_id: string; rating: number; feedback: string }) =>
    request<Msg>('POST', '/portal/surveys', body),
  deleteSurvey: (id: string) => request<Msg>('DELETE', `/portal/surveys/${id}`),

  feedback: (studentId?: string | null) => request<FeedbackPage>('GET', `/portal/feedback${q(studentId)}`),
  submitFeedback: (body: FeedbackInput) => request<Msg & { feedback: StageFeedback; created: boolean }>('POST', '/portal/feedback', body),
  deleteFeedback: (id: string) => request<Msg>('DELETE', `/portal/feedback/${id}`),

  paymentReports: (status: PaymentReportStatus) => request<{ data: ReportRow[] }>('GET', `/payment-reports?status=${status}`),
  confirmReport: (id: string) => request<Msg>('POST', `/payment-reports/${id}/confirm`),
  rejectReport: (id: string, reason: string) => request<Msg>('POST', `/payment-reports/${id}/reject`, { reason }),
}
