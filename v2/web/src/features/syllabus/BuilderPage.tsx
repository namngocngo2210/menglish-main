// Soạn syllabus theo chặng (SyllabusController::builder): Giáo trình → Chặng (Big Test cuối chặng) → Unit → Buổi.
// Ô soạn chặng / unit / buổi mở bằng hộp thoại; ?curriculum= chọn giáo trình, ?edit_lesson= mở sẵn buổi (từ màn đề xuất).
import { useQuery } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { errorMessage } from '../../api'
import { Alert, Badge, Button, Card, Dialog, Field, Icon, Select } from '../../components/ui'
import { navigate, useQueryParam } from '../../router'
import { syllabusApi, type BuilderPage as Page, type Lesson, type Stage, type Unit } from './api'
import { confirmThen, Empty, ErrorText, LinkButton, NoticeBar, PageHeader, TextArea, useAction } from './components'
import { isImageUrl, lessonCount, pad2 } from './logic'

type Editor =
  | { type: 'stage'; model: Stage | null }
  | { type: 'unit'; model: Unit | null; parent: Stage | null }
  | { type: 'lesson'; model: Lesson | null; parent: Unit | null }

const opt = (s: string) => (s.trim() ? s.trim() : null)

export function BuilderPage() {
  const curriculum = useQueryParam('curriculum')
  const editLesson = useQueryParam('edit_lesson')
  const page = useQuery({
    queryKey: ['syllabus', 'builder', curriculum],
    queryFn: () => syllabusApi.builder(curriculum || undefined),
  })
  const [creating, setCreating] = useState(false)
  const [editor, setEditor] = useState<Editor | null>(null)
  const act = useAction(async (fn: () => Promise<{ message: string }>) => fn())
  const d = page.data

  // Mở sẵn buổi cần sửa khi vào từ đề xuất đã duyệt.
  useEffect(() => {
    if (!d || !editLesson) return
    for (const s of d.stages)
      for (const u of s.units) for (const l of u.lessons) if (l.id === editLesson) setEditor({ type: 'lesson', model: l, parent: u })
  }, [d, editLesson])

  return (
    <>
      <PageHeader
        title="Soạn syllabus theo chặng"
        back="/syllabus/documents"
        description="Thiết lập cấu trúc chương trình học và nội dung chi tiết từng buổi. Giáo trình → Chặng (Big Test cuối chặng) → Unit → Buổi. Số buổi đánh liên tục trong cả giáo trình."
        actions={
          <>
            <LinkButton href="/syllabus/assignments" icon="assignment_ind">
              Giao chặng
            </LinkButton>
            {d?.can_manage && (
              <Button className="gap-1.5" onClick={() => setCreating(true)}>
                <Icon name="library_add" className="text-[18px]" />
                Tạo giáo trình mới
              </Button>
            )}
          </>
        }
      />
      <NoticeBar notice={act.notice} onClose={() => act.setNotice(null)} />
      <ErrorText error={act.error} />
      {page.isError && <Alert>{errorMessage(page.error)}</Alert>}
      {d && (
        <div className="space-y-6">
          <Card>
            <Select
              label="Giáo trình đang soạn"
              value={d.curriculum?.id ?? ''}
              onChange={(e) => navigate(`/syllabus/builder?curriculum=${e.target.value}`)}
            >
              {!d.curriculums.length && <option value="">Chưa có giáo trình</option>}
              {d.curriculums.map((c) => (
                <option key={c.value} value={c.value}>
                  {c.label}
                </option>
              ))}
            </Select>
          </Card>
          {!d.curriculum ? (
            <Empty icon="library_books" title="Chưa có giáo trình nào" hint="Tạo giáo trình đầu tiên để bắt đầu soạn chặng, unit và buổi học.">
              {d.can_manage && <Button onClick={() => setCreating(true)}>Tạo giáo trình mới</Button>}
            </Empty>
          ) : (
            <>
              <CurriculumInfo key={d.curriculum.id} page={d} onDelete={() => act.run(() => syllabusApi.deleteCurriculum(d.curriculum!.id))} />
              <section className="space-y-4">
                <div className="flex items-center justify-between gap-2">
                  <h2 className="flex items-center gap-2 font-semibold">
                    <Icon name="account_tree" className="text-primary" />
                    Chặng học của giáo trình
                  </h2>
                  {d.can_manage && (
                    <Button
                      variant="secondary"
                      className="h-9"
                      onClick={() =>
                        setEditor({
                          type: 'stage',
                          model: null,
                        })
                      }
                    >
                      Thêm chặng
                    </Button>
                  )}
                </div>
                <Alert tone="info">
                  Mỗi lớp chỉ học 1 chặng tại một thời điểm. Khi Big Test của chặng được duyệt và gửi kết quả cho phụ huynh, chặng đóng và chặng kế
                  tiếp (theo thứ tự dưới đây) tự mở.
                </Alert>
                {d.stages.map((stage, index) => (
                  <StageCard
                    key={stage.id}
                    stage={stage}
                    first={index === 0}
                    last={index === d.stages.length - 1}
                    canManage={d.can_manage}
                    onEdit={setEditor}
                    onAction={(fn) => act.run(fn)}
                  />
                ))}
              </section>
            </>
          )}
          <div className="flex justify-end gap-2">
            <LinkButton href="/syllabus/documents">Quay lại</LinkButton>
            <LinkButton href="/syllabus/assignments" variant="primary" icon="arrow_forward">
              Tiếp tục: Chặng của lớp
            </LinkButton>
          </div>
        </div>
      )}
      {creating && d && (
        <NewCurriculumDialog
          page={d}
          onClose={() => setCreating(false)}
          onDone={(m, id) => {
            act.setNotice({ text: m, tone: 'success' })
            setCreating(false)
            if (id) navigate(`/syllabus/builder?curriculum=${id}`)
          }}
        />
      )}
      {editor && d?.curriculum && (
        <EditorDialog
          editor={editor}
          page={d}
          onClose={() => {
            setEditor(null)
            if (editLesson) navigate(`/syllabus/builder?curriculum=${d.curriculum!.id}`)
          }}
          onDone={(m) => {
            act.setNotice({ text: m, tone: 'success' })
            setEditor(null)
          }}
        />
      )}
    </>
  )
}

