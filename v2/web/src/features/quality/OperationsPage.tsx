// QA Observation — dự giờ đội vận hành (ClassQuality/Operations.vue): danh sách lượt dự giờ mới nhất trước, bấm dòng xem
// chi tiết; "Ghi nhận dự giờ mới" / Sửa mở hộp thoại. Form chỉ cho chọn giáo viên đang có lớp hoạt động, lớp lọc theo
// giáo viên đã chọn. Các mục nội dung là tùy chọn, xếp loại bắt buộc.
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent, type ReactNode } from 'react'
import { errorMessage, type Me } from '../../api'
import { Alert, Badge, Button, Dialog, Field, Icon, Select } from '../../components/ui'
import { EmptyState, PageHeader, Pager } from '../platform/parts'
import { qualityApi, type Operation, type OperationsPage as Data } from './api'
import { ConfirmDialog, FilterSelect, Panel, Table, Textarea } from './components'
import { badgeTone, classesForTeacher, filledNotes, formatDate, operationForm, pickClass, toOperationInput, withCurrent } from './logic'

export function OperationsPage(_: { me: Me }) {
  const qc = useQueryClient()
  const [filter, setFilter] = useState({ teacher_id: '', rating: '', page: 1 })
  const [viewing, setViewing] = useState<Operation | null>(null)
  const [editing, setEditing] = useState<Operation | 'new' | null>(null)
  const [deleting, setDeleting] = useState<Operation | null>(null)
  const [notice, setNotice] = useState('')
  const page = useQuery({
    queryKey: ['class-quality', 'operations', filter],
    queryFn: () => qualityApi.operations(filter),
    placeholderData: keepPreviousData,
  })
  const d = page.data
  const done = (msg: string) => {
    setNotice(msg)
    setEditing(null)
    setDeleting(null)
    qc.invalidateQueries({ queryKey: ['class-quality'] })
  }
  const edit = (o: Operation | 'new') => {
    setViewing(null)
    setEditing(o)
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title="QA Observation — Dự giờ vận hành"
        description="Ghi nhận và theo dõi lịch sử dự giờ quan sát lớp học của đội vận hành (QA / Học vụ)."
        actions={
          d?.can_record && (
            <Button onClick={() => edit('new')}>
              <Icon name="add_circle" className="mr-1 text-[18px]" /> Ghi nhận dự giờ mới
            </Button>
          )
        }
      />
      {notice && <Alert tone="success">{notice}</Alert>}
      {page.isError && <Alert>{errorMessage(page.error)}</Alert>}

      {d && (
        <>
          <div className="grid gap-3 sm:grid-cols-[240px_240px]">
            <FilterSelect
              label="Giáo viên"
              all="Tất cả giáo viên"
              options={d.filter_teachers}
              value={filter.teacher_id}
              onChange={(e) => setFilter({ ...filter, teacher_id: e.target.value, page: 1 })}
            />
            <FilterSelect
              label="Xếp loại"
              all="Tất cả xếp loại"
              options={d.ratings}
              value={filter.rating}
              onChange={(e) => setFilter({ ...filter, rating: e.target.value, page: 1 })}
            />
          </div>
          <Panel>
            <Table
              minWidth={860}
              head={
                <tr>
                  <th>Ngày dự giờ</th>
                  <th>Giáo viên</th>
                  <th>Lớp</th>
                  <th>Người dự giờ</th>
                  <th>Xếp loại</th>
                  <th className="text-right">Thao tác</th>
                </tr>
              }
            >
              {d.observations.map((o) => (
                <tr key={o.id} className="cursor-pointer hover:bg-surface-container-low" onClick={() => setViewing(o)}>
                  <td className="whitespace-nowrap">
                    <span className="inline-flex items-center gap-1 font-mono">
                      <Icon name="calendar_today" className="text-[16px] text-on-surface-variant" />
                      {formatDate(o.observed_on)}
                    </span>
                  </td>
                  <td>
                    <span className="font-semibold">{o.teacher ?? '—'}</span>
                    {o.teacher_code && <span className="block text-xs text-on-surface-variant">Mã GV: {o.teacher_code}</span>}
                  </td>
                  <td>
                    {o.class}
                    {o.branch && <span className="block text-xs text-on-surface-variant">{o.branch}</span>}
                  </td>
                  <td>{o.observer ?? '—'}</td>
                  <td>
                    <Badge tone={badgeTone(o.rating_color)}>{o.rating_label}</Badge>
                  </td>
                  <td className="text-right whitespace-nowrap" onClick={(e) => e.stopPropagation()}>
                    <span className="inline-flex gap-1">
                      <Button variant="secondary" className="h-8 px-2" onClick={() => setViewing(o)}>
                        Xem
                      </Button>
                      {d.can_record && (
                        <>
                          <Button
                            variant="secondary"
                            className="h-8 px-2"
                            aria-label={`Sửa lượt dự giờ ${o.teacher ?? ''} ${formatDate(o.observed_on)}`}
                            onClick={() => edit(o)}
                          >
                            Sửa
                          </Button>
                          <Button
                            variant="danger"
                            className="h-8 px-2"
                            aria-label={`Xóa lượt dự giờ ${o.teacher ?? ''} ${formatDate(o.observed_on)}`}
                            onClick={() => setDeleting(o)}
                          >
                            <Icon name="delete" className="text-[18px]" />
                          </Button>
                        </>
                      )}
                    </span>
                  </td>
                </tr>
              ))}
              {d.observations.length === 0 && (
                <tr>
                  <td colSpan={6}>
                    <EmptyState
                      icon="visibility"
                      title="Chưa có lượt dự giờ nào"
                      description={d.can_record ? 'Bấm “Ghi nhận dự giờ mới” sau mỗi buổi quan sát lớp.' : undefined}
                    />
                  </td>
                </tr>
              )}
            </Table>
            <Pager page={d.meta.page} perPage={d.meta.per_page} total={d.meta.total} unit="lượt dự giờ" onPage={(p) => setFilter({ ...filter, page: p })} />
          </Panel>
        </>
      )}

      {viewing && d && <ViewDialog o={viewing} d={d} onClose={() => setViewing(null)} onEdit={() => edit(viewing)} />}
      {editing && d && <OperationDialog o={editing === 'new' ? null : editing} d={d} onClose={() => setEditing(null)} onDone={done} />}
      {deleting && (
        <ConfirmDialog
          title="Xóa lượt dự giờ"
          confirm="Xóa"
          danger
          action={() => qualityApi.deleteOperation(deleting.id)}
          onClose={() => setDeleting(null)}
          onDone={done}
        >
          Xóa lượt dự giờ <strong>{deleting.teacher}</strong> ngày <strong>{formatDate(deleting.observed_on)}</strong>?
        </ConfirmDialog>
      )}
    </div>
  )
}

