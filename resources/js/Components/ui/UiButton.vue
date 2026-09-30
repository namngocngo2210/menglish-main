<script setup>
/**
 * Nút chuẩn (như <x-ui.button>).
 *   variant: primary | secondary | ghost | danger | danger-text | success | info      size: md | sm
 *   icon: tên Material Symbol trước nhãn (không có nhãn → nút chỉ-icon)
 *   href: link chuyển trang trong app (Inertia <Link>); native → thẻ <a> thường (tải file, tab mới, trang ngoài app)
 *   modal: (cần href) true | cỡ modal (sm|md|lg|xl|2xl|3xl|4xl|full) → mở trang đó trong modal chung
 *   <UiButton icon="add" :href="route('classes.create')">Tạo lớp mới</UiButton>
 *   <UiButton icon="add" :href="route('holidays.create')" modal="md">Thêm ngày nghỉ</UiButton>
 *   <UiButton variant="secondary" icon="download" :href="route('crm.import.template')" native>Tải file mẫu</UiButton>
 *   <UiButton type="submit">Lưu</UiButton>
 */
import { computed, useAttrs, useSlots } from 'vue';
import { Link, useFormContext } from '@inertiajs/vue3';
import { openRemoteModal } from '@/lib/remoteModal';

const props = defineProps({
    variant: { type: String, default: 'primary' },
    size: { type: String, default: 'md' },
    icon: { type: String, default: null },
    href: { type: String, default: null },
    type: { type: String, default: 'button' },
    modal: { type: [Boolean, String], default: null },
    native: { type: Boolean, default: false },
    method: { type: String, default: null },
    data: { type: Object, default: null },
    preserveScroll: { type: Boolean, default: false },
    preserveState: { type: Boolean, default: false },
});
const attrs = useAttrs();
const slots = useSlots();
// Nút submit trong <UiForm>: khoá khi đang gửi (chống bấm 2 lần).
const form = useFormContext();
const busy = computed(() => props.type === 'submit' && !!form?.processing);

const variants = {
    primary: 'bg-primary-container text-white shadow-sm hover:bg-primary',
    secondary: 'border border-outline-variant bg-surface-container-lowest text-on-surface shadow-sm hover:bg-surface-container-low',
    ghost: 'text-on-surface-variant hover:bg-surface-container-high hover:text-primary',
    danger: 'bg-error text-white shadow-sm hover:bg-on-error-container',
    'danger-text': 'border border-transparent text-error hover:border-error/20 hover:bg-error-container/50',
    success: 'bg-tertiary text-white shadow-sm hover:bg-on-tertiary-fixed-variant',
    info: 'bg-secondary text-white shadow-sm hover:bg-secondary-hover',
};
const iconOnly = computed(() => !slots.default && !!props.icon);
const classes = computed(() => {
    const sizes = iconOnly.value
        ? { md: 'p-sm', sm: 'p-xs' }
        : { md: 'px-md py-sm font-body-medium text-body-medium', sm: 'px-sm py-xs font-body-medium text-body-small' };
    // Điện thoại: vùng bấm tối thiểu 44×44px.
    return [
        'max-md:min-h-11',
        iconOnly.value ? 'max-md:min-w-11' : '',
        'inline-flex shrink-0 items-center justify-center gap-xs whitespace-nowrap rounded-lg transition-colors duration-150 active:scale-[0.98] focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-container/40 disabled:pointer-events-none disabled:opacity-50',
        variants[props.variant] ?? variants.primary,
        sizes[props.size] ?? sizes.md,
    ];
});
const iconSize = computed(() => (props.size === 'sm' ? 'text-[16px]' : 'text-[18px]'));
const isNative = computed(() => props.native || !!attrs.target || attrs.download !== undefined);

function openModal(event) {
    if (event.ctrlKey || event.metaKey || event.shiftKey || event.button === 1) return;
    event.preventDefault();
    openRemoteModal(props.href, { size: typeof props.modal === 'string' ? props.modal : 'lg' });
}
</script>

<template>
    <a v-if="href && modal" :href="href" :class="classes" @click="openModal">
        <span v-if="icon" :class="['material-symbols-outlined', iconSize]" aria-hidden="true">{{ icon }}</span>
        <slot />
    </a>
    <a v-else-if="href && isNative" :href="href" :class="classes">
        <span v-if="icon" :class="['material-symbols-outlined', iconSize]" aria-hidden="true">{{ icon }}</span>
        <slot />
    </a>
    <Link v-else-if="href" :href="href" :method="method ?? 'get'" :data="data ?? {}" :as="method && method !== 'get' ? 'button' : 'a'" :preserve-scroll="preserveScroll" :preserve-state="preserveState" :class="classes">
        <span v-if="icon" :class="['material-symbols-outlined', iconSize]" aria-hidden="true">{{ icon }}</span>
        <slot />
    </Link>
    <button v-else :type="type" :class="classes" :disabled="busy || undefined" :aria-busy="busy ? 'true' : undefined">
        <span v-if="icon" :class="['material-symbols-outlined', iconSize]" aria-hidden="true">{{ icon }}</span>
        <slot />
    </button>
</template>
