// Phòng học & loại phòng (RoomController, RoomTypeController của Laravel); hai tab: /rooms và /rooms/types.
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { errorMessage, type Me } from '../../api'
import { Alert, Badge, Button, Card, Dialog, Field, Select } from '../../components/ui'
import { can } from '../../nav'
import { navigate, usePath } from '../../router'
import { catalogsApi, type Room, type RoomInput, type RoomScope, type RoomType, type RoomTypeOption } from './api'
import { Empty, Pager, PageTitle, Table, TextArea, actionsTd, intOrNull, td, useAction } from './common'

export function RoomsPage({ me }: { me: Me }) {
  const types = usePath() === '/rooms/types'
  const tab = (active: boolean) =>
    `rounded-lg px-3 py-1.5 text-sm font-medium ${active ? 'bg-primary text-white' : 'text-on-surface-variant hover:bg-surface-container-low'}`
  return (
    <div className="space-y-4">
      <nav className="flex gap-2" aria-label="Phòng học">
        <button type="button" className={tab(!types)} onClick={() => navigate('/rooms')}>
          Phòng học
        </button>
        <button type="button" className={tab(types)} onClick={() => navigate('/rooms/types')}>
          Loại phòng
        </button>
      </nav>
      {types ? <RoomTypesTab me={me} /> : <RoomsTab me={me} />}
    </div>
  )
}

function Blocked() {
  return <Alert tone="info">Tài khoản chưa được gán chi nhánh nên chưa xem được phòng học. Liên hệ quản trị viên để được gán chi nhánh.</Alert>
}

function RoomsTab({ me }: { me: Me }) {
  const [branch, setBranch] = useState('')
  const [type, setType] = useState('')
  const [page, setPage] = useState(1)
  const [editing, setEditing] = useState<Room | 'new' | null>(null)
  const list = useQuery({
    queryKey: ['rooms', branch, type, page],
    queryFn: () => catalogsApi.rooms({ branch_id: branch, room_type_id: type, page }),
  })
  const act = useAction([['rooms'], ['room-types']])
  const d = list.data
  const rooms = d?.rooms

  return (
    <Card
      title={
        <PageTitle title="Phòng học">
          {can(me, 'room.manage') && d && !d.blocked && <Button onClick={() => setEditing('new')}>Thêm phòng</Button>}
        </PageTitle>
      }
    >
      <div className="space-y-4">
        {list.isError && <Alert>{errorMessage(list.error)}</Alert>}
        {d?.blocked && <Blocked />}
        {d && !d.blocked && (
          <div className="grid gap-3 md:grid-cols-2">
            {d.locked_branch ? (
              <Field label="Chi nhánh" value={`${d.locked_branch.code} – ${d.locked_branch.name}`} disabled />
            ) : (
              <Select
                label="Chi nhánh"
                value={branch}
                onChange={(e) => {
                  setBranch(e.target.value)
                  setPage(1)
                }}
              >
                <option value="">Tất cả chi nhánh</option>
                {d.branches.map((b) => (
                  <option key={b.id} value={b.id}>
                    {b.code} – {b.name}
                  </option>
                ))}
              </Select>
            )}
            <Select
              label="Loại phòng"
              value={type}
              onChange={(e) => {
                setType(e.target.value)
                setPage(1)
              }}
            >
              <option value="">Tất cả loại phòng</option>
              {d.types.map((t) => (
                <option key={t.value} value={t.value}>
                  {t.label}
                  {t.is_active ? '' : ' (ngừng dùng)'}
                </option>
              ))}
            </Select>
          </div>
        )}
        {act.notice}
        {rooms && rooms.items.length === 0 && <Empty>Chưa có phòng học nào.</Empty>}
        {rooms && rooms.items.length > 0 && (
          <Table head={['Tên phòng', 'Chi nhánh', 'Loại phòng', 'Sức chứa', 'Lớp đang dùng', 'Ghi chú', '']}>
            {rooms.items.map((r) => (
              <tr key={r.id}>
                <td className={`${td} font-medium`}>{r.name}</td>
                <td className={td}>{r.branch}</td>
                <td className={td}>
                  {r.type} {!r.type_active && <Badge>Ngừng dùng</Badge>}
                </td>
                <td className={td}>{r.capacity ?? '—'}</td>
                <td className={td}>
                  {r.classes && r.classes.length > 0
                    ? r.classes.map((c) => (
                        <p key={c.id}>
                          {c.name} {c.studying ? <Badge tone="success">Đang học</Badge> : <Badge>Dự kiến</Badge>}
                          {c.slots.length > 0 && <span className="block text-xs text-on-surface-variant">{c.slots.join(', ')}</span>}
                        </p>
                      ))
                    : '—'}
                </td>
                <td className={`${td} text-on-surface-variant`}>{r.description}</td>
                <td className={actionsTd}>
                  {can(me, 'room.manage') && (
                    <Button variant="secondary" onClick={() => setEditing(r)}>
                      Sửa
                    </Button>
                  )}
                  {can(me, 'room.delete') && (
                    <Button variant="danger" onClick={() => act.run(() => catalogsApi.deleteRoom(r.id), `Xóa phòng ${r.name}?`)}>
                      Xóa
                    </Button>
                  )}
                </td>
              </tr>
            ))}
          </Table>
        )}
        {rooms && <Pager page={rooms.page} total={rooms.total} size={rooms.page_size} onPage={setPage} />}
      </div>
      {editing && d && (
        <RoomDialog
          room={editing === 'new' ? null : editing}
          scope={d}
          types={d.types}
          onClose={() => setEditing(null)}
          onSaved={(msg) => act.setNotice({ tone: 'success', text: msg })}
        />
      )}
    </Card>
  )
}

