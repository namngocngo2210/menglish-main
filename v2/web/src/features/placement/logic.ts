// Logic thuần của module test đầu vào: tính điểm theo thang khối (giống server), khóa màn hình khi làm bài, soạn câu hỏi.
import type { KeyEntry, Question, QuestionOption, RubricGroupConfig, Skill, Violation } from './api'

export const SKILLS: Skill[] = ['listening', 'reading_writing', 'speaking']
export const SKILL_LABELS: Record<Skill, string> = { listening: 'Nghe', reading_writing: 'Đọc & Viết', speaking: 'Nói' }
// Khối không có thang: điểm từng kỹ năng nhập theo thang tạm 0–10 trên form (server nhận tới 100).
const FALLBACK_MAX = 10

export function parseScore(v: string): number | null {
  if (v.trim() === '') return null
  const n = Number(v.trim().replace(',', '.'))
  return Number.isFinite(n) ? n : null
}

export function round1(v: number) {
  return Math.round(v * 10) / 10
}

// Lớp đề xuất theo tổng điểm (lt: tổng < lt; lte: tổng ≤ lte; không cận: băng cao nhất).
export function suggestClass(g: RubricGroupConfig | null, total: number): string | null {
  if (!g?.has_rubric) return null
  const hit = g.placements.find((p) => (p.lt !== null ? total < p.lt : p.lte !== null ? total <= p.lte : true))
  return hit?.class ?? null
}

// Nhận xét gợi ý theo băng điểm: băng có min cao nhất mà điểm đạt.
export function bandComment(g: RubricGroupConfig | null, skill: Skill, score: number | null): string {
  if (!g?.has_rubric || score === null) return ''
  let text = ''
  for (const b of g.bands[skill] ?? []) if (score >= b.min) text = b.text
  return text
}

export function maxFor(g: RubricGroupConfig | null, skill: Skill) {
  return g?.has_rubric ? g.max[skill] : FALLBACK_MAX
}

export type Scores = Record<Skill, string>
export type Evaluation = {
  complete: boolean
  total: number
  maxTotal: number
  suggested: string | null
  suggestions: Record<Skill, string>
  overMax: Record<Skill, boolean>
  percent: Record<Skill, number>
}

// evaluate: tổng = Nghe + Đọc & Viết + Nói (làm tròn 1 chữ số) → lớp đề xuất khi đủ 3 kỹ năng.
export function evaluate(g: RubricGroupConfig | null, scores: Scores): Evaluation {
  const nums = Object.fromEntries(SKILLS.map((s) => [s, parseScore(scores[s])])) as Record<Skill, number | null>
  const complete = SKILLS.every((s) => nums[s] !== null)
  const total = round1(SKILLS.reduce((sum, s) => sum + (nums[s] ?? 0), 0))
  const maxTotal = SKILLS.reduce((sum, s) => sum + maxFor(g, s), 0)
  const pick = <T,>(f: (s: Skill) => T) => Object.fromEntries(SKILLS.map((s) => [s, f(s)])) as Record<Skill, T>
  return {
    complete,
    total,
    maxTotal,
    suggested: complete ? suggestClass(g, total) : null,
    suggestions: pick((s) => bandComment(g, s, nums[s])),
    overMax: pick((s) => nums[s] !== null && nums[s] > maxFor(g, s)),
    percent: pick((s) => (nums[s] === null ? 0 : Math.max(0, Math.min(100, Math.round((nums[s] / maxFor(g, s)) * 100))))),
  }
}

// ---- Khóa màn hình khi làm bài ----

export type ViolationState = { log: Violation[]; lastAt: number }
export type ViolationResult = { state: ViolationState; action: 'ignored' | 'warn' | 'auto_submit' }

// Chuyển tab thường bắn cùng lúc blur + visibilitychange + thoát toàn màn hình: trong 1,5 giây tính là 1 lần.
export const VIOLATION_DEBOUNCE_MS = 1500

