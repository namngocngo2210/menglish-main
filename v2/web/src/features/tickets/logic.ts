// Logic thuần của Ticket hỗ trợ (kiểm file, mã lần gửi, đường dẫn, màu nhãn) — có test ở logic.test.ts.
import { checkFile, type FileRule } from '../../lib/files'

export const TICKETS_PATH = '/tickets'
export const NEW_PATH = '/tickets/new'

// Khớp tickets.AttachmentPolicy / MaxFiles của máy chủ.
export const ATTACHMENT_RULE: FileRule = {
  types: ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xlsx'],
  maxBytes: 15 * 1024 * 1024,
  tooLarge: 'Dung lượng tập tin đính kèm không được vượt quá 15360 kB.',
}
export const MAX_FILES = 4
export const ACCEPT = 'image/*,.pdf,.doc,.docx,.xlsx'

// Trang con theo đường dẫn: danh sách, tạo mới, chi tiết (id hoặc mã ticket).
export type TicketRoute = { kind: 'list' } | { kind: 'new' } | { kind: 'show'; id: string }
export function ticketRoute(path: string): TicketRoute {
  if (path === NEW_PATH) return { kind: 'new' }
  const rest = path.startsWith(TICKETS_PATH + '/') ? decodeURIComponent(path.slice(TICKETS_PATH.length + 1).split('/')[0]) : ''
  return rest ? { kind: 'show', id: rest } : { kind: 'list' }
}

export const ticketPath = (id: string) => `${TICKETS_PATH}/${id}`

// newToken: mã lần gửi của form tạo ticket (gửi lại sau lỗi mạng không tạo trùng).
export function newToken(): string {
  const c = globalThis.crypto
  if (c?.randomUUID) return c.randomUUID()
  return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}${Math.random().toString(36).slice(2)}`
}

// addFiles: thêm file vào danh sách đang chọn; trả danh sách mới + lỗi (file sai loại / quá lớn / quá số lượng).
export function addFiles(current: File[], incoming: File[]): { files: File[]; error: string | null } {
  const files = [...current]
  let error: string | null = null
  for (const f of incoming) {
    const bad = checkFile(f, ATTACHMENT_RULE)
    if (bad) {
      error = `${f.name}: ${bad}`
      continue
    }
    if (files.length >= MAX_FILES) {
      error = `Mỗi lần gửi tối đa ${MAX_FILES} tệp đính kèm.`
      break
    }
    files.push(f)
  }
  return { files, error }
}

// pastedName: tên file cho ảnh dán từ clipboard ("anh-chup-man-hinh-2.png").
export function pastedName(type: string, n: number): string {
  const ext = { 'image/jpeg': 'jpg', 'image/gif': 'gif', 'image/webp': 'webp' }[type] ?? 'png'
  return `anh-chup-man-hinh-${n}.${ext}`
}

// ticketForm: form multipart gửi máy chủ — trường payload (JSON) + attachments[0..3].
export function ticketForm(payload: unknown, files: File[]): FormData {
  const form = new FormData()
  form.append('payload', JSON.stringify(payload))
  files.forEach((f, i) => form.append(`attachments[${i}]`, f, f.name))
  return form
}

export type Tone = 'neutral' | 'success' | 'error' | 'info' | 'warning'

export function statusTone(status: string): Tone {
  return ({ open: 'warning', in_progress: 'info', resolved: 'success', closed: 'neutral' } as Record<string, Tone>)[status] ?? 'neutral'
}

export function priorityTone(priority: string): Tone {
  return ({ urgent: 'error', high: 'warning', medium: 'info', low: 'neutral' } as Record<string, Tone>)[priority] ?? 'neutral'
}

// initial: chữ cái đầu của tên (ảnh đại diện trong hội thoại).
export const initial = (name: string | null) => [...(name || 'U')][0].toUpperCase()
