// Quên mật khẩu / đặt lại mật khẩu qua email (Laravel Auth/ForgotPassword.vue, Auth/ResetPassword.vue). Công khai.
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { api, errorMessage } from '../api'
import { Alert, Button, Card, Field, GuestShell, PasswordField } from '../components/ui'
import { navigate, usePath, useQueryParam } from '../router'

export const FORGOT_PATH = '/forgot-password'
export const RESET_PATH = '/reset-password'

// usePasswordResetRoute trả trang quên / đặt lại mật khẩu theo đường dẫn, null nếu không phải hai trang này.
export function usePasswordResetRoute() {
  const path = usePath()
  const token = useQueryParam('token')
  const email = useQueryParam('email')
  if (path === RESET_PATH && token) return <ResetPasswordPage key={token} token={token} initialEmail={email} />
  if (path === FORGOT_PATH || path === RESET_PATH) return <ForgotPasswordPage />
  return null
}

function BackToLogin() {
  return (
    <button type="button" onClick={() => navigate('/')} className="w-full text-center text-xs text-on-surface-variant hover:underline">
      Quay lại đăng nhập
    </button>
  )
}

export function ForgotPasswordPage() {
  const [email, setEmail] = useState('')
  const send = useMutation({ mutationFn: () => api.forgotPassword(email) })
  const submit = (e: FormEvent) => {
    e.preventDefault()
    send.mutate()
  }
  return (
    <GuestShell>
      <Card title="Quên mật khẩu">
        <p className="-mt-2 mb-4 text-sm text-on-surface-variant">
          Nhập email đăng nhập của bạn. Hệ thống sẽ gửi một đường link để bạn đặt mật khẩu mới.
        </p>
        <form onSubmit={submit} className="space-y-4">
          {send.isSuccess && <Alert tone="success">{send.data.message}</Alert>}
          {send.isError && <Alert>{errorMessage(send.error)}</Alert>}
          <Field
            label="Email"
            type="email"
            autoComplete="email"
            autoFocus
            required
            value={email}
            onChange={(e) => setEmail(e.target.value)}
          />
          <Button type="submit" className="w-full" disabled={send.isPending}>
            {send.isPending ? 'Đang gửi…' : 'Gửi link đặt lại mật khẩu'}
          </Button>
          <BackToLogin />
        </form>
      </Card>
    </GuestShell>
  )
}

export function ResetPasswordPage({ token, initialEmail }: { token: string; initialEmail: string }) {
  const qc = useQueryClient()
  const [email, setEmail] = useState(initialEmail)
  const [password, setPassword] = useState('')
  const [confirm, setConfirm] = useState('')
  const reset = useMutation({
    mutationFn: () => api.resetPassword(token, email, password, confirm),
    onSuccess: () => {
      setPassword('')
      setConfirm('')
      // Đặt lại xong mọi phiên đều bị đăng xuất.
      void qc.invalidateQueries({ queryKey: ['me'] })
    },
  })
  const submit = (e: FormEvent) => {
    e.preventDefault()
    reset.mutate()
  }
  if (reset.isSuccess) {
    return (
      <GuestShell>
        <Card title="Đặt lại mật khẩu">
          <div className="space-y-4">
            <Alert tone="success">{reset.data.message}</Alert>
            <Button type="button" className="w-full" onClick={() => navigate('/')}>
              Đăng nhập
            </Button>
          </div>
        </Card>
      </GuestShell>
    )
  }
  return (
    <GuestShell>
      <Card title="Đặt lại mật khẩu">
        <form onSubmit={submit} className="space-y-4">
          {reset.isError && <Alert>{errorMessage(reset.error)}</Alert>}
          <Field label="Email" type="email" autoComplete="username" required value={email} onChange={(e) => setEmail(e.target.value)} />
          <PasswordField
            label="Mật khẩu"
            autoComplete="new-password"
            autoFocus
            required
            value={password}
            onChange={(e) => setPassword(e.target.value)}
          />
          <PasswordField
            label="Nhập lại mật khẩu"
            autoComplete="new-password"
            required
            value={confirm}
            onChange={(e) => setConfirm(e.target.value)}
          />
          <Button type="submit" className="w-full" disabled={reset.isPending}>
            {reset.isPending ? 'Đang lưu…' : 'Đặt lại mật khẩu'}
          </Button>
          <BackToLogin />
        </form>
      </Card>
    </GuestShell>
  )
}
