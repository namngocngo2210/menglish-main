import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { errorMessage } from '../../api'
import { Alert, Badge, Card, Field, Select } from '../../components/ui'
import { placementApi, type RubricConfig } from './api'
import { PageHeader, Tabs, Textarea } from './components'
import { evaluate, SKILL_LABELS, SKILLS } from './logic'
import { BASE } from './TestsPage'

// Thang điểm & hướng dẫn nhận xét (Laravel PlacementTests/RubricGuide): công cụ tính thử + bảng băng điểm từng khối.
export function RubricGuidePage() {
  const cfg = useQuery({ queryKey: ['placement', 'rubric'], queryFn: placementApi.rubric })
  if (cfg.isError) return <Alert>{errorMessage(cfg.error)}</Alert>
  if (!cfg.data) return <p className="text-sm text-on-surface-variant">Đang tải…</p>
  return <Guide cfg={cfg.data} />
}

function Guide({ cfg }: { cfg: RubricConfig }) {
  const withRubric = cfg.groups.filter((g) => g.has_rubric)
  const [key, setKey] = useState(withRubric[0]?.key ?? '')
  const [scores, setScores] = useState({ listening: '8', reading_writing: '10', speaking: '6' })
  const [tab, setTab] = useState(withRubric[0]?.key ?? '')
  const g = cfg.groups.find((x) => x.key === key) ?? null
  const ev = evaluate(g, scores)
  const comment = SKILLS.map((s) => ev.suggestions[s] && `${SKILL_LABELS[s]}: ${ev.suggestions[s]}`)
    .filter(Boolean)
    .join('\n')
  const table = cfg.rubrics.find((r) => r.key === tab)

  return (
    <div className="space-y-6">
      <PageHeader
        title="Thang điểm & nhận xét tự động"
        back={BASE}
        description="Hệ thống quy chuẩn điểm số, nhận xét theo từng kỹ năng và gợi ý xếp lớp chuẩn Cambridge YLE (Starters - Movers)."
      />
      <div className="rounded-xl bg-sidebar p-6 text-white">
        <h2 className="text-lg font-semibold">Quy chuẩn đánh giá năng lực đầu vào</h2>
        <p className="mt-1 max-w-3xl text-xs leading-relaxed opacity-80">
          Chấm theo khối lớp: Tổng = Nghe + Đọc & Viết + Nói (điểm thô), tra tổng điểm ra lớp đề xuất; nhận xét từng kỹ năng gợi ý theo băng điểm (người chấm sửa được).
          Nói luôn nhập tay. Khối chưa có thang (lớp 5–9, IELTS, người đi làm, mầm non): {cfg.notice}.
        </p>
      </div>

      <Card
        title={
          <span className="flex flex-wrap items-center justify-between gap-2">
            Công cụ tính điểm & tạo nhận xét trực tiếp
            <Badge tone="info">Dùng đúng thang điểm hệ thống</Badge>
          </span>
        }
      >
        <div className="grid gap-3 sm:grid-cols-2 md:grid-cols-4">
          <Select label="Chọn khối lớp" value={key} onChange={(e) => setKey(e.target.value)}>
            {withRubric.map((x) => (
              <option key={x.key} value={x.key}>
                {x.label}
              </option>
            ))}
          </Select>
          {SKILLS.map((s) => (
            <Field
              key={s}
              label={`Điểm ${SKILL_LABELS[s]}${g ? ` (tối đa ${g.max[s]})` : ''}`}
              type="number"
              step={0.5}
              min={0}
              value={scores[s]}
              onChange={(e) => setScores({ ...scores, [s]: e.target.value })}
            />
          ))}
        </div>
        <div className="mt-4 space-y-3 rounded-lg bg-surface-container-low p-4">
          <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
            <span className="text-xs font-semibold text-on-surface-variant uppercase">Kết quả & nhận xét sinh tự động</span>
            <span className="flex items-center gap-3">
              Tổng điểm: <strong className="font-mono text-primary">{ev.total}</strong>
              <span className="font-mono text-on-surface-subtle">/ {ev.maxTotal}</span>
              <Badge tone="info">{ev.suggested ?? '—'}</Badge>
            </span>
          </div>
          <Textarea aria-label="Nhận xét tự động" rows={4} readOnly value={comment} />
        </div>
      </Card>

      <div className="space-y-4">
        <Tabs value={tab} onChange={setTab} items={cfg.rubrics.map((r) => ({ value: r.key, label: r.label }))} />
        {table && (
          <Card
            title={
              <span className="flex items-center justify-between gap-2">
                Thang điểm {table.label}
                <Badge>{table.level}</Badge>
              </span>
            }
          >
            <div className="overflow-x-auto">
              <table className="w-full min-w-[720px] border-collapse text-xs">
                <thead>
                  <tr className="bg-sidebar text-left text-white">
                    {SKILLS.map((s) => (
                      <th key={s} className="border border-white/10 p-3 font-semibold">
                        {SKILL_LABELS[s]} (tối đa {table.max[s]})
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {Array.from({ length: Math.max(...SKILLS.map((s) => table.bands[s]?.length ?? 0)) }).map((_, i) => (
                    <tr key={i}>
                      {SKILLS.map((s) => {
                        const b = table.bands[s]?.[i]
                        return (
                          <td key={s} className="border border-surface-container-highest p-3 align-top">
                            {b && (
                              <>
                                <div className="mb-1 font-semibold text-primary">{b.range}</div>
                                {b.text}
                              </>
                            )}
                          </td>
                        )
                      })}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <div className="mt-4 rounded-lg border border-warning/30 bg-warning-container/40 p-4">
              <h3 className="mb-2 text-xs font-semibold uppercase">Xếp lớp theo tổng điểm</h3>
              <div className="grid gap-3 sm:grid-cols-3">
                {table.placements.map((p) => (
                  <div key={p.range} className="rounded-lg border border-warning/30 bg-surface p-3 text-center">
                    <span className="block text-xs text-on-surface-variant">{p.range}</span>
                    <span className="text-xs font-semibold">{p.class}</span>
                  </div>
                ))}
              </div>
            </div>
          </Card>
        )}
      </div>
    </div>
  )
}
