<script setup>
/**
 * Ô đính kèm của ticket (tạo ticket + ô trả lời): chọn / kéo thả file vào input `attachments[]`, dán ảnh từ clipboard
 * (gửi base64 qua hidden `pasted_images`). Nằm trong <UiForm>: form gửi FormData nên file lên server như form thường.
 *   compact: ô gọn một dòng (ô trả lời).   reset(): xoá file đã chọn (gọi sau khi gửi thành công).
 */
import { onBeforeUnmount, onMounted, ref } from 'vue';

defineProps({ compact: { type: Boolean, default: false } });

const input = ref(null);
const dragging = ref(false);
const files = ref([]); // File đã chọn / kéo thả (đồng bộ vào input để form gửi đi)
const pasted = ref([]); // Ảnh dán: [{ id, data }]
const previews = ref([]);
let seq = 0;

function sync() {
    if (!input.value || typeof DataTransfer === 'undefined') return;
    const transfer = new DataTransfer();
    files.value.forEach((file) => transfer.items.add(file));
    input.value.files = transfer.files;
}

function addFiles(list) {
    for (const file of list) {
        const isImage = file.type.startsWith('image/');
        files.value.push(file);
        previews.value.push({
            key: ++seq,
            file,
            name: file.name,
            url: isImage ? URL.createObjectURL(file) : '',
            isImage,
            ext: file.name.split('.').pop(),
            size: (file.size / 1024 / 1024).toFixed(2) + ' MB',
        });
    }
    sync();
}

function onSelect(event) {
    // Input đã giữ file mới chọn; gộp với file chọn trước đó rồi đồng bộ lại.
    addFiles([...event.target.files].filter((file) => !files.value.includes(file)));
}

function onDrop(event) {
    dragging.value = false;
    addFiles(event.dataTransfer?.files ?? []);
}

function onPaste(event) {
    for (const item of event.clipboardData?.items ?? []) {
        if (!item.type.startsWith('image/')) continue;
        const blob = item.getAsFile();
        const reader = new FileReader();
        reader.onload = (e) => {
            const id = ++seq;
            pasted.value.push({ id, data: e.target.result });
            previews.value.push({
                key: id,
                pastedId: id,
                name: `Ảnh chụp màn hình (${previews.value.length + 1})`,
                url: e.target.result,
                isImage: true,
                size: Math.round(blob.size / 1024) + ' KB',
            });
        };
        reader.readAsDataURL(blob);
    }
}

function remove(index) {
    const item = previews.value[index];
    if (item.pastedId) pasted.value = pasted.value.filter((p) => p.id !== item.pastedId);
    if (item.file) {
        files.value = files.value.filter((file) => file !== item.file);
        sync();
    }
    if (item.url && item.file) URL.revokeObjectURL(item.url);
    previews.value.splice(index, 1);
}

function reset() {
    previews.value.forEach((item) => item.file && item.url && URL.revokeObjectURL(item.url));
    files.value = [];
    pasted.value = [];
    previews.value = [];
    sync();
}

onMounted(() => window.addEventListener('paste', onPaste));
onBeforeUnmount(() => window.removeEventListener('paste', onPaste));

defineExpose({ reset });
</script>

