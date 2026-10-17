// Phiếu KPI tháng (Kpi/Monthly.vue): mỗi nhân sự một dòng / phiếu, lọc kỳ lương / vai trò / cơ sở; bấm dòng mở phiếu trong
// hộp thoại để điền số liệu còn trống rồi Duyệt hoặc Không duyệt. Phiếu quý hiện ở cả 3 tháng của quý.
import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { errorMessage } from '../../api'
import { Alert, Badge, Button, Card, Select } from '../../components/ui'
import { kpiApi, type MonthlyFilter } from './api'
import { EvaluateDialog } from './EvaluateDialog'
import { statusTone } from './logic'

export function MonthlyPage() {
  const qc = useQueryClient()
  const [filter, setFilter] = useState<MonthlyFilter>({ period: '', role: '', branch_id: '', page: 1 })
  const [open, setOpen] = useState<{ userId: string; period: string; track: string } | null>(null)
  const [notice, setNotice] = useState('')
  const page = useQuery({ queryKey: ['kpi', 'monthly', filter], queryFn: () => kpiApi.monthly(filter), placeholderData: keepPreviousData })
  const d = page.data
  const pages = d ? Math.max(1, Math.ceil(d.total / d.per_page)) : 1
  const set = (patch: Partial<MonthlyFilter>) => setFilter({ ...(d?.filters ?? filter), ...patch, page: patch.page ?? 1 })

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-xl font-semibold">Phiếu KPI tháng</h1>
        <p className="text-sm text-on-surface-variant">
          Mỗi nhân sự một phiếu, tự tạo đầu tháng theo tiêu chí KPI của vai trò. Bấm vào một dòng để mở phiếu, điền số liệu còn trống rồi Duyệt hoặc Không
          duyệt.
        </p>
      </div>
      {notice && <Alert tone="success">{notice}</Alert>}
      {page.isError && <Alert>{errorMessage(page.error)}</Alert>}
      {d && (
        <Card>
          <div className="grid gap-3 sm:grid-cols-3">
            <Select label="Kỳ lương" value={d.filters.period} onChange={(e) => set({ period: e.target.value })}>
              {d.period_options.map((o) => (
                <option key={o.value} value={o.value}>
                  {o.label}
                </option>
              ))}
            </Select>
            <Select label="Vai trò" value={d.filters.role} onChange={(e) => set({ role: e.target.value })}>
              <option value="">Tất cả vai trò</option>
              {d.role_options.map((o) => (
                <option key={o.value} value={o.value}>
                  {o.label}
                </option>
              ))}
            </Select>
            <Select label="Cơ sở" value={d.filters.branch_id} onChange={(e) => set({ branch_id: e.target.value })}>
              <option value="">Tất cả cơ sở</option>
              {d.branch_options.map((o) => (
                <option key={o.value} value={o.value}>
                  {o.label}
                </option>
              ))}
            </Select>
          </div>
          <div className="mt-4 overflow-x-auto">
            <table className="w-full min-w-[720px] text-left text-sm">
              <thead className="text-xs text-on-surface-variant">
                <tr>
                  <th className="py-2 pr-4 font-medium">Nhân sự</th>
                  <th className="py-2 pr-4 font-medium">Vai trò</th>
                  <th className="py-2 pr-4 text-right font-medium">Tỉ lệ đạt</th>
                  <th className="py-2 pr-4 text-right font-medium">Thưởng KPI</th>
                  <th className="py-2 pr-4 font-medium">Trạng thái</th>
                </tr>
              </thead>
              <tbody>
                {d.staff.flatMap((s) =>
                  s.sheets.map((sh) => (
                    <tr
                      key={`${s.id}-${sh.track}`}
                      className="cursor-pointer border-t border-surface-container hover:bg-surface-container-low"
                      tabIndex={0}
                      onClick={() => setOpen({ userId: s.id, period: sh.period, track: sh.track })}
                      onKeyDown={(e) => e.key === 'Enter' && setOpen({ userId: s.id, period: sh.period, track: sh.track })}
                    >
                      <td className="py-2 pr-4">
                        <p className="font-semibold">{s.name}</p>
                        {s.branch && <p className="text-xs text-on-surface-variant">{s.branch}</p>}
                      </td>
                      <td className="py-2 pr-4 whitespace-nowrap">
                        {sh.role}
                        {sh.period_label && <p className="text-xs text-on-surface-variant">{sh.period_label}</p>}
                      </td>
                      <td className="py-2 pr-4 text-right font-mono whitespace-nowrap">{sh.rate_label}</td>
                      <td className="py-2 pr-4 text-right font-mono whitespace-nowrap">{sh.amount_label}</td>
                      <td className="py-2 pr-4">
                        <Badge tone={statusTone(sh.status_color)}>{sh.status_label}</Badge>
                      </td>
                    </tr>
                  )),
                )}
                {d.staff.length === 0 && (
                  <tr>
                    <td colSpan={5} className="py-8 text-center text-on-surface-variant">
                      <p className="font-medium text-on-surface">Không có phiếu KPI nào</p>
                      Chỉ nhân sự có vai trò đã có tiêu chí KPI (Cài đặt → Tiêu chí KPI) mới có phiếu.
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
                Trang {d.page}/{pages} · {d.total} phiếu
              </span>
              <Button variant="secondary" disabled={d.page >= pages} onClick={() => set({ page: d.page + 1 })}>
                Sau
              </Button>
            </div>
          )}
        </Card>
      )}
      {open && (
        <EvaluateDialog
          {...open}
          onClose={() => setOpen(null)}
          onDone={(msg) => {
            setNotice(msg)
            setOpen(null)
            qc.invalidateQueries({ queryKey: ['kpi'] })
          }}
        />
      )}
    </div>
  )
}
