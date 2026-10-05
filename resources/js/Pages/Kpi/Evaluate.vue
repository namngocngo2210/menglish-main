<script setup>
/**
 * Phiếu KPI tháng của một nhân sự (mở trong modal từ Phiếu KPI tháng): tiêu chí theo vai trò, cột Số liệu là số hệ thống
 * ghi nhận hoặc ô trống để người chấm điền; mức đạt 100 / 50 / 0% và tiền thưởng tính ngay khi điền.
 * Duyệt (cần đủ số liệu, từ ngày cuối tháng) → vào bảng lương; Không duyệt → bắt buộc ghi lý do. Không tự chấm phiếu của mình.
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
    periodLabel: { type: String, required: true },
    status: { type: String, required: true },
    statusLabel: { type: String, required: true },
    statusColor: { type: String, default: 'neutral' },
    rejectReason: { type: String, default: null },
    decidedBy: { type: String, default: null },
    decidedAt: { type: String, default: null },
    fund: { type: Number, default: null },
    weightTotal: { type: Number, default: 0 },
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
const values = reactive(Object.fromEntries(allItems.value.filter((c) => !c.auto).map((c) => [c.id, c.value === null ? '' : String(c.value)])));
const valueOf = (c) => {
    if (c.auto) return c.value;
    const raw = String(values[c.id] ?? '').trim();
    return /^\d+$/.test(raw) ? Number(raw) : null;
};
const levelOf = (c) => {
    if (!c.count_based) return c.level;
    const n = valueOf(c);
    if (n === null) return null;
    return n <= c.max_full ? 100 : n <= c.max_half ? 50 : 0;
};
const amountOf = (c) => (c.max_amount === null || levelOf(c) === null ? null : Math.round((c.max_amount * levelOf(c)) / 100));
const missing = computed(() => allItems.value.filter((c) => levelOf(c) === null).length);
const total = computed(() => (props.weightTotal > 0 ? allItems.value.reduce((s, c) => s + (levelOf(c) ?? 0) * c.weight, 0) / props.weightTotal : 0));
const totalLabel = computed(() => String(Math.round(total.value * 100) / 100).replace('.', ','));
const totalMoney = computed(() => (hasFund.value ? Math.round((props.fund * total.value) / 100) : null));
const levelColor = (l) => (l === 100 ? 'success' : l === 50 ? 'warning' : 'error');

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
    if (props.canDecide && !props.canClose) return `Duyệt từ ngày cuối tháng ${props.closeOn}. Trước đó có thể điền số liệu và Không duyệt.`;
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

        <UiEmptyState v-if="!groups.length" icon="tune" title="Vai trò này chưa có tiêu chí KPI" description="Thêm tiêu chí ở Cài đặt → Tiêu chí KPI." />

        <div v-else class="overflow-x-auto rounded-lg border border-outline-variant">
            <table class="w-full min-w-[720px] border-collapse font-body-small text-body-small">
                <thead>
                    <tr class="bg-surface-container-low text-left font-label-caps text-label-caps uppercase text-on-surface-variant">
                        <th class="px-md py-sm">Tiêu chí</th>
                        <th class="whitespace-nowrap px-sm py-sm">Đạt 100% khi</th>
                        <th class="whitespace-nowrap px-sm py-sm">Đạt 50% khi</th>
                        <th class="px-sm py-sm text-right">Số liệu</th>
                        <th class="whitespace-nowrap px-sm py-sm">Mức đạt</th>
                        <th v-if="hasFund" class="px-md py-sm text-right">Thưởng</th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="g in groups" :key="g.name">
                        <tr class="border-t border-surface-container bg-surface-container-lowest first:border-t-0">
                            <td :colspan="hasFund ? 6 : 5" class="px-md pb-xs pt-md font-body-semibold text-body-semibold text-on-surface">{{ g.name }}</td>
                        </tr>
                        <tr v-for="c in g.items" :key="c.id" class="border-t border-surface-container align-top" :data-criterion="c.id">
                            <td class="px-md py-sm">
                                <p class="font-medium text-on-surface">{{ c.name }}</p>
                                <p class="font-caption text-caption text-on-surface-variant">{{ c.weight_label }}%<template v-if="c.max_amount !== null"> · tối đa {{ money(c.max_amount) }} đ</template></p>
                                <template v-if="c.auto && c.evidence.length">
                                    <button type="button" class="mt-xs font-caption text-caption font-medium text-primary hover:underline" @click="openEvidence[c.id] = !openEvidence[c.id]">
                                        {{ openEvidence[c.id] ? 'Ẩn' : 'Xem' }} {{ c.evidence.length }} bản ghi
                                    </button>
                                    <ul v-if="openEvidence[c.id]" class="mt-xs space-y-0.5 rounded-lg bg-surface-container-low px-sm py-xs font-caption text-caption text-on-surface-variant">
                                        <li v-for="(e, i) in c.evidence" :key="i" :class="e.counted ? '' : 'line-through opacity-70'">{{ e.date }} · {{ e.text }}<template v-if="e.note"> ({{ e.note }})</template></li>
                                    </ul>
                                </template>
                            </td>
                            <td class="whitespace-nowrap px-sm py-sm">{{ c.threshold_full }}</td>
                            <td class="whitespace-nowrap px-sm py-sm">{{ c.threshold_half }}</td>
                            <td class="px-sm py-sm text-right">
                                <span v-if="c.auto || !c.count_based" class="inline-block w-20 pr-sm font-semibold tabular-nums text-on-surface">{{ c.value ?? '—' }}</span>
                                <input
                                    v-else
                                    v-model="values[c.id]"
                                    type="number"
                                    min="0"
                                    max="9999"
                                    step="1"
                                    inputmode="numeric"
                                    :name="`actual[${c.id}]`"
                                    :disabled="!canDecide"
                                    :aria-label="`Số liệu: ${c.name}`"
                                    :class="[
                                        'w-20 rounded-lg border bg-surface-container-lowest px-sm py-xs text-right tabular-nums [appearance:textfield] focus:border-primary-container focus:outline-none focus:ring-2 focus:ring-primary-container/50 disabled:bg-surface-container-low [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none',
                                        valueOf(c) === null && canDecide ? 'border-dashed border-primary-container bg-primary-fixed/30' : 'border-outline-variant',
                                    ]"
                                />
                            </td>
                            <td class="px-sm py-sm">
                                <UiBadge v-if="levelOf(c) !== null" :color="levelColor(levelOf(c))" :dot="false">{{ levelOf(c) }}%</UiBadge>
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
                <span class="font-caption text-caption text-on-surface-variant">Tổng thưởng KPI · {{ totalLabel }}%<template v-if="missing"> · còn {{ missing }} tiêu chí chưa có số liệu</template></span>
                <span class="tabular-nums text-body-semibold font-semibold text-on-surface">{{ totalMoney !== null ? money(totalMoney) + ' đ' : totalLabel + '%' }}</span>
            </div>
            <template v-if="canDecide">
                <UiButton type="submit" variant="danger" icon="close" @click="onReject">{{ rejecting ? 'Xác nhận không duyệt' : 'Không duyệt' }}</UiButton>
                <UiButton type="submit" icon="check" :disabled="!canClose" @click="onApprove">Duyệt</UiButton>
            </template>
        </template>
    </UiModalFrame>
</template>