export function registerViolation(
  state: ViolationState,
  type: Violation['type'],
  nowMs: number,
  max: number,
): ViolationResult {
  if (nowMs - state.lastAt < VIOLATION_DEBOUNCE_MS) return { state, action: 'ignored' }
  const log = [...state.log, { type, at: new Date(nowMs).toISOString() }]
  return { state: { log, lastAt: nowMs }, action: log.length >= max ? 'auto_submit' : 'warn' }
}

type KeyLike = { key: string; ctrlKey: boolean; metaKey: boolean; shiftKey: boolean; altKey: boolean }

// Phím tắt bị chặn khi đang làm bài: DevTools, xem nguồn, lưu, in, tải lại, sao chép / dán, quay lại.
export function isBlockedShortcut(e: KeyLike) {
  const key = (e.key || '').toLowerCase()
  const mod = e.ctrlKey || e.metaKey
  return (
    key === 'f12' ||
    key === 'f5' ||
    (mod && e.shiftKey && ['i', 'j', 'c', 'k'].includes(key)) ||
    (mod && ['u', 's', 'p', 'r', 'c', 'x', 'v', 'o', 'f', 'g'].includes(key)) ||
    (e.altKey && ['arrowleft', 'arrowright'].includes(key))
  )
}

export function answeredCount(answers: Record<string, string>, keys: string[]) {
  return keys.filter((k) => (answers[k] ?? '').trim() !== '').length
}

// Gần giống str_word_count của PHP: đếm cụm có chữ cái.
export function wordCount(s: string) {
  return s.split(/\s+/).filter((w) => /\p{L}/u.test(w)).length
}

// ---- Soạn đề ----

export const QUESTION_SKILLS = [
  { value: 'listening', label: 'Nghe (Listening)' },
  { value: 'reading', label: 'Đọc (Reading)' },
  { value: 'grammar', label: 'Ngữ pháp (Grammar)' },
  { value: 'writing', label: 'Viết (Writing)' },
  { value: 'speaking', label: 'Nói (Speaking)' },
  { value: 'general', label: 'Chung' },
]
export const QUESTION_TYPES = [
  { value: 'multiple_choice', label: 'Trắc nghiệm' },
  { value: 'true_false', label: 'Đúng / Sai' },
  { value: 'fill_blank', label: 'Điền từ' },
  { value: 'essay', label: 'Tự luận (Viết)' },
  { value: 'speaking_prompt', label: 'Gợi ý phỏng vấn (Nói)' },
]
export const hasOptions = (type: string) => type === 'multiple_choice' || type === 'single_choice' || type === 'true_false'
export const hasAnswerKey = (type: string) => type !== 'essay' && type !== 'speaking_prompt'

let seq = 0
// newQuestion: câu mới, id duy nhất trong đề (câu trả lời của thí sinh gắn theo id).
export function newQuestion(existing: Question[], skill = 'listening', type = 'multiple_choice'): Question {
  const ids = new Set(existing.map((q) => q.id))
  let id = ''
  do id = `q${Date.now().toString(36)}${(seq++).toString(36)}`
  while (ids.has(id))
  const options =
    type === 'true_false'
      ? [
          { key: 'T', text: 'True' },
          { key: 'F', text: 'False' },
        ]
      : hasOptions(type)
        ? ['A', 'B', 'C', 'D'].map((key) => ({ key, text: '' }))
        : []
  return { id, skill, type, title: '', options, correct_answer: '', points: 1 }
}

// changeType: đổi loại câu, bỏ phương án / đáp án không còn hợp.
export function changeType(q: Question, type: string): Question {
  const fresh = newQuestion([], q.skill, type)
  return {
    ...q,
    type,
    options: hasOptions(type) ? (hasOptions(q.type) && type !== 'true_false' && q.type !== 'true_false' ? q.options : fresh.options) : [],
    correct_answer: hasAnswerKey(type) ? q.correct_answer : '',
  }
}

