// Trang của lớp trong cổng giáo viên: /teacher/classes/<id>/<homework|scores|remarks>.
import { Card } from '../../components/ui'
import { usePath } from '../../router'
import { HomeworkPage } from './HomeworkPage'
import { classRoute } from './logic'
import { RemarksPage } from './RemarksPage'
import { ScoresPage } from './ScoresPage'

export function TeacherClassPage() {
  const r = classRoute(usePath())
  if (!r) return <Card title="Không tìm thấy trang">Đường dẫn này không tồn tại.</Card>
  if (r.tab === 'homework') return <HomeworkPage key={r.id} id={r.id} />
  if (r.tab === 'scores') return <ScoresPage key={r.id} id={r.id} />
  return <RemarksPage key={r.id} id={r.id} />
}