function CurriculumInfo({ page, onDelete }: { page: Page; onDelete: () => void }) {
  const c = page.curriculum!
  const [f, setF] = useState({
    title: c.title,
    code: c.code,
    version: c.version,
    course_id: c.course_id ?? '',
    description: c.description ?? '',
    level_ids: c.level_ids,
  })
  const save = useAction(() =>
    syllabusApi.updateCurriculum(c.id, {
      ...f,
      course_id: f.course_id || null,
      description: opt(f.description),
      level_ids: f.level_ids,
    }),
  )
  const off = !page.can_manage
  return (
    <Card
      title={
        <span className="flex flex-wrap items-center justify-between gap-2">
          <span className="flex items-center gap-2">
            <Icon name="info" className="text-primary" />
            Thông tin giáo trình
            <Badge tone="info">
              {page.stages.length} chặng · {page.totals.units} unit · {page.totals.lessons} buổi
            </Badge>
          </span>
          {page.can_manage && (
            <button
              type="button"
              className="text-sm font-medium text-error hover:underline"
              onClick={() => confirmThen(`Xóa giáo trình ${c.title} cùng toàn bộ chặng, unit, buổi học và tài liệu?`, onDelete)}
            >
              Xóa giáo trình
            </button>
          )}
        </span>
      }
    >
      <NoticeBar notice={save.notice} onClose={() => save.setNotice(null)} />
      <form
        className="grid grid-cols-1 gap-4 md:grid-cols-2"
        onSubmit={(e) => {
          e.preventDefault()
          save.run()
        }}
      >
        <Field label="Tên giáo trình" required disabled={off} value={f.title} onChange={(e) => setF({ ...f, title: e.target.value })} />
        <Field label="Mã giáo trình" required disabled={off} value={f.code} onChange={(e) => setF({ ...f, code: e.target.value })} />
        <Field label="Phiên bản" required disabled={off} value={f.version} onChange={(e) => setF({ ...f, version: e.target.value })} />
        <Select label="Khóa học áp dụng" disabled={off} value={f.course_id} onChange={(e) => setF({ ...f, course_id: e.target.value })}>
          <option value="">-- Không gắn khóa --</option>
          {page.courses.map((o) => (
            <option key={o.value} value={o.value}>
              {o.label}
            </option>
          ))}
        </Select>
        <div className="space-y-1.5 md:col-span-2">
          <p className="text-sm font-medium">Trình độ áp dụng</p>
          <div className="flex flex-wrap gap-2">
            {page.levels.map((l) => (
              <label
                key={l.id}
                className={`inline-flex items-center gap-1.5 rounded-lg border border-outline-variant px-2.5 py-1.5 text-xs ${l.other ? 'text-on-surface-variant' : ''}`}
                title={l.other ? 'Đang gắn giáo trình khác — chọn sẽ chuyển sang giáo trình này' : undefined}
              >
                <input
                  type="checkbox"
                  className="accent-primary"
                  disabled={off}
                  checked={f.level_ids.includes(l.id)}
                  onChange={(e) =>
                    setF({
                      ...f,
                      level_ids: e.target.checked ? [...f.level_ids, l.id] : f.level_ids.filter((x) => x !== l.id),
                    })
                  }
                />
                {l.name} <span className="font-mono">{l.code}</span>
                {l.other && <span>(đang dùng GT khác)</span>}
              </label>
            ))}
            {!page.levels.length && <span className="text-xs text-on-surface-variant">Chưa có trình độ nào — tạo ở màn Cấu hình trình độ.</span>}
          </div>
          <p className="text-xs text-on-surface-variant">Lớp thuộc trình độ được chọn sẽ mặc định học giáo trình này khi mở chặng.</p>
        </div>
        <div className="md:col-span-2">
          <TextArea label="Mô tả" rows={2} disabled={off} value={f.description} onChange={(e) => setF({ ...f, description: e.target.value })} />
        </div>
        <div className="md:col-span-2">
          <ErrorText error={save.error} />
        </div>
        {page.can_manage && (
          <div className="flex justify-end md:col-span-2">
            <Button type="submit" disabled={save.pending}>
              Lưu thông tin giáo trình
            </Button>
          </div>
        )}
      </form>
    </Card>
  )
}

