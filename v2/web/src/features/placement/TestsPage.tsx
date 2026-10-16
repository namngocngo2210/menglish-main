import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { errorMessage, type Me } from '../../api'
import { Alert, Badge, Button, Card, Field, Select } from '../../components/ui'
import { can } from '../../nav'
import { navigate, usePath } from '../../router'
import { placementApi, type SubmissionRow, type TestSummary } from './api'
import { Empty, Link, LinkButton, PageHeader, Pagination, StatCard, td, th } from './components'
import { GradingPage } from './GradingPage'
import { formatDateTime } from './logic'
import { RubricGuidePage } from './RubricGuidePage'
import { ScorecardPage } from './ScorecardPage'
import { TestDetailPage } from './TestDetailPage'
import { TestEditor } from './TestEditor'

export const BASE = '/placement-tests'

// Màn "Đề test đầu vào": /placement-tests (danh sách), /new, /<id>, /<id>/edit, /submissions/<id>(/scorecard), /rubric-guide.
export function PlacementTestsPage({ me }: { me: Me }) {
  const rest = usePath().slice(BASE.length).split('/').filter(Boolean)
  if (rest[0] === 'rubric-guide') return <RubricGuidePage />
  if (rest[0] === 'new') return <TestEditor me={me} />
  if (rest[0] === 'submissions' && rest[1]) {
    return rest[2] === 'scorecard' ? <ScorecardPage id={rest[1]} /> : <GradingPage me={me} id={rest[1]} />
  }
  if (rest[0] && rest[1] === 'edit') return <TestEditor me={me} id={rest[0]} />
  if (rest[0]) return <TestDetailPage me={me} id={rest[0]} />
  return <TestList me={me} />
}

