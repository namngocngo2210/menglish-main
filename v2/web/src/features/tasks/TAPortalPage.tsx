// Nhiệm vụ hôm nay của TA (portal.ta-tasks): việc trợ giảng theo 3 ca, tiến độ trong ngày, báo hoàn thành kèm ảnh minh chứng.
// Có ảnh minh chứng → hoàn thành ngay; không có → chờ Admin xác nhận. Lớp trực: buổi mình được gán + buổi của lớp có
// việc giao trong ngày. Báo cáo trực lớp: chờ chuyển màn báo cáo buổi học.
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { errorMessage, type Me } from '../../api'
import { Alert, Button, Card, Dialog, Field, Select } from '../../components/ui'
import { tasksApi, type SlotKey, type TACard } from './api'
import { Chip, PageHeader, StatusBadge, TextArea } from './components'
import { formatDate, formatDateTime, percent } from './logic'

const COMPLETABLE = ['new', 'in_progress', 'overdue']

export function TAPortalPage(_: { me: Me }) {
  const [date, setDate] = useState('')
  const [ta, setTa] = useState('')
  const [open, setOpen] = useState<Partial<Record<SlotKey, boolean>>>({})
  const [completing, setCompleting] = useState<TACard | null>(null)
  const [notice, setNotice] = useState('')
  const portal = useQuery({ queryKey: ['tasks', 'ta-portal', date, ta], queryFn: () => tasksApi.taPortal(date, ta) })

  if (portal.isPending) return <p className="text-sm text-on-surface-variant">Đang tải…</p>
  if (portal.isError) return <Alert>{errorMessage(portal.error)}</Alert>
  const p = portal.data
  const pct = percent(p.done, p.total)
  const isOpen = (key: SlotKey) => open[key] ?? key === p.first_open

  return (
    <div className="mx-auto max-w-4xl">
      <PageHeader
        title={p.title}
        description={p.ta_user ? `${p.ta_user.name}${p.ta_user.branch ? ` · ${p.ta_user.branch}` : ''}` : undefined}
      />
      {notice && (
        <div className="mb-4">
          <Alert tone="success">{notice}</Alert>
        </div>
      )}
      <Card>
        <div className="flex flex-wrap items-end gap-4">
          <div className="min-w-48 flex-1">
            <p className="text-sm text-on-surface-variant">
              Tiến độ {p.is_today ? 'hôm nay' : `ngày ${formatDate(p.date)}`}: <span className="font-semibold text-on-surface">{p.done}/{p.total}</span> việc ({pct}%)
            </p>
            <div className="mt-2 h-2 overflow-hidden rounded-full bg-surface-container-high" role="progressbar" aria-valuenow={pct} aria-valuemin={0} aria-valuemax={100}>
              <div className="h-full rounded-full bg-primary transition-all" style={{ width: `${pct}%` }} />
            </div>
          </div>
          <div className="w-44">
            <Field label="Ngày" type="date" value={date || p.date} onChange={(e) => setDate(e.target.value)} />
          </div>
          {p.can_pick_ta && (
            <div className="w-56">
              <Select label="Trợ giảng" value={ta || p.ta_user?.id || ''} onChange={(e) => setTa(e.target.value)}>
                {!p.ta_user && <option value="">-- Chọn trợ giảng --</option>}
                {p.assistants.map((a) => (
                  <option key={a.id} value={a.id}>
                    {a.label}
                  </option>
                ))}
              </Select>
            </div>
          )}
        </div>
      </Card>

      {p.overdue_count > 0 && (
        <div className="mt-4">
          <Alert>Bạn còn {p.overdue_count} việc quá hạn từ các ngày trước chưa xử lý. Hãy hoàn thành hoặc báo lại cho người giao.</Alert>
        </div>
      )}
      {!p.ta_user && p.can_pick_ta && (
        <div className="mt-4">
          <Alert tone="info">Chọn một trợ giảng để xem nhiệm vụ trong ngày.</Alert>
        </div>
      )}

      <div className="mt-4 space-y-3">
        {p.groups.map((g) => (
          <section key={g.key} className="rounded-xl border border-surface-container-highest bg-surface shadow-sm">
            <button
              type="button"
              aria-expanded={isOpen(g.key)}
              onClick={() => setOpen({ ...open, [g.key]: !isOpen(g.key) })}
              className="flex w-full items-center justify-between px-4 py-3 text-left"
            >
              <span className="font-semibold">{g.title}</span>
              <span className="text-xs text-on-surface-variant">
                {g.tasks.filter((t) => t.status === 'completed').length}/{g.tasks.length} xong {isOpen(g.key) ? '▲' : '▼'}
              </span>
            </button>
            {isOpen(g.key) && (
              <div className="space-y-2 border-t border-surface-container-highest p-3">
                {g.tasks.length === 0 && <p className="py-2 text-center text-sm text-on-surface-variant">Không có nhiệm vụ trong ca này.</p>}
                {g.tasks.map((t) => (
                  <TaskCard key={t.id} task={t} canComplete={p.can_complete} onComplete={() => setCompleting(t)} />
                ))}
              </div>
            )}
          </section>
        ))}
      </div>

      <div className="mt-4">
        <Card title={`Lớp trực ${p.is_today ? 'hôm nay' : 'ngày ' + formatDate(p.date)}`}>
          {p.sessions.length === 0 ? (
            <p className="text-sm italic text-on-surface-variant">Không có buổi học nào trong ngày.</p>
          ) : (
            <ul className="space-y-2">
              {p.sessions.map((s) => (
                <li key={s.id} className="flex items-center justify-between gap-2 rounded-lg border border-outline-variant p-3">
                  <div className="min-w-0">
                    <p className="truncate font-medium">{s.class_name}</p>
                    <p className="text-xs text-on-surface-variant">
                      <span className="tabular-nums">
                        {s.start} - {s.end}
                      </span>
                      {' · '}
                      {s.room || 'Chưa có phòng'}
                      {s.branch && ` · ${s.branch}`}
                    </p>
                  </div>
                  {s.makeup && <Chip>Học bù</Chip>}
                </li>
              ))}
            </ul>
          )}
        </Card>
      </div>

      {completing && (
        <CompleteDialog
          task={completing}
          onClose={() => setCompleting(null)}
          onDone={(m) => {
            setNotice(m)
            setCompleting(null)
          }}
        />
      )}
    </div>
  )
}

