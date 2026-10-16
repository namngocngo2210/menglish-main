// Lịch & TKB lớp (WorkTaskController::scheduleConfig): chọn lớp, tối đa 2 ca học tuần theo khung giờ ca dạy, khoảng ngày và
// phòng học → sinh buổi học thực tế; hỏi chuyển phòng khi phòng đang gán cho lớp chưa bắt đầu học; kết thúc / mở lại lớp.
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { ApiError, errorMessage } from '../../api'
import { Alert, Button, Card, Checkbox, Dialog, Field, Select } from '../../components/ui'
import { navigate } from '../../router'
import { classesApi, type ScheduleConfig, type ScheduleData } from './api'
import { Header, Loading, td, th } from './components'
import { addMinutes, shiftsFor, splitTransfer } from './logic'

export function ScheduleConfigPage() {
  const [classId, setClassId] = useState(() => new URLSearchParams(window.location.search).get('class_id') ?? '')
  const [search, setSearch] = useState('')
  const page = useQuery({
    queryKey: ['schedule-config', classId, search],
    queryFn: () => classesApi.scheduleConfig({ class_id: classId || undefined, class_q: search || undefined }),
    placeholderData: keepPreviousData,
  })
  if (page.isPending) return <Loading />
  if (page.isError) return <Alert>{errorMessage(page.error)}</Alert>
  const p = page.data
  const pick = (id: string) => {
    setClassId(id)
    window.history.replaceState(null, '', id ? `/schedule-config?class_id=${id}` : '/schedule-config')
  }
  return (
    <>
      <Header title="Lịch & TKB lớp" description={`Mỗi ca dạy ${p.shift_duration} phút. Thứ 2–6: ${p.weekday_rule}.`} />
      <div className="space-y-4">
        {p.can_schedule && <ScheduleForm key={`${p.initial.class_id ?? ''}-${classId}`} p={p} classId={classId} onPick={pick} />}
        {p.holiday_sessions.length > 0 && (
          <Card title="Buổi nghỉ lễ sắp tới">
            <ul className="space-y-1 text-sm">
              {p.holiday_sessions.map((h) => (
                <li key={h.id}>
                  {h.class_name} ({h.class_code}) · {h.when} · {h.holiday ?? 'Nghỉ lễ'} →{' '}
                  {h.makeup ? `học bù ${h.makeup}` : <span className="text-error">chưa xếp bù</span>}
                </li>
              ))}
            </ul>
          </Card>
        )}
        {p.off_schedule_classes.length > 0 && (
          <Card title="Lớp có buổi lệch khung giờ ca dạy (60 ngày tới)">
            <ul className="space-y-1 text-sm">
              {p.off_schedule_classes.map((c) => (
                <li key={c.class_id}>
                  {c.class_name} ({c.class_code}) · {c.branch} · {c.sessions} buổi · {c.slots.join(', ')}
                </li>
              ))}
            </ul>
          </Card>
        )}
        <ClassTable p={p} search={search} onSearch={setSearch} onPick={pick} />
      </div>
    </>
  )
}

