import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ReactNode } from 'react'
import { afterEach, expect, test, vi } from 'vitest'
import type { Me } from '../../api'
import { MaterialOrdersPage } from './MaterialOrdersPage'
import { StockPage } from './StockPage'

afterEach(() => {
  vi.restoreAllMocks()
  window.history.pushState(null, '', '/')
})

type Route = (body: unknown) => [number, unknown?]

function mockApi(routes: Record<string, Route>) {
  const calls: { key: string; body: unknown }[] = []
  vi.spyOn(globalThis, 'fetch').mockImplementation(async (input, init = {}) => {
    const key = `${init.method ?? 'GET'} ${String(input).replace('/api/v1', '')}`
    const body = init.body ? JSON.parse(String(init.body)) : undefined
    calls.push({ key, body })
    const route = routes[key]
    if (!route) return new Response(JSON.stringify({ error: 'not_found', message: key }), { status: 404 })
    const [status, data] = route(body)
    return new Response(data === undefined ? null : JSON.stringify(data), { status })
  })
  return calls
}

function wrap(node: ReactNode) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(<QueryClientProvider client={client}>{node}</QueryClientProvider>)
}

const me = {
  user: { id: 'u1', kind: 'staff', code: 'HV001', email: null, phone: null, full_name: 'Học vụ', must_change_password: false, totp_enabled: true },
  csrf_token: 'c',
  totp_required: true,
  roles: [],
  permissions: ['merchandise_stock.view', 'merchandise_stock.manage'],
  scopes: {},
  branches: [],
} as Me

const overview = {
  branches: [{ id: 'b1', name: 'Cầu Giấy' }],
  branch: { id: 'b1', name: 'Cầu Giấy' },
  rows: [
    { id: 'i1', code: 'KB1', name: 'Sách Kids Box 1', unit: 'Cuốn', category_label: 'Sách & Giáo trình', category_icon: '', is_active: true, quantity: 3, level: 'low', legacy: 0 },
    { id: 'i2', code: 'UNI', name: 'Áo đồng phục', unit: 'Chiếc', category_label: 'Đồng phục học viên', category_icon: '', is_active: false, quantity: -2, level: 'negative', legacy: 4 },
  ],
  counts: { negative: 1, out: 0, low: 1 },
  legacy_total: 4,
  movements: [],
  categories: [{ value: 'book', label: 'Sách & Giáo trình', icon: '' }],
  filters: { level: '', q: '', category: '' },
  low_threshold: 5,
  can_manage: true,
}

test('trang tồn kho: mức tồn, nhập kho gửi đúng dữ liệu', async () => {
  const calls = mockApi({
    'GET /inventory/stock': () => [200, overview],
    'GET /inventory/stock/form-options': () => [
      200,
      {
        items: [{ value: 'i1', label: '[KB1] Sách Kids Box 1', unit: 'Cuốn', legacy: 0 }],
        branches: [{ value: 'b1', label: 'Cầu Giấy' }],
        transfer_branches: [{ value: 'b1', label: 'Cầu Giấy' }, { value: 'b2', label: 'Hà Đông' }],
        stock_by_branch: { b1: { i1: 3 } },
      },
    ],
    'POST /inventory/stock/movements': () => [201, { message: 'Đã nhập 5 Cuốn Sách Kids Box 1 vào kho Cầu Giấy.', movements: [], branch_id: 'b1' }],
  })
  wrap(<StockPage me={me} />)
  expect(await screen.findByText('Sách Kids Box 1')).toBeInTheDocument()
  expect(screen.getByText('Sắp hết')).toBeInTheDocument()
  expect(screen.getByText('Âm kho', { selector: 'span' })).toBeInTheDocument()
  expect(screen.getByText(/ngừng bán · tồn cũ chưa phân: 4/)).toBeInTheDocument()
  expect(screen.getByRole('button', { name: 'Âm kho (1)' })).toBeInTheDocument()

  await userEvent.click(screen.getByRole('button', { name: 'Nhập kho Sách Kids Box 1' }))
  const dialog = await screen.findByRole('dialog', { name: 'Nhập kho' })
  expect(within(dialog).getByText('3')).toBeInTheDocument() // tồn hiện tại
  await userEvent.type(within(dialog).getByLabelText('Số lượng nhập thêm'), '5')
  await userEvent.click(within(dialog).getAllByRole('button', { name: 'Nhập kho' }).at(-1)!)
  expect(await screen.findByText('Đã nhập 5 Cuốn Sách Kids Box 1 vào kho Cầu Giấy.')).toBeInTheDocument()
  expect(calls.find((c) => c.key === 'POST /inventory/stock/movements')?.body).toEqual({
    type: 'import',
    item_id: 'i1',
    branch_id: 'b1',
    quantity: 5,
    from_legacy: false,
    note: '',
    to_branch_id: null,
  })
})

const order = {
  id: 'o1',
  code: 'OH-2026-0001',
  title: 'Flashcard unit 5',
  category: 'props',
  category_label: 'Đạo cụ',
  quantity: 20,
  branch_id: 'b1',
  branch: 'Cầu Giấy',
  class_id: null,
  requester: 'Giáo viên A',
  use_date: '2026-10-12',
  due_at: '2026-10-11T08:00:00Z',
  status: 'pending',
  status_label: 'Chờ xử lý',
  deadline_state: 'soon',
  created_late: true,
  created_at: '2026-10-07T02:00:00Z',
  processed_late: false,
}

test('order học liệu: danh sách, mở chi tiết theo đường dẫn, nhận xử lý', async () => {
  window.history.pushState(null, '', '/material-orders/o1')
  const calls = mockApi({
    'GET /material-orders?page=1': () => [
      200,
      { orders: [order], total: 1, page: 1, page_size: 15, statuses: [], categories: [], branches: [], can_create: false },
    ],
    'GET /material-orders/o1': () => [200, { order: { ...order, description: 'In màu' }, actions: { claim: true, complete: true, reject: true } }],
    'POST /material-orders/o1/claim': () => [200, { message: 'Đã nhận xử lý order.' }],
  })
  wrap(<MaterialOrdersPage />)
  expect(await screen.findByText('OH-2026-0001')).toBeInTheDocument()
  expect(screen.getAllByText('Tạo trễ').length).toBeGreaterThan(0)
  expect(screen.getAllByText('11/10/2026 15:00').length).toBeGreaterThan(0)

  const dialog = await screen.findByRole('dialog', { name: 'Flashcard unit 5' })
  expect(within(dialog).getByText('In màu')).toBeInTheDocument()
  await userEvent.click(within(dialog).getByRole('button', { name: 'Nhận xử lý' }))
  expect(await screen.findByText('Đã nhận xử lý order.')).toBeInTheDocument()
  expect(calls.some((c) => c.key === 'POST /material-orders/o1/claim')).toBe(true)

  // Từ chối mở hộp thoại nhập lý do
  await userEvent.click(within(dialog).getByRole('button', { name: 'Từ chối' }))
  expect(screen.getByRole('dialog', { name: 'Từ chối order' })).toBeInTheDocument()
})