function ViewDialog({ o, d, onClose, onEdit }: { o: Operation; d: Data; onClose: () => void; onEdit: () => void }) {
  const notes = filledNotes(o.notes, d.note_fields)
  const item = (label: string, value: ReactNode) => (
    <div>
      <dt className="text-xs text-on-surface-variant">{label}</dt>
      <dd className="font-semibold">{value}</dd>
    </div>
  )
  return (
    <Dialog title="Chi tiết lượt dự giờ" onClose={onClose}>
      <dl className="grid gap-4 sm:grid-cols-2">
        {item('Ngày dự giờ', formatDate(o.observed_on))}
        {item('Xếp loại', <Badge tone={badgeTone(o.rating_color)}>{o.rating_label}</Badge>)}
        {item('Giáo viên', o.teacher ?? '—')}
        {item('Lớp', o.class)}
        {item('Người dự giờ', o.observer ?? '—')}
      </dl>
      <div className="mt-6 space-y-4">
        {notes.map((f) => (
          <div key={f.key}>
            <h3 className="text-xs font-semibold text-on-surface-variant uppercase">{f.label}</h3>
            <p className="mt-1 text-sm whitespace-pre-line">{o.notes[f.key]}</p>
          </div>
        ))}
        {notes.length === 0 && <p className="text-sm text-on-surface-variant italic">Không ghi nội dung quan sát chi tiết.</p>}
      </div>
      <div className="mt-6 flex justify-end gap-2">
        <Button variant="secondary" onClick={onClose}>
          Đóng
        </Button>
        {d.can_record && <Button onClick={onEdit}>Sửa</Button>}
      </div>
    </Dialog>
  )
}

