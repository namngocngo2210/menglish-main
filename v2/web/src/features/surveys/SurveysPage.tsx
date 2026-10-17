import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent, type MouseEvent } from 'react'
import { errorMessage, type Me } from '../../api'
import { Alert, Badge, Button, Checkbox, Dialog, Field, Icon } from '../../components/ui'
import { navigate, usePath } from '../../router'
import { Textarea } from '../crm/components'
import { formatDateTime } from '../platform/format'
import { EmptyState, PageHeader } from '../platform/parts'
import { surveysApi, type Survey } from './api'
import { deleteMessage, emptyForm, formatAverage, formError, formOf, payloadOf, stars, surveyRoute, SURVEYS_PATH, type SurveyForm } from './logic'

// Đợt khảo sát (SurveyController): học vụ tạo đợt + hạn, sửa / đóng / xóa; học viên / phụ huynh nộp ở Cổng PH/HS.
// /surveys/<id>: bài đã nộp của đợt (bổ sung so với bản Laravel).
export function SurveysPage(_: { me: Me }) {
  const r = surveyRoute(usePath())
  return r.kind === 'responses' ? <ResponsesView id={r.id} /> : <ListView />
}

const th = 'px-3 py-2 text-left text-xs font-medium text-on-surface-variant'
const td = 'px-3 py-2 align-top'

function go(e: MouseEvent, href: string) {
  if (e.metaKey || e.ctrlKey) return
  e.preventDefault()
  navigate(href)
}

