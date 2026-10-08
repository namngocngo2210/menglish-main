<script setup>
/**
 * Phiếu KPI của một nhân sự (mở trong modal từ Phiếu KPI tháng; phiếu quý với vai trò chấm theo quý): tiêu chí theo vai trò,
 * cột Số liệu là số hệ thống ghi nhận hoặc ô trống để người chấm điền; mức đạt (theo bậc / tỉ lệ), tiền thưởng (Học vụ) và
 * xếp loại A–E (GV part-time, Học thuật, phiếu quý) tính ngay khi điền. Có điều kiện loại trừ (nghỉ không phép) → tổng 0.
 * Tiêu chí cho phép "Không phát sinh": đánh dấu thì bỏ khỏi cả tử số và mẫu số. Điều kiện chặn (gradeCap) giới hạn hạng.
 * Duyệt (cần đủ số liệu, từ ngày cuối kỳ) → chốt phiếu; Không duyệt → bắt buộc ghi lý do. Không tự chấm phiếu của mình.
 */
import { computed, nextTick, reactive, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { route } from '@/lib/route';
import { money } from '../Payroll/format';

defineOptions({ layout: { title: 'Phiếu KPI tháng' } });

const props = defineProps({
    staff: { type: Object, required: true },
    month: { type: Number, required: true },
    year: { type: Number, required: true },
    periodMonths: { type: Number, default: 1 },
    periodLabel: { type: String, required: true },
    status: { type: String, required: true },
    statusLabel: { type: String, required: true },
    statusColor: { type: String, default: 'neutral' },
    rejectReason: { type: String, default: null },
    decidedBy: { type: String, default: null },
    decidedAt: { type: String, default: null },
    fund: { type: Number, default: null },
    weightTotal: { type: Number, default: 0 },
    grades: { type: Array, default: () => [] },
    simpleRules: { type: Boolean, default: true },
    bonusFund: { type: Number, default: null },
    gradeCap: { type: String, default: null },
    isSelf: { type: Boolean, default: false },
    locked: { type: Boolean, default: false },
    canClose: { type: Boolean, default: true },
    closeOn: { type: String, default: '' },
    canDecide: { type: Boolean, default: false },
    groups: { type: Array, default: () => [] },
    backUrl: { type: String, default: null },
    asModal: { type: Boolean, default: false },
});

const page = usePage();
const serverError = computed(() => Object.values(page.props.errors ?? {})[0] ?? null);
const hasFund = computed(() => props.fund !== null);
const allItems = computed(() => props.groups.flatMap((g) => g.items));

// Số điền tay theo tiêu chí (chuỗi trong ô); tiêu chí tự động lấy số hệ thống.
const values = reactive(Object.fromEntries(allItems.value.filter((c) => !c.auto).map((c) => [c.id, c.value === null ? '' : String(c.value).replace('.', ',')])));
// "Không phát sinh" của tiêu chí cho phép (bỏ khỏi tử số và mẫu số).
const na = reactive(Object.fromEntries(allItems.value.filter((c) => c.allow_na).map((c) => [c.id, !!c.na])));
const isNa = (c) => (c.allow_na ? !!na[c.id] : !!c.na);
const valueOf = (c) => {
    if (isNa(c)) return null;
    if (c.auto) return c.value;
    const raw = String(values[c.id] ?? '').trim();
    if (c.measure === 'rate') return /^\d+([.,]\d+)?$/.test(raw) ? Number(raw.replace(',', '.')) : null;
    return /^\d+$/.test(raw) ? Number(raw) : null;
};
// Cùng cách tính với KpiCriterion::levelFor: đếm số lần → bậc đầu tiên có số ≤ ngưỡng; tỉ lệ → bậc đầu tiên có tỉ lệ ≥ ngưỡng,
// hoặc thẳng theo tỉ lệ; vượt bậc cuối = 0%.
const ruleLevel = (rule, n) => {
    if (rule.measure === 'rate' && rule.linear) return Math.round(Math.max(0, Math.min(100, (n / rule.full_at) * 100)) * 100) / 100;
    const lower = rule.lower_better ?? rule.measure !== 'rate';
    for (const [at, pct] of rule.tiers) if (lower ? n <= at : n >= at) return pct;
    return 0;
};
const levelOf = (c) => {
    if (isNa(c)) return null;
    // Số hệ thống (gồm phiếu quý tính từng tháng rồi lấy trung bình) và phiếu đã duyệt: dùng mức đạt máy chủ đã tính.
    if (!c.count_based || c.auto || props.status === 'confirmed') return c.level;
    const n = valueOf(c);
    return n === null ? null : ruleLevel(c.rule, n);
};
const amountOf = (c) => (c.max_amount === null || levelOf(c) === null ? null : Math.round((c.max_amount * levelOf(c)) / 100));
const missing = computed(() => allItems.value.filter((c) => levelOf(c) === null && !isNa(c)).length);
const knockoutItem = computed(() => allItems.value.find((c) => c.knockout && (valueOf(c) ?? 0) > 0) ?? null);
// Tổng = Σ(mức đạt × trọng số) ÷ Σ trọng số các mục áp dụng (bỏ mục Không phát sinh).
const applicableWeight = computed(() => allItems.value.filter((c) => !isNa(c)).reduce((s, c) => s + c.weight, 0));
const total = computed(() => {
    if (knockoutItem.value || applicableWeight.value <= 0) return 0;
    return allItems.value.filter((c) => !isNa(c)).reduce((s, c) => s + (levelOf(c) ?? 0) * c.weight, 0) / applicableWeight.value;
});
const fmt = (n) => String(Math.round(n * 100) / 100).replace('.', ',');
const totalLabel = computed(() => fmt(total.value));
const totalMoney = computed(() => (hasFund.value ? Math.round((props.fund * total.value) / 100) : null));
const capIndex = computed(() => (props.gradeCap ? props.grades.findIndex((g) => g.grade === props.gradeCap) : 0));
const grade = computed(() => props.grades.find((g, i) => i >= capIndex.value && total.value >= g.from) ?? null);
const bonus = computed(() => (props.bonusFund && grade.value ? Math.round((props.bonusFund * grade.value.pay) / 100) : null));
const valueLabel = (c) => (isNa(c) ? 'Không phát sinh' : c.value === null ? '—' : c.measure === 'rate' ? `${fmt(c.value)}%` : String(c.value));
const levelColor = (l) => (l >= 100 ? 'success' : l > 0 ? 'warning' : 'error');

const openEvidence = reactive({});
const decision = ref('approve');
const rejecting = ref(false);
const localError = ref(null);
const reasonBox = ref(null);
const transform = (data) => ({ ...data, action: decision.value });

function onApprove(event) {
    decision.value = 'approve';
    rejecting.value = false;
    localError.value = null;
    if (missing.value > 0) {
        event.preventDefault();
        localError.value = `Còn ${missing.value} tiêu chí chưa có số liệu.`;
    }
}
async function onReject(event) {
    if (!rejecting.value) {
        event.preventDefault();
        rejecting.value = true;
        await nextTick();
        reasonBox.value?.querySelector('textarea')?.focus();
        return;
    }
    decision.value = 'reject';
}
const notice = computed(() => {
    if (props.isSelf) return 'Không tự chấm KPI của chính mình.';
    if (props.locked) return 'Kỳ lương tháng này đã duyệt, phiếu đã khóa.';
    if (props.canDecide && !props.canClose) return `Duyệt từ ngày cuối ${props.periodMonths > 1 ? 'quý' : 'tháng'} ${props.closeOn}. Trước đó có thể điền số liệu và Không duyệt.`;
    return null;
});
</script>

<template>
    <UiModalFrame
        :title="staff.name"
        :description="[staff.role_label, staff.branch, periodLabel].filter(Boolean).join(' · ')"
        :action="canDecide ? route('kpi.evaluate.store', staff.id) : null"
        method="post"
        :submit-label="false"
        cancel="Đóng"
        :back="backUrl"
        size="4xl"
        page-width="max-w-5xl"
        :form-options="{ transform }"
    >
        <input type="hidden" name="month" :value="month" />
        <input type="hidden" name="year" :value="year" />

        <div class="flex flex-wrap items-center gap-sm">
            <UiBadge :color="statusColor">{{ statusLabel }}</UiBadge>
            <span v-if="status === 'confirmed' && decidedBy" class="font-body-small text-body-small text-on-surface-variant">Duyệt bởi {{ decidedBy }}<template v-if="decidedAt"> lúc {{ decidedAt }}</template>. Số tiền đã vào bảng lương kỳ này.</span>
        </div>
        <UiAlert v-if="rejectReason" type="error">Không duyệt<template v-if="decidedBy"> ({{ decidedBy }})</template>: {{ rejectReason }}</UiAlert>
        <UiAlert v-if="notice" type="info">{{ notice }}</UiAlert>
        <UiAlert v-if="localError || serverError" type="error">{{ localError || serverError }}</UiAlert>
        <UiAlert v-if="knockoutItem" type="warning">Có {{ knockoutItem.name.toLowerCase() }} trong kỳ: mất toàn bộ KPI kỳ này (tổng 0%).</UiAlert>

        <UiEmptyState v-if="!groups.length" icon="tune" title="Vai trò này chưa có tiêu chí KPI" description="Thêm tiêu chí ở Cài đặt → Tiêu chí KPI." />

        <div v-else class="overflow-x-auto rounded-lg border border-outline-variant">
            <table class="w-full min-w-[720px] border-collapse font-body-small text-body-small">
                <thead>
                    <tr class="bg-surface-container-low text-left font-label-caps text-label-caps uppercase text-on-surface-variant">
                        <th class="px-md py-sm">Tiêu chí</th>
                        <template v-if="simpleRules">
                            <th class="whitespace-nowrap px-sm py-sm">Đạt 100% khi</th>
                            <th class="whitespace-nowrap px-sm py-sm">Đạt 50% khi</th>
                        </template>
                        <th v-else class="px-sm py-sm">Cách tính</th>
                        <th class="px-sm py-sm text-right">Số liệu</th>
                        <th class="whitespace-nowrap px-sm py-sm">Mức đạt</th>
                        <th v-if="hasFund" class="px-md py-sm text-right">Thưởng</th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="g in groups" :key="g.name">
                        <tr class="border-t border-surface-container bg-surface-container-lowest first:border-t-0">
                            <td :colspan="(hasFund ? 6 : 5) - (simpleRules ? 0 : 1)" class="px-md pb-xs pt-md font-body-semibold text-body-semibold text-on-surface">{{ g.name }}</td>
                        </tr>
                        <tr v-for="c in g.items" :key="c.id" class="border-t border-surface-container align-top" :data-criterion="c.id">
                            <td class="px-md py-sm">
                                <p class="font-medium text-on-surface">{{ c.name }}</p>
                                <p class="font-caption text-caption text-on-surface-variant">
                                    <template v-if="c.knockout">Điều kiện loại trừ</template>
                                    <template v-else>{{ c.weight_label }}{{ hasFund ? '%' : ' điểm' }}<template v-if="c.max_amount !== null"> · tối đa {{ money(c.max_amount) }} đ</template></template>
                                    <template v-if="c.per_month && periodMonths > 1"> · tính từng tháng, lấy trung bình quý</template>
                                </p>
                                <p v-if="c.cap" class="font-caption text-caption font-medium text-error">Có order giao sau giờ dùng: mục tối đa 50%, tháng không xếp loại A</p>
                                <template v-if="c.auto && c.evidence.length">
                                    <button type="button" class="mt-xs font-caption text-caption font-medium text-primary hover:underline" @click="openEvidence[c.id] = !openEvidence[c.id]">
                                        {{ openEvidence[c.id] ? 'Ẩn' : 'Xem' }} {{ c.evidence.length }} bản ghi
                                    </button>
                                    <ul v-if="openEvidence[c.id]" class="mt-xs space-y-0.5 rounded-lg bg-surface-container-low px-sm py-xs font-caption text-caption text-on-surface-variant">
                                        <li v-for="(e, i) in c.evidence" :key="i" :class="e.counted ? '' : 'line-through opacity-70'">{{ e.date }} · {{ e.text }}<template v-if="e.note"> ({{ e.note }})</template></li>
                                    </ul>
                                </template>
                            </td>
                            <template v-if="simpleRules">
                                <td class="whitespace-nowrap px-sm py-sm">{{ c.threshold_full }}</td>
                                <td class="whitespace-nowrap px-sm py-sm">{{ c.threshold_half }}</td>
                            </template>
                            <td v-else class="min-w-[180px] max-w-[240px] px-sm py-sm font-caption text-caption text-on-surface-variant">{{ c.rule_label }}</td>
                            <td class="px-sm py-sm text-right">
                                <span v-if="c.auto || !c.count_based || isNa(c)" :class="['inline-block pr-sm tabular-nums', isNa(c) ? 'font-caption text-caption text-on-surface-variant' : 'w-20 font-semibold text-on-surface']">{{ valueLabel(c) }}</span>
                                <input
                                    v-else
                                    v-model="values[c.id]"
                                    :type="c.measure === 'rate' ? 'text' : 'number'"
                                    min="0"
                                    max="9999"
                                    step="1"
                                    :inputmode="c.measure === 'rate' ? 'decimal' : 'numeric'"
                                    :placeholder="c.measure === 'rate' ? '%' : null"
                                    :name="`actual[${c.id}]`"
                                    :disabled="!canDecide"
                                    :aria-label="`Số liệu: ${c.name}`"
                                    :class="[
                                        'w-20 rounded-lg border bg-surface-container-lowest px-sm py-xs text-right tabular-nums [appearance:textfield] focus:border-primary-container focus:outline-none focus:ring-2 focus:ring-primary-container/50 disabled:bg-surface-container-low [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none',
                                        valueOf(c) === null && canDecide ? 'border-dashed border-primary-container bg-primary-fixed/30' : 'border-outline-variant',
                                    ]"
                                />
                                <template v-if="c.allow_na">
                                    <input type="hidden" :name="`na[${c.id}]`" :value="na[c.id] ? 1 : 0" />
                                    <label class="mt-xs flex items-center justify-end gap-xs font-caption text-caption text-on-surface-variant">
                                        <input v-model="na[c.id]" type="checkbox" :disabled="!canDecide" class="rounded border-outline-variant text-primary focus:ring-primary-container" />
                                        Không phát sinh
                                    </label>
                                </template>
                            </td>
                            <td class="px-sm py-sm">
                                <UiBadge v-if="levelOf(c) !== null" :color="levelColor(levelOf(c))" :dot="false">{{ fmt(levelOf(c)) }}%</UiBadge>
                                <span v-else class="text-on-surface-variant">—</span>
                            </td>
                            <td v-if="hasFund" class="whitespace-nowrap px-md py-sm text-right tabular-nums">{{ amountOf(c) === null ? '—' : money(amountOf(c)) + ' đ' }}</td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <div v-if="rejecting" ref="reasonBox" class="rounded-xl border border-error/30 bg-error-container/40 p-md">
            <UiTextarea name="reject_reason" label="Lý do không duyệt (bắt buộc)" :rows="3" placeholder="vd: Thiếu số liệu feedback Big Test lớp FLY-2409, điền lại giúp" hint="Người được chấm thấy lý do này ở trang KPI của tôi." />
        </div>

        <template #footer>
            <div class="mr-auto flex flex-col justify-center">
                <span class="font-caption text-caption text-on-surface-variant">{{ hasFund ? `Tổng thưởng KPI · ${totalLabel}%` : 'Tổng KPI' }}<template v-if="missing"> · còn {{ missing }} tiêu chí chưa có số liệu</template></span>
                <span class="tabular-nums text-body-semibold font-semibold text-on-surface">
                    {{ totalMoney !== null ? money(totalMoney) + ' đ' : totalLabel + '%' }}
                    <template v-if="grade"> · Loại {{ grade.grade }} ({{ grade.label }}, hệ số {{ grade.pay }}%)</template>
                    <template v-if="bonus !== null"> · thưởng {{ money(bonus) }} đ</template>
                </span>
            </div>
            <template v-if="canDecide">
                <UiButton type="submit" variant="danger" icon="close" @click="onReject">{{ rejecting ? 'Xác nhận không duyệt' : 'Không duyệt' }}</UiButton>
                <UiButton type="submit" icon="check" :disabled="!canClose" @click="onApprove">Duyệt</UiButton>
            </template>
        </template>
    </UiModalFrame>
</template>