function StageCard({
  stage,
  first,
  last,
  canManage,
  onEdit,
  onAction,
}: {
  stage: Stage
  first: boolean
  last: boolean
  canManage: boolean
  onEdit: (e: Editor) => void
  onAction: (fn: () => Promise<{ message: string }>) => void
}) {
  const n = lessonCount(stage)
  const lastUnit = stage.units[stage.units.length - 1]
  return (
    <article className="overflow-hidden rounded-xl border border-surface-container-highest bg-surface shadow-sm">
      <header className="flex flex-col gap-3 border-b border-surface-container-highest bg-surface-container-low p-4 md:flex-row md:items-start md:justify-between">
        <div className="flex min-w-0 items-start gap-3">
          <span className="flex size-9 shrink-0 items-center justify-center rounded-xl bg-primary-light text-sm font-bold text-primary">
            {stage.position}
          </span>
          <div className="min-w-0 text-xs text-on-surface-variant">
            <h3 className="flex flex-wrap items-center gap-2 text-base font-semibold text-on-surface">
              {stage.label}
              <Badge tone="info">Tổng số: {pad2(n)} buổi</Badge>
            </h3>
            <p>
              {stage.units.length} unit · {n} buổi
              {stage.open_classes && ` · Đang học: ${stage.open_classes}`}
            </p>
            {stage.description && <p className="mt-1 whitespace-pre-line">{stage.description}</p>}
            <p className="mt-1.5 flex flex-wrap items-center gap-2">
              <span className="inline-flex items-center gap-1 font-semibold text-on-surface">
                <Icon name="quiz" className="text-[14px]" />
                {stage.big_test_title || 'Big Test cuối chặng (chưa đặt tên)'}
              </span>
              {stage.big_test_note && <span>— {stage.big_test_note}</span>}
              {stage.overview_link && (
                <a href={stage.overview_link} target="_blank" rel="noopener noreferrer" className="font-semibold text-primary hover:underline">
                  Tổng quan chặng
                </a>
              )}
            </p>
          </div>
        </div>
        {canManage && (
          <div className="flex shrink-0 flex-wrap items-center gap-1 text-sm">
            {!first && <IconButton icon="arrow_upward" title="Chuyển lên" onClick={() => onAction(() => syllabusApi.moveStage(stage.id, 'up'))} />}
            {!last && (
              <IconButton icon="arrow_downward" title="Chuyển xuống" onClick={() => onAction(() => syllabusApi.moveStage(stage.id, 'down'))} />
            )}
            <TextButton onClick={() => onEdit({ type: 'stage', model: stage })}>Sửa</TextButton>
            <TextButton
              onClick={() =>
                onEdit({
                  type: 'unit',
                  model: null,
                  parent: stage,
                })
              }
            >
              + Unit
            </TextButton>
            <IconButton
              icon="delete"
              title="Xóa chặng"
              danger
              onClick={() => confirmThen(`Xóa ${stage.label}?`, () => onAction(() => syllabusApi.deleteStage(stage.id)))}
            />
          </div>
        )}
      </header>
      <div className="space-y-3 p-4">
        {stage.units.map((u) => (
          <div key={u.id} className="rounded-xl border border-surface-container-highest">
            <div className="flex items-center justify-between gap-3 px-4 py-3">
              <div className="min-w-0">
                <p className="text-sm font-semibold">
                  Unit {u.unit_number}: {u.title}
                </p>
                {u.objectives && <p className="truncate text-xs text-on-surface-variant">{u.objectives}</p>}
              </div>
              {canManage && (
                <div className="flex shrink-0 items-center gap-1 text-sm">
                  <TextButton
                    onClick={() =>
                      onEdit({
                        type: 'lesson',
                        model: null,
                        parent: u,
                      })
                    }
                  >
                    + Buổi
                  </TextButton>
                  <TextButton
                    onClick={() =>
                      onEdit({
                        type: 'unit',
                        model: u,
                        parent: stage,
                      })
                    }
                  >
                    Sửa
                  </TextButton>
                  <IconButton
                    icon="delete"
                    title="Xóa unit"
                    danger
                    onClick={() =>
                      confirmThen(`Xóa Unit ${u.unit_number} cùng ${u.lessons.length} buổi học?`, () => onAction(() => syllabusApi.deleteUnit(u.id)))
                    }
                  />
                </div>
              )}
            </div>
            <div className="divide-y divide-surface-container-highest border-t border-surface-container-highest">
              {u.lessons.map((l) => (
                <details key={l.id} className="group px-4 py-2.5 text-xs">
                  <summary className="flex cursor-pointer list-none items-center justify-between gap-2">
                    <span className="flex min-w-0 items-center gap-2">
                      <span className="flex size-7 shrink-0 items-center justify-center rounded-lg bg-primary-light font-bold text-primary">
                        {l.session_no}
                      </span>
                      <span className="truncate font-semibold">
                        Buổi {l.session_no}: {l.title}
                      </span>
                    </span>
                    <Icon name="expand_more" className="text-[18px] transition group-open:rotate-180" />
                  </summary>
                  <div className="mt-3 grid grid-cols-1 gap-3 text-on-surface-variant md:grid-cols-2">
                    {LESSON_FIELDS.map(([k, label, empty]) => (
                      <p key={k} className="whitespace-pre-line">
                        <span className="font-semibold">{label}</span> {l[k] || empty}
                      </p>
                    ))}
                  </div>
                  {canManage && (
                    <div className="mt-2 flex justify-end gap-2 text-sm">
                      <TextButton
                        onClick={() =>
                          onEdit({
                            type: 'lesson',
                            model: l,
                            parent: u,
                          })
                        }
                      >
                        Sửa buổi
                      </TextButton>
                      <TextButton
                        danger
                        onClick={() => confirmThen(`Xóa Buổi ${l.session_no}?`, () => onAction(() => syllabusApi.deleteLesson(l.id)))}
                      >
                        Xóa
                      </TextButton>
                    </div>
                  )}
                </details>
              ))}
              {!u.lessons.length && <p className="px-4 py-3 text-xs text-on-surface-variant">Unit chưa có buổi học nào.</p>}
            </div>
          </div>
        ))}
        {!stage.units.length && <p className="py-6 text-center text-xs text-on-surface-variant">Chặng chưa có unit nào.</p>}
        {canManage && (
          <button
            type="button"
            onClick={() =>
              onEdit(
                lastUnit
                  ? {
                      type: 'lesson',
                      model: null,
                      parent: lastUnit,
                    }
                  : {
                      type: 'unit',
                      model: null,
                      parent: stage,
                    },
              )
            }
            className="flex w-full flex-col items-center gap-1 rounded-xl border-2 border-dashed border-outline-variant py-4 text-sm text-on-surface-variant hover:border-primary hover:text-primary"
          >
            <Icon name="add" />
            {lastUnit ? 'Thêm buổi học mới vào chặng' : 'Thêm Unit đầu tiên cho chặng'}
          </button>
        )}
      </div>
    </article>
  )
}

