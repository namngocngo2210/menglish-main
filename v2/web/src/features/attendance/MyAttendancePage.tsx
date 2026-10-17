// Chấm công của tôi (MyAttendance/CheckIn.vue): đồng hồ máy chủ, cơ sở làm việc, giờ phải có mặt hôm nay, nút chấm vào / ra
// mở hộp thoại lấy GPS + chụp ảnh khuôn mặt bằng camera trước.
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useRef, useState } from 'react'
import { errorMessage } from '../../api'
import { Alert, Badge, Button, Card, Dialog, Icon } from '../../components/ui'
import { currentPosition, distanceMeters, openFrontCamera, shrinkImageFile, snapshot, type Position } from '../../lib/geo'
import { attendanceApi, type BranchInfo, type Day } from './api'
import { link } from './parts'
import { actionLabel, blocker, clockText, dateText, expectedText, inRange, lateNote, nextKind, num } from './logic'

export function MyAttendancePage() {
  const page = useQuery({ queryKey: ['attendance', 'me'], queryFn: attendanceApi.home })
  const [open, setOpen] = useState(false)
  const [notice, setNotice] = useState<{ text: string; warning: boolean } | null>(null)
  const d = page.data
  const stop = d ? blocker(d.branch, d.locked) : null

  return (
    <div className="mx-auto max-w-xl space-y-4">
      <h1 className="text-xl font-semibold">Chấm công của tôi</h1>
      {page.isError && <Alert>{errorMessage(page.error)}</Alert>}
      {notice && (
        <div className={`rounded-lg border px-3 py-2 text-sm ${notice.warning ? 'border-warning/30 bg-warning-container text-warning' : 'border-success/20 bg-success-container text-on-success-container'}`} role="status">
          {notice.text}
        </div>
      )}
      {d && (
        <>
          <ServerClock serverTime={d.server_time} />
          {d.branch && <BranchCard branch={d.branch} expected={expectedText(d.expected)} sessions={d.expected.sessions} />}
          <TodayCard today={d.today} />
          {stop ? (
            <Alert>{stop}</Alert>
          ) : (
            <Button className="h-14 w-full text-base" onClick={() => setOpen(true)}>
              <Icon name={nextKind(d.today) === 'in' ? 'login' : 'logout'} className="mr-2" />
              {actionLabel(d.today)}
            </Button>
          )}
          <div className="flex flex-wrap justify-center gap-4 text-sm">
            <a href="/cham-cong/don?type=correction" onClick={link('/cham-cong/don?type=correction')} className="text-primary hover:underline">
              Quên chấm công?
            </a>
            <a href="/cham-cong/don?type=leave" onClick={link('/cham-cong/don?type=leave')} className="text-primary hover:underline">
              Xin nghỉ
            </a>
            <a href="/cham-cong/lich-su" onClick={link('/cham-cong/lich-su')} className="text-primary hover:underline">
              Lịch sử công
            </a>
          </div>
          {open && d.branch && (
            <PunchDialog
              kind={nextKind(d.today)}
              branch={d.branch}
              onClose={() => setOpen(false)}
              onDone={(text, warning) => {
                setOpen(false)
                setNotice({ text, warning })
              }}
            />
          )}
        </>
      )}
    </div>
  )
}

// Đồng hồ theo giờ máy chủ (lệch giờ điện thoại vẫn đúng).
function ServerClock({ serverTime }: { serverTime: string }) {
  const offset = useRef(Date.parse(serverTime) - Date.now())
  const [now, setNow] = useState(() => Date.now() + offset.current)
  useEffect(() => {
    offset.current = Date.parse(serverTime) - Date.now()
    const t = window.setInterval(() => setNow(Date.now() + offset.current), 1000)
    return () => window.clearInterval(t)
  }, [serverTime])
  return (
    <section className="rounded-xl border border-surface-container-highest bg-surface p-6 text-center shadow-sm">
      <div className="text-4xl font-bold tabular-nums text-on-surface">{clockText(now)}</div>
      <div className="mt-1 text-sm text-on-surface-variant">{dateText(now)}</div>
    </section>
  )
}