<template>
    <div :class="compact ? 'space-y-2' : 'space-y-2 pt-2'">
        <label v-if="!compact" class="block font-semibold text-on-surface-variant">Hình ảnh đính kèm minh chứng / Ảnh chụp màn hình lỗi</label>

        <div
            :class="[
                compact
                    ? 'flex cursor-pointer items-center justify-center gap-2 rounded-xl border border-dashed bg-surface-container-low/50 p-3 text-center transition hover:bg-primary-container/5'
                    : 'relative flex cursor-pointer flex-col items-center justify-center gap-2 rounded-2xl border-2 border-dashed bg-surface-container-low/50 p-6 text-center transition hover:bg-primary-container/5',
                dragging ? 'border-primary-container bg-primary-container/5 ring-2 ring-primary-container/20' : 'border-outline-variant hover:border-primary-container',
            ]"
            @dragover.prevent="dragging = true"
            @dragleave.prevent="dragging = false"
            @drop.prevent="onDrop"
            @click="input?.click()"
        >
            <input ref="input" type="file" name="attachments[]" multiple accept="image/*,.pdf,.doc,.docx,.xlsx" class="hidden" @change="onSelect" />
            <input type="hidden" name="pasted_images" :value="JSON.stringify(pasted.map((p) => p.data))" />

            <template v-if="compact">
                <span class="material-symbols-outlined text-base text-primary">add_photo_alternate</span>
                <span class="text-xs font-medium text-on-surface-variant">Kéo thả ảnh hoặc <span class="text-primary underline">chọn ảnh</span> / Dán trực tiếp (Ctrl+V)</span>
            </template>
            <template v-else>
                <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-primary-container/10 text-primary shadow-inner">
                    <span class="material-symbols-outlined text-2xl">cloud_upload</span>
                </div>
                <div class="space-y-1">
                    <p class="text-xs font-bold text-on-surface">Kéo thả ảnh chụp lỗi vào đây, hoặc <span class="text-primary underline">chọn từ thiết bị</span></p>
                    <p class="text-xs text-on-surface-variant">Hỗ trợ: PNG, JPG, GIF, WEBP hoặc tài liệu PDF/Excel (Tối đa 15MB/file)</p>
                    <div class="mt-1 inline-flex items-center gap-1 rounded-full bg-primary-container/10 px-2.5 py-0.5 text-xs font-semibold text-primary">
                        <span class="material-symbols-outlined text-[13px]">content_paste</span>
                        <span>Có thể dán trực tiếp ảnh từ Clipboard (Ctrl + V / Cmd + V)</span>
                    </div>
                </div>
            </template>
        </div>

        <!-- Xem trước file đã chọn / ảnh đã dán -->
        <div v-if="previews.length" :class="compact ? 'grid grid-cols-2 gap-2 pt-1 sm:grid-cols-4' : 'grid grid-cols-2 gap-3 pt-3 sm:grid-cols-4'">
            <div v-for="(item, index) in previews" :key="item.key" :class="['group relative flex flex-col overflow-hidden border border-surface-container-highest bg-surface-container-lowest shadow-sm', compact ? 'rounded-lg p-1' : 'rounded-xl p-1.5']">
                <div :class="['relative flex items-center justify-center overflow-hidden bg-surface-container', compact ? 'h-16 rounded' : 'h-24 rounded-lg']">
                    <img v-if="item.isImage" :src="item.url" class="h-full w-full object-cover" alt="" />
                    <span v-else-if="compact" class="text-xs font-bold uppercase text-on-surface-variant">{{ item.ext }}</span>
                    <div v-else class="flex flex-col items-center gap-1 text-on-surface-variant">
                        <span class="material-symbols-outlined text-2xl">draft</span>
                        <span class="text-xs font-bold uppercase">{{ item.ext }}</span>
                    </div>
                    <button type="button" :class="['absolute flex items-center justify-center rounded-full bg-error text-white opacity-90 shadow transition hover:opacity-100', compact ? 'right-0.5 top-0.5 h-5 w-5' : 'right-1 top-1 h-6 w-6']" title="Xóa tệp này" aria-label="Xóa tệp này" @click.stop="remove(index)">
                        <span :class="['material-symbols-outlined', compact ? 'text-xs' : 'text-sm']">close</span>
                    </button>
                </div>
                <div v-if="compact" class="mt-0.5 truncate px-0.5 text-xs font-medium text-on-surface">{{ item.name }}</div>
                <div v-else class="mt-1 px-1">
                    <div class="truncate text-xs font-medium text-on-surface">{{ item.name }}</div>
                    <div class="font-mono text-xs text-on-surface-subtle">{{ item.size }}</div>
                </div>
            </div>
        </div>
    </div>
</template>
