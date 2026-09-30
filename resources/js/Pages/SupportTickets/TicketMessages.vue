<script setup>
/**
 * Luồng hội thoại ticket (cũ trước, mới nhất ở cuối) — dùng chung trang và modal. Ảnh đính kèm: trang đầy đủ phóng to bằng
 * lightbox (emit `zoom`); trong modal mở ảnh ở tab mới (không lồng lớp phủ trong modal).
 * Cuối danh sách: phản hồi đang gửi (pendingReplies) — "Đang gửi…" hoặc "Chưa gửi được" + Gửi lại / Bỏ.
 */
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { usePendingReplies } from './pendingReplies';

const props = defineProps({
    messages: { type: Array, default: () => [] },
    asModal: { type: Boolean, default: false },
});
const emit = defineEmits(['zoom']);

const pending = usePendingReplies();
const page = usePage();
const me = computed(() => page.props.shell?.user?.name ?? '');

const IMAGE_EXTS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
const initial = (name) => [...(name || 'U')][0];

function zoom(event, url) {
    if (props.asModal || event.ctrlKey || event.metaKey || event.shiftKey) return;
    event.preventDefault();
    emit('zoom', url);
}
</script>

<template>
    <div class="space-y-4" data-ticket-messages>
        <div v-for="msg in messages" :key="msg.id" :data-message-id="msg.id" :class="['space-y-3 rounded-2xl border bg-surface-container-lowest p-5 shadow-sm', msg.is_internal_note ? 'border-warning/30 bg-warning-container/20' : 'border-surface-container-highest']">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2.5">
                    <div class="flex h-8 w-8 items-center justify-center rounded-full bg-primary-container/10 text-xs font-bold text-primary">{{ initial(msg.user) }}</div>
                    <div>
                        <div class="flex items-center gap-2 text-xs font-bold text-on-surface">
                            <span>{{ msg.user }}</span>
                            <UiBadge v-if="msg.is_internal_note" color="warning">Ghi chú nội bộ</UiBadge>
                        </div>
                        <div class="font-mono text-xs text-on-surface-subtle">{{ formatDate(msg.created_at, 'd/m/Y H:i') }}</div>
                    </div>
                </div>
            </div>

            <div class="whitespace-pre-line pl-10 text-xs leading-relaxed text-on-surface">{{ msg.message }}</div>

            <div v-if="msg.attachments.length" class="pl-10 pt-2">
                <div class="mb-2 flex items-center gap-1 text-xs font-bold text-on-surface-variant">
                    <span class="material-symbols-outlined text-[15px] text-primary">attach_file</span>
                    <span>Tệp / Hình ảnh đính kèm ({{ msg.attachments.length }}):</span>
                </div>
                <div class="grid grid-cols-2 gap-2.5 sm:grid-cols-3">
                    <template v-for="file in msg.attachments" :key="file.url">
                        <a v-if="IMAGE_EXTS.includes(file.ext)" :href="file.url" target="_blank" rel="noopener" class="group relative block cursor-pointer overflow-hidden rounded-xl border border-surface-container-highest bg-surface-container-low transition hover:shadow-md" @click="zoom($event, file.url)">
                            <div class="flex h-28 items-center justify-center overflow-hidden bg-surface-container">
                                <img :src="file.url" alt="Attachment" class="h-full w-full object-cover transition duration-300 group-hover:scale-105" />
                            </div>
                            <div class="flex items-center justify-between bg-surface-container-lowest p-1.5">
                                <span class="max-w-[120px] truncate text-xs font-medium text-on-surface-variant">{{ file.name }}</span>
                                <span class="material-symbols-outlined text-xs text-on-surface-subtle group-hover:text-primary">zoom_in</span>
                            </div>
                        </a>
                        <a v-else :href="file.url" target="_blank" class="group flex items-center gap-2 rounded-xl border border-surface-container-highest bg-surface-container-lowest p-2.5 shadow-sm transition hover:bg-surface-container-low">
                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-primary-container/10 text-xs font-bold text-primary">{{ file.ext.toUpperCase() }}</div>
                            <div class="min-w-0 flex-1">
                                <div class="truncate text-xs font-bold text-on-surface group-hover:text-primary">{{ file.name }}</div>
                                <div class="text-xs text-on-surface-subtle">Nhấn để tải về</div>
                            </div>
                            <span class="material-symbols-outlined text-sm text-on-surface-subtle group-hover:text-primary">download</span>
                        </a>
                    </template>
                </div>
            </div>
        </div>

        <div
            v-for="item in pending"
            :key="'pending-' + item.key"
            :class="[
                'space-y-3 rounded-2xl border bg-surface-container-lowest p-5 shadow-sm transition-opacity',
                item.internal ? 'bg-warning-container/20' : '',
                item.state === 'failed' ? 'border-error/40' : item.internal ? 'border-warning/30' : 'border-surface-container-highest',
                item.state === 'sending' ? 'opacity-70' : '',
            ]"
            data-message-pending
            :data-state="item.state"
        >
            <div class="flex items-center justify-between gap-sm">
                <div class="flex items-center gap-2.5">
                    <div class="flex h-8 w-8 items-center justify-center rounded-full bg-primary-container/10 text-xs font-bold text-primary">{{ initial(me) }}</div>
                    <div>
                        <div class="flex items-center gap-2 text-xs font-bold text-on-surface">
                            <span>{{ me }}</span>
                            <UiBadge v-if="item.internal" color="warning">Ghi chú nội bộ</UiBadge>
                        </div>
                        <div class="font-mono text-xs text-on-surface-subtle">Vừa xong</div>
                    </div>
                </div>
                <span v-if="item.state === 'sending'" class="inline-flex items-center gap-1 text-xs font-medium text-on-surface-subtle" role="status">
                    <span class="material-symbols-outlined animate-spin text-[16px]" aria-hidden="true">progress_activity</span>
                    <span>{{ item.fileCount > 0 ? `Đang gửi kèm ${item.fileCount} tệp…` : 'Đang gửi…' }}</span>
                </span>
            </div>
            <div class="whitespace-pre-line pl-10 text-xs leading-relaxed text-on-surface">{{ item.text }}</div>
            <div v-if="item.state === 'failed'" class="flex flex-wrap items-center gap-sm pl-10 text-xs font-medium text-error" role="alert">
                <span class="material-symbols-outlined text-[16px]" aria-hidden="true">error</span>
                <span class="flex-1">Chưa gửi được: {{ item.error }}</span>
                <UiButton size="sm" variant="secondary" icon="refresh" @click="item.retry()">Gửi lại</UiButton>
                <UiButton size="sm" variant="ghost" @click="item.discard()">Bỏ</UiButton>
            </div>
        </div>
    </div>
</template>
