// Tổng hợp báo cáo & nhật ký (staff_report.view_all; Quản lý cơ sở chỉ thấy nhân sự chi nhánh mình): thống kê, lọc,
// kiểm tra từng dòng / hàng loạt, chi tiết (kiểm tra, yêu cầu bổ sung, sang báo cáo kế tiếp), tab "Chưa nộp báo cáo ngày".
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState, type ReactNode } from 'react'
import { errorMessage } from '../../api'
import { Alert, Badge, Button, Field, Select } from '../../components/ui'
import { reportsApi, type AllFilters, type AllPage as AllData, type ReportDetail } from './api'
import { KpiTable, MetricCard, PageHeader, Pager, Panel, ReadonlyField, TextArea, WideDialog } from './components'
import { formatDate, readQuery, reviewBadge, selectableIds, writeQuery } from './logic'
import { QueryState } from './shared'

const reviewOptions = [
  { value: 'pending', label: 'Chờ kiểm tra' },
  { value: 'returned', label: 'Đã yêu cầu bổ sung' },
  { value: 'done', label: 'Đã kiểm tra' },
]

const FILTER_KEYS = ['view', 'type', 'review', 'user_id', 'branch_id', 'from', 'to'] as const

function initialFilters(): AllFilters {
  const f: AllFilters = {}
  for (const k of FILTER_KEYS) {
    const v = readQuery(k)
    if (v) f[k] = v
  }
  // Link cũ ?date= = lọc đúng một ngày.
  const date = readQuery('date')
  if (date) {
    f.from ??= date
    f.to ??= date
  }
  return f
}

export function AllPage() {
  const qc = useQueryClient()
  const [filters, setFilters] = useState<AllFilters>(initialFilters)
  const [openId, setOpenId] = useState(() => readQuery('open'))
  const [selected, setSelected] = useState<string[]>([])
  const [message, setMessage] = useState<string | null>(null)
  const q = useQuery({ queryKey: ['reports', 'all', filters], queryFn: () => reportsApi.all(filters) })
  const apply = (f: AllFilters) => {
    setFilters(f)
    setSelected([])
    writeQuery({ view: f.view, type: f.type, review: f.review, user_id: f.user_id, branch_id: f.branch_id, from: f.from, to: f.to, page: f.page, date: null })
  }
  const open = (id: string | null) => {
    setOpenId(id ?? '')
    writeQuery({ open: id })
  }
  const after = (r: { message: string }) => {
    setMessage(r.message)
    setSelected([])
    qc.invalidateQueries({ queryKey: ['reports'] })
  }
  const review = useMutation({ mutationFn: ({ id, reviewed }: { id: string; reviewed: boolean }) => reportsApi.review(id, reviewed), onSuccess: after })
  const bulk = useMutation({ mutationFn: () => reportsApi.reviewBulk(selected), onSuccess: after })

  return (
    <div>
      <PageHeader title="Tổng hợp Báo cáo & Nhật ký" />
      {message && (
        <div className="mb-4">
          <Alert tone="success">{message}</Alert>
        </div>
      )}
      {(review.error || bulk.error) && (
        <div className="mb-4">
          <Alert>{errorMessage(review.error ?? bulk.error)}</Alert>
        </div>
      )}
      <QueryState isPending={q.isPending} error={q.error}>
        {() => (
          <AllView
            data={q.data!}
            filters={filters}
            apply={apply}
            selected={selected}
            setSelected={setSelected}
            onOpen={open}
            onReview={(id, reviewed) => review.mutate({ id, reviewed })}
            onBulk={() => bulk.mutate()}
          />
        )}
      </QueryState>
      {(openId || q.data?.open_id) && <DetailDialog id={openId || q.data!.open_id!} onOpen={open} onDone={after} />}
    </div>
  )
}

function Stat({ label, value, tone, onClick }: { label: string; value: number; tone?: 'error' | 'warning'; onClick?: () => void }) {
  const color = tone === 'error' && value ? 'text-error' : tone === 'warning' && value ? 'text-info' : ''
  const body = (
    <>
      <p className="text-xs text-on-surface-variant">{label}</p>
      <p className={`text-2xl font-semibold ${color}`}>{value}</p>
    </>
  )
  const cls = 'rounded-xl border border-surface-container-highest bg-surface p-3 text-left shadow-sm'
  return onClick ? (
    <button type="button" className={`${cls} hover:bg-surface-container-low`} onClick={onClick}>
      {body}
    </button>
  ) : (
    <div className={cls}>{body}</div>
  )
}

