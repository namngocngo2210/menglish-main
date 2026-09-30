<script setup>
/**
 * Layout trống cho trang không dùng khung ứng dụng (trang công khai, làm bài test toàn màn hình…):
 * chỉ nội dung trang + toast + hộp xác nhận. Trang dùng: defineOptions({ layout: BareLayout }).
 */
import ToastHost from './Shell/ToastHost.vue';
import ConfirmDialogHost from './Shell/ConfirmDialogHost.vue';

defineProps({ flash: { type: Array, default: () => [] } });
</script>

<template>
    <slot />
    <ToastHost :flash="flash" />
    <ConfirmDialogHost />
</template>
