<script setup>
/**
 * KPI của tôi: phiếu KPI tháng của chính mình — tiền thưởng KPI (Học vụ), trạng thái phiếu (lý do nếu không duyệt),
 * số liệu từng tiêu chí: số hệ thống ghi nhận (cập nhật hằng ngày, xem được từng bản ghi) hoặc số người chấm điền.
 */
import { reactive } from 'vue';
import { router } from '@inertiajs/vue3';
import { route } from '@/lib/route';
import { money } from '../Payroll/format';

defineOptions({ layout: { title: 'KPI của tôi' } });

const props = defineProps({
    hasKpi: { type: Boolean, default: true },
    name: { type: String, required: true },
    roleLabel: { type: String, default: '' },
    period: { type: String, required: true },
    periodOptions: { type: Array, default: () => [] },
    status: { type: String, required: true },
    statusLabel: { type: String, required: true },
    statusColor: { type: String, default: 'neutral' },
    rejectReason: { type: String, default: null },
    decidedBy: { type: String, default: null },
    decidedAt: { type: String, default: null },
    fund: { type: Number, default: null },
    amount: { type: Number, default: null },
    rateLabel: { type: String, default: '0%' },
    groups: { type: Array, default: () => [] },
});

const openEvidence = reactive({});
const levelColor = (l) => (l === 100 ? 'success' : l === 50 ? 'warning' : 'error');
function changePeriod(event) {
    router.get(route('kpi.mine'), { period: event.target.value }, { preserveScroll: true });
}
</script>

<template>
    <div class="space-y-lg">
        <UiPageHeader title="KPI của tôi" :description="hasKpi ? `${name} · ${roleLabel}. Số liệu hệ thống ghi nhận cập nhật hằng ngày; tiêu chí điền tay có số khi người chấm điền cuối kỳ.` : null">
            <template v-if="hasKpi" #actions>
                <label for="kpi-mine-period" class="sr-only">Kỳ lương</label>
                <select id="kpi-mine-period" class="rounded-lg border border-outline-variant bg-surface-container-lowest py-sm pl-md pr-xl font-body-base text-body-base text-on-surface focus:border-primary-container focus:outline-none focus:ring-2 focus:ring-primary-container/50" :value="period" @change="changePeriod">
                    <option v-for="o in periodOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
                </select>
            </template>
        </UiPageHeader>

        <div v-if="!hasKpi" class="rounded-xl border border-outline-variant bg-surface-container-lowest shadow-sm">
            <UiEmptyState icon="tune" title="Bạn chưa có tiêu chí KPI" description="Vai trò của bạn chưa có bộ tiêu chí KPI." />
        </div>

        <template v-else>
            <div class="flex flex-wrap items-center gap-x-xl gap-y-md rounded-xl border border-outline-variant bg-surface-container-lowest p-md shadow-sm">
                <div>
                    <p class="font-label-caps text-label-caps uppercase text-on-surface-variant">{{ fund !== null ? 'Thưởng KPI' : 'Tỉ lệ đạt' }}</p>
                    <p class="tabular-nums text-h2 font-bold text-on-surface">{{ fund !== null ? money(amount) + ' đ' : rateLabel }}</p>
                    <p v-if="fund !== null" class="font-caption text-caption text-on-surface-variant">{{ rateLabel }} quỹ {{ money(fund) }} đ</p>
                </div>
                <div>
                    <p class="font-label-caps text-label-caps uppercase text-on-surface-variant">Phiếu</p>
                    <p class="mt-xs"><UiBadge :color="statusColor">{{ statusLabel }}</UiBadge></p>
                    <p v-if="status === 'confirmed' && decidedBy" class="font-caption text-caption text-on-surface-variant">Duyệt bởi {{ decidedBy }}<template v-if="decidedAt"> · {{ decidedAt }}</template></p>
                </div>
            </div>
            <UiAlert v-if="rejectReason" type="error">Phiếu không được duyệt: {{ rejectReason }}</UiAlert>

            <section v-for="g in groups" :key="g.name" class="rounded-xl border border-outline-variant bg-surface-container-lowest shadow-sm">
                <h2 class="border-b border-surface-container px-md py-sm font-body-semibold text-body-semibold text-on-surface">{{ g.name }}</h2>
                <div v-for="c in g.items" :key="c.id" class="grid grid-cols-[minmax(0,1fr)_auto] gap-x-md border-b border-surface-container px-md py-sm last:border-b-0">
                    <div class="min-w-0">
                        <p class="font-medium text-on-surface">{{ c.name }}</p>
                        <p class="font-caption text-caption text-on-surface-variant">
                            <template v-if="c.value === null">Chưa có số liệu</template>
                            <template v-else><span class="font-semibold tabular-nums text-on-surface">{{ c.value }}</span> {{ c.unit }}</template>
                            · đạt đủ khi {{ c.threshold_full }}, một nửa khi {{ c.threshold_half }}
                        </p>
                        <template v-if="c.evidence.length">
                            <button type="button" class="mt-xs font-caption text-caption font-medium text-primary hover:underline" @click="openEvidence[c.id] = !openEvidence[c.id]">
                                {{ openEvidence[c.id] ? 'Ẩn' : 'Xem' }} {{ c.evidence.length }} bản ghi
                            </button>
                            <ul v-if="openEvidence[c.id]" class="mt-xs space-y-0.5 rounded-lg bg-surface-container-low px-sm py-xs font-caption text-caption text-on-surface-variant">
                                <li v-for="(e, i) in c.evidence" :key="i" :class="e.counted ? '' : 'line-through opacity-70'">{{ e.date }} · {{ e.text }}<template v-if="e.note"> ({{ e.note }})</template></li>
                            </ul>
                        </template>
                    </div>
                    <div class="text-right">
                        <UiBadge v-if="c.level !== null" :color="levelColor(c.level)" :dot="false">{{ c.level }}%</UiBadge>
                        <span v-else class="text-on-surface-variant">—</span>
                        <p v-if="c.amount !== null" class="tabular-nums font-caption text-caption text-on-surface-variant">{{ money(c.amount) }} đ</p>
                    </div>
                </div>
            </section>
        </template>
    </div>
</template>
