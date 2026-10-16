// Tạo / sửa lớp (ClassManagementController@create / edit). Lịch học tuần cấu hình ở Lịch & TKB lớp sau khi tạo.
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { errorMessage } from '../../api'
import { Alert, Button, Card, Field, Select } from '../../components/ui'
import { TextArea } from '../catalogs/common'
import { navigate } from '../../router'
import { classesApi, type FormOptions } from './api'
import { setFlash } from './flash'
import { Header, Loading } from './components'
import { emptyForm, formFromValues, toInput, type ClassForm } from './logic'

const STATUSES = [
  { value: 'pending_schedule', label: 'Chờ lịch' },
  { value: 'upcoming', label: 'Sắp khai giảng' },
  { value: 'active', label: 'Đang học' },
  { value: 'completed', label: 'Đã kết thúc' },
  { value: 'cancelled', label: 'Đã hủy' },
]

export function ClassFormPage({ id }: { id?: string }) {
  const options = useQuery({ queryKey: ['class-form-options', id ?? 'new'], queryFn: () => classesApi.formOptions(id) })
  if (options.isPending) return <Loading />
  if (options.isError) return <Alert>{errorMessage(options.error)}</Alert>
  return <Form id={id} options={options.data} />
}

function Form({ id, options }: { id?: string; options: FormOptions }) {
  const qc = useQueryClient()
  const edit = Boolean(id)
  const [f, setF] = useState<ClassForm>(() => (options.values ? formFromValues(options.values) : { ...emptyForm, branch_id: options.branches.length === 1 ? options.branches[0].value : '' }))
  const set = (patch: Partial<ClassForm>) => setF((cur) => ({ ...cur, ...patch }))
  const save = useMutation({
    mutationFn: () => (id ? classesApi.update(id, toInput(f, true)) : classesApi.create(toInput(f, false))),
    onSuccess: (r) => {
      qc.invalidateQueries({ queryKey: ['classes'] })
      qc.invalidateQueries({ queryKey: ['class'] })
      setFlash(r.message)
      navigate(`/classes/${r.id}`)
    },
  })
  const rooms = options.rooms.filter((r) => r.branch_id === f.branch_id)
  const room = rooms.find((r) => r.value === f.room_id)
  const programs = edit ? options.programs : options.courses

  return (
    <>
      <Header
        title={edit ? 'Sửa lớp học' : 'Tạo lớp mới'}
        description={edit ? undefined : 'Sau khi tạo, cấu hình lịch học tuần ở Lịch & TKB lớp để sinh buổi học.'}
        actions={
          <Button variant="secondary" onClick={() => navigate(id ? `/classes/${id}` : '/classes')}>
            Quay lại
          </Button>
        }
      />
      <Card>
        <form
          className="space-y-4"
          onSubmit={(e) => {
            e.preventDefault()
            save.mutate()
          }}
        >
          {save.isError && <Alert>{errorMessage(save.error)}</Alert>}
          <div className="grid gap-4 md:grid-cols-2">
            <Field label="Tên lớp" required value={f.name} onChange={(e) => set({ name: e.target.value })} />
            <Field label="Mã lớp" hint="Để trống để hệ thống tự sinh theo chương trình." value={f.code} onChange={(e) => set({ code: e.target.value })} />
            <Select label="Chi nhánh" required value={f.branch_id} onChange={(e) => set({ branch_id: e.target.value, room_id: '' })}>
              <option value="">Chọn chi nhánh</option>
              {options.branches.map((b) => (
                <option key={b.value} value={b.value}>
                  {b.label}
                </option>
              ))}
            </Select>
            <Select
              label="Chương trình"
              required
              value={f.program}
              onChange={(e) => {
                const course = options.courses.find((c) => c.value === e.target.value)
                set({ program: e.target.value, ...(course && !f.tuition_fee ? { tuition_fee: String(course.fee) } : {}) })
              }}
            >
              <option value="">Chọn chương trình</option>
              {f.program && !programs.some((p) => p.value === f.program) && <option value={f.program}>{f.program}</option>}
              {programs.map((p) => (
                <option key={p.value} value={p.value}>
                  {p.label}
                </option>
              ))}
            </Select>
            <Select label="Cấp độ" required value={f.level} onChange={(e) => set({ level: e.target.value })}>
              <option value="">Chọn cấp độ</option>
              {f.level && !options.levels.some((p) => p.value === f.level) && <option value={f.level}>{f.level}</option>}
              {options.levels.map((p) => (
                <option key={p.value} value={p.value}>
                  {p.label}
                </option>
              ))}
            </Select>
            <Field label="Học phí (đồng)" inputMode="numeric" value={f.tuition_fee} onChange={(e) => set({ tuition_fee: e.target.value })} />
            <Field label="Sĩ số tối đa" type="number" min={1} max={100} required value={f.max_capacity} onChange={(e) => set({ max_capacity: e.target.value })} />
            <Field
              label="Ngưỡng khai giảng"
              type="number"
              min={1}
              max={100}
              hint="Số học viên tối thiểu để mở lớp."
              value={f.min_students}
              onChange={(e) => set({ min_students: e.target.value })}
            />
            <Select label="Giáo viên chính" value={f.teacher_id} onChange={(e) => set({ teacher_id: e.target.value })}>
              <option value="">Chưa phân công</option>
              {options.teachers.map((t) => (
                <option key={t.value} value={t.value}>
                  {t.label}
                </option>
              ))}
            </Select>
            <Select label="GVNN mặc định" value={f.foreign_teacher_id} onChange={(e) => set({ foreign_teacher_id: e.target.value })}>
              <option value="">Không có GVNN</option>
              {options.foreign_teachers.map((t) => (
                <option key={t.value} value={t.value}>
                  {t.label}
                </option>
              ))}
            </Select>
            {edit && options.assistants.length > 0 && (
              <Select label="Trợ giảng cố định (lớp cũ)" value={f.assistant_id} onChange={(e) => set({ assistant_id: e.target.value })}>
                <option value="">Bỏ trợ giảng cố định</option>
                {options.assistants.map((t) => (
                  <option key={t.value} value={t.value}>
                    {t.label}
                  </option>
                ))}
              </Select>
            )}
            <div className="space-y-1">
              <Select label="Phòng học" value={f.room_id} disabled={!f.branch_id} onChange={(e) => set({ room_id: e.target.value })}>
                <option value="">{f.branch_id ? 'Chưa gán phòng' : 'Chọn chi nhánh trước'}</option>
                {rooms.map((r) => (
                  <option key={r.value} value={r.value}>
                    {r.label}
                  </option>
                ))}
              </Select>
              {room && room.classes.length > 0 && (
                <p className="text-xs text-warning">
                  Đang dùng bởi: {room.classes.map((c) => `${c.name} (${c.slots.join(', ') || 'chưa có lịch'})${c.studying ? ' — đang học' : ''}`).join('; ')}
                </p>
              )}
            </div>
            {edit && (
              <Select label="Trạng thái" value={f.status} onChange={(e) => set({ status: e.target.value })}>
                {STATUSES.map((s) => (
                  <option key={s.value} value={s.value}>
                    {s.label}
                  </option>
                ))}
              </Select>
            )}
          </div>
          <TextArea label="Ghi chú" value={f.notes} onChange={(e) => set({ notes: e.target.value })} />
          <div className="flex justify-end gap-2">
            <Button type="submit" disabled={save.isPending}>
              {edit ? 'Lưu thay đổi' : 'Tạo lớp'}
            </Button>
          </div>
        </form>
      </Card>
    </>
  )
}
