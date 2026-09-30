<script setup>
/**
 * Khung trường form: label (+ * bắt buộc), control (slot), gợi ý, lỗi validate — như <x-ui.field>.
 * Thường không dùng trực tiếp: UiInput / UiSelect / UiTextarea tự bọc khi có `label`.
 */
import { useFieldError } from './useFieldError';

const props = defineProps({
    label: { type: String, default: null },
    name: { type: String, default: null },
    for: { type: String, default: null },
    required: { type: Boolean, default: false },
    hint: { type: String, default: null },
    bag: { type: String, default: null },
    error: { type: String, default: null },
});

const message = useFieldError(props);
</script>

<template>
    <div class="flex flex-col gap-xs">
        <label v-if="label" :for="props.for" class="font-body-small text-body-small text-on-surface-variant" data-field-label>{{ label }}<span v-if="required" class="ml-0.5 text-error" aria-hidden="true">*</span></label>
        <slot />
        <p v-if="message" :id="props.for ? props.for + '-error' : null" class="flex items-center gap-xs font-caption text-caption text-error" role="alert">
            <span class="material-symbols-outlined text-[14px]" aria-hidden="true">error</span>{{ message }}
        </p>
        <p v-else-if="hint" :id="props.for ? props.for + '-hint' : null" class="font-caption text-caption text-on-surface-variant">{{ hint }}</p>
    </div>
</template>
