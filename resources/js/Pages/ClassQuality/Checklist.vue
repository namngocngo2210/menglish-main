<script setup>
/**
 * Checklist Học phí & Feedback theo lớp (mockup 02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu/09): theo tháng, mỗi lớp có học trong tháng
 * 1 dòng — 3 mục học phí + ghi chú, 3 mục Big Test & feedback, mỗi mục Có / Không / N-A. Lưu cả trang một lần.
 * Mục "đến hạn" = Có mà việc cần làm = Không → dòng được đánh dấu thiếu sót (căn cứ chấm KPI Học vụ).
 */
import { reactive, watch } from 'vue';

defineOptions({ layout: { title: 'Checklist học phí & feedback' } });

const props = defineProps({
    month: { type: String, required: true },
    months: { type: Array, default: () => [] },
    classes: { type: Object, required: true },
    items: { type: Object, default: () => ({}) },
    followUps: { type: Object, default: () => ({}) },
    answers: { type: Object, default: () => ({}) },
    canEdit: { type: Boolean, default: false },
});

const TUITION = ['tuition_due', 'tuition_reminded', 'tuition_collected'];
const BIG_TEST = ['big_test_due', 'feedback_on_time', 'negative_feedback_handled'];

// Trạng thái đang sửa của từng dòng (khởi tạo lại khi đổi tháng / trang).
const rows = reactive({});
watch(
    () => props.classes.data,
    (list) => {
        for (const key of Object.keys(rows)) delete rows[key];
        for (const c of list) rows[c.id] = { ...c.values };
    },
    { immediate: true },
);

function gaps(classId) {
    const row = rows[classId] ?? {};
    return Object.entries(props.followUps).flatMap(([due, items]) => (row[due] === 'yes' ? items.filter((item) => row[item] === 'no') : []));
}
const answerTone = { yes: 'peer-checked:bg-tertiary peer-checked:text-on-tertiary', no: 'peer-checked:bg-error peer-checked:text-on-error', na: 'peer-checked:bg-on-surface-variant peer-checked:text-inverse-on-surface' };
</script>

<template>
    <UiPageHeader title="Checklist Học phí & Feedback theo lớp" icon="checklist" description="Theo dõi quy trình nhắc học phí và feedback Big Test định kỳ theo từng lớp để tính chỉ số KPI." />

    <UiFilterBar placeholder="Tìm mã / tên lớp...">
        <UiSelect name="month" label="Tháng theo dõi" :options="months" :value="month" />
    </UiFilterBar>

    <UiForm id="class-checklist-form" :action="route('class-quality.checklist.save')" method="put">
        <input type="hidden" name="month" :value="month" />
        <UiDataTable min-width="1280px">
            <table>
                <thead>
                    <tr>
                        <th rowspan="2" class="align-bottom">Lớp học</th>
                        <th colspan="4" class="border-b border-surface-container-highest text-center">Học phí</th>
                        <th colspan="3" class="border-b border-surface-container-highest text-center">Big Test & Feedback</th>
                    </tr>
                    <tr>
                        <th v-for="key in TUITION" :key="key" class="text-center">{{ items[key] }}</th>
                        <th>Ghi chú học phí</th>
                        <th v-for="key in BIG_TEST" :key="key" class="text-center">{{ items[key] }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(c, index) in classes.data" :key="c.id" :class="gaps(c.id).length ? 'bg-error/5' : ''">
                        <td class="whitespace-nowrap">
                            <input type="hidden" :name="`rows[${index}][class_id]`" :value="c.id" />
                            <span class="flex items-center gap-xs font-semibold text-on-surface">
                                <span v-if="gaps(c.id).length" class="material-symbols-outlined text-[18px] text-error" :title="`Thiếu: ${gaps(c.id).map((k) => items[k]).join(', ')}`" aria-hidden="true">error</span>
                                {{ c.code }}
                            </span>
                            <span class="block font-body-small text-body-small text-on-surface-variant">{{ c.name }}<template v-if="c.teacher"> · {{ c.teacher }}</template></span>
                        </td>
                        <template v-for="key in [...TUITION, 'tuition_note', ...BIG_TEST]" :key="key">
                            <td v-if="key === 'tuition_note'" class="min-w-[200px]">
                                <UiInput :name="`rows[${index}][tuition_note]`" :value="c.tuition_note" placeholder="Ghi chú..." :disabled="!canEdit" :aria-label="`Ghi chú học phí ${c.code}`" />
                            </td>
                            <td v-else class="text-center">
                                <div class="inline-flex overflow-hidden rounded-lg border border-outline-variant" role="radiogroup" :aria-label="`${items[key]} — ${c.code}`">
                                    <label v-for="(label, value) in answers" :key="value" class="cursor-pointer">
                                        <input v-model="rows[c.id][key]" type="radio" class="peer sr-only" :name="`rows[${index}][${key}]`" :value="value" :disabled="!canEdit" />
                                        <span :class="['block px-sm py-xs font-body-small text-body-small text-on-surface-variant transition-colors peer-focus-visible:ring-2 peer-focus-visible:ring-primary-container', answerTone[value], gaps(c.id).includes(key) && value === 'no' ? 'ring-1 ring-error' : '']">{{ label }}</span>
                                    </label>
                                </div>
                            </td>
                        </template>
                    </tr>
                    <tr v-if="!classes.data.length">
                        <td colspan="8"><UiEmptyState icon="checklist" title="Không có lớp nào học trong tháng này" /></td>
                    </tr>
                </tbody>
            </table>
            <template #footer><UiPagination :paginator="classes" unit="lớp" /></template>
        </UiDataTable>
        <div v-if="canEdit && classes.data.length" class="mt-md flex justify-end">
            <UiButton type="submit" icon="save">Lưu checklist</UiButton>
        </div>
    </UiForm>
</template>
