import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ReactNode } from 'react'
import { afterEach, expect, test, vi } from 'vitest'
import type { Me } from '../../api'
import { buildSidebar, workspaceTabs } from '../../nav'
import { ApprovePage } from './ApprovePage'
import { BillPage } from './BillPage'
import { CancellationsPage } from './CancellationsPage'
import { ConfigPage } from './ConfigPage'
import { OverduePage } from './OverduePage'
import { RefundsPage } from './RefundsPage'
import { SepayPage } from './SepayPage'
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
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  })
  return render(<QueryClientProvider client={client}>{node}</QueryClientProvider>)
}

const me = (perms: string[], admin = false) =>
  ({
    user: {
      id: 'u1',
      kind: 'staff',
      code: 'QL1',
      email: null,
      phone: null,
      full_name: 'Quản lý',
      must_change_password: false,
      totp_enabled: true,
    },
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
      student: {
        id: 's1',
        name: 'Nguyễn Văn An',
        code: 'HV0001',
        phone: '0912',
      },
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
  due: {
    serious_overdue: [],
    new_overdue: [],
    upcoming: [],
    upcoming_page: { ...page, total: 0 },
    paused: [],
    overdue_count: 1,
    type: 'all',
    serious_days: 7,
    upcoming_days: 14,
  },
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
  reconciliation: {
    tone: 'warning',
    label: 'Cần đối chiếu thủ công',
    detail: 'Chưa tìm thấy giao dịch.',
  },
  sepay_warnings: [],
}

test('Duyệt phiếu thu: gửi xác nhận không trùng và hiện thông báo', async () => {
  const calls = mockApi({
    'GET /tuition/receipts/approve': () => [
      200,
      {
        filters: {
          status: 'pending',
          branch_id: null,
          payment_method: 'all',
          q: '',
        },
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
    'POST /tuition/receipts/r1/approve': () => [
      200,
      {
        id: 'r1',
        code: 'PT-2026-000001',
        status: 'approved',
        message: 'Đã phê duyệt phiếu thu PT-2026-000001 và phát hành HĐĐT số C26MEN-0001001!',
      },
    ],
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

test('Bill thông báo nộp học phí: còn phải nộp, đợt thu, VietQR', async () => {
  const id = '0190a000-0000-7000-8000-000000000001'
  mockApi({
    [`GET /tuition/bill/${id}`]: () => [
      200,
      {
        tuition: {
          id,
          total_amount: 6000000,
          discount_amount: 600000,
          other_fees: 0,
          prepaid_amount: 0,
          final_amount: 5400000,
          paid_amount: 0,
          debt_amount: 5400000,
          notes: null,
        },
        student: {
          id: 's1',
          code: 'HV0001',
          name: 'Nguyễn Văn Đức',
          phone: '0912',
          parent_name: 'Phụ huynh Đức',
          parent_phone: null,
        },
        class_name: 'IELTS 1',
        course_name: 'IELTS',
        branch_name: 'Cầu Giấy',
        customer: { id: 'l1', code: 'KH-0001', name: 'Nguyễn Văn Đức' },
        promotion_name: 'Giảm 10%',
        bank_account: {
          bank_name: 'Vietcombank',
          account_number: '00110022',
          account_holder: 'CONG TY MENGLISH',
        },
        transfer_memo: 'NGUYENVANDUC HV0001 IELTS1',
        amount_to_pay: 3400000,
        pending_amount: 2000000,
        vietqr_url: 'https://img.vietqr.io/image/VCB-00110022-compact2.png?amount=3400000',
        qr_warning: null,
        total_sessions: 24,
        schedule: {
          text: 'T2-T4 18:00',
          start_date: '19/10/2026',
          end_date: '19/01/2027',
        },
        receipts: [
          {
            installment: 1,
            code: 'PT-2026-000001',
            invoice_number: null,
            paper_invoice_number: null,
            status: 'pending',
            method: 'transfer',
            method_label: 'Chuyển khoản VietQR',
            date: '17/10/2026',
            content: 'Thanh toán học phí đợt 1',
            amount: 2000000,
          },
        ],
        center: {
          name: 'MENGLISH',
          branches: ['Cầu Giấy: 1 Xuân Thủy'],
          phone: null,
        },
        today: '17/10/2026',
      },
    ],
  })
  window.history.pushState(null, '', `/tuition/bill/${id}`)
  wrap(<BillPage me={me(['lead.view'])} />)
  expect(await screen.findByText('THÔNG BÁO NỘP HỌC PHÍ')).toBeInTheDocument()
  expect(screen.getByText('Ngày 17 tháng 10 năm 2026')).toBeInTheDocument()
  expect(screen.getByText('PT-2026-000001')).toBeInTheDocument()
  expect(screen.getByText('Chờ đối soát')).toBeInTheDocument()
  expect(screen.getByAltText('QR thanh toán VietQR')).toHaveAttribute('src', expect.stringContaining('amount=3400000'))
  expect(screen.getByText('← Quay lại CRM Pipeline')).toBeInTheDocument()
})

test('Quá hạn & nhắc phí: thống kê, gửi nhắc, ghi nhận liên hệ', async () => {
  const row = {
    id: 't1',
    student: { id: 's1', name: 'Nguyễn Văn An', code: 'HV0001', phone: '0912' },
    class_label: 'IE-01',
    class_name: 'IELTS 1',
    branch_name: 'Cầu Giấy',
    fee_label: 'Học phí IELTS',
    debt_amount: 2400000,
    due_date: '07/10/2026',
    days_overdue: 10,
    session_lines: [],
    last_contact: null,
    last_report_date: null,
    reminder_paused_until: null,
    deferred_until: null,
  }
  const calls = mockApi({
    'GET /tuition/overdue': () => [
      200,
      {
        due: { ...students.due, serious_overdue: [row], overdue_count: 1 },
        stats_by_branch: [{ branch_name: 'Cầu Giấy', total_debt: 2400000, count: 1, overdue_count: 1 }],
        stats_by_class: [{ class_name: 'IELTS 1', class_code: 'IE-01', total_debt: 2400000, count: 1, overdue_count: 1 }],
        branches: [],
        classes: [],
      },
    ],
    'POST /tuition/overdue/t1/remind': () => [
      200,
      { message: 'Đã gửi nhắc mốc T+3 cho học viên Nguyễn Văn An (0912): Đã gửi theo mốc Cảnh báo quá hạn.' },
    ],
    'POST /tuition/overdue/t1/contacted': () => [200, { message: 'Đã ghi nhận liên hệ với phụ huynh học viên Nguyễn Văn An — chờ thu.' }],
  })
  wrap(<OverduePage me={me(['tuition.view', 'tuition.mark_contacted'])} />)
  expect(await screen.findByText('Nguyễn Văn An')).toBeInTheDocument()
  expect(screen.getByText('IELTS 1 (IE-01)')).toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'Báo cáo Admin' })).not.toBeInTheDocument()
  await userEvent.click(screen.getByRole('button', { name: 'Gửi nhắc' }))
  expect(await screen.findByText(/Đã gửi nhắc mốc T\+3/)).toBeInTheDocument()
  await userEvent.click(screen.getByRole('button', { name: 'Đã liên hệ' }))
  await userEvent.type(screen.getByLabelText('Ghi chú'), 'Hẹn thứ 6')
  await userEvent.click(screen.getByRole('button', { name: 'Lưu liên hệ' }))
  expect(await screen.findByText('Đã ghi nhận liên hệ với phụ huynh học viên Nguyễn Văn An — chờ thu.')).toBeInTheDocument()
  expect(calls.find((c) => c.key === 'POST /tuition/overdue/t1/contacted')?.body).toEqual({ note: 'Hẹn thứ 6' })
})

test('Hoàn tiền & Khất nợ: căn cứ hoàn, lập hồ sơ, bắt buộc ảnh bằng chứng, từ chối', async () => {
  const pending = {
    id: 'r1',
    ref: '#A1B2C3',
    type: 'refund',
    type_label: 'Hoàn tiền',
    status: 'pending',
    student: { name: 'Nguyễn Văn An', code: 'HV0001', class_name: 'IELTS 1' },
    target: null,
    refund_amount: 900000,
    defer_from: null,
    defer_to: null,
    extended_due_date: null,
    processing_deadline: '20/10/2026',
    overdue: true,
    overdue_days: 2,
    reason: 'Chuyển nơi ở',
    no_transfer_reason: 'Không có người nhận',
    rejection_reason: null,
    created_date: '10/10/2026',
    created_at: '10/10/2026 09:00',
    requester_name: 'Quản lý',
    approver_name: null,
    clawback_commission: null,
    clawback_amount: 0,
    clawback_user_name: null,
    has_proof: false,
    proof_url: null,
    approve_permission: 'refund_transfer.approve_refund',
  }
  const calls = mockApi({
    'GET /tuition/refunds': () => [
      200,
      {
        pending_requests: [pending],
        overdue_count: 1,
        history_requests: [],
        total_count: 1,
        filters: { status: '', type: '', search: '' },
        type_options: [
          { value: 'refund', label: 'Hoàn tiền' },
          { value: 'transfer', label: 'Chuyển nhượng' },
          { value: 'extension', label: 'Khất nợ' },
          { value: 'deferral', label: 'Bảo lưu' },
        ],
        approvable_types: ['refund', 'transfer', 'extension', 'deferral'],
        can_reject_any: true,
        clawback_hints: { r1: { suggest: true, amount: 0, owner: 'Tư vấn A', start: '01/10/2026' } },
        student_finance: {
          s2: {
            has_tuition: true,
            paid: 2000000,
            contract: 2400000,
            debt: 400000,
            due_date: '01/11/2026',
            total_sessions: 24,
            attended_sessions: 12,
            remaining_sessions: 12,
            unit_price: 100000,
            remaining_value: 1200000,
            admin_fee: 120000,
            suggested_refund: 1080000,
            status_label: 'Còn nợ',
          },
        },
        admin_fee_percent: 10,
        targets: [],
        source_options: [{ value: 's2', label: 'Trần Thị Bình · HV0002' }],
        debtor_options: [],
        deadline: '24/10/2026',
        current_month: '2026-10',
        today: '2026-10-17',
        tomorrow: '2026-10-18',
      },
    ],
    'POST /tuition/refunds': () => [201, { message: 'Đã lập hồ sơ Hoàn tiền và gửi lên Admin phê duyệt! Hạn xử lý: 24/10/2026.' }],
    'POST /tuition/refunds/r1/reject': () => [200, { message: 'Đã từ chối yêu cầu.' }],
  })
  wrap(<RefundsPage me={me(['tuition.view', 'refund_transfer.request', 'refund_transfer.reject'], true)} />)
  expect(await screen.findByText('Nguyễn Văn An')).toBeInTheDocument()
  expect(screen.getByText('Quá hạn xử lý 2 ngày')).toBeInTheDocument()

  await userEvent.selectOptions(screen.getByLabelText('Học viên'), 's2')
  expect(screen.getByText('12 / 24')).toBeInTheDocument()
  expect(screen.getByLabelText('Số tiền (đ)')).toHaveValue('1080000')
  await userEvent.type(screen.getByLabelText('Lý do'), 'Không theo được lịch')
  await userEvent.click(screen.getByRole('button', { name: 'Gửi duyệt' }))
  expect(await screen.findByText(/Đã lập hồ sơ Hoàn tiền/)).toBeInTheDocument()
  expect(calls.find((c) => c.key === 'POST /tuition/refunds')?.body).toEqual({
    student_id: 's2',
    type: 'refund',
    reason: 'Không theo được lịch',
    refund_amount: 1080000,
  })

  await userEvent.click(screen.getByRole('button', { name: 'Duyệt' }))
  expect(screen.getByLabelText('Thu hồi hoa hồng của tư vấn viên')).toBeChecked()
  await userEvent.click(screen.getAllByRole('button', { name: 'Duyệt' }).at(-1)!)
  expect(await screen.findByText('Hoàn tiền bắt buộc đính kèm ảnh bằng chứng (ủy nhiệm chi / biên nhận).')).toBeInTheDocument()
  expect(calls.some((c) => c.key === 'POST /tuition/refunds/r1/approve')).toBe(false)
  await userEvent.click(screen.getByRole('button', { name: 'Hủy' }))

  await userEvent.click(screen.getByRole('button', { name: 'Từ chối' }))
  await userEvent.type(screen.getByLabelText('Lý do từ chối'), 'Thiếu chứng từ')
  await userEvent.click(screen.getAllByRole('button', { name: 'Từ chối' }).at(-1)!)
  expect(await screen.findByText('Đã từ chối yêu cầu.')).toBeInTheDocument()
  expect(calls.find((c) => c.key === 'POST /tuition/refunds/r1/reject')?.body).toEqual({ rejection_reason: 'Thiếu chứng từ' })
})

test('Sidebar: Hoàn tiền & Khất nợ chỉ Admin thấy ở Cần duyệt', () => {
  const tabs = (m: Me) => workspaceTabs(m, '/tuition/refunds')?.tabs.map((t) => t.label) ?? []
  expect(tabs(me(['tuition.view', 'refund_transfer.request'], true))).toEqual(['Phiếu thu', 'Hủy hóa đơn', 'Hoàn tiền & Khất nợ'])
  expect(tabs(me(['tuition.view', 'refund_transfer.request']))).not.toContain('Hoàn tiền & Khất nợ')
})

test('Hủy hóa đơn: điền sẵn từ phiếu, gửi yêu cầu, xem chi tiết và duyệt hủy', async () => {
  const row = {
    id: 'c1',
    invoice_number: 'C26MEN-0001001',
    receipt_number: 'PT-2026-000001',
    student_name: 'Nguyễn Văn An',
    student_code: 'HV0001',
    amount: 1000000,
    reason: 'Viết sai tên phụ huynh',
    requester_name: 'Quản lý',
    created_at: '09:00 17/10/2026',
    created_ago: '2 giờ trước',
    status: 'pending',
  }
  const selected = {
    ...row,
    rejection_reason: null,
    approver_name: null,
    created_date: '17/10/2026',
    updated_at: '17/10/2026 09:00',
    proof_url: null,
    class_name: 'IELTS 1',
    branch_name: 'Cầu Giấy',
    receipt: {
      receipt_number: 'PT-2026-000001',
      status_color: 'success',
      status_label: 'Đã duyệt',
      payer_name: 'Phụ huynh An',
      payer_phone: '0912',
      payment_method: 'cash',
      method_label: 'Tiền mặt',
      is_vat_invoice: false,
      paper_invoice_number: null,
      tuition_amount: 1000000,
      discount_amount: 0,
      surcharge_amount: 0,
      surcharge_reason: null,
    },
    payer_name: 'Phụ huynh An',
    payer_phone: '0912',
  }
  const page = (withSelected: boolean) => ({
    filters: { status: 'pending', branch_id: 'all', q: '' },
    branches: [{ value: 'b1', label: 'Cầu Giấy' }],
    pending_count: 1,
    approved_month_count: 0,
    rejected_count: 0,
    can_approve_cancel: true,
    prefill: withSelected ? null : { invoice_number: 'C26MEN-0001001', amount: 1000000 },
    cancellations: [row],
    selected: withSelected ? selected : null,
  })
  const calls = mockApi({
    'GET /tuition/invoices/cancellations': () => [200, page(window.location.search.includes('selected_id'))],
    'POST /tuition/invoices/cancellations': () => [201, { message: 'Đã gửi yêu cầu hủy hóa đơn GTGT lên cấp quản lý phê duyệt!' }],
    'POST /tuition/invoices/cancellations/c1/approve': () => [200, { message: 'Đã duyệt hủy hóa đơn C26MEN-0001001 và hoàn tác công nợ học viên!' }],
  })
  window.history.pushState(null, '', '/tuition/invoices/cancellations?cancel_invoice=C26MEN-0001001')
  wrap(<CancellationsPage me={me(['tuition.view', 'invoice.request_cancel', 'invoice.approve_cancel'], true)} />)
  expect(await screen.findByLabelText('Số Hóa đơn / Biên lai cần hủy')).toHaveValue('C26MEN-0001001')
  expect(screen.getByLabelText('Số tiền trên hóa đơn (VNĐ)')).toHaveValue('1000000')
  await userEvent.type(screen.getByLabelText('Lý do giải trình hủy hóa đơn'), 'Viết sai tên phụ huynh')
  await userEvent.click(screen.getByRole('button', { name: 'Gửi yêu cầu hủy' }))
  expect(await screen.findByText('Đã gửi yêu cầu hủy hóa đơn GTGT lên cấp quản lý phê duyệt!')).toBeInTheDocument()
  expect(calls.find((c) => c.key === 'POST /tuition/invoices/cancellations')?.body).toEqual({
    invoice_number: 'C26MEN-0001001',
    amount: 1000000,
    reason: 'Viết sai tên phụ huynh',
  })

  await userEvent.click(screen.getByRole('button', { name: 'Xem' }))
  expect(await screen.findByText('Yêu cầu hủy hóa đơn C26MEN-0001001')).toBeInTheDocument()
  expect(screen.getByText('Phụ huynh An · 0912')).toBeInTheDocument()
  await userEvent.click(screen.getByRole('button', { name: 'Duyệt hủy hóa đơn' }))
  await userEvent.click(screen.getByRole('button', { name: 'Xác nhận duyệt hủy ngay' }))
  expect(await screen.findByText('Đã duyệt hủy hóa đơn C26MEN-0001001 và hoàn tác công nợ học viên!')).toBeInTheDocument()
  expect(screen.getByRole('link', { name: 'Xuất danh sách' })).toHaveAttribute(
    'href',
    '/api/v1/tuition/invoices/cancellations/export?status=pending&branch_id=all',
  )
})

test('Cấu hình dải số hóa đơn: bảng dải, sửa số kế tiếp, thêm dải giấy, ngừng dùng', async () => {
  const range = {
    id: 'g1',
    branch_id: 'b1',
    branch_name: 'Cầu Giấy',
    kind: 'electronic',
    kind_label: 'Hóa đơn điện tử',
    series_code: 'C26CG',
    template_code: '1/001',
    provider: 'vnpt',
    start_number: 1,
    end_number: 100,
    current_number: 96,
    range_label: '0000001 – 0000100',
    current_label: '0000096',
    max_issued: 95,
    max_issued_label: '0000095',
    remaining: 5,
    remaining_label: '5',
    low: true,
    is_active: true,
    can_manage: true,
    recent: [{ invoice_number: 'C26CG-0000095', label: '17/10/2026 09:00' }],
  }
  const calls = mockApi({
    'GET /tuition/config': () => [
      200,
      { ranges: [range], branches: [{ value: 'b1', label: 'Cầu Giấy' }], can_manage: true, can_manage_default: false },
    ],
    'POST /tuition/config': () => [200, { message: 'Đã lưu cấu hình dải số hóa đơn điện tử thành công!' }],
    'POST /tuition/config/ranges': () => [201, { message: 'Đã thêm dải số hóa đơn giấy (tiền mặt) C26HDG 0000001 – 0000050 cho Cầu Giấy.' }],
    'POST /tuition/config/ranges/g1/toggle': () => [200, { message: 'Đã ngừng dùng dải số C26CG.' }],
  })
  wrap(<ConfigPage me={me(['tuition.view', 'invoice_range.manage', 'invoice.request_cancel'])} />)
  expect(await screen.findByText('Còn 5 — Sắp hết số')).toBeInTheDocument()

  await userEvent.click(screen.getByRole('button', { name: 'Lịch sử cấp số' }))
  expect(screen.getByText('C26CG-0000095')).toBeInTheDocument()
  await userEvent.click(screen.getByRole('button', { name: /Đóng/ }))

  await userEvent.click(screen.getByRole('button', { name: 'Sửa' }))
  expect(screen.getByText('Đã cấp tới 95 — chỉ được đặt từ 96 trở lên.')).toBeInTheDocument()
  await userEvent.clear(screen.getByLabelText('Số hiện tại (số kế tiếp sẽ cấp)'))
  await userEvent.type(screen.getByLabelText('Số hiện tại (số kế tiếp sẽ cấp)'), '97')
  await userEvent.click(screen.getByRole('button', { name: 'Lưu dải số' }))
  expect(await screen.findByText('Đã lưu cấu hình dải số hóa đơn điện tử thành công!')).toBeInTheDocument()
  expect(calls.find((c) => c.key === 'POST /tuition/config')?.body).toEqual({
    config_id: 'g1',
    template_code: '1/001',
    series_code: 'C26CG',
    start_number: 1,
    end_number: 100,
    current_number: 97,
  })

  await userEvent.click(screen.getByRole('button', { name: 'Thêm cấu hình mới' }))
  await userEvent.selectOptions(screen.getByLabelText('Loại hóa đơn'), 'paper')
  await userEvent.selectOptions(screen.getByLabelText('Chọn chi nhánh'), 'b1')
  await userEvent.clear(screen.getByLabelText('Ký hiệu'))
  await userEvent.type(screen.getByLabelText('Ký hiệu'), 'C26HDG')
  await userEvent.type(screen.getByLabelText('Số bắt đầu'), '1')
  await userEvent.type(screen.getByLabelText('Số kết thúc'), '50')
  await userEvent.click(screen.getByRole('button', { name: 'Thêm dải số' }))
  expect(await screen.findByText(/Đã thêm dải số hóa đơn giấy/)).toBeInTheDocument()
  expect(calls.find((c) => c.key === 'POST /tuition/config/ranges')?.body).toEqual({
    kind: 'paper',
    branch_id: 'b1',
    template_code: '1/001',
    series_code: 'C26HDG',
    start_number: 1,
    end_number: 50,
  })

  await userEvent.click(screen.getByRole('button', { name: 'Ngừng dùng' }))
  expect(await screen.findByText('Đã ngừng dùng dải số C26CG.')).toBeInTheDocument()
})

test('Webhook SePay: lưu cấu hình (khóa để trống giữ khóa cũ) và nhật ký giao dịch', async () => {
  const calls = mockApi({
    'GET /tuition/sepay': () => [
      200,
      {
        enabled: true,
        config: {
          webhook_name: 'Xác Thực Thanh Toán Meducation',
          webhook_url: 'https://dungthu.meducation.vn/hook/sepay-gateway/v1/add-payment',
          transaction_type: 'in',
          data_format: 'json',
          auth_method: 'hmac_sha256',
          has_secret: true,
          has_api_key: false,
          is_active: true,
          auto_retry: true,
          current_endpoint: 'https://hoc.menglish.vn/hook/sepay-gateway/v1/add-payment',
          saved_host: 'dungthu.meducation.vn',
          current_host: 'hoc.menglish.vn',
          different_host: true,
        },
        transactions: [
          {
            id: 't1',
            sepay_id: '1004',
            date: '17/10/2026 09:30',
            gateway: 'VCB',
            account_number: '0123456789',
            transfer_type: 'in',
            amount: 1_000_000,
            content: 'NGUYEN VAN AN HV0001',
            reference_code: 'FT1004',
            status: 'matched',
            status_label: 'Đã khớp',
            response_message: 'Đã gạch nợ thành công',
          },
        ],
      },
    ],
    'POST /tuition/sepay/config': () => [200, { message: 'Đã lưu cấu hình kết nối SePay Gateway thành công!' }],
  })
  wrap(<SepayPage me={me(['bank_account.manage'])} />)
  expect(await screen.findByText('https://hoc.menglish.vn/hook/sepay-gateway/v1/add-payment')).toBeInTheDocument()
  expect(screen.getByText(/khác tên miền đang mở \(hoc.menglish.vn\)/)).toBeInTheDocument()
  expect(screen.getByText('Đã khớp')).toBeInTheDocument()
  expect(screen.getByText('NGUYEN VAN AN HV0001')).toBeInTheDocument()

  await userEvent.selectOptions(screen.getByLabelText('Phương thức xác thực'), 'api_key')
  await userEvent.type(screen.getByLabelText('API Key'), 'k-123')
  await userEvent.click(screen.getByLabelText('Tự gửi lại khi lỗi'))
  await userEvent.click(screen.getByRole('button', { name: 'Lưu cấu hình SePay' }))
  expect(await screen.findByText('Đã lưu cấu hình kết nối SePay Gateway thành công!')).toBeInTheDocument()
  expect(calls.find((c) => c.key === 'POST /tuition/sepay/config')?.body).toEqual({
    webhook_name: 'Xác Thực Thanh Toán Meducation',
    webhook_url: 'https://dungthu.meducation.vn/hook/sepay-gateway/v1/add-payment',
    transaction_type: 'in',
    data_format: 'json',
    auth_method: 'api_key',
    secret_key: '',
    api_key: 'k-123',
    is_active: true,
    auto_retry: false,
  })
})

test('Webhook SePay tắt bằng cờ máy chủ: chỉ báo tắt', async () => {
  mockApi({ 'GET /tuition/sepay': () => [200, { enabled: false, config: null, transactions: [] }] })
  wrap(<SepayPage me={me(['bank_account.manage'])} />)
  expect(await screen.findByText(/SePay webhook hiện đang tắt trên hệ thống/)).toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'Lưu cấu hình SePay' })).not.toBeInTheDocument()
})
