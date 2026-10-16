// Danh sách khách hàng (Laravel Crm/Customers/Index): bộ lọc, chip lọc nhanh, phân công lại hàng loạt, xuất Excel.
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { errorMessage, type Me } from '../../api'
import { Alert, Badge, Button, Card, Dialog, Field, Select } from '../../components/ui'
import { can } from '../../nav'
import { usePath } from '../../router'
import { crmApi, downloads, type ListParams, type Options } from './api'
import { CustomerDetailPage } from './CustomerDetailPage'
import { CreateCustomerPage } from './CustomerForm'
import { CrmHeader, customerPath, Empty, Link, Pager, PATHS, SlaBadge, Table, Td, Textarea, useFlash, type Chip } from './components'
import { formatDateTime, ownersForBranch } from './logic'

export function CustomersRoute({ me }: { me: Me }) {
  const path = usePath()
  if (path === PATHS.create) return <CreateCustomerPage me={me} />
  if (path.startsWith(PATHS.list + '/')) return <CustomerDetailPage me={me} id={decodeURIComponent(path.slice(PATHS.list.length + 1))} />
  return <CustomersPage me={me} />
}

export type Filters = Omit<ListParams, 'sla' | 'follow_up' | 'test_today' | 'page' | 'per_page'>

function initialChip(): Chip | null {
  const c = new URLSearchParams(window.location.search).get('chip')
  return c === 'sla' || c === 'test_today' || c === 'follow_up' ? c : null
}

function CustomersPage({ me }: { me: Me }) {
  const [filters, setFilters] = useState<Filters>({})
  const [chip, setChip] = useState<Chip | null>(initialChip)
  const [page, setPage] = useState(1)
  const [selected, setSelected] = useState<Set<string>>(new Set())
  const [bulk, setBulk] = useState(false)
  const flash = useFlash()
  const params: ListParams = { ...filters, sla: chip === 'sla', follow_up: chip === 'follow_up', test_today: chip === 'test_today', page }
  const list = useQuery({ queryKey: ['crm', 'leads', params], queryFn: () => crmApi.leads(params), placeholderData: keepPreviousData })
  const options = useQuery({ queryKey: ['crm', 'options'], queryFn: crmApi.options })
  const canAssign = can(me, 'lead.assign')
  const rows = list.data?.data ?? []
  const toggle = (id: string) =>
    setSelected((s) => {
      const n = new Set(s)
      if (n.has(id)) n.delete(id)
      else n.add(id)
      return n
    })

  return (
    <Card>
      <CrmHeader
        me={me}
        title="Danh sách khách hàng"
        active={chip}
        onChip={(c) => {
          setChip(c)
          setPage(1)
        }}
        actions={
          <>
            {can(me, 'lead.approve_transfer') && (
              <Link href={PATHS.transfers} className="inline-flex h-10 items-center rounded-lg border border-outline-variant px-4 text-sm font-medium hover:bg-surface-container-low">
                Duyệt chuyển cơ sở
              </Link>
            )}
            <a className="inline-flex h-10 items-center rounded-lg border border-outline-variant px-4 text-sm font-medium hover:bg-surface-container-low" href={downloads.leads(params, 'xlsx')}>
              Xuất Excel
            </a>
          </>
        }
      />
      {options.data && <FilterBar options={options.data} value={filters} onChange={(f) => (setFilters(f), setPage(1))} withStage />}
      {flash.node}
      {list.isError && <Alert>{errorMessage(list.error)}</Alert>}
      {canAssign && selected.size > 0 && (
        <div className="mb-3 flex items-center gap-3 rounded-lg bg-surface-container-low px-3 py-2 text-sm">
          Đã chọn {selected.size} khách
          <Button variant="secondary" onClick={() => setBulk(true)}>
            Phân công lại
          </Button>
          <button type="button" className="text-on-surface-variant hover:underline" onClick={() => setSelected(new Set())}>
            Bỏ chọn
          </button>
        </div>
      )}
      {list.data && rows.length === 0 && <Empty>Không có khách hàng nào phù hợp.</Empty>}
      {rows.length > 0 && (
        <Table
          head={[
            canAssign ? (
              <input
                type="checkbox"
                aria-label="Chọn tất cả"
                checked={rows.every((r) => selected.has(r.id))}
                onChange={(e) => setSelected(e.target.checked ? new Set(rows.map((r) => r.id)) : new Set())}
              />
            ) : (
              ''
            ),
            'Khách hàng',
            'Giai đoạn',
            'Hạn liên hệ',
            'Phụ trách',
            'Nguồn',
            'Cơ sở',
            'Cập nhật',
          ]}
        >
          {rows.map((r) => (
            <tr key={r.id} className="hover:bg-surface-container-low">
              <Td>{canAssign && <input type="checkbox" aria-label={`Chọn ${r.name}`} checked={selected.has(r.id)} onChange={() => toggle(r.id)} />}</Td>
              <Td>
                <Link href={customerPath(r.id)}>{r.name}</Link>
                <div className="text-xs text-on-surface-variant">
                  {r.short_code} · {r.phone}
                  {r.parent_name && ` · PH: ${r.parent_name}`}
                </div>
              </Td>
              <Td>
                <Badge tone={r.stage === 'lost' ? 'error' : r.stage === 'won' ? 'success' : 'neutral'}>{r.stage_label}</Badge>
                {r.test_today_at && <div className="mt-1 text-xs text-info">Test {formatDateTime(r.test_today_at).slice(0, 5)} hôm nay</div>}
              </Td>
              <Td>
                <SlaBadge sla={r.sla} compact />
              </Td>
              <Td>{r.assigned_user ?? '—'}</Td>
              <Td>{r.source ?? '—'}</Td>
              <Td>{r.branch ?? '—'}</Td>
              <Td className="whitespace-nowrap text-on-surface-variant">{formatDateTime(r.updated_at)}</Td>
            </tr>
          ))}
        </Table>
      )}
      {list.data && <Pager meta={list.data.meta} onPage={setPage} />}
      {bulk && options.data && (
        <BulkReassignDialog
          options={options.data}
          ids={[...selected]}
          onClose={() => setBulk(false)}
          onDone={(m) => {
            flash.ok(m)
            setSelected(new Set())
          }}
        />
      )}
    </Card>
  )
}

