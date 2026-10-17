// Các danh sách phụ của CRM: khách chốt thành công, thất bại, chờ xếp lớp, đã xóa (Laravel Won / LostDeals /
// WaitingList / Customers/Deleted).
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { errorMessage, type Me } from '../../api'
import { Alert, Badge, Button, Card, Field } from '../../components/ui'
import { can } from '../../nav'
import { crmApi, downloads, type ListParams } from './api'
import { FilterBar, type Filters } from './CustomersPage'
import { CrmHeader, customerPath, Empty, Link, Pager, Table, Td, useFlash } from './components'
import { formatDateTime, formatMoney } from './logic'

function ExportLinks({ xlsx, csv }: { xlsx: string; csv: string }) {
  const cls = 'inline-flex h-10 items-center rounded-lg border border-outline-variant px-4 text-sm font-medium hover:bg-surface-container-low'
  return (
    <>
      <a className={cls} href={xlsx}>
        Xuất Excel
      </a>
      <a className={cls} href={csv}>
        CSV
      </a>
    </>
  )
}

function Stat({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-lg bg-surface-container-low px-4 py-3">
      <div className="text-xs text-on-surface-variant">{label}</div>
      <div className="text-lg font-semibold">{value}</div>
    </div>
  )
}

export function WonPage({ me }: { me: Me }) {
  const [filters, setFilters] = useState<Filters>({})
  const [page, setPage] = useState(1)
  const params: ListParams = { ...filters, page }
  const list = useQuery({ queryKey: ['crm', 'won', params], queryFn: () => crmApi.won(params), placeholderData: keepPreviousData })
  const options = useQuery({ queryKey: ['crm', 'options'], queryFn: crmApi.options })
  const d = list.data
  return (
    <Card>
      <CrmHeader
        me={me}
        title="Khách chốt thành công"
        active="won"
        actions={<ExportLinks xlsx={downloads.won(filters, 'xlsx')} csv={downloads.won(filters, 'csv')} />}
      />
      {options.data && <FilterBar options={options.data} value={filters} onChange={(f) => (setFilters(f), setPage(1))} dateLabel="Ngày chốt" />}
      {d && (
        <div className="mb-4 grid gap-3 sm:grid-cols-4">
          <Stat label="Số khách chốt" value={String(d.total_count)} />
          <Stat label="Tổng giá trị hợp đồng" value={formatMoney(d.total_contract_amount)} />
          <Stat label="Đã thu (duyệt)" value={formatMoney(d.total_collected_amount)} />
          <Stat label="Còn nợ" value={formatMoney(d.total_debt_amount)} />
        </div>
      )}
      {list.isError && <Alert>{errorMessage(list.error)}</Alert>}
      {d && d.data.length === 0 && <Empty>Chưa có khách chốt trong khoảng này.</Empty>}
      {d && d.data.length > 0 && (
        <Table head={['Khách hàng', 'Cơ sở', 'Khóa đăng ký', 'Lớp', 'Giá trị HĐ', 'Học phí', 'Phụ trách', 'Ngày chốt', 'Xác nhận']}>
          {d.data.map((r) => (
            <tr key={r.id}>
              <Td>
                <Link href={customerPath(r.id)}>{r.name}</Link>
                <div className="text-xs text-on-surface-variant">
                  {r.short_code} · {r.phone}
                </div>
              </Td>
              <Td>{r.branch ?? '—'}</Td>
              <Td>{r.course_interest ?? '—'}</Td>
              <Td>{r.class_name ?? <span className="text-on-surface-variant">Chưa xếp lớp</span>}</Td>
              <Td className="whitespace-nowrap">{formatMoney(r.deal_value)}</Td>
              <Td>{r.tuition ? <Badge tone={r.tuition.tone}>{r.tuition.label}</Badge> : '—'}</Td>
              <Td>{r.assigned_user ?? '—'}</Td>
              <Td className="whitespace-nowrap">{formatDateTime(r.converted_at)}</Td>
              <Td>
                {r.enrollment_confirmed === null ? '—' : r.enrollment_confirmed ? <Badge tone="success">Đã xác nhận</Badge> : <Badge>Chờ xác nhận</Badge>}
              </Td>
            </tr>
          ))}
        </Table>
      )}
      {d && <Pager meta={d.meta} onPage={setPage} />}
    </Card>
  )
}

export function LostPage({ me }: { me: Me }) {
  const [filters, setFilters] = useState<Filters>({})
  const [page, setPage] = useState(1)
  const params: ListParams = { ...filters, page }
  const list = useQuery({ queryKey: ['crm', 'lost', params], queryFn: () => crmApi.lost(params), placeholderData: keepPreviousData })
  const options = useQuery({ queryKey: ['crm', 'options'], queryFn: crmApi.options })
  const d = list.data
  return (
    <Card>
      <CrmHeader
        me={me}
        title="Khách thất bại"
        active="lost"
        actions={<ExportLinks xlsx={downloads.lost(filters, 'xlsx')} csv={downloads.lost(filters, 'csv')} />}
      />
      {options.data && <FilterBar options={options.data} value={filters} onChange={(f) => (setFilters(f), setPage(1))} dateLabel="Ngày thất bại" />}
      {list.isError && <Alert>{errorMessage(list.error)}</Alert>}
      {d && d.data.length === 0 && <Empty>Không có khách thất bại.</Empty>}
      {d && d.data.length > 0 && (
        <Table head={['Khách hàng', 'Cơ sở', 'Nguồn', 'Lý do thất bại', 'Phụ trách', 'Ngày thất bại']}>
          {d.data.map((r) => (
            <tr key={r.id}>
              <Td>
                <Link href={customerPath(r.id)}>{r.name}</Link>
                <div className="text-xs text-on-surface-variant">
                  {r.short_code} · {r.phone}
                </div>
              </Td>
              <Td>{r.branch ?? '—'}</Td>
              <Td>{r.source ?? '—'}</Td>
              <Td>{r.lost_reason ?? '—'}</Td>
              <Td>{r.assigned_user ?? '—'}</Td>
              <Td className="whitespace-nowrap">{formatDateTime(r.lost_at)}</Td>
            </tr>
          ))}
        </Table>
      )}
      {d && <Pager meta={d.meta} onPage={setPage} />}
    </Card>
  )
}

