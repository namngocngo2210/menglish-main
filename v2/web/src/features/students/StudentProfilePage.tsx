import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState, type ChangeEvent, type ReactNode, type TextareaHTMLAttributes } from 'react'
import { ApiError, errorMessage, type Me } from '../../api'
import { Alert, Button, Card, Dialog, Field, Select } from '../../components/ui'
import { navigate } from '../../router'
import { studentsApi, type StudentInput, type StudentProfile, type StudentStatus } from './api'
import { NotYet, PageHeader, StatusBadge, TempAccount } from './components'
import { formatDate, formatDateTime, genderOptions, toInput } from './logic'

type Tab = 'info' | 'guardians' | 'classes' | 'tuition' | 'history'

// Chi tiết hồ sơ học sinh (Laravel Students/Show + Components/Students/StudentProfile) và "Xem theo phân quyền"
// (Students/Scoped): server chỉ gửi phần người xem có quyền.
export function StudentProfilePage({ me, id, scoped }: { me: Me; id: string; scoped: boolean }) {
  const [tab, setTab] = useState<Tab>('info')
  const [flash, setFlash] = useState<string | null>(null)
  const student = useQuery({ queryKey: ['student', id, scoped], queryFn: () => studentsApi.get(id, scoped) })
  const options = useQuery({ queryKey: ['student-options'], queryFn: studentsApi.options })

  if (student.isPending) return <p className="text-sm text-on-surface-variant">Đang tải…</p>
  if (student.isError) {
    return (
      <Card title="Hồ sơ học sinh">
        <Alert>{errorMessage(student.error)}</Alert>
        <Button variant="secondary" className="mt-4" onClick={() => navigate('/students')}>
          Danh sách học sinh
        </Button>
      </Card>
    )
  }
  const st = student.data
  const color = options.data?.statuses.find((s) => s.value === st.status)?.color ?? 'neutral'
  const tabs: { key: Tab; label: string; show: boolean }[] = [
    { key: 'info', label: 'Thông tin', show: true },
    { key: 'guardians', label: `Phụ huynh${st.view.can_view_contact ? ` (${st.guardians.length})` : ''}`, show: true },
    { key: 'classes', label: 'Lớp học & lộ trình', show: true },
    { key: 'tuition', label: 'Học phí', show: st.view.can_view_tuition },
    { key: 'history', label: 'Lịch sử', show: !scoped },
  ]

  return (
    <>
      <PageHeader
        title="Chi tiết hồ sơ học sinh"
        description={
          scoped ? `${st.full_name} (${st.code}) — chỉ hiển thị các mục bạn được phân quyền xem.` : `${st.full_name} · ${st.code}`
        }
        actions={
          <>
            {scoped ? (
              <Button variant="secondary" onClick={() => navigate(`/students/${st.id}`)}>
                Quay lại hồ sơ
              </Button>
            ) : (
              <Button variant="secondary" onClick={() => navigate(`/students/${st.id}/scoped`)}>
                Xem theo phân quyền
              </Button>
            )}
            <Button variant="secondary" onClick={() => navigate('/students')}>
              Danh sách học sinh
            </Button>
          </>
        }
      />
      {flash && (
        <div className="mb-4">
          <Alert tone="success">{flash}</Alert>
        </div>
      )}
      {scoped && <ScopedBanner me={me} st={st} />}

      <div className="grid gap-4 lg:grid-cols-[18rem_1fr]">
        <Summary st={st} color={color} />
        <div className="space-y-4">
          <StatusBar st={st} color={color} onDone={setFlash} />
          <div role="tablist" aria-label="Hồ sơ học sinh" className="flex gap-1 overflow-x-auto border-b border-surface-container-highest">
            {tabs
              .filter((t) => t.show)
              .map((t) => (
                <button
                  key={t.key}
                  role="tab"
                  type="button"
                  aria-selected={tab === t.key}
                  onClick={() => setTab(t.key)}
                  className="border-b-2 border-transparent px-3 py-2 text-sm whitespace-nowrap text-on-surface-variant hover:text-on-surface aria-selected:border-primary aria-selected:font-medium aria-selected:text-primary"
                >
                  {t.label}
                </button>
              ))}
          </div>
          {tab === 'info' && <InfoTab st={st} onDone={setFlash} />}
          {tab === 'guardians' && <GuardiansTab st={st} onDone={setFlash} />}
          {tab === 'classes' && <ClassesTab st={st} />}
          {tab === 'tuition' && st.view.can_view_tuition && (
            <NotYet title="Thông tin học phí">
              Sổ học phí, phiếu thu và sổ buổi thuộc phân hệ Học phí, chưa chuyển sang hệ thống mới. Tra cứu trên bản cũ.
            </NotYet>
          )}
          {tab === 'history' && !scoped && <HistoryTab st={st} />}
        </div>
      </div>
    </>
  )
}

