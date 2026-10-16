import { useQueryClient } from '@tanstack/react-query'
import { api, type User } from '../api'
import { Alert, Card, GuestShell } from '../components/ui'
import { ChangePasswordForm } from './ChangePasswordForm'

export function FirstPasswordPage({ user }: { user: User }) {
  const qc = useQueryClient()
  return (
    <GuestShell>
      <Card title="Đổi mật khẩu lần đầu">
        <div className="mb-4">
          <Alert tone="info">
            Chào {user.full_name}. Bạn đang dùng mật khẩu tạm do trung tâm cấp, hãy đặt mật khẩu của riêng bạn.
          </Alert>
        </div>
        <ChangePasswordForm onDone={() => qc.invalidateQueries({ queryKey: ['me'] })} />
        <button
          type="button"
          className="mt-3 w-full text-center text-xs text-on-surface-variant hover:underline"
          onClick={async () => {
            await api.logout()
            qc.invalidateQueries({ queryKey: ['me'] })
          }}
        >
          Đăng xuất
        </button>
      </Card>
    </GuestShell>
  )
}
