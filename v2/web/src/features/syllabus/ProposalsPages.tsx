// Đề xuất sửa giáo trình: duyệt (SyllabusController::versions, ?proposal= mở chi tiết) và gửi (teacherPropose).
import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { errorMessage } from '../../api'
import { Alert, Badge, Button, Dialog, Icon, Select } from '../../components/ui'
import { checkFile, type FileRule } from '../../lib/files'
import { useQueryParam } from '../../router'
import { syllabusApi, type ProposalDetail, type TeacherProposePage as ProposePage } from './api'
import {
  EmptyRow,
  ErrorText,
  Link,
  LinkButton,
  NoticeBar,
  PageHeader,
  Pagination,
  setParam,
  StatusBadge,
  TableCard,
  tableCls,
  tbodyCls,
  td,
  TextArea,
  th,
  useAction,
  Info,
} from './components'
import { fmtDateTime } from './logic'

// Khớp syllabus.ProposalPolicy của máy chủ.
export const PROPOSAL_RULE: FileRule = {
  types: ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'mp3', 'wav', 'm4a'],
  maxBytes: 20 * 1024 * 1024,
  tooLarge: 'Dung lượng file đính kèm tối đa 20 MB.',
}

const PROPOSAL_TYPES = [
  'Sửa lỗi chính tả / ngữ pháp trong bài giảng',
  'Cập nhật file audio / video bị lỗi',
  'Thay đổi độ dài / thời gian bài tập',
  'Bổ sung hoạt động / trò chơi tương tác',
  'Khác',
]

export function VersionsPage() {
  const selectedId = useQueryParam('proposal')
  const [filters, setFilters] = useState({ status: '', page: 1 })
  const list = useQuery({
    queryKey: ['syllabus', 'versions', filters, selectedId],
    queryFn: () =>
      syllabusApi.versions({
        ...filters,
        proposal: selectedId || undefined,
      }),
  })
  const done = useAction(async (m: string) => ({ message: m }))
  const d = list.data
  return (
    <>
      <PageHeader
        title="Đề xuất sửa giáo trình"
        back="/syllabus/documents"
        description="Quản lý giáo trình › Đề xuất sửa giáo trình"
        actions={
          <LinkButton href="/syllabus/teacher-propose" icon="edit_attributes">
            Gửi đề xuất
          </LinkButton>
        }
      />
      <NoticeBar notice={done.notice} onClose={() => done.setNotice(null)} />
      {list.isError && <Alert>{errorMessage(list.error)}</Alert>}
      <TableCard
        header={
          <>
            <h2 className="flex items-center gap-2 font-semibold">
              Đề xuất <Badge tone="warning">{d?.pending_count ?? 0} chờ duyệt</Badge>
            </h2>
            <Select label="Trạng thái" value={filters.status} onChange={(e) => setFilters({ status: e.target.value, page: 1 })}>
              <option value="">Tất cả trạng thái</option>
              {d?.status_options.map((o) => (
                <option key={o.value} value={o.value}>
                  {o.label}
                </option>
              ))}
            </Select>
          </>
        }
        footer={
          d && <Pagination page={d.page} pageSize={d.page_size} total={d.total} unit="đề xuất" onPage={(page) => setFilters({ ...filters, page })} />
        }
      >
        <table className={`${tableCls} min-w-[760px]`}>
          <thead>
            <tr>
              <th className={th}>Giáo trình</th>
              <th className={th}>Buổi học / Unit</th>
              <th className={th}>Người đề xuất</th>
              <th className={th}>Gửi lúc</th>
              <th className={th}>Trạng thái</th>
              <th className={th}>
                <span className="sr-only">Thao tác</span>
              </th>
            </tr>
          </thead>
          <tbody className={tbodyCls}>
            {d?.proposals.map((p) => (
              <tr
                key={p.id}
                className={`cursor-pointer hover:bg-surface-container-low ${d.selected?.id === p.id ? 'bg-primary-light' : ''}`}
                onClick={() => setParam('proposal', p.id)}
              >
                <td className={`${td} font-semibold`}>{p.curriculum}</td>
                <td className={`${td} text-on-surface-variant`}>{p.target_label}</td>
                <td className={td}>{p.proposer || '—'}</td>
                <td className={`${td} font-mono text-xs whitespace-nowrap`}>{fmtDateTime(p.created_at)}</td>
                <td className={`${td} whitespace-nowrap`}>
                  <StatusBadge color={p.status_color}>{p.status_label}</StatusBadge>
                </td>
                <td className={`${td} text-right`}>
                  <Button variant="secondary" className="h-8 px-3">
                    Xem
                  </Button>
                </td>
              </tr>
            ))}
            {d && !d.proposals.length && (
              <EmptyRow colSpan={6} title="Chưa có đề xuất nào" hint="Giáo viên gửi đề xuất từ Xin duyệt › Đề xuất sửa giáo trình." />
            )}
          </tbody>
        </table>
      </TableCard>
      {d?.selected && (
        <ProposalDialog
          p={d.selected}
          canReview={d.can_review}
          canManage={d.can_manage}
          onDone={(m) => {
            done.run(m)
            setParam('proposal', null)
          }}
        />
      )}
    </>
  )
}

