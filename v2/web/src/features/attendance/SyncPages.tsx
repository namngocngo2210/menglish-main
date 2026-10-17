// Lịch sử đồng bộ (Payroll/Timesheets/Sync.vue) và AppSheet (Payroll/Timesheets/AppSheet.vue).
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { errorMessage } from '../../api'
import { Alert, Badge, Button, Dialog, Field, Icon } from '../../components/ui'
import { EmptyState, Pager } from '../platform/parts'
import { attendanceApi, type SyncFilter, type SyncLog } from './api'
import { syncStyle } from './logic'
import { OptionSelect, TableCard, TD, TH } from './parts'

const EMPTY: SyncFilter = { from: '', to: '', status: '', page: '' }

export function SyncHistoryPage() {
  const [f, setF] = useState<SyncFilter>(EMPTY)
  const [errors, setErrors] = useState<SyncLog | null>(null)
  const set = (k: keyof SyncFilter, v: string) => setF((x) => ({ ...x, [k]: v, page: k === 'page' ? v : '' }))
  const page = useQuery({ queryKey: ['attendance', 'sync', f], queryFn: () => attendanceApi.sync(f), placeholderData: keepPreviousData })
  const d = page.data

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-xl font-semibold">Lịch sử đồng bộ</h1>
        <Button disabled title="Chưa kết nối máy chấm công">
          <Icon name="sync" className="mr-2" />
          Đồng bộ ngay
        </Button>
      </div>
      {page.isError && <Alert>{errorMessage(page.error)}</Alert>}
      {d && !d.has_any_log && (
        <Alert tone="info">
          Hệ thống chưa kết nối máy chấm công nào nên chưa có đợt đồng bộ. Giờ dạy hiện được ghi qua check-in của giáo viên, xác nhận theo lịch và chấm
          công tay.
        </Alert>
      )}
      {d && (
        <div className="grid gap-3 rounded-xl border border-surface-container-highest bg-surface p-4 shadow-sm sm:grid-cols-3">
          <Field label="Từ ngày" type="date" value={f.from} onChange={(e) => set('from', e.target.value)} />
          <Field label="Đến ngày" type="date" value={f.to} onChange={(e) => set('to', e.target.value)} />
          <OptionSelect label="Trạng thái" value={f.status} all="Tất cả trạng thái" options={d.status_options} onChange={(v) => set('status', v)} />
        </div>
      )}
      {d && (
        <TableCard footer={<Pager page={d.meta.page} perPage={d.meta.per_page} total={d.meta.total} unit="đợt đồng bộ" onPage={(p) => set('page', String(p))} />}>
          <thead className="bg-surface-container-low">
            <tr>
              <th className={TH}>Thời gian</th>
              <th className={TH}>Thiết bị</th>
              <th className={TH}>Chi nhánh</th>
              <th className={TH}>Bản ghi</th>
              <th className={TH}>Khớp</th>
              <th className={TH}>Lỗi</th>
              <th className={TH}>Bỏ qua</th>
              <th className={TH}>Trạng thái</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-surface-container-highest">
            {d.logs.map((l) => {
              const [icon, color] = syncStyle(l.status)
              return (
                <tr key={l.id}>
                  <td className={`${TD} whitespace-nowrap`}>{l.created_at}</td>
                  <td className={TD}>{l.device_name}</td>
                  <td className={TD}>{l.branch}</td>
                  <td className={TD}>{l.records_count}</td>
                  <td className={TD}>{l.matched_count}</td>
                  <td className={TD}>{l.failed_count}</td>
                  <td className={TD}>{l.skipped_count}</td>
                  <td className={TD}>
                    <span className={`inline-flex items-center gap-1 ${color}`}>
                      <Icon name={icon} className="text-base" />
                      {l.status_label}
                    </span>
                    {l.has_errors && (
                      <button type="button" className="block text-xs text-primary hover:underline" onClick={() => setErrors(l)}>
                        Xem lỗi
                      </button>
                    )}
                  </td>
                </tr>
              )
            })}
            {d.logs.length === 0 && (
              <tr>
                <td colSpan={8}>
                  <EmptyState icon="sync_disabled" title="Chưa có đợt đồng bộ nào" />
                </td>
              </tr>
            )}
          </tbody>
        </TableCard>
      )}
      {errors && (
        <Dialog title={`Lỗi đồng bộ · ${errors.device_name}`} onClose={() => setErrors(null)} wide>
          <div className="space-y-3 text-sm">
            {errors.error_message && (
              <Alert>
                {errors.error_code && <b>{errors.error_code}: </b>}
                {errors.error_message}
              </Alert>
            )}
            {errors.error_rows.length > 0 && (
              <ul className="max-h-80 divide-y divide-surface-container-highest overflow-y-auto">
                {errors.error_rows.map((r, i) => (
                  <li key={i} className="py-2">
                    <div className="font-medium">
                      {[r.employee_code, r.employee_name].filter(Boolean).join(' · ') || 'Không rõ nhân sự'}
                      {r.code && <Badge tone="error">{r.code}</Badge>}
                    </div>
                    <div className="text-on-surface-variant">{r.message}</div>
                  </li>
                ))}
              </ul>
            )}
            <div className="flex justify-end">
              <a
                href={attendanceApi.syncErrorsUrl(errors.id)}
                className="inline-flex h-10 items-center rounded-lg border border-outline-variant px-4 font-medium hover:bg-surface-container-low"
              >
                <Icon name="download" className="mr-2" />
                Xuất CSV
              </a>
            </div>
          </div>
        </Dialog>
      )}
    </div>
  )
}

// Ứng dụng AppSheet chấm công cũ (nhúng như Laravel).
export const APPSHEET_URL = 'https://www.appsheet.com/start/00cb153e-2abe-4a50-bc50-36f9be53a422'

export function AppSheetPage() {
  const [full, setFull] = useState(false)
  return (
    <div className={full ? 'fixed inset-0 z-50 flex flex-col bg-surface p-2' : 'space-y-4'}>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-xl font-semibold">AppSheet</h1>
        <div className="flex gap-2">
          <a
            href={APPSHEET_URL}
            target="_blank"
            rel="noopener noreferrer"
            className="inline-flex h-10 items-center rounded-lg border border-outline-variant px-4 text-sm font-medium hover:bg-surface-container-low"
          >
            <Icon name="open_in_new" className="mr-2" />
            Mở tab mới
          </a>
          <Button variant="secondary" onClick={() => setFull((x) => !x)}>
            <Icon name={full ? 'fullscreen_exit' : 'fullscreen'} className="mr-2" />
            {full ? 'Thu nhỏ' : 'Toàn màn hình'}
          </Button>
        </div>
      </div>
      <iframe
        title="AppSheet chấm công"
        src={APPSHEET_URL}
        className={`w-full rounded-xl border border-surface-container-highest ${full ? 'flex-1' : 'h-[75vh]'}`}
        allow="geolocation; camera"
      />
    </div>
  )
}