function ScopedBanner({ me, st }: { me: Me; st: StudentProfile }) {
  const mark = (on: boolean, label: string) => (
    <span
      className={`inline-flex rounded-full px-2.5 py-0.5 text-xs ${on ? 'bg-success-container text-on-success-container' : 'bg-surface-container-high text-on-surface-variant'}`}
    >
      {on ? '✓' : '✕'} {label}
    </span>
  )
  return (
    <div className="mb-4 flex flex-wrap items-center gap-2 rounded-xl border border-outline-variant bg-surface p-3 text-sm">
      <span className="text-xs font-medium text-on-surface-variant uppercase">Đang xem với vai trò</span>
      <span className="rounded-full bg-info-container px-2.5 py-0.5 text-xs text-on-info-container">
        {me.roles.map((r) => r.short_label).join(', ') || 'Người dùng'}
      </span>
      <span className="ml-auto flex flex-wrap gap-1">
        {mark(st.view.can_view_academic, 'Lớp học & điểm danh')}
        {mark(st.view.can_view_contact, 'Liên hệ')}
        {mark(st.view.can_view_tuition, 'Học phí')}
      </span>
    </div>
  )
}

function Summary({ st, color }: { st: StudentProfile; color: string }) {
  return (
    <section className="space-y-4 rounded-xl border border-surface-container-highest bg-surface p-5 text-center shadow-sm">
      <div className="mx-auto flex size-20 items-center justify-center rounded-full bg-primary-light text-2xl font-semibold text-primary">
        {st.full_name.trim().split(/\s+/).pop()?.charAt(0).toUpperCase()}
      </div>
      <div>
        <h2 className="text-lg font-semibold">{st.full_name}</h2>
        <p className="text-sm text-on-surface-variant">
          {st.dob ? `${formatDate(st.dob)} (${st.age} tuổi)` : 'Chưa cập nhật ngày sinh'}
          {st.gender && ` · ${st.gender}`}
        </p>
        {st.view.can_view_contact ? (
          <div className="mt-1 text-sm">
            <p className="font-mono">{st.phone || '—'}</p>
            {st.email && <p className="text-xs text-on-surface-variant">{st.email}</p>}
          </div>
        ) : (
          <p className="mt-1 text-xs text-on-surface-variant italic">Thông tin liên hệ ẩn theo phân quyền</p>
        )}
        <p className="mt-1 text-xs text-on-surface-variant">Cơ sở: {st.branch_name ?? '—'}</p>
      </div>
      <div className="grid grid-cols-2 gap-2 border-t border-surface-container-highest pt-4 text-left">
        <Stat label="Mã học sinh" value={<span className="font-mono text-primary">{st.code}</span>} />
        <Stat label="Ngày bắt đầu học" value={formatDate(st.study_started_on) ?? 'Chưa vào học'} />
        <div className="col-span-2">
          <Stat label="Điểm test đầu vào" value={st.entrance_score || 'Chưa có'} />
        </div>
      </div>
      <div className="space-y-1 text-xs text-on-surface-variant">
        <p className="flex flex-wrap items-center justify-center gap-1">
          {st.long_term && <StatusBadge color="primary">Học viên lâu năm</StatusBadge>}
          {st.tenure_label ? <span>Đã học {st.tenure_label}</span> : !st.study_started_on && <span>Tự ghi nhận khi điểm danh buổi có mặt đầu tiên</span>}
        </p>
        <p>Tạo hồ sơ {formatDate(st.created_at.slice(0, 10))}</p>
        <p>
          Trạng thái: <StatusBadge color={color}>{st.status_label}</StatusBadge>
        </p>
      </div>
    </section>
  )
}