function OperationDialog({ o, d, onClose, onDone }: { o: Operation | null; d: Data; onClose: () => void; onDone: (msg: string) => void }) {
  const [f, setF] = useState(() => operationForm(o, d.today))
  const teachers = withCurrent(d.teachers, o?.teacher_id ? { value: o.teacher_id, label: o.teacher ?? '' } : null)
  const allClasses = withCurrent(d.classes, o ? { value: o.class_id, label: o.class, teacher_ids: o.teacher_id ? [o.teacher_id] : [] } : null)
  const classes = classesForTeacher(allClasses, f.teacher_id)
  const save = useMutation({
    mutationFn: () => (o ? qualityApi.updateOperation(o.id, toOperationInput(f)) : qualityApi.createOperation(toOperationInput(f))),
    onSuccess: (r) => onDone(r.message),
  })
  const submit = (e: FormEvent) => {
    e.preventDefault()
    save.mutate()
  }
  return (
    <Dialog title={o ? 'Sửa lượt dự giờ' : 'Ghi nhận lượt dự giờ mới'} onClose={onClose} wide>
      <form className="space-y-4" onSubmit={submit}>
        <p className="text-xs text-on-surface-variant">Nhập đầy đủ thông tin buổi quan sát thực tế (các mục nội dung là tùy chọn).</p>
        {save.isError && <Alert>{errorMessage(save.error)}</Alert>}
        <div className="grid gap-4 sm:grid-cols-3">
          <Field label="Ngày dự giờ" type="date" required max={d.today} value={f.observed_on} onChange={(e) => setF({ ...f, observed_on: e.target.value })} />
          <Select
            label="Giáo viên"
            required
            value={f.teacher_id}
            onChange={(e) => {
              const teacher = e.target.value
              setF({ ...f, teacher_id: teacher, class_id: pickClass(classesForTeacher(allClasses, teacher), f.class_id) })
            }}
          >
            <option value="">-- Chọn giáo viên --</option>
            {teachers.map((t) => (
              <option key={t.value} value={t.value}>
                {t.label}
              </option>
            ))}
          </Select>
          <Select label="Lớp" required value={f.class_id} onChange={(e) => setF({ ...f, class_id: e.target.value })}>
            <option value="">{f.teacher_id ? '-- Chọn lớp học --' : 'Chọn giáo viên trước'}</option>
            {classes.map((c) => (
              <option key={c.value} value={c.value}>
                {c.label}
              </option>
            ))}
          </Select>
        </div>
        <p className="-mt-2 text-xs text-on-surface-variant">Chỉ hiện giáo viên đang có lớp hoạt động; lớp lọc theo giáo viên đã chọn.</p>
        <div className="grid gap-4 sm:grid-cols-2">
          {d.note_fields.map((n) => (
            <Textarea
              key={n.key}
              label={n.label}
              value={f.notes[n.key] ?? ''}
              onChange={(e) => setF({ ...f, notes: { ...f.notes, [n.key]: e.target.value } })}
            />
          ))}
        </div>
        <div className="sm:w-1/3">
          <Select label="Xếp loại" required value={f.rating} onChange={(e) => setF({ ...f, rating: e.target.value })}>
            <option value="">-- Chọn xếp loại --</option>
            {d.ratings.map((r) => (
              <option key={r.value} value={r.value}>
                {r.label}
              </option>
            ))}
          </Select>
        </div>
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            Hủy
          </Button>
          <Button type="submit" disabled={save.isPending}>
            Lưu
          </Button>
        </div>
      </form>
    </Dialog>
  )
}
