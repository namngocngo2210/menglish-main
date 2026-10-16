import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState, type ChangeEvent, type ReactNode } from 'react'
import { api, errorMessage, type StaffProfile, type StaffProfileInput } from '../../api'
import { Alert, Badge, Button, Checkbox, Field } from '../../components/ui'

// Hồ sơ nhân sự trong hộp Tài khoản: tab "Hồ sơ" và "Hợp đồng & Lương" của Laravel (Users/Show + UserFields.vue).
// CCCD, quê quán, nơi ở, liên lạc khẩn cấp, lương cơ bản, thù lao giờ chỉ người xem được mọi phiếu lương thấy / sửa.

const money = (v: number | null) => (v == null ? '—' : `${new Intl.NumberFormat('vi-VN').format(v)} đ`)
const dmy = (v: string | null) => (v ? v.split('-').reverse().join('/') : '—')
const tone = (t: string) => (t === 'success' || t === 'warning' || t === 'error' ? t : 'neutral')
const remainingClass: Record<string, string> = { error: 'text-error', warning: 'text-warning', success: 'text-on-surface-variant' }

export function StaffProfilePanel({ userId, canEdit }: { userId: string; canEdit: boolean }) {
  const profile = useQuery({ queryKey: ['staff-profile', userId], queryFn: () => api.staffProfile(userId) })
  const [editing, setEditing] = useState(false)
  if (profile.isError) return <Alert>{errorMessage(profile.error)}</Alert>
  if (!profile.data) return null
  const p = profile.data
  if (editing) return <StaffProfileForm userId={userId} profile={p} onDone={() => setEditing(false)} />

  const hidden = <span className="text-on-surface-variant italic">Chỉ người xem được lương thấy</span>
  const sensitive = (v: string | null) => (p.can_view_sensitive ? v || '—' : hidden)
  const rows: [string, ReactNode][] = [
    ['Số CCCD (12 số)', <span className="font-mono">{sensitive(p.id_card_number)}</span>],
    ['Liên lạc khẩn cấp', sensitive(p.emergency_contact)],
    ['Quê quán', sensitive(p.hometown)],
    ['Nơi ở hiện tại', sensitive(p.current_address)],
    ['Tốt nghiệp', p.graduation_school || '—'],
    ['Chứng chỉ', p.certificates || '—'],
    ['Level giảng dạy / Chuyên môn', p.teaching_level || '—'],
  ]
  return (
    <section className="space-y-3 border-t border-surface-container-highest pt-4">
      <div className="flex items-center justify-between gap-2">
        <h3 className="text-sm font-semibold">Hồ sơ nhân sự</h3>
        {canEdit && (
          <Button variant="secondary" onClick={() => setEditing(true)}>
            Sửa hồ sơ
          </Button>
        )}
      </div>
      <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
        {rows.map(([label, value]) => (
          <div key={label} className="contents">
            <dt className="text-on-surface-variant">{label}</dt>
            <dd>{value}</dd>
          </div>
        ))}
        {p.academic_lead && (
          <>
            <dt className="text-on-surface-variant">Kiêm nhiệm giảng dạy</dt>
            <dd>{p.academic_teaching ? 'Có' : 'Không'}</dd>
          </>
        )}
      </dl>
      <h3 className="text-sm font-semibold">Hợp đồng &amp; Lương</h3>
      <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
        <dt className="text-on-surface-variant">Loại hợp đồng</dt>
        <dd className="space-x-2">
          <span>{p.contract_type || '—'}</span>
          <Badge tone={tone(p.contract_status.tone)}>{p.contract_status.label}</Badge>
        </dd>
        <dt className="text-on-surface-variant">Thời hạn</dt>
        <dd>
          {dmy(p.contract_start_date)} → {dmy(p.contract_end_date)}
          {p.contract_remaining && (
            <span className={`ml-2 text-xs font-medium ${remainingClass[p.contract_remaining.tone] ?? ''}`}>{p.contract_remaining.label}</span>
          )}
        </dd>
        <dt className="text-on-surface-variant">Lương cơ bản</dt>
        <dd>{p.can_view_sensitive ? money(p.base_salary) : hidden}</dd>
        <dt className="text-on-surface-variant">Thù lao theo giờ</dt>
        <dd>{p.can_view_sensitive ? money(p.hourly_rate) : hidden}</dd>
      </dl>
    </section>
  )
}

type Form = Omit<StaffProfileInput, 'base_salary' | 'hourly_rate'> & { base_salary: string; hourly_rate: string }

