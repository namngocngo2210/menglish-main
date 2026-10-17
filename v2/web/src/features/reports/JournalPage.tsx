// Nhật ký Học vụ: thêm sự việc (mã KPI + nội dung, mức độ, lớp; Admin / Quản lý ghi thay một Học vụ) và dòng thời gian
// các sự việc với "Theo dõi xử lý" (trạng thái, follow-up); Admin bật "Lỗi nghiêm trọng".
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { errorMessage } from '../../api'
import { Alert, Badge, Button, Select } from '../../components/ui'
import { reportsApi, type JournalEntry, type JournalPage as JournalData } from './api'
import { PageHeader, Pager, Panel, TextArea } from './components'
import { formatDate } from './logic'
import { QueryState } from './shared'

const severityOptions = [
  { value: 'normal', label: 'Bình thường' },
  { value: 'important', label: 'Quan trọng' },
  { value: 'urgent', label: 'Khẩn (báo ngay Admin / Quản lý)' },
]
const statusLabel: Record<string, string> = { open: 'Mới', following: 'Đang theo dõi', resolved: 'Đã xử lý' }

export function JournalPage() {
  const [filter, setFilter] = useState({ severity: '', status: '', page: 1 })
  const q = useQuery({ queryKey: ['reports', 'journal', filter], queryFn: () => reportsApi.journal(filter) })
  const [message, setMessage] = useState<string | null>(null)
  return (
    <div className="mx-auto max-w-5xl space-y-6">
      <PageHeader title="Nhật ký Học vụ" meta="Ghi nhận và theo dõi các sự việc liên quan đến học vụ." />
      {message && <Alert tone="success">{message}</Alert>}
      <QueryState isPending={q.isPending} error={q.error}>
        {() => (
          <>
            <NewEntry data={q.data!} onDone={setMessage} />
            <div className="flex flex-wrap gap-3">
              <div className="w-56">
                <Select label="Mức độ" value={filter.severity} onChange={(e) => setFilter({ ...filter, severity: e.target.value, page: 1 })}>
                  <option value="">Mọi mức độ</option>
                  {severityOptions.map((o) => (
                    <option key={o.value} value={o.value}>
                      {o.label}
                    </option>
                  ))}
                </Select>
              </div>
              <div className="w-56">
                <Select label="Trạng thái" value={filter.status} onChange={(e) => setFilter({ ...filter, status: e.target.value, page: 1 })}>
                  <option value="">Mọi trạng thái</option>
                  {q.data!.statuses.map((o) => (
                    <option key={o.value} value={o.value}>
                      {o.label}
                    </option>
                  ))}
                </Select>
              </div>
            </div>
            {q.data!.journals.length ? (
              <ol className="space-y-4">
                {q.data!.journals.map((j) => (
                  <Entry key={j.id} j={j} data={q.data!} onDone={setMessage} />
                ))}
              </ol>
            ) : (
              <p className="rounded-xl border border-surface-container-highest bg-surface p-6 text-center text-sm text-on-surface-variant">
                Chưa có sự việc nào được ghi nhận.
              </p>
            )}
            <Pager page={q.data!.page} onPage={(p) => setFilter({ ...filter, page: p })} />
          </>
        )}
      </QueryState>
    </div>
  )
}

