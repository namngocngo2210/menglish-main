// Checklist Học phí & Feedback theo lớp (ClassQuality/Checklist.vue): theo tháng, mỗi lớp có học trong tháng một dòng — 3 mục
// học phí + ghi chú, 3 mục Big Test & feedback, mỗi mục Có / Không / N-A. Lưu cả trang một lần. Mục "đến hạn" = Có mà
// việc cần làm = Không → dòng được đánh dấu thiếu sót (căn cứ chấm KPI Học vụ).
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { errorMessage, type Me } from '../../api'
import { Alert, Button, Field, Icon } from '../../components/ui'
import { EmptyState, PageHeader, Pager } from '../platform/parts'
import { qualityApi, type ChecklistPage as Data, type ChecklistRow } from './api'
import { FilterSelect, Panel } from './components'
import { checklistGaps } from './logic'

const TUITION = ['tuition_due', 'tuition_reminded', 'tuition_collected']
const BIG_TEST = ['big_test_due', 'feedback_on_time', 'negative_feedback_handled']
const ANSWER_TONE: Record<string, string> = {
  yes: 'bg-success text-white',
  no: 'bg-error text-white',
  na: 'bg-on-surface-variant text-white',
}

type Rows = Record<string, Record<string, string | null>>

function rowsOf(d: Data): Rows {
  const out: Rows = {}
  for (const c of d.classes) out[c.id] = { ...c.values, tuition_note: c.tuition_note }
  return out
}

export function ChecklistPage(_: { me: Me }) {
  const [filter, setFilter] = useState({ month: '', search: '', page: 1 })
  const [q, setQ] = useState('')
  const page = useQuery({
    queryKey: ['class-quality', 'checklist', filter],
    queryFn: () => qualityApi.checklist(filter),
    placeholderData: keepPreviousData,
  })
  const d = page.data

  return (
    <div className="space-y-6">
      <PageHeader
        title="Checklist Học phí & Feedback theo lớp"
        description="Theo dõi quy trình nhắc học phí và feedback Big Test định kỳ theo từng lớp để tính chỉ số KPI."
      />
      {page.isError && <Alert>{errorMessage(page.error)}</Alert>}
      {d && (
        <>
          <form
            className="grid gap-3 sm:grid-cols-[1fr_220px_auto] sm:items-end"
            onSubmit={(e) => {
              e.preventDefault()
              setFilter({ ...filter, search: q, page: 1 })
            }}
          >
            <Field label="Tìm kiếm" placeholder="Tìm mã / tên lớp..." value={q} onChange={(e) => setQ(e.target.value)} />
            <FilterSelect label="Tháng theo dõi" options={d.months} value={d.month} onChange={(e) => setFilter({ ...filter, month: e.target.value, page: 1 })} />
            <Button type="submit" variant="secondary">
              Lọc
            </Button>
          </form>
          {/* Dựng lại bảng khi đổi tháng / trang để bỏ phần đang sửa dở. */}
          <ChecklistGrid key={`${d.month}:${d.meta.page}:${filter.search}`} d={d} onPage={(p) => setFilter({ ...filter, page: p })} />
        </>
      )}
    </div>
  )
}

