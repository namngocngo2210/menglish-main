// Báo cáo tuyển sinh (Laravel Crm/Reports): chỉ số theo kỳ so với kỳ trước, phễu giai đoạn, lý do thất bại, hiệu suất.
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { errorMessage, type Me } from '../../api'
import { Alert, Card, Field, Select } from '../../components/ui'
import { crmApi, downloads } from './api'
import { customerPath, Link, Table, Td } from './components'
import { formatDate, formatDateTime, formatMoney } from './logic'

function Delta({ v }: { v: number }) {
  const cls = v > 0 ? 'text-success' : v < 0 ? 'text-error' : 'text-on-surface-variant'
  return <span className={`text-xs ${cls}`}>{v > 0 ? `+${v}` : v}% so với kỳ trước</span>
}

function Metric({ label, value, delta }: { label: string; value: string; delta: number }) {
  return (
    <div className="rounded-lg bg-surface-container-low px-4 py-3">
      <div className="text-xs text-on-surface-variant">{label}</div>
      <div className="text-xl font-semibold">{value}</div>
      <Delta v={delta} />
    </div>
  )
}

export function ReportsPage(_: { me: Me }) {
  const [preset, setPreset] = useState('last_30_days')
  const [start, setStart] = useState('')
  const [end, setEnd] = useState('')
  const [branch, setBranch] = useState('')
  const params = { preset, start_date: preset === 'custom' ? start : undefined, end_date: preset === 'custom' ? end : undefined, branch_id: branch || undefined }
  const rep = useQuery({ queryKey: ['crm', 'report', params], queryFn: () => crmApi.report(params), placeholderData: keepPreviousData })
  const r = rep.data
  const maxFunnel = Math.max(1, ...(r?.funnel_stages.map((f) => f.count) ?? [1]))
  return (
    <div className="space-y-4">
      <Card
        title={
          <span className="flex flex-wrap items-center justify-between gap-3">
            Báo cáo tuyển sinh
            <a
              className="inline-flex h-10 items-center rounded-lg border border-outline-variant px-4 text-sm font-medium hover:bg-surface-container-low"
              href={downloads.report(params, 'xlsx')}
            >
              Xuất hiệu suất (Excel)
            </a>
          </span>
        }
      >
        <div className="grid gap-3 sm:grid-cols-4">
          <Select label="Kỳ báo cáo" value={preset} onChange={(e) => setPreset(e.target.value)}>
            {(r?.presets ?? [{ value: 'last_30_days', label: '30 ngày qua' }]).map((p) => (
              <option key={p.value} value={p.value}>
                {p.label}
              </option>
            ))}
          </Select>
          {preset === 'custom' && (
            <>
              <Field label="Từ ngày" type="date" value={start} onChange={(e) => setStart(e.target.value)} />
              <Field label="Đến ngày" type="date" value={end} onChange={(e) => setEnd(e.target.value)} />
            </>
          )}
          {r && r.branches.length > 1 && (
            <Select label="Cơ sở" value={branch} onChange={(e) => setBranch(e.target.value)}>
              <option value="">Tất cả cơ sở</option>
              {r.branches.map((b) => (
                <option key={b.value} value={b.value}>
                  {b.label}
                </option>
              ))}
            </Select>
          )}
        </div>
        {rep.isError && <Alert>{errorMessage(rep.error)}</Alert>}
        {r && (
          <>
            <p className="mt-3 text-xs text-on-surface-variant">
              {formatDate(r.start_date)} – {formatDate(r.end_date)} · cập nhật {formatDateTime(r.updated_at)}
            </p>
            <div className="mt-3 grid gap-3 sm:grid-cols-4">
              <Metric label="Khách mới" value={String(r.metric_total_leads)} delta={r.lead_delta_percent} />
              <Metric label="Chốt thành công" value={String(r.metric_won_deals)} delta={r.won_delta_percent} />
              <Metric label="Tỷ lệ chốt" value={`${r.metric_conversion_rate}%`} delta={r.conversion_delta_percent} />
              <Metric label="Thất bại" value={String(r.metric_lost_deals)} delta={r.lost_delta_percent} />
            </div>
          </>
        )}
      </Card>
      {r && (
        <div className="grid gap-4 lg:grid-cols-2">
          <Card title="Phễu theo giai đoạn">
            <ul className="space-y-2 text-sm">
              {r.funnel_stages.map((f) => (
                <li key={f.stage} title={f.desc}>
                  <div className="flex justify-between">
                    <span>{f.name}</span>
                    <span>
                      {f.count} · {f.percent}%
                    </span>
                  </div>
                  <div className="h-2 rounded-full bg-surface-container-high">
                    <div className="h-2 rounded-full bg-primary" style={{ width: `${(f.count / maxFunnel) * 100}%` }} />
                  </div>
                </li>
              ))}
            </ul>
          </Card>
          <Card title="Khách thất bại gần đây">
            {r.lost_reasons.length === 0 && <p className="text-sm text-on-surface-variant">Không có khách thất bại trong kỳ.</p>}
            <ul className="space-y-2 text-sm">
              {r.lost_reasons.map((l) => (
                <li key={l.id}>
                  <Link href={customerPath(l.id)}>{l.name}</Link> — {l.reason ?? '—'}
                  <div className="text-xs text-on-surface-variant">
                    {formatDateTime(l.at)} · {l.user ?? '—'}
                  </div>
                </li>
              ))}
            </ul>
          </Card>
        </div>
      )}
      {r && (
        <Card title="Hiệu suất theo người phụ trách">
          <Table head={['Người phụ trách', 'Số lead', 'Chốt', '% chốt', 'Doanh thu thực thu', 'Hoa hồng', 'Đánh giá']}>
            {r.reps.map((p) => (
              <tr key={p.user_id}>
                <Td>
                  <span className="font-medium">{p.name}</span>
                  <div className="text-xs text-on-surface-variant">{p.role}</div>
                </Td>
                <Td>{p.leads}</Td>
                <Td>{p.won}</Td>
                <Td>{p.rate}%</Td>
                <Td>{formatMoney(p.revenue)}</Td>
                <Td>{formatMoney(p.commission_amount)}</Td>
                <Td>{p.rating}</Td>
              </tr>
            ))}
          </Table>
          <p className="mt-2 text-xs text-on-surface-variant">Doanh thu thực thu và hoa hồng hiện cần phân hệ học phí của hệ thống mới (chưa gộp).</p>
        </Card>
      )}
    </div>
  )
}
