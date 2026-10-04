<script setup>
/**
 * Lịch sử thu học phí của một học viên (dữ liệu từ App\Support\TuitionPaymentHistory): tổng phải thu / đã đóng / còn nợ,
 * rồi từng đợt nộp (mới nhất trên cùng) kèm số còn nợ sau đợt đó. Dùng ở modal Học vụ (Tuition/StudentPayments)
 * và cổng học sinh (Portal/Home).
 *   staff = true → thêm số HĐ, món thu khác, người lập / duyệt, ghi chú và các phiếu chưa tính (chờ duyệt, bị trả về…).
 *   Học sinh: "Thu khác" chỉ hiện tổng tiền, không liệt kê từng món.
 */
import { computed } from 'vue';

const props = defineProps({
    summary: { type: Object, required: true },
    tuitions: { type: Array, default: () => [] },
    payments: { type: Array, default: () => [] },
    others: { type: Array, default: () => [] },
    staff: { type: Boolean, default: false },
});

const KIND_ICON = { refund: 'undo', transfer_out: 'call_made', transfer_in: 'call_received' };
const negative = (p) => p.amount < 0;
const icon = (p) => KIND_ICON[p.kind] ?? p.method_icon ?? 'payments';
const multipleTuitions = computed(() => props.tuitions.length > 1);
</script>

