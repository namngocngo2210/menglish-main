<script setup>
/**
 * Khung cho trang dùng được cả trong modal lẫn trang đầy đủ (thay <x-ui.modal-frame>).
 * Mở bằng <UiButton :href modal> → khung modal (header + thân cuộn + footer cố định);
 * mở thẳng URL → trang đầy đủ: tiêu đề trang (UiPageHeader) + khung trắng + nút ở cuối.
 *   <UiModalFrame title="Sửa ngày nghỉ" :action="route('holidays.update', holiday.id)" method="put" :back="route('holidays.index')">
 *       <UiInput name="name" label="Tên ngày nghỉ" required />
 *   </UiModalFrame>
 * Props:
 *   title, description
 *   action + method → cả khung là một <UiForm> (nút submit ở footer thuộc form); submitLabel (mặc định "Lưu thông tin", false = ẩn)
 *   cancel: nhãn nút hủy (mặc định "Hủy bỏ", false = ẩn) — trong modal: đóng modal; trang đầy đủ: về `back`
 *   back: URL quay lại khi là trang đầy đủ (dự phòng — nút Quay lại / Hủy về trang vừa mở trước đó nếu có, xem lib/backLink.js)
 *   size: đổi cỡ modal khi nội dung cần rộng hơn nút mở (vd. bước xem trước nhập Excel → 4xl)
 *   fill: trong modal (từ sm) thân không cuộn — phần tử lấp chỗ còn lại tự cuộn (vd. <UiDataTable fill> giữ tiêu đề cột cố định)
 *   pageWidth: độ rộng tối đa khi là trang đầy đủ (mặc định max-w-3xl)
 * Slots: mặc định (nội dung), footer (nút thêm, đặt trước nút submit), actions (nút cạnh tiêu đề ở trang đầy đủ)
 * Các prop khác của UiForm (confirm, danger, stay…) truyền thẳng qua `form-options`.
 */
import { computed, onMounted } from 'vue';
import { useBackLink } from '@/lib/backLink';
import { remoteModal } from '@/lib/remoteModal';
import UiButton from './UiButton.vue';
import UiForm from './UiForm.vue';
import UiPageHeader from './UiPageHeader.vue';
import { useRemoteModal } from './modalContext';

const props = defineProps({
    title: { type: String, required: true },
    description: { type: String, default: null },
    action: { type: String, default: null },
    method: { type: String, default: 'post' },
    submitLabel: { type: [String, Boolean], default: 'Lưu thông tin' },
    submitIcon: { type: String, default: null },
    submitVariant: { type: String, default: 'primary' },
    cancel: { type: [String, Boolean], default: 'Hủy bỏ' },
    back: { type: String, default: null },
    size: { type: String, default: null },
    pageWidth: { type: String, default: 'max-w-3xl' },
    fill: { type: Boolean, default: false },
    formOptions: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['success']);
const modal = useRemoteModal();
const asForm = computed(() => !!props.action);
const backLink = useBackLink(() => props.back);
const showFooter = computed(() => !!props.cancel || (asForm.value && props.submitLabel !== false));

onMounted(() => {
    if (modal && props.size) remoteModal.size = props.size;
});
</script>

<template>
    <!-- Trong modal -->
    <component
        :is="asForm ? UiForm : 'div'"
        v-if="modal"
        v-bind="asForm ? { action, method, ...formOptions } : {}"
        class="flex min-h-0 flex-1 flex-col"
        @success="emit('success', $event)"
    >
        <div class="flex shrink-0 items-start justify-between gap-md border-b border-surface-container px-lg py-md">
            <div class="min-w-0">
                <h2 :id="modal.titleId" class="font-h3 text-h3 text-on-surface">{{ title }}</h2>
                <p v-if="description" class="mt-0.5 font-body-small text-body-small text-on-surface-variant">{{ description }}</p>
            </div>
            <button type="button" class="shrink-0 rounded-lg p-xs text-on-surface-variant hover:bg-surface-container-high" aria-label="Đóng" data-modal-close @click="modal.close()">
                <span class="material-symbols-outlined" aria-hidden="true">close</span>
            </button>
        </div>
        <div :class="['min-h-0 flex-1 space-y-md overflow-y-auto px-lg py-md font-body-base text-body-base text-on-surface', fill && 'sm:flex sm:flex-col sm:overflow-hidden']"><slot /></div>
        <div v-if="showFooter || $slots.footer" class="flex shrink-0 flex-wrap justify-end gap-sm border-t border-surface-container bg-surface-container-low px-lg py-md">
            <UiButton v-if="cancel" variant="secondary" @click="modal.close()">{{ cancel }}</UiButton>
            <slot name="footer" />
            <UiButton v-if="asForm && submitLabel !== false" type="submit" :variant="submitVariant" :icon="submitIcon">{{ submitLabel }}</UiButton>
        </div>
    </component>

    <!-- Trang đầy đủ -->
    <div v-else :class="['mx-auto', pageWidth]">
        <UiPageHeader :title="title" :description="description" :back="back">
            <template v-if="$slots.actions" #actions><slot name="actions" /></template>
        </UiPageHeader>
        <component :is="asForm ? UiForm : 'div'" v-bind="asForm ? { action, method, ...formOptions } : {}" class="rounded-xl border border-outline-variant bg-surface-container-lowest shadow-sm" @success="emit('success', $event)">
            <div class="space-y-md p-md md:p-lg"><slot /></div>
            <div v-if="showFooter || $slots.footer" class="flex flex-wrap justify-end gap-sm border-t border-surface-container px-md py-md md:px-lg">
                <UiButton v-if="cancel && back" variant="secondary" :href="backLink.href" data-back-link>{{ cancel }}</UiButton>
                <slot name="footer" />
                <UiButton v-if="asForm && submitLabel !== false" type="submit" :variant="submitVariant" :icon="submitIcon">{{ submitLabel }}</UiButton>
            </div>
        </component>
    </div>
</template>