function TestList({ me }: { me: Me }) {
  const qc = useQueryClient()
  const [filter, setFilter] = useState({ search: '', grade_group: '', status: '', page: 1 })
  const [draft, setDraft] = useState(filter.search)
  const [testId, setTestId] = useState<string | undefined>(() => new URLSearchParams(window.location.search).get('test_id') ?? undefined)
  const [flash, setFlash] = useState<string | null>(null)
  const list = useQuery({ queryKey: ['placement', 'tests', filter], queryFn: () => placementApi.tests(filter) })
  const options = useQuery({ queryKey: ['placement', 'options'], queryFn: placementApi.options })
  const subs = useQuery({ queryKey: ['placement', 'submissions', testId ?? ''], queryFn: () => placementApi.submissions(testId) })
  const act = useMutation({
    mutationFn: async ({ kind, t }: { kind: 'toggle' | 'duplicate' | 'delete' | 'distribute'; t: TestSummary }) => {
      if (kind === 'toggle') return (await placementApi.toggleTest(t.id)).message
      if (kind === 'duplicate') return (await placementApi.duplicateTest(t.id)).message
      if (kind === 'delete') return (await placementApi.deleteTest(t.id)).message
      const r = await placementApi.distribute(t.id)
      await navigator.clipboard?.writeText(window.location.origin + r.data.path).catch(() => undefined)
      return `${r.message} Đã sao chép link làm bài: ${window.location.origin}${r.data.path}`
    },
    onSuccess: (msg) => {
      setFlash(msg)
      qc.invalidateQueries({ queryKey: ['placement'] })
    },
  })
  const stats = list.data?.stats

  return (
    <div className="space-y-6">
      <PageHeader
        title="Quản lý đề test đầu vào"
        description="Quản lý và cập nhật các bộ đề đánh giá năng lực học sinh."
        actions={
          <>
            <LinkButton href={`${BASE}/rubric-guide`}>Thang điểm & hướng dẫn nhận xét</LinkButton>
            {can(me, 'placement_test.create') && (
              <LinkButton variant="primary" href={`${BASE}/new`}>
                Tạo đề mới
              </LinkButton>
            )}
          </>
        }
      />
      {flash && <Alert tone="success">{flash}</Alert>}
      {act.isError && <Alert>{errorMessage(act.error)}</Alert>}
      {stats && (
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
          <StatCard label="Tổng số đề" value={stats.total_tests} tone="primary" />
          <StatCard label="Đang hoạt động" value={stats.active_tests} tone="success" hint={`${stats.hidden_tests} đề đang ẩn`} />
          <StatCard label="Lượt làm bài" value={stats.total_submissions} />
          <StatCard label="Bài chờ chấm" value={stats.pending_submissions} tone="warning" hint="Viết / Nói do Học vụ chấm" />
        </div>
      )}

      <Card>
        <form
          className="mb-4 grid gap-3 sm:grid-cols-[1fr_200px_200px_auto] sm:items-end"
          onSubmit={(e) => {
            e.preventDefault()
            setFilter({ ...filter, search: draft, page: 1 })
          }}
        >
          <Field label="Tìm kiếm" placeholder="Nhập tên đề cần tìm..." value={draft} onChange={(e) => setDraft(e.target.value)} />
          <Select label="Cấp độ" value={filter.grade_group} onChange={(e) => setFilter({ ...filter, grade_group: e.target.value, page: 1 })}>
            <option value="">Tất cả cấp độ</option>
            {options.data?.grade_groups.map((g) => (
              <option key={g.value} value={g.value}>
                {g.label}
              </option>
            ))}
          </Select>
          <Select label="Trạng thái" value={filter.status} onChange={(e) => setFilter({ ...filter, status: e.target.value, page: 1 })}>
            <option value="">Tất cả trạng thái</option>
            <option value="active">Hoạt động</option>
            <option value="hidden">Ẩn</option>
          </Select>
          <Button type="submit">Lọc</Button>
        </form>
        {list.isError && <Alert>{errorMessage(list.error)}</Alert>}
        <div className="overflow-x-auto">
          <table className="w-full min-w-[900px] text-left text-sm">
            <thead className="text-xs text-on-surface-variant">
              <tr>
                <th className={th}>Tên đề test</th>
                <th className={th}>Loại đề</th>
                <th className={th}>Cấp độ</th>
                <th className={th}>Thời gian</th>
                <th className={`${th} text-center`}>Lượt làm</th>
                <th className={th}>Trạng thái</th>
                <th className={`${th} text-right`}>Hành động</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-surface-container-highest">
              {list.data?.tests.map((t) => (
                <tr key={t.id}>
                  <td className={td}>
                    <Link href={`${BASE}/${t.id}`} className="block font-medium text-on-surface hover:text-primary">
                      {t.title}
                    </Link>
                    <span className="font-mono text-xs text-on-surface-variant">ID: {t.code}</span>
                    {t.is_preset && <span className="ml-2 rounded bg-surface-container-high px-1.5 text-xs text-on-surface-variant">Đề chuẩn</span>}
                  </td>
                  <td className={td}>
                    <code className="rounded bg-surface-container-low px-2 py-0.5 font-mono text-xs text-on-surface-variant">{t.type}</code>
                  </td>
                  <td className={td}>
                    <Badge tone="info">{t.grade_group_label}</Badge>
                    {t.target_level && <div className="mt-1 text-xs text-on-surface-variant">{t.target_level}</div>}
                  </td>
                  <td className={`${td} whitespace-nowrap text-on-surface-variant`}>{t.duration_minutes} phút</td>
                  <td className={`${td} text-center`}>
                    <button
                      type="button"
                      className={`font-mono ${t.submissions_count > 0 ? 'text-primary hover:underline' : 'text-on-surface-variant'}`}
                      onClick={() => {
                        setTestId(t.id)
                        document.getElementById('submissions')?.scrollIntoView({ behavior: 'smooth' })
                      }}
                    >
                      {t.submissions_count}
                    </button>
                  </td>
                  <td className={td}>{t.is_active ? <Badge tone="success">Hoạt động</Badge> : <Badge>Ẩn</Badge>}</td>
                  <td className={`${td} pr-0 text-right whitespace-nowrap`}>
                    <div className="flex flex-wrap justify-end gap-1">
                      <LinkButton href={`${BASE}/${t.id}`}>Xem</LinkButton>
                      {can(me, 'placement_test.update') && !t.is_preset && <LinkButton href={`${BASE}/${t.id}/edit`}>Sửa</LinkButton>}
                      {can(me, 'placement_test.update') && (
                        <Button variant="secondary" disabled={act.isPending} onClick={() => act.mutate({ kind: 'toggle', t })}>
                          {t.is_active ? 'Ẩn đề' : 'Kích hoạt'}
                        </Button>
                      )}
                      {can(me, 'placement_test.distribute') && (
                        <Button variant="secondary" disabled={act.isPending} onClick={() => act.mutate({ kind: 'distribute', t })}>
                          Phát hành
                        </Button>
                      )}
                      {can(me, 'placement_test.create') && (
                        <Button variant="secondary" disabled={act.isPending} onClick={() => act.mutate({ kind: 'duplicate', t })}>
                          Nhân bản
                        </Button>
                      )}
                      {can(me, 'placement_test.delete') && !t.is_preset && t.submissions_count === 0 && (
                        <Button
                          variant="danger"
                          disabled={act.isPending}
                          onClick={() => window.confirm('Xóa đề thi này?') && act.mutate({ kind: 'delete', t })}
                        >
                          Xóa
                        </Button>
                      )}
                    </div>
                  </td>
                </tr>
              ))}
              {list.data && list.data.tests.length === 0 && (
                <Empty colSpan={7}>Không tìm thấy đề test. Thử đổi từ khóa hoặc bỏ lọc.</Empty>
              )}
            </tbody>
          </table>
        </div>
        {list.data && (
          <Pagination page={list.data.page} perPage={list.data.per_page} total={list.data.total} unit="đề test" onPage={(page) => setFilter({ ...filter, page })} />
        )}
      </Card>

      <div id="submissions" className="scroll-mt-6">
        <Card
          title={
            <span className="flex flex-wrap items-center justify-between gap-2">
              <span className="flex flex-wrap items-center gap-2">
                Bài làm & kết quả chấm
                {subs.data?.selected_test ? (
                  <Badge tone="info">
                    Đề: {subs.data.selected_test.title} ({subs.data.selected_test.code}) · {subs.data.submissions.length} thí sinh
                  </Badge>
                ) : (
                  <span className="text-xs font-normal text-on-surface-variant">{subs.data?.submissions.length ?? 0} bài nộp gần nhất</span>
                )}
              </span>
              {testId && (
                <Button variant="secondary" onClick={() => setTestId(undefined)}>
                  Bỏ lọc đề này
                </Button>
              )}
            </span>
          }
        >
          {subs.isError && <Alert>{errorMessage(subs.error)}</Alert>}
          <SubmissionsTable me={me} rows={subs.data?.submissions ?? []} filtered={!!testId} />
        </Card>
      </div>
    </div>
  )
}

