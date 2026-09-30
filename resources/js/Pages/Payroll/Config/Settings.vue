<script setup>
/**
 * Cấu hình tham số lương: BHXH / Công đoàn Full-time, quỹ KPI Học vụ, bảng % thưởng tái tục theo số HS nghỉ
 * (thêm / xoá mốc ngay trên form; mốc chưa chốt đánh dấu "chờ BA").
 */
import { computed, reactive } from 'vue';
import { usePage } from '@inertiajs/vue3';

defineOptions({ layout: { title: 'Cấu hình Tham số Lương' } });

const props = defineProps({
    settings: { type: Object, required: true },
    renewalRows: { type: Array, default: () => [] },
});

const page = usePage();
const rows = reactive(props.renewalRows.map((row) => ({ ...row })));
const renewalError = computed(() => {
    const errors = page.props.errors ?? {};
    return errors.renewal ?? null;
});
const renewalRowError = computed(() => {
    const errors = page.props.errors ?? {};
    const key = Object.keys(errors).find((k) => k.startsWith('renewal.'));
    return key ? errors[key] : null;
});
</script>

<template>
    <div>
        <UiPageHeader title="Cấu hình Tham số Lương" icon="tune" :back="route('payroll.periods.index')" description="BHXH / Công đoàn Full-time, quỹ KPI Học vụ, bảng % thưởng tái tục." />

        <div class="max-w-3xl space-y-5">
            <UiAlert type="info">
                <div class="space-y-1 text-xs leading-relaxed text-on-surface-variant">
                    <p>Thay đổi chỉ áp dụng khi <strong>tính/tính lại</strong> các kỳ lương về sau. Kỳ lương <strong>Đã duyệt / Đã chi trả</strong> không bị ảnh hưởng.</p>
                    <p><strong>Part-time</strong> = số buổi × đơn giá buổi riêng (màn Đơn giá GV) + KPI giữ HS (bậc {{ settings.retention_tiers }}đ, chọn trên phiếu lương) + buổi có GVNN (chờ BA) + phụ cấp tự do − khoản trừ. Không BHXH / Công đoàn.</p>
                    <p><strong>Full-time</strong> = lương cơ bản + KPI / hoa hồng / thưởng tái tục / phụ cấp − BHXH − Công đoàn − thuế TNCN (nhập tay) − trừ vi phạm.</p>
                </div>
            </UiAlert>

            <UiForm :action="route('payroll.config.settings.store')" method="post" preserve-state="errors" class="divide-y divide-surface-container-highest rounded-2xl border border-surface-container-highest bg-surface-container-lowest shadow-sm">
                <div class="space-y-5 p-6">
                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-3">
                        <UiInput id="insurance_rate_percent" type="number" name="insurance_rate_percent" label="BHXH (% lương cơ bản)" suffix="%" min="0" max="100" step="0.1" :value="settings.insurance_rate_percent" class="font-mono" />
                        <UiInput id="union_rate_percent" type="number" name="union_rate_percent" label="Công đoàn (% lương cơ bản)" suffix="%" min="0" max="100" step="0.1" :value="settings.union_rate_percent" class="font-mono" />
                        <UiInput id="academic_kpi_fund" type="number" name="academic_kpi_fund" label="Quỹ KPI Học vụ / tháng" suffix="đ" min="0" step="1000" :value="settings.academic_kpi_fund" class="font-mono" />
                    </div>

                    <div class="space-y-2">
                        <div class="flex items-center justify-between">
                            <div>
                                <h3 class="text-xs font-bold text-on-surface-variant">Thưởng tái tục — % doanh thu lớp theo số HS nghỉ trong kỳ</h3>
                                <p class="text-xs text-on-surface-variant">BA mới chốt: giữ đủ 100% → 1%, nghỉ 1 HS → 0,7%. Các mốc khác đánh dấu <strong>chờ BA</strong> cho tới khi có bảng đầy đủ.</p>
                            </div>
                            <UiButton variant="secondary" size="sm" @click="rows.push({ quits: rows.length, percent: 0, pending: true })">+ Thêm mốc</UiButton>
                        </div>
                        <table class="w-full text-xs">
                            <thead>
                                <tr class="text-left text-xs uppercase text-on-surface-variant">
                                    <th class="py-1">Số HS nghỉ</th><th class="py-1">% doanh thu lớp</th><th class="py-1">Chờ BA</th><th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(row, i) in rows" :key="i">
                                    <td class="py-1 pr-2"><input v-model="row.quits" type="number" min="0" :name="`renewal[${i}][quits]`" :aria-label="`Số HS nghỉ, dòng ${i + 1}`" class="w-24 rounded-lg border border-surface-container-highest px-2 py-1.5 font-mono text-xs" /></td>
                                    <td class="py-1 pr-2"><input v-model="row.percent" type="number" min="0" max="100" step="0.05" :name="`renewal[${i}][percent]`" :aria-label="`% doanh thu lớp, dòng ${i + 1}`" class="w-28 rounded-lg border border-surface-container-highest px-2 py-1.5 font-mono text-xs" /></td>
                                    <td class="py-1 pr-2">
                                        <input type="hidden" :name="`renewal[${i}][pending]`" :value="row.pending ? 1 : 0" />
                                        <input v-model="row.pending" type="checkbox" :aria-label="`Chờ BA, dòng ${i + 1}`" class="rounded border-outline-variant" />
                                        <span v-show="row.pending" class="ml-1 text-xs font-semibold text-warning">chờ BA</span>
                                    </td>
                                    <td class="py-1 text-right"><UiButton variant="danger-text" size="sm" @click="rows.splice(i, 1)">Xoá</UiButton></td>
                                </tr>
                            </tbody>
                        </table>
                        <div class="flex items-center gap-2">
                            <label for="renewal_beyond_percent" class="text-xs font-semibold text-on-surface-variant">Nghỉ nhiều hơn các mốc trên:</label>
                            <input id="renewal_beyond_percent" type="number" name="renewal_beyond_percent" min="0" max="100" step="0.05" :value="settings.renewal_beyond_percent" class="w-28 rounded-lg border border-surface-container-highest px-2 py-1.5 font-mono text-xs" />
                            <span class="text-xs text-on-surface-variant">% (chờ BA)</span>
                        </div>
                        <p v-if="renewalError" class="mt-1 text-xs text-error">{{ renewalError }}</p>
                        <p v-if="renewalRowError" class="mt-1 text-xs text-error">{{ renewalRowError }}</p>
                    </div>
                </div>

                <div class="flex flex-col items-center justify-between gap-4 border-t border-surface-container-highest bg-surface-container-low p-6 sm:flex-row">
                    <div class="text-center text-xs text-on-surface-variant sm:text-left">
                        Mặc định (config/payroll.php): BHXH 10,5% · Công đoàn 0,5% · quỹ KPI Học vụ 2.000.000đ.
                    </div>
                    <UiButton type="submit" icon="save" class="w-full sm:w-auto">Lưu tham số</UiButton>
                </div>
            </UiForm>
        </div>
    </div>
</template>
