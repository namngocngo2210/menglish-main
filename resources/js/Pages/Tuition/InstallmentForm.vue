<script setup>
/** Tạo đợt học phí mới cho học viên đã có trong lớp (TK-2026-0116). Modal từ Công nợ học viên; mở thẳng URL → trang đầy đủ. */
import { computed, ref } from 'vue';

defineOptions({ layout: { title: 'Tạo đợt học phí mới' } });

const props = defineProps({
    asModal: { type: Boolean, default: false },
    student: { type: Object, required: true },
    classes: { type: Array, default: () => [] },
    classId: { type: Number, default: null },
});

const sessions = ref(24);
const unitPrice = ref(0);
const total = computed(() => Math.max(0, Number(sessions.value) || 0) * Math.max(0, Number(unitPrice.value) || 0));
const action = route('tuition.installments.store', props.student.id);
</script>

<template>
    <component
        :is="asModal ? 'UiModalFrame' : 'UiForm'"
        v-bind="asModal
            ? { title: `Đợt học phí mới · ${student.name}`, description: 'Tạo khoản học phí mới cho học viên đã có trong lớp. Phiếu thu lập và duyệt như bình thường.', action, method: 'post' }
            : { action, method: 'post', class: 'space-y-md' }"
    >
        <UiSelect name="class_id" label="Lớp học" required :options="classes" :value="classId" />
        <div class="grid grid-cols-2 gap-sm">
            <UiDate name="start_date" label="Ngày bắt đầu" required />
            <UiDate name="end_date" label="Ngày kết thúc" required />
        </div>
        <div class="grid grid-cols-2 gap-sm">
            <UiInput v-model="sessions" name="session_count" type="number" min="1" step="1" label="Số buổi" required />
            <UiInput v-model="unitPrice" name="unit_price" type="number" min="0" step="1000" label="Đơn giá / buổi" required suffix="đ" />
        </div>
        <p class="font-body-small text-body-small text-on-surface-variant">Tổng học phí: <UiMoney :value="total" /></p>
        <UiInput name="notes" label="Ghi chú" />
        <UiButton v-if="!asModal" type="submit" icon="save">Tạo đợt học phí</UiButton>
    </component>
</template>