export function moveItem<T>(list: T[], from: number, to: number): T[] {
  if (to < 0 || to >= list.length) return list
  const out = [...list]
  const [it] = out.splice(from, 1)
  out.splice(to, 0, it)
  return out
}

// questionIssues: cảnh báo trước khi lưu (server vẫn kiểm lại).
export function questionIssues(qs: Question[]): string[] {
  const out: string[] = []
  qs.forEach((q, i) => {
    const n = i + 1
    if (!q.title.trim()) out.push(`Câu ${n}: chưa có nội dung câu hỏi.`)
    if (hasOptions(q.type) && (q.options ?? []).filter((o) => o.text.trim() || o.image_url).length < 2)
      out.push(`Câu ${n}: cần ít nhất 2 phương án.`)
    if (hasOptions(q.type) && q.correct_answer && !(q.options ?? []).some((o) => o.key === q.correct_answer))
      out.push(`Câu ${n}: đáp án đúng không nằm trong các phương án.`)
  })
  return out
}

// Mã đề phải chứa khối lớp (vd. G3-G4) để chấm đúng thang điểm.
export function codeMatchesGroup(code: string, token: string | undefined) {
  return !token || token === 'KHAC' || code.toUpperCase().includes(token)
}

export function formatDateTime(iso: string | null | undefined) {
  if (!iso) return '—'
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return iso
  const p = (n: number) => String(n).padStart(2, '0')
  return `${p(d.getHours())}:${p(d.getMinutes())} ${p(d.getDate())}/${p(d.getMonth() + 1)}/${d.getFullYear()}`
}

// Ngày dạng YYYY-MM-DD → dd/mm/yyyy.
export function formatDate(ymd: string) {
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(ymd)
  return m ? `${m[3]}/${m[2]}/${m[1]}` : ymd
}

// ---- Phiếu trả lời đề PDF (PdfSheetEditor.vue của Laravel) ----

export const SHEET_LETTERS = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H']
export const SHEET_SKILLS = [
  { value: 'listening', label: 'Nghe' },
  { value: 'reading', label: 'Đọc' },
  { value: 'grammar', label: 'Ngữ pháp' },
  { value: 'writing', label: 'Viết' },
  { value: 'speaking', label: 'Nói' },
]
export const SHEET_TYPES = [
  { value: 'multiple_choice', label: 'Trắc nghiệm' },
  { value: 'fill_blank', label: 'Điền từ' },
  { value: 'essay', label: 'Tự luận' },
  { value: 'speaking_prompt', label: 'Speaking' },
]

// Câu chấm tự động (có đáp án)
export const isGradable = (q: Question) => q.type === 'multiple_choice' || q.type === 'fill_blank'
const hasKey = (q: Question) => String(q.correct_answer ?? '').trim() !== ''
export const keyedCount = (qs: Question[]) => qs.filter((q) => isGradable(q) && hasKey(q)).length
export const gradableCount = (qs: Question[]) => qs.filter(isGradable).length
// Phiếu đã có đáp án / nội dung nhập tay (hỏi trước khi thay cả phiếu)
export const sheetHasWork = (qs: Question[]) => qs.some((q) => hasKey(q) || String(q.title ?? '').trim() !== '')

export function sheetOptions(count: number, old: QuestionOption[] = []): QuestionOption[] {
  return SHEET_LETTERS.slice(0, count).map((key, i) => ({ ...(old[i] ?? {}), key, text: old[i]?.text ?? '' }))
}

export function sheetQuestion(id: string, number: string | number): Question {
  return {
    id,
    number: String(number),
    skill: 'reading',
    type: 'multiple_choice',
    section: '',
    title: '',
    audio_url: '',
    passage: '',
    options: sheetOptions(3),
    correct_answer: '',
    points: 1,
    explanation: '',
    teacher_note: '',
  }
}

