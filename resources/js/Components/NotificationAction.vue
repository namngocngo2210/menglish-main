<script setup>
/**
 * Nút hành động của thông báo (docs/kho-syllabus.md §6.1): XÁC NHẬN / NHẮC LẠI (post), GIAO CHẶNG / XỬ LÝ (get), ĐÃ XEM (read).
 * Bấm xong thì đánh dấu thông báo đã đọc (người có quyền xem thông báo).
 */
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { route } from '@/lib/route';
import { can } from '@/lib/can';

const props = defineProps({
    action: { type: Object, required: true },
    notificationId: { type: Number, required: true },
    isRead: { type: Boolean, default: false },
    size: { type: String, default: 'sm' },
});

const busy = ref(false);

function markRead(then) {
    if (props.isRead || !can('notification.view')) return then?.();
    router.post(route('notifications.read', props.notificationId), {}, { preserveScroll: true, onFinish: () => then?.() });
}

function run() {
    if (busy.value) return;
    busy.value = true;
    const done = () => (busy.value = false);
    const { method, url } = props.action;
    if (method === 'post') {
        let ok = false;
        router.post(url, {}, { preserveScroll: true, onSuccess: () => (ok = true), onFinish: () => (ok ? markRead(done) : done()) });
    } else if (method === 'get') {
        markRead(() => router.visit(url, { onFinish: done }));
    } else {
        markRead(done);
    }
}
</script>

<template>
    <UiButton :size="size" :variant="action.method === 'read' ? 'secondary' : 'primary'" :disabled="busy" @click.stop="run">{{ action.label }}</UiButton>
</template>
