import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { api, type Me } from '../api'
import { Alert, Button, Card } from '../components/ui'
import { ChangePasswordForm } from './ChangePasswordForm'
import { SessionsCard } from './SessionsCard'
import { TwoFactorCard } from './TwoFactorCard'

const kindLabel = { staff: 'Nhân sự', student: 'Học viên', guardian: 'Phụ huynh' }

export function HomePage({ me }: { me: Me }) {
  const qc = useQueryClient()
  const meta = useQuery({ queryKey: ['meta'], queryFn: api.meta })
  const logout = useMutation({
    mutationFn: api.logout,
    onSettled: () => qc.invalidateQueries({ queryKey: ['me'] }),
  })
  const u = me.user

  return (
    <div className="min-h-dvh bg-background text-on-surface">
      <header className="border-b border-surface-container-highest bg-surface">
        <div className="mx-auto flex h-14 max-w-3xl items-center justify-between px-4">
          <span className="flex items-center gap-2 font-semibold">
            <img src="/images/menglish-logo.png" alt="" width={32} height={32} className="size-8 rounded-md" />
            MEnglish
          </span>
          <div className="flex items-center gap-3 text-sm">
            <span className="hidden text-on-surface-variant sm:inline">{u.full_name}</span>
            <Button variant="secondary" onClick={() => logout.mutate()} disabled={logout.isPending}>
              Đăng xuất
            </Button>
          </div>
        </div>
      </header>

      <main className="mx-auto max-w-3xl space-y-6 px-4 py-6">
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
          </dl>
          <p className="mt-4 text-sm text-on-surface-variant">
            Các chức năng quản lý sẽ xuất hiện ở đây khi từng module được hoàn thành.
          </p>
        </Card>

        <TwoFactorCard me={me} />
        <PasswordCard />
        <SessionsCard />

        {meta.data && (
          <p className="text-center text-xs text-on-surface-subtle">
            {meta.data.env} · {meta.data.version.slice(0, 12)}
          </p>
        )}
      </main>
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
