<script setup>
/**
 * Chi tiết ticket: mở từ danh sách → modal 3xl gồm hội thoại + ô trả lời + thông tin; gửi phản hồi / đổi trạng thái /
 * phân công trong modal → modal giữ mở, tải lại nội dung mới, danh sách nền cập nhật + thông báo.
 * Mở thẳng URL → trang đầy đủ (có lightbox xem ảnh).
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { formatDate } from '@/lib/format';
import ReplyForm from './ReplyForm.vue';
import StatusForm from './StatusForm.vue';
import TicketInfo from './TicketInfo.vue';
import TicketMessages from './TicketMessages.vue';
import { providePendingReplies } from './pendingReplies';

// Lỗi gửi phản hồi hiện trên khung phản hồi tạm; lỗi các form khác hiện tại trường → không cần khối lỗi chung.
defineOptions({ layout: (props) => ({ title: props.ticket?.title, hideErrors: true }) });

const props = defineProps({
    asModal: { type: Boolean, default: false },
    ticket: { type: Object, required: true },
    messages: { type: Array, default: () => [] },
    staffs: { type: Array, default: () => [] },
    canPostInternal: { type: Boolean, default: false },
    canReopen: { type: Boolean, default: false },
});

const created = computed(() => formatDate(props.ticket.created_at, 'd/m/Y H:i'));
const lightbox = ref(null); // URL ảnh đang phóng to (trang đầy đủ)
providePendingReplies(); // phản hồi đang gửi: giữ qua các lần tải lại nội dung modal

function onKey(event) {
    if (event.key === 'Escape') lightbox.value = null;
}
onMounted(() => window.addEventListener('keydown', onKey));
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));
</script>

<template>
    <UiModalFrame v-if="asModal" :title="`#${ticket.code} · ${ticket.title}`" :description="`Tạo bởi ${ticket.creator ?? 'Hệ thống'} lúc ${created} · ${ticket.category_label}`" cancel="Đóng">
        <div class="space-y-md" data-testid="ticket-conversation">
            <div class="flex flex-wrap items-center justify-between gap-sm">
                <StatusForm :ticket="ticket" :can-reopen="canReopen" />
                <UiButton variant="ghost" size="sm" icon="open_in_new" :href="route('tickets.show', ticket.id)" native>Mở trang đầy đủ</UiButton>
            </div>
            <div class="grid grid-cols-1 gap-md lg:grid-cols-3">
                <div class="space-y-md lg:col-span-2">
                    <TicketMessages :messages="messages" as-modal />
                </div>
                <TicketInfo :ticket="ticket" :staffs="staffs" />
            </div>
            <ReplyForm :ticket="ticket" :can-post-internal="canPostInternal" as-modal />
        </div>
    </UiModalFrame>

    <template v-else>
        <UiPageHeader :title="ticket.title" :back="route('tickets.index')">
            <template #badges>
                <span class="inline-flex items-center rounded-xl border border-primary-container/30 bg-primary-container/10 px-2.5 py-1 font-mono text-xs font-bold text-primary shadow-2xs">#{{ ticket.code }}</span>
            </template>
            <template #meta>Tạo bởi {{ ticket.creator }} vào lúc {{ created }} · {{ ticket.category_label }}</template>
            <template #actions>
                <StatusForm :ticket="ticket" :can-reopen="canReopen" />
            </template>
        </UiPageHeader>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">
                <TicketMessages :messages="messages" @zoom="lightbox = $event" />
                <ReplyForm :ticket="ticket" :can-post-internal="canPostInternal" />
            </div>

            <TicketInfo :ticket="ticket" :staffs="staffs" />

            <!-- Lightbox phóng to ảnh đính kèm -->
            <div v-if="lightbox" class="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-4" @click="lightbox = null">
                <div class="relative flex max-h-[90vh] max-w-5xl flex-col items-center overflow-hidden rounded-2xl bg-transparent shadow-2xl" @click.stop>
                    <button type="button" class="absolute right-3 top-3 z-10 flex h-9 w-9 items-center justify-center rounded-full bg-black/60 text-white transition hover:bg-black/80" aria-label="Đóng" @click="lightbox = null">
                        <span class="material-symbols-outlined text-lg">close</span>
                    </button>
                    <img :src="lightbox" class="max-h-[85vh] max-w-full rounded-xl object-contain shadow-lg" alt="" />
                    <div class="mt-2 text-center">
                        <a :href="lightbox" target="_blank" download class="inline-flex items-center gap-1.5 rounded-lg bg-white/20 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-white/30">
                            <span class="material-symbols-outlined text-sm">download</span>
                            <span>Mở ảnh gốc trong tab mới</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </template>
</template>
