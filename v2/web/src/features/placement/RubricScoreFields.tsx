import { Badge, Button, Select } from '../../components/ui'
import type { RubricConfig, RubricInitial, Skill } from './api'
import { Textarea } from './components'
import { evaluate, maxFor, SKILL_LABELS, SKILLS, type Scores } from './logic'

export type RubricForm = {
  group: string
  scores: Scores
  comments: Record<Skill, string>
  edited: Record<Skill, boolean>
  chosen: string
  teacher_comments: string
}

// initialForm: nhận xét đã sửa tay thì giữ; chưa sửa thì theo gợi ý băng điểm (đổi điểm là đổi theo).
export function initialForm(cfg: RubricConfig, init: RubricInitial): RubricForm {
  const scores: Scores = { listening: init.listening, reading_writing: init.reading_writing, speaking: init.speaking }
  const g = cfg.groups.find((x) => x.key === init.group) ?? null
  const ev = evaluate(g, scores)
  const edited = Object.fromEntries(SKILLS.map((s) => [s, !!init.edited[s]])) as Record<Skill, boolean>
  const comments = Object.fromEntries(SKILLS.map((s) => [s, edited[s] ? (init.comments[s] ?? '') : ev.suggestions[s]])) as Record<Skill, string>
  return { group: init.group, scores, comments, edited, chosen: init.chosen, teacher_comments: init.teacher_comments }
}

// withScores: đổi khối / điểm → nhận xét chưa sửa tay chạy theo gợi ý mới.
function resync(cfg: RubricConfig, f: RubricForm): RubricForm {
  const ev = evaluate(cfg.groups.find((x) => x.key === f.group) ?? null, f.scores)
  return { ...f, comments: Object.fromEntries(SKILLS.map((s) => [s, f.edited[s] ? f.comments[s] : ev.suggestions[s]])) as Record<Skill, string> }
}

const tone: Record<Skill, string> = {
  listening: 'border-info/20 bg-info-container/40',
  reading_writing: 'border-success/20 bg-success-container/40',
  speaking: 'border-primary/20 bg-primary-light',
}

