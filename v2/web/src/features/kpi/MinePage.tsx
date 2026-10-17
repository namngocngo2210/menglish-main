// KPI của tôi (Kpi/Mine.vue): phiếu KPI của chính mình theo tháng / quý — tiền thưởng (Học vụ) hoặc tỉ lệ đạt và xếp loại,
// trạng thái phiếu, số liệu từng tiêu chí (số hệ thống kèm bản ghi, hoặc số người chấm điền).
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { ApiError, errorMessage } from '../../api'
import { Alert, Badge } from '../../components/ui'
import { kpiApi } from './api'
import { EvidenceList } from './EvaluateDialog'
import { fmt, levelTone, money, statusTone } from './logic'

export function MinePage() {
  const [period, setPeriod] = useState('')
  const [track, setTrack] = useState('main')
  const [open, setOpen] = useState<Record<string, boolean>>({})
  const page = useQuery({
    queryKey: ['kpi', 'mine', period, track],
    queryFn: () => kpiApi.mine(period, track),
    placeholderData: keepPreviousData,
    retry: false,
  })
  const d = page.data
  const noKpi = page.error instanceof ApiError && page.error.code === 'no_kpi'

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold">KPI của tôi</h1>
          {d && (
            <p className="text-sm text-on-surface-variant">
              {d.name} · {d.role_label} · {d.period_label}. Số liệu hệ thống ghi nhận cập nhật hằng ngày; tiêu chí điền tay có số khi người chấm điền cuối kỳ.
            </p>
          )}
        </div>
        {d && (
          <div className="flex flex-wrap items-center gap-2">
            {d.track_options.length > 0 && (
              <div className="flex gap-1" role="group" aria-label="Phiếu KPI">
                {d.track_options.map((o) => (
                  <button
                    key={o.value}
                    type="button"
                    aria-pressed={o.value === d.track}
                    onClick={() => setTrack(o.value)}
                    className={`rounded-full px-3 py-1 text-sm ${o.value === d.track ? 'bg-primary font-semibold text-white' : 'bg-surface-container-low text-on-surface-variant'}`}
                  >
                    {o.label}
                  </button>
                ))}
              </div>
            )}
            <label htmlFor="kpi-mine-period" className="sr-only">
              {d.period_months > 1 ? 'Quý' : 'Kỳ lương'}
            </label>
            <select
              id="kpi-mine-period"
              className="h-10 rounded-lg border border-outline-variant bg-surface px-3 text-sm"
              value={d.period}
              onChange={(e) => setPeriod(e.target.value)}
            >
              {d.period_options.map((o) => (
                <option key={o.value} value={o.value}>
                  {o.label}
                </option>
              ))}
            </select>
          </div>
        )}
      </div>

      {noKpi ? (
        <div className="rounded-xl border border-surface-container-highest bg-surface p-8 text-center text-sm">
          <p className="font-medium">Bạn chưa có tiêu chí KPI</p>
          <p className="text-on-surface-variant">Vai trò của bạn chưa có bộ tiêu chí KPI.</p>
        </div>
      ) : (
        page.isError && <Alert>{errorMessage(page.error)}</Alert>
      )}

      {d && !noKpi && (
        <>
          <section className="flex flex-wrap items-center gap-x-10 gap-y-4 rounded-xl border border-surface-container-highest bg-surface p-4 shadow-sm">
            <div>
              <p className="text-xs font-semibold text-on-surface-variant uppercase">{d.fund !== null ? 'Thưởng KPI' : 'Tỉ lệ đạt'}</p>
              <p className="text-2xl font-bold tabular-nums">{d.fund !== null ? money(d.amount ?? 0) : d.rate_label}</p>
              {d.fund !== null && (
                <p className="text-xs text-on-surface-variant">
                  {d.rate_label} quỹ {money(d.fund)}
                </p>
              )}
            </div>
            {d.grade && (
              <div>
                <p className="text-xs font-semibold text-on-surface-variant uppercase">Xếp loại {d.period_months > 1 ? 'quý' : 'tháng'}</p>
                <p className="text-2xl font-bold">{d.grade.grade}</p>
                <p className="text-xs text-on-surface-variant">
                  {d.grade.label} · hệ số lương KPI {d.grade.pay}%
                </p>
                {d.missing > 0 && <p className="text-xs text-on-surface-variant">Tạm tính: còn {d.missing} tiêu chí chưa có số liệu</p>}
              </div>
            )}
            {d.bonus !== null && d.grade && (
              <div>
                <p className="text-xs font-semibold text-on-surface-variant uppercase">Thưởng KPI</p>
                <p className="text-2xl font-bold tabular-nums">{money(d.bonus)}</p>
                <p className="text-xs text-on-surface-variant">
                  {money(d.bonus_fund ?? 0)} × hệ số loại {d.grade.grade}
                </p>
              </div>
            )}
            <div>
              <p className="text-xs font-semibold text-on-surface-variant uppercase">Phiếu</p>
              <p className="mt-1">
                <Badge tone={statusTone(d.status_color)}>{d.status_label}</Badge>
              </p>
              {d.status === 'confirmed' && d.decided_by && (
                <p className="text-xs text-on-surface-variant">
                  Duyệt bởi {d.decided_by}
                  {d.decided_at && ` · ${d.decided_at}`}
                </p>
              )}
            </div>
          </section>
          {d.reject_reason && <Alert>Phiếu không được duyệt: {d.reject_reason}</Alert>}
          {d.knockout && <Alert>Có {d.knockout.toLowerCase()} trong kỳ: mất toàn bộ KPI kỳ này.</Alert>}

          {d.groups.map((g) => (
            <section key={g.name} className="rounded-xl border border-surface-container-highest bg-surface shadow-sm">
              <h2 className="border-b border-surface-container px-4 py-2 font-semibold">{g.name}</h2>
              {g.items.map((c) => (
                <div key={c.id} className="grid grid-cols-[minmax(0,1fr)_auto] gap-x-4 border-b border-surface-container px-4 py-2 last:border-b-0">
                  <div className="min-w-0">
                    <p className="font-medium">{c.name}</p>
                    <p className="text-xs text-on-surface-variant">
                      {c.na ? (
                        'Không phát sinh trong kỳ'
                      ) : c.value === null ? (
                        'Chưa có số liệu'
                      ) : c.measure === 'rate' ? (
                        <span className="font-semibold text-on-surface tabular-nums">{fmt(c.value)}%</span>
                      ) : (
                        <>
                          <span className="font-semibold text-on-surface tabular-nums">{c.value}</span> {c.unit}
                        </>
                      )}{' '}
                      · {c.rule_label}
                      {c.per_month && d.period_months > 1 && ' (tính từng tháng, lấy trung bình quý)'}
                    </p>
                    {c.evidence.length > 0 && <EvidenceList id={c.id} line={c} open={!!open[c.id]} toggle={(k) => setOpen((o) => ({ ...o, [k]: !o[k] }))} />}
                  </div>
                  <div className="text-right">
                    {c.level !== null ? <Badge tone={levelTone(c.level)}>{fmt(c.level)}%</Badge> : <span className="text-on-surface-variant">—</span>}
                    {c.amount !== null && <p className="text-xs text-on-surface-variant tabular-nums">{money(c.amount)}</p>}
                  </div>
                </div>
              ))}
            </section>
          ))}
        </>
      )}
    </div>
  )
}
