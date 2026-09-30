<script setup>
/**
 * Dropdown chuẩn (như <x-ui.select>). Có `label` → tự bọc UiField.
 *   <UiSelect name="branch_id" label="Chi nhánh" :options="branches" placeholder="-- Chọn --" required />
 *   <UiSelect v-model="status" :options="[{ value: 'new', label: 'Mới' }]" />
 * options: mảng {value, label} (giữ thứ tự) — xem options.js. Slot mặc định: thêm <option> tự viết.
 * value mặc định: tham số cùng tên trên URL (bộ lọc giữ lựa chọn sau khi lọc).
 * searchable: ô chọn có tìm kiếm (Tom Select, kiểu select2). Mặc định bật trong UiFilterBar.
 */
import { computed, inject, nextTick, onBeforeUnmount, onMounted, ref, useAttrs, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import UiField from './UiField.vue';
import { fieldId, useFieldError } from './useFieldError';
import { normalizeOptions } from './options';

defineOptions({ inheritAttrs: false });

const props = defineProps({
    name: { type: String, default: null },
    label: { type: String, default: null },
    options: { type: [Array, Object], default: () => [] },
    value: { type: [String, Number, Boolean], default: null },
    modelValue: { type: [String, Number, Boolean, Array], default: undefined },
    placeholder: { type: String, default: null },
    required: { type: Boolean, default: false },
    hint: { type: String, default: null },
    inlineLabel: { type: String, default: null },
    bag: { type: String, default: null },
    error: { type: String, default: null },
    searchable: { type: Boolean, default: null },
    multiple: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue', 'change']);
const attrs = useAttrs();
const page = usePage();
const inFilterBar = inject('uiFilterBar', false);

const id = fieldId(props, attrs);
const error = useFieldError(props);
const el = ref(null);
let tom = null;

const items = computed(() => normalizeOptions(props.options));
const controlled = computed(() => props.modelValue !== undefined);
const queryValue = () => {
    if (!props.name) return null;
    const url = new URL(page.url ?? '/', 'http://localhost');
    return url.searchParams.get(props.name);
};
const selected = computed(() => {
    const v = controlled.value ? props.modelValue : (props.value ?? queryValue());
    return v === null || v === undefined ? '' : Array.isArray(v) ? v.map(String) : String(v);
});
const isSelected = (value) => (Array.isArray(selected.value) ? selected.value.includes(String(value)) : String(value) === selected.value);
const describedBy = computed(() => (props.label && id ? (error.value ? id + '-error' : props.hint ? id + '-hint' : null) : null));
const ariaLabel = computed(() => attrs['aria-label'] ?? (!props.label && !props.inlineLabel && props.placeholder ? props.placeholder : null));
const control = computed(() => [
    'w-full min-w-[150px] rounded-lg border bg-surface-container-lowest py-sm pl-md pr-xl font-body-base text-body-base text-on-surface transition-colors focus:outline-none focus:ring-2 disabled:cursor-not-allowed disabled:bg-surface-container-low',
    error.value ? 'border-error focus:border-error focus:ring-error/20' : 'border-outline-variant focus:border-primary-container focus:ring-primary-container/50',
]);
const selectAttrs = computed(() => {
    const { id: _id, 'aria-label': _a, onChange: _c, ...rest } = attrs;
    return rest;
});
const useSearch = computed(() => !props.multiple && (props.searchable ?? inFilterBar));

function onChange(event) {
    const target = event.target;
    const value = props.multiple ? [...target.selectedOptions].map((o) => o.value) : target.value;
    if (controlled.value) emit('update:modelValue', value);
    emit('change', event);
    attrs.onChange?.(event);
}

// Tên cho trình đọc màn hình: aria-label → <label for> → chữ "Tất cả …" của option rỗng.
function accessibleName(select) {
    const fromLabel = select.id ? document.querySelector(`label[for="${CSS.escape(select.id)}"]`)?.textContent : null;
    return (select.getAttribute('aria-label') || fromLabel || props.placeholder || '').replace(/\s+/g, ' ').trim();
}

async function enhance() {
    if (!useSearch.value || !el.value || tom) return;
    const { default: TomSelect } = await import('tom-select/base');
    const { default: DropdownInput } = await import('tom-select/plugins/dropdown_input/plugin.js');
    TomSelect.define('dropdown_input', DropdownInput);
    if (!el.value) return;
    const select = el.value;
    tom = new TomSelect(select, {
        allowEmptyOption: true,
        maxOptions: null,
        plugins: ['dropdown_input'],
        placeholder: props.placeholder ?? undefined,
        render: { no_results: () => '<div class="no-results">Không tìm thấy</div>' },
        onInitialize() {
            // Chỉ giữ class bố cục trên khung bao; viền / nền / chữ do .ts-control trong app.css.
            // Giữ cả class trạng thái của Tom Select (has-items, full…): thiếu has-items thì placeholder hiện chồng lên lựa chọn.
            this.wrapper.className = this.wrapper.className
                .split(' ')
                .filter((c) => !c || /^(?:[a-z]+:)*(?:w-|min-w-|max-w-|flex-|basis-|grow|shrink|col-|self-|order-|ts-|single|plugin-|hidden$|block$)/.test(c) || /^(?:has-items|has-options|full|input-active|input-hidden|disabled|required|invalid)$/.test(c))
                .join(' ');
            this.control_input?.setAttribute('placeholder', 'Gõ để tìm…');
            const label = accessibleName(select);
            if (label) [select, this.control, this.control_input].forEach((node) => node?.setAttribute('aria-label', label));
        },
    });
}

onMounted(enhance);
onBeforeUnmount(() => {
    tom?.destroy();
    tom = null;
});
watch(items, async () => {
    if (!tom) return;
    await nextTick();
    tom.clearOptions();
    tom.sync();
});
watch(selected, (value) => {
    if (tom && String(tom.getValue()) !== String(value)) tom.setValue(value, true);
});
</script>

<template>
    <UiField v-if="label" :label="label" :name="name" :for="id" :required="required" :hint="hint" :bag="bag" :error="error">
        <select ref="el" v-bind="selectAttrs" :name="name" :id="id" :required="required" :multiple="multiple" :aria-invalid="error ? 'true' : null" :aria-describedby="describedBy" :aria-label="ariaLabel" :class="control" @change="onChange">
            <option v-if="placeholder !== null" value="" :selected="selected === ''">{{ placeholder }}</option>
            <option v-for="opt in items" :key="String(opt.value)" :value="opt.value" :selected="isSelected(opt.value)" :disabled="opt.disabled">{{ opt.label }}</option>
            <slot />
        </select>
    </UiField>
    <label v-else-if="inlineLabel" class="flex items-center gap-sm">
        <span class="whitespace-nowrap font-body-small text-body-small font-medium text-on-surface-variant">{{ inlineLabel }}</span>
        <select ref="el" v-bind="selectAttrs" :name="name" :id="id" :required="required" :multiple="multiple" :aria-invalid="error ? 'true' : null" :aria-label="ariaLabel" :class="control" @change="onChange">
            <option v-if="placeholder !== null" value="" :selected="selected === ''">{{ placeholder }}</option>
            <option v-for="opt in items" :key="String(opt.value)" :value="opt.value" :selected="isSelected(opt.value)" :disabled="opt.disabled">{{ opt.label }}</option>
            <slot />
        </select>
    </label>
    <select v-else ref="el" v-bind="selectAttrs" :name="name" :id="id" :required="required" :multiple="multiple" :aria-invalid="error ? 'true' : null" :aria-label="ariaLabel" :class="control" @change="onChange">
        <option v-if="placeholder !== null" value="" :selected="selected === ''">{{ placeholder }}</option>
        <option v-for="opt in items" :key="String(opt.value)" :value="opt.value" :selected="isSelected(opt.value)" :disabled="opt.disabled">{{ opt.label }}</option>
        <slot />
    </select>
</template>
