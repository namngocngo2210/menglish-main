// Xem tài liệu giáo trình (SyllabusController::teacherView): tab Tài liệu / Tổng quan syllabus / Nội dung buổi học.
// ?document= mở sẵn hộp thoại xem tài liệu; ?class= chọn lớp; ?tab= chọn tab.
import { useQuery } from '@tanstack/react-query'
import { useRef, useState } from 'react'
import { errorMessage, type Me } from '../../api'
import { Alert, Badge, Button, Dialog, Field, Icon, Select } from '../../components/ui'
import { useQueryParam } from '../../router'
import { syllabusApi, type Lesson, type TeacherViewPage as Page, type Unit, type ViewStage } from './api'
import { Empty, EmptyRow, ErrorText, LinkButton, PageHeader, setParam, TableCard, tableCls, tbodyCls, td, Tabs, th, useAction } from './components'

type Tab = 'docs' | 'overview' | 'lessons'
const TABS: { value: Tab; label: string }[] = [
  { value: 'docs', label: 'Tài liệu' },
  { value: 'overview', label: 'Tổng quan syllabus' },
  { value: 'lessons', label: 'Nội dung buổi học' },
]
const STATE_LABEL: Record<ViewStage['state'], string> = {
  open: 'Đang học',
  done: 'Đã xong',
  todo: 'Chưa mở',
}
const STATE_TONE: Record<ViewStage['state'], 'info' | 'success' | 'neutral'> = {
  open: 'info',
  done: 'success',
  todo: 'neutral',
}
const STATE_ICON: Record<ViewStage['state'], string> = {
  open: 'play_circle',
  done: 'check_circle',
  todo: 'lock',
}
const LESSON_FIELDS: [keyof Lesson, string][] = [
  ['objectives', 'Mục tiêu'],
  ['vocabulary_focus', 'Từ vựng'],
  ['grammar_focus', 'Ngữ pháp'],
  ['content', 'Hoạt động'],
  ['homework_guide', 'Bài tập về nhà'],
]

export function TeacherViewPage({ me }: { me: Me }) {
  const cls = useQueryParam('class')
  const doc = useQueryParam('document')
  const tabParam = useQueryParam('tab')
  const [search, setSearch] = useState('')
  const [applied, setApplied] = useState('')
  const page = useQuery({
    queryKey: ['syllabus', 'teacher-view', cls, doc, applied],
    queryFn: () =>
      syllabusApi.teacherView({
        class: cls,
        document: doc,
        search: applied,
        tab: tabParam,
      }),
  })
  const [tab, setTab] = useState<Tab | null>(null)
  const [lesson, setLesson] = useState<{ l: Lesson; u: Unit } | null>(null)
  const d = page.data
  const current = tab ?? ((tabParam || d?.initial_tab || 'docs') as Tab)

  return (
    <>
      <PageHeader
        title="Xem tài liệu giáo trình"
        back="/syllabus/documents"
        description={d?.overview_curriculum ? `Giáo trình & Tài liệu › ${d.overview_curriculum}` : 'Giáo trình & Tài liệu'}
        actions={
          <>
            <form
              className="flex items-end gap-2"
              onSubmit={(e) => {
                e.preventDefault()
                setApplied(search)
              }}
            >
              <Field label="Tìm kiếm" placeholder="Tìm kiếm tài liệu..." value={search} onChange={(e) => setSearch(e.target.value)} />
              <Button type="submit" variant="secondary">
                Lọc
              </Button>
            </form>
            <LinkButton href="/syllabus/teacher-propose" icon="edit_attributes">
              Đề xuất sửa
            </LinkButton>
          </>
        }
      />
      {page.isError && <Alert>{errorMessage(page.error)}</Alert>}
      {d && (
        <div className="space-y-6">
          <Tabs value={current} onChange={setTab} items={TABS} />
          {current === 'docs' && <DocsTab d={d} />}
          {current === 'overview' && <OverviewTab d={d} onLessons={() => setTab('lessons')} />}
          {current === 'lessons' && <LessonsTab d={d} onLesson={(l, u) => setLesson({ l, u })} />}
        </div>
      )}
      {d?.selected && <Viewer d={d} me={me} />}
      {lesson && (
        <Dialog title={`Buổi ${lesson.l.session_no}: ${lesson.l.title}`} onClose={() => setLesson(null)} wide>
          <p className="mb-3 text-xs text-on-surface-variant">
            Unit {lesson.u.unit_number}: {lesson.u.title}
          </p>
          <dl className="space-y-3 text-sm">
            {LESSON_FIELDS.map(([k, label]) => (
              <div key={k}>
                <dt className="text-xs font-medium text-on-surface-variant uppercase">{label}:</dt>
                <dd className="mt-1 whitespace-pre-line">{(lesson.l[k] as string | null) || '—'}</dd>
              </div>
            ))}
          </dl>
        </Dialog>
      )}
    </>
  )
}

