// Đơn chấm công & nghỉ (MyAttendance/Requests.vue): chọn loại đơn (bổ sung công, đi muộn / về sớm, nghỉ phép), đơn đã gửi
// (rút đơn chờ duyệt), các yêu cầu khác theo vai trò. ?type=<loại> mở sẵn form (từ "Quên chấm công?" / "Xin nghỉ").
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { errorMessage, type Me } from '../../api'
import { Alert, Badge, Button, Card, Dialog, Field, Icon } from '../../components/ui'
import { navigate, useQueryParam } from '../../router'
import { formatDateTime } from '../platform/format'
import { EmptyState } from '../platform/parts'
import { attendanceApi, type RequestInput, type RequestType } from './api'
import { isRequestType, REQUEST_HINTS, REQUEST_ICONS, roleRequests } from './logic'
import { AppLink } from './parts'

export function RequestsPage({ me }: { me: Me }) {
  const qc = useQueryClient()
  const typeParam = useQueryParam('type')
  const page = useQuery({ queryKey: ['attendance', 'requests'], queryFn: attendanceApi.requests })
  const [notice, setNotice] = useState('')
  const cancel = useMutation({
    mutationFn: attendanceApi.cancel,
    onSuccess: (r) => {
      setNotice(r.message)
      qc.invalidateQueries({ queryKey: ['attendance'] })
    },
  })
  const d = page.data
  const open = isRequestType(typeParam) ? typeParam : null
  const setOpen = (t: RequestType | null) => navigate(t ? `/cham-cong/don?type=${t}` : '/cham-cong/don')
  const others = roleRequests(me)

  return (
    <div className="mx-auto max-w-3xl space-y-4">
      <h1 className="text-xl font-semibold">Đơn chấm công & nghỉ</h1>
      {notice && <Alert tone="success">{notice}</Alert>}
      {(page.isError || cancel.isError) && <Alert>{errorMessage(page.error ?? cancel.error)}</Alert>}
      {d && (
        <>
          <div className="grid gap-3 sm:grid-cols-3">
            {d.types.map((t) => {
              const type = t.value as RequestType
              return (
                <button
                  key={t.value}
                  type="button"
                  onClick={() => setOpen(type)}
                  className="flex items-start gap-3 rounded-xl border border-surface-container-highest bg-surface p-4 text-left shadow-sm hover:border-primary"
                >
                  <span className="flex size-10 items-center justify-center rounded-xl bg-primary-light text-primary">
                    <Icon name={REQUEST_ICONS[type] ?? 'description'} />
                  </span>
                  <span className="min-w-0">
                    <span className="block text-sm font-semibold">{t.label}</span>
                    <span className="block text-xs text-on-surface-variant">{REQUEST_HINTS[type]}</span>
                  </span>
                </button>
              )
            })}
          </div>

          <Card title="Đơn đã gửi">
            {d.requests.length === 0 ? (
              <EmptyState icon="inbox" title="Bạn chưa gửi đơn nào" />
            ) : (
              <ul className="divide-y divide-surface-container-highest">
                {d.requests.map((r) => (
                  <li key={r.id} className="flex flex-wrap items-start justify-between gap-2 py-3 text-sm">
                    <div className="min-w-0">
                      <div className="font-medium">
                        {r.type_label} · {r.period}
                      </div>
                      <div className="text-on-surface-variant">{r.reason}</div>
                      <div className="text-xs text-on-surface-variant">
                        Gửi {formatDateTime(r.created_at)}
                        {r.reviewer && ` · ${r.status === 'approved' ? 'Duyệt' : 'Xử lý'} bởi ${r.reviewer}`}
                      </div>
                      {r.rejection_reason && <div className="text-xs text-error">Lý do từ chối: {r.rejection_reason}</div>}
                    </div>
                    <div className="flex items-center gap-2">
                      <Badge tone={r.status_tone}>{r.status_label}</Badge>
                      {r.status === 'pending' && (
                        <Button
                          variant="secondary"
                          className="h-8 px-3 text-xs"
                          disabled={cancel.isPending}
                          onClick={() => window.confirm('Rút đơn này?') && cancel.mutate(r.id)}
                        >
                          Rút đơn
                        </Button>
                      )}
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </Card>

          {others.length > 0 && (
            <Card title="Yêu cầu khác theo vai trò">
              <div className="grid gap-2 sm:grid-cols-2">
                {others.map((o) => (
                  <AppLink key={o.path} to={o.path} className="flex items-center gap-2 rounded-lg border border-surface-container-highest p-3 text-sm hover:border-primary">
                    <Icon name={o.icon} className="text-primary" />
                    {o.label}
                  </AppLink>
                ))}
              </div>
            </Card>
          )}

          {open && (
            <RequestDialog
              type={open}
              label={d.types.find((t) => t.value === open)?.label ?? ''}
              today={d.today}
              onClose={() => setOpen(null)}
              onDone={(m) => {
                setNotice(m)
                setOpen(null)
              }}
            />
          )}
        </>
      )}
    </div>
  )
}

function RequestDialog({ type, label, today, onClose, onDone }: { type: RequestType; label: string; today: string; onClose: () => void; onDone: (m: string) => void }) {
  const qc = useQueryClient()
  const [f, setF] = useState<RequestInput>({ type, date_from: today, date_to: today, check_in_time: '', check_out_time: '', reason: '' })
  const set = (k: keyof RequestInput, v: string) => setF((x) => ({ ...x, [k]: v }))
  const submit = useMutation({
    mutationFn: () => attendanceApi.submit({ ...f, date_to: type === 'leave' ? f.date_to : '' }),
    onSuccess: (r) => {
      qc.invalidateQueries({ queryKey: ['attendance'] })
      onDone(r.message)
    },
  })

  return (
    <Dialog title={label} onClose={onClose}>
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault()
          submit.mutate()
        }}
      >
        <p className="text-sm text-on-surface-variant">{REQUEST_HINTS[type]}</p>
        <div className="grid gap-3 sm:grid-cols-2">
          <Field
            label={type === 'leave' ? 'Từ ngày' : 'Ngày'}
            type="date"
            required
            value={f.date_from}
            max={type === 'correction' ? today : undefined}
            onChange={(e) => set('date_from', e.target.value)}
          />
          {type === 'leave' && <Field label="Đến ngày" type="date" required value={f.date_to} min={f.date_from} onChange={(e) => set('date_to', e.target.value)} />}
          {type === 'correction' && (
            <>
              <Field label="Giờ vào" type="time" value={f.check_in_time} onChange={(e) => set('check_in_time', e.target.value)} />
              <Field label="Giờ ra" type="time" value={f.check_out_time} onChange={(e) => set('check_out_time', e.target.value)} />
            </>
          )}
        </div>
        {type === 'correction' && <p className="text-xs text-on-surface-variant">Nhập giờ vào, giờ ra hoặc cả hai cần bổ sung.</p>}
        <div className="space-y-1.5">
          <label htmlFor="att-reason" className="block text-sm font-medium">
            Lý do
          </label>
          <textarea
            id="att-reason"
            required
            maxLength={1000}
            rows={4}
            value={f.reason}
            onChange={(e) => set('reason', e.target.value)}
            className="block w-full rounded-lg border border-outline-variant px-3 py-2 text-sm outline-none focus:border-primary focus:ring-2 focus:ring-primary/25"
          />
        </div>
        {submit.isError && <Alert>{errorMessage(submit.error)}</Alert>}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            Hủy
          </Button>
          <Button type="submit" disabled={submit.isPending}>
            {submit.isPending ? 'Đang gửi…' : 'Gửi đơn'}
          </Button>
        </div>
      </form>
    </Dialog>
  )
}
