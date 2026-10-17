// Giao bài tập về nhà (TeacherPortalController::homework*): buổi học, hạn nộp, ghi chú nhắc cả lớp, tài liệu tham khảo
// (YouTube, file nghe, Quizizz), hạng mục bài tập (≥ 1) kèm yêu cầu. Hạng mục đã có học sinh nộp bài thì khóa.
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { errorMessage } from '../../api'
import { FileUpload } from '../../components/FileUpload'
import { Alert, Badge, Button, Card, Field, Icon, Select } from '../../components/ui'
import { navigate, useQueryParam } from '../../router'
import { teacherApi, type HomeworkPage as Page } from './api'
import { ClassTabs, Empty, Header, LoadError, Loading, Textarea } from './components'
import { editHomeworkForm, emptyHomeworkForm, HOMEWORK_AUDIO_RULE, homeworkInput, toggleCategory, type HomeworkForm } from './logic'

export function HomeworkPage({ id }: { id: string }) {
  const edit = useQueryParam('edit')
  const session = useQueryParam('session')
  const [flash, setFlash] = useState('')
  const page = useQuery({
    queryKey: ['teacher-homework', id, edit, session],
    queryFn: () => teacherApi.homework(id, { edit: edit || undefined, session: session || undefined }),
  })
  if (page.isPending) return <Loading />
  if (page.isError) return <LoadError title="Không mở được trang giao bài" error={page.error} back={{ label: 'Về cổng giáo viên', path: '/teacher/home' }} />
  const p = page.data
  return (
    <>
      <Header
        title={`Giao bài tập · ${p.classroom.name}`}
        description={`${p.classroom.code} · ${p.classroom.branch_name}`}
        actions={
          <Button variant="secondary" onClick={() => navigate('/teacher/home')}>
            Về cổng giáo viên
          </Button>
        }
      />
      <ClassTabs classId={id} active="homework" sessionId={session || undefined} />
      <div className="space-y-4">
        {flash && <Alert tone="success">{flash}</Alert>}
        <HomeworkFormCard
          key={`${p.editing?.id ?? 'new'}-${p.selected_session_id ?? ''}`}
          id={id}
          page={p}
          onSaved={(msg) => {
            setFlash(msg)
            navigate(`/teacher/classes/${id}/homework`)
          }}
        />
        <HomeworkList id={id} page={p} onDeleted={setFlash} />
      </div>
    </>
  )
}

function HomeworkFormCard({ id, page, onSaved }: { id: string; page: Page; onSaved: (msg: string) => void }) {
  const qc = useQueryClient()
  const editing = page.editing
  const [form, setForm] = useState<HomeworkForm>(() =>
    editing ? editHomeworkForm(editing) : emptyHomeworkForm(page.selected_session_id, page.due_default),
  )
  const [audioName, setAudioName] = useState('')
  const locked = editing?.locked ?? []
  const set = (patch: Partial<HomeworkForm>) => setForm((f) => ({ ...f, ...patch }))
  const save = useMutation({
    mutationFn: () => teacherApi.saveHomework(id, editing?.id ?? null, homeworkInput(form)),
    onSuccess: (r) => {
      qc.invalidateQueries({ queryKey: ['teacher-homework', id] })
      onSaved(r.message)
    },
  })
  return (
    <Card title={editing ? 'Sửa bài tập về nhà' : 'Giao bài tập mới'}>
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault()
          save.mutate()
        }}
      >
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
          <Select label="Buổi học" value={form.session_id} onChange={(e) => set({ session_id: e.target.value })}>
            <option value="">Chọn buổi học</option>
            {page.sessions.map((s) => (
              <option key={s.value} value={s.value}>
                {s.label}
              </option>
            ))}
          </Select>
          <Field label="Hạn nộp" type="datetime-local" value={form.due_at} onChange={(e) => set({ due_at: e.target.value })} />
        </div>
        <Textarea
          label="Ghi chú nhắc nhở cả lớp"
          rows={2}
          maxLength={2000}
          value={form.class_note}
          onChange={(e) => set({ class_note: e.target.value })}
          placeholder="Ví dụ: Các con nhớ mang sách Workbook buổi sau…"
        />

        <fieldset className="space-y-3">
          <legend className="text-sm font-medium">Hạng mục bài tập</legend>
          <div className="flex flex-wrap gap-2">
            {page.categories.map((c) => {
              const on = form.categories.includes(c.key)
              const isLocked = locked.includes(c.key)
              return (
                <button
                  key={c.key}
                  type="button"
                  aria-pressed={on}
                  title={isLocked ? 'Đã có học sinh nộp bài hạng mục này' : undefined}
                  onClick={() => setForm((f) => toggleCategory(f, c.key, locked))}
                  className={`inline-flex items-center gap-1 rounded-full border px-3 py-1 text-xs font-medium ${
                    on ? 'border-primary bg-primary text-white' : 'border-outline-variant text-on-surface-variant hover:bg-surface-container-low'
                  }`}
                >
                  <Icon name={c.icon} className="text-base" />
                  {c.label}
                  {isLocked && <Icon name="lock" className="text-sm" />}
                </button>
              )
            })}
          </div>
          {page.categories
            .filter((c) => form.categories.includes(c.key))
            .map((c) => (
              <Textarea
                key={c.key}
                label={c.label}
                rows={2}
                maxLength={2000}
                placeholder={c.placeholder}
                value={form.items[c.key] ?? ''}
                onChange={(e) => setForm((f) => ({ ...f, items: { ...f.items, [c.key]: e.target.value } }))}
                hint={locked.includes(c.key) ? 'Đã có học sinh nộp bài — không bỏ được hạng mục này.' : undefined}
              />
            ))}
        </fieldset>

        <fieldset className="space-y-3">
          <legend className="text-sm font-medium">Tài liệu tham khảo (không bắt buộc)</legend>
          <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
            <Field
              label="Link YouTube"
              type="url"
              maxLength={500}
              value={form.youtube_url}
              onChange={(e) => set({ youtube_url: e.target.value })}
              placeholder="https://youtube.com/…"
            />
            <Field
              label="Link Quizizz"
              type="url"
              maxLength={500}
              value={form.quizizz_url}
              onChange={(e) => set({ quizizz_url: e.target.value })}
              placeholder="https://quizizz.com/…"
            />
          </div>
          <div className="flex flex-wrap items-center gap-3">
            <FileUpload
              rule={HOMEWORK_AUDIO_RULE}
              accept="audio/*"
              label={form.audio_file_id || editing?.has_audio ? 'Thay file nghe' : 'Tải file nghe'}
              icon="headphones"
              onUpload={async (file) => {
                const a = await teacherApi.uploadAudio(id, file)
                set({ audio_file_id: a.id })
                setAudioName(a.name)
              }}
            />
            {audioName ? (
              <span className="text-xs text-on-surface-variant">Đã tải: {audioName} (gắn khi lưu bài)</span>
            ) : (
              editing?.has_audio && <span className="text-xs text-on-surface-variant">Bài đã có file nghe.</span>
            )}
          </div>
        </fieldset>

        {save.isError && <Alert>{errorMessage(save.error)}</Alert>}
        <div className="flex flex-wrap justify-end gap-2">
          {editing && (
            <Button type="button" variant="secondary" onClick={() => navigate(`/teacher/classes/${id}/homework`)}>
              Hủy sửa
            </Button>
          )}
          <Button type="submit" disabled={save.isPending}>
            {save.isPending ? 'Đang lưu…' : editing ? 'Cập nhật bài tập' : 'Giao bài tập'}
          </Button>
        </div>
      </form>
    </Card>
  )
}

