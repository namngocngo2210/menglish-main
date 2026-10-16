import { useMutation, useQueryClient } from '@tanstack/react-query'
import QRCode from 'qrcode'
import { useEffect, useState } from 'react'
import { api, errorMessage, type Me } from '../api'
import { Alert, Button, Card, Field, PasswordField } from '../components/ui'

export function TwoFactorCard({ me }: { me: Me }) {
  const qc = useQueryClient()
  const [recovery, setRecovery] = useState<string[] | null>(null)
  const refresh = () => qc.invalidateQueries({ queryKey: ['me'] })

  return (
    <Card title="Xác thực hai bước">
      {recovery ? (
        <RecoveryCodes codes={recovery} onClose={() => setRecovery(null)} />
      ) : me.user.totp_enabled ? (
        <Enabled left={me.recovery_codes_left ?? 0} onDisabled={refresh} />
      ) : (
        <Setup
          onEnabled={(codes) => {
            setRecovery(codes)
            refresh()
          }}
        />
      )}
    </Card>
  )
}

function Setup({ onEnabled }: { onEnabled: (codes: string[]) => void }) {
  const [qr, setQr] = useState<string | null>(null)
  const [code, setCode] = useState('')
  const setup = useMutation({ mutationFn: api.totpSetup })
  const enable = useMutation({ mutationFn: () => api.totpEnable(code), onSuccess: (r) => onEnabled(r.recovery_codes) })

  useEffect(() => {
    if (setup.data) QRCode.toDataURL(setup.data.otpauth_url, { margin: 1, width: 200 }).then(setQr)
  }, [setup.data])

  if (!setup.data) {
    return (
      <div className="space-y-3">
        <p className="text-sm text-on-surface-variant">
          Khi bật, mỗi lần đăng nhập cần thêm mã 6 số từ ứng dụng xác thực (Google Authenticator, Microsoft
          Authenticator...). Bắt buộc với Admin và người có quyền phân quyền, duyệt tiền, chạy lương.
        </p>
        {setup.isError && <Alert>{errorMessage(setup.error)}</Alert>}
        <Button onClick={() => setup.mutate()} disabled={setup.isPending}>
          Bật xác thực hai bước
        </Button>
      </div>
    )
  }

  return (
    <form
      className="space-y-4"
      onSubmit={(e) => {
        e.preventDefault()
        enable.mutate()
      }}
    >
      <p className="text-sm text-on-surface-variant">1. Quét mã QR bằng ứng dụng xác thực, hoặc nhập khóa bên dưới.</p>
      {qr && <img src={qr} alt="Mã QR xác thực hai bước" className="size-48 rounded border border-surface-container-highest" />}
      <p className="font-mono text-xs break-all text-on-surface">{setup.data.secret}</p>
      <p className="text-sm text-on-surface-variant">2. Nhập mã 6 số ứng dụng hiện ra để xác nhận.</p>
      {enable.isError && <Alert>{errorMessage(enable.error)}</Alert>}
      <Field
        label="Mã xác thực"
        inputMode="numeric"
        autoComplete="one-time-code"
        required
        value={code}
        onChange={(e) => setCode(e.target.value)}
      />
      <Button type="submit" disabled={enable.isPending}>
        Xác nhận và bật
      </Button>
    </form>
  )
}

function RecoveryCodes({ codes, onClose }: { codes: string[]; onClose: () => void }) {
  return (
    <div className="space-y-4">
      <Alert tone="success">Đã bật xác thực hai bước.</Alert>
      <p className="text-sm text-on-surface">
        Lưu 10 mã dự phòng này ở nơi an toàn. Mỗi mã dùng được một lần khi bạn không có điện thoại. Mã chỉ hiện một
        lần.
      </p>
      <ul className="grid grid-cols-2 gap-2 rounded-lg bg-background p-3 font-mono text-sm">
        {codes.map((c) => (
          <li key={c}>{c}</li>
        ))}
      </ul>
      <div className="flex gap-2">
        <Button variant="secondary" onClick={() => navigator.clipboard?.writeText(codes.join('\n'))}>
          Sao chép
        </Button>
        <Button onClick={onClose}>Tôi đã lưu</Button>
      </div>
    </div>
  )
}

function Enabled({ left, onDisabled }: { left: number; onDisabled: () => void }) {
  const [open, setOpen] = useState(false)
  const [password, setPassword] = useState('')
  const disable = useMutation({ mutationFn: () => api.totpDisable(password), onSuccess: onDisabled })

  return (
    <div className="space-y-3">
      <Alert tone="success">Đang bật. Còn {left} mã dự phòng chưa dùng.</Alert>
      {open ? (
        <form
          className="space-y-3"
          onSubmit={(e) => {
            e.preventDefault()
            disable.mutate()
          }}
        >
          {disable.isError && <Alert>{errorMessage(disable.error)}</Alert>}
          <PasswordField
            label="Nhập mật khẩu để tắt"
            autoComplete="current-password"
            required
            value={password}
            onChange={(e) => setPassword(e.target.value)}
          />
          <div className="flex gap-2">
            <Button type="submit" variant="danger" disabled={disable.isPending}>
              Tắt xác thực hai bước
            </Button>
            <Button type="button" variant="secondary" onClick={() => setOpen(false)}>
              Hủy
            </Button>
          </div>
        </form>
      ) : (
        <Button variant="danger" onClick={() => setOpen(true)}>
          Tắt
        </Button>
      )}
    </div>
  )
}
