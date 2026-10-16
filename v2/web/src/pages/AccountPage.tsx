import { useState } from 'react'
import type { Me } from '../api'
import { Alert, Button, Card } from '../components/ui'
import { ChangePasswordForm } from './ChangePasswordForm'
import { SessionsCard } from './SessionsCard'
import { TwoFactorCard } from './TwoFactorCard'

const kindLabel = { staff: 'Nhân sự', student: 'Học viên', guardian: 'Phụ huynh' }

export function AccountPage({ me }: { me: Me }) {
  const u = me.user
  return (
    <div className="space-y-6">
      <Card title="Tài khoản">
        <dl className="grid grid-cols-[auto_1fr] gap-x-6 gap-y-2 text-sm">
          <dt className="text-on-surface-variant">Họ tên</dt>
          <dd>{u.full_name}</dd>
          <dt className="text-on-surface-variant">Loại tài khoản</dt>
          <dd>{kindLabel[u.kind]}</dd>
          {u.code && (
            <>
              <dt className="text-on-surface-variant">Mã</dt>
              <dd className="font-mono">{u.code}</dd>
            </>
          )}
          {u.email && (
            <>
              <dt className="text-on-surface-variant">Email</dt>
              <dd>{u.email}</dd>
            </>
          )}
          {u.phone && (
            <>
              <dt className="text-on-surface-variant">Số điện thoại</dt>
              <dd>{u.phone}</dd>
            </>
          )}
          <dt className="text-on-surface-variant">Vai trò</dt>
          <dd>{me.roles.length ? me.roles.map((r) => r.label).join(', ') : 'Chưa có vai trò'}</dd>
          {me.branches.length > 0 && (
            <>
              <dt className="text-on-surface-variant">Chi nhánh</dt>
              <dd>{me.branches.map((b) => (b.primary ? `${b.name} (chính)` : b.name)).join(', ')}</dd>
            </>
          )}
        </dl>
      </Card>

      <TwoFactorCard me={me} />
      <PasswordCard />
      <SessionsCard />
    </div>
  )
}

function PasswordCard() {
  const [open, setOpen] = useState(false)
  const [done, setDone] = useState(false)
  return (
    <Card title="Mật khẩu">
      {done && (
        <div className="mb-4">
          <Alert tone="success">Đã đổi mật khẩu. Các thiết bị khác đã bị đăng xuất.</Alert>
        </div>
      )}
      {open ? (
        <ChangePasswordForm
          onDone={() => {
            setOpen(false)
            setDone(true)
          }}
        />
      ) : (
        <Button
          variant="secondary"
          onClick={() => {
            setOpen(true)
            setDone(false)
          }}
        >
          Đổi mật khẩu
        </Button>
      )}
    </Card>
  )
}