function RoomDialog({
  room,
  scope,
  types,
  onClose,
  onSaved,
}: {
  room: Room | null
  scope: RoomScope
  types: RoomTypeOption[]
  onClose: () => void
  onSaved: (msg: string) => void
}) {
  const qc = useQueryClient()
  const [form, setForm] = useState({
    branch_id: room?.branch_id ?? scope.locked_branch?.id ?? (scope.branches.length === 1 ? scope.branches[0].id : ''),
    name: room?.name ?? '',
    room_type_id: room?.room_type_id ?? '',
    capacity: room?.capacity != null ? String(room.capacity) : '',
    description: room?.description ?? '',
  })
  const save = useMutation({
    mutationFn: () => {
      const body: RoomInput = {
        branch_id: form.branch_id || null,
        name: form.name,
        room_type_id: form.room_type_id || null,
        capacity: intOrNull(form.capacity),
        description: form.description,
      }
      return room ? catalogsApi.updateRoom(room.id, body) : catalogsApi.createRoom(body)
    },
    onSuccess: (r) => {
      qc.invalidateQueries({ queryKey: ['rooms'] })
      qc.invalidateQueries({ queryKey: ['room-types'] })
      onSaved(r.message)
      onClose()
    },
  })
  // Loại đã ngừng chỉ hiện khi đang là loại của phòng này
  const typeOptions = types.filter((t) => t.is_active || t.value === room?.room_type_id)

  return (
    <Dialog title={room ? `Sửa phòng ${room.name}` : 'Thêm phòng học'} onClose={onClose}>
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault()
          save.mutate()
        }}
      >
        {save.isError && <Alert>{errorMessage(save.error)}</Alert>}
        {room || scope.locked_branch ? (
          <Field label="Chi nhánh" disabled value={room ? room.branch : `${scope.locked_branch!.code} – ${scope.locked_branch!.name}`} />
        ) : (
          <Select label="Chi nhánh" required value={form.branch_id} onChange={(e) => setForm({ ...form, branch_id: e.target.value })}>
            <option value="">— Chọn chi nhánh —</option>
            {scope.branches.map((b) => (
              <option key={b.id} value={b.id}>
                {b.code} – {b.name}
              </option>
            ))}
          </Select>
        )}
        <Field label="Tên phòng" required maxLength={100} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
        <div className="grid gap-4 sm:grid-cols-2">
          <Select label="Loại phòng" required value={form.room_type_id} onChange={(e) => setForm({ ...form, room_type_id: e.target.value })}>
            <option value="">— Chọn loại phòng —</option>
            {typeOptions.map((t) => (
              <option key={t.value} value={t.value}>
                {t.label}
              </option>
            ))}
          </Select>
          <Field
            label="Sức chứa (học viên)"
            type="number"
            min={1}
            max={999}
            value={form.capacity}
            onChange={(e) => setForm({ ...form, capacity: e.target.value })}
          />
        </div>
        <TextArea label="Ghi chú" maxLength={2000} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />
        <Button type="submit" disabled={save.isPending}>
          Lưu
        </Button>
      </form>
    </Dialog>
  )
}

