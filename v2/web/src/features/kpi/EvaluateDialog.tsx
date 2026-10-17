// Phiếu KPI của một nhân sự (Kpi/Evaluate.vue, mở từ Phiếu KPI tháng): số hệ thống ghi nhận hoặc ô điền tay, mức đạt / tiền
// thưởng / xếp loại tính ngay khi điền; Duyệt (đủ số liệu, từ ngày cuối kỳ) hoặc Không duyệt (bắt buộc lý do).
import { useMutation, useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { errorMessage } from '../../api'
import { Alert, Badge, Button, Dialog } from '../../components/ui'
import { kpiApi, type EvaluatePage, type Line } from './api'
import {
  allLines,
  amountOf,
  decideInput,
  fmt,
  initialNA,
  initialValues,
  isNa,
  levelOf,
  levelTone,
  money,
  sheetNotice,
  sheetTotals,
  statusTone,
  valueLabel,
  valueOf,
  type SheetForm,
} from './logic'

type Props = { userId: string; period: string; track: string; onClose: () => void; onDone: (msg: string) => void }

export function EvaluateDialog(props: Props) {
  const page = useQuery({
    queryKey: ['kpi', 'evaluate', props.userId, props.period, props.track],
    queryFn: () => kpiApi.evaluate(props.userId, props.period, props.track),
  })
  if (!page.data)
    return (
      <Dialog title="Phiếu KPI" onClose={props.onClose} wide>
        {page.isError ? <Alert>{errorMessage(page.error)}</Alert> : <p className="text-sm text-on-surface-variant">Đang tải…</p>}
      </Dialog>
    )
  return <Sheet {...props} p={page.data} />
}

function Sheet({ p, userId, onClose, onDone }: Props & { p: EvaluatePage }) {
  const lines = allLines(p.groups)
  const [form, setForm] = useState<SheetForm>(() => ({ values: initialValues(lines), na: initialNA(lines) }))
  const [rejecting, setRejecting] = useState(false)
  const [reason, setReason] = useState('')
  const [localError, setLocalError] = useState('')
  const [open, setOpen] = useState<Record<string, boolean>>({})
  const t = sheetTotals(p, lines, form)
  const hasFund = p.fund !== null
  const decide = useMutation({
    mutationFn: (action: 'approve' | 'reject') => kpiApi.decide(userId, decideInput(p, form, action, reason)),
    onSuccess: (r) => onDone(r.message),
  })
  const approve = () => {
    setRejecting(false)
    setLocalError('')
    if (t.missing > 0) return setLocalError(`Còn ${t.missing} tiêu chí chưa có số liệu.`)
    decide.mutate('approve')
  }
  const reject = () => {
    setLocalError('')
    if (!rejecting) return setRejecting(true)
    decide.mutate('reject')
  }
  const notice = sheetNotice(p)
  const totalLabel = fmt(t.total)
  const cols = (hasFund ? 6 : 5) - (p.simple_rules ? 0 : 1)
  const setValue = (id: string, v: string) => setForm((f) => ({ ...f, values: { ...f.values, [id]: v } }))

  return (
    <Dialog title={p.staff.name} onClose={onClose} wide>
      <div className="space-y-3">
        <p className="text-sm text-on-surface-variant">{[p.staff.role_label, p.staff.branch, p.period_label].filter(Boolean).join(' · ')}</p>
        <div className="flex flex-wrap items-center gap-2">
          <Badge tone={statusTone(p.status_color)}>{p.status_label}</Badge>
          {p.status === 'confirmed' && p.decided_by && (
            <span className="text-xs text-on-surface-variant">
              Duyệt bởi {p.decided_by}
              {p.decided_at && ` lúc ${p.decided_at}`}.{p.track === 'main' && ' Số tiền đã vào bảng lương kỳ này.'}
            </span>
          )}
        </div>
        {p.track === 'teaching' && (
          <Alert tone="info">
            Phiếu KPI giảng dạy của Học thuật kiêm nhiệm: chấm theo bộ tiêu chí GV part-time (Cài đặt → Tiêu chí KPI), riêng với phiếu KPI Học thuật.
          </Alert>
        )}
        {p.reject_reason && (
          <Alert>
            Không duyệt{p.decided_by && ` (${p.decided_by})`}: {p.reject_reason}
          </Alert>
        )}
        {notice && <Alert tone="info">{notice}</Alert>}
        {(localError || decide.isError) && <Alert>{localError || errorMessage(decide.error)}</Alert>}
        {t.knockout && <Alert>Có {t.knockout.name.toLowerCase()} trong kỳ: mất toàn bộ KPI kỳ này (tổng 0%).</Alert>}

        {lines.length === 0 ? (
          <p className="rounded-lg bg-surface-container-low p-6 text-center text-sm">
            Vai trò này chưa có tiêu chí KPI. Thêm tiêu chí ở Cài đặt → Tiêu chí KPI.
          </p>
        ) : (
          <div className="overflow-x-auto rounded-lg border border-outline-variant">
            <table className="w-full min-w-[720px] text-sm">
              <thead className="bg-surface-container-low text-left text-xs text-on-surface-variant uppercase">
                <tr>
                  <th className="px-3 py-2">Tiêu chí</th>
                  {p.simple_rules ? (
                    <>
                      <th className="px-2 py-2 whitespace-nowrap">Đạt 100% khi</th>
                      <th className="px-2 py-2 whitespace-nowrap">Đạt 50% khi</th>
                    </>
                  ) : (
                    <th className="px-2 py-2">Cách tính</th>
                  )}
                  <th className="px-2 py-2 text-right">Số liệu</th>
                  <th className="px-2 py-2 whitespace-nowrap">Mức đạt</th>
                  {hasFund && <th className="px-3 py-2 text-right">Thưởng</th>}
                </tr>
              </thead>
              <tbody>
                {p.groups.map((g) => (
                  <GroupRows
                    key={g.name}
                    name={g.name}
                    items={g.items}
                    cols={cols}
                    p={p}
                    form={form}
                    open={open}
                    toggle={(k) => setOpen((o) => ({ ...o, [k]: !o[k] }))}
                    setValue={setValue}
                    setNA={(id, v) => setForm((f) => ({ ...f, na: { ...f.na, [id]: v } }))}
                  />
                ))}
              </tbody>
            </table>
          </div>
        )}

        {rejecting && (
          <label className="block space-y-1.5 rounded-xl border border-error/30 bg-error-container/40 p-4 text-sm font-medium">
            <span>Lý do không duyệt (bắt buộc)</span>
            <textarea
              autoFocus
              rows={3}
              className="block w-full rounded-lg border border-outline-variant bg-surface px-3 py-2 font-normal"
              placeholder="vd: Thiếu số liệu feedback Big Test lớp FLY-2409, điền lại giúp"
              value={reason}
              onChange={(e) => setReason(e.target.value)}
            />
            <span className="block text-xs font-normal text-on-surface-variant">Người được chấm thấy lý do này ở trang KPI của tôi.</span>
          </label>
        )}

        <div className="flex flex-wrap items-center gap-2 border-t border-surface-container pt-3">
          <div className="mr-auto flex flex-col">
            <span className="text-xs text-on-surface-variant">
              {hasFund ? `Tổng thưởng KPI · ${totalLabel}%` : 'Tổng KPI'}
              {t.missing > 0 && ` · còn ${t.missing} tiêu chí chưa có số liệu`}
            </span>
            <span className="font-semibold tabular-nums" data-testid="kpi-total">
              {t.totalMoney !== null ? money(t.totalMoney) : `${totalLabel}%`}
              {t.grade && ` · Loại ${t.grade.grade} (${t.grade.label}, hệ số ${t.grade.pay}%)`}
              {t.bonus !== null && ` · thưởng ${money(t.bonus)}`}
            </span>
          </div>
          <Button variant="secondary" onClick={onClose}>
            Đóng
          </Button>
          {p.can_decide && (
            <>
              <Button variant="danger" disabled={decide.isPending} onClick={reject}>
                {rejecting ? 'Xác nhận không duyệt' : 'Không duyệt'}
              </Button>
              <Button disabled={!p.can_close || decide.isPending} onClick={approve}>
                Duyệt
              </Button>
            </>
          )}
        </div>
      </div>
    </Dialog>
  )
}

function GroupRows({
  name,
  items,
  cols,
  p,
  form,
  open,
  toggle,
  setValue,
  setNA,
}: {
  name: string
  items: Line[]
  cols: number
  p: EvaluatePage
  form: SheetForm
  open: Record<string, boolean>
  toggle: (k: string) => void
  setValue: (id: string, v: string) => void
  setNA: (id: string, v: boolean) => void
}) {
  const hasFund = p.fund !== null
  return (
    <>
      <tr className="border-t border-surface-container">
        <td colSpan={cols} className="px-3 pt-3 pb-1 font-semibold">
          {name}
        </td>
      </tr>
      {items.map((c) => {
        const level = levelOf(c, form, p.status)
        const amount = amountOf(c, form, p.status)
        const editable = !c.auto && c.count_based && !isNa(c, form)
        return (
          <tr key={c.id} className="border-t border-surface-container align-top">
            <td className="px-3 py-2">
              <p className="font-medium">{c.name}</p>
              <p className="text-xs text-on-surface-variant">
                {c.knockout ? 'Điều kiện loại trừ' : `${c.weight_label}${hasFund ? '%' : ' điểm'}`}
                {!c.knockout && c.max_amount !== null && ` · tối đa ${money(c.max_amount)}`}
                {c.per_month && p.period_months > 1 && ' · tính từng tháng, lấy trung bình quý'}
              </p>
              {c.pending && <p className="text-xs text-warning">{c.pending}</p>}
              {c.cap && <p className="text-xs font-medium text-error">Có order giao sau giờ dùng: mục tối đa 50%, tháng không xếp loại A</p>}
              {c.suggestion && (
                <div className="mt-1 flex flex-wrap items-center gap-x-2 text-xs text-on-surface-variant">
                  <span>
                    Nhật ký sự vụ trong kỳ: <strong className="text-on-surface">{c.suggestion.count} lần</strong>
                  </span>
                  {p.can_decide && editable && form.values[c.id] !== String(c.suggestion.count) && (
                    <button type="button" className="font-medium text-primary hover:underline" onClick={() => setValue(c.id, String(c.suggestion!.count))}>
                      Điền {c.suggestion.count}
                    </button>
                  )}
                  {c.suggestion.evidence.length > 0 && (
                    <button type="button" className="font-medium text-primary hover:underline" onClick={() => toggle('j' + c.id)}>
                      {open['j' + c.id] ? 'Ẩn' : 'Xem'}
                    </button>
                  )}
                </div>
              )}
              {c.suggestion && open['j' + c.id] && (
                <ul className="mt-1 space-y-0.5 rounded-lg bg-surface-container-low px-2 py-1 text-xs text-on-surface-variant">
                  {c.suggestion.evidence.map((e, i) => (
                    <li key={i}>
                      {e.date} · {e.text}
                    </li>
                  ))}
                </ul>
              )}
              {c.auto && c.evidence.length > 0 && <EvidenceList id={c.id} line={c} open={!!open[c.id]} toggle={toggle} />}
            </td>
            {p.simple_rules ? (
              <>
                <td className="px-2 py-2 whitespace-nowrap">{c.threshold_full}</td>
                <td className="px-2 py-2 whitespace-nowrap">{c.threshold_half}</td>
              </>
            ) : (
              <td className="max-w-[240px] min-w-[180px] px-2 py-2 text-xs text-on-surface-variant">{c.rule_label}</td>
            )}
            <td className="px-2 py-2 text-right">
              {editable ? (
                <input
                  type={c.measure === 'rate' ? 'text' : 'number'}
                  min={0}
                  max={9999}
                  inputMode={c.measure === 'rate' ? 'decimal' : 'numeric'}
                  placeholder={c.measure === 'rate' ? '%' : undefined}
                  disabled={!p.can_decide}
                  aria-label={`Số liệu: ${c.name}`}
                  value={form.values[c.id] ?? ''}
                  onChange={(e) => setValue(c.id, e.target.value)}
                  className={`w-20 rounded-lg border px-2 py-1 text-right tabular-nums disabled:bg-surface-container-low ${
                    valueOf(c, form) === null && p.can_decide ? 'border-dashed border-primary' : 'border-outline-variant'
                  }`}
                />
              ) : (
                <span className={isNa(c, form) ? 'text-xs text-on-surface-variant' : 'font-semibold tabular-nums'}>{valueLabel(c, form)}</span>
              )}
              {c.allow_na && (
                <label className="mt-1 flex items-center justify-end gap-1 text-xs text-on-surface-variant">
                  <input type="checkbox" disabled={!p.can_decide} checked={!!form.na[c.id]} onChange={(e) => setNA(c.id, e.target.checked)} />
                  Không phát sinh
                </label>
              )}
            </td>
            <td className="px-2 py-2">
              {level !== null ? <Badge tone={levelTone(level)}>{fmt(level)}%</Badge> : <span className="text-on-surface-variant">—</span>}
            </td>
            {hasFund && <td className="px-3 py-2 text-right whitespace-nowrap tabular-nums">{amount === null ? '—' : money(amount)}</td>}
          </tr>
        )
      })}
    </>
  )
}

export function EvidenceList({ id, line, open, toggle }: { id: string; line: Line; open: boolean; toggle: (k: string) => void }) {
  return (
    <>
      <button type="button" className="mt-1 text-xs font-medium text-primary hover:underline" onClick={() => toggle(id)}>
        {open ? 'Ẩn' : 'Xem'} {line.evidence.length} bản ghi
      </button>
      {open && (
        <ul className="mt-1 space-y-0.5 rounded-lg bg-surface-container-low px-2 py-1 text-xs text-on-surface-variant">
          {line.evidence.map((e, i) => (
            <li key={i} className={e.counted ? '' : 'line-through opacity-70'}>
              {e.date} · {e.text}
              {e.note && ` (${e.note})`}
            </li>
          ))}
        </ul>
      )}
    </>
  )
}
