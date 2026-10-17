// Quản lý tài liệu giáo trình (SyllabusController::documents): danh sách, lọc, tải lên (hộp thoại), xóa.
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { errorMessage } from '../../api'
import { FileUpload } from '../../components/FileUpload'
import { Alert, Badge, Button, Checkbox, Dialog, Field, Icon, Select } from '../../components/ui'
import type { FileRule } from '../../lib/files'
import { syllabusApi, type DocumentList } from './api'
import {
  confirmThen,
  EmptyRow,
  ErrorText,
  Link,
  LinkButton,
  NoticeBar,
  PageHeader,
  Pagination,
  TableCard,
  tableCls,
  tbodyCls,
  td,
  th,
  useAction,
} from './components'

// Khớp syllabus.DocumentPolicy của máy chủ.
export const DOCUMENT_RULE: FileRule = {
  types: [
    'pdf',
    'doc',
    'docx',
    'ppt',
    'pptx',
    'xls',
    'xlsx',
    'jpg',
    'jpeg',
    'png',
    'gif',
    'webp',
    'mp3',
    'wav',
    'm4a',
    'ogg',
    'mp4',
    'mov',
    'webm',
    'm4v',
  ],
  maxBytes: 100 * 1024 * 1024,
  tooLarge: 'Dung lượng file tối đa 100 MB.',
}

