// Giao chặng học cho giáo viên (SyllabusController::assignments): lịch sử giao chặng, thiết lập chặng mới, sửa / đóng tay.
import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { errorMessage } from '../../api'
import { Alert, Badge, Button, Checkbox, Dialog, Field, Icon, Select } from '../../components/ui'
import { syllabusApi, type Assignment, type AssignmentsPage as Page } from './api'
import {
  ErrorText,
  LinkButton,
  NoticeBar,
  PageHeader,
  Pagination,
  EmptyRow,
  TableCard,
  tableCls,
  tbodyCls,
  td,
  TextArea,
  th,
  useAction,
} from './components'
import { fmtDate, todayISO } from './logic'

const label = (a: Assignment) => `${a.stage_label} — lớp ${a.class_name ?? ''}`

export function AssignmentsPage() {
  const [draft, setDraft] = useState({
    search: '',
    class_id: '',
    status: '',
  })
  const [filters, setFilters] = useState({ ...draft, page: 1 })
  const [creating, setCreating] = useState(false)
  const [editing, setEditing] = useState<Assignment | null>(null)
  const [closing, setClosing] = useState<Assignment | null>(null)
  const list = useQuery({
    queryKey: ['syllabus', 'assignments', filters],
    queryFn: () => syllabusApi.assignments(filters),
  })
  const done = useAction(async (m: string) => ({ message: m }))
  const d = list.data
  const apply = (next: typeof draft) => {
    setDraft(next)
    setFilters({ ...next, page: 1 })
  }

  return (
    <>
      <PageHeader
        title="Giao chặng học cho giáo viên"
        description="Thiết lập quyền truy cập giáo trình theo từng chặng học cho giáo viên của từng lớp."
        actions={
          <>
            {d?.can_view_log && (
              <LinkButton href={`/activity-logs?log_name=${encodeURIComponent('Giáo trình & Syllabus')}`} icon="history">
                Xem log hệ thống
              </LinkButton>
            )}
            <LinkButton href="/syllabus/teacher-view" icon="menu_book">
              Màn GV xem giáo trình
            </LinkButton>
            {d?.can_manage && (
              <Button className="gap-1.5" onClick={() => setCreating(true)}>
                <Icon name="add_task" className="text-[18px]" />
                Thiết lập chặng mới
              </Button>
            )}
          </>
        }
      />
      <NoticeBar notice={done.notice} onClose={() => done.setNotice(null)} />
      <TableCard
        header={
          <>
            <h2 className="flex items-center gap-2 font-semibold">
              <Icon name="history_edu" className="text-primary" />
              Lịch sử phân quyền chặng học <Badge>{d?.total ?? 0} lượt</Badge>
            </h2>
            <form
              className="flex flex-wrap items-end gap-2"
              onSubmit={(e) => {
                e.preventDefault()
                apply(draft)
              }}
            >
              <Field
                label="Tìm kiếm"
                placeholder="Tìm tên giáo viên, lớp..."
                value={draft.search}
                onChange={(e) =>
                  setDraft({
                    ...draft,
                    search: e.target.value,
                  })
                }
              />
              <Select
                label="Lớp"
                value={draft.class_id}
                onChange={(e) =>
                  apply({
                    ...draft,
                    class_id: e.target.value,
                  })
                }
              >
                <option value="">Tất cả lớp</option>
                {d?.classes.map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.name}
                  </option>
                ))}
              </Select>
              <Select label="Trạng thái" value={draft.status} onChange={(e) => apply({ ...draft, status: e.target.value })}>
                <option value="">Tất cả trạng thái</option>
                <option value="in_progress">Đang hiệu lực</option>
                <option value="completed">Đã đóng</option>
              </Select>
            </form>
          </>
        }
        footer={
          d && <Pagination page={d.page} pageSize={d.page_size} total={d.total} unit="lượt" onPage={(page) => setFilters({ ...filters, page })} />
        }
      >
        {list.isError && <Alert>{errorMessage(list.error)}</Alert>}
        <table className={`${tableCls} min-w-[860px]`}>
          <thead>
            <tr>
              <th className={th}>Lớp học</th>
              <th className={th}>Giáo viên</th>
              <th className={th}>Chặng học</th>
              <th className={th}>Thời hạn</th>
              <th className={th}>Trạng thái</th>
              <th className={`${th} text-right`}>Hành động</th>
            </tr>
          </thead>
          <tbody className={tbodyCls}>
            {d?.assignments.map((a) => (
              <tr key={a.id}>
                <td className={td}>
                  <p className="font-semibold">{a.class_name || 'Chưa gắn lớp'}</p>
                  <p className="text-xs text-on-surface-variant">{a.class_place || '—'}</p>
                  <p className="text-xs text-on-surface-variant">{a.curriculum}</p>
                </td>
                <td className={td}>
                  <p>{a.teacher || '—'}</p>
                  {a.teacher_code && <p className="text-xs text-on-surface-variant">ID: {a.teacher_code}</p>}
                </td>
                <td className={td}>
                  <Badge tone="info">{a.stage_label || '—'}</Badge>
                  <p className="mt-1 text-xs text-on-surface-variant">{a.assigned_chapters}</p>
                  {a.extra_sessions > 0 && <p className="text-xs text-warning">+{a.extra_sessions} buổi giãn tiến độ</p>}
                </td>
                <td className={`${td} space-y-1 text-xs whitespace-nowrap`}>
                  <p>Bắt đầu: {fmtDate(a.start_date)}</p>
                  {a.closed_at ? (
                    <>
                      <p className="text-on-surface-variant">Đóng: {fmtDate(a.closed_at)}</p>
                      {a.close_reason && <p className="max-w-[200px] whitespace-normal text-on-surface-variant">{a.close_reason}</p>}
                    </>
                  ) : (
                    <>
                      <p className="text-on-surface-variant">Tự động đóng theo sự kiện</p>
                      {a.deadline && <p className="text-on-surface-variant">Dự kiến xong: {fmtDate(a.deadline)}</p>}
                    </>
                  )}
                </td>
                <td className={`${td} whitespace-nowrap`}>
                  {a.is_open ? (
                    <Badge tone="success">Đang hiệu lực</Badge>
                  ) : (
                    <>
                      <Badge>Đã đóng</Badge>
                      {a.curriculum_completed && <p className="mt-1 text-xs text-success">Hoàn thành giáo trình</p>}
                    </>
                  )}
                </td>
                <td className={`${td} text-right whitespace-nowrap`}>
                  {a.is_open ? (
                    <div className="flex justify-end gap-2">
                      {d.can_manage && (
                        <Button variant="secondary" className="h-8 px-3" onClick={() => setEditing(a)}>
                          Sửa
                        </Button>
                      )}
                      {d.can_override && (
                        <Button variant="secondary" className="h-8 px-3" onClick={() => setClosing(a)}>
                          Đóng tay
                        </Button>
                      )}
                    </div>
                  ) : (
                    <span className="text-xs text-on-surface-variant">{a.closing_big_test ? `Big Test ${a.closing_big_test}` : '—'}</span>
                  )}
                </td>
              </tr>
            ))}
            {d && !d.assignments.length && <EmptyRow colSpan={6} icon="assignment_ind" title="Chưa có lớp nào được giao chặng" />}
          </tbody>
        </table>
      </TableCard>
      <section className="mt-6 rounded-xl border border-outline-variant bg-surface-container-low p-4 text-sm">
        <p className="font-semibold">Cần hỗ trợ?</p>
        <p className="mt-1 text-on-surface-variant">
          Liên hệ bộ phận Học thuật hoặc Kỹ thuật nếu bạn gặp vấn đề trong quá trình phân quyền giáo trình.
        </p>
      </section>
      {creating && d && (
        <CreateDialog
          page={d}
          onClose={() => setCreating(false)}
          onDone={(m) => {
            setCreating(false)
            done.run(m)
          }}
        />
      )}
      {editing && d && (
        <EditDialog
          page={d}
          a={editing}
          onClose={() => setEditing(null)}
          onDone={(m) => {
            setEditing(null)
            done.run(m)
          }}
        />
      )}
      {closing && (
        <CloseDialog
          a={closing}
          onClose={() => setClosing(null)}
          onDone={(m) => {
            setClosing(null)
            done.run(m)
          }}
        />
      )}
    </>
  )
}