function AllView({
  data,
  filters,
  apply,
  selected,
  setSelected,
  onOpen,
  onReview,
  onBulk,
}: {
  data: AllData
  filters: AllFilters
  apply: (f: AllFilters) => void
  selected: string[]
  setSelected: (ids: string[]) => void
  onOpen: (id: string) => void
  onReview: (id: string, reviewed: boolean) => void
  onBulk: () => void
}) {
  const [draft, setDraft] = useState<AllFilters>(filters)
  const s = data.stats
  const selectable = selectableIds(data.reports)
  const allSelected = selectable.length > 0 && selectable.every((id) => selected.includes(id))
  const missing = data.view === 'missing'
  const set = (k: keyof AllFilters) => (e: { target: { value: string } }) => setDraft({ ...draft, [k]: e.target.value || undefined })
  const keepScope = { user_id: filters.user_id, branch_id: filters.branch_id }

  return (
    <div className="space-y-6">
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-8">
        <Stat label="Chờ kiểm tra" value={s.pending_review} tone="warning" onClick={() => apply({ review: 'pending' })} />
        <Stat label="Đã yêu cầu bổ sung" value={s.returned} onClick={() => apply({ review: 'returned' })} />
        <Stat label="Hôm qua chưa nộp" value={s.missing_yesterday} tone="error" onClick={() => apply({ view: 'missing' })} />
        <Stat label="Nhật ký" value={s.journal} />
        <Stat label="BC Ngày" value={s.daily} />
        <Stat label="BC Tuần" value={s.weekly} />
        <Stat label="BC Tháng" value={s.monthly} />
        <Stat label="Sự vụ khẩn chưa xử lý" value={s.urgent_open} tone="error" />
      </div>

      <nav className="flex gap-2 border-b border-surface-container-highest" aria-label="Chế độ xem">
        {[
          { key: 'list', label: 'Báo cáo đã nộp' },
          { key: 'missing', label: 'Chưa nộp báo cáo ngày' },
        ].map((t) => (
          <button
            key={t.key}
            type="button"
            aria-current={data.view === t.key ? 'page' : undefined}
            className="-mb-px border-b-2 border-transparent px-3 py-2 text-sm font-semibold text-on-surface-variant hover:text-on-surface aria-[current=page]:border-primary aria-[current=page]:text-primary"
            onClick={() => {
              const f = { ...keepScope, view: t.key === 'missing' ? 'missing' : undefined }
              setDraft(f)
              apply(f)
            }}
          >
            {t.label}
          </button>
        ))}
      </nav>

      <form
        className="grid grid-cols-1 gap-3 rounded-xl border border-surface-container-highest bg-surface p-4 shadow-sm sm:grid-cols-2 lg:grid-cols-4"
        onSubmit={(e) => {
          e.preventDefault()
          apply({ ...draft, view: missing ? 'missing' : undefined, page: undefined })
        }}
      >
        {!missing && (
          <>
            <Select label="Loại báo cáo" value={draft.type ?? ''} onChange={set('type')}>
              <option value="">Tất cả loại</option>
              {data.types.map((o) => (
                <option key={o.value} value={o.value}>
                  {o.label}
                </option>
              ))}
            </Select>
            <Select label="Trạng thái kiểm tra" value={draft.review ?? ''} onChange={set('review')}>
              <option value="">Mọi trạng thái kiểm tra</option>
              {reviewOptions.map((o) => (
                <option key={o.value} value={o.value}>
                  {o.label}
                </option>
              ))}
            </Select>
          </>
        )}
        <Select label="Người viết" value={draft.user_id ?? ''} onChange={set('user_id')}>
          <option value="">Mọi người viết</option>
          {data.users.map((o) => (
            <option key={o.value} value={o.value}>
              {o.label}
            </option>
          ))}
        </Select>
        {data.branches.length > 1 && (
          <Select label="Chi nhánh" value={draft.branch_id ?? ''} onChange={set('branch_id')}>
            <option value="">Mọi chi nhánh</option>
            {data.branches.map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </Select>
        )}
        <Field label="Từ ngày" type="date" value={draft.from ?? data.missing?.from ?? ''} onChange={set('from')} />
        <Field label="Đến ngày" type="date" value={draft.to ?? data.missing?.to ?? ''} onChange={set('to')} />
        <div className="flex items-end gap-2 sm:col-span-2">
          <Button type="submit">Lọc</Button>
          <Button
            type="button"
            variant="secondary"
            onClick={() => {
              const f = { view: missing ? 'missing' : undefined }
              setDraft(f)
              apply(f)
            }}
          >
            Xóa lọc
          </Button>
        </div>
      </form>

      {missing && data.missing ? (
        <Panel>
          <p className="text-xs text-on-surface-variant">
            Báo cáo ngày còn thiếu từ {formatDate(data.missing.from)} đến {formatDate(data.missing.to)}. Không tính Chủ nhật, ngày lễ và ngày nghỉ phép đã duyệt; hạn nộp 09:00 sáng hôm sau.
          </p>
          <ul className="divide-y divide-surface-container-highest">
            {data.missing.rows.map((row) => (
              <li key={row.user_id} className="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                  <p className="text-sm font-bold">{row.user}</p>
                  <p className="text-xs text-on-surface-variant">
                    {row.for}
                    {row.branch && ` · ${row.branch}`} · thiếu {row.days.length} ngày
                  </p>
                </div>
                <div className="flex flex-wrap gap-1.5">
                  {row.days.map((d) => (
                    <Badge key={d.date} tone="error">
                      {d.label}
                    </Badge>
                  ))}
                </div>
              </li>
            ))}
          </ul>
          {!data.missing.rows.length && <p className="text-center text-sm text-on-surface-variant">Mọi người đã nộp đủ báo cáo ngày trong khoảng này.</p>}
        </Panel>
      ) : (
        <>
          {data.can_review && selectable.length > 0 && (
            <div className="flex flex-wrap items-center gap-3 rounded-xl bg-surface-container-low px-4 py-2 text-sm">
              <label className="flex cursor-pointer items-center gap-2 font-semibold">
                <input type="checkbox" className="size-4 accent-primary" checked={allSelected} onChange={() => setSelected(allSelected ? [] : selectable)} />
                Chọn tất cả báo cáo chờ kiểm tra trên trang
              </label>
              {selected.length > 0 && <Button onClick={onBulk}>Đánh dấu đã kiểm tra ({selected.length})</Button>}
            </div>
          )}
          <div className="divide-y divide-surface-container-highest rounded-xl border border-surface-container-highest bg-surface shadow-sm">
            {data.reports.map((r) => {
              const badge = reviewBadge(r)
              const pick = data.can_review && selectable.includes(r.id)
              return (
                <div key={r.id} className="flex gap-3 p-4">
                  {pick ? (
                    <input
                      type="checkbox"
                      className="mt-1 size-4 shrink-0 accent-primary"
                      aria-label={`Chọn ${r.title}`}
                      checked={selected.includes(r.id)}
                      onChange={(e) => setSelected(e.target.checked ? [...selected, r.id] : selected.filter((x) => x !== r.id))}
                    />
                  ) : (
                    data.can_review && selectable.length > 0 && <span className="w-4 shrink-0" aria-hidden="true" />
                  )}
                  <div className="flex min-w-0 flex-1 flex-col justify-between gap-3 sm:flex-row sm:items-start">
                    <div className="min-w-0 flex-1">
                      <div className="flex flex-wrap items-center gap-2">
                        <Badge tone="info">
                          {r.type_label}
                          {r.for && ` · ${r.for}`}
                        </Badge>
                        {r.type === 'journal' ? <Badge>{r.severity_label}</Badge> : badge && <Badge tone={badge.tone}>{badge.label}</Badge>}
                        {r.late && <Badge tone="error">Trễ</Badge>}
                        <button type="button" className="text-left text-sm font-bold hover:text-primary" onClick={() => onOpen(r.id)}>
                          {r.title}
                        </button>
                      </div>
                      <p className="mt-1 text-xs text-on-surface-variant">
                        {formatDate(r.report_date)} · <span className="font-semibold">{r.user}</span>
                        {r.branch && ` · ${r.branch}`}
                        {r.reviewable && ` · nộp lúc ${formatDate(r.submitted_at, 'H:i d/m')}`}
                        {r.reviewable && r.updated_at && ` · sửa lúc ${formatDate(r.updated_at, 'H:i d/m')}`}
                        {r.followups_count > 0 && ` · ${r.followups_count} follow-up`}
                        {r.reviewed_at && ` · ${r.reviewed_by ?? 'Admin'} kiểm tra lúc ${formatDate(r.reviewed_at, 'H:i d/m')}`}
                      </p>
                      {r.preview && <p className="mt-1.5 line-clamp-2 text-sm text-on-surface-variant">{r.preview}</p>}
                      {r.status === 'returned' && r.review_note && <p className="mt-1 line-clamp-2 text-xs text-error">Đã yêu cầu: {r.review_note}</p>}
                    </div>
                    <div className="flex shrink-0 flex-wrap gap-2">
                      <Button variant="secondary" onClick={() => onOpen(r.id)}>
                        Chi tiết
                      </Button>
                      {data.can_review && r.reviewable && !r.reviewed_at && r.status === 'submitted' && <Button onClick={() => onReview(r.id, true)}>Đã kiểm tra</Button>}
                      {data.can_review && r.reviewable && r.reviewed_at && (
                        <Button variant="secondary" onClick={() => onReview(r.id, false)}>
                          Bỏ đánh dấu
                        </Button>
                      )}
                    </div>
                  </div>
                </div>
              )
            })}
            {!data.reports.length && <p className="p-6 text-center text-sm text-on-surface-variant">Không có báo cáo nào khớp bộ lọc.</p>}
          </div>
          <Pager page={data.page} onPage={(p) => apply({ ...filters, page: p })} />
        </>
      )}
    </div>
  )
}

// ───────────── Chi tiết một báo cáo ─────────────

function DetailDialog({ id, onOpen, onDone }: { id: string; onOpen: (id: string | null) => void; onDone: (r: { message: string }) => void }) {
  const q = useQuery({ queryKey: ['reports', 'detail', id], queryFn: () => reportsApi.detail(id) })
  const [asking, setAsking] = useState(false)
  const [note, setNote] = useState('')
  const review = useMutation({
    mutationFn: () => reportsApi.review(id, true),
    onSuccess: (r) => {
      onDone(r)
      onOpen(q.data?.next_id ?? null)
    },
  })
  const ask = useMutation({
    mutationFn: () => reportsApi.requestChanges(id, note),
    onSuccess: (r) => {
      setAsking(false)
      setNote('')
      onDone(r)
    },
  })
  const d = q.data
  const footer: ReactNode = d && (
    <>
      {d.can_review && d.report.reviewable && !asking && d.report.status !== 'returned' && (
        <Button variant="secondary" onClick={() => setAsking(true)}>
          Yêu cầu bổ sung
        </Button>
      )}
      {d.can_review && d.report.reviewable && !d.report.reviewed_at && (
        <Button onClick={() => review.mutate()} disabled={review.isPending}>
          {d.next_id ? 'Đã kiểm tra, sang báo cáo kế tiếp' : 'Đã kiểm tra'}
        </Button>
      )}
      {d.next_id && (
        <Button variant="secondary" onClick={() => onOpen(d.next_id)}>
          Kế tiếp
        </Button>
      )}
    </>
  )
  return (
    <WideDialog title={d?.report.title ?? 'Chi tiết báo cáo'} onClose={() => onOpen(null)} footer={footer}>
      <QueryState isPending={q.isPending} error={q.error}>
        {() => (
          <div className="space-y-5">
            {(review.error || ask.error) && <Alert>{errorMessage(review.error ?? ask.error)}</Alert>}
            {asking && (
              <form
                className="space-y-2 rounded-xl border border-outline-variant bg-surface-container-low p-3"
                onSubmit={(e) => {
                  e.preventDefault()
                  ask.mutate()
                }}
              >
                <TextArea label="Cần bổ sung gì?" required rows={3} maxLength={2000} value={note} onChange={setNote} placeholder="Vd: Thiếu ghi chú lớp K26 nghỉ đột xuất, bổ sung lý do và hướng xử lý." />
                <div className="flex justify-end gap-2">
                  <Button type="button" variant="secondary" onClick={() => setAsking(false)}>
                    Thôi
                  </Button>
                  <Button type="submit" disabled={ask.isPending}>
                    Gửi cho {d!.report.user}
                  </Button>
                </div>
              </form>
            )}
            <DetailBody d={d!} />
          </div>
        )}
      </QueryState>
    </WideDialog>
  )
}

export function DetailBody({ d }: { d: ReportDetail }) {
  const r = d.report
  const badge = reviewBadge({ type: r.reviewable ? 'report' : 'journal', status: r.status, reviewed_at: r.reviewed_at })
  return (
    <>
      <div className="flex flex-wrap items-center gap-2 text-sm">
        <Badge tone="info">
          {r.type_label}
          {r.for && ` · ${r.for}`}
        </Badge>
        {r.severity_label ? <Badge>{r.severity_label}</Badge> : badge && <Badge tone={badge.tone}>{badge.label}</Badge>}
        {r.late && <Badge tone="error">Trễ</Badge>}
        <span className="text-on-surface-variant">
          {formatDate(r.report_date)} · <strong className="text-on-surface">{r.user}</strong> · nộp lúc {formatDate(r.submitted_at, 'H:i d/m')}
          {r.updated_at !== r.submitted_at && ` · sửa lúc ${formatDate(r.updated_at, 'H:i d/m')}`}
          {r.reviewed_at && ` · ${r.reviewed_by ?? 'Admin'} kiểm tra lúc ${formatDate(r.reviewed_at, 'H:i d/m')}`}
        </span>
      </div>
      {r.status === 'returned' && r.review_note && (
        <Alert>
          <strong>Đã yêu cầu bổ sung</strong>
          <p className="whitespace-pre-line">{r.review_note}</p>
        </Alert>
      )}
      {d.auto?.computed_at && (
        <p className="text-sm text-on-surface-variant">
          Số liệu hệ thống tính lúc {formatDate(d.auto.computed_at, 'H:i d/m/Y')} {d.view === 'daily_legacy' ? '(tính lại khi mở chi tiết).' : '(lúc người viết nộp).'}
        </p>
      )}
      {(d.view === 'daily' || d.view === 'daily_legacy') && (
        <>
          {d.view === 'daily_legacy' && (
            <Alert tone="info">
              Báo cáo này nộp trước khi chuyển sang số liệu tự động. Số liệu và chi tiết bên dưới được hệ thống tính lại cho ngày {formatDate(r.report_date)} như màn Học vụ đang thấy; khung "Nhập tay lúc nộp" là các ô người viết đã nhập.
            </Alert>
          )}
          {(d.auto?.sections ?? []).map((s) => (
            <Panel key={s.key} title={s.title}>
              {!s.items.length && s.empty && <p className="text-sm text-on-surface-variant">{s.empty}</p>}
              <div className="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
                {s.items.map((item) => (
                  <MetricCard key={item.key} item={item} full />
                ))}
              </div>
              {d.view === 'daily' && <ReadonlyField label={d.note_labels[s.key] ?? 'Ghi chú'} value={d.notes[s.key]} multiline />}
            </Panel>
          ))}
          {d.view === 'daily_legacy' && d.legacy?.fields && (
            <Panel title="Nhập tay lúc nộp">
              <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                {d.legacy.fields.map((f) => (
                  <ReadonlyField key={f.label} label={f.label} value={f.value === '—' ? null : f.value} multiline />
                ))}
              </div>
            </Panel>
          )}
          <Panel title="Task follow-up">
            {d.tasks.map((t) => (
              <div key={t.id} className="grid grid-cols-1 gap-3 md:grid-cols-4">
                <ReadonlyField label="Việc đã tạo" value={t.title} />
                <ReadonlyField label="Người phụ trách (PIC)" value={t.assignee} />
                <ReadonlyField label="Hạn xử lý" value={t.due_date ? formatDate(t.due_date) : null} />
                <ReadonlyField label="Trạng thái" value={t.status_label} />
              </div>
            ))}
            {!d.tasks.length && <p className="text-sm text-on-surface-variant italic">Không tạo task.</p>}
          </Panel>
        </>
      )}
      {d.view === 'weekly_kpi' && d.auto && (
        <>
          <div className="grid grid-cols-1 gap-4 lg:grid-cols-[1fr_340px]">
            <Panel title="Số lần phát sinh theo mục KPI" className="min-w-0">
              <KpiTable groups={d.auto.groups ?? []} />
            </Panel>
            <Panel title="Thông tin theo dõi" className="min-w-0">
              {(d.auto.metrics?.items ?? []).map((item) => (
                <MetricCard key={item.key} item={item} full />
              ))}
            </Panel>
          </div>
          <Panel title="Ghi chú tuần">
            {d.auto.daily && (
              <p className="text-sm text-on-surface-variant">
                Báo cáo ngày đã nộp {d.auto.daily.submitted}/{d.auto.daily.expected} ngày
                {d.auto.daily.missing.length > 0 && ` · còn thiếu ${d.auto.daily.missing.join(', ')}`}.
              </p>
            )}
            <ReadonlyField label="Ghi chú tuần" value={d.note} multiline />
          </Panel>
        </>
      )}
      {d.view === 'weekly_legacy' && d.legacy && (
        <>
          <Alert tone="info">Báo cáo nộp trước khi chuyển sang số liệu tự động: các số dưới đây do người viết nhập tay.</Alert>
          <div className="grid grid-cols-1 gap-4 lg:grid-cols-[1fr_340px]">
            <Panel title="Số lần phát sinh theo mục KPI" className="min-w-0">
              {(d.legacy.groups ?? []).length ? (
                <KpiTable groups={d.legacy.groups ?? []} />
              ) : (
                <p className="text-sm text-on-surface-variant italic">Không có mục KPI nào trong báo cáo.</p>
              )}
            </Panel>
            <Panel title="Thông tin theo dõi" className="min-w-0">
              <p className="text-sm text-on-surface-variant">(Chỉ số quy mô, không tính KPI)</p>
              {(d.legacy.metrics ?? []).map((m) => (
                <div key={m.label} className="flex items-center justify-between gap-2 text-sm">
                  <span>{m.label}</span>
                  <span className="font-semibold">{m.value}</span>
                </div>
              ))}
            </Panel>
          </div>
        </>
      )}
      {d.view === 'academic_weekly' &&
        d.sections.map((sec) => (
          <Panel key={sec.value} title={<span className="text-primary">{sec.label}</span>}>
            {d.rows[sec.value]?.length ? (
              <div className="overflow-x-auto">
                <table className="w-full min-w-[1000px] text-left text-sm">
                  <thead className="text-xs text-on-surface-variant">
                    <tr>
                      <th className="py-1 pr-2 font-medium">STT</th>
                      {d.columns.map((c) => (
                        <th key={c.value} className="py-1 pr-2 font-medium">
                          {c.label}
                        </th>
                      ))}
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-surface-container-highest">
                    {d.rows[sec.value].map((row, i) => (
                      <tr key={i} className="align-top">
                        <td className="py-1 pr-2 font-mono text-on-surface-variant">{i + 1}</td>
                        {d.columns.map((c) => {
                          const v = row[c.value as keyof typeof row]
                          let content: ReactNode = v ?? '—'
                          if (c.value === 'progress') content = row.progress === null ? '—' : `${row.progress}%`
                          if (c.value === 'deadline') content = row.deadline ? formatDate(row.deadline) : '—'
                          if (c.value === 'link' && row.link)
                            content = (
                              <a href={row.link} target="_blank" rel="noopener noreferrer" className="text-primary hover:underline">
                                Mở link
                              </a>
                            )
                          return (
                            <td key={c.value} className="py-1 pr-2 whitespace-pre-line">
                              {content}
                            </td>
                          )
                        })}
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            ) : (
              <p className="text-sm text-on-surface-variant italic">Không có dòng nào.</p>
            )}
          </Panel>
        ))}
      {(d.view === 'journal' || d.view === 'text') && (
        <>
          {d.view === 'journal' && (r.class || r.kpi_code) && (
            <dl className="grid grid-cols-2 gap-3 text-sm">
              {r.class && (
                <div>
                  <dt className="text-xs text-on-surface-variant uppercase">Lớp</dt>
                  <dd>{r.class}</dd>
                </div>
              )}
              {r.kpi_code && (
                <div>
                  <dt className="text-xs text-on-surface-variant uppercase">Mã KPI</dt>
                  <dd>{r.kpi_code}</dd>
                </div>
              )}
            </dl>
          )}
          <Panel>
            <p className="text-sm whitespace-pre-line">{r.content || 'Không có nội dung.'}</p>
          </Panel>
        </>
      )}
      {r.followups.length > 0 && (
        <Panel title="Follow-up">
          <ul className="space-y-2">
            {r.followups.map((f, i) => (
              <li key={i} className="text-sm">
                <strong>{f.user}</strong> <span className="text-on-surface-variant">· {formatDate(f.created_at, 'H:i d/m')}</span>
                <p className="whitespace-pre-line">{f.content}</p>
              </li>
            ))}
          </ul>
        </Panel>
      )}
    </>
  )
}
