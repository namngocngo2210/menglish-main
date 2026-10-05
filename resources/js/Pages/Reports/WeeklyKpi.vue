<script setup>
/**
 * Báo cáo tuần Học vụ (mockup "Nhập Báo cáo tuần"): số lần phát sinh theo từng mục KPI đang áp dụng (nhóm theo cấu hình
 * KPI Học vụ) + chỉ số quy mô (theo dõi, không tính KPI). Mỗi tuần 1 báo cáo, lưu lại trong tuần là cập nhật.
 */
import ReportTabs from './ReportTabs.vue';
import ReportHistory from './ReportHistory.vue';

defineOptions({ layout: { title: 'Báo cáo tuần Học vụ' } });

defineProps({
    tabs: { type: Array, default: () => [] },
    week: { type: String, required: true },
    weeks: { type: Array, default: () => [] },
    groups: { type: Array, default: () => [] },
    metrics: { type: Object, default: () => ({}) },
    metricValues: { type: Object, default: () => ({}) },
    submittedAt: { type: String, default: null },
    history: { type: Array, default: () => [] },
});
</script>

<template>
    <UiPageHeader title="Nhập báo cáo tuần" icon="assignment_turned_in">
        <template #meta>Nhập số liệu thực tế phát sinh trong tuần học vụ tương ứng</template>
    </UiPageHeader>

    <ReportTabs :tabs="tabs" />

    <UiFilterBar :search="false">
        <UiSelect name="week" label="Tuần báo cáo" :options="weeks" :value="week" />
    </UiFilterBar>

    <div class="grid grid-cols-1 gap-lg lg:grid-cols-[1fr_280px]">
        <UiForm id="weekly-kpi-form" :action="route('reports.periodic.weekly-kpi.store')" method="post" class="space-y-lg">
            <input type="hidden" name="week" :value="week" />
            <UiAlert v-if="submittedAt" type="info">Đã nộp tuần này lúc {{ formatDate(submittedAt, 'H:i d/m/Y') }} — lưu lại để cập nhật.</UiAlert>

            <section class="rounded-2xl border border-surface-container-highest bg-surface-container-lowest shadow-sm">
                <h2 class="flex items-center gap-xs border-b border-surface-container-highest px-lg py-md font-semibold text-on-surface">
                    <span class="material-symbols-outlined text-[20px] text-primary-container" aria-hidden="true">assignment_turned_in</span>Số lần phát sinh theo mục KPI
                </h2>
                <UiDataTable>
                    <table>
                        <thead>
                            <tr>
                                <th>Hạng mục công việc</th>
                                <th class="w-40 text-right">Số lần phát sinh</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="group in groups" :key="group.name">
                                <tr class="bg-surface-container-low">
                                    <td colspan="2" class="font-body-small text-body-small font-semibold uppercase text-on-surface-variant">Nhóm {{ group.name }}</td>
                                </tr>
                                <tr v-for="item in group.items" :key="item.id">
                                    <td><span class="font-code text-on-surface-variant">{{ item.code }}</span> {{ item.name }}</td>
                                    <td class="text-right">
                                        <UiInput :name="`counts[${item.id}]`" type="number" min="0" :value="item.count" placeholder="0" class="text-right" :aria-label="`Số lần phát sinh: ${item.name}`" />
                                    </td>
                                </tr>
                            </template>
                            <tr v-if="!groups.length">
                                <td colspan="2"><UiEmptyState icon="tune" title="Chưa có mục KPI nào đang áp dụng" description="Admin cấu hình mục KPI ở màn Tiêu chí KPI." /></td>
                            </tr>
                        </tbody>
                    </table>
                </UiDataTable>
            </section>

            <section class="space-y-md rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-lg shadow-sm">
                <h2 class="flex items-center gap-xs font-semibold text-on-surface">
                    <span class="material-symbols-outlined text-[20px] text-primary-container" aria-hidden="true">monitoring</span>Thông tin theo dõi
                    <span class="font-body-small text-body-small font-normal text-on-surface-variant">(chỉ số quy mô, không tính KPI)</span>
                </h2>
                <div class="grid grid-cols-1 gap-md sm:grid-cols-3">
                    <UiInput v-for="(label, key) in metrics" :key="key" :name="`metrics[${key}]`" type="number" min="0" :label="label" :value="metricValues[key]" />
                </div>
            </section>

            <div class="flex justify-end">
                <UiButton type="submit" icon="save">Lưu báo cáo tuần</UiButton>
            </div>
        </UiForm>

        <ReportHistory :items="history" :current="week" title="Các tuần đã nộp" />
    </div>
</template>