function Stat({ label, value }: { label: string; value: ReactNode }) {
  return (
    <div className="rounded-lg bg-surface-container-low p-2">
      <p className="text-[11px] text-on-surface-variant uppercase">{label}</p>
      <p className="text-sm font-semibold">{value}</p>
    </div>
  )
}

function useRefresh(st: StudentProfile) {
  const qc = useQueryClient()
  return () => {
    qc.invalidateQueries({ queryKey: ['student', st.id] })
    qc.invalidateQueries({ queryKey: ['student', st.code] })
    qc.invalidateQueries({ queryKey: ['students'] })
  }
}

// Trạng thái vòng đời: Đổi trạng thái (student.change_status), Kết thúc bảo lưu, Xóa hồ sơ (student.delete).
function StatusBar({ st, color, onDone }: { st: StudentProfile; color: string; onDone: (m: string) => void }) {
  const refresh = useRefresh(st)
  const options = useQuery({ queryKey: ['student-options'], queryFn: studentsApi.options })
  const [confirm, setConfirm] = useState<null | { title: string; body: string; label: string; danger?: boolean; run: () => Promise<{ message: string }> }>(null)
  const action = useMutation({
    mutationFn: (run: () => Promise<{ message: string }>) => run(),
    onSuccess: (r) => {
      setConfirm(null)
      onDone(r.message)
      refresh()
    },
  })
  const remove = useMutation({
    mutationFn: () => studentsApi.remove(st.id),
    onSuccess: (r) => {
      refresh()
      navigate('/students')
      window.alert(r.message)
    },
  })
  const pick = (value: StudentStatus) => {
    const label = options.data?.statuses.find((s) => s.value === value)?.label ?? value
    const dropped = value === 'dropped'
    setConfirm({
      title: 'Đổi trạng thái',
      body: dropped
        ? 'Chuyển sang Thôi học sẽ đưa học viên ra khỏi danh sách lớp đang học (vẫn giữ lịch sử). Tiếp tục?'
        : `Chuyển học viên ${st.full_name} sang "${label}"?`,
      label: dropped ? 'Chuyển sang Thôi học' : 'Đồng ý',
      danger: dropped,
      run: () => studentsApi.changeStatus(st.id, value),
    })
  }

  return (
    <section className="flex flex-col gap-3 rounded-xl border border-surface-container-highest bg-surface p-4 shadow-sm sm:flex-row sm:items-center sm:justify-between">
      <div className="flex items-center gap-6">
        <div>
          <p className="text-[11px] text-on-surface-variant uppercase">Trạng thái hiện tại</p>
          <div className="mt-1">
            <StatusBadge color={color}>{st.status_label}</StatusBadge>
          </div>
        </div>
        <div>
          <p className="text-[11px] text-on-surface-variant uppercase">Thời gian cập nhật</p>
          <p className="mt-1 font-mono text-sm">{formatDateTime(st.updated_at)}</p>
        </div>
      </div>
      {st.view.can_change_status ? (
        <div className="flex flex-wrap items-center gap-2">
          {st.status === 'deferred' && (
            <Button
              variant="secondary"
              onClick={() =>
                setConfirm({
                  title: 'Kết thúc bảo lưu',
                  body: 'Kết thúc bảo lưu cho học viên này? Học viên sẽ về Đang học (hoặc Chờ khai giảng nếu lớp chưa khai giảng) và công nợ, nhắc nợ chạy lại.',
                  label: 'Kết thúc bảo lưu',
                  run: () => studentsApi.endDeferral(st.id),
                })
              }
            >
              Kết thúc bảo lưu{st.deferred_until && ` (hạn ${formatDate(st.deferred_until)})`}
            </Button>
          )}
          <select
            aria-label="Đổi trạng thái"
            className="h-10 rounded-lg border border-outline-variant bg-surface px-3 text-sm"
            value=""
            onChange={(e) => e.target.value && pick(e.target.value as StudentStatus)}
          >
            <option value="">Đổi trạng thái…</option>
            {options.data?.statuses
              .filter((s) => s.value !== st.status)
              .map((s) => (
                <option key={s.value} value={s.value}>
                  {s.label}
                </option>
              ))}
          </select>
          {st.view.can_delete && !st.view.scoped && (
            <Button
              variant="danger"
              disabled={remove.isPending}
              onClick={() => window.confirm(`Xóa hồ sơ học viên ${st.full_name}? Lịch sử học tập, điểm danh, học phí vẫn được giữ.`) && remove.mutate()}
            >
              Xóa hồ sơ
            </Button>
          )}
        </div>
      ) : (
        <div className="text-right">
          <Button variant="secondary" disabled>
            Đổi trạng thái 🔒
          </Button>
          <p className="mt-1 text-xs text-on-surface-variant">Quyền xem duy nhất</p>
        </div>
      )}
      {remove.isError && <Alert>{errorMessage(remove.error)}</Alert>}
      {confirm && (
        <Dialog title={confirm.title} onClose={() => setConfirm(null)}>
          <p className="text-sm">{confirm.body}</p>
          {action.isError && (
            <div className="mt-3">
              <Alert>{errorMessage(action.error)}</Alert>
            </div>
          )}
          <div className="mt-5 flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setConfirm(null)}>
              Hủy
            </Button>
            <Button variant={confirm.danger ? 'danger' : 'primary'} disabled={action.isPending} onClick={() => action.mutate(confirm.run)}>
              {confirm.label}
            </Button>
          </div>
        </Dialog>
      )}
    </section>
  )
}

