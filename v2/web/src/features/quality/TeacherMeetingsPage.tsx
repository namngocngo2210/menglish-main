// Báo cáo họp giáo viên theo tuần (ClassQuality/TeacherMeetings.vue): danh sách mới nhất trước, lọc tuần / giáo viên /
// tình trạng; bấm dòng xem chi tiết; Thêm / Sửa mở hộp thoại gồm Thông tin chung, Nội dung chuyên môn, Đề xuất & Hành động.
// Lớp lọc theo giáo viên đã chọn, hiện chương trình của lớp.
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent, type ReactNode } from 'react'
import { errorMessage, type Me } from '../../api'
import { Alert, Badge, Button, Dialog, Field, Icon, Select } from '../../components/ui'
import { EmptyState, PageHeader, Pager } from '../platform/parts'
import { qualityApi, type Meeting, type MeetingsPage as Data } from './api'
import { ConfirmDialog, FilterSelect, Panel, Table, Textarea } from './components'
import { badgeTone, classesForTeacher, meetingForm, pickClass, toMeetingInput, weekLabel, weekRange, withCurrent } from './logic'

const NOTES: [keyof Meeting, string][] = [
  ['syllabus_note', 'Ghi chú Syllabus'],
  ['scores_note', 'Ghi chú Bảng điểm'],
  ['class_note', 'Ghi chú tình hình lớp / học sinh'],
  ['academic_order', 'Order học thuật'],
  ['recommendation', 'Nhận xét / đề xuất của Học thuật'],
]

