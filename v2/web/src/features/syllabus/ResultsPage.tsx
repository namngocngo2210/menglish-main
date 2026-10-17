// Bảng điểm Big Test (SyllabusController::bigTestResults): /syllabus/big-tests/results[/<id>], ?result= mở chi tiết.
// GV (syllabus.update) nhập điểm 4 kỹ năng / vắng thi / nhận xét / video rồi Lưu nháp hoặc Gửi duyệt; người duyệt chỉ xem,
// Admin duyệt và gửi phụ huynh qua Zalo.
import { useQuery } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { errorMessage } from '../../api'
import { Alert, Badge, Button, Dialog, Icon, Select } from '../../components/ui'
import { navigate, usePath, useQueryParam } from '../../router'
import { syllabusApi, type ResultsPage as Page } from './api'
import { EmptyRow, ErrorText, Info, NoticeBar, PageHeader, setParam, StatCard, TableCard, useAction } from './components'
import { daysLeftLabel, draftOf, overallOf, resultsPayload, resultTone, SKILL_LABELS, SKILLS, type Draft } from './logic'

const BASE = '/syllabus/big-tests/results'

export function ResultsPage() {
  const testId =
    usePath()
      .slice(BASE.length + 1)
      .split('/')[0] ?? ''
  const resultId = useQueryParam('result')
  const page = useQuery({
    queryKey: ['syllabus', 'results', testId, resultId],
    queryFn: () => syllabusApi.results(testId || undefined, resultId || undefined),
    // Không tự nạp lại khi quay lại tab: giữ điểm đang nhập dở.
    refetchOnWindowFocus: false,
  })
  const d = page.data
  const t = d?.test
  const [drafts, setDrafts] = useState<Record<string, Draft>>({})
  useEffect(() => {
    if (d) setDrafts(Object.fromEntries(d.rows.map((r) => [r.student_id, draftOf(r.result)])))
  }, [d])
  const store = useAction((action: 'draft' | 'submit') =>
    syllabusApi.storeResults(
      t!.id,
      action,
      resultsPayload(
        d!.rows.map((r) => ({
          student_id: r.student_id,
          locked: !d!.can_grade || !!r.result?.locked,
          draft: drafts[r.student_id] ?? draftOf(null),
        })),
      ),
    ),
  )
  const approve = useAction(syllabusApi.approveResults)
  const zalo = useAction(syllabusApi.sendZalo)
  const single = useAction(syllabusApi.sendSingle)
  const approveSend = useAction(syllabusApi.approveSend, () => setParam('result', null))
  const actions = [store, approve, zalo, single, approveSend]
  const notice = actions.find((a) => a.notice)?.notice ?? null
  const error = actions.map((a) => a.error).find(Boolean) ?? ''
  const set = (id: string, patch: Partial<Draft>) =>
    setDrafts((all) => ({
      ...all,
      [id]: { ...(all[id] ?? draftOf(null)), ...patch },
    }))
  const hasUnlocked = !!d?.rows.some((r) => !r.result?.locked)

  return (
    <>
      <PageHeader
        title={d?.is_approver ? 'Duyệt kết quả Big Test & gửi phụ huynh' : 'Nhập điểm Big Test'}
        back={d?.can_back ? '/syllabus/big-tests/distribution' : undefined}
        description={
          d?.is_approver
            ? 'Bảng điểm 4 kỹ năng, nhận xét, link video; Học thuật duyệt và gửi kết quả cho phụ huynh qua Zalo.'
            : 'Nhập điểm 4 kỹ năng, nhận xét, link video cho lớp mình dạy rồi gửi Học thuật duyệt.'
        }
        actions={
          <>
            {t && d?.is_approver && (
              <>
                {d.can_approve && (
                  <Button disabled={approve.pending} onClick={() => approve.run(t.id)}>
                    Duyệt kết quả
                  </Button>
                )}
                <Button
                  variant="secondary"
                  title="Gửi các kết quả đã duyệt cho phụ huynh qua Zalo"
                  disabled={zalo.pending}
                  onClick={() => zalo.run(t.id)}
                >
                  Gửi phụ huynh
                </Button>
              </>
            )}
            <Button variant="secondary" onClick={() => window.print()}>
              In bảng điểm
            </Button>
          </>
        }
      />
      <NoticeBar notice={notice} onClose={() => actions.forEach((a) => a.setNotice(null))} />
      <ErrorText error={error} />
      {page.isError && <Alert>{errorMessage(page.error)}</Alert>}
      {d && (
        <div className="space-y-5">
          <div className="grid grid-cols-1 gap-4 lg:grid-cols-4">
            <div className="space-y-3 rounded-xl border border-surface-container-highest bg-surface p-4 shadow-sm lg:col-span-2">
              <Select label="Chọn Kỳ Thi Big Test:" value={t?.id ?? ''} onChange={(e) => e.target.value && navigate(`${BASE}/${e.target.value}`)}>
                {!d.all_tests.length && <option value="">Chưa có kỳ thi Big Test nào</option>}
                {d.all_tests.map((o) => (
                  <option key={o.value} value={o.value}>
                    {o.label}
                  </option>
                ))}
              </Select>
              {t && (
                <div className="flex flex-wrap items-center gap-2 font-mono text-xs text-on-surface-variant">
                  <span className="rounded-md bg-primary-light px-2 py-0.5 font-bold text-primary">Lớp: {t.class_name}</span>
                  <span className="rounded-md bg-surface-container px-2 py-0.5">Mã: {t.code}</span>
                  <span className="rounded-md bg-surface-container px-2 py-0.5">Phòng: {t.room}</span>
                  {t.results_due && (
                    <span
                      className={`rounded-md px-2 py-0.5 ${t.results_overdue ? 'bg-error-container font-bold text-error' : 'bg-warning-container text-warning'}`}
                      title={`Hạn trả kết quả = ngày thi + ${d.result_deadline_days} ngày`}
                    >
                      Hạn trả KQ: {t.results_due}
                    </span>
                  )}
                  {t.stage_badge && (
                    <span
                      className="rounded-md bg-primary-light px-2 py-0.5 font-bold text-primary"
                      title="Duyệt và gửi đủ kết quả cho phụ huynh sẽ đóng chặng này và tự mở chặng kế tiếp"
                    >
                      {t.stage_badge}
                    </span>
                  )}
                </div>
              )}
            </div>
            <StatCard
              label="Điểm Trung Bình Cả Lớp"
              tone="primary"
              value={<>{d.stats.avg} / 10</>}
              hint={`Dựa trên ${d.stats.taken} học viên dự thi (${d.stats.absent} vắng)`}
            />
            <StatCard
              label="Điểm Cao Nhất (Top Score)"
              tone="success"
              value={<>{d.stats.highest} / 10</>}
              hint={`Tổng số thí sinh: ${d.stats.total} học viên`}
            />
          </div>

          <TableCard
            header={
              <div>
                <h2 className="text-xs font-bold tracking-wider uppercase">Danh Sách Bảng Điểm Chi Tiết ({d.stats.total} Học viên)</h2>
                <p className="text-xs text-on-surface-variant">Kết quả khảo thí định kỳ được lưu trữ phục vụ xếp lớp và đánh giá năng lực</p>
              </div>
            }
            footer={
              d.can_grade &&
              hasUnlocked && (
                <div className="mt-3 flex flex-wrap items-center justify-between gap-3 border-t border-surface-container-highest pt-3">
                  <span className="max-w-2xl text-xs text-on-surface-variant">
                    Học viên vắng: tích "Vắng thi" (không nhập điểm). Dòng để trống sẽ bỏ qua. "Lưu nháp" chưa gửi Học thuật (sửa tiếp được); "Gửi
                    duyệt" cần đủ 4 kỹ năng. Điểm đã duyệt/đã gửi phụ huynh không thể sửa.
                  </span>
                  <span className="flex gap-2">
                    <Button variant="secondary" disabled={store.pending} onClick={() => store.run('draft')}>
                      Lưu nháp
                    </Button>
                    <Button disabled={store.pending} onClick={() => store.run('submit')}>
                      Gửi duyệt
                    </Button>
                  </span>
                </div>
              )
            }
          >
            {t && !t.is_distributed && d.can_grade_role && (
              <p className="mb-3 rounded-lg bg-warning-container px-3 py-2 text-xs">
                Đề thi của đợt này chưa được duyệt và phân phối, chưa nhập điểm được.
              </p>
            )}
            <table className="w-full min-w-[1100px] text-left text-xs">
              <thead className="text-on-surface-variant">
                <tr>
                  <th className="py-2 pr-3 font-medium">Học viên &amp; Mã số</th>
                  <th className="py-2 pr-3 font-medium">Trạng thái</th>
                  <th className="px-1 py-2 text-center font-medium">Vắng thi</th>
                  {SKILLS.map((k) => (
                    <th key={k} className="px-1 py-2 text-center font-medium">
                      {SKILL_LABELS[k]}
                    </th>
                  ))}
                  <th className="bg-primary-light px-2 py-2 text-center font-medium text-primary">Overall</th>
                  <th className="py-2 pr-3 pl-2 font-medium">Nhận xét &amp; video</th>
                  <th className="py-2 font-medium">Đã gửi PH</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-surface-container-highest">
                {d.rows.map((row) => {
                  const r = row.result
                  const locked = !d.can_grade || !!r?.locked
                  const dr = drafts[row.student_id] ?? draftOf(r)
                  const overall = r && locked ? r.overall_score : overallOf(dr)
                  return (
                    <tr key={row.student_id} className="align-top">
                      <td className="py-2 pr-3">
                        <div className="font-bold">{row.name}</div>
                        <div className="max-w-[200px] truncate font-mono text-on-surface-variant">Mã HV: {row.code}</div>
                      </td>
                      <td className="py-2 pr-3 whitespace-nowrap">
                        <Badge tone={resultTone(r?.status)}>
                          {r?.status === 'draft' ? 'Nháp (GV chưa gửi duyệt)' : (r?.status_label ?? 'Chưa nhập')}
                        </Badge>
                        {r && r.status !== 'draft' && t && (
                          <button
                            type="button"
                            className="mt-1 flex items-center gap-0.5 font-semibold text-primary hover:underline"
                            onClick={() => setParam('result', r.id)}
                          >
                            <Icon name="rate_review" className="text-[14px]" />
                            Xem &amp; duyệt
                          </button>
                        )}
                      </td>
                      <td className="px-1 py-2 text-center">
                        <input
                          type="checkbox"
                          className="size-4 accent-error"
                          aria-label={`Vắng thi — ${row.name}`}
                          disabled={locked}
                          checked={dr.is_absent}
                          onChange={(e) =>
                            set(row.student_id, {
                              is_absent: e.target.checked,
                            })
                          }
                        />
                      </td>
                      {SKILLS.map((k) => (
                        <td key={k} className="px-1 py-2 text-center">
                          <input
                            type="number"
                            step="0.1"
                            min={0}
                            max={10}
                            placeholder="—"
                            aria-label={`Điểm ${SKILL_LABELS[k]} — ${row.name}`}
                            disabled={locked || dr.is_absent}
                            value={dr[k]}
                            onChange={(e) =>
                              set(row.student_id, {
                                [k]: e.target.value,
                              })
                            }
                            className="w-16 rounded border border-outline-variant px-1 py-1 text-xs disabled:bg-surface-container-low"
                          />
                        </td>
                      ))}
                      <td className="bg-primary-light px-2 py-2 text-center font-mono text-base font-black text-primary">
                        {dr.is_absent ? <span className="text-xs font-bold text-error">Vắng thi</span> : (overall ?? '—')}
                      </td>
                      <td className="min-w-[180px] space-y-1 py-2 pr-3 pl-2">
                        <textarea
                          rows={2}
                          disabled={locked}
                          placeholder="Nhận xét tiến độ"
                          aria-label={`Nhận xét tiến độ — ${row.name}`}
                          value={dr.progress_note}
                          onChange={(e) =>
                            set(row.student_id, {
                              progress_note: e.target.value,
                            })
                          }
                          className="w-full rounded border border-outline-variant px-1 py-1 text-xs disabled:bg-surface-container-low"
                        />
                        {locked ? (
                          r?.video_url && (
                            <a
                              href={r.video_url}
                              target="_blank"
                              rel="noopener noreferrer"
                              className="inline-flex items-center gap-1 font-semibold text-primary hover:underline"
                            >
                              <Icon name="video_library" className="text-[14px]" />
                              Link video bài thi
                            </a>
                          )
                        ) : (
                          <input
                            type="url"
                            placeholder="Link video bài thi (https://...)"
                            aria-label={`Link video bài thi — ${row.name}`}
                            value={dr.video_url}
                            onChange={(e) =>
                              set(row.student_id, {
                                video_url: e.target.value,
                              })
                            }
                            className="w-full rounded border border-outline-variant px-1 py-1 text-xs"
                          />
                        )}
                      </td>
                      <td className="py-2 whitespace-nowrap">
                        {r?.parent_notified ? (
                          <span className="inline-flex items-center gap-1 font-semibold text-success">
                            <Icon name="mark_email_read" className="text-[16px]" />
                            {r.notified_at || 'Đã gửi'}
                          </span>
                        ) : t && d.is_approver && r?.status === 'approved' && !r.is_absent ? (
                          <Button variant="secondary" className="h-8 px-3" disabled={single.pending} onClick={() => single.run(r.id)}>
                            Gửi PH
                          </Button>
                        ) : (
                          <span className="text-on-surface-variant">{r?.is_absent ? 'Vắng thi' : 'Chưa gửi'}</span>
                        )}
                        {r?.missing_phone && <span className="mt-1 block font-semibold text-error">{d.missing_phone_label}</span>}
                      </td>
                    </tr>
                  )
                })}
                {!d.rows.length && <EmptyRow colSpan={10} icon="sentiment_neutral" title="Chưa có kết quả thi cho kỳ thi Big Test này." />}
              </tbody>
            </table>
          </TableCard>
        </div>
      )}
      {d && t && d.selected_result && <ResultDialog d={d} onApproveSend={(id) => approveSend.run(id)} pending={approveSend.pending} />}
    </>
  )
}

