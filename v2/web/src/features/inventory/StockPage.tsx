// Tồn kho sách / hàng hóa theo chi nhánh (Merchandise/Stock.vue): chọn chi nhánh trong phạm vi, lọc nhóm / tìm kiếm,
// chip nhanh Âm kho / Hết hàng / Sắp hết; bấm dòng xem nhật ký xuất nhập; Nhập kho / Kiểm kê (như Laravel) và
// Xuất kho / Chuyển kho (mới ở v2) trong hộp thoại. Xuất kho theo phiếu thu do module tài chính ghi.
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { errorMessage, type Me } from '../../api'
import { Alert, Button, Card, Checkbox, Field, Select } from '../../components/ui'
import { can } from '../../nav'
import { navigate } from '../../router'
import { inventoryApi, type MoveType, type StockRow } from './api'
import { MovementTable, Tag, Textarea, WideDialog } from './components'
import { LEVELS, toInt } from './logic'

const CHIPS = [
  { level: '', label: 'Tất cả' },
  { level: 'negative', label: 'Âm kho' },
  { level: 'out', label: 'Hết hàng' },
  { level: 'low', label: 'Sắp hết' },
] as const

const MOVE_TITLES: Record<MoveType, { title: string; hint: string; submit: string }> = {
  import: { title: 'Nhập kho', hint: 'Cộng thêm hàng về kho của chi nhánh.', submit: 'Nhập kho' },
  count: { title: 'Kiểm kê tồn kho', hint: 'Nhập số lượng thực tế đếm được, hệ thống tự ghi phần chênh lệch.', submit: 'Lưu kiểm kê' },
  issue: { title: 'Xuất kho', hint: 'Xuất hàng khỏi kho (cấp cho học viên, dùng cho lớp...). Không xuất quá số tồn.', submit: 'Xuất kho' },
  transfer: { title: 'Chuyển kho', hint: 'Chuyển hàng sang chi nhánh khác. Không chuyển quá số tồn.', submit: 'Chuyển kho' },
}