function DocsTab({ d }: { d: Page }) {
  return (
    <TableCard
      header={
        <h2 className="flex items-center gap-2 font-semibold">
          Danh sách tài liệu <Badge tone="info">{d.documents.length} tài liệu</Badge>
        </h2>
      }
    >
      <table className={`${tableCls} min-w-[760px]`}>
        <thead>
          <tr>
            <th className={th}>Tài liệu</th>
            <th className={th}>Giáo trình · Chặng</th>
            <th className={th}>Định dạng</th>
            <th className={th}>Quyền</th>
            <th className={th}>Đã xem</th>
            <th className={th}>
              <span className="sr-only">Thao tác</span>
            </th>
          </tr>
        </thead>
        <tbody className={tbodyCls}>
          {d.documents.map((doc) => (
            <tr
              key={doc.id}
              className={`cursor-pointer hover:bg-surface-container-low ${d.selected?.id === doc.id ? 'bg-primary-light' : ''}`}
              onClick={() => setParam('document', doc.id)}
            >
              <td className={td}>
                <span className="flex items-center gap-2 font-semibold">
                  <Icon name={doc.icon} className="text-[20px] text-primary" />
                  <span className="truncate">{doc.title}</span>
                </span>
              </td>
              <td className={`${td} text-on-surface-variant`}>{[doc.curriculum, doc.stage_label].filter(Boolean).join(' · ') || '—'}</td>
              <td className={`${td} font-mono text-xs whitespace-nowrap uppercase`}>
                {doc.extension} · {doc.size_human}
              </td>
              <td className={`${td} whitespace-nowrap`}>
                {doc.can_download ? <Badge tone="success">Có thể tải</Badge> : <Badge tone="error">Chỉ xem</Badge>}
              </td>
              <td className={`${td} whitespace-nowrap`}>
                {doc.viewed ? (
                  <span className="inline-flex items-center gap-1 text-success">
                    <Icon name="check_circle" className="text-[16px]" />
                    Đã xem
                  </span>
                ) : (
                  <span className="text-on-surface-variant">Chưa xem</span>
                )}
              </td>
              <td className={`${td} text-right`}>
                <Button variant="secondary" className="h-8 px-3">
                  Xem
                </Button>
              </td>
            </tr>
          ))}
          {!d.documents.length && (
            <EmptyRow
              colSpan={6}
              icon="folder_off"
              title={d.search ? 'Không tìm thấy tài liệu phù hợp' : 'Chưa có tài liệu nào'}
              hint="Học thuật chưa chia sẻ tài liệu nào cho vai trò của bạn."
            />
          )}
        </tbody>
      </table>
    </TableCard>
  )
}

function OverviewTab({ d, onLessons }: { d: Page; onLessons: () => void }) {
  return (
    <section className="space-y-4">
      <h2 className="text-lg font-semibold">
        Lộ trình tổng quát
        {d.overview_curriculum ? ` — ${d.overview_curriculum}` : ''}
      </h2>
      {!d.overview_stages.length ? (
        <Empty icon="route" title="Chưa có lộ trình" hint="Học thuật chưa soạn chặng cho giáo trình này." />
      ) : (
        <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
          {d.overview_stages.map((s) => (
            <article
              key={s.id}
              className={`flex flex-col gap-2 rounded-xl border bg-surface p-4 shadow-sm ${s.state === 'open' ? 'border-primary ring-1 ring-primary/20' : 'border-outline-variant'}`}
            >
              <div className="flex items-center justify-between gap-2">
                <h3 className="font-semibold">{s.label}</h3>
                {d.assignment && <Badge tone={STATE_TONE[s.state]}>{STATE_LABEL[s.state]}</Badge>}
              </div>
              <p className="flex-1 text-sm text-on-surface-variant">{s.summary}</p>
              {s.overview_link ? (
                <a href={s.overview_link} target="_blank" rel="noopener noreferrer" className="text-sm font-medium text-primary hover:underline">
                  Xem mục lục chặng →
                </a>
              ) : (
                <button type="button" className="self-start text-sm font-medium text-primary hover:underline" onClick={onLessons}>
                  Xem mục lục chặng →
                </button>
              )}
            </article>
          ))}
        </div>
      )}
    </section>
  )
}

