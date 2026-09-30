<script setup>
/** Chuông thông báo trên topbar: cổng học viên → link hộp thư của cổng; còn lại → menu 6 thông báo mới nhất. */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({ notifications: { type: Object, required: true } });
const n = computed(() => props.notifications);
const unreadText = computed(() => (n.value.unread > 9 ? '9+' : String(n.value.unread)));
</script>

<template>
    <Link v-if="n.portal" :href="n.url" class="relative inline-flex items-center justify-center rounded-lg p-2 text-on-surface-variant transition-colors hover:bg-surface-container-high hover:text-primary max-md:min-h-11 max-md:min-w-11" :aria-label="`Thông báo${n.unread > 0 ? ` (${n.unread} chưa đọc)` : ''}`">
        <span class="material-symbols-outlined" aria-hidden="true">notifications</span>
        <span v-if="n.unread > 0" class="absolute right-1 top-1 flex h-[18px] min-w-[18px] items-center justify-center rounded-full border-2 border-surface bg-error px-1 font-code text-xs font-bold text-white" aria-hidden="true">{{ unreadText }}</span>
    </Link>
    <UiDropdown v-else align="right" width="notification">
        <template #trigger>
            <button type="button" class="relative inline-flex items-center justify-center rounded-lg p-2 text-on-surface-variant transition-colors hover:bg-surface-container-high hover:text-primary max-md:min-h-11 max-md:min-w-11" aria-label="Thông báo">
                <span class="material-symbols-outlined" aria-hidden="true">notifications</span>
                <span v-if="n.unread > 0" class="absolute right-1 top-1 flex h-[18px] min-w-[18px] items-center justify-center rounded-full border-2 border-surface bg-error px-1 font-code text-xs font-bold text-white">{{ unreadText }}</span>
            </button>
        </template>
        <template #content>
            <div class="flex items-center justify-between border-b border-surface-container bg-surface-container-low p-md">
                <div class="flex items-center gap-xs font-body-semibold text-body-semibold text-on-surface">
                    <span class="material-symbols-outlined text-[18px] text-error" aria-hidden="true">notifications_active</span>
                    <span>Thông báo</span>
                    <UiBadge v-if="n.unread > 0" color="error" :dot="false" pill>{{ n.unread }} chưa đọc</UiBadge>
                </div>
                <Link v-if="n.unread > 0 && n.read_all_url" :href="n.read_all_url" method="post" as="button" preserve-scroll class="font-caption text-caption font-semibold text-primary hover:underline">Đã đọc tất cả</Link>
            </div>
            <div class="max-h-[380px] divide-y divide-surface-container overflow-y-auto">
                <div v-for="item in n.items" :key="item.id" :class="['flex items-start gap-sm p-md transition-colors hover:bg-surface-container-low', item.is_read ? '' : 'bg-error-container/20']">
                    <div :class="['flex h-9 w-9 shrink-0 items-center justify-center rounded-lg', item.badge_color]">
                        <span class="material-symbols-outlined text-[18px]" aria-hidden="true">{{ item.icon }}</span>
                    </div>
                    <div class="min-w-0 flex-1 space-y-xs">
                        <div class="flex items-center justify-between gap-sm">
                            <span class="truncate font-body-semibold text-body-small text-on-surface">{{ item.title }}</span>
                            <span v-if="!item.is_read" class="h-2 w-2 shrink-0 rounded-full bg-error"></span>
                        </div>
                        <p class="line-clamp-2 font-caption text-caption text-on-surface-variant">{{ item.message }}</p>
                        <div class="flex items-center justify-between">
                            <span class="font-code text-xs text-on-surface-subtle">{{ item.ago }}</span>
                            <a v-if="item.link" :href="item.link" class="inline-flex items-center gap-0.5 font-caption text-caption font-semibold text-secondary hover:underline">
                                <span>Xử lý ngay</span>
                                <span class="material-symbols-outlined text-[14px]" aria-hidden="true">arrow_forward</span>
                            </a>
                        </div>
                    </div>
                </div>
                <UiEmptyState v-if="!n.items.length" icon="notifications_paused" title="Không có thông báo" description="Chưa có thông báo hoặc cảnh báo nào." class="!py-lg" />
            </div>
            <div v-if="n.index_url" class="border-t border-surface-container bg-surface-container-low p-sm text-center">
                <Link :href="n.index_url" class="inline-flex items-center gap-xs font-body-small text-body-small font-semibold text-primary hover:underline">
                    <span>Xem tất cả thông báo</span>
                    <span class="material-symbols-outlined text-[16px]" aria-hidden="true">chevron_right</span>
                </Link>
            </div>
        </template>
    </UiDropdown>
</template>
