<script setup>
/**
 * Phiếu KPI tháng của một nhân sự (roundcuoi 02/04_kpi_thang + 03_tong_hop_kpi_danh_gia_thang): bảng 6 nhóm / 15 mục
 * (Quỹ, Ngưỡng 100 / 50, Thực tế, % Đạt, Tiền KPI — tính ngay khi nhập, "Lỗi nghiêm trọng" đưa % đạt về 0),
 * tổng hợp theo nhóm, xếp loại tháng, cảnh báo hiệu suất, nhận xét của quản lý, "Lưu nháp" / "Chốt KPI tháng".
 * Đổi nhân sự / kỳ đánh giá → mở phiếu tương ứng. Không tự chấm KPI của chính mình.
 */
import { computed, reactive } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { route } from '@/lib/route';
import { money } from '../Payroll/format';

defineOptions({ layout: { title: 'KPI tháng' } });

const props = defineProps({
    staff: { type: Object, required: true },
    month: { type: Number, required: true },
    year: { type: Number, required: true },
    periodValue: { type: String, required: true },
    periodOptions: { type: Array, default: () => [] },
    staffOptions: { type: Array, default: () => [] },
    isSelf: { type: Boolean, default: false },
    canConfirm: { type: Boolean, default: false },
    isAcademicStaff: { type: Boolean, default: false },
    fund: { type: Number, default: 0 },
    evaluation: { type: Object, default: null },
    total: { type: Number, default: 0 },
    totalLabel: { type: String, default: '0' },
    grade: { type: Object, required: true },
    criteriaGroups: { type: Array, default: () => [] },
    groupSummary: { type: Array, default: () => [] },
    warnings: { type: Object, required: true },
});

const page = usePage();
const firstError = computed(() => Object.values(page.props.errors ?? {})[0] ?? null);

const items = reactive(
    Object.fromEntries(props.criteriaGroups.flatMap((g) => g.items).map((c) => [c.id, { fund: c.fund, score: c.score, critical: c.critical }])),
);
const pct = (it) => (it.critical ? 0 : Math.max(0, Math.min(100, parseFloat(it.score) || 0)));
const itemMoney = (it) => Math.round((it.fund * pct(it)) / 100);
const totalMoney = computed(() => Object.values(items).reduce((sum, it) => sum + itemMoney(it), 0));
const hasCriteria = computed(() => props.criteriaGroups.length > 0);
const sum = (key) => props.groupSummary.reduce((s, row) => s + Number(row[key] ?? 0), 0);

const selectedStaff = String(props.staff.id);
function navigate(event) {
    const form = event.target.form;
    if (!form) return;
    const staffId = form.querySelector('[name=staff]').value || selectedStaff;
    const period = form.querySelector('[name=period]').value;
    router.visit(route('kpi.evaluate', { userId: staffId, period }));
}
</script>