function LessonsTab({ d, onLesson }: { d: Page; onLesson: (l: Lesson, u: Unit) => void }) {
  const a = d.assignment
  const p = d.position
  return (
    <section className="space-y-4 rounded-xl border border-outline-variant bg-surface p-4 shadow-sm">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <h2 className="flex items-center gap-2 font-semibold">
          <Icon name="list_alt" className="text-primary" />
          {a?.has_stage ? a.stage_label : 'Nội dung giảng dạy theo chặng'}
        </h2>
        {d.classes.length > 0 && (
          <Select label="Lớp" value={d.class_id ?? ''} onChange={(e) => setParam('class', e.target.value)}>
            {d.classes.map((c) => (
              <option key={c.value} value={c.value}>
                {c.label}
              </option>
            ))}
          </Select>
        )}
      </div>
      {!a || !p ? (
        <Empty
          icon="school"
          title="Chưa có lớp nào đang học chặng"
          hint="Học thuật mở chặng cho lớp ở màn Giao chặng; nội dung buổi học sẽ hiện ở đây."
        />
      ) : (
        <>
          <ol className="flex flex-wrap gap-2 text-xs">
            {d.stages.map((s) => (
              <li key={s.id} className="inline-flex items-center gap-1 rounded-lg border border-outline-variant px-2.5 py-1">
                <Icon name={STATE_ICON[s.state]} className="text-[13px]" />
                <span className={s.state === 'open' ? 'font-bold text-primary' : s.state === 'done' ? 'text-success' : 'text-on-surface-variant'}>
                  {s.label}
                </span>
              </li>
            ))}
          </ol>
          <div className="grid grid-cols-1 gap-3 text-sm md:grid-cols-3">
            <div className="rounded-xl border border-surface-container-highest p-3">
              <p className="text-xs font-bold text-on-surface-variant uppercase">Chặng đang học</p>
              <p className="font-semibold">{a.stage_label}</p>
              <p className="text-on-surface-variant">
                {a.curriculum} · mở {a.opened_at}
              </p>
            </div>
            <div className="rounded-xl border border-surface-container-highest p-3">
              <p className="text-xs font-bold text-on-surface-variant uppercase">Tiến độ</p>
              <p className="font-semibold">
                Đã dạy {p.taught} / {p.lessons} buổi của chặng
              </p>
              {a.extra_sessions > 0 && <p className="text-warning">+{a.extra_sessions} buổi giãn tiến độ đã duyệt</p>}
            </div>
            <div className="rounded-xl border border-surface-container-highest p-3">
              <p className="text-xs font-bold text-on-surface-variant uppercase">Big Test cuối chặng</p>
              <p className="font-semibold">{a.big_test_title}</p>
              <p className="text-on-surface-variant">Chặng đóng khi kết quả được duyệt và gửi phụ huynh.</p>
            </div>
          </div>
          {p.current ? (
            <Alert tone="info">
              <strong>
                Buổi tiếp theo: Buổi {p.current.session_no} — Unit {p.current.unit_number}
              </strong>
              <br />
              {p.current.title}
            </Alert>
          ) : (
            p.lessons > 0 && (
              <Alert tone="info">
                Lớp đã dạy hết {p.lessons} buổi của chặng
                {p.over ? ` (vượt ${p.over} buổi)` : ''} — ôn tập và tổ chức Big Test cuối chặng, hoặc gửi yêu cầu giãn tiến độ.
              </Alert>
            )
          )}
          <div className="space-y-3">
            {d.stage_units.map((u) => (
              <div key={u.id} className="overflow-hidden rounded-xl border border-outline-variant">
                <p className="bg-surface-container-low px-4 py-2.5 font-semibold">
                  Unit {u.unit_number}: {u.title}
                </p>
                <div className="divide-y divide-surface-container-highest">
                  {u.lessons.map((l) => (
                    <button
                      key={l.id}
                      type="button"
                      onClick={() => onLesson(l, u)}
                      className={`flex w-full items-center justify-between gap-2 px-4 py-2.5 text-left text-sm font-semibold hover:bg-surface-container-low ${p.current?.id === l.id ? 'bg-primary-light' : ''}`}
                    >
                      <span>
                        Buổi {l.session_no}: {l.title} {p.current?.id === l.id && <Badge tone="info">Buổi tiếp theo</Badge>}
                      </span>
                      <Icon name="open_in_new" className="text-[18px] text-on-surface-variant" />
                    </button>
                  ))}
                  {!u.lessons.length && <p className="px-4 py-2.5 text-xs text-on-surface-variant">Unit chưa có buổi học.</p>}
                </div>
              </div>
            ))}
            {!d.stage_units.length && <Empty icon="description" title="Học thuật chưa soạn nội dung cho chặng này" />}
          </div>
        </>
      )}
    </section>
  )
}