function HomeworkList({ id, page, onDeleted }: { id: string; page: Page; onDeleted: (msg: string) => void }) {
  const qc = useQueryClient()
  const del = useMutation({
    mutationFn: (hid: string) => teacherApi.deleteHomework(id, hid),
    onSuccess: (r) => {
      onDeleted(r.message)
      qc.invalidateQueries({ queryKey: ['teacher-homework', id] })
    },
  })
  return (
    <Card title={`Bài tập đã giao (${page.homeworks.length})`}>
      {del.isError && (
        <div className="mb-3">
          <Alert>{errorMessage(del.error)}</Alert>
        </div>
      )}
      {page.homeworks.length === 0 ? (
        <Empty>Lớp chưa có bài tập về nhà.</Empty>
      ) : (
        <ul className="divide-y divide-surface-container-highest">
          {page.homeworks.map((h) => (
            <li key={h.id} className="space-y-2 py-3 text-sm">
              <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0">
                  <p className="font-semibold">{h.title}</p>
                  <p className="text-xs text-on-surface-variant">
                    Hạn nộp: {h.due_label}
                    {h.teacher_name && ` · ${h.teacher_name}`}
                  </p>
                </div>
                <div className="flex gap-2">
                  <Button variant="secondary" className="h-8 px-3 text-xs" onClick={() => navigate(`/teacher/classes/${id}/homework?edit=${h.id}`)}>
                    Sửa
                  </Button>
                  {h.deletable && (
                    <Button
                      variant="danger"
                      className="h-8 px-3 text-xs"
                      disabled={del.isPending}
                      onClick={() => {
                        if (window.confirm('Xóa bài tập này?')) del.mutate(h.id)
                      }}
                    >
                      Xóa
                    </Button>
                  )}
                </div>
              </div>
              <ul className="space-y-1">
                {h.items.map((it) => (
                  <li key={it.key}>
                    <span className="font-medium">{it.label}:</span> <span className="whitespace-pre-line">{it.text}</span>{' '}
                    {it.locked && <Badge tone="info">Đã có bài nộp</Badge>}
                  </li>
                ))}
              </ul>
              {h.class_note && <p className="text-xs text-on-surface-variant">Ghi chú: {h.class_note}</p>}
              <div className="flex flex-wrap gap-3 text-xs">
                {h.youtube_url && (
                  <a href={h.youtube_url} target="_blank" rel="noopener noreferrer" className="text-primary hover:underline">
                    Video YouTube
                  </a>
                )}
                {h.quizizz_url && (
                  <a href={h.quizizz_url} target="_blank" rel="noopener noreferrer" className="text-primary hover:underline">
                    Quizizz
                  </a>
                )}
                {h.audio_url && <audio controls preload="none" src={h.audio_url} className="h-8 max-w-full" />}
              </div>
            </li>
          ))}
        </ul>
      )}
    </Card>
  )
}
