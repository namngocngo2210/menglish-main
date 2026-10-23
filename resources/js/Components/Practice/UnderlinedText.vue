<script setup>
/**
 * Chữ có gạch chân theo markup `[x]` của ngân hàng câu hỏi (đề, phương án, ngữ liệu). Không dùng v-html.
 *   <UnderlinedText :text="question.stem" class="whitespace-pre-line" />
 */
import { computed } from 'vue';
import { splitUnderline } from '@/lib/underline';

const props = defineProps({ text: { type: String, default: '' } });
const parts = computed(() => splitUnderline(props.text));
</script>

<template>
    <span><template v-for="(part, i) in parts" :key="i"><u v-if="part.underline" class="decoration-2 underline-offset-2">{{ part.text }}</u><template v-else>{{ part.text }}</template></template></span>
</template>