type DialogProps = { onClose: () => void; onDone: (m: string) => void }

function CreateDialog({ page, onClose, onDone }: DialogProps & { page: Page }) {
  const [f, setF] = useState({
    class_id: '',
    user_id: '',
    curriculum_id: '',
    stage_id: '',
    start_date: todayISO(),
    deadline: '',
    replace_current: false,
    reason: '',
  })
  const stages = page.curriculums.find((c) => c.id === f.curriculum_id)?.stages ?? []
  const openStage = page.class_open_stage[f.class_id]
  const save = useAction(
    () =>
      syllabusApi.openAssignment({
        class_id: f.class_id,
        user_id: f.user_id || null,
        curriculum_id: f.curriculum_id || null,
        stage_id: f.stage_id || null,
        start_date: f.start_date || null,
        deadline: f.deadline || null,
        replace_current: f.replace_current,
        reason: f.reason.trim() || null,
      }),
    (m) => onDone(m.message),
  )
  return (
    <Dialog title="Thiết lập chặng mới" onClose={onClose} wide>
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault()
          save.run()
        }}
      >
        <Select
          label="Chọn lớp học"
          required
          value={f.class_id}
          onChange={(e) =>
            setF({
              ...f,
              class_id: e.target.value,
              curriculum_id: page.class_curriculum[e.target.value] ?? f.curriculum_id,
              stage_id: '',
            })
          }
        >
          <option value="" disabled>
            Chọn lớp học đang quản lý...
          </option>
          {page.classes.map((c) => (
            <option key={c.id} value={c.id}>
              {c.name} ({c.code})
            </option>
          ))}
        </Select>
        {openStage && (
          <Alert tone="info">
            Lớp đang học <strong>{openStage}</strong>. Chặng mới chỉ mở được sau khi chặng này đóng
            {page.can_override && ', hoặc chọn "Chuyển chặng" bên dưới'}.
          </Alert>
        )}
        <Select label="Chọn giáo viên" value={f.user_id} onChange={(e) => setF({ ...f, user_id: e.target.value })}>
          <option value="">Chọn giáo viên phụ trách... (mặc định GV chính của lớp)</option>
          {page.teachers.map((t) => (
            <option key={t.value} value={t.value}>
              {t.label}
            </option>
          ))}
        </Select>
        <Select
          label="Giáo trình"
          value={f.curriculum_id}
          onChange={(e) =>
            setF({
              ...f,
              curriculum_id: e.target.value,
              stage_id: '',
            })
          }
        >
          <option value="">— Theo trình độ của lớp —</option>
          {page.curriculums.map((c) => (
            <option key={c.id} value={c.id}>
              {c.title} ({c.code})
            </option>
          ))}
        </Select>
        <Select label="Chọn chặng học" value={f.stage_id} onChange={(e) => setF({ ...f, stage_id: e.target.value })}>
          <option value="">Chọn chặng giáo trình... (chặng kế tiếp của lớp)</option>
          {stages.map((s) => (
            <option key={s.value} value={s.value}>
              {s.label}
            </option>
          ))}
        </Select>
        <div className="grid grid-cols-2 gap-3">
          <Field label="Ngày bắt đầu" type="date" value={f.start_date} onChange={(e) => setF({ ...f, start_date: e.target.value })} />
          <Field label="Dự kiến hoàn thành" type="date" value={f.deadline} onChange={(e) => setF({ ...f, deadline: e.target.value })} />
        </div>
        {page.can_override && (
          <div className="space-y-2 border-t border-outline-variant pt-3">
            <Checkbox
              label={
                <span>
                  <strong>Chuyển chặng (Học thuật):</strong> đóng chặng đang mở của lớp rồi mở chặng đã chọn.
                </span>
              }
              checked={f.replace_current}
              onChange={(e) =>
                setF({
                  ...f,
                  replace_current: e.target.checked,
                })
              }
            />
            {f.replace_current && (
              <TextArea
                label="Lý do"
                rows={2}
                placeholder="VD: Lớp đã thi chặng 1 ở cơ sở cũ, chuyển thẳng chặng 2"
                value={f.reason}
                onChange={(e) => setF({ ...f, reason: e.target.value })}
              />
            )}
          </div>
        )}
        <Alert tone="info">
          Mỗi LỚP HỌC chỉ được giao duy nhất 1 chặng học có hiệu lực tại một thời điểm. Hệ thống sẽ tự động đóng chặng hiện tại của lớp và mở chặng kế
          tiếp khi kết quả Big Test được duyệt gửi.
        </Alert>
        <ErrorText error={save.error} />
        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={onClose}>
            Hủy
          </Button>
          <Button type="submit" disabled={save.pending}>
            Xác nhận giao chặng
          </Button>
        </div>
      </form>
    </Dialog>
  )
}