function ChecklistGrid({ d, onPage }: { d: Data; onPage: (p: number) => void }) {
  const qc = useQueryClient()
  const [rows, setRows] = useState<Rows>(() => rowsOf(d))
  const [notice, setNotice] = useState('')
  const label = (key: string) => d.items.find((i) => i.key === key)?.label ?? key
  const set = (classId: string, key: string, value: string | null) => setRows({ ...rows, [classId]: { ...rows[classId], [key]: value } })
  const save = useMutation({
    mutationFn: () =>
      qualityApi.saveChecklist(
        d.month,
        d.classes.map((c) => ({ ...rows[c.id], class_id: c.id, tuition_note: rows[c.id]?.tuition_note ?? null }) as ChecklistRow),
      ),
    onSuccess: (r) => {
      setNotice(r.message)
      qc.invalidateQueries({ queryKey: ['class-quality'] })
    },
  })

  return (
    <div className="space-y-4">
      {notice && <Alert tone="success">{notice}</Alert>}
      {save.isError && <Alert>{errorMessage(save.error)}</Alert>}
      <Panel>
        <div className="overflow-x-auto">
          <table className="w-full min-w-[1280px] text-left text-sm">
            <thead className="text-xs text-on-surface-variant [&_th]:px-3 [&_th]:py-2 [&_th]:font-medium">
              <tr>
                <th rowSpan={2} className="align-bottom">
                  Lớp học
                </th>
                <th colSpan={4} className="border-b border-surface-container-highest text-center">
                  Học phí
                </th>
                <th colSpan={3} className="border-b border-surface-container-highest text-center">
                  Big Test & Feedback
                </th>
              </tr>
              <tr className="border-b border-surface-container-highest">
                {TUITION.map((k) => (
                  <th key={k} className="text-center">
                    {label(k)}
                  </th>
                ))}
                <th>Ghi chú học phí</th>
                {BIG_TEST.map((k) => (
                  <th key={k} className="text-center">
                    {label(k)}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody className="divide-y divide-surface-container-highest [&_td]:px-3 [&_td]:py-2">
              {d.classes.map((c) => {
                const row = rows[c.id] ?? {}
                const gaps = checklistGaps(row, d.follow_ups)
                return (
                  <tr key={c.id} className={gaps.length ? 'bg-error/5' : ''}>
                    <td className="whitespace-nowrap">
                      <span className="flex items-center gap-1 font-semibold">
                        {gaps.length > 0 && (
                          <span title={`Thiếu: ${gaps.map(label).join(', ')}`}>
                            <Icon name="error" className="text-[18px] text-error" />
                          </span>
                        )}
                        {c.code}
                      </span>
                      <span className="block text-xs text-on-surface-variant">
                        {c.name}
                        {c.teacher && ` · ${c.teacher}`}
                      </span>
                    </td>
                    {[...TUITION, 'tuition_note', ...BIG_TEST].map((key) =>
                      key === 'tuition_note' ? (
                        <td key={key} className="min-w-[200px]">
                          <input
                            className="block h-9 w-full rounded-lg border border-outline-variant px-2 text-sm outline-none focus:border-primary disabled:bg-surface-container-low"
                            value={row.tuition_note ?? ''}
                            placeholder="Ghi chú..."
                            disabled={!d.can_edit}
                            aria-label={`Ghi chú học phí ${c.code}`}
                            onChange={(e) => set(c.id, 'tuition_note', e.target.value)}
                          />
                        </td>
                      ) : (
                        <td key={key} className="text-center">
                          <div className="inline-flex overflow-hidden rounded-lg border border-outline-variant" role="radiogroup" aria-label={`${label(key)} — ${c.code}`}>
                            {d.answers.map((a) => {
                              const checked = row[key] === a.key
                              const flagged = a.key === 'no' && gaps.includes(key)
                              return (
                                <label key={a.key} className="cursor-pointer">
                                  <input
                                    type="radio"
                                    className="peer sr-only"
                                    name={`${c.id}-${key}`}
                                    value={a.key}
                                    checked={checked}
                                    disabled={!d.can_edit}
                                    onChange={() => set(c.id, key, a.key)}
                                  />
                                  <span
                                    className={`block px-2 py-1 text-xs transition-colors peer-focus-visible:ring-2 peer-focus-visible:ring-primary ${
                                      checked ? ANSWER_TONE[a.key] : 'text-on-surface-variant'
                                    } ${flagged ? 'ring-1 ring-error' : ''}`}
                                  >
                                    {a.label}
                                  </span>
                                </label>
                              )
                            })}
                          </div>
                        </td>
                      ),
                    )}
                  </tr>
                )
              })}
              {d.classes.length === 0 && (
                <tr>
                  <td colSpan={8}>
                    <EmptyState icon="checklist" title="Không có lớp nào học trong tháng này" />
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
        <Pager page={d.meta.page} perPage={d.meta.per_page} total={d.meta.total} unit="lớp" onPage={onPage} />
      </Panel>
      {d.can_edit && d.classes.length > 0 && (
        <div className="flex justify-end">
          <Button disabled={save.isPending} onClick={() => save.mutate()}>
            <Icon name="save" className="mr-1 text-[18px]" /> Lưu checklist
          </Button>
        </div>
      )}
    </div>
  )
}