function ProposalDialog({
  p,
  canReview,
  canManage,
  onDone,
}: {
  p: ProposalDetail
  canReview: boolean
  canManage: boolean
  onDone: (m: string) => void
}) {
  const [note, setNote] = useState('')
  const review = useAction(
    (approve: boolean) => (approve ? syllabusApi.approveProposal(p.id, note) : syllabusApi.rejectProposal(p.id, note)),
    (m) => onDone(m.message),
  )
  const reviewing = canReview && p.status === 'pending'
  return (
    <Dialog title={`Chi tiết đề xuất · ${p.curriculum}`} onClose={() => setParam('proposal', null)} wide>
      <div className="space-y-5 text-sm">
        <section className="space-y-3">
          <h3 className="flex items-center gap-2 font-semibold">
            <Icon name="info" className="text-primary" />
            Thông tin chung
          </h3>
          <dl className="grid grid-cols-1 gap-3 rounded-lg border border-outline-variant bg-surface-container-low p-3 md:grid-cols-2">
            <Info label="Giáo trình">
              {p.curriculum} ({p.curriculum_version})
            </Info>
            <Info label="Buổi học/Unit cần sửa">{p.target_label}</Info>
            <Info label="Người đề xuất" className="md:col-span-2">
              {p.proposer || '—'} <span className="text-xs text-on-surface-variant">{p.proposer_roles || '—'}</span>
            </Info>
          </dl>
        </section>
        <section className="space-y-3">
          <h3 className="flex items-center gap-2 font-semibold">
            <Icon name="edit_document" className="text-primary" />
            Nội dung thay đổi chi tiết
            {p.proposal_type && <Badge>{p.proposal_type}</Badge>}
          </h3>
          <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
            <div>
              <p className="mb-1 text-xs font-medium text-on-surface-variant uppercase">Nội dung cũ</p>
              <div className="min-h-[72px] rounded-lg border border-error/20 bg-error-container/30 p-3 whitespace-pre-line">
                {p.old_content || '—'}
              </div>
            </div>
            <div>
              <p className="mb-1 text-xs font-medium text-primary uppercase">Nội dung mới đề xuất</p>
              <div className="min-h-[72px] rounded-lg border border-success/20 bg-success-container/30 p-3 font-medium whitespace-pre-line">
                {p.new_content}
              </div>
            </div>
          </div>
          <div>
            <p className="mb-1 text-xs font-medium text-on-surface-variant uppercase">Lý do thay đổi</p>
            <div className="rounded-lg border border-outline-variant p-3 whitespace-pre-line">{p.reason || '—'}</div>
          </div>
          {p.attachment_url && (
            <a href={p.attachment_url} className="inline-flex items-center gap-1.5 font-semibold text-primary hover:underline">
              <Icon name="attach_file" className="text-[16px]" />
              {p.attachment_name}
            </a>
          )}
        </section>
        <section className="space-y-3 rounded-lg border border-outline-variant bg-surface-container-low p-3">
          <h3 className="flex items-center gap-2 font-semibold">
            <Icon name="verified_user" className="text-primary" />
            Trạng thái phê duyệt
          </h3>
          <div className="flex flex-wrap items-center gap-3">
            <StatusBadge color={p.status_color}>{p.status_label}</StatusBadge>
            <span className="text-on-surface-variant">Người phê duyệt: {p.reviewer ?? 'Chưa phân công'} · Ban Học thuật</span>
          </div>
          <ol className="space-y-2 border-l-2 border-outline-variant pl-3 text-xs">
            <li>
              <p className="font-semibold">Đã tạo đề xuất</p>
              <p className="text-on-surface-variant">
                {fmtDateTime(p.created_at)} - {p.proposer}
              </p>
            </li>
            <li>
              {p.status === 'pending' ? (
                <>
                  <p className="font-semibold text-on-surface-variant">Đang chờ xử lý</p>
                  <p className="text-on-surface-variant">Hệ thống đang chờ phê duyệt...</p>
                </>
              ) : (
                <>
                  <p className="font-semibold">{p.status_label}</p>
                  <p className="text-on-surface-variant">
                    {fmtDateTime(p.reviewed_at)} - {p.reviewer}
                  </p>
                </>
              )}
            </li>
          </ol>
          {reviewing ? (
            <TextArea
              label="Phản hồi từ người duyệt"
              rows={4}
              placeholder="Nhập lý do phê duyệt hoặc từ chối đề xuất này..."
              hint="Bắt buộc khi từ chối."
              value={note}
              onChange={(e) => setNote(e.target.value)}
            />
          ) : (
            p.review_note && (
              <div>
                <p className="mb-1 text-xs font-medium text-on-surface-variant uppercase">Phản hồi từ người duyệt</p>
                <div className="rounded-lg border border-outline-variant bg-surface p-3 whitespace-pre-line">{p.review_note}</div>
              </div>
            )
          )}
          {p.status === 'approved' && (
            <>
              <Alert tone="info">Nội dung được duyệt chưa tự áp vào giáo trình — Học thuật cập nhật buổi học ở màn Soạn syllabus.</Alert>
              {p.edit_lesson_id && canManage && (
                <LinkButton href={`/syllabus/builder?curriculum=${p.curriculum_id}&edit_lesson=${p.edit_lesson_id}`} icon="edit" className="w-full">
                  Mở buổi để cập nhật
                </LinkButton>
              )}
            </>
          )}
        </section>
        <ErrorText error={review.error} />
        {reviewing && (
          <div className="flex justify-end gap-2">
            <Button variant="danger" disabled={review.pending} onClick={() => review.run(false)}>
              Từ chối
            </Button>
            <Button disabled={review.pending} onClick={() => review.run(true)}>
              Phê duyệt
            </Button>
          </div>
        )}
      </div>
    </Dialog>
  )
}

