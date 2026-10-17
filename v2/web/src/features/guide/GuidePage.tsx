import { useQuery } from '@tanstack/react-query'
import { useEffect, useMemo, useState, type MouseEvent } from 'react'
import type { Me } from '../../api'
import { Alert, Badge, Icon } from '../../components/ui'
import { navigate, useQueryParam } from '../../router'
import { EmptyState, PageHeader } from '../platform/parts'
import { filterSections, GUIDE_PARAM, guidesFor, imageSrc, parts, selectedId, type GuideImage } from './logic'

// Hướng dẫn sử dụng theo vai trò (UserGuideController): chọn vai trò ở thanh trên (vai trò của mình xếp đầu), mỗi chức
// năng là một thẻ: ở đâu trong menu, ảnh chụp (bấm để phóng to), các bước, lưu ý. Nội dung tĩnh ở content.ts (tải riêng).
export function GuidePage({ me }: { me: Me }) {
  const content = useQuery({ queryKey: ['guide-content'], queryFn: () => import('./content').then((m) => m.GUIDES), staleTime: Infinity })
  const guides = useMemo(() => (content.data ? guidesFor(me, content.data) : []), [me, content.data])
  const param = useQueryParam(GUIDE_PARAM)
  const [current, setCurrent] = useState(param)
  const [query, setQuery] = useState('')
  const [zoomed, setZoomed] = useState<GuideImage | null>(null)
  const id = selectedId(guides, current)
  const guide = guides.find((g) => g.id === id)
  const sections = guide ? filterSections(guide.sections, query) : []

  useEffect(() => {
    if (!zoomed) return
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && setZoomed(null)
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [zoomed])

  function choose(next: string) {
    setCurrent(next)
    setQuery('')
    const url = new URL(window.location.href)
    url.searchParams.set(GUIDE_PARAM, next)
    url.hash = ''
    window.history.replaceState(window.history.state, '', url)
    window.scrollTo?.({ top: 0 })
  }

  return (
    <>
      <PageHeader title="Hướng dẫn sử dụng" description="Chọn vai trò để xem từng chức năng: vào ở đâu, màn hình trông thế nào và các bước thực hiện." />
      {content.isError && <Alert>Không tải được nội dung hướng dẫn, vui lòng tải lại trang.</Alert>}
      {content.isPending && <p className="text-sm text-on-surface-variant">Đang tải…</p>}
      {guide && (
        <>
          <nav className="-mx-4 mb-4 overflow-x-auto px-4 lg:mx-0 lg:px-0" aria-label="Chọn vai trò">
            <div className="flex w-max gap-2 lg:w-auto lg:flex-wrap">
              {guides.map((g) => {
                const on = g.id === guide.id
                return (
                  <button
                    key={g.id}
                    type="button"
                    aria-pressed={on}
                    onClick={() => choose(g.id)}
                    className={`flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-sm font-medium whitespace-nowrap transition ${
                      on ? 'border-primary bg-primary text-white shadow-sm' : 'border-outline-variant bg-surface text-on-surface hover:border-primary/40'
                    }`}
                  >
                    <Icon name={g.icon} className="text-[18px]" />
                    {g.label}
                    {g.own && <span className={`rounded-full px-1.5 text-xs font-bold ${on ? 'bg-white/25 text-white' : 'bg-primary/10 text-primary'}`}>Của bạn</span>}
                  </button>
                )
              })}
            </div>
          </nav>

          <section className="mb-4 rounded-2xl border border-outline-variant bg-surface p-4 shadow-sm lg:p-6">
            <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
              <div className="flex items-start gap-3">
                <span className="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-primary/10 text-primary">
                  <Icon name={guide.icon} className="text-[28px]" />
                </span>
                <div className="min-w-0">
                  <div className="flex flex-wrap items-center gap-2">
                    <h2 className="text-lg font-semibold">{guide.common ? guide.label : 'Hướng dẫn cho ' + guide.label}</h2>
                    {guide.own && <Badge tone="info">Vai trò của bạn</Badge>}
                  </div>
                  <p className="mt-1 max-w-3xl text-sm text-on-surface-variant">{guide.summary}</p>
                </div>
              </div>
              <div className="w-full md:w-72">
                <input
                  type="search"
                  value={query}
                  onChange={(e) => setQuery(e.target.value)}
                  placeholder="Tìm chức năng, nút bấm…"
                  aria-label="Tìm trong hướng dẫn"
                  className="block h-10 w-full rounded-lg border border-outline-variant px-3 text-sm outline-none focus:border-primary focus:ring-2 focus:ring-primary/25"
                />
              </div>
            </div>
          </section>

          <div className="flex flex-col gap-4 lg:flex-row lg:items-start">
            <aside className="hidden w-64 shrink-0 lg:sticky lg:top-20 lg:block" aria-label="Mục lục">
              <div className="rounded-2xl border border-outline-variant bg-surface p-2 shadow-sm">
                <div className="px-2 py-1 text-xs font-semibold tracking-wide text-on-surface-variant uppercase">Mục lục</div>
                {sections.map((s) => (
                  <a key={s.id} href={'#' + s.id} className="flex items-start gap-2 rounded-xl px-2 py-1.5 text-sm hover:bg-surface-container-low">
                    <span className="mt-px flex size-5 shrink-0 items-center justify-center rounded-full bg-surface-container text-xs font-bold text-on-surface-variant">
                      {guide.sections.indexOf(s) + 1}
                    </span>
                    <span>{s.title}</span>
                  </a>
                ))}
              </div>
            </aside>

            <div className="min-w-0 flex-1 space-y-4">
              {sections.length === 0 && (
                <EmptyState icon="search_off" title="Không tìm thấy chức năng phù hợp" description="Thử từ khóa khác, hoặc chọn vai trò khác ở thanh phía trên." />
              )}
              {sections.map((s) => (
                <article key={guide.id + s.id} id={s.id} className="scroll-mt-24 overflow-hidden rounded-2xl border border-outline-variant bg-surface shadow-sm">
                  <header className="flex flex-col gap-2 border-b border-surface-container p-4 md:flex-row md:items-start md:justify-between lg:px-6">
                    <div className="flex min-w-0 items-start gap-2">
                      <span className="flex size-9 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary">
                        <Icon name={s.icon ?? 'check_circle'} className="text-[20px]" />
                      </span>
                      <div className="min-w-0">
                        <h3 className="text-base font-semibold">
                          <span className="text-on-surface-variant">{guide.sections.indexOf(s) + 1}.</span> {s.title}
                        </h3>
                        {s.menu && (
                          <p className="mt-0.5 text-sm text-on-surface-variant">
                            <Icon name="near_me" className="mr-0.5 align-[-3px] text-[16px]" />
                            Ở đâu: <span className="font-semibold text-on-surface">{s.menu}</span>
                          </p>
                        )}
                      </div>
                    </div>
                    {s.url && (
                      <a
                        href={s.url}
                        onClick={(e: MouseEvent) => {
                          if (e.metaKey || e.ctrlKey) return
                          e.preventDefault()
                          navigate(s.url!)
                        }}
                        className="inline-flex h-9 shrink-0 items-center gap-1 rounded-lg border border-outline-variant px-3 text-sm font-medium hover:bg-surface-container-low"
                      >
                        <Icon name="open_in_new" className="text-[18px]" /> Mở màn hình
                      </a>
                    )}
                  </header>
                  <div className="space-y-4 p-4 lg:px-6">
                    {s.intro && (
                      <p className="text-sm">
                        <Rich text={s.intro} />
                      </p>
                    )}
                    {s.images.length > 0 && (
                      <div className={`grid gap-4 ${s.images.length > 1 && s.images.every((img) => img.mobile) ? 'grid-cols-2 sm:grid-cols-3' : 'max-w-5xl'}`}>
                        {s.images.map((img) => (
                          <figure key={img.src} className={img.mobile ? 'mx-auto w-full max-w-[280px]' : ''}>
                            <button
                              type="button"
                              onClick={() => setZoomed(img)}
                              aria-label={'Phóng to ảnh: ' + (img.caption ?? s.title)}
                              className="group block w-full overflow-hidden rounded-xl border border-outline-variant bg-surface-container-low shadow-sm"
                            >
                              <img src={imageSrc(img.src)} alt={img.caption ?? s.title} loading="lazy" className="block w-full transition group-hover:opacity-90" />
                            </button>
                            {img.caption && <figcaption className="mt-1 text-center text-sm text-on-surface-variant">{img.caption}</figcaption>}
                          </figure>
                        ))}
                      </div>
                    )}
                    {s.steps.length > 0 && (
                      <div>
                        <h4 className="mb-2 text-sm font-semibold">Các bước thực hiện</h4>
                        <ol className="space-y-2">
                          {s.steps.map((step, n) => (
                            <li key={n} className="flex items-start gap-2 text-sm">
                              <span className="mt-px flex size-6 shrink-0 items-center justify-center rounded-full bg-primary text-xs font-bold text-white">{n + 1}</span>
                              <span className="min-w-0 pt-0.5">
                                <Rich text={step} />
                              </span>
                            </li>
                          ))}
                        </ol>
                      </div>
                    )}
                    {s.tips.length > 0 && (
                      <div className="rounded-xl border border-info/20 bg-info-container/40 p-3">
                        <div className="mb-1 flex items-center gap-1 text-sm font-semibold text-on-info-container">
                          <Icon name="lightbulb" className="text-[18px]" /> Lưu ý
                        </div>
                        <ul className="list-inside list-disc space-y-0.5 text-sm">
                          {s.tips.map((tip, n) => (
                            <li key={n}>
                              <Rich text={tip} />
                            </li>
                          ))}
                        </ul>
                      </div>
                    )}
                  </div>
                </article>
              ))}
            </div>
          </div>
        </>
      )}

      {zoomed && (
        <div
          className="fixed inset-0 z-[100] flex items-center justify-center bg-black/80 p-4"
          role="dialog"
          aria-modal="true"
          aria-label={zoomed.caption ?? 'Ảnh hướng dẫn'}
          onClick={(e) => e.target === e.currentTarget && setZoomed(null)}
        >
          <button
            type="button"
            onClick={() => setZoomed(null)}
            aria-label="Đóng ảnh"
            className="absolute top-4 right-4 flex size-10 items-center justify-center rounded-full bg-white/15 text-white hover:bg-white/25"
          >
            <Icon name="close" />
          </button>
          <figure className="max-h-full max-w-6xl overflow-auto">
            <img src={imageSrc(zoomed.src)} alt={zoomed.caption ?? ''} className="mx-auto block max-h-[85vh] w-auto rounded-lg" />
            {zoomed.caption && <figcaption className="mt-2 text-center text-sm text-white/80">{zoomed.caption}</figcaption>}
          </figure>
        </div>
      )}
    </>
  )
}

function Rich({ text }: { text: string }) {
  return (
    <>
      {parts(text).map((p, k) => (p.bold ? <strong key={k}>{p.text}</strong> : <span key={k}>{p.text}</span>))}
    </>
  )
}
