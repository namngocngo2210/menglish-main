<script setup>
/**
 * Toast toàn cục: thông báo flash của server (prop `flash`) + toast('…') từ JS (lib/toast.js).
 * Flash vẽ ngay khi render phía server (không nháy), tự ẩn sau 5 giây.
 */
import { computed, onMounted, ref, watch } from 'vue';
import { dismissToast, toasts } from '@/lib/toast';

const props = defineProps({ flash: { type: Array, default: () => [] } });
let seq = 0;
const flashItems = ref(props.flash.map((f) => ({ ...f, id: `f${++seq}` })));
const all = computed(() => [...flashItems.value, ...toasts]);
const icon = (type) => ({ success: 'check_circle', error: 'error', warning: 'warning', info: 'info' })[type] || 'info';
const iconTone = (type) => ({ success: 'text-tertiary-fixed', error: 'text-error-container', warning: 'text-warning-container', info: 'text-secondary-fixed-dim' })[type];

function remove(id) {
    flashItems.value = flashItems.value.filter((t) => t.id !== id);
    dismissToast(id);
}
function schedule(items) {
    items.forEach((item) => setTimeout(() => remove(item.id), 5000));
}

onMounted(() => schedule(flashItems.value));
// Trang mới / form vừa gửi xong mang flash mới.
watch(
    () => props.flash,
    (flash) => {
        const items = (flash ?? []).map((f) => ({ ...f, id: `f${++seq}` }));
        flashItems.value = [...flashItems.value, ...items];
        schedule(items);
    },
);
</script>

<template>
    <div class="pointer-events-none fixed bottom-lg left-md right-md z-[70] flex flex-col gap-sm sm:left-auto sm:right-lg sm:w-full sm:max-w-sm" aria-live="polite" data-toasts>
        <TransitionGroup enter-active-class="transition ease-out duration-300" enter-from-class="opacity-0 translate-y-4" leave-active-class="transition ease-in duration-200" leave-to-class="opacity-0">
            <div v-for="t in all" :key="t.id" class="pointer-events-auto flex items-center gap-md rounded-xl bg-inverse-surface p-md text-inverse-on-surface shadow-level-3" :role="t.type === 'error' ? 'alert' : 'status'">
                <span :class="['material-symbols-outlined shrink-0', iconTone(t.type)]" aria-hidden="true">{{ icon(t.type) }}</span>
                <span class="flex-1 font-body-medium text-body-medium">{{ t.message }}</span>
                <button type="button" class="shrink-0 rounded p-0.5 text-inverse-on-surface/70 hover:text-inverse-on-surface" aria-label="Đóng thông báo" @click="remove(t.id)">
                    <span class="material-symbols-outlined text-[18px]" aria-hidden="true">close</span>
                </button>
            </div>
        </TransitionGroup>
    </div>
</template>
