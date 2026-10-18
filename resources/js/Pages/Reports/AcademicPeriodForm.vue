<script setup>
/**
 * Form chung của báo cáo tháng / quý Học thuật (chủ dự án 17/10/2026): thay các ô tường thuật bằng khối số liệu tự động
 * (StaffReportController::academicPeriodProps → AcademicPeriodMetrics). Mỗi khối: số liệu chỉ đọc + ghi chú + task follow-up
 * (việc chưa xong kỳ trước hiện lại đúng khối đó); cuối form là "Đánh giá chung". Ghi chú / đánh giá đang gõ giữ trên máy
 * (useNoteDraft) khi bấm "Cập nhật số liệu" hoặc đổi kỳ. Kỳ đã nộp hiện số lúc nộp, "Cập nhật số liệu" tính lại.
 */
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import DailyMetric from './DailyMetric.vue';
import SectionTasks from './SectionTasks.vue';
import { useNoteDraft } from './useNoteDraft';

const props = defineProps({
    action: { type: String, required: true },
    periodField: { type: String, required: true },
    period: { type: String, required: true },
    refreshUrl: { type: String, required: true },
    overallTitle: { type: String, default: 'Đánh giá chung' },
    fields: { type: Object, default: () => ({}) },
    values: { type: Object, default: () => ({}) },
    placeholders: { type: Object, default: () => ({}) },
    sections: { type: Object, default: () => ({}) },
    computedAt: { type: String, default: null },
    live: { type: Boolean, default: true },
    noteLabels: { type: Object, default: () => ({}) },
    notes: { type: Object, default: () => ({}) },
    draftKey: { type: String, required: true },
    sectionTasks: { type: Object, default: () => ({}) },
    assignees: { type: Array, default: () => [] },
    today: { type: String, required: true },
    legacyFields: { type: Array, default: () => [] },
    submittedAt: { type: String, default: null },
    submitLabel: { type: String, default: 'Lưu báo cáo' },
});

// Ghi chú khối và mục đánh giá chung chung một bản nháp (khóa "n:" / "f:" để không trùng nhau).
const { notes: draft, restored, discard, clear } = useNoteDraft(props.draftKey, {
    ...Object.fromEntries(Object.keys(props.sections).map((key) => [`n:${key}`, props.notes[key] ?? ''])),
    ...Object.fromEntries(Object.keys(props.fields).map((key) => [`f:${key}`, props.values[key] ?? ''])),
});
const hasValue = (item) => item.value !== null && item.value !== undefined;
const refresh = () => router.get(props.refreshUrl, { [props.periodField]: props.period, refresh: 1 }, { preserveScroll: true, preserveState: true });

let uid = 0;
const newTasks = ref([]);
const addTask = (section) => newTasks.value.push({ uid: ++uid, section });
const removeTask = (t) => (newTasks.value = newTasks.value.filter((x) => x.uid !== t.uid));
const rowsIn = (section) => newTasks.value.filter((t) => t.section === section);
const indexOf = (t) => newTasks.value.indexOf(t);
const onSaved = () => {
    clear();
    newTasks.value = [];
};
</script>

