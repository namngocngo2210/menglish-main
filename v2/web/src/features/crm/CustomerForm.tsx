// Form thêm / sửa khách (Laravel Crm/Customers/Create + Edit).
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState, type ChangeEvent } from 'react'
import { errorMessage, type Me } from '../../api'
import { Alert, Button, Card, Field, Select } from '../../components/ui'
import { can } from '../../nav'
import { navigate } from '../../router'
import { crmApi, type LeadDetail, type LeadInput, type Option, type OwnerOption } from './api'
import { customerPath, PATHS, Textarea } from './components'
import { ownersForBranch, toDateTimeLocal, validPhone } from './logic'

export type LeadForm = Required<{ [K in keyof LeadInput]: string }>

export const emptyLead: LeadForm = {
  name: '',
  phone: '',
  parent_name: '',
  parent_phone: '',
  email: '',
  dob: '',
  gender: '',
  address: '',
  school: '',
  branch_id: '',
  course_interest: '',
  source: '',
  assigned_user_id: '',
  deal_value: '',
  next_follow_up_at: '',
  notes: '',
}

export function leadFormFrom(d: LeadDetail): LeadForm {
  return {
    name: d.name,
    phone: d.phone,
    parent_name: d.parent_name ?? '',
    parent_phone: d.parent_phone ?? '',
    email: d.email ?? '',
    dob: d.dob ?? '',
    gender: d.gender ?? '',
    address: d.address ?? '',
    school: d.school ?? '',
    branch_id: d.branch_id ?? '',
    course_interest: d.course_interest ?? '',
    source: d.source ?? '',
    assigned_user_id: d.assigned_user_id ?? '',
    deal_value: d.deal_value ? String(d.deal_value) : '',
    next_follow_up_at: toDateTimeLocal(d.next_follow_up_at),
    notes: d.notes ?? '',
  }
}

export function toInput(f: LeadForm, withOwner: boolean): LeadInput {
  return {
    ...f,
    branch_id: f.branch_id || null,
    assigned_user_id: withOwner && f.assigned_user_id ? f.assigned_user_id : null,
    deal_value: f.deal_value ? Number(f.deal_value.replace(/\D/g, '')) : null,
  }
}

export function LeadFields({
  form,
  setForm,
  branches,
  owners,
  sources,
  withOwner,
  locked = [],
}: {
  form: LeadForm
  setForm: (f: LeadForm) => void
  branches: Option[]
  owners: OwnerOption[]
  sources: string[]
  withOwner: boolean
  locked?: string[]
}) {
  const set = (k: keyof LeadForm) => (e: ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>) =>
    setForm({ ...form, [k]: e.target.value })
  const phoneHint = form.phone && !validPhone(form.phone) ? 'SĐT Việt Nam 10 số bắt đầu bằng 0 (hoặc +84).' : undefined
  const branchOwners = ownersForBranch(owners, form.branch_id)
  return (
    <div className="grid gap-3 sm:grid-cols-2">
      <Field label="Họ và tên *" value={form.name} onChange={set('name')} required maxLength={255} />
      <Field label="Số điện thoại *" value={form.phone} onChange={set('phone')} required maxLength={20} hint={phoneHint} inputMode="tel" />
      <Field label="Tên phụ huynh" value={form.parent_name} onChange={set('parent_name')} maxLength={255} />
      <Field label="SĐT phụ huynh" value={form.parent_phone} onChange={set('parent_phone')} maxLength={20} inputMode="tel" />
      <Field label="Email" type="email" value={form.email} onChange={set('email')} maxLength={255} />
      <Field label="Ngày sinh" type="date" value={form.dob} onChange={set('dob')} />
      <Select label="Giới tính" value={form.gender} onChange={set('gender')}>
        <option value="">—</option>
        <option value="Nam">Nam</option>
        <option value="Nữ">Nữ</option>
      </Select>
      <Field label="Trường học" value={form.school} onChange={set('school')} maxLength={255} />
      <div className="sm:col-span-2">
        <Field label="Địa chỉ" value={form.address} onChange={set('address')} maxLength={255} />
      </div>
      <Select label="Cơ sở *" value={form.branch_id} onChange={set('branch_id')} disabled={locked.includes('branch_id')} required>
        <option value="">— Chọn cơ sở —</option>
        {branches.map((b) => (
          <option key={b.value} value={b.value}>
            {b.label}
          </option>
        ))}
      </Select>
      <Field label="Khóa học quan tâm" value={form.course_interest} onChange={set('course_interest')} disabled={locked.includes('course_interest')} maxLength={255} />
      <div className="space-y-1.5">
        <Field label="Nguồn khách *" list="crm-sources" value={form.source} onChange={set('source')} required maxLength={255} />
        <datalist id="crm-sources">
          {sources.map((s) => (
            <option key={s} value={s} />
          ))}
        </datalist>
      </div>
      {withOwner && (
        <Select label="Người phụ trách" value={form.assigned_user_id} onChange={set('assigned_user_id')}>
          <option value="">— Chính tôi —</option>
          {branchOwners.map((o) => (
            <option key={o.value} value={o.value}>
              {o.label}
            </option>
          ))}
        </Select>
      )}
      <Field
        label="Giá trị hợp đồng dự kiến (đ)"
        inputMode="numeric"
        value={form.deal_value}
        onChange={set('deal_value')}
        disabled={locked.includes('deal_value')}
      />
      <Field label="Hạn liên hệ tiếp theo" type="datetime-local" value={form.next_follow_up_at} onChange={set('next_follow_up_at')} />
      <div className="sm:col-span-2">
        <Textarea label="Ghi chú" value={form.notes} onChange={set('notes')} maxLength={1000} />
      </div>
    </div>
  )
}

export function CreateCustomerPage({ me }: { me: Me }) {
  const qc = useQueryClient()
  const options = useQuery({ queryKey: ['crm', 'options'], queryFn: crmApi.options })
  const [form, setForm] = useState<LeadForm | null>(null)
  const current = form ?? { ...emptyLead, branch_id: options.data?.default_branch_id ?? '' }
  const withOwner = can(me, 'lead.assign')
  const create = useMutation({
    mutationFn: () => crmApi.create(toInput(current, withOwner)),
    onSuccess: (r) => {
      qc.invalidateQueries({ queryKey: ['crm'] })
      navigate(customerPath(r.id))
    },
  })
  if (!can(me, 'lead.create')) return <Card title="Không có quyền">Bạn không có quyền thêm khách hàng.</Card>
  return (
    <Card title="Thêm khách hàng mới">
      {options.isError && <Alert>{errorMessage(options.error)}</Alert>}
      {options.data && (
        <form
          className="space-y-4"
          onSubmit={(e) => {
            e.preventDefault()
            create.mutate()
          }}
        >
          <LeadFields
            form={current}
            setForm={setForm}
            branches={options.data.form_branches}
            owners={options.data.form_owners}
            sources={options.data.sources}
            withOwner={withOwner}
          />
          {create.isError && <Alert>{errorMessage(create.error)}</Alert>}
          <div className="flex justify-end gap-2">
            <Button type="button" variant="secondary" onClick={() => navigate(PATHS.list)}>
              Hủy
            </Button>
            <Button type="submit" disabled={create.isPending}>
              Lưu khách hàng
            </Button>
          </div>
        </form>
      )}
    </Card>
  )
}