export function TeacherMeetingsPage(_: { me: Me }) {
  const qc = useQueryClient()
  const [filter, setFilter] = useState({ week: '', teacher_id: '', status: '', page: 1 })
  const [viewing, setViewing] = useState<Meeting | null>(null)
  const [editing, setEditing] = useState<Meeting | 'new' | null>(null)
  const [deleting, setDeleting] = useState<Meeting | null>(null)
  const [notice, setNotice] = useState('')
  const page = useQuery({
    queryKey: ['class-quality', 'teacher-meetings', filter],
    queryFn: () => qualityApi.meetings(filter),
    placeholderData: keepPreviousData,
  })
  const d = page.data
  const done = (msg: string) => {
    setNotice(msg)
    setEditing(null)
    setDeleting(null)
    qc.invalidateQueries({ queryKey: ['class-quality'] })
  }
  const edit = (m: Meeting | 'new') => {
    setViewing(null)
    setEditing(m)
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title="Báo cáo họp giáo viên"
        description="Ghi nhận nội dung họp và thu thập dữ liệu chuyên môn theo tuần."
        actions={
          d?.can_manage && (
            <Button onClick={() => edit('new')}>
              <Icon name="add" className="mr-1 text-[18px]" /> Thêm báo cáo họp
            </Button>
          )
        }
      />
      {notice && <Alert tone="success">{notice}</Alert>}
      {page.isError && <Alert>{errorMessage(page.error)}</Alert>}

      {d && (
        <>
          <div className="grid gap-3 sm:grid-cols-[220px_240px_220px]">
            <Field label="Tuần (chọn 1 ngày bất kỳ)" type="date" value={filter.week} onChange={(e) => setFilter({ ...filter, week: e.target.value, page: 1 })} />
            <FilterSelect
              label="Giáo viên"
              all="Tất cả giáo viên"
              options={d.teachers}
              value={filter.teacher_id}
              onChange={(e) => setFilter({ ...filter, teacher_id: e.target.value, page: 1 })}
            />
            <FilterSelect
              label="Tình trạng"
              all="Tất cả tình trạng"
              options={d.statuses}
              value={filter.status}
              onChange={(e) => setFilter({ ...filter, status: e.target.value, page: 1 })}
            />
          </div>
          <Panel>
            <Table
              minWidth={960}
              head={
                <tr>
                  <th>Tuần</th>
                  <th>Giáo viên</th>
                  <th>Lớp</th>
                  <th>Tình hình lớp / học sinh</th>
                  <th>Tình trạng</th>
                  <th className="text-right">Thao tác</th>
                </tr>
              }
            >
              {d.reports.map((r) => (
                <tr key={r.id} className="cursor-pointer hover:bg-surface-container-low" onClick={() => setViewing(r)}>
                  <td className="font-mono whitespace-nowrap">{weekLabel(r.week_start, r.week_end)}</td>
                  <td className="font-semibold">{r.teacher ?? '—'}</td>
                  <td>
                    {r.class ?? '—'}
                    {r.course && <span className="block text-xs text-on-surface-variant">{r.course}</span>}
                  </td>
                  <td className="max-w-[320px]">
                    <p className="line-clamp-2">{r.class_note}</p>
                  </td>
                  <td>
                    <Badge tone={badgeTone(r.status_color)}>{r.status_label}</Badge>
                  </td>
                  <td className="text-right whitespace-nowrap" onClick={(e) => e.stopPropagation()}>
                    <span className="inline-flex gap-1">
                      <Button variant="secondary" className="h-8 px-2" onClick={() => setViewing(r)}>
                        Xem
                      </Button>
                      {d.can_manage && (
                        <>
                          <Button variant="secondary" className="h-8 px-2" aria-label={`Sửa báo cáo họp ${r.teacher ?? ''}`} onClick={() => edit(r)}>
                            Sửa
                          </Button>
                          <Button variant="danger" className="h-8 px-2" aria-label={`Xóa báo cáo họp ${r.teacher ?? ''}`} onClick={() => setDeleting(r)}>
                            <Icon name="delete" className="text-[18px]" />
                          </Button>
                        </>
                      )}
                    </span>
                  </td>
                </tr>
              ))}
              {d.reports.length === 0 && (
                <tr>
                  <td colSpan={6}>
                    <EmptyState icon="groups" title="Chưa có báo cáo họp giáo viên nào" />
                  </td>
                </tr>
              )}
            </Table>
            <Pager page={d.meta.page} perPage={d.meta.per_page} total={d.meta.total} unit="báo cáo" onPage={(p) => setFilter({ ...filter, page: p })} />
          </Panel>
        </>
      )}

      {viewing && d && (
        <Dialog title="Báo cáo họp giáo viên" onClose={() => setViewing(null)}>
          <dl className="grid gap-4 sm:grid-cols-2">
            <Item label="Tuần">{weekLabel(viewing.week_start, viewing.week_end)}</Item>
            <Item label="Tình trạng">
              <Badge tone={badgeTone(viewing.status_color)}>{viewing.status_label}</Badge>
            </Item>
            <Item label="Giáo viên">{viewing.teacher ?? '—'}</Item>
            <Item label="Lớp">
              {viewing.class ?? '—'}
              {viewing.course && ` · ${viewing.course}`}
            </Item>
            <Item label="Người ghi">{viewing.author ?? '—'}</Item>
          </dl>
          <div className="mt-6 space-y-4">
            {NOTES.filter(([k]) => viewing[k]).map(([k, label]) => (
              <div key={k}>
                <h3 className="text-xs font-semibold text-on-surface-variant uppercase">{label}</h3>
                <p className="mt-1 text-sm whitespace-pre-line">{viewing[k] as string}</p>
              </div>
            ))}
          </div>
          <div className="mt-6 flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setViewing(null)}>
              Đóng
            </Button>
            {d.can_manage && <Button onClick={() => edit(viewing)}>Sửa</Button>}
          </div>
        </Dialog>
      )}
      {editing && d && <MeetingDialog m={editing === 'new' ? null : editing} d={d} onClose={() => setEditing(null)} onDone={done} />}
      {deleting && (
        <ConfirmDialog
          title="Xóa báo cáo họp giáo viên"
          confirm="Xóa"
          danger
          action={() => qualityApi.deleteMeeting(deleting.id)}
          onClose={() => setDeleting(null)}
          onDone={done}
        >
          Xóa báo cáo họp với <strong>{deleting.teacher}</strong> tuần <strong>{weekLabel(deleting.week_start, deleting.week_end)}</strong>?
        </ConfirmDialog>
      )}
    </div>
  )
}

function Item({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div>
      <dt className="text-xs text-on-surface-variant">{label}</dt>
      <dd className="font-semibold">{children}</dd>
    </div>
  )
}

