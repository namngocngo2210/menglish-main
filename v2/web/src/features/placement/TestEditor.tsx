import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { errorMessage, type Me } from '../../api'
import { Alert, Button, Card, Checkbox, Field, Select } from '../../components/ui'
import { navigate } from '../../router'
import { placementApi, type FormOptions, type Question, type TestDetail, type TestInput } from './api'
import { PageHeader, Textarea } from './components'
import {
  changeType,
  codeMatchesGroup,
  hasAnswerKey,
  hasOptions,
  moveItem,
  newQuestion,
  QUESTION_SKILLS,
  QUESTION_TYPES,
  questionIssues,
} from './logic'
import { BASE } from './TestsPage'

// Tạo / sửa đề (cách "Soạn từng câu"). Đề PDF cũ nhập từ Laravel vẫn sửa được phiếu trả lời; tải PDF mới chưa hỗ trợ.
export function TestEditor({ me, id }: { me: Me; id?: string }) {
  const options = useQuery({ queryKey: ['placement', 'options'], queryFn: placementApi.options })
  const test = useQuery({ queryKey: ['placement', 'test', id], queryFn: () => placementApi.test(id!), enabled: !!id })
  if (options.isError || test.isError) return <Alert>{errorMessage(options.error ?? test.error)}</Alert>
  if (!options.data || (id && !test.data)) return <p className="text-sm text-on-surface-variant">Đang tải…</p>
  return <EditorForm me={me} options={options.data} test={test.data} />
}

