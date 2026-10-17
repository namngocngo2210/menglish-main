// Thành phần dùng chung của màn học viên.
import type { ReactNode } from 'react'
import { Alert } from '../../components/ui'
import { statusClass } from './logic'
import type { StatusColor } from './api'

export function StatusBadge({ color, children }: { color: StatusColor | string; children: ReactNode }) {
  return (
    <span className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium whitespace-nowrap ${statusClass(color)}`}>
      {children}
    </span>
  )
}

export function PageHeader({ title, description, actions }: { title: string; description?: ReactNode; actions?: ReactNode }) {
  return (
    <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <h1 className="text-xl font-semibold text-on-surface">{title}</h1>
        {description && <p className="mt-1 text-sm text-on-surface-variant">{description}</p>}
      </div>
      {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
    </div>
  )
}

// Mục phụ thuộc phân hệ chưa chuyển sang v2: nói rõ "chưa có", không hiện số liệu giả.
export function NotYet({ title, children }: { title: string; children: ReactNode }) {
  return (
    <div className="rounded-lg border border-dashed border-outline-variant p-4 text-sm">
      <p className="font-medium text-on-surface">{title}: chưa có</p>
      <p className="mt-1 text-on-surface-variant">{children}</p>
    </div>
  )
}

export function TempAccount({ intro, login, password }: { intro: string; login: string; password: string }) {
  return (
    <div className="space-y-3">
      <Alert tone="success">{intro} Mật khẩu chỉ hiện một lần, phải đổi ở lần đăng nhập đầu.</Alert>
      <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
        <dt className="text-on-surface-variant">Đăng nhập</dt>
        <dd className="font-mono">{login}</dd>
        <dt className="text-on-surface-variant">Mật khẩu tạm</dt>
        <dd className="font-mono">{password}</dd>
      </dl>
    </div>
  )
}
