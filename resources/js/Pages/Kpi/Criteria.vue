<script setup>
/**
 * Tiêu chí KPI theo vai trò cố định: chọn vai trò ở dropdown → hiện các nhóm tiêu chí và chu kỳ chấm (tháng / quý) của vai trò.
 * Mỗi tiêu chí đo bằng số lần (đơn vị chọn từ danh sách) hoặc tỉ lệ %, mức đạt theo các bậc (ngưỡng → % điểm) hoặc thẳng theo
 * tỉ lệ; có thể là điều kiện loại trừ (có từ 1 lần là mất toàn bộ KPI kỳ). Thêm / sửa trong modal (bấm dòng để sửa).
 * Học vụ có quỹ tiền KPI (vào bảng lương); vai trò khác chấm theo % đạt, phiếu quý có xếp loại A–E.
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
    sourceOptions: { type: Array, default: () => [] },
    measureOptions: { type: Array, default: () => [] },
    periodMonths: { type: Number, default: 1 },
    cycleOptions: { type: Array, default: () => [] },
    grades: { type: Array, default: () => [] },
    gradeFund: { type: Number, default: null },
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
const selectedCycle = ref(props.periodMonths);
watch(selectedCycle, (value) => {
    if (Number(value) !== props.periodMonths) router.post(route('kpi.criteria.cycle'), { role: props.role, period_months: Number(value) }, { preserveScroll: true });
});
// Thưởng KPI tối đa / tháng theo xếp loại (vai trò có xếp loại A–E); để trống = không có khoản thưởng này.
const gradeFundInput = ref(props.gradeFund ?? '');
function saveGradeFund() {
    router.post(route('kpi.criteria.cycle'), { role: props.role, period_months: props.periodMonths, grade_fund: gradeFundInput.value === '' ? null : Number(gradeFundInput.value) }, { preserveScroll: true });
}
const gradeText = computed(() => props.grades.map((g) => `${g.grade} ≥ ${g.from}% (hệ số ${g.pay}%)`).join(' · '));

// Modal thêm / sửa: một form, đổi nội dung theo tiêu chí đang sửa (null = thêm mới).
const blankRule = () => ({ measure: 'count', tiers: [{ at: 0, percent: 100 }, { at: 2, percent: 50 }], linear: false, full_at: 100, knockout: false, per_month: false, allow_na: false });
const modal = reactive({ open: false, item: null, weight: '', ...blankRule() });
function openCreate() {
    Object.assign(modal, { item: null, weight: '', ...blankRule(), open: true });
}
function openEdit(item) {
    if (!canManage.value) return;
    Object.assign(modal, {
        item,
        weight: item.weight,
        measure: item.measure,
        tiers: item.tiers.length ? item.tiers.map(([at, percent]) => ({ at, percent })) : [{ at: item.measure === 'rate' ? 90 : 0, percent: 100 }],
        linear: item.linear,
        full_at: item.full_at ?? 100,
        knockout: item.knockout,
        per_month: item.per_month,
        allow_na: item.allow_na,
        open: true,
    });
}
const isRate = computed(() => modal.measure !== 'count');
const isRateUp = computed(() => modal.measure === 'rate');
const tierLabel = computed(() => (isRateUp.value ? 'Từ (%)' : isRate.value ? 'Không quá (%)' : 'Không quá (số lần)'));
const weightMoney = computed(() => (hasFund.value ? Math.round((props.fund * (parseFloat(modal.weight) || 0)) / 100) : null));
const formKey = computed(() => (modal.item ? `edit-${modal.item.id}` : 'new'));
</script>

<template>
    <div>
        <UiPageHeader title="Tiêu chí KPI" description="Mỗi vai trò một bộ tiêu chí và một chu kỳ chấm (tháng hoặc quý). Tiêu chí đo bằng số lần hoặc tỉ lệ %, mức đạt theo các bậc tính điểm.">
            <template v-if="canManage" #actions>
                <UiButton icon="add" @click="openCreate">Thêm tiêu chí</UiButton>
            </template>
        </UiPageHeader>

        <div class="space-y-lg">
            <UiAlert v-if="firstError" type="error">{{ firstError }}</UiAlert>

            <div class="flex flex-col gap-md rounded-xl border border-outline-variant bg-surface-container-lowest p-md shadow-sm sm:flex-row sm:items-end sm:justify-between">
                <div class="flex w-full flex-col gap-md sm:max-w-2xl sm:flex-row">
                    <div class="w-full sm:w-64">
                        <UiSelect v-model="selectedRole" name="role" label="Vai trò" :options="roleOptions" :searchable="false" />
                    </div>
                    <div v-if="canManage && grades.length && !hasFund" class="w-full sm:w-48">
                        <UiInput v-model="gradeFundInput" type="number" name="grade_fund" label="Thưởng KPI tối đa/tháng" suffix="đ" min="0" step="100000" @change="saveGradeFund" />
                    </div>
                    <div class="w-full sm:w-44">
                        <UiSelect v-if="canManage" v-model="selectedCycle" name="period_months" label="Chu kỳ chấm" :options="cycleOptions" :searchable="false" />
                        <p v-else class="pt-lg font-body-small text-body-small text-on-surface">{{ cycleOptions.find((o) => Number(o.value) === periodMonths)?.label }}</p>
                    </div>
                </div>
                <p class="font-body-small text-body-small text-on-surface-variant">
                    Tổng trọng số đang áp dụng:
                    <strong :class="matched ? 'text-tertiary' : 'text-warning'">{{ totalWeightLabel }}%</strong>
                    <template v-if="hasFund"> · Quỹ KPI tháng <strong class="text-on-surface">{{ money(fund) }} đ</strong></template>
                    <template v-else-if="gradeFund"> · Thưởng KPI tối đa <strong class="text-on-surface">{{ money(gradeFund) }} đ</strong>/tháng × hệ số xếp loại</template>
                    <template v-else> · Vai trò này chưa có quỹ tiền KPI trong bảng lương, KPI tính theo % đạt</template>
                </p>
            </div>

            <p v-if="periodMonths > 1 || grades.length" class="font-body-small text-body-small text-on-surface-variant">
                {{ periodMonths > 1 ? 'Phiếu theo quý, duyệt từ ngày cuối quý.' : 'Phiếu theo tháng, duyệt từ ngày cuối tháng.' }}
                <template v-if="grades.length">Xếp loại theo tổng % đạt: {{ gradeText }}.</template>
            </p>

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
                            <th>Cách tính</th>
                            <th>Trạng thái</th>
                            <th v-if="canManage" class="text-right"><span class="sr-only">Thao tác</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="group in criteriaGroups" :key="group.name">
                            <tr class="bg-surface-container-low">
                                <td :colspan="hasFund ? 6 : 5" class="font-body-semibold text-body-semibold text-on-surface">
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
                                <td class="whitespace-nowrap text-right font-mono">{{ cr.knockout ? 'Loại trừ' : cr.weight + '%' }}</td>
                                <td v-if="hasFund" class="whitespace-nowrap text-right font-mono font-semibold text-primary">{{ money(cr.fund_amount) }} đ</td>
                                <td class="min-w-[200px] max-w-xs font-body-small text-body-small">
                                    {{ cr.rule_label || '—' }}
                                    <p v-if="cr.per_month && periodMonths > 1" class="font-caption text-caption text-on-surface-variant">Tính từng tháng, lấy trung bình quý</p>
                                    <p v-if="cr.allow_na" class="font-caption text-caption text-on-surface-variant">Tháng không phát sinh thì bỏ khỏi tổng</p>
                                </td>
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
                        :suffix="hasFund ? '% quỹ' : 'điểm'"
                        required
                        step="0.25"
                        min="0"
                        max="100"
                        :hint="weightMoney !== null ? `= ${money(weightMoney)} đ / tháng` : modal.knockout ? 'Điều kiện loại trừ để 0' : null"
                    />
                    <UiSelect v-model="modal.measure" name="measure" label="Cách đo" :options="measureOptions" :searchable="false" />
                    <UiSelect v-if="!isRate" name="unit" label="Đơn vị đếm" :options="unitOptions" :value="modal.item?.unit ?? null" placeholder="-- Chọn đơn vị --" required :searchable="false" />
                </div>

                <input type="hidden" name="knockout" :value="!isRate && modal.knockout ? 1 : 0" />
                <input type="hidden" name="linear" :value="isRate && modal.linear ? 1 : 0" />
                <input type="hidden" name="per_month" :value="modal.per_month ? 1 : 0" />
                <input type="hidden" name="allow_na" :value="modal.allow_na ? 1 : 0" />
                <UiCheckbox v-if="!isRate" v-model="modal.knockout" label="Điều kiện loại trừ: có từ 1 lần là mất toàn bộ KPI kỳ" />
                <UiCheckbox v-if="isRateUp" v-model="modal.linear" label="Tính điểm thẳng theo tỉ lệ" />
                <UiInput v-if="isRateUp && modal.linear" v-model="modal.full_at" type="number" name="full_at" label="Đủ điểm khi đạt" suffix="%" min="0.01" max="1000" step="any" hint="vd 100%: đạt 80% được 80% điểm; 10%: tăng 5% được một nửa điểm" />

                <fieldset v-if="!modal.knockout && !(isRateUp && modal.linear)" class="space-y-sm rounded-lg border border-outline-variant p-md">
                    <legend class="px-xs font-body-semibold text-body-semibold text-on-surface">Bậc tính điểm</legend>
                    <p class="font-caption text-caption text-on-surface-variant">
                        {{ isRateUp ? 'Từ ngưỡng % trở lên thì đạt số % điểm tương ứng; dưới bậc thấp nhất là 0%.' : isRate ? 'Không quá tỉ lệ % thì đạt số % điểm tương ứng; vượt bậc cuối là 0%.' : 'Không quá số lần thì đạt số % điểm tương ứng; vượt bậc cuối là 0%.' }}
                    </p>
                    <div v-for="(tier, i) in modal.tiers" :key="i" class="flex items-end gap-sm">
                        <div class="flex-1">
                            <UiInput v-model="tier.at" type="number" :name="`tiers[${i}][at]`" :label="i === 0 ? tierLabel : null" :aria-label="tierLabel" min="0" step="any" />
                        </div>
                        <div class="flex-1">
                            <UiInput v-model="tier.percent" type="number" :name="`tiers[${i}][percent]`" :label="i === 0 ? 'Đạt (% điểm)' : null" aria-label="Đạt (% điểm)" min="0" max="100" step="any" />
                        </div>
                        <UiButton variant="ghost" size="sm" icon="delete" :disabled="modal.tiers.length === 1" title="Bỏ bậc" aria-label="Bỏ bậc" @click="modal.tiers.splice(i, 1)" />
                    </div>
                    <UiButton v-if="modal.tiers.length < 8" variant="secondary" size="sm" icon="add" @click="modal.tiers.push({ at: '', percent: '' })">Thêm bậc</UiButton>
                </fieldset>
                <UiCheckbox v-if="periodMonths > 1 && !isRate && !modal.knockout" v-model="modal.per_month" label="Tính từng tháng rồi lấy trung bình quý (số liệu tự động)" />
                <UiCheckbox v-if="!modal.knockout" v-model="modal.allow_na" label="Cho phép &quot;Không phát sinh&quot;: kỳ không có việc để đánh giá thì bỏ mục khỏi tổng KPI" />
                <UiSelect name="auto_source" label="Số liệu" :options="sourceOptions" :value="modal.item?.auto_source ?? ''" :searchable="false" hint="Tự động: hệ thống tự đếm, người chấm không điền. Điền tay: người chấm điền số trên phiếu KPI tháng." />
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