const LESSON_FIELDS: [keyof Lesson & ('objectives' | 'content' | 'homework_guide' | 'vocabulary_focus' | 'grammar_focus'), string, string][] = [
  ['objectives', 'Mục tiêu:', 'Chưa cập nhật'],
  ['content', 'Nội dung chính:', 'Chưa cập nhật'],
  ['homework_guide', 'Bài tập về nhà:', 'Chưa cập nhật'],
  ['vocabulary_focus', 'Từ vựng:', '—'],
  ['grammar_focus', 'Ngữ pháp:', '—'],
]

function IconButton({ icon, title, danger, onClick }: { icon: string; title: string; danger?: boolean; onClick: () => void }) {
  return (
    <button
      type="button"
      title={title}
      aria-label={title}
      onClick={onClick}
      className={`rounded p-1 hover:bg-surface-container-low ${danger ? 'text-error' : ''}`}
    >
      <Icon name={icon} className="text-[18px]" />
    </button>
  )
}

function TextButton({ danger, onClick, children }: { danger?: boolean; onClick: () => void; children: string }) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={`rounded px-2 py-1 font-medium hover:bg-surface-container-low ${danger ? 'text-error' : 'text-primary'}`}
    >
      {children}
    </button>
  )
}

function NewCurriculumDialog({ page, onClose, onDone }: { page: Page; onClose: () => void; onDone: (m: string, id?: string) => void }) {
  const [f, setF] = useState({
    code: page.new_code,
    version: 'v1.0',
    title: '',
    course_id: '',
    stage_name: '',
  })
  const save = useAction(
    () =>
      syllabusApi.createCurriculum({
        ...f,
        course_id: f.course_id || null,
        stage_name: opt(f.stage_name),
      }),
    (m) => onDone(m.message, m.id),
  )
  return (
    <Dialog title="Tạo giáo trình mới" onClose={onClose} wide>
      <form
        className="grid grid-cols-1 gap-4 md:grid-cols-2"
        onSubmit={(e) => {
          e.preventDefault()
          save.run()
        }}
      >
        <Field label="Mã giáo trình" required value={f.code} onChange={(e) => setF({ ...f, code: e.target.value })} />
        <Field label="Phiên bản" required value={f.version} onChange={(e) => setF({ ...f, version: e.target.value })} />
        <div className="md:col-span-2">
          <Field
            label="Tên giáo trình"
            required
            placeholder="IELTS Foundation - Level 1"
            value={f.title}
            onChange={(e) => setF({ ...f, title: e.target.value })}
          />
        </div>
        <Select label="Khóa học áp dụng" value={f.course_id} onChange={(e) => setF({ ...f, course_id: e.target.value })}>
          <option value="">-- Chọn khóa học --</option>
          {page.courses.map((o) => (
            <option key={o.value} value={o.value}>
              {o.label}
            </option>
          ))}
        </Select>
        <Field
          label="Tên chặng đầu tiên"
          placeholder="Chặng 1: Xây dựng nền tảng"
          hint="Để trống sẽ đặt là “Chặng 1”."
          value={f.stage_name}
          onChange={(e) => setF({ ...f, stage_name: e.target.value })}
        />
        <div className="md:col-span-2">
          <ErrorText error={save.error} />
        </div>
        <div className="flex justify-end gap-2 md:col-span-2">
          <Button variant="secondary" onClick={onClose}>
            Hủy
          </Button>
          <Button type="submit" disabled={save.pending}>
            Tạo giáo trình
          </Button>
        </div>
      </form>
    </Dialog>
  )
}

