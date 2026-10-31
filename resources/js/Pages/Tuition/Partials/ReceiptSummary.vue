<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { formatMoney } from '@/lib/format';

const props = defineProps({
    form: { type: Object, required: true },
    layout: { type: String, default: 'modal' },
    submitLabel: { type: String, default: 'Lưu phiếu thu & Gửi duyệt' },
    cancelUrl: { type: String, default: '' },
});
const money = (value) => formatMoney(value || 0);
const q = computed(() => props.form.quote);
/** Explain why the receipt cannot be submitted yet. */
const invalidReason = computed(() => {
    const st = props.form.state;
    if (props.form.needsDiscountReason && !st.discountReason.trim()) return 'Nhập lý do giảm trừ (không theo ưu đãi có sẵn).';
    if (st.otherFee > 0 && !st.otherFeeReason.trim()) return 'Nhập nội dung khoản thu khác.';
    if (st.surchargeAmount > 0 && !(st.surchargeReason || '').trim()) return 'Nhập lý do phụ thu.';
    return 'Phiếu cần có số tiền từ 1.000 đ (thu buổi, học liệu, phí thi, khoản khác hoặc phụ thu).';
});
</script>

<template>
    <aside
        data-testid="receipt-summary"
        aria-label="Tóm tắt phiếu thu"
        :class="['order-2 min-w-0 self-start space-y-5 rounded-2xl border border-primary-container/30 bg-surface-container-lowest p-4 shadow-sm sm:p-5', layout === 'page' ? 'lg:sticky lg:top-lg' : '']"
    >
        <div>
            <h2 class="text-xs font-bold uppercase tracking-wider text-on-surface-variant">Tóm tắt phiếu thu</h2>
            <p class="mt-1 break-words text-base font-bold text-on-surface">{{ form.state.currentStudent?.name || 'Chưa chọn học viên' }}</p>
        </div>
        <dl class="space-y-3 text-xs" data-testid="receipt-totals">
            <div class="flex justify-between gap-4">
                <dt class="text-on-surface-variant">Học phí {{ form.calc.sessionCount }} buổi × {{ money(q?.unit_price || 0) }}</dt>
                <dd class="shrink-0 font-code text-on-surface">{{ money(form.calc.sessionValue) }}</dd>
            </div>
            <div class="flex justify-between gap-4">
                <dt class="text-on-surface-variant">+ Học liệu</dt>
                <dd class="shrink-0 font-code text-on-surface">{{ money(form.calc.materialFee) }}</dd>
            </div>
            <div class="flex justify-between gap-4">
                <dt class="text-on-surface-variant">+ Phí thi</dt>
                <dd class="shrink-0 font-code text-on-surface">{{ money(form.calc.examFee) }}</dd>
            </div>
            <div class="flex justify-between gap-4">
                <dt class="text-on-surface-variant">+ Khoản khác</dt>
                <dd class="shrink-0 font-code text-on-surface">{{ money(form.calc.otherFee) }}</dd>
            </div>
            <div class="flex justify-between gap-4 border-t border-dashed border-surface-container-highest pt-1">
                <dt class="font-bold text-on-surface">Tổng trước giảm</dt>
                <dd class="shrink-0 font-code font-bold text-on-surface">{{ money(form.calc.subtotal) }}</dd>
            </div>
            <div class="flex justify-between gap-4">
                <dt class="text-on-surface-variant">− Giảm trừ</dt>
                <dd class="shrink-0 font-code text-tertiary">{{ money(form.calc.discount) }}</dd>
            </div>
            <div class="flex justify-between gap-4 border-t border-dashed border-surface-container-highest pt-1">
                <dt class="font-bold text-on-surface">Tổng phải thu</dt>
                <dd class="shrink-0 font-code font-bold text-on-surface">{{ money(form.calc.totalDue) }}</dd>
            </div>
            <div class="flex justify-between gap-4">
                <dt class="text-on-surface-variant">+ Phụ thu</dt>
                <dd class="shrink-0 font-code text-primary">{{ money(form.calc.extra) }}</dd>
            </div>
        </dl>
        <div class="space-y-2 border-t border-primary-container/20 pt-4">
            <p class="text-xs font-bold uppercase text-on-surface-variant">TỔNG THỰC THU:</p>
            <p class="break-words font-code text-3xl font-bold text-primary">{{ money(form.totalAmount) }}</p>
            <div class="pt-1 text-xs">
                <span v-if="form.isValidReceipt" class="flex items-center gap-1 font-medium text-tertiary">
                    <span class="material-symbols-outlined text-sm text-tertiary">check_circle</span>
                    Hợp lệ để gửi duyệt.
                </span>
                <span v-else class="flex items-center gap-1 font-medium text-error">
                    <span class="material-symbols-outlined text-sm text-error">warning</span>
                    {{ invalidReason }}
                </span>
            </div>
        </div>
        <div v-if="layout === 'page'" class="hidden flex-col gap-3 lg:flex">
            <UiButton variant="secondary" type="submit" name="submit_action" value="draft" icon="drafts" class="min-h-11">Lưu nháp</UiButton>
            <UiButton type="submit" name="submit_action" value="submit" data-testid="receipt-submit-desktop" icon="save" :disabled="!form.isValidReceipt" class="min-h-11 w-full !whitespace-normal [&>.material-symbols-outlined]:shrink-0">
                <span class="min-w-0">{{ submitLabel }} (<span class="font-code">{{ money(form.totalAmount) }}</span>)</span>
            </UiButton>
        </div>
        <Link v-if="layout === 'page'" :href="cancelUrl" class="inline-flex min-h-11 w-full items-center justify-center rounded-lg text-sm font-medium text-on-surface-variant hover:text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-container">Hủy bỏ</Link>
    </aside>
</template>
