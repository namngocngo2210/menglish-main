<script setup>
/**
 * Menu thả xuống (như <x-ui.dropdown>): slot `trigger` (nút mở) + slot `content` (mục). Bấm ra ngoài / Esc / chọn mục → đóng.
 *   <UiDropdown align="right" width="56">
 *       <template #trigger><UiButton variant="secondary">Xếp lớp</UiButton></template>
 *       <template #content><Link href="…" class="block px-md py-sm hover:bg-surface-container-low">Mục</Link></template>
 *   </UiDropdown>
 * align: right | left | top · width: 48 | 56 | 64 | 72 | 80 | 96 | notification | class w-*
 *
 * Menu được đưa ra <body> và định vị `fixed` theo nút mở: nằm trong bảng cuộn ngang (UiDataTable, overflow)
 * menu không bị cắt mất — trước đây nút "⋯" ở dòng cuối bảng bấm không thấy gì. Không đủ chỗ phía dưới → mở lên trên.
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({
    align: { type: String, default: 'right' },
    width: { type: String, default: '48' },
    contentClasses: { type: String, default: 'py-1 bg-surface-container-lowest' },
});
const open = ref(false);
const root = ref(null);
const panel = ref(null);
const position = ref({});
const dropUp = ref(false);

const GAP = 8;

const origin = computed(() => {
    const vertical = dropUp.value ? 'bottom' : 'top';
    return props.align === 'right' ? `origin-${vertical}-right` : `origin-${vertical}-left`;
});
const widthClass = computed(() => {
    const map = { 48: 'w-48', 56: 'w-56', 64: 'w-64', 72: 'w-72', 80: 'w-80', 96: 'w-80 sm:w-96', notification: 'w-[320px] sm:w-[380px] md:w-[420px] max-w-[92vw]' };
    return map[props.width] ?? (props.width.startsWith('w-') ? props.width : `w-${props.width}`);
});

/** Đặt menu ngay dưới (hoặc trên) nút mở, canh phải/trái theo `align`, không tràn khỏi màn hình. */
function place() {
    if (!open.value || !root.value) return;
    const rect = root.value.getBoundingClientRect();
    const viewportW = document.documentElement.clientWidth;
    const viewportH = window.innerHeight;
    const height = panel.value?.offsetHeight ?? 0;
    const width = panel.value?.offsetWidth ?? 0;

    dropUp.value = rect.bottom + GAP + height > viewportH && rect.top - GAP - height >= 0;
    const top = dropUp.value ? rect.top - GAP - height : rect.bottom + GAP;

    let left = props.align === 'right' ? rect.right - width : rect.left;
    left = Math.max(GAP, Math.min(left, viewportW - width - GAP));

    position.value = { top: `${Math.round(top)}px`, left: `${Math.round(left)}px` };
}

async function toggle() {
    open.value = !open.value;
    if (open.value) {
        await nextTick();
        place();
    }
}

function onDocument(event) {
    if (!open.value) return;
    if (root.value?.contains(event.target) || panel.value?.contains(event.target)) return;
    open.value = false;
}
function onKey(event) {
    if (open.value && event.key === 'Escape') open.value = false;
}
onMounted(() => {
    document.addEventListener('click', onDocument);
    document.addEventListener('keydown', onKey);
    window.addEventListener('resize', place);
    window.addEventListener('scroll', place, true);
});
onBeforeUnmount(() => {
    document.removeEventListener('click', onDocument);
    document.removeEventListener('keydown', onKey);
    window.removeEventListener('resize', place);
    window.removeEventListener('scroll', place, true);
});

defineExpose({ close: () => (open.value = false) });
</script>

<template>
    <div ref="root" class="relative">
        <div @click="toggle"><slot name="trigger" :open="open" /></div>
        <Teleport to="body">
            <Transition enter-active-class="transition ease-out duration-200" enter-from-class="opacity-0 scale-95" enter-to-class="opacity-100 scale-100" leave-active-class="transition ease-in duration-75" leave-from-class="opacity-100 scale-100" leave-to-class="opacity-0 scale-95">
                <div v-show="open" ref="panel" :class="['fixed z-[60] rounded-xl shadow-level-3', widthClass, origin]" :style="position" @click="open = false">
                    <div :class="['overflow-hidden rounded-xl border border-surface-container-highest bg-surface-container-lowest', contentClasses]">
                        <slot name="content" :close="() => (open = false)" />
                    </div>
                </div>
            </Transition>
        </Teleport>
    </div>
</template>
