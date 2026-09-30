<script setup>
/**
 * Ô chọn ngày (input type="date", giá trị Y-m-d) — cùng API với UiInput.
 *   <UiDate name="start_date" label="Ngày khai giảng" required :value="klass.start_date" />
 */
import { computed } from 'vue';
import UiInput from './UiInput.vue';
import { formatDate } from '@/lib/format';

const props = defineProps({ value: { type: String, default: null } });
const val = computed(() => (props.value && !/^\d{4}-\d{2}-\d{2}$/.test(props.value) ? formatDate(props.value, 'Y-m-d') : props.value));
</script>

<template>
    <UiInput type="date" :value="val" />
</template>
