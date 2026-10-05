<script setup>
/**
 * Tiêu chí KPI theo vai trò cố định: chọn vai trò ở dropdown → hiện các nhóm tiêu chí của vai trò đó.
 * Mỗi tiêu chí đếm số lần trong tháng, đơn vị chọn từ danh sách (chuẩn hóa), 3 mức: ≤ ngưỡng 100% → đủ quỹ, ≤ ngưỡng 50% → một nửa, vượt → 0.
 * Thêm / sửa trong modal (bấm dòng để sửa). Học vụ có quỹ tiền KPI (vào bảng lương); vai trò khác chấm theo % đạt.
 */
import { computed, reactive, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { can } from '@/lib/can';
import { route } from '@/lib/route';
import { money } from '../Payroll/format';

defineOptions({ layout: { title: 'Tiêu chí KPI' } });

const props = defineProps({
    role: { type: String, required: true },
    roleOptions: { type: Array, default: () => [] },
    unitOptions: { type: Array, default: () => [] },
    groups: { type: Array, default: () => [] },
    fund: { type: Number, default: null },
    totalWeight: { type: Number, default: 0 },
    totalWeightLabel: { type: String, default: '0' },
    criteriaGroups: { type: Array, default: () => [] },
});

const page = usePage();
const firstError = computed(() => Object.values(page.props.errors ?? {})[0] ?? null);
const hasFund = computed(() => props.fund !== null);
const matched = computed(() => Math.abs(props.totalWeight - 100) < 0.001);
const roleLabel = computed(() => props.roleOptions.find((o) => o.value === props.role)?.label ?? '');
const groupOptions = computed(() => props.groups.map((g) => ({ value: g, label: g })));
const canManage = computed(() => can('kpi.manage'));

const selectedRole = ref(props.role);
watch(selectedRole, (value) => {
    if (value && value !== props.role) router.get(route('kpi.criteria'), { role: value });
});

// Modal thêm / sửa: một form, đổi nội dung theo tiêu chí đang sửa (null = thêm mới).
const modal = reactive({ open: false, item: null, weight: '' });
function openCreate() {
    modal.item = null;
    modal.weight = '';
    modal.open = true;
}
function openEdit(item) {
    if (!canManage.value) return;
    modal.item = item;
    modal.weight = item.weight;
    modal.open = true;
}
const weightMoney = computed(() => (hasFund.value ? Math.round((props.fund * (parseFloat(modal.weight) || 0)) / 100) : null));
const formKey = computed(() => (modal.item ? `edit-${modal.item.id}` : 'new'));
</script>

<template>
    <div>
        <UiPageHeader title="Tiêu chí KPI" description="Mỗi vai trò một bộ tiêu chí. Tiêu chí đếm số lần trong tháng: không vượt ngưỡng 100% thì đạt đủ, không vượt ngưỡng 50% thì đạt một nửa, vượt thì 0.">
            <template v-if="canManage" #actions>
                <UiButton icon="add" @click="openCreate">Thêm tiêu chí</UiButton>
            </template>
        </UiPageHeader>

        <div class="space-y-lg">
            <UiAlert v-if="firstError" type="error">{{ firstError }}</UiAlert>

            <div class="flex flex-col gap-md rounded-xl border border-outline-variant bg-surface-container-lowest p-md shadow-sm sm:flex-row sm:items-end sm:justify-between">
                <div class="w-full sm:max-w-xs">
                    <UiSelect v-model="selectedRole" name="role" label="Vai trò" :options="roleOptions" :searchable="false" />
                </div>
                <p class="font-body-small text-body-small text-on-surface-variant">
                    Tổng trọng số đang áp dụng:
                    <strong :class="matched ? 'text-tertiary' : 'text-warning'">{{ totalWeightLabel }}%</strong>
                    <template v-if="hasFund"> · Quỹ KPI tháng <strong class="text-on-surface">{{ money(fund) }} đ</strong></template>
                    <template v-else> · Vai trò này chưa có quỹ tiền KPI trong bảng lương, KPI tính theo % đạt</template>
                </p>
            </div>

            <UiAlert v-if="criteriaGroups.length && !matched" type="warning">
                Tổng trọng số các tiêu chí đang áp dụng của {{ roleLabel }} là {{ totalWeightLabel }}%, chưa bằng 100%. KPI vẫn tính theo tỉ lệ trọng số.
            </UiAlert>

            <UiDataTable v-if="criteriaGroups.length" min-width="900px">
                <table>
                    <thead>
                        <tr>
                            <th>Tiêu chí</th>
                            <th class="text-right">Trọng số</th>
                            <th v-if="hasFund" class="text-right">Quỹ</th>
                            <th>Đạt 100% khi</th>
                            <th>Đạt 50% khi</th>
                            <th>Trạng thái</th>
                            <th v-if="canManage" class="text-right"><span class="sr-only">Thao tác</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="group in criteriaGroups" :key="group.name">
                            <tr class="bg-surface-container-low">
                                <td :colspan="hasFund ? 7 : 6" class="font-body-semibold text-body-semibold text-on-surface">
                                    {{ group.name }}
                                    <span class="ml-sm font-caption text-caption font-normal text-on-surface-variant">
                                        {{ group.count }} tiêu chí · {{ group.active_weight }}%<template v-if="hasFund"> · {{ money(group.active_fund) }} đ</template>
                                    </span>
                                </td>
                            </tr>
                            <tr
                                v-for="cr in group.items"
                                :key="cr.id"
                                :class="[canManage ? 'cursor-pointer hover:bg-surface-container-low' : '', cr.is_active ? '' : 'opacity-60']"
                                :tabindex="canManage ? 0 : undefined"
                                @click="openEdit(cr)"
                                @keydown.enter="openEdit(cr)"
                            >
                                <td class="min-w-[260px] max-w-md">
                                    <p class="font-medium text-on-surface">{{ cr.name }}</p>
                                    <p v-if="cr.description" class="line-clamp-2 font-caption text-caption text-on-surface-variant">{{ cr.description }}</p>
                                </td>
                                <td class="whitespace-nowrap text-right font-mono">{{ cr.weight }}%</td>
                                <td v-if="hasFund" class="whitespace-nowrap text-right font-mono font-semibold text-primary">{{ money(cr.fund_amount) }} đ</td>
                                <td class="whitespace-nowrap">{{ cr.threshold_full || '—' }}</td>
                                <td class="whitespace-nowrap">{{ cr.threshold_half || '—' }}</td>
                                <td>
                                    <UiBadge :color="cr.is_active ? 'success' : 'neutral'">{{ cr.is_active ? 'Áp dụng' : 'Tạm tắt' }}</UiBadge>
                                </td>
                                <td v-if="canManage" class="whitespace-nowrap" @click.stop>
                                    <div class="flex items-center justify-end gap-xs">
                                        <UiButton variant="ghost" size="sm" icon="edit" title="Sửa tiêu chí" :aria-label="`Sửa ${cr.name}`" @click="openEdit(cr)" />
                                        <UiButton type="submit" :form="`del-${cr.id}`" variant="danger-text" size="sm" icon="delete" title="Xóa tiêu chí" :aria-label="`Xóa ${cr.name}`" />
                                    </div>
                                    <UiForm :id="`del-${cr.id}`" :action="route('kpi.criteria.destroy', cr.id)" method="delete" :confirm="`Xoá tiêu chí ${cr.name}?`" confirm-label="Xóa" danger class="hidden" />
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </UiDataTable>

            <div v-else class="rounded-xl border border-outline-variant bg-surface-container-lowest shadow-sm">
                <UiEmptyState icon="tune" :title="`${roleLabel} chưa có tiêu chí KPI`" description="Thêm tiêu chí để chấm KPI tháng cho vai trò này.">
                    <UiButton v-if="canManage" icon="add" @click="openCreate">Thêm tiêu chí</UiButton>
                </UiEmptyState>
            </div>
        </div>

        <UiModal v-if="canManage" :show="modal.open" :title="modal.item ? 'Sửa tiêu chí KPI' : `Thêm tiêu chí KPI · ${roleLabel}`" max-width="xl" data-modal="kpi-criterion" @close="modal.open = false">
            <UiForm
                id="kpi-criterion-form"
                :key="formKey"
                :action="modal.item ? route('kpi.criteria.update', modal.item.id) : route('kpi.criteria.store')"
                :method="modal.item ? 'put' : 'post'"
                preserve-state="errors"
                class="space-y-md"
                @success="modal.open = false"
            >
                <input v-if="!modal.item" type="hidden" name="role" :value="role" />
                <div class="grid grid-cols-1 gap-md sm:grid-cols-2">
                    <UiSelect name="group_name" label="Nhóm" :options="groupOptions" :value="modal.item?.group_name ?? null" placeholder="-- Chọn nhóm --" />
                    <UiInput name="new_group" label="Hoặc tạo nhóm mới" placeholder="vd: Học phí & dữ liệu" />
                </div>
                <UiInput name="name" label="Tên tiêu chí" required :value="modal.item?.name ?? ''" />
                <div class="grid grid-cols-1 gap-md sm:grid-cols-2">
                    <UiInput
                        v-model="modal.weight"
                        type="number"
                        name="weight"
                        label="Trọng số"
                        suffix="% quỹ"
                        required
                        step="0.25"
                        min="0"
                        max="100"
                        :hint="weightMoney !== null ? `= ${money(weightMoney)} đ / tháng` : null"
                    />
                    <UiSelect name="unit" label="Đơn vị đếm" :options="unitOptions" :value="modal.item?.unit ?? null" placeholder="-- Chọn đơn vị --" required :searchable="false" />
                    <UiInput type="number" name="max_full" label="Đạt 100% khi không quá" required min="0" max="9999" :value="modal.item?.max_full ?? ''" hint="Số lần tối đa trong tháng" />
                    <UiInput type="number" name="max_half" label="Đạt 50% khi không quá" required min="0" max="9999" :value="modal.item?.max_half ?? ''" hint="Vượt số này thì 0%" />
                </div>
                <UiTextarea name="description" label="Cách đếm" :rows="3" :value="modal.item?.description ?? ''" placeholder="Đếm cái gì, lấy số liệu ở đâu" />
                <template v-if="modal.item">
                    <input type="hidden" name="is_active" value="0" />
                    <UiCheckbox name="is_active" value="1" :checked="modal.item.is_active" label="Đang áp dụng" />
                </template>
            </UiForm>
            <template #footer>
                <UiButton variant="secondary" @click="modal.open = false">Hủy</UiButton>
                <UiButton type="submit" form="kpi-criterion-form" :icon="modal.item ? 'save' : 'add'">{{ modal.item ? 'Lưu' : 'Thêm tiêu chí' }}</UiButton>
            </template>
        </UiModal>
    </div>
</template>
