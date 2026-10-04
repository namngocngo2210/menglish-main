<script setup>
/**
 * Trang Thông báo (mở từ chuông): thống kê, lọc nhanh (Tất cả / Lead tồn đọng >24h / Chưa đọc), danh sách + đánh dấu đã đọc.
 * Trang đứng riêng nên không hiện thanh tab workspace "Cá nhân". Phần Lead tồn đọng chỉ cho người xem khách (lead.view),
 * nút "Quét lại hệ thống" chỉ cho notification.manage (GV / TA / Kế toán chỉ thấy thông báo của mình).
 */
import { Link } from '@inertiajs/vue3';
import { can } from '@/lib/can';
import { shortenCodesIn } from '@/lib/format';
import { copyText } from '@/lib/clipboard';

defineOptions({ layout: { title: 'Thông báo', workspaceTabs: false } });

defineProps({
    notifications: { type: Object, required: true },
    stats: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
});
</script>

<template>
    <UiPageHeader title="Thông báo" icon="notifications_active">
        <template #actions>
            <UiForm v-if="can('notification.manage')" :action="route('notifications.scan')" method="post" class="inline">
                <UiButton type="submit" variant="secondary" size="sm" icon="sync">Quét lại hệ thống</UiButton>
            </UiForm>
            <UiForm :action="route('notifications.read-all')" method="post" class="inline">
                <UiButton type="submit" size="sm" icon="done_all">Đánh dấu tất cả đã đọc</UiButton>
            </UiForm>
        </template>
    </UiPageHeader>

    <div class="space-y-6">
        <div :class="['grid grid-cols-1 gap-4', can('lead.view') ? 'sm:grid-cols-3' : 'sm:grid-cols-2']">
            <UiStatCard label="Tổng thông báo" :value="stats.total" icon="notifications" />
            <UiStatCard v-if="can('lead.view')" label="Lead bị sót >24h (Chưa xử lý)" :value="stats.stale_leads" tone="error" icon="person_alert" />
            <UiStatCard label="Thông báo chưa đọc" :value="stats.unread" tone="warning" icon="mark_email_unread" />
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-4 shadow-sm">
            <div class="flex flex-wrap items-center gap-2">
                <Link :href="route('notifications.index')" :class="['rounded-xl px-3 py-1.5 text-xs font-bold transition', !filters.type && !filters.unread ? 'bg-primary-container text-white' : 'bg-surface-container text-on-surface-variant hover:bg-surface-container-high']">Tất cả</Link>
                <Link v-if="can('lead.view')" :href="route('notifications.index', { type: 'stale_lead_24h' })" :class="['rounded-xl px-3 py-1.5 text-xs font-bold transition', filters.type === 'stale_lead_24h' ? 'bg-error text-white' : 'border border-error/30 bg-error/10 text-error hover:bg-error/20']">
                    <span class="inline-flex items-center gap-xs"><span class="material-symbols-outlined text-[16px]" aria-hidden="true">warning</span>Lead tồn đọng &gt;24h ({{ stats.stale_leads }})</span>
                </Link>
                <Link :href="route('notifications.index', { unread: 1 })" :class="['rounded-xl px-3 py-1.5 text-xs font-bold transition', filters.unread ? 'bg-warning text-white' : 'border border-warning/30 bg-warning-container text-on-warning-container hover:bg-warning/20']">Chưa đọc ({{ stats.unread }})</Link>
            </div>
        </div>

        <div class="space-y-3">
            <div v-for="notif in notifications.data" :key="notif.id" :class="['rounded-2xl border bg-surface-container-lowest p-5 transition hover:shadow-md', !notif.is_read ? 'border-error/30 bg-error/5 shadow-sm' : 'border-surface-container-highest opacity-80']">
                <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                    <div class="flex min-w-0 flex-1 items-start gap-3.5">
                        <div :class="['flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl', notif.badge_color]">
                            <span class="material-symbols-outlined text-xl">{{ notif.icon }}</span>
                        </div>
                        <div class="min-w-0 flex-1 space-y-1.5">
                            <div class="flex flex-wrap items-center gap-2">
                                <span :class="['rounded-full border px-2.5 py-0.5 text-xs font-bold', notif.badge_color]">{{ notif.type_label }}</span>
                                <h3 class="text-sm font-bold text-on-surface">{{ shortenCodesIn(notif.title) }}</h3>
                                <span v-if="!notif.is_read" class="inline-block h-2 w-2 animate-pulse rounded-full bg-error"></span>
                            </div>

                            <p class="text-xs leading-relaxed text-on-surface-variant">{{ notif.message }}</p>

                            <div v-if="notif.lead" class="flex flex-wrap items-center gap-4 pt-1 text-xs font-medium text-on-surface-variant">
                                <span>Khách hàng: <strong class="text-on-surface">{{ notif.lead.customer_name }}</strong></span>
                                <span>SĐT: <strong class="font-mono text-on-surface">{{ notif.lead.customer_phone }}</strong></span>
                                <span>Sales: <strong class="text-secondary">{{ notif.lead.assigned_user }}</strong></span>
                                <span>Thời gian trễ: <strong class="font-mono font-bold text-error">{{ notif.lead.hours_elapsed }}h</strong></span>
                            </div>

                            <div v-if="notif.copy_text" class="whitespace-pre-line break-words rounded-xl border border-surface-container-highest bg-surface-container-low p-3 text-xs leading-relaxed text-on-surface">{{ notif.copy_text }}</div>

                            <div class="pt-1 font-mono text-xs text-on-surface-subtle">Ghi nhận lúc: {{ formatDate(notif.created_at, 'd/m/Y H:i') }} ({{ notif.created_ago }})</div>
                        </div>
                    </div>

                    <div class="flex shrink-0 flex-wrap items-center justify-end gap-2 self-end sm:self-center">
                        <UiButton v-if="notif.lead" variant="info" size="sm" icon="call" :href="route('crm.customers.show', notif.lead.customer_id)">Xử lý Lead ngay</UiButton>
                        <UiButton v-if="notif.copy_text" size="sm" icon="content_copy" @click="copyText(notif.copy_text, 'Đã sao chép nội dung — dán vào Zalo để gửi phụ huynh.')">Sao chép nội dung</UiButton>
                        <UiButton v-if="notif.copy_text && notif.link && !notif.lead" variant="secondary" size="sm" icon="open_in_new" :href="notif.link">Mở hồ sơ</UiButton>
                        <UiForm v-if="!notif.is_read" :action="route('notifications.read', notif.id)" method="post" class="inline">
                            <UiButton type="submit" variant="secondary" size="sm" icon="check" title="Đánh dấu đã đọc" aria-label="Đánh dấu đã đọc" />
                        </UiForm>
                    </div>
                </div>
            </div>
            <div v-if="!notifications.data.length" class="rounded-2xl border border-surface-container-highest bg-surface-container-lowest">
                <UiEmptyState icon="task_alt" title="Hệ thống đang hoạt động tối ưu!" description="Không có Lead nào bị sót quá 24h và không có thông báo cảnh báo chưa xử lý." />
            </div>
        </div>

        <div class="overflow-hidden rounded-2xl border border-outline-variant bg-surface-container-low shadow-sm">
            <UiPagination :paginator="notifications" />
        </div>
    </div>
</template>
