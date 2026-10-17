import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { errorMessage, type Me } from '../../api'
import { Alert, Button, Dialog, Icon } from '../../components/ui'
import { can } from '../../nav'
import { portalApi, type Homework, type Remark } from './api'
import { Block, Empty, Pill, PortalFrame, PortalLink, QueryError, SubTabs, TextArea, useNotice, useStudentParam } from './components'
import { CATEGORIES, dmy, filled, markUrgent, portalHref, submittedAt } from './logic'

const REMARK_FIELDS: { key: keyof Remark; label: string; icon: string; color: string }[] = [
  { key: 'monsters', label: 'Monsters', icon: 'hotel_class', color: 'text-warning' },
  { key: 'grammar', label: 'Ngữ pháp', icon: 'psychology', color: 'text-info' },
  { key: 'attitude', label: 'Tinh thần', icon: 'mood', color: 'text-success' },
  { key: 'result', label: 'Kết quả', icon: 'checklist', color: 'text-primary' },
]

// Học tập & Nộp bài tập (Portal/Homework.vue): 6 hạng mục bài tập (nộp / sửa / hủy nộp), nhận xét buổi học, bảng điểm.
// Bài nộp, nhận xét, mini test thuộc phân hệ Cổng giáo viên: chưa gắn thì màn trống và nút nộp báo chờ phân hệ.
export function HomeworkPage({ me }: { me: Me }) {
  const sid = useStudentParam()
  const q = useQuery({ queryKey: ['portal', 'homework', sid], queryFn: () => portalApi.homework(sid) })
  const notice = useNotice()
  const qc = useQueryClient()
  const [upload, setUpload] = useState<{ key: string; title: string } | null>(null)
  const [edit, setEdit] = useState<{ id: string; notes: string } | null>(null)
  const del = useMutation({
    mutationFn: portalApi.deleteHomework,
    onSuccess: (r) => {
      void qc.invalidateQueries({ queryKey: ['portal'] })
      notice.ok(r.message)
    },
    onError: notice.fail,
  })
  if (q.isError) return <QueryError error={q.error} />
  const d = q.data
  const student = d?.student ?? null

  return (
    <PortalFrame
      ctx={d}
      title="Học tập & Nộp bài tập"
      tab="learning"
      actions={
        can(me, 'homework.grade') ? (
          <PortalLink href="/portal/teacher-submissions" className="inline-flex h-9 items-center gap-1 rounded-lg border border-outline-variant px-3 text-xs font-medium hover:bg-surface-container-low">
            <Icon name="video_library" className="text-[16px]" /> Cổng GV: Xem bài nộp của lớp
          </PortalLink>
        ) : null
      }
    >
      <SubTabs
        items={[
          { href: portalHref('/portal/student-homework', student?.id), label: 'Nộp bài tập', icon: 'assignment', active: true },
          { href: portalHref('/portal/pronunciation', student?.id), label: 'Luyện phát âm AI', icon: 'mic', active: false },
        ]}
      />
      {notice.view}
      {d && !d.ready && student && (
        <div className="mb-4">
          <Alert tone="info">Phần bài tập, nhận xét buổi học và điểm mini test chờ phân hệ Cổng giáo viên chuyển sang hệ thống mới.</Alert>
        </div>
      )}
      {d && (
        <div className="flex flex-col gap-6">
          <Block title="Bài tập về nhà" icon="assignment">
            {d.latest_homework && (
              <div className="mb-3 rounded-xl border border-error/30 bg-error-container p-3 text-xs text-on-error-container">
                <p className="font-bold">{d.latest_homework.title}</p>
                {d.latest_homework.description && <p className="mt-0.5">{d.latest_homework.description}</p>}
                <p className="mt-1">
                  {d.latest_homework.class_name} · Hạn nộp: {d.latest_homework.due_date ? dmy(d.latest_homework.due_date) : 'Không giới hạn'}
                </p>
              </div>
            )}
            <div className="mb-3 flex items-center justify-between gap-3">
              <p className="text-xs font-bold text-on-surface">Đã hoàn thành: {d.completed_count}/6 hạng mục</p>
              <div className="h-2 w-1/3 overflow-hidden rounded-full bg-surface-container-high">
                <div className="h-full rounded-full bg-success" style={{ width: `${Math.round((d.completed_count / 6) * 100)}%` }} />
              </div>
            </div>
            <div className="grid gap-2.5 md:grid-cols-2">
              {markUrgent(d.cards, d.homework_urgent).map((c) => (
                <HomeworkCard
                  key={c.key}
                  card={c}
                  canWrite={d.can_write}
                  onUpload={() => setUpload({ key: c.key, title: c.title })}
                  onEdit={(id, notes) => setEdit({ id, notes })}
                  onDelete={(id) => window.confirm('Hủy bài nộp này?') && del.mutate(id)}
                />
              ))}
            </div>
          </Block>

          <Block title="Nhận xét buổi học" icon="insights">
            <div className="flex flex-col gap-3">
              {d.remarks.map((r, i) => (
                <div key={i} className="rounded-2xl border border-surface-container-highest p-4">
                  <div className="mb-2 flex items-center justify-between">
                    <p className="text-xs text-on-surface-variant">Ngày {dmy(r.date)}</p>
                    <Pill tone="success">Đã nhận xét</Pill>
                  </div>
                  <div className="grid grid-cols-2 gap-2">
                    {REMARK_FIELDS.filter((f) => filled(r[f.key])).map((f) => (
                      <div key={f.key} className="flex items-center gap-2 rounded-xl border border-surface-container-highest bg-surface-container-low p-2.5">
                        <Icon name={f.icon} className={`text-lg ${f.color}`} />
                        <div>
                          <p className="text-xs text-on-surface-variant">{f.label}</p>
                          <p className="text-xs font-bold text-on-surface">{r[f.key]}</p>
                        </div>
                      </div>
                    ))}
                  </div>
                  {filled(r.comment) && (
                    <p className="mt-3 rounded-xl border border-primary/30 bg-primary/10 p-2.5 text-xs leading-relaxed text-on-surface-variant">{r.comment}</p>
                  )}
                </div>
              ))}
              {d.remarks.length === 0 && <Empty icon="insights" title="Chưa có nhận xét buổi học nào." />}
            </div>
          </Block>

          <Block title="Bảng điểm" icon="school">
            <div className="flex flex-col gap-2">
              {d.mini_tests.map((t) => (
                <div key={t.id} className="flex items-center justify-between rounded-xl border border-surface-container-highest p-3.5">
                  <div>
                    <h4 className="text-xs font-bold text-on-surface">{t.name}</h4>
                    <p className="font-mono text-xs text-on-surface-variant">Ngày thi: {dmy(t.test_date)}</p>
                  </div>
                  <div className="font-mono text-lg font-black text-primary">
                    {t.score}
                    <span className="text-xs text-on-surface-variant">/{t.max_score}</span>
                  </div>
                </div>
              ))}
              {d.big_test_results.map((b) => (
                <div key={b.id} className="flex items-center justify-between rounded-xl border border-surface-container-highest p-3.5">
                  <div>
                    <h4 className="text-xs font-bold text-on-surface">{b.title}</h4>
                    <p className="font-mono text-xs text-on-surface-variant">Ngày thi: {dmy(b.scheduled_at)}</p>
                  </div>
                  {b.is_absent ? <Pill>Vắng thi</Pill> : <div className="font-mono text-lg font-black text-primary">{b.overall_score ?? '—'}</div>}
                </div>
              ))}
              {d.mini_tests.length === 0 && d.big_test_results.length === 0 && <Empty icon="school" title="Chưa có điểm kiểm tra nào." />}
            </div>
          </Block>
        </div>
      )}

      {upload && student && (
        <UploadDialog
          studentId={student.id}
          type={upload.key}
          title={upload.title}
          onClose={() => setUpload(null)}
          onDone={(m) => {
            setUpload(null)
            notice.ok(m)
          }}
        />
      )}
      {edit && (
        <EditDialog
          id={edit.id}
          initial={edit.notes}
          onClose={() => setEdit(null)}
          onDone={(m) => {
            setEdit(null)
            notice.ok(m)
          }}
        />
      )}
    </PortalFrame>
  )
}