export function DocumentsPage() {
  const [draft, setDraft] = useState({ curriculum_id: '', search: '' })
  const [filters, setFilters] = useState({ ...draft, page: 1 })
  const [uploading, setUploading] = useState(false)
  const list = useQuery({
    queryKey: ['syllabus', 'documents', filters],
    queryFn: () => syllabusApi.documents(filters),
  })
  const del = useAction(syllabusApi.deleteDocument)
  const data = list.data

  return (
    <>
      <PageHeader
        title="Quản lý tài liệu giáo trình"
        description="Quản lý và cập nhật tài liệu cho các khóa học."
        actions={
          <>
            <LinkButton href="/syllabus/builder" icon="edit_document">
              Soạn syllabus
            </LinkButton>
            <LinkButton href="/syllabus/teacher-view" icon="menu_book">
              Xem như giáo viên
            </LinkButton>
            {data?.can_upload && (
              <Button className="gap-1.5" onClick={() => setUploading(true)}>
                <Icon name="upload_file" className="text-[18px]" />
                Tải lên tài liệu
              </Button>
            )}
          </>
        }
      />
      <NoticeBar notice={del.notice} onClose={() => del.setNotice(null)} />
      <ErrorText error={del.error} />
      <TableCard
        header={
          <>
            <h2 className="flex items-center gap-2 font-semibold">
              Danh sách tài liệu đã tải lên <Badge>{data?.total ?? 0} tài liệu</Badge>
            </h2>
            <form
              className="flex flex-wrap items-end gap-2"
              onSubmit={(e) => {
                e.preventDefault()
                setFilters({ ...draft, page: 1 })
              }}
            >
              <Select
                label="Giáo trình"
                value={draft.curriculum_id}
                onChange={(e) =>
                  setFilters({
                    ...draft,
                    curriculum_id: e.target.value,
                    page: 1,
                  })
                }
              >
                <option value="">Tất cả giáo trình</option>
                {data?.curriculums.map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.title}
                  </option>
                ))}
              </Select>
              <Field
                label="Tìm kiếm"
                placeholder="Tìm kiếm tài liệu..."
                value={draft.search}
                onChange={(e) =>
                  setDraft({
                    ...draft,
                    search: e.target.value,
                  })
                }
              />
              <Button type="submit" variant="secondary">
                Lọc
              </Button>
            </form>
          </>
        }
        footer={
          data && (
            <Pagination
              page={data.page}
              pageSize={data.page_size}
              total={data.total}
              unit="tài liệu"
              onPage={(page) => setFilters({ ...filters, page })}
            />
          )
        }
      >
        {list.isError && <Alert>{errorMessage(list.error)}</Alert>}
        <table className={`${tableCls} min-w-[760px]`}>
          <thead>
            <tr>
              <th className={th}>Tên tài liệu</th>
              <th className={th}>Chặng học</th>
              <th className={th}>Đối tượng xem</th>
              <th className={th}>Trạng thái</th>
              <th className={`${th} text-right`}>Thao tác</th>
            </tr>
          </thead>
          <tbody className={tbodyCls}>
            {data?.documents.map((doc) => (
              <tr key={doc.id}>
                <td className={td}>
                  <div className="flex items-center gap-3">
                    <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary-light text-primary">
                      <Icon name={doc.icon} className="text-[18px]" />
                    </span>
                    <div className="min-w-0">
                      <p className="line-clamp-1 font-medium">{doc.title}</p>
                      <p className="text-xs text-on-surface-variant">
                        {doc.curriculum} • <span className="font-mono uppercase">{doc.extension}</span> •{' '}
                        <span className="font-mono">{doc.size_human}</span>
                      </p>
                    </div>
                  </div>
                </td>
                <td className={`${td} whitespace-nowrap`}>{doc.stage_label || '—'}</td>
                <td className={td}>
                  <div className="flex flex-wrap gap-1">
                    {doc.audience_labels.map((l) => (
                      <Badge key={l} tone={l === 'Giáo viên' || l === 'Trợ giảng' ? 'info' : 'neutral'}>
                        {l}
                      </Badge>
                    ))}
                  </div>
                </td>
                <td className={`${td} whitespace-nowrap`}>
                  {doc.downloadable ? <Badge tone="success">Có thể tải</Badge> : <Badge tone="error">Chỉ xem online</Badge>}
                </td>
                <td className={`${td} text-right whitespace-nowrap`}>
                  <div className="flex items-center justify-end gap-2">
                    {doc.downloadable && (
                      <a href={doc.url} className="text-primary hover:underline" title="Tải xuống" download>
                        <Icon name="download" />
                      </a>
                    )}
                    <Link href={`/syllabus/teacher-view?document=${doc.id}`} className="text-primary hover:underline" title="Xem chi tiết">
                      <Icon name="visibility" />
                    </Link>
                    {data.can_upload && (
                      <button
                        type="button"
                        className="text-error hover:underline"
                        title="Xóa"
                        disabled={del.pending}
                        onClick={() => confirmThen(`Xóa tài liệu ${doc.title}? File sẽ bị xóa khỏi máy chủ.`, () => del.run(doc.id))}
                      >
                        <Icon name="delete" />
                      </button>
                    )}
                  </div>
                </td>
              </tr>
            ))}
            {data && !data.documents.length && (
              <EmptyRow colSpan={5} icon="folder_off" title="Chưa có tài liệu nào" hint="Tài liệu được tải lên sẽ hiển thị tại đây." />
            )}
          </tbody>
        </table>
      </TableCard>
      {uploading && data && (
        <UploadDialog data={data} onClose={() => setUploading(false)} onDone={(m) => del.setNotice({ text: m, tone: 'success' })} />
      )}
    </>
  )
}

