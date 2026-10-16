// Tiếp nhận & Xếp lớp (Laravel Students/Enrollments): xếp lớp chính (chuyển lớp), liên kết lớp học song song, checklist
// bàn giao (giáo trình, nhóm Zalo lớp). Dữ liệu từ phân hệ Lớp học (/class-enrollments).
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { errorMessage, type Me } from '../../api'
import { Alert, Button, Card, Select } from '../../components/ui'
import { navigate } from '../../router'
import { classesApi, type Enrollment, type EnrollmentList } from '../classes/api'
import { PageHeader, StatusBadge } from './components'
import { formatDate, pageCount } from './logic'

export function EnrollmentsPage({ me: _me }: { me: Me }) {
  const qc = useQueryClient()
  const [page, setPage] = useState(1)
  const [notice, setNotice] = useState<{ tone: 'success' | 'error'; text: string } | null>(null)
  const list = useQuery({
    queryKey: ['class-enrollments', page],
    queryFn: () => classesApi.enrollments({ page }),
    placeholderData: keepPreviousData,
  })
  const done = (text: string) => {
    setNotice({ tone: 'success', text })
    qc.invalidateQueries({ queryKey: ['class-enrollments'] })
    qc.invalidateQueries({ queryKey: ['classes'] })
    qc.invalidateQueries({ queryKey: ['students'] })
  }
  const fail = (e: unknown) => setNotice({ tone: 'error', text: errorMessage(e) })
  const d = list.data

  return (
    <>
      <PageHeader
        title="Tiếp nhận & Xếp lớp"
        description="Xếp học viên vào lớp và hoàn tất bàn giao (giáo trình, nhóm Zalo lớp)."
        actions={
          <Button variant="secondary" onClick={() => navigate('/students')}>
            Danh sách học sinh
          </Button>
        }
      />
      <div className="space-y-4">
        {notice && <Alert tone={notice.tone}>{notice.text}</Alert>}
        {d?.can_assign && <AssignForm d={d} onDone={done} onError={fail} />}
        <Card title="Lượt xếp lớp">
          {list.isError && <Alert>{errorMessage(list.error)}</Alert>}
          {d && d.enrollments.length === 0 && <p className="text-sm text-on-surface-variant">Chưa có lượt xếp lớp nào trong phạm vi của bạn.</p>}
          {d && d.enrollments.length > 0 && (
            <div className="overflow-x-auto">
              <table className="w-full min-w-[760px] text-left text-sm">
                <thead className="text-xs text-on-surface-variant">
                  <tr>
                    <th className="py-2 pr-4 font-medium">Học viên</th>
                    <th className="py-2 pr-4 font-medium">Lớp</th>
                    <th className="py-2 pr-4 font-medium">Ngày xếp lớp</th>
                    <th className="py-2 pr-4 font-medium">Bàn giao</th>
                    <th className="py-2 pr-4 font-medium">Trạng thái</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-surface-container-highest">
                  {d.enrollments.map((e) => (
                    <EnrollmentRow key={e.id} e={e} canEdit={d.can_assign} onDone={done} onError={fail} />
                  ))}
                </tbody>
              </table>
            </div>
          )}
          {d && pageCount(d.total, d.per_page) > 1 && (
            <div className="mt-4 flex items-center gap-3 text-sm">
              <Button variant="secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>
                Trước
              </Button>
              <span>
                Trang {page}/{pageCount(d.total, d.per_page)}
              </span>
              <Button variant="secondary" disabled={page >= pageCount(d.total, d.per_page)} onClick={() => setPage(page + 1)}>
                Sau
              </Button>
            </div>
          )}
        </Card>
      </div>
    </>
  )
}

function AssignForm({ d, onDone, onError }: { d: EnrollmentList; onDone: (t: string) => void; onError: (e: unknown) => void }) {
  const [student, setStudent] = useState('')
  const [klass, setKlass] = useState('')
  const st = d.students.find((s) => s.value === student)
  const classes = d.classes.filter((c) => !st?.branch_id || c.branch_id === st.branch_id)
  const m = useMutation({
    mutationFn: (link: boolean) => (link ? classesApi.link : classesApi.enroll)({ student_id: student, class_id: klass }),
    onSuccess: (r) => {
      setKlass('')
      onDone(r.message)
    },
    onError,
  })
  return (
    <Card title="Xếp lớp">
      <form
        className="grid gap-3 md:grid-cols-[1fr_1fr_auto_auto] md:items-end"
        onSubmit={(e) => {
          e.preventDefault()
          m.mutate(false)
        }}
      >
        <Select label="Học viên" value={student} onChange={(e) => setStudent(e.target.value)}>
          <option value="">Chọn học viên</option>
          {d.students.map((s) => (
            <option key={s.value} value={s.value}>
              {s.label}
              {s.branch ? ` · ${s.branch}` : ''}
            </option>
          ))}
        </Select>
        <Select label="Lớp" value={klass} onChange={(e) => setKlass(e.target.value)}>
          <option value="">Chọn lớp</option>
          {classes.map((c) => (
            <option key={c.id} value={c.id}>
              {c.label} · sĩ số {c.seat}
            </option>
          ))}
        </Select>
        <Button type="submit" disabled={!student || !klass || m.isPending}>
          Xếp lớp chính
        </Button>
        <Button type="button" variant="secondary" disabled={!student || !klass || m.isPending} onClick={() => m.mutate(true)}>
          Liên kết lớp khác
        </Button>
      </form>
      <p className="mt-2 text-xs text-on-surface-variant">
        Xếp lớp chính khi học viên đã có lớp là chuyển lớp: lượt ở lớp cũ hết hiệu lực từ hôm nay. Liên kết lớp khác để học song song, không đổi lớp chính.
      </p>
    </Card>
  )
}

