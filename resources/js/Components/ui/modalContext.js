/**
 * Ngữ cảnh modal tải từ server (RemoteModalHost cung cấp): trang Inertia đang hiển thị trong modal hay trang đầy đủ.
 *   const modal = useRemoteModal();   // null khi là trang đầy đủ
 *   modal?.close();                   // đóng modal
 *   modal?.reload();                  // tải lại nội dung modal (vd. sau khi gửi phản hồi ticket)
 */
import { inject } from 'vue';

export const REMOTE_MODAL = Symbol('remoteModal');

export function useRemoteModal() {
    return inject(REMOTE_MODAL, null);
}
