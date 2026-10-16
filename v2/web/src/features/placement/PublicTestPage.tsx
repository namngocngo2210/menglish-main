import { useMutation, useQuery } from '@tanstack/react-query'
import { useCallback, useEffect, useRef, useState, type ReactNode } from 'react'
import { ApiError, errorMessage } from '../../api'
import { Alert, Button, Field } from '../../components/ui'
import { placementApi, type PortalQuestion, type PublicPage, type Violation } from './api'
import { Textarea } from './components'
import { answeredCount, isBlockedShortcut, registerViolation, type ViolationState } from './logic'

// Đường dẫn trang làm bài công khai (link mời): /lam-bai/<token>. Không cần đăng nhập.
export const PUBLIC_PREFIX = '/lam-bai/'

export function publicToken(path: string) {
  return path.startsWith(PUBLIC_PREFIX) ? decodeURIComponent(path.slice(PUBLIC_PREFIX.length).split('/')[0]) : null
}

const SELF_RATES = [
  { value: 'beginner', title: 'Mới bắt đầu / Mất gốc (A1)', text: 'Chưa tự tin phát âm, hay ấp úng khi giao tiếp câu cơ bản.' },
  { value: 'intermediate', title: 'Trung bình (A2 - B1)', text: 'Giao tiếp được câu hoàn chỉnh hàng ngày, phản xạ tương đối ổn.' },
  { value: 'advanced', title: 'Nâng cao (B2 - C1)', text: 'Tự tin thuyết trình, tranh luận học thuật và phản xạ nhanh.' },
]

function Frame({ children, wide }: { children: ReactNode; wide?: boolean }) {
  return (
    <div className="min-h-dvh bg-surface-container-low text-on-surface">
      <header className="sticky top-0 z-40 border-b border-surface-container-highest bg-surface shadow-sm">
        <div className="mx-auto flex max-w-5xl items-center gap-3 px-4 py-3">
          <img src="/images/menglish-logo.png" alt="" width={40} height={40} className="size-10 rounded-xl" />
          <div>
            <div className="font-bold">MEnglish Academy</div>
            <div className="text-xs text-on-surface-variant">Hệ thống đánh giá trình độ & xếp lớp</div>
          </div>
        </div>
      </header>
      <main className={`mx-auto space-y-6 px-4 py-8 ${wide ? 'max-w-6xl' : 'max-w-4xl'}`}>{children}</main>
    </div>
  )
}

export function PublicTestPage({ token }: { token: string }) {
  const page = useQuery({ queryKey: ['public-test', token], queryFn: () => placementApi.publicTest(token), retry: false, staleTime: Infinity })
  const [done, setDone] = useState<{ candidate_name: string; test_title: string } | null>(null)
  if (done) return <DoneScreen {...done} />
  if (page.isPending) return <Frame><p className="text-center text-sm text-on-surface-variant">Đang tải đề…</p></Frame>
  if (page.isError) {
    return (
      <Frame>
        <div className="mx-auto max-w-md space-y-3 rounded-2xl border border-surface-container-highest bg-surface p-8 text-center shadow-sm">
          <h1 className="text-lg font-semibold">Không mở được bài test</h1>
          <p className="text-sm text-on-surface-variant">
            {page.error instanceof ApiError ? page.error.message : errorMessage(page.error)}
          </p>
        </div>
      </Frame>
    )
  }
  return <TakeTest token={token} data={page.data} onDone={setDone} />
}

function DoneScreen({ candidate_name, test_title }: { candidate_name: string; test_title: string }) {
  return (
    <div className="flex min-h-dvh items-center justify-center bg-surface-container-low p-4 text-on-surface">
      <main className="w-full max-w-md space-y-4 rounded-3xl border border-surface-container-highest bg-surface p-8 text-center shadow-xl" data-testid="placement-test-done">
        <div className="mx-auto flex size-20 items-center justify-center rounded-full bg-primary-light text-4xl" aria-hidden="true">
          🎉
        </div>
        <h1 className="text-2xl font-bold">Chúc mừng con đã hoàn thiện bài test!</h1>
        {candidate_name && <p className="text-sm font-semibold">{candidate_name}</p>}
        <p className="text-sm leading-relaxed text-on-surface-variant">
          Bài làm đã được gửi tới MEnglish. Thầy cô sẽ chấm và liên hệ gửi kết quả cùng lộ trình học phù hợp cho phụ huynh trong thời gian sớm nhất.
        </p>
        <p className="text-xs text-on-surface-variant">{test_title}</p>
      </main>
    </div>
  )
}