function BranchCard({ branch, expected, sessions }: { branch: BranchInfo; expected: string; sessions: { shift: string | null; time: string; class: string }[] }) {
  return (
    <Card>
      <div className="flex items-start gap-3">
        <Icon name="location_on" className="text-primary" />
        <div className="min-w-0 text-sm">
          <div className="font-semibold">{branch.name}</div>
          {branch.address && <div className="text-on-surface-variant">{branch.address}</div>}
          <div className="mt-1 text-on-surface-variant">
            Bán kính chấm công {branch.radius} m · cho phép muộn {branch.grace} phút
          </div>
          <div className="mt-1">{expected}</div>
          {sessions.length > 0 && (
            <ul className="mt-1 space-y-0.5 text-xs text-on-surface-variant">
              {sessions.map((s, i) => (
                <li key={i}>
                  {s.shift ? `${s.shift} · ` : ''}
                  {s.time} · {s.class}
                </li>
              ))}
            </ul>
          )}
        </div>
      </div>
    </Card>
  )
}

function TodayCard({ today }: { today: Day | null }) {
  const note = lateNote(today)
  return (
    <Card title="Hôm nay">
      <div className="grid grid-cols-2 gap-3">
        <Slot label="Giờ vào" time={today?.check_in ?? null} photo={today?.check_in_photo ?? null} />
        <Slot label="Giờ ra" time={today?.check_out ?? null} photo={today?.check_out_photo ?? null} />
      </div>
      {today && (
        <div className="mt-3 flex flex-wrap items-center gap-2 text-sm">
          <Badge tone={today.status_tone}>{today.status_label}</Badge>
          {today.check_in_distance !== null && <span className="text-on-surface-variant">Cách cơ sở {num(today.check_in_distance)} m</span>}
        </div>
      )}
      {note && <p className="mt-2 text-sm text-warning">{note}</p>}
    </Card>
  )
}

function Slot({ label, time, photo }: { label: string; time: string | null; photo: string | null }) {
  return (
    <div className="flex items-center gap-3 rounded-lg bg-surface-container-low p-3">
      {photo ? (
        <img src={photo} alt={`Ảnh ${label.toLocaleLowerCase('vi')}`} className="size-12 rounded-lg object-cover" />
      ) : (
        <span className="flex size-12 items-center justify-center rounded-lg bg-surface text-on-surface-subtle">
          <Icon name="photo_camera" />
        </span>
      )}
      <div>
        <div className="text-xs text-on-surface-variant">{label}</div>
        <div className="text-lg font-semibold tabular-nums">{time ?? '--:--'}</div>
      </div>
    </div>
  )
}

type Gps = { state: 'loading' } | { state: 'error'; message: string } | { state: 'ok'; pos: Position; distance: number }