<template>
    <div class="flex flex-col gap-md">
        <!-- Tổng quan -->
        <section aria-label="Tổng quan học phí" class="grid grid-cols-3 gap-px overflow-hidden rounded-xl border border-outline-variant bg-outline-variant text-center">
            <div class="bg-surface-container-lowest px-xs py-sm sm:p-sm">
                <p class="font-caption text-caption text-on-surface-variant">Tổng phải thu</p>
                <p class="whitespace-nowrap font-code text-body-small font-semibold text-on-surface sm:text-body-medium">{{ formatMoney(summary.final_amount) }}</p>
            </div>
            <div class="bg-surface-container-lowest px-xs py-sm sm:p-sm">
                <p class="font-caption text-caption text-on-surface-variant">Đã đóng</p>
                <p class="whitespace-nowrap font-code text-body-small font-semibold text-tertiary sm:text-body-medium">{{ formatMoney(summary.paid_amount) }}</p>
            </div>
            <div class="bg-surface-container-lowest px-xs py-sm sm:p-sm">
                <p class="font-caption text-caption text-on-surface-variant">Còn nợ</p>
                <p :class="['whitespace-nowrap font-code text-body-small font-semibold sm:text-body-medium', summary.debt_amount > 0 ? 'text-error' : 'text-tertiary']">{{ formatMoney(summary.debt_amount) }}</p>
            </div>
        </section>

        <div class="-mt-xs flex flex-wrap items-center gap-x-md gap-y-xs font-caption text-caption text-on-surface-variant">
            <span v-if="summary.is_settled" class="inline-flex items-center gap-xs font-semibold text-tertiary">
                <span class="material-symbols-outlined text-[16px]" aria-hidden="true">verified</span>Đã đóng đủ học phí
            </span>
            <span v-else-if="summary.next_due_date" class="inline-flex items-center gap-xs">
                <span class="material-symbols-outlined text-[16px]" aria-hidden="true">event</span>Hạn đóng phần còn lại: <strong class="font-semibold text-on-surface">{{ summary.next_due_date }}</strong>
            </span>
            <span v-if="summary.discount_amount > 0">Ưu đãi khi thu: {{ formatMoney(summary.discount_amount) }}</span>
            <span v-if="summary.other_fees > 0">Thu khác trong học phí: {{ formatMoney(summary.other_fees) }}</span>
        </div>

        <!-- Học nhiều khóa → liệt kê từng khoản học phí (đã đóng / phải thu); mỗi lần thu bên dưới ghi rõ khóa · lớp -->
        <ul v-if="multipleTuitions" class="flex flex-col gap-xs" aria-label="Các khoản học phí">
            <li v-for="t in tuitions" :key="t.id" class="flex flex-wrap items-center justify-between gap-sm rounded-lg bg-surface-container-low px-sm py-xs font-caption text-caption">
                <span class="font-semibold text-on-surface">{{ t.course_label }}</span>
                <span class="flex items-center gap-sm">
                    <span class="whitespace-nowrap font-code">{{ formatMoney(t.paid_amount) }} / {{ formatMoney(t.final_amount) }}</span>
                    <UiBadge :color="t.status_color" :dot="false">{{ t.status_label }}</UiBadge>
                </span>
            </li>
        </ul>

        <!-- Các đợt đã thu -->
        <section aria-labelledby="payment-history-title" class="flex flex-col gap-sm">
            <h3 id="payment-history-title" class="font-label text-label uppercase text-on-surface-variant">Các lần đã thu ({{ payments.length }})</h3>

            <ol v-if="payments.length" class="relative flex flex-col gap-sm">
                <li v-for="(p, i) in payments" :key="p.id" class="relative flex gap-sm" data-payment-row>
                    <!-- Trục thời gian -->
                    <div class="flex flex-col items-center">
                        <span :class="['flex h-9 w-9 shrink-0 items-center justify-center rounded-full', negative(p) ? 'bg-error-container text-error' : 'bg-primary-fixed text-primary']">
                            <span class="material-symbols-outlined text-[18px]" aria-hidden="true">{{ icon(p) }}</span>
                        </span>
                        <span v-if="i < payments.length - 1" class="mt-xs w-px flex-1 bg-outline-variant" aria-hidden="true"></span>
                    </div>

                    <div class="min-w-0 flex-1 rounded-lg border border-outline-variant bg-surface-container-lowest p-sm">
                        <div class="flex items-start justify-between gap-sm">
                            <div class="min-w-0">
                                <p class="font-body-medium text-body-medium font-semibold text-on-surface">{{ p.title }}<span class="font-normal text-on-surface-variant"> · {{ p.date ?? '—' }}</span></p>
                                <p class="break-words font-caption text-caption text-on-surface-variant">
                                    {{ p.method }} · <span class="font-code">{{ p.number }}</span>
                                    <template v-if="staff && p.invoice_number"> · HĐ <span class="font-code">{{ p.invoice_number }}</span></template>
                                </p>
                                <p v-if="p.tuition_label" class="flex items-center gap-xs font-caption text-caption text-on-surface-variant">
                                    <span class="material-symbols-outlined text-[14px]" aria-hidden="true">school</span>{{ p.tuition_label }}
                                </p>
                            </div>
                            <p :class="['shrink-0 whitespace-nowrap font-code text-body-medium font-bold', negative(p) ? 'text-error' : 'text-on-surface']">{{ formatMoney(p.amount) }}</p>
                        </div>

                        <div v-if="p.surcharge > 0 || p.discount > 0 || p.debt_after !== null" class="mt-xs flex flex-wrap items-center justify-between gap-x-md gap-y-xs border-t border-surface-container pt-xs font-caption text-caption">
                            <span class="flex flex-wrap gap-x-md gap-y-xs text-on-surface-variant">
                                <span v-if="p.surcharge > 0">Gồm thu khác {{ formatMoney(p.surcharge) }}</span>
                                <span v-if="p.discount > 0">Ưu đãi {{ formatMoney(p.discount) }}</span>
                            </span>
                            <span v-if="p.debt_after !== null" :class="p.debt_after > 0 ? 'text-on-surface-variant' : 'font-semibold text-tertiary'">
                                <template v-if="p.debt_after > 0">Còn nợ sau lần này: <strong class="whitespace-nowrap font-code text-error">{{ formatMoney(p.debt_after) }}</strong></template>
                                <template v-else>Đã đóng đủ</template>
                            </span>
                        </div>

                        <dl v-if="staff && (p.surcharge_detail || p.creator_name || p.approver_name || p.notes)" class="mt-xs grid grid-cols-[auto_1fr] gap-x-sm gap-y-0.5 font-caption text-caption text-on-surface-variant">
                            <template v-if="p.surcharge_detail"><dt>Thu khác:</dt><dd class="text-on-surface">{{ p.surcharge_detail }}</dd></template>
                            <template v-if="p.creator_name || p.approver_name"><dt>Lập / duyệt:</dt><dd class="text-on-surface">{{ p.creator_name ?? '—' }} / {{ p.approver_name ?? '—' }}</dd></template>
                            <template v-if="p.notes"><dt>Ghi chú:</dt><dd class="break-words text-on-surface">{{ p.notes }}</dd></template>
                        </dl>
                    </div>
                </li>
            </ol>
            <UiEmptyState v-else icon="receipt_long" title="Chưa có lần thu nào" description="Khoản học phí chưa được ghi nhận lần nộp nào đã duyệt." />
        </section>

        <!-- Học vụ: phiếu chưa tính vào công nợ -->
        <section v-if="staff && others.length" aria-labelledby="payment-others-title" class="flex flex-col gap-sm">
            <h3 id="payment-others-title" class="font-label text-label uppercase text-on-surface-variant">Phiếu chưa tính vào công nợ ({{ others.length }})</h3>
            <ul class="flex flex-col gap-xs">
                <li v-for="o in others" :key="o.id" class="flex flex-wrap items-center justify-between gap-sm rounded-lg border border-dashed border-outline-variant px-sm py-xs font-caption text-caption">
                    <span class="min-w-0">
                        <span class="font-code text-on-surface">{{ o.number }}</span> · {{ o.date ?? '—' }} · {{ o.method }}
                        <span v-if="o.rejection_reason" class="block text-error">Lý do trả về: {{ o.rejection_reason }}</span>
                    </span>
                    <span class="flex items-center gap-sm">
                        <span class="font-code text-on-surface">{{ formatMoney(o.amount) }}</span>
                        <UiBadge :color="o.status_color" :dot="false">{{ o.status_label }}</UiBadge>
                    </span>
                </li>
            </ul>
        </section>
    </div>
</template>