function EditorDialog({ editor, page, onClose, onDone }: { editor: Editor; page: Page; onClose: () => void; onDone: (m: string) => void }) {
  const cur = page.curriculum!.id
  const title =
    editor.type === 'stage'
      ? editor.model
        ? `Sửa ${editor.model.label}`
        : `Thêm chặng mới (Chặng ${page.totals.next_position})`
      : editor.type === 'unit'
        ? editor.model
          ? `Sửa Unit ${editor.model.unit_number}: ${editor.model.title}`
          : `Thêm Unit vào ${editor.parent?.label ?? ''}`
        : editor.model
          ? `Sửa Buổi ${editor.model.session_no}: ${editor.model.title}`
          : `Thêm buổi vào Unit ${editor.parent?.unit_number ?? ''}: ${editor.parent?.title ?? ''}`
  return (
    <Dialog title={title} onClose={onClose} wide>
      {editor.type === 'stage' && <StageForm cur={cur} model={editor.model} onClose={onClose} onDone={onDone} />}
      {editor.type === 'unit' && <UnitForm cur={cur} page={page} model={editor.model} parent={editor.parent} onClose={onClose} onDone={onDone} />}
      {editor.type === 'lesson' && <LessonForm page={page} model={editor.model} parent={editor.parent} onClose={onClose} onDone={onDone} />}
    </Dialog>
  )
}

