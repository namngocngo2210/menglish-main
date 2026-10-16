import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { api, ApiError, errorMessage, type LoginResponse } from '../api'
import { Alert, Button, Card, Field, GuestShell, PasswordField } from '../components/ui'

export function LoginPage() {
  const qc = useQueryClient()
  const [challenge, setChallenge] = useState<string | null>(null)

  const onLoggedIn = (res: LoginResponse) => {
    if (res.status === 'totp_required') {
      setChallenge(res.challenge)
      return
    }
    qc.invalidateQueries({ queryKey: ['me'] })
  }

  return (
    <GuestShell>
      {challenge ? (
        <TotpStep challenge={challenge} onDone={onLoggedIn} onRestart={() => setChallenge(null)} />
      ) : (
        <PasswordStep onDone={onLoggedIn} />
      )}
    </GuestShell>
  )
}

function PasswordStep({ onDone }: { onDone: (r: LoginResponse) => void }) {
  const [identifier, setIdentifier] = useState('')
  const [password, setPassword] = useState('')
  const [remember, setRemember] = useState(false)
  const login = useMutation({ mutationFn: () => api.login(identifier, password, remember), onSuccess: onDone })

  const submit = (e: FormEvent) => {
    e.preventDefault()
    login.mutate()
  }

  return (
    <Card title="Đăng nhập">
      <form onSubmit={submit} className="space-y-4">
        {login.isError && <Alert>{errorMessage(login.error)}</Alert>}
        <Field
          label="Tài khoản"
          hint="Email, mã nhân viên, mã học viên hoặc SĐT phụ huynh"
          autoComplete="username"
          autoFocus
          required
          value={identifier}
          onChange={(e) => setIdentifier(e.target.value)}
        />
        <PasswordField
          label="Mật khẩu"
          autoComplete="current-password"
          required
          value={password}
          onChange={(e) => setPassword(e.target.value)}
        />
        <label className="flex items-center gap-2 text-sm text-slate-700">
          <input
            type="checkbox"
            className="size-4 rounded border-slate-300"
            checked={remember}
            onChange={(e) => setRemember(e.target.checked)}
          />
          Ghi nhớ đăng nhập 30 ngày
        </label>
        <Button type="submit" className="w-full" disabled={login.isPending}>
          {login.isPending ? 'Đang đăng nhập…' : 'Đăng nhập'}
        </Button>
        <p className="text-center text-xs text-slate-500">Quên mật khẩu? Liên hệ quản lý cơ sở để được cấp lại.</p>
      </form>
    </Card>
  )
}

function TotpStep({
  challenge,
  onDone,
  onRestart,
}: {
  challenge: string
  onDone: (r: LoginResponse) => void
  onRestart: () => void
}) {
  const [code, setCode] = useState('')
  const verify = useMutation({ mutationFn: () => api.loginTotp(challenge, code), onSuccess: onDone })
  const expired = verify.error instanceof ApiError && verify.error.code !== 'invalid_code'

  return (
    <Card title="Xác thực hai bước">
      <form
        onSubmit={(e) => {
          e.preventDefault()
          verify.mutate()
        }}
        className="space-y-4"
      >
        {verify.isError && <Alert>{errorMessage(verify.error)}</Alert>}
        <Field
          label="Mã xác thực"
          hint="Nhập mã 6 số trong ứng dụng xác thực, hoặc một mã dự phòng dạng xxxxx-xxxxx."
          inputMode="text"
          autoComplete="one-time-code"
          autoFocus
          required
          value={code}
          onChange={(e) => setCode(e.target.value)}
        />
        {expired ? (
          <Button type="button" className="w-full" onClick={onRestart}>
            Đăng nhập lại
          </Button>
        ) : (
          <Button type="submit" className="w-full" disabled={verify.isPending}>
            {verify.isPending ? 'Đang kiểm tra…' : 'Xác nhận'}
          </Button>
        )}
        <button type="button" onClick={onRestart} className="w-full text-center text-xs text-slate-500 hover:underline">
          Quay lại
        </button>
      </form>
    </Card>
  )
}
