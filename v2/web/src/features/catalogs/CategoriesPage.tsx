// Danh mục hệ thống (SystemCategoryController của Laravel): nguồn khách, lý do mất khách, chức vụ, mức phạt.
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { errorMessage, type Me } from '../../api'
import { Alert, Badge, Button, Card, Checkbox, Dialog, Field } from '../../components/ui'
import { catalogsApi, type Category, type CategoryInput, type CategoryList } from './api'
import { Empty, Pager, PageTitle, Table, actionsTd, intOrNull, td, useAction } from './common'

export function CategoriesPage(_: { me: Me }) {
  const [type, setType] = useState('lead_source')
  const [q, setQ] = useState('')
  const [page, setPage] = useState(1)
  const [editing, setEditing] = useState<Category | 'new' | null>(null)
  const list = useQuery({ queryKey: ['system-categories', type, q, page], queryFn: () => catalogsApi.categories({ type, q, page }) })
  const act = useAction([['system-categories']])
  const d = list.data
  const tab = (active: boolean) =>
    `rounded-lg px-3 py-1.5 text-sm font-medium ${active ? 'bg-primary text-white' : 'text-on-surface-variant hover:bg-surface-container-low'}`

  return (
    <Card title={<PageTitle title="Danh mục hệ thống">{d && <Button onClick={() => setEditing('new')}>Thêm danh mục</Button>}</PageTitle>}>
      <div className="space-y-4">
        <nav className="flex flex-wrap gap-2" aria-label="Nhóm danh mục">
          {(d?.types ?? []).map((t) => (
            <button
              key={t.value}
              type="button"
              className={tab(t.value === type)}
              onClick={() => {
                setType(t.value)
                setPage(1)
              }}
            >
              {t.label}
            </button>
          ))}
        </nav>
        <div className="max-w-md">
          <Field
            label="Tìm kiếm"
            placeholder="Mã hoặc tên"
            value={q}
            onChange={(e) => {
              setQ(e.target.value)
              setPage(1)
            }}
          />
        </div>
        {list.isError && <Alert>{errorMessage(list.error)}</Alert>}
        {act.notice}
        {d && d.items.length === 0 && <Empty>Nhóm này chưa có danh mục nào.</Empty>}
        {d && d.items.length > 0 && (
          <Table head={['Thứ tự', 'Mã', 'Tên', 'Trạng thái', '']}>
            {d.items.map((c) => (
              <tr key={c.id}>
                <td className={td}>{c.sort_order}</td>
                <td className={`${td} font-mono`}>{c.code}</td>
                <td className={`${td} font-medium`}>{c.name}</td>
                <td className={td}>{c.is_active ? <Badge tone="success">Đang dùng</Badge> : <Badge>Ngừng dùng</Badge>}</td>
                <td className={actionsTd}>
                  <Button variant="secondary" onClick={() => setEditing(c)}>
                    Sửa
                  </Button>
                  {c.is_active ? (
                    <Button
                      variant="danger"
                      onClick={() => act.run(() => catalogsApi.setCategoryActive(c.id, false), `Ngừng dùng danh mục ${c.name}?`)}
                    >
                      Ngừng dùng
                    </Button>
                  ) : (
                    <Button variant="secondary" onClick={() => act.run(() => catalogsApi.setCategoryActive(c.id, true))}>
                      Dùng lại
                    </Button>
                  )}
                </td>
              </tr>
            ))}
          </Table>
        )}
        {d && <Pager page={d.page} total={d.total} size={d.page_size} onPage={setPage} />}
      </div>
      {editing && d && (
        <CategoryDialog
          category={editing === 'new' ? null : editing}
          list={d}
          onClose={() => setEditing(null)}
          onSaved={(msg) => act.setNotice({ tone: 'success', text: msg })}
        />
      )}
    </Card>
  )
}

function CategoryDialog({
  category,
  list,
  onClose,
  onSaved,
}: {
  category: Category | null
  list: CategoryList
  onClose: () => void
  onSaved: (msg: string) => void
}) {
  const qc = useQueryClient()
  const [form, setForm] = useState({
    code: category?.code ?? list.suggested_code,
    name: category?.name ?? '',
    sort_order: String(category?.sort_order ?? list.next_order),
    is_active: category?.is_active ?? true,
  })
  const typeLabel = list.types.find((t) => t.value === (category?.type ?? list.type))?.label ?? ''
  const save = useMutation({
    mutationFn: () => {
      const body: CategoryInput = {
        type: category?.type ?? list.type,
        code: form.code,
        name: form.name,
        sort_order: intOrNull(form.sort_order),
        is_active: form.is_active,
      }
      return category ? catalogsApi.updateCategory(category.id, body) : catalogsApi.createCategory(body)
    },
    onSuccess: (r) => {
      qc.invalidateQueries({ queryKey: ['system-categories'] })
      onSaved(r.message)
      onClose()
    },
  })

  return (
    <Dialog title={`${category ? 'Sửa' : 'Thêm'} danh mục · ${typeLabel}`} onClose={onClose}>
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault()
          save.mutate()
        }}
      >
        {save.isError && <Alert>{errorMessage(save.error)}</Alert>}
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Mã" required maxLength={50} value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value })} />
          <Field
            label="Thứ tự hiển thị"
            type="number"
            min={0}
            value={form.sort_order}
            onChange={(e) => setForm({ ...form, sort_order: e.target.value })}
          />
        </div>
        <Field label="Tên" required maxLength={255} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
        <Checkbox label="Đang dùng" checked={form.is_active} onChange={(e) => setForm({ ...form, is_active: e.target.checked })} />
        <Button type="submit" disabled={save.isPending}>
          Lưu
        </Button>
      </form>
    </Dialog>
  )
}