function TaskCard({ task: t, canComplete, onComplete }: { task: TACard; canComplete: boolean; onComplete: () => void }) {
  const done = t.status === 'completed'
  return (
    <div className={`rounded-lg border p-3 ${t.status === 'overdue' ? 'border-error/40' : 'border-outline-variant'} ${done ? 'opacity-75' : ''}`}>
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div className="min-w-0 flex-1">
          <p className={`font-medium ${done ? 'line-through' : ''}`}>{t.title}</p>
          <p className="mt-0.5 text-xs text-on-surface-variant">
            Hạn: {t.due_label} · Người giao: {t.creator}
            {t.class_label && ` · ${t.class_label}`}
            {t.room && ` · Phòng ${t.room}`}
            {t.session_time && ` · ${t.session_time}`}
          </p>
        </div>
        <StatusBadge status={t.status} label={t.pending_label ?? t.status_label} />
      </div>
      {t.late_hours > 0 && !done && <p className="mt-1 text-xs text-error">Trễ {t.late_hours} giờ so với hạn.</p>}
      {t.blocked_reason && <p className="mt-1 text-xs text-error">Lý do bị chặn: {t.blocked_reason}</p>}
      {t.rejection_reason && <p className="mt-1 text-xs text-error">Yêu cầu làm lại: {t.rejection_reason}</p>}
      {done && t.completed_at && (
        <p className="mt-1 text-xs text-on-surface-variant">
          Hoàn thành lúc {formatDateTime(t.completed_at)}
          {t.has_proof ? ' · có ảnh minh chứng' : ''}
        </p>
      )}
      {canComplete && COMPLETABLE.includes(t.status) && (
        <div className="mt-2 flex justify-end">
          <Button className="h-8 px-3" onClick={onComplete}>
            Báo xong
          </Button>
        </div>
      )}
    </div>
  )
}

function CompleteDialog({ task, onClose, onDone }: { task: TACard; onClose: () => void; onDone: (m: string) => void }) {
  const qc = useQueryClient()
  const [note, setNote] = useState('')
  const [proof, setProof] = useState('')
  const save = useMutation({
    mutationFn: () => tasksApi.complete(task.id, note, proof.trim()),
    onSuccess: (res) => {
      qc.invalidateQueries({ queryKey: ['tasks'] })
      onDone(res.message)
    },
  })
  return (
    <Dialog title={`Báo hoàn thành: ${task.title}`} onClose={onClose}>
      <form
        className="space-y-3"
        onSubmit={(e) => {
          e.preventDefault()
          save.mutate()
        }}
      >
        {save.isError && <Alert>{errorMessage(save.error)}</Alert>}
        <TextArea label="Ghi chú (tùy chọn)" rows={3} maxLength={1000} value={note} onChange={(e) => setNote(e.target.value)} placeholder="Kết quả, vướng mắc..." />
        <Field
          label="Link ảnh minh chứng"
          type="url"
          maxLength={2048}
          placeholder="https://..."
          value={proof}
          onChange={(e) => setProof(e.target.value)}
          hint="Có ảnh minh chứng: hoàn thành ngay. Không có: chuyển sang Chờ xác nhận để Admin duyệt."
        />
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            Hủy
          </Button>
          <Button type="submit" disabled={save.isPending}>
            {save.isPending ? 'Đang gửi…' : proof.trim() ? 'Hoàn thành' : 'Báo xong (chờ xác nhận)'}
          </Button>
        </div>
      </form>
    </Dialog>
  )
}
