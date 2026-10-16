// Khóa học & Bảng giá (CourseController của Laravel).
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { errorMessage, type Me } from '../../api'
import { Alert, Badge, Button, Card, Checkbox, Dialog, Field, Select } from '../../components/ui'
import { can } from '../../nav'
import { catalogsApi, type Course, type CourseInput, type Option } from './api'
import { Empty, Pager, PageTitle, Stat, Table, TextArea, actionsTd, intOrNull, td, useAction } from './common'
import { formatMoney } from './format'

export function CoursesPage({ me }: { me: Me }) {
  const [search, setSearch] = useState('')
  const [level, setLevel] = useState('')
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)
  const [editing, setEditing] = useState<Course | 'new' | null>(null)
  const list = useQuery({
    queryKey: ['courses', search, level, status, page],
    queryFn: () => catalogsApi.courses({ search, course_level_id: level, status, page }),
  })
  const act = useAction([['courses']])
  const d = list.data

  return (
    <Card
      title={
        <PageTitle title="Khóa học & Bảng giá">
          {can(me, 'course.create') && <Button onClick={() => setEditing('new')}>Thêm khóa học</Button>}
        </PageTitle>
      }
    >
      <div className="space-y-4">
        {d && (
          <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
            <Stat label="Tổng khóa học" value={d.stats.total_courses} />
            <Stat label="Đang mở" value={d.stats.active_courses} />
            <Stat label="Học phí trung bình" value={formatMoney(d.stats.avg_tuition)} />
            <Stat label="Học phí cao nhất" value={formatMoney(d.stats.max_tuition)} />
          </div>
        )}
        <div className="grid gap-3 md:grid-cols-3">
          <Field
            label="Tìm kiếm"
            placeholder="Mã hoặc tên khóa học"
            value={search}
            onChange={(e) => {
              setSearch(e.target.value)
              setPage(1)
            }}
          />
          <Select
            label="Trình độ"
            value={level}
            onChange={(e) => {
              setLevel(e.target.value)
              setPage(1)
            }}
          >
            <option value="">Tất cả trình độ</option>
            {d?.levels.map((l) => (
              <option key={l.value} value={l.value}>
                {l.label}
              </option>
            ))}
          </Select>
          <Select
            label="Trạng thái"
            value={status}
            onChange={(e) => {
              setStatus(e.target.value)
              setPage(1)
            }}
          >
            <option value="">Tất cả</option>
            <option value="active">Đang mở</option>
            <option value="inactive">Tạm dừng</option>
          </Select>
        </div>
        {list.isError && <Alert>{errorMessage(list.error)}</Alert>}
        {act.notice}
        {d && d.items.length === 0 && <Empty>Chưa có khóa học nào.</Empty>}
        {d && d.items.length > 0 && (
          <Table head={['Mã', 'Tên khóa học', 'Trình độ', 'Học phí', 'Số buổi', 'Lớp', 'Trạng thái', '']}>
            {d.items.map((c) => (
              <tr key={c.id}>
                <td className={`${td} font-mono`}>{c.code}</td>
                <td className={td}>
                  <p className="font-medium">{c.name}</p>
                  {c.description && <p className="text-xs text-on-surface-variant">{c.description}</p>}
                </td>
                <td className={td}>{c.level ? `${c.level.code} – ${c.level.name}` : '—'}</td>
                <td className={`${td} whitespace-nowrap`}>{formatMoney(c.tuition_fee)}</td>
                <td className={td}>{c.total_lessons}</td>
                <td className={td}>{c.classes_count}</td>
                <td className={td}>{c.is_active ? <Badge tone="success">Đang mở</Badge> : <Badge>Tạm dừng</Badge>}</td>
                <td className={actionsTd}>
                  {can(me, 'course.update') && (
                    <>
                      <Button variant="secondary" onClick={() => setEditing(c)}>
                        Sửa
                      </Button>
                      <Button variant="secondary" onClick={() => act.run(() => catalogsApi.toggleCourse(c.id))}>
                        {c.is_active ? 'Tạm dừng' : 'Mở lại'}
                      </Button>
                    </>
                  )}
                  {can(me, 'course.delete') && (
                    <Button
                      variant="danger"
                      onClick={() => act.run(() => catalogsApi.deleteCourse(c.id), `Xóa khóa học ${c.code} – ${c.name}?`)}
                    >
                      Xóa
                    </Button>
                  )}
                </td>
              </tr>
            ))}
          </Table>
        )}
        {d && <Pager page={d.page} total={d.total} size={d.page_size} onPage={setPage} />}
      </div>
      {editing && (
        <CourseDialog
          course={editing === 'new' ? null : editing}
          levels={d?.levels ?? []}
          onClose={() => setEditing(null)}
          onSaved={(msg) => act.setNotice({ tone: 'success', text: msg })}
        />
      )}
    </Card>
  )
}

function CourseDialog({
  course,
  levels,
  onClose,
  onSaved,
}: {
  course: Course | null
  levels: Option[]
  onClose: () => void
  onSaved: (msg: string) => void
}) {
  const qc = useQueryClient()
  const [form, setForm] = useState({
    name: course?.name ?? '',
    course_level_id: course?.course_level_id ?? '',
    tuition_fee: course ? String(course.tuition_fee) : '',
    total_lessons: course ? String(course.total_lessons) : '',
    description: course?.description ?? '',
    is_active: course?.is_active ?? true,
  })
  const save = useMutation({
    mutationFn: () => {
      const body: CourseInput = {
        name: form.name,
        course_level_id: form.course_level_id || null,
        tuition_fee: intOrNull(form.tuition_fee),
        total_lessons: intOrNull(form.total_lessons),
        description: form.description,
        is_active: form.is_active,
      }
      return course ? catalogsApi.updateCourse(course.id, body) : catalogsApi.createCourse(body)
    },
    onSuccess: (r) => {
      qc.invalidateQueries({ queryKey: ['courses'] })
      onSaved(r.message)
      onClose()
    },
  })
  const fee = intOrNull(form.tuition_fee)

  return (
    <Dialog title={course ? `Sửa khóa học ${course.code}` : 'Thêm khóa học'} onClose={onClose}>
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault()
          save.mutate()
        }}
      >
        {save.isError && <Alert>{errorMessage(save.error)}</Alert>}
        <Field label="Tên khóa học" required maxLength={255} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
        <Select label="Trình độ" value={form.course_level_id} onChange={(e) => setForm({ ...form, course_level_id: e.target.value })}>
          <option value="">— Chọn trình độ —</option>
          {levels.map((l) => (
            <option key={l.value} value={l.value}>
              {l.label}
            </option>
          ))}
        </Select>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field
            label="Học phí (đồng)"
            required
            type="number"
            min={0}
            step={1000}
            value={form.tuition_fee}
            onChange={(e) => setForm({ ...form, tuition_fee: e.target.value })}
            hint={fee !== null ? formatMoney(fee) : undefined}
          />
          <Field
            label="Tổng số buổi"
            required
            type="number"
            min={1}
            value={form.total_lessons}
            onChange={(e) => setForm({ ...form, total_lessons: e.target.value })}
          />
        </div>
        <TextArea label="Mô tả" maxLength={1000} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />
        <Checkbox label="Đang mở" checked={form.is_active} onChange={(e) => setForm({ ...form, is_active: e.target.checked })} />
        <Button type="submit" disabled={save.isPending}>
          Lưu
        </Button>
      </form>
    </Dialog>
  )
}
