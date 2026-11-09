<script setup>
/**
 * Cấu hình Bảng bậc KPI giữ học sinh (lũy tiến):
 * - Danh sách nhóm thâm niên (min_seniority_months), nhóm đầu tiên cố định từ 0 tháng và không thể xóa.
 * - Mỗi nhóm gồm các bậc: Từ HS (tự động nối tiếp = Đến HS trước + 1), Đến HS (để trống ở bậc cuối = không giới hạn), Đơn giá (đ/HS).
 */
import { computed, reactive, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { route } from '@/lib/route';

defineOptions({ layout: { title: 'Cấu hình Bậc KPI Giữ Học Sinh' } });

const props = defineProps({
    bands: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();
const serverErrors = computed(() => page.props.errors ?? {});
const clientError = ref('');

const bandsState = reactive(
    (props.bands?.length ? props.bands : [
        {
            min_seniority_months: 0,
            tiers: [
                { from_students: 1, to_students: 5, rate: 25000 },
                { from_students: 6, to_students: 10, rate: 30000 },
                { from_students: 11, to_students: null, rate: 35000 },
            ],
        },
    ]).map((b) => ({
        min_seniority_months: Number(b.min_seniority_months ?? 0),
        tiers: (b.tiers?.length ? b.tiers : [{ from_students: 1, to_students: null, rate: 25000 }]).map((t) => ({
            from_students: Number(t.from_students ?? 1),
            to_students: t.to_students === null || t.to_students === '' || t.to_students === undefined ? null : Number(t.to_students),
            rate: Number(t.rate ?? 0),
        })),
    }))
);

function syncTierBounds(band) {
    let nextFrom = 1;
    for (let i = 0; i < band.tiers.length; i++) {
        const tier = band.tiers[i];
        tier.from_students = nextFrom;
        if (tier.to_students !== null && tier.to_students !== '' && tier.to_students !== undefined && !isNaN(Number(tier.to_students))) {
            nextFrom = Number(tier.to_students) + 1;
        } else {
            nextFrom = tier.from_students + 1;
        }
    }
}

function onToChange(band, tIdx, val) {
    band.tiers[tIdx].to_students = val === '' || val === null ? null : Number(val);
    syncTierBounds(band);
}

// Đồng bộ ban đầu
bandsState.forEach(syncTierBounds);

function addTier(band) {
    clientError.value = '';
    const lastTier = band.tiers[band.tiers.length - 1];
    if (lastTier) {
        if (lastTier.to_students === null || lastTier.to_students === '' || lastTier.to_students === undefined) {
            lastTier.to_students = Number(lastTier.from_students) + 4;
        }
    }
    const from = lastTier ? Number(lastTier.to_students) + 1 : 1;
    band.tiers.push({
        from_students: from,
        to_students: null,
        rate: lastTier ? Number(lastTier.rate) : 25000,
    });
    syncTierBounds(band);
}

function removeTier(band, index) {
    clientError.value = '';
    if (band.tiers.length <= 1) return;
    band.tiers.splice(index, 1);
    syncTierBounds(band);
}

function addBand() {
    clientError.value = '';
    const maxMonths = bandsState.reduce((max, b) => Math.max(max, Number(b.min_seniority_months || 0)), 0);
    const newBand = {
        min_seniority_months: maxMonths + 6,
        tiers: [
            { from_students: 1, to_students: 5, rate: 25000 },
            { from_students: 6, to_students: null, rate: 30000 },
        ],
    };
    bandsState.push(newBand);
    syncTierBounds(newBand);
}

function removeBand(index) {
    clientError.value = '';
    if (index === 0) return;
    bandsState.splice(index, 1);
}

function validateBeforeSubmit() {
    clientError.value = '';
    if (Number(bandsState[0]?.min_seniority_months) !== 0) {
        clientError.value = 'Nhóm thâm niên đầu tiên phải cố định từ 0 tháng.';
        return false;
    }
    const months = bandsState.map((b) => Number(b.min_seniority_months));
    if (new Set(months).size !== months.length) {
        clientError.value = 'Các mốc thâm niên (tháng) không được trùng lặp.';
        return false;
    }
    for (let b = 0; b < bandsState.length; b++) {
        const band = bandsState[b];
        syncTierBounds(band);
        if (band.min_seniority_months < 0) {
            clientError.value = 'Thâm niên không được là số âm.';
            return false;
        }
        for (let t = 0; t < band.tiers.length; t++) {
            const tier = band.tiers[t];
            const isLast = t === band.tiers.length - 1;
            if (!isLast && (tier.to_students === null || tier.to_students === '' || tier.to_students === undefined)) {
                clientError.value = `Nhóm thâm niên ${band.min_seniority_months} tháng: chỉ bậc cuối cùng mới được để trống "Đến HS".`;
                return false;
            }
            if (tier.to_students !== null && tier.to_students !== '' && Number(tier.to_students) < Number(tier.from_students)) {
                clientError.value = `Nhóm thâm niên ${band.min_seniority_months} tháng, bậc ${t + 1}: "Đến HS" (${tier.to_students}) phải lớn hơn hoặc bằng "Từ HS" (${tier.from_students}).`;
                return false;
            }
            if (tier.rate === null || tier.rate === '' || Number(tier.rate) < 0) {
                clientError.value = `Nhóm thâm niên ${band.min_seniority_months} tháng, bậc ${t + 1}: Đơn giá không được để trống hoặc âm.`;
                return false;
            }
        }
    }
    return true;
}

function submit() {
    if (!validateBeforeSubmit()) {
        return;
    }
    const payload = {
        bands: bandsState.map((b, bIdx) => ({
            min_seniority_months: bIdx === 0 ? 0 : Number(b.min_seniority_months),
            tiers: b.tiers.map((t) => ({
                from_students: Number(t.from_students),
                to_students: t.to_students === null || t.to_students === '' || t.to_students === undefined
                    ? null
                    : Number(t.to_students),
                rate: Number(t.rate),
            })),
        })),
    };
    router.post(route('payroll.config.kpi-tiers.store'), payload, {
        preserveScroll: true,
    });
}
</script>

<template>
    <div>
        <UiPageHeader
            title="Cấu hình Bậc KPI Giữ Học Sinh"
            icon="military_tech"
            :back="route('payroll.config.settings')"
            description="Bảng bậc KPI lũy tiến theo số học sinh duy trì và thâm niên giáo viên."
        />

        <div class="max-w-3xl space-y-5">
            <UiAlert type="info">
                <div class="space-y-1 text-xs leading-relaxed text-on-surface-variant">
                    <p>
                        <strong>KPI lũy tiến:</strong> mỗi HS tính theo đơn giá của bậc mình; ví dụ 8 HS với bậc 1–5 = 25.000đ, 6–10 = 30.000đ → 5 × 25.000 + 3 × 30.000 = 215.000đ.
                    </p>
                    <p>
                        Bảng áp theo <strong>thâm niên</strong> từ ngày bắt đầu hợp đồng của giáo viên. Thay đổi chỉ áp dụng cho các lần tính / tính lại kỳ lương về sau.
                    </p>
                </div>
            </UiAlert>

            <UiAlert v-if="clientError" type="error" dismissible @close="clientError = ''">
                {{ clientError }}
            </UiAlert>

            <UiAlert v-if="serverErrors.bands" type="error">
                {{ serverErrors.bands }}
            </UiAlert>

            <div class="divide-y divide-surface-container-highest rounded-2xl border border-surface-container-highest bg-surface-container-lowest shadow-sm">
                <div v-for="(band, bIdx) in bandsState" :key="bIdx" class="space-y-4 p-6">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="flex items-center gap-3">
                            <span class="flex h-8 w-8 items-center justify-center rounded-full bg-primary/10 text-primary font-bold text-sm">
                                {{ bIdx + 1 }}
                            </span>
                            <div>
                                <label :for="`seniority-${bIdx}`" class="text-xs font-bold text-on-surface-variant">
                                    Thâm niên từ (tháng)
                                </label>
                                <p class="text-xs text-on-surface-variant">
                                    {{ bIdx === 0 ? 'Nhóm khởi đầu (cố định 0 tháng, áp dụng cho nhân sự mới)' : 'Áp dụng cho nhân sự có thâm niên từ số tháng này' }}
                                </p>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <input
                                :id="`seniority-${bIdx}`"
                                v-model.number="band.min_seniority_months"
                                type="number"
                                min="0"
                                :disabled="bIdx === 0"
                                :class="[
                                    'w-24 rounded-lg border px-2.5 py-1.5 font-mono text-xs focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary',
                                    bIdx === 0
                                        ? 'border-outline-variant/40 bg-surface-container text-on-surface-variant cursor-not-allowed'
                                        : 'border-surface-container-highest bg-surface-container-lowest text-on-surface'
                                ]"
                                :aria-label="`Thâm niên từ (tháng), nhóm ${bIdx + 1}`"
                            />
                            <span class="text-xs text-on-surface-variant">tháng</span>
                            <UiButton
                                v-if="bIdx > 0"
                                type="button"
                                variant="danger-text"
                                size="sm"
                                icon="delete"
                                @click="removeBand(bIdx)"
                            >
                                Xóa nhóm
                            </UiButton>
                        </div>
                    </div>

                    <p v-if="serverErrors[`bands.${bIdx}.min_seniority_months`]" class="text-xs text-error">
                        {{ serverErrors[`bands.${bIdx}.min_seniority_months`] }}
                    </p>

                    <div class="overflow-x-auto rounded-xl border border-surface-container">
                        <table class="w-full text-left text-xs">
                            <thead>
                                <tr class="border-b border-surface-container bg-surface-container-low font-semibold text-on-surface-variant">
                                    <th class="py-2 px-3 w-14 text-center">Bậc</th>
                                    <th class="py-2 px-3 w-28">Từ HS</th>
                                    <th class="py-2 px-3 w-36">Đến HS</th>
                                    <th class="py-2 px-3">Đơn giá (đ/HS)</th>
                                    <th class="py-2 px-3 text-right w-20"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-surface-container">
                                <tr v-for="(tier, tIdx) in band.tiers" :key="tIdx" class="hover:bg-surface-container-lowest/50">
                                    <td class="py-2 px-3 text-center font-mono font-medium text-on-surface-variant">
                                        {{ tIdx + 1 }}
                                    </td>
                                    <td class="py-2 px-3">
                                        <input
                                            :value="tier.from_students"
                                            type="number"
                                            readonly
                                            disabled
                                            :aria-label="`Từ HS, bậc ${tIdx + 1}, nhóm thâm niên ${bIdx + 1}`"
                                            class="w-20 rounded-lg border border-outline-variant/40 bg-surface-container px-2 py-1.5 font-mono text-xs text-on-surface-variant cursor-not-allowed"
                                        />
                                    </td>
                                    <td class="py-2 px-3">
                                        <input
                                            :value="tier.to_students ?? ''"
                                            type="number"
                                            :min="tier.from_students"
                                            :placeholder="tIdx === band.tiers.length - 1 ? 'Không giới hạn' : ''"
                                            :aria-label="`Đến HS, bậc ${tIdx + 1}, nhóm thâm niên ${bIdx + 1}`"
                                            class="w-28 rounded-lg border border-surface-container-highest px-2 py-1.5 font-mono text-xs focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                            @input="onToChange(band, tIdx, $event.target.value)"
                                        />
                                    </td>
                                    <td class="py-2 px-3">
                                        <div class="flex items-center gap-1.5">
                                            <input
                                                v-model.number="tier.rate"
                                                type="number"
                                                min="0"
                                                step="1000"
                                                :aria-label="`Đơn giá đ/HS, bậc ${tIdx + 1}, nhóm thâm niên ${bIdx + 1}`"
                                                class="w-32 rounded-lg border border-surface-container-highest px-2 py-1.5 font-mono text-xs focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                            />
                                            <span class="text-xs text-on-surface-variant">đ/HS</span>
                                        </div>
                                    </td>
                                    <td class="py-2 px-3 text-right">
                                        <UiButton
                                            v-if="band.tiers.length > 1"
                                            type="button"
                                            variant="danger-text"
                                            size="sm"
                                            @click="removeTier(band, tIdx)"
                                        >
                                            Xoá
                                        </UiButton>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="flex flex-wrap items-center justify-between gap-2 pt-1">
                        <UiButton
                            type="button"
                            variant="secondary"
                            size="sm"
                            icon="add"
                            @click="addTier(band)"
                        >
                            Thêm bậc
                        </UiButton>
                        <span class="text-xs text-on-surface-variant">
                            (Bậc cuối để trống Đến HS = không giới hạn)
                        </span>
                    </div>

                    <p v-if="serverErrors[`bands.${bIdx}.tiers`]" class="text-xs text-error">
                        {{ serverErrors[`bands.${bIdx}.tiers`] }}
                    </p>
                </div>

                <!-- Thêm nhóm thâm niên -->
                <div class="flex flex-col gap-2 p-6 sm:flex-row sm:items-center sm:justify-between bg-surface-container-low/40">
                    <div>
                        <h4 class="text-xs font-bold text-on-surface">Thêm nhóm thâm niên</h4>
                        <p class="text-xs text-on-surface-variant">Thêm bảng bậc riêng cho giáo viên có thâm niên hợp đồng cao hơn.</p>
                    </div>
                    <UiButton
                        type="button"
                        variant="secondary"
                        size="sm"
                        icon="add_circle"
                        @click="addBand"
                    >
                        + Thêm nhóm thâm niên
                    </UiButton>
                </div>

                <!-- Footer actions -->
                <div class="flex flex-col items-center justify-between gap-4 border-t border-surface-container-highest bg-surface-container-low p-6 sm:flex-row">
                    <div class="text-center text-xs text-on-surface-variant sm:text-left">
                        Áp dụng cho giáo viên Part-time và Full-time từ lần tính lương sau.
                    </div>
                    <UiButton
                        type="button"
                        icon="save"
                        class="w-full sm:w-auto"
                        @click="submit"
                    >
                        Lưu bảng bậc KPI
                    </UiButton>
                </div>
            </div>
        </div>
    </div>
</template>
