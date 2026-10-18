<script setup>
/**
 * Nộp báo cáo trực lớp, luật A6 Q8: ảnh không bắt buộc; có ≥ 1 ảnh → đầu việc "Trực lớp" tự hoàn thành;
 * không ảnh → chờ Admin xác nhận (chỉ Admin xác nhận từ 06/10/2026).
 * Mở từ Cổng TA / menu → modal 2xl; mở thẳng URL → trang riêng (gọn cho điện thoại).
 */
import { useBackLink } from '@/lib/backLink';
import { route } from '@/lib/route';
import ClassReportForm from './Partials/ClassReportForm.vue';

defineOptions({ layout: { title: 'Nộp báo cáo trực lớp' } });

const props = defineProps({
    classes: { type: Array, default: () => [] },
    selectedClassId: { type: Number, default: null },
    task: { type: Object, default: null },
    students: { type: Array, default: () => [] },
    sessionOptions: { type: Array, default: () => [] },
    defaultSessionId: { type: Number, default: null },
    confirmMode: { type: String, default: 'none' },
    confirmer: { type: String, default: null },
    asModal: { type: Boolean, default: false },
    classInfo: { type: Object, default: null },
    sessionDetails: { type: Object, default: () => ({}) },
    reporter: { type: String, default: null },
    testSuggestions: { type: Array, default: () => [] },
    followUps: { type: Array, default: () => [] },
    assignees: { type: Array, default: () => [] },
    today: { type: String, default: null },
});
const back = useBackLink(() => route('portal.ta-tasks'));
</script>

<template>
    <UiModalFrame v-if="asModal" title="Báo cáo trực lớp" description="Tiến độ, nhật ký giảng dạy & học viên cần bổ trợ." size="2xl">
        <ClassReportForm v-bind="props" form-id="modal-class-report-form" />
        <template v-if="classes.length" #footer>
            <UiButton type="submit" form="modal-class-report-form" icon="send">Nộp báo cáo</UiButton>
        </template>
    </UiModalFrame>

    <div v-else class="mx-auto max-w-4xl space-y-md pb-24 md:pb-0">
        <header class="flex items-center gap-sm rounded-2xl border border-outline-variant bg-surface-container-lowest p-md shadow-sm">
            <UiButton variant="ghost" icon="arrow_back" :href="back.href" data-back-link aria-label="Quay lại" title="Quay lại" />
            <div class="min-w-0">
                <h1 class="flex flex-wrap items-center gap-sm font-h2 text-h2 text-on-surface">
                    Báo cáo trực lớp
                    <span class="rounded-full bg-primary-fixed px-sm py-[2px] font-caption text-caption font-semibold text-primary">Trợ giảng</span>
                </h1>
                <p class="font-body-small text-body-small text-on-surface-variant">Tiến độ, nhật ký giảng dạy & học viên cần bổ trợ.</p>
            </div>
        </header>
        <ClassReportForm v-bind="props" form-id="class-report-form" :cancel-href="back.href" />
    </div>
</template>
