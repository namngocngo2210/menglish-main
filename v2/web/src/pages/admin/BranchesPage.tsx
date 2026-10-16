import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState, type ChangeEvent } from 'react'
import { api, errorMessage, type Branch, type BranchInput, type Me } from '../../api'
import { Alert, Badge, Button, Card, Dialog, Field } from '../../components/ui'
import { can } from '../../nav'

export function BranchesPage({ me }: { me: Me }) {
  const qc = useQueryClient()
  const list = useQuery({ queryKey: ['branches'], queryFn: api.branches })
  const [editing, setEditing] = useState<Branch | 'new' | null>(null)
  const toggle = useMutation({
    mutationFn: (b: Branch) => api.setBranchActive(b.id, !b.is_active),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['branches'] }),
  })

  return (
    <Card
      title={
        <span className="flex items-center justify-between gap-4">
          Chi nhánh
          {can(me, 'branch.create') && <Button onClick={() => setEditing('new')}>Thêm chi nhánh</Button>}
        </span>
      }
    >
      {list.isError && <Alert>{errorMessage(list.error)}</Alert>}
      {toggle.isError && <Alert>{errorMessage(toggle.error)}</Alert>}
      {list.data && list.data.branches.length === 0 && (
        <p className="text-sm text-on-surface-variant">Chưa có chi nhánh nào.</p>
      )}
      {list.data && list.data.branches.length > 0 && (
        <div className="overflow-x-auto">
          <table className="w-full text-left text-sm">
            <thead className="text-xs text-on-surface-variant">
              <tr>
                <th className="py-2 pr-4 font-medium">Mã</th>
                <th className="py-2 pr-4 font-medium">Tên</th>
                <th className="py-2 pr-4 font-medium">Địa chỉ</th>
                <th className="py-2 pr-4 font-medium">Điện thoại</th>
                <th className="py-2 pr-4 font-medium">Trạng thái</th>
                <th />
              </tr>
            </thead>
            <tbody className="divide-y divide-surface-container-highest">
              {list.data.branches.map((b) => (
                <tr key={b.id}>
                  <td className="py-2 pr-4 font-mono">{b.code}</td>
                  <td className="py-2 pr-4">{b.name}</td>
                  <td className="py-2 pr-4 text-on-surface-variant">{b.address}</td>
                  <td className="py-2 pr-4">{b.phone}</td>
                  <td className="py-2 pr-4">
                    {b.is_active ? <Badge tone="success">Hoạt động</Badge> : <Badge>Ngừng</Badge>}
                  </td>
                  <td className="space-x-2 py-2 text-right whitespace-nowrap">
                    {can(me, 'branch.update') && (
                      <Button variant="secondary" onClick={() => setEditing(b)}>
                        Sửa
                      </Button>
                    )}
                    {can(me, 'branch.manage') && (
                      <Button variant={b.is_active ? 'danger' : 'secondary'} onClick={() => toggle.mutate(b)}>
                        {b.is_active ? 'Ngừng' : 'Mở lại'}
                      </Button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {editing && <BranchDialog branch={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
    </Card>
  )
}

function BranchDialog({ branch, onClose }: { branch: Branch | null; onClose: () => void }) {
  const qc = useQueryClient()
  const [form, setForm] = useState<BranchInput>({
    code: branch?.code ?? '',
    name: branch?.name ?? '',
    address: branch?.address ?? '',
    phone: branch?.phone ?? '',
  })
  const save = useMutation({
    mutationFn: () => (branch ? api.updateBranch(branch.id, form) : api.createBranch(form)),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['branches'] })
      onClose()
    },
  })
  const set = (k: keyof BranchInput) => (e: ChangeEvent<HTMLInputElement>) => setForm({ ...form, [k]: e.target.value })

  return (
    <Dialog title={branch ? 'Sửa chi nhánh' : 'Thêm chi nhánh'} onClose={onClose}>
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault()
          save.mutate()
        }}
      >
        {save.isError && <Alert>{errorMessage(save.error)}</Alert>}
        <Field label="Mã chi nhánh" required maxLength={20} value={form.code} onChange={set('code')} hint="Ví dụ HN01" />
        <Field label="Tên chi nhánh" required maxLength={200} value={form.name} onChange={set('name')} />
        <Field label="Địa chỉ" maxLength={500} value={form.address} onChange={set('address')} />
        <Field label="Điện thoại" inputMode="tel" value={form.phone} onChange={set('phone')} />
        <Button type="submit" disabled={save.isPending}>
          Lưu
        </Button>
      </form>
    </Dialog>
  )
}
