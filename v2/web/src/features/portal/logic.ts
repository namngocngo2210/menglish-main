// Hàm thuần của Cổng Phụ huynh & Học sinh: nhãn lịch học, hạng mục bài tập, đồng hồ ghi âm, kiểm góp ý chặng.
import type { Me } from '../../api'
import type { SessionItem, Submission } from './api'

// Tài khoản chỉ dùng cổng (User::isPortalStudentOnly): có portal.student, không có portal.staff — trang "/" là Trang chủ
// cổng, không có ô tìm kiếm, sidebar ghi "Cổng học viên".
export function isPortalOnly(me: Me) {
  return me.permissions.includes('portal.student') && !me.permissions.includes('portal.staff')
}

// Đường dẫn màn cổng kèm học viên đang xem (?student_id=), giữ nguyên khi đổi học viên.
export function portalHref(path: string, studentId?: string | null) {
  return studentId ? `${path}?student_id=${encodeURIComponent(studentId)}` : path
}

const WEEKDAYS = ['CN', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7']

// "T2, 20/10 · 18:00-19:30" như trang chủ Laravel. date dạng "2026-10-20" hoặc ISO (lấy phần ngày).
export function sessionLabel(s: Pick<SessionItem, 'date' | 'start_time' | 'end_time'>) {
  const [y, m, d] = s.date.slice(0, 10).split('-').map(Number)
  const wd = WEEKDAYS[new Date(Date.UTC(y, m - 1, d)).getUTCDay()]
  return `${wd}, ${String(d).padStart(2, '0')}/${String(m).padStart(2, '0')} · ${s.start_time}-${s.end_time}`
}

export type BadgeTone = 'neutral' | 'success' | 'info'

// Nhãn buổi học: Nghỉ / Học bù / Phụ đạo / Buổi học.
export function sessionBadge(s: Pick<SessionItem, 'cancelled' | 'type'>): [BadgeTone, string] {
  if (s.cancelled) return ['neutral', 'Nghỉ']
  if (s.type === 'makeup') return ['info', 'Học bù']
  if (s.type === 'support') return ['info', 'Phụ đạo']
  return ['success', 'Buổi học']
}

// "2026-10-20" / ISO → "20/10/2026".
export function dmy(value: string | null | undefined) {
  if (!value) return ''
  const [y, m, d] = value.slice(0, 10).split('-')
  return d && m && y ? `${d}/${m}/${y}` : value
}

// Giờ Việt Nam "HH:MM, dd/mm/yyyy" (định dạng giờ nộp bài của Laravel).
export function submittedAt(iso: string) {
  const date = new Date(iso)
  if (Number.isNaN(date.getTime())) return iso
  const p = Object.fromEntries(
    new Intl.DateTimeFormat('en-GB', {
      timeZone: 'Asia/Ho_Chi_Minh',
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      hourCycle: 'h23',
    })
      .formatToParts(date)
      .map((x) => [x.type, x.value]),
  )
  return `${p.hour}:${p.minute}, ${p.day}/${p.month}/${p.year}`
}

// Hạng mục bài tập (CATEGORIES của Homework.vue): biểu tượng, màu, chữ nút, có phải Quiz.
export const CATEGORIES: Record<string, { icon: string; color: string; button: string; quiz?: boolean }> = {
  video: { icon: 'videocam', color: 'text-primary', button: 'Tải lên video' },
  vocabulary: { icon: 'edit_document', color: 'text-info', button: 'Tải lên bài viết' },
  workbook: { icon: 'menu_book', color: 'text-info', button: 'Tải lên Workbook' },
  extra_book: { icon: 'library_books', color: 'text-success', button: 'Tải lên bài làm' },
  bgd_book: { icon: 'import_contacts', color: 'text-warning', button: 'Tải lên bài làm' },
  quiz: { icon: 'quiz', color: 'text-warning', button: 'Nộp kết quả Quiz', quiz: true },
}

// Chỉ tô nổi mục chưa nộp đầu tiên khi bài giáo viên giao sắp / đã tới hạn.
export function markUrgent<T extends { submission: Submission | null }>(cards: T[], urgent: boolean): (T & { urgent: boolean })[] {
  let used = false
  return cards.map((c) => {
    const u = !c.submission && urgent && !used
    if (u) used = true
    return { ...c, urgent: u }
  })
}

export const filled = (v: string | null | undefined) => v !== null && v !== undefined && v.trim() !== ''

// Đồng hồ ghi âm "mm:ss".
export function timerText(seconds: number) {
  const s = Math.max(0, Math.floor(seconds))
  return `${String(Math.floor(s / 60)).padStart(2, '0')}:${String(s % 60).padStart(2, '0')}`
}

// Đuôi file bản ghi theo loại MediaRecorder trả về (Safari: mp4 → m4a).
export function recordingExtension(mime: string) {
  if (mime.includes('mp4')) return 'm4a'
  if (mime.includes('ogg')) return 'ogg'
  return 'webm'
}

export const RATING_LABELS = ['Chưa chọn', 'Rất không hài lòng', 'Không hài lòng', 'Bình thường', 'Hài lòng', 'Rất hài lòng']

// R-04: cần ít nhất một mục — số sao, lĩnh vực góp ý hoặc nội dung.
export function feedbackFilled(f: { rating: number; fb_hoc_thuat: boolean; fb_giao_vien: boolean; fb_khac: boolean; content: string }) {
  return f.rating > 0 || f.fb_hoc_thuat || f.fb_giao_vien || f.fb_khac || f.content.trim() !== ''
}

export const FEEDBACK_REQUIRED = 'Vui lòng điền ít nhất 1 mục (Nội dung, Lĩnh vực góp ý hoặc Chọn mức hài lòng) để gửi feedback.'

// Số tiền gợi ý ở hộp "Báo đóng học phí": còn nợ (làm tròn xuống), không nợ thì để trống.
export const suggestedAmount = (debt: number) => (debt > 0 ? String(Math.trunc(debt)) : '')

// Ô số tiền → số nguyên (bỏ dấu chấm / phẩy / khoảng trắng); rỗng → null.
export function parseAmount(v: string): number | null {
  const digits = v.replace(/[.,\s]/g, '')
  if (digits === '') return null
  const n = Number(digits)
  return Number.isFinite(n) ? Math.trunc(n) : null
}