type FormProps = { onClose: () => void; onDone: (m: string) => void }

function Actions({ onClose, pending, label, error }: { onClose: () => void; pending: boolean; label: string; error: string }) {
  return (
    <div className="space-y-3 md:col-span-2">
      <ErrorText error={error} />
      <div className="flex justify-end gap-2 border-t border-surface-container-highest pt-3">
        <Button variant="secondary" onClick={onClose}>
          Hủy
        </Button>
        <Button type="submit" disabled={pending}>
          {label}
        </Button>
      </div>
    </div>
  )
}

function StageForm({ cur, model, onClose, onDone }: FormProps & { cur: string; model: Stage | null }) {
  const [f, setF] = useState({
    name: model?.name ?? '',
    description: model?.description ?? '',
    overview_link: model?.overview_link ?? '',
    big_test_title: model?.big_test_title ?? '',
    big_test_note: model?.big_test_note ?? '',
  })
  const [preview, setPreview] = useState(f.overview_link)
  const input = () => ({
    curriculum_id: cur,
    name: f.name,
    description: opt(f.description),
    overview_link: opt(f.overview_link),
    big_test_title: opt(f.big_test_title),
    big_test_note: opt(f.big_test_note),
  })
  const save = useAction(
    () => (model ? syllabusApi.updateStage(model.id, input()) : syllabusApi.createStage(input())),
    (m) => onDone(m.message),
  )
  return (
    <form
      className="grid grid-cols-1 gap-4 md:grid-cols-2"
      onSubmit={(e) => {
        e.preventDefault()
        save.run()
      }}
    >
      <Field
        label="Tên chặng học"
        required
        placeholder="Ví dụ: Chặng 1: Xây dựng nền tảng"
        value={f.name}
        onChange={(e) => setF({ ...f, name: e.target.value })}
      />
      <Select label="Chính sách mở khóa" disabled value="bt">
        <option value="bt">Hoàn thành Big Test chặng trước (duyệt &amp; gửi PH) mới được mở</option>
      </Select>
      <div className="space-y-2 md:col-span-2">
        <div className="flex items-end gap-2">
          <div className="flex-1">
            <Field
              label="Link ảnh/tài liệu tổng quan chặng (overview_link)"
              type="url"
              placeholder="https://example.com/image-syllabus.jpg"
              value={f.overview_link}
              onChange={(e) => setF({ ...f, overview_link: e.target.value })}
            />
          </div>
          <Button variant="secondary" onClick={() => setPreview(f.overview_link)}>
            Xem thử
          </Button>
        </div>
        <div className="relative flex h-48 items-center justify-center overflow-hidden rounded-xl border-2 border-dashed border-outline-variant bg-surface-container-low text-sm text-on-surface-variant">
          {preview && isImageUrl(preview) ? (
            <img src={preview} alt="Ảnh tổng quan chặng" className="absolute inset-0 size-full object-contain" />
          ) : preview ? (
            <a href={preview} target="_blank" rel="noopener noreferrer" className="text-primary hover:underline">
              Mở tài liệu tổng quan chặng
            </a>
          ) : (
            <span>Nhập link bên trên để hiển thị hình ảnh mục lục tổng quan</span>
          )}
        </div>
      </div>
      <div className="md:col-span-2">
        <TextArea label="Mục tiêu / đầu ra của chặng" rows={2} value={f.description} onChange={(e) => setF({ ...f, description: e.target.value })} />
      </div>
      <Field
        label="Big Test cuối chặng"
        placeholder="Big Test chặng 2 — 4 kỹ năng"
        value={f.big_test_title}
        onChange={(e) => setF({ ...f, big_test_title: e.target.value })}
      />
      <TextArea
        label="Ghi chú Big Test (dạng đề, thời lượng, đầu ra)"
        rows={2}
        value={f.big_test_note}
        onChange={(e) => setF({ ...f, big_test_note: e.target.value })}
      />
      <Actions onClose={onClose} pending={save.pending} error={save.error} label={model ? 'Lưu chặng học' : 'Thêm chặng học'} />
    </form>
  )
}