function ScheduleForm({ p, classId, onPick }: { p: ScheduleConfig; classId: string; onPick: (id: string) => void }) {
  const qc = useQueryClient()
  const data = p.initial
  const [f, setF] = useState<ScheduleData>(data)
  const [room, setRoom] = useState(data.room_id ?? '')
  const [activate, setActivate] = useState(false)
  const [notice, setNotice] = useState('')
  const [transfer, setTransfer] = useState<string | null>(null)
  const set = (patch: Partial<ScheduleData>) => setF((cur) => ({ ...cur, ...patch }))
  const current = classId ? p.schedule_data[classId] : undefined
  const rooms = p.rooms.filter((r) => !current || r.branch_id === current.branch_id)
  const save = useMutation({
    mutationFn: (confirmTransfer: boolean) =>
      classesApi.saveSchedule({
        class_id: classId,
        academic_year: f.academic_year,
        start_date: f.start_date,
        end_date: f.end_date,
        slot1_day: f.slot1_day,
        slot1_start: f.slot1_start,
        slot1_end: f.slot1_end,
        slot2_day: f.slot2_day,
        slot2_start: f.slot2_start,
        slot2_end: f.slot2_end,
        room_id: room,
        activate,
        transfer_room: confirmTransfer,
      }),
    onSuccess: (r) => {
      setTransfer(null)
      setNotice(r.message)
      qc.invalidateQueries({ queryKey: ['schedule-config'] })
      qc.invalidateQueries({ queryKey: ['class'] })
      qc.invalidateQueries({ queryKey: ['classes'] })
    },
    onError: (e) => {
      if (e instanceof ApiError && e.code === 'room_transfer') setTransfer(e.message)
    },
  })
  const error = save.isError && !(save.error instanceof ApiError && save.error.code === 'room_transfer') ? errorMessage(save.error) : ''

  return (
    <Card title="Cấu hình lịch học tuần">
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault()
          setNotice('')
          save.mutate(false)
        }}
      >
        {notice && <Alert tone="success">{notice}</Alert>}
        {error && <Alert>{error}</Alert>}
        <Select label="Lớp" required value={classId} onChange={(e) => onPick(e.target.value)}>
          <option value="">Chọn lớp</option>
          {p.new_classes.length > 0 && (
            <optgroup label="Lớp chưa có TKB">
              {p.new_classes.map((o) => (
                <option key={o.value} value={o.value}>
                  {o.label}
                </option>
              ))}
            </optgroup>
          )}
          {p.scheduled_classes.length > 0 && (
            <optgroup label="Lớp đã có TKB (xếp lại)">
              {p.scheduled_classes.map((o) => (
                <option key={o.value} value={o.value}>
                  {o.label}
                </option>
              ))}
            </optgroup>
          )}
        </Select>
        <div className="grid gap-4 md:grid-cols-3">
          <Select label="Năm học" value={f.academic_year} onChange={(e) => set({ academic_year: e.target.value })}>
            {p.academic_years.map((y) => (
              <option key={y.value} value={y.value}>
                {y.label}
              </option>
            ))}
          </Select>
          <Field label="Từ ngày" type="date" value={f.start_date} onChange={(e) => set({ start_date: e.target.value })} />
          <Field label="Đến ngày" type="date" value={f.end_date} onChange={(e) => set({ end_date: e.target.value })} />
        </div>
        {(['1', '2'] as const).map((n) => (
          <SlotRow key={n} n={n} f={f} set={set} p={p} />
        ))}
        <Select label="Phòng học" value={room} onChange={(e) => setRoom(e.target.value)}>
          <option value="">Chưa gán phòng</option>
          {rooms.map((r) => (
            <option key={r.value} value={r.value}>
              {r.label}
              {r.classes.length > 0 ? ` — đang gán: ${r.classes.map((c) => c.name).join(', ')}` : ''}
            </option>
          ))}
        </Select>
        {current?.status === 'upcoming' && (
          <Checkbox label="Kích hoạt lớp (Đang học) khi lưu lịch" checked={activate} onChange={(e) => setActivate(e.target.checked)} />
        )}
        <p className="text-xs text-on-surface-variant">
          Chỉ xếp buổi từ hôm nay trở đi, bỏ qua ngày nghỉ lễ; buổi đã điểm danh được giữ nguyên.
        </p>
        <div className="flex justify-end gap-2">
          {classId && (
            <Button type="button" variant="secondary" onClick={() => navigate(`/classes/${classId}?tab=schedule`)}>
              Mở trang lớp
            </Button>
          )}
          <Button type="submit" disabled={!classId || save.isPending}>
            Lưu lịch & tạo buổi học
          </Button>
        </div>
      </form>
      {transfer && <TransferDialog message={transfer} pending={save.isPending} onClose={() => setTransfer(null)} onConfirm={() => save.mutate(true)} />}
    </Card>
  )
}

function SlotRow({ n, f, set, p }: { n: '1' | '2'; f: ScheduleData; set: (patch: Partial<ScheduleData>) => void; p: ScheduleConfig }) {
  const day = f[`slot${n}_day`]
  const start = f[`slot${n}_start`]
  const end = f[`slot${n}_end`]
  const shifts = shiftsFor(p.shift_options, day)
  const value = shifts.find((s) => s.start === start && s.end === end)?.value ?? ''
  return (
    <fieldset className="grid gap-4 rounded-lg border border-surface-container-highest p-3 md:grid-cols-[10rem_1fr_8rem_8rem]">
      <legend className="px-1 text-sm font-medium">Ca {n}</legend>
      <Select label="Ngày học" value={day} onChange={(e) => set({ [`slot${n}_day`]: e.target.value })}>
        <option value="">{n === '2' ? 'Không có ca 2' : 'Chọn ngày'}</option>
        {p.weekdays.map((d) => (
          <option key={d} value={d}>
            {d}
          </option>
        ))}
      </Select>
      <Select
        label="Khung giờ"
        value={value}
        disabled={!day}
        onChange={(e) => {
          const s = shifts.find((x) => x.value === e.target.value)
          if (s) set({ [`slot${n}_start`]: s.start, [`slot${n}_end`]: s.end })
        }}
      >
        <option value="">{shifts.length ? 'Giờ tự nhập' : 'Không có khung giờ'}</option>
        {shifts.map((s) => (
          <option key={s.value} value={s.value}>
            {s.label}
          </option>
        ))}
      </Select>
      <Field
        label="Bắt đầu"
        type="time"
        value={start}
        disabled={!day}
        onChange={(e) => set({ [`slot${n}_start`]: e.target.value, [`slot${n}_end`]: addMinutes(e.target.value, p.shift_duration) })}
      />
      <Field label="Kết thúc" type="time" value={end} disabled={!day} onChange={(e) => set({ [`slot${n}_end`]: e.target.value })} />
    </fieldset>
  )
}

