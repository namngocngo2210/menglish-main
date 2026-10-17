// API Khảo sát (Go: internal/surveys). Thông báo lỗi tiếng Việt lấy từ máy chủ (errorMessage).
// Cổng PH/HS ("Khảo sát 5 sao") gọi /portal/surveys của phân hệ Cổng PH/HS (features/portal).
import { request } from '../../api'

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

export const surveysApi = {
  list: () => request<SurveyList>('GET', '/surveys'),
  create: (input: SurveyInput) => request<Result>('POST', '/surveys', input),
  update: (id: string, input: SurveyInput) => request<Result>('PUT', `/surveys/${id}`, input),
  remove: (id: string) => request<Result>('DELETE', `/surveys/${id}`),
  responses: (id: string) => request<ResponsesPage>('GET', `/surveys/${id}/responses`),
}
