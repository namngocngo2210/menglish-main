<script setup>
/**
 * Nút "i" mở hướng dẫn chi tiết (bấm ra ngoài / Esc / bấm vào khung → đóng). Dùng thay cho đoạn hướng dẫn dài hiện sẵn
 * trên trang để màn hình gọn (yêu cầu 14/10/2026).
 *   <UiInfo title="Quy tắc hoàn thành nhiệm vụ">Có ảnh → hoàn thành ngay…</UiInfo>
 * align: left | right (canh khung theo nút) · width: như UiDropdown (mặc định 80).
 */
import UiDropdown from './UiDropdown.vue';

defineProps({
    title: { type: String, default: null },
    label: { type: String, default: 'Xem hướng dẫn' },
    align: { type: String, default: 'left' },
    width: { type: String, default: '80' },
});
</script>

<template>
    <UiDropdown :align="align" :width="width" content-classes="bg-surface-container-lowest p-md">
        <template #trigger="{ open }">
            <button
                type="button"
                class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-on-surface-variant transition-colors hover:bg-surface-container-high hover:text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-container/50"
                :aria-label="label"
                :title="label"
                :aria-expanded="open ? 'true' : 'false'"
                data-info-toggle
            >
                <span class="material-symbols-outlined text-[20px]" aria-hidden="true">info</span>
            </button>
        </template>
        <template #content>
            <div class="space-y-xs text-left" role="note">
                <p v-if="title" class="font-body-small text-body-small font-semibold text-on-surface">{{ title }}</p>
                <div class="space-y-xs font-body-small text-body-small leading-relaxed text-on-surface-variant"><slot /></div>
            </div>
        </template>
    </UiDropdown>
</template>
