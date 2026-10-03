<script setup>
/**
 * Đánh giá dự giờ học thuật (mockup 02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu/16): theo tháng, mỗi lớp có học trong tháng 1 dòng
 * — % chuyên cần, % đạt yêu cầu, đã dự giờ chưa. Bấm dòng mở modal đánh giá: công tắc "Đã dự giờ" → ngày, người dự giờ,
 * 6 tiêu chí nhận xét, ghi chú / hành động. % chuyên cần gợi ý từ điểm danh của lớp trong tháng.
 */
import { computed, ref } from 'vue';
import { formatNumber } from '@/lib/format';

defineOptions({ layout: { title: 'Đánh giá dự giờ' } });

const props = defineProps({
    month: { type: String, required: true },
    months: { type: Array, default: () => [] },
    classes: { type: Object, required: true },
    stats: { type: Object, default: () => ({ total: 0, observed: 0 }) },
    criteria: { type: Object, default: () => ({}) },
    observers: { type: Array, default: () => [] },
    canRecord: { type: Boolean, default: false },
    currentUserId: { type: Number, default: null },
});

const editing = ref(null);
const observed = ref(true);
const monthLabel = computed(() => props.months.find((m) => m.value === props.month)?.label ?? props.month);

function open(row) {
    editing.value = row;
    observed.value = row.evaluation ? row.evaluation.observed : true;
}
const rate = (value) => {
    if (value === null || value === undefined || value === '') return '—';
    const n = Number(value);
    return `${formatNumber(n, Number.isInteger(n) ? 0 : 1)}%`;
};
</script>