function HomeworkCard({
  card,
  canWrite,
  onUpload,
  onEdit,
  onDelete,
}: {
  card: Homework['cards'][number] & { urgent: boolean }
  canWrite: boolean
  onUpload: () => void
  onEdit: (id: string, notes: string) => void
  onDelete: (id: string) => void
}) {
  const cat = CATEGORIES[card.key] ?? { icon: 'assignment', color: 'text-primary', button: 'Tải lên bài làm' }
  const sub = card.submission
  return (
    <div className="flex flex-col gap-2 rounded-2xl border border-surface-container-highest p-3.5">
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-2">
          <Icon name={cat.icon} className={`text-lg ${cat.color}`} />
          <h4 className="text-xs font-bold text-on-surface">{card.title}</h4>
        </div>
        {sub ? (
          <Pill tone="success">
            <Icon name="check_circle" className="text-[12px]" /> Đã nộp
          </Pill>
        ) : (
          <Pill tone="error">Chưa nộp</Pill>
        )}
      </div>
      {sub ? (
        <>
          <div className="space-y-1 rounded-xl border border-surface-container-highest bg-surface-container-low p-2.5 text-xs">
            <div className="flex items-center justify-between gap-2 text-on-surface-variant">
              <span>
                Nộp lúc: <strong className="font-mono">{submittedAt(sub.submitted_at)}</strong>
              </span>
              {sub.status === 'reviewed' ? (
                <Pill tone="success">{filled(sub.score) ? `Đã chấm: ${sub.score}` : 'Giáo viên đã xem'}</Pill>
              ) : (
                <span className="font-medium text-warning">Chờ giáo viên chấm</span>
              )}
            </div>
            {sub.attachment_url && (
              <a href={sub.attachment_url} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 font-bold text-primary hover:underline">
                <Icon name="attachment" className="text-[14px]" /> {sub.attachment_name ?? 'Xem tệp đính kèm'}
              </a>
            )}
            {sub.notes && <p className="text-on-surface-variant italic">"{sub.notes}"</p>}
            {sub.feedback && (
              <div className="rounded-lg border border-info/30 bg-info/10 p-2 text-info">
                <strong>Nhận xét của GV:</strong> {sub.feedback}
              </div>
            )}
          </div>
          {canWrite && (
            <div className="flex items-center gap-2">
              <Button variant="secondary" className="h-8 flex-1 text-xs" onClick={() => onEdit(sub.id, sub.notes ?? '')}>
                <Icon name="edit" className="mr-1 text-[16px]" /> Sửa / Nộp lại
              </Button>
              <Button variant="danger" className="h-8 px-2" title="Hủy nộp" aria-label="Hủy nộp" onClick={() => onDelete(sub.id)}>
                <Icon name="delete" className="text-[18px]" />
              </Button>
            </div>
          )}
        </>
      ) : (
        canWrite && (
          <div className="flex flex-col gap-1.5">
            <Button variant={card.urgent ? 'primary' : 'secondary'} className="w-full" onClick={onUpload}>
              <Icon name="upload" className="mr-1 text-[18px]" /> {cat.button}
            </Button>
            {cat.quiz && (
              <a href="https://quizizz.com" target="_blank" rel="noopener noreferrer" className="inline-flex items-center justify-center gap-1 text-xs font-bold text-info hover:underline">
                Mở link Quiz trực tuyến <Icon name="open_in_new" className="text-[14px]" />
              </a>
            )}
          </div>
        )
      )}
    </div>
  )
}

