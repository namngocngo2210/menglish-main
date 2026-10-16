import { useQuery } from '@tanstack/react-query'
import { fetchMeta } from './api'

export function App() {
  const meta = useQuery({ queryKey: ['meta'], queryFn: fetchMeta })

  return (
    <main className="flex min-h-dvh items-center justify-center bg-slate-50 p-4 text-slate-900">
      <section className="w-full max-w-md rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
        <h1 className="text-xl font-semibold">MEnglish v2</h1>
        <p className="mt-1 text-sm text-slate-600">Bản dựng thử hệ thống mới (React + Go + PostgreSQL).</p>
        <dl className="mt-4 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
          <dt className="text-slate-500">API</dt>
          <dd>
            {meta.isPending && 'Đang kiểm tra…'}
            {meta.isError && <span className="text-red-600">Không kết nối được</span>}
            {meta.isSuccess && <span className="text-emerald-700">Hoạt động</span>}
          </dd>
          {meta.isSuccess && (
            <>
              <dt className="text-slate-500">Môi trường</dt>
              <dd>{meta.data.env}</dd>
              <dt className="text-slate-500">Phiên bản</dt>
              <dd className="font-mono text-xs leading-5">{meta.data.version.slice(0, 12)}</dd>
            </>
          )}
        </dl>
      </section>
    </main>
  )
}
