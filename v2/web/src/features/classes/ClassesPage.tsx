// Danh sách lớp (ClassManagementController@index): chip trạng thái / chương trình / cấp độ, tìm kiếm, chi nhánh, thống kê nhanh.
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { errorMessage, type Me } from '../../api'
import { Alert, Button, Card, Field, Select } from '../../components/ui'
import { usePath, navigate } from '../../router'
import { classesApi, type ClassFilter } from './api'
import { AttendancePage } from './AttendancePage'
import { ClassDetailPage } from './ClassDetailPage'
import { ClassFormPage } from './ClassFormPage'
import { Chip, Header, Loading, Pill, Stat, td, th } from './components'
import { parseClassPath, seatText } from './logic'

export function ClassesPage({ me }: { me: Me }) {
  const route = parseClassPath(usePath())
  switch (route.page) {
    case 'new':
      return <ClassFormPage />
    case 'edit':
      return <ClassFormPage key={route.id} id={route.id} />
    case 'attendance':
      return <AttendancePage key={route.id} id={route.id} />
    case 'show':
      return <ClassDetailPage key={route.id} me={me} id={route.id} />
    default:
      return <ClassListPage />
  }
}

function ClassListPage() {
  const [f, setF] = useState<ClassFilter>({ page: 1 })
  const [q, setQ] = useState('')
  const list = useQuery({ queryKey: ['classes', f], queryFn: () => classesApi.list(f), placeholderData: keepPreviousData })
  const set = (patch: Partial<ClassFilter>) => setF({ ...f, page: 1, ...patch })
  const d = list.data
  const pages = d ? Math.max(1, Math.ceil(d.total / d.per_page)) : 1

  return (
    <>
      <Header
        title="Danh sách lớp"
        description="Mỗi lớp một trang: lịch, học viên, điểm danh và việc tiếp theo."
        actions={d?.can_create && <Button onClick={() => navigate('/classes/new')}>Tạo lớp mới</Button>}
      />
      <div className="space-y-4">
        {d && (
          <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
            <Stat label="Đang học" value={d.stats.active} />
            <Stat label="Chờ lịch" value={d.stats.pending_schedule} />
            <Stat label="Chưa đủ ngưỡng khai giảng" value={d.stats.short} />
            <Stat label="Buổi hôm nay chưa điểm danh" value={d.stats.missing_attendance} tone={d.stats.missing_attendance ? 'error' : undefined} />
          </div>
        )}
        <Card>
          <form
            className="grid gap-3 sm:grid-cols-[1fr_16rem_auto] sm:items-end"
            onSubmit={(e) => {
              e.preventDefault()
              set({ q })
            }}
          >
            <Field label="Tìm lớp" placeholder="Tên hoặc mã lớp" value={q} onChange={(e) => setQ(e.target.value)} />
            <Select label="Chi nhánh" value={f.branch_id ?? ''} onChange={(e) => set({ branch_id: e.target.value })}>
              <option value="">Tất cả chi nhánh</option>
              {d?.branches.map((b) => (
                <option key={b.value} value={b.value}>
                  {b.label}
                </option>
              ))}
            </Select>
            <Button type="submit" variant="secondary">
              Tìm
            </Button>
          </form>
          {d && (
            <div className="mt-4 space-y-2">
              <div className="flex flex-wrap gap-2" aria-label="Trạng thái">
                <Chip active={!f.status} onClick={() => set({ status: '' })}>
                  Tất cả ({d.open_count})
                </Chip>
                {d.statuses.map((s) => (
                  <Chip key={s.key} active={f.status === s.key} onClick={() => set({ status: f.status === s.key ? '' : s.key })}>
                    {s.label} ({s.count})
                  </Chip>
                ))}
              </div>
              {d.program_counts.length > 0 && (
                <div className="flex flex-wrap gap-2" aria-label="Chương trình">
                  {d.program_counts.map((p) => (
                    <Chip key={p.label} active={f.program === p.label} onClick={() => set({ program: f.program === p.label ? '' : p.label })}>
                      {p.label} ({p.total})
                    </Chip>
                  ))}
                </div>
              )}
              {d.level_counts.length > 0 && (
                <div className="flex flex-wrap gap-2" aria-label="Cấp độ">
                  {d.level_counts.map((p) => (
                    <Chip key={p.label} active={f.level === p.label} onClick={() => set({ level: f.level === p.label ? '' : p.label })}>
                      {p.label} ({p.total})
                    </Chip>
                  ))}
                </div>
              )}
            </div>
          )}
        </Card>
        <Card>
          {list.isError && <Alert>{errorMessage(list.error)}</Alert>}
          {list.isPending && <Loading />}
          {d && d.classes.length === 0 && <p className="text-sm text-on-surface-variant">Không có lớp nào phù hợp.</p>}
          {d && d.classes.length > 0 && (
            <div className="overflow-x-auto">
              <table className="w-full min-w-[760px] text-left text-sm">
                <thead className="text-xs text-on-surface-variant">
                  <tr>
                    <th className={th}>Lớp</th>
                    <th className={th}>Lịch học</th>
                    <th className={th}>Giáo viên</th>
                    <th className={th}>Sĩ số</th>
                    <th className={th}>Buổi</th>
                    <th className={th}>Việc tiếp theo</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-surface-container-highest">
                  {d.classes.map((c) => (
                    <tr key={c.id} className="cursor-pointer hover:bg-surface-container-low" onClick={() => navigate(`/classes/${c.id}`)}>
                      <td className={td}>
                        <p className="font-medium">{c.name}</p>
                        <p className="text-xs text-on-surface-variant">
                          <span className="font-mono">{c.code}</span> · {c.meta}
                        </p>
                        <div className="mt-1">
                          <Pill tone={c.status.color}>{c.status.label}</Pill>
                        </div>
                      </td>
                      <td className={td}>{c.schedule_text || '—'}</td>
                      <td className={td}>
                        {c.teacher ?? '—'}
                        {c.foreign_teacher && <p className="text-xs text-on-surface-variant">GVNN: {c.foreign_teacher}</p>}
                      </td>
                      <td className={`${td} font-mono`}>
                        {seatText(c.seat.occupied, c.seat.capacity)}
                        {c.seat.not_started && c.seat.needed > 0 && <p className="text-xs text-warning">thiếu {c.seat.needed}</p>}
                      </td>
                      <td className={`${td} font-mono`}>
                        {c.done_sessions}/{c.total_sessions}
                      </td>
                      <td className={td}>{c.next && <Pill tone={c.next.tone}>{c.next.label}</Pill>}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
          {d && pages > 1 && (
            <div className="mt-4 flex items-center justify-end gap-2 text-sm">
              <Button variant="secondary" disabled={(f.page ?? 1) <= 1} onClick={() => setF({ ...f, page: (f.page ?? 1) - 1 })}>
                Trước
              </Button>
              <span>
                Trang {f.page ?? 1}/{pages}
              </span>
              <Button variant="secondary" disabled={(f.page ?? 1) >= pages} onClick={() => setF({ ...f, page: (f.page ?? 1) + 1 })}>
                Sau
              </Button>
            </div>
          )}
        </Card>
      </div>
    </>
  )
}