// Hộp thoại xem tài liệu: PDF / ảnh / audio / video xem trực tiếp; watermark khi không được tải.
function Viewer({ d, me }: { d: Page; me: Me }) {
  const s = d.selected!
  const box = useRef<HTMLDivElement>(null)
  const mark = useAction(() => syllabusApi.markViewed(s.id))
  const viewed = s.viewed || !!mark.notice
  return (
    <Dialog title={s.title} onClose={() => setParam('document', null)} wide>
      <div className="space-y-3">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <p className="text-xs text-on-surface-variant">{s.meta}</p>
          <div className="flex items-center gap-2">
            {!s.can_download && <Badge tone="warning">Bảo mật nội dung</Badge>}
            <button
              type="button"
              title="Toàn màn hình"
              onClick={() => box.current?.requestFullscreen?.()}
              className="rounded p-1 hover:bg-surface-container-low"
            >
              <Icon name="fullscreen" />
            </button>
            {s.can_download && (
              <a
                href={s.url}
                download
                className="inline-flex h-9 items-center gap-1 rounded-lg border border-outline-variant px-3 text-sm font-medium hover:bg-surface-container-low"
              >
                <Icon name="download" className="text-[18px]" />
                Tải về
              </a>
            )}
          </div>
        </div>
        <div ref={box} className="relative flex min-h-[320px] items-center justify-center overflow-hidden rounded-lg bg-surface-container p-3">
          {s.kind === 'pdf' ? (
            <iframe
              src={`${s.url}#toolbar=0`}
              title={s.title}
              className="h-[65vh] w-full rounded-lg border border-surface-container-highest bg-surface"
            />
          ) : s.kind === 'image' ? (
            <img src={s.url} alt={s.title} className="max-h-[65vh] rounded-lg" />
          ) : s.kind === 'audio' ? (
            <audio controls controlsList="nodownload" src={s.url} className="w-full max-w-xl" />
          ) : s.kind === 'video' ? (
            <video controls controlsList="nodownload" src={s.url} className="max-h-[65vh] w-full rounded-lg bg-black" />
          ) : (
            <div className="text-center text-sm">
              <Icon name={s.icon} className="text-4xl text-on-surface-variant" />
              <p className="font-semibold">Định dạng {s.extension.toUpperCase()} không xem trực tuyến được</p>
              <p className="text-on-surface-variant">
                {s.can_download
                  ? 'Bấm Tải về để mở bằng phần mềm tương ứng.'
                  : 'Tài liệu này không cho phép tải về; liên hệ Học thuật nếu cần bản xem được.'}
              </p>
            </div>
          )}
          {!s.can_download && (
            <div
              className="pointer-events-none absolute inset-0 flex flex-col items-center justify-around opacity-[0.08] select-none"
              aria-hidden="true"
            >
              <p className="-rotate-12 text-3xl font-bold tracking-widest">MENGLISH INTERNAL ONLY</p>
              <p className="-rotate-12 text-xl font-bold uppercase">{me.user.email ?? me.user.code ?? me.user.full_name}</p>
              <p className="-rotate-12 text-3xl font-bold tracking-widest">MENGLISH INTERNAL ONLY</p>
            </div>
          )}
        </div>
        <p className="flex items-center gap-1.5 text-sm text-on-surface-variant">
          <Icon name="info" className={`text-[16px] ${s.can_download ? 'text-success' : 'text-warning'}`} />
          {s.can_download ? 'Tài liệu được phép tải về.' : 'Tài liệu này không hỗ trợ tải về để bảo mật nội dung theo chính sách của MENGLISH.'}
        </p>
        <ErrorText error={mark.error} />
        <div className="flex justify-end">
          {viewed ? (
            <span className="inline-flex items-center gap-1 text-sm font-semibold text-success">
              <Icon name="task_alt" className="text-[18px]" />
              Đã xem
            </span>
          ) : (
            <Button disabled={mark.pending} onClick={() => mark.run()}>
              Đánh dấu đã xem
            </Button>
          )}
        </div>
      </div>
    </Dialog>
  )
}
