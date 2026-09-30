<script setup>
/**
 * Mã chứng từ / hồ sơ: mã ULID dài rút gọn (tiền tố + 6 ký tự cuối), mã đầy đủ ở tooltip (như <x-ui.code>).
 *   <UiCode :value="student.code" class="text-on-surface-variant" />
 */
import { computed } from 'vue';
import { shortCode } from '@/lib/format';

const props = defineProps({ value: { type: [String, Number], default: null } });
const full = computed(() => (props.value === null || props.value === undefined ? '' : String(props.value)));
const short = computed(() => shortCode(full.value));
</script>

<template>
    <span v-if="full === ''">—</span>
    <span v-else-if="short !== full" class="cursor-help" :title="full">{{ short }}</span>
    <span v-else>{{ full }}</span>
</template>
