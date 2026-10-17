// Logic thuần của màn Giáo trình & Big Test (tách ra để kiểm bằng Vitest).
import type { Color, ResultCell, ResultInput, Stage } from './api'

const VN = 'Asia/Ho_Chi_Minh'

// fmtDate: "2026-10-17" hoặc ISO có giờ → "17/10/2026"; rỗng → '—'.
export function fmtDate(v: string | null | undefined): string {
  if (!v) return '—'
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(v)
  if (m) return `${m[3]}/${m[2]}/${m[1]}`
  const d = new Date(v)
  if (Number.isNaN(d.getTime())) return v
  return new Intl.DateTimeFormat('vi-VN', {
    timeZone: VN,
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  }).format(d)
}

// fmtDateTime: ISO → "17/10/2026 08:30" theo giờ Việt Nam.
export function fmtDateTime(v: string | null | undefined): string {
  if (!v) return '—'
  const d = new Date(v)
  if (Number.isNaN(d.getTime())) return v
  const p = Object.fromEntries(
    new Intl.DateTimeFormat('en-GB', {
      timeZone: VN,
      day: '2-digit',
      month: '2-digit',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
      hourCycle: 'h23',
    })
      .formatToParts(d)
      .map((x) => [x.type, x.value]),
  )
  return `${p.day}/${p.month}/${p.year} ${p.hour}:${p.minute}`
}

// timeAgo: "vừa xong", "5 phút trước", "3 giờ trước", "2 ngày trước"… (diffForHumans của Laravel).
export function timeAgo(iso: string, now: Date = new Date()): string {
  const t = new Date(iso).getTime()
  if (Number.isNaN(t)) return ''
  const s = Math.max(0, Math.floor((now.getTime() - t) / 1000))
  if (s < 60) return 'vừa xong'
  const units: [number, string][] = [
    [60 * 60 * 24 * 365, 'năm'],
    [60 * 60 * 24 * 30, 'tháng'],
    [60 * 60 * 24 * 7, 'tuần'],
    [60 * 60 * 24, 'ngày'],
    [60 * 60, 'giờ'],
    [60, 'phút'],
  ]
  for (const [size, label] of units) if (s >= size) return `${Math.floor(s / size)} ${label} trước`
  return 'vừa xong'
}

// todayISO: ngày hôm nay theo giờ Việt Nam, dạng "YYYY-MM-DD".
export function todayISO(now: Date = new Date()): string {
  return new Intl.DateTimeFormat('en-CA', {
    timeZone: VN,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).format(now)
}

// Màu nhãn của máy chủ → tone của Badge.
export function badgeTone(c: Color | string | null | undefined): 'neutral' | 'success' | 'error' | 'info' | 'warning' {
  return c === 'success' || c === 'error' || c === 'info' || c === 'warning' ? c : 'neutral'
}

export const lessonCount = (s: Pick<Stage, 'units'>) => s.units.reduce((n, u) => n + u.lessons.length, 0)
export const pad2 = (n: number) => String(n).padStart(2, '0')
export const isImageUrl = (u: string) => /\.(png|jpe?g|gif|webp|svg)(\?.*)?$/i.test(u)

// --- Bảng điểm ---

export const SKILLS = ['listening_score', 'reading_score', 'writing_score', 'speaking_score'] as const
export type Skill = (typeof SKILLS)[number]
export const SKILL_LABELS: Record<Skill, string> = {
  listening_score: 'Listening',
  reading_score: 'Reading',
  writing_score: 'Writing',
  speaking_score: 'Speaking',
}

// Ô nhập của một học viên (chuỗi để giữ đúng thứ người dùng gõ).
export type Draft = Record<Skill, string> & {
  progress_note: string
  video_url: string
  is_absent: boolean
}

export function draftOf(r: ResultCell | null): Draft {
  const s = (v: number | null | undefined) => (v === null || v === undefined ? '' : String(v))
  return {
    listening_score: s(r?.listening_score),
    reading_score: s(r?.reading_score),
    writing_score: s(r?.writing_score),
    speaking_score: s(r?.speaking_score),
    progress_note: r?.progress_note ?? '',
    video_url: r?.video_url ?? '',
    is_absent: r?.is_absent ?? false,
  }
}

// parseScore: "7,5" / "7.5" → 7.5; rỗng → null; không phải số → NaN (máy chủ báo lỗi).
export function parseScore(v: string): number | null {
  const t = v.trim().replace(',', '.')
  if (t === '') return null
  return Number(t)
}

// overallOf: trung bình 4 kỹ năng làm tròn 1 chữ số (như máy chủ); thiếu kỹ năng → null.
export function overallOf(d: Pick<Draft, Skill>): number | null {
  const v = SKILLS.map((k) => parseScore(d[k]))
  if (v.some((x) => x === null || Number.isNaN(x))) return null
  const sum = (v as number[]).reduce((a, b) => a + b, 0)
  return Math.round((sum / 4) * 10) / 10
}

// resultsPayload: dòng gửi lên máy chủ (bỏ dòng đã khóa); vắng thi thì không gửi điểm.
export function resultsPayload(rows: { student_id: string; locked: boolean; draft: Draft }[]): ResultInput[] {
  return rows
    .filter((r) => !r.locked)
    .map(({ student_id, draft: d }) => ({
      student_id,
      listening_score: d.is_absent ? null : parseScore(d.listening_score),
      reading_score: d.is_absent ? null : parseScore(d.reading_score),
      writing_score: d.is_absent ? null : parseScore(d.writing_score),
      speaking_score: d.is_absent ? null : parseScore(d.speaking_score),
      progress_note: d.progress_note.trim() || null,
      video_url: d.video_url.trim() || null,
      is_absent: d.is_absent,
    }))
}

export function resultTone(status: ResultCell['status'] | undefined): 'neutral' | 'success' | 'info' | 'warning' {
  return status === 'approved' ? 'success' : status === 'sent' ? 'info' : status === 'pending_review' ? 'warning' : 'neutral'
}

// Số ngày còn lại tới ngày thi: ≤ 2 đỏ, ≤ 3 vàng.
export function daysLeftClass(n: number): string {
  return n <= 2 ? 'text-error' : n <= 3 ? 'text-warning' : 'text-on-surface'
}

// Hạn trả kết quả: "Quá hạn 2 ngày" / "Còn 3 ngày".
export function daysLeftLabel(n: number): string {
  return n < 0 ? `Quá hạn ${Math.abs(n)} ngày` : `Còn ${n} ngày`
}

// datetime-local → giữ nguyên "YYYY-MM-DDTHH:MM" (máy chủ hiểu là giờ Việt Nam).
export const toLocalInput = (v: string) => v.slice(0, 16)
