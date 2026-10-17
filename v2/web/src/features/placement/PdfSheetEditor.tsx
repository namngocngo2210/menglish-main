// Soạn đề bằng file PDF (PdfSheetEditor.vue của Laravel, cách "Tải đề PDF"): tải file → máy chủ đọc chữ trong PDF và
// dựng sẵn phiếu trả lời + đáp án. Người tạo đề xem PDF bên trái, kiểm tra / sửa phiếu bên phải (kỹ năng, dạng câu,
// đáp án đúng, điểm). Phiếu dùng chung cấu trúc câu hỏi với cách soạn từng câu nên tự chấm như cũ.
import { useState } from 'react'
import { errorMessage } from '../../api'
import { FileUpload } from '../../components/FileUpload'
import { Alert, Button, Card, Icon } from '../../components/ui'
import { AUDIO_RULE, PDF_RULE } from '../../lib/files'
import { placementApi, type KeyEntry, type Question } from './api'
import { Textarea } from './components'
import {
  addSheetRow,
  keyedCount,
  blankSheet,
  fillAnswers,
  gradableCount,
  isGradable,
  setOptionCount,
  setSheetType,
  sheetHasWork,
  SHEET_LETTERS,
  SHEET_SKILLS,
  SHEET_TYPES,
  showSection,
} from './logic'

export type PdfState = { pdfPath: string; pdfUrl: string; audioUrl: string }

