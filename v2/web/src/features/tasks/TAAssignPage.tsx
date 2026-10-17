// Tạo lượt giao việc cho Trợ giảng (tasks.ta-assign): nhiều đầu việc theo 3 ca Trước / Trong / Sau giờ học trong một ngày.
// Luật riêng: chỉ work_task.assign, trợ giảng trong phạm vi, gửi sau 15:30 vẫn lưu nhưng báo Admin và người giao.
// Gắn lớp: chọn lớp + buổi học trong ngày giao (giờ hạn theo giờ buổi); lớp không có buổi hôm đó thì gõ tên buổi.
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { errorMessage, type Me } from '../../api'
import { Alert, Button, Card, Field, Select } from '../../components/ui'
import { navigate } from '../../router'
import { tasksApi, type SlotKey, type TATaskInput } from './api'
import { PageHeader, flash } from './components'
import { DUE_TEXT, isLateAssign } from './logic'

// Chọn đối tượng giao việc: Nhân sự (một việc, giao 2 chiều) | Trợ giảng (nhiều đầu việc theo ca).
export function AssignModeSwitch({ current }: { current: 'staff' | 'assistant' }) {
  const modes = [
    { key: 'staff', label: 'Nhân sự', path: '/tasks/new' },
    { key: 'assistant', label: 'Trợ giảng (theo ca)', path: '/tasks/ta-assign' },
  ] as const
  return (
    <div className="mb-4 inline-flex items-center rounded-lg border border-outline-variant bg-surface-container-low p-0.5 text-sm" role="tablist" aria-label="Giao cho">
      <span className="px-2 text-on-surface-variant">Giao cho:</span>
      {modes.map((m) => (
        <button
          key={m.key}
          type="button"
          role="tab"
          aria-selected={current === m.key}
          onClick={() => navigate(m.path)}
          className="rounded-md px-3 py-1 text-on-surface-variant aria-selected:bg-surface aria-selected:font-semibold aria-selected:text-primary aria-selected:shadow-sm"
        >
          {m.label}
        </button>
      ))}
    </div>
  )
}

type Row = TATaskInput & { id: number }
let seq = 1
const newRow = (category: SlotKey): Row => ({ id: seq++, category, content: '', attach_class: false, class_id: '', class_session_id: '', session: '' })

