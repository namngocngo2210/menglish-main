<script setup>
/**
 * Rà soát điểm danh (Học vụ): điểm danh học viên theo ngày / lớp, thống kê có mặt / muộn / vắng / có phép;
 * bản ghi chờ rà soát → "Duyệt" hoặc "Trả lại" (cập nhật số buổi học của học viên theo điểm danh đã duyệt).
 */
defineOptions({ layout: { title: 'Rà soát điểm danh (Học vụ)' } });

defineProps({
    records: { type: Array, default: () => [] },
    summary: { type: Object, required: true },
    classes: { type: Array, default: () => [] },
    date: { type: String, default: null },
    classId: { type: [String, Number], default: null },
});

const badgeColor = (status) => ({ present: 'success', late: 'warning', absent: 'error' })[status] ?? 'info';
</script>

<template>
    <div>
        <UiPageHeader title="Rà soát điểm danh (Học vụ)" icon="rule" />

        <div class="space-y-6">
            <UiFilterBar :search="false" class="!mb-0">
                <UiDate name="date" label="Ngày" :value="date" />
                <UiSelect name="class_id" label="Lớp" :value="classId ?? ''" placeholder="Tất cả lớp" :options="classes" />
            </UiFilterBar>

            <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                <UiStatCard label="Có mặt" :value="summary.present" tone="success" />
                <UiStatCard label="Đi muộn" :value="summary.late" tone="warning" />
                <UiStatCard label="Vắng" :value="summary.absent" tone="error" />
                <UiStatCard label="Có phép" :value="summary.excused" tone="secondary" />
            </div>

            <div class="divide-y divide-surface-container-highest rounded-2xl border border-surface-container-highest bg-surface-container-lowest shadow-sm">
                <div v-for="r in records" :key="r.id" class="flex items-center justify-between gap-3 p-4">
                    <div class="min-w-0">
                        <div class="text-sm font-semibold text-on-surface">{{ r.student }}</div>
                        <div class="text-xs text-on-surface-subtle">{{ r.class }} · GV: {{ r.teacher }}<template v-if="r.note"> · {{ r.note }}</template></div>
                    </div>
                    <div class="flex items-center gap-2">
                        <UiBadge :color="badgeColor(r.status)" pill>{{ r.status_label }}</UiBadge>
                        <span class="text-xs text-on-surface-variant">{{ r.review_label }}</span>
                        <UiForm v-if="r.review_status === 'pending_review'" :action="route('kpi.attendance-review.update', r.id)" method="post" class="flex gap-1">
                            <UiButton type="submit" name="decision" value="approved" variant="success" size="sm">Duyệt</UiButton>
                            <UiButton type="submit" name="decision" value="rejected" variant="danger" size="sm">Trả lại</UiButton>
                        </UiForm>
                    </div>
                </div>
                <UiEmptyState v-if="!records.length" icon="fact_check" title="Không có dữ liệu điểm danh cho ngày/lớp đã chọn." />
            </div>
        </div>
    </div>
</template>
