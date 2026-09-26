{{-- Form tạo ticket — dùng chung trang (support-tickets/create) và modal ($asModal: nút Gửi ở footer modal).
     Đính kèm: Alpine.data('attachmentUploader') (resources/js/modules/attachment-uploader.js) — chọn / kéo thả / dán ảnh.
     Form multipart → htmx gửi FormData (file vẫn lên server). Biến: $staffs, $asModal. --}}
@php
    $asModal = $asModal ?? false;
@endphp
<form id="{{ $asModal ? 'modal-' : '' }}ticket-form" action="{{ route('tickets.store') }}" method="POST" enctype="multipart/form-data"
      x-data="attachmentUploader" @class(['space-y-6', 'bg-surface-container-lowest rounded-2xl border border-surface-container-highest shadow-sm p-6' => ! $asModal])>
    @csrf

    <div class="space-y-4 text-xs">
        <x-ui.input name="title" label="Tiêu đề sự cố / yêu cầu" required placeholder="Ví dụ: Lỗi không xuất được hóa đơn điện tử cho học viên HV-0012" class="text-xs font-bold" />

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <x-ui.select name="category" label="Phân loại danh mục" required class="text-xs font-semibold"
                         :options="['technical_issue' => 'Lỗi Hệ Thống / IT', 'curriculum' => 'Giáo Trình / Học Vụ', 'tuition' => 'Học Phí / Hóa Đơn', 'customer_complaint' => 'Khiếu Nại Học Viên', 'other' => 'Yêu Cầu Hỗ Trợ Khác']" />

            <x-ui.select name="priority" label="Mức độ ưu tiên" required class="text-xs font-semibold" value="medium"
                         :options="['low' => 'Thấp (Low)', 'medium' => 'Trung bình (Medium)', 'high' => 'Cao (High)', 'urgent' => 'Khẩn cấp (Urgent)']" />

            <x-ui.select name="assignee_id" label="Phân công người xử lý" class="text-xs" placeholder="-- Để mở (Chưa gán) --"
                         :options="$staffs->pluck('name', 'id')" />
        </div>

        <x-ui.textarea name="description" label="Mô tả chi tiết sự cố / Nội dung yêu cầu" rows="4" required class="text-xs"
                       placeholder="Mô tả cụ thể các bước tái hiện lỗi, đường dẫn URL bị lỗi hoặc yêu cầu nghiệp vụ cần xử lý..." />

        {{-- Drag and Drop Image Upload Zone --}}
        <div class="space-y-2 pt-2">
            <label class="block font-semibold text-on-surface-variant">
                Hình ảnh đính kèm minh chứng / Ảnh chụp màn hình lỗi
            </label>
            
            <div 
                class="border-2 border-dashed rounded-2xl p-6 text-center transition cursor-pointer flex flex-col items-center justify-center gap-2 relative bg-surface-container-low/50 hover:bg-primary-container/5"
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

                <div class="w-12 h-12 rounded-2xl bg-primary-container/10 text-primary flex items-center justify-center shadow-inner">
                    <span class="material-symbols-outlined text-2xl">cloud_upload</span>
                </div>
                <div class="space-y-1">
                    <p class="font-bold text-on-surface text-xs">
                        Kéo thả ảnh chụp lỗi vào đây, hoặc <span class="text-primary underline">chọn từ thiết bị</span>
                    </p>
                    <p class="text-[11px] text-on-surface-variant">
                        Hỗ trợ: PNG, JPG, GIF, WEBP hoặc tài liệu PDF/Excel (Tối đa 15MB/file)
                    </p>
                    <div class="inline-flex items-center gap-1 text-[10px] text-primary bg-primary-container/10 px-2.5 py-0.5 rounded-full font-semibold mt-1">
                        <span class="material-symbols-outlined text-[13px]">content_paste</span>
                        <span>Có thể dán trực tiếp ảnh từ Clipboard (Ctrl + V / Cmd + V)</span>
                    </div>
                </div>
            </div>

            {{-- Previews of selected / pasted files --}}
            <template x-if="previews.length > 0">
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 pt-3">
                    <template x-for="(item, index) in previews" :key="index">
                        <div class="relative group bg-surface-container-lowest border border-surface-container-highest rounded-xl overflow-hidden shadow-sm p-1.5 flex flex-col">
                            <div class="h-24 rounded-lg bg-surface-container overflow-hidden flex items-center justify-center relative">
                                <template x-if="item.isImage">
                                    <img :src="item.url" class="w-full h-full object-cover" />
                                </template>
                                <template x-if="!item.isImage">
                                    <div class="flex flex-col items-center gap-1 text-on-surface-variant">
                                        <span class="material-symbols-outlined text-2xl">draft</span>
                                        <span class="text-[9px] uppercase font-bold" x-text="item.ext"></span>
                                    </div>
                                </template>
                                <button 
                                    type="button" 
                                    @click.stop="removeFile(index)" 
                                    class="absolute top-1 right-1 w-6 h-6 rounded-full bg-error text-white flex items-center justify-center opacity-90 hover:opacity-100 shadow transition"
                                    title="Xóa tệp này"
                                >
                                    <span class="material-symbols-outlined text-sm">close</span>
                                </button>
                            </div>
                            <div class="mt-1 px-1">
                                <div class="font-medium text-[11px] text-on-surface truncate" x-text="item.name"></div>
                                <div class="text-[10px] text-on-surface-variant/70 font-mono" x-text="item.sizeFormatted"></div>
                            </div>
                        </div>
                    </template>
                </div>
            </template>
        </div>
    </div>

    @unless ($asModal)
        <div class="flex items-center justify-end gap-3 pt-4 border-t border-surface-container-highest">
            <x-ui.button variant="secondary" :href="route('tickets.index')">Hủy</x-ui.button>
            <x-ui.button type="submit" icon="send">Tạo &amp; Gửi Ticket</x-ui.button>
        </div>
    @endunless
</form>