function EditorForm({ options, test }: { me: Me; options: FormOptions; test?: TestDetail }) {
  const qc = useQueryClient()
  const editing = !!test
  const [code, setCode] = useState(test?.code ?? options.suggested_code)
  const [codeTouched, setCodeTouched] = useState(editing)
  const [form, setForm] = useState({
    title: test?.title ?? '',
    description: test?.description ?? '',
    grade_level: test?.grade_level ?? 'lop_3',
    target_level: test?.target_level ?? '',
    duration_minutes: String(test?.duration_minutes ?? 45),
    audio_url: test?.audio_url ?? '',
    is_active: test?.is_active ?? true,
  })
  const [questions, setQuestions] = useState<Question[]>(() => test?.questions ?? [newQuestion([], 'reading')])
  const [current, setCurrent] = useState(0)
  const pdfMode = !!test?.pdf_path
  const group = options.level_rubric_groups[form.grade_level] ?? 'khac'
  const token = options.grade_code_tokens[group]

  // Mã đề gợi ý theo khối lớp (người dùng sửa tay thì giữ nguyên).
  useEffect(() => {
    if (codeTouched) return
    const stamp = options.suggested_code.split('-').slice(-2).join('-')
    const levelToken = form.grade_level === 'mau_giao' ? 'PRE-G1' : 'G' + form.grade_level.replace('lop_', '')
    setCode(`TEST-${group !== 'khac' && token ? token : levelToken}-${stamp}`)
  }, [form.grade_level, group, token, codeTouched, options.suggested_code])

  const save = useMutation({
    mutationFn: (saveMode: 'draft' | 'publish') => {
      const input: TestInput = {
        title: form.title,
        description: form.description,
        target_level: form.target_level,
        grade_level: form.grade_level || null,
        duration_minutes: form.duration_minutes === '' ? null : Number(form.duration_minutes),
        questions,
        audio_url: pdfMode ? form.audio_url : null,
      }
      if (!pdfMode) input.mode = 'manual'
      if (editing) return placementApi.updateTest(test.id, { ...input, is_active: form.is_active })
      return placementApi.createTest({ ...input, code, save_mode: saveMode })
    },
    onSuccess: (r) => {
      qc.invalidateQueries({ queryKey: ['placement'] })
      navigate(`${BASE}/${r.data.id}`)
    },
  })

  const q = questions[current]
  const setQ = (patch: Partial<Question>) => setQuestions(questions.map((x, i) => (i === current ? { ...x, ...patch } : x)))
  const issues = questionIssues(questions)
  const set = (k: keyof typeof form) => (e: { target: { value: string } }) => setForm({ ...form, [k]: e.target.value })

  return (
    <div className="space-y-6">
      <PageHeader
        title={editing ? `Sửa đề: ${test.title}` : 'Tạo đề thi mới'}
        back={editing ? `${BASE}/${test.id}` : BASE}
        description={pdfMode ? 'Đề PDF (nhập từ hệ thống cũ): sửa phiếu trả lời và đáp án; thay file PDF mới chưa hỗ trợ.' : 'Soạn từng câu: nhập câu hỏi, phương án, đáp án; thí sinh làm từng câu trên màn hình.'}
      />
      {save.isError && <Alert>{errorMessage(save.error)}</Alert>}
      <div className="grid gap-6 lg:grid-cols-[360px_minmax(0,1fr)]">
        <div className="space-y-6">
          <Card title="Thông tin chung">
            <div className="space-y-4">
              <Field label="Tên đề thi" required maxLength={255} value={form.title} onChange={set('title')} placeholder="VD: Đề test đầu vào lớp 3" />
              <Select label="Cấp độ (khối lớp)" value={form.grade_level} onChange={set('grade_level')} disabled={editing && test.is_preset}>
                <option value="">— Chọn cấp độ —</option>
                {options.grade_levels.map((l) => (
                  <option key={l.value} value={l.value}>
                    {l.label}
                  </option>
                ))}
              </Select>
              <Field
                label="Mã đề"
                required
                maxLength={50}
                value={code}
                disabled={editing}
                onChange={(e) => {
                  setCode(e.target.value)
                  setCodeTouched(true)
                }}
                hint={
                  editing
                    ? 'Mã đề không đổi sau khi tạo.'
                    : codeMatchesGroup(code, token)
                      ? `Chấm theo thang điểm: ${options.grade_groups.find((g) => g.value === group)?.label ?? 'thủ công'}.`
                      : `Mã đề phải chứa "${token}" để hệ thống chấm theo thang điểm.`
                }
              />
              <Field label="Cấp độ mục tiêu" maxLength={255} value={form.target_level} onChange={set('target_level')} placeholder="Để trống = theo cấp độ" />
              <Field label="Thời gian làm bài (phút)" type="number" min={10} max={600} required value={form.duration_minutes} onChange={set('duration_minutes')} />
              <Textarea label="Mô tả" rows={2} maxLength={1000} value={form.description} onChange={set('description')} />
              {pdfMode && <Field label="File nghe chung (URL)" maxLength={500} value={form.audio_url} onChange={set('audio_url')} />}
              {editing && (
                <Checkbox label="Đang hoạt động (cho phép làm bài)" checked={form.is_active} onChange={(e) => setForm({ ...form, is_active: e.target.checked })} />
              )}
            </div>
          </Card>
          <Card
            title={
              <span className="flex items-center justify-between">
                Danh sách câu hỏi ({questions.length})
                <Button
                  variant="secondary"
                  className="h-8"
                  onClick={() => {
                    setQuestions([...questions, newQuestion(questions, q?.skill ?? 'reading', q?.type ?? 'multiple_choice')])
                    setCurrent(questions.length)
                  }}
                >
                  Thêm câu
                </Button>
              </span>
            }
          >
            <ol className="max-h-[420px] space-y-1 overflow-y-auto text-sm">
              {questions.map((x, i) => (
                <li key={x.id}>
                  <button
                    type="button"
                    onClick={() => setCurrent(i)}
                    className={`flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left ${i === current ? 'bg-primary-light text-primary' : 'hover:bg-surface-container-low'}`}
                  >
                    <span className="w-12 shrink-0 font-mono text-xs">Câu {x.number || i + 1}</span>
                    <span className="truncate">{x.title || <em className="text-on-surface-subtle">(chưa có nội dung)</em>}</span>
                    <span className="ml-auto shrink-0 text-xs text-on-surface-variant">{QUESTION_SKILLS.find((s) => s.value === x.skill)?.label.split(' ')[0]}</span>
                  </button>
                </li>
              ))}
            </ol>
          </Card>
        </div>

        <div className="space-y-4">
          {q ? (
            <Card
              title={
                <span className="flex flex-wrap items-center justify-between gap-2">
                  Câu {q.number || current + 1}
                  <span className="flex gap-1">
                    <Button variant="secondary" className="h-8" disabled={current === 0} onClick={() => (setQuestions(moveItem(questions, current, current - 1)), setCurrent(current - 1))}>
                      Lên
                    </Button>
                    <Button
                      variant="secondary"
                      className="h-8"
                      disabled={current === questions.length - 1}
                      onClick={() => (setQuestions(moveItem(questions, current, current + 1)), setCurrent(current + 1))}
                    >
                      Xuống
                    </Button>
                    <Button
                      variant="danger"
                      className="h-8"
                      onClick={() => {
                        setQuestions(questions.filter((_, i) => i !== current))
                        setCurrent(Math.max(0, current - 1))
                      }}
                    >
                      Xóa câu
                    </Button>
                  </span>
                </span>
              }
            >
              <QuestionForm q={q} pdfMode={pdfMode} onChange={setQ} />
            </Card>
          ) : (
            <Card>
              <p className="text-sm text-on-surface-variant">Đề chưa có câu hỏi nào. Bấm “Thêm câu” để soạn.</p>
            </Card>
          )}
          {issues.length > 0 && (
            <Alert tone="info">
              <ul className="list-inside list-disc">
                {issues.slice(0, 6).map((m) => (
                  <li key={m}>{m}</li>
                ))}
              </ul>
            </Alert>
          )}
          <div className="flex flex-wrap justify-end gap-2">
            {editing ? (
              <Button disabled={save.isPending} onClick={() => save.mutate('publish')}>
                Lưu thay đổi
              </Button>
            ) : (
              <>
                <Button variant="secondary" disabled={save.isPending} onClick={() => save.mutate('draft')}>
                  Lưu nháp
                </Button>
                <Button disabled={save.isPending} onClick={() => save.mutate('publish')}>
                  Lưu & phát hành
                </Button>
              </>
            )}
          </div>
        </div>
      </div>
    </div>
  )
}

