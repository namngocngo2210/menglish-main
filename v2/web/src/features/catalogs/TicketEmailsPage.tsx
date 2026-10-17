// Email nhận Ticket (SystemConfigController::ticketEmails của Laravel): người nhận, sự kiện báo, hòm thư SMTP.
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { errorMessage, type Me } from '../../api'
import { Alert, Button, Card, Checkbox, Field, PasswordField, Select } from '../../components/ui'
import { catalogsApi, type TicketEmailConfig, type TicketEmailInput } from './api'
import { TextArea } from './common'
import { splitEmails } from './format'

export function TicketEmailsPage({ me }: { me: Me }) {
  const cfg = useQuery({ queryKey: ['ticket-emails'], queryFn: catalogsApi.ticketEmails })
  if (cfg.isError) return <Alert>{errorMessage(cfg.error)}</Alert>
  return cfg.data ? (
    <div className="space-y-4">
      <TicketEmailsForm cfg={cfg.data} />
      <TestEmailCard blocked={cfg.data.mail_blocked} defaultEmail={me.user.email ?? ''} />
    </div>
  ) : null
}

// Gửi thử ngay qua SMTP đang lưu (route system-config.ticket-emails.test). Lưu cấu hình trước rồi mới gửi thử.
function TestEmailCard({ blocked, defaultEmail }: { blocked: boolean; defaultEmail: string }) {
  const [email, setEmail] = useState(defaultEmail)
  const test = useMutation({ mutationFn: () => catalogsApi.testTicketEmail(email.trim()) })
  const r = test.data
  return (
    <Card title="Gửi thử nghiệm (Test Email)">
      <form
        className="space-y-3"
        onSubmit={(e) => {
          e.preventDefault()
          test.mutate()
        }}
      >
        <p className="-mt-2 text-sm text-on-surface-variant">Bắn email thử để kiểm tra kết nối SMTP và tài khoản vừa cấu hình (đã lưu).</p>
        {blocked && <Alert tone="info">Môi trường này đang chặn gửi email ra ngoài (MAIL_BLOCK_OUTGOING), không gửi thử được.</Alert>}
        <Field
          label="Gửi tới địa chỉ email kiểm tra"
          type="email"
          placeholder="Nhập email nhận thư test..."
          value={email}
          onChange={(e) => setEmail(e.target.value)}
          hint="Để trống nếu muốn bắn đồng loạt tới tất cả email đã cấu hình ở trên."
        />
        <Button type="submit" variant="secondary" disabled={blocked || test.isPending}>
          {test.isPending ? 'Đang gửi…' : 'Bắn thử email ngay'}
        </Button>
        {test.isError && <Alert>{errorMessage(test.error)}</Alert>}
        {r && (
          <div data-testid="test-mail-result">
            <Alert tone={r.ok ? 'success' : 'error'}>
              <p className="font-semibold">{r.ok ? 'Kết quả gửi thử: thành công' : 'Kết quả gửi thử: thất bại'}</p>
              <p className="mt-1">{r.message}</p>
              {r.detail && <p className="mt-1 font-mono text-xs break-all">Chi tiết: {r.detail}</p>}
              {r.hints.map((h) => (
                <p key={h} className="mt-1 text-xs">
                  {h}
                </p>
              ))}
            </Alert>
          </div>
        )}
      </form>
    </Card>
  )
}

function TicketEmailsForm({ cfg }: { cfg: TicketEmailConfig }) {
  const qc = useQueryClient()
  const [emails, setEmails] = useState(cfg.emails.join('\n'))
  const [events, setEvents] = useState(cfg.events)
  const [mail, setMail] = useState(
    cfg.mail && {
      host: cfg.mail.host,
      port: String(cfg.mail.port || ''),
      encryption: cfg.mail.encryption || 'tls',
      username: cfg.mail.username,
      password: '',
      from_address: cfg.mail.from_address,
      from_name: cfg.mail.from_name,
    },
  )
  const [done, setDone] = useState('')
  const save = useMutation({
    mutationFn: () => {
      const body: TicketEmailInput = { emails: splitEmails(emails), events }
      if (cfg.can_manage_mail && mail) body.mail = { ...mail, port: Number(mail.port) || 0 }
      return catalogsApi.updateTicketEmails(body)
    },
    onSuccess: (r) => {
      setDone(r.message)
      qc.invalidateQueries({ queryKey: ['ticket-emails'] })
    },
    onError: () => setDone(''),
  })
  type MailForm = NonNullable<typeof mail>
  const setM = (k: keyof MailForm) => (e: { target: { value: string } }) => mail && setMail({ ...mail, [k]: e.target.value })

  return (
    <form
      className="space-y-4"
      onSubmit={(e) => {
        e.preventDefault()
        save.mutate()
      }}
    >
      {done && <Alert tone="success">{done}</Alert>}
      {save.isError && <Alert>{errorMessage(save.error)}</Alert>}
      <Card title="Email nhận thông báo">
        <div className="space-y-4">
          <TextArea
            label="Danh sách email"
            rows={4}
            value={emails}
            onChange={(e) => setEmails(e.target.value)}
            hint="Mỗi dòng một email (hoặc cách nhau bởi dấu phẩy). Email sai định dạng sẽ bị bỏ qua."
          />
          <fieldset className="space-y-1.5">
            <legend className="mb-1.5 text-sm font-medium">Sự kiện gửi thông báo</legend>
            {cfg.event_labels.map((ev) => (
              <Checkbox
                key={ev.value}
                label={ev.label}
                checked={!!events[ev.value]}
                onChange={(e) => setEvents({ ...events, [ev.value]: e.target.checked })}
              />
            ))}
          </fieldset>
        </div>
      </Card>
      {cfg.can_manage_mail && mail && (
        <Card title="Hòm thư gửi (SMTP)">
          <div className="grid gap-4 md:grid-cols-2">
            <Field label="Máy chủ SMTP" maxLength={255} value={mail.host} onChange={setM('host')} hint="Ví dụ smtp.gmail.com" />
            <div className="grid grid-cols-2 gap-4">
              <Field label="Cổng" type="number" min={1} max={65535} value={mail.port} onChange={setM('port')} />
              <Select label="Mã hóa" value={mail.encryption} onChange={setM('encryption')}>
                <option value="tls">TLS</option>
                <option value="ssl">SSL</option>
                <option value="null">Không</option>
              </Select>
            </div>
            <Field label="Tài khoản" maxLength={255} autoComplete="off" value={mail.username} onChange={setM('username')} />
            <div className="space-y-1">
              <PasswordField label="Mật khẩu ứng dụng" autoComplete="new-password" value={mail.password} onChange={setM('password')} />
              <p className="text-xs text-on-surface-variant">
                {cfg.mail?.has_password ? 'Đã lưu mật khẩu; để trống để giữ nguyên.' : 'Chưa lưu mật khẩu.'}
              </p>
            </div>
            <Field label="Email người gửi" type="email" maxLength={255} value={mail.from_address} onChange={setM('from_address')} />
            <Field label="Tên người gửi" maxLength={255} value={mail.from_name} onChange={setM('from_name')} />
          </div>
        </Card>
      )}
      <Button type="submit" disabled={save.isPending}>
        Lưu cấu hình
      </Button>
    </form>
  )
}
