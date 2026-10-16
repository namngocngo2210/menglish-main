// Ngày nghỉ lễ (HolidayController của Laravel): toàn hệ thống hoặc theo chi nhánh.
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { errorMessage, type Me } from '../../api'
import { Alert, Badge, Button, Card, Checkbox, Dialog, Field } from '../../components/ui'
import { catalogsApi, type BranchOption, type Holiday, type HolidayInput } from './api'
import { Empty, Pager, PageTitle, Table, actionsTd, td, useAction } from './common'
import { formatDateRange } from './format'

// Mọi thao tác ở màn này cùng một quyền holiday.manage (đã kiểm ở menu).
export function HolidaysPage(_: { me: Me }) {
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [editing, setEditing] = useState<string | 'new' | null>(null)
  const list = useQuery({ queryKey: ['holidays', search, page], queryFn: () => catalogsApi.holidays({ search, page }) })
  const act = useAction([['holidays']])
  const d = list.data

  return (
    <Card title={<PageTitle title="Ngày nghỉ lễ">{<Button onClick={() => setEditing('new')}>Thêm ngày nghỉ</Button>}</PageTitle>}>
      <div className="space-y-4">
        <div className="max-w-md">
          <Field
            label="Tìm kiếm"
            placeholder="Mã hoặc tên ngày nghỉ"
            value={search}
            onChange={(e) => {
              setSearch(e.target.value)
              setPage(1)
            }}
          />
        </div>
        {list.isError && <Alert>{errorMessage(list.error)}</Alert>}
        {act.notice}
        {d && d.items.length === 0 && <Empty>Chưa có ngày nghỉ nào.</Empty>}
        {d && d.items.length > 0 && (
          <Table head={['Mã', 'Tên ngày nghỉ', 'Thời gian', 'Phạm vi', '']}>
            {d.items.map((h) => (
              <tr key={h.id}>
                <td className={`${td} font-mono`}>{h.code}</td>
                <td className={`${td} font-medium`}>{h.name}</td>
                <td className={`${td} whitespace-nowrap`}>{formatDateRange(h.start_date, h.end_date)}</td>
                <td className={td}>
                  {h.is_system_wide ? <Badge tone="info">Toàn hệ thống</Badge> : (h.branches ?? []).join(', ') || '—'}
                </td>
                <td className={actionsTd}>
                  <Button variant="secondary" onClick={() => setEditing(h.id)}>
                    Sửa
                  </Button>
                  <Button variant="danger" onClick={() => act.run(() => catalogsApi.deleteHoliday(h.id), `Xóa ngày nghỉ ${h.name}?`)}>
                    Xóa
                  </Button>
                </td>
              </tr>
            ))}
          </Table>
        )}
        {d && <Pager page={d.page} total={d.total} size={d.page_size} onPage={setPage} />}
      </div>
      {editing === 'new' && d && (
        <HolidayDialog
          holiday={null}
          branches={d.branch_options}
          onClose={() => setEditing(null)}
          onSaved={(msg) => act.setNotice({ tone: 'success', text: msg })}
        />
      )}
      {editing && editing !== 'new' && d && (
        <EditHoliday
          id={editing}
          branches={d.branch_options}
          onClose={() => setEditing(null)}
          onSaved={(msg) => act.setNotice({ tone: 'success', text: msg })}
        />
      )}
    </Card>
  )
}

// Sửa: tải chi tiết (kèm chi nhánh đã chọn) rồi mở form.
function EditHoliday({ id, ...rest }: { id: string; branches: BranchOption[]; onClose: () => void; onSaved: (msg: string) => void }) {
  const h = useQuery({ queryKey: ['holiday', id], queryFn: () => catalogsApi.holiday(id), gcTime: 0 })
  if (h.isError) {
    return (
      <Dialog title="Sửa ngày nghỉ" onClose={rest.onClose}>
        <Alert>{errorMessage(h.error)}</Alert>
      </Dialog>
    )
  }
  return h.data ? <HolidayDialog holiday={h.data} {...rest} /> : null
}

function HolidayDialog({
  holiday,
  branches,
  onClose,
  onSaved,
}: {
  holiday: Holiday | null
  branches: BranchOption[]
  onClose: () => void
  onSaved: (msg: string) => void
}) {
  const qc = useQueryClient()
  const [form, setForm] = useState<HolidayInput>({
    code: holiday?.code ?? '',
    name: holiday?.name ?? '',
    start_date: holiday?.start_date ?? '',
    end_date: holiday?.end_date ?? '',
    is_system_wide: holiday?.is_system_wide ?? true,
    branch_ids: holiday?.branch_ids ?? [],
  })
  const save = useMutation({
    mutationFn: () => (holiday ? catalogsApi.updateHoliday(holiday.id, form) : catalogsApi.createHoliday(form)),
    onSuccess: (r) => {
      qc.invalidateQueries({ queryKey: ['holidays'] })
      onSaved(r.message)
      onClose()
    },
  })
  const toggleBranch = (id: string, on: boolean) =>
    setForm({ ...form, branch_ids: on ? [...form.branch_ids, id] : form.branch_ids.filter((x) => x !== id) })

  return (
    <Dialog title={holiday ? 'Sửa ngày nghỉ' : 'Thêm ngày nghỉ'} onClose={onClose}>
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault()
          save.mutate()
        }}
      >
        {save.isError && <Alert>{errorMessage(save.error)}</Alert>}
        <Field
          label="Mã ngày nghỉ"
          maxLength={50}
          value={form.code}
          onChange={(e) => setForm({ ...form, code: e.target.value })}
          hint={holiday ? 'Để trống để giữ mã cũ.' : 'Để trống để hệ thống tự sinh (HOL-năm-số).'}
        />
        <Field label="Tên ngày nghỉ" required maxLength={255} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Từ ngày" type="date" required value={form.start_date} onChange={(e) => setForm({ ...form, start_date: e.target.value })} />
          <Field
            label="Đến ngày"
            type="date"
            required
            min={form.start_date || undefined}
            value={form.end_date}
            onChange={(e) => setForm({ ...form, end_date: e.target.value })}
          />
        </div>
        <Checkbox
          label="Áp dụng toàn hệ thống"
          checked={form.is_system_wide}
          onChange={(e) => setForm({ ...form, is_system_wide: e.target.checked })}
        />
        {!form.is_system_wide && (
          <fieldset>
            <legend className="mb-1.5 text-sm font-medium">Chi nhánh nghỉ</legend>
            {branches.length === 0 && <p className="text-sm text-on-surface-variant">Chưa có chi nhánh đang hoạt động.</p>}
            <div className="grid gap-1 sm:grid-cols-2">
              {branches.map((b) => (
                <Checkbox
                  key={b.id}
                  label={`${b.code} – ${b.name}`}
                  checked={form.branch_ids.includes(b.id)}
                  onChange={(e) => toggleBranch(b.id, e.target.checked)}
                />
              ))}
            </div>
            <p className="mt-1 text-xs text-on-surface-variant">Không chọn chi nhánh nào thì ngày nghỉ áp dụng toàn hệ thống.</p>
          </fieldset>
        )}
        <Button type="submit" disabled={save.isPending}>
          Lưu
        </Button>
      </form>
    </Dialog>
  )
}