function StaffProfileForm({ userId, profile: p, onDone }: { userId: string; profile: StaffProfile; onDone: () => void }) {
  const qc = useQueryClient()
  const [form, setForm] = useState<Form>({
    id_card_number: p.id_card_number ?? '',
    hometown: p.hometown ?? '',
    current_address: p.current_address ?? '',
    emergency_contact: p.emergency_contact ?? '',
    graduation_school: p.graduation_school ?? '',
    certificates: p.certificates ?? '',
    teaching_level: p.teaching_level ?? '',
    contract_type: p.contract_type ?? '',
    contract_start_date: p.contract_start_date ?? '',
    contract_end_date: p.contract_end_date ?? '',
    base_salary: p.base_salary?.toString() ?? '',
    hourly_rate: p.hourly_rate?.toString() ?? '',
    academic_teaching: p.academic_teaching,
  })
  const num = (v: string) => (v.trim() === '' ? null : Number(v.replace(/[.\s]/g, '')))
  const save = useMutation({
    mutationFn: () => api.updateStaffProfile(userId, { ...form, base_salary: num(form.base_salary), hourly_rate: num(form.hourly_rate) }),
    onSuccess: (res) => {
      qc.setQueryData(['staff-profile', userId], res)
      onDone()
    },
  })
  const set = (k: keyof Form) => (e: ChangeEvent<HTMLInputElement>) => setForm({ ...form, [k]: e.target.value })
  // Loại hợp đồng cũ không nằm trong danh sách gợi ý vẫn giữ được
  const types = form.contract_type && !p.contract_types.includes(form.contract_type) ? [...p.contract_types, form.contract_type] : p.contract_types

  return (
    <form
      className="space-y-4 border-t border-surface-container-highest pt-4"
      onSubmit={(e) => {
        e.preventDefault()
        save.mutate()
      }}
    >
      <h3 className="text-sm font-semibold">Sửa hồ sơ nhân sự</h3>
      {save.isError && <Alert>{errorMessage(save.error)}</Alert>}
      {!p.can_view_sensitive && (
        <Alert tone="info">CCCD, quê quán, nơi ở, liên lạc khẩn cấp và lương chỉ người xem được mọi phiếu lương sửa.</Alert>
      )}
      {p.can_view_sensitive && (
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Số CCCD (12 số)" maxLength={30} inputMode="numeric" value={form.id_card_number} onChange={set('id_card_number')} />
          <Field label="Liên lạc khẩn cấp" maxLength={255} value={form.emergency_contact} onChange={set('emergency_contact')} hint="Tên, quan hệ, số điện thoại" />
          <Field label="Quê quán" maxLength={255} value={form.hometown} onChange={set('hometown')} />
          <Field label="Nơi ở hiện tại" maxLength={255} value={form.current_address} onChange={set('current_address')} />
        </div>
      )}
      <div className="grid gap-4 sm:grid-cols-2">
        <Field label="Tốt nghiệp" maxLength={255} value={form.graduation_school} onChange={set('graduation_school')} />
        <Field label="Chứng chỉ" maxLength={255} value={form.certificates} onChange={set('certificates')} />
        <Field label="Level giảng dạy / Chuyên môn" maxLength={255} value={form.teaching_level} onChange={set('teaching_level')} />
        <div className="space-y-1.5">
          <label htmlFor="staff-contract-type" className="block text-sm font-medium text-on-surface">
            Loại hợp đồng
          </label>
          <select
            id="staff-contract-type"
            value={form.contract_type}
            onChange={(e) => setForm({ ...form, contract_type: e.target.value })}
            className="h-10 w-full rounded-lg border border-outline-variant bg-surface px-3 text-sm"
          >
            <option value="">Chưa chọn</option>
            {types.map((t) => (
              <option key={t} value={t}>
                {t}
              </option>
            ))}
          </select>
        </div>
        <Field label="Ngày bắt đầu hợp đồng" type="date" value={form.contract_start_date} onChange={set('contract_start_date')} />
        <Field label="Ngày kết thúc hợp đồng" type="date" value={form.contract_end_date} onChange={set('contract_end_date')} />
        {p.can_view_sensitive && (
          <>
            <Field label="Lương cơ bản (đ / tháng)" inputMode="numeric" value={form.base_salary} onChange={set('base_salary')} hint="Để trống = 0" />
            <Field label="Thù lao theo giờ (đ)" inputMode="numeric" value={form.hourly_rate} onChange={set('hourly_rate')} hint="Để trống = 0" />
          </>
        )}
      </div>
      {p.academic_lead && (
        <Checkbox
          label="Kiêm nhiệm giảng dạy"
          checked={form.academic_teaching}
          onChange={(e) => setForm({ ...form, academic_teaching: e.target.checked })}
        />
      )}
      <div className="flex gap-2">
        <Button type="submit" disabled={save.isPending}>
          Lưu hồ sơ
        </Button>
        <Button type="button" variant="secondary" onClick={onDone}>
          Hủy
        </Button>
      </div>
    </form>
  )
}
