{{-- Ô gửi phản hồi ticket — dùng chung trang và modal. Trong modal form được hx-boost: gửi xong server trả lại nội dung
     modal (hội thoại mới) + toast; đính kèm dùng Alpine.data('attachmentUploader'). Biến: $ticket, $canPostInternal, $asModal. --}}
@php
    $asModal = $asModal ?? false;
@endphp
{{-- Reply Box with Drag & Drop Uploader --}}
<div class="bg-surface-container-lowest rounded-2xl border border-surface-container-highest shadow-sm p-5" x-data="attachmentUploader">
    <h3 class="text-xs font-bold text-on-surface uppercase tracking-wider mb-3 flex items-center gap-1.5">
        <span class="material-symbols-outlined text-primary text-base">reply</span>
        Gửi phản hồi / Cập nhật tiến độ
    </h3>
    <form id="{{ $asModal ? 'modal-' : '' }}ticket-reply-form" action="{{ route('tickets.messages.store', $ticket->id) }}" method="POST" enctype="multipart/form-data" class="space-y-3">
        @csrf
        <x-ui.field name="message">
            <x-ui.textarea name="message" rows="3" required placeholder="Nhập câu trả lời hoặc tiến độ giải quyết vấn đề..." class="text-xs" />
        </x-ui.field>

        {{-- Compact Drag & Drop Upload Zone for Reply --}}
        <div class="space-y-2">
            <div 
                class="border border-dashed rounded-xl p-3 text-center transition cursor-pointer flex items-center justify-center gap-2 bg-surface-container-low/50 hover:bg-primary-container/5"
                :class="isDragging ? 'border-primary-container bg-primary-container/5 ring-2 ring-primary-container/20' : 'border-outline-variant hover:border-primary-container'"
                @dragover.prevent="isDragging = true"
                @dragleave.prevent="isDragging = false"
                @drop.prevent="handleDrop($event)"
                @click="$refs.fileInput.click()"
            >
                <input 
                    type="file" 
                    x-ref="fileInput" 
                    name="attachments[]" 
                    multiple 
                    accept="image/*,.pdf,.doc,.docx,.xlsx" 
                    class="hidden" 
                    @change="handleFileSelect($event)"
                />
                <input type="hidden" name="pasted_images" :value="JSON.stringify(pastedImages)" />

                <span class="material-symbols-outlined text-base text-primary">add_photo_alternate</span>
                <span class="text-[11px] text-on-surface-variant font-medium">Kéo thả ảnh hoặc <span class="text-primary underline">chọn ảnh</span> / Dán trực tiếp (Ctrl+V)</span>
            </div>

            {{-- Previews --}}
            <template x-if="previews.length > 0">
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 pt-1">
                    <template x-for="(item, index) in previews" :key="index">
                        <div class="relative group bg-surface-container-lowest border border-surface-container-highest rounded-lg overflow-hidden shadow-sm p-1 flex flex-col">
                            <div class="h-16 rounded bg-surface-container overflow-hidden flex items-center justify-center relative">
                                <template x-if="item.isImage">
                                    <img :src="item.url" class="w-full h-full object-cover" />
                                </template>
                                <template x-if="!item.isImage">
                                    <span class="text-[9px] uppercase font-bold text-on-surface-variant" x-text="item.ext"></span>
                                </template>
                                <button 
                                    type="button" 
                                    @click.stop="removeFile(index)" 
                                    class="absolute top-0.5 right-0.5 w-5 h-5 rounded-full bg-error text-white flex items-center justify-center opacity-90 hover:opacity-100 shadow transition"
                                >
                                    <span class="material-symbols-outlined text-xs">close</span>
                                </button>
                            </div>
                            <div class="font-medium text-[10px] text-on-surface truncate px-0.5 mt-0.5" x-text="item.name"></div>
                        </div>
                    </template>
                </div>
            </template>
        </div>

        <div class="flex items-center justify-between pt-1">
            @if ($canPostInternal ?? false)
                <label class="flex items-center gap-2 text-xs text-on-surface-variant cursor-pointer">
                    <input type="checkbox" name="is_internal_note" value="1" class="rounded border-outline-variant text-warning focus:ring-warning">
                    <span>Chỉ hiển thị nội bộ giữa các phòng ban</span>
                </label>
            @else
                <span></span>
            @endif
            <x-ui.button type="submit" size="sm" icon="send">Gửi phản hồi</x-ui.button>
        </div>
    </form>
</div>
