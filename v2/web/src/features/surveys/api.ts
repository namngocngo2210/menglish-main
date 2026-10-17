// API Khảo sát (Go: internal/surveys). Thông báo lỗi tiếng Việt lấy từ máy chủ (errorMessage).
// Cổng PH/HS ("Khảo sát 5 sao") dùng portal/submit/deleteResponse — TODO(gộp): màn cổng do phân hệ Cổng PH/HS dựng.
import { request } from '../../api'
import { toQuery } from '../platform/format'

export type Survey = {
  id: string
  title: string
  description: string | null
  deadline: string | null
  deadline_label: string | null
  deadline_today: boolean
  is_active: boolean
  creator_name: string | null
  response_count: number
}

export type SurveyList = { today: string; surveys: Survey[] }

export type SurveyInput = { title: string; description: string | null; deadline: string | null; is_active?: boolean }

export type Result = { id: string; message: string }

export type SurveyResponse = {
  id: string
  student_id: string
  student_name: string
  student_code: string
  submitter: string | null
  rating: number
  feedback: string
  created_at: string
}

export type ResponsesPage = { survey: Survey; responses: SurveyResponse[]; average: number | null }

export type PortalStudent = { id: string; name: string; code: string }
export type OpenSurvey = { id: string; title: string; description: string | null; deadline: string; is_urgent: boolean; status_text: string }
export type PastSurvey = { id: string; survey_id: string | null; title: string; survey_title: string; rating: number; feedback: string; submitted_at: string }
export type PortalPage = { student: PortalStudent | null; students: PortalStudent[]; surveys: OpenSurvey[]; past_surveys: PastSurvey[] }
export type SubmitInput = { student_id: string; survey_id: string; rating: number; feedback: string }

export const surveysApi = {
  list: () => request<SurveyList>('GET', '/surveys'),
  create: (input: SurveyInput) => request<Result>('POST', '/surveys', input),
  update: (id: string, input: SurveyInput) => request<Result>('PUT', `/surveys/${id}`, input),
  remove: (id: string) => request<Result>('DELETE', `/surveys/${id}`),
  responses: (id: string) => request<ResponsesPage>('GET', `/surveys/${id}/responses`),
  portal: (studentId?: string) => {
    const qs = toQuery({ student_id: studentId })
    return request<PortalPage>('GET', `/surveys/portal${qs ? `?${qs}` : ''}`)
  },
  submit: (input: SubmitInput) => request<Result>('POST', '/surveys/portal', input),
  deleteResponse: (id: string) => request<Result>('DELETE', `/surveys/portal/responses/${id}`),
}
