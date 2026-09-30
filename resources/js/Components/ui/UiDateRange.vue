<script setup>
/**
 * Khoảng ngày / tháng trong 1 ô: [từ] – [đến], chung 1 nhãn (như <x-ui.date-range>).
 *   <UiDateRange label="Ngày tạo" />
 *   <UiDateRange label="Kỳ báo cáo" type="month" from="month" to="month_to" :from-value="month" :to-value="monthTo" />
 * Giá trị mặc định lấy từ tham số trên URL.
 */
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import UiField from './UiField.vue';
import UiInput from './UiInput.vue';
import { pageErrors } from './useFieldError';

const props = defineProps({
    label: { type: String, default: null },
    from: { type: String, default: 'from' },
    to: { type: String, default: 'to' },
    fromValue: { type: String, default: null },
    toValue: { type: String, default: null },
    type: { type: String, default: 'date' },
});
const page = usePage();
const query = computed(() => new URL(page.url ?? '/', 'http://localhost').searchParams);
const fromVal = computed(() => props.fromValue ?? query.value.get(props.from));
const toVal = computed(() => props.toValue ?? query.value.get(props.to));
const errorName = computed(() => {
    const errors = pageErrors();
    return errors[props.to] && !errors[props.from] ? props.to : props.from;
});
</script>

<template>
    <UiField :label="label" :name="errorName" :for="'f_' + from" class="sm:col-span-2" data-date-range>
        <div class="flex items-center gap-xs">
            <UiInput :type="type" :name="from" :value="fromVal" class="min-w-0 flex-1" :aria-label="(label ? label + ' ' : '') + 'từ'" />
            <span class="shrink-0 text-on-surface-variant" aria-hidden="true">–</span>
            <UiInput :type="type" :name="to" :value="toVal" class="min-w-0 flex-1" :aria-label="(label ? label + ' ' : '') + 'đến'" />
        </div>
    </UiField>
</template>
