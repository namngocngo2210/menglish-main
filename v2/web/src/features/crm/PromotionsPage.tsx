// Danh mục ưu đãi học phí (Laravel Promotions/Index + Form): không xóa, chỉ ngừng áp dụng.
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { errorMessage, type Me } from '../../api'
import { Alert, Badge, Button, Card, Checkbox, Dialog, Field, Select } from '../../components/ui'
import { crmApi, type Option, type PromotionInput, type PromotionRow } from './api'
import { Empty, Pager, Table, Td, Textarea, useFlash } from './components'
import { toDateTimeLocal } from './logic'

export function PromotionsPage(_: { me: Me }) {
  const qc = useQueryClient()
  const [tab, setTab] = useState<'catalog' | 'special'>('catalog')
  const [status, setStatus] = useState('')
  const [q, setQ] = useState('')
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [open, setOpen] = useState<PromotionRow | 'new' | null>(null)
  const flash = useFlash()
  const list = useQuery({
    queryKey: ['crm', 'promotions', tab, status, search, page],
    queryFn: () => crmApi.promotions({ tab, status, q: search, page }),
    placeholderData: keepPreviousData,
  })
  const toggle = useMutation({
    mutationFn: crmApi.togglePromotion,
    onSuccess: (r) => {
      flash.ok(r.message)
      qc.invalidateQueries({ queryKey: ['crm', 'promotions'] })
    },
    onError: (e) => flash.fail(errorMessage(e)),
  })
  const d = list.data
  return (
    <Card
      title={
        <span className="flex items-center justify-between gap-4">
          Ưu đãi học phí
          <Button onClick={() => setOpen('new')}>Thêm ưu đãi</Button>
        </span>
      }
    >
      <div className="mb-4 flex flex-wrap gap-2">
        {(['catalog', 'special'] as const).map((t) => (
          <Button key={t} variant={tab === t ? 'primary' : 'secondary'} onClick={() => (setTab(t), setPage(1))}>
            {t === 'catalog' ? 'Danh mục ưu đãi' : 'Ưu đãi riêng (ca đặc biệt)'} {d ? `(${d.counts[t] ?? 0})` : ''}
          </Button>
        ))}
      </div>
      <form
        className="mb-4 grid gap-3 sm:grid-cols-[1fr_12rem_auto] sm:items-end"
        onSubmit={(e) => {
          e.preventDefault()
          setSearch(q)
          setPage(1)
        }}
      >
        <Field label="Tìm tên, mã, lý do" value={q} onChange={(e) => setQ(e.target.value)} />
        <Select label="Trạng thái" value={status} onChange={(e) => (setStatus(e.target.value), setPage(1))}>
          <option value="">Tất cả</option>
          <option value="active">Đang áp dụng</option>
          <option value="inactive">Ngừng áp dụng</option>
          <option value="default">Mặc định</option>
        </Select>
        <Button type="submit" variant="secondary">
          Tìm
        </Button>
      </form>
      {flash.node}
      {list.isError && <Alert>{errorMessage(list.error)}</Alert>}
      {d && d.data.length === 0 && <Empty>Chưa có ưu đãi nào.</Empty>}
      {d && d.data.length > 0 && (
        <Table head={['Mã / tên', 'Giá trị', 'Phạm vi', 'Thời gian', 'Lượt dùng', 'Trạng thái', '']}>
          {d.data.map((p) => (
            <tr key={p.id}>
              <Td>
                <span className="font-medium">{p.name}</span>
                <div className="font-mono text-xs text-on-surface-variant">{p.code}</div>
                {p.reason && <div className="text-xs text-on-surface-variant">Lý do: {p.reason}</div>}
              </Td>
              <Td className="whitespace-nowrap">{p.value_label}</Td>
              <Td>{p.scope_label}</Td>
              <Td>{p.period_label}</Td>
              <Td>{p.usage_label}</Td>
              <Td className="space-x-1">
                {p.is_active ? <Badge tone="success">Đang áp dụng</Badge> : <Badge>Ngừng</Badge>}
                {p.is_default && <Badge tone="info">Mặc định</Badge>}
                {p.is_active && !p.is_available && <Badge tone="error">Hết hiệu lực</Badge>}
              </Td>
              <Td className="space-x-2 whitespace-nowrap">
                <Button variant="secondary" onClick={() => setOpen(p)}>
                  Sửa
                </Button>
                <Button variant={p.is_active ? 'danger' : 'secondary'} onClick={() => toggle.mutate(p.id)} disabled={toggle.isPending}>
                  {p.is_active ? 'Ngừng' : 'Bật lại'}
                </Button>
              </Td>
            </tr>
          ))}
        </Table>
      )}
      {d && <Pager meta={d.meta} onPage={setPage} />}
      {open && (
        <PromotionDialog
          row={open === 'new' ? null : open}
          special={tab === 'special'}
          branches={d?.branches ?? []}
          courses={d?.courses ?? []}
          onClose={() => setOpen(null)}
          onDone={flash.ok}
        />
      )}
    </Card>
  )
}

