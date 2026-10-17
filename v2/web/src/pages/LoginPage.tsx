import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { api, ApiError, errorMessage, type LoginResponse } from '../api'
import { Alert, Button, Card, Field, GuestShell, PasswordField } from '../components/ui'
import { navigate } from '../router'
import { FORGOT_PATH } from './PasswordResetPages'

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
      <p className="-mt-2 mb-4 text-sm text-on-surface-variant">
        Nhân viên dùng mã nhân viên, học viên dùng mã học viên do trung tâm cấp, phụ huynh dùng số điện thoại. Vẫn đăng
        nhập được bằng email.
      </p>
      <form onSubmit={submit} className="space-y-4">
        {login.isError && <Alert>{errorMessage(login.error)}</Alert>}
        <Field
          label="Tài khoản"
          placeholder="VD: MES012"
          autoCapitalize="none"
          spellCheck={false}
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
        <label className="flex items-center gap-2 text-sm text-on-surface">
          <input
            type="checkbox"
            className="size-4 rounded border-outline-variant accent-primary"
            checked={remember}
            onChange={(e) => setRemember(e.target.checked)}
          />
          Ghi nhớ đăng nhập
        </label>
        <Button type="submit" className="w-full" disabled={login.isPending}>
          {login.isPending ? 'Đang đăng nhập…' : 'Đăng nhập'}
        </Button>
        <button
          type="button"
          onClick={() => navigate(FORGOT_PATH)}
          className="w-full text-center text-xs text-on-surface-variant underline hover:text-on-surface"
        >
          Quên mật khẩu?
        </button>
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
        <button type="button" onClick={onRestart} className="w-full text-center text-xs text-on-surface-variant hover:underline">
          Quay lại
        </button>
      </form>
    </Card>
  )
}
