<script setup>
/**
 * Lịch học thử: các buổi học thử đã đặt cho khách tuyển sinh (chỉ khách trong phạm vi người xem).
 * Đặt / hủy học thử ở hồ sơ khách (CRM); giáo viên buổi đó nhận xét vào hồ sơ.
 */
import { router } from '@inertiajs/vue3';
import { urlWith } from '@/lib/url';
import { route } from '@/lib/route';

defineOptions({ layout: { title: 'Lịch học thử' } });

const props = defineProps({
    bookings: { type: Object, required: true },
    scope: { type: String, default: 'upcoming' },
    status: { type: String, default: null },
    statuses: { type: Array, default: () => [] },
});

const tabUrl = (scope) => route('classes.trial-booking', props.status ? { scope, status: props.status } : { scope });
const statusColor = (s) => ({ attended: 'success', no_show: 'error', cancelled: 'neutral' })[s] ?? 'info';

/** Đổi trạng thái → lọc ngay (giữ tab đang xem, về trang 1). */
function filterStatus(value) {
    router.get(urlWith({ status: value, page: null, scope: props.scope }), {}, { preserveScroll: true });
}
</script>

<template>
    <UiPageHeader title="Lịch học thử" icon="event_available" description="Các buổi học thử đã đặt cho khách tuyển sinh. Đặt hoặc hủy học thử trong hồ sơ khách (CRM); giáo viên buổi đó thấy khách và nhận xét vào hồ sơ." />

    <div class="space-y-4">
        <UiTabs>
            <UiTab :href="tabUrl('upcoming')" :active="scope === 'upcoming'">Hôm nay &amp; sắp tới</UiTab>
            <UiTab :href="tabUrl('past')" :active="scope === 'past'">Đã diễn ra</UiTab>
        </UiTabs>

        <div class="flex flex-wrap items-center gap-sm">
            <UiSelect name="status" aria-label="Trạng thái" placeholder="Tất cả trạng thái" :model-value="status ?? ''" :options="statuses" @update:model-value="filterStatus" />
        </div>

        <UiDataTable min-width="960px" class="shadow-sm">
            <table class="text-xs">
                <thead>
                    <tr>
                        <th class="w-[200px]">Buổi học</th>
                        <th class="w-[220px]">Khách học thử</th>
                        <th class="w-[110px]">Trạng thái</th>
                        <th>Nhận xét của giáo viên</th>
                        <th class="w-[120px] text-right">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="b in bookings.data" :key="b.id" class="align-top">
                        <td>
                            <div class="font-bold text-on-surface">{{ b.class_name }}</div>
                            <div class="text-on-surface-variant">{{ formatDate(b.date) }} · {{ b.start }}–{{ b.end }}</div>
                            <div class="text-on-surface-subtle">{{ b.course_name }} · {{ b.branch_name }}</div>
                        </td>
                        <td>
                            <div class="font-bold text-on-surface">{{ b.customer_name }}</div>
                            <div v-if="b.parent_name" class="text-on-surface-variant">PH: {{ b.parent_name }}</div>
                            <div class="text-on-surface-variant">{{ b.stage_label }} · Test: {{ b.test_score ?? 'Chưa test' }}</div>
                            <div class="text-on-surface-subtle">Phụ trách: {{ b.assigned_name ?? '—' }} · Đặt bởi: {{ b.booked_by ?? '—' }}</div>
                        </td>
                        <td>
                            <UiBadge :color="statusColor(b.status)" pill>{{ b.status_label }}</UiBadge>
                        </td>
                        <td>
                            <template v-if="b.feedback_at">
                                <div v-if="b.rating">
                                    <span class="font-bold">{{ b.rating }}/5</span><template v-if="b.remarks"> · {{ b.remarks }}</template>
                                </div>
                                <div class="text-on-surface-variant">{{ b.feedback || '—' }}</div>
                                <div class="text-xs text-on-surface-subtle">{{ b.feedback_by }} · {{ formatDate(b.feedback_at, 'd/m/Y H:i') }}</div>
                            </template>
                            <span v-else class="italic text-on-surface-subtle">Chưa có nhận xét</span>
                        </td>
                        <td class="text-right">
                            <UiButton v-if="b.customer_id" variant="ghost" size="sm" icon="visibility" :href="route('crm.customers.show', b.customer_id)">Hồ sơ khách</UiButton>
                        </td>
                    </tr>
                    <tr v-if="!bookings.data.length">
                        <td colspan="5">
                            <UiEmptyState icon="event_available" :title="scope === 'past' ? 'Chưa có buổi học thử nào đã diễn ra.' : 'Chưa có lịch học thử sắp tới.'" description="Đặt học thử trong hồ sơ khách tuyển sinh (nút Đặt lịch học thử)." />
                        </td>
                    </tr>
                </tbody>
            </table>
            <template #footer><UiPagination :paginator="bookings" unit="buổi" /></template>
        </UiDataTable>
    </div>
</template>
