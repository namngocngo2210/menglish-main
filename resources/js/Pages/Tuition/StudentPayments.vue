<script setup>
/**
 * Lịch sử thu học phí của một học viên — modal khi bấm dòng ở Công nợ học viên / thẻ "Thông tin học phí" trong hồ sơ
 * học viên; mở thẳng URL → trang đầy đủ. Nội dung: Components/Tuition/PaymentHistory (staff).
 */
import PaymentHistory from '@/Components/Tuition/PaymentHistory.vue';

defineOptions({ layout: { title: 'Lịch sử thu học phí' } });

defineProps({
    asModal: { type: Boolean, default: false },
    student: { type: Object, required: true },
    summary: { type: Object, required: true },
    tuitions: { type: Array, default: () => [] },
    payments: { type: Array, default: () => [] },
    others: { type: Array, default: () => [] },
});
</script>

<template>
    <UiModalFrame
        title="Lịch sử thu học phí"
        :description="`${student.name} · ${shortCode(student.code)}`"
        :submit-label="false"
        cancel="Đóng"
        size="2xl"
        :back="route('tuition.students')"
    >
        <PaymentHistory :summary="summary" :tuitions="tuitions" :payments="payments" :others="others" staff />
        <template #footer>
            <UiButton variant="ghost" icon="receipt_long" :href="route('tuition.history', { student_id: student.id })">Mở trong Lịch sử thu</UiButton>
            <UiButton
                v-if="can('tuition.create') && summary.debt_amount > 0"
                variant="secondary"
                icon="payments"
                :href="route('tuition.receipts.create', { student_id: student.id })"
                modal="4xl"
            >
                Lập phiếu thu
            </UiButton>
        </template>
    </UiModalFrame>
</template>
