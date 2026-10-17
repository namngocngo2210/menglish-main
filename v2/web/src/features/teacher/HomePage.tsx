// Check-in & Điểm danh hôm nay (TeacherPortalController::home): ca hôm nay (kèm ca tối qua còn hạn check-in), check-in
// nhiều ca, buổi chưa điểm danh trong 30 ngày, lịch tuần, học sinh cần chú ý, chấm công / lương tạm tính.
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { errorMessage } from '../../api'
import { Alert, Badge, Button, Card, Icon } from '../../components/ui'
import { navigate, useQueryParam } from '../../router'
import { teacherApi, type Home, type Shift } from './api'
import { Empty, Header, LinkButton, LoadError, Loading } from './components'
import { attendanceHref, checkinState, classHref, countdown, formatMoney, selectable } from './logic'

export function HomePage() {
  const week = useQueryParam('week')
  const home = useQuery({ queryKey: ['teacher-home', week], queryFn: () => teacherApi.home(week || undefined) })
  // Thông báo check-in giữ qua lần tải lại trang sau khi check-in.
  const [done, setDone] = useState<Done>(null)
  if (home.isPending) return <Loading />
  if (home.isError) return <LoadError title="Không mở được cổng giáo viên" error={home.error} />
  return <HomeView key={home.dataUpdatedAt} home={home.data} done={done} setDone={setDone} />
}

type Done = { message: string; warning?: string } | null

function HomeView({ home, done, setDone }: { home: Home; done: Done; setDone: (d: Done) => void }) {
  const qc = useQueryClient()
  // Đếm ngược hạn check-in từ số giây máy chủ gửi, cập nhật mỗi 30 giây.
  const [loadedAt] = useState(() => Date.now())
  const [elapsed, setElapsed] = useState(0)
  useEffect(() => {
    const t = setInterval(() => setElapsed(Date.now() - loadedAt), 30000)
    return () => clearInterval(t)
  }, [loadedAt])
  const [picked, setPicked] = useState<string[]>([])
  const checkin = useMutation({
    mutationFn: () => teacherApi.checkin(picked),
    onSuccess: (r) => {
      setDone(r)
      setPicked([])
      qc.invalidateQueries({ queryKey: ['teacher-home'] })
    },
  })
  const choosable = home.shifts.filter((s) => selectable(s, elapsed, home.can_checkin))
  const w = home.widgets

  return (
    <div className="space-y-4">
      <Header
        title="Tổng quan hôm nay"
        description={
          <>
            Xin chào <span className="font-semibold text-on-surface">{home.teacher_name}</span> · Cổng Giáo viên
          </>
        }
        actions={
          <>
            <LinkButton to="/teacher/trial-guests" icon="person_add">
              Khách học thử
            </LinkButton>
            <LinkButton to="/teacher/general-report" icon="summarize">
              Báo cáo giảng dạy
            </LinkButton>
          </>
        }
      />

      <Card title={`Lịch dạy hôm nay — ${home.today_label}`}>
        <p className="mb-4 text-sm text-on-surface-variant">
          {home.stats.total > 0
            ? `Bạn có ${home.stats.total} ca dạy trong ngày hôm nay · đã check-in ${home.stats.checked_in} · đã điểm danh ${home.stats.attendance_done}.`
            : 'Hôm nay bạn không có buổi dạy nào trên lịch.'}
        </p>
        {home.next_shift && (
          <div className="mb-4 flex flex-col gap-3 rounded-lg border border-primary/20 bg-primary/5 p-3 sm:flex-row sm:items-center">
            <Icon name="warning" className="text-primary" />
            <div className="flex-1 text-sm">
              <p className="font-medium">
                Ca dạy lúc {home.next_shift.start_time} {home.next_shift.upcoming ? 'sắp bắt đầu!' : 'đang diễn ra!'}
              </p>
              <p className="text-xs text-on-surface-variant">Vui lòng hoàn thành thủ tục điểm danh tại lớp học.</p>
            </div>
            <LinkButton primary to={attendanceHref(home.next_shift.class_id, home.next_shift.session_id)}>
              Điểm danh ngay
            </LinkButton>
          </div>
        )}
        {!home.can_checkin && home.shifts.length > 0 && (
          <div className="mb-4">
            <Alert tone="info">Check-in giờ dạy: {w.work_pending || 'chưa dùng được trên hệ thống mới'}. Vui lòng check-in trên hệ thống cũ.</Alert>
          </div>
        )}
        {done && (
          <div className="mb-4 space-y-2">
            <Alert tone="success">{done.message}</Alert>
            {done.warning && <Alert tone="info">{done.warning}</Alert>}
          </div>
        )}
        {checkin.isError && (
          <div className="mb-4">
            <Alert>{errorMessage(checkin.error)}</Alert>
          </div>
        )}
        {home.shifts.length === 0 ? (
          <Empty>Không có ca dạy hôm nay. Lịch dạy được sinh từ TKB của các lớp bạn phụ trách.</Empty>
        ) : (
          <form
            onSubmit={(e) => {
              e.preventDefault()
              setDone(null)
              checkin.mutate()
            }}
            className="space-y-4"
          >
            <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
              {home.shifts.map((s) => (
                <ShiftCard
                  key={s.session_id}
                  shift={s}
                  elapsed={elapsed}
                  canPick={selectable(s, elapsed, home.can_checkin)}
                  picked={picked.includes(s.session_id)}
                  onPick={(on) => setPicked((cur) => (on ? [...cur, s.session_id] : cur.filter((x) => x !== s.session_id)))}
                />
              ))}
            </div>
            {choosable.length > 0 && (
              <div className="flex justify-end">
                <Button type="submit" disabled={picked.length === 0 || checkin.isPending} className="w-full md:w-auto">
                  {checkin.isPending ? 'Đang check-in…' : 'Check-in các ca đã chọn'}
                </Button>
              </div>
            )}
          </form>
        )}
      </Card>

      {home.pending_sessions.length > 0 && <PendingCard home={home} />}

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <Card title="Học sinh cần chú ý">
          {w.attention.length === 0 ? (
            <Empty>Không có học sinh dưới 7/10 trong 30 ngày qua.</Empty>
          ) : (
            <ul className="divide-y divide-surface-container-highest text-sm">
              {w.attention.map((a) => (
                <li key={a.id} className="flex items-center justify-between gap-2 py-2">
                  <div className="min-w-0">
                    <p className="font-medium">{a.student_name}</p>
                    <p className="truncate text-xs text-on-surface-variant">
                      {a.class_name} · {a.name}
                    </p>
                  </div>
                  <Badge tone="error">{a.score}</Badge>
                </li>
              ))}
            </ul>
          )}
        </Card>
        <Card title={`Lương tạm tính tháng ${w.month}`}>
          {w.work_pending ? (
            <Empty>{w.work_pending}.</Empty>
          ) : (
            <div className="space-y-1 text-sm">
              <p className="text-lg font-semibold">{w.estimate != null ? formatMoney(w.estimate) : 'Chưa có đơn giá'}</p>
              <p className="text-on-surface-variant">
                {w.hours_label} giờ dạy · 01/{w.period} – {w.today_dm}
              </p>
            </div>
          )}
        </Card>
        <Card title="Chấm công & vi phạm">
          {w.work_pending ? (
            <Empty>{w.work_pending}.</Empty>
          ) : (
            <div className="space-y-1 text-sm">
              <p>
                {w.timesheets_total} phiên chấm công · {w.timesheets_pending} chờ duyệt
              </p>
              <p>
                {w.violations_count} vi phạm trong tháng
                {w.latest_violation && ` · gần nhất ${w.latest_violation.violation_type} (${w.latest_violation.date}, ${w.latest_violation.status_label})`}
              </p>
            </div>
          )}
        </Card>
      </div>

      <WeekCard home={home} />
    </div>
  )
}