function UploadDialog({ studentId, type, title, onClose, onDone }: { studentId: string; type: string; title: string; onClose: () => void; onDone: (m: string) => void }) {
  const qc = useQueryClient()
  const [file, setFile] = useState<File | null>(null)
  const [notes, setNotes] = useState('')
  const m = useMutation({
    mutationFn: () => {
      const f = new FormData()
      f.set('student_id', studentId)
      f.set('homework_type', type)
      f.set('notes', notes)
      if (file) f.set('attachment', file)
      return portalApi.submitHomework(f)
    },
    onSuccess: (r) => {
      void qc.invalidateQueries({ queryKey: ['portal'] })
      onDone(r.message)
    },
  })
  return (
    <Dialog title="Nộp bài tập" onClose={onClose}>
      <form
        onSubmit={(e) => {
          e.preventDefault()
          m.mutate()
        }}
        className="space-y-4"
      >
        <p className="flex items-center gap-2 text-sm font-bold">
          <Icon name="cloud_upload" className="text-primary" /> Nộp bài: {title}
        </p>
        {m.isError && <Alert>{errorMessage(m.error)}</Alert>}
        <label className="block cursor-pointer rounded-xl border-2 border-dashed border-outline-variant bg-surface-container-low p-6 text-center text-xs">
          <Icon name="attach_file" className="block text-[36px] text-on-surface-variant" />
          <span className="block text-on-surface-variant">Bấm để chọn tệp bài làm (Video / Ảnh / PDF)</span>
          <span className="mt-1 block text-on-surface-variant">Hỗ trợ MP4, MOV, PNG, JPG, PDF (tối đa 100MB)</span>
          <input type="file" className="mt-3 w-full text-center" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
        </label>
        <TextArea label="Ghi chú gửi thầy cô (Tùy chọn)" value={notes} onChange={setNotes} placeholder="Nhập ghi chú cho bài nộp..." />
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            Hủy
          </Button>
          <Button type="submit" disabled={m.isPending}>
            Xác nhận nộp bài
          </Button>
        </div>
      </form>
    </Dialog>
  )
}