<template>
    <div>
        <UiPageHeader :title="`KPI tháng — ${staff.name}`" :description="`${staff.role_label} — Xem và cập nhật hiệu suất công việc hàng tháng.`">
            <template #breadcrumbs>
                <Link :href="route('kpi.monthly', { month, year })" class="hover:text-primary">Tổng hợp KPI & Đánh giá tháng</Link>
                <span aria-hidden="true">/</span><span>{{ staff.name }}</span>
            </template>
        </UiPageHeader>

        <form method="GET" class="mb-lg flex flex-wrap items-end gap-md rounded-xl border border-surface-container-highest bg-surface-container-lowest p-md shadow-sm" @submit.prevent>
            <UiSelect name="staff" label="Nhân sự" :options="staffOptions" :value="staff.id" @change="navigate" />
            <UiSelect name="period" label="Kỳ đánh giá" :options="periodOptions" :value="periodValue" @change="navigate" />
            <UiBadge v-if="evaluation" :color="evaluation.status === 'confirmed' ? 'success' : 'warning'">
                {{ evaluation.status === 'confirmed' ? 'Đã chốt KPI tháng' : 'Bản nháp — chưa chốt' }}
            </UiBadge>
            <UiBadge v-else color="neutral">Chưa đánh giá</UiBadge>
        </form>

        <UiAlert v-if="firstError" type="error" class="mb-md">{{ firstError }}</UiAlert>
        <UiAlert v-if="isSelf" type="warning" title="Không tự chấm KPI" class="mb-md">Bạn đang xem phiếu KPI của chính mình — việc chấm điểm do cấp quản lý thực hiện.</UiAlert>

        <UiEmptyState v-if="!hasCriteria" icon="tune" title="Chưa có chỉ số KPI" description="Vui lòng cấu hình chỉ số KPI (6 nhóm / 15 mục) trước.">
            <UiButton :href="route('kpi.criteria')" icon="tune">Cấu hình chỉ số</UiButton>
        </UiEmptyState>
        <UiForm v-else :action="route('kpi.evaluate.store', staff.id)" method="post" class="space-y-lg">
            <input type="hidden" name="month" :value="month" />
            <input type="hidden" name="year" :value="year" />

            <UiAlert type="info">
                <template v-if="isAcademicStaff">
                    KPI Học vụ tính lương tự động: <strong>quỹ {{ money(fund) }} đ × điểm KPI tổng</strong> (mục chưa chấm tính 0%). Chỉ phiếu <strong>đã chốt</strong> được dùng khi tính lương.
                </template>
                <template v-else>Điểm KPI tổng = Σ(% đạt × trọng số) / Σ trọng số các mục đang áp dụng.</template>
                Bật "Lỗi nghiêm trọng" tại một mục sẽ tự động đưa % đạt của mục đó về 0%.
            </UiAlert>

            <UiDataTable min-width="1000px">
                <table>
                    <thead>
                        <tr>
                            <th>Mã</th>
                            <th>Tiêu chí</th>
                            <th class="text-right">Quỹ (VNĐ)</th>
                            <th>Ngưỡng 100</th>
                            <th>Ngưỡng 50</th>
                            <th>Thực tế</th>
                            <th class="text-center">% Đạt</th>
                            <th class="text-right">Tiền KPI</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="(group, gi) in criteriaGroups" :key="group.name">
                            <tr class="bg-surface-container-low">
                                <td colspan="8" class="font-body-semibold text-body-semibold text-on-surface">{{ gi + 1 }}. {{ group.name }}</td>
                            </tr>
                            <tr v-for="cr in group.items" :key="cr.id">
                                <td class="font-code text-code">{{ cr.code || '—' }}</td>
                                <td>
                                    <p class="font-medium text-on-surface">{{ cr.name }}</p>
                                    <label class="mt-xs inline-flex items-center gap-xs font-caption text-caption text-error">
                                        <input v-model="items[cr.id].critical" type="checkbox" :name="`critical[${cr.id}]`" value="1" :disabled="!canConfirm" class="rounded border-outline-variant text-error focus:ring-error/30" />
                                        Lỗi nghiêm trọng
                                    </label>
                                    <p v-if="cr.description" class="font-caption text-caption text-on-surface-variant">{{ cr.description }}</p>
                                </td>
                                <td class="text-right"><UiMoney :value="cr.fund_exact" suffix="" /></td>
                                <td class="font-body-small text-body-small">{{ cr.threshold_full }}</td>
                                <td class="font-body-small text-body-small">{{ cr.threshold_half }}</td>
                                <td>
                                    <input type="text" :name="`actual[${cr.id}]`" :value="cr.actual" placeholder="VD: 95%" :aria-label="`Thực tế ${cr.code ?? ''}`" :disabled="!canConfirm" class="w-28 rounded-lg border border-outline-variant bg-surface-container-lowest px-sm py-xs font-body-small text-body-small" />
                                </td>
                                <td class="text-center">
                                    <span class="inline-flex items-center gap-xs">
                                        <input v-model="items[cr.id].score" type="number" :name="`score[${cr.id}]`" :disabled="items[cr.id].critical || !canConfirm" min="0" max="100" step="1" placeholder="0-100" :aria-label="`% đạt ${cr.code ?? ''}`" class="w-20 rounded-lg border border-outline-variant bg-surface-container-lowest px-sm py-xs text-center font-mono font-semibold" />%
                                    </span>
                                </td>
                                <td class="text-right font-mono font-semibold">{{ money(itemMoney(items[cr.id])) }}</td>
                            </tr>
                        </template>
                        <tr class="bg-primary-fixed/30">
                            <td colspan="7" class="text-right font-body-semibold text-body-semibold">Tổng tiền KPI dự tính:</td>
                            <td class="text-right font-mono font-bold text-primary"><span>{{ money(totalMoney) }}</span> đ</td>
                        </tr>
                    </tbody>
                </table>
            </UiDataTable>

            <div class="grid grid-cols-1 gap-lg lg:grid-cols-3">
                <!-- Chi tiết điểm KPI theo nhóm (mockup 03) -->
                <UiDataTable class="lg:col-span-2">
                    <template #header><h3 class="font-h3 text-h3 text-on-surface">Chi tiết điểm KPI theo nhóm</h3></template>
                    <table>
                        <thead><tr><th>Nhóm KPI</th><th class="text-center">Số tiêu chí</th><th class="text-right">Quỹ KPI (VNĐ)</th><th class="text-right">Tiền đạt (VNĐ)</th><th class="text-right">% Đạt</th></tr></thead>
                        <tbody>
                            <tr v-for="row in groupSummary" :key="row.name">
                                <td>{{ row.name }}</td>
                                <td class="text-center font-mono">{{ row.count }}</td>
                                <td class="text-right"><UiMoney :value="row.fund" suffix="" /></td>
                                <td class="text-right"><UiMoney :value="row.earned" suffix="" /></td>
                                <td class="text-right font-mono">{{ row.percent_label }}%</td>
                            </tr>
                            <tr class="font-semibold">
                                <td>Tổng cộng</td>
                                <td class="text-center font-mono">{{ sum('count') }}</td>
                                <td class="text-right"><UiMoney :value="sum('fund')" suffix="" /></td>
                                <td class="text-right"><UiMoney :value="sum('earned')" suffix="" /></td>
                                <td class="text-right font-mono">{{ totalLabel }}%</td>
                            </tr>
                        </tbody>
                    </table>
                    <template #footer><p class="p-sm font-caption text-caption text-on-surface-variant">Số liệu theo phiếu đã lưu{{ evaluation?.evaluator ? ` — người đánh giá: ${evaluation.evaluator}` : '' }}.</p></template>
                </UiDataTable>

                <div class="space-y-md">
                    <section class="rounded-xl border border-outline-variant bg-surface-container-lowest p-md">
                        <h3 class="font-h3 text-h3 text-on-surface">Xếp loại tháng</h3>
                        <template v-if="evaluation">
                            <div class="mt-sm flex items-center gap-md">
                                <span class="flex h-14 w-14 items-center justify-center rounded-full bg-primary-container font-h1 text-h1 text-white">{{ grade.letter }}</span>
                                <div>
                                    <p class="font-body-semibold text-body-semibold">{{ grade.label }}</p>
                                    <p class="font-body-small text-body-small text-on-surface-variant">Tổng KPI đạt: {{ totalLabel }}%</p>
                                </div>
                            </div>
                            <div class="mt-sm h-2 w-full overflow-hidden rounded-full bg-surface-container"><div class="h-2 rounded-full bg-primary-container" :style="{ width: `${Math.min(100, Math.max(0, total))}%` }"></div></div>
                            <p class="mt-xs flex justify-between font-caption text-caption text-on-surface-variant"><span>0%</span><span>Tiêu chuẩn {{ grade.label }}: {{ grade.range }}</span><span>100%</span></p>
                        </template>
                        <p v-else class="mt-sm font-body-small text-body-small text-on-surface-variant">Chưa đánh giá tháng này.</p>
                    </section>
                    <section class="rounded-xl border border-outline-variant bg-surface-container-lowest p-md">
                        <h3 class="flex items-center gap-xs font-h3 text-h3 text-on-surface"><span class="material-symbols-outlined text-warning" aria-hidden="true">warning</span>Cảnh báo hiệu suất</h3>
                        <div class="mt-sm grid grid-cols-2 gap-sm">
                            <div class="rounded-lg bg-warning/10 p-sm">
                                <p class="flex items-center gap-xs font-caption text-caption text-on-warning-container"><span class="material-symbols-outlined text-[16px]" aria-hidden="true">trending_down</span>Mức cảnh báo (≤50%)</p>
                                <p class="font-h2 text-h2 text-warning">{{ warnings.low }}</p>
                                <p class="font-caption text-caption text-on-warning-container">Tiêu chí cần chú ý</p>
                            </div>
                            <div class="rounded-lg bg-error-container p-sm">
                                <p class="flex items-center gap-xs font-caption text-caption text-on-error-container"><span class="material-symbols-outlined text-[16px]" aria-hidden="true">cancel</span>Không đạt (0%)</p>
                                <p class="font-h2 text-h2 text-error">{{ warnings.zero }}</p>
                                <p class="font-caption text-caption text-on-error-container">Tiêu chí bỏ lỡ</p>
                            </div>
                        </div>
                    </section>
                </div>
            </div>

            <section class="space-y-md rounded-xl border border-outline-variant bg-surface-container-lowest p-lg">
                <h3 class="font-h3 text-h3 text-on-surface">Đánh giá &amp; Nhận xét từ Quản lý</h3>
                <div class="grid grid-cols-1 gap-md md:grid-cols-3">
                    <UiTextarea name="strengths" label="Điểm tốt" rows="3" :value="evaluation?.strengths" placeholder="Nhập các điểm tích cực..." :disabled="!canConfirm" />
                    <UiTextarea name="improvements" label="Điểm cần cải thiện" rows="3" :value="evaluation?.improvements" placeholder="Nhập các điểm cần khắc phục..." :disabled="!canConfirm" />
                    <UiTextarea name="next_actions" label="Hành động tháng sau" rows="3" :value="evaluation?.next_actions" placeholder="Mục tiêu hoặc kế hoạch cụ thể cho tháng tới..." :disabled="!canConfirm" />
                </div>
                <UiTextarea name="comment" label="Nhận xét tổng quan" rows="2" :value="evaluation?.comment" :disabled="!canConfirm" />
            </section>

            <div v-if="canConfirm" class="flex flex-wrap items-center justify-end gap-sm">
                <UiButton type="submit" variant="secondary" name="action" value="draft" icon="save">Lưu nháp</UiButton>
                <UiButton type="submit" name="action" value="confirm" icon="lock">Chốt KPI tháng &amp; Lưu đánh giá</UiButton>
            </div>
        </UiForm>
    </div>
</template>
