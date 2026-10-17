// Báo cáo tuần: Học vụ (bảng KPI + chỉ số quy mô + ghi chú tuần) và Học thuật (3 bảng nhập theo dòng).
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Alert, Badge, Button, Select } from '../../components/ui'
import { reportsApi, type AcademicWeeklyForm, type WeeklyKpiForm } from './api'
import { HistoryList, KpiTable, LegacyList, MetricCard, PageHeader, Panel, ReportTabs, ReviewState, TextArea, useNoteDraft } from './components'
import { blankRow, formatDate, initialLines, missingSuggestions, rowsPayload, toLine, type Line } from './logic'
import { QueryState, SaveResult, usePeriod } from './shared'

function WeekSelect({ value, options, onChange, label }: { value: string; options: { value: string; label: string }[]; onChange: (v: string) => void; label: string }) {
  return (
    <div className="w-80">
      <Select label={label} value={value} onChange={(e) => onChange(e.target.value)}>
        {options.map((o) => (
          <option key={o.value} value={o.value}>
            {o.label}
          </option>
        ))}
      </Select>
    </div>
  )
}

// ═════════════ Báo cáo tuần Học vụ (KPI) ═════════════

export function WeeklyKpiPage() {
  const [week, setWeek] = usePeriod('week')
  const [refresh, setRefresh] = useState(false)
  const q = useQuery({
    queryKey: ['reports', 'weekly-kpi', week, refresh],
    queryFn: () => reportsApi.weeklyKpi(week || undefined, refresh),
    placeholderData: keepPreviousData,
  })
  const pick = (w: string) => {
    setRefresh(false)
    setWeek(w)
  }
  return (
    <QueryState isPending={q.isPending} error={q.error}>
      {() => <WeeklyKpiView key={q.data!.week} form={q.data!} onPick={pick} onRefresh={() => setRefresh(true)} refreshing={q.isFetching} onSaved={() => setRefresh(false)} />}
    </QueryState>
  )
}

