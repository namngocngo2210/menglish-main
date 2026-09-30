<script setup>
/** Hộp xác nhận chung (lib/confirm.js). Esc / bấm nền = Hủy; giữ focus trong 2 nút. */
import { nextTick, ref, watch } from 'vue';
import { confirmState, settleConfirm } from '@/lib/confirm';

const cancelBtn = ref(null);
const okBtn = ref(null);
let previousFocus = null;

watch(
    () => confirmState.open,
    async (open) => {
        if (open) {
            previousFocus = document.activeElement;
            await nextTick();
            (confirmState.danger ? cancelBtn.value : okBtn.value)?.focus();
        } else {
            previousFocus?.focus?.({ preventScroll: true });
            previousFocus = null;
        }
    },
);

function onKeydown(event) {
    if (event.key === 'Escape') {
        event.stopPropagation();
        event.preventDefault();
        settleConfirm(false);
    } else if (event.key === 'Tab') {
        const [first, last] = [cancelBtn.value, okBtn.value];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }
}
const btn = 'inline-flex min-h-11 items-center justify-center rounded-lg px-md py-sm font-body-medium text-body-medium transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-container/40 md:min-h-0';
</script>

<template>
    <div v-if="confirmState.open" class="fixed inset-0 z-[70] flex items-end justify-center bg-on-surface/40 p-md sm:items-center" data-confirm-dialog @click.self="settleConfirm(false)" @keydown="onKeydown">
        <div class="w-full max-w-md rounded-xl bg-surface-container-lowest p-lg text-left shadow-level-3" role="alertdialog" aria-modal="true" aria-labelledby="confirm-dialog-title" aria-describedby="confirm-dialog-message">
            <h2 id="confirm-dialog-title" class="font-h3 text-h3 text-on-surface">{{ confirmState.title }}</h2>
            <p id="confirm-dialog-message" class="mt-sm whitespace-pre-line font-body-base text-body-base text-on-surface-variant">{{ confirmState.message }}</p>
            <div class="mt-lg flex flex-col-reverse gap-sm sm:flex-row sm:justify-end">
                <button ref="cancelBtn" type="button" :class="[btn, 'border border-outline-variant bg-surface-container-lowest text-on-surface hover:bg-surface-container-low']" @click="settleConfirm(false)">{{ confirmState.cancelLabel }}</button>
                <button ref="okBtn" type="button" :class="[btn, confirmState.danger ? 'bg-error text-white hover:bg-on-error-container' : 'bg-primary-container text-white hover:bg-primary']" @click="settleConfirm(true)">{{ confirmState.confirmLabel }}</button>
            </div>
        </div>
    </div>
</template>
