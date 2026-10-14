<script setup>
/**
 * Báo cáo ngày Học vụ (mockup 02/06 "Báo cáo ngày Học vụ"), Học thuật dùng chung biểu mẫu (tiêu đề theo `audience`): Vận hành lớp học, Tuyển sinh / Học viên, Truyền thông
 * (khối và ô theo StaffReportController::DAILY_SECTIONS) + Task follow-up (bật "Tạo Task" → tạo đầu việc trong Giao việc).
 * Mỗi ngày 1 báo cáo, lưu lại là cập nhật; chọn ngày khác ở góc phải để xem / bổ sung báo cáo ngày đó.
 */
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { route } from '@/lib/route';
import ReportTabs from './ReportTabs.vue';
import ReportHistory from './ReportHistory.vue';
import LegacyReports from './LegacyReports.vue';

defineOptions({ layout: { title: 'Báo cáo ngày' } });

const props = defineProps({
    audience: { type: String, default: 'Học vụ' },
    tabs: { type: Array, default: () => [] },
    date: { type: String, required: true },
    dateLabel: { type: String, default: '' },
    today: { type: String, required: true },
    sections: { type: Object, default: () => ({}) },
    fields: { type: Object, default: () => ({}) },
    values: { type: Object, default: () => ({}) },
    task: { type: Object, default: null },
    assignees: { type: Array, default: () => [] },
    submittedAt: { type: String, default: null },
    history: { type: Array, default: () => [] },
    legacy: { type: Array, default: () => [] },
});

const taskOn = ref(false);
const picker = ref(null);
const openPicker = () => (picker.value?.showPicker ? picker.value.showPicker() : picker.value?.click());
const pickDate = (event) => event.target.value && event.target.value !== props.date && router.get(route('reports.periodic.daily'), { date: event.target.value });
const field = (key) => props.fields[key] ?? { label: key, type: 'text' };
// Bố cục hàng như mockup: 2 ô số chia đôi; ô số + ghi chú chia 4/8; ô ghi chú đứng riêng thì cả hàng.
const paired = (row) => row.length > 1 && row.some((name) => field(name).type === 'text');
const rowClass = (row) => (row.length < 2 ? '' : paired(row) ? 'md:grid-cols-12' : 'md:grid-cols-2');
const cellClass = (row, name) => (paired(row) ? (field(name).type === 'text' ? 'md:col-span-8' : 'md:col-span-4') : '');
const textRows = (row, name) => field(name).rows ?? (row.length > 1 ? 2 : 3);
</script>