function WeeklyKpiView({
  form,
  onPick,
  onRefresh,
  refreshing,
  onSaved,
}: {
  form: WeeklyKpiForm
  onPick: (w: string) => void
  onRefresh: () => void
  refreshing: boolean
  onSaved: () => void
}) {
  const qc = useQueryClient()
  const draft = useNoteDraft(`report-draft:weekly-kpi:${form.week}`, { note: form.note ?? '' })
  const [message, setMessage] = useState<string | null>(null)
  const save = useMutation({
    mutationFn: () => reportsApi.saveWeeklyKpi({ week: form.week, note: draft.notes.note || null, refresh: form.live && !!form.submitted_at }),
    onSuccess: (r) => {
      setMessage(r.message)
      draft.clear()
      onSaved()
      qc.invalidateQueries({ queryKey: ['reports', 'weekly-kpi'] })
    },
  })
  const daily = form.daily

  return (
    <div>
      <PageHeader
        title="Báo cáo tuần"
        meta="Số liệu lấy tự động từ hệ thống, bạn chỉ cần ghi chú."
        actions={<WeekSelect label="Tuần báo cáo" value={form.week} options={form.weeks} onChange={onPick} />}
      />
      <ReportTabs tabs={form.tabs} current="weekly_kpi" />
      <form
        className="space-y-6"
        onSubmit={(e) => {
          e.preventDefault()
          setMessage(null)
          save.mutate()
        }}
      >
        <SaveResult message={message} error={save.error} />
        <ReviewState review={form.review} />
        {form.submitted_at && (
          <Alert tone="info">
            Đã nộp tuần này lúc {formatDate(form.submitted_at, 'H:i d/m/Y')}.{' '}
            {form.live
              ? 'Số liệu bên dưới vừa được tính lại, bấm Lưu để cập nhật báo cáo bằng số mới.'
              : 'Số liệu bên dưới là số lúc nộp, sửa ghi chú không làm đổi số.'}
          </Alert>
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
        {form.legacy.length > 0 && (
          <Panel title="Số liệu đã nhập tay (bản cũ)">
            <p className="text-sm text-on-surface-variant">Báo cáo này nộp trước khi chuyển sang số liệu tự động. Lưu lại sẽ thay bằng số liệu hệ thống.</p>
            <dl className="grid grid-cols-1 gap-3 md:grid-cols-2">
              {form.legacy.map((f) => (
                <div key={f.label}>
                  <dt className="text-xs text-on-surface-variant uppercase">{f.label}</dt>
                  <dd className="text-sm">{f.value}</dd>
                </div>
              ))}
            </dl>
          </Panel>
        )}
        <div className="grid grid-cols-1 gap-6 lg:grid-cols-[1fr_380px]">
          <Panel title="Số lần phát sinh theo mục KPI" className="min-w-0">
            <KpiTable groups={form.groups} />
          </Panel>
          <Panel title="Thông tin theo dõi" className="min-w-0">
            <p className="text-sm text-on-surface-variant">(Chỉ số quy mô, không tính KPI)</p>
            <div className="space-y-2">
              {form.metrics.items.map((item) => (
                <MetricCard key={item.key} item={item} />
              ))}
            </div>
          </Panel>
        </div>
        <Panel title="Ghi chú tuần">
          <p className="text-sm text-on-surface-variant">
            Báo cáo ngày đã nộp {daily.submitted}/{daily.expected} ngày{daily.missing.length > 0 && ` · còn thiếu ${daily.missing.join(', ')}`}.
          </p>
          {daily.notes.length > 0 && (
            <div className="grid grid-cols-1 gap-3 md:grid-cols-3">
              {daily.notes.map((n) => (
                <div key={n.key} className="rounded-lg border border-surface-container-highest bg-surface-container-low p-3">
                  <p className="mb-1 text-xs text-on-surface-variant uppercase">{n.label}</p>
                  <ul className="space-y-1">
                    {n.lines.map((l, i) => (
                      <li key={i} className="text-sm whitespace-pre-line">
                        {l.text}
                      </li>
                    ))}
                  </ul>
                </div>
              ))}
            </div>
          )}
          <TextArea
            label="Ghi chú tuần"
            value={draft.notes.note ?? ''}
            maxLength={5000}
            onChange={(v) => draft.setNotes({ note: v })}
            placeholder="Tổng kết tuần, việc cần lưu ý tuần tới..."
          />
        </Panel>
        <div className="flex justify-end">
          <Button type="submit" disabled={save.isPending}>
            Lưu báo cáo tuần
          </Button>
        </div>
      </form>
      <div className="mt-6">
        <HistoryList items={form.history} current={form.week} title="Các tuần đã nộp" onPick={onPick} />
      </div>
    </div>
  )
}

// ═════════════ Báo cáo tuần Học thuật ═════════════

export function AcademicWeeklyPage() {
  const [week, setWeek] = usePeriod('week')
  const q = useQuery({ queryKey: ['reports', 'academic-weekly', week], queryFn: () => reportsApi.academicWeekly(week || undefined) })
  return <QueryState isPending={q.isPending} error={q.error}>{() => <AcademicWeeklyView key={q.data!.week} form={q.data!} onPick={setWeek} />}</QueryState>
}

const cell = 'block w-full rounded-lg border border-outline-variant px-2 py-1.5 text-sm outline-none focus:border-primary focus:ring-2 focus:ring-primary/25'

function AcademicWeeklyView({ form, onPick }: { form: AcademicWeeklyForm; onPick: (w: string) => void }) {
  const qc = useQueryClient()
  const keys = form.sections.map((s) => s.value)
  const [lines, setLines] = useState(() => initialLines(keys, form.rows, form.suggestions))
  const [message, setMessage] = useState<string | null>(null)
  const col = Object.fromEntries(form.columns.map((c) => [c.value, c.label]))
  const save = useMutation({
    mutationFn: () => reportsApi.saveAcademicWeekly({ week: form.week, rows: rowsPayload(lines) }),
    onSuccess: (r) => {
      setMessage(r.message)
      qc.invalidateQueries({ queryKey: ['reports', 'academic-weekly'] })
    },
  })
  const update = (key: string, rowUid: number, patch: Partial<Line>) =>
    setLines({ ...lines, [key]: lines[key].map((r) => (r.uid === rowUid ? { ...r, ...patch } : r)) })
  const text = (key: string, r: Line, field: 'content' | 'detail' | 'link' | 'report' | 'support' | 'note' | 'deadline') => ({
    value: r[field] ?? '',
    onChange: (e: { target: { value: string } }) => update(key, r.uid, { [field]: e.target.value || null }),
  })

  return (
    <div>
      <PageHeader title="Báo cáo tuần — Học thuật" meta="Công việc đã làm, kế hoạch tuần tới và các yêu cầu đang quản lý" />
      <ReportTabs tabs={form.tabs} current="academic_weekly" />
      <div className="mb-6 space-y-4">
        <ReviewState review={form.review} />
        <WeekSelect label="Khoảng ngày báo cáo (Tuần)" value={form.week} options={form.weeks} onChange={onPick} />
      </div>
      <form
        className="space-y-6"
        onSubmit={(e) => {
          e.preventDefault()
          setMessage(null)
          save.mutate()
        }}
      >
        <SaveResult message={message} error={save.error} />
        {form.submitted_at && <Alert tone="info">Đã nộp tuần này lúc {formatDate(form.submitted_at, 'H:i d/m/Y')}. Lưu lại để cập nhật.</Alert>}
        {form.summary.items.length > 0 && (
          <Panel title="Số liệu tuần">
            <p className="text-sm text-on-surface-variant">Tính lúc {formatDate(form.computed_at, 'H:i d/m/Y')}</p>
            <div className="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
              {form.summary.items.map((item) => (
                <MetricCard key={item.key} item={item} />
              ))}
            </div>
          </Panel>
        )}
        {form.sections.map((sec) => {
          const rows = lines[sec.value] ?? []
          const missing = missingSuggestions(rows, form.suggestions[sec.value])
          return (
            <Panel key={sec.value} title={<span className="text-primary">{sec.label}</span>}>
              <div className="overflow-x-auto">
                <table className="w-full min-w-[1400px] text-left text-sm">
                  <thead className="text-xs text-on-surface-variant">
                    <tr>
                      <th className="w-10 py-2 pr-2 font-medium">STT</th>
                      <th className="py-2 pr-2 font-medium">
                        {col.content} <span className="text-error">*</span>
                      </th>
                      <th className="py-2 pr-2 font-medium">{col.detail}</th>
                      <th className="py-2 pr-2 font-medium">{col.deadline}</th>
                      <th className="py-2 pr-2 font-medium">{col.progress}</th>
                      <th className="py-2 pr-2 font-medium">{col.link}</th>
                      <th className="py-2 pr-2 font-medium">{col.report}</th>
                      <th className="py-2 pr-2 font-medium">{col.support}</th>
                      <th className="py-2 pr-2 font-medium">{col.note}</th>
                      <th className="py-2 font-medium">Thao tác</th>
                    </tr>
                  </thead>
                  <tbody>
                    {rows.map((r, i) => (
                      <tr key={r.uid} className="align-top">
                        <td className="py-1 pr-2 pt-3 font-mono text-on-surface-variant">{i + 1}</td>
                        <td className="min-w-[220px] py-1 pr-2">
                          <input className={cell} placeholder="Nhập nội dung" maxLength={500} aria-label={`${sec.label}: nội dung dòng ${i + 1}`} {...text(sec.value, r, 'content')} />
                          {r.source && (
                            <span className="mt-1 inline-block">
                              <Badge tone="info">Từ hệ thống</Badge>
                            </span>
                          )}
                        </td>
                        <td className="min-w-[220px] py-1 pr-2">
                          <textarea className={cell} rows={2} placeholder="Chi tiết..." aria-label={`Chi tiết dòng ${i + 1}`} {...text(sec.value, r, 'detail')} />
                        </td>
                        <td className="min-w-[150px] py-1 pr-2">
                          <input className={cell} type="date" aria-label={`Deadline dòng ${i + 1}`} {...text(sec.value, r, 'deadline')} />
                        </td>
                        <td className="min-w-[130px] py-1 pr-2">
                          <select
                            className={cell}
                            aria-label={`Tiến độ dòng ${i + 1}`}
                            value={r.progress ?? ''}
                            onChange={(e) => update(sec.value, r.uid, { progress: e.target.value === '' ? null : Number(e.target.value) })}
                          >
                            <option value="">Chọn tiến độ</option>
                            {form.progress_options.map((p) => (
                              <option key={p} value={p}>
                                {p}%
                              </option>
                            ))}
                          </select>
                        </td>
                        <td className="min-w-[180px] py-1 pr-2">
                          <input className={cell} type="url" placeholder="https://..." aria-label={`Link sản phẩm dòng ${i + 1}`} {...text(sec.value, r, 'link')} />
                        </td>
                        <td className="min-w-[160px] py-1 pr-2">
                          <input className={cell} placeholder="..." aria-label={`Báo cáo dòng ${i + 1}`} {...text(sec.value, r, 'report')} />
                        </td>
                        <td className="min-w-[160px] py-1 pr-2">
                          <input className={cell} placeholder="..." aria-label={`Đề xuất hỗ trợ dòng ${i + 1}`} {...text(sec.value, r, 'support')} />
                        </td>
                        <td className="min-w-[160px] py-1 pr-2">
                          <input className={cell} placeholder="..." aria-label={`Ghi chú dòng ${i + 1}`} {...text(sec.value, r, 'note')} />
                        </td>
                        <td className="py-1 text-center">
                          <Button
                            type="button"
                            variant="danger"
                            aria-label={`Xóa dòng ${i + 1}`}
                            title="Xóa dòng"
                            onClick={() => {
                              const next = rows.filter((x) => x.uid !== r.uid)
                              setLines({ ...lines, [sec.value]: next.length ? next : [blankRow()] })
                            }}
                          >
                            Xóa
                          </Button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <div className="flex flex-wrap gap-2">
                <Button type="button" variant="secondary" onClick={() => setLines({ ...lines, [sec.value]: [...rows, blankRow()] })}>
                  Thêm dòng
                </Button>
                {missing.length > 0 && (
                  <Button type="button" variant="secondary" onClick={() => setLines({ ...lines, [sec.value]: [...rows, ...missing.map(toLine)] })}>
                    Thêm từ hệ thống ({missing.length})
                  </Button>
                )}
              </div>
            </Panel>
          )
        })}
        <div className="flex justify-end">
          <Button type="submit" disabled={save.isPending}>
            Lưu báo cáo tuần
          </Button>
        </div>
      </form>
      <div className="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
        <HistoryList items={form.history} current={form.week} title="Các tuần đã nộp" onPick={onPick} />
        <LegacyList items={form.legacy} />
      </div>
    </div>
  )
}