function RoomTypesTab({ me }: { me: Me }) {
  const qc = useQueryClient()
  const list = useQuery({ queryKey: ['room-types'], queryFn: catalogsApi.roomTypes })
  const act = useAction([['room-types'], ['rooms']])
  const [name, setName] = useState('')
  const [renaming, setRenaming] = useState<RoomType | null>(null)
  const manage = can(me, 'room.manage_types')
  const create = useMutation({
    mutationFn: () => catalogsApi.createRoomType(name),
    onSuccess: (r) => {
      setName('')
      act.setNotice({ tone: 'success', text: r.message })
      qc.invalidateQueries({ queryKey: ['room-types'] })
      qc.invalidateQueries({ queryKey: ['rooms'] })
    },
    onError: (e) => act.setNotice({ tone: 'error', text: errorMessage(e) }),
  })
  const d = list.data

  return (
    <Card title="Loại phòng">
      <div className="space-y-4">
        {list.isError && <Alert>{errorMessage(list.error)}</Alert>}
        {manage && (
          <form
            className="flex flex-wrap items-end gap-3"
            onSubmit={(e) => {
              e.preventDefault()
              create.mutate()
            }}
          >
            <div className="min-w-60 flex-1">
              <Field label="Tên loại phòng mới" required maxLength={100} value={name} onChange={(e) => setName(e.target.value)} />
            </div>
            <Button type="submit" disabled={create.isPending}>
              Thêm loại phòng
            </Button>
          </form>
        )}
        {act.notice}
        {d && d.room_types.length === 0 && <Empty>Chưa có loại phòng nào.</Empty>}
        {d && d.room_types.length > 0 && (
          <Table head={['Tên loại phòng', 'Trạng thái', '']}>
            {d.room_types.map((t) => (
              <tr key={t.id}>
                <td className={`${td} font-medium`}>{t.name}</td>
                <td className={td}>
                  {t.is_active ? <Badge tone="success">Đang dùng</Badge> : <Badge>Ngừng dùng</Badge>}{' '}
                  {t.in_use && <Badge tone="info">Có phòng</Badge>}
                </td>
                <td className={actionsTd}>
                  {manage && (
                    <>
                      <Button variant="secondary" onClick={() => setRenaming(t)}>
                        Đổi tên
                      </Button>
                      <Button variant="secondary" onClick={() => act.run(() => catalogsApi.toggleRoomType(t.id))}>
                        {t.is_active ? 'Ngừng dùng' : 'Dùng lại'}
                      </Button>
                      <Button
                        variant="danger"
                        disabled={t.in_use}
                        title={t.in_use ? 'Loại phòng đang có phòng, không xóa được.' : undefined}
                        onClick={() => act.run(() => catalogsApi.deleteRoomType(t.id), `Xóa loại phòng ${t.name}?`)}
                      >
                        Xóa
                      </Button>
                    </>
                  )}
                </td>
              </tr>
            ))}
          </Table>
        )}
      </div>
      {renaming && (
        <RenameDialog
          type={renaming}
          onClose={() => setRenaming(null)}
          onSaved={(msg) => act.setNotice({ tone: 'success', text: msg })}
        />
      )}
    </Card>
  )
}

function RenameDialog({ type, onClose, onSaved }: { type: RoomType; onClose: () => void; onSaved: (msg: string) => void }) {
  const qc = useQueryClient()
  const [name, setName] = useState(type.name)
  const save = useMutation({
    mutationFn: () => catalogsApi.renameRoomType(type.id, name),
    onSuccess: (r) => {
      qc.invalidateQueries({ queryKey: ['room-types'] })
      qc.invalidateQueries({ queryKey: ['rooms'] })
      onSaved(r.message)
      onClose()
    },
  })
  return (
    <Dialog title="Đổi tên loại phòng" onClose={onClose}>
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault()
          save.mutate()
        }}
      >
        {save.isError && <Alert>{errorMessage(save.error)}</Alert>}
        <Field label="Tên loại phòng" required maxLength={100} value={name} onChange={(e) => setName(e.target.value)} />
        <Button type="submit" disabled={save.isPending}>
          Lưu
        </Button>
      </form>
    </Dialog>
  )
}
