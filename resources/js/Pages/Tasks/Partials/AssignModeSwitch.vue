<script setup>
/**
 * Chọn đối tượng giao việc: "Nhân sự" (tasks.create — 1 việc, giao 2 chiều) | "Trợ giảng" (tasks.ta-assign — nhiều đầu việc
 * theo ca, luật riêng). Trong modal: đổi nội dung modal tại chỗ; trang đầy đủ: chuyển trang.
 */
import { Link } from '@inertiajs/vue3';
import { openRemoteModal } from '@/lib/remoteModal';
import { useRemoteModal } from '@/Components/ui/modalContext';
import { route } from '@/lib/route';

defineProps({ current: { type: String, required: true } });

const modal = useRemoteModal();
const modes = [
    { key: 'staff', label: 'Nhân sự', icon: 'person', route: 'tasks.create', size: '2xl' },
    { key: 'assistant', label: 'Trợ giảng (theo ca)', icon: 'support_agent', route: 'tasks.ta-assign', size: '4xl' },
];

function go(event, mode) {
    if (!modal) return;
    event.preventDefault();
    openRemoteModal(route(mode.route), { size: mode.size });
}
</script>

<template>
    <div class="mb-md inline-flex rounded-lg border border-outline-variant bg-surface-container-low p-[2px]" role="tablist" aria-label="Giao cho">
        <span class="self-center px-sm font-body-small text-body-small text-on-surface-variant">Giao cho:</span>
        <component
            :is="modal ? 'a' : Link"
            v-for="mode in modes"
            :key="mode.key"
            :href="route(mode.route)"
            role="tab"
            :aria-selected="current === mode.key ? 'true' : 'false'"
            :data-assign-mode="mode.key"
            :class="[
                'inline-flex items-center gap-xs rounded-md px-sm py-xs font-body-small text-body-small transition-colors',
                current === mode.key ? 'bg-surface-container-lowest font-semibold text-primary shadow-sm' : 'text-on-surface-variant hover:text-primary',
            ]"
            @click="go($event, mode)"
        >
            <span class="material-symbols-outlined text-[16px]" aria-hidden="true">{{ mode.icon }}</span>{{ mode.label }}
        </component>
    </div>
</template>