// id kế tiếp: số lớn nhất + 1 (phiếu dùng id số như Laravel)
export const nextSheetId = (qs: Question[]) => String(qs.reduce((max, q) => Math.max(max, Number(q.id) || 0), 0) + 1)

export function setSheetType(q: Question, type: string): Question {
  if (type === 'multiple_choice') {
    const options = q.options?.length ? q.options : sheetOptions(3)
    return { ...q, type, options, correct_answer: options.some((o) => o.key === q.correct_answer) ? q.correct_answer : '' }
  }
  return { ...q, type, options: [], correct_answer: type === 'fill_blank' ? q.correct_answer : '' }
}

export function setOptionCount(q: Question, count: number): Question {
  const options = sheetOptions(Math.max(2, Math.min(SHEET_LETTERS.length, count)), q.options)
  return { ...q, options, correct_answer: options.some((o) => o.key === q.correct_answer) ? q.correct_answer : '' }
}

// addSheetRow: câu mới cuối phiếu, số câu nối tiếp, cùng kỹ năng / phần với câu cuối.
export function addSheetRow(qs: Question[]): Question[] {
  const last = qs[qs.length - 1]
  const number = last && /^\d+$/.test(String(last.number ?? '')) ? Number(last.number) + 1 : qs.length + 1
  const row = sheetQuestion(nextSheetId(qs), number)
  if (last) {
    row.skill = last.skill
    row.section = last.section ?? ''
  }
  return [...qs, row]
}

export function blankSheet(count: number): Question[] {
  const n = Math.max(1, Math.min(200, Math.trunc(count) || 0))
  return Array.from({ length: n }, (_, i) => sheetQuestion(String(i + 1), i + 1))
}

// showSection: in tiêu đề phần trước câu đầu của mỗi phần.
export function showSection(qs: Question[], i: number) {
  const section = qs[i]?.section ?? ''
  return section !== '' && (i === 0 || (qs[i - 1]?.section ?? '') !== section)
}

// fillAnswers điền danh sách {number, answer} vào phiếu: số câu không trùng → ghép theo số câu; đề đánh lại số từng
// phần → ghép theo thứ tự các câu chấm tự động. Chữ A–H vào câu trắc nghiệm, còn lại chuyển câu sang điền từ.
export function fillAnswers(qs: Question[], answers: KeyEntry[]): { questions: Question[]; applied: number } {
  if (!answers.length) return { questions: qs, applied: 0 }
  let out = qs.length ? qs.map((q) => ({ ...q })) : answers.map((a, i) => sheetQuestion(String(i + 1), a.number))
  const numbers = out.map((q) => String(q.number ?? ''))
  const unique = (list: string[]) => new Set(list).size === list.length
  const byNumber = unique(numbers) && numbers.every((n) => n !== '') && unique(answers.map((a) => String(a.number)))
  const gradable = out.map((q, i) => ({ q, i })).filter(({ q }) => q.type !== 'essay' && q.type !== 'speaking_prompt')
  let applied = 0
  answers.forEach((entry, k) => {
    const idx = byNumber ? out.findIndex((q) => String(q.number) === String(entry.number)) : (gradable[k]?.i ?? -1)
    const q = out[idx]
    if (!q || q.type === 'essay' || q.type === 'speaking_prompt') return
    const letter = /^\(?([A-Ha-h])\)?\.?$/.exec(entry.answer.trim())
    let next: Question
    if (q.type === 'multiple_choice' && letter) {
      const key = letter[1].toUpperCase()
      next = SHEET_LETTERS.indexOf(key) >= (q.options?.length ?? 0) ? setOptionCount(q, SHEET_LETTERS.indexOf(key) + 1) : q
      next = { ...next, correct_answer: key }
    } else {
      next = { ...setSheetType(q, 'fill_blank'), correct_answer: entry.answer }
    }
    out = out.map((x, j) => (j === idx ? next : x))
    applied++
  })
  return { questions: out, applied }
}
