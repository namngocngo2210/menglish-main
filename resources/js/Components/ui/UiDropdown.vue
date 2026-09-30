<script setup>
/**
 * Menu thả xuống (như <x-ui.dropdown>): slot `trigger` (nút mở) + slot `content` (mục). Bấm ra ngoài / Esc / chọn mục → đóng.
 *   <UiDropdown align="right" width="56">
 *       <template #trigger><UiButton variant="secondary">Xếp lớp</UiButton></template>
 *       <template #content><Link href="…" class="block px-md py-sm hover:bg-surface-container-low">Mục</Link></template>
 *   </UiDropdown>
 * align: right | left | top · width: 48 | 56 | 64 | 72 | 80 | 96 | notification | class w-*
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({
    align: { type: String, default: 'right' },
    width: { type: String, default: '48' },
    contentClasses: { type: String, default: 'py-1 bg-surface-container-lowest' },
});
const open = ref(false);
const root = ref(null);

const alignment = computed(() => ({ left: 'origin-top-left start-0', top: 'origin-top' })[props.align] ?? 'origin-top-right end-0');
const widthClass = computed(() => {
    const map = { 48: 'w-48', 56: 'w-56', 64: 'w-64', 72: 'w-72', 80: 'w-80', 96: 'w-80 sm:w-96', notification: 'w-[320px] sm:w-[380px] md:w-[420px] max-w-[92vw]' };
    return map[props.width] ?? (props.width.startsWith('w-') ? props.width : `w-${props.width}`);
});

function onDocument(event) {
    if (open.value && root.value && !root.value.contains(event.target)) open.value = false;
}
function onKey(event) {
    if (open.value && event.key === 'Escape') open.value = false;
}
onMounted(() => {
    document.addEventListener('click', onDocument);
    document.addEventListener('keydown', onKey);
});
onBeforeUnmount(() => {
    document.removeEventListener('click', onDocument);
    document.removeEventListener('keydown', onKey);
});

defineExpose({ close: () => (open.value = false) });
</script>

<template>
    <div ref="root" class="relative">
        <div @click="open = !open"><slot name="trigger" :open="open" /></div>
        <Transition enter-active-class="transition ease-out duration-200" enter-from-class="opacity-0 scale-95" enter-to-class="opacity-100 scale-100" leave-active-class="transition ease-in duration-75" leave-from-class="opacity-100 scale-100" leave-to-class="opacity-0 scale-95">
            <div v-show="open" :class="['absolute z-50 mt-2 rounded-xl shadow-level-3', widthClass, alignment]" @click="open = false">
                <div :class="['overflow-hidden rounded-xl border border-surface-container-highest bg-surface-container-lowest', contentClasses]">
                    <slot name="content" :close="() => (open = false)" />
                </div>
            </div>
        </Transition>
    </div>
</template>
