<script setup>
/**
 * Ô nhập nhiều dòng (như <x-ui.textarea>). Có `label` → tự bọc UiField.
 *   <UiTextarea name="reason" label="Lý do không chốt" required :rows="3" />
 */
import { computed, useAttrs } from 'vue';
import UiField from './UiField.vue';
import { fieldId, useFieldError, useTypedValue } from './useFieldError';

defineOptions({ inheritAttrs: false });

const props = defineProps({
    name: { type: String, default: null },
    label: { type: String, default: null },
    value: { type: [String, Number], default: null },
    modelValue: { type: [String, Number], default: undefined },
    rows: { type: [Number, String], default: 4 },
    required: { type: Boolean, default: false },
    hint: { type: String, default: null },
    bag: { type: String, default: null },
    error: { type: String, default: null },
});
const emit = defineEmits(['update:modelValue']);
const attrs = useAttrs();

const id = fieldId(props, attrs);
const error = useFieldError(props);
const controlled = computed(() => props.modelValue !== undefined);
// Không v-model: giữ chữ đang gõ khi render lại (vd. vừa hiện lỗi validate).
const { value: typed, bindEl } = useTypedValue(() => props.value);
const describedBy = computed(() => (props.label && id ? (error.value ? id + '-error' : props.hint ? id + '-hint' : null) : null));
const control = computed(() => [
    'w-full rounded-lg border bg-surface-container-lowest px-md py-sm font-body-base text-body-base text-on-surface placeholder:text-on-surface-subtle transition-colors focus:outline-none focus:ring-2 disabled:cursor-not-allowed disabled:bg-surface-container-low',
    error.value ? 'border-error focus:border-error focus:ring-error/20' : 'border-outline-variant focus:border-primary-container focus:ring-primary-container/50',
]);
const textareaAttrs = computed(() => {
    const { id: _id, ...rest } = attrs;
    return rest;
});
function onInput(event) {
    if (controlled.value) emit('update:modelValue', event.target.value);
}
</script>

<template>
    <UiField v-if="label" :label="label" :name="name" :for="id" :required="required" :hint="hint" :bag="bag" :error="error">
        <textarea :ref="bindEl" v-bind="textareaAttrs" :name="name" :id="id" :rows="rows" :required="required" :aria-invalid="error ? 'true' : null" :aria-describedby="describedBy" :class="control" :value="controlled ? modelValue : typed" @input="onInput"></textarea>
    </UiField>
    <textarea v-else :ref="bindEl" v-bind="textareaAttrs" :name="name" :id="id" :rows="rows" :required="required" :aria-invalid="error ? 'true' : null" :class="control" :value="controlled ? modelValue : typed" @input="onInput"></textarea>
</template>
