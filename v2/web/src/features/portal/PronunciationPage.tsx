import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useRef, useState } from 'react'
import { Alert, Button, Field, Icon } from '../../components/ui'
import { portalApi } from './api'
import { Block, Empty, Pill, PortalFrame, QueryError, SubTabs, useNotice, useStudentParam } from './components'
import { portalHref, recordingExtension, submittedAt, timerText } from './logic'

// Luyện phát âm (Portal/Pronunciation.vue): bài nghe của lớp, ghi âm bằng micro (MediaRecorder) rồi nộp, lịch sử bài đã
// nộp. Như Laravel, không có dịch vụ chấm tự động: giáo viên chấm điểm ở màn "Chấm bài nộp của lớp".
export function PronunciationPage() {
  const sid = useStudentParam()
  const q = useQuery({ queryKey: ['portal', 'pronunciation', sid], queryFn: () => portalApi.pronunciation(sid) })
  const qc = useQueryClient()
  const notice = useNotice()
  const [unit, setUnit] = useState('')
  const rec = useRecorder()
  const submit = useMutation({
    mutationFn: () => {
      const f = new FormData()
      f.set('student_id', q.data?.student?.id ?? '')
      f.set('unit_title', unit)
      f.set('duration', timerText(rec.seconds))
      if (rec.file) f.set('audio', rec.file)
      return portalApi.submitPronunciation(f)
    },
    onSuccess: (r) => {
      rec.reset()
      void qc.invalidateQueries({ queryKey: ['portal'] })
      notice.ok(r.message)
    },
    onError: notice.fail,
  })
  const del = useMutation({
    mutationFn: portalApi.deletePronunciation,
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
    <PortalFrame ctx={d} title="Luyện phát âm" tab="pronunciation">
      <SubTabs
        items={[
          { href: portalHref('/portal/student-homework', student?.id), label: 'Nộp bài tập', icon: 'assignment', active: false },
          { href: portalHref('/portal/pronunciation', student?.id), label: 'Luyện phát âm', icon: 'mic', active: true },
        ]}
      />
      {notice.view}
      {d && (
        <div className="grid gap-4 md:grid-cols-2 md:items-start">
          <Block title="Bài nghe của lớp" icon="headphones">
            <p className="mb-3 text-xs text-on-surface-variant">File nghe giáo viên gửi kèm bài tập. Nghe, chọn bài rồi thu âm theo.</p>
            <div className="space-y-2">
              {d.practice_items.map((it, i) => (
                <div key={i} className={`rounded-xl border p-2.5 ${unit === it.title ? 'border-primary/40 bg-primary/10' : 'border-surface-container-highest'}`}>
                  <div className="flex items-center justify-between gap-2">
                    <div className="min-w-0">
                      <p className="truncate text-xs font-semibold text-on-surface">{it.title}</p>
                      <p className="text-xs text-on-surface-variant">{it.class_name}</p>
                    </div>
                    <Button variant="secondary" className="h-8 px-3 text-xs" onClick={() => setUnit(it.title)}>
                      Chọn
                    </Button>
                  </div>
                  <audio controls preload="none" className="mt-2 h-8 w-full" src={it.audio_url} />
                </div>
              ))}
              {d.practice_items.length === 0 && (
                <Empty
                  icon="headphones"
                  title="Lớp chưa có file nghe"
                  description="Khi giáo viên gửi bài tập kèm file nghe, bài sẽ hiện ở đây. Bạn vẫn có thể tự đặt tên bài và thu âm."
                />
              )}
            </div>
          </Block>

          {d.can_write && (
            <Block title="Ghi âm & nộp bài" icon="mic">
              <form
                onSubmit={(e) => {
                  e.preventDefault()
                  submit.mutate()
                }}
                className="space-y-3"
              >
                <Field label="Bài đang luyện" value={unit} onChange={(e) => setUnit(e.target.value)} maxLength={255} placeholder="Ví dụ: Unit 1 - Greetings" />
                <div className="text-center font-mono text-3xl font-bold tracking-wider">{timerText(rec.seconds)}</div>
                <div className="flex justify-center py-2">
                  <button
                    type="button"
                    onClick={rec.toggle}
                    aria-label={rec.recording ? 'Dừng ghi âm' : 'Bắt đầu ghi âm'}
                    className="relative flex size-20 items-center justify-center rounded-full bg-primary text-white shadow-xl transition hover:scale-105 hover:bg-primary-hover focus:ring-4 focus:ring-primary/30 focus:outline-none active:scale-95"
                  >
                    {rec.recording && <span className="absolute inset-0 animate-ping rounded-full border-2 border-primary opacity-75" />}
                    <Icon name={rec.recording ? 'stop' : 'mic'} fill className="text-3xl" />
                  </button>
                </div>
                <p className="text-center text-xs text-on-surface-variant">
                  {rec.recording ? 'Chạm để dừng ghi âm' : rec.file ? 'Nghe lại bên dưới, hoặc chạm micro để ghi lại' : 'Chạm micro để bắt đầu ghi âm'}
                </p>
                {rec.error && <p className="text-center text-xs font-semibold text-error">{rec.error}</p>}
                {rec.preview && !rec.recording && <audio controls className="h-8 w-full" src={rec.preview} />}
                <div className="flex justify-center pt-2">
                  <Button type="submit" className="w-full max-w-[240px]" disabled={!rec.file || rec.recording || submit.isPending}>
                    <Icon name="send" className="mr-1 text-[18px]" /> Nộp bài ghi âm
                  </Button>
                </div>
              </form>
            </Block>
          )}

          <Block title="Lịch sử của bạn" icon="history" aside={<span className="text-xs text-on-surface-variant">Giáo viên chấm điểm</span>} className="md:col-span-2">
            <div className="space-y-2">
              {d.history.map((r) => (
                <div key={r.id} className="flex items-center justify-between gap-2 rounded-xl border border-surface-container-highest bg-surface-container-low p-3">
                  <div className="min-w-0">
                    <p className="text-xs font-bold text-on-surface">{r.title}</p>
                    <div className="mt-1 flex items-center gap-2 text-xs text-on-surface-variant">
                      <span className="flex items-center gap-0.5">
                        <Icon name="calendar_today" className="text-[13px]" /> {submittedAt(r.submitted_at)}
                      </span>
                      <span>•</span>
                      <span className="flex items-center gap-0.5 font-mono">
                        <Icon name="timer" className="text-[13px]" /> {r.duration ?? '—'}
                      </span>
                    </div>
                    {r.status === 'reviewed' && r.score ? (
                      <div className="mt-1">
                        <Pill tone="success">Giáo viên chấm: {r.score}</Pill>
                        {r.feedback && <p className="mt-1 text-xs text-on-surface-variant italic">"{r.feedback}"</p>}
                      </div>
                    ) : (
                      <div className="mt-1">
                        <Pill tone="warning">Chờ giáo viên chấm</Pill>
                      </div>
                    )}
                  </div>
                  <div className="flex items-center gap-1.5">
                    {r.audio_url && (
                      <a href={r.audio_url} target="_blank" rel="noopener noreferrer" title="Nghe lại" aria-label="Nghe lại" className="inline-flex size-8 items-center justify-center rounded-lg text-primary hover:bg-primary/10">
                        <Icon name="play_arrow" className="text-[18px]" />
                      </a>
                    )}
                    {d.can_write && (
                      <button
                        type="button"
                        title="Xóa bản ghi"
                        aria-label="Xóa bản ghi"
                        onClick={() => window.confirm('Xóa bản ghi âm này?') && del.mutate(r.id)}
                        className="inline-flex size-8 items-center justify-center rounded-lg text-error hover:bg-error-container"
                      >
                        <Icon name="delete" className="text-[18px]" />
                      </button>
                    )}
                  </div>
                </div>
              ))}
              {d.history.length === 0 && <Empty icon="mic" title="Chưa có bài ghi âm" description="Chọn bài, thu âm và bấm Nộp bài — giáo viên sẽ chấm và phản hồi." />}
            </div>
          </Block>
        </div>
      )}
      {!d && !q.isPending && <Alert>Không tải được dữ liệu.</Alert>}
    </PortalFrame>
  )
}

// Ghi âm bằng micro: bật / dừng, đồng hồ, file ghi âm để nộp và bản nghe thử.
function useRecorder() {
  const [recording, setRecording] = useState(false)
  const [seconds, setSeconds] = useState(0)
  const [file, setFile] = useState<File | null>(null)
  const [preview, setPreview] = useState<string | null>(null)
  const [error, setError] = useState('')
  const recorder = useRef<MediaRecorder | null>(null)
  const timer = useRef<number | undefined>(undefined)

  useEffect(
    () => () => {
      window.clearInterval(timer.current)
      if (recorder.current?.state === 'recording') recorder.current.stop()
    },
    [],
  )
  useEffect(() => () => {
    if (preview) URL.revokeObjectURL(preview)
  }, [preview])

  async function toggle() {
    if (recording) {
      recorder.current?.stop()
      return
    }
    setError('')
    if (!navigator.mediaDevices?.getUserMedia || typeof MediaRecorder === 'undefined') {
      setError('Trình duyệt không hỗ trợ ghi âm. Hãy dùng Chrome, Edge hoặc Safari bản mới.')
      return
    }
    let stream: MediaStream
    try {
      stream = await navigator.mediaDevices.getUserMedia({ audio: true })
    } catch {
      setError('Chưa được cấp quyền dùng micro. Hãy cho phép micro rồi thử lại.')
      return
    }
    const chunks: Blob[] = []
    const r = new MediaRecorder(stream)
    r.ondataavailable = (e) => {
      if (e.data.size > 0) chunks.push(e.data)
    }
    r.onstop = () => {
      stream.getTracks().forEach((t) => t.stop())
      window.clearInterval(timer.current)
      setRecording(false)
      const type = r.mimeType || 'audio/webm'
      const blob = new Blob(chunks, { type })
      if (blob.size > 0) {
        setFile(new File([blob], `ghi-am.${recordingExtension(type)}`, { type }))
        setPreview(URL.createObjectURL(blob))
      }
    }
    recorder.current = r
    setSeconds(0)
    r.start()
    setRecording(true)
    timer.current = window.setInterval(() => setSeconds((s) => s + 1), 1000)
  }

  return {
    recording,
    seconds,
    file,
    preview,
    error,
    toggle,
    reset: () => {
      setFile(null)
      setPreview(null)
      setSeconds(0)
    },
  }
}
