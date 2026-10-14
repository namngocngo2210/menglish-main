<script setup>
/**
 * Báo cáo tuần Học vụ (mockup 02/07 "Nhập Báo cáo tuần"): trái là số lần phát sinh theo từng mục KPI đang áp dụng (nhóm theo
 * cấu hình KPI Học vụ), phải là thông tin theo dõi (chỉ số quy mô, không tính KPI). Mỗi tuần 1 báo cáo, lưu lại trong tuần là cập nhật.
 */
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { route } from '@/lib/route';
import ReportTabs from './ReportTabs.vue';
import ReportHistory from './ReportHistory.vue';

defineOptions({ layout: { title: 'Báo cáo tuần Học vụ' } });

const props = defineProps({
    tabs: { type: Array, default: () => [] },
    week: { type: String, required: true },
    weeks: { type: Array, default: () => [] },
    groups: { type: Array, default: () => [] },
    metrics: { type: Object, default: () => ({}) },
    metricValues: { type: Object, default: () => ({}) },
    submittedAt: { type: String, default: null },
    history: { type: Array, default: () => [] },
});

const form = ref(null);
const pickWeek = (value) => value && value !== props.week && router.get(route('reports.periodic.weekly-kpi'), { week: value });
</script>

<template>
    <UiPageHeader title="Nhập báo cáo tuần" icon="assignment_turned_in">
        <template #badges>
            <UiInfo title="Hướng dẫn nhập báo cáo tuần">
                <p>Nhập số liệu thực tế phát sinh trong tuần học vụ tương ứng.</p>
                <p>Mỗi mục KPI ghi <strong class="text-on-surface">số lần phát sinh</strong> trong tuần (để trống = 0). Các chỉ số quy mô chỉ để theo dõi, không tính KPI.</p>
            </UiInfo>
        </template>
        <template #actions>
            <div class="w-72">
                <p class="mb-xs font-body-small text-body-small font-semibold text-on-surface-variant">Tuần báo cáo</p>
                <UiSelect :model-value="week" :options="weeks" :searchable="false" aria-label="Tuần báo cáo" @update:model-value="pickWeek" />
            </div>
        </template>
    </UiPageHeader>

    <ReportTabs :tabs="tabs" />

    <UiForm ref="form" id="weekly-kpi-form" :action="route('reports.periodic.weekly-kpi.store')" method="post" class="space-y-lg">
        <input type="hidden" name="week" :value="week" />
        <UiAlert v-if="submittedAt" type="info">Đã nộp tuần này lúc {{ formatDate(submittedAt, 'H:i d/m/Y') }}. Lưu lại để cập nhật.</UiAlert>

        <div class="grid grid-cols-1 gap-lg lg:grid-cols-[1fr_340px]">
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
                                    <td colspan="2" class="font-body-small text-body-small font-semibold italic text-on-surface-variant">Nhóm {{ group.name }}</td>
                                </tr>
                                <tr v-for="item in group.items" :key="item.id">
                                    <td>{{ item.name }} <span class="font-code text-xs text-on-surface-subtle">{{ item.code }}</span></td>
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

            <div class="space-y-lg">
                <section class="rounded-2xl border border-surface-container-highest bg-surface-container-lowest shadow-sm" data-weekly-metrics>
                    <h2 class="flex items-center gap-xs border-b border-surface-container-highest px-lg py-md font-semibold text-on-surface">
                        <span class="material-symbols-outlined text-[20px] text-primary-container" aria-hidden="true">monitoring</span>Thông tin theo dõi
                    </h2>
                    <div class="space-y-sm p-lg">
                        <p class="font-body-small text-body-small text-on-surface-variant">(Chỉ số quy mô, không tính KPI)</p>
                        <div v-for="(label, key) in metrics" :key="key" class="grid grid-cols-[1fr_120px] items-center gap-sm">
                            <label :for="`f_metrics_${key}_`" class="font-body-base text-body-base text-on-surface">{{ label }}</label>
                            <UiInput :id="`f_metrics_${key}_`" :name="`metrics[${key}]`" type="number" min="0" placeholder="0" class="text-right" :value="metricValues[key]" />
                        </div>
                    </div>
                </section>
                <ReportHistory :items="history" :current="week" title="Các tuần đã nộp" />
            </div>
        </div>

        <div class="flex justify-end gap-sm border-t border-surface-container-highest pt-lg">
            <UiButton variant="secondary" @click="form?.reset()">Hủy bỏ</UiButton>
            <UiButton type="submit" icon="save">Lưu báo cáo tuần</UiButton>
        </div>
    </UiForm>
</template>