export function TAAssignPage(_: { me: Me }) {
  const qc = useQueryClient()
  const options = useQuery({ queryKey: ['tasks', 'ta-assign-options'], queryFn: tasksApi.taAssignOptions })
  const [assistant, setAssistant] = useState('')
  const [date, setDate] = useState('')
  const [branch, setBranch] = useState('')
  const [rows, setRows] = useState<Row[]>(() => [newRow('before')])
  // Đồng hồ chạy tiếp từ giờ máy chủ để cảnh báo gửi trễ ngay trên form.
  const [elapsed, setElapsed] = useState(0)
  useEffect(() => {
    const start = Date.now()
    const timer = setInterval(() => setElapsed(Math.floor((Date.now() - start) / 60000)), 15000)
    return () => clearInterval(timer)
  }, [])
  const save = useMutation({
    mutationFn: tasksApi.taAssign,
    onSuccess: (res) => {
      qc.invalidateQueries({ queryKey: ['tasks'] })
      flash.set(res.message)
      navigate('/tasks')
    },
  })

  if (options.isPending) return <p className="text-sm text-on-surface-variant">Đang tải…</p>
  if (options.isError) return <Alert>{errorMessage(options.error)}</Alert>
  const o = options.data
  const day = date || o.today
  const late = isLateAssign(day, o.today, o.now_time, o.cutoff, elapsed)
  const cutoffLabel = o.cutoff.replace(':', 'h')
  const update = (id: number, patch: Partial<Row>) => setRows(rows.map((r) => (r.id === id ? { ...r, ...patch } : r)))

  return (
    <div className="mx-auto max-w-4xl">
      <PageHeader title="Tạo lượt giao việc cho Trợ giảng" description="Phân công nhiệm vụ chi tiết theo ngày và ca học." />
      <AssignModeSwitch current="assistant" />
      <Card>
        <form
          className="space-y-5"
          onSubmit={(e) => {
            e.preventDefault()
            save.mutate({
              assistant_id: assistant,
              assign_date: day,
              branch_id: branch,
              tasks: rows.map(({ id: _id, ...r }) => r),
            })
          }}
        >
          {save.isError && <Alert>{errorMessage(save.error)}</Alert>}
          <div className="grid gap-4 md:grid-cols-3">
            <Select label="Chọn Trợ giảng" required value={assistant} onChange={(e) => setAssistant(e.target.value)}>
              <option value="">-- Chọn Trợ giảng --</option>
              {o.assistants.map((a) => (
                <option key={a.id} value={a.id}>
                  {a.label}
                </option>
              ))}
            </Select>
            <Field label="Ngày giao việc" type="date" required value={day} onChange={(e) => setDate(e.target.value)} />
            <Select label="Chi nhánh" value={branch} onChange={(e) => setBranch(e.target.value)}>
              <option value="">-- Chọn Chi nhánh --</option>
              {o.branches.map((b) => (
                <option key={b.id} value={b.id}>
                  {b.label}
                </option>
              ))}
            </Select>
          </div>
          {late && (
            <Alert tone="info">
              Đã quá {cutoffLabel} của ngày {day.split('-').reverse().join('/')}. Bạn vẫn gửi được, nhưng hệ thống sẽ báo cho Admin và cho bạn là gửi muộn.
            </Alert>
          )}
          {o.assistants.length === 0 && <Alert>Chưa có tài khoản trợ giảng nào đang hoạt động trong phạm vi bạn quản lý.</Alert>}

          <div className="space-y-3 border-t border-surface-container-highest pt-4">
            <div className="flex items-center justify-between">
              <h2 className="font-semibold">Danh sách nhiệm vụ</h2>
              <span className="text-xs text-on-surface-variant">{rows.length} đầu việc</span>
            </div>
            {rows.map((r) => {
              const sessions = (o.class_sessions[r.class_id] ?? []).filter((s) => s.date === day)
              return (
                <div key={r.id} className="space-y-3 rounded-lg border border-outline-variant bg-surface-container-low p-3">
                  <div className="grid gap-3 md:grid-cols-[12rem_1fr_auto_auto] md:items-end">
                    <Select label="Nhóm đầu mục" value={r.category} onChange={(e) => update(r.id, { category: e.target.value as SlotKey })}>
                      {o.slots.map((s) => (
                        <option key={s.value} value={s.value}>
                          {s.label}
                        </option>
                      ))}
                    </Select>
                    <Field label="Nội dung" required maxLength={500} placeholder="Nhập nội dung công việc..." value={r.content} onChange={(e) => update(r.id, { content: e.target.value })} />
                    <label className="inline-flex h-10 items-center gap-2 text-sm font-medium">
                      <input type="checkbox" className="accent-primary" checked={r.attach_class} onChange={(e) => update(r.id, { attach_class: e.target.checked })} />
                      Gắn lớp?
                    </label>
                    <Button type="button" variant="danger" disabled={rows.length <= 1} onClick={() => setRows(rows.filter((x) => x.id !== r.id))} aria-label="Xóa đầu việc">
                      Xóa
                    </Button>
                  </div>
                  {r.attach_class && (
                    <div className="grid gap-3 border-t border-dashed border-outline-variant pt-3 md:grid-cols-2">
                      <Select label="Lớp học" required value={r.class_id} onChange={(e) => update(r.id, { class_id: e.target.value, class_session_id: '' })}>
                        <option value="">-- Chọn lớp --</option>
                        {o.classes.map((c) => (
                          <option key={c.id} value={c.id}>
                            {c.label}
                          </option>
                        ))}
                      </Select>
                      {sessions.length > 0 ? (
                        <Select label="Buổi học" required value={r.class_session_id} onChange={(e) => update(r.id, { class_session_id: e.target.value })}>
                          <option value="">Chọn buổi học</option>
                          {sessions.map((x) => (
                            <option key={x.id} value={x.id}>
                              {x.label}
                            </option>
                          ))}
                        </Select>
                      ) : (
                        <Field
                          label="Buổi học"
                          required
                          maxLength={255}
                          placeholder="Lớp không có buổi học hôm đó, hãy gõ tên buổi"
                          value={r.session}
                          onChange={(e) => update(r.id, { session: e.target.value, class_session_id: '' })}
                        />
                      )}
                    </div>
                  )}
                </div>
              )
            })}
            <Button
              type="button"
              variant="secondary"
              className="w-full border-dashed"
              disabled={rows.length >= 30}
              onClick={() => setRows([...rows, newRow('during')])}
            >
              + Thêm đầu việc
            </Button>
          </div>

          <div className="space-y-1 rounded-lg bg-surface-container-low p-3 text-xs text-on-surface-variant">
            <p className="text-sm">Nên gửi trước {cutoffLabel}. Gửi muộn hơn vẫn được, nhưng hệ thống sẽ báo cho Admin và cho bạn.</p>
            <p>Hạn làm xong của từng ca:</p>
            <ul className="list-disc space-y-0.5 pl-5">
              {o.slots.map((s) => (
                <li key={s.value}>
                  <span className="font-medium text-on-surface">{s.label}</span>: {DUE_TEXT[s.value]}. Không chọn buổi học thì hạn là {s.due}.
                </li>
              ))}
            </ul>
          </div>
          <div className="flex justify-end gap-2">
            <Button type="button" variant="secondary" onClick={() => navigate('/tasks')}>
              Hủy bỏ
            </Button>
            <Button type="submit" disabled={save.isPending}>
              {save.isPending ? 'Đang gửi…' : 'Gửi nhiệm vụ'}
            </Button>
          </div>
        </form>
      </Card>
    </div>
  )
}