function ShiftCard({
  shift: s,
  elapsed,
  canPick,
  picked,
  onPick,
}: {
  shift: Shift
  elapsed: number
  canPick: boolean
  picked: boolean
  onPick: (on: boolean) => void
}) {
  const st = checkinState(s, elapsed)
  return (
    <div className={`rounded-lg border p-3 ${s.checked_in ? 'border-success/40' : 'border-outline-variant'} bg-surface-container-low`}>
      <div className="flex items-start gap-3">
        {canPick && (
          <input
            type="checkbox"
            className="mt-1 size-5 shrink-0 accent-primary"
            aria-label={`Chọn ca ${s.class_name} để check-in`}
            checked={picked}
            onChange={(e) => onPick(e.target.checked)}
          />
        )}
        <Icon name={s.checked_in ? 'check_circle' : 'schedule'} className="mt-0.5 text-primary" />
        <div className="min-w-0 flex-1 text-sm">
          <p className="flex flex-wrap items-center gap-1 font-semibold">
            {s.class_name}
            {s.type_label && <Badge tone="info">{s.type_label}</Badge>}
            {!s.is_today && <Badge tone="warning">Ca ngày {s.date_label}</Badge>}
          </p>
          <p className="text-on-surface-variant">
            {s.shift_name && `${s.shift_name} · `}
            {s.scheduled_time} • {s.room_label || 'Chưa có phòng'}, {s.branch_name}
          </p>
          <p className="text-xs text-on-surface-variant">
            {s.class_code} · {s.student_count} HV
            {s.trial_count > 0 && <span className="font-semibold text-primary"> · +{s.trial_count} khách học thử</span>}
            {s.checked_in && <span className="font-semibold text-success"> · Đã check-in {s.checkin_time}</span>}
          </p>
          {st?.expired && <p className="mt-1 text-xs font-semibold text-error">Quá hạn chấm công — liên hệ Học vụ</p>}
          {st && !st.expired && (
            <p className={`mt-1 text-xs ${st.warning ? 'font-semibold text-error' : 'text-on-surface-variant'}`}>
              {st.warning && 'Còn dưới 15 phút để chấm công · '}
              Hạn chấm công {s.checkin.deadline} (còn {countdown(st.left)})
            </p>
          )}
        </div>
      </div>
      <div className="mt-3 flex flex-wrap gap-1.5 border-t border-outline-variant/60 pt-2">
        <LinkButton to={attendanceHref(s.class_id, s.session_id)} icon="fact_check" primary={!s.attendance_done}>
          {s.attendance_done ? 'Đã điểm danh' : 'Điểm danh'}
        </LinkButton>
        {!s.is_support && (
          <>
            <LinkButton to={classHref(s.class_id, 'remarks', s.session_id)} icon="rate_review">
              Nhận xét
            </LinkButton>
            <LinkButton to={classHref(s.class_id, 'homework', s.session_id)} icon="assignment">
              Giao bài
            </LinkButton>
            <LinkButton to={classHref(s.class_id, 'scores')} icon="grading">
              Nhập điểm
            </LinkButton>
          </>
        )}
      </div>
    </div>
  )
}

