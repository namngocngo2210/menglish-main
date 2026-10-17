// Chấm công hằng ngày (StaffAttendance/Index.vue + Show.vue): "Theo ngày" (giờ vào / ra, ảnh, khoảng cách, trạng thái, nghỉ
// phép) và "Tổng hợp tháng" của nhân sự trong phạm vi chi nhánh; bấm dòng để xem chi tiết ảnh + vị trí.
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { errorMessage } from '../../api'
import { Alert, Badge, Dialog, Field } from '../../components/ui'
import { EmptyState, Pager, StatCard } from '../platform/parts'
import { attendanceApi, type DailyFilter, type PunchDetail } from './api'
import { dateLabel, num } from './logic'
import { AppLink, OptionSelect, TableCard, TD, TH } from './parts'

const EMPTY: DailyFilter = { view: 'day', date: '', month: '', branch_id: '', status: '', search: '', page: '' }

export function DailyPage() {
  const [f, setF] = useState<DailyFilter>(EMPTY)
  const [detail, setDetail] = useState('')
  const set = (k: keyof DailyFilter, v: string) => setF((x) => ({ ...x, [k]: v, page: k === 'page' ? v : '' }))
  const page = useQuery({ queryKey: ['attendance', 'daily', f], queryFn: () => attendanceApi.daily(f), placeholderData: keepPreviousData })
  const d = page.data
  const month = f.view === 'month'

  return (
    <div className="space-y-4">
      <h1 className="text-xl font-semibold">Chấm công hằng ngày</h1>
      <div className="inline-flex rounded-lg border border-outline-variant bg-surface p-1" role="tablist">
        {[
          ['day', 'Theo ngày'],
          ['month', 'Tổng hợp tháng'],
        ].map(([v, label]) => (
          <button
            key={v}
            type="button"
            role="tab"
            aria-selected={f.view === v}
            onClick={() => set('view', v)}
            className={`rounded-md px-4 py-1.5 text-sm font-medium ${f.view === v ? 'bg-primary text-white' : 'text-on-surface-variant hover:text-on-surface'}`}
          >
            {label}
          </button>
        ))}
      </div>
      {page.isError && <Alert>{errorMessage(page.error)}</Alert>}
      {d && d.unconfigured.length > 0 && (
        <Alert tone="info">
          Cơ sở chưa cài toạ độ chấm công: {d.unconfigured.join(', ')}. Vào Cài đặt → <AppLink to="/branches">Cơ sở & chi nhánh</AppLink> để cài.
        </Alert>
      )}
      {d?.stats && (
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
          <StatCard label="Nhân sự" value={d.stats.staff} icon="groups" />
          <StatCard label="Đã chấm công" value={d.stats.checked_in} icon="how_to_reg" tone="success" />
          <StatCard label="Đi muộn" value={d.stats.late} icon="schedule" tone="warning" />
          <StatCard label="Nghỉ phép" value={d.stats.leave} icon="beach_access" tone="primary" />
        </div>
      )}
      {d && (
        <div className="grid gap-3 rounded-xl border border-surface-container-highest bg-surface p-4 shadow-sm sm:grid-cols-2 lg:grid-cols-4">
          {month ? (
            <Field label="Tháng" type="month" value={f.month || d.month} onChange={(e) => set('month', e.target.value)} />
          ) : (
            <Field label="Ngày" type="date" value={f.date || d.date} onChange={(e) => set('date', e.target.value)} />
          )}
          {d.branches.length > 1 && <OptionSelect label="Cơ sở" value={f.branch_id} all="Tất cả cơ sở" options={d.branches} onChange={(v) => set('branch_id', v)} />}
          {!month && <OptionSelect label="Trạng thái" value={f.status} all="Tất cả trạng thái" options={d.statuses} onChange={(v) => set('status', v)} />}
          <Field label="Tìm nhân sự" placeholder="Tên, mã nhân viên" value={f.search} onChange={(e) => set('search', e.target.value)} />
        </div>
      )}
      {d && (
        <TableCard footer={<Pager page={d.meta.page} perPage={d.meta.per_page} total={d.meta.total} unit="nhân sự" onPage={(p) => set('page', String(p))} />}>
          <thead className="bg-surface-container-low">
            {month ? (
              <tr>
                <th className={TH}>Nhân sự</th>
                <th className={TH}>Ngày công</th>
                <th className={TH}>Đi muộn</th>
                <th className={TH}>Có đơn</th>
                <th className={TH}>Về sớm</th>
                <th className={TH}>Thiếu giờ ra</th>
                <th className={TH}>Nghỉ phép</th>
              </tr>
            ) : (
              <tr>
                <th className={TH}>Nhân sự</th>
                <th className={TH}>Giờ phải có mặt</th>
                <th className={TH}>Vào</th>
                <th className={TH}>Ra</th>
                <th className={TH}>Khoảng cách</th>
                <th className={TH}>Trạng thái</th>
              </tr>
            )}
          </thead>
          <tbody className="divide-y divide-surface-container-highest">
            {d.rows.map((r) => {
              const who = (
                <td className={TD}>
                  <div className="font-medium">{r.user.name}</div>
                  <div className="text-xs text-on-surface-variant">
                    {r.user.code}
                    {r.user.branch && ` · ${r.user.branch}`}
                  </div>
                </td>
              )
              if (month) {
                const s = r.summary
                return (
                  <tr key={r.user.id}>
                    {who}
                    <td className={TD}>{s?.days ?? 0}</td>
                    <td className={TD}>{s?.late_count ? `${s.late_count} lần · ${s.late_minutes} phút` : '0'}</td>
                    <td className={TD}>{s?.excused_late ?? 0}</td>
                    <td className={TD}>{s?.early_count ?? 0}</td>
                    <td className={TD}>{s?.missing_out ?? 0}</td>
                    <td className={TD}>{s?.leave_days ?? 0}</td>
                  </tr>
                )
              }
              const a = r.attendance
              return (
                <tr
                  key={r.user.id}
                  className={a ? 'cursor-pointer hover:bg-surface-container-low' : ''}
                  onClick={() => a && setDetail(a.id)}
                  tabIndex={a ? 0 : undefined}
                  onKeyDown={(e) => a && e.key === 'Enter' && setDetail(a.id)}
                >
                  {who}
                  <td className={TD}>{a?.expected || '—'}</td>
                  <td className={TD}>
                    <span className="inline-flex items-center gap-2 tabular-nums">
                      {a?.check_in_photo && <img src={a.check_in_photo} alt="" className="size-8 rounded object-cover" />}
                      {a?.check_in ?? '—'}
                    </span>
                  </td>
                  <td className={`${TD} tabular-nums`}>{a?.check_out ?? '—'}</td>
                  <td className={TD}>{a?.check_in_distance != null ? `${num(a.check_in_distance)} m` : '—'}</td>
                  <td className={TD}>
                    {a ? (
                      <div className="space-y-1">
                        <Badge tone={a.status_tone}>{a.status_label}</Badge>
                        {a.source === 'request' && <div className="text-xs text-on-surface-variant">Theo đơn bổ sung</div>}
                        {a.penalty && <div className="text-xs text-warning">Biên bản {a.penalty}</div>}
                      </div>
                    ) : r.leave ? (
                      <Badge tone="info">Nghỉ phép · {r.leave}</Badge>
                    ) : (
                      <Badge>Chưa chấm công</Badge>
                    )}
                  </td>
                </tr>
              )
            })}
            {d.rows.length === 0 && (
              <tr>
                <td colSpan={7}>
                  <EmptyState icon="person_off" title="Không có nhân sự nào khớp bộ lọc" />
                </td>
              </tr>
            )}
          </tbody>
        </TableCard>
      )}
      {detail && <DetailDialog id={detail} onClose={() => setDetail('')} />}
    </div>
  )
}

