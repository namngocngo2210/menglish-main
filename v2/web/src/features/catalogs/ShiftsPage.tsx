// Khung giờ ca dạy (TeachingShiftController của Laravel): mỗi ca 90 phút, có thể có giờ lệch.
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { errorMessage, type Me } from '../../api'
import { Alert, Badge, Button, Card, Dialog, Field, Select } from '../../components/ui'
import { can } from '../../nav'
import { catalogsApi, type Option, type Shift, type ShiftInput } from './api'
import { Empty, PageTitle, Table, actionsTd, td, useAction } from './common'
import { addMinutes } from './format'

export function ShiftsPage({ me }: { me: Me }) {
  const list = useQuery({ queryKey: ['teaching-shifts'], queryFn: catalogsApi.shifts })
  const act = useAction([['teaching-shifts']])
  const [editing, setEditing] = useState<Shift | 'new' | null>(null)
  const manage = can(me, 'teaching_shift.manage')
  const d = list.data

  return (
    <Card
      title={
        <PageTitle title="Khung giờ ca dạy">{manage && <Button onClick={() => setEditing('new')}>Thêm khung giờ</Button>}</PageTitle>
      }
    >
      <div className="space-y-4">
        {list.isError && <Alert>{errorMessage(list.error)}</Alert>}
        {d && (
          <Alert tone="info">
            Mỗi ca dạy {d.duration} phút. {d.weekday_rule && <>Thứ 2 – Thứ 6 đang dùng: {d.weekday_rule}.</>}
          </Alert>
        )}
        {act.notice}
        {d?.off_schedule_classes && d.off_schedule_classes.length > 0 && (
          <Alert tone="info">
            Lớp có buổi lệch khung giờ (60 ngày tới):{' '}
            {d.off_schedule_classes.map((c) => `${c.class_name} (${c.class_code}, ${c.sessions} buổi: ${c.slots.join(', ')})`).join('; ')}
          </Alert>
        )}
        {d && d.shifts.length === 0 && <Empty>Chưa có khung giờ nào.</Empty>}
        {d &&
          d.day_types.map((dt) => {
            const rows = d.shifts.filter((s) => s.day_type === dt.value)
            if (rows.length === 0) return null
            return (
              <section key={dt.value} className="space-y-2">
                <h3 className="text-sm font-semibold">{dt.label}</h3>
                <Table head={['Ca', 'Giờ chuẩn', 'Giờ lệch', 'Ghi chú', 'Trạng thái', '']}>
                  {rows.map((s) => (
                    <tr key={s.id}>
                      <td className={`${td} font-medium`}>{s.name}</td>
                      <td className={`${td} font-mono`}>{s.standard}</td>
                      <td className={`${td} font-mono`}>{s.alt ?? '—'}</td>
                      <td className={`${td} text-on-surface-variant`}>{s.note}</td>
                      <td className={td}>{s.is_active ? <Badge tone="success">Đang dùng</Badge> : <Badge>Tạm ẩn</Badge>}</td>
                      <td className={actionsTd}>
                        {manage && (
                          <>
                            <Button variant="secondary" onClick={() => setEditing(s)}>
                              Sửa
                            </Button>
                            <Button variant="secondary" onClick={() => act.run(() => catalogsApi.toggleShift(s.id))}>
                              {s.is_active ? 'Tạm ẩn' : 'Dùng lại'}
                            </Button>
                            <Button
                              variant="danger"
                              onClick={() => act.run(() => catalogsApi.deleteShift(s.id), `Xóa khung giờ ${s.name} (${s.day_label})?`)}
                            >
                              Xóa
                            </Button>
                          </>
                        )}
                      </td>
                    </tr>
                  ))}
                </Table>
              </section>
            )
          })}
      </div>
      {editing && d && (
        <ShiftDialog
          shift={editing === 'new' ? null : editing}
          dayTypes={d.day_types}
          duration={d.duration}
          onClose={() => setEditing(null)}
          onSaved={(msg) => act.setNotice({ tone: 'success', text: msg })}
        />
      )}
    </Card>
  )
}

function ShiftDialog({
  shift,
  dayTypes,
  duration,
  onClose,
  onSaved,
}: {
  shift: Shift | null
  dayTypes: Option[]
  duration: number
  onClose: () => void
  onSaved: (msg: string) => void
}) {
  const qc = useQueryClient()
  const [form, setForm] = useState<ShiftInput>({
    day_type: shift?.day_type ?? dayTypes[0]?.value ?? 'weekday',
    name: shift?.name ?? '',
    start_time: shift?.start_time ?? '',
    end_time: shift?.end_time ?? '',
    alt_start_time: shift?.alt_start_time ?? '',
    alt_end_time: shift?.alt_end_time ?? '',
    note: shift?.note ?? '',
  })
  const save = useMutation({
    mutationFn: () => (shift ? catalogsApi.updateShift(shift.id, form) : catalogsApi.createShift(form)),
    onSuccess: (r) => {
      qc.invalidateQueries({ queryKey: ['teaching-shifts'] })
      onSaved(r.message)
      onClose()
    },
  })
  // Nhập giờ bắt đầu thì tự điền giờ kết thúc = bắt đầu + 90 phút
  const setStart = (key: 'start_time' | 'alt_start_time', endKey: 'end_time' | 'alt_end_time', v: string) =>
    setForm({ ...form, [key]: v, [endKey]: v ? addMinutes(v, duration) : '' })

  return (
    <Dialog title={shift ? 'Sửa khung giờ' : 'Thêm khung giờ'} onClose={onClose}>
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault()
          save.mutate()
        }}
      >
        {save.isError && <Alert>{errorMessage(save.error)}</Alert>}
        <div className="grid gap-4 sm:grid-cols-2">
          <Select label="Loại ngày" value={form.day_type} onChange={(e) => setForm({ ...form, day_type: e.target.value })}>
            {dayTypes.map((d) => (
              <option key={d.value} value={d.value}>
                {d.label}
              </option>
            ))}
          </Select>
          <Field label="Tên ca" required maxLength={50} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} hint="Ví dụ Ca 1" />
        </div>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Giờ bắt đầu" type="time" required value={form.start_time} onChange={(e) => setStart('start_time', 'end_time', e.target.value)} />
          <Field label="Giờ kết thúc" type="time" required value={form.end_time} onChange={(e) => setForm({ ...form, end_time: e.target.value })} />
        </div>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field
            label="Giờ lệch bắt đầu"
            type="time"
            value={form.alt_start_time}
            onChange={(e) => setStart('alt_start_time', 'alt_end_time', e.target.value)}
            hint="Để trống nếu không có giờ lệch"
          />
          <Field label="Giờ lệch kết thúc" type="time" value={form.alt_end_time} onChange={(e) => setForm({ ...form, alt_end_time: e.target.value })} />
        </div>
        <Field label="Ghi chú" maxLength={255} value={form.note} onChange={(e) => setForm({ ...form, note: e.target.value })} />
        <Button type="submit" disabled={save.isPending}>
          Lưu
        </Button>
      </form>
    </Dialog>
  )
}
