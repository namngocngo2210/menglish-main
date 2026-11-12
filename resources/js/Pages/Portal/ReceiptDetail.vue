<script setup>
/**
 * Chi tiết một phiếu thu học phí đã duyệt (cổng học viên): mã phiếu, khoản thu, số tiền, hình thức, ngày thu và bill xem ngay trong trang.
 * Không hiển thị đối soát, ghi chú, người thu.
 */
import PortalPageHeader from './PortalPageHeader.vue';
import PortalTopHeader from './PortalTopHeader.vue';

defineOptions({ layout: { title: 'Chi tiết phiếu thu', workspaceTabs: false } });

defineProps({
    student: { type: Object, default: null },
    receipt: { type: Object, required: true },
    backUrl: { type: String, required: true },
});
</script>

<template>
    <PortalPageHeader title="Chi tiết phiếu thu" icon="receipt_long" :back="backUrl" />

    <div class="relative mx-auto my-4 flex max-w-[430px] flex-col overflow-hidden rounded-3xl border border-surface-container-highest bg-surface-container-lowest pb-6 shadow-2xl md:max-w-2xl md:shadow-sm">
        <PortalTopHeader :student="student" title="Chi tiết phiếu thu" show-back :back-url="backUrl" />

        <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 p-4 text-sm" data-receipt-detail>
            <dt class="text-on-surface-variant">Mã phiếu</dt><dd class="text-right font-code font-semibold">{{ receipt.number }}</dd>
            <dt class="text-on-surface-variant">Khoản thu</dt><dd class="text-right">{{ receipt.fee_item }}</dd>
            <dt class="text-on-surface-variant">Số tiền</dt><dd class="text-right font-code font-bold">{{ formatMoney(receipt.amount) }}</dd>
            <dt class="text-on-surface-variant">Hình thức</dt><dd class="text-right">{{ receipt.method }}</dd>
            <dt class="text-on-surface-variant">Ngày thu</dt><dd class="text-right">{{ receipt.date ? receipt.date.split('-').reverse().join('/') : '—' }}</dd>
        </dl>

        <section class="px-4" aria-labelledby="receipt-bill-title">
            <h2 id="receipt-bill-title" class="mb-2 text-xs font-bold uppercase tracking-wider text-on-surface-subtle">Bill</h2>
            <iframe v-if="receipt.bill && receipt.bill_is_pdf" :src="receipt.bill" title="Bill phiếu thu" class="h-[70vh] w-full rounded-lg border border-outline-variant"></iframe>
            <img v-else-if="receipt.bill" :src="receipt.bill" alt="Bill phiếu thu" class="max-h-[70vh] w-full rounded-lg border border-outline-variant object-contain" />
            <UiEmptyState v-else icon="receipt_long" title="Chưa có bill" description="Phiếu thu này chưa có bill đính kèm." />
        </section>
    </div>
</template>
