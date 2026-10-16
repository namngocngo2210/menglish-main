import { useMutation } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { api, errorMessage } from '../api'
import { Alert, Button, PasswordField } from '../components/ui'

// Dùng cho cả lần đăng nhập đầu (bắt buộc) và đổi mật khẩu trong trang Bảo mật.
export function ChangePasswordForm({ onDone }: { onDone: () => void }) {
  const [current, setCurrent] = useState('')
  const [next, setNext] = useState('')
  const [confirm, setConfirm] = useState('')
  const [mismatch, setMismatch] = useState(false)
  const change = useMutation({ mutationFn: () => api.changePassword(current, next), onSuccess: onDone })

  const submit = (e: FormEvent) => {
    e.preventDefault()
    setMismatch(next !== confirm)
    if (next === confirm) change.mutate()
  }

  return (
    <form onSubmit={submit} className="space-y-4">
      {mismatch && <Alert>Mật khẩu nhập lại không khớp.</Alert>}
      {change.isError && <Alert>{errorMessage(change.error)}</Alert>}
      <PasswordField
        label="Mật khẩu hiện tại"
        autoComplete="current-password"
        required
        value={current}
        onChange={(e) => setCurrent(e.target.value)}
      />
      <PasswordField
        label="Mật khẩu mới"
        autoComplete="new-password"
        required
        minLength={10}
        value={next}
        onChange={(e) => setNext(e.target.value)}
      />
      <PasswordField
        label="Nhập lại mật khẩu mới"
        autoComplete="new-password"
        required
        value={confirm}
        onChange={(e) => setConfirm(e.target.value)}
      />
      <p className="text-xs text-on-surface-variant">
        Ít nhất 10 ký tự, không dùng mật khẩu phổ biến hoặc chứa mã, email, SĐT của bạn. Các thiết bị khác sẽ bị đăng
        xuất.
      </p>
      <Button type="submit" className="w-full" disabled={change.isPending}>
        {change.isPending ? 'Đang lưu…' : 'Đổi mật khẩu'}
      </Button>
    </form>
  )
}