function TransferDialog({ message, pending, onClose, onConfirm }: { message: string; pending: boolean; onClose: () => void; onConfirm: () => void }) {
  const { question, lines } = splitTransfer(message)
  return (
    <Dialog title="Chuyển phòng học?" onClose={onClose}>
      <p className="text-sm">{question}</p>
      {lines.length > 0 && (
        <ul className="mt-2 list-disc space-y-1 pl-5 text-sm text-on-surface-variant">
          {lines.map((l) => (
            <li key={l}>{l}</li>
          ))}
        </ul>
      )}
      <div className="mt-4 flex justify-end gap-2">
        <Button variant="secondary" onClick={onClose}>
          Chọn phòng khác
        </Button>
        <Button disabled={pending} onClick={onConfirm}>
          Chuyển phòng
        </Button>
      </div>
    </Dialog>
  )
}

function ClassTable({ p, search, onSearch, onPick }: { p: ScheduleConfig; search: string; onSearch: (s: string) => void; onPick: (id: string) => void }) {
  const qc = useQueryClient()
  const [q, setQ] = useState(search)
  const [notice, setNotice] = useState<{ tone: 'success' | 'error' | 'info'; text: string } | null>(null)
  const toggle = useMutation({
    mutationFn: (id: string) => classesApi.toggle(id),
    onSuccess: (r) => {
      setNotice({ tone: r.warning ? 'info' : 'success', text: r.warning ? `${r.message} ${r.warning}` : r.message })
      qc.invalidateQueries({ queryKey: ['schedule-config'] })
      qc.invalidateQueries({ queryKey: ['classes'] })
    },
    onError: (e) => setNotice({ tone: 'error', text: errorMessage(e) }),
  })
  return (
    <Card title="Lớp phụ trách">
      <form
        className="mb-3 flex gap-2"
        onSubmit={(e) => {
          e.preventDefault()
          onSearch(q)
        }}
      >
        <input
          aria-label="Tìm lớp"
          className="block h-10 w-full max-w-sm rounded-lg border border-outline-variant px-3 text-sm"
          placeholder="Tên hoặc mã lớp"
          value={q}
          onChange={(e) => setQ(e.target.value)}
        />
        <Button type="submit" variant="secondary">
          Tìm
        </Button>
      </form>
      {notice && <Alert tone={notice.tone}>{notice.text}</Alert>}
      <div className="overflow-x-auto">
        <table className="w-full min-w-[640px] text-left text-sm">
          <thead className="text-xs text-on-surface-variant">
            <tr>
              <th className={th}>Lớp</th>
              <th className={th}>Lịch</th>
              <th className={th}>Phòng</th>
              <th className={th}>Trạng thái</th>
              <th className={th}></th>
            </tr>
          </thead>
          <tbody className="divide-y divide-surface-container-highest">
            {p.list_classes.map((c) => (
              <tr key={c.id}>
                <td className={td}>
                  <button type="button" className="font-medium text-primary hover:underline" onClick={() => navigate(`/classes/${c.id}`)}>
                    {c.name}
                  </button>
                  <p className="font-mono text-xs text-on-surface-variant">{c.code}</p>
                </td>
                <td className={td}>{c.schedule_text || (c.has_config ? '—' : 'Chưa có TKB')}</td>
                <td className={td}>{c.room ?? '—'}</td>
                <td className={td}>{c.status_label}</td>
                <td className={`${td} space-x-2 text-right whitespace-nowrap`}>
                  {p.can_schedule && (
                    <button type="button" className="text-primary hover:underline" onClick={() => onPick(c.id)}>
                      Xếp lịch
                    </button>
                  )}
                  {p.can_toggle && (c.status === 'active' || c.status === 'completed') && (
                    <button
                      type="button"
                      className="text-primary hover:underline"
                      disabled={toggle.isPending}
                      onClick={() =>
                        window.confirm(c.status === 'active' ? `Kết thúc lớp ${c.name}? Phòng học sẽ được giải phóng.` : `Mở lại lớp ${c.name}?`) &&
                        toggle.mutate(c.id)
                      }
                    >
                      {c.status === 'active' ? 'Kết thúc' : 'Mở lại'}
                    </button>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </Card>
  )
}
