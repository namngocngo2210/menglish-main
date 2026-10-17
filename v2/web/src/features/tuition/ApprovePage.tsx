// Duyệt phiếu thu (Tuition/ApproveReceipt.vue): danh sách phiếu (mặc định Chờ duyệt) + khung chi tiết bên phải,
// đối soát SePay, cảnh báo trùng giao dịch, xuất kho dự kiến; Duyệt (cấp số hóa đơn) / Trả về kèm lý do; xác nhận
// tiền mặt đã nộp về TK công ty. Chỉ Admin duyệt / trả về (tuition.approve / tuition.reject là quyền admin_only).
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { errorMessage, type Me } from '../../api'
import { Alert, Button, Card, Checkbox, Field, Select } from '../../components/ui'
import { navigate, useQueryParam } from '../../router'
import { tuitionApi } from './api'
import { ReceiptView, Stat, Tag } from './components'
import { DEPOSIT, formatMoney } from './logic'

export function ApprovePage({ me }: { me: Me }) {
  void me
  const qc = useQueryClient()
  const selectedParam = useQueryParam('selected_id')
  const [status, setStatus] = useState('')
  const [branchId, setBranchId] = useState('')
  const [method, setMethod] = useState('')
  const [q, setQ] = useState('')
  const [search, setSearch] = useState('')
  const [selected, setSelected] = useState(selectedParam)
  const [notice, setNotice] = useState<{ tone: 'success' | 'error'; text: string } | null>(null)
  const [reason, setReason] = useState('')
  const [rejecting, setRejecting] = useState(false)
  const [notDuplicate, setNotDuplicate] = useState(false)

  const screen = useQuery({
    queryKey: ['tuition', 'approve', status, branchId, method, search, selected],
    queryFn: () => tuitionApi.approveScreen({ status, branch_id: branchId, payment_method: method, q: search, selected_id: selected }),
    placeholderData: keepPreviousData,
  })
  const d = screen.data
  const r = d?.selected ?? null

  const done = (text: string) => {
    setNotice({ tone: 'success', text })
    setRejecting(false)
    setReason('')
    setNotDuplicate(false)
    qc.invalidateQueries({ queryKey: ['tuition'] })
  }
  const fail = (e: unknown) => setNotice({ tone: 'error', text: errorMessage(e) })
  const approve = useMutation({ mutationFn: (id: string) => tuitionApi.approve(id, notDuplicate), onSuccess: (x) => done(x.message), onError: fail })
  const reject = useMutation({ mutationFn: (id: string) => tuitionApi.reject(id, reason), onSuccess: (x) => done(x.message), onError: fail })
  const deposit = useMutation({ mutationFn: (id: string) => tuitionApi.confirmDeposit(id), onSuccess: (x) => done(x.message), onError: fail })
  const busy = approve.isPending || reject.isPending || deposit.isPending

  const choose = (id: string) => {
    setSelected(id)
    setNotice(null)
    setRejecting(false)
    setNotDuplicate(false)
    navigate(`/tuition/receipts/approve?selected_id=${id}`)
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-xl font-semibold">Duyệt phiếu thu</h1>
        <p className="text-sm text-on-surface-variant">Đối chiếu chứng từ, sao kê rồi duyệt để cấp số hóa đơn và ghi nhận vào công nợ học viên.</p>
      </div>
      {screen.isError && <Alert>{errorMessage(screen.error)}</Alert>}
      {notice && <Alert tone={notice.tone}>{notice.text}</Alert>}
      {d && (
        <>
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <Stat label="Chờ duyệt" value={`${d.pending_count} phiếu`} tone="warning" />
            <Stat label="Tổng tiền chờ duyệt" value={formatMoney(d.pending_total)} />
            <Stat label="Đã duyệt hôm nay" value={`${d.approved_today_count} phiếu`} tone="success" />
            <Stat label="Trả về hôm nay" value={`${d.rejected_today_count} phiếu`} tone="error" />
          </div>
          <Card>
            <form
              className="grid gap-3 sm:grid-cols-[1fr_180px_180px_180px_auto] sm:items-end"
              onSubmit={(e) => {
                e.preventDefault()
                setSearch(q)
              }}
            >
              <Field label="Tìm kiếm" placeholder="Mã phiếu, học viên, mã giao dịch..." value={q} onChange={(e) => setQ(e.target.value)} />
              <Select label="Trạng thái" value={status || d.filters.status} onChange={(e) => setStatus(e.target.value)}>
                <option value="all">Tất cả</option>
                {d.statuses.map((s) => (
                  <option key={s.value} value={s.value}>
                    {s.label}
                  </option>
                ))}
              </Select>
              <Select label="Chi nhánh" value={branchId} onChange={(e) => setBranchId(e.target.value)}>
                <option value="">Tất cả chi nhánh</option>
                {d.branches.map((b) => (
                  <option key={b.value} value={b.value}>
                    {b.label}
                  </option>
                ))}
              </Select>
              <Select label="Hình thức" value={method} onChange={(e) => setMethod(e.target.value)}>
                <option value="">Tất cả</option>
                <option value="transfer">Chuyển khoản</option>
                <option value="vietqr">VietQR</option>
                <option value="cash">Tiền mặt</option>
              </Select>
              <Button type="submit" variant="secondary">
                Lọc
              </Button>
            </form>
          </Card>
          <div className="grid gap-6 lg:grid-cols-[1fr_420px]">
            <Card>
              {d.receipts.length === 0 ? (
                <p className="text-sm text-on-surface-variant">Không có phiếu thu nào khớp bộ lọc.</p>
              ) : (
                <ul className="divide-y divide-surface-container-highest">
                  {d.receipts.map((x) => (
                    <li key={x.id}>
                      <button
                        type="button"
                        aria-current={r?.id === x.id}
                        onClick={() => choose(x.id)}
                        className={`flex w-full flex-wrap items-start justify-between gap-2 px-2 py-3 text-left text-sm hover:bg-surface-container-low ${r?.id === x.id ? 'bg-surface-container-low' : ''}`}
                      >
                        <span>
                          <span className="font-mono font-semibold">{x.code}</span> <Tag tone={x.status_color}>{x.status_label}</Tag>
                          {x.deposit_state && (
                            <>
                              {' '}
                              <Tag tone={DEPOSIT[x.deposit_state].tone}>{DEPOSIT[x.deposit_state].label}</Tag>
                            </>
                          )}
                          <span className="block">
                            {x.student_name}
                            {x.student_code && <span className="ml-1 font-mono text-xs text-on-surface-variant">{x.student_code}</span>}
                          </span>
                          <span className="block text-xs text-on-surface-variant">
                            {x.class_name} · {x.branch_name} · {x.method_label}
                            {x.transaction_code && ` · ${x.transaction_code}`} · {x.created_at}
                            {x.creator_name && ` · ${x.creator_name}`}
                          </span>
                        </span>
                        <span className="font-mono font-semibold">{formatMoney(x.amount)}</span>
                      </button>
                    </li>
                  ))}
                </ul>
              )}
            </Card>
            <Card title="Chi tiết phiếu">
              {!r ? (
                <p className="text-sm text-on-surface-variant">Chọn một phiếu ở danh sách để xem chi tiết.</p>
              ) : (
                <div className="space-y-4">
                  <ReceiptView r={r} />
                  {r.status === 'pending' && (d.can_approve || d.can_reject) && (
                    <div className="space-y-3 border-t border-surface-container-highest pt-4">
                      {d.can_approve && r.payment_method !== 'cash' && (
                        <Checkbox label="Xác nhận không trùng giao dịch SePay" checked={notDuplicate} onChange={(e) => setNotDuplicate(e.target.checked)} />
                      )}
                      {rejecting ? (
                        <>
                          <Field label="Lý do trả về" value={reason} onChange={(e) => setReason(e.target.value)} placeholder="VD: Ảnh chuyển khoản mờ..." />
                          <div className="flex gap-2">
                            <Button variant="danger" disabled={busy} onClick={() => reject.mutate(r.id)}>
                              Xác nhận trả về
                            </Button>
                            <Button variant="secondary" onClick={() => setRejecting(false)}>
                              Hủy
                            </Button>
                          </div>
                        </>
                      ) : (
                        <div className="flex gap-2">
                          {d.can_approve && (
                            <Button disabled={busy} onClick={() => approve.mutate(r.id)}>
                              Duyệt & cấp hóa đơn
                            </Button>
                          )}
                          {d.can_reject && (
                            <Button variant="danger" disabled={busy} onClick={() => setRejecting(true)}>
                              Trả về
                            </Button>
                          )}
                        </div>
                      )}
                    </div>
                  )}
                  {r.can_confirm_deposit && (
                    <Button variant="secondary" disabled={busy} onClick={() => deposit.mutate(r.id)}>
                      Xác nhận đã nộp về TK công ty
                    </Button>
                  )}
                </div>
              )}
            </Card>
          </div>
        </>
      )}
    </div>
  )
}
