// Cấu hình dải số hóa đơn (Tuition/Config.vue): bảng dải theo chi nhánh + dải mặc định, thêm / sửa / ngừng dùng, lịch sử
// số đã cấp gần đây. Số đã cấp không bao giờ cấp lại.
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { errorMessage, type Me } from '../../api'
import { Alert, Button, Card, Dialog, Field, Select } from '../../components/ui'
import { can, navItemFor } from '../../nav'
import { navigate } from '../../router'
import { tuitionApi, type InvoiceRange, type Option } from './api'
import { Tag } from './components'

const branchLabel = (r: InvoiceRange) => r.branch_name ?? 'Dải mặc định (dùng chung)'
const num = (v: string) => (v.trim() === '' ? null : Number(v.replace(/\D/g, '')))

export function ConfigPage({ me }: { me: Me }) {
  const qc = useQueryClient()
  const [notice, setNotice] = useState<{ tone: 'success' | 'error'; text: string } | null>(null)
  const [editing, setEditing] = useState<InvoiceRange | 'new' | null>(null)
  const [history, setHistory] = useState<InvoiceRange | null>(null)
  const page = useQuery({ queryKey: ['tuition', 'config'], queryFn: tuitionApi.config })
  const d = page.data
  const done = (text: string) => {
    setNotice({ tone: 'success', text })
    setEditing(null)
    qc.invalidateQueries({ queryKey: ['tuition', 'config'] })
  }
  const toggle = useMutation({
    mutationFn: (id: string) => tuitionApi.toggleRange(id),
    onSuccess: (x) => done(x.message),
    onError: (e) => setNotice({ tone: 'error', text: errorMessage(e) }),
  })
  const showBank = !!navItemFor('/system-config/bank-accounts') && can(me, 'bank_account.manage')

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold">Cấu hình dải số hóa đơn</h1>
          <p className="text-sm text-on-surface-variant">
            Quản lý dải số hóa đơn điện tử và hóa đơn giấy (thu tiền mặt) cho từng chi nhánh. Số đã cấp không bao giờ được cấp lại.
          </p>
        </div>
        <div className="flex flex-wrap gap-2">
          {showBank && (
            <Button variant="secondary" onClick={() => navigate('/system-config/bank-accounts')}>
              Tài khoản ngân hàng
            </Button>
          )}
          {d?.can_manage && <Button onClick={() => setEditing('new')}>Thêm cấu hình mới</Button>}
        </div>
      </div>
      {page.isError && <Alert>{errorMessage(page.error)}</Alert>}
      {notice && <Alert tone={notice.tone}>{notice.text}</Alert>}

      {d && (
        <Card>
          {d.ranges.length === 0 ? (
            <p className="text-sm text-on-surface-variant">
              Chưa có dải số hóa đơn. Hệ thống sẽ tự tạo dải mặc định C26MEN khi duyệt phiếu đầu tiên. Nên cấu hình dải riêng cho từng chi nhánh.
            </p>
          ) : (
            <ul className="divide-y divide-surface-container-highest">
              {d.ranges.map((r) => (
                <li key={r.id} className={`flex flex-wrap items-start justify-between gap-3 py-3 text-sm ${r.is_active ? '' : 'opacity-60'}`}>
                  <div className="space-y-0.5">
                    <div className="font-medium">
                      {branchLabel(r)} <Tag tone={r.kind === 'paper' ? 'warning' : 'info'}>{r.kind_label}</Tag>{' '}
                      <Tag tone={r.is_active ? 'success' : 'neutral'}>{r.is_active ? 'Đang hiệu lực' : 'Đã ngừng dùng'}</Tag>
                    </div>
                    {!r.branch_id && <div className="text-xs text-on-surface-variant">Dùng khi chi nhánh chưa có dải riêng / dải riêng đã hết</div>}
                    <div>
                      <span className="font-mono">{r.series_code}</span> · Mẫu {r.template_code} · Dải{' '}
                      <span className="font-mono">{r.range_label}</span>
                    </div>
                    <div className="text-xs text-on-surface-variant">
                      Số hiện tại <span className="font-mono">{r.current_label}</span>
                      {r.max_issued_label && ` · Đã cấp tới ${r.max_issued_label}`} ·{' '}
                      {r.remaining === null ? (
                        'Không giới hạn'
                      ) : r.remaining === 0 ? (
                        <span className="font-medium text-error">Hết số</span>
                      ) : r.low ? (
                        <span className="font-medium text-error">Còn {r.remaining} — Sắp hết số</span>
                      ) : (
                        `Còn ${r.remaining_label}`
                      )}
                    </div>
                  </div>
                  <div className="flex flex-wrap gap-2">
                    <Button variant="secondary" className="h-8 px-3 text-xs" onClick={() => setHistory(r)}>
                      Lịch sử cấp số
                    </Button>
                    {r.can_manage && (
                      <>
                        <Button variant="secondary" className="h-8 px-3 text-xs" onClick={() => setEditing(r)}>
                          Sửa
                        </Button>
                        <Button
                          variant={r.is_active ? 'danger' : 'secondary'}
                          className="h-8 px-3 text-xs"
                          disabled={toggle.isPending}
                          onClick={() => toggle.mutate(r.id)}
                        >
                          {r.is_active ? 'Ngừng dùng' : 'Dùng lại'}
                        </Button>
                      </>
                    )}
                  </div>
                </li>
              ))}
            </ul>
          )}
        </Card>
      )}

      <Alert tone="info">
        <strong>Chính sách cấp số hóa đơn.</strong> Hóa đơn điện tử: khi duyệt phiếu thu, hệ thống lấy số từ dải đang hiệu lực của chi nhánh ghi nhận
        học phí; chi nhánh chưa có dải riêng hoặc dải đã hết thì lấy từ dải mặc định. Hóa đơn giấy (tiền mặt): mỗi chi nhánh một dải theo cuốn hóa đơn
        giấy, cấp số kế tiếp khi lập phiếu tiền mặt; ghi sai thì tạo yêu cầu Hủy hóa đơn số đó. Dải số không được chồng lấn dải khác cùng ký hiệu; "Số
        hiện tại" là số kế tiếp sẽ cấp và không được lùi về số đã cấp. Hóa đơn bị hủy vẫn giữ số. Mọi thay đổi dải số được thông báo tới Kế toán và
        Quản lý cơ sở của chi nhánh liên quan.
      </Alert>

      {history && (
        <Dialog title={`Số đã cấp gần đây — ${history.series_code}`} onClose={() => setHistory(null)}>
          {history.recent.length === 0 ? (
            <p className="text-sm text-on-surface-variant">Dải này chưa cấp số nào.</p>
          ) : (
            <ul className="divide-y divide-surface-container text-sm">
              {history.recent.map((x) => (
                <li key={x.invoice_number} className="flex justify-between py-1.5">
                  <span className="font-mono">{x.invoice_number}</span>
                  <span className="text-on-surface-variant">{x.label}</span>
                </li>
              ))}
            </ul>
          )}
        </Dialog>
      )}
      {editing === 'new' && d && (
        <CreateDialog branches={d.branches} canManageDefault={d.can_manage_default} onClose={() => setEditing(null)} onDone={done} />
      )}
      {editing && editing !== 'new' && <EditDialog r={editing} onClose={() => setEditing(null)} onDone={done} />}
    </div>
  )
}