function EnrollmentRow({ e, canEdit, onDone, onError }: { e: Enrollment; canEdit: boolean; onDone: (t: string) => void; onError: (e: unknown) => void }) {
  const m = useMutation({
    mutationFn: (b: { curriculum_delivered: boolean; zalo_group_added: boolean }) => classesApi.handoff(e.id, b),
    onSuccess: (r) => onDone(r.message),
    onError,
  })
  const editable = canEdit && !e.dropped && !e.left_at
  const box = (label: string, key: 'curriculum_delivered' | 'zalo_group_added') => (
    <label className="flex items-center gap-2">
      <input
        type="checkbox"
        className="size-4 accent-primary"
        checked={e[key]}
        disabled={!editable || m.isPending}
        onChange={(ev) =>
          m.mutate({ curriculum_delivered: e.curriculum_delivered, zalo_group_added: e.zalo_group_added, [key]: ev.target.checked })
        }
      />
      {label}
    </label>
  )
  return (
    <tr>
      <td className="py-2 pr-4">
        <button type="button" className="font-medium hover:underline" onClick={() => navigate(`/students/${e.student_id}`)}>
          {e.student_name}
        </button>
        <p className="font-mono text-xs text-on-surface-variant">{e.student_code}</p>
      </td>
      <td className="py-2 pr-4">
        <button type="button" className="hover:underline" onClick={() => navigate(`/classes/${e.class_id}`)}>
          {e.class_name}
        </button>
        <p className="text-xs text-on-surface-variant">
          {e.class_code} · {e.branch}
          {e.teacher && ` · ${e.teacher}`}
        </p>
      </td>
      <td className="py-2 pr-4 font-mono">
        {formatDate(e.enrolled_at)}
        {e.left_at && <p className="text-xs text-on-surface-variant">rời lớp {formatDate(e.left_at)}</p>}
      </td>
      <td className="space-y-1 py-2 pr-4">
        {box('Đã nhận giáo trình', 'curriculum_delivered')}
        {box('Đã vào nhóm Zalo lớp', 'zalo_group_added')}
      </td>
      <td className="py-2 pr-4">
        {e.dropped ? (
          <StatusBadge color="error">Thôi học</StatusBadge>
        ) : e.left_at ? (
          <StatusBadge color="secondary">Đã chuyển lớp</StatusBadge>
        ) : e.status === 'completed' ? (
          <StatusBadge color="success">Đã bàn giao</StatusBadge>
        ) : (
          <StatusBadge color="warning">Chờ bàn giao</StatusBadge>
        )}
      </td>
    </tr>
  )
}

// Lớp học của một học viên (tab "Lớp học & lộ trình" của hồ sơ).
export function StudentClasses({ studentId }: { studentId: string }) {
  const list = useQuery({ queryKey: ['class-enrollments', 'student', studentId], queryFn: () => classesApi.enrollments({ student_id: studentId }) })
  if (list.isPending) return <p className="text-sm text-on-surface-variant">Đang tải…</p>
  if (list.isError) return <Alert>{errorMessage(list.error)}</Alert>
  const rows = list.data.enrollments
  return (
    <Card title="Lớp học">
      {rows.length === 0 ? (
        <p className="text-sm text-on-surface-variant">Học viên chưa được xếp lớp.</p>
      ) : (
        <ul className="divide-y divide-surface-container-highest text-sm">
          {rows.map((e) => (
            <li key={e.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
              <span>
                <button type="button" className="font-medium hover:underline" onClick={() => navigate(`/classes/${e.class_id}`)}>
                  {e.class_name}
                </button>{' '}
                <span className="text-xs text-on-surface-variant">
                  {e.class_code} · từ {formatDate(e.enrolled_at)}
                  {e.left_at && ` đến ${formatDate(e.left_at)}`}
                </span>
              </span>
              {e.left_at ? (
                <StatusBadge color="secondary">Đã rời lớp</StatusBadge>
              ) : e.status === 'completed' ? (
                <StatusBadge color="success">Đã bàn giao</StatusBadge>
              ) : (
                <StatusBadge color="warning">Chờ bàn giao</StatusBadge>
              )}
            </li>
          ))}
        </ul>
      )}
    </Card>
  )
}
