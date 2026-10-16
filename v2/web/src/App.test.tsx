import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import { afterEach, expect, test, vi } from 'vitest'
import { App } from './App'

afterEach(() => vi.restoreAllMocks())

function renderApp() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <App />
    </QueryClientProvider>,
  )
}

test('hiện trạng thái API và phiên bản', async () => {
  vi.spyOn(globalThis, 'fetch').mockResolvedValue(
    new Response(JSON.stringify({ version: 'abc123def4567890', env: 'beta' })),
  )
  renderApp()
  expect(await screen.findByText('Hoạt động')).toBeInTheDocument()
  expect(screen.getByText('beta')).toBeInTheDocument()
  expect(screen.getByText('abc123def456')).toBeInTheDocument()
})

test('báo lỗi khi API không trả lời', async () => {
  vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response('', { status: 502 }))
  renderApp()
  expect(await screen.findByText('Không kết nối được')).toBeInTheDocument()
})
