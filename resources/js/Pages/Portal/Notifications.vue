<script setup>
/**
 * Hộp thư thông báo của học viên (MH #5): đánh dấu tất cả / từng thông báo đã đọc, xóa thông báo.
 * Trên điện thoại: thanh điều hướng đáy là điều hướng chính, ẩn tiêu đề/nút quay lại và dải tab.
 */
import { computed } from 'vue';
import PortalBottomNav from './PortalBottomNav.vue';
import PortalPageHeader from './PortalPageHeader.vue';
import PortalTopHeader from './PortalTopHeader.vue';

defineOptions({ layout: { title: 'Thông báo', workspaceTabs: false } });

const props = defineProps({
    student: { type: Object, default: null },
    students: { type: Array, default: () => [] },
    unreadCount: { type: Number, default: 0 },
    notifications: { type: Array, default: () => [] },
});

const params = computed(() => ({ studentId: props.student?.id ?? null }));
</script>

<template>
    <PortalPageHeader title="Thông báo" icon="notifications" :back="route('portal.student.home', params)">
        <template #actions>
            <UiButton variant="secondary" icon="cottage" :href="route('portal.student.home', params)">Về Trang chủ</UiButton>
        </template>
    </PortalPageHeader>

    <!-- Khung điện thoại -->
    <div class="relative mx-auto my-4 flex min-h-[844px] max-w-[430px] flex-col overflow-hidden rounded-3xl border border-surface-container-highest bg-surface-container-lowest pb-24 shadow-2xl md:min-h-0 md:max-w-4xl md:pb-6 md:shadow-sm">
        <PortalTopHeader :student="student" :students="students" title="Thông báo" show-back :back-url="route('portal.student.home', params)" />

        <div class="flex items-center justify-between border-b border-surface-container-highest bg-surface-container-low/80 px-4 py-3">
            <span class="text-xs font-bold text-on-surface">Tất cả thông báo</span>
            <UiForm :action="route('portal.student.notifications.read')" method="post">
                <input type="hidden" name="student_id" :value="student?.id" />
                <UiButton type="submit" variant="ghost" size="sm" class="text-primary">Đánh dấu tất cả đã đọc</UiButton>
            </UiForm>
        </div>

        <div class="flex-1 overflow-y-auto">
            <div class="divide-y divide-surface-container-highest">
                <div v-for="notif in notifications" :key="notif.id" :class="['group relative flex items-start px-4 py-3.5 transition-colors hover:bg-surface-container-low', notif.unread ? 'bg-primary-container/10' : 'opacity-80']">
                    <!-- Chấm chưa đọc -->
                    <div v-if="notif.unread" class="absolute left-2 top-1/2 h-2 w-2 -translate-y-1/2 rounded-full bg-primary-container shadow-xs"></div>

                    <div :class="['ml-2 mr-3 flex h-10 w-10 shrink-0 items-center justify-center rounded-full', notif.bg_color]">
                        <span :class="['material-symbols-outlined text-[20px]', notif.text_color]" style="font-variation-settings: 'FILL' 1">{{ notif.icon }}</span>
                    </div>

                    <div class="min-w-0 flex-1">
                        <div class="mb-0.5 flex items-start justify-between">
                            <h3 :class="['truncate pr-2 text-xs', notif.unread ? 'font-bold text-on-surface' : 'font-medium text-on-surface-variant']">{{ notif.title }}</h3>
                            <span class="whitespace-nowrap text-xs font-medium text-primary">{{ notif.time }}</span>
                        </div>
                        <p class="line-clamp-2 text-xs leading-relaxed text-on-surface-variant">{{ notif.content }}</p>

                        <div class="mt-2 flex items-center gap-3 text-xs">
                            <UiForm v-if="notif.unread" :action="route('portal.student.notifications.read-single', notif.id)" method="post" class="inline">
                                <UiButton type="submit" variant="ghost" size="sm" icon="drafts" class="text-primary">Đánh dấu đã đọc</UiButton>
                            </UiForm>
                            <UiForm :action="route('portal.student.notifications.destroy', notif.id)" method="delete" class="inline" confirm="Xóa thông báo này?" confirm-label="Xóa" danger>
                                <UiButton type="submit" variant="danger-text" size="sm" icon="delete">Xóa</UiButton>
                            </UiForm>
                        </div>
                    </div>
                </div>
                <UiEmptyState v-if="!notifications.length" icon="notifications_off" title="Chưa có thông báo nào." />
            </div>

            <div class="flex items-center justify-center gap-1.5 py-6 text-center text-xs text-on-surface-subtle">
                <span class="material-symbols-outlined text-[16px]">done_all</span>
                <span>Đã tải hết thông báo gần đây</span>
            </div>
        </div>

        <PortalBottomNav active-tab="notifications" :student="student" :unread-count="unreadCount" />
    </div>
</template>