function InfoTab({ st, onDone }: { st: StudentProfile; onDone: (m: string) => void }) {
  const qc = useQueryClient()
  const refresh = useRefresh(st)
  const [form, setForm] = useState<StudentInput>(() => toInput(st))
  const save = useMutation({
    mutationFn: () => studentsApi.update(st.id, form),
    onSuccess: (r) => {
      qc.setQueryData(['student', st.id, false], r.student)
      setForm(toInput(r.student))
      onDone(r.message)
      refresh()
    },
  })
  const set = (k: keyof StudentInput) => (e: ChangeEvent<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>) =>
    setForm({ ...form, [k]: e.target.value })
  const today = new Date().toLocaleDateString('sv-SE')

  if (!st.view.can_edit || st.view.scoped) {
    const row = (label: string, value: string | null | undefined) => (
      <div>
        <dt className="text-xs text-on-surface-variant">{label}</dt>
        <dd className="text-sm">{value || '—'}</dd>
      </div>
    )
    return (
      <Card title="Thông tin học sinh">
        <dl className="grid gap-4 sm:grid-cols-2">
          {row('Trường học', st.school)}
          {row('Giới tính', st.gender ?? 'Chưa cập nhật')}
          {st.view.can_view_contact && (
            <>
              {row('Họ tên phụ huynh', st.parent_name)}
              {row('SĐT phụ huynh', st.parent_phone)}
              {row('Địa chỉ liên hệ', st.address)}
            </>
          )}
          {row('Mục tiêu học tập', st.target)}
          {row('Ghi chú đặc biệt', st.notes)}
        </dl>
        {!st.view.can_edit && <p className="mt-4 text-right text-xs text-error">Bạn không có quyền sửa thông tin này</p>}
      </Card>
    )
  }

  return (
    <Card title="Chỉnh sửa thông tin">
      <form
        className="space-y-4"
        data-testid="student-edit-form"
        onSubmit={(e) => {
          e.preventDefault()
          save.mutate()
        }}
      >
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Họ và tên" required maxLength={255} value={form.full_name} onChange={set('full_name')} />
          <Field label="Số điện thoại" type="tel" required maxLength={20} value={form.phone} onChange={set('phone')} />
          <Field label="Ngày sinh" type="date" max={today} value={form.dob} onChange={set('dob')} />
          <Select label="Giới tính" value={form.gender} onChange={set('gender')}>
            <option value="">-- Chọn --</option>
            {genderOptions(st.gender).map((g) => (
              <option key={g} value={g}>
                {g}
              </option>
            ))}
          </Select>
          <Field label="Email liên hệ" type="email" maxLength={255} value={form.email} onChange={set('email')} placeholder="hocvien@menglish.edu.vn" />
          <Field label="Mục tiêu học tập" maxLength={100} value={form.target} onChange={set('target')} placeholder="VD: IELTS 6.5, Cambridge Starters..." />
          <Field label="Họ tên phụ huynh" maxLength={255} value={form.parent_name} onChange={set('parent_name')} placeholder="VD: Nguyễn Thị Hoa" />
          <Field
            label="SĐT phụ huynh (nhận kết quả Zalo)"
            type="tel"
            maxLength={20}
            value={form.parent_phone}
            onChange={set('parent_phone')}
            placeholder="VD: 0987 654 321"
          />
          <Field label="Trường học" maxLength={255} value={form.school} onChange={set('school')} placeholder="VD: Trường THCS Đoàn Thị Điểm" />
          <Field
            label="Ngày bắt đầu học"
            type="date"
            max={today}
            value={form.study_started_on}
            onChange={set('study_started_on')}
            hint="Tự điền theo buổi có mặt đầu tiên; sửa tay nếu học viên học từ trước khi dùng hệ thống."
          />
        </div>
        <TextArea label="Địa chỉ liên hệ" rows={2} maxLength={255} value={form.address} onChange={set('address')} placeholder="Nhập địa chỉ của học viên..." />
        <TextArea
          label="Ghi chú đặc biệt"
          rows={3}
          maxLength={500}
          value={form.notes}
          onChange={set('notes')}
          placeholder="Nhập ghi chú về học sinh (ví dụ: dị ứng, sở thích, mục tiêu học tập...)"
        />
        {save.isError && (
          <Alert>
            {errorMessage(save.error)}
            {save.error instanceof ApiError && save.error.code === 'stale' && (
              <button type="button" className="ml-2 underline" onClick={() => refresh()}>
                Tải lại
              </button>
            )}
          </Alert>
        )}
        <div className="flex justify-end gap-2 border-t border-surface-container-highest pt-4">
          <Button type="button" variant="secondary" onClick={() => setForm(toInput(st))}>
            Hủy
          </Button>
          <Button type="submit" disabled={save.isPending}>
            Lưu thay đổi
          </Button>
        </div>
      </form>
    </Card>
  )
}