function UnitForm({
  cur,
  page,
  model,
  parent,
  onClose,
  onDone,
}: FormProps & {
  cur: string
  page: Page
  model: Unit | null
  parent: Stage | null
}) {
  const [f, setF] = useState({
    unit_number: String(model?.unit_number ?? page.totals.next_unit_number),
    title: model?.title ?? '',
    stage_id: model?.stage_id ?? parent?.id ?? '',
    objectives: model?.objectives ?? '',
  })
  const input = () => ({
    curriculum_id: cur,
    stage_id: f.stage_id || null,
    unit_number: Number(f.unit_number),
    title: f.title,
    objectives: opt(f.objectives),
  })
  const save = useAction(
    () => (model ? syllabusApi.updateUnit(model.id, input()) : syllabusApi.createUnit(input())),
    (m) => onDone(m.message),
  )
  return (
    <form
      className="grid grid-cols-1 gap-4 md:grid-cols-2"
      onSubmit={(e) => {
        e.preventDefault()
        save.run()
      }}
    >
      <Field label="Unit số" type="number" min={1} required value={f.unit_number} onChange={(e) => setF({ ...f, unit_number: e.target.value })} />
      <Field
        label="Tên Unit"
        required
        placeholder="Unit 3: Environment & Climate Change"
        value={f.title}
        onChange={(e) => setF({ ...f, title: e.target.value })}
      />
      <div className="md:col-span-2">
        <Select label="Thuộc chặng" required value={f.stage_id} onChange={(e) => setF({ ...f, stage_id: e.target.value })}>
          <option value="">-- Chọn chặng --</option>
          {page.stages.map((s) => (
            <option key={s.id} value={s.id}>
              {s.label}
            </option>
          ))}
        </Select>
      </div>
      <div className="md:col-span-2">
        <TextArea label="Mô tả / mục tiêu Unit" rows={2} value={f.objectives} onChange={(e) => setF({ ...f, objectives: e.target.value })} />
      </div>
      <Actions onClose={onClose} pending={save.pending} error={save.error} label={model ? 'Lưu Unit' : 'Thêm Unit'} />
    </form>
  )
}

