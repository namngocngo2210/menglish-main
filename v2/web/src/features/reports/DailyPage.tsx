// Báo cáo ngày Học vụ / Học thuật: số liệu từng khối lấy từ hệ thống, người viết nhập ghi chú mỗi khối + task follow-up.
// Ngày chưa nộp: số liệu tính trực tiếp; đã nộp: số chụp lúc nộp, "Cập nhật số liệu" tính lại và Lưu thì ghi số mới.
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Alert, Badge, Button, Field, Select } from '../../components/ui'
import { reportsApi, type DailyForm, type TaskInput } from './api'
import { HistoryList, LegacyList, PageHeader, Panel, ReportTabs, ReviewState, SectionMetrics, TextArea, useNoteDraft } from './components'
import { formatDate, missingSummary } from './logic'
import { QueryState, SaveResult, usePeriod } from './shared'

export function DailyPage() {
  const [date, setDate] = usePeriod('date')
  const [refresh, setRefresh] = useState(false)
  const q = useQuery({ queryKey: ['reports', 'daily', date, refresh], queryFn: () => reportsApi.daily(date || undefined, refresh),
    placeholderData: keepPreviousData,
  })
  const pick = (d: string) => {
    setRefresh(false)
    setDate(d)
  }
  return (
    <QueryState isPending={q.isPending} error={q.error}>
      {() => <DailyFormView key={q.data!.date} form={q.data!} onPick={pick} onRefresh={() => setRefresh(true)} refreshing={q.isFetching} onSaved={() => setRefresh(false)} />}
    </QueryState>
  )
}

type NewTask = TaskInput & { uid: number }
let uid = 0