function PendingCard({ home }: { home: Home }) {
  const [all, setAll] = useState(false)
  const list = all ? home.pending_sessions : home.pending_sessions.slice(0, 5)
  return (
    <Card title={`Buổi chưa điểm danh (${home.pending_sessions.length})`}>
      <p className="mb-3 text-sm text-on-surface-variant">Các buổi đã qua trong 30 ngày chưa có điểm danh — điểm danh bù để tính chuyên cần.</p>
      <ul className="divide-y divide-surface-container-highest text-sm">
        {list.map((p) => (
          <li key={p.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
            <div>
              <p className="font-medium">
                {p.class_name} {p.type_label && <Badge tone="info">{p.type_label}</Badge>}
              </p>
              <p className="text-xs text-on-surface-variant">
                {p.date} · {p.time}
              </p>
            </div>
            <LinkButton to={attendanceHref(p.class_id, p.id)} icon="fact_check">
              Điểm danh bù
            </LinkButton>
          </li>
        ))}
      </ul>
      {home.pending_sessions.length > 5 && (
        <button type="button" className="mt-2 text-sm font-medium text-primary hover:underline" onClick={() => setAll((v) => !v)}>
          {all ? 'Thu gọn' : `Xem tất cả ${home.pending_sessions.length} buổi`}
        </button>
      )}
    </Card>
  )
}

function WeekCard({ home }: { home: Home }) {
  return (
    <Card title={`Lịch dạy tuần ${home.week.label}`}>
      <div className="mb-3 flex gap-2">
        <Button variant="secondary" className="h-8 px-3 text-xs" onClick={() => navigate(`/teacher/home?week=${home.week.prev}`)}>
          ‹ Tuần trước
        </Button>
        <Button variant="secondary" className="h-8 px-3 text-xs" onClick={() => navigate('/teacher/home')}>
          Tuần này
        </Button>
        <Button variant="secondary" className="h-8 px-3 text-xs" onClick={() => navigate(`/teacher/home?week=${home.week.next}`)}>
          Tuần sau ›
        </Button>
      </div>
      <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-7">
        {home.week_days.map((d) => (
          <div key={d.date} className={`rounded-lg border p-2 ${d.is_today ? 'border-primary' : 'border-surface-container-highest'}`}>
            <p className={`mb-1 text-xs font-semibold ${d.is_today ? 'text-primary' : 'text-on-surface-variant'}`}>{d.label}</p>
            {d.sessions.length === 0 ? (
              <p className="text-xs text-on-surface-subtle">—</p>
            ) : (
              <ul className="space-y-1.5">
                {d.sessions.map((s) => (
                  <li key={s.id} className={`text-xs ${s.cancelled ? 'text-on-surface-subtle line-through' : ''}`}>
                    <p className="font-medium">{s.class_name}</p>
                    <p className="text-on-surface-variant">
                      {s.time}
                      {s.room && ` · ${s.room}`}
                      {s.type_label && ` · ${s.type_label}`}
                    </p>
                    {s.done && <Badge tone="success">Đã điểm danh</Badge>}
                    {!s.done && s.can_take && (
                      <a
                        href={attendanceHref(s.class_id, s.id)}
                        className="font-medium text-primary hover:underline"
                        onClick={(e) => {
                          e.preventDefault()
                          navigate(attendanceHref(s.class_id, s.id))
                        }}
                      >
                        Điểm danh
                      </a>
                    )}
                    {s.checkin_overdue && <p className="text-error">Quá hạn check-in</p>}
                  </li>
                ))}
              </ul>
            )}
          </div>
        ))}
      </div>
    </Card>
  )
}
