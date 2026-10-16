import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, expect, test, vi } from 'vitest'
import { App } from '../../App'
import type { PublicPage } from './api'

afterEach(() => {
  vi.restoreAllMocks()
  window.history.pushState(null, '', '/')
})

const page: PublicPage = {
  test: { code: 'TEST-G3-G4-01', title: 'Đề lớp 3', target_level: 'Lớp 3', duration_minutes: 45, questions_count: 2, pdf_url: '', audio_src: '' },
  listening: [{ number: 1, answer_key: 'l1', type: 'multiple_choice', title: 'Nghe và chọn', options: [{ key: 'A', text: 'cat' }, { key: 'B', text: 'dog' }] }],
  reading: [{ number: 2, answer_key: 'r1', type: 'fill_blank', title: 'Điền từ', options: [] }],
  writing_prompt: null,
  speaking: null,
  sheet: [],
  lead: { name: 'Bé Na', phone: '0912345678', email: '' },
  max_violations: 3,
  expires_at: '2026-12-01T00:00:00Z',
}

function mockApi() {
  const posts: unknown[] = []
  vi.spyOn(globalThis, 'fetch').mockImplementation(async (input, init = {}) => {
    const url = String(input)
    if (url.endsWith('/public/placement/tok-1') && (init.method ?? 'GET') === 'GET') return new Response(JSON.stringify(page), { status: 200 })
    if (url.endsWith('/public/placement/tok-1/submit')) {
      posts.push(JSON.parse(String(init.body)))
      return new Response(JSON.stringify({ candidate_name: 'Bé Na', test_title: 'Đề lớp 3' }), { status: 201 })
    }
    if (url.endsWith('/public/placement/het-han')) {
      return new Response(JSON.stringify({ error: 'link_expired', message: 'Link làm bài đã hết hạn hoặc đã bị thu hồi.' }), { status: 410 })
    }
    return new Response(JSON.stringify({ error: 'unauthenticated' }), { status: 401 })
  })
  return posts
}

function renderAt(path: string) {
  window.history.pushState(null, '', path)
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <App />
    </QueryClientProvider>,
  )
}

test('link làm bài mở không cần đăng nhập; rời bài 3 lần thì tự nộp kèm nhật ký', async () => {
  const posts = mockApi()
  renderAt('/lam-bai/tok-1')
  expect(await screen.findByText('Đề lớp 3')).toBeInTheDocument()
  expect(screen.getByLabelText(/Họ và tên thí sinh/)).toHaveValue('Bé Na')
  // Chưa bắt đầu thì chưa thấy câu hỏi.
  expect(screen.queryByText('Nghe và chọn')).not.toBeInTheDocument()

  await userEvent.click(screen.getByRole('button', { name: 'Bắt đầu làm bài' }))
  await userEvent.click(screen.getByLabelText(/cat/))
  await userEvent.type(screen.getByLabelText('Câu 2'), 'seven')

  let now = 10_000
  vi.spyOn(Date, 'now').mockImplementation(() => now)
  act(() => {
    window.dispatchEvent(new Event('blur'))
  })
  expect(await screen.findByRole('alertdialog')).toHaveTextContent('Đây là lần thứ 1/3')
  // Copy / chuột phải bị chặn khi đang làm bài.
  const copy = new Event('copy', { cancelable: true })
  document.dispatchEvent(copy)
  expect(copy.defaultPrevented).toBe(true)
  await userEvent.click(screen.getByRole('button', { name: 'Quay lại làm bài' }))

  now += 500 // cùng một lần rời bài (blur + visibilitychange) chỉ tính 1
  act(() => {
    window.dispatchEvent(new Event('blur'))
  })
  expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument()
  now += 5_000
  act(() => {
    window.dispatchEvent(new Event('blur'))
  })
  now += 5_000
  act(() => {
    window.dispatchEvent(new Event('blur'))
  })

  expect(await screen.findByTestId('placement-test-done')).toBeInTheDocument()
  expect(posts).toHaveLength(1)
  expect(posts[0]).toMatchObject({
    candidate_name: 'Bé Na',
    answers: { l1: 'A', r1: 'seven' },
    violation_count: 3,
    auto_submitted: true,
  })
})

test('link hết hạn báo lỗi của máy chủ', async () => {
  mockApi()
  renderAt('/lam-bai/het-han')
  expect(await screen.findByText('Link làm bài đã hết hạn hoặc đã bị thu hồi.')).toBeInTheDocument()
})
