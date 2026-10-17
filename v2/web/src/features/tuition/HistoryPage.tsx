// Lịch sử thu (Tuition/History.vue): mọi phiếu thu trong phạm vi, lọc trạng thái / hình thức / ngày / gia hạn,
// tổng tiền theo bộ lọc, xuất CSV; bấm dòng xem chi tiết; người lập sửa phiếu nháp / bị trả về; kế toán xác nhận
// tiền mặt đã nộp về TK công ty.
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { errorMessage, type Me } from '../../api'
import { Alert, Button, Card, Field, Select } from '../../components/ui'
import { navigate, useQueryParam } from '../../router'
import { tuitionApi } from './api'
import { Modal, Pager, ReceiptView, Tag } from './components'
import { DEPOSIT, formatMoney } from './logic'

export function HistoryPage({ me }: { me: Me }) {
  void me
  const qc = useQueryClient()
  const initialSearch = useQueryParam('search')
  const flash = useQueryParam('notice')
  const [q, setQ] = useState(initialSearch)
  const [filters, setFilters] = useState({ search: initialSearch, status: '', method: '', from: '', to: '', kind: '' })
  const [page, setPage] = useState(1)
  const [open, setOpen] = useState<string | null>(null)
  const [notice, setNotice] = useState(flash)

  const list = useQuery({
    queryKey: ['tuition', 'history', filters, page],
    queryFn: () => tuitionApi.history({ ...filters, page }),
    placeholderData: keepPreviousData,
  })
  const detail = useQuery({ queryKey: ['tuition', 'receipt', open], queryFn: () => tuitionApi.receipt(open!), enabled: !!open })
  const deposit = useMutation({
    mutationFn: (id: string) => tuitionApi.confirmDeposit(id),
    onSuccess: (x) => {
      setNotice(x.message)
      qc.invalidateQueries({ queryKey: ['tuition'] })
    },
    onError: (e) => setNotice(errorMessage(e)),
  })
  const set = (k: keyof typeof filters, v: string) => {
    setPage(1)
    setFilters((f) => ({ ...f, [k]: v }))
  }
  const d = list.data

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold">Lịch sử thu</h1>
          <p className="text-sm text-on-surface-variant">Toàn bộ phiếu thu học phí & phụ thu: trạng thái duyệt, số hóa đơn, tiền mặt đã nộp về công ty.</p>
        </div>
        <a
          className="inline-flex h-10 items-center rounded-lg border border-outline-variant px-4 text-sm font-medium hover:bg-surface-container-low"
          href={tuitionApi.exportUrl(filters)}
        >
          Xuất CSV
        </a>
      </div>
      {notice && <Alert tone="success">{notice}</Alert>}
      {list.isError && <Alert>{errorMessage(list.error)}</Alert>}
      <Card>
        <form
          className="grid gap-3 sm:grid-cols-3 lg:grid-cols-[1fr_160px_160px_150px_150px_160px_auto] lg:items-end"
          onSubmit={(e) => {
            e.preventDefault()
            set('search', q)
          }}
        >
          <Field label="Tìm kiếm" placeholder="Mã phiếu, số HĐ, học viên, mã GD..." value={q} onChange={(e) => setQ(e.target.value)} />
          <Select label="Trạng thái" value={filters.status} onChange={(e) => set('status', e.target.value)}>
            <option value="">Tất cả</option>
            {d?.statuses.map((s) => (
              <option key={s.value} value={s.value}>
                {s.label}
              </option>
            ))}
          </Select>
          <Select label="Hình thức" value={filters.method} onChange={(e) => set('method', e.target.value)}>
            <option value="">Tất cả</option>
            {d?.methods.map((s) => (
              <option key={s.value} value={s.value}>
                {s.label}
              </option>
            ))}
          </Select>
          <Field label="Từ ngày" type="date" value={filters.from} onChange={(e) => set('from', e.target.value)} />
          <Field label="Đến ngày" type="date" value={filters.to} onChange={(e) => set('to', e.target.value)} />
          <Select label="Loại phiếu" value={filters.kind} onChange={(e) => set('kind', e.target.value)}>
            <option value="">Tất cả</option>
            <option value="renewal">Gia hạn / khóa kế tiếp</option>
          </Select>
          <Button type="submit" variant="secondary">
            Lọc
          </Button>
        </form>
      </Card>
      {d && (
        <Card title={`Tổng tiền theo bộ lọc: ${formatMoney(d.amount_total)}`}>
          {d.receipts.length === 0 ? (
            <p className="text-sm text-on-surface-variant">Chưa có phiếu thu nào khớp bộ lọc.</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full min-w-[960px] text-left text-sm">
                <thead className="text-xs text-on-surface-variant">
                  <tr>
                    <th className="py-2 pr-3 font-medium">Mã phiếu</th>
                    <th className="py-2 pr-3 font-medium">Học viên</th>
                    <th className="py-2 pr-3 font-medium">Khoản thu</th>
                    <th className="py-2 pr-3 text-right font-medium">Số tiền</th>
                    <th className="py-2 pr-3 font-medium">Hình thức</th>
                    <th className="py-2 pr-3 font-medium">Ngày thu</th>
                    <th className="py-2 pr-3 font-medium">Trạng thái</th>
                    <th className="py-2 font-medium" />
                  </tr>
                </thead>
                <tbody className="divide-y divide-surface-container-highest">
                  {d.receipts.map((x) => (
                    <tr
                      key={x.id}
                      tabIndex={0}
                      className="cursor-pointer hover:bg-surface-container-low"
                      onClick={() => setOpen(x.id)}
                      onKeyDown={(e) => e.key === 'Enter' && e.target === e.currentTarget && setOpen(x.id)}
                    >
                      <td className="py-2 pr-3">
                        <div className="font-mono font-semibold">{x.code}</div>
                        {x.invoice_number && <div className="font-mono text-xs text-on-surface-variant">HĐ {x.invoice_number}</div>}
                      </td>
                      <td className="py-2 pr-3">
                        <div>{x.student_name}</div>
                        <div className="text-xs text-on-surface-variant">
                          {x.class_name} · {x.branch_name}
                        </div>
                      </td>
                      <td className="py-2 pr-3">{x.fee_label ?? '—'}</td>
                      <td className="py-2 pr-3 text-right font-mono whitespace-nowrap">
                        {formatMoney(x.amount)}
                        {x.has_surcharge && <div className="text-xs text-on-surface-variant">phụ thu {formatMoney(x.surcharge_amount)}</div>}
                      </td>
                      <td className="py-2 pr-3">
                        {x.method_label}
                        {x.transaction_code && <div className="font-mono text-xs text-on-surface-variant">{x.transaction_code}</div>}
                      </td>
                      <td className="py-2 pr-3 font-mono text-xs whitespace-nowrap">{x.payment_date ?? x.created_at}</td>
                      <td className="py-2 pr-3">
                        <Tag tone={x.status_color}>{x.status_label}</Tag>
                        {x.deposit_state && (
                          <div className="mt-1">
                            <Tag tone={DEPOSIT[x.deposit_state].tone}>{DEPOSIT[x.deposit_state].label}</Tag>
                          </div>
                        )}
                      </td>
                      <td className="py-2 text-right whitespace-nowrap" onClick={(e) => e.stopPropagation()}>
                        {x.can_confirm_deposit && (
                          <Button variant="secondary" className="h-8 px-2" disabled={deposit.isPending} onClick={() => deposit.mutate(x.id)}>
                            Đã nộp TK công ty
                          </Button>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
          <Pager page={d.page} unit="phiếu thu" onPage={setPage} />
        </Card>
      )}
      {open && (
        <Modal title="Chi tiết phiếu thu" onClose={() => setOpen(null)}>
          {detail.isError && <Alert>{errorMessage(detail.error)}</Alert>}
          {detail.data && (
            <div className="space-y-4">
              <ReceiptView r={detail.data} />
              {detail.data.can_resubmit && (
                <Button onClick={() => navigate(`/tuition/receipts/${detail.data!.id}/edit`)}>Sửa & gửi duyệt lại</Button>
              )}
            </div>
          )}
        </Modal>
      )}
    </div>
  )
}