export function SubmissionsTable({ me, rows, filtered }: { me: Me; rows: SubmissionRow[]; filtered?: boolean }) {
  return (
    <div className="overflow-x-auto">
      <table className="w-full min-w-[1000px] text-left text-sm">
        <thead className="text-xs text-on-surface-variant">
          <tr>
            <th className={th}>Thí sinh</th>
            <th className={th}>Số điện thoại</th>
            <th className={th}>Đề kiểm tra</th>
            <th className={`${th} text-center`}>Nghe</th>
            <th className={`${th} text-center`}>Đọc & Viết</th>
            <th className={`${th} text-center`}>Nói</th>
            <th className={`${th} text-center`}>Tổng điểm</th>
            <th className={th}>Lớp xếp</th>
            <th className={`${th} text-right`}>Thao tác</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-surface-container-highest">
          {rows.map((s) => (
            <tr key={s.id}>
              <td className={`${td} whitespace-nowrap`}>
                <div className="flex flex-wrap items-center gap-1 font-medium">
                  {s.candidate_name}
                  {s.lead_id && <Badge tone="info">Khách CRM</Badge>}
                  {(s.violation_count > 0 || s.auto_submitted) && (
                    <Badge tone="error">
                      Rời bài {s.violation_count} lần{s.auto_submitted ? ' · tự nộp' : ''}
                    </Badge>
                  )}
                </div>
                <div className="font-mono text-xs text-on-surface-variant">{formatDateTime(s.created_at)}</div>
              </td>
              <td className={`${td} font-mono text-on-surface-variant`}>{s.candidate_phone}</td>
              <td className={td}>
                <div>{s.test_title}</div>
                <div className="font-mono text-xs text-on-surface-variant">ID: {s.test_code}</div>
              </td>
              <td className={`${td} text-center font-mono`}>{s.listening}</td>
              <td className={`${td} text-center font-mono`}>{s.reading_writing}</td>
              <td className={`${td} text-center font-mono`}>{s.speaking}</td>
              <td className={`${td} text-center whitespace-nowrap`}>
                {s.is_pending ? <Badge>Chờ chấm</Badge> : <span className="font-mono font-semibold text-primary">{s.total}</span>}
              </td>
              <td className={`${td} font-medium text-primary`}>{s.final_class}</td>
              <td className={`${td} pr-0 text-right whitespace-nowrap`}>
                <div className="flex justify-end gap-1">
                  <Button variant="secondary" onClick={() => navigate(`${BASE}/submissions/${s.id}/scorecard`)}>
                    Phiếu điểm
                  </Button>
                  {can(me, 'placement_test.grade') && (
                    <Button variant="secondary" onClick={() => navigate(`${BASE}/submissions/${s.id}`)}>
                      {s.is_pending ? 'Chấm bài' : 'Chấm lại'}
                    </Button>
                  )}
                </div>
              </td>
            </tr>
          ))}
          {rows.length === 0 && (
            <Empty colSpan={9}>{filtered ? 'Chưa có thí sinh nào nộp bài cho đề này.' : 'Chưa có bài thi nào được nộp.'}</Empty>
          )}
        </tbody>
      </table>
    </div>
  )
}