<template>
    <UiForm :action="action" method="post" class="space-y-lg" #default="{ errors }" data-academic-period-form @success="onSaved">
        <input type="hidden" :name="periodField" :value="period" />
        <input type="hidden" name="refresh" :value="live && submittedAt ? 1 : 0" />
        <UiAlert v-if="submittedAt" type="info" data-period-submitted>
            Đã nộp lúc {{ formatDate(submittedAt, 'H:i d/m/Y') }}.
            <template v-if="!live">Số liệu bên dưới là số lúc nộp, sửa ghi chú không làm đổi số.</template>
            <template v-else>Số liệu bên dưới vừa được tính lại, bấm Lưu để cập nhật báo cáo bằng số mới.</template>
        </UiAlert>
        <UiAlert v-if="restored" type="info">
            Đã khôi phục ghi chú bạn gõ trên máy này nhưng chưa lưu.
            <button type="button" class="ml-xs font-semibold underline" @click="discard">Bỏ bản nháp</button>
        </UiAlert>
        <UiAlert v-if="errors.narrative" type="error">{{ errors.narrative }}</UiAlert>

        <div class="flex flex-wrap items-center justify-between gap-sm">
            <div class="inline-flex items-center gap-xs font-body-small text-body-small text-on-surface-variant" data-computed-at>
                <span class="material-symbols-outlined text-[16px]" aria-hidden="true">sync</span>
                Số liệu tính lúc {{ computedAt ? formatDate(computedAt, 'H:i d/m/Y') : '—' }}
                <UiInfo title="Số liệu báo cáo">
                    <p>Mọi số được hệ thống tính lại từ dữ liệu gốc cho cả kỳ, theo các lớp bạn được xem trong chi nhánh của mình. Bấm icon "i" ở từng mục để xem cách đếm.</p>
                    <p>Số sai thì sửa ở màn gốc rồi bấm <strong class="text-on-surface">Cập nhật số liệu</strong>. Khi bấm Lưu, số lúc đó được lưu vào báo cáo để Admin xem đúng số bạn đã thấy.</p>
                </UiInfo>
            </div>
            <UiButton type="button" variant="ghost" size="sm" icon="refresh" data-refresh-metrics @click="refresh">Cập nhật số liệu</UiButton>
        </div>

        <section v-if="legacyFields.length" class="space-y-sm rounded-xl border border-dashed border-outline-variant bg-surface-container-lowest p-lg" data-period-legacy>
            <h2 class="font-h3 text-h3 text-on-surface">Tường thuật đã nhập (bản cũ)</h2>
            <p class="font-body-small text-body-small text-on-surface-variant">Báo cáo này có các mục tường thuật nhập trước khi chuyển sang số liệu tự động. Lưu lại vẫn giữ nguyên các mục này.</p>
            <dl class="space-y-sm">
                <div v-for="f in legacyFields" :key="f.label">
                    <dt class="font-label text-label uppercase text-on-surface-variant">{{ f.label }}</dt>
                    <dd class="whitespace-pre-line font-body-medium text-body-medium text-on-surface">{{ f.value }}</dd>
                </div>
            </dl>
        </section>

        <section v-for="(section, key) in sections" :key="key" class="space-y-lg rounded-xl border border-surface-variant bg-surface-container-lowest p-lg shadow-sm" :data-period-section="key">
            <h2 class="flex items-center gap-xs font-h3 text-h3 text-on-surface">
                <span class="material-symbols-outlined text-primary-container" style="font-variation-settings: 'FILL' 1" aria-hidden="true">{{ section.icon }}</span>{{ section.title }}
                <UiBadge v-if="sectionTasks[key]?.some((t) => t.carried)" color="warning" class="ml-xs">{{ sectionTasks[key].filter((t) => t.carried).length }} việc tồn</UiBadge>
            </h2>
            <p v-if="!section.items.length && section.empty" class="font-body-small text-body-small text-on-surface-variant">{{ section.empty }}</p>
            <div v-if="section.items.length" class="grid grid-cols-1 gap-md sm:grid-cols-2">
                <DailyMetric v-for="item in section.items" :key="item.key" :item="item" :class="hasValue(item) ? '' : 'sm:col-span-2'" />
            </div>
            <UiTextarea v-model="draft[`n:${key}`]" :name="`notes[${key}]`" :label="noteLabels[key] ?? 'Ghi chú'" :rows="2" placeholder="Ghi chú thêm (nếu có)..." />
            <SectionTasks
                :section="key"
                :title="`Task follow-up · ${section.title}`"
                :tasks="sectionTasks[key] ?? []"
                :rows="rowsIn(key)"
                :index-of="indexOf"
                :assignees="assignees"
                :today="today"
                @add="addTask(key)"
                @remove="removeTask"
            />
        </section>

        <section class="space-y-md rounded-xl border border-primary-container/30 bg-surface-container-lowest p-lg shadow-sm" data-period-overall>
            <h2 class="flex items-center gap-xs font-h3 text-h3 text-on-surface">
                <span class="material-symbols-outlined text-primary-container" aria-hidden="true">edit_note</span>{{ overallTitle }}
            </h2>
            <div :class="Object.keys(fields).length > 1 ? 'grid grid-cols-1 gap-md md:grid-cols-2' : ''">
                <UiTextarea
                    v-for="(label, key) in fields"
                    :key="key"
                    v-model="draft[`f:${key}`]"
                    :name="`narrative[${key}]`"
                    :label="label"
                    :rows="Object.keys(fields).length > 1 ? 3 : 4"
                    :placeholder="placeholders[key] ?? ''"
                />
            </div>
        </section>

        <div class="flex justify-end">
            <UiButton type="submit" icon="save">{{ submitLabel }}</UiButton>
        </div>
    </UiForm>
</template>
