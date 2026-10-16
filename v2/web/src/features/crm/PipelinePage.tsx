// Kanban tuyển sinh (Laravel Crm/Pipeline): 8 cột theo giai đoạn, kéo thả thẻ để chuyển giai đoạn.
// Học vụ / Quản lý cơ sở tiến đúng 1 bước; Admin lùi bước (bắt buộc lý do); Chờ xếp lớp / Đã chốt chỉ qua Chốt & Xếp lớp.
import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState, type DragEvent } from 'react'
import { errorMessage, type Me } from '../../api'
import { Alert, Card } from '../../components/ui'
import { navigate } from '../../router'
import { crmApi, type Card as LeadCard, type Column } from './api'
import { FilterBar, type Filters } from './CustomersPage'
import { CrmHeader, customerPath, ReasonDialog, SlaBadge, useFlash } from './components'
import { canDrag, formatMoney, needsReason, stageEditOptions, transitionType, type StageRules } from './logic'

type Pending = { lead: LeadCard; from: string; to?: string }

export function PipelinePage({ me }: { me: Me }) {
  const qc = useQueryClient()
  const [filters, setFilters] = useState<Filters>({})
  const pipeline = useQuery({
    queryKey: ['crm', 'pipeline', filters],
    queryFn: () => crmApi.pipeline(filters),
    placeholderData: keepPreviousData,
  })
  const options = useQuery({ queryKey: ['crm', 'options'], queryFn: crmApi.options })
  const [dragging, setDragging] = useState<{ lead: LeadCard; from: string } | null>(null)
  const [over, setOver] = useState<string | null>(null)
  const [edit, setEdit] = useState<Pending | null>(null)
  const flash = useFlash()
  const p = pipeline.data
  const rules: StageRules | null = p ? { order: p.order, closed: p.closed, permissions: p.permissions } : null
  const labels = Object.fromEntries((p?.stages ?? []).map((s) => [s.id, s.name]))
  const refresh = () => qc.invalidateQueries({ queryKey: ['crm'] })

  async function move(lead: LeadCard, to: string, reason?: string) {
    try {
      const r = await crmApi.stage(lead.id, to, reason)
      flash.ok(r.message)
      refresh()
    } catch (e) {
      flash.fail(errorMessage(e))
    }
  }

  function onDrop(e: DragEvent, col: Column) {
    e.preventDefault()
    setOver(null)
    const d = dragging
    setDragging(null)
    if (!d || !rules) return
    const t = transitionType(rules, d.from, col.id)
    if (t === 'forward') void move(d.lead, col.id)
    else if (t === 'backward') setEdit({ lead: d.lead, from: d.from, to: col.id })
    else if (d.from !== col.id) flash.fail('Không thể kéo khách sang giai đoạn này. Chờ xếp lớp / Đã chốt chỉ qua Chốt & Xếp lớp.')
  }

  return (
    <Card>
      <CrmHeader me={me} title="Kanban tuyển sinh" />
      {options.data && <FilterBar options={options.data} value={filters} onChange={setFilters} />}
      {flash.node}
      {pipeline.isError && <Alert>{errorMessage(pipeline.error)}</Alert>}
      {p && rules && (
        <div className="mt-3 flex gap-3 overflow-x-auto pb-3">
          {p.stages.map((col) => {
            const droppable = !!dragging && transitionType(rules, dragging.from, col.id) !== null
            return (
              <section
                key={col.id}
                aria-label={col.name}
                className={`flex w-64 shrink-0 flex-col rounded-xl border bg-surface-container-low p-2 ${
                  over === col.id && droppable ? 'border-primary' : 'border-transparent'
                }`}
                onDragOver={(e) => {
                  if (droppable) {
                    e.preventDefault()
                    setOver(col.id)
                  }
                }}
                onDragLeave={() => setOver((o) => (o === col.id ? null : o))}
                onDrop={(e) => onDrop(e, col)}
              >
                <header className="mb-2 px-1">
                  <div className="flex items-center justify-between text-sm font-semibold">
                    {col.name}
                    <span className="rounded-full bg-surface px-2 text-xs">{col.count}</span>
                  </div>
                  <div className="text-xs text-on-surface-variant">{formatMoney(col.amount)}</div>
                </header>
                <div className="flex flex-col gap-2">
                  {col.leads.map((lead) => {
                    const drag = canDrag(rules, col.id)
                    const opts = stageEditOptions(rules, col.id, labels)
                    return (
                      <article
                        key={lead.id}
                        draggable={drag}
                        onDragStart={(e) => {
                          e.dataTransfer.effectAllowed = 'move'
                          e.dataTransfer.setData('text/plain', lead.id)
                          setDragging({ lead, from: col.id })
                        }}
                        onDragEnd={() => setDragging(null)}
                        onClick={(e) => {
                          if ((e.target as HTMLElement).closest('button')) return
                          navigate(customerPath(lead.id))
                        }}
                        className={`rounded-lg border border-surface-container-highest bg-surface p-2 text-sm shadow-sm ${
                          drag ? 'cursor-grab active:cursor-grabbing' : 'cursor-pointer'
                        }`}
                      >
                        <div className="font-medium">{lead.name}</div>
                        <div className="text-xs text-on-surface-variant">
                          {lead.short_code} · {lead.phone}
                        </div>
                        <div className="mt-1 text-xs text-on-surface-variant">
                          {lead.course || '—'} · {lead.agent || 'Chưa phân công'}
                        </div>
                        {lead.score && <div className="text-xs">Điểm test: {lead.score}</div>}
                        <div className="mt-1 flex flex-wrap items-center gap-1">
                          <SlaBadge sla={lead.sla} compact />
                          {opts.length > 0 && (
                            <button
                              type="button"
                              className="ml-auto text-xs text-primary hover:underline"
                              onClick={() => setEdit({ lead, from: col.id })}
                            >
                              Sửa giai đoạn
                            </button>
                          )}
                        </div>
                        {lead.next_blocked && <p className="mt-1 text-[11px] text-on-surface-variant">{lead.next_blocked}</p>}
                      </article>
                    )
                  })}
                  {col.hidden > 0 && <p className="px-1 text-xs text-on-surface-variant">Còn {col.hidden} khách — xem ở Danh sách.</p>}
                </div>
              </section>
            )
          })}
        </div>
      )}
      {edit && rules && (
        <ReasonDialog
          title={`Sửa giai đoạn: ${edit.lead.name}`}
          options={(() => {
            const o = stageEditOptions(rules, edit.from, labels)
            return edit.to ? [...o.filter((x) => x.value === edit.to), ...o.filter((x) => x.value !== edit.to)] : o
          })()}
          required={(choice) => needsReason(rules, edit.from, choice)}
          onClose={() => setEdit(null)}
          onSubmit={async (reason, to) => {
            const r = await crmApi.stage(edit.lead.id, to, reason || undefined)
            flash.ok(r.message)
            refresh()
          }}
        />
      )}
    </Card>
  )
}