// Ô nhập điểm theo thang điểm khối lớp (Laravel RubricScoreFields): Tổng = Nghe + Đọc & Viết + Nói → lớp đề xuất.
export function RubricScoreFields({ cfg, form, onChange }: { cfg: RubricConfig; form: RubricForm; onChange: (f: RubricForm) => void }) {
  const g = cfg.groups.find((x) => x.key === form.group) ?? null
  const ev = evaluate(g, form.scores)
  const hasRubric = !!g?.has_rubric
  const synced = hasRubric && ev.complete && SKILLS.every((s) => !form.edited[s])
  const update = (f: RubricForm) => onChange(resync(cfg, f))

  return (
    <div className="space-y-4 text-sm">
      <div className="flex flex-col gap-3 rounded-lg bg-surface-container-low p-4 sm:flex-row sm:items-end">
        <div className="flex-1">
          <Select label="Khối lớp / Thang điểm tự động *" value={form.group} onChange={(e) => update({ ...form, group: e.target.value })}>
            <option value="">— Chọn khối —</option>
            {cfg.groups.map((x) => (
              <option key={x.key} value={x.key}>
                {x.label}
              </option>
            ))}
          </Select>
        </div>
        <Button
          variant="secondary"
          disabled={!hasRubric}
          onClick={() => {
            const reset = resync(cfg, { ...form, edited: { listening: false, reading_writing: false, speaking: false } })
            onChange({ ...reset, chosen: ev.suggested ?? reset.chosen })
          }}
        >
          Tự động tạo nhận xét & Xếp lớp
        </Button>
      </div>
      {!hasRubric && (
        <p className="rounded-lg border-l-4 border-warning bg-warning-container px-3 py-2 font-medium text-warning">
          {cfg.notice}. Điểm từng kỹ năng nhập theo thang tạm 0–10, không quy đổi ra lớp.
        </p>
      )}

      <div className="grid gap-3 md:grid-cols-3">
        {SKILLS.map((s) => (
          <div key={s} className={`space-y-2 rounded-lg border p-3 ${tone[s]}`}>
            <div className="flex items-center justify-between">
              <label htmlFor={`score_${s}`} className="text-xs font-semibold uppercase">
                Điểm {SKILL_LABELS[s]}
                {s === 'speaking' && <span className="block text-on-surface-variant normal-case">(Nhập tay)</span>}
              </label>
              <span className="font-mono text-xs">{hasRubric ? `/ ${maxFor(g, s)}` : 'điểm'}</span>
            </div>
            <input
              id={`score_${s}`}
              type="number"
              step={0.5}
              min={0}
              max={maxFor(g, s)}
              value={form.scores[s]}
              onChange={(e) => update({ ...form, scores: { ...form.scores, [s]: e.target.value } })}
              className={`w-full rounded-lg border bg-surface p-2 text-center font-mono text-lg ${ev.overMax[s] ? 'border-error ring-2 ring-error/20' : 'border-outline-variant'}`}
            />
            <div className="h-1.5 w-full overflow-hidden rounded-full bg-surface-container-high">
              <div className="h-full rounded-full bg-primary transition-all" style={{ width: `${ev.percent[s]}%` }} />
            </div>
            <p className="min-h-10 text-xs text-on-surface-variant italic">
              {ev.suggestions[s] || `Nhận xét ${SKILL_LABELS[s].toLocaleLowerCase('vi')} sẽ tự động sinh...`}
            </p>
          </div>
        ))}
      </div>

      <div className="space-y-3 rounded-lg border border-surface-container-highest p-4">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <span className="font-semibold">Nhận xét gợi ý (Tự động theo Thang điểm)</span>
          {synced && <Badge tone="success">Đã đồng bộ Thang điểm</Badge>}
        </div>
        {SKILLS.map((s) => (
          <div key={s}>
            <div className="mb-1 flex items-center justify-between">
              <span className="text-xs font-semibold text-on-surface-variant">{SKILL_LABELS[s]}</span>
              {form.edited[s] && ev.suggestions[s] && (
                <button type="button" className="text-xs font-semibold text-primary hover:underline" onClick={() => update({ ...form, edited: { ...form.edited, [s]: false } })}>
                  Dùng lại gợi ý
                </button>
              )}
            </div>
            <Textarea
              aria-label={`Nhận xét ${SKILL_LABELS[s]}`}
              rows={2}
              maxLength={3000}
              value={form.comments[s]}
              placeholder={`Nhận xét ${SKILL_LABELS[s].toLocaleLowerCase('vi')}...`}
              onChange={(e) => onChange({ ...form, comments: { ...form.comments, [s]: e.target.value }, edited: { ...form.edited, [s]: true } })}
            />
          </div>
        ))}
        <Textarea
          label="Ghi chú chung / lời khuyên của người chấm"
          rows={2}
          maxLength={3000}
          value={form.teacher_comments}
          placeholder="Định hướng lộ trình, lưu ý cho tư vấn viên..."
          onChange={(e) => onChange({ ...form, teacher_comments: e.target.value })}
        />
      </div>

      <div className="flex flex-col gap-4 rounded-lg bg-sidebar p-4 text-white sm:flex-row sm:items-center">
        <div className="flex items-center gap-3">
          <div className="flex size-14 items-center justify-center rounded-full bg-brand text-lg font-semibold">{ev.complete ? ev.total : '—'}</div>
          <div>
            <p className="text-xs uppercase opacity-80">Tổng điểm hệ thống</p>
            <p className="text-lg font-semibold">
              {ev.complete ? ev.total : '—'} <span className="text-sm opacity-70">{hasRubric ? `/ ${ev.maxTotal} điểm` : 'điểm'}</span>
            </p>
          </div>
        </div>
        <div className="flex-1">
          <p className="text-xs uppercase opacity-80">Đề xuất xếp lớp tự động</p>
          <p className="font-semibold">{hasRubric ? (ev.suggested ?? 'Nhập đủ 3 kỹ năng để tra lớp') : 'Không có — chọn lớp thủ công'}</p>
        </div>
      </div>

      <div className="space-y-1.5">
        <label htmlFor="chosen_class" className="block text-sm font-medium">
          Lớp xếp cho học viên {!hasRubric && <span className="text-error">*</span>}
          {hasRubric && <span className="text-xs font-normal text-on-surface-variant"> (để trống = theo lớp đề xuất; có thể chọn lại)</span>}
        </label>
        <input
          id="chosen_class"
          list="rubric-class-options"
          maxLength={255}
          value={form.chosen}
          placeholder={ev.suggested ?? 'Nhập / chọn lớp phù hợp'}
          onChange={(e) => onChange({ ...form, chosen: e.target.value })}
          className="block h-10 w-full rounded-lg border border-outline-variant px-3 text-sm text-primary outline-none focus:border-primary"
        />
        <datalist id="rubric-class-options">
          {cfg.class_options.map((c) => (
            <option key={c} value={c} />
          ))}
        </datalist>
      </div>
    </div>
  )
}