const root = () => document.documentElement as HTMLElement & { webkitRequestFullscreen?: () => Promise<void> | void }
type Doc = Document & { webkitFullscreenElement?: Element | null; webkitExitFullscreen?: () => Promise<void> | void }
const canFullscreen = () => !!(root().requestFullscreen || root().webkitRequestFullscreen)
const isFullscreen = () => !!(document.fullscreenElement || (document as Doc).webkitFullscreenElement)
function enterFullscreen() {
  if (!canFullscreen() || isFullscreen()) return
  try {
    const p = (root().requestFullscreen ?? root().webkitRequestFullscreen)?.call(root())
    if (p && 'catch' in p) p.catch(() => undefined)
  } catch {
    // trình duyệt từ chối toàn màn hình: vẫn cho làm bài
  }
}
function exitFullscreen() {
  if (!isFullscreen()) return
  try {
    const d = document as Doc
    const p = (d.exitFullscreen ?? d.webkitExitFullscreen)?.call(d)
    if (p && 'catch' in p) p.catch(() => undefined)
  } catch {
    // bỏ qua
  }
}

// Làm bài có khóa màn hình: toàn màn hình; rời bài (ẩn tab / mất focus / thoát toàn màn hình) được ghi lại và gửi kèm;
// tới max_violations lần thì tự nộp. Đang làm: chặn quay lại / tải lại, chuột phải, sao chép / dán, kéo thả, phím tắt.
function TakeTest({ token, data, onDone }: { token: string; data: PublicPage; onDone: (r: { candidate_name: string; test_title: string }) => void }) {
  const [info, setInfo] = useState({ name: data.lead?.name ?? '', phone: data.lead?.phone ?? '', email: data.lead?.email ?? '' })
  const [answers, setAnswers] = useState<Record<string, string>>({})
  const [writing, setWriting] = useState('')
  const [selfRate, setSelfRate] = useState('intermediate')
  const [started, setStarted] = useState(false)
  const [warning, setWarning] = useState(false)
  const [autoSubmitting, setAutoSubmitting] = useState(false)
  const [startError, setStartError] = useState<string | null>(null)
  const [violations, setViolations] = useState<ViolationState>({ log: [], lastAt: -Infinity })
  const submittingRef = useRef(false)
  const stateRef = useRef({ answers, writing, selfRate, info, violations })
  stateRef.current = { answers, writing, selfRate, info, violations }

  const submit = useMutation({
    mutationFn: ({ auto, log }: { auto: boolean; log: Violation[] }) => {
      const s = stateRef.current
      return placementApi.submitTest(token, {
        candidate_name: s.info.name,
        candidate_phone: s.info.phone,
        candidate_email: s.info.email,
        answers: s.answers,
        writing_content: s.writing,
        speaking_self_rate: s.selfRate,
        violation_count: log.length,
        violation_log: log,
        auto_submitted: auto,
      })
    },
    onMutate: () => {
      submittingRef.current = true
    },
    onSuccess: (r) => {
      exitFullscreen()
      onDone(r)
    },
    onError: () => {
      // Lỗi kiểm tra dữ liệu: cho làm tiếp, bỏ lớp phủ "đang gửi bài".
      submittingRef.current = false
      setAutoSubmitting(false)
    },
  })

  const record = useCallback(
    (type: Violation['type']) => {
      if (!started || submittingRef.current) return
      const r = registerViolation(stateRef.current.violations, type, Date.now(), data.max_violations)
      if (r.action === 'ignored') return
      setViolations(r.state)
      stateRef.current.violations = r.state
      if (r.action === 'auto_submit') {
        setWarning(false)
        setAutoSubmitting(true)
        submit.mutate({ auto: true, log: r.state.log })
      } else {
        setWarning(true)
      }
    },
    [started, data.max_violations, submit],
  )
  const recordRef = useRef(record)
  useEffect(() => {
    recordRef.current = record
  }, [record])

  useEffect(() => {
    if (!started) return
    const rec = (type: Violation['type']) => recordRef.current(type)
    const onVisibility = () => document.visibilityState === 'hidden' && rec('hidden')
    const onBlur = () => rec('blur')
    const onFullscreen = () => canFullscreen() && !isFullscreen() && rec('fullscreen_exit')
    const block = (e: Event) => !submittingRef.current && e.preventDefault()
    const onSelect = (e: Event) => {
      const el = e.target as Element | null
      if (!submittingRef.current && !el?.closest?.('input, textarea')) e.preventDefault()
    }
    const onKey = (e: KeyboardEvent) => {
      if (!submittingRef.current && isBlockedShortcut(e)) {
        e.preventDefault()
        e.stopPropagation()
      }
    }
    const onBeforeUnload = (e: BeforeUnloadEvent) => {
      if (!submittingRef.current) {
        e.preventDefault()
        e.returnValue = ''
      }
    }
    // Khóa nút Quay lại: đẩy lại mục lịch sử trùng trang.
    const onPop = () => !submittingRef.current && window.history.pushState(null, '', window.location.href)
    window.history.pushState(null, '', window.location.href)
    const blocked = ['contextmenu', 'copy', 'cut', 'paste', 'drop', 'dragstart']
    document.addEventListener('visibilitychange', onVisibility)
    window.addEventListener('blur', onBlur)
    document.addEventListener('fullscreenchange', onFullscreen)
    document.addEventListener('webkitfullscreenchange', onFullscreen)
    blocked.forEach((t) => document.addEventListener(t, block))
    document.addEventListener('selectstart', onSelect)
    document.addEventListener('keydown', onKey, true)
    window.addEventListener('beforeunload', onBeforeUnload)
    window.addEventListener('popstate', onPop)
    return () => {
      document.removeEventListener('visibilitychange', onVisibility)
      window.removeEventListener('blur', onBlur)
      document.removeEventListener('fullscreenchange', onFullscreen)
      document.removeEventListener('webkitfullscreenchange', onFullscreen)
      blocked.forEach((t) => document.removeEventListener(t, block))
      document.removeEventListener('selectstart', onSelect)
      document.removeEventListener('keydown', onKey, true)
      window.removeEventListener('beforeunload', onBeforeUnload)
      window.removeEventListener('popstate', onPop)
    }
  }, [started])

  const t = data.test
  const pdf = !!t.pdf_url
  const keys = pdf ? data.sheet.map((s) => s.answer_key) : [...data.listening, ...data.reading].map((q) => q.answer_key)
  const setAnswer = (k: string, v: string) => setAnswers((a) => ({ ...a, [k]: v }))

  return (
    <Frame wide={pdf}>
      <div className="space-y-2 rounded-3xl bg-gradient-to-r from-brand to-primary p-6 text-white shadow-lg md:p-8">
        <div className="flex flex-wrap items-center gap-2">
          <span className="rounded-full bg-white/20 px-3 py-1 font-mono text-xs font-bold uppercase">{t.code}</span>
          {t.target_level && <span className="rounded-full bg-white/20 px-3 py-1 text-xs font-semibold">{t.target_level}</span>}
          <span className="rounded-full bg-white/20 px-3 py-1 text-xs font-semibold">Thời gian: {t.duration_minutes} phút</span>
          {started && (
            <span className="rounded-full bg-white px-3 py-1 text-xs font-bold text-error">
              Rời bài: {violations.log.length}/{data.max_violations}
            </span>
          )}
        </div>
        <h1 className="text-2xl font-bold md:text-3xl">{t.title}</h1>
        <p className="max-w-2xl text-sm opacity-90">
          Bài kiểm tra gồm {t.questions_count} câu hỏi đánh giá 4 kỹ năng (Nghe, Đọc - Ngữ pháp, Viết và Nói). Sau khi nộp bài, <strong>Học vụ MEnglish</strong> sẽ chấm
          và gửi kết quả xếp lớp cho phụ huynh.
        </p>
      </div>

      <form
        className="space-y-6"
        onSubmit={(e) => {
          e.preventDefault()
          submit.mutate({ auto: false, log: violations.log })
        }}
      >
        {submit.isError && <Alert>{errorMessage(submit.error)}</Alert>}
        <Section title="1. Thông tin thí sinh dự thi">
          <div className="grid gap-4 md:grid-cols-3">
            <Field label="Họ và tên thí sinh" required maxLength={255} value={info.name} onChange={(e) => setInfo({ ...info, name: e.target.value })} placeholder="Họ và tên" />
            <Field
              label="Số điện thoại liên hệ"
              required
              maxLength={20}
              inputMode="tel"
              value={info.phone}
              onChange={(e) => setInfo({ ...info, phone: e.target.value })}
              placeholder="VD: 0912 345 678"
            />
            <Field label="Email nhận bảng điểm" type="email" maxLength={255} value={info.email} onChange={(e) => setInfo({ ...info, email: e.target.value })} placeholder="hocvien@gmail.com" />
          </div>
        </Section>

        {!started ? (
          <Section title="Quy chế làm bài">
            <ul className="list-inside list-disc space-y-1.5 text-sm text-on-surface-variant">
              <li>
                Khi bấm <strong>Bắt đầu làm bài</strong>, bài thi mở ở chế độ <strong>toàn màn hình</strong>. Không thoát toàn màn hình, không chuyển sang tab, trang web hay ứng
                dụng khác cho tới khi nộp bài.
              </li>
              <li>Bài thi không cho sao chép, dán, bấm chuột phải hay tải lại trang.</li>
              <li>
                Mỗi lần rời khỏi bài thi đều được ghi lại và gửi kèm bài làm cho người chấm. Rời bài tới lần thứ <strong>{data.max_violations}</strong>, bài sẽ{' '}
                <strong>tự động nộp</strong> với các câu đã làm.
              </li>
            </ul>
            {startError && <Alert>{startError}</Alert>}
            <div className="flex flex-wrap items-center justify-between gap-3 pt-2">
              <span className="text-xs text-on-surface-variant">Điền họ tên và số điện thoại ở trên trước khi bắt đầu.</span>
              <Button
                type="button"
                onClick={() => {
                  if (!info.name.trim() || !info.phone.trim()) {
                    setStartError('Vui lòng nhập họ tên và số điện thoại trước khi bắt đầu.')
                    return
                  }
                  setStartError(null)
                  enterFullscreen()
                  setStarted(true)
                  window.scrollTo({ top: 0 })
                }}
              >
                Bắt đầu làm bài
              </Button>
            </div>
          </Section>
        ) : (
          <>
            {pdf ? (
              <div className="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_320px]">
                <Section title="2. Đề bài">
                  {t.audio_src && <audio controls preload="metadata" className="h-9 w-full" src={t.audio_src} />}
                  <iframe title="Đề bài" src={`${t.pdf_url}#toolbar=0`} className="h-[75vh] w-full rounded-lg border border-surface-container-highest" />
                </Section>
                <Section title={`3. Phiếu trả lời (${data.sheet.length} câu)`}>
                  <div className="space-y-2 lg:max-h-[70vh] lg:overflow-y-auto">
                    {data.sheet.map((r, i) => (
                      <div key={r.answer_key}>
                        {r.section && (i === 0 || data.sheet[i - 1].section !== r.section) && (
                          <p className="pt-1 text-xs font-bold text-on-surface-variant uppercase">{r.section}</p>
                        )}
                        <div className="flex items-center gap-2 rounded-lg border border-surface-container-highest px-2 py-1.5">
                          <span className="w-14 shrink-0 font-mono text-xs font-bold">Câu {r.number}</span>
                          {r.type === 'fill_blank' ? (
                            <input
                              aria-label={`Câu ${r.number}`}
                              className="h-8 flex-1 rounded-lg border border-outline-variant px-2 text-sm"
                              value={answers[r.answer_key] ?? ''}
                              maxLength={1000}
                              onChange={(e) => setAnswer(r.answer_key, e.target.value)}
                            />
                          ) : (
                            <div role="radiogroup" aria-label={`Câu ${r.number}`} className="flex flex-wrap gap-1.5">
                              {r.options.map((k) => (
                                <label key={k} className="cursor-pointer">
                                  <input
                                    type="radio"
                                    className="peer sr-only"
                                    name={r.answer_key}
                                    checked={answers[r.answer_key] === k}
                                    onChange={() => setAnswer(r.answer_key, k)}
                                  />
                                  <span className="flex size-9 items-center justify-center rounded-full border border-outline-variant font-mono text-sm font-bold peer-checked:border-primary peer-checked:bg-primary peer-checked:text-white">
                                    {k}
                                  </span>
                                </label>
                              ))}
                            </div>
                          )}
                        </div>
                      </div>
                    ))}
                  </div>
                </Section>
              </div>
            ) : (
              <>
                {data.listening.length > 0 && (
                  <Section title={`2. Listening Comprehension (${data.listening.length} câu)`}>
                    {data.listening.map((q) => (
                      <QuestionBlock key={q.answer_key} q={q} value={answers[q.answer_key] ?? ''} onChange={(v) => setAnswer(q.answer_key, v)} />
                    ))}
                  </Section>
                )}
                {data.reading.length > 0 && (
                  <Section title={`3. Reading & Grammar (${data.reading.length} câu)`}>
                    {data.reading.map((q) => (
                      <QuestionBlock key={q.answer_key} q={q} value={answers[q.answer_key] ?? ''} onChange={(v) => setAnswer(q.answer_key, v)} />
                    ))}
                  </Section>
                )}
              </>
            )}
            {data.writing_prompt && (
              <Section title="4. Writing Task (Viết tự luận)">
                <p className="rounded-lg border border-warning/30 bg-warning-container p-3 text-sm">
                  <strong>Đề bài Writing:</strong> {data.writing_prompt}
                </p>
                <Textarea aria-label="Bài viết" rows={6} maxLength={5000} value={writing} onChange={(e) => setWriting(e.target.value)} placeholder="Nhập bài viết của bạn tại đây..." />
              </Section>
            )}
            <Section title="5. Speaking & Fluency (Nói & Phản xạ)">
              {data.speaking && (
                <div className="rounded-lg border border-error/20 bg-error-container/40 p-3 text-sm">
                  <strong>Gợi ý chủ đề Speaking: {data.speaking.title}</strong>
                  <p className="font-mono text-xs whitespace-pre-line">{data.speaking.cue_points}</p>
                </div>
              )}
              <p className="text-sm font-semibold text-on-surface-variant">Tự đánh giá mức độ phản xạ nói tiếng Anh hiện tại:</p>
              <div className="grid gap-3 sm:grid-cols-3">
                {SELF_RATES.map((r) => (
                  <label key={r.value} className="block cursor-pointer space-y-1 rounded-xl border border-surface-container-highest p-3 text-sm has-checked:border-primary">
                    <input type="radio" name="speaking_self_rate" className="accent-primary" checked={selfRate === r.value} onChange={() => setSelfRate(r.value)} />
                    <div className="font-semibold">{r.title}</div>
                    <p className="text-xs text-on-surface-variant">{r.text}</p>
                  </label>
                ))}
              </div>
            </Section>
            <div className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-surface-container-highest bg-surface p-6 shadow-sm">
              <span className="text-sm text-on-surface-variant">
                Đã làm {answeredCount(answers, keys)}/{keys.length} câu. Vui lòng kiểm tra lại câu trả lời trước khi gửi bài thi.
              </span>
              <Button type="submit" disabled={submit.isPending}>
                Nộp bài
              </Button>
            </div>
          </>
        )}
      </form>

      {warning && (
        <div className="fixed inset-0 z-[100] flex items-center justify-center bg-sidebar/90 p-4">
          <div role="alertdialog" aria-label="Bạn vừa rời khỏi bài thi" className="w-full max-w-md space-y-4 rounded-2xl bg-surface p-6 text-center shadow-xl">
            <h2 className="text-lg font-bold">Bạn vừa rời khỏi bài thi</h2>
            <p className="text-sm text-on-surface-variant">
              Đây là lần thứ <strong>{violations.log.length || 1}</strong>/{data.max_violations}. Lần rời bài đã được ghi lại cho người chấm. Tới lần thứ {data.max_violations}
              , bài sẽ tự động nộp.
            </p>
            <Button
              className="w-full"
              onClick={() => {
                setWarning(false)
                enterFullscreen()
              }}
            >
              Quay lại làm bài
            </Button>
          </div>
        </div>
      )}
      {autoSubmitting && (
        <div className="fixed inset-0 z-[110] flex items-center justify-center bg-sidebar/90 p-4">
          <div className="w-full max-w-md space-y-3 rounded-2xl bg-surface p-6 text-center shadow-xl">
            <h2 className="text-lg font-bold">Bài thi đã tự động nộp</h2>
            <p className="text-sm text-on-surface-variant">Bạn đã rời khỏi bài thi quá số lần cho phép. Đang gửi bài làm...</p>
          </div>
        </div>
      )}
    </Frame>
  )
}

