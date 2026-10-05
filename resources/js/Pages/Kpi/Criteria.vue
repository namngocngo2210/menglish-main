<script setup>
/**
 * Tiêu chí KPI (bộ Học vụ theo file Excel KPI): 6 nhóm / 15 tiêu chí, trọng số % quỹ, số lần lỗi tối đa để đạt 100% / 50%
 * (đếm lỗi, càng ít càng tốt; vượt ngưỡng 50% = 0%); sửa từng mục ngay trên dòng (Lưu / Xoá), thêm mục mới trong modal new-kpi.
 * Tiền KPI tháng = quỹ × điểm KPI có trọng số (tự động vào bảng lương).
 */
import { computed, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { money } from '../Payroll/format';

defineOptions({ layout: { title: 'Tiêu chí KPI' } });

const props = defineProps({
    groups: { type: Array, default: () => [] },
    fund: { type: Number, default: 0 },
    totalWeight: { type: Number, default: 0 },
    totalWeightLabel: { type: String, default: '0' },
    criteriaGroups: { type: Array, default: () => [] },
});

const page = usePage();
const firstError = computed(() => Object.values(page.props.errors ?? {})[0] ?? null);
const matched = computed(() => Math.abs(props.totalWeight - 100) < 0.001);
const fundTitle = computed(
    () => `Trạng thái quỹ KPI: Tổng trọng số hiện tại: ${money((props.fund * props.totalWeight) / 100)}đ / Quỹ KPI: ${money(props.fund)}đ (${matched.value ? 'Khớp' : 'Chưa khớp'})`,
);
const newOpen = ref(false);
const input = 'rounded-lg border-outline-variant focus:border-primary-container focus:ring-primary-container';
</script>

<template>
    <div>
        <UiPageHeader title="Tiêu chí KPI" description="Bộ tiêu chí KPI Học vụ — 6 nhóm / 15 tiêu chí. Mỗi tiêu chí đếm số lần lỗi trong tháng và chỉ có 3 mức: ≤ ngưỡng 100% thì nhận đủ quỹ tiêu chí, ≤ ngưỡng 50% thì nhận một nửa, vượt thì 0. Tiền KPI tháng = quỹ × điểm KPI có trọng số (tự động vào bảng lương).">
            <template v-if="can('kpi.manage')" #actions>
                <UiButton icon="add" @click="newOpen = true">Thêm mục mới</UiButton>
            </template>
        </UiPageHeader>

        <div class="space-y-lg">
            <UiAlert v-if="firstError" type="error">{{ firstError }}</UiAlert>

            <UiAlert :type="matched ? 'success' : 'warning'" :title="fundTitle">
                Tổng trọng số đang áp dụng: {{ totalWeightLabel }}%.
                <template v-if="!matched">
                    Tổng trọng số của các mục đang áp dụng chưa khớp với Quỹ KPI hiện tại. Vui lòng kiểm tra lại để đảm bảo tính chính xác khi tính lương.
                </template>
            </UiAlert>

            <datalist id="kpi-groups">
                <option v-for="group in groups" :key="group" :value="group"></option>
            </datalist>

            <!-- Danh sách / sửa, theo nhóm -->
            <div class="space-y-4">
                <div v-for="group in criteriaGroups" :key="group.name" class="space-y-2">
                    <div class="flex items-center justify-between px-1">
                        <h3 class="text-xs font-bold uppercase tracking-wider text-on-surface-variant">{{ group.name }}</h3>
                        <span class="text-xs font-semibold text-on-surface-variant">{{ group.count }} mục · {{ money(group.active_fund) }} đ</span>
                    </div>
                    <template v-for="cr in group.items" :key="cr.id">
                        <UiForm :action="route('kpi.criteria.update', cr.id)" method="put" :class="['rounded-xl border bg-surface-container-lowest p-4 shadow-sm', cr.is_active ? 'border-outline-variant' : 'border-outline-variant opacity-60']">
                            <div class="grid grid-cols-1 items-center gap-2 sm:grid-cols-12">
                                <input type="text" name="code" :value="cr.code" placeholder="Mã" aria-label="Mã mục" :class="['text-sm sm:col-span-1', input]" title="Mã mục" />
                                <input type="text" name="name" :value="cr.name" aria-label="Tên mục" :class="['text-sm sm:col-span-3', input]" />
                                <input type="number" name="weight" step="0.25" min="0" max="100" :value="cr.weight" aria-label="Trọng số % quỹ" :class="['text-sm sm:col-span-1', input]" title="Trọng số % quỹ" />
                                <span class="font-mono text-xs font-bold text-primary sm:col-span-2" title="Tiền KPI tối đa của mục">{{ money(cr.fund_amount) }} đ</span>
                                <input type="number" name="max_full" min="0" max="9999" :value="cr.max_full" placeholder="≤ 100%" aria-label="Số lần tối đa để đạt 100%" :class="['text-sm sm:col-span-1', input]" title="Số lần tối đa để đạt 100% quỹ tiêu chí" />
                                <input type="number" name="max_half" min="0" max="9999" :value="cr.max_half" placeholder="≤ 50%" aria-label="Số lần tối đa để đạt 50%" :class="['text-sm sm:col-span-1', input]" title="Số lần tối đa để đạt 50% quỹ tiêu chí (vượt = 0%)" />
                                <input type="text" name="unit" :value="cr.unit" placeholder="Đơn vị" aria-label="Đơn vị" :class="['text-sm sm:col-span-1', input]" title="Đơn vị đếm: lần, case, lớp…" />
                                <label class="flex items-center gap-1 text-xs text-on-surface-variant sm:col-span-1">
                                    <input type="checkbox" name="is_active" value="1" :checked="cr.is_active" class="rounded border-outline-variant text-primary focus:ring-primary-container" /> Bật
                                </label>
                                <div class="flex items-center justify-end gap-1 sm:col-span-1">
                                    <UiButton type="submit" variant="ghost" size="sm" icon="save" title="Lưu" aria-label="Lưu" class="!text-tertiary" />
                                </div>
                            </div>
                            <div class="mt-2 grid grid-cols-1 items-center gap-2 sm:grid-cols-12">
                                <input type="text" name="group_name" :value="cr.group_name" list="kpi-groups" placeholder="Nhóm" aria-label="Nhóm" :class="['text-xs sm:col-span-3', input]" />
                                <input type="text" name="description" :value="cr.description" placeholder="Cách đo" aria-label="Cách đo" :class="['text-xs sm:col-span-8', input]" />
                                <div class="flex justify-end sm:col-span-1">
                                    <UiButton type="submit" :form="`del-${cr.id}`" variant="danger-text" size="sm">Xoá</UiButton>
                                </div>
                            </div>
                        </UiForm>
                        <UiForm :id="`del-${cr.id}`" :action="route('kpi.criteria.destroy', cr.id)" method="delete" confirm="Xoá mục KPI này?" confirm-label="Xóa" danger class="hidden" />
                    </template>
                </div>
                <div v-if="!criteriaGroups.length" class="rounded-xl border border-outline-variant bg-surface-container-lowest shadow-sm">
                    <UiEmptyState icon="tune" title='Chưa có mục KPI nào. Bấm "Thêm mục mới" để tạo.' />
                </div>
            </div>
        </div>

        <UiModal v-if="can('kpi.manage')" :show="newOpen" title="Thêm mục KPI mới" max-width="xl" data-modal="new-kpi" @close="newOpen = false">
            <UiForm id="kpi-add-form" :action="route('kpi.criteria.store')" method="post" preserve-state="errors" class="space-y-md">
                <div class="grid grid-cols-1 gap-md sm:grid-cols-2">
                    <UiInput name="group_name" label="Nhóm KPI" list="kpi-groups" placeholder="vd: Chăm sóc học viên" />
                    <UiInput name="code" label="Mã" placeholder="vd: 1.4" />
                </div>
                <UiInput name="name" label="Tên mục" required />
                <div class="grid grid-cols-1 gap-md sm:grid-cols-2">
                    <UiInput type="number" name="weight" label="Trọng số % quỹ" required step="0.25" min="0" max="100" />
                    <UiInput name="unit" label="Đơn vị đếm" placeholder="vd: lần, case, lớp" />
                    <UiInput type="number" name="max_full" label="Tối đa để đạt 100%" min="0" max="9999" placeholder="vd: 0" />
                    <UiInput type="number" name="max_half" label="Tối đa để đạt 50%" min="0" max="9999" placeholder="vd: 2" />
                </div>
                <UiInput name="description" label="Cách đo" placeholder="Đếm cái gì, tính vào tháng nào" />
            </UiForm>
            <template #footer>
                <UiButton variant="secondary" @click="newOpen = false">Hủy</UiButton>
                <UiButton type="submit" form="kpi-add-form" icon="add">Thêm mục</UiButton>
            </template>
        </UiModal>
    </div>
</template>
