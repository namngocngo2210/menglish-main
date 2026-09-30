<script setup>
/**
 * "Nhận xét học thử" (Cổng Giáo viên): khách học thử trên buổi dạy của giáo viên, điểm danh + nhận xét như học sinh chính thức.
 * Nhận xét lưu vào hồ sơ khách tuyển sinh (TrialGuestController::feedback). Chỉ mở nhận xét từ ngày học thử.
 */
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import TeacherBottomNav from '@/Components/Teacher/TeacherBottomNav.vue';

defineOptions({ layout: { title: 'Nhận xét học thử', hideErrors: true } });

defineProps({
    scope: { type: String, default: 'upcoming' },
    bookings: { type: Object, required: true },
    remarkFields: { type: Array, default: () => [] },
});

const page = usePage();
const firstError = computed(() => Object.values(page.props.errors ?? {})[0] ?? null);

const statusOptions = [
    { value: 'attended', label: 'Có mặt (đã học thử)' },
    { value: 'no_show', label: 'Vắng mặt' },
];
const ratingOptions = [5, 4, 3, 2, 1].map((rating) => ({ value: rating, label: `${rating}/5` }));
const remarkPlaceholders = { grammar: 'Khá', attitude: 'Hăng hái', result: 'Đạt mục tiêu' };
const statusColor = (status) => (status === 'attended' ? 'success' : status === 'no_show' ? 'error' : 'info');
</script>

<template>
    <UiPageHeader title="Nhận xét học thử" icon="school" description="Khách học thử (chưa chốt) trên buổi dạy của bạn. Nhận xét như học sinh chính thức — lưu vào hồ sơ khách tuyển sinh để Học vụ / tư vấn viên theo dõi.">
        <template #actions>
            <UiButton variant="secondary" icon="arrow_back" :href="route('teacher.home')">Về trang chủ</UiButton>
        </template>
    </UiPageHeader>

    <div class="space-y-4">
        <UiAlert v-if="firstError" type="error">{{ firstError }}</UiAlert>

        <UiTabs>
            <UiTab :href="route('teacher.trial-guests', { scope: 'upcoming' })" :active="scope === 'upcoming'">Hôm nay &amp; sắp tới</UiTab>
            <UiTab :href="route('teacher.trial-guests', { scope: 'past' })" :active="scope === 'past'">Đã diễn ra</UiTab>
        </UiTabs>

        <UiDataTable min-width="960px" class="shadow-sm">
            <table class="text-xs">
                <thead>
                    <tr>
                        <th class="w-[200px]">Buổi học</th>
                        <th class="w-[200px]">Khách học thử</th>
                        <th class="w-[110px]">Trạng thái</th>
                        <th>Nhận xét của giáo viên</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="booking in bookings.data" :key="booking.id" class="align-top">
                        <td>
                            <div class="font-bold text-on-surface">{{ booking.class_name }}</div>
                            <div class="text-on-surface-variant">{{ booking.session_date }} · {{ booking.session_time }}</div>
                            <div class="text-on-surface-subtle">{{ booking.course_name }}</div>
                        </td>
                        <td>
                            <div class="font-bold text-on-surface">{{ booking.customer_name }}</div>
                            <div v-if="booking.parent_name" class="text-on-surface-variant">PH: {{ booking.parent_name }}</div>
                            <div class="text-on-surface-variant">Kết quả test: {{ booking.test_score ?? 'Chưa test' }}</div>
                            <div v-if="booking.notes" class="mt-1 text-on-surface-variant">Ghi chú của Học vụ: {{ booking.notes }}</div>
                        </td>
                        <td>
                            <UiBadge :color="statusColor(booking.status)" pill>{{ booking.status_label }}</UiBadge>
                        </td>
                        <td>
                            <div v-if="booking.feedback_at" class="mb-2 space-y-0.5 text-on-surface-variant">
                                <div v-if="booking.rating">
                                    <span class="font-bold">{{ booking.rating }}/5</span><template v-if="booking.remarks_summary"> · {{ booking.remarks_summary }}</template>
                                </div>
                                <div>{{ booking.feedback || '—' }}</div>
                                <div class="text-xs text-on-surface-subtle">{{ booking.feedback_by }} · {{ booking.feedback_at }}</div>
                            </div>
                            <UiForm v-if="booking.started" :action="route('teacher.trial-guests.feedback', booking.id)" method="post" class="space-y-2">
                                <div class="flex flex-wrap gap-2">
                                    <UiSelect :id="`trial-status-${booking.id}`" name="status" class="text-xs" aria-label="Trạng thái" :value="booking.status === 'no_show' ? 'no_show' : 'attended'" :options="statusOptions" />
                                    <UiSelect :id="`trial-rating-${booking.id}`" name="rating" class="text-xs" title="Mức độ phù hợp với lớp" :value="booking.rating ?? 4" :options="ratingOptions" />
                                </div>
                                <div class="grid grid-cols-3 gap-2">
                                    <label v-for="field in remarkFields" :key="field.key" class="block">
                                        <span class="text-xs font-semibold uppercase text-on-surface-variant">{{ field.label }}</span>
                                        <input
                                            type="text"
                                            :name="`remarks[${field.key}]`"
                                            :value="booking.remarks[field.key] ?? ''"
                                            maxlength="255"
                                            class="w-full rounded-lg border-outline-variant bg-surface-container-lowest text-xs text-on-surface focus:border-primary-container focus:ring-primary-container/50"
                                            :placeholder="remarkPlaceholders[field.key]"
                                        />
                                    </label>
                                </div>
                                <textarea
                                    name="feedback"
                                    rows="2"
                                    maxlength="3000"
                                    placeholder="Nhận xét chi tiết: mức độ phù hợp với lớp, tương tác, đề xuất..."
                                    class="w-full rounded-lg border-outline-variant bg-surface-container-lowest text-xs text-on-surface focus:border-primary-container focus:ring-primary-container/50"
                                    :value="booking.feedback ?? ''"
                                ></textarea>
                                <UiButton type="submit" size="sm" icon="rate_review">Lưu nhận xét</UiButton>
                            </UiForm>
                            <p v-else class="italic text-on-surface-subtle">Nhận xét được mở từ ngày học thử.</p>
                        </td>
                    </tr>
                    <tr v-if="!bookings.data.length">
                        <td colspan="4"><UiEmptyState icon="school" :title="scope === 'past' ? 'Chưa có buổi học thử nào đã diễn ra.' : 'Không có khách học thử sắp tới trên buổi dạy của bạn.'" /></td>
                    </tr>
                </tbody>
            </table>
            <template #footer><UiPagination :paginator="bookings" /></template>
        </UiDataTable>
    </div>
    <div class="h-20 md:hidden" aria-hidden="true"></div>

    <TeacherBottomNav />
</template>
