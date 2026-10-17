// Logic thuần màn Đợt khảo sát (có test): form tạo / sửa, đường dẫn trang con, hiển thị sao.
import type { Survey, SurveyInput } from './api'

export const SURVEYS_PATH = '/surveys'

export type SurveyForm = { title: string; description: string; deadline: string; is_active: boolean }

export const emptyForm = (): SurveyForm => ({ title: '', description: '', deadline: '', is_active: true })

export const formOf = (s: Survey): SurveyForm => ({
  title: s.title,
  description: s.description ?? '',
  deadline: s.deadline ?? '',
  is_active: s.is_active,
})

// Gửi lên máy chủ: chuỗi rỗng → null; is_active chỉ gửi khi sửa (tạo mới luôn đang mở).
export function payloadOf(f: SurveyForm, editing: boolean): SurveyInput {
  const text = (v: string) => (v.trim() ? v.trim() : null)
  const out: SurveyInput = { title: f.title.trim(), description: text(f.description), deadline: text(f.deadline) }
  if (editing) out.is_active = f.is_active
  return out
}

// Kiểm nhanh phía trình duyệt (máy chủ vẫn kiểm lại, cùng câu chữ).
export function formError(f: SurveyForm, today: string, editing: boolean): string | null {
  if (!f.title.trim()) return 'Vui lòng nhập Tiêu đề.'
  if ([...f.title.trim()].length > 255) return 'Trường Tiêu đề không được vượt quá 255 ký tự.'
  if ([...f.description.trim()].length > 2000) return 'Trường Mô tả chi tiết không được vượt quá 2000 ký tự.'
  if (!editing && f.deadline && f.deadline < today) return 'Trường Hạn hoàn thành phải là thời gian bắt đầu sau hoặc đúng bằng hôm nay.'
  return null
}

export const deleteMessage = (s: Pick<Survey, 'title'>) => `Xóa khảo sát "${s.title}"? Lịch sử đã nộp của học viên vẫn được giữ.`

// /surveys/<id> → bài đã nộp của đợt; còn lại là danh sách.
export function surveyRoute(path: string): { kind: 'list' } | { kind: 'responses'; id: string } {
  const m = path.match(/^\/surveys\/([0-9a-f-]{36})\/?$/i)
  return m ? { kind: 'responses', id: m[1] } : { kind: 'list' }
}

export const stars = (n: number) => '★'.repeat(Math.max(0, Math.min(5, n))) + '☆'.repeat(5 - Math.max(0, Math.min(5, n)))

export const formatAverage = (n: number | null) => (n === null ? '—' : n.toFixed(1).replace('.', ',') + ' / 5')