export function StockPage({ me }: { me: Me }) {
  const qc = useQueryClient()
  const [branchId, setBranchId] = useState('')
  const [level, setLevel] = useState('')
  const [q, setQ] = useState('')
  const [search, setSearch] = useState('')
  const [category, setCategory] = useState('')
  const [history, setHistory] = useState<StockRow | null>(null)
  const [moving, setMoving] = useState<{ type: MoveType; itemId?: string } | null>(null)
  const [notice, setNotice] = useState('')

  const overview = useQuery({
    queryKey: ['inventory', 'stock', branchId, level, search, category],
    queryFn: () => inventoryApi.stock({ branch_id: branchId, level, q: search, category }),
    placeholderData: keepPreviousData,
  })
  const data = overview.data
  const branch = data?.branch ?? null
  const canManage = !!data?.can_manage

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold">Tồn kho theo chi nhánh</h1>
          <p className="text-sm text-on-surface-variant">
            Số sách / hàng hóa còn ở từng chi nhánh. Tự trừ khi phiếu thu kèm sách được duyệt, tự hoàn khi hủy hóa đơn.
          </p>
        </div>
        <span className="flex flex-wrap gap-2">
          {can(me, 'system_category.manage') && (
            <Button variant="secondary" onClick={() => navigate('/inventory/items')}>
              Danh mục hàng hóa
            </Button>
          )}
          {canManage && branch && (
            <>
              <Button variant="secondary" onClick={() => setMoving({ type: 'count' })}>
                Kiểm kê
              </Button>
              <Button variant="secondary" onClick={() => setMoving({ type: 'issue' })}>
                Xuất kho
              </Button>
              <Button variant="secondary" onClick={() => setMoving({ type: 'transfer' })}>
                Chuyển kho
              </Button>
              <Button onClick={() => setMoving({ type: 'import' })}>Nhập kho</Button>
            </>
          )}
        </span>
      </div>

      {notice && <Alert tone="success">{notice}</Alert>}
      {overview.isError && <Alert>{errorMessage(overview.error)}</Alert>}

      {data && !branch && (
        <Card title="Tài khoản chưa được gán chi nhánh">
          <p className="text-sm text-on-surface-variant">Liên hệ Admin gán chi nhánh để xem tồn kho.</p>
        </Card>
      )}

      {data && branch && (
        <>
          {data.legacy_total > 0 && (
            <Alert tone="info">
              Trước đây tồn kho là một số chung cho cả hệ thống. Còn <strong className="font-mono">{data.legacy_total}</strong> sản phẩm chưa biết nằm ở
              chi nhánh nào: khi <strong>Nhập kho</strong>, tick “Lấy từ tồn cũ chưa phân chi nhánh” để chuyển về đúng chi nhánh.
            </Alert>
          )}

          <Card>
            <div className="mb-4 flex flex-wrap gap-1.5 text-xs">
              {CHIPS.map((c) => (
                <button
                  key={c.level}
                  type="button"
                  onClick={() => setLevel(c.level)}
                  aria-pressed={level === c.level}
                  className={`rounded-lg px-3 py-1 font-medium ${level === c.level ? 'bg-primary text-white' : 'bg-surface-container-low text-on-surface-variant hover:bg-surface-container-high'}`}
                >
                  {c.label}
                  {c.level && ` (${data.counts[c.level]})`}
                </button>
              ))}
            </div>
            <form
              className="grid gap-3 sm:grid-cols-[1fr_200px_200px_auto] sm:items-end"
              onSubmit={(e) => {
                e.preventDefault()
                setSearch(q)
              }}
            >
              <Field label="Tìm kiếm" placeholder="Mã hoặc tên sách, đồng phục..." value={q} onChange={(e) => setQ(e.target.value)} />
              {data.branches.length > 1 ? (
                <Select label="Chi nhánh" value={branch.id} onChange={(e) => setBranchId(e.target.value)}>
                  {data.branches.map((b) => (
                    <option key={b.id} value={b.id}>
                      {b.name}
                    </option>
                  ))}
                </Select>
              ) : (
                <div className="space-y-1.5">
                  <span className="block text-sm font-medium">Chi nhánh</span>
                  <div className="flex h-10 items-center rounded-lg border border-outline-variant bg-surface-container-low px-3 text-sm" title="Chi nhánh của bạn">
                    {branch.name}
                  </div>
                </div>
              )}
              <Select label="Nhóm hàng" value={category} onChange={(e) => setCategory(e.target.value)}>
                <option value="">Tất cả nhóm hàng</option>
                {data.categories.map((c) => (
                  <option key={c.value} value={c.value}>
                    {c.label}
                  </option>
                ))}
              </Select>
              <Button type="submit" variant="secondary">
                Lọc
              </Button>
            </form>

            {data.rows.length === 0 ? (
              <p className="mt-4 text-sm text-on-surface-variant">Không có mặt hàng nào khớp bộ lọc.</p>
            ) : (
              <div className="mt-4 overflow-x-auto">
                <table className="w-full min-w-[680px] text-left text-sm">
                  <thead className="text-xs text-on-surface-variant">
                    <tr>
                      <th className="py-2 pr-4 font-medium">Mặt hàng</th>
                      <th className="py-2 pr-4 font-medium">Nhóm</th>
                      <th className="py-2 pr-4 text-right font-medium">Tồn tại {branch.name}</th>
                      <th className="py-2 pr-4 font-medium">Tình trạng</th>
                      {canManage && <th />}
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-surface-container-highest">
                    {data.rows.map((row) => (
                      <tr
                        key={row.id}
                        tabIndex={0}
                        title="Xem nhật ký xuất nhập"
                        className="cursor-pointer hover:bg-surface-container-low"
                        onClick={() => setHistory(row)}
                        onKeyDown={(e) => e.key === 'Enter' && e.target === e.currentTarget && setHistory(row)}
                      >
                        <td className="py-2 pr-4">
                          <div className="font-semibold">{row.name}</div>
                          <div className="font-mono text-xs text-on-surface-variant">
                            {row.code}
                            {!row.is_active && ' · ngừng bán'}
                            {row.legacy > 0 && ` · tồn cũ chưa phân: ${row.legacy}`}
                          </div>
                        </td>
                        <td className="py-2 pr-4 text-on-surface-variant">{row.category_label}</td>
                        <td className="py-2 pr-4 text-right whitespace-nowrap">
                          <span className={`font-mono text-base font-semibold ${row.level === 'negative' || row.level === 'out' ? 'text-error' : row.level === 'low' ? 'text-warning' : ''}`}>
                            {row.quantity}
                          </span>
                          <span className="ml-1 text-xs text-on-surface-variant">{row.unit}</span>
                        </td>
                        <td className="py-2 pr-4">
                          <Tag tone={LEVELS[row.level].tone}>{LEVELS[row.level].label}</Tag>
                        </td>
                        {canManage && (
                          <td className="space-x-1 py-2 text-right whitespace-nowrap" onClick={(e) => e.stopPropagation()}>
                            <Button variant="secondary" className="h-8 px-2" onClick={() => setMoving({ type: 'import', itemId: row.id })} aria-label={`Nhập kho ${row.name}`}>
                              Nhập
                            </Button>
                            <Button variant="secondary" className="h-8 px-2" onClick={() => setMoving({ type: 'count', itemId: row.id })} aria-label={`Kiểm kê ${row.name}`}>
                              Kiểm kê
                            </Button>
                          </td>
                        )}
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
            <p className="mt-3 text-xs text-on-surface-variant">
              Sắp hết: còn từ {data.low_threshold} trở xuống. Âm kho: đã giao cho học viên nhiều hơn số đã nhập, cần nhập kho bổ sung hoặc kiểm kê lại.
            </p>
          </Card>

          <Card title={`Xuất nhập gần đây · ${branch.name}`}>
            <MovementTable movements={data.movements} showItem />
          </Card>
        </>
      )}

      {history && branch && <HistoryDialog row={history} branchId={branch.id} onClose={() => setHistory(null)} />}
      {moving && branch && (
        <MoveDialog
          type={moving.type}
          itemId={moving.itemId}
          branchId={branch.id}
          onClose={() => setMoving(null)}
          onDone={(msg, movedBranch) => {
            setMoving(null)
            setNotice(msg)
            setBranchId(movedBranch)
            qc.invalidateQueries({ queryKey: ['inventory'] })
          }}
        />
      )}
    </div>
  )
}

function HistoryDialog({ row, branchId, onClose }: { row: StockRow; branchId: string; onClose: () => void }) {
  const h = useQuery({ queryKey: ['inventory', 'history', row.id, branchId], queryFn: () => inventoryApi.history(row.id, branchId) })
  return (
    <WideDialog title={h.data ? `${h.data.item.name} · ${h.data.branch.name}` : row.name} subtitle={`Mã ${row.code} · 100 lần xuất nhập gần nhất`} onClose={onClose}>
      {h.isError && <Alert>{errorMessage(h.error)}</Alert>}
      {h.data && (
        <div className="space-y-4">
          <p className="flex items-baseline gap-2">
            <span className="text-sm text-on-surface-variant">Tồn hiện tại:</span>
            <span className={`font-mono text-2xl font-semibold ${h.data.quantity <= 0 ? 'text-error' : ''}`}>{h.data.quantity}</span>
            <span className="text-on-surface-variant">{h.data.item.unit}</span>
          </p>
          <MovementTable movements={h.data.movements} />
        </div>
      )}
    </WideDialog>
  )
}

function MoveDialog({
  type: initialType,
  itemId,
  branchId,
  onClose,
  onDone,
}: {
  type: MoveType
  itemId?: string
  branchId: string
  onClose: () => void
  onDone: (msg: string, branchId: string) => void
}) {
  const opts = useQuery({ queryKey: ['inventory', 'stock-form'], queryFn: inventoryApi.stockFormOptions })
  const [type, setType] = useState<MoveType>(initialType)
  const [item, setItem] = useState(itemId ?? '')
  const [branch, setBranch] = useState(branchId)
  const [toBranch, setToBranch] = useState('')
  const [quantity, setQuantity] = useState('')
  const [fromLegacy, setFromLegacy] = useState(false)
  const [note, setNote] = useState('')
  const save = useMutation({
    mutationFn: () =>
      inventoryApi.move({
        type,
        item_id: item || null,
        branch_id: branch || null,
        quantity: toInt(quantity),
        from_legacy: type === 'import' && fromLegacy,
        note,
        to_branch_id: type === 'transfer' ? toBranch || null : null,
      }),
    onSuccess: (r) => onDone(r.message, r.branch_id),
  })
  const picked = opts.data?.items.find((i) => i.value === item)
  const current = item && branch && opts.data ? (opts.data.stock_by_branch[branch]?.[item] ?? 0) : null
  const t = MOVE_TITLES[type]
  const submit = (e: FormEvent) => {
    e.preventDefault()
    save.mutate()
  }

  return (
    <WideDialog title={t.title} subtitle={t.hint} onClose={onClose}>
      {opts.isError && <Alert>{errorMessage(opts.error)}</Alert>}
      {opts.data && (
        <form className="space-y-4" onSubmit={submit}>
          {save.isError && <Alert>{errorMessage(save.error)}</Alert>}
          <div className="grid grid-cols-2 gap-2 sm:grid-cols-4" role="radiogroup" aria-label="Loại thao tác">
            {(Object.keys(MOVE_TITLES) as MoveType[]).map((k) => (
              <Button key={k} type="button" variant={k === type ? 'primary' : 'secondary'} role="radio" aria-checked={k === type} onClick={() => setType(k)}>
                {MOVE_TITLES[k].title.replace(' tồn kho', '')}
              </Button>
            ))}
          </div>
          <Select label="Mặt hàng" required value={item} onChange={(e) => setItem(e.target.value)}>
            <option value="">-- Chọn sách / hàng hóa --</option>
            {opts.data.items.map((i) => (
              <option key={i.value} value={i.value}>
                {i.label}
              </option>
            ))}
          </Select>
          <div className="grid gap-4 sm:grid-cols-2">
            <Select label={type === 'transfer' ? 'Chuyển từ chi nhánh' : 'Chi nhánh'} required value={branch} onChange={(e) => setBranch(e.target.value)}>
              <option value="">-- Chọn chi nhánh --</option>
              {opts.data.branches.map((b) => (
                <option key={b.value} value={b.value}>
                  {b.label}
                </option>
              ))}
            </Select>
            {type === 'transfer' && (
              <Select label="Sang chi nhánh" required value={toBranch} onChange={(e) => setToBranch(e.target.value)}>
                <option value="">-- Chọn chi nhánh nhận --</option>
                {opts.data.transfer_branches
                  .filter((b) => b.value !== branch)
                  .map((b) => (
                    <option key={b.value} value={b.value}>
                      {b.label}
                    </option>
                  ))}
              </Select>
            )}
          </div>
          {current !== null && (
            <p className="text-sm text-on-surface-variant">
              Tồn hiện tại: <strong className={`font-mono ${current <= 0 ? 'text-error' : 'text-on-surface'}`}>{current}</strong> {picked?.unit}
            </p>
          )}
          <Field
            label={type === 'count' ? 'Số tồn thực tế đếm được' : type === 'import' ? 'Số lượng nhập thêm' : type === 'issue' ? 'Số lượng xuất' : 'Số lượng chuyển'}
            required
            type="number"
            min={type === 'count' ? 0 : 1}
            max={100000}
            step={1}
            value={quantity}
            onChange={(e) => setQuantity(e.target.value)}
          />
          {type === 'import' && picked && picked.legacy > 0 && (
            <Checkbox label={`Lấy từ tồn cũ chưa phân chi nhánh (còn ${picked.legacy})`} checked={fromLegacy} onChange={(e) => setFromLegacy(e.target.checked)} />
          )}
          <Textarea label="Ghi chú" rows={2} maxLength={500} value={note} onChange={(e) => setNote(e.target.value)} placeholder="VD: Nhập lô sách tháng 10, phiếu nhập NCC số..." />
          <div className="flex justify-end gap-2">
            <Button type="button" variant="secondary" onClick={onClose}>
              Hủy
            </Button>
            <Button type="submit" disabled={save.isPending}>
              {t.submit}
            </Button>
          </div>
        </form>
      )}
    </WideDialog>
  )
}
