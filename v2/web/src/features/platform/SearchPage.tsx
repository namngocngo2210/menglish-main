import { useQuery } from '@tanstack/react-query'
import { useState, type ReactNode } from 'react'
import { errorMessage, type Me } from '../../api'
import { Alert, Button, Field, Icon } from '../../components/ui'
import { searchScreens } from '../../nav'
import { navigate, useQueryParam } from '../../router'
import { searchApi } from './api'
import { hitSubtitle, MIN_TERM, searchPath } from './palette'
import { EmptyState, PageHeader } from './parts'

// Trang Tìm kiếm (Laravel Search/Index.vue): màn hình theo tên + kết quả từng nhóm trong phạm vi người dùng được xem.
export function SearchPage({ me }: { me: Me }) {
  const term = useQueryParam('q').trim()
  // Ô nhập theo từ khóa trên URL; đổi URL (quay lại, tìm từ bảng Ctrl K) thì ô nhập đổi theo.
  const [draft, setDraft] = useState({ term, value: term })
  if (draft.term !== term) setDraft({ term, value: term })
  const tooShort = [...term].length < MIN_TERM
  const result = useQuery({ queryKey: ['search', term, 0], queryFn: () => searchApi.search(term), enabled: !tooShort })
  const screens = searchScreens(me, term)
  const total = screens.length + (result.data?.total ?? 0)

  return (
    <>
      <PageHeader
        title="Tìm kiếm"
        description="Tìm màn hình theo tên, người dùng và chi nhánh theo tên, mã, email hoặc số điện thoại (trong phạm vi bạn được xem)."
      />
      <form
        role="search"
        className="mb-6 flex max-w-xl items-end gap-2"
        onSubmit={(e) => {
          e.preventDefault()
          navigate(draft.value.trim() ? searchPath(draft.value) : '/search')
        }}
      >
        <div className="flex-1">
          <Field
            label="Từ khóa tìm kiếm"
            type="search"
            placeholder="Nhập tên, mã hoặc SĐT (ít nhất 2 ký tự)..."
            value={draft.value}
            onChange={(e) => setDraft({ term, value: e.target.value })}
          />
        </div>
        <Button type="submit">Tìm</Button>
      </form>

      {result.isError && (
        <div className="mb-4">
          <Alert>{errorMessage(result.error)}</Alert>
        </div>
      )}

      {tooShort ? (
        <Panel>
          <EmptyState icon="search" title="Nhập từ khóa để tìm kiếm" description="Từ khóa cần ít nhất 2 ký tự." />
        </Panel>
      ) : !result.data ? null : result.data.forbidden && screens.length === 0 ? (
        <Panel>
          <EmptyState icon="lock" title="Bạn chưa có quyền tìm kiếm" description="Tài khoản của bạn chưa được cấp quyền xem người dùng, chi nhánh hoặc dữ liệu khác để tìm kiếm." />
        </Panel>
      ) : total === 0 ? (
        <Panel>
          <EmptyState icon="search_off" title="Không tìm thấy kết quả" description={`Không có kết quả cho “${term}”. Thử từ khóa khác.`} />
        </Panel>
      ) : (
        <div className="space-y-6">
          <p className="text-sm text-on-surface-variant">
            Tìm thấy {total} kết quả cho “<strong className="text-on-surface">{term}</strong>”.
          </p>
          {screens.length > 0 && (
            <section className="rounded-xl border border-surface-container-highest bg-surface p-4">
              <h2 className="mb-2 text-base font-semibold text-on-surface">Màn hình ({screens.length})</h2>
              <ul className="grid grid-cols-1 gap-1 sm:grid-cols-2 lg:grid-cols-3">
                {screens.map((s) => (
                  <li key={s.path + s.title}>
                    <Link path={s.path} className="flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm text-primary hover:bg-surface-container-low">
                      <Icon name="arrow_forward" className="text-[18px] text-on-surface-variant" />
                      <span className="truncate">{s.title}</span>
                    </Link>
                  </li>
                ))}
              </ul>
            </section>
          )}
          {result.data.groups.map((g) => (
            <section key={g.key} className="overflow-hidden rounded-xl border border-surface-container-highest bg-surface">
              <h2 className="flex items-center gap-2 border-b border-surface-container-high px-4 py-3 text-base font-semibold text-on-surface">
                <Icon name={g.icon || 'search'} className="text-[20px] text-on-surface-variant" />
                {g.label} ({g.items.length})
              </h2>
              <ul className="divide-y divide-surface-container-highest">
                {g.items.map((h) => (
                  <li key={h.id}>
                    <Link path={h.link} className="flex flex-col gap-0.5 px-4 py-3 hover:bg-surface-container-low">
                      <span className="text-sm font-semibold text-primary">{h.title}</span>
                      {hitSubtitle(h) && <span className="text-xs text-on-surface-variant">{hitSubtitle(h)}</span>}
                    </Link>
                  </li>
                ))}
              </ul>
            </section>
          ))}
        </div>
      )}
    </>
  )
}

function Panel({ children }: { children: ReactNode }) {
  return <div className="rounded-xl border border-surface-container-highest bg-surface">{children}</div>
}

function Link({ path, className, children }: { path: string; className: string; children: ReactNode }) {
  return (
    <a
      href={path}
      className={className}
      onClick={(e) => {
        if (e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return
        e.preventDefault()
        navigate(path)
      }}
    >
      {children}
    </a>
  )
}