function EditDialog({ page, a, onClose, onDone }: DialogProps & { page: Page; a: Assignment }) {
  const [f, setF] = useState({
    user_id: a.user_id,
    start_date: a.start_date,
    deadline: a.deadline,
  })
  const save = useAction(
    () =>
      syllabusApi.updateAssignment(a.id, {
        user_id: f.user_id,
        start_date: f.start_date || null,
        deadline: f.deadline || null,
      }),
    (m) => onDone(m.message),
  )
  return (
    <Dialog title="Chỉnh sửa chặng đang hiệu lực" onClose={onClose}>
      <form
        className="space-y-3"
        onSubmit={(e) => {
          e.preventDefault()
          save.run()
        }}
      >
        <p className="text-sm text-on-surface-variant">
          Chặng: <strong>{label(a)}</strong>. Muốn đổi sang chặng khác, dùng "Chuyển chặng" (Học thuật, bắt buộc lý do).
        </p>
        <Select label="Giáo viên phụ trách" required value={f.user_id} onChange={(e) => setF({ ...f, user_id: e.target.value })}>
          {page.teachers.map((t) => (
            <option key={t.value} value={t.value}>
              {t.label}
            </option>
          ))}
        </Select>
        <div className="grid grid-cols-2 gap-3">
          <Field label="Ngày bắt đầu" type="date" value={f.start_date} onChange={(e) => setF({ ...f, start_date: e.target.value })} />
          <Field label="Dự kiến hoàn thành" type="date" value={f.deadline} onChange={(e) => setF({ ...f, deadline: e.target.value })} />
        </div>
        <ErrorText error={save.error} />
        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={onClose}>
            Hủy
          </Button>
          <Button type="submit" disabled={save.pending}>
            Lưu thay đổi
          </Button>
        </div>
      </form>
    </Dialog>
  )
}

function CloseDialog({ a, onClose, onDone }: DialogProps & { a: Assignment }) {
  const [reason, setReason] = useState('')
  const [next, setNext] = useState(true)
  const save = useAction(
    () => syllabusApi.closeAssignment(a.id, reason, next),
    (m) => onDone(m.message),
  )
  return (
    <Dialog title="Đóng tay chặng đang mở" onClose={onClose}>
      <form
        className="space-y-3"
        onSubmit={(e) => {
          e.preventDefault()
          save.run()
        }}
      >
        <p className="text-sm text-on-surface-variant">
          Chặng: <strong>{label(a)}</strong>. Thông thường chặng tự đóng khi Big Test được duyệt và gửi phụ huynh — chỉ đóng tay khi có ngoại lệ.
        </p>
        <TextArea label="Lý do" required rows={3} value={reason} onChange={(e) => setReason(e.target.value)} />
        <Checkbox label="Mở luôn chặng kế tiếp của giáo trình" checked={next} onChange={(e) => setNext(e.target.checked)} />
        <ErrorText error={save.error} />
        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={onClose}>
            Hủy
          </Button>
          <Button type="submit" disabled={save.pending}>
            Đóng chặng
          </Button>
        </div>
      </form>
    </Dialog>
  )
}
