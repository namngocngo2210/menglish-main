// Hook và khung dùng chung của các màn nhập báo cáo: kỳ trên URL, lỗi "không thuộc vai trò", thông báo lưu xong.
import { useState, type ReactNode } from 'react'
import { ApiError, errorMessage } from '../../api'
import { Alert, Button } from '../../components/ui'
import { navigate } from '../../router'
import { Loading } from './components'
import { readQuery, writeQuery } from './logic'

// Kỳ báo cáo đang xem, đồng bộ lên thanh địa chỉ (?date= / ?week= / ?month= / ?quarter=).
export function usePeriod(param: string) {
  const [value, setValue] = useState(() => readQuery(param))
  const set = (v: string) => {
    setValue(v)
    writeQuery({ [param]: v })
  }
  return [value, set] as const
}

// Trạng thái tải: đang tải / lỗi (báo cáo không thuộc vai trò → nút về báo cáo định kỳ chính) / nội dung.
export function QueryState({ isPending, error, children }: { isPending: boolean; error: unknown; children: () => ReactNode }) {
  if (error) {
    const notForRole = error instanceof ApiError && error.code === 'report_not_for_role'
    return (
      <div className="space-y-3">
        <Alert>{errorMessage(error)}</Alert>
        {notForRole && (
          <Button variant="secondary" onClick={() => navigate('/reports')}>
            Về báo cáo định kỳ của tôi
          </Button>
        )}
      </div>
    )
  }
  if (isPending) return <Loading />
  return <>{children()}</>
}

// Kết quả lưu: thông báo thành công từ máy chủ hoặc lỗi kiểm tra dữ liệu.
export function SaveResult({ message, error }: { message: string | null; error: unknown }) {
  if (error) return <Alert>{errorMessage(error)}</Alert>
  if (message) return <Alert tone="success">{message}</Alert>
  return null
}

// Đổi đường dẫn không thêm lịch sử (mở màn báo cáo chính thay cho /reports, nút Back không quay lại vòng chuyển hướng).
export function replacePath(path: string) {
  window.history.replaceState(null, '', path)
  window.dispatchEvent(new PopStateEvent('popstate'))
}
