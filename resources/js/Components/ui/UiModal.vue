<script setup>
/**
 * Hộp thoại (như <x-ui.modal>): overlay + panel, đóng bằng Esc / bấm nền / nút X.
 *   <UiModal :show="confirmDelete" title="Xóa lớp học?" max-width="md" @close="confirmDelete = false">
 *       <p>Hành động này không thể hoàn tác.</p>
 *       <template #footer>
 *           <UiButton variant="secondary" @click="confirmDelete = false">Hủy</UiButton>
 *           <UiButton variant="danger" type="submit" form="delete-form">Xóa</UiButton>
 *       </template>
 *   </UiModal>
 * Props: show, title, maxWidth (sm|md|lg|xl|2xl|3xl|4xl|full — từ 2xl: toàn màn trên điện thoại), bare (slot tự dựng khung),
 *        dismissUrl (URL đặt lại lên thanh địa chỉ khi đóng, không tải trang — modal chi tiết mở sẵn theo ?selected_id=…)
 * Hành vi: giữ focus trong modal, đóng thì trả focus về chỗ cũ, khoá cuộn trang nền; mở chồng modal: Esc chỉ đóng modal trên cùng.
 *   Form bên trong đã sửa mà bấm nền / Esc / X → hỏi "Bỏ các thay đổi chưa lưu?" (đóng bằng code / sau khi lưu thì không hỏi).
 * Nội dung luôn nằm trong trang (ẩn bằng v-show) để server render đủ HTML.
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue';
import { confirmDialog } from '@/lib/confirm';
import { focusables, isTopModal, pushModal, removeModal } from './modalStack';

const props = defineProps({
    show: { type: Boolean, default: false },
    title: { type: String, default: null },
    maxWidth: { type: String, default: 'lg' },
    bare: { type: Boolean, default: false },
    dismissUrl: { type: String, default: null },
    closeable: { type: Boolean, default: true },
    labelledby: { type: String, default: null },
});
const emit = defineEmits(['close', 'closed']);

const widths = {
    sm: 'sm:max-w-sm', md: 'sm:max-w-md', lg: 'sm:max-w-lg', xl: 'sm:max-w-xl',
    '2xl': 'sm:max-w-2xl', '3xl': 'sm:max-w-3xl', '4xl': 'sm:max-w-4xl', full: 'sm:max-w-none',
};
const uid = useId();
const titleId = `modal-${uid.replace(/[^A-Za-z0-9_-]/g, '')}-title`;
const panel = ref(null);
const dirty = ref(false);
let confirming = false;
let previousFocus = null;

const size = computed(() => (widths[props.maxWidth] ? props.maxWidth : 'lg'));
const large = computed(() => ['2xl', '3xl', '4xl', 'full'].includes(size.value));

async function requestClose(force = false) {
    if (!props.show || confirming || !props.closeable) return;
    if (!force && dirty.value) {
        confirming = true;
        const discard = await confirmDialog({ title: 'Đóng biểu mẫu?', message: 'Các thay đổi chưa lưu sẽ bị mất.', confirmLabel: 'Bỏ thay đổi', cancelLabel: 'Tiếp tục sửa', danger: true });
        confirming = false;
        if (!discard) return;
    }
    emit('close');
}

function onKeydown(event) {
    if (!props.show || !isTopModal(uid)) return;
    if (event.key === 'Escape' && !event.defaultPrevented) {
        event.preventDefault();
        requestClose();
    } else if (event.key === 'Tab' && panel.value) {
        const items = focusables(panel.value);
        if (!items.length) return event.preventDefault();
        const [first, last] = [items[0], items.at(-1)];
        if (!panel.value.contains(document.activeElement)) {
            event.preventDefault();
            first.focus();
        } else if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }
}

async function opened() {
    dirty.value = false;
    previousFocus = document.activeElement;
    pushModal(uid);
    await nextTick();
    const target = panel.value?.querySelector('[autofocus]') ?? (panel.value ? focusables(panel.value).find((el) => !el.matches('[data-modal-close]')) : null);
    (target ?? panel.value)?.focus({ preventScroll: true });
}

function closedNow() {
    removeModal(uid);
    dirty.value = false;
    if (props.dismissUrl) history.replaceState(history.state, '', props.dismissUrl);
    previousFocus?.focus?.({ preventScroll: true });
    previousFocus = null;
}

watch(() => props.show, (open) => (open ? opened() : closedNow()));
onMounted(() => {
    window.addEventListener('keydown', onKeydown);
    if (props.show) opened();
});
onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown);
    removeModal(uid);
});

defineExpose({ requestClose, markClean: () => (dirty.value = false) });
</script>

<template>
    <div v-show="show" class="fixed inset-0 z-50 flex items-end justify-center sm:items-center sm:px-md sm:py-lg" :class="large ? 'p-0' : 'px-md py-lg'" role="dialog" aria-modal="true" :aria-labelledby="labelledby ?? titleId" data-modal>
        <Transition enter-active-class="transition-opacity duration-200" enter-from-class="opacity-0" leave-active-class="transition-opacity duration-150" leave-to-class="opacity-0">
            <div v-show="show" class="fixed inset-0 bg-on-surface/40 backdrop-blur-xs" @click="requestClose()"></div>
        </Transition>
        <Transition
            enter-active-class="ease-out duration-200"
            enter-from-class="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
            enter-to-class="opacity-100 translate-y-0 sm:scale-100"
            leave-active-class="ease-in duration-150"
            leave-from-class="opacity-100 sm:scale-100"
            leave-to-class="opacity-0 sm:scale-95"
            @after-leave="emit('closed')"
        >
            <div
                v-show="show"
                ref="panel"
                tabindex="-1"
                :class="[widths[size], large ? 'h-full rounded-none' : 'rounded-xl', large && size !== 'full' ? 'sm:h-auto' : '']"
                class="relative flex max-h-full w-full flex-col overflow-hidden bg-surface-container-lowest shadow-level-3 transition-all focus:outline-none sm:rounded-xl"
                @input="dirty = true"
                @change="dirty = true"
            >
                <slot v-if="bare" :close="requestClose" :title-id="titleId" />
                <template v-else>
                    <div class="flex shrink-0 items-center justify-between gap-md border-b border-surface-container px-lg py-md">
                        <h3 :id="titleId" class="font-h3 text-h3 text-on-surface"><slot name="title">{{ title }}</slot></h3>
                        <button type="button" class="rounded-lg p-xs text-on-surface-variant hover:bg-surface-container-high" aria-label="Đóng" data-modal-close @click="requestClose()">
                            <span class="material-symbols-outlined" aria-hidden="true">close</span>
                        </button>
                    </div>
                    <div class="min-h-0 flex-1 overflow-y-auto px-lg py-md font-body-base text-body-base text-on-surface"><slot /></div>
                    <div v-if="$slots.footer" class="flex shrink-0 flex-wrap justify-end gap-sm border-t border-surface-container bg-surface-container-low px-lg py-md"><slot name="footer" /></div>
                </template>
            </div>
        </Transition>
    </div>
</template>
