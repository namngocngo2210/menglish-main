<script setup>
/**
 * Chăm sóc tháng đầu trên hồ sơ khách (chỉ khi đã có hồ sơ học viên): tick mốc chăm sóc + ghi chú, lưu vào crm.customers.care-checklist.
 * Bấm vào dòng đổi biểu tượng tick ngay; checkbox ẩn vẫn gửi đi khi Lưu.
 */
import { computed, reactive, ref, watch } from 'vue';
import { can } from '@/lib/can';

const props = defineProps({
    customerId: { type: Number, required: true },
    items: { type: Array, required: true },
    card: { type: String, required: true },
});
const checked = reactive({});
const sync = () => props.items.forEach((item) => (checked[item.key] = item.done));
sync();
watch(() => props.items, sync);
const doneCount = computed(() => props.items.filter((item) => item.done).length);
const dirty = ref(false);
const editable = computed(() => can('lead.update'));
// Nút lưu kiểu phụ, chỉ tô cam khi form đang được sửa.
const dirtySave = '!border-transparent !bg-primary-container !text-white hover:!bg-primary';
</script>

<template>
    <UiForm
        :action="route('crm.customers.care-checklist', customerId)"
        method="post"
        :class="[card, 'space-y-md p-lg']"
        :reset-on-success="['note']"
        @input="dirty = true"
        @change="dirty = true"
        @success="dirty = false"
    >
        <div class="flex items-center justify-between gap-sm">
            <h3 class="font-h3 text-h3 text-on-surface">Chăm sóc tháng đầu</h3>
            <span class="font-caption text-caption text-on-surface-variant">{{ doneCount }}/{{ items.length }} mốc</span>
        </div>
        <div class="space-y-sm">
            <label v-for="item in items" :key="item.key" :class="['flex cursor-pointer items-start gap-sm rounded-lg p-sm', item.done ? 'bg-tertiary/5' : 'hover:bg-surface-container-low']">
                <input v-model="checked[item.key]" type="checkbox" name="items[]" :value="item.key" :disabled="!editable" class="peer sr-only" />
                <span :class="['material-symbols-outlined', checked[item.key] ? 'text-tertiary' : 'text-outline']" :style="checked[item.key] ? { fontVariationSettings: `'FILL' 1` } : null">{{ checked[item.key] ? 'check_circle' : 'radio_button_unchecked' }}</span>
                <span class="min-w-0">
                    <span :class="['block font-body-medium text-body-medium', item.done ? 'text-on-surface' : 'text-on-surface-variant']">{{ item.label }}</span>
                    <span v-if="item.done_at" class="block font-caption text-caption text-on-surface-variant">Hoàn thành: {{ item.done_at }}{{ item.by ? ' · ' + item.by : '' }}</span>
                </span>
            </label>
        </div>
        <div v-if="editable" class="flex items-center gap-sm">
            <div class="flex-1"><UiInput name="note" maxlength="1000" placeholder="Ghi chú chăm sóc (tuỳ chọn)" aria-label="Ghi chú chăm sóc" class="font-body-small text-body-small" /></div>
            <UiButton type="submit" variant="secondary" size="sm" icon="save" :class="dirty ? dirtySave : ''">Lưu checklist</UiButton>
        </div>
    </UiForm>
</template>
