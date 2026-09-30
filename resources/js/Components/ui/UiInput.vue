<script setup>
/**
 * Ô nhập liệu chuẩn (như <x-ui.input>). Có `label` → tự bọc UiField (label, *, gợi ý, lỗi).
 * Dùng trong <UiForm> như form HTML thường (theo `name`), hoặc v-model khi cần giá trị trong Vue.
 *   <UiInput name="phone" label="Số điện thoại" required hint="10 số, bắt đầu bằng 0" />
 *   <UiInput name="amount" type="number" label="Số tiền" :value="receipt.amount" suffix="đ" />
 *   <UiInput v-model="keyword" icon="search" placeholder="Tìm..." />
 * type="password" → có nút hiện / ẩn mật khẩu.
 */
import { computed, ref, useAttrs } from 'vue';
import UiField from './UiField.vue';
import { fieldId, useFieldError, useTypedValue } from './useFieldError';

defineOptions({ inheritAttrs: false });

const props = defineProps({
    name: { type: String, default: null },
    label: { type: String, default: null },
    type: { type: String, default: 'text' },
    value: { type: [String, Number], default: null },
    modelValue: { type: [String, Number], default: undefined },
    required: { type: Boolean, default: false },
    hint: { type: String, default: null },
    icon: { type: String, default: null },
    inlineLabel: { type: String, default: null },
    suffix: { type: String, default: null },
    bag: { type: String, default: null },
    error: { type: String, default: null },
});
const emit = defineEmits(['update:modelValue']);
const attrs = useAttrs();

const id = fieldId(props, attrs);
const error = useFieldError(props);
const reveal = ref(false);
const revealable = computed(() => props.type === 'password');
const describedBy = computed(() => (props.label && id ? (error.value ? id + '-error' : props.hint ? id + '-hint' : null) : null));
const controlled = computed(() => props.modelValue !== undefined);
// Không v-model: giữ chữ đang gõ khi render lại (mật khẩu không nhận giá trị ban đầu từ server).
const { value: typed, bindEl } = useTypedValue(() => (revealable.value ? null : props.value));
const currentValue = computed(() => (controlled.value ? props.modelValue : typed.value));
const control = computed(() => [
    'w-full rounded-lg border bg-surface-container-lowest px-md py-sm font-body-base text-body-base text-on-surface placeholder:text-on-surface-subtle transition-colors focus:outline-none focus:ring-2 disabled:cursor-not-allowed disabled:bg-surface-container-low disabled:text-on-surface-variant',
    error.value ? 'border-error focus:border-error focus:ring-error/20' : 'border-outline-variant focus:border-primary-container focus:ring-primary-container/50',
    props.icon ? 'pl-10' : '',
    props.suffix ? 'pr-16' : '',
    revealable.value ? 'pr-12' : '',
]);
const inputAttrs = computed(() => {
    const { id: _id, ...rest } = attrs;
    return rest;
});
const wrapped = computed(() => props.label || props.inlineLabel || props.icon || props.suffix || revealable.value);

function onInput(event) {
    if (controlled.value) emit('update:modelValue', event.target.value);
}
</script>

<template>
    <UiField v-if="label" :label="label" :name="name" :for="id" :required="required" :hint="hint" :bag="bag" :error="error">
        <div class="relative">
            <span v-if="icon" class="material-symbols-outlined pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[20px] text-on-surface-variant" aria-hidden="true">{{ icon }}</span>
            <input :ref="bindEl" v-bind="inputAttrs" :type="revealable && reveal ? 'text' : type" :name="name" :id="id" :value="currentValue" :required="required" :aria-invalid="error ? 'true' : null" :aria-describedby="describedBy" :class="control" @input="onInput" />
            <span v-if="suffix" class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 font-body-small text-body-small text-on-surface-variant">{{ suffix }}</span>
            <button v-if="revealable" type="button" class="absolute right-1 top-1/2 flex h-10 w-10 -translate-y-1/2 items-center justify-center rounded-lg text-on-surface-variant hover:bg-surface-container-high hover:text-on-surface focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-container/40" :aria-pressed="reveal.toString()" :aria-label="reveal ? 'Ẩn mật khẩu' : 'Hiện mật khẩu'" @click="reveal = !reveal">
                <span class="material-symbols-outlined text-[20px]" aria-hidden="true">{{ reveal ? 'visibility_off' : 'visibility' }}</span>
            </button>
        </div>
    </UiField>
    <label v-else-if="wrapped" class="relative flex items-center gap-sm">
        <span v-if="inlineLabel" class="whitespace-nowrap font-body-small text-body-small font-medium text-on-surface-variant">{{ inlineLabel }}</span>
        <span v-if="icon" class="material-symbols-outlined pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[20px] text-on-surface-variant" aria-hidden="true">{{ icon }}</span>
        <input :ref="bindEl" v-bind="inputAttrs" :type="revealable && reveal ? 'text' : type" :name="name" :id="id" :value="currentValue" :required="required" :aria-invalid="error ? 'true' : null" :class="control" @input="onInput" />
        <span v-if="suffix" class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 font-body-small text-body-small text-on-surface-variant">{{ suffix }}</span>
        <button v-if="revealable" type="button" class="absolute right-1 top-1/2 flex h-10 w-10 -translate-y-1/2 items-center justify-center rounded-lg text-on-surface-variant hover:bg-surface-container-high hover:text-on-surface focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-container/40" :aria-pressed="reveal.toString()" :aria-label="reveal ? 'Ẩn mật khẩu' : 'Hiện mật khẩu'" @click="reveal = !reveal">
            <span class="material-symbols-outlined text-[20px]" aria-hidden="true">{{ reveal ? 'visibility_off' : 'visibility' }}</span>
        </button>
    </label>
    <input v-else :ref="bindEl" v-bind="inputAttrs" :type="type" :name="name" :id="id" :value="currentValue" :required="required" :aria-invalid="error ? 'true' : null" :class="control" @input="onInput" />
</template>
