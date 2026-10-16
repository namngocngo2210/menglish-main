// Trình độ (CEFR) — CourseLevelController của Laravel; sắp xếp bằng nút lên / xuống thay cho kéo thả.
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { errorMessage, type Me } from '../../api'
import { Alert, Badge, Button, Card, Checkbox, Dialog, Field, Select } from '../../components/ui'
import { can } from '../../nav'
import { catalogsApi, type Level, type LevelInput, type Option } from './api'
import { Empty, Pager, PageTitle, Stat, Table, TextArea, actionsTd, intOrNull, td, useAction } from './common'
import { moveItem } from './format'

export function LevelsPage({ me }: { me: Me }) {
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [group, setGroup] = useState('')
  const [page, setPage] = useState(1)
  const [editing, setEditing] = useState<Level | 'new' | null>(null)
  const list = useQuery({
    queryKey: ['course-levels', search, status, group, page],
    queryFn: () => catalogsApi.levels({ search, status, group, page }),
  })
  const act = useAction([['course-levels'], ['courses']])
  const d = list.data
  const reorder = d?.can_reorder && can(me, 'level.update')

  const move = (i: number, delta: number) => {
    if (!d) return
    const ids = moveItem(
      d.items.map((l) => l.id),
      i,
      delta,
    )
    act.run(() => catalogsApi.reorderLevels(ids))
  }
  const filter = (fn: () => void) => {
    fn()
    setPage(1)
  }

  return (
    <Card
      title={
        <PageTitle title="Trình độ (CEFR)">
          {can(me, 'level.create') && <Button onClick={() => setEditing('new')}>Thêm trình độ</Button>}
        </PageTitle>
      }
    >
      <div className="space-y-4">
        {d && (
          <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
            <Stat label="Tổng trình độ" value={d.stats.total} />
            <Stat label="Đang hoạt động" value={d.stats.active} />
            <Stat label="Đã gắn giáo trình" value={d.stats.syllabus} />
            <Stat label="Nhóm trình độ" value={d.stats.groups} />
          </div>
        )}
        <div className="grid gap-3 md:grid-cols-3">
          <Field label="Tìm kiếm" placeholder="Mã, tên hoặc mục tiêu" value={search} onChange={(e) => filter(() => setSearch(e.target.value))} />
          <Select label="Nhóm" value={group} onChange={(e) => filter(() => setGroup(e.target.value))}>
            <option value="">Tất cả nhóm</option>
            {d?.groups.map((g) => (
              <option key={g} value={g}>
                {g}
              </option>
            ))}
          </Select>
          <Select label="Trạng thái" value={status} onChange={(e) => filter(() => setStatus(e.target.value))}>
            <option value="">Tất cả</option>
            <option value="active">Đang hoạt động</option>
            <option value="inactive">Tạm ngưng</option>
          </Select>
        </div>
        {list.isError && <Alert>{errorMessage(list.error)}</Alert>}
        {act.notice}
        {d && d.items.length === 0 && <Empty>Chưa có trình độ nào.</Empty>}
        {d && d.items.length > 0 && (
          <Table head={['Mã', 'Tên trình độ', 'Nhóm', 'Cấp độ đầu vào', 'Mục tiêu', 'Số buổi', 'Đang dùng', 'Trạng thái', '']}>
            {d.items.map((l, i) => (
              <tr key={l.id}>
                <td className={`${td} font-mono`}>{l.code}</td>
                <td className={td}>
                  <p className="font-medium">{l.name}</p>
                  {l.description && <p className="text-xs text-on-surface-variant">{l.description}</p>}
                </td>
                <td className={td}>{l.level_group ?? '—'}</td>
                <td className={td}>{l.grade_labels.length ? l.grade_labels.join(', ') : '—'}</td>
                <td className={td}>{l.target}</td>
                <td className={td}>{l.lessons_count}</td>
                <td className={`${td} text-xs text-on-surface-variant`}>
                  {l.courses_count} khóa · {l.classes_count} lớp · {l.students_using} học viên
                </td>
                <td className={td}>{l.is_active ? <Badge tone="success">Hoạt động</Badge> : <Badge>Tạm ngưng</Badge>}</td>
                <td className={actionsTd}>
                  {reorder && (
                    <>
                      <Button variant="secondary" aria-label="Chuyển lên" disabled={i === 0 || act.pending} onClick={() => move(i, -1)}>
                        ↑
                      </Button>
                      <Button
                        variant="secondary"
                        aria-label="Chuyển xuống"
                        disabled={i === d.items.length - 1 || act.pending}
                        onClick={() => move(i, 1)}
                      >
                        ↓
                      </Button>
                    </>
                  )}
                  {can(me, 'level.update') && (
                    <>
                      <Button variant="secondary" onClick={() => setEditing(l)}>
                        Sửa
                      </Button>
                      <Button variant="secondary" onClick={() => act.run(() => catalogsApi.toggleLevel(l.id))}>
                        {l.is_active ? 'Tạm ngưng' : 'Kích hoạt'}
                      </Button>
                    </>
                  )}
                  {can(me, 'level.delete') && (
                    <Button variant="danger" onClick={() => act.run(() => catalogsApi.deleteLevel(l.id), `Xóa trình độ ${l.code} – ${l.name}?`)}>
                      Xóa
                    </Button>
                  )}
                </td>
              </tr>
            ))}
          </Table>
        )}
        {d && !d.can_reorder && can(me, 'level.update') && (
          <p className="text-xs text-on-surface-variant">Bỏ bộ lọc để sắp xếp lại thứ tự trình độ.</p>
        )}
        {d && <Pager page={d.page} total={d.total} size={d.page_size} onPage={setPage} />}
      </div>
      {editing && (
        <LevelDialog
          level={editing === 'new' ? null : editing}
          grades={d?.grade_levels ?? []}
          groups={d?.groups ?? []}
          onClose={() => setEditing(null)}
          onSaved={(msg) => act.setNotice({ tone: 'success', text: msg })}
        />
      )}
    </Card>
  )
}

