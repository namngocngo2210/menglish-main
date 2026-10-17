// Danh mục Hàng hóa & Vật phẩm (Merchandise/Index.vue): thẻ số liệu, lọc nhóm / trạng thái, bảng hàng hóa,
// thêm / sửa trong hộp thoại, bấm trạng thái để bật / tắt kinh doanh, xóa qua hộp xác nhận.
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState, type ChangeEvent, type FormEvent } from 'react'
import { errorMessage, type Me } from '../../api'
import { Alert, Button, Card, Checkbox, Dialog, Field, Select } from '../../components/ui'
import { can } from '../../nav'
import { navigate } from '../../router'
import { inventoryApi, type Category, type Item, type ItemInput, type Option } from './api'
import { Pager, Tag, Textarea, WideDialog } from './components'
import { formatCount, formatVnd, toInt } from './logic'

const STATUS_OPTIONS = [
  { value: 'active', label: 'Đang kinh doanh' },
  { value: 'inactive', label: 'Tạm ngừng' },
]

export function ItemsPage({ me }: { me: Me }) {
  const qc = useQueryClient()
  const [q, setQ] = useState('')
  const [search, setSearch] = useState('')
  const [category, setCategory] = useState('')
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)
  const [editing, setEditing] = useState<Item | 'new' | null>(null)
  const [deleting, setDeleting] = useState<Item | null>(null)
  const [notice, setNotice] = useState('')

  const list = useQuery({
    queryKey: ['inventory', 'items', search, category, status, page],
    queryFn: () => inventoryApi.items({ q: search, category, status, page }),
    placeholderData: keepPreviousData,
  })
  const done = (msg: string) => {
    setNotice(msg)
    qc.invalidateQueries({ queryKey: ['inventory'] })
  }
  const toggle = useMutation({ mutationFn: (it: Item) => inventoryApi.toggleItem(it.id), onSuccess: (r) => done(r.message) })
  const filter = (f: () => void) => {
    f()
    setPage(1)
  }
  const data = list.data

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-xl font-semibold">Danh mục Hàng hóa &amp; Vật phẩm</h1>
        <span className="flex gap-2">
          {can(me, 'merchandise_stock.view') && (
            <Button variant="secondary" onClick={() => navigate('/inventory/stock')}>
              Tồn kho theo chi nhánh
            </Button>
          )}
          <Button onClick={() => setEditing('new')}>Thêm Hàng hóa mới</Button>
        </span>
      </div>

      {notice && <Alert tone="success">{notice}</Alert>}
      {list.isError && <Alert>{errorMessage(list.error)}</Alert>}
      {toggle.isError && <Alert>{errorMessage(toggle.error)}</Alert>}

      {data && (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
          <Stat label="Tổng mặt hàng" value={formatCount(data.metrics.total)} hint={`${data.metrics.active} đang bán`} />
          <Stat label="Sách & Giáo trình" value={formatCount(data.metrics.books)} hint="Giáo trình + Bài tập" />
          <Stat label="Đồng phục & Balo" value={formatCount(data.metrics.uniforms)} hint="Áo polo, balo, túi" />
          <Stat label="Tổng tồn kho" value={formatCount(data.metrics.total_stock)} hint="Cộng mọi chi nhánh" />
        </div>
      )}

      <Card>
        <form
          className="grid gap-3 sm:grid-cols-[1fr_200px_200px_auto] sm:items-end"
          onSubmit={(e) => {
            e.preventDefault()
            filter(() => setSearch(q))
          }}
        >
          <Field label="Tìm kiếm" placeholder="Mã hàng, tên sách, đồng phục..." value={q} onChange={(e) => setQ(e.target.value)} />
          <Select label="Nhóm hàng" value={category} onChange={(e) => filter(() => setCategory(e.target.value))}>
            <option value="">Tất cả nhóm hàng</option>
            {data?.categories.map((c) => (
              <option key={c.value} value={c.value}>
                {c.label}
              </option>
            ))}
          </Select>
          <Select label="Trạng thái" value={status} onChange={(e) => filter(() => setStatus(e.target.value))}>
            <option value="">Tất cả trạng thái</option>
            {STATUS_OPTIONS.map((s) => (
              <option key={s.value} value={s.value}>
                {s.label}
              </option>
            ))}
          </Select>
          <Button type="submit" variant="secondary">
            Lọc
          </Button>
        </form>

        {data && data.items.length === 0 && (
          <p className="mt-4 text-sm text-on-surface-variant">Không tìm thấy hàng hóa / vật phẩm nào phù hợp điều kiện lọc.</p>
        )}
        {data && data.items.length > 0 && (
          <div className="mt-4 overflow-x-auto">
            <table className="w-full min-w-[820px] text-left text-sm">
              <thead className="text-xs text-on-surface-variant">
                <tr>
                  <th className="py-2 pr-4 font-medium">Mã hàng</th>
                  <th className="py-2 pr-4 font-medium">Tên hàng hóa &amp; Vật phẩm</th>
                  <th className="py-2 pr-4 font-medium">Nhóm phân loại</th>
                  <th className="py-2 pr-4 text-center font-medium">ĐVT</th>
                  <th className="py-2 pr-4 text-right font-medium">Đơn giá niêm yết</th>
                  <th className="py-2 pr-4 text-center font-medium">Tồn (mọi chi nhánh)</th>
                  <th className="py-2 pr-4 text-center font-medium">Trạng thái</th>
                  <th />
                </tr>
              </thead>
              <tbody className="divide-y divide-surface-container-highest">
                {data.items.map((it) => (
                  <tr key={it.id}>
                    <td className="py-2 pr-4 font-mono font-semibold">{it.code}</td>
                    <td className="py-2 pr-4">
                      <div className="font-semibold">{it.name}</div>
                      {it.description && <div className="max-w-sm truncate text-xs text-on-surface-subtle">{it.description}</div>}
                    </td>
                    <td className="py-2 pr-4">
                      <Tag>{it.category_label}</Tag>
                    </td>
                    <td className="py-2 pr-4 text-center text-on-surface-variant">{it.unit}</td>
                    <td className="py-2 pr-4 text-right font-mono font-semibold text-primary">{formatVnd(it.price)}</td>
                    <td className="py-2 pr-4 text-center">
                      <span className={`font-mono font-semibold ${(it.stock_quantity ?? 0) <= 10 ? 'rounded bg-error-container px-2 py-0.5 text-error' : ''}`}>
                        {formatCount(it.stock_quantity ?? 0)}
                      </span>
                    </td>
                    <td className="py-2 pr-4 text-center">
                      <button
                        type="button"
                        title="Bấm để bật/tắt kinh doanh"
                        disabled={toggle.isPending}
                        onClick={() => toggle.mutate(it)}
                        className={`rounded-full px-2.5 py-0.5 text-xs font-semibold ${it.is_active ? 'bg-success-container text-on-success-container' : 'bg-surface-container-high text-on-surface-variant'}`}
                      >
                        {it.is_active ? 'Đang bán' : 'Tạm ngừng'}
                      </button>
                    </td>
                    <td className="space-x-2 py-2 text-right whitespace-nowrap">
                      <Button variant="secondary" onClick={() => setEditing(it)} aria-label={`Sửa ${it.name}`}>
                        Sửa
                      </Button>
                      <Button variant="danger" onClick={() => setDeleting(it)} aria-label={`Xóa ${it.name}`}>
                        Xóa
                      </Button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        {data && <Pager page={data.page} total={data.total} size={data.page_size} onPage={setPage} />}
      </Card>

      {editing && data && (
        <ItemDialog
          item={editing === 'new' ? null : editing}
          categories={data.categories}
          branches={data.branches}
          onClose={() => setEditing(null)}
          onSaved={(msg) => {
            setEditing(null)
            done(msg)
          }}
        />
      )}
      {deleting && (
        <DeleteDialog
          item={deleting}
          onClose={() => setDeleting(null)}
          onDeleted={(msg) => {
            setDeleting(null)
            done(msg)
          }}
        />
      )}
    </div>
  )
}

function Stat({ label, value, hint }: { label: string; value: string; hint: string }) {
  return (
    <div className="rounded-xl border border-surface-container-highest bg-surface p-4">
      <p className="text-xs text-on-surface-variant">{label}</p>
      <p className="mt-1 text-2xl font-semibold">{value}</p>
      <p className="text-xs text-on-surface-subtle">{hint}</p>
    </div>
  )
}

type Form = Omit<ItemInput, 'price' | 'cost_price' | 'stock_quantity'> & { price: string; cost_price: string; stock_quantity: string }

function ItemDialog({
  item,
  categories,
  branches,
  onClose,
  onSaved,
}: {
  item: Item | null
  categories: Category[]
  branches: Option[]
  onClose: () => void
  onSaved: (msg: string) => void
}) {
  const [form, setForm] = useState<Form>({
    code: item?.code ?? '',
    name: item?.name ?? '',
    category: item?.category ?? 'book',
    unit: item?.unit ?? 'Bộ',
    price: String(item?.price ?? 0),
    cost_price: item?.cost_price == null ? '' : String(item.cost_price),
    is_active: item?.is_active ?? true,
    description: item?.description ?? '',
    stock_quantity: '0',
    stock_branch_id: '',
  })
  const save = useMutation({
    mutationFn: () => {
      const body: ItemInput = {
        code: form.code,
        name: form.name,
        category: form.category,
        unit: form.unit,
        price: toInt(form.price),
        cost_price: toInt(form.cost_price),
        is_active: form.is_active,
        description: form.description,
      }
      if (item) return inventoryApi.updateItem(item.id, body)
      return inventoryApi.createItem({ ...body, stock_quantity: toInt(form.stock_quantity), stock_branch_id: form.stock_branch_id || null })
    },
    onSuccess: (r) => onSaved(r.message),
  })
  const set = (k: keyof Form) => (e: ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>) =>
    setForm({ ...form, [k]: e.target.value })
  const submit = (e: FormEvent) => {
    e.preventDefault()
    save.mutate()
  }

  return (
    <WideDialog
      title={item ? `Cập nhật Hàng hóa: ${item.name}` : 'Thêm mới Hàng hóa & Vật phẩm'}
      subtitle="Hàng hóa đang kinh doanh được chọn khi lập Hóa đơn / Phiếu thu."
      onClose={onClose}
    >
      <form className="space-y-4" onSubmit={submit}>
        {save.isError && <Alert>{errorMessage(save.error)}</Alert>}
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Mã hàng hóa (SKU)" required maxLength={50} value={form.code} onChange={set('code')} placeholder="Ví dụ: BOOK-CAM-S3, UNI-POLO-M..." hint="Mã định danh duy nhất của hàng hóa trong hệ thống." />
          <Select label="Nhóm phân loại" required value={form.category} onChange={set('category')}>
            {categories.map((c) => (
              <option key={c.value} value={c.value}>
                {c.label}
              </option>
            ))}
          </Select>
        </div>
        <Field label="Tên hàng hóa / Vật phẩm" required maxLength={255} value={form.name} onChange={set('name')} placeholder="Ví dụ: Bộ Giáo trình Cambridge Stage 3..." />
        <div className="grid gap-4 sm:grid-cols-3">
          <Field label="Đơn vị tính" required maxLength={50} value={form.unit} onChange={set('unit')} placeholder="Bộ, Cuốn, Chiếc, Cái..." />
          <Field label="Đơn giá niêm yết (VNĐ)" required type="number" min={0} step={1000} value={form.price} onChange={set('price')} hint="Giá tính vào hợp đồng & hoá đơn." />
          <Field label="Giá vốn nhập (VNĐ)" type="number" min={0} step={1000} value={form.cost_price} onChange={set('cost_price')} placeholder="Tùy chọn" />
        </div>
        {item ? (
          <Alert tone="info">Số tồn được quản lý theo từng chi nhánh ở trang Tồn kho theo chi nhánh (nhập kho, kiểm kê).</Alert>
        ) : (
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Số lượng tồn ban đầu" type="number" min={0} max={100000} value={form.stock_quantity} onChange={set('stock_quantity')} />
            <Select label="Nhập vào kho chi nhánh" value={form.stock_branch_id ?? ''} onChange={set('stock_branch_id')}>
              <option value="">-- Chưa phân chi nhánh --</option>
              {branches.map((b) => (
                <option key={b.value} value={b.value}>
                  {b.label}
                </option>
              ))}
            </Select>
          </div>
        )}
        <Checkbox
          label="Kích hoạt kinh doanh (cho phép chọn khi tạo Hóa đơn / Phiếu thu)"
          checked={form.is_active}
          onChange={(e) => setForm({ ...form, is_active: e.target.checked })}
        />
        <Textarea label="Mô tả & Ghi chú về hàng hóa" rows={3} maxLength={1000} value={form.description} onChange={set('description')} />
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            Hủy bỏ
          </Button>
          <Button type="submit" disabled={save.isPending}>
            {item ? 'Lưu cập nhật' : 'Tạo mới Hàng hóa'}
          </Button>
        </div>
      </form>
    </WideDialog>
  )
}

function DeleteDialog({ item, onClose, onDeleted }: { item: Item; onClose: () => void; onDeleted: (msg: string) => void }) {
  const del = useMutation({ mutationFn: () => inventoryApi.deleteItem(item.id), onSuccess: (r) => onDeleted(r.message) })
  return (
    <Dialog title="Xóa mặt hàng?" onClose={onClose}>
      <div className="space-y-4 text-sm">
        {del.isError && <Alert>{errorMessage(del.error)}</Alert>}
        <p>
          Xóa mặt hàng <strong>{item.name}</strong>?
        </p>
        <p className="text-on-surface-variant">Mặt hàng được chuyển vào thùng rác, không còn chọn được khi lập hóa đơn.</p>
        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={onClose}>
            Hủy
          </Button>
          <Button variant="danger" disabled={del.isPending} onClick={() => del.mutate()}>
            Xóa
          </Button>
        </div>
      </div>
    </Dialog>
  )
}
