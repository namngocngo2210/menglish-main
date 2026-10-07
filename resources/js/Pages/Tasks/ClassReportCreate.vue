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
});
const back = useBackLink(() => route('portal.ta-tasks'));
</script>

<template>
    <UiModalFrame v-if="asModal" title="Nộp báo cáo trực lớp" description="Nội dung bài giảng, nhật ký lớp và học sinh cần bổ trợ." size="2xl">
        <ClassReportForm v-bind="props" form-id="modal-class-report-form" />
        <template v-if="classes.length" #footer>
            <UiButton type="submit" form="modal-class-report-form" icon="send">Nộp báo cáo</UiButton>
        </template>
    </UiModalFrame>

    <div v-else class="mx-auto max-w-2xl space-y-md pb-24 md:pb-0">
        <header class="flex items-center gap-sm">
            <UiButton variant="ghost" icon="arrow_back" :href="back.href" data-back-link aria-label="Quay lại" title="Quay lại" />
            <div class="min-w-0">
                <h1 class="font-h2 text-h2 text-on-surface">Nộp báo cáo trực lớp</h1>
                <p class="font-body-small text-body-small text-on-surface-variant">Nội dung bài giảng, nhật ký lớp và học sinh cần bổ trợ.</p>
            </div>
        </header>
        <ClassReportForm v-bind="props" form-id="class-report-form" />
    </div>
</template>
