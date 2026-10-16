<script setup>
/**
 * Trạng thái Admin kiểm tra báo cáo, hiện cho người viết trên màn nhập (rà soát 16/10/2026 B7):
 * Admin "Yêu cầu bổ sung" → băng vàng kèm nhận xét (sửa rồi Lưu để nộp lại); đã kiểm tra → băng xanh.
 */
defineProps({ review: { type: Object, default: null } });
</script>

<template>
    <UiAlert v-if="review?.status === 'returned'" type="warning" title="Admin yêu cầu bổ sung" data-report-returned>
        <p class="whitespace-pre-line">{{ review.note }}</p>
        <p class="mt-xs font-body-small text-body-small">{{ review.by ? review.by + ' gửi. ' : '' }}Sửa báo cáo rồi bấm Lưu để nộp lại.</p>
    </UiAlert>
    <UiAlert v-else-if="review?.status === 'reviewed'" type="success" data-report-reviewed>
        {{ review.by ?? 'Admin' }} đã kiểm tra báo cáo này<template v-if="review.at"> lúc {{ formatDate(review.at, 'H:i d/m/Y') }}</template>.
    </UiAlert>
</template>