export function PdfSheetEditor({
  questions,
  onQuestions,
  pdf,
  onPdf,
}: {
  questions: Question[]
  onQuestions: (qs: Question[]) => void
  pdf: PdfState
  onPdf: (p: PdfState) => void
}) {
  const [warnings, setWarnings] = useState<string[]>([])
  const [keyPage, setKeyPage] = useState<number | null>(null)
  const [summary, setSummary] = useState<{ questions: number; answers: number } | null>(null)
  const [notice, setNotice] = useState<{ tone: 'success' | 'error'; text: string } | null>(null)
  const [pasteText, setPasteText] = useState('')
  const [applying, setApplying] = useState(false)
  const [blankCount, setBlankCount] = useState('20')

  const answered = keyedCount(questions)
  const setQ = (i: number, q: Question) => onQuestions(questions.map((x, j) => (j === i ? q : x)))

  async function uploadPdf(file: File) {
    setNotice(null)
    const r = await placementApi.uploadPdf(file)
    const hadAnswers = answered > 0
    onPdf({ ...pdf, pdfPath: r.pdf_path, pdfUrl: r.pdf_url })
    setKeyPage(r.answer_key_page)
    // Đổi sang file đề không có trang đáp án: giữ phiếu + đáp án đã có.
    if (hadAnswers && !r.answers_found) {
      setWarnings([])
      setSummary(null)
      setNotice({ tone: 'success', text: 'Đã đổi file đề, giữ nguyên phiếu và đáp án hiện tại.' })
      return
    }
    setWarnings(r.warnings ?? [])
    setSummary({ questions: r.questions.length, answers: r.answers_found })
    if (
      r.questions.length &&
      (!sheetHasWork(questions) || window.confirm(`Thay phiếu hiện tại bằng phiếu đọc từ file mới (${r.questions.length} câu)?`))
    ) {
      onQuestions(r.questions.map((q) => ({ ...q, id: String(q.id) })))
    }
    setNotice({ tone: 'success', text: 'Đã tải file đề lên.' })
  }

  function removePdf() {
    if (!window.confirm('Bỏ file PDF khỏi đề? Phiếu đáp án vẫn giữ nguyên.')) return
    onPdf({ ...pdf, pdfPath: '', pdfUrl: '' })
    setKeyPage(null)
    setWarnings([])
    setSummary(null)
  }

  function apply(answers: KeyEntry[]) {
    const r = fillAnswers(questions, answers)
    if (!r.applied) {
      setNotice({ tone: 'error', text: 'Không nhận ra đáp án nào. Ghi dạng "1A 2B 3. apple".' })
      return 0
    }
    onQuestions(r.questions)
    setNotice({ tone: 'success', text: `Đã điền đáp án cho ${r.applied} câu.` })
    return r.applied
  }

  async function applyPaste() {
    if (!pasteText.trim()) return
    setApplying(true)
    setNotice(null)
    try {
      const { answers } = await placementApi.parseAnswers(pasteText)
      if (apply(answers)) setPasteText('')
    } catch (e) {
      setNotice({ tone: 'error', text: errorMessage(e) })
    } finally {
      setApplying(false)
    }
  }

  function makeBlank() {
    const n = Math.max(1, Math.min(200, parseInt(blankCount, 10) || 0))
    if (sheetHasWork(questions) && !window.confirm(`Thay phiếu hiện tại bằng phiếu trống ${n} câu?`)) return
    onQuestions(blankSheet(n))
  }

  return (
    <div className="space-y-4">
      <Card title="File đề PDF">
        <div className="space-y-3">
          {!pdf.pdfUrl ? (
            <FileUpload
              appearance="zone"
              rule={PDF_RULE}
              icon="picture_as_pdf"
              label="Chọn file PDF đề test (tối đa 20 MB)"
              busyLabel="Đang tải lên và đọc đề…"
              hint='Hệ thống đọc chữ trong file để tạo sẵn phiếu trả lời và đáp án (nếu đề có phần "Answer key" / "Đáp án"). Thí sinh xem nguyên file PDF, giữ tranh và bố cục, rồi trả lời trên phiếu.'
              onUpload={uploadPdf}
            />
          ) : (
            <div className="flex flex-wrap items-start gap-2">
              <span className="inline-flex h-9 items-center gap-1.5 rounded-lg bg-surface-container-low px-3 text-sm font-medium">
                <Icon name="description" className="text-[18px] text-error" />
                Đã có file đề
              </span>
              <a href={pdf.pdfUrl} target="_blank" rel="noopener noreferrer" className="inline-flex h-9 items-center text-sm font-semibold text-primary underline">
                Mở file
              </a>
              <FileUpload rule={PDF_RULE} icon="swap_horiz" label="Đổi file" busyLabel="Đang tải…" onUpload={uploadPdf} />
              <Button variant="danger" className="h-9" onClick={removePdf}>
                Bỏ file
              </Button>
            </div>
          )}
          {summary && (
            <Alert tone={warnings.length ? 'error' : 'success'}>
              <p className="font-medium">
                Đã đọc {summary.questions} câu, {summary.answers} câu có đáp án — kiểm tra lại phiếu trước khi lưu.
              </p>
              {warnings.length > 0 && (
                <ul className="mt-1 list-inside list-disc">
                  {warnings.map((w) => (
                    <li key={w}>{w}</li>
                  ))}
                </ul>
              )}
            </Alert>
          )}
          {pdf.pdfUrl && keyPage && (
            <Alert>
              <p className="font-medium">File này còn phần đáp án (trang {keyPage}) — thí sinh sẽ thấy nếu dùng file này.</p>
              <p>
                Hệ thống đã đọc đáp án vào phiếu. Bấm "Đổi file" và tải file đề không có trang đáp án để lưu được đề; phiếu và đáp án hiện tại được giữ
                nguyên.
              </p>
            </Alert>
          )}
          <div className="space-y-2 rounded-lg border border-surface-container-highest p-3">
            <p className="flex items-center gap-1.5 text-sm font-medium">
              <Icon name="headphones" className="text-[18px] text-primary" />
              File nghe của đề (phần Listening, nếu có)
            </p>
            <div className="flex flex-wrap items-start gap-2">
              <FileUpload
                rule={AUDIO_RULE}
                accept="audio/*"
                label="Tải file nghe (.mp3)"
                onUpload={async (file) => onPdf({ ...pdf, audioUrl: (await placementApi.uploadMedia('audio', file)).url })}
              />
              <input
                value={pdf.audioUrl}
                maxLength={500}
                onChange={(e) => onPdf({ ...pdf, audioUrl: e.target.value })}
                placeholder="hoặc dán đường dẫn file nghe"
                aria-label="Đường dẫn file nghe"
                className="h-9 min-w-[200px] flex-1 rounded-lg border border-outline-variant px-3 font-mono text-xs outline-none focus:border-primary"
              />
            </div>
            {pdf.audioUrl && <audio controls className="h-9 w-full" src={pdf.audioUrl} />}
          </div>
        </div>
      </Card>

      {notice && <Alert tone={notice.tone}>{notice.text}</Alert>}

      <div className="grid gap-4 xl:grid-cols-2">
        <div className="rounded-xl border border-surface-container-highest bg-surface-container-low p-3 xl:sticky xl:top-4 xl:max-h-[85vh]">
          {pdf.pdfUrl ? (
            <iframe title="Xem đề PDF" src={pdf.pdfUrl} className="h-[75vh] w-full rounded-lg border border-surface-container-highest bg-surface" />
          ) : (
            <div className="flex h-48 flex-col items-center justify-center gap-1 text-center text-sm text-on-surface-variant">
              <Icon name="picture_as_pdf" className="text-3xl" />
              <p className="font-medium text-on-surface">Chưa có file đề</p>
              <p>Tải file PDF lên để xem đề cạnh phiếu đáp án.</p>
            </div>
          )}
        </div>

        <Card
          title={
            <span className="flex flex-wrap items-center justify-between gap-2">
              Phiếu trả lời &amp; đáp án
              <span className="rounded-full bg-primary-light px-2.5 py-0.5 font-mono text-xs text-primary">
                {questions.length} câu · {answered}/{gradableCount(questions)} có đáp án
              </span>
            </span>
          }
        >
          <div className="space-y-3">
            <div className="space-y-2 rounded-lg bg-surface-container-low p-3">
              <Textarea
                label="Dán nhanh đáp án"
                rows={2}
                maxLength={20000}
                value={pasteText}
                onChange={(e) => setPasteText(e.target.value)}
                placeholder="VD: 1A 2B 3C 4. apple 5. seven | 7"
                hint="Câu trắc nghiệm ghi chữ A–H, câu điền từ ghi đáp án; nhiều cách viết đúng ngăn bằng dấu |."
              />
              <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="flex flex-wrap items-start gap-2">
                  <Button variant="secondary" className="h-9" disabled={applying || !pasteText.trim()} onClick={applyPaste}>
                    {applying ? 'Đang điền…' : 'Điền đáp án'}
                  </Button>
                  <FileUpload
                    rule={PDF_RULE}
                    icon="key"
                    label="Đọc từ file đáp án"
                    busyLabel="Đang đọc…"
                    onUpload={async (file) => {
                      setNotice(null)
                      apply((await placementApi.readAnswerKey(file)).answers)
                    }}
                  />
                </div>
                <div className="flex items-center gap-1.5 text-sm">
                  <label htmlFor="sheet-blank-count" className="text-on-surface-variant">
                    Phiếu trống
                  </label>
                  <input
                    id="sheet-blank-count"
                    type="number"
                    min={1}
                    max={200}
                    value={blankCount}
                    onChange={(e) => setBlankCount(e.target.value)}
                    className="h-9 w-16 rounded-lg border border-outline-variant px-2 text-center font-mono"
                  />
                  <span className="text-on-surface-variant">câu</span>
                  <Button variant="secondary" className="h-9" onClick={makeBlank}>
                    Tạo
                  </Button>
                </div>
              </div>
            </div>

            {!questions.length && (
              <p className="py-4 text-center text-sm text-on-surface-variant">
                Phiếu chưa có câu nào. Tải file PDF để hệ thống tạo phiếu, hoặc tạo phiếu trống theo số câu rồi dán đáp án.
              </p>
            )}
            {questions.map((q, i) => (
              <div key={q.id}>
                {showSection(questions, i) && <p className="mt-1 mb-1.5 text-xs font-semibold tracking-wide text-on-surface-variant uppercase">{q.section}</p>}
                <SheetRow
                  q={q}
                  onChange={(next) => setQ(i, next)}
                  onRemove={() => window.confirm('Xóa câu này khỏi phiếu?') && onQuestions(questions.filter((_, j) => j !== i))}
                />
              </div>
            ))}
            <Button variant="secondary" className="w-full border-dashed" onClick={() => onQuestions(addSheetRow(questions))}>
              Thêm câu vào phiếu
            </Button>
          </div>
        </Card>
      </div>
    </div>
  )
}

