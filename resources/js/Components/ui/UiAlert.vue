<script setup>
/**
 * Banner thông báo trong trang, viền trái 4px (như <x-ui.alert>). type: error | warning | info | success
 *   <UiAlert type="warning" title="Cảnh báo" dismissible>Số lớp đã thay đổi.</UiAlert>
 */
import { computed, ref } from 'vue';

const props = defineProps({
    type: { type: String, default: 'info' },
    title: { type: String, default: null },
    dismissible: { type: Boolean, default: false },
});
const open = ref(true);
const styles = {
    error: ['bg-error-container border-error text-on-error-container', 'text-error', 'error'],
    warning: ['bg-warning-container border-warning text-on-warning-container', 'text-warning', 'warning'],
    info: ['bg-secondary-fixed/50 border-secondary text-on-secondary-fixed', 'text-secondary', 'info'],
    success: ['bg-tertiary-fixed/30 border-tertiary text-on-tertiary-fixed-variant', 'text-tertiary', 'check_circle'],
};
const style = computed(() => styles[props.type] ?? styles.info);
</script>

<template>
    <div v-show="open" :role="type === 'error' ? 'alert' : 'status'" :class="['flex items-start gap-sm rounded-r-lg border-l-4 p-md shadow-sm', style[0]]">
        <span :class="['material-symbols-outlined mt-[2px] shrink-0', style[1]]" aria-hidden="true">{{ style[2] }}</span>
        <div class="min-w-0 flex-1 font-body-base text-body-base">
            <h4 v-if="title" class="font-body-medium text-body-medium font-semibold">{{ title }}</h4>
            <div :class="{ 'mt-xs': title }"><slot /></div>
        </div>
        <button v-if="dismissible" type="button" class="shrink-0 rounded p-0.5 opacity-70 hover:opacity-100" aria-label="Đóng" @click="open = false">
            <span class="material-symbols-outlined text-[18px]">close</span>
        </button>
    </div>
</template>