function NewEntry({ data, onDone }: { data: JournalData; onDone: (m: string) => void }) {
  const qc = useQueryClient()
  const empty = { subject_user_id: '', kpi_code: '', severity: 'normal', class_id: '', content: '' }
  const [form, setForm] = useState(empty)
  const add = useMutation({
    mutationFn: () =>
      reportsApi.addJournal({
        content: form.content,
        kpi_code: form.kpi_code || null,
        severity: form.severity,
        class_id: form.class_id || null,
        subject_user_id: form.subject_user_id || null,
        report_date: data.today,
      }),
    onSuccess: (r) => {
      setForm(empty)
      onDone(r.message)
      qc.invalidateQueries({ queryKey: ['reports', 'journal'] })
    },
  })
  return (
    <Panel title="Thêm nhật ký mới">
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault()
          add.mutate()
        }}
      >
        {add.isError && <Alert>{errorMessage(add.error)}</Alert>}
        <div className="flex flex-col gap-4 md:flex-row">
          <div className="space-y-3 md:w-1/3">
            {data.subjects.length > 0 && (
              <Select label="SỰ VIỆC CỦA" value={form.subject_user_id} onChange={(e) => setForm({ ...form, subject_user_id: e.target.value })}>
                <option value="">-- Của tôi --</option>
                {data.subjects.map((o) => (
                  <option key={o.value} value={o.value}>
                    {o.label}
                  </option>
                ))}
              </Select>
            )}
            <Select label="GẮN MÃ KPI (TÙY CHỌN)" value={form.kpi_code} onChange={(e) => setForm({ ...form, kpi_code: e.target.value })}>
              <option value="">-- Không chọn --</option>
              {data.kpi_options.map((o) => (
                <option key={o.value} value={o.value}>
                  {o.label}
                </option>
              ))}
            </Select>
            <Select label="MỨC ĐỘ" value={form.severity} onChange={(e) => setForm({ ...form, severity: e.target.value })}>
              {severityOptions.map((o) => (
                <option key={o.value} value={o.value}>
                  {o.label}
                </option>
              ))}
            </Select>
            {data.classes.length > 0 && (
              <Select label="LỚP LIÊN QUAN (TÙY CHỌN)" value={form.class_id} onChange={(e) => setForm({ ...form, class_id: e.target.value })}>
                <option value="">-- Không gắn lớp --</option>
                {data.classes.map((o) => (
                  <option key={o.value} value={o.value}>
                    {o.label}
                  </option>
                ))}
              </Select>
            )}
          </div>
          <div className="md:w-2/3">
            <TextArea label="NỘI DUNG SỰ VIỆC" required rows={data.classes.length ? 8 : 6} placeholder="Nhập chi tiết sự việc..." value={form.content} onChange={(v) => setForm({ ...form, content: v })} />
          </div>
        </div>
        <div className="flex justify-end">
          <Button type="submit" disabled={add.isPending}>
            Thêm nhật ký
          </Button>
        </div>
      </form>
    </Panel>
  )
}

