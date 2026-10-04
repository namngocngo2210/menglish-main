<script setup>
/**
 * Mục cấp 1 của sidebar (workspace / Tổng quan / Cài đặt); badge = số việc chờ (vd. Cần duyệt).
 * `modal` (cỡ modal) → bấm mở trang đó trong modal chung thay vì chuyển trang (Ctrl/⌘-click vẫn mở tab mới).
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { openRemoteModal } from '@/lib/remoteModal';

const props = defineProps({
    url: { type: String, required: true },
    label: { type: String, required: true },
    icon: { type: String, required: true },
    active: { type: Boolean, default: false },
    id: { type: String, required: true },
    badge: { type: Number, default: null },
    modal: { type: String, default: null },
});
const emit = defineEmits(['modal']);
const badgeText = computed(() => (props.badge > 0 ? (props.badge > 99 ? '99+' : String(props.badge)) : null));

function openModal(event) {
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.button === 1) return;
    event.preventDefault();
    openRemoteModal(props.url, { size: props.modal });
    emit('modal');
}
</script>

<template>
    <component
        :is="modal ? 'a' : Link"
        :href="url"
        @click="modal ? openModal($event) : null"
        :aria-current="active ? 'page' : null"
        :data-tooltip="label + (badgeText ? ` (${badgeText})` : '')"
        :data-menu-item="id"
        data-sidebar-center
        :class="['relative flex items-center gap-md rounded-lg px-md py-sm max-md:min-h-11 font-body-medium text-body-medium transition-colors duration-150 active:scale-95 md:justify-center md:px-0 desktop:justify-start desktop:px-md', active ? 'bg-primary-container text-white shadow-lg shadow-primary-container/20' : 'hover:bg-white/10 hover:text-white']"
    >
        <span :class="['material-symbols-outlined shrink-0', active ? 'fill' : '']" aria-hidden="true">{{ icon }}</span>
        <span class="truncate md:hidden desktop:inline" data-sidebar-text>{{ label }}</span>
        <span v-if="badgeText" class="ml-auto min-w-[20px] rounded-full bg-error px-1.5 text-center font-code text-xs font-semibold leading-5 text-white md:absolute md:right-2 md:top-1 md:ml-0 md:min-w-[16px] md:px-1 md:leading-4 desktop:static desktop:ml-auto desktop:min-w-[20px] desktop:px-1.5 desktop:leading-5" data-approval-badge>{{ badgeText }}<span class="sr-only"> việc chờ duyệt</span></span>
    </component>
</template>