// Bộ lọc dùng chung cho danh sách, Kanban, khách chốt / thất bại.
export function FilterBar({
  options,
  value,
  onChange,
  withStage,
  dateLabel = 'Ngày tạo',
}: {
  options: Options
  value: Filters
  onChange: (f: Filters) => void
  withStage?: boolean
  dateLabel?: string
}) {
  const [draft, setDraft] = useState<Filters>(value)
  const set = (k: keyof Filters) => (e: { target: { value: string } }) => setDraft({ ...draft, [k]: e.target.value || undefined })
  return (
    <form
      className="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4"
      onSubmit={(e) => {
        e.preventDefault()
        onChange(draft)
      }}
    >
      <Field label="Tìm tên, SĐT, mã, email" value={draft.search ?? ''} onChange={set('search')} />
      {options.filter_branches.length > 1 && (
        <Select label="Cơ sở" value={draft.branch_id ?? ''} onChange={set('branch_id')}>
          <option value="">Tất cả cơ sở</option>
          {options.filter_branches.map((b) => (
            <option key={b.value} value={b.value}>
              {b.label}
            </option>
          ))}
        </Select>
      )}
      {options.filter_owners.length > 0 && (
        <Select label="Người phụ trách" value={draft.assigned_user_id ?? ''} onChange={set('assigned_user_id')}>
          <option value="">Tất cả</option>
          {options.filter_owners.map((o) => (
            <option key={o.value} value={o.value}>
              {o.label}
            </option>
          ))}
        </Select>
      )}
      <Select label="Nguồn" value={draft.source ?? ''} onChange={set('source')}>
        <option value="">Tất cả nguồn</option>
        {options.filter_sources.map((s) => (
          <option key={s} value={s}>
            {s}
          </option>
        ))}
      </Select>
      {withStage && (
        <Select label="Giai đoạn" value={draft.stage ?? ''} onChange={set('stage')}>
          <option value="">Tất cả giai đoạn</option>
          {options.stages.map((s) => (
            <option key={s.value} value={s.value}>
              {s.label}
            </option>
          ))}
        </Select>
      )}
      <Field label={`${dateLabel} từ`} type="date" value={draft.from ?? ''} onChange={set('from')} />
      <Field label="đến" type="date" value={draft.to ?? ''} onChange={set('to')} />
      <div className="flex items-end gap-2">
        <Button type="submit" variant="secondary">
          Lọc
        </Button>
        <Button
          type="button"
          variant="secondary"
          onClick={() => {
            setDraft({})
            onChange({})
          }}
        >
          Xóa lọc
        </Button>
      </div>
    </form>
  )
}

function BulkReassignDialog({
  options,
  ids,
  onClose,
  onDone,
}: {
  options: Options
  ids: string[]
  onClose: () => void
  onDone: (msg: string) => void
}) {
  const qc = useQueryClient()
  const [owner, setOwner] = useState('')
  const [reason, setReason] = useState('')
  const m = useMutation({
    mutationFn: () => crmApi.bulkReassign({ ids, assigned_user_id: owner, reason }),
    onSuccess: (r) => {
      qc.invalidateQueries({ queryKey: ['crm'] })
      onDone(r.message)
      onClose()
    },
  })
  return (
    <Dialog title={`Phân công lại ${ids.length} khách`} onClose={onClose}>
      <form
        className="space-y-3"
        onSubmit={(e) => {
          e.preventDefault()
          m.mutate()
        }}
      >
        <Select label="Người phụ trách mới" value={owner} onChange={(e) => setOwner(e.target.value)}>
          <option value="">— Chọn —</option>
          {ownersForBranch(options.all_owners, null).map((o) => (
            <option key={o.value} value={o.value}>
              {o.label}
            </option>
          ))}
        </Select>
        <Textarea label="Lý do (bắt buộc)" value={reason} onChange={(e) => setReason(e.target.value)} maxLength={1000} />
        <p className="text-xs text-on-surface-variant">Khách khác cơ sở với người nhận sẽ gửi yêu cầu chuyển cơ sở chờ Admin duyệt.</p>
        {m.isError && <Alert>{errorMessage(m.error)}</Alert>}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            Hủy
          </Button>
          <Button type="submit" disabled={!owner || !reason.trim() || m.isPending}>
            Phân công
          </Button>
        </div>
      </form>
    </Dialog>
  )
}
