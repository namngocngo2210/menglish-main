// Vi phạm & Phạt (Penalties/Index.vue) — luồng BPMN 9b: ghi nhận (kèm bằng chứng) → nhân sự giải trình → HT/CM chốt lỗi,
// chốt mức phạt → nộp trong N ngày (quá hạn trừ lương) → khắc phục. "Đóng - không phạt" / "Hủy" trong hộp thoại chi tiết.
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { errorMessage } from '../../api'
import { Alert, Badge, Button, Card, Dialog, Field, Select } from '../../components/ui'
import { payrollApi, type PenaltiesPage as Page, type PenaltyFilter, type PenaltyRow } from './api'
import { decidable, money, showAmount, statusTone, stepTone, violationSuggestions } from './logic'
import { Header, Pager, Stat, Textarea, td, th } from './parts'

type Open = { kind: 'view' | 'explain' | 'decide' | 'remedy'; row: PenaltyRow } | { kind: 'new' } | null

const empty: PenaltyFilter = {
  search: '',
  status: '',
  error_category: '',
  step: '',
  from: '',
  to: '',
  page: 1,
}

export function PenaltiesPage() {
  const qc = useQueryClient()
  const [filter, setFilter] = useState<PenaltyFilter>(() => {
    const s = new URLSearchParams(window.location.search).get('search') ?? ''
    return { ...empty, search: s }
  })
  const [draft, setDraft] = useState(filter)
  const [open, setOpen] = useState<Open>(null)
  const [notice, setNotice] = useState('')
  const page = useQuery({
    queryKey: ['payroll', 'penalties', filter],
    queryFn: () => payrollApi.penalties(filter),
    placeholderData: keepPreviousData,
  })
  const quick = useMutation({
    mutationFn: async ({ kind, id }: { kind: 'paid' | 'resolve' | 'cancel'; id: string }) =>
      kind === 'paid' ? payrollApi.penaltyPaid(id) : kind === 'resolve' ? payrollApi.resolve(id) : payrollApi.cancel(id),
    onSuccess: (r) => done(r.message),
  })
  const done = (msg: string) => {
    setNotice(msg)
    setOpen(null)
    qc.invalidateQueries({ queryKey: ['payroll', 'penalties'] })
  }
  const d = page.data
  const set = (patch: Partial<PenaltyFilter>) => {
    const next = { ...filter, ...patch, page: patch.page ?? 1 }
    setFilter(next)
    setDraft(next)
  }
  return (
    <div className="space-y-6">
      <Header
        title="Danh sách vi phạm"
        description={`Ghi nhận → nhân sự giải trình → HT/CM chốt lỗi, chốt mức phạt → nộp trong ${d?.payment_due_days ?? 2} ngày (quá hạn trừ vào lương) → khắc phục.`}
        actions={d?.can_create && <Button onClick={() => setOpen({ kind: 'new' })}>Ghi nhận vi phạm mới</Button>}
      />
      {notice && <Alert tone="success">{notice}</Alert>}
      {page.isError && <Alert>{errorMessage(page.error)}</Alert>}
      {quick.isError && <Alert>{errorMessage(quick.error)}</Alert>}
      {d && (
        <>
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <Stat label="Chờ giải trình" value={d.counts.pending} icon="edit_note" tone="warning" />
            <Stat label="Chờ HT/CM chốt" value={d.counts.deciding} icon="gavel" />
            <Stat label="Đã chốt phạt (trong hạn nộp)" value={d.counts.fined} icon="schedule" tone="primary" />
            <Stat label="Quá hạn — sẽ trừ lương" value={d.counts.overdue} icon="money_off" tone="error" />
          </div>
          <Card>
            <div className="mb-3 flex flex-wrap items-center gap-2 text-sm">
              <span className="text-xs font-medium tracking-wide text-on-surface-variant uppercase">Lọc theo bước</span>
              {[{ value: '', label: 'Tất cả' }, ...d.steps].map((s) => (
                <button
                  key={s.value}
                  type="button"
                  className={`rounded-full border px-3 py-1 ${filter.step === s.value ? 'border-primary bg-primary text-white' : 'border-outline-variant hover:bg-surface-container-low'}`}
                  onClick={() => set({ step: s.value })}
                >
                  {s.label}
                </button>
              ))}
            </div>
            <form
              className="grid gap-3 md:grid-cols-3 lg:grid-cols-[1fr_180px_200px_150px_150px_auto] lg:items-end"
              role="search"
              onSubmit={(e) => {
                e.preventDefault()
                set(draft)
              }}
            >
              <Field
                label="Tìm nhân sự"
                type="search"
                value={draft.search}
                placeholder="Nhập tên hoặc mã nhân viên..."
                onChange={(e) =>
                  setDraft({
                    ...draft,
                    search: e.target.value,
                  })
                }
              />
              <Select
                label="Loại lỗi"
                value={draft.error_category}
                onChange={(e) =>
                  setDraft({
                    ...draft,
                    error_category: e.target.value,
                  })
                }
              >
                <option value="">Tất cả loại lỗi</option>
                {d.category_options.map((o) => (
                  <option key={o.value} value={o.value}>
                    {o.label}
                  </option>
                ))}
              </Select>
              <Select
                label="Trạng thái"
                value={draft.status}
                onChange={(e) =>
                  setDraft({
                    ...draft,
                    status: e.target.value,
                  })
                }
              >
                <option value="">Tất cả trạng thái</option>
                {d.status_options.map((o) => (
                  <option key={o.value} value={o.value}>
                    {o.label}
                  </option>
                ))}
              </Select>
              <Field label="Từ ngày" type="date" value={draft.from} onChange={(e) => setDraft({ ...draft, from: e.target.value })} />
              <Field label="Đến ngày" type="date" value={draft.to} onChange={(e) => setDraft({ ...draft, to: e.target.value })} />
              <Button type="submit" variant="secondary">
                Lọc
              </Button>
            </form>
            <div className="mt-4 overflow-x-auto">
              <table className="w-full min-w-[1100px] text-left text-sm">
                <thead className="text-xs text-on-surface-variant">
                  <tr>
                    <th className={th}>Nhân viên</th>
                    <th className={th}>Ngày vi phạm</th>
                    <th className={th}>Nguồn</th>
                    <th className={th}>Lỗi vi phạm</th>
                    <th className={th}>Bước hiện tại</th>
                    <th className={`${th} text-right`}>Số tiền phạt</th>
                    <th className={th}>Trạng thái GV</th>
                    <th className={`${th} text-right`}>Thao tác</th>
                  </tr>
                </thead>
                <tbody>
                  {d.penalties.map((p) => (
                    <tr key={p.id} className="border-t border-surface-container align-top">
                      <td className={td}>
                        <p className="font-semibold">{p.user_name}</p>
                        <p className="text-xs text-on-surface-variant">
                          ID: {p.employee_code || '—'} · <span className="font-mono">{p.code}</span>
                        </p>
                      </td>
                      <td className={`${td} font-mono whitespace-nowrap`}>
                        {p.violation_date}
                        {p.violation_time && <span className="block text-xs text-on-surface-variant">{p.violation_time}</span>}
                      </td>
                      <td className={td}>{p.source_label}</td>
                      <td className={`${td} max-w-xs`}>
                        <p className="font-medium text-error">{p.violation_type}</p>
                        <p className="text-xs text-on-surface-variant">
                          {p.category_label} · chốt bởi {p.confirmer_label}
                        </p>
                      </td>
                      <td className={td}>
                        <Badge tone={stepTone[p.step] ?? 'neutral'}>{p.step_label}</Badge>
                        <p className="mt-1 text-xs text-on-surface-variant">{p.status_label}</p>
                        {p.overdue && <p className="text-xs font-semibold text-error">Quá hạn nộp — trừ lương</p>}
                      </td>
                      <td className={`${td} text-right font-mono whitespace-nowrap`}>
                        {showAmount(p) ? money(p.amount) : '—'}
                        {p.due_date && p.status === 'fined' && <p className="text-xs text-on-surface-variant">Hạn {p.due_date}</p>}
                      </td>
                      <td className={td}>
                        <Badge tone={statusTone(p.employee_color)}>{p.employee_state}</Badge>
                      </td>
                      <td className={`${td} text-right whitespace-nowrap`}>
                        <span className="inline-flex flex-wrap justify-end gap-1">
                          {p.can_explain && (
                            <Button
                              className="h-8 px-2"
                              onClick={() =>
                                setOpen({
                                  kind: 'explain',
                                  row: p,
                                })
                              }
                            >
                              Giải trình
                            </Button>
                          )}
                          {p.can_decide && (
                            <Button
                              className="h-8 px-2"
                              onClick={() =>
                                setOpen({
                                  kind: 'decide',
                                  row: p,
                                })
                              }
                            >
                              Chốt
                            </Button>
                          )}
                          {p.can_mark_paid && (
                            <Button
                              variant="secondary"
                              className="h-8 px-2"
                              disabled={quick.isPending}
                              onClick={() =>
                                window.confirm(`Ghi nhận ${p.user_name} đã nộp phạt ${p.code} trực tiếp?`) &&
                                quick.mutate({
                                  kind: 'paid',
                                  id: p.id,
                                })
                              }
                            >
                              Đã nộp
                            </Button>
                          )}
                          {p.can_remedy && (
                            <Button
                              variant="secondary"
                              className="h-8 px-2"
                              onClick={() =>
                                setOpen({
                                  kind: 'remedy',
                                  row: p,
                                })
                              }
                            >
                              Khắc phục
                            </Button>
                          )}
                          <Button
                            variant="secondary"
                            className="h-8 px-2"
                            aria-label={`Chi tiết ${p.code}`}
                            onClick={() =>
                              setOpen({
                                kind: 'view',
                                row: p,
                              })
                            }
                          >
                            ⋯
                          </Button>
                        </span>
                      </td>
                    </tr>
                  ))}
                  {d.penalties.length === 0 && (
                    <tr>
                      <td colSpan={8} className="py-8 text-center text-on-surface-variant">
                        Không có biên bản vi phạm nào.
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
            <Pager paging={d.paging} unit="biên bản" onPage={(pg) => setFilter({ ...filter, page: pg })} />
          </Card>
          {open?.kind === 'new' && <CreateDialog page={d} onClose={() => setOpen(null)} onDone={done} />}
          {open?.kind === 'explain' && <ExplainDialog row={open.row} onClose={() => setOpen(null)} onDone={done} />}
          {open?.kind === 'decide' && <DecideDialog row={open.row} dueDays={d.payment_due_days} onClose={() => setOpen(null)} onDone={done} />}
          {open?.kind === 'remedy' && <RemedyDialog row={open.row} onClose={() => setOpen(null)} onDone={done} />}
          {open?.kind === 'view' && (
            <ViewDialog
              row={open.row}
              busy={quick.isPending}
              onClose={() => setOpen(null)}
              onResolve={() =>
                window.confirm(`Đóng biên bản ${open.row.code} không phạt?`) &&
                quick.mutate({
                  kind: 'resolve',
                  id: open.row.id,
                })
              }
              onCancel={() =>
                window.confirm(`Hủy biên bản ${open.row.code}?`) &&
                quick.mutate({
                  kind: 'cancel',
                  id: open.row.id,
                })
              }
            />
          )}
        </>
      )}
    </div>
  )
}

function CreateDialog({ page, onClose, onDone }: { page: Page; onClose: () => void; onDone: (m: string) => void }) {
  const [f, setF] = useState({
    user_id: '',
    error_category: 'operations',
    violation_type: '',
    violation_at: page.violation_window.max,
    amount: '',
    class_id: '',
    notes: '',
  })
  const [file, setFile] = useState<File | null>(null)
  const save = useMutation({
    mutationFn: () => payrollApi.createPenalty(f, file),
    onSuccess: (r) => onDone(r.message),
  })
  const sug = violationSuggestions(page, f.user_id)
  const pickViolation = (v: string) => {
    const known = sug.list.find((x) => x.value === v)
    setF({
      ...f,
      violation_type: v,
      error_category: known ? known.category : f.error_category,
    })
  }
  return (
    <Dialog title="Ghi nhận vi phạm mới" onClose={onClose} wide>
      <form
        className="space-y-3"
        onSubmit={(e) => {
          e.preventDefault()
          save.mutate()
        }}
      >
        {save.isError && <Alert>{errorMessage(save.error)}</Alert>}
        <Select label="Nhân sự vi phạm" value={f.user_id} onChange={(e) => setF({ ...f, user_id: e.target.value })}>
          <option value="">Chọn nhân sự</option>
          {page.users.map((u) => (
            <option key={u.value} value={u.value}>
              {u.label}
            </option>
          ))}
        </Select>
        <Field
          label="Lỗi vi phạm"
          list="penalty-violations"
          value={f.violation_type}
          hint={
            sug.roles.length
              ? `Gợi ý ${sug.list.length} lỗi của vai trò ${sug.roles.join(', ')}. Không có trong danh sách thì nhập mô tả.`
              : 'Chọn nhân sự để chỉ gợi ý lỗi đúng vai trò. Không có trong danh sách thì nhập mô tả.'
          }
          onChange={(e) => pickViolation(e.target.value)}
        />
        <datalist id="penalty-violations">
          {sug.list.map((v) => (
            <option key={v.value} value={v.value} />
          ))}
        </datalist>
        <div className="grid gap-3 sm:grid-cols-2">
          <Select label="Loại lỗi (người chốt)" value={f.error_category} onChange={(e) => setF({ ...f, error_category: e.target.value })}>
            {page.category_confirmer_options.map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </Select>
          <Field
            label="Thời điểm vi phạm"
            type="datetime-local"
            min={page.violation_window.min}
            max={page.violation_window.max}
            value={f.violation_at}
            hint={`Chỉ ghi nhận vi phạm trong ${page.violation_window.hours}h gần nhất.`}
            onChange={(e) => setF({ ...f, violation_at: e.target.value })}
          />
          <Field label="Mức phạt đề xuất (VNĐ)" type="number" min={0} step={1000} value={f.amount} onChange={(e) => setF({ ...f, amount: e.target.value })} />
          <Select label="Lớp liên quan" value={f.class_id} onChange={(e) => setF({ ...f, class_id: e.target.value })}>
            <option value="">Không gắn lớp</option>
            {page.classes.map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </Select>
        </div>
        <Textarea label="Ghi chú" value={f.notes} onChange={(v) => setF({ ...f, notes: v })} rows={2} />
        <label className="block space-y-1.5 text-sm">
          <span className="block font-medium">Bằng chứng (ảnh hoặc PDF, tối đa 10MB)</span>
          <input type="file" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
        </label>
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            Hủy
          </Button>
          <Button type="submit" disabled={save.isPending}>
            Ghi nhận
          </Button>
        </div>
      </form>
    </Dialog>
  )
}

function ExplainDialog({ row, onClose, onDone }: { row: PenaltyRow; onClose: () => void; onDone: (m: string) => void }) {
  const [text, setText] = useState('')
  const save = useMutation({
    mutationFn: () => payrollApi.explain(row.id, text),
    onSuccess: (r) => onDone(r.message),
  })
  return (
    <Dialog title={`Giải trình biên bản ${row.code}`} onClose={onClose}>
      <form
        className="space-y-3"
        onSubmit={(e) => {
          e.preventDefault()
          save.mutate()
        }}
      >
        <p className="text-sm">
          <strong>{row.violation_type}</strong> — {row.violation_date}
        </p>
        {save.isError && <Alert>{errorMessage(save.error)}</Alert>}
        <Textarea label="Nội dung giải trình (ít nhất 10 ký tự)" value={text} onChange={setText} rows={4} />
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            Hủy
          </Button>
          <Button type="submit" disabled={save.isPending}>
            Gửi giải trình
          </Button>
        </div>
      </form>
    </Dialog>
  )
}

function DecideDialog({ row, dueDays, onClose, onDone }: { row: PenaltyRow; dueDays: number; onClose: () => void; onDone: (m: string) => void }) {
  const [decision, setDecision] = useState<'fine' | 'error'>(row.status === 'confirmed' ? 'fine' : 'error')
  const [amount, setAmount] = useState(row.amount ? String(row.amount) : '')
  const [note, setNote] = useState(row.decision_note ?? '')
  const save = useMutation({
    mutationFn: () =>
      payrollApi.decide(row.id, {
        decision,
        amount: amount === '' ? null : Number(amount),
        decision_note: note,
      }),
    onSuccess: (r) => onDone(r.message),
  })
  return (
    <Dialog title={`Chốt biên bản ${row.code}`} onClose={onClose}>
      <form
        className="space-y-3"
        onSubmit={(e) => {
          e.preventDefault()
          save.mutate()
        }}
      >
        <p className="text-sm">
          {row.user_name}: <strong>{row.violation_type}</strong>
        </p>
        {row.explanation && <Alert tone="info">Giải trình: {row.explanation}</Alert>}
        {save.isError && <Alert>{errorMessage(save.error)}</Alert>}
        <fieldset className="space-y-1 text-sm">
          <label className="flex items-center gap-2">
            <input type="radio" className="accent-primary" checked={decision === 'error'} onChange={() => setDecision('error')} />
            Chốt lỗi (xác nhận có lỗi, chốt mức phạt sau)
          </label>
          <label className="flex items-center gap-2">
            <input type="radio" className="accent-primary" checked={decision === 'fine'} onChange={() => setDecision('fine')} />
            Chốt mức phạt (nộp trong {dueDays} ngày, quá hạn trừ lương)
          </label>
        </fieldset>
        {decision === 'fine' && (
          <Field label="Số tiền phạt (VNĐ)" type="number" min={1000} step={1000} value={amount} onChange={(e) => setAmount(e.target.value)} />
        )}
        <Textarea label="Ghi chú quyết định" value={note} onChange={setNote} rows={2} />
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            Hủy
          </Button>
          <Button type="submit" disabled={save.isPending}>
            Xác nhận
          </Button>
        </div>
      </form>
    </Dialog>
  )
}

function RemedyDialog({ row, onClose, onDone }: { row: PenaltyRow; onClose: () => void; onDone: (m: string) => void }) {
  const [note, setNote] = useState('')
  const save = useMutation({
    mutationFn: () => payrollApi.remedy(row.id, note),
    onSuccess: (r) => onDone(r.message),
  })
  return (
    <Dialog title={`Ghi nhận khắc phục ${row.code}`} onClose={onClose}>
      <form
        className="space-y-3"
        onSubmit={(e) => {
          e.preventDefault()
          save.mutate()
        }}
      >
        {save.isError && <Alert>{errorMessage(save.error)}</Alert>}
        <Textarea label="Ghi chú khắc phục" value={note} onChange={setNote} rows={3} />
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            Hủy
          </Button>
          <Button type="submit" disabled={save.isPending}>
            Lưu
          </Button>
        </div>
      </form>
    </Dialog>
  )
}

function ViewDialog({
  row,
  busy,
  onClose,
  onResolve,
  onCancel,
}: {
  row: PenaltyRow
  busy: boolean
  onClose: () => void
  onResolve: () => void
  onCancel: () => void
}) {
  const info: [string, string | null][] = [
    ['Nhân sự', `${row.user_name}${row.employee_code ? ` (${row.employee_code})` : ''}`],
    ['Thời điểm', `${row.violation_date}${row.violation_time ? ` ${row.violation_time}` : ''}`],
    ['Lỗi', row.violation_type],
    ['Loại lỗi', `${row.category_label} — chốt bởi ${row.confirmer_label}`],
    ['Lớp', row.class_name],
    ['Người ghi nhận', row.reporter],
    ['Trạng thái', row.status_label],
    ['Số tiền', showAmount(row) || row.amount ? money(row.amount) : null],
    ['Hạn nộp', row.due_date],
    ['Đã nộp', row.paid_at],
    ['Ghi chú', row.notes],
    ['Giải trình', row.explanation],
    ['Quyết định', row.decision_note ? `${row.decision_note}${row.decider ? ` — ${row.decider}` : ''}` : row.decider],
    ['Khắc phục', row.remedy],
  ]
  return (
    <Dialog title={`Biên bản ${row.code}`} onClose={onClose} wide>
      <dl className="grid gap-x-4 gap-y-2 text-sm sm:grid-cols-[140px_1fr]">
        {info
          .filter(([, v]) => v)
          .map(([k, v]) => (
            <div key={k} className="contents">
              <dt className="text-on-surface-variant">{k}</dt>
              <dd className="whitespace-pre-line">{v}</dd>
            </div>
          ))}
      </dl>
      {row.evidence_url && (
        <p className="mt-3 text-sm">
          <a href={row.evidence_url} target="_blank" rel="noopener noreferrer" className="font-semibold text-primary hover:underline">
            Xem bằng chứng
          </a>
        </p>
      )}
      {(row.can_resolve || (row.can_cancel && decidable(row))) && (
        <div className="mt-4 flex justify-end gap-2">
          {row.can_resolve && (
            <Button variant="secondary" disabled={busy} onClick={onResolve}>
              Đóng - không phạt
            </Button>
          )}
          {row.can_cancel && decidable(row) && (
            <Button variant="danger" disabled={busy} onClick={onCancel}>
              Hủy vi phạm
            </Button>
          )}
        </div>
      )}
    </Dialog>
  )
}
