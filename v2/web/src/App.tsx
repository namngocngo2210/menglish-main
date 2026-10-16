import { useQuery } from '@tanstack/react-query'
import { api, errorMessage } from './api'
import { Alert, GuestShell } from './components/ui'
import { FirstPasswordPage } from './pages/FirstPasswordPage'
import { HomePage } from './pages/HomePage'
import { LoginPage } from './pages/LoginPage'

export function App() {
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
  return <HomePage me={me.data} />
}