function TextArea({ label, ...props }: TextareaHTMLAttributes<HTMLTextAreaElement> & { label: string }) {
  return (
    <label className="block space-y-1.5">
      <span className="block text-sm font-medium text-on-surface">{label}</span>
      <textarea
        className="block w-full rounded-lg border border-outline-variant px-3 py-2 text-sm outline-none focus:border-primary focus:ring-2 focus:ring-primary/25"
        {...props}
      />
    </label>
  )
}

// Phụ huynh: tài khoản đăng nhập bằng SĐT, một phụ huynh gắn được nhiều con. Liên hệ phụ huynh trên hồ sơ (Zalo) giữ ở tab Thông tin.
function GuardiansTab({ st, onDone }: { st: StudentProfile; onDone: (m: string) => void }) {
  const refresh = useRefresh(st)
  const [adding, setAdding] = useState(false)
  const [form, setForm] = useState({ phone: st.parent_phone ?? '', full_name: st.parent_name ?? '', relationship: '' })
  const add = useMutation({
    mutationFn: () => studentsApi.addGuardian(st.id, form),
    onSuccess: (r) => {
      refresh()
      if (!r.temporary_password) {
        setAdding(false)
        onDone(`Đã gắn phụ huynh ${r.guardian.full_name} (tài khoản có sẵn).`)
      }
    },
  })
  const remove = useMutation({
    mutationFn: (gid: string) => studentsApi.removeGuardian(st.id, gid),
    onSuccess: () => {
      refresh()
      onDone('Đã bỏ gắn phụ huynh.')
    },
  })
  const reset = useMutation({ mutationFn: () => studentsApi.resetAccount(st.id), onSuccess: refresh })
  const canEdit = st.view.can_edit && !st.view.scoped

  if (!st.view.can_view_contact) {
    return <Card title="Phụ huynh">Thông tin phụ huynh ẩn theo phân quyền.</Card>
  }
  return (
    <div className="space-y-4">
      <Card
        title={
          <span className="flex items-center justify-between gap-4">
            Phụ huynh
            {canEdit && (
              <Button
                onClick={() => {
                  add.reset()
                  setAdding(true)
                }}
              >
                Gắn phụ huynh
              </Button>
            )}
          </span>
        }
      >
        <p className="mb-3 text-sm text-on-surface-variant">
          Liên hệ trên hồ sơ: {st.parent_name || 'chưa có tên'} · {st.parent_phone || 'chưa có SĐT'}
        </p>
        {st.guardians.length === 0 ? (
          <p className="text-sm text-on-surface-variant">Chưa gắn tài khoản phụ huynh. Phụ huynh đăng nhập cổng học sinh bằng SĐT sau khi được gắn.</p>
        ) : (
          <ul className="divide-y divide-surface-container-highest">
            {st.guardians.map((g) => (
              <li key={g.id} className="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                  <p className="font-medium">
                    {g.full_name}
                    {g.relationship && <span className="text-sm font-normal text-on-surface-variant"> · {g.relationship}</span>}
                  </p>
                  <p className="text-sm text-on-surface-variant">
                    <span className="font-mono">{g.phone}</span>
                    {g.children > 1 && ` · phụ huynh của ${g.children} học viên`}
                    {g.status === 'disabled' && ' · tài khoản đã khóa'}
                    {g.must_change_password && ' · chưa đổi mật khẩu'}
                  </p>
                </div>
                {canEdit && (
                  <Button
                    variant="danger"
                    className="h-8 px-3"
                    disabled={remove.isPending}
                    onClick={() => window.confirm(`Bỏ gắn phụ huynh ${g.full_name} khỏi học viên này?`) && remove.mutate(g.id)}
                  >
                    Bỏ gắn
                  </Button>
                )}
              </li>
            ))}
          </ul>
        )}
        {remove.isError && <Alert>{errorMessage(remove.error)}</Alert>}
      </Card>

      <Card title="Tài khoản cổng học sinh">
        {st.account ? (
          <div className="space-y-3 text-sm">
            <p>
              Đăng nhập: <span className="font-mono">{st.account.login ?? '—'}</span>
              {st.account.status === 'disabled' && ' · đã khóa'}
              {st.account.must_change_password && ' · chưa đổi mật khẩu lần đầu'}
            </p>
            <p className="text-on-surface-variant">
              {st.account.last_login_at ? `Đăng nhập gần nhất ${formatDateTime(st.account.last_login_at)}` : 'Chưa đăng nhập lần nào'}
            </p>
            {st.view.can_assign_class && !st.view.scoped && !reset.data && (
              <Button
                variant="secondary"
                disabled={reset.isPending}
                onClick={() => window.confirm('Cấp mật khẩu tạm mới? Mật khẩu cũ hết hiệu lực và mọi thiết bị bị đăng xuất.') && reset.mutate()}
              >
                Cấp mật khẩu tạm
              </Button>
            )}
            {reset.isError && <Alert>{errorMessage(reset.error)}</Alert>}
            {reset.data && <TempAccount intro={reset.data.message} login={reset.data.login} password={reset.data.temporary_password} />}
          </div>
        ) : (
          <p className="text-sm text-on-surface-variant">Học viên chưa có tài khoản cổng học sinh, liên hệ Admin để cấp tài khoản.</p>
        )}
      </Card>

      {adding && (
        <Dialog title="Gắn phụ huynh" onClose={() => setAdding(false)}>
          {add.data?.temporary_password ? (
            <TempAccount
              intro={`Đã tạo tài khoản phụ huynh ${add.data.guardian.full_name} và gắn với học viên.`}
              login={add.data.login}
              password={add.data.temporary_password}
            />
          ) : (
            <form
              className="space-y-4"
              onSubmit={(e) => {
                e.preventDefault()
                add.mutate()
              }}
            >
              <Field
                label="SĐT phụ huynh"
                type="tel"
                required
                value={form.phone}
                onChange={(e) => setForm({ ...form, phone: e.target.value })}
                hint="SĐT đã có tài khoản phụ huynh (phụ huynh nhiều con) thì gắn luôn, chưa có thì tạo tài khoản mới."
              />
              <Field label="Họ tên phụ huynh" required maxLength={200} value={form.full_name} onChange={(e) => setForm({ ...form, full_name: e.target.value })} />
              <Field
                label="Quan hệ"
                maxLength={50}
                placeholder="VD: Mẹ, Bố, Ông bà"
                value={form.relationship}
                onChange={(e) => setForm({ ...form, relationship: e.target.value })}
              />
              {add.isError && <Alert>{errorMessage(add.error)}</Alert>}
              <div className="flex justify-end gap-2">
                <Button type="button" variant="secondary" onClick={() => setAdding(false)}>
                  Hủy
                </Button>
                <Button type="submit" disabled={add.isPending}>
                  Gắn phụ huynh
                </Button>
              </div>
            </form>
          )}
        </Dialog>
      )}
    </div>
  )
}