function EditDialog({ r, onClose, onDone }: { r: InvoiceRange; onClose: () => void; onDone: (m: string) => void }) {
  const [template, setTemplate] = useState(r.template_code)
  const [series, setSeries] = useState(r.series_code)
  const [start, setStart] = useState(String(r.start_number))
  const [end, setEnd] = useState(r.end_number === null ? '' : String(r.end_number))
  const [current, setCurrent] = useState(String(r.current_number))
  const [error, setError] = useState('')
  const save = useMutation({
    mutationFn: () =>
      tuitionApi.updateRange({
        config_id: r.id,
        template_code: template,
        series_code: series,
        start_number: num(start),
        end_number: num(end),
        current_number: num(current),
      }),
    onSuccess: (x) => onDone(x.message),
    onError: (e) => setError(errorMessage(e)),
  })
  return (
    <Dialog title={`Sửa dải số ${r.series_code} · ${branchLabel(r)}`} onClose={onClose}>
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault()
          save.mutate()
        }}
      >
        {error && <Alert>{error}</Alert>}
        <div className="grid grid-cols-2 gap-3">
          <Field label="Mẫu số" value={template} onChange={(e) => setTemplate(e.target.value)} />
          <Field label="Ký hiệu" value={series} onChange={(e) => setSeries(e.target.value)} />
          <Field label="Số bắt đầu" inputMode="numeric" value={start} onChange={(e) => setStart(e.target.value)} />
          <Field label="Số kết thúc" inputMode="numeric" hint="Để trống = không giới hạn" value={end} onChange={(e) => setEnd(e.target.value)} />
        </div>
        <Field
          label="Số hiện tại (số kế tiếp sẽ cấp)"
          inputMode="numeric"
          hint={r.max_issued ? `Đã cấp tới ${r.max_issued} — chỉ được đặt từ ${r.max_issued + 1} trở lên.` : 'Chưa cấp số nào trong dải này.'}
          value={current}
          onChange={(e) => setCurrent(e.target.value)}
        />
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            Hủy
          </Button>
          <Button type="submit" disabled={save.isPending}>
            Lưu dải số
          </Button>
        </div>
      </form>
    </Dialog>
  )
}

