import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { errorMessage, type Me } from '../../api'
import { Alert, Button, Card } from '../../components/ui'
import { can } from '../../nav'
import { navigate } from '../../router'
import { studentsApi } from './api'
import { NotYet, PageHeader, StatusBadge } from './components'
import { formatDate, pageCount } from './logic'

// Tiếp nhận & Xếp lớp (Laravel Students/Enrollments): bảng lượt xếp lớp, checklist bàn giao (giáo trình, nhóm Zalo) và
// xếp lớp đều cần phân hệ Lớp học — chưa có ở v2. Tạm hiện học viên chưa có lớp (chờ xếp lớp) trong phạm vi.
export function EnrollmentsPage({ me }: { me: Me }) {
  const [page, setPage] = useState(1)
  const options = useQuery({ queryKey: ['student-options'], queryFn: studentsApi.options })
  const list = useQuery({
    queryKey: ['students', 'without-class', page],
    queryFn: () => studentsApi.list({ without_class: true, page, per_page: 20 }),
    placeholderData: keepPreviousData,
  })
  const color = (s: string) => options.data?.statuses.find((o) => o.value === s)?.color ?? 'neutral'

  return (
    <>
      <PageHeader
        title="Tiếp nhận & Xếp lớp"
        description="Xếp học viên vào lớp và hoàn tất bàn giao (giáo trình, nhóm Zalo lớp)."
        actions={
          <Button variant="secondary" onClick={() => navigate('/students')}>
            Danh sách học sinh
          </Button>
        }
      />
      <div className="space-y-4">
        <NotYet title="Xếp lớp, liên kết lớp, checklist bàn giao">
          Các thao tác này cần phân hệ Lớp học (lớp, lượt xếp lớp, sĩ số), chưa chuyển sang hệ thống mới. Trong lúc chờ, xếp lớp trên bản cũ.
          {can(me, 'student.assign_class') ? '' : ' Bạn cũng cần quyền xếp lớp học viên để thao tác.'}
        </NotYet>
        <Card title="Học viên chưa có lớp">
          {list.isError && <Alert>{errorMessage(list.error)}</Alert>}
          {list.data && list.data.students.length === 0 && (
            <p className="text-sm text-on-surface-variant">Không có học viên nào đang chờ xếp lớp trong phạm vi của bạn.</p>
          )}
          {list.data && list.data.students.length > 0 && (
            <div className="overflow-x-auto">
              <table className="w-full min-w-[640px] text-left text-sm">
                <thead className="text-xs text-on-surface-variant">
                  <tr>
                    <th className="py-2 pr-4 font-medium">Học viên</th>
                    <th className="py-2 pr-4 font-medium">Chi nhánh</th>
                    <th className="py-2 pr-4 font-medium">Ngày tạo hồ sơ</th>
                    <th className="py-2 pr-4 font-medium">Trạng thái</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-surface-container-highest">
                  {list.data.students.map((st) => (
                    <tr key={st.id} className="cursor-pointer hover:bg-surface-container-low" onClick={() => navigate(`/students/${st.id}`)}>
                      <td className="py-2 pr-4">
                        <p className="font-medium">{st.full_name}</p>
                        <p className="font-mono text-xs text-on-surface-variant">{st.code}</p>
                      </td>
                      <td className="py-2 pr-4">{st.branch_name ?? '—'}</td>
                      <td className="py-2 pr-4 font-mono">{formatDate(st.created_at.slice(0, 10))}</td>
                      <td className="py-2 pr-4">
                        <StatusBadge color={color(st.status)}>{st.status_label}</StatusBadge>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
          {list.data && pageCount(list.data.total, list.data.per_page) > 1 && (
            <div className="mt-4 flex items-center gap-3 text-sm">
              <Button variant="secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>
                Trước
              </Button>
              <span>
                Trang {page}/{pageCount(list.data.total, list.data.per_page)}
              </span>
              <Button variant="secondary" disabled={page >= pageCount(list.data.total, list.data.per_page)} onClick={() => setPage(page + 1)}>
                Sau
              </Button>
            </div>
          )}
        </Card>
      </div>
    </>
  )
}
