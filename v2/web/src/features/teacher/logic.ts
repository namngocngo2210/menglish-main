// Logic thuần của cổng giáo viên (tách khỏi giao diện để test).
import type { FileRule } from '../../lib/files'
import type { HomeworkEdit, HomeworkInput, Shift } from './api'

// File nghe đính kèm bài tập (khớp v2/api teacher.AudioPolicy).
export const HOMEWORK_AUDIO_RULE: FileRule = {
  types: ['mp3', 'wav', 'm4a', 'ogg', 'oga', 'weba', 'webm', 'aac'],
  maxBytes: 50 * 1024 * 1024,
  tooLarge: 'File nghe tối đa 50 MB.',
}

// countdown: số giây còn lại → "2 giờ 5 phút" / "12 phút".
export function countdown(left: number) {
  const h = Math.floor(left / 3600)
  const m = Math.floor((left % 3600) / 60)
  return h > 0 ? `${h} giờ ${m} phút` : `${m} phút`
}

export type CheckinState = { expired: boolean; warning: boolean; left: number }

// checkinState: hạn check-in của ca sau elapsed mili giây kể từ lúc tải trang (null = đã check-in).
export function checkinState(shift: Shift, elapsedMs: number, warningMinutes = 15): CheckinState | null {
  if (shift.checked_in) return null
  const left = shift.checkin.remaining_seconds - Math.floor(elapsedMs / 1000)
  if (shift.checkin.expired || left <= 0) return { expired: true, warning: false, left: 0 }
  return { expired: false, warning: left < warningMinutes * 60, left }
}

// selectable: ca chọn được để check-in (chưa check-in, còn hạn, đã có phân hệ chấm công).
export function selectable(shift: Shift, elapsedMs: number, canCheckin: boolean) {
  const st = checkinState(shift, elapsedMs)
  return canCheckin && !!st && !st.expired
}

// ───────────── Bài tập ─────────────

export type HomeworkForm = {
  session_id: string
  due_at: string
  class_note: string
  youtube_url: string
  quizizz_url: string
  audio_file_id: string | null
  categories: string[]
  items: Record<string, string>
}

export function emptyHomeworkForm(sessionId: string | null, dueDefault: string): HomeworkForm {
  return { session_id: sessionId ?? '', due_at: dueDefault, class_note: '', youtube_url: '', quizizz_url: '', audio_file_id: null, categories: [], items: {} }
}

export function editHomeworkForm(e: HomeworkEdit): HomeworkForm {
  return {
    session_id: e.session_id ?? '',
    due_at: e.due_at,
    class_note: e.class_note,
    youtube_url: e.youtube_url,
    quizizz_url: e.quizizz_url,
    audio_file_id: null,
    categories: Object.keys(e.items),
    items: { ...e.items },
  }
}

// toggleCategory: bật / tắt hạng mục (hạng mục đã có bài nộp không tắt được).
export function toggleCategory(form: HomeworkForm, key: string, locked: string[] = []): HomeworkForm {
  if (form.categories.includes(key)) {
    if (locked.includes(key)) return form
    return { ...form, categories: form.categories.filter((k) => k !== key) }
  }
  return { ...form, categories: [...form.categories, key] }
}

// homeworkInput: dữ liệu gửi máy chủ — chỉ yêu cầu của hạng mục đang chọn, bỏ khoảng trắng thừa.
export function homeworkInput(form: HomeworkForm): HomeworkInput {
  const items: Record<string, string> = {}
  for (const k of form.categories) items[k] = (form.items[k] ?? '').trim()
  return {
    session_id: form.session_id || null,
    due_at: form.due_at,
    class_note: form.class_note.trim(),
    youtube_url: form.youtube_url.trim(),
    quizizz_url: form.quizizz_url.trim(),
    audio_file_id: form.audio_file_id,
    categories: form.categories,
    items,
  }
}

// ───────────── Điểm mini test ─────────────

const num = (v: string) => Number(v.trim().replace(',', '.'))