function EditDialog({ id, initial, onClose, onDone }: { id: string; initial: string; onClose: () => void; onDone: (m: string) => void }) {
  const qc = useQueryClient()
  const [file, setFile] = useState<File | null>(null)
  const [notes, setNotes] = useState(initial)
  const m = useMutation({
    mutationFn: () => {
      const f = new FormData()
      f.set('notes', notes)
      if (file) f.set('attachment', file)
      return portalApi.updateHomework(id, f)
    },
    onSuccess: (r) => {
      void qc.invalidateQueries({ queryKey: ['portal'] })
      onDone(r.message)
    },
  })
  return (
    <Dialog title="Chỉnh sửa / Cập nhật bài nộp" onClose={onClose}>
      <form
        onSubmit={(e) => {
          e.preventDefault()
          m.mutate()
        }}
        className="space-y-4"
      >
        {m.isError && <Alert>{errorMessage(m.error)}</Alert>}
        <label className="block space-y-1.5 text-sm">
          <span className="font-medium">Cập nhật lại tệp tin bài làm (Nếu muốn thay thế tệp cũ)</span>
          <input type="file" className="block w-full rounded-lg border border-outline-variant p-2 text-xs" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
          <span className="block text-xs text-on-surface-variant">Để trống nếu muốn giữ nguyên tệp tin đã tải lên trước đó.</span>
        </label>
        <TextArea label="Ghi chú gửi thầy cô" value={notes} onChange={setNotes} />
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            Hủy
          </Button>
          <Button type="submit" disabled={m.isPending}>
            Lưu cập nhật
          </Button>
        </div>
      </form>
    </Dialog>
  )
}
