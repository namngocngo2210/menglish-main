// Cần duyệt › Đơn chấm công & nghỉ: Admin duyệt / từ chối đơn của nhân sự. Laravel đưa các đơn này vào hộp duyệt chung
// (approvals.index); v2 tạm có trang riêng.
// TODO(gộp): đưa vào hộp "Cần duyệt" chung khi phân hệ Phê duyệt có ở v2.
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { errorMessage } from '../../api'
import { Alert, Badge, Button, Dialog } from '../../components/ui'
import { formatDateTime } from '../platform/format'
import { EmptyState } from '../platform/parts'
import { attendanceApi, type PendingItem } from './api'
import { REQUEST_ICONS } from './logic'
import { TableCard, TD, TH } from './parts'

export function ApprovalsPage() {
  const qc = useQueryClient()
  const page = useQuery({ queryKey: ['attendance', 'approvals'], queryFn: attendanceApi.pending })
  const [notice, setNotice] = useState('')
  const [rejecting, setRejecting] = useState<PendingItem | null>(null)
  const done = (r: { message: string }) => {
    setNotice(r.message)
    qc.invalidateQueries({ queryKey: ['attendance'] })
  }
  const approve = useMutation({ mutationFn: attendanceApi.approve, onSuccess: done })
  const items = page.data?.items ?? []

  return (
    <div className="space-y-4">
      <h1 className="text-xl font-semibold">Đơn chấm công & nghỉ chờ duyệt</h1>
      {notice && <Alert tone="success">{notice}</Alert>}
      {(page.isError || approve.isError) && <Alert>{errorMessage(page.error ?? approve.error)}</Alert>}
      {page.data && (
        <TableCard>
          <thead className="bg-surface-container-low">
            <tr>
              <th className={TH}>Nhân sự</th>
              <th className={TH}>Loại đơn</th>
              <th className={TH}>Thời gian</th>
              <th className={TH}>Lý do</th>
              <th className={TH}>Công hiện tại</th>
              <th className={`${TH} text-right`}>Thao tác</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-surface-container-highest">
            {items.map((r) => (
              <tr key={r.id}>
                <td className={TD}>
                  <div className="font-medium">{r.user_name}</div>
                  <div className="text-xs text-on-surface-variant">
                    {r.user_code}
                    {r.branch && ` · ${r.branch}`}
                  </div>
                </td>
                <td className={TD}>
                  <span className="inline-flex items-center gap-1">
                    <span className="material-symbols-outlined text-base text-primary" aria-hidden="true">
                      {REQUEST_ICONS[r.type] ?? 'description'}
                    </span>
                    {r.type_label}
                  </span>
                </td>
                <td className={TD}>
                  <div>{r.period}</div>
                  <div className="text-xs text-on-surface-variant">Gửi {formatDateTime(r.created_at)}</div>
                </td>
                <td className={`${TD} max-w-xs`}>{r.reason}</td>
                <td className={TD}>{r.current_punch ? r.current_punch : <Badge>Chưa có</Badge>}</td>
                <td className={`${TD} text-right whitespace-nowrap`}>
                  <Button className="mr-2 h-8 px-3 text-xs" disabled={approve.isPending} onClick={() => approve.mutate(r.id)}>
                    Duyệt
                  </Button>
                  <Button variant="danger" className="h-8 px-3 text-xs" onClick={() => setRejecting(r)}>
                    Từ chối
                  </Button>
                </td>
              </tr>
            ))}
            {items.length === 0 && (
              <tr>
                <td colSpan={6}>
                  <EmptyState icon="task_alt" title="Không có đơn nào chờ duyệt" />
                </td>
              </tr>
            )}
          </tbody>
        </TableCard>
      )}
      {rejecting && (
        <RejectDialog
          item={rejecting}
          onClose={() => setRejecting(null)}
          onDone={(r) => {
            setRejecting(null)
            done(r)
          }}
        />
      )}
    </div>
  )
}

function RejectDialog({ item, onClose, onDone }: { item: PendingItem; onClose: () => void; onDone: (r: { message: string }) => void }) {
  const [reason, setReason] = useState('')
  const reject = useMutation({ mutationFn: () => attendanceApi.reject(item.id, reason), onSuccess: onDone })
  return (
    <Dialog title="Từ chối đơn" onClose={onClose}>
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault()
          reject.mutate()
        }}
      >
        <p className="text-sm">
          {item.type_label} · {item.period} của <b>{item.user_name}</b>
        </p>
        <div className="space-y-1.5">
          <label htmlFor="att-reject" className="block text-sm font-medium">
            Lý do từ chối
          </label>
          <textarea
            id="att-reject"
            required
            maxLength={1000}
            rows={3}
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            className="block w-full rounded-lg border border-outline-variant px-3 py-2 text-sm outline-none focus:border-primary focus:ring-2 focus:ring-primary/25"
          />
        </div>
        {reject.isError && <Alert>{errorMessage(reject.error)}</Alert>}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            Hủy
          </Button>
          <Button type="submit" variant="danger" disabled={reject.isPending}>
            Từ chối đơn
          </Button>
        </div>
      </form>
    </Dialog>
  )
}