function SheetRow({ q, onChange, onRemove }: { q: Question; onChange: (q: Question) => void; onRemove: () => void }) {
  const missing = isGradable(q) && !String(q.correct_answer ?? '').trim()
  const opts = q.options ?? []
  const small = 'h-8 rounded-lg border border-outline-variant bg-surface px-2 text-sm'
  return (
    <div className={`relative space-y-2 rounded-lg border p-2.5 pr-10 text-sm ${missing ? 'border-warning/60 bg-warning-container/40' : 'border-surface-container-highest'}`}>
      <div className="flex flex-wrap items-center gap-1.5">
        <label htmlFor={`sheet-num-${q.id}`} className="font-semibold">
          Câu
        </label>
        <input id={`sheet-num-${q.id}`} value={q.number ?? ''} maxLength={10} onChange={(e) => onChange({ ...q, number: e.target.value })} className={`${small} w-12 text-center font-mono`} />
        <select aria-label="Kỹ năng" value={q.skill} onChange={(e) => onChange({ ...q, skill: e.target.value })} className={small}>
          {SHEET_SKILLS.map((s) => (
            <option key={s.value} value={s.value}>
              {s.label}
            </option>
          ))}
        </select>
        <select aria-label="Dạng câu" value={q.type} onChange={(e) => onChange(setSheetType(q, e.target.value))} className={small}>
          {SHEET_TYPES.map((t) => (
            <option key={t.value} value={t.value}>
              {t.label}
            </option>
          ))}
        </select>
        <input
          type="number"
          min={0.25}
          step={0.25}
          aria-label="Điểm"
          title="Điểm của câu"
          value={q.points ?? 1}
          onChange={(e) => onChange({ ...q, points: e.target.value === '' ? null : Number(e.target.value) })}
          className={`${small} w-16 text-center font-mono`}
        />
        <span className="text-on-surface-variant" aria-hidden="true">
          đ
        </span>
        <button type="button" onClick={onRemove} title="Xóa câu" aria-label="Xóa câu" className="absolute top-2 right-2 text-on-surface-variant hover:text-error">
          <Icon name="close" className="text-[18px]" />
        </button>
      </div>
      {q.type === 'multiple_choice' ? (
        <div className="flex flex-wrap items-center gap-1.5">
          <span className="text-on-surface-variant">Đáp án đúng:</span>
          {opts.map((o) => (
            <button
              key={o.key}
              type="button"
              aria-pressed={q.correct_answer === o.key}
              title={o.text || `Phương án ${o.key}`}
              onClick={() => onChange({ ...q, correct_answer: o.key })}
              className={`size-8 rounded-full border font-mono font-bold ${q.correct_answer === o.key ? 'border-primary bg-primary text-white' : 'border-outline-variant hover:border-primary'}`}
            >
              {o.key}
            </button>
          ))}
          <span className="ml-1 text-on-surface-variant">Số phương án</span>
          <button type="button" aria-label="Bớt phương án" disabled={opts.length <= 2} onClick={() => onChange(setOptionCount(q, opts.length - 1))} className="size-8 rounded-lg border border-outline-variant disabled:opacity-40">
            −
          </button>
          <button
            type="button"
            aria-label="Thêm phương án"
            disabled={opts.length >= SHEET_LETTERS.length}
            onClick={() => onChange(setOptionCount(q, opts.length + 1))}
            className="size-8 rounded-lg border border-outline-variant disabled:opacity-40"
          >
            +
          </button>
        </div>
      ) : q.type === 'fill_blank' ? (
        <input
          aria-label="Đáp án đúng"
          value={q.correct_answer}
          maxLength={1000}
          onChange={(e) => onChange({ ...q, correct_answer: e.target.value })}
          placeholder="Đáp án đúng (nhiều cách viết: 7 | seven)"
          className="h-9 w-full rounded-lg border border-outline-variant px-3 font-mono font-semibold"
        />
      ) : (
        <p className="text-on-surface-variant italic">{q.type === 'essay' ? 'Bài viết: thí sinh viết vào ô Writing, Học vụ chấm tay.' : 'Phần nói: Học vụ chấm tay.'}</p>
      )}
      {q.title && <p className="line-clamp-2 text-on-surface-variant">{q.title}</p>}
    </div>
  )
}