<template>
    <UiPageHeader :title="`Báo cáo ngày ${audience}`" icon="assignment">
        <template #meta>Hoàn thành biểu mẫu để cập nhật thông tin vận hành trong ngày.</template>
        <template #actions>
            <div class="relative text-right">
                <p class="font-body-small text-body-small text-on-surface-variant">Ngày báo cáo</p>
                <button type="button" class="inline-flex items-center gap-xs font-body-medium text-body-medium text-on-surface hover:text-primary" title="Chọn ngày khác" @click="openPicker">
                    <span data-date-label>{{ dateLabel }}</span>
                    <span class="material-symbols-outlined text-[18px] text-on-surface-variant" aria-hidden="true">calendar_month</span>
                </button>
                <input ref="picker" type="date" class="pointer-events-none absolute bottom-0 right-0 h-px w-px opacity-0" tabindex="-1" :value="date" :max="today" aria-label="Chọn ngày báo cáo" @change="pickDate" />
            </div>
        </template>
    </UiPageHeader>

    <ReportTabs :tabs="tabs" />

    <div class="space-y-xl">
        <UiForm id="daily-ops-form" :action="route('reports.periodic.daily.store')" method="post" class="space-y-xl" #default="{ errors }">
            <input type="hidden" name="date" :value="date" />
            <UiAlert v-if="submittedAt" type="info">Đã nộp báo cáo ngày này lúc {{ formatDate(submittedAt, 'H:i d/m/Y') }}. Lưu lại để cập nhật.</UiAlert>
            <UiAlert v-if="errors.fields" type="error">{{ errors.fields }}</UiAlert>

            <section v-for="(section, key) in sections" :key="key" class="space-y-lg rounded-xl border border-surface-variant bg-surface-container-lowest p-lg shadow-sm" :data-daily-section="key">
                <h2 class="flex items-center gap-xs font-h3 text-h3 text-on-surface">
                    <span class="material-symbols-outlined text-primary-container" style="font-variation-settings: 'FILL' 1" aria-hidden="true">{{ section.icon }}</span>{{ section.title }}
                </h2>
                <div v-for="(row, i) in section.rows" :key="i" :class="['grid grid-cols-1 gap-lg', rowClass(row)]">
                    <div v-for="name in row" :key="name" :class="cellClass(row, name)">
                        <UiTextarea v-if="field(name).type === 'text'" :name="`fields[${name}]`" :label="field(name).label" :rows="textRows(row, name)" :placeholder="field(name).placeholder" :value="values[name]" />
                        <UiInput
                            v-else
                            :name="`fields[${name}]`"
                            :label="field(name).label"
                            :type="field(name).type === 'number' ? 'number' : 'text'"
                            :min="field(name).type === 'number' ? 0 : undefined"
                            :placeholder="field(name).placeholder ?? (field(name).type === 'number' ? '0' : undefined)"
                            :value="values[name]"
                        />
                    </div>
                </div>
            </section>

            <section class="space-y-lg rounded-xl border border-primary-container/30 bg-surface-container-low p-lg shadow-sm" data-daily-task>
                <div class="flex items-center justify-between gap-md">
                    <h2 class="flex items-center gap-xs font-h3 text-h3 text-on-surface">
                        <span class="material-symbols-outlined text-primary-container" aria-hidden="true">assignment_turned_in</span>Task follow-up
                    </h2>
                    <label v-if="!task" class="flex cursor-pointer items-center gap-sm font-body-medium text-body-medium text-on-surface">
                        <input type="hidden" name="task[enabled]" :value="taskOn ? 1 : 0" />
                        <button
                            type="button"
                            role="switch"
                            :aria-checked="taskOn"
                            :class="['relative h-6 w-11 rounded-full transition-colors', taskOn ? 'bg-primary-container' : 'bg-outline-variant']"
                            @click="taskOn = !taskOn"
                        >
                            <span :class="['absolute top-0.5 h-5 w-5 rounded-full bg-white shadow transition-all', taskOn ? 'left-[22px]' : 'left-0.5']" />
                        </button>
                        Tạo Task
                    </label>
                </div>
                <p v-if="task" class="font-body-small text-body-small text-on-surface" data-daily-task-created>
                    Đã tạo việc <strong>{{ task.title }}</strong> · Người phụ trách: {{ task.assignee ?? '—' }} · Ngày giải quyết {{ formatDate(task.due_date, 'd/m/Y') }}.
                    <UiButton variant="ghost" size="sm" :href="route('tasks.index')">Mở danh sách việc</UiButton>
                </p>
                <div v-else-if="taskOn" class="grid grid-cols-1 gap-lg md:grid-cols-2">
                    <UiDate name="task[due_date]" label="Ngày giải quyết" required icon="calendar_today" :value="today" />
                    <UiSelect name="task[assignee_id]" label="Người phụ trách (PIC)" :options="assignees" :value="assignees[0]?.value" :searchable="assignees.length > 8" />
                </div>
            </section>

            <div class="flex justify-end pt-md">
                <UiButton type="submit" icon="save" class="px-xl py-md">Lưu báo cáo</UiButton>
            </div>
        </UiForm>

        <div class="grid grid-cols-1 gap-lg lg:grid-cols-2">
            <ReportHistory :items="history" :current="date" title="Các ngày đã nộp" />
            <LegacyReports :items="legacy" />
        </div>
    </div>
</template>