function QuestionForm({ q, pdfMode, onChange }: { q: Question; pdfMode: boolean; onChange: (p: Partial<Question>) => void }) {
  const opts = q.options ?? []
  const setOpt = (i: number, text: string) => onChange({ options: opts.map((o, j) => (j === i ? { ...o, text } : o)) })
  return (
    <div className="space-y-4">
      <div className="grid gap-3 sm:grid-cols-3">
        <Select label="Kỹ năng" value={q.skill} onChange={(e) => onChange({ skill: e.target.value })}>
          {QUESTION_SKILLS.map((s) => (
            <option key={s.value} value={s.value}>
              {s.label}
            </option>
          ))}
        </Select>
        <Select label="Loại câu" value={q.type} onChange={(e) => onChange(changeType(q, e.target.value))}>
          {QUESTION_TYPES.map((t) => (
            <option key={t.value} value={t.value}>
              {t.label}
            </option>
          ))}
        </Select>
        <Field label="Điểm" type="number" min={0} step={0.5} value={q.points ?? 1} onChange={(e) => onChange({ points: e.target.value === '' ? null : Number(e.target.value) })} />
      </div>
      {pdfMode && (
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label="Số câu in trong PDF" maxLength={20} value={q.number ?? ''} onChange={(e) => onChange({ number: e.target.value })} />
          <Field label="Phần (section)" maxLength={255} value={q.section ?? ''} onChange={(e) => onChange({ section: e.target.value })} />
        </div>
      )}
      <Textarea label="Nội dung câu hỏi" rows={2} maxLength={5000} value={q.title} onChange={(e) => onChange({ title: e.target.value })} />
      {(q.skill === 'reading' || q.passage) && (
        <Textarea label="Đoạn văn / ngữ cảnh" rows={3} maxLength={20000} value={q.passage ?? ''} onChange={(e) => onChange({ passage: e.target.value })} />
      )}
      <div className="grid gap-3 sm:grid-cols-2">
        {(q.skill === 'listening' || q.audio_url) && (
          <Field label="File nghe (URL)" maxLength={500} value={q.audio_url ?? ''} onChange={(e) => onChange({ audio_url: e.target.value })} hint="Dán đường dẫn file mp3 (tải file lên chưa hỗ trợ)." />
        )}
        <Field label="Ảnh minh họa (URL)" maxLength={500} value={q.image_url ?? ''} onChange={(e) => onChange({ image_url: e.target.value })} />
      </div>
      {hasOptions(q.type) && (
        <fieldset className="space-y-2">
          <legend className="text-sm font-medium">Phương án (chọn đáp án đúng)</legend>
          {opts.map((o, i) => (
            <div key={i} className="flex items-center gap-2">
              <input
                type="radio"
                name={`correct-${q.id}`}
                aria-label={`Đáp án đúng ${o.key}`}
                className="size-4 accent-primary"
                checked={q.correct_answer === o.key}
                onChange={() => onChange({ correct_answer: o.key })}
              />
              <span className="w-6 font-mono text-sm font-semibold">{o.key}</span>
              <input
                aria-label={`Phương án ${o.key}`}
                className="h-9 flex-1 rounded-lg border border-outline-variant px-3 text-sm outline-none focus:border-primary"
                value={o.text}
                maxLength={2000}
                disabled={q.type === 'true_false'}
                onChange={(e) => setOpt(i, e.target.value)}
              />
              {q.type !== 'true_false' && opts.length > 2 && (
                <button
                  type="button"
                  className="text-xs text-error hover:underline"
                  onClick={() => {
                    const next = opts.filter((_, j) => j !== i).map((x, j) => ({ ...x, key: String.fromCharCode(65 + j) }))
                    onChange({ options: next, correct_answer: q.correct_answer === o.key ? '' : q.correct_answer })
                  }}
                >
                  Bỏ
                </button>
              )}
            </div>
          ))}
          {q.type !== 'true_false' && opts.length < 12 && (
            <Button variant="secondary" className="h-8" onClick={() => onChange({ options: [...opts, { key: String.fromCharCode(65 + opts.length), text: '' }] })}>
              Thêm phương án
            </Button>
          )}
        </fieldset>
      )}
      {q.type === 'fill_blank' && (
        <Field
          label="Đáp án đúng"
          maxLength={1000}
          value={q.correct_answer}
          onChange={(e) => onChange({ correct_answer: e.target.value })}
          hint='Nhiều cách viết được chấp nhận thì ngăn bằng "|", VD: 7 | seven.'
        />
      )}
      {hasAnswerKey(q.type) && (
        <Field label="Giải thích đáp án" maxLength={5000} value={q.explanation ?? ''} onChange={(e) => onChange({ explanation: e.target.value })} />
      )}
      {q.type === 'essay' && (
        <Textarea label="Hướng dẫn chấm bài viết" rows={2} maxLength={2000} value={q.rubric_note ?? ''} onChange={(e) => onChange({ rubric_note: e.target.value })} />
      )}
      {q.type === 'speaking_prompt' && (
        <Textarea label="Gợi ý phỏng vấn Speaking" rows={3} maxLength={5000} value={q.cue_points ?? ''} onChange={(e) => onChange({ cue_points: e.target.value })} />
      )}
      <Textarea label="Ghi chú cho giáo viên (thí sinh không thấy)" rows={2} maxLength={5000} value={q.teacher_note ?? ''} onChange={(e) => onChange({ teacher_note: e.target.value })} />
    </div>
  )
}