function Section({ title, children }: { title: string; children: ReactNode }) {
  return (
    <section className="space-y-4 rounded-2xl border border-surface-container-highest bg-surface p-6 shadow-sm">
      <h2 className="border-b border-surface-container-highest pb-2 text-sm font-bold tracking-wide uppercase">{title}</h2>
      {children}
    </section>
  )
}

function QuestionBlock({ q, value, onChange }: { q: PortalQuestion; value: string; onChange: (v: string) => void }) {
  return (
    <div className="space-y-3 rounded-xl border border-surface-container-highest bg-surface-container-low/50 p-4 text-sm">
      <div className="flex items-start gap-2 font-semibold">
        <span className="shrink-0 rounded-md bg-primary-light px-2 py-0.5 font-mono text-xs text-primary">Câu {q.number}</span>
        <span className="whitespace-pre-line">{q.title}</span>
      </div>
      {q.audio_src && <audio controls preload="metadata" className="h-9 w-full" src={q.audio_src} />}
      {q.passage && <p className="rounded-lg border border-info/20 bg-info-container/40 p-3 text-xs whitespace-pre-line italic">“{q.passage}”</p>}
      {q.image_url && <img src={q.image_url} alt="Minh họa" className="mx-auto max-h-64 rounded-lg object-contain" />}
      {q.type === 'fill_blank' || q.options.length === 0 ? (
        <input
          aria-label={`Câu ${q.number}`}
          className="h-10 w-full rounded-lg border border-outline-variant px-3 font-semibold"
          placeholder="Nhập câu trả lời..."
          maxLength={1000}
          value={value}
          onChange={(e) => onChange(e.target.value)}
        />
      ) : (
        <div role="radiogroup" aria-label={`Câu ${q.number}`} className="grid gap-2 sm:grid-cols-2">
          {q.options.map((o) => (
            <label key={o.key} className="flex cursor-pointer flex-col gap-1 rounded-xl border border-surface-container-highest bg-surface p-2.5 has-checked:border-primary">
              <span className="flex items-center gap-2">
                <input type="radio" className="accent-primary" name={q.answer_key} checked={value === o.key} onChange={() => onChange(o.key)} />
                <span className="font-bold">{o.key}.</span>
                <span>{o.text}</span>
              </span>
              {o.image_url && <img src={o.image_url} alt={o.key} className="mx-auto max-h-32 rounded-lg object-contain" />}
            </label>
          ))}
        </div>
      )}
    </div>
  )
}