function LessonForm({ page, model, parent, onClose, onDone }: FormProps & { page: Page; model: Lesson | null; parent: Unit | null }) {
  const [f, setF] = useState({
    session_no: String(model?.session_no ?? page.totals.next_session_no),
    title: model?.title ?? '',
    unit_id: model?.unit_id ?? parent?.id ?? '',
    objectives: model?.objectives ?? '',
    content: model?.content ?? '',
    homework_guide: model?.homework_guide ?? '',
    vocabulary_focus: model?.vocabulary_focus ?? '',
    grammar_focus: model?.grammar_focus ?? '',
  })
  const input = () => ({
    unit_id: f.unit_id || null,
    session_no: Number(f.session_no),
    title: f.title,
    objectives: opt(f.objectives),
    content: opt(f.content),
    homework_guide: opt(f.homework_guide),
    vocabulary_focus: opt(f.vocabulary_focus),
    grammar_focus: opt(f.grammar_focus),
  })
  const save = useAction(
    () => (model ? syllabusApi.updateLesson(model.id, input()) : syllabusApi.createLesson(input())),
    (m) => onDone(m.message),
  )
  const text = (k: keyof typeof f, label: string, rows: number, placeholder?: string) => (
    <TextArea label={label} rows={rows} placeholder={placeholder} value={f[k]} onChange={(e) => setF({ ...f, [k]: e.target.value })} />
  )
  return (
    <form
      className="grid grid-cols-1 gap-4 md:grid-cols-2"
      onSubmit={(e) => {
        e.preventDefault()
        save.run()
      }}
    >
      <Field
        label="Buổi số"
        type="number"
        min={1}
        required
        hint="Đánh liên tục trong cả giáo trình"
        value={f.session_no}
        onChange={(e) => setF({ ...f, session_no: e.target.value })}
      />
      <Field
        label="Tiêu đề buổi học"
        required
        placeholder="Buổi 01: Introduction to IELTS & Greetings"
        value={f.title}
        onChange={(e) => setF({ ...f, title: e.target.value })}
      />
      <div className="md:col-span-2">
        <Select label="Thuộc Unit" required value={f.unit_id} onChange={(e) => setF({ ...f, unit_id: e.target.value })}>
          <option value="">-- Chọn Unit --</option>
          {page.unit_options.map((o) => (
            <option key={o.value} value={o.value}>
              {o.label}
            </option>
          ))}
        </Select>
      </div>
      <div className="md:col-span-2">{text('objectives', 'Mục tiêu buổi học (Target)', 3, 'Người học cần đạt được điều gì sau buổi này...')}</div>
      {text('content', 'Nội dung bài học chính', 6, 'Nhập nội dung chi tiết (hoạt động trên lớp)...')}
      {text('homework_guide', 'Bài tập về nhà (Homework)', 6, 'Ghi chú bài tập hoặc link bài tập...')}
      {text('vocabulary_focus', 'Trọng tâm từ vựng', 2)}
      {text('grammar_focus', 'Trọng tâm ngữ pháp', 2)}
      <Actions onClose={onClose} pending={save.pending} error={save.error} label={model ? 'Lưu buổi học' : 'Thêm buổi học vào chặng'} />
    </form>
  )
}