// scorePreview: điểm tổng (trung bình các kỹ năng, 2 chữ số lẻ) khi đã nhập đủ và hợp lệ; '' nếu chưa.
export function scorePreview(skills: Record<string, string>, keys: string[]) {
  const vals = keys.map((k) => (skills[k] ?? '').trim())
  if (vals.some((v) => v === '' || !Number.isFinite(num(v)))) return ''
  const avg = vals.reduce((s, v) => s + num(v), 0) / vals.length
  return String(Math.round(avg * 100) / 100)
}

// overMax: có kỹ năng vượt điểm tối đa.
export function overMax(skills: Record<string, string>, max: string) {
  const m = num(max || '10')
  return Number.isFinite(m) && Object.values(skills).some((v) => v.trim() !== '' && num(v) > m)
}

// ───────────── Nhận xét ─────────────

export const REMARK_FIELDS = [
  { key: 'monsters_group', label: 'Nhóm Monsters' },
  { key: 'monsters_bonus', label: 'Điểm thưởng Monsters' },
  { key: 'grammar', label: 'Thực hành ngữ pháp' },
  { key: 'attitude', label: 'Tinh thần học tập' },
  { key: 'result', label: 'Kết quả' },
  { key: 'comment', label: 'Nhận xét chung' },
] as const

const ATT_LABELS: Record<string, string> = {
  present: 'Có mặt',
  late: 'Đi muộn',
  absent: 'Vắng',
  excused: 'Vắng có phép',
  none: 'Chưa điểm danh',
}

export const attLabel = (status: string) => ATT_LABELS[status] ?? status

// ───────────── Định tuyến ─────────────

export type ClassTab = 'homework' | 'scores' | 'remarks'

// classRoute: /teacher/classes/<id>/<homework|scores|remarks> → { id, tab }; sai → null.
export function classRoute(path: string): { id: string; tab: ClassTab } | null {
  const m = /^\/teacher\/classes\/([0-9a-f-]{36})\/(homework|scores|remarks)\/?$/i.exec(path)
  return m ? { id: m[1], tab: m[2] as ClassTab } : null
}

export const attendanceHref = (classId: string, sessionId?: string) =>
  `/classes/${classId}/attendance${sessionId ? `?session=${sessionId}` : ''}`

export const classHref = (classId: string, tab: ClassTab, sessionId?: string) =>
  `/teacher/classes/${classId}/${tab}${sessionId ? `?session=${sessionId}` : ''}`

// formatMoney: 1500000 → "1.500.000 đ".
export const formatMoney = (v: number) => `${new Intl.NumberFormat('vi-VN').format(v)} đ`

// ───────────── Chấm bài nộp của lớp (Portal/TeacherSubmissions.vue) ─────────────

// Biểu tượng tab theo loại bài (nhãn tab lấy từ API).
export const SUBMISSION_TAB_ICONS: Record<string, string> = {
  video: 'videocam',
  vocabulary: 'edit_document',
  workbook: 'menu_book',
  extra_book: 'library_books',
  bgd_book: 'import_contacts',
  quiz: 'quiz',
  pronunciation: 'mic',
}

// Đường dẫn màn chấm bài theo lớp + tab (bỏ tham số rỗng).
export function submissionsHref(classId: string | undefined, type: string) {
  const q = new URLSearchParams()
  if (classId) q.set('class_id', classId)
  if (type) q.set('type', type)
  const s = q.toString()
  return s ? `/teacher/submissions?${s}` : '/teacher/submissions'
}

// Hai chữ đầu tên học viên (ô ảnh đại diện).
export const initials = (name: string | null | undefined) => Array.from(name || 'HV').slice(0, 2).join('')

// Nhãn bài đã chấm: có điểm → "Đã chấm: 9/10", không điểm → "Đã xem".
export const reviewedLabel = (score: string | null | undefined) => (score && score.trim() !== '' ? `Đã chấm: ${score}` : 'Đã xem')