export function WaitingPage({ me }: { me: Me }) {
  const list = useQuery({ queryKey: ['crm', 'waiting'], queryFn: crmApi.waiting })
  return (
    <Card>
      <CrmHeader me={me} title="Chờ xếp lớp" active="waiting" />
      {list.isError && <Alert>{errorMessage(list.error)}</Alert>}
      {list.data && list.data.data.length === 0 && <Empty>Không có học viên chờ xếp lớp.</Empty>}
      {list.data && list.data.data.length > 0 && (
        <Table head={['Học viên', 'Khóa đã chốt', 'Cơ sở', 'Phụ trách', 'Ngày chốt', 'Đã chờ', 'Học phí']}>
          {list.data.data.map((r) => (
            <tr key={r.id}>
              <Td>
                <Link href={customerPath(r.id)}>{r.name}</Link>
                <div className="text-xs text-on-surface-variant">
                  {r.short_code} · {r.phone}
                </div>
              </Td>
              <Td>{r.course ?? '—'}</Td>
              <Td>{r.branch || '—'}</Td>
              <Td>{r.assigned_user ?? '—'}</Td>
              <Td className="whitespace-nowrap">{formatDateTime(r.converted_at)}</Td>
              <Td>
                {r.wait_days !== null && (
                  <Badge tone={r.wait_days > 14 ? 'error' : 'neutral'}>{r.wait_days} ngày</Badge>
                )}
              </Td>
              <Td>{r.fee_paid_at_closing ? <Badge tone="success">Đã đóng</Badge> : <Badge tone="error">Chưa đóng</Badge>}</Td>
            </tr>
          ))}
        </Table>
      )}
    </Card>
  )
}

export function DeletedPage({ me }: { me: Me }) {
  const qc = useQueryClient()
  const [q, setQ] = useState('')
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const flash = useFlash()
  const list = useQuery({ queryKey: ['crm', 'deleted', search, page], queryFn: () => crmApi.deleted(search, page), placeholderData: keepPreviousData })
  const restore = useMutation({
    mutationFn: crmApi.restore,
    onSuccess: (r) => {
      flash.ok(r.message)
      qc.invalidateQueries({ queryKey: ['crm'] })
    },
    onError: (e) => flash.fail(errorMessage(e)),
  })
  if (!can(me, 'lead.delete')) return <Card title="Không có quyền">Bạn không có quyền xem khách đã xóa.</Card>
  return (
    <Card>
      <CrmHeader me={me} title="Khách đã xóa" active="deleted" />
      <p className="mb-3 text-sm text-on-surface-variant">
        Hệ thống không còn xóa khách (bỏ khách = đánh Thất bại kèm lý do). Khách đã xóa trước đây vẫn xem và khôi phục được.
      </p>
      <form
        className="mb-4 flex items-end gap-2"
        onSubmit={(e) => {
          e.preventDefault()
          setSearch(q)
          setPage(1)
        }}
      >
        <Field label="Tìm tên, SĐT, mã" value={q} onChange={(e) => setQ(e.target.value)} />
        <Button type="submit" variant="secondary">
          Tìm
        </Button>
      </form>
      {flash.node}
      {list.isError && <Alert>{errorMessage(list.error)}</Alert>}
      {list.data && list.data.data.length === 0 && <Empty>Không có khách đã xóa.</Empty>}
      {list.data && list.data.data.length > 0 && (
        <Table head={['Khách hàng', 'Giai đoạn', 'Cơ sở', 'Phụ trách', 'Ngày xóa', '']}>
          {list.data.data.map((r) => (
            <tr key={r.id}>
              <Td>
                <span className="font-medium">{r.name}</span>
                <div className="text-xs text-on-surface-variant">
                  {r.short_code} · {r.phone}
                </div>
              </Td>
              <Td>{r.stage_label}</Td>
              <Td>{r.branch ?? '—'}</Td>
              <Td>{r.assigned_user ?? '—'}</Td>
              <Td className="whitespace-nowrap">{formatDateTime(r.deleted_at)}</Td>
              <Td>
                <Button variant="secondary" onClick={() => restore.mutate(r.id)} disabled={restore.isPending}>
                  Khôi phục
                </Button>
              </Td>
            </tr>
          ))}
        </Table>
      )}
      {list.data && <Pager meta={list.data.meta} onPage={setPage} />}
    </Card>
  )
}
