// Lịch sử công của tôi (MyAttendance/History.vue): chọn tháng, thẻ tổng hợp, từng ngày chấm công và các ngày nghỉ đã duyệt.
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { errorMessage } from '../../api'
import { Alert, Badge, Card } from '../../components/ui'
import { EmptyState, StatCard } from '../platform/parts'
import { attendanceApi } from './api'
import { dateLabel, weekday } from './logic'
import { AppLink, MonthNav } from './parts'

export function HistoryPage() {
  const [month, setMonth] = useState('')
  const page = useQuery({ queryKey: ['attendance', 'history', month], queryFn: () => attendanceApi.history(month), placeholderData: keepPreviousData })
  const d = page.data

  return (
    <div className="mx-auto max-w-3xl space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-xl font-semibold">Lịch sử công của tôi</h1>
        <AppLink to="/cham-cong/don">Gửi đơn chấm công & nghỉ</AppLink>
      </div>
      {page.isError && <Alert>{errorMessage(page.error)}</Alert>}
      {d && (
        <>
          <MonthNav label={d.month_label} prev={d.prev_month} next={d.next_month} onChange={setMonth} />
          <div className="grid gap-3 sm:grid-cols-3">
            <StatCard label="Ngày đi làm" value={d.summary.days} icon="event_available" tone="primary" />
            <StatCard
              label="Đi muộn"
              value={`${d.summary.late_count} lần · ${d.summary.late_minutes} phút`}
              icon="schedule"
              tone={d.summary.late_count ? 'warning' : 'neutral'}
            />
            <StatCard label="Ngày nghỉ có phép" value={d.summary.leave_days} icon="beach_access" tone="success" />
          </div>
          {(d.summary.excused_late > 0 || d.summary.early_count > 0 || d.summary.missing_out > 0) && (
            <p className="text-sm text-on-surface-variant">
              {[
                d.summary.excused_late && `${d.summary.excused_late} lần đi muộn có đơn`,
                d.summary.early_count && `${d.summary.early_count} lần về sớm`,
                d.summary.missing_out && `${d.summary.missing_out} ngày chưa chấm ra`,
              ]
                .filter(Boolean)
                .join(' · ')}
            </p>
          )}
          <Card title="Chấm công trong tháng">
            {d.rows.length === 0 ? (
              <EmptyState icon="event_busy" title="Chưa có ngày công nào trong tháng này" />
            ) : (
              <ul className="divide-y divide-surface-container-highest">
                {d.rows.map((r) => (
                  <li key={r.id} className="flex flex-wrap items-center justify-between gap-2 py-2.5 text-sm">
                    <div>
                      <div className="font-medium">
                        {weekday(r.date)}, {dateLabel(r.date)}
                      </div>
                      <div className="text-on-surface-variant tabular-nums">
                        Vào {r.check_in ?? '--:--'} · Ra {r.check_out ?? '--:--'}
                        {r.expected_start && ` · Giờ vào ${r.expected_start}`}
                        {r.source === 'request' && ' · Theo đơn bổ sung'}
                      </div>
                      {r.penalty && <div className="text-xs text-warning">Biên bản {r.penalty.code}</div>}
                    </div>
                    <Badge tone={r.status_tone}>{r.status_label}</Badge>
                  </li>
                ))}
              </ul>
            )}
          </Card>
          {d.leaves.length > 0 && (
            <Card title="Ngày nghỉ đã duyệt">
              <ul className="divide-y divide-surface-container-highest">
                {d.leaves.map((l) => (
                  <li key={l.id} className="py-2.5 text-sm">
                    <div className="font-medium">{l.period}</div>
                    <div className="text-on-surface-variant">{l.reason}</div>
                  </li>
                ))}
              </ul>
            </Card>
          )}
        </>
      )}
    </div>
  )
}
