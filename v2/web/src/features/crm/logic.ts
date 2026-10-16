// Logic thuần của CRM (không gọi API) để test riêng: SĐT, đồng hồ SLA, định dạng, luật kéo thả Kanban.
import type { OwnerOption, SLA, StagePerms } from './api'

const TZ = 'Asia/Ho_Chi_Minh'

// SĐT Việt Nam: di động 10 số (03/05/07/08/09) hoặc cố định 11 số (02x); chấp nhận khoảng trắng, chấm, gạch, +84.
export function normalizePhone(phone: string): string {
  let d = phone.replace(/\D+/g, '')
  if ((d.length === 11 || d.length === 12) && d.startsWith('84')) d = '0' + d.slice(2)
  return d
}

export function validPhone(phone: string): boolean {
  const raw = phone.trim()
  if (!raw || /[^\d\s.\-+()]/.test(raw)) return false
  return /^(0[35789]\d{8}|02\d{9})$/.test(normalizePhone(raw))
}

// "Còn 2 giờ 14 phút" / "Quá hạn 1 ngày 3 giờ" (có ngày thì bỏ phút) — giống Go remainingLabel.
export function remainingLabel(deadline: Date, now: Date): string {
  let ms = deadline.getTime() - now.getTime()
  let prefix = 'Còn '
  if (ms < 0) {
    prefix = 'Quá hạn '
    ms = -ms
  }
  const days = Math.floor(ms / 86_400_000)
  const hours = Math.floor(ms / 3_600_000) % 24
  const minutes = Math.floor(ms / 60_000) % 60
  const parts: string[] = []
  if (days > 0) parts.push(`${days} ngày`)
  if (hours > 0) parts.push(`${hours} giờ`)
  if (days === 0 && minutes > 0) parts.push(`${minutes} phút`)
  return prefix + (parts.length ? parts.join(' ') : 'dưới 1 phút')
}

// Trạng thái đồng hồ SLA tính lại theo giờ hiện tại (trang mở lâu vẫn đúng).
export function slaState(sla: SLA, now: Date): SLA['state'] {
  const left = (new Date(sla.deadline).getTime() - now.getTime()) / 1000
  if (left < 0) return 'overdue'
  if (left < sla.warn_seconds) return 'due_soon'
  return 'on_time'
}

export const slaTone: Record<SLA['state'], 'error' | 'info' | 'success'> = {
  overdue: 'error',
  due_soon: 'info',
  on_time: 'success',
}

export function formatMoney(v: number | null | undefined): string {
  return `${Math.round(v ?? 0).toLocaleString('vi-VN').replace(/,/g, '.')} đ`
}

function parts(iso: string) {
  const f = new Intl.DateTimeFormat('en-GB', {
    timeZone: TZ,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
  })
  const p = Object.fromEntries(f.formatToParts(new Date(iso)).map((x) => [x.type, x.value]))
  return p as Record<'year' | 'month' | 'day' | 'hour' | 'minute', string>
}

// "15:04 02/01/2006" theo giờ Việt Nam như bản Laravel.
export function formatDateTime(iso: string | null | undefined): string {
  if (!iso) return '—'
  const p = parts(iso)
  return `${p.hour}:${p.minute} ${p.day}/${p.month}/${p.year}`
}

export function formatDate(iso: string | null | undefined): string {
  if (!iso) return '—'
  if (/^\d{4}-\d{2}-\d{2}$/.test(iso)) {
    const [y, m, d] = iso.split('-')
    return `${d}/${m}/${y}`
  }
  const p = parts(iso)
  return `${p.day}/${p.month}/${p.year}`
}

// Giá trị cho ô datetime-local (giờ Việt Nam).
export function toDateTimeLocal(iso: string | null | undefined): string {
  if (!iso) return ''
  const p = parts(iso)
  return `${p.year}-${p.month}-${p.day}T${p.hour}:${p.minute}`
}

// Ngày hôm nay (YYYY-MM-DD, giờ Việt Nam).
export function todayVN(now = new Date()): string {
  const p = parts(now.toISOString())
  return `${p.year}-${p.month}-${p.day}`
}

// Người phụ trách chọn được cho cơ sở đang chọn: Admin (mọi cơ sở) + người thuộc cơ sở đó.
export function ownersForBranch(owners: OwnerOption[], branchId: string | null | undefined): OwnerOption[] {
  if (!branchId) return owners
  return owners.filter((o) => o.all_branches || o.branch_ids.includes(branchId))
}

export type StageRules = { order: string[]; closed: string[]; permissions: StagePerms }

// Kéo thẻ Kanban: tiến đúng 1 bước (không vào Chờ xếp lớp / Đã chốt), lùi bước nếu có quyền (khách chưa chốt).
export function transitionType(r: StageRules, from: string, to: string): 'forward' | 'backward' | null {
  const a = r.order.indexOf(from)
  const b = r.order.indexOf(to)
  if (a < 0 || b < 0 || a === b) return null
  if (b === a + 1 && r.permissions.can_forward && !r.closed.includes(to)) return 'forward'
  if (b < a && r.permissions.can_backward && !r.closed.includes(from)) return 'backward'
  return null
}

export function canDrag(r: StageRules, stage: string): boolean {
  const i = r.order.indexOf(stage)
  const next = r.order[i + 1]
  const forward = r.permissions.can_forward && !!next && !r.closed.includes(next)
  return forward || (r.permissions.can_backward && i > 0 && !r.closed.includes(stage))
}

// Lựa chọn trong hộp "Sửa giai đoạn": tiến 1 bước, lùi về các bước trước, Thất bại.
export function stageEditOptions(r: StageRules, from: string, labels: Record<string, string>) {
  const out: { value: string; label: string }[] = []
  const i = r.order.indexOf(from)
  const next = r.order[i + 1]
  if (r.permissions.can_forward && next && !r.closed.includes(next)) out.push({ value: next, label: `Tiến 1 bước → ${labels[next]}` })
  if (r.permissions.can_backward && !r.closed.includes(from)) {
    for (const s of r.order.slice(0, Math.max(0, i)).reverse()) out.push({ value: s, label: `Lùi về ← ${labels[s]}` })
  }
  if (r.permissions.can_mark_lost && !r.closed.includes(from)) out.push({ value: 'lost', label: 'Chuyển sang Thất bại' })
  return out
}

export function needsReason(r: StageRules, from: string, to: string): boolean {
  return to === 'lost' || r.order.indexOf(to) < r.order.indexOf(from)
}
