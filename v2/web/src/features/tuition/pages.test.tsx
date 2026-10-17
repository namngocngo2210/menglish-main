import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ReactNode } from 'react'
import { afterEach, expect, test, vi } from 'vitest'
import type { Me } from '../../api'
import { buildSidebar } from '../../nav'
import { ApprovePage } from './ApprovePage'
import { StudentsPage } from './StudentsPage'

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
    const route = routes[key.split('?')[0]]
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

const me = (perms: string[], admin = false) =>
  ({
    user: { id: 'u1', kind: 'staff', code: 'QL1', email: null, phone: null, full_name: 'Quản lý', must_change_password: false, totp_enabled: true },
    csrf_token: 'c',
    totp_required: true,
    roles: admin ? [{ name: 'admin', label: 'Admin', short_label: 'Admin' }] : [],
    permissions: perms,
    scopes: {},
    branches: [],
  }) as Me

const page = { page: 1, per_page: 20, total: 1, last_page: 1 }
const students = {
  tuitions: [
    {
      id: 't1',
      student: { id: 's1', name: 'Nguyễn Văn An', code: 'HV0001', phone: '0912' },
      class_name: 'IELTS 1',
      branch_name: 'Cầu Giấy',
      fee_label: 'Học phí IELTS',
      final_amount: 2400000,
      paid_amount: 1000000,
      debt_amount: 1400000,
      due_date: '01/10/2026',
      days_overdue: 9,
      status: 'partial',
      status_color: 'warning',
      status_label: 'Đang nợ (Đã cọc)',
    },
  ],
  page,
  stats: { final: 2400000, paid: 1000000, debt: 1400000, overdue: 1 },
  branches: [{ value: 'b1', label: 'Cầu Giấy' }],
  classes: [],
  statuses: [{ value: 'paid', label: 'Đã hoàn thành' }],
  due: { serious_overdue: [], new_overdue: [], upcoming: [], upcoming_page: { ...page, total: 0 }, paused: [], overdue_count: 1, type: 'all', serious_days: 7, upcoming_days: 14 },
}

test('Công nợ học viên: số liệu, quá hạn nghiêm trọng, lập phiếu từ dòng còn nợ', async () => {
  mockApi({ 'GET /tuition/students': () => [200, students] })
  wrap(<StudentsPage me={me(['tuition.view', 'tuition.create'])} />)
  expect(await screen.findByText('Nguyễn Văn An')).toBeInTheDocument()
  expect(screen.getAllByText('1.400.000 đ').length).toBeGreaterThan(0)
  expect(screen.getByText('Quá hạn 9 ngày')).toBeInTheDocument()
  expect(screen.getByText('Bắt buộc liên hệ trực tiếp')).toBeInTheDocument()
  await userEvent.click(screen.getByRole('button', { name: 'Lập phiếu thu cho Nguyễn Văn An' }))
  expect(window.location.pathname + window.location.search).toBe('/tuition/receipts/new?tuition_id=t1')
})

test('Công nợ học viên: không có quyền lập phiếu thì không có nút', async () => {
  mockApi({ 'GET /tuition/students': () => [200, students] })
  wrap(<StudentsPage me={me(['tuition.view'])} />)
  expect(await screen.findByText('Nguyễn Văn An')).toBeInTheDocument()
  expect(screen.queryByRole('button', { name: /Lập phiếu thu/ })).not.toBeInTheDocument()
})

const detail = {
  id: 'r1',
  code: 'PT-2026-000001',
  source: 'manual',
  status: 'pending',
  status_label: 'Chờ duyệt',
  status_color: 'warning',
  invoice_number: null,
  issued_paper_invoice: null,
  created_at: '10:00 - 10/10/2026',
  creator_name: 'Quản lý',
  approver_name: null,
  branch_name: 'Cầu Giấy',
  student_id: 's1',
  student_name: 'Nguyễn Văn An',
  student_code: 'HV0001',
  class_name: 'IELTS 1',
  payer_name: 'Phụ huynh',
  payer_phone: '0912',
  student_tuition_id: 't1',
  tuition_due_date: null,
  fee_label: 'Học phí IELTS',
  payment_method: 'transfer',
  method_label: 'Chuyển khoản',
  transaction_code: 'FT1',
  paper_invoice_number: null,
  amount: 1000000,
  tuition_portion: 1000000,
  discount_amount: 0,
  promotion_id: null,
  promotion_name: null,
  contract_promotion_name: null,
  contract_promotion_reason: null,
  discount_reason: null,
  surcharge_amount: 0,
  surcharge_reason: null,
  surcharge_items: [],
  session: null,
  is_vat_invoice: false,
  notes: null,
  rejection_reason: null,
  proof_url: 'https://drive.example/a.jpg',
  reference: 'FT1',
  payment_date: '10/10/2026',
  deposit_state: null,
  deposited_at: null,
  deposited_by_name: null,
  can_confirm_deposit: false,
  can_resubmit: false,
  beneficiary: null,
  stock_out: [],
  reconciliation: { tone: 'warning', label: 'Cần đối chiếu thủ công', detail: 'Chưa tìm thấy giao dịch.' },
  sepay_warnings: [],
}

test('Duyệt phiếu thu: gửi xác nhận không trùng và hiện thông báo', async () => {
  const calls = mockApi({
    'GET /tuition/receipts/approve': () => [
      200,
      {
        filters: { status: 'pending', branch_id: null, payment_method: 'all', q: '' },
        branches: [],
        statuses: [{ value: 'pending', label: 'Chờ duyệt' }],
        pending_count: 1,
        pending_total: 1000000,
        approved_today_count: 0,
        rejected_today_count: 0,
        receipts: [],
        selected: detail,
        can_approve: true,
        can_reject: true,
      },
    ],
    'POST /tuition/receipts/r1/approve': () => [200, { id: 'r1', code: 'PT-2026-000001', status: 'approved', message: 'Đã phê duyệt phiếu thu PT-2026-000001 và phát hành HĐĐT số C26MEN-0001001!' }],
  })
  window.history.pushState(null, '', '/tuition/receipts/approve?selected_id=r1')
  wrap(<ApprovePage me={me(['tuition.view'], true)} />)
  expect(await screen.findByText('Cần đối chiếu thủ công')).toBeInTheDocument()
  await userEvent.click(screen.getByLabelText('Xác nhận không trùng giao dịch SePay'))
  await userEvent.click(screen.getByRole('button', { name: 'Duyệt & cấp hóa đơn' }))
  expect(await screen.findByText(/phát hành HĐĐT số C26MEN-0001001/)).toBeInTheDocument()
  expect(calls.find((c) => c.key === 'POST /tuition/receipts/r1/approve')?.body).toEqual({ confirm_not_duplicate: true })
})

test('Menu Học phí neo quyền kế toán; Cần duyệt › Phiếu thu chỉ Admin', () => {
  const links = (m: Me) => buildSidebar(m, '/').sections.flatMap((s) => s.links.map((l) => l.label))
  expect(links(me(['tuition.view']))).not.toContain('Học phí')
  expect(links(me(['tuition.view', 'invoice.request_cancel']))).toContain('Học phí')
  expect(links(me(['tuition.view', 'invoice.request_cancel']))).not.toContain('Cần duyệt')
  expect(links(me(['tuition.view', 'tuition.approve'], true))).toContain('Cần duyệt')
})