function ListView() {
  const qc = useQueryClient()
  const list = useQuery({ queryKey: ['surveys'], queryFn: surveysApi.list })
  const [editing, setEditing] = useState<Survey | 'new' | null>(null)
  const [removing, setRemoving] = useState<Survey | null>(null)
  const [notice, setNotice] = useState('')
  const done = (msg: string) => {
    setNotice(msg)
    setEditing(null)
    setRemoving(null)
    void qc.invalidateQueries({ queryKey: ['surveys'] })
  }
  const remove = useMutation({ mutationFn: (id: string) => surveysApi.remove(id), onSuccess: (r) => done(r.message) })

  return (
    <>
      <PageHeader
        title="Quản lý Đợt Khảo sát Chất lượng"
        description="Học viên / phụ huynh thấy đợt đang mở và chấm sao, góp ý tại Cổng PH/HS."
        actions={
          <Button onClick={() => setEditing('new')}>
            <Icon name="add_circle" className="mr-1 text-[18px]" /> Tạo đợt khảo sát
          </Button>
        }
      />
      {notice && (
        <div className="mb-4">
          <Alert tone="success">{notice}</Alert>
        </div>
      )}
      {list.isError && <Alert>{errorMessage(list.error)}</Alert>}
      {list.data && (
        <div className="overflow-x-auto rounded-xl border border-outline-variant bg-surface shadow-sm">
          <table className="w-full text-sm">
            <thead className="border-b border-surface-container">
              <tr>
                <th className={th}>Tiêu đề</th>
                <th className={th}>Hạn</th>
                <th className={th}>Trạng thái</th>
                <th className={th}>Người tạo</th>
                <th className={th}>Bài đã nộp</th>
                <th className={`${th} text-right`}>Thao tác</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-surface-container">
              {list.data.surveys.map((sv) => (
                <tr key={sv.id}>
                  <td className={`${td} max-w-md`}>
                    <p className="font-semibold">{sv.title}</p>
                    {sv.description && <p className="line-clamp-2 text-xs text-on-surface-variant">{sv.description}</p>}
                  </td>
                  <td className={`${td} whitespace-nowrap text-on-surface-variant tabular-nums`}>
                    {sv.deadline_label ?? '—'}
                    {sv.deadline_today && <span className="font-bold text-error"> • Hôm nay</span>}
                  </td>
                  <td className={td}>
                    <Badge tone={sv.is_active ? 'success' : 'neutral'}>{sv.is_active ? 'Đang mở' : 'Đã đóng'}</Badge>
                  </td>
                  <td className={`${td} text-on-surface-variant`}>{sv.creator_name ?? '—'}</td>
                  <td className={td}>
                    <a href={`${SURVEYS_PATH}/${sv.id}`} onClick={(e) => go(e, `${SURVEYS_PATH}/${sv.id}`)} className="font-medium text-primary hover:underline">
                      {sv.response_count} bài
                    </a>
                  </td>
                  <td className={`${td} whitespace-nowrap text-right`}>
                    <Button variant="secondary" className="h-8" onClick={() => setEditing(sv)}>
                      Sửa
                    </Button>{' '}
                    <Button variant="danger" className="h-8" onClick={() => setRemoving(sv)}>
                      Xóa
                    </Button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          {list.data.surveys.length === 0 && <EmptyState icon="ballot" title="Chưa có đợt khảo sát nào." />}
        </div>
      )}
      {editing && list.data && (
        <SurveyDialog survey={editing === 'new' ? null : editing} today={list.data.today} onClose={() => setEditing(null)} onDone={done} />
      )}
      {removing && (
        <Dialog title="Xóa khảo sát" onClose={() => setRemoving(null)}>
          <p className="text-sm">{deleteMessage(removing)}</p>
          {remove.isError && (
            <div className="mt-3">
              <Alert>{errorMessage(remove.error)}</Alert>
            </div>
          )}
          <div className="mt-4 flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setRemoving(null)}>
              Hủy
            </Button>
            <Button variant="danger" disabled={remove.isPending} onClick={() => remove.mutate(removing.id)}>
              Xóa
            </Button>
          </div>
        </Dialog>
      )}
    </>
  )
}

function SurveyDialog({ survey, today, onClose, onDone }: { survey: Survey | null; today: string; onClose: () => void; onDone: (msg: string) => void }) {
  const editing = survey !== null
  const [form, setForm] = useState<SurveyForm>(survey ? formOf(survey) : emptyForm())
  const [error, setError] = useState<string | null>(null)
  const save = useMutation({
    mutationFn: () => (survey ? surveysApi.update(survey.id, payloadOf(form, true)) : surveysApi.create(payloadOf(form, false))),
    onSuccess: (r) => onDone(r.message),
  })
  const set = (patch: Partial<SurveyForm>) => setForm({ ...form, ...patch })
  function submit(e: FormEvent) {
    e.preventDefault()
    const err = formError(form, today, editing)
    setError(err)
    if (!err) save.mutate()
  }
  return (
    <Dialog title={editing ? 'Sửa khảo sát' : 'Tạo đợt khảo sát mới'} onClose={onClose}>
      <form className="space-y-4" onSubmit={submit} noValidate>
        <Field
          label="Tiêu đề khảo sát"
          required
          maxLength={255}
          value={form.title}
          placeholder={editing ? undefined : 'VD: Đánh giá chất lượng cơ sở vật chất tháng 10'}
          onChange={(e) => set({ title: e.target.value })}
          autoFocus
        />
        <Field label="Hạn hoàn thành" type="date" min={editing ? undefined : today} value={form.deadline} onChange={(e) => set({ deadline: e.target.value })} />
        <Textarea
          label={editing ? 'Mô tả / câu hỏi hướng dẫn' : 'Mô tả / câu hỏi hướng dẫn (tùy chọn)'}
          rows={3}
          maxLength={2000}
          value={form.description}
          placeholder={editing ? undefined : 'VD: Đánh giá phòng học, thiết bị, thái độ hỗ trợ của học vụ...'}
          onChange={(e) => set({ description: e.target.value })}
        />
        {editing && <Checkbox label="Đang mở" checked={form.is_active} onChange={(e) => set({ is_active: e.target.checked })} />}
        {(error || save.isError) && <Alert>{error ?? errorMessage(save.error)}</Alert>}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            Hủy
          </Button>
          <Button type="submit" disabled={save.isPending}>
            {editing ? 'Lưu' : 'Tạo khảo sát'}
          </Button>
        </div>
      </form>
    </Dialog>
  )
}

function ResponsesView({ id }: { id: string }) {
  const q = useQuery({ queryKey: ['surveys', 'responses', id], queryFn: () => surveysApi.responses(id) })
  const d = q.data
  return (
    <>
      <PageHeader
        title={d ? d.survey.title : 'Bài khảo sát đã nộp'}
        description={d ? `${d.responses.length} bài đã nộp · Điểm trung bình ${formatAverage(d.average)}` : undefined}
        actions={
          <a
            href={SURVEYS_PATH}
            onClick={(e) => go(e, SURVEYS_PATH)}
            className="inline-flex h-10 items-center gap-1 rounded-lg border border-outline-variant px-4 text-sm font-medium hover:bg-surface-container-low"
          >
            <Icon name="arrow_back" className="text-[18px]" /> Đợt khảo sát
          </a>
        }
      />
      {q.isError && <Alert>{errorMessage(q.error)}</Alert>}
      {d && (
        <div className="overflow-x-auto rounded-xl border border-outline-variant bg-surface shadow-sm">
          <table className="w-full text-sm">
            <thead className="border-b border-surface-container">
              <tr>
                <th className={th}>Học viên</th>
                <th className={th}>Người gửi</th>
                <th className={th}>Đánh giá</th>
                <th className={th}>Góp ý</th>
                <th className={th}>Gửi lúc</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-surface-container">
              {d.responses.map((r) => (
                <tr key={r.id}>
                  <td className={td}>
                    <p className="font-medium">{r.student_name}</p>
                    <p className="text-xs text-on-surface-variant">{r.student_code}</p>
                  </td>
                  <td className={`${td} text-on-surface-variant`}>{r.submitter ?? '—'}</td>
                  <td className={`${td} whitespace-nowrap text-warning`} aria-label={`${r.rating} sao`}>
                    {stars(r.rating)}
                  </td>
                  <td className={`${td} max-w-lg break-words whitespace-pre-line`}>{r.feedback}</td>
                  <td className={`${td} whitespace-nowrap text-on-surface-variant`}>{formatDateTime(r.created_at)}</td>
                </tr>
              ))}
            </tbody>
          </table>
          {d.responses.length === 0 && <EmptyState icon="ballot" title="Chưa có bài khảo sát nào." />}
        </div>
      )}
    </>
  )
}
