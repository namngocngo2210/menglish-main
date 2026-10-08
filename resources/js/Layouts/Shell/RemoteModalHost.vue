<script setup>
/**
 * Modal DUY NHẤT cho trang tải từ server (lib/remoteModal.js) — đặt 1 lần trong AppLayout.
 * Trang hiển thị bên trong nhận ngữ cảnh modal (useRemoteModal) → UiModalFrame dựng khung modal.
 */
import { computed, provide, ref, watch } from 'vue';
import UiModal from '@/Components/ui/UiModal.vue';
import { REMOTE_MODAL } from '@/Components/ui/modalContext';
import { clearRemoteModal, closeRemoteModal, reloadRemoteModal, remoteModal } from '@/lib/remoteModal';

const modalRef = ref(null);
const titleId = 'modal-remote-title';

provide(REMOTE_MODAL, {
    titleId,
    close: () => modalRef.value?.requestClose(),
    forceClose: closeRemoteModal,
    reload: reloadRemoteModal,
    markClean: () => modalRef.value?.markClean(),
});

const open = computed(() => remoteModal.open);
// Nội dung mới (mở modal khác / tải lại) → bỏ đánh dấu "đã sửa".
watch(() => remoteModal.key, () => modalRef.value?.markClean());
</script>

<template>
    <UiModal ref="modalRef" :show="open" :max-width="remoteModal.size" bare :labelledby="titleId" @close="closeRemoteModal" @closed="clearRemoteModal">
        <div class="flex min-h-0 flex-1 flex-col" aria-live="polite" :aria-busy="remoteModal.loading ? 'true' : null" data-remote-modal>
            <div v-if="remoteModal.loading || !remoteModal.component" class="flex min-h-0 flex-1 flex-col">
                <span :id="titleId" class="sr-only">Đang tải…</span>
                <div class="flex shrink-0 items-center justify-between gap-md border-b border-surface-container px-lg py-md">
                    <div class="h-6 w-1/2 animate-pulse rounded bg-surface-container-high"></div>
                    <button type="button" class="rounded-lg p-xs text-on-surface-variant hover:bg-surface-container-high" aria-label="Đóng" @click="closeRemoteModal()">
                        <span class="material-symbols-outlined" aria-hidden="true">close</span>
                    </button>
                </div>
                <div class="space-y-md px-lg py-md">
                    <div v-for="i in 3" :key="i" class="space-y-xs">
                        <div class="h-3 w-1/4 animate-pulse rounded bg-surface-container-high"></div>
                        <div class="h-10 w-full animate-pulse rounded-lg bg-surface-container"></div>
                    </div>
                </div>
            </div>
            <component :is="remoteModal.component" v-else v-bind="remoteModal.props" :key="remoteModal.key" />
        </div>
    </UiModal>
</template>
