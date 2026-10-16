import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useState, type MouseEvent } from 'react'
import { errorMessage } from '../../api'
import { Alert, Button, Card, Field, Select } from '../../components/ui'
import { navigate } from '../../router'
import { studentsApi, type ListParams } from './api'
import { PageHeader, StatusBadge } from './components'
import { formatDate, pageCount, toggle } from './logic'

// Hồ sơ học sinh — danh sách (Laravel Students/Index): tìm, lọc chi nhánh / chip 6 trạng thái / thâm niên, sắp xếp,
// cột Họ tên & Ngày sinh, Liên hệ, Lớp hiện tại, Ngày bắt đầu học (thâm niên), Trạng thái.
export function StudentsListPage() {
  const [q, setQ] = useState('')
  const [params, setParams] = useState<ListParams>({ statuses: [], per_page: 20 })
  const set = (p: Partial<ListParams>) => setParams({ ...params, page: 1, ...p })
  const options = useQuery({ queryKey: ['student-options'], queryFn: studentsApi.options })
  const list = useQuery({
    queryKey: ['students', params],
    queryFn: () => studentsApi.list(params),
    placeholderData: keepPreviousData,
  })
  const statusColor = (s: string) => options.data?.statuses.find((o) => o.value === s)?.color ?? 'neutral'
  const pages = list.data ? pageCount(list.data.total, list.data.per_page) : 1
  const open = (e: MouseEvent, id: string) => {
    e.preventDefault()
    navigate(`/students/${id}`)
  }

  return (
    <>
      <PageHeader
        title="Hồ sơ học sinh"
        description="Quản lý và tra cứu thông tin học sinh toàn hệ thống."
        actions={
          <>
            <div className="flex items-center gap-2 rounded-xl border border-outline-variant bg-surface px-3 py-2">
              <span className="text-sm text-on-surface-variant">Tổng số học sinh</span>
              <span className="text-lg font-semibold text-primary" data-testid="student-total">
                {list.data?.total_visible.toLocaleString('vi-VN') ?? '—'}
              </span>
            </div>
            <Button variant="secondary" onClick={() => navigate('/students/enrollments')}>
              Tiếp nhận &amp; Xếp lớp
            </Button>
          </>
        }
      />
      <Card>
        <form
          className="grid gap-3 md:grid-cols-[1fr_12rem_12rem_12rem_auto] md:items-end"
          onSubmit={(e) => {
            e.preventDefault()
            set({ q })
          }}
        >
          <Field label="Tìm học sinh" placeholder="Tên, mã, SĐT hoặc email…" value={q} onChange={(e) => setQ(e.target.value)} />
          <Select label="Chi nhánh" value={params.branch_id ?? ''} onChange={(e) => set({ branch_id: e.target.value })}>
            <option value="">Tất cả chi nhánh</option>
            {options.data?.branches.map((b) => (
              <option key={b.id} value={b.id}>
                {b.name}
              </option>
            ))}
          </Select>
          <Select label="Thâm niên" value={params.tenure ?? ''} onChange={(e) => set({ tenure: e.target.value })}>
            <option value="">Mọi thâm niên</option>
            {options.data?.tenures.map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </Select>
          <Select label="Sắp xếp" value={params.sort ?? ''} onChange={(e) => set({ sort: e.target.value })}>
            <option value="">Hồ sơ mới nhất</option>
            {options.data?.sorts.map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </Select>
          <Button type="submit" variant="secondary">
            Tìm
          </Button>
        </form>

        {options.data && (
          <div className="mt-4 flex flex-wrap items-center gap-2" role="group" aria-label="Lọc theo trạng thái">
            <span className="text-xs font-medium text-on-surface-variant uppercase">Trạng thái</span>
            {options.data.statuses.map((s) => {
              const on = params.statuses?.includes(s.value) ?? false
              return (
                <button
                  key={s.value}
                  type="button"
                  aria-pressed={on}
                  onClick={() => set({ statuses: toggle(params.statuses ?? [], s.value) })}
                  className={`rounded-full border px-3 py-1 text-sm transition-colors ${
                    on ? 'border-primary bg-primary-light font-semibold text-primary' : 'border-outline-variant text-on-surface-variant hover:bg-surface-container-low'
                  }`}
                >
                  {s.label}
                </button>
              )
            })}
          </div>
        )}

        <div className="mt-4">
          {list.isError && <Alert>{errorMessage(list.error)}</Alert>}
          {list.data && list.data.students.length === 0 && (
            <div className="py-10 text-center text-sm text-on-surface-variant">
              {list.data.has_filters ? (
                <>
                  <p className="font-medium text-on-surface">Không tìm thấy học viên</p>
                  <p className="mt-1">Thử đổi từ khóa hoặc bỏ bớt bộ lọc.</p>
                  <Button
                    variant="secondary"
                    className="mt-3"
                    onClick={() => {
                      setQ('')
                      setParams({ statuses: [], per_page: params.per_page })
                    }}
                  >
                    Xóa bộ lọc
                  </Button>
                </>
              ) : (
                <>
                  <p className="font-medium text-on-surface">Chưa có học viên nào</p>
                  <p className="mt-1">Danh sách chỉ gồm học viên thuộc chi nhánh / lớp bạn được phân quyền.</p>
                </>
              )}
            </div>
          )}
          {list.data && list.data.students.length > 0 && (
            <div className="overflow-x-auto">
              <table className="w-full min-w-[860px] text-left text-sm">
                <thead className="text-xs text-on-surface-variant">
                  <tr>
                    <th className="py-2 pr-4 font-medium">Họ tên &amp; Ngày sinh</th>
                    <th className="py-2 pr-4 font-medium">Thông tin liên hệ</th>
                    <th className="py-2 pr-4 font-medium">Lớp hiện tại</th>
                    <th className="py-2 pr-4 font-medium">Ngày bắt đầu học</th>
                    <th className="py-2 pr-4 font-medium">Trạng thái</th>
                    <th className="py-2 text-right font-medium">Thao tác</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-surface-container-highest">
                  {list.data.students.map((st) => (
                    <tr key={st.id} className="align-top">
                      <td className="py-2.5 pr-4">
                        <a href={`/students/${st.id}`} onClick={(e) => open(e, st.id)} className="font-medium hover:text-primary">
                          {st.full_name}
                        </a>
                        <p className="text-xs text-on-surface-variant">
                          {formatDate(st.dob) ?? 'Chưa có ngày sinh'} · <span className="font-mono">{st.code}</span>
                        </p>
                      </td>
                      <td className="py-2.5 pr-4">
                        <p className="font-mono">{st.phone || '—'}</p>
                        <p className="text-xs text-on-surface-variant">{st.email ?? 'Chưa có email'}</p>
                      </td>
                      <td className="py-2.5 pr-4">
                        {/* Lớp học thuộc phân hệ Lớp học, chưa có ở v2 */}
                        <span className="inline-flex rounded bg-surface-container-high px-2 py-0.5 text-xs text-on-surface-variant">
                          {st.current_class_id ? 'Đã xếp lớp' : 'Chưa có lớp'}
                        </span>
                        {st.branch_name && <p className="mt-1 text-xs text-on-surface-variant">{st.branch_name}</p>}
                      </td>
                      <td className="py-2.5 pr-4">
                        {st.study_started_on ? (
                          <>
                            <p className="font-mono">{formatDate(st.study_started_on)}</p>
                            {st.tenure_label && (
                              <p
                                className={`text-xs whitespace-nowrap ${st.long_term ? 'font-semibold text-primary' : 'text-on-surface-variant'}`}
                                title={st.long_term ? 'Học viên lâu năm' : undefined}
                              >
                                {st.long_term && '★ '}
                                {st.tenure_label}
                              </p>
                            )}
                          </>
                        ) : (
                          <span className="text-xs text-on-surface-variant">Chưa vào học</span>
                        )}
                      </td>
                      <td className="py-2.5 pr-4">
                        <StatusBadge color={statusColor(st.status)}>{st.status_label}</StatusBadge>
                      </td>
                      <td className="py-2.5 text-right">
                        <Button variant="secondary" className="h-8 px-3" onClick={() => navigate(`/students/${st.id}`)}>
                          Chi tiết
                        </Button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>

        {list.data && list.data.total > 0 && (
          <div className="mt-4 flex flex-wrap items-center gap-3 text-sm">
            <Button variant="secondary" disabled={list.data.page <= 1} onClick={() => setParams({ ...params, page: list.data.page - 1 })}>
              Trước
            </Button>
            <span>
              Trang {list.data.page}/{pages} · {list.data.total.toLocaleString('vi-VN')} học sinh
            </span>
            <Button
              variant="secondary"
              disabled={list.data.page >= pages}
              onClick={() => setParams({ ...params, page: list.data.page + 1 })}
            >
              Sau
            </Button>
            <label className="ml-auto flex items-center gap-2">
              <span className="text-on-surface-variant">Mỗi trang</span>
              <select
                className="h-9 rounded-lg border border-outline-variant bg-surface px-2"
                value={params.per_page}
                onChange={(e) => set({ per_page: Number(e.target.value) })}
              >
                {[10, 20, 50].map((n) => (
                  <option key={n} value={n}>
                    {n}
                  </option>
                ))}
              </select>
            </label>
          </div>
        )}
      </Card>
    </>
  )
}
