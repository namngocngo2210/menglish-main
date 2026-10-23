/**
 * Chặn phím tắt in / lưu trang (Ctrl/⌘+P, Ctrl/⌘+S) khi đang xem tài liệu "Chỉ xem".
 * Chỉ răn đe người dùng phổ thông — web không chặn được chụp / quay màn hình.
 *   useCopyGuard(computed(() => !doc.can_download));
 * Gọi trong setup của component; listener gỡ khi component bị huỷ.
 */
import { onBeforeUnmount, onMounted, watch } from 'vue';

function block(event) {
    if ((event.ctrlKey || event.metaKey) && ['p', 's'].includes(event.key?.toLowerCase())) event.preventDefault();
}

export function useCopyGuard(enabled) {
    let attached = false;
    const sync = () => {
        const want = !!enabled.value;
        if (want === attached) return;
        attached = want;
        if (want) window.addEventListener('keydown', block, true);
        else window.removeEventListener('keydown', block, true);
    };

    // Chỉ chạy trên trình duyệt (SSR không gọi onMounted, không đổi enabled).
    onMounted(sync);
    watch(enabled, sync);
    onBeforeUnmount(() => {
        if (attached) window.removeEventListener('keydown', block, true);
        attached = false;
    });
}
