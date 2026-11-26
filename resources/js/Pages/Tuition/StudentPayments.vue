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
        <section v-if="can('student.opening_sessions')" class="mb-lg space-y-md rounded-lg border border-outline-variant p-md" aria-labelledby="tuition-opening-sessions-heading">
            <h2 id="tuition-opening-sessions-heading" class="font-label text-label uppercase text-on-surface-variant">Buổi dư đầu kỳ</h2>
            <UiForm :key="`${student.id}-${student.opening_sessions}-${student.opening_sessions_set}`" :action="route('students.opening-sessions', student.id)" method="post" stay class="grid grid-cols-1 items-end gap-md sm:grid-cols-2">
                <UiInput id="tuition-opening-sessions" name="sessions" type="number" label="Số buổi dư đầu kỳ" min="0" max="4294967295" step="1" :value="student.opening_sessions" required hint="Chỉ nhập một lần khi đưa dữ liệu học viên cũ vào; học viên mới tự tính theo lịch sử." />
                <UiButton type="submit" icon="save">Lưu buổi dư</UiButton>
            </UiForm>
        </section>
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
