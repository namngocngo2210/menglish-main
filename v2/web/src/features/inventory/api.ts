// API Kho vật phẩm (hàng hóa, tồn kho theo chi nhánh) và Order học liệu.
import { request } from '../../api'

export type Option = { value: string; label: string }
export type Category = Option & { icon: string }
export type BranchRef = { id: string; name: string }

export type Item = {
  id: string
  code: string
  name: string
  category: string
  category_label: string
  category_icon: string
  unit: string
  price: number
  cost_price: number | null
  unallocated_quantity: number
  is_active: boolean
  description: string | null
  stock_quantity?: number
  created_at: string
}

export type ItemMetrics = { total: number; active: number; total_stock: number; books: number; uniforms: number }
export type ItemList = {
  items: Item[]
  total: number
  page: number
  page_size: number
  metrics: ItemMetrics
  categories: Category[]
  branches: Option[]
}

export type ItemInput = {
  code: string
  name: string
  category: string
  unit: string
  price: number | null
  cost_price: number | null
  is_active: boolean
  description: string
  stock_quantity?: number | null
  stock_branch_id?: string | null
}

export type StockLevel = 'negative' | 'out' | 'low' | 'ok'
export type StockRow = {
  id: string
  code: string
  name: string
  unit: string
  category_label: string
  category_icon: string
  is_active: boolean
  quantity: number
  level: StockLevel
  legacy: number
}

export type Movement = {
  id: string
  created_at: string
  item_id: string
  item_name: string
  item_code: string
  type: string
  type_label: string
  change: number
  balance: number
  document_number: string | null
  receipt_id: string | null
  student_id: string | null
  counterpart_branch: string | null
  user_name: string | null
  note: string | null
}

export type StockOverview = {
  branches: BranchRef[]
  branch: BranchRef | null
  rows: StockRow[]
  counts: Record<'negative' | 'out' | 'low', number>
  legacy_total: number
  movements: Movement[]
  categories: Category[]
  filters: { level: string; q: string; category: string }
  low_threshold: number
  can_manage: boolean
}

export type StockHistory = { item: Item; branch: BranchRef; quantity: number; movements: Movement[] }

export type FormItem = Option & { unit: string; legacy: number }
export type StockFormOptions = {
  items: FormItem[]
  branches: Option[]
  transfer_branches: Option[]
  stock_by_branch: Record<string, Record<string, number>>
}

export type MoveType = 'import' | 'count' | 'issue' | 'transfer'
export type MoveInput = {
  type: MoveType
  item_id: string | null
  branch_id: string | null
  quantity: number | null
  from_legacy?: boolean
  note?: string
  to_branch_id?: string | null
}

export type OrderStatus = 'pending' | 'processing' | 'done' | 'rejected' | 'overdue'
export type DeadlineState = 'ok' | 'soon' | 'overdue' | ''
export type Order = {
  id: string
  code: string
  title: string
  category: string
  category_label: string
  quantity: number | null
  branch_id: string
  branch: string
  class_id: string | null
  requester: string
  use_date: string
  due_at: string
  status: OrderStatus
  status_label: string
  deadline_state: DeadlineState
  created_late: boolean
  created_at: string
  description?: string | null
  processor_note?: string | null
  reject_reason?: string | null
  processed_by?: string | null
  processed_at?: string | null
  processed_late: boolean
}
export type OrderList = {
  orders: Order[]
  total: number
  page: number
  page_size: number
  statuses: Option[]
  categories: Option[]
  branches: Option[]
  can_create: boolean
}
export type OrderDetail = { order: Order; actions: { claim: boolean; complete: boolean; reject: boolean } }
export type OrderFormOptions = {
  categories: Option[]
  branches: Option[]
  // Lớp mình thấy (phân hệ Lớp học), lọc theo chi nhánh đã chọn.
  classes?: (Option & { branch_id: string })[]
  default_branch_id: string | null
  start_of_month_day: number
  day_before_cutoff: string
}
export type OrderInput = {
  category: string
  branch_id: string | null
  class_id: string | null
  title: string
  description: string
  quantity: number | null
  use_date: string
}

type Msg = { message: string }

// Chuỗi truy vấn bỏ các tham số rỗng.
export function qs(params: Record<string, string | number | null | undefined>) {
  const out = new URLSearchParams()
  for (const [k, v] of Object.entries(params)) if (v !== null && v !== undefined && v !== '') out.set(k, String(v))
  const s = out.toString()
  return s ? `?${s}` : ''
}

export const inventoryApi = {
  items: (p: { q?: string; category?: string; status?: string; page?: number }) =>
    request<ItemList>('GET', `/inventory/items${qs(p)}`),
  createItem: (b: ItemInput) => request<Msg & { item: Item }>('POST', '/inventory/items', b),
  updateItem: (id: string, b: ItemInput) => request<Msg & { item: Item }>('PUT', `/inventory/items/${id}`, b),
  toggleItem: (id: string) => request<Msg & { item: Item }>('POST', `/inventory/items/${id}/toggle`),
  deleteItem: (id: string) => request<Msg>('DELETE', `/inventory/items/${id}`),

  stock: (p: { branch_id?: string; level?: string; q?: string; category?: string }) =>
    request<StockOverview>('GET', `/inventory/stock${qs(p)}`),
  history: (itemId: string, branchId: string) =>
    request<StockHistory>('GET', `/inventory/stock/items/${itemId}/history${qs({ branch_id: branchId })}`),
  stockFormOptions: () => request<StockFormOptions>('GET', '/inventory/stock/form-options'),
  move: (b: MoveInput) => request<Msg & { movements: Movement[]; branch_id: string }>('POST', '/inventory/stock/movements', b),

  orders: (p: { search?: string; status?: string; category?: string; branch_id?: string; page?: number }) =>
    request<OrderList>('GET', `/material-orders${qs(p)}`),
  order: (id: string) => request<OrderDetail>('GET', `/material-orders/${id}`),
  orderFormOptions: () => request<OrderFormOptions>('GET', '/material-orders/form-options'),
  createOrder: (b: OrderInput) => request<Msg & { order: Order; warning?: string }>('POST', '/material-orders', b),
  claimOrder: (id: string) => request<Msg>('POST', `/material-orders/${id}/claim`),
  completeOrder: (id: string, processor_note: string) =>
    request<Msg>('POST', `/material-orders/${id}/complete`, { processor_note }),
  rejectOrder: (id: string, reject_reason: string) =>
    request<Msg>('POST', `/material-orders/${id}/reject`, { reject_reason }),
}