function ClassesTab({ st }: { st: StudentProfile }) {
  if (!st.view.can_view_academic) {
    return <Card title="Lớp học">Lớp học, lộ trình và chuyên cần ẩn theo phân quyền (cần quyền xem điểm danh học viên).</Card>
  }
  return (
    <div className="space-y-3">
      <NotYet title="Lớp học hiện tại, liên kết lớp khác">
        Phân hệ Lớp học chưa chuyển sang hệ thống mới.{' '}
        {st.current_class_id ? 'Học viên đã có lớp chính trên bản cũ.' : 'Học viên chưa được xếp lớp.'}
      </NotYet>
      <NotYet title="Lộ trình học tập & danh sách buổi học">Buổi học và nội dung giáo trình hiện khi phân hệ Lớp học và Giáo trình có ở v2.</NotYet>
      <NotYet title="Chuyên cần & lịch sử điểm danh">Điểm danh học viên thuộc phân hệ Điểm danh, chưa có ở v2.</NotYet>
      <NotYet title="Chăm sóc tháng đầu">Ba mốc chăm sóc (Buổi 1, Buổi 4–5, đủ 30 ngày) cần lớp học, điểm danh và công việc.</NotYet>
    </div>
  )
}

function HistoryTab({ st }: { st: StudentProfile }) {
  if (st.history.length === 0) return <Card title="Lịch sử hồ sơ">Chưa có thay đổi nào được ghi lại.</Card>
  return (
    <Card title="Lịch sử hồ sơ">
      <ol className="space-y-3">
        {st.history.map((e) => (
          <li key={e.id} className="border-l-2 border-primary-light pl-3 text-sm">
            <p>{e.message}</p>
            <p className="text-xs text-on-surface-variant">
              {formatDateTime(e.created_at)}
              {e.actor_name && ` · ${e.actor_name}`}
            </p>
          </li>
        ))}
      </ol>
    </Card>
  )
}
