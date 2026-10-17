import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState, type ChangeEvent, type ClipboardEvent } from 'react'
import { api, errorMessage, type Branch, type BranchInput, type Me } from '../../api'
import { Alert, Badge, Button, Card, Dialog, Field } from '../../components/ui'
import { currentPosition, parseCoordinates } from '../../lib/geo'
import { can } from '../../nav'
import { BranchLocationMap } from './BranchLocationMap'

export function BranchesPage({ me }: { me: Me }) {
  const qc = useQueryClient()
  const list = useQuery({ queryKey: ['branches'], queryFn: api.branches })
  const [editing, setEditing] = useState<Branch | 'new' | null>(null)
  const [checkin, setCheckin] = useState<Branch | null>(null)
  const toggle = useMutation({
    mutationFn: (b: Branch) => api.setBranchActive(b.id, !b.is_active),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['branches'] }),
  })

  return (
    <Card
      title={
        <span className="flex items-center justify-between gap-4">
          Chi nhánh
          {can(me, 'branch.create') && <Button onClick={() => setEditing('new')}>Thêm chi nhánh</Button>}
        </span>
      }
    >
      {list.isError && <Alert>{errorMessage(list.error)}</Alert>}
      {toggle.isError && <Alert>{errorMessage(toggle.error)}</Alert>}
      {list.data && list.data.branches.length === 0 && (
        <p className="text-sm text-on-surface-variant">Chưa có chi nhánh nào.</p>
      )}
      {list.data && list.data.branches.length > 0 && (
        <div className="overflow-x-auto">
          <table className="w-full text-left text-sm">
            <thead className="text-xs text-on-surface-variant">
              <tr>
                <th className="py-2 pr-4 font-medium">Mã</th>
                <th className="py-2 pr-4 font-medium">Tên</th>
                <th className="py-2 pr-4 font-medium">Địa chỉ</th>
                <th className="py-2 pr-4 font-medium">Điện thoại</th>
                <th className="py-2 pr-4 font-medium">Chấm công</th>
                <th className="py-2 pr-4 font-medium">Trạng thái</th>
                <th />
              </tr>
            </thead>
            <tbody className="divide-y divide-surface-container-highest">
              {list.data.branches.map((b) => (
                <tr key={b.id}>
                  <td className="py-2 pr-4 font-mono">{b.code}</td>
                  <td className="py-2 pr-4">{b.name}</td>
                  <td className="py-2 pr-4 text-on-surface-variant">{b.address}</td>
                  <td className="py-2 pr-4">{b.phone}</td>
                  <td className="py-2 pr-4 text-xs">
                    <CheckinSummary branch={b} onOpen={can(me, 'branch.update') ? () => setCheckin(b) : undefined} />
                  </td>
                  <td className="py-2 pr-4">
                    {b.is_active ? <Badge tone="success">Hoạt động</Badge> : <Badge>Ngừng</Badge>}
                  </td>
                  <td className="space-x-2 py-2 text-right whitespace-nowrap">
                    {can(me, 'branch.update') && (
                      <Button variant="secondary" onClick={() => setEditing(b)}>
                        Sửa
                      </Button>
                    )}
                    {can(me, 'branch.manage') && (
                      <Button variant={b.is_active ? 'danger' : 'secondary'} onClick={() => toggle.mutate(b)}>
                        {b.is_active ? 'Ngừng' : 'Mở lại'}
                      </Button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {checkin && <CheckinDialog branch={checkin} onClose={() => setCheckin(null)} />}
      {editing && <BranchDialog branch={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
    </Card>
  )
}

function BranchDialog({ branch, onClose }: { branch: Branch | null; onClose: () => void }) {
  const qc = useQueryClient()
  const [form, setForm] = useState<BranchInput>({
    code: branch?.code ?? '',
    name: branch?.name ?? '',
    address: branch?.address ?? '',
    phone: branch?.phone ?? '',
  })
  const save = useMutation({
    mutationFn: () => (branch ? api.updateBranch(branch.id, form) : api.createBranch(form)),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['branches'] })
      onClose()
    },
  })
  const set = (k: keyof BranchInput) => (e: ChangeEvent<HTMLInputElement>) => setForm({ ...form, [k]: e.target.value })

  return (
    <Dialog title={branch ? 'Sửa chi nhánh' : 'Thêm chi nhánh'} onClose={onClose}>
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault()
          save.mutate()
        }}
      >
        {save.isError && <Alert>{errorMessage(save.error)}</Alert>}
        <Field label="Mã chi nhánh" required maxLength={20} value={form.code} onChange={set('code')} hint="Ví dụ HN01" />
        <Field label="Tên chi nhánh" required maxLength={200} value={form.name} onChange={set('name')} />
        <Field label="Địa chỉ" maxLength={500} value={form.address} onChange={set('address')} />
        <Field label="Điện thoại" inputMode="tel" value={form.phone} onChange={set('phone')} />
        <Button type="submit" disabled={save.isPending}>
          Lưu
        </Button>
      </form>
    </Dialog>
  )
}

// Cột "Chấm công": giờ làm + bán kính, hoặc "Chưa cài tọa độ"; bấm để mở cài đặt (người có quyền sửa chi nhánh).
function CheckinSummary({ branch, onOpen }: { branch: Branch; onOpen?: () => void }) {
  const c = branch.checkin
  const body = c.configured ? (
    <>
      <span className="block font-semibold text-on-surface">
        {c.work_start ?? '--:--'}–{c.work_end ?? '--:--'}
      </span>
      <span className="block text-on-surface-variant">
        Bán kính {c.radius} m · muộn sau {c.grace}′
      </span>
    </>
  ) : (
    <Badge tone="error">Chưa cài tọa độ</Badge>
  )
  if (!onOpen) return body
  return (
    <button type="button" className="text-left hover:underline" aria-label={`Cài đặt chấm công: ${branch.name}`} onClick={onOpen}>
      {body}
    </button>
  )
}

type CheckinForm = { latitude: string; longitude: string; radius: string; start: string; end: string; grace: string }

// Cài đặt chấm công: tọa độ (tìm địa chỉ / bấm trên bản đồ / gõ tay / dán "vĩ độ, kinh độ" từ Google Maps / lấy vị trí
// hiện tại khi đang ở chi nhánh), bán kính, giờ vào / ra, số phút cho phép muộn.
function CheckinDialog({ branch, onClose }: { branch: Branch; onClose: () => void }) {
  const qc = useQueryClient()
  const c = branch.checkin
  const [form, setForm] = useState<CheckinForm>({
    latitude: c.latitude?.toString() ?? '',
    longitude: c.longitude?.toString() ?? '',
    radius: String(c.radius),
    start: c.work_start ?? '08:00',
    end: c.work_end ?? '17:30',
    grace: String(c.grace),
  })
  const [locating, setLocating] = useState(false)
  const [locateError, setLocateError] = useState<string | null>(null)
  const num = (v: string) => (v.trim() === '' || Number.isNaN(Number(v)) ? null : Number(v))
  const save = useMutation({
    mutationFn: () =>
      api.updateBranchCheckin(branch.id, {
        latitude: num(form.latitude),
        longitude: num(form.longitude),
        checkin_radius: num(form.radius),
        work_start_time: form.start,
        work_end_time: form.end,
        late_grace_minutes: num(form.grace),
      }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['branches'] })
      onClose()
    },
  })
  const set = (k: keyof CheckinForm) => (e: ChangeEvent<HTMLInputElement>) => setForm({ ...form, [k]: e.target.value })
  const paste = (e: ClipboardEvent<HTMLInputElement>) => {
    const p = parseCoordinates(e.clipboardData.getData('text'))
    if (!p) return
    e.preventDefault()
    setForm({ ...form, latitude: p[0], longitude: p[1] })
  }
  async function useCurrentLocation() {
    setLocating(true)
    setLocateError(null)
    try {
      const pos = await currentPosition()
      setForm((f) => ({ ...f, latitude: pos.latitude.toFixed(7), longitude: pos.longitude.toFixed(7) }))
    } catch (e) {
      setLocateError(e instanceof Error ? e.message : 'Không lấy được vị trí GPS.')
    } finally {
      setLocating(false)
    }
  }
  const mapUrl =
    form.latitude && form.longitude
      ? `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(`${form.latitude},${form.longitude}`)}`
      : null

  return (
    <Dialog title={`Cài đặt chấm công: ${branch.name}`} onClose={onClose} wide>
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault()
          save.mutate()
        }}
      >
        <p className="text-sm text-on-surface-variant">
          Nhân sự của chi nhánh chỉ chấm công được khi GPS nằm trong bán kính quanh tọa độ này. Tìm địa chỉ trên bản đồ rồi
          kéo ghim đến đúng cổng chi nhánh, dán tọa độ từ Google Maps (nhấn giữ vị trí → sao chép "vĩ độ, kinh độ") hoặc
          đứng tại chi nhánh và bấm "Lấy vị trí hiện tại".
        </p>
        {save.isError && <Alert>{errorMessage(save.error)}</Alert>}
        <BranchLocationMap
          latitude={form.latitude}
          longitude={form.longitude}
          radius={Number(form.radius) || 100}
          address={branch.address ?? ''}
          onPick={(latitude, longitude) => setForm((f) => ({ ...f, latitude, longitude }))}
        />
        <div className="grid grid-cols-2 gap-4">
          <Field label="Vĩ độ (latitude)" required inputMode="decimal" placeholder="21.0285" value={form.latitude} onChange={set('latitude')} onPaste={paste} />
          <Field label="Kinh độ (longitude)" required inputMode="decimal" placeholder="105.8542" value={form.longitude} onChange={set('longitude')} onPaste={paste} />
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Button type="button" variant="secondary" disabled={locating} onClick={useCurrentLocation}>
            {locating ? 'Đang lấy vị trí…' : 'Lấy vị trí hiện tại'}
          </Button>
          {mapUrl && (
            <a href={mapUrl} target="_blank" rel="noopener noreferrer" className="text-sm text-primary hover:underline">
              Mở Google Maps
            </a>
          )}
        </div>
        {locateError && <Alert tone="info">{locateError}</Alert>}
        <Field
          label="Bán kính được chấm công (mét)"
          type="number"
          required
          min={20}
          max={5000}
          value={form.radius}
          onChange={set('radius')}
          hint="Nên 50–150 m: GPS điện thoại trong nhà thường lệch 10–50 m."
        />
        <div className="grid grid-cols-3 gap-4">
          <Field label="Giờ vào" type="time" required value={form.start} onChange={set('start')} />
          <Field label="Giờ ra" type="time" required value={form.end} onChange={set('end')} />
          <Field label="Cho phép muộn (phút)" type="number" required min={0} max={240} value={form.grace} onChange={set('grace')} />
        </div>
        <p className="text-xs text-on-surface-variant">
          Giờ vào / ra áp dụng cho nhân sự văn phòng. Giáo viên, trợ giảng, GVNN tính theo buổi dạy đầu tiên trong ngày. Đến
          muộn quá số phút cho phép thì tự lập biên bản chờ giải trình (0đ, người chốt quyết mức phạt).
        </p>
        <div className="flex gap-2">
          <Button type="submit" disabled={save.isPending}>
            Lưu cài đặt
          </Button>
          <Button type="button" variant="secondary" onClick={onClose}>
            Hủy
          </Button>
        </div>
      </form>
    </Dialog>
  )
}
