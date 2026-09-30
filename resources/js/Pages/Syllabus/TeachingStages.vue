<script setup>
/**
 * Mockup 03_Cong_Giao_Vien/07 (Chặng đang dạy & Order Test) + 10/11 (Lịch dự kiến Big Test): mỗi lớp đang mở chặng là một thẻ.
 * GV chính đặt ngày dự kiến Big Test cuối chặng và order đề cho đúng chặng đang mở; trợ giảng chỉ xem.
 */
defineOptions({ layout: { title: 'Chặng đang dạy & Order Test' } });

defineProps({
    assignments: { type: Array, default: () => [] },
    today: { type: String, required: true },
    month: { type: String, default: '' },
});
</script>

<template>
    <UiPageHeader title="Chặng đang dạy & Order Test" description="Quản lý các chặng học, lịch dự kiến Big Test và yêu cầu đề thi cho học viên.">
        <template #actions>
            <span class="inline-flex items-center gap-xs rounded-lg border border-outline-variant bg-surface-container-lowest px-md py-sm font-body-medium text-body-small text-on-surface">
                <span class="material-symbols-outlined text-[18px] text-primary">calendar_today</span>{{ month }}
            </span>
        </template>
    </UiPageHeader>

    <div v-if="!assignments.length" class="flex flex-col items-center gap-sm rounded-xl border border-outline-variant bg-surface-container-lowest p-xl text-center shadow-sm">
        <span class="material-symbols-outlined text-[48px] text-on-surface-variant">inventory_2</span>
        <p class="font-h3 text-h3 text-on-surface">Chưa được giao chặng nào</p>
        <p class="font-body-small text-body-small text-on-surface-variant">Hiện tại bạn chưa có chặng học nào đang mở. Vui lòng liên hệ Quản lý chuyên môn nếu có sai sót.</p>
    </div>
    <div v-else class="grid grid-cols-1 gap-lg md:grid-cols-2 xl:grid-cols-3">
        <article v-for="as in assignments" :key="as.id" class="flex flex-col gap-md rounded-xl border border-outline-variant bg-surface-container-lowest p-lg shadow-sm">
            <div class="flex items-start justify-between gap-sm">
                <div class="min-w-0">
                    <span class="inline-flex rounded-md bg-primary-fixed/60 px-sm py-0.5 font-label text-label text-primary">{{ as.stage_label }}</span>
                    <h3 class="mt-xs font-h3 text-h3 text-on-surface">{{ as.class_label }}</h3>
                </div>
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary-fixed text-primary"><span class="material-symbols-outlined text-[20px]">school</span></span>
            </div>
            <div class="space-y-xs font-body-small text-body-small">
                <p class="flex items-center gap-xs text-on-surface-variant"><span class="material-symbols-outlined text-[18px]">calendar_today</span>Bắt đầu: {{ as.start_date }}</p>
                <p class="flex items-center gap-xs text-on-surface-variant"><span class="material-symbols-outlined text-[18px]">{{ as.is_assistant ? 'supervisor_account' : 'person' }}</span>{{ as.role_label }}</p>
            </div>

            <!-- Trạng thái đề -->
            <div v-if="as.exam === 'approved'" class="flex items-center gap-xs rounded-lg bg-tertiary/10 px-md py-sm font-body-small text-body-small font-medium text-tertiary"><span class="material-symbols-outlined text-[18px]">check_circle</span>Đã có đề</div>
            <div v-else-if="as.exam === 'pending'" class="flex items-center gap-xs rounded-lg bg-warning/10 px-md py-sm font-body-small text-body-small font-medium text-on-warning-container"><span class="material-symbols-outlined text-[18px]">pending</span>Đã order - Chờ HT duyệt</div>

            <!-- Lịch dự kiến Big Test -->
            <div class="space-y-sm rounded-lg border border-outline-variant bg-surface-container-low p-md">
                <p class="font-body-small text-body-small text-on-surface">
                    Ngày dự kiến Big Test:
                    <strong :class="as.date ? 'text-on-surface' : 'text-error'">{{ as.date ?? 'Chưa đặt lịch' }}</strong>
                    <span v-if="as.big_test_code" class="font-caption text-caption text-on-surface-variant"> (đợt thi {{ as.big_test_code }})</span>
                </p>
                <UiForm v-if="as.can_set_date" :action="route('syllabus.assignments.expected-date', as.id)" method="post" class="flex items-end gap-sm">
                    <div class="flex-1">
                        <UiDate :id="`expected_big_test_date_${as.id}`" :label="as.expected_date ? 'Sửa ngày' : 'Chọn ngày'" name="expected_big_test_date" required :min="today" :value="as.expected_date" />
                    </div>
                    <UiButton type="submit" size="sm" :icon="as.expected_date ? null : 'save'">{{ as.expected_date ? 'Cập nhật' : 'Lưu' }}</UiButton>
                </UiForm>
            </div>

            <div class="mt-auto">
                <UiButton v-if="['pending', 'approved'].includes(as.order_status)" variant="secondary" class="w-full" :href="route('teacher.order-test', as.class_id)">Chi tiết</UiButton>
                <template v-else-if="as.can_order">
                    <p v-if="as.order_status === 'rejected'" class="mb-sm font-caption text-caption text-error">Order trước bị từ chối: {{ as.order_rejection }}</p>
                    <UiButton icon="assignment_add" class="w-full" :href="route('teacher.order-test', as.class_id)">Order đề Big Test</UiButton>
                </template>
            </div>
        </article>
    </div>
</template>