<template>
    <UiPageHeader title="Đánh giá dự giờ" icon="fact_check" description="Ghi nhận đánh giá định kỳ và chuyên môn giảng dạy theo tháng cho từng lớp học." />

    <div class="mb-lg grid grid-cols-1 gap-md sm:grid-cols-3">
        <UiStatCard label="Lớp có học trong tháng" :value="stats.total" icon="co_present" />
        <UiStatCard label="Đã dự giờ" :value="stats.observed" icon="task_alt" tone="success" />
        <UiStatCard label="Chưa dự giờ" :value="Math.max(0, stats.total - stats.observed)" icon="event_busy" :tone="stats.total - stats.observed > 0 ? 'warning' : 'default'" />
    </div>

    <UiFilterBar placeholder="Tìm mã / tên lớp...">
        <UiSelect name="month" label="Tháng" :options="months" :value="month" />
        <UiSelect name="status" label="Dự giờ" :options="[{ value: 'observed', label: 'Đã dự giờ' }, { value: 'pending', label: 'Chưa dự giờ' }]" placeholder="Tất cả lớp" />
    </UiFilterBar>

    <UiDataTable min-width="900px">
        <table>
            <thead>
                <tr>
                    <th>Lớp</th>
                    <th>Giáo viên</th>
                    <th class="text-right">% chuyên cần</th>
                    <th class="text-right">% đạt yêu cầu</th>
                    <th>Dự giờ {{ monthLabel.toLowerCase() }}</th>
                    <th class="text-right">Thao tác</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="c in classes.data" :key="c.id" class="cursor-pointer" @click="open(c)">
                    <td>
                        <span class="font-semibold text-on-surface">{{ c.code }}</span>
                        <span class="block font-body-small text-body-small text-on-surface-variant">{{ c.name }}<template v-if="c.course"> · {{ c.course }}</template></span>
                    </td>
                    <td>{{ c.teacher ?? '—' }}</td>
                    <td class="text-right font-code">
                        <template v-if="c.evaluation?.attendance_rate !== null && c.evaluation?.attendance_rate !== undefined">{{ rate(c.evaluation.attendance_rate) }}</template>
                        <span v-else-if="c.suggested_attendance !== null" class="text-on-surface-variant" title="Tính từ điểm danh trong tháng">{{ rate(c.suggested_attendance) }}</span>
                        <template v-else>—</template>
                    </td>
                    <td class="text-right font-code">{{ rate(c.evaluation?.pass_rate) }}</td>
                    <td>
                        <UiBadge v-if="c.evaluation?.observed" color="success">Đã dự giờ {{ formatDate(c.evaluation.observed_on, 'd/m') }}</UiBadge>
                        <UiBadge v-else color="warning">Chưa dự giờ</UiBadge>
                        <span v-if="c.evaluation?.observed" class="block font-body-small text-body-small text-on-surface-variant">{{ c.evaluation.observer }}</span>
                    </td>
                    <td class="whitespace-nowrap text-right" @click.stop>
                        <UiButton variant="ghost" size="sm" :icon="canRecord ? 'edit_note' : 'visibility'" @click="open(c)">{{ canRecord ? 'Đánh giá' : 'Xem' }}</UiButton>
                    </td>
                </tr>
                <tr v-if="!classes.data.length">
                    <td colspan="6"><UiEmptyState icon="co_present" title="Không có lớp nào học trong tháng này" /></td>
                </tr>
            </tbody>
        </table>
        <template #footer><UiPagination :paginator="classes" unit="lớp" /></template>
    </UiDataTable>

    <UiModal :show="!!editing" :title="editing ? `Đánh giá dự giờ — ${editing.code}` : ''" max-width="3xl" @close="editing = null">
        <UiForm v-if="editing" id="academic-observation-form" :action="route('class-quality.academic.save')" method="put" class="space-y-lg" @success="editing = null">
            <input type="hidden" name="class_id" :value="editing.id" />
            <input type="hidden" name="month" :value="month" />
            <input type="hidden" name="observed" :value="observed ? 1 : 0" />
            <fieldset :disabled="!canRecord" class="space-y-lg">
                <section class="space-y-md">
                    <h3 class="flex items-center gap-xs font-semibold text-on-surface">
                        <span class="material-symbols-outlined text-[18px] text-primary-container" aria-hidden="true">info</span>Thông tin cơ bản
                    </h3>
                    <div class="grid grid-cols-1 gap-md sm:grid-cols-2">
                        <UiField label="Lớp"><p class="py-sm font-semibold">{{ editing.code }} · {{ editing.name }}<template v-if="editing.teacher"> (GV: {{ editing.teacher }})</template></p></UiField>
                        <UiField label="Tháng"><p class="py-sm font-semibold">{{ monthLabel }}</p></UiField>
                        <UiInput
                            name="attendance_rate"
                            type="number"
                            step="0.1"
                            min="0"
                            max="100"
                            suffix="%"
                            label="Tỉ lệ % chuyên cần học sinh"
                            :value="editing.evaluation?.attendance_rate ?? editing.suggested_attendance"
                            :hint="editing.suggested_attendance !== null ? `Theo điểm danh trong tháng: ${rate(editing.suggested_attendance)}` : 'Chưa có dữ liệu điểm danh trong tháng'"
                        />
                        <UiInput name="pass_rate" type="number" step="0.1" min="0" max="100" suffix="%" label="Tỉ lệ % học sinh đạt yêu cầu" :value="editing.evaluation?.pass_rate" />
                    </div>
                </section>

                <section class="space-y-md">
                    <div class="flex items-center justify-between gap-md">
                        <h3 class="flex items-center gap-xs font-semibold text-on-surface">
                            <span class="material-symbols-outlined text-[18px] text-primary-container" aria-hidden="true">fact_check</span>Chi tiết đánh giá
                        </h3>
                        <UiCheckbox v-model="observed" label="Đã dự giờ" />
                    </div>
                    <template v-if="observed">
                        <div class="grid grid-cols-1 gap-md sm:grid-cols-2">
                            <UiDate name="observed_on" label="Ngày dự giờ" required :value="editing.evaluation?.observed_on" />
                            <UiSelect name="observer_id" label="Người dự giờ" required :options="observers" placeholder="-- Chọn người dự giờ --" :value="editing.evaluation?.observer_id ?? currentUserId" />
                        </div>
                        <div class="grid grid-cols-1 gap-md sm:grid-cols-2">
                            <UiTextarea v-for="(label, key, index) in criteria" :key="key" :name="key" :label="`${index + 1}. ${label}`" :rows="3" :value="editing.evaluation?.criteria?.[key]" />
                        </div>
                        <UiTextarea name="action_notes" label="Ghi chú / Hành động" :rows="3" :value="editing.evaluation?.action_notes" placeholder="Kế hoạch theo dõi tiếp theo, đề xuất khắc phục hoặc giải pháp cải thiện chuyên môn..." />
                    </template>
                    <div v-else class="rounded-xl border border-dashed border-outline-variant p-lg text-center text-on-surface-variant">
                        <span class="material-symbols-outlined text-[32px]" aria-hidden="true">event_busy</span>
                        <p class="mt-xs">Lớp học này chưa thực hiện dự giờ trong tháng đã chọn.</p>
                        <p class="font-body-small text-body-small">Bật “Đã dự giờ” để ghi nhận thông tin và đánh giá chi tiết.</p>
                    </div>
                </section>
            </fieldset>
        </UiForm>
        <template #footer>
            <UiButton variant="secondary" @click="editing = null">{{ canRecord ? 'Hủy bỏ' : 'Đóng' }}</UiButton>
            <UiButton v-if="canRecord" type="submit" form="academic-observation-form" icon="save">Lưu</UiButton>
        </template>
    </UiModal>
</template>