function MeetingDialog({ m, d, onClose, onDone }: { m: Meeting | null; d: Data; onClose: () => void; onDone: (msg: string) => void }) {
  const [f, setF] = useState(() => meetingForm(m, d.current_week))
  const set = (k: keyof typeof f) => (e: { target: { value: string } }) => setF({ ...f, [k]: e.target.value })
  const allClasses = withCurrent(d.classes, m?.class_id ? { value: m.class_id, label: m.class ?? '', course: m.course, teacher_ids: [m.teacher_id] } : null)
  const classes = classesForTeacher(allClasses, f.teacher_id)
  const course = classes.find((c) => c.value === f.class_id)?.course
  const range = weekRange(f.week_start)
  const save = useMutation({
    mutationFn: () => (m ? qualityApi.updateMeeting(m.id, toMeetingInput(f)) : qualityApi.createMeeting(toMeetingInput(f))),
    onSuccess: (r) => onDone(r.message),
  })
  const submit = (e: FormEvent) => {
    e.preventDefault()
    save.mutate()
  }
  return (
    <Dialog title={m ? 'Sửa báo cáo họp giáo viên' : 'Thêm báo cáo họp giáo viên'} onClose={onClose} wide>
      <form className="space-y-6" onSubmit={submit}>
        {save.isError && <Alert>{errorMessage(save.error)}</Alert>}
        <section className="space-y-4">
          <h3 className="font-semibold">Thông tin chung</h3>
          <div className="grid gap-4 sm:grid-cols-3">
            <Field label="Chọn tuần" type="date" required value={f.week_start} onChange={set('week_start')} hint={range ? `Tuần ${range}` : undefined} />
            <Select
              label="Chọn giáo viên"
              required
              value={f.teacher_id}
              onChange={(e) => {
                const teacher = e.target.value
                setF({ ...f, teacher_id: teacher, class_id: pickClass(classesForTeacher(allClasses, teacher), f.class_id) })
              }}
            >
              <option value="">-- Chọn giáo viên --</option>
              {d.teachers.map((t) => (
                <option key={t.value} value={t.value}>
                  {t.label}
                </option>
              ))}
            </Select>
            <div>
              <Select label="Chọn lớp học" value={f.class_id} onChange={set('class_id')}>
                <option value="">{f.teacher_id ? '-- Chọn lớp --' : 'Chọn giáo viên trước'}</option>
                {classes.map((c) => (
                  <option key={c.value} value={c.value}>
                    {c.label}
                  </option>
                ))}
              </Select>
              {course && <p className="mt-1.5 text-xs text-on-surface-variant">Chương trình: {course}</p>}
            </div>
          </div>
        </section>
        <section className="space-y-4">
          <h3 className="font-semibold">Nội dung chuyên môn</h3>
          <div className="grid gap-4 sm:grid-cols-2">
            <Textarea label="Ghi chú Syllabus" hint="Tùy chọn" placeholder="Nhập ghi chú về tiến độ Syllabus..." value={f.syllabus_note} onChange={set('syllabus_note')} />
            <Textarea label="Ghi chú Bảng điểm" hint="Tùy chọn" placeholder="Nhập ghi chú về tình hình điểm số..." value={f.scores_note} onChange={set('scores_note')} />
          </div>
          <Textarea
            label="Ghi chú tình hình lớp / học sinh"
            required
            rows={4}
            placeholder="Nhập đánh giá chung về lớp, học sinh cần lưu ý..."
            value={f.class_note}
            onChange={set('class_note')}
          />
        </section>
        <section className="space-y-4">
          <h3 className="font-semibold">Đề xuất & Hành động</h3>
          <Textarea label="Order học thuật" placeholder="Yêu cầu từ giáo viên gửi bộ phận học thuật..." value={f.academic_order} onChange={set('academic_order')} />
          <div className="grid gap-4 sm:grid-cols-2">
            <Textarea label="Nhận xét / đề xuất của Học thuật" placeholder="Phản hồi từ bộ phận học thuật..." value={f.recommendation} onChange={set('recommendation')} />
            <Select label="Tình trạng xử lý" required value={f.status} onChange={set('status')}>
              {d.statuses.map((s) => (
                <option key={s.value} value={s.value}>
                  {s.label}
                </option>
              ))}
            </Select>
          </div>
        </section>
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            Hủy
          </Button>
          <Button type="submit" disabled={save.isPending}>
            Lưu báo cáo
          </Button>
        </div>
      </form>
    </Dialog>
  )
}
