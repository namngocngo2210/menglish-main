// Bảng KPI tự động (Tasks/KpiDashboard.vue, KpiBoardService): chỉ số giữ chân / chuyên cần / bài tập / công việc trong kỳ.
// Phạm vi Công việc "Của tôi" → chỉ KPI của mình; "Chi nhánh" → nhân sự chi nhánh mình.
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { errorMessage } from '../../api'
import { Alert, Button, Card, Field, Icon, Select } from '../../components/ui'
import { kpiApi, type BoardFilter, type BoardRow } from './api'
import { percent1 } from './logic'

// Cột tỉ lệ và ngưỡng tô đỏ.
const COLUMNS: [keyof BoardRow & ('retention' | 'attendance' | 'homework' | 'tasks'), number][] = [
  ['retention', 80],
  ['attendance', 85],
  ['homework', 70],
  ['tasks', 80],
]

export function KpiBoardPage() {
  const [filter, setFilter] = useState<BoardFilter>({ month: '', month_to: '', user_id: '', page: 1 })
  const page = useQuery({ queryKey: ['kpi', 'board', filter], queryFn: () => kpiApi.board(filter), placeholderData: keepPreviousData })
  const d = page.data
  const pages = d ? Math.max(1, Math.ceil(d.total / d.per_page)) : 1
  const current: BoardFilter = d ? { month: d.month, month_to: d.month_to, user_id: d.user_id ?? '', page: d.page } : filter
  const set = (patch: Partial<BoardFilter>) => setFilter({ ...current, ...patch, page: patch.page ?? 1 })

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold">Bảng KPI tự động</h1>
          <p className="text-sm text-on-surface-variant">
            Theo dõi các chỉ số hiệu suất chính của nhân sự giảng dạy, tính tự động từ điểm danh, bài tập và công việc.
          </p>
        </div>
        {d && (
          <a
            className="inline-flex h-10 items-center gap-1 rounded-lg border border-outline-variant px-4 text-sm font-medium hover:bg-surface-container-low"
            href={kpiApi.boardExportUrl(current)}
          >
            <Icon name="download" className="text-[18px]" /> Xuất Excel KPI
          </a>
        )}
      </div>
      {page.isError && <Alert>{errorMessage(page.error)}</Alert>}
      {d?.pending && <Alert tone="info">{d.pending}</Alert>}
      {d && (
        <>
          <Card>
            <div className="grid gap-3 sm:grid-cols-4 sm:items-end">
              {d.can_see_staff && (
                <Select label="Chọn nhân sự" value={d.user_id ?? ''} onChange={(e) => set({ user_id: e.target.value })}>
                  <option value="">Tất cả nhân sự</option>
                  {d.staff_options.map((o) => (
                    <option key={o.value} value={o.value}>
                      {o.label}
                    </option>
                  ))}
                </Select>
              )}
              <Field label="Từ tháng" type="month" value={d.month} onChange={(e) => set({ month: e.target.value })} />
              <Field label="Đến tháng" type="month" value={d.month_to} onChange={(e) => set({ month_to: e.target.value })} />
              <span className="self-center text-sm text-on-surface-variant">{d.period_label}</span>
            </div>
          </Card>
          {!d.can_see_staff && <Alert tone="info">Bạn đang xem KPI của chính mình. KPI toàn bộ nhân sự chỉ dành cho người có quyền duyệt công việc.</Alert>}
          <Card>
            <div className="overflow-x-auto">
              <table className="w-full min-w-[860px] text-left text-sm">
                <thead className="text-xs text-on-surface-variant">
                  <tr>
                    <th className="py-2 pr-4 font-medium">Nhân sự</th>
                    <th className="py-2 pr-4 text-center font-medium">Lớp phụ trách</th>
                    <th className="py-2 pr-4 text-right font-medium">Tỷ lệ giữ chân học viên</th>
                    <th className="py-2 pr-4 text-right font-medium">Tỷ lệ chuyên cần</th>
                    <th className="py-2 pr-4 text-right font-medium">Tỷ lệ hoàn thành bài tập</th>
                    <th className="py-2 pr-4 text-right font-medium">Hoàn thành công việc</th>
                  </tr>
                </thead>
                <tbody>
                  {d.staff.map((row) => (
                    <tr key={row.id} className="border-t border-surface-container">
                      <td className="py-2 pr-4">
                        <div className="font-semibold">{row.name}</div>
                        <div className="font-mono text-xs text-on-surface-variant">{row.code}</div>
                      </td>
                      <td className="py-2 pr-4 text-center font-mono">{row.classes}</td>
                      {COLUMNS.map(([key, threshold]) => {
                        const v = row[key]
                        return (
                          <td key={key} className="py-2 pr-4 text-right">
                            {v === null ? (
                              <span
                                className="text-xs text-on-surface-variant italic"
                                title={key === 'homework' ? d.homework_pending : 'Không có dữ liệu trong kỳ'}
                              >
                                Chưa có dữ liệu
                              </span>
                            ) : (
                              <>
                                <span className={`font-mono font-semibold ${v < threshold ? 'text-error' : ''}`}>{percent1(v)}</span>
                                <span className="block text-xs text-on-surface-variant">{row[`${key}_detail`]}</span>
                              </>
                            )}
                          </td>
                        )
                      })}
                    </tr>
                  ))}
                  {d.staff.length === 0 && (
                    <tr>
                      <td colSpan={6} className="py-8 text-center text-on-surface-variant">
                        <p className="font-medium text-on-surface">Chưa có nhân sự để tính KPI</p>
                        Bảng KPI theo dõi giáo viên, trợ giảng và học vụ đang hoạt động.
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
            {pages > 1 && (
              <div className="mt-3 flex items-center justify-end gap-2 text-sm">
                <Button variant="secondary" disabled={d.page <= 1} onClick={() => set({ page: d.page - 1 })}>
                  Trước
                </Button>
                <span>
                  Trang {d.page}/{pages} · {d.total} nhân sự
                </span>
                <Button variant="secondary" disabled={d.page >= pages} onClick={() => set({ page: d.page + 1 })}>
                  Sau
                </Button>
              </div>
            )}
            <p className="mt-3 text-xs text-on-surface-variant">
              Chuyên cần = lượt có mặt/đi muộn ÷ lượt điểm danh trong kỳ (lớp phụ trách). Bài tập = bài học viên nộp ÷ (bài giao có hạn trong kỳ × sĩ số). Công
              việc = việc có hạn trong kỳ đã hoàn thành. Giữ chân = học viên chưa thôi học ÷ học viên đã vào lớp.
            </p>
          </Card>
        </>
      )}
    </div>
  )
}
