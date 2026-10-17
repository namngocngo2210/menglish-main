import { useQuery } from '@tanstack/react-query'
import { api, errorMessage, type Me } from './api'
import { Shell } from './components/Shell'
import { Alert, Card, GuestShell } from './components/ui'
import { can, navItemFor } from './nav'
import { AccountPage } from './pages/AccountPage'
import { FirstPasswordPage } from './pages/FirstPasswordPage'
import { LoginPage } from './pages/LoginPage'
import { TotpRequiredPage } from './pages/TotpRequiredPage'
import { PAGES } from './routes'
import { usePath } from './router'
import { PublicTestPage, publicToken } from './features/placement/PublicTestPage'
import { usePasswordResetRoute } from './pages/PasswordResetPages'

export function App() {
  // Trang làm bài test đầu vào qua link mời: công khai, không cần đăng nhập.
  const token = publicToken(usePath())
  // Quên / đặt lại mật khẩu qua link email: công khai.
  const passwordReset = usePasswordResetRoute()
  if (token) return <PublicTestPage token={token} />
  if (passwordReset) return passwordReset
  return <AuthedApp />
}

function AuthedApp() {
  const me = useQuery({ queryKey: ['me'], queryFn: api.me })

  if (me.isPending) {
    return (
      <GuestShell>
        <p className="text-center text-sm text-on-surface-variant">Đang tải…</p>
      </GuestShell>
    )
  }
  if (me.isError) {
    return (
      <GuestShell>
        <Alert>{errorMessage(me.error)}</Alert>
      </GuestShell>
    )
  }
  if (!me.data) return <LoginPage />
  if (me.data.user.must_change_password) return <FirstPasswordPage user={me.data.user} />
  if (me.data.totp_required && !me.data.user.totp_enabled) return <TotpRequiredPage me={me.data} />
  return (
    <Shell me={me.data}>
      <Page me={me.data} />
    </Shell>
  )
}

function Page({ me }: { me: Me }) {
  const path = usePath()
  const item = navItemFor(path)
  if (!item) return <Card title="Không tìm thấy trang">Đường dẫn này không tồn tại.</Card>
  if (item.perms && !can(me, ...item.perms)) {
    return <Card title="Không có quyền">Bạn không có quyền xem trang này.</Card>
  }
  const page = PAGES[item.path]
  return page ? page(me) : <AccountPage me={me} />
}
