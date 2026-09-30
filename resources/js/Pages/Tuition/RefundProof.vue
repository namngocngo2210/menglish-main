<script setup>
/**
 * Lightbox ảnh bằng chứng hoàn tiền (mở trong modal từ bảng hoàn phí; mở thẳng URL tuition.refunds.proof trả file ảnh gốc).
 */
import { computed } from 'vue';

defineOptions({ layout: { title: 'Ảnh bằng chứng hoàn tiền' } });

const props = defineProps({
    refund: { type: Object, required: true },
});

const title = computed(() => 'Ảnh bằng chứng — ' + (props.refund.student_name ?? 'Hồ sơ #' + props.refund.id));
const description = computed(() => 'Hồ sơ #' + props.refund.id + (props.refund.student_code ? ' · ' + props.refund.student_code : ''));
</script>

<template>
    <UiModalFrame :title="title" :description="description" cancel="Đóng" size="xl" :back="route('tuition.refunds')">
        <figure class="flex justify-center rounded-lg bg-surface-container-low p-sm">
            <img :src="refund.proof_url" :alt="'Ảnh bằng chứng hoàn tiền của ' + (refund.student_name ?? '')" class="max-h-[70vh] w-auto max-w-full rounded object-contain" />
        </figure>
        <template #footer>
            <UiButton variant="secondary" icon="open_in_new" :href="refund.proof_url" target="_blank" rel="noopener" native>Mở ảnh gốc</UiButton>
        </template>
    </UiModalFrame>
</template>
