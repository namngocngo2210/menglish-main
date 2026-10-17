// Sổ khoản chi vận hành (Finance/Expenses/Index.vue): 4 thẻ số liệu, lọc kỳ tháng / chi nhánh / tìm kiếm, dòng chi lương
// tự động (khóa, không sửa / xóa) + khoản chi tự nhập; Thêm / Sửa trong hộp thoại (lưu ngay, không cần duyệt).
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent, type ReactNode } from 'react'
import { errorMessage, type Me } from '../../api'
import { Alert, Badge, Button, Card, Dialog, Field, Icon, Select } from '../../components/ui'
import { financeApi, type Expense, type ExpensesPageData, type ExpenseFilter } from './api'
import { branchFilterOptions, dateLabel, expenseDiffText, expenseForm, money, toExpenseInput, vnd } from './logic'

export function ExpensesPage({ me }: { me: Me }) {
  const qc = useQueryClient()
  const [filter, setFilter] = useState<ExpenseFilter>({
    month: '',
    branch_id: '',
    search: '',
  })
  const [q, setQ] = useState('')
  const [editing, setEditing] = useState<Expense | 'new' | null>(null)
  const [deleting, setDeleting] = useState<Expense | null>(null)
  const [notice, setNotice] = useState('')
  const page = useQuery({
    queryKey: ['finance', 'expenses', filter],
    queryFn: () => financeApi.expenses(filter),
    placeholderData: keepPreviousData,
  })
  const d = page.data
  const done = (msg: string) => {
    setNotice(msg)
    setEditing(null)
    setDeleting(null)
    qc.invalidateQueries({ queryKey: ['finance'] })
  }
  const current: ExpenseFilter | null = d ? { month: d.month, branch_id: d.branch_id, search: d.search } : null

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold">Sổ khoản chi vận hành</h1>
          <p className="text-sm text-on-surface-variant">Quản lý và ghi nhận các khoản chi phí hành chính, cơ sở vật chất và chi lương tự động</p>
        </div>
        <span className="flex flex-wrap gap-2">
          {current && (
            <a
              className="inline-flex h-10 items-center gap-1 rounded-lg border border-outline-variant px-4 text-sm font-medium hover:bg-surface-container-low"
              href={financeApi.expensesExportUrl(current)}
              title="Xuất dữ liệu Excel (CSV)"
            >
              <Icon name="download" className="text-[18px]" /> Xuất Excel
            </a>
          )}
          {d?.can_manage && <Button onClick={() => setEditing('new')}>Thêm khoản chi mới</Button>}
        </span>
      </div>

      {notice && <Alert tone="success">{notice}</Alert>}
      {page.isError && <Alert>{errorMessage(page.error)}</Alert>}
      {d?.payroll_pending && <Alert tone="info">{d.payroll_pending}</Alert>}

      {d && (
        <>
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Stat label="Tổng chi kỳ này" icon="account_balance_wallet" value={vnd(d.grand_total_expense)}>
              <span className={d.percent_diff === 0 ? '' : d.is_decreased ? 'text-success' : 'text-error'}>
                {expenseDiffText(d.percent_diff, d.is_decreased, d.prev_month_label)}
              </span>
            </Stat>
            <Stat label="Chi lương tự động (bảng lương)" icon="badge" value={vnd(d.auto_salary_amount)}>
              {d.auto_salary_amount > 0 ? `Đã chốt & Đã trả (${d.auto_salary_staff_count} nhân sự)` : <i>Chưa có bảng lương chốt</i>}
            </Stat>
            <Stat label="Chi phí vận hành tự nhập" icon="shopping_bag" value={vnd(d.total_manual_expense)}>
              Tổng số <strong>{d.manual_expenses_count}</strong> phiếu chi tự nhập
            </Stat>
            <section className="rounded-xl border border-surface-container-highest bg-surface p-5 shadow-sm">
              <div className="flex items-center justify-between text-xs font-semibold tracking-wider text-on-surface-variant uppercase">
                Cơ cấu hình thức <Icon name="credit_card" className="text-[20px] text-primary" />
              </div>
              <div className="mt-3 flex justify-between text-xs">
                <span>Chuyển khoản ({d.transfer_percent}%)</span>
                <strong>{money(d.total_transfer)}</strong>
              </div>
              <div className="mt-1.5 flex h-2 overflow-hidden rounded-full bg-surface-container-low" aria-hidden="true">
                <div className="h-full bg-primary" style={{ width: `${d.transfer_percent}%` }} />
                <div className="h-full bg-warning/70" style={{ width: `${d.cash_percent}%` }} />
              </div>
              <div className="mt-1 text-xs text-on-surface-variant">
                Tiền mặt: {money(d.total_cash)} ({d.cash_percent}%)
              </div>
            </section>
          </div>

          <Card>
            <form
              className="grid gap-3 sm:grid-cols-[1fr_200px_220px_auto] sm:items-end"
              onSubmit={(e) => {
                e.preventDefault()
                setFilter({ ...filter, search: q })
              }}
            >
              <Field label="Tìm kiếm" placeholder="Tìm theo nội dung, người lập..." value={q} onChange={(e) => setQ(e.target.value)} />
              <Select
                label="Kỳ tháng"
                value={d.month}
                onChange={(e) =>
                  setFilter({
                    ...filter,
                    month: e.target.value,
                  })
                }
              >
                {d.month_options.map((o) => (
                  <option key={o.value} value={o.value}>
                    {o.label}
                  </option>
                ))}
              </Select>
              <Select
                label="Chi nhánh"
                value={d.branch_id}
                onChange={(e) =>
                  setFilter({
                    ...filter,
                    branch_id: e.target.value,
                  })
                }
              >
                {branchFilterOptions(d.branches, d.branch_scoped).map((o) => (
                  <option key={o.value} value={o.value}>
                    {o.label}
                  </option>
                ))}
              </Select>
              <Button type="submit" variant="secondary">
                Lọc
              </Button>
            </form>
            <p className="mt-2 text-xs text-on-surface-variant">Dòng lương tự động ẩn khi kỳ chưa có bảng lương chốt/trả</p>

            <div className="mt-4 flex flex-wrap items-center justify-between gap-2">
              <h2 className="flex items-center gap-2 font-semibold">
                Danh sách các khoản chi <Badge>{d.total_items_count} khoản chi</Badge>
              </h2>
            </div>
            <ExpenseTable d={d} onEdit={setEditing} onDelete={setDeleting} />
            <p className="mt-3 text-xs text-on-surface-variant">
              Hiển thị toàn bộ <strong>{d.total_items_count}</strong> bản ghi của tháng | Tổng cộng thực chi:{' '}
              <strong className="text-sm text-on-surface">{money(d.grand_total_expense)}</strong>
            </p>
          </Card>
        </>
      )}

      {editing && d && (
        <ExpenseDialog expense={editing === 'new' ? null : editing} data={d} userName={me.user.full_name} onClose={() => setEditing(null)} onDone={done} />
      )}
      {deleting && <DeleteDialog expense={deleting} onClose={() => setDeleting(null)} onDone={done} />}
    </div>
  )
}

