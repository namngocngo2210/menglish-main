<script setup>
/**
 * Ô tích + nhãn. Dùng trong <UiForm> theo `name` (như checkbox HTML), hoặc v-model (boolean, hoặc mảng giá trị khi có `value`).
 *   <UiCheckbox name="is_active" value="1" :checked="course.is_active" label="Đang mở" />
 *   <UiCheckbox v-for="b in branches" :key="b.id" v-model="picked" name="branch_ids[]" :value="b.id" :label="b.name" />
 * Muốn gửi 0 khi bỏ tích: thêm <input type="hidden" name="is_active" value="0"> đặt TRƯỚC ô tích.
 */
import { computed } from 'vue';

defineOptions({ inheritAttrs: false });

const props = defineProps({
    name: { type: String, default: null },
    value: { type: [String, Number, Boolean], default: '1' },
    checked: { type: Boolean, default: false },
    modelValue: { type: [Boolean, Array], default: undefined },
    label: { type: String, default: null },
    hint: { type: String, default: null },
    disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue', 'change']);

const isChecked = computed(() => {
    if (props.modelValue === undefined) return props.checked;
    if (Array.isArray(props.modelValue)) return props.modelValue.map(String).includes(String(props.value));
    return !!props.modelValue;
});

function onChange(event) {
    const on = event.target.checked;
    if (Array.isArray(props.modelValue)) {
        const rest = props.modelValue.filter((v) => String(v) !== String(props.value));
        emit('update:modelValue', on ? [...rest, props.value] : rest);
    } else if (props.modelValue !== undefined) {
        emit('update:modelValue', on);
    }
    emit('change', event);
}
</script>

<template>
    <label :class="['flex items-start gap-sm font-body-small text-body-small text-on-surface', disabled ? 'cursor-not-allowed opacity-60' : 'cursor-pointer', $attrs.class]">
        <input v-bind="{ ...$attrs, class: undefined }" type="checkbox" :name="name" :value="value" :checked="isChecked" :disabled="disabled" class="mt-0.5 rounded border-outline-variant text-primary-container focus:ring-primary-container/40" @change="onChange" />
        <span v-if="label || hint || $slots.default" class="min-w-0">
            <slot>{{ label }}</slot>
            <span v-if="hint" class="block font-caption text-caption text-on-surface-variant">{{ hint }}</span>
        </span>
    </label>
</template>