function DetailDialog({ id, onClose }: { id: string; onClose: () => void }) {
  const q = useQuery({ queryKey: ['attendance', 'detail', id], queryFn: () => attendanceApi.detail(id) })
  const d = q.data
  return (
    <Dialog title="Chi tiết chấm công" onClose={onClose} wide>
      {q.isError && <Alert>{errorMessage(q.error)}</Alert>}
      {d && (
        <div className="space-y-4 text-sm">
          <div className="flex flex-wrap items-start justify-between gap-2">
            <div>
              <div className="font-semibold">
                {d.user} · {d.code}
              </div>
              <div className="text-on-surface-variant">
                {dateLabel(d.date)}
                {d.branch && ` · ${d.branch}`}
                {d.radius != null && ` (bán kính ${d.radius} m)`}
              </div>
              {(d.expected_start || d.expected_end) && (
                <div className="text-on-surface-variant">
                  Giờ phải có mặt: {d.expected_start ?? '—'} – {d.expected_end ?? '—'}
                </div>
              )}
            </div>
            <Badge tone={d.status_tone}>{d.status_label}</Badge>
          </div>
          <div className="grid gap-3 sm:grid-cols-2">
            <PunchBox label="Chấm công vào" p={d.in} />
            <PunchBox label="Chấm công ra" p={d.out} />
          </div>
          {d.source === 'request' && <p className="text-on-surface-variant">Công được bổ sung theo đơn đã duyệt.</p>}
          {d.note && <p>Ghi chú: {d.note}</p>}
          {d.penalty && (
            <p>
              Biên bản đi muộn: <AppLink to={d.penalty.url}>{d.penalty.code}</AppLink>
            </p>
          )}
        </div>
      )}
    </Dialog>
  )
}

function PunchBox({ label, p }: { label: string; p: PunchDetail | null }) {
  return (
    <div className="rounded-lg border border-surface-container-highest p-3">
      <div className="mb-2 font-medium">{label}</div>
      {!p ? (
        <p className="text-on-surface-variant">Chưa chấm.</p>
      ) : (
        <div className="space-y-2">
          {p.photo ? (
            <a href={p.photo} target="_blank" rel="noopener noreferrer">
              <img src={p.photo} alt={`Ảnh ${label.toLocaleLowerCase('vi')}`} className="aspect-square w-full rounded-lg object-cover" />
            </a>
          ) : (
            <p className="text-on-surface-variant">Không có ảnh.</p>
          )}
          <div className="tabular-nums">Lúc {p.time}</div>
          {p.distance != null && (
            <div className="text-on-surface-variant">
              Cách cơ sở {num(p.distance)} m{p.accuracy != null && ` · sai số ±${num(p.accuracy)} m`}
            </div>
          )}
          {p.map && (
            <a href={p.map} target="_blank" rel="noopener noreferrer" className="text-primary hover:underline">
              Xem vị trí trên bản đồ
            </a>
          )}
        </div>
      )}
    </div>
  )
}