// Hộp thoại chấm công: lấy GPS, mở camera trước, chụp ảnh rồi gửi. Không mở được camera thì chụp qua ô chọn ảnh.
function PunchDialog({ kind, branch, onClose, onDone }: { kind: 'in' | 'out'; branch: BranchInfo; onClose: () => void; onDone: (text: string, warning: boolean) => void }) {
  const qc = useQueryClient()
  const [gps, setGps] = useState<Gps>({ state: 'loading' })
  const [cameraError, setCameraError] = useState('')
  const [photo, setPhoto] = useState<File | null>(null)
  const [preview, setPreview] = useState('')
  const video = useRef<HTMLVideoElement>(null)
  const stream = useRef<MediaStream | null>(null)

  const locate = () => {
    setGps({ state: 'loading' })
    currentPosition()
      .then((pos) => setGps({ state: 'ok', pos, distance: Math.round(distanceMeters(branch.latitude ?? 0, branch.longitude ?? 0, pos.latitude, pos.longitude)) }))
      .catch((e: Error) => setGps({ state: 'error', message: e.message }))
  }

  const stopCamera = () => {
    stream.current?.getTracks().forEach((t) => t.stop())
    stream.current = null
  }

  const startCamera = () => {
    setCameraError('')
    openFrontCamera()
      .then((s) => {
        stream.current = s
        if (video.current) {
          video.current.srcObject = s
          void video.current.play().catch(() => undefined)
        }
      })
      .catch((e: Error) => setCameraError(e.message))
  }

  useEffect(() => {
    locate()
    startCamera()
    return stopCamera
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  useEffect(() => () => (preview ? URL.revokeObjectURL(preview) : undefined), [preview])

  const take = (file: File) => {
    setPhoto(file)
    setPreview(URL.createObjectURL(file))
    stopCamera()
  }
  const capture = () => {
    if (video.current) snapshot(video.current).then(take, (e: Error) => setCameraError(e.message))
  }
  const retake = () => {
    setPhoto(null)
    setPreview('')
    startCamera()
  }

  const punch = useMutation({
    mutationFn: () => {
      if (gps.state !== 'ok' || !photo) throw new Error('Chưa đủ vị trí và ảnh.')
      return attendanceApi.punch({ kind, ...gps.pos, photo })
    },
    onSuccess: (r) => {
      qc.invalidateQueries({ queryKey: ['attendance'] })
      onDone(r.message, r.warning)
    },
  })

  const near = gps.state === 'ok' && inRange(gps.distance, branch)
  const title = kind === 'in' ? 'Chấm công vào' : 'Chấm công ra'

  return (
    <Dialog
      title={title}
      onClose={() => {
        stopCamera()
        onClose()
      }}
    >
      <div className="space-y-4">
        <div className="flex items-start gap-2 rounded-lg bg-surface-container-low p-3 text-sm">
          <Icon name={gps.state === 'ok' ? (near ? 'my_location' : 'wrong_location') : gps.state === 'error' ? 'location_off' : 'location_searching'} className={near ? 'text-success' : gps.state === 'loading' ? 'text-on-surface-variant' : 'text-error'} />
          <div className="min-w-0 flex-1">
            {gps.state === 'loading' && <span>Đang lấy vị trí GPS…</span>}
            {gps.state === 'error' && <span className="text-error">{gps.message}</span>}
            {gps.state === 'ok' &&
              (near ? (
                <span>
                  Bạn đang ở {branch.name} (cách {num(gps.distance)} m, sai số ±{num(gps.pos.accuracy)} m).
                </span>
              ) : (
                <span className="text-error">
                  Bạn đang cách {branch.name} khoảng {num(gps.distance)} m, chỉ chấm công được trong bán kính {branch.radius} m quanh cơ sở.
                </span>
              ))}
          </div>
          {gps.state !== 'loading' && (
            <button type="button" className="shrink-0 text-xs font-medium text-primary hover:underline" onClick={locate}>
              Lấy lại vị trí
            </button>
          )}
        </div>

        <div className="overflow-hidden rounded-xl bg-black">
          {preview ? (
            <img src={preview} alt="Ảnh vừa chụp" className="mx-auto aspect-square w-full object-cover" />
          ) : cameraError ? (
            <div className="space-y-3 bg-surface-container-low p-4 text-sm">
              <p className="text-error">{cameraError}</p>
              <label className="inline-flex h-10 cursor-pointer items-center rounded-lg border border-outline-variant bg-surface px-4 font-medium">
                <Icon name="photo_camera" className="mr-2" />
                Chụp ảnh khuôn mặt
                <input
                  type="file"
                  accept="image/*"
                  capture="user"
                  className="sr-only"
                  onChange={(e) => {
                    const f = e.target.files?.[0]
                    if (f) shrinkImageFile(f).then(take, (err: Error) => setCameraError(err.message))
                  }}
                />
              </label>
            </div>
          ) : (
            <video ref={video} playsInline muted className="mx-auto aspect-square w-full -scale-x-100 object-cover" />
          )}
        </div>
        <p className="text-xs text-on-surface-variant">Ảnh khuôn mặt dùng để đối chiếu người chấm công, chỉ quản lý cơ sở và Admin xem được.</p>

        {punch.isError && <Alert>{errorMessage(punch.error)}</Alert>}

        <div className="flex flex-wrap justify-end gap-2">
          {preview ? (
            <Button variant="secondary" onClick={retake} disabled={punch.isPending}>
              Chụp lại
            </Button>
          ) : (
            !cameraError && (
              <Button variant="secondary" onClick={capture}>
                <Icon name="photo_camera" className="mr-2" />
                Chụp ảnh
              </Button>
            )
          )}
          <Button disabled={!photo || !near || punch.isPending} onClick={() => punch.mutate()}>
            {punch.isPending ? 'Đang gửi…' : title}
          </Button>
        </div>
      </div>
    </Dialog>
  )
}