function Entry({ j, data, onDone }: { j: JournalEntry; data: JournalData; onDone: (m: string) => void }) {
  const qc = useQueryClient()
  const [open, setOpen] = useState(false)
  const [followup, setFollowup] = useState('')
  const after = (r: { message: string }) => {
    onDone(r.message)
    qc.invalidateQueries({ queryKey: ['reports', 'journal'] })
  }
  const serious = useMutation({ mutationFn: () => reportsApi.setJournalSerious(j.id, !j.serious), onSuccess: after })
  const status = useMutation({ mutationFn: (s: string) => reportsApi.setJournalStatus(j.id, s), onSuccess: after })
  const add = useMutation({
    mutationFn: () => reportsApi.addFollowup(j.id, followup),
    onSuccess: (r) => {
      setFollowup('')
      after(r)
    },
  })
  const err = serious.error ?? status.error ?? add.error
  return (
    <li>
      <article className={`rounded-xl border p-4 shadow-sm ${j.serious ? 'border-error/40 bg-error-container/30' : 'border-surface-container-highest bg-surface'}`}>
        <div className="mb-2 flex items-start justify-between gap-3">
          <div className="min-w-0">
            <p className="text-xs text-on-surface-variant">{formatDate(j.created_at, 'd/m/Y H:i')}</p>
            <p className="mt-1 text-xs font-medium text-primary uppercase">
              {j.logged_by ? 'Sự việc của' : 'Người ghi'}: {j.user}
              {j.user_role && ` (${j.user_role})`}
            </p>
            {j.logged_by && <p className="text-xs text-on-surface-variant">Ghi bởi {j.logged_by}</p>}
          </div>
          <div className="flex shrink-0 flex-col items-end gap-1">
            {(j.severity === 'urgent' || j.severity === 'important') && <Badge tone={j.severity === 'urgent' ? 'error' : 'info'}>{j.severity_label}</Badge>}
            {data.can_confirm_serious ? (
              <label className="flex cursor-pointer items-center gap-2 text-xs" title="Chỉ Admin mới có quyền đánh dấu lỗi nghiêm trọng">
                <span className={j.serious ? 'text-error' : 'text-on-surface-variant'}>Lỗi nghiêm trọng</span>
                <input
                  type="checkbox"
                  role="switch"
                  className="size-4 accent-error"
                  checked={j.serious}
                  disabled={serious.isPending}
                  aria-label={`Lỗi nghiêm trọng: sự việc ${formatDate(j.report_date)}`}
                  onChange={() => serious.mutate()}
                />
              </label>
            ) : j.serious ? (
              <Badge tone="error">Lỗi nghiêm trọng</Badge>
            ) : (
              <Badge>Chưa xác nhận</Badge>
            )}
            {j.serious && j.serious_by && <span className="text-xs text-error">Xác nhận bởi: {j.serious_by}</span>}
          </div>
        </div>
        {(j.kpi_code || j.class) && (
          <div className="mb-1 flex flex-wrap gap-1 text-xs">
            {j.kpi_code && <span className="rounded border border-outline-variant px-2 py-0.5">KPI: {j.kpi_code}</span>}
            {j.class && <span className="rounded border border-outline-variant px-2 py-0.5 text-primary">Lớp {j.class.code}</span>}
          </div>
        )}
        <p className={`text-sm whitespace-pre-line ${j.serious ? 'font-medium' : ''}`}>{j.content || j.title}</p>
        {err && (
          <div className="mt-2">
            <Alert>{errorMessage(err)}</Alert>
          </div>
        )}
        <div className="mt-3 border-t border-surface-container-highest pt-2">
          <button
            type="button"
            className="flex w-full items-center justify-between text-left text-sm text-on-surface-variant hover:text-on-surface"
            aria-expanded={open}
            onClick={() => setOpen(!open)}
          >
            <span>
              Theo dõi xử lý · <span className="font-semibold">{statusLabel[j.status] ?? 'Mới'}</span>
              {j.followups.length > 0 && ` · ${j.followups.length} follow-up`}
            </span>
            <span aria-hidden="true">{open ? '▴' : '▾'}</span>
          </button>
          {open && (
            <div className="mt-2 space-y-2">
              <div className="w-56">
                <Select label="Trạng thái" value={j.status} disabled={status.isPending} onChange={(e) => status.mutate(e.target.value)}>
                  {data.statuses.map((o) => (
                    <option key={o.value} value={o.value}>
                      {o.label}
                    </option>
                  ))}
                </Select>
              </div>
              {j.followups.length > 0 && (
                <div className="space-y-1 border-l-2 border-surface-container-highest pl-3">
                  {j.followups.map((f) => (
                    <p key={f.id} className="text-xs text-on-surface-variant">
                      <span className="font-semibold text-on-surface">{f.user ?? 'N/A'}:</span> {f.content}{' '}
                      <span>· {formatDate(f.created_at, 'd/m H:i')}</span>
                    </p>
                  ))}
                </div>
              )}
              <form
                className="flex items-center gap-2"
                onSubmit={(e) => {
                  e.preventDefault()
                  add.mutate()
                }}
              >
                <input
                  className="block h-9 w-full rounded-lg border border-outline-variant px-3 text-sm"
                  required
                  maxLength={5000}
                  placeholder="Nhập nội dung follow-up..."
                  aria-label="Nội dung follow-up"
                  value={followup}
                  onChange={(e) => setFollowup(e.target.value)}
                />
                <Button type="submit" variant="secondary" disabled={add.isPending}>
                  Thêm
                </Button>
              </form>
            </div>
          )}
        </div>
      </article>
    </li>
  )
}
