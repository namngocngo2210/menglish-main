// Tạo đầu việc (tasks.create / tasks.store): giao việc 2 chiều. Người có work_task.create giao cho mọi người trong phạm vi;
// người chỉ có work_task.request (GV / TA / Sales) đề xuất việc cho Admin và người duyệt công việc.
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { errorMessage, type Me } from '../../api'
import { Alert, Button, Card, Field, Select } from '../../components/ui'
import { navigate } from '../../router'
import { tasksApi, type CreateInput } from './api'
import { AssignModeSwitch } from './TAAssignPage'
import { PageHeader, TextArea, flash } from './components'

const FREQUENCIES = [
  { value: 'daily', label: 'Hàng ngày' },
  { value: 'weekly', label: 'Hàng tuần' },
  { value: 'monthly', label: 'Hàng tháng' },
]

export function CreateTaskPage(_: { me: Me }) {
  const qc = useQueryClient()
  const options = useQuery({ queryKey: ['tasks', 'options'], queryFn: tasksApi.options })
  const [form, setForm] = useState<CreateInput | null>(null)
  const save = useMutation({
    mutationFn: (input: CreateInput) => tasksApi.create(input),
    onSuccess: (res) => {
      qc.invalidateQueries({ queryKey: ['tasks'] })
      flash.set(res.message)
      navigate('/tasks')
    },
  })

  if (options.isPending) return <p className="text-sm text-on-surface-variant">Đang tải…</p>
  if (options.isError) return <Alert>{errorMessage(options.error)}</Alert>
  const o = options.data
  const f: CreateInput = form ?? {
    title: '',
    description: '',
    assignee_id: '',
    due_date: o.default_due_date,
    due_time: '',
    task_type: 'one_time',
    frequency: 'weekly',
    branch_id: '',
  }
  const set = (patch: Partial<CreateInput>) => setForm({ ...f, ...patch })

  return (
    <div className="mx-auto max-w-2xl">
      <PageHeader title={o.title} description="Giao một việc cho đồng nghiệp. Ai cũng có thể giao việc cho Admin." />
      {o.can_ta_assign && <AssignModeSwitch current="staff" />}
      <Card>
        <form
          className="space-y-4"
          onSubmit={(e) => {
            e.preventDefault()
            save.mutate({ ...f, frequency: f.task_type === 'recurring' ? f.frequency : '' })
          }}
        >
          {save.isError && <Alert>{errorMessage(save.error)}</Alert>}
          <Field label="Tiêu đề công việc" required maxLength={255} placeholder="Nhập tiêu đề công việc..." value={f.title} onChange={(e) => set({ title: e.target.value })} />
          <TextArea label="Mô tả chi tiết" rows={3} placeholder="Mô tả nội dung công việc chi tiết..." value={f.description} onChange={(e) => set({ description: e.target.value })} />
          <div className="grid gap-4 sm:grid-cols-2">
            <Select label="Người nhận" required value={f.assignee_id} onChange={(e) => set({ assignee_id: e.target.value })}>
              <option value="">Chọn nhân sự...</option>
              {o.users.map((u) => (
                <option key={u.id} value={u.id}>
                  {u.label}
                </option>
              ))}
            </Select>
            <div className="grid grid-cols-[1fr_7rem] gap-2">
              <Field label="Hạn hoàn thành" type="date" required value={f.due_date} onChange={(e) => set({ due_date: e.target.value })} />
              <Field label="Giờ hạn" type="time" value={f.due_time} placeholder="18:00" onChange={(e) => set({ due_time: e.target.value })} hint="Mặc định 18:00" />
            </div>
          </div>
          <Select label="Chi nhánh" value={f.branch_id} onChange={(e) => set({ branch_id: e.target.value })}>
            <option value="">-- Không chỉ định --</option>
            {o.branches.map((b) => (
              <option key={b.id} value={b.id}>
                {b.label}
              </option>
            ))}
          </Select>
          <fieldset className="space-y-3 rounded-lg border border-outline-variant bg-surface-container-low p-4">
            <legend className="px-1 text-sm font-medium">Loại công việc</legend>
            <div className="flex flex-wrap gap-6 text-sm">
              <label className="inline-flex items-center gap-2">
                <input type="radio" name="task_type" className="accent-primary" checked={f.task_type === 'one_time'} onChange={() => set({ task_type: 'one_time' })} />
                Phát sinh
              </label>
              <label className="inline-flex items-center gap-2">
                <input type="radio" name="task_type" className="accent-primary" checked={f.task_type === 'recurring'} onChange={() => set({ task_type: 'recurring' })} />
                Lặp đi lặp lại
              </label>
            </div>
            {f.task_type === 'recurring' && (
              <div className="border-t border-outline-variant pt-3">
                <Select label="Tần suất" value={f.frequency} onChange={(e) => set({ frequency: e.target.value })}>
                  {FREQUENCIES.map((x) => (
                    <option key={x.value} value={x.value}>
                      {x.label}
                    </option>
                  ))}
                </Select>
                <p className="mt-1 text-xs text-on-surface-variant">Mỗi lần việc này được xác nhận xong, hệ thống tự tạo lại việc cho lần sau.</p>
              </div>
            )}
          </fieldset>
          <div className="flex justify-end gap-2">
            <Button type="button" variant="secondary" onClick={() => navigate('/tasks')}>
              Hủy
            </Button>
            <Button type="submit" disabled={save.isPending}>
              {save.isPending ? 'Đang lưu…' : 'Lưu và Giao việc'}
            </Button>
          </div>
        </form>
      </Card>
    </div>
  )
}
