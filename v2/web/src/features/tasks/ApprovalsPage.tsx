// Xác nhận hoàn thành thủ công (tasks.manual-approvals): việc "Chờ xác nhận" không ảnh minh chứng — chỉ Admin xác nhận /
// trả lại (không tự duyệt việc của mình). Báo cáo trực lớp chờ xác nhận: chờ module lớp.
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { errorMessage, type Me } from '../../api'
import { Alert, Button, Card, Dialog, Field, Select } from '../../components/ui'
import { tasksApi, type ApprovalItem } from './api'
import { PageHeader, StatusBadge, TextArea } from './components'

export function ApprovalsPage(_: { me: Me }) {
  const [draft, setDraft] = useState({ q: '', assignee_id: '' })
  const [filters, setFilters] = useState(draft)
  const [selected, setSelected] = useState<ApprovalItem | null>(null)
  const [notice, setNotice] = useState('')
  const list = useQuery({ queryKey: ['tasks', 'approvals', filters], queryFn: () => tasksApi.approvals(filters.q, filters.assignee_id) })

  return (
    <>
      <PageHeader
        title="Xác nhận hoàn thành thủ công"
        description="Danh sách các đầu việc chờ xác nhận: báo cáo không đính kèm ảnh minh chứng cần Admin xác nhận."
      />
      {notice && (
        <div className="mb-4">
          <Alert tone="success">{notice}</Alert>
        </div>
      )}
      <Card>
        <form
          className="mb-4 grid gap-3 sm:grid-cols-[1fr_16rem_auto] sm:items-end"
          onSubmit={(e) => {
            e.preventDefault()
            setFilters(draft)
          }}
        >
          <Field label="Tìm kiếm" placeholder="Đầu việc, trợ giảng..." value={draft.q} onChange={(e) => setDraft({ ...draft, q: e.target.value })} />
          <Select label="Người thực hiện" value={draft.assignee_id} onChange={(e) => setDraft({ ...draft, assignee_id: e.target.value })}>
            <option value="">Tất cả người thực hiện</option>
            {list.data?.assignee_options.map((o) => (
              <option key={o.id} value={o.id}>
                {o.label}
              </option>
            ))}
          </Select>
          <Button type="submit" variant="secondary">
            Lọc
          </Button>
        </form>
        {list.isError && <Alert>{errorMessage(list.error)}</Alert>}
        {list.data && !list.data.can_approve && <Alert tone="info">Chỉ Admin được xác nhận / trả lại công việc.</Alert>}
        {list.data && (
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead className="text-xs text-on-surface-variant">
                <tr>
                  <th className="py-2 pr-4 font-medium">Đầu việc</th>
                  <th className="py-2 pr-4 font-medium">Người thực hiện</th>
                  <th className="py-2 pr-4 font-medium">Ngày giao</th>
                  <th className="py-2 text-right font-medium">Thao tác</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-surface-container-highest">
                {list.data.items.map((item) => (
                  <tr key={item.id} className="cursor-pointer hover:bg-surface-container-low" onClick={() => setSelected(item)}>
                    <td className="py-2 pr-4">
                      <StatusBadge status="pending_confirmation" label="Chờ xác nhận" />
                      <p className="mt-1 font-semibold">{item.title}</p>
                      <p className="text-xs text-on-surface-variant">{item.lesson_session ?? 'Công việc chung'}</p>
                    </td>
                    <td className="py-2 pr-4">{item.person ?? 'Chưa phân công'}</td>
                    <td className="py-2 pr-4 whitespace-nowrap">{item.assigned_date}</td>
                    <td className="py-2 text-right">
                      <Button variant="secondary" className="h-8 px-3" onClick={() => setSelected(item)}>
                        Xem
                      </Button>
                    </td>
                  </tr>
                ))}
                {list.data.items.length === 0 && (
                  <tr>
                    <td colSpan={4} className="py-8 text-center text-on-surface-variant">
                      Không có đầu việc nào cần xác nhận thủ công
                      <span className="block text-xs">Các báo cáo đã được xử lý hoặc đã hoàn thành tự động (có ảnh minh chứng).</span>
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        )}
      </Card>
      {selected && (
        <ApprovalDialog
          item={selected}
          onClose={() => setSelected(null)}
          onDone={(msg) => {
            setNotice(msg)
            setSelected(null)
          }}
        />
      )}
    </>
  )
}

function ApprovalDialog({ item, onClose, onDone }: { item: ApprovalItem; onClose: () => void; onDone: (m: string) => void }) {
  const qc = useQueryClient()
  const [rejecting, setRejecting] = useState(false)
  const [note, setNote] = useState('')
  const act = useMutation({
    mutationFn: () => (rejecting ? tasksApi.reject(item.id, note) : tasksApi.approve(item.id, note)),
    onSuccess: (res) => {
      qc.invalidateQueries({ queryKey: ['tasks'] })
      onDone(res.message)
    },
  })
  return (
    <Dialog title={item.title} onClose={onClose}>
      <div className="space-y-3 text-sm">
        <StatusBadge status="pending_confirmation" label="Chờ xác nhận" />
        <div className="space-y-1 rounded-lg border border-outline-variant bg-surface-container-low p-3">
          <p className="text-xs font-medium text-on-surface-variant uppercase">Thông tin giao việc</p>
          <p className="flex justify-between gap-2">
            <span className="text-on-surface-variant">Người thực hiện:</span>
            <span className="font-semibold">{item.person ?? '—'}</span>
          </p>
          <p className="flex justify-between gap-2">
            <span className="text-on-surface-variant">Người giao việc:</span>
            <span>{item.creator}</span>
          </p>
          <p className="flex justify-between gap-2">
            <span className="text-on-surface-variant">Ngày giao:</span>
            <span>{item.assigned_date}</span>
          </p>
          <p className="flex justify-between gap-2">
            <span className="text-on-surface-variant">Hạn chót:</span>
            <span className="font-semibold text-error">{item.due ?? '—'}</span>
          </p>
        </div>
        <div className="space-y-2 rounded-lg border border-primary/20 bg-primary-light p-3">
          <p className="text-xs font-medium text-on-surface-variant uppercase">Báo cáo từ người thực hiện</p>
          <p className="whitespace-pre-line">{item.note || 'Người thực hiện không ghi chú khi báo hoàn thành.'}</p>
          {item.links.map((l) => (
            <a key={l} href={l} target="_blank" rel="noopener noreferrer" className="block truncate text-primary hover:underline">
              {l}
            </a>
          ))}
          <p className="text-xs text-error">
            <strong>Ghi chú:</strong> báo cáo hoàn thành nhưng không đính kèm ảnh chụp minh chứng lên hệ thống.
          </p>
        </div>
        {act.isError && <Alert>{errorMessage(act.error)}</Alert>}
        <TextArea
          label={rejecting ? 'Lý do từ chối / yêu cầu làm lại' : 'Ghi chú xác nhận (Tùy chọn)'}
          required={rejecting}
          rows={2}
          maxLength={1000}
          value={note}
          onChange={(e) => setNote(e.target.value)}
          placeholder={rejecting ? 'Ví dụ: thiếu ảnh lớp, thông tin chưa chính xác...' : 'Nhập ghi chú hoặc phản hồi...'}
        />
        <div className="flex flex-wrap justify-end gap-2">
          {rejecting ? (
            <>
              <Button variant="secondary" onClick={() => setRejecting(false)}>
                Quay lại
              </Button>
              <Button variant="danger" disabled={act.isPending} onClick={() => act.mutate()}>
                Gửi yêu cầu làm lại
              </Button>
            </>
          ) : (
            <>
              <Button variant="danger" onClick={() => setRejecting(true)}>
                Từ chối / Yêu cầu bổ sung
              </Button>
              <Button disabled={act.isPending} onClick={() => act.mutate()}>
                Xác nhận hoàn thành
              </Button>
            </>
          )}
        </div>
      </div>
    </Dialog>
  )
}
