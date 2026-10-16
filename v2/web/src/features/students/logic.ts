// Logic thuần của màn học viên (không phụ thuộc React) để test riêng.
import type { StatusColor, StudentInput, StudentProfile } from './api'

// 2026-03-02 → 02/03/2026; null / trống → null.
export function formatDate(iso: string | null | undefined): string | null {
  if (!iso) return null
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso)
  return m ? `${m[3]}/${m[2]}/${m[1]}` : null
}

// Thời điểm (ISO) → "Hôm nay, 14:05" hoặc "02/03/2026 14:05" theo giờ máy người dùng.
export function formatDateTime(iso: string, now = new Date()): string {
  const d = new Date(iso)
  const pad = (n: number) => String(n).padStart(2, '0')
  const time = `${pad(d.getHours())}:${pad(d.getMinutes())}`
  if (d.toDateString() === now.toDateString()) return `Hôm nay, ${time}`
  return `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()} ${time}`
}

// Màu badge trạng thái như UiBadge của Laravel.
export function statusClass(color: StatusColor | string): string {
  switch (color) {
    case 'info':
      return 'bg-info-container text-on-info-container'
    case 'success':
      return 'bg-success-container text-on-success-container'
    case 'warning':
      return 'bg-warning-container text-warning'
    case 'primary':
      return 'bg-primary-light text-primary'
    case 'error':
      return 'bg-error-container text-on-error-container'
    default:
      return 'bg-surface-container-high text-on-surface-variant'
  }
}

// Bật / tắt một chip trạng thái trong bộ lọc.
export function toggle(list: string[], value: string): string[] {
  return list.includes(value) ? list.filter((v) => v !== value) : [...list, value]
}

// Đường dẫn trang học viên: /students → danh sách; /students/enrollments → tiếp nhận & xếp lớp;
// /students/<id hoặc mã> → hồ sơ; /students/<id>/scoped → hồ sơ theo phân quyền.
export type StudentRoute =
  | { page: 'list' }
  | { page: 'enrollments' }
  | { page: 'profile'; id: string; scoped: boolean }

export function parseStudentPath(path: string): StudentRoute {
  const parts = path.replace(/\/+$/, '').split('/').filter(Boolean)
  if (parts[0] !== 'students' || parts.length === 1) return { page: 'list' }
  if (parts[1] === 'enrollments' && parts.length === 2) return { page: 'enrollments' }
  return { page: 'profile', id: decodeURIComponent(parts[1]), scoped: parts[2] === 'scoped' }
}

// Dữ liệu form sửa từ hồ sơ (giá trị null thành chuỗi trống, kèm phiên bản để báo người khác vừa sửa).
export function toInput(p: StudentProfile): StudentInput {
  return {
    full_name: p.full_name,
    phone: p.phone ?? '',
    email: p.email ?? '',
    dob: p.dob ?? '',
    gender: p.gender ?? '',
    target: p.target ?? '',
    parent_name: p.parent_name ?? '',
    parent_phone: p.parent_phone ?? '',
    school: p.school ?? '',
    study_started_on: p.study_started_on ?? '',
    address: p.address ?? '',
    notes: p.notes ?? '',
    version: p.version,
  }
}

// Giới tính: giá trị cũ ngoài danh sách (nhập Excel CRM ghi tự do) vẫn hiện để lưu form không làm mất.
export function genderOptions(current: string | null | undefined): string[] {
  const base = ['Nam', 'Nữ', 'Khác']
  return current && !base.includes(current) ? [...base, current] : base
}

// Số trang của danh sách.
export function pageCount(total: number, perPage: number): number {
  return Math.max(1, Math.ceil(total / Math.max(1, perPage)))
}