function CreateDialog({
  branches,
  canManageDefault,
  onClose,
  onDone,
}: {
  branches: Option[]
  canManageDefault: boolean
  onClose: () => void
  onDone: (m: string) => void
}) {
  const [kind, setKind] = useState<'electronic' | 'paper'>('electronic')
  const [branch, setBranch] = useState('')
  const [template, setTemplate] = useState('1/001')
  const [series, setSeries] = useState('C26MEN')
  const [start, setStart] = useState('')
  const [end, setEnd] = useState('')
  const [error, setError] = useState('')
  const allowDefault = canManageDefault && kind === 'electronic'
  const save = useMutation({
    mutationFn: () =>
      tuitionApi.createRange({
        kind,
        branch_id: branch || null,
        template_code: template,
        series_code: series,
        start_number: num(start),
        end_number: num(end),
      }),
    onSuccess: (x) => onDone(x.message),
    onError: (e) => setError(errorMessage(e)),
  })
  return (
    <Dialog title="Thêm cấu hình mới" onClose={onClose}>
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault()
          save.mutate()
        }}
      >
        {error && <Alert>{error}</Alert>}
        <Select label="Loại hóa đơn" value={kind} onChange={(e) => setKind(e.target.value as 'electronic' | 'paper')}>
          <option value="electronic">Hóa đơn điện tử — cấp số khi duyệt phiếu</option>
          <option value="paper">Hóa đơn giấy (tiền mặt) — cấp số khi lập phiếu</option>
        </Select>
        <Select label="Chọn chi nhánh" value={branch} onChange={(e) => setBranch(e.target.value)}>
          <option value="">{allowDefault ? 'Dải mặc định (dùng chung)' : 'Chọn chi nhánh'}</option>
          {branches.map((b) => (
            <option key={b.value} value={b.value}>
              {b.label}
            </option>
          ))}
        </Select>
        {kind === 'paper' && <p className="text-xs text-on-surface-variant">Hóa đơn giấy luôn theo từng chi nhánh (mỗi chi nhánh một cuốn).</p>}
        <div className="grid grid-cols-2 gap-3">
          <Field label="Mẫu số" value={template} onChange={(e) => setTemplate(e.target.value)} />
          <Field label="Ký hiệu" value={series} onChange={(e) => setSeries(e.target.value)} />
          <Field label="Số bắt đầu" inputMode="numeric" placeholder="Ví dụ: 1" value={start} onChange={(e) => setStart(e.target.value)} />
          <Field label="Số kết thúc" inputMode="numeric" placeholder="Ví dụ: 1000" value={end} onChange={(e) => setEnd(e.target.value)} />
        </div>
        <Alert tone="info">
          Dải số này phải duy nhất trên hệ thống và không được chồng lấn với các dải số đã tồn tại cùng ký hiệu. Hóa đơn giấy nên dùng ký hiệu riêng
          (VD: C26HDG) và số đầu – cuối đúng cuốn hóa đơn đang dùng.
        </Alert>
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            Hủy
          </Button>
          <Button type="submit" disabled={save.isPending}>
            Thêm dải số
          </Button>
        </div>
      </form>
    </Dialog>
  )
}
