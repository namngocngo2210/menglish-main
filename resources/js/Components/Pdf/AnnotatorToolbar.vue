<script setup>
/**
 * Thanh công cụ vẽ trên PDF: công cụ │ màu │ độ dày │ hoàn tác / làm lại │ xoá tất cả │ toàn màn hình.
 *   <AnnotatorToolbar v-model:tool="tool" v-model:color="color" v-model:size="size" :can-undo … @undo="undo" />
 * Các hình (đường thẳng, mũi tên, chữ nhật, tròn) gom vào một nút có menu.
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { COLORS, SHAPE_TOOLS, SIZES, TOOLS } from '@/lib/annotations';

const props = defineProps({
    tool: { type: String, required: true },
    color: { type: String, required: true },
    size: { type: Number, required: true },
    tools: { type: Array, default: () => TOOLS.map((t) => t.key) },
    canUndo: { type: Boolean, default: false },
    canRedo: { type: Boolean, default: false },
    hasAny: { type: Boolean, default: false },
    fullscreen: { type: Boolean, default: false },
});
const emit = defineEmits(['update:tool', 'update:color', 'update:size', 'undo', 'redo', 'clear-all', 'toggle-fullscreen']);

const available = computed(() => TOOLS.filter((t) => props.tools.includes(t.key)));
const plainTools = computed(() => available.value.filter((t) => !SHAPE_TOOLS.includes(t.key)));
const shapeTools = computed(() => available.value.filter((t) => SHAPE_TOOLS.includes(t.key)));
const lastShape = ref('line');
watch(() => props.tool, (tool) => SHAPE_TOOLS.includes(tool) && (lastShape.value = tool));
const shapeButton = computed(() => TOOLS.find((t) => t.key === lastShape.value));
const styled = computed(() => !['view', 'eraser', 'laser'].includes(props.tool));

const menu = ref(null);
const menuOpen = ref(false);
function closeOnOutside(event) {
    if (!menu.value?.contains(event.target)) menuOpen.value = false;
}
watch(menuOpen, (open) => (open ? document.addEventListener('pointerdown', closeOnOutside) : document.removeEventListener('pointerdown', closeOnOutside)));
onBeforeUnmount(() => document.removeEventListener('pointerdown', closeOnOutside));

function pickShape(key) {
    emit('update:tool', key);
    menuOpen.value = false;
}

const button = 'inline-flex h-9 min-w-9 items-center justify-center rounded-lg px-1.5 text-on-surface-variant transition-colors hover:bg-surface-container-low disabled:cursor-not-allowed disabled:opacity-40';
const active = 'bg-primary-container/10 text-primary';
const divider = 'mx-1 h-6 w-px shrink-0 bg-outline-variant';
</script>

<template>
    <div class="flex flex-wrap items-center gap-1 bg-surface-container-lowest px-2 py-1.5" role="toolbar" aria-label="Công cụ vẽ">
        <button
            v-for="t in plainTools.filter((t) => ['view', 'pen', 'highlighter'].includes(t.key))"
            :key="t.key"
            type="button"
            :class="[button, { [active]: tool === t.key }]"
            :title="t.label"
            :aria-label="t.label"
            :aria-pressed="tool === t.key"
            @click="emit('update:tool', t.key)"
        >
            <span class="material-symbols-outlined text-[20px]" aria-hidden="true">{{ t.icon }}</span>
        </button>

        <div v-if="shapeTools.length" ref="menu" class="relative" @keydown.esc.stop.prevent="menuOpen = false">
            <button
                type="button"
                :class="[button, { [active]: shapeTools.some((t) => t.key === tool) }]"
                title="Hình"
                aria-label="Hình"
                aria-haspopup="menu"
                :aria-expanded="menuOpen"
                @click="menuOpen = !menuOpen"
            >
                <span class="material-symbols-outlined text-[20px]" aria-hidden="true">{{ shapeButton.icon }}</span>
                <span class="material-symbols-outlined text-[16px]" aria-hidden="true">arrow_drop_down</span>
            </button>
            <div v-show="menuOpen" class="absolute left-0 top-full z-20 mt-1 w-44 rounded-lg border border-outline-variant bg-surface-container-lowest p-1 shadow-lg" role="menu">
                <button
                    v-for="t in shapeTools"
                    :key="t.key"
                    type="button"
                    role="menuitem"
                    :class="['flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-body-small text-on-surface hover:bg-surface-container-low', { [active]: tool === t.key }]"
                    @click="pickShape(t.key)"
                >
                    <span class="material-symbols-outlined text-[18px]" aria-hidden="true">{{ t.icon }}</span>{{ t.label }}
                </button>
            </div>
        </div>

        <button
            v-for="t in plainTools.filter((t) => !['view', 'pen', 'highlighter'].includes(t.key))"
            :key="t.key"
            type="button"
            :class="[button, { [active]: tool === t.key }]"
            :title="t.label"
            :aria-label="t.label"
            :aria-pressed="tool === t.key"
            @click="emit('update:tool', t.key)"
        >
            <span class="material-symbols-outlined text-[20px]" aria-hidden="true">{{ t.icon }}</span>
        </button>

        <template v-if="styled">
            <span :class="divider" aria-hidden="true" />
            <button
                v-for="c in COLORS"
                :key="c.key"
                type="button"
                :class="[button, { [active]: color === c.value }]"
                :title="c.label"
                :aria-pressed="color === c.value"
                @click="emit('update:color', c.value)"
            >
                <span class="block h-4 w-4 rounded-full border border-outline-variant" :style="{ background: c.value }" aria-hidden="true" />
                <span class="sr-only">{{ c.label }}</span>
            </button>
            <span :class="divider" aria-hidden="true" />
            <button
                v-for="s in SIZES"
                :key="s"
                type="button"
                :class="[button, { [active]: size === s }]"
                :title="`Độ dày ${s}`"
                :aria-pressed="size === s"
                @click="emit('update:size', s)"
            >
                <span class="block w-5 rounded-full bg-current" :style="{ height: `${s * 2}px` }" aria-hidden="true" />
                <span class="sr-only">Độ dày {{ s }}</span>
            </button>
        </template>

        <span :class="divider" aria-hidden="true" />
        <button type="button" :class="button" title="Hoàn tác (Ctrl+Z)" aria-label="Hoàn tác" :disabled="!canUndo" @click="emit('undo')">
            <span class="material-symbols-outlined text-[20px]" aria-hidden="true">undo</span>
        </button>
        <button type="button" :class="button" title="Làm lại (Ctrl+Shift+Z)" aria-label="Làm lại" :disabled="!canRedo" @click="emit('redo')">
            <span class="material-symbols-outlined text-[20px]" aria-hidden="true">redo</span>
        </button>
        <button type="button" :class="button" title="Xoá tất cả nét vẽ" aria-label="Xoá tất cả nét vẽ" :disabled="!hasAny" @click="emit('clear-all')">
            <span class="material-symbols-outlined text-[20px]" aria-hidden="true">delete_sweep</span>
        </button>
        <button
            type="button"
            :class="[button, 'ml-auto']"
            :title="fullscreen ? 'Thoát toàn màn hình' : 'Toàn màn hình'"
            :aria-label="fullscreen ? 'Thoát toàn màn hình' : 'Toàn màn hình'"
            @click="emit('toggle-fullscreen')"
        >
            <span class="material-symbols-outlined text-[20px]" aria-hidden="true">{{ fullscreen ? 'fullscreen_exit' : 'fullscreen' }}</span>
        </button>
    </div>
</template>