function PromotionDialog({
  row,
  special,
  branches,
  courses,
  onClose,
  onDone,
}: {
  row: PromotionRow | null
  special: boolean
  branches: Option[]
  courses: Option[]
  onClose: () => void
  onDone: (m: string) => void
}) {
  const qc = useQueryClient()
  const [f, setF] = useState({
    name: row?.name ?? '',
    type: (row?.type ?? 'percent') as 'percent' | 'fixed',
    value: row ? String(row.value) : '',
    max_discount_amount: row?.max_discount_amount ? String(row.max_discount_amount) : '',
    description: row?.description ?? '',
    reason: row?.reason ?? '',
    branch_id: row?.branch_id ?? '',
    course_id: row?.course_id ?? '',
    starts_at: toDateTimeLocal(row?.starts_at),
    ends_at: toDateTimeLocal(row?.ends_at),
    usage_limit: row?.usage_limit ? String(row.usage_limit) : '',
    is_active: row?.is_active ?? true,
    is_default: row?.is_default ?? false,
    is_special: row?.is_special ?? special,
  })
  const m = useMutation({
    mutationFn: () => {
      const body: PromotionInput = {
        name: f.name,
        type: f.type,
        value: Number(f.value),
        max_discount_amount: f.max_discount_amount ? Number(f.max_discount_amount) : null,
        description: f.description,
        reason: f.reason,
        branch_id: f.branch_id || null,
        course_id: f.course_id || null,
        starts_at: f.starts_at,
        ends_at: f.ends_at,
        usage_limit: f.usage_limit ? Number(f.usage_limit) : null,
        is_active: f.is_active,
        is_default: f.is_default,
        is_special: f.is_special,
      }
      return row ? crmApi.updatePromotion(row.id, body) : crmApi.createPromotion(body)
    },
    onSuccess: (r) => {
      qc.invalidateQueries({ queryKey: ['crm', 'promotions'] })
      onDone(r.message)
      onClose()
    },
  })
  const set = (k: keyof typeof f) => (e: { target: { value: string } }) => setF({ ...f, [k]: e.target.value })
  return (
    <Dialog title={row ? `Sửa ưu đãi ${row.code}` : 'Thêm ưu đãi'} onClose={onClose}>
      <form
        className="space-y-3"
        onSubmit={(e) => {
          e.preventDefault()
          m.mutate()
        }}
      >
        <Field label="Tên ưu đãi *" value={f.name} onChange={set('name')} required maxLength={255} />
        <div className="grid gap-3 sm:grid-cols-2">
          <Select label="Loại" value={f.type} onChange={set('type')}>
            <option value="percent">Giảm theo %</option>
            <option value="fixed">Giảm số tiền</option>
          </Select>
          <Field label={f.type === 'percent' ? 'Giá trị (%) *' : 'Số tiền giảm (đ) *'} inputMode="decimal" value={f.value} onChange={set('value')} required />
          {f.type === 'percent' && (
            <Field label="Giảm tối đa (đ)" inputMode="numeric" value={f.max_discount_amount} onChange={set('max_discount_amount')} />
          )}
          <Select label="Cơ sở áp dụng" value={f.branch_id} onChange={set('branch_id')}>
            <option value="">Mọi cơ sở</option>
            {branches.map((b) => (
              <option key={b.value} value={b.value}>
                {b.label}
              </option>
            ))}
          </Select>
          <Select label="Khóa học áp dụng" value={f.course_id} onChange={set('course_id')}>
            <option value="">Mọi khóa học</option>
            {courses.map((c) => (
              <option key={c.value} value={c.value}>
                {c.label}
              </option>
            ))}
          </Select>
          <Field label="Bắt đầu" type="datetime-local" value={f.starts_at} onChange={set('starts_at')} />
          <Field label="Kết thúc" type="datetime-local" value={f.ends_at} onChange={set('ends_at')} />
          {!f.is_special && <Field label="Giới hạn lượt dùng" inputMode="numeric" value={f.usage_limit} onChange={set('usage_limit')} />}
        </div>
        <Textarea label="Mô tả" value={f.description} onChange={set('description')} maxLength={1000} />
        {!row && <Checkbox label="Ưu đãi riêng cho ca đặc biệt (1 lượt, bắt buộc lý do)" checked={f.is_special} onChange={(e) => setF({ ...f, is_special: e.target.checked })} />}
        {f.is_special && <Textarea label="Lý do (bắt buộc)" value={f.reason} onChange={set('reason')} maxLength={1000} />}
        <Checkbox label="Đang áp dụng" checked={f.is_active} onChange={(e) => setF({ ...f, is_active: e.target.checked })} />
        {!f.is_special && <Checkbox label="Ưu đãi mặc định khi chốt khách" checked={f.is_default} onChange={(e) => setF({ ...f, is_default: e.target.checked })} />}
        {m.isError && <Alert>{errorMessage(m.error)}</Alert>}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            Hủy
          </Button>
          <Button type="submit" disabled={m.isPending}>
            Lưu
          </Button>
        </div>
      </form>
    </Dialog>
  )
}
