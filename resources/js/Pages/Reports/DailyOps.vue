<script setup>
/**
 * Báo cáo ngày Học vụ (mockup 02/06 "Báo cáo ngày Học vụ"): Vận hành lớp học, Tuyển sinh / Học viên, Truyền thông
 * (khối và ô theo StaffReportController::DAILY_SECTIONS) + Task follow-up (bật "Tạo Task" → tạo đầu việc trong Giao việc).
 * Mỗi ngày 1 báo cáo, lưu lại là cập nhật; chọn ngày khác ở góc phải để xem / bổ sung báo cáo ngày đó.
 */
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import ReportTabs from './ReportTabs.vue';
import ReportHistory from './ReportHistory.vue';
import LegacyReports from './LegacyReports.vue';

defineOptions({ layout: { title: 'Báo cáo ngày Học vụ' } });

const props = defineProps({
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
const pickDate = (event) => event.target.value && router.get(route('reports.periodic.daily'), { date: event.target.value });
const field = (key) => props.fields[key] ?? { label: key, type: 'text' };
</script>

<template>
    <UiPageHeader title="Báo cáo ngày Học vụ" icon="assignment">
        <template #meta>Hoàn thành biểu mẫu để cập nhật thông tin vận hành trong ngày.</template>
        <template #actions>
            <label class="flex flex-col items-end text-right">
                <span class="font-body-small text-body-small text-on-surface-variant">Ngày báo cáo</span>
                <span class="font-semibold text-on-surface" data-date-label>{{ dateLabel }}</span>
                <input type="date" class="mt-xs rounded-lg border border-outline-variant bg-surface-container-lowest px-sm py-xs font-body-small text-body-small" :value="date" :max="today" aria-label="Chọn ngày báo cáo" @change="pickDate" />
            </label>
        </template>
    </UiPageHeader>

    <ReportTabs :tabs="tabs" />

    <div class="grid grid-cols-1 gap-lg lg:grid-cols-[1fr_280px]">
        <UiForm id="daily-ops-form" :action="route('reports.periodic.daily.store')" method="post" class="space-y-lg" #default="{ errors }">
            <input type="hidden" name="date" :value="date" />
            <UiAlert v-if="submittedAt" type="info">Đã nộp báo cáo ngày này lúc {{ formatDate(submittedAt, 'H:i d/m/Y') }}. Lưu lại để cập nhật.</UiAlert>
            <UiAlert v-if="errors.fields" type="error">{{ errors.fields }}</UiAlert>

            <section v-for="(section, key) in sections" :key="key" class="space-y-md rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-lg shadow-sm" :data-daily-section="key">
                <h2 class="flex items-center gap-xs font-semibold text-on-surface">
                    <span class="material-symbols-outlined text-[20px] text-primary-container" aria-hidden="true">{{ section.icon }}</span>{{ section.title }}
                </h2>
                <div v-for="(row, i) in section.rows" :key="i" :class="['grid grid-cols-1 gap-md', row.length > 1 ? 'md:grid-cols-2' : '']">
                    <template v-for="name in row" :key="name">
                        <UiTextarea v-if="field(name).type === 'text'" :name="`fields[${name}]`" :label="field(name).label" :rows="3" :placeholder="field(name).placeholder" :value="values[name]" />
                        <UiInput
                            v-else
                            :name="`fields[${name}]`"
                            :label="field(name).label"
                            :type="field(name).type === 'number' ? 'number' : 'text'"
                            :min="field(name).type === 'number' ? 0 : undefined"
                            :placeholder="field(name).placeholder ?? (field(name).type === 'number' ? '0' : undefined)"
                            :value="values[name]"
                        />
                    </template>
                </div>
            </section>

            <section class="space-y-md rounded-2xl border border-primary-container/30 bg-surface-container-low p-lg shadow-sm" data-daily-task>
                <div class="flex items-center justify-between gap-md">
                    <h2 class="flex items-center gap-xs font-semibold text-on-surface">
                        <span class="material-symbols-outlined text-[20px] text-primary-container" aria-hidden="true">assignment_turned_in</span>Task follow-up
                    </h2>
                    <label v-if="!task" class="flex cursor-pointer items-center gap-sm font-body-small text-body-small text-on-surface">
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
                    Đã tạo việc <strong>{{ task.title }}</strong> · Người phụ trách: {{ task.assignee ?? '—' }} · Hạn {{ formatDate(task.due_date, 'd/m/Y') }}.
                    <UiButton variant="ghost" size="sm" :href="route('tasks.index')">Mở danh sách việc</UiButton>
                </p>
                <div v-else-if="taskOn" class="grid grid-cols-1 gap-md md:grid-cols-2">
                    <div class="md:col-span-2">
                        <UiInput name="task[title]" label="Tiêu đề công việc follow-up" required placeholder="Ví dụ: Gọi điện phụ huynh em Minh giải thích việc vắng học..." />
                    </div>
                    <div class="md:col-span-2">
                        <UiTextarea name="task[description]" label="Nội dung chi tiết & hành động tiếp theo" required :rows="3" placeholder="Mô tả cụ thể nhiệm vụ, kết quả mong đợi hoặc tài liệu cần chuẩn bị..." />
                    </div>
                    <UiDate name="task[due_date]" label="Ngày hạn chót giải quyết" required :value="today" />
                    <UiSelect name="task[assignee_id]" label="Người phụ trách (PIC)" required :options="assignees" :value="assignees[0]?.value" />
                </div>
                <p v-else class="font-body-small text-body-small text-on-surface-variant">Bật "Tạo Task" để giao việc cần xử lý tiếp từ báo cáo hôm nay.</p>
            </section>

            <div class="flex justify-end">
                <UiButton type="submit" icon="save">Lưu báo cáo</UiButton>
            </div>
        </UiForm>

        <div class="space-y-lg">
            <ReportHistory :items="history" :current="date" title="Các ngày đã nộp" />
            <LegacyReports :items="legacy" />
        </div>
    </div>
</template>