export function TeacherProposePage() {
  const [filters, setFilters] = useState({ status: '', page: 1 })
  const [open, setOpen] = useState(false)
  const list = useQuery({
    queryKey: ['syllabus', 'teacher-propose', filters],
    queryFn: () => syllabusApi.teacherPropose(filters),
  })
  const done = useAction(async (m: string) => ({ message: m }))
  const d = list.data
  return (
    <>
      <PageHeader
        title="Đề xuất sửa giáo trình"
        back="/syllabus/teacher-view"
        description="Gửi đề xuất sửa lỗi hoặc nội dung giáo trình lên Ban Học thuật."
        actions={
          <>
            <LinkButton href="/syllabus/versions" icon="checklist_rtl">
              Xem trạng thái đề xuất
            </LinkButton>
            <LinkButton href="/syllabus/teacher-adjust" icon="speed">
              Xin điều chỉnh tiến độ
            </LinkButton>
            {d?.can_propose && (
              <Button className="gap-1.5" onClick={() => setOpen(true)}>
                <Icon name="edit_note" className="text-[18px]" />
                Gửi đề xuất
              </Button>
            )}
          </>
        }
      />
      <NoticeBar notice={done.notice} onClose={() => done.setNotice(null)} />
      {list.isError && <Alert>{errorMessage(list.error)}</Alert>}
      <TableCard
        header={
          <>
            <h2 className="flex items-center gap-2 font-semibold">
              Lịch sử đề xuất <Badge>{d?.total ?? 0} đề xuất</Badge>
            </h2>
            <Select label="Lọc" value={filters.status} onChange={(e) => setFilters({ status: e.target.value, page: 1 })}>
              <option value="">Lọc: tất cả trạng thái</option>
              {d?.status_options.map((o) => (
                <option key={o.value} value={o.value}>
                  {o.label}
                </option>
              ))}
            </Select>
          </>
        }
        footer={
          d && <Pagination page={d.page} pageSize={d.page_size} total={d.total} unit="đề xuất" onPage={(page) => setFilters({ ...filters, page })} />
        }
      >
        <table className={`${tableCls} min-w-[640px]`}>
          <thead>
            <tr>
              <th className={th}>Giáo trình / Buổi</th>
              <th className={th}>Nội dung đề xuất</th>
              <th className={th}>Ngày gửi</th>
              <th className={th}>Trạng thái</th>
            </tr>
          </thead>
          <tbody className={tbodyCls}>
            {d?.proposals.map((p) => (
              <tr key={p.id}>
                <td className={td}>
                  <p className="font-medium">{p.curriculum}</p>
                  <p className="text-xs text-on-surface-variant">{p.target}</p>
                </td>
                <td className={`${td} max-w-xs`}>
                  <Link href={`/syllabus/versions?proposal=${p.id}`} className="line-clamp-2 hover:text-primary">
                    {p.new_content}
                  </Link>
                </td>
                <td className={`${td} font-mono text-xs whitespace-nowrap text-on-surface-variant`}>{fmtDateTime(p.created_at)}</td>
                <td className={`${td} whitespace-nowrap`}>
                  <StatusBadge color={p.status_color}>{p.status_label}</StatusBadge>
                  {p.status === 'rejected' && p.review_note && (
                    <p className="mt-1 max-w-[220px] text-xs whitespace-normal text-error">{p.review_note}</p>
                  )}
                </td>
              </tr>
            ))}
            {d && !d.proposals.length && <EmptyRow colSpan={4} icon="edit_note" title="Bạn chưa gửi đề xuất nào" />}
          </tbody>
        </table>
      </TableCard>
      {open && d && (
        <ProposeDialog
          page={d}
          onClose={() => setOpen(false)}
          onDone={(m) => {
            setOpen(false)
            done.run(m)
          }}
        />
      )}
    </>
  )
}