function Stat({ label, icon, value, children }: { label: string; icon: string; value: string; children: ReactNode }) {
  return (
    <section className="rounded-xl border border-surface-container-highest bg-surface p-5 shadow-sm">
      <div className="flex items-center justify-between text-xs font-semibold tracking-wider text-on-surface-variant uppercase">
        {label} <Icon name={icon} className="text-[20px] text-primary" />
      </div>
      <div className="mt-3 text-2xl font-extrabold tracking-tight">
        {value} <span className="text-sm font-semibold text-on-surface-variant">VNĐ</span>
      </div>
      <div className="mt-1 text-xs text-on-surface-variant">{children}</div>
    </section>
  )
}

function MethodBadge({ method }: { method: string }) {
  return method === 'chuyen_khoan' ? <Badge tone="info">Chuyển khoản</Badge> : <Badge tone="warning">Tiền mặt</Badge>
}

function ExpenseTable({ d, onEdit, onDelete }: { d: ExpensesPageData; onEdit: (e: Expense) => void; onDelete: (e: Expense) => void }) {
  const s = d.auto_salary_row
  if (!s && d.expenses.length === 0) {
    return (
      <div className="mt-4 rounded-lg bg-surface-container-low p-6 text-center text-sm text-on-surface-variant">
        <p className="font-medium text-on-surface">Chưa có khoản chi nào trong kỳ tháng này</p>
        {d.can_manage && <p>Bấm "+ Thêm khoản chi mới" để ghi nhận chi phí</p>}
      </div>
    )
  }
  const th = 'py-2 pr-4 font-medium'
  return (
    <div className="mt-3 overflow-x-auto">
      <table className="w-full min-w-[860px] text-left text-sm">
        <thead className="text-xs text-on-surface-variant">
          <tr>
            <th className={th}>Ngày chi</th>
            <th className={th}>Nội dung khoản chi</th>
            <th className={`${th} text-right`}>Số tiền (VNĐ)</th>
            <th className={th}>Hình thức</th>
            <th className={th}>Chi nhánh</th>
            <th className={th}>Ghi chú & Chứng từ</th>
            {d.can_manage && <th className={`${th} text-center`}>Thao tác</th>}
          </tr>
        </thead>
        <tbody className="divide-y divide-surface-container-highest">
          {s && (
            <tr className="border-l-4 border-l-primary bg-primary/5">
              <td className="py-2 pr-4 pl-2 whitespace-nowrap">
                <div className="font-semibold">{s.expense_date}</div>
                <div className="text-xs text-on-surface-variant">{s.date_sub}</div>
              </td>
              <td className="py-2 pr-4">
                <div className="flex items-center gap-2">
                  <Badge tone="info">TỰ ĐỘNG</Badge>
                  <span className="font-bold">{s.title}</span>
                </div>
                <p className="mt-1 text-xs text-on-surface-variant">{s.description}</p>
              </td>
              <td className="py-2 pr-4 text-right whitespace-nowrap">
                <div className="font-mono font-extrabold">{vnd(s.amount)}</div>
                <div className="text-xs text-on-surface-variant">{s.staff_count} nhân sự đủ điều kiện</div>
              </td>
              <td className="py-2 pr-4">
                <MethodBadge method="chuyen_khoan" />
              </td>
              <td className="py-2 pr-4 whitespace-nowrap">
                <Badge>{s.branch_name}</Badge>
              </td>
              <td className="max-w-xs truncate py-2 pr-4 text-xs text-on-surface-variant" title={s.notes}>
                {s.notes}
              </td>
              {d.can_manage && (
                <td className="py-2 text-center text-xs text-on-surface-variant" title="Số liệu tự động từ bảng lương đã chốt — không sửa được tại đây">
                  <Icon name="lock" className="text-[16px]" /> Cố định
                </td>
              )}
            </tr>
          )}
          {d.expenses.map((e) => (
            <tr key={e.id} className="hover:bg-surface-container-low">
              <td className="py-2 pr-4 whitespace-nowrap text-on-surface-variant">{dateLabel(e.expense_date)}</td>
              <td className="py-2 pr-4">
                <div className="font-semibold">{e.title}</div>
                <div className="text-xs text-on-surface-variant">
                  Người lập: {e.creator_name ?? '—'} • {e.created_at} · {e.category_label}
                </div>
              </td>
              <td className="py-2 pr-4 text-right font-mono font-bold whitespace-nowrap">{vnd(e.amount)}</td>
              <td className="py-2 pr-4">
                <MethodBadge method={e.payment_method} />
              </td>
              <td className="py-2 pr-4 whitespace-nowrap text-on-surface-variant">{e.branch_name ?? 'Toàn hệ thống'}</td>
              <td className="py-2 pr-4 text-xs text-on-surface-variant">{e.notes || '—'}</td>
              {d.can_manage && (
                <td className="space-x-1 py-2 text-center whitespace-nowrap">
                  <Button variant="secondary" className="h-8 px-2" onClick={() => onEdit(e)} aria-label={`Sửa khoản chi ${e.title}`}>
                    Sửa
                  </Button>
                  <Button variant="danger" className="h-8 px-2" onClick={() => onDelete(e)} aria-label={`Xóa khoản chi ${e.title}`}>
                    Xóa
                  </Button>
                </td>
              )}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

function ExpenseDialog({
  expense,
  data,
  userName,
  onClose,
  onDone,
}: {
  expense: Expense | null
  data: ExpensesPageData
  userName: string
  onClose: () => void
  onDone: (msg: string) => void
}) {
  const [f, setF] = useState(() => expenseForm(expense, data.today))
  const set = (k: keyof typeof f) => (e: { target: { value: string } }) => setF({ ...f, [k]: e.target.value })
  const save = useMutation({
    mutationFn: () => (expense ? financeApi.updateExpense(expense.id, toExpenseInput(f)) : financeApi.createExpense(toExpenseInput(f))),
    onSuccess: (r) => onDone(r.message),
  })
  const submit = (e: FormEvent) => {
    e.preventDefault()
    save.mutate()
  }
  return (
    <Dialog title={expense ? 'Chỉnh sửa khoản chi vận hành' : 'Thêm khoản chi vận hành mới'} onClose={onClose}>
      <p className="mb-4 text-xs text-on-surface-variant">Lưu ngay vào sổ chi, không cần duyệt</p>
      <form className="space-y-4" onSubmit={submit}>
        {save.isError && <Alert>{errorMessage(save.error)}</Alert>}
        <Field label="Ngày chi" type="date" required value={f.expense_date} onChange={set('expense_date')} />
        <Field
          label="Nội dung khoản chi"
          required
          maxLength={255}
          value={f.title}
          onChange={set('title')}
          placeholder="Ví dụ: Mua rèm cửa phòng học, Nạp mực máy in..."
        />
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Số tiền (VNĐ)" type="number" required min={1000} step={1000} value={f.amount} onChange={set('amount')} placeholder="0" />
          <Select label="Hình thức chi" required value={f.payment_method} onChange={set('payment_method')}>
            <option value="chuyen_khoan">Chuyển khoản</option>
            <option value="tien_mat">Tiền mặt</option>
          </Select>
        </div>
        <Select label="Chi nhánh áp dụng" required value={f.branch_id} onChange={set('branch_id')}>
          <option value="" disabled>
            -- Chọn chi nhánh cơ sở --
          </option>
          {data.branches.map((b) => (
            <option key={b.id} value={b.id}>
              {b.name}
            </option>
          ))}
        </Select>
        <Select label="Phân loại chi phí (Tự động nhận diện nếu để trống)" value={f.category} onChange={set('category')}>
          <option value="">Tự động theo nội dung</option>
          {data.categories.map((c) => (
            <option key={c.value} value={c.value}>
              {c.label}
            </option>
          ))}
        </Select>
        <label className="block space-y-1.5">
          <span className="block text-sm font-medium">Ghi chú & Thông tin chứng từ (Tùy chọn)</span>
          <textarea
            rows={3}
            maxLength={1000}
            value={f.notes}
            onChange={set('notes')}
            placeholder="Nhập mã hóa đơn, thông tin nhà cung cấp hoặc lưu ý nội bộ..."
            className="block w-full resize-none rounded-lg border border-outline-variant px-3 py-2 text-sm outline-none focus:border-primary focus:ring-2 focus:ring-primary/25"
          />
        </label>
        <div className="flex justify-between rounded-lg bg-surface-container-low p-3 text-xs text-on-surface-variant">
          <span>
            Người lập: <strong>{userName}</strong>
          </span>
          <span>
            Thời điểm: <strong>Tự động khi lưu</strong>
          </span>
        </div>
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            Hủy bỏ
          </Button>
          <Button type="submit" disabled={save.isPending}>
            {expense ? 'Cập nhật khoản chi' : 'Lưu khoản chi'}
          </Button>
        </div>
      </form>
    </Dialog>
  )
}

function DeleteDialog({ expense, onClose, onDone }: { expense: Expense; onClose: () => void; onDone: (msg: string) => void }) {
  const del = useMutation({
    mutationFn: () => financeApi.deleteExpense(expense.id),
    onSuccess: (r) => onDone(r.message),
  })
  return (
    <Dialog title="Xóa khoản chi" onClose={onClose}>
      {del.isError && <Alert>{errorMessage(del.error)}</Alert>}
      <p className="text-sm">Xóa khoản chi “{expense.title}” khỏi sổ chi vận hành?</p>
      <div className="mt-4 flex justify-end gap-2">
        <Button variant="secondary" onClick={onClose}>
          Hủy
        </Button>
        <Button variant="danger" disabled={del.isPending} onClick={() => del.mutate()}>
          Xóa
        </Button>
      </div>
    </Dialog>
  )
}
