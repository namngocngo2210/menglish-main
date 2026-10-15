<script setup>
/**
 * Báo cáo ngày Học vụ / Học thuật (mockup 02/06 + chủ dự án 15/10/2026): số liệu từng khối lấy từ hệ thống
 * (StaffReportController::daily → DailyReportMetrics), người viết chỉ nhập ghi chú mỗi khối + bật "Tạo Task" nếu cần follow-up.
 * Ngày chưa nộp: số liệu tính trực tiếp. Đã nộp: hiện số đã chụp lúc nộp; "Cập nhật số liệu" tính lại, Lưu để ghi đè.
 */
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { route } from '@/lib/route';
import ReportTabs from './ReportTabs.vue';
import ReportHistory from './ReportHistory.vue';
import LegacyReports from './LegacyReports.vue';
import DailyMetric from './DailyMetric.vue';

defineOptions({ layout: { title: 'Báo cáo ngày' } });

const props = defineProps({
    audience: { type: String, default: 'Học vụ' },
    tabs: { type: Array, default: () => [] },
    date: { type: String, required: true },
    dateLabel: { type: String, default: '' },
    today: { type: String, required: true },
    sections: { type: Object, default: () => ({}) },
    computedAt: { type: String, default: null },
    live: { type: Boolean, default: true },
    noteLabels: { type: Object, default: () => ({}) },
    notes: { type: Object, default: () => ({}) },
    legacyFields: { type: Array, default: () => [] },
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
const refresh = () => router.get(route('reports.periodic.daily'), { date: props.date, refresh: 1 }, { preserveScroll: true });
// Mục có số → thẻ nhỏ (3 cột); mục chỉ là danh sách (không có số) → cả hàng.
const hasValue = (item) => item.value !== null && item.value !== undefined;
</script>

<template>
    <UiPageHeader :title="`Báo cáo ngày ${audience}`" icon="assignment">
        <template #meta>
            <span class="inline-flex items-center gap-xs">
                Số liệu lấy tự động từ hệ thống, bạn chỉ cần ghi chú.
                <UiInfo title="Số liệu báo cáo ngày">
                    <p>Mọi số trong báo cáo được hệ thống tính theo <strong class="text-on-surface">ngày báo cáo</strong> và phạm vi lớp / chi nhánh bạn được xem. Bấm icon "i" ở từng mục để xem cách đếm.</p>
                    <p>Số sai thì sửa ở màn gốc (điểm danh, CRM, phiếu thu, buổi bổ trợ…) rồi bấm <strong class="text-on-surface">Cập nhật số liệu</strong>.</p>
                    <p>Khi bấm Lưu, số liệu lúc đó được lưu vào báo cáo để Admin xem đúng số bạn đã thấy.</p>
                </UiInfo>
            </span>
        </template>
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
            <UiAlert v-if="submittedAt" type="info">
                Đã nộp báo cáo ngày này lúc {{ formatDate(submittedAt, 'H:i d/m/Y') }}.
                <template v-if="!live">Số liệu bên dưới là số lúc nộp.</template>
                <template v-else>Số liệu bên dưới vừa được tính lại, bấm Lưu để cập nhật báo cáo.</template>
            </UiAlert>
            <UiAlert v-if="errors.notes" type="error">{{ errors.notes }}</UiAlert>

            <div class="flex flex-wrap items-center justify-between gap-sm">
                <p class="font-body-small text-body-small text-on-surface-variant" data-computed-at>
                    <span class="material-symbols-outlined align-middle text-[16px]" aria-hidden="true">sync</span>
                    Số liệu tính lúc {{ computedAt ? formatDate(computedAt, 'H:i d/m/Y') : '—' }}
                </p>
                <UiButton type="button" variant="ghost" size="sm" icon="refresh" data-refresh-metrics @click="refresh">Cập nhật số liệu</UiButton>
            </div>

            <section
                v-if="legacyFields.length"
                class="space-y-sm rounded-xl border border-surface-variant bg-surface-container-lowest p-lg shadow-sm"
                data-daily-legacy
            >
                <h2 class="font-h3 text-h3 text-on-surface">Số liệu đã nhập tay (bản cũ)</h2>
                <p class="font-body-small text-body-small text-on-surface-variant">Báo cáo này nộp trước khi chuyển sang số liệu tự động. Lưu lại sẽ thay bằng số liệu hệ thống.</p>
                <dl class="grid grid-cols-1 gap-sm md:grid-cols-2">
                    <div v-for="f in legacyFields" :key="f.label">
                        <dt class="font-label text-label uppercase text-on-surface-variant">{{ f.label }}</dt>
                        <dd class="whitespace-pre-line font-body-medium text-body-medium text-on-surface">{{ f.value }}</dd>
                    </div>
                </dl>
            </section>

            <section v-for="(section, key) in sections" :key="key" class="space-y-lg rounded-xl border border-surface-variant bg-surface-container-lowest p-lg shadow-sm" :data-daily-section="key">
                <h2 class="flex items-center gap-xs font-h3 text-h3 text-on-surface">
                    <span class="material-symbols-outlined text-primary-container" style="font-variation-settings: 'FILL' 1" aria-hidden="true">{{ section.icon }}</span>{{ section.title }}
                </h2>
                <p v-if="!section.items.length && section.empty" class="font-body-small text-body-small text-on-surface-variant">{{ section.empty }}</p>
                <div v-if="section.items.length" class="grid grid-cols-1 gap-md md:grid-cols-2 xl:grid-cols-3">
                    <DailyMetric
                        v-for="item in section.items"
                        :key="item.key"
                        :item="item"
                        :class="hasValue(item) ? '' : 'md:col-span-2 xl:col-span-3'"
                    />
                </div>
                <UiTextarea
                    :name="`notes[${key}]`"
                    :label="noteLabels[key] ?? 'Ghi chú'"
                    :rows="3"
                    :placeholder="key === 'media' ? 'Nhập nội dung kèm link bài viết...' : 'Ghi chú thêm (nếu có)...'"
                    :value="notes[key]"
                />
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
