import { onMounted, reactive, ref, watch } from 'vue';

/**
 * Ghi chú báo cáo giữ trên máy người viết (localStorage, rà soát 16/10/2026 B5): không mất chữ đang gõ khi bấm "Cập nhật số liệu",
 * đổi ngày / tuần rồi quay lại, hoặc lỡ đóng tab. Bản nháp chỉ đọc sau khi trang chạy trên trình duyệt (SSR không có localStorage);
 * trùng với bản đã lưu trên máy chủ thì tự xoá.
 */
export function useNoteDraft(key, initial) {
    const notes = reactive({ ...initial });
    const restored = ref(false);
    const norm = (v) => (v ?? '').toString().trim();
    const same = (a, b) => [...new Set([...Object.keys(a), ...Object.keys(b)])].every((k) => norm(a[k]) === norm(b[k]));
    const read = () => {
        try {
            const raw = window.localStorage.getItem(key);
            return raw ? JSON.parse(raw) : null;
        } catch {
            return null;
        }
    };
    const write = (value) => {
        try {
            window.localStorage.setItem(key, JSON.stringify(value));
        } catch {
            // Trình duyệt chặn lưu trữ: vẫn dùng được, chỉ không có bản nháp.
        }
    };
    const clear = () => {
        try {
            window.localStorage.removeItem(key);
        } catch {
            // bỏ qua
        }
        restored.value = false;
    };
    const discard = () => {
        clear();
        Object.keys(notes).forEach((k) => delete notes[k]);
        Object.assign(notes, initial);
    };

    onMounted(() => {
        const saved = read();
        if (saved && typeof saved === 'object' && !same(saved, initial)) {
            Object.assign(notes, saved);
            restored.value = true;
        } else if (saved) {
            clear();
        }
        watch(notes, (value) => (same(value, initial) ? clear() : write({ ...value })), { deep: true });
    });

    return { notes, restored, clear, discard };
}