function UploadDialog({ data, onClose, onDone }: { data: DocumentList; onClose: () => void; onDone: (m: string) => void }) {
  const [f, setF] = useState({
    curriculum_id: '',
    stage_id: '',
    title: '',
    visible_to_teachers: false,
    visible_to_assistants: false,
    downloadable: false,
  })
  const stages = data.curriculums.find((c) => c.id === f.curriculum_id)?.stages ?? []
  const qc = useQueryClient()
  if (!data.curriculums.length) {
    return (
      <Dialog title="Tải lên tài liệu mới" onClose={onClose}>
        <div className="space-y-3 text-center text-sm">
          <p className="font-semibold">Chưa có giáo trình</p>
          <p className="text-on-surface-variant">Tạo giáo trình ở màn Soạn syllabus trước khi tải tài liệu.</p>
          <LinkButton href="/syllabus/builder" variant="primary" icon="add">
            Tạo giáo trình
          </LinkButton>
        </div>
      </Dialog>
    )
  }
  const ready = f.curriculum_id && f.title.trim() && (f.stage_id || !stages.length)
  return (
    <Dialog title="Tải lên tài liệu mới" onClose={onClose} wide>
      <div className="space-y-4">
        <Select
          label="Chọn giáo trình"
          required
          value={f.curriculum_id}
          onChange={(e) =>
            setF({
              ...f,
              curriculum_id: e.target.value,
              stage_id: '',
            })
          }
        >
          <option value="">-- Chọn giáo trình --</option>
          {data.curriculums.map((c) => (
            <option key={c.id} value={c.id}>
              {c.title} ({c.code} · {c.version})
            </option>
          ))}
        </Select>
        <Select label="Chọn chặng học" required={stages.length > 0} value={f.stage_id} onChange={(e) => setF({ ...f, stage_id: e.target.value })}>
          <option value="">-- Chọn chặng học --</option>
          {stages.map((s) => (
            <option key={s.value} value={s.value}>
              {s.label}
            </option>
          ))}
        </Select>
        <Field
          label="Tên tài liệu"
          required
          placeholder="IELTS Reading Masterclass - Student Book"
          value={f.title}
          onChange={(e) => setF({ ...f, title: e.target.value })}
        />
        <div className="space-y-2 rounded-lg border border-outline-variant bg-surface-container-low p-3">
          <p className="text-sm font-medium">Chọn đối tượng xem</p>
          <div className="grid grid-cols-2 gap-2">
            {['Admin', 'Học vụ', 'Học thuật'].map((r) => (
              <Checkbox key={r} label={r} checked disabled readOnly />
            ))}
            <Checkbox
              label="Giáo viên"
              checked={f.visible_to_teachers}
              onChange={(e) =>
                setF({
                  ...f,
                  visible_to_teachers: e.target.checked,
                })
              }
            />
            <Checkbox
              label="Trợ giảng"
              checked={f.visible_to_assistants}
              onChange={(e) =>
                setF({
                  ...f,
                  visible_to_assistants: e.target.checked,
                })
              }
            />
          </div>
          <div className="border-t border-outline-variant pt-2">
            <Checkbox
              label="Cho phép GV/TG tải về (bỏ chọn = chỉ xem trực tuyến)"
              checked={f.downloadable}
              onChange={(e) => setF({ ...f, downloadable: e.target.checked })}
            />
          </div>
          <p className="text-xs text-on-surface-variant">Admin, Học vụ, Học thuật luôn xem được mọi tài liệu.</p>
        </div>
        {(f.visible_to_teachers || f.visible_to_assistants) && !f.downloadable && (
          <Alert tone="info">Khóa tải xuống — Giáo viên chỉ được phép xem trực tuyến để bảo vệ tài liệu.</Alert>
        )}
        <FileUpload
          rule={DOCUMENT_RULE}
          appearance="zone"
          icon="cloud_upload"
          disabled={!ready}
          label={ready ? 'Kéo thả file vào đây hoặc chọn file để lưu tài liệu' : 'Chọn giáo trình, chặng và nhập tên trước khi chọn file'}
          hint="Hỗ trợ PDF, DOCX, PPTX, XLSX, ảnh, audio, video (Tối đa 100MB)"
          onUpload={async (file) => {
            const m = await syllabusApi.uploadDocument(f, file)
            qc.invalidateQueries({ queryKey: ['syllabus'] })
            onDone(m.message)
            onClose()
          }}
        />
        <div className="flex justify-end">
          <Button variant="secondary" onClick={onClose}>
            Hủy
          </Button>
        </div>
      </div>
    </Dialog>
  )
}