function ProposeDialog({ page, onClose, onDone }: { page: ProposePage; onClose: () => void; onDone: (m: string) => void }) {
  const [f, setF] = useState({
    curriculum_id: '',
    lesson_id: '',
    new_content: '',
    proposal_type: '',
    old_content: '',
    reason: '',
  })
  const [file, setFile] = useState<File | null>(null)
  const [fileError, setFileError] = useState('')
  const lessons = page.curriculums.find((c) => c.id === f.curriculum_id)?.lessons ?? []
  const save = useAction(
    () => syllabusApi.createProposal(f, file),
    (m) => onDone(m.message),
  )
  return (
    <Dialog title="Gửi đề xuất sửa giáo trình" onClose={onClose} wide>
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault()
          save.run()
        }}
      >
        <Select
          label="Chọn giáo trình"
          required
          value={f.curriculum_id}
          onChange={(e) =>
            setF({
              ...f,
              curriculum_id: e.target.value,
              lesson_id: '',
            })
          }
        >
          <option value="">Chọn giáo trình...</option>
          {page.curriculums.map((c) => (
            <option key={c.id} value={c.id}>
              {c.label}
            </option>
          ))}
        </Select>
        <div className="space-y-1">
          <Select label="Chọn buổi học (Tùy chọn)" value={f.lesson_id} onChange={(e) => setF({ ...f, lesson_id: e.target.value })}>
            <option value="">Chọn buổi học...</option>
            {lessons.map((l) => (
              <option key={l.value} value={l.value}>
                {l.label}
              </option>
            ))}
          </Select>
          <p className="text-xs text-on-surface-variant">Bỏ trống nếu đề xuất áp dụng chung cho cả giáo trình.</p>
        </div>
        <TextArea
          label="Mô tả thay đổi đề xuất"
          required
          rows={5}
          placeholder="Nhập chi tiết nội dung cần sửa đổi..."
          value={f.new_content}
          onChange={(e) => setF({ ...f, new_content: e.target.value })}
        />
        <details className="rounded-lg border border-outline-variant bg-surface-container-low" open={!!save.error}>
          <summary className="cursor-pointer px-3 py-2 text-sm font-medium">Thông tin bổ sung (tùy chọn)</summary>
          <div className="space-y-3 border-t border-outline-variant p-3">
            <Select label="Loại đề xuất" value={f.proposal_type} onChange={(e) => setF({ ...f, proposal_type: e.target.value })}>
              <option value="">-- Chọn loại --</option>
              {PROPOSAL_TYPES.map((t) => (
                <option key={t} value={t}>
                  {t}
                </option>
              ))}
            </Select>
            <TextArea
              label="Nội dung hiện tại trong giáo trình"
              rows={2}
              value={f.old_content}
              onChange={(e) => setF({ ...f, old_content: e.target.value })}
            />
            <TextArea label="Lý do thay đổi" rows={2} value={f.reason} onChange={(e) => setF({ ...f, reason: e.target.value })} />
            <div className="space-y-1">
              <label className="block text-sm font-medium">File đính kèm</label>
              <input
                type="file"
                className="block w-full text-xs"
                onChange={(e) => {
                  const picked = e.target.files?.[0] ?? null
                  const err = picked ? checkFile(picked, PROPOSAL_RULE) : null
                  setFileError(err ?? '')
                  setFile(err ? null : picked)
                }}
              />
              <p className="text-xs text-on-surface-variant">PDF, Word, PowerPoint, Excel, ảnh hoặc audio — tối đa 20 MB.</p>
              {fileError && <p className="text-xs text-error">{fileError}</p>}
            </div>
          </div>
        </details>
        <ErrorText error={save.error} />
        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={onClose}>
            Hủy
          </Button>
          <Button type="submit" disabled={save.pending}>
            Gửi đề xuất
          </Button>
        </div>
      </form>
    </Dialog>
  )
}
