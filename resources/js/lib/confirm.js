/**
 * Hộp xác nhận chung (thay cho window.confirm của trình duyệt). Giao diện: ConfirmDialogHost trong AppLayout.
 *   if (await confirmDialog({ message: 'Xóa khóa học A?', confirmLabel: 'Xóa', danger: true })) { … }
 *   <UiForm confirm="Xóa khóa học A?" confirm-label="Xóa" danger …>      // form tự hỏi trước khi gửi
 * Esc / bấm nền = Hủy. Thao tác nguy hiểm (danger): focus nút Hủy trước để Enter không xóa nhầm.
 */
import { reactive } from 'vue';

export const DEFAULT_CONFIRM_TITLE = 'Xác nhận thao tác';

export const confirmState = reactive({
    open: false,
    title: DEFAULT_CONFIRM_TITLE,
    message: '',
    confirmLabel: 'Đồng ý',
    cancelLabel: 'Hủy',
    danger: false,
    resolve: null,
});

export function confirmDialog({ title = DEFAULT_CONFIRM_TITLE, message = '', confirmLabel = 'Đồng ý', cancelLabel = 'Hủy', danger = false } = {}) {
    // Hộp đang mở (bấm liên tiếp) → coi như Hủy hộp cũ.
    confirmState.resolve?.(false);
    return new Promise((resolve) => {
        Object.assign(confirmState, { open: true, title, message, confirmLabel, cancelLabel, danger, resolve });
    });
}

export function settleConfirm(result) {
    const resolve = confirmState.resolve;
    confirmState.open = false;
    confirmState.resolve = null;
    resolve?.(result);
}