function DailyFormView({
  form,
  onPick,
  onRefresh,
  refreshing,
  onSaved,
}: {
  form: DailyForm
  onPick: (d: string) => void
  onRefresh: () => void
  refreshing: boolean
  onSaved: () => void
}) {
  const qc = useQueryClient()
  const sections = form.sections ?? []
  const initial = Object.fromEntries(sections.map((s) => [s.key, form.notes[s.key] ?? '']))
  const draft = useNoteDraft(`report-draft:daily:${form.date}`, initial)
  const [tasks, setTasks] = useState<NewTask[]>([])
  const [message, setMessage] = useState<string | null>(null)
  const missing = missingSummary(form.missing_days, form.date)

  const save = useMutation({
    mutationFn: () =>
      reportsApi.saveDaily({
        date: form.date,
        refresh: form.live && !!form.submitted_at,
        notes: draft.notes,
        tasks: tasks.map(({ uid: _uid, ...t }) => t),
      }),
    onSuccess: (r) => {
      setMessage(r.message)
      draft.clear()
      setTasks([])
      onSaved()
      qc.invalidateQueries({ queryKey: ['reports', 'daily'] })
    },
  })
  const setTask = (u: number, patch: Partial<NewTask>) => setTasks(tasks.map((t) => (t.uid === u ? { ...t, ...patch } : t)))

  return (
    <div>
      <PageHeader
        title={`Báo cáo ngày ${form.audience}`}
        meta="Số liệu lấy tự động từ hệ thống, bạn chỉ cần ghi chú."
        actions={
          <div className="w-56">
            <Field label="Ngày báo cáo" type="date" value={form.date} max={form.today} onChange={(e) => e.target.value && onPick(e.target.value)} hint={form.date_label} />
          </div>
        }
      />
      <ReportTabs tabs={form.tabs} current="daily_ops" />
      <form
        className="space-y-6"
        onSubmit={(e) => {
          e.preventDefault()
          setMessage(null)
          save.mutate()
        }}
      >
        <SaveResult message={message} error={save.error} />
        {missing.total > 0 && (
          <Alert>
            Bạn chưa nộp báo cáo ngày{' '}
            {missing.shown.map((d, i) => (
              <span key={d.date}>
                <button type="button" className="font-semibold underline" onClick={() => onPick(d.date)}>
                  {d.label}
                </button>
                {i < missing.shown.length - 1 ? ', ' : ''}
              </span>
            ))}
            {missing.extra > 0 && ` và ${missing.extra} ngày khác (xem danh sách bên dưới)`}. Ngày không nộp tính là nộp trễ.
          </Alert>
        )}
        <ReviewState review={form.review} />
        {form.submitted_at ? (
          <Alert tone="info">
            Đã nộp lúc {formatDate(form.first_submitted_at ?? form.submitted_at, 'H:i d/m/Y')}
            {form.first_submitted_at && form.first_submitted_at !== form.submitted_at && `, sửa lần cuối ${formatDate(form.submitted_at, 'H:i d/m/Y')}`}.{' '}
            {form.late && <Badge tone="error">Nộp sau hạn {form.deadline}</Badge>}{' '}
            {form.live
              ? 'Số liệu bên dưới vừa được tính lại, bấm Lưu để cập nhật báo cáo bằng số mới.'
              : 'Số liệu bên dưới là số lúc nộp, sửa ghi chú không làm đổi số.'}
          </Alert>
        ) : (
          form.date < form.today && <Alert tone="info">Hạn nộp báo cáo ngày này: {form.deadline}.</Alert>
        )}
        {draft.restored && (
          <Alert tone="info">
            Đã khôi phục ghi chú bạn gõ trên máy này nhưng chưa lưu.{' '}
            <button type="button" className="font-semibold underline" onClick={draft.discard}>
              Bỏ bản nháp
            </button>
          </Alert>
        )}

        <div className="flex flex-wrap items-center justify-between gap-2">
          <p className="text-sm text-on-surface-variant">Số liệu tính lúc {form.computed_at ? formatDate(form.computed_at, 'H:i d/m/Y') : '—'}</p>
          <Button type="button" variant="secondary" onClick={onRefresh} disabled={refreshing}>
            Cập nhật số liệu
          </Button>
        </div>

        {form.legacy_fields.length > 0 && (
          <Panel title="Số liệu đã nhập tay (bản cũ)">
            <p className="text-sm text-on-surface-variant">Báo cáo này nộp trước khi chuyển sang số liệu tự động. Lưu lại sẽ thay bằng số liệu hệ thống.</p>
            <dl className="grid grid-cols-1 gap-3 md:grid-cols-2">
              {form.legacy_fields.map((f) => (
                <div key={f.label}>
                  <dt className="text-xs text-on-surface-variant uppercase">{f.label}</dt>
                  <dd className="text-sm whitespace-pre-line">{f.value}</dd>
                </div>
              ))}
            </dl>
          </Panel>
        )}

        {sections.map((s) => (
          <SectionMetrics key={s.key} section={s}>
            <TextArea
              label={form.note_labels[s.key] ?? 'Ghi chú'}
              value={draft.notes[s.key] ?? ''}
              onChange={(v) => draft.setNotes({ ...draft.notes, [s.key]: v })}
              maxLength={5000}
              placeholder={s.key === 'media' ? 'Nhập nội dung kèm link bài viết...' : 'Ghi chú thêm (nếu có)...'}
            />
          </SectionMetrics>
        ))}

        <Panel
          title={
            <span className="flex flex-wrap items-center justify-between gap-3">
              Task follow-up
              <Button type="button" variant="secondary" onClick={() => setTasks([...tasks, { uid: ++uid, title: '', content: null, due_date: form.today, assignee_id: form.assignees[0]?.value ?? null }])}>
                Thêm task
              </Button>
            </span>
          }
        >
          {!form.tasks_enabled && (
            <p className="text-xs text-on-surface-variant italic">Phân hệ Công việc chưa chuyển sang hệ thống mới nên chưa tạo được task từ báo cáo.</p>
          )}
          {form.tasks.length > 0 ? (
            <ul className="space-y-1">
              {form.tasks.map((t) => (
                <li key={t.id} className="flex flex-wrap items-center gap-2 rounded-lg bg-surface-container-low px-3 py-2 text-sm">
                  <strong>{t.title}</strong>
                  <span className="text-on-surface-variant">
                    · {t.assignee ?? '—'} · hạn {formatDate(t.due_date)}
                  </span>
                  <Badge>{t.status_label}</Badge>
                </li>
              ))}
            </ul>
          ) : (
            !tasks.length && (
              <p className="text-sm text-on-surface-variant">
                Cần ai xử lý tiếp việc gì thì bấm "Thêm task". Mỗi task có tiêu đề, hạn và người phụ trách riêng.
              </p>
            )
          )}
          {tasks.map((t, i) => (
            <div key={t.uid} className="space-y-3 rounded-lg border border-surface-container-highest p-3">
              <div className="flex items-end gap-3">
                <div className="min-w-0 flex-1">
                  <Field label="Tiêu đề task" required maxLength={255} value={t.title} placeholder="Vd: Gọi lại phụ huynh bé An về học bù" onChange={(e) => setTask(t.uid, { title: e.target.value })} />
                </div>
                <Button type="button" variant="secondary" aria-label={`Bỏ task ${i + 1}`} onClick={() => setTasks(tasks.filter((x) => x.uid !== t.uid))}>
                  Bỏ
                </Button>
              </div>
              <TextArea label="Nội dung (tùy chọn)" rows={2} value={t.content ?? ''} onChange={(v) => setTask(t.uid, { content: v || null })} />
              <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                <Field label="Hạn xử lý" type="date" required min={form.today} value={t.due_date} onChange={(e) => setTask(t.uid, { due_date: e.target.value })} />
                <Select label="Người phụ trách (PIC)" value={t.assignee_id ?? ''} onChange={(e) => setTask(t.uid, { assignee_id: e.target.value || null })}>
                  {form.assignees.map((a) => (
                    <option key={a.value} value={a.value}>
                      {a.label}
                    </option>
                  ))}
                </Select>
              </div>
            </div>
          ))}
        </Panel>

        <div className="flex justify-end">
          <Button type="submit" disabled={save.isPending}>
            Lưu báo cáo
          </Button>
        </div>
      </form>

      <div className="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
        <HistoryList items={form.history} current={form.date} title="Các ngày gần đây" onPick={onPick} />
        <LegacyList items={form.legacy} />
      </div>
    </div>
  )
}
