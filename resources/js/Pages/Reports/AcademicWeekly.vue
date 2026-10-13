<script setup>
/**
 * Báo cáo tuần Học thuật (mockup 02/18 "Báo cáo tuần — Học thuật"): 3 bảng nhập theo dòng — công việc đã làm trong tuần,
 * dự kiến tuần tới, quản lý yêu cầu. Mỗi dòng: nội dung (bắt buộc), chi tiết, deadline, tiến độ, link sản phẩm, báo cáo,
 * đề xuất hỗ trợ, ghi chú. Mỗi tuần 1 báo cáo, lưu lại là cập nhật.
 */
import { reactive } from 'vue';
import { router } from '@inertiajs/vue3';
import ReportTabs from './ReportTabs.vue';
import ReportHistory from './ReportHistory.vue';
import LegacyReports from './LegacyReports.vue';

defineOptions({ layout: { title: 'Báo cáo tuần Học thuật' } });

const props = defineProps({
    tabs: { type: Array, default: () => [] },
    week: { type: String, required: true },
    weeks: { type: Array, default: () => [] },
    sections: { type: Object, default: () => ({}) },
    columns: { type: Object, default: () => ({}) },
    progressOptions: { type: Array, default: () => [] },
    rows: { type: Object, default: () => ({}) },
    submittedAt: { type: String, default: null },
    history: { type: Array, default: () => [] },
    legacy: { type: Array, default: () => [] },
});

let uid = 0;
const blank = () => ({ uid: ++uid, content: null, detail: null, deadline: null, progress: null, link: null, report: null, support: null, note: null });
const lines = reactive(
    Object.fromEntries(
        Object.keys(props.sections).map((key) => {
            const saved = props.rows?.[key] ?? [];
            return [key, saved.length ? saved.map((r) => ({ ...blank(), ...r, progress: r.progress === null || r.progress === undefined ? null : String(r.progress) })) : [blank()]];
        }),
    ),
);
const add = (key) => lines[key].push(blank());
const remove = (key, index) => {
    lines[key].splice(index, 1);
    if (!lines[key].length) lines[key].push(blank());
};
const name = (key, i, col) => `rows[${key}][${i}][${col}]`;
const pickWeek = (value) => value && value !== props.week && router.get(route('reports.periodic.academic-weekly'), { week: value });
</script>

<template>
    <UiPageHeader title="Báo cáo tuần — Học thuật" icon="assignment">
        <template #meta>Công việc đã làm, kế hoạch tuần tới và các yêu cầu đang quản lý</template>
    </UiPageHeader>

    <ReportTabs :tabs="tabs" />

    <div class="mb-lg w-full max-w-xs">
        <UiSelect label="Khoảng ngày báo cáo (Tuần)" :model-value="week" :options="weeks" :searchable="false" @update:model-value="pickWeek" />
    </div>

    <div class="space-y-lg">
        <UiForm id="academic-weekly-form" :action="route('reports.periodic.academic-weekly.store')" method="post" class="min-w-0 space-y-lg" #default="{ errors }">
            <input type="hidden" name="week" :value="week" />
            <UiAlert v-if="submittedAt" type="info">Đã nộp tuần này lúc {{ formatDate(submittedAt, 'H:i d/m/Y') }}. Lưu lại để cập nhật.</UiAlert>
            <UiAlert v-if="errors.rows" type="error">{{ errors.rows }}</UiAlert>

            <section v-for="(title, key) in sections" :key="key" class="space-y-md rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-lg shadow-sm" :data-weekly-section="key">
                <h2 class="font-h3 text-h3 text-primary">{{ title }}</h2>
                <UiDataTable min-width="1560px">
                    <table>
                        <thead>
                            <tr>
                                <th class="w-12">STT</th>
                                <th class="min-w-[220px]">{{ columns.content }} <span class="text-error">*</span></th>
                                <th class="min-w-[220px]">{{ columns.detail }}</th>
                                <th class="min-w-[160px]">{{ columns.deadline }}</th>
                                <th class="min-w-[150px]">{{ columns.progress }}</th>
                                <th class="min-w-[180px]">{{ columns.link }}</th>
                                <th class="min-w-[160px]">{{ columns.report }}</th>
                                <th class="min-w-[160px]">{{ columns.support }}</th>
                                <th class="min-w-[160px]">{{ columns.note }}</th>
                                <th class="w-16 text-center">Thao tác</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(row, i) in lines[key]" :key="row.uid" class="align-top">
                                <td class="pt-md font-code text-on-surface-variant">{{ i + 1 }}</td>
                                <td><UiInput :name="name(key, i, 'content')" :value="row.content" placeholder="Nhập nội dung" :aria-label="`${title}: nội dung dòng ${i + 1}`" /></td>
                                <td><UiTextarea :name="name(key, i, 'detail')" :value="row.detail" :rows="2" placeholder="Chi tiết..." :aria-label="`Chi tiết dòng ${i + 1}`" /></td>
                                <td><UiInput :name="name(key, i, 'deadline')" type="date" :value="row.deadline" :aria-label="`Deadline dòng ${i + 1}`" /></td>
                                <td><UiSelect :name="name(key, i, 'progress')" :options="progressOptions" :value="row.progress" placeholder="Chọn tiến độ" :searchable="false" /></td>
                                <td><UiInput :name="name(key, i, 'link')" type="url" :value="row.link" placeholder="https://..." :aria-label="`Link sản phẩm dòng ${i + 1}`" /></td>
                                <td><UiInput :name="name(key, i, 'report')" :value="row.report" placeholder="..." :aria-label="`Báo cáo dòng ${i + 1}`" /></td>
                                <td><UiInput :name="name(key, i, 'support')" :value="row.support" placeholder="..." :aria-label="`Đề xuất hỗ trợ dòng ${i + 1}`" /></td>
                                <td><UiInput :name="name(key, i, 'note')" :value="row.note" placeholder="..." :aria-label="`Ghi chú dòng ${i + 1}`" /></td>
                                <td class="text-center">
                                    <UiButton variant="danger-text" size="sm" icon="delete" :aria-label="`Xóa dòng ${i + 1}`" title="Xóa dòng" @click="remove(key, i)" />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </UiDataTable>
                <UiButton variant="secondary" size="sm" icon="add" @click="add(key)">Thêm dòng</UiButton>
            </section>

            <div class="flex justify-end">
                <UiButton type="submit" icon="save">Lưu báo cáo tuần</UiButton>
            </div>
        </UiForm>

        <div class="grid grid-cols-1 gap-lg lg:grid-cols-2">
            <ReportHistory :items="history" :current="week" title="Các tuần đã nộp" />
            <LegacyReports :items="legacy" />
        </div>
    </div>
</template>