function ResultDialog({ d, onApproveSend, pending }: { d: Page; onApproveSend: (id: string) => void; pending: boolean }) {
  const r = d.selected_result!
  const t = d.test!
  return (
    <Dialog title={`Kết quả Big Test · ${r.student || 'Học viên'}`} onClose={() => setParam('result', null)} wide>
      <div className="space-y-4 text-sm">
        <dl className="grid grid-cols-1 gap-3 rounded-lg border border-outline-variant bg-surface-container-low p-3 sm:grid-cols-3">
          <Info label="Học viên">
            <span className="font-semibold">{r.student}</span>
            <span className="block text-xs text-on-surface-variant">Mã HV: {r.student_code}</span>
          </Info>
          <Info label="Lớp học">
            {t.class_name}
            <span className="block text-xs text-on-surface-variant">GV: {t.class_teacher ?? '—'}</span>
          </Info>
          <Info label="Chặng học">
            <Badge tone="info">{t.stage_label}</Badge>
          </Info>
        </dl>
        <section>
          <h3 className="mb-2 text-xs font-medium text-on-surface-variant uppercase">Điểm chi tiết</h3>
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
            {SKILLS.map((k) => (
              <div key={k} className="flex items-center justify-between rounded-lg border border-outline-variant bg-surface-container-low px-3 py-2">
                <span className="text-on-surface-variant">{SKILL_LABELS[k]}</span>
                <span className="text-base font-semibold">{r.is_absent ? '—' : (r[k] ?? '—')}</span>
              </div>
            ))}
          </div>
          <p className="mt-3 mb-1 text-xs font-medium text-on-surface-variant">Link video bài thi</p>
          {r.video_url ? (
            <a href={r.video_url} target="_blank" rel="noopener noreferrer" className="block truncate text-primary hover:underline">
              {r.video_url}
            </a>
          ) : (
            <p className="text-on-surface-variant">Chưa có link video.</p>
          )}
        </section>
        <section>
          <h3 className="mb-2 text-xs font-medium text-on-surface-variant uppercase">Nhận xét của giáo viên/HT</h3>
          <div className="rounded-lg bg-surface-container-low p-3 whitespace-pre-line italic">{r.progress_note || 'Chưa có nhận xét.'}</div>
        </section>
        <section className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <div className="space-y-2 rounded-lg border border-outline-variant p-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <h3 className="font-semibold">Tổng điểm (Big Test)</h3>
              {t.days_left !== null && <Badge tone={t.days_left < 0 ? 'error' : 'warning'}>{daysLeftLabel(t.days_left)}</Badge>}
            </div>
            <p className="text-3xl font-bold text-primary">{r.is_absent ? 'Vắng thi' : (r.overall_score ?? '—')}</p>
            <p className="text-xs text-on-surface-variant">Trạng thái dữ liệu</p>
            <p className="font-semibold">{r.status_label}</p>
          </div>
          <div className="space-y-2 rounded-lg border border-outline-variant p-3">
            <Info label="Người gửi kết quả">{r.grader ?? '—'}</Info>
            <Info label={r.approver ? 'Người duyệt' : 'Người duyệt (Hiện tại)'}>{r.approver ?? 'Bạn'}</Info>
          </div>
        </section>
        {r.parent_notified ? (
          <Alert tone="success">
            <strong>Đã gửi phụ huynh</strong> — {r.notified_at}, kết quả đã ghi nhận vào hồ sơ học tập của học viên.
          </Alert>
        ) : (
          r.status !== 'draft' && <Alert tone="success">Hợp lệ — thông tin sẽ được ghi nhận vào hệ thống học tập của học viên.</Alert>
        )}
        {d.can_approve && (r.status === 'pending_review' || r.status === 'approved') && !r.parent_notified && (
          <div className="flex justify-end">
            <Button disabled={pending} onClick={() => onApproveSend(r.id)}>
              {r.is_absent ? 'Duyệt (vắng thi)' : 'Duyệt & Gửi phụ huynh'}
            </Button>
          </div>
        )}
      </div>
    </Dialog>
  )
}