function LevelDialog({
  level,
  grades,
  groups,
  onClose,
  onSaved,
}: {
  level: Level | null
  grades: Option[]
  groups: string[]
  onClose: () => void
  onSaved: (msg: string) => void
}) {
  const qc = useQueryClient()
  const [form, setForm] = useState({
    code: level?.code ?? '',
    name: level?.name ?? '',
    description: level?.description ?? '',
    level_group: level?.level_group ?? '',
    grade_levels: level?.grade_levels ?? [],
    target: level?.target ?? '',
    lessons_count: level ? String(level.lessons_count) : '',
    is_active: level?.is_active ?? true,
  })
  const save = useMutation({
    mutationFn: () => {
      const body: LevelInput = { ...form, lessons_count: intOrNull(form.lessons_count) }
      return level ? catalogsApi.updateLevel(level.id, body) : catalogsApi.createLevel(body)
    },
    onSuccess: (r) => {
      qc.invalidateQueries({ queryKey: ['course-levels'] })
      qc.invalidateQueries({ queryKey: ['courses'] })
      onSaved(r.message)
      onClose()
    },
  })
  const toggleGrade = (g: string, on: boolean) =>
    setForm({ ...form, grade_levels: on ? [...form.grade_levels, g] : form.grade_levels.filter((x) => x !== g) })

  return (
    <Dialog title={level ? `Sửa trình độ ${level.code}` : 'Thêm trình độ'} onClose={onClose}>
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault()
          save.mutate()
        }}
      >
        {save.isError && <Alert>{errorMessage(save.error)}</Alert>}
        <div className="grid gap-4 sm:grid-cols-2">
          <Field
            label="Mã trình độ"
            required={!level}
            disabled={!!level}
            maxLength={20}
            value={form.code}
            onChange={(e) => setForm({ ...form, code: e.target.value })}
            hint={level ? 'Không đổi được mã sau khi tạo.' : 'Ví dụ A1, STARTERS'}
          />
          <Field
            label="Nhóm trình độ"
            maxLength={50}
            list="level-groups"
            value={form.level_group}
            onChange={(e) => setForm({ ...form, level_group: e.target.value })}
            hint="Ví dụ CAMBRIDGE YLE, CEFR"
          />
          <datalist id="level-groups">
            {groups.map((g) => (
              <option key={g} value={g} />
            ))}
          </datalist>
        </div>
        <Field label="Tên trình độ" required maxLength={255} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Mục tiêu đầu ra" required maxLength={255} value={form.target} onChange={(e) => setForm({ ...form, target: e.target.value })} />
          <Field
            label="Số buổi học"
            required
            type="number"
            min={1}
            value={form.lessons_count}
            onChange={(e) => setForm({ ...form, lessons_count: e.target.value })}
          />
        </div>
        <fieldset>
          <legend className="mb-1.5 text-sm font-medium">Cấp độ test đầu vào phù hợp</legend>
          <div className="grid grid-cols-2 gap-1 sm:grid-cols-5">
            {grades.map((g) => (
              <Checkbox
                key={g.value}
                label={g.label}
                checked={form.grade_levels.includes(g.value)}
                onChange={(e) => toggleGrade(g.value, e.target.checked)}
              />
            ))}
          </div>
        </fieldset>
        <TextArea label="Mô tả" maxLength={1000} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />
        <Checkbox label="Đang hoạt động" checked={form.is_active} onChange={(e) => setForm({ ...form, is_active: e.target.checked })} />
        <p className="text-xs text-on-surface-variant">Gắn giáo trình sẽ có khi phân hệ giáo trình chuyển sang hệ thống mới.</p>
        <Button type="submit" disabled={save.isPending}>
          Lưu
        </Button>
      </form>
    </Dialog>
  )
}
